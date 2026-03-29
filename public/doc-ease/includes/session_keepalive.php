<?php
// Lightweight endpoint to keep the PHP session alive while the user is active.
// Returns JSON and relies on layouts/session.php for auth + idle-timeout enforcement.

include __DIR__ . '/../layouts/session.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Content-Type: application/json; charset=utf-8');

$minutes = (isset($conn) && $conn instanceof mysqli && function_exists('session_idle_timeout_get_minutes'))
    ? (int) session_idle_timeout_get_minutes($conn)
    : 0;

echo json_encode([
    'status' => 'ok',
    'now' => time(),
    'idle_timeout_minutes' => $minutes,
]);
