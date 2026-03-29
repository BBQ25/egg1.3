<?php

namespace Database\Seeders;

use App\Enums\UserRegistrationStatus;
use App\Enums\UserRole;
use App\Models\EggIntakeRecord;
use App\Models\EggItem;
use App\Models\Farm;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryDemoSeeder extends Seeder
{
    /**
     * @var array<int, array{code:string,size_class:string,quantity:int,unit_cost:string,selling_price:string,reorder_level:int,notes:string}>
     */
    private const INVENTORY_BLUEPRINT = [
        ['code' => 'PULLET', 'size_class' => 'Pullet', 'quantity' => 6, 'unit_cost' => '6.80', 'selling_price' => '7.80', 'reorder_level' => 4, 'notes' => 'Few pullet eggs for sample stock.'],
        ['code' => 'SMALL', 'size_class' => 'Small', 'quantity' => 27, 'unit_cost' => '7.20', 'selling_price' => '8.20', 'reorder_level' => 10, 'notes' => 'Small eggs fixed at 15% of the demo inventory.'],
        ['code' => 'MEDIUM', 'size_class' => 'Medium', 'quantity' => 54, 'unit_cost' => '7.80', 'selling_price' => '8.90', 'reorder_level' => 18, 'notes' => 'Medium eggs are part of the majority demo stock mix.'],
        ['code' => 'LARGE', 'size_class' => 'Large', 'quantity' => 45, 'unit_cost' => '8.40', 'selling_price' => '9.60', 'reorder_level' => 16, 'notes' => 'Large eggs are part of the majority demo stock mix.'],
        ['code' => 'XL', 'size_class' => 'Extra-Large', 'quantity' => 36, 'unit_cost' => '8.90', 'selling_price' => '10.20', 'reorder_level' => 14, 'notes' => 'Extra-large eggs are part of the majority demo stock mix.'],
        ['code' => 'JUMBO', 'size_class' => 'Jumbo', 'quantity' => 12, 'unit_cost' => '9.50', 'selling_price' => '10.90', 'reorder_level' => 6, 'notes' => 'Few jumbo eggs included for sample stock.'],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $farms = Farm::query()
                ->where('is_active', true)
                ->whereNotNull('owner_user_id')
                ->with('owner:id')
                ->orderBy('id')
                ->get();

            if ($farms->isEmpty()) {
                $farms = collect([$this->createDemoFarm()]);
            }

            $staffUsers = User::query()
                ->where('role', UserRole::WORKER)
                ->where('is_active', true)
                ->orderBy('id')
                ->get();

            if ($staffUsers->isEmpty()) {
                $staffUsers = collect([$this->createDemoStaff()]);
            }

            $this->ensureStaffAssignments($farms, $staffUsers);

            foreach ($farms as $farm) {
                $this->seedFarmInventory($farm);
            }
        });
    }

    private function createDemoFarm(): Farm
    {
        /** @var User $owner */
        $owner = User::factory()->owner()->create([
            'full_name' => 'Inventory Demo Owner',
            'first_name' => 'Inventory',
            'middle_name' => null,
            'last_name' => 'Owner',
            'address' => 'Bontoc, Southern Leyte',
            'username' => 'inventory-demo-owner',
            'password_hash' => 'password',
            'registration_status' => UserRegistrationStatus::APPROVED,
            'approved_at' => now(),
        ]);

        return Farm::query()->create([
            'farm_name' => 'Inventory Demo Farm',
            'location' => 'Poblacion',
            'sitio' => 'Central',
            'barangay' => 'Poblacion',
            'municipality' => 'Bontoc',
            'province' => 'Southern Leyte',
            'latitude' => null,
            'longitude' => null,
            'owner_user_id' => $owner->id,
            'is_active' => true,
        ]);
    }

    private function createDemoStaff(): User
    {
        return User::factory()->staff()->create([
            'full_name' => 'Inventory Demo Staff',
            'first_name' => 'Inventory',
            'middle_name' => null,
            'last_name' => 'Staff',
            'address' => 'Bontoc, Southern Leyte',
            'username' => 'inventory-demo-staff',
            'password_hash' => 'password',
            'registration_status' => UserRegistrationStatus::APPROVED,
            'approved_at' => now(),
        ]);
    }

    /**
     * @param Collection<int, Farm> $farms
     * @param Collection<int, User> $staffUsers
     */
    private function ensureStaffAssignments(Collection $farms, Collection $staffUsers): void
    {
        if ($staffUsers->isEmpty()) {
            return;
        }

        foreach ($farms->values() as $index => $farm) {
            if ($farm->staffUsers()->exists()) {
                continue;
            }

            /** @var User $staff */
            $staff = $staffUsers[$index % $staffUsers->count()];
            $farm->staffUsers()->syncWithoutDetaching([$staff->id]);
        }
    }

    private function seedFarmInventory(Farm $farm): void
    {
        $referencePrefix = 'DEMO-OPEN-' . $farm->id . '-';
        $activeCodes = [];

        foreach (self::INVENTORY_BLUEPRINT as $entry) {
            $itemCode = 'DEMO-' . $entry['code'];
            $activeCodes[] = $itemCode;

            /** @var EggItem $item */
            $item = EggItem::query()->updateOrCreate(
                [
                    'farm_id' => $farm->id,
                    'item_code' => $itemCode,
                ],
                [
                    'egg_type' => 'Chicken Egg',
                    'size_class' => $entry['size_class'],
                    'unit_cost' => $entry['unit_cost'],
                    'selling_price' => $entry['selling_price'],
                    'reorder_level' => $entry['reorder_level'],
                    'current_stock' => $entry['quantity'],
                ]
            );

            /** @var StockMovement $movement */
            $movement = StockMovement::query()->updateOrCreate(
                [
                    'item_id' => $item->id,
                    'reference_no' => $referencePrefix . $entry['code'],
                ],
                [
                    'movement_type' => 'IN',
                    'quantity' => $entry['quantity'],
                    'stock_before' => 0,
                    'stock_after' => $entry['quantity'],
                    'unit_cost' => $entry['unit_cost'],
                    'notes' => 'Demo opening balance seeded for inventory preview.',
                    'movement_date' => now()->toDateString(),
                ]
            );

            EggIntakeRecord::query()->updateOrCreate(
                [
                    'movement_id' => $movement->id,
                ],
                [
                    'farm_id' => $farm->id,
                    'item_id' => $item->id,
                    'source' => 'MANUAL',
                    'egg_type' => 'Chicken Egg',
                    'size_class' => $entry['size_class'],
                    'weight_grams' => 0,
                    'quantity' => $entry['quantity'],
                    'stock_before' => 0,
                    'stock_after' => $entry['quantity'],
                    'reference_no' => $movement->reference_no,
                    'notes' => $entry['notes'],
                    'payload_json' => json_encode([
                        'seeded_by' => static::class,
                        'distribution_rule' => 'max_180_no_pewee_small_15_percent',
                    ], JSON_UNESCAPED_SLASHES),
                    'created_by_user_id' => $farm->owner_user_id,
                    'recorded_at' => now(),
                ]
            );
        }

        EggItem::query()
            ->where('farm_id', $farm->id)
            ->where('item_code', 'like', 'DEMO-%')
            ->whereNotIn('item_code', $activeCodes)
            ->delete();

        if ($this->command) {
            $this->command->info(sprintf(
                'Seeded %d demo eggs across %d inventory SKUs for farm #%d %s.',
                array_sum(array_column(self::INVENTORY_BLUEPRINT, 'quantity')),
                count(self::INVENTORY_BLUEPRINT),
                $farm->id,
                $farm->farm_name
            ));
        }
    }
}
