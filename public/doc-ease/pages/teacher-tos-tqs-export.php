<?php include '../layouts/session.php'; ?>
<?php require_active_role('teacher'); ?>

<?php
require_once __DIR__ . '/../includes/tos_tqs.php';
require_once __DIR__ . '/../includes/report_export.php';

ttq_ensure_tables($conn);
set_time_limit(0);

$teacherId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$documentId = isset($_GET['document_id']) ? (int) $_GET['document_id'] : 0;
$format = strtolower(trim((string) ($_GET['format'] ?? 'html')));
$viewer = strtolower(trim((string) ($_GET['viewer'] ?? 'teacher')));
$csrf = isset($_GET['csrf_token']) ? (string) $_GET['csrf_token'] : '';

if (!csrf_validate($csrf)) {
    http_response_code(403);
    echo 'Security check failed (CSRF).';
    exit;
}

if (!in_array($format, ['html', 'pdf', 'docx'], true)) $format = 'html';
if (!in_array($viewer, ['teacher', 'student'], true)) $viewer = 'teacher';

if ($teacherId <= 0 || $documentId <= 0) {
    http_response_code(400);
    echo 'Invalid export request.';
    exit;
}

$doc = ttq_fetch_document($conn, $documentId, $teacherId);
if (!$doc) {
    http_response_code(404);
    echo 'Document not found.';
    exit;
}

$html = ttq_render_document_html($doc, [
    'viewer' => $viewer,
]);
$filename = ttq_document_export_filename($doc, $format, $viewer);

if ($format === 'html') {
    report_send_binary_download('text/html; charset=utf-8', $filename, $html);
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

if ($format === 'pdf') {
    report_send_binary_download('application/pdf', $filename, $pdfBinary);
    exit;
}

$tmpPngDir = '';
$pngPaths = report_pdf_binary_to_png_paths($pdfBinary, $tmpPngDir, $err);
if (count($pngPaths) === 0) {
    report_tmp_dir_delete($tmpPngDir);
    http_response_code(500);
    echo $err !== '' ? $err : 'Unable to rasterize PDF pages.';
    exit;
}

$userRow = current_user_row($conn);
$creator = $userRow ? current_user_display_name($userRow) : ((string) ($_SESSION['user_name'] ?? 'Teacher'));
$docxTitle = trim((string) ($doc['title'] ?? 'TOS-TQS'));
if ($docxTitle === '') $docxTitle = 'TOS-TQS';
if ($viewer === 'student') $docxTitle .= ' (Student Copy)';

$docxBinary = report_make_docx_binary_from_png_pages($pngPaths, [
    'width_mm' => 210,
    'height_mm' => 297,
    'orientation' => 'portrait',
], [
    'title' => $docxTitle,
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
    $filename,
    $docxBinary
);
exit;

