<?php include '../layouts/session.php'; ?>
<?php require_once __DIR__ . '/../includes/site_pages.php'; ?>
<?php include '../layouts/main.php'; ?>

<?php
$pageKey = 'news';
$pageData = site_pages_get($conn, $pageKey);
if (!is_array($pageData)) {
    $defaults = site_pages_defaults();
    $pageData = isset($defaults[$pageKey]) ? $defaults[$pageKey] : [];
}

$pageTitle = trim((string) ($pageData['page_title'] ?? 'News | E-Record'));
$pageLabel = trim((string) ($pageData['nav_label'] ?? site_pages_label_for_key($pageKey)));
$isPublished = ((int) ($pageData['is_published'] ?? 1) === 1);
$updatedAtLabel = site_pages_format_timestamp((string) ($pageData['updated_at'] ?? ''));

if (!function_exists('news_color_scheme')) {
    function news_color_scheme($index) {
        $shared = site_pages_theme_scheme($index, 0.87, 0.68);
        return [
            'badge' => (string) ($shared['badge'] ?? '#3e60d5'),
            'card_bg' => (string) ($shared['panel_bg'] ?? '#eef2ff'),
            'card_border' => (string) ($shared['panel_border'] ?? '#c7d2fe'),
        ];
    }
}

if (!function_exists('news_parse_content_blocks')) {
    function news_parse_content_blocks($rawContent) {
        $text = str_replace(["\r\n", "\r"], "\n", (string) $rawContent);
        $lines = explode("\n", $text);

        $result = [
            'intro_lines' => [],
            'meta' => [],
            'sections' => [],
            'featured_images' => [],
        ];

        $sectionHints = [
            'included features',
            'scheduled rollout plan',
            'maintenance schedule',
            'guidance for users',
        ];

        $currentSection = '';
        $currentItem = null;

        $ensureSection = function ($title) use (&$result) {
            $title = trim((string) $title);
            if ($title === '') $title = 'Updates';
            if (!isset($result['sections'][$title])) {
                $result['sections'][$title] = [];
            }
        };

        $flushItem = function () use (&$currentItem, &$currentSection, &$result, $ensureSection) {
            if (!is_array($currentItem)) return;
            $sectionTitle = trim((string) $currentSection);
            if ($sectionTitle === '') $sectionTitle = 'Updates';
            $ensureSection($sectionTitle);
            $result['sections'][$sectionTitle][] = $currentItem;
            $currentItem = null;
        };

        foreach ($lines as $rawLine) {
            $line = trim((string) $rawLine);
            if ($line === '') continue;

            if (preg_match('/^\[\[infographic:([^\]|]+)(?:\|([^\]]*))?\]\]$/i', $line, $m)) {
                $result['featured_images'][] = [
                    'path' => trim((string) ($m[1] ?? '')),
                    'caption' => trim((string) ($m[2] ?? '')),
                ];
                continue;
            }

            if (preg_match('/^(Published|Coverage Window)\s*:\s*(.+)$/i', $line, $m) && trim((string) $currentSection) === '') {
                $result['meta'][] = [
                    'label' => trim((string) $m[1]),
                    'value' => trim((string) $m[2]),
                ];
                continue;
            }

            $lineLower = strtolower($line);
            $isSectionHeader = false;
            foreach ($sectionHints as $hint) {
                if (strpos($lineLower, $hint) === 0) {
                    $isSectionHeader = true;
                    break;
                }
            }
            if ($isSectionHeader) {
                $flushItem();
                $currentSection = $line;
                $ensureSection($currentSection);
                continue;
            }

            if (preg_match('/^([A-Za-z]+\s+\d{1,2},\s+\d{4})\s*-\s*(.+)$/', $line, $m)) {
                $flushItem();
                $currentItem = [
                    'badge' => trim((string) $m[1]),
                    'title' => trim((string) $m[2]),
                    'details' => [],
                ];
                continue;
            }

            if (strpos($line, ':') !== false) {
                $parts = explode(':', $line, 2);
                $label = trim((string) ($parts[0] ?? ''));
                $value = trim((string) ($parts[1] ?? ''));
                if ($value === '') continue;

                if (is_array($currentItem)) {
                    $currentItem['details'][] = ['label' => $label, 'value' => $value];
                } else {
                    $sectionTitle = trim((string) $currentSection);
                    if ($sectionTitle === '') $sectionTitle = 'Updates';
                    $ensureSection($sectionTitle);
                    $result['sections'][$sectionTitle][] = [
                        'badge' => $label !== '' ? $label : 'Update',
                        'title' => $value,
                        'details' => [],
                    ];
                }
                continue;
            }

            if (is_array($currentItem)) {
                $currentItem['details'][] = ['label' => '', 'value' => $line];
            } elseif (trim((string) $currentSection) === '') {
                $result['intro_lines'][] = $line;
            } else {
                $ensureSection($currentSection);
                $result['sections'][$currentSection][] = [
                    'badge' => 'Update',
                    'title' => $line,
                    'details' => [],
                ];
            }
        }

        $flushItem();
        return $result;
    }
}

$newsBlocks = news_parse_content_blocks((string) ($pageData['content_text'] ?? ''));
$hasStructuredFlow = count($newsBlocks['sections']) > 0;
?>

<head>
    <title><?php echo site_pages_h($pageTitle); ?></title>
    <?php include '../layouts/title-meta.php'; ?>
    <?php include '../layouts/head-css.php'; ?>
    <link href="assets/css/info-pages-typography.css" rel="stylesheet" type="text/css" />
    <style>
        .site-page-hero {
            border: 1px solid rgba(47, 85, 212, 0.18);
            border-radius: 14px;
            background: linear-gradient(130deg, rgba(47, 85, 212, 0.12), rgba(47, 85, 212, 0.03));
        }
        .news-meta-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 10px;
            border: 1px solid transparent;
            border-radius: 999px;
        }
        .news-section-title {
            margin: 0;
            letter-spacing: 0.02em;
            text-transform: uppercase;
            color: #475569;
        }
        .news-flow {
            position: relative;
            padding-left: 18px;
        }
        .news-flow::before {
            content: "";
            position: absolute;
            top: 4px;
            bottom: 6px;
            left: 5px;
            width: 2px;
            background: #d9e2ec;
        }
        .news-flow-item {
            position: relative;
            margin-bottom: 14px;
            --flow-badge: #3e60d5;
            --flow-bg: #eef2ff;
            --flow-border: #c7d2fe;
        }
        .news-flow-item:last-child {
            margin-bottom: 0;
        }
        .news-flow-marker {
            position: absolute;
            top: 12px;
            left: -18px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: var(--flow-badge);
            box-shadow: 0 0 0 3px var(--flow-bg);
            border: 1px solid rgba(255, 255, 255, 0.8);
        }
        .news-flow-panel {
            border: 1px solid var(--flow-border);
            border-radius: 14px;
            background: var(--flow-bg);
            padding: 12px 14px;
        }
        .news-item-badge {
            padding: 6px 10px;
            border-radius: 999px;
        }
        .news-item-title {
            font-weight: 700;
            color: #1e293b;
        }
        .news-item-detail {
            color: #334155;
        }
        @media (max-width: 767.98px) {
            .news-flow {
                padding-left: 14px;
            }
            .news-flow::before {
                left: 4px;
            }
            .news-flow-marker {
                left: -14px;
            }
        }
    </style>
</head>

<body class="info-page">
    <div class="wrapper">
        <?php include '../layouts/menu.php'; ?>

        <div class="content-page">
            <div class="content">
                <div class="container-fluid">
                    <div class="row">
                        <div class="col-12">
                            <div class="page-title-box">
                                <div class="page-title-right">
                                    <ol class="breadcrumb m-0">
                                        <li class="breadcrumb-item"><a href="index.php">E-Record</a></li>
                                        <li class="breadcrumb-item active"><?php echo site_pages_h($pageLabel !== '' ? $pageLabel : 'News'); ?></li>
                                    </ol>
                                </div>
                                <h4 class="page-title"><?php echo site_pages_h($pageLabel !== '' ? $pageLabel : 'News'); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-12">
                            <div class="d-flex flex-wrap gap-2">
                                <a class="btn btn-sm btn-primary" href="news.php">News</a>
                                <a class="btn btn-sm btn-outline-primary" href="about.php">About</a>
                                <a class="btn btn-sm btn-outline-primary" href="support.php">Support</a>
                                <a class="btn btn-sm btn-outline-primary" href="contact-us.php">Contact Us</a>
                            </div>
                        </div>
                    </div>

                    <?php if (!$isPublished): ?>
                        <div class="row">
                            <div class="col-12">
                                <div class="alert alert-warning mb-0">
                                    This page is currently unpublished. Please contact your administrator.
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="row">
                            <div class="col-12">
                                <div class="card site-page-hero">
                                    <div class="card-body p-4">
                                        <h3 class="mb-2"><?php echo site_pages_h((string) ($pageData['hero_title'] ?? 'News')); ?></h3>
                                        <p class="text-muted mb-0"><?php echo site_pages_h((string) ($pageData['hero_subtitle'] ?? '')); ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row mt-3">
                            <div class="col-12">
                                <div class="card">
                                    <div class="card-body">
                                        <?php if ($updatedAtLabel !== ''): ?>
                                            <div class="text-muted small mb-3">Last updated: <?php echo site_pages_h($updatedAtLabel); ?></div>
                                        <?php endif; ?>
                                        <?php if (count($newsBlocks['featured_images']) > 0): ?>
                                            <div class="mb-3">
                                                <?php foreach ($newsBlocks['featured_images'] as $img): ?>
                                                    <?php echo site_pages_render_infographic_html((string) ($img['path'] ?? ''), (string) ($img['caption'] ?? '')); ?>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (count($newsBlocks['intro_lines']) > 0): ?>
                                            <div class="mb-3">
                                                <?php foreach ($newsBlocks['intro_lines'] as $introLine): ?>
                                                    <p class="mb-2 fw-semibold text-dark"><?php echo site_pages_h((string) $introLine); ?></p>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (count($newsBlocks['meta']) > 0): ?>
                                            <div class="d-flex flex-wrap gap-2 mb-3">
                                                <?php foreach ($newsBlocks['meta'] as $metaIndex => $meta): ?>
                                                    <?php $metaColor = news_color_scheme($metaIndex); ?>
                                                    <span class="news-meta-chip" style="background-color: <?php echo site_pages_h($metaColor['card_bg']); ?>; border-color: <?php echo site_pages_h($metaColor['card_border']); ?>;">
                                                        <span class="badge rounded-pill" style="background-color: <?php echo site_pages_h($metaColor['badge']); ?>;">
                                                            <?php echo site_pages_h((string) ($meta['label'] ?? 'Info')); ?>
                                                        </span>
                                                        <span><?php echo site_pages_h((string) ($meta['value'] ?? '')); ?></span>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($hasStructuredFlow): ?>
                                            <?php $itemColorIndex = 0; ?>
                                            <?php foreach ($newsBlocks['sections'] as $sectionTitle => $items): ?>
                                                <div class="mb-3">
                                                    <h5 class="news-section-title mb-2"><?php echo site_pages_h((string) $sectionTitle); ?></h5>
                                                    <div class="news-flow">
                                                        <?php foreach ($items as $item): ?>
                                                            <?php
                                                                $itemColor = news_color_scheme($itemColorIndex);
                                                                $itemColorIndex++;
                                                                $badgeText = trim((string) ($item['badge'] ?? 'Update'));
                                                                if ($badgeText === '') $badgeText = 'Update';
                                                                $itemTitle = trim((string) ($item['title'] ?? ''));
                                                                $details = is_array($item['details'] ?? null) ? $item['details'] : [];
                                                                $flowStyle = '--flow-badge:' . $itemColor['badge'] . ';--flow-bg:' . $itemColor['card_bg'] . ';--flow-border:' . $itemColor['card_border'] . ';';
                                                            ?>
                                                            <article class="news-flow-item" style="<?php echo site_pages_h($flowStyle); ?>">
                                                                <span class="news-flow-marker" aria-hidden="true"></span>
                                                                <div class="news-flow-panel">
                                                                    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                                                        <span class="badge news-item-badge" style="background-color: <?php echo site_pages_h($itemColor['badge']); ?>;"><?php echo site_pages_h($badgeText); ?></span>
                                                                    </div>
                                                                    <?php if ($itemTitle !== ''): ?>
                                                                        <div class="news-item-title mb-2"><?php echo site_pages_h($itemTitle); ?></div>
                                                                    <?php endif; ?>
                                                                    <?php if (count($details) > 0): ?>
                                                                        <div class="d-flex flex-column gap-1">
                                                                            <?php foreach ($details as $detail): ?>
                                                                                <?php
                                                                                    $detailLabel = trim((string) ($detail['label'] ?? ''));
                                                                                    $detailValue = trim((string) ($detail['value'] ?? ''));
                                                                                    if ($detailValue === '') continue;
                                                                                ?>
                                                                                <div class="news-item-detail">
                                                                                    <?php if ($detailLabel !== ''): ?>
                                                                                        <span class="fw-semibold"><?php echo site_pages_h($detailLabel); ?>:</span>
                                                                                    <?php endif; ?>
                                                                                    <?php echo site_pages_h($detailValue); ?>
                                                                                </div>
                                                                            <?php endforeach; ?>
                                                                        </div>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </article>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <div class="site-page-body"><?php echo site_pages_render_content_html((string) ($pageData['content_text'] ?? '')); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php include '../layouts/footer.php'; ?>
        </div>
    </div>

    <?php include '../layouts/right-sidebar.php'; ?>
    <?php include '../layouts/footer-scripts.php'; ?>
    <script src="assets/js/app.min.js"></script>
</body>

</html>
