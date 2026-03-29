<?php

namespace App\Enums;

enum UserRole: string
{
    case ADMIN = 'ADMIN';
    case OWNER = 'OWNER';
    case WORKER = 'WORKER';
    case CUSTOMER = 'CUSTOMER';

    public function label(): string
    {
        return match ($this) {
            self::ADMIN => 'Admin',
            self::OWNER => 'Poultry Owner',
            self::WORKER => 'Poultry Staff',
            self::CUSTOMER => 'Customer',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        $labels = [];

        foreach (self::cases() as $case) {
            $labels[$case->value] = $case->label();
        }

        return $labels;
    }
}
