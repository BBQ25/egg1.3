<?php include '../layouts/session.php'; ?>
<?php require_active_role('teacher'); ?>
<?php include '../layouts/main.php'; ?>

<?php
require_once __DIR__ . '/../includes/attendance_checkin.php';
attendance_checkin_ensure_tables($conn);

if (!function_exists('taqr_h')) {
    function taqr_h($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

$teacherId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$sessionId = isset($_GET['session_id']) ? (int) $_GET['session_id'] : 0;

$session = ($teacherId > 0 && $sessionId > 0)
    ? attendance_checkin_get_session_for_teacher($conn, $sessionId, $teacherId)
    : null;

$method = is_array($session) ? attendance_checkin_normalize_method((string) ($session['checkin_method'] ?? 'code')) : 'code';
$code = is_array($session) ? trim((string) ($session['attendance_code'] ?? '')) : '';
$payload = ($sessionId > 0 && $code !== '') ? ('DOC_EASE_ATTENDANCE|' . $sessionId . '|' . $code) : '';
$back = '';
if (is_array($session)) {
    $cid = (int) ($session['class_record_id'] ?? 0);
    $back = 'teacher-attendance-uploads.php';
    if ($cid > 0) $back .= '?class_record_id=' . $cid . '&session_id=' . (int) $sessionId;
}
?>

<head>
    <title>Attendance QR | E-Record</title>
    <?php include '../layouts/title-meta.php'; ?>
    <?php include '../layouts/head-css.php'; ?>
    <style>
        .taqr-wrap{max-width:980px;margin:0 auto;}
        .taqr-qr{display:flex;justify-content:center;align-items:center;min-height:360px;}
        .taqr-qr > div{background:#fff;padding:16px;border-radius:12px;border:1px solid rgba(0,0,0,.08);box-shadow:0 6px 24px rgba(0,0,0,.08);}
        .taqr-code{font-family:monospace;font-size:1.05rem;}
    </style>
</head>

<body class="bg-light">
<div class="container-fluid py-3">
    <div class="taqr-wrap">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <div>
                <h4 class="mb-0">Attendance QR</h4>
                <div class="text-muted small">Show this QR code to your class. Students can scan it from their account.</div>
            </div>
            <div class="d-flex gap-2">
                <?php if ($back !== ''): ?>
                    <a class="btn btn-outline-secondary" href="<?php echo taqr_h($back); ?>">
                        <i class="ri-arrow-left-line me-1" aria-hidden="true"></i>Back
                    </a>
                <?php endif; ?>
                <button class="btn btn-outline-dark" type="button" onclick="document.documentElement.requestFullscreen && document.documentElement.requestFullscreen();">
                    <i class="ri-fullscreen-line me-1" aria-hidden="true"></i>Fullscreen
                </button>
            </div>
        </div>

        <?php if (!is_array($session)): ?>
            <div class="alert alert-danger">Session not found or you do not have access.</div>
        <?php elseif ($method === 'face'): ?>
            <div class="alert alert-info">
                This session uses <strong>Facial Check-In</strong>. QR check-in is not enabled for this session.
            </div>
        <?php elseif ($method !== 'qr'): ?>
            <div class="alert alert-info">
                This session uses <strong>Code Entry</strong>. QR check-in is not enabled for this session.
            </div>
        <?php elseif ($payload === ''): ?>
            <div class="alert alert-warning">Unable to generate QR payload for this session.</div>
        <?php else: ?>
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                        <div>
                            <div class="fw-semibold"><?php echo taqr_h((string) ($session['session_label'] ?? 'Attendance Session')); ?></div>
                            <div class="text-muted small">
                                Session ID: <span class="taqr-code"><?php echo (int) $sessionId; ?></span>
                                <span class="mx-2">|</span>
                                Code: <span class="taqr-code"><?php echo taqr_h($code); ?></span>
                            </div>
                        </div>
                        <span class="badge bg-info-subtle text-info">QR Check-In</span>
                    </div>

                    <div class="taqr-qr">
                        <div id="taqr_qr"></div>
                    </div>

                    <div class="mt-3">
                        <label class="form-label">QR Payload (for debugging / fallback)</label>
                        <div class="input-group">
                            <input class="form-control taqr-code" id="taqr_payload" value="<?php echo taqr_h($payload); ?>" readonly>
                            <button class="btn btn-outline-primary" type="button" id="taqr_copy">Copy</button>
                        </div>
                        <div class="form-text">Students should use the QR scanner in their Attendance page.</div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include '../layouts/footer-scripts.php'; ?>
<script src="assets/vendor/qrcodejs/qrcode.min.js"></script>
<script>
(function () {
  var payloadEl = document.getElementById('taqr_payload');
  var qrEl = document.getElementById('taqr_qr');
  if (!payloadEl || !qrEl || typeof QRCode !== 'function') return;

  var text = String(payloadEl.value || '');
  if (!text) return;

  // QRCode.js renders into the element.
  new QRCode(qrEl, {
    text: text,
    width: 320,
    height: 320,
    correctLevel: QRCode.CorrectLevel.M
  });

  var copyBtn = document.getElementById('taqr_copy');
  if (copyBtn) {
    copyBtn.addEventListener('click', function () {
      try {
        payloadEl.focus();
        payloadEl.select();
        document.execCommand('copy');
        copyBtn.textContent = 'Copied';
        setTimeout(function () { copyBtn.textContent = 'Copy'; }, 1200);
      } catch (e) {
        // ignore
      }
    });
  }
})();
</script>
</body>
</html>
