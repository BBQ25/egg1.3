<?php include '../layouts/session.php'; ?>
<?php require_any_active_role(['admin', 'teacher']); ?>
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

function gsp_student_display_name(array $studentRow) {
    $surname = trim((string) ($studentRow['surname'] ?? ''));
    $firstname = trim((string) ($studentRow['firstname'] ?? ''));
    $middlename = trim((string) ($studentRow['middlename'] ?? ''));
    $middleInitial = $middlename !== '' ? strtoupper(substr($middlename, 0, 1)) . '.' : '';

    $parts = [];
    if ($surname !== '') $parts[] = $surname . ',';
    if ($firstname !== '') $parts[] = $firstname;
    if ($middleInitial !== '') $parts[] = $middleInitial;

    $name = trim(implode(' ', $parts));
    if ($name === '') $name = 'Student';
    return $name;
}

function gsp_fmt_grade_value($value) {
    if ($value === null || !is_numeric($value)) return '';
    return number_format((float) $value, 1, '.', '');
}

function gsp_asset_version_token($absPath) {
    $hash = @md5_file($absPath);
    if (is_string($hash) && $hash !== '') return substr($hash, 0, 12);
    $mtime = @filemtime($absPath);
    $size = @filesize($absPath);
    return (string) (($mtime ?: 0) . '-' . ($size ?: 0));
}

function gsp_render_slsu_header() {
    $headerPath = __DIR__ . '/../assets/images/report-template/header-strip-template.png';
    $ver = gsp_asset_version_token($headerPath);
    $src = 'assets/images/report-template/header-strip-template.png' . ($ver !== '' ? ('?v=' . $ver) : '');
    ?>
    <div class="slsu-header">
        <img class="slsu-header-strip" src="<?php echo gsp_h($src); ?>" alt="SLSU Header">
    </div>
    <?php
}

function gsp_render_slsu_footer() {
    $qsPath = __DIR__ . '/../assets/images/report-template/image17.png';
    $socotecPath = __DIR__ . '/../assets/images/report-template/image18.png';
    $qsVer = gsp_asset_version_token($qsPath);
    $socotecVer = gsp_asset_version_token($socotecPath);
    $qsSrc = 'assets/images/report-template/image17.png' . ($qsVer !== '' ? ('?v=' . $qsVer) : '');
    $socotecSrc = 'assets/images/report-template/image18.png' . ($socotecVer !== '' ? ('?v=' . $socotecVer) : '');
    ?>
    <div class="slsu-footer">
        <img class="slsu-footer-qs" src="<?php echo gsp_h($qsSrc); ?>" alt="QS Rated Good">
        <img class="slsu-footer-socotec" src="<?php echo gsp_h($socotecSrc); ?>" alt="Socotec ISO 9001:2015">
    </div>
    <?php
}

function gsp_sample_term_grade_value($classRecordId, $studentId, $salt) {
    $key = (string) $classRecordId . ':' . (string) $studentId . ':' . (string) $salt;
    $hash = crc32($key);
    if ($hash < 0) $hash = $hash * -1;
    $tenths = (int) ($hash % 21); // 0..20
    return 1.0 + ((float) $tenths / 10.0); // 1.0 .. 3.0
}

function gsp_build_sample_class_grade($classRecordId, $studentId) {
    $key = (string) $classRecordId . ':' . (string) $studentId;
    $hash = crc32($key);
    if ($hash < 0) $hash = $hash * -1;

    // Deterministic sample statuses so preview is stable on reload.
    $isDropped = (($hash % 59) === 0);
    if ($isDropped) {
        return [
            'mt' => null,
            'ft' => null,
            'avg' => null,
            'inc' => '',
            'dr' => 'DR',
            'status' => 'dropped',
        ];
    }

    $isIncomplete = (($hash % 23) === 0);
    $mt = gsp_sample_term_grade_value($classRecordId, $studentId, 'mt');
    $ft = gsp_sample_term_grade_value($classRecordId, $studentId, 'ft');

    if ($isIncomplete) {
        if ((($hash >> 3) % 2) === 0) {
            $ft = null;
        } else {
            $mt = null;
        }
        return [
            'mt' => $mt,
            'ft' => $ft,
            'avg' => null,
            'inc' => 'INC',
            'dr' => '',
            'status' => 'incomplete',
        ];
    }

    $avg = round((((float) $mt) + ((float) $ft)) / 2.0, 1);
    return [
        'mt' => $mt,
        'ft' => $ft,
        'avg' => $avg,
        'inc' => '',
        'dr' => '',
        'status' => 'graded',
    ];
}

function gsp_load_class_roster(mysqli $conn, $classRecordId) {
    $classRecordId = (int) $classRecordId;
    if ($classRecordId <= 0) return [];

    $rows = [];
    $q = $conn->prepare(
        "SELECT ce.student_id,
                st.StudentNo AS student_no,
                st.Surname AS surname,
                st.FirstName AS firstname,
                st.MiddleName AS middlename
         FROM class_enrollments ce
         JOIN students st ON st.id = ce.student_id
         WHERE ce.class_record_id = ? AND ce.status = 'enrolled'
         ORDER BY st.Surname ASC, st.FirstName ASC, st.MiddleName ASC, st.StudentNo ASC"
    );
    if (!$q) return $rows;
    $q->bind_param('i', $classRecordId);
    $q->execute();
    $res = $q->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
        $rows[] = $row;
    }
    $q->close();
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

$targetTeacherEmail = 'langging@erecord.com';
$targetTeacherUsername = 'langging';
$targetAcademicYear = '2025 - 2026';
$targetSemester = '1st Semester';
$defaultPerComponent = isset($_GET['per_component']) ? (int) $_GET['per_component'] : 3;
if ($defaultPerComponent < 1) $defaultPerComponent = 1;
if ($defaultPerComponent > 3) $defaultPerComponent = 3;
$autoPrint = isset($_GET['autoprint']) && (string) $_GET['autoprint'] === '1';

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
$plannedRows = gsp_flatten_planned_rows($classRows, 0);
$generatedAt = date('Y-m-d H:i:s');

$sampleIncCount = 0;
$sampleDrCount = 0;
foreach ($classRows as $idx => $row) {
    $classRecordId = (int) ($row['class_record_id'] ?? 0);
    $roster = gsp_load_class_roster($conn, $classRecordId);
    $sampleRows = [];
    $lineNo = 1;
    foreach ($roster as $student) {
        $studentId = (int) ($student['student_id'] ?? 0);
        $grade = gsp_build_sample_class_grade($classRecordId, $studentId);
        if (($grade['inc'] ?? '') === 'INC') $sampleIncCount++;
        if (($grade['dr'] ?? '') === 'DR') $sampleDrCount++;

        $sampleRows[] = [
            'line_no' => $lineNo,
            'student_no' => (string) ($student['student_no'] ?? ''),
            'student_name' => gsp_student_display_name($student),
            'mt' => $grade['mt'],
            'ft' => $grade['ft'],
            'avg' => $grade['avg'],
            'inc' => (string) ($grade['inc'] ?? ''),
            'dr' => (string) ($grade['dr'] ?? ''),
        ];
        $lineNo++;
    }

    $classRows[$idx]['roster'] = $roster;
    $classRows[$idx]['sample_rows'] = $sampleRows;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Grade Seed Subject Class Record Print Preview</title>
    <style>
        :root {
            --page-w: 297mm;
            --template-page-px-w: 1650;
            --template-header-px-w: 1050;
        }
        @page {
            size: A4 landscape;
            margin: 10mm;
        }
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            color: #111827;
            margin: 0;
            padding: 0;
            background: #ffffff;
        }
        .page {
            padding: 14px;
        }
        h1 {
            font-size: 18px;
            margin: 0 0 6px 0;
        }
        .meta {
            margin-bottom: 10px;
            color: #374151;
            font-size: 12px;
        }
        .chips {
            margin: 10px 0 14px 0;
        }
        .chip {
            display: inline-block;
            border: 1px solid #d1d5db;
            background: #f9fafb;
            border-radius: 999px;
            padding: 4px 10px;
            margin: 0 6px 6px 0;
            font-size: 11px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            border: 1px solid #d1d5db;
            padding: 6px 7px;
            vertical-align: top;
            text-align: left;
        }
        thead th {
            background: #f3f4f6;
            font-size: 11px;
        }
        tbody td {
            font-size: 11px;
        }
        .muted {
            color: #6b7280;
            font-size: 10px;
            margin-top: 2px;
        }
        .subject-record {
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 14px;
            break-inside: avoid-page;
            page-break-inside: avoid;
        }
        .slsu-header {
            margin: 0 0 7px 0;
            text-align: center;
        }
        .slsu-header-strip {
            width: calc(var(--page-w) * var(--template-header-px-w) / var(--template-page-px-w));
            max-width: 100%;
            height: auto;
            object-fit: contain;
            display: block;
            margin: 0 auto;
        }
        .subject-record-header {
            margin-bottom: 8px;
        }
        .subject-record-title {
            font-size: 14px;
            font-weight: 700;
            margin: 0 0 2px 0;
        }
        .subject-record-meta {
            font-size: 11px;
            color: #374151;
        }
        .subject-table thead th {
            text-align: center;
        }
        .subject-table td:nth-child(1),
        .subject-table td:nth-child(4),
        .subject-table td:nth-child(5),
        .subject-table td:nth-child(6),
        .subject-table td:nth-child(7),
        .subject-table td:nth-child(8) {
            text-align: center;
        }
        .slsu-footer {
            margin-top: 9px;
            display: flex;
            justify-content: flex-end;
            align-items: flex-end;
            gap: 2.6mm;
            line-height: 1;
        }
        .slsu-footer-qs {
            width: auto;
            height: 17.5mm;
            object-fit: contain;
            display: block;
        }
        .slsu-footer-socotec {
            width: 35mm;
            max-width: none;
            height: auto;
            object-fit: contain;
            display: block;
        }
        .legend {
            font-size: 11px;
            color: #374151;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 8px 10px;
            margin-bottom: 12px;
            background: #f9fafb;
        }
        .legend strong {
            color: #111827;
        }
        .section-break {
            page-break-after: always;
        }
        .toolbar {
            position: sticky;
            top: 0;
            z-index: 20;
            background: #ffffff;
            border-bottom: 1px solid #e5e7eb;
            padding: 10px 14px;
        }
        .btn {
            display: inline-block;
            text-decoration: none;
            border: 1px solid #9ca3af;
            color: #111827;
            padding: 6px 10px;
            border-radius: 6px;
            font-size: 12px;
            margin-right: 6px;
        }
        .btn-primary {
            border-color: #2563eb;
            background: #2563eb;
            color: #ffffff;
        }
        .warn {
            border: 1px solid #f59e0b;
            background: #fffbeb;
            color: #92400e;
            padding: 8px 10px;
            border-radius: 6px;
            margin-bottom: 10px;
            font-size: 12px;
        }
        .no-print {
            display: block;
        }
        @media print {
            .no-print {
                display: none !important;
            }
            .page {
                padding: 0;
            }
            a {
                color: inherit;
                text-decoration: none;
            }
            .subject-record {
                border: 0;
                border-radius: 0;
                padding: 0;
                margin-bottom: 10px;
                min-height: 188mm;
            }
            .section-break {
                page-break-after: always;
            }
        }
    </style>
</head>
<body>
    <div class="toolbar no-print">
        <a class="btn" href="grade-record-seed-preview.php?per_component=<?php echo (int) $defaultPerComponent; ?>">Back to Preview</a>
        <a class="btn btn-primary" href="javascript:window.print()">Print</a>
        <a class="btn" href="grade-record-seed-preview-print.php?per_component=<?php echo (int) $defaultPerComponent; ?>&autoprint=1">Open + Auto Print</a>
    </div>

    <div class="page">
        <h1>Sample Class Record Per Subject (1st Sem 2025-2026)</h1>
        <div class="meta">
            AY <?php echo gsp_h($targetAcademicYear); ?> | <?php echo gsp_h($targetSemester); ?> | Generated <?php echo gsp_h($generatedAt); ?>
        </div>

        <?php if (!is_array($teacher)): ?>
            <div class="warn">Target teacher not found: <?php echo gsp_h($targetTeacherEmail); ?></div>
        <?php else: ?>
            <div class="chips">
                <span class="chip">Teacher: <?php echo gsp_h((string) ($teacher['display_name'] ?? '')); ?></span>
                <span class="chip">Account: <?php echo gsp_h((string) ($teacher['useremail'] ?? '')); ?></span>
                <span class="chip">Classes: <?php echo (int) $summaryClasses; ?></span>
                <span class="chip">Roster Rows: <?php echo (int) $summaryRoster; ?></span>
                <span class="chip">Planned MT: <?php echo (int) $summaryMidtermAssessments; ?></span>
                <span class="chip">Planned FT: <?php echo (int) $summaryFinalAssessments; ?></span>
                <span class="chip">Planned Total: <?php echo (int) $plannedRowsTotal; ?></span>
                <span class="chip">Default per non-project/exam: <?php echo (int) $defaultPerComponent; ?></span>
                <span class="chip">Sample INC rows: <?php echo (int) $sampleIncCount; ?></span>
                <span class="chip">Sample DR rows: <?php echo (int) $sampleDrCount; ?></span>
            </div>

            <div class="warn">
                <strong>Sample only.</strong> This print view is for review and does not save grades. MT/FT values below are deterministic mock values.
            </div>

            <div class="legend">
                <strong>Legend:</strong>
                MT = Midterm, FT = Final Term, AVG = average of MT and FT, INC = incomplete (missing term grade), DR = dropped.
            </div>

            <?php if (count($classRows) === 0): ?>
                <div class="warn">No class records found for the selected term.</div>
            <?php else: ?>
                <?php foreach ($classRows as $classIndex => $classRow): ?>
                    <?php
                    $sampleRows = (array) ($classRow['sample_rows'] ?? []);
                    $midCount = (int) (($classRow['midterm_plan']['assessment_record_count'] ?? 0));
                    $finalCount = (int) (($classRow['final_plan']['assessment_record_count'] ?? 0));
                    ?>
                    <div class="subject-record<?php echo ($classIndex < (count($classRows) - 1)) ? ' section-break' : ''; ?>">
                        <?php gsp_render_slsu_header(); ?>
                        <div class="subject-record-header">
                            <div class="subject-record-title">
                                <?php echo gsp_h((string) ($classRow['section'] ?? '')); ?> - <?php echo gsp_h((string) ($classRow['subject_code'] ?? '')); ?> - <?php echo gsp_h((string) ($classRow['subject_name'] ?? '')); ?>
                            </div>
                            <div class="subject-record-meta">
                                Course: <?php echo gsp_h((string) ($classRow['course'] ?? 'N/A')); ?> |
                                Year: <?php echo gsp_h((string) ($classRow['year_level'] ?? 'N/A')); ?> |
                                Type: <?php echo gsp_h((string) ($classRow['type'] ?? '')); ?> |
                                Students: <?php echo (int) ($classRow['roster_count'] ?? 0); ?> |
                                Planned MT assessments: <?php echo $midCount; ?> |
                                Planned FT assessments: <?php echo $finalCount; ?>
                            </div>
                        </div>

                        <table class="subject-table">
                            <thead>
                                <tr>
                                    <th style="width: 42px;">#</th>
                                    <th style="width: 150px;">Student No</th>
                                    <th>Name</th>
                                    <th style="width: 70px;">MT</th>
                                    <th style="width: 70px;">FT</th>
                                    <th style="width: 70px;">AVG</th>
                                    <th style="width: 80px;">INC</th>
                                    <th style="width: 70px;">DR</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($sampleRows) === 0): ?>
                                    <tr>
                                        <td colspan="8">No enrolled students found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($sampleRows as $sr): ?>
                                        <tr>
                                            <td><?php echo (int) ($sr['line_no'] ?? 0); ?></td>
                                            <td><?php echo gsp_h((string) ($sr['student_no'] ?? '')); ?></td>
                                            <td><?php echo gsp_h((string) ($sr['student_name'] ?? '')); ?></td>
                                            <td><?php echo gsp_h(gsp_fmt_grade_value($sr['mt'] ?? null)); ?></td>
                                            <td><?php echo gsp_h(gsp_fmt_grade_value($sr['ft'] ?? null)); ?></td>
                                            <td><?php echo gsp_h(gsp_fmt_grade_value($sr['avg'] ?? null)); ?></td>
                                            <td><?php echo gsp_h((string) ($sr['inc'] ?? '')); ?></td>
                                            <td><?php echo gsp_h((string) ($sr['dr'] ?? '')); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        <?php gsp_render_slsu_footer(); ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <div class="subject-record">
                <?php gsp_render_slsu_header(); ?>
                <div class="subject-record-header">
                    <div class="subject-record-title">Appendix: Planned Assessment Rows</div>
                    <div class="subject-record-meta">Generated rows that would be created per component (for MT and FT).</div>
                </div>
                <table>
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
                        <?php if (count($plannedRows) === 0): ?>
                            <tr>
                                <td colspan="8">No planned assessment rows available.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($plannedRows as $row): ?>
                                <tr>
                                    <td><?php echo (int) ($row['seq'] ?? 0); ?></td>
                                    <td>
                                        <div><?php echo gsp_h((string) ($row['class_label'] ?? '')); ?></div>
                                        <div class="muted"><?php echo gsp_h((string) ($row['subject_name'] ?? '')); ?></div>
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
                <?php gsp_render_slsu_footer(); ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($autoPrint): ?>
        <script>
            window.addEventListener('load', function () {
                window.print();
            });
        </script>
    <?php endif; ?>
</body>
</html>
