<?php

namespace App\Support;

use App\Models\AppSetting;
use Throwable;

class UiFont
{
    public const SETTING_KEY = 'ui_font_style';

    public const DEFAULT = 'figtree';

    /**
     * @var array<string, array{label: string, css: string|null, stack: string}>
     */
    private const MAP = [
        'figtree' => [
            'label' => 'Figtree',
            'css' => 'figtree.css',
            'stack' => "'Figtree', -apple-system, BlinkMacSystemFont, 'Segoe UI', Oxygen, Ubuntu, Cantarell, 'Fira Sans', 'Droid Sans', 'Helvetica Neue', sans-serif",
        ],
        'poppins' => [
            'label' => 'Poppins',
            'css' => 'poppins.css',
            'stack' => "'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Oxygen, Ubuntu, Cantarell, 'Fira Sans', 'Droid Sans', 'Helvetica Neue', sans-serif",
        ],
        'cambria' => [
            'label' => 'Cambria',
            'css' => null,
            'stack' => "Cambria, Constantia, Cochin, Georgia, Times, 'Times New Roman', serif",
        ],
    ];

    private static ?string $cachedCurrent = null;

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $out = [];

        foreach (self::MAP as $key => $meta) {
            $out[$key] = $meta['label'];
        }

        return $out;
    }

    public static function resolve(?string $key): string
    {
        $normalized = strtolower(trim((string) $key));

        return array_key_exists($normalized, self::MAP) ? $normalized : self::DEFAULT;
    }

    public static function cssFile(string $key): ?string
    {
        $resolved = self::resolve($key);

        return self::MAP[$resolved]['css'];
    }

    public static function inlineCss(string $key): string
    {
        $resolved = self::resolve($key);
        $stack = self::MAP[$resolved]['stack'];

        return <<<CSS
:root,
[data-bs-theme='light'],
[data-bs-theme='dark'] {
  --bs-font-sans-serif: {$stack} !important;
  --bs-body-font-family: {$stack} !important;
  --bs-heading-font-family: {$stack} !important;
}

body,
h1, .h1, h2, .h2, h3, .h3, h4, .h4, h5, .h5, h6, .h6,
p, 
a, 
table, 
th, 
td,
legend,
.layout-menu,
.menu-link,
.menu-text,
.navbar,
.card,
.card-header,
.card-title,
.card-body,
.dropdown-menu,
.dropdown-item,
.form-control,
.form-select,
.form-label,
.form-check-label,
.custom-option,
.custom-option-header,
.custom-option-content,
.btn,
.badge,
.text-muted,
.text-body-secondary,
.text-primary,
.text-success,
.text-danger,
.text-warning,
.text-info,
.alert,
select,
option,
label {
  font-family: {$stack} !important;
}

.apexcharts-canvas,
.apexcharts-canvas svg text,
.apexcharts-legend-text,
.apexcharts-title-text,
.apexcharts-subtitle-text,
.apexcharts-datalabel,
.apexcharts-xaxis-label,
.apexcharts-yaxis-label,
.apexcharts-tooltip,
.apexcharts-tooltip * {
  font-family: {$stack} !important;
}
CSS;
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

        self::$cachedCurrent = self::resolve($value);

        return self::$cachedCurrent;
    }

    public static function set(string $key): string
    {
        $resolved = self::resolve($key);

        AppSetting::query()->updateOrCreate(
            ['setting_key' => self::SETTING_KEY],
            ['setting_value' => $resolved]
        );

        self::$cachedCurrent = $resolved;

        return $resolved;
    }
}
