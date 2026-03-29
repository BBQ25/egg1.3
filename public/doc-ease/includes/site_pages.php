<?php

if (!function_exists('site_pages_h')) {
    function site_pages_h($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('site_pages_theme_palette')) {
    function site_pages_theme_palette() {
        return [
            '#3e60d5', // primary blue
            '#16a7e9', // info blue
            '#14b8a6', // teal
            '#47ad77', // green
            '#f97316', // orange
            '#f15776', // rose
            '#6b5eae', // indigo
        ];
    }
}

if (!function_exists('site_pages_theme_hex_to_rgb')) {
    function site_pages_theme_hex_to_rgb($hex) {
        $hex = ltrim(trim((string) $hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[a-f0-9]{6}$/i', $hex)) {
            return [62, 96, 213];
        }
        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }
}

if (!function_exists('site_pages_theme_lighten_hex')) {
    function site_pages_theme_lighten_hex($hex, $amount = 0.85) {
        $amount = (float) $amount;
        if ($amount < 0) $amount = 0;
        if ($amount > 1) $amount = 1;

        [$r, $g, $b] = site_pages_theme_hex_to_rgb($hex);
        $nr = (int) round($r + (255 - $r) * $amount);
        $ng = (int) round($g + (255 - $g) * $amount);
        $nb = (int) round($b + (255 - $b) * $amount);

        return sprintf('#%02x%02x%02x', $nr, $ng, $nb);
    }
}

if (!function_exists('site_pages_theme_color_by_index')) {
    function site_pages_theme_color_by_index($index) {
        $palette = site_pages_theme_palette();
        if (count($palette) === 0) $palette = ['#3e60d5'];
        $i = (int) $index;
        if ($i < 0) $i = 0;
        return $palette[$i % count($palette)];
    }
}

if (!function_exists('site_pages_theme_scheme')) {
    /**
     * Shared color scheme for Site Pages UI blocks.
     * Returns ['badge' => hex, 'panel_bg' => hex, 'panel_border' => hex].
     */
    function site_pages_theme_scheme($index, $panelBgLighten = 0.88, $panelBorderLighten = 0.70) {
        $base = site_pages_theme_color_by_index($index);
        return [
            'badge' => $base,
            'panel_bg' => site_pages_theme_lighten_hex($base, $panelBgLighten),
            'panel_border' => site_pages_theme_lighten_hex($base, $panelBorderLighten),
        ];
    }
}

if (!function_exists('site_pages_keys')) {
    function site_pages_keys() {
        return ['news', 'about', 'support', 'contact'];
    }
}

if (!function_exists('site_pages_defaults')) {
    function site_pages_defaults() {
        return [
            'news' => [
                'page_key' => 'news',
                'nav_label' => 'News',
                'page_title' => 'News & Feature Schedule | E-Record',
                'hero_title' => 'Doc Ease System News',
                'hero_subtitle' => 'Feature timeline, rollout schedules, and maintenance advisories.',
                'content_text' => "Doc Ease Feature Bulletin\nPublished: February 16, 2026\nCoverage Window: Second Semester AY 2025-2026\n\nIncluded Features (Live in the System)\nJanuary 15, 2026 - Accounts and Access Management\nScope: Student, Teacher, and Admin account handling; role-based access; approval flow support.\nBenefit: Controlled onboarding and cleaner account governance.\n\nJanuary 22, 2026 - Scheduling and Approval Workflows\nScope: Class schedules, enrollment approvals, and schedule approval checkpoints.\nBenefit: Reduced scheduling conflicts and clearer approval visibility.\n\nJanuary 29, 2026 - Attendance Boundary (Geo-fence)\nScope: Campus boundary setup for attendance check-ins with admin/superadmin controls.\nBenefit: More reliable in-campus attendance validation.\n\nFebruary 5, 2026 - Learning Materials and Assessment Modules\nScope: Teacher materials, assignment/quiz modules, and score processing.\nBenefit: Centralized classroom delivery and grading workflow.\n\nFebruary 12, 2026 - Notification Stream and Audit Visibility\nScope: In-app notification feed and role-sensitive activity context.\nBenefit: Faster follow-up and better accountability.\n\nFebruary 16, 2026 - Site Pages Management (News, About, Support, Contact)\nScope: Superadmin-editable public information pages with publish control.\nBenefit: Faster communication of updates and support information.\n\nScheduled Rollout Plan (Upcoming)\nFebruary 20, 2026 - Attendance QR flow refinements\nPlanned Work: Faster validation feedback and improved edge-case handling.\n\nFebruary 27, 2026 - Report output consistency pass\nPlanned Work: Improved report formatting consistency across templates and print views.\n\nMarch 6, 2026 - Mobile usability polish\nPlanned Work: Better spacing and navigation behavior for key pages on smaller screens.\n\nMarch 13, 2026 - Security and session policy hardening\nPlanned Work: Stronger session guardrails and improved admin policy controls.\n\nMarch 20, 2026 - Campus policy preset enhancements\nPlanned Work: Reusable policy setup patterns for campus-level configurations.\n\nMaintenance Schedule\nWeekly Window: Every Friday, 9:00 PM to 10:00 PM (server time)\nMonthly Extended Window: Second Saturday of each month, 8:00 PM to 11:00 PM (server time)\nExpected Impact: Short service interruptions may occur during deployment tasks.\n\nGuidance for Users\nAdmins: Review new controls in Settings after each release window.\nTeachers: Verify class pages and grading tools after schedule updates.\nStudents: Re-login after maintenance and refresh cached pages if needed.",
                'is_published' => 1,
            ],
            'about' => [
                'page_key' => 'about',
                'nav_label' => 'About',
                'page_title' => 'About | E-Record',
                'hero_title' => 'About Doc Ease',
                'hero_subtitle' => 'A platform for records, classes, attendance, and academic workflows.',
                'content_text' => "Doc Ease helps schools manage class records, attendance check-ins, learning materials, and account operations in one portal.\n\nThis page can be customized by superadmin to reflect your campus mission and system overview.",
                'is_published' => 1,
            ],
            'support' => [
                'page_key' => 'support',
                'nav_label' => 'Support',
                'page_title' => 'Support | E-Record',
                'hero_title' => 'Support Center',
                'hero_subtitle' => 'Need help? Start with common support paths.',
                'content_text' => "For account access issues:\n- Contact your campus admin.\n- Include your email/Student ID and a screenshot.\n\nFor technical issues:\n- Describe what happened.\n- Include the page URL, time encountered, and your browser/device.",
                'is_published' => 1,
            ],
            'contact' => [
                'page_key' => 'contact',
                'nav_label' => 'Contact Us',
                'page_title' => 'Contact Us | E-Record',
                'hero_title' => 'Contact Us',
                'hero_subtitle' => 'Reach the Doc Ease support team.',
                'content_text' => "Email: support@docease.local\nPhone: +63 000 000 0000\nOffice Hours: Monday to Friday, 8:00 AM to 5:00 PM\n\nSuperadmin can update this information in Site Pages settings.",
                'is_published' => 1,
            ],
        ];
    }
}

if (!function_exists('site_pages_news_legacy_placeholder_content')) {
    function site_pages_news_legacy_placeholder_content() {
        return "No announcements have been posted yet.\n\nUse this page to publish release notes, campus-wide reminders, and maintenance schedules.";
    }
}

if (!function_exists('site_pages_valid_key')) {
    function site_pages_valid_key($key) {
        $key = strtolower(trim((string) $key));
        return in_array($key, site_pages_keys(), true) ? $key : '';
    }
}

if (!function_exists('site_pages_label_for_key')) {
    function site_pages_label_for_key($key) {
        $key = site_pages_valid_key($key);
        if ($key === '') return '';
        $defaults = site_pages_defaults();
        return isset($defaults[$key]['nav_label']) ? (string) $defaults[$key]['nav_label'] : ucfirst($key);
    }
}

if (!function_exists('site_pages_project_root')) {
    function site_pages_project_root() {
        static $root = null;
        if (is_string($root) && $root !== '') return $root;
        $resolved = realpath(__DIR__ . '/..');
        $root = is_string($resolved) && $resolved !== '' ? str_replace('\\', '/', $resolved) : '';
        return $root;
    }
}

if (!function_exists('site_pages_allowed_image_exts')) {
    function site_pages_allowed_image_exts() {
        return ['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg'];
    }
}

if (!function_exists('site_pages_humanize_filename')) {
    function site_pages_humanize_filename($pathOrName) {
        $name = (string) $pathOrName;
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/\.[a-z0-9]+$/i', '', $name);
        $name = preg_replace('/[_\-]+/', ' ', (string) $name);
        $name = trim((string) $name);
        if ($name === '') return 'Infographic';
        return ucwords(strtolower($name));
    }
}

if (!function_exists('site_pages_normalize_image_path')) {
    function site_pages_normalize_image_path($path, $mustExist = true) {
        $path = trim((string) $path);
        if ($path === '') return '';

        $path = str_replace('\\', '/', $path);
        while (strpos($path, '//') !== false) {
            $path = str_replace('//', '/', $path);
        }
        while (strpos($path, './') === 0) {
            $path = substr($path, 2);
        }
        while (strpos($path, '../') === 0) {
            $path = substr($path, 3);
        }
        $path = ltrim($path, '/');

        if ($path === '') return '';
        if (preg_match('/^[a-zA-Z]+:\/\//', $path)) return '';
        if (preg_match('/^[a-zA-Z]:\//', $path)) return '';
        if (strpos($path, '..') !== false) return '';
        if (strpos($path, "\0") !== false) return '';

        if (
            strpos($path, 'assets/images/') !== 0 &&
            strpos($path, 'uploads/') !== 0
        ) {
            return '';
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, site_pages_allowed_image_exts(), true)) return '';

        if (!$mustExist) return $path;

        $root = site_pages_project_root();
        if ($root === '') return '';

        $abs = realpath($root . '/' . $path);
        if (!is_string($abs) || $abs === '') return '';
        $abs = str_replace('\\', '/', $abs);
        if (strpos($abs, $root . '/') !== 0 && $abs !== $root) return '';
        if (!is_file($abs)) return '';

        return ltrim(substr($abs, strlen($root)), '/');
    }
}

if (!function_exists('site_pages_infographic_library')) {
    function site_pages_infographic_library($limit = 120) {
        $limit = (int) $limit;
        if ($limit < 1) $limit = 1;
        if ($limit > 500) $limit = 500;

        $root = site_pages_project_root();
        if ($root === '') return [];

        $sources = [
            ['path' => 'assets/images/site-infographics', 'group' => 'Infographics'],
            ['path' => 'assets/images/report-template', 'group' => 'Report Template'],
            ['path' => 'assets/images/svg', 'group' => 'SVG'],
        ];

        $items = [];
        $seen = [];
        foreach ($sources as $source) {
            $relBase = (string) ($source['path'] ?? '');
            $group = (string) ($source['group'] ?? 'Library');
            if ($relBase === '') continue;

            $absBase = realpath($root . '/' . $relBase);
            if (!is_string($absBase) || $absBase === '') continue;
            $absBase = str_replace('\\', '/', $absBase);
            if (!is_dir($absBase)) continue;

            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($absBase, FilesystemIterator::SKIP_DOTS)
                );
            } catch (Throwable $e) {
                continue;
            }

            foreach ($iterator as $fileInfo) {
                if (count($items) >= $limit) break 2;
                if (!($fileInfo instanceof SplFileInfo) || !$fileInfo->isFile()) continue;

                $ext = strtolower((string) $fileInfo->getExtension());
                if (!in_array($ext, site_pages_allowed_image_exts(), true)) continue;

                $absPath = str_replace('\\', '/', (string) $fileInfo->getPathname());
                if (strpos($absPath, $root . '/') !== 0) continue;

                $relPath = ltrim(substr($absPath, strlen($root)), '/');
                $safeRelPath = site_pages_normalize_image_path($relPath, true);
                if ($safeRelPath === '' || isset($seen[$safeRelPath])) continue;
                $seen[$safeRelPath] = true;

                $title = site_pages_humanize_filename($safeRelPath);
                $items[] = [
                    'path' => $safeRelPath,
                    'title' => $title,
                    'group' => $group,
                    'token' => '[[infographic:' . $safeRelPath . '|' . $title . ']]',
                ];
            }
        }

        return $items;
    }
}

if (!function_exists('site_pages_has_column')) {
    function site_pages_has_column(mysqli $conn, $tableName, $columnName) {
        $tableName = trim((string) $tableName);
        $columnName = trim((string) $columnName);
        if ($tableName === '' || $columnName === '') return false;

        $stmt = $conn->prepare(
            "SELECT 1
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
             LIMIT 1"
        );
        if (!$stmt) return false;

        $stmt->bind_param('ss', $tableName, $columnName);
        $stmt->execute();
        $res = $stmt->get_result();
        $exists = ($res && $res->num_rows === 1);
        $stmt->close();
        return $exists;
    }
}

if (!function_exists('site_pages_ensure_table')) {
    function site_pages_ensure_table(mysqli $conn) {
        static $done = false;
        if ($done) return;
        $done = true;

        $conn->query(
            "CREATE TABLE IF NOT EXISTS site_content_pages (
                page_key VARCHAR(32) NOT NULL PRIMARY KEY,
                nav_label VARCHAR(80) NOT NULL DEFAULT '',
                page_title VARCHAR(160) NOT NULL DEFAULT '',
                hero_title VARCHAR(190) NOT NULL DEFAULT '',
                hero_subtitle VARCHAR(500) NOT NULL DEFAULT '',
                content_text MEDIUMTEXT NOT NULL,
                is_published TINYINT(1) NOT NULL DEFAULT 1,
                updated_by BIGINT UNSIGNED NULL DEFAULT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_site_content_pages_published (is_published)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        if (!site_pages_has_column($conn, 'site_content_pages', 'nav_label')) {
            $conn->query("ALTER TABLE site_content_pages ADD COLUMN nav_label VARCHAR(80) NOT NULL DEFAULT '' AFTER page_key");
        }
        if (!site_pages_has_column($conn, 'site_content_pages', 'page_title')) {
            $conn->query("ALTER TABLE site_content_pages ADD COLUMN page_title VARCHAR(160) NOT NULL DEFAULT '' AFTER nav_label");
        }
        if (!site_pages_has_column($conn, 'site_content_pages', 'hero_title')) {
            $conn->query("ALTER TABLE site_content_pages ADD COLUMN hero_title VARCHAR(190) NOT NULL DEFAULT '' AFTER page_title");
        }
        if (!site_pages_has_column($conn, 'site_content_pages', 'hero_subtitle')) {
            $conn->query("ALTER TABLE site_content_pages ADD COLUMN hero_subtitle VARCHAR(500) NOT NULL DEFAULT '' AFTER hero_title");
        }
        if (!site_pages_has_column($conn, 'site_content_pages', 'content_text')) {
            $conn->query("ALTER TABLE site_content_pages ADD COLUMN content_text MEDIUMTEXT NOT NULL AFTER hero_subtitle");
        }
        if (!site_pages_has_column($conn, 'site_content_pages', 'is_published')) {
            $conn->query("ALTER TABLE site_content_pages ADD COLUMN is_published TINYINT(1) NOT NULL DEFAULT 1 AFTER content_text");
        }
        if (!site_pages_has_column($conn, 'site_content_pages', 'updated_by')) {
            $conn->query("ALTER TABLE site_content_pages ADD COLUMN updated_by BIGINT UNSIGNED NULL DEFAULT NULL AFTER is_published");
        }
        if (!site_pages_has_column($conn, 'site_content_pages', 'updated_at')) {
            $conn->query(
                "ALTER TABLE site_content_pages
                 ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER updated_by"
            );
        }

        $defaults = site_pages_defaults();
        $stmt = $conn->prepare(
            "INSERT IGNORE INTO site_content_pages
                (page_key, nav_label, page_title, hero_title, hero_subtitle, content_text, is_published)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) return;

        foreach (site_pages_keys() as $key) {
            if (!isset($defaults[$key])) continue;
            $row = $defaults[$key];
            $pageKey = (string) ($row['page_key'] ?? $key);
            $navLabel = (string) ($row['nav_label'] ?? ucfirst($key));
            $pageTitle = (string) ($row['page_title'] ?? ucfirst($key) . ' | E-Record');
            $heroTitle = (string) ($row['hero_title'] ?? ucfirst($key));
            $heroSubtitle = (string) ($row['hero_subtitle'] ?? '');
            $contentText = (string) ($row['content_text'] ?? '');
            $isPublished = !empty($row['is_published']) ? 1 : 0;

            $stmt->bind_param(
                'ssssssi',
                $pageKey,
                $navLabel,
                $pageTitle,
                $heroTitle,
                $heroSubtitle,
                $contentText,
                $isPublished
            );
            try {
                $stmt->execute();
            } catch (Throwable $e) {
                // Ignore insert failures per-row and continue bootstrap.
            }
        }
        $stmt->close();

        // One-time uplift for legacy installs that still have the original placeholder News content.
        if (isset($defaults['news']) && is_array($defaults['news'])) {
            $legacyNews = site_pages_news_legacy_placeholder_content();
            $newsNavLabel = (string) ($defaults['news']['nav_label'] ?? 'News');
            $newsPageTitle = (string) ($defaults['news']['page_title'] ?? 'News & Feature Schedule | E-Record');
            $newsHeroTitle = (string) ($defaults['news']['hero_title'] ?? 'Doc Ease System News');
            $newsHeroSubtitle = (string) ($defaults['news']['hero_subtitle'] ?? '');
            $newsContent = (string) ($defaults['news']['content_text'] ?? '');

            $legacyPageTitle = 'News | E-Record';
            $legacyHeroTitle = 'Doc Ease News';
            $legacyHeroSubtitle = 'Announcements, feature releases, and maintenance updates.';

            $selNews = $conn->prepare(
                "SELECT nav_label, page_title, hero_title, hero_subtitle, content_text
                 FROM site_content_pages
                 WHERE page_key = 'news'
                 LIMIT 1"
            );
            if ($selNews) {
                try {
                    $selNews->execute();
                    $resNews = $selNews->get_result();
                    $rowNews = ($resNews && $resNews->num_rows === 1) ? $resNews->fetch_assoc() : null;
                    if (is_array($rowNews)) {
                        $currentNavLabel = (string) ($rowNews['nav_label'] ?? '');
                        $currentPageTitle = (string) ($rowNews['page_title'] ?? '');
                        $currentHeroTitle = (string) ($rowNews['hero_title'] ?? '');
                        $currentHeroSubtitle = (string) ($rowNews['hero_subtitle'] ?? '');
                        $currentContent = (string) ($rowNews['content_text'] ?? '');

                        $normalizedCurrent = str_replace(["\r\n", "\r"], "\n", $currentContent);
                        $containsLegacy = (strpos($normalizedCurrent, $legacyNews) !== false) || trim($normalizedCurrent) === '';
                        $alreadyUpgraded = (strpos($normalizedCurrent, 'Doc Ease Feature Bulletin') !== false);

                        if ($containsLegacy && !$alreadyUpgraded) {
                            $upgradedContent = trim($normalizedCurrent) === ''
                                ? $newsContent
                                : str_replace($legacyNews, $newsContent, $normalizedCurrent);
                            if (trim($upgradedContent) === '') $upgradedContent = $newsContent;

                            $nextNavLabel = $currentNavLabel === '' ? $newsNavLabel : $currentNavLabel;
                            $nextPageTitle = ($currentPageTitle === '' || $currentPageTitle === $legacyPageTitle)
                                ? $newsPageTitle
                                : $currentPageTitle;
                            $nextHeroTitle = ($currentHeroTitle === '' || $currentHeroTitle === $legacyHeroTitle)
                                ? $newsHeroTitle
                                : $currentHeroTitle;
                            $nextHeroSubtitle = ($currentHeroSubtitle === '' || $currentHeroSubtitle === $legacyHeroSubtitle)
                                ? $newsHeroSubtitle
                                : $currentHeroSubtitle;

                            $updNews = $conn->prepare(
                                "UPDATE site_content_pages
                                 SET nav_label = ?, page_title = ?, hero_title = ?, hero_subtitle = ?, content_text = ?
                                 WHERE page_key = 'news'"
                            );
                            if ($updNews) {
                                $updNews->bind_param(
                                    'sssss',
                                    $nextNavLabel,
                                    $nextPageTitle,
                                    $nextHeroTitle,
                                    $nextHeroSubtitle,
                                    $upgradedContent
                                );
                                try {
                                    $updNews->execute();
                                } catch (Throwable $e) {
                                    // Non-fatal; keep running with existing content.
                                }
                                $updNews->close();
                            }
                        }
                    }
                } catch (Throwable $e) {
                    // Non-fatal.
                }
                $selNews->close();
            }
        }
    }
}

if (!function_exists('site_pages_rows')) {
    function site_pages_rows(mysqli $conn) {
        site_pages_ensure_table($conn);

        $rows = [];
        $res = $conn->query(
            "SELECT page_key, nav_label, page_title, hero_title, hero_subtitle, content_text, is_published, updated_by, updated_at
             FROM site_content_pages"
        );
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $key = site_pages_valid_key((string) ($row['page_key'] ?? ''));
                if ($key === '') continue;
                $rows[$key] = [
                    'page_key' => $key,
                    'nav_label' => (string) ($row['nav_label'] ?? ''),
                    'page_title' => (string) ($row['page_title'] ?? ''),
                    'hero_title' => (string) ($row['hero_title'] ?? ''),
                    'hero_subtitle' => (string) ($row['hero_subtitle'] ?? ''),
                    'content_text' => (string) ($row['content_text'] ?? ''),
                    'is_published' => ((int) ($row['is_published'] ?? 0) === 1) ? 1 : 0,
                    'updated_by' => (int) ($row['updated_by'] ?? 0),
                    'updated_at' => (string) ($row['updated_at'] ?? ''),
                ];
            }
        }

        $defaults = site_pages_defaults();
        foreach (site_pages_keys() as $key) {
            if (!isset($rows[$key])) {
                $d = $defaults[$key];
                $rows[$key] = [
                    'page_key' => $key,
                    'nav_label' => (string) ($d['nav_label'] ?? ucfirst($key)),
                    'page_title' => (string) ($d['page_title'] ?? ucfirst($key) . ' | E-Record'),
                    'hero_title' => (string) ($d['hero_title'] ?? ucfirst($key)),
                    'hero_subtitle' => (string) ($d['hero_subtitle'] ?? ''),
                    'content_text' => (string) ($d['content_text'] ?? ''),
                    'is_published' => !empty($d['is_published']) ? 1 : 0,
                    'updated_by' => 0,
                    'updated_at' => '',
                ];
            }
        }

        return $rows;
    }
}

if (!function_exists('site_pages_get')) {
    function site_pages_get(mysqli $conn, $key) {
        $key = site_pages_valid_key($key);
        if ($key === '') return null;
        $rows = site_pages_rows($conn);
        return isset($rows[$key]) ? $rows[$key] : null;
    }
}

if (!function_exists('site_pages_format_timestamp')) {
    function site_pages_format_timestamp($value) {
        $value = trim((string) $value);
        if ($value === '') return '';
        $ts = strtotime($value);
        if ($ts === false) return '';
        return date('M d, Y h:i A', $ts);
    }
}

if (!function_exists('site_pages_render_infographic_html')) {
    function site_pages_render_infographic_html($path, $caption = '') {
        $safePath = site_pages_normalize_image_path($path, true);
        if ($safePath === '') return '';

        $caption = trim((string) $caption);
        if ($caption === '') $caption = site_pages_humanize_filename($safePath);

        $html = '<figure class="site-page-infographic mb-3">';
        $html .= '<img src="' . site_pages_h($safePath) . '" class="img-fluid rounded border" alt="' . site_pages_h($caption) . '" loading="lazy">';
        if ($caption !== '') {
            $html .= '<figcaption class="small text-muted mt-1">' . site_pages_h($caption) . '</figcaption>';
        }
        $html .= '</figure>';
        return $html;
    }
}

if (!function_exists('site_pages_render_content_html')) {
    /**
     * Renders plain text content with support for infographic tokens:
     * [[infographic:assets/images/path.png|Optional caption]]
     */
    function site_pages_render_content_html($contentText) {
        $text = trim((string) $contentText);
        if ($text === '') {
            return '<p class="text-muted mb-0">No content available.</p>';
        }

        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = explode("\n", $text);

        $paragraphBuffer = [];
        $parts = [];

        $flushParagraphs = function () use (&$paragraphBuffer, &$parts) {
            if (count($paragraphBuffer) === 0) return;

            $segments = [];
            $chunk = [];
            foreach ($paragraphBuffer as $line) {
                if (trim((string) $line) === '') {
                    if (count($chunk) > 0) {
                        $segments[] = implode("\n", $chunk);
                        $chunk = [];
                    }
                    continue;
                }
                $chunk[] = (string) $line;
            }
            if (count($chunk) > 0) {
                $segments[] = implode("\n", $chunk);
            }

            foreach ($segments as $segment) {
                $parts[] = '<p class="mb-3">' . nl2br(site_pages_h($segment)) . '</p>';
            }

            $paragraphBuffer = [];
        };

        foreach ($lines as $line) {
            $trimmed = trim((string) $line);
            if (preg_match('/^\[\[infographic:([^\]|]+)(?:\|([^\]]*))?\]\]$/i', $trimmed, $m)) {
                $flushParagraphs();
                $imgPath = isset($m[1]) ? (string) $m[1] : '';
                $imgCaption = isset($m[2]) ? (string) $m[2] : '';
                $imgHtml = site_pages_render_infographic_html($imgPath, $imgCaption);
                if ($imgHtml !== '') {
                    $parts[] = $imgHtml;
                } else {
                    $parts[] = '<p class="mb-3">' . site_pages_h($trimmed) . '</p>';
                }
                continue;
            }
            $paragraphBuffer[] = (string) $line;
        }

        $flushParagraphs();

        if (count($parts) === 0) {
            return '<p class="text-muted mb-0">No content available.</p>';
        }

        return implode("\n", $parts);
    }
}

if (!function_exists('site_pages_save')) {
    function site_pages_save(mysqli $conn, $key, array $data, $updatedBy = 0) {
        $key = site_pages_valid_key($key);
        if ($key === '') return [false, 'Invalid page key.'];

        $defaults = site_pages_defaults();
        $fallback = isset($defaults[$key]) ? $defaults[$key] : [
            'nav_label' => ucfirst($key),
            'page_title' => ucfirst($key) . ' | E-Record',
            'hero_title' => ucfirst($key),
            'hero_subtitle' => '',
            'content_text' => '',
            'is_published' => 1,
        ];

        $navLabel = trim((string) ($data['nav_label'] ?? $fallback['nav_label']));
        if ($navLabel === '') $navLabel = (string) $fallback['nav_label'];
        if (strlen($navLabel) > 80) $navLabel = substr($navLabel, 0, 80);

        $pageTitle = trim((string) ($data['page_title'] ?? $fallback['page_title']));
        if ($pageTitle === '') return [false, 'Page title is required.'];
        if (strlen($pageTitle) > 160) $pageTitle = substr($pageTitle, 0, 160);

        $heroTitle = trim((string) ($data['hero_title'] ?? $fallback['hero_title']));
        if ($heroTitle === '') return [false, 'Hero title is required.'];
        if (strlen($heroTitle) > 190) $heroTitle = substr($heroTitle, 0, 190);

        $heroSubtitle = trim((string) ($data['hero_subtitle'] ?? $fallback['hero_subtitle']));
        if (strlen($heroSubtitle) > 500) $heroSubtitle = substr($heroSubtitle, 0, 500);

        $contentText = trim((string) ($data['content_text'] ?? $fallback['content_text']));
        if ($contentText === '') return [false, 'Page content is required.'];
        if (strlen($contentText) > 60000) {
            $contentText = substr($contentText, 0, 60000);
        }

        $isPublished = !empty($data['is_published']) ? 1 : 0;
        $updatedBy = (int) $updatedBy;
        if ($updatedBy < 0) $updatedBy = 0;

        site_pages_ensure_table($conn);

        $stmt = $conn->prepare(
            "INSERT INTO site_content_pages
                (page_key, nav_label, page_title, hero_title, hero_subtitle, content_text, is_published, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                nav_label = VALUES(nav_label),
                page_title = VALUES(page_title),
                hero_title = VALUES(hero_title),
                hero_subtitle = VALUES(hero_subtitle),
                content_text = VALUES(content_text),
                is_published = VALUES(is_published),
                updated_by = VALUES(updated_by)"
        );
        if (!$stmt) return [false, 'Unable to save page settings.'];

        $stmt->bind_param(
            'ssssssii',
            $key,
            $navLabel,
            $pageTitle,
            $heroTitle,
            $heroSubtitle,
            $contentText,
            $isPublished,
            $updatedBy
        );
        $ok = false;
        try {
            $ok = $stmt->execute();
        } catch (Throwable $e) {
            $ok = false;
        }
        $stmt->close();

        if (!$ok) return [false, 'Unable to save page settings.'];
        return [true, 'Saved.'];
    }
}
