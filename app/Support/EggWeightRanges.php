<?php

namespace App\Support;

use App\Models\AppSetting;
use Throwable;

final class EggWeightRanges
{
    public const SETTING_KEY = 'egg_weight_ranges';

    /**
     * @var array<int, array{slug:string,class:string,label:string,min:float,max:float}>
     */
    private const DEFAULTS = [
        ['slug' => 'reject', 'class' => EggSizeClass::REJECT, 'label' => 'Reject', 'min' => 0.00, 'max' => 34.99],
        ['slug' => 'peewee', 'class' => EggSizeClass::PEEWEE, 'label' => 'Peewee', 'min' => 35.00, 'max' => 44.99],
        ['slug' => 'pullet', 'class' => EggSizeClass::PULLET, 'label' => 'Pullet', 'min' => 45.00, 'max' => 49.99],
        ['slug' => 'small', 'class' => EggSizeClass::SMALL, 'label' => 'Small', 'min' => 50.00, 'max' => 54.99],
        ['slug' => 'medium', 'class' => EggSizeClass::MEDIUM, 'label' => 'Medium', 'min' => 55.00, 'max' => 59.99],
        ['slug' => 'large', 'class' => EggSizeClass::LARGE, 'label' => 'Large', 'min' => 60.00, 'max' => 64.99],
        ['slug' => 'extra_large', 'class' => EggSizeClass::EXTRA_LARGE, 'label' => 'Extra-Large', 'min' => 65.00, 'max' => 69.99],
        ['slug' => 'jumbo', 'class' => EggSizeClass::JUMBO, 'label' => 'Jumbo', 'min' => 70.00, 'max' => 1000.00],
    ];

    /**
     * @return array<int, array{slug:string,class:string,label:string,min:float,max:float}>
     */
    public static function definitions(): array
    {
        return self::DEFAULTS;
    }

    /**
     * @return array<string, array{slug:string,class:string,label:string,min:string,max:string}>
     */
    public static function current(): array
    {
        $stored = null;

        try {
            $value = AppSetting::query()
                ->where('setting_key', self::SETTING_KEY)
                ->value('setting_value');

            $decoded = json_decode($value ?? '[]', true);
            $stored = is_array($decoded) ? $decoded : null;
        } catch (Throwable) {
            $stored = null;
        }

        return self::normalize($stored);
    }

    /**
     * @param array<string, mixed> $ranges
     * @return array<string, string>
     */
    public static function configurationErrors(array $ranges): array
    {
        $normalized = self::normalize($ranges);
        $entries = array_values($normalized);
        $errors = [];

        for ($index = 0; $index < count($entries); $index++) {
            $current = $entries[$index];
            $currentMin = (float) $current['min'];
            $currentMax = (float) $current['max'];

            if ($currentMin > $currentMax) {
                $errors["egg_weight_ranges.{$current['slug']}.min"] = "{$current['label']} minimum must be less than or equal to its maximum.";
                $errors["egg_weight_ranges.{$current['slug']}.max"] = "{$current['label']} maximum must be greater than or equal to its minimum.";
            }

            if ($index === 0) {
                continue;
            }

            $previous = $entries[$index - 1];
            $previousMax = (float) $previous['max'];

            if ($currentMin <= $previousMax) {
                $errors["egg_weight_ranges.{$current['slug']}.min"] = "{$current['label']} minimum must be greater than {$previous['label']} maximum.";
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $ranges
     * @return array<string, array{slug:string,class:string,label:string,min:string,max:string}>
     */
    public static function set(array $ranges): array
    {
        $normalized = self::normalize($ranges);
        $payload = [];

        foreach ($normalized as $slug => $entry) {
            $payload[$slug] = [
                'min' => $entry['min'],
                'max' => $entry['max'],
            ];
        }

        AppSetting::query()->updateOrCreate(
            ['setting_key' => self::SETTING_KEY],
            ['setting_value' => json_encode($payload)]
        );

        return $normalized;
    }

    public static function classify(float $weightGrams): string
    {
        foreach (self::current() as $entry) {
            if ($weightGrams >= (float) $entry['min'] && $weightGrams <= (float) $entry['max']) {
                return $entry['class'];
            }
        }

        return EggSizeClass::REJECT;
    }

    /**
     * @param array<string, mixed>|null $stored
     * @return array<string, array{slug:string,class:string,label:string,min:string,max:string}>
     */
    private static function normalize(?array $stored): array
    {
        $normalized = [];

        foreach (self::DEFAULTS as $definition) {
            $slug = $definition['slug'];
            $storedEntry = is_array($stored[$slug] ?? null) ? $stored[$slug] : [];
            $min = self::normalizeBoundary($storedEntry['min'] ?? $definition['min']);
            $max = self::normalizeBoundary($storedEntry['max'] ?? $definition['max']);

            $normalized[$slug] = [
                'slug' => $slug,
                'class' => $definition['class'],
                'label' => $definition['label'],
                'min' => number_format($min, 2, '.', ''),
                'max' => number_format($max, 2, '.', ''),
            ];
        }

        return $normalized;
    }

    private static function normalizeBoundary(mixed $value): float
    {
        $normalized = is_numeric($value) ? (float) $value : 0.0;

        return round(max(0, $normalized), 2);
    }
}
