<?php

namespace App\Support;

use Illuminate\Support\Str;

class EggTrayFormatter
{
    public const FULL_TRAY_CAPACITY = 30;
    public const HALF_TRAY_CAPACITY = 15;

    /**
     * @return array{egg_count:int,full_trays:int,half_trays:int,loose_eggs:int}
     */
    public static function breakdown(int $eggCount): array
    {
        $eggCount = max(0, $eggCount);
        $fullTrays = intdiv($eggCount, self::FULL_TRAY_CAPACITY);
        $remainder = $eggCount % self::FULL_TRAY_CAPACITY;
        $halfTrays = 0;

        if ($remainder >= self::HALF_TRAY_CAPACITY) {
            $halfTrays = 1;
            $remainder -= self::HALF_TRAY_CAPACITY;
        }

        return [
            'egg_count' => $eggCount,
            'full_trays' => $fullTrays,
            'half_trays' => $halfTrays,
            'loose_eggs' => $remainder,
        ];
    }

    public static function trayLabel(int $eggCount): string
    {
        $breakdown = self::breakdown($eggCount);
        $segments = [];

        if ($breakdown['full_trays'] > 0) {
            $segments[] = $breakdown['full_trays'] . ' ' . Str::plural('tray', $breakdown['full_trays']);
        }

        if ($breakdown['half_trays'] > 0) {
            $segments[] = '1/2 tray';
        }

        if ($breakdown['loose_eggs'] > 0 || $segments === []) {
            $segments[] = $breakdown['loose_eggs'] . ' ' . Str::plural('egg', $breakdown['loose_eggs']);
        }

        return implode(' + ', $segments);
    }

    public static function eggCountLabel(int $eggCount): string
    {
        $eggCount = max(0, $eggCount);

        return number_format($eggCount) . ' ' . Str::plural('egg', $eggCount);
    }

    public static function summary(int $eggCount): string
    {
        return self::eggCountLabel($eggCount) . ' · ' . self::trayLabel($eggCount);
    }
}
