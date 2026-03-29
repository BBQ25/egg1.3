<?php include '../layouts/session.php'; ?>
<?php require_role('admin'); ?>
<?php include '../layouts/main.php'; ?>

<?php
// Fetch courses and their majors from students table
$courseMajors = [];
$courseMajorQuery = "SELECT course, major FROM students GROUP BY course, major ORDER BY course, major";

$courseMajorResult = $conn->query($courseMajorQuery);
if ($courseMajorResult) {
    while ($row = $courseMajorResult->fetch_assoc()) {
        $course = $row['course'];
        $major = $row['major'];
        if (!isset($courseMajors[$course])) {
            $courseMajors[$course] = [];
        }
        $courseMajors[$course][] = $major;
    }
}
$courses = array_keys($courseMajors);

// Fetch all subjects
$subjectsQuery = "SELECT subject_code, subject_name, description, course, major, academic_year, semester, units, type, status FROM subjects ORDER BY created_at DESC";
$subjectsResult = $conn->query($subjectsQuery);
$subjects = [];
if ($subjectsResult) {
    while ($row = $subjectsResult->fetch_assoc()) {
        $subjects[] = $row;
    }
}

// Academic year options
include_once __DIR__ . '/../includes/reference.php';
ensure_reference_tables($conn);
$academicYears = ref_list_active_names($conn, 'academic_years');
if (count($academicYears) === 0) {
    // Backward-compatible defaults if the reference list is still empty.
    $academicYears = ['2025 - 2026', '2026 - 2027'];
}

// Semester options
$semesters = ref_list_active_names($conn, 'semesters');
if (count($semesters) === 0) {
    $semesters = ['1st Semester', '2nd Semester', 'Summer'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
        $_SESSION['error'] = 'Required fields are missing.';
    } else {
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
                $_SESSION['error'] = 'No valid user account found to own the new subject.';
                header('Location: add-subject.php');
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

            if ($insertStmt->execute()) {
                $_SESSION['success'] = 'Subject added successfully.';
            } else {
                $_SESSION['error'] = 'Database insert failed: ' . $insertStmt->error;
            }
            $insertStmt->close();
        } else {
            $_SESSION['error'] = 'Database insert failed: unable to prepare statement.';
        }
    }
    header('Location: add-subject.php');
    exit;
}
?>

<head>
    <title>Subject | Attex - Bootstrap 5 Admin & Dashboard Template</title>
    <?php include '../layouts/title-meta.php'; ?>
    <?php include '../layouts/head-css.php'; ?>
    <link rel="stylesheet" href="assets/css/admin-ops-ui.css">

    <style>
        :root { --subject-hero-min-h: 148px; }

        .subject-hero {
            min-height: var(--subject-hero-min-h);
            background: linear-gradient(140deg, #14253d 0%, #194f8a 52%, #1f7d75 100%);
        }

        .subject-hero__bg {
            background-image:
                linear-gradient(135deg, rgba(17, 24, 39, 0.38), rgba(17, 24, 39, 0.05)),
                url("assets/images/bg-auth.jpg");
            background-size: cover;
            background-position: center;
            filter: saturate(0.95) contrast(1.02);
        }

        .subject-hero__bg::after {
            content: "";
            position: absolute;
            inset: 0;
            background:
                radial-gradient(800px 260px at 10% 25%, rgba(48, 81, 255, 0.35), transparent 55%),
                radial-gradient(680px 240px at 92% 78%, rgba(16, 185, 129, 0.22), transparent 58%);
            mix-blend-mode: screen;
            opacity: 0.9;
        }

        .compact-form .row.mb-3 { margin-bottom: 0.75rem !important; }
        .compact-form .form-label { margin-bottom: 0.25rem; }

        .card-tight .card-body { padding: 1rem; }
        @media (min-width: 992px) { .card-tight .card-body { padding: 1.25rem; } }

        .subject-form-actions {
            display: flex;
            justify-content: flex-end;
        }

        .subject-table-actions { white-space: nowrap; }

        .ops-chip--link { text-decoration: none; }
        .ops-chip--link:hover { color: #fff; border-color: rgba(255, 255, 255, 0.4); background: rgba(255, 255, 255, 0.22); }

        .table-subjects th,
        .table-subjects td { vertical-align: middle; }

        @media (prefers-reduced-motion: reduce) {
            .subject-hero__bg { transform: none !important; }
        }
    </style>
</head>

<body>
    <!-- Begin page -->
    <div class="wrapper">

        <?php include '../layouts/menu.php'; ?>

        <!-- ============================================================== -->
        <!-- Start Page Content here -->
        <!-- ============================================================== -->

        <div class="content-page">
            <div class="content">

                <!-- Start Content-->
                <div class="container-fluid">

                    <div class="subject-hero ops-hero ops-page-shell" id="subjectHero" data-ops-parallax>
                        <div class="subject-hero__bg ops-hero__bg" data-ops-parallax-layer aria-hidden="true"></div>
                        <div class="ops-hero__content">
                            <div class="d-flex flex-wrap align-items-end justify-content-between gap-2">
                                <div>
                                    <div class="ops-hero__kicker">Admin Panel / Subjects</div>
                                    <h2 class="ops-hero__title">Subjects</h2>
                                    <div class="ops-hero__subtitle">Create, edit, and maintain your subject offerings. Use the list to quickly update details or disable a subject.</div>
                                </div>
                                <div class="d-none d-md-flex gap-2">
                                    <a class="ops-chip ops-chip--link" href="#subjectForm">
                                        <i class="ri-add-line" aria-hidden="true"></i>
                                        <span>Add</span>
                                    </a>
                                    <a class="ops-chip ops-chip--link" href="#subjectsTable">
                                        <i class="ri-list-check-2" aria-hidden="true"></i>
                                        <span>View List</span>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-xl-5" id="subjectForm">
                            <div class="card card-tight h-100 ops-card ops-page-shell">
                                <div class="card-body">

                                    <h4 class="header-title mb-3">Subject Details</h4>

                                    <?php if (isset($_SESSION['error'])): ?>
                                    <div class="alert alert-danger">
                                        <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                                    </div>
                                    <?php endif; ?>

                                    <form action="" method="post" class="compact-form">
                                        <div class="row mb-3">
                                            <div class="col-md-4">
                                                <label class="form-label" for="subjectCode">Subject Code <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control" id="subjectCode" name="subjectCode" required>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label" for="subjectName">Subject Name <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control" id="subjectName" name="subjectName" required>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label" for="description">Description</label>
                                                <input type="text" class="form-control" id="description" name="description">
                                            </div>
                                        </div>
                                        <div class="row mb-3">
                                            <div class="col-md-3">
                                                <label class="form-label" for="course">Course</label>
                                                <select class="form-select" id="course" name="course">
                                                    <?php foreach ($courses as $courseOption): ?>
                                                    <option value="<?php echo htmlspecialchars($courseOption); ?>"><?php echo htmlspecialchars($courseOption); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label" for="major">Major</label>
                                                <select class="form-select" id="major" name="major">
                                                </select>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label" for="academicYear">Academic Year</label>
                                                <select class="form-select" id="academicYear" name="academicYear">
                                                    <option value="">Select Academic Year</option>
                                                    <?php foreach ($academicYears as $yearOption): ?>
                                                    <option value="<?php echo htmlspecialchars($yearOption); ?>"><?php echo htmlspecialchars($yearOption); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label" for="semester">Semester</label>
                                                <select class="form-select" id="semester" name="semester">
                                                    <option value="">Select Semester</option>
                                                    <?php foreach ($semesters as $semesterOption): ?>
                                                    <option value="<?php echo htmlspecialchars($semesterOption); ?>"><?php echo htmlspecialchars($semesterOption); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="row mb-3">
                                            <div class="col-md-4">
                                                <label class="form-label">Type <span class="text-danger">*</span></label>
                                                <div class="ops-choice-group">
                                                    <div class="form-check form-check-inline">
                                                        <input class="form-check-input" type="radio" name="subjectType" id="lecture" value="Lecture" required>
                                                        <label class="form-check-label" for="lecture">
                                                            Lecture
                                                        </label>
                                                    </div>
                                                    <div class="form-check form-check-inline">
                                                        <input class="form-check-input" type="radio" name="subjectType" id="laboratory" value="Laboratory" required>
                                                        <label class="form-check-label" for="laboratory">
                                                            Laboratory
                                                        </label>
                                                    </div>
                                                    <div class="form-check form-check-inline">
                                                        <input class="form-check-input" type="radio" name="subjectType" id="lecLab" value="Lec&Lab" required>
                                                        <label class="form-check-label" for="lecLab">
                                                            Lec&Lab
                                                        </label>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label" for="units">Units <span class="text-danger">*</span></label>
                                                <input type="number" class="form-control" id="units" name="units" step="0.1" value="2.0" required>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Status <span class="text-danger">*</span></label>
                                                <div class="ops-choice-group">
                                                    <div class="form-check form-check-inline">
                                                        <input class="form-check-input" type="radio" name="status" id="active" value="active" checked required>
                                                        <label class="form-check-label" for="active">
                                                            Active
                                                        </label>
                                                    </div>
                                                    <div class="form-check form-check-inline">
                                                        <input class="form-check-input" type="radio" name="status" id="inactive" value="inactive" required>
                                                        <label class="form-check-label" for="inactive">
                                                            Inactive
                                                        </label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row mb-3">
                                            <div class="col-12 subject-form-actions">
                                                <button type="submit" class="btn btn-primary">Add Subject</button>
                                            </div>
                                        </div>
                                    </form>

                                </div> <!-- end card-body -->
                            </div> <!-- end card-->
                        </div> <!-- end col -->

                        <div class="col-xl-7" id="subjectsTable">
                            <div class="card card-tight h-100 ops-card ops-page-shell">
                                <div class="card-body">
                                    <h4 class="header-title mb-3">Added Subjects</h4>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-striped table-hover table-subjects ops-table">
                                            <thead>
                                                <tr>
                                                    <th>Action</th>
                                                    <th>Subject Code</th>
                                                    <th>Subject Name</th>
                                                    <th>Description</th>
                                                    <th>Course</th>
                                                    <th>Major</th>
                                                    <th>Academic Year</th>
                                                    <th>Semester</th>
                                                    <th>Units</th>
                                                    <th>Type</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (empty($subjects)): ?>
                                                <tr>
                                                    <td colspan="11" class="text-center">No subjects added yet.</td>
                                                </tr>
                                                <?php else: ?>
                                                <?php foreach ($subjects as $subject): ?>
                                                <tr>
                                                    <td class="subject-table-actions">
                                                        <button
                                                            type="button"
                                                            class="btn btn-sm btn-outline-primary ops-btn-icon me-1"
                                                            onclick="editSubject(this)"
                                                            data-bs-toggle="tooltip"
                                                            title="Edit"
                                                            data-subject-code="<?php echo htmlspecialchars($subject['subject_code']); ?>"
                                                            data-subject-name="<?php echo htmlspecialchars($subject['subject_name']); ?>"
                                                            data-description="<?php echo htmlspecialchars($subject['description']); ?>"
                                                            data-course="<?php echo htmlspecialchars($subject['course']); ?>"
                                                            data-major="<?php echo htmlspecialchars($subject['major']); ?>"
                                                            data-academic-year="<?php echo htmlspecialchars($subject['academic_year']); ?>"
                                                            data-semester="<?php echo htmlspecialchars($subject['semester']); ?>"
                                                            data-units="<?php echo htmlspecialchars($subject['units']); ?>"
                                                            data-type="<?php echo htmlspecialchars($subject['type']); ?>"
                                                            data-status="<?php echo htmlspecialchars($subject['status']); ?>"
                                                            aria-label="Edit"
                                                        >
                                                            <i class="ri-pencil-line" aria-hidden="true"></i>
                                                        </button>
                                                        <button
                                                            type="button"
                                                            class="btn btn-sm btn-outline-danger ops-btn-icon"
                                                            onclick="deleteSubject('<?php echo htmlspecialchars($subject['subject_code']); ?>')"
                                                            data-bs-toggle="tooltip"
                                                            title="Delete"
                                                            aria-label="Delete"
                                                        >
                                                            <i class="ri-delete-bin-6-line" aria-hidden="true"></i>
                                                        </button>
                                                    </td>
                                                        <td><?php echo htmlspecialchars((string)($subject['subject_code'] ?? '')); ?></td>
                                                        <td><?php echo htmlspecialchars((string)($subject['subject_name'] ?? '')); ?></td>
                                                        <td><?php echo htmlspecialchars((string)($subject['description'] ?? '')); ?></td>
                                                        <td><?php echo htmlspecialchars((string)($subject['course'] ?? '')); ?></td>
                                                        <td><?php echo htmlspecialchars((string)($subject['major'] ?? '')); ?></td>
                                                        <td><?php echo htmlspecialchars((string)($subject['academic_year'] ?? '')); ?></td>
                                                        <td><?php echo htmlspecialchars((string)($subject['semester'] ?? '')); ?></td>
                                                        <td><?php echo htmlspecialchars((string)($subject['units'] ?? '')); ?></td>
                                                        <td><?php echo htmlspecialchars((string)($subject['type'] ?? '')); ?></td>
                                                        <td>
                                                            <span class="badge bg-<?php echo ((string)($subject['status'] ?? '')) === 'active' ? 'success' : 'secondary'; ?>">
                                                                <?php echo htmlspecialchars((string)ucfirst($subject['status'] ?? '')); ?>
                                                        </span>
                                                        </td>
                                                </tr>
                                                <?php endforeach; ?>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div> <!-- end card-body -->
                            </div> <!-- end card-->
                        </div> <!-- end col -->
                    </div>
                    <!-- end row -->

                </div> <!-- container -->

            </div> <!-- content -->

            <?php include '../layouts/footer.php'; ?>

        </div>

        <!-- ============================================================== -->
        <!-- End Page content -->
        <!-- ============================================================== -->
    </div>
    <!-- END wrapper -->

    <!-- Success Modal -->
    <div class="modal fade" id="successModal" tabindex="-1" aria-labelledby="successModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-success">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="successModalLabel">
                        <i class="fas fa-check-circle me-2"></i>Success!
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <div class="mb-3">
                        <i class="fas fa-party-popper fa-3x text-success"></i>
                    </div>
                    <h4 class="text-success"><?php echo isset($_SESSION['success']) ? $_SESSION['success'] : ''; ?></h4>
                    <div id="confetti-container"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-success" data-bs-dismiss="modal">
                        <i class="fas fa-thumbs-up me-1"></i>Great!
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-danger">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="deleteModalLabel">
                        <i class="fas fa-trash me-2"></i>Confirm Delete
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <div class="mb-3">
                        <i class="fas fa-exclamation-triangle fa-3x text-danger"></i>
                    </div>
                    <h4 class="text-danger">Are you sure you want to delete this subject?</h4>
                    <p>This action cannot be undone.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Cancel
                    </button>
                    <button type="button" class="btn btn-danger" id="confirmDeleteBtn">
                        <i class="fas fa-trash me-1"></i>Delete
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Notification Modal -->
    <div class="modal fade" id="notificationModal" tabindex="-1" aria-labelledby="notificationModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" id="notificationModalContent">
                <div class="modal-header" id="notificationModalHeader">
                    <h5 class="modal-title" id="notificationModalLabel">Notification</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center" id="notificationModalBody">
                    Message here
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Subject Modal -->
    <div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editModalLabel">Edit Subject</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="editSubjectForm">
                        <input type="hidden" id="originalSubjectCode" name="originalSubjectCode" />
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label class="form-label" for="editSubjectCode">Subject Code <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="editSubjectCode" name="subjectCode" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="editSubjectName">Subject Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="editSubjectName" name="subjectName" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="editDescription">Description</label>
                                <input type="text" class="form-control" id="editDescription" name="description">
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-3">
                                <label class="form-label" for="editCourse">Course</label>
                                <select class="form-select" id="editCourse" name="course">
                                    <?php foreach ($courses as $courseOption): ?>
                                    <option value="<?php echo htmlspecialchars($courseOption); ?>"><?php echo htmlspecialchars($courseOption); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="editMajor">Major</label>
                                <select class="form-select" id="editMajor" name="major">
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="editAcademicYear">Academic Year</label>
                                <select class="form-select" id="editAcademicYear" name="academicYear">
                                    <option value="">Select Academic Year</option>
                                    <?php foreach ($academicYears as $yearOption): ?>
                                    <option value="<?php echo htmlspecialchars($yearOption); ?>"><?php echo htmlspecialchars($yearOption); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="editSemester">Semester</label>
                                <select class="form-select" id="editSemester" name="semester">
                                    <option value="">Select Semester</option>
                                    <?php foreach ($semesters as $semesterOption): ?>
                                    <option value="<?php echo htmlspecialchars($semesterOption); ?>"><?php echo htmlspecialchars($semesterOption); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Type <span class="text-danger">*</span></label>
                                <div class="ops-choice-group">
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="subjectType" id="editLecture" value="Lecture" required>
                                        <label class="form-check-label" for="editLecture">
                                            Lecture
                                        </label>
                                    </div>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="subjectType" id="editLaboratory" value="Laboratory" required>
                                        <label class="form-check-label" for="editLaboratory">
                                            Laboratory
                                        </label>
                                    </div>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="subjectType" id="editLecLab" value="Lec&Lab" required>
                                        <label class="form-check-label" for="editLecLab">
                                            Lec&Lab
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="editUnits">Units <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="editUnits" name="units" step="0.1" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Status <span class="text-danger">*</span></label>
                                <div class="ops-choice-group">
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="status" id="editActive" value="active" required>
                                        <label class="form-check-label" for="editActive">
                                            Active
                                        </label>
                                    </div>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="status" id="editInactive" value="inactive" required>
                                        <label class="form-check-label" for="editInactive">
                                            Inactive
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="saveEditBtn">Save Changes</button>
                </div>
            </div>
        </div>
    </div>

    <?php include '../layouts/right-sidebar.php'; ?>

    <?php include '../layouts/footer-scripts.php'; ?>

    <!-- Confetti Library -->
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.5.1/dist/confetti.browser.min.js"></script>

    <!-- App js -->
    <script src="assets/js/app.min.js"></script>
    <script src="assets/js/admin-ops-ui.js"></script>

    <script>
    // Course-Major mapping
    const courseMajors = <?php echo json_encode($courseMajors); ?>;

    $(document).ready(function() {
        // Tooltips for compact icon action buttons.
        if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
            document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => {
                bootstrap.Tooltip.getOrCreateInstance(el);
            });
        }

        // Function to update majors based on selected course
        function updateMajors() {
            const selectedCourse = $('#course').val();
            const majorSelect = $('#major');
            majorSelect.empty(); // Clear existing options

            if (selectedCourse && courseMajors[selectedCourse]) {
                courseMajors[selectedCourse].forEach(function(major) {
                    majorSelect.append('<option value="' + major + '">' + major + '</option>');
                });
            }
        }

        // Function to update majors for edit modal
        function updateMajorsForEdit() {
            const selectedCourse = $('#editCourse').val();
            const majorSelect = $('#editMajor');
            majorSelect.empty(); // Clear existing options

            if (selectedCourse && courseMajors[selectedCourse]) {
                courseMajors[selectedCourse].forEach(function(major) {
                    majorSelect.append('<option value="' + major + '">' + major + '</option>');
                });
            }
        }

        // Update majors on course change
        $('#course').change(updateMajors);
        $('#editCourse').change(updateMajorsForEdit);

        // Initial update if course is pre-selected
        updateMajors();

        // Set default units for Lecture
        $('#lecture').prop('checked', true);
        $('#units').val(2.0);

        // Update units on type change for add form
        $('input[name="subjectType"]').change(function() {
            var type = $(this).val();
            if (type === 'Lecture') {
                $('#units').val(2.0);
            } else if (type === 'Laboratory') {
                $('#units').val(1.0);
            } else if (type === 'Lec&Lab') {
                $('#units').val(3.0);
            }
        });

        // Update units on type change for edit modal
        $('input[name="subjectType"][id^="edit"]').change(function() {
            var type = $(this).val();
            if (type === 'Lecture') {
                $('#editUnits').val(2.0);
            } else if (type === 'Laboratory') {
                $('#editUnits').val(1.0);
            } else if (type === 'Lec&Lab') {
                $('#editUnits').val(3.0);
            }
        });

        <?php if (isset($_SESSION['success'])): ?>
        $('#successModal').modal('show');
        // Trigger confetti when modal is shown
        $('#successModal').on('shown.bs.modal', function () {
            confetti({
                particleCount: 100,
                spread: 70,
                origin: { y: 0.6 }
            });
        });
        <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        // Save edit button handler
        $('#saveEditBtn').on('click', function() {
            var formData = $('#editSubjectForm').serialize();
            $.ajax({
                url: 'process_edit_subject.php',
                type: 'POST',
                data: formData,
                success: function(response) {
                    var data = JSON.parse(response);
                    if (data.status === 'success') {
                        showNotification('Success', data.message, 'success');
                        $('#editModal').modal('hide');
                        location.reload(); // Reload the page to update the table
                    } else {
                        showNotification('Error', data.message, 'danger');
                    }
                },
                error: function() {
                    showNotification('Error', 'An error occurred while updating the subject.', 'danger');
                }
            });
        });
    });

    function editSubject(button) {
        var code = button.dataset.subjectCode;
        var name = button.dataset.subjectName;
        var description = button.dataset.description;
        var course = button.dataset.course;
        var major = button.dataset.major;
        var academicYear = button.dataset.academicYear;
        var semester = button.dataset.semester;
        var units = button.dataset.units;
        var type = button.dataset.type;
        var status = button.dataset.status;

        $('#originalSubjectCode').val(code);
        $('#editSubjectCode').val(code);
        $('#editSubjectName').val(name);
        $('#editDescription').val(description);
        $('#editCourse').val(course).trigger('change');
        setTimeout(function() {
            $('#editMajor').val(major);
        }, 100);
        $('#editAcademicYear').val(academicYear);
        $('#editSemester').val(semester);
        $('#editUnits').val(units);

        if (type === 'Lecture') {
            $('#editLecture').prop('checked', true);
        } else if (type === 'Laboratory') {
            $('#editLaboratory').prop('checked', true);
        } else if (type === 'Lec&Lab') {
            $('#editLecLab').prop('checked', true);
        }

        if (status === 'active') {
            $('#editActive').prop('checked', true);
        } else {
            $('#editInactive').prop('checked', true);
        }

        $('#editModal').modal('show');
    }

    function deleteSubject(subjectCode) {
        $('#deleteModal').modal('show');
        $('#confirmDeleteBtn').off('click').on('click', function() {
            $('#deleteModal').modal('hide');
            $.ajax({
                url: 'process_delete_subject.php',
                type: 'POST',
                data: { subjectCode: subjectCode },
                success: function(response) {
                    var data = JSON.parse(response);
                    if (data.status === 'success') {
                        showNotification('Success', data.message, 'success');
                        location.reload(); // Reload the page to update the table
                    } else {
                        showNotification('Error', data.message, 'danger');
                    }
                },
                error: function() {
                    showNotification('Error', 'An error occurred while deleting the subject.', 'danger');
                }
            });
        });
    }

    function showNotification(title, message, type) {
        $('#notificationModalLabel').text(title);
        $('#notificationModalBody').html('<h4 class="text-' + type + '">' + message + '</h4>');
        $('#notificationModalHeader').removeClass('bg-success bg-danger').addClass('bg-' + type);
        $('#notificationModal').modal('show');
    }
    </script>
</body>

</html>

