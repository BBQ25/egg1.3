<?php

namespace App\Services\AiChat;

class AiChatResponseFormatter
{
    /**
     * @param array<string, mixed> $context
     */
    public function format(array $context): string
    {
        $intent = (string) ($context['intent'] ?? 'overview');
        $data = is_array($context['data'] ?? null) ? $context['data'] : [];
        $question = (string) ($context['question'] ?? '');
        $profile = is_array($context['question_profile'] ?? null) ? $context['question_profile'] : [];

        return match ($intent) {
            'refusal' => (string) ($data['message'] ?? 'I cannot access that data with your current account role.'),
            'clarification' => $this->formatClarification($data),
            'action_draft' => $this->formatActionDraft($data),
            'inventory_forecast' => $this->formatForecast($data, $profile),
            'best_selling_eggs' => $this->formatBestSelling($data),
            'price_monitoring' => $this->formatPriceMonitoring($data, $question),
            'batch_summary' => $this->formatBatchSummary($data),
            'production_trend' => $this->formatProductionTrend($data),
            'low_stock' => $this->formatInventory($data, true, $question),
            default => $this->formatInventory($data, false, $question),
        };
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $profile
     */
    private function formatForecast(array $data, array $profile): string
    {
        $horizon = $data['horizon']['label'] ?? 'day';
        $currentStock = (int) ($data['current_stock'] ?? 0);
        $algorithms = is_array($data['algorithms'] ?? null) ? $data['algorithms'] : [];
        $best = isset($algorithms[0]) && is_array($algorithms[0]) ? $algorithms[0] : null;

        $lines = [
            "Inventory forecast for the next {$horizon}.",
            "Current scoped stock: {$this->number($currentStock)} eggs",
            'History window: ' . ($data['series_start'] ?? 'N/A') . ' to ' . ($data['series_end'] ?? 'N/A'),
            'Movement data points: ' . $this->number((int) ($data['non_zero_data_points'] ?? 0)),
        ];

        if (!empty($data['insufficient_data'])) {
            $lines[] = 'I cannot give a reliable projection yet because the movement history is too thin.';
            $lines[] = 'Reliability note: ' . ($data['insufficient_reason'] ?? 'Insufficient movement history.');
        } elseif ($best) {
            $projected = ($best['projected_stock'] ?? null) === null
                ? 'N/A'
                : $this->number((int) $best['projected_stock']) . ' eggs';
            $lines[] = sprintf(
                'Best current candidate: %s, with MAE %s and projected stock of %s.',
                $best['name'] ?? 'N/A',
                $this->metric($best['mae'] ?? null),
                $projected
            );
        }

        if (($profile['wants_explanation'] ?? false) === true) {
            $lines[] = 'Logic used: I compared recent daily net movement (IN minus OUT/adjustments), backtested each candidate algorithm, then ranked them by MAE.';
        }

        $lines[] = '';
        $lines[] = '| Algorithm | MAE | RMSE | Daily net | Projected stock | Confidence |';
        $lines[] = '| --- | ---: | ---: | ---: | ---: | --- |';

        foreach ($algorithms as $row) {
            if (!is_array($row)) {
                continue;
            }

            $lines[] = sprintf(
                '| %s | %s | %s | %s | %s | %s |',
                $row['name'] ?? '-',
                $this->metric($row['mae'] ?? null),
                $this->metric($row['rmse'] ?? null),
                $this->metric($row['daily_net_forecast'] ?? null),
                ($row['projected_stock'] ?? null) === null ? 'N/A' : $this->number((int) $row['projected_stock']),
                $row['confidence'] ?? '-'
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function formatBestSelling(array $data): string
    {
        $rows = is_array($data['rows'] ?? null) ? $data['rows'] : [];
        $lines = [
            'Best-selling eggs proxy.',
            (string) ($data['basis'] ?? 'Based on OUT stock movements.'),
        ];

        if (isset($rows[0]) && is_array($rows[0])) {
            $top = $rows[0];
            $lines[] = sprintf(
                'Direct answer: %s %s is currently ranked first by outbound quantity (%s eggs out).',
                $top['egg_type'] ?? 'Egg',
                $top['size_class'] ?? '',
                $this->number((int) ($top['quantity_out'] ?? 0))
            );
        } else {
            $lines[] = 'Direct answer: I do not see outbound movement data for this period yet.';
        }

        $lines[] = '';
        $lines[] = '| Rank | Egg type | Size | Quantity out | Revenue proxy |';
        $lines[] = '| ---: | --- | --- | ---: | ---: |';

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $lines[] = sprintf(
                '| %d | %s | %s | %s | %s |',
                $index + 1,
                $row['egg_type'] ?? '-',
                $row['size_class'] ?? '-',
                $this->number((int) ($row['quantity_out'] ?? 0)),
                $this->money((float) ($row['revenue_proxy'] ?? 0))
            );
        }

        if ($rows === []) {
            $lines[] = '| - | No outbound stock movements found | - | 0 | 0.00 |';
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function formatPriceMonitoring(array $data, string $question): string
    {
        $rows = is_array($data['size_prices'] ?? null) ? $data['size_prices'] : [];
        $lines = [
            $this->priceDirectAnswer($rows, $question),
            '',
            '| Size class | Stock | Farms | Min price | Max price |',
            '| --- | ---: | ---: | ---: | ---: |',
        ];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $lines[] = sprintf(
                '| %s | %s | %s | %s | %s |',
                $row['size_class'] ?? '-',
                $this->number((int) ($row['stock_total'] ?? 0)),
                $this->number((int) ($row['farm_count'] ?? 0)),
                $this->money((float) ($row['min_price'] ?? 0)),
                $this->money((float) ($row['max_price'] ?? 0))
            );
        }

        if ($rows === []) {
            $lines[] = '| - | 0 | 0 | 0.00 | 0.00 |';
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function formatInventory(array $data, bool $lowStockOnly, string $question): string
    {
        $summary = is_array($data['summary'] ?? null) ? $data['summary'] : [];
        $stockTotal = (int) ($summary['stock_total'] ?? 0);
        $skuTotal = (int) ($summary['sku_total'] ?? 0);
        $lowStockTotal = (int) ($summary['low_stock_total'] ?? 0);
        $bySize = is_array($data['by_size'] ?? null) ? $data['by_size'] : [];
        $lowStockItems = is_array($data['low_stock_items'] ?? null) ? $data['low_stock_items'] : [];

        $lines = [
            $this->inventoryDirectAnswer($data, $lowStockOnly, $question),
            'Scoped stock: ' . $this->number($stockTotal) . ' eggs',
            'Tracked SKUs: ' . $this->number($skuTotal),
            'Low-stock SKUs: ' . $this->number($lowStockTotal),
            '',
            '| Size class | Items | Stock |',
            '| --- | ---: | ---: |',
        ];

        foreach ($bySize as $row) {
            if (!is_array($row)) {
                continue;
            }

            $lines[] = sprintf(
                '| %s | %s | %s |',
                $row['size_class'] ?? '-',
                $this->number((int) ($row['item_count'] ?? 0)),
                $this->number((int) ($row['stock_total'] ?? 0))
            );
        }

        if ($bySize === []) {
            $lines[] = '| - | 0 | 0 |';
        }

        if ($lowStockItems !== []) {
            $lines[] = '';
            $lines[] = 'Items at or below reorder level:';
            foreach ($lowStockItems as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $lines[] = sprintf(
                    '- %s / %s: %s eggs, reorder level %s',
                    $row['farm_name'] ?? '-',
                    $row['item_code'] ?? '-',
                    $this->number((int) ($row['current_stock'] ?? 0)),
                    $this->number((int) ($row['reorder_level'] ?? 0))
                );
            }
        } elseif ($lowStockOnly) {
            $lines[] = '';
            $lines[] = 'No items are currently at or below reorder level in your visible scope.';
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function formatBatchSummary(array $data): string
    {
        $batches = is_array($data['batches'] ?? null) ? $data['batches'] : [];
        $lines = [
            'Batch monitoring summary.',
            'Window: ' . ($data['window'] ?? 'last 30 days'),
        ];

        if (isset($batches[0]) && is_array($batches[0])) {
            $first = $batches[0];
            $lines[] = sprintf(
                'Most recent visible batch: %s at %s, status %s, with %s eggs and %s rejects.',
                $first['batch_code'] ?? '-',
                $first['farm_name'] ?? '-',
                $first['status'] ?? '-',
                $this->number((int) ($first['total_eggs'] ?? 0)),
                $this->number((int) ($first['reject_count'] ?? 0))
            );
        } else {
            $lines[] = 'I do not see visible batch records for this window.';
        }

        $lines[] = '';
        $lines[] = '| Batch | Farm | Device | Status | Eggs | Rejects | Avg weight |';
        $lines[] = '| --- | --- | --- | --- | ---: | ---: | ---: |';

        foreach ($batches as $row) {
            if (!is_array($row)) {
                continue;
            }

            $lines[] = sprintf(
                '| %s | %s | %s | %s | %s | %s | %s |',
                $row['batch_code'] ?? '-',
                $row['farm_name'] ?? '-',
                $row['device_name'] ?? '-',
                $row['status'] ?? '-',
                $this->number((int) ($row['total_eggs'] ?? 0)),
                $this->number((int) ($row['reject_count'] ?? 0)),
                $this->metric($row['avg_weight_grams'] ?? null)
            );
        }

        if ($batches === []) {
            $lines[] = '| - | No visible batch records found | - | - | 0 | 0 | 0 |';
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function formatProductionTrend(array $data): string
    {
        $rows = is_array($data['daily_rows'] ?? null) ? $data['daily_rows'] : [];
        $lines = [
            $this->productionDirectAnswer($rows),
            '',
            '| Date | Eggs | Rejects | Avg weight |',
            '| --- | ---: | ---: | ---: |',
        ];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $lines[] = sprintf(
                '| %s | %s | %s | %s |',
                $row['date'] ?? '-',
                $this->number((int) ($row['total_eggs'] ?? 0)),
                $this->number((int) ($row['reject_count'] ?? 0)),
                $this->metric($row['avg_weight_grams'] ?? null)
            );
        }

        if ($rows === []) {
            $lines[] = '| - | 0 | 0 | 0 |';
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function formatActionDraft(array $data): string
    {
        return implode("\n", [
            'I prepared this as a draft only. No database record was changed.',
            'Request: ' . (string) ($data['request'] ?? 'Action request'),
            'Suggested destination: ' . (string) ($data['target_label'] ?? 'Review in the appropriate page'),
            'Draft summary: ' . (string) ($data['summary'] ?? 'Review the request before taking action.'),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function formatClarification(array $data): string
    {
        $lines = [(string) ($data['message'] ?? 'What would you like me to check?')];
        $prompts = is_array($data['suggested_prompts'] ?? null) ? $data['suggested_prompts'] : [];

        if ($prompts !== []) {
            $lines[] = '';
            $lines[] = 'You can ask, for example:';
            foreach (array_slice($prompts, 0, 3) as $prompt) {
                $lines[] = '- ' . (string) $prompt;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<int, mixed> $rows
     */
    private function priceDirectAnswer(array $rows, string $question): string
    {
        if ($rows === []) {
            return 'I do not see price or stock entries yet.';
        }

        $lower = mb_strtolower($question);

        if (preg_match('/\b(most available|highest stock|most stock|available stock|availability)\b/', $lower)) {
            $top = $this->maxRow($rows, 'stock_total');

            return sprintf(
                'The most available size is %s with %s eggs in visible stock.',
                $top['size_class'] ?? '-',
                $this->number((int) ($top['stock_total'] ?? 0))
            );
        }

        if (preg_match('/\b(cheapest|lowest price|min price|least expensive)\b/', $lower)) {
            $top = $this->minRow($rows, 'min_price');

            return sprintf(
                'The lowest visible price is %s for %s eggs.',
                $this->money((float) ($top['min_price'] ?? 0)),
                $top['size_class'] ?? '-'
            );
        }

        if (preg_match('/\b(highest price|max price|most expensive)\b/', $lower)) {
            $top = $this->maxRow($rows, 'max_price');

            return sprintf(
                'The highest visible price is %s for %s eggs.',
                $this->money((float) ($top['max_price'] ?? 0)),
                $top['size_class'] ?? '-'
            );
        }

        return 'Here are the current visible price ranges and stock by size.';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function inventoryDirectAnswer(array $data, bool $lowStockOnly, string $question): string
    {
        $summary = is_array($data['summary'] ?? null) ? $data['summary'] : [];
        $stockTotal = (int) ($summary['stock_total'] ?? 0);
        $skuTotal = (int) ($summary['sku_total'] ?? 0);
        $lowStockTotal = (int) ($summary['low_stock_total'] ?? 0);
        $lower = mb_strtolower($question);

        if ($lowStockOnly) {
            return $lowStockTotal > 0
                ? 'I found ' . $this->number($lowStockTotal) . ' low-stock SKU(s) that need attention.'
                : 'I do not see low-stock risks in your visible inventory right now.';
        }

        if (preg_match('/\b(most|highest|largest)\b.*\b(stock|inventory|available)\b/', $lower)) {
            $top = $this->maxRow(is_array($data['by_size'] ?? null) ? $data['by_size'] : [], 'stock_total');
            if ($top !== []) {
                return sprintf(
                    '%s has the highest visible stock with %s eggs.',
                    $top['size_class'] ?? '-',
                    $this->number((int) ($top['stock_total'] ?? 0))
                );
            }
        }

        return 'You currently have ' . $this->number($stockTotal) . ' eggs across ' . $this->number($skuTotal) . ' tracked SKU(s) in your visible inventory.';
    }

    /**
     * @param array<int, mixed> $rows
     */
    private function productionDirectAnswer(array $rows): string
    {
        if ($rows === []) {
            return 'I do not see production trend records in your visible scope yet.';
        }

        $first = is_array($rows[0] ?? null) ? $rows[0] : [];
        $last = is_array($rows[array_key_last($rows)] ?? null) ? $rows[array_key_last($rows)] : [];
        $firstEggs = (int) ($first['total_eggs'] ?? 0);
        $lastEggs = (int) ($last['total_eggs'] ?? 0);
        $direction = $lastEggs <=> $firstEggs;
        $trend = match ($direction) {
            1 => 'up',
            -1 => 'down',
            default => 'flat',
        };

        return sprintf(
            'Production is %s across the visible trend window: %s eggs on %s versus %s eggs on %s.',
            $trend,
            $this->number($lastEggs),
            $last['date'] ?? '-',
            $this->number($firstEggs),
            $first['date'] ?? '-'
        );
    }

    /**
     * @param array<int, mixed> $rows
     * @return array<string, mixed>
     */
    private function maxRow(array $rows, string $key): array
    {
        return array_reduce($rows, static function (?array $carry, mixed $row) use ($key): array {
            if (!is_array($row)) {
                return $carry ?? [];
            }

            if ($carry === null || $carry === []) {
                return $row;
            }

            return (float) ($row[$key] ?? 0) > (float) ($carry[$key] ?? 0) ? $row : $carry;
        }) ?? [];
    }

    /**
     * @param array<int, mixed> $rows
     * @return array<string, mixed>
     */
    private function minRow(array $rows, string $key): array
    {
        return array_reduce($rows, static function (?array $carry, mixed $row) use ($key): array {
            if (!is_array($row)) {
                return $carry ?? [];
            }

            if ($carry === null || $carry === []) {
                return $row;
            }

            return (float) ($row[$key] ?? 0) < (float) ($carry[$key] ?? 0) ? $row : $carry;
        }) ?? [];
    }

    private function number(int $value): string
    {
        return number_format($value);
    }

    private function metric(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'N/A';
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
