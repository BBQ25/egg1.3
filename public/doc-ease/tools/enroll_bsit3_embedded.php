<?php
/**
 * Seed/maintenance script: enroll BSIT 3A/3B students into Embedded Systems (Lecture + Lab).
 *
 * What it does:
 * - Finds subjects by subject_code: IT 316 (Lecture) and IT 316L (Laboratory)
 * - Detects the target AY/Semester from active class_records for those subjects/sections
 * - Enrolls all students whose `students.Section` is BSIT - 3A or BSIT - 3B
 *   - Inserts into `class_enrollments` (actual class roster)
 *   - Upserts into `enrollments` as status='Claimed' (so teacher-claim won't re-import)
 *
 * Run:
 *   php tools/enroll_bsit3_embedded.php
 *
 * Optional:
 *   php tools/enroll_bsit3_embedded.php --dry-run
 */

require __DIR__ . '/../config/db.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

$dryRun = in_array('--dry-run', $argv ?? [], true);

function out($s) { fwrite(STDOUT, $s . PHP_EOL); }
function err($s) { fwrite(STDERR, $s . PHP_EOL); }

$targetSections = ['BSIT - 3A', 'BSIT - 3B']; // canonical section names used by class_records/enrollments
$sourceStudentSections = ['A', 'B']; // values currently stored in students.Section for BSIT 3
$targetCourse = 'BSInfoTech';
$targetYear = '3rd Year';
$subjectCodes = ['IT 316', 'IT 316L'];
$today = date('Y-m-d');
$createdByLabel = 'system-seed';

out('Enroll BSIT 3 -> Embedded Systems (IT 316 + IT 316L)');
out('Target class sections: ' . implode(', ', $targetSections));
out("Student filter: Course='{$targetCourse}', Year='{$targetYear}', Section IN (" . implode(', ', $sourceStudentSections) . ')');
out('Mode: ' . ($dryRun ? 'DRY RUN' : 'APPLY'));

// Admin user id for class_enrollments.created_by
$adminId = 1;
$adm = $conn->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
if ($adm && $adm->num_rows === 1) {
    $adminId = (int) ($adm->fetch_assoc()['id'] ?? 1);
}

// Resolve subject ids.
$subj = $conn->prepare("SELECT id, subject_code, subject_name FROM subjects WHERE status = 'active' AND subject_code = ? LIMIT 1");
if (!$subj) {
    err('Failed to prepare subject lookup.');
    exit(1);
}

$subjects = []; // code => ['id'=>int,'name'=>string]
foreach ($subjectCodes as $code) {
    $subj->bind_param('s', $code);
    $subj->execute();
    $res = $subj->get_result();
    if (!$res || $res->num_rows !== 1) {
        err("Subject not found (active): {$code}");
        exit(1);
    }
    $row = $res->fetch_assoc();
    $subjects[$code] = ['id' => (int) $row['id'], 'name' => (string) $row['subject_name']];
}
$subj->close();

out('Subjects:');
foreach ($subjects as $code => $s) {
    out("  {$code}: subj#{$s['id']} ({$s['name']})");
}

// Detect AY/Semester from active class_records for these subjects/sections.
$ids = array_map(fn($s) => (int) $s['id'], array_values($subjects));
$idList = implode(',', array_map('intval', $ids));
$secList = implode(',', array_fill(0, count($targetSections), '?'));

$sqlAySem =
    "SELECT academic_year, semester, COUNT(1) AS c
     FROM class_records
     WHERE status = 'active'
       AND subject_id IN ({$idList})
       AND section IN ({$secList})
     GROUP BY academic_year, semester
     ORDER BY c DESC, academic_year DESC, semester DESC
     LIMIT 1";

$stmtAySem = $conn->prepare($sqlAySem);
if (!$stmtAySem) {
    err('Failed to prepare AY/Sem detection.');
    exit(1);
}

// Bind dynamic section params
$types = str_repeat('s', count($targetSections));
$stmtAySem->bind_param($types, ...$targetSections);
$stmtAySem->execute();
$resAySem = $stmtAySem->get_result();
$stmtAySem->close();

if (!$resAySem || $resAySem->num_rows !== 1) {
    err('Could not detect target Academic Year/Semester from active class_records. Ensure IT 316/IT 316L are assigned to BSIT - 3A/3B.');
    exit(1);
}

$aySem = $resAySem->fetch_assoc();
$academicYear = (string) ($aySem['academic_year'] ?? '');
$semester = (string) ($aySem['semester'] ?? '');

if ($academicYear === '' || $semester === '') {
    err('Detected empty Academic Year or Semester.');
    exit(1);
}

out("Target term: AY {$academicYear} | {$semester}");

// Map: (subject_id, section) => class_record_id
$classRecordMap = [];
$cr = $conn->prepare(
    "SELECT id, subject_id, section
     FROM class_records
     WHERE status = 'active'
       AND academic_year = ?
       AND semester = ?
       AND subject_id IN ({$idList})
       AND section IN ({$secList})"
);
if (!$cr) {
    err('Failed to prepare class_records lookup.');
    exit(1);
}
$bindArgs = [$academicYear, $semester, ...$targetSections];
$cr->bind_param('ss' . $types, ...$bindArgs);
$cr->execute();
$crRes = $cr->get_result();
while ($crRes && ($row = $crRes->fetch_assoc())) {
    $sid = (int) ($row['subject_id'] ?? 0);
    $sec = (string) ($row['section'] ?? '');
    $classRecordMap[$sid . '|' . $sec] = (int) ($row['id'] ?? 0);
}
$cr->close();

// Verify all combos exist.
$missing = [];
foreach ($subjects as $s) {
    foreach ($targetSections as $sec) {
        $k = ((int) $s['id']) . '|' . $sec;
        if (empty($classRecordMap[$k])) $missing[] = 'subj#' . (int) $s['id'] . ' sec=' . $sec;
    }
}
if (count($missing) > 0) {
    err('Missing active class_records for:');
    foreach ($missing as $m) err('  ' . $m);
    err('Fix assignments first, then re-run.');
    exit(1);
}

// Browse: show counts of students by A/B section letters for BSIT 3.
$secCounts = [];
$srcSecList = implode(',', array_fill(0, count($sourceStudentSections), '?'));
$srcTypes = str_repeat('s', count($sourceStudentSections));
$qCounts = $conn->prepare(
    "SELECT Section, COUNT(1) AS c
     FROM students
     WHERE Course = ? AND Year = ? AND Section IN ({$srcSecList})
     GROUP BY Section
     ORDER BY Section"
);
$qCounts->bind_param('ss' . $srcTypes, $targetCourse, $targetYear, ...$sourceStudentSections);
$qCounts->execute();
$rCounts = $qCounts->get_result();
while ($rCounts && ($row = $rCounts->fetch_assoc())) {
    $secCounts[(string) $row['Section']] = (int) ($row['c'] ?? 0);
}
$qCounts->close();

out('Students found:');
foreach ($sourceStudentSections as $secLetter) {
    $canon = 'BSIT - 3' . strtoupper(trim($secLetter));
    out('  ' . $canon . ' (students.Section=' . $secLetter . '): ' . (int) ($secCounts[$secLetter] ?? 0));
}

// Fetch students.
$qStudents = $conn->prepare(
    "SELECT id, StudentNo, Surname, FirstName, MiddleName, Course, Year, Section
     FROM students
     WHERE Course = ? AND Year = ? AND Section IN ({$srcSecList})
     ORDER BY Section ASC, Surname ASC, FirstName ASC, StudentNo ASC"
);
$qStudents->bind_param('ss' . $srcTypes, $targetCourse, $targetYear, ...$sourceStudentSections);
$qStudents->execute();
$sRes = $qStudents->get_result();
$students = [];
while ($sRes && ($row = $sRes->fetch_assoc())) $students[] = $row;
$qStudents->close();

if (count($students) === 0) {
    out('No students to enroll.');
    exit(0);
}

// Prepared statements for enrollment.
$insClassEnroll = $conn->prepare(
    "INSERT INTO class_enrollments (class_record_id, student_id, enrollment_date, status, created_by, class_id)
     VALUES (?, ?, ?, 'enrolled', ?, ?)"
);
$upsertEnroll = $conn->prepare(
    "INSERT INTO enrollments (student_no, subject_id, academic_year, semester, section, status, created_by)
     VALUES (?, ?, ?, ?, ?, 'Claimed', ?)
     ON DUPLICATE KEY UPDATE section = VALUES(section), status = 'Claimed'"
);

if (!$insClassEnroll || !$upsertEnroll) {
    err('Failed to prepare enrollment inserts.');
    exit(1);
}

$counts = [
    'class_enroll_inserted' => 0,
    'class_enroll_skipped' => 0,
    'enroll_upserted' => 0,
];

if (!$dryRun) $conn->begin_transaction();
try {
    foreach ($students as $st) {
        $studentId = (int) ($st['id'] ?? 0);
        $studentNo = trim((string) ($st['StudentNo'] ?? ''));
        $secLetter = strtoupper(trim((string) ($st['Section'] ?? '')));
        $sec = 'BSIT - 3' . $secLetter;

        if ($studentId <= 0 || $studentNo === '' || $secLetter === '') continue;
        if (strlen($sec) > 10) {
            err("Skip StudentNo {$studentNo}: section '{$sec}' too long for enrollments.section (max 10).");
            continue;
        }

        foreach ($subjects as $code => $subjInfo) {
            $subjectId = (int) ($subjInfo['id'] ?? 0);
            $crKey = $subjectId . '|' . $sec;
            $classRecordId = (int) ($classRecordMap[$crKey] ?? 0);
            if ($classRecordId <= 0) continue;

            if ($dryRun) {
                $counts['class_enroll_inserted']++;
                $counts['enroll_upserted']++;
                continue;
            }

            // Insert actual roster row.
            try {
                $insClassEnroll->bind_param('iisii', $classRecordId, $studentId, $today, $adminId, $classRecordId);
                $insClassEnroll->execute();
                $counts['class_enroll_inserted']++;
            } catch (mysqli_sql_exception $e) {
                if ((int) $e->getCode() === 1062) {
                    $counts['class_enroll_skipped']++;
                } else {
                    throw $e;
                }
            }

            // Upsert enrollments record as Claimed (queue parity).
            $upsertEnroll->bind_param('sissss', $studentNo, $subjectId, $academicYear, $semester, $sec, $createdByLabel);
            $upsertEnroll->execute();
            $counts['enroll_upserted']++;
        }
    }

    if (!$dryRun) $conn->commit();
} catch (Throwable $e) {
    if (!$dryRun) $conn->rollback();
    err('FAILED: ' . $e->getMessage());
    exit(1);
} finally {
    $insClassEnroll->close();
    $upsertEnroll->close();
}

out('Result:');
out('  class_enrollments inserted: ' . $counts['class_enroll_inserted']);
out('  class_enrollments skipped (dupe): ' . $counts['class_enroll_skipped']);
out('  enrollments upserted: ' . $counts['enroll_upserted']);
