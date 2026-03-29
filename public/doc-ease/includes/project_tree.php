<?php
// Superadmin-only project tree helpers.

if (!function_exists('project_tree_default_excludes')) {
    function project_tree_default_excludes() {
        return [
            '.git',
            'node_modules',
            'vendor',
            '__pycache__',
        ];
    }
}

if (!function_exists('project_tree_clamp_int')) {
    function project_tree_clamp_int($value, $minValue, $maxValue) {
        $value = (int) $value;
        $minValue = (int) $minValue;
        $maxValue = (int) $maxValue;
        if ($value < $minValue) return $minValue;
        if ($value > $maxValue) return $maxValue;
        return $value;
    }
}

if (!function_exists('project_tree_sorted_entries')) {
    function project_tree_sorted_entries($directory) {
        $directory = (string) $directory;
        $items = @scandir($directory);
        if (!is_array($items)) return [[], false];

        $entries = [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $entries[] = $item;
        }

        usort($entries, function ($a, $b) use ($directory) {
            $aPath = $directory . DIRECTORY_SEPARATOR . $a;
            $bPath = $directory . DIRECTORY_SEPARATOR . $b;
            $aDir = is_dir($aPath) && !is_link($aPath);
            $bDir = is_dir($bPath) && !is_link($bPath);
            if ($aDir !== $bDir) return $aDir ? -1 : 1;
            return strcasecmp((string) $a, (string) $b);
        });

        return [$entries, true];
    }
}

if (!function_exists('project_tree_collect_lines')) {
    function project_tree_collect_lines($rootPath, $maxDepth = 3, $maxItemsPerDir = 30, array $excludeDirs = []) {
        $rootPath = (string) $rootPath;
        $maxDepth = project_tree_clamp_int($maxDepth, 1, 8);
        $maxItemsPerDir = project_tree_clamp_int($maxItemsPerDir, 5, 300);

        if ($rootPath === '' || !is_dir($rootPath)) {
            return [[
                'text' => '[invalid project root]',
                'depth' => 0,
                'is_dir' => false,
                'kind' => 'error',
            ]];
        }

        $excludeSet = [];
        foreach ($excludeDirs as $name) {
            $name = trim((string) $name);
            if ($name !== '') $excludeSet[strtolower($name)] = true;
        }

        $rootName = basename(rtrim($rootPath, '/\\'));
        if ($rootName === '') $rootName = $rootPath;

        $lines = [[
            'text' => $rootName . '/',
            'depth' => 0,
            'is_dir' => true,
            'kind' => 'normal',
        ]];

        $walk = function ($directory, $prefix, $depth) use (&$walk, &$lines, $maxDepth, $maxItemsPerDir, $excludeSet) {
            [$entries, $ok] = project_tree_sorted_entries($directory);
            if (!$ok) {
                $lines[] = [
                    'text' => $prefix . '└── [permission denied]',
                    'depth' => $depth + 1,
                    'is_dir' => false,
                    'kind' => 'warn',
                ];
                return;
            }

            $filtered = [];
            foreach ($entries as $entryName) {
                $entryPath = $directory . DIRECTORY_SEPARATOR . $entryName;
                if (is_dir($entryPath) && !is_link($entryPath)) {
                    if (isset($excludeSet[strtolower($entryName)])) continue;
                }
                $filtered[] = $entryName;
            }

            if (count($filtered) === 0) return;

            if ($depth >= $maxDepth) {
                $lines[] = [
                    'text' => $prefix . '└── ... (depth limit, ' . count($filtered) . ' items)',
                    'depth' => $depth + 1,
                    'is_dir' => false,
                    'kind' => 'meta',
                ];
                return;
            }

            $omitted = 0;
            if (count($filtered) > $maxItemsPerDir) {
                $omitted = count($filtered) - $maxItemsPerDir;
                $filtered = array_slice($filtered, 0, $maxItemsPerDir);
            }

            $total = count($filtered);
            for ($i = 0; $i < $total; $i++) {
                $entryName = (string) $filtered[$i];
                $entryPath = $directory . DIRECTORY_SEPARATOR . $entryName;
                $isLast = ($i === $total - 1) && ($omitted === 0);
                $connector = $isLast ? '└── ' : '├── ';
                $isDir = is_dir($entryPath) && !is_link($entryPath);

                $lines[] = [
                    'text' => $prefix . $connector . $entryName . ($isDir ? '/' : ''),
                    'depth' => $depth + 1,
                    'is_dir' => $isDir,
                    'kind' => 'normal',
                ];

                if ($isDir) {
                    $nextPrefix = $prefix . ($isLast ? '    ' : '│   ');
                    $walk($entryPath, $nextPrefix, $depth + 1);
                }
            }

            if ($omitted > 0) {
                $lines[] = [
                    'text' => $prefix . '└── ... (' . $omitted . ' more items)',
                    'depth' => $depth + 1,
                    'is_dir' => false,
                    'kind' => 'meta',
                ];
            }
        };

        $walk($rootPath, '', 0);
        return $lines;
    }
}

