<?php

namespace App\Services;

use App\Models\Device;
use App\Models\ProductionBatch;
use App\Support\BatchCodeFormatter;
use App\Support\EggSizeClass;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

class BatchMonitoringService
{
    public const STATUS_ALL = 'all';
    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';

    /**
     * @return array<int, string>
     */
    public static function allowedStatuses(): array
    {
        return [
            self::STATUS_ALL,
            self::STATUS_OPEN,
            self::STATUS_CLOSED,
        ];
    }

    /**
     * @param array{
     *   range:string,
     *   selected:array{farm_id:int|null,device_id:int|null},
     *   scope:array{farm_ids:array<int,int>,device_ids:array<int,int>}
     * } $context
     * @return array{
     *   stats:stdClass,
     *   batches:LengthAwarePaginator<int, stdClass>,
     *   window:array{start:CarbonImmutable,end:CarbonImmutable}
     * }
     */
    public function buildList(array $context, string $search = '', string $status = self::STATUS_ALL): array
    {
        $window = $this->resolveWindow((string) ($context['range'] ?? DashboardContextService::RANGE_1D));
        $aggregateQuery = $this->hasProductionBatchesTable()
            ? $this->scopedBatchQuery($context, $window['start'], $window['end'], $search, $status)
            : $this->legacyAggregateBatchQuery($context, $window['start'], $window['end'], $search, $status);

        /** @var LengthAwarePaginator<int, stdClass> $batches */
        $batches = (clone $aggregateQuery)
            ->orderByRaw('COALESCE(ended_at, started_at) DESC')
            ->orderByDesc('started_at')
            ->paginate(12)
            ->withQueryString();

        $stats = DB::query()
            ->fromSub($aggregateQuery, 'batch_rows')
            ->selectRaw('COUNT(*) AS total_batches')
            ->selectRaw('COALESCE(SUM(total_eggs), 0) AS total_eggs')
            ->selectRaw('COALESCE(SUM(reject_count), 0) AS reject_eggs')
            ->selectRaw('COALESCE(SUM(total_weight_grams), 0) AS total_weight_grams')
            ->selectRaw('COUNT(DISTINCT farm_id) AS active_farms')
            ->selectRaw('COUNT(DISTINCT device_id) AS active_devices')
            ->selectRaw('MAX(ended_at) AS latest_recorded_at')
            ->first();

        return [
            'stats' => $stats ?? $this->emptyStats(),
            'batches' => $batches,
            'window' => $window,
        ];
    }

    /**
     * @param array{
     *   range:string,
     *   selected:array{farm_id:int|null,device_id:int|null},
     *   scope:array{farm_ids:array<int,int>,device_ids:array<int,int>}
     * } $context
     * @return array{
     *   summary:stdClass,
     *   size_breakdown:Collection<int, stdClass>,
     *   records:LengthAwarePaginator<int, stdClass>
     * }
     */
    public function buildDetail(array $context, int $farmId, int $deviceId, string $batchCode): array
    {
        $batchCode = trim($batchCode);

        if (!$this->hasProductionBatchesTable()) {
            return $this->buildLegacyDetail($context, $farmId, $deviceId, $batchCode);
        }

        $summary = $this->findBatchSummary($context, $farmId, $deviceId, $batchCode);

        abort_if($summary === null, 404);

        $detailBaseQuery = $this->detailEventQuery((int) $summary->id);

        $sizeBreakdown = (clone $detailBaseQuery)
            ->selectRaw('events.size_class')
            ->selectRaw('COUNT(*) AS eggs')
            ->selectRaw('COALESCE(SUM(events.weight_grams), 0) AS total_weight_grams')
            ->groupBy('events.size_class')
            ->orderByRaw($this->sizeClassOrderSql('events.size_class'))
            ->get();

        /** @var LengthAwarePaginator<int, stdClass> $records */
        $records = (clone $detailBaseQuery)
            ->select([
                'events.id',
                'events.egg_uid',
                'events.batch_code',
                'events.weight_grams',
                'events.size_class',
                'events.recorded_at',
                'events.created_at',
                'events.source_ip',
            ])
            ->orderByDesc('events.recorded_at')
            ->orderByDesc('events.id')
            ->paginate(20)
            ->withQueryString();

        return [
            'summary' => $summary,
            'size_breakdown' => $sizeBreakdown,
            'records' => $records,
        ];
    }

    /**
     * @param array{
     *   range:string,
     *   selected:array{farm_id:int|null,device_id:int|null},
     *   scope:array{farm_ids:array<int,int>,device_ids:array<int,int>}
     * } $context
     * @return Collection<int, stdClass>
     */
    public function exportListRows(array $context, string $search = '', string $status = self::STATUS_ALL): Collection
    {
        $window = $this->resolveWindow((string) ($context['range'] ?? DashboardContextService::RANGE_1D));

        $query = $this->hasProductionBatchesTable()
            ? $this->scopedBatchQuery($context, $window['start'], $window['end'], $search, $status)
            : $this->legacyAggregateBatchQuery($context, $window['start'], $window['end'], $search, $status);

        return $query
            ->orderByRaw('COALESCE(ended_at, started_at) DESC')
            ->orderByDesc('started_at')
            ->get();
    }

    /**
     * @param array{
     *   selected:array{farm_id:int|null,device_id:int|null},
     *   scope:array{farm_ids:array<int,int>,device_ids:array<int,int>}
     * } $context
     * @return Collection<int, stdClass>
     */
    public function exportDetailRows(array $context, int $farmId, int $deviceId, string $batchCode): Collection
    {
        if (!$this->hasProductionBatchesTable()) {
            return $this->legacyDetailEventQuery($context, $farmId, $deviceId, trim($batchCode))
                ->select([
                    'events.id',
                    'events.egg_uid',
                    'events.batch_code',
                    'events.weight_grams',
                    'events.size_class',
                    'events.recorded_at',
                    'events.created_at',
                    'events.source_ip',
                    'farms.farm_name',
                    'devices.module_board_name as device_name',
                    'devices.primary_serial_no as device_serial',
                    'owners.full_name as owner_name',
                ])
                ->orderByDesc('events.recorded_at')
                ->orderByDesc('events.id')
                ->get();
        }

        $summary = $this->findBatchSummary($context, $farmId, $deviceId, trim($batchCode));
        abort_if($summary === null, 404);

        return $this->detailEventQuery((int) $summary->id)
            ->select([
                'events.id',
                'events.egg_uid',
                'events.batch_code',
                'events.weight_grams',
                'events.size_class',
                'events.recorded_at',
                'events.created_at',
                'events.source_ip',
                'farms.farm_name',
                'devices.module_board_name as device_name',
                'devices.primary_serial_no as device_serial',
                'owners.full_name as owner_name',
            ])
            ->orderByDesc('events.recorded_at')
            ->orderByDesc('events.id')
            ->get();
    }

    /**
     * @param array{
     *   selected:array{farm_id:int|null,device_id:int|null},
     *   scope:array{farm_ids:array<int,int>,device_ids:array<int,int>}
     * } $context
     */
    private function scopedBatchQuery(array $context, CarbonImmutable $start, CarbonImmutable $end, string $search = '', string $status = self::STATUS_ALL)
    {
        $query = DB::table('production_batches as batches')
            ->join('devices', 'devices.id', '=', 'batches.device_id')
            ->join('farms', 'farms.id', '=', 'batches.farm_id')
            ->leftJoin('users as owners', 'owners.id', '=', 'batches.owner_user_id')
            ->leftJoinSub($this->eventAggregateSubquery(), 'event_stats', function ($join): void {
                $join->on('event_stats.production_batch_id', '=', 'batches.id');
            })
            ->whereBetween('batches.started_at', [$start->toDateTimeString(), $end->toDateTimeString()]);

        $scopeFarmIds = array_values(array_unique(array_map(static fn ($id): int => (int) $id, $context['scope']['farm_ids'] ?? [])));
        $scopeDeviceIds = array_values(array_unique(array_map(static fn ($id): int => (int) $id, $context['scope']['device_ids'] ?? [])));

        if ($scopeFarmIds === [] || $scopeDeviceIds === []) {
            return $query->whereRaw('1 = 0');
        }

        $query->whereIn('batches.farm_id', $scopeFarmIds)
            ->whereIn('batches.device_id', $scopeDeviceIds);

        $selectedFarmId = $context['selected']['farm_id'] ?? null;
        if (is_int($selectedFarmId) && $selectedFarmId > 0) {
            $query->where('batches.farm_id', $selectedFarmId);
        }

        $selectedDeviceId = $context['selected']['device_id'] ?? null;
        if (is_int($selectedDeviceId) && $selectedDeviceId > 0) {
            $query->where('batches.device_id', $selectedDeviceId);
        }

        if ($search !== '') {
            $query->where('batches.batch_code', 'like', '%' . Str::replace(['%', '_'], ['\%', '\_'], $search) . '%');
        }

        $this->applyStatusFilter($query, 'batches.status', $status);

        return $query
            ->selectRaw('batches.id')
            ->selectRaw('batches.batch_code')
            ->selectRaw('batches.status')
            ->selectRaw('batches.farm_id')
            ->selectRaw('farms.farm_name')
            ->selectRaw('batches.device_id')
            ->selectRaw('devices.module_board_name AS device_name')
            ->selectRaw('devices.primary_serial_no AS device_serial')
            ->selectRaw('owners.full_name AS owner_name')
            ->selectRaw('COALESCE(event_stats.total_eggs, 0) AS total_eggs')
            ->selectRaw('COALESCE(event_stats.total_weight_grams, 0) AS total_weight_grams')
            ->selectRaw('COALESCE(event_stats.avg_weight_grams, 0) AS avg_weight_grams')
            ->selectRaw('COALESCE(event_stats.reject_count, 0) AS reject_count')
            ->selectRaw('batches.started_at')
            ->selectRaw('batches.ended_at');
    }

    /**
     * @param array{
     *   selected:array{farm_id:int|null,device_id:int|null},
     *   scope:array{farm_ids:array<int,int>,device_ids:array<int,int>}
     * } $context
     */
    private function legacyAggregateBatchQuery(array $context, CarbonImmutable $start, CarbonImmutable $end, string $search = '', string $status = self::STATUS_ALL)
    {
        $query = $this->legacyScopedEventQuery($context)
            ->whereBetween('events.recorded_at', [$start->toDateTimeString(), $end->toDateTimeString()])
            ->whereNotNull('events.batch_code')
            ->where('events.batch_code', '<>', '');

        if ($search !== '') {
            $query->where('events.batch_code', 'like', '%' . Str::replace(['%', '_'], ['\%', '\_'], $search) . '%');
        }

        if ($status !== self::STATUS_ALL) {
            $query->whereRaw('1 = 0');
        }

        return $query
            ->selectRaw('NULL AS id')
            ->selectRaw('events.batch_code')
            ->selectRaw("'legacy' AS status")
            ->selectRaw('events.farm_id')
            ->selectRaw('farms.farm_name')
            ->selectRaw('events.device_id')
            ->selectRaw('devices.module_board_name AS device_name')
            ->selectRaw('devices.primary_serial_no AS device_serial')
            ->selectRaw('owners.full_name AS owner_name')
            ->selectRaw('COUNT(*) AS total_eggs')
            ->selectRaw('COALESCE(SUM(events.weight_grams), 0) AS total_weight_grams')
            ->selectRaw('COALESCE(AVG(events.weight_grams), 0) AS avg_weight_grams')
            ->selectRaw('SUM(CASE WHEN events.size_class = ? THEN 1 ELSE 0 END) AS reject_count', [EggSizeClass::REJECT])
            ->selectRaw('MIN(events.recorded_at) AS started_at')
            ->selectRaw('MAX(events.recorded_at) AS ended_at')
            ->groupBy(
                'events.batch_code',
                'events.farm_id',
                'farms.farm_name',
                'events.device_id',
                'devices.module_board_name',
                'devices.primary_serial_no',
                'owners.full_name'
            );
    }

    private function applyStatusFilter($query, string $column, string $status): void
    {
        if ($status === self::STATUS_OPEN) {
            $query->where($column, self::STATUS_OPEN);

            return;
        }

        if ($status === self::STATUS_CLOSED) {
            $query->where($column, self::STATUS_CLOSED);
        }
    }

    /**
     * @param array{
     *   selected:array{farm_id:int|null,device_id:int|null},
     *   scope:array{farm_ids:array<int,int>,device_ids:array<int,int>}
     * } $context
     */
    private function findBatchSummary(array $context, int $farmId, int $deviceId, string $batchCode): ?stdClass
    {
        return $this->scopedBatchQuery(
            [
                'selected' => [
                    'farm_id' => $context['selected']['farm_id'] ?? null,
                    'device_id' => $context['selected']['device_id'] ?? null,
                ],
                'scope' => [
                    'farm_ids' => $context['scope']['farm_ids'] ?? [],
                    'device_ids' => $context['scope']['device_ids'] ?? [],
                ],
            ],
            CarbonImmutable::create(2000, 1, 1, 0, 0, 0),
            CarbonImmutable::now()->addYears(10),
            ''
        )
            ->where('batches.farm_id', $farmId)
            ->where('batches.device_id', $deviceId)
            ->where('batches.batch_code', $batchCode)
            ->first();
    }

    private function detailEventQuery(int $productionBatchId)
    {
        return DB::table('device_ingest_events as events')
            ->join('devices', 'devices.id', '=', 'events.device_id')
            ->join('farms', 'farms.id', '=', 'events.farm_id')
            ->leftJoin('users as owners', 'owners.id', '=', 'events.owner_user_id')
            ->where('events.production_batch_id', $productionBatchId);
    }

    /**
     * @param array{
     *   selected:array{farm_id:int|null,device_id:int|null},
     *   scope:array{farm_ids:array<int,int>,device_ids:array<int,int>}
     * } $context
     * @return array{
     *   summary:stdClass,
     *   size_breakdown:Collection<int, stdClass>,
     *   records:LengthAwarePaginator<int, stdClass>
     * }
     */
    private function buildLegacyDetail(array $context, int $farmId, int $deviceId, string $batchCode): array
    {
        $detailBaseQuery = $this->legacyDetailEventQuery($context, $farmId, $deviceId, $batchCode);

        $summary = (clone $detailBaseQuery)
            ->selectRaw('NULL AS id')
            ->selectRaw('events.batch_code')
            ->selectRaw("'legacy' AS status")
            ->selectRaw('events.farm_id')
            ->selectRaw('farms.farm_name')
            ->selectRaw('events.device_id')
            ->selectRaw('devices.module_board_name AS device_name')
            ->selectRaw('devices.primary_serial_no AS device_serial')
            ->selectRaw('owners.full_name AS owner_name')
            ->selectRaw('COUNT(*) AS total_eggs')
            ->selectRaw('COALESCE(SUM(events.weight_grams), 0) AS total_weight_grams')
            ->selectRaw('COALESCE(AVG(events.weight_grams), 0) AS avg_weight_grams')
            ->selectRaw('SUM(CASE WHEN events.size_class = ? THEN 1 ELSE 0 END) AS reject_count', [EggSizeClass::REJECT])
            ->selectRaw('MIN(events.recorded_at) AS started_at')
            ->selectRaw('MAX(events.recorded_at) AS ended_at')
            ->groupBy(
                'events.batch_code',
                'events.farm_id',
                'farms.farm_name',
                'events.device_id',
                'devices.module_board_name',
                'devices.primary_serial_no',
                'owners.full_name'
            )
            ->first();

        abort_if($summary === null, 404);

        $sizeBreakdown = (clone $detailBaseQuery)
            ->selectRaw('events.size_class')
            ->selectRaw('COUNT(*) AS eggs')
            ->selectRaw('COALESCE(SUM(events.weight_grams), 0) AS total_weight_grams')
            ->groupBy('events.size_class')
            ->orderByRaw($this->sizeClassOrderSql('events.size_class'))
            ->get();

        /** @var LengthAwarePaginator<int, stdClass> $records */
        $records = (clone $detailBaseQuery)
            ->select([
                'events.id',
                'events.egg_uid',
                'events.batch_code',
                'events.weight_grams',
                'events.size_class',
                'events.recorded_at',
                'events.created_at',
                'events.source_ip',
            ])
            ->orderByDesc('events.recorded_at')
            ->orderByDesc('events.id')
            ->paginate(20)
            ->withQueryString();

        return [
            'summary' => $summary,
            'size_breakdown' => $sizeBreakdown,
            'records' => $records,
        ];
    }

    /**
     * @param array{
     *   selected:array{farm_id:int|null,device_id:int|null},
     *   scope:array{farm_ids:array<int,int>,device_ids:array<int,int>}
     * } $context
     */
    private function legacyDetailEventQuery(array $context, int $farmId, int $deviceId, string $batchCode)
    {
        return $this->legacyScopedEventQuery($context)
            ->where('events.farm_id', $farmId)
            ->where('events.device_id', $deviceId)
            ->where('events.batch_code', $batchCode);
    }

    /**
     * @param array{
     *   selected:array{farm_id:int|null,device_id:int|null},
     *   scope:array{farm_ids:array<int,int>,device_ids:array<int,int>}
     * } $context
     */
    private function legacyScopedEventQuery(array $context)
    {
        $query = DB::table('device_ingest_events as events')
            ->join('devices', 'devices.id', '=', 'events.device_id')
            ->join('farms', 'farms.id', '=', 'events.farm_id')
            ->leftJoin('users as owners', 'owners.id', '=', 'events.owner_user_id');

        $scopeFarmIds = array_values(array_unique(array_map(static fn ($id): int => (int) $id, $context['scope']['farm_ids'] ?? [])));
        $scopeDeviceIds = array_values(array_unique(array_map(static fn ($id): int => (int) $id, $context['scope']['device_ids'] ?? [])));

        if ($scopeFarmIds === [] || $scopeDeviceIds === []) {
            return $query->whereRaw('1 = 0');
        }

        $query->whereIn('events.farm_id', $scopeFarmIds)
            ->whereIn('events.device_id', $scopeDeviceIds);

        $selectedFarmId = $context['selected']['farm_id'] ?? null;
        if (is_int($selectedFarmId) && $selectedFarmId > 0) {
            $query->where('events.farm_id', $selectedFarmId);
        }

        $selectedDeviceId = $context['selected']['device_id'] ?? null;
        if (is_int($selectedDeviceId) && $selectedDeviceId > 0) {
            $query->where('events.device_id', $selectedDeviceId);
        }

        return $query;
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

    private function emptyStats(): stdClass
    {
        return (object) [
            'total_batches' => 0,
            'total_eggs' => 0,
            'reject_eggs' => 0,
            'total_weight_grams' => 0,
            'active_farms' => 0,
            'active_devices' => 0,
            'latest_recorded_at' => null,
        ];
    }

    private function eventAggregateSubquery()
    {
        return DB::table('device_ingest_events')
            ->selectRaw('production_batch_id')
            ->selectRaw('COUNT(*) AS total_eggs')
            ->selectRaw('COALESCE(SUM(weight_grams), 0) AS total_weight_grams')
            ->selectRaw('COALESCE(AVG(weight_grams), 0) AS avg_weight_grams')
            ->selectRaw('SUM(CASE WHEN size_class = ? THEN 1 ELSE 0 END) AS reject_count', [EggSizeClass::REJECT])
            ->whereNotNull('production_batch_id')
            ->groupBy('production_batch_id');
    }

    public function closeBatch(ProductionBatch $batch): void
    {
        $latestRecordedAt = $batch->ingestEvents()->max('recorded_at');
        $endedAt = $latestRecordedAt ? CarbonImmutable::parse((string) $latestRecordedAt) : CarbonImmutable::now();

        $batch->update([
            'status' => 'closed',
            'ended_at' => $endedAt,
        ]);
    }

    public function openBatch(Device $device, ?string $batchCode = null): ProductionBatch
    {
        $batchCode = trim((string) $batchCode);

        if ($batchCode === '') {
            $batchCode = $this->generateUniqueBatchCode($device);
        }

        $existingBatch = ProductionBatch::query()
            ->where('device_id', $device->id)
            ->where('farm_id', $device->farm_id)
            ->where('batch_code', $batchCode)
            ->first();

        if ($existingBatch !== null) {
            throw ValidationException::withMessages([
                'batch_code' => 'Batch code already exists for this device.',
            ]);
        }

        $deviceHasOpenBatch = ProductionBatch::query()
            ->where('device_id', $device->id)
            ->where('farm_id', $device->farm_id)
            ->where('status', self::STATUS_OPEN)
            ->exists();

        if ($deviceHasOpenBatch) {
            throw ValidationException::withMessages([
                'device_id' => 'This device already has an open batch.',
            ]);
        }

        return ProductionBatch::query()->create([
            'device_id' => $device->id,
            'farm_id' => (int) $device->farm_id,
            'owner_user_id' => (int) $device->owner_user_id,
            'batch_code' => $batchCode,
            'status' => self::STATUS_OPEN,
            'started_at' => CarbonImmutable::now(),
            'ended_at' => null,
        ]);
    }

    private function hasProductionBatchesTable(): bool
    {
        return Schema::hasTable('production_batches');
    }

    private function generateUniqueBatchCode(Device $device, ?CarbonInterface $observedAt = null): string
    {
        $device->loadMissing('farm');
        $observedAt = $observedAt
            ? CarbonImmutable::instance($observedAt)
            : CarbonImmutable::now();

        $candidate = BatchCodeFormatter::build($device->farm?->farm_name, $observedAt);
        $suffix = 1;

        while (
            ProductionBatch::query()
                ->where('device_id', $device->id)
                ->where('farm_id', $device->farm_id)
                ->where('batch_code', $candidate)
                ->exists()
        ) {
            $suffix++;
            $candidate = BatchCodeFormatter::build($device->farm?->farm_name, $observedAt, $suffix);
        }

        return $candidate;
    }

    private function sizeClassOrderSql(string $column): string
    {
        $cases = [];

        foreach (EggSizeClass::values() as $index => $sizeClass) {
            $safeClass = str_replace("'", "''", $sizeClass);
            $cases[] = "WHEN '{$safeClass}' THEN {$index}";
        }

        return "CASE {$column} " . implode(' ', $cases) . ' ELSE 999 END';
    }
}
