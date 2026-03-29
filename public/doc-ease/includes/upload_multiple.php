<?php
include '../layouts/session.php';
require_approved_user();

function getWeekOfMonth($date) {
    $firstOfMonth = strtotime(date("Y-m-01", $date));
    return intval(date("W", $date)) - intval(date("W", $firstOfMonth)) + 1;
}

$allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
$maxFileSize = 5 * 1024 * 1024; // 5 MB

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
}

if (!isset($_FILES['files'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'No files received.']);
    exit;
}

$subject = isset($_POST['subject']) ? trim($_POST['subject']) : '';
$studentNo = isset($_POST['studentNo']) ? trim($_POST['studentNo']) : '';
$notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';

if ($subject === '') {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Subject is required.']);
    exit;
}

if ($studentNo === '') {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Student number is required.']);
    exit;
}

$targetDirectory = '../uploads/' . $subject . '/';

// Query student details
$studentNoEscaped = $conn->real_escape_string($studentNo);
$student_query = "SELECT surname, firstname, middlename FROM students WHERE studentno = '$studentNoEscaped'";
$student_result = $conn->query($student_query);
if (!$student_result) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database query failed: ' . $conn->error]);
    exit;
}
if ($student_result->num_rows == 0) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Student not found.']);
    exit;
}
$student_row = $student_result->fetch_assoc();
$surname = $student_row['surname'];
$firstname = $student_row['firstname'];
$middlename = $student_row['middlename'];

$uploadedFiles = [];
$errors = [];

foreach ($_FILES['files']['name'] as $index => $originalName) {
    if ($_FILES['files']['error'][$index] !== UPLOAD_ERR_OK) {
        $errors[] = "File $originalName failed to upload (error code: " . $_FILES['files']['error'][$index] . ').';
        continue;
    }

    $tmpPath = $_FILES['files']['tmp_name'][$index];
    $fileSize = $_FILES['files']['size'][$index];
    $fileType = $_FILES['files']['type'][$index];
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if (!in_array($extension, $allowedExtensions, true)) {
        $errors[] = "File $originalName has an unsupported format.";
        continue;
    }

    if ($fileSize > $maxFileSize) {
        $errors[] = "File $originalName exceeds the maximum allowed size.";
        continue;
    }

    $monthName = date('F');
    $weekNumber = getWeekOfMonth(time());
    $dayOfMonth = date('d');

    $weekFolderName = '';
    switch ($weekNumber) {
        case 1:
            $weekFolderName = '1st Week';
            break;
        case 2:
            $weekFolderName = '2nd Week';
            break;
        case 3:
            $weekFolderName = '3rd Week';
            break;
        case 4:
            $weekFolderName = '4th Week';
            break;
        default:
            $weekFolderName = $weekNumber.'th Week';
            break;
    }

    $newTargetDirectory = $targetDirectory . $monthName . '/' . $weekFolderName . '/' . $dayOfMonth . '/';

    if (!is_dir($newTargetDirectory) && !mkdir($newTargetDirectory, 0777, true)) {
        $errors[] = "Failed to create upload directory for $originalName.";
        continue;
    }

    $generatedName = uniqid('', true) . '-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $originalName);
    $destination = $newTargetDirectory . $generatedName;

    if (!move_uploaded_file($tmpPath, $destination)) {
        $errors[] = "File $originalName could not be saved.";
        continue;
    }

    // Construct new file name
    $name_part = $firstname . ($middlename ? ' ' . $middlename : '');
    $suffix = count($_FILES['files']['name']) > 1 ? ' - ' . ($index + 1) : '';
    $new_file_name = $studentNo . ' - ' . $surname . ', ' . $name_part . $suffix . '.' . $extension;
    $new_destination = $newTargetDirectory . $new_file_name;

    // Rename the file
    if (!rename($destination, $new_destination)) {
        $errors[] = "File $originalName could not be renamed.";
        unlink($destination);
        continue;
    }

    // Update variables
    $generatedName = $new_file_name;
    $destination = $new_destination;

    $studentNoEscaped = $conn->real_escape_string($studentNo);
    $originalNameEscaped = $conn->real_escape_string($originalName);
    $generatedNameEscaped = $conn->real_escape_string($generatedName);
    $destinationEscaped = $conn->real_escape_string($destination);
    $notesEscaped = $conn->real_escape_string($notes);
    $latitude = isset($_POST['location_latitude']) ? trim($_POST['location_latitude']) : null;
    $longitude = isset($_POST['location_longitude']) ? trim($_POST['location_longitude']) : null;
    $latitudeEscaped = $latitude !== null && $latitude !== '' ? "'" . $conn->real_escape_string($latitude) . "'" : 'NULL';
    $longitudeEscaped = $longitude !== null && $longitude !== '' ? "'" . $conn->real_escape_string($longitude) . "'" : 'NULL';

    $insertSql = "INSERT INTO uploaded_files (StudentNo, original_name, file_name, file_path, file_size, file_type, notes, location_latitude, location_longitude, upload_date, created_at, updated_at) VALUES ('{$studentNoEscaped}', '{$originalNameEscaped}', '{$generatedNameEscaped}', '{$destinationEscaped}', {$fileSize}, '{$fileType}', '{$notesEscaped}', {$latitudeEscaped}, {$longitudeEscaped}, NOW(), NOW(), NOW())";

    if ($conn->query($insertSql) !== true) {
        // Roll back physical file if database insert fails.
        if (file_exists($destination)) {
            unlink($destination);
        }
        $errors[] = "File $originalName saved but database insert failed: " . $conn->error;
        continue;
    }

    $uploadedFiles[] = [
        'original_name' => $originalName,
        'file_name' => $generatedName,
        'file_size' => $fileSize,
    ];
}

$conn->close();

if (empty($uploadedFiles)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'No files were uploaded successfully.',
        'errors' => $errors,
    ]);
    exit;
}

echo json_encode([
    'status' => 'success',
    'message' => 'Files uploaded successfully.',
    'uploaded' => $uploadedFiles,
    'errors' => $errors,
]);
exit;
