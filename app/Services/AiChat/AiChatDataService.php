<?php

namespace App\Services\AiChat;

use App\Models\EggItem;
use App\Models\Farm;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\AppTimezone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AiChatDataService
{
    public function __construct(
        private readonly InventoryForecastService $forecastService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function bootstrap(User $user): array
    {
        $roleKey = $this->roleKey($user);

        return [
            'role_key' => $roleKey,
            'role_label' => $user->role?->label() ?? ucfirst($roleKey),
            'timezone' => AppTimezone::current(),
            'action_mode' => 'draft_only',
            'quick_prompts' => $this->quickPrompts($roleKey),
            'capabilities' => $this->capabilities($roleKey),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function build(User $user, string $message): array
    {
        $roleKey = $this->roleKey($user);
        $intent = $this->detectIntent($message);
        $farmIds = $this->accessibleFarmIds($user);
        $deviceIds = $this->accessibleDeviceIds($user, $farmIds);

        $context = [
            'role_key' => $roleKey,
            'role_label' => $user->role?->label() ?? ucfirst($roleKey),
            'intent' => $intent,
            'generated_at' => AppTimezone::now()->toIso8601String(),
            'scope' => [
                'farm_count' => count($farmIds),
                'device_count' => count($deviceIds),
                'draft_only' => true,
            ],
            'sources' => [],
            'data' => [],
            'action_draft' => null,
        ];

        if ($roleKey === 'customer' && $this->isPrivateOperationalAsk($message, $intent)) {
            return $this->refusal($context, 'Your customer account can use price monitoring and public stock/price summaries, but it cannot access private farm, device, batch, or owner inventory records.');
        }

        if ($intent === 'action_draft') {
            $draft = $this->buildActionDraft($roleKey, $message);
            $context['data'] = $draft;
            $context['sources'] = [['label' => 'Draft-only AI action policy', 'table' => 'ai_chat_action_drafts']];
            $context['action_draft'] = [
                'action_type' => $draft['action_type'],
                'summary' => $draft['summary'],
                'target_route' => $draft['target_route'],
                'payload_json' => [
                    'request' => $message,
                    'role_key' => $roleKey,
                    'draft_only' => true,
                ],
            ];

            return $context;
        }

        if ($intent === 'best_selling_eggs') {
            $context['data'] = $this->buildBestSelling($roleKey, $farmIds);
            $context['sources'] = [['label' => 'Outbound stock movement proxy', 'table' => 'stock_movements']];

            return $context;
        }

        if ($intent === 'price_monitoring' || $roleKey === 'customer') {
            $context['intent'] = 'price_monitoring';
            $context['data'] = $this->buildPriceMonitoring();
            $context['sources'] = [['label' => 'Price monitoring aggregates', 'table' => 'egg_items']];

            return $context;
        }

        if ($farmIds === []) {
            return $this->refusal($context, 'No farms or devices are available in your current account scope.');
        }

        if ($intent === 'inventory_forecast') {
            $context['data'] = $this->forecastService->build($farmIds, $message);
            $context['sources'] = [
                ['label' => 'Scoped stock movements', 'table' => 'stock_movements'],
                ['label' => 'Scoped inventory items', 'table' => 'egg_items'],
            ];

            return $context;
        }

        if ($intent === 'batch_summary') {
            $context['data'] = $this->buildBatchSummary($farmIds, $deviceIds);
            $context['sources'] = [
                ['label' => 'Scoped production batches', 'table' => 'production_batches'],
                ['label' => 'Scoped device ingest records', 'table' => 'device_ingest_events'],
            ];

            return $context;
        }

        if ($intent === 'production_trend') {
            $context['data'] = $this->buildProductionTrend($farmIds, $deviceIds);
            $context['sources'] = [['label' => 'Scoped device ingest records', 'table' => 'device_ingest_events']];

            return $context;
        }

        if ($intent === 'low_stock') {
            $context['data'] = $this->buildInventoryOverview($farmIds);
            $context['sources'] = [['label' => 'Scoped inventory items', 'table' => 'egg_items']];

            return $context;
        }

        $context['intent'] = 'inventory_overview';
        $context['data'] = $this->buildInventoryOverview($farmIds);
        $context['sources'] = [
            ['label' => 'Scoped inventory items', 'table' => 'egg_items'],
            ['label' => 'Scoped stock movements', 'table' => 'stock_movements'],
        ];

        return $context;
    }

    public function roleKey(User $user): string
    {
        if ($user->isAdmin()) {
            return 'admin';
        }

        if ($user->isOwner()) {
            return 'owner';
        }

        if ($user->isStaff()) {
            return 'staff';
        }

        return 'customer';
    }

    /**
     * @return array<int, int>
     */
    public function accessibleFarmIds(User $user): array
    {
        if ($user->isAdmin()) {
            return Farm::query()
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
        }

        if ($user->isOwner()) {
            return $user->ownedFarms()
                ->where('is_active', true)
                ->pluck('farms.id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
        }

        if ($user->isStaff()) {
            return $user->staffFarms()
                ->where('farms.is_active', true)
                ->pluck('farms.id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
        }

        return [];
    }

    /**
     * @param array<int, int> $farmIds
     * @return array<int, int>
     */
    public function accessibleDeviceIds(User $user, array $farmIds): array
    {
        if ($user->isCustomer() || $farmIds === []) {
            return [];
        }

        $query = DB::table('devices')
            ->whereIn('farm_id', $farmIds)
            ->where('is_active', true);

        if ($user->isOwner()) {
            $query->where('owner_user_id', (int) $user->id);
        }

        return $query
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function quickPrompts(string $roleKey): array
    {
        return match ($roleKey) {
            'customer' => [
                'What are the best-selling eggs right now?',
                'Show egg price ranges by size.',
                'Which egg size has the most available stock?',
            ],
            'admin' => [
                'Summarize system farm, device, and inventory health.',
                'Predict egg inventory for the next week.',
                'Show batch monitoring performance this month.',
            ],
            default => [
                'Predict egg inventory for tomorrow.',
                'Predict egg inventory for the next week.',
                'Show low-stock risks for my farms.',
                'Summarize production trends this month.',
            ],
        };
    }

    /**
     * @return array<int, string>
     */
    private function capabilities(string $roleKey): array
    {
        return match ($roleKey) {
            'customer' => ['Price monitoring', 'Best-selling proxy by outbound stock', 'Public stock summaries'],
            'admin' => ['All farm/device/inventory summaries', 'Batch monitoring', 'Inventory forecasting', 'Draft-only action requests'],
            default => ['Scoped farm inventory', 'Scoped batch monitoring', 'Inventory forecasting', 'Draft-only action requests'],
        };
    }

    private function detectIntent(string $message): string
    {
        $lower = Str::lower($message);

        if (preg_match('/\b(create|record|update|delete|close|open|notify|inform|send|change|add|remove|do)\b/', $lower)) {
            return 'action_draft';
        }

        if (preg_match('/\b(predict|forecast|projection|estimate|tomorrow|next\s+day|next\s+week|next\s+month)\b/', $lower)) {
            return 'inventory_forecast';
        }

        if (preg_match('/\b(best[- ]selling|top[- ]selling|seller|sales|sold|outbound)\b/', $lower)) {
            return 'best_selling_eggs';
        }

        if (preg_match('/\b(price|pricing|cost|available stock|availability|market)\b/', $lower)) {
            return 'price_monitoring';
        }

        if (preg_match('/\b(batch|batches|reject|average weight|total weight|throughput)\b/', $lower)) {
            return 'batch_summary';
        }

        if (preg_match('/\b(production|trend|daily|records|telemetry|movement)\b/', $lower)) {
            return 'production_trend';
        }

        if (preg_match('/\b(low stock|reorder|shortage|risk)\b/', $lower)) {
            return 'low_stock';
        }

        return 'inventory_overview';
    }

    private function isPrivateOperationalAsk(string $message, string $intent): bool
    {
        if (in_array($intent, ['best_selling_eggs', 'price_monitoring', 'action_draft'], true)) {
            return false;
        }

        return (bool) preg_match('/\b(farm|device|batch|owner|staff|inventory|production|telemetry|record|private)\b/i', $message);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function refusal(array $context, string $message): array
    {
        $context['intent'] = 'refusal';
        $context['data'] = ['message' => $message];
        $context['sources'] = [];

        return $context;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildActionDraft(string $roleKey, string $message): array
    {
        $targetRoute = null;
        $targetLabel = 'Review in the appropriate page';
        $actionType = 'general_request';

        if (preg_match('/\b(stock|inventory|egg item|movement)\b/i', $message) && in_array($roleKey, ['admin', 'owner', 'staff'], true)) {
            $targetRoute = 'inventory.index';
            $targetLabel = 'Inventory';
            $actionType = 'inventory_request';
        } elseif (preg_match('/\b(batch|close|open)\b/i', $message) && $roleKey !== 'customer') {
            $targetRoute = 'monitoring.batches.index';
            $targetLabel = 'Batch Monitoring';
            $actionType = 'batch_request';
        } elseif (preg_match('/\b(notify|inform|message|owner)\b/i', $message) && $roleKey === 'staff') {
            $targetRoute = 'monitoring.notifications.index';
            $targetLabel = 'Owner notification draft';
            $actionType = 'owner_note_request';
        } elseif (preg_match('/\b(price|pricing)\b/i', $message) && in_array($roleKey, ['admin', 'owner'], true)) {
            $targetRoute = $roleKey === 'owner' ? 'inventory.index' : 'price-monitoring.index';
            $targetLabel = $roleKey === 'owner' ? 'Inventory pricing' : 'Price Monitoring';
            $actionType = 'pricing_request';
        }

        return [
            'action_type' => $actionType,
            'request' => $message,
            'target_route' => $targetRoute,
            'target_label' => $targetLabel,
            'summary' => 'The assistant can draft this request, but the user must complete any real change manually in the system.',
            'draft_only' => true,
        ];
    }

    /**
     * @param array<int, int> $farmIds
     * @return array<string, mixed>
     */
    private function buildInventoryOverview(array $farmIds): array
    {
        if ($farmIds === []) {
            return [
                'summary' => ['sku_total' => 0, 'stock_total' => 0, 'low_stock_total' => 0, 'inventory_value' => 0],
                'by_size' => [],
                'low_stock_items' => [],
                'recent_movements' => [],
            ];
        }

        $summary = EggItem::query()
            ->whereIn('farm_id', $farmIds)
            ->selectRaw('COUNT(*) as sku_total')
            ->selectRaw('COALESCE(SUM(current_stock), 0) as stock_total')
            ->selectRaw('SUM(CASE WHEN current_stock <= reorder_level THEN 1 ELSE 0 END) as low_stock_total')
            ->selectRaw('COALESCE(SUM(current_stock * COALESCE(NULLIF(selling_price, 0), unit_cost)), 0) as inventory_value')
            ->first();

        $bySize = EggItem::query()
            ->whereIn('farm_id', $farmIds)
            ->selectRaw('size_class')
            ->selectRaw('COUNT(*) as item_count')
            ->selectRaw('COALESCE(SUM(current_stock), 0) as stock_total')
            ->groupBy('size_class')
            ->orderByRaw($this->sizeClassOrderSql('size_class'))
            ->get()
            ->map(static fn ($row): array => [
                'size_class' => (string) $row->size_class,
                'item_count' => (int) $row->item_count,
                'stock_total' => (int) $row->stock_total,
            ])
            ->all();

        $lowStockItems = EggItem::query()
            ->join('farms', 'farms.id', '=', 'egg_items.farm_id')
            ->whereIn('egg_items.farm_id', $farmIds)
            ->whereColumn('egg_items.current_stock', '<=', 'egg_items.reorder_level')
            ->orderBy('egg_items.current_stock')
            ->limit(8)
            ->get([
                'egg_items.item_code',
                'egg_items.egg_type',
                'egg_items.size_class',
                'egg_items.current_stock',
                'egg_items.reorder_level',
                'farms.farm_name',
            ])
            ->map(static fn ($row): array => [
                'farm_name' => (string) $row->farm_name,
                'item_code' => (string) $row->item_code,
                'egg_type' => (string) $row->egg_type,
                'size_class' => (string) $row->size_class,
                'current_stock' => (int) $row->current_stock,
                'reorder_level' => (int) $row->reorder_level,
            ])
            ->all();

        $recentMovements = StockMovement::query()
            ->join('egg_items', 'egg_items.id', '=', 'stock_movements.item_id')
            ->join('farms', 'farms.id', '=', 'egg_items.farm_id')
            ->whereIn('egg_items.farm_id', $farmIds)
            ->orderByDesc('stock_movements.movement_date')
            ->orderByDesc('stock_movements.id')
            ->limit(5)
            ->get([
                'stock_movements.movement_type',
                'stock_movements.quantity',
                'stock_movements.stock_before',
                'stock_movements.stock_after',
                'stock_movements.movement_date',
                'egg_items.item_code',
                'egg_items.size_class',
                'farms.farm_name',
            ])
            ->map(static fn ($row): array => [
                'farm_name' => (string) $row->farm_name,
                'item_code' => (string) $row->item_code,
                'size_class' => (string) $row->size_class,
                'movement_type' => (string) $row->movement_type,
                'quantity' => (int) $row->quantity,
                'stock_before' => (int) $row->stock_before,
                'stock_after' => (int) $row->stock_after,
                'movement_date' => (string) $row->movement_date,
            ])
            ->all();

        return [
            'summary' => [
                'sku_total' => (int) ($summary->sku_total ?? 0),
                'stock_total' => (int) ($summary->stock_total ?? 0),
                'low_stock_total' => (int) ($summary->low_stock_total ?? 0),
                'inventory_value' => round((float) ($summary->inventory_value ?? 0), 2),
            ],
            'by_size' => $bySize,
            'low_stock_items' => $lowStockItems,
            'recent_movements' => $recentMovements,
        ];
    }

    /**
     * @param array<int, int> $farmIds
     * @return array<string, mixed>
     */
    private function buildBestSelling(string $roleKey, array $farmIds): array
    {
        $start = AppTimezone::now()->subDays(90)->toDateString();
        $query = DB::table('stock_movements as movements')
            ->join('egg_items as items', 'items.id', '=', 'movements.item_id')
            ->where('movements.movement_type', 'OUT')
            ->where('movements.movement_date', '>=', $start);

        if ($roleKey !== 'customer') {
            if ($farmIds === []) {
                return [
                    'basis' => 'No farms are available in this account scope.',
                    'rows' => [],
                    'window_start' => $start,
                ];
            }

            $query->whereIn('items.farm_id', $farmIds);
        }

        $rows = $query
            ->selectRaw('items.egg_type')
            ->selectRaw('items.size_class')
            ->selectRaw('SUM(movements.quantity) as quantity_out')
            ->selectRaw('COALESCE(SUM(movements.quantity * COALESCE(NULLIF(items.selling_price, 0), items.unit_cost)), 0) as revenue_proxy')
            ->selectRaw('COUNT(DISTINCT items.farm_id) as farm_count')
            ->groupBy('items.egg_type', 'items.size_class')
            ->orderByDesc('quantity_out')
            ->limit(5)
            ->get()
            ->map(static fn ($row): array => [
                'egg_type' => (string) $row->egg_type,
                'size_class' => (string) $row->size_class,
                'quantity_out' => (int) $row->quantity_out,
                'revenue_proxy' => round((float) $row->revenue_proxy, 2),
                'farm_count' => (int) $row->farm_count,
            ])
            ->all();

        return [
            'basis' => 'No sales/order tables were found, so this ranks eggs by OUT stock movements over the last 90 days.',
            'window_start' => $start,
            'rows' => $rows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPriceMonitoring(): array
    {
        $rows = EggItem::query()
            ->selectRaw('size_class')
            ->selectRaw('COUNT(*) as item_count')
            ->selectRaw('COUNT(DISTINCT farm_id) as farm_count')
            ->selectRaw('COALESCE(SUM(current_stock), 0) as stock_total')
            ->selectRaw('MIN(COALESCE(NULLIF(selling_price, 0), unit_cost)) as min_price')
            ->selectRaw('MAX(COALESCE(NULLIF(selling_price, 0), unit_cost)) as max_price')
            ->groupBy('size_class')
            ->orderByRaw($this->sizeClassOrderSql('size_class'))
            ->get()
            ->map(static fn ($row): array => [
                'size_class' => (string) $row->size_class,
                'item_count' => (int) $row->item_count,
                'farm_count' => (int) $row->farm_count,
                'stock_total' => (int) $row->stock_total,
                'min_price' => round((float) $row->min_price, 2),
                'max_price' => round((float) $row->max_price, 2),
            ])
            ->all();

        return [
            'size_prices' => $rows,
        ];
    }

    /**
     * @param array<int, int> $farmIds
     * @param array<int, int> $deviceIds
     * @return array<string, mixed>
     */
    private function buildBatchSummary(array $farmIds, array $deviceIds): array
    {
        if ($farmIds === [] || $deviceIds === []) {
            return ['window' => 'last 30 days', 'batches' => []];
        }

        $start = AppTimezone::now()->subDays(30)->toDateTimeString();

        if (!Schema::hasTable('production_batches')) {
            return ['window' => 'last 30 days', 'batches' => []];
        }

        $eventStats = DB::table('device_ingest_events')
            ->selectRaw('production_batch_id')
            ->selectRaw('COUNT(*) as total_eggs')
            ->selectRaw("SUM(CASE WHEN size_class = 'Reject' THEN 1 ELSE 0 END) as reject_count")
            ->selectRaw('COALESCE(AVG(weight_grams), 0) as avg_weight_grams')
            ->selectRaw('COALESCE(SUM(weight_grams), 0) as total_weight_grams')
            ->whereNotNull('production_batch_id')
            ->groupBy('production_batch_id');

        $rows = DB::table('production_batches as batches')
            ->join('farms', 'farms.id', '=', 'batches.farm_id')
            ->join('devices', 'devices.id', '=', 'batches.device_id')
            ->leftJoinSub($eventStats, 'event_stats', static function ($join): void {
                $join->on('event_stats.production_batch_id', '=', 'batches.id');
            })
            ->whereIn('batches.farm_id', $farmIds)
            ->whereIn('batches.device_id', $deviceIds)
            ->where('batches.started_at', '>=', $start)
            ->orderByRaw('COALESCE(batches.ended_at, batches.started_at) DESC')
            ->limit(8)
            ->get([
                'batches.batch_code',
                'batches.status',
                'batches.started_at',
                'batches.ended_at',
                'farms.farm_name',
                'devices.module_board_name as device_name',
                DB::raw('COALESCE(event_stats.total_eggs, 0) as total_eggs'),
                DB::raw('COALESCE(event_stats.reject_count, 0) as reject_count'),
                DB::raw('COALESCE(event_stats.avg_weight_grams, 0) as avg_weight_grams'),
                DB::raw('COALESCE(event_stats.total_weight_grams, 0) as total_weight_grams'),
            ])
            ->map(static fn ($row): array => [
                'batch_code' => (string) $row->batch_code,
                'farm_name' => (string) $row->farm_name,
                'device_name' => (string) $row->device_name,
                'status' => (string) $row->status,
                'total_eggs' => (int) $row->total_eggs,
                'reject_count' => (int) $row->reject_count,
                'avg_weight_grams' => round((float) $row->avg_weight_grams, 2),
                'total_weight_grams' => round((float) $row->total_weight_grams, 2),
                'started_at' => (string) $row->started_at,
                'ended_at' => $row->ended_at ? (string) $row->ended_at : null,
            ])
            ->all();

        return [
            'window' => 'last 30 days',
            'batches' => $rows,
        ];
    }

    /**
     * @param array<int, int> $farmIds
     * @param array<int, int> $deviceIds
     * @return array<string, mixed>
     */
    private function buildProductionTrend(array $farmIds, array $deviceIds): array
    {
        if ($farmIds === [] || $deviceIds === []) {
            return ['daily_rows' => []];
        }

        $start = AppTimezone::now()->subDays(13)->startOfDay()->toDateTimeString();

        $rows = DB::table('device_ingest_events')
            ->whereIn('farm_id', $farmIds)
            ->whereIn('device_id', $deviceIds)
            ->where('recorded_at', '>=', $start)
            ->selectRaw('DATE(recorded_at) as production_date')
            ->selectRaw('COUNT(*) as total_eggs')
            ->selectRaw("SUM(CASE WHEN size_class = 'Reject' THEN 1 ELSE 0 END) as reject_count")
            ->selectRaw('COALESCE(AVG(weight_grams), 0) as avg_weight_grams')
            ->groupByRaw('DATE(recorded_at)')
            ->orderBy('production_date')
            ->get()
            ->map(static fn ($row): array => [
                'date' => (string) $row->production_date,
                'total_eggs' => (int) $row->total_eggs,
                'reject_count' => (int) $row->reject_count,
                'avg_weight_grams' => round((float) $row->avg_weight_grams, 2),
            ])
            ->all();

        return [
            'daily_rows' => $rows,
        ];
    }

    private function sizeClassOrderSql(string $column): string
    {
        return "
            CASE {$column}
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
        ";
    }
}
