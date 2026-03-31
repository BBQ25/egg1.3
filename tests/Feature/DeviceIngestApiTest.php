<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AppSetting;
use App\Models\Device;
use App\Models\DeviceIngestEvent;
use App\Models\ProductionBatch;
use App\Models\DeviceSerialAlias;
use App\Models\Farm;
use App\Models\User;
use App\Services\AutomaticBatchLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DeviceIngestApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_headers_and_payload_are_accepted(): void
    {
        [$device, $plainKey] = $this->createDevice('ESP32-INGEST-001', 'device-key-001');

        $response = $this->postJson(route('api.devices.ingest'), [
            'weight_grams' => 61.45,
            'size_class' => 'Large',
            'recorded_at' => now()->toIso8601String(),
            'batch_code' => 'BATCH-001',
            'egg_uid' => 'EGG-001',
            'metadata' => ['sensor' => 'hx711'],
        ], [
            'X-Device-Serial' => $device->primary_serial_no,
            'X-Device-Key' => $plainKey,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('message', 'Ingest accepted.');

        $this->assertDatabaseHas('device_ingest_events', [
            'device_id' => $device->id,
            'farm_id' => $device->farm_id,
            'owner_user_id' => $device->owner_user_id,
            'weight_grams' => 61.45,
            'size_class' => 'Large',
            'batch_code' => 'BATCH-001',
            'egg_uid' => 'egg-001',
        ]);
    }

    public function test_alias_serial_can_authenticate_ingest_request(): void
    {
        [$device, $plainKey] = $this->createDevice('ESP32-INGEST-ALIAS-001', 'device-key-002');
        DeviceSerialAlias::query()->create([
            'device_id' => $device->id,
            'serial_no' => 'ESP32-ALIAS-INGEST',
        ]);

        $response = $this->postJson(route('api.devices.ingest'), [
            'weight_grams' => 50.00,
            'size_class' => 'Medium',
        ], [
            'X-Device-Serial' => 'esp32-alias-ingest',
            'X-Device-Key' => $plainKey,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('device_ingest_events', [
            'device_id' => $device->id,
            'size_class' => 'Medium',
        ]);
    }

    public function test_wrong_device_key_returns_unauthorized(): void
    {
        [$device] = $this->createDevice('ESP32-INGEST-KEY-FAIL', 'correct-key');

        $response = $this->postJson(route('api.devices.ingest'), [
            'weight_grams' => 48.75,
            'size_class' => 'Small',
        ], [
            'X-Device-Serial' => $device->primary_serial_no,
            'X-Device-Key' => 'wrong-key',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('ok', false);
    }

    public function test_unknown_serial_returns_unauthorized(): void
    {
        $response = $this->postJson(route('api.devices.ingest'), [
            'weight_grams' => 48.75,
            'size_class' => 'Small',
        ], [
            'X-Device-Serial' => 'UNKNOWN-SERIAL',
            'X-Device-Key' => 'some-key',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('ok', false);
    }

    public function test_inactive_device_returns_unauthorized(): void
    {
        [$device, $plainKey] = $this->createDevice('ESP32-INGEST-INACTIVE', 'inactive-key');
        $device->update([
            'is_active' => false,
            'deactivated_at' => now(),
        ]);

        $response = $this->postJson(route('api.devices.ingest'), [
            'weight_grams' => 71.25,
            'size_class' => 'Extra-Large',
        ], [
            'X-Device-Serial' => $device->primary_serial_no,
            'X-Device-Key' => $plainKey,
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('ok', false);
    }

    public function test_invalid_payload_returns_validation_error_json(): void
    {
        [$device, $plainKey] = $this->createDevice('ESP32-INGEST-VALIDATE', 'validate-key');

        $response = $this->postJson(route('api.devices.ingest'), [
            'weight_grams' => -5,
            'size_class' => 'Unknown-Class',
        ], [
            'X-Device-Serial' => $device->primary_serial_no,
            'X-Device-Key' => $plainKey,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('ok', false);
        $response->assertJsonStructure([
            'ok',
            'message',
            'errors' => ['weight_grams', 'size_class'],
        ]);
    }

    public function test_ingest_endpoint_accepts_requests_without_csrf_token(): void
    {
        [$device, $plainKey] = $this->createDevice('ESP32-INGEST-CSRF', 'csrf-key');

        $response = $this->postJson(route('api.devices.ingest'), [
            'weight_grams' => 59.20,
            'size_class' => 'Large',
        ], [
            'X-Device-Serial' => $device->primary_serial_no,
            'X-Device-Key' => $plainKey,
        ]);

        $response->assertStatus(201);
    }

    public function test_successful_ingest_updates_device_last_seen_metadata(): void
    {
        [$device, $plainKey] = $this->createDevice('ESP32-INGEST-SEEN', 'seen-key');

        $this->assertNull($device->last_seen_at);
        $this->assertNull($device->last_seen_ip);

        $response = $this->postJson(route('api.devices.ingest'), [
            'weight_grams' => 64.10,
            'size_class' => 'Large',
        ], [
            'X-Device-Serial' => $device->primary_serial_no,
            'X-Device-Key' => $plainKey,
        ]);

        $response->assertStatus(201);

        $device->refresh();
        $this->assertNotNull($device->last_seen_at);
        $this->assertNotNull($device->last_seen_ip);
    }

    public function test_ingest_with_batch_code_creates_and_links_production_batch(): void
    {
        [$device, $plainKey] = $this->createDevice('ESP32-INGEST-BATCH-LINK', 'batch-link-key');

        $recordedAt = now()->subMinutes(3)->toIso8601String();

        $response = $this->postJson(route('api.devices.ingest'), [
            'weight_grams' => 63.40,
            'size_class' => 'Large',
            'recorded_at' => $recordedAt,
            'batch_code' => 'BATCH-LINK-001',
            'egg_uid' => 'EGG-LINK-001',
        ], [
            'X-Device-Serial' => $device->primary_serial_no,
            'X-Device-Key' => $plainKey,
        ]);

        $response->assertStatus(201);

        $batch = ProductionBatch::query()
            ->where('device_id', $device->id)
            ->where('batch_code', 'BATCH-LINK-001')
            ->first();

        $this->assertNotNull($batch);
        $this->assertDatabaseHas('device_ingest_events', [
            'device_id' => $device->id,
            'batch_code' => 'BATCH-LINK-001',
            'production_batch_id' => $batch?->id,
            'egg_uid' => 'egg-link-001',
        ]);
    }

    public function test_ingest_without_batch_code_auto_creates_and_links_production_batch(): void
    {
        [$device, $plainKey] = $this->createDevice('ESP32-INGEST-AUTO-OPEN', 'auto-open-key');

        $recordedAt = now()->subMinutes(4);

        $response = $this->postJson(route('api.devices.ingest'), [
            'weight_grams' => 58.20,
            'size_class' => 'Medium',
            'recorded_at' => $recordedAt->toIso8601String(),
            'egg_uid' => 'EGG-AUTO-001',
        ], [
            'X-Device-Serial' => $device->primary_serial_no,
            'X-Device-Key' => $plainKey,
        ]);

        $response->assertStatus(201);

        $batch = ProductionBatch::query()
            ->where('device_id', $device->id)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($batch);
        $this->assertSame('open', $batch->status);
        $this->assertStringStartsWith('AUTO-D' . $device->id . '-', (string) $batch->batch_code);
        $this->assertSame($recordedAt->toDateTimeString(), $batch->started_at?->toDateTimeString());
        $this->assertSame($recordedAt->toDateTimeString(), $batch->ended_at?->toDateTimeString());

        $this->assertDatabaseHas('device_ingest_events', [
            'device_id' => $device->id,
            'production_batch_id' => $batch->id,
            'batch_code' => $batch->batch_code,
            'egg_uid' => 'egg-auto-001',
        ]);
    }

    public function test_ingest_without_batch_code_reuses_current_open_batch_while_active(): void
    {
        [$device, $plainKey] = $this->createDevice('ESP32-INGEST-AUTO-REUSE', 'auto-reuse-key');

        $firstRecordedAt = now()->subMinutes(6);
        $secondRecordedAt = now()->subMinutes(2);

        $this->postJson(route('api.devices.ingest'), [
            'weight_grams' => 56.10,
            'size_class' => 'Medium',
            'recorded_at' => $firstRecordedAt->toIso8601String(),
            'egg_uid' => 'EGG-REUSE-001',
        ], [
            'X-Device-Serial' => $device->primary_serial_no,
            'X-Device-Key' => $plainKey,
        ])->assertStatus(201);

        $firstBatch = ProductionBatch::query()
            ->where('device_id', $device->id)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($firstBatch);

        $this->postJson(route('api.devices.ingest'), [
            'weight_grams' => 60.80,
            'size_class' => 'Large',
            'recorded_at' => $secondRecordedAt->toIso8601String(),
            'egg_uid' => 'EGG-REUSE-002',
        ], [
            'X-Device-Serial' => $device->primary_serial_no,
            'X-Device-Key' => $plainKey,
        ])->assertStatus(201);

        $this->assertSame(1, ProductionBatch::query()->where('device_id', $device->id)->count());

        $batch = $firstBatch->fresh();

        $this->assertNotNull($batch);
        $this->assertSame('open', $batch->status);
        $this->assertSame($firstRecordedAt->toDateTimeString(), $batch->started_at?->toDateTimeString());
        $this->assertSame($secondRecordedAt->toDateTimeString(), $batch->ended_at?->toDateTimeString());

        $this->assertDatabaseHas('device_ingest_events', [
            'device_id' => $device->id,
            'egg_uid' => 'egg-reuse-002',
            'production_batch_id' => $batch->id,
            'batch_code' => $batch->batch_code,
        ]);
    }

    public function test_ingest_after_idle_timeout_starts_a_new_automatic_batch(): void
    {
        [$device, $plainKey] = $this->createDevice('ESP32-INGEST-AUTO-ROTATE', 'auto-rotate-key');

        $firstRecordedAt = now()->subMinutes(AutomaticBatchLifecycleService::INACTIVITY_MINUTES + 6);
        $secondRecordedAt = now();

        $this->postJson(route('api.devices.ingest'), [
            'weight_grams' => 57.00,
            'size_class' => 'Medium',
            'recorded_at' => $firstRecordedAt->toIso8601String(),
            'egg_uid' => 'EGG-ROTATE-001',
        ], [
            'X-Device-Serial' => $device->primary_serial_no,
            'X-Device-Key' => $plainKey,
        ])->assertStatus(201);

        $firstBatch = ProductionBatch::query()
            ->where('device_id', $device->id)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($firstBatch);

        $this->postJson(route('api.devices.ingest'), [
            'weight_grams' => 62.40,
            'size_class' => 'Large',
            'recorded_at' => $secondRecordedAt->toIso8601String(),
            'egg_uid' => 'EGG-ROTATE-002',
        ], [
            'X-Device-Serial' => $device->primary_serial_no,
            'X-Device-Key' => $plainKey,
        ])->assertStatus(201);

        $batches = ProductionBatch::query()
            ->where('device_id', $device->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $batches);
        $this->assertSame('closed', (string) $batches[0]->status);
        $this->assertSame($firstRecordedAt->toDateTimeString(), $batches[0]->ended_at?->toDateTimeString());
        $this->assertSame('open', (string) $batches[1]->status);
        $this->assertSame($secondRecordedAt->toDateTimeString(), $batches[1]->started_at?->toDateTimeString());
        $this->assertNotSame($batches[0]->batch_code, $batches[1]->batch_code);
    }

    public function test_egg_uid_without_suffix_after_prefix_is_rejected(): void
    {
        [$device, $plainKey] = $this->createDevice('ESP32-INGEST-EGG-UID', 'egg-uid-key');

        $response = $this->postJson(route('api.devices.ingest'), [
            'weight_grams' => 59.20,
            'size_class' => 'Large',
            'egg_uid' => 'egg-',
        ], [
            'X-Device-Serial' => $device->primary_serial_no,
            'X-Device-Key' => $plainKey,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['egg_uid']);
    }

    public function test_runtime_config_returns_open_batch_and_current_weight_ranges(): void
    {
        [$device, $plainKey] = $this->createDevice('ESP32-RUNTIME-CONFIG-001', 'runtime-config-key');

        AppSetting::query()->updateOrCreate(
            ['setting_key' => 'egg_weight_ranges'],
            ['setting_value' => json_encode([
                'reject' => ['min' => 0, 'max' => 30.99],
                'peewee' => ['min' => 31, 'max' => 40.99],
                'pullet' => ['min' => 41, 'max' => 49.99],
                'small' => ['min' => 50, 'max' => 54.99],
                'medium' => ['min' => 55, 'max' => 59.99],
                'large' => ['min' => 60, 'max' => 64.99],
                'extra_large' => ['min' => 65, 'max' => 68.99],
                'jumbo' => ['min' => 69, 'max' => 1000],
            ])]
        );

        ProductionBatch::query()->create([
            'device_id' => $device->id,
            'farm_id' => $device->farm_id,
            'owner_user_id' => $device->owner_user_id,
            'batch_code' => 'BATCH-OPEN-001',
            'status' => 'open',
            'started_at' => now()->subMinutes(5),
            'ended_at' => null,
        ]);

        $response = $this->getJson(route('api.devices.runtime-config'), [
            'X-Device-Serial' => $device->primary_serial_no,
            'X-Device-Key' => $plainKey,
        ]);

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('data.device_serial', $device->primary_serial_no);
        $response->assertJsonPath('data.open_batch_code', 'BATCH-OPEN-001');
        $response->assertJsonPath('data.refresh_after_seconds', 60);
        $response->assertJsonPath('data.weight_ranges.reject.min', 0);
        $response->assertJsonPath('data.weight_ranges.reject.max', 30.99);
        $response->assertJsonPath('data.weight_ranges.extra_large.label', 'Extra-Large');
        $response->assertJsonPath('data.weight_ranges.extra_large.max', 68.99);
        $response->assertJsonPath('data.weight_ranges.jumbo.max', 1000);
    }

    public function test_runtime_config_auto_closes_stale_open_batch_and_returns_null(): void
    {
        [$device, $plainKey] = $this->createDevice('ESP32-RUNTIME-CONFIG-AUTO-CLOSE', 'runtime-config-auto-close-key');

        $recordedAt = now()->subMinutes(AutomaticBatchLifecycleService::INACTIVITY_MINUTES + 4);

        $batch = ProductionBatch::query()->create([
            'device_id' => $device->id,
            'farm_id' => $device->farm_id,
            'owner_user_id' => $device->owner_user_id,
            'batch_code' => 'AUTO-D' . $device->id . '-OLD',
            'status' => 'open',
            'started_at' => $recordedAt,
            'ended_at' => $recordedAt,
        ]);

        DeviceIngestEvent::query()->create([
            'device_id' => $device->id,
            'farm_id' => $device->farm_id,
            'owner_user_id' => $device->owner_user_id,
            'production_batch_id' => $batch->id,
            'egg_uid' => 'egg-runtime-close-001',
            'batch_code' => $batch->batch_code,
            'weight_grams' => 61.20,
            'size_class' => 'Large',
            'recorded_at' => $recordedAt,
            'source_ip' => '127.0.0.1',
            'raw_payload_json' => '{}',
        ]);

        $response = $this->getJson(route('api.devices.runtime-config'), [
            'X-Device-Serial' => $device->primary_serial_no,
            'X-Device-Key' => $plainKey,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.open_batch_code', null);

        $batch->refresh();

        $this->assertSame('closed', $batch->status);
        $this->assertSame($recordedAt->toDateTimeString(), $batch->ended_at?->toDateTimeString());
    }

    public function test_runtime_config_returns_null_batch_when_no_open_batch_exists(): void
    {
        [$device, $plainKey] = $this->createDevice('ESP32-RUNTIME-CONFIG-NULL', 'runtime-config-null-key');

        $response = $this->getJson(route('api.devices.runtime-config'), [
            'X-Device-Serial' => $device->primary_serial_no,
            'X-Device-Key' => $plainKey,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.open_batch_code', null);
        $response->assertJsonPath('data.weight_ranges.medium.label', 'Medium');
        $response->assertJsonPath('data.weight_ranges.medium.min', 55);
        $response->assertJsonPath('data.weight_ranges.medium.max', 59.99);
    }

    public function test_runtime_config_rejects_invalid_device_credentials(): void
    {
        [$device] = $this->createDevice('ESP32-RUNTIME-CONFIG-FAIL', 'runtime-config-fail-key');

        $response = $this->getJson(route('api.devices.runtime-config'), [
            'X-Device-Serial' => $device->primary_serial_no,
            'X-Device-Key' => 'wrong-key',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('ok', false);
        $response->assertJsonPath('message', 'Unauthorized device credentials.');
    }

    /**
     * @return array{0:\App\Models\Device,1:string}
     */
    private function createDevice(string $serial, string $plainKey): array
    {
        $owner = User::factory()->create([
            'role' => UserRole::OWNER,
            'username' => 'owner_' . strtolower(str_replace('-', '_', $serial)),
        ]);

        $farm = Farm::query()->create([
            'farm_name' => 'Farm ' . $serial,
            'location' => 'Purok 1',
            'sitio' => 'Sitio Uno',
            'barangay' => 'Barangay Uno',
            'municipality' => 'Municipality Uno',
            'province' => 'Province Uno',
            'latitude' => 10.3547270,
            'longitude' => 124.9659800,
            'owner_user_id' => $owner->id,
            'is_active' => true,
        ]);

        $device = Device::query()->create([
            'owner_user_id' => $owner->id,
            'farm_id' => $farm->id,
            'module_board_name' => 'ESP32 Device',
            'primary_serial_no' => strtoupper($serial),
            'api_key_hash' => Hash::make($plainKey),
            'is_active' => true,
        ]);

        return [$device, $plainKey];
    }
}
