<?php

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Farm;
use App\Models\ProductionBatch;
use App\Models\User;
use App\Services\BatchMonitoringService;
use App\Services\DashboardContextService;
use App\Support\BatchCodeFormatter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\View\View;

class BatchMonitoringController extends Controller
{
    public function __construct(
        private readonly DashboardContextService $contextService,
        private readonly BatchMonitoringService $batchMonitoringService
    ) {
    }

    public function index(Request $request): View
    {
        /** @var User|null $user */
        $user = $request->user();
        abort_unless($user && !$user->isCustomer(), 403);

        try {
            $context = $this->contextService->resolve($request, $user);
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        }

        $search = trim((string) $request->query('q', ''));
        $status = $this->resolveStatusFilter($request);
        $payload = $this->batchMonitoringService->buildList($context, $search, $status);

        return view('monitoring.batch-monitoring.index', [
            'batchPayload' => $payload,
            'batchContext' => $context,
            'selectedRange' => $context['range'],
            'selectedSearch' => $search,
            'selectedStatus' => $status,
            'rangeOptions' => [
                DashboardContextService::RANGE_1D => '1D',
                DashboardContextService::RANGE_1W => '1W',
                DashboardContextService::RANGE_1M => '1M',
            ],
            'statusOptions' => [
                BatchMonitoringService::STATUS_ALL => 'All Statuses',
                BatchMonitoringService::STATUS_OPEN => 'Open',
                BatchMonitoringService::STATUS_CLOSED => 'Closed',
            ],
        ]);
    }

    public function show(Request $request, Farm $farm, Device $device, string $batchCode): View
    {
        /** @var User|null $user */
        $user = $request->user();
        abort_unless($user && !$user->isCustomer(), 403);
        abort_unless((int) $device->farm_id === (int) $farm->id, 404);

        try {
            $context = $this->contextService->resolve($request, $user);
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        }

        $scopeFarmIds = $context['scope']['farm_ids'] ?? [];
        $scopeDeviceIds = $context['scope']['device_ids'] ?? [];

        abort_unless(in_array((int) $farm->id, $scopeFarmIds, true), 403);
        abort_unless(in_array((int) $device->id, $scopeDeviceIds, true), 403);

        $payload = $this->batchMonitoringService->buildDetail($context, (int) $farm->id, (int) $device->id, $batchCode);

        return view('monitoring.batch-monitoring.show', [
            'batchDetailPayload' => $payload,
            'batchContext' => $context,
            'selectedRange' => $context['range'],
            'selectedSearch' => trim((string) $request->query('q', '')),
        ]);
    }

    public function exportIndex(Request $request): StreamedResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        abort_unless($user && !$user->isCustomer(), 403);

        try {
            $context = $this->contextService->resolve($request, $user);
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        }

        $search = trim((string) $request->query('q', ''));
        $status = $this->resolveStatusFilter($request);
        $rows = $this->batchMonitoringService->exportListRows($context, $search, $status);
        $filename = 'batch-monitoring-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'wb');
            if ($handle === false) {
                return;
            }

            fputcsv($handle, [
                'batch_code',
                'farm_name',
                'device_name',
                'device_serial',
                'owner_name',
                'status',
                'total_eggs',
                'reject_count',
                'avg_weight_grams',
                'total_weight_grams',
                'started_at',
                'ended_at',
            ]);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row->batch_code,
                    $row->farm_name,
                    $row->device_name,
                    $row->device_serial,
                    $row->owner_name,
                    $row->status,
                    (int) $row->total_eggs,
                    (int) $row->reject_count,
                    number_format((float) $row->avg_weight_grams, 2, '.', ''),
                    number_format((float) $row->total_weight_grams, 2, '.', ''),
                    BatchCodeFormatter::formatPhilippineDateTime($row->started_at, 'Y-m-d H:i:s'),
                    $row->ended_at ? BatchCodeFormatter::formatPhilippineDateTime($row->ended_at, 'Y-m-d H:i:s') : null,
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function exportShow(Request $request, Farm $farm, Device $device, string $batchCode): StreamedResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        abort_unless($user && !$user->isCustomer(), 403);
        abort_unless((int) $device->farm_id === (int) $farm->id, 404);

        try {
            $context = $this->contextService->resolve($request, $user);
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        }

        $scopeFarmIds = $context['scope']['farm_ids'] ?? [];
        $scopeDeviceIds = $context['scope']['device_ids'] ?? [];

        abort_unless(in_array((int) $farm->id, $scopeFarmIds, true), 403);
        abort_unless(in_array((int) $device->id, $scopeDeviceIds, true), 403);

        $rows = $this->batchMonitoringService->exportDetailRows($context, (int) $farm->id, (int) $device->id, $batchCode);
        abort_if($rows->isEmpty(), 404);

        $filename = sprintf(
            'batch-%s-%s.csv',
            preg_replace('/[^A-Za-z0-9_-]+/', '-', $batchCode) ?: 'records',
            now()->format('Ymd-His')
        );

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'wb');
            if ($handle === false) {
                return;
            }

            fputcsv($handle, [
                'record_id',
                'batch_code',
                'farm_name',
                'device_name',
                'device_serial',
                'owner_name',
                'egg_uid',
                'size_class',
                'weight_grams',
                'recorded_at',
                'source_ip',
            ]);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    (int) $row->id,
                    $row->batch_code,
                    $row->farm_name,
                    $row->device_name,
                    $row->device_serial,
                    $row->owner_name,
                    $row->egg_uid,
                    $row->size_class,
                    number_format((float) $row->weight_grams, 2, '.', ''),
                    BatchCodeFormatter::formatPhilippineDateTime($row->recorded_at, 'Y-m-d H:i:s'),
                    $row->source_ip,
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function close(Request $request, Farm $farm, Device $device, string $batchCode)
    {
        /** @var User|null $user */
        $user = $request->user();
        abort_unless($user && !$user->isCustomer(), 403);
        abort_unless((int) $device->farm_id === (int) $farm->id, 404);

        try {
            $context = $this->contextService->resolve($request, $user);
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        }

        $scopeFarmIds = $context['scope']['farm_ids'] ?? [];
        $scopeDeviceIds = $context['scope']['device_ids'] ?? [];

        abort_unless(in_array((int) $farm->id, $scopeFarmIds, true), 403);
        abort_unless(in_array((int) $device->id, $scopeDeviceIds, true), 403);

        $batch = ProductionBatch::query()
            ->where('farm_id', $farm->id)
            ->where('device_id', $device->id)
            ->where('batch_code', $batchCode)
            ->firstOrFail();

        if ($batch->status !== 'closed') {
            $this->batchMonitoringService->closeBatch($batch);
        }

        return redirect()
            ->route('monitoring.batches.show', array_filter([
                'farm' => $farm->id,
                'device' => $device->id,
                'batchCode' => $batchCode,
                'range' => $request->query('range'),
                'context_farm_id' => $request->query('context_farm_id'),
                'context_device_id' => $request->query('context_device_id'),
                'q' => $request->query('q'),
                'status' => $request->query('status'),
            ], static fn ($value) => $value !== null && $value !== ''))
            ->with('status', "Batch {$batchCode} closed.");
    }

    public function store(Request $request)
    {
        /** @var User|null $user */
        $user = $request->user();
        abort_unless($user && !$user->isCustomer(), 403);
        abort_unless(Schema::hasTable('production_batches'), 503, 'Batch persistence is not available.');

        try {
            $context = $this->contextService->resolve($request, $user);
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        }

        $validated = $request->validate([
            'farm_id' => ['required', 'integer'],
            'device_id' => ['required', 'integer'],
            'batch_code' => ['nullable', 'string', 'max:80'],
        ]);

        $scopeFarmIds = $context['scope']['farm_ids'] ?? [];
        $scopeDeviceIds = $context['scope']['device_ids'] ?? [];

        abort_unless(in_array((int) $validated['farm_id'], $scopeFarmIds, true), 403);
        abort_unless(in_array((int) $validated['device_id'], $scopeDeviceIds, true), 403);

        $device = Device::query()
            ->whereKey((int) $validated['device_id'])
            ->where('farm_id', (int) $validated['farm_id'])
            ->firstOrFail();

        $batch = $this->batchMonitoringService->openBatch($device, $validated['batch_code'] ?? null);

        return redirect()
            ->route('monitoring.batches.show', array_filter([
                'farm' => $batch->farm_id,
                'device' => $batch->device_id,
                'batchCode' => $batch->batch_code,
                'range' => $request->query('range'),
                'context_farm_id' => $request->query('context_farm_id'),
                'context_device_id' => $request->query('context_device_id'),
                'q' => $request->query('q'),
                'status' => $request->query('status'),
            ], static fn ($value) => $value !== null && $value !== ''))
            ->with('status', "Batch {$batch->batch_code} opened.");
    }

    private function resolveStatusFilter(Request $request): string
    {
        $status = strtolower(trim((string) $request->query('status', BatchMonitoringService::STATUS_ALL)));

        if (!in_array($status, BatchMonitoringService::allowedStatuses(), true)) {
            return BatchMonitoringService::STATUS_ALL;
        }

        return $status;
    }
}
