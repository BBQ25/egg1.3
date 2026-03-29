<?php include '../layouts/session.php'; ?>
<?php require_active_role('teacher'); ?>
<?php include '../layouts/main.php'; ?>

<?php
require_once __DIR__ . '/../includes/attendance_checkin.php';
require_once __DIR__ . '/../includes/teacher_activity_events.php';
attendance_checkin_ensure_tables($conn);
teacher_activity_ensure_tables($conn);

if (!function_exists('tau_h')) {
    function tau_h($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

$flash = isset($_SESSION['flash_message']) ? (string) $_SESSION['flash_message'] : '';
$flashType = isset($_SESSION['flash_type']) ? (string) $_SESSION['flash_type'] : 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

$teacherId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$assigned = [];
if ($teacherId > 0) {
    $stmt = $conn->prepare(
        "SELECT cr.id AS class_record_id,
                cr.section,
                cr.academic_year,
                cr.semester,
                s.subject_code,
                s.subject_name
         FROM teacher_assignments ta
         JOIN class_records cr ON cr.id = ta.class_record_id
         JOIN subjects s ON s.id = cr.subject_id
         WHERE ta.teacher_id = ?
           AND ta.status = 'active'
           AND cr.status = 'active'
         ORDER BY cr.academic_year DESC, cr.semester DESC, s.subject_code ASC, s.subject_name ASC"
    );
    if ($stmt) {
        $stmt->bind_param('i', $teacherId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $row['attendance_enabled'] = attendance_class_has_attendance_component($conn, (int) ($row['class_record_id'] ?? 0));
            $assigned[] = $row;
        }
        $stmt->close();
    }
}

$classMap = [];
foreach ($assigned as $row) {
    $cid = (int) ($row['class_record_id'] ?? 0);
    if ($cid > 0) $classMap[$cid] = $row;
}

$selectedClassId = isset($_GET['class_record_id']) ? (int) $_GET['class_record_id'] : 0;
if (isset($_POST['class_record_id'])) $selectedClassId = (int) $_POST['class_record_id'];
if ($selectedClassId <= 0 || !isset($classMap[$selectedClassId])) {
    $selectedClassId = 0;
    if (count($assigned) > 0) {
        $selectedClassId = (int) ($assigned[0]['class_record_id'] ?? 0);
    }
}

$selectedSessionId = isset($_GET['session_id']) ? (int) $_GET['session_id'] : 0;
if (isset($_POST['session_id'])) $selectedSessionId = (int) $_POST['session_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    $selectedClassId = (int) ($_POST['class_record_id'] ?? $selectedClassId);
    $redirectSessionId = 0;

    if (!csrf_validate($csrf)) {
        $_SESSION['flash_message'] = 'Security check failed (CSRF). Please refresh and try again.';
        $_SESSION['flash_type'] = 'danger';
    } elseif ($selectedClassId <= 0 || !isset($classMap[$selectedClassId])) {
        $_SESSION['flash_message'] = 'Select a valid class.';
        $_SESSION['flash_type'] = 'warning';
    } elseif (!attendance_is_teacher_assigned($conn, $teacherId, $selectedClassId)) {
        $_SESSION['flash_message'] = 'You are not assigned to this class.';
        $_SESSION['flash_type'] = 'danger';
    } elseif ($action === 'create_session') {
        $checkinMethod = trim((string) ($_POST['checkin_method'] ?? 'code'));
        $faceVerifyRequired = !empty($_POST['face_verify_required']) ? 1 : 0;
        $faceThreshold = isset($_POST['face_threshold']) ? (float) $_POST['face_threshold'] : 0.550;
        [$ok, $result] = attendance_checkin_create_session(
            $conn,
            $teacherId,
            $selectedClassId,
            trim((string) ($_POST['session_date'] ?? '')),
            trim((string) ($_POST['session_label'] ?? '')),
            trim((string) ($_POST['attendance_code'] ?? '')),
            trim((string) ($_POST['start_time'] ?? '')),
            trim((string) ($_POST['end_time'] ?? '')),
            (int) ($_POST['late_minutes'] ?? 15),
            $checkinMethod,
            $faceVerifyRequired,
            $faceThreshold
        );

        if ($ok) {
            $redirectSessionId = (int) $result;
            $m = attendance_checkin_normalize_method($checkinMethod);
            if ($m === 'qr') {
                $_SESSION['flash_message'] = 'Attendance session created. Show the QR code to your class.';
            } elseif ($m === 'face') {
                $_SESSION['flash_message'] = $faceVerifyRequired
                    ? 'Attendance session created. Students must submit a selfie and pass face verification (registered face required).'
                    : 'Attendance session created. Students must submit a selfie for facial check-in.';
            } else {
                $_SESSION['flash_message'] = 'Attendance session created. Share the code with your class.';
            }
            $_SESSION['flash_type'] = 'success';

            $sessionDate = trim((string) ($_POST['session_date'] ?? ''));
            if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $sessionDate)) $sessionDate = date('Y-m-d');
            $eventTitle = 'Attendance session created';
            $eventId = teacher_activity_create_event(
                $conn,
                $teacherId,
                $selectedClassId,
                'attendance_session_created',
                $sessionDate,
                $eventTitle,
                [
                    'attendance_session_id' => $redirectSessionId,
                    'checkin_method' => $m,
                ]
            );
            if ($eventId > 0) {
                if (function_exists('teacher_activity_queue_add')) {
                    teacher_activity_queue_add($eventId, $eventTitle, $sessionDate);
                } else {
                    $_SESSION['pending_accomplishment_event'] = [
                        'id' => $eventId,
                        'title' => $eventTitle,
                        'date' => $sessionDate,
                    ];
                }
            }
        } else {
            $_SESSION['flash_message'] = (string) $result;
            $_SESSION['flash_type'] = 'warning';
        }
    } elseif ($action === 'close_session') {
        $sessionId = (int) ($_POST['session_id'] ?? 0);
        [$ok, $result] = attendance_checkin_close_session($conn, $teacherId, $sessionId);
        if ($ok) {
            $redirectSessionId = $sessionId;
            $_SESSION['flash_message'] = (string) $result;
            $_SESSION['flash_type'] = 'success';

            $sessionDate = date('Y-m-d');
            $closedSession = attendance_checkin_get_session_for_teacher($conn, $sessionId, $teacherId);
            if (is_array($closedSession)) {
                $d = trim((string) ($closedSession['session_date'] ?? ''));
                if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $d)) $sessionDate = $d;
            }

            $eventTitle = 'Attendance session closed';
            $eventId = teacher_activity_create_event(
                $conn,
                $teacherId,
                $selectedClassId,
                'attendance_session_closed',
                $sessionDate,
                $eventTitle,
                [
                    'attendance_session_id' => $sessionId,
                ]
            );
            if ($eventId > 0) {
                if (function_exists('teacher_activity_queue_add')) {
                    teacher_activity_queue_add($eventId, $eventTitle, $sessionDate);
                } else {
                    $_SESSION['pending_accomplishment_event'] = [
                        'id' => $eventId,
                        'title' => $eventTitle,
                        'date' => $sessionDate,
                    ];
                }
            }
        } else {
            $_SESSION['flash_message'] = (string) $result;
            $_SESSION['flash_type'] = 'warning';
        }
    } elseif ($action === 'edit_session') {
        $sessionId = (int) ($_POST['session_id'] ?? 0);
        $session = ($sessionId > 0) ? attendance_checkin_get_session_for_teacher($conn, $sessionId, $teacherId) : null;
        if (!is_array($session)) {
            $_SESSION['flash_message'] = 'Attendance session not found.';
            $_SESSION['flash_type'] = 'warning';
        } elseif ((int) ($session['class_record_id'] ?? 0) !== (int) $selectedClassId) {
            $_SESSION['flash_message'] = 'This session does not belong to the selected class.';
            $_SESSION['flash_type'] = 'danger';
        } else {
            [$ok, $result] = attendance_checkin_update_session(
                $conn,
                $teacherId,
                $sessionId,
                trim((string) ($_POST['session_date'] ?? '')),
                trim((string) ($_POST['session_label'] ?? '')),
                trim((string) ($_POST['start_time'] ?? '')),
                trim((string) ($_POST['end_time'] ?? '')),
                (int) ($_POST['late_minutes'] ?? 15)
            );
            if ($ok) {
                $redirectSessionId = $sessionId;
                $_SESSION['flash_message'] = (string) $result;
                $_SESSION['flash_type'] = 'success';
            } else {
                $_SESSION['flash_message'] = (string) $result;
                $_SESSION['flash_type'] = 'warning';
            }
        }
    } elseif ($action === 'delete_session') {
        $sessionId = (int) ($_POST['session_id'] ?? 0);
        $session = ($sessionId > 0) ? attendance_checkin_get_session_for_teacher($conn, $sessionId, $teacherId) : null;
        if (!is_array($session)) {
            $_SESSION['flash_message'] = 'Attendance session not found.';
            $_SESSION['flash_type'] = 'warning';
        } elseif ((int) ($session['class_record_id'] ?? 0) !== (int) $selectedClassId) {
            $_SESSION['flash_message'] = 'This session does not belong to the selected class.';
            $_SESSION['flash_type'] = 'danger';
        } else {
            [$ok, $result] = attendance_checkin_delete_session($conn, $teacherId, $sessionId);
            if ($ok) {
                // Don't redirect to a deleted session_id.
                $redirectSessionId = 0;
                $_SESSION['flash_message'] = (string) $result;
                $_SESSION['flash_type'] = 'success';
            } else {
                $_SESSION['flash_message'] = (string) $result;
                $_SESSION['flash_type'] = 'warning';
            }
        }
    } else {
        $_SESSION['flash_message'] = 'Invalid request.';
        $_SESSION['flash_type'] = 'danger';
    }

    $redirect = 'teacher-attendance-uploads.php';
    if ($selectedClassId > 0) $redirect .= '?class_record_id=' . $selectedClassId;
    if ($redirectSessionId > 0) {
        $redirect .= ($selectedClassId > 0 ? '&' : '?') . 'session_id=' . $redirectSessionId;
    }
    header('Location: ' . $redirect);
    exit;
}

$selectedClass = ($selectedClassId > 0 && isset($classMap[$selectedClassId])) ? $classMap[$selectedClassId] : null;
$attendanceEnabled = is_array($selectedClass) && !empty($selectedClass['attendance_enabled']);
$manualEntryHref = '';
if ($selectedClassId > 0) {
    $termKey = 'midterm';
    if (function_exists('attendance_checkin_attendance_components')) {
        $comps = attendance_checkin_attendance_components($conn, $selectedClassId);
        if (count($comps) > 0) {
            $maybe = strtolower(trim((string) ($comps[0]['term_key'] ?? 'midterm')));
            if ($maybe === 'final') $termKey = 'final';
        }
    }
    $manualEntryHref = 'teacher-grading-config.php?class_record_id=' . (int) $selectedClassId .
        '&term=' . rawurlencode($termKey) .
        '&step=3&focus=attendance';
}
$sessions = [];
if ($selectedClassId > 0) {
    $sessions = attendance_checkin_get_teacher_sessions($conn, $teacherId, $selectedClassId, 200);
}
if ($selectedSessionId <= 0 && count($sessions) > 0) {
    $selectedSessionId = (int) ($sessions[0]['id'] ?? 0);
}

[$selectedSession, $roster] = [null, []];
if ($selectedSessionId > 0) {
    [$selectedSession, $roster] = attendance_checkin_get_session_roster($conn, $teacherId, $selectedSessionId);
    if (!is_array($selectedSession)) $selectedSessionId = 0;
}

$nowTs = time();
?>

<head>
    <title>Attendance Check-In | E-Record</title>
    <?php include '../layouts/title-meta.php'; ?>
    <?php include '../layouts/head-css.php'; ?>
    <link rel="stylesheet" href="assets/css/admin-ops-ui.css">
    <style>
      .tau-code { font-family: monospace; font-size: .95rem; }

      .tau-hero {
        background: linear-gradient(140deg, #16243d 0%, #165c86 54%, #0f766e 100%);
      }

      .tau-hero::after {
        content: "";
        position: absolute;
        inset: 0;
        background: repeating-linear-gradient(
          140deg,
          rgba(255, 255, 255, 0.06) 0px,
          rgba(255, 255, 255, 0.06) 1px,
          rgba(255, 255, 255, 0) 10px,
          rgba(255, 255, 255, 0) 20px
        );
        opacity: 0.34;
        pointer-events: none;
      }

      .tau-actions { white-space: nowrap; }
    </style>
</head>

<body>
<div class="wrapper">
<?php include '../layouts/menu.php'; ?>
<div class="content-page"><div class="content"><div class="container-fluid">

<div class="row"><div class="col-12"><div class="page-title-box">
<div class="page-title-right"><ol class="breadcrumb m-0"><li class="breadcrumb-item"><a href="teacher-dashboard.php">Teacher</a></li><li class="breadcrumb-item active">Attendance Check-In</li></ol></div>
<h4 class="page-title">Attendance Check-In</h4>
</div></div></div>

<?php if ($flash !== ''): ?>
<div class="alert alert-<?php echo tau_h($flashType); ?>"><?php echo tau_h($flash); ?></div>
<?php endif; ?>

<div class="ops-hero tau-hero ops-page-shell" data-ops-parallax>
<div class="ops-hero__bg" data-ops-parallax-layer aria-hidden="true"></div>
<div class="ops-hero__content">
<div class="d-flex align-items-end justify-content-between flex-wrap gap-2">
<div>
<div class="ops-hero__kicker">Teacher Workspace</div>
<h1 class="ops-hero__title h3">Attendance Check-In</h1>
<div class="ops-hero__subtitle">Create attendance windows, monitor live submissions, and manage roster status in one place.</div>
</div>
<div class="ops-hero__chips">
<div class="ops-chip"><span>Assigned Classes</span><strong><?php echo (int) count($assigned); ?></strong></div>
<div class="ops-chip"><span>Sessions Loaded</span><strong><?php echo (int) count($sessions); ?></strong></div>
<div class="ops-chip"><span>Selected Session</span><strong><?php echo $selectedSessionId > 0 ? (int) $selectedSessionId : 'None'; ?></strong></div>
</div>
</div>
</div>
</div>

<?php if (count($assigned) === 0): ?>
<div class="alert alert-info">No assigned classes were found.</div>
<?php else: ?>
<div class="card ops-card ops-page-shell"><div class="card-body">
<form method="get" class="row g-2 align-items-end">
<div class="col-lg-9">
<label class="form-label mb-1">Class Record</label>
<select class="form-select" name="class_record_id" required>
<?php foreach ($assigned as $row): ?>
<?php
$cid = (int) ($row['class_record_id'] ?? 0);
$enabled = !empty($row['attendance_enabled']);
$label = trim((string) ($row['subject_code'] ?? ''));
$subjectName = trim((string) ($row['subject_name'] ?? ''));
if ($subjectName !== '') $label .= ' - ' . $subjectName;
$label .= ' | ' . trim((string) ($row['section'] ?? ''));
$label .= ' | ' . trim((string) ($row['academic_year'] ?? '')) . ' ' . trim((string) ($row['semester'] ?? ''));
$label .= $enabled ? ' | Gradebook Sync ON' : ' | Gradebook Sync OFF';
?>
<option value="<?php echo $cid; ?>" <?php echo $cid === $selectedClassId ? 'selected' : ''; ?>><?php echo tau_h($label); ?></option>
<?php endforeach; ?>
</select>
</div>
<div class="col-lg-3"><button class="btn btn-primary" type="submit">Load Class</button></div>
</form>
</div></div>

<?php if (!is_array($selectedClass)): ?>
<div class="alert alert-light border">Select a class to continue.</div>
<?php else: ?>
<?php if ($attendanceEnabled): ?>
<div class="alert alert-success">Gradebook sync is ON for this class. Check-ins can auto-record to Attendance assessments in Class Record.</div>
<?php else: ?>
<div class="alert alert-info">Gradebook sync is OFF for this class. Check-ins still work, but scores are not pushed to Class Record until an Attendance component is configured.</div>
<?php endif; ?>
<div class="row">
<div class="col-xl-4">
<div class="card ops-card ops-page-shell"><div class="card-header"><h5 class="mb-0">Create Attendance Session</h5></div><div class="card-body">
<form method="post">
<input type="hidden" name="csrf_token" value="<?php echo tau_h(csrf_token()); ?>">
<input type="hidden" name="action" value="create_session">
<input type="hidden" name="class_record_id" value="<?php echo (int) $selectedClassId; ?>">
<div class="mb-2">
<label class="form-label">Check-In Method</label>
<select class="form-select" name="checkin_method" id="tau_checkin_method" required>
  <option value="code">Code Entry</option>
  <option value="qr">QR Code (auto-generated)</option>
  <option value="face">Facial Check-In (selfie)</option>
</select>
<div class="form-text">QR and Facial sessions generate a secure code automatically.</div>
</div>
<div class="mb-2" id="tau_face_verify_wrap" style="display:none;">
  <div class="form-check">
    <input class="form-check-input" type="checkbox" id="tau_face_verify_required" name="face_verify_required" value="1">
    <label class="form-check-label" for="tau_face_verify_required">Require face verification (match registered face)</label>
  </div>
  <div class="row g-2 mt-1" id="tau_face_threshold_wrap" style="display:none;">
    <div class="col-6">
      <label class="form-label mb-1">Match Threshold</label>
      <input type="number" class="form-control" name="face_threshold" id="tau_face_threshold" min="0.300" max="0.900" step="0.001" value="0.550">
      <div class="form-text">Lower is stricter. Default: <code>0.550</code></div>
    </div>
    <div class="col-6"></div>
  </div>
  <div class="form-text">Students must have a face registration to submit if enabled.</div>
</div>
<div class="mb-2"><label class="form-label">Session Label (Optional)</label><input type="text" class="form-control" name="session_label" maxlength="120" placeholder="Week 5"></div>
<div class="mb-2"><label class="form-label">Session Date</label><input type="date" class="form-control" name="session_date" value="<?php echo tau_h(date('Y-m-d')); ?>" required></div>
<div class="mb-2" id="tau_code_wrap"><label class="form-label">Attendance Code</label><input type="text" class="form-control tau-code" name="attendance_code" id="tau_attendance_code" maxlength="64" required></div>
<div class="row g-2">
<div class="col-6"><label class="form-label">Start</label><input type="time" class="form-control" name="start_time" value="<?php echo tau_h(date('H:i')); ?>" required></div>
<div class="col-6"><label class="form-label">On-Time Until</label><input type="time" class="form-control" name="end_time" value="<?php echo tau_h(date('H:i', strtotime('+15 minutes'))); ?>" required></div>
</div>
<div class="mt-2"><label class="form-label">Late Extension (minutes)</label><input type="number" class="form-control" name="late_minutes" min="0" max="360" value="15" required></div>
<button type="submit" class="btn btn-primary mt-3">Create Session</button>
</form>
</div></div>
</div>

<div class="col-xl-8">
<div class="card ops-card ops-page-shell"><div class="card-header d-flex justify-content-between align-items-center"><h5 class="mb-0">Sessions</h5><span class="badge bg-light text-dark border"><?php echo (int) count($sessions); ?></span></div><div class="card-body p-0">
<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0 ops-table"><thead class="table-light"><tr><th>Session</th><th>Window</th><th>Method</th><th>Code</th><th>Status</th><th>Counts</th><th>Action</th></tr></thead><tbody>
<?php if (count($sessions) === 0): ?><tr><td colspan="7" class="text-center text-muted py-4">No sessions yet.</td></tr><?php endif; ?>
<?php foreach ($sessions as $row): ?>
<?php
$sid = (int) ($row['id'] ?? 0);
 $method = attendance_checkin_normalize_method((string) ($row['checkin_method'] ?? 'code'));
 $methodLabel = 'Code';
 $methodBadge = 'bg-primary-subtle text-primary';
 if ($method === 'qr') { $methodLabel = 'QR'; $methodBadge = 'bg-info-subtle text-info'; }
 elseif ($method === 'face') {
     $methodLabel = !empty($row['face_verify_required']) ? 'Face+Verify' : 'Face';
     $methodBadge = 'bg-dark-subtle text-dark';
 }
$phase = attendance_checkin_phase($row, $nowTs);
$phaseLabel = attendance_checkin_phase_label($phase);
$phaseClass = 'bg-secondary-subtle text-secondary';
if ($phase === 'present_window') $phaseClass = 'bg-success-subtle text-success';
elseif ($phase === 'late_window') $phaseClass = 'bg-warning-subtle text-warning';
elseif ($phase === 'upcoming') $phaseClass = 'bg-info-subtle text-info';
$total = (int) ($row['total_students'] ?? 0);
$present = (int) ($row['present_count'] ?? 0);
$late = (int) ($row['late_count'] ?? 0);
$remaining = max(0, $total - $present - $late);
$absent = ($phase === 'closed') ? $remaining : 0;
$pending = ($phase === 'closed') ? 0 : $remaining;
?>
<tr>
<td><div class="fw-semibold"><?php echo tau_h((string) ($row['session_label'] ?? 'Attendance Session')); ?></div><div class="small text-muted"><?php echo tau_h((string) ($row['session_date'] ?? '')); ?></div></td>
<td class="small"><?php echo tau_h((string) ($row['starts_at'] ?? '')); ?><br>to <?php echo tau_h((string) ($row['present_until'] ?? '')); ?><br>late <?php echo tau_h((string) ($row['late_until'] ?? '')); ?></td>
<td><span class="badge <?php echo tau_h($methodBadge); ?>"><?php echo tau_h($methodLabel); ?></span></td>
<td><?php if ($method === 'face'): ?><span class="text-muted">-</span><?php else: ?><code class="tau-code"><?php echo tau_h((string) ($row['attendance_code'] ?? '')); ?></code><?php endif; ?></td>
<td><span class="badge <?php echo tau_h($phaseClass); ?>"><?php echo tau_h($phaseLabel); ?></span></td>
<td class="small"><span class="text-success">P:<?php echo $present; ?></span> <span class="text-warning">L:<?php echo $late; ?></span> <span class="text-danger">A:<?php echo $absent; ?></span> <span class="text-muted">Pend:<?php echo $pending; ?></span></td>
<td class="tau-actions">
<span class="ops-actions">
<a class="btn btn-sm btn-outline-primary" href="teacher-attendance-uploads.php?class_record_id=<?php echo (int) $selectedClassId; ?>&session_id=<?php echo (int) $sid; ?>">View</a>
<?php if ($method === 'qr'): ?>
<a class="btn btn-sm btn-outline-info" href="teacher-attendance-qr.php?session_id=<?php echo (int) $sid; ?>" target="_blank" rel="noopener">QR</a>
<?php endif; ?>
<?php
$startTime = '';
$endTime = '';
$startsAtRaw = (string) ($row['starts_at'] ?? '');
$presentUntilRaw = (string) ($row['present_until'] ?? '');
$st = strtotime($startsAtRaw);
$et = strtotime($presentUntilRaw);
if ($st !== false && $st > 0) $startTime = date('H:i', $st);
if ($et !== false && $et > 0) $endTime = date('H:i', $et);
 ?>
 <button
   type="button"
   class="btn btn-sm btn-outline-dark"
   onclick="window.location.href=<?php echo json_encode((string) $manualEntryHref); ?>"
   <?php echo $manualEntryHref === '' ? 'disabled' : ''; ?>
 >Manual Entry</button>
 <button
   type="button"
   class="btn btn-sm btn-outline-secondary tau-edit-btn"
   data-bs-toggle="modal"
   data-bs-target="#tauEditSessionModal"
   data-session-id="<?php echo (int) $sid; ?>"
  data-session-label="<?php echo tau_h((string) ($row['session_label'] ?? '')); ?>"
  data-session-date="<?php echo tau_h((string) ($row['session_date'] ?? '')); ?>"
  data-start-time="<?php echo tau_h($startTime); ?>"
  data-end-time="<?php echo tau_h($endTime); ?>"
  data-late-minutes="<?php echo (int) ($row['late_minutes'] ?? 15); ?>"
  data-checkin-method="<?php echo tau_h($method); ?>"
>Edit</button>
<button
  type="button"
  class="btn btn-sm btn-outline-danger tau-delete-btn"
  data-bs-toggle="modal"
  data-bs-target="#tauDeleteSessionModal"
  data-session-id="<?php echo (int) $sid; ?>"
  data-session-label="<?php echo tau_h((string) ($row['session_label'] ?? '')); ?>"
  data-session-date="<?php echo tau_h((string) ($row['session_date'] ?? '')); ?>"
>Delete</button>
<?php if ($phase !== 'closed'): ?>
<form method="post" class="d-inline">
<input type="hidden" name="csrf_token" value="<?php echo tau_h(csrf_token()); ?>">
<input type="hidden" name="action" value="close_session">
<input type="hidden" name="class_record_id" value="<?php echo (int) $selectedClassId; ?>">
<input type="hidden" name="session_id" value="<?php echo (int) $sid; ?>">
<button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Close this session now?');">Close</button>
</form>
<?php endif; ?>
</span>
</td>
</tr>
<?php endforeach; ?>
</tbody></table></div></div></div>
</div>
</div>

<?php if (is_array($selectedSession)): ?>
<?php $selectedPhase = attendance_checkin_phase($selectedSession, $nowTs); ?>
<?php
$selectedMethod = attendance_checkin_normalize_method((string) ($selectedSession['checkin_method'] ?? 'code'));
$selectedMethodLabel = $selectedMethod === 'qr' ? 'QR' : ($selectedMethod === 'face' ? 'Face' : 'Code');
$selectedMethodBadge = $selectedMethod === 'qr' ? 'bg-info-subtle text-info' : ($selectedMethod === 'face' ? 'bg-dark-subtle text-dark' : 'bg-primary-subtle text-primary');
?>
<div class="card ops-card ops-page-shell"><div class="card-header d-flex justify-content-between align-items-center"><div><h5 class="mb-0"><?php echo tau_h((string) ($selectedSession['session_label'] ?? 'Attendance Session')); ?></h5><div class="small text-muted mt-1"><span class="badge <?php echo tau_h($selectedMethodBadge); ?>"><?php echo tau_h($selectedMethodLabel); ?></span><?php if ($selectedMethod !== 'face'): ?> <span class="ms-2">Code: <code class="tau-code"><?php echo tau_h((string) ($selectedSession['attendance_code'] ?? '')); ?></code></span><?php endif; ?> <span class="ms-2"><?php echo tau_h(attendance_checkin_phase_label($selectedPhase)); ?></span><?php if ($selectedMethod === 'qr'): ?> <a class="ms-2" href="teacher-attendance-qr.php?session_id=<?php echo (int) ($selectedSession['id'] ?? 0); ?>" target="_blank" rel="noopener">Show QR</a><?php endif; ?></div></div><span class="badge bg-light text-dark border"><?php echo (int) count($roster); ?> student(s)</span></div><div class="card-body p-0">
<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0 ops-table"><thead class="table-light"><tr><th>Student</th><th>Student ID</th><th>Method</th><th>Status</th><th>Submitted At</th><th>Location</th></tr></thead><tbody>
 <?php if (count($roster) === 0): ?><tr><td colspan="6" class="text-center text-muted py-4">No enrolled students found.</td></tr><?php endif; ?>
 <?php foreach ($roster as $row): ?>
 <?php
 $submittedStatus = strtolower(trim((string) ($row['submitted_status'] ?? '')));
 $submittedAt = trim((string) ($row['submitted_at'] ?? ''));
 $submittedMethod = strtolower(trim((string) ($row['submission_method'] ?? '')));
 if ($submittedMethod === '') $submittedMethod = '-';
 $facePath = trim((string) ($row['face_image_path'] ?? ''));
 $geoLat = trim((string) ($row['location_latitude'] ?? ''));
 $geoLng = trim((string) ($row['location_longitude'] ?? ''));
 $geoAcc = trim((string) ($row['location_accuracy_m'] ?? ''));
 $geoDist = trim((string) ($row['location_distance_m'] ?? ''));
 $geoWithinRaw = isset($row['location_within_boundary']) ? (string) $row['location_within_boundary'] : '';
 $geoWithin = ($geoWithinRaw === '1') ? 1 : (($geoWithinRaw === '0') ? 0 : null);
 $geoHasPoint = ($geoLat !== '' && $geoLng !== '');
 $geoMapHref = $geoHasPoint ? ('https://www.google.com/maps?q=' . rawurlencode($geoLat . ',' . $geoLng)) : '';
 if ($submittedStatus === 'present') { $label = 'Present'; $badge = 'bg-success-subtle text-success'; }
 elseif ($submittedStatus === 'late') { $label = 'Late'; $badge = 'bg-warning-subtle text-warning'; }
 else {
     if ($selectedPhase === 'closed') { $label = 'Absent'; $badge = 'bg-danger-subtle text-danger'; }
    elseif ($selectedPhase === 'upcoming') { $label = 'Upcoming'; $badge = 'bg-info-subtle text-info'; }
    else { $label = 'Pending'; $badge = 'bg-secondary-subtle text-secondary'; }
 }
 $studentName = trim((string) ($row['surname'] ?? '') . ', ' . (string) ($row['firstname'] ?? '') . ' ' . (string) ($row['middlename'] ?? ''));
 ?>
 <tr>
   <td><?php echo tau_h($studentName); ?></td>
   <td><?php echo tau_h((string) ($row['student_no'] ?? '')); ?></td>
   <td class="small">
     <?php if ($submittedMethod === 'face' && $facePath !== ''): ?>
       <span class="badge bg-dark-subtle text-dark">Face</span>
       <a class="ms-1" href="<?php echo tau_h($facePath); ?>" target="_blank" rel="noopener">Selfie</a>
     <?php elseif ($submittedMethod === 'qr'): ?>
       <span class="badge bg-info-subtle text-info">QR</span>
     <?php elseif ($submittedMethod === 'code'): ?>
       <span class="badge bg-primary-subtle text-primary">Code</span>
     <?php else: ?>
       <span class="text-muted">-</span>
     <?php endif; ?>
   </td>
   <td><span class="badge <?php echo tau_h($badge); ?>"><?php echo tau_h($label); ?></span></td>
   <td class="small text-muted"><?php echo $submittedAt !== '' ? tau_h($submittedAt) : '-'; ?></td>
   <td class="small">
     <?php if ($geoHasPoint): ?>
       <div>
         <a href="<?php echo tau_h($geoMapHref); ?>" target="_blank" rel="noopener"><?php echo tau_h($geoLat . ', ' . $geoLng); ?></a>
       </div>
       <div class="text-muted">
         <?php if ($geoDist !== ''): ?>Dist: <?php echo tau_h($geoDist); ?>m<?php endif; ?>
         <?php if ($geoAcc !== ''): ?><?php echo $geoDist !== '' ? ' | ' : ''; ?>Acc: <?php echo tau_h($geoAcc); ?>m<?php endif; ?>
       </div>
       <?php if ($geoWithin === 1): ?>
         <span class="badge bg-success-subtle text-success">Inside Boundary</span>
       <?php elseif ($geoWithin === 0): ?>
         <span class="badge bg-danger-subtle text-danger">Outside Boundary</span>
       <?php endif; ?>
     <?php else: ?>
       <span class="text-muted">-</span>
     <?php endif; ?>
   </td>
 </tr>
 <?php endforeach; ?>
 </tbody></table></div></div></div>
 <?php endif; ?>
 <?php endif; ?>
 <?php endif; ?>

</div></div>
<?php include '../layouts/footer.php'; ?>
</div></div>

<?php include '../layouts/right-sidebar.php'; ?>
<?php include '../layouts/footer-scripts.php'; ?>
<script src="assets/js/app.min.js"></script>
<script src="assets/js/admin-ops-ui.js"></script>

<!-- Edit Session Modal -->
<div class="modal fade" id="tauEditSessionModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post" id="tauEditSessionForm">
        <div class="modal-header">
          <h5 class="modal-title">Edit Attendance Session</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?php echo tau_h(csrf_token()); ?>">
          <input type="hidden" name="action" value="edit_session">
          <input type="hidden" name="class_record_id" value="<?php echo (int) $selectedClassId; ?>">
          <input type="hidden" name="session_id" id="tau_edit_session_id" value="0">

          <div class="alert alert-warning small mb-3">
            Do not refresh while saving. If this session is linked to Class Record attendance, the related assessment may be updated and re-synced.
          </div>

          <div class="mb-2">
            <label class="form-label">Session Label</label>
            <input type="text" class="form-control" name="session_label" id="tau_edit_session_label" maxlength="120" placeholder="Attendance 2026-02-16">
          </div>
          <div class="mb-2">
            <label class="form-label">Session Date</label>
            <input type="date" class="form-control" name="session_date" id="tau_edit_session_date" required>
          </div>
          <div class="row g-2">
            <div class="col-6">
              <label class="form-label">Start</label>
              <input type="time" class="form-control" name="start_time" id="tau_edit_start_time" required>
            </div>
            <div class="col-6">
              <label class="form-label">On-Time Until</label>
              <input type="time" class="form-control" name="end_time" id="tau_edit_end_time" required>
            </div>
          </div>
          <div class="mt-2">
            <label class="form-label">Late Extension (minutes)</label>
            <input type="number" class="form-control" name="late_minutes" id="tau_edit_late_minutes" min="0" max="360" value="15" required>
            <div class="form-text">Existing submissions will be recalculated as Present/Late based on the new on-time cutoff.</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Delete Session Modal -->
<div class="modal fade" id="tauDeleteSessionModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post" id="tauDeleteSessionForm">
        <div class="modal-header">
          <h5 class="modal-title">Delete Attendance Session</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?php echo tau_h(csrf_token()); ?>">
          <input type="hidden" name="action" value="delete_session">
          <input type="hidden" name="class_record_id" value="<?php echo (int) $selectedClassId; ?>">
          <input type="hidden" name="session_id" id="tau_delete_session_id" value="0">

          <div class="alert alert-danger small mb-2">
            This will hide the session and stop future check-ins. This cannot be undone from the teacher side.
          </div>
          <div class="small text-muted">
            Session: <span class="fw-semibold" id="tau_delete_session_label">-</span><br>
            Date: <span class="fw-semibold" id="tau_delete_session_date">-</span>
          </div>
          <div class="mt-2 small text-muted">
            If the session auto-created a Class Record attendance assessment, it will be set to inactive.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger">Delete Session</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(function () {
  var sel = document.getElementById('tau_checkin_method');
  var wrap = document.getElementById('tau_code_wrap');
  var input = document.getElementById('tau_attendance_code');
  var faceWrap = document.getElementById('tau_face_verify_wrap');
  var faceChk = document.getElementById('tau_face_verify_required');
  var thrWrap = document.getElementById('tau_face_threshold_wrap');
  if (!sel || !wrap || !input) return;

  function sync() {
    var v = String(sel.value || 'code').toLowerCase();
    var needs = (v === 'code');
    wrap.style.display = needs ? '' : 'none';
    input.required = needs;
    if (!needs) input.value = '';

    var isFace = (v === 'face');
    if (faceWrap) faceWrap.style.display = isFace ? '' : 'none';
    if (!isFace) {
      if (faceChk) faceChk.checked = false;
      if (thrWrap) thrWrap.style.display = 'none';
    } else {
      if (thrWrap && faceChk) thrWrap.style.display = faceChk.checked ? '' : 'none';
    }
  }

  sel.addEventListener('change', sync);
  if (faceChk) faceChk.addEventListener('change', sync);
  sync();
})();
</script>

<script>
(function () {
  function q(id) { return document.getElementById(id); }

  var editId = q('tau_edit_session_id');
  var editLabel = q('tau_edit_session_label');
  var editDate = q('tau_edit_session_date');
  var editStart = q('tau_edit_start_time');
  var editEnd = q('tau_edit_end_time');
  var editLate = q('tau_edit_late_minutes');

  document.querySelectorAll('.tau-edit-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (!editId || !editLabel || !editDate || !editStart || !editEnd || !editLate) return;
      editId.value = String(btn.getAttribute('data-session-id') || '0');
      editLabel.value = String(btn.getAttribute('data-session-label') || '');
      editDate.value = String(btn.getAttribute('data-session-date') || '');
      editStart.value = String(btn.getAttribute('data-start-time') || '');
      editEnd.value = String(btn.getAttribute('data-end-time') || '');
      editLate.value = String(btn.getAttribute('data-late-minutes') || '15');
    });
  });

  var delId = q('tau_delete_session_id');
  var delLabel = q('tau_delete_session_label');
  var delDate = q('tau_delete_session_date');
  document.querySelectorAll('.tau-delete-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (!delId || !delLabel || !delDate) return;
      delId.value = String(btn.getAttribute('data-session-id') || '0');
      delLabel.textContent = String(btn.getAttribute('data-session-label') || '-');
      delDate.textContent = String(btn.getAttribute('data-session-date') || '-');
    });
  });
})();
</script>
</body>
</html>
