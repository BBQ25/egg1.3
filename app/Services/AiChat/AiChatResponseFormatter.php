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
        $data = $context['data'] ?? [];

        return match ($intent) {
            'refusal' => (string) ($data['message'] ?? 'I cannot access that data with your current account role.'),
            'action_draft' => $this->formatActionDraft($data),
            'inventory_forecast' => $this->formatForecast($data),
            'best_selling_eggs' => $this->formatBestSelling($data),
            'price_monitoring' => $this->formatPriceMonitoring($data),
            'batch_summary' => $this->formatBatchSummary($data),
            'production_trend' => $this->formatProductionTrend($data),
            'low_stock' => $this->formatInventory($data, true),
            default => $this->formatInventory($data, false),
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    private function formatForecast(array $data): string
    {
        $horizon = $data['horizon']['label'] ?? 'day';
        $currentStock = (int) ($data['current_stock'] ?? 0);
        $lines = [
            "Inventory forecast for the next {$horizon}",
            "Current scoped stock: {$this->number($currentStock)} eggs",
            "History window: " . ($data['series_start'] ?? 'N/A') . ' to ' . ($data['series_end'] ?? 'N/A'),
            "Movement data points: " . $this->number((int) ($data['non_zero_data_points'] ?? 0)),
        ];

        if (!empty($data['insufficient_data'])) {
            $lines[] = 'Reliability note: ' . ($data['insufficient_reason'] ?? 'Insufficient movement history.');
        }

        $lines[] = '';
        $lines[] = '| Algorithm | MAE | RMSE | Daily net | Projected stock | Confidence |';
        $lines[] = '| --- | ---: | ---: | ---: | ---: | --- |';

        foreach (($data['algorithms'] ?? []) as $row) {
            $lines[] = sprintf(
                '| %s | %s | %s | %s | %s | %s |',
                $row['name'] ?? '-',
                $this->metric($row['mae'] ?? null),
                $this->metric($row['rmse'] ?? null),
                $this->metric($row['daily_net_forecast'] ?? null),
                $row['projected_stock'] === null ? 'N/A' : $this->number((int) $row['projected_stock']),
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
        $lines = [
            'Best-selling eggs proxy',
            (string) ($data['basis'] ?? 'Based on OUT stock movements.'),
            '',
            '| Rank | Egg type | Size | Quantity out | Revenue proxy |',
            '| ---: | --- | --- | ---: | ---: |',
        ];

        foreach (($data['rows'] ?? []) as $index => $row) {
            $lines[] = sprintf(
                '| %d | %s | %s | %s | %s |',
                $index + 1,
                $row['egg_type'] ?? '-',
                $row['size_class'] ?? '-',
                $this->number((int) ($row['quantity_out'] ?? 0)),
                $this->money((float) ($row['revenue_proxy'] ?? 0))
            );
        }

        if (($data['rows'] ?? []) === []) {
            $lines[] = '| - | No outbound stock movements found | - | 0 | 0.00 |';
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function formatPriceMonitoring(array $data): string
    {
        $lines = [
            'Price monitoring summary',
            '',
            '| Size class | Stock | Farms | Min price | Max price |',
            '| --- | ---: | ---: | ---: | ---: |',
        ];

        foreach (($data['size_prices'] ?? []) as $row) {
            $lines[] = sprintf(
                '| %s | %s | %s | %s | %s |',
                $row['size_class'] ?? '-',
                $this->number((int) ($row['stock_total'] ?? 0)),
                $this->number((int) ($row['farm_count'] ?? 0)),
                $this->money((float) ($row['min_price'] ?? 0)),
                $this->money((float) ($row['max_price'] ?? 0))
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function formatInventory(array $data, bool $lowStockOnly): string
    {
        $summary = $data['summary'] ?? [];
        $lines = [
            $lowStockOnly ? 'Low-stock inventory summary' : 'Inventory summary',
            'Scoped stock: ' . $this->number((int) ($summary['stock_total'] ?? 0)) . ' eggs',
            'Tracked SKUs: ' . $this->number((int) ($summary['sku_total'] ?? 0)),
            'Low-stock SKUs: ' . $this->number((int) ($summary['low_stock_total'] ?? 0)),
            '',
            '| Size class | Items | Stock |',
            '| --- | ---: | ---: |',
        ];

        foreach (($data['by_size'] ?? []) as $row) {
            $lines[] = sprintf(
                '| %s | %s | %s |',
                $row['size_class'] ?? '-',
                $this->number((int) ($row['item_count'] ?? 0)),
                $this->number((int) ($row['stock_total'] ?? 0))
            );
        }

        if (!empty($data['low_stock_items'])) {
            $lines[] = '';
            $lines[] = 'Items at or below reorder level:';
            foreach ($data['low_stock_items'] as $row) {
                $lines[] = sprintf(
                    '- %s / %s: %s eggs, reorder level %s',
                    $row['farm_name'] ?? '-',
                    $row['item_code'] ?? '-',
                    $this->number((int) ($row['current_stock'] ?? 0)),
                    $this->number((int) ($row['reorder_level'] ?? 0))
                );
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function formatBatchSummary(array $data): string
    {
        $lines = [
            'Batch monitoring summary',
            'Window: ' . ($data['window'] ?? 'last 30 days'),
            '',
            '| Batch | Farm | Device | Status | Eggs | Rejects | Avg weight |',
            '| --- | --- | --- | --- | ---: | ---: | ---: |',
        ];

        foreach (($data['batches'] ?? []) as $row) {
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

        if (($data['batches'] ?? []) === []) {
            $lines[] = '| - | No visible batch records found | - | - | 0 | 0 | 0 |';
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function formatProductionTrend(array $data): string
    {
        $lines = [
            'Production trend',
            '',
            '| Date | Eggs | Rejects | Avg weight |',
            '| --- | ---: | ---: | ---: |',
        ];

        foreach (($data['daily_rows'] ?? []) as $row) {
            $lines[] = sprintf(
                '| %s | %s | %s | %s |',
                $row['date'] ?? '-',
                $this->number((int) ($row['total_eggs'] ?? 0)),
                $this->number((int) ($row['reject_count'] ?? 0)),
                $this->metric($row['avg_weight_grams'] ?? null)
            );
        }

        if (($data['daily_rows'] ?? []) === []) {
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
            'Draft prepared only. No database record was changed.',
            'Request: ' . (string) ($data['request'] ?? 'Action request'),
            'Suggested destination: ' . (string) ($data['target_label'] ?? 'Review in the appropriate page'),
            'Draft summary: ' . (string) ($data['summary'] ?? 'Review the request before taking action.'),
        ]);
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
