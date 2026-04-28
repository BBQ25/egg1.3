<?php

namespace App\Services\AiChat;

use App\Support\AppTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class InventoryForecastService
{
    /**
     * @param array<int, int> $farmIds
     * @return array<string, mixed>
     */
    public function build(array $farmIds, string $message): array
    {
        $farmIds = $this->normalizeIds($farmIds);
        $horizon = $this->resolveHorizon($message);
        $currentStock = $this->currentStockTotal($farmIds);
        $series = $this->dailyNetMovementSeries($farmIds);
        $values = array_values($series);
        $dataPoints = count(array_filter($values, static fn (float $value): bool => abs($value) > 0.00001));
        $insufficientData = count($values) < 7 || $dataPoints < 4;

        $algorithms = [];
        foreach (['sgma', 'sma', 'wma', 'exponential_smoothing', 'linear_trend'] as $algorithm) {
            $dailyNetForecast = $this->predict($algorithm, $values);
            $metrics = $this->backtest($algorithm, $values);

            $algorithms[] = [
                'key' => $algorithm,
                'name' => $this->algorithmName($algorithm),
                'mae' => $metrics['mae'],
                'mse' => $metrics['mse'],
                'rmse' => $metrics['rmse'],
                'daily_net_forecast' => round($dailyNetForecast, 2),
                'horizon_net_forecast' => round($dailyNetForecast * $horizon['days'], 2),
                'projected_stock' => $insufficientData
                    ? null
                    : max(0, (int) round($currentStock + ($dailyNetForecast * $horizon['days']))),
                'confidence' => $this->confidenceLabel(count($values), $metrics['mae']),
            ];
        }

        usort($algorithms, static function (array $left, array $right): int {
            $leftMae = $left['mae'];
            $rightMae = $right['mae'];

            if ($leftMae === null && $rightMae === null) {
                return 0;
            }

            if ($leftMae === null) {
                return 1;
            }

            if ($rightMae === null) {
                return -1;
            }

            return $leftMae <=> $rightMae;
        });

        return [
            'horizon' => $horizon,
            'current_stock' => $currentStock,
            'series_start' => array_key_first($series),
            'series_end' => array_key_last($series),
            'series_days' => count($series),
            'non_zero_data_points' => $dataPoints,
            'insufficient_data' => $insufficientData,
            'insufficient_reason' => $insufficientData
                ? 'At least 7 calendar days with 4 non-zero movement days are needed for reliable ranking.'
                : null,
            'algorithms' => array_values($algorithms),
        ];
    }

    /**
     * @param array<int, int> $farmIds
     */
    private function currentStockTotal(array $farmIds): int
    {
        if ($farmIds === []) {
            return 0;
        }

        return (int) DB::table('egg_items')
            ->whereIn('farm_id', $farmIds)
            ->sum('current_stock');
    }

    /**
     * @param array<int, int> $farmIds
     * @return array<string, float>
     */
    private function dailyNetMovementSeries(array $farmIds): array
    {
        if ($farmIds === []) {
            return [];
        }

        $baseQuery = DB::table('stock_movements as movements')
            ->join('egg_items as items', 'items.id', '=', 'movements.item_id')
            ->whereIn('items.farm_id', $farmIds);

        $latestMovementDate = (clone $baseQuery)->max('movements.movement_date');
        $end = $latestMovementDate
            ? CarbonImmutable::parse((string) $latestMovementDate, AppTimezone::current())->startOfDay()
            : AppTimezone::now()->startOfDay();
        $start = $end->subDays(89);

        $rows = (clone $baseQuery)
            ->whereBetween('movements.movement_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('DATE(movements.movement_date) as movement_day')
            ->selectRaw("
                SUM(
                    CASE
                        WHEN movements.movement_type = 'IN' THEN movements.quantity
                        WHEN movements.movement_type = 'OUT' THEN -movements.quantity
                        ELSE movements.stock_after - movements.stock_before
                    END
                ) as net_quantity
            ")
            ->groupByRaw('DATE(movements.movement_date)')
            ->pluck('net_quantity', 'movement_day')
            ->map(static fn ($value): float => (float) $value)
            ->all();

        $series = [];
        for ($date = $start; $date <= $end; $date = $date->addDay()) {
            $key = $date->toDateString();
            $series[$key] = round((float) ($rows[$key] ?? 0), 2);
        }

        return $series;
    }

    /**
     * @return array{label:string,days:int}
     */
    private function resolveHorizon(string $message): array
    {
        $lower = mb_strtolower($message);

        if (preg_match('/\b(month|monthly|30\s*days?)\b/', $lower)) {
            return ['label' => 'month', 'days' => 30];
        }

        if (preg_match('/\b(week|weekly|7\s*days?)\b/', $lower)) {
            return ['label' => 'week', 'days' => 7];
        }

        return ['label' => 'day', 'days' => 1];
    }

    /**
     * @param array<int, float|int> $values
     */
    private function predict(string $algorithm, array $values): float
    {
        $values = array_map(static fn ($value): float => (float) $value, $values);
        if ($values === []) {
            return 0.0;
        }

        return match ($algorithm) {
            'sgma' => $this->predictSgma($values),
            'sma' => $this->average(array_slice($values, -7)),
            'wma' => $this->predictWeightedMovingAverage($values),
            'exponential_smoothing' => $this->predictExponentialSmoothing($values),
            'linear_trend' => $this->predictLinearTrend($values),
            default => 0.0,
        };
    }

    /**
     * Signed geometric mean over recent movement magnitudes.
     *
     * @param array<int, float> $values
     */
    private function predictSgma(array $values): float
    {
        $recent = array_slice($values, -7);
        $nonZero = array_values(array_filter($recent, static fn (float $value): bool => abs($value) > 0.00001));

        if ($nonZero === []) {
            return 0.0;
        }

        $sign = $this->average($nonZero) < 0 ? -1 : 1;
        $logs = array_map(static fn (float $value): float => log(abs($value) + 1), $nonZero);

        return $sign * (exp($this->average($logs)) - 1);
    }

    /**
     * @param array<int, float> $values
     */
    private function predictWeightedMovingAverage(array $values): float
    {
        $recent = array_slice($values, -7);
        $weightTotal = 0;
        $weightedTotal = 0.0;

        foreach (array_values($recent) as $index => $value) {
            $weight = $index + 1;
            $weightTotal += $weight;
            $weightedTotal += $value * $weight;
        }

        return $weightTotal > 0 ? $weightedTotal / $weightTotal : 0.0;
    }

    /**
     * @param array<int, float> $values
     */
    private function predictExponentialSmoothing(array $values): float
    {
        $alpha = 0.4;
        $level = (float) ($values[0] ?? 0);

        foreach (array_slice($values, 1) as $value) {
            $level = ($alpha * $value) + ((1 - $alpha) * $level);
        }

        return $level;
    }

    /**
     * @param array<int, float> $values
     */
    private function predictLinearTrend(array $values): float
    {
        $recent = array_values(array_slice($values, -14));
        $count = count($recent);

        if ($count < 2) {
            return (float) ($recent[0] ?? 0);
        }

        $sumX = 0.0;
        $sumY = 0.0;
        $sumXY = 0.0;
        $sumXX = 0.0;

        foreach ($recent as $index => $value) {
            $x = $index + 1;
            $sumX += $x;
            $sumY += $value;
            $sumXY += $x * $value;
            $sumXX += $x * $x;
        }

        $denominator = ($count * $sumXX) - ($sumX * $sumX);
        if (abs($denominator) < 0.00001) {
            return $this->average($recent);
        }

        $slope = (($count * $sumXY) - ($sumX * $sumY)) / $denominator;
        $intercept = ($sumY - ($slope * $sumX)) / $count;

        return $intercept + ($slope * ($count + 1));
    }

    /**
     * @param array<int, float> $values
     * @return array{mae:?float,mse:?float,rmse:?float}
     */
    private function backtest(string $algorithm, array $values): array
    {
        if (count($values) < 7) {
            return ['mae' => null, 'mse' => null, 'rmse' => null];
        }

        $absoluteErrors = [];
        $squaredErrors = [];

        for ($index = 5, $total = count($values); $index < $total; $index++) {
            $history = array_slice($values, 0, $index);
            $prediction = $this->predict($algorithm, $history);
            $actual = (float) $values[$index];
            $error = $prediction - $actual;

            $absoluteErrors[] = abs($error);
            $squaredErrors[] = $error * $error;
        }

        if ($absoluteErrors === []) {
            return ['mae' => null, 'mse' => null, 'rmse' => null];
        }

        $mse = $this->average($squaredErrors);

        return [
            'mae' => round($this->average($absoluteErrors), 2),
            'mse' => round($mse, 2),
            'rmse' => round(sqrt($mse), 2),
        ];
    }

    /**
     * @param array<int, float> $values
     */
    private function average(array $values): float
    {
        return $values === [] ? 0.0 : array_sum($values) / count($values);
    }

    private function algorithmName(string $algorithm): string
    {
        return match ($algorithm) {
            'sgma' => 'SGMA',
            'sma' => 'Simple Moving Average',
            'wma' => 'Weighted Moving Average',
            'exponential_smoothing' => 'Exponential Smoothing',
            'linear_trend' => 'Linear Trend Regression',
            default => $algorithm,
        };
    }

    private function confidenceLabel(int $seriesDays, ?float $mae): string
    {
        if ($seriesDays < 7 || $mae === null) {
            return 'Insufficient history';
        }

        if ($seriesDays < 21) {
            return 'Low';
        }

        if ($seriesDays < 45) {
            return 'Medium';
        }

        return 'High';
    }

    /**
     * @param array<int, int|string> $ids
     * @return array<int, int>
     */
    private function normalizeIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn ($id): int => (int) $id,
            $ids
        ), static fn (int $id): bool => $id > 0)));
    }
}
