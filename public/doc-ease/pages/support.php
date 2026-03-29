<?php include '../layouts/session.php'; ?>
<?php require_once __DIR__ . '/../includes/site_pages.php'; ?>
<?php include '../layouts/main.php'; ?>

<?php
$pageKey = 'support';
$pageData = site_pages_get($conn, $pageKey);
if (!is_array($pageData)) {
    $defaults = site_pages_defaults();
    $pageData = isset($defaults[$pageKey]) ? $defaults[$pageKey] : [];
}

$pageTitle = trim((string) ($pageData['page_title'] ?? 'Support | E-Record'));
$pageLabel = trim((string) ($pageData['nav_label'] ?? site_pages_label_for_key($pageKey)));
$isPublished = ((int) ($pageData['is_published'] ?? 1) === 1);
$updatedAtLabel = site_pages_format_timestamp((string) ($pageData['updated_at'] ?? ''));

if (!function_exists('support_color_scheme')) {
    function support_color_scheme($index) {
        $shared = site_pages_theme_scheme($index, 0.90, 0.72);
        return [
            'badge' => (string) ($shared['badge'] ?? '#14b8a6'),
            'panel_bg' => (string) ($shared['panel_bg'] ?? '#eefcf9'),
            'panel_border' => (string) ($shared['panel_border'] ?? '#bce9e3'),
        ];
    }
}

if (!function_exists('support_parse_content')) {
    function support_parse_content($raw) {
        $text = str_replace(["\r\n", "\r"], "\n", (string) $raw);
        $lines = explode("\n", $text);
        $result = [
            'intro_lines' => [],
            'sections' => [],
            'featured_images' => [],
        ];

        $currentSection = '';
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

            if (substr($line, -1) === ':' && strpos($line, '- ') !== 0) {
                $currentSection = rtrim($line, ': ');
                if ($currentSection === '') $currentSection = 'Support';
                if (!isset($result['sections'][$currentSection])) {
                    $result['sections'][$currentSection] = [];
                }
                continue;
            }

            if (strpos($line, '- ') === 0) {
                $item = trim(substr($line, 2));
                if ($item === '') continue;
                if ($currentSection === '') {
                    $currentSection = 'Support';
                    if (!isset($result['sections'][$currentSection])) $result['sections'][$currentSection] = [];
                }
                $result['sections'][$currentSection][] = $item;
                continue;
            }

            if ($currentSection !== '') {
                $result['sections'][$currentSection][] = $line;
            } else {
                $result['intro_lines'][] = $line;
            }
        }

        return $result;
    }
}

$supportBlocks = support_parse_content((string) ($pageData['content_text'] ?? ''));
$hasStructuredSupport = count($supportBlocks['sections']) > 0;
?>

<head>
    <title><?php echo site_pages_h($pageTitle); ?></title>
    <?php include '../layouts/title-meta.php'; ?>
    <?php include '../layouts/head-css.php'; ?>
    <link href="assets/css/info-pages-typography.css" rel="stylesheet" type="text/css" />
    <style>
        .site-page-hero {
            border: 1px solid rgba(20, 184, 166, 0.22);
            border-radius: 14px;
            background: linear-gradient(135deg, rgba(20, 184, 166, 0.16), rgba(22, 167, 233, 0.08));
        }
        .support-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border-radius: 999px;
            border: 1px solid #bce9e3;
            background: #eefcf9;
            padding: 6px 10px;
            color: #334155;
        }
        .support-lane {
            border-width: 1px;
            border-style: solid;
            border-radius: 14px;
            padding: 14px;
        }
        .support-heading-badge {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 999px;
            color: #fff;
        }
        .support-list {
            margin: 10px 0 0;
            padding: 0;
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .support-list li {
            position: relative;
            padding-left: 22px;
            color: #1f2937;
        }
        .support-list li::before {
            content: "";
            position: absolute;
            left: 0;
            top: 0.42rem;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: currentColor;
            opacity: 0.35;
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
                                        <li class="breadcrumb-item active"><?php echo site_pages_h($pageLabel !== '' ? $pageLabel : 'Support'); ?></li>
                                    </ol>
                                </div>
                                <h4 class="page-title"><?php echo site_pages_h($pageLabel !== '' ? $pageLabel : 'Support'); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-12">
                            <div class="d-flex flex-wrap gap-2">
                                <a class="btn btn-sm btn-outline-primary" href="news.php">News</a>
                                <a class="btn btn-sm btn-outline-primary" href="about.php">About</a>
                                <a class="btn btn-sm btn-primary" href="support.php">Support</a>
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
                                        <h3 class="mb-2"><?php echo site_pages_h((string) ($pageData['hero_title'] ?? 'Support')); ?></h3>
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
                                            <div class="mb-3">
                                                <span class="support-chip">Last updated: <?php echo site_pages_h($updatedAtLabel); ?></span>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (count($supportBlocks['featured_images']) > 0): ?>
                                            <div class="mb-3">
                                                <?php foreach ($supportBlocks['featured_images'] as $img): ?>
                                                    <?php echo site_pages_render_infographic_html((string) ($img['path'] ?? ''), (string) ($img['caption'] ?? '')); ?>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (count($supportBlocks['intro_lines']) > 0): ?>
                                            <div class="mb-3">
                                                <?php foreach ($supportBlocks['intro_lines'] as $line): ?>
                                                    <p class="mb-2 text-dark"><?php echo site_pages_h((string) $line); ?></p>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($hasStructuredSupport): ?>
                                            <div class="row g-3">
                                                <?php $supportColorIndex = 0; ?>
                                                <?php foreach ($supportBlocks['sections'] as $sectionTitle => $items): ?>
                                                    <?php $scheme = support_color_scheme($supportColorIndex++); ?>
                                                    <div class="col-lg-6">
                                                        <section class="support-lane h-100" style="background-color: <?php echo site_pages_h($scheme['panel_bg']); ?>; border-color: <?php echo site_pages_h($scheme['panel_border']); ?>;">
                                                            <span class="support-heading-badge" style="background-color: <?php echo site_pages_h($scheme['badge']); ?>;"><?php echo site_pages_h((string) $sectionTitle); ?></span>
                                                            <?php if (count($items) > 0): ?>
                                                                <ul class="support-list" style="color: <?php echo site_pages_h($scheme['badge']); ?>;">
                                                                    <?php foreach ($items as $it): ?>
                                                                        <li><span style="color:#1f2937;"><?php echo site_pages_h((string) $it); ?></span></li>
                                                                    <?php endforeach; ?>
                                                                </ul>
                                                            <?php endif; ?>
                                                        </section>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
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
