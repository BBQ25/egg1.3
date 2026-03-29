<?php include '../layouts/session.php'; ?>
<?php require_role('superadmin'); ?>

<?php
require_once __DIR__ . '/../includes/audit.php';
ensure_audit_logs_table($conn);

$superadminId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$flash = isset($_SESSION['flash_message']) ? (string) $_SESSION['flash_message'] : '';
$flashType = isset($_SESSION['flash_type']) ? (string) $_SESSION['flash_type'] : 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

if (!function_exists('campus_h')) {
    function campus_h($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('campus_user_label')) {
    function campus_user_label(array $row) {
        $full = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
        if ($full !== '') return $full;
        return trim((string) ($row['username'] ?? 'User'));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    if (!csrf_validate($csrf)) {
        $_SESSION['flash_message'] = 'Security check failed (CSRF). Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: admin-campuses.php');
        exit;
    }

    $action = isset($_POST['action']) ? trim((string) $_POST['action']) : '';

    if ($action === 'create_campus') {
        $campusCode = strtoupper(trim((string) ($_POST['campus_code'] ?? '')));
        $campusName = trim((string) ($_POST['campus_name'] ?? ''));
        $isActive = !empty($_POST['is_active']) ? 1 : 0;

        if ($campusCode === '' || $campusName === '') {
            $_SESSION['flash_message'] = 'Campus code and campus name are required.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: admin-campuses.php');
            exit;
        }
        if (strlen($campusCode) > 40 || strlen($campusName) > 160) {
            $_SESSION['flash_message'] = 'Campus code or name is too long.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: admin-campuses.php');
            exit;
        }

        $ins = $conn->prepare(
            "INSERT INTO campuses (campus_code, campus_name, is_active)
             VALUES (?, ?, ?)"
        );
        if ($ins) {
            $ins->bind_param('ssi', $campusCode, $campusName, $isActive);
            $ok = false;
            try {
                $ok = $ins->execute();
            } catch (Throwable $e) {
                $ok = false;
            }
            $newCampusId = $ok ? (int) $conn->insert_id : 0;
            $ins->close();

            if ($ok && $newCampusId > 0) {
                $_SESSION['flash_message'] = 'Campus created.';
                $_SESSION['flash_type'] = 'success';
                audit_log($conn, 'campus.created', 'campus', $newCampusId, 'Campus created by superadmin.');
            } else {
                $_SESSION['flash_message'] = 'Unable to create campus. Code or name may already exist.';
                $_SESSION['flash_type'] = 'danger';
            }
        } else {
            $_SESSION['flash_message'] = 'Unable to create campus.';
            $_SESSION['flash_type'] = 'danger';
        }

        header('Location: admin-campuses.php');
        exit;
    }

    if ($action === 'assign_campus_admin') {
        $campusId = isset($_POST['campus_id']) ? (int) $_POST['campus_id'] : 0;
        $userId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;

        if ($campusId <= 0 || $userId <= 0) {
            $_SESSION['flash_message'] = 'Campus and user are required.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: admin-campuses.php');
            exit;
        }

        $campusExists = false;
        $checkCampus = $conn->prepare("SELECT id FROM campuses WHERE id = ? LIMIT 1");
        if ($checkCampus) {
            $checkCampus->bind_param('i', $campusId);
            $checkCampus->execute();
            $res = $checkCampus->get_result();
            $campusExists = ($res && $res->num_rows === 1);
            $checkCampus->close();
        }
        if (!$campusExists) {
            $_SESSION['flash_message'] = 'Campus not found.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: admin-campuses.php');
            exit;
        }

        $targetUser = null;
        $userStmt = $conn->prepare(
            "SELECT id, role, is_superadmin, campus_id
             FROM users
             WHERE id = ?
             LIMIT 1"
        );
        if ($userStmt) {
            $userStmt->bind_param('i', $userId);
            $userStmt->execute();
            $res = $userStmt->get_result();
            if ($res && $res->num_rows === 1) $targetUser = $res->fetch_assoc();
            $userStmt->close();
        }

        if (!is_array($targetUser) || ((int) ($targetUser['is_superadmin'] ?? 0) === 1)) {
            $_SESSION['flash_message'] = 'Selected user cannot be assigned as campus admin.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: admin-campuses.php');
            exit;
        }

        $existingCampusAdminId = 0;
        $existingStmt = $conn->prepare(
            "SELECT id
             FROM users
             WHERE role = 'admin'
               AND is_superadmin = 0
               AND campus_id = ?
             ORDER BY id ASC
             LIMIT 1"
        );
        if ($existingStmt) {
            $existingStmt->bind_param('i', $campusId);
            $existingStmt->execute();
            $res = $existingStmt->get_result();
            if ($res && $res->num_rows === 1) {
                $existingCampusAdminId = (int) (($res->fetch_assoc()['id'] ?? 0));
            }
            $existingStmt->close();
        }

        if ($existingCampusAdminId > 0 && $existingCampusAdminId !== $userId) {
            $_SESSION['flash_message'] = 'This campus already has a campus admin. Update that account first.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: admin-campuses.php');
            exit;
        }

        $targetRole = strtolower(trim((string) ($targetUser['role'] ?? '')));
        $targetCampusId = (int) ($targetUser['campus_id'] ?? 0);
        if ($targetRole === 'admin' && $targetCampusId > 0 && $targetCampusId !== $campusId) {
            $_SESSION['flash_message'] = 'Selected user is already a campus admin of another campus.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: admin-campuses.php');
            exit;
        }

        $upd = $conn->prepare(
            "UPDATE users
             SET role = 'admin',
                 campus_id = ?,
                 is_superadmin = 0
             WHERE id = ?
               AND is_superadmin = 0
             LIMIT 1"
        );
        if ($upd) {
            $upd->bind_param('ii', $campusId, $userId);
            $ok = false;
            try {
                $ok = $upd->execute();
            } catch (Throwable $e) {
                $ok = false;
            }
            $upd->close();

            if ($ok) {
                if (function_exists('session_table_exists') && function_exists('session_table_has_column')) {
                    if (session_table_exists($conn, 'students') && session_table_has_column($conn, 'students', 'campus_id')) {
                        $syncStudent = $conn->prepare("UPDATE students SET campus_id = ? WHERE user_id = ?");
                        if ($syncStudent) {
                            $syncStudent->bind_param('ii', $campusId, $userId);
                            $syncStudent->execute();
                            $syncStudent->close();
                        }
                    }
                    if (session_table_exists($conn, 'teachers') && session_table_has_column($conn, 'teachers', 'campus_id')) {
                        $syncTeacher = $conn->prepare("UPDATE teachers SET campus_id = ? WHERE user_id = ?");
                        if ($syncTeacher) {
                            $syncTeacher->bind_param('ii', $campusId, $userId);
                            $syncTeacher->execute();
                            $syncTeacher->close();
                        }
                    }
                }

                $_SESSION['flash_message'] = 'Campus admin assigned.';
                $_SESSION['flash_type'] = 'success';
                audit_log($conn, 'campus.admin.assigned', 'user', $userId, 'Assigned as campus admin.', ['campus_id' => $campusId]);
            } else {
                $_SESSION['flash_message'] = 'Unable to assign campus admin.';
                $_SESSION['flash_type'] = 'danger';
            }
        } else {
            $_SESSION['flash_message'] = 'Unable to assign campus admin.';
            $_SESSION['flash_type'] = 'danger';
        }

        header('Location: admin-campuses.php');
        exit;
    }

    $_SESSION['flash_message'] = 'Invalid request.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: admin-campuses.php');
    exit;
}

$campuses = [];
$campusQuery = $conn->query(
    "SELECT c.id,
            c.campus_code,
            c.campus_name,
            c.is_active,
            c.created_at,
            u.id AS campus_admin_id,
            u.username AS campus_admin_username,
            u.useremail AS campus_admin_email,
            u.first_name AS campus_admin_first_name,
            u.last_name AS campus_admin_last_name,
            u.is_active AS campus_admin_is_active
     FROM campuses c
     LEFT JOIN users u
       ON u.id = (
            SELECT ux.id
            FROM users ux
            WHERE ux.role = 'admin'
              AND ux.is_superadmin = 0
              AND ux.campus_id = c.id
            ORDER BY ux.id ASC
            LIMIT 1
       )
     ORDER BY c.campus_name ASC"
);
if ($campusQuery) {
    while ($row = $campusQuery->fetch_assoc()) {
        $campuses[] = $row;
    }
}

$assignableUsers = [];
$assignUserQuery = $conn->query(
    "SELECT id, username, useremail, first_name, last_name, role, is_active
     FROM users
     WHERE is_superadmin = 0
       AND role <> 'admin'
     ORDER BY first_name ASC, last_name ASC, username ASC
     LIMIT 500"
);
if ($assignUserQuery) {
    while ($row = $assignUserQuery->fetch_assoc()) {
        $assignableUsers[] = $row;
    }
}
?>

<?php include '../layouts/main.php'; ?>

<head>
    <title>Campus Management | E-Record</title>
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
                                    <li class="breadcrumb-item active">Campus Management</li>
                                </ol>
                            </div>
                            <h4 class="page-title">Campus Management</h4>
                        </div>
                    </div>
                </div>

                <?php if ($flash): ?>
                    <div class="alert alert-<?php echo campus_h($flashType); ?>"><?php echo campus_h($flash); ?></div>
                <?php endif; ?>

                <div class="row g-3">
                    <div class="col-lg-5">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="header-title mb-2">Create Campus</h5>
                                <p class="text-muted small mb-3">Create a campus before assigning a campus admin.</p>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?php echo campus_h(csrf_token()); ?>">
                                    <input type="hidden" name="action" value="create_campus">

                                    <div class="mb-2">
                                        <label class="form-label">Campus Code</label>
                                        <input type="text" class="form-control" name="campus_code" maxlength="40" required placeholder="e.g. MAIN / NORTH">
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label">Campus Name</label>
                                        <input type="text" class="form-control" name="campus_name" maxlength="160" required placeholder="e.g. Main Campus">
                                    </div>
                                    <div class="form-check mb-3">
                                        <input class="form-check-input" type="checkbox" value="1" id="campusIsActive" name="is_active" checked>
                                        <label class="form-check-label" for="campusIsActive">Active campus</label>
                                    </div>
                                    <button class="btn btn-primary" type="submit">
                                        <i class="ri-add-line me-1"></i>Create Campus
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-7">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="header-title mb-2">Assign Campus Admin</h5>
                                <p class="text-muted small mb-3">Each campus can only have one campus admin account.</p>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?php echo campus_h(csrf_token()); ?>">
                                    <input type="hidden" name="action" value="assign_campus_admin">

                                    <div class="mb-2">
                                        <label class="form-label">Campus</label>
                                        <select class="form-select" name="campus_id" required>
                                            <option value="">Select campus</option>
                                            <?php foreach ($campuses as $campus): ?>
                                                <option value="<?php echo (int) ($campus['id'] ?? 0); ?>">
                                                    <?php echo campus_h((string) ($campus['campus_code'] ?? '')); ?> - <?php echo campus_h((string) ($campus['campus_name'] ?? '')); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">User to Promote as Campus Admin</label>
                                        <select class="form-select" name="user_id" required>
                                            <option value="">Select user</option>
                                            <?php foreach ($assignableUsers as $u): ?>
                                                <?php
                                                $label = campus_user_label($u);
                                                $role = ucfirst((string) ($u['role'] ?? ''));
                                                $status = ((int) ($u['is_active'] ?? 0) === 1) ? 'Active' : 'Pending';
                                                ?>
                                                <option value="<?php echo (int) ($u['id'] ?? 0); ?>">
                                                    <?php echo campus_h($label); ?> | <?php echo campus_h((string) ($u['useremail'] ?? '')); ?> | <?php echo campus_h($role); ?> | <?php echo campus_h($status); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if (count($assignableUsers) === 0): ?>
                                            <div class="text-muted small mt-1">No non-admin users available to promote.</div>
                                        <?php endif; ?>
                                    </div>

                                    <button class="btn btn-success" type="submit">
                                        <i class="ri-user-settings-line me-1"></i>Assign Campus Admin
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mt-3">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <h5 class="header-title mb-0">Campuses</h5>
                            <div class="text-muted small">Total: <strong><?php echo (int) count($campuses); ?></strong></div>
                        </div>

                        <div class="table-responsive mt-3">
                            <table class="table table-sm table-striped table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Campus</th>
                                        <th>Status</th>
                                        <th>Campus Admin</th>
                                        <th>Created</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($campuses) === 0): ?>
                                        <tr>
                                            <td colspan="4" class="text-center text-muted py-4">No campuses found.</td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php foreach ($campuses as $c): ?>
                                        <?php
                                        $adminName = trim((string) ($c['campus_admin_first_name'] ?? '') . ' ' . (string) ($c['campus_admin_last_name'] ?? ''));
                                        if ($adminName === '') $adminName = trim((string) ($c['campus_admin_username'] ?? ''));
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="fw-semibold"><?php echo campus_h((string) ($c['campus_name'] ?? '')); ?></div>
                                                <div class="text-muted small"><?php echo campus_h((string) ($c['campus_code'] ?? '')); ?></div>
                                            </td>
                                            <td>
                                                <?php if ((int) ($c['is_active'] ?? 0) === 1): ?>
                                                    <span class="badge bg-success">Active</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ((int) ($c['campus_admin_id'] ?? 0) > 0): ?>
                                                    <div class="fw-semibold"><?php echo campus_h($adminName !== '' ? $adminName : 'Campus Admin'); ?></div>
                                                    <div class="text-muted small"><?php echo campus_h((string) ($c['campus_admin_email'] ?? '')); ?></div>
                                                    <?php if ((int) ($c['campus_admin_is_active'] ?? 0) === 1): ?>
                                                        <span class="badge bg-success-subtle text-success">Active</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning-subtle text-warning">Pending</span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">No campus admin assigned</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-muted small"><?php echo campus_h((string) ($c['created_at'] ?? '')); ?></td>
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
