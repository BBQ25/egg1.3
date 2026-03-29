<?php include __DIR__ . '/../../layouts/session.php'; ?>
<?php
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/login_click_bypass.php';
ensure_audit_logs_table($conn);
ensure_users_password_policy_columns($conn);
login_click_bypass_ensure_tables($conn);

if (!function_exists('post_login_redirect_by_role')) {
    function post_login_redirect_by_role($role) {
        $role = normalize_role((string) $role);
        if ($role === 'admin') return 'admin-dashboard.php';
        if ($role === 'teacher') return 'teacher-dashboard.php';
        if ($role === 'student') return 'student-dashboard.php';
        return 'index.php';
    }
}

if (!function_exists('auth_login_throttle_policy')) {
    function auth_login_throttle_policy(mysqli $conn, $scopeType) {
        $scopeType = strtolower(trim((string) $scopeType));
        $lockMinutes = function_exists('auth_login_lockout_get_minutes')
            ? auth_login_lockout_get_minutes($conn)
            : 20;
        $lockSeconds = max(60, ((int) $lockMinutes) * 60);
        if ($scopeType === 'ip') {
            return [
                'window_seconds' => 15 * 60,
                'max_failures' => 15,
                'lock_seconds' => $lockSeconds,
            ];
        }
        // login identifier scope
        return [
            'window_seconds' => 15 * 60,
            'max_failures' => 8,
            'lock_seconds' => $lockSeconds,
        ];
    }
}

if (!function_exists('auth_login_throttle_ensure_table')) {
    function auth_login_throttle_ensure_table(mysqli $conn) {
        static $done = false;
        if ($done) return;
        $done = true;

        $conn->query(
            "CREATE TABLE IF NOT EXISTS auth_login_throttle (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                scope_type VARCHAR(16) NOT NULL,
                scope_key CHAR(64) NOT NULL,
                fail_count INT NOT NULL DEFAULT 0,
                window_started_at DATETIME NULL DEFAULT NULL,
                locked_until DATETIME NULL DEFAULT NULL,
                last_failed_at DATETIME NULL DEFAULT NULL,
                last_success_at DATETIME NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_auth_login_scope (scope_type, scope_key),
                KEY idx_auth_login_locked (locked_until),
                KEY idx_auth_login_updated (updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}

if (!function_exists('auth_login_client_ip')) {
    function auth_login_client_ip() {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';
        if ($ip === '') $ip = '0.0.0.0';
        return $ip;
    }
}

if (!function_exists('auth_login_identifier_normalize')) {
    function auth_login_identifier_normalize($loginId) {
        $v = strtolower(trim((string) $loginId));
        if ($v === '') $v = 'unknown';
        return $v;
    }
}

if (!function_exists('auth_login_scope_hash')) {
    function auth_login_scope_hash($raw) {
        return hash('sha256', (string) $raw);
    }
}

if (!function_exists('auth_login_throttle_fetch_row')) {
    function auth_login_throttle_fetch_row(mysqli $conn, $scopeType, $scopeKeyHash) {
        auth_login_throttle_ensure_table($conn);
        $stmt = $conn->prepare(
            "SELECT id, fail_count, window_started_at, locked_until
             FROM auth_login_throttle
             WHERE scope_type = ?
               AND scope_key = ?
             LIMIT 1"
        );
        if (!$stmt) return null;
        $stmt->bind_param('ss', $scopeType, $scopeKeyHash);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
        $stmt->close();
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('auth_login_throttle_update_row')) {
    function auth_login_throttle_update_row(
        mysqli $conn,
        $scopeType,
        $scopeKeyHash,
        $failCount,
        $windowStartedAt,
        $lockedUntil,
        $lastFailedAt,
        $lastSuccessAt = null
    ) {
        auth_login_throttle_ensure_table($conn);
        $exists = auth_login_throttle_fetch_row($conn, $scopeType, $scopeKeyHash);

        if (is_array($exists)) {
            $stmt = $conn->prepare(
                "UPDATE auth_login_throttle
                 SET fail_count = ?,
                     window_started_at = ?,
                     locked_until = ?,
                     last_failed_at = ?,
                     last_success_at = COALESCE(?, last_success_at)
                 WHERE scope_type = ?
                   AND scope_key = ?
                 LIMIT 1"
            );
            if (!$stmt) return false;
            $stmt->bind_param(
                'issssss',
                $failCount,
                $windowStartedAt,
                $lockedUntil,
                $lastFailedAt,
                $lastSuccessAt,
                $scopeType,
                $scopeKeyHash
            );
            $ok = $stmt->execute();
            $stmt->close();
            return (bool) $ok;
        }

        $stmt = $conn->prepare(
            "INSERT INTO auth_login_throttle
                (scope_type, scope_key, fail_count, window_started_at, locked_until, last_failed_at, last_success_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) return false;
        $stmt->bind_param(
            'ssissss',
            $scopeType,
            $scopeKeyHash,
            $failCount,
            $windowStartedAt,
            $lockedUntil,
            $lastFailedAt,
            $lastSuccessAt
        );
        $ok = $stmt->execute();
        $stmt->close();
        return (bool) $ok;
    }
}

if (!function_exists('auth_login_throttle_check_lock')) {
    function auth_login_throttle_check_lock(mysqli $conn, $scopeType, $scopeRawKey, &$retryAfterSeconds = 0) {
        $retryAfterSeconds = 0;
        $scopeType = strtolower(trim((string) $scopeType));
        $scopeRawKey = trim((string) $scopeRawKey);
        if ($scopeType === '' || $scopeRawKey === '') return false;

        $row = auth_login_throttle_fetch_row($conn, $scopeType, auth_login_scope_hash($scopeRawKey));
        if (!is_array($row)) return false;

        $now = time();
        $lockedUntilTs = strtotime((string) ($row['locked_until'] ?? ''));
        if ($lockedUntilTs && $lockedUntilTs > $now) {
            $retryAfterSeconds = max(1, $lockedUntilTs - $now);
            return true;
        }
        return false;
    }
}

if (!function_exists('auth_login_throttle_check_any_lock')) {
    function auth_login_throttle_check_any_lock(mysqli $conn, $clientIp, $identifier, &$retryAfterSeconds = 0) {
        $retryAfterSeconds = 0;
        $ipWait = 0;
        $idWait = 0;
        $ipLocked = auth_login_throttle_check_lock($conn, 'ip', $clientIp, $ipWait);
        $idLocked = auth_login_throttle_check_lock($conn, 'identifier', $identifier, $idWait);
        if (!$ipLocked && !$idLocked) return false;
        $retryAfterSeconds = max((int) $ipWait, (int) $idWait);
        return true;
    }
}

if (!function_exists('auth_login_throttle_apply_failure')) {
    function auth_login_throttle_apply_failure(mysqli $conn, $scopeType, $scopeRawKey) {
        $scopeType = strtolower(trim((string) $scopeType));
        $scopeRawKey = trim((string) $scopeRawKey);
        if ($scopeType === '' || $scopeRawKey === '') return ['locked' => false, 'retry_after' => 0];

        auth_login_throttle_ensure_table($conn);
        $policy = auth_login_throttle_policy($conn, $scopeType);
        $scopeKeyHash = auth_login_scope_hash($scopeRawKey);
        $row = auth_login_throttle_fetch_row($conn, $scopeType, $scopeKeyHash);

        $nowTs = time();
        $now = date('Y-m-d H:i:s', $nowTs);
        $failCount = 1;
        $windowStartedAt = $now;
        $lockedUntil = null;
        $locked = false;
        $retryAfter = 0;

        if (is_array($row)) {
            $existingLockedTs = strtotime((string) ($row['locked_until'] ?? ''));
            if ($existingLockedTs && $existingLockedTs > $nowTs) {
                $locked = true;
                $retryAfter = max(1, $existingLockedTs - $nowTs);
                $lockedUntil = date('Y-m-d H:i:s', $existingLockedTs);
                $failCount = 0;
                $windowStartedAt = null;
            } else {
                $windowStartTs = strtotime((string) ($row['window_started_at'] ?? ''));
                $windowSeconds = (int) ($policy['window_seconds'] ?? 900);
                if (!$windowStartTs || ($nowTs - $windowStartTs) > $windowSeconds) {
                    $failCount = 1;
                    $windowStartedAt = $now;
                } else {
                    $failCount = ((int) ($row['fail_count'] ?? 0)) + 1;
                    $windowStartedAt = date('Y-m-d H:i:s', $windowStartTs);
                }

                if ($failCount >= (int) ($policy['max_failures'] ?? 8)) {
                    $locked = true;
                    $retryAfter = (int) ($policy['lock_seconds'] ?? 900);
                    $lockedUntil = date('Y-m-d H:i:s', $nowTs + $retryAfter);
                    $failCount = 0;
                    $windowStartedAt = null;
                }
            }
        }

        auth_login_throttle_update_row(
            $conn,
            $scopeType,
            $scopeKeyHash,
            $failCount,
            $windowStartedAt,
            $lockedUntil,
            $now
        );

        return [
            'locked' => $locked,
            'retry_after' => max(0, (int) $retryAfter),
        ];
    }
}

if (!function_exists('auth_login_throttle_register_failure')) {
    function auth_login_throttle_register_failure(mysqli $conn, $clientIp, $identifier) {
        $ip = auth_login_throttle_apply_failure($conn, 'ip', $clientIp);
        $id = auth_login_throttle_apply_failure($conn, 'identifier', $identifier);
        return [
            'locked' => !empty($ip['locked']) || !empty($id['locked']),
            'retry_after' => max((int) ($ip['retry_after'] ?? 0), (int) ($id['retry_after'] ?? 0)),
        ];
    }
}

if (!function_exists('auth_login_throttle_clear_success')) {
    function auth_login_throttle_clear_success(mysqli $conn, $clientIp, $identifier) {
        $now = date('Y-m-d H:i:s');
        auth_login_throttle_update_row($conn, 'ip', auth_login_scope_hash($clientIp), 0, null, null, null, $now);
        auth_login_throttle_update_row($conn, 'identifier', auth_login_scope_hash($identifier), 0, null, null, null, $now);
    }
}

if (!function_exists('auth_login_throttle_wait_text')) {
    function auth_login_throttle_wait_text($seconds) {
        $seconds = max(1, (int) $seconds);
        if ($seconds < 60) return '1 minute';
        $minutes = (int) ceil($seconds / 60);
        return $minutes . ' minute' . ($minutes === 1 ? '' : 's');
    }
}

$error = '';
$notice = '';
$loginId = '';

$reason = isset($_GET['reason']) ? strtolower(trim((string) $_GET['reason'])) : '';
if ($reason === 'timeout') {
    $notice = 'Your session expired due to inactivity. Please log in again.';
} elseif ($reason === 'logout') {
    $notice = 'You have been logged out.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    $loginId = isset($_POST['email']) ? trim((string) $_POST['email']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $clientIp = auth_login_client_ip();
    $throttleIdentifier = auth_login_identifier_normalize($loginId);
    $throttleRetryAfter = 0;
    $throttleTrackFailure = false;

    if (!csrf_validate($csrf)) {
        $error = 'Security check failed. Please refresh and try again.';
    } elseif ($loginId === '' || $password === '') {
        $error = 'Email/Student ID and password are required.';
    } elseif (auth_login_throttle_check_any_lock($conn, $clientIp, $throttleIdentifier, $throttleRetryAfter)) {
        $error = 'Too many login attempts. Try again in ' . auth_login_throttle_wait_text($throttleRetryAfter) . '.';
        audit_log($conn, 'auth.login.throttled', 'setting', null, 'Login attempt blocked by throttle.', [
            'ip' => $clientIp,
            'identifier_hash' => auth_login_scope_hash($throttleIdentifier),
            'retry_after_seconds' => (int) $throttleRetryAfter,
        ]);
    } else {
        $stmt = $conn->prepare(
            "SELECT id, useremail, username, password, role, is_active, must_change_password, campus_id, is_superadmin
             FROM users
             WHERE useremail = ? OR (username = ? AND role IN ('student', 'user'))
             LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param("ss", $loginId, $loginId);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $result->num_rows === 1) {
                $user = $result->fetch_assoc();
                if (!password_verify($password, $user['password'])) {
                    $error = 'Invalid email or password.';
                    $throttleTrackFailure = true;
                } else {
                    $role = isset($user['role']) ? $user['role'] : 'student';
                    $role = normalize_role($role);
                    $isActive = isset($user['is_active']) ? (int) $user['is_active'] : 0;
                    $campusId = isset($user['campus_id']) ? (int) $user['campus_id'] : 0;
                    $isSuperadmin = ((int) ($user['is_superadmin'] ?? 0) === 1) ? 1 : 0;
                    if ($role !== 'admin' && $isActive !== 1) {
                        $error = 'Your account is pending admin approval.';
                    } else {
                        auth_login_throttle_clear_success($conn, $clientIp, $throttleIdentifier);
                        session_regenerate_id(true);
                        $_SESSION['user_id'] = (int) $user['id'];
                        $_SESSION['user_email'] = $user['useremail'];
                        $_SESSION['user_name'] = $user['username'];
                        $_SESSION['user_role'] = $role;
                        $_SESSION['is_active'] = $isActive;
                        $_SESSION['campus_id'] = $campusId;
                        $_SESSION['is_superadmin'] = $isSuperadmin;
                        $_SESSION['force_password_change'] = ((int) ($user['must_change_password'] ?? 0) === 1) ? 1 : 0;
                        $_SESSION['last_activity_ts'] = time();
                        try {
                            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                        } catch (Throwable $e) {
                            unset($_SESSION['csrf_token']);
                            csrf_token();
                        }
                        unset($_SESSION['student_no'], $_SESSION['student_name'], $_SESSION['student_section']);

                        audit_log($conn, 'auth.login', 'user', (int) $user['id'], null);

                        if (!empty($_SESSION['force_password_change'])) {
                            header('Location: auth-force-password.php');
                        } else {
                            header('Location: ' . post_login_redirect_by_role($role));
                        }
                        exit;
                    }
                }
            } else {
                // Use a single generic failure message to reduce account-enumeration signals.
                $error = 'Invalid email or password.';
                $throttleTrackFailure = true;
            }
            $stmt->close();
        } else {
            $error = 'Unable to prepare login query.';
        }

        if ($throttleTrackFailure) {
            $result = auth_login_throttle_register_failure($conn, $clientIp, $throttleIdentifier);
            if (!empty($result['locked'])) {
                $waitSeconds = (int) ($result['retry_after'] ?? 0);
                if ($waitSeconds > 0) {
                    $error = 'Too many login attempts. Try again in ' . auth_login_throttle_wait_text($waitSeconds) . '.';
                }
                audit_log($conn, 'auth.login.locked', 'setting', null, 'Login lock applied after repeated failures.', [
                    'ip' => $clientIp,
                    'identifier_hash' => auth_login_scope_hash($throttleIdentifier),
                    'retry_after_seconds' => max(0, $waitSeconds),
                ]);
            }
        }
    }
}

$clickBypassRules = login_click_bypass_fetch_public_rules($conn);
?>

<?php include __DIR__ . '/../../layouts/main.php'; ?>

<head>
    <title>Log In | Attex - Bootstrap 5 Admin & Dashboard Template</title>
    <?php include __DIR__ . '/../../layouts/title-meta.php'; ?>

    <?php include __DIR__ . '/../../layouts/head-css.php'; ?>
    <style>
        .login-secret-ripple {
            position: fixed;
            left: 0;
            top: 0;
            width: 12px;
            height: 12px;
            border-radius: 999px;
            border: 2px solid rgba(13, 110, 253, 0.50);
            background: rgba(13, 110, 253, 0.14);
            transform: translate(-50%, -50%) scale(0.2);
            pointer-events: none;
            z-index: 9999;
            animation: login-secret-ripple-wave 460ms ease-out forwards;
            box-shadow: 0 0 0 0 rgba(13, 110, 253, 0.22);
        }
        @keyframes login-secret-ripple-wave {
            0% {
                opacity: 0.72;
                transform: translate(-50%, -50%) scale(0.2);
                box-shadow: 0 0 0 0 rgba(13, 110, 253, 0.22);
            }
            75% {
                opacity: 0.20;
                box-shadow: 0 0 0 12px rgba(13, 110, 253, 0.09);
            }
            100% {
                opacity: 0;
                transform: translate(-50%, -50%) scale(5.4);
                box-shadow: 0 0 0 16px rgba(13, 110, 253, 0);
            }
        }
    </style>
</head>

<body class="authentication-bg position-relative">

<?php include __DIR__ . '/../../layouts/background.php'; ?>

    <div class="account-pages pt-2 pt-sm-5 pb-4 pb-sm-5 position-relative">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-xxl-4 col-lg-5">
                    <div class="card" id="login-card">

                        <!-- Logo -->
                        <div class="card-header py-4 text-center bg-primary">
                            <a href="index.php">
                                <span><img src="assets/images/logo.png" alt="logo" height="30"></span>
                            </a>
                        </div>

                        <div class="card-body p-4">

                            <div class="text-center w-75 m-auto">
                                <h4 class="text-dark-50 text-center pb-0 fw-bold">Sign In</h4>
                                <p class="text-muted mb-4">Enter your email or Student ID and password to access the portal.</p>
                            </div>

                            <?php if ($error): ?>
                                <div class="alert alert-danger" role="alert">
                                    <?php echo htmlspecialchars($error); ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($notice): ?>
                                <div class="alert alert-warning" role="alert">
                                    <?php echo htmlspecialchars($notice); ?>
                                </div>
                            <?php endif; ?>

                            <form action="auth-login.php" method="post">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">

                                <div class="mb-3">
                                    <label for="email" class="form-label">Email or Student ID</label>
                                    <input class="form-control" type="text" id="email" name="email" required placeholder="name@example.com or 2410001-1" value="<?php echo htmlspecialchars($loginId); ?>">
                                </div>

                                <div class="mb-3">
                                    <label for="password" class="form-label">Password</label>
                                    <input class="form-control" type="password" id="password" name="password" required placeholder="Enter your password">
                                </div>

                                <div class="mb-3 mb-0 text-center">
                                    <button class="btn btn-primary" type="submit"> Log In </button>
                                </div>

                            </form>
                        </div> <!-- end card-body -->
                    </div>
                    <!-- end card -->

                            <div class="row mt-3">
                        <div class="col-12 text-center">
                            <p class="text-muted bg-body">Don't have an account? <a href="auth-register.php" class="text-muted ms-1 link-offset-3 text-decoration-underline"><b>Sign Up</b></a></p>
                            <p class="text-muted bg-body mb-0">Student self-enrollment: <a href="auth-student-enroll.php" class="text-muted ms-1 link-offset-3 text-decoration-underline"><b>Enroll Here</b></a></p>
                            <p class="text-muted bg-body mb-0">Forgot password? Contact the admin to reset your password.</p>
                        </div> <!-- end col -->
                    </div>
                    <!-- end row -->

                </div> <!-- end col -->
            </div>
            <!-- end row -->
        </div>
        <!-- end container -->
    </div>
    <!-- end page -->

    <footer class="footer footer-alt fw-medium">
        <span class="bg-body">
            2026 @ Ryhn Solutions
        </span>
    </footer>
    <?php include __DIR__ . '/../../layouts/footer-scripts.php'; ?>

    <script>
    (function () {
        var rules = <?php echo json_encode($clickBypassRules, JSON_UNESCAPED_SLASHES); ?>;
        if (!Array.isArray(rules) || rules.length === 0) return;

        rules = rules
            .map(function (r) {
                return {
                    click_count: Number(r && r.click_count ? r.click_count : 0),
                    window_seconds: Number(r && r.window_seconds ? r.window_seconds : 0)
                };
            })
            .filter(function (r) {
                return r.click_count >= 2 && r.window_seconds > 0;
            })
            .sort(function (a, b) {
                if (a.click_count !== b.click_count) return b.click_count - a.click_count;
                return a.window_seconds - b.window_seconds;
            });
        if (rules.length === 0) return;

        var maxWindowMs = 0;
        for (var i = 0; i < rules.length; i++) {
            var wm = Math.round(rules[i].window_seconds * 1000);
            if (wm > maxWindowMs) maxWindowMs = wm;
        }
        if (maxWindowMs <= 0) return;

        var csrf = <?php echo json_encode(csrf_token(), JSON_UNESCAPED_SLASHES); ?>;
        var card = document.getElementById('login-card');
        var clickTimes = [];
        var pending = false;
        var deferredTimer = 0;

        function spawnSecretRipple(e) {
            var x = Number(e && e.clientX);
            var y = Number(e && e.clientY);
            if (!Number.isFinite(x) || !Number.isFinite(y)) return;

            var ripple = document.createElement('span');
            ripple.className = 'login-secret-ripple';
            ripple.style.left = String(Math.round(x)) + 'px';
            ripple.style.top = String(Math.round(y)) + 'px';
            document.body.appendChild(ripple);

            window.setTimeout(function () {
                if (ripple && ripple.parentNode) ripple.parentNode.removeChild(ripple);
            }, 560);
        }

        function prune(now) {
            var kept = [];
            for (var i = 0; i < clickTimes.length; i++) {
                if ((now - clickTimes[i]) <= maxWindowMs) kept.push(clickTimes[i]);
            }
            clickTimes = kept;
        }

        function clearDeferredTimer() {
            if (!deferredTimer) return;
            window.clearTimeout(deferredTimer);
            deferredTimer = 0;
        }

        function attemptRule(rule, elapsedMs) {
            if (pending) return;
            clearDeferredTimer();
            pending = true;

            var body = new URLSearchParams();
            body.set('csrf_token', String(csrf || ''));
            body.set('click_count', String(Math.round(rule.click_count)));
            body.set('duration_ms', String(Math.max(0, Math.round(elapsedMs))));

            fetch('includes/login_click_bypass_action.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: body.toString()
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data && data.ok && data.redirect) {
                    window.location.href = String(data.redirect);
                    return;
                }
                pending = false;
            })
            .catch(function () {
                pending = false;
            });
        }

        function findMatchedRule(now) {
            for (var i = 0; i < rules.length; i++) {
                var rule = rules[i];
                var needed = Math.round(rule.click_count);
                var windowMs = Math.round(rule.window_seconds * 1000);
                if (needed < 2 || windowMs <= 0) continue;
                if (clickTimes.length < needed) continue;

                var start = clickTimes[clickTimes.length - needed];
                var elapsed = now - start;
                if (elapsed <= windowMs) {
                    return {
                        rule: rule,
                        elapsed: elapsed
                    };
                }
            }
            return null;
        }

        function getDeferralMs(candidate, now) {
            if (!candidate || !candidate.rule || clickTimes.length === 0) return 0;

            var candidateCount = Math.round(candidate.rule.click_count);
            var candidateWindowMs = Math.round(candidate.rule.window_seconds * 1000);
            var candidateRemaining = candidateWindowMs - Math.max(0, Math.round(candidate.elapsed));
            if (candidateRemaining <= 0) return 0;

            var nextHigherCount = 0;
            for (var i = 0; i < rules.length; i++) {
                var count = Math.round(rules[i].click_count);
                if (count <= candidateCount) continue;
                if (nextHigherCount === 0 || count < nextHigherCount) nextHigherCount = count;
            }
            if (nextHigherCount <= 0) return 0;
            if (clickTimes.length >= nextHigherCount) return 0;

            var oldest = clickTimes[0];
            if (!oldest) return 0;

            var higherRemaining = 0;
            for (var j = 0; j < rules.length; j++) {
                var higherRule = rules[j];
                var higherCount = Math.round(higherRule.click_count);
                if (higherCount !== nextHigherCount) continue;

                var higherWindowMs = Math.round(higherRule.window_seconds * 1000);
                if (higherWindowMs <= 0) continue;

                var remaining = higherWindowMs - (now - oldest);
                if (remaining <= 0) continue;
                if (higherRemaining === 0 || remaining < higherRemaining) higherRemaining = remaining;
            }
            if (higherRemaining <= 0) return 0;
            return Math.min(candidateRemaining, higherRemaining);
        }

        function scheduleRecheck(delayMs) {
            clearDeferredTimer();
            var ms = Math.max(20, Math.round(delayMs) - 30);
            deferredTimer = window.setTimeout(function () {
                deferredTimer = 0;
                if (pending) return;
                var now = Date.now();
                prune(now);
                evaluateAndAttempt(now);
            }, ms);
        }

        function evaluateAndAttempt(now) {
            var candidate = findMatchedRule(now);
            if (!candidate) return;

            var deferMs = getDeferralMs(candidate, now);
            if (deferMs > 40) {
                scheduleRecheck(deferMs);
                return;
            }

            clickTimes = [];
            attemptRule(candidate.rule, candidate.elapsed);
        }

        document.addEventListener('click', function (e) {
            if (pending) return;
            if (card && e.target && card.contains(e.target)) return;

            spawnSecretRipple(e);
            var now = Date.now();
            clickTimes.push(now);
            prune(now);
            clearDeferredTimer();
            evaluateAndAttempt(now);
        }, true);
    })();
    </script>

    <!-- App js -->
    <script src="assets/js/app.min.js"></script>

</body>

</html>


