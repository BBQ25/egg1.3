<?php include '../layouts/session.php'; ?>
<?php require_active_role('teacher'); ?>
<?php include '../layouts/main.php'; ?>

<?php
require_once __DIR__ . '/../includes/learning_materials.php';
ensure_learning_material_tables($conn);

$teacherId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$classRecordId = isset($_GET['class_record_id']) ? (int) $_GET['class_record_id'] : 0;
$materialId = isset($_GET['material_id']) ? (int) $_GET['material_id'] : 0;
if ($classRecordId <= 0 || $materialId <= 0) {
    $_SESSION['flash_message'] = 'Invalid live slide request.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: teacher-my-classes.php');
    exit;
}

$flash = isset($_SESSION['flash_message']) ? (string) $_SESSION['flash_message'] : '';
$flashType = isset($_SESSION['flash_type']) ? (string) $_SESSION['flash_type'] : 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

if (!function_exists('tls_h')) {
    function tls_h($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

$ctx = null;
$ctxQ = $conn->prepare(
    "SELECT lm.id AS material_id,
            lm.title AS material_title,
            lm.status AS material_status,
            cr.id AS class_record_id,
            cr.section, cr.academic_year, cr.semester,
            s.subject_code, s.subject_name
     FROM learning_materials lm
     JOIN class_records cr ON cr.id = lm.class_record_id
     JOIN subjects s ON s.id = cr.subject_id
     JOIN teacher_assignments ta
       ON ta.class_record_id = lm.class_record_id
      AND ta.teacher_id = ?
      AND ta.status = 'active'
     WHERE lm.id = ?
       AND lm.class_record_id = ?
       AND cr.status = 'active'
     LIMIT 1"
);
if ($ctxQ) {
    $ctxQ->bind_param('iii', $teacherId, $materialId, $classRecordId);
    $ctxQ->execute();
    $res = $ctxQ->get_result();
    if ($res && $res->num_rows === 1) $ctx = $res->fetch_assoc();
    $ctxQ->close();
}
if (!is_array($ctx)) {
    deny_access(403, 'Forbidden: class/material is not available for this teacher.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    if (!csrf_validate($csrf)) {
        $_SESSION['flash_message'] = 'Security check failed (CSRF).';
        $_SESSION['flash_type'] = 'danger';
        header('Location: teacher-live-slides.php?class_record_id=' . $classRecordId . '&material_id=' . $materialId);
        exit;
    }

    $action = trim((string) ($_POST['action'] ?? ''));
    $error = '';
    if ($action === 'start_broadcast') {
        $attachmentId = isset($_POST['attachment_id']) ? (int) $_POST['attachment_id'] : 0;
        $row = learning_material_live_start_from_attachment($conn, $teacherId, $classRecordId, $materialId, $attachmentId, $error);
        if (is_array($row)) {
            $_SESSION['flash_message'] = 'Live broadcast started. Join code: ' . (string) ($row['access_code'] ?? '');
            $_SESSION['flash_type'] = 'success';
        } else {
            $_SESSION['flash_message'] = $error !== '' ? $error : 'Unable to start live broadcast.';
            $_SESSION['flash_type'] = 'danger';
        }
        header('Location: teacher-live-slides.php?class_record_id=' . $classRecordId . '&material_id=' . $materialId);
        exit;
    }

    if ($action === 'step_slide') {
        $broadcastId = isset($_POST['broadcast_id']) ? (int) $_POST['broadcast_id'] : 0;
        $delta = isset($_POST['delta']) ? (int) $_POST['delta'] : 0;
        $row = learning_material_live_step_slide_for_teacher($conn, $teacherId, $broadcastId, $delta, $error);
        $_SESSION['flash_message'] = is_array($row) ? 'Slide updated.' : ($error !== '' ? $error : 'Unable to update slide.');
        $_SESSION['flash_type'] = is_array($row) ? 'success' : 'danger';
        header('Location: teacher-live-slides.php?class_record_id=' . $classRecordId . '&material_id=' . $materialId);
        exit;
    }

    if ($action === 'set_slide') {
        $broadcastId = isset($_POST['broadcast_id']) ? (int) $_POST['broadcast_id'] : 0;
        $slideNo = isset($_POST['slide_no']) ? (int) $_POST['slide_no'] : 1;
        $row = learning_material_live_set_slide_for_teacher($conn, $teacherId, $broadcastId, $slideNo, $error);
        $_SESSION['flash_message'] = is_array($row) ? 'Slide updated.' : ($error !== '' ? $error : 'Unable to update slide.');
        $_SESSION['flash_type'] = is_array($row) ? 'success' : 'danger';
        header('Location: teacher-live-slides.php?class_record_id=' . $classRecordId . '&material_id=' . $materialId);
        exit;
    }

    if ($action === 'end_broadcast') {
        $broadcastId = isset($_POST['broadcast_id']) ? (int) $_POST['broadcast_id'] : 0;
        $ok = learning_material_live_end_for_teacher($conn, $teacherId, $broadcastId, $error);
        $_SESSION['flash_message'] = $ok ? 'Live broadcast ended.' : ($error !== '' ? $error : 'Unable to end live broadcast.');
        $_SESSION['flash_type'] = $ok ? 'success' : 'danger';
        header('Location: teacher-live-slides.php?class_record_id=' . $classRecordId . '&material_id=' . $materialId);
        exit;
    }
}

$attachments = learning_material_fetch_attachments($conn, $materialId);
$liveAttachments = learning_material_live_filter_attachment_candidates($attachments);
$active = learning_material_live_get_teacher_broadcast($conn, $teacherId, $classRecordId, $materialId);
$activeSlideHref = is_array($active) ? (string) ($active['slide_href'] ?? '') : '';
?>

<head>
    <title>Live Slides | E-Record</title>
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
                                <li class="breadcrumb-item"><a href="teacher-dashboard.php">Teacher</a></li>
                                <li class="breadcrumb-item"><a href="teacher-my-classes.php">My Classes</a></li>
                                <li class="breadcrumb-item"><a href="teacher-learning-materials.php?class_record_id=<?php echo (int) $classRecordId; ?>">Learning Materials</a></li>
                                <li class="breadcrumb-item active">Live Slides</li>
                            </ol>
                        </div>
                        <h4 class="page-title">Live Slides</h4>
                    </div>

                    <?php if ($flash !== ''): ?>
                        <div class="alert alert-<?php echo tls_h($flashType); ?> alert-dismissible fade show" role="alert">
                            <?php echo tls_h($flash); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <div class="card mb-3">
                        <div class="card-body">
                            <h5 class="mb-1"><?php echo tls_h((string) ($ctx['material_title'] ?? 'Material')); ?></h5>
                            <div class="text-muted">
                                <?php echo tls_h((string) ($ctx['subject_name'] ?? 'Subject')); ?>
                                <?php if (!empty($ctx['subject_code'])): ?>(<?php echo tls_h((string) ($ctx['subject_code'] ?? '')); ?>)<?php endif; ?>
                                | Section: <?php echo tls_h((string) ($ctx['section'] ?? '')); ?>
                            </div>
                        </div>
                    </div>

                    <div class="card mb-3">
                        <div class="card-body">
                            <h5 class="mb-3">Start or Restart Broadcast</h5>
                            <?php if (count($liveAttachments) === 0): ?>
                                <div class="alert alert-warning mb-0">
                                    No supported file found. Upload a PDF/PPT/PPTX/PPS/PPSX attachment first.
                                </div>
                            <?php else: ?>
                                <form method="post" class="d-flex flex-wrap align-items-center gap-2">
                                    <input type="hidden" name="csrf_token" value="<?php echo tls_h(csrf_token()); ?>">
                                    <input type="hidden" name="action" value="start_broadcast">
                                    <select name="attachment_id" class="form-select" style="max-width: 460px;" required>
                                        <?php foreach ($liveAttachments as $attachment): ?>
                                            <?php $aid = (int) ($attachment['id'] ?? 0); ?>
                                            <option value="<?php echo $aid; ?>">
                                                <?php echo tls_h((string) ($attachment['original_name'] ?? 'Attachment')); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn btn-primary">
                                        <?php echo is_array($active) ? 'Restart Broadcast' : 'Start Broadcast'; ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-body">
                            <?php if (!is_array($active)): ?>
                                <div class="alert alert-light border mb-0">No active broadcast.</div>
                            <?php else: ?>
                                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                                    <div>
                                        <div class="text-muted small">Join Code</div>
                                        <div class="h2 mb-0 font-monospace"><?php echo tls_h((string) ($active['access_code'] ?? '')); ?></div>
                                    </div>
                                    <div class="text-muted">
                                        Slide <?php echo (int) ($active['current_slide'] ?? 1); ?> / <?php echo (int) ($active['slide_count'] ?? 1); ?>
                                    </div>
                                </div>

                                <div class="d-flex flex-wrap gap-2 mb-3">
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo tls_h(csrf_token()); ?>">
                                        <input type="hidden" name="action" value="step_slide">
                                        <input type="hidden" name="broadcast_id" value="<?php echo (int) ($active['id'] ?? 0); ?>">
                                        <input type="hidden" name="delta" value="-1">
                                        <button class="btn btn-outline-secondary" type="submit">Previous</button>
                                    </form>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo tls_h(csrf_token()); ?>">
                                        <input type="hidden" name="action" value="step_slide">
                                        <input type="hidden" name="broadcast_id" value="<?php echo (int) ($active['id'] ?? 0); ?>">
                                        <input type="hidden" name="delta" value="1">
                                        <button class="btn btn-outline-primary" type="submit">Next</button>
                                    </form>
                                    <form method="post" class="d-flex align-items-center gap-2">
                                        <input type="hidden" name="csrf_token" value="<?php echo tls_h(csrf_token()); ?>">
                                        <input type="hidden" name="action" value="set_slide">
                                        <input type="hidden" name="broadcast_id" value="<?php echo (int) ($active['id'] ?? 0); ?>">
                                        <input type="number" name="slide_no" class="form-control" min="1" max="<?php echo (int) ($active['slide_count'] ?? 1); ?>" value="<?php echo (int) ($active['current_slide'] ?? 1); ?>" style="width: 110px;">
                                        <button class="btn btn-outline-dark" type="submit">Go</button>
                                    </form>
                                    <form method="post" class="d-inline ms-auto">
                                        <input type="hidden" name="csrf_token" value="<?php echo tls_h(csrf_token()); ?>">
                                        <input type="hidden" name="action" value="end_broadcast">
                                        <input type="hidden" name="broadcast_id" value="<?php echo (int) ($active['id'] ?? 0); ?>">
                                        <button class="btn btn-outline-danger" type="submit" onclick="return confirm('End this live broadcast?');">End Broadcast</button>
                                    </form>
                                </div>

                                <?php if ($activeSlideHref !== ''): ?>
                                    <div class="border rounded p-2 bg-light">
                                        <img src="<?php echo tls_h($activeSlideHref); ?>?v=<?php echo (int) time(); ?>" alt="Current slide" class="img-fluid">
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-warning mb-0">Slide image is not available.</div>
                                <?php endif; ?>
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
    <script src="assets/js/app.min.js"></script>
</body>

</html>
