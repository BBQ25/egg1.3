<?php include '../layouts/session.php'; ?>
<?php require_role('superadmin'); ?>

<?php
require_once __DIR__ . '/../includes/report_templates.php';

report_templates_ensure_tables($conn);

$superadminId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;

$flash = isset($_SESSION['flash_message']) ? (string) $_SESSION['flash_message'] : '';
$flashType = isset($_SESSION['flash_type']) ? (string) $_SESSION['flash_type'] : 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

if (!function_exists('rt_h')) {
    function rt_h($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

$selectedId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$isNew = !empty($_GET['new']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    if (!csrf_validate($csrf)) {
        $_SESSION['flash_message'] = 'Security check failed (CSRF). Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: admin-report-templates.php');
        exit;
    }

    $action = isset($_POST['action']) ? trim((string) $_POST['action']) : '';
    $postId = isset($_POST['template_id']) ? (int) $_POST['template_id'] : 0;

    if ($action === 'save_template') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $templateKey = trim((string) ($_POST['template_key'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $status = (string) ($_POST['status'] ?? 'active');
        if (!in_array($status, ['active', 'inactive'], true)) $status = 'active';

        $docCode = trim((string) ($_POST['doc_code'] ?? ''));
        $revision = trim((string) ($_POST['revision'] ?? ''));
        $issueDate = trim((string) ($_POST['issue_date'] ?? ''));

        $pageFormat = strtoupper(trim((string) ($_POST['page_format'] ?? 'A4')));
        if (!in_array($pageFormat, ['A4', 'LETTER', 'LEGAL', 'CUSTOM'], true)) $pageFormat = 'A4';
        $orientation = strtolower(trim((string) ($_POST['orientation'] ?? 'portrait')));
        if (!in_array($orientation, ['portrait', 'landscape'], true)) $orientation = 'portrait';

        $pageW = null;
        $pageH = null;
        if ($pageFormat === 'CUSTOM') {
            $pageW = (float) ($_POST['page_width_mm'] ?? 0);
            $pageH = (float) ($_POST['page_height_mm'] ?? 0);
        }

        $mt = (float) ($_POST['margin_top_mm'] ?? 10);
        $mr = (float) ($_POST['margin_right_mm'] ?? 10);
        $mb = (float) ($_POST['margin_bottom_mm'] ?? 10);
        $ml = (float) ($_POST['margin_left_mm'] ?? 10);
        $hh = (float) ($_POST['header_height_mm'] ?? 20);
        $fh = (float) ($_POST['footer_height_mm'] ?? 15);

        $headerHtml = (string) ($_POST['header_html'] ?? '');
        $footerHtml = (string) ($_POST['footer_html'] ?? '');
        $bodyHtml = (string) ($_POST['body_html'] ?? '');
        $css = (string) ($_POST['css'] ?? '');
        $sampleJson = (string) ($_POST['sample_data_json'] ?? '');

        if ($name === '') {
            $_SESSION['flash_message'] = 'Template name is required.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: admin-report-templates.php' . ($postId > 0 ? ('?id=' . $postId) : '?new=1'));
            exit;
        }
        if (strlen($name) > 120 || strlen($templateKey) > 64 || strlen($description) > 255) {
            $_SESSION['flash_message'] = 'One or more fields are too long.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: admin-report-templates.php' . ($postId > 0 ? ('?id=' . $postId) : '?new=1'));
            exit;
        }
        if (strlen($docCode) > 64 || strlen($revision) > 64 || strlen($issueDate) > 120) {
            $_SESSION['flash_message'] = 'Doc code / revision / issue date is too long.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: admin-report-templates.php' . ($postId > 0 ? ('?id=' . $postId) : '?new=1'));
            exit;
        }
        if ($pageFormat === 'CUSTOM' && ($pageW <= 0 || $pageH <= 0)) {
            $_SESSION['flash_message'] = 'Custom page size requires width and height (mm).';
            $_SESSION['flash_type'] = 'danger';
            header('Location: admin-report-templates.php' . ($postId > 0 ? ('?id=' . $postId) : '?new=1'));
            exit;
        }

        $data = [
            'template_key' => $templateKey,
            'name' => $name,
            'description' => $description,
            'status' => $status,
            'doc_code' => $docCode,
            'revision' => $revision,
            'issue_date' => $issueDate,
            'page_format' => $pageFormat,
            'orientation' => $orientation,
            'page_width_mm' => ($pageFormat === 'CUSTOM') ? $pageW : null,
            'page_height_mm' => ($pageFormat === 'CUSTOM') ? $pageH : null,
            'margin_top_mm' => $mt,
            'margin_right_mm' => $mr,
            'margin_bottom_mm' => $mb,
            'margin_left_mm' => $ml,
            'header_height_mm' => $hh,
            'footer_height_mm' => $fh,
            'header_html' => $headerHtml,
            'footer_html' => $footerHtml,
            'body_html' => $bodyHtml,
            'css' => $css,
            'sample_data_json' => $sampleJson,
        ];

        $err = '';
        if ($postId > 0) {
            $ok = report_template_update($conn, $postId, $data, $superadminId, $err);
            if ($ok) {
                $_SESSION['flash_message'] = 'Template saved.';
                $_SESSION['flash_type'] = 'success';
                header('Location: admin-report-templates.php?id=' . $postId);
                exit;
            }
            $_SESSION['flash_message'] = $err !== '' ? $err : 'Unable to save template.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: admin-report-templates.php?id=' . $postId);
            exit;
        }

        $newId = report_template_insert($conn, $data, $superadminId, $err);
        if ($newId > 0) {
            $_SESSION['flash_message'] = 'Template created.';
            $_SESSION['flash_type'] = 'success';
            header('Location: admin-report-templates.php?id=' . $newId);
            exit;
        }
        $_SESSION['flash_message'] = $err !== '' ? $err : 'Unable to create template.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: admin-report-templates.php?new=1');
        exit;
    }

    if ($action === 'duplicate_template') {
        if ($postId <= 0) {
            $_SESSION['flash_message'] = 'Invalid template.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: admin-report-templates.php');
            exit;
        }
        $err = '';
        $newId = report_template_duplicate($conn, $postId, $superadminId, $err);
        if ($newId > 0) {
            $_SESSION['flash_message'] = 'Template duplicated.';
            $_SESSION['flash_type'] = 'success';
            header('Location: admin-report-templates.php?id=' . $newId);
            exit;
        }
        $_SESSION['flash_message'] = $err !== '' ? $err : 'Unable to duplicate template.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: admin-report-templates.php?id=' . $postId);
        exit;
    }

    if ($action === 'delete_template') {
        if ($postId <= 0) {
            $_SESSION['flash_message'] = 'Invalid template.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: admin-report-templates.php');
            exit;
        }
        $err = '';
        $ok = report_template_delete($conn, $postId, $err);
        if ($ok) {
            $_SESSION['flash_message'] = 'Template deleted.';
            $_SESSION['flash_type'] = 'success';
            header('Location: admin-report-templates.php');
            exit;
        }
        $_SESSION['flash_message'] = $err !== '' ? $err : 'Unable to delete template.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: admin-report-templates.php?id=' . $postId);
        exit;
    }

    $_SESSION['flash_message'] = 'Invalid request.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: admin-report-templates.php');
    exit;
}

$templates = report_templates_list($conn);
if ($selectedId <= 0 && !$isNew && count($templates) > 0) {
    $selectedId = (int) ($templates[0]['id'] ?? 0);
}

$tpl = null;
if (!$isNew && $selectedId > 0) {
    $tpl = report_templates_get($conn, $selectedId);
}
if (!is_array($tpl)) {
    $tpl = report_template_default_fields();
    $isNew = true;
}

$csrf = csrf_token();
$previewHref = $selectedId > 0 ? ('admin-report-template-render.php?id=' . $selectedId) : '';
$downloadPdfHref = $selectedId > 0 ? ($previewHref . '&download=pdf&csrf_token=' . urlencode($csrf)) : '';
$downloadDocxHref = $selectedId > 0 ? ($previewHref . '&download=docx&csrf_token=' . urlencode($csrf)) : '';
?>

<?php include '../layouts/main.php'; ?>

<head>
    <title>Report Templates | E-Record</title>
    <?php include '../layouts/title-meta.php'; ?>
    <?php include '../layouts/head-css.php'; ?>
    <link href="assets/vendor/codemirror/codemirror.min.css" rel="stylesheet" type="text/css" />
    <style>
        .CodeMirror { border: 1px solid #e5e7eb; border-radius: .5rem; height: 260px; }
        .rt-actions .btn { white-space: nowrap; }
        iframe.rt-preview { width: 100%; height: 740px; border: 1px solid #e5e7eb; border-radius: .75rem; background: #fff; }
        .rt-hint code { font-size: 0.85em; }
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
                                        <li class="breadcrumb-item active">Report Templates</li>
                                    </ol>
                                </div>
                                <h4 class="page-title">Report Templates (Superadmin)</h4>
                            </div>
                        </div>
                    </div>

                    <?php if ($flash !== ''): ?>
                        <div class="row">
                            <div class="col-12">
                                <div class="alert alert-<?php echo rt_h($flashType); ?>" role="alert">
                                    <?php echo rt_h($flash); ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-lg-4">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                        <h4 class="header-title mb-0">Templates</h4>
                                        <a class="btn btn-sm btn-primary" href="admin-report-templates.php?new=1">
                                            <i class="ri-add-line me-1" aria-hidden="true"></i>
                                            New
                                        </a>
                                    </div>

                                    <?php if (count($templates) === 0): ?>
                                        <div class="alert alert-info mb-0" role="alert">
                                            No templates yet. Click <strong>New</strong> to create one.
                                        </div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-sm table-hover align-middle mb-0">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>Template</th>
                                                        <th>Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($templates as $t): ?>
                                                        <?php
                                                            $tid = (int) ($t['id'] ?? 0);
                                                            $activeRow = (!$isNew && $tid === $selectedId);
                                                            $badge = ((string) ($t['status'] ?? 'active')) === 'active' ? 'bg-success' : 'bg-secondary';
                                                        ?>
                                                        <tr class="<?php echo $activeRow ? 'table-primary' : ''; ?>">
                                                            <td>
                                                                <div class="fw-semibold">
                                                                    <a href="admin-report-templates.php?id=<?php echo $tid; ?>">
                                                                        <?php echo rt_h((string) ($t['name'] ?? '')); ?>
                                                                    </a>
                                                                </div>
                                                                <div class="text-muted small">
                                                                    <code><?php echo rt_h((string) ($t['template_key'] ?? '')); ?></code>
                                                                </div>
                                                            </td>
                                                            <td>
                                                                <span class="badge <?php echo $badge; ?>">
                                                                    <?php echo rt_h((string) ($t['status'] ?? 'active')); ?>
                                                                </span>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>

                                    <div class="rt-hint text-muted small mt-3">
                                        Placeholders:
                                        <code>{{title}}</code>,
                                        <code>{{subtitle}}</code>,
                                        <code>{{month_label}}</code>,
                                        <code>{{year_label}}</code>,
                                        <code>{{school_year_term}}</code>,
                                        <code>{{{rows_html}}}</code>,
                                        <code>{{{content}}}</code>,
                                        <code>{{template.doc_code}}</code>,
                                        <code>{{template.revision}}</code>,
                                        <code>{{template.issue_date}}</code>,
                                        <code>{{generated.date}}</code>,
                                        <code>{{user.name}}</code>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-8">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3 rt-actions">
                                        <div>
                                            <h4 class="header-title mb-0">
                                                <?php echo $isNew ? 'New Template' : 'Edit Template'; ?>
                                            </h4>
                                            <?php if (!$isNew): ?>
                                                <div class="text-muted small">ID: <?php echo (int) $selectedId; ?></div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="d-flex flex-wrap gap-2">
                                            <?php if (!$isNew && $previewHref !== ''): ?>
                                                <a class="btn btn-outline-secondary btn-sm" href="<?php echo rt_h($previewHref); ?>" target="_blank" rel="noopener">
                                                    <i class="ri-eye-line me-1" aria-hidden="true"></i>
                                                    Preview
                                                </a>
                                                <a class="btn btn-outline-primary btn-sm" href="<?php echo rt_h($downloadPdfHref); ?>">
                                                    <i class="ri-file-pdf-2-line me-1" aria-hidden="true"></i>
                                                    PDF
                                                </a>
                                                <a class="btn btn-outline-primary btn-sm" href="<?php echo rt_h($downloadDocxHref); ?>">
                                                    <i class="ri-file-word-2-line me-1" aria-hidden="true"></i>
                                                    DOCX
                                                </a>
                                            <?php endif; ?>

                                            <?php if (!$isNew): ?>
                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?php echo rt_h($csrf); ?>">
                                                    <input type="hidden" name="action" value="duplicate_template">
                                                    <input type="hidden" name="template_id" value="<?php echo (int) $selectedId; ?>">
                                                    <button type="submit" class="btn btn-outline-info btn-sm">
                                                        <i class="ri-file-copy-2-line me-1" aria-hidden="true"></i>
                                                        Duplicate
                                                    </button>
                                                </form>
                                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this template? This cannot be undone.');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo rt_h($csrf); ?>">
                                                    <input type="hidden" name="action" value="delete_template">
                                                    <input type="hidden" name="template_id" value="<?php echo (int) $selectedId; ?>">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm">
                                                        <i class="ri-delete-bin-6-line me-1" aria-hidden="true"></i>
                                                        Delete
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <form method="post" id="tplForm">
                                        <input type="hidden" name="csrf_token" value="<?php echo rt_h($csrf); ?>">
                                        <input type="hidden" name="action" value="save_template">
                                        <input type="hidden" name="template_id" value="<?php echo $isNew ? 0 : (int) $selectedId; ?>">

                                        <div class="row g-3">
                                            <div class="col-md-8">
                                                <label class="form-label">Template Name</label>
                                                <input class="form-control" name="name" value="<?php echo rt_h((string) ($tpl['name'] ?? '')); ?>" required maxlength="120">
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Template Key</label>
                                                <input class="form-control" name="template_key" value="<?php echo rt_h((string) ($tpl['template_key'] ?? '')); ?>" maxlength="64" placeholder="auto-from-name">
                                            </div>

                                            <div class="col-12">
                                                <label class="form-label">Description</label>
                                                <input class="form-control" name="description" value="<?php echo rt_h((string) ($tpl['description'] ?? '')); ?>" maxlength="255" placeholder="Optional">
                                            </div>

                                            <div class="col-md-3">
                                                <label class="form-label">Status</label>
                                                <select class="form-select" name="status">
                                                    <?php $s = (string) ($tpl['status'] ?? 'active'); ?>
                                                    <option value="active" <?php echo $s === 'active' ? 'selected' : ''; ?>>Active</option>
                                                    <option value="inactive" <?php echo $s === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                                </select>
                                            </div>
                                            <div class="col-md-5">
                                                <label class="form-label">Document Code</label>
                                                <input class="form-control" name="doc_code" value="<?php echo rt_h((string) ($tpl['doc_code'] ?? '')); ?>" maxlength="64" placeholder="e.g. SLSU-QF-IN41">
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label">Revision</label>
                                                <input class="form-control" name="revision" value="<?php echo rt_h((string) ($tpl['revision'] ?? '')); ?>" maxlength="64" placeholder="01">
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label">Issue Date</label>
                                                <input class="form-control" name="issue_date" value="<?php echo rt_h((string) ($tpl['issue_date'] ?? '')); ?>" maxlength="120" placeholder="14 October 2019">
                                            </div>

                                            <div class="col-md-4">
                                                <label class="form-label">Page Format</label>
                                                <?php $pf = strtoupper((string) ($tpl['page_format'] ?? 'A4')); ?>
                                                <select class="form-select" id="page_format" name="page_format">
                                                    <option value="A4" <?php echo $pf === 'A4' ? 'selected' : ''; ?>>A4</option>
                                                    <option value="LETTER" <?php echo $pf === 'LETTER' ? 'selected' : ''; ?>>Letter</option>
                                                    <option value="LEGAL" <?php echo $pf === 'LEGAL' ? 'selected' : ''; ?>>Legal</option>
                                                    <option value="CUSTOM" <?php echo $pf === 'CUSTOM' ? 'selected' : ''; ?>>Custom</option>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Orientation</label>
                                                <?php $po = strtolower((string) ($tpl['orientation'] ?? 'portrait')); ?>
                                                <select class="form-select" id="orientation" name="orientation">
                                                    <option value="portrait" <?php echo $po === 'portrait' ? 'selected' : ''; ?>>Portrait</option>
                                                    <option value="landscape" <?php echo $po === 'landscape' ? 'selected' : ''; ?>>Landscape</option>
                                                </select>
                                            </div>
                                            <div class="col-md-2" id="custom_w_wrap">
                                                <label class="form-label">Width (mm)</label>
                                                <input class="form-control" id="page_width_mm" type="number" step="0.1" min="50" name="page_width_mm" value="<?php echo rt_h((string) ($tpl['page_width_mm'] ?? '')); ?>">
                                            </div>
                                            <div class="col-md-2" id="custom_h_wrap">
                                                <label class="form-label">Height (mm)</label>
                                                <input class="form-control" id="page_height_mm" type="number" step="0.1" min="50" name="page_height_mm" value="<?php echo rt_h((string) ($tpl['page_height_mm'] ?? '')); ?>">
                                            </div>

                                            <?php
                                                [$resolvedW, $resolvedH] = report_templates_resolve_page_mm($tpl);
                                                $fmtMm = function ($v) {
                                                    $v = (float) $v;
                                                    if (abs($v - round($v)) < 0.01) return (string) ((int) round($v));
                                                    $s = number_format($v, 1, '.', '');
                                                    return rtrim(rtrim($s, '0'), '.');
                                                };
                                                $resolvedLabel = $fmtMm($resolvedW) . 'mm x ' . $fmtMm($resolvedH) . 'mm';
                                            ?>
                                            <div class="col-12">
                                                <div class="text-muted small mt-1">
                                                    Resolved size: <span id="resolved_size_value" class="fw-semibold"><?php echo rt_h($resolvedLabel); ?></span>
                                                </div>
                                            </div>

                                            <div class="col-md-3">
                                                <label class="form-label">Top Margin (mm)</label>
                                                <input class="form-control" type="number" step="0.1" min="0" name="margin_top_mm" value="<?php echo rt_h((string) ($tpl['margin_top_mm'] ?? 10)); ?>">
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">Right Margin (mm)</label>
                                                <input class="form-control" type="number" step="0.1" min="0" name="margin_right_mm" value="<?php echo rt_h((string) ($tpl['margin_right_mm'] ?? 10)); ?>">
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">Bottom Margin (mm)</label>
                                                <input class="form-control" type="number" step="0.1" min="0" name="margin_bottom_mm" value="<?php echo rt_h((string) ($tpl['margin_bottom_mm'] ?? 10)); ?>">
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">Left Margin (mm)</label>
                                                <input class="form-control" type="number" step="0.1" min="0" name="margin_left_mm" value="<?php echo rt_h((string) ($tpl['margin_left_mm'] ?? 10)); ?>">
                                            </div>

                                            <div class="col-md-6">
                                                <label class="form-label">Header Height (mm)</label>
                                                <input class="form-control" type="number" step="0.1" min="0" name="header_height_mm" value="<?php echo rt_h((string) ($tpl['header_height_mm'] ?? 20)); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Footer Height (mm)</label>
                                                <input class="form-control" type="number" step="0.1" min="0" name="footer_height_mm" value="<?php echo rt_h((string) ($tpl['footer_height_mm'] ?? 15)); ?>">
                                            </div>
                                        </div>

                                        <ul class="nav nav-tabs nav-bordered mt-4" role="tablist">
                                            <li class="nav-item" role="presentation">
                                                <a class="nav-link active" data-bs-toggle="tab" href="#tab_css" role="tab">CSS</a>
                                            </li>
                                            <li class="nav-item" role="presentation">
                                                <a class="nav-link" data-bs-toggle="tab" href="#tab_header" role="tab">Header HTML</a>
                                            </li>
                                            <li class="nav-item" role="presentation">
                                                <a class="nav-link" data-bs-toggle="tab" href="#tab_body" role="tab">Body HTML</a>
                                            </li>
                                            <li class="nav-item" role="presentation">
                                                <a class="nav-link" data-bs-toggle="tab" href="#tab_footer" role="tab">Footer HTML</a>
                                            </li>
                                            <li class="nav-item" role="presentation">
                                                <a class="nav-link" data-bs-toggle="tab" href="#tab_sample" role="tab">Sample JSON</a>
                                            </li>
                                        </ul>
                                        <div class="tab-content border border-top-0 p-2">
                                            <div class="tab-pane fade show active" id="tab_css" role="tabpanel">
                                                <textarea id="tpl_css" name="css" rows="10"><?php echo rt_h((string) ($tpl['css'] ?? '')); ?></textarea>
                                            </div>
                                            <div class="tab-pane fade" id="tab_header" role="tabpanel">
                                                <textarea id="tpl_header_html" name="header_html" rows="10"><?php echo rt_h((string) ($tpl['header_html'] ?? '')); ?></textarea>
                                            </div>
                                            <div class="tab-pane fade" id="tab_body" role="tabpanel">
                                                <textarea id="tpl_body_html" name="body_html" rows="10"><?php echo rt_h((string) ($tpl['body_html'] ?? '')); ?></textarea>
                                            </div>
                                            <div class="tab-pane fade" id="tab_footer" role="tabpanel">
                                                <textarea id="tpl_footer_html" name="footer_html" rows="10"><?php echo rt_h((string) ($tpl['footer_html'] ?? '')); ?></textarea>
                                            </div>
                                            <div class="tab-pane fade" id="tab_sample" role="tabpanel">
                                                <textarea id="tpl_sample_json" name="sample_data_json" rows="10"><?php echo rt_h((string) ($tpl['sample_data_json'] ?? '')); ?></textarea>
                                                <div class="text-muted small mt-2">
                                                    Tip: put HTML inside a string like <code>"content"</code> and render with <code>{{{content}}}</code>.
                                                </div>
                                            </div>
                                        </div>

                                        <div class="d-flex justify-content-end mt-3">
                                            <button type="submit" class="btn btn-primary">
                                                <i class="ri-save-3-line me-1" aria-hidden="true"></i>
                                                Save
                                            </button>
                                        </div>
                                    </form>

                                    <div class="mt-4">
                                        <div class="d-flex align-items-center justify-content-between mb-2">
                                            <div class="fw-semibold">Preview</div>
                                            <?php if ($previewHref !== ''): ?>
                                                <a class="btn btn-sm btn-outline-secondary" href="<?php echo rt_h($previewHref); ?>" target="_blank" rel="noopener">
                                                    Open in new tab
                                                </a>
                                            <?php endif; ?>
                                        </div>

                                        <?php if ($previewHref === ''): ?>
                                            <div class="alert alert-info mb-0" role="alert">
                                                Save the template to enable preview and downloads.
                                            </div>
                                        <?php else: ?>
                                            <iframe class="rt-preview" src="<?php echo rt_h($previewHref); ?>"></iframe>
                                            <div class="text-muted small mt-2">
                                                Note: downloads use server tools (<code>python</code>/<code>python3</code> + WeasyPrint for PDF, and <code>pdftoppm</code> for DOCX snapshots).
                                            </div>
                                        <?php endif; ?>
                                    </div>

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
    <script src="assets/vendor/codemirror/codemirror.min.js"></script>
    <script src="assets/vendor/codemirror/mode/xml/xml.min.js"></script>
    <script src="assets/vendor/codemirror/mode/javascript/javascript.min.js"></script>
    <script src="assets/vendor/codemirror/mode/css/css.min.js"></script>
    <script src="assets/vendor/codemirror/mode/htmlmixed/htmlmixed.min.js"></script>
    <script src="assets/vendor/codemirror/addon/edit/matchbrackets.min.js"></script>
    <script src="assets/vendor/codemirror/addon/edit/closebrackets.min.js"></script>
    <script src="assets/vendor/codemirror/addon/selection/active-line.min.js"></script>
    <script>
        (function () {
            var formatSel = document.getElementById('page_format');
            var orientSel = document.getElementById('orientation');
            var wWrap = document.getElementById('custom_w_wrap');
            var hWrap = document.getElementById('custom_h_wrap');
            var wInput = document.getElementById('page_width_mm');
            var hInput = document.getElementById('page_height_mm');
            var resolvedSizeValue = document.getElementById('resolved_size_value');

            function fmtMm(v) {
                var n = Number(v);
                if (!isFinite(n) || n <= 0) return '--';
                var rounded = Math.round(n * 10) / 10;
                if (Math.abs(rounded - Math.round(rounded)) < 0.01) return String(Math.round(rounded));
                var s = rounded.toFixed(1);
                return s.replace(/\.0$/, '');
            }

            function resolvePageMm() {
                var fmt = formatSel ? String(formatSel.value || '').toUpperCase() : 'A4';
                var orient = orientSel ? String(orientSel.value || '').toLowerCase() : 'portrait';

                var w = 210.0;
                var h = 297.0;
                var note = '';

                if (fmt === 'LETTER') {
                    w = 215.9;
                    h = 279.4;
                } else if (fmt === 'LEGAL') {
                    w = 215.9;
                    h = 355.6;
                } else if (fmt === 'CUSTOM') {
                    var cw = wInput ? parseFloat(String(wInput.value || '')) : 0;
                    var ch = hInput ? parseFloat(String(hInput.value || '')) : 0;
                    if (isFinite(cw) && isFinite(ch) && cw > 0 && ch > 0) {
                        w = cw;
                        h = ch;
                    } else {
                        // Mirror backend fallback behavior (falls back to A4).
                        note = ' (fallback A4)';
                    }
                }

                if (orient === 'landscape') {
                    var tmp = w;
                    w = h;
                    h = tmp;
                }

                if (w < 50) w = 50;
                if (h < 50) h = 50;

                return { w: w, h: h, note: note };
            }

            function updateResolvedSize() {
                if (!resolvedSizeValue) return;
                var r = resolvePageMm();
                resolvedSizeValue.textContent = fmtMm(r.w) + 'mm x ' + fmtMm(r.h) + 'mm' + (r.note || '');
            }

            function toggleCustom() {
                var isCustom = formatSel && String(formatSel.value || '').toUpperCase() === 'CUSTOM';
                if (wWrap) wWrap.style.display = isCustom ? '' : 'none';
                if (hWrap) hWrap.style.display = isCustom ? '' : 'none';
            }
            if (formatSel) {
                formatSel.addEventListener('change', function () {
                    toggleCustom();
                    updateResolvedSize();
                });
                toggleCustom();
            }
            if (orientSel) orientSel.addEventListener('change', updateResolvedSize);
            if (wInput) wInput.addEventListener('input', updateResolvedSize);
            if (hInput) hInput.addEventListener('input', updateResolvedSize);
            updateResolvedSize();

            if (typeof CodeMirror === 'undefined') return;
            var editors = [];

            function makeEditor(textareaId, mode) {
                var ta = document.getElementById(textareaId);
                if (!ta) return null;
                var cm = CodeMirror.fromTextArea(ta, {
                    lineNumbers: true,
                    lineWrapping: true,
                    mode: mode,
                    matchBrackets: true,
                    autoCloseBrackets: true,
                    styleActiveLine: true,
                });
                cm.setSize(null, 280);
                editors.push(cm);
                return cm;
            }

            makeEditor('tpl_css', 'css');
            makeEditor('tpl_header_html', 'htmlmixed');
            makeEditor('tpl_body_html', 'htmlmixed');
            makeEditor('tpl_footer_html', 'htmlmixed');
            makeEditor('tpl_sample_json', { name: 'javascript', json: true });

            var form = document.getElementById('tplForm');
            if (form) {
                form.addEventListener('submit', function () {
                    editors.forEach(function (cm) {
                        try { cm.save(); } catch (e) {}
                    });
                });
            }

            document.querySelectorAll('a[data-bs-toggle="tab"]').forEach(function (el) {
                el.addEventListener('shown.bs.tab', function () {
                    window.setTimeout(function () {
                        editors.forEach(function (cm) {
                            try { cm.refresh(); } catch (e) {}
                        });
                    }, 0);
                });
            });
        })();
    </script>
</body>
</html>
