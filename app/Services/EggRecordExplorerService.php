<?php

namespace App\Services;

use App\Support\EggUid;
use App\Support\EggSizeClass;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use JsonException;
use stdClass;

class EggRecordExplorerService
{
    private const LIVE_PAGE_SIZE = 8;

    /**
     * @param array{
     *   range:string,
     *   selected:array{farm_id:int|null,device_id:int|null},
     *   scope:array{farm_ids:array<int,int>,device_ids:array<int,int>}
     * } $context
     * @param array{
     *   egg_uid:string,
     *   batch_code:string,
     *   size_class:string|null,
     *   weight_min:float|null,
     *   weight_max:float|null
     * } $filters
     * @return array{
     *   stats:stdClass,
     *   records:LengthAwarePaginator<int, stdClass>,
     *   window:array{start:CarbonImmutable,end:CarbonImmutable}
     * }
     */
    public function buildList(array $context, array $filters): array
    {
        $window = $this->resolveWindow((string) ($context['range'] ?? DashboardContextService::RANGE_1D));
        $recordsQuery = $this->scopedEventQuery($context, $window['start'], $window['end'], $filters);

        /** @var LengthAwarePaginator<int, stdClass> $records */
        $records = (clone $recordsQuery)
            ->orderByDesc('events.recorded_at')
            ->orderByDesc('events.id')
            ->paginate(20)
            ->withQueryString();
        $records->setCollection($this->enrichRecordCollection($records->getCollection()));

        $stats = DB::query()
            ->fromSub($recordsQuery, 'record_rows')
            ->selectRaw('COUNT(*) AS total_records')
            ->selectRaw('COUNT(DISTINCT production_batch_id) AS unique_batches')
            ->selectRaw('COALESCE(SUM(CASE WHEN size_class = ? THEN 1 ELSE 0 END), 0) AS reject_count', [EggSizeClass::REJECT])
            ->selectRaw('COALESCE(AVG(weight_grams), 0) AS avg_weight_grams')
            ->selectRaw('COALESCE(SUM(weight_grams), 0) AS total_weight_grams')
            ->selectRaw('MAX(recorded_at) AS latest_recorded_at')
            ->first();

        return [
            'stats' => $stats ?? $this->emptyStats(),
            'records' => $records,
            'window' => $window,
        ];
    }

    /**
     * @param array{
     *   range:string,
     *   selected:array{farm_id:int|null,device_id:int|null},
     *   scope:array{farm_ids:array<int,int>,device_ids:array<int,int>}
     * } $context
     * @param array{
     *   egg_uid:string,
     *   batch_code:string,
     *   size_class:string|null,
     *   weight_min:float|null,
     *   weight_max:float|null
     * } $filters
     * @return Collection<int, stdClass>
     */
    public function exportRows(array $context, array $filters): Collection
    {
        $window = $this->resolveWindow((string) ($context['range'] ?? DashboardContextService::RANGE_1D));

        return $this->enrichRecordCollection($this->scopedEventQuery($context, $window['start'], $window['end'], $filters)
            ->orderByDesc('events.recorded_at')
            ->orderByDesc('events.id')
            ->get());
    }

    /**
     * @param array{
     *   range:string,
     *   selected:array{farm_id:int|null,device_id:int|null},
     *   scope:array{farm_ids:array<int,int>,device_ids:array<int,int>}
     * } $context
     * @param array{
     *   egg_uid:string,
     *   batch_code:string,
     *   size_class:string|null,
     *   weight_min:float|null,
     *   weight_max:float|null
     * } $filters
     * @return array{
     *   as_of:string,
     *   refresh_interval_seconds:int,
     *   pagination:array{
     *     current_page:int,
     *     last_page:int,
     *     per_page:int,
     *     total:int,
     *     from:int|null,
     *     to:int|null
     *   },
     *   stats:array{
     *     total_records:int,
     *     unique_batches:int,
     *     reject_count:int,
     *     avg_weight_grams:float,
     *     total_weight_grams:float,
     *     latest_recorded_at:string|null,
     *     observed_gap_seconds:float|null
     *   },
     *   size_tally:array<int, array{size_class:string,total:int}>,
     *   recent_records:array<int, array{
     *     id:int,
     *     egg_uid:string|null,
     *     batch_code:string|null,
     *     batch_status:string|null,
     *     size_class:string,
     *     weight_grams:float,
     *     recorded_at:string|null,
     *     created_at:string|null,
     *     farm_name:string|null,
     *     owner_name:string|null,
     *     device_name:string|null,
     *     device_serial:string|null
     *   }>
     * }
     */
    public function buildLiveSnapshot(array $context, array $filters, int $page = 1): array
    {
        $window = $this->resolveWindow((string) ($context['range'] ?? DashboardContextService::RANGE_1D));
        $recordsQuery = $this->scopedEventQuery($context, $window['start'], $window['end'], $filters);

        $recentGapRecords = (clone $recordsQuery)
            ->orderByDesc('events.recorded_at')
            ->orderByDesc('events.id')
            ->limit(8)
            ->get();

        /** @var LengthAwarePaginator<int, stdClass> $liveRecords */
        $liveRecords = (clone $recordsQuery)
            ->orderByDesc('events.recorded_at')
            ->orderByDesc('events.id')
            ->paginate(self::LIVE_PAGE_SIZE, ['*'], 'live_page', max(1, $page));
        $liveRecords->setCollection($this->enrichRecordCollection($liveRecords->getCollection()));

        $stats = DB::query()
            ->fromSub($recordsQuery, 'record_rows')
            ->selectRaw('COUNT(*) AS total_records')
            ->selectRaw('COUNT(DISTINCT production_batch_id) AS unique_batches')
            ->selectRaw('COALESCE(SUM(CASE WHEN size_class = ? THEN 1 ELSE 0 END), 0) AS reject_count', [EggSizeClass::REJECT])
            ->selectRaw('COALESCE(AVG(weight_grams), 0) AS avg_weight_grams')
            ->selectRaw('COALESCE(SUM(weight_grams), 0) AS total_weight_grams')
            ->selectRaw('MAX(recorded_at) AS latest_recorded_at')
            ->first();

        $sizeCounts = DB::query()
            ->fromSub($recordsQuery, 'record_rows')
            ->select('size_class')
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('size_class')
            ->get()
            ->keyBy(static fn (stdClass $row): string => (string) $row->size_class);

        $observedGapSeconds = $recentGapRecords
            ->filter(static fn (stdClass $row): bool => $row->recorded_at !== null && $row->created_at !== null)
            ->map(static function (stdClass $row): int {
                $recordedAt = CarbonImmutable::parse((string) $row->recorded_at);
                $createdAt = CarbonImmutable::parse((string) $row->created_at);

                return max(0, $createdAt->getTimestamp() - $recordedAt->getTimestamp());
            })
            ->avg();

        return [
            'as_of' => CarbonImmutable::now()->toIso8601String(),
            'refresh_interval_seconds' => 2,
            'pagination' => [
                'current_page' => $liveRecords->currentPage(),
                'last_page' => $liveRecords->lastPage(),
                'per_page' => $liveRecords->perPage(),
                'total' => $liveRecords->total(),
                'from' => $liveRecords->firstItem(),
                'to' => $liveRecords->lastItem(),
            ],
            'stats' => [
                'total_records' => (int) ($stats->total_records ?? 0),
                'unique_batches' => (int) ($stats->unique_batches ?? 0),
                'reject_count' => (int) ($stats->reject_count ?? 0),
                'avg_weight_grams' => round((float) ($stats->avg_weight_grams ?? 0), 2),
                'total_weight_grams' => round((float) ($stats->total_weight_grams ?? 0), 2),
                'latest_recorded_at' => $stats->latest_recorded_at,
                'observed_gap_seconds' => $observedGapSeconds !== null ? round((float) $observedGapSeconds, 1) : null,
            ],
            'size_tally' => collect(EggSizeClass::values())
                ->map(static function (string $sizeClass) use ($sizeCounts): array {
                    $row = $sizeCounts->get($sizeClass);

                    return [
                        'size_class' => $sizeClass,
                        'total' => (int) ($row->total ?? 0),
                    ];
                })
                ->values()
                ->all(),
            'recent_records' => collect($liveRecords->items())
                ->map(static function (stdClass $row): array {
                    return [
                        'id' => (int) $row->id,
                        'egg_uid' => $row->egg_uid ? (string) $row->egg_uid : null,
                        'batch_code' => $row->batch_code ? (string) $row->batch_code : null,
                        'batch_status' => $row->batch_status ? (string) $row->batch_status : null,
                        'size_class' => (string) $row->size_class,
                        'weight_grams' => round((float) $row->weight_grams, 2),
                        'recorded_at' => $row->recorded_at,
                        'created_at' => $row->created_at,
                        'farm_name' => $row->farm_name ? (string) $row->farm_name : null,
                        'owner_name' => $row->owner_name ? (string) $row->owner_name : null,
                        'device_name' => $row->device_name ? (string) $row->device_name : null,
                        'device_serial' => $row->device_serial ? (string) $row->device_serial : null,
                        'source_ip' => $row->source_ip ? (string) $row->source_ip : null,
                        'esp32_mac_address' => $row->esp32_mac_address ? (string) $row->esp32_mac_address : null,
                        'router_mac_address' => $row->router_mac_address ? (string) $row->router_mac_address : null,
                        'wifi_ssid' => $row->wifi_ssid ? (string) $row->wifi_ssid : null,
                    ];
                })
                ->values()
                ->all(),
        ];
    }

    /**
     * @param array{
     *   range:string,
     *   selected:array{farm_id:int|null,device_id:int|null},
     *   scope:array{farm_ids:array<int,int>,device_ids:array<int,int>}
     * } $context
     * @param array{
     *   egg_uid:string,
     *   batch_code:string,
     *   size_class:string|null,
     *   weight_min:float|null,
     *   weight_max:float|null
     * } $filters
     */
    public function scopedEventQuery(array $context, CarbonImmutable $start, CarbonImmutable $end, array $filters)
    {
        $query = DB::table('device_ingest_events as events')
            ->join('devices', 'devices.id', '=', 'events.device_id')
            ->join('farms', 'farms.id', '=', 'events.farm_id')
            ->leftJoin('users as owners', 'owners.id', '=', 'events.owner_user_id');

        if (Schema::hasTable('production_batches')) {
            $query->leftJoin('production_batches as batches', 'batches.id', '=', 'events.production_batch_id');
        }

        $scopeFarmIds = array_values(array_unique(array_map(static fn ($id): int => (int) $id, $context['scope']['farm_ids'] ?? [])));
        $scopeDeviceIds = array_values(array_unique(array_map(static fn ($id): int => (int) $id, $context['scope']['device_ids'] ?? [])));

        if ($scopeFarmIds === [] || $scopeDeviceIds === []) {
            return $query->whereRaw('1 = 0');
        }

        $query->whereIn('events.farm_id', $scopeFarmIds)
            ->whereIn('events.device_id', $scopeDeviceIds)
            ->whereBetween('events.recorded_at', [$start->toDateTimeString(), $end->toDateTimeString()]);

        $selectedFarmId = $context['selected']['farm_id'] ?? null;
        if (is_int($selectedFarmId) && $selectedFarmId > 0) {
            $query->where('events.farm_id', $selectedFarmId);
        }

        $selectedDeviceId = $context['selected']['device_id'] ?? null;
        if (is_int($selectedDeviceId) && $selectedDeviceId > 0) {
            $query->where('events.device_id', $selectedDeviceId);
        }

        $eggUid = EggUid::normalize((string) ($filters['egg_uid'] ?? ''));
        if ($eggUid !== null) {
            $query->whereRaw('LOWER(events.egg_uid) LIKE ?', ['%' . strtolower($eggUid) . '%']);
        }

        $batchCode = trim((string) ($filters['batch_code'] ?? ''));
        if ($batchCode !== '') {
            $query->where('events.batch_code', 'like', '%' . Str::replace(['%', '_'], ['\%', '\_'], $batchCode) . '%');
        }

        $sizeClass = $filters['size_class'] ?? null;
        if (is_string($sizeClass) && $sizeClass !== '') {
            $query->where('events.size_class', $sizeClass);
        }

        $weightMin = $filters['weight_min'] ?? null;
        if (is_float($weightMin) || is_int($weightMin)) {
            $query->where('events.weight_grams', '>=', (float) $weightMin);
        }

        $weightMax = $filters['weight_max'] ?? null;
        if (is_float($weightMax) || is_int($weightMax)) {
            $query->where('events.weight_grams', '<=', (float) $weightMax);
        }

        return $query->select([
            'events.id',
            'events.egg_uid',
            'events.batch_code',
            'events.production_batch_id',
            'events.weight_grams',
            'events.size_class',
            'events.recorded_at',
            'events.created_at',
            'events.source_ip',
            'events.raw_payload_json',
            'events.farm_id',
            'events.device_id',
            'farms.farm_name',
            'devices.module_board_name as device_name',
            'devices.primary_serial_no as device_serial',
            'owners.full_name as owner_name',
            DB::raw(Schema::hasTable('production_batches') ? 'batches.status as batch_status' : 'NULL as batch_status'),
        ]);
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

    /**
     * @param Collection<int, stdClass> $records
     * @return Collection<int, stdClass>
     */
    private function enrichRecordCollection(Collection $records): Collection
    {
        return $records->map(fn (stdClass $record): stdClass => $this->enrichRecord($record));
    }

    private function enrichRecord(stdClass $record): stdClass
    {
        $network = $this->extractNetworkMetadata($record->raw_payload_json ?? null);

        $record->esp32_mac_address = $network['esp32_mac_address'];
        $record->router_mac_address = $network['router_mac_address'];
        $record->wifi_ssid = $network['wifi_ssid'];

        return $record;
    }

    /**
     * @return array{
     *   esp32_mac_address:string|null,
     *   router_mac_address:string|null,
     *   wifi_ssid:string|null
     * }
     */
    private function extractNetworkMetadata(mixed $rawPayloadJson): array
    {
        $payload = [];

        if (is_string($rawPayloadJson) && trim($rawPayloadJson) !== '') {
            try {
                $decoded = json_decode($rawPayloadJson, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            } catch (JsonException) {
                $payload = [];
            }
        }

        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];

        return [
            'esp32_mac_address' => $this->normalizeMacAddress($this->firstNonEmptyValue(
                $metadata,
                $payload,
                ['esp32_mac', 'board_mac', 'device_mac', 'mac_address', 'mac']
            )),
            'router_mac_address' => $this->normalizeMacAddress($this->firstNonEmptyValue(
                $metadata,
                $payload,
                ['router_mac', 'gateway_mac', 'access_point_mac', 'ap_mac', 'bssid']
            )),
            'wifi_ssid' => $this->normalizeTextValue($this->firstNonEmptyValue(
                $metadata,
                $payload,
                ['wifi_ssid', 'ssid', 'wifi_name', 'network_name']
            )),
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $payload
     * @param array<int, string> $keys
     */
    private function firstNonEmptyValue(array $metadata, array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $metadata[$key] ?? $payload[$key] ?? null;
            if (!is_scalar($value)) {
                continue;
            }

            $normalized = trim((string) $value);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    private function normalizeTextValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim($value);

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeMacAddress(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = strtoupper(preg_replace('/[^A-F0-9]/i', '', $value) ?? '');
        if (strlen($clean) !== 12) {
            $trimmed = trim($value);

            return $trimmed !== '' ? strtoupper($trimmed) : null;
        }

        return implode(':', str_split($clean, 2));
    }

    private function emptyStats(): stdClass
    {
        return (object) [
            'total_records' => 0,
            'unique_batches' => 0,
            'reject_count' => 0,
            'avg_weight_grams' => 0,
            'total_weight_grams' => 0,
            'latest_recorded_at' => null,
        ];
    }
}
