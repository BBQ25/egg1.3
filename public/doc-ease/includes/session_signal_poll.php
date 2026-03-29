<?php
// Poll endpoint for cross-device session control signals.
// Keep this request from extending idle timeout.
define('DOC_EASE_SKIP_IDLE_TOUCH', true);
include __DIR__ . '/../layouts/session.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Content-Type: application/json; charset=utf-8');

$state = [
    'refresh_version' => 1,
    'logout_version' => 1,
];

if (isset($conn) && $conn instanceof mysqli && function_exists('session_global_control_eval')) {
    $state = session_global_control_eval($conn);
}

echo json_encode([
    'status' => 'ok',
    'action' => 'none',
    'refresh_version' => (int) ($state['refresh_version'] ?? 1),
    'logout_version' => (int) ($state['logout_version'] ?? 1),
]);

