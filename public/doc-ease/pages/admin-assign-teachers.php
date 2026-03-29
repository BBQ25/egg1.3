<?php include '../layouts/session.php'; ?>
<?php require_role('admin'); ?>
<?php include '../layouts/main.php'; ?>
<?php include_once '../includes/reference.php'; ?>

<?php
$flash = isset($_SESSION['flash_message']) ? (string) $_SESSION['flash_message'] : '';
$flashType = isset($_SESSION['flash_type']) ? (string) $_SESSION['flash_type'] : 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

// Load teachers and subjects for assignment.
$teachers = [];
$tRes = $conn->query("SELECT id, username, useremail FROM users WHERE role = 'teacher' AND is_active = 1 ORDER BY username");
if ($tRes) {
    while ($r = $tRes->fetch_assoc()) $teachers[] = $r;
}

$subjects = [];
$sRes = $conn->query("SELECT id, subject_code, subject_name, academic_year, semester FROM subjects WHERE status = 'active' ORDER BY subject_name");
if ($sRes) {
    while ($r = $sRes->fetch_assoc()) $subjects[] = $r;
}

// Reference dropdown values.
// Prefer reference tables (admin-references.php) but keep fallbacks for older datasets.
$academicYears = [];
$resAy = $conn->query("SELECT name FROM academic_years WHERE status = 'active' ORDER BY sort_order ASC, name ASC");
if ($resAy) while ($r = $resAy->fetch_assoc()) $academicYears[] = $r['name'];
$academicYears = array_values(array_unique(array_filter(array_map('trim', $academicYears))));
if (count($academicYears) === 0) {
    $ayFallback = [];
    $ay1 = $conn->query("SELECT DISTINCT academic_year FROM enrollments WHERE academic_year IS NOT NULL AND academic_year <> ''");
    if ($ay1) while ($r = $ay1->fetch_assoc()) $ayFallback[] = $r['academic_year'];
    $ay2 = $conn->query("SELECT DISTINCT academic_year FROM subjects WHERE academic_year IS NOT NULL AND academic_year <> ''");
    if ($ay2) while ($r = $ay2->fetch_assoc()) $ayFallback[] = $r['academic_year'];
    $academicYears = array_values(array_unique(array_filter(array_map('trim', $ayFallback))));
    rsort($academicYears);
}

$semesters = [];
$resSem = $conn->query("SELECT name FROM semesters WHERE status = 'active' ORDER BY sort_order ASC, name ASC");
if ($resSem) while ($r = $resSem->fetch_assoc()) $semesters[] = $r['name'];
$semesters = array_values(array_unique(array_filter(array_map('trim', $semesters))));
if (count($semesters) === 0) {
    $semFallback = [];
    $sm1 = $conn->query("SELECT DISTINCT semester FROM enrollments WHERE semester IS NOT NULL AND semester <> ''");
    if ($sm1) while ($r = $sm1->fetch_assoc()) $semFallback[] = $r['semester'];
    $sm2 = $conn->query("SELECT DISTINCT semester FROM subjects WHERE semester IS NOT NULL AND semester <> ''");
    if ($sm2) while ($r = $sm2->fetch_assoc()) $semFallback[] = $r['semester'];
    $semesters = array_values(array_unique(array_filter(array_map('trim', $semFallback))));
    sort($semesters);
}

$sections = [];
$sectionOptions = [];

// Canonical class section options:
// prefer IF-style codes to avoid ambiguous entries like "A", "B", or typo variants.
$addSectionOption = static function (&$sections, &$sectionOptions, $secValue, $secLabel = '') {
    $secValue = trim((string) $secValue);
    if ($secValue === '') return;
    if (!isset($sections[$secValue])) {
        $sections[$secValue] = true;
        $sectionOptions[] = [
            'value' => $secValue,
            'label' => $secLabel !== '' ? $secLabel : $secValue,
        ];
    }
};

$baseSections = [];
if (function_exists('ref_sync_class_sections_from_records')) {
    ref_sync_class_sections_from_records($conn);
}
if (function_exists('ref_list_class_sections')) {
    $baseSections = ref_list_class_sections($conn, true, true);
}
foreach ($baseSections as $row) {
    $sec = strtoupper(trim((string) ($row['code'] ?? '')));
    if ($sec === '') continue;
    $addSectionOption($sections, $sectionOptions, $sec, $sec);
}

if (count($sectionOptions) === 0) {
    // Last-resort fallback for legacy datasets before section split migration.
    $resSections = $conn->query(
        "SELECT DISTINCT section
         FROM class_records
         WHERE subject_id IS NOT NULL
           AND status = 'active'
           AND section IS NOT NULL
           AND section <> ''
         ORDER BY section ASC"
    );
    if ($resSections) {
        while ($r = $resSections->fetch_assoc()) {
            $sec = strtoupper(trim((string) ($r['section'] ?? '')));
            if ($sec === '') continue;
            if (function_exists('ref_is_if_section') && !ref_is_if_section($sec)) continue;
            $addSectionOption($sections, $sectionOptions, $sec, $sec);
        }
    }
}

$sections = array_keys($sections);
sort($sections);
usort($sectionOptions, static function ($a, $b) {
    $aLabel = strtolower((string) ($a['label'] ?? ''));
    $bLabel = strtolower((string) ($b['label'] ?? ''));
    return $aLabel <=> $bLabel;
});

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? (string) $_POST['action'] : 'assign';
    $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';

    if (!csrf_validate($csrf)) {
        $_SESSION['flash_message'] = 'Security check failed (CSRF). Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: admin-assign-teachers.php');
        exit;
    }

    if ($action === 'revoke') {
        $assignmentId = isset($_POST['assignment_id']) ? (int) $_POST['assignment_id'] : 0;
        if ($assignmentId <= 0) {
            $_SESSION['flash_message'] = 'Invalid assignment.';
            $_SESSION['flash_type'] = 'danger';
        } else {
            // Assignments are only editable if there are no enrolled students in the class record yet.
            $classRecordId = 0;
            $findCr = $conn->prepare("SELECT class_record_id FROM teacher_assignments WHERE id = ? LIMIT 1");
            if ($findCr) {
                $findCr->bind_param('i', $assignmentId);
                $findCr->execute();
                $r = $findCr->get_result();
                if ($r && $r->num_rows === 1) {
                    $classRecordId = (int) ($r->fetch_assoc()['class_record_id'] ?? 0);
                }
                $findCr->close();
            }

            $hasStudents = false;
            if ($classRecordId > 0) {
                $chk = $conn->prepare("SELECT 1 FROM class_enrollments WHERE class_record_id = ? LIMIT 1");
                if ($chk) {
                    $chk->bind_param('i', $classRecordId);
                    $chk->execute();
                    $rr = $chk->get_result();
                    $hasStudents = ($rr && $rr->num_rows > 0);
                    $chk->close();
                }
            }

            if ($hasStudents) {
                $_SESSION['flash_message'] = 'This assignment is locked because students are already enrolled. Revoke/edit is only allowed before enrollments exist.';
                $_SESSION['flash_type'] = 'warning';
                header('Location: admin-assign-teachers.php');
                exit;
            }

            $stmt = $conn->prepare("UPDATE teacher_assignments SET status = 'inactive' WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('i', $assignmentId);
                if ($stmt->execute()) {
                    $_SESSION['flash_message'] = 'Assignment revoked.';
                    $_SESSION['flash_type'] = 'success';
                } else {
                    $_SESSION['flash_message'] = 'Revoke failed. Please try again.';
                    $_SESSION['flash_type'] = 'danger';
                }
                $stmt->close();
            } else {
                $_SESSION['flash_message'] = 'Revoke failed. Please try again.';
                $_SESSION['flash_type'] = 'danger';
            }
        }

        header('Location: admin-assign-teachers.php');
        exit;
    }

    $teacherId = isset($_POST['teacher_id']) ? (int) $_POST['teacher_id'] : 0;
    $subjectId = isset($_POST['subject_id']) ? (int) $_POST['subject_id'] : 0;
    $academicYear = isset($_POST['academic_year']) ? trim((string) $_POST['academic_year']) : '';
    $semester = isset($_POST['semester']) ? trim((string) $_POST['semester']) : '';
    $section = isset($_POST['section']) ? trim((string) $_POST['section']) : '';
    $teacherRole = isset($_POST['teacher_role']) ? trim((string) $_POST['teacher_role']) : 'primary';
    $notes = isset($_POST['assignment_notes']) ? trim((string) $_POST['assignment_notes']) : '';

    if (!in_array($teacherRole, ['primary', 'co_teacher'], true)) $teacherRole = 'primary';

    if (function_exists('ref_section_lookup_hint')) {
        $section = ref_section_lookup_hint($section);
    }

    // If dropdowns are populated, require posted values to be in the reference lists.
    // This keeps the assign flow consistent with the Admin References page.
    if ($academicYear !== '' && count($academicYears) > 0 && !in_array($academicYear, $academicYears, true)) $academicYear = '';
    if ($semester !== '' && count($semesters) > 0 && !in_array($semester, $semesters, true)) $semester = '';
    if ($section !== '' && count($sections) > 0 && !in_array($section, $sections, true)) $section = '';

    if ($teacherId <= 0 || $subjectId <= 0 || $academicYear === '' || $semester === '' || $section === '') {
        $_SESSION['flash_message'] = 'Teacher, Subject, Academic Year, Semester, and Section are required.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: admin-assign-teachers.php');
        exit;
    }

    // Validate teacher + subject exist.
    $chkT = $conn->prepare("SELECT id FROM users WHERE id = ? AND role = 'teacher' LIMIT 1");
    $chkS = $conn->prepare("SELECT id FROM subjects WHERE id = ? AND status = 'active' LIMIT 1");
    if (!$chkT || !$chkS) {
        $_SESSION['flash_message'] = 'Unable to validate teacher/subject.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: admin-assign-teachers.php');
        exit;
    }
    $chkT->bind_param('i', $teacherId);
    $chkT->execute();
    $tr = $chkT->get_result();
    $chkT->close();
    $chkS->bind_param('i', $subjectId);
    $chkS->execute();
    $sr = $chkS->get_result();
    $chkS->close();
    if (!$tr || $tr->num_rows !== 1 || !$sr || $sr->num_rows !== 1) {
        $_SESSION['flash_message'] = 'Teacher or subject not found.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: admin-assign-teachers.php');
        exit;
    }

    $adminId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;

    $conn->begin_transaction();
    try {
        // 1) Ensure class record exists.
        $classRecordId = null;
        $existingTeacherId = null;

        if (function_exists('ref_resolve_class_record_target')) {
            $resolved = ref_resolve_class_record_target($conn, $subjectId, $academicYear, $semester, $section);
            $resolvedClassId = (int) ($resolved['class_record_id'] ?? 0);
            if ($resolvedClassId > 0) {
                $classRecordId = $resolvedClassId;
                $section = trim((string) ($resolved['section'] ?? $section));
            }
        }

        if (!$classRecordId) {
            $find = $conn->prepare("SELECT id, teacher_id FROM class_records WHERE subject_id = ? AND section = ? AND academic_year = ? AND semester = ? AND status = 'active' LIMIT 1");
            $find->bind_param('isss', $subjectId, $section, $academicYear, $semester);
            $find->execute();
            $res = $find->get_result();
            if ($res && $res->num_rows === 1) {
                $row = $res->fetch_assoc();
                $classRecordId = (int) $row['id'];
                $existingTeacherId = isset($row['teacher_id']) ? (int) $row['teacher_id'] : null;
            }
            $find->close();
        }

        if (!$classRecordId) {
            $sectionHint = function_exists('ref_section_lookup_hint') ? strtoupper(ref_section_lookup_hint($section)) : strtoupper($section);
            $looksAmbiguousSectionCode =
                preg_match('/^[A-Z]$/', $sectionHint) === 1 ||
                preg_match('/^[1-4][A-Z]$/', $sectionHint) === 1;
            if ($looksAmbiguousSectionCode) {
                throw new RuntimeException(
                    'Section code ' . $section .
                    ' is ambiguous. Use a full class section code (for example IF-2-B-6) in the assignment form.'
                );
            }
        }

        // Assignments are only editable if there are no enrolled students in the class record yet.
        if ($classRecordId) {
            $chk = $conn->prepare("SELECT 1 FROM class_enrollments WHERE class_record_id = ? LIMIT 1");
            $hasStudents = false;
            if ($chk) {
                $chk->bind_param('i', $classRecordId);
                $chk->execute();
                $rr = $chk->get_result();
                $hasStudents = ($rr && $rr->num_rows > 0);
                $chk->close();
            }

            if ($hasStudents) {
                throw new Exception('Assignment locked: students are already enrolled in this class record.');
            }
        }

        if (!$classRecordId) {
            $teacherForRecord = $teacherId;
            $ins = $conn->prepare("INSERT INTO class_records (subject_id, teacher_id, record_type, section, academic_year, semester, created_by, status) VALUES (?, ?, 'assigned', ?, ?, ?, ?, 'active')");
            $ins->bind_param('iisssi', $subjectId, $teacherForRecord, $section, $academicYear, $semester, $adminId);
            $ins->execute();
            $classRecordId = (int) $conn->insert_id;
            $ins->close();
        } else {
            // If admin assigns a primary teacher, reflect it on the class record too (keeps teacher tools consistent).
            if ($teacherRole === 'primary' && $existingTeacherId !== $teacherId) {
                $upd = $conn->prepare("UPDATE class_records SET teacher_id = ?, record_type = 'assigned' WHERE id = ?");
                $upd->bind_param('ii', $teacherId, $classRecordId);
                $upd->execute();
                $upd->close();
            }
        }

        // 2) Upsert teacher assignment.
        $ta = $conn->prepare("SELECT id FROM teacher_assignments WHERE teacher_id = ? AND class_record_id = ? LIMIT 1");
        $ta->bind_param('ii', $teacherId, $classRecordId);
        $ta->execute();
        $taRes = $ta->get_result();
        $existingTaId = null;
        if ($taRes && $taRes->num_rows === 1) {
            $existingTaId = (int) $taRes->fetch_assoc()['id'];
        }
        $ta->close();

        if ($existingTaId) {
            $updTa = $conn->prepare("UPDATE teacher_assignments SET teacher_role = ?, assigned_by = ?, status = 'active', assignment_notes = ?, assigned_at = CURRENT_TIMESTAMP WHERE id = ?");
            $updTa->bind_param('sisi', $teacherRole, $adminId, $notes, $existingTaId);
            $updTa->execute();
            $updTa->close();
        } else {
            $insTa = $conn->prepare("INSERT INTO teacher_assignments (teacher_id, teacher_role, class_record_id, assigned_by, status, assignment_notes) VALUES (?, ?, ?, ?, 'active', ?)");
            $insTa->bind_param('isiis', $teacherId, $teacherRole, $classRecordId, $adminId, $notes);
            $insTa->execute();
            $insTa->close();
        }

        $conn->commit();
        $_SESSION['flash_message'] = 'Teacher assigned successfully.';
        $_SESSION['flash_type'] = 'success';
    } catch (Throwable $e) {
        $conn->rollback();
        // Avoid showing raw SQL/stack details in UI. Keep details in server logs.
        error_log('[admin-assign-teachers] assignment failed: ' . $e->getMessage());
        $msg = (string) $e->getMessage();
        if (stripos($msg, 'Assignment locked') !== false) {
            $_SESSION['flash_message'] = $msg;
            $_SESSION['flash_type'] = 'warning';
        } else {
            $_SESSION['flash_message'] = 'Assignment failed. Please try again.';
            $_SESSION['flash_type'] = 'danger';
        }
    }

    header('Location: admin-assign-teachers.php');
    exit;
}

// List assignments.
$assignments = [];
$list = $conn->query(
    "SELECT ta.id AS assignment_id, ta.teacher_role, ta.status, ta.assigned_at,
            u.username AS teacher_name, u.useremail AS teacher_email,
            s.subject_code, s.subject_name,
            cr.id AS class_record_id,
            cr.section, cr.academic_year, cr.semester,
            EXISTS(SELECT 1 FROM class_enrollments ce WHERE ce.class_record_id = cr.id LIMIT 1) AS has_students
     FROM teacher_assignments ta
     JOIN users u ON u.id = ta.teacher_id
     JOIN class_records cr ON cr.id = ta.class_record_id
     JOIN subjects s ON s.id = cr.subject_id
     WHERE cr.status = 'active'
     ORDER BY COALESCE(ta.assigned_at, ta.created_at) DESC, ta.id DESC
     LIMIT 250"
);
if ($list) {
    while ($r = $list->fetch_assoc()) $assignments[] = $r;
}

$activeAssignments = 0;
foreach ($assignments as $a) {
    if (((string) ($a['status'] ?? '')) === 'active') $activeAssignments++;
}
?>

<head>
    <title>Assign Teachers | E-Record</title>
    <?php include '../layouts/title-meta.php'; ?>
    <?php include '../layouts/head-css.php'; ?>
    <link rel="stylesheet" href="assets/css/admin-ops-ui.css">
    <style>
        .assign-hero {
            background: linear-gradient(140deg, #10263f 0%, #15506e 55%, #14846d 100%);
        }

        .assign-hero::after {
            content: "";
            position: absolute;
            inset: 0;
            background: repeating-linear-gradient(
                135deg,
                rgba(255, 255, 255, 0.06) 0px,
                rgba(255, 255, 255, 0.06) 1px,
                rgba(255, 255, 255, 0) 9px,
                rgba(255, 255, 255, 0) 18px
            );
            opacity: 0.34;
            pointer-events: none;
        }

        .assign-table-actions {
            white-space: nowrap;
        }

        .assign-stack .card + .card {
            margin-top: 0;
        }

        .assign-new-form .form-row-tight {
            margin-bottom: 0.75rem;
        }

        .assign-new-form .form-row-tight .form-label {
            margin-bottom: 0.3rem;
        }

        .assign-new-form .form-grid-tight {
            --bs-gutter-x: 0.75rem;
            --bs-gutter-y: 0.5rem;
        }

        .assign-new-form .form-actions {
            margin-top: 0.9rem;
        }

        .assign-current-card .ops-toolbar {
            margin-bottom: 0.6rem;
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
                                        <li class="breadcrumb-item active">Assign Teachers</li>
                                    </ol>
                                </div>
                                <h4 class="page-title">Assign Teachers</h4>
                            </div>
                        </div>
                    </div>

                    <div class="ops-hero assign-hero ops-page-shell" data-ops-parallax>
                        <div class="ops-hero__bg" data-ops-parallax-layer aria-hidden="true"></div>
                        <div class="ops-hero__content">
                            <div class="d-flex align-items-end justify-content-between flex-wrap gap-2">
                                <div>
                                    <div class="ops-hero__kicker">Administration</div>
                                    <h1 class="ops-hero__title h3">Assign Teachers</h1>
                                    <div class="ops-hero__subtitle">
                                        Create primary and co-teacher assignments per subject, section, and term.
                                    </div>
                                </div>
                                <div class="ops-hero__chips">
                                    <div class="ops-chip">
                                        <span class="text-white-50">Teachers</span>
                                        <strong><?php echo (int) count($teachers); ?></strong>
                                    </div>
                                    <div class="ops-chip">
                                        <span class="text-white-50">Subjects</span>
                                        <strong><?php echo (int) count($subjects); ?></strong>
                                    </div>
                                    <div class="ops-chip">
                                        <span class="text-white-50">Active</span>
                                        <strong><?php echo (int) $activeAssignments; ?></strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($flash): ?>
                        <div class="alert alert-<?php echo htmlspecialchars($flashType); ?>"><?php echo htmlspecialchars($flash); ?></div>
                    <?php endif; ?>

                    <div class="row g-3 assign-stack">
                        <div class="col-12">
                            <div class="card ops-card ops-page-shell assign-new-card">
                                <div class="card-body">
                                    <h4 class="header-title">New Assignment</h4>
                                    <p class="text-muted">Assign a teacher to a subject for a specific term and section.</p>

                                    <?php if (count($teachers) === 0): ?>
                                        <div class="alert alert-warning mb-0">
                                            No active teacher accounts found. Approve/create a teacher account first in Teacher Accounts.
                                        </div>
                                    <?php else: ?>
                                        <form method="post" class="assign-new-form">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                            <input type="hidden" name="action" value="assign">

                                            <div class="alert alert-light border py-2 px-3 mb-3 small">
                                                Hierarchy: 1 Academic Year -> 2 Semester -> 3 Subject -> 4 Section -> 5 Teacher -> Assign
                                            </div>

                                            <div class="row form-grid-tight">
                                                <div class="col-md-6">
                                                    <label class="form-label">1) Academic Year</label>
                                                    <select class="form-select" name="academic_year" id="assignAcademicYearSelect" required>
                                                        <option value="">Select</option>
                                                        <?php if (count($academicYears) === 0): ?>
                                                            <option value="" disabled>No academic years found (add in References).</option>
                                                        <?php endif; ?>
                                                        <?php foreach ($academicYears as $ay): ?>
                                                            <option value="<?php echo htmlspecialchars((string) $ay); ?>">
                                                                <?php echo htmlspecialchars((string) $ay); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <?php if (count($academicYears) === 0): ?>
                                                        <div class="ops-inline-help">
                                                            Go to <a href="admin-references.php#academic-years">References</a> to add Academic Years.
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">2) Semester</label>
                                                    <select class="form-select" name="semester" id="assignSemesterSelect" required>
                                                        <option value="">Select</option>
                                                        <?php if (count($semesters) === 0): ?>
                                                            <option value="" disabled>No semesters found (add in References).</option>
                                                        <?php endif; ?>
                                                        <?php foreach ($semesters as $sem): ?>
                                                            <option value="<?php echo htmlspecialchars((string) $sem); ?>">
                                                                <?php echo htmlspecialchars((string) $sem); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <?php if (count($semesters) === 0): ?>
                                                        <div class="ops-inline-help">
                                                            Go to <a href="admin-references.php#semesters">References</a> to add Semesters.
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>

                                            <div class="row form-grid-tight mt-0">
                                                <div class="col-md-6">
                                                    <label class="form-label">3) Subject</label>
                                                    <select class="form-select" name="subject_id" id="assignSubjectSelect" required>
                                                        <option value="">Select</option>
                                                        <?php foreach ($subjects as $s): ?>
                                                            <option
                                                                value="<?php echo (int) $s['id']; ?>"
                                                                data-academic-year="<?php echo htmlspecialchars((string) ($s['academic_year'] ?? ''), ENT_QUOTES); ?>"
                                                                data-semester="<?php echo htmlspecialchars((string) ($s['semester'] ?? ''), ENT_QUOTES); ?>"
                                                            >
                                                                <?php echo htmlspecialchars(($s['subject_name'] ?? '') . ' (' . ($s['subject_code'] ?? '') . ')'); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">4) Section</label>
                                                    <select class="form-select" name="section" id="assignSectionSelect" required>
                                                        <option value="">Select</option>
                                                        <?php if (count($sectionOptions) === 0): ?>
                                                            <option value="" disabled>No sectioning found (populate Students or Sections records).</option>
                                                        <?php endif; ?>
                                                        <?php foreach ($sectionOptions as $sec): ?>
                                                            <option value="<?php echo htmlspecialchars((string) ($sec['value'] ?? '')); ?>">
                                                                <?php echo htmlspecialchars((string) ($sec['label'] ?? '')); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>

                                            <div class="row form-grid-tight mt-0">
                                                <div class="col-md-6">
                                                    <label class="form-label">5) Teacher</label>
                                                    <select class="form-select" name="teacher_id" id="assignTeacherSelect" required>
                                                        <option value="">Select</option>
                                                        <?php foreach ($teachers as $t): ?>
                                                            <option value="<?php echo (int) $t['id']; ?>">
                                                                <?php echo htmlspecialchars(($t['username'] ?? '') . ' (' . ($t['useremail'] ?? '') . ')'); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">Role</label>
                                                    <select class="form-select" name="teacher_role" id="assignRoleSelect">
                                                        <option value="primary">Primary</option>
                                                        <option value="co_teacher">Co-Teacher</option>
                                                    </select>
                                                </div>
                                            </div>

                                            <div class="form-row-tight mt-2">
                                                <label class="form-label">Notes (optional)</label>
                                                <textarea class="form-control" rows="2" name="assignment_notes" placeholder="Notes for this assignment (optional)"></textarea>
                                            </div>

                                            <div class="form-actions">
                                                <button class="btn btn-primary" type="submit" id="assignSubmitBtn">
                                                    <i class="ri-user-add-line me-1" aria-hidden="true"></i>
                                                    Assign
                                                </button>
                                            </div>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="card ops-card ops-page-shell assign-current-card">
                                <div class="card-body">
                                    <div class="ops-toolbar">
                                        <h4 class="header-title mb-0">Current Assignments</h4>
                                        <div class="input-group input-group-sm ops-search">
                                            <span class="input-group-text">
                                                <i class="ri-search-line" aria-hidden="true"></i>
                                            </span>
                                            <input id="erAssignSearch" class="form-control" type="search" placeholder="Search teacher, subject, section..." aria-label="Search assignments">
                                            <button id="erAssignClear" class="btn btn-outline-secondary" type="button">Clear</button>
                                        </div>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-striped table-hover align-middle mb-0 ops-table" id="erAssignTable">
                                            <thead>
                                                <tr>
                                                    <th>Teacher</th>
                                                    <th>Subject</th>
                                                    <th>Term</th>
                                                    <th>Role</th>
                                                    <th>Status</th>
                                                    <th class="text-end">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (count($assignments) === 0): ?>
                                                    <tr>
                                                        <td colspan="6" class="text-center text-muted">No assignments yet.</td>
                                                    </tr>
                                                <?php endif; ?>
                                                <?php foreach ($assignments as $a): ?>
                                                    <?php $isActive = ((string) ($a['status'] ?? '')) === 'active'; ?>
                                                    <?php $hasStudents = !empty($a['has_students']); ?>
                                                    <tr>
                                                        <td>
                                                            <div class="fw-semibold"><?php echo htmlspecialchars((string) ($a['teacher_name'] ?? '')); ?></div>
                                                            <div class="text-muted small"><?php echo htmlspecialchars((string) ($a['teacher_email'] ?? '')); ?></div>
                                                        </td>
                                                        <td>
                                                            <div class="d-flex flex-wrap gap-1">
                                                                <?php if (((string) ($a['subject_name'] ?? '')) !== ''): ?>
                                                                    <span class="badge bg-primary">
                                                                        <?php echo htmlspecialchars((string) ($a['subject_name'] ?? '')); ?>
                                                                    </span>
                                                                <?php endif; ?>

                                                                <?php if (((string) ($a['subject_code'] ?? '')) !== ''): ?>
                                                                    <span class="badge bg-light text-dark border">
                                                                        <?php echo htmlspecialchars((string) ($a['subject_code'] ?? '')); ?>
                                                                    </span>
                                                                <?php endif; ?>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <div class="fw-semibold"><?php echo htmlspecialchars((string) ($a['section'] ?? '')); ?></div>
                                                            <div class="text-muted small">
                                                                <?php echo htmlspecialchars((string) ($a['academic_year'] ?? '')); ?>,
                                                                <?php echo htmlspecialchars((string) ($a['semester'] ?? '')); ?>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <?php $role = (string) ($a['teacher_role'] ?? ''); ?>
                                                            <?php if ($role === 'primary'): ?>
                                                                <span class="badge bg-dark">Primary</span>
                                                            <?php elseif ($role === 'co_teacher'): ?>
                                                                <span class="badge bg-info text-dark">Co-Teacher</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($role); ?></span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php if ($isActive): ?>
                                                                <span class="badge bg-success">Active</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-secondary">Inactive</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="text-end assign-table-actions">
                                                            <?php $crId = (int) ($a['class_record_id'] ?? 0); ?>
                                                            <span class="ops-actions justify-content-end">
                                                            <?php if ($crId > 0): ?>
                                                                <a class="btn btn-sm btn-outline-secondary"
                                                                    href="admin-class-roster.php?class_record_id=<?php echo (int) $crId; ?>"
                                                                    title="View roster">
                                                                    <i class="ri-team-line me-1" aria-hidden="true"></i>
                                                                    Roster
                                                                </a>
                                                            <?php endif; ?>

                                                            <?php if ($isActive && !$hasStudents): ?>
                                                                <form method="post" class="d-inline">
                                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                                                    <input type="hidden" name="action" value="revoke">
                                                                    <input type="hidden" name="assignment_id" value="<?php echo (int) ($a['assignment_id'] ?? 0); ?>">
                                                                    <button class="btn btn-sm btn-outline-danger" type="submit" onclick="return confirm('Revoke this assignment?');">
                                                                        <i class="ri-forbid-2-line me-1" aria-hidden="true"></i>
                                                                        Revoke
                                                                    </button>
                                                                </form>
                                                            <?php elseif ($isActive && $hasStudents): ?>
                                                                <span class="badge bg-warning text-dark" title="Locked: students already enrolled">Locked</span>
                                                            <?php else: ?>
                                                                <span class="text-muted small">Revoked</span>
                                                            <?php endif; ?>
                                                            </span>
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
    <script src="assets/js/admin-ops-ui.js"></script>
    <script>
        (function () {
            // Quick filter for Current Assignments table.
            var input = document.getElementById('erAssignSearch');
            var clearBtn = document.getElementById('erAssignClear');
            var table = document.getElementById('erAssignTable');
            var assignSubjectSelect = document.getElementById('assignSubjectSelect');
            var assignAcademicYearSelect = document.getElementById('assignAcademicYearSelect');
            var assignSemesterSelect = document.getElementById('assignSemesterSelect');
            var assignSectionSelect = document.getElementById('assignSectionSelect');
            var assignTeacherSelect = document.getElementById('assignTeacherSelect');
            var assignRoleSelect = document.getElementById('assignRoleSelect');
            var assignSubmitBtn = document.getElementById('assignSubmitBtn');

            function normalize(value) {
                return String(value || '').trim().toLowerCase();
            }

            function rebuildAssignSubjectOptions() {
                if (!assignSubjectSelect || !assignAcademicYearSelect || !assignSemesterSelect) return;

                var ay = normalize(assignAcademicYearSelect.value);
                var sem = normalize(assignSemesterSelect.value);
                var selectedValue = String(assignSubjectSelect.value || '');
                var canShowSubjects = ay !== '' && sem !== '';

                Array.from(assignSubjectSelect.options).forEach(function (opt, idx) {
                    if (idx === 0) {
                        opt.hidden = false;
                        opt.disabled = false;
                        return;
                    }
                    var subjectAy = normalize(opt.getAttribute('data-academic-year'));
                    var subjectSem = normalize(opt.getAttribute('data-semester'));
                    var ayMatch = subjectAy === '' || subjectAy === ay;
                    var semMatch = subjectSem === '' || subjectSem === sem;
                    var isVisible = ayMatch && semMatch;
                    if (!canShowSubjects) isVisible = false;

                    opt.hidden = !isVisible;
                    opt.disabled = !isVisible;
                });

                var chosen = assignSubjectSelect.options[assignSubjectSelect.selectedIndex];
                if (!chosen || chosen.disabled) assignSubjectSelect.value = '';
                if (selectedValue !== '' && String(assignSubjectSelect.value || '') === '') assignSubjectSelect.value = '';
            }

            function applyAssignHierarchy() {
                var ay = normalize(assignAcademicYearSelect ? assignAcademicYearSelect.value : '');
                var sem = normalize(assignSemesterSelect ? assignSemesterSelect.value : '');
                var subject = normalize(assignSubjectSelect ? assignSubjectSelect.value : '');
                var section = normalize(assignSectionSelect ? assignSectionSelect.value : '');
                var teacher = normalize(assignTeacherSelect ? assignTeacherSelect.value : '');

                var hasAy = ay !== '';
                var hasSem = sem !== '';
                var hasSubject = subject !== '';
                var hasSection = section !== '';
                var hasTeacher = teacher !== '';

                if (assignSemesterSelect) {
                    assignSemesterSelect.disabled = !hasAy;
                    if (!hasAy) assignSemesterSelect.value = '';
                }
                if (!hasAy) hasSem = false;

                if (assignSubjectSelect) {
                    assignSubjectSelect.disabled = !(hasAy && hasSem);
                    if (assignSubjectSelect.disabled) assignSubjectSelect.value = '';
                }
                hasSubject = normalize(assignSubjectSelect ? assignSubjectSelect.value : '') !== '';

                if (assignSectionSelect) {
                    assignSectionSelect.disabled = !hasSubject;
                    if (assignSectionSelect.disabled) assignSectionSelect.value = '';
                }
                hasSection = normalize(assignSectionSelect ? assignSectionSelect.value : '') !== '';

                if (assignTeacherSelect) {
                    assignTeacherSelect.disabled = !hasSection;
                    if (assignTeacherSelect.disabled) assignTeacherSelect.value = '';
                }
                hasTeacher = normalize(assignTeacherSelect ? assignTeacherSelect.value : '') !== '';

                if (assignRoleSelect) {
                    assignRoleSelect.disabled = !hasTeacher;
                    if (assignRoleSelect.disabled) assignRoleSelect.value = 'primary';
                }

                if (assignSubmitBtn) {
                    assignSubmitBtn.disabled = !(hasAy && hasSem && hasSubject && hasSection && hasTeacher);
                }
            }

            function filterRows() {
                if (!input || !table) return;
                var q = (input.value || '').toLowerCase().trim();
                var rows = table.querySelectorAll('tbody tr');

                rows.forEach(function (tr) {
                    // Keep the "No assignments yet" row visible when it's the only row.
                    if (tr.querySelector('td[colspan]')) return;
                    var text = (tr.textContent || '').toLowerCase();
                    tr.style.display = (q === '' || text.indexOf(q) !== -1) ? '' : 'none';
                });
            }

            if (input) input.addEventListener('input', filterRows);
            if (assignSubjectSelect) assignSubjectSelect.addEventListener('change', applyAssignHierarchy);
            if (assignAcademicYearSelect) {
                assignAcademicYearSelect.addEventListener('change', function () {
                    rebuildAssignSubjectOptions();
                    applyAssignHierarchy();
                });
            }
            if (assignSemesterSelect) {
                assignSemesterSelect.addEventListener('change', function () {
                    rebuildAssignSubjectOptions();
                    applyAssignHierarchy();
                });
            }
            if (assignSectionSelect) assignSectionSelect.addEventListener('change', applyAssignHierarchy);
            if (assignTeacherSelect) assignTeacherSelect.addEventListener('change', applyAssignHierarchy);
            rebuildAssignSubjectOptions();
            applyAssignHierarchy();
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
