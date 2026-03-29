<?php
/**
 * Import IPT (IT 208 lecture) Section A attendance scores from the provided sheet.
 *
 * Target:
 * - class_records.section = IF-2-A-6
 * - term = midterm
 * - component name like "Attendance"
 *
 * Run:
 *   php tools/import_ipt_attendance_section_a.php
 *
 * Dry run:
 *   php tools/import_ipt_attendance_section_a.php --dry-run
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

$dates = [
    '2026-01-12',
    '2026-01-19',
    '2026-01-22',
    '2026-01-26',
    '2026-01-29',
    '2026-02-09',
];

$sheetRows = [
    ['name' => 'Abengoza, Lian Yi Shin',    'scores' => [0,1,1,1,1,1]],
    ['name' => 'Andam, Kristin',            'scores' => [0,1,1,1,1,1]],
    ['name' => 'Bernales, Jomarie',         'scores' => [0,1,1,1,1,1]],
    ['name' => 'Camarista, John Brunz',     'scores' => [1,1,1,1,1,1]],
    ['name' => 'Caon, Mariel',              'scores' => [0,1,1,1,1,1]],
    ['name' => 'Caube, Renz Arthur',        'scores' => [0,1,1,1,1,1]],
    ['name' => 'Cebuala, Emarie',           'scores' => [0,1,1,1,1,1]],
    ['name' => 'Daga, Samantha',            'scores' => [0,1,1,1,1,1]],
    ['name' => 'Davis, Rodel',              'scores' => [0,1,1,1,1,1]],
    ['name' => 'Ello, Jake',                'scores' => [0,1,1,1,1,1]],
    ['name' => 'Epis, Jerome',              'scores' => [0,1,1,1,1,1]],
    ['name' => 'Esclamado, Glydel',         'scores' => [0,1,1,1,1,1]],
    ['name' => 'Garcia, Lenard Kier',       'scores' => [0,1,1,1,1,1]],
    ['name' => 'Gayo, Erica',               'scores' => [0,1,1,1,1,1]],
    ['name' => 'Gula, Diana',               'scores' => [0,1,1,1,1,1]],
    ['name' => 'Hilo, Jhastine',            'scores' => [0,1,1,1,1,1]],
    ['name' => 'Ilogon, Jeriel Kish',       'scores' => [0,1,1,1,1,1]],
    ['name' => 'Inal, Rogelino Mondido Fe', 'scores' => [0,1,1,1,1,1]],
    ['name' => 'Liad, Angel Marie',         'scores' => [0,0,1,1,1,1]],
    ['name' => 'Lisbos, Ronel',             'scores' => [0,1,1,1,1,1]],
    ['name' => 'Lozada, Kevin',             'scores' => [0,0,1,1,1,1]],
    ['name' => 'Marino, Raymond',           'scores' => [0,1,1,0,1,1]],
    ['name' => 'Medina, Wyndel',            'scores' => [0,0,1,1,1,1]],
    ['name' => 'Mori, Bryll Jane',          'scores' => [0,1,1,1,1,1]],
    ['name' => 'Narte, Glenda',             'scores' => [0,1,1,1,1,1]],
    ['name' => 'Robles, Dave',              'scores' => [0,1,1,1,1,1]],
    ['name' => 'Sueno, Chanill Lay',        'scores' => [0,1,1,1,1,1]],
    ['name' => 'Tabada, Lotis',             'scores' => [0,1,1,1,1,1]],
    ['name' => 'Tomon, Danessa',            'scores' => [0,0,1,1,1,1]],
];

out('Import IPT Section A attendance sheet');
out('Mode: ' . ($dryRun ? 'DRY RUN' : 'APPLY'));

$class = null;
$qClass = $conn->prepare(
    "SELECT cr.id, cr.section, cr.academic_year, cr.semester, cr.teacher_id
     FROM class_records cr
     JOIN subjects s ON s.id = cr.subject_id
     WHERE cr.status = 'active'
       AND cr.section = 'IF-2-A-6'
       AND s.subject_code = 'IT 208'
     LIMIT 1"
);
$qClass->execute();
$rClass = $qClass->get_result();
if ($rClass && $rClass->num_rows === 1) {
    $class = $rClass->fetch_assoc();
}
$qClass->close();

if (!$class) {
    err('Target class IF-2-A-6 / IT 208 not found.');
    exit(1);
}

$classRecordId = (int) ($class['id'] ?? 0);
$academicYear = (string) ($class['academic_year'] ?? '');
$semester = (string) ($class['semester'] ?? '');
$teacherId = (int) ($class['teacher_id'] ?? 2);
if ($teacherId <= 0) $teacherId = 2;

out('Target class: CR#' . $classRecordId . ' sec=IF-2-A-6');

$componentId = 0;
$qComp = $conn->prepare(
    "SELECT gc.id
     FROM grading_components gc
     JOIN section_grading_configs sgc ON sgc.id = gc.section_config_id
     JOIN subjects s ON s.id = sgc.subject_id
     WHERE sgc.section = 'IF-2-A-6'
       AND sgc.academic_year = ?
       AND sgc.semester = ?
       AND sgc.term = 'midterm'
       AND s.subject_code = 'IT 208'
       AND LOWER(gc.component_name) LIKE '%attendance%'
     ORDER BY gc.id ASC
     LIMIT 1"
);
$qComp->bind_param('ss', $academicYear, $semester);
$qComp->execute();
$rComp = $qComp->get_result();
if ($rComp && $rComp->num_rows === 1) {
    $componentId = (int) ($rComp->fetch_assoc()['id'] ?? 0);
}
$qComp->close();

if ($componentId <= 0) {
    err('Attendance component not found for IF-2-A-6 midterm.');
    exit(1);
}
out('Attendance component: #' . $componentId);

// Load roster for mapping.
$roster = [];
$qRoster = $conn->prepare(
    "SELECT st.id AS student_id, st.Surname AS surname, st.FirstName AS first_name
     FROM class_enrollments ce
     JOIN students st ON st.id = ce.student_id
     WHERE ce.class_record_id = ?
       AND ce.status = 'enrolled'"
);
$qRoster->bind_param('i', $classRecordId);
$qRoster->execute();
$rRoster = $qRoster->get_result();
while ($rRoster && ($rr = $rRoster->fetch_assoc())) {
    $roster[] = $rr;
}
$qRoster->close();

// Map sheet names -> student_id.
$mapping = [];
$unmatched = [];
foreach ($sheetRows as $row) {
    [$ss, $sf] = split_name((string) $row['name']);
    $hit = null;
    foreach ($roster as $rr) {
        if (names_match($rr, $ss, $sf)) {
            $hit = $rr;
            break;
        }
    }
    if ($hit) {
        $mapping[(string) $row['name']] = (int) ($hit['student_id'] ?? 0);
    } else {
        $unmatched[] = (string) $row['name'];
    }
}

out('Mapped names: ' . count($mapping) . '/' . count($sheetRows));

// Existing assessments for component.
$assessments = []; // date => id
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

if (!$dryRun) $conn->begin_transaction();
try {
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
        $insAss->bind_param('issii', $componentId, $name, $d, $nextOrder, $teacherId);
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

    $writes = 0;
    foreach ($sheetRows as $row) {
        $name = (string) $row['name'];
        $studentId = (int) ($mapping[$name] ?? 0);
        if ($studentId <= 0) continue;
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
                $upScore->bind_param('iidi', $aid, $studentId, $score, $teacherId);
                $upScore->execute();
            }
            $writes++;
        }
    }
    $upScore->close();

    if (!$dryRun) $conn->commit();

    out('Score upserts: ' . $writes);
    if (count($unmatched) > 0) {
        out('Unmatched names (' . count($unmatched) . '):');
        foreach ($unmatched as $u) out('  - ' . $u);
    }

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

