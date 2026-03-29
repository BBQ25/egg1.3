<?php include '../layouts/session.php'; ?>
<?php require_role('admin'); ?>
<?php include '../layouts/main.php'; ?>

<?php
$integrityError = '';
$integrityRows = [];
$integritySummary = [
    'duplicate_groups' => 0,
    'affected_students' => 0,
    'extra_active_rows' => 0,
];

$integritySql =
    "SELECT ce.student_id,
            st.StudentNo AS student_no,
            st.Surname AS surname,
            st.FirstName AS firstname,
            st.MiddleName AS middlename,
            cr.subject_id,
            s.subject_code,
            s.subject_name,
            cr.academic_year,
            cr.semester,
            COUNT(*) AS duplicate_count,
            GROUP_CONCAT(
                CONCAT('ce#', ce.id, ' / class#', cr.id, ' / sec=', cr.section)
                ORDER BY ce.id ASC
                SEPARATOR ' || '
            ) AS duplicate_details
     FROM class_enrollments ce
     JOIN class_records cr ON cr.id = ce.class_record_id
     JOIN students st ON st.id = ce.student_id
     JOIN subjects s ON s.id = cr.subject_id
     WHERE ce.status = 'enrolled'
       AND cr.status = 'active'
     GROUP BY ce.student_id, cr.subject_id, cr.academic_year, cr.semester
     HAVING COUNT(*) > 1
     ORDER BY duplicate_count DESC, st.Surname ASC, st.FirstName ASC, s.subject_code ASC";

$integrityStmt = $conn->prepare($integritySql);
if ($integrityStmt) {
    $integrityStmt->execute();
    $integrityRes = $integrityStmt->get_result();
    while ($integrityRes && ($row = $integrityRes->fetch_assoc())) {
        $integrityRows[] = $row;
    }
    $integrityStmt->close();
} else {
    $integrityError = 'Unable to run integrity check right now.';
}

if ($integrityError === '') {
    $integritySummary['duplicate_groups'] = count($integrityRows);
    $affected = [];
    $extraRows = 0;
    foreach ($integrityRows as $row) {
        $studentId = (int) ($row['student_id'] ?? 0);
        if ($studentId > 0) $affected[$studentId] = true;

        $dupCount = (int) ($row['duplicate_count'] ?? 0);
        if ($dupCount > 1) $extraRows += ($dupCount - 1);
    }
    $integritySummary['affected_students'] = count($affected);
    $integritySummary['extra_active_rows'] = $extraRows;
}

$integrityDisplayRows = array_slice($integrityRows, 0, 6);
?>

<head>
    <title>Admin Dashboard | E-Record</title>
    <?php include '../layouts/title-meta.php'; ?>
    <?php include '../layouts/head-css.php'; ?>
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

                    <div class="row">
                        <div class="col-12">
                            <div class="page-title-box">
                                <div class="page-title-right">
                                    <ol class="breadcrumb m-0">
                                        <li class="breadcrumb-item"><a href="javascript: void(0);">E-Record</a></li>
                                        <li class="breadcrumb-item active">Admin Dashboard</li>
                                    </ol>
                                </div>
                                <h4 class="page-title">Admin Dashboard</h4>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-xl-8">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title">Access Management</h4>
                                    <p class="text-muted">
                                        Manage student and teacher accounts separately, or open the full account list.
                                    </p>
                                    <div class="d-flex flex-wrap gap-2">
                                        <a class="btn btn-primary" href="admin-users-students.php">Student Accounts</a>
                                        <a class="btn btn-outline-primary" href="admin-users-teachers.php">Teacher Accounts</a>
                                        <a class="btn btn-outline-secondary" href="admin-enrollment-approvals.php">Enrollment Approvals</a>
                                        <a class="btn btn-light" href="admin-users.php">All Accounts</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-4">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title">Quick Tips</h4>
                                    <p class="text-muted mb-0">
                                        Approve accounts to unlock their dashboard access. You can revoke access anytime.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                                        <div>
                                            <h4 class="header-title mb-1">Data Integrity Check</h4>
                                            <p class="text-muted mb-2">
                                                Flags duplicate active class enrollments for the same student + subject + term.
                                            </p>
                                        </div>
                                        <div class="d-flex flex-wrap gap-2">
                                            <span class="badge bg-light text-dark border">
                                                Duplicate groups: <?php echo (int) ($integritySummary['duplicate_groups'] ?? 0); ?>
                                            </span>
                                            <span class="badge bg-light text-dark border">
                                                Affected students: <?php echo (int) ($integritySummary['affected_students'] ?? 0); ?>
                                            </span>
                                            <span class="badge bg-light text-dark border">
                                                Extra active rows: <?php echo (int) ($integritySummary['extra_active_rows'] ?? 0); ?>
                                            </span>
                                        </div>
                                    </div>

                                    <?php if ($integrityError !== ''): ?>
                                        <div class="alert alert-danger mb-0"><?php echo htmlspecialchars($integrityError); ?></div>
                                    <?php elseif (count($integrityRows) === 0): ?>
                                        <div class="alert alert-success mb-0">
                                            No duplicate active enrollment groups found.
                                        </div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-sm table-striped table-hover align-middle mb-0">
                                                <thead>
                                                    <tr>
                                                        <th>Student</th>
                                                        <th>Subject / Term</th>
                                                        <th>Active Rows</th>
                                                        <th>Details</th>
                                                        <th class="text-end">Action</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($integrityDisplayRows as $row): ?>
                                                        <?php
                                                        $studentId = (int) ($row['student_id'] ?? 0);
                                                        $studentNo = trim((string) ($row['student_no'] ?? ''));
                                                        $name = trim(
                                                            (string) ($row['surname'] ?? '') . ', ' .
                                                            (string) ($row['firstname'] ?? '') . ' ' .
                                                            (string) ($row['middlename'] ?? '')
                                                        );
                                                        if ($name === '' && $studentNo !== '') $name = $studentNo;

                                                        $subjectCode = trim((string) ($row['subject_code'] ?? ''));
                                                        $subjectName = trim((string) ($row['subject_name'] ?? ''));
                                                        $academicYear = trim((string) ($row['academic_year'] ?? ''));
                                                        $semester = trim((string) ($row['semester'] ?? ''));
                                                        $details = trim((string) ($row['duplicate_details'] ?? ''));
                                                        $dupCount = (int) ($row['duplicate_count'] ?? 0);
                                                        ?>
                                                        <tr>
                                                            <td>
                                                                <div class="fw-semibold"><?php echo htmlspecialchars($name); ?></div>
                                                                <div class="text-muted small"><?php echo htmlspecialchars($studentNo !== '' ? $studentNo : 'No student no'); ?></div>
                                                            </td>
                                                            <td class="small">
                                                                <div class="fw-semibold">
                                                                    <?php echo htmlspecialchars($subjectName !== '' ? $subjectName : 'Unknown Subject'); ?>
                                                                    <?php if ($subjectCode !== ''): ?>
                                                                        <span class="text-muted">(<?php echo htmlspecialchars($subjectCode); ?>)</span>
                                                                    <?php endif; ?>
                                                                </div>
                                                                <div class="text-muted">
                                                                    <?php echo htmlspecialchars($academicYear !== '' ? $academicYear : 'N/A'); ?> |
                                                                    <?php echo htmlspecialchars($semester !== '' ? $semester : 'N/A'); ?>
                                                                </div>
                                                            </td>
                                                            <td>
                                                                <span class="badge bg-warning text-dark"><?php echo (int) $dupCount; ?></span>
                                                            </td>
                                                            <td class="small text-muted"><?php echo htmlspecialchars($details !== '' ? $details : 'N/A'); ?></td>
                                                            <td class="text-end">
                                                                <?php if ($studentId > 0): ?>
                                                                    <a class="btn btn-sm btn-outline-primary" href="admin-student-enrollment-details.php?student_id=<?php echo (int) $studentId; ?>">
                                                                        Open Student
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

                                        <?php if (count($integrityRows) > count($integrityDisplayRows)): ?>
                                            <p class="text-muted small mt-2 mb-0">
                                                Showing first <?php echo (int) count($integrityDisplayRows); ?> duplicate groups.
                                            </p>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                </div> <!-- container -->

            </div> <!-- content -->

            <?php include '../layouts/footer.php'; ?>

        </div>

        <!-- ============================================================== -->
        <!-- End Page content -->
        <!-- ============================================================== -->

    </div>
    <!-- END wrapper -->

    <?php include '../layouts/right-sidebar.php'; ?>
    <?php include '../layouts/footer-scripts.php'; ?>

    <!-- App js -->
    <script src="assets/js/app.min.js"></script>

</body>

</html>
