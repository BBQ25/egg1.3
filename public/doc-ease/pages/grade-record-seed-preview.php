<?php include '../layouts/session.php'; ?>
<?php require_any_active_role(['admin', 'teacher']); ?>
<?php include '../layouts/main.php'; ?>

<?php
function gsp_h($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function gsp_normalize_component_type($type) {
    $type = strtolower(trim((string) $type));
    if ($type === '') return 'other';
    return $type;
}

function gsp_planned_assessment_count(array $component, $defaultCount = 3) {
    $name = strtolower(trim((string) ($component['component_name'] ?? '')));
    $code = strtolower(trim((string) ($component['component_code'] ?? '')));
    $type = gsp_normalize_component_type((string) ($component['component_type'] ?? 'other'));

    if ($type === 'project' || $type === 'exam') return 1;
    if (strpos($name, 'project') !== false || strpos($name, 'exam') !== false) return 1;
    if ($code === 'proj' || $code === 'texam' || $code === 'exam') return 1;

    $defaultCount = (int) $defaultCount;
    if ($defaultCount < 1) $defaultCount = 1;
    if ($defaultCount > 3) $defaultCount = 3;
    return $defaultCount;
}

function gsp_assessment_labels($componentName, $count) {
    $componentName = trim((string) $componentName);
    if ($componentName === '') $componentName = 'Component';

    $count = (int) $count;
    if ($count <= 1) return [$componentName];

    $labels = [];
    for ($i = 1; $i <= $count; $i++) {
        $labels[] = $componentName . ' ' . $i;
    }
    return $labels;
}

function gsp_term_label($term) {
    $term = strtolower(trim((string) $term));
    if ($term === 'final') return 'FT';
    return 'MT';
}

function gsp_flatten_planned_rows(array $classRows, $maxRows = 0) {
    $rows = [];
    $seq = 1;
    $maxRows = (int) $maxRows;

    foreach ($classRows as $classRow) {
        $classLabel = trim((string) ($classRow['section'] ?? '')) . ' - ' . trim((string) ($classRow['subject_code'] ?? ''));
        $classLabel = trim($classLabel, ' -');
        if ($classLabel === '') $classLabel = 'Class';
        $subjectName = trim((string) ($classRow['subject_name'] ?? ''));

        $termMaps = [
            ['term' => 'midterm', 'plan' => (array) ($classRow['midterm_plan'] ?? [])],
            ['term' => 'final', 'plan' => (array) ($classRow['final_plan'] ?? [])],
        ];

        foreach ($termMaps as $termMap) {
            $term = (string) ($termMap['term'] ?? 'midterm');
            $plan = (array) ($termMap['plan'] ?? []);
            $components = (array) ($plan['components'] ?? []);

            foreach ($components as $component) {
                $labels = (array) ($component['planned_labels'] ?? []);
                if (count($labels) === 0) $labels = [trim((string) ($component['component_name'] ?? 'Component'))];

                foreach ($labels as $labelIndex => $label) {
                    $rows[] = [
                        'seq' => $seq,
                        'class_label' => $classLabel,
                        'subject_name' => $subjectName,
                        'term' => $term,
                        'term_label' => gsp_term_label($term),
                        'component_name' => (string) ($component['component_name'] ?? ''),
                        'component_type' => (string) ($component['component_type'] ?? 'other'),
                        'component_weight' => (float) ($component['weight'] ?? 0),
                        'assessment_name' => (string) $label,
                        'assessment_index' => ((int) $labelIndex) + 1,
                        'suggested_max_score' => 100,
                    ];
                    $seq++;
                    if ($maxRows > 0 && count($rows) >= $maxRows) {
                        return $rows;
                    }
                }
            }
        }
    }

    return $rows;
}

function gsp_load_term_plan(mysqli $conn, array $classRow, $term, $defaultCount = 3) {
    $subjectId = (int) ($classRow['subject_id'] ?? 0);
    $course = trim((string) ($classRow['course'] ?? 'N/A'));
    $yearLevel = trim((string) ($classRow['year_level'] ?? 'N/A'));
    $section = trim((string) ($classRow['section'] ?? ''));
    $academicYear = trim((string) ($classRow['academic_year'] ?? ''));
    $semester = trim((string) ($classRow['semester'] ?? ''));
    $term = strtolower(trim((string) $term));

    $plan = [
        'term' => $term,
        'config_id' => 0,
        'components' => [],
        'component_count' => 0,
        'assessment_record_count' => 0,
    ];

    if ($subjectId <= 0 || $section === '' || $academicYear === '' || $semester === '' || $term === '') {
        return $plan;
    }

    $cfg = $conn->prepare(
        "SELECT id
         FROM section_grading_configs
         WHERE subject_id = ? AND course = ? AND year = ? AND section = ? AND academic_year = ? AND semester = ? AND term = ?
         LIMIT 1"
    );
    if (!$cfg) return $plan;
    $cfg->bind_param('issssss', $subjectId, $course, $yearLevel, $section, $academicYear, $semester, $term);
    $cfg->execute();
    $cfgRes = $cfg->get_result();
    if (!($cfgRes && $cfgRes->num_rows === 1)) {
        $cfg->close();
        return $plan;
    }
    $cfgRow = $cfgRes->fetch_assoc();
    $cfg->close();

    $configId = (int) ($cfgRow['id'] ?? 0);
    if ($configId <= 0) return $plan;
    $plan['config_id'] = $configId;

    $q = $conn->prepare(
        "SELECT component_name, component_code, component_type, weight, display_order, is_active
         FROM grading_components
         WHERE section_config_id = ?
         ORDER BY display_order ASC, id ASC"
    );
    if (!$q) return $plan;
    $q->bind_param('i', $configId);
    $q->execute();
    $res = $q->get_result();

    while ($res && ($row = $res->fetch_assoc())) {
        $count = gsp_planned_assessment_count($row, $defaultCount);
        $labels = gsp_assessment_labels((string) ($row['component_name'] ?? ''), $count);
        $plan['assessment_record_count'] += $count;
        $plan['components'][] = [
            'component_name' => (string) ($row['component_name'] ?? ''),
            'component_code' => (string) ($row['component_code'] ?? ''),
            'component_type' => (string) ($row['component_type'] ?? 'other'),
            'weight' => (float) ($row['weight'] ?? 0),
            'is_active' => (int) ($row['is_active'] ?? 1),
            'planned_count' => $count,
            'planned_labels' => $labels,
        ];
    }
    $q->close();

    $plan['component_count'] = count($plan['components']);
    return $plan;
}

$targetTeacherEmail = 'langging@erecord.com';
$targetTeacherUsername = 'langging';
$targetAcademicYear = '2025 - 2026';
$targetSemester = '1st Semester';
$defaultPerComponent = isset($_GET['per_component']) ? (int) $_GET['per_component'] : 3;
if ($defaultPerComponent < 1) $defaultPerComponent = 1;
if ($defaultPerComponent > 3) $defaultPerComponent = 3;

$teacher = null;
$teacherStmt = $conn->prepare(
    "SELECT id, username, useremail, COALESCE(NULLIF(TRIM(CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,''))), ''), username) AS display_name
     FROM users
     WHERE role = 'teacher'
       AND (LOWER(useremail) = LOWER(?) OR LOWER(username) = LOWER(?))
     ORDER BY (LOWER(useremail) = LOWER(?)) DESC, id ASC
     LIMIT 1"
);
if ($teacherStmt) {
    $teacherStmt->bind_param('sss', $targetTeacherEmail, $targetTeacherUsername, $targetTeacherEmail);
    $teacherStmt->execute();
    $teacherRes = $teacherStmt->get_result();
    if ($teacherRes && $teacherRes->num_rows === 1) $teacher = $teacherRes->fetch_assoc();
    $teacherStmt->close();
}

$classRows = [];
if (is_array($teacher)) {
    $teacherId = (int) ($teacher['id'] ?? 0);
    $classStmt = $conn->prepare(
        "SELECT cr.id AS class_record_id,
                cr.subject_id,
                cr.section,
                cr.academic_year,
                cr.semester,
                COALESCE(NULLIF(TRIM(cr.year_level), ''), 'N/A') AS year_level,
                COALESCE(NULLIF(TRIM(s.course), ''), 'N/A') AS course,
                s.subject_code,
                s.subject_name,
                s.type,
                COUNT(ce.id) AS roster_count
         FROM class_records cr
         JOIN subjects s ON s.id = cr.subject_id
         JOIN teacher_assignments ta ON ta.class_record_id = cr.id
         LEFT JOIN class_enrollments ce ON ce.class_record_id = cr.id
         WHERE ta.teacher_id = ?
           AND ta.status = 'active'
           AND cr.status = 'active'
           AND cr.academic_year = ?
           AND cr.semester = ?
         GROUP BY cr.id, cr.subject_id, cr.section, cr.academic_year, cr.semester, cr.year_level, s.course, s.subject_code, s.subject_name, s.type
         ORDER BY cr.section ASC, s.subject_code ASC"
    );
    if ($classStmt) {
        $classStmt->bind_param('iss', $teacherId, $targetAcademicYear, $targetSemester);
        $classStmt->execute();
        $classRes = $classStmt->get_result();
        while ($classRes && ($row = $classRes->fetch_assoc())) {
            $row['midterm_plan'] = gsp_load_term_plan($conn, $row, 'midterm', $defaultPerComponent);
            $row['final_plan'] = gsp_load_term_plan($conn, $row, 'final', $defaultPerComponent);
            $classRows[] = $row;
        }
        $classStmt->close();
    }
}

$summaryClasses = count($classRows);
$summaryRoster = 0;
$summaryMidtermAssessments = 0;
$summaryFinalAssessments = 0;
foreach ($classRows as $row) {
    $summaryRoster += (int) ($row['roster_count'] ?? 0);
    $summaryMidtermAssessments += (int) (($row['midterm_plan']['assessment_record_count'] ?? 0));
    $summaryFinalAssessments += (int) (($row['final_plan']['assessment_record_count'] ?? 0));
}
$plannedRowsTotal = $summaryMidtermAssessments + $summaryFinalAssessments;
$plannedRowsLimit = 500;
$plannedRows = gsp_flatten_planned_rows($classRows, $plannedRowsLimit);
$plannedRowsDisplayed = count($plannedRows);
?>

<head>
    <title>Grade Record Seed Preview | E-Record</title>
    <?php include '../layouts/title-meta.php'; ?>
    <?php include '../layouts/head-css.php'; ?>
    <style>
        .gsp-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 999px;
            background: rgba(13, 110, 253, .12);
            color: #0d6efd;
            font-size: 12px;
            font-weight: 600;
            margin-right: 6px;
        }
        .gsp-label {
            display: inline-block;
            border: 1px solid #dce3ee;
            border-radius: 999px;
            padding: 2px 8px;
            font-size: 12px;
            margin: 2px 4px 2px 0;
            background: #fff;
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
                                        <li class="breadcrumb-item active">Grade Record Seed Preview</li>
                                    </ol>
                                </div>
                                <h4 class="page-title">Grade Record Seed Preview</h4>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-warning">
                        <strong>Preview only.</strong> This page does not insert or update any records.
                        It shows the planned MT/FT assessment records using current component setup.
                    </div>

                    <?php if (!is_array($teacher)): ?>
                        <div class="alert alert-danger mb-0">Target teacher not found: <?php echo gsp_h($targetTeacherEmail); ?></div>
                    <?php else: ?>
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex flex-wrap gap-2 align-items-center">
                                    <div class="gsp-chip">Teacher: <?php echo gsp_h((string) ($teacher['display_name'] ?? '')); ?></div>
                                    <div class="gsp-chip">Account: <?php echo gsp_h((string) ($teacher['useremail'] ?? '')); ?></div>
                                    <div class="gsp-chip">AY: <?php echo gsp_h($targetAcademicYear); ?></div>
                                    <div class="gsp-chip">Semester: <?php echo gsp_h($targetSemester); ?></div>
                                    <div class="gsp-chip">Classes: <?php echo (int) $summaryClasses; ?></div>
                                    <div class="gsp-chip">Roster Rows: <?php echo (int) $summaryRoster; ?></div>
                                    <div class="gsp-chip">Planned MT Records: <?php echo (int) $summaryMidtermAssessments; ?></div>
                                    <div class="gsp-chip">Planned FT Records: <?php echo (int) $summaryFinalAssessments; ?></div>
                                    <div class="gsp-chip">Default per non-project/exam: <?php echo (int) $defaultPerComponent; ?></div>
                                </div>
                                <div class="mt-3">
                                    <form method="get" class="row g-2 align-items-end">
                                        <div class="col-auto">
                                            <label class="form-label small mb-1" for="gsp-per-component">Non-project/exam records</label>
                                            <select class="form-select form-select-sm" id="gsp-per-component" name="per_component">
                                                <option value="1" <?php echo $defaultPerComponent === 1 ? 'selected' : ''; ?>>1</option>
                                                <option value="2" <?php echo $defaultPerComponent === 2 ? 'selected' : ''; ?>>2</option>
                                                <option value="3" <?php echo $defaultPerComponent === 3 ? 'selected' : ''; ?>>3</option>
                                            </select>
                                        </div>
                                        <div class="col-auto">
                                            <button class="btn btn-sm btn-primary" type="submit">Refresh Preview</button>
                                        </div>
                                        <div class="col-auto">
                                            <a class="btn btn-sm btn-outline-secondary" target="_blank" href="grade-record-seed-preview-print.php?per_component=<?php echo (int) $defaultPerComponent; ?>">
                                                Subject Class Record Print Preview
                                            </a>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-body">
                                <h5 class="header-title mb-3">Class Plans</h5>
                                <div class="table-responsive">
                                    <table class="table table-bordered table-sm align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>Class</th>
                                                <th>Type</th>
                                                <th>Roster</th>
                                                <th>Midterm Plan</th>
                                                <th>Final Plan</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (count($classRows) === 0): ?>
                                                <tr>
                                                    <td colspan="5" class="text-center text-muted">No classes found for the target term.</td>
                                                </tr>
                                            <?php endif; ?>
                                            <?php foreach ($classRows as $row): ?>
                                                <?php
                                                $mid = (array) ($row['midterm_plan'] ?? []);
                                                $fin = (array) ($row['final_plan'] ?? []);
                                                ?>
                                                <tr>
                                                    <td>
                                                        <div class="fw-semibold">
                                                            <?php echo gsp_h((string) ($row['section'] ?? '')); ?>
                                                            -
                                                            <?php echo gsp_h((string) ($row['subject_code'] ?? '')); ?>
                                                        </div>
                                                        <div class="text-muted small"><?php echo gsp_h((string) ($row['subject_name'] ?? '')); ?></div>
                                                    </td>
                                                    <td><?php echo gsp_h((string) ($row['type'] ?? '')); ?></td>
                                                    <td><?php echo (int) ($row['roster_count'] ?? 0); ?></td>
                                                    <td>
                                                        <div class="small mb-1">
                                                            Components: <strong><?php echo (int) ($mid['component_count'] ?? 0); ?></strong> |
                                                            Planned records: <strong><?php echo (int) ($mid['assessment_record_count'] ?? 0); ?></strong>
                                                        </div>
                                                        <details>
                                                            <summary class="small text-primary">View components</summary>
                                                            <?php foreach ((array) ($mid['components'] ?? []) as $c): ?>
                                                                <div class="mt-2">
                                                                    <div class="small">
                                                                        <strong><?php echo gsp_h((string) ($c['component_name'] ?? '')); ?></strong>
                                                                        (<?php echo gsp_h((string) ($c['component_type'] ?? 'other')); ?>)
                                                                        - weight <?php echo number_format((float) ($c['weight'] ?? 0), 2); ?>%
                                                                        - planned <?php echo (int) ($c['planned_count'] ?? 0); ?> record(s)
                                                                    </div>
                                                                    <div class="mt-1">
                                                                        <?php foreach ((array) ($c['planned_labels'] ?? []) as $label): ?>
                                                                            <span class="gsp-label"><?php echo gsp_h($label); ?></span>
                                                                        <?php endforeach; ?>
                                                                    </div>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </details>
                                                    </td>
                                                    <td>
                                                        <div class="small mb-1">
                                                            Components: <strong><?php echo (int) ($fin['component_count'] ?? 0); ?></strong> |
                                                            Planned records: <strong><?php echo (int) ($fin['assessment_record_count'] ?? 0); ?></strong>
                                                        </div>
                                                        <details>
                                                            <summary class="small text-primary">View components</summary>
                                                            <?php foreach ((array) ($fin['components'] ?? []) as $c): ?>
                                                                <div class="mt-2">
                                                                    <div class="small">
                                                                        <strong><?php echo gsp_h((string) ($c['component_name'] ?? '')); ?></strong>
                                                                        (<?php echo gsp_h((string) ($c['component_type'] ?? 'other')); ?>)
                                                                        - weight <?php echo number_format((float) ($c['weight'] ?? 0), 2); ?>%
                                                                        - planned <?php echo (int) ($c['planned_count'] ?? 0); ?> record(s)
                                                                    </div>
                                                                    <div class="mt-1">
                                                                        <?php foreach ((array) ($c['planned_labels'] ?? []) as $label): ?>
                                                                            <span class="gsp-label"><?php echo gsp_h($label); ?></span>
                                                                        <?php endforeach; ?>
                                                                    </div>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </details>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                                    <h5 class="header-title mb-0">Planned Assessment Records (Flat Preview)</h5>
                                    <div class="text-muted small">
                                        Showing <?php echo (int) $plannedRowsDisplayed; ?> of <?php echo (int) $plannedRowsTotal; ?> planned rows
                                    </div>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Class</th>
                                                <th>Term</th>
                                                <th>Component</th>
                                                <th>Type</th>
                                                <th>Weight</th>
                                                <th>Assessment Name</th>
                                                <th>Max Score</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if ($plannedRowsDisplayed === 0): ?>
                                                <tr>
                                                    <td colspan="8" class="text-center text-muted">No planned assessment records found.</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($plannedRows as $row): ?>
                                                    <tr>
                                                        <td><?php echo (int) ($row['seq'] ?? 0); ?></td>
                                                        <td>
                                                            <div class="fw-semibold"><?php echo gsp_h((string) ($row['class_label'] ?? '')); ?></div>
                                                            <div class="text-muted small"><?php echo gsp_h((string) ($row['subject_name'] ?? '')); ?></div>
                                                        </td>
                                                        <td><?php echo gsp_h((string) ($row['term_label'] ?? 'MT')); ?></td>
                                                        <td><?php echo gsp_h((string) ($row['component_name'] ?? '')); ?></td>
                                                        <td><?php echo gsp_h((string) ($row['component_type'] ?? 'other')); ?></td>
                                                        <td><?php echo number_format((float) ($row['component_weight'] ?? 0), 2); ?>%</td>
                                                        <td><?php echo gsp_h((string) ($row['assessment_name'] ?? '')); ?></td>
                                                        <td><?php echo (int) ($row['suggested_max_score'] ?? 100); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
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
