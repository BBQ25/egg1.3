<?php include '../layouts/session.php'; ?>
<?php require_role('admin'); ?>

<?php
require_once __DIR__ . '/../includes/schedule.php';
require_once __DIR__ . '/../includes/audit.php';
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

if (!function_exists('admin_schedule_request_accessible')) {
    function admin_schedule_request_accessible(mysqli $conn, $requestId, $isSuperadmin, $campusId) {
        $requestId = (int) $requestId;
        $isSuperadmin = (bool) $isSuperadmin;
        $campusId = (int) $campusId;
        if ($requestId <= 0) return false;

        if ($isSuperadmin) {
            $stmt = $conn->prepare(
                "SELECT 1
                 FROM schedule_change_requests
                 WHERE id = ? AND status = 'pending'
                 LIMIT 1"
            );
            if (!$stmt) return false;
            $stmt->bind_param('i', $requestId);
        } else {
            if ($campusId <= 0) return false;
            $stmt = $conn->prepare(
                "SELECT 1
                 FROM schedule_change_requests r
                 JOIN class_records cr ON cr.id = r.class_record_id
                 WHERE r.id = ?
                   AND r.status = 'pending'
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
            $stmt->bind_param('iiii', $requestId, $campusId, $campusId, $campusId);
        }

        $stmt->execute();
        $res = $stmt->get_result();
        $ok = ($res && $res->num_rows === 1);
        $stmt->close();
        return $ok;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    if (!csrf_validate($csrf)) {
        $_SESSION['flash_message'] = 'Security check failed (CSRF). Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: admin-schedule-approvals.php');
        exit;
    }

    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';
    $requestId = isset($_POST['request_id']) ? (int) $_POST['request_id'] : 0;
    $note = isset($_POST['review_note']) ? trim((string) $_POST['review_note']) : '';

    if (!in_array($action, ['approve', 'reject'], true) || $requestId <= 0) {
        $_SESSION['flash_message'] = 'Invalid request.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: admin-schedule-approvals.php');
        exit;
    }

    if (!admin_schedule_request_accessible($conn, $requestId, $adminIsSuperadmin, $adminCampusId)) {
        $_SESSION['flash_message'] = 'Request is not available for your campus scope.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: admin-schedule-approvals.php');
        exit;
    }

    [$ok, $msg] = schedule_apply_change_request($conn, $requestId, $adminId, $action === 'approve', $note);
    $_SESSION['flash_message'] = $msg;
    $_SESSION['flash_type'] = $ok ? 'success' : 'danger';
    if ($ok) {
        audit_log($conn, $action === 'approve' ? 'schedule.request.approved' : 'schedule.request.rejected', 'schedule_change_request', $requestId, null);
    }
    header('Location: admin-schedule-approvals.php');
    exit;
}

// Pending requests.
$requests = [];
if ($adminIsSuperadmin) {
    $q = $conn->prepare(
        "SELECT r.id, r.requested_at, r.action, r.class_record_id, r.slot_id, r.payload_json,
                u.id AS requester_id, u.username, u.useremail, u.first_name, u.last_name,
                cr.section, cr.academic_year, cr.semester,
                s.subject_code, s.subject_name
         FROM schedule_change_requests r
         JOIN users u ON u.id = r.requester_id
         JOIN class_records cr ON cr.id = r.class_record_id
         JOIN subjects s ON s.id = cr.subject_id
         WHERE r.status = 'pending'
         ORDER BY r.requested_at ASC, r.id ASC"
    );
    if ($q) {
        $q->execute();
        $res = $q->get_result();
        while ($res && ($row = $res->fetch_assoc())) $requests[] = $row;
        $q->close();
    }
} else {
    $q = $conn->prepare(
        "SELECT r.id, r.requested_at, r.action, r.class_record_id, r.slot_id, r.payload_json,
                u.id AS requester_id, u.username, u.useremail, u.first_name, u.last_name,
                cr.section, cr.academic_year, cr.semester,
                s.subject_code, s.subject_name
         FROM schedule_change_requests r
         JOIN users u ON u.id = r.requester_id
         JOIN class_records cr ON cr.id = r.class_record_id
         JOIN subjects s ON s.id = cr.subject_id
         WHERE r.status = 'pending'
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
         ORDER BY r.requested_at ASC, r.id ASC"
    );
    if ($q) {
        $q->bind_param('iii', $adminCampusId, $adminCampusId, $adminCampusId);
        $q->execute();
        $res = $q->get_result();
        while ($res && ($row = $res->fetch_assoc())) $requests[] = $row;
        $q->close();
    }
}

function full_name($u) {
    $fn = trim((string) ($u['first_name'] ?? ''));
    $ln = trim((string) ($u['last_name'] ?? ''));
    $full = trim($fn . ' ' . $ln);
    return $full !== '' ? $full : (string) ($u['username'] ?? 'User');
}
?>

<?php include '../layouts/main.php'; ?>

<head>
    <title>Schedule Approvals | E-Record</title>
    <?php include '../layouts/title-meta.php'; ?>
    <?php include '../layouts/head-css.php'; ?>
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
                                    <li class="breadcrumb-item active">Schedule Approvals</li>
                                </ol>
                            </div>
                            <h4 class="page-title">Schedule Approvals</h4>
                        </div>
                    </div>
                </div>

                <?php if ($flash): ?>
                    <div class="alert alert-<?php echo h($flashType); ?>"><?php echo h($flash); ?></div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div>
                                <h4 class="header-title mb-0">Pending Schedule Requests</h4>
                                <div class="text-muted small">Teachers submit schedule changes here for approval.</div>
                            </div>
                            <div class="text-muted small">Pending: <strong><?php echo (int) count($requests); ?></strong></div>
                        </div>

                        <div class="table-responsive mt-3">
                            <table class="table table-sm table-striped table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Requested</th>
                                        <th>Teacher</th>
                                        <th>Class</th>
                                        <th>Change</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($requests) === 0): ?>
                                        <tr>
                                            <td colspan="5" class="text-center text-muted py-4">No pending requests.</td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php foreach ($requests as $r): ?>
                                        <?php
                                        $payload = json_decode((string) ($r['payload_json'] ?? ''), true);
                                        if (!is_array($payload)) $payload = [];
                                        $action = (string) ($r['action'] ?? '');
                                        $slotLine = '';
                                        if ($action === 'create' || $action === 'update') {
                                            $dow = isset($payload['day_of_week']) ? schedule_day_label((int) $payload['day_of_week']) : '';
                                            $st = substr((string) ($payload['start_time'] ?? ''), 0, 5);
                                            $et = substr((string) ($payload['end_time'] ?? ''), 0, 5);
                                            $room = trim((string) ($payload['room'] ?? ''));
                                            $slotLine = trim($dow . ' ' . $st . '-' . $et);
                                            if ($room !== '') $slotLine .= ' (' . $room . ')';
                                        }
                                        ?>
                                        <tr>
                                            <td class="text-muted small"><?php echo h((string) ($r['requested_at'] ?? '')); ?></td>
                                            <td>
                                                <div class="fw-semibold"><?php echo h(full_name($r)); ?></div>
                                                <div class="text-muted small"><?php echo h((string) ($r['useremail'] ?? '')); ?></div>
                                            </td>
                                            <td>
                                                <div class="fw-semibold"><?php echo h((string) ($r['subject_code'] ?? '') . ' - ' . (string) ($r['subject_name'] ?? '')); ?></div>
                                                <div class="text-muted small"><?php echo h((string) ($r['section'] ?? '')); ?> | <?php echo h((string) ($r['academic_year'] ?? '')); ?> | <?php echo h((string) ($r['semester'] ?? '')); ?></div>
                                            </td>
                                            <td class="text-muted small">
                                                <div><strong><?php echo h(strtoupper($action)); ?></strong><?php echo $r['slot_id'] ? ' (slot #' . (int) $r['slot_id'] . ')' : ''; ?></div>
                                                <?php if ($slotLine !== ''): ?><div><?php echo h($slotLine); ?></div><?php endif; ?>
                                                <?php if (!empty($payload['modality'])): ?><div>modality: <?php echo h((string) $payload['modality']); ?></div><?php endif; ?>
                                                <?php if (!empty($payload['notes'])): ?><div>notes: <?php echo h((string) $payload['notes']); ?></div><?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                                                    <input type="hidden" name="action" value="approve">
                                                    <input type="hidden" name="request_id" value="<?php echo (int) ($r['id'] ?? 0); ?>">
                                                    <input type="hidden" name="review_note" value="">
                                                    <button class="btn btn-sm btn-success" type="submit" onclick="return confirm('Approve this schedule request?');">
                                                        <i class="ri-check-line me-1"></i>Approve
                                                    </button>
                                                </form>
                                                <form method="post" class="d-inline ms-1">
                                                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                                                    <input type="hidden" name="action" value="reject">
                                                    <input type="hidden" name="request_id" value="<?php echo (int) ($r['id'] ?? 0); ?>">
                                                    <input type="hidden" name="review_note" value="">
                                                    <button class="btn btn-sm btn-outline-danger" type="submit" onclick="return confirm('Reject this schedule request?');">
                                                        <i class="ri-close-line me-1"></i>Reject
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

        <?php include '../layouts/footer.php'; ?>
    </div>
</div>

<?php include '../layouts/right-sidebar.php'; ?>
<?php include '../layouts/footer-scripts.php'; ?>
<script src="assets/js/app.min.js"></script>
</body>
</html>
