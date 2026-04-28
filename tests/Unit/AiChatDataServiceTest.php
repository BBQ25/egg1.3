<?php

namespace Tests\Unit;

use App\Models\EggItem;
use App\Models\Farm;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\AiChat\AiChatDataService;
use App\Services\AiChat\InventoryForecastService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiChatDataServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_best_selling_proxy_uses_outbound_stock_movements(): void
    {
        $owner = User::factory()->owner()->create();
        $farm = $this->createFarm($owner, 'Sales Proxy Farm');
        $large = $this->createItem($farm, 'LARGE-PROXY', 'Large', 100);
        $medium = $this->createItem($farm, 'MEDIUM-PROXY', 'Medium', 100);

        $this->movement($large, 'OUT', 30, 100, 70);
        $this->movement($medium, 'OUT', 12, 100, 88);

        $service = app(AiChatDataService::class);
        $context = $service->build($owner, 'What are the best-selling eggs?');

        $this->assertSame('best_selling_eggs', $context['intent']);
        $this->assertSame('Large', $context['data']['rows'][0]['size_class']);
        $this->assertSame(30, $context['data']['rows'][0]['quantity_out']);
        $this->assertStringContainsString('OUT stock movements', $context['data']['basis']);
    }

    public function test_forecast_service_returns_five_ranked_algorithms_with_exact_metrics(): void
    {
        $owner = User::factory()->owner()->create();
        $farm = $this->createFarm($owner, 'Forecast Farm');
        $item = $this->createItem($farm, 'FORECAST-PROXY', 'Large', 200);

        for ($index = 20; $index >= 1; $index--) {
            StockMovement::query()->create([
                'item_id' => $item->id,
                'movement_type' => 'OUT',
                'quantity' => $index,
                'stock_before' => 200,
                'stock_after' => 200 - $index,
                'unit_cost' => 7.50,
                'reference_no' => 'FORECAST-' . $index,
                'movement_date' => now()->subDays($index)->toDateString(),
            ]);
        }

        $forecast = app(InventoryForecastService::class)->build([$farm->id], 'Predict inventory for the next week.');

        $this->assertSame('week', $forecast['horizon']['label']);
        $this->assertCount(5, $forecast['algorithms']);
        $this->assertFalse($forecast['insufficient_data']);
        $this->assertNotNull($forecast['algorithms'][0]['mae']);
        $this->assertArrayHasKey('projected_stock', $forecast['algorithms'][0]);
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

    private function createItem(Farm $farm, string $itemCode, string $sizeClass, int $stock): EggItem
    {
        return EggItem::query()->create([
            'farm_id' => $farm->id,
            'item_code' => $itemCode,
            'egg_type' => 'Chicken Egg',
            'size_class' => $sizeClass,
            'unit_cost' => 7.50,
            'selling_price' => 9.00,
            'reorder_level' => 20,
            'current_stock' => $stock,
        ]);
    }

    private function movement(EggItem $item, string $type, int $quantity, int $before, int $after): void
    {
        StockMovement::query()->create([
            'item_id' => $item->id,
            'movement_type' => $type,
            'quantity' => $quantity,
            'stock_before' => $before,
            'stock_after' => $after,
            'unit_cost' => 7.50,
            'reference_no' => 'MOVE-' . $item->item_code . '-' . $quantity,
            'movement_date' => now()->toDateString(),
        ]);
    }
}
