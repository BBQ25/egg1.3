<?php
if (!function_exists('app_request_is_https')) {
    function app_request_is_https() {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') return true;
        if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) return true;
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') return true;
        if (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') return true;
        return false;
    }
}

// Initialize the session with hardened cookie/security settings.
if (session_status() !== PHP_SESSION_ACTIVE) {
    $isHttps = app_request_is_https();

    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.use_only_cookies', '1');
    @ini_set('session.cookie_httponly', '1');
    @ini_set('session.cookie_secure', $isHttps ? '1' : '0');

    if (PHP_VERSION_ID >= 70300) {
        @ini_set('session.cookie_samesite', 'Lax');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        // Best effort for pre-7.3 runtimes.
        session_set_cookie_params(0, '/; samesite=Lax', '', $isHttps, true);
    }

    session_start();
}

// Use Philippine Standard Time across the app.
// Must run before any date()/strtotime() usage to keep behavior consistent.
if (!function_exists('app_bootstrap_timezone')) {
    function app_bootstrap_timezone() {
        @date_default_timezone_set('Asia/Manila');
    }
}
app_bootstrap_timezone();

// Optional environment helper shared with hardening/bootstrap modules.
$envSecretsPath = __DIR__ . '/../includes/env_secrets.php';
if (is_file($envSecretsPath)) {
    require_once $envSecretsPath;
}

if (!function_exists('normalize_role')) {
    // Backward-compatible role normalization. Historically we used `user` for student accounts.
    function normalize_role($role) {
        $role = strtolower(trim((string) $role));
        if ($role === 'user') return 'student';
        return $role;
    }
}

if (!function_exists('doc_ease_session_env_value')) {
    function doc_ease_session_env_value($name, $default = '') {
        $name = trim((string) $name);
        if ($name === '') return (string) $default;

        if (function_exists('doc_ease_env_value')) {
            $fromHelper = trim((string) doc_ease_env_value($name));
            if ($fromHelper !== '') return $fromHelper;
        }

        $fromGetenv = getenv($name);
        if (is_string($fromGetenv) && trim($fromGetenv) !== '') {
            return trim($fromGetenv);
        }

        if (isset($_ENV[$name])) {
            $fromEnv = trim((string) $_ENV[$name]);
            if ($fromEnv !== '') return $fromEnv;
        }

        return (string) $default;
    }
}

if (!function_exists('doc_ease_session_env_bool')) {
    function doc_ease_session_env_bool($name, $default = false) {
        $raw = strtolower(trim((string) doc_ease_session_env_value($name, '')));
        if ($raw === '') return (bool) $default;
        if (in_array($raw, ['1', 'true', 'yes', 'on'], true)) return true;
        if (in_array($raw, ['0', 'false', 'no', 'off'], true)) return false;
        return (bool) $default;
    }
}

if (!function_exists('doc_ease_direct_lock_enabled')) {
    function doc_ease_direct_lock_enabled() {
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') return false;
        return doc_ease_session_env_bool('DOC_EASE_DIRECT_PATH_LOCK', false);
    }
}

if (!function_exists('doc_ease_bridge_marker_present')) {
    function doc_ease_bridge_marker_present() {
        return !empty($_SESSION['doc_ease_bridge_verified']) && (int) $_SESSION['doc_ease_bridge_verified'] === 1;
    }
}

if (!function_exists('doc_ease_bridge_lock_bypass')) {
    function doc_ease_bridge_lock_bypass() {
        return defined('DOC_EASE_BRIDGE_BYPASS_SESSION_LOCK') && DOC_EASE_BRIDGE_BYPASS_SESSION_LOCK;
    }
}

if (!function_exists('doc_ease_gateway_target')) {
    function doc_ease_gateway_target() {
        $path = trim((string) doc_ease_session_env_value('DOC_EASE_LARAVEL_GATEWAY_PATH', '/legacy/doc-ease'));
        if ($path === '') $path = '/legacy/doc-ease';
        if (preg_match('#^https?://#i', $path)) return $path;

        $path = '/' . ltrim(str_replace('\\', '/', $path), '/');
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        if (strpos($path, '..') !== false) return '/legacy/doc-ease';
        return $path;
    }
}

if (!function_exists('doc_ease_append_query')) {
    function doc_ease_append_query($url, array $params) {
        $url = trim((string) $url);
        if ($url === '') return '';
        if (count($params) < 1) return $url;

        $query = http_build_query($params);
        if ($query === '' || $query === '0') return $url;
        return $url . (strpos($url, '?') === false ? '?' : '&') . $query;
    }
}

if (!function_exists('doc_ease_redirect_to_gateway')) {
    function doc_ease_redirect_to_gateway($reason = 'bridge_required') {
        $reason = trim((string) $reason);
        if ($reason === '') $reason = 'bridge_required';

        $target = doc_ease_gateway_target();
        $target = doc_ease_append_query($target, [
            'doc_ease_reason' => $reason,
        ]);

        if (is_api_request()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'status' => 'error',
                'code' => 'DOC_EASE_GATEWAY_REQUIRED',
                'message' => 'Please access Doc-Ease through the Laravel gateway.',
                'redirect' => $target,
            ]);
            exit;
        }

        header('Location: ' . $target);
        exit;
    }
}

// Include database configuration using an absolute path relative to this file
$dbPath = __DIR__ . '/../config/db.php';
if (file_exists($dbPath)) {
    include $dbPath;
} else {
    // Fallback to previous behavior; this will trigger a clear warning instead of silent include failure
    include __DIR__ . '/../config/db.php';
}

// Align DB session time zone with PST (+08:00). Works even without time zone tables.
if (isset($conn) && $conn instanceof mysqli) {
    try { @$conn->query("SET time_zone = '+08:00'"); } catch (Throwable $e) { /* ignore */ }
}

if (!function_exists('is_auth_page')) {
    function is_auth_page() {
        $scriptName = isset($_SERVER['PHP_SELF']) ? basename($_SERVER['PHP_SELF']) : '';
        return strpos($scriptName, 'auth-') === 0;
    }
}

if (!function_exists('is_api_request')) {
    function is_api_request() {
        $script = isset($_SERVER['PHP_SELF']) ? str_replace('\\', '/', $_SERVER['PHP_SELF']) : '';
        $requestUri = isset($_SERVER['REQUEST_URI']) ? str_replace('\\', '/', $_SERVER['REQUEST_URI']) : '';
        $accept = isset($_SERVER['HTTP_ACCEPT']) ? strtolower($_SERVER['HTTP_ACCEPT']) : '';
        $requestedWith = isset($_SERVER['HTTP_X_REQUESTED_WITH']) ? strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) : '';

        if (strpos($script, '/includes/') !== false || strpos($requestUri, '/includes/') !== false) {
            return true;
        }

        $apiScripts = ['get_monthly_uploads.php', 'get_student_name.php', 'process_add_subject.php', 'process_edit_subject.php', 'process_delete_subject.php'];
        $scriptBase = basename($script);
        if (in_array($scriptBase, $apiScripts, true)) {
            return true;
        }

        if (strpos($accept, 'application/json') !== false || $requestedWith === 'xmlhttprequest') {
            return true;
        }

        return false;
    }
}

if (!function_exists('deny_access')) {
    function deny_access($statusCode = 403, $message = 'Forbidden') {
        http_response_code((int) $statusCode);
        if (is_api_request()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'error', 'message' => $message]);
            exit;
        }
        header('Location: auth-login.php');
        exit;
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_validate')) {
    function csrf_validate($token) {
        if (!isset($_SESSION['csrf_token']) || !is_string($token)) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }
}

if (!function_exists('session_idle_timeout_default_minutes')) {
    function session_idle_timeout_default_minutes() {
        return 30;
    }
}

if (!function_exists('session_idle_timeout_clamp_minutes')) {
    function session_idle_timeout_clamp_minutes($minutes) {
        $minutes = (int) $minutes;
        if ($minutes < 1) $minutes = 1;
        if ($minutes > 1440) $minutes = 1440;
        return $minutes;
    }
}

if (!function_exists('session_idle_timeout_ensure_settings')) {
    function session_idle_timeout_ensure_settings(mysqli $conn) {
        static $done = false;
        if ($done) return;
        $done = true;

        // Shared key/value table used across multiple modules.
        $conn->query(
            "CREATE TABLE IF NOT EXISTS app_settings (
                setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
                setting_value VARCHAR(255) NOT NULL DEFAULT '',
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $defaultText = (string) session_idle_timeout_default_minutes();
        $stmt = $conn->prepare(
            "INSERT IGNORE INTO app_settings (setting_key, setting_value)
             VALUES ('session_idle_timeout_minutes', ?)"
        );
        if (!$stmt) return;
        $stmt->bind_param('s', $defaultText);
        try { $stmt->execute(); } catch (Throwable $e) { /* ignore */ }
        $stmt->close();
    }
}

if (!function_exists('session_idle_timeout_get_minutes')) {
    function session_idle_timeout_get_minutes(mysqli $conn) {
        static $cached = null;
        if ($cached !== null) return (int) $cached;

        session_idle_timeout_ensure_settings($conn);

        $default = session_idle_timeout_default_minutes();
        $stmt = $conn->prepare(
            "SELECT setting_value
             FROM app_settings
             WHERE setting_key = 'session_idle_timeout_minutes'
             LIMIT 1"
        );
        if (!$stmt) {
            $cached = $default;
            return (int) $cached;
        }

        $stmt->execute();
        $res = $stmt->get_result();
        $value = $default;
        if ($res && $res->num_rows === 1) {
            $row = $res->fetch_assoc();
            $value = session_idle_timeout_clamp_minutes((int) ($row['setting_value'] ?? $default));
        }
        $stmt->close();

        $cached = $value;
        return (int) $cached;
    }
}

if (!function_exists('session_idle_timeout_save_minutes')) {
    function session_idle_timeout_save_minutes(mysqli $conn, $minutes) {
        session_idle_timeout_ensure_settings($conn);
        $minutes = session_idle_timeout_clamp_minutes($minutes);
        $valueText = (string) $minutes;

        $stmt = $conn->prepare(
            "INSERT INTO app_settings (setting_key, setting_value)
             VALUES ('session_idle_timeout_minutes', ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        if (!$stmt) return false;
        $stmt->bind_param('s', $valueText);
        $ok = false;
        try { $ok = $stmt->execute(); } catch (Throwable $e) { $ok = false; }
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('auth_login_lockout_default_minutes')) {
    function auth_login_lockout_default_minutes() {
        return 20;
    }
}

if (!function_exists('auth_login_lockout_clamp_minutes')) {
    function auth_login_lockout_clamp_minutes($minutes) {
        $minutes = (int) $minutes;
        if ($minutes < 1) $minutes = 1;
        if ($minutes > 1440) $minutes = 1440;
        return $minutes;
    }
}

if (!function_exists('auth_login_lockout_ensure_settings')) {
    function auth_login_lockout_ensure_settings(mysqli $conn) {
        static $done = false;
        if ($done) return;
        $done = true;

        session_idle_timeout_ensure_settings($conn);

        $defaultText = (string) auth_login_lockout_default_minutes();
        $stmt = $conn->prepare(
            "INSERT IGNORE INTO app_settings (setting_key, setting_value)
             VALUES ('auth_login_lockout_minutes', ?)"
        );
        if (!$stmt) return;
        $stmt->bind_param('s', $defaultText);
        try { $stmt->execute(); } catch (Throwable $e) { /* ignore */ }
        $stmt->close();
    }
}

if (!function_exists('auth_login_lockout_get_minutes')) {
    function auth_login_lockout_get_minutes(mysqli $conn, $forceReload = false) {
        static $cached = null;
        if (!$forceReload && $cached !== null) return (int) $cached;

        auth_login_lockout_ensure_settings($conn);
        $default = auth_login_lockout_default_minutes();

        $stmt = $conn->prepare(
            "SELECT setting_value
             FROM app_settings
             WHERE setting_key = 'auth_login_lockout_minutes'
             LIMIT 1"
        );
        if (!$stmt) {
            $cached = $default;
            return (int) $cached;
        }

        $stmt->execute();
        $res = $stmt->get_result();
        $value = $default;
        if ($res && $res->num_rows === 1) {
            $row = $res->fetch_assoc();
            $value = auth_login_lockout_clamp_minutes((int) ($row['setting_value'] ?? $default));
        }
        $stmt->close();

        $cached = $value;
        return (int) $cached;
    }
}

if (!function_exists('auth_login_lockout_save_minutes')) {
    function auth_login_lockout_save_minutes(mysqli $conn, $minutes) {
        auth_login_lockout_ensure_settings($conn);
        $minutes = auth_login_lockout_clamp_minutes($minutes);
        $valueText = (string) $minutes;

        $stmt = $conn->prepare(
            "INSERT INTO app_settings (setting_key, setting_value)
             VALUES ('auth_login_lockout_minutes', ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        if (!$stmt) return false;
        $stmt->bind_param('s', $valueText);
        $ok = false;
        try { $ok = $stmt->execute(); } catch (Throwable $e) { $ok = false; }
        $stmt->close();

        if ($ok) {
            auth_login_lockout_get_minutes($conn, true);
        }
        return $ok;
    }
}

if (!function_exists('session_global_control_ensure_settings')) {
    function session_global_control_ensure_settings(mysqli $conn) {
        static $done = false;
        if ($done) return;
        $done = true;

        session_idle_timeout_ensure_settings($conn);
        $conn->query(
            "INSERT IGNORE INTO app_settings (setting_key, setting_value)
             VALUES
                ('session_global_refresh_version', '1'),
                ('session_global_logout_version', '1')"
        );
    }
}

if (!function_exists('session_global_control_get_versions')) {
    function session_global_control_get_versions(mysqli $conn, $forceReload = false) {
        static $cached = null;
        if (!$forceReload && is_array($cached)) return $cached;

        session_global_control_ensure_settings($conn);
        $versions = ['refresh' => 1, 'logout' => 1];

        $res = $conn->query(
            "SELECT setting_key, setting_value
             FROM app_settings
             WHERE setting_key IN ('session_global_refresh_version', 'session_global_logout_version')"
        );
        if ($res instanceof mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                $key = (string) ($row['setting_key'] ?? '');
                $val = (int) ($row['setting_value'] ?? 1);
                if ($val < 1) $val = 1;
                if ($key === 'session_global_refresh_version') $versions['refresh'] = $val;
                if ($key === 'session_global_logout_version') $versions['logout'] = $val;
            }
            $res->free();
        }

        $cached = $versions;
        return $cached;
    }
}

if (!function_exists('session_global_control_bump_refresh')) {
    function session_global_control_bump_refresh(mysqli $conn) {
        session_global_control_ensure_settings($conn);
        $ok = $conn->query(
            "INSERT INTO app_settings (setting_key, setting_value)
             VALUES ('session_global_refresh_version', '1')
             ON DUPLICATE KEY UPDATE setting_value = CAST(setting_value AS UNSIGNED) + 1"
        );
        if (!$ok) return 0;
        $versions = session_global_control_get_versions($conn, true);
        return (int) ($versions['refresh'] ?? 0);
    }
}

if (!function_exists('session_global_control_bump_logout')) {
    function session_global_control_bump_logout(mysqli $conn) {
        session_global_control_ensure_settings($conn);
        $ok = $conn->query(
            "INSERT INTO app_settings (setting_key, setting_value)
             VALUES ('session_global_logout_version', '1')
             ON DUPLICATE KEY UPDATE setting_value = CAST(setting_value AS UNSIGNED) + 1"
        );
        if (!$ok) return 0;
        $versions = session_global_control_get_versions($conn, true);
        return (int) ($versions['logout'] ?? 0);
    }
}

if (!function_exists('session_global_control_eval')) {
    function session_global_control_eval(mysqli $conn) {
        $state = [
            'force_logout' => false,
            'refresh_version' => 1,
            'logout_version' => 1,
        ];

        $uid = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
        if ($uid <= 0) return $state;

        $versions = session_global_control_get_versions($conn);
        $state['refresh_version'] = (int) ($versions['refresh'] ?? 1);
        $state['logout_version'] = (int) ($versions['logout'] ?? 1);

        $seenLogout = isset($_SESSION['session_global_logout_seen'])
            ? (int) $_SESSION['session_global_logout_seen']
            : 0;
        if ($seenLogout <= 0) {
            $_SESSION['session_global_logout_seen'] = (int) $state['logout_version'];
            $seenLogout = (int) $state['logout_version'];
        }

        if ((int) $state['logout_version'] > $seenLogout) {
            $_SESSION['session_global_logout_seen'] = (int) $state['logout_version'];
            $state['force_logout'] = true;
        }

        return $state;
    }
}

if (!function_exists('session_idle_timeout_logout')) {
    function session_idle_timeout_logout(mysqli $conn, $reason = 'timeout') {
        $reason = trim((string) $reason);
        if ($reason === '') $reason = 'timeout';

        $uid = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;

        // Best-effort audit log. We do this before clearing the session so actor_user_id is available.
        $auditPath = __DIR__ . '/../includes/audit.php';
        if ($uid > 0 && is_file($auditPath)) {
            require_once $auditPath;
            if (function_exists('audit_log')) {
                $meta = [
                    'reason' => $reason,
                    'timeout_minutes' => session_idle_timeout_get_minutes($conn),
                ];
                audit_log($conn, 'auth.session_timeout', 'user', $uid, 'Session expired due to inactivity.', $meta);
            }
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                if (!headers_sent()) {
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
            }
            @session_destroy();
        }

        if (is_api_request()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'status' => 'error',
                'code' => 'SESSION_EXPIRED',
                'message' => 'Session expired due to inactivity. Please log in again.',
            ]);
            exit;
        }

        $target = 'auth-login.php?reason=' . urlencode($reason);
        header('Location: ' . $target);
        exit;
    }
}

if (!function_exists('session_idle_timeout_enforce')) {
    function session_idle_timeout_enforce(mysqli $conn) {
        $uid = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
        if ($uid <= 0) return;

        $minutes = session_idle_timeout_get_minutes($conn);
        $timeoutSeconds = (int) ($minutes * 60);
        if ($timeoutSeconds <= 0) return;

        $now = time();
        $last = isset($_SESSION['last_activity_ts']) ? (int) $_SESSION['last_activity_ts'] : 0;

        if ($last > 0 && ($now - $last) > $timeoutSeconds) {
            session_idle_timeout_logout($conn, 'timeout');
        }

        $skipTouch = defined('DOC_EASE_SKIP_IDLE_TOUCH') && DOC_EASE_SKIP_IDLE_TOUCH;
        if (!$skipTouch) {
            $_SESSION['last_activity_ts'] = $now;
        }
    }
}

if (isset($conn) && $conn instanceof mysqli) {
    session_idle_timeout_enforce($conn);
}

if (!function_exists('ensure_users_password_policy_columns')) {
    /**
     * Ensure first-login password policy columns exist.
     * - must_change_password: 1 means user must change password before continuing.
     * - password_changed_at: timestamp of latest user-initiated password change.
     */
    function ensure_users_password_policy_columns(mysqli $conn) {
        static $done = false;
        if ($done) return;
        $done = true;

        $hasMustChange = false;
        $hasChangedAt = false;

        $res = $conn->query("SHOW COLUMNS FROM users LIKE 'must_change_password'");
        if ($res && $res->num_rows > 0) $hasMustChange = true;
        if ($res instanceof mysqli_result) $res->free();

        $res = $conn->query("SHOW COLUMNS FROM users LIKE 'password_changed_at'");
        if ($res && $res->num_rows > 0) $hasChangedAt = true;
        if ($res instanceof mysqli_result) $res->free();

        if (!$hasMustChange) {
            $conn->query("ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0");
        }
        if (!$hasChangedAt) {
            $conn->query("ALTER TABLE users ADD COLUMN password_changed_at DATETIME NULL DEFAULT NULL");
        }
    }
}

if (!function_exists('session_table_exists')) {
    function session_table_exists(mysqli $conn, $tableName) {
        $tableName = trim((string) $tableName);
        if ($tableName === '') return false;

        $stmt = $conn->prepare(
            "SELECT 1
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
             LIMIT 1"
        );
        if (!$stmt) return false;

        $stmt->bind_param('s', $tableName);
        $stmt->execute();
        $res = $stmt->get_result();
        $exists = $res && $res->num_rows === 1;
        $stmt->close();
        return $exists;
    }
}

if (!function_exists('session_table_has_column')) {
    function session_table_has_column(mysqli $conn, $tableName, $columnName) {
        $tableName = trim((string) $tableName);
        $columnName = trim((string) $columnName);
        if ($tableName === '' || $columnName === '') return false;
        if (!session_table_exists($conn, $tableName)) return false;

        $stmt = $conn->prepare(
            "SELECT 1
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
             LIMIT 1"
        );
        if (!$stmt) return false;

        $stmt->bind_param('ss', $tableName, $columnName);
        $stmt->execute();
        $res = $stmt->get_result();
        $exists = $res && $res->num_rows === 1;
        $stmt->close();
        return $exists;
    }
}

if (!function_exists('ensure_campus_access_schema')) {
    /**
     * Campus-aware access bootstrap.
     * - Adds campuses table.
     * - Adds campus_id + is_superadmin fields to users.
     * - Adds campus_id to students/teachers for profile-level scoping.
     * - Seeds default campus and backfills existing rows.
     */
    function ensure_campus_access_schema(mysqli $conn) {
        static $done = false;
        if ($done) return;
        $done = true;

        $conn->query(
            "CREATE TABLE IF NOT EXISTS campuses (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                campus_code VARCHAR(40) NOT NULL,
                campus_name VARCHAR(160) NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_campuses_code (campus_code),
                UNIQUE KEY uq_campuses_name (campus_name),
                KEY idx_campuses_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        if (session_table_exists($conn, 'users')) {
            if (!session_table_has_column($conn, 'users', 'is_superadmin')) {
                $conn->query("ALTER TABLE users ADD COLUMN is_superadmin TINYINT(1) NOT NULL DEFAULT 0");
            }
            if (!session_table_has_column($conn, 'users', 'campus_id')) {
                $conn->query("ALTER TABLE users ADD COLUMN campus_id BIGINT UNSIGNED NULL DEFAULT NULL");
            }
        }

        if (session_table_exists($conn, 'students') && !session_table_has_column($conn, 'students', 'campus_id')) {
            $conn->query("ALTER TABLE students ADD COLUMN campus_id BIGINT UNSIGNED NULL DEFAULT NULL");
        }

        if (session_table_exists($conn, 'teachers') && !session_table_has_column($conn, 'teachers', 'campus_id')) {
            $conn->query("ALTER TABLE teachers ADD COLUMN campus_id BIGINT UNSIGNED NULL DEFAULT NULL");
        }

        $defaultCampusId = 0;
        $resCampus = $conn->query("SELECT id FROM campuses ORDER BY id ASC LIMIT 1");
        if ($resCampus && $resCampus->num_rows === 1) {
            $defaultCampusId = (int) ($resCampus->fetch_assoc()['id'] ?? 0);
        }
        if ($defaultCampusId <= 0) {
            $insCampus = $conn->prepare("INSERT INTO campuses (campus_code, campus_name, is_active) VALUES ('MAIN', 'Main Campus', 1)");
            if ($insCampus) {
                $insCampus->execute();
                $insCampus->close();
                $defaultCampusId = (int) $conn->insert_id;
            }
        }

        if ($defaultCampusId > 0 && session_table_exists($conn, 'users')) {
            $superCount = 0;
            $resSuper = $conn->query("SELECT COUNT(*) AS c FROM users WHERE role = 'admin' AND is_superadmin = 1");
            if ($resSuper && $resSuper->num_rows === 1) {
                $superCount = (int) (($resSuper->fetch_assoc()['c'] ?? 0));
            }

            if ($superCount === 0) {
                $firstAdminRes = $conn->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
                if ($firstAdminRes && $firstAdminRes->num_rows === 1) {
                    $firstAdminId = (int) (($firstAdminRes->fetch_assoc()['id'] ?? 0));
                    if ($firstAdminId > 0) {
                        $setSuper = $conn->prepare("UPDATE users SET is_superadmin = 1 WHERE id = ? LIMIT 1");
                        if ($setSuper) {
                            $setSuper->bind_param('i', $firstAdminId);
                            $setSuper->execute();
                            $setSuper->close();
                        }
                    }
                }
            }

            $backfillUsers = $conn->prepare(
                "UPDATE users
                 SET campus_id = ?
                 WHERE campus_id IS NULL
                   AND (is_superadmin = 0 OR role <> 'admin')"
            );
            if ($backfillUsers) {
                $backfillUsers->bind_param('i', $defaultCampusId);
                $backfillUsers->execute();
                $backfillUsers->close();
            }
        }

        if (session_table_exists($conn, 'students') && session_table_has_column($conn, 'students', 'campus_id')) {
            $conn->query(
                "UPDATE students s
                 JOIN users u ON u.id = s.user_id
                 SET s.campus_id = u.campus_id
                 WHERE s.campus_id IS NULL
                   AND u.campus_id IS NOT NULL"
            );
            if ($defaultCampusId > 0) {
                $backfillStudents = $conn->prepare("UPDATE students SET campus_id = ? WHERE campus_id IS NULL");
                if ($backfillStudents) {
                    $backfillStudents->bind_param('i', $defaultCampusId);
                    $backfillStudents->execute();
                    $backfillStudents->close();
                }
            }
        }

        if (session_table_exists($conn, 'teachers') && session_table_has_column($conn, 'teachers', 'campus_id')) {
            $conn->query(
                "UPDATE teachers t
                 JOIN users u ON u.id = t.user_id
                 SET t.campus_id = u.campus_id
                 WHERE t.campus_id IS NULL
                   AND u.campus_id IS NOT NULL"
            );
            if ($defaultCampusId > 0) {
                $backfillTeachers = $conn->prepare("UPDATE teachers SET campus_id = ? WHERE campus_id IS NULL");
                if ($backfillTeachers) {
                    $backfillTeachers->bind_param('i', $defaultCampusId);
                    $backfillTeachers->execute();
                    $backfillTeachers->close();
                }
            }
        }
    }
}

if (!function_exists('campus_list')) {
    function campus_list(mysqli $conn, $activeOnly = true) {
        $rows = [];
        if (!session_table_exists($conn, 'campuses')) return $rows;

        if ($activeOnly) {
            $sql = "SELECT id, campus_code, campus_name, is_active FROM campuses WHERE is_active = 1 ORDER BY campus_name ASC";
            $res = $conn->query($sql);
        } else {
            $sql = "SELECT id, campus_code, campus_name, is_active FROM campuses ORDER BY campus_name ASC";
            $res = $conn->query($sql);
        }

        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'campus_code' => (string) ($row['campus_code'] ?? ''),
                    'campus_name' => (string) ($row['campus_name'] ?? ''),
                    'is_active' => (int) ($row['is_active'] ?? 0),
                ];
            }
            $res->free();
        }
        return $rows;
    }
}

if (!function_exists('campus_default_id')) {
    function campus_default_id(mysqli $conn) {
        $rows = campus_list($conn, true);
        if (count($rows) > 0) return (int) ($rows[0]['id'] ?? 0);

        $rows = campus_list($conn, false);
        if (count($rows) > 0) return (int) ($rows[0]['id'] ?? 0);

        return 0;
    }
}

if (!function_exists('current_user_is_superadmin')) {
    function current_user_is_superadmin() {
        return !empty($_SESSION['is_superadmin']) && (int) $_SESSION['is_superadmin'] === 1;
    }
}

if (!function_exists('current_user_is_admin')) {
    function current_user_is_admin() {
        $role = isset($_SESSION['user_role']) ? normalize_role((string) $_SESSION['user_role']) : '';
        return $role === 'admin';
    }
}

if (!function_exists('current_user_is_campus_admin')) {
    function current_user_is_campus_admin() {
        return current_user_is_admin() && !current_user_is_superadmin();
    }
}

if (!function_exists('current_user_campus_id')) {
    function current_user_campus_id() {
        return isset($_SESSION['campus_id']) ? (int) $_SESSION['campus_id'] : 0;
    }
}

if (!function_exists('current_user_can_manage_campus')) {
    function current_user_can_manage_campus($campusId) {
        $campusId = (int) $campusId;
        if ($campusId <= 0) return false;
        if (current_user_is_superadmin()) return true;
        return current_user_campus_id() > 0 && current_user_campus_id() === $campusId;
    }
}

if (!function_exists('session_role_matches')) {
    function session_role_matches($needRole, $haveRole, $isSuperadmin = false) {
        $needRole = normalize_role((string) $needRole);
        $haveRole = normalize_role((string) $haveRole);
        $isSuperadmin = (bool) $isSuperadmin;

        if ($needRole === 'superadmin') {
            return $haveRole === 'admin' && $isSuperadmin;
        }
        if ($needRole === 'campus_admin') {
            return $haveRole === 'admin' && !$isSuperadmin;
        }
        if ($needRole === 'admin') {
            return $haveRole === 'admin';
        }
        return $needRole !== '' && $needRole === $haveRole;
    }
}

if (!function_exists('current_user_row')) {
    /**
     * Best-effort fetch of the currently authenticated user row.
     * Cached per-request to avoid duplicate queries from layout includes.
     */
    function current_user_row(mysqli $conn) {
        static $cachedUserId = null;
        static $cachedRow = null;

        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
        if ($userId <= 0) return null;

        if ($cachedUserId === $userId && is_array($cachedRow)) {
            return $cachedRow;
        }

        $row = null;
        $stmt = $conn->prepare(
            "SELECT id, username, useremail, role, is_active, first_name, last_name, profile_picture, campus_id, is_superadmin
             FROM users
             WHERE id = ?
             LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows === 1) $row = $res->fetch_assoc();
            $stmt->close();
        }

        $cachedUserId = $userId;
        $cachedRow = is_array($row) ? $row : null;
        return $cachedRow;
    }
}

if (isset($conn) && $conn instanceof mysqli) {
    ensure_campus_access_schema($conn);
}

if (!function_exists('current_user_display_name')) {
    function current_user_display_name(array $u) {
        $fn = trim((string) ($u['first_name'] ?? ''));
        $ln = trim((string) ($u['last_name'] ?? ''));
        $full = trim($fn . ' ' . $ln);
        if ($full !== '') return $full;
        return (string) ($u['username'] ?? 'Account');
    }
}

if (!function_exists('current_user_avatar_url')) {
    function current_user_avatar_url($path) {
        $path = trim((string) $path);
        if ($path === '') return 'assets/images/users/avatar-1.jpg';
        return $path;
    }
}

// Check if user is logged in.
// Some auth-adjacent endpoints (such as temporary login bypass actions)
// intentionally allow guests and set ALLOW_GUEST_SESSION before including this file.
$allowGuestSession = defined('ALLOW_GUEST_SESSION') && ALLOW_GUEST_SESSION;
if (doc_ease_direct_lock_enabled() && !doc_ease_bridge_lock_bypass()) {
    $scriptName = isset($_SERVER['PHP_SELF']) ? basename((string) $_SERVER['PHP_SELF']) : '';
    $allowWithoutBridge = in_array($scriptName, ['bridge-login.php', 'auth-logout.php', 'auth-logout-2.php'], true);
    if (!$allowWithoutBridge && !doc_ease_bridge_marker_present()) {
        unset(
            $_SESSION['user_id'],
            $_SESSION['user_email'],
            $_SESSION['user_name'],
            $_SESSION['user_role'],
            $_SESSION['is_active'],
            $_SESSION['campus_id'],
            $_SESSION['is_superadmin'],
            $_SESSION['force_password_change']
        );
        doc_ease_redirect_to_gateway('bridge_required');
    }
}
if (!isset($_SESSION['user_id']) && !is_auth_page() && !$allowGuestSession) {
    deny_access(401, 'Unauthorized. Please log in.');
}

// Normalize role values inside the session so the rest of the app can rely on consistent names.
if (isset($_SESSION['user_role'])) {
    $_SESSION['user_role'] = normalize_role($_SESSION['user_role']);
}

if (isset($_SESSION['campus_id'])) {
    $_SESSION['campus_id'] = (int) $_SESSION['campus_id'];
}
if (isset($_SESSION['is_superadmin'])) {
    $_SESSION['is_superadmin'] = ((int) $_SESSION['is_superadmin'] === 1) ? 1 : 0;
}

if (isset($_SESSION['user_id']) && ( !isset($_SESSION['campus_id']) || !isset($_SESSION['is_superadmin']) )) {
    if (isset($conn) && $conn instanceof mysqli) {
        $sessionUser = current_user_row($conn);
        if (is_array($sessionUser)) {
            $_SESSION['campus_id'] = (int) ($sessionUser['campus_id'] ?? 0);
            $_SESSION['is_superadmin'] = ((int) ($sessionUser['is_superadmin'] ?? 0) === 1) ? 1 : 0;
        }
    }
}

if (isset($_SESSION['user_id']) && isset($conn) && $conn instanceof mysqli) {
    $globalControl = session_global_control_eval($conn);
    if (!empty($globalControl['force_logout'])) {
        session_idle_timeout_logout($conn, 'admin_force_logout');
    }
}

// Enforce mandatory password change when flagged.
if (isset($_SESSION['user_id'])) {
    $mustChange = !empty($_SESSION['force_password_change']);
    if ($mustChange) {
        $scriptName = isset($_SERVER['PHP_SELF']) ? basename((string) $_SERVER['PHP_SELF']) : '';
        $allowedPages = ['auth-force-password.php', 'auth-logout.php', 'auth-logout-2.php'];
        if (!in_array($scriptName, $allowedPages, true)) {
            if (is_api_request()) {
                deny_access(403, 'Password change required before using this endpoint.');
            }
            header('Location: auth-force-password.php');
            exit;
        }
    }
}

if (!function_exists('require_role')) {
    function require_role($role) {
        $need = normalize_role($role);
        $have = isset($_SESSION['user_role']) ? normalize_role($_SESSION['user_role']) : '';
        $isSuperadmin = !empty($_SESSION['is_superadmin']) && (int) $_SESSION['is_superadmin'] === 1;
        if (!isset($_SESSION['user_id']) || $have === '' || !session_role_matches($need, $have, $isSuperadmin)) {
            deny_access(403, 'Forbidden: insufficient role.');
        }
    }
}

if (!function_exists('require_any_role')) {
    function require_any_role(array $roles) {
        $roles = array_values(array_unique(array_map('normalize_role', $roles)));
        $have = isset($_SESSION['user_role']) ? normalize_role($_SESSION['user_role']) : '';
        $isSuperadmin = !empty($_SESSION['is_superadmin']) && (int) $_SESSION['is_superadmin'] === 1;
        $ok = false;
        foreach ($roles as $need) {
            if (session_role_matches($need, $have, $isSuperadmin)) {
                $ok = true;
                break;
            }
        }
        if (!isset($_SESSION['user_id']) || $have === '' || !$ok) {
            deny_access(403, 'Forbidden: insufficient role.');
        }
    }
}

if (!function_exists('require_active_role')) {
    function require_active_role($role) {
        $need = normalize_role($role);
        $have = isset($_SESSION['user_role']) ? normalize_role($_SESSION['user_role']) : '';
        $isSuperadmin = !empty($_SESSION['is_superadmin']) && (int) $_SESSION['is_superadmin'] === 1;
        if (!isset($_SESSION['user_id']) || $have === '' || !session_role_matches($need, $have, $isSuperadmin) || empty($_SESSION['is_active'])) {
            deny_access(403, 'Forbidden: account not approved.');
        }
    }
}

if (!function_exists('require_any_active_role')) {
    function require_any_active_role(array $roles) {
        $roles = array_values(array_unique(array_map('normalize_role', $roles)));
        $have = isset($_SESSION['user_role']) ? normalize_role($_SESSION['user_role']) : '';
        $isSuperadmin = !empty($_SESSION['is_superadmin']) && (int) $_SESSION['is_superadmin'] === 1;
        $ok = false;
        foreach ($roles as $need) {
            if (session_role_matches($need, $have, $isSuperadmin)) {
                $ok = true;
                break;
            }
        }
        if (!isset($_SESSION['user_id']) || $have === '' || !$ok || empty($_SESSION['is_active'])) {
            deny_access(403, 'Forbidden: account not approved.');
        }
    }
}

if (!function_exists('require_approved_student')) {
    function require_approved_student() {
        require_active_role('student');
    }
}

// Backward-compatible helper name used by existing pages.
if (!function_exists('require_approved_user')) {
    function require_approved_user() {
        require_any_active_role(['student', 'user']);
    }
}

if (!function_exists('ai_access_allowed_roles')) {
    function ai_access_allowed_roles() {
        // Students (legacy "user" maps to student) must never invoke AI features.
        return ['admin', 'teacher'];
    }
}

if (!function_exists('ai_access_denied_message')) {
    function ai_access_denied_message() {
        return 'AI features are restricted. Student/user accounts are not allowed to use AI.';
    }
}

if (!function_exists('ai_access_can_use')) {
    function ai_access_can_use() {
        if (!isset($_SESSION['user_id']) || (int) $_SESSION['user_id'] <= 0) return false;
        $role = isset($_SESSION['user_role']) ? normalize_role((string) $_SESSION['user_role']) : '';
        if ($role === '' || !in_array($role, ai_access_allowed_roles(), true)) return false;

        // Keep existing approval policy for non-admin operational roles.
        if ($role !== 'admin' && empty($_SESSION['is_active'])) return false;
        return true;
    }
}

if (!function_exists('require_ai_access')) {
    function require_ai_access() {
        if (!ai_access_can_use()) {
            deny_access(403, ai_access_denied_message());
        }
    }
}
?>
