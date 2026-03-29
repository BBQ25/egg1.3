<?php include '../layouts/session.php'; ?>
<?php require_approved_student(); ?>
<?php include '../layouts/main.php'; ?>

<?php
require_once __DIR__ . '/../includes/attendance_checkin.php';
attendance_checkin_ensure_tables($conn);

if (!function_exists('sa_h')) {
    function sa_h($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

$flash = isset($_SESSION['flash_message']) ? (string) $_SESSION['flash_message'] : '';
$flashType = isset($_SESSION['flash_type']) ? (string) $_SESSION['flash_type'] : 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$student = null;
$studentId = 0;
$studentNo = '';
$studentName = '';

if ($userId > 0) {
    $st = $conn->prepare(
        "SELECT id, StudentNo, Surname, FirstName, MiddleName
         FROM students
         WHERE user_id = ?
         LIMIT 1"
    );
    if ($st) {
        $st->bind_param('i', $userId);
        $st->execute();
        $res = $st->get_result();
        if ($res && $res->num_rows === 1) {
            $student = $res->fetch_assoc();
        }
        $st->close();
    }
}

if (is_array($student)) {
    $studentId = (int) ($student['id'] ?? 0);
    $studentNo = trim((string) ($student['StudentNo'] ?? ''));
    $studentName = trim(
        (string) ($student['Surname'] ?? '') . ', ' .
        (string) ($student['FirstName'] ?? '') . ' ' .
        (string) ($student['MiddleName'] ?? '')
    );
}

$faceProfile = ($studentId > 0 && function_exists('face_profiles_get')) ? face_profiles_get($conn, $studentId) : null;
$faceRegistered = is_array($faceProfile);

$classRows = [];
if ($studentId > 0) {
    $en = $conn->prepare(
        "SELECT DISTINCT
                ce.class_record_id,
                cr.subject_id,
                cr.section,
                cr.academic_year,
                cr.semester,
                cr.year_level,
                s.subject_code,
                s.subject_name,
                s.course
         FROM class_enrollments ce
         JOIN class_records cr ON cr.id = ce.class_record_id
         JOIN subjects s ON s.id = cr.subject_id
         WHERE ce.student_id = ?
           AND ce.status = 'enrolled'
           AND cr.status = 'active'
         ORDER BY cr.academic_year DESC, cr.semester DESC, s.subject_code ASC, s.subject_name ASC"
    );
    if ($en) {
        $en->bind_param('i', $studentId);
        $en->execute();
        $res = $en->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $row['attendance_enabled'] = attendance_class_has_attendance_component($conn, (int) ($row['class_record_id'] ?? 0));
            $classRows[] = $row;
        }
        $en->close();
    }
}

$classMap = [];
foreach ($classRows as $row) {
    $cid = (int) ($row['class_record_id'] ?? 0);
    if ($cid > 0) $classMap[$cid] = $row;
}

$selectedClassId = isset($_GET['class_record_id']) ? (int) $_GET['class_record_id'] : 0;
if (isset($_POST['class_record_id'])) $selectedClassId = (int) $_POST['class_record_id'];
if ($selectedClassId <= 0 || !isset($classMap[$selectedClassId])) {
    $selectedClassId = 0;
    if (count($classRows) > 0) {
        $selectedClassId = (int) ($classRows[0]['class_record_id'] ?? 0);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    $selectedClassId = (int) ($_POST['class_record_id'] ?? $selectedClassId);

    if (!csrf_validate($csrf)) {
        $_SESSION['flash_message'] = 'Security check failed (CSRF). Please refresh and try again.';
        $_SESSION['flash_type'] = 'danger';
    } elseif (!in_array($action, ['submit_attendance', 'submit_attendance_face'], true)) {
        $_SESSION['flash_message'] = 'Invalid request.';
        $_SESSION['flash_type'] = 'danger';
    } elseif (!is_array($student) || $studentId <= 0) {
        $_SESSION['flash_message'] = 'Student profile is not linked to this account.';
        $_SESSION['flash_type'] = 'warning';
    } elseif ($selectedClassId <= 0 || !isset($classMap[$selectedClassId])) {
        $_SESSION['flash_message'] = 'Select a valid class.';
        $_SESSION['flash_type'] = 'warning';
    } else {
        $sessionId = (int) ($_POST['session_id'] ?? 0);
        $session = attendance_checkin_get_session_for_student($conn, $sessionId, $studentId);
        if (!is_array($session) || (int) ($session['class_record_id'] ?? 0) !== $selectedClassId) {
            $_SESSION['flash_message'] = 'Selected attendance session does not belong to the chosen class.';
            $_SESSION['flash_type'] = 'warning';
        } else {
            $geoLocation = attendance_geo_location_from_request_array($_POST);
            if ($action === 'submit_attendance_face') {
                $file = isset($_FILES['face_image']) ? $_FILES['face_image'] : null;
                $descriptor = isset($_POST['face_descriptor']) ? (string) $_POST['face_descriptor'] : '';
                [$ok, $result] = attendance_checkin_submit_face($conn, $studentId, $userId, $sessionId, $file, $descriptor, $geoLocation);
            } else {
                $attendanceCode = trim((string) ($_POST['attendance_code'] ?? ''));
                [$ok, $result] = attendance_checkin_submit_code($conn, $studentId, $userId, $sessionId, $attendanceCode, 'code', $geoLocation);
            }

            if ($ok) {
                $data = is_array($result) ? $result : [];
                $status = strtolower(trim((string) ($data['status'] ?? 'present')));
                $_SESSION['flash_message'] = 'Attendance submitted successfully as ' . $status . '.';
                $_SESSION['flash_type'] = ($status === 'late') ? 'warning' : 'success';
            } else {
                $_SESSION['flash_message'] = (string) $result;
                $_SESSION['flash_type'] = 'warning';
            }
        }
    }

    $redirect = 'student-attendance.php';
    if ($selectedClassId > 0) $redirect .= '?class_record_id=' . $selectedClassId;
    header('Location: ' . $redirect);
    exit;
}

$selectedClass = ($selectedClassId > 0 && isset($classMap[$selectedClassId])) ? $classMap[$selectedClassId] : null;
$selectedAttendanceEnabled = is_array($selectedClass) && !empty($selectedClass['attendance_enabled']);
$sessions = [];
if ($studentId > 0 && $selectedClassId > 0) {
    $sessions = attendance_checkin_get_student_sessions($conn, $studentId, $selectedClassId, 200);
}

$nowTs = time();
$openCodeSessions = [];
$openFaceSessions = [];
$openQrSessions = [];
foreach ($sessions as $row) {
    $submitted = trim((string) ($row['submitted_status'] ?? ''));
    if ($submitted !== '') continue;

    $phase = attendance_checkin_phase($row, $nowTs);
    if ($phase !== 'present_window' && $phase !== 'late_window') continue;

    $m = attendance_checkin_normalize_method((string) ($row['checkin_method'] ?? 'code'));
    if ($m === 'face') {
        $openFaceSessions[] = $row;
    } elseif ($m === 'qr') {
        $openQrSessions[] = $row;
    } else {
        $openCodeSessions[] = $row;
    }
}

$activeSessionId = count($openCodeSessions) > 0 ? (int) ($openCodeSessions[0]['id'] ?? 0) : 0;
$activeFaceSessionId = count($openFaceSessions) > 0 ? (int) ($openFaceSessions[0]['id'] ?? 0) : 0;
?>

<head>
    <title>Attendance Check-In | E-Record</title>
    <?php include '../layouts/title-meta.php'; ?>
    <?php include '../layouts/head-css.php'; ?>
    <style>.sa-code{font-family:monospace;font-size:1rem;letter-spacing:.02em;}</style>
</head>

<body>
<div class="wrapper">
<?php include '../layouts/menu.php'; ?>
<div class="content-page"><div class="content"><div class="container-fluid">

<div class="row"><div class="col-12"><div class="page-title-box">
<div class="page-title-right"><ol class="breadcrumb m-0"><li class="breadcrumb-item"><a href="student-dashboard.php">Student</a></li><li class="breadcrumb-item active">Attendance Check-In</li></ol></div>
<h4 class="page-title">Attendance Check-In</h4>
</div></div></div>

<?php if ($flash !== ''): ?>
<div class="alert alert-<?php echo sa_h($flashType); ?>"><?php echo sa_h($flash); ?></div>
<?php endif; ?>

<?php if (!is_array($student) || $studentId <= 0): ?>
<div class="alert alert-warning">Your student profile is not linked to this account yet. Please contact the administrator.</div>
<?php else: ?>
<div class="card mb-3"><div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2"><div><h5 class="mb-1"><?php echo sa_h($studentName !== '' ? $studentName : 'Student'); ?></h5><p class="text-muted mb-0">Student ID: <strong><?php echo sa_h($studentNo !== '' ? $studentNo : 'N/A'); ?></strong></p></div><span class="badge bg-primary-subtle text-primary">Self Check-In Enabled</span></div></div>

<?php if (count($classRows) === 0): ?>
<div class="alert alert-info">No enrolled class records were found for your account.</div>
<?php else: ?>
<div class="card mb-3"><div class="card-body">
<form method="get" class="row g-2 align-items-end">
<div class="col-lg-9">
<label class="form-label mb-1">Class Record</label>
<select class="form-select" name="class_record_id" required>
<?php foreach ($classRows as $row): ?>
<?php
$cid = (int) ($row['class_record_id'] ?? 0);
$enabled = !empty($row['attendance_enabled']);
$label = trim((string) ($row['subject_code'] ?? ''));
$subjName = trim((string) ($row['subject_name'] ?? ''));
if ($subjName !== '') $label .= ' - ' . $subjName;
$label .= ' | ' . trim((string) ($row['section'] ?? ''));
$label .= ' | ' . trim((string) ($row['academic_year'] ?? '')) . ' ' . trim((string) ($row['semester'] ?? ''));
$label .= $enabled ? ' | Gradebook Sync ON' : ' | Gradebook Sync OFF';
?>
<option value="<?php echo $cid; ?>" <?php echo $cid === $selectedClassId ? 'selected' : ''; ?>><?php echo sa_h($label); ?></option>
<?php endforeach; ?>
</select>
</div>
<div class="col-lg-3"><button class="btn btn-primary" type="submit">Load Class</button></div>
</form>
</div></div>

<?php if (!is_array($selectedClass)): ?>
<div class="alert alert-light border">Select a class to continue.</div>
<?php else: ?>
<?php if ($selectedAttendanceEnabled): ?>
<div class="alert alert-success">Gradebook sync is ON for this class. Your check-in can be recorded to Attendance in Class Record.</div>
<?php else: ?>
<div class="alert alert-info">Gradebook sync is OFF for this class. You can still check in, but Class Record scores are not updated for this class yet.</div>
<?php endif; ?>
<div class="row">
<div class="col-xl-4">
<div class="card"><div class="card-header"><h5 class="mb-0">Check In (Code)</h5></div><div class="card-body">
<?php if (count($openCodeSessions) === 0): ?>
<div class="alert alert-light border mb-0">No active Code/QR attendance session is open right now for this class.</div>
<?php else: ?>
<form method="post" id="sa_code_form">
<input type="hidden" name="csrf_token" value="<?php echo sa_h(csrf_token()); ?>">
<input type="hidden" name="action" value="submit_attendance">
<input type="hidden" name="class_record_id" value="<?php echo (int) $selectedClassId; ?>">
<input type="hidden" name="geo_latitude" value="">
<input type="hidden" name="geo_longitude" value="">
<input type="hidden" name="geo_accuracy_m" value="">
<input type="hidden" name="geo_captured_at" value="">
<div class="mb-2">
<label class="form-label">Active Session</label>
<select class="form-select" name="session_id" required>
<?php foreach ($openCodeSessions as $row): ?>
<?php
$sid = (int) ($row['id'] ?? 0);
$m = attendance_checkin_normalize_method((string) ($row['checkin_method'] ?? 'code'));
$mLabel = $m === 'qr' ? 'QR' : 'Code';
?>
<option value="<?php echo $sid; ?>" <?php echo $sid === $activeSessionId ? 'selected' : ''; ?>>
<?php echo sa_h((string) ($row['session_label'] ?? 'Attendance Session')); ?> (<?php echo sa_h($mLabel); ?>) | <?php echo sa_h((string) ($row['starts_at'] ?? '')); ?> - <?php echo sa_h((string) ($row['late_until'] ?? '')); ?>
</option>
<?php endforeach; ?>
</select>
</div>
<div class="mb-2"><label class="form-label">Attendance Code</label><input type="text" class="form-control sa-code" name="attendance_code" maxlength="64" required></div>
<button type="submit" class="btn btn-primary w-100">Mark Attendance</button>
</form>
<?php endif; ?>
</div></div>

<div class="card mt-3"><div class="card-header"><h5 class="mb-0">Check In (Scan QR)</h5></div><div class="card-body">
<p class="text-muted mb-2">Scan your teacher's QR code to submit attendance instantly.</p>
<a class="btn btn-outline-primary w-100" href="student-attendance-scan.php">Open QR Scanner</a>
<?php if (count($openQrSessions) === 0): ?>
<div class="small text-muted mt-2">No QR-based sessions are currently open for this selected class, but you can still scan a QR from any class.</div>
<?php endif; ?>
</div></div>

<div class="card mt-3"><div class="card-header"><h5 class="mb-0">Check In (Facial)</h5></div><div class="card-body">
 <?php if (count($openFaceSessions) === 0): ?>
 <div class="alert alert-light border mb-0">No active facial check-in session is open right now for this class.</div>
 <?php else: ?>
 <form method="post" enctype="multipart/form-data" id="sa_face_form">
 <input type="hidden" name="csrf_token" value="<?php echo sa_h(csrf_token()); ?>">
 <input type="hidden" name="action" value="submit_attendance_face">
 <input type="hidden" name="class_record_id" value="<?php echo (int) $selectedClassId; ?>">
 <input type="hidden" name="face_descriptor" id="sa_face_descriptor" value="">
 <input type="hidden" name="geo_latitude" value="">
 <input type="hidden" name="geo_longitude" value="">
 <input type="hidden" name="geo_accuracy_m" value="">
 <input type="hidden" name="geo_captured_at" value="">

 <div class="small text-muted mb-2">
   Face registration:
   <?php if ($faceRegistered): ?>
     <span class="fw-semibold text-success">Registered</span>
   <?php else: ?>
     <span class="fw-semibold text-danger">Not registered</span>
   <?php endif; ?>
   <a class="ms-2" href="student-face-register.php">Register / Update</a>
 </div>
 <div class="alert alert-warning py-2 d-none" id="sa_face_verify_notice"></div>

 <div class="mb-2">
 <label class="form-label">Active Session</label>
 <select class="form-select" name="session_id" id="sa_face_session" required>
 <?php foreach ($openFaceSessions as $row): ?>
 <?php
 $sid = (int) ($row['id'] ?? 0);
 $verify = !empty($row['face_verify_required']);
 $thr = isset($row['face_threshold']) ? (float) $row['face_threshold'] : 0.550;
 if ($thr <= 0) $thr = 0.550;
 $label = $verify ? 'Face (Verified)' : 'Face';
 ?>
 <option
   value="<?php echo $sid; ?>"
   data-face-verify="<?php echo $verify ? '1' : '0'; ?>"
   data-face-threshold="<?php echo sa_h(number_format($thr, 3, '.', '')); ?>"
   <?php echo $sid === $activeFaceSessionId ? 'selected' : ''; ?>
 >
 <?php echo sa_h((string) ($row['session_label'] ?? 'Attendance Session')); ?> (<?php echo sa_h($label); ?>) | <?php echo sa_h((string) ($row['starts_at'] ?? '')); ?> - <?php echo sa_h((string) ($row['late_until'] ?? '')); ?>
 </option>
 <?php endforeach; ?>
 </select>
 </div>

 <div class="mb-2">
   <label class="form-label">Camera (Optional)</label>
   <div class="row g-2 align-items-end">
     <div class="col-lg-6">
       <select class="form-select" id="sa_face_camera">
         <option value="">Auto (front camera)</option>
       </select>
       <div class="form-text">Use this if you have a separate/external webcam.</div>
     </div>
     <div class="col-lg-6">
       <div class="d-flex flex-wrap gap-2 justify-content-lg-end">
         <button type="button" class="btn btn-outline-secondary" id="sa_face_refresh">Refresh</button>
         <button type="button" class="btn btn-outline-secondary" id="sa_face_start">Start</button>
         <button type="button" class="btn btn-outline-secondary" id="sa_face_capture" disabled>Capture</button>
         <button type="button" class="btn btn-outline-secondary" id="sa_face_stop" disabled>Stop</button>
       </div>
     </div>
   </div>
   <video id="sa_face_video" class="w-100 mt-2" style="max-height: 260px; background:#000; border-radius: 12px;" playsinline muted></video>
   <canvas id="sa_face_canvas" style="display:none;"></canvas>
   <div class="small text-muted mt-1" id="sa_face_cam_status">Camera is idle.</div>
 </div>
 <div class="mb-2">
   <label class="form-label">Selfie (Face Image)</label>
   <input type="file" class="form-control" name="face_image" id="sa_face_file" accept="image/*" capture="user" required>
   <div class="form-text">JPG/PNG/WEBP, max 5MB.</div>
 </div>
 <button type="submit" class="btn btn-dark w-100">Submit Facial Check-In</button>
 </form>
 <?php endif; ?>
</div></div>
</div>

<div class="col-xl-8">
<div class="card"><div class="card-header d-flex justify-content-between align-items-center"><h5 class="mb-0">Attendance Sessions</h5><span class="badge bg-light text-dark border"><?php echo (int) count($sessions); ?> session(s)</span></div><div class="card-body p-0">
<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0"><thead class="table-light"><tr><th>Session</th><th>Method</th><th>Window</th><th>My Status</th><th>Submitted At</th></tr></thead><tbody>
<?php if (count($sessions) === 0): ?><tr><td colspan="5" class="text-center text-muted py-4">No attendance sessions yet for this class.</td></tr><?php endif; ?>
<?php foreach ($sessions as $row): ?>
<?php
$method = attendance_checkin_normalize_method((string) ($row['checkin_method'] ?? 'code'));
$methodLabel = 'Code';
$methodBadge = 'bg-primary-subtle text-primary';
if ($method === 'qr') { $methodLabel = 'QR'; $methodBadge = 'bg-info-subtle text-info'; }
elseif ($method === 'face') { $methodLabel = 'Face'; $methodBadge = 'bg-dark-subtle text-dark'; }
$submittedStatus = strtolower(trim((string) ($row['submitted_status'] ?? '')));
$submittedAt = trim((string) ($row['submitted_at'] ?? ''));
$phase = attendance_checkin_phase($row, $nowTs);
if ($submittedStatus === 'present') {
    $label = 'Present';
    $badge = 'bg-success-subtle text-success';
} elseif ($submittedStatus === 'late') {
    $label = 'Late';
    $badge = 'bg-warning-subtle text-warning';
} else {
    if ($phase === 'closed') {
        $label = 'Absent';
        $badge = 'bg-danger-subtle text-danger';
    } elseif ($phase === 'upcoming') {
        $label = 'Upcoming';
        $badge = 'bg-info-subtle text-info';
    } else {
        $label = 'Pending';
        $badge = 'bg-secondary-subtle text-secondary';
    }
}
?>
<tr>
<td><div class="fw-semibold"><?php echo sa_h((string) ($row['session_label'] ?? 'Attendance Session')); ?></div><div class="small text-muted"><?php echo sa_h((string) ($row['session_date'] ?? '')); ?></div></td>
<td><span class="badge <?php echo sa_h($methodBadge); ?>"><?php echo sa_h($methodLabel); ?></span></td>
<td class="small"><?php echo sa_h((string) ($row['starts_at'] ?? '')); ?><br>to <?php echo sa_h((string) ($row['present_until'] ?? '')); ?><br>late <?php echo sa_h((string) ($row['late_until'] ?? '')); ?></td>
<td><span class="badge <?php echo sa_h($badge); ?>"><?php echo sa_h($label); ?></span></td>
<td class="small text-muted"><?php echo $submittedAt !== '' ? sa_h($submittedAt) : '-'; ?></td>
</tr>
<?php endforeach; ?>
</tbody></table></div></div></div>
</div>
</div>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>

</div></div>
<?php include '../layouts/footer.php'; ?>
</div></div>

<?php include '../layouts/right-sidebar.php'; ?>
<?php include '../layouts/footer-scripts.php'; ?>
<script src="assets/js/app.min.js"></script>
<script>
(function () {
  "use strict";

  function setHidden(form, name, value) {
    if (!form) return;
    var el = form.querySelector('input[name="' + name + '"]');
    if (!el) return;
    el.value = (value === null || typeof value === "undefined") ? "" : String(value);
  }

  function fillGeo(form, loc) {
    if (!form) return;
    setHidden(form, "geo_latitude", loc && typeof loc.latitude === "number" ? loc.latitude.toFixed(8) : "");
    setHidden(form, "geo_longitude", loc && typeof loc.longitude === "number" ? loc.longitude.toFixed(8) : "");
    setHidden(form, "geo_accuracy_m", loc && typeof loc.accuracy === "number" ? loc.accuracy.toFixed(2) : "");
    setHidden(form, "geo_captured_at", loc && loc.captured_at ? loc.captured_at : "");
  }

  function captureGeo() {
    return new Promise(function (resolve) {
      if (!navigator.geolocation || !navigator.geolocation.getCurrentPosition) {
        return resolve(null);
      }

      navigator.geolocation.getCurrentPosition(
        function (pos) {
          if (!pos || !pos.coords) return resolve(null);
          var out = {
            latitude: Number(pos.coords.latitude),
            longitude: Number(pos.coords.longitude),
            accuracy: Number(pos.coords.accuracy),
            captured_at: new Date().toISOString()
          };
          if (!Number.isFinite(out.latitude) || !Number.isFinite(out.longitude)) return resolve(null);
          if (!Number.isFinite(out.accuracy) || out.accuracy < 0) out.accuracy = null;
          resolve(out);
        },
        function () { resolve(null); },
        {
          enableHighAccuracy: true,
          timeout: 8000,
          maximumAge: 15000
        }
      );
    });
  }

  window.docEaseCaptureGeoForForm = function (form) {
    return captureGeo().then(function (loc) {
      fillGeo(form, loc);
      return loc;
    });
  };

  var codeForm = document.getElementById("sa_code_form");
  if (codeForm) {
    codeForm.addEventListener("submit", function (e) {
      e.preventDefault();
      window.docEaseCaptureGeoForForm(codeForm)
        .then(function () { codeForm.submit(); })
        .catch(function () { codeForm.submit(); });
    });
  }
})();
</script>
<?php if (count($openFaceSessions) > 0): ?>
<script src="assets/vendor/face-api/face-api.min.js"></script>
<script>
(function () {
  "use strict";

  var faceRegistered = <?php echo json_encode((bool) $faceRegistered); ?>;
  var registerUrl = "student-face-register.php";
  var modelUrl = "assets/vendor/face-api/models";

  var form = document.getElementById("sa_face_form");
  var sessionSel = document.getElementById("sa_face_session");
  var fileInput = document.getElementById("sa_face_file");
  var descInput = document.getElementById("sa_face_descriptor");
  var notice = document.getElementById("sa_face_verify_notice");

  var camSel = document.getElementById("sa_face_camera");
  var refreshBtn = document.getElementById("sa_face_refresh");
  var startBtn = document.getElementById("sa_face_start");
  var captureBtn = document.getElementById("sa_face_capture");
  var stopBtn = document.getElementById("sa_face_stop");
  var video = document.getElementById("sa_face_video");
  var canvas = document.getElementById("sa_face_canvas");
  var camStatus = document.getElementById("sa_face_cam_status");

  if (!form || !sessionSel || !fileInput || !descInput || !notice) return;

  var stream = null;
  var modelsReady = false;
  var storageKey = "doc_ease_face_checkin_camera";

  function setNotice(kind, msg) {
    if (!notice) return;
    if (!msg) {
      notice.classList.add("d-none");
      notice.textContent = "";
      return;
    }
    notice.classList.remove("d-none");
    notice.className = "alert py-2 alert-" + kind;
    notice.innerHTML = msg;
  }

  function getActiveOpt() {
    var idx = sessionSel.selectedIndex;
    if (idx < 0) return null;
    return sessionSel.options[idx] || null;
  }

  function isVerifyRequired() {
    var opt = getActiveOpt();
    return !!(opt && String(opt.getAttribute("data-face-verify") || "") === "1");
  }

  function getThreshold() {
    var opt = getActiveOpt();
    var v = opt ? parseFloat(String(opt.getAttribute("data-face-threshold") || "")) : NaN;
    if (!Number.isFinite(v) || v <= 0) v = 0.55;
    return v;
  }

  function syncVerifyNotice() {
    if (!isVerifyRequired()) {
      setNotice("", "");
      return;
    }
    if (!faceRegistered) {
      setNotice("warning", "This session requires face verification, but your account has no face registration yet. <a href=\"" + registerUrl + "\">Register your face</a> first.");
      return;
    }
    setNotice("info", "Face verification is enabled for this session. Your selfie will be checked against your registered face.");
  }

  function setCamStatus(msg) {
    if (camStatus) camStatus.textContent = String(msg || "");
  }

  function stopStream() {
    if (stream) {
      try { stream.getTracks().forEach(function (t) { t.stop(); }); } catch (e) {}
    }
    stream = null;
    if (video) video.srcObject = null;
    if (startBtn) startBtn.disabled = false;
    if (stopBtn) stopBtn.disabled = true;
    if (captureBtn) captureBtn.disabled = true;
    setCamStatus("Camera is idle.");
  }

  function getSelectedDeviceId() {
    var v = String((camSel && camSel.value) || "");
    return v ? v : "";
  }

  function rememberDeviceId(id) {
    try { localStorage.setItem(storageKey, String(id || "")); } catch (e) {}
  }

  function loadRememberedDeviceId() {
    try { return String(localStorage.getItem(storageKey) || ""); } catch (e) { return ""; }
  }

  function refreshCameras() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices || !camSel) return Promise.resolve();
    return navigator.mediaDevices.enumerateDevices().then(function (devices) {
      var cams = (devices || []).filter(function (d) { return d && d.kind === "videoinput"; });
      var keep = getSelectedDeviceId() || loadRememberedDeviceId();
      while (camSel.options.length > 1) camSel.remove(1);
      cams.forEach(function (c, idx) {
        var opt = document.createElement("option");
        opt.value = c.deviceId || "";
        opt.textContent = c.label || ("Camera " + (idx + 1));
        camSel.appendChild(opt);
      });
      if (keep) camSel.value = keep;
    }).catch(function () {});
  }

  function startCamera() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      setCamStatus("Camera access is not supported in this browser.");
      return;
    }
    if (!video || !canvas) return;

    if (startBtn) startBtn.disabled = true;
    if (stopBtn) stopBtn.disabled = false;
    setCamStatus("Starting camera...");

    var deviceId = getSelectedDeviceId();
    var constraints = deviceId
      ? { video: { deviceId: { exact: deviceId } }, audio: false }
      : { video: { facingMode: "user" }, audio: false };

    navigator.mediaDevices.getUserMedia(constraints).then(function (s) {
      stream = s;
      video.srcObject = stream;
      return video.play();
    }).then(function () {
      if (captureBtn) captureBtn.disabled = false;
      setCamStatus("Camera ready. Click Capture to fill the selfie field.");
      rememberDeviceId(deviceId);
      refreshCameras();
    }).catch(function () {
      setCamStatus("Unable to access camera. Check permissions and make sure you are on HTTPS (or localhost).");
      stopStream();
    });
  }

  function captureToFileInput() {
    if (!video || !canvas || !fileInput) return;
    var w = video.videoWidth || 0;
    var h = video.videoHeight || 0;
    if (!w || !h) { setCamStatus("No camera frame yet. Try again."); return; }
    canvas.width = w;
    canvas.height = h;
    var ctx = canvas.getContext("2d", { willReadFrequently: true });
    if (!ctx) { setCamStatus("Canvas not available."); return; }
    ctx.drawImage(video, 0, 0, w, h);

    canvas.toBlob(function (blob) {
      if (!blob) { setCamStatus("Capture failed."); return; }
      var file = new File([blob], "selfie.jpg", { type: "image/jpeg" });
      var dt = new DataTransfer();
      dt.items.add(file);
      fileInput.files = dt.files;
      descInput.value = "";
      setCamStatus("Captured selfie. You can submit now.");
    }, "image/jpeg", 0.92);
  }

  function ensureModels() {
    if (modelsReady) return Promise.resolve(true);
    if (typeof faceapi === "undefined") return Promise.resolve(false);
    return Promise.all([
      faceapi.nets.tinyFaceDetector.loadFromUri(modelUrl),
      faceapi.nets.faceLandmark68Net.loadFromUri(modelUrl),
      faceapi.nets.faceRecognitionNet.loadFromUri(modelUrl)
    ]).then(function () {
      modelsReady = true;
      return true;
    }).catch(function () { return false; });
  }

  function computeDescriptorFromFile(file) {
    if (!file) return Promise.resolve(null);
    if (typeof faceapi === "undefined") return Promise.resolve(null);
    return ensureModels().then(function (ok) {
      if (!ok) return null;
      return faceapi.bufferToImage(file).then(function (img) {
        var opts = new faceapi.TinyFaceDetectorOptions({ inputSize: 416, scoreThreshold: 0.5 });
        return faceapi.detectSingleFace(img, opts).withFaceLandmarks().withFaceDescriptor().then(function (det) {
          if (!det || !det.descriptor) return null;
          return JSON.stringify(Array.from(det.descriptor));
        });
      });
    }).catch(function () { return null; });
  }

  sessionSel.addEventListener("change", function () {
    descInput.value = "";
    syncVerifyNotice();
  });
  fileInput.addEventListener("change", function () { descInput.value = ""; });
  syncVerifyNotice();

  if (camSel) camSel.addEventListener("change", function () { rememberDeviceId(getSelectedDeviceId()); });
  if (refreshBtn) refreshBtn.addEventListener("click", function () {
    setCamStatus("Refreshing camera list...");
    refreshCameras().then(function () { setCamStatus("Camera list updated."); });
  });
  if (startBtn) startBtn.addEventListener("click", startCamera);
  if (stopBtn) stopBtn.addEventListener("click", stopStream);
  if (captureBtn) captureBtn.addEventListener("click", captureToFileInput);

  refreshCameras();

  function submitWithGeo() {
    var done = function () { form.submit(); };
    if (typeof window.docEaseCaptureGeoForForm === "function") {
      window.docEaseCaptureGeoForForm(form).then(done).catch(done);
      return;
    }
    done();
  }

  form.addEventListener("submit", function (e) {
    if (!isVerifyRequired()) {
      e.preventDefault();
      submitWithGeo();
      return;
    }

    if (!faceRegistered) {
      e.preventDefault();
      syncVerifyNotice();
      return;
    }

    if (descInput.value) {
      e.preventDefault();
      submitWithGeo();
      return;
    }

    var f = (fileInput.files && fileInput.files[0]) ? fileInput.files[0] : null;
    if (!f) {
      e.preventDefault();
      submitWithGeo();
      return;
    }

    e.preventDefault();
    setNotice("info", "Verifying face... (threshold: " + getThreshold().toFixed(3) + ")");

    computeDescriptorFromFile(f).then(function (json) {
      if (!json) {
        setNotice("warning", "Unable to detect a face in the selfie. Try again with better lighting.");
        return;
      }
      descInput.value = json;
      submitWithGeo();
    });
  });
})();
</script>
<?php endif; ?>
</body>
</html>
