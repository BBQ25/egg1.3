<?php

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use App\Models\EvaluationRun;
use App\Services\DashboardContextService;
use App\Services\ValidationAccuracyService;
use App\Support\EggSizeClass;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ValidationAccuracyController extends Controller
{
    public function __construct(
        private readonly DashboardContextService $contextService,
        private readonly ValidationAccuracyService $validationAccuracyService
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
        $payload = $this->validationAccuracyService->build($context, $filters);

        return view('monitoring.validation.index', [
            'validationPayload' => $payload,
            'validationContext' => $context,
            'selectedRange' => $context['range'],
            'filters' => $filters,
            'rangeOptions' => [
                DashboardContextService::RANGE_1D => '1D',
                DashboardContextService::RANGE_1W => '1W',
                DashboardContextService::RANGE_1M => '1M',
            ],
            'statusOptions' => [
                'all' => 'All Runs',
                'in_progress' => 'In Progress',
                'completed' => 'Completed',
            ],
            'sizeClassOptions' => EggSizeClass::values(),
        ]);
    }

    public function storeRun(Request $request)
    {
        $user = $request->user();
        abort_unless($user && !$user->isCustomer(), 403);

        try {
            $context = $this->contextService->resolve($request, $user);
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        }

        $run = $this->validationAccuracyService->createRun($user, $context, $request->all());

        return redirect()->route('monitoring.validation.index', [
            'range' => $context['range'],
            'context_farm_id' => $run->farm_id,
            'context_device_id' => $run->device_id,
            'status' => 'all',
            'run' => $run->id,
        ])->with('status', 'Validation run created.');
    }

    public function storeMeasurement(Request $request, EvaluationRun $run)
    {
        $user = $request->user();
        abort_unless($user && !$user->isCustomer(), 403);

        try {
            $context = $this->contextService->resolve($request, $user);
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        }

        $measurement = $this->validationAccuracyService->addMeasurement($user, $context, $run, $request->all());

        return redirect()->route('monitoring.validation.index', [
            'range' => $context['range'],
            'context_farm_id' => $run->farm_id,
            'context_device_id' => $run->device_id,
            'status' => 'all',
            'run' => $run->id,
        ])->with('status', 'Validation measurement recorded for ' . ($measurement->egg_uid ?: 'the selected egg event') . '.');
    }

    public function export(Request $request, EvaluationRun $run): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user && !$user->isCustomer(), 403);

        try {
            $context = $this->contextService->resolve($request, $user);
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        }

        $rows = $this->validationAccuracyService->exportMeasurements($context, $run);
        $filename = 'validation-run-' . strtolower($run->run_code) . '-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'wb');
            if ($handle === false) {
                return;
            }

            fputcsv($handle, [
                'measurement_id',
                'measured_at',
                'egg_uid',
                'batch_code',
                'reference_weight_grams',
                'automated_weight_grams',
                'weight_error_grams',
                'absolute_error_grams',
                'manual_size_class',
                'automated_size_class',
                'class_match',
                'automated_recorded_at',
                'notes',
            ]);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    (int) $row->id,
                    $row->measured_at,
                    $row->egg_uid,
                    $row->batch_code,
                    number_format((float) $row->reference_weight_grams, 2, '.', ''),
                    number_format((float) $row->automated_weight_grams, 2, '.', ''),
                    number_format((float) $row->weight_error_grams, 2, '.', ''),
                    number_format((float) $row->absolute_error_grams, 2, '.', ''),
                    $row->manual_size_class,
                    $row->automated_size_class,
                    (int) $row->class_match,
                    $row->automated_recorded_at,
                    $row->notes,
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array{run_id:int|null,status:string}
     */
    private function resolveFilters(Request $request): array
    {
        $status = trim((string) $request->query('status', 'all'));
        if (!in_array($status, ['all', 'in_progress', 'completed'], true)) {
            $status = 'all';
        }

        $runId = trim((string) $request->query('run', ''));

        return [
            'run_id' => ctype_digit($runId) ? (int) $runId : null,
            'status' => $status,
        ];
    }
}
