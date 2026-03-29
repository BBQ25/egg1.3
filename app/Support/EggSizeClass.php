<?php

namespace App\Support;

final class EggSizeClass
{
    public const REJECT = 'Reject';
    public const PEEWEE = 'Peewee';
    public const PULLET = 'Pullet';
    public const SMALL = 'Small';
    public const MEDIUM = 'Medium';
    public const LARGE = 'Large';
    public const EXTRA_LARGE = 'Extra-Large';
    public const JUMBO = 'Jumbo';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return [
            self::REJECT,
            self::PEEWEE,
            self::PULLET,
            self::SMALL,
            self::MEDIUM,
            self::LARGE,
            self::EXTRA_LARGE,
            self::JUMBO,
        ];
    }
}
