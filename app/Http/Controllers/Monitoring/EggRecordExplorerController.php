<?php

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use App\Services\DashboardContextService;
use App\Services\EggRecordExplorerService;
use App\Support\EggUid;
use App\Support\EggSizeClass;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EggRecordExplorerController extends Controller
{
    public function __construct(
        private readonly DashboardContextService $contextService,
        private readonly EggRecordExplorerService $recordExplorerService
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
        $payload = $this->recordExplorerService->buildList($context, $filters);
        $liveFeed = $this->recordExplorerService->buildLiveSnapshot(
            $context,
            $filters,
            max(1, (int) $request->query('live_page', 1))
        );

        return view('monitoring.egg-records.index', [
            'recordPayload' => $payload,
            'liveFeed' => $liveFeed,
            'recordContext' => $context,
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

    public function live(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && !$user->isCustomer(), 403);

        try {
            $context = $this->contextService->resolve($request, $user);
        } catch (AuthorizationException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 403);
        }

        $filters = $this->resolveFilters($request);
        $payload = $this->recordExplorerService->buildLiveSnapshot(
            $context,
            $filters,
            max(1, (int) $request->query('live_page', 1))
        );

        return response()->json([
            'ok' => true,
            ...$payload,
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
        $rows = $this->recordExplorerService->exportRows($context, $filters);
        $filename = 'egg-records-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'wb');
            if ($handle === false) {
                return;
            }

            fputcsv($handle, [
                'record_id',
                'recorded_at',
                'egg_uid',
                'batch_code',
                'batch_status',
                'size_class',
                'weight_grams',
                'farm_name',
                'device_name',
                'device_serial',
                'owner_name',
                'source_ip',
                'esp32_mac_address',
                'router_mac_address',
                'wifi_ssid',
            ]);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    (int) $row->id,
                    $row->recorded_at,
                    $row->egg_uid,
                    $row->batch_code,
                    $row->batch_status,
                    $row->size_class,
                    number_format((float) $row->weight_grams, 2, '.', ''),
                    $row->farm_name,
                    $row->device_name,
                    $row->device_serial,
                    $row->owner_name,
                    $row->source_ip,
                    $row->esp32_mac_address,
                    $row->router_mac_address,
                    $row->wifi_ssid,
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
