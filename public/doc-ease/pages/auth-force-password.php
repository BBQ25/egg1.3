<?php include __DIR__ . '/../layouts/session.php'; ?>
<?php
require_once __DIR__ . '/../includes/audit.php';
ensure_audit_logs_table($conn);
ensure_users_password_policy_columns($conn);

if (!function_exists('force_password_redirect_by_role')) {
    function force_password_redirect_by_role($role) {
        $role = normalize_role((string) $role);
        if ($role === 'admin') return 'admin-dashboard.php';
        if ($role === 'teacher') return 'teacher-dashboard.php';
        if ($role === 'student') return 'student-dashboard.php';
        return 'index.php';
    }
}

if (!isset($_SESSION['user_id'])) {
    header('Location: auth-login.php');
    exit;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$role = normalize_role((string) ($_SESSION['user_role'] ?? ''));
if ($userId <= 0 || $role === '') {
    $_SESSION = [];
    if (ini_get('session.use_cookies') && !headers_sent()) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'] ?? '/',
            $params['domain'] ?? '',
            !empty($params['secure']),
            !empty($params['httponly'])
        );
    }
    @session_destroy();
    header('Location: auth-login.php?reason=logout');
    exit;
}

if (empty($_SESSION['force_password_change'])) {
    header('Location: ' . force_password_redirect_by_role($role));
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    $currentPassword = isset($_POST['current_password']) ? (string) $_POST['current_password'] : '';
    $newPassword = isset($_POST['new_password']) ? (string) $_POST['new_password'] : '';
    $confirmPassword = isset($_POST['confirm_password']) ? (string) $_POST['confirm_password'] : '';

    if (!csrf_validate($csrf)) {
        $error = 'Security check failed (CSRF). Please try again.';
    } elseif ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
        $error = 'Please fill in all password fields.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'New password confirmation does not match.';
    } elseif (strlen($newPassword) < 8) {
        $error = 'New password must be at least 8 characters.';
    } else {
        $stmt = $conn->prepare("SELECT password, must_change_password FROM users WHERE id = ? LIMIT 1");
        if (!$stmt) {
            $error = 'Unable to validate your account. Please try again.';
        } else {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
            $stmt->close();

            if (!$row) {
                $error = 'Account not found.';
            } elseif (!password_verify($currentPassword, (string) ($row['password'] ?? ''))) {
                $error = 'Current password is incorrect.';
            } elseif (password_verify($newPassword, (string) ($row['password'] ?? ''))) {
                $error = 'New password must be different from your current password.';
            } else {
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                $upd = $conn->prepare("UPDATE users SET password = ?, must_change_password = 0, password_changed_at = NOW() WHERE id = ? LIMIT 1");
                if (!$upd) {
                    $error = 'Unable to update password. Please try again.';
                } else {
                    $upd->bind_param('si', $hash, $userId);
                    if ($upd->execute()) {
                        session_regenerate_id(true);
                        try {
                            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                        } catch (Throwable $e) {
                            unset($_SESSION['csrf_token']);
                            csrf_token();
                        }
                        $_SESSION['force_password_change'] = 0;
                        audit_log($conn, 'auth.password.changed', 'user', $userId, null);
                        $success = 'Password changed successfully. Redirecting...';
                        header('Refresh: 1; url=' . force_password_redirect_by_role($role));
                    } else {
                        $error = 'Unable to update password. Please try again.';
                    }
                    $upd->close();
                }
            }
        }
    }
}
?>

<?php include __DIR__ . '/../layouts/main.php'; ?>

<head>
    <title>Change Password | E-Record</title>
    <?php include __DIR__ . '/../layouts/title-meta.php'; ?>
    <?php include __DIR__ . '/../layouts/head-css.php'; ?>
</head>

<body class="authentication-bg position-relative">

<?php include __DIR__ . '/../layouts/background.php'; ?>

    <div class="account-pages pt-2 pt-sm-5 pb-4 pb-sm-5 position-relative">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-xxl-4 col-lg-5">
                    <div class="card">
                        <div class="card-header py-4 text-center bg-primary">
                            <a href="javascript:void(0)">
                                <span><img src="assets/images/logo.png" alt="logo" height="30"></span>
                            </a>
                        </div>

                        <div class="card-body p-4">
                            <div class="text-center w-75 m-auto">
                                <h4 class="text-dark-50 text-center pb-0 fw-bold">Change Default Password</h4>
                                <p class="text-muted mb-4">For security, you must set a new password before continuing.</p>
                            </div>

                            <?php if ($error): ?>
                                <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($error); ?></div>
                            <?php endif; ?>
                            <?php if ($success): ?>
                                <div class="alert alert-success" role="alert"><?php echo htmlspecialchars($success); ?></div>
                            <?php endif; ?>

                            <form action="auth-force-password.php" method="post" autocomplete="off">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">

                                <div class="mb-3">
                                    <label for="current_password" class="form-label">Current Password</label>
                                    <input class="form-control" type="password" id="current_password" name="current_password" required>
                                </div>

                                <div class="mb-3">
                                    <label for="new_password" class="form-label">New Password</label>
                                    <input class="form-control" type="password" id="new_password" name="new_password" minlength="8" required>
                                    <small class="text-muted">Minimum 8 characters.</small>
                                </div>

                                <div class="mb-3">
                                    <label for="confirm_password" class="form-label">Confirm New Password</label>
                                    <input class="form-control" type="password" id="confirm_password" name="confirm_password" minlength="8" required>
                                </div>

                                <div class="mb-0 text-center">
                                    <button class="btn btn-primary" type="submit">Update Password</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="row mt-3">
                        <div class="col-12 text-center">
                            <p class="text-muted bg-body mb-0">Need to sign out?</p>
                            <form action="auth-logout.php" method="post" class="d-inline-block mt-1">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="reason" value="logout">
                                <button type="submit" class="btn btn-link text-muted ms-1 link-offset-3 text-decoration-underline p-0">
                                    <b>Log Out</b>
                                </button>
                            </form>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <footer class="footer footer-alt fw-medium">
        <span class="bg-body">2026 @ Ryhn Solutions</span>
    </footer>

    <?php include __DIR__ . '/../layouts/footer-scripts.php'; ?>
    <script src="assets/js/app.min.js"></script>
</body>

</html>
