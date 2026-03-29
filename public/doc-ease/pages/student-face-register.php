<?php include '../layouts/session.php'; ?>
<?php require_approved_student(); ?>
<?php include '../layouts/main.php'; ?>

<?php
require_once __DIR__ . '/../includes/attendance_checkin.php';
require_once __DIR__ . '/../includes/face_profiles.php';
attendance_checkin_ensure_tables($conn);
face_profiles_ensure_tables($conn);

if (!function_exists('sfr_h')) {
    function sfr_h($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$studentId = 0;
if ($userId > 0) {
    $st = $conn->prepare("SELECT id FROM students WHERE user_id = ? LIMIT 1");
    if ($st) {
        $st->bind_param('i', $userId);
        $st->execute();
        $res = $st->get_result();
        if ($res && $res->num_rows === 1) {
            $row = $res->fetch_assoc();
            $studentId = (int) ($row['id'] ?? 0);
        }
        $st->close();
    }
}

$profile = ($studentId > 0) ? face_profiles_get($conn, $studentId) : null;
$hasProfile = is_array($profile);
$profileUpdated = $hasProfile ? trim((string) ($profile['updated_at'] ?? '')) : '';
?>

<head>
    <title>Face Registration | E-Record</title>
    <?php include '../layouts/title-meta.php'; ?>
    <?php include '../layouts/head-css.php'; ?>
    <style>
        .sfr-wrap{max-width:980px;margin:0 auto;}
        .sfr-video{width:100%;max-height:520px;background:#000;border-radius:12px;}
    </style>
</head>

<body>
<div class="wrapper">
<?php include '../layouts/menu.php'; ?>
<div class="content-page"><div class="content"><div class="container-fluid">

<div class="row"><div class="col-12"><div class="page-title-box">
<div class="page-title-right"><ol class="breadcrumb m-0"><li class="breadcrumb-item"><a href="student-dashboard.php">Student</a></li><li class="breadcrumb-item active">Face Registration</li></ol></div>
<h4 class="page-title">Face Registration</h4>
</div></div></div>

<div class="sfr-wrap">
    <div class="alert alert-info">
        Register your face once, then your teacher can enable face verification during facial check-in sessions.
        <div class="small text-muted mt-1">
            Status:
            <?php if ($hasProfile): ?>
                <span class="fw-semibold text-success">Registered</span>
                <?php if ($profileUpdated !== ''): ?> (updated: <?php echo sfr_h($profileUpdated); ?>)<?php endif; ?>
            <?php else: ?>
                <span class="fw-semibold text-danger">Not registered</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-lg-6">
                    <label class="form-label mb-1">Camera</label>
                    <select class="form-select" id="sfr_camera">
                        <option value="">Auto</option>
                    </select>
                    <div class="form-text">If you have an external webcam, select it here.</div>
                </div>
                <div class="col-lg-6">
                    <div class="d-flex gap-2 justify-content-lg-end">
                        <button class="btn btn-outline-secondary" type="button" id="sfr_refresh">Refresh</button>
                        <button class="btn btn-primary" type="button" id="sfr_start">Start Camera</button>
                        <button class="btn btn-outline-secondary" type="button" id="sfr_stop" disabled>Stop</button>
                        <button class="btn btn-dark" type="button" id="sfr_register" disabled>Register Face</button>
                    </div>
                </div>
            </div>

            <div class="mt-3">
                <video id="sfr_video" class="sfr-video" playsinline muted></video>
                <canvas id="sfr_canvas" style="display:none;"></canvas>
            </div>

            <div class="alert alert-secondary mt-3 mb-0" id="sfr_status">Idle.</div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <h5 class="mb-2">Tips</h5>
            <ul class="mb-0">
                <li>Face the camera directly.</li>
                <li>Use good lighting (avoid backlight).</li>
                <li>Remove face coverings during registration.</li>
            </ul>
        </div>
    </div>
</div>

</div></div>
<?php include '../layouts/footer.php'; ?>
</div></div>

<?php include '../layouts/right-sidebar.php'; ?>
<?php include '../layouts/footer-scripts.php'; ?>
<script src="assets/js/app.min.js"></script>
<script src="assets/vendor/face-api/face-api.min.js"></script>
<script>
(function () {
  "use strict";

  var csrf = <?php echo json_encode(csrf_token()); ?>;
  var submitUrl = "includes/face_profile_submit.php";
  var modelUrl = "assets/vendor/face-api/models";

  var video = document.getElementById("sfr_video");
  var canvas = document.getElementById("sfr_canvas");
  var statusEl = document.getElementById("sfr_status");
  var camSel = document.getElementById("sfr_camera");
  var refreshBtn = document.getElementById("sfr_refresh");
  var startBtn = document.getElementById("sfr_start");
  var stopBtn = document.getElementById("sfr_stop");
  var regBtn = document.getElementById("sfr_register");

  var stream = null;
  var modelsReady = false;
  var storageKey = "doc_ease_face_reg_camera";

  function setStatus(type, msg) {
    statusEl.className = "alert mt-3 mb-0 alert-" + type;
    statusEl.textContent = msg;
  }

  function stopStream() {
    if (stream) {
      try { stream.getTracks().forEach(function (t) { t.stop(); }); } catch (e) {}
    }
    stream = null;
    video.srcObject = null;
    stopBtn.disabled = true;
    startBtn.disabled = false;
    regBtn.disabled = true;
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
    if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) return Promise.resolve();
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

  function ensureModels() {
    if (modelsReady) return Promise.resolve(true);
    if (typeof faceapi === "undefined") {
      setStatus("danger", "Face recognition library failed to load.");
      return Promise.resolve(false);
    }
    setStatus("info", "Loading face models...");
    return Promise.all([
      faceapi.nets.tinyFaceDetector.loadFromUri(modelUrl),
      faceapi.nets.faceLandmark68Net.loadFromUri(modelUrl),
      faceapi.nets.faceRecognitionNet.loadFromUri(modelUrl)
    ]).then(function () {
      modelsReady = true;
      setStatus("info", "Models loaded. Start the camera.");
      return true;
    }).catch(function () {
      setStatus("danger", "Unable to load face models. Check the server path: " + modelUrl);
      return false;
    });
  }

  function startCamera() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      setStatus("danger", "Camera access is not supported in this browser.");
      return;
    }

    startBtn.disabled = true;
    stopBtn.disabled = false;
    setStatus("info", "Starting camera...");

    var deviceId = getSelectedDeviceId();
    var constraints = deviceId
      ? { video: { deviceId: { exact: deviceId } }, audio: false }
      : { video: { facingMode: "user" }, audio: false };

    navigator.mediaDevices.getUserMedia(constraints).then(function (s) {
      stream = s;
      video.srcObject = stream;
      return video.play();
    }).then(function () {
      regBtn.disabled = false;
      setStatus("info", "Camera ready. Click Register Face.");
      rememberDeviceId(deviceId);
      return refreshCameras();
    }).catch(function () {
      setStatus("danger", "Unable to access camera. Check permissions and make sure you are on HTTPS (or localhost).");
      stopStream();
    });
  }

  function captureFrame() {
    var w = video.videoWidth || 0;
    var h = video.videoHeight || 0;
    if (!w || !h) return null;
    canvas.width = w;
    canvas.height = h;
    var ctx = canvas.getContext("2d", { willReadFrequently: true });
    if (!ctx) return null;
    ctx.drawImage(video, 0, 0, w, h);
    return { w: w, h: h };
  }

  function canvasToBlob() {
    return new Promise(function (resolve) {
      canvas.toBlob(function (b) { resolve(b || null); }, "image/jpeg", 0.92);
    });
  }

  function registerFace() {
    if (!stream) {
      setStatus("warning", "Start the camera first.");
      return;
    }

    ensureModels().then(function (ok) {
      if (!ok) return;
      var frame = captureFrame();
      if (!frame) {
        setStatus("warning", "No camera frame available yet. Try again.");
        return;
      }

      setStatus("info", "Detecting face...");
      var opts = new faceapi.TinyFaceDetectorOptions({ inputSize: 416, scoreThreshold: 0.5 });

      return faceapi.detectSingleFace(canvas, opts).withFaceLandmarks().withFaceDescriptor().then(function (det) {
        if (!det || !det.descriptor) {
          setStatus("warning", "No face detected. Center your face and try again.");
          return;
        }

        return canvasToBlob().then(function (blob) {
          if (!blob) {
            setStatus("danger", "Unable to capture image.");
            return;
          }

          setStatus("info", "Saving registration...");
          var fd = new FormData();
          fd.append("csrf_token", csrf);
          fd.append("face_descriptor", JSON.stringify(Array.from(det.descriptor)));
          fd.append("face_image", new File([blob], "face_registration.jpg", { type: "image/jpeg" }));

          return fetch(submitUrl, { method: "POST", credentials: "same-origin", body: fd })
            .then(function (res) { return res.json().catch(function () { return null; }).then(function (j) { return { ok: res.ok, json: j }; }); })
            .then(function (wrap) {
              var data = wrap && wrap.json ? wrap.json : null;
              if (!data || typeof data !== "object") {
                setStatus("danger", "Unexpected server response.");
                return;
              }
              if (data.status === "ok") {
                setStatus("success", String(data.message || "Face registered."));
                return;
              }
              setStatus("warning", String(data.message || "Unable to register face."));
            })
            .catch(function () {
              setStatus("danger", "Network error. Please try again.");
            });
        });
      }).catch(function () {
        setStatus("danger", "Face detection failed. Try again.");
      });
    });
  }

  if (camSel) {
    camSel.addEventListener("change", function () { rememberDeviceId(getSelectedDeviceId()); });
  }
  if (refreshBtn) refreshBtn.addEventListener("click", function () {
    setStatus("secondary", "Refreshing camera list...");
    refreshCameras().then(function () { setStatus("secondary", "Camera list updated."); });
  });
  if (startBtn) startBtn.addEventListener("click", startCamera);
  if (stopBtn) stopBtn.addEventListener("click", function () { setStatus("secondary", "Stopped."); stopStream(); });
  if (regBtn) regBtn.addEventListener("click", registerFace);

  refreshCameras();
  ensureModels();
})();
</script>
</body>
</html>

