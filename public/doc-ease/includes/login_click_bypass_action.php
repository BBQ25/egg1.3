<?php
if (!defined('ALLOW_GUEST_SESSION')) {
    define('ALLOW_GUEST_SESSION', true);
}
include __DIR__ . '/../layouts/session.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/login_click_bypass.php';

ensure_audit_logs_table($conn);
login_click_bypass_ensure_tables($conn);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'ok' => false,
        'message' => 'Method not allowed.',
    ]);
    exit;
}

$csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
if (!csrf_validate($csrf)) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'message' => 'Security check failed.',
    ]);
    exit;
}

$clickCount = isset($_POST['click_count']) ? (int) $_POST['click_count'] : 0;
$durationMs = isset($_POST['duration_ms']) ? (int) $_POST['duration_ms'] : 0;

$error = '';
$rule = null;
$redirect = '';
$ok = login_click_bypass_attempt($conn, $clickCount, $durationMs, $error, $rule, $redirect);
if (!$ok) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'message' => $error !== '' ? $error : 'Bypass login failed.',
    ]);
    exit;
}

echo json_encode([
    'ok' => true,
    'redirect' => $redirect,
    'rule_id' => (int) ($rule['rule_id'] ?? 0),
]);
exit;
