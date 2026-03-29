<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceIngestEvent;
use App\Models\EvaluationRun;
use App\Models\EvaluationRunMeasurement;
use App\Models\User;
use App\Support\EggSizeClass;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use stdClass;

class ValidationAccuracyService
{
    /**
     * @param array{
     *   range:string,
     *   selected:array{farm_id:int|null,device_id:int|null},
     *   scope:array{farm_ids:array<int,int>,device_ids:array<int,int>}
     * } $context
     * @param array{run_id:int|null,status:string} $filters
     * @return array{
     *   summary:stdClass,
     *   runs:Collection<int, stdClass>,
     *   selected_run:stdClass|null,
     *   measurements:Collection<int, stdClass>,
     *   candidate_records:Collection<int, stdClass>,
     *   confusion_matrix:array{columns:array<int,string>,rows:array<int,array{manual_size_class:string,counts:array<string,int>,total:int}>},
     *   window:array{start:CarbonImmutable,end:CarbonImmutable}
     * }
     */
    public function build(array $context, array $filters): array
    {
        $window = $this->resolveWindow((string) ($context['range'] ?? DashboardContextService::RANGE_1D));

        if (!$this->hasValidationTables()) {
            return [
                'available' => false,
                'missing_tables' => $this->missingValidationTables(),
                'summary' => $this->emptySummary(),
                'runs' => collect(),
                'selected_run' => null,
                'measurements' => collect(),
                'candidate_records' => collect(),
                'confusion_matrix' => $this->emptyConfusionMatrix(),
                'window' => $window,
            ];
        }

        $runQuery = $this->scopedRunQuery($context, $filters['status'], $window['start'], $window['end']);

        $summary = DB::query()
            ->fromSub(clone $runQuery, 'run_rows')
            ->selectRaw('COUNT(*) AS total_runs')
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS active_runs', ['in_progress'])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS completed_runs', ['completed'])
            ->selectRaw('COALESCE(SUM(total_measurements), 0) AS total_measurements')
            ->selectRaw('COALESCE(AVG(mae_grams), 0) AS avg_mae_grams')
            ->first();

        $runs = (clone $runQuery)
            ->orderByRaw("CASE WHEN runs.status = 'in_progress' THEN 0 ELSE 1 END")
            ->orderByDesc('runs.started_at')
            ->orderByDesc('runs.id')
            ->get();

        $this->decorateRuns($runs);

        $selectedRun = null;
        if ($filters['run_id'] !== null) {
            $selectedRun = $runs->first(static fn (stdClass $run): bool => (int) $run->id === $filters['run_id']);
        }

        if ($selectedRun === null && $runs->isNotEmpty()) {
            $selectedRun = $runs->first();
        }

        $measurements = collect();
        $candidateRecords = collect();
        $confusionMatrix = $this->emptyConfusionMatrix();

        if ($selectedRun !== null) {
            $measurements = $this->measurementRows((int) $selectedRun->id);
            $candidateRecords = $this->candidateRecords($selectedRun);
            $confusionMatrix = $this->confusionMatrix((int) $selectedRun->id);
        }

        return [
            'available' => true,
            'missing_tables' => [],
            'summary' => $summary ?? $this->emptySummary(),
            'runs' => $runs,
            'selected_run' => $selectedRun,
            'measurements' => $measurements,
            'candidate_records' => $candidateRecords,
            'confusion_matrix' => $confusionMatrix,
            'window' => $window,
        ];
    }

    /**
     * @param array{
     *   range:string,
     *   selected:array{farm_id:int|null,device_id:int|null},
     *   scope:array{farm_ids:array<int,int>,device_ids:array<int,int>}
     * } $context
     * @param array<string,mixed> $input
     */
    public function createRun(User $user, array $context, array $input): EvaluationRun
    {
        $this->ensureValidationTablesAvailable();

        $farmIds = array_values(array_unique(array_map(static fn ($id): int => (int) $id, $context['scope']['farm_ids'] ?? [])));
        $deviceIds = array_values(array_unique(array_map(static fn ($id): int => (int) $id, $context['scope']['device_ids'] ?? [])));

        $validator = Validator::make($input, [
            'farm_id' => ['required', 'integer', Rule::in($farmIds)],
            'device_id' => ['required', 'integer', Rule::in($deviceIds)],
            'run_code' => ['required', 'string', 'max:80'],
            'title' => ['required', 'string', 'max:150'],
            'sample_size_target' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $validator->after(function ($validator) use ($input): void {
            $deviceId = (int) ($input['device_id'] ?? 0);
            $farmId = (int) ($input['farm_id'] ?? 0);
            $runCode = strtoupper(trim((string) ($input['run_code'] ?? '')));

            $device = Device::query()->find($deviceId);
            if (!$device) {
                $validator->errors()->add('device_id', 'Selected device could not be found.');

                return;
            }

            if ((int) $device->farm_id !== $farmId) {
                $validator->errors()->add('device_id', 'Selected device does not belong to the selected farm.');
            }

            if ($runCode !== '' && EvaluationRun::query()->where('device_id', $deviceId)->where('run_code', $runCode)->exists()) {
                $validator->errors()->add('run_code', 'The run code is already in use for the selected device.');
            }
        });

        $validated = $validator->validate();
        $device = Device::query()->findOrFail((int) $validated['device_id']);

        return EvaluationRun::query()->create([
            'farm_id' => (int) $validated['farm_id'],
            'device_id' => (int) $validated['device_id'],
            'owner_user_id' => (int) $device->owner_user_id,
            'performed_by_user_id' => (int) $user->id,
            'run_code' => strtoupper(trim((string) $validated['run_code'])),
            'title' => trim((string) $validated['title']),
            'status' => 'in_progress',
            'sample_size_target' => $validated['sample_size_target'] !== null ? (int) $validated['sample_size_target'] : null,
            'started_at' => now(),
            'notes' => $this->nullableTrim($validated['notes'] ?? null),
        ]);
    }

    /**
     * @param array{
     *   range:string,
     *   selected:array{farm_id:int|null,device_id:int|null},
     *   scope:array{farm_ids:array<int,int>,device_ids:array<int,int>}
     * } $context
     * @param array<string,mixed> $input
     */
    public function addMeasurement(User $user, array $context, EvaluationRun $run, array $input): EvaluationRunMeasurement
    {
        unset($user);
        $this->ensureValidationTablesAvailable();

        $accessibleRun = $this->accessibleRun($context, $run);

        if ($accessibleRun->status === 'completed') {
            throw ValidationException::withMessages([
                'device_ingest_event_id' => 'This validation run is already completed.',
            ]);
        }

        $validator = Validator::make($input, [
            'device_ingest_event_id' => ['required', 'integer'],
            'reference_weight_grams' => ['required', 'numeric', 'gt:0', 'max:999.99'],
            'manual_size_class' => ['required', 'string', Rule::in(EggSizeClass::values())],
            'measured_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $validator->after(function ($validator) use ($accessibleRun, $input): void {
            $event = DeviceIngestEvent::query()->find((int) ($input['device_ingest_event_id'] ?? 0));

            if (!$event) {
                $validator->errors()->add('device_ingest_event_id', 'Selected ingest record could not be found.');

                return;
            }

            if ((int) $event->farm_id !== (int) $accessibleRun->farm_id || (int) $event->device_id !== (int) $accessibleRun->device_id) {
                $validator->errors()->add('device_ingest_event_id', 'Selected ingest record does not belong to this validation run.');
            }

            if (EvaluationRunMeasurement::query()
                ->where('evaluation_run_id', (int) $accessibleRun->id)
                ->where('device_ingest_event_id', (int) $event->id)
                ->exists()) {
                $validator->errors()->add('device_ingest_event_id', 'This ingest record has already been measured in the selected run.');
            }
        });

        $validated = $validator->validate();
        $event = DeviceIngestEvent::query()->findOrFail((int) $validated['device_ingest_event_id']);
        $measuredAt = !empty($validated['measured_at'])
            ? CarbonImmutable::parse((string) $validated['measured_at'])
            : CarbonImmutable::now();

        $referenceWeight = round((float) $validated['reference_weight_grams'], 2);
        $automatedWeight = round((float) $event->weight_grams, 2);
        $weightError = round($automatedWeight - $referenceWeight, 2);
        $absoluteError = round(abs($weightError), 2);
        $manualSizeClass = (string) $validated['manual_size_class'];
        $automatedSizeClass = (string) $event->size_class;

        $measurement = EvaluationRunMeasurement::query()->create([
            'evaluation_run_id' => (int) $accessibleRun->id,
            'device_ingest_event_id' => (int) $event->id,
            'egg_uid' => $event->egg_uid,
            'batch_code' => $event->batch_code,
            'reference_weight_grams' => $referenceWeight,
            'automated_weight_grams' => $automatedWeight,
            'manual_size_class' => $manualSizeClass,
            'automated_size_class' => $automatedSizeClass,
            'weight_error_grams' => $weightError,
            'absolute_error_grams' => $absoluteError,
            'class_match' => $manualSizeClass === $automatedSizeClass,
            'measured_at' => $measuredAt,
            'notes' => $this->nullableTrim($validated['notes'] ?? null),
        ]);

        $totalMeasurements = EvaluationRunMeasurement::query()
            ->where('evaluation_run_id', (int) $accessibleRun->id)
            ->count();

        if ($accessibleRun->sample_size_target !== null && $totalMeasurements >= (int) $accessibleRun->sample_size_target) {
            $accessibleRun->forceFill([
                'status' => 'completed',
                'ended_at' => $measuredAt,
            ])->save();
        }

        return $measurement;
    }

    /**
     * @param array{
     *   range:string,
     *   selected:array{farm_id:int|null,device_id:int|null},
     *   scope:array{farm_ids:array<int,int>,device_ids:array<int,int>}
     * } $context
     * @return Collection<int, stdClass>
     */
    public function exportMeasurements(array $context, EvaluationRun $run): Collection
    {
        $this->ensureValidationTablesAvailable();
        $accessibleRun = $this->accessibleRun($context, $run);

        return $this->measurementRows((int) $accessibleRun->id);
    }

    /**
     * @param array{
     *   range:string,
     *   selected:array{farm_id:int|null,device_id:int|null},
     *   scope:array{farm_ids:array<int,int>,device_ids:array<int,int>}
     * } $context
     */
    public function accessibleRun(array $context, EvaluationRun $run): EvaluationRun
    {
        $farmIds = array_values(array_unique(array_map(static fn ($id): int => (int) $id, $context['scope']['farm_ids'] ?? [])));
        $deviceIds = array_values(array_unique(array_map(static fn ($id): int => (int) $id, $context['scope']['device_ids'] ?? [])));

        if (!in_array((int) $run->farm_id, $farmIds, true) || !in_array((int) $run->device_id, $deviceIds, true)) {
            abort(403);
        }

        return $run;
    }

    private function scopedRunQuery(array $context, string $status, CarbonImmutable $start, CarbonImmutable $end)
    {
        $measurementStats = DB::table('evaluation_run_measurements as measurements')
            ->selectRaw('evaluation_run_id')
            ->selectRaw('COUNT(*) AS total_measurements')
            ->selectRaw('COALESCE(AVG(absolute_error_grams), 0) AS mae_grams')
            ->selectRaw('COALESCE(AVG(weight_error_grams), 0) AS mean_error_grams')
            ->selectRaw('COALESCE(SUM(CASE WHEN class_match = 1 THEN 1 ELSE 0 END), 0) AS class_matches')
            ->selectRaw('MAX(measured_at) AS latest_measured_at')
            ->groupBy('evaluation_run_id');

        $query = DB::table('evaluation_runs as runs')
            ->join('devices', 'devices.id', '=', 'runs.device_id')
            ->join('farms', 'farms.id', '=', 'runs.farm_id')
            ->leftJoin('users as owners', 'owners.id', '=', 'runs.owner_user_id')
            ->leftJoin('users as performers', 'performers.id', '=', 'runs.performed_by_user_id')
            ->leftJoinSub($measurementStats, 'measurement_stats', function ($join): void {
                $join->on('measurement_stats.evaluation_run_id', '=', 'runs.id');
            });

        $scopeFarmIds = array_values(array_unique(array_map(static fn ($id): int => (int) $id, $context['scope']['farm_ids'] ?? [])));
        $scopeDeviceIds = array_values(array_unique(array_map(static fn ($id): int => (int) $id, $context['scope']['device_ids'] ?? [])));

        if ($scopeFarmIds === [] || $scopeDeviceIds === []) {
            return $query->whereRaw('1 = 0');
        }

        $query->whereIn('runs.farm_id', $scopeFarmIds)
            ->whereIn('runs.device_id', $scopeDeviceIds)
            ->whereBetween('runs.started_at', [$start->toDateTimeString(), $end->toDateTimeString()]);

        $selectedFarmId = $context['selected']['farm_id'] ?? null;
        if (is_int($selectedFarmId) && $selectedFarmId > 0) {
            $query->where('runs.farm_id', $selectedFarmId);
        }

        $selectedDeviceId = $context['selected']['device_id'] ?? null;
        if (is_int($selectedDeviceId) && $selectedDeviceId > 0) {
            $query->where('runs.device_id', $selectedDeviceId);
        }

        if ($status !== 'all') {
            $query->where('runs.status', $status);
        }

        return $query->select([
            'runs.id',
            'runs.farm_id',
            'runs.device_id',
            'runs.owner_user_id',
            'runs.performed_by_user_id',
            'runs.run_code',
            'runs.title',
            'runs.status',
            'runs.sample_size_target',
            'runs.started_at',
            'runs.ended_at',
            'runs.notes',
            'farms.farm_name',
            'devices.module_board_name as device_name',
            'devices.primary_serial_no as device_serial',
            'owners.full_name as owner_name',
            'performers.full_name as performed_by_name',
            DB::raw('COALESCE(measurement_stats.total_measurements, 0) as total_measurements'),
            DB::raw('COALESCE(measurement_stats.mae_grams, 0) as mae_grams'),
            DB::raw('COALESCE(measurement_stats.mean_error_grams, 0) as mean_error_grams'),
            DB::raw('COALESCE(measurement_stats.class_matches, 0) as class_matches'),
            DB::raw('measurement_stats.latest_measured_at as latest_measured_at'),
        ]);
    }

    /**
     * @param Collection<int, stdClass> $runs
     */
    private function decorateRuns(Collection $runs): void
    {
        $runs->each(function (stdClass $run): void {
            $totalMeasurements = (int) ($run->total_measurements ?? 0);
            $classMatches = (int) ($run->class_matches ?? 0);
            $run->mismatched_classes = max(0, $totalMeasurements - $classMatches);
            $run->accuracy_percent = $totalMeasurements > 0
                ? round(($classMatches / $totalMeasurements) * 100, 2)
                : 0.0;
            $run->completion_percent = (int) ($run->sample_size_target ?? 0) > 0
                ? round(min(100, ($totalMeasurements / (int) $run->sample_size_target) * 100), 2)
                : null;
        });
    }

    /**
     * @return Collection<int, stdClass>
     */
    private function measurementRows(int $runId): Collection
    {
        return DB::table('evaluation_run_measurements as measurements')
            ->leftJoin('device_ingest_events as events', 'events.id', '=', 'measurements.device_ingest_event_id')
            ->where('measurements.evaluation_run_id', $runId)
            ->select([
                'measurements.id',
                'measurements.evaluation_run_id',
                'measurements.device_ingest_event_id',
                'measurements.egg_uid',
                'measurements.batch_code',
                'measurements.reference_weight_grams',
                'measurements.automated_weight_grams',
                'measurements.manual_size_class',
                'measurements.automated_size_class',
                'measurements.weight_error_grams',
                'measurements.absolute_error_grams',
                'measurements.class_match',
                'measurements.measured_at',
                'measurements.notes',
                'events.recorded_at as automated_recorded_at',
            ])
            ->orderByDesc('measurements.measured_at')
            ->orderByDesc('measurements.id')
            ->get();
    }

    /**
     * @param stdClass $selectedRun
     * @return Collection<int, stdClass>
     */
    private function candidateRecords(stdClass $selectedRun): Collection
    {
        return DB::table('device_ingest_events as events')
            ->leftJoin('evaluation_run_measurements as measurements', function ($join) use ($selectedRun): void {
                $join->on('measurements.device_ingest_event_id', '=', 'events.id')
                    ->where('measurements.evaluation_run_id', '=', (int) $selectedRun->id);
            })
            ->where('events.farm_id', (int) $selectedRun->farm_id)
            ->where('events.device_id', (int) $selectedRun->device_id)
            ->whereNull('measurements.id')
            ->select([
                'events.id',
                'events.egg_uid',
                'events.batch_code',
                'events.weight_grams',
                'events.size_class',
                'events.recorded_at',
            ])
            ->orderByDesc('events.recorded_at')
            ->orderByDesc('events.id')
            ->limit(40)
            ->get();
    }

    /**
     * @return array{columns:array<int,string>,rows:array<int,array{manual_size_class:string,counts:array<string,int>,total:int}>}
     */
    private function confusionMatrix(int $runId): array
    {
        $columns = EggSizeClass::values();
        $rows = [];

        foreach ($columns as $manualSizeClass) {
            $rows[$manualSizeClass] = [
                'manual_size_class' => $manualSizeClass,
                'counts' => array_fill_keys($columns, 0),
                'total' => 0,
            ];
        }

        $counts = DB::table('evaluation_run_measurements')
            ->where('evaluation_run_id', $runId)
            ->selectRaw('manual_size_class')
            ->selectRaw('automated_size_class')
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('manual_size_class', 'automated_size_class')
            ->get();

        foreach ($counts as $count) {
            $manual = (string) $count->manual_size_class;
            $automated = (string) $count->automated_size_class;

            if (!isset($rows[$manual]) || !array_key_exists($automated, $rows[$manual]['counts'])) {
                continue;
            }

            $rows[$manual]['counts'][$automated] = (int) $count->total;
            $rows[$manual]['total'] += (int) $count->total;
        }

        return [
            'columns' => $columns,
            'rows' => array_values($rows),
        ];
    }

    /**
     * @return array{columns:array<int,string>,rows:array<int,array{manual_size_class:string,counts:array<string,int>,total:int}>}
     */
    private function emptyConfusionMatrix(): array
    {
        return [
            'columns' => EggSizeClass::values(),
            'rows' => array_map(static function (string $manualSizeClass): array {
                return [
                    'manual_size_class' => $manualSizeClass,
                    'counts' => array_fill_keys(EggSizeClass::values(), 0),
                    'total' => 0,
                ];
            }, EggSizeClass::values()),
        ];
    }

    /**
     * @return array{start:CarbonImmutable,end:CarbonImmutable}
     */
    private function resolveWindow(string $range): array
    {
        $end = CarbonImmutable::now();

        $start = match ($range) {
            DashboardContextService::RANGE_1W => $end->subWeek(),
            DashboardContextService::RANGE_1M => $end->subMonth(),
            default => $end->subDay(),
        };

        return [
            'start' => $start,
            'end' => $end,
        ];
    }

    private function emptySummary(): stdClass
    {
        return (object) [
            'total_runs' => 0,
            'active_runs' => 0,
            'completed_runs' => 0,
            'total_measurements' => 0,
            'avg_mae_grams' => 0,
        ];
    }

    private function nullableTrim(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed === '' ? null : $trimmed;
    }

    private function hasValidationTables(): bool
    {
        return $this->missingValidationTables() === [];
    }

    /**
     * @return array<int, string>
     */
    private function missingValidationTables(): array
    {
        $required = [
            'evaluation_runs',
            'evaluation_run_measurements',
        ];

        return array_values(array_filter($required, static fn (string $table): bool => !Schema::hasTable($table)));
    }

    private function ensureValidationTablesAvailable(): void
    {
        if ($this->hasValidationTables()) {
            return;
        }

        throw ValidationException::withMessages([
            'validation' => 'Validation tables are not available in this environment yet. Run the pending monitoring migrations first.',
        ]);
    }
}
