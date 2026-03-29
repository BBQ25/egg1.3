<?php

namespace App\Support;

use Illuminate\Support\Str;

class EggUid
{
    public const PREFIX = 'egg-';

    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^egg(?:[-_\s]+)?(.*)$/i', $trimmed, $matches) === 1) {
            $suffix = trim((string) ($matches[1] ?? ''));
        } else {
            $suffix = $trimmed;
        }

        $suffix = ltrim($suffix, "-_ \t\n\r\0\x0B");

        if ($suffix === '') {
            return self::PREFIX;
        }

        return Str::lower(self::PREFIX . $suffix);
    }

    public static function hasSuffix(?string $value): bool
    {
        $normalized = self::normalize($value);

        return $normalized !== null && $normalized !== self::PREFIX;
    }
}
