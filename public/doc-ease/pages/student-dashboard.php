<?php include '../layouts/session.php'; ?>
<?php require_approved_student(); ?>
<?php include '../layouts/main.php'; ?>

<?php
$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
require_once __DIR__ . '/../includes/subject_colors.php';
require_once __DIR__ . '/../includes/grading.php';
require_once __DIR__ . '/../includes/learning_materials.php';
ensure_grading_tables($conn);
ensure_learning_material_tables($conn);

if (!function_exists('sd_h')) {
    function sd_h($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('sd_fmt2')) {
    function sd_fmt2($number) {
        return rtrim(rtrim(number_format((float) $number, 2, '.', ''), '0'), '.');
    }
}

if (!function_exists('sd_term_label')) {
    function sd_term_label($term) {
        return strtolower((string) $term) === 'final' ? 'Final' : 'Midterm';
    }
}

if (!function_exists('sd_subject_card_colors')) {
    function sd_subject_card_colors($key) {
        if (function_exists('subject_color_for_key')) {
            $c = subject_color_for_key($key);
            if (is_array($c)) {
                $bg = trim((string) ($c['bg'] ?? ''));
                $border = trim((string) ($c['border'] ?? ''));
                $text = trim((string) ($c['text'] ?? ''));
                if ($bg !== '' && $border !== '' && $text !== '') {
                    return ['bg' => $bg, 'border' => $border, 'text' => $text];
                }
            }
        }
        return ['bg' => '#EEF2F7', 'border' => '#C9D2E3', 'text' => '#274060'];
    }
}

if (!function_exists('sd_load_term_breakdown')) {
    function sd_load_term_breakdown(mysqli $conn, array $classRow, $studentId, $term) {
        $studentId = (int) $studentId;
        $term = strtolower(trim((string) $term));
        if (!in_array($term, ['midterm', 'final'], true)) $term = 'midterm';

        $result = [
            'term' => $term,
            'term_label' => sd_term_label($term),
            'config_id' => 0,
            'has_config' => false,
            'has_assessments' => false,
            'components' => [],
            'component_results' => [],
            'assessments_by_component' => [],
            'term_grade' => 0.0,
        ];

        $subjectId = (int) ($classRow['subject_id'] ?? 0);
        $course = trim((string) ($classRow['course'] ?? ''));
        $yearLevel = trim((string) ($classRow['year_level'] ?? ''));
        $section = trim((string) ($classRow['section'] ?? ''));
        $academicYear = trim((string) ($classRow['academic_year'] ?? ''));
        $semester = trim((string) ($classRow['semester'] ?? ''));

        if ($course === '') $course = 'N/A';
        if ($yearLevel === '') $yearLevel = 'N/A';

        $configId = 0;
        $cfg = $conn->prepare(
            "SELECT id
             FROM section_grading_configs
             WHERE subject_id = ?
               AND course = ?
               AND year = ?
               AND section = ?
               AND academic_year = ?
               AND semester = ?
               AND term = ?
             LIMIT 1"
        );
        if ($cfg) {
            $cfg->bind_param('issssss', $subjectId, $course, $yearLevel, $section, $academicYear, $semester, $term);
            $cfg->execute();
            $res = $cfg->get_result();
            if ($res && $res->num_rows === 1) {
                $configId = (int) ($res->fetch_assoc()['id'] ?? 0);
            }
            $cfg->close();
        }

        if ($configId <= 0) {
            return $result;
        }

        $result['config_id'] = $configId;
        $result['has_config'] = true;

        $components = [];
        $qComp = $conn->prepare(
            "SELECT gc.id,
                    gc.component_name,
                    gc.component_code,
                    gc.weight,
                    gc.display_order,
                    COALESCE(c.category_name, 'Uncategorized') AS category_name
             FROM grading_components gc
             LEFT JOIN grading_categories c ON c.id = gc.category_id
             WHERE gc.section_config_id = ?
               AND gc.is_active = 1
             ORDER BY gc.display_order ASC, gc.id ASC"
        );
        if ($qComp) {
            $qComp->bind_param('i', $configId);
            $qComp->execute();
            $res = $qComp->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                $components[] = $row;
            }
            $qComp->close();
        }
        $result['components'] = $components;

        $assessments = [];
        $qAssess = $conn->prepare(
            "SELECT ga.id,
                    ga.grading_component_id,
                    ga.name,
                    ga.max_score,
                    ga.assessment_mode,
                    ga.module_type,
                    ga.open_at,
                    ga.close_at,
                    ga.time_limit_minutes,
                    ga.attempts_allowed,
                    ga.display_order
             FROM grading_assessments ga
             JOIN grading_components gc ON gc.id = ga.grading_component_id
             WHERE gc.section_config_id = ?
               AND gc.is_active = 1
               AND ga.is_active = 1
             ORDER BY gc.display_order ASC, gc.id ASC, ga.display_order ASC, ga.id ASC"
        );
        if ($qAssess) {
            $qAssess->bind_param('i', $configId);
            $qAssess->execute();
            $res = $qAssess->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                $assessments[] = $row;
            }
            $qAssess->close();
        }
        $result['has_assessments'] = count($assessments) > 0;

        $scoresByAssessment = [];
        $qScores = $conn->prepare(
            "SELECT gas.assessment_id, gas.score
             FROM grading_assessment_scores gas
             JOIN grading_assessments ga ON ga.id = gas.assessment_id
             JOIN grading_components gc ON gc.id = ga.grading_component_id
             WHERE gc.section_config_id = ?
               AND gc.is_active = 1
               AND ga.is_active = 1
               AND gas.student_id = ?"
        );
        if ($qScores) {
            $qScores->bind_param('ii', $configId, $studentId);
            $qScores->execute();
            $res = $qScores->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                $aid = (int) ($row['assessment_id'] ?? 0);
                $scoresByAssessment[$aid] = $row['score'];
            }
            $qScores->close();
        }

        $attemptSummaryByAssessment = [];
        $qAttempts = $conn->prepare(
            "SELECT gaa.assessment_id,
                    SUM(CASE WHEN gaa.status IN ('submitted', 'autosubmitted') THEN 1 ELSE 0 END) AS completed_attempts,
                    MAX(CASE WHEN gaa.status = 'in_progress' THEN gaa.id ELSE 0 END) AS in_progress_attempt_id
             FROM grading_assessment_attempts gaa
             JOIN grading_assessments ga ON ga.id = gaa.assessment_id
             JOIN grading_components gc ON gc.id = ga.grading_component_id
             WHERE gc.section_config_id = ?
               AND gaa.student_id = ?
             GROUP BY gaa.assessment_id"
        );
        if ($qAttempts) {
            $qAttempts->bind_param('ii', $configId, $studentId);
            $qAttempts->execute();
            $res = $qAttempts->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                $aid = (int) ($row['assessment_id'] ?? 0);
                $attemptSummaryByAssessment[$aid] = [
                    'completed_attempts' => (int) ($row['completed_attempts'] ?? 0),
                    'in_progress_attempt_id' => (int) ($row['in_progress_attempt_id'] ?? 0),
                ];
            }
            $qAttempts->close();
        }

        $assignmentSummaryByAssessment = [];
        $qAssignment = $conn->prepare(
            "SELECT gs.assessment_id, gs.status, gs.submitted_at
             FROM grading_assignment_submissions gs
             JOIN grading_assessments ga ON ga.id = gs.assessment_id
             JOIN grading_components gc ON gc.id = ga.grading_component_id
             WHERE gc.section_config_id = ?
               AND gs.student_id = ?"
        );
        if ($qAssignment) {
            $qAssignment->bind_param('ii', $configId, $studentId);
            $qAssignment->execute();
            $res = $qAssignment->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                $aid = (int) ($row['assessment_id'] ?? 0);
                $assignmentSummaryByAssessment[$aid] = [
                    'status' => strtolower(trim((string) ($row['status'] ?? ''))),
                    'submitted_at' => (string) ($row['submitted_at'] ?? ''),
                ];
            }
            $qAssignment->close();
        }

        $assessmentsByComponent = [];
        foreach ($assessments as $assessment) {
            $cid = (int) ($assessment['grading_component_id'] ?? 0);
            $aid = (int) ($assessment['id'] ?? 0);
            $attemptSummary = $attemptSummaryByAssessment[$aid] ?? ['completed_attempts' => 0, 'in_progress_attempt_id' => 0];
            $assignmentSummary = $assignmentSummaryByAssessment[$aid] ?? ['status' => '', 'submitted_at' => ''];
            if (!isset($assessmentsByComponent[$cid])) {
                $assessmentsByComponent[$cid] = [];
            }
            $assessmentsByComponent[$cid][] = [
                'id' => $aid,
                'name' => (string) ($assessment['name'] ?? ''),
                'max_score' => (float) ($assessment['max_score'] ?? 0),
                'assessment_mode' => (string) ($assessment['assessment_mode'] ?? 'manual'),
                'module_type' => grading_normalize_module_type((string) ($assessment['module_type'] ?? 'assessment')),
                'open_at' => (string) ($assessment['open_at'] ?? ''),
                'close_at' => (string) ($assessment['close_at'] ?? ''),
                'time_limit_minutes' => (int) ($assessment['time_limit_minutes'] ?? 0),
                'attempts_allowed' => (int) ($assessment['attempts_allowed'] ?? 1),
                'completed_attempts' => (int) ($attemptSummary['completed_attempts'] ?? 0),
                'in_progress_attempt_id' => (int) ($attemptSummary['in_progress_attempt_id'] ?? 0),
                'assignment_submission_status' => (string) ($assignmentSummary['status'] ?? ''),
                'assignment_submitted_at' => (string) ($assignmentSummary['submitted_at'] ?? ''),
                'score' => array_key_exists($aid, $scoresByAssessment) ? $scoresByAssessment[$aid] : null,
            ];
        }
        $result['assessments_by_component'] = $assessmentsByComponent;

        $termGrade = 0.0;
        $componentResults = [];
        foreach ($components as $component) {
            $cid = (int) ($component['id'] ?? 0);
            $weight = (float) ($component['weight'] ?? 0);
            $rows = $assessmentsByComponent[$cid] ?? [];

            $sumMax = 0.0;
            $sumScore = 0.0;
            foreach ($rows as $row) {
                $maxScore = (float) ($row['max_score'] ?? 0);
                $sumMax += $maxScore;

                $score = $row['score'] ?? null;
                if ($score !== null && is_numeric($score)) {
                    $sumScore += (float) $score;
                }
            }

            $pct = null;
            $weightedPoints = 0.0;
            if ($sumMax > 0) {
                $pct = ($sumScore / $sumMax) * 100.0;
                $weightedPoints = $pct * ($weight / 100.0);
                $termGrade += $weightedPoints;
            }

            $componentResults[$cid] = [
                'sum_score' => $sumScore,
                'sum_max' => $sumMax,
                'pct' => $pct,
                'weighted_points' => $weightedPoints,
                'weight' => $weight,
            ];
        }

        $result['component_results'] = $componentResults;
        $result['term_grade'] = $termGrade;
        return $result;
    }
}

$student = null;
$studentId = 0;
$studentNo = '';
$studentName = '';
$classRows = [];
$classViews = [];
$liveByClass = [];

if ($userId > 0) {
    $st = $conn->prepare(
        "SELECT id, StudentNo, Surname, FirstName, MiddleName
         FROM students
         WHERE user_id = ?
         LIMIT 1"
    );
    if ($st) {
        $st->bind_param('i', $userId);
        $st->execute();
        $res = $st->get_result();
        if ($res && $res->num_rows === 1) {
            $student = $res->fetch_assoc();
        }
        $st->close();
    }
}

if (is_array($student)) {
    $studentId = (int) ($student['id'] ?? 0);
    $studentNo = trim((string) ($student['StudentNo'] ?? ''));
    $studentName = trim(
        (string) ($student['Surname'] ?? '') . ', ' .
        (string) ($student['FirstName'] ?? '') . ' ' .
        (string) ($student['MiddleName'] ?? '')
    );

    if ($studentId > 0) {
        $en = $conn->prepare(
            "SELECT DISTINCT
                    ce.class_record_id,
                    cr.subject_id,
                    cr.section,
                    cr.academic_year,
                    cr.semester,
                    cr.year_level,
                    cr.status AS class_status,
                    s.subject_code,
                    s.subject_name,
                    s.course
             FROM class_enrollments ce
             JOIN class_records cr ON cr.id = ce.class_record_id
             JOIN subjects s ON s.id = cr.subject_id
             WHERE ce.student_id = ?
               AND ce.status = 'enrolled'
             ORDER BY cr.academic_year DESC, cr.semester DESC, s.subject_code ASC, s.subject_name ASC"
        );
        if ($en) {
            $en->bind_param('i', $studentId);
            $en->execute();
            $res = $en->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                $classRows[] = $row;
            }
            $en->close();
        }

        if (count($classRows) > 0) {
            $classIds = [];
            foreach ($classRows as $classRow) {
                $cid = (int) ($classRow['class_record_id'] ?? 0);
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

        foreach ($classRows as $classRow) {
            $classViews[] = [
                'class' => $classRow,
                'midterm' => sd_load_term_breakdown($conn, $classRow, $studentId, 'midterm'),
                'final' => sd_load_term_breakdown($conn, $classRow, $studentId, 'final'),
            ];
        }
    }
}
?>

<head>
    <title>My Grades & Scores | E-Record</title>
    <?php include '../layouts/title-meta.php'; ?>
    <?php include '../layouts/head-css.php'; ?>
    <style>
        .sd-subject-card {
            border: 1px solid var(--sd-card-border, #d5dceb);
            background: linear-gradient(180deg, var(--sd-card-bg, #f6f8fc) 0%, #ffffff 84%);
        }

        .sd-subject-title {
            color: var(--sd-card-text, #203b5f);
        }

        .sd-subject-code {
            color: var(--sd-card-text, #203b5f);
            opacity: 0.82;
        }

        .sd-subject-meta {
            color: var(--sd-card-text, #203b5f);
            opacity: 0.78;
        }

        .sd-class-id-badge {
            border: 1px solid var(--sd-card-border, #d5dceb);
            background: rgba(255, 255, 255, 0.72);
            color: var(--sd-card-text, #203b5f);
        }

        .sd-term-block {
            border: 1px solid rgba(0, 0, 0, 0.08);
            border-radius: 0.5rem;
            padding: 1rem;
            background: rgba(255, 255, 255, 0.84);
        }

        .sd-score-table th,
        .sd-score-table td {
            vertical-align: middle;
        }

        .sd-score-badge {
            font-size: 0.82rem;
            padding: 0.35rem 0.55rem;
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
                                        <li class="breadcrumb-item"><a href="javascript: void(0);">E-Record</a></li>
                                        <li class="breadcrumb-item active">My Grades & Scores</li>
                                    </ol>
                                </div>
                                <h4 class="page-title">My Grades & Scores</h4>
                            </div>
                        </div>
                    </div>

                    <?php if (!is_array($student)): ?>
                        <div class="alert alert-warning">
                            Your student profile is not linked to this account yet. Please contact the administrator.
                        </div>
                    <?php else: ?>
                        <div class="row mb-3">
                            <div class="col-12">
                                <div class="card">
                                    <div class="card-body">
                                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                                            <div>
                                                <h5 class="mb-1"><?php echo sd_h($studentName !== '' ? $studentName : 'Student'); ?></h5>
                                                <p class="text-muted mb-0">
                                                    Student ID: <strong><?php echo sd_h($studentNo !== '' ? $studentNo : 'N/A'); ?></strong>
                                                </p>
                                            </div>
                                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                                <span class="badge bg-info sd-score-badge">Read-only view</span>
                                                <a class="btn btn-sm btn-outline-primary" href="student-attendance.php">
                                                    <i class="ri-checkbox-circle-line me-1" aria-hidden="true"></i>
                                                    Attendance Check-In
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if (count($classViews) === 0): ?>
                            <div class="alert alert-info">
                                No enrolled class records were found for your account.
                            </div>
                        <?php endif; ?>

                        <?php foreach ($classViews as $view): ?>
                            <?php
                            $class = $view['class'];
                            $subjectCode = (string) ($class['subject_code'] ?? '');
                            $subjectName = (string) ($class['subject_name'] ?? '');
                            $section = (string) ($class['section'] ?? '');
                            $academicYear = (string) ($class['academic_year'] ?? '');
                            $semester = (string) ($class['semester'] ?? '');
                            $course = trim((string) ($class['course'] ?? ''));
                            $yearLevel = trim((string) ($class['year_level'] ?? ''));
                            $classRecordId = (int) ($class['class_record_id'] ?? 0);
                            $liveCount = (int) ($liveByClass[$classRecordId] ?? 0);
                            if ($course === '') $course = 'N/A';
                            if ($yearLevel === '') $yearLevel = 'N/A';
                            $subjectColorKey = $subjectCode !== '' ? $subjectCode : ($subjectName !== '' ? $subjectName : ('class-' . $classRecordId));
                            $subjectColor = sd_subject_card_colors($subjectColorKey);
                            $subjectCardBg = (string) ($subjectColor['bg'] ?? '#EEF2F7');
                            $subjectCardBorder = (string) ($subjectColor['border'] ?? '#C9D2E3');
                            $subjectCardText = (string) ($subjectColor['text'] ?? '#274060');
                            ?>
                            <div class="row mb-3">
                                <div class="col-12">
                                    <div
                                        class="card sd-subject-card"
                                        style="
                                            --sd-card-bg: <?php echo sd_h($subjectCardBg); ?>;
                                            --sd-card-border: <?php echo sd_h($subjectCardBorder); ?>;
                                            --sd-card-text: <?php echo sd_h($subjectCardText); ?>;
                                        "
                                    >
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                                <div>
                                                    <h5 class="mb-1 sd-subject-title">
                                                        <?php echo sd_h($subjectName); ?>
                                                        <?php if ($subjectCode !== ''): ?>
                                                            <span class="sd-subject-code">(<?php echo sd_h($subjectCode); ?>)</span>
                                                        <?php endif; ?>
                                                    </h5>
                                                    <p class="mb-0 sd-subject-meta">
                                                        Section: <?php echo sd_h($section !== '' ? $section : 'N/A'); ?> |
                                                        <?php echo sd_h($academicYear !== '' ? $academicYear : 'N/A'); ?> |
                                                        <?php echo sd_h($semester !== '' ? $semester : 'N/A'); ?> |
                                                        <?php echo sd_h($course); ?> / <?php echo sd_h($yearLevel); ?>
                                                    </p>
                                                </div>
                                                <div class="d-flex flex-column align-items-end gap-2">
                                                    <span class="badge sd-score-badge sd-class-id-badge">
                                                        Class Record #<?php echo $classRecordId; ?>
                                                    </span>
                                                    <?php if ($liveCount > 0): ?>
                                                        <span class="badge bg-danger-subtle text-danger sd-score-badge">
                                                            Live Now: <?php echo (int) $liveCount; ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <a class="btn btn-sm btn-outline-secondary" href="student-learning-materials.php?class_record_id=<?php echo (int) $classRecordId; ?>">
                                                        <i class="ri-article-line me-1" aria-hidden="true"></i>
                                                        Learning Materials
                                                    </a>
                                                </div>
                                            </div>

                                            <?php
                                            $terms = [
                                                $view['midterm'],
                                                $view['final'],
                                            ];
                                            ?>

                                            <?php foreach ($terms as $termData): ?>
                                                <?php
                                                $termKey = strtolower((string) ($termData['term'] ?? 'midterm'));
                                                $termBadgeClass = $termKey === 'final' ? 'bg-success' : 'bg-primary';
                                                ?>
                                                <div class="sd-term-block mt-3">
                                                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                                                        <h6 class="mb-0"><?php echo sd_h((string) ($termData['term_label'] ?? 'Term')); ?></h6>
                                                        <span class="badge <?php echo sd_h($termBadgeClass); ?> sd-score-badge">
                                                            Term Grade:
                                                            <?php echo !empty($termData['has_assessments']) ? sd_h(sd_fmt2((float) ($termData['term_grade'] ?? 0))) : '-'; ?>
                                                        </span>
                                                    </div>

                                                    <?php if (empty($termData['has_config'])): ?>
                                                        <div class="alert alert-light border mb-0">
                                                            No grading configuration found for this term.
                                                        </div>
                                                    <?php elseif (count((array) ($termData['components'] ?? [])) === 0): ?>
                                                        <div class="text-muted">No active grading components found for this term.</div>
                                                    <?php else: ?>
                                                        <div class="table-responsive">
                                                            <table class="table table-sm table-bordered sd-score-table mb-0">
                                                                <thead class="table-light">
                                                                    <tr>
                                                                        <th>Component</th>
                                                                        <th style="width: 110px;">Weight</th>
                                                                        <th style="width: 160px;">Score</th>
                                                                        <th style="width: 120px;">Percent</th>
                                                                        <th style="width: 130px;">Points</th>
                                                                    </tr>
                                                                </thead>
                                                                <tbody>
                                                                    <?php foreach ((array) ($termData['components'] ?? []) as $component): ?>
                                                                        <?php
                                                                        $cid = (int) ($component['id'] ?? 0);
                                                                        $componentResult = $termData['component_results'][$cid] ?? [
                                                                            'sum_score' => 0.0,
                                                                            'sum_max' => 0.0,
                                                                            'pct' => null,
                                                                            'weighted_points' => 0.0,
                                                                            'weight' => 0.0,
                                                                        ];
                                                                        ?>
                                                                        <tr>
                                                                            <td>
                                                                                <div class="fw-semibold"><?php echo sd_h((string) ($component['component_name'] ?? 'Component')); ?></div>
                                                                                <small class="text-muted"><?php echo sd_h((string) ($component['category_name'] ?? '')); ?></small>
                                                                            </td>
                                                                            <td><?php echo sd_h(sd_fmt2((float) ($componentResult['weight'] ?? 0))); ?>%</td>
                                                                            <td><?php echo sd_h(sd_fmt2((float) ($componentResult['sum_score'] ?? 0))); ?> / <?php echo sd_h(sd_fmt2((float) ($componentResult['sum_max'] ?? 0))); ?></td>
                                                                            <td>
                                                                                <?php if (($componentResult['pct'] ?? null) !== null): ?>
                                                                                    <?php echo sd_h(sd_fmt2((float) $componentResult['pct'])); ?>%
                                                                                <?php else: ?>
                                                                                    -
                                                                                <?php endif; ?>
                                                                            </td>
                                                                            <td><?php echo sd_h(sd_fmt2((float) ($componentResult['weighted_points'] ?? 0))); ?></td>
                                                                        </tr>
                                                                    <?php endforeach; ?>
                                                                </tbody>
                                                            </table>
                                                        </div>

                                                        <?php foreach ((array) ($termData['components'] ?? []) as $component): ?>
                                                            <?php
                                                            $cid = (int) ($component['id'] ?? 0);
                                                            $assessmentRows = $termData['assessments_by_component'][$cid] ?? [];
                                                            ?>
                                                            <div class="mt-3">
                                                                <h6 class="mb-2">
                                                                    <?php echo sd_h((string) ($component['component_name'] ?? 'Component')); ?>
                                                                    <small class="text-muted">
                                                                        (<?php echo sd_h((string) ($component['category_name'] ?? '')); ?>)
                                                                    </small>
                                                                </h6>

                                                                <?php if (count($assessmentRows) === 0): ?>
                                                                    <div class="text-muted">No assessments under this component.</div>
                                                                <?php else: ?>
                                                                    <div class="table-responsive">
                                                                        <table class="table table-sm table-striped table-bordered sd-score-table mb-0">
                                                                            <thead class="table-light">
                                                                                <tr>
                                                                                    <th>Assessment</th>
                                                                                    <th style="width: 120px;">Type</th>
                                                                                    <th style="width: 130px;">Max Score</th>
                                                                                    <th style="width: 130px;">Your Score</th>
                                                                                    <th style="width: 130px;">Attempts</th>
                                                                                    <th style="width: 150px;">Action</th>
                                                                                </tr>
                                                                            </thead>
                                                                            <tbody>
                                                                                <?php foreach ($assessmentRows as $assessment): ?>
                                                                                    <tr>
                                                                                        <td>
                                                                                            <div class="fw-semibold"><?php echo sd_h((string) ($assessment['name'] ?? 'Assessment')); ?></div>
                                                                                            <?php
                                                                                            $openAt = trim((string) ($assessment['open_at'] ?? ''));
                                                                                            $closeAt = trim((string) ($assessment['close_at'] ?? ''));
                                                                                            $openLabel = $openAt;
                                                                                            $closeLabel = $closeAt;
                                                                                            if ($openAt !== '') {
                                                                                                $openTs = strtotime($openAt);
                                                                                                if ($openTs) $openLabel = date('Y-m-d H:i', $openTs);
                                                                                            }
                                                                                            if ($closeAt !== '') {
                                                                                                $closeTs = strtotime($closeAt);
                                                                                                if ($closeTs) $closeLabel = date('Y-m-d H:i', $closeTs);
                                                                                            }
                                                                                            ?>
                                                                                            <?php if ($openAt !== '' || $closeAt !== ''): ?>
                                                                                                <div class="text-muted small">
                                                                                                    <?php if ($openAt !== ''): ?>Opens: <?php echo sd_h((string) $openLabel); ?><?php endif; ?>
                                                                                                    <?php if ($openAt !== '' && $closeAt !== ''): ?> | <?php endif; ?>
                                                                                                    <?php if ($closeAt !== ''): ?>Closes: <?php echo sd_h((string) $closeLabel); ?><?php endif; ?>
                                                                                                </div>
                                                                                            <?php endif; ?>
                                                                                        </td>
                                                                                        <td>
                                                                                            <?php $assessmentMode = strtolower((string) ($assessment['assessment_mode'] ?? 'manual')); ?>
                                                                                            <?php
                                                                                            $moduleType = grading_normalize_module_type((string) ($assessment['module_type'] ?? 'assessment'));
                                                                                            $moduleInfo = grading_module_info($moduleType);
                                                                                            $moduleLabel = (string) ($moduleInfo['label'] ?? 'Assessment');
                                                                                            $moduleKind = strtolower((string) ($moduleInfo['kind'] ?? 'assessment'));
                                                                                            $moduleClass = 'bg-secondary-subtle text-secondary';
                                                                                            if ($moduleKind === 'activity') $moduleClass = 'bg-primary-subtle text-primary';
                                                                                            elseif ($moduleKind === 'resource') $moduleClass = 'bg-info-subtle text-info';
                                                                                            ?>
                                                                                            <?php if ($assessmentMode === 'quiz'): ?>
                                                                                                <span class="badge bg-primary-subtle text-primary">Quiz</span>
                                                                                                <?php $limitMin = (int) ($assessment['time_limit_minutes'] ?? 0); ?>
                                                                                                <?php if ($limitMin > 0): ?>
                                                                                                    <div class="text-muted small mt-1"><?php echo (int) $limitMin; ?> min</div>
                                                                                                <?php endif; ?>
                                                                                            <?php else: ?>
                                                                                                <span class="badge bg-secondary-subtle text-secondary">Manual</span>
                                                                                            <?php endif; ?>
                                                                                            <?php if ($moduleType !== 'assessment'): ?>
                                                                                                <div class="mt-1">
                                                                                                    <span class="badge <?php echo sd_h($moduleClass); ?>"><?php echo sd_h($moduleLabel); ?></span>
                                                                                                </div>
                                                                                            <?php endif; ?>
                                                                                        </td>
                                                                                        <td><?php echo sd_h(sd_fmt2((float) ($assessment['max_score'] ?? 0))); ?></td>
                                                                                        <td>
                                                                                            <?php
                                                                                            $scoreValue = $assessment['score'] ?? null;
                                                                                            echo $scoreValue !== null && is_numeric($scoreValue)
                                                                                                ? sd_h(sd_fmt2((float) $scoreValue))
                                                                                                : '-';
                                                                                            ?>
                                                                                        </td>
                                                                                        <td>
                                                                                            <?php if (($assessmentMode ?? 'manual') === 'quiz'): ?>
                                                                                                <?php
                                                                                                $attemptsAllowed = (int) ($assessment['attempts_allowed'] ?? 1);
                                                                                                if ($attemptsAllowed < 1) $attemptsAllowed = 1;
                                                                                                $completedAttempts = (int) ($assessment['completed_attempts'] ?? 0);
                                                                                                ?>
                                                                                                <?php echo (int) $completedAttempts; ?> / <?php echo (int) $attemptsAllowed; ?>
                                                                                            <?php else: ?>
                                                                                                <?php if ($moduleType === 'assignment'): ?>
                                                                                                    <?php
                                                                                                    $subStatus = strtolower(trim((string) ($assessment['assignment_submission_status'] ?? '')));
                                                                                                    $subLabel = '-';
                                                                                                    if ($subStatus === 'draft') $subLabel = 'Draft';
                                                                                                    elseif ($subStatus === 'submitted') $subLabel = 'Submitted';
                                                                                                    elseif ($subStatus === 'graded') $subLabel = 'Graded';
                                                                                                    ?>
                                                                                                    <?php echo sd_h($subLabel); ?>
                                                                                                <?php else: ?>
                                                                                                    -
                                                                                                <?php endif; ?>
                                                                                            <?php endif; ?>
                                                                                        </td>
                                                                                        <td>
                                                                                            <?php if (($assessmentMode ?? 'manual') === 'quiz'): ?>
                                                                                                <?php
                                                                                                $inProgressAttemptId = (int) ($assessment['in_progress_attempt_id'] ?? 0);
                                                                                                $quizUrl = 'student-quiz-attempt.php?assessment_id=' . (int) ($assessment['id'] ?? 0);
                                                                                                $proofUrl = 'student-assessment-module.php?assessment_id=' . (int) ($assessment['id'] ?? 0);
                                                                                                ?>
                                                                                                <div class="d-flex flex-wrap gap-1">
                                                                                                    <a class="btn btn-sm btn-outline-primary" href="<?php echo sd_h($quizUrl); ?>">
                                                                                                        <?php echo $inProgressAttemptId > 0 ? 'Resume' : 'Open Quiz'; ?>
                                                                                                    </a>
                                                                                                    <a class="btn btn-sm btn-outline-secondary" href="<?php echo sd_h($proofUrl); ?>">
                                                                                                        Upload Proof
                                                                                                    </a>
                                                                                                </div>
                                                                                            <?php elseif ($moduleType !== 'assessment'): ?>
                                                                                                <?php $moduleUrl = 'student-assessment-module.php?assessment_id=' . (int) ($assessment['id'] ?? 0); ?>
                                                                                                <a class="btn btn-sm btn-outline-info" href="<?php echo sd_h($moduleUrl); ?>">
                                                                                                    <?php echo $moduleType === 'assignment' ? 'Open Assignment' : 'Open Module'; ?>
                                                                                                </a>
                                                                                            <?php else: ?>
                                                                                                <?php $moduleUrl = 'student-assessment-module.php?assessment_id=' . (int) ($assessment['id'] ?? 0); ?>
                                                                                                <a class="btn btn-sm btn-outline-secondary" href="<?php echo sd_h($moduleUrl); ?>">
                                                                                                    Open Assessment
                                                                                                </a>
                                                                                            <?php endif; ?>
                                                                                        </td>
                                                                                    </tr>
                                                                                <?php endforeach; ?>
                                                                            </tbody>
                                                                        </table>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

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
