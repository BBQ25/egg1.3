<?php
include __DIR__ . '/../../layouts/session.php';
require_approved_user();

$first_day_this_month = date('Y-m-01 00:00:00');
$last_day_this_month  = date('Y-m-t 23:59:59');

// Limit to the current student's uploads (avoid leaking other students' records).
$studentNo = isset($_SESSION['student_no']) ? trim((string) $_SESSION['student_no']) : '';

// If missing in session, resolve from the linked student row.
if ($studentNo === '' && isset($_SESSION['user_id'])) {
    $userId = (int) $_SESSION['user_id'];
    $find = $conn->prepare("SELECT StudentNo FROM students WHERE user_id = ? LIMIT 1");
    if ($find) {
        $find->bind_param('i', $userId);
        $find->execute();
        $res = $find->get_result();
        if ($res && $res->num_rows === 1) {
            $studentNo = (string) ($res->fetch_assoc()['StudentNo'] ?? '');
            if ($studentNo !== '') $_SESSION['student_no'] = $studentNo;
        }
        $find->close();
    }
}

if ($studentNo === '') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([]);
    exit;
}

$sql =
    "SELECT id, StudentNo, original_name, file_name, file_path, file_size, file_type, notes, created_at
     FROM uploaded_files
     WHERE StudentNo = ? AND created_at >= ? AND created_at <= ?
     ORDER BY created_at DESC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'Unable to prepare query.']);
    exit;
}

$stmt->bind_param('sss', $studentNo, $first_day_this_month, $last_day_this_month);
$stmt->execute();

$result = $stmt->get_result();
$files = [];
while ($result && ($row = $result->fetch_assoc())) {
    $files[] = $row;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($files);

$stmt->close();
$conn->close();
?>
