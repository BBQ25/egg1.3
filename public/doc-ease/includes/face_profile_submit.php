<?php
include __DIR__ . '/../layouts/session.php';
require_approved_student();

require_once __DIR__ . '/attendance_checkin.php';
require_once __DIR__ . '/face_profiles.php';
attendance_checkin_ensure_tables($conn);
face_profiles_ensure_tables($conn);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (!function_exists('face_reg_json')) {
    function face_reg_json($status, $message, array $extra = []) {
        $out = array_merge([
            'status' => (string) $status,
            'message' => (string) $message,
        ], $extra);
        echo json_encode($out);
        exit;
    }
}

$csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
if (!csrf_validate($csrf)) {
    face_reg_json('error', 'Security check failed (CSRF). Please refresh and try again.');
}

$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
if ($userId <= 0) face_reg_json('error', 'Unauthorized.');

// Resolve student_id from this user account.
$studentId = 0;
$st = $conn->prepare("SELECT id FROM students WHERE user_id = ? LIMIT 1");
if ($st) {
    $st->bind_param('i', $userId);
    $st->execute();
    $res = $st->get_result();
    if ($res && $res->num_rows === 1) {
        $row = $res->fetch_assoc();
        $studentId = (int) ($row['id'] ?? 0);
    }
    $st->close();
}
if ($studentId <= 0) face_reg_json('error', 'Student profile is not linked to this account.');

$descriptorRaw = isset($_POST['face_descriptor']) ? (string) $_POST['face_descriptor'] : '';
$descriptor = face_profiles_parse_descriptor($descriptorRaw);
if (!is_array($descriptor)) {
    face_reg_json('error', 'Face data is invalid. Please try again.');
}

$file = isset($_FILES['face_image']) ? $_FILES['face_image'] : null;
[$okUp, $upErr, $meta] = face_profiles_save_upload($file, $studentId);
if (!$okUp) face_reg_json('error', (string) $upErr);
$meta = is_array($meta) ? $meta : [];
$meta['model'] = 'face-api.js';

[$ok, $msg] = face_profiles_upsert($conn, $studentId, $descriptor, $meta);
if ($ok) {
    face_reg_json('ok', (string) $msg, [
        'image_path' => (string) ($meta['path'] ?? ''),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
}
face_reg_json('error', (string) $msg);

