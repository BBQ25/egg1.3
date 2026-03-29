<?php
include __DIR__ . '/../layouts/session.php';
require_any_role(['admin', 'teacher', 'student']);

header('Content-Type: application/json; charset=UTF-8');

function notifications_action_json($status, $message, array $extra = []) {
    echo json_encode(array_merge(['status' => $status, 'message' => $message], $extra), JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    notifications_action_json('error', 'Database connection unavailable.');
}

require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/audit.php';

$raw = file_get_contents('php://input');
$req = null;
if (is_string($raw) && trim($raw) !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) $req = $decoded;
}
if (!is_array($req)) $req = $_POST;

$csrf = (string) ($req['csrf_token'] ?? '');
if (!csrf_validate($csrf)) {
    notifications_action_json('error', 'Security check failed (CSRF). Please refresh and try again.');
}

$action = strtolower(trim((string) ($req['action'] ?? '')));
if ($action !== 'clear_all') {
    notifications_action_json('error', 'Invalid request.');
}

$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$role = isset($_SESSION['user_role']) ? (string) $_SESSION['user_role'] : '';
if ($userId <= 0) {
    notifications_action_json('error', 'Unauthorized.');
}

$maxSeenId = notification_mark_all_read($conn, $userId, $role);
audit_log(
    $conn,
    'notification.cleared',
    'audit_log',
    $maxSeenId > 0 ? $maxSeenId : null,
    'Marked all notifications as read.',
    ['max_seen_audit_id' => $maxSeenId]
);

notifications_action_json('ok', 'All notifications marked as read.', [
    'max_seen_audit_id' => (int) $maxSeenId,
    'unread_count' => 0,
]);
