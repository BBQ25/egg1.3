<?php include '../layouts/session.php'; ?>
<?php require_active_role('teacher'); ?>
<?php include '../layouts/main.php'; ?>
<?php include_once '../includes/reference.php'; ?>

<?php
$flash = '';
$flashType = 'success';

// Load subjects list
$subjects = [];
$subRes = $conn->query("SELECT id, subject_code, subject_name, academic_year, semester FROM subjects WHERE status = 'active' ORDER BY subject_name");
if ($subRes) {
    while ($r = $subRes->fetch_assoc()) $subjects[] = $r;
}

// Load available terms from enrollments to reduce typing
$academicYears = [];
$ayRes = $conn->query("SELECT DISTINCT academic_year FROM enrollments ORDER BY academic_year DESC");
if ($ayRes) {
    while ($r = $ayRes->fetch_assoc()) $academicYears[] = $r['academic_year'];
}
$semesters = [];
$semRes = $conn->query("SELECT DISTINCT semester FROM enrollments ORDER BY semester");
if ($semRes) {
    while ($r = $semRes->fetch_assoc()) $semesters[] = $r['semester'];
}

// Canonical section suggestions (prefer IF-style codes).
$sectionSuggestions = [];
$seenSections = [];
$addSectionSuggestion = static function (&$sectionSuggestions, &$seenSections, $value) {
    $value = trim((string) $value);
    if ($value === '') return;
    if (isset($seenSections[$value])) return;
    $seenSections[$value] = true;
    $sectionSuggestions[] = $value;
};

if (function_exists('ref_sync_class_sections_from_records')) {
    ref_sync_class_sections_from_records($conn);
}
if (function_exists('ref_list_class_sections')) {
    $classSections = ref_list_class_sections($conn, true, true);
    foreach ($classSections as $row) {
        $secName = trim((string) ($row['code'] ?? ''));
        if ($secName === '') continue;
        $addSectionSuggestion($sectionSuggestions, $seenSections, strtoupper($secName));
    }
}
sort($sectionSuggestions);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    if (!csrf_validate($csrf)) {
        $flash = 'Security check failed (CSRF). Please try again.';
        $flashType = 'danger';
    } else {
    $subjectId = isset($_POST['subject_id']) ? (int) $_POST['subject_id'] : 0;
    $academicYear = isset($_POST['academic_year']) ? trim($_POST['academic_year']) : '';
    $semester = isset($_POST['semester']) ? trim($_POST['semester']) : '';
    $section = isset($_POST['section']) ? trim($_POST['section']) : '';

    if (function_exists('ref_section_lookup_hint')) {
        $section = ref_section_lookup_hint($section);
    }

    if ($subjectId <= 0 || $academicYear === '' || $semester === '' || $section === '') {
        $flash = 'Subject, Academic Year, Semester, and Section are required.';
        $flashType = 'danger';
    } else {
        $teacherId = (int) $_SESSION['user_id'];
        $conn->begin_transaction();
        try {
            // 1) Ensure class_record exists
            $classRecordId = null;
            if (function_exists('ref_resolve_class_record_target')) {
                $resolved = ref_resolve_class_record_target($conn, $subjectId, $academicYear, $semester, $section);
                $resolvedClassId = (int) ($resolved['class_record_id'] ?? 0);
                if ($resolvedClassId > 0) {
                    $classRecordId = $resolvedClassId;
                    $section = trim((string) ($resolved['section'] ?? $section));
                }
            }

            if (!$classRecordId) {
                $find = $conn->prepare("SELECT id FROM class_records WHERE subject_id = ? AND section = ? AND academic_year = ? AND semester = ? AND status = 'active' LIMIT 1");
                $find->bind_param('isss', $subjectId, $section, $academicYear, $semester);
                $find->execute();
                $res = $find->get_result();
                if ($res && $res->num_rows === 1) {
                    $classRecordId = (int) $res->fetch_assoc()['id'];
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
                        ' is ambiguous. Use a full class section code (for example IF-2-B-6).'
                    );
                }

                $createdBy = $teacherId;
                $ins = $conn->prepare("INSERT INTO class_records (subject_id, teacher_id, record_type, section, academic_year, semester, created_by, status) VALUES (?, ?, 'assigned', ?, ?, ?, ?, 'active')");
                $ins->bind_param('iisssi', $subjectId, $teacherId, $section, $academicYear, $semester, $createdBy);
                $ins->execute();
                $classRecordId = (int) $conn->insert_id;
                $ins->close();
            }

            // 2) Ensure teacher assignment exists
            $ta = $conn->prepare("SELECT id FROM teacher_assignments WHERE teacher_id = ? AND class_record_id = ? LIMIT 1");
            $ta->bind_param('ii', $teacherId, $classRecordId);
            $ta->execute();
            $taRes = $ta->get_result();
            if (!$taRes || $taRes->num_rows === 0) {
                $assignedBy = $teacherId;
                $insTa = $conn->prepare("INSERT INTO teacher_assignments (teacher_id, teacher_role, class_record_id, assigned_by, status) VALUES (?, 'primary', ?, ?, 'active')");
                $insTa->bind_param('iii', $teacherId, $classRecordId, $assignedBy);
                $insTa->execute();
                $insTa->close();
            }
            $ta->close();

            // 3) Pull enrollments for this term/section/subject.
            // Teacher submissions require admin approval, so we move Pending/Active -> TeacherPending.
            $en = $conn->prepare(
                "SELECT id, student_no, status, section
                 FROM enrollments
                 WHERE subject_id = ?
                   AND academic_year = ?
                   AND semester = ?
                   AND status IN ('Pending','Active','TeacherPending')
                 ORDER BY enrollment_date ASC"
            );
            $en->bind_param('iss', $subjectId, $academicYear, $semester);
            $en->execute();
            $enRes = $en->get_result();

            $submittedCount = 0;
            $alreadyPendingCount = 0;
            $skippedCount = 0;

            $findStudent = $conn->prepare("SELECT id FROM students WHERE StudentNo = ? LIMIT 1");
            $markTeacherPending = $conn->prepare(
                "UPDATE enrollments
                 SET status = 'TeacherPending', created_by = ?
                 WHERE id = ? AND status IN ('Pending', 'Active')"
            );
            if (!$findStudent || !$markTeacherPending) {
                throw new RuntimeException('Unable to prepare enrollment request statements.');
            }

            while ($row = $enRes->fetch_assoc()) {
                $enrollmentId = (int) ($row['id'] ?? 0);
                $studentNo = (string) ($row['student_no'] ?? '');
                $enrollmentStatus = trim((string) ($row['status'] ?? ''));
                $rowSection = trim((string) ($row['section'] ?? ''));

                $matchesSection = strcasecmp($rowSection, $section) === 0;
                if (!$matchesSection && function_exists('ref_section_alias_match')) {
                    $matchesSection = ref_section_alias_match($rowSection, $section);
                }
                if (!$matchesSection) {
                    continue;
                }

                if ($enrollmentId <= 0 || $studentNo === '') {
                    $skippedCount++;
                    continue;
                }

                if (strcasecmp($enrollmentStatus, 'TeacherPending') === 0) {
                    $alreadyPendingCount++;
                    continue;
                }

                $findStudent->bind_param('s', $studentNo);
                $findStudent->execute();
                $sRes = $findStudent->get_result();
                if (!$sRes || $sRes->num_rows !== 1) {
                    $skippedCount++;
                    continue;
                }

                $statusTag = strcasecmp($enrollmentStatus, 'Pending') === 0 ? 'pending' : 'active';
                $requestTag = 'teacher_request:t' . $teacherId . ':c' . (int) $classRecordId . ':s' . $statusTag;
                $markTeacherPending->bind_param('si', $requestTag, $enrollmentId);
                $markTeacherPending->execute();
                if ((int) $markTeacherPending->affected_rows === 1) {
                    $submittedCount++;
                } else {
                    $alreadyPendingCount++;
                }
            }
            $en->close();

            $findStudent->close();
            $markTeacherPending->close();

            $conn->commit();
            $flash = "Submitted {$submittedCount} enrollment request(s) for admin approval. Already pending {$alreadyPendingCount}. Skipped {$skippedCount}.";
            $flashType = 'success';
        } catch (Throwable $e) {
            $conn->rollback();
            $flash = 'Claim failed: ' . $e->getMessage();
            $flashType = 'danger';
        }
    }
    }
}
?>

<head>
    <title>Enrollment Requests | E-Record</title>
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
                                        <li class="breadcrumb-item"><a href="teacher-dashboard.php">Teacher</a></li>
                                        <li class="breadcrumb-item active">Enrollment Requests</li>
                                    </ol>
                                </div>
                                <h4 class="page-title">Enrollment Requests</h4>
                            </div>
                        </div>
                    </div>

                    <?php if ($flash): ?>
                        <div class="alert alert-<?php echo htmlspecialchars($flashType); ?>"><?php echo htmlspecialchars($flash); ?></div>
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-lg-8">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title">Submit Enrollment Requests</h4>
                                    <p class="text-muted">Teacher-submitted enrollments require admin approval before students appear on the class roster.</p>

                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES); ?>">
                                        <div class="alert alert-light border py-2 px-3 mb-3 small">
                                            Hierarchy: 1 Academic Year -> 2 Semester -> 3 Subject -> 4 Section -> Submit
                                        </div>

                                        <div class="row g-2">
                                            <div class="col-md-4">
                                                <label class="form-label">1) Academic Year</label>
                                                <select class="form-select" name="academic_year" id="claimAcademicYearSelect" required>
                                                    <option value="">Select</option>
                                                    <?php foreach ($academicYears as $ay): ?>
                                                        <option value="<?php echo htmlspecialchars($ay); ?>"><?php echo htmlspecialchars($ay); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">2) Semester</label>
                                                <select class="form-select" name="semester" id="claimSemesterSelect" required>
                                                    <option value="">Select</option>
                                                    <?php foreach ($semesters as $sem): ?>
                                                        <option value="<?php echo htmlspecialchars($sem); ?>"><?php echo htmlspecialchars($sem); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="mt-2">
                                            <label class="form-label">3) Subject</label>
                                            <select class="form-select" name="subject_id" id="claimSubjectSelect" required>
                                                <option value="">Select</option>
                                                <?php foreach ($subjects as $s): ?>
                                                    <option
                                                        value="<?php echo (int) $s['id']; ?>"
                                                        data-academic-year="<?php echo htmlspecialchars((string) ($s['academic_year'] ?? ''), ENT_QUOTES); ?>"
                                                        data-semester="<?php echo htmlspecialchars((string) ($s['semester'] ?? ''), ENT_QUOTES); ?>"
                                                    >
                                                        <?php echo htmlspecialchars($s['subject_name'] . ' (' . $s['subject_code'] . ')'); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="mt-2">
                                            <label class="form-label">4) Section</label>
                                            <input class="form-control" name="section" id="claimSectionInput" required placeholder="e.g. IF-2-B-6" list="section-options">
                                            <datalist id="section-options">
                                                <?php foreach ($sectionSuggestions as $sec): ?>
                                                    <option value="<?php echo htmlspecialchars($sec); ?>"></option>
                                                <?php endforeach; ?>
                                            </datalist>
                                        </div>

                                        <div class="mt-3">
                                            <button class="btn btn-primary" type="submit" id="claimSubmitBtn">Submit for Approval</button>
                                        </div>
                                    </form>
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
            var subjectSelect = document.getElementById('claimSubjectSelect');
            var aySelect = document.getElementById('claimAcademicYearSelect');
            var semSelect = document.getElementById('claimSemesterSelect');
            var sectionInput = document.getElementById('claimSectionInput');
            var submitBtn = document.getElementById('claimSubmitBtn');

            function normalize(value) {
                return String(value || '').trim().toLowerCase();
            }

            function rebuildSubjectOptions() {
                if (!subjectSelect || !aySelect || !semSelect) return;
                var ay = normalize(aySelect.value);
                var sem = normalize(semSelect.value);
                var selectedValue = String(subjectSelect.value || '');
                var canShowSubjects = ay !== '' && sem !== '';

                Array.from(subjectSelect.options).forEach(function (opt, idx) {
                    if (idx === 0) {
                        opt.hidden = false;
                        opt.disabled = false;
                        return;
                    }
                    var subjectAy = normalize(opt.getAttribute('data-academic-year'));
                    var subjectSem = normalize(opt.getAttribute('data-semester'));
                    var ayMatch = subjectAy === '' || subjectAy === ay;
                    var semMatch = subjectSem === '' || subjectSem === sem;
                    var visible = ayMatch && semMatch;
                    if (!canShowSubjects) visible = false;

                    opt.hidden = !visible;
                    opt.disabled = !visible;
                });

                var selected = subjectSelect.options[subjectSelect.selectedIndex];
                if (!selected || selected.disabled) subjectSelect.value = '';
                if (selectedValue !== '' && String(subjectSelect.value || '') === '') subjectSelect.value = '';
            }

            function applyClaimHierarchy() {
                var hasAy = normalize(aySelect ? aySelect.value : '') !== '';
                var hasSem = normalize(semSelect ? semSelect.value : '') !== '';
                var hasSubject = normalize(subjectSelect ? subjectSelect.value : '') !== '';

                if (semSelect) {
                    semSelect.disabled = !hasAy;
                    if (!hasAy) semSelect.value = '';
                }
                if (!hasAy) hasSem = false;

                if (subjectSelect) {
                    subjectSelect.disabled = !(hasAy && hasSem);
                    if (subjectSelect.disabled) subjectSelect.value = '';
                }
                hasSubject = normalize(subjectSelect ? subjectSelect.value : '') !== '';

                if (sectionInput) {
                    sectionInput.disabled = !hasSubject;
                    if (!hasSubject) sectionInput.value = '';
                }

                if (submitBtn) {
                    submitBtn.disabled = !(hasSubject && normalize(sectionInput ? sectionInput.value : '') !== '');
                }
            }

            if (subjectSelect) subjectSelect.addEventListener('change', applyClaimHierarchy);
            if (aySelect) aySelect.addEventListener('change', function () {
                rebuildSubjectOptions();
                applyClaimHierarchy();
            });
            if (semSelect) semSelect.addEventListener('change', function () {
                rebuildSubjectOptions();
                applyClaimHierarchy();
            });
            if (sectionInput) sectionInput.addEventListener('input', applyClaimHierarchy);
            rebuildSubjectOptions();
            applyClaimHierarchy();
        })();
    </script>
</body>
</html>


