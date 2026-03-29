<?php

namespace App\Http\Controllers;

use App\Models\EggItem;
use App\Support\EggWeightRanges;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class PriceMonitoringController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user && ($user->isAdmin() || $user->isCustomer()), 403);

        $weightRanges = collect(EggWeightRanges::current())->keyBy('class');
        $selectedSizeClass = trim((string) $request->query('size_class', ''));
        if ($selectedSizeClass !== '' && !$weightRanges->has($selectedSizeClass)) {
            $selectedSizeClass = '';
        }

        $sizeComparisons = EggItem::query()
            ->selectRaw('size_class')
            ->selectRaw('COUNT(*) as item_count')
            ->selectRaw('COUNT(DISTINCT farm_id) as farm_count')
            ->selectRaw('COALESCE(SUM(current_stock), 0) as stock_total')
            ->selectRaw('MIN(COALESCE(NULLIF(selling_price, 0), unit_cost)) as min_price')
            ->selectRaw('MAX(COALESCE(NULLIF(selling_price, 0), unit_cost)) as max_price')
            ->selectRaw('COUNT(DISTINCT COALESCE(NULLIF(selling_price, 0), unit_cost)) as distinct_price_count')
            ->groupBy('size_class')
            ->orderByRaw("
                CASE size_class
                    WHEN 'Reject' THEN 1
                    WHEN 'Peewee' THEN 2
                    WHEN 'Pullet' THEN 3
                    WHEN 'Small' THEN 4
                    WHEN 'Medium' THEN 5
                    WHEN 'Large' THEN 6
                    WHEN 'Extra-Large' THEN 7
                    WHEN 'Jumbo' THEN 8
                    ELSE 99
                END
            ")
            ->get()
            ->map(function ($row) use ($weightRanges): array {
                $range = $weightRanges->get((string) $row->size_class);
                $minPrice = round((float) ($row->min_price ?? 0), 2);
                $maxPrice = round((float) ($row->max_price ?? 0), 2);

                return [
                    'size_class' => (string) $row->size_class,
                    'weight_range' => $range ? number_format((float) $range['min'], 2) . 'g to ' . number_format((float) $range['max'], 2) . 'g' : 'Range unavailable',
                    'item_count' => (int) ($row->item_count ?? 0),
                    'farm_count' => (int) ($row->farm_count ?? 0),
                    'stock_total' => (int) ($row->stock_total ?? 0),
                    'min_price' => $minPrice,
                    'max_price' => $maxPrice,
                    'spread' => round($maxPrice - $minPrice, 2),
                    'distinct_price_count' => (int) ($row->distinct_price_count ?? 0),
                ];
            });

        $farmSizeComparisons = EggItem::query()
            ->join('farms', 'farms.id', '=', 'egg_items.farm_id')
            ->selectRaw('farms.id as farm_id, farms.farm_name, egg_items.size_class')
            ->selectRaw('COUNT(egg_items.id) as item_count')
            ->selectRaw('COALESCE(SUM(egg_items.current_stock), 0) as stock_total')
            ->selectRaw('MIN(COALESCE(NULLIF(egg_items.selling_price, 0), egg_items.unit_cost)) as min_price')
            ->selectRaw('MAX(COALESCE(NULLIF(egg_items.selling_price, 0), egg_items.unit_cost)) as max_price')
            ->groupBy('farms.id', 'farms.farm_name', 'egg_items.size_class')
            ->orderBy('farms.farm_name')
            ->get()
            ->groupBy('farm_id');

        $farmComparisons = EggItem::query()
            ->join('farms', 'farms.id', '=', 'egg_items.farm_id')
            ->selectRaw('farms.id as farm_id, farms.farm_name')
            ->selectRaw('COUNT(egg_items.id) as item_count')
            ->selectRaw('COALESCE(SUM(egg_items.current_stock), 0) as stock_total')
            ->selectRaw('MIN(COALESCE(NULLIF(egg_items.selling_price, 0), egg_items.unit_cost)) as min_price')
            ->selectRaw('MAX(COALESCE(NULLIF(egg_items.selling_price, 0), egg_items.unit_cost)) as max_price')
            ->selectRaw('COUNT(DISTINCT COALESCE(NULLIF(egg_items.selling_price, 0), egg_items.unit_cost)) as distinct_price_count')
            ->groupBy('farms.id', 'farms.farm_name')
            ->orderBy('farms.farm_name')
            ->get()
            ->map(function ($row) use ($farmSizeComparisons): array {
                $minPrice = round((float) ($row->min_price ?? 0), 2);
                $maxPrice = round((float) ($row->max_price ?? 0), 2);
                $farmPrices = collect($farmSizeComparisons->get((int) $row->farm_id, []))
                    ->sortBy(static function ($entry): int {
                        return match ((string) $entry->size_class) {
                            'Reject' => 1,
                            'Peewee' => 2,
                            'Pullet' => 3,
                            'Small' => 4,
                            'Medium' => 5,
                            'Large' => 6,
                            'Extra-Large' => 7,
                            'Jumbo' => 8,
                            default => 99,
                        };
                    })
                    ->map(function ($entry): array {
                        $rowMinPrice = round((float) ($entry->min_price ?? 0), 2);
                        $rowMaxPrice = round((float) ($entry->max_price ?? 0), 2);

                        return [
                            'size_class' => (string) $entry->size_class,
                            'item_count' => (int) ($entry->item_count ?? 0),
                            'stock_total' => (int) ($entry->stock_total ?? 0),
                            'display_price' => $rowMinPrice === $rowMaxPrice
                                ? number_format($rowMinPrice, 2, '.', '')
                                : number_format($rowMinPrice, 2, '.', '') . ' to ' . number_format($rowMaxPrice, 2, '.', ''),
                        ];
                    })
                    ->values()
                    ->all();

                return [
                    'farm_id' => (int) $row->farm_id,
                    'farm_name' => (string) $row->farm_name,
                    'item_count' => (int) ($row->item_count ?? 0),
                    'stock_total' => (int) ($row->stock_total ?? 0),
                    'min_price' => $minPrice,
                    'max_price' => $maxPrice,
                    'spread' => round($maxPrice - $minPrice, 2),
                    'distinct_price_count' => (int) ($row->distinct_price_count ?? 0),
                    'size_prices' => $farmPrices,
                ];
            });

        $filteredFarmPrices = collect();
        $lowestFilteredPrice = null;

        if ($selectedSizeClass !== '') {
            $filteredFarmPrices = EggItem::query()
                ->join('farms', 'farms.id', '=', 'egg_items.farm_id')
                ->where('egg_items.size_class', $selectedSizeClass)
                ->selectRaw('farms.id as farm_id, farms.farm_name, egg_items.size_class')
                ->selectRaw('COUNT(egg_items.id) as item_count')
                ->selectRaw('COALESCE(SUM(egg_items.current_stock), 0) as stock_total')
                ->selectRaw('MIN(COALESCE(NULLIF(egg_items.selling_price, 0), egg_items.unit_cost)) as min_price')
                ->selectRaw('MAX(COALESCE(NULLIF(egg_items.selling_price, 0), egg_items.unit_cost)) as max_price')
                ->groupBy('farms.id', 'farms.farm_name', 'egg_items.size_class')
                ->orderByRaw('MIN(COALESCE(NULLIF(egg_items.selling_price, 0), egg_items.unit_cost)) asc')
                ->orderBy('farms.farm_name')
                ->get()
                ->map(function ($row) use ($weightRanges): array {
                    $minPrice = round((float) ($row->min_price ?? 0), 2);
                    $maxPrice = round((float) ($row->max_price ?? 0), 2);
                    $range = $weightRanges->get((string) $row->size_class);

                    return [
                        'farm_id' => (int) $row->farm_id,
                        'farm_name' => (string) $row->farm_name,
                        'size_class' => (string) $row->size_class,
                        'weight_range' => $range ? number_format((float) $range['min'], 2) . 'g to ' . number_format((float) $range['max'], 2) . 'g' : 'Range unavailable',
                        'item_count' => (int) ($row->item_count ?? 0),
                        'stock_total' => (int) ($row->stock_total ?? 0),
                        'min_price' => $minPrice,
                        'max_price' => $maxPrice,
                        'price_label' => $minPrice === $maxPrice
                            ? number_format($minPrice, 2, '.', '')
                            : number_format($minPrice, 2, '.', '') . ' to ' . number_format($maxPrice, 2, '.', ''),
                    ];
                })
                ->values();

            $lowestFilteredPrice = $filteredFarmPrices->min('min_price');
            $filteredFarmPrices = $filteredFarmPrices->map(function (array $row) use ($lowestFilteredPrice): array {
                $row['is_lowest'] = $lowestFilteredPrice !== null && (float) $row['min_price'] === (float) $lowestFilteredPrice;

                return $row;
            });
        }

        $summary = $this->buildSummary($sizeComparisons, $farmComparisons);

        return view('price-monitoring.index', [
            'priceSummary' => $summary,
            'sizeComparisons' => $sizeComparisons,
            'farmComparisons' => $farmComparisons,
            'sizeClassOptions' => $weightRanges->map(fn (array $row): array => [
                'class' => $row['class'],
                'label' => $row['label'],
                'min' => $row['min'],
                'max' => $row['max'],
            ])->values(),
            'selectedSizeClass' => $selectedSizeClass,
            'filteredFarmPrices' => $filteredFarmPrices,
            'lowestFilteredPrice' => $lowestFilteredPrice,
        ]);
    }

    /**
     * @param Collection<int, array{size_class:string,weight_range:string,item_count:int,farm_count:int,stock_total:int,min_price:float,max_price:float,spread:float,distinct_price_count:int}> $sizeComparisons
     * @param Collection<int, array{farm_id:int,farm_name:string,item_count:int,stock_total:int,min_price:float,max_price:float,spread:float,distinct_price_count:int,size_prices:array<int, array{size_class:string,item_count:int,stock_total:int,display_price:string}>}> $farmComparisons
     * @return array{tracked_sizes:int,tracked_farms:int,stock_total:int,widest_spread:float,highest_class:string|null,lowest_class:string|null,live_price_points:int}
     */
    private function buildSummary(Collection $sizeComparisons, Collection $farmComparisons): array
    {
        $highestClass = $sizeComparisons->sortByDesc('max_price')->first();
        $lowestClass = $sizeComparisons->filter(fn (array $row): bool => $row['min_price'] > 0)->sortBy('min_price')->first();

        return [
            'tracked_sizes' => $sizeComparisons->count(),
            'tracked_farms' => $farmComparisons->count(),
            'stock_total' => (int) $farmComparisons->sum('stock_total'),
            'widest_spread' => round((float) $sizeComparisons->max('spread'), 2),
            'highest_class' => $highestClass['size_class'] ?? null,
            'lowest_class' => $lowestClass['size_class'] ?? null,
            'live_price_points' => (int) $farmComparisons->sum('distinct_price_count'),
        ];
    }
}
