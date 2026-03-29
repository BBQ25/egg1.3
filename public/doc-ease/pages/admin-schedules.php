<?php include '../layouts/session.php'; ?>
<?php require_role('admin'); ?>

<?php
require_once __DIR__ . '/../includes/schedule.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/subject_colors.php';
ensure_schedule_tables($conn);
ensure_audit_logs_table($conn);

$adminId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$adminIsSuperadmin = current_user_is_superadmin();
$adminCampusId = current_user_campus_id();
if (!$adminIsSuperadmin && $adminCampusId <= 0) {
    deny_access(403, 'Campus admin account has no campus assignment.');
}

$flash = isset($_SESSION['flash_message']) ? (string) $_SESSION['flash_message'] : '';
$flashType = isset($_SESSION['flash_type']) ? (string) $_SESSION['flash_type'] : 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

function h($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

if (!function_exists('admin_schedule_class_accessible')) {
    function admin_schedule_class_accessible(mysqli $conn, $classRecordId, $isSuperadmin, $campusId) {
        $classRecordId = (int) $classRecordId;
        $isSuperadmin = (bool) $isSuperadmin;
        $campusId = (int) $campusId;
        if ($classRecordId <= 0) return false;

        if ($isSuperadmin) {
            $stmt = $conn->prepare("SELECT 1 FROM class_records WHERE id = ? AND status = 'active' LIMIT 1");
            if (!$stmt) return false;
            $stmt->bind_param('i', $classRecordId);
        } else {
            if ($campusId <= 0) return false;
            $stmt = $conn->prepare(
                "SELECT 1
                 FROM class_records cr
                 WHERE cr.id = ?
                   AND cr.status = 'active'
                   AND (
                        EXISTS(
                            SELECT 1
                            FROM teacher_assignments ta
                            JOIN users u_ta ON u_ta.id = ta.teacher_id
                            WHERE ta.class_record_id = cr.id
                              AND ta.status = 'active'
                              AND u_ta.campus_id = ?
                        )
                        OR EXISTS(
                            SELECT 1
                            FROM users u_cr
                            WHERE u_cr.id = cr.teacher_id
                              AND u_cr.campus_id = ?
                        )
                        OR EXISTS(
                            SELECT 1
                            FROM users u_cb
                            WHERE u_cb.id = cr.created_by
                              AND u_cb.campus_id = ?
                        )
                   )
                 LIMIT 1"
            );
            if (!$stmt) return false;
            $stmt->bind_param('iiii', $classRecordId, $campusId, $campusId, $campusId);
        }

        $stmt->execute();
        $res = $stmt->get_result();
        $ok = ($res && $res->num_rows === 1);
        $stmt->close();
        return $ok;
    }
}

// Load class records for dropdown.
$classes = [];
if ($adminIsSuperadmin) {
    $res = $conn->query(
        "SELECT cr.id AS class_record_id, cr.section, cr.academic_year, cr.semester,
                s.subject_code, s.subject_name
         FROM class_records cr
         JOIN subjects s ON s.id = cr.subject_id
         WHERE cr.status = 'active'
         ORDER BY cr.academic_year DESC, cr.semester ASC, s.subject_name ASC, cr.section ASC
         LIMIT 400"
    );
    while ($res && ($r = $res->fetch_assoc())) $classes[] = $r;
} else {
    $res = null;
    $stmt = $conn->prepare(
        "SELECT DISTINCT cr.id AS class_record_id, cr.section, cr.academic_year, cr.semester,
                s.subject_code, s.subject_name
         FROM class_records cr
         JOIN subjects s ON s.id = cr.subject_id
         WHERE cr.status = 'active'
           AND (
                EXISTS(
                    SELECT 1
                    FROM teacher_assignments ta
                    JOIN users u_ta ON u_ta.id = ta.teacher_id
                    WHERE ta.class_record_id = cr.id
                      AND ta.status = 'active'
                      AND u_ta.campus_id = ?
                )
                OR EXISTS(
                    SELECT 1
                    FROM users u_cr
                    WHERE u_cr.id = cr.teacher_id
                      AND u_cr.campus_id = ?
                )
                OR EXISTS(
                    SELECT 1
                    FROM users u_cb
                    WHERE u_cb.id = cr.created_by
                      AND u_cb.campus_id = ?
                )
           )
         ORDER BY cr.academic_year DESC, cr.semester ASC, s.subject_name ASC, cr.section ASC
         LIMIT 400"
    );
    if ($stmt) {
        $stmt->bind_param('iii', $adminCampusId, $adminCampusId, $adminCampusId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($r = $res->fetch_assoc())) $classes[] = $r;
        $stmt->close();
    }
}

$classRecordId = isset($_GET['class_record_id']) ? (int) $_GET['class_record_id'] : 0;
$allowedClassIds = [];
foreach ($classes as $c) {
    $cid = (int) ($c['class_record_id'] ?? 0);
    if ($cid > 0) $allowedClassIds[$cid] = true;
}
if ($classRecordId <= 0 || !isset($allowedClassIds[$classRecordId])) {
    $classRecordId = (count($classes) > 0) ? (int) ($classes[0]['class_record_id'] ?? 0) : 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    if (!csrf_validate($csrf)) {
        $_SESSION['flash_message'] = 'Security check failed (CSRF). Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: admin-schedules.php?class_record_id=' . (int) $classRecordId);
        exit;
    }

    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';
    $classRecordId = isset($_POST['class_record_id']) ? (int) $_POST['class_record_id'] : $classRecordId;
    $classAccessible = admin_schedule_class_accessible($conn, $classRecordId, $adminIsSuperadmin, $adminCampusId);
    if (!$classAccessible) {
        $_SESSION['flash_message'] = 'Class record is not available for your campus scope.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: admin-schedules.php');
        exit;
    }

    if ($action === 'add_slot') {
        $dow = isset($_POST['day_of_week']) ? (int) $_POST['day_of_week'] : -1;
        $st = isset($_POST['start_time']) ? (string) $_POST['start_time'] : '';
        $et = isset($_POST['end_time']) ? (string) $_POST['end_time'] : '';
        $room = isset($_POST['room']) ? trim((string) $_POST['room']) : '';
        $modality = isset($_POST['modality']) ? (string) $_POST['modality'] : 'face_to_face';
        $notes = isset($_POST['notes']) ? trim((string) $_POST['notes']) : '';

        if ($classRecordId <= 0 || $dow < 0 || $dow > 6 || !schedule_time_ok($st) || !schedule_time_ok($et) || $st >= $et) {
            $_SESSION['flash_message'] = 'Invalid schedule slot.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: admin-schedules.php?class_record_id=' . (int) $classRecordId);
            exit;
        }
        if (!in_array($modality, ['face_to_face', 'online', 'hybrid'], true)) $modality = 'face_to_face';

        $stmt = $conn->prepare(
            "INSERT INTO schedule_slots (class_record_id, day_of_week, start_time, end_time, room, modality, notes, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?)"
        );
        if ($stmt) {
            $roomParam = ($room !== '') ? $room : null;
            $notesParam = ($notes !== '') ? $notes : null;
            $stmt->bind_param('iisssssi', $classRecordId, $dow, $st, $et, $roomParam, $modality, $notesParam, $adminId);
            $ok = false;
            try { $ok = $stmt->execute(); } catch (Throwable $e) { $ok = false; }
            $stmt->close();

            if ($ok) {
                schedule_update_class_record_summary($conn, $classRecordId);
                audit_log($conn, 'schedule.slot.created', 'class_record', $classRecordId, null);
                $_SESSION['flash_message'] = 'Schedule slot added.';
                $_SESSION['flash_type'] = 'success';
            } else {
                $_SESSION['flash_message'] = 'Unable to add slot.';
                $_SESSION['flash_type'] = 'danger';
            }
        }

        header('Location: admin-schedules.php?class_record_id=' . (int) $classRecordId);
        exit;
    }

    if ($action === 'deactivate_slot') {
        $slotId = isset($_POST['slot_id']) ? (int) $_POST['slot_id'] : 0;
        if ($classRecordId <= 0 || $slotId <= 0) {
            $_SESSION['flash_message'] = 'Invalid request.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: admin-schedules.php?class_record_id=' . (int) $classRecordId);
            exit;
        }

        $stmt = $conn->prepare("UPDATE schedule_slots SET status = 'inactive' WHERE id = ? AND class_record_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('ii', $slotId, $classRecordId);
            $ok = false;
            try { $ok = $stmt->execute(); } catch (Throwable $e) { $ok = false; }
            $stmt->close();
            if ($ok) {
                schedule_update_class_record_summary($conn, $classRecordId);
                audit_log($conn, 'schedule.slot.deactivated', 'schedule_slot', $slotId, null);
                $_SESSION['flash_message'] = 'Slot deactivated.';
                $_SESSION['flash_type'] = 'success';
            } else {
                $_SESSION['flash_message'] = 'Unable to deactivate slot.';
                $_SESSION['flash_type'] = 'danger';
            }
        }

        header('Location: admin-schedules.php?class_record_id=' . (int) $classRecordId);
        exit;
    }

    $_SESSION['flash_message'] = 'Invalid request.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: admin-schedules.php?class_record_id=' . (int) $classRecordId);
    exit;
}

$slots = $classRecordId > 0 ? schedule_list_slots_for_class($conn, $classRecordId) : [];

// Calendar events for selected class record (weekly recurring).
$selectedClass = null;
foreach ($classes as $c) {
    if ((int) ($c['class_record_id'] ?? 0) === (int) $classRecordId) { $selectedClass = $c; break; }
}
$subjectCodeForColor = (string) ($selectedClass['subject_code'] ?? '');
$calendarEvents = [];
foreach ($slots as $s) {
    if (((string) ($s['status'] ?? '')) !== 'active') continue;
    $slotId = (int) ($s['slot_id'] ?? 0);
    $dow = (int) ($s['day_of_week'] ?? 0);
    $st = substr((string) ($s['start_time'] ?? ''), 0, 8);
    $et = substr((string) ($s['end_time'] ?? ''), 0, 8);
    $room = (string) ($s['room'] ?? '');
    $modality = (string) ($s['modality'] ?? '');

    if ($slotId <= 0 || $dow < 0 || $dow > 6 || $st === '' || $et === '') continue;

    $title = trim($room !== '' ? $room : $modality);
    if ($title === '') $title = 'Class';

    $ev = [
        'id' => (string) $slotId,
        'title' => $title,
        'daysOfWeek' => [$dow],
        'startTime' => $st,
        'endTime' => $et,
        'startRecur' => '2020-01-01',
        'endRecur' => '2100-01-01',
        'display' => 'block',
        'extendedProps' => [
            'slot_id' => $slotId,
            'day_of_week' => $dow,
            'start_time' => substr((string) ($s['start_time'] ?? ''), 0, 5),
            'end_time' => substr((string) ($s['end_time'] ?? ''), 0, 5),
            'room' => $room,
            'modality' => $modality,
            'notes' => (string) ($s['notes'] ?? ''),
        ],
    ];

    $ev = array_merge($ev, subject_color_event_props($subjectCodeForColor !== '' ? $subjectCodeForColor : 'class'));
    $calendarEvents[] = $ev;
}
?>

<?php include '../layouts/main.php'; ?>

<head>
    <title>Schedules | E-Record</title>
    <?php include '../layouts/title-meta.php'; ?>
    <!-- Fullcalendar css -->
    <link href="assets/vendor/fullcalendar/main.min.css" rel="stylesheet" type="text/css" />
    <?php include '../layouts/head-css.php'; ?>
    <style>
        .subj-chip { display: inline-flex; align-items: center; gap: 8px; padding: 7px 10px; border-radius: 999px; border: 1px solid var(--subj-border, rgba(0,0,0,0.12)); background: var(--subj-bg, #f2f2f7); color: var(--subj-text, #343a40); font-weight: 600; }
        .subj-dot { width: 10px; height: 10px; border-radius: 999px; background: var(--subj-border, #adb5bd); box-shadow: 0 0 0 3px rgba(255,255,255,0.85); }
        #admin-schedule-calendar { min-height: 560px; }

        /* Fix legacy FullCalendar overrides from the theme (improves readability on modern FullCalendar). */
        #admin-schedule-calendar .fc .fc-event,
        #admin-schedule-calendar .fc .fc-event-time,
        #admin-schedule-calendar .fc .fc-event-title {
            color: var(--fc-event-text-color, #0f172a) !important;
        }
        #admin-schedule-calendar .fc .fc-event .fc-event-main,
        #admin-schedule-calendar .fc .fc-event .fc-event-main * {
            color: var(--fc-event-text-color, #0b1220) !important;
        }
        #admin-schedule-calendar .fc .fc-event {
            margin: 0 !important;
            padding: 0 !important;
            text-align: left !important;
            border-width: 1px !important;
            border-style: solid !important;
            border-color: var(--fc-event-border-color, rgba(15, 23, 42, 0.25)) !important;
            cursor: pointer;
        }
        #admin-schedule-calendar .fc .fc-event .fc-event-main,
        #admin-schedule-calendar .fc .fc-event .fc-event-main-frame {
            text-align: left !important;
        }
        #admin-schedule-calendar .fc .fc-timegrid-event .fc-event-main {
            padding: 6px 8px !important;
        }
        #admin-schedule-calendar .fc .fc-timegrid-event .fc-event-time {
            font-weight: 800;
            font-size: 0.82rem;
            margin-bottom: 2px;
        }
        #admin-schedule-calendar .fc .fc-timegrid-event .fc-event-title {
            white-space: normal;
            font-weight: 700;
            line-height: 1.1;
        }

        /* Make prev/next buttons look intentional even if theme CSS changes. */
        #admin-schedule-calendar .fc .fc-prev-button,
        #admin-schedule-calendar .fc .fc-next-button {
            width: 2.75rem;
            padding: 0 !important;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff !important;
        }
        #admin-schedule-calendar .fc .fc-prev-button i,
        #admin-schedule-calendar .fc .fc-next-button i {
            font-size: 1.35rem;
            line-height: 1;
            color: #fff !important;
        }
        #admin-schedule-calendar .fc .fc-next-button {
            /* Make the split between prev/next obvious. */
            border-left: 1px solid rgba(255, 255, 255, 0.28) !important;
        }
    </style>
</head>

<body>
<div class="wrapper">
    <?php include '../layouts/menu.php'; ?>

    <div class="content-page">
        <div class="content">
            <div class="container-fluid">

                <div class="row">
                    <div class="col-12">
                        <div class="page-title-box">
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="admin-dashboard.php">Admin</a></li>
                                    <li class="breadcrumb-item active">Schedules</li>
                                </ol>
                            </div>
                            <h4 class="page-title">Schedules</h4>
                        </div>
                    </div>
                </div>

                <?php if ($flash): ?>
                    <div class="alert alert-<?php echo h($flashType); ?>"><?php echo h($flash); ?></div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-lg-3">
                                <div class="d-grid">
                                    <button class="btn btn-primary" type="button" onclick="document.getElementById('admin-add-slot-form').scrollIntoView({behavior:'smooth',block:'start'});">
                                        <i class="ri-add-line me-1"></i>Add Schedule Slot
                                    </button>
                                </div>

                                <div class="mt-3">
                                    <h4 class="header-title mb-2">Select Class</h4>
                                    <form method="get">
                                        <label class="form-label">Class Record</label>
                                        <select class="form-select" name="class_record_id" onchange="this.form.submit()">
                                            <?php foreach ($classes as $c): ?>
                                                <?php $id = (int) ($c['class_record_id'] ?? 0); ?>
                                                <option value="<?php echo $id; ?>" <?php echo $id === (int) $classRecordId ? 'selected' : ''; ?>>
                                                    <?php echo h(($c['subject_code'] ?? '') . ' | ' . ($c['section'] ?? '') . ' | ' . ($c['academic_year'] ?? '') . ' | ' . ($c['semester'] ?? '')); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>

                                    <?php if ($selectedClass): ?>
                                        <div class="mt-2">
                                            <span class="subj-chip" <?php echo subject_color_style_attr((string) ($selectedClass['subject_code'] ?? '')); ?>>
                                                <span class="subj-dot"></span>
                                                <?php echo h((string) ($selectedClass['subject_code'] ?? '')); ?>
                                                <span class="text-muted">(<?php echo h((string) ($selectedClass['section'] ?? '')); ?>)</span>
                                            </span>
                                            <div class="text-muted small mt-1"><?php echo h((string) ($selectedClass['subject_name'] ?? '')); ?></div>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="mt-3" id="admin-add-slot-form">
                                    <h4 class="header-title mb-2">Add Slot</h4>
                                    <div class="text-muted small">Tip: drag on the calendar to prefill Day/Start/End.</div>
                                    <form method="post" class="mt-2" id="admin-add-slot">
                                        <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                                        <input type="hidden" name="action" value="add_slot">
                                        <input type="hidden" name="class_record_id" value="<?php echo (int) $classRecordId; ?>">

                                        <div class="row g-2">
                                            <div class="col-6">
                                                <label class="form-label">Day</label>
                                                <select class="form-select" name="day_of_week" id="admin-slot-dow" required>
                                                    <?php for ($i = 0; $i <= 6; $i++): ?>
                                                        <option value="<?php echo $i; ?>"><?php echo h(schedule_day_label($i)); ?></option>
                                                    <?php endfor; ?>
                                                </select>
                                            </div>
                                            <div class="col-6">
                                                <label class="form-label">Modality</label>
                                                <select class="form-select" name="modality" id="admin-slot-modality">
                                                    <option value="face_to_face">Face-to-face</option>
                                                    <option value="online">Online</option>
                                                    <option value="hybrid">Hybrid</option>
                                                </select>
                                            </div>
                                            <div class="col-6">
                                                <label class="form-label">Start</label>
                                                <input type="time" class="form-control" name="start_time" id="admin-slot-start" required>
                                            </div>
                                            <div class="col-6">
                                                <label class="form-label">End</label>
                                                <input type="time" class="form-control" name="end_time" id="admin-slot-end" required>
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label">Room</label>
                                                <input type="text" class="form-control" name="room" id="admin-slot-room" maxlength="60" placeholder="e.g. CLab-1 / Rm 101">
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label">Notes</label>
                                                <input type="text" class="form-control" name="notes" maxlength="255" placeholder="Optional">
                                            </div>
                                            <div class="col-12 d-grid">
                                                <button class="btn btn-primary" type="submit">
                                                    <i class="ri-add-line me-1"></i>Add Slot
                                                </button>
                                            </div>
                                        </div>
                                    </form>

                                    <form method="post" id="admin-deactivate-slot" class="d-none">
                                        <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                                        <input type="hidden" name="action" value="deactivate_slot">
                                        <input type="hidden" name="class_record_id" value="<?php echo (int) $classRecordId; ?>">
                                        <input type="hidden" name="slot_id" id="admin-deactivate-slot-id" value="0">
                                    </form>
                                </div>
                            </div>

                            <div class="col-lg-9">
                                <div class="mt-4 mt-lg-0">
                                    <div id="admin-schedule-calendar"></div>
                                </div>

                                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3">
                                    <div>
                                        <h4 class="header-title mb-0">Current Slots</h4>
                                        <div class="text-muted small">Click a slot on the calendar to deactivate it.</div>
                                    </div>
                                    <div class="text-muted small">Total: <strong><?php echo (int) count($slots); ?></strong></div>
                                </div>

                                <div class="table-responsive mt-2">
                                    <table class="table table-sm table-striped table-hover align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>Day</th>
                                                <th>Time</th>
                                                <th>Room</th>
                                                <th>Modality</th>
                                                <th>Notes</th>
                                                <th>Status</th>
                                                <th class="text-end">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (count($slots) === 0): ?>
                                                <tr>
                                                    <td colspan="7" class="text-center text-muted py-4">No schedule slots yet.</td>
                                                </tr>
                                            <?php endif; ?>
                                            <?php foreach ($slots as $s): ?>
                                                <?php
                                                $status = (string) ($s['status'] ?? '');
                                                $st = substr((string) ($s['start_time'] ?? ''), 0, 5);
                                                $et = substr((string) ($s['end_time'] ?? ''), 0, 5);
                                                ?>
                                                <tr>
                                                    <td><?php echo h(schedule_day_label((int) ($s['day_of_week'] ?? 0))); ?></td>
                                                    <td class="text-muted small"><?php echo h($st . '-' . $et); ?></td>
                                                    <td><?php echo h((string) ($s['room'] ?? '') ?: '-'); ?></td>
                                                    <td class="text-muted small"><?php echo h((string) ($s['modality'] ?? '')); ?></td>
                                                    <td class="text-muted small"><?php echo h((string) ($s['notes'] ?? '') ?: '-'); ?></td>
                                                    <td>
                                                        <?php if ($status === 'active'): ?>
                                                            <span class="badge bg-success-subtle text-success">active</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary-subtle text-secondary"><?php echo h($status); ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-end">
                                                        <?php if ($status === 'active'): ?>
                                                            <form method="post" class="d-inline">
                                                                <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                                                                <input type="hidden" name="action" value="deactivate_slot">
                                                                <input type="hidden" name="class_record_id" value="<?php echo (int) $classRecordId; ?>">
                                                                <input type="hidden" name="slot_id" value="<?php echo (int) ($s['slot_id'] ?? 0); ?>">
                                                                <button class="btn btn-sm btn-outline-danger" type="submit" onclick="return confirm('Deactivate this slot?');">
                                                                    <i class="ri-close-line me-1"></i>Deactivate
                                                                </button>
                                                            </form>
                                                        <?php else: ?>
                                                            <span class="text-muted small">-</span>
                                                        <?php endif; ?>
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
window.__ADMIN_SCHEDULE_EVENTS__ = <?php echo json_encode($calendarEvents, JSON_UNESCAPED_SLASHES); ?>;
</script>
<script src="assets/js/pages/admin-schedules.calendar.js"></script>
<script src="assets/js/app.min.js"></script>
</body>
</html>
