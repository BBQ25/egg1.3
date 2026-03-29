<?php
include '../layouts/session.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
}

$subjectCode = isset($_POST['subjectCode']) ? trim($_POST['subjectCode']) : '';
$subjectName = isset($_POST['subjectName']) ? trim($_POST['subjectName']) : '';
$subjectType = isset($_POST['subjectType']) ? trim($_POST['subjectType']) : '';
$description = isset($_POST['description']) ? trim($_POST['description']) : '';
$course = isset($_POST['course']) ? trim($_POST['course']) : '';
$major = isset($_POST['major']) ? trim($_POST['major']) : '';
$academicYear = isset($_POST['academicYear']) ? trim($_POST['academicYear']) : '';
$semester = isset($_POST['semester']) ? trim($_POST['semester']) : '';
$units = isset($_POST['units']) ? floatval($_POST['units']) : 3.0;
$status = isset($_POST['status']) ? trim($_POST['status']) : 'active';

if ($subjectCode === '' || $subjectName === '' || $subjectType === '' || $status === '') {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Required fields are missing.']);
    exit;
}

// Escape inputs
$subjectCodeEscaped = $conn->real_escape_string($subjectCode);
$subjectNameEscaped = $conn->real_escape_string($subjectName);
$subjectTypeEscaped = $conn->real_escape_string($subjectType);
$descriptionEscaped = $conn->real_escape_string($description);
$courseEscaped = $conn->real_escape_string($course);
$majorEscaped = $conn->real_escape_string($major);
$academicYearEscaped = $conn->real_escape_string($academicYear);
$semesterEscaped = $conn->real_escape_string($semester);
$statusEscaped = $conn->real_escape_string($status);

// Ensure the user id exists in users table to satisfy FK constraint
$createdBy = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$createdByValid = null;

if ($createdBy > 0) {
    $checkUserStmt = $conn->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
    if ($checkUserStmt) {
        $checkUserStmt->bind_param("i", $createdBy);
        $checkUserStmt->execute();
        $checkUserStmt->store_result();
        if ($checkUserStmt->num_rows === 1) {
            $createdByValid = $createdBy;
        }
        $checkUserStmt->close();
    }
}

// Fallback to the first available user if the session user is missing from users table
if ($createdByValid === null) {
    $fallbackUser = $conn->query("SELECT id FROM users ORDER BY id LIMIT 1");
    if ($fallbackUser && $fallbackUser->num_rows > 0) {
        $createdByValid = (int) $fallbackUser->fetch_assoc()['id'];
    } else {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'No valid user account found to own the new subject.']);
        exit;
    }
}

// Insert into subjects table via prepared statement
$insertStmt = $conn->prepare("INSERT INTO subjects (subject_code, subject_name, description, course, major, academic_year, semester, units, type, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
if ($insertStmt) {
    $insertStmt->bind_param(
        "sssssssdssi",
        $subjectCodeEscaped,
        $subjectNameEscaped,
        $descriptionEscaped,
        $courseEscaped,
        $majorEscaped,
        $academicYearEscaped,
        $semesterEscaped,
        $units,
        $subjectTypeEscaped,
        $statusEscaped,
        $createdByValid
    );

    if (!$insertStmt->execute()) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Database insert failed: ' . $insertStmt->error]);
        $insertStmt->close();
        exit;
    }
    $insertStmt->close();
} else {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database insert failed: unable to prepare statement.']);
    exit;
}

// Create folder in uploads directory
$uploadDir = 'uploads/' . $subjectCode;
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        // Optional: log error or handle, but don't fail the insert
        error_log('Failed to create directory: ' . $uploadDir);
    }
}

$conn->close();

http_response_code(200);
echo json_encode(['status' => 'success', 'message' => 'Subject added successfully.']);
exit;
?>

