<?php include '../layouts/session.php'; ?>
<?php require_approved_student(); ?>
<?php include '../layouts/main.php'; ?>

<?php
require_once __DIR__ . '/../includes/attendance_checkin.php';
attendance_checkin_ensure_tables($conn);

if (!function_exists('sas_h')) {
    function sas_h($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
?>

<head>
    <title>Scan Attendance QR | E-Record</title>
    <?php include '../layouts/title-meta.php'; ?>
    <?php include '../layouts/head-css.php'; ?>
    <style>
        .sas-wrap{max-width:980px;margin:0 auto;}
        .sas-video{width:100%;max-height:520px;background:#000;border-radius:12px;}
        .sas-code{font-family:monospace;}
    </style>
</head>

<body>
<div class="wrapper">
<?php include '../layouts/menu.php'; ?>
<div class="content-page"><div class="content"><div class="container-fluid">

<div class="row"><div class="col-12"><div class="page-title-box">
<div class="page-title-right"><ol class="breadcrumb m-0"><li class="breadcrumb-item"><a href="student-dashboard.php">Student</a></li><li class="breadcrumb-item"><a href="student-attendance.php">Attendance</a></li><li class="breadcrumb-item active">Scan QR</li></ol></div>
<h4 class="page-title">Scan Teacher QR Code</h4>
</div></div></div>

<div class="sas-wrap">
    <div class="card mb-3">
        <div class="card-body">
            <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between">
                <div class="text-muted">
                    Point your camera at the QR code shown by your teacher. When detected, attendance will submit automatically.
                </div>
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <select class="form-select form-select-sm" id="sas_camera" style="max-width: 260px;">
                        <option value="">Auto camera</option>
                    </select>
                    <button class="btn btn-outline-secondary" type="button" id="sas_refresh">Refresh</button>
                    <button class="btn btn-primary" type="button" id="sas_start">
                        <i class="ri-qr-scan-2-line me-1" aria-hidden="true"></i>Start Scanner
                    </button>
                    <button class="btn btn-outline-secondary" type="button" id="sas_stop" disabled>Stop</button>
                </div>
            </div>

            <div class="mt-3">
                <video id="sas_video" class="sas-video" playsinline muted></video>
                <canvas id="sas_canvas" style="display:none;"></canvas>
            </div>

            <div class="alert alert-info mt-3 mb-0" id="sas_status">Scanner is idle.</div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <h5 class="mb-2">Manual Fallback</h5>
            <p class="text-muted">If scanning is not available, you can paste the QR payload here (from your teacher) and submit.</p>
            <div class="input-group">
                <input type="text" class="form-control sas-code" id="sas_payload" placeholder="DOC_EASE_ATTENDANCE|123|ABCDEF1234">
                <button class="btn btn-outline-primary" type="button" id="sas_submit_payload">Submit</button>
            </div>
            <div class="form-text">Supported format: <code>DOC_EASE_ATTENDANCE|&lt;session_id&gt;|&lt;code&gt;</code></div>
        </div>
    </div>
</div>

</div></div>
<?php include '../layouts/footer.php'; ?>
</div></div>

<?php include '../layouts/right-sidebar.php'; ?>
<?php include '../layouts/footer-scripts.php'; ?>
<script src="assets/js/app.min.js"></script>
<script src="assets/vendor/jsqr/jsQR.min.js"></script>
<script>
(function () {
  "use strict";

  var csrf = <?php echo json_encode(csrf_token()); ?>;
  var submitUrl = "includes/attendance_qr_submit.php";

  var video = document.getElementById("sas_video");
  var canvas = document.getElementById("sas_canvas");
  var statusEl = document.getElementById("sas_status");
  var startBtn = document.getElementById("sas_start");
  var stopBtn = document.getElementById("sas_stop");
  var camSel = document.getElementById("sas_camera");
  var refreshBtn = document.getElementById("sas_refresh");
  var payloadInput = document.getElementById("sas_payload");
  var submitPayloadBtn = document.getElementById("sas_submit_payload");

  var stream = null;
  var scanning = false;
  var storageKey = "doc_ease_qr_camera";

  function setStatus(type, msg) {
    statusEl.className = "alert mt-3 mb-0 alert-" + type;
    statusEl.textContent = msg;
  }

  function stopStream() {
    scanning = false;
    if (stream) {
      try {
        stream.getTracks().forEach(function (t) { t.stop(); });
      } catch (e) {}
    }
    stream = null;
    video.srcObject = null;
    stopBtn.disabled = true;
    startBtn.disabled = false;
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

  function parsePayload(raw) {
    raw = String(raw || "").trim();
    if (!raw) return null;

    // Primary format: DOC_EASE_ATTENDANCE|<sid>|<code>
    if (raw.indexOf("DOC_EASE_ATTENDANCE|") === 0) {
      var parts = raw.split("|");
      if (parts.length >= 3) {
        var sid = parseInt(parts[1], 10);
        var code = String(parts.slice(2).join("|") || "").trim();
        if (Number.isFinite(sid) && sid > 0 && code) return { session_id: sid, attendance_code: code };
      }
    }

    // Optional JSON format: {"sid":123,"code":"..."}
    if (raw[0] === "{") {
      try {
        var obj = JSON.parse(raw);
        var sid2 = parseInt(obj.sid || obj.session_id || 0, 10);
        var code2 = String(obj.code || obj.attendance_code || "").trim();
        if (Number.isFinite(sid2) && sid2 > 0 && code2) return { session_id: sid2, attendance_code: code2 };
      } catch (e) {}
    }

    return null;
  }

  function captureGeo() {
    return new Promise(function (resolve) {
      if (!navigator.geolocation || !navigator.geolocation.getCurrentPosition) {
        return resolve(null);
      }
      navigator.geolocation.getCurrentPosition(
        function (pos) {
          if (!pos || !pos.coords) return resolve(null);
          var lat = Number(pos.coords.latitude);
          var lng = Number(pos.coords.longitude);
          var acc = Number(pos.coords.accuracy);
          if (!Number.isFinite(lat) || !Number.isFinite(lng)) return resolve(null);
          if (!Number.isFinite(acc) || acc < 0) acc = null;
          resolve({
            geo_latitude: lat.toFixed(8),
            geo_longitude: lng.toFixed(8),
            geo_accuracy_m: (acc === null ? "" : acc.toFixed(2)),
            geo_captured_at: new Date().toISOString()
          });
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

  function submitAttendance(payload) {
    if (!payload || !payload.session_id || !payload.attendance_code) {
      setStatus("warning", "Invalid payload. Ask your teacher for a valid QR code.");
      return Promise.resolve(false);
    }

    setStatus("info", "Submitting attendance...");

    return captureGeo().then(function (geo) {
      var body = {
        csrf_token: csrf,
        session_id: payload.session_id,
        attendance_code: payload.attendance_code
      };
      if (geo && typeof geo === "object") {
        body.geo_latitude = geo.geo_latitude || "";
        body.geo_longitude = geo.geo_longitude || "";
        body.geo_accuracy_m = geo.geo_accuracy_m || "";
        body.geo_captured_at = geo.geo_captured_at || "";
      }

      return fetch(submitUrl, {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/json",
          "Accept": "application/json"
        },
        body: JSON.stringify(body)
      });
    })
      .then(function (res) { return res.json().catch(function () { return null; }).then(function (j) { return { ok: res.ok, json: j }; }); })
      .then(function (wrap) {
        var data = wrap && wrap.json ? wrap.json : null;
        if (!data || typeof data !== "object") {
          setStatus("danger", "Unexpected server response. Please try again.");
          return false;
        }

        if (data.status === "ok") {
          setStatus("success", String(data.message || "Attendance submitted."));
          if (data.redirect) {
            setTimeout(function () { window.location.href = String(data.redirect); }, 900);
          }
          return true;
        }

        setStatus("warning", String(data.message || "Unable to submit attendance."));
        return false;
      })
      .catch(function () {
        setStatus("danger", "Network error. Please try again.");
        return false;
      });
  }

  function tick() {
    if (!scanning) return;
    if (!video.videoWidth || !video.videoHeight) {
      requestAnimationFrame(tick);
      return;
    }

    var w = video.videoWidth;
    var h = video.videoHeight;

    // Downscale for performance.
    var maxW = 960;
    if (w > maxW) {
      h = Math.round((h * maxW) / w);
      w = maxW;
    }

    canvas.width = w;
    canvas.height = h;
    var ctx = canvas.getContext("2d", { willReadFrequently: true });
    if (!ctx) {
      setStatus("danger", "Canvas not available.");
      stopStream();
      return;
    }

    try {
      ctx.drawImage(video, 0, 0, w, h);
      var img = ctx.getImageData(0, 0, w, h);
      var qr = (typeof jsQR === "function") ? jsQR(img.data, w, h, { inversionAttempts: "attemptBoth" }) : null;
      if (qr && qr.data) {
        var parsed = parsePayload(qr.data);
        if (parsed) {
          setStatus("info", "QR detected. Processing...");
          stopStream();
          submitAttendance(parsed);
          return;
        } else {
          setStatus("warning", "QR detected but payload is not recognized.");
          stopStream();
          return;
        }
      }
    } catch (e) {
      // ignore frame errors
    }

    requestAnimationFrame(tick);
  }

  function startScanner() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      setStatus("danger", "Camera access is not supported in this browser.");
      return;
    }
    if (typeof jsQR !== "function") {
      setStatus("danger", "QR scanner library failed to load.");
      return;
    }

    startBtn.disabled = true;
    stopBtn.disabled = false;
    setStatus("info", "Starting camera...");

    var deviceId = getSelectedDeviceId();
    var constraints = deviceId
      ? { video: { deviceId: { exact: deviceId } }, audio: false }
      : { video: { facingMode: "environment" }, audio: false };

    navigator.mediaDevices.getUserMedia(constraints).then(function (s) {
      stream = s;
      video.srcObject = stream;
      return video.play();
    }).then(function () {
      scanning = true;
      setStatus("info", "Scanning... Hold the QR code steady.");
      rememberDeviceId(deviceId);
      refreshCameras();
      requestAnimationFrame(tick);
    }).catch(function () {
      setStatus("danger", "Unable to access camera. Check browser permissions and make sure you are on HTTPS (or localhost).");
      stopStream();
    });
  }

  startBtn.addEventListener("click", startScanner);
  stopBtn.addEventListener("click", function () {
    setStatus("secondary", "Scanner stopped.");
    stopStream();
  });
  if (camSel) camSel.addEventListener("change", function () { rememberDeviceId(getSelectedDeviceId()); });
  if (refreshBtn) refreshBtn.addEventListener("click", function () {
    setStatus("secondary", "Refreshing camera list...");
    refreshCameras().then(function () { setStatus("secondary", "Camera list updated."); });
  });

  submitPayloadBtn.addEventListener("click", function () {
    var parsed = parsePayload(payloadInput.value);
    submitAttendance(parsed);
  });

  refreshCameras();
})();
</script>
</body>
</html>
