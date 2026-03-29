<?php
// Subject color helpers: stable, soft/pastel colors for easy identification.
// Intentionally does not require DB schema changes.

if (!function_exists('subject_color_palette')) {
    function subject_color_palette() {
        // bg: light/pastel fill, border: slightly stronger edge, text: dark for contrast
        return [
            ['bg' => '#E8F5E9', 'border' => '#A5D6A7', 'text' => '#1B5E20'], // green
            ['bg' => '#E3F2FD', 'border' => '#90CAF9', 'text' => '#0D47A1'], // blue
            ['bg' => '#FFF3E0', 'border' => '#FFCC80', 'text' => '#7A2E00'], // orange/brown
            ['bg' => '#F3E5F5', 'border' => '#CE93D8', 'text' => '#4A148C'], // purple
            ['bg' => '#E0F7FA', 'border' => '#80DEEA', 'text' => '#006064'], // cyan
            ['bg' => '#FCE4EC', 'border' => '#F48FB1', 'text' => '#880E4F'], // pink
            ['bg' => '#E8EAF6', 'border' => '#9FA8DA', 'text' => '#1A237E'], // indigo
            ['bg' => '#F1F8E9', 'border' => '#C5E1A5', 'text' => '#33691E'], // lime
            ['bg' => '#FFEBEE', 'border' => '#EF9A9A', 'text' => '#7F0E0E'], // red
            ['bg' => '#E0F2F1', 'border' => '#80CBC4', 'text' => '#004D40'], // teal
            ['bg' => '#FFFDE7', 'border' => '#FFF59D', 'text' => '#5D4C00'], // yellow
            ['bg' => '#ECEFF1', 'border' => '#B0BEC5', 'text' => '#263238'], // blue-grey
        ];
    }
}

if (!function_exists('subject_color_for_key')) {
    /**
     * Returns ['bg'=>..., 'border'=>..., 'text'=>...] for a stable key (e.g., subject_code).
     */
    function subject_color_for_key($key) {
        $key = trim((string) $key);
        $palette = subject_color_palette();
        $n = count($palette);
        if ($n <= 0) return ['bg' => '#f2f2f7', 'border' => '#dee2e6', 'text' => '#343a40'];

        // crc32 can be signed; convert to unsigned for stable modulo.
        $hash = sprintf('%u', crc32($key !== '' ? $key : 'subject'));
        $idx = (int) ($hash % $n);
        return $palette[$idx];
    }
}

if (!function_exists('subject_color_event_props')) {
    /**
     * FullCalendar event colors.
     */
    function subject_color_event_props($key) {
        $c = subject_color_for_key($key);
        return [
            'backgroundColor' => $c['bg'],
            'borderColor' => $c['border'],
            'textColor' => $c['text'],
        ];
    }
}

if (!function_exists('subject_color_style_attr')) {
    /**
     * CSS variable style attribute for pills/chips.
     */
    function subject_color_style_attr($key) {
        $c = subject_color_for_key($key);
        $bg = htmlspecialchars((string) $c['bg'], ENT_QUOTES, 'UTF-8');
        $border = htmlspecialchars((string) $c['border'], ENT_QUOTES, 'UTF-8');
        $text = htmlspecialchars((string) $c['text'], ENT_QUOTES, 'UTF-8');
        return "style=\"--subj-bg: {$bg}; --subj-border: {$border}; --subj-text: {$text};\"";
    }
}

