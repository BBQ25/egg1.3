<?php include '../layouts/session.php'; ?>
<?php require_role('admin'); ?>
<?php include '../layouts/main.php'; ?>

<?php
require_once __DIR__ . '/../includes/profile.php';
require_once __DIR__ . '/../includes/audit.php';
ensure_profile_tables($conn);
ensure_audit_logs_table($conn);

$adminId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;

$flash = isset($_SESSION['flash_message']) ? (string) $_SESSION['flash_message'] : '';
$flashType = isset($_SESSION['flash_type']) ? (string) $_SESSION['flash_type'] : 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    if (!csrf_validate($csrf)) {
        $_SESSION['flash_message'] = 'Security check failed (CSRF). Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: admin-profile-approvals.php');
        exit;
    }

    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';
    $requestId = isset($_POST['request_id']) ? (int) $_POST['request_id'] : 0;
    $note = isset($_POST['review_note']) ? trim((string) $_POST['review_note']) : '';

    if ($requestId <= 0 || !in_array($action, ['approve', 'reject'], true)) {
        $_SESSION['flash_message'] = 'Invalid request.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: admin-profile-approvals.php');
        exit;
    }

    [$ok, $msg] = profile_apply_change_request($conn, $requestId, $adminId, $action === 'approve', $note);
    $_SESSION['flash_message'] = $msg;
    $_SESSION['flash_type'] = $ok ? 'success' : 'danger';
    if ($ok) {
        audit_log($conn, $action === 'approve' ? 'profile.change.approved' : 'profile.change.rejected', 'profile_change_request', $requestId, null);
    }
    header('Location: admin-profile-approvals.php');
    exit;
}

// Load pending requests.
$requests = [];
$q = $conn->prepare(
    "SELECT r.id, r.user_id, r.payload_json, r.staged_avatar_path, r.requested_at,
            u.username, u.useremail, u.first_name, u.last_name
     FROM user_profile_change_requests r
     JOIN users u ON u.id = r.user_id
     WHERE r.status = 'pending'
     ORDER BY r.requested_at ASC, r.id ASC"
);
if ($q) {
    $q->execute();
    $res = $q->get_result();
    while ($res && ($row = $res->fetch_assoc())) $requests[] = $row;
    $q->close();
}

$programChairMap = [];
$programChairOptions = profile_program_chair_options($conn);
foreach ($programChairOptions as $opt) {
    $optId = (int) ($opt['id'] ?? 0);
    if ($optId <= 0) continue;
    $programChairMap[$optId] = $opt;
}

function h($v) { return htmlspecialchars((string) $v); }
function full_name($u) {
    $fn = trim((string) ($u['first_name'] ?? ''));
    $ln = trim((string) ($u['last_name'] ?? ''));
    $full = trim($fn . ' ' . $ln);
    return $full !== '' ? $full : (string) ($u['username'] ?? 'User');
}
?>

<head>
    <title>Profile Approvals | E-Record</title>
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
                                        <li class="breadcrumb-item active">Profile Approvals</li>
                                    </ol>
                                </div>
                                <h4 class="page-title">Profile Approvals</h4>
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
                                    <h4 class="header-title mb-0">Pending Profile Updates</h4>
                                    <div class="text-muted small">Approve or reject user-submitted profile changes.</div>
                                </div>
                                <div class="text-muted small">Pending: <strong><?php echo (int) count($requests); ?></strong></div>
                            </div>

                            <div class="table-responsive mt-3">
                                <table class="table table-sm table-striped table-hover align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>User</th>
                                            <th>Requested</th>
                                            <th>Proposed Changes</th>
                                            <th class="text-end">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (count($requests) === 0): ?>
                                            <tr>
                                                <td colspan="4" class="text-center text-muted">No pending requests.</td>
                                            </tr>
                                        <?php endif; ?>
                                        <?php foreach ($requests as $r): ?>
                                            <?php
                                            $payload = json_decode((string) ($r['payload_json'] ?? ''), true);
                                            if (!is_array($payload)) $payload = [];
                                            ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-semibold"><?php echo h(full_name($r)); ?></div>
                                                    <div class="text-muted small"><?php echo h((string) ($r['useremail'] ?? '')); ?></div>
                                                </td>
                                                <td class="text-muted small"><?php echo h((string) ($r['requested_at'] ?? '')); ?></td>
                                                <td class="text-muted small">
                                                    <?php foreach ($payload as $k => $v): ?>
                                                        <?php
                                                        if ($k === 'program_chair_user_id') {
                                                            $pcId = (int) $v;
                                                            $pcText = 'Not assigned';
                                                            if ($pcId > 0) {
                                                                if (isset($programChairMap[$pcId])) {
                                                                    $opt = $programChairMap[$pcId];
                                                                    $pcText = trim((string) ($opt['display_name'] ?? 'Program Chair'));
                                                                    $pcEmail = trim((string) ($opt['useremail'] ?? ''));
                                                                    if ($pcEmail !== '') $pcText .= ' (' . $pcEmail . ')';
                                                                } else {
                                                                    $pcText = 'User #' . $pcId;
                                                                }
                                                            }
                                                            ?>
                                                            <div><strong>Program Chair:</strong> <?php echo h($pcText); ?></div>
                                                            <?php
                                                            continue;
                                                        }
                                                        if ($k === 'subject_program_chair_map') {
                                                            $subjectRows = is_array($v) ? $v : [];
                                                            ?>
                                                            <div><strong>Program Chair by Subject:</strong></div>
                                                            <?php if (count($subjectRows) === 0): ?>
                                                                <div class="ms-3">No subject mapping submitted.</div>
                                                            <?php else: ?>
                                                                <?php foreach ($subjectRows as $subjectRow): ?>
                                                                    <?php
                                                                    if (!is_array($subjectRow)) continue;
                                                                    $subjectLabel = trim((string) ($subjectRow['subject_label'] ?? 'Subject'));
                                                                    $subjectPcId = (int) ($subjectRow['program_chair_user_id'] ?? 0);
                                                                    $subjectPcText = 'Not assigned';
                                                                    if ($subjectPcId > 0) {
                                                                        if (isset($programChairMap[$subjectPcId])) {
                                                                            $subjectOpt = $programChairMap[$subjectPcId];
                                                                            $subjectPcText = trim((string) ($subjectOpt['display_name'] ?? 'Program Chair'));
                                                                            $subjectPcEmail = trim((string) ($subjectOpt['useremail'] ?? ''));
                                                                            if ($subjectPcEmail !== '') $subjectPcText .= ' (' . $subjectPcEmail . ')';
                                                                        } else {
                                                                            $subjectPcText = 'User #' . $subjectPcId;
                                                                        }
                                                                    }
                                                                    ?>
                                                                    <div class="ms-3"><strong><?php echo h($subjectLabel); ?>:</strong> <?php echo h($subjectPcText); ?></div>
                                                                <?php endforeach; ?>
                                                            <?php endif; ?>
                                                            <?php
                                                            continue;
                                                        }
                                                        if ($v === null || (is_string($v) && trim($v) === '')) continue;
                                                        ?>
                                                        <div><strong><?php echo h($k); ?>:</strong> <?php echo h(is_string($v) ? $v : json_encode($v)); ?></div>
                                                    <?php endforeach; ?>
                                                    <?php if (!empty($r['staged_avatar_path'])): ?>
                                                        <div><strong>avatar:</strong> <?php echo h((string) $r['staged_avatar_path']); ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">
                                                    <form method="post" class="d-inline">
                                                        <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                                                        <input type="hidden" name="action" value="approve">
                                                        <input type="hidden" name="request_id" value="<?php echo (int) ($r['id'] ?? 0); ?>">
                                                        <input type="hidden" name="review_note" value="">
                                                        <button class="btn btn-sm btn-success" type="submit" onclick="return confirm('Approve this profile update?');">
                                                            <i class="ri-check-line me-1" aria-hidden="true"></i>
                                                            Approve
                                                        </button>
                                                    </form>
                                                    <form method="post" class="d-inline ms-1">
                                                        <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                                                        <input type="hidden" name="action" value="reject">
                                                        <input type="hidden" name="request_id" value="<?php echo (int) ($r['id'] ?? 0); ?>">
                                                        <input type="hidden" name="review_note" value="">
                                                        <button class="btn btn-sm btn-outline-danger" type="submit" onclick="return confirm('Reject this profile update?');">
                                                            <i class="ri-close-line me-1" aria-hidden="true"></i>
                                                            Reject
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
