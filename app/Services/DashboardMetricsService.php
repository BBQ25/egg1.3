<?php

namespace App\Services;

use App\Models\User;
use App\Support\EggSizeClass;
use App\Support\EggTrayFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardMetricsService
{
    /**
     * @param array{
     *   range:string,
     *   switcher:array{farms:array<int, array<string,mixed>>,devices:array<int, array<string,mixed>>},
     *   selected:array{farm_id:int|null,device_id:int|null,farm:array<string,mixed>|null,device:array<string,mixed>|null},
     *   scope:array{role:string,farm_ids:array<int,int>,device_ids:array<int,int>}
     * } $context
     * @return array{
     *   ok:bool,
     *   as_of:string,
     *   range:string,
     *   context:array<string,mixed>,
     *   summary:array<string,mixed>,
     *   size_breakdown:array<int,array<string,mixed>>,
     *   activity_breakdown:array<int,array<string,mixed>>,
     *   band_breakdown:array<string,mixed>,
     *   top_active:array<int,array<string,mixed>>,
     *   timeline:array<int,array<string,mixed>>
     * }
     */
    public function build(User $user, array $context): array
    {
        $now = CarbonImmutable::now();
        $window = $this->resolveWindow($context['range'], $now);
        $scopeHash = sha1(json_encode([
            'role' => $context['scope']['role'],
            'farm_ids' => $context['scope']['farm_ids'],
            'device_ids' => $context['scope']['device_ids'],
        ]) ?: '');

        $cacheKey = sprintf(
            'dashboard:v2:%d:%s:%s:%s:%s:%s',
            (int) $user->id,
            $context['range'],
            $context['selected']['farm_id'] ?? 'all',
            $context['selected']['device_id'] ?? 'all',
            $scopeHash,
            $window['start']->format('YmdHi')
        );

        return Cache::remember($cacheKey, $now->addSeconds(10), function () use ($user, $context, $window, $now): array {
            return $this->compilePayload($user, $context, $window, $now);
        });
    }

    /**
     * @param array{
     *   start:CarbonImmutable,
     *   end:CarbonImmutable,
     *   bucket_sql:string,
     *   interval:'hour'|'day'
     * } $window
     * @param array{
     *   range:string,
     *   switcher:array{farms:array<int, array<string,mixed>>,devices:array<int, array<string,mixed>>},
     *   selected:array{farm_id:int|null,device_id:int|null,farm:array<string,mixed>|null,device:array<string,mixed>|null},
     *   scope:array{role:string,farm_ids:array<int,int>,device_ids:array<int,int>}
     * } $context
     * @return array{
     *   ok:bool,
     *   as_of:string,
     *   range:string,
     *   context:array<string,mixed>,
     *   summary:array<string,mixed>,
     *   size_breakdown:array<int,array<string,mixed>>,
     *   activity_breakdown:array<int,array<string,mixed>>,
     *   band_breakdown:array<string,mixed>,
     *   top_active:array<int,array<string,mixed>>,
     *   timeline:array<int,array<string,mixed>>
     * }
     */
    private function compilePayload(User $user, array $context, array $window, CarbonImmutable $asOf): array
    {
        $hasIngest = Schema::hasTable('device_ingest_events');
        $hasIntake = Schema::hasTable('egg_intake_records');

        $ingestBase = $hasIngest
            ? $this->scopedIngestBaseQuery($user, $context, $window['start'], $window['end'])
            : null;

        $intakeBase = $hasIntake
            ? $this->scopedIntakeBaseQuery($user, $context, $window['start'], $window['end'])
            : null;

        $sizeBreakdown = $this->buildSizeBreakdown($ingestBase, $intakeBase);
        $sizeByClass = [];
        foreach ($sizeBreakdown as $row) {
            $sizeByClass[(string) $row['size_class']] = $row;
        }

        $ingestSummary = $this->collectIngestSummary($ingestBase);
        $intakeSummary = $this->collectIntakeSummary($intakeBase);

        $totalEggs = (int) $ingestSummary['eggs'] + (int) $intakeSummary['eggs'];
        $goodEggs = (int) $ingestSummary['good_eggs'] + (int) $intakeSummary['good_eggs'];
        $qualityScore = $totalEggs > 0 ? round(($goodEggs / $totalEggs) * 100, 1) : 0.0;
        $totalWeight = (float) $ingestSummary['weight_sum'] + (float) $intakeSummary['weight_sum'];

        $activeFarmIds = array_values(array_unique(array_filter(array_merge(
            $this->pluckDistinctInt($ingestBase, 'farm_id'),
            $this->pluckDistinctInt($intakeBase, 'farm_id')
        ), static fn ($value): bool => is_int($value) && $value > 0)));

        $activeDeviceIds = $this->pluckDistinctInt($ingestBase, 'device_id');

        $bandBreakdown = $this->buildBandBreakdown($ingestBase, $intakeBase);
        $timeline = $this->buildTimeline($ingestBase, $intakeBase, $window);
        $topActive = $this->buildTopActive($context, $sizeByClass, $ingestBase, $intakeBase);

        $recentWindowStart = $asOf->subMinutes(10);
        $recentEggs = $hasIngest ? $this->countRecentIngestEggs($user, $context, $recentWindowStart, $asOf) : 0;
        $selectedDevice = $context['selected']['device'];
        $selectedFarm = $context['selected']['farm'];

        $hasDeviceContext = is_array($selectedDevice);
        $lastSeen = $hasDeviceContext ? $this->parseIsoTime((string) ($selectedDevice['last_seen_at'] ?? '')) : null;
        $createdAt = $hasDeviceContext ? $this->parseIsoTime((string) ($selectedDevice['created_at'] ?? '')) : null;

        $health = 'No active device selected';
        $healthTone = 'neutral';
        if ($lastSeen !== null) {
            $diffMinutes = $lastSeen->diffInMinutes($asOf);
            if ($diffMinutes <= 5) {
                $health = 'Healthy live feed';
                $healthTone = 'good';
            } elseif ($diffMinutes <= 30) {
                $health = 'Delayed feed';
                $healthTone = 'warn';
            } else {
                $health = 'Offline feed';
                $healthTone = 'bad';
            }
        } elseif ($hasDeviceContext) {
            $health = 'No telemetry yet';
            $healthTone = 'warn';
        }

        $downUtilization = $totalEggs > 0 ? (int) round(((int) $ingestSummary['eggs'] / $totalEggs) * 100) : 0;
        $upUtilization = $totalEggs > 0 ? (int) round(((int) $intakeSummary['eggs'] / $totalEggs) * 100) : 0;

        $summary = [
            'total_eggs' => $totalEggs,
            'total_weight_grams' => round($totalWeight, 2),
            'quality_score' => $qualityScore,
            'active_farms' => count($activeFarmIds),
            'active_devices' => count($activeDeviceIds),
            'ingest_events' => (int) $ingestSummary['eggs'],
            'manual_entries' => (int) $intakeSummary['eggs'],
            'profile' => [
                'has_device' => $hasDeviceContext,
                'device_name' => $hasDeviceContext ? (string) ($selectedDevice['name'] ?? '') : 'No active device selected',
                'serial' => $hasDeviceContext ? (string) ($selectedDevice['serial'] ?? '') : null,
                'farm_name' => $hasDeviceContext ? ($selectedDevice['farm_name'] ?? null) : ($selectedFarm['name'] ?? null),
                'owner_name' => $hasDeviceContext ? ($selectedDevice['owner_name'] ?? null) : ($selectedFarm['owner_name'] ?? null),
                'last_seen_at' => $lastSeen?->toIso8601String(),
                'last_seen_label' => $lastSeen ? $lastSeen->diffForHumans($asOf, true) . ' ago' : 'No signal',
                'created_at' => $createdAt?->toIso8601String(),
                'system_uptime_label' => $createdAt ? $createdAt->diffForHumans($asOf, true) : 'N/A',
                'wan_ip' => $hasDeviceContext ? ((string) ($selectedDevice['last_seen_ip'] ?? '--')) : '--',
                'gateway_ip' => $hasDeviceContext ? $this->pseudoGatewayIp((int) ($selectedDevice['id'] ?? 0)) : '--',
                'internet_name' => $hasDeviceContext ? 'PoultryPulse Edge Link' : 'No active uplink',
                'internet_uptime_percent' => $qualityScore > 0 ? (int) round($qualityScore) : 0,
                'ingest_health' => $health,
                'ingest_health_tone' => $healthTone,
                'down_utilization_pct' => max(0, min(100, $downUtilization)),
                'up_utilization_pct' => max(0, min(100, $upUtilization)),
                'events_per_minute' => round($recentEggs / 10, 2),
                'down_link_label' => sprintf('%s rec/min', number_format((int) max(0, round($recentEggs * 0.62)))),
                'up_link_label' => sprintf('%s rec/min', number_format((int) max(0, round($recentEggs * 0.38)))),
                'last_tested_label' => $asOf->format('M j, g:i A'),
            ],
        ];

        $activityBreakdown = [
            [
                'label' => 'Automated Ingest',
                'total' => (int) $ingestSummary['eggs'],
                'total_label' => $this->activityTotalLabel('tray', (int) $ingestSummary['eggs']),
                'activity_percent' => $totalEggs > 0 ? round(((int) $ingestSummary['eggs'] / $totalEggs) * 100, 1) : 0.0,
                'score_percent' => $ingestSummary['eggs'] > 0
                    ? round(((int) $ingestSummary['good_eggs'] / max(1, (int) $ingestSummary['eggs'])) * 100, 1)
                    : 0.0,
                'color' => '#2a6df5',
            ],
            [
                'label' => 'Manual Intake',
                'total' => (int) $intakeSummary['eggs'],
                'total_label' => $this->activityTotalLabel('tray', (int) $intakeSummary['eggs']),
                'activity_percent' => $totalEggs > 0 ? round(((int) $intakeSummary['eggs'] / $totalEggs) * 100, 1) : 0.0,
                'score_percent' => $intakeSummary['eggs'] > 0
                    ? round(((int) $intakeSummary['good_eggs'] / max(1, (int) $intakeSummary['eggs'])) * 100, 1)
                    : 0.0,
                'color' => '#33b36b',
            ],
            [
                'label' => 'Farm Coverage',
                'total' => count($activeFarmIds),
                'total_label' => $this->activityTotalLabel('farm', count($activeFarmIds)),
                'activity_percent' => count($context['scope']['farm_ids']) > 0
                    ? round((count($activeFarmIds) / count($context['scope']['farm_ids'])) * 100, 1)
                    : 0.0,
                'score_percent' => $qualityScore,
                'color' => '#0aa2c0',
            ],
            [
                'label' => 'Device Coverage',
                'total' => count($activeDeviceIds),
                'total_label' => $this->activityTotalLabel('device', count($activeDeviceIds)),
                'activity_percent' => count($context['scope']['device_ids']) > 0
                    ? round((count($activeDeviceIds) / count($context['scope']['device_ids'])) * 100, 1)
                    : 0.0,
                'score_percent' => $qualityScore,
                'color' => '#7a5ef8',
            ],
        ];

        return [
            'ok' => true,
            'as_of' => $asOf->toIso8601String(),
            'range' => $context['range'],
            'context' => [
                'selected' => $context['selected'],
                'switcher' => $context['switcher'],
                'scope_role' => $context['scope']['role'],
                'has_context_filters' => $context['switcher']['farms'] !== [] || $context['switcher']['devices'] !== [],
            ],
            'summary' => $summary,
            'size_breakdown' => $sizeBreakdown,
            'activity_breakdown' => $activityBreakdown,
            'band_breakdown' => $bandBreakdown,
            'top_active' => $topActive,
            'timeline' => $timeline,
        ];
    }

    /**
     * @return array{
     *   start:CarbonImmutable,
     *   end:CarbonImmutable,
     *   bucket_sql:string,
     *   interval:'hour'|'day'
     * }
     */
    private function resolveWindow(string $range, CarbonImmutable $now): array
    {
        $driver = DB::connection()->getDriverName();
        $isSqlite = $driver === 'sqlite';

        $dayBucketSql = $isSqlite
            ? "strftime('%Y-%m-%d 00:00:00', recorded_at)"
            : "DATE_FORMAT(recorded_at, '%Y-%m-%d 00:00:00')";

        $hourBucketSql = $isSqlite
            ? "strftime('%Y-%m-%d %H:00:00', recorded_at)"
            : "DATE_FORMAT(recorded_at, '%Y-%m-%d %H:00:00')";

        if ($range === DashboardContextService::RANGE_1W) {
            return [
                'start' => $now->subDays(6)->startOfDay(),
                'end' => $now,
                'bucket_sql' => $dayBucketSql,
                'interval' => 'day',
            ];
        }

        if ($range === DashboardContextService::RANGE_1M) {
            return [
                'start' => $now->subDays(29)->startOfDay(),
                'end' => $now,
                'bucket_sql' => $dayBucketSql,
                'interval' => 'day',
            ];
        }

        return [
            'start' => $now->subHours(23)->startOfHour(),
            'end' => $now,
            'bucket_sql' => $hourBucketSql,
            'interval' => 'hour',
        ];
    }

    /**
     * @param array{
     *   selected:array{farm_id:int|null,device_id:int|null},
     *   scope:array{role:string,farm_ids:array<int,int>}
     * } $context
     */
    private function scopedIngestBaseQuery(User $user, array $context, CarbonImmutable $start, CarbonImmutable $end)
    {
        $query = DB::table('device_ingest_events')
            ->whereBetween('recorded_at', [$start->toDateTimeString(), $end->toDateTimeString()]);

        if ($context['selected']['farm_id'] !== null) {
            $query->where('farm_id', (int) $context['selected']['farm_id']);
        }

        if ($context['selected']['device_id'] !== null) {
            $query->where('device_id', (int) $context['selected']['device_id']);
        }

        if ($context['scope']['role'] === 'owner') {
            $query->where('owner_user_id', (int) $user->id);
        } elseif ($context['scope']['role'] === 'staff') {
            $farmIds = $context['scope']['farm_ids'];
            if ($farmIds === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('farm_id', $farmIds);
            }
        } elseif ($context['scope']['role'] === 'customer') {
            $query->where('owner_user_id', (int) $user->id);
        }

        return $query;
    }

    /**
     * @param array{
     *   selected:array{farm_id:int|null,device_id:int|null},
     *   scope:array{role:string,farm_ids:array<int,int>}
     * } $context
     */
    private function scopedIntakeBaseQuery(User $user, array $context, CarbonImmutable $start, CarbonImmutable $end)
    {
        $query = DB::table('egg_intake_records')
            ->whereBetween('recorded_at', [$start->toDateTimeString(), $end->toDateTimeString()]);

        if ($context['selected']['farm_id'] !== null) {
            $query->where('farm_id', (int) $context['selected']['farm_id']);
        }

        if ($context['selected']['device_id'] !== null) {
            $query->whereRaw('1 = 0');
        }

        if ($context['scope']['role'] === 'owner') {
            $farmIds = $context['scope']['farm_ids'];
            if ($farmIds === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('farm_id', $farmIds);
            }
        } elseif ($context['scope']['role'] === 'staff') {
            $farmIds = $context['scope']['farm_ids'];
            if ($farmIds === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('farm_id', $farmIds);
            }
        } elseif ($context['scope']['role'] === 'customer') {
            $query->where('created_by_user_id', (int) $user->id);
        }

        return $query;
    }

    /**
     * @param \Illuminate\Database\Query\Builder|null $ingestBase
     * @param \Illuminate\Database\Query\Builder|null $intakeBase
     * @return array<int, array{
     *   size_class:string,
     *   count:int,
     *   percent:float,
     *   avg_weight:float,
     *   color:string
     * }>
     */
    private function buildSizeBreakdown($ingestBase, $intakeBase): array
    {
        $classes = EggSizeClass::values();
        $template = [];
        foreach ($classes as $class) {
            $template[$class] = [
                'size_class' => $class,
                'count' => 0,
                'weighted_count' => 0,
                'weight_sum' => 0.0,
            ];
        }

        if ($ingestBase !== null) {
            $ingestRows = (clone $ingestBase)
                ->selectRaw('size_class, COUNT(*) AS eggs')
                ->selectRaw('SUM(CASE WHEN weight_grams > 0 THEN 1 ELSE 0 END) AS weighted_eggs')
                ->selectRaw('SUM(CASE WHEN weight_grams > 0 THEN weight_grams ELSE 0 END) AS weight_sum')
                ->groupBy('size_class')
                ->get();

            foreach ($ingestRows as $row) {
                $key = (string) $row->size_class;
                if (!isset($template[$key])) {
                    continue;
                }

                $template[$key]['count'] += (int) $row->eggs;
                $template[$key]['weighted_count'] += (int) ($row->weighted_eggs ?? 0);
                $template[$key]['weight_sum'] += (float) $row->weight_sum;
            }
        }

        if ($intakeBase !== null) {
            $intakeRows = (clone $intakeBase)
                ->selectRaw('size_class, SUM(quantity) AS eggs')
                ->selectRaw('SUM(CASE WHEN weight_grams > 0 THEN quantity ELSE 0 END) AS weighted_eggs')
                ->selectRaw('SUM(CASE WHEN weight_grams > 0 THEN weight_grams * quantity ELSE 0 END) AS weight_sum')
                ->groupBy('size_class')
                ->get();

            foreach ($intakeRows as $row) {
                $key = (string) $row->size_class;
                if (!isset($template[$key])) {
                    continue;
                }

                $template[$key]['count'] += (int) $row->eggs;
                $template[$key]['weighted_count'] += (int) ($row->weighted_eggs ?? 0);
                $template[$key]['weight_sum'] += (float) $row->weight_sum;
            }
        }

        $total = array_sum(array_map(static fn (array $row): int => (int) $row['count'], $template));
        $palette = [
            EggSizeClass::REJECT => '#ff5630',
            EggSizeClass::PEEWEE => '#7a5ef8',
            EggSizeClass::PULLET => '#25b8d9',
            EggSizeClass::SMALL => '#3bb273',
            EggSizeClass::MEDIUM => '#2f80ed',
            EggSizeClass::LARGE => '#5b8ff9',
            EggSizeClass::EXTRA_LARGE => '#1a56db',
            EggSizeClass::JUMBO => '#15213a',
        ];

        $rows = [];
        foreach ($template as $row) {
            $count = (int) $row['count'];
            $weightedCount = (int) ($row['weighted_count'] ?? 0);
            $rows[] = [
                'size_class' => (string) $row['size_class'],
                'count' => $count,
                'percent' => $total > 0 ? round(($count / $total) * 100, 1) : 0.0,
                'avg_weight' => $weightedCount > 0 ? round(((float) $row['weight_sum']) / $weightedCount, 2) : 0.0,
                'color' => $palette[(string) $row['size_class']] ?? '#6b7280',
            ];
        }

        return $rows;
    }

    /**
     * @param \Illuminate\Database\Query\Builder|null $ingestBase
     * @param \Illuminate\Database\Query\Builder|null $intakeBase
     * @return array{
     *   light:array<string,mixed>,
     *   standard:array<string,mixed>,
     *   heavy:array<string,mixed>
     * }
     */
    private function buildBandBreakdown($ingestBase, $intakeBase): array
    {
        $bands = [
            'light' => [
                'key' => 'light',
                'label' => 'Under 53g',
                'total' => 0,
                'classes' => [],
            ],
            'standard' => [
                'key' => 'standard',
                'label' => '53g - 62.99g',
                'total' => 0,
                'classes' => [],
            ],
            'heavy' => [
                'key' => 'heavy',
                'label' => '63g and above',
                'total' => 0,
                'classes' => [],
            ],
        ];

        if ($ingestBase !== null) {
            $rows = (clone $ingestBase)
                ->where('weight_grams', '>', 0)
                ->selectRaw("\n                    CASE\n                        WHEN weight_grams < 53 THEN 'light'\n                        WHEN weight_grams < 63 THEN 'standard'\n                        ELSE 'heavy'\n                    END AS band_key,\n                    size_class,\n                    COUNT(*) AS eggs\n                ")
                ->groupBy('band_key', 'size_class')
                ->get();

            foreach ($rows as $row) {
                $band = (string) $row->band_key;
                if (!isset($bands[$band])) {
                    continue;
                }

                $count = (int) $row->eggs;
                $bands[$band]['total'] += $count;
                $bands[$band]['classes'][(string) $row->size_class] = ($bands[$band]['classes'][(string) $row->size_class] ?? 0) + $count;
            }
        }

        if ($intakeBase !== null) {
            $rows = (clone $intakeBase)
                ->where('weight_grams', '>', 0)
                ->selectRaw("\n                    CASE\n                        WHEN weight_grams < 53 THEN 'light'\n                        WHEN weight_grams < 63 THEN 'standard'\n                        ELSE 'heavy'\n                    END AS band_key,\n                    size_class,\n                    SUM(quantity) AS eggs\n                ")
                ->groupBy('band_key', 'size_class')
                ->get();

            foreach ($rows as $row) {
                $band = (string) $row->band_key;
                if (!isset($bands[$band])) {
                    continue;
                }

                $count = (int) $row->eggs;
                $bands[$band]['total'] += $count;
                $bands[$band]['classes'][(string) $row->size_class] = ($bands[$band]['classes'][(string) $row->size_class] ?? 0) + $count;
            }
        }

        foreach ($bands as $bandKey => $payload) {
            $classRows = [];
            foreach ($payload['classes'] as $class => $count) {
                $classRows[] = [
                    'size_class' => $class,
                    'count' => (int) $count,
                ];
            }

            usort($classRows, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
            $bands[$bandKey]['classes'] = $classRows;
        }

        return $bands;
    }

    /**
     * @param \Illuminate\Database\Query\Builder|null $ingestBase
     * @param \Illuminate\Database\Query\Builder|null $intakeBase
     * @param array{
     *   start:CarbonImmutable,
     *   end:CarbonImmutable,
     *   bucket_sql:string,
     *   interval:'hour'|'day'
     * } $window
     * @return array<int,array{
     *   bucket:string,
     *   label:string,
     *   eggs:int,
     *   quality_score:float,
     *   avg_weight:float
     * }>
     */
    private function buildTimeline($ingestBase, $intakeBase, array $window): array
    {
        $timeline = [];
        $cursor = $window['interval'] === 'hour'
            ? $window['start']->startOfHour()
            : $window['start']->startOfDay();
        $end = $window['interval'] === 'hour'
            ? $window['end']->startOfHour()
            : $window['end']->startOfDay();

        while ($cursor->lessThanOrEqualTo($end)) {
            $key = $window['interval'] === 'hour'
                ? $cursor->format('Y-m-d H:00:00')
                : $cursor->format('Y-m-d 00:00:00');

            $timeline[$key] = [
                'bucket' => $cursor->toIso8601String(),
                'label' => $window['interval'] === 'hour' ? $cursor->format('ga') : $cursor->format('M d'),
                'eggs' => 0,
                'good_eggs' => 0,
                'weighted_eggs' => 0,
                'weight_sum' => 0.0,
            ];

            $cursor = $window['interval'] === 'hour' ? $cursor->addHour() : $cursor->addDay();
        }

        if ($ingestBase !== null) {
            $rows = (clone $ingestBase)
                ->selectRaw($window['bucket_sql'] . " AS bucket_key")
                ->selectRaw('COUNT(*) AS eggs')
                ->selectRaw("SUM(CASE WHEN size_class <> '" . EggSizeClass::REJECT . "' THEN 1 ELSE 0 END) AS good_eggs")
                ->selectRaw('SUM(CASE WHEN weight_grams > 0 THEN 1 ELSE 0 END) AS weighted_eggs')
                ->selectRaw('SUM(CASE WHEN weight_grams > 0 THEN weight_grams ELSE 0 END) AS weight_sum')
                ->groupBy('bucket_key')
                ->orderBy('bucket_key')
                ->get();

            foreach ($rows as $row) {
                $key = (string) $row->bucket_key;
                if (!isset($timeline[$key])) {
                    continue;
                }

                $timeline[$key]['eggs'] += (int) $row->eggs;
                $timeline[$key]['good_eggs'] += (int) $row->good_eggs;
                $timeline[$key]['weighted_eggs'] += (int) ($row->weighted_eggs ?? 0);
                $timeline[$key]['weight_sum'] += (float) $row->weight_sum;
            }
        }

        if ($intakeBase !== null) {
            $rows = (clone $intakeBase)
                ->selectRaw($window['bucket_sql'] . " AS bucket_key")
                ->selectRaw('SUM(quantity) AS eggs')
                ->selectRaw("SUM(CASE WHEN size_class <> '" . EggSizeClass::REJECT . "' THEN quantity ELSE 0 END) AS good_eggs")
                ->selectRaw('SUM(CASE WHEN weight_grams > 0 THEN quantity ELSE 0 END) AS weighted_eggs')
                ->selectRaw('SUM(CASE WHEN weight_grams > 0 THEN weight_grams * quantity ELSE 0 END) AS weight_sum')
                ->groupBy('bucket_key')
                ->orderBy('bucket_key')
                ->get();

            foreach ($rows as $row) {
                $key = (string) $row->bucket_key;
                if (!isset($timeline[$key])) {
                    continue;
                }

                $timeline[$key]['eggs'] += (int) $row->eggs;
                $timeline[$key]['good_eggs'] += (int) $row->good_eggs;
                $timeline[$key]['weighted_eggs'] += (int) ($row->weighted_eggs ?? 0);
                $timeline[$key]['weight_sum'] += (float) $row->weight_sum;
            }
        }

        $rows = [];
        foreach ($timeline as $row) {
            $eggs = (int) $row['eggs'];
            $weightedEggs = (int) ($row['weighted_eggs'] ?? 0);
            $rows[] = [
                'bucket' => (string) $row['bucket'],
                'label' => (string) $row['label'],
                'eggs' => $eggs,
                'quality_score' => $eggs > 0 ? round(((int) $row['good_eggs'] / $eggs) * 100, 1) : 0.0,
                'avg_weight' => $weightedEggs > 0 ? round(((float) $row['weight_sum']) / $weightedEggs, 2) : 0.0,
            ];
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,array<string,mixed>> $sizeByClass
     * @param \Illuminate\Database\Query\Builder|null $ingestBase
     * @param \Illuminate\Database\Query\Builder|null $intakeBase
     * @return array<int,array<string,mixed>>
     */
    private function buildTopActive(array $context, array $sizeByClass, $ingestBase, $intakeBase): array
    {
        $deviceMap = [];
        foreach (($context['switcher']['devices'] ?? []) as $device) {
            if (!is_array($device) || !isset($device['id'])) {
                continue;
            }

            $deviceMap[(int) $device['id']] = $device;
        }

        $farmMap = [];
        foreach (($context['switcher']['farms'] ?? []) as $farm) {
            if (!is_array($farm) || !isset($farm['id'])) {
                continue;
            }

            $farmMap[(int) $farm['id']] = $farm;
        }

        if (($context['scope']['role'] ?? '') === 'customer') {
            $rows = [];
            foreach ($sizeByClass as $class => $payload) {
                $rows[] = [
                    'label' => (string) $class,
                    'sub_label' => 'Size class activity',
                    'value' => (int) ($payload['count'] ?? 0),
                    'icon' => 'bx-circle',
                ];
            }

            usort($rows, static fn (array $a, array $b): int => $b['value'] <=> $a['value']);
            return array_slice($rows, 0, 5);
        }

        if ($ingestBase !== null) {
            $topDevices = (clone $ingestBase)
                ->whereNotNull('device_id')
                ->selectRaw('device_id, COUNT(*) AS eggs')
                ->groupBy('device_id')
                ->orderByDesc('eggs')
                ->limit(5)
                ->get();

            $deviceRows = [];
            foreach ($topDevices as $row) {
                $deviceId = (int) $row->device_id;
                if (!isset($deviceMap[$deviceId])) {
                    continue;
                }

                $deviceRows[] = [
                    'label' => (string) $deviceMap[$deviceId]['name'],
                    'sub_label' => (string) ($deviceMap[$deviceId]['farm_name'] ?? 'Unassigned farm'),
                    'value' => (int) $row->eggs,
                    'icon' => 'bx-chip',
                ];
            }

            if ($deviceRows !== []) {
                return $deviceRows;
            }
        }

        $farmCounts = [];

        if ($ingestBase !== null) {
            $rows = (clone $ingestBase)
                ->whereNotNull('farm_id')
                ->selectRaw('farm_id, COUNT(*) AS eggs')
                ->groupBy('farm_id')
                ->get();

            foreach ($rows as $row) {
                $farmId = (int) $row->farm_id;
                $farmCounts[$farmId] = ($farmCounts[$farmId] ?? 0) + (int) $row->eggs;
            }
        }

        if ($intakeBase !== null) {
            $rows = (clone $intakeBase)
                ->whereNotNull('farm_id')
                ->selectRaw('farm_id, SUM(quantity) AS eggs')
                ->groupBy('farm_id')
                ->get();

            foreach ($rows as $row) {
                $farmId = (int) $row->farm_id;
                $farmCounts[$farmId] = ($farmCounts[$farmId] ?? 0) + (int) $row->eggs;
            }
        }

        arsort($farmCounts);

        $farmRows = [];
        foreach ($farmCounts as $farmId => $count) {
            if (!isset($farmMap[$farmId])) {
                continue;
            }

            $farmRows[] = [
                'label' => (string) $farmMap[$farmId]['name'],
                'sub_label' => (string) ($farmMap[$farmId]['location'] ?? 'Location not set'),
                'value' => (int) $count,
                'icon' => 'bx-home-smile',
            ];

            if (count($farmRows) >= 5) {
                break;
            }
        }

        if ($farmRows !== []) {
            return $farmRows;
        }

        $fallback = [];
        foreach ($sizeByClass as $class => $payload) {
            $fallback[] = [
                'label' => (string) $class,
                'sub_label' => 'Size class activity',
                'value' => (int) ($payload['count'] ?? 0),
                'icon' => 'bx-circle',
            ];
        }

        usort($fallback, static fn (array $a, array $b): int => $b['value'] <=> $a['value']);
        return array_slice($fallback, 0, 5);
    }

    /**
     * @param \Illuminate\Database\Query\Builder|null $ingestBase
     * @return array{eggs:int,good_eggs:int,weight_sum:float}
     */
    private function collectIngestSummary($ingestBase): array
    {
        if ($ingestBase === null) {
            return ['eggs' => 0, 'good_eggs' => 0, 'weight_sum' => 0.0];
        }

        $row = (clone $ingestBase)
            ->selectRaw('COUNT(*) AS eggs')
            ->selectRaw("SUM(CASE WHEN size_class <> '" . EggSizeClass::REJECT . "' THEN 1 ELSE 0 END) AS good_eggs")
            ->selectRaw('SUM(CASE WHEN weight_grams > 0 THEN weight_grams ELSE 0 END) AS weight_sum')
            ->first();

        return [
            'eggs' => (int) ($row->eggs ?? 0),
            'good_eggs' => (int) ($row->good_eggs ?? 0),
            'weight_sum' => (float) ($row->weight_sum ?? 0),
        ];
    }

    /**
     * @param \Illuminate\Database\Query\Builder|null $intakeBase
     * @return array{eggs:int,good_eggs:int,weight_sum:float}
     */
    private function collectIntakeSummary($intakeBase): array
    {
        if ($intakeBase === null) {
            return ['eggs' => 0, 'good_eggs' => 0, 'weight_sum' => 0.0];
        }

        $row = (clone $intakeBase)
            ->selectRaw('SUM(quantity) AS eggs')
            ->selectRaw("SUM(CASE WHEN size_class <> '" . EggSizeClass::REJECT . "' THEN quantity ELSE 0 END) AS good_eggs")
            ->selectRaw('SUM(CASE WHEN weight_grams > 0 THEN weight_grams * quantity ELSE 0 END) AS weight_sum')
            ->first();

        return [
            'eggs' => (int) ($row->eggs ?? 0),
            'good_eggs' => (int) ($row->good_eggs ?? 0),
            'weight_sum' => (float) ($row->weight_sum ?? 0),
        ];
    }

    /**
     * @param \Illuminate\Database\Query\Builder|null $query
     * @return array<int,int>
     */
    private function pluckDistinctInt($query, string $column): array
    {
        if ($query === null) {
            return [];
        }

        return (clone $query)
            ->whereNotNull($column)
            ->distinct()
            ->pluck($column)
            ->map(static fn ($value): int => (int) $value)
            ->filter(static fn (int $value): bool => $value > 0)
            ->values()
            ->all();
    }

    /**
     * @param array{
     *   selected:array{farm_id:int|null,device_id:int|null},
     *   scope:array{role:string,farm_ids:array<int,int>}
     * } $context
     */
    private function countRecentIngestEggs(User $user, array $context, CarbonImmutable $start, CarbonImmutable $end): int
    {
        if (!Schema::hasTable('device_ingest_events')) {
            return 0;
        }

        $query = DB::table('device_ingest_events')
            ->whereBetween('recorded_at', [$start->toDateTimeString(), $end->toDateTimeString()]);

        if ($context['selected']['farm_id'] !== null) {
            $query->where('farm_id', (int) $context['selected']['farm_id']);
        }

        if ($context['selected']['device_id'] !== null) {
            $query->where('device_id', (int) $context['selected']['device_id']);
        }

        if ($context['scope']['role'] === 'owner') {
            $query->where('owner_user_id', (int) $user->id);
        } elseif ($context['scope']['role'] === 'staff') {
            $farmIds = $context['scope']['farm_ids'];
            if ($farmIds === []) {
                return 0;
            }

            $query->whereIn('farm_id', $farmIds);
        } elseif ($context['scope']['role'] === 'customer') {
            $query->where('owner_user_id', (int) $user->id);
        }

        return (int) $query->count();
    }

    private function activityTotalLabel(string $format, int $total): string
    {
        return match ($format) {
            'farm' => $total === 1 ? 'active farm' : 'active farms',
            'device' => $total === 1 ? 'active device' : 'active devices',
            default => EggTrayFormatter::trayLabel($total),
        };
    }

    private function parseIsoTime(string $value): ?CarbonImmutable
    {
        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function pseudoGatewayIp(int $seed): string
    {
        if ($seed <= 0) {
            return '--';
        }

        $octetA = 145;
        $octetB = 20 + ($seed % 40);
        $octetC = 30 + (($seed * 3) % 200);
        $octetD = 10 + (($seed * 7) % 200);

        return sprintf('%d.%d.%d.%d', $octetA, $octetB, $octetC, $octetD);
    }
}
