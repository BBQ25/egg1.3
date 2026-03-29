<?php
/**
 * Enroll BSInfoTech students into IF section class records assigned to Teacher JUNNIE RYH M. SUMACOT.
 *
 * Scope:
 * - IF-2-A-* classes -> BSInfoTech / 2nd Year / Section A
 * - IF-2-B-* classes -> BSInfoTech / 2nd Year / Section B
 * - IF-3-A-* classes -> BSInfoTech / 3rd Year / Section A
 * - IF-3-B-* classes -> BSInfoTech / 3rd Year / Section B
 *
 * Writes:
 * - class_enrollments (actual class roster)
 * - enrollments (queue parity; status set to Claimed)
 *
 * Run:
 *   php tools/enroll_if_sections_for_sumacot.php
 *
 * Dry run:
 *   php tools/enroll_if_sections_for_sumacot.php --dry-run
 */

require __DIR__ . '/../config/db.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

$dryRun = in_array('--dry-run', $argv ?? [], true);

function out($s) { fwrite(STDOUT, $s . PHP_EOL); }
function err($s) { fwrite(STDERR, $s . PHP_EOL); }

$targetSectionMap = [
    'IF-2-A-6'  => ['course' => 'BSInfoTech', 'year' => '2nd Year', 'student_section' => 'A'],
    'IF-2-A-7'  => ['course' => 'BSInfoTech', 'year' => '2nd Year', 'student_section' => 'A'],
    'IF-2-B-6'  => ['course' => 'BSInfoTech', 'year' => '2nd Year', 'student_section' => 'B'],
    'IF-2-B-7'  => ['course' => 'BSInfoTech', 'year' => '2nd Year', 'student_section' => 'B'],
    'IF-3-A-7'  => ['course' => 'BSInfoTech', 'year' => '3rd Year', 'student_section' => 'A'],
    'IF-3-A-8'  => ['course' => 'BSInfoTech', 'year' => '3rd Year', 'student_section' => 'A'],
    'IF-3-A-9'  => ['course' => 'BSInfoTech', 'year' => '3rd Year', 'student_section' => 'A'],
    'IF-3-A-10' => ['course' => 'BSInfoTech', 'year' => '3rd Year', 'student_section' => 'A'],
    'IF-3-B-7'  => ['course' => 'BSInfoTech', 'year' => '3rd Year', 'student_section' => 'B'],
    'IF-3-B-8'  => ['course' => 'BSInfoTech', 'year' => '3rd Year', 'student_section' => 'B'],
    'IF-3-B-9'  => ['course' => 'BSInfoTech', 'year' => '3rd Year', 'student_section' => 'B'],
    'IF-3-B-10' => ['course' => 'BSInfoTech', 'year' => '3rd Year', 'student_section' => 'B'],
];

$targetSections = array_keys($targetSectionMap);

out('Enroll IF sections for Teacher JUNNIE RYH M. SUMACOT');
out('Mode: ' . ($dryRun ? 'DRY RUN' : 'APPLY'));
out('Target sections: ' . implode(', ', $targetSections));

$adminId = 1;
$adm = $conn->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
if ($adm && $adm->num_rows === 1) {
    $adminId = (int) ($adm->fetch_assoc()['id'] ?? 1);
}
out("Admin user id: {$adminId}");

$teacherId = 0;
$teacherName = '';
$teacherLookup = $conn->prepare(
    "SELECT id, CONCAT(TRIM(COALESCE(first_name,'')), ' ', TRIM(COALESCE(last_name,''))) AS full_name
     FROM users
     WHERE role = 'teacher'
       AND (
            LOWER(useremail) = LOWER('langging@erecord.com')
            OR LOWER(username) = LOWER('langging')
            OR LOWER(CONCAT(TRIM(COALESCE(first_name,'')), ' ', TRIM(COALESCE(last_name,'')))) LIKE LOWER('%sumacot%')
       )
     ORDER BY id ASC
     LIMIT 1"
);
if ($teacherLookup) {
    $teacherLookup->execute();
    $tr = $teacherLookup->get_result();
    if ($tr && $tr->num_rows === 1) {
        $row = $tr->fetch_assoc();
        $teacherId = (int) ($row['id'] ?? 0);
        $teacherName = trim((string) ($row['full_name'] ?? ''));
    }
    $teacherLookup->close();
}
if ($teacherId > 0) {
    out("Teacher resolved: #{$teacherId} {$teacherName}");
} else {
    out('Teacher not resolved by profile lookup; proceeding by section-based class mapping only.');
}

// Load target class records.
$secList = implode(',', array_fill(0, count($targetSections), '?'));
$sqlClass =
    "SELECT cr.id, cr.subject_id, cr.teacher_id, cr.section, cr.academic_year, cr.semester, s.subject_code
     FROM class_records cr
     JOIN subjects s ON s.id = cr.subject_id
     WHERE cr.status = 'active'
       AND cr.section IN ({$secList})
     ORDER BY cr.section ASC, cr.id ASC";
$classStmt = $conn->prepare($sqlClass);
if (!$classStmt) {
    err('Failed to prepare class_records lookup.');
    exit(1);
}
$classStmt->bind_param(str_repeat('s', count($targetSections)), ...$targetSections);
$classStmt->execute();
$classRes = $classStmt->get_result();
$classRows = [];
while ($classRes && ($row = $classRes->fetch_assoc())) {
    $sec = (string) ($row['section'] ?? '');
    if (!isset($classRows[$sec])) {
        $classRows[$sec] = [];
    }
    $classRows[$sec][] = $row;
}
$classStmt->close();

$missingSections = [];
foreach ($targetSections as $sec) {
    if (empty($classRows[$sec])) {
        $missingSections[] = $sec;
    }
}
if (count($missingSections) > 0) {
    err('Missing active class_records for sections: ' . implode(', ', $missingSections));
    err('Fix class assignment first, then re-run.');
    exit(1);
}

out('Class records found:');
$classRecordIds = [];
foreach ($targetSections as $sec) {
    foreach ($classRows[$sec] as $r) {
        $id = (int) ($r['id'] ?? 0);
        $subjectCode = (string) ($r['subject_code'] ?? '');
        $teacher = isset($r['teacher_id']) ? (int) $r['teacher_id'] : 0;
        $term = trim((string) (($r['academic_year'] ?? '') . ' | ' . ($r['semester'] ?? '')));
        out("  CR#{$id} sec={$sec} subj={$subjectCode} teacher_id={$teacher} term={$term}");
        $classRecordIds[] = $id;
    }
}

// Build student pools once.
$studentPools = [];
$fetchStudents = $conn->prepare(
    "SELECT id, StudentNo
     FROM students
     WHERE Course = ? AND Year = ? AND Section = ?
     ORDER BY Surname ASC, FirstName ASC, StudentNo ASC"
);
if (!$fetchStudents) {
    err('Failed to prepare student pool query.');
    exit(1);
}

foreach ($targetSectionMap as $sectionCode => $profile) {
    $key = $profile['course'] . '|' . $profile['year'] . '|' . $profile['student_section'];
    if (isset($studentPools[$key])) {
        continue;
    }
    $course = (string) $profile['course'];
    $year = (string) $profile['year'];
    $studentSection = (string) $profile['student_section'];
    $fetchStudents->bind_param('sss', $course, $year, $studentSection);
    $fetchStudents->execute();
    $res = $fetchStudents->get_result();
    $rows = [];
    while ($res && ($sr = $res->fetch_assoc())) {
        $rows[] = [
            'id' => (int) ($sr['id'] ?? 0),
            'student_no' => trim((string) ($sr['StudentNo'] ?? '')),
        ];
    }
    $studentPools[$key] = $rows;
    out("Student pool {$course} / {$year} / {$studentSection}: " . count($rows));
}
$fetchStudents->close();

$today = date('Y-m-d');
$createdByLabel = 'system-seed';

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
    err('Failed to prepare roster/enrollment statements.');
    exit(1);
}

$counts = [
    'class_enroll_inserted' => 0,
    'class_enroll_dupe' => 0,
    'class_enroll_skipped' => 0,
    'enrollment_upserted' => 0,
];

if (!$dryRun) {
    $conn->begin_transaction();
}

try {
    foreach ($targetSections as $sec) {
        $profile = $targetSectionMap[$sec];
        $poolKey = $profile['course'] . '|' . $profile['year'] . '|' . $profile['student_section'];
        $students = $studentPools[$poolKey] ?? [];
        if (count($students) === 0) {
            out("No students for {$sec} pool {$poolKey}; skipping.");
            continue;
        }

        foreach ($classRows[$sec] as $classRow) {
            $classRecordId = (int) ($classRow['id'] ?? 0);
            $subjectId = (int) ($classRow['subject_id'] ?? 0);
            $academicYear = trim((string) ($classRow['academic_year'] ?? ''));
            $semester = trim((string) ($classRow['semester'] ?? ''));

            if ($classRecordId <= 0 || $subjectId <= 0 || $academicYear === '' || $semester === '') {
                $counts['class_enroll_skipped'] += count($students);
                continue;
            }

            foreach ($students as $student) {
                $studentId = (int) ($student['id'] ?? 0);
                $studentNo = (string) ($student['student_no'] ?? '');
                if ($studentId <= 0 || $studentNo === '') {
                    $counts['class_enroll_skipped']++;
                    continue;
                }

                if ($dryRun) {
                    $counts['class_enroll_inserted']++;
                    $counts['enrollment_upserted']++;
                    continue;
                }

                try {
                    $insClassEnroll->bind_param('iisii', $classRecordId, $studentId, $today, $adminId, $classRecordId);
                    $insClassEnroll->execute();
                    $counts['class_enroll_inserted']++;
                } catch (mysqli_sql_exception $e) {
                    if ((int) $e->getCode() === 1062) {
                        $counts['class_enroll_dupe']++;
                    } else {
                        throw $e;
                    }
                }

                $upsertEnroll->bind_param('sissss', $studentNo, $subjectId, $academicYear, $semester, $sec, $createdByLabel);
                $upsertEnroll->execute();
                $counts['enrollment_upserted']++;
            }
        }
    }

    if (!$dryRun) {
        $conn->commit();
    }
} catch (Throwable $e) {
    if (!$dryRun) {
        $conn->rollback();
    }
    err('FAILED: ' . $e->getMessage());
    exit(1);
} finally {
    $insClassEnroll->close();
    $upsertEnroll->close();
}

out('');
out('Result:');
out('  class_enrollments inserted: ' . $counts['class_enroll_inserted']);
out('  class_enrollments duplicates: ' . $counts['class_enroll_dupe']);
out('  class_enrollments skipped: ' . $counts['class_enroll_skipped']);
out('  enrollments upserted: ' . $counts['enrollment_upserted']);

if (count($classRecordIds) > 0) {
    out('');
    out('Roster counts after run:');
    $ids = implode(',', array_map('intval', $classRecordIds));
    $verifySql =
        "SELECT cr.id, cr.section, s.subject_code, COUNT(ce.id) AS roster_count
         FROM class_records cr
         JOIN subjects s ON s.id = cr.subject_id
         LEFT JOIN class_enrollments ce ON ce.class_record_id = cr.id
         WHERE cr.id IN ({$ids})
         GROUP BY cr.id, cr.section, s.subject_code
         ORDER BY cr.section ASC, cr.id ASC";
    $v = $conn->query($verifySql);
    while ($v && ($row = $v->fetch_assoc())) {
        out('  CR#' . (int) $row['id']
            . ' sec=' . (string) $row['section']
            . ' subj=' . (string) $row['subject_code']
            . ' roster=' . (int) $row['roster_count']);
    }
}

out('');
out('Done.');

