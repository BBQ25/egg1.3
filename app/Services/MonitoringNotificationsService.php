<?php

namespace App\Services;

use App\Models\Device;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use stdClass;

class MonitoringNotificationsService
{
    public const SEVERITY_ALL = 'all';
    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_WARN = 'warn';
    public const SEVERITY_INFO = 'info';

    /**
     * @return array<int, string>
     */
    public static function allowedSeverities(): array
    {
        return [
            self::SEVERITY_ALL,
            self::SEVERITY_CRITICAL,
            self::SEVERITY_WARN,
            self::SEVERITY_INFO,
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
     *   alerts:Collection<int, array<string, mixed>>,
     *   window:array{start:CarbonImmutable,end:CarbonImmutable}
     * }
     */
    public function build(array $context, string $severity = self::SEVERITY_ALL): array
    {
        $window = $this->resolveWindow((string) ($context['range'] ?? DashboardContextService::RANGE_1D));
        $now = CarbonImmutable::now();
        $alerts = $this->compileAlerts($context, $window['start'], $window['end'], $now);

        if ($severity !== self::SEVERITY_ALL) {
            $alerts = $alerts->where('severity', $severity)->values();
        }

        $stats = (object) [
            'total_alerts' => $alerts->count(),
            'critical_count' => $alerts->where('severity', self::SEVERITY_CRITICAL)->count(),
            'warn_count' => $alerts->where('severity', self::SEVERITY_WARN)->count(),
            'info_count' => $alerts->where('severity', self::SEVERITY_INFO)->count(),
            'flagged_devices' => $alerts->pluck('device_id')->filter()->unique()->count(),
            'latest_triggered_at' => $alerts->pluck('triggered_at')->filter()->sortDesc()->first(),
        ];

        return [
            'stats' => $stats,
            'alerts' => $alerts,
            'window' => $window,
        ];
    }

    /**
     * @param array{
     *   selected:array{farm_id:int|null,device_id:int|null},
     *   scope:array{farm_ids:array<int,int>,device_ids:array<int,int>}
     * } $context
     * @return Collection<int, array<string, mixed>>
     */
    private function compileAlerts(array $context, CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $now): Collection
    {
        $devices = $this->scopedDeviceQuery($context)->get();
        $rangeStats = $this->rangeEventStats($context, $start, $end)->keyBy('device_id');
        $latestStats = $this->latestEventStats($context)->keyBy('device_id');
        $openBatchStats = $this->openBatchStats($context)->groupBy('device_id');

        $alerts = collect();

        foreach ($devices as $device) {
            $deviceId = (int) $device->id;
            $range = $rangeStats->get($deviceId);
            $latest = $latestStats->get($deviceId);
            $openBatches = collect($openBatchStats->get($deviceId, []));

            $lastSeenAt = $device->last_seen_at ? CarbonImmutable::parse((string) $device->last_seen_at) : null;
            $latestIngestAt = $latest?->latest_recorded_at ? CarbonImmutable::parse((string) $latest->latest_recorded_at) : null;
            $rangeLatestAt = $range?->latest_recorded_at ? CarbonImmutable::parse((string) $range->latest_recorded_at) : null;

            if ($lastSeenAt === null) {
                $alerts->push($this->makeAlert(
                    severity: self::SEVERITY_WARN,
                    type: 'device.no_signal',
                    title: 'No telemetry yet',
                    message: 'This device has not reported any heartbeat yet.',
                    triggeredAt: $device->created_at ? CarbonImmutable::parse((string) $device->created_at) : $now,
                    device: $device
                ));
            } else {
                $minutesSinceSeen = $lastSeenAt->diffInMinutes($now);

                if ($minutesSinceSeen > 30) {
                    $alerts->push($this->makeAlert(
                        severity: self::SEVERITY_CRITICAL,
                        type: 'device.offline',
                        title: 'Device offline',
                        message: "No device heartbeat for {$minutesSinceSeen} minutes.",
                        triggeredAt: $lastSeenAt,
                        device: $device
                    ));
                } elseif ($minutesSinceSeen > 10) {
                    $alerts->push($this->makeAlert(
                        severity: self::SEVERITY_WARN,
                        type: 'device.delayed',
                        title: 'Device heartbeat delayed',
                        message: "Last heartbeat was {$minutesSinceSeen} minutes ago.",
                        triggeredAt: $lastSeenAt,
                        device: $device
                    ));
                }
            }

            if ($lastSeenAt !== null && $lastSeenAt->diffInMinutes($now) <= 30) {
                if ($latestIngestAt === null) {
                    $alerts->push($this->makeAlert(
                        severity: self::SEVERITY_WARN,
                        type: 'ingest.missing',
                        title: 'No ingest records yet',
                        message: 'The device is online but no egg records have been received.',
                        triggeredAt: $lastSeenAt,
                        device: $device
                    ));
                } elseif ($latestIngestAt->diffInMinutes($now) > 15) {
                    $alerts->push($this->makeAlert(
                        severity: self::SEVERITY_WARN,
                        type: 'ingest.gap',
                        title: 'Ingest gap detected',
                        message: 'The device is online but recent ingest has stopped for more than 15 minutes.',
                        triggeredAt: $latestIngestAt,
                        device: $device
                    ));
                }
            }

            $totalRangeRecords = (int) ($range?->total_records ?? 0);
            $rejectCount = (int) ($range?->reject_count ?? 0);
            $rejectRate = $totalRangeRecords > 0 ? ($rejectCount / $totalRangeRecords) : 0.0;

            if ($totalRangeRecords >= 10 && $rejectRate >= 0.20) {
                $alerts->push($this->makeAlert(
                    severity: self::SEVERITY_CRITICAL,
                    type: 'quality.reject_spike',
                    title: 'Reject spike detected',
                    message: sprintf('Reject rate is %s%% across %d records in the selected window.', number_format($rejectRate * 100, 1), $totalRangeRecords),
                    triggeredAt: $rangeLatestAt ?? $now,
                    device: $device
                ));
            } elseif ($totalRangeRecords >= 10 && $rejectRate >= 0.10) {
                $alerts->push($this->makeAlert(
                    severity: self::SEVERITY_WARN,
                    type: 'quality.reject_risk',
                    title: 'Reject rate rising',
                    message: sprintf('Reject rate is %s%% across %d records in the selected window.', number_format($rejectRate * 100, 1), $totalRangeRecords),
                    triggeredAt: $rangeLatestAt ?? $now,
                    device: $device
                ));
            }

            foreach ($openBatches as $openBatch) {
                $batchStartedAt = CarbonImmutable::parse((string) $openBatch->started_at);
                $batchLatestAt = $openBatch->latest_event_at ? CarbonImmutable::parse((string) $openBatch->latest_event_at) : null;
                $minutesSinceBatchActivity = $batchLatestAt ? $batchLatestAt->diffInMinutes($now) : $batchStartedAt->diffInMinutes($now);

                if ($minutesSinceBatchActivity > 15) {
                    $alerts->push($this->makeAlert(
                        severity: self::SEVERITY_WARN,
                        type: 'batch.stalled',
                        title: 'Open batch is stalled',
                        message: "Batch {$openBatch->batch_code} is still open but has no recent activity.",
                        triggeredAt: $batchLatestAt ?? $batchStartedAt,
                        device: $device,
                        batchCode: (string) $openBatch->batch_code,
                        extra: [
                            'batch_status' => 'open',
                        ]
                    ));
                }
            }
        }

        return $alerts
            ->sortBy([
                ['severity_rank', 'asc'],
                ['triggered_at', 'desc'],
            ])
            ->values();
    }

    /**
     * @param array{
     *   selected:array{farm_id:int|null,device_id:int|null},
     *   scope:array{farm_ids:array<int,int>,device_ids:array<int,int>}
     * } $context
     */
    private function scopedDeviceQuery(array $context)
    {
        $query = Device::query()
            ->join('farms', 'farms.id', '=', 'devices.farm_id')
            ->leftJoin('users as owners', 'owners.id', '=', 'devices.owner_user_id')
            ->select([
                'devices.id',
                'devices.farm_id',
                'devices.owner_user_id',
                'devices.module_board_name as device_name',
                'devices.primary_serial_no as device_serial',
                'devices.is_active',
                'devices.last_seen_at',
                'devices.last_seen_ip',
                'devices.created_at',
                'farms.farm_name',
                'owners.full_name as owner_name',
            ])
            ->where('devices.is_active', true);

        $scopeFarmIds = array_values(array_unique(array_map(static fn ($id): int => (int) $id, $context['scope']['farm_ids'] ?? [])));
        $scopeDeviceIds = array_values(array_unique(array_map(static fn ($id): int => (int) $id, $context['scope']['device_ids'] ?? [])));

        if ($scopeFarmIds === [] || $scopeDeviceIds === []) {
            return $query->whereRaw('1 = 0');
        }

        $query->whereIn('devices.farm_id', $scopeFarmIds)
            ->whereIn('devices.id', $scopeDeviceIds);

        $selectedFarmId = $context['selected']['farm_id'] ?? null;
        if (is_int($selectedFarmId) && $selectedFarmId > 0) {
            $query->where('devices.farm_id', $selectedFarmId);
        }

        $selectedDeviceId = $context['selected']['device_id'] ?? null;
        if (is_int($selectedDeviceId) && $selectedDeviceId > 0) {
            $query->where('devices.id', $selectedDeviceId);
        }

        return $query->orderBy('farms.farm_name')->orderBy('devices.module_board_name');
    }

    /**
     * @param array{
     *   selected:array{farm_id:int|null,device_id:int|null},
     *   scope:array{farm_ids:array<int,int>,device_ids:array<int,int>}
     * } $context
     */
    private function rangeEventStats(array $context, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return $this->scopedEventQuery($context, $start, $end)
            ->selectRaw('device_id')
            ->selectRaw('COUNT(*) AS total_records')
            ->selectRaw("SUM(CASE WHEN size_class = 'Reject' THEN 1 ELSE 0 END) AS reject_count")
            ->selectRaw('MAX(recorded_at) AS latest_recorded_at')
            ->groupBy('device_id')
            ->get();
    }

    /**
     * @param array{
     *   selected:array{farm_id:int|null,device_id:int|null},
     *   scope:array{farm_ids:array<int,int>,device_ids:array<int,int>}
     * } $context
     */
    private function latestEventStats(array $context): Collection
    {
        return $this->scopedEventQuery($context, null, null)
            ->selectRaw('device_id')
            ->selectRaw('MAX(recorded_at) AS latest_recorded_at')
            ->groupBy('device_id')
            ->get();
    }

    /**
     * @param array{
     *   selected:array{farm_id:int|null,device_id:int|null},
     *   scope:array{farm_ids:array<int,int>,device_ids:array<int,int>}
     * } $context
     */
    private function openBatchStats(array $context): Collection
    {
        if (!Schema::hasTable('production_batches')) {
            return collect();
        }

        $query = DB::table('production_batches as batches')
            ->leftJoin('device_ingest_events as events', 'events.production_batch_id', '=', 'batches.id')
            ->selectRaw('batches.id')
            ->selectRaw('batches.device_id')
            ->selectRaw('batches.batch_code')
            ->selectRaw('batches.started_at')
            ->selectRaw('MAX(events.recorded_at) AS latest_event_at')
            ->where('batches.status', 'open')
            ->groupBy('batches.id', 'batches.device_id', 'batches.batch_code', 'batches.started_at');

        $scopeFarmIds = array_values(array_unique(array_map(static fn ($id): int => (int) $id, $context['scope']['farm_ids'] ?? [])));
        $scopeDeviceIds = array_values(array_unique(array_map(static fn ($id): int => (int) $id, $context['scope']['device_ids'] ?? [])));

        if ($scopeFarmIds === [] || $scopeDeviceIds === []) {
            return collect();
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

        return $query->get();
    }

    /**
     * @param array{
     *   selected:array{farm_id:int|null,device_id:int|null},
     *   scope:array{farm_ids:array<int,int>,device_ids:array<int,int>}
     * } $context
     */
    private function scopedEventQuery(array $context, ?CarbonImmutable $start, ?CarbonImmutable $end)
    {
        $query = DB::table('device_ingest_events');

        if ($start !== null && $end !== null) {
            $query->whereBetween('recorded_at', [$start->toDateTimeString(), $end->toDateTimeString()]);
        }

        $scopeFarmIds = array_values(array_unique(array_map(static fn ($id): int => (int) $id, $context['scope']['farm_ids'] ?? [])));
        $scopeDeviceIds = array_values(array_unique(array_map(static fn ($id): int => (int) $id, $context['scope']['device_ids'] ?? [])));

        if ($scopeFarmIds === [] || $scopeDeviceIds === []) {
            return $query->whereRaw('1 = 0');
        }

        $query->whereIn('farm_id', $scopeFarmIds)
            ->whereIn('device_id', $scopeDeviceIds);

        $selectedFarmId = $context['selected']['farm_id'] ?? null;
        if (is_int($selectedFarmId) && $selectedFarmId > 0) {
            $query->where('farm_id', $selectedFarmId);
        }

        $selectedDeviceId = $context['selected']['device_id'] ?? null;
        if (is_int($selectedDeviceId) && $selectedDeviceId > 0) {
            $query->where('device_id', $selectedDeviceId);
        }

        return $query;
    }

    private function makeAlert(
        string $severity,
        string $type,
        string $title,
        string $message,
        CarbonImmutable $triggeredAt,
        object $device,
        ?string $batchCode = null,
        array $extra = []
    ): array {
        $severityRank = match ($severity) {
            self::SEVERITY_CRITICAL => 1,
            self::SEVERITY_WARN => 2,
            default => 3,
        };

        return array_merge([
            'severity' => $severity,
            'severity_rank' => $severityRank,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'triggered_at' => $triggeredAt->toIso8601String(),
            'device_id' => (int) $device->id,
            'device_name' => (string) $device->device_name,
            'device_serial' => (string) $device->device_serial,
            'farm_id' => (int) $device->farm_id,
            'farm_name' => (string) $device->farm_name,
            'owner_name' => $device->owner_name ? (string) $device->owner_name : null,
            'batch_code' => $batchCode,
        ], $extra);
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
}
