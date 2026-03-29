<?php

namespace Tests\Feature;

use App\Models\EggItem;
use App\Models\Farm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PriceMonitoringPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_and_customer_can_access_price_monitoring_page(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->customer()->create();
        $owner = User::factory()->owner()->create();

        $farm = $this->createFarm($owner, 'Monitor Farm');
        $this->createItem($farm, 'PRICE-MEDIUM', 'Medium', 8.75, 42);

        $adminResponse = $this->actingAs($admin)->get(route('price-monitoring.index'));
        $customerResponse = $this->actingAs($customer)->get(route('price-monitoring.index'));

        $adminResponse->assertOk()->assertSee('Price Monitoring')->assertSee('Size Class Price Comparison')->assertSee('Monitor Farm');
        $customerResponse->assertOk()->assertSee('Price Monitoring')->assertSee('Farm Price Comparison')->assertSee('Medium');
    }

    public function test_owner_and_staff_cannot_access_price_monitoring_page(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->staff()->create();

        $this->actingAs($owner)->get(route('price-monitoring.index'))->assertForbidden();
        $this->actingAs($staff)->get(route('price-monitoring.index'))->assertForbidden();
    }

    public function test_price_monitoring_can_filter_by_size_class_and_mark_lowest_price(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->owner()->create();

        $cheapFarm = $this->createFarm($owner, 'Cheap Farm');
        $premiumFarm = $this->createFarm($owner, 'Premium Farm');
        $largeOnlyFarm = $this->createFarm($owner, 'Large Only Farm');

        $this->createItem($cheapFarm, 'PRICE-MEDIUM-1', 'Medium', 8.25, 30);
        $this->createItem($premiumFarm, 'PRICE-MEDIUM-2', 'Medium', 9.10, 25);
        $this->createItem($largeOnlyFarm, 'PRICE-LARGE-1', 'Large', 10.00, 40);

        $response = $this->actingAs($admin)->get(route('price-monitoring.index', [
            'size_class' => 'Medium',
        ]));

        $response->assertOk();
        $response->assertSee('Farm Prices By Selected Weight Size');
        $response->assertSee('Cheap Farm');
        $response->assertSee('Premium Farm');
        $response->assertSee('Showing farms with');
        $response->assertSee('Medium');
        $response->assertSee('Lowest Price');
        $response->assertSee('PHP 8.25');
    }

    public function test_price_monitoring_shows_tray_equivalents_for_filtered_stock(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->owner()->create();

        $fullTrayFarm = $this->createFarm($owner, 'Full Tray Farm');
        $halfTrayFarm = $this->createFarm($owner, 'Half Tray Farm');

        $this->createItem($fullTrayFarm, 'PRICE-SMALL-1', 'Small', 7.80, 30);
        $this->createItem($halfTrayFarm, 'PRICE-SMALL-2', 'Small', 8.10, 15);

        $response = $this->actingAs($admin)->get(route('price-monitoring.index', [
            'size_class' => 'Small',
        ]));

        $response->assertOk();
        $response->assertSee('30 eggs');
        $response->assertSee('1 tray');
        $response->assertSee('15 eggs');
        $response->assertSee('1/2 tray');
    }

    private function createFarm(User $owner, string $farmName): Farm
    {
        return Farm::query()->create([
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
    }

    private function createItem(Farm $farm, string $itemCode, string $sizeClass, float $sellingPrice, int $stock): EggItem
    {
        return EggItem::query()->create([
            'farm_id' => $farm->id,
            'item_code' => $itemCode,
            'egg_type' => 'Chicken Egg',
            'size_class' => $sizeClass,
            'unit_cost' => 7.00,
            'selling_price' => $sellingPrice,
            'reorder_level' => 10,
            'current_stock' => $stock,
        ]);
    }
}
