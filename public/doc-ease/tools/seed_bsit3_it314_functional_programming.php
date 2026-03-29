<?php
/**
 * Seed/maintenance script: create subjects IT 314 + IT 314L then enroll BSIT 3A/3B students.
 *
 * What it does:
 * - Upserts subjects by subject_code:
 *   - IT 314  (Lecture)      "Functional Programming"
 *   - IT 314L (Laboratory)   "Functional Programming [Laboratory]"
 * - Ensures sections exist: BSIT - 3A, BSIT - 3B (active)
 * - Ensures section_subjects mappings exist for both sections + both subjects
 * - Upserts enrollments for all students where:
 *   - students.Course = BSInfoTech
 *   - students.Year = 3rd Year
 *   - students.Section IN (A, B)
 *
 * Notes:
 * - This script writes to the *queue* table `enrollments` with status='Active' so a teacher can later
 *   use "Claim Enrollments" to import them into a class record roster.
 *
 * Run:
 *   php tools/seed_bsit3_it314_functional_programming.php
 *
 * Optional:
 *   php tools/seed_bsit3_it314_functional_programming.php --dry-run
 */

require __DIR__ . '/../config/db.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

$dryRun = in_array('--dry-run', $argv ?? [], true);

function out($s) { fwrite(STDOUT, $s . PHP_EOL); }
function err($s) { fwrite(STDERR, $s . PHP_EOL); }

$targetCourse = 'BSInfoTech';
$targetYear = '3rd Year';
$sourceStudentSections = ['A', 'B'];      // values stored in students.Section for BSIT 3
$targetSections = ['BSIT - 3A', 'BSIT - 3B']; // canonical section names used by enrollments.section
$createdByLabel = 'system-seed';

$subjectsWanted = [
    [
        'code' => 'IT 314',
        'name' => 'Functional Programming',
        'type' => 'Lecture',
        'units' => 3.0,
    ],
    [
        'code' => 'IT 314L',
        'name' => 'Functional Programming [Laboratory]',
        'type' => 'Laboratory',
        'units' => 1.0,
    ],
];

out('Seed BSIT 3 -> IT 314 Functional Programming (Lecture + Lab)');
out('Mode: ' . ($dryRun ? 'DRY RUN' : 'APPLY'));

// Resolve an admin user to satisfy subjects.created_by FK.
$adminId = 1;
$adm = $conn->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
if ($adm && $adm->num_rows === 1) $adminId = (int) ($adm->fetch_assoc()['id'] ?? 1);
out("Admin user id: {$adminId}");

// Detect Academic Year / Semester:
// Prefer existing class_records term for BSIT - 3A/3B (any active record). If missing, fall back to reference tables,
// then hard-coded defaults to keep script usable on fresh DBs.
$academicYear = '';
$semester = '';

$stmtTerm = $conn->prepare(
    "SELECT academic_year, semester, COUNT(1) AS c
     FROM class_records
     WHERE status = 'active' AND section IN (?, ?)
       AND academic_year IS NOT NULL AND academic_year <> ''
       AND semester IS NOT NULL AND semester <> ''
     GROUP BY academic_year, semester
     ORDER BY c DESC, academic_year DESC, semester DESC
     LIMIT 1"
);
if ($stmtTerm) {
    $stmtTerm->bind_param('ss', $targetSections[0], $targetSections[1]);
    $stmtTerm->execute();
    $res = $stmtTerm->get_result();
    if ($res && $res->num_rows === 1) {
        $row = $res->fetch_assoc();
        $academicYear = trim((string) ($row['academic_year'] ?? ''));
        $semester = trim((string) ($row['semester'] ?? ''));
    }
    $stmtTerm->close();
}

if ($academicYear === '' || $semester === '') {
    // reference tables created/managed by admin-references.php
    $ay = $conn->query("SELECT name FROM academic_years WHERE status = 'active' ORDER BY sort_order ASC, name ASC LIMIT 1");
    if ($ay && $ay->num_rows === 1) $academicYear = trim((string) ($ay->fetch_assoc()['name'] ?? ''));
    $sem = $conn->query("SELECT name FROM semesters WHERE status = 'active' ORDER BY sort_order ASC, name ASC LIMIT 1");
    if ($sem && $sem->num_rows === 1) $semester = trim((string) ($sem->fetch_assoc()['name'] ?? ''));
}

if ($academicYear === '' || $semester === '') {
    $academicYear = '2025 - 2026';
    $semester = '2nd Semester';
}

out("Target term: AY {$academicYear} | {$semester}");

// Ensure sections exist.
$secSel = $conn->prepare("SELECT id FROM sections WHERE name = ? LIMIT 1");
$secIns = $conn->prepare("INSERT INTO sections (name, description, status) VALUES (?, ?, 'active')");
if (!$secSel || !$secIns) {
    err('Failed to prepare section statements.');
    exit(1);
}

$sectionIds = []; // name => id
foreach ($targetSections as $secName) {
    $secSel->bind_param('s', $secName);
    $secSel->execute();
    $res = $secSel->get_result();
    if ($res && $res->num_rows === 1) {
        $sectionIds[$secName] = (int) ($res->fetch_assoc()['id'] ?? 0);
        continue;
    }

    if ($dryRun) {
        out("Would create section: {$secName}");
        $sectionIds[$secName] = -1;
        continue;
    }

    $desc = 'Auto-created by seed script for BSIT 3 enrollment.';
    $secIns->bind_param('ss', $secName, $desc);
    $secIns->execute();
    $sectionIds[$secName] = (int) $conn->insert_id;
    out("Created section: {$secName} (id={$sectionIds[$secName]})");
}
$secSel->close();
$secIns->close();

// Upsert subjects and collect ids.
$subSel = $conn->prepare("SELECT id FROM subjects WHERE subject_code = ? LIMIT 1");
$subIns = $conn->prepare(
    "INSERT INTO subjects (subject_code, subject_name, description, course, major, academic_year, semester, units, type, status, created_by)
     VALUES (?, ?, '', ?, '', ?, ?, ?, ?, 'active', ?)"
);
$subUpd = $conn->prepare(
    "UPDATE subjects
     SET subject_name = ?, course = ?, academic_year = ?, semester = ?, units = ?, type = ?, status = 'active'
     WHERE subject_code = ?"
);
if (!$subSel || !$subIns || !$subUpd) {
    err('Failed to prepare subject statements.');
    exit(1);
}

$subjectIds = []; // code => id
foreach ($subjectsWanted as $s) {
    $code = (string) $s['code'];
    $name = (string) $s['name'];
    $type = (string) $s['type'];
    $units = (float) $s['units'];

    $subSel->bind_param('s', $code);
    $subSel->execute();
    $res = $subSel->get_result();

    if ($res && $res->num_rows === 1) {
        $id = (int) ($res->fetch_assoc()['id'] ?? 0);
        $subjectIds[$code] = $id;
        out("Subject exists: {$code} (id={$id})");

        if (!$dryRun) {
            $subUpd->bind_param('ssssdss', $name, $targetCourse, $academicYear, $semester, $units, $type, $code);
            $subUpd->execute();
        } else {
            out("Would update subject metadata: {$code}");
        }

        continue;
    }

    if ($dryRun) {
        out("Would create subject: {$code} ({$name})");
        $subjectIds[$code] = -1;
        continue;
    }

    $subIns->bind_param('sssssdsi', $code, $name, $targetCourse, $academicYear, $semester, $units, $type, $adminId);
    $subIns->execute();
    $id = (int) $conn->insert_id;
    $subjectIds[$code] = $id;
    out("Created subject: {$code} (id={$id})");
}

$subSel->close();
$subIns->close();
$subUpd->close();

// Ensure section_subjects mapping (best-effort if sections were dry-run placeholders).
$mapIns = $conn->prepare("INSERT INTO section_subjects (section_id, subject_id) VALUES (?, ?)");
if (!$mapIns) {
    err('Failed to prepare section_subjects insert.');
    exit(1);
}

foreach ($targetSections as $secName) {
    $secId = (int) ($sectionIds[$secName] ?? 0);
    foreach ($subjectsWanted as $s) {
        $code = (string) $s['code'];
        $subId = (int) ($subjectIds[$code] ?? 0);
        if ($secId <= 0 || $subId <= 0) {
            if ($dryRun) out("Would map section_subjects: {$secName} <-> {$code}");
            continue;
        }
        if ($dryRun) continue;
        try {
            $mapIns->bind_param('ii', $secId, $subId);
            $mapIns->execute();
        } catch (mysqli_sql_exception $e) {
            if ((int) $e->getCode() !== 1062) throw $e; // ignore duplicates
        }
    }
}
$mapIns->close();

// Fetch target students.
$srcSecList = implode(',', array_fill(0, count($sourceStudentSections), '?'));
$srcTypes = str_repeat('s', count($sourceStudentSections));

$qStudents = $conn->prepare(
    "SELECT id, StudentNo, Surname, FirstName, MiddleName, Course, Year, Section
     FROM students
     WHERE Course = ? AND Year = ? AND Section IN ({$srcSecList})
     ORDER BY Section ASC, Surname ASC, FirstName ASC, StudentNo ASC"
);
if (!$qStudents) {
    err('Failed to prepare students query.');
    exit(1);
}
$qStudents->bind_param('ss' . $srcTypes, $targetCourse, $targetYear, ...$sourceStudentSections);
$qStudents->execute();
$res = $qStudents->get_result();
$students = [];
while ($res && ($row = $res->fetch_assoc())) $students[] = $row;
$qStudents->close();

out('Students matched: ' . count($students));
if (count($students) === 0) exit(0);

// Prepare enrollment upsert.
$upsert = $conn->prepare(
    "INSERT INTO enrollments (student_no, subject_id, academic_year, semester, section, status, created_by)
     VALUES (?, ?, ?, ?, ?, 'Active', ?)
     ON DUPLICATE KEY UPDATE section = VALUES(section), status = 'Active', created_by = VALUES(created_by)"
);
if (!$upsert) {
    err('Failed to prepare enrollments upsert.');
    exit(1);
}

$counts = ['upserted' => 0, 'skipped' => 0];

if (!$dryRun) $conn->begin_transaction();
try {
    foreach ($students as $st) {
        $studentNo = trim((string) ($st['StudentNo'] ?? ''));
        $secLetter = strtoupper(trim((string) ($st['Section'] ?? '')));
        $canonSection = 'BSIT - 3' . $secLetter;

        if ($studentNo === '' || !in_array($secLetter, ['A', 'B'], true)) {
            $counts['skipped']++;
            continue;
        }
        if (strlen($canonSection) > 10) {
            err("Skip StudentNo {$studentNo}: section '{$canonSection}' too long for enrollments.section (max 10).");
            $counts['skipped']++;
            continue;
        }

        foreach ($subjectsWanted as $s) {
            $code = (string) $s['code'];
            $subId = (int) ($subjectIds[$code] ?? 0);
            if ($subId <= 0) {
                $counts['skipped']++;
                continue;
            }

            if ($dryRun) {
                $counts['upserted']++;
                continue;
            }

            $upsert->bind_param('sissss', $studentNo, $subId, $academicYear, $semester, $canonSection, $createdByLabel);
            $upsert->execute();
            $counts['upserted']++;
        }
    }

    if (!$dryRun) $conn->commit();
} catch (Throwable $e) {
    if (!$dryRun) $conn->rollback();
    throw $e;
}

$upsert->close();

out('Enrollments upserted: ' . (int) $counts['upserted']);
out('Skipped rows: ' . (int) $counts['skipped']);

out('Done');
