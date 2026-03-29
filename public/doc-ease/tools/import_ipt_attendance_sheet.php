<?php
/**
 * Import attendance sheet scores for IPT (IT 208) using name matching.
 *
 * The script:
 * - Finds active IT 208 lecture class records (IF-2-*-6 sections).
 * - Picks the section whose roster best matches the provided sheet names.
 * - Uses midterm "Attendance" component for that section.
 * - Ensures assessment rows exist for the target dates.
 * - Upserts grading_assessment_scores for matched students.
 *
 * Run:
 *   php tools/import_ipt_attendance_sheet.php
 *
 * Dry-run:
 *   php tools/import_ipt_attendance_sheet.php --dry-run
 */

require __DIR__ . '/../config/db.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

$dryRun = in_array('--dry-run', $argv ?? [], true);

function out($s) { fwrite(STDOUT, $s . PHP_EOL); }
function err($s) { fwrite(STDERR, $s . PHP_EOL); }

function norm($s) {
    $s = strtolower(trim((string) $s));
    $s = str_replace(['ñ'], ['n'], $s);
    return preg_replace('/[^a-z0-9]/', '', $s);
}

function split_name($full) {
    $parts = explode(',', (string) $full, 2);
    $surname = trim((string) ($parts[0] ?? ''));
    $first = trim((string) ($parts[1] ?? ''));
    return [$surname, $first];
}

function names_match(array $rosterRow, $sheetSurname, $sheetFirst) {
    $rosterSurname = norm((string) ($rosterRow['surname'] ?? ''));
    $rosterFirst = norm((string) ($rosterRow['first_name'] ?? ''));
    $sheetSurnameN = norm($sheetSurname);
    $sheetFirstN = norm($sheetFirst);

    if ($rosterSurname !== $sheetSurnameN) return false;
    if ($sheetFirstN === '' || $rosterFirst === '') return true;
    if ($rosterFirst === $sheetFirstN) return true;
    if (strpos($rosterFirst, $sheetFirstN) === 0) return true;
    if (strpos($sheetFirstN, $rosterFirst) === 0) return true;
    return levenshtein($rosterFirst, $sheetFirstN) <= 2;
}

// Attendance sheet data from screenshot.
$dates = [
    '2026-01-12',
    '2026-01-19',
    '2026-01-22',
    '2026-01-26',
    '2026-01-29',
    '2026-02-09',
];

$sheetRows = [
    ['name' => 'Albesa, Ma. Patricia',               'scores' => [1, 1, 1, 0.75, 1, 1]],
    ['name' => 'Araniel, Allen Justine',             'scores' => [1, 1, 1, 0.5, 1, 1]],
    ['name' => 'Basas, Niel John',                   'scores' => [1, 1, 1, 1, 1, 1]],
    ['name' => 'Basuga, Gelbert',                    'scores' => [1, 1, 1, 0.5, 1, 1]],
    ['name' => 'Beronilla, Dariel',                  'scores' => [0, 1, 1, 0.5, 1, 1]],
    ['name' => 'Cabodbud, Cristina Jane',            'scores' => [1, 1, 1, 1, 1, 1]],
    ['name' => 'Cagadas, John',                      'scores' => [1, 1, 1, 0.5, 1, 1]],
    ['name' => 'Capilitan, Raniel',                  'scores' => [null, null, null, null, 1, 1]],
    ['name' => 'Carungay, Ramila Jean',              'scores' => [1, 1, 1, 0.5, 1, 1]],
    ['name' => 'Casono, Kemuel',                     'scores' => [1, 1, 1, 1, 1, 1]],
    ['name' => 'De Los Santos, Ashley Bernadette',   'scores' => [0, 1, 1, 0.5, 1, 1]],
    ['name' => 'Dinolan, Lallyn',                    'scores' => [1, 1, 1, 1, 1, 1]],
    ['name' => 'Escorpion, Johnathan',               'scores' => [1, 1, 1, 1, 1, 1]],
    ['name' => 'Galenzoga, Josh Martin',             'scores' => [1, 1, 1, 1, 1, 1]],
    ['name' => 'Garcia, Erich Karyl',                'scores' => [1, 1, 1, 0.5, 1, 1]],
    ['name' => 'Garcia, Glen Mark',                  'scores' => [1, 1, 1, 0.5, 1, 1]],
    ['name' => 'Gaspay, Kimberly Anne',              'scores' => [0, 1, 1, 0.5, 1, 1]],
    ['name' => 'Gerong, Jessie Boy',                 'scores' => [0, 1, 0, 0.5, 1, 1]],
    ['name' => 'Gultiano, Jiros',                    'scores' => [1, 1, 1, 0.5, 1, 1]],
    ['name' => 'Kuizon, Jericho',                    'scores' => [0, 1, 1, 1, 1, 1]],
    ['name' => 'Layo, Jonathan, Jr',                 'scores' => [1, 1, 1, 1, 1, 1]],
    ['name' => 'Magsinolog, Rosevie',                'scores' => [1, 1, 1, 0.5, 1, 1]],
    ['name' => 'Sasing, Kyssiah',                    'scores' => [1, 1, 1, 0.5, 1, 1]],
    ['name' => 'Siega, Alyssa Blessie',              'scores' => [1, 1, 1, 0.5, 1, 1]],
    ['name' => 'Siega, Reymart',                     'scores' => [1, 1, 1, 1, 1, 1]],
    ['name' => 'Tablada, Marianne Rose',             'scores' => [0, 1, 1, 1, 1, 1]],
    ['name' => 'Talamo, Jean Ann',                   'scores' => [1, 1, 1, 0.5, 1, 1]],
    ['name' => 'Vanzuela, Klaire',                   'scores' => [0, 0, 0, 0.5, 1, 1]],
];

out('Import IPT attendance sheet');
out('Mode: ' . ($dryRun ? 'DRY RUN' : 'APPLY'));

// Candidate IT 208 lecture classes.
$classes = [];
$qClass = $conn->query(
    "SELECT cr.id, cr.section, cr.academic_year, cr.semester
     FROM class_records cr
     JOIN subjects s ON s.id = cr.subject_id
     WHERE cr.status = 'active'
       AND s.subject_code = 'IT 208'
       AND cr.section LIKE 'IF-2-%-6'
     ORDER BY cr.id ASC"
);
while ($qClass && ($r = $qClass->fetch_assoc())) {
    $classes[] = $r;
}

if (count($classes) === 0) {
    err('No active IT 208 lecture class records found.');
    exit(1);
}

$best = null;
$bestMatches = -1;
$bestRoster = [];
$bestMapping = [];

foreach ($classes as $class) {
    $classId = (int) ($class['id'] ?? 0);
    $roster = [];
    $st = $conn->prepare(
        "SELECT st.id AS student_id, st.Surname AS surname, st.FirstName AS first_name
         FROM class_enrollments ce
         JOIN students st ON st.id = ce.student_id
         WHERE ce.class_record_id = ?
           AND ce.status = 'enrolled'"
    );
    $st->bind_param('i', $classId);
    $st->execute();
    $res = $st->get_result();
    while ($res && ($r = $res->fetch_assoc())) {
        $roster[] = $r;
    }
    $st->close();

    $matchedCount = 0;
    $mapping = [];
    foreach ($sheetRows as $row) {
        [$ss, $sf] = split_name($row['name']);
        $hit = null;
        foreach ($roster as $rr) {
            if (names_match($rr, $ss, $sf)) {
                $hit = $rr;
                break;
            }
        }
        if ($hit) {
            $matchedCount++;
            $mapping[$row['name']] = (int) ($hit['student_id'] ?? 0);
        }
    }

    out('Candidate CR#' . $classId . ' sec=' . (string) $class['section'] . ' matches=' . $matchedCount . '/' . count($sheetRows));

    if ($matchedCount > $bestMatches) {
        $bestMatches = $matchedCount;
        $best = $class;
        $bestRoster = $roster;
        $bestMapping = $mapping;
    }
}

if (!$best || $bestMatches <= 0) {
    err('Could not map sheet names to any IT 208 class roster.');
    exit(1);
}

$classRecordId = (int) ($best['id'] ?? 0);
$sectionCode = (string) ($best['section'] ?? '');
$academicYear = (string) ($best['academic_year'] ?? '');
$semester = (string) ($best['semester'] ?? '');

out('Selected class: CR#' . $classRecordId . ' sec=' . $sectionCode . ' (' . $bestMatches . ' matches)');

// Resolve midterm attendance component for the selected section.
$componentId = 0;
$qComp = $conn->prepare(
    "SELECT gc.id
     FROM grading_components gc
     JOIN section_grading_configs sgc ON sgc.id = gc.section_config_id
     JOIN subjects s ON s.id = sgc.subject_id
     WHERE sgc.section = ?
       AND sgc.academic_year = ?
       AND sgc.semester = ?
       AND sgc.term = 'midterm'
       AND s.subject_code = 'IT 208'
       AND LOWER(gc.component_name) LIKE '%attendance%'
     ORDER BY gc.id ASC
     LIMIT 1"
);
$qComp->bind_param('sss', $sectionCode, $academicYear, $semester);
$qComp->execute();
$rComp = $qComp->get_result();
if ($rComp && $rComp->num_rows === 1) {
    $componentId = (int) ($rComp->fetch_assoc()['id'] ?? 0);
}
$qComp->close();

if ($componentId <= 0) {
    err('Attendance component not found for selected class.');
    exit(1);
}
out('Attendance component: #' . $componentId . ' (midterm)');

$assessments = []; // date => assessment_id
$nextOrder = 1;
$qAss = $conn->prepare(
    "SELECT id, assessment_date, display_order
     FROM grading_assessments
     WHERE grading_component_id = ?"
);
$qAss->bind_param('i', $componentId);
$qAss->execute();
$rAss = $qAss->get_result();
while ($rAss && ($a = $rAss->fetch_assoc())) {
    $d = (string) ($a['assessment_date'] ?? '');
    if ($d !== '') $assessments[$d] = (int) ($a['id'] ?? 0);
    $ord = (int) ($a['display_order'] ?? 0);
    if ($ord >= $nextOrder) $nextOrder = $ord + 1;
}
$qAss->close();

$createdBy = 2; // teacher JUNNIE (existing context).

if (!$dryRun) $conn->begin_transaction();
try {
    // Ensure assessments exist.
    $insAss = $conn->prepare(
        "INSERT INTO grading_assessments
            (grading_component_id, name, max_score, assessment_date, is_active, display_order, created_by)
         VALUES (?, ?, 1.00, ?, 1, ?, ?)"
    );
    $updAss = $conn->prepare(
        "UPDATE grading_assessments
         SET name = ?, max_score = 1.00, is_active = 1
         WHERE id = ?"
    );

    foreach ($dates as $d) {
        if (isset($assessments[$d])) {
            $aid = (int) $assessments[$d];
            $name = 'Attendance ' . date('d-M-y', strtotime($d));
            if (!$dryRun) {
                $updAss->bind_param('si', $name, $aid);
                $updAss->execute();
            }
            continue;
        }

        $name = 'Attendance ' . date('d-M-y', strtotime($d));
        if ($dryRun) {
            $assessments[$d] = -1;
            $nextOrder++;
            continue;
        }
        $insAss->bind_param('issii', $componentId, $name, $d, $nextOrder, $createdBy);
        $insAss->execute();
        $assessments[$d] = (int) $conn->insert_id;
        $nextOrder++;
    }
    $insAss->close();
    $updAss->close();

    $upScore = $conn->prepare(
        "INSERT INTO grading_assessment_scores (assessment_id, student_id, score, recorded_by)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE score = VALUES(score), recorded_by = VALUES(recorded_by), updated_at = CURRENT_TIMESTAMP"
    );

    $matchedRows = 0;
    $scoreWrites = 0;
    $unmatched = [];

    foreach ($sheetRows as $row) {
        $name = (string) $row['name'];
        if (!isset($bestMapping[$name]) || (int) $bestMapping[$name] <= 0) {
            $unmatched[] = $name;
            continue;
        }

        $studentId = (int) $bestMapping[$name];
        $matchedRows++;
        $scores = (array) ($row['scores'] ?? []);

        foreach ($dates as $idx => $d) {
            $aid = (int) ($assessments[$d] ?? 0);
            if ($aid <= 0) continue;
            $v = $scores[$idx] ?? null;
            if ($v === null || $v === '') continue;
            $score = round((float) $v, 2);
            if ($score < 0) $score = 0.0;
            if ($score > 1) $score = 1.0;

            if (!$dryRun) {
                $upScore->bind_param('iidi', $aid, $studentId, $score, $createdBy);
                $upScore->execute();
            }
            $scoreWrites++;
        }
    }
    $upScore->close();

    if (!$dryRun) $conn->commit();

    out('Rows matched: ' . $matchedRows . '/' . count($sheetRows));
    out('Score upserts: ' . $scoreWrites);
    if (count($unmatched) > 0) {
        out('Unmatched names (' . count($unmatched) . '):');
        foreach ($unmatched as $u) out('  - ' . $u);
    }

    // Verify assessment row counts.
    if (!$dryRun) {
        out('');
        out('Assessment counts:');
        foreach ($dates as $d) {
            $aid = (int) ($assessments[$d] ?? 0);
            if ($aid <= 0) continue;
            $qc = $conn->prepare("SELECT COUNT(*) AS c FROM grading_assessment_scores WHERE assessment_id = ? AND score IS NOT NULL");
            $qc->bind_param('i', $aid);
            $qc->execute();
            $res = $qc->get_result();
            $c = ($res && $res->num_rows === 1) ? (int) ($res->fetch_assoc()['c'] ?? 0) : 0;
            $qc->close();
            out('  ' . $d . ' -> ASM#' . $aid . ' scored=' . $c);
        }
    }
} catch (Throwable $e) {
    if (!$dryRun) $conn->rollback();
    err('FAILED: ' . $e->getMessage());
    exit(1);
}

out('Done.');

