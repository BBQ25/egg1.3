<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\ProductionBatch;
use App\Models\Farm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProductionReportPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_owner_and_staff_can_access_production_reports_but_customer_cannot(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->staff()->create();
        $customer = User::factory()->customer()->create();

        [$farm, $device] = $this->createFarmAndDevice($owner, 'Report Farm', 'ESP32-REPORT-001');
        $this->assignStaffToFarm($staff, $farm);
        $this->insertEvent($device, $owner, 'BATCH-REPORT-001', 'egg-report-001', 58.4, 'Medium', now()->subMinutes(5));

        $this->actingAs($admin)->get(route('monitoring.reports.production.index'))
            ->assertOk()
            ->assertSee('Production Reports')
            ->assertSee('Report Farm');

        $this->actingAs($owner)->get(route('monitoring.reports.production.index'))
            ->assertOk()
            ->assertSee('Production Reports');

        $this->actingAs($staff)->get(route('monitoring.reports.production.index'))
            ->assertOk()
            ->assertSee('Production Reports');

        $this->actingAs($customer)->get(route('monitoring.reports.production.index'))
            ->assertForbidden();
    }

    public function test_production_reports_aggregate_filtered_records(): void
    {
        $owner = User::factory()->owner()->create();
        [$farm, $device] = $this->createFarmAndDevice($owner, 'Aggregate Report Farm', 'ESP32-REPORT-002');

        $this->insertEvent($device, $owner, 'BATCH-REPORT-A', 'egg-r1', 57.1, 'Medium', now()->subDays(2));
        $this->insertEvent($device, $owner, 'BATCH-REPORT-A', 'egg-r2', 67.3, 'Extra-Large', now()->subDay());
        $this->insertEvent($device, $owner, 'BATCH-REPORT-B', 'egg-r3', 49.2, 'Reject', now()->subDay());

        $response = $this->actingAs($owner)->get(route('monitoring.reports.production.index', [
            'range' => '1w',
            'context_farm_id' => $farm->id,
            'context_device_id' => $device->id,
            'batch_code' => 'BATCH-REPORT-A',
        ]));

        $response->assertOk()
            ->assertSee('Aggregate Report Farm')
            ->assertSee('BATCH-REPORT-A', false)
            ->assertSee('2')
            ->assertDontSee('BATCH-REPORT-B', false)
            ->assertSee('124.40 g');
    }

    public function test_production_reports_export_downloads_daily_csv(): void
    {
        $owner = User::factory()->owner()->create();
        [$farm, $device] = $this->createFarmAndDevice($owner, 'Export Report Farm', 'ESP32-REPORT-003');

        $this->insertEvent($device, $owner, 'BATCH-REPORT-CSV', 'egg-csv-r1', 60.0, 'Large', now()->subDay());
        $this->insertEvent($device, $owner, 'BATCH-REPORT-CSV', 'egg-csv-r2', 61.5, 'Large', now()->subDay());

        $response = $this->actingAs($owner)->get(route('monitoring.reports.production.export', [
            'range' => '1w',
            'context_farm_id' => $farm->id,
            'context_device_id' => $device->id,
        ]));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();
        $this->assertStringContainsString('report_date,total_records,unique_batches,reject_count,avg_weight_grams,total_weight_grams', $content);
        $this->assertStringContainsString('2,1,0,60.75,121.50', $content);
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

        if ($batch->ended_at === null || $recordedAt > $batch->ended_at) {
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
