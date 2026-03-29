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

class EggRecordExplorerPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_owner_and_staff_can_access_egg_record_explorer_but_customer_cannot(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->staff()->create();
        $customer = User::factory()->customer()->create();

        [$farm, $device] = $this->createFarmAndDevice($owner, 'Explorer Farm', 'ESP32-RECORDS-001');
        $this->assignStaffToFarm($staff, $farm);
        $this->insertEvent(
            $device,
            $owner,
            'BATCH-REC-001',
            'egg-record-001',
            58.4,
            'Medium',
            now()->subMinutes(5),
            [
                'esp32_mac' => '24:6F:28:AA:10:01',
                'router_mac' => 'F4:EC:38:9B:77:20',
                'wifi_ssid' => 'PoultryPulse-Lab',
            ]
        );

        $this->actingAs($admin)->get(route('monitoring.records.index'))
            ->assertOk()
            ->assertSee('Egg Record Explorer')
            ->assertSee('egg-record-001');

        $this->actingAs($owner)->get(route('monitoring.records.index'))
            ->assertOk()
            ->assertSee('Explorer Farm')
            ->assertSee('24:6F:28:AA:10:01')
            ->assertSee('F4:EC:38:9B:77:20')
            ->assertSee('PoultryPulse-Lab');

        $this->actingAs($staff)->get(route('monitoring.records.index'))
            ->assertOk()
            ->assertSee('Explorer Farm');

        $this->actingAs($customer)->get(route('monitoring.records.index'))
            ->assertForbidden();
    }

    public function test_egg_record_explorer_filters_records_and_links_to_batch_detail(): void
    {
        $owner = User::factory()->owner()->create();
        [$farm, $device] = $this->createFarmAndDevice($owner, 'Filter Explorer Farm', 'ESP32-RECORDS-002');

        $this->insertEvent($device, $owner, 'BATCH-REC-OPEN', 'egg-alpha', 57.2, 'Medium', now()->subMinutes(10));
        $this->insertEvent($device, $owner, 'BATCH-REC-OPEN', 'egg-beta', 66.4, 'Large', now()->subMinutes(8));
        $this->insertEvent($device, $owner, 'BATCH-REC-CLOSED', 'egg-gamma', 49.0, 'Reject', now()->subMinutes(6));

        ProductionBatch::query()
            ->where('device_id', $device->id)
            ->where('batch_code', 'BATCH-REC-CLOSED')
            ->update([
                'status' => 'closed',
                'ended_at' => now()->subMinutes(5),
            ]);

        $response = $this->actingAs($owner)->get(route('monitoring.records.index', [
            'range' => '1d',
            'context_farm_id' => $farm->id,
            'context_device_id' => $device->id,
            'egg_uid' => 'EGG-BETA',
            'batch_code' => 'BATCH-REC-OPEN',
            'size_class' => 'Large',
            'weight_min' => 65,
            'weight_max' => 67,
        ]));

        $response->assertOk()
            ->assertSee('egg-beta')
            ->assertDontSee('egg-alpha')
            ->assertDontSee('egg-gamma')
            ->assertSee('BATCH-REC-OPEN')
            ->assertSee(route('monitoring.batches.show', [
                'farm' => $farm->id,
                'device' => $device->id,
                'batchCode' => 'BATCH-REC-OPEN',
                'range' => '1d',
                'context_farm_id' => $farm->id,
                'context_device_id' => $device->id,
                'batch_code' => 'BATCH-REC-OPEN',
                'egg_uid' => 'egg-beta',
                'size_class' => 'Large',
                'weight_min' => 65,
                'weight_max' => 67,
            ]));
    }

    public function test_egg_record_explorer_export_downloads_filtered_csv(): void
    {
        $owner = User::factory()->owner()->create();
        [$farm, $device] = $this->createFarmAndDevice($owner, 'Export Explorer Farm', 'ESP32-RECORDS-003');

        $this->insertEvent(
            $device,
            $owner,
            'BATCH-REC-CSV',
            'egg-csv-001',
            62.3,
            'Large',
            now()->subMinutes(7),
            [
                'esp32_mac' => '24:6F:28:AA:10:01',
                'router_mac' => 'F4:EC:38:9B:77:20',
                'wifi_ssid' => 'PoultryPulse-Lab',
            ]
        );
        $this->insertEvent($device, $owner, 'BATCH-REC-CSV', 'egg-csv-002', 54.0, 'Medium', now()->subMinutes(6));

        $response = $this->actingAs($owner)->get(route('monitoring.records.export', [
            'range' => '1d',
            'context_farm_id' => $farm->id,
            'context_device_id' => $device->id,
            'size_class' => 'Large',
        ]));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();
        $this->assertStringContainsString('record_id,recorded_at,egg_uid,batch_code,batch_status,size_class', $content);
        $this->assertStringContainsString('egg-csv-001', $content);
        $this->assertStringContainsString('24:6F:28:AA:10:01', $content);
        $this->assertStringContainsString('F4:EC:38:9B:77:20', $content);
        $this->assertStringContainsString('PoultryPulse-Lab', $content);
        $this->assertStringNotContainsString('egg-csv-002', $content);
    }

    public function test_egg_record_explorer_live_feed_returns_recent_records_for_owner_scope(): void
    {
        $owner = User::factory()->owner()->create();
        [$farm, $device] = $this->createFarmAndDevice($owner, 'Live Feed Farm', 'ESP32-RECORDS-004');

        $this->insertEvent($device, $owner, 'BATCH-LIVE-001', 'egg-live-001', 59.8, 'Medium', now()->subSeconds(6));
        $this->insertEvent($device, $owner, 'BATCH-LIVE-001', 'egg-live-002', 63.1, 'Large', now()->subSeconds(2));

        $response = $this->actingAs($owner)->getJson(route('monitoring.records.live', [
            'range' => '1d',
            'context_farm_id' => $farm->id,
            'context_device_id' => $device->id,
        ]));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'refresh_interval_seconds' => 2,
            ])
            ->assertJsonPath('stats.total_records', 2)
            ->assertJsonPath('pagination.current_page', 1)
            ->assertJsonPath('pagination.last_page', 1)
            ->assertJsonPath('recent_records.0.egg_uid', 'egg-live-002')
            ->assertJsonPath('recent_records.0.farm_name', 'Live Feed Farm')
            ->assertJsonPath('recent_records.0.owner_name', $owner->full_name)
            ->assertJsonPath('recent_records.0.device_serial', 'ESP32-RECORDS-004')
            ->assertJsonPath('recent_records.1.egg_uid', 'egg-live-001')
            ->assertJsonFragment([
                'size_class' => 'Medium',
                'total' => 1,
            ])
            ->assertJsonFragment([
                'size_class' => 'Large',
                'total' => 1,
            ]);
    }

    public function test_egg_record_explorer_live_feed_supports_pagination(): void
    {
        $owner = User::factory()->owner()->create();
        [$farm, $device] = $this->createFarmAndDevice($owner, 'Paged Live Feed Farm', 'ESP32-RECORDS-005');

        for ($index = 1; $index <= 10; $index++) {
            $this->insertEvent(
                $device,
                $owner,
                'BATCH-LIVE-PAGED',
                sprintf('egg-live-page-%03d', $index),
                54 + $index,
                $index % 2 === 0 ? 'Large' : 'Medium',
                now()->subSeconds(11 - $index)
            );
        }

        $response = $this->actingAs($owner)->getJson(route('monitoring.records.live', [
            'range' => '1d',
            'context_farm_id' => $farm->id,
            'context_device_id' => $device->id,
            'live_page' => 2,
        ]));

        $response->assertOk()
            ->assertJsonPath('stats.total_records', 10)
            ->assertJsonPath('pagination.current_page', 2)
            ->assertJsonPath('pagination.last_page', 2)
            ->assertJsonPath('pagination.per_page', 8)
            ->assertJsonPath('pagination.total', 10)
            ->assertJsonPath('pagination.from', 9)
            ->assertJsonPath('pagination.to', 10)
            ->assertJsonPath('recent_records.0.egg_uid', 'egg-live-page-002')
            ->assertJsonPath('recent_records.1.egg_uid', 'egg-live-page-001');
    }

    public function test_customer_cannot_access_egg_record_explorer_live_feed(): void
    {
        $customer = User::factory()->customer()->create();

        $this->actingAs($customer)
            ->getJson(route('monitoring.records.live'))
            ->assertForbidden();
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

    private function insertEvent(
        Device $device,
        User $owner,
        string $batchCode,
        string $eggUid,
        float $weight,
        string $sizeClass,
        $recordedAt,
        array $metadata = []
    ): void
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
            'raw_payload_json' => json_encode([
                'weight_grams' => $weight,
                'size_class' => $sizeClass,
                'recorded_at' => $recordedAt instanceof \DateTimeInterface ? $recordedAt->format(DATE_ATOM) : (string) $recordedAt,
                'batch_code' => $batchCode,
                'egg_uid' => $eggUid,
                'metadata' => $metadata,
            ]),
            'created_at' => $recordedAt,
        ]);
    }
}
