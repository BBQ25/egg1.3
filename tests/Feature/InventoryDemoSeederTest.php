<?php

namespace Tests\Feature;

use App\Models\EggItem;
use Database\Seeders\InventoryDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_demo_seeder_creates_expected_distribution(): void
    {
        $this->seed(InventoryDemoSeeder::class);

        $this->assertSame(6, EggItem::query()->count());
        $this->assertSame(180, (int) EggItem::query()->sum('current_stock'));
        $this->assertSame(0, EggItem::query()->where('size_class', 'Peewee')->count());
        $this->assertSame(6, (int) EggItem::query()->where('size_class', 'Pullet')->value('current_stock'));
        $this->assertSame(27, (int) EggItem::query()->where('size_class', 'Small')->value('current_stock'));
        $this->assertSame(54, (int) EggItem::query()->where('size_class', 'Medium')->value('current_stock'));
        $this->assertSame(45, (int) EggItem::query()->where('size_class', 'Large')->value('current_stock'));
        $this->assertSame(36, (int) EggItem::query()->where('size_class', 'Extra-Large')->value('current_stock'));
        $this->assertSame(12, (int) EggItem::query()->where('size_class', 'Jumbo')->value('current_stock'));
        $this->assertDatabaseCount('stock_movements', 6);
        $this->assertDatabaseCount('egg_intake_records', 6);
        $this->assertDatabaseHas('farm_staff_assignments', ['farm_id' => 1, 'user_id' => 2]);
    }
}
