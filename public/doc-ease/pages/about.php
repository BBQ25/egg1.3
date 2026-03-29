<?php include '../layouts/session.php'; ?>
<?php require_once __DIR__ . '/../includes/site_pages.php'; ?>
<?php include '../layouts/main.php'; ?>

<?php
$pageKey = 'about';
$pageData = site_pages_get($conn, $pageKey);
if (!is_array($pageData)) {
    $defaults = site_pages_defaults();
    $pageData = isset($defaults[$pageKey]) ? $defaults[$pageKey] : [];
}

$pageTitle = trim((string) ($pageData['page_title'] ?? 'About | E-Record'));
$pageLabel = trim((string) ($pageData['nav_label'] ?? site_pages_label_for_key($pageKey)));
$isPublished = ((int) ($pageData['is_published'] ?? 1) === 1);
$updatedAtLabel = site_pages_format_timestamp((string) ($pageData['updated_at'] ?? ''));

if (!function_exists('about_color_scheme')) {
    function about_color_scheme($index) {
        $shared = site_pages_theme_scheme($index, 0.89, 0.70);
        return [
            'badge' => (string) ($shared['badge'] ?? '#3e60d5'),
            'panel_bg' => (string) ($shared['panel_bg'] ?? '#f4f7ff'),
            'panel_border' => (string) ($shared['panel_border'] ?? '#dbe5ff'),
        ];
    }
}

if (!function_exists('about_parse_content')) {
    function about_parse_content($raw) {
        $text = str_replace(["\r\n", "\r"], "\n", (string) $raw);
        $lines = explode("\n", $text);
        $result = [
            'paragraphs' => [],
            'featured_images' => [],
        ];

        $buffer = [];
        $flush = function () use (&$buffer, &$result) {
            if (count($buffer) === 0) return;
            $paragraph = trim(implode("\n", $buffer));
            if ($paragraph !== '') $result['paragraphs'][] = $paragraph;
            $buffer = [];
        };

        foreach ($lines as $rawLine) {
            $line = trim((string) $rawLine);

            if (preg_match('/^\[\[infographic:([^\]|]+)(?:\|([^\]]*))?\]\]$/i', $line, $m)) {
                $flush();
                $result['featured_images'][] = [
                    'path' => trim((string) ($m[1] ?? '')),
                    'caption' => trim((string) ($m[2] ?? '')),
                ];
                continue;
            }

            if ($line === '') {
                $flush();
                continue;
            }

            $buffer[] = $line;
        }

        $flush();
        return $result;
    }
}

if (!function_exists('about_block_label')) {
    function about_block_label($index) {
        $labels = ['Platform', 'Workflow', 'Governance', 'Direction', 'Context'];
        $i = (int) $index;
        if ($i < 0) $i = 0;
        return $labels[$i % count($labels)];
    }
}

$aboutBlocks = about_parse_content((string) ($pageData['content_text'] ?? ''));
$hasStyledAboutBlocks = count($aboutBlocks['paragraphs']) > 0;
?>

<head>
    <title><?php echo site_pages_h($pageTitle); ?></title>
    <?php include '../layouts/title-meta.php'; ?>
    <?php include '../layouts/head-css.php'; ?>
    <link href="assets/css/info-pages-typography.css" rel="stylesheet" type="text/css" />
    <style>
        .site-page-hero {
            border: 1px solid rgba(62, 96, 213, 0.20);
            border-radius: 14px;
            background: linear-gradient(135deg, rgba(62, 96, 213, 0.14), rgba(107, 94, 174, 0.08));
        }
        .about-meta-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border-radius: 999px;
            border: 1px solid #dbe5ff;
            background: #f4f7ff;
            padding: 6px 10px;
            color: #334155;
        }
        .about-story {
            border: 1px solid #dbe5ff;
            border-radius: 14px;
            padding: 14px 14px 12px;
        }
        .about-story-label {
            display: inline-block;
            border-radius: 999px;
            padding: 6px 10px;
            color: #fff;
        }
        .about-story-text {
            margin: 10px 0 0;
            color: #1f2937;
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
                                        <li class="breadcrumb-item active"><?php echo site_pages_h($pageLabel !== '' ? $pageLabel : 'About'); ?></li>
                                    </ol>
                                </div>
                                <h4 class="page-title"><?php echo site_pages_h($pageLabel !== '' ? $pageLabel : 'About'); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-12">
                            <div class="d-flex flex-wrap gap-2">
                                <a class="btn btn-sm btn-outline-primary" href="news.php">News</a>
                                <a class="btn btn-sm btn-primary" href="about.php">About</a>
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
                                        <h3 class="mb-2"><?php echo site_pages_h((string) ($pageData['hero_title'] ?? 'About')); ?></h3>
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
                                                <span class="about-meta-chip">Last updated: <?php echo site_pages_h($updatedAtLabel); ?></span>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (count($aboutBlocks['featured_images']) > 0): ?>
                                            <div class="mb-3">
                                                <?php foreach ($aboutBlocks['featured_images'] as $img): ?>
                                                    <?php echo site_pages_render_infographic_html((string) ($img['path'] ?? ''), (string) ($img['caption'] ?? '')); ?>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($hasStyledAboutBlocks): ?>
                                            <div class="row g-3">
                                                <?php foreach ($aboutBlocks['paragraphs'] as $idx => $paragraph): ?>
                                                    <?php
                                                        $scheme = about_color_scheme($idx);
                                                        $label = about_block_label($idx);
                                                    ?>
                                                    <div class="col-lg-6">
                                                        <article class="about-story h-100" style="background-color: <?php echo site_pages_h($scheme['panel_bg']); ?>; border-color: <?php echo site_pages_h($scheme['panel_border']); ?>;">
                                                            <span class="about-story-label" style="background-color: <?php echo site_pages_h($scheme['badge']); ?>;"><?php echo site_pages_h($label); ?></span>
                                                            <p class="about-story-text"><?php echo nl2br(site_pages_h((string) $paragraph)); ?></p>
                                                        </article>
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
