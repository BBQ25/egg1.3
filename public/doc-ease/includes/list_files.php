<?php
include '../layouts/session.php';
require_role('admin');

$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 5;
$offset = ($page - 1) * $limit;

$whereClause = '';
if ($search !== '') {
    $whereClause = "WHERE StudentNo LIKE '%$search%' OR original_name LIKE '%$search%' OR file_name LIKE '%$search%' OR notes LIKE '%$search%'";
}

$countSql = "SELECT COUNT(*) as total FROM uploaded_files $whereClause";
$countResult = $conn->query($countSql);
$totalRows = $countResult->fetch_assoc()['total'];
$totalPages = ceil($totalRows / $limit);

$sql = "SELECT id, StudentNo, original_name, file_name, file_path, file_size, file_type, notes, location_latitude, location_longitude, checklist, upload_date FROM uploaded_files $whereClause ORDER BY upload_date DESC LIMIT $limit OFFSET $offset";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    echo '<div class="table-responsive" style="max-height: 400px; overflow-y: auto;">';
    echo '<table class="table table-bordered table-striped table-sm align-middle">';
    echo '<thead><tr><th>Student No.</th><th>File Name</th><th>Original Name</th><th>Size (KB)</th><th>Type</th><th>Notes</th><th>Latitude</th><th>Longitude</th><th>File Path</th><th>Checklist</th><th>Upload Date</th><th>Actions</th></tr></thead>';
    echo '<tbody>';
    while($row = $result->fetch_assoc()) {
        $fileUrl = '../uploads/' . $row['file_name'];
        $checklistStr = isset($row['checklist']) ? $row['checklist'] : '';
        $checklistItems = $checklistStr !== '' ? explode(',', $checklistStr) : [];
        $checklistHtml = '<ul class="list-unstyled mb-0">';
        foreach ($checklistItems as $item) {
            $checklistHtml .= '<li><span class="badge bg-primary me-1">' . htmlspecialchars(trim($item)) . '</span></li>';
        }
        $checklistHtml .= '</ul>';

        echo '<tr>';
        echo '<td>' . htmlspecialchars($row['StudentNo'] ?? '') . '</td>';
        echo '<td><a href="' . $fileUrl . '" target="_blank">' . htmlspecialchars($row['file_name']) . '</a></td>';
        echo '<td>' . htmlspecialchars($row['original_name']) . '</td>';
        echo '<td>' . round($row['file_size'] / 1024, 2) . '</td>';
        echo '<td>' . htmlspecialchars($row['file_type']) . '</td>';
        echo '<td>' . htmlspecialchars($row['notes'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($row['location_latitude'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($row['location_longitude'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($row['file_path']) . '</td>';
        echo '<td>' . $checklistHtml . '</td>';
        echo '<td>' . $row['upload_date'] . '</td>';
        echo '<td><button class="btn btn-danger btn-sm delete-btn" data-id="' . $row['id'] . '">Delete</button></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '</div>';

    // Pagination controls
    echo '<nav aria-label="Page navigation">';
    echo '<ul class="pagination justify-content-center">';
    if ($page > 1) {
        echo '<li class="page-item"><a class="page-link" href="#" data-page="' . ($page - 1) . '">Previous</a></li>';
    } else {
        echo '<li class="page-item disabled"><span class="page-link">Previous</span></li>';
    }
    for ($i = 1; $i <= $totalPages; $i++) {
        $activeClass = ($i === $page) ? ' active' : '';
        echo '<li class="page-item' . $activeClass . '"><a class="page-link" href="#" data-page="' . $i . '">' . $i . '</a></li>';
    }
    if ($page < $totalPages) {
        echo '<li class="page-item"><a class="page-link" href="#" data-page="' . ($page + 1) . '">Next</a></li>';
    } else {
        echo '<li class="page-item disabled"><span class="page-link">Next</span></li>';
    }
    echo '</ul>';
    echo '</nav>';
} else {
    echo '<p>No files uploaded yet.</p>';
}

$conn->close();
?>
