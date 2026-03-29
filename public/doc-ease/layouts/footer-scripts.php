<!-- Vendor js -->
<script src="assets/js/vendor.min.js"></script>
<?php
$sessionIdleMinutes = null;
$sessionTimeoutEnabled = false;

if (
    isset($_SESSION['user_id']) && (int) $_SESSION['user_id'] > 0 &&
    isset($conn) && $conn instanceof mysqli &&
    function_exists('session_idle_timeout_get_minutes')
) {
    $sessionIdleMinutes = (int) session_idle_timeout_get_minutes($conn);
    $sessionTimeoutEnabled = ($sessionIdleMinutes > 0);
}

$sessionTimeoutJsVersion = '1';
$sessionTimeoutJsPath = __DIR__ . '/../assets/js/session-timeout.js';
if ($sessionTimeoutEnabled && is_file($sessionTimeoutJsPath)) {
    $sessionTimeoutJsVersion = (string) filemtime($sessionTimeoutJsPath);
}
$sessionTimeoutLogoutCsrf = '';
if ($sessionTimeoutEnabled && function_exists('csrf_token')) {
    $sessionTimeoutLogoutCsrf = (string) csrf_token();
}
?>
<?php if ($sessionTimeoutEnabled): ?>
<script>
window.DOC_EASE_SESSION = {
    idleTimeoutMs: <?php echo (int) ($sessionIdleMinutes * 60 * 1000); ?>,
    keepaliveUrl: "includes/session_keepalive.php",
    signalPollUrl: "includes/session_signal_poll.php",
    signalPollEveryMs: 15000,
    logoutUrl: "auth-logout.php",
    logoutReason: "timeout",
    logoutCsrfToken: <?php echo json_encode($sessionTimeoutLogoutCsrf, JSON_UNESCAPED_SLASHES); ?>,
    logoutLegacyUrl: "auth-logout.php?reason=timeout"
};
</script>
<script src="assets/js/session-timeout.js?v=<?php echo urlencode($sessionTimeoutJsVersion); ?>"></script>
<?php endif; ?>

<?php
$pendingAcc = null;
$pendingAccId = 0;
$pendingAccTitle = '';
$pendingAccDate = '';

if (isset($_SESSION['user_id']) && (int) $_SESSION['user_id'] > 0) {
    // Prefer queue-based prompts if helper is available.
    if (!function_exists('teacher_activity_queue_peek') && is_file(__DIR__ . '/../includes/teacher_activity_events.php')) {
        require_once __DIR__ . '/../includes/teacher_activity_events.php';
    }
    if (function_exists('teacher_activity_queue_peek')) {
        $pendingAcc = teacher_activity_queue_peek();
    }
}

if (!is_array($pendingAcc)) {
    // Backward compatible single-event prompt.
    $pendingAcc = (isset($_SESSION['pending_accomplishment_event']) && is_array($_SESSION['pending_accomplishment_event']))
        ? $_SESSION['pending_accomplishment_event']
        : null;
}
$pendingAccId = is_array($pendingAcc) ? (int) ($pendingAcc['id'] ?? 0) : 0;
$pendingAccTitle = is_array($pendingAcc) ? trim((string) ($pendingAcc['title'] ?? 'Class activity')) : '';
$pendingAccDate = is_array($pendingAcc) ? trim((string) ($pendingAcc['date'] ?? '')) : '';
$canPromptAcc = (
    $pendingAccId > 0 &&
    isset($_SESSION['user_id']) && (int) $_SESSION['user_id'] > 0 &&
    isset($_SESSION['user_role']) && (string) $_SESSION['user_role'] === 'teacher'
);
?>
<?php if ($canPromptAcc): ?>
<div class="modal fade" id="docEaseAccEventModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add to Monthly Accomplishment?</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2">
                    <div class="fw-semibold"><?php echo htmlspecialchars($pendingAccTitle !== '' ? $pendingAccTitle : 'Class activity', ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php if ($pendingAccDate !== ''): ?>
                        <div class="text-muted small">Date: <?php echo htmlspecialchars($pendingAccDate, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>
                </div>
                <div class="small text-muted">
                    If you continue, this activity will be added as an additional entry in your Monthly Accomplishment report.
                    A result chart and attendance summary proof will be attached automatically.
                </div>
                <div id="docEaseAccEventStatus" class="small mt-3"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" id="docEaseAccDismissBtn" data-bs-dismiss="modal">Not now</button>
                <button type="button" class="btn btn-primary" id="docEaseAccAcceptBtn">Add to Accomplishment</button>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    var eventId = <?php echo (int) $pendingAccId; ?>;
    var csrf = <?php echo json_encode(csrf_token(), JSON_UNESCAPED_SLASHES); ?>;
    var endpoint = "includes/accomplishment_event_action.php";
    var statusEl = document.getElementById("docEaseAccEventStatus");
    var acceptBtn = document.getElementById("docEaseAccAcceptBtn");
    var dismissBtn = document.getElementById("docEaseAccDismissBtn");

    var inFlight = false;
    var handled = false;

    function setStatus(text, cls) {
        if (!statusEl) return;
        statusEl.className = "small mt-3 " + (cls || "");
        statusEl.textContent = text || "";
    }

    function post(action) {
        inFlight = true;
        if (acceptBtn) acceptBtn.disabled = true;
        if (dismissBtn) dismissBtn.disabled = true;
        setStatus("Saving...", "text-muted");

        return fetch(endpoint, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ action: action, event_id: eventId, csrf_token: csrf })
        }).then(function (r) { return r.json(); });
    }

    function finish(ok) {
        inFlight = false;
        if (ok) {
            handled = true;
            window.location.reload();
            return;
        }
        if (acceptBtn) acceptBtn.disabled = false;
        if (dismissBtn) dismissBtn.disabled = false;
    }

    function showModalOrConfirm() {
        if (window.bootstrap && bootstrap.Modal) {
            var el = document.getElementById("docEaseAccEventModal");
            if (!el) return;
            var modal = bootstrap.Modal.getOrCreateInstance(el, { backdrop: "static" });

            acceptBtn && acceptBtn.addEventListener("click", function () {
                if (inFlight || handled) return;
                post("accept").then(function (data) {
                    if (data && data.status === "ok") return finish(true);
                    setStatus((data && data.message) ? data.message : "Request failed.", "text-danger");
                    finish(false);
                }).catch(function () {
                    setStatus("Request failed. Please try again.", "text-danger");
                    finish(false);
                });
            });

            dismissBtn && dismissBtn.addEventListener("click", function () {
                if (inFlight || handled) return;
                post("dismiss").then(function () { return finish(true); }).catch(function () { return finish(true); });
            });

            el.addEventListener("hidden.bs.modal", function () {
                if (inFlight || handled) return;
                // Treat closing as "Not now" to prevent repeated prompts.
                post("dismiss").then(function () { return finish(true); }).catch(function () { return finish(true); });
            }, { once: true });

            modal.show();
            return;
        }

        // Fallback prompt if Bootstrap isn't present.
        var yes = window.confirm("Add this activity to your Monthly Accomplishment as an additional entry?");
        post(yes ? "accept" : "dismiss").then(function () { return finish(true); }).catch(function () { return finish(true); });
    }

    // Slight delay so the page renders before the modal appears.
    window.setTimeout(showModalOrConfirm, 350);
})();
</script>
<?php endif; ?>
