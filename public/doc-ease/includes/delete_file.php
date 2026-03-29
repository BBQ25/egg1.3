<?php
include '../layouts/session.php';
require_role('admin');

if (!isset($_POST['id'])) {
    echo "Invalid request.";
    exit;
}

$id = intval($_POST['id']);

// Get file info from DB
$sql = "SELECT file_name FROM uploaded_files WHERE id = $id";
$result = $conn->query($sql);

if ($result->num_rows == 0) {
    echo "File not found.";
    exit;
}

$row = $result->fetch_assoc();
$file_path = "../uploads/" . $row['file_name'];

// Delete file from filesystem
if (file_exists($file_path)) {
    unlink($file_path);
}

// Delete record from DB
$sql = "DELETE FROM uploaded_files WHERE id = $id";
if ($conn->query($sql) === TRUE) {
    echo "File deleted successfully.";
} else {
    echo "Error deleting file: " . $conn->error;
}

$conn->close();
?>
