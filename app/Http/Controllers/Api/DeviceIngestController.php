<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceIngestEvent;
use App\Services\AutomaticBatchLifecycleService;
use App\Support\AppTimezone;
use App\Support\EggUid;
use App\Support\EggSizeClass;
use App\Support\EggWeightRanges;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class DeviceIngestController extends Controller
{
    public function __construct(
        private readonly AutomaticBatchLifecycleService $automaticBatchLifecycleService
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $device = $this->resolveAuthenticatedDevice($request);
        if ($device === null) {
            return $this->unauthorizedResponse();
        }

        $validator = Validator::make($request->all(), [
            'weight_grams' => ['required', 'numeric', 'gt:0'],
            'size_class' => ['required', Rule::in(EggSizeClass::values())],
            'recorded_at' => ['nullable', 'date'],
            'batch_code' => ['nullable', 'string', 'max:80'],
            'egg_uid' => [
                'nullable',
                'string',
                'max:80',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (trim((string) $value) !== '' && !EggUid::hasSuffix((string) $value)) {
                        $fail('The egg uid must include a value after the egg- prefix.');
                    }
                },
            ],
            'metadata' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok' => false,
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $recordedAt = isset($validated['recorded_at'])
            ? AppTimezone::parseInbound((string) $validated['recorded_at'])
            : AppTimezone::now();
        $batchCode = isset($validated['batch_code']) ? trim((string) $validated['batch_code']) : null;
        if ($batchCode === '') {
            $batchCode = null;
        }

        $eggUid = EggUid::normalize(isset($validated['egg_uid']) ? (string) $validated['egg_uid'] : null);

        if ($eggUid !== null) {
            $existingEvent = DeviceIngestEvent::query()
                ->where('device_id', (int) $device->id)
                ->where('egg_uid', $eggUid)
                ->first();

            if ($existingEvent !== null) {
                $this->touchDeviceHeartbeat($device, $request);

                return response()->json([
                    'ok' => true,
                    'message' => 'Ingest already accepted.',
                    'data' => [
                        'event_id' => (int) $existingEvent->id,
                        'device_id' => (int) $device->id,
                        'recorded_at' => $existingEvent->recorded_at?->toIso8601String(),
                        'deduplicated' => true,
                    ],
                ]);
            }
        }

        $rawPayload = $request->json()->all();
        if (!is_array($rawPayload) || $rawPayload === []) {
            $rawPayload = $request->all();
        }

        $event = DB::transaction(function () use ($device, $validated, $recordedAt, $batchCode, $eggUid, $request, $rawPayload) {
            $productionBatch = $this->automaticBatchLifecycleService->resolveForIngest($device, $batchCode, $recordedAt);

            $event = DeviceIngestEvent::query()->create([
                'device_id' => (int) $device->id,
                'farm_id' => (int) $device->farm_id,
                'owner_user_id' => (int) $device->owner_user_id,
                'production_batch_id' => $productionBatch?->id,
                'egg_uid' => $eggUid,
                'batch_code' => $productionBatch?->batch_code ?? $batchCode,
                'weight_grams' => $validated['weight_grams'],
                'size_class' => $validated['size_class'],
                'recorded_at' => $recordedAt,
                'source_ip' => $request->ip(),
                'raw_payload_json' => json_encode($rawPayload),
            ]);

            $this->touchDeviceHeartbeat($device, $request);

            return $event;
        });

        return response()->json([
            'ok' => true,
            'message' => 'Ingest accepted.',
            'data' => [
                'event_id' => (int) $event->id,
                'device_id' => (int) $device->id,
                'recorded_at' => $event->recorded_at?->toIso8601String(),
            ],
        ], 201);
    }

    public function runtimeConfig(Request $request): JsonResponse
    {
        $device = $this->resolveAuthenticatedDevice($request);
        if ($device === null) {
            return $this->unauthorizedResponse();
        }

        $this->touchDeviceHeartbeat($device, $request);

        $openBatch = $this->automaticBatchLifecycleService->currentOpenBatch($device);

        $weightRanges = [];
        foreach (EggWeightRanges::current() as $slug => $entry) {
            $weightRanges[$slug] = [
                'label' => (string) $entry['label'],
                'min' => round((float) $entry['min'], 2),
                'max' => round((float) $entry['max'], 2),
            ];
        }

        return response()->json([
            'ok' => true,
            'data' => [
                'server_time' => AppTimezone::now()->toIso8601String(),
                'server_timezone' => AppTimezone::current(),
                'server_timezone_label' => AppTimezone::label(),
                'device_serial' => (string) $device->primary_serial_no,
                'open_batch_code' => $openBatch?->batch_code ? (string) $openBatch->batch_code : null,
                'refresh_after_seconds' => 60,
                'weight_ranges' => $weightRanges,
            ],
        ]);
    }

    private function unauthorizedResponse(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'message' => 'Unauthorized device credentials.',
        ], 401);
    }

    private function touchDeviceHeartbeat(Device $device, Request $request): void
    {
        $device->forceFill([
            'last_seen_at' => AppTimezone::now(),
            'last_seen_ip' => $request->ip(),
        ])->save();
    }

    private function resolveAuthenticatedDevice(Request $request): ?Device
    {
        $serialHeader = Device::normalizeSerial((string) $request->header('X-Device-Serial', ''));
        $deviceKey = trim((string) $request->header('X-Device-Key', ''));

        if ($serialHeader === '' || $deviceKey === '') {
            return null;
        }

        $device = Device::query()
            ->where(function ($query) use ($serialHeader) {
                $query->where('primary_serial_no', $serialHeader)
                    ->orWhereHas('aliases', function ($aliasQuery) use ($serialHeader) {
                        $aliasQuery->where('serial_no', $serialHeader);
                    });
            })
            ->first();

        if (!$device || !$device->is_active || !Hash::check($deviceKey, $device->api_key_hash)) {
            return null;
        }

        return $device;
    }
}
