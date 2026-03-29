<?php
/**
 * Seed/maintenance script: upsert subjects + class_records + schedule_slots
 * for AY 2025 - 2026 | 2nd Semester, based on the provided schedule list.
 *
 * Run:
 *   php tools/seed_2025_2026_2nd_sem_subjects_schedules.php
 *
 * Dry run:
 *   php tools/seed_2025_2026_2nd_sem_subjects_schedules.php --dry-run
 */

require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/schedule.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

$dryRun = in_array('--dry-run', $argv ?? [], true);

function out($s) { fwrite(STDOUT, $s . PHP_EOL); }
function warn($s) { fwrite(STDERR, '[warn] ' . $s . PHP_EOL); }

function time_to_mysql($time12h) {
    $time12h = trim((string) $time12h);
    $dt = DateTimeImmutable::createFromFormat('h:i A', $time12h);
    if (!$dt) return null;
    return $dt->format('H:i:s');
}

function parse_days($token) {
    // Supports common PH campus abbreviations: M, T, W, Th, F, Sat, Sun; combined (e.g., MTh, TF).
    $token = trim((string) $token);
    if ($token === '') return [];

    $map = [
        'Sun' => 0,
        'M' => 1,
        'T' => 2,
        'W' => 3,
        'Th' => 4,
        'F' => 5,
        'Sat' => 6,
    ];

    preg_match_all('/Sun|Sat|Th|M|T|W|F/', $token, $m);
    $days = [];
    foreach (($m[0] ?? []) as $t) {
        if (isset($map[$t])) $days[] = $map[$t];
    }
    $days = array_values(array_unique($days));
    sort($days);
    return $days;
}

function parse_schedule_str($s) {
    // Example: "CLab-1 MTh 01:00 PM 02:00 PM"
    $s = preg_replace('/\\s+/', ' ', trim((string) $s));
    if ($s === '') return null;

    if (!preg_match('/^(.+?)\\s+([A-Za-z]+)\\s+(\\d{1,2}:\\d{2}\\s+[AP]M)\\s+(\\d{1,2}:\\d{2}\\s+[AP]M)$/', $s, $m)) {
        return null;
    }

    $room = trim((string) $m[1]);
    $daysToken = trim((string) $m[2]);
    $start12 = trim((string) $m[3]);
    $end12 = trim((string) $m[4]);

    $days = parse_days($daysToken);
    $start = time_to_mysql($start12);
    $end = time_to_mysql($end12);
    if (count($days) === 0 || !$start || !$end) return null;

    return [
        'room' => $room,
        'days' => $days,
        'start_time' => $start,
        'end_time' => $end,
    ];
}

out('Seed subjects + schedules: AY 2025 - 2026 | 2nd Semester');
out('Mode: ' . ($dryRun ? 'DRY RUN' : 'APPLY'));

// Ensure schedule tables exist.
ensure_schedule_tables($conn);

// Resolve an admin user to satisfy subjects.created_by and schedule_slots.created_by.
$adminId = 1;
$adm = $conn->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
if ($adm && $adm->num_rows === 1) $adminId = (int) ($adm->fetch_assoc()['id'] ?? 1);
out("Admin user id: {$adminId}");

$academicYear = '2025 - 2026';
$semester = '2nd Semester';

// Source rows (from the provided schedule list).
$rows = [
    ['course_code' => 'IF-2-B-6',  'subject_code' => 'IT 208',  'desc' => 'Integrative Programming and Technologies', 'units' => 2.0, 'schedule' => 'CLab-1 MTh 01:00 PM 02:00 PM'],
    ['course_code' => 'IF-2-B-7',  'subject_code' => 'IT 208L', 'desc' => 'Integrative Programming and Technologies', 'units' => 1.0, 'schedule' => 'CLab-1 MTh 02:00 PM 03:30 PM'],
    ['course_code' => 'IF-3-A-7',  'subject_code' => 'IT 314',  'desc' => 'Functional Programming',                 'units' => 2.0, 'schedule' => 'CLab-2 MTh 07:30 AM 08:30 AM'],
    ['course_code' => 'IF-3-B-8',  'subject_code' => 'IT 314L', 'desc' => 'Functional Programming',                 'units' => 1.0, 'schedule' => 'CLab-2 MTh 11:00 AM 12:30 PM'],
    ['course_code' => 'IF-2-A-6',  'subject_code' => 'IT 208',  'desc' => 'Integrative Programming and Technologies', 'units' => 2.0, 'schedule' => 'CLab-1 MTh 03:30 PM 04:30 PM'],
    ['course_code' => 'IF-2-A-7',  'subject_code' => 'IT 208L', 'desc' => 'Integrative Programming and Technologies', 'units' => 1.0, 'schedule' => 'CLab-1 MTh 04:30 PM 06:00 PM'],
    ['course_code' => 'IF-3-A-9',  'subject_code' => 'IT 316',  'desc' => 'Embedded Systems Programming',          'units' => 2.0, 'schedule' => 'CLab-2 TF 07:30 AM 08:30 AM'],
    ['course_code' => 'IF-3-A-8',  'subject_code' => 'IT 314L', 'desc' => 'Functional Programming',                 'units' => 1.0, 'schedule' => 'CLab-2 MTh 08:30 AM 10:00 AM'],
    ['course_code' => 'IF-3-A-10', 'subject_code' => 'IT 316L', 'desc' => 'Embedded Systems Programming',          'units' => 1.0, 'schedule' => 'CLab-2 TF 08:30 AM 10:00 AM'],
    ['course_code' => 'IF-3-B-7',  'subject_code' => 'IT 314',  'desc' => 'Functional Programming',                 'units' => 2.0, 'schedule' => 'CLab-2 MTh 10:00 AM 11:00 AM'],
    ['course_code' => 'IF-3-B-9',  'subject_code' => 'IT 316',  'desc' => 'Embedded Systems Programming',          'units' => 2.0, 'schedule' => 'CLab-2 TF 10:00 AM 11:00 AM'],
    ['course_code' => 'IF-3-B-10', 'subject_code' => 'IT 316L', 'desc' => 'Embedded Systems Programming',          'units' => 1.0, 'schedule' => 'CLab-2 TF 11:00 AM 12:30 PM'],
];

// Upsert subjects (by subject_code).
$subSel = $conn->prepare("SELECT id FROM subjects WHERE subject_code = ? LIMIT 1");
$subIns = $conn->prepare(
    "INSERT INTO subjects (subject_code, subject_name, description, course, major, academic_year, semester, units, type, status, created_by)
     VALUES (?, ?, ?, '', '', ?, ?, ?, ?, 'active', ?)"
);
$subUpd = $conn->prepare(
    "UPDATE subjects
     SET subject_name = ?, description = ?, academic_year = ?, semester = ?, units = ?, type = ?, status = 'active'
     WHERE subject_code = ?"
);
if (!$subSel || !$subIns || !$subUpd) {
    throw new RuntimeException('Failed to prepare subject statements.');
}

$subjectIds = []; // code => id
foreach ($rows as $r) {
    $code = (string) $r['subject_code'];
    if (isset($subjectIds[$code])) continue;

    $baseName = (string) $r['desc'];
    $isLab = preg_match('/L\\s*$/', $code) === 1;
    $subjectName = $isLab ? ($baseName . ' [Laboratory]') : $baseName;
    $units = (float) $r['units'];
    $type = $isLab ? 'Laboratory' : 'Lecture';

    $subSel->bind_param('s', $code);
    $subSel->execute();
    $res = $subSel->get_result();
    if ($res && $res->num_rows === 1) {
        $id = (int) ($res->fetch_assoc()['id'] ?? 0);
        $subjectIds[$code] = $id;
        out("Subject exists: {$code} (id={$id})");
        if (!$dryRun) {
            $subUpd->bind_param('ssssdss', $subjectName, $baseName, $academicYear, $semester, $units, $type, $code);
            $subUpd->execute();
        }
        continue;
    }

    if ($dryRun) {
        out("Would create subject: {$code} ({$subjectName})");
        $subjectIds[$code] = -1;
        continue;
    }

    $subIns->bind_param('sssssdsi', $code, $subjectName, $baseName, $academicYear, $semester, $units, $type, $adminId);
    $subIns->execute();
    $id = (int) $conn->insert_id;
    $subjectIds[$code] = $id;
    out("Created subject: {$code} (id={$id})");
}

$subSel->close();
$subIns->close();
$subUpd->close();

// Class record upsert (by subject_id + section + term).
$crSel = $conn->prepare(
    "SELECT id FROM class_records
     WHERE subject_id = ? AND section = ? AND academic_year = ? AND semester = ? AND status = 'active'
     LIMIT 1"
);
if (!$crSel) throw new RuntimeException('Failed to prepare class_records select.');

// Prepared insert with teacher_id as NULL; may fail if teacher_id is NOT NULL, so we retry with adminId.
$crInsNullTeacher = $conn->prepare(
    "INSERT INTO class_records (subject_id, teacher_id, record_type, section, academic_year, semester, created_by, status)
     VALUES (?, NULL, 'assigned', ?, ?, ?, ?, 'active')"
);
$crInsWithTeacher = $conn->prepare(
    "INSERT INTO class_records (subject_id, teacher_id, record_type, section, academic_year, semester, created_by, status)
     VALUES (?, ?, 'assigned', ?, ?, ?, ?, 'active')"
);
if (!$crInsNullTeacher || !$crInsWithTeacher) {
    throw new RuntimeException('Failed to prepare class_records inserts.');
}

// Slot upsert: look up exact slot, else insert.
$slotSel = $conn->prepare(
    "SELECT id, status FROM schedule_slots
     WHERE class_record_id = ? AND day_of_week = ? AND start_time = ? AND end_time = ? AND COALESCE(room,'') = ?
     LIMIT 1"
);
$slotIns = $conn->prepare(
    "INSERT INTO schedule_slots (class_record_id, day_of_week, start_time, end_time, room, modality, notes, status, created_by)
     VALUES (?, ?, ?, ?, ?, 'face_to_face', NULL, 'active', ?)"
);
$slotUpd = $conn->prepare(
    "UPDATE schedule_slots
     SET status = 'active', modality = 'face_to_face'
     WHERE id = ?"
);
if (!$slotSel || !$slotIns || !$slotUpd) {
    throw new RuntimeException('Failed to prepare schedule_slots statements.');
}

$createdClassRecords = 0;
$createdSlots = 0;
$reactivatedSlots = 0;
$skippedSlots = 0;

foreach ($rows as $r) {
    $courseCode = trim((string) $r['course_code']);
    $subjectCode = trim((string) $r['subject_code']);
    $schedStr = (string) $r['schedule'];

    $subjectId = (int) ($subjectIds[$subjectCode] ?? 0);
    if ($subjectId <= 0) {
        warn("Skipping row (subject missing): {$courseCode} / {$subjectCode}");
        continue;
    }

    $parsed = parse_schedule_str($schedStr);
    if (!$parsed) {
        warn("Skipping row (schedule parse failed): {$courseCode} / {$subjectCode} / {$schedStr}");
        continue;
    }

    // Ensure class_record exists.
    $classRecordId = 0;
    $crSel->bind_param('isss', $subjectId, $courseCode, $academicYear, $semester);
    $crSel->execute();
    $res = $crSel->get_result();
    if ($res && $res->num_rows === 1) {
        $classRecordId = (int) ($res->fetch_assoc()['id'] ?? 0);
        out("Class record exists: {$courseCode} / {$subjectCode} (id={$classRecordId})");
    } else {
        if ($dryRun) {
            out("Would create class record: {$courseCode} / {$subjectCode}");
            $classRecordId = -1;
        } else {
            try {
                $crInsNullTeacher->bind_param('isssi', $subjectId, $courseCode, $academicYear, $semester, $adminId);
                $crInsNullTeacher->execute();
                $classRecordId = (int) $conn->insert_id;
            } catch (Throwable $e) {
                // Retry with teacher_id = adminId if teacher_id is NOT NULL.
                $crInsWithTeacher->bind_param('iisssi', $subjectId, $adminId, $courseCode, $academicYear, $semester, $adminId);
                $crInsWithTeacher->execute();
                $classRecordId = (int) $conn->insert_id;
            }

            $createdClassRecords++;
            out("Created class record: {$courseCode} / {$subjectCode} (id={$classRecordId})");
        }
    }

    // Upsert schedule slots for each day.
    foreach (($parsed['days'] ?? []) as $dow) {
        $dow = (int) $dow;
        $st = (string) $parsed['start_time'];
        $et = (string) $parsed['end_time'];
        $room = (string) ($parsed['room'] ?? '');
        $roomCmp = $room; // COALESCE(room,'') compare value

        if ($dryRun) {
            out("Would upsert slot: CR {$classRecordId} DOW {$dow} {$st}-{$et} {$room}");
            continue;
        }

        $slotSel->bind_param('iisss', $classRecordId, $dow, $st, $et, $roomCmp);
        $slotSel->execute();
        $sr = $slotSel->get_result();
        if ($sr && $sr->num_rows === 1) {
            $row = $sr->fetch_assoc();
            $slotId = (int) ($row['id'] ?? 0);
            $status = (string) ($row['status'] ?? '');
            if ($status !== 'active') {
                $slotUpd->bind_param('i', $slotId);
                $slotUpd->execute();
                $reactivatedSlots++;
                out("Reactivated slot id={$slotId} ({$courseCode} {$subjectCode})");
            } else {
                $skippedSlots++;
            }
            continue;
        }

        $slotIns->bind_param('iisssi', $classRecordId, $dow, $st, $et, $room, $adminId);
        $slotIns->execute();
        $createdSlots++;
    }

    if (!$dryRun && $classRecordId > 0) {
        // Update class_records.schedule and room_number summary for convenience.
        schedule_update_class_record_summary($conn, $classRecordId);
    }
}

$crSel->close();
$crInsNullTeacher->close();
$crInsWithTeacher->close();
$slotSel->close();
$slotIns->close();
$slotUpd->close();

out('---');
out('Summary:');
out("Created class_records: {$createdClassRecords}");
out("Created schedule_slots: {$createdSlots}");
out("Reactivated schedule_slots: {$reactivatedSlots}");
out("Unchanged schedule_slots: {$skippedSlots}");
