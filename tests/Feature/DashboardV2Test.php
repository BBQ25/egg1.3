<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Device;
use App\Models\EggItem;
use App\Models\Farm;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DashboardV2Test extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_page_is_accessible_for_all_authenticated_roles(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->staff()->create();
        $customer = User::factory()->customer()->create();

        $this->actingAs($admin)->get(route('dashboard'))->assertOk()->assertSee('Real-Time Poultry Monitoring');
        $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertSee('dashboardApp');
        $this->actingAs($staff)->get(route('dashboard'))->assertOk()->assertSee('dashboardApp');
        $this->actingAs($customer)->get(route('dashboard'))->assertOk()->assertSee('dashboardApp');
    }

    public function test_dashboard_sidebar_shows_machine_blueprint_for_owner_and_staff_but_not_customer(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->staff()->create();
        $customer = User::factory()->customer()->create();

        $ownerResponse = $this->actingAs($owner)->get(route('dashboard'));
        $staffResponse = $this->actingAs($staff)->get(route('dashboard'));
        $customerResponse = $this->actingAs($customer)->get(route('dashboard'));

        $ownerResponse->assertOk()->assertSee(route('machine-blueprint.index'))->assertSee('Machine Blueprint');
        $staffResponse->assertOk()->assertSee(route('machine-blueprint.index'))->assertSee('Machine Blueprint');
        $customerResponse->assertOk()->assertDontSee(route('machine-blueprint.index'))->assertDontSee('Machine Blueprint');
    }

    public function test_dashboard_sidebar_shows_inventory_for_owner_and_staff_but_not_customer(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->staff()->create();
        $customer = User::factory()->customer()->create();

        $ownerResponse = $this->actingAs($owner)->get(route('dashboard'));
        $staffResponse = $this->actingAs($staff)->get(route('dashboard'));
        $customerResponse = $this->actingAs($customer)->get(route('dashboard'));

        $ownerResponse->assertOk()->assertSee(route('inventory.index'))->assertSee('Inventory');
        $staffResponse->assertOk()->assertSee(route('inventory.index'))->assertSee('Inventory');
        $customerResponse->assertOk()->assertDontSee(route('inventory.index'))->assertDontSee('Inventory');
    }

    public function test_dashboard_sidebar_shows_batch_monitoring_for_admin_owner_and_staff_but_not_customer(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->staff()->create();
        $customer = User::factory()->customer()->create();

        $adminResponse = $this->actingAs($admin)->get(route('dashboard'));
        $ownerResponse = $this->actingAs($owner)->get(route('dashboard'));
        $staffResponse = $this->actingAs($staff)->get(route('dashboard'));
        $customerResponse = $this->actingAs($customer)->get(route('dashboard'));

        $adminResponse->assertOk()->assertSee(route('monitoring.batches.index'))->assertSee('Batch Monitoring');
        $ownerResponse->assertOk()->assertSee(route('monitoring.batches.index'))->assertSee('Batch Monitoring');
        $staffResponse->assertOk()->assertSee(route('monitoring.batches.index'))->assertSee('Batch Monitoring');
        $customerResponse->assertOk()->assertDontSee(route('monitoring.batches.index'))->assertDontSee('Batch Monitoring');
    }

    public function test_dashboard_sidebar_shows_egg_records_for_admin_owner_and_staff_but_not_customer(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->staff()->create();
        $customer = User::factory()->customer()->create();

        $adminResponse = $this->actingAs($admin)->get(route('dashboard'));
        $ownerResponse = $this->actingAs($owner)->get(route('dashboard'));
        $staffResponse = $this->actingAs($staff)->get(route('dashboard'));
        $customerResponse = $this->actingAs($customer)->get(route('dashboard'));

        $adminResponse->assertOk()->assertSee(route('monitoring.records.index'))->assertSee('Egg Records');
        $ownerResponse->assertOk()->assertSee(route('monitoring.records.index'))->assertSee('Egg Records');
        $staffResponse->assertOk()->assertSee(route('monitoring.records.index'))->assertSee('Egg Records');
        $customerResponse->assertOk()->assertDontSee(route('monitoring.records.index'))->assertDontSee('Egg Records');
    }

    public function test_dashboard_sidebar_shows_production_reports_for_admin_owner_and_staff_but_not_customer(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->staff()->create();
        $customer = User::factory()->customer()->create();

        $adminResponse = $this->actingAs($admin)->get(route('dashboard'));
        $ownerResponse = $this->actingAs($owner)->get(route('dashboard'));
        $staffResponse = $this->actingAs($staff)->get(route('dashboard'));
        $customerResponse = $this->actingAs($customer)->get(route('dashboard'));

        $adminResponse->assertOk()->assertSee(route('monitoring.reports.production.index'))->assertSee('Production Reports');
        $ownerResponse->assertOk()->assertSee(route('monitoring.reports.production.index'))->assertSee('Production Reports');
        $staffResponse->assertOk()->assertSee(route('monitoring.reports.production.index'))->assertSee('Production Reports');
        $customerResponse->assertOk()->assertDontSee(route('monitoring.reports.production.index'))->assertDontSee('Production Reports');
    }

    public function test_dashboard_sidebar_shows_notifications_for_admin_owner_and_staff_but_not_customer(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->staff()->create();
        $customer = User::factory()->customer()->create();

        $adminResponse = $this->actingAs($admin)->get(route('dashboard'));
        $ownerResponse = $this->actingAs($owner)->get(route('dashboard'));
        $staffResponse = $this->actingAs($staff)->get(route('dashboard'));
        $customerResponse = $this->actingAs($customer)->get(route('dashboard'));

        $adminResponse->assertOk()->assertSee(route('monitoring.notifications.index'))->assertSee('Notifications');
        $ownerResponse->assertOk()->assertSee(route('monitoring.notifications.index'))->assertSee('Notifications');
        $staffResponse->assertOk()->assertSee(route('monitoring.notifications.index'))->assertSee('Notifications');
        $customerResponse->assertOk()->assertDontSee(route('monitoring.notifications.index'));
    }

    public function test_dashboard_sidebar_shows_validation_for_admin_owner_and_staff_but_not_customer(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->staff()->create();
        $customer = User::factory()->customer()->create();

        $adminResponse = $this->actingAs($admin)->get(route('dashboard'));
        $ownerResponse = $this->actingAs($owner)->get(route('dashboard'));
        $staffResponse = $this->actingAs($staff)->get(route('dashboard'));
        $customerResponse = $this->actingAs($customer)->get(route('dashboard'));

        $adminResponse->assertOk()->assertSee(route('monitoring.validation.index'))->assertSee('Validation &amp; Accuracy', false);
        $ownerResponse->assertOk()->assertSee(route('monitoring.validation.index'))->assertSee('Validation &amp; Accuracy', false);
        $staffResponse->assertOk()->assertSee(route('monitoring.validation.index'))->assertSee('Validation &amp; Accuracy', false);
        $customerResponse->assertOk()->assertDontSee(route('monitoring.validation.index'));
    }

    public function test_dashboard_sidebar_shows_my_farms_for_owner_only(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->staff()->create();
        $customer = User::factory()->customer()->create();

        $ownerResponse = $this->actingAs($owner)->get(route('dashboard'));
        $staffResponse = $this->actingAs($staff)->get(route('dashboard'));
        $customerResponse = $this->actingAs($customer)->get(route('dashboard'));

        $ownerResponse->assertOk()->assertSee(route('owner.farms.index'))->assertSee('My Farms');
        $staffResponse->assertOk()->assertDontSee(route('owner.farms.index'))->assertDontSee('My Farms');
        $customerResponse->assertOk()->assertDontSee(route('owner.farms.index'))->assertDontSee('My Farms');
    }

    public function test_dashboard_sidebar_shows_price_monitoring_for_admin_and_customer_only(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->staff()->create();
        $customer = User::factory()->customer()->create();

        $adminResponse = $this->actingAs($admin)->get(route('dashboard'));
        $ownerResponse = $this->actingAs($owner)->get(route('dashboard'));
        $staffResponse = $this->actingAs($staff)->get(route('dashboard'));
        $customerResponse = $this->actingAs($customer)->get(route('dashboard'));

        $adminResponse->assertOk()->assertSee(route('price-monitoring.index'))->assertSee('Price Monitoring');
        $customerResponse->assertOk()->assertSee(route('price-monitoring.index'))->assertSee('Price Monitoring');
        $ownerResponse->assertOk()->assertDontSee(route('price-monitoring.index'))->assertDontSee('Price Monitoring');
        $staffResponse->assertOk()->assertDontSee(route('price-monitoring.index'))->assertDontSee('Price Monitoring');
    }

    public function test_dashboard_data_returns_schema_complete_payload(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->getJson(route('dashboard.data'));

        $response->assertOk();
        $response->assertJsonStructure([
            'ok',
            'as_of',
            'range',
            'context',
            'summary',
            'size_breakdown',
            'activity_breakdown',
            'band_breakdown',
            'top_active',
            'timeline',
        ]);
        $response->assertJson(['ok' => true]);
    }

    public function test_owner_cannot_request_out_of_scope_farm_context(): void
    {
        $owner = User::factory()->owner()->create();
        $otherOwner = User::factory()->owner()->create();

        Farm::query()->create([
            'farm_name' => 'Owner Farm',
            'location' => 'Loc A',
            'sitio' => 'Sitio A',
            'barangay' => 'Barangay A',
            'municipality' => 'Town A',
            'province' => 'Province A',
            'latitude' => null,
            'longitude' => null,
            'owner_user_id' => $owner->id,
            'is_active' => true,
        ]);

        $forbiddenFarm = Farm::query()->create([
            'farm_name' => 'Forbidden Farm',
            'location' => 'Loc B',
            'sitio' => 'Sitio B',
            'barangay' => 'Barangay B',
            'municipality' => 'Town B',
            'province' => 'Province B',
            'latitude' => null,
            'longitude' => null,
            'owner_user_id' => $otherOwner->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($owner)->getJson(route('dashboard.data', [
            'context_farm_id' => $forbiddenFarm->id,
        ]));

        $response->assertStatus(403);
        $response->assertJson(['ok' => false]);
    }

    public function test_dashboard_data_respects_range_filters_and_builds_timeline(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->owner()->create();

        $farm = Farm::query()->create([
            'farm_name' => 'Range Farm',
            'location' => 'Purok 1',
            'sitio' => 'Sitio 1',
            'barangay' => 'Barangay 1',
            'municipality' => 'Municipality 1',
            'province' => 'Province 1',
            'latitude' => null,
            'longitude' => null,
            'owner_user_id' => $owner->id,
            'is_active' => true,
        ]);

        $device = Device::query()->create([
            'owner_user_id' => $owner->id,
            'farm_id' => $farm->id,
            'module_board_name' => 'ESP32 Dev Board',
            'primary_serial_no' => 'ESP32-RANGE-001',
            'main_technical_specs' => null,
            'processing_memory' => null,
            'gpio_interfaces' => null,
            'api_key_hash' => Hash::make('test-key'),
            'is_active' => true,
            'last_seen_at' => now(),
            'last_seen_ip' => '127.0.0.1',
            'deactivated_at' => null,
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
        ]);

        DB::table('device_ingest_events')->insert([
            [
                'device_id' => $device->id,
                'farm_id' => $farm->id,
                'owner_user_id' => $owner->id,
                'egg_uid' => 'egg-001',
                'batch_code' => 'B1',
                'weight_grams' => 58.20,
                'size_class' => 'Medium',
                'recorded_at' => now()->subDays(2),
                'source_ip' => '127.0.0.1',
                'raw_payload_json' => '{}',
                'created_at' => now()->subDays(2),
            ],
            [
                'device_id' => $device->id,
                'farm_id' => $farm->id,
                'owner_user_id' => $owner->id,
                'egg_uid' => 'egg-002',
                'batch_code' => 'B1',
                'weight_grams' => 66.10,
                'size_class' => 'Large',
                'recorded_at' => now()->subDay(),
                'source_ip' => '127.0.0.1',
                'raw_payload_json' => '{}',
                'created_at' => now()->subDay(),
            ],
        ]);

        $response = $this->actingAs($admin)->getJson(route('dashboard.data', [
            'range' => '1w',
            'context_farm_id' => $farm->id,
            'context_device_id' => $device->id,
        ]));

        $response->assertOk();
        $response->assertJsonPath('range', '1w');
        $timeline = $response->json('timeline');
        $this->assertIsArray($timeline);
        $this->assertGreaterThanOrEqual(7, count($timeline));
    }

    public function test_empty_database_state_returns_fallback_payload_without_errors(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->getJson(route('dashboard.data'));

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('summary.total_eggs', 0);
        $response->assertJsonPath('summary.active_farms', 0);
        $this->assertIsArray($response->json('timeline'));
    }

    public function test_dashboard_shows_tray_equivalents_for_admin_stock_summary(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->owner()->create();

        $farm = Farm::query()->create([
            'farm_name' => 'Tray Dashboard Farm',
            'location' => 'Purok 1',
            'sitio' => 'Sitio 1',
            'barangay' => 'Barangay 1',
            'municipality' => 'Municipality 1',
            'province' => 'Province 1',
            'latitude' => null,
            'longitude' => null,
            'owner_user_id' => $owner->id,
            'is_active' => true,
        ]);

        $item = EggItem::query()->create([
            'farm_id' => $farm->id,
            'item_code' => 'DASH-TRAY-001',
            'egg_type' => 'Chicken Egg',
            'size_class' => 'Medium',
            'unit_cost' => 7.50,
            'selling_price' => 8.50,
            'reorder_level' => 10,
            'current_stock' => 45,
        ]);

        $movement = StockMovement::query()->create([
            'item_id' => $item->id,
            'movement_type' => 'IN',
            'quantity' => 45,
            'stock_before' => 0,
            'stock_after' => 45,
            'unit_cost' => 7.50,
            'reference_no' => 'DASH-TRAY-REF',
            'notes' => 'Dashboard tray summary seed',
            'movement_date' => now()->toDateString(),
        ]);

        DB::table('egg_intake_records')->insert([
            'farm_id' => $farm->id,
            'item_id' => $item->id,
            'movement_id' => $movement->id,
            'source' => 'MANUAL',
            'egg_type' => 'Chicken Egg',
            'size_class' => 'Medium',
            'weight_grams' => 0,
            'quantity' => 45,
            'stock_before' => 0,
            'stock_after' => 45,
            'reference_no' => 'DASH-TRAY-REF',
            'notes' => 'Dashboard tray summary seed',
            'payload_json' => '{}',
            'created_by_user_id' => $owner->id,
            'recorded_at' => now(),
            'created_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('45');
        $response->assertSee('1 tray + 1/2 tray');
    }

    public function test_dashboard_data_ignores_zero_weight_manual_intake_for_average_weight_metrics(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->owner()->create();

        $farm = Farm::query()->create([
            'farm_name' => 'Weighted Metrics Farm',
            'location' => 'Purok 1',
            'sitio' => 'Sitio 1',
            'barangay' => 'Barangay 1',
            'municipality' => 'Municipality 1',
            'province' => 'Province 1',
            'latitude' => null,
            'longitude' => null,
            'owner_user_id' => $owner->id,
            'is_active' => true,
        ]);

        $device = Device::query()->create([
            'owner_user_id' => $owner->id,
            'farm_id' => $farm->id,
            'module_board_name' => 'ESP32 Dev Board',
            'primary_serial_no' => 'ESP32-WEIGHT-001',
            'main_technical_specs' => null,
            'processing_memory' => null,
            'gpio_interfaces' => null,
            'api_key_hash' => Hash::make('test-key'),
            'is_active' => true,
            'last_seen_at' => now(),
            'last_seen_ip' => '127.0.0.1',
            'deactivated_at' => null,
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
        ]);

        DB::table('device_ingest_events')->insert([
            'device_id' => $device->id,
            'farm_id' => $farm->id,
            'owner_user_id' => $owner->id,
            'egg_uid' => 'weighted-egg-001',
            'batch_code' => 'WEIGHT-001',
            'weight_grams' => 58.20,
            'size_class' => 'Medium',
            'recorded_at' => now()->subMinutes(5),
            'source_ip' => '127.0.0.1',
            'raw_payload_json' => '{}',
            'created_at' => now()->subMinutes(5),
        ]);

        $item = EggItem::query()->create([
            'farm_id' => $farm->id,
            'item_code' => 'WEIGHT-ITEM-001',
            'egg_type' => 'Chicken Egg',
            'size_class' => 'Medium',
            'unit_cost' => 7.50,
            'selling_price' => 8.50,
            'reorder_level' => 10,
            'current_stock' => 45,
        ]);

        $movement = StockMovement::query()->create([
            'item_id' => $item->id,
            'movement_type' => 'IN',
            'quantity' => 45,
            'stock_before' => 0,
            'stock_after' => 45,
            'unit_cost' => 7.50,
            'reference_no' => 'WEIGHT-REF-001',
            'notes' => 'Zero-weight manual stock import',
            'movement_date' => now()->toDateString(),
        ]);

        DB::table('egg_intake_records')->insert([
            'farm_id' => $farm->id,
            'item_id' => $item->id,
            'movement_id' => $movement->id,
            'source' => 'MANUAL',
            'egg_type' => 'Chicken Egg',
            'size_class' => 'Medium',
            'weight_grams' => 0,
            'quantity' => 45,
            'stock_before' => 0,
            'stock_after' => 45,
            'reference_no' => 'WEIGHT-REF-001',
            'notes' => 'Zero-weight manual stock import',
            'payload_json' => '{}',
            'created_by_user_id' => $owner->id,
            'recorded_at' => now()->subMinutes(5),
            'created_at' => now()->subMinutes(5),
        ]);

        $response = $this->actingAs($admin)->getJson(route('dashboard.data', [
            'range' => '1d',
            'context_farm_id' => $farm->id,
        ]));

        $response->assertOk();
        $response->assertJsonPath('summary.total_eggs', 46);

        $sizeBreakdown = collect($response->json('size_breakdown'));
        $medium = $sizeBreakdown->firstWhere('size_class', 'Medium');

        $this->assertNotNull($medium);
        $this->assertSame(46, $medium['count']);
        $this->assertSame(58.2, $medium['avg_weight']);

        $timelinePoint = collect($response->json('timeline'))
            ->firstWhere('eggs', 46);

        $this->assertNotNull($timelinePoint);
        $this->assertSame(58.2, $timelinePoint['avg_weight']);

        $activityBreakdown = collect($response->json('activity_breakdown'));
        $this->assertSame('active farm', $activityBreakdown->firstWhere('label', 'Farm Coverage')['total_label'] ?? null);
        $this->assertSame('active device', $activityBreakdown->firstWhere('label', 'Device Coverage')['total_label'] ?? null);
    }
}
