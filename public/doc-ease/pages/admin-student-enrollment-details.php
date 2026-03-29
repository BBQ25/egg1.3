<?php include '../layouts/session.php'; ?>
<?php require_role('admin'); ?>

<?php
if (!function_exists('ased_h')) {
    function ased_h($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('ased_text_or_na')) {
    function ased_text_or_na($value) {
        $text = trim((string) $value);
        return $text !== '' ? $text : 'N/A';
    }
}

if (!function_exists('ased_status_badge')) {
    function ased_status_badge($status, $group = 'generic') {
        $status = strtolower(trim((string) $status));

        if ($group === 'class_enrollment') {
            if ($status === 'enrolled') return 'success';
            if ($status === 'dropped') return 'danger';
            if ($status === 'completed') return 'info';
            return 'secondary';
        }

        if ($group === 'class_record') {
            if ($status === 'active') return 'success';
            if ($status === 'archived') return 'warning text-dark';
            return 'secondary';
        }

        if ($group === 'queue_enrollment') {
            if ($status === 'active') return 'success';
            if ($status === 'pending') return 'warning text-dark';
            if ($status === 'teacherpending') return 'warning text-dark';
            if ($status === 'claimed') return 'info';
            if ($status === 'dropped') return 'danger';
            if ($status === 'rejected') return 'secondary';
            return 'secondary';
        }

        return 'secondary';
    }
}

$adminIsSuperadmin = current_user_is_superadmin();
$adminCampusId = current_user_campus_id();
if (!$adminIsSuperadmin && $adminCampusId <= 0) {
    deny_access(403, 'Campus admin account has no campus assignment.');
}

$studentId = isset($_GET['student_id']) ? (int) $_GET['student_id'] : 0;
if ($studentId <= 0) {
    $_SESSION['flash_message'] = 'Invalid student record.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: admin-users-students.php');
    exit;
}

$student = null;
if ($adminIsSuperadmin) {
    $studentStmt = $conn->prepare(
        "SELECT s.id,
                s.campus_id,
                s.StudentNo,
                s.Surname,
                s.FirstName,
                s.MiddleName,
                s.Course,
                s.Major,
                s.Year,
                s.Section,
                s.Status AS student_profile_status,
                s.email AS student_email,
                s.user_id,
                u.username AS account_username,
                u.useremail AS account_email,
                u.is_active AS account_is_active
         FROM students s
         LEFT JOIN users u ON u.id = s.user_id
         WHERE s.id = ?
         LIMIT 1"
    );
    if ($studentStmt) {
        $studentStmt->bind_param('i', $studentId);
        $studentStmt->execute();
        $studentRes = $studentStmt->get_result();
        if ($studentRes && $studentRes->num_rows === 1) {
            $student = $studentRes->fetch_assoc();
        }
        $studentStmt->close();
    }
} else {
    $studentStmt = $conn->prepare(
        "SELECT s.id,
                s.campus_id,
                s.StudentNo,
                s.Surname,
                s.FirstName,
                s.MiddleName,
                s.Course,
                s.Major,
                s.Year,
                s.Section,
                s.Status AS student_profile_status,
                s.email AS student_email,
                s.user_id,
                u.username AS account_username,
                u.useremail AS account_email,
                u.is_active AS account_is_active
         FROM students s
         LEFT JOIN users u ON u.id = s.user_id
         WHERE s.id = ?
           AND s.campus_id = ?
         LIMIT 1"
    );
    if ($studentStmt) {
        $studentStmt->bind_param('ii', $studentId, $adminCampusId);
        $studentStmt->execute();
        $studentRes = $studentStmt->get_result();
        if ($studentRes && $studentRes->num_rows === 1) {
            $student = $studentRes->fetch_assoc();
        }
        $studentStmt->close();
    }
}

if (!is_array($student)) {
    $_SESSION['flash_message'] = 'Student record not found.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: admin-users-students.php');
    exit;
}

$classEnrollments = [];
$classStmt = $conn->prepare(
    "SELECT ce.id AS class_enrollment_id,
            ce.class_record_id,
            ce.status AS class_enrollment_status,
            ce.enrollment_date,
            ce.grade,
            ce.remarks,
            cr.status AS class_record_status,
            cr.record_type,
            cr.section AS class_section,
            cr.academic_year,
            cr.semester,
            cr.year_level,
            cr.room_number,
            cr.schedule,
            s.id AS subject_id,
            s.subject_code,
            s.subject_name,
            s.units,
            s.type AS subject_type,
            s.course AS subject_course,
            s.major AS subject_major,
            u.username AS teacher_username,
            u.useremail AS teacher_email
     FROM class_enrollments ce
     JOIN class_records cr ON cr.id = ce.class_record_id
     JOIN subjects s ON s.id = cr.subject_id
     LEFT JOIN users u ON u.id = cr.teacher_id
     WHERE ce.student_id = ?
     ORDER BY cr.academic_year DESC,
              cr.semester DESC,
              ce.enrollment_date DESC,
              s.subject_code ASC,
              s.subject_name ASC"
);
if ($classStmt) {
    $classStmt->bind_param('i', $studentId);
    $classStmt->execute();
    $classRes = $classStmt->get_result();
    while ($classRes && ($row = $classRes->fetch_assoc())) {
        $classEnrollments[] = $row;
    }
    $classStmt->close();
}

$queueEnrollments = [];
$studentNo = trim((string) ($student['StudentNo'] ?? ''));
if ($studentNo !== '') {
    $queueStmt = $conn->prepare(
        "SELECT e.id AS enrollment_id,
                e.subject_id,
                e.academic_year,
                e.semester,
                e.section,
                e.enrollment_date,
                e.status AS enrollment_status,
                e.created_by,
                s.subject_code,
                s.subject_name,
                s.units,
                s.type AS subject_type
         FROM enrollments e
         LEFT JOIN subjects s ON s.id = e.subject_id
         WHERE e.student_no = ?
         ORDER BY e.enrollment_date DESC, e.id DESC"
    );
    if ($queueStmt) {
        $queueStmt->bind_param('s', $studentNo);
        $queueStmt->execute();
        $queueRes = $queueStmt->get_result();
        while ($queueRes && ($row = $queueRes->fetch_assoc())) {
            $queueEnrollments[] = $row;
        }
        $queueStmt->close();
    }
}

$studentName = trim(
    (string) ($student['Surname'] ?? '') . ', ' .
    (string) ($student['FirstName'] ?? '') . ' ' .
    (string) ($student['MiddleName'] ?? '')
);
if ($studentName === '' && $studentNo !== '') {
    $studentName = $studentNo;
}

$studentProgram = trim((string) ($student['Course'] ?? ''));
$studentMajor = trim((string) ($student['Major'] ?? ''));
if ($studentMajor !== '') {
    $studentProgram = trim($studentProgram . ' - ' . $studentMajor);
}
$studentYear = trim((string) ($student['Year'] ?? ''));
$studentSection = trim((string) ($student['Section'] ?? ''));

$activeClassEnrollments = 0;
foreach ($classEnrollments as $row) {
    $ceStatus = strtolower(trim((string) ($row['class_enrollment_status'] ?? '')));
    $crStatus = strtolower(trim((string) ($row['class_record_status'] ?? '')));
    if ($ceStatus === 'enrolled' && $crStatus === 'active') {
        $activeClassEnrollments++;
    }
}

$queueActiveCount = 0;
foreach ($queueEnrollments as $row) {
    if (strtolower(trim((string) ($row['enrollment_status'] ?? ''))) === 'active') {
        $queueActiveCount++;
    }
}

$accountUsername = trim((string) ($student['account_username'] ?? ''));
$accountEmail = trim((string) ($student['account_email'] ?? ''));
$studentEmail = trim((string) ($student['student_email'] ?? ''));
$accountIsActive = isset($student['account_is_active']) ? (int) $student['account_is_active'] : -1;
?>

<?php include '../layouts/main.php'; ?>

<head>
    <title>Student Enrollment Details | E-Record</title>
    <?php include '../layouts/title-meta.php'; ?>
    <?php include '../layouts/head-css.php'; ?>
</head>

<body>
    <div class="wrapper">
        <?php include '../layouts/menu.php'; ?>

        <div class="content-page">
            <div class="content">
                <div class="container-fluid">
                    <div class="row">
                        <div class="col-12">
                            <div class="page-title-box">
                                <div class="page-title-right">
                                    <ol class="breadcrumb m-0">
                                        <li class="breadcrumb-item"><a href="admin-dashboard.php">Admin</a></li>
                                        <li class="breadcrumb-item"><a href="admin-users-students.php">Student Accounts</a></li>
                                        <li class="breadcrumb-item active">Enrollment Details</li>
                                    </ol>
                                </div>
                                <h4 class="page-title">Student Enrollment Details</h4>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                        <div>
                                            <h4 class="mb-1"><?php echo ased_h($studentName !== '' ? $studentName : 'Student'); ?></h4>
                                            <p class="text-muted mb-1">
                                                Student No: <strong><?php echo ased_h(ased_text_or_na($studentNo)); ?></strong>
                                            </p>
                                            <p class="text-muted mb-0">
                                                Program: <?php echo ased_h(ased_text_or_na($studentProgram)); ?> |
                                                Year/Section: <?php echo ased_h(ased_text_or_na(trim($studentYear . ($studentSection !== '' ? ' / ' . $studentSection : '')))); ?>
                                            </p>
                                        </div>
                                        <div class="text-end">
                                            <a class="btn btn-sm btn-outline-secondary" href="admin-users-students.php">
                                                <i class="ri-arrow-left-line me-1" aria-hidden="true"></i>
                                                Back to Student Accounts
                                            </a>
                                        </div>
                                    </div>

                                    <div class="row mt-3 g-3">
                                        <div class="col-md-3 col-sm-6">
                                            <div class="border rounded p-2 h-100">
                                                <div class="small text-muted">Current Active Classes</div>
                                                <div class="fs-4 fw-semibold"><?php echo (int) $activeClassEnrollments; ?></div>
                                            </div>
                                        </div>
                                        <div class="col-md-3 col-sm-6">
                                            <div class="border rounded p-2 h-100">
                                                <div class="small text-muted">Class Enrollment Rows</div>
                                                <div class="fs-4 fw-semibold"><?php echo (int) count($classEnrollments); ?></div>
                                            </div>
                                        </div>
                                        <div class="col-md-3 col-sm-6">
                                            <div class="border rounded p-2 h-100">
                                                <div class="small text-muted">Enrollment Queue Rows</div>
                                                <div class="fs-4 fw-semibold"><?php echo (int) count($queueEnrollments); ?></div>
                                            </div>
                                        </div>
                                        <div class="col-md-3 col-sm-6">
                                            <div class="border rounded p-2 h-100">
                                                <div class="small text-muted">Queue Active Rows</div>
                                                <div class="fs-4 fw-semibold"><?php echo (int) $queueActiveCount; ?></div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row mt-3 g-3">
                                        <div class="col-md-4">
                                            <div class="small text-muted">Student Profile Status</div>
                                            <div class="fw-semibold"><?php echo ased_h(ased_text_or_na($student['student_profile_status'] ?? '')); ?></div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="small text-muted">Student Email</div>
                                            <div class="fw-semibold"><?php echo ased_h(ased_text_or_na($studentEmail)); ?></div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="small text-muted">Linked Login Account</div>
                                            <div class="fw-semibold">
                                                <?php if ($accountUsername !== '' || $accountEmail !== ''): ?>
                                                    <?php echo ased_h($accountUsername !== '' ? $accountUsername : $accountEmail); ?>
                                                    <?php if ($accountIsActive === 1): ?>
                                                        <span class="badge bg-success ms-1">Active</span>
                                                    <?php elseif ($accountIsActive === 0): ?>
                                                        <span class="badge bg-warning text-dark ms-1">Pending</span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">No linked account</span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($accountEmail !== ''): ?>
                                                <div class="small text-muted"><?php echo ased_h($accountEmail); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <h5 class="header-title mb-2">Class Enrollment + Class Record Details</h5>
                                    <p class="text-muted mb-3">Source: <code>class_enrollments</code>, <code>class_records</code>, and <code>subjects</code>.</p>

                                    <div class="table-responsive">
                                        <table class="table table-sm table-striped table-hover align-middle mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Subject</th>
                                                    <th>Term / Section</th>
                                                    <th>Class Record</th>
                                                    <th>Enrollment</th>
                                                    <th>Teacher</th>
                                                    <th class="text-end">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (count($classEnrollments) === 0): ?>
                                                    <tr>
                                                        <td colspan="6" class="text-center text-muted py-3">No class enrollment records found.</td>
                                                    </tr>
                                                <?php endif; ?>

                                                <?php foreach ($classEnrollments as $row): ?>
                                                    <?php
                                                    $classRecordId = (int) ($row['class_record_id'] ?? 0);
                                                    $subjectCode = trim((string) ($row['subject_code'] ?? ''));
                                                    $subjectName = trim((string) ($row['subject_name'] ?? ''));
                                                    $subjectType = trim((string) ($row['subject_type'] ?? ''));
                                                    $subjectUnits = (string) ($row['units'] ?? '');
                                                    $academicYear = trim((string) ($row['academic_year'] ?? ''));
                                                    $semester = trim((string) ($row['semester'] ?? ''));
                                                    $classSection = trim((string) ($row['class_section'] ?? ''));
                                                    $yearLevel = trim((string) ($row['year_level'] ?? ''));
                                                    $roomNumber = trim((string) ($row['room_number'] ?? ''));
                                                    $schedule = trim((string) ($row['schedule'] ?? ''));
                                                    $classRecordStatus = trim((string) ($row['class_record_status'] ?? ''));
                                                    $recordType = trim((string) ($row['record_type'] ?? ''));
                                                    $classEnrollmentStatus = trim((string) ($row['class_enrollment_status'] ?? ''));
                                                    $enrollmentDate = trim((string) ($row['enrollment_date'] ?? ''));
                                                    $grade = isset($row['grade']) ? trim((string) $row['grade']) : '';
                                                    $remarks = trim((string) ($row['remarks'] ?? ''));
                                                    $teacherName = trim((string) ($row['teacher_username'] ?? ''));
                                                    $teacherEmail = trim((string) ($row['teacher_email'] ?? ''));
                                                    ?>
                                                    <tr>
                                                        <td>
                                                            <div class="fw-semibold"><?php echo ased_h($subjectName !== '' ? $subjectName : 'Unknown Subject'); ?></div>
                                                            <div class="text-muted small">
                                                                <?php echo ased_h($subjectCode !== '' ? $subjectCode : 'No subject code'); ?>
                                                                <?php if ($subjectType !== ''): ?>
                                                                    | <?php echo ased_h($subjectType); ?>
                                                                <?php endif; ?>
                                                                <?php if ($subjectUnits !== ''): ?>
                                                                    | <?php echo ased_h($subjectUnits); ?> unit(s)
                                                                <?php endif; ?>
                                                            </div>
                                                        </td>
                                                        <td class="small text-muted">
                                                            <div><?php echo ased_h(ased_text_or_na($academicYear)); ?></div>
                                                            <div><?php echo ased_h(ased_text_or_na($semester)); ?></div>
                                                            <div>Section: <?php echo ased_h(ased_text_or_na($classSection)); ?></div>
                                                            <?php if ($yearLevel !== ''): ?>
                                                                <div>Year Level: <?php echo ased_h($yearLevel); ?></div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="small">
                                                            <div class="text-muted">ID: <strong><?php echo (int) $classRecordId; ?></strong></div>
                                                            <div>
                                                                <span class="badge bg-<?php echo ased_h(ased_status_badge($classRecordStatus, 'class_record')); ?>">
                                                                    <?php echo ased_h(ucfirst($classRecordStatus !== '' ? $classRecordStatus : 'unknown')); ?>
                                                                </span>
                                                                <?php if ($recordType !== ''): ?>
                                                                    <span class="badge bg-light text-dark border ms-1"><?php echo ased_h(ucfirst($recordType)); ?></span>
                                                                <?php endif; ?>
                                                            </div>
                                                            <?php if ($roomNumber !== ''): ?>
                                                                <div class="text-muted">Room: <?php echo ased_h($roomNumber); ?></div>
                                                            <?php endif; ?>
                                                            <?php if ($schedule !== ''): ?>
                                                                <div class="text-muted">Schedule: <?php echo ased_h($schedule); ?></div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="small">
                                                            <div>
                                                                <span class="badge bg-<?php echo ased_h(ased_status_badge($classEnrollmentStatus, 'class_enrollment')); ?>">
                                                                    <?php echo ased_h(ucfirst($classEnrollmentStatus !== '' ? $classEnrollmentStatus : 'unknown')); ?>
                                                                </span>
                                                            </div>
                                                            <div class="text-muted">Date: <?php echo ased_h(ased_text_or_na($enrollmentDate)); ?></div>
                                                            <?php if ($grade !== '' && strtolower($grade) !== 'null'): ?>
                                                                <div class="text-muted">Grade: <?php echo ased_h($grade); ?></div>
                                                            <?php endif; ?>
                                                            <?php if ($remarks !== ''): ?>
                                                                <div class="text-muted">Remarks: <?php echo ased_h($remarks); ?></div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="small">
                                                            <div class="fw-semibold"><?php echo ased_h($teacherName !== '' ? $teacherName : 'Unassigned'); ?></div>
                                                            <?php if ($teacherEmail !== ''): ?>
                                                                <div class="text-muted"><?php echo ased_h($teacherEmail); ?></div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="text-end">
                                                            <?php if ($classRecordId > 0): ?>
                                                                <a class="btn btn-sm btn-outline-secondary mb-1" href="admin-class-roster.php?class_record_id=<?php echo (int) $classRecordId; ?>">
                                                                    Roster
                                                                </a>
                                                                <a class="btn btn-sm btn-outline-primary" href="class-record-print.php?class_record_id=<?php echo (int) $classRecordId; ?>&term=midterm&view=assessments">
                                                                    Class Record
                                                                </a>
                                                            <?php else: ?>
                                                                <span class="text-muted small">-</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <h5 class="header-title mb-2">Enrollment Queue / History</h5>
                                    <p class="text-muted mb-3">Source: <code>enrollments</code> table.</p>

                                    <div class="table-responsive">
                                        <table class="table table-sm table-striped table-hover align-middle mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Subject</th>
                                                    <th>Term / Section</th>
                                                    <th>Status</th>
                                                    <th>Enrollment Date</th>
                                                    <th>Created By</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (count($queueEnrollments) === 0): ?>
                                                    <tr>
                                                        <td colspan="5" class="text-center text-muted py-3">No enrollment queue records found.</td>
                                                    </tr>
                                                <?php endif; ?>

                                                <?php foreach ($queueEnrollments as $row): ?>
                                                    <?php
                                                    $subjectCode = trim((string) ($row['subject_code'] ?? ''));
                                                    $subjectName = trim((string) ($row['subject_name'] ?? ''));
                                                    $subjectType = trim((string) ($row['subject_type'] ?? ''));
                                                    $subjectUnits = (string) ($row['units'] ?? '');
                                                    $academicYear = trim((string) ($row['academic_year'] ?? ''));
                                                    $semester = trim((string) ($row['semester'] ?? ''));
                                                    $section = trim((string) ($row['section'] ?? ''));
                                                    $status = trim((string) ($row['enrollment_status'] ?? ''));
                                                    $enrollmentDate = trim((string) ($row['enrollment_date'] ?? ''));
                                                    $createdBy = trim((string) ($row['created_by'] ?? ''));
                                                    ?>
                                                    <tr>
                                                        <td>
                                                            <div class="fw-semibold"><?php echo ased_h($subjectName !== '' ? $subjectName : 'Unknown Subject'); ?></div>
                                                            <div class="text-muted small">
                                                                <?php echo ased_h($subjectCode !== '' ? $subjectCode : 'No subject code'); ?>
                                                                <?php if ($subjectType !== ''): ?>
                                                                    | <?php echo ased_h($subjectType); ?>
                                                                <?php endif; ?>
                                                                <?php if ($subjectUnits !== ''): ?>
                                                                    | <?php echo ased_h($subjectUnits); ?> unit(s)
                                                                <?php endif; ?>
                                                            </div>
                                                        </td>
                                                        <td class="small text-muted">
                                                            <div><?php echo ased_h(ased_text_or_na($academicYear)); ?></div>
                                                            <div><?php echo ased_h(ased_text_or_na($semester)); ?></div>
                                                            <div>Section: <?php echo ased_h(ased_text_or_na($section)); ?></div>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-<?php echo ased_h(ased_status_badge($status, 'queue_enrollment')); ?>">
                                                                <?php echo ased_h(ucfirst($status !== '' ? $status : 'unknown')); ?>
                                                            </span>
                                                        </td>
                                                        <td class="small text-muted"><?php echo ased_h(ased_text_or_na($enrollmentDate)); ?></td>
                                                        <td class="small text-muted"><?php echo ased_h(ased_text_or_na($createdBy)); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <?php include '../layouts/footer.php'; ?>
        </div>
    </div>

    <?php include '../layouts/right-sidebar.php'; ?>
    <?php include '../layouts/footer-scripts.php'; ?>
    <script src="assets/js/app.min.js"></script>
</body>

</html>
