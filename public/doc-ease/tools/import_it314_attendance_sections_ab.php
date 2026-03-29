<?php
/**
 * Import Functional Programming attendance sheet values (Section A and B)
 * into both Lecture (IT 314) and Laboratory (IT 314L) midterm Attendance components.
 *
 * Source dates:
 * - 2026-01-22
 * - 2026-01-26
 * - 2026-01-29
 * - 2026-02-09
 * - 2026-02-12
 *
 * Run:
 *   php tools/import_it314_attendance_sections_ab.php
 *
 * Dry run:
 *   php tools/import_it314_attendance_sections_ab.php --dry-run
 */

require __DIR__ . '/../config/db.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

$dryRun = in_array('--dry-run', $argv ?? [], true);

function out($s) { fwrite(STDOUT, $s . PHP_EOL); }
function err($s) { fwrite(STDERR, $s . PHP_EOL); }

function norm($s) {
    $s = strtolower(trim((string) $s));
    $s = str_replace(['ñ', 'Ã±', '�'], ['n', 'n', 'n'], $s);
    $s = preg_replace('/[^a-z0-9]+/', '', $s);
    return (string) $s;
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

function parse_score($v) {
    if ($v === null || $v === '') return null;
    if (is_int($v) || is_float($v)) {
        $f = (float) $v;
        if ($f < 0) $f = 0.0;
        if ($f > 1) $f = 1.0;
        return $f;
    }

    $s = strtolower(trim((string) $v));
    if ($s === '') return null;
    if (preg_match('/^(e|excused|with excuse|with excuse letter|w\\/ excuse)$/', $s)) return 1.0;
    if (preg_match('/^(a|absent)$/', $s)) return 0.0;
    if (preg_match('/^\\d+(\\.\\d+)?$/', $s)) {
        $f = (float) $s;
        if ($f < 0) $f = 0.0;
        if ($f > 1) $f = 1.0;
        return $f;
    }
    return null;
}

$dates = [
    '2026-01-22',
    '2026-01-26',
    '2026-01-29',
    '2026-02-09',
    '2026-02-12',
];

$sectionRows = [
    'A' => [
        ['name' => 'Abande, Elisha Mae', 'scores' => [1, 1, 1, 1, 1]],
        ['name' => 'Amora, Emil Jon', 'scores' => [1, 1, 1, 1, 1]],
        ['name' => 'Apilan, Akissah Beth', 'scores' => [1, 1, 1, 1, 1]],
        ['name' => 'Aton, April Grace', 'scores' => [1, 1, 1, 1, 1]],
        ['name' => 'Cabahug, Danica Marie', 'scores' => [1, 1, 1, 1, 1]],
        ['name' => 'Cabilic, Eva Mae', 'scores' => [1, 1, 1, 1, 1]],
        ['name' => 'Cabillada, James', 'scores' => [1, 1, 1, 1, 1]],
        ['name' => 'Consuelo, Vhan Mariz', 'scores' => [1, 1, 1, 1, 1]],
        ['name' => 'Dublois, Cristina Marie', 'scores' => [1, 1, 1, 1, 1]],
        ['name' => 'Dumagat, Danilo, Jr.', 'scores' => [1, 1, 0, 1, 1]],
        ['name' => 'Gesto, Karyl', 'scores' => [1, 0, 1, 1, 1]],
        ['name' => 'Gula, Grace', 'scores' => [1, 1, 1, 1, 1]],
        ['name' => 'Gumapi, Clarice', 'scores' => [1, 1, 1, 'with excuse', 1]],
        ['name' => 'Guzon, Anna Mae', 'scores' => ['with excuse', 1, 1, 1, 1]],
        ['name' => 'Hermogino, Dexter', 'scores' => [1, 1, 1, 1, 1]],
        ['name' => 'Hernandez, Carmela', 'scores' => [1, 1, 1, 1, 1]],
        ['name' => 'Himo, Cherry Ann', 'scores' => [1, 1, 1, 1, 1]],
        ['name' => 'Libodlibod, Jaylynne Gayle', 'scores' => [1, 1, 1, 1, 1]],
        ['name' => 'Lopez, Miles Adrian', 'scores' => [1, 1, 0, 1, 1]],
        ['name' => 'Morillo, Ronell', 'scores' => [1, 1, 1, 1, 1]],
        ['name' => 'Ruales, Marrisa', 'scores' => [1, 1, 1, 1, 1]],
        ['name' => 'Saludo, Henre Aidenry', 'scores' => [1, 1, 1, 1, 1]],
        ['name' => 'Tago-on, Cristina', 'scores' => [1, 1, 1, 1, 1]],
        ['name' => 'Tumanda, John Rogiel', 'scores' => [1, 1, 1, 1, 1]],
        ['name' => 'Vilbar, Leanne Krista', 'scores' => [1, 1, 1, 1, 1]],
        ['name' => 'Viure, Karyl', 'scores' => [1, 1, 1, 1, 1]],
    ],
    'B' => [
        ['name' => 'Abrea, Leonel', 'scores' => [1, 1, 1, null, null]],
        ['name' => 'Arañez, Rizaly', 'scores' => [1, 1, 1, 1, null]],
        ['name' => 'Baslot, Ryan', 'scores' => [1, null, 1, null, null]],
        ['name' => 'Batalon, Elyn Joy', 'scores' => [1, 1, 1, 1, null]],
        ['name' => 'Bugnos, Cliff Jhone', 'scores' => [1, 1, 1, null, null]],
        ['name' => 'Calunsod, Gian Del', 'scores' => [1, null, 1, null, null]],
        ['name' => 'Cataylo, Rica', 'scores' => [1, 1, 1, 1, null]],
        ['name' => 'Diabordo, Kierby', 'scores' => [1, 1, 1, 1, null]],
        ['name' => 'Espinosa, Angelyn', 'scores' => [1, 1, 1, 1, null]],
        ['name' => 'Gesulga, Ahnjellou', 'scores' => [1, 1, 1, 1, null]],
        ['name' => 'Gesulga, Jhoarn Mae', 'scores' => [1, null, 1, 1, null]],
        ['name' => 'Lamoste, John Clifford', 'scores' => [1, 1, 1, null, null]],
        ['name' => 'Lamoste, Mary Grace', 'scores' => [1, 1, 'E', 1, null]],
        ['name' => 'Magadan, Sean Carlo', 'scores' => [1, 1, 1, 1, null]],
        ['name' => 'Makilang, Charlene', 'scores' => [1, 1, 1, 1, null]],
        ['name' => 'Oclarit, Annalou', 'scores' => [1, 1, 1, 1, null]],
        ['name' => 'Orit, Mayrich', 'scores' => [1, null, 1, 1, null]],
        ['name' => 'Ruales, Dean Dale', 'scores' => [1, 1, 1, null, null]],
        ['name' => 'Serdan, Angel Mae', 'scores' => [1, 1, null, 1, null]],
        ['name' => 'Sinco, Stella Marie', 'scores' => [null, 1, 1, 'with excuse', null]],
        ['name' => 'Tablada, Wendy Ann', 'scores' => [1, 1, 1, 1, null]],
        ['name' => 'Trimucha, Christian', 'scores' => [1, 1, 1, null, null]],
        ['name' => 'Timkang, Efmarjoy', 'scores' => [1, 1, 1, 1, null]],
        ['name' => 'Yecyec, John Mark', 'scores' => [1, null, 1, null, null]],
    ],
];

$targets = [
    ['label' => 'Section A Lecture', 'subject_code' => 'IT 314', 'section' => 'IF-3-A-7', 'sheet' => 'A'],
    ['label' => 'Section A Laboratory', 'subject_code' => 'IT 314L', 'section' => 'IF-3-A-8', 'sheet' => 'A'],
    ['label' => 'Section B Lecture', 'subject_code' => 'IT 314', 'section' => 'IF-3-B-7', 'sheet' => 'B'],
    ['label' => 'Section B Laboratory', 'subject_code' => 'IT 314L', 'section' => 'IF-3-B-8', 'sheet' => 'B'],
];

out('Import Functional Programming attendance (Sections A/B, Lecture + Lab)');
out('Mode: ' . ($dryRun ? 'DRY RUN' : 'APPLY'));

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
    $upScore = $conn->prepare(
        "INSERT INTO grading_assessment_scores (assessment_id, student_id, score, recorded_by)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE score = VALUES(score), recorded_by = VALUES(recorded_by), updated_at = CURRENT_TIMESTAMP"
    );

    foreach ($targets as $t) {
        $label = (string) $t['label'];
        $subjectCode = (string) $t['subject_code'];
        $section = (string) $t['section'];
        $sheetKey = (string) $t['sheet'];
        $rows = (array) ($sectionRows[$sheetKey] ?? []);

        out('');
        out('--- ' . $label . ' ---');

        $qClass = $conn->prepare(
            "SELECT cr.id AS class_record_id, cr.teacher_id, cr.academic_year, cr.semester
             FROM class_records cr
             JOIN subjects s ON s.id = cr.subject_id
             WHERE cr.status = 'active'
               AND s.subject_code = ?
               AND cr.section = ?
             LIMIT 1"
        );
        $qClass->bind_param('ss', $subjectCode, $section);
        $qClass->execute();
        $rClass = $qClass->get_result();
        $class = ($rClass && $rClass->num_rows === 1) ? $rClass->fetch_assoc() : null;
        $qClass->close();

        if (!$class) {
            err('Class not found: ' . $subjectCode . ' / ' . $section);
            continue;
        }

        $classRecordId = (int) ($class['class_record_id'] ?? 0);
        $teacherId = (int) ($class['teacher_id'] ?? 2);
        if ($teacherId <= 0) $teacherId = 2;
        $academicYear = (string) ($class['academic_year'] ?? '');
        $semester = (string) ($class['semester'] ?? '');
        out('ClassRecord: #' . $classRecordId . ' (' . $subjectCode . ' ' . $section . ')');

        $qComp = $conn->prepare(
            "SELECT gc.id AS component_id
             FROM grading_components gc
             JOIN section_grading_configs sgc ON sgc.id = gc.section_config_id
             JOIN subjects s ON s.id = sgc.subject_id
             WHERE s.subject_code = ?
               AND sgc.section = ?
               AND sgc.academic_year = ?
               AND sgc.semester = ?
               AND sgc.term = 'midterm'
               AND LOWER(TRIM(COALESCE(gc.component_name, ''))) LIKE '%attendance%'
             ORDER BY gc.id ASC
             LIMIT 1"
        );
        $qComp->bind_param('ssss', $subjectCode, $section, $academicYear, $semester);
        $qComp->execute();
        $rComp = $qComp->get_result();
        $componentId = ($rComp && $rComp->num_rows === 1) ? (int) ($rComp->fetch_assoc()['component_id'] ?? 0) : 0;
        $qComp->close();

        if ($componentId <= 0) {
            err('Attendance component not found for class #' . $classRecordId);
            continue;
        }
        out('Attendance component: #' . $componentId);

        $roster = [];
        $qRoster = $conn->prepare(
            "SELECT st.id AS student_id, st.Surname AS surname, st.FirstName AS first_name
             FROM class_enrollments ce
             JOIN students st ON st.id = ce.student_id
             WHERE ce.class_record_id = ?
               AND ce.status = 'enrolled'
             ORDER BY st.Surname ASC, st.FirstName ASC"
        );
        $qRoster->bind_param('i', $classRecordId);
        $qRoster->execute();
        $rRoster = $qRoster->get_result();
        while ($rRoster && ($rr = $rRoster->fetch_assoc())) $roster[] = $rr;
        $qRoster->close();
        out('Roster size: ' . count($roster));

        $mapping = [];
        $unmatched = [];
        foreach ($rows as $row) {
            $sheetName = (string) ($row['name'] ?? '');
            [$ss, $sf] = split_name($sheetName);
            $hit = null;
            foreach ($roster as $rr) {
                if (names_match($rr, $ss, $sf)) {
                    $hit = $rr;
                    break;
                }
            }
            if ($hit) {
                $mapping[$sheetName] = (int) ($hit['student_id'] ?? 0);
            } else {
                $unmatched[] = $sheetName;
            }
        }
        out('Mapped rows: ' . count($mapping) . '/' . count($rows));

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

        foreach ($dates as $d) {
            $name = 'Attendance ' . date('d-M-y', strtotime($d));
            if (isset($assessments[$d])) {
                $aid = (int) $assessments[$d];
                if (!$dryRun) {
                    $updAss->bind_param('si', $name, $aid);
                    $updAss->execute();
                }
                continue;
            }

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

        $writes = 0;
        foreach ($rows as $row) {
            $sheetName = (string) ($row['name'] ?? '');
            $studentId = (int) ($mapping[$sheetName] ?? 0);
            if ($studentId <= 0) continue;
            $scores = (array) ($row['scores'] ?? []);

            foreach ($dates as $idx => $d) {
                $aid = (int) ($assessments[$d] ?? 0);
                if ($aid <= 0) continue;
                $score = parse_score($scores[$idx] ?? null);
                if ($score === null) continue;
                if (!$dryRun) {
                    $upScore->bind_param('iidi', $aid, $studentId, $score, $teacherId);
                    $upScore->execute();
                }
                $writes++;
            }
        }

        out('Score upserts: ' . $writes);
        if (count($unmatched) > 0) {
            out('Unmatched names (' . count($unmatched) . '):');
            foreach ($unmatched as $u) out('  - ' . $u);
        }
    }

    $insAss->close();
    $updAss->close();
    $upScore->close();

    if (!$dryRun) $conn->commit();
    out('');
    out('Done.');
} catch (Throwable $e) {
    if (!$dryRun) $conn->rollback();
    err('FAILED: ' . $e->getMessage());
    exit(1);
}

