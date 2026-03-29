<?php include '../layouts/session.php'; ?>
<?php require_once __DIR__ . '/../includes/site_pages.php'; ?>
<?php include '../layouts/main.php'; ?>

<?php
$pageKey = 'contact';
$pageData = site_pages_get($conn, $pageKey);
if (!is_array($pageData)) {
    $defaults = site_pages_defaults();
    $pageData = isset($defaults[$pageKey]) ? $defaults[$pageKey] : [];
}

$pageTitle = trim((string) ($pageData['page_title'] ?? 'Contact Us | E-Record'));
$pageLabel = trim((string) ($pageData['nav_label'] ?? site_pages_label_for_key($pageKey)));
$isPublished = ((int) ($pageData['is_published'] ?? 1) === 1);
$updatedAtLabel = site_pages_format_timestamp((string) ($pageData['updated_at'] ?? ''));

if (!function_exists('contact_color_scheme')) {
    function contact_color_scheme($index) {
        $shared = site_pages_theme_scheme($index, 0.90, 0.72);
        return [
            'badge' => (string) ($shared['badge'] ?? '#f97316'),
            'panel_bg' => (string) ($shared['panel_bg'] ?? '#fff6ee'),
            'panel_border' => (string) ($shared['panel_border'] ?? '#fedbb9'),
        ];
    }
}

if (!function_exists('contact_parse_content')) {
    function contact_parse_content($raw) {
        $text = str_replace(["\r\n", "\r"], "\n", (string) $raw);
        $lines = explode("\n", $text);
        $result = [
            'methods' => [],
            'notes' => [],
            'featured_images' => [],
        ];

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

            if (preg_match('/^([A-Za-z][A-Za-z0-9\s\/&()\-]{1,60})\s*:\s*(.+)$/', $line, $m)) {
                $result['methods'][] = [
                    'label' => trim((string) $m[1]),
                    'value' => trim((string) $m[2]),
                ];
                continue;
            }

            $result['notes'][] = $line;
        }

        return $result;
    }
}

if (!function_exists('contact_method_value_html')) {
    function contact_method_value_html($label, $value) {
        $labelLower = strtolower(trim((string) $label));
        $value = trim((string) $value);
        if ($value === '') return '';

        if (strpos($labelLower, 'email') !== false) {
            $email = filter_var($value, FILTER_VALIDATE_EMAIL);
            if ($email !== false) {
                return '<a href="mailto:' . site_pages_h((string) $email) . '" class="text-decoration-underline">' . site_pages_h((string) $email) . '</a>';
            }
        }

        if (strpos($labelLower, 'phone') !== false || strpos($labelLower, 'mobile') !== false || strpos($labelLower, 'contact') !== false) {
            $tel = preg_replace('/[^0-9+]/', '', $value);
            if ($tel !== '') {
                return '<a href="tel:' . site_pages_h($tel) . '" class="text-decoration-underline">' . site_pages_h($value) . '</a>';
            }
        }

        return site_pages_h($value);
    }
}

$contactBlocks = contact_parse_content((string) ($pageData['content_text'] ?? ''));
$hasContactMethods = count($contactBlocks['methods']) > 0;
?>

<head>
    <title><?php echo site_pages_h($pageTitle); ?></title>
    <?php include '../layouts/title-meta.php'; ?>
    <?php include '../layouts/head-css.php'; ?>
    <link href="assets/css/info-pages-typography.css" rel="stylesheet" type="text/css" />
    <style>
        .site-page-hero {
            border: 1px solid rgba(249, 115, 22, 0.24);
            border-radius: 14px;
            background: linear-gradient(135deg, rgba(249, 115, 22, 0.16), rgba(14, 165, 233, 0.10));
        }
        .contact-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border-radius: 999px;
            border: 1px solid #fedbb9;
            background: #fff6ee;
            padding: 6px 10px;
            color: #334155;
        }
        .contact-method {
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 14px;
        }
        .contact-method-badge {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 999px;
            color: #fff;
        }
        .contact-method-value {
            margin-top: 10px;
            color: #1f2937;
            word-break: break-word;
        }
        .contact-note {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #f8fafc;
            padding: 10px 12px;
            color: #334155;
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
                                        <li class="breadcrumb-item active"><?php echo site_pages_h($pageLabel !== '' ? $pageLabel : 'Contact Us'); ?></li>
                                    </ol>
                                </div>
                                <h4 class="page-title"><?php echo site_pages_h($pageLabel !== '' ? $pageLabel : 'Contact Us'); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-12">
                            <div class="d-flex flex-wrap gap-2">
                                <a class="btn btn-sm btn-outline-primary" href="news.php">News</a>
                                <a class="btn btn-sm btn-outline-primary" href="about.php">About</a>
                                <a class="btn btn-sm btn-outline-primary" href="support.php">Support</a>
                                <a class="btn btn-sm btn-primary" href="contact-us.php">Contact Us</a>
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
                                        <h3 class="mb-2"><?php echo site_pages_h((string) ($pageData['hero_title'] ?? 'Contact Us')); ?></h3>
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
                                                <span class="contact-chip">Last updated: <?php echo site_pages_h($updatedAtLabel); ?></span>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (count($contactBlocks['featured_images']) > 0): ?>
                                            <div class="mb-3">
                                                <?php foreach ($contactBlocks['featured_images'] as $img): ?>
                                                    <?php echo site_pages_render_infographic_html((string) ($img['path'] ?? ''), (string) ($img['caption'] ?? '')); ?>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($hasContactMethods): ?>
                                            <div class="row g-3 mb-3">
                                                <?php foreach ($contactBlocks['methods'] as $idx => $method): ?>
                                                    <?php
                                                        $scheme = contact_color_scheme($idx);
                                                        $label = trim((string) ($method['label'] ?? 'Contact'));
                                                        $value = trim((string) ($method['value'] ?? ''));
                                                    ?>
                                                    <div class="col-lg-6">
                                                        <article class="contact-method h-100" style="background-color: <?php echo site_pages_h($scheme['panel_bg']); ?>; border-color: <?php echo site_pages_h($scheme['panel_border']); ?>;">
                                                            <span class="contact-method-badge" style="background-color: <?php echo site_pages_h($scheme['badge']); ?>;"><?php echo site_pages_h($label); ?></span>
                                                            <div class="contact-method-value"><?php echo contact_method_value_html($label, $value); ?></div>
                                                        </article>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (count($contactBlocks['notes']) > 0): ?>
                                            <div class="d-flex flex-column gap-2 mb-2">
                                                <?php foreach ($contactBlocks['notes'] as $note): ?>
                                                    <div class="contact-note"><?php echo site_pages_h((string) $note); ?></div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!$hasContactMethods && count($contactBlocks['notes']) === 0): ?>
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
