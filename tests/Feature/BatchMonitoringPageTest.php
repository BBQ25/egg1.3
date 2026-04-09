<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\AppSetting;
use App\Models\Farm;
use App\Models\ProductionBatch;
use App\Models\User;
use App\Support\AppTimezone;
use App\Support\BatchCodeFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BatchMonitoringPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_owner_and_staff_can_access_batch_monitoring_but_customer_cannot(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->staff()->create();
        $customer = User::factory()->customer()->create();

        [$farm, $device] = $this->createFarmAndDevice($owner, 'Scope Farm', 'ESP32-BATCH-001');
        $this->assignStaffToFarm($staff, $farm);
        $this->insertEvent($device, $owner, 'BATCH-ALPHA', 'egg-001', 58.4, 'Medium', now()->subMinutes(5));

        $this->actingAs($admin)->get(route('monitoring.batches.index'))
            ->assertOk()
            ->assertSee('Batch Monitoring')
            ->assertSee('BATCH-ALPHA');

        $this->actingAs($owner)->get(route('monitoring.batches.index'))
            ->assertOk()
            ->assertSee('Scope Farm');

        $this->actingAs($staff)->get(route('monitoring.batches.index'))
            ->assertOk()
            ->assertSee('Scope Farm');

        $this->actingAs($customer)->get(route('monitoring.batches.index'))
            ->assertForbidden();
    }

    public function test_batch_monitoring_groups_ingest_records_and_shows_detail_page(): void
    {
        $owner = User::factory()->owner()->create();
        [$farm, $device] = $this->createFarmAndDevice($owner, 'Grouped Farm', 'ESP32-GROUP-001');

        $this->insertEvent($device, $owner, 'BATCH-GROUPED', 'egg-101', 57.0, 'Medium', now()->subMinutes(12));
        $this->insertEvent($device, $owner, 'BATCH-GROUPED', 'egg-102', 60.2, 'Large', now()->subMinutes(8));
        $this->insertEvent($device, $owner, 'BATCH-GROUPED', 'egg-103', 49.8, 'Reject', now()->subMinutes(2));

        $listResponse = $this->actingAs($owner)->get(route('monitoring.batches.index', [
            'range' => '1d',
        ]));

        $listResponse->assertOk()
            ->assertSee('BATCH-GROUPED')
            ->assertSee('3')
            ->assertSee('1');

        $detailResponse = $this->actingAs($owner)->get(route('monitoring.batches.show', [
            'farm' => $farm->id,
            'device' => $device->id,
            'batchCode' => 'BATCH-GROUPED',
            'range' => '1d',
        ]));

        $detailResponse->assertOk()
            ->assertSee('Batch Detail')
            ->assertSee('Grouped Farm')
            ->assertSee('ESP32 Dev Board')
            ->assertSee('egg-101')
            ->assertSee('egg-103')
            ->assertSee('Reject')
            ->assertSee('Large')
            ->assertSee('Medium');
    }

    public function test_owner_cannot_view_batch_outside_owned_scope(): void
    {
        $owner = User::factory()->owner()->create();
        $otherOwner = User::factory()->owner()->create();

        [$ownFarm] = $this->createFarmAndDevice($owner, 'Owned Farm', 'ESP32-OWN-001');
        [$otherFarm, $otherDevice] = $this->createFarmAndDevice($otherOwner, 'Other Farm', 'ESP32-OTHER-001');

        $this->insertEvent($otherDevice, $otherOwner, 'BATCH-LOCKED', 'egg-201', 61.1, 'Large', now()->subMinutes(4));

        $response = $this->actingAs($owner)->get(route('monitoring.batches.show', [
            'farm' => $otherFarm->id,
            'device' => $otherDevice->id,
            'batchCode' => 'BATCH-LOCKED',
            'range' => '1d',
            'context_farm_id' => $ownFarm->id,
        ]));

        $response->assertForbidden();
    }

    public function test_batch_monitoring_index_export_downloads_csv_for_visible_scope(): void
    {
        $owner = User::factory()->owner()->create();
        [$farm, $device] = $this->createFarmAndDevice($owner, 'Export Farm', 'ESP32-EXPORT-001');

        $this->insertEvent($device, $owner, 'BATCH-CSV', 'egg-301', 62.3, 'Large', now()->subMinutes(7));

        $response = $this->actingAs($owner)->get(route('monitoring.batches.export', [
            'range' => '1d',
            'context_farm_id' => $farm->id,
            'context_device_id' => $device->id,
        ]));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();
        $this->assertStringContainsString('batch_code,farm_name,device_name,device_serial,owner_name,status', $content);
        $this->assertStringContainsString('BATCH-CSV', $content);
        $this->assertStringContainsString('Export Farm', $content);
    }

    public function test_batch_monitoring_detail_export_downloads_batch_records_csv(): void
    {
        $owner = User::factory()->owner()->create();
        [$farm, $device] = $this->createFarmAndDevice($owner, 'Detail Export Farm', 'ESP32-EXPORT-DETAIL-001');

        $this->insertEvent($device, $owner, 'BATCH-DETAIL-CSV', 'egg-401', 55.0, 'Medium', now()->subMinutes(9));
        $this->insertEvent($device, $owner, 'BATCH-DETAIL-CSV', 'egg-402', 65.8, 'Extra-Large', now()->subMinutes(6));

        $response = $this->actingAs($owner)->get(route('monitoring.batches.show.export', [
            'farm' => $farm->id,
            'device' => $device->id,
            'batchCode' => 'BATCH-DETAIL-CSV',
            'range' => '1d',
        ]));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();
        $this->assertStringContainsString('record_id,batch_code,farm_name,device_name', $content);
        $this->assertStringContainsString('BATCH-DETAIL-CSV', $content);
        $this->assertStringContainsString('egg-401', $content);
        $this->assertStringContainsString('egg-402', $content);
    }

    public function test_owner_can_close_open_batch(): void
    {
        $owner = User::factory()->owner()->create();
        [$farm, $device] = $this->createFarmAndDevice($owner, 'Closable Farm', 'ESP32-CLOSE-001');

        $this->insertEvent($device, $owner, 'BATCH-CLOSE-001', 'egg-501', 59.5, 'Medium', now()->subMinutes(5));

        $response = $this->actingAs($owner)->patch(route('monitoring.batches.close', [
            'farm' => $farm->id,
            'device' => $device->id,
            'batchCode' => 'BATCH-CLOSE-001',
            'range' => '1d',
        ]));

        $response->assertRedirect();

        $this->assertDatabaseHas('production_batches', [
            'farm_id' => $farm->id,
            'device_id' => $device->id,
            'batch_code' => 'BATCH-CLOSE-001',
            'status' => 'closed',
        ]);

        $showResponse = $this->actingAs($owner)->get(route('monitoring.batches.show', [
            'farm' => $farm->id,
            'device' => $device->id,
            'batchCode' => 'BATCH-CLOSE-001',
            'range' => '1d',
        ]));

        $showResponse->assertOk()
            ->assertSee('Closed')
            ->assertDontSee('Close Batch');
    }

    public function test_owner_can_open_batch_from_list_page(): void
    {
        $owner = User::factory()->owner()->create();
        [$farm, $device] = $this->createFarmAndDevice($owner, 'Openable Farm', 'ESP32-OPEN-001');

        $response = $this->actingAs($owner)->post(route('monitoring.batches.store', [
            'range' => '1d',
            'context_farm_id' => $farm->id,
            'context_device_id' => $device->id,
        ]), [
            'farm_id' => $farm->id,
            'device_id' => $device->id,
        ]);

        $batch = ProductionBatch::query()
            ->where('farm_id', $farm->id)
            ->where('device_id', $device->id)
            ->first();

        $this->assertNotNull($batch);
        $this->assertMatchesRegularExpression(
            '/^' . preg_quote(BatchCodeFormatter::farmPrefix($farm->farm_name), '/') . '-\d{8}-\d{6}$/',
            (string) $batch->batch_code
        );

        $response->assertRedirect(route('monitoring.batches.show', [
            'farm' => $farm->id,
            'device' => $device->id,
            'batchCode' => $batch->batch_code,
            'range' => '1d',
            'context_farm_id' => $farm->id,
            'context_device_id' => $device->id,
        ]));

        $this->assertDatabaseHas('production_batches', [
            'farm_id' => $farm->id,
            'device_id' => $device->id,
            'batch_code' => $batch->batch_code,
            'status' => 'open',
        ]);

        $showResponse = $this->actingAs($owner)->get(route('monitoring.batches.show', [
            'farm' => $farm->id,
            'device' => $device->id,
            'batchCode' => $batch->batch_code,
            'range' => '1d',
        ]));

        $showResponse->assertOk()
            ->assertSee("Batch {$batch->batch_code} opened.", false)
            ->assertSee('Open')
            ->assertSee('No records are available for this batch.');
    }

    public function test_batch_monitoring_can_filter_open_and_closed_batches(): void
    {
        $owner = User::factory()->owner()->create();
        [$farm, $device] = $this->createFarmAndDevice($owner, 'Filter Farm', 'ESP32-FILTER-001');

        $this->insertEvent($device, $owner, 'BATCH-OPEN-FILTER', 'egg-601', 58.0, 'Medium', now()->subMinutes(10));
        $this->insertEvent($device, $owner, 'BATCH-CLOSED-FILTER', 'egg-602', 61.4, 'Large', now()->subMinutes(8));

        ProductionBatch::query()
            ->where('device_id', $device->id)
            ->where('batch_code', 'BATCH-CLOSED-FILTER')
            ->update([
                'status' => 'closed',
                'ended_at' => now()->subMinutes(7),
            ]);

        $openResponse = $this->actingAs($owner)->get(route('monitoring.batches.index', [
            'range' => '1d',
            'status' => 'open',
        ]));

        $openResponse->assertOk()
            ->assertSee('BATCH-OPEN-FILTER')
            ->assertDontSee('BATCH-CLOSED-FILTER');

        $closedResponse = $this->actingAs($owner)->get(route('monitoring.batches.index', [
            'range' => '1d',
            'status' => 'closed',
        ]));

        $closedResponse->assertOk()
            ->assertSee('BATCH-CLOSED-FILTER')
            ->assertDontSee('BATCH-OPEN-FILTER');
    }

    public function test_batch_monitoring_uses_configured_timezone_label(): void
    {
        AppSetting::query()->updateOrCreate(
            ['setting_key' => AppTimezone::SETTING_KEY],
            ['setting_value' => 'UTC']
        );
        AppTimezone::clearCache();
        AppTimezone::activate('UTC');

        $owner = User::factory()->owner()->create();
        [$farm, $device] = $this->createFarmAndDevice($owner, 'Timezone Farm', 'ESP32-TZ-001');

        $this->insertEvent($device, $owner, 'BATCH-TZ-001', 'egg-tz-001', 60.5, 'Large', now()->subMinutes(3));

        $response = $this->actingAs($owner)->get(route('monitoring.batches.index', [
            'range' => '1d',
        ]));

        $response->assertOk()
            ->assertSee('Timezone: Coordinated Universal Time')
            ->assertSee('current Coordinated Universal Time time', false);
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

    private function insertEvent(Device $device, User $owner, string $batchCode, string $eggUid, float $weight, string $sizeClass, $recordedAt): void
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

        if ($recordedAt > $batch->ended_at) {
            $batch->ended_at = $recordedAt;
        }

        $batch->save();

        DB::table('device_ingest_events')->insert([
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
