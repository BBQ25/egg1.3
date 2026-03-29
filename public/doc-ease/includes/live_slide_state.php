<?php
include __DIR__ . '/../layouts/session.php';
require_approved_student();

require_once __DIR__ . '/learning_materials.php';
ensure_learning_material_tables($conn);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (!function_exists('live_slide_state_json')) {
    function live_slide_state_json(array $payload) {
        echo json_encode($payload);
        exit;
    }
}

$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$studentId = learning_material_student_id_from_user($conn, $userId);
if ($studentId <= 0) {
    live_slide_state_json([
        'ok' => false,
        'message' => 'Student profile not found.',
    ]);
}

$code = learning_material_live_normalize_code($_GET['code'] ?? '');
if (!learning_material_live_code_is_valid($code)) {
    live_slide_state_json([
        'ok' => false,
        'message' => 'Invalid code.',
    ]);
}

$broadcast = learning_material_live_get_student_broadcast_by_code($conn, $studentId, $code);
if (!is_array($broadcast)) {
    live_slide_state_json([
        'ok' => true,
        'live' => false,
    ]);
}

live_slide_state_json([
    'ok' => true,
    'live' => true,
    'broadcast' => [
        'id' => (int) ($broadcast['id'] ?? 0),
        'class_record_id' => (int) ($broadcast['class_record_id'] ?? 0),
        'material_id' => (int) ($broadcast['material_id'] ?? 0),
        'current_slide' => (int) ($broadcast['current_slide'] ?? 1),
        'slide_count' => (int) ($broadcast['slide_count'] ?? 1),
        'slide_href' => (string) ($broadcast['slide_href'] ?? ''),
        'material_title' => (string) ($broadcast['material_title'] ?? ''),
        'subject_name' => (string) ($broadcast['subject_name'] ?? ''),
        'subject_code' => (string) ($broadcast['subject_code'] ?? ''),
        'section' => (string) ($broadcast['section'] ?? ''),
    ],
]);
