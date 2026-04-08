<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\EvaluationRun;
use App\Models\ProductionBatch;
use App\Models\Farm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ValidationAccuracyPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_owner_and_staff_can_access_validation_page_but_customer_cannot(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->staff()->create();
        $customer = User::factory()->customer()->create();

        [$farm, $device] = $this->createFarmAndDevice($owner, 'Validation Farm', 'ESP32-VALIDATE-001');
        $this->assignStaffToFarm($staff, $farm);
        $this->createRun($owner, $farm, $device, 'RUN-ACCESS-001', 'Access validation run');

        $this->actingAs($admin)->get(route('monitoring.validation.index'))
            ->assertOk()
            ->assertSee('Validation &amp; Accuracy', false)
            ->assertSee('RUN-ACCESS-001');

        $this->actingAs($owner)->get(route('monitoring.validation.index'))
            ->assertOk()
            ->assertSee('Validation Farm');

        $this->actingAs($staff)->get(route('monitoring.validation.index'))
            ->assertOk()
            ->assertSee('Validation Farm');

        $this->actingAs($customer)->get(route('monitoring.validation.index'))
            ->assertForbidden();
    }

    public function test_owner_can_create_run_record_measurements_and_complete_target(): void
    {
        $owner = User::factory()->owner()->create();
        [$farm, $device] = $this->createFarmAndDevice($owner, 'Validation Flow Farm', 'ESP32-VALIDATE-002');

        $eventOneId = $this->insertEvent($device, $owner, 'BATCH-VAL-001', 'egg-val-001', 60.80, 'Large', now()->subMinutes(9));
        $eventTwoId = $this->insertEvent($device, $owner, 'BATCH-VAL-001', 'egg-val-002', 48.90, 'Reject', now()->subMinutes(7));

        $createResponse = $this->actingAs($owner)->post(route('monitoring.validation.runs.store', [
            'range' => '1d',
            'context_farm_id' => $farm->id,
            'context_device_id' => $device->id,
        ]), [
            'farm_id' => $farm->id,
            'device_id' => $device->id,
            'run_code' => 'RUN-VAL-001',
            'title' => 'Reference scale comparison',
            'sample_size_target' => 2,
        ]);

        $run = EvaluationRun::query()->where('run_code', 'RUN-VAL-001')->first();

        $createResponse->assertRedirect(route('monitoring.validation.index', [
            'range' => '1d',
            'context_farm_id' => $farm->id,
            'context_device_id' => $device->id,
            'status' => 'all',
            'run' => $run?->id,
        ]));

        $this->assertNotNull($run);

        $this->actingAs($owner)->post(route('monitoring.validation.measurements.store', [
            'run' => $run->id,
            'range' => '1d',
            'context_farm_id' => $farm->id,
            'context_device_id' => $device->id,
        ]), [
            'device_ingest_event_id' => $eventOneId,
            'reference_weight_grams' => 60.10,
            'manual_size_class' => 'Large',
        ])->assertRedirect();

        $secondMeasurementResponse = $this->actingAs($owner)->post(route('monitoring.validation.measurements.store', [
            'run' => $run->id,
            'range' => '1d',
            'context_farm_id' => $farm->id,
            'context_device_id' => $device->id,
        ]), [
            'device_ingest_event_id' => $eventTwoId,
            'reference_weight_grams' => 50.10,
            'manual_size_class' => 'Small',
        ]);

        $secondMeasurementResponse->assertRedirect();

        $this->assertDatabaseHas('evaluation_run_measurements', [
            'evaluation_run_id' => $run->id,
            'device_ingest_event_id' => $eventOneId,
            'manual_size_class' => 'Large',
            'automated_size_class' => 'Large',
            'class_match' => true,
        ]);

        $this->assertDatabaseHas('evaluation_run_measurements', [
            'evaluation_run_id' => $run->id,
            'device_ingest_event_id' => $eventTwoId,
            'manual_size_class' => 'Small',
            'automated_size_class' => 'Reject',
            'class_match' => false,
        ]);

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertNotNull($run->ended_at);

        $pageResponse = $this->actingAs($owner)->get(route('monitoring.validation.index', [
            'range' => '1d',
            'context_farm_id' => $farm->id,
            'context_device_id' => $device->id,
            'run' => $run->id,
        ]));

        $pageResponse->assertOk()
            ->assertSee('RUN-VAL-001')
            ->assertSee('Reference scale comparison')
            ->assertSee('egg-val-001')
            ->assertSee('egg-val-002')
            ->assertSee('50.00%')
            ->assertSee('0.95 g')
            ->assertSee('0.97 g')
            ->assertSee('0.98 g')
            ->assertSee('-0.25 g');
    }

    public function test_validation_export_downloads_run_csv(): void
    {
        $owner = User::factory()->owner()->create();
        [$farm, $device] = $this->createFarmAndDevice($owner, 'Validation Export Farm', 'ESP32-VALIDATE-003');
        $run = $this->createRun($owner, $farm, $device, 'RUN-EXPORT-001', 'Export run');
        $eventId = $this->insertEvent($device, $owner, 'BATCH-VAL-CSV', 'egg-export-001', 61.30, 'Large', now()->subMinutes(6));

        DB::table('evaluation_run_measurements')->insert([
            'evaluation_run_id' => $run->id,
            'device_ingest_event_id' => $eventId,
            'egg_uid' => 'egg-export-001',
            'batch_code' => 'BATCH-VAL-CSV',
            'reference_weight_grams' => 60.20,
            'automated_weight_grams' => 61.30,
            'manual_size_class' => 'Large',
            'automated_size_class' => 'Large',
            'weight_error_grams' => 1.10,
            'absolute_error_grams' => 1.10,
            'class_match' => true,
            'measured_at' => now(),
            'notes' => 'Export sample',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($owner)->get(route('monitoring.validation.export', [
            'run' => $run->id,
            'range' => '1d',
            'context_farm_id' => $farm->id,
            'context_device_id' => $device->id,
        ]));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();
        $this->assertStringContainsString('measurement_id,measured_at,egg_uid,batch_code,reference_weight_grams,automated_weight_grams,weight_error_grams,absolute_error_grams,squared_error_grams', $content);
        $this->assertStringContainsString('egg-export-001', $content);
        $this->assertStringContainsString('1.10', $content);
        $this->assertStringContainsString('1.21', $content);
    }

    public function test_validation_page_shows_unavailable_state_when_tables_are_missing(): void
    {
        $owner = User::factory()->owner()->create();

        Schema::dropIfExists('evaluation_run_measurements');
        Schema::dropIfExists('evaluation_runs');

        $response = $this->actingAs($owner)->get(route('monitoring.validation.index'));

        $response->assertOk()
            ->assertSee('Validation storage is not ready in this environment yet.')
            ->assertSee('Missing tables: evaluation_runs, evaluation_run_measurements.')
            ->assertSee('Validation run storage is unavailable until the pending monitoring migrations are applied.');
    }

    private function createFarmAndDevice(User $owner, string $farmName, string $serial): array
    {
        $farm = Farm::query()->create([
            'farm_name' => $farmName,
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
            'module_board_name' => 'ESP32 Dev Board',
            'primary_serial_no' => $serial,
            'main_technical_specs' => null,
            'processing_memory' => null,
            'gpio_interfaces' => null,
            'api_key_hash' => Hash::make('test-key'),
            'is_active' => true,
            'last_seen_at' => now(),
            'last_seen_ip' => '127.0.0.1',
            'deactivated_at' => null,
            'created_by_user_id' => $owner->id,
            'updated_by_user_id' => $owner->id,
        ]);

        return [$farm, $device];
    }

    private function assignStaffToFarm(User $staff, Farm $farm): void
    {
        DB::table('farm_staff_assignments')->insert([
            'farm_id' => $farm->id,
            'user_id' => $staff->id,
            'created_at' => now(),
        ]);
    }

    private function createRun(User $owner, Farm $farm, Device $device, string $runCode, string $title): EvaluationRun
    {
        return EvaluationRun::query()->create([
            'farm_id' => $farm->id,
            'device_id' => $device->id,
            'owner_user_id' => $owner->id,
            'performed_by_user_id' => $owner->id,
            'run_code' => $runCode,
            'title' => $title,
            'status' => 'in_progress',
            'sample_size_target' => 5,
            'started_at' => now()->subMinutes(15),
            'notes' => null,
        ]);
    }

    private function insertEvent(Device $device, User $owner, string $batchCode, string $eggUid, float $weight, string $sizeClass, $recordedAt): int
    {
        $batch = ProductionBatch::query()->firstOrCreate(
            [
                'device_id' => $device->id,
                'farm_id' => $device->farm_id,
                'owner_user_id' => $owner->id,
                'batch_code' => $batchCode,
            ],
            [
                'status' => 'open',
                'started_at' => $recordedAt,
                'ended_at' => $recordedAt,
            ]
        );

        if ($recordedAt < $batch->started_at) {
            $batch->started_at = $recordedAt;
        }

        if ($batch->ended_at === null || $recordedAt > $batch->ended_at) {
            $batch->ended_at = $recordedAt;
        }

        $batch->save();

        return (int) DB::table('device_ingest_events')->insertGetId([
            'device_id' => $device->id,
            'farm_id' => $device->farm_id,
            'owner_user_id' => $owner->id,
            'production_batch_id' => $batch->id,
            'egg_uid' => $eggUid,
            'batch_code' => $batchCode,
            'weight_grams' => $weight,
            'size_class' => $sizeClass,
            'recorded_at' => $recordedAt,
            'source_ip' => '127.0.0.1',
            'raw_payload_json' => '{}',
            'created_at' => $recordedAt,
        ]);
    }
}
