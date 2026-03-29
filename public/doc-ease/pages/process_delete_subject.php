<?php
include '../layouts/session.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
}

$subjectCode = isset($_POST['subjectCode']) ? trim($_POST['subjectCode']) : '';

if ($subjectCode === '') {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Subject code is required.']);
    exit;
}

// Escape input
$subjectCodeEscaped = $conn->real_escape_string($subjectCode);

// Delete from subjects table
$deleteSql = "DELETE FROM subjects WHERE subject_code = '{$subjectCodeEscaped}'";

if ($conn->query($deleteSql) !== true) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database delete failed: ' . $conn->error]);
    exit;
}

$conn->close();

http_response_code(200);
echo json_encode(['status' => 'success', 'message' => 'Subject deleted successfully.']);
exit;
?>

