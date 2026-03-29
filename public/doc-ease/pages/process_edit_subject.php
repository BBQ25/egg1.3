<?php
include '../layouts/session.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
}

$originalSubjectCode = isset($_POST['originalSubjectCode']) ? trim($_POST['originalSubjectCode']) : '';
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

if ($originalSubjectCode === '' || $subjectCode === '' || $subjectName === '' || $subjectType === '' || $status === '') {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Required fields are missing.']);
    exit;
}

// Escape inputs
$originalSubjectCodeEscaped = $conn->real_escape_string($originalSubjectCode);
$subjectCodeEscaped = $conn->real_escape_string($subjectCode);
$subjectNameEscaped = $conn->real_escape_string($subjectName);
$subjectTypeEscaped = $conn->real_escape_string($subjectType);
$descriptionEscaped = $conn->real_escape_string($description);
$courseEscaped = $conn->real_escape_string($course);
$majorEscaped = $conn->real_escape_string($major);
$academicYearEscaped = $conn->real_escape_string($academicYear);
$semesterEscaped = $conn->real_escape_string($semester);
$statusEscaped = $conn->real_escape_string($status);

// Update subjects table
$updateSql = "UPDATE subjects SET subject_code='{$subjectCodeEscaped}', subject_name='{$subjectNameEscaped}', description='{$descriptionEscaped}', course='{$courseEscaped}', major='{$majorEscaped}', academic_year='{$academicYearEscaped}', semester='{$semesterEscaped}', units={$units}, type='{$subjectTypeEscaped}', status='{$statusEscaped}' WHERE subject_code='{$originalSubjectCodeEscaped}'";

if ($conn->query($updateSql) !== true) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database update failed: ' . $conn->error]);
    exit;
}

$conn->close();

http_response_code(200);
echo json_encode(['status' => 'success', 'message' => 'Subject updated successfully.']);
exit;
?>

