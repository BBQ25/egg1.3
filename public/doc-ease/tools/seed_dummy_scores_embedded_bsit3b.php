<?php
/**
 * Seed dummy scores for Embedded Systems (IT 316 + IT 316L) for BSIT - 3B.
 *
 * Default behavior:
 * - Only fills missing scores (no row or NULL score).
 * - Seeds both terms (midterm + final) if assessments exist.
 *
 * Usage:
 *   php tools/seed_dummy_scores_embedded_bsit3b.php
 *   php tools/seed_dummy_scores_embedded_bsit3b.php --overwrite
 *   php tools/seed_dummy_scores_embedded_bsit3b.php --term=midterm
 *   php tools/seed_dummy_scores_embedded_bsit3b.php --term=final
 */

require __DIR__ . '/../config/db.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

require_once __DIR__ . '/../includes/grading.php';
ensure_grading_tables($conn);

function out($s) { fwrite(STDOUT, $s . PHP_EOL); }
function err($s) { fwrite(STDERR, $s . PHP_EOL); }

$overwrite = in_array('--overwrite', $argv ?? [], true);
$createMissingAssessments = !in_array('--no-create', $argv ?? [], true);
$perComponent = 1;
foreach (($argv ?? []) as $a) {
    if (strpos($a, '--per-component=') === 0) {
        $n = (int) trim(substr($a, 16));
        if ($n > 0 && $n <= 20) $perComponent = $n;
    }
}
$termFilter = 'all';
foreach (($argv ?? []) as $a) {
    if (strpos($a, '--term=') === 0) {
        $termFilter = strtolower(trim(substr($a, 7)));
    }
}
if (!in_array($termFilter, ['all', 'midterm', 'final'], true)) $termFilter = 'all';

$academicYear = '2025 - 2026';
$semester = '2nd Semester';
$section = 'BSIT - 3B';
$subjectCodes = ['IT 316', 'IT 316L'];

out('Seed Dummy Scores: Embedded Systems (BSIT - 3B)');
out('AY/Sem: ' . $academicYear . ' | ' . $semester);
out('Section: ' . $section);
out('Term: ' . $termFilter);
out('Mode: ' . ($overwrite ? 'OVERWRITE' : 'FILL MISSING'));
out('Create missing assessments: ' . ($createMissingAssessments ? 'yes' : 'no') . ' (per component: ' . $perComponent . ')');

// Resolve subject ids.
$subjects = []; // id => code
$sub = $conn->prepare("SELECT id, subject_code FROM subjects WHERE status='active' AND subject_code = ? LIMIT 1");
foreach ($subjectCodes as $code) {
    $sub->bind_param('s', $code);
    $sub->execute();
    $res = $sub->get_result();
    if (!$res || $res->num_rows !== 1) {
        err("Missing active subject: {$code}");
        exit(1);
    }
    $row = $res->fetch_assoc();
    $subjects[(int) $row['id']] = (string) $row['subject_code'];
}
$sub->close();

$subIdList = implode(',', array_map('intval', array_keys($subjects)));

// Find the active class_records for these subjects in this section+term.
$classRecords = []; // subject_id => ['id'=>, 'teacher_id'=>]
$cr = $conn->query(
    "SELECT id, subject_id, teacher_id
     FROM class_records
     WHERE status='active'
       AND academic_year = '" . $conn->real_escape_string($academicYear) . "'
       AND semester = '" . $conn->real_escape_string($semester) . "'
       AND section = '" . $conn->real_escape_string($section) . "'
       AND subject_id IN ({$subIdList})"
);
while ($cr && ($row = $cr->fetch_assoc())) {
    $sid = (int) ($row['subject_id'] ?? 0);
    $classRecords[$sid] = ['id' => (int) ($row['id'] ?? 0), 'teacher_id' => (int) ($row['teacher_id'] ?? 0)];
}

if (count($classRecords) === 0) {
    err('No active class_records found for IT 316 / IT 316L in this section.');
    exit(1);
}

out('Class Records:');
foreach ($classRecords as $sid => $info) {
    out('  subj#' . (int) $sid . ' (' . ($subjects[$sid] ?? 'unknown') . ') -> CR#' . (int) $info['id'] . ' teacher#' . (int) $info['teacher_id']);
}

// Load enrolled students once per class_record (they should match between lecture/lab, but we do it per record anyway).
$studentsByCr = []; // crid => [student_id=>student_no]
$en = $conn->prepare(
    "SELECT ce.student_id, st.StudentNo AS student_no
     FROM class_enrollments ce
     JOIN students st ON st.id = ce.student_id
     WHERE ce.class_record_id = ? AND ce.status = 'enrolled'
     ORDER BY st.Surname ASC, st.FirstName ASC, st.MiddleName ASC, st.StudentNo ASC"
);
foreach ($classRecords as $sid => $info) {
    $crid = (int) $info['id'];
    $studentsByCr[$crid] = [];
    $en->bind_param('i', $crid);
    $en->execute();
    $res = $en->get_result();
    while ($res && ($r = $res->fetch_assoc())) {
        $studentsByCr[$crid][(int) ($r['student_id'] ?? 0)] = (string) ($r['student_no'] ?? '');
    }
    out('  CR#' . $crid . ' enrolled students: ' . count($studentsByCr[$crid]));
}
$en->close();

// Create missing assessments (so "all components" can be seeded).
if ($createMissingAssessments) {
    out('Ensuring each active component has at least ' . $perComponent . ' assessment(s)...');

    $whereTerm = '';
    if ($termFilter !== 'all') {
        $whereTerm = " AND sgc.term = '" . $conn->real_escape_string($termFilter) . "' ";
    }

    // Load configs for this section/AY/sem/subjects and map to class_record teacher_id (used as created_by).
    $configs = []; // each: config_id, subject_id, term, class_record_id, teacher_id
    $sqlCfg =
        "SELECT sgc.id AS config_id, sgc.subject_id, sgc.term,
                cr.id AS class_record_id, cr.teacher_id
         FROM section_grading_configs sgc
         JOIN class_records cr
            ON cr.subject_id = sgc.subject_id
           AND cr.section = sgc.section
           AND cr.academic_year = sgc.academic_year
           AND cr.semester = sgc.semester
           AND cr.status = 'active'
         WHERE sgc.section = '" . $conn->real_escape_string($section) . "'
           AND sgc.academic_year = '" . $conn->real_escape_string($academicYear) . "'
           AND sgc.semester = '" . $conn->real_escape_string($semester) . "'
           AND sgc.subject_id IN ({$subIdList})
           {$whereTerm}
         ORDER BY sgc.subject_id, sgc.term, sgc.id";
    $rCfg = $conn->query($sqlCfg);
    while ($rCfg && ($row = $rCfg->fetch_assoc())) $configs[] = $row;

    // Helper: pick a reasonable default max_score for a component type.
    $defaultMaxByType = [
        'exam' => 100.0,
        'project' => 100.0,
        'quiz' => 30.0,
        'assignment' => 20.0,
        'participation' => 10.0,
        'other' => 50.0,
    ];

    $qComps = $conn->prepare(
        "SELECT id, component_name, component_type
         FROM grading_components
         WHERE section_config_id = ? AND is_active = 1
         ORDER BY display_order ASC, id ASC"
    );
    $qCount = $conn->prepare("SELECT COUNT(1) AS c FROM grading_assessments WHERE grading_component_id = ? AND is_active = 1");
    $qMaxOrder = $conn->prepare("SELECT COALESCE(MAX(display_order), -1) AS mx FROM grading_assessments WHERE grading_component_id = ?");
    $insA = $conn->prepare(
        "INSERT INTO grading_assessments (grading_component_id, name, max_score, assessment_date, is_active, display_order, created_by)
         VALUES (?, ?, ?, NULL, 1, ?, ?)"
    );
    if (!$qComps || !$qCount || !$qMaxOrder || !$insA) {
        err('Prepare failed for assessment creation.');
        exit(1);
    }

    $created = 0;
    foreach ($configs as $cfgRow) {
        $configId = (int) ($cfgRow['config_id'] ?? 0);
        $teacherForCreatedBy = (int) ($cfgRow['teacher_id'] ?? 1);
        $term = (string) ($cfgRow['term'] ?? '');
        $subCode = $subjects[(int) ($cfgRow['subject_id'] ?? 0)] ?? 'unknown';

        $qComps->bind_param('i', $configId);
        $qComps->execute();
        $rComps = $qComps->get_result();
        while ($rComps && ($gc = $rComps->fetch_assoc())) {
            $componentId = (int) ($gc['id'] ?? 0);
            $componentName = trim((string) ($gc['component_name'] ?? 'Component'));
            $componentType = strtolower(trim((string) ($gc['component_type'] ?? 'other')));
            if (!isset($defaultMaxByType[$componentType])) $componentType = 'other';

            $qCount->bind_param('i', $componentId);
            $qCount->execute();
            $rc = $qCount->get_result();
            $count = ($rc && $rc->num_rows === 1) ? (int) ($rc->fetch_assoc()['c'] ?? 0) : 0;

            if ($count >= $perComponent) continue;

            $qMaxOrder->bind_param('i', $componentId);
            $qMaxOrder->execute();
            $ro = $qMaxOrder->get_result();
            $mxOrder = ($ro && $ro->num_rows === 1) ? (int) ($ro->fetch_assoc()['mx'] ?? -1) : -1;

            for ($i = $count + 1; $i <= $perComponent; $i++) {
                $name = $componentName . ' ' . $i . ': Dummy';
                $max = (float) ($defaultMaxByType[$componentType] ?? 50.0);
                $order = $mxOrder + $i;
                $insA->bind_param('isdii', $componentId, $name, $max, $order, $teacherForCreatedBy);
                $insA->execute();
                $created++;
                out('  created: ' . $subCode . ' | ' . $term . ' | ' . $componentName . ' -> ' . $name . ' (max ' . $max . ')');
            }
        }
    }

    $qComps->close();
    $qCount->close();
    $qMaxOrder->close();
    $insA->close();

    out('Assessments created: ' . $created);
}

// Collect all assessments for this section/AY/sem and those subjects (by joining through grading_components/section_grading_configs).
$whereTerm = '';
if ($termFilter !== 'all') {
    $whereTerm = " AND sgc.term = '" . $conn->real_escape_string($termFilter) . "' ";
}

$assessments = []; // each row contains: assessment_id, max_score, term, subject_id, subject_code, class_record_id
$sql =
    "SELECT ga.id AS assessment_id,
            ga.max_score,
            sgc.term,
            sgc.subject_id,
            s.subject_code,
            cr.id AS class_record_id,
            cr.teacher_id
     FROM grading_assessments ga
     JOIN grading_components gc ON gc.id = ga.grading_component_id AND gc.is_active = 1
     JOIN section_grading_configs sgc ON sgc.id = gc.section_config_id
     JOIN subjects s ON s.id = sgc.subject_id
     JOIN class_records cr
        ON cr.subject_id = sgc.subject_id
       AND cr.section = sgc.section
       AND cr.academic_year = sgc.academic_year
       AND cr.semester = sgc.semester
       AND cr.status = 'active'
     WHERE sgc.section = '" . $conn->real_escape_string($section) . "'
       AND sgc.academic_year = '" . $conn->real_escape_string($academicYear) . "'
       AND sgc.semester = '" . $conn->real_escape_string($semester) . "'
       AND sgc.subject_id IN ({$subIdList})
       {$whereTerm}
       AND ga.is_active = 1
     ORDER BY s.subject_code, sgc.term, ga.id";

$res = $conn->query($sql);
while ($res && ($r = $res->fetch_assoc())) $assessments[] = $r;

if (count($assessments) === 0) {
    out('No assessments found for these classes yet. Create assessments first, then re-run.');
    exit(0);
}

out('Assessments found: ' . count($assessments));

// Prepared statements for reading existing and upserting.
$qExisting = $conn->prepare("SELECT student_id, score FROM grading_assessment_scores WHERE assessment_id = ?");
$up = $conn->prepare(
    "INSERT INTO grading_assessment_scores (assessment_id, student_id, score, recorded_by)
     VALUES (?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE score = VALUES(score), recorded_by = VALUES(recorded_by), updated_at = CURRENT_TIMESTAMP"
);
if (!$qExisting || !$up) {
    err('Prepare failed.');
    exit(1);
}

// Deterministic pseudo-random in [0,1).
function prand01($assessmentId, $studentId) {
    $seed = crc32((string) $assessmentId . ':' . (string) $studentId);
    // crc32 can be signed in PHP; normalize.
    if ($seed < 0) $seed = $seed + 4294967296;
    return ($seed % 10000) / 10000.0;
}

$inserted = 0;
$skipped = 0;
$updated = 0;

$conn->begin_transaction();
try {
    foreach ($assessments as $a) {
        $aid = (int) ($a['assessment_id'] ?? 0);
        $max = (float) ($a['max_score'] ?? 0);
        $term = (string) ($a['term'] ?? '');
        $crid = (int) ($a['class_record_id'] ?? 0);
        $teacherId = (int) ($a['teacher_id'] ?? 0);
        if ($teacherId <= 0) $teacherId = 1;

        $roster = $studentsByCr[$crid] ?? [];
        if ($aid <= 0 || count($roster) === 0) continue;

        // Load existing scores for this assessment.
        $existing = []; // sid => score|null
        $qExisting->bind_param('i', $aid);
        $qExisting->execute();
        $r = $qExisting->get_result();
        while ($r && ($row = $r->fetch_assoc())) {
            $existing[(int) ($row['student_id'] ?? 0)] = $row['score']; // may be null
        }

        foreach ($roster as $sid => $_no) {
            $sid = (int) $sid;
            $had = array_key_exists($sid, $existing);
            $prev = $had ? $existing[$sid] : null;

            if (!$overwrite) {
                // Fill missing only: if there is a non-null numeric score already, skip.
                if ($had && $prev !== null && is_numeric($prev)) {
                    $skipped++;
                    continue;
                }
            }

            if ($max <= 0) {
                $skipped++;
                continue;
            }

            // 60% .. 95% of max, deterministic per (assessment, student).
            $r01 = prand01($aid, $sid);
            $pct = 0.60 + (0.35 * $r01);
            $score = round($max * $pct, 2);
            if ($score < 0) $score = 0;
            if ($score > $max) $score = $max;

            $up->bind_param('iidi', $aid, $sid, $score, $teacherId);
            $up->execute();

            if ($had) $updated++;
            else $inserted++;
        }
    }

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    throw $e;
} finally {
    $qExisting->close();
    $up->close();
}

out('Done.');
out('Inserted: ' . $inserted);
out('Updated:  ' . $updated);
out('Skipped:  ' . $skipped);
