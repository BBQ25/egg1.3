<?php

namespace Tests\Feature;

use App\Models\EggItem;
use App\Models\Farm;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventoryPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_access_inventory_and_only_see_owned_farm_items(): void
    {
        $owner = User::factory()->owner()->create();
        $otherOwner = User::factory()->owner()->create();

        $ownerFarm = $this->createFarm($owner, 'Owner Farm');
        $otherFarm = $this->createFarm($otherOwner, 'Other Farm');

        $visibleItem = $this->createItem($ownerFarm, 'INV-OWNER');
        $this->createItem($otherFarm, 'INV-OTHER');

        $response = $this->actingAs($owner)->get(route('inventory.index'));

        $response->assertOk();
        $response->assertSee('Inventory');
        $response->assertSee($visibleItem->item_code);
        $response->assertSee($ownerFarm->farm_name);
        $response->assertDontSee('INV-OTHER');
        $response->assertDontSee($otherFarm->farm_name);
    }

    public function test_staff_can_access_inventory_and_only_see_assigned_farm_items(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->staff()->create();

        $assignedFarm = $this->createFarm($owner, 'Assigned Farm');
        $foreignFarm = $this->createFarm($owner, 'Foreign Farm');

        DB::table('farm_staff_assignments')->insert([
            'farm_id' => $assignedFarm->id,
            'user_id' => $staff->id,
            'created_at' => now(),
        ]);

        $visibleItem = $this->createItem($assignedFarm, 'INV-STAFF');
        $this->createItem($foreignFarm, 'INV-HIDDEN');

        $response = $this->actingAs($staff)->get(route('inventory.index'));

        $response->assertOk();
        $response->assertSee($visibleItem->item_code);
        $response->assertSee($assignedFarm->farm_name);
        $response->assertDontSee('INV-HIDDEN');
        $response->assertDontSee($foreignFarm->farm_name);
    }

    public function test_admin_and_customer_cannot_access_inventory_page(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->customer()->create();

        $this->actingAs($admin)->get(route('inventory.index'))->assertForbidden();
        $this->actingAs($customer)->get(route('inventory.index'))->assertForbidden();
    }

    public function test_owner_can_record_stock_in_movement(): void
    {
        $owner = User::factory()->owner()->create();
        $farm = $this->createFarm($owner, 'Owner Farm');
        $item = $this->createItem($farm, 'INV-STOCK-IN');

        $response = $this->actingAs($owner)->post(route('inventory.movements.store'), [
            'item_id' => $item->id,
            'movement_type' => 'IN',
            'quantity' => 15,
            'unit_cost' => 8.25,
            'reference_no' => 'REF-IN-001',
            'notes' => 'Additional counted stock',
            'movement_date' => now()->toDateString(),
        ]);

        $response->assertRedirect(route('inventory.index'));
        $response->assertSessionHas('status');

        $this->assertDatabaseHas('stock_movements', [
            'item_id' => $item->id,
            'movement_type' => 'IN',
            'quantity' => 15,
            'stock_before' => 40,
            'stock_after' => 55,
            'reference_no' => 'REF-IN-001',
        ]);

        $this->assertDatabaseHas('egg_items', [
            'id' => $item->id,
            'current_stock' => 55,
        ]);

        $this->assertDatabaseHas('egg_intake_records', [
            'item_id' => $item->id,
            'movement_id' => 2,
            'source' => 'MANUAL',
            'quantity' => 15,
            'stock_before' => 40,
            'stock_after' => 55,
            'created_by_user_id' => $owner->id,
        ]);
    }

    public function test_owner_can_record_stock_in_using_tray_breakdown(): void
    {
        $owner = User::factory()->owner()->create();
        $farm = $this->createFarm($owner, 'Owner Farm');
        $item = $this->createItem($farm, 'INV-TRAY-IN');

        $response = $this->actingAs($owner)->post(route('inventory.movements.store'), [
            'item_id' => $item->id,
            'movement_type' => 'IN',
            'quantity_entry_mode' => 'TRAYS',
            'quantity_full_trays' => 1,
            'quantity_half_trays' => 1,
            'quantity_loose_eggs' => 0,
            'unit_cost' => 8.25,
            'reference_no' => 'REF-TRAY-IN-001',
            'notes' => 'Tray-based stock entry',
            'movement_date' => now()->toDateString(),
        ]);

        $response->assertRedirect(route('inventory.index'));

        $this->assertDatabaseHas('stock_movements', [
            'item_id' => $item->id,
            'movement_type' => 'IN',
            'quantity' => 45,
            'stock_before' => 40,
            'stock_after' => 85,
            'reference_no' => 'REF-TRAY-IN-001',
        ]);
    }

    public function test_owner_cannot_record_stock_out_beyond_available_stock(): void
    {
        $owner = User::factory()->owner()->create();
        $farm = $this->createFarm($owner, 'Owner Farm');
        $item = $this->createItem($farm, 'INV-STOCK-OUT');

        $response = $this->actingAs($owner)
            ->from(route('inventory.index'))
            ->post(route('inventory.movements.store'), [
                'item_id' => $item->id,
                'movement_type' => 'OUT',
                'quantity' => 99,
                'reference_no' => 'REF-OUT-001',
                'movement_date' => now()->toDateString(),
            ]);

        $response->assertRedirect(route('inventory.index'));
        $response->assertSessionHasErrors('quantity');

        $this->assertDatabaseMissing('stock_movements', [
            'reference_no' => 'REF-OUT-001',
        ]);

        $this->assertDatabaseHas('egg_items', [
            'id' => $item->id,
            'current_stock' => 40,
        ]);
    }

    public function test_staff_can_record_adjustment_only_for_assigned_farm_item(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->staff()->create();

        $assignedFarm = $this->createFarm($owner, 'Assigned Farm');
        $foreignFarm = $this->createFarm($owner, 'Foreign Farm');

        DB::table('farm_staff_assignments')->insert([
            'farm_id' => $assignedFarm->id,
            'user_id' => $staff->id,
            'created_at' => now(),
        ]);

        $assignedItem = $this->createItem($assignedFarm, 'INV-ADJ-OK');
        $foreignItem = $this->createItem($foreignFarm, 'INV-ADJ-NO');

        $okResponse = $this->actingAs($staff)->post(route('inventory.movements.store'), [
            'item_id' => $assignedItem->id,
            'movement_type' => 'ADJUSTMENT',
            'adjustment_direction' => 'DECREASE',
            'quantity' => 5,
            'reference_no' => 'REF-ADJ-001',
            'movement_date' => now()->toDateString(),
        ]);

        $okResponse->assertRedirect(route('inventory.index'));

        $this->assertDatabaseHas('egg_items', [
            'id' => $assignedItem->id,
            'current_stock' => 35,
        ]);

        $forbiddenScopeResponse = $this->actingAs($staff)
            ->from(route('inventory.index'))
            ->post(route('inventory.movements.store'), [
                'item_id' => $foreignItem->id,
                'movement_type' => 'ADJUSTMENT',
                'adjustment_direction' => 'INCREASE',
                'quantity' => 3,
                'reference_no' => 'REF-ADJ-002',
                'movement_date' => now()->toDateString(),
            ]);

        $forbiddenScopeResponse->assertRedirect(route('inventory.index'));
        $forbiddenScopeResponse->assertSessionHasErrors('item_id');

        $this->assertDatabaseHas('egg_items', [
            'id' => $foreignItem->id,
            'current_stock' => 40,
        ]);
    }

    public function test_owner_can_create_inventory_item_with_opening_stock(): void
    {
        $owner = User::factory()->owner()->create();
        $farm = $this->createFarm($owner, 'Owner Farm');

        $response = $this->actingAs($owner)->post(route('inventory.items.store'), [
            'inventory_form_mode' => 'create-item',
            'farm_id' => $farm->id,
            'item_code' => 'INV-NEW-001',
            'egg_type' => 'Duck Egg',
            'size_class' => 'Medium',
            'unit_cost' => 6.50,
            'selling_price' => 8.75,
            'reorder_level' => 20,
            'opening_stock' => 12,
            'opening_reference_no' => 'OPEN-001',
            'opening_notes' => 'Opening inventory balance',
        ]);

        $response->assertRedirect(route('inventory.index'));
        $response->assertSessionHas('status');

        $this->assertDatabaseHas('egg_items', [
            'farm_id' => $farm->id,
            'item_code' => 'INV-NEW-001',
            'egg_type' => 'Duck Egg',
            'size_class' => 'Medium',
            'reorder_level' => 20,
            'current_stock' => 12,
        ]);

        $item = EggItem::query()->where('item_code', 'INV-NEW-001')->firstOrFail();

        $this->assertDatabaseHas('stock_movements', [
            'item_id' => $item->id,
            'movement_type' => 'IN',
            'quantity' => 12,
            'stock_before' => 0,
            'stock_after' => 12,
            'reference_no' => 'OPEN-001',
        ]);
    }

    public function test_owner_can_create_inventory_item_with_tray_based_opening_stock(): void
    {
        $owner = User::factory()->owner()->create();
        $farm = $this->createFarm($owner, 'Owner Farm');

        $response = $this->actingAs($owner)->post(route('inventory.items.store'), [
            'inventory_form_mode' => 'create-item',
            'farm_id' => $farm->id,
            'item_code' => 'INV-TRAY-OPEN-001',
            'egg_type' => 'Chicken Egg',
            'size_class' => 'Large',
            'unit_cost' => 6.50,
            'selling_price' => 8.75,
            'reorder_level' => 20,
            'opening_stock_entry_mode' => 'TRAYS',
            'opening_full_trays' => 1,
            'opening_half_trays' => 1,
            'opening_loose_eggs' => 0,
            'opening_reference_no' => 'OPEN-TRAY-001',
            'opening_notes' => 'Opening inventory from trays',
        ]);

        $response->assertRedirect(route('inventory.index'));

        $this->assertDatabaseHas('egg_items', [
            'farm_id' => $farm->id,
            'item_code' => 'INV-TRAY-OPEN-001',
            'current_stock' => 45,
        ]);

        $item = EggItem::query()->where('item_code', 'INV-TRAY-OPEN-001')->firstOrFail();

        $this->assertDatabaseHas('stock_movements', [
            'item_id' => $item->id,
            'movement_type' => 'IN',
            'quantity' => 45,
            'stock_before' => 0,
            'stock_after' => 45,
            'reference_no' => 'OPEN-TRAY-001',
        ]);
    }

    public function test_staff_cannot_create_inventory_item_for_unassigned_farm(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->staff()->create();
        $foreignFarm = $this->createFarm($owner, 'Foreign Farm');

        $response = $this->actingAs($staff)
            ->from(route('inventory.index'))
            ->post(route('inventory.items.store'), [
                'inventory_form_mode' => 'create-item',
                'farm_id' => $foreignFarm->id,
                'item_code' => 'INV-FORBIDDEN',
                'egg_type' => 'Chicken Egg',
                'size_class' => 'Large',
                'unit_cost' => 7.50,
                'selling_price' => 9.00,
                'reorder_level' => 10,
            ]);

        $response->assertRedirect(route('inventory.index'));
        $response->assertSessionHasErrors('farm_id');

        $this->assertDatabaseMissing('egg_items', [
            'item_code' => 'INV-FORBIDDEN',
        ]);
    }

    public function test_owner_can_update_inventory_item_pricing_and_reorder_level(): void
    {
        $owner = User::factory()->owner()->create();
        $farm = $this->createFarm($owner, 'Owner Farm');
        $item = $this->createItem($farm, 'INV-EDIT-001');

        $response = $this->actingAs($owner)->put(route('inventory.items.update', $item), [
            'inventory_form_mode' => 'edit-item',
            'item_id' => $item->id,
            'item_code' => 'INV-EDIT-001A',
            'egg_type' => 'Premium Chicken Egg',
            'size_class' => 'Extra-Large',
            'unit_cost' => 8.10,
            'selling_price' => 10.25,
            'reorder_level' => 18,
        ]);

        $response->assertRedirect(route('inventory.index'));
        $response->assertSessionHas('status');

        $this->assertDatabaseHas('egg_items', [
            'id' => $item->id,
            'item_code' => 'INV-EDIT-001A',
            'egg_type' => 'Premium Chicken Egg',
            'size_class' => 'Extra-Large',
            'unit_cost' => 8.10,
            'selling_price' => 10.25,
            'reorder_level' => 18,
            'current_stock' => 40,
        ]);
    }

    public function test_owner_can_set_prices_by_size_class_for_owned_farm(): void
    {
        $owner = User::factory()->owner()->create();
        $farm = $this->createFarm($owner, 'Owner Farm');

        EggItem::query()->create([
            'farm_id' => $farm->id,
            'item_code' => 'INV-MEDIUM-001',
            'egg_type' => 'Chicken Egg',
            'size_class' => 'Medium',
            'unit_cost' => 7.10,
            'selling_price' => 8.50,
            'reorder_level' => 20,
            'current_stock' => 30,
        ]);

        EggItem::query()->create([
            'farm_id' => $farm->id,
            'item_code' => 'INV-LARGE-001',
            'egg_type' => 'Chicken Egg',
            'size_class' => 'Large',
            'unit_cost' => 7.90,
            'selling_price' => 9.20,
            'reorder_level' => 20,
            'current_stock' => 35,
        ]);

        $response = $this->actingAs($owner)->post(route('inventory.pricing.update'), [
            'farm_id' => $farm->id,
            'price_matrix' => [
                'medium' => 9.75,
                'large' => 10.50,
            ],
        ]);

        $response->assertRedirect(route('inventory.index'));
        $response->assertSessionHas('status');

        $this->assertDatabaseHas('egg_items', [
            'farm_id' => $farm->id,
            'item_code' => 'INV-MEDIUM-001',
            'selling_price' => 9.75,
        ]);

        $this->assertDatabaseHas('egg_items', [
            'farm_id' => $farm->id,
            'item_code' => 'INV-LARGE-001',
            'selling_price' => 10.50,
        ]);
    }

    public function test_staff_cannot_use_owner_price_matrix_update(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->staff()->create();
        $farm = $this->createFarm($owner, 'Owner Farm');

        DB::table('farm_staff_assignments')->insert([
            'farm_id' => $farm->id,
            'user_id' => $staff->id,
            'created_at' => now(),
        ]);

        $response = $this->actingAs($staff)->post(route('inventory.pricing.update'), [
            'farm_id' => $farm->id,
            'price_matrix' => [
                'medium' => 9.75,
            ],
        ]);

        $response->assertForbidden();
    }

    public function test_inventory_page_shows_tray_equivalents_for_stock_counts(): void
    {
        $owner = User::factory()->owner()->create();
        $farm = $this->createFarm($owner, 'Tray Farm');

        EggItem::query()->create([
            'farm_id' => $farm->id,
            'item_code' => 'INV-TRAY-001',
            'egg_type' => 'Chicken Egg',
            'size_class' => 'Medium',
            'unit_cost' => 7.50,
            'selling_price' => 8.50,
            'reorder_level' => 10,
            'current_stock' => 30,
        ]);

        EggItem::query()->create([
            'farm_id' => $farm->id,
            'item_code' => 'INV-TRAY-002',
            'egg_type' => 'Chicken Egg',
            'size_class' => 'Small',
            'unit_cost' => 7.00,
            'selling_price' => 8.00,
            'reorder_level' => 10,
            'current_stock' => 15,
        ]);

        $response = $this->actingAs($owner)->get(route('inventory.index'));

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

    private function createItem(Farm $farm, string $itemCode): EggItem
    {
        $item = EggItem::query()->create([
            'farm_id' => $farm->id,
            'item_code' => $itemCode,
            'egg_type' => 'Chicken Egg',
            'size_class' => 'Large',
            'unit_cost' => 7.50,
            'selling_price' => 9.00,
            'reorder_level' => 25,
            'current_stock' => 40,
        ]);

        StockMovement::query()->create([
            'item_id' => $item->id,
            'movement_type' => 'IN',
            'quantity' => 40,
            'stock_before' => 0,
            'stock_after' => 40,
            'unit_cost' => 7.50,
            'reference_no' => 'REF-' . $itemCode,
            'notes' => 'Initial stock',
            'movement_date' => now()->toDateString(),
        ]);

        return $item;
    }
}
