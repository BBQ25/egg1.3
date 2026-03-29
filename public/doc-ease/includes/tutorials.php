<?php

if (!function_exists('tutorial_h')) {
    function tutorial_h($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('tutorial_asset_url')) {
    /**
     * Resolve a tutorial asset URL with cache-busting.
     * Falls back to a placeholder image when the asset is missing.
     *
     * @param string $relativePath Path relative to repo root (no leading slash)
     * @return string URL-safe relative path with ?v=
     */
    function tutorial_asset_url($relativePath) {
        $relativePath = ltrim((string) $relativePath, '/');
        $root = __DIR__ . '/..';

        $abs = $root . '/' . $relativePath;
        if (!is_file($abs)) {
            $relativePath = 'assets/images/tutorials/placeholder.svg';
            $abs = $root . '/' . $relativePath;
        }

        $v = '1';
        if (is_file($abs)) {
            $v = (string) filemtime($abs);
        }

        return $relativePath . '?v=' . rawurlencode($v);
    }
}

if (!function_exists('tutorial_shot')) {
    /**
     * Render a screenshot figure that opens the full image in a new tab.
     *
     * @param string $relativePath Path relative to repo root (no leading slash)
     * @param string $alt Alt text
     * @param string $caption Caption text
     * @return string HTML
     */
    function tutorial_shot($relativePath, $alt, $caption = '') {
        $src = tutorial_asset_url($relativePath);
        $alt = (string) $alt;
        $caption = trim((string) $caption);

        $capHtml = '';
        if ($caption !== '') {
            $capHtml = '<figcaption class="text-muted small">' . tutorial_h($caption) . '</figcaption>';
        }

        return
            '<figure class="tutorial-shot">' .
                '<a href="' . tutorial_h($src) . '" target="_blank" rel="noopener">' .
                    '<img class="img-fluid rounded border shadow-sm" loading="lazy" src="' . tutorial_h($src) . '" alt="' . tutorial_h($alt) . '">' .
                '</a>' .
                $capHtml .
            '</figure>';
    }
}

