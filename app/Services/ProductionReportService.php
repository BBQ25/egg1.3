<?php

namespace App\Services;

use App\Support\EggSizeClass;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

class ProductionReportService
{
    public function __construct(
        private readonly EggRecordExplorerService $recordExplorerService
    ) {
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
     *   summary:stdClass,
     *   daily:Collection<int, stdClass>,
     *   farms:Collection<int, stdClass>,
     *   devices:Collection<int, stdClass>,
     *   sizes:Collection<int, stdClass>,
     *   window:array{start:CarbonImmutable,end:CarbonImmutable}
     * }
     */
    public function build(array $context, array $filters): array
    {
        $window = $this->resolveWindow((string) ($context['range'] ?? DashboardContextService::RANGE_1D));
        $baseQuery = $this->recordExplorerService->scopedEventQuery($context, $window['start'], $window['end'], $filters);

        $summary = DB::query()
            ->fromSub($baseQuery, 'record_rows')
            ->selectRaw('COUNT(*) AS total_records')
            ->selectRaw('COUNT(DISTINCT farm_id) AS active_farms')
            ->selectRaw('COUNT(DISTINCT device_id) AS active_devices')
            ->selectRaw('COUNT(DISTINCT production_batch_id) AS unique_batches')
            ->selectRaw('COALESCE(SUM(weight_grams), 0) AS total_weight_grams')
            ->selectRaw('COALESCE(AVG(weight_grams), 0) AS avg_weight_grams')
            ->selectRaw('COALESCE(SUM(CASE WHEN size_class = ? THEN 1 ELSE 0 END), 0) AS reject_count', [EggSizeClass::REJECT])
            ->first();

        $daily = DB::query()
            ->fromSub($baseQuery, 'record_rows')
            ->selectRaw('DATE(recorded_at) AS report_date')
            ->selectRaw('COUNT(*) AS total_records')
            ->selectRaw('COUNT(DISTINCT production_batch_id) AS unique_batches')
            ->selectRaw('COALESCE(SUM(weight_grams), 0) AS total_weight_grams')
            ->selectRaw('COALESCE(AVG(weight_grams), 0) AS avg_weight_grams')
            ->selectRaw('COALESCE(SUM(CASE WHEN size_class = ? THEN 1 ELSE 0 END), 0) AS reject_count', [EggSizeClass::REJECT])
            ->groupByRaw('DATE(recorded_at)')
            ->orderBy('report_date')
            ->get();

        $farms = DB::query()
            ->fromSub($baseQuery, 'record_rows')
            ->selectRaw('farm_id')
            ->selectRaw('farm_name')
            ->selectRaw('COUNT(*) AS total_records')
            ->selectRaw('COUNT(DISTINCT production_batch_id) AS unique_batches')
            ->selectRaw('COALESCE(SUM(weight_grams), 0) AS total_weight_grams')
            ->selectRaw('COALESCE(AVG(weight_grams), 0) AS avg_weight_grams')
            ->selectRaw('COALESCE(SUM(CASE WHEN size_class = ? THEN 1 ELSE 0 END), 0) AS reject_count', [EggSizeClass::REJECT])
            ->groupBy('farm_id', 'farm_name')
            ->orderByDesc('total_records')
            ->orderBy('farm_name')
            ->get();

        $devices = DB::query()
            ->fromSub($baseQuery, 'record_rows')
            ->selectRaw('device_id')
            ->selectRaw('device_name')
            ->selectRaw('device_serial')
            ->selectRaw('COUNT(*) AS total_records')
            ->selectRaw('COUNT(DISTINCT production_batch_id) AS unique_batches')
            ->selectRaw('COALESCE(SUM(weight_grams), 0) AS total_weight_grams')
            ->selectRaw('COALESCE(AVG(weight_grams), 0) AS avg_weight_grams')
            ->selectRaw('COALESCE(SUM(CASE WHEN size_class = ? THEN 1 ELSE 0 END), 0) AS reject_count', [EggSizeClass::REJECT])
            ->groupBy('device_id', 'device_name', 'device_serial')
            ->orderByDesc('total_records')
            ->orderBy('device_name')
            ->get();

        $sizes = DB::query()
            ->fromSub($baseQuery, 'record_rows')
            ->selectRaw('size_class')
            ->selectRaw('COUNT(*) AS total_records')
            ->selectRaw('COALESCE(SUM(weight_grams), 0) AS total_weight_grams')
            ->selectRaw('COALESCE(AVG(weight_grams), 0) AS avg_weight_grams')
            ->groupBy('size_class')
            ->orderByRaw($this->sizeClassOrderSql('size_class'))
            ->get();

        return [
            'summary' => $summary ?? $this->emptySummary(),
            'daily' => $daily,
            'farms' => $farms,
            'devices' => $devices,
            'sizes' => $sizes,
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
    public function exportDailyRows(array $context, array $filters): Collection
    {
        return $this->build($context, $filters)['daily'];
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
            'total_records' => 0,
            'active_farms' => 0,
            'active_devices' => 0,
            'unique_batches' => 0,
            'total_weight_grams' => 0,
            'avg_weight_grams' => 0,
            'reject_count' => 0,
        ];
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
