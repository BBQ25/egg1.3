<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceIngestEvent;
use App\Models\ProductionBatch;
use App\Support\EggUid;
use App\Support\EggSizeClass;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class DeviceIngestController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $serialHeader = Device::normalizeSerial((string) $request->header('X-Device-Serial', ''));
        $deviceKey = trim((string) $request->header('X-Device-Key', ''));

        if ($serialHeader === '' || $deviceKey === '') {
            return $this->unauthorizedResponse();
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
            ? Carbon::parse((string) $validated['recorded_at'])
            : Carbon::now();
        $batchCode = isset($validated['batch_code']) ? trim((string) $validated['batch_code']) : null;
        if ($batchCode === '') {
            $batchCode = null;
        }

        $eggUid = EggUid::normalize(isset($validated['egg_uid']) ? (string) $validated['egg_uid'] : null);

        $rawPayload = $request->json()->all();
        if (!is_array($rawPayload) || $rawPayload === []) {
            $rawPayload = $request->all();
        }

        $event = DB::transaction(function () use ($device, $validated, $recordedAt, $batchCode, $eggUid, $request, $rawPayload) {
            $productionBatch = $this->resolveProductionBatch($device, $batchCode, $recordedAt);

            $event = DeviceIngestEvent::query()->create([
                'device_id' => (int) $device->id,
                'farm_id' => (int) $device->farm_id,
                'owner_user_id' => (int) $device->owner_user_id,
                'production_batch_id' => $productionBatch?->id,
                'egg_uid' => $eggUid,
                'batch_code' => $batchCode,
                'weight_grams' => $validated['weight_grams'],
                'size_class' => $validated['size_class'],
                'recorded_at' => $recordedAt,
                'source_ip' => $request->ip(),
                'raw_payload_json' => json_encode($rawPayload),
            ]);

            $device->update([
                'last_seen_at' => Carbon::now(),
                'last_seen_ip' => $request->ip(),
            ]);

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

    private function unauthorizedResponse(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'message' => 'Unauthorized device credentials.',
        ], 401);
    }

    private function resolveProductionBatch(Device $device, ?string $batchCode, Carbon $recordedAt): ?ProductionBatch
    {
        if ($batchCode === null) {
            return null;
        }

        /** @var ProductionBatch $batch */
        $batch = ProductionBatch::query()->firstOrCreate(
            [
                'device_id' => (int) $device->id,
                'farm_id' => (int) $device->farm_id,
                'owner_user_id' => (int) $device->owner_user_id,
                'batch_code' => $batchCode,
            ],
            [
                'status' => 'open',
                'started_at' => $recordedAt,
                'ended_at' => $recordedAt,
            ]
        );

        $updates = [];

        if ($batch->started_at === null || $recordedAt->lt($batch->started_at)) {
            $updates['started_at'] = $recordedAt;
        }

        if ($batch->ended_at === null || $recordedAt->gt($batch->ended_at)) {
            $updates['ended_at'] = $recordedAt;
        }

        if ((string) $batch->status === '') {
            $updates['status'] = 'open';
        }

        if ($updates !== []) {
            $batch->fill($updates)->save();
        }

        return $batch;
    }
}
