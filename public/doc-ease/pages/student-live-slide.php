<?php include '../layouts/session.php'; ?>
<?php require_approved_student(); ?>
<?php include '../layouts/main.php'; ?>

<?php
require_once __DIR__ . '/../includes/learning_materials.php';
ensure_learning_material_tables($conn);

$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$studentId = learning_material_student_id_from_user($conn, $userId);
if ($studentId <= 0) {
    deny_access(403, 'Student profile is not linked to this account.');
}

$classRecordIdBack = isset($_GET['class_record_id']) ? (int) $_GET['class_record_id'] : 0;
$flash = isset($_SESSION['flash_message']) ? (string) $_SESSION['flash_message'] : '';
$flashType = isset($_SESSION['flash_type']) ? (string) $_SESSION['flash_type'] : 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

if (!function_exists('sls_h')) {
    function sls_h($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

$code = isset($_GET['code']) ? learning_material_live_normalize_code($_GET['code']) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    if (!csrf_validate($csrf)) {
        $_SESSION['flash_message'] = 'Security check failed (CSRF).';
        $_SESSION['flash_type'] = 'danger';
        header('Location: student-live-slide.php' . ($classRecordIdBack > 0 ? ('?class_record_id=' . $classRecordIdBack) : ''));
        exit;
    }

    $postedCode = learning_material_live_normalize_code($_POST['access_code'] ?? '');
    if (!learning_material_live_code_is_valid($postedCode)) {
        $_SESSION['flash_message'] = 'Enter a valid 6-digit code.';
        $_SESSION['flash_type'] = 'warning';
        header('Location: student-live-slide.php' . ($classRecordIdBack > 0 ? ('?class_record_id=' . $classRecordIdBack) : ''));
        exit;
    }

    $target = 'student-live-slide.php?code=' . urlencode($postedCode);
    if ($classRecordIdBack > 0) $target .= '&class_record_id=' . $classRecordIdBack;
    header('Location: ' . $target);
    exit;
}

$broadcast = null;
if (learning_material_live_code_is_valid($code)) {
    $broadcast = learning_material_live_get_student_broadcast_by_code($conn, $studentId, $code);
}
if (is_array($broadcast)) {
    $classRecordIdBack = (int) ($broadcast['class_record_id'] ?? $classRecordIdBack);
}
$backHref = $classRecordIdBack > 0
    ? ('student-learning-materials.php?class_record_id=' . $classRecordIdBack)
    : 'student-dashboard.php';
?>

<head>
    <title>Live Slide Viewer | E-Record</title>
    <?php include '../layouts/title-meta.php'; ?>
    <?php include '../layouts/head-css.php'; ?>
</head>

<body>
    <div class="wrapper">
        <?php include '../layouts/menu.php'; ?>

        <div class="content-page">
            <div class="content">
                <div class="container-fluid">
                    <div class="page-title-box">
                        <div class="page-title-right">
                            <ol class="breadcrumb m-0">
                                <li class="breadcrumb-item"><a href="student-dashboard.php">My Grades &amp; Scores</a></li>
                                <li class="breadcrumb-item active">Live Slide Viewer</li>
                            </ol>
                        </div>
                        <h4 class="page-title">Live Slide Viewer</h4>
                    </div>

                    <?php if ($flash !== ''): ?>
                        <div class="alert alert-<?php echo sls_h($flashType); ?> alert-dismissible fade show" role="alert">
                            <?php echo sls_h($flash); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <div class="card mb-3">
                        <div class="card-body">
                            <form method="post" class="d-flex flex-wrap align-items-center gap-2">
                                <input type="hidden" name="csrf_token" value="<?php echo sls_h(csrf_token()); ?>">
                                <input type="text" name="access_code" class="form-control" placeholder="Enter 6-digit code" value="<?php echo sls_h($code); ?>" pattern="[0-9]{6}" maxlength="6" style="max-width: 220px;" required>
                                <button class="btn btn-primary" type="submit">Join Broadcast</button>
                                <a class="btn btn-outline-secondary" href="<?php echo sls_h($backHref); ?>">Back</a>
                            </form>
                            <div class="text-muted small mt-2">Viewer mode only. Slide navigation is controlled by the teacher.</div>
                        </div>
                    </div>

                    <?php if ($code !== '' && !learning_material_live_code_is_valid($code)): ?>
                        <div class="alert alert-warning">Invalid code format.</div>
                    <?php elseif ($code !== '' && !is_array($broadcast)): ?>
                        <div class="alert alert-warning">No active live broadcast found for this code (or you are not enrolled in that class).</div>
                    <?php elseif (is_array($broadcast)): ?>
                        <?php $slideHref = (string) ($broadcast['slide_href'] ?? ''); ?>
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                                    <div>
                                        <h5 class="mb-1"><?php echo sls_h((string) ($broadcast['material_title'] ?? 'Live Material')); ?></h5>
                                        <div class="text-muted">
                                            <?php echo sls_h((string) ($broadcast['subject_name'] ?? 'Subject')); ?>
                                            <?php if (!empty($broadcast['subject_code'])): ?>(<?php echo sls_h((string) ($broadcast['subject_code'] ?? '')); ?>)<?php endif; ?>
                                            | Section: <?php echo sls_h((string) ($broadcast['section'] ?? '')); ?>
                                        </div>
                                    </div>
                                    <div class="text-muted" id="liveSlideCounter">
                                        Slide <?php echo (int) ($broadcast['current_slide'] ?? 1); ?> / <?php echo (int) ($broadcast['slide_count'] ?? 1); ?>
                                    </div>
                                </div>

                                <?php if ($slideHref !== ''): ?>
                                    <div class="border rounded p-2 bg-light">
                                        <img id="liveSlideImage" src="<?php echo sls_h($slideHref); ?>?v=<?php echo (int) time(); ?>" alt="Live slide" class="img-fluid">
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-warning mb-0">Current slide image is unavailable.</div>
                                <?php endif; ?>
                                <div id="liveSlideStatus" class="text-muted small mt-2">Connected. Waiting for slide updates...</div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php include '../layouts/footer.php'; ?>
        </div>
    </div>

    <?php include '../layouts/right-sidebar.php'; ?>
    <?php include '../layouts/footer-scripts.php'; ?>
    <script src="assets/js/app.min.js"></script>
    <?php if (is_array($broadcast)): ?>
        <script>
            (function() {
                var code = <?php echo json_encode((string) $code, JSON_UNESCAPED_SLASHES); ?>;
                var img = document.getElementById('liveSlideImage');
                var counter = document.getElementById('liveSlideCounter');
                var status = document.getElementById('liveSlideStatus');
                if (!code) return;

                var currentBaseHref = <?php echo json_encode((string) ($broadcast['slide_href'] ?? ''), JSON_UNESCAPED_SLASHES); ?>;
                var timer = null;

                function setStatus(text) {
                    if (!status) return;
                    status.textContent = String(text || '');
                }

                function tick() {
                    fetch('includes/live_slide_state.php?code=' + encodeURIComponent(code), {
                        credentials: 'same-origin',
                        cache: 'no-store'
                    }).then(function(resp) {
                        return resp.json();
                    }).then(function(json) {
                        if (!json || !json.ok) {
                            setStatus('Unable to fetch live state.');
                            return;
                        }
                        if (!json.live || !json.broadcast) {
                            setStatus('Broadcast ended or unavailable.');
                            return;
                        }

                        var b = json.broadcast;
                        var nextHref = String(b.slide_href || '');
                        var slideNo = Number(b.current_slide || 1);
                        var slideCount = Number(b.slide_count || 1);
                        if (counter) {
                            counter.textContent = 'Slide ' + slideNo + ' / ' + slideCount;
                        }
                        if (img && nextHref) {
                            if (currentBaseHref !== nextHref) {
                                currentBaseHref = nextHref;
                                img.setAttribute('src', nextHref + '?v=' + Date.now());
                            }
                        }
                        setStatus('Connected. Last update: ' + new Date().toLocaleTimeString());
                    }).catch(function() {
                        setStatus('Connection issue. Retrying...');
                    });
                }

                timer = setInterval(tick, 2500);
                tick();
                window.addEventListener('beforeunload', function() {
                    if (timer) clearInterval(timer);
                });
            })();
        </script>
    <?php endif; ?>
</body>

</html>
