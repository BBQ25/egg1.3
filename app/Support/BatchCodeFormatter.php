<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

class BatchCodeFormatter
{
    public const TIMEZONE = 'Asia/Manila';
    public const MAX_LENGTH = 80;

    public static function farmPrefix(?string $farmName): string
    {
        $ascii = Str::of((string) $farmName)
            ->ascii()
            ->upper()
            ->value();

        $normalized = preg_replace('/[^A-Z0-9]+/', '-', $ascii) ?? '';
        $normalized = preg_replace('/-+/', '-', $normalized) ?? '';
        $normalized = trim($normalized, '-');

        return $normalized !== '' ? $normalized : 'FARM';
    }

    public static function build(?string $farmName, CarbonInterface $observedAt, ?int $suffix = null): string
    {
        $timestamp = self::timestamp($observedAt);
        $suffixLabel = $suffix !== null && $suffix > 1 ? '-' . $suffix : '';
        $maxPrefixLength = max(1, self::MAX_LENGTH - strlen($timestamp) - strlen($suffixLabel) - 1);
        $prefix = substr(self::farmPrefix($farmName), 0, $maxPrefixLength);

        return $prefix . '-' . $timestamp . $suffixLabel;
    }

    public static function timestamp(CarbonInterface $observedAt): string
    {
        return CarbonImmutable::instance($observedAt)
            ->setTimezone(self::TIMEZONE)
            ->format('Ymd-His');
    }

    public static function toPhilippineTime(CarbonInterface|string $value): CarbonImmutable
    {
        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::instance($value)
                ->utc()
                ->setTimezone(self::TIMEZONE);
        }

        return CarbonImmutable::parse((string) $value, 'UTC')
            ->setTimezone(self::TIMEZONE);
    }

    public static function formatPhilippineDateTime(CarbonInterface|string|null $value, string $format = 'M j, Y g:i A'): string
    {
        if ($value === null || $value === '') {
            return 'N/A';
        }

        return self::toPhilippineTime($value)->format($format);
    }

    public static function matchesGeneratedPattern(string $batchCode): bool
    {
        $batchCode = trim($batchCode);

        if ($batchCode === '') {
            return false;
        }

        return str_starts_with($batchCode, 'AUTO-D')
            || (bool) preg_match('/^[A-Z0-9]+(?:-[A-Z0-9]+)*-\d{8}-\d{6}(?:-\d+)?$/', $batchCode);
    }
}
