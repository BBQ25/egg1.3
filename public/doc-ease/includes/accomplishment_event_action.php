<?php
include __DIR__ . '/../layouts/session.php';
require_active_role('teacher');

header('Content-Type: application/json; charset=UTF-8');

function acc_evt_json($status, $message, array $extra = []) {
    echo json_encode(array_merge(['status' => $status, 'message' => $message], $extra), JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    acc_evt_json('error', 'Database connection unavailable.');
}

require_once __DIR__ . '/teacher_activity_events.php';
require_once __DIR__ . '/attendance_checkin.php';
require_once __DIR__ . '/accomplishments.php';

teacher_activity_ensure_tables($conn);
attendance_checkin_ensure_tables($conn);
if (function_exists('ensure_accomplishment_tables')) ensure_accomplishment_tables($conn);

$teacherId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
if ($teacherId <= 0) acc_evt_json('error', 'Unauthorized.');

$raw = file_get_contents('php://input');
$req = null;
if (is_string($raw) && trim($raw) !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) $req = $decoded;
}
if (!is_array($req)) $req = $_POST;

$csrf = (string) ($req['csrf_token'] ?? '');
if (!csrf_validate($csrf)) {
    acc_evt_json('error', 'Security check failed (CSRF). Please refresh and try again.');
}

$action = strtolower(trim((string) ($req['action'] ?? '')));
$eventId = (int) ($req['event_id'] ?? 0);
if (!in_array($action, ['accept', 'dismiss'], true) || $eventId <= 0) {
    acc_evt_json('error', 'Invalid request.');
}

$event = teacher_activity_get_event_for_teacher($conn, $eventId, $teacherId);
if (!is_array($event)) acc_evt_json('error', 'Event not found.');

$status = strtolower(trim((string) ($event['status'] ?? 'pending')));
if ($status !== 'pending') {
    // Idempotent responses for double-clicks or refresh.
    acc_evt_json('ok', 'Already handled.', ['handled' => true, 'status_value' => $status]);
}

if ($action === 'dismiss') {
    teacher_activity_mark_event_handled($conn, $eventId, $teacherId, 'dismissed', null);
    if (function_exists('teacher_activity_queue_remove')) teacher_activity_queue_remove($eventId);
    unset($_SESSION['pending_accomplishment_event']);
    $_SESSION['flash_message'] = 'Activity was not added to Monthly Accomplishment.';
    $_SESSION['flash_type'] = 'info';
    acc_evt_json('ok', 'Dismissed.', ['handled' => true, 'status_value' => 'dismissed']);
}

// Accept -> create accomplishment entry + auto-evidence.
$classRecordId = (int) ($event['class_record_id'] ?? 0);
$eventType = trim((string) ($event['event_type'] ?? ''));
$eventDate = trim((string) ($event['event_date'] ?? date('Y-m-d')));
$eventTitle = trim((string) ($event['title'] ?? 'Class Activity'));

$payload = [];
$payloadJson = (string) ($event['payload_json'] ?? '');
if ($payloadJson !== '') {
    $decoded = json_decode($payloadJson, true);
    if (is_array($decoded)) $payload = $decoded;
}

$subjectLabel = teacher_activity_class_subject_label($conn, $classRecordId);

// Build context + evidence for known event types (start with attendance).
$contextLines = [];
$contextLines[] = 'Teacher activity: ' . ($eventTitle !== '' ? $eventTitle : $eventType);
$contextLines[] = 'Event date: ' . $eventDate;
$contextLines[] = 'Subject label: ' . $subjectLabel;

$proofPng = '';
$proofName = '';
$proofMime = '';
$proofSize = 0;
$nowTs = time();

if (isset($payload['attendance_session_id'])) {
    $sessionId = (int) $payload['attendance_session_id'];
    [$session, $roster] = attendance_checkin_get_session_roster($conn, $teacherId, $sessionId);
    if (is_array($session)) {
        $label = trim((string) ($session['session_label'] ?? 'Attendance'));
        $date = trim((string) ($session['session_date'] ?? $eventDate));
        $method = trim((string) ($session['checkin_method'] ?? 'code'));
        $phase = function_exists('attendance_checkin_phase')
            ? (string) attendance_checkin_phase($session, $nowTs)
            : (((int) ($session['is_closed'] ?? 0) === 1) ? 'closed' : 'present_window');
        if (!in_array($phase, ['upcoming', 'present_window', 'late_window', 'closed'], true)) {
            $phase = 'closed';
        }
        $isClosed = ($phase === 'closed');
        $phaseLabel = function_exists('attendance_checkin_phase_label')
            ? (string) attendance_checkin_phase_label($phase)
            : ucfirst(str_replace('_', ' ', $phase));
        $session['_phase'] = $phase;

        $present = 0;
        $late = 0;
        $missing = 0;
        foreach ($roster as $r) {
            if (!is_array($r)) continue;
            $st = strtolower(trim((string) ($r['submitted_status'] ?? '')));
            if ($st === 'present') $present++;
            elseif ($st === 'late') $late++;
            else $missing++;
        }
        $absent = $isClosed ? $missing : 0;
        $pending = $isClosed ? 0 : $missing;

        $contextLines[] = 'Attendance session: ' . ($label !== '' ? $label : 'Attendance');
        $contextLines[] = 'Session date: ' . $date;
        $contextLines[] = 'Check-in method: ' . $method;
        $contextLines[] = 'Window status: ' . ($isClosed ? 'closed' : 'open');
        $contextLines[] = 'Window phase: ' . $phaseLabel;
        $contextLines[] = 'Roster size: ' . (int) count($roster);
        $contextLines[] = 'Counts: present=' . $present . ', late=' . $late . ', ' . ($isClosed ? ('absent=' . $absent) : ('pending=' . $pending));

        $proofPng = teacher_activity_gd_make_attendance_chart_png($session, $roster);
        if ($proofPng !== '') {
            $proofMime = 'image/png';
            $proofSize = strlen($proofPng);
            $proofName = 'Attendance chart - ' . $date . '.png';
        }
    }
}

$attendanceSummaryAttached = false;
if ($proofPng === '' && $classRecordId > 0) {
    // Generic attendance summary evidence for this class & date.
    $sessionIds = [];
    $methods = [];
    $labels = [];
    $allClosed = true;
    $phaseCounts = ['upcoming' => 0, 'present_window' => 0, 'late_window' => 0, 'closed' => 0];
    $summaryPhase = 'closed';

    $s = $conn->prepare(
        "SELECT id, session_label, checkin_method, is_closed, starts_at, present_until, late_until
         FROM attendance_sessions
         WHERE class_record_id = ? AND session_date = ?
           " . (function_exists('attendance_checkin_has_deleted_column') && attendance_checkin_has_deleted_column($conn) ? " AND COALESCE(is_deleted, 0) = 0" : "") . "
         ORDER BY id ASC"
    );
    if ($s) {
        $s->bind_param('is', $classRecordId, $eventDate);
        $s->execute();
        $res = $s->get_result();
        while ($res && ($r = $res->fetch_assoc())) {
            $sid = (int) ($r['id'] ?? 0);
            if ($sid <= 0) continue;
            $sessionIds[] = $sid;
            $m = strtolower(trim((string) ($r['checkin_method'] ?? 'code')));
            if ($m !== '') $methods[$m] = true;
            $lab = trim((string) ($r['session_label'] ?? ''));
            if ($lab !== '') $labels[] = $lab;

            $phase = function_exists('attendance_checkin_phase')
                ? (string) attendance_checkin_phase($r, $nowTs)
                : (((int) ($r['is_closed'] ?? 0) === 1) ? 'closed' : 'present_window');
            if (!in_array($phase, ['upcoming', 'present_window', 'late_window', 'closed'], true)) {
                $phase = 'closed';
            }
            $phaseCounts[$phase] = (int) ($phaseCounts[$phase] ?? 0) + 1;
            if ($phase !== 'closed') $allClosed = false;
        }
        $s->close();
    }

    if (count($sessionIds) > 0) {
        if (($phaseCounts['present_window'] ?? 0) > 0) $summaryPhase = 'present_window';
        elseif (($phaseCounts['late_window'] ?? 0) > 0) $summaryPhase = 'late_window';
        elseif (($phaseCounts['upcoming'] ?? 0) > 0) $summaryPhase = 'upcoming';
        else $summaryPhase = 'closed';
        $allClosed = ($summaryPhase === 'closed');

        $in = implode(',', array_map('intval', $sessionIds));

        $present = 0;
        $late = 0;
        $missing = 0;
        $total = 0;

        $sql = "SELECT ce.student_id,
                       MAX(CASE WHEN sb.status = 'present' THEN 1 ELSE 0 END) AS has_present,
                       MAX(CASE WHEN sb.status = 'late' THEN 1 ELSE 0 END) AS has_late
                FROM class_enrollments ce
                LEFT JOIN attendance_submissions sb
                       ON sb.student_id = ce.student_id
                      AND sb.session_id IN ($in)
                WHERE ce.class_record_id = ?
                  AND ce.status = 'enrolled'
                GROUP BY ce.student_id";
        $q = $conn->prepare($sql);
        if ($q) {
            $q->bind_param('i', $classRecordId);
            $q->execute();
            $res = $q->get_result();
            while ($res && ($r = $res->fetch_assoc())) {
                $total++;
                $hasPresent = (int) ($r['has_present'] ?? 0) === 1;
                $hasLate = (int) ($r['has_late'] ?? 0) === 1;
                if ($hasPresent) $present++;
                elseif ($hasLate) $late++;
                else $missing++;
            }
            $q->close();
        }

        $methodLabel = (count($methods) === 1) ? strtoupper((string) array_key_first($methods)) : 'MIXED';
        $label = (count($labels) === 1) ? $labels[0] : ('Attendance summary (' . count($sessionIds) . ' sessions)');

        // Build synthetic roster for the chart generator.
        $fakeRoster = [];
        for ($i = 0; $i < $present; $i++) $fakeRoster[] = ['submitted_status' => 'present'];
        for ($i = 0; $i < $late; $i++) $fakeRoster[] = ['submitted_status' => 'late'];
        for ($i = 0; $i < $missing; $i++) $fakeRoster[] = ['submitted_status' => ''];

        $fakeSession = [
            'is_closed' => $allClosed ? 1 : 0,
            'session_label' => $label,
            'session_date' => $eventDate,
            'checkin_method' => strtolower($methodLabel) === 'mixed' ? 'code' : strtolower($methodLabel),
            '_phase' => $summaryPhase,
        ];

        $contextLines[] = 'Attendance evidence: ' . $label;
        $contextLines[] = 'Attendance sessions found: ' . count($sessionIds);
        $contextLines[] = 'Attendance window status: ' . ($allClosed ? 'closed' : 'open');
        $contextLines[] = 'Attendance counts (unique students): present=' . $present . ', late=' . $late . ', ' . ($allClosed ? ('absent=' . $missing) : ('pending=' . $missing));

        $proofPng = teacher_activity_gd_make_attendance_chart_png($fakeSession, $fakeRoster);
        if ($proofPng !== '') {
            $proofMime = 'image/png';
            $proofSize = strlen($proofPng);
            $proofName = 'Attendance summary - ' . $eventDate . '.png';
            $attendanceSummaryAttached = true;
        }
    }
}

$context = implode("\n", $contextLines);

// AI generation (falls back to deterministic copy if key not configured or AI restricted).
$aiType = 'Lecture';
$aiDetails = $context;
$aiRemarks = 'Accomplished';

$remarksHint = (
    stripos($context, 'Window status: open') !== false
    || stripos($context, 'Attendance window status: open') !== false
) ? 'On-going' : 'Accomplished';
$aiRemarks = $remarksHint;

if (function_exists('acc_generate_descriptions_with_ai')) {
    // acc_generate_descriptions_with_ai requires OpenAI key; if missing it returns [false, msg].
    [$okAi, $rowsOrMsg] = acc_generate_descriptions_with_ai($subjectLabel, $context, [$eventDate], 'balanced', []);
    if ($okAi && is_array($rowsOrMsg) && count($rowsOrMsg) > 0) {
        $row = $rowsOrMsg[0];
        $aiType = trim((string) ($row['type'] ?? 'Lecture'));
        $aiDetails = (string) ($row['description'] ?? $context);
        $aiRemarks = (string) ($row['remarks'] ?? $remarksHint);
    } else {
        // If AI fails, keep fallback.
        $aiRemarks = $remarksHint;
    }
}

$aiType = trim((string) $aiType);
if ($aiType === '') $aiType = 'Lecture';
$remarksStatus = function_exists('acc_normalize_remarks_status') ? acc_normalize_remarks_status($aiRemarks) : trim((string) $aiRemarks);
$remarksSupport = function_exists('acc_extract_remarks_support_text') ? acc_extract_remarks_support_text($aiRemarks) : '';
if (function_exists('acc_normalize_remarks_support_note')) {
    $remarksSupport = acc_normalize_remarks_support_note($remarksSupport, $aiDetails, $remarksStatus);
}
if (function_exists('acc_compose_remarks_with_support')) {
    $aiRemarks = acc_compose_remarks_with_support($remarksStatus, $remarksSupport);
} else {
    $aiRemarks = $remarksStatus;
}
if (strlen((string) $aiRemarks) > 5000) $aiRemarks = substr((string) $aiRemarks, 0, 5000);

[$okCreate, $entryIdOrMsg] = acc_create_entry($conn, $teacherId, $eventDate, $aiType, $aiDetails, $subjectLabel, $aiRemarks);
if (!$okCreate) {
    acc_evt_json('error', is_string($entryIdOrMsg) ? $entryIdOrMsg : 'Unable to create accomplishment entry.');
}
$entryId = (int) $entryIdOrMsg;

// Attach chart proof if available and acceptable.
$proofSaved = 0;
if ($entryId > 0 && $proofPng !== '' && $proofSize > 0 && $proofMime === 'image/png') {
    // Workspace root (doc-ease). Keep in sync with accomplishment uploader paths.
    $root = realpath(__DIR__ . '/..');
    if ($root) {
        $month = substr($eventDate, 0, 7);
        $relDir = 'uploads/accomplishments/user_' . $teacherId . '/' . $month . '/entry_' . $entryId;
        $absDir = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relDir);
        if (!is_dir($absDir)) @mkdir($absDir, 0775, true);

        $suffix = '';
        try { $suffix = bin2hex(random_bytes(6)); } catch (Throwable $e) { $suffix = substr(md5(uniqid('', true)), 0, 12); }
        $fileName = 'proof_generated_' . date('Ymd_His') . '_' . $suffix . '.png';
        $relPath = $relDir . '/' . $fileName;
        $absPath = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relPath);

        $written = @file_put_contents($absPath, $proofPng);
        if ($written !== false && (int) $written > 0) {
            $orig = $proofName !== '' ? $proofName : 'Attendance chart.png';
            if (strlen($orig) > 255) $orig = substr($orig, 0, 255);

            $ins = $conn->prepare(
                "INSERT INTO accomplishment_proofs (entry_id, original_name, file_name, file_path, file_size, mime_type)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            if ($ins) {
                $size = (int) $written;
                $mime = 'image/png';
                $ins->bind_param('isssis', $entryId, $orig, $fileName, $relPath, $size, $mime);
                $okIns = false;
                try { $okIns = $ins->execute(); } catch (Throwable $e) { $okIns = false; }
                $ins->close();
                if ($okIns) $proofSaved = 1;
                else @unlink($absPath);
            } else {
                @unlink($absPath);
            }
        }
    }
}

teacher_activity_mark_event_handled($conn, $eventId, $teacherId, 'accepted', $entryId);
if (function_exists('teacher_activity_queue_remove')) teacher_activity_queue_remove($eventId);
unset($_SESSION['pending_accomplishment_event']);

$_SESSION['flash_message'] = 'Added to Monthly Accomplishment (' . substr($eventDate, 0, 7) . '). Proof images attached: ' . (int) $proofSaved . '.';
$_SESSION['flash_type'] = 'success';

acc_evt_json('ok', 'Added to accomplishment.', [
    'handled' => true,
    'status_value' => 'accepted',
    'entry_id' => $entryId,
    'proofs_added' => $proofSaved,
]);
