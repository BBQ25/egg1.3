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

class MonitoringNotificationsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_owner_and_staff_can_access_notifications_but_customer_cannot(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->staff()->create();
        $customer = User::factory()->customer()->create();

        [$farm] = $this->createFarmAndDevice($owner, 'Notification Farm', 'ESP32-NOTIF-001', now()->subMinutes(45));
        $this->assignStaffToFarm($staff, $farm);

        $this->actingAs($admin)->get(route('monitoring.notifications.index'))
            ->assertOk()
            ->assertSee('Notifications')
            ->assertSee('Notification Farm');

        $this->actingAs($owner)->get(route('monitoring.notifications.index'))
            ->assertOk()
            ->assertSee('Notifications');

        $this->actingAs($staff)->get(route('monitoring.notifications.index'))
            ->assertOk()
            ->assertSee('Notifications');

        $this->actingAs($customer)->get(route('monitoring.notifications.index'))
            ->assertForbidden();
    }

    public function test_notifications_page_detects_offline_devices_and_reject_spikes(): void
    {
        $owner = User::factory()->owner()->create();
        [$farm, $offlineDevice] = $this->createFarmAndDevice($owner, 'Alert Farm', 'ESP32-NOTIF-002', now()->subMinutes(45));
        [, $qualityDevice] = $this->createFarmAndDevice($owner, 'Alert Farm', 'ESP32-NOTIF-003', now()->subMinutes(2), $farm);

        for ($index = 1; $index <= 10; $index++) {
            $this->insertEvent(
                $qualityDevice,
                $owner,
                'BATCH-ALERT-001',
                'egg-a' . $index,
                $index <= 3 ? 49.5 : 60.4,
                $index <= 3 ? 'Reject' : 'Large',
                now()->subMinutes(10 - $index)
            );
        }

        $response = $this->actingAs($owner)->get(route('monitoring.notifications.index', [
            'range' => '1d',
            'context_farm_id' => $farm->id,
            'severity' => 'critical',
        ]));

        $response->assertOk()
            ->assertSee('Device offline')
            ->assertSee('Reject spike detected')
            ->assertSee('ESP32 Dev Board');

        $warnResponse = $this->actingAs($owner)->get(route('monitoring.notifications.index', [
            'range' => '1d',
            'context_farm_id' => $farm->id,
            'severity' => 'warn',
        ]));

        $warnResponse->assertOk()
            ->assertSee('No alerts matched the selected monitoring scope and severity filter.');
    }

    private function createFarmAndDevice(User $owner, string $farmName, string $serial, $lastSeenAt, ?Farm $farm = null): array
    {
        $farm ??= Farm::query()->create([
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
            'last_seen_at' => $lastSeenAt,
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
