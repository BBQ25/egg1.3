<?php

namespace App\Http\Controllers;

use App\Models\EggIntakeRecord;
use App\Models\EggItem;
use App\Models\Farm;
use App\Models\StockMovement;
use App\Support\EggTrayFormatter;
use App\Support\EggWeightRanges;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class InventoryController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user && ($user->isOwner() || $user->isStaff()), 403);

        $farmIds = $this->resolveScopedFarmIds($request);
        $search = trim((string) $request->query('q', ''));
        $scopeLabel = $user->isOwner() ? 'Owner inventory scope' : 'Staff inventory scope';
        $weightRanges = EggWeightRanges::current();

        $farms = Farm::query()
            ->whereIn('id', $farmIds)
            ->withCount('eggItems')
            ->withSum('eggItems as farm_stock_total', 'current_stock')
            ->orderBy('farm_name')
            ->get(['id', 'farm_name', 'owner_user_id']);

        $inventoryBaseQuery = EggItem::query()
            ->with(['farm:id,farm_name'])
            ->whereIn('farm_id', $farmIds);

        if ($search !== '') {
            $inventoryBaseQuery->where(function ($query) use ($search): void {
                $query->where('item_code', 'like', "%{$search}%")
                    ->orWhere('egg_type', 'like', "%{$search}%")
                    ->orWhere('size_class', 'like', "%{$search}%")
                    ->orWhereHas('farm', function ($farmQuery) use ($search): void {
                        $farmQuery->where('farm_name', 'like', "%{$search}%");
                    });
            });
        }

        $items = (clone $inventoryBaseQuery)
            ->orderBy('farm_id')
            ->orderBy('size_class')
            ->orderBy('egg_type')
            ->paginate(12)
            ->withQueryString();

        $movementItems = EggItem::query()
            ->with(['farm:id,farm_name'])
            ->whereIn('farm_id', $farmIds)
            ->orderBy('farm_id')
            ->orderBy('size_class')
            ->orderBy('egg_type')
            ->get([
                'id',
                'farm_id',
                'item_code',
                'egg_type',
                'size_class',
                'unit_cost',
                'selling_price',
                'current_stock',
                'reorder_level',
            ]);

        $inventoryStats = (clone $inventoryBaseQuery)
            ->selectRaw('COUNT(*) as sku_total')
            ->selectRaw('COALESCE(SUM(current_stock), 0) as stock_total')
            ->selectRaw('SUM(CASE WHEN current_stock <= reorder_level THEN 1 ELSE 0 END) as low_stock_total')
            ->selectRaw('COALESCE(SUM(current_stock * COALESCE(NULLIF(selling_price, 0), unit_cost)), 0) as inventory_value')
            ->first();

        $recentMovements = StockMovement::query()
            ->with(['item:id,farm_id,item_code,egg_type,size_class', 'item.farm:id,farm_name'])
            ->whereHas('item', function ($query) use ($farmIds): void {
                $query->whereIn('farm_id', $farmIds);
            })
            ->orderByDesc('movement_date')
            ->orderByDesc('id')
            ->limit(8)
            ->get();

        $movementTotals = StockMovement::query()
            ->whereHas('item', function ($query) use ($farmIds): void {
                $query->whereIn('farm_id', $farmIds);
            })
            ->select('movement_type', DB::raw('COUNT(*) as movement_count'))
            ->groupBy('movement_type')
            ->pluck('movement_count', 'movement_type');

        $priceMatrix = $user->isOwner()
            ? $this->buildPriceMatrix($farmIds, $weightRanges)
            : collect();

        return view('inventory.index', [
            'farms' => $farms,
            'items' => $items,
            'movementItems' => $movementItems,
            'recentMovements' => $recentMovements,
            'search' => $search,
            'scopeLabel' => $scopeLabel,
            'weightRanges' => $weightRanges,
            'priceMatrix' => $priceMatrix,
            'openItemModal' => in_array((string) old('inventory_form_mode'), ['create-item', 'edit-item'], true),
            'openMovementModal' => old('item_id') !== null,
            'inventoryStats' => [
                'sku_total' => (int) ($inventoryStats->sku_total ?? 0),
                'stock_total' => (int) ($inventoryStats->stock_total ?? 0),
                'low_stock_total' => (int) ($inventoryStats->low_stock_total ?? 0),
                'inventory_value' => round((float) ($inventoryStats->inventory_value ?? 0), 2),
                'movement_in_total' => (int) ($movementTotals['IN'] ?? 0),
                'movement_out_total' => (int) ($movementTotals['OUT'] ?? 0),
                'movement_adjustment_total' => (int) ($movementTotals['ADJUSTMENT'] ?? 0),
            ],
        ]);
    }

    public function updatePricing(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user && $user->isOwner(), 403);

        $farmIds = $this->resolveScopedFarmIds($request);
        $weightRanges = EggWeightRanges::current();

        $rules = [
            'farm_id' => ['required', 'integer'],
        ];

        foreach ($weightRanges as $slug => $definition) {
            $rules["price_matrix.{$slug}"] = ['nullable', 'numeric', 'min:0'];
        }

        $validator = Validator::make($request->all(), $rules);

        $validator->after(function ($validator) use ($farmIds, $request, $weightRanges): void {
            $farmId = (int) $request->input('farm_id');

            if (!in_array($farmId, $farmIds, true)) {
                $validator->errors()->add('farm_id', 'Selected farm is outside your inventory scope.');
            }

            $hasValue = false;
            foreach (array_keys($weightRanges) as $slug) {
                if ($request->filled("price_matrix.{$slug}")) {
                    $hasValue = true;
                    break;
                }
            }

            if (!$hasValue) {
                $validator->errors()->add('price_matrix', 'Enter at least one selling price to update.');
            }
        });

        if ($validator->fails()) {
            return redirect()
                ->route('inventory.index')
                ->withErrors($validator->errors())
                ->withInput();
        }

        $validated = $validator->validated();
        $farmId = (int) $validated['farm_id'];
        $updatedClasses = [];
        $updatedItems = 0;

        DB::transaction(function () use ($validated, $weightRanges, $farmId, &$updatedClasses, &$updatedItems): void {
            foreach ($weightRanges as $slug => $definition) {
                $rawPrice = data_get($validated, "price_matrix.{$slug}");
                if ($rawPrice === null || $rawPrice === '') {
                    continue;
                }

                $price = round((float) $rawPrice, 2);
                $affected = EggItem::query()
                    ->where('farm_id', $farmId)
                    ->where('size_class', $definition['class'])
                    ->update(['selling_price' => $price]);

                if ($affected > 0) {
                    $updatedClasses[] = $definition['label'];
                    $updatedItems += $affected;
                }
            }
        });

        if ($updatedItems === 0) {
            return redirect()
                ->route('inventory.index')
                ->with('status', 'No inventory items matched the selected farm and size classes.');
        }

        return redirect()
            ->route('inventory.index')
            ->with('status', 'Selling prices updated for ' . implode(', ', $updatedClasses) . '.');
    }

    public function storeItem(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user && ($user->isOwner() || $user->isStaff()), 403);

        $farmIds = $this->resolveScopedFarmIds($request);

        $validator = Validator::make($request->all(), [
            'inventory_form_mode' => ['nullable', 'string', Rule::in(['create-item'])],
            'farm_id' => ['required', 'integer'],
            'item_code' => ['required', 'string', 'max:40'],
            'egg_type' => ['required', 'string', 'max:80'],
            'size_class' => ['required', 'string', Rule::in(['Reject', 'Peewee', 'Pullet', 'Small', 'Medium', 'Large', 'Extra-Large', 'Jumbo'])],
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'reorder_level' => ['required', 'integer', 'min:0'],
            'opening_stock_entry_mode' => ['nullable', 'string', Rule::in(['EGGS', 'TRAYS'])],
            'opening_stock' => ['nullable', 'integer', 'min:0'],
            'opening_full_trays' => ['nullable', 'integer', 'min:0'],
            'opening_half_trays' => ['nullable', 'integer', 'min:0'],
            'opening_loose_eggs' => ['nullable', 'integer', 'min:0'],
            'opening_reference_no' => ['nullable', 'string', 'max:80'],
            'opening_notes' => ['nullable', 'string', 'max:255'],
        ]);

        $validator->after(function ($validator) use ($farmIds, $request): void {
            $farmId = (int) $request->input('farm_id');
            $itemCode = trim((string) $request->input('item_code'));

            if (!in_array($farmId, $farmIds, true)) {
                $validator->errors()->add('farm_id', 'Selected farm is outside your inventory scope.');
                return;
            }

            $codeExists = EggItem::query()
                ->where('farm_id', $farmId)
                ->whereRaw('LOWER(item_code) = ?', [mb_strtolower($itemCode)])
                ->exists();

            if ($codeExists) {
                $validator->errors()->add('item_code', 'Item code already exists for the selected farm.');
            }
        });

        if ($validator->fails()) {
            return redirect()
                ->route('inventory.index')
                ->withErrors($validator->errors())
                ->withInput();
        }

        $validated = $validator->validated();
        $openingStock = $this->resolveEggCountFromPayload(
            $validated,
            'opening_stock_entry_mode',
            'opening_stock',
            'opening'
        );

        DB::transaction(function () use ($validated, $user, $openingStock): void {
            $itemCode = trim((string) $validated['item_code']);

            /** @var EggItem $item */
            $item = EggItem::query()->create([
                'farm_id' => (int) $validated['farm_id'],
                'item_code' => $itemCode,
                'egg_type' => trim((string) $validated['egg_type']),
                'size_class' => (string) $validated['size_class'],
                'unit_cost' => round((float) $validated['unit_cost'], 2),
                'selling_price' => round((float) $validated['selling_price'], 2),
                'reorder_level' => (int) $validated['reorder_level'],
                'current_stock' => $openingStock,
            ]);

            if ($openingStock > 0) {
                $referenceNo = filled($validated['opening_reference_no'] ?? null)
                    ? trim((string) $validated['opening_reference_no'])
                    : 'OPEN-' . $itemCode . '-' . now()->format('YmdHis');

                $movement = StockMovement::query()->create([
                    'item_id' => (int) $item->id,
                    'movement_type' => 'IN',
                    'quantity' => $openingStock,
                    'stock_before' => 0,
                    'stock_after' => $openingStock,
                    'unit_cost' => round((float) $validated['unit_cost'], 2),
                    'reference_no' => $referenceNo,
                    'notes' => filled($validated['opening_notes'] ?? null)
                        ? trim((string) $validated['opening_notes'])
                        : 'Opening stock created from item maintenance.',
                    'movement_date' => now()->toDateString(),
                ]);

                EggIntakeRecord::query()->create([
                    'farm_id' => (int) $item->farm_id,
                    'item_id' => (int) $item->id,
                    'movement_id' => (int) $movement->id,
                    'source' => 'MANUAL',
                    'egg_type' => (string) $item->egg_type,
                    'size_class' => (string) $item->size_class,
                    'weight_grams' => 0,
                    'quantity' => $openingStock,
                    'stock_before' => 0,
                    'stock_after' => $openingStock,
                    'reference_no' => $referenceNo,
                    'notes' => filled($validated['opening_notes'] ?? null)
                        ? trim((string) $validated['opening_notes'])
                        : 'Opening stock created from item maintenance.',
                    'payload_json' => json_encode([
                        'movement_type' => 'IN',
                        'recorded_via' => 'inventory_item_create',
                    ], JSON_THROW_ON_ERROR),
                    'created_by_user_id' => (int) $user->id,
                    'recorded_at' => now(),
                ]);
            }
        });

        return redirect()
            ->route('inventory.index')
            ->with('status', 'Inventory item created successfully.');
    }

    public function updateItem(Request $request, EggItem $item): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user && ($user->isOwner() || $user->isStaff()), 403);

        $farmIds = $this->resolveScopedFarmIds($request);
        abort_unless(in_array((int) $item->farm_id, $farmIds, true), 403);

        $validator = Validator::make($request->all(), [
            'inventory_form_mode' => ['nullable', 'string', Rule::in(['edit-item'])],
            'item_id' => ['nullable', 'integer'],
            'item_code' => ['required', 'string', 'max:40'],
            'egg_type' => ['required', 'string', 'max:80'],
            'size_class' => ['required', 'string', Rule::in(['Reject', 'Peewee', 'Pullet', 'Small', 'Medium', 'Large', 'Extra-Large', 'Jumbo'])],
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'reorder_level' => ['required', 'integer', 'min:0'],
        ]);

        $validator->after(function ($validator) use ($item, $request): void {
            $itemCode = trim((string) $request->input('item_code'));

            $codeExists = EggItem::query()
                ->where('farm_id', (int) $item->farm_id)
                ->whereKeyNot((int) $item->id)
                ->whereRaw('LOWER(item_code) = ?', [mb_strtolower($itemCode)])
                ->exists();

            if ($codeExists) {
                $validator->errors()->add('item_code', 'Item code already exists for this farm.');
            }
        });

        if ($validator->fails()) {
            return redirect()
                ->route('inventory.index')
                ->withErrors($validator->errors())
                ->withInput(array_merge($request->all(), [
                    'inventory_form_mode' => 'edit-item',
                    'item_id' => $item->id,
                ]));
        }

        $validated = $validator->validated();

        $item->update([
            'item_code' => trim((string) $validated['item_code']),
            'egg_type' => trim((string) $validated['egg_type']),
            'size_class' => (string) $validated['size_class'],
            'unit_cost' => round((float) $validated['unit_cost'], 2),
            'selling_price' => round((float) $validated['selling_price'], 2),
            'reorder_level' => (int) $validated['reorder_level'],
        ]);

        return redirect()
            ->route('inventory.index')
            ->with('status', 'Inventory item updated successfully.');
    }

    public function storeMovement(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user && ($user->isOwner() || $user->isStaff()), 403);

        $farmIds = $this->resolveScopedFarmIds($request);

        $validator = Validator::make($request->all(), [
            'item_id' => ['required', 'integer'],
            'movement_type' => ['required', 'string', Rule::in(['IN', 'OUT', 'ADJUSTMENT'])],
            'quantity_entry_mode' => ['nullable', 'string', Rule::in(['EGGS', 'TRAYS'])],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'quantity_full_trays' => ['nullable', 'integer', 'min:0'],
            'quantity_half_trays' => ['nullable', 'integer', 'min:0'],
            'quantity_loose_eggs' => ['nullable', 'integer', 'min:0'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'reference_no' => ['required', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:255'],
            'movement_date' => ['required', 'date'],
            'adjustment_direction' => ['nullable', 'string', Rule::in(['INCREASE', 'DECREASE'])],
        ]);

        $validator->after(function ($validator) use ($farmIds, $request): void {
            $itemId = (int) $request->input('item_id');
            $movementType = (string) $request->input('movement_type');
            $quantity = $this->resolveEggCountFromPayload(
                $request->all(),
                'quantity_entry_mode',
                'quantity',
                'quantity'
            );

            if ($quantity < 1) {
                $validator->errors()->add('quantity', 'Enter at least one egg or tray equivalent for this movement.');
            }

            $item = EggItem::query()
                ->whereIn('farm_id', $farmIds)
                ->find($itemId, ['id', 'farm_id', 'current_stock']);

            if (!$item) {
                $validator->errors()->add('item_id', 'Selected inventory item is outside your farm scope.');
                return;
            }

            if ($movementType === 'ADJUSTMENT' && !in_array((string) $request->input('adjustment_direction'), ['INCREASE', 'DECREASE'], true)) {
                $validator->errors()->add('adjustment_direction', 'Adjustment direction is required for stock adjustments.');
            }

            $willDecrease = $movementType === 'OUT'
                || ($movementType === 'ADJUSTMENT' && (string) $request->input('adjustment_direction') === 'DECREASE');

            if ($willDecrease && $quantity > (int) $item->current_stock) {
                $validator->errors()->add('quantity', 'Quantity exceeds the available stock for this item.');
            }
        });

        if ($validator->fails()) {
            return redirect()
                ->route('inventory.index')
                ->withErrors($validator->errors())
                ->withInput();
        }

        $validated = $validator->validated();
        $quantity = $this->resolveEggCountFromPayload(
            $validated,
            'quantity_entry_mode',
            'quantity',
            'quantity'
        );

        DB::transaction(function () use ($validated, $farmIds, $user, $quantity): void {
            /** @var EggItem $item */
            $item = EggItem::query()
                ->whereIn('farm_id', $farmIds)
                ->whereKey((int) $validated['item_id'])
                ->lockForUpdate()
                ->firstOrFail([
                    'id',
                    'farm_id',
                    'item_code',
                    'egg_type',
                    'size_class',
                    'unit_cost',
                    'current_stock',
                ]);

            $stockBefore = (int) $item->current_stock;
            $movementType = (string) $validated['movement_type'];
            $adjustmentDirection = (string) ($validated['adjustment_direction'] ?? '');

            if ($movementType === 'IN') {
                $stockAfter = $stockBefore + $quantity;
            } elseif ($movementType === 'OUT') {
                $stockAfter = $stockBefore - $quantity;
            } else {
                $stockAfter = $adjustmentDirection === 'DECREASE'
                    ? $stockBefore - $quantity
                    : $stockBefore + $quantity;
            }

            $unitCost = array_key_exists('unit_cost', $validated) && $validated['unit_cost'] !== null
                ? round((float) $validated['unit_cost'], 2)
                : round((float) $item->unit_cost, 2);

            $movement = StockMovement::query()->create([
                'item_id' => (int) $item->id,
                'movement_type' => $movementType,
                'quantity' => $quantity,
                'stock_before' => $stockBefore,
                'stock_after' => $stockAfter,
                'unit_cost' => $unitCost,
                'reference_no' => trim((string) $validated['reference_no']),
                'notes' => filled($validated['notes'] ?? null)
                    ? trim((string) $validated['notes'])
                    : ($movementType === 'ADJUSTMENT' ? 'Adjustment direction: ' . $adjustmentDirection : null),
                'movement_date' => $validated['movement_date'],
            ]);

            $item->update([
                'current_stock' => $stockAfter,
                'unit_cost' => $unitCost,
            ]);

            EggIntakeRecord::query()->create([
                'farm_id' => (int) $item->farm_id,
                'item_id' => (int) $item->id,
                'movement_id' => (int) $movement->id,
                'source' => 'MANUAL',
                'egg_type' => (string) $item->egg_type,
                'size_class' => (string) $item->size_class,
                'weight_grams' => 0,
                'quantity' => $quantity,
                'stock_before' => $stockBefore,
                'stock_after' => $stockAfter,
                'reference_no' => trim((string) $validated['reference_no']),
                'notes' => filled($validated['notes'] ?? null)
                    ? trim((string) $validated['notes'])
                    : ($movementType === 'ADJUSTMENT' ? 'Adjustment direction: ' . $adjustmentDirection : null),
                'payload_json' => json_encode([
                    'movement_type' => $movementType,
                    'adjustment_direction' => $adjustmentDirection !== '' ? $adjustmentDirection : null,
                    'recorded_via' => 'inventory_page',
                ], JSON_THROW_ON_ERROR),
                'created_by_user_id' => (int) $user->id,
                'recorded_at' => now(),
            ]);
        });

        return redirect()
            ->route('inventory.index')
            ->with('status', 'Inventory movement recorded successfully.');
    }

    /**
     * @return array<int, int>
     */
    private function resolveScopedFarmIds(Request $request): array
    {
        $user = $request->user();
        abort_unless($user && ($user->isOwner() || $user->isStaff()), 403);

        return $user->isOwner()
            ? $user->ownedFarms()->where('is_active', true)->pluck('farms.id')->map(static fn ($id): int => (int) $id)->all()
            : $user->staffFarms()->where('farms.is_active', true)->pluck('farms.id')->map(static fn ($id): int => (int) $id)->all();
    }

    /**
     * @param array<int, int> $farmIds
     * @param array<string, array{slug:string,class:string,label:string,min:string,max:string}> $weightRanges
     * @return Collection<int, array{id:int,farm_name:string,rows:array<int, array{slug:string,class:string,label:string,min:string,max:string,current_price:?string,is_mixed:bool,item_count:int,stock_total:int}>}>
     */
    private function buildPriceMatrix(array $farmIds, array $weightRanges): Collection
    {
        $pricingRows = EggItem::query()
            ->whereIn('farm_id', $farmIds)
            ->selectRaw('farm_id, size_class, COUNT(*) as item_count, COALESCE(SUM(current_stock), 0) as stock_total, MIN(selling_price) as min_price, MAX(selling_price) as max_price')
            ->groupBy('farm_id', 'size_class')
            ->get()
            ->keyBy(static fn ($row): string => $row->farm_id . '|' . $row->size_class);

        return Farm::query()
            ->whereIn('id', $farmIds)
            ->orderBy('farm_name')
            ->get(['id', 'farm_name'])
            ->map(function (Farm $farm) use ($weightRanges, $pricingRows): array {
                $rows = [];

                foreach ($weightRanges as $definition) {
                    $row = $pricingRows->get($farm->id . '|' . $definition['class']);
                    $minPrice = $row ? (float) $row->min_price : null;
                    $maxPrice = $row ? (float) $row->max_price : null;
                    $isMixed = $row && $minPrice !== $maxPrice;

                    $rows[] = [
                        'slug' => $definition['slug'],
                        'class' => $definition['class'],
                        'label' => $definition['label'],
                        'min' => $definition['min'],
                        'max' => $definition['max'],
                        'current_price' => $row && !$isMixed ? number_format($minPrice ?? 0, 2, '.', '') : null,
                        'is_mixed' => (bool) $isMixed,
                        'item_count' => (int) ($row->item_count ?? 0),
                        'stock_total' => (int) ($row->stock_total ?? 0),
                    ];
                }

                return [
                    'id' => (int) $farm->id,
                    'farm_name' => (string) $farm->farm_name,
                    'rows' => $rows,
                ];
            });
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveEggCountFromPayload(
        array $payload,
        string $entryModeField,
        string $quantityField,
        string $fieldPrefix
    ): int {
        $entryMode = strtoupper(trim((string) ($payload[$entryModeField] ?? 'EGGS')));

        if ($entryMode !== 'TRAYS') {
            return max(0, (int) ($payload[$quantityField] ?? 0));
        }

        $fullTrays = max(0, (int) ($payload[$fieldPrefix . '_full_trays'] ?? 0));
        $halfTrays = max(0, (int) ($payload[$fieldPrefix . '_half_trays'] ?? 0));
        $looseEggs = max(0, (int) ($payload[$fieldPrefix . '_loose_eggs'] ?? 0));

        return ($fullTrays * EggTrayFormatter::FULL_TRAY_CAPACITY)
            + ($halfTrays * EggTrayFormatter::HALF_TRAY_CAPACITY)
            + $looseEggs;
    }
}
