<?php include '../layouts/session.php'; ?>
<?php require_active_role('teacher'); ?>

<?php
require_once __DIR__ . '/../includes/schedule.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/subject_colors.php';
ensure_schedule_tables($conn);
ensure_audit_logs_table($conn);

$teacherId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
if ($teacherId <= 0) deny_access(401, 'Unauthorized.');

$flash = isset($_SESSION['flash_message']) ? (string) $_SESSION['flash_message'] : '';
$flashType = isset($_SESSION['flash_type']) ? (string) $_SESSION['flash_type'] : 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

function h($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

$tab = isset($_GET['tab']) ? strtolower(trim((string) $_GET['tab'])) : 'view';
if (!in_array($tab, ['view', 'manage'], true)) $tab = 'view';

$view = isset($_GET['view']) ? strtolower(trim((string) $_GET['view'])) : 'calendar';
if (!in_array($view, ['calendar', 'daily', 'weekly', 'weekly_sun'], true)) $view = 'calendar';

$dateStr = isset($_GET['date']) ? trim((string) $_GET['date']) : '';
if ($dateStr === '' || !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $dateStr)) $dateStr = date('Y-m-d');
try { $selectedDate = new DateTimeImmutable($dateStr); } catch (Throwable $e) { $selectedDate = new DateTimeImmutable('today'); }

$classes = schedule_list_teacher_classes($conn, $teacherId);
$slots = schedule_list_slots_for_teacher($conn, $teacherId);

$editSlotId = isset($_GET['edit_slot_id']) ? (int) $_GET['edit_slot_id'] : 0;
$editSlot = null;
if ($tab === 'manage' && $editSlotId > 0) {
    foreach ($slots as $s) {
        if ((int) ($s['slot_id'] ?? 0) === $editSlotId) { $editSlot = $s; break; }
    }
}

function slot_time_label($s) {
    $st = substr((string) ($s['start_time'] ?? ''), 0, 5);
    $et = substr((string) ($s['end_time'] ?? ''), 0, 5);
    return $st . '-' . $et;
}

function schedule_modality_label($modality) {
    $mod = trim((string) $modality);
    if ($mod === '') return '-';
    return ucwords(str_replace('_', ' ', $mod));
}

function schedule_modality_badge_class($modality) {
    $mod = strtolower(trim((string) $modality));
    if ($mod === 'online') return 'bg-info-subtle text-info border border-info-subtle';
    if ($mod === 'hybrid') return 'bg-warning-subtle text-warning border border-warning-subtle';
    return 'bg-success-subtle text-success border border-success-subtle';
}

function schedule_request_badge_class($action) {
    $act = strtolower(trim((string) $action));
    if ($act === 'delete') return 'bg-danger-subtle text-danger border border-danger-subtle';
    if ($act === 'update') return 'bg-warning-subtle text-warning border border-warning-subtle';
    return 'bg-primary-subtle text-primary border border-primary-subtle';
}

// Slots grouped by day for dashboard.
$slotsByDow = array_fill(0, 7, []);
foreach ($slots as $s) {
    $dow = (int) ($s['day_of_week'] ?? 0);
    if ($dow < 0 || $dow > 6) continue;
    $slotsByDow[$dow][] = $s;
}
for ($i = 0; $i <= 6; $i++) {
    usort($slotsByDow[$i], function ($a, $b) {
        return strcmp((string) ($a['start_time'] ?? ''), (string) ($b['start_time'] ?? ''));
    });
}

$selectedDow = (int) $selectedDate->format('w'); // 0..6

// Calendar events (recurring weekly).
$calendarEvents = [];
foreach ($slots as $s) {
    $code = (string) ($s['subject_code'] ?? '');
    $section = (string) ($s['section'] ?? '');
    $title = trim($code . ($section !== '' ? " ($section)" : ''));
    $dow = (int) ($s['day_of_week'] ?? 0);
    $st = (string) ($s['start_time'] ?? '');
    $et = (string) ($s['end_time'] ?? '');
    $slotId = (int) ($s['slot_id'] ?? 0);

    if ($title === '' || $dow < 0 || $dow > 6 || $st === '' || $et === '') continue;

    $ev = [
        'id' => (string) $slotId,
        'title' => $title,
        'daysOfWeek' => [$dow], // 0=Sun .. 6=Sat (matches FullCalendar)
        'startTime' => substr($st, 0, 8),
        'endTime' => substr($et, 0, 8),
        'startRecur' => '2020-01-01',
        'endRecur' => '2100-01-01',
        'display' => 'block',
        'extendedProps' => [
            'slot_id' => $slotId,
            'class_record_id' => (int) ($s['class_record_id'] ?? 0),
            'subject_code' => $code,
            'subject_name' => (string) ($s['subject_name'] ?? ''),
            'section' => $section,
            'room' => (string) ($s['room'] ?? ''),
            'modality' => (string) ($s['modality'] ?? ''),
            'notes' => (string) ($s['notes'] ?? ''),
        ],
    ];

    $ev = array_merge($ev, subject_color_event_props($code !== '' ? $code : $title));
    $calendarEvents[] = $ev;
}

// Teacher schedule requests (admin-approved).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    if (!csrf_validate($csrf)) {
        $_SESSION['flash_message'] = 'Security check failed (CSRF). Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: teacher-schedule.php?tab=manage');
        exit;
    }

    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

    if ($action === 'cancel_request') {
        $requestId = isset($_POST['request_id']) ? (int) $_POST['request_id'] : 0;
        if ($requestId > 0 && schedule_cancel_change_request($conn, $teacherId, $requestId)) {
            audit_log($conn, 'schedule.request.cancelled', 'schedule_change_request', $requestId, null);
            $_SESSION['flash_message'] = 'Request cancelled.';
            $_SESSION['flash_type'] = 'success';
        } else {
            $_SESSION['flash_message'] = 'Unable to cancel request.';
            $_SESSION['flash_type'] = 'danger';
        }
        header('Location: teacher-schedule.php?tab=manage');
        exit;
    }

    $classRecordId = isset($_POST['class_record_id']) ? (int) $_POST['class_record_id'] : 0;
    if ($classRecordId <= 0 || !schedule_teacher_has_class($conn, $teacherId, $classRecordId)) {
        $_SESSION['flash_message'] = 'Invalid class selection.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: teacher-schedule.php?tab=manage');
        exit;
    }

    if (in_array($action, ['request_create', 'request_update'], true)) {
        $dow = isset($_POST['day_of_week']) ? (int) $_POST['day_of_week'] : -1;
        $st = isset($_POST['start_time']) ? (string) $_POST['start_time'] : '';
        $et = isset($_POST['end_time']) ? (string) $_POST['end_time'] : '';
        $room = isset($_POST['room']) ? trim((string) $_POST['room']) : '';
        $modality = isset($_POST['modality']) ? (string) $_POST['modality'] : 'face_to_face';
        $notes = isset($_POST['notes']) ? trim((string) $_POST['notes']) : '';

        if ($dow < 0 || $dow > 6 || !schedule_time_ok($st) || !schedule_time_ok($et) || $st >= $et) {
            $_SESSION['flash_message'] = 'Invalid schedule slot.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: teacher-schedule.php?tab=manage');
            exit;
        }
        if (!in_array($modality, ['face_to_face', 'online', 'hybrid'], true)) $modality = 'face_to_face';

        $payload = [
            'day_of_week' => $dow,
            'start_time' => $st,
            'end_time' => $et,
            'room' => $room,
            'modality' => $modality,
            'notes' => $notes,
        ];

        if ($action === 'request_create') {
            [$ok, $res] = schedule_create_change_request($conn, $teacherId, $classRecordId, 'create', $payload, null);
        } else {
            $slotId = isset($_POST['slot_id']) ? (int) $_POST['slot_id'] : 0;
            if ($slotId <= 0) {
                $_SESSION['flash_message'] = 'Missing slot.';
                $_SESSION['flash_type'] = 'danger';
                header('Location: teacher-schedule.php?tab=manage');
                exit;
            }
            $okSlot = false;
            $chk = $conn->prepare("SELECT 1 FROM schedule_slots WHERE id = ? AND class_record_id = ? AND status = 'active' LIMIT 1");
            if ($chk) {
                $chk->bind_param('ii', $slotId, $classRecordId);
                $chk->execute();
                $r2 = $chk->get_result();
                $okSlot = ($r2 && $r2->num_rows === 1);
                $chk->close();
            }
            if (!$okSlot) {
                $_SESSION['flash_message'] = 'Slot not found.';
                $_SESSION['flash_type'] = 'danger';
                header('Location: teacher-schedule.php?tab=manage');
                exit;
            }
            [$ok, $res] = schedule_create_change_request($conn, $teacherId, $classRecordId, 'update', $payload, $slotId);
        }

        if ($ok) {
            $id = (int) $res;
            audit_log($conn, 'schedule.requested', 'schedule_change_request', $id, null, ['action' => $action]);
            $_SESSION['flash_message'] = 'Schedule request submitted. Waiting for admin approval.';
            $_SESSION['flash_type'] = 'success';
        } else {
            $_SESSION['flash_message'] = (string) $res;
            $_SESSION['flash_type'] = 'danger';
        }

        header('Location: teacher-schedule.php?tab=manage');
        exit;
    }

    if ($action === 'request_delete') {
        $slotId = isset($_POST['slot_id']) ? (int) $_POST['slot_id'] : 0;
        if ($slotId <= 0) {
            $_SESSION['flash_message'] = 'Missing slot.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: teacher-schedule.php?tab=manage');
            exit;
        }
        $okSlot = false;
        $chk = $conn->prepare("SELECT 1 FROM schedule_slots WHERE id = ? AND class_record_id = ? AND status = 'active' LIMIT 1");
        if ($chk) {
            $chk->bind_param('ii', $slotId, $classRecordId);
            $chk->execute();
            $r2 = $chk->get_result();
            $okSlot = ($r2 && $r2->num_rows === 1);
            $chk->close();
        }
        if (!$okSlot) {
            $_SESSION['flash_message'] = 'Slot not found.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: teacher-schedule.php?tab=manage');
            exit;
        }
        [$ok, $res] = schedule_create_change_request($conn, $teacherId, $classRecordId, 'delete', ['reason' => trim((string) ($_POST['reason'] ?? ''))], $slotId);
        if ($ok) {
            $id = (int) $res;
            audit_log($conn, 'schedule.requested', 'schedule_change_request', $id, null, ['action' => 'delete']);
            $_SESSION['flash_message'] = 'Delete request submitted. Waiting for admin approval.';
            $_SESSION['flash_type'] = 'success';
        } else {
            $_SESSION['flash_message'] = (string) $res;
            $_SESSION['flash_type'] = 'danger';
        }
        header('Location: teacher-schedule.php?tab=manage');
        exit;
    }

    $_SESSION['flash_message'] = 'Invalid request.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: teacher-schedule.php?tab=manage');
    exit;
}

$pendingReqs = [];
$stmt = $conn->prepare(
    "SELECT r.id, r.requested_at, r.action, r.class_record_id, r.slot_id, r.payload_json,
            cr.section, cr.academic_year, cr.semester,
            s.subject_code, s.subject_name
     FROM schedule_change_requests r
     JOIN class_records cr ON cr.id = r.class_record_id
     JOIN subjects s ON s.id = cr.subject_id
     WHERE r.requester_id = ? AND r.status = 'pending'
     ORDER BY r.requested_at DESC, r.id DESC
     LIMIT 50"
);
if ($stmt) {
    $stmt->bind_param('i', $teacherId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) $pendingReqs[] = $row;
    $stmt->close();
}

$classCount = count($classes);
$slotCount = count($slots);
$pendingCount = count($pendingReqs);
?>

<?php include '../layouts/main.php'; ?>

<head>
    <title>Schedule | E-Record</title>
    <?php include '../layouts/title-meta.php'; ?>
    <!-- Fullcalendar css -->
    <link href="assets/vendor/fullcalendar/main.min.css" rel="stylesheet" type="text/css" />
    <?php include '../layouts/head-css.php'; ?>
    <style>
        .teacher-schedule-page {
            --sched-bg: #f1f5fb;
            --sched-panel: #ffffff;
            --sched-border: #d3deee;
            --sched-text: #10233d;
            --sched-muted: #5e6f85;
            --sched-primary: #1f6feb;
            --sched-primary-strong: #1553bc;
            --sched-shadow: 0 14px 34px rgba(16, 35, 61, 0.1);
        }

        .teacher-schedule-page .page-title {
            font-size: 1.35rem;
            font-weight: 700;
            color: var(--sched-text);
            letter-spacing: 0.01em;
        }
        .teacher-schedule-page .header-title {
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--sched-text);
        }
        .teacher-schedule-page .text-muted {
            color: var(--sched-muted) !important;
        }

        .teacher-schedule-page .sched-hero {
            position: relative;
            overflow: hidden;
            border-radius: 18px;
            padding: 22px 24px;
            margin-bottom: 14px;
            background: linear-gradient(120deg, #123b6f 0%, #1f6feb 52%, #12a4ba 100%);
            box-shadow: 0 18px 36px rgba(17, 45, 82, 0.26);
            color: #ffffff;
        }
        .teacher-schedule-page .sched-hero-layer {
            position: absolute;
            inset: -24%;
            pointer-events: none;
            will-change: transform;
        }
        .teacher-schedule-page .sched-hero-layer.layer-a {
            background: radial-gradient(circle at 18% 38%, rgba(255, 255, 255, 0.26) 0%, rgba(255, 255, 255, 0) 55%);
        }
        .teacher-schedule-page .sched-hero-layer.layer-b {
            background: radial-gradient(circle at 84% 18%, rgba(29, 224, 213, 0.28) 0%, rgba(29, 224, 213, 0) 50%);
        }
        .teacher-schedule-page .sched-hero-body {
            position: relative;
            z-index: 1;
        }
        .teacher-schedule-page .sched-hero-kicker {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.76rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            border: 1px solid rgba(255, 255, 255, 0.28);
            background: rgba(255, 255, 255, 0.14);
            border-radius: 999px;
            padding: 5px 10px;
            margin-bottom: 8px;
        }
        .teacher-schedule-page .sched-hero-title {
            margin: 0 0 4px;
            font-size: 1.48rem;
            font-weight: 700;
            letter-spacing: 0.01em;
        }
        .teacher-schedule-page .sched-hero-note {
            margin: 0;
            max-width: 680px;
            color: rgba(255, 255, 255, 0.92);
            font-size: 0.93rem;
        }
        .teacher-schedule-page .sched-hero-stamp {
            text-align: right;
            border: 1px solid rgba(255, 255, 255, 0.26);
            border-radius: 14px;
            background: rgba(4, 18, 48, 0.22);
            padding: 10px 12px;
            min-width: 160px;
        }
        .teacher-schedule-page .sched-hero-stamp .stamp-label {
            font-size: 0.68rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            opacity: 0.8;
        }
        .teacher-schedule-page .sched-hero-stamp .stamp-date {
            font-size: 1rem;
            font-weight: 700;
            line-height: 1.2;
        }

        .teacher-schedule-page .sched-main-card {
            border: 1px solid var(--sched-border);
            border-radius: 16px;
            background: var(--sched-panel);
            box-shadow: var(--sched-shadow);
        }
        .teacher-schedule-page .sched-main-card .card-body {
            padding: 16px;
        }

        .teacher-schedule-page .sched-summary {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            border: 1px solid var(--sched-border);
            border-radius: 14px;
            padding: 12px;
            background: linear-gradient(180deg, #ffffff 0%, #f7faff 100%);
        }
        .teacher-schedule-page .sched-summary .icon {
            width: 38px;
            height: 38px;
            border-radius: 11px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.05rem;
            color: #ffffff;
        }
        .teacher-schedule-page .sched-summary .label {
            font-size: 0.72rem;
            letter-spacing: 0.07em;
            text-transform: uppercase;
            color: var(--sched-muted);
            margin-bottom: 2px;
        }
        .teacher-schedule-page .sched-summary .value {
            font-size: 1.26rem;
            line-height: 1.1;
            font-weight: 700;
            color: var(--sched-text);
        }
        .teacher-schedule-page .sched-summary-classes .icon {
            background: linear-gradient(135deg, #1f6feb, #164793);
        }
        .teacher-schedule-page .sched-summary-slots .icon {
            background: linear-gradient(135deg, #12a4ba, #0d6f8f);
        }
        .teacher-schedule-page .sched-summary-pending .icon {
            background: linear-gradient(135deg, #ff9a3d, #d57414);
        }

        .teacher-schedule-page .sched-tab-nav {
            display: flex;
            gap: 8px;
            background: transparent !important;
            padding: 0;
        }
        .teacher-schedule-page .sched-tab-nav .nav-item {
            flex: 1;
        }
        .teacher-schedule-page .sched-tab-nav .nav-link {
            width: 100%;
            font-size: 0.9rem;
            font-weight: 700;
            border-radius: 12px;
            border: 1px solid var(--sched-border);
            color: #2d4966;
            background: #f7faff;
            padding: 10px 12px;
        }
        .teacher-schedule-page .sched-tab-nav .nav-link.active {
            color: #ffffff;
            border-color: transparent;
            background: linear-gradient(120deg, #1f6feb 0%, #1457c9 100%);
            box-shadow: 0 10px 20px rgba(21, 83, 188, 0.24);
        }

        .teacher-schedule-page .sched-toolbar {
            border: 1px solid var(--sched-border);
            border-radius: 14px;
            padding: 10px;
            background: #f8fbff;
        }
        .teacher-schedule-page .sched-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 7px 12px;
            border-radius: 999px;
            border: 1px solid #bdd0ea;
            color: #2f4b68;
            background: #ffffff;
            font-size: 0.83rem;
            font-weight: 700;
            transition: all 0.2s ease-in-out;
        }
        .teacher-schedule-page .sched-pill:hover {
            color: var(--sched-primary);
            border-color: rgba(31, 111, 235, 0.4);
            background: rgba(31, 111, 235, 0.08);
            transform: translateY(-1px);
        }
        .teacher-schedule-page .sched-pill.active {
            border-color: rgba(31, 111, 235, 0.5);
            color: var(--sched-primary);
            background: rgba(31, 111, 235, 0.12);
        }

        .teacher-schedule-page .sched-date-filter .form-control {
            min-width: 180px;
        }
        .teacher-schedule-page .form-control,
        .teacher-schedule-page .form-select {
            border-color: #c8d6ea;
        }
        .teacher-schedule-page .form-control:focus,
        .teacher-schedule-page .form-select:focus {
            border-color: rgba(31, 111, 235, 0.58);
            box-shadow: 0 0 0 0.2rem rgba(31, 111, 235, 0.14);
        }

        .teacher-schedule-page .sched-sidepanel {
            border: 1px solid var(--sched-border);
            border-radius: 14px;
            padding: 12px;
            background: #f7faff;
            height: 100%;
        }
        .teacher-schedule-page .schedule-calendar-shell {
            border: 1px solid var(--sched-border);
            border-radius: 14px;
            padding: 10px;
            background: #ffffff;
            box-shadow: 0 10px 24px rgba(16, 35, 61, 0.08);
        }
        #teacher-schedule-calendar {
            min-height: 560px;
        }

        .teacher-schedule-page .fc .fc-toolbar-title {
            font-size: 1.04rem;
            font-weight: 700;
            color: var(--sched-text);
        }
        .teacher-schedule-page .fc .fc-button-primary {
            font-size: 0.74rem;
            font-weight: 700;
            letter-spacing: 0.03em;
            text-transform: uppercase;
            background-color: var(--sched-primary);
            border-color: var(--sched-primary);
        }
        .teacher-schedule-page .fc .fc-button-primary:hover,
        .teacher-schedule-page .fc .fc-button-primary:focus,
        .teacher-schedule-page .fc .fc-button-primary:not(:disabled).fc-button-active {
            background-color: var(--sched-primary-strong);
            border-color: var(--sched-primary-strong);
        }
        .teacher-schedule-page .fc .fc-col-header-cell-cushion,
        .teacher-schedule-page .fc .fc-timegrid-axis-cushion {
            font-size: 0.77rem;
            font-weight: 700;
            color: #355170;
        }
        .teacher-schedule-page .fc .fc-timegrid-slot-label-cushion {
            font-size: 0.73rem;
            color: #556d87;
        }
        .teacher-schedule-page .fc .fc-event {
            border: none;
            border-radius: 8px;
            box-shadow: 0 6px 14px rgba(16, 35, 61, 0.15);
            padding: 2px 4px;
        }

        .teacher-schedule-page .sched-item {
            border: 1px solid var(--sched-border);
            border-radius: 12px;
            padding: 12px;
            background: #ffffff;
            box-shadow: 0 6px 14px rgba(16, 35, 61, 0.06);
        }
        .teacher-schedule-page .sched-item .meta {
            font-size: 0.75rem;
            color: var(--sched-muted);
        }

        .teacher-schedule-page .sched-grid td {
            vertical-align: top;
        }
        .teacher-schedule-page .sched-cell {
            min-width: 220px;
        }
        .teacher-schedule-page .sched-badge {
            font-size: 0.64rem;
            letter-spacing: 0.03em;
        }
        .teacher-schedule-page .table thead th {
            border-bottom-color: #d5dfed;
            font-size: 0.74rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #375172;
            font-weight: 700;
        }
        .teacher-schedule-page .sched-grid thead.table-light th {
            background: #edf4fd;
        }

        .teacher-schedule-page .subj-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 10px;
            border-radius: 999px;
            border: 1px solid var(--subj-border, rgba(0, 0, 0, 0.12));
            background: var(--subj-bg, #f2f2f7);
            color: var(--subj-text, #1f3147);
            font-size: 0.78rem;
            font-weight: 700;
        }
        .teacher-schedule-page .subj-dot {
            width: 10px;
            height: 10px;
            border-radius: 999px;
            background: var(--subj-border, #adb5bd);
            box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.88);
        }

        .teacher-schedule-page .calendar-legend .subj-list {
            max-height: 350px;
            overflow: auto;
            padding-right: 2px;
        }
        .teacher-schedule-page .calendar-legend .subj-row {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 8px 10px;
            border-radius: 10px;
            border: 1px solid #d5dfed;
            background: #ffffff;
            transition: transform 0.18s ease, border-color 0.18s ease;
        }
        .teacher-schedule-page .calendar-legend .subj-row:hover {
            border-color: rgba(31, 111, 235, 0.45);
            transform: translateX(2px);
        }
        .teacher-schedule-page .calendar-legend .meta {
            font-size: 0.74rem;
        }

        .teacher-schedule-page .sched-request-item {
            border: 1px solid var(--sched-border);
            border-radius: 12px;
            padding: 10px 12px;
            background: #ffffff;
        }
        .teacher-schedule-page .sched-request-time {
            font-size: 0.74rem;
            color: var(--sched-muted);
        }

        @media (max-width: 991.98px) {
            .teacher-schedule-page .sched-date-filter {
                width: 100%;
            }
            .teacher-schedule-page .sched-date-filter .form-control,
            .teacher-schedule-page .sched-date-filter .btn {
                width: 100%;
            }
            .teacher-schedule-page .sched-tab-nav {
                flex-direction: column;
            }
            #teacher-schedule-calendar {
                min-height: 440px;
            }
            .teacher-schedule-page .sched-cell {
                min-width: 180px;
            }
        }

        @media (max-width: 767.98px) {
            .teacher-schedule-page .sched-hero {
                padding: 18px 16px;
            }
            .teacher-schedule-page .sched-hero-title {
                font-size: 1.24rem;
            }
            .teacher-schedule-page .sched-hero-note {
                font-size: 0.86rem;
            }
            .teacher-schedule-page .sched-hero-stamp {
                text-align: left;
                margin-top: 10px;
                min-width: 0;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .teacher-schedule-page .sched-hero-layer {
                transform: none !important;
            }
        }
    </style>
</head>

<body>
<div class="wrapper">
    <?php include '../layouts/menu.php'; ?>

    <div class="content-page">
        <div class="content">
            <div class="container-fluid teacher-schedule-page">
                <div class="row">
                    <div class="col-12">
                        <div class="page-title-box">
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="teacher-dashboard.php">Teacher</a></li>
                                    <li class="breadcrumb-item active">Schedule</li>
                                </ol>
                            </div>
                            <h4 class="page-title">Schedule</h4>
                        </div>
                    </div>
                </div>

                <?php if ($flash): ?>
                    <div class="alert alert-<?php echo h($flashType); ?>"><?php echo h($flash); ?></div>
                <?php endif; ?>

                <div class="sched-hero" data-parallax-shell>
                    <span class="sched-hero-layer layer-a" data-parallax-layer data-speed="16"></span>
                    <span class="sched-hero-layer layer-b" data-parallax-layer data-speed="30"></span>
                    <div class="sched-hero-body d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            <div class="sched-hero-kicker">
                                <i class="ri-calendar-2-line"></i>Teacher Workspace
                            </div>
                            <h2 class="sched-hero-title">Schedule Planner</h2>
                            <p class="sched-hero-note">Manage class timing, monitor weekly load, and send updates for admin approval in one screen.</p>
                        </div>
                        <div class="sched-hero-stamp">
                            <div class="stamp-label">Selected Date</div>
                            <div class="stamp-date"><?php echo h($selectedDate->format('M d, Y')); ?></div>
                        </div>
                    </div>
                </div>

                <div class="card sched-main-card">
                    <div class="card-body">
                        <div class="row g-2 mb-3">
                            <div class="col-sm-4">
                                <div class="sched-summary sched-summary-classes h-100">
                                    <div class="icon"><i class="ri-book-open-line"></i></div>
                                    <div>
                                        <div class="label">Assigned Classes</div>
                                        <div class="value"><?php echo (int) $classCount; ?></div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <div class="sched-summary sched-summary-slots h-100">
                                    <div class="icon"><i class="ri-time-line"></i></div>
                                    <div>
                                        <div class="label">Active Slots</div>
                                        <div class="value"><?php echo (int) $slotCount; ?></div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <div class="sched-summary sched-summary-pending h-100">
                                    <div class="icon"><i class="ri-hourglass-line"></i></div>
                                    <div>
                                        <div class="label">Pending Requests</div>
                                        <div class="value"><?php echo (int) $pendingCount; ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <ul class="nav nav-pills bg-nav-pills nav-justified mb-3 sched-tab-nav">
                            <li class="nav-item">
                                <a class="nav-link <?php echo $tab === 'view' ? 'active' : ''; ?>"
                                   href="teacher-schedule.php?tab=view&view=<?php echo h($view); ?>&date=<?php echo h($selectedDate->format('Y-m-d')); ?>">
                                    <i class="ri-calendar-event-line me-1"></i>Dashboard
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php echo $tab === 'manage' ? 'active' : ''; ?>"
                                   href="teacher-schedule.php?tab=manage">
                                    <i class="ri-edit-2-line me-1"></i>Manage (Admin Approval)
                                </a>
                            </li>
                        </ul>

                        <?php if ($tab === 'view'): ?>
                            <div class="sched-toolbar d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <div class="d-flex gap-2 flex-wrap">
                                    <a class="sched-pill text-decoration-none <?php echo $view === 'calendar' ? 'active' : ''; ?>"
                                       href="teacher-schedule.php?tab=view&view=calendar&date=<?php echo h($selectedDate->format('Y-m-d')); ?>">Calendar</a>
                                    <a class="sched-pill text-decoration-none <?php echo $view === 'daily' ? 'active' : ''; ?>"
                                       href="teacher-schedule.php?tab=view&view=daily&date=<?php echo h($selectedDate->format('Y-m-d')); ?>">Daily</a>
                                    <a class="sched-pill text-decoration-none <?php echo $view === 'weekly' ? 'active' : ''; ?>"
                                       href="teacher-schedule.php?tab=view&view=weekly&date=<?php echo h($selectedDate->format('Y-m-d')); ?>">Weekly (Mon-Sun)</a>
                                    <a class="sched-pill text-decoration-none <?php echo $view === 'weekly_sun' ? 'active' : ''; ?>"
                                       href="teacher-schedule.php?tab=view&view=weekly_sun&date=<?php echo h($selectedDate->format('Y-m-d')); ?>">Weekly (Sun-Sat)</a>
                                </div>

                                <form method="get" class="d-flex gap-2 align-items-center sched-date-filter">
                                    <input type="hidden" name="tab" value="view">
                                    <input type="hidden" name="view" value="<?php echo h($view); ?>">
                                    <input type="date" class="form-control" name="date" value="<?php echo h($selectedDate->format('Y-m-d')); ?>">
                                    <button class="btn btn-outline-primary" type="submit">Go</button>
                                </form>
                            </div>

                            <?php if (count($slots) === 0): ?>
                                <div class="alert alert-light border mt-3 mb-0">No schedule slots yet. Use the Manage tab to request a schedule, or ask admin to add it.</div>
                            <?php endif; ?>

                            <?php if ($view === 'calendar'): ?>
                                <div class="row mt-3">
                                    <div class="col-lg-3">
                                        <div class="sched-sidepanel">
                                            <div class="d-grid">
                                                <a class="btn btn-primary" href="teacher-schedule.php?tab=manage">
                                                    <i class="ri-add-circle-fill me-1"></i>Request New Slot
                                                </a>
                                            </div>

                                            <div class="mt-3 calendar-legend">
                                                <p class="text-muted mb-2">Subject colors help you identify your classes quickly.</p>
                                                <div class="subj-list">
                                                    <?php foreach ($classes as $c): ?>
                                                        <?php
                                                        $code = (string) ($c['subject_code'] ?? '');
                                                        $name = (string) ($c['subject_name'] ?? '');
                                                        $section = (string) ($c['section'] ?? '');
                                                        ?>
                                                        <div class="subj-row mb-2" <?php echo subject_color_style_attr($code !== '' ? $code : $name); ?>>
                                                            <div class="subj-dot"></div>
                                                            <div>
                                                                <div class="fw-semibold" style="color: var(--subj-text);">
                                                                    <?php echo h($code); ?>
                                                                    <?php if ($section !== ''): ?><span class="text-muted">(<?php echo h($section); ?>)</span><?php endif; ?>
                                                                </div>
                                                                <div class="meta text-muted"><?php echo h($name); ?></div>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>

                                                    <?php if (count($classes) === 0): ?>
                                                        <div class="text-muted">No assigned classes yet.</div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-lg-9">
                                        <div class="schedule-calendar-shell mt-4 mt-lg-0">
                                            <div id="teacher-schedule-calendar"></div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Slot Details MODAL -->
                                <div class="modal fade" id="schedule-slot-modal" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header py-3 px-4 border-bottom-0">
                                                <h5 class="modal-title" id="schedule-slot-modal-title">Schedule Slot</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                            </div>
                                            <div class="modal-body px-4 pb-4 pt-0">
                                                <div class="mb-2">
                                                    <span class="subj-chip" id="schedule-slot-chip"></span>
                                                </div>
                                                <div class="text-muted" id="schedule-slot-subtitle"></div>
                                                <hr class="my-3">
                                                <div class="row g-2 small">
                                                    <div class="col-12"><span class="text-muted">Time:</span> <span id="schedule-slot-time"></span></div>
                                                    <div class="col-12"><span class="text-muted">Room:</span> <span id="schedule-slot-room"></span></div>
                                                    <div class="col-12"><span class="text-muted">Modality:</span> <span id="schedule-slot-modality"></span></div>
                                                    <div class="col-12 d-none" id="schedule-slot-notes-wrap"><span class="text-muted">Notes:</span> <span id="schedule-slot-notes"></span></div>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <a class="btn btn-outline-secondary" id="schedule-slot-components" href="#">Components</a>
                                                <a class="btn btn-outline-secondary" id="schedule-slot-print" href="#">Print</a>
                                                <a class="btn btn-outline-warning" id="schedule-slot-wheel" href="#">Wheel</a>
                                                <a class="btn btn-primary" id="schedule-slot-manage" href="#">Manage</a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <!-- end modal-->

                            <?php elseif ($view === 'daily'): ?>
                                <div class="mt-3">
                                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                                        <div class="fw-semibold">
                                            <?php echo h(schedule_day_label($selectedDow)); ?>, <?php echo h($selectedDate->format('M j, Y')); ?>
                                        </div>
                                        <div class="text-muted small">Classes: <strong><?php echo (int) count($slotsByDow[$selectedDow]); ?></strong></div>
                                    </div>

                                    <?php if (count($slotsByDow[$selectedDow]) === 0): ?>
                                        <div class="text-muted">No classes on this day.</div>
                                    <?php endif; ?>

                                    <div class="row g-2">
                                        <?php foreach ($slotsByDow[$selectedDow] as $s): ?>
                                            <div class="col-12">
                                                <div class="sched-item">
                                                    <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                                                        <div>
                                                            <div class="fw-semibold">
                                                                <span class="subj-chip me-1 align-middle" <?php echo subject_color_style_attr((string) ($s['subject_code'] ?? '')); ?>>
                                                                    <span class="subj-dot"></span><?php echo h((string) ($s['subject_code'] ?? '')); ?>
                                                                </span>
                                                                <span class="text-muted">(<?php echo h((string) ($s['section'] ?? '')); ?>)</span>
                                                                - <?php echo h((string) ($s['subject_name'] ?? '')); ?>
                                                            </div>
                                                            <div class="meta text-muted">
                                                                <?php echo h(slot_time_label($s)); ?>
                                                                <?php if (!empty($s['room'])): ?> | <?php echo h((string) $s['room']); ?><?php endif; ?>
                                                                <?php if (!empty($s['modality'])): ?> | <?php echo h(schedule_modality_label((string) $s['modality'])); ?><?php endif; ?>
                                                            </div>
                                                            <?php if (!empty($s['notes'])): ?>
                                                                <div class="text-muted small mt-1"><?php echo h((string) $s['notes']); ?></div>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="d-flex gap-2">
                                                            <a class="btn btn-sm btn-outline-primary" href="teacher-grading-config.php?class_record_id=<?php echo (int) ($s['class_record_id'] ?? 0); ?>">
                                                                <i class="ri-scales-3-line me-1"></i>Components
                                                            </a>
                                                            <a class="btn btn-sm btn-outline-warning" href="teacher-wheel.php?class_record_id=<?php echo (int) ($s['class_record_id'] ?? 0); ?>">
                                                                <i class="ri-disc-line me-1"></i>Wheel
                                                            </a>
                                                            <a class="btn btn-sm btn-outline-secondary" href="class-record-print.php?class_record_id=<?php echo (int) ($s['class_record_id'] ?? 0); ?>&term=midterm&view=assessments">
                                                                <i class="ri-printer-line me-1"></i>Print
                                                            </a>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <?php
                                $weekStart = $selectedDate;
                                if ($view === 'weekly') {
                                    $iso = (int) $weekStart->format('N'); // 1..7
                                    $weekStart = $weekStart->sub(new DateInterval('P' . ($iso - 1) . 'D'));
                                    $order = [1,2,3,4,5,6,0]; // Mon..Sun
                                } else {
                                    $w = (int) $weekStart->format('w'); // 0..6
                                    $weekStart = $weekStart->sub(new DateInterval('P' . $w . 'D'));
                                    $order = [0,1,2,3,4,5,6]; // Sun..Sat
                                }
                                $weekEnd = $weekStart->add(new DateInterval('P6D'));
                                $prev = $weekStart->sub(new DateInterval('P7D'));
                                $next = $weekStart->add(new DateInterval('P7D'));
                                ?>

                                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3">
                                    <div class="fw-semibold">
                                        Week: <?php echo h($weekStart->format('M j')); ?> - <?php echo h($weekEnd->format('M j, Y')); ?>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <a class="btn btn-sm btn-outline-secondary" href="teacher-schedule.php?tab=view&view=<?php echo h($view); ?>&date=<?php echo h($prev->format('Y-m-d')); ?>">
                                            <i class="ri-arrow-left-line me-1"></i>Prev
                                        </a>
                                        <a class="btn btn-sm btn-outline-secondary" href="teacher-schedule.php?tab=view&view=<?php echo h($view); ?>&date=<?php echo h($next->format('Y-m-d')); ?>">
                                            Next<i class="ri-arrow-right-line ms-1"></i>
                                        </a>
                                    </div>
                                </div>

                                <div class="table-responsive mt-3">
                                    <table class="table table-bordered table-sm sched-grid align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <?php foreach (array_values($order) as $idx => $dow): ?>
                                                    <?php $d = $weekStart->add(new DateInterval('P' . $idx . 'D')); ?>
                                                    <th class="sched-cell">
                                                        <?php echo h(schedule_day_short($dow)); ?>
                                                        <div class="text-muted small"><?php echo h($d->format('M j')); ?></div>
                                                    </th>
                                                <?php endforeach; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <?php foreach ($order as $dow): ?>
                                                    <td class="sched-cell">
                                                        <?php if (count($slotsByDow[$dow]) === 0): ?>
                                                            <div class="text-muted small">-</div>
                                                        <?php endif; ?>
                                                        <?php foreach ($slotsByDow[$dow] as $s): ?>
                                                            <div class="sched-item mb-2">
                                                                <div class="d-flex justify-content-between gap-2">
                                                                    <div class="fw-semibold">
                                                                        <span class="subj-chip me-1 align-middle" <?php echo subject_color_style_attr((string) ($s['subject_code'] ?? '')); ?>>
                                                                            <span class="subj-dot"></span><?php echo h((string) ($s['subject_code'] ?? '')); ?>
                                                                        </span>
                                                                        <span class="text-muted">(<?php echo h((string) ($s['section'] ?? '')); ?>)</span>
                                                                    </div>
                                                                    <span class="badge bg-light text-dark border sched-badge"><?php echo h(slot_time_label($s)); ?></span>
                                                                </div>
                                                                <div class="text-muted small"><?php echo h((string) ($s['subject_name'] ?? '')); ?></div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>

                        <?php else: ?>
                            <div class="row g-3">
                                <div class="col-xl-5">
                                    <div class="card border">
                                        <div class="card-body">
                                            <h4 class="header-title mb-2">Pending Requests</h4>
                                            <div class="text-muted small">You can cancel a pending request before admin reviews it.</div>

                                            <div class="mt-3">
                                                <?php if (count($pendingReqs) === 0): ?>
                                                    <div class="text-muted">No pending schedule requests.</div>
                                                <?php endif; ?>
                                                <?php foreach ($pendingReqs as $r): ?>
                                                    <?php
                                                    $payload = json_decode((string) ($r['payload_json'] ?? ''), true);
                                                    if (!is_array($payload)) $payload = [];
                                                    $action = strtolower((string) ($r['action'] ?? ''));
                                                    if (in_array($action, ['create','update'], true)) {
                                                        $line = schedule_day_short((int) ($payload['day_of_week'] ?? 0))
                                                            . ' ' . substr((string) ($payload['start_time'] ?? ''), 0, 5)
                                                            . '-' . substr((string) ($payload['end_time'] ?? ''), 0, 5);
                                                    } else {
                                                        $line = 'Slot #' . (int) ($r['slot_id'] ?? 0);
                                                    }
                                                    ?>
                                                    <div class="sched-request-item mb-2">
                                                        <div class="d-flex justify-content-between align-items-start gap-2">
                                                            <div>
                                                                <div class="d-flex align-items-center flex-wrap gap-2 mb-1">
                                                                    <span class="badge <?php echo h(schedule_request_badge_class($action)); ?> text-uppercase"><?php echo h($action !== '' ? $action : 'request'); ?></span>
                                                                    <div class="fw-semibold"><?php echo h((string) ($r['subject_code'] ?? '') . ' | ' . (string) ($r['section'] ?? '')); ?></div>
                                                                </div>
                                                                <div class="text-muted small"><?php echo h($line); ?></div>
                                                                <div class="sched-request-time"><?php echo h((string) ($r['requested_at'] ?? '')); ?></div>
                                                            </div>
                                                            <form method="post">
                                                                <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                                                                <input type="hidden" name="action" value="cancel_request">
                                                                <input type="hidden" name="request_id" value="<?php echo (int) ($r['id'] ?? 0); ?>">
                                                                <button class="btn btn-sm btn-outline-danger" type="submit" onclick="return confirm('Cancel this pending request?');">
                                                                    <i class="ri-close-line me-1"></i>Cancel
                                                                </button>
                                                            </form>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="card border">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                                <div>
                                                    <h4 class="header-title mb-0">Request New Slot</h4>
                                                    <div class="text-muted small">Submitted slots require admin approval.</div>
                                                </div>
                                            </div>

                                            <form method="post" class="mt-3">
                                                <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                                                <input type="hidden" name="action" value="request_create">

                                                <div class="mb-2">
                                                    <label class="form-label">Class</label>
                                                    <select class="form-select" name="class_record_id" required>
                                                        <?php foreach ($classes as $c): ?>
                                                            <option value="<?php echo (int) ($c['class_record_id'] ?? 0); ?>">
                                                                <?php echo h(($c['subject_code'] ?? '') . ' | ' . ($c['section'] ?? '') . ' | ' . ($c['academic_year'] ?? '') . ' | ' . ($c['semester'] ?? '')); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>

                                                <div class="row g-2">
                                                    <div class="col-6">
                                                        <label class="form-label">Day</label>
                                                        <select class="form-select" name="day_of_week" required>
                                                            <?php for ($i = 0; $i <= 6; $i++): ?>
                                                                <option value="<?php echo $i; ?>"><?php echo h(schedule_day_label($i)); ?></option>
                                                            <?php endfor; ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-6">
                                                        <label class="form-label">Modality</label>
                                                        <select class="form-select" name="modality">
                                                            <option value="face_to_face">Face-to-face</option>
                                                            <option value="online">Online</option>
                                                            <option value="hybrid">Hybrid</option>
                                                        </select>
                                                    </div>
                                                    <div class="col-6">
                                                        <label class="form-label">Start</label>
                                                        <input type="time" class="form-control" name="start_time" required>
                                                    </div>
                                                    <div class="col-6">
                                                        <label class="form-label">End</label>
                                                        <input type="time" class="form-control" name="end_time" required>
                                                    </div>
                                                    <div class="col-12">
                                                        <label class="form-label">Room</label>
                                                        <input type="text" class="form-control" name="room" maxlength="60" placeholder="e.g. Rm 101">
                                                    </div>
                                                    <div class="col-12">
                                                        <label class="form-label">Notes</label>
                                                        <input type="text" class="form-control" name="notes" maxlength="255" placeholder="Optional">
                                                    </div>
                                                    <div class="col-12 d-grid">
                                                        <button class="btn btn-success" type="submit">
                                                            <i class="ri-send-plane-2-line me-1"></i>Submit For Approval
                                                        </button>
                                                    </div>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-xl-7">
                                    <?php if ($editSlot): ?>
                                        <?php
                                        $st = substr((string) ($editSlot['start_time'] ?? ''), 0, 5);
                                        $et = substr((string) ($editSlot['end_time'] ?? ''), 0, 5);
                                        $m = (string) ($editSlot['modality'] ?? 'face_to_face');
                                        ?>
                                        <div class="card border">
                                            <div class="card-body">
                                                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                                    <div>
                                                        <h4 class="header-title mb-0">Request Update</h4>
                                                        <div class="text-muted small">
                                                            Slot #<?php echo (int) ($editSlot['slot_id'] ?? 0); ?> for
                                                            <?php echo h((string) ($editSlot['subject_code'] ?? '') . ' | ' . (string) ($editSlot['section'] ?? '')); ?>
                                                        </div>
                                                    </div>
                                                    <a class="btn btn-sm btn-outline-secondary" href="teacher-schedule.php?tab=manage">
                                                        Close
                                                    </a>
                                                </div>

                                                <form method="post" class="mt-3">
                                                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                                                    <input type="hidden" name="action" value="request_update">
                                                    <input type="hidden" name="class_record_id" value="<?php echo (int) ($editSlot['class_record_id'] ?? 0); ?>">
                                                    <input type="hidden" name="slot_id" value="<?php echo (int) ($editSlot['slot_id'] ?? 0); ?>">

                                                    <div class="row g-2">
                                                        <div class="col-md-4">
                                                            <label class="form-label">Day</label>
                                                            <select class="form-select" name="day_of_week" required>
                                                                <?php for ($i = 0; $i <= 6; $i++): ?>
                                                                    <option value="<?php echo $i; ?>" <?php echo (int) ($editSlot['day_of_week'] ?? 0) === $i ? 'selected' : ''; ?>>
                                                                        <?php echo h(schedule_day_label($i)); ?>
                                                                    </option>
                                                                <?php endfor; ?>
                                                            </select>
                                                        </div>
                                                        <div class="col-md-2">
                                                            <label class="form-label">Start</label>
                                                            <input type="time" class="form-control" name="start_time" value="<?php echo h($st); ?>" required>
                                                        </div>
                                                        <div class="col-md-2">
                                                            <label class="form-label">End</label>
                                                            <input type="time" class="form-control" name="end_time" value="<?php echo h($et); ?>" required>
                                                        </div>
                                                        <div class="col-md-2">
                                                            <label class="form-label">Room</label>
                                                            <input type="text" class="form-control" name="room" maxlength="60" value="<?php echo h((string) ($editSlot['room'] ?? '')); ?>">
                                                        </div>
                                                        <div class="col-md-2">
                                                            <label class="form-label">Modality</label>
                                                            <select class="form-select" name="modality">
                                                                <option value="face_to_face" <?php echo $m === 'face_to_face' ? 'selected' : ''; ?>>Face-to-face</option>
                                                                <option value="online" <?php echo $m === 'online' ? 'selected' : ''; ?>>Online</option>
                                                                <option value="hybrid" <?php echo $m === 'hybrid' ? 'selected' : ''; ?>>Hybrid</option>
                                                            </select>
                                                        </div>
                                                        <div class="col-12">
                                                            <label class="form-label">Notes</label>
                                                            <input type="text" class="form-control" name="notes" maxlength="255" value="<?php echo h((string) ($editSlot['notes'] ?? '')); ?>">
                                                        </div>
                                                        <div class="col-12 text-end">
                                                            <button class="btn btn-outline-primary" type="submit">
                                                                <i class="ri-send-plane-2-line me-1"></i>Submit Update Request
                                                            </button>
                                                        </div>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <div class="card border">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                                <div>
                                                    <h4 class="header-title mb-0">Current Slots</h4>
                                                    <div class="text-muted small">Edit and delete are request-based (admin approval).</div>
                                                </div>
                                                <div class="text-muted small">Total: <strong><?php echo (int) $slotCount; ?></strong></div>
                                            </div>

                                            <div class="table-responsive mt-3">
                                                <table class="table table-sm table-striped table-hover align-middle mb-0">
                                                    <thead>
                                                        <tr>
                                                            <th>Class</th>
                                                            <th>Day</th>
                                                            <th>Time</th>
                                                            <th>Room</th>
                                                            <th>Modality</th>
                                                            <th class="text-end">Action</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php if ($slotCount === 0): ?>
                                                            <tr>
                                                                <td colspan="6" class="text-center text-muted py-4">No active slots yet.</td>
                                                            </tr>
                                                        <?php endif; ?>
                                                        <?php foreach ($slots as $s): ?>
                                                            <?php $slotId = (int) ($s['slot_id'] ?? 0); ?>
                                                            <tr>
                                                        <td>
                                                            <div class="fw-semibold">
                                                                <span class="subj-chip me-1 align-middle" <?php echo subject_color_style_attr((string) ($s['subject_code'] ?? '')); ?>>
                                                                    <span class="subj-dot"></span><?php echo h((string) ($s['subject_code'] ?? '')); ?>
                                                                </span>
                                                                <span class="text-muted"><?php echo h((string) ($s['section'] ?? '')); ?></span>
                                                            </div>
                                                            <div class="text-muted small"><?php echo h((string) ($s['academic_year'] ?? '') . ' | ' . (string) ($s['semester'] ?? '')); ?></div>
                                                        </td>
                                                                <td><?php echo h(schedule_day_short((int) ($s['day_of_week'] ?? 0))); ?></td>
                                                                <td class="text-muted small"><?php echo h(slot_time_label($s)); ?></td>
                                                                <td><?php echo h((string) ($s['room'] ?? '') ?: '-'); ?></td>
                                                                <td>
                                                                    <?php $mod = (string) ($s['modality'] ?? ''); ?>
                                                                    <?php if ($mod !== ''): ?>
                                                                        <span class="badge <?php echo h(schedule_modality_badge_class($mod)); ?>">
                                                                            <?php echo h(schedule_modality_label($mod)); ?>
                                                                        </span>
                                                                    <?php else: ?>
                                                                        <span class="text-muted small">-</span>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td class="text-end">
                                                                    <a class="btn btn-sm btn-outline-primary" href="teacher-schedule.php?tab=manage&edit_slot_id=<?php echo (int) $slotId; ?>">
                                                                        <i class="ri-edit-2-line me-1"></i>Edit
                                                                    </a>
                                                                    <a class="btn btn-sm btn-outline-warning ms-1" href="teacher-wheel.php?class_record_id=<?php echo (int) ($s['class_record_id'] ?? 0); ?>">
                                                                        <i class="ri-disc-line me-1"></i>Wheel
                                                                    </a>
                                                                    <form method="post" class="d-inline ms-1">
                                                                        <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                                                                        <input type="hidden" name="action" value="request_delete">
                                                                        <input type="hidden" name="class_record_id" value="<?php echo (int) ($s['class_record_id'] ?? 0); ?>">
                                                                        <input type="hidden" name="slot_id" value="<?php echo (int) $slotId; ?>">
                                                                        <button class="btn btn-sm btn-outline-danger" type="submit" onclick="return confirm('Request deletion of this slot?');">
                                                                            <i class="ri-delete-bin-line me-1"></i>Delete
                                                                        </button>
                                                                    </form>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                    </div>
                </div>

            </div>
        </div>

        <?php include '../layouts/footer.php'; ?>
    </div>
</div>

<?php include '../layouts/right-sidebar.php'; ?>
<?php include '../layouts/footer-scripts.php'; ?>
<!-- Fullcalendar js -->
<script src="assets/vendor/fullcalendar/main.min.js"></script>
<script>
window.__TEACHER_SCHEDULE_EVENTS__ = <?php echo json_encode($calendarEvents, JSON_UNESCAPED_SLASHES); ?>;
</script>
<script src="assets/js/pages/teacher-schedule.calendar.js"></script>
<script>
(function () {
    "use strict";

    var shell = document.querySelector("[data-parallax-shell]");
    if (!shell) return;

    if (window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
        return;
    }

    var layers = shell.querySelectorAll("[data-parallax-layer]");
    if (!layers.length) return;

    var ticking = false;
    function applyParallax() {
        var rect = shell.getBoundingClientRect();
        var winH = window.innerHeight || document.documentElement.clientHeight || 0;
        var progress = (winH - rect.top) / (winH + rect.height);
        progress = Math.max(0, Math.min(1, progress));

        layers.forEach(function (layer) {
            var speed = Number(layer.getAttribute("data-speed") || 16);
            var offset = (progress - 0.5) * speed * 2;
            layer.style.transform = "translate3d(0," + offset.toFixed(2) + "px,0)";
        });

        ticking = false;
    }

    function requestTick() {
        if (!ticking) {
            window.requestAnimationFrame(applyParallax);
            ticking = true;
        }
    }

    window.addEventListener("scroll", requestTick, { passive: true });
    window.addEventListener("resize", requestTick);
    requestTick();
})();
</script>
<script src="assets/js/app.min.js"></script>
</body>
</html>
