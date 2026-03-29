<?php
/**
 * Maintenance script: assign IT 314 + IT 314L to instructor "langga" for BSIT - 3A and BSIT - 3B.
 *
 * Data written:
 * - class_records (creates if missing; updates teacher_id only if no roster exists yet)
 * - teacher_assignments (upserts active row for the teacher per class_record)
 *
 * Run:
 *   php tools/assign_instructor_langga_it314.php
 *
 * Optional:
 *   php tools/assign_instructor_langga_it314.php --dry-run
 */

require __DIR__ . '/../config/db.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

$dryRun = in_array('--dry-run', $argv ?? [], true);

function out($s) { fwrite(STDOUT, $s . PHP_EOL); }
function err($s) { fwrite(STDERR, $s . PHP_EOL); }

$teacherUsername = 'langga';
$subjectCodes = ['IT 314', 'IT 314L'];
$sections = ['BSIT - 3A', 'BSIT - 3B'];

out('Assign instructor -> IT 314 / IT 314L');
out('Teacher username: ' . $teacherUsername);
out('Mode: ' . ($dryRun ? 'DRY RUN' : 'APPLY'));

// Admin user id (assigned_by / created_by).
$adminId = 1;
$adm = $conn->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
if ($adm && $adm->num_rows === 1) $adminId = (int) ($adm->fetch_assoc()['id'] ?? 1);

// Resolve teacher by username (case-insensitive) with a fallback search.
$teacher = null;
$stmtT = $conn->prepare("SELECT id, username, useremail, role, is_active FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1");
if (!$stmtT) {
    err('Failed to prepare teacher lookup.');
    exit(1);
}
$stmtT->bind_param('s', $teacherUsername);
$stmtT->execute();
$resT = $stmtT->get_result();
if ($resT && $resT->num_rows === 1) $teacher = $resT->fetch_assoc();
$stmtT->close();

if (!$teacher) {
    $like = '%' . $teacherUsername . '%';
    $stmtLike = $conn->prepare("SELECT id, username, useremail, role, is_active FROM users WHERE username LIKE ? ORDER BY id ASC LIMIT 5");
    if ($stmtLike) {
        $stmtLike->bind_param('s', $like);
        $stmtLike->execute();
        $r = $stmtLike->get_result();
        err("Teacher '{$teacherUsername}' not found. Similar users:");
        while ($r && ($row = $r->fetch_assoc())) {
            err("  #{$row['id']} {$row['username']} <{$row['useremail']}> role={$row['role']} active={$row['is_active']}");
        }
        $stmtLike->close();
    } else {
        err("Teacher '{$teacherUsername}' not found.");
    }
    exit(1);
}

$teacherId = (int) ($teacher['id'] ?? 0);
$teacherRole = (string) ($teacher['role'] ?? '');
$teacherActive = (int) ($teacher['is_active'] ?? 0);

out("Teacher resolved: #{$teacherId} {$teacher['username']} <{$teacher['useremail']}> role={$teacherRole} active={$teacherActive}");
if ($teacherId <= 0) exit(1);
if (strtolower($teacherRole) !== 'teacher') {
    err("User '{$teacherUsername}' is not role=teacher (current role='{$teacherRole}'). Fix role first in admin-users.php.");
    exit(1);
}
if ($teacherActive !== 1) {
    err("User '{$teacherUsername}' is not approved (is_active=0). Approve first in admin-users.php so they can access teacher pages.");
    exit(1);
}

// Resolve subject ids.
$stmtS = $conn->prepare("SELECT id, subject_code, subject_name, status FROM subjects WHERE subject_code = ? LIMIT 1");
if (!$stmtS) {
    err('Failed to prepare subject lookup.');
    exit(1);
}

$subjects = []; // code => ['id'=>int,'name'=>string]
foreach ($subjectCodes as $code) {
    $stmtS->bind_param('s', $code);
    $stmtS->execute();
    $res = $stmtS->get_result();
    if (!$res || $res->num_rows !== 1) {
        err("Subject not found: {$code}");
        exit(1);
    }
    $row = $res->fetch_assoc();
    if (strtolower((string) ($row['status'] ?? '')) !== 'active') {
        err("Subject is not active: {$code}");
        exit(1);
    }
    $subjects[$code] = ['id' => (int) ($row['id'] ?? 0), 'name' => (string) ($row['subject_name'] ?? '')];
}
$stmtS->close();

out('Subjects:');
foreach ($subjects as $code => $s) out("  {$code}: subj#{$s['id']} {$s['name']}");

// Detect AY/Semester from enrollments for these subjects and sections (preferred).
$academicYear = '';
$semester = '';
$subjIds = array_map(fn($s) => (int) $s['id'], array_values($subjects));
$idList = implode(',', array_map('intval', $subjIds));
$secList = implode(',', array_fill(0, count($sections), '?'));

$sqlTerm =
    "SELECT academic_year, semester, COUNT(1) AS c
     FROM enrollments
     WHERE subject_id IN ({$idList})
       AND section IN ({$secList})
       AND academic_year IS NOT NULL AND academic_year <> ''
       AND semester IS NOT NULL AND semester <> ''
     GROUP BY academic_year, semester
     ORDER BY c DESC, academic_year DESC, semester DESC
     LIMIT 1";

$stmtTerm = $conn->prepare($sqlTerm);
if ($stmtTerm) {
    $types = str_repeat('s', count($sections));
    $stmtTerm->bind_param($types, ...$sections);
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
    // Fallback to the seed defaults used by tools/seed_bsit3_it314_functional_programming.php
    $academicYear = '2025 - 2026';
    $semester = '2nd Semester';
}

out("Target term: AY {$academicYear} | {$semester}");

// Prepared statements for class_records and teacher_assignments.
$findCr = $conn->prepare(
    "SELECT id, teacher_id
     FROM class_records
     WHERE subject_id = ? AND section = ? AND academic_year = ? AND semester = ? AND status = 'active'
     LIMIT 1"
);
$hasRoster = $conn->prepare("SELECT 1 FROM class_enrollments WHERE class_record_id = ? LIMIT 1");
$insCr = $conn->prepare(
    "INSERT INTO class_records (subject_id, teacher_id, record_type, section, academic_year, semester, created_by, status)
     VALUES (?, ?, 'assigned', ?, ?, ?, ?, 'active')"
);
$updCrTeacher = $conn->prepare("UPDATE class_records SET teacher_id = ?, record_type = 'assigned' WHERE id = ?");

$findTa = $conn->prepare("SELECT id FROM teacher_assignments WHERE teacher_id = ? AND class_record_id = ? LIMIT 1");
$insTa = $conn->prepare(
    "INSERT INTO teacher_assignments (teacher_id, teacher_role, class_record_id, assigned_by, status, assignment_notes)
     VALUES (?, ?, ?, ?, 'active', ?)"
);
$updTa = $conn->prepare(
    "UPDATE teacher_assignments
     SET teacher_role = ?, assigned_by = ?, status = 'active', assigned_at = CURRENT_TIMESTAMP
     WHERE id = ?"
);
$hasActivePrimary = $conn->prepare(
    "SELECT 1 FROM teacher_assignments
     WHERE class_record_id = ? AND status = 'active' AND teacher_role = 'primary'
     LIMIT 1"
);

if (!$findCr || !$hasRoster || !$insCr || !$updCrTeacher || !$findTa || !$insTa || !$updTa || !$hasActivePrimary) {
    err('Missing required tables or failed to prepare statements (class_records/teacher_assignments/class_enrollments).');
    exit(1);
}

$counts = [
    'class_records_created' => 0,
    'class_records_teacher_updated' => 0,
    'class_records_locked' => 0,
    'teacher_assignments_created' => 0,
    'teacher_assignments_updated' => 0,
];

if (!$dryRun) $conn->begin_transaction();
try {
    foreach ($sections as $sec) {
        foreach ($subjects as $code => $s) {
            $subjectId = (int) $s['id'];

            // Ensure class_record.
            $classRecordId = 0;
            $existingTeacherId = null;

            $findCr->bind_param('isss', $subjectId, $sec, $academicYear, $semester);
            $findCr->execute();
            $res = $findCr->get_result();
            if ($res && $res->num_rows === 1) {
                $row = $res->fetch_assoc();
                $classRecordId = (int) ($row['id'] ?? 0);
                $existingTeacherId = isset($row['teacher_id']) ? (int) $row['teacher_id'] : null;
            } else {
                if ($dryRun) {
                    out("Would create class_record: {$code} sec={$sec}");
                    $classRecordId = -1;
                } else {
                    $insCr->bind_param('iisssi', $subjectId, $teacherId, $sec, $academicYear, $semester, $adminId);
                    $insCr->execute();
                    $classRecordId = (int) $conn->insert_id;
                    $counts['class_records_created']++;
                    out("Created class_record#{$classRecordId}: {$code} sec={$sec}");
                }
            }

            // If class_record exists, only change teacher_id if no roster exists (matches admin-assign-teachers lock behavior).
            if ($classRecordId > 0 && $existingTeacherId !== $teacherId) {
                $has = false;
                $hasRoster->bind_param('i', $classRecordId);
                $hasRoster->execute();
                $r = $hasRoster->get_result();
                $has = ($r && $r->num_rows > 0);

                if ($has) {
                    $counts['class_records_locked']++;
                    out("Locked (has roster): class_record#{$classRecordId} {$code} sec={$sec} (kept teacher_id={$existingTeacherId})");
                } else {
                    if ($dryRun) {
                        out("Would update class_record teacher: class_record#{$classRecordId} {$code} sec={$sec} -> teacher#{$teacherId}");
                    } else {
                        $updCrTeacher->bind_param('ii', $teacherId, $classRecordId);
                        $updCrTeacher->execute();
                        $counts['class_records_teacher_updated']++;
                    }
                }
            }

            // Upsert teacher_assignment for this class_record.
            if ($classRecordId <= 0) continue; // dry-run placeholder

            $findTa->bind_param('ii', $teacherId, $classRecordId);
            $findTa->execute();
            $taRes = $findTa->get_result();
            $existingTaId = 0;
            if ($taRes && $taRes->num_rows === 1) $existingTaId = (int) ($taRes->fetch_assoc()['id'] ?? 0);

            // If another primary exists, add as co_teacher; otherwise primary.
            $role = 'primary';
            $hasActivePrimary->bind_param('i', $classRecordId);
            $hasActivePrimary->execute();
            $pr = $hasActivePrimary->get_result();
            if ($pr && $pr->num_rows > 0) $role = 'co_teacher';

            if ($existingTaId > 0) {
                if ($dryRun) {
                    out("Would update teacher_assignment#{$existingTaId}: {$code} sec={$sec} role={$role}");
                } else {
                    $updTa->bind_param('sii', $role, $adminId, $existingTaId);
                    $updTa->execute();
                    $counts['teacher_assignments_updated']++;
                }
            } else {
                $note = 'Auto-assigned by maintenance script.';
                if ($dryRun) {
                    out("Would create teacher_assignment: {$code} sec={$sec} role={$role}");
                } else {
                    $insTa->bind_param('isiis', $teacherId, $role, $classRecordId, $adminId, $note);
                    $insTa->execute();
                    $counts['teacher_assignments_created']++;
                }
            }
        }
    }

    if (!$dryRun) $conn->commit();
} catch (Throwable $e) {
    if (!$dryRun) $conn->rollback();
    throw $e;
}

out('Summary:');
foreach ($counts as $k => $v) out("  {$k}: {$v}");
out('Done');

