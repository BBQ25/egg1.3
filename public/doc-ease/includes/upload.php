 <?php
include '../layouts/session.php';
require_approved_user();

$target_dir = "../uploads/";
$original_name = basename($_FILES["file"]["name"]);
$file_name = uniqid() . '-' . $original_name;
$file_path = $target_dir . $file_name;
$imageFileType = strtolower(pathinfo($file_path,PATHINFO_EXTENSION));

// Check if file already exists
if (file_exists($file_path)) {
  echo "Sorry, file already exists.";
  die();
}

// Check file size
if ($_FILES["file"]["size"] > 5000000) {
  echo "Sorry, your file is too large.";
  die();
}

// Allow certain file formats
if($imageFileType != "jpg" && $imageFileType != "png" && $imageFileType != "jpeg"
&& $imageFileType != "gif" && $imageFileType != "pdf" ) {
  echo "Sorry, only JPG, JPEG, PNG, GIF, & PDF files are allowed.";
  die();
}

if (move_uploaded_file($_FILES["file"]["tmp_name"], $file_path)) {
    $studentNo = isset($_POST['studentNo']) ? $_POST['studentNo'] : null;
    $notes = isset($_POST['notes']) ? $_POST['notes'] : null;
    $latitude = isset($_POST['latitude']) ? $_POST['latitude'] : null;
    $longitude = isset($_POST['longitude']) ? $_POST['longitude'] : null;
    $file_size = $_FILES["file"]["size"];
    $file_type = $_FILES["file"]["type"];
    $checklist = isset($_POST['checklist']) ? $_POST['checklist'] : [];

    // Query student details
    $studentNoEscaped = $conn->real_escape_string($studentNo);
    $student_query = "SELECT surname, firstname, middlename FROM students WHERE studentno = '$studentNoEscaped'";
    $student_result = $conn->query($student_query);
    if (!$student_result) {
        echo "Error: Database query failed: " . $conn->error;
        unlink($file_path);
        die();
    }
    if ($student_result->num_rows == 0) {
        echo "Error: Student not found.";
        unlink($file_path);
        die();
    }
    $student_row = $student_result->fetch_assoc();
    $surname = $student_row['surname'];
    $firstname = $student_row['firstname'];
    $middlename = $student_row['middlename'];

    // Construct new file name
    $name_part = $firstname . ($middlename ? ' ' . $middlename : '');
    $new_file_name = $studentNo . ' - ' . $surname . ', ' . $name_part . '.' . $imageFileType;
    $new_file_path = $target_dir . $new_file_name;

    // Rename the file
    if (!rename($file_path, $new_file_path)) {
        echo "Error: Could not rename file.";
        unlink($file_path);
        die();
    }

    $checklist_str = $conn->real_escape_string(implode(',', $checklist));

    $sql = "INSERT INTO uploaded_files (StudentNo, original_name, file_name, file_path, file_size, file_type, notes, location_latitude, location_longitude, checklist, upload_date, created_at, updated_at) VALUES ('$studentNo', '$original_name', '$new_file_name', '$new_file_path', '$file_size', '$file_type', '$notes', '$latitude', '$longitude', '$checklist_str', NOW(), NOW(), NOW())";

    if ($conn->query($sql) === TRUE) {
      echo "The file ". htmlspecialchars( basename( $_FILES["file"]["name"])). " has been uploaded.";
    } else {
      echo "Error: " . $sql . "<br>" . $conn->error;
    }

  } else {
    echo "Sorry, there was an error uploading your file.";
  }

$conn->close();
?>
