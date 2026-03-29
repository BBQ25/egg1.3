<?php include __DIR__ . '/../../layouts/session.php'; ?>
<?php
require_once __DIR__ . '/../../includes/audit.php';
ensure_audit_logs_table($conn);

if (!defined('AUTH_LOGOUT_GET_FALLBACK_UNTIL')) {
    // Temporary compatibility window for legacy GET logout links.
    define('AUTH_LOGOUT_GET_FALLBACK_UNTIL', '2026-03-31 23:59:59');
}

if (!function_exists('auth_logout_reason')) {
    function auth_logout_reason($raw) {
        $reason = strtolower(trim((string) $raw));
        if (!in_array($reason, ['logout', 'timeout'], true)) {
            $reason = 'logout';
        }
        return $reason;
    }
}

if (!function_exists('auth_logout_get_fallback_allowed')) {
    function auth_logout_get_fallback_allowed() {
        $cutoffTs = strtotime((string) AUTH_LOGOUT_GET_FALLBACK_UNTIL);
        if (!$cutoffTs) return false;
        return time() <= $cutoffTs;
    }
}

if (!function_exists('auth_logout_destroy_session')) {
    function auth_logout_destroy_session() {
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
    }
}

if (!function_exists('auth_logout_reject')) {
    function auth_logout_reject($statusCode, $message) {
        $statusCode = (int) $statusCode;
        if ($statusCode < 400) $statusCode = 400;

        http_response_code($statusCode);
        if ($statusCode === 405) {
            header('Allow: POST');
        }

        if (function_exists('is_api_request') && is_api_request()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'status' => 'error',
                'code' => 'LOGOUT_REJECTED',
                'message' => (string) $message,
            ]);
            exit;
        }

        header('Content-Type: text/plain; charset=utf-8');
        echo (string) $message;
        exit;
    }
}

$method = strtoupper(trim((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')));
$reasonRaw = ($method === 'POST')
    ? ($_POST['reason'] ?? ($_GET['reason'] ?? 'logout'))
    : ($_GET['reason'] ?? ($_POST['reason'] ?? 'logout'));
$reason = auth_logout_reason($reasonRaw);
$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;

if ($method === 'POST') {
    $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    // Enforce CSRF only when an authenticated session is still present.
    if ($userId > 0 && !csrf_validate($csrf)) {
        audit_log(
            $conn,
            'auth.logout.csrf_failed',
            'user',
            $userId,
            'Logout rejected due to invalid CSRF token.',
            ['reason' => $reason]
        );
        auth_logout_reject(403, 'Security check failed. Please log out again from the app menu.');
    }
} elseif ($method === 'GET') {
    if (!auth_logout_get_fallback_allowed()) {
        audit_log(
            $conn,
            'auth.logout.get_rejected',
            'user',
            $userId > 0 ? $userId : null,
            'Legacy GET logout rejected after fallback window.',
            [
                'reason' => $reason,
                'fallback_until' => AUTH_LOGOUT_GET_FALLBACK_UNTIL,
            ]
        );
        auth_logout_reject(405, 'Logout via GET is no longer allowed. Please submit a POST logout request.');
    }

    audit_log(
        $conn,
        'auth.logout.legacy_get',
        'user',
        $userId > 0 ? $userId : null,
        'Legacy GET logout fallback used.',
        [
            'reason' => $reason,
            'fallback_until' => AUTH_LOGOUT_GET_FALLBACK_UNTIL,
        ]
    );
} else {
    auth_logout_reject(405, 'Method not allowed for logout.');
}

$auditAction = ($reason === 'timeout') ? 'auth.session_timeout' : 'auth.logout';
audit_log(
    $conn,
    $auditAction,
    'user',
    $userId > 0 ? $userId : null,
    null,
    ['method' => $method]
);

auth_logout_destroy_session();
header('Location: auth-login.php?reason=' . urlencode($reason));
exit;
