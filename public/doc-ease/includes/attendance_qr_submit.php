<?php
include __DIR__ . '/../layouts/session.php';
require_approved_student();

require_once __DIR__ . '/attendance_checkin.php';
attendance_checkin_ensure_tables($conn);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (!function_exists('att_qr_json')) {
    function att_qr_json($status, $message, array $extra = []) {
        $out = array_merge([
            'status' => (string) $status,
            'message' => (string) $message,
        ], $extra);
        echo json_encode($out);
        exit;
    }
}

// Read input (supports JSON and form-encoded).
$raw = (string) file_get_contents('php://input');
$decoded = null;
if ($raw !== '') {
    $tmp = json_decode($raw, true);
    if (is_array($tmp)) $decoded = $tmp;
}
$req = is_array($decoded) ? $decoded : $_POST;

$csrf = isset($req['csrf_token']) ? (string) $req['csrf_token'] : '';
if (!csrf_validate($csrf)) {
    att_qr_json('error', 'Security check failed (CSRF). Please refresh and try again.');
}

$sessionId = isset($req['session_id']) ? (int) $req['session_id'] : 0;
$attendanceCode = isset($req['attendance_code']) ? trim((string) $req['attendance_code']) : '';

if ($sessionId <= 0 || $attendanceCode === '') {
    att_qr_json('error', 'Invalid QR payload.');
}

$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
if ($userId <= 0) {
    att_qr_json('error', 'Unauthorized.');
}

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
if ($studentId <= 0) {
    att_qr_json('error', 'Student profile is not linked to this account.');
}

$session = attendance_checkin_get_session_for_student($conn, $sessionId, $studentId);
if (!is_array($session)) {
    att_qr_json('error', 'Attendance session not found.');
}
$classRecordId = (int) ($session['class_record_id'] ?? 0);
$geoLocation = attendance_geo_location_from_request_array(is_array($req) ? $req : []);

[$ok, $result] = attendance_checkin_submit_code($conn, $studentId, $userId, $sessionId, $attendanceCode, 'qr', $geoLocation);

if ($ok) {
    $data = is_array($result) ? $result : [];
    $status = strtolower(trim((string) ($data['status'] ?? 'present')));
    $_SESSION['flash_message'] = 'Attendance submitted successfully as ' . $status . '.';
    $_SESSION['flash_type'] = ($status === 'late') ? 'warning' : 'success';

    $redirect = 'student-attendance.php';
    if ($classRecordId > 0) $redirect .= '?class_record_id=' . $classRecordId;
    att_qr_json('ok', (string) $_SESSION['flash_message'], [
        'redirect' => $redirect,
        'attendance_status' => $status,
    ]);
}

$_SESSION['flash_message'] = (string) $result;
$_SESSION['flash_type'] = 'warning';
att_qr_json('error', (string) $result);
