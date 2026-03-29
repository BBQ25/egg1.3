<?php
if (!defined('ALLOW_GUEST_SESSION')) {
    define('ALLOW_GUEST_SESSION', true);
}
if (!defined('DOC_EASE_BRIDGE_BYPASS_SESSION_LOCK')) {
    define('DOC_EASE_BRIDGE_BYPASS_SESSION_LOCK', true);
}

require_once __DIR__ . '/includes/env_secrets.php';
include __DIR__ . '/layouts/session.php';

if (!function_exists('doc_ease_bridge_env_value')) {
    function doc_ease_bridge_env_value($name, $default = '') {
        $name = trim((string) $name);
        if ($name === '') return (string) $default;

        if (function_exists('doc_ease_env_value')) {
            $value = doc_ease_env_value($name);
            if ($value !== '') return (string) $value;
        }

        $raw = getenv($name);
        if (is_string($raw) && trim($raw) !== '') {
            return trim($raw);
        }

        return (string) $default;
    }
}

if (!function_exists('doc_ease_bridge_env_bool')) {
    function doc_ease_bridge_env_bool($name, $default = false) {
        $raw = strtolower(trim((string) doc_ease_bridge_env_value($name, '')));
        if ($raw === '') return (bool) $default;
        if (in_array($raw, ['1', 'true', 'yes', 'on'], true)) return true;
        if (in_array($raw, ['0', 'false', 'no', 'off'], true)) return false;
        return (bool) $default;
    }
}

if (!function_exists('doc_ease_bridge_gateway_path')) {
    function doc_ease_bridge_gateway_path() {
        $path = trim((string) doc_ease_bridge_env_value('DOC_EASE_LARAVEL_GATEWAY_PATH', '/legacy/doc-ease'));
        if ($path === '') $path = '/legacy/doc-ease';
        if (preg_match('#^https?://#i', $path)) return $path;

        $path = '/' . ltrim(str_replace('\\', '/', $path), '/');
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        if (strpos($path, '..') !== false) return '/legacy/doc-ease';
        return $path;
    }
}

if (!function_exists('doc_ease_bridge_append_query')) {
    function doc_ease_bridge_append_query($url, array $params) {
        $url = trim((string) $url);
        if ($url === '') return '';
        if (count($params) === 0) return $url;

        $query = http_build_query($params);
        if ($query === '' || $query === '0') return $url;

        return $url . (strpos($url, '?') === false ? '?' : '&') . $query;
    }
}

if (!function_exists('doc_ease_bridge_fail')) {
    function doc_ease_bridge_fail($statusCode, $reasonCode, $message = '') {
        $statusCode = (int) $statusCode;
        if ($statusCode < 100) $statusCode = 400;

        $reasonCode = trim((string) $reasonCode);
        if ($reasonCode === '') $reasonCode = 'bridge_error';

        $message = trim((string) $message);
        if ($message === '') $message = 'Unable to establish Doc-Ease bridge session.';

        $gateway = doc_ease_bridge_gateway_path();
        $redirect = doc_ease_bridge_append_query($gateway, [
            'doc_ease_reason' => $reasonCode,
        ]);

        if (function_exists('is_api_request') && is_api_request()) {
            http_response_code($statusCode);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'status' => 'error',
                'code' => strtoupper($reasonCode),
                'message' => $message,
                'redirect' => $redirect,
            ]);
            exit;
        }

        if (!headers_sent()) {
            header('Location: ' . $redirect, true, 302);
        } else {
            echo '<script>window.location.href=' . json_encode($redirect) . ';</script>';
        }
        exit;
    }
}

if (!function_exists('doc_ease_bridge_base64url_decode')) {
    function doc_ease_bridge_base64url_decode($input) {
        $input = trim((string) $input);
        if ($input === '') return null;
        if (!preg_match('/^[A-Za-z0-9\-_]+$/', $input)) return null;

        $raw = strtr($input, '-_', '+/');
        $pad = strlen($raw) % 4;
        if ($pad > 0) {
            $raw .= str_repeat('=', 4 - $pad);
        }

        $decoded = base64_decode($raw, true);
        if (!is_string($decoded) || $decoded === '') return null;
        return $decoded;
    }
}

if (!function_exists('doc_ease_bridge_verify_token')) {
    function doc_ease_bridge_verify_token($token, $secret, &$errorCode = '', &$errorMessage = '') {
        $errorCode = '';
        $errorMessage = '';

        $token = trim((string) $token);
        $secret = (string) $secret;

        if ($token === '') {
            $errorCode = 'missing_token';
            $errorMessage = 'Missing bridge token.';
            return null;
        }
        if ($secret === '') {
            $errorCode = 'bridge_secret_missing';
            $errorMessage = 'Bridge secret is not configured.';
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            $errorCode = 'invalid_token_format';
            $errorMessage = 'Invalid bridge token format.';
            return null;
        }

        $encodedPayload = (string) $parts[0];
        $encodedSignature = (string) $parts[1];

        $payloadJson = doc_ease_bridge_base64url_decode($encodedPayload);
        $signature = doc_ease_bridge_base64url_decode($encodedSignature);
        if (!is_string($payloadJson) || !is_string($signature)) {
            $errorCode = 'invalid_token_encoding';
            $errorMessage = 'Invalid bridge token encoding.';
            return null;
        }

        $expectedSignature = hash_hmac('sha256', $encodedPayload, $secret, true);
        if (!hash_equals($expectedSignature, $signature)) {
            $errorCode = 'invalid_token_signature';
            $errorMessage = 'Invalid bridge token signature.';
            return null;
        }

        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            $errorCode = 'invalid_token_payload';
            $errorMessage = 'Invalid bridge token payload.';
            return null;
        }

        $now = time();
        $exp = isset($payload['exp']) && is_numeric($payload['exp']) ? (int) $payload['exp'] : 0;
        $iat = isset($payload['iat']) && is_numeric($payload['iat']) ? (int) $payload['iat'] : 0;

        if ($exp <= 0 || $exp < $now) {
            $errorCode = 'token_expired';
            $errorMessage = 'Bridge token expired.';
            return null;
        }
        if ($iat > 0 && $iat > ($now + 60)) {
            $errorCode = 'token_not_yet_valid';
            $errorMessage = 'Bridge token is not yet valid.';
            return null;
        }

        return $payload;
    }
}

if (!function_exists('doc_ease_bridge_payload_string')) {
    function doc_ease_bridge_payload_string(array $payload, $key, $default = '') {
        if (!array_key_exists($key, $payload)) return trim((string) $default);
        $value = $payload[$key];
        if (!is_scalar($value)) return trim((string) $default);
        return trim((string) $value);
    }
}

if (!function_exists('doc_ease_bridge_payload_int')) {
    function doc_ease_bridge_payload_int(array $payload, $key, $default = 0) {
        if (!array_key_exists($key, $payload)) return (int) $default;
        $value = $payload[$key];
        if (is_int($value)) return $value;
        if (is_numeric($value)) return (int) $value;
        return (int) $default;
    }
}

if (!function_exists('doc_ease_bridge_payload_bool')) {
    function doc_ease_bridge_payload_bool(array $payload, $key, $default = false) {
        if (!array_key_exists($key, $payload)) return (bool) $default;
        $value = $payload[$key];
        if (is_bool($value)) return $value;
        if (is_int($value)) return $value === 1;
        if (is_string($value)) {
            $v = strtolower(trim($value));
            if (in_array($v, ['1', 'true', 'yes', 'on'], true)) return true;
            if (in_array($v, ['0', 'false', 'no', 'off'], true)) return false;
        }
        return (bool) $default;
    }
}

if (!function_exists('doc_ease_bridge_role')) {
    function doc_ease_bridge_role(array $payload) {
        $role = strtolower(trim((string) doc_ease_bridge_payload_string($payload, 'role', '')));
        if ($role === 'user') $role = 'student';
        if (in_array($role, ['admin', 'teacher', 'student'], true)) {
            return $role;
        }

        $sourceRole = strtoupper(trim((string) doc_ease_bridge_payload_string($payload, 'source_role', '')));
        if ($sourceRole === 'ADMIN') return 'admin';
        if ($sourceRole === 'OWNER' || $sourceRole === 'WORKER') return 'teacher';
        return 'student';
    }
}

if (!function_exists('doc_ease_bridge_safe_next')) {
    function doc_ease_bridge_safe_next($rawNext) {
        $next = trim((string) $rawNext);
        if ($next === '') $next = '/doc-ease/index.php';

        if (preg_match('#^[a-zA-Z]+://#', $next)) {
            return '/doc-ease/index.php';
        }

        $next = '/' . ltrim(str_replace('\\', '/', $next), '/');
        $next = preg_replace('#/+#', '/', $next) ?? $next;

        if (strpos($next, '..') !== false) {
            return '/doc-ease/index.php';
        }

        if (strpos($next, '/doc-ease/') !== 0) {
            return '/doc-ease/index.php';
        }

        return $next;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    doc_ease_bridge_fail(405, 'method_not_allowed', 'Only GET is supported for Doc-Ease bridge login.');
}

$bridgeSecret = trim((string) doc_ease_bridge_env_value('DOC_EASE_BRIDGE_SECRET', ''));
if ($bridgeSecret === '') {
    doc_ease_bridge_fail(503, 'bridge_not_configured', 'Bridge secret is not configured.');
}

$token = isset($_GET['token']) ? trim((string) $_GET['token']) : '';
if ($token === '' && isset($_GET['t'])) {
    $token = trim((string) $_GET['t']);
}

$verifyErrorCode = '';
$verifyErrorMessage = '';
$payload = doc_ease_bridge_verify_token($token, $bridgeSecret, $verifyErrorCode, $verifyErrorMessage);
if (!is_array($payload)) {
    doc_ease_bridge_fail(401, $verifyErrorCode, $verifyErrorMessage);
}

$userId = doc_ease_bridge_payload_int($payload, 'uid', 0);
if ($userId <= 0) {
    $userId = doc_ease_bridge_payload_int($payload, 'sub', 0);
}
if ($userId <= 0) {
    doc_ease_bridge_fail(401, 'invalid_subject', 'Bridge token subject is invalid.');
}

$role = doc_ease_bridge_role($payload);
$username = doc_ease_bridge_payload_string($payload, 'username', '');
$displayName = doc_ease_bridge_payload_string($payload, 'name', '');
if ($displayName === '') $displayName = $username;
if ($displayName === '') $displayName = 'Gateway User';

$email = doc_ease_bridge_payload_string($payload, 'email', '');
$isActive = doc_ease_bridge_payload_bool($payload, 'is_active', true) ? 1 : 0;
$campusId = doc_ease_bridge_payload_int($payload, 'campus_id', 0);
if ($campusId < 0) $campusId = 0;

$defaultSuperadmin = ($role === 'admin');
$isSuperadmin = doc_ease_bridge_payload_bool($payload, 'is_superadmin', $defaultSuperadmin) ? 1 : 0;
$forcePasswordChange = doc_ease_bridge_payload_bool($payload, 'force_password_change', false) ? 1 : 0;

session_regenerate_id(true);

$_SESSION['user_id'] = (int) $userId;
$_SESSION['user_email'] = $email;
$_SESSION['user_name'] = $username !== '' ? $username : $displayName;
$_SESSION['user_role'] = $role;
$_SESSION['is_active'] = $isActive;
$_SESSION['campus_id'] = (int) $campusId;
$_SESSION['is_superadmin'] = $isSuperadmin;
$_SESSION['force_password_change'] = $forcePasswordChange;
$_SESSION['last_activity_ts'] = time();
$_SESSION['doc_ease_bridge_verified'] = 1;
$_SESSION['doc_ease_bridge_source'] = 'laravel';
$_SESSION['doc_ease_bridge_issued_at'] = doc_ease_bridge_payload_int($payload, 'iat', time());
$_SESSION['doc_ease_bridge_expires_at'] = doc_ease_bridge_payload_int($payload, 'exp', time());

unset($_SESSION['student_no'], $_SESSION['student_name'], $_SESSION['student_section']);

try {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
} catch (Throwable $e) {
    unset($_SESSION['csrf_token']);
    if (function_exists('csrf_token')) {
        csrf_token();
    }
}

$next = doc_ease_bridge_safe_next(isset($_GET['next']) ? $_GET['next'] : '');
header('Location: ' . $next);
exit;

