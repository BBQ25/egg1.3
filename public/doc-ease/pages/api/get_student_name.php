<?php
// AJAX endpoint: return student name for given student number
// Uses session helpers for auth and returns JSON errors for API requests.
header('Content-Type: application/json');

include __DIR__ . '/../../layouts/session.php';
require_approved_user();

$studentNo = isset($_GET['studentNo']) ? trim($_GET['studentNo']) : '';

if ($studentNo === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Student number is required.']);
    exit;
}

$query = "SELECT surname, firstname, middlename FROM students WHERE studentno = ? LIMIT 1";
if ($stmt = $conn->prepare($query)) {
    $stmt->bind_param('s', $studentNo);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $nameParts = [];
        if (!empty($row['firstname'])) $nameParts[] = $row['firstname'];
        if (!empty($row['middlename'])) $nameParts[] = $row['middlename'];
        $given = implode(' ', $nameParts);
        // Return in the format: Surname, Given Names
        $fullName = trim($row['surname'] . ($given ? ', ' . $given : ''));
        echo json_encode(['student_name' => $fullName]);
    } else {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Student not found.']);
    }
    $stmt->close();
} else {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
}

$conn->close();
exit;
?>


