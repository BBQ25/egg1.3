<?php include '../layouts/session.php'; ?>
<?php include '../layouts/main.php'; ?>

<?php
$profileHelperPath = __DIR__ . '/../includes/profile.php';
if (is_file($profileHelperPath)) {
    require_once $profileHelperPath;
}

$role = isset($_SESSION['user_role']) ? (string) $_SESSION['user_role'] : '';
if ($role === 'teacher') {
    require_active_role('teacher');
} elseif ($role === 'admin') {
    require_role('admin');
} else {
    deny_access(403, 'Forbidden.');
}

$classRecordId = isset($_GET['class_record_id']) ? (int) $_GET['class_record_id'] : 0;
if ($classRecordId <= 0) {
    $_SESSION['flash_message'] = 'Invalid class record.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . ($role === 'admin' ? 'admin-assign-teachers.php' : 'teacher-dashboard.php'));
    exit;
}

$term = isset($_GET['term']) ? strtolower(trim((string) $_GET['term'])) : 'midterm';
if (!in_array($term, ['midterm', 'final'], true)) $term = 'midterm';
$termLabel = $term === 'final' ? 'Final Term' : 'Midterm';

$view = isset($_GET['view']) ? strtolower(trim((string) $_GET['view'])) : 'assessments';
if (!in_array($view, ['assessments', 'summary'], true)) $view = 'assessments';

$allowedRowsPerPage = [15, 20, 25, 30];
$rowsPerPage = isset($_GET['rows_per_page']) ? (int) $_GET['rows_per_page'] : 20;
if (!in_array($rowsPerPage, $allowedRowsPerPage, true)) $rowsPerPage = 20;
$hasRowsPerPageQuery = array_key_exists('rows_per_page', $_GET);

$allowedRankingBases = ['avg_trans_strict', 'avg_initial_tiebreak'];
$globalDefaultRankingBasis = 'avg_initial_tiebreak';
if (!in_array($globalDefaultRankingBasis, $allowedRankingBases, true)) $globalDefaultRankingBasis = 'avg_trans_strict';
$rankingBasis = isset($_GET['ranking_basis']) ? strtolower(trim((string) $_GET['ranking_basis'])) : $globalDefaultRankingBasis;
if (!in_array($rankingBasis, $allowedRankingBases, true)) $rankingBasis = $globalDefaultRankingBasis;
$hasRankingBasisQuery = array_key_exists('ranking_basis', $_GET);

$includeAnalyticsPrintRaw = strtolower(trim((string) ($_GET['include_analytics_print'] ?? '0')));
$includeAnalyticsPrint = in_array($includeAnalyticsPrintRaw, ['1', 'true', 'yes', 'on'], true);
$hasIncludeAnalyticsPrintQuery = array_key_exists('include_analytics_print', $_GET);

$teacherId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;

// Load class record context.
$ctx = null;
$stmt = $conn->prepare(
    "SELECT cr.id AS class_record_id,
            cr.subject_id, cr.teacher_id, cr.section, cr.academic_year, cr.semester, cr.year_level, cr.status AS class_status,
            s.subject_code, s.subject_name, s.course,
            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), ''), u.username) AS primary_teacher,
            u.useremail AS primary_email
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
    header('Location: ' . ($role === 'admin' ? 'admin-assign-teachers.php' : 'teacher-dashboard.php'));
    exit;
}

// Teacher authorization (admin can view any).
if ($role === 'teacher') {
    $ok = false;
    $auth = $conn->prepare(
        "SELECT 1
         FROM teacher_assignments
         WHERE teacher_id = ? AND class_record_id = ? AND status = 'active'
         LIMIT 1"
    );
    if ($auth) {
        $auth->bind_param('ii', $teacherId, $classRecordId);
        $auth->execute();
        $r = $auth->get_result();
        $ok = ($r && $r->num_rows === 1);
        $auth->close();
    }
    if (!$ok) {
        deny_access(403, 'Forbidden: not assigned to this class record.');
    }
}

$subjectId = (int) ($ctx['subject_id'] ?? 0);
$section = trim((string) ($ctx['section'] ?? ''));
$academicYear = trim((string) ($ctx['academic_year'] ?? ''));
$semester = trim((string) ($ctx['semester'] ?? ''));
$course = trim((string) ($ctx['course'] ?? ''));
if ($course === '') $course = 'N/A';
$yearLevel = trim((string) ($ctx['year_level'] ?? ''));
if ($yearLevel === '') $yearLevel = 'N/A';

// Load enrolled students.
$students = [];
$en = $conn->prepare(
    "SELECT ce.student_id,
            st.StudentNo AS student_no,
            st.Surname AS surname, st.FirstName AS firstname, st.MiddleName AS middlename
     FROM class_enrollments ce
     JOIN students st ON st.id = ce.student_id
     WHERE ce.class_record_id = ? AND ce.status = 'enrolled'
     ORDER BY st.Surname ASC, st.FirstName ASC, st.MiddleName ASC, st.StudentNo ASC"
);
if ($en) {
    $en->bind_param('i', $classRecordId);
    $en->execute();
    $res = $en->get_result();
    while ($res && ($r = $res->fetch_assoc())) $students[] = $r;
    $en->close();
}

// Find grading config for this term (read-only; do not auto-create).
$configId = 0;
$cfg = $conn->prepare(
    "SELECT id
     FROM section_grading_configs
     WHERE subject_id = ? AND course = ? AND year = ? AND section = ? AND academic_year = ? AND semester = ? AND term = ?
     LIMIT 1"
);
if ($cfg) {
    $cfg->bind_param('issssss', $subjectId, $course, $yearLevel, $section, $academicYear, $semester, $term);
    $cfg->execute();
    $res = $cfg->get_result();
    if ($res && $res->num_rows === 1) $configId = (int) ($res->fetch_assoc()['id'] ?? 0);
    $cfg->close();
}

$components = []; // each: id, name, code, weight, category, order
$assessments = []; // each: id, component_id, name, max_score, order
$scores = []; // [student_id][assessment_id] => score (float|null)

if ($configId > 0) {
    $q = $conn->prepare(
        "SELECT gc.id, gc.component_name, gc.component_code, gc.weight, gc.display_order,
                COALESCE(c.category_name, 'Uncategorized') AS category_name
         FROM grading_components gc
         LEFT JOIN grading_categories c ON c.id = gc.category_id
         WHERE gc.section_config_id = ? AND gc.is_active = 1
         ORDER BY gc.display_order ASC, gc.id ASC"
    );
    if ($q) {
        $q->bind_param('i', $configId);
        $q->execute();
        $res = $q->get_result();
        while ($res && ($r = $res->fetch_assoc())) $components[] = $r;
        $q->close();
    }

    $qa = $conn->prepare(
        "SELECT ga.id, ga.grading_component_id, ga.name, ga.max_score, ga.display_order,
                gc.component_code
         FROM grading_assessments ga
         JOIN grading_components gc ON gc.id = ga.grading_component_id
         WHERE gc.section_config_id = ? AND gc.is_active = 1 AND ga.is_active = 1
         ORDER BY gc.display_order ASC, gc.id ASC, ga.display_order ASC, ga.id ASC"
    );
    if ($qa) {
        $qa->bind_param('i', $configId);
        $qa->execute();
        $res = $qa->get_result();
        while ($res && ($r = $res->fetch_assoc())) $assessments[] = $r;
        $qa->close();
    }

    // Load scores for enrolled students + this term's assessments.
    $qs = $conn->prepare(
        "SELECT gas.assessment_id, gas.student_id, gas.score
         FROM grading_assessment_scores gas
         JOIN grading_assessments ga ON ga.id = gas.assessment_id
         JOIN grading_components gc ON gc.id = ga.grading_component_id
         JOIN class_enrollments ce
            ON ce.student_id = gas.student_id
           AND ce.class_record_id = ?
           AND ce.status = 'enrolled'
         WHERE gc.section_config_id = ? AND gc.is_active = 1 AND ga.is_active = 1"
    );
    if ($qs) {
        $qs->bind_param('ii', $classRecordId, $configId);
        $qs->execute();
        $res = $qs->get_result();
        while ($res && ($r = $res->fetch_assoc())) {
            $sid = (int) ($r['student_id'] ?? 0);
            $aid = (int) ($r['assessment_id'] ?? 0);
            $scores[$sid][$aid] = $r['score']; // may be null
        }
        $qs->close();
    }
}

// Index assessments by component.
$assessmentsByComponent = [];
$assessmentMax = []; // aid => max
$assessmentIsAttendance = []; // aid => bool
foreach ($assessments as $a) {
    $cid = (int) ($a['grading_component_id'] ?? 0);
    if (!isset($assessmentsByComponent[$cid])) $assessmentsByComponent[$cid] = [];
    $assessmentsByComponent[$cid][] = $a;
    $aid = (int) ($a['id'] ?? 0);
    $assessmentMax[$aid] = (float) ($a['max_score'] ?? 0);
    $assessmentIsAttendance[$aid] = strtoupper(trim((string) ($a['component_code'] ?? ''))) === 'ATTEND';
}

// Safety normalization: if a stored score exceeds max_score, treat 0..100 values as percentages.
foreach ($scores as $sid => $scoreMap) {
    if (!is_array($scoreMap)) continue;
    foreach ($scoreMap as $aid => $rawVal) {
        if ($rawVal === null || !is_numeric($rawVal)) continue;
        $mx = (float) ($assessmentMax[(int) $aid] ?? 0);
        if ($mx <= 0) continue;
        $val = (float) $rawVal;
        if ($val < 0) $val = 0.0;
        if ($val > $mx) {
            if ($val <= 100.0) {
                $val = round(($val / 100.0) * $mx, 2);
            } else {
                $val = $mx;
            }
        }
        if (!($assessmentIsAttendance[(int) $aid] ?? false)) {
            $val = round($val, 0);
        } else {
            // Preserve attendance half-step policy (0, 0.5, 1).
            $val = round($val * 2.0) / 2.0;
        }
        if ($val > $mx) $val = $mx;
        if ($val < 0) $val = 0.0;
        $scores[$sid][(int) $aid] = $val;
    }
}

function er_compute_student_grades(array $students, array $components, array $assessmentsByComponent, array $scores, $normalizeToSelectedWeight = false) {
    $studentGrades = [];
    foreach ($students as $st) {
        $sid = (int) ($st['student_id'] ?? 0);
        $termPoints = 0.0;
        $selectedWeight = 0.0;
        $compData = [];

        foreach ($components as $gc) {
            $cid = (int) ($gc['id'] ?? 0);
            $w = (float) ($gc['weight'] ?? 0);
            $list = $assessmentsByComponent[$cid] ?? [];
            $sumMax = 0.0;
            $sumScore = 0.0;

            foreach ($list as $a) {
                $aid = (int) ($a['id'] ?? 0);
                $mx = (float) ($a['max_score'] ?? 0);
                $sumMax += $mx;
                $val = $scores[$sid][$aid] ?? null;
                if ($val !== null && is_numeric($val)) $sumScore += (float) $val;
            }

            $pct = null;
            $pts = 0.0;
            if ($sumMax > 0) {
                $pct = ($sumScore / $sumMax) * 100.0;
                $pts = $pct * ($w / 100.0);
                $termPoints += $pts;
                $selectedWeight += $w;
            }

            $compData[$cid] = ['sum' => $sumScore, 'max' => $sumMax, 'pct' => $pct, 'pts' => $pts, 'weight' => $w];
        }

        $termGrade = $termPoints;
        if ($normalizeToSelectedWeight) {
            if ($selectedWeight > 0) {
                $termGrade = ($termPoints / $selectedWeight) * 100.0;
            } else {
                $termGrade = 0.0;
            }
        }

        $studentGrades[$sid] = ['term_grade' => $termGrade, 'components' => $compData];
    }
    return $studentGrades;
}

// Official/full grades (all assessments).
$studentGrades = er_compute_student_grades($students, $components, $assessmentsByComponent, $scores, false);

// Print column filtering (assessment columns only; grades always included).
$assessmentIds = [];
foreach ($assessments as $a) {
    $aid = (int) ($a['id'] ?? 0);
    if ($aid > 0) $assessmentIds[] = $aid;
}
$assessmentIdSet = [];
foreach ($assessmentIds as $aid) $assessmentIdSet[$aid] = true;

$hasAssessmentFilter = array_key_exists('print_cols_mode', $_GET) || array_key_exists('print_cols', $_GET);
$hasColumnStateInUrl = $hasAssessmentFilter
    || array_key_exists('show_student_no', $_GET)
    || array_key_exists('show_student_name', $_GET)
    || array_key_exists('show_primary_teacher', $_GET)
    || array_key_exists('show_students_count', $_GET)
    || array_key_exists('show_class_record_id', $_GET)
    || array_key_exists('show_generated_at', $_GET)
    || array_key_exists('show_course_year', $_GET)
    || array_key_exists('show_view_label', $_GET);
$selectedAssessmentIdSet = [];
if ($hasAssessmentFilter) {
    $rawCols = $_GET['print_cols'] ?? [];
    if (!is_array($rawCols)) $rawCols = [$rawCols];
    foreach ($rawCols as $rawCol) {
        if (is_array($rawCol)) continue;
        $rawCol = trim((string) $rawCol);
        if ($rawCol === '') continue;
        $parts = strpos($rawCol, ',') !== false ? explode(',', $rawCol) : [$rawCol];
        foreach ($parts as $part) {
            $aid = (int) trim((string) $part);
            if ($aid > 0 && isset($assessmentIdSet[$aid])) $selectedAssessmentIdSet[$aid] = true;
        }
    }
} else {
    foreach ($assessmentIds as $aid) $selectedAssessmentIdSet[$aid] = true;
}
$selectedAssessmentIds = array_map('intval', array_keys($selectedAssessmentIdSet));
sort($selectedAssessmentIds);

$showStudentNoRaw = strtolower(trim((string) ($_GET['show_student_no'] ?? '1')));
$showStudentNameRaw = strtolower(trim((string) ($_GET['show_student_name'] ?? '1')));
$showStudentNo = !in_array($showStudentNoRaw, ['0', 'false', 'off', 'no'], true);
$showStudentName = !in_array($showStudentNameRaw, ['0', 'false', 'off', 'no'], true);
if (!$showStudentNo && !$showStudentName) $showStudentName = true;
$showPrimaryTeacherRaw = strtolower(trim((string) ($_GET['show_primary_teacher'] ?? '1')));
$showStudentsCountRaw = strtolower(trim((string) ($_GET['show_students_count'] ?? '1')));
$showClassRecordIdRaw = strtolower(trim((string) ($_GET['show_class_record_id'] ?? '1')));
$showGeneratedAtRaw = strtolower(trim((string) ($_GET['show_generated_at'] ?? '1')));
$showCourseYearRaw = strtolower(trim((string) ($_GET['show_course_year'] ?? '1')));
$showViewLabelRaw = strtolower(trim((string) ($_GET['show_view_label'] ?? '1')));
$showPrimaryTeacher = !in_array($showPrimaryTeacherRaw, ['0', 'false', 'off', 'no'], true);
$showStudentsCount = !in_array($showStudentsCountRaw, ['0', 'false', 'off', 'no'], true);
$showClassRecordId = !in_array($showClassRecordIdRaw, ['0', 'false', 'off', 'no'], true);
$showGeneratedAt = !in_array($showGeneratedAtRaw, ['0', 'false', 'off', 'no'], true);
$showCourseYear = !in_array($showCourseYearRaw, ['0', 'false', 'off', 'no'], true);
$showViewLabel = !in_array($showViewLabelRaw, ['0', 'false', 'off', 'no'], true);
$printShowStudentFused = ($showStudentNo || $showStudentName);

$displayAssessments = [];
foreach ($assessments as $a) {
    $aid = (int) ($a['id'] ?? 0);
    if (isset($selectedAssessmentIdSet[$aid])) $displayAssessments[] = $a;
}
$displayAssessmentsByComponent = [];
foreach ($displayAssessments as $a) {
    $cid = (int) ($a['grading_component_id'] ?? 0);
    if (!isset($displayAssessmentsByComponent[$cid])) $displayAssessmentsByComponent[$cid] = [];
    $displayAssessmentsByComponent[$cid][] = $a;
}
$assessmentDisplayComponents = [];
foreach ($components as $gc) {
    $cid = (int) ($gc['id'] ?? 0);
    if (count($displayAssessmentsByComponent[$cid] ?? []) > 0) $assessmentDisplayComponents[] = $gc;
}

// Hidden columns affect print visibility only; grade calculations always use full assessments.
$studentGradesForAssessments = $studentGrades;

$totalAssessmentCols = count($displayAssessments);
$assessmentHeaderRowspan = $totalAssessmentCols > 0 ? 2 : 1;
$screenAssessmentHeaderRowspan = count($assessments) > 0 ? 2 : 1;
$printAssessmentTableColspan = ($printShowStudentFused ? 1 : 0) + 2 + $totalAssessmentCols;
$printColsQuery = '';
if ($hasAssessmentFilter) {
    $printColsQuery = '&print_cols_mode=1';
    foreach ($selectedAssessmentIds as $aid) $printColsQuery .= '&print_cols[]=' . (int) $aid;
}
$columnStateQuery = $printColsQuery
    . '&show_student_no=' . ($showStudentNo ? '1' : '0')
    . '&show_student_name=' . ($showStudentName ? '1' : '0')
    . '&show_primary_teacher=' . ($showPrimaryTeacher ? '1' : '0')
    . '&show_students_count=' . ($showStudentsCount ? '1' : '0')
    . '&show_class_record_id=' . ($showClassRecordId ? '1' : '0')
    . '&show_generated_at=' . ($showGeneratedAt ? '1' : '0')
    . '&show_course_year=' . ($showCourseYear ? '1' : '0')
    . '&show_view_label=' . ($showViewLabel ? '1' : '0')
    . '&rows_per_page=' . (int) $rowsPerPage
    . '&ranking_basis=' . urlencode($rankingBasis)
    . '&include_analytics_print=' . ($includeAnalyticsPrint ? '1' : '0');

// Keep requested view; column picker controls print width for assessments.

function h($v) { return htmlspecialchars((string) $v); }
function fmt2($n) { return rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.'); }
function er_render_signature_block($preparedByName, $preparedByRole, $approvedByName, $approvedByRole) {
    $preparedByName = trim((string) $preparedByName);
    $preparedByRole = trim((string) $preparedByRole);
    $approvedByName = trim((string) $approvedByName);
    $approvedByRole = trim((string) $approvedByRole);
    if ($preparedByName === '') $preparedByName = '____________________________';
    if ($approvedByName === '') $approvedByName = '____________________________';
    if ($preparedByRole === '') $preparedByRole = 'Subject Instructor';
    if ($approvedByRole === '') $approvedByRole = 'Program Chair';
    ?>
    <div class="er-signatures">
        <div class="er-signature-col">
            <div class="er-signature-label">Prepared by:</div>
            <div class="er-signature-name"><?php echo h(strtoupper($preparedByName)); ?></div>
            <div class="er-signature-role"><?php echo h($preparedByRole); ?></div>
        </div>
        <div class="er-signature-col">
            <div class="er-signature-label">Approved by:</div>
            <div class="er-signature-name"><?php echo h($approvedByName); ?></div>
            <div class="er-signature-role"><?php echo h($approvedByRole); ?></div>
        </div>
    </div>
    <?php
}
function er_fmt_score($n, $isAttendance = false) {
    if (!is_numeric($n)) return '';
    $v = (float) $n;
    if ($isAttendance) {
        $v = round($v * 2.0) / 2.0;
        if (abs($v - round($v, 0)) < 0.0001) return (string) ((int) round($v, 0));
        return number_format($v, 1, '.', '');
    }
    return (string) ((int) round($v, 0));
}
function er_initial_grade_value($n) {
    if (!is_numeric($n)) return 0.0;
    $v = (float) $n;
    if ($v < 0) $v = 0.0;
    if ($v > 100) $v = 100.0;
    return (float) round($v, 0);
}
function er_asset_version_token($absPath) {
    $hash = @md5_file($absPath);
    if (is_string($hash) && $hash !== '') return substr($hash, 0, 12);
    $mtime = @filemtime($absPath);
    $size = @filesize($absPath);
    return (string) (($mtime ?: 0) . '-' . ($size ?: 0));
}
function er_transmuted_grade($initialGrade) {
    if (!is_numeric($initialGrade)) return '';
    $score = (int) round((float) $initialGrade);
    if ($score <= 0) return '';
    if ($score < 1) $score = 1;
    if ($score > 100) $score = 100;

    if ($score <= 55) return '5.0';
    if ($score >= 99) return '1.0';
    if ($score >= 96) return '1.1';
    if ($score >= 93) return '1.2';

    $transmuted = 5.0 - (($score - 55) * 0.1);
    return number_format($transmuted, 1, '.', '');
}

function er_component_key(array $gc) {
    $code = strtoupper(trim((string) ($gc['component_code'] ?? '')));
    if ($code === 'QUIZ') return 'quiz';
    if ($code === 'ASSIGN') return 'assign';
    if ($code === 'ACT') return 'act';
    if ($code === 'RECIT') return 'recit';
    if ($code === 'ATTEND') return 'attend';
    if ($code === 'PROJ') return 'proj';
    if ($code === 'TEXAM') return 'texam';

    $type = strtolower(trim((string) ($gc['component_type'] ?? '')));
    $name = strtolower(trim((string) ($gc['component_name'] ?? '')));
    $hay = $name . ' ' . $type;

    if (strpos($hay, 'attendance') !== false || strpos($hay, 'attend') !== false) return 'attend';
    if (strpos($hay, 'project') !== false) return 'proj';
    if (strpos($hay, 'exam') !== false || strpos($hay, 'term exam') !== false) return 'texam';
    if (strpos($hay, 'quiz') !== false) return 'quiz';
    if (strpos($hay, 'recit') !== false || strpos($hay, 'particip') !== false) return 'recit';
    if (strpos($hay, 'activity') !== false || strpos($hay, 'practical') !== false || strpos($hay, 'performance') !== false) return 'act';
    if (strpos($hay, 'assign') !== false || strpos($hay, 'written work') !== false || strpos($hay, 'written') !== false) return 'assign';

    if ($type === 'quiz') return 'quiz';
    if ($type === 'assignment') return 'assign';
    if ($type === 'project') return 'proj';
    if ($type === 'exam') return 'texam';
    if ($type === 'participation') return 'act';

    return 'default';
}

function er_component_header_class(array $gc) {
    $key = er_component_key($gc);
    switch ($key) {
        case 'quiz': return 'er-comp-quiz-h';
        case 'assign': return 'er-comp-assign-h';
        case 'act': return 'er-comp-act-h';
        case 'recit': return 'er-comp-recit-h';
        case 'attend': return 'er-comp-attend-h';
        case 'proj': return 'er-comp-proj-h';
        case 'texam': return 'er-comp-texam-h';
        default: return 'er-comp-default-h';
    }
}

function er_component_cell_class(array $gc) {
    $key = er_component_key($gc);
    switch ($key) {
        case 'quiz': return 'er-comp-quiz-c';
        case 'assign': return 'er-comp-assign-c';
        case 'act': return 'er-comp-act-c';
        case 'recit': return 'er-comp-recit-c';
        case 'attend': return 'er-comp-attend-c';
        case 'proj': return 'er-comp-proj-c';
        case 'texam': return 'er-comp-texam-c';
        default: return 'er-comp-default-c';
    }
}

function er_compact_assessment_label($name) {
    $label = trim((string) $name);
    if ($label === '') return '';

    $label = preg_replace('/\s+/', ' ', $label);
    if (!is_string($label) || $label === '') return trim((string) $name);

    $abbrMap = [
        'attendance' => 'Att',
        'assignment' => 'Asg',
        'activity' => 'Act',
        'recitation' => 'Rec',
        'quiz' => 'Quiz',
        'project' => 'Proj',
        'term exam' => 'Exam',
        'exam' => 'Exam',
    ];

    $lower = strtolower($label);
    foreach ($abbrMap as $long => $short) {
        if (strpos($lower, $long) === 0) {
            if (preg_match('/(\d+)\s*$/', $label, $m)) {
                return $short . ' ' . $m[1];
            }
            return $short;
        }
    }

    return $label;
}

function er_load_term_analytics_snapshot(
    mysqli $conn,
    int $classRecordId,
    int $subjectId,
    string $course,
    string $yearLevel,
    string $section,
    string $academicYear,
    string $semester,
    string $term,
    array $students
): array {
    $result = [
        'initial_raw' => [],
        'initial' => [],
        'transmuted' => [],
        'component_pct' => [],
    ];

    if ($classRecordId <= 0 || $subjectId <= 0) return $result;

    $configId = 0;
    $cfg = $conn->prepare(
        "SELECT id
         FROM section_grading_configs
         WHERE subject_id = ? AND course = ? AND year = ? AND section = ? AND academic_year = ? AND semester = ? AND term = ?
         LIMIT 1"
    );
    if (!$cfg) return $result;
    $cfg->bind_param('issssss', $subjectId, $course, $yearLevel, $section, $academicYear, $semester, $term);
    $cfg->execute();
    $cfgRes = $cfg->get_result();
    if ($cfgRes && $cfgRes->num_rows === 1) {
        $configId = (int) (($cfgRes->fetch_assoc()['id'] ?? 0));
    }
    $cfg->close();
    if ($configId <= 0) return $result;

    $components = [];
    $componentCodeById = [];
    $compQ = $conn->prepare(
        "SELECT gc.id, gc.component_name, gc.component_code, gc.weight, gc.display_order,
                COALESCE(c.category_name, 'Uncategorized') AS category_name
         FROM grading_components gc
         LEFT JOIN grading_categories c ON c.id = gc.category_id
         WHERE gc.section_config_id = ? AND gc.is_active = 1
         ORDER BY gc.display_order ASC, gc.id ASC"
    );
    if (!$compQ) return $result;
    $compQ->bind_param('i', $configId);
    $compQ->execute();
    $compRes = $compQ->get_result();
    while ($compRes && ($row = $compRes->fetch_assoc())) {
        $components[] = $row;
        $componentCodeById[(int) ($row['id'] ?? 0)] = strtoupper(trim((string) ($row['component_code'] ?? '')));
    }
    $compQ->close();
    if (count($components) === 0) return $result;

    $assessmentsByComponent = [];
    $assessmentMax = [];
    $assessmentIsAttendance = [];
    $asQ = $conn->prepare(
        "SELECT ga.id, ga.grading_component_id, ga.name, ga.max_score, ga.display_order
         FROM grading_assessments ga
         JOIN grading_components gc ON gc.id = ga.grading_component_id
         WHERE gc.section_config_id = ? AND gc.is_active = 1 AND ga.is_active = 1
         ORDER BY gc.display_order ASC, gc.id ASC, ga.display_order ASC, ga.id ASC"
    );
    if (!$asQ) return $result;
    $asQ->bind_param('i', $configId);
    $asQ->execute();
    $asRes = $asQ->get_result();
    while ($asRes && ($row = $asRes->fetch_assoc())) {
        $cid = (int) ($row['grading_component_id'] ?? 0);
        if (!isset($assessmentsByComponent[$cid])) $assessmentsByComponent[$cid] = [];
        $assessmentsByComponent[$cid][] = $row;
        $aid = (int) ($row['id'] ?? 0);
        $assessmentMax[$aid] = (float) ($row['max_score'] ?? 0);
        $assessmentIsAttendance[$aid] = (($componentCodeById[$cid] ?? '') === 'ATTEND');
    }
    $asQ->close();

    $scores = [];
    $scoreQ = $conn->prepare(
        "SELECT gas.assessment_id, gas.student_id, gas.score
         FROM grading_assessment_scores gas
         JOIN grading_assessments ga ON ga.id = gas.assessment_id
         JOIN grading_components gc ON gc.id = ga.grading_component_id
         JOIN class_enrollments ce
            ON ce.student_id = gas.student_id
           AND ce.class_record_id = ?
           AND ce.status = 'enrolled'
         WHERE gc.section_config_id = ? AND gc.is_active = 1 AND ga.is_active = 1"
    );
    if ($scoreQ) {
        $scoreQ->bind_param('ii', $classRecordId, $configId);
        $scoreQ->execute();
        $scoreRes = $scoreQ->get_result();
        while ($scoreRes && ($row = $scoreRes->fetch_assoc())) {
            $sid = (int) ($row['student_id'] ?? 0);
            $aid = (int) ($row['assessment_id'] ?? 0);
            $scores[$sid][$aid] = $row['score'];
        }
        $scoreQ->close();
    }

    // Align with runtime score rules (non-attendance whole number; attendance half-step).
    foreach ($scores as $sid => $scoreMap) {
        if (!is_array($scoreMap)) continue;
        foreach ($scoreMap as $aid => $rawVal) {
            if ($rawVal === null || !is_numeric($rawVal)) continue;
            $mx = (float) ($assessmentMax[(int) $aid] ?? 0);
            if ($mx <= 0) continue;
            $val = (float) $rawVal;
            if ($val < 0) $val = 0.0;
            if ($val > $mx) {
                if ($val <= 100.0) $val = round(($val / 100.0) * $mx, 2);
                else $val = $mx;
            }
            if (!($assessmentIsAttendance[(int) $aid] ?? false)) $val = round($val, 0);
            else $val = round($val * 2.0) / 2.0;
            if ($val > $mx) $val = $mx;
            if ($val < 0) $val = 0.0;
            $scores[$sid][(int) $aid] = $val;
        }
    }

    $studentGrades = er_compute_student_grades($students, $components, $assessmentsByComponent, $scores, false);
    foreach ($students as $st) {
        $sid = (int) ($st['student_id'] ?? 0);
        $g = $studentGrades[$sid] ?? ['term_grade' => 0.0, 'components' => []];
        $initialRaw = (float) ($g['term_grade'] ?? 0);
        $result['initial_raw'][$sid] = $initialRaw;
        $result['initial'][$sid] = er_initial_grade_value($initialRaw);
        $result['transmuted'][$sid] = er_transmuted_grade($initialRaw);

        foreach ($components as $gc) {
            $cid = (int) ($gc['id'] ?? 0);
            $code = strtoupper(trim((string) ($gc['component_code'] ?? '')));
            if ($code === '') continue;
            $pct = $g['components'][$cid]['pct'] ?? null;
            if ($pct !== null && is_numeric($pct)) {
                $result['component_pct'][$sid][$code] = (float) $pct;
            }
        }
    }

    return $result;
}

$transmutedGradeColLabel = $term === 'final' ? 'Final Term Grade' : 'Midterm Grade';
$summaryTransmutedColLabel = $term === 'final' ? 'Subject Average' : 'Midterm Grade';
$erHeaderPath = __DIR__ . '/../assets/images/report-template/header-strip-template.png';
$erQsPath = __DIR__ . '/../assets/images/report-template/image17.png';
$erSocotecPath = __DIR__ . '/../assets/images/report-template/image18.png';
$erHeaderVer = er_asset_version_token($erHeaderPath);
$erQsVer = er_asset_version_token($erQsPath);
$erSocotecVer = er_asset_version_token($erSocotecPath);
$erHeaderSrc = 'assets/images/report-template/header-strip-template.png' . ($erHeaderVer !== '' ? ('?v=' . $erHeaderVer) : '');
$erQsSrc = 'assets/images/report-template/image17.png' . ($erQsVer !== '' ? ('?v=' . $erQsVer) : '');
$erSocotecSrc = 'assets/images/report-template/image18.png' . ($erSocotecVer !== '' ? ('?v=' . $erSocotecVer) : '');
$preparedByName = trim((string) ($ctx['primary_teacher'] ?? ''));
if ($preparedByName === '') $preparedByName = '____________________________';
$preparedByRole = 'Subject Instructor';
$approvedByName = '';
$approvedByRole = 'Program Chair';
$classTeacherOwnerId = (int) ($ctx['teacher_id'] ?? 0);
$subjectCodeForApproval = trim((string) ($ctx['subject_code'] ?? ''));
$subjectNameForApproval = trim((string) ($ctx['subject_name'] ?? ''));
$approvalSubjectLabels = [];
if ($subjectCodeForApproval !== '' && $subjectNameForApproval !== '') {
    $approvalSubjectLabels[] = $subjectCodeForApproval . ' - ' . $subjectNameForApproval;
}
if ($subjectNameForApproval !== '') $approvalSubjectLabels[] = $subjectNameForApproval;
if ($subjectCodeForApproval !== '') $approvalSubjectLabels[] = $subjectCodeForApproval;
if (function_exists('profile_resolve_program_chair_for_subjects') && $classTeacherOwnerId > 0 && count($approvalSubjectLabels) > 0) {
    $resolvedProgramChair = profile_resolve_program_chair_for_subjects($conn, $classTeacherOwnerId, $approvalSubjectLabels);
    if (!empty($resolvedProgramChair['has_assignment'])) {
        $approvedByName = trim((string) ($resolvedProgramChair['program_chair_display_name'] ?? ''));
    }
    if (!empty($resolvedProgramChair['multiple'])) {
        $approvedByRole = 'Program Chair (Per Subject)';
    }
}
if ($approvedByName === '') $approvedByName = '____________________________';
$printTitle = 'Class Record - ' . ($ctx['subject_code'] ?? '') . ' - ' . ($ctx['section'] ?? '') . ' - ' . $termLabel;
$rowsPerPrintPage = $rowsPerPage;
$studentPages = array_chunk($students, $rowsPerPrintPage);
if (count($studentPages) === 0) $studentPages = [[]];

$analyticsEnabled = ($view === 'summary');
$analyticsRows = [];
$analyticsTop10 = [];
$subjectAverageTransByStudent = [];
$rankingBasisLabel = $rankingBasis === 'avg_initial_tiebreak'
    ? 'AVG Initial (desc) with tie-breakers'
    : 'AVG Transmuted (asc) strict';
$analyticsClassMetrics = [
    'mt_avg_trans' => '',
    'ft_avg_trans' => '',
    'subject_avg_trans' => '',
];
$analyticsChartData = null;

if ($analyticsEnabled && count($students) > 0) {
    $midSnapshot = er_load_term_analytics_snapshot(
        $conn,
        (int) $classRecordId,
        (int) $subjectId,
        (string) $course,
        (string) $yearLevel,
        (string) $section,
        (string) $academicYear,
        (string) $semester,
        'midterm',
        $students
    );
    $finalSnapshot = er_load_term_analytics_snapshot(
        $conn,
        (int) $classRecordId,
        (int) $subjectId,
        (string) $course,
        (string) $yearLevel,
        (string) $section,
        (string) $academicYear,
        (string) $semester,
        'final',
        $students
    );

    foreach ($students as $st) {
        $sid = (int) ($st['student_id'] ?? 0);
        $studentName = trim((string) ($st['surname'] ?? '') . ', ' . (string) ($st['firstname'] ?? '') . ' ' . (string) ($st['middlename'] ?? ''));
        $studentShort = trim((string) ($st['surname'] ?? ''));
        if ($studentShort === '') $studentShort = $studentName;

        $mtInitialRaw = (float) ($midSnapshot['initial_raw'][$sid] ?? 0.0);
        $ftInitialRaw = (float) ($finalSnapshot['initial_raw'][$sid] ?? 0.0);
        $avgInitialRaw = ($mtInitialRaw + $ftInitialRaw) / 2.0;

        $mtTrans = (string) ($midSnapshot['transmuted'][$sid] ?? '');
        $ftTrans = (string) ($finalSnapshot['transmuted'][$sid] ?? '');
        $avgTrans = '';
        if (is_numeric($mtTrans) && is_numeric($ftTrans)) {
            $avgTrans = number_format((((float) $mtTrans) + ((float) $ftTrans)) / 2.0, 1, '.', '');
        } elseif (is_numeric($mtTrans)) {
            $avgTrans = number_format((float) $mtTrans, 1, '.', '');
        } elseif (is_numeric($ftTrans)) {
            $avgTrans = number_format((float) $ftTrans, 1, '.', '');
        }

        $attValues = [];
        if (isset($midSnapshot['component_pct'][$sid]['ATTEND']) && is_numeric($midSnapshot['component_pct'][$sid]['ATTEND'])) {
            $attValues[] = (float) $midSnapshot['component_pct'][$sid]['ATTEND'];
        }
        if (isset($finalSnapshot['component_pct'][$sid]['ATTEND']) && is_numeric($finalSnapshot['component_pct'][$sid]['ATTEND'])) {
            $attValues[] = (float) $finalSnapshot['component_pct'][$sid]['ATTEND'];
        }
        $attendanceAvg = count($attValues) > 0 ? (array_sum($attValues) / (float) count($attValues)) : 0.0;
        $consistency = 100.0 - (abs($mtInitialRaw - $ftInitialRaw) * 2.0);
        if ($consistency < 0) $consistency = 0.0;
        if ($consistency > 100) $consistency = 100.0;

        $analyticsRows[] = [
            'student_id' => $sid,
            'student_no' => (string) ($st['student_no'] ?? ''),
            'student_name' => $studentName,
            'student_short' => $studentShort,
            'mt_initial_raw' => $mtInitialRaw,
            'ft_initial_raw' => $ftInitialRaw,
            'avg_initial_raw' => $avgInitialRaw,
            'mt_trans' => $mtTrans,
            'ft_trans' => $ftTrans,
            'avg_trans' => $avgTrans,
            'consistency' => $consistency,
            'attendance_avg' => $attendanceAvg,
        ];
        $subjectAverageTransByStudent[$sid] = $avgTrans;
    }

    usort($analyticsRows, function (array $a, array $b) use ($rankingBasis): int {
        $aTransNumeric = is_numeric($a['avg_trans'] ?? null);
        $bTransNumeric = is_numeric($b['avg_trans'] ?? null);
        $aTrans = $aTransNumeric ? (float) $a['avg_trans'] : 99.9;
        $bTrans = $bTransNumeric ? (float) $b['avg_trans'] : 99.9;
        $aInitial = (float) ($a['avg_initial_raw'] ?? 0.0);
        $bInitial = (float) ($b['avg_initial_raw'] ?? 0.0);
        $aFt = (float) ($a['ft_initial_raw'] ?? 0.0);
        $bFt = (float) ($b['ft_initial_raw'] ?? 0.0);
        $aMt = (float) ($a['mt_initial_raw'] ?? 0.0);
        $bMt = (float) ($b['mt_initial_raw'] ?? 0.0);

        if ($rankingBasis === 'avg_initial_tiebreak') {
            if (abs($aInitial - $bInitial) > 0.0001) return $aInitial > $bInitial ? -1 : 1;
            if (abs($aTrans - $bTrans) > 0.0001) return $aTrans < $bTrans ? -1 : 1;
            if (abs($aFt - $bFt) > 0.0001) return $aFt > $bFt ? -1 : 1;
            if (abs($aMt - $bMt) > 0.0001) return $aMt > $bMt ? -1 : 1;
            return strcmp((string) ($a['student_name'] ?? ''), (string) ($b['student_name'] ?? ''));
        }

        // Strict mode: rank by AVG transmuted ascending only (with deterministic name tie fallback).
        if (abs($aTrans - $bTrans) > 0.0001) return $aTrans < $bTrans ? -1 : 1;
        return strcmp((string) ($a['student_name'] ?? ''), (string) ($b['student_name'] ?? ''));
    });

    $analyticsTop10 = array_slice($analyticsRows, 0, 10);
    foreach ($analyticsTop10 as $i => $row) {
        $analyticsTop10[$i]['rank'] = $i + 1;
    }

    $mtSum = 0.0; $mtCnt = 0;
    $ftSum = 0.0; $ftCnt = 0;
    $avgSum = 0.0; $avgCnt = 0;
    foreach ($analyticsRows as $row) {
        if (is_numeric($row['mt_trans'] ?? null)) { $mtSum += (float) $row['mt_trans']; $mtCnt++; }
        if (is_numeric($row['ft_trans'] ?? null)) { $ftSum += (float) $row['ft_trans']; $ftCnt++; }
        if (is_numeric($row['avg_trans'] ?? null)) { $avgSum += (float) $row['avg_trans']; $avgCnt++; }
    }
    $analyticsClassMetrics = [
        'mt_avg_trans' => $mtCnt > 0 ? number_format($mtSum / (float) $mtCnt, 2, '.', '') : '',
        'ft_avg_trans' => $ftCnt > 0 ? number_format($ftSum / (float) $ftCnt, 2, '.', '') : '',
        'subject_avg_trans' => $avgCnt > 0 ? number_format($avgSum / (float) $avgCnt, 2, '.', '') : '',
    ];

    $bandLabels = ['1.0-1.5', '1.6-2.0', '2.1-2.5', '2.6-3.0', '3.1+ / INC'];
    $mtBandCounts = [0, 0, 0, 0, 0];
    $ftBandCounts = [0, 0, 0, 0, 0];
    $avgBandCounts = [0, 0, 0, 0, 0];
    $passCnt = 0;
    $condCnt = 0;
    $riskCnt = 0;
    $bandIndexForGrade = function ($gradeValue): int {
        if (!is_numeric($gradeValue)) return 4;
        $g = (float) $gradeValue;
        if ($g <= 1.5) return 0;
        if ($g <= 2.0) return 1;
        if ($g <= 2.5) return 2;
        if ($g <= 3.0) return 3;
        return 4;
    };

    foreach ($analyticsRows as $row) {
        $mtBandCounts[$bandIndexForGrade($row['mt_trans'] ?? null)]++;
        $ftBandCounts[$bandIndexForGrade($row['ft_trans'] ?? null)]++;
        $avgBandCounts[$bandIndexForGrade($row['avg_trans'] ?? null)]++;
    }

    foreach ($analyticsRows as $row) {
        $avgTrans = $row['avg_trans'] ?? '';
        if (!is_numeric($avgTrans)) {
            $riskCnt++;
            continue;
        }
        $g = (float) $avgTrans;
        if ($g <= 3.0) $passCnt++;
        elseif ($g <= 3.5) $condCnt++;
        else $riskCnt++;
    }

    $totalRows = max(1, count($analyticsRows));
    $radialSeries = [
        round(($passCnt / (float) $totalRows) * 100.0, 2),
        round(($condCnt / (float) $totalRows) * 100.0, 2),
        round(($riskCnt / (float) $totalRows) * 100.0, 2),
    ];

    $radarCategories = ['MT Initial', 'FT Initial', 'Subject Avg', 'Consistency', 'Attendance'];
    $radarSeries = [];
    $classCount = max(1, count($analyticsRows));
    $classMtRawSum = 0.0;
    $classFtRawSum = 0.0;
    $classAvgRawSum = 0.0;
    $classConsistencySum = 0.0;
    $classAttendanceSum = 0.0;
    foreach ($analyticsRows as $row) {
        $classMtRawSum += (float) ($row['mt_initial_raw'] ?? 0.0);
        $classFtRawSum += (float) ($row['ft_initial_raw'] ?? 0.0);
        $classAvgRawSum += (float) ($row['avg_initial_raw'] ?? 0.0);
        $classConsistencySum += (float) ($row['consistency'] ?? 0.0);
        $classAttendanceSum += (float) ($row['attendance_avg'] ?? 0.0);
    }
    $radarSeries[] = [
        'name' => 'Whole Class',
        'data' => [
            round($classMtRawSum / $classCount, 2),
            round($classFtRawSum / $classCount, 2),
            round($classAvgRawSum / $classCount, 2),
            round($classConsistencySum / $classCount, 2),
            round($classAttendanceSum / $classCount, 2),
        ],
    ];
    if (count($analyticsTop10) > 0) {
        $topCnt = count($analyticsTop10);
        $topMt = 0.0; $topFt = 0.0; $topAvg = 0.0; $topCons = 0.0; $topAtt = 0.0;
        foreach ($analyticsTop10 as $row) {
            $topMt += (float) ($row['mt_initial_raw'] ?? 0.0);
            $topFt += (float) ($row['ft_initial_raw'] ?? 0.0);
            $topAvg += (float) ($row['avg_initial_raw'] ?? 0.0);
            $topCons += (float) ($row['consistency'] ?? 0.0);
            $topAtt += (float) ($row['attendance_avg'] ?? 0.0);
        }
        $radarSeries[] = [
            'name' => 'Top 10',
            'data' => [
                round($topMt / $topCnt, 2),
                round($topFt / $topCnt, 2),
                round($topAvg / $topCnt, 2),
                round($topCons / $topCnt, 2),
                round($topAtt / $topCnt, 2),
            ],
        ];
    }

    $heatmapSeries = [];
    foreach ($analyticsRows as $idx => $row) {
        $shortName = trim((string) ($row['student_short'] ?? ''));
        if ($shortName === '') $shortName = trim((string) ($row['student_no'] ?? ''));
        if (strlen($shortName) > 12) $shortName = substr($shortName, 0, 12);
        $seriesLabel = 'R' . ((int) $idx + 1) . ' ' . $shortName;
        $heatmapSeries[] = [
            'name' => $seriesLabel,
            'data' => [
                ['x' => 'MT', 'y' => round((float) ($row['mt_initial_raw'] ?? 0.0), 2)],
                ['x' => 'FT', 'y' => round((float) ($row['ft_initial_raw'] ?? 0.0), 2)],
                ['x' => 'AVG', 'y' => round((float) ($row['avg_initial_raw'] ?? 0.0), 2)],
            ],
        ];
    }

    $contourPoints = [];
    foreach ($analyticsRows as $row) {
        $contourPoints[] = [
            'x' => round((float) ($row['mt_initial_raw'] ?? 0.0), 2),
            'y' => round((float) ($row['ft_initial_raw'] ?? 0.0), 2),
        ];
    }

    $analyticsChartData = [
        'column' => [
            'categories' => $bandLabels,
            'series' => [
                ['name' => 'MT', 'data' => $mtBandCounts],
                ['name' => 'FT', 'data' => $ftBandCounts],
                ['name' => 'Subject AVG', 'data' => $avgBandCounts],
            ],
        ],
        'radial' => [
            'labels' => ['Passed (<=3.0)', "Cond'l (3.1-3.5)", 'At Risk / INC'],
            'series' => $radialSeries,
        ],
        'radar' => [
            'categories' => $radarCategories,
            'series' => $radarSeries,
        ],
        'heatmap' => [
            'series' => $heatmapSeries,
        ],
        'pie' => [
            'labels' => $bandLabels,
            'series' => $avgBandCounts,
        ],
        'contour' => [
            'points' => $contourPoints,
            'x_min' => 50,
            'x_max' => 100,
            'y_min' => 50,
            'y_max' => 100,
        ],
        'meta' => [
            'ranking_basis_label' => $rankingBasisLabel,
            'class_size' => count($analyticsRows),
        ],
    ];
}
?>

<head>
    <title><?php echo h($printTitle); ?> | E-Record</title>
    <?php include '../layouts/title-meta.php'; ?>
    <?php include '../layouts/head-css.php'; ?>
    <style>
        :root {
            --er-page-w: 297mm;
            --er-template-page-px-w: 1650;
            --er-template-header-px-w: 1050;
        }
        /* Screen layout: keep it readable; printing uses @media print overrides. */
        .er-print-bar {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }
        .er-print-bar .btn-group {
            flex-wrap: wrap;
        }
        .er-rpp-picker {
            min-width: 170px;
        }
        .er-rank-picker {
            min-width: 240px;
        }
        .er-rpp-picker .input-group-text {
            font-size: 12px;
        }
        .er-rank-picker .input-group-text {
            font-size: 12px;
        }
        .er-inline-check {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin: 0;
            font-weight: 600;
            cursor: default;
        }
        .er-inline-check .form-check-input {
            margin: 0;
            float: none;
            flex: 0 0 auto;
        }
        .er-inline-check .form-check-input.er-visibility-toggle {
            position: absolute;
            opacity: 0;
            pointer-events: none;
            width: 0;
            height: 0;
        }
        .er-inline-check .form-check-label {
            margin: 0;
            font-weight: 600;
            line-height: 1.15;
            user-select: none;
            pointer-events: none;
        }
        .er-inline-check .er-visibility-icon {
            font-size: 14px;
            line-height: 1;
            color: currentColor;
            opacity: 0.88;
            margin-left: 2px;
            cursor: pointer;
        }
        .er-inline-check.comp {
            color: inherit;
        }
        .er-inline-check.assess {
            font-size: 12px;
        }
        .er-column-picker {
            width: 100%;
            border: 1px solid rgba(0,0,0,0.12);
            border-radius: 8px;
            padding: 10px;
            background: #fff;
        }
        .er-column-picker-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 8px;
            max-height: 260px;
            overflow: auto;
        }
        .er-column-picker-group {
            border: 1px solid rgba(0,0,0,0.08);
            border-radius: 6px;
            padding: 6px 8px;
            background: #fafbfc;
        }
        .er-column-picker .form-check {
            margin-bottom: 4px;
        }
        .er-column-picker .form-check-label {
            font-size: 12px;
        }
        .er-display-panel {
            border: 1px solid rgba(15, 23, 42, 0.12);
            border-radius: 8px;
            padding: 6px 8px;
            background: #f8fafc;
            width: 100%;
        }
        .er-display-panel > summary {
            cursor: pointer;
            font-size: 12px;
            font-weight: 700;
            list-style: none;
            user-select: none;
        }
        .er-display-panel > summary::-webkit-details-marker {
            display: none;
        }
        .er-display-panel[open] > summary {
            margin-bottom: 6px;
        }
        .er-display-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 6px 10px;
        }
        .er-sheet {
            background: #fff;
            border: 1px solid rgba(0,0,0,0.12);
            border-radius: 10px;
            padding: 12px;
        }
        .er-headline {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            align-items: start;
            gap: 8px;
        }
        .er-headline-title {
            text-align: center;
            min-width: 420px;
            border: 0;
            padding: 0;
            background: transparent;
            border-radius: 0;
        }
        .er-headline-main {
            margin: 0;
            font-size: 14pt;
            line-height: 1.15;
            font-weight: 700;
            letter-spacing: 0.2px;
            color: #0f172a;
            font-family: "Cambria Math", "Cambria", "Times New Roman", serif;
        }
        .er-headline-sub {
            margin: 2px 0 0;
            line-height: 1.15;
            font-size: 12pt;
            color: #0f172a;
            font-family: "Cambria Math", "Cambria", "Times New Roman", serif;
        }
        .er-headline-right {
            text-align: right;
            justify-self: end;
            min-width: 170px;
        }
        .er-meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4px 14px;
            font-size: 12px;
        }
        .er-meta strong { font-weight: 700; }
        .er-class-table th, .er-class-table td { vertical-align: middle; }
        .er-class-table thead th { background: #f7f7f9; }
        .er-slsu-header {
            margin: 0 0 8px 0;
            text-align: center;
        }
        .er-slsu-header-strip {
            width: calc(var(--er-page-w) * var(--er-template-header-px-w) / var(--er-template-page-px-w));
            max-width: 100%;
            height: auto;
            object-fit: contain;
            display: block;
            margin: 0 auto;
        }
        .er-slsu-footer {
            margin-top: 10px;
            display: flex;
            justify-content: flex-end;
            align-items: flex-end;
            gap: 2.6mm;
            line-height: 1;
        }
        .er-slsu-footer-qs {
            width: auto;
            height: 17.5mm;
            object-fit: contain;
            display: block;
        }
        .er-slsu-footer-socotec {
            width: 35mm;
            max-width: none;
            height: auto;
            object-fit: contain;
            display: block;
        }
        .er-signatures {
            margin-top: 7mm;
            display: grid;
            grid-template-columns: 1fr 1fr;
            column-gap: 16mm;
            align-items: end;
            font-size: 11pt;
            font-family: "Cambria Math", "Cambria", "Times New Roman", serif;
        }
        .er-signature-label {
            margin-bottom: 8mm;
            color: #111827;
        }
        .er-signature-name {
            font-size: 11pt;
            font-weight: 700;
            text-decoration: underline;
            text-underline-offset: 2px;
            min-height: 17px;
        }
        .er-signature-role {
            margin-top: 2px;
        }
        .er-print-signatures {
            display: none;
        }
        .er-print-page + .er-print-page {
            margin-top: 14px;
        }
        .er-print-body {
            display: block;
        }
        .er-slsu-footer-print {
            display: flex;
        }
        .er-screen-only {
            display: block;
        }
        .er-print-only {
            display: none;
        }
        .er-tight { font-size: 12px; }
        .er-col-student { min-width: 240px; }
        .er-col-student-fused { min-width: 260px; }
        .er-col-sn { min-width: 120px; }
        .er-col-grade { min-width: 90px; }
        .er-col-assess { min-width: 84px; }
        .er-small { font-size: 11px; color: #6c757d; }
        .er-cell-student-fused .er-student-name-line {
            font-weight: 600;
            line-height: 1.15;
            font-size: 12px;
        }
        .er-cell-student-fused .er-student-no-line {
            font-size: 9px;
            line-height: 1.05;
            color: #64748b;
            margin-top: 1px;
        }
        .er-class-table .er-grade-col {
            background: #f8fafc;
            font-weight: 700;
        }
        .er-class-table .er-comp-quiz-h {
            background: #0b5ed7 !important;
            color: #ffffff;
        }
        .er-class-table .er-comp-assign-h {
            background: #08a44f !important;
            color: #ffffff;
        }
        .er-class-table .er-comp-act-h {
            background: #f4b400 !important;
            color: #111827;
        }
        .er-class-table .er-comp-recit-h {
            background: #ff007f !important;
            color: #ffffff;
        }
        .er-class-table .er-comp-attend-h {
            background: #10a9df !important;
            color: #ffffff;
        }
        .er-class-table .er-comp-proj-h {
            background: #14a2c8 !important;
            color: #ffffff;
        }
        .er-class-table .er-comp-texam-h {
            background: #f7e300 !important;
            color: #111827;
        }
        .er-class-table .er-comp-default-h {
            background: #e5e7eb !important;
            color: #111827;
        }
        .er-class-table td.er-comp-quiz-c,
        .er-class-table th.er-comp-quiz-c {
            --bs-table-bg: #e9f2ff;
            --bs-table-accent-bg: #e9f2ff;
            background-color: #e9f2ff !important;
        }
        .er-class-table td.er-comp-assign-c,
        .er-class-table th.er-comp-assign-c {
            --bs-table-bg: #eaf9ef;
            --bs-table-accent-bg: #eaf9ef;
            background-color: #eaf9ef !important;
        }
        .er-class-table td.er-comp-act-c,
        .er-class-table th.er-comp-act-c {
            --bs-table-bg: #fff8db;
            --bs-table-accent-bg: #fff8db;
            background-color: #fff8db !important;
        }
        .er-class-table td.er-comp-recit-c,
        .er-class-table th.er-comp-recit-c {
            --bs-table-bg: #ffe6f3;
            --bs-table-accent-bg: #ffe6f3;
            background-color: #ffe6f3 !important;
        }
        .er-class-table td.er-comp-attend-c,
        .er-class-table th.er-comp-attend-c {
            --bs-table-bg: #e8f8ff;
            --bs-table-accent-bg: #e8f8ff;
            background-color: #e8f8ff !important;
        }
        .er-class-table td.er-comp-proj-c,
        .er-class-table th.er-comp-proj-c {
            --bs-table-bg: #e7f7fc;
            --bs-table-accent-bg: #e7f7fc;
            background-color: #e7f7fc !important;
        }
        .er-class-table td.er-comp-texam-c,
        .er-class-table th.er-comp-texam-c {
            --bs-table-bg: #fffde3;
            --bs-table-accent-bg: #fffde3;
            background-color: #fffde3 !important;
        }
        .er-class-table td.er-comp-default-c,
        .er-class-table th.er-comp-default-c {
            --bs-table-bg: #f8fafc;
            --bs-table-accent-bg: #f8fafc;
            background-color: #f8fafc !important;
        }
        .er-class-table td.er-comp-quiz-c,
        .er-class-table td.er-comp-assign-c,
        .er-class-table td.er-comp-act-c,
        .er-class-table td.er-comp-recit-c,
        .er-class-table td.er-comp-attend-c,
        .er-class-table td.er-comp-proj-c,
        .er-class-table td.er-comp-texam-c,
        .er-class-table td.er-comp-default-c {
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
            color: #0f172a;
        }
        .er-class-table td.er-comp-quiz-c .er-small,
        .er-class-table td.er-comp-assign-c .er-small,
        .er-class-table td.er-comp-act-c .er-small,
        .er-class-table td.er-comp-recit-c .er-small,
        .er-class-table td.er-comp-attend-c .er-small,
        .er-class-table td.er-comp-proj-c .er-small,
        .er-class-table td.er-comp-texam-c .er-small,
        .er-class-table td.er-comp-default-c .er-small {
            color: #334155;
        }
        .er-class-table .er-comp-quiz-h .er-small,
        .er-class-table .er-comp-assign-h .er-small,
        .er-class-table .er-comp-recit-h .er-small,
        .er-class-table .er-comp-attend-h .er-small,
        .er-class-table .er-comp-proj-h .er-small {
            color: rgba(255, 255, 255, 0.92);
        }
        .er-class-table .er-comp-act-h .er-small,
        .er-class-table .er-comp-texam-h .er-small,
        .er-class-table .er-comp-default-h .er-small {
            color: rgba(17, 24, 39, 0.82);
        }
        .er-analytics-shell {
            margin-top: 14px;
            border: 1px solid rgba(15, 23, 42, 0.12);
            border-radius: 12px;
            background: linear-gradient(180deg, #f8fafc 0%, #ffffff 60%);
            position: relative;
            overflow: hidden;
        }
        .er-analytics-shell[data-er-analytics-parallax] .er-analytics-bg {
            position: absolute;
            inset: -20% -10% auto -10%;
            height: 260px;
            background:
                radial-gradient(circle at 8% 18%, rgba(14, 116, 144, 0.16) 0%, rgba(14, 116, 144, 0) 45%),
                radial-gradient(circle at 88% 22%, rgba(5, 150, 105, 0.14) 0%, rgba(5, 150, 105, 0) 45%),
                linear-gradient(90deg, rgba(59, 130, 246, 0.05), rgba(6, 182, 212, 0.05));
            pointer-events: none;
            transform: translate3d(0, 0, 0);
            transition: transform 140ms linear;
        }
        .er-analytics-inner {
            position: relative;
            z-index: 1;
            padding: 12px;
        }
        .er-analytics-kpis {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 8px;
            margin-bottom: 10px;
        }
        .er-kpi {
            border: 1px solid rgba(30, 41, 59, 0.12);
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.92);
            padding: 8px 10px;
        }
        .er-kpi .k {
            font-size: 11px;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .er-kpi .v {
            font-size: 21px;
            line-height: 1.1;
            font-weight: 700;
            color: #0f172a;
        }
        .er-analytics-grid {
            display: grid;
            grid-template-columns: repeat(12, minmax(0, 1fr));
            gap: 10px;
        }
        .er-chart-card {
            border: 1px solid rgba(30, 41, 59, 0.12);
            border-radius: 10px;
            background: #fff;
            padding: 8px;
            box-shadow: 0 6px 14px rgba(15, 23, 42, 0.05);
        }
        .er-chart-title {
            font-size: 12px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 6px;
        }
        .er-chart-card.col-6 { grid-column: span 6; }
        .er-chart-card.col-4 { grid-column: span 4; }
        .er-chart-card.col-8 { grid-column: span 8; }
        .er-chart-card.col-12 { grid-column: span 12; }
        .er-chart-canvas {
            width: 100%;
            min-height: 260px;
        }
        .er-chart-canvas.sm {
            min-height: 220px;
        }
        .er-chart-canvas.xs {
            min-height: 200px;
        }
        .er-analytics-shell .er-reveal {
            opacity: 0;
            transform: translateY(16px);
            transition: opacity 420ms ease, transform 420ms ease;
        }
        .er-analytics-shell .er-reveal.is-in {
            opacity: 1;
            transform: translateY(0);
        }
        .er-ranking-table th,
        .er-ranking-table td {
            font-size: 12px;
            padding: 4px 6px;
        }
        .er-ranking-table tbody tr:nth-child(-n+3) td {
            background: rgba(250, 204, 21, 0.12);
            font-weight: 600;
        }
        .er-analytics-print-section {
            display: block;
        }
        .er-analytics-signatures {
            display: none;
        }
        @media (max-width: 1200px) {
            .er-chart-card.col-6,
            .er-chart-card.col-8,
            .er-chart-card.col-4 {
                grid-column: span 12;
            }
        }

        @media print {
            @page { size: A4 landscape; margin: 0; }
            body { background: #fff !important; }
            .leftside-menu, .navbar-custom, .footer, .right-bar, .page-title-box, .breadcrumb, .er-print-bar, .d-print-none { display: none !important; }
            .content-page, .content, .container-fluid { margin: 0 !important; padding: 0 !important; display: block !important; }
            .row, .col-12 { display: block !important; }
            .card, .card-body { border: 0 !important; box-shadow: none !important; padding: 0 !important; }
            .er-sheet { border: 0 !important; border-radius: 0 !important; padding: 0 !important; margin: 0 !important; box-shadow: none !important; }
            .er-screen-only { display: none !important; }
            .er-print-only { display: block !important; }
            .er-tight { font-size: 10px !important; }
            .er-print-page {
                width: 297mm !important;
                min-height: 210mm !important;
                height: 210mm !important;
                padding: 9mm 10mm 10mm 10mm !important;
                box-sizing: border-box !important;
                position: relative !important;
                display: block !important;
                overflow: hidden !important;
                page-break-after: always;
                break-after: page;
            }
            .er-print-page + .er-print-page {
                margin-top: 0 !important;
            }
            .er-print-page:last-child {
                page-break-after: auto;
                break-after: auto;
            }
            .er-print-body {
                height: 100% !important;
                padding-bottom: 29mm !important;
                box-sizing: border-box;
                overflow: hidden !important;
            }
            .er-print-signatures {
                display: block !important;
                position: static !important;
                margin-top: 4.8em !important;
                margin-bottom: 0 !important;
            }
            .er-print-signatures .er-signatures {
                margin-top: 0 !important;
                width: 72%;
                column-gap: 10mm;
                font-size: 11pt;
            }
            .er-print-signatures .er-signature-label {
                margin-bottom: 8mm;
            }
            .er-print-signatures .er-signature-name {
                min-height: 17px;
            }
            .er-slsu-footer {
                margin-top: 0 !important;
            }
            .er-slsu-footer-print {
                position: absolute !important;
                right: 10mm !important;
                bottom: 5mm !important;
                z-index: 2;
            }
            .table-responsive {
                overflow: visible !important;
            }
            .er-class-table {
                width: 100% !important;
                table-layout: fixed !important;
                page-break-inside: auto;
                break-inside: auto;
            }
            .er-col-student,
            .er-col-student-fused,
            .er-col-sn,
            .er-col-grade,
            .er-col-assess {
                min-width: 0 !important;
                width: auto !important;
            }
            .er-class-table thead {
                display: table-header-group;
            }
            .er-class-table tfoot {
                display: table-footer-group;
            }
            .er-class-table tr {
                page-break-inside: avoid;
                break-inside: avoid;
            }
            .er-class-table th, .er-class-table td {
                padding: 1px 2px !important;
                overflow-wrap: normal !important;
                word-break: normal !important;
                line-height: 1.05 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .er-class-table th.er-col-sn,
            .er-class-table td.er-cell-sn {
                white-space: nowrap !important;
                width: 92px !important;
                font-size: 8.4px !important;
            }
            .er-class-table th.er-col-student,
            .er-class-table td.er-cell-student {
                white-space: nowrap !important;
                overflow: hidden !important;
                text-overflow: ellipsis !important;
                max-width: 0 !important;
                width: 130px !important;
                font-size: 8.4px !important;
            }
            .er-class-table th.er-col-student-fused,
            .er-class-table td.er-cell-student-fused {
                white-space: normal !important;
                overflow: hidden !important;
                text-overflow: clip !important;
                max-width: 150px !important;
                width: 150px !important;
                font-size: 8.2px !important;
            }
            .er-class-table td.er-cell-student-fused .er-student-name-line {
                display: block !important;
                font-weight: 600;
                font-size: 8.3px !important;
                line-height: 1.05 !important;
                white-space: nowrap !important;
                overflow: hidden !important;
                text-overflow: ellipsis !important;
            }
            .er-class-table td.er-cell-student-fused .er-student-no-line {
                display: block !important;
                font-size: 6.6px !important;
                line-height: 1.0 !important;
                color: #64748b !important;
                margin-top: 1px !important;
                white-space: nowrap !important;
                overflow: hidden !important;
                text-overflow: ellipsis !important;
            }
            .er-headline {
                grid-template-columns: 1fr auto 1fr !important;
            }
            .er-headline-title {
                text-align: center !important;
                min-width: 360px !important;
                border: 0 !important;
                padding: 0 !important;
            }
            .er-headline-main {
                font-size: 14pt !important;
                line-height: 1.15 !important;
            }
            .er-headline-sub {
                font-size: 12pt !important;
                line-height: 1.15 !important;
                margin-top: 0.6mm !important;
            }
            .er-headline-right {
                text-align: right !important;
            }
            .er-small { font-size: 9px !important; line-height: 1.1 !important; }
            a { text-decoration: none !important; color: #000 !important; }
            .er-analytics-print-section { display: none !important; }
            body.er-print-analytics .er-analytics-print-section {
                display: block !important;
                width: 297mm !important;
                min-height: 210mm !important;
                padding: 9mm 10mm 34mm 10mm !important;
                page-break-before: always;
                break-before: page;
                position: relative !important;
            }
            body.er-print-analytics .er-analytics-print-section .er-analytics-page-heading {
                display: none !important;
            }
            body.er-print-analytics .er-analytics-signatures {
                display: block !important;
                position: absolute !important;
                left: 10mm !important;
                bottom: 8mm !important;
                width: 72% !important;
                z-index: 2;
            }
            body.er-print-analytics .er-analytics-signatures .er-signatures {
                margin-top: 0 !important;
                column-gap: 10mm;
                font-size: 11pt;
            }
            body.er-print-analytics .er-analytics-signatures .er-signature-label {
                margin-bottom: 8mm;
            }
            body.er-print-analytics .er-analytics-signatures .er-signature-name {
                min-height: 17px;
            }
            .er-analytics-shell {
                border: 0 !important;
                box-shadow: none !important;
            }
            .er-chart-card {
                page-break-inside: avoid;
                break-inside: avoid;
            }
            .er-analytics-shell .er-reveal {
                opacity: 1 !important;
                transform: none !important;
            }
        }
        @media (max-width: 900px) {
            .er-headline {
                grid-template-columns: 1fr;
                gap: 4px;
            }
            .er-headline-title {
                text-align: left;
                min-width: 0;
                border: 0;
                padding: 0;
            }
            .er-headline-right {
                justify-self: start;
                text-align: left;
                min-width: 0;
            }
        }
    </style>
</head>

<body class="<?php echo $includeAnalyticsPrint ? 'er-print-analytics' : ''; ?>">
    <div class="wrapper">
        <?php include '../layouts/menu.php'; ?>

        <div class="content-page">
            <div class="content">
                <div class="container-fluid">
                    <div class="row">
                        <div class="col-12">
                            <div class="page-title-box d-print-none">
                                <div class="page-title-right">
                                    <ol class="breadcrumb m-0">
                                        <li class="breadcrumb-item"><?php echo $role === 'admin' ? '<a href="admin-dashboard.php">Admin</a>' : '<a href="teacher-dashboard.php">Teacher</a>'; ?></li>
                                        <li class="breadcrumb-item active">Class Record Print</li>
                                    </ol>
                                </div>
                                <h4 class="page-title">Class Record Print</h4>
                            </div>

                            <div class="er-print-bar d-print-none">
                                <div class="d-flex gap-2 flex-wrap">
                                    <button class="btn btn-outline-secondary btn-sm" type="button" onclick="history.back();">
                                        <i class="ri-arrow-left-line me-1" aria-hidden="true"></i>
                                        Back
                                    </button>
                                    <a class="btn btn-primary btn-sm" href="javascript:window.print()">
                                        <i class="ri-printer-line me-1" aria-hidden="true"></i>
                                        Print (A4 Landscape)
                                    </a>
                                    <div class="input-group input-group-sm er-rpp-picker">
                                        <span class="input-group-text">Rows/Page</span>
                                        <select class="form-select form-select-sm" id="er-rows-per-page-select" aria-label="Rows per print page">
                                            <?php foreach ($allowedRowsPerPage as $optRows): ?>
                                                <option value="<?php echo (int) $optRows; ?>" <?php echo ((int) $rowsPerPage === (int) $optRows) ? 'selected' : ''; ?>>
                                                    <?php echo (int) $optRows; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <?php if ($view === 'summary'): ?>
                                    <div class="input-group input-group-sm er-rank-picker">
                                        <span class="input-group-text">Ranking Basis</span>
                                        <select class="form-select form-select-sm" id="er-ranking-basis-select" aria-label="Ranking basis">
                                            <option value="avg_trans_strict" <?php echo $rankingBasis === 'avg_trans_strict' ? 'selected' : ''; ?>>AVG Transmuted (asc) strict</option>
                                            <option value="avg_initial_tiebreak" <?php echo $rankingBasis === 'avg_initial_tiebreak' ? 'selected' : ''; ?>>AVG Initial (desc) + tie-break</option>
                                        </select>
                                    </div>
                                    <?php endif; ?>
                                    <div class="form-check form-switch mb-0 mt-1 mt-sm-0">
                                        <input class="form-check-input" type="checkbox" id="er-include-analytics-print" <?php echo $includeAnalyticsPrint ? 'checked' : ''; ?>>
                                        <label class="form-check-label small" for="er-include-analytics-print">Include Analytics in Print</label>
                                    </div>
                                </div>
                                <div class="d-flex gap-2 flex-wrap">
                                    <div class="btn-group btn-group-sm" role="group" aria-label="Term">
                                        <a class="btn btn-outline-dark <?php echo $term === 'midterm' ? 'active' : ''; ?>"
                                            href="class-record-print.php?class_record_id=<?php echo (int) $classRecordId; ?>&term=midterm&view=<?php echo h($view); ?><?php echo h($columnStateQuery); ?>">
                                            Midterm
                                        </a>
                                        <a class="btn btn-outline-dark <?php echo $term === 'final' ? 'active' : ''; ?>"
                                            href="class-record-print.php?class_record_id=<?php echo (int) $classRecordId; ?>&term=final&view=<?php echo h($view); ?><?php echo h($columnStateQuery); ?>">
                                            Final
                                        </a>
                                    </div>
                                    <div class="btn-group btn-group-sm" role="group" aria-label="View">
                                        <a class="btn btn-outline-secondary <?php echo $view === 'assessments' ? 'active' : ''; ?>"
                                            href="class-record-print.php?class_record_id=<?php echo (int) $classRecordId; ?>&term=<?php echo h($term); ?>&view=assessments<?php echo h($columnStateQuery); ?>">
                                            Assessments
                                        </a>
                                        <a class="btn btn-outline-secondary <?php echo $view === 'summary' ? 'active' : ''; ?>"
                                            href="class-record-print.php?class_record_id=<?php echo (int) $classRecordId; ?>&term=<?php echo h($term); ?>&view=summary<?php echo h($columnStateQuery); ?>">
                                            Summary
                                        </a>
                                    </div>
                                </div>
                            </div>
                                <form method="get" class="d-print-none mb-2" id="er-print-column-form">
                                    <input type="hidden" name="class_record_id" value="<?php echo (int) $classRecordId; ?>">
                                    <input type="hidden" name="term" value="<?php echo h($term); ?>">
                                    <input type="hidden" name="view" value="<?php echo h($view); ?>">
                                    <?php if ($view === 'assessments'): ?>
                                        <input type="hidden" name="print_cols_mode" value="1">
                                    <?php elseif ($hasAssessmentFilter): ?>
                                        <input type="hidden" name="print_cols_mode" value="1">
                                        <?php foreach ($selectedAssessmentIds as $preserveAid): ?>
                                            <input type="hidden" name="print_cols[]" value="<?php echo (int) $preserveAid; ?>">
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    <input type="hidden" name="rows_per_page" value="<?php echo (int) $rowsPerPage; ?>">
                                    <input type="hidden" name="ranking_basis" value="<?php echo h($rankingBasis); ?>">
                                    <input type="hidden" name="include_analytics_print" id="er-include-analytics-print-hidden" value="<?php echo $includeAnalyticsPrint ? '1' : '0'; ?>">
                                    <input type="hidden" name="show_student_no" id="er-show-student-no-hidden" value="<?php echo $showStudentNo ? '1' : '0'; ?>">
                                    <input type="hidden" name="show_student_name" id="er-show-student-name-hidden" value="<?php echo $showStudentName ? '1' : '0'; ?>">
                                    <input type="hidden" name="show_primary_teacher" id="er-show-primary-teacher-hidden" value="<?php echo $showPrimaryTeacher ? '1' : '0'; ?>">
                                    <input type="hidden" name="show_students_count" id="er-show-students-count-hidden" value="<?php echo $showStudentsCount ? '1' : '0'; ?>">
                                    <input type="hidden" name="show_class_record_id" id="er-show-class-record-id-hidden" value="<?php echo $showClassRecordId ? '1' : '0'; ?>">
                                    <input type="hidden" name="show_generated_at" id="er-show-generated-at-hidden" value="<?php echo $showGeneratedAt ? '1' : '0'; ?>">
                                    <input type="hidden" name="show_course_year" id="er-show-course-year-hidden" value="<?php echo $showCourseYear ? '1' : '0'; ?>">
                                    <input type="hidden" name="show_view_label" id="er-show-view-label-hidden" value="<?php echo $showViewLabel ? '1' : '0'; ?>">
                                    <?php if ($view === 'assessments'): ?>
                                        <span id="er-print-cols-hidden"></span>
                                    <?php endif; ?>
                                    <div class="d-flex gap-2 flex-wrap align-items-center mb-2">
                                        <strong class="small">Display Fields</strong>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" id="er-display-all">Show All</button>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" id="er-display-essentials">Essentials</button>
                                        <button type="submit" class="btn btn-primary btn-sm">Apply</button>
                                    </div>
                                    <details class="er-display-panel mb-2">
                                        <summary>Display Options</summary>
                                        <div class="er-display-grid">
                                            <span class="er-inline-check comp">
                                                <input class="form-check-input er-meta-toggle er-visibility-toggle" type="checkbox" id="er-toggle-primary-teacher" <?php echo $showPrimaryTeacher ? 'checked' : ''; ?>>
                                                <span class="form-check-label">Primary Teacher</span>
                                                <i class="ri-eye-line er-visibility-icon" aria-hidden="true"></i>
                                            </span>
                                            <span class="er-inline-check comp">
                                                <input class="form-check-input er-meta-toggle er-visibility-toggle" type="checkbox" id="er-toggle-students-count" <?php echo $showStudentsCount ? 'checked' : ''; ?>>
                                                <span class="form-check-label">Students Count</span>
                                                <i class="ri-eye-line er-visibility-icon" aria-hidden="true"></i>
                                            </span>
                                            <span class="er-inline-check comp">
                                                <input class="form-check-input er-meta-toggle er-visibility-toggle" type="checkbox" id="er-toggle-course-year" <?php echo $showCourseYear ? 'checked' : ''; ?>>
                                                <span class="form-check-label">Course / Year</span>
                                                <i class="ri-eye-line er-visibility-icon" aria-hidden="true"></i>
                                            </span>
                                            <span class="er-inline-check comp">
                                                <input class="form-check-input er-meta-toggle er-visibility-toggle" type="checkbox" id="er-toggle-view-label" <?php echo $showViewLabel ? 'checked' : ''; ?>>
                                                <span class="form-check-label">View Label</span>
                                                <i class="ri-eye-line er-visibility-icon" aria-hidden="true"></i>
                                            </span>
                                            <span class="er-inline-check comp">
                                                <input class="form-check-input er-meta-toggle er-visibility-toggle" type="checkbox" id="er-toggle-class-record-id" <?php echo $showClassRecordId ? 'checked' : ''; ?>>
                                                <span class="form-check-label">Class Record ID</span>
                                                <i class="ri-eye-line er-visibility-icon" aria-hidden="true"></i>
                                            </span>
                                            <span class="er-inline-check comp">
                                                <input class="form-check-input er-meta-toggle er-visibility-toggle" type="checkbox" id="er-toggle-generated-at" <?php echo $showGeneratedAt ? 'checked' : ''; ?>>
                                                <span class="form-check-label">Generated Time</span>
                                                <i class="ri-eye-line er-visibility-icon" aria-hidden="true"></i>
                                            </span>
                                        </div>
                                    </details>
                                    <?php if ($view === 'assessments'): ?>
                                        <div class="d-flex gap-2 flex-wrap align-items-center mb-2">
                                            <strong class="small">Print Column Selection</strong>
                                            <button type="button" class="btn btn-outline-secondary btn-sm" id="er-cols-all">Select All Assessments</button>
                                            <button type="button" class="btn btn-outline-secondary btn-sm" id="er-cols-none">Clear Assessments</button>
                                            <button type="submit" class="btn btn-primary btn-sm">Apply Selection</button>
                                            <span class="small text-muted">Use the eye icons beside Student/Component/Assessment headers below.</span>
                                        </div>
                                    <?php endif; ?>
                                </form>

                            <div class="er-sheet er-tight er-screen-only">
                                <div class="er-slsu-header">
                                    <img class="er-slsu-header-strip" src="<?php echo h($erHeaderSrc); ?>" alt="SLSU Header">
                                </div>
                                <div class="er-headline">
                                    <div></div>
                                    <div class="er-headline-title">
                                        <div class="er-headline-main"><?php echo h($ctx['subject_name'] ?? ''); ?> (<?php echo h($ctx['subject_code'] ?? ''); ?>)</div>
                                        <div class="er-headline-sub">
                                            Section: <?php echo h($section); ?> |
                                            <?php echo h($academicYear); ?>, <?php echo h($semester); ?> |
                                            <?php echo h($termLabel); ?>
                                        </div>
                                        <?php if ($showCourseYear): ?>
                                            <div class="er-headline-sub">
                                                Course / Year: <?php echo h($course); ?> / <?php echo h($yearLevel); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="er-headline-right">
                                        <?php if ($showClassRecordId): ?>
                                            <div class="small text-muted">Class Record ID: <?php echo (int) $classRecordId; ?></div>
                                        <?php endif; ?>
                                        <?php if ($showGeneratedAt): ?>
                                            <div class="small text-muted">Generated: <?php echo h(date('Y-m-d H:i')); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <hr class="my-2">

                                <div class="er-meta">
                                    <?php if ($showPrimaryTeacher): ?>
                                        <div><strong>Primary Teacher:</strong> <?php echo h($ctx['primary_teacher'] ?? 'N/A'); ?></div>
                                    <?php endif; ?>
                                    <?php if ($showStudentsCount): ?>
                                        <div><strong>Students:</strong> <?php echo (int) count($students); ?></div>
                                    <?php endif; ?>
                                    <?php if ($showViewLabel): ?>
                                        <div><strong>View:</strong> <?php echo h(ucfirst($view)); ?></div>
                                    <?php endif; ?>
                                </div>

                                <?php if ($configId <= 0): ?>
                                    <div class="alert alert-warning mt-3 mb-0">
                                        No grading configuration found for this term. (You can still print the roster.)
                                    </div>
                                <?php endif; ?>
                                <?php if ($view === 'summary'): ?>
                                    <div class="table-responsive mt-3">
                                        <table class="table table-bordered table-sm er-class-table mb-0">
                                            <thead>
                                                <tr>
                                                    <th class="er-col-sn">Student No</th>
                                                    <th class="er-col-student">Student Name</th>
                                                    <?php foreach ($components as $gc): ?>
                                                        <?php $hClass = er_component_header_class((array) $gc); ?>
                                                        <th>
                                                            <div class="<?php echo h($hClass); ?> p-1 rounded-1">
                                                                <?php echo h($gc['component_name'] ?? ''); ?>
                                                                <div class="er-small"><?php echo h($gc['category_name'] ?? ''); ?> | <?php echo fmt2($gc['weight'] ?? 0); ?>%</div>
                                                            </div>
                                                        </th>
                                                    <?php endforeach; ?>
                                                    <th class="er-col-grade er-grade-col">Initial Grade</th>
                                                    <th class="er-col-grade er-grade-col"><?php echo h($summaryTransmutedColLabel); ?> (Transmuted)</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (count($students) === 0): ?>
                                                    <tr><td colspan="<?php echo 4 + (int) count($components); ?>" class="text-center text-muted">No enrolled students.</td></tr>
                                                <?php endif; ?>
                                                <?php foreach ($students as $st): ?>
                                                    <?php
                                                    $sid = (int) ($st['student_id'] ?? 0);
                                                    $name = trim((string) ($st['surname'] ?? '') . ', ' . (string) ($st['firstname'] ?? '') . ' ' . (string) ($st['middlename'] ?? ''));
                                                    $g = $studentGrades[$sid] ?? ['term_grade' => 0.0, 'components' => []];
                                                    $initialGrade = er_initial_grade_value($g['term_grade'] ?? 0);
                                                    $transmutedGrade = er_transmuted_grade($initialGrade);
                                                    if ($term === 'final' && isset($subjectAverageTransByStudent[$sid]) && is_numeric($subjectAverageTransByStudent[$sid])) {
                                                        $transmutedGrade = number_format((float) $subjectAverageTransByStudent[$sid], 1, '.', '');
                                                    }
                                                    ?>
                                                    <tr>
                                                        <td class="er-cell-sn"><?php echo h($st['student_no'] ?? ''); ?></td>
                                                        <td class="er-cell-student"><?php echo h($name); ?></td>
                                                        <?php foreach ($components as $gc): ?>
                                                            <?php
                                                            $cid = (int) ($gc['id'] ?? 0);
                                                            $cd = $g['components'][$cid] ?? ['sum' => 0, 'max' => 0, 'pct' => null, 'pts' => 0, 'weight' => 0];
                                                            $cClass = er_component_cell_class((array) $gc);
                                                            ?>
                                                            <td class="<?php echo h($cClass); ?>">
                                                                <?php if (($cd['max'] ?? 0) > 0 && $cd['pct'] !== null): ?>
                                                                    <div><?php echo fmt2($cd['pct']); ?>%</div>
                                                                    <div class="er-small"><?php echo fmt2($cd['sum']); ?>/<?php echo fmt2($cd['max']); ?> | <?php echo fmt2($cd['pts']); ?> pts</div>
                                                                <?php else: ?>
                                                                    <span class="text-muted">-</span>
                                                                <?php endif; ?>
                                                            </td>
                                                        <?php endforeach; ?>
                                                        <td class="fw-semibold er-grade-col"><?php echo fmt2($initialGrade); ?></td>
                                                        <td class="fw-semibold er-grade-col"><?php echo $transmutedGrade === '' ? '<span class="text-muted">-</span>' : h($transmutedGrade); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="er-small mt-2">
                                        Component percent is computed by <code>sum(score) / sum(max_score)</code> inside the component. Initial Grade is the weighted sum of component percents. <?php echo h($summaryTransmutedColLabel); ?> uses the transmuted scale.
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive mt-3">
                                        <table class="table table-bordered table-sm er-class-table mb-0">
                                            <thead>
                                                <tr>
                                                    <th rowspan="<?php echo (int) $screenAssessmentHeaderRowspan; ?>" class="er-col-sn">
                                                        <span class="er-inline-check comp">
                                                            <input class="form-check-input er-meta-toggle er-visibility-toggle" type="checkbox" id="er-toggle-student-no" <?php echo $showStudentNo ? 'checked' : ''; ?>>
                                                            <span class="form-check-label">Student No</span>
                                                            <i class="ri-eye-line er-visibility-icon" aria-hidden="true"></i>
                                                        </span>
                                                    </th>
                                                    <th rowspan="<?php echo (int) $screenAssessmentHeaderRowspan; ?>" class="er-col-student">
                                                        <span class="er-inline-check comp">
                                                            <input class="form-check-input er-meta-toggle er-visibility-toggle" type="checkbox" id="er-toggle-student-name" <?php echo $showStudentName ? 'checked' : ''; ?>>
                                                            <span class="form-check-label">Student Name</span>
                                                            <i class="ri-eye-line er-visibility-icon" aria-hidden="true"></i>
                                                        </span>
                                                    </th>
                                                    <?php
                                                    foreach ($components as $gc) {
                                                        $cid = (int) ($gc['id'] ?? 0);
                                                        $list = $assessmentsByComponent[$cid] ?? [];
                                                        $cols = count($list);
                                                        if ($cols <= 0) continue;
                                                        $label = trim((string) ($gc['component_name'] ?? ''));
                                                        $cat = trim((string) ($gc['category_name'] ?? ''));
                                                        $selectedCount = 0;
                                                        foreach ($list as $a) {
                                                            $aid = (int) ($a['id'] ?? 0);
                                                            if (isset($selectedAssessmentIdSet[$aid])) $selectedCount++;
                                                        }
                                                        $allSelectedInGroup = ($selectedCount === $cols);
                                                        $hClass = er_component_header_class((array) $gc);
                                                        echo '<th class="' . h($hClass) . '" colspan="' . (int) $cols . '"><span class="er-inline-check comp"><input class="form-check-input er-component-toggle er-visibility-toggle" type="checkbox" data-component-id="' . (int) $cid . '" ' . ($allSelectedInGroup ? 'checked' : '') . '><span class="form-check-label">' . h($label) . '</span><i class="ri-eye-line er-visibility-icon" aria-hidden="true"></i></span><div class="er-small">' . h($cat) . ' | ' . fmt2($gc['weight'] ?? 0) . '%</div></th>';
                                                    }
                                                    ?>
                                                    <th rowspan="<?php echo (int) $screenAssessmentHeaderRowspan; ?>" class="er-col-grade er-grade-col">Initial Grade</th>
                                                    <th rowspan="<?php echo (int) $screenAssessmentHeaderRowspan; ?>" class="er-col-grade er-grade-col"><?php echo h($transmutedGradeColLabel); ?> (Transmuted)</th>
                                                </tr>
                                                <?php if (count($assessments) > 0): ?>
                                                    <tr>
                                                        <?php foreach ($components as $gc): ?>
                                                            <?php
                                                            $cid = (int) ($gc['id'] ?? 0);
                                                            $list = $assessmentsByComponent[$cid] ?? [];
                                                            if (count($list) === 0) continue;
                                                            $hClass = er_component_header_class((array) $gc);
                                                            foreach ($list as $a) {
                                                                $aid = (int) ($a['id'] ?? 0);
                                                                $nm = (string) ($a['name'] ?? '');
                                                                $nmCompact = er_compact_assessment_label($nm);
                                                                $mx = (float) ($a['max_score'] ?? 0);
                                                                echo '<th class="er-col-assess ' . h($hClass) . '" title="' . h($nm) . '"><span class="er-inline-check assess"><input class="form-check-input er-assessment-checkbox er-visibility-toggle" type="checkbox" value="' . (int) $aid . '" data-component-id="' . (int) $cid . '" form="er-print-column-form" ' . (isset($selectedAssessmentIdSet[$aid]) ? 'checked' : '') . '><span class="form-check-label">' . h($nmCompact) . '</span><i class="ri-eye-line er-visibility-icon" aria-hidden="true"></i></span><div class="er-small">Max ' . h(fmt2($mx)) . '</div></th>';
                                                            }
                                                            ?>
                                                        <?php endforeach; ?>
                                                    </tr>
                                                <?php endif; ?>
                                            </thead>
                                            <tbody>
                                                <?php if (count($students) === 0): ?>
                                                    <tr><td colspan="<?php echo 4 + (int) count($assessments); ?>" class="text-center text-muted">No enrolled students.</td></tr>
                                                <?php endif; ?>
                                                <?php foreach ($students as $st): ?>
                                                    <?php
                                                    $sid = (int) ($st['student_id'] ?? 0);
                                                    $name = trim((string) ($st['surname'] ?? '') . ', ' . (string) ($st['firstname'] ?? '') . ' ' . (string) ($st['middlename'] ?? ''));
                                                    $g = $studentGradesForAssessments[$sid] ?? ['term_grade' => 0.0];
                                                    $initialGrade = er_initial_grade_value($g['term_grade'] ?? 0);
                                                    $transmutedGrade = er_transmuted_grade($initialGrade);
                                                    ?>
                                                    <tr>
                                                        <td class="er-cell-sn"><?php echo h($st['student_no'] ?? ''); ?></td>
                                                        <td class="er-cell-student"><?php echo h($name); ?></td>
                                                        <?php foreach ($components as $gc): ?>
                                                            <?php
                                                            $cid = (int) ($gc['id'] ?? 0);
                                                            $list = $assessmentsByComponent[$cid] ?? [];
                                                            if (count($list) === 0) continue;
                                                            $cClass = er_component_cell_class((array) $gc);
                                                            $isAttendanceComponent = er_component_key((array) $gc) === 'attend';
                                                            foreach ($list as $a) {
                                                                $aid = (int) ($a['id'] ?? 0);
                                                                $val = $scores[$sid][$aid] ?? null;
                                                                echo '<td class="' . h($cClass) . '">' . ($val === null ? '<span class="text-muted"> </span>' : h(er_fmt_score($val, $isAttendanceComponent))) . '</td>';
                                                            }
                                                            ?>
                                                        <?php endforeach; ?>
                                                        <td class="fw-semibold er-grade-col"><?php echo fmt2($initialGrade); ?></td>
                                                        <td class="fw-semibold er-grade-col"><?php echo $transmutedGrade === '' ? '<span class="text-muted">-</span>' : h($transmutedGrade); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php if ($hasAssessmentFilter): ?>
                                        <div class="alert alert-info mt-2 d-print-none">
                                            Hidden columns are excluded from view only. Initial Grade and <?php echo h($transmutedGradeColLabel); ?> still use all assessment scores.
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($totalAssessmentCols === 0): ?>
                                        <div class="alert alert-info mt-2 d-print-none">
                                            No assessment columns selected for printing. Set at least one assessment to visible using the eye icon in the table header, then click <strong>Apply Selection</strong>.
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($totalAssessmentCols > 24): ?>
                                        <div class="alert alert-warning mt-2 d-print-none">
                                            This class record has many assessments (<?php echo (int) $totalAssessmentCols; ?> columns). Consider using the Summary view for cleaner printing.
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <div class="er-slsu-footer">
                                    <img class="er-slsu-footer-qs" src="<?php echo h($erQsSrc); ?>" alt="QS Rated Good">
                                    <img class="er-slsu-footer-socotec" src="<?php echo h($erSocotecSrc); ?>" alt="Socotec ISO 9001:2015">
                                </div>
                            </div>

                            <div class="er-print-only">
                            <?php foreach ($studentPages as $pageIndex => $studentsPage): ?>
                            <?php
                                $isFirstStudentPage = ($pageIndex === 0);
                                $isLastStudentPage = ($pageIndex === (count($studentPages) - 1));
                                $hasAnalyticsPrintPage = ($analyticsEnabled && is_array($analyticsChartData) && $includeAnalyticsPrint);
                                $showPrintSignaturesHere = $isLastStudentPage && !$hasAnalyticsPrintPage;
                                $showDisplayFieldsOnPrintPage = $isFirstStudentPage;
                            ?>
                            <div class="er-sheet er-tight er-print-page">
                                <div class="er-print-body">
                                <div class="er-slsu-header">
                                    <img class="er-slsu-header-strip" src="<?php echo h($erHeaderSrc); ?>" alt="SLSU Header">
                                </div>
                                <?php if ($showDisplayFieldsOnPrintPage): ?>
                                    <div class="er-headline">
                                        <div></div>
                                        <div class="er-headline-title">
                                            <div class="er-headline-main"><?php echo h($ctx['subject_name'] ?? ''); ?> (<?php echo h($ctx['subject_code'] ?? ''); ?>)</div>
                                            <div class="er-headline-sub">
                                                Section: <?php echo h($section); ?> |
                                                <?php echo h($academicYear); ?>, <?php echo h($semester); ?> |
                                                <?php echo h($termLabel); ?>
                                            </div>
                                            <?php if ($showCourseYear): ?>
                                                <div class="er-headline-sub">
                                                    Course / Year: <?php echo h($course); ?> / <?php echo h($yearLevel); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="er-headline-right">
                                            <?php if ($showClassRecordId): ?>
                                                <div class="small text-muted">Class Record ID: <?php echo (int) $classRecordId; ?></div>
                                            <?php endif; ?>
                                            <?php if ($showGeneratedAt): ?>
                                                <div class="small text-muted">Generated: <?php echo h(date('Y-m-d H:i')); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <hr class="my-2">
                                    <div class="er-meta">
                                        <?php if ($showPrimaryTeacher): ?>
                                            <div><strong>Primary Teacher:</strong> <?php echo h($ctx['primary_teacher'] ?? 'N/A'); ?></div>
                                        <?php endif; ?>
                                        <?php if ($showStudentsCount): ?>
                                            <div><strong>Students:</strong> <?php echo (int) count($students); ?></div>
                                        <?php endif; ?>
                                        <?php if ($showViewLabel): ?>
                                            <div><strong>View:</strong> <?php echo h(ucfirst($view)); ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ($configId <= 0): ?>
                                    <div class="alert alert-warning mt-3 mb-0">
                                        No grading configuration found for this term. (You can still print the roster.)
                                    </div>
                                <?php endif; ?>

                                <?php if ($view === 'summary'): ?>
                                    <div class="table-responsive mt-3">
                                        <table class="table table-bordered table-sm er-class-table mb-0">
                                            <thead>
                                                <tr>
                                                    <?php if ($printShowStudentFused): ?>
                                                        <th class="er-col-student-fused">Student</th>
                                                    <?php endif; ?>
                                                    <?php foreach ($components as $gc): ?>
                                                        <?php $hClass = er_component_header_class((array) $gc); ?>
                                                        <th>
                                                            <div class="<?php echo h($hClass); ?> p-1 rounded-1">
                                                                <?php echo h($gc['component_name'] ?? ''); ?>
                                                                <div class="er-small"><?php echo h($gc['category_name'] ?? ''); ?> | <?php echo fmt2($gc['weight'] ?? 0); ?>%</div>
                                                            </div>
                                                        </th>
                                                    <?php endforeach; ?>
                                                    <th class="er-col-grade er-grade-col">Initial Grade</th>
                                                    <th class="er-col-grade er-grade-col"><?php echo h($summaryTransmutedColLabel); ?> (Transmuted)</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (count($studentsPage) === 0): ?>
                                                    <tr><td colspan="<?php echo ($printShowStudentFused ? 1 : 0) + 2 + (int) count($components); ?>" class="text-center text-muted">No enrolled students.</td></tr>
                                                <?php endif; ?>
                                                <?php foreach ($studentsPage as $st): ?>
                                                    <?php
                                                    $sid = (int) ($st['student_id'] ?? 0);
                                                    $name = trim((string) ($st['surname'] ?? '') . ', ' . (string) ($st['firstname'] ?? '') . ' ' . (string) ($st['middlename'] ?? ''));
                                                    $g = $studentGrades[$sid] ?? ['term_grade' => 0.0, 'components' => []];
                                                    $initialGrade = er_initial_grade_value($g['term_grade'] ?? 0);
                                                    $transmutedGrade = er_transmuted_grade($initialGrade);
                                                    if ($term === 'final' && isset($subjectAverageTransByStudent[$sid]) && is_numeric($subjectAverageTransByStudent[$sid])) {
                                                        $transmutedGrade = number_format((float) $subjectAverageTransByStudent[$sid], 1, '.', '');
                                                    }
                                                    ?>
                                                    <tr>
                                                        <?php if ($printShowStudentFused): ?>
                                                            <td class="er-cell-student-fused">
                                                                <?php if ($showStudentName): ?>
                                                                    <div class="er-student-name-line"><?php echo h($name); ?></div>
                                                                <?php endif; ?>
                                                                <?php if ($showStudentNo): ?>
                                                                    <div class="er-student-no-line"><?php echo h($st['student_no'] ?? ''); ?></div>
                                                                <?php endif; ?>
                                                            </td>
                                                        <?php endif; ?>
                                                        <?php foreach ($components as $gc): ?>
                                                            <?php
                                                            $cid = (int) ($gc['id'] ?? 0);
                                                            $cd = $g['components'][$cid] ?? ['sum' => 0, 'max' => 0, 'pct' => null, 'pts' => 0, 'weight' => 0];
                                                            $cClass = er_component_cell_class((array) $gc);
                                                            ?>
                                                            <td class="<?php echo h($cClass); ?>">
                                                                <?php if (($cd['max'] ?? 0) > 0 && $cd['pct'] !== null): ?>
                                                                    <div><?php echo fmt2($cd['pct']); ?>%</div>
                                                                    <div class="er-small"><?php echo fmt2($cd['sum']); ?>/<?php echo fmt2($cd['max']); ?> | <?php echo fmt2($cd['pts']); ?> pts</div>
                                                                <?php else: ?>
                                                                    <span class="text-muted">-</span>
                                                                <?php endif; ?>
                                                            </td>
                                                        <?php endforeach; ?>
                                                        <td class="fw-semibold er-grade-col"><?php echo fmt2($initialGrade); ?></td>
                                                        <td class="fw-semibold er-grade-col"><?php echo $transmutedGrade === '' ? '<span class="text-muted">-</span>' : h($transmutedGrade); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="er-small mt-2">
                                        Component percent is computed by <code>sum(score) / sum(max_score)</code> inside the component. Initial Grade is the weighted sum of component percents. <?php echo h($summaryTransmutedColLabel); ?> uses the transmuted scale.
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive mt-3">
                                        <table class="table table-bordered table-sm er-class-table mb-0">
                                            <thead>
                                                <tr>
                                                    <?php if ($printShowStudentFused): ?>
                                                        <th rowspan="<?php echo (int) $assessmentHeaderRowspan; ?>" class="er-col-student-fused">Student</th>
                                                    <?php endif; ?>
                                                    <?php
                                                    foreach ($assessmentDisplayComponents as $gc) {
                                                        $cid = (int) ($gc['id'] ?? 0);
                                                        $cols = count($displayAssessmentsByComponent[$cid] ?? []);
                                                        $label = trim((string) ($gc['component_name'] ?? ''));
                                                        $cat = trim((string) ($gc['category_name'] ?? ''));
                                                        $span = $cols;
                                                        $hClass = er_component_header_class((array) $gc);
                                                        echo '<th class="' . h($hClass) . '" colspan="' . (int) $span . '">' . h($label) . '<div class="er-small">' . h($cat) . ' | ' . fmt2($gc['weight'] ?? 0) . '%</div></th>';
                                                    }
                                                    ?>
                                                    <th rowspan="<?php echo (int) $assessmentHeaderRowspan; ?>" class="er-col-grade er-grade-col">Initial Grade</th>
                                                    <th rowspan="<?php echo (int) $assessmentHeaderRowspan; ?>" class="er-col-grade er-grade-col"><?php echo h($transmutedGradeColLabel); ?> (Transmuted)</th>
                                                </tr>
                                                <?php if ($totalAssessmentCols > 0): ?>
                                                    <tr>
                                                        <?php foreach ($assessmentDisplayComponents as $gc): ?>
                                                            <?php
                                                            $cid = (int) ($gc['id'] ?? 0);
                                                            $list = $displayAssessmentsByComponent[$cid] ?? [];
                                                            $hClass = er_component_header_class((array) $gc);
                                                            foreach ($list as $a) {
                                                                $nm = (string) ($a['name'] ?? '');
                                                                $nmCompact = er_compact_assessment_label($nm);
                                                                $mx = (float) ($a['max_score'] ?? 0);
                                                                echo '<th class="er-col-assess ' . h($hClass) . '" title="' . h($nm) . '">' . h($nmCompact) . '<div class="er-small">Max ' . h(fmt2($mx)) . '</div></th>';
                                                            }
                                                            ?>
                                                        <?php endforeach; ?>
                                                    </tr>
                                                <?php endif; ?>
                                            </thead>
                                            <tbody>
                                                <?php if (count($studentsPage) === 0): ?>
                                                    <tr><td colspan="<?php echo (int) $printAssessmentTableColspan; ?>" class="text-center text-muted">No enrolled students.</td></tr>
                                                <?php endif; ?>
                                                <?php foreach ($studentsPage as $st): ?>
                                                    <?php
                                                    $sid = (int) ($st['student_id'] ?? 0);
                                                    $name = trim((string) ($st['surname'] ?? '') . ', ' . (string) ($st['firstname'] ?? '') . ' ' . (string) ($st['middlename'] ?? ''));
                                                    $g = $studentGradesForAssessments[$sid] ?? ['term_grade' => 0.0];
                                                    $initialGrade = er_initial_grade_value($g['term_grade'] ?? 0);
                                                    $transmutedGrade = er_transmuted_grade($initialGrade);
                                                    ?>
                                                    <tr>
                                                        <?php if ($printShowStudentFused): ?>
                                                            <td class="er-cell-student-fused">
                                                                <?php if ($showStudentName): ?>
                                                                    <div class="er-student-name-line"><?php echo h($name); ?></div>
                                                                <?php endif; ?>
                                                                <?php if ($showStudentNo): ?>
                                                                    <div class="er-student-no-line"><?php echo h($st['student_no'] ?? ''); ?></div>
                                                                <?php endif; ?>
                                                            </td>
                                                        <?php endif; ?>
                                                        <?php foreach ($assessmentDisplayComponents as $gc): ?>
                                                            <?php
                                                            $cid = (int) ($gc['id'] ?? 0);
                                                            $list = $displayAssessmentsByComponent[$cid] ?? [];
                                                            $cClass = er_component_cell_class((array) $gc);
                                                            $isAttendanceComponent = er_component_key((array) $gc) === 'attend';
                                                            foreach ($list as $a) {
                                                                $aid = (int) ($a['id'] ?? 0);
                                                                $val = $scores[$sid][$aid] ?? null;
                                                                echo '<td class="' . h($cClass) . '">' . ($val === null ? '<span class="text-muted"> </span>' : h(er_fmt_score($val, $isAttendanceComponent))) . '</td>';
                                                            }
                                                            ?>
                                                        <?php endforeach; ?>
                                                        <td class="fw-semibold er-grade-col"><?php echo fmt2($initialGrade); ?></td>
                                                        <td class="fw-semibold er-grade-col"><?php echo $transmutedGrade === '' ? '<span class="text-muted">-</span>' : h($transmutedGrade); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php if ($totalAssessmentCols > 24): ?>
                                        <div class="alert alert-warning mt-2 d-print-none">
                                            This class record has many assessments (<?php echo (int) $totalAssessmentCols; ?> columns). Consider using the Summary view for cleaner printing.
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($showPrintSignaturesHere): ?>
                                    <div class="er-print-signatures">
                                        <?php er_render_signature_block($preparedByName, $preparedByRole, $approvedByName, $approvedByRole); ?>
                                    </div>
                                <?php endif; ?>
                                </div>
                                <div class="er-slsu-footer er-slsu-footer-print">
                                    <img class="er-slsu-footer-qs" src="<?php echo h($erQsSrc); ?>" alt="QS Rated Good">
                                    <img class="er-slsu-footer-socotec" src="<?php echo h($erSocotecSrc); ?>" alt="Socotec ISO 9001:2015">
                                </div>
                            </div>
                            <?php endforeach; ?>
                            </div>

                            <?php if ($analyticsEnabled && is_array($analyticsChartData)): ?>
                            <div class="er-analytics-print-section">
                                <div class="er-analytics-shell" data-er-analytics-parallax>
                                    <div class="er-analytics-bg" aria-hidden="true"></div>
                                    <div class="er-analytics-inner">
                                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2 er-analytics-page-heading">
                                            <div>
                                                <h5 class="mb-1">Data Analytics</h5>
                                                <div class="small text-muted">MT/FT totals and subject-average intelligence for <?php echo h($ctx['subject_code'] ?? ''); ?>.</div>
                                            </div>
                                            <div class="small text-muted">
                                                Whole class charts | Ranking basis: <?php echo h($rankingBasisLabel); ?>
                                            </div>
                                        </div>

                                        <div class="er-analytics-kpis">
                                            <div class="er-kpi er-reveal">
                                                <div class="k">Class MT Avg</div>
                                                <div class="v"><?php echo h($analyticsClassMetrics['mt_avg_trans'] !== '' ? $analyticsClassMetrics['mt_avg_trans'] : '-'); ?></div>
                                            </div>
                                            <div class="er-kpi er-reveal">
                                                <div class="k">Class FT Avg</div>
                                                <div class="v"><?php echo h($analyticsClassMetrics['ft_avg_trans'] !== '' ? $analyticsClassMetrics['ft_avg_trans'] : '-'); ?></div>
                                            </div>
                                            <div class="er-kpi er-reveal">
                                                <div class="k">Subject Avg (MT+FT)</div>
                                                <div class="v"><?php echo h($analyticsClassMetrics['subject_avg_trans'] !== '' ? $analyticsClassMetrics['subject_avg_trans'] : '-'); ?></div>
                                            </div>
                                        </div>

                                        <div class="er-analytics-grid">
                                            <div class="er-chart-card col-8 er-reveal">
                                                <div class="er-chart-title">Column Chart: MT/FT/AVG Distribution (Whole Class)</div>
                                                <div id="er-analytics-column" class="er-chart-canvas"></div>
                                            </div>
                                            <div class="er-chart-card col-4 er-reveal">
                                                <div class="er-chart-title">Radial Bar: Class Status</div>
                                                <div id="er-analytics-radial" class="er-chart-canvas sm"></div>
                                            </div>
                                            <div class="er-chart-card col-6 er-reveal">
                                                <div class="er-chart-title">Radar Chart: Whole Class vs Top 10 Profile</div>
                                                <div id="er-analytics-radar" class="er-chart-canvas"></div>
                                            </div>
                                            <div class="er-chart-card col-6 er-reveal">
                                                <div class="er-chart-title">Pie Chart: Subject Average Distribution</div>
                                                <div id="er-analytics-pie" class="er-chart-canvas sm"></div>
                                            </div>
                                            <div class="er-chart-card col-6 er-reveal">
                                                <div class="er-chart-title">Heatmap: MT/FT/AVG Initial by Whole-Class Rank</div>
                                                <div id="er-analytics-heatmap" class="er-chart-canvas"></div>
                                            </div>
                                            <div class="er-chart-card col-6 er-reveal">
                                                <div class="er-chart-title">Contour Plot: MT vs FT Density</div>
                                                <canvas id="er-analytics-contour" class="er-chart-canvas xs"></canvas>
                                            </div>
                                            <div class="er-chart-card col-12 er-reveal">
                                                <div class="er-chart-title">Subject Ranking (Top 10)</div>
                                                <div class="small text-muted mb-1">Sorted by: <?php echo h($rankingBasisLabel); ?></div>
                                                <div class="table-responsive">
                                                    <table class="table table-sm table-bordered mb-0 er-ranking-table">
                                                        <thead>
                                                            <tr>
                                                                <th style="width:60px;">Rank</th>
                                                                <th style="width:140px;">Student No</th>
                                                                <th>Student Name</th>
                                                                <th style="width:90px;">MT</th>
                                                                <th style="width:90px;">FT</th>
                                                                <th style="width:100px;">AVG</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php if (count($analyticsTop10) === 0): ?>
                                                                <tr><td colspan="6" class="text-center text-muted">No analytics rows available.</td></tr>
                                                            <?php endif; ?>
                                                            <?php foreach ($analyticsTop10 as $rankRow): ?>
                                                                <tr>
                                                                    <td><?php echo (int) ($rankRow['rank'] ?? 0); ?></td>
                                                                    <td><?php echo h((string) ($rankRow['student_no'] ?? '')); ?></td>
                                                                    <td><?php echo h((string) ($rankRow['student_name'] ?? '')); ?></td>
                                                                    <td><?php echo h((string) (($rankRow['mt_trans'] ?? '') !== '' ? $rankRow['mt_trans'] : '-')); ?></td>
                                                                    <td><?php echo h((string) (($rankRow['ft_trans'] ?? '') !== '' ? $rankRow['ft_trans'] : '-')); ?></td>
                                                                    <td><?php echo h((string) (($rankRow['avg_trans'] ?? '') !== '' ? $rankRow['avg_trans'] : '-')); ?></td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php if ($includeAnalyticsPrint): ?>
                                    <div class="er-analytics-signatures">
                                        <?php er_render_signature_block($preparedByName, $preparedByRole, $approvedByName, $approvedByRole); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
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
            const form = document.getElementById('er-print-column-form');
            if (!form) return;

            const checks = Array.from(document.querySelectorAll('.er-assessment-checkbox'));
            const componentToggles = Array.from(document.querySelectorAll('.er-component-toggle'));
            const selectAllBtn = document.getElementById('er-cols-all');
            const selectNoneBtn = document.getElementById('er-cols-none');
            const displayAllBtn = document.getElementById('er-display-all');
            const displayEssentialsBtn = document.getElementById('er-display-essentials');
            const studentNoToggle = document.getElementById('er-toggle-student-no');
            const studentNameToggle = document.getElementById('er-toggle-student-name');
            const primaryTeacherToggle = document.getElementById('er-toggle-primary-teacher');
            const studentsCountToggle = document.getElementById('er-toggle-students-count');
            const courseYearToggle = document.getElementById('er-toggle-course-year');
            const viewLabelToggle = document.getElementById('er-toggle-view-label');
            const classRecordIdToggle = document.getElementById('er-toggle-class-record-id');
            const generatedAtToggle = document.getElementById('er-toggle-generated-at');
            const studentNoHidden = document.getElementById('er-show-student-no-hidden');
            const studentNameHidden = document.getElementById('er-show-student-name-hidden');
            const primaryTeacherHidden = document.getElementById('er-show-primary-teacher-hidden');
            const studentsCountHidden = document.getElementById('er-show-students-count-hidden');
            const courseYearHidden = document.getElementById('er-show-course-year-hidden');
            const viewLabelHidden = document.getElementById('er-show-view-label-hidden');
            const classRecordIdHidden = document.getElementById('er-show-class-record-id-hidden');
            const generatedAtHidden = document.getElementById('er-show-generated-at-hidden');
            const printColsHiddenHost = document.getElementById('er-print-cols-hidden');
            const includeAnalyticsToggle = document.getElementById('er-include-analytics-print');
            const includeAnalyticsHidden = document.getElementById('er-include-analytics-print-hidden');
            const storageKey = <?php echo json_encode('er-print-cols-' . (int) $classRecordId . '-' . $term); ?>;
            const hasUrlFilter = <?php echo $hasColumnStateInUrl ? 'true' : 'false'; ?>;
            const groupMap = new Map();
            const visibilityInputs = Array.from(document.querySelectorAll('.er-assessment-checkbox, .er-component-toggle, .er-meta-toggle'));
            const visibilityControls = Array.from(document.querySelectorAll('.er-inline-check'));
            let autoApplyTimer = null;

            checks.forEach((cb) => {
                const componentId = cb.getAttribute('data-component-id') || '';
                if (!groupMap.has(componentId)) groupMap.set(componentId, []);
                groupMap.get(componentId).push(cb);
            });

            function syncVisibilityIcon(input) {
                if (!input) return;
                const holder = input.closest('.er-inline-check');
                if (!holder) return;
                const icon = holder.querySelector('.er-visibility-icon');
                if (!icon) return;
                const isPartial = !!input.indeterminate;
                const isVisible = !!input.checked;
                icon.classList.remove('ri-eye-line', 'ri-eye-off-line', 'ri-eye-2-line');
                if (isPartial) {
                    icon.classList.add('ri-eye-2-line');
                    icon.setAttribute('title', 'Partially visible');
                    icon.setAttribute('aria-label', 'Partially visible');
                    icon.setAttribute('aria-pressed', 'mixed');
                    return;
                }
                if (isVisible) {
                    icon.classList.add('ri-eye-line');
                    icon.setAttribute('title', 'Visible');
                    icon.setAttribute('aria-label', 'Visible');
                    icon.setAttribute('aria-pressed', 'true');
                } else {
                    icon.classList.add('ri-eye-off-line');
                    icon.setAttribute('title', 'Hidden');
                    icon.setAttribute('aria-label', 'Hidden');
                    icon.setAttribute('aria-pressed', 'false');
                }
            }

            function syncAllVisibilityIcons() {
                visibilityInputs.forEach((input) => {
                    syncVisibilityIcon(input);
                });
            }

            function syncComponentToggle(componentId) {
                const group = groupMap.get(componentId) || [];
                const toggle = componentToggles.find((item) => (item.getAttribute('data-component-id') || '') === componentId);
                if (!toggle) return;
                const checked = group.filter((cb) => cb.checked).length;
                toggle.checked = (group.length > 0 && checked === group.length);
                toggle.indeterminate = (checked > 0 && checked < group.length);
                syncVisibilityIcon(toggle);
            }

            function syncAllComponentToggles() {
                componentToggles.forEach((toggle) => {
                    const componentId = toggle.getAttribute('data-component-id') || '';
                    syncComponentToggle(componentId);
                });
            }

            function syncMetaHidden() {
                ensureStudentColumnVisible();
                if (studentNoHidden && studentNoToggle) studentNoHidden.value = studentNoToggle.checked ? '1' : '0';
                if (studentNameHidden && studentNameToggle) studentNameHidden.value = studentNameToggle.checked ? '1' : '0';
                if (primaryTeacherHidden && primaryTeacherToggle) primaryTeacherHidden.value = primaryTeacherToggle.checked ? '1' : '0';
                if (studentsCountHidden && studentsCountToggle) studentsCountHidden.value = studentsCountToggle.checked ? '1' : '0';
                if (courseYearHidden && courseYearToggle) courseYearHidden.value = courseYearToggle.checked ? '1' : '0';
                if (viewLabelHidden && viewLabelToggle) viewLabelHidden.value = viewLabelToggle.checked ? '1' : '0';
                if (classRecordIdHidden && classRecordIdToggle) classRecordIdHidden.value = classRecordIdToggle.checked ? '1' : '0';
                if (generatedAtHidden && generatedAtToggle) generatedAtHidden.value = generatedAtToggle.checked ? '1' : '0';
                if (includeAnalyticsHidden && includeAnalyticsToggle) includeAnalyticsHidden.value = includeAnalyticsToggle.checked ? '1' : '0';
            }

            function ensureStudentColumnVisible() {
                if (!studentNoToggle || !studentNameToggle) return;
                if (!studentNoToggle.checked && !studentNameToggle.checked) {
                    studentNameToggle.checked = true;
                    studentNameToggle.indeterminate = false;
                    syncVisibilityIcon(studentNameToggle);
                }
            }

            function syncPrintColsHidden() {
                if (!printColsHiddenHost) return;
                printColsHiddenHost.innerHTML = '';
                checks.forEach((cb) => {
                    if (!cb.checked) return;
                    const val = String(cb.value || '').trim();
                    if (val === '') return;
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'print_cols[]';
                    input.value = val;
                    printColsHiddenHost.appendChild(input);
                });
            }

            function submitSelectionFormNow() {
                syncMetaHidden();
                syncPrintColsHidden();
                saveSelection();
                if (typeof form.requestSubmit === 'function') form.requestSubmit();
                else form.submit();
            }

            function scheduleAutoApply() {
                if (autoApplyTimer) window.clearTimeout(autoApplyTimer);
                autoApplyTimer = window.setTimeout(function () {
                    submitSelectionFormNow();
                }, 140);
            }

            function applySavedSelection() {
                try {
                    const raw = window.localStorage.getItem(storageKey);
                    if (!raw) return;
                    const parsed = JSON.parse(raw);
                    if (Array.isArray(parsed)) {
                        const selectedLegacy = new Set(parsed.map((value) => String(value)));
                        checks.forEach((cb) => {
                            cb.checked = selectedLegacy.has(String(cb.value));
                        });
                        return;
                    }
                    if (typeof parsed !== 'object' || parsed === null) return;
                    const selected = new Set(Array.isArray(parsed.assessments) ? parsed.assessments.map((value) => String(value)) : []);
                    checks.forEach((cb) => {
                        cb.checked = selected.has(String(cb.value));
                    });
                    if (studentNoToggle && typeof parsed.showStudentNo === 'boolean') studentNoToggle.checked = parsed.showStudentNo;
                    if (studentNameToggle && typeof parsed.showStudentName === 'boolean') studentNameToggle.checked = parsed.showStudentName;
                    if (primaryTeacherToggle && typeof parsed.showPrimaryTeacher === 'boolean') primaryTeacherToggle.checked = parsed.showPrimaryTeacher;
                    if (studentsCountToggle && typeof parsed.showStudentsCount === 'boolean') studentsCountToggle.checked = parsed.showStudentsCount;
                    if (courseYearToggle && typeof parsed.showCourseYear === 'boolean') courseYearToggle.checked = parsed.showCourseYear;
                    if (viewLabelToggle && typeof parsed.showViewLabel === 'boolean') viewLabelToggle.checked = parsed.showViewLabel;
                    if (classRecordIdToggle && typeof parsed.showClassRecordId === 'boolean') classRecordIdToggle.checked = parsed.showClassRecordId;
                    if (generatedAtToggle && typeof parsed.showGeneratedAt === 'boolean') generatedAtToggle.checked = parsed.showGeneratedAt;
                    if (includeAnalyticsToggle && typeof parsed.includeAnalyticsPrint === 'boolean') includeAnalyticsToggle.checked = parsed.includeAnalyticsPrint;
                } catch (err) {
                    // Ignore malformed saved state.
                }
            }

            function saveSelection() {
                try {
                    const payload = {
                        assessments: checks.filter((cb) => cb.checked).map((cb) => String(cb.value)),
                        showStudentNo: studentNoToggle ? !!studentNoToggle.checked : true,
                        showStudentName: studentNameToggle ? !!studentNameToggle.checked : true,
                        showPrimaryTeacher: primaryTeacherToggle ? !!primaryTeacherToggle.checked : true,
                        showStudentsCount: studentsCountToggle ? !!studentsCountToggle.checked : true,
                        showCourseYear: courseYearToggle ? !!courseYearToggle.checked : true,
                        showViewLabel: viewLabelToggle ? !!viewLabelToggle.checked : true,
                        showClassRecordId: classRecordIdToggle ? !!classRecordIdToggle.checked : true,
                        showGeneratedAt: generatedAtToggle ? !!generatedAtToggle.checked : true,
                        includeAnalyticsPrint: includeAnalyticsToggle ? !!includeAnalyticsToggle.checked : false
                    };
                    window.localStorage.setItem(storageKey, JSON.stringify(payload));
                } catch (err) {
                    // Ignore storage failures.
                }
            }

            if (!hasUrlFilter) applySavedSelection();
            ensureStudentColumnVisible();
            syncAllComponentToggles();
            syncMetaHidden();
            syncPrintColsHidden();
            syncAllVisibilityIcons();

            visibilityControls.forEach((control) => {
                const toggleInput = control.querySelector('input.er-visibility-toggle');
                const icon = control.querySelector('.er-visibility-icon');
                if (!toggleInput || !icon) return;

                icon.setAttribute('role', 'button');
                icon.setAttribute('tabindex', '0');
                icon.setAttribute('aria-hidden', 'false');

                control.addEventListener('click', function (event) {
                    const clickedIcon = event.target.closest('.er-visibility-icon');
                    if (!clickedIcon) return;
                    event.preventDefault();
                    toggleInput.indeterminate = false;
                    toggleInput.checked = !toggleInput.checked;
                    toggleInput.dispatchEvent(new Event('change', { bubbles: true }));
                });

                icon.addEventListener('keydown', function (event) {
                    if (event.key !== 'Enter' && event.key !== ' ') return;
                    event.preventDefault();
                    toggleInput.indeterminate = false;
                    toggleInput.checked = !toggleInput.checked;
                    toggleInput.dispatchEvent(new Event('change', { bubbles: true }));
                });
            });

            checks.forEach((cb) => {
                cb.addEventListener('change', function () {
                    const componentId = this.getAttribute('data-component-id') || '';
                    syncComponentToggle(componentId);
                    syncVisibilityIcon(this);
                    scheduleAutoApply();
                });
            });

            componentToggles.forEach((toggle) => {
                toggle.addEventListener('change', function () {
                    const componentId = this.getAttribute('data-component-id') || '';
                    const group = groupMap.get(componentId) || [];
                    group.forEach((cb) => {
                        cb.checked = this.checked;
                        syncVisibilityIcon(cb);
                    });
                    syncComponentToggle(componentId);
                    syncVisibilityIcon(this);
                    scheduleAutoApply();
                });
            });

            if (selectAllBtn) {
                selectAllBtn.addEventListener('click', function () {
                    checks.forEach((cb) => {
                        cb.checked = true;
                    });
                    syncAllComponentToggles();
                    syncAllVisibilityIcons();
                    scheduleAutoApply();
                });
            }

            if (selectNoneBtn) {
                selectNoneBtn.addEventListener('click', function () {
                    checks.forEach((cb) => {
                        cb.checked = false;
                    });
                    syncAllComponentToggles();
                    syncAllVisibilityIcons();
                    scheduleAutoApply();
                });
            }

            if (studentNoToggle) {
                studentNoToggle.addEventListener('change', function () {
                    ensureStudentColumnVisible();
                    syncMetaHidden();
                    syncVisibilityIcon(this);
                    scheduleAutoApply();
                });
            }

            if (studentNameToggle) {
                studentNameToggle.addEventListener('change', function () {
                    ensureStudentColumnVisible();
                    syncMetaHidden();
                    syncVisibilityIcon(this);
                    scheduleAutoApply();
                });
            }

            if (includeAnalyticsToggle) {
                includeAnalyticsToggle.addEventListener('change', function () {
                    syncMetaHidden();
                });
            }

            [primaryTeacherToggle, studentsCountToggle, courseYearToggle, viewLabelToggle, classRecordIdToggle, generatedAtToggle]
                .filter(Boolean)
                .forEach((toggle) => {
                    toggle.addEventListener('change', function () {
                        syncMetaHidden();
                        syncVisibilityIcon(this);
                        scheduleAutoApply();
                    });
                });

            function setMetaFieldState(map) {
                Object.keys(map).forEach((key) => {
                    const tuple = map[key];
                    if (!Array.isArray(tuple) || tuple.length < 2) return;
                    const toggle = tuple[0];
                    const value = !!tuple[1];
                    if (!toggle) return;
                    toggle.checked = value;
                    toggle.indeterminate = false;
                    syncVisibilityIcon(toggle);
                });
                syncMetaHidden();
                syncAllVisibilityIcons();
            }

            if (displayAllBtn) {
                displayAllBtn.addEventListener('click', function () {
                    setMetaFieldState({
                        primary_teacher: [primaryTeacherToggle, true],
                        students_count: [studentsCountToggle, true],
                        course_year: [courseYearToggle, true],
                        view_label: [viewLabelToggle, true],
                        class_record_id: [classRecordIdToggle, true],
                        generated_at: [generatedAtToggle, true]
                    });
                    scheduleAutoApply();
                });
            }

            if (displayEssentialsBtn) {
                displayEssentialsBtn.addEventListener('click', function () {
                    setMetaFieldState({
                        primary_teacher: [primaryTeacherToggle, true],
                        students_count: [studentsCountToggle, true],
                        course_year: [courseYearToggle, true],
                        view_label: [viewLabelToggle, true],
                        class_record_id: [classRecordIdToggle, false],
                        generated_at: [generatedAtToggle, false]
                    });
                    scheduleAutoApply();
                });
            }

            form.addEventListener('submit', function () {
                syncMetaHidden();
                syncPrintColsHidden();
                saveSelection();
            });
        })();
    </script>
    <script>
        (function () {
            const rowsSelect = document.getElementById('er-rows-per-page-select');
            const rankingSelect = document.getElementById('er-ranking-basis-select');
            const includeToggle = document.getElementById('er-include-analytics-print');
            const includeHidden = document.getElementById('er-include-analytics-print-hidden');
            const allowedRows = new Set(['15', '20', '25', '30']);
            const allowedRanking = new Set(['avg_trans_strict', 'avg_initial_tiebreak']);
            const defaultRanking = <?php echo json_encode($globalDefaultRankingBasis); ?>;
            const storageKey = <?php echo json_encode('er-print-prefs-' . (int) $classRecordId . '-' . $term); ?>;
            const hasRowsQuery = <?php echo $hasRowsPerPageQuery ? 'true' : 'false'; ?>;
            const hasRankingQuery = <?php echo $hasRankingBasisQuery ? 'true' : 'false'; ?>;
            const hasIncludeQuery = <?php echo $hasIncludeAnalyticsPrintQuery ? 'true' : 'false'; ?>;

            function applyPrintAnalyticsClass() {
                const includeAnalytics = includeToggle ? !!includeToggle.checked : false;
                document.body.classList.toggle('er-print-analytics', includeAnalytics);
                if (includeHidden) includeHidden.value = includeAnalytics ? '1' : '0';
            }

            function savePrefs() {
                try {
                    const payload = {
                        rowsPerPage: rowsSelect ? String(rowsSelect.value || '20') : '20',
                        rankingBasis: rankingSelect ? String(rankingSelect.value || defaultRanking) : defaultRanking,
                        includeAnalyticsPrint: includeToggle ? !!includeToggle.checked : false
                    };
                    window.localStorage.setItem(storageKey, JSON.stringify(payload));
                } catch (err) {
                    // Ignore storage failures.
                }
            }

            function navigateWithPrefs() {
                const nextUrl = new URL(window.location.href);
                if (rowsSelect && allowedRows.has(String(rowsSelect.value || '20'))) {
                    nextUrl.searchParams.set('rows_per_page', String(rowsSelect.value));
                }
                if (rankingSelect && allowedRanking.has(String(rankingSelect.value || defaultRanking))) {
                    nextUrl.searchParams.set('ranking_basis', String(rankingSelect.value));
                }
                nextUrl.searchParams.set('include_analytics_print', includeToggle && includeToggle.checked ? '1' : '0');
                window.location.href = nextUrl.toString();
            }

            if ((!hasRowsQuery || !hasRankingQuery || !hasIncludeQuery) && rowsSelect) {
                try {
                    const raw = window.localStorage.getItem(storageKey);
                    if (raw) {
                        const parsed = JSON.parse(raw);
                        const storedRows = String(parsed && parsed.rowsPerPage ? parsed.rowsPerPage : '');
                        const storedRanking = String(parsed && parsed.rankingBasis ? parsed.rankingBasis : '');
                        const storedInclude = !!(parsed && parsed.includeAnalyticsPrint);

                        if (!hasRowsQuery && allowedRows.has(storedRows)) {
                            rowsSelect.value = storedRows;
                        }
                        if (!hasRankingQuery && rankingSelect && allowedRanking.has(storedRanking)) {
                            rankingSelect.value = storedRanking;
                        }
                        if (!hasIncludeQuery && includeToggle) {
                            includeToggle.checked = storedInclude;
                        }
                        if (
                            (!hasRowsQuery && allowedRows.has(storedRows)) ||
                            (!hasRankingQuery && rankingSelect && allowedRanking.has(storedRanking)) ||
                            (!hasIncludeQuery && includeToggle)
                        ) {
                            navigateWithPrefs();
                            return;
                        }
                    }
                } catch (err) {
                    // Ignore malformed storage payloads.
                }
            }

            applyPrintAnalyticsClass();

            if (rowsSelect) {
                rowsSelect.addEventListener('change', function () {
                    const selected = String(this.value || '20');
                    if (!allowedRows.has(selected)) return;
                    savePrefs();
                    navigateWithPrefs();
                });
            }

            if (rankingSelect) {
                rankingSelect.addEventListener('change', function () {
                    const selected = String(this.value || defaultRanking);
                    if (!allowedRanking.has(selected)) return;
                    savePrefs();
                    navigateWithPrefs();
                });
            }

            if (includeToggle) {
                includeToggle.addEventListener('change', function () {
                    applyPrintAnalyticsClass();
                    savePrefs();
                    navigateWithPrefs();
                });
            }

            window.addEventListener('beforeprint', applyPrintAnalyticsClass);
            window.addEventListener('afterprint', applyPrintAnalyticsClass);
        })();
    </script>
    <?php if ($analyticsEnabled && is_array($analyticsChartData)): ?>
    <script src="assets/vendor/apexcharts/apexcharts.min.js"></script>
    <script>
        (function () {
            const payload = <?php echo json_encode($analyticsChartData, JSON_UNESCAPED_SLASHES); ?>;
            if (!payload || typeof payload !== 'object') return;

            const revealNodes = Array.from(document.querySelectorAll('.er-analytics-shell .er-reveal'));
            if ('IntersectionObserver' in window && revealNodes.length > 0) {
                const observer = new IntersectionObserver(function (entries) {
                    entries.forEach(function (entry) {
                        if (!entry.isIntersecting) return;
                        entry.target.classList.add('is-in');
                        observer.unobserve(entry.target);
                    });
                }, { threshold: 0.14 });
                revealNodes.forEach(function (node) { observer.observe(node); });
            } else {
                revealNodes.forEach(function (node) { node.classList.add('is-in'); });
            }

            const shell = document.querySelector('[data-er-analytics-parallax]');
            const bgLayer = shell ? shell.querySelector('.er-analytics-bg') : null;
            if (shell && bgLayer) {
                let ticking = false;
                const renderParallax = function () {
                    const rect = shell.getBoundingClientRect();
                    const offset = Math.max(-36, Math.min(36, rect.top * -0.06));
                    bgLayer.style.transform = 'translate3d(0,' + offset.toFixed(2) + 'px,0)';
                    ticking = false;
                };
                const onScroll = function () {
                    if (ticking) return;
                    ticking = true;
                    window.requestAnimationFrame(renderParallax);
                };
                window.addEventListener('scroll', onScroll, { passive: true });
                window.addEventListener('resize', onScroll);
                onScroll();
            }

            if (typeof ApexCharts !== 'undefined') {
                const animationCfg = {
                    enabled: true,
                    easing: 'easeinout',
                    speed: 750,
                    animateGradually: { enabled: true, delay: 110 },
                    dynamicAnimation: { enabled: true, speed: 320 }
                };

                const columnEl = document.getElementById('er-analytics-column');
                if (columnEl) {
                    const classSize = Math.max(1, Number(payload.meta && payload.meta.class_size ? payload.meta.class_size : 1));
                    new ApexCharts(columnEl, {
                        chart: { type: 'bar', height: 320, toolbar: { show: false }, animations: animationCfg },
                        plotOptions: { bar: { horizontal: false, columnWidth: '55%', endingShape: 'rounded' } },
                        dataLabels: { enabled: false },
                        stroke: { show: true, width: 1, colors: ['transparent'] },
                        series: payload.column.series || [],
                        xaxis: {
                            categories: payload.column.categories || [],
                            labels: { rotate: 0 }
                        },
                        yaxis: {
                            min: 0,
                            max: classSize,
                            tickAmount: Math.min(classSize, 8),
                            title: { text: 'Student Count' }
                        },
                        fill: { opacity: 0.92 },
                        legend: { position: 'top' },
                        colors: ['#2563eb', '#0ea5e9', '#10b981'],
                        tooltip: { y: { formatter: function (v) { return Number(v).toFixed(2); } } }
                    }).render();
                }

                const radialEl = document.getElementById('er-analytics-radial');
                if (radialEl) {
                    new ApexCharts(radialEl, {
                        chart: { type: 'radialBar', height: 270, animations: animationCfg },
                        series: payload.radial.series || [],
                        labels: payload.radial.labels || [],
                        colors: ['#16a34a', '#eab308', '#dc2626'],
                        plotOptions: {
                            radialBar: {
                                dataLabels: {
                                    name: { fontSize: '12px' },
                                    value: { fontSize: '13px', formatter: function (v) { return Number(v).toFixed(1) + '%'; } },
                                    total: { show: true, label: 'Class', formatter: function () { return 'Status'; } }
                                }
                            }
                        }
                    }).render();
                }

                const radarEl = document.getElementById('er-analytics-radar');
                if (radarEl) {
                    new ApexCharts(radarEl, {
                        chart: { type: 'radar', height: 300, animations: animationCfg, toolbar: { show: false } },
                        series: payload.radar.series || [],
                        xaxis: { categories: payload.radar.categories || [] },
                        yaxis: { min: 0, max: 100 },
                        stroke: { width: 2 },
                        fill: { opacity: 0.2 },
                        markers: { size: 3 },
                        legend: { position: 'top' },
                        colors: ['#ef4444', '#3b82f6', '#22c55e']
                    }).render();
                }

                const heatmapEl = document.getElementById('er-analytics-heatmap');
                if (heatmapEl) {
                    const heatSeries = payload.heatmap.series || [];
                    const dynamicHeatHeight = Math.max(280, Math.min(720, (heatSeries.length * 18) + 120));
                    new ApexCharts(heatmapEl, {
                        chart: { type: 'heatmap', height: dynamicHeatHeight, toolbar: { show: false }, animations: animationCfg },
                        dataLabels: { enabled: false },
                        series: heatSeries,
                        colors: ['#0ea5e9'],
                        plotOptions: {
                            heatmap: {
                                colorScale: {
                                    ranges: [
                                        { from: 0, to: 69.99, color: '#f87171', name: 'Low' },
                                        { from: 70, to: 84.99, color: '#facc15', name: 'Medium' },
                                        { from: 85, to: 100, color: '#22c55e', name: 'High' }
                                    ]
                                }
                            }
                        },
                        xaxis: { labels: { rotate: 0 } },
                        yaxis: { labels: { show: true, maxWidth: 110 } },
                        tooltip: { y: { formatter: function (v) { return Number(v).toFixed(2); } } }
                    }).render();
                }

                const pieEl = document.getElementById('er-analytics-pie');
                if (pieEl) {
                    new ApexCharts(pieEl, {
                        chart: { type: 'pie', height: 260, animations: animationCfg },
                        series: payload.pie.series || [],
                        labels: payload.pie.labels || [],
                        legend: { position: 'bottom' },
                        colors: ['#16a34a', '#65a30d', '#eab308', '#f97316', '#ef4444']
                    }).render();
                }
            }

            const contourCanvas = document.getElementById('er-analytics-contour');
            if (contourCanvas && payload.contour && Array.isArray(payload.contour.points)) {
                const ctx = contourCanvas.getContext('2d');
                const points = payload.contour.points;
                const xMin = Number(payload.contour.x_min || 50);
                const xMax = Number(payload.contour.x_max || 100);
                const yMin = Number(payload.contour.y_min || 50);
                const yMax = Number(payload.contour.y_max || 100);
                const levels = [0.15, 0.3, 0.45, 0.6, 0.75];
                const colors = ['#ecfeff', '#bae6fd', '#7dd3fc', '#38bdf8', '#0ea5e9'];

                const renderContour = function () {
                    const w = Math.max(320, contourCanvas.clientWidth || 520);
                    const h = Math.max(220, contourCanvas.clientHeight || 220);
                    contourCanvas.width = w;
                    contourCanvas.height = h;

                    ctx.clearRect(0, 0, w, h);
                    ctx.fillStyle = '#f8fafc';
                    ctx.fillRect(0, 0, w, h);

                    const pad = { l: 38, r: 14, t: 14, b: 28 };
                    const gw = 60;
                    const gh = 42;
                    const sigma = 0.085;
                    const density = [];
                    let maxD = 0;

                    for (let yi = 0; yi < gh; yi++) {
                        density[yi] = [];
                        const gy = yi / (gh - 1);
                        for (let xi = 0; xi < gw; xi++) {
                            const gx = xi / (gw - 1);
                            let d = 0;
                            for (let i = 0; i < points.length; i++) {
                                const p = points[i];
                                const px = (Number(p.x) - xMin) / (xMax - xMin);
                                const py = 1 - ((Number(p.y) - yMin) / (yMax - yMin));
                                const dx = gx - px;
                                const dy = gy - py;
                                d += Math.exp(-(dx * dx + dy * dy) / (2 * sigma * sigma));
                            }
                            density[yi][xi] = d;
                            if (d > maxD) maxD = d;
                        }
                    }
                    if (maxD <= 0) maxD = 1;

                    const cellW = (w - pad.l - pad.r) / (gw - 1);
                    const cellH = (h - pad.t - pad.b) / (gh - 1);
                    for (let yi = 0; yi < gh - 1; yi++) {
                        for (let xi = 0; xi < gw - 1; xi++) {
                            const v = density[yi][xi] / maxD;
                            let color = colors[0];
                            for (let li = levels.length - 1; li >= 0; li--) {
                                if (v >= levels[li]) { color = colors[li]; break; }
                            }
                            ctx.fillStyle = color;
                            ctx.fillRect(pad.l + xi * cellW, pad.t + yi * cellH, cellW + 0.6, cellH + 0.6);
                        }
                    }

                    ctx.strokeStyle = '#0369a1';
                    ctx.lineWidth = 0.8;
                    for (let li = 0; li < levels.length; li++) {
                        const lvl = levels[li];
                        ctx.beginPath();
                        for (let yi = 0; yi < gh - 1; yi++) {
                            for (let xi = 0; xi < gw - 1; xi++) {
                                const a = density[yi][xi] / maxD;
                                const b = density[yi][xi + 1] / maxD;
                                const c = density[yi + 1][xi] / maxD;
                                if ((a < lvl && b >= lvl) || (a >= lvl && b < lvl)) {
                                    const x = pad.l + (xi + 0.5) * cellW;
                                    const y = pad.t + yi * cellH;
                                    ctx.moveTo(x, y);
                                    ctx.lineTo(x, y + cellH);
                                }
                                if ((a < lvl && c >= lvl) || (a >= lvl && c < lvl)) {
                                    const x2 = pad.l + xi * cellW;
                                    const y2 = pad.t + (yi + 0.5) * cellH;
                                    ctx.moveTo(x2, y2);
                                    ctx.lineTo(x2 + cellW, y2);
                                }
                            }
                        }
                        ctx.stroke();
                    }

                    ctx.strokeStyle = '#334155';
                    ctx.lineWidth = 1;
                    ctx.strokeRect(pad.l, pad.t, w - pad.l - pad.r, h - pad.t - pad.b);

                    ctx.fillStyle = '#0f172a';
                    points.forEach(function (p) {
                        const x = pad.l + ((Number(p.x) - xMin) / (xMax - xMin)) * (w - pad.l - pad.r);
                        const y = pad.t + (1 - ((Number(p.y) - yMin) / (yMax - yMin))) * (h - pad.t - pad.b);
                        ctx.beginPath();
                        ctx.arc(x, y, 2.2, 0, Math.PI * 2);
                        ctx.fill();
                    });

                    ctx.fillStyle = '#334155';
                    ctx.font = '11px "Inter", sans-serif';
                    ctx.fillText('MT Initial', w / 2 - 22, h - 8);
                    ctx.save();
                    ctx.translate(12, h / 2 + 24);
                    ctx.rotate(-Math.PI / 2);
                    ctx.fillText('FT Initial', 0, 0);
                    ctx.restore();
                };

                renderContour();
                window.addEventListener('resize', function () {
                    window.requestAnimationFrame(renderContour);
                });
            }
        })();
    </script>
    <?php endif; ?>
</body>
</html>
