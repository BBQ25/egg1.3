<?php include '../layouts/session.php'; ?>
<?php require_role('superadmin'); ?>
<?php require_once __DIR__ . '/../includes/site_pages.php'; ?>
<?php require_once __DIR__ . '/../includes/audit.php'; ?>
<?php include '../layouts/main.php'; ?>

<?php
ensure_audit_logs_table($conn);

$flash = isset($_SESSION['flash_message']) ? (string) $_SESSION['flash_message'] : '';
$flashType = isset($_SESSION['flash_type']) ? (string) $_SESSION['flash_type'] : 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

$pageKeys = site_pages_keys();
$pageRows = site_pages_rows($conn);
$activeKey = isset($_GET['tab']) ? site_pages_valid_key((string) $_GET['tab']) : '';
if ($activeKey === '') $activeKey = 'news';
if (!in_array($activeKey, $pageKeys, true)) $activeKey = 'news';

$superadminId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    if (!csrf_validate($csrf)) {
        $_SESSION['flash_message'] = 'Security check failed (CSRF). Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: admin-site-pages.php');
        exit;
    }

    $postKey = isset($_POST['page_key']) ? site_pages_valid_key((string) $_POST['page_key']) : '';
    if ($postKey === '') {
        $_SESSION['flash_message'] = 'Invalid page selection.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: admin-site-pages.php');
        exit;
    }

    $savePayload = [
        'nav_label' => isset($_POST['nav_label']) ? (string) $_POST['nav_label'] : '',
        'page_title' => isset($_POST['page_title']) ? (string) $_POST['page_title'] : '',
        'hero_title' => isset($_POST['hero_title']) ? (string) $_POST['hero_title'] : '',
        'hero_subtitle' => isset($_POST['hero_subtitle']) ? (string) $_POST['hero_subtitle'] : '',
        'content_text' => isset($_POST['content_text']) ? (string) $_POST['content_text'] : '',
        'is_published' => isset($_POST['is_published']) ? 1 : 0,
    ];

    [$ok, $message] = site_pages_save($conn, $postKey, $savePayload, $superadminId);
    if ($ok) {
        $_SESSION['flash_message'] = 'Page settings saved.';
        $_SESSION['flash_type'] = 'success';
        audit_log($conn, 'site_pages.updated', 'setting', null, 'Site page updated by superadmin.', [
            'page_key' => $postKey,
            'is_published' => !empty($savePayload['is_published']) ? 1 : 0,
        ]);
    } else {
        $_SESSION['flash_message'] = $message !== '' ? $message : 'Unable to save page settings.';
        $_SESSION['flash_type'] = 'danger';
    }

    header('Location: admin-site-pages.php?tab=' . rawurlencode($postKey));
    exit;
}

$pageRows = site_pages_rows($conn);
$activeRow = isset($pageRows[$activeKey]) ? $pageRows[$activeKey] : [];
if (!$activeRow) {
    $defaults = site_pages_defaults();
    $activeRow = isset($defaults[$activeKey]) ? $defaults[$activeKey] : [];
}

$updatedAtLabel = site_pages_format_timestamp((string) ($activeRow['updated_at'] ?? ''));
$infographicItems = site_pages_infographic_library(180);
?>

<head>
    <title>Site Pages | E-Record</title>
    <?php include '../layouts/title-meta.php'; ?>
    <?php include '../layouts/head-css.php'; ?>
    <style>
        .site-pages-tab .nav-link {
            border-radius: 10px;
            margin-right: 6px;
        }
        .site-pages-tab .nav-link.active {
            font-weight: 600;
        }
        .site-pages-dropzone {
            transition: box-shadow 140ms ease, border-color 140ms ease;
            border: 1px dashed rgba(47, 85, 212, 0.45);
        }
        .site-pages-dropzone.is-drop-target {
            border-color: rgba(47, 85, 212, 0.9);
            box-shadow: 0 0 0 0.2rem rgba(47, 85, 212, 0.15);
        }
        .infographic-card {
            cursor: grab;
            border: 1px solid rgba(15, 23, 42, 0.12);
            transition: transform 120ms ease, box-shadow 120ms ease;
        }
        .infographic-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.08);
        }
        .infographic-card.dragging {
            opacity: 0.65;
        }
        .infographic-thumb {
            width: 100%;
            aspect-ratio: 16 / 9;
            object-fit: cover;
            border-radius: 8px;
            background: #f8fafc;
        }
        .token-preview {
            font-size: 0.75rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
    </style>
</head>

<body>
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
                                        <li class="breadcrumb-item"><a href="admin-dashboard.php">Admin</a></li>
                                        <li class="breadcrumb-item active">Site Pages</li>
                                    </ol>
                                </div>
                                <h4 class="page-title">Site Pages (Superadmin)</h4>
                            </div>
                        </div>
                    </div>

                    <?php if ($flash !== ''): ?>
                        <?php
                            $flashClass = 'alert-success';
                            if (in_array(strtolower($flashType), ['danger', 'error'], true)) $flashClass = 'alert-danger';
                            if (strtolower($flashType) === 'warning') $flashClass = 'alert-warning';
                            if (strtolower($flashType) === 'info') $flashClass = 'alert-info';
                        ?>
                        <div class="row">
                            <div class="col-12">
                                <div class="alert <?php echo site_pages_h($flashClass); ?>" role="alert">
                                    <?php echo site_pages_h($flash); ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title mb-2">Manage Public Information Pages</h4>
                                    <p class="text-muted mb-3">
                                        Edit the content shown in <code>News</code>, <code>About</code>, <code>Support</code>, and <code>Contact Us</code>.
                                    </p>

                                    <div class="site-pages-tab mb-3">
                                        <?php foreach ($pageKeys as $key): ?>
                                            <?php
                                                $row = isset($pageRows[$key]) ? $pageRows[$key] : [];
                                                $label = trim((string) ($row['nav_label'] ?? site_pages_label_for_key($key)));
                                                if ($label === '') $label = ucfirst($key);
                                                $activeClass = ($key === $activeKey) ? 'btn-primary' : 'btn-outline-primary';
                                            ?>
                                            <a class="btn btn-sm <?php echo site_pages_h($activeClass); ?>" href="admin-site-pages.php?tab=<?php echo rawurlencode($key); ?>">
                                                <?php echo site_pages_h($label); ?>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>

                                    <form method="post" class="row g-3">
                                        <input type="hidden" name="csrf_token" value="<?php echo site_pages_h(csrf_token()); ?>">
                                        <input type="hidden" name="page_key" value="<?php echo site_pages_h($activeKey); ?>">

                                        <div class="col-md-4">
                                            <label class="form-label" for="nav_label">Navigation Label</label>
                                            <input
                                                type="text"
                                                class="form-control"
                                                id="nav_label"
                                                name="nav_label"
                                                maxlength="80"
                                                value="<?php echo site_pages_h((string) ($activeRow['nav_label'] ?? '')); ?>"
                                                required
                                            >
                                            <div class="form-text">Shown in sidebar and page header.</div>
                                        </div>

                                        <div class="col-md-8">
                                            <label class="form-label" for="page_title">Browser/Page Title</label>
                                            <input
                                                type="text"
                                                class="form-control"
                                                id="page_title"
                                                name="page_title"
                                                maxlength="160"
                                                value="<?php echo site_pages_h((string) ($activeRow['page_title'] ?? '')); ?>"
                                                required
                                            >
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label" for="hero_title">Hero Title</label>
                                            <input
                                                type="text"
                                                class="form-control"
                                                id="hero_title"
                                                name="hero_title"
                                                maxlength="190"
                                                value="<?php echo site_pages_h((string) ($activeRow['hero_title'] ?? '')); ?>"
                                                required
                                            >
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label" for="hero_subtitle">Hero Subtitle</label>
                                            <input
                                                type="text"
                                                class="form-control"
                                                id="hero_subtitle"
                                                name="hero_subtitle"
                                                maxlength="500"
                                                value="<?php echo site_pages_h((string) ($activeRow['hero_subtitle'] ?? '')); ?>"
                                            >
                                        </div>

                                        <div class="col-12">
                                            <label class="form-label" for="content_text">Page Content</label>
                                            <textarea
                                                class="form-control site-pages-dropzone"
                                                id="content_text"
                                                name="content_text"
                                                rows="12"
                                                maxlength="60000"
                                                required
                                            ><?php echo site_pages_h((string) ($activeRow['content_text'] ?? '')); ?></textarea>
                                            <div class="form-text">
                                                Line breaks are preserved on the page.
                                                Drag an infographic from the library below into this textarea, or click <strong>Insert</strong>.
                                            </div>
                                        </div>

                                        <div class="col-12">
                                            <div class="alert alert-light border mb-0">
                                                <div class="fw-semibold mb-1">Infographic Token Format</div>
                                                <div class="small text-muted mb-0">
                                                    <code>[[infographic:assets/images/path.png|Optional caption]]</code>
                                                    <br>
                                                    Tokens are rendered as image blocks on News, About, Support, and Contact Us pages.
                                                </div>
                                            </div>
                                        </div>

                                        <div class="col-12">
                                            <h5 class="mb-2">Infographics Library (Drag and Drop)</h5>
                                            <?php if (count($infographicItems) === 0): ?>
                                                <div class="alert alert-warning mb-0">
                                                    No infographic files found. Add images in <code>assets/images/site-infographics</code>.
                                                </div>
                                            <?php else: ?>
                                                <div class="row g-2">
                                                    <?php foreach ($infographicItems as $item): ?>
                                                        <?php
                                                            $imgPath = (string) ($item['path'] ?? '');
                                                            $imgTitle = (string) ($item['title'] ?? 'Infographic');
                                                            $imgGroup = (string) ($item['group'] ?? 'Library');
                                                            $imgToken = (string) ($item['token'] ?? '');
                                                        ?>
                                                        <div class="col-sm-6 col-md-4 col-lg-3">
                                                            <div
                                                                class="card h-100 infographic-card"
                                                                draggable="true"
                                                                data-infographic-token="<?php echo site_pages_h($imgToken); ?>"
                                                            >
                                                                <div class="card-body p-2">
                                                                    <img src="<?php echo site_pages_h($imgPath); ?>" alt="<?php echo site_pages_h($imgTitle); ?>" class="infographic-thumb mb-2">
                                                                    <div class="fw-semibold small text-truncate"><?php echo site_pages_h($imgTitle); ?></div>
                                                                    <div class="text-muted small text-truncate"><?php echo site_pages_h($imgGroup); ?></div>
                                                                    <div class="token-preview text-muted mt-1"><?php echo site_pages_h($imgToken); ?></div>
                                                                    <button type="button" class="btn btn-sm btn-outline-primary w-100 mt-2" data-insert-infographic>
                                                                        Insert
                                                                    </button>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="col-12">
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" id="is_published" name="is_published" value="1" <?php echo !empty($activeRow['is_published']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="is_published">Published (visible to users)</label>
                                            </div>
                                        </div>

                                        <div class="col-12 d-flex flex-wrap align-items-center gap-2">
                                            <button type="submit" class="btn btn-primary">
                                                <i class="ri-save-line me-1" aria-hidden="true"></i>
                                                Save Page
                                            </button>
                                            <?php if ($updatedAtLabel !== ''): ?>
                                                <span class="text-muted small">Last updated: <?php echo site_pages_h($updatedAtLabel); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php include '../layouts/footer.php'; ?>
        </div>
    </div>

    <?php include '../layouts/right-sidebar.php'; ?>
    <?php include '../layouts/footer-scripts.php'; ?>
    <script src="assets/js/app.min.js"></script>
    <script>
    (function () {
        var textarea = document.getElementById('content_text');
        if (!textarea) return;

        var cards = document.querySelectorAll('[data-infographic-token]');
        var activeToken = '';

        function insertToken(el, token) {
            token = (token || '').trim();
            if (!token) return;

            var start = typeof el.selectionStart === 'number' ? el.selectionStart : el.value.length;
            var end = typeof el.selectionEnd === 'number' ? el.selectionEnd : el.value.length;
            var before = el.value.substring(0, start);
            var after = el.value.substring(end);

            var needsLeadingNewline = before.length > 0 && !before.endsWith('\n');
            var needsTrailingNewline = after.length > 0 && !after.startsWith('\n');
            var insertText = (needsLeadingNewline ? '\n' : '') + token + (needsTrailingNewline ? '\n' : '');

            el.value = before + insertText + after;
            var caretPos = before.length + insertText.length;
            el.selectionStart = caretPos;
            el.selectionEnd = caretPos;
            el.focus();
        }

        cards.forEach(function (card) {
            var token = card.getAttribute('data-infographic-token') || '';
            var insertButton = card.querySelector('[data-insert-infographic]');

            card.addEventListener('dragstart', function (e) {
                activeToken = token;
                card.classList.add('dragging');
                if (e.dataTransfer) {
                    e.dataTransfer.setData('text/plain', token);
                    e.dataTransfer.effectAllowed = 'copy';
                }
            });

            card.addEventListener('dragend', function () {
                card.classList.remove('dragging');
            });

            if (insertButton) {
                insertButton.addEventListener('click', function () {
                    insertToken(textarea, token);
                });
            }
        });

        textarea.addEventListener('dragover', function (e) {
            if (cards.length === 0) return;
            e.preventDefault();
            textarea.classList.add('is-drop-target');
            if (e.dataTransfer) e.dataTransfer.dropEffect = 'copy';
        });

        textarea.addEventListener('dragleave', function () {
            textarea.classList.remove('is-drop-target');
        });

        textarea.addEventListener('drop', function (e) {
            if (cards.length === 0) return;
            e.preventDefault();
            textarea.classList.remove('is-drop-target');

            var token = '';
            if (e.dataTransfer) {
                token = e.dataTransfer.getData('text/plain') || '';
            }
            if (!token) token = activeToken;
            insertToken(textarea, token);
            activeToken = '';
        });
    })();
    </script>
</body>

</html>
