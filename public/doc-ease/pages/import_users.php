<?php
declare(strict_types=1);

require_once __DIR__ . '/../layouts/session.php';

if (trim((string) getenv('DOC_EASE_ENABLE_IMPORT_USERS')) !== '1') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found.';
    exit;
}

require_role('superadmin');

header('Content-Type: application/json; charset=utf-8');

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
}

$csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
if (!csrf_validate($csrf)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Security check failed (CSRF).']);
    exit;
}

$sqlFile = __DIR__ . '/../attex_design/attex-php.sql';
if (!is_file($sqlFile)) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'SQL source file is missing.']);
    exit;
}

$sql = (string) @file_get_contents($sqlFile);
if (trim($sql) === '') {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'SQL source file is empty.']);
    exit;
}

if (!$conn->multi_query($sql)) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Import failed: ' . $conn->error]);
    exit;
}

do {
    $result = $conn->store_result();
    if ($result instanceof mysqli_result) {
        $result->free();
    }
} while ($conn->more_results() && $conn->next_result());

echo json_encode(['status' => 'success', 'message' => 'Import completed.']);
