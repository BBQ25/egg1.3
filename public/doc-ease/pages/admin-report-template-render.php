<?php include '../layouts/session.php'; ?>
<?php require_role('superadmin'); ?>

<?php
require_once __DIR__ . '/../includes/report_templates.php';
require_once __DIR__ . '/../includes/report_export.php';

report_templates_ensure_tables($conn);
set_time_limit(0);

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo 'Missing template id.';
    exit;
}

$tpl = report_templates_get($conn, $id);
if (!$tpl) {
    http_response_code(404);
    echo 'Template not found.';
    exit;
}

$download = strtolower(trim((string) ($_GET['download'] ?? '')));
$isDownload = ($download === 'pdf' || $download === 'docx');

if ($isDownload) {
    $csrf = (string) ($_GET['csrf_token'] ?? '');
    if (!csrf_validate($csrf)) {
        http_response_code(403);
        echo 'Security check failed (CSRF).';
        exit;
    }
}

$ctx = report_template_build_context($conn, $tpl);
$html = report_template_render_full_html($tpl, $ctx);

if (!$isDownload) {
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}

$rootPath = realpath(__DIR__ . '/..');
if (!is_string($rootPath) || $rootPath === '') {
    http_response_code(500);
    echo 'Project root not found.';
    exit;
}

$err = '';
$pdfBinary = report_generate_pdf_binary_from_html($html, $rootPath, $err);
if ($pdfBinary === '') {
    http_response_code(500);
    echo $err !== '' ? $err : 'Unable to generate PDF.';
    exit;
}

$key = trim((string) ($tpl['template_key'] ?? 'template'));
if ($key === '') $key = 'template';
$name = trim((string) ($tpl['name'] ?? 'Report Template'));
if ($name === '') $name = 'Report Template';
$safeBase = preg_replace('/[^A-Za-z0-9._-]/', '_', $key);
if (!is_string($safeBase) || $safeBase === '') $safeBase = 'template';

if ($download === 'pdf') {
    report_send_binary_download('application/pdf', $safeBase . '.pdf', $pdfBinary);
    exit;
}

[$wMm, $hMm] = report_templates_resolve_page_mm($tpl);
$orientation = strtolower(trim((string) ($tpl['orientation'] ?? 'portrait')));
if (!in_array($orientation, ['portrait', 'landscape'], true)) $orientation = 'portrait';

$tmpPngDir = '';
$pngPaths = report_pdf_binary_to_png_paths($pdfBinary, $tmpPngDir, $err);
if (count($pngPaths) === 0) {
    report_tmp_dir_delete($tmpPngDir);
    http_response_code(500);
    echo $err !== '' ? $err : 'Unable to rasterize PDF pages.';
    exit;
}

$u = current_user_row($conn);
$creator = $u ? current_user_display_name($u) : (isset($_SESSION['user_name']) ? (string) $_SESSION['user_name'] : 'Superadmin');

$docxBinary = report_make_docx_binary_from_png_pages($pngPaths, [
    'width_mm' => $wMm,
    'height_mm' => $hMm,
    'orientation' => $orientation,
], [
    'title' => $name,
    'creator' => $creator,
]);

report_tmp_dir_delete($tmpPngDir);

if ($docxBinary === '') {
    http_response_code(500);
    echo 'Unable to build DOCX.';
    exit;
}

report_send_binary_download(
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    $safeBase . '.docx',
    $docxBinary
);
exit;
