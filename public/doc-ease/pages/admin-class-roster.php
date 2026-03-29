<?php include '../layouts/session.php'; ?>
<?php require_role('admin'); ?>

<?php
$flash = isset($_SESSION['flash_message']) ? (string) $_SESSION['flash_message'] : '';
$flashType = isset($_SESSION['flash_type']) ? (string) $_SESSION['flash_type'] : 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

$adminIsSuperadmin = current_user_is_superadmin();
$adminCampusId = current_user_campus_id();
if (!$adminIsSuperadmin && $adminCampusId <= 0) {
    deny_access(403, 'Campus admin account has no campus assignment.');
}

if (!function_exists('admin_roster_class_accessible')) {
    function admin_roster_class_accessible(mysqli $conn, $classRecordId, $isSuperadmin, $campusId) {
        $classRecordId = (int) $classRecordId;
        $isSuperadmin = (bool) $isSuperadmin;
        $campusId = (int) $campusId;
        if ($classRecordId <= 0) return false;

        if ($isSuperadmin) {
            $stmt = $conn->prepare("SELECT 1 FROM class_records WHERE id = ? LIMIT 1");
            if (!$stmt) return false;
            $stmt->bind_param('i', $classRecordId);
        } else {
            if ($campusId <= 0) return false;
            $stmt = $conn->prepare(
                "SELECT 1
                 FROM class_records cr
                 WHERE cr.id = ?
                   AND (
                        EXISTS(
                            SELECT 1
                            FROM teacher_assignments ta
                            JOIN users u_ta ON u_ta.id = ta.teacher_id
                            WHERE ta.class_record_id = cr.id
                              AND ta.status = 'active'
                              AND u_ta.campus_id = ?
                        )
                        OR EXISTS(
                            SELECT 1
                            FROM users u_cr
                            WHERE u_cr.id = cr.teacher_id
                              AND u_cr.campus_id = ?
                        )
                        OR EXISTS(
                            SELECT 1
                            FROM users u_cb
                            WHERE u_cb.id = cr.created_by
                              AND u_cb.campus_id = ?
                        )
                   )
                 LIMIT 1"
            );
            if (!$stmt) return false;
            $stmt->bind_param('iiii', $classRecordId, $campusId, $campusId, $campusId);
        }

        $stmt->execute();
        $res = $stmt->get_result();
        $ok = ($res && $res->num_rows === 1);
        $stmt->close();
        return $ok;
    }
}

$classRecordId = isset($_GET['class_record_id']) ? (int) $_GET['class_record_id'] : 0;
if ($classRecordId <= 0) {
    $_SESSION['flash_message'] = 'Invalid class record.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: admin-assign-teachers.php');
    exit;
}
if (!admin_roster_class_accessible($conn, $classRecordId, $adminIsSuperadmin, $adminCampusId)) {
    $_SESSION['flash_message'] = 'Class record is not available for your campus scope.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: admin-assign-teachers.php');
    exit;
}

// Roster filter (default: only currently enrolled students).
$show = isset($_GET['show']) ? strtolower(trim((string) $_GET['show'])) : '';
$showAll = ($show === 'all');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    if (!csrf_validate($csrf)) {
        $_SESSION['flash_message'] = 'Security check failed (CSRF). Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: admin-class-roster.php?class_record_id=' . $classRecordId . ($showAll ? '&show=all' : ''));
        exit;
    }

    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';
    if ($action === 'drop_student') {
        $enrollmentId = isset($_POST['enrollment_id']) ? (int) $_POST['enrollment_id'] : 0;
        if ($enrollmentId <= 0) {
            $_SESSION['flash_message'] = 'Invalid enrollment.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: admin-class-roster.php?class_record_id=' . $classRecordId . ($showAll ? '&show=all' : ''));
            exit;
        }

        // Load context needed to update both class_enrollments + enrollments (queue table).
        $ctxStmt = $conn->prepare(
            "SELECT ce.id AS enrollment_id, ce.student_id,
                    st.StudentNo AS student_no,
                    cr.subject_id, cr.academic_year, cr.semester, cr.section
             FROM class_enrollments ce
             JOIN class_records cr ON cr.id = ce.class_record_id
             JOIN students st ON st.id = ce.student_id
             WHERE ce.id = ? AND ce.class_record_id = ?
             LIMIT 1"
        );
        $dropCtx = null;
        if ($ctxStmt) {
            $ctxStmt->bind_param('ii', $enrollmentId, $classRecordId);
            $ctxStmt->execute();
            $res = $ctxStmt->get_result();
            if ($res && $res->num_rows === 1) $dropCtx = $res->fetch_assoc();
            $ctxStmt->close();
        }

        if (!$dropCtx) {
            $_SESSION['flash_message'] = 'Enrollment not found for this class.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: admin-class-roster.php?class_record_id=' . $classRecordId . ($showAll ? '&show=all' : ''));
            exit;
        }

        $conn->begin_transaction();
        try {
            // Soft-remove from roster: set status to dropped.
            $drop = $conn->prepare("UPDATE class_enrollments SET status = 'dropped' WHERE id = ? AND class_record_id = ?");
            $drop->bind_param('ii', $enrollmentId, $classRecordId);
            $drop->execute();
            $affected = $drop->affected_rows;
            $drop->close();

            if ($affected !== 1) {
                throw new Exception('No changes made. (Already removed?)');
            }

            // Keep enrollments table in sync (if a row exists).
            $studentNo = (string) ($dropCtx['student_no'] ?? '');
            $subjectId = (int) ($dropCtx['subject_id'] ?? 0);
            $academicYear = (string) ($dropCtx['academic_year'] ?? '');
            $semester = (string) ($dropCtx['semester'] ?? '');
            $section = (string) ($dropCtx['section'] ?? '');

            if ($studentNo !== '' && $subjectId > 0 && $academicYear !== '' && $semester !== '' && $section !== '') {
                $updE = $conn->prepare(
                    "UPDATE enrollments
                     SET status = 'Dropped', section = ?
                     WHERE student_no = ? AND subject_id = ? AND academic_year = ? AND semester = ?"
                );
                $updE->bind_param('ssiss', $section, $studentNo, $subjectId, $academicYear, $semester);
                $updE->execute();
                $updE->close();
            }

            $conn->commit();
            $_SESSION['flash_message'] = 'Student removed from the roster.';
            $_SESSION['flash_type'] = 'success';
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('[admin-class-roster] drop_student failed: ' . $e->getMessage());
            $_SESSION['flash_message'] = 'Remove failed.';
            $_SESSION['flash_type'] = 'danger';
        }

        header('Location: admin-class-roster.php?class_record_id=' . $classRecordId . ($showAll ? '&show=all' : ''));
        exit;
    }
}

// Load class context.
$ctx = null;
$stmt = $conn->prepare(
    "SELECT cr.id AS class_record_id, cr.status AS class_status,
            cr.section, cr.academic_year, cr.semester,
            s.subject_code, s.subject_name,
            u.username AS primary_teacher, u.useremail AS primary_email
     FROM class_records cr
     JOIN subjects s ON s.id = cr.subject_id
     LEFT JOIN users u ON u.id = cr.teacher_id
     WHERE cr.id = ?
     LIMIT 1"
);
if ($stmt) {
    $stmt->bind_param('i', $classRecordId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows === 1) $ctx = $res->fetch_assoc();
    $stmt->close();
}

if (!$ctx) {
    $_SESSION['flash_message'] = 'Class record not found.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: admin-assign-teachers.php');
    exit;
}

// Load roster.
$students = [];
$sqlRoster =
    "SELECT ce.id AS enrollment_id,
            ce.student_id, ce.status AS enrollment_status, ce.enrollment_date,
            st.StudentNo AS student_no,
            st.Surname AS surname, st.FirstName AS firstname, st.MiddleName AS middlename
     FROM class_enrollments ce
     JOIN students st ON st.id = ce.student_id
     WHERE ce.class_record_id = ?";
if (!$showAll) $sqlRoster .= " AND ce.status = 'enrolled'";
$sqlRoster .= " ORDER BY st.Surname ASC, st.FirstName ASC, st.MiddleName ASC, st.StudentNo ASC";

$en = $conn->prepare($sqlRoster);
if ($en) {
    $en->bind_param('i', $classRecordId);
    $en->execute();
    $res = $en->get_result();
    while ($res && ($r = $res->fetch_assoc())) $students[] = $r;
    $en->close();
}

$classStatus = (string) ($ctx['class_status'] ?? 'active');
$isArchived = $classStatus !== 'active';
?>

<?php include '../layouts/main.php'; ?>

<head>
    <title>Class Roster | E-Record</title>
    <?php include '../layouts/title-meta.php'; ?>
    <?php include '../layouts/head-css.php'; ?>
    <style>
        .er-roster-search {
            max-width: 420px;
        }
    </style>
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
                                        <li class="breadcrumb-item"><a href="admin-assign-teachers.php">Assign Teachers</a></li>
                                        <li class="breadcrumb-item active">Class Roster</li>
                                    </ol>
                                </div>
                                <h4 class="page-title">Class Roster</h4>
                            </div>
                        </div>
                    </div>

                    <?php if ($isArchived): ?>
                        <div class="alert alert-warning">
                            This class record is <strong><?php echo htmlspecialchars($classStatus); ?></strong>. Roster is shown for reference.
                        </div>
                    <?php endif; ?>

                    <?php if ($flash): ?>
                        <div class="alert alert-<?php echo htmlspecialchars($flashType); ?>">
                            <?php echo htmlspecialchars($flash); ?>
                        </div>
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex align-items-start justify-content-between flex-wrap gap-2">
                                        <div>
                                            <div class="fw-semibold">
                                                <?php echo htmlspecialchars((string) ($ctx['subject_name'] ?? '')); ?>
                                                <span class="text-muted">(<?php echo htmlspecialchars((string) ($ctx['subject_code'] ?? '')); ?>)</span>
                                            </div>
                                            <div class="text-muted small">
                                                Section: <?php echo htmlspecialchars((string) ($ctx['section'] ?? '')); ?> |
                                                <?php echo htmlspecialchars((string) ($ctx['academic_year'] ?? '')); ?>, <?php echo htmlspecialchars((string) ($ctx['semester'] ?? '')); ?>
                                            </div>
                                            <div class="text-muted small">
                                                Primary teacher: <?php echo htmlspecialchars((string) ($ctx['primary_teacher'] ?? 'N/A')); ?>
                                                <?php if (!empty($ctx['primary_email'])): ?>
                                                    <span class="text-muted">(<?php echo htmlspecialchars((string) ($ctx['primary_email'] ?? '')); ?>)</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="text-end">
                                            <a class="btn btn-sm btn-outline-secondary" href="admin-assign-teachers.php">
                                                <i class="ri-arrow-left-line me-1" aria-hidden="true"></i>
                                                Back
                                            </a>
                                            <a class="btn btn-sm btn-outline-primary ms-1"
                                                href="class-record-print.php?class_record_id=<?php echo (int) $classRecordId; ?>&term=midterm&view=assessments"
                                                title="Print Class Record (A4 landscape)">
                                                <i class="ri-printer-line me-1" aria-hidden="true"></i>
                                                Print
                                            </a>
                                        </div>
                                    </div>

                                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mt-3">
                                        <div class="text-muted small">
                                            Enrolled students: <strong><?php echo (int) count($students); ?></strong>
                                            <?php if ($showAll): ?>
                                                <span class="text-muted">(showing all statuses)</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="d-flex align-items-center gap-2">
                                            <?php if ($showAll): ?>
                                                <a class="btn btn-sm btn-outline-secondary"
                                                    href="admin-class-roster.php?class_record_id=<?php echo (int) $classRecordId; ?>">
                                                    Hide Dropped
                                                </a>
                                            <?php else: ?>
                                                <a class="btn btn-sm btn-outline-secondary"
                                                    href="admin-class-roster.php?class_record_id=<?php echo (int) $classRecordId; ?>&show=all">
                                                    Show All
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                        <div class="input-group input-group-sm er-roster-search">
                                            <span class="input-group-text"><i class="ri-search-line" aria-hidden="true"></i></span>
                                            <input id="erRosterSearch" class="form-control" type="search" placeholder="Search student no/name..." aria-label="Search roster">
                                            <button id="erRosterClear" class="btn btn-outline-secondary" type="button">Clear</button>
                                        </div>
                                    </div>

                                    <div class="table-responsive mt-2">
                                        <table class="table table-sm table-striped table-hover align-middle mb-0" id="erRosterTable">
                                            <thead>
                                                <tr>
                                                    <th style="width: 60px;">#</th>
                                                    <th>Student</th>
                                                    <th style="width: 140px;">Status</th>
                                                    <th style="width: 140px;">Enrolled</th>
                                                    <th class="text-end" style="width: 140px;">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (count($students) === 0): ?>
                                                    <tr>
                                                        <td colspan="5" class="text-center text-muted">No enrolled students found for this class record.</td>
                                                    </tr>
                                                <?php endif; ?>
                                                <?php $i = 1; ?>
                                                <?php foreach ($students as $st): ?>
                                                    <?php $enrollmentId = (int) ($st['enrollment_id'] ?? 0); ?>
                                                    <?php
                                                    $name = trim(
                                                        (string) ($st['surname'] ?? '') . ', ' .
                                                        (string) ($st['firstname'] ?? '') . ' ' .
                                                        (string) ($st['middlename'] ?? '')
                                                    );
                                                    $estatus = (string) ($st['enrollment_status'] ?? 'enrolled');
                                                    $badge = 'secondary';
                                                    if ($estatus === 'enrolled') $badge = 'success';
                                                    if ($estatus === 'dropped') $badge = 'danger';
                                                    if ($estatus === 'completed') $badge = 'info';
                                                    ?>
                                                    <tr>
                                                        <td class="text-muted"><?php echo (int) $i++; ?></td>
                                                        <td>
                                                            <div class="fw-semibold"><?php echo htmlspecialchars($name); ?></div>
                                                            <div class="text-muted small"><?php echo htmlspecialchars((string) ($st['student_no'] ?? '')); ?></div>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-<?php echo htmlspecialchars($badge); ?>">
                                                                <?php echo htmlspecialchars(ucfirst($estatus)); ?>
                                                            </span>
                                                        </td>
                                                        <td class="text-muted small">
                                                            <?php echo htmlspecialchars((string) ($st['enrollment_date'] ?? '')); ?>
                                                        </td>
                                                        <td class="text-end">
                                                            <?php if ($estatus === 'enrolled' && $enrollmentId > 0): ?>
                                                                <form method="post" class="d-inline">
                                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                                                    <input type="hidden" name="action" value="drop_student">
                                                                    <input type="hidden" name="enrollment_id" value="<?php echo (int) $enrollmentId; ?>">
                                                                    <button class="btn btn-sm btn-outline-danger"
                                                                        type="submit"
                                                                        onclick="return confirm('Remove this student from the roster?');">
                                                                        <i class="ri-user-unfollow-line me-1" aria-hidden="true"></i>
                                                                        Remove
                                                                    </button>
                                                                </form>
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

                </div>
            </div>

            <?php include '../layouts/footer.php'; ?>
        </div>
    </div>

    <?php include '../layouts/right-sidebar.php'; ?>
    <?php include '../layouts/footer-scripts.php'; ?>
    <script src="assets/js/app.min.js"></script>
    <script>
        (function () {
            var input = document.getElementById('erRosterSearch');
            var clearBtn = document.getElementById('erRosterClear');
            var table = document.getElementById('erRosterTable');

            function filterRows() {
                if (!input || !table) return;
                var q = (input.value || '').toLowerCase().trim();
                var rows = table.querySelectorAll('tbody tr');
                rows.forEach(function (tr) {
                    if (tr.querySelector('td[colspan]')) return;
                    var text = (tr.textContent || '').toLowerCase();
                    tr.style.display = (q === '' || text.indexOf(q) !== -1) ? '' : 'none';
                });
            }

            if (input) input.addEventListener('input', filterRows);
            if (clearBtn) {
                clearBtn.addEventListener('click', function () {
                    if (!input) return;
                    input.value = '';
                    input.focus();
                    filterRows();
                });
            }
        })();
    </script>
</body>
</html>
