<?php include '../layouts/session.php'; ?>
<?php require_active_role('teacher'); ?>
<?php include '../layouts/main.php'; ?>

<?php
require_once __DIR__ . '/../includes/reference.php';
require_once __DIR__ . '/../includes/reverse_class_record.php';
require_once __DIR__ . '/../includes/learning_materials.php';
ensure_reference_tables($conn);
reverse_class_record_ensure_settings_table($conn);
ensure_learning_material_tables($conn);

$teacherId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$canUseReverseClassRecord = reverse_class_record_can_teacher_use($conn, $teacherId);
$assigned = [];
if ($teacherId > 0) {
    $stmt = $conn->prepare(
        "SELECT cr.id AS class_record_id,
                ta.teacher_role,
                cr.section, cr.academic_year, cr.semester,
                COALESCE(NULLIF(TRIM(cr.year_level), ''), 'N/A') AS year_level,
                COALESCE(NULLIF(TRIM(s.course), ''), 'N/A') AS course,
                COALESCE(NULLIF(TRIM(s.major), ''), 'N/A') AS major,
                s.subject_code, s.subject_name
         FROM teacher_assignments ta
         JOIN class_records cr ON cr.id = ta.class_record_id
         JOIN subjects s ON s.id = cr.subject_id
         WHERE ta.teacher_id = ? AND ta.status = 'active' AND cr.status = 'active'
         ORDER BY cr.academic_year DESC, cr.semester ASC, s.course ASC, s.major ASC, s.subject_name ASC, cr.section ASC
         LIMIT 500"
    );
    if ($stmt) {
        $stmt->bind_param('i', $teacherId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($r = $res->fetch_assoc())) $assigned[] = $r;
        $stmt->close();
    }
}

$liveByClass = [];
if (count($assigned) > 0) {
    $classIds = [];
    foreach ($assigned as $row) {
        $cid = (int) ($row['class_record_id'] ?? 0);
        if ($cid > 0) $classIds[$cid] = $cid;
    }
    if (count($classIds) > 0) {
        $idSql = implode(',', array_values($classIds));
        $liveRes = $conn->query(
            "SELECT class_record_id, COUNT(*) AS live_count
             FROM learning_material_live_broadcasts
             WHERE status = 'live'
               AND class_record_id IN (" . $idSql . ")
             GROUP BY class_record_id"
        );
        while ($liveRes && ($liveRow = $liveRes->fetch_assoc())) {
            $cid = (int) ($liveRow['class_record_id'] ?? 0);
            if ($cid <= 0) continue;
            $liveByClass[$cid] = (int) ($liveRow['live_count'] ?? 0);
        }
    }
}

if (!function_exists('tmc_h')) {
    function tmc_h($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('tmc_section_group')) {
    function tmc_section_group($section) {
        $section = strtoupper(trim((string) $section));
        if ($section === '') return '';
        if (preg_match('/^[A-Z]+-\d+-([A-Z]+)-\d+$/', $section, $m)) return (string) ($m[1] ?? '');
        if (preg_match('/^[A-Z]+$/', $section)) return $section;
        return '';
    }
}

// Reference lists (admin-set active names). Used for defaults and filter options.
$refAcademicYears = ref_list_active_names($conn, 'academic_years');
$refSemesters = ref_list_active_names($conn, 'semesters');

// Build filter option sets from assignments + references.
$optAy = [];
$optSem = [];
$optCourse = [];
$optMajor = [];
$optYear = [];
$optSectionGroup = [];
$optSubject = []; // subject_code => label

foreach ($assigned as $a) {
    $ay = trim((string) ($a['academic_year'] ?? ''));
    $sem = trim((string) ($a['semester'] ?? ''));
    $course = trim((string) ($a['course'] ?? 'N/A'));
    $major = trim((string) ($a['major'] ?? 'N/A'));
    $year = trim((string) ($a['year_level'] ?? 'N/A'));
    $section = trim((string) ($a['section'] ?? ''));
    $scode = trim((string) ($a['subject_code'] ?? ''));
    $sname = trim((string) ($a['subject_name'] ?? ''));

    if ($ay !== '') $optAy[$ay] = true;
    if ($sem !== '') $optSem[$sem] = true;
    if ($course === '') $course = 'N/A';
    if ($major === '') $major = 'N/A';
    if ($year === '') $year = 'N/A';
    $optCourse[$course] = true;
    $optMajor[$major] = true;
    $optYear[$year] = true;
    $sectionGroup = tmc_section_group($section);
    if ($sectionGroup !== '') $optSectionGroup[$sectionGroup] = true;

    if ($scode !== '') {
        $label = $scode;
        if ($sname !== '') $label .= ' - ' . $sname;
        $optSubject[$scode] = $label;
    }
}
foreach ($refAcademicYears as $ay) if ($ay !== '') $optAy[$ay] = true;
foreach ($refSemesters as $sem) if ($sem !== '') $optSem[$sem] = true;

$optAy = array_keys($optAy);
$optSem = array_keys($optSem);
$optCourse = array_keys($optCourse);
$optMajor = array_keys($optMajor);
$optYear = array_keys($optYear);
$optSectionGroup = array_keys($optSectionGroup);
sort($optAy);
sort($optSem);
sort($optCourse);
sort($optMajor);
sort($optYear);
sort($optSectionGroup);
asort($optSubject);

// Defaults: if reference table has exactly 1 active AY/semester, use it; else use first assignment row.
$first = count($assigned) > 0 ? $assigned[0] : [];
$defaultAy = count($refAcademicYears) === 1 ? (string) $refAcademicYears[0] : (string) ($first['academic_year'] ?? '');
$defaultSem = count($refSemesters) === 1 ? (string) $refSemesters[0] : (string) ($first['semester'] ?? '');
if ($defaultAy !== '' && count($assigned) > 0 && !in_array($defaultAy, $optAy, true)) $defaultAy = (string) ($first['academic_year'] ?? '');
if ($defaultSem !== '' && count($assigned) > 0 && !in_array($defaultSem, $optSem, true)) $defaultSem = (string) ($first['semester'] ?? '');

$prefAy = isset($_GET['academic_year']) ? trim((string) $_GET['academic_year']) : $defaultAy;
$prefSem = isset($_GET['semester']) ? trim((string) $_GET['semester']) : $defaultSem;
$prefCourse = isset($_GET['course']) ? trim((string) $_GET['course']) : '';
$prefMajor = isset($_GET['major']) ? trim((string) $_GET['major']) : '';
$prefYear = isset($_GET['year_level']) ? trim((string) $_GET['year_level']) : '';
$prefSectionRaw = isset($_GET['section']) ? trim((string) $_GET['section']) : '';
$prefSection = tmc_section_group($prefSectionRaw);
if ($prefSection === '' && preg_match('/^[A-Za-z]+$/', $prefSectionRaw)) $prefSection = strtoupper($prefSectionRaw);
$prefSubject = isset($_GET['subject']) ? trim((string) $_GET['subject']) : '';
$prefSearch = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
?>

<head>
    <title>My Classes | E-Record</title>
    <?php include '../layouts/title-meta.php'; ?>
    <?php include '../layouts/head-css.php'; ?>
    <style>
        .class-action-cell {
            white-space: nowrap;
        }

        .class-action-btn {
            width: 2.15rem;
            height: 2.15rem;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .class-action-btn+.class-action-btn {
            margin-left: .35rem;
        }

        .class-action-btn i {
            font-size: 1rem;
            line-height: 1;
        }

        .tmc-filters .form-control,
        .tmc-filters .form-select {
            min-width: 10rem;
        }

        .tmc-filters .tmc-search {
            min-width: 16rem;
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
                                        <li class="breadcrumb-item"><a href="teacher-dashboard.php">Teacher</a></li>
                                        <li class="breadcrumb-item active">My Classes</li>
                                    </ol>
                                </div>
                                <h4 class="page-title">My Classes</h4>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                        <div>
                                            <h4 class="header-title mb-1">My Assigned Classes</h4>
                                            <p class="text-muted mb-0">Subjects/sections assigned to you by the admin (including approved enrollment requests).</p>
                                        </div>
                                        <div class="text-muted small">
                                            Active: <strong><?php echo (int) count($assigned); ?></strong>
                                        </div>
                                    </div>

                                    <div class="tmc-filters mt-3">
                                        <div class="row g-2 align-items-end">
                                            <div class="col-12 col-lg-3">
                                                <label class="form-label mb-1">Search</label>
                                                <input id="tmcSearch" class="form-control tmc-search" placeholder="Subject, section, course, major..." value="<?php echo tmc_h($prefSearch); ?>">
                                            </div>
                                            <div class="col-6 col-lg-2">
                                                <label class="form-label mb-1">Academic Year</label>
                                                <select id="tmcAy" class="form-select">
                                                    <option value="">All</option>
                                                    <?php foreach ($optAy as $ay): ?>
                                                        <option value="<?php echo tmc_h($ay); ?>" <?php echo $ay === $prefAy ? 'selected' : ''; ?>><?php echo tmc_h($ay); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-6 col-lg-2">
                                                <label class="form-label mb-1">Semester</label>
                                                <select id="tmcSem" class="form-select">
                                                    <option value="">All</option>
                                                    <?php foreach ($optSem as $sem): ?>
                                                        <option value="<?php echo tmc_h($sem); ?>" <?php echo $sem === $prefSem ? 'selected' : ''; ?>><?php echo tmc_h($sem); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-6 col-lg-2">
                                                <label class="form-label mb-1">Course</label>
                                                <select id="tmcCourse" class="form-select">
                                                    <option value="">All</option>
                                                    <?php foreach ($optCourse as $course): ?>
                                                        <option value="<?php echo tmc_h($course); ?>" <?php echo $course === $prefCourse ? 'selected' : ''; ?>><?php echo tmc_h($course); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-6 col-lg-3">
                                                <label class="form-label mb-1">Major</label>
                                                <select id="tmcMajor" class="form-select">
                                                    <option value="">All</option>
                                                    <?php foreach ($optMajor as $major): ?>
                                                        <option value="<?php echo tmc_h($major); ?>" <?php echo $major === $prefMajor ? 'selected' : ''; ?>><?php echo tmc_h($major); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-6 col-lg-2">
                                                <label class="form-label mb-1">Year Level</label>
                                                <select id="tmcYear" class="form-select">
                                                    <option value="">All</option>
                                                    <?php foreach ($optYear as $year): ?>
                                                        <option value="<?php echo tmc_h($year); ?>" <?php echo $year === $prefYear ? 'selected' : ''; ?>><?php echo tmc_h($year); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-6 col-lg-2">
                                                <label class="form-label mb-1">Section</label>
                                                <select id="tmcSection" class="form-select">
                                                    <option value="">All</option>
                                                    <?php foreach ($optSectionGroup as $secGroup): ?>
                                                        <option value="<?php echo tmc_h($secGroup); ?>" <?php echo $secGroup === $prefSection ? 'selected' : ''; ?>><?php echo tmc_h($secGroup); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-12 col-lg-4">
                                                <label class="form-label mb-1">Subject</label>
                                                <select id="tmcSubject" class="form-select">
                                                    <option value="">All</option>
                                                    <?php foreach ($optSubject as $scode => $label): ?>
                                                        <option value="<?php echo tmc_h($scode); ?>" <?php echo $scode === $prefSubject ? 'selected' : ''; ?>><?php echo tmc_h($label); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-12 col-lg-2 d-flex gap-2 align-items-end">
                                                <button id="tmcClear" type="button" class="btn btn-outline-secondary w-100">Clear</button>
                                            </div>
                                            <div class="col-12 col-lg-3 d-flex align-items-end">
                                                <div class="text-muted small">
                                                    Showing: <strong id="tmcShown">0</strong> of <strong id="tmcTotal"><?php echo (int) count($assigned); ?></strong>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="table-responsive mt-3">
                                        <table class="table table-sm table-striped table-hover align-middle mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Subject</th>
                                                    <th>Section</th>
                                                    <th>Term</th>
                                                    <th>Role</th>
                                                    <th class="text-end">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (count($assigned) === 0): ?>
                                                    <tr>
                                                        <td colspan="5" class="text-center text-muted py-4">
                                                            No active assignments yet.
                                                            <div class="mt-2">
                                                                <a class="btn btn-sm btn-outline-primary" href="teacher-claim.php">
                                                                    <i class="ri-team-line me-1" aria-hidden="true"></i>
                                                                    Enrollment Requests
                                                                </a>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endif; ?>
                                                <?php foreach ($assigned as $a): ?>
                                                    <?php
                                                    $classRecordId = (int) ($a['class_record_id'] ?? 0);
                                                    $ay = trim((string) ($a['academic_year'] ?? ''));
                                                    $sem = trim((string) ($a['semester'] ?? ''));
                                                    $course = trim((string) ($a['course'] ?? 'N/A'));
                                                    $major = trim((string) ($a['major'] ?? 'N/A'));
                                                    $yearLevel = trim((string) ($a['year_level'] ?? 'N/A'));
                                                    $section = trim((string) ($a['section'] ?? ''));
                                                    $sectionGroup = tmc_section_group($section);
                                                    $scode = trim((string) ($a['subject_code'] ?? ''));
                                                    $sname = trim((string) ($a['subject_name'] ?? ''));
                                                    $liveCount = (int) ($liveByClass[$classRecordId] ?? 0);
                                                    $searchBlob = strtolower(trim(preg_replace('/\\s+/', ' ', implode(' ', [
                                                        $scode,
                                                        $sname,
                                                        $section,
                                                        $ay,
                                                        $sem,
                                                        $course,
                                                        $major,
                                                        $yearLevel,
                                                    ]))));
                                                    ?>
                                                    <tr>
                                                        <td>
                                                            <div class="fw-semibold"><?php echo tmc_h($sname); ?></div>
                                                            <div class="text-muted small">
                                                                <span class="badge bg-light text-dark border">
                                                                    <?php echo tmc_h($scode); ?>
                                                                </span>
                                                            </div>
                                                            <div class="text-muted small">
                                                                <?php echo tmc_h($course); ?><?php echo $major !== '' && $major !== 'N/A' ? (' | ' . tmc_h($major)) : ''; ?>
                                                            </div>
                                                            <?php if ($liveCount > 0): ?>
                                                                <div class="mt-1">
                                                                    <span class="badge bg-danger-subtle text-danger">
                                                                        Live Now: <?php echo (int) $liveCount; ?>
                                                                    </span>
                                                                </div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <div><?php echo tmc_h($section); ?></div>
                                                            <div class="text-muted small"><?php echo tmc_h($yearLevel); ?></div>
                                                        </td>
                                                        <td>
                                                            <div><?php echo tmc_h($ay); ?></div>
                                                            <div class="text-muted small"><?php echo tmc_h($sem); ?></div>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-primary-subtle text-primary">
                                                                <?php echo tmc_h((string) ($a['teacher_role'] ?? '')); ?>
                                                            </span>
                                                        </td>
                                                        <td class="text-end class-action-cell"
                                                            data-ay="<?php echo tmc_h($ay); ?>"
                                                            data-sem="<?php echo tmc_h($sem); ?>"
                                                            data-course="<?php echo tmc_h($course); ?>"
                                                            data-major="<?php echo tmc_h($major); ?>"
                                                            data-year="<?php echo tmc_h($yearLevel); ?>"
                                                            data-section="<?php echo tmc_h($section); ?>"
                                                            data-section-group="<?php echo tmc_h($sectionGroup); ?>"
                                                            data-subject="<?php echo tmc_h($scode); ?>"
                                                            data-search="<?php echo tmc_h($searchBlob); ?>">
                                                            <a class="btn btn-sm btn-outline-primary class-action-btn"
                                                                href="teacher-grading-config.php?class_record_id=<?php echo $classRecordId; ?>"
                                                                data-bs-toggle="tooltip"
                                                                data-bs-placement="top"
                                                                title="Components &amp; Weights"
                                                                aria-label="Components &amp; Weights">
                                                                <i class="ri-scales-3-line" aria-hidden="true"></i>
                                                            </a>
                                                            <?php if ($canUseReverseClassRecord): ?>
                                                                <a class="btn btn-sm btn-outline-danger class-action-btn"
                                                                    href="teacher-reverse-class-record.php?class_record_id=<?php echo $classRecordId; ?>"
                                                                    data-bs-toggle="tooltip"
                                                                    data-bs-placement="top"
                                                                    title="Reverse Class Record"
                                                                    aria-label="Reverse Class Record">
                                                                    <i class="ri-magic-line" aria-hidden="true"></i>
                                                                </a>
                                                            <?php endif; ?>
                                                            <a class="btn btn-sm btn-outline-info class-action-btn"
                                                                href="teacher-attendance-uploads.php?class_record_id=<?php echo $classRecordId; ?>"
                                                                data-bs-toggle="tooltip"
                                                                data-bs-placement="top"
                                                                title="Check-In"
                                                                aria-label="Check-In">
                                                                <i class="ri-key-2-line" aria-hidden="true"></i>
                                                            </a>
                                                            <a class="btn btn-sm btn-outline-success class-action-btn"
                                                                href="teacher-learning-materials.php?class_record_id=<?php echo $classRecordId; ?>"
                                                                data-bs-toggle="tooltip"
                                                                data-bs-placement="top"
                                                                title="Materials"
                                                                aria-label="Materials">
                                                                <i class="ri-article-line" aria-hidden="true"></i>
                                                            </a>
                                                            <a class="btn btn-sm btn-outline-dark class-action-btn"
                                                                href="teacher-tos-tqs.php?class_record_id=<?php echo $classRecordId; ?>"
                                                                data-bs-toggle="tooltip"
                                                                data-bs-placement="top"
                                                                title="TOS/TQS"
                                                                aria-label="TOS/TQS">
                                                                <i class="ri-file-chart-line" aria-hidden="true"></i>
                                                            </a>
                                                            <a class="btn btn-sm btn-outline-warning class-action-btn"
                                                                href="teacher-wheel.php?class_record_id=<?php echo $classRecordId; ?>"
                                                                data-bs-toggle="tooltip"
                                                                data-bs-placement="top"
                                                                title="Wheel"
                                                                aria-label="Wheel">
                                                                <i class="ri-disc-line" aria-hidden="true"></i>
                                                            </a>
                                                            <a class="btn btn-sm btn-outline-secondary class-action-btn"
                                                                href="class-record-print.php?class_record_id=<?php echo $classRecordId; ?>&term=midterm&view=assessments"
                                                                data-bs-toggle="tooltip"
                                                                data-bs-placement="top"
                                                                title="Print"
                                                                aria-label="Print">
                                                                <i class="ri-printer-line" aria-hidden="true"></i>
                                                            </a>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <div class="mt-3">
                                        <a class="btn btn-outline-primary" href="teacher-claim.php">
                                            <i class="ri-team-line me-1" aria-hidden="true"></i>
                                            Enrollment Requests
                                        </a>
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
            var searchEl = document.getElementById('tmcSearch');
            var ayEl = document.getElementById('tmcAy');
            var semEl = document.getElementById('tmcSem');
            var courseEl = document.getElementById('tmcCourse');
            var majorEl = document.getElementById('tmcMajor');
            var yearEl = document.getElementById('tmcYear');
            var sectionEl = document.getElementById('tmcSection');
            var subjectEl = document.getElementById('tmcSubject');
            var clearEl = document.getElementById('tmcClear');
            var shownEl = document.getElementById('tmcShown');

            var defaults = {
                ay: <?php echo json_encode((string) $prefAy, JSON_UNESCAPED_SLASHES); ?>,
                sem: <?php echo json_encode((string) $prefSem, JSON_UNESCAPED_SLASHES); ?>
            };

            var cells = Array.prototype.slice.call(document.querySelectorAll('td.class-action-cell[data-search]'));
            var subjectLabelMap = {};
            if (subjectEl) {
                Array.prototype.slice.call(subjectEl.options).forEach(function (opt) {
                    var val = String(opt && opt.value || '').trim();
                    if (!val) return;
                    subjectLabelMap[val] = String(opt.textContent || val).trim();
                });
            }

            function norm(v) {
                return String(v || '').trim().toLowerCase();
            }

            function matchesExact(needle, value) {
                needle = norm(needle);
                if (!needle) return true;
                return needle === norm(value);
            }

            function sectionGroup(value) {
                var raw = String(value || '').trim().toUpperCase();
                if (!raw) return '';
                var m = raw.match(/^[A-Z]+-\d+-([A-Z]+)-\d+$/);
                if (m && m[1]) return String(m[1]).toUpperCase();
                if (/^[A-Z]+$/.test(raw)) return raw;
                return '';
            }

            function updateSectionOptions() {
                if (!sectionEl) return;

                var ay = ayEl ? ayEl.value : '';
                var sem = semEl ? semEl.value : '';
                var course = courseEl ? courseEl.value : '';
                var major = majorEl ? majorEl.value : '';
                var year = yearEl ? yearEl.value : '';

                var current = sectionEl.value || '';
                var groups = {};
                cells.forEach(function (cell) {
                    var ok = true;
                    if (ok && ay) ok = matchesExact(ay, cell.dataset.ay);
                    if (ok && sem) ok = matchesExact(sem, cell.dataset.sem);
                    if (ok && course) ok = matchesExact(course, cell.dataset.course);
                    if (ok && major) ok = matchesExact(major, cell.dataset.major);
                    if (ok && year) ok = matchesExact(year, cell.dataset.year);
                    if (!ok) return;

                    var grp = sectionGroup(cell.dataset.sectionGroup || cell.dataset.section);
                    if (grp) groups[grp] = true;
                });

                var keys = Object.keys(groups).sort();
                sectionEl.innerHTML = '';
                var allOpt = document.createElement('option');
                allOpt.value = '';
                allOpt.textContent = 'All';
                sectionEl.appendChild(allOpt);
                keys.forEach(function (k) {
                    var opt = document.createElement('option');
                    opt.value = k;
                    opt.textContent = k;
                    sectionEl.appendChild(opt);
                });

                var normalizedCurrent = sectionGroup(current);
                if (normalizedCurrent && groups[normalizedCurrent]) sectionEl.value = normalizedCurrent;
                else sectionEl.value = '';
            }

            function updateSubjectOptions() {
                if (!subjectEl) return;

                var ay = ayEl ? ayEl.value : '';
                var sem = semEl ? semEl.value : '';
                var course = courseEl ? courseEl.value : '';
                var major = majorEl ? majorEl.value : '';
                var year = yearEl ? yearEl.value : '';
                var section = sectionEl ? sectionEl.value : '';

                var current = String(subjectEl.value || '').trim();
                var subjects = {};

                cells.forEach(function (cell) {
                    var ok = true;
                    if (ok && ay) ok = matchesExact(ay, cell.dataset.ay);
                    if (ok && sem) ok = matchesExact(sem, cell.dataset.sem);
                    if (ok && course) ok = matchesExact(course, cell.dataset.course);
                    if (ok && major) ok = matchesExact(major, cell.dataset.major);
                    if (ok && year) ok = matchesExact(year, cell.dataset.year);
                    if (ok && section) ok = matchesExact(section, cell.dataset.sectionGroup || sectionGroup(cell.dataset.section));
                    if (!ok) return;

                    var code = String(cell.dataset.subject || '').trim();
                    if (!code) return;
                    subjects[code] = subjectLabelMap[code] || code;
                });

                var keys = Object.keys(subjects).sort(function (a, b) {
                    return String(subjects[a]).localeCompare(String(subjects[b]));
                });

                subjectEl.innerHTML = '';
                var allOpt = document.createElement('option');
                allOpt.value = '';
                allOpt.textContent = 'All';
                subjectEl.appendChild(allOpt);
                keys.forEach(function (code) {
                    var opt = document.createElement('option');
                    opt.value = code;
                    opt.textContent = subjects[code];
                    subjectEl.appendChild(opt);
                });

                if (current && subjects[current]) subjectEl.value = current;
                else subjectEl.value = '';
            }

            function apply() {
                updateSectionOptions();
                updateSubjectOptions();

                var q = norm(searchEl && searchEl.value);
                var ay = ayEl ? ayEl.value : '';
                var sem = semEl ? semEl.value : '';
                var course = courseEl ? courseEl.value : '';
                var major = majorEl ? majorEl.value : '';
                var year = yearEl ? yearEl.value : '';
                var section = sectionEl ? sectionEl.value : '';
                var subject = subjectEl ? subjectEl.value : '';

                var shown = 0;
                cells.forEach(function (cell) {
                    var ok = true;
                    if (ok && ay) ok = matchesExact(ay, cell.dataset.ay);
                    if (ok && sem) ok = matchesExact(sem, cell.dataset.sem);
                    if (ok && course) ok = matchesExact(course, cell.dataset.course);
                    if (ok && major) ok = matchesExact(major, cell.dataset.major);
                    if (ok && year) ok = matchesExact(year, cell.dataset.year);
                    if (ok && section) ok = matchesExact(section, cell.dataset.sectionGroup || sectionGroup(cell.dataset.section));
                    if (ok && subject) ok = matchesExact(subject, cell.dataset.subject);
                    if (ok && q) ok = norm(cell.dataset.search).indexOf(q) !== -1;

                    var tr = cell.parentElement;
                    if (!tr) return;
                    tr.style.display = ok ? '' : 'none';
                    if (ok) shown++;
                });

                if (shownEl) shownEl.textContent = String(shown);
            }

            function clear() {
                if (searchEl) searchEl.value = '';
                if (ayEl) ayEl.value = defaults.ay || '';
                if (semEl) semEl.value = defaults.sem || '';
                if (courseEl) courseEl.value = '';
                if (majorEl) majorEl.value = '';
                if (yearEl) yearEl.value = '';
                if (sectionEl) sectionEl.value = '';
                if (subjectEl) subjectEl.value = '';
                updateSectionOptions();
                updateSubjectOptions();
                apply();
            }

            [searchEl, ayEl, semEl, courseEl, majorEl, yearEl, sectionEl, subjectEl].forEach(function (el) {
                if (!el) return;
                el.addEventListener('input', apply);
                el.addEventListener('change', apply);
            });
            clearEl && clearEl.addEventListener('click', clear);

            apply();
        })();
    </script>
</body>
</html>
