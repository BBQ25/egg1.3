<?php

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use App\Services\DashboardContextService;
use App\Services\ProductionReportService;
use App\Support\EggUid;
use App\Support\EggSizeClass;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductionReportController extends Controller
{
    public function __construct(
        private readonly DashboardContextService $contextService,
        private readonly ProductionReportService $productionReportService
    ) {
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user && !$user->isCustomer(), 403);

        try {
            $context = $this->contextService->resolve($request, $user);
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        }

        $filters = $this->resolveFilters($request);
        $payload = $this->productionReportService->build($context, $filters);

        return view('monitoring.production-reports.index', [
            'reportPayload' => $payload,
            'reportContext' => $context,
            'selectedRange' => $context['range'],
            'filters' => $filters,
            'rangeOptions' => [
                DashboardContextService::RANGE_1D => '1D',
                DashboardContextService::RANGE_1W => '1W',
                DashboardContextService::RANGE_1M => '1M',
            ],
            'sizeClassOptions' => EggSizeClass::values(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user && !$user->isCustomer(), 403);

        try {
            $context = $this->contextService->resolve($request, $user);
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        }

        $filters = $this->resolveFilters($request);
        $rows = $this->productionReportService->exportDailyRows($context, $filters);
        $filename = 'production-report-daily-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'wb');
            if ($handle === false) {
                return;
            }

            fputcsv($handle, [
                'report_date',
                'total_records',
                'unique_batches',
                'reject_count',
                'avg_weight_grams',
                'total_weight_grams',
            ]);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row->report_date,
                    (int) $row->total_records,
                    (int) $row->unique_batches,
                    (int) $row->reject_count,
                    number_format((float) $row->avg_weight_grams, 2, '.', ''),
                    number_format((float) $row->total_weight_grams, 2, '.', ''),
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array{
     *   egg_uid:string,
     *   batch_code:string,
     *   size_class:string|null,
     *   weight_min:float|null,
     *   weight_max:float|null
     * }
     */
    private function resolveFilters(Request $request): array
    {
        $sizeClass = trim((string) $request->query('size_class', ''));
        if ($sizeClass !== '' && !in_array($sizeClass, EggSizeClass::values(), true)) {
            $sizeClass = '';
        }

        $weightMin = $this->normalizeNumericQuery($request->query('weight_min'));
        $weightMax = $this->normalizeNumericQuery($request->query('weight_max'));

        if ($weightMin !== null && $weightMax !== null && $weightMin > $weightMax) {
            [$weightMin, $weightMax] = [$weightMax, $weightMin];
        }

        return [
            'egg_uid' => EggUid::normalize((string) $request->query('egg_uid', '')) ?? '',
            'batch_code' => trim((string) $request->query('batch_code', '')),
            'size_class' => $sizeClass !== '' ? $sizeClass : null,
            'weight_min' => $weightMin,
            'weight_max' => $weightMax,
        ];
    }

    private function normalizeNumericQuery(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $raw = trim((string) $value);
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }

        return round((float) $raw, 2);
    }
}
