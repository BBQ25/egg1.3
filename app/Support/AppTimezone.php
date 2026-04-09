<?php

namespace App\Support;

use App\Models\AppSetting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

final class AppTimezone
{
    public const SETTING_KEY = 'app_timezone';

    public const DEFAULT = 'Asia/Manila';

    /**
     * @var array<string, string>
     */
    private const OPTIONS = [
        'Asia/Manila' => 'Philippine Standard Time',
        'UTC' => 'Coordinated Universal Time',
    ];

    private static ?string $cachedCurrent = null;

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return self::OPTIONS;
    }

    public static function resolve(?string $timezone): string
    {
        $normalized = trim((string) $timezone);

        return array_key_exists($normalized, self::OPTIONS) ? $normalized : self::DEFAULT;
    }

    public static function label(?string $timezone = null): string
    {
        $resolved = self::resolve($timezone ?? self::current());

        return self::OPTIONS[$resolved];
    }

    public static function current(): string
    {
        if (self::$cachedCurrent !== null) {
            return self::$cachedCurrent;
        }

        $value = null;

        try {
            $value = AppSetting::query()
                ->where('setting_key', self::SETTING_KEY)
                ->value('setting_value');
        } catch (Throwable) {
            $value = null;
        }

        self::$cachedCurrent = self::resolve(is_string($value) ? $value : null);

        return self::$cachedCurrent;
    }

    public static function set(string $timezone): string
    {
        $resolved = self::resolve($timezone);

        AppSetting::query()->updateOrCreate(
            ['setting_key' => self::SETTING_KEY],
            ['setting_value' => $resolved]
        );

        self::$cachedCurrent = $resolved;

        return self::activate($resolved);
    }

    public static function activate(?string $timezone = null): string
    {
        $resolved = self::resolve($timezone ?? self::current());

        config(['app.timezone' => $resolved]);
        date_default_timezone_set($resolved);
        self::$cachedCurrent = $resolved;

        return $resolved;
    }

    public static function clearCache(): void
    {
        self::$cachedCurrent = null;
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::current());
    }

    public static function parseInbound(CarbonInterface|string $value): CarbonImmutable
    {
        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::instance($value)->setTimezone(self::current());
        }

        $stringValue = trim((string) $value);

        if (self::hasExplicitTimezone($stringValue)) {
            return CarbonImmutable::parse($stringValue)->setTimezone(self::current());
        }

        return CarbonImmutable::parse($stringValue, self::current())
            ->setTimezone(self::current());
    }

    public static function toAppTime(CarbonInterface|string|null $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::parseInbound($value);
    }

    public static function formatDateTime(CarbonInterface|string|null $value, string $format = 'M j, Y g:i A'): string
    {
        $resolved = self::toAppTime($value);

        return $resolved?->format($format) ?? 'N/A';
    }

    public static function formatDate(CarbonInterface|string|null $value, string $format = 'M j, Y'): string
    {
        $resolved = self::toAppTime($value);

        return $resolved?->format($format) ?? 'N/A';
    }

    public static function hasExplicitTimezone(string $value): bool
    {
        return (bool) preg_match('/(?:Z|[+-]\d{2}:\d{2}|[+-]\d{4})$/i', trim($value));
    }
}
