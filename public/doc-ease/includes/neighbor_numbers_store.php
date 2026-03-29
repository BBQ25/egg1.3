<?php
include __DIR__ . '/../layouts/session.php';
require_role('admin');
require_once __DIR__ . '/neighbor_numbers.php';

header('Content-Type: application/json; charset=utf-8');

if (!function_exists('nn_store_json')) {
    function nn_store_json($status, $message, array $extra = []) {
        echo json_encode(array_merge([
            'status' => $status,
            'message' => $message,
        ], $extra), JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    nn_store_json('error', 'Database connection unavailable.');
}

nn_history_ensure_table($conn);
nn_settings_ensure_table($conn);
nn_snapshot_ensure_table($conn);

$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
if ($userId <= 0) {
    nn_store_json('error', 'Unauthorized.');
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'GET') {
    $summary = nn_history_load_user_store($conn, $userId);
    $settings = nn_settings_load($conn);
    $snapshots = nn_snapshot_load_user($conn, $userId);
    nn_store_json('ok', 'History store loaded.', [
        'store' => $summary['store'] ?? [],
        'key_count' => (int) ($summary['key_count'] ?? 0),
        'entry_count' => (int) ($summary['entry_count'] ?? 0),
        'skipped_rows' => (int) ($summary['skipped_rows'] ?? 0),
        'settings' => $settings,
        'snapshots' => $snapshots,
        'is_superadmin' => current_user_is_superadmin() ? 1 : 0,
    ]);
}

if ($method !== 'POST') {
    http_response_code(405);
    nn_store_json('error', 'Method not allowed.');
}

$raw = file_get_contents('php://input');
$req = null;
if (is_string($raw) && trim($raw) !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) $req = $decoded;
}
if (!is_array($req)) $req = $_POST;

$csrf = isset($req['csrf_token']) ? (string) $req['csrf_token'] : '';
if (!csrf_validate($csrf)) {
    http_response_code(403);
    nn_store_json('error', 'Security check failed (CSRF). Please refresh and try again.');
}

$action = strtolower(trim((string) ($req['action'] ?? 'save_store')));
if ($action === 'save_store') {
    $store = $req['store'] ?? [];
    [$ok, $summary, $error] = nn_history_replace_user_store($conn, $userId, $store);
    if (!$ok) {
        http_response_code(500);
        nn_store_json('error', $error !== '' ? $error : 'Unable to save history store.');
    }

    nn_store_json('ok', 'History store saved.', [
        'key_count' => (int) ($summary['key_count'] ?? 0),
        'entry_count' => (int) ($summary['entry_count'] ?? 0),
        'skipped_keys' => (int) ($summary['skipped_keys'] ?? 0),
        'skipped_rows' => (int) ($summary['skipped_rows'] ?? 0),
    ]);
}

if ($action === 'save_settings') {
    if (!current_user_is_superadmin()) {
        http_response_code(403);
        nn_store_json('error', 'Only superadmin can update Neighbor Numbers settings.');
    }

    $settingsRaw = $req['settings'] ?? [];
    [$ok, $settings, $error] = nn_settings_save($conn, $settingsRaw, $userId);
    if (!$ok) {
        http_response_code(500);
        nn_store_json('error', $error !== '' ? $error : 'Unable to save settings.');
    }

    nn_store_json('ok', 'Neighbor Numbers settings saved.', [
        'settings' => $settings,
    ]);
}

if ($action === 'save_snapshots') {
    $storeKey = (string) ($req['store_key'] ?? '');
    $cfg = nn_history_cfg_from_store_key($storeKey);
    if (!is_array($cfg)) {
        http_response_code(400);
        nn_store_json('error', 'Invalid table key for snapshots.');
    }

    $accuracyStyle = (string) ($req['accuracy_style'] ?? 'hybrid');
    $historySignature = (string) ($req['history_signature'] ?? '');
    $snapshotsRaw = $req['snapshots'] ?? [];

    [$ok, $savedCount, $error] = nn_snapshot_save_user_key_style(
        $conn,
        $userId,
        $cfg,
        $accuracyStyle,
        $historySignature,
        $snapshotsRaw
    );
    if (!$ok) {
        http_response_code(500);
        nn_store_json('error', $error !== '' ? $error : 'Unable to save algorithm snapshots.');
    }

    nn_store_json('ok', 'Algorithm snapshots saved.', [
        'saved_count' => (int) $savedCount,
    ]);
}

nn_store_json('error', 'Invalid request.');
