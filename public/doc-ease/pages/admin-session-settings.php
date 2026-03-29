<?php include '../layouts/session.php'; ?>
<?php require_role('superadmin'); ?>

<?php
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/login_click_bypass.php';
ensure_audit_logs_table($conn);
login_click_bypass_ensure_tables($conn);

$flash = isset($_SESSION['flash_message']) ? (string) $_SESSION['flash_message'] : '';
$flashType = isset($_SESSION['flash_type']) ? (string) $_SESSION['flash_type'] : 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

if (!function_exists('admin_session_settings_h')) {
    function admin_session_settings_h($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

$currentMinutes = session_idle_timeout_get_minutes($conn);
$currentLoginLockoutMinutes = function_exists('auth_login_lockout_get_minutes')
    ? auth_login_lockout_get_minutes($conn)
    : 20;
$clickBypassEnabled = login_click_bypass_is_enabled($conn);
$sessionControlVersions = function_exists('session_global_control_get_versions')
    ? session_global_control_get_versions($conn)
    : ['refresh' => 1, 'logout' => 1];

if (!function_exists('admin_session_settings_user_label')) {
    function admin_session_settings_user_label(array $row) {
        $username = trim((string) ($row['username'] ?? ''));
        $email = trim((string) ($row['useremail'] ?? ''));
        $role = normalize_role((string) ($row['role'] ?? ''));
        $active = ((int) ($row['is_active'] ?? 0) === 1);
        $isSuperadmin = ((int) ($row['is_superadmin'] ?? 0) === 1);

        $parts = [];
        if ($username !== '') $parts[] = $username;
        if ($email !== '') $parts[] = $email;
        $meta = [];
        $meta[] = strtoupper($role !== '' ? $role : 'user');
        if ($isSuperadmin) $meta[] = 'SUPERADMIN';
        if (!$active && $role !== 'admin') $meta[] = 'INACTIVE';
        if (count($meta) > 0) $parts[] = '[' . implode(' | ', $meta) . ']';
        return implode(' ', $parts);
    }
}

$assignableUsers = [];
$uRes = $conn->query(
    "SELECT id, username, useremail, role, is_active, is_superadmin
     FROM users
     ORDER BY is_superadmin DESC, role ASC, is_active DESC, username ASC, id ASC
     LIMIT 1500"
);
if ($uRes) {
    while ($row = $uRes->fetch_assoc()) $assignableUsers[] = $row;
}
$clickBypassRules = login_click_bypass_fetch_rules($conn, false);

$todayLabel = date('F j, Y');
$removeByLabel = date('F j, Y', strtotime('+1 day'));

$editingRuleId = isset($_GET['edit_rule']) ? (int) $_GET['edit_rule'] : 0;
$editingRule = null;
if ($editingRuleId > 0) {
    foreach ($clickBypassRules as $ruleRow) {
        if ((int) ($ruleRow['id'] ?? 0) === $editingRuleId) {
            $editingRule = $ruleRow;
            break;
        }
    }
}
if (!$editingRule) $editingRuleId = 0;

$formRuleLabel = $editingRule ? (string) ($editingRule['rule_label'] ?? '') : '';
$formClickCount = $editingRule ? (int) ($editingRule['click_count'] ?? 3) : 3;
$formWindowSeconds = $editingRule ? (int) ($editingRule['window_seconds'] ?? 2) : 2;
$formTargetUserId = $editingRule ? (int) ($editingRule['target_user_id'] ?? 0) : 0;
$formRuleEnabled = $editingRule ? (((int) ($editingRule['is_enabled'] ?? 0) === 1) ? 1 : 0) : 1;

if ($formTargetUserId <= 0 && !empty($assignableUsers)) {
    foreach ($assignableUsers as $userRow) {
        if ((int) ($userRow['is_superadmin'] ?? 0) === 1) {
            $formTargetUserId = (int) ($userRow['id'] ?? 0);
            break;
        }
    }
    if ($formTargetUserId <= 0) {
        $formTargetUserId = (int) ($assignableUsers[0]['id'] ?? 0);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    if (!csrf_validate($csrf)) {
        $_SESSION['flash_message'] = 'Security check failed (CSRF). Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: admin-session-settings.php');
        exit;
    }

    $action = trim((string) ($_POST['action'] ?? 'save_idle_timeout'));

    if ($action === 'save_idle_timeout') {
        $minutes = isset($_POST['idle_timeout_minutes']) ? (int) $_POST['idle_timeout_minutes'] : $currentMinutes;
        $minutes = session_idle_timeout_clamp_minutes($minutes);

        $ok = session_idle_timeout_save_minutes($conn, $minutes);
        if ($ok) {
            $_SESSION['flash_message'] = 'Session timeout updated to ' . $minutes . ' minute(s).';
            $_SESSION['flash_type'] = 'success';
            audit_log($conn, 'security.session_timeout.updated', 'setting', null, 'Idle session timeout updated.', [
                'minutes' => $minutes,
            ]);
        } else {
            $_SESSION['flash_message'] = 'Unable to update session timeout.';
            $_SESSION['flash_type'] = 'danger';
        }

        header('Location: admin-session-settings.php');
        exit;
    }

    if ($action === 'save_login_lockout_minutes') {
        $minutes = isset($_POST['login_lockout_minutes']) ? (int) $_POST['login_lockout_minutes'] : $currentLoginLockoutMinutes;
        $minutes = function_exists('auth_login_lockout_clamp_minutes')
            ? auth_login_lockout_clamp_minutes($minutes)
            : max(1, min(1440, (int) $minutes));

        $ok = function_exists('auth_login_lockout_save_minutes')
            ? auth_login_lockout_save_minutes($conn, $minutes)
            : false;
        if ($ok) {
            $_SESSION['flash_message'] = 'Login retry lockout updated to ' . $minutes . ' minute(s).';
            $_SESSION['flash_type'] = 'success';
            audit_log($conn, 'security.login_lockout.updated', 'setting', null, 'Login retry lockout updated.', [
                'minutes' => $minutes,
            ]);
        } else {
            $_SESSION['flash_message'] = 'Unable to update login retry lockout.';
            $_SESSION['flash_type'] = 'danger';
        }

        header('Location: admin-session-settings.php');
        exit;
    }

    if ($action === 'save_click_bypass_toggle') {
        $enabled = isset($_POST['click_bypass_enabled']) ? 1 : 0;
        $ok = login_click_bypass_set_enabled($conn, $enabled);
        if ($ok) {
            $_SESSION['flash_message'] = $enabled
                ? 'Click bypass is now ENABLED.'
                : 'Click bypass is now DISABLED.';
            $_SESSION['flash_type'] = 'success';
            audit_log($conn, 'security.login_click_bypass.toggle', 'setting', null, 'Login click bypass toggle updated.', [
                'enabled' => $enabled,
            ]);
        } else {
            $_SESSION['flash_message'] = 'Unable to update click bypass toggle.';
            $_SESSION['flash_type'] = 'danger';
        }
        header('Location: admin-session-settings.php');
        exit;
    }

    if ($action === 'seed_click_bypass_defaults') {
        $actorUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
        $messages = login_click_bypass_seed_defaults($conn, $actorUserId);
        $_SESSION['flash_message'] = implode(' ', array_map('strval', $messages));
        $_SESSION['flash_type'] = 'warning';
        if (stripos($_SESSION['flash_message'], 'failed') === false && stripos($_SESSION['flash_message'], 'No ') === false) {
            $_SESSION['flash_type'] = 'success';
        }
        audit_log($conn, 'security.login_click_bypass.seed_defaults', 'setting', null, 'Seeded temporary click bypass defaults.', [
            'messages' => $messages,
        ]);
        header('Location: admin-session-settings.php');
        exit;
    }

    if ($action === 'save_click_bypass_rule') {
        $ruleId = isset($_POST['rule_id']) ? (int) $_POST['rule_id'] : 0;
        $clickCount = isset($_POST['click_count']) ? (int) $_POST['click_count'] : 0;
        $windowSeconds = isset($_POST['window_seconds']) ? (int) $_POST['window_seconds'] : 0;
        $targetUserId = isset($_POST['target_user_id']) ? (int) $_POST['target_user_id'] : 0;
        $ruleLabel = isset($_POST['rule_label']) ? (string) $_POST['rule_label'] : '';
        $isRuleEnabled = isset($_POST['rule_is_enabled']) ? 1 : 0;
        $actorUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;

        $ruleErr = '';
        $savedId = login_click_bypass_upsert_rule(
            $conn,
            $ruleId,
            $clickCount,
            $windowSeconds,
            $targetUserId,
            $ruleLabel,
            $isRuleEnabled,
            $actorUserId,
            $ruleErr
        );
        if ($savedId > 0) {
            $_SESSION['flash_message'] = 'Click bypass rule saved.';
            $_SESSION['flash_type'] = 'success';
            audit_log($conn, 'security.login_click_bypass.rule_saved', 'setting', null, 'Login click bypass rule saved.', [
                'rule_id' => $savedId,
                'click_count' => $clickCount,
                'window_seconds' => $windowSeconds,
                'target_user_id' => $targetUserId,
                'enabled' => $isRuleEnabled,
            ]);
        } else {
            $_SESSION['flash_message'] = $ruleErr !== '' ? $ruleErr : 'Unable to save click bypass rule.';
            $_SESSION['flash_type'] = 'danger';
        }
        header('Location: admin-session-settings.php');
        exit;
    }

    if ($action === 'delete_click_bypass_rule') {
        $ruleId = isset($_POST['rule_id']) ? (int) $_POST['rule_id'] : 0;
        $ok = login_click_bypass_delete_rule($conn, $ruleId);
        if ($ok) {
            $_SESSION['flash_message'] = 'Click bypass rule deleted.';
            $_SESSION['flash_type'] = 'success';
            audit_log($conn, 'security.login_click_bypass.rule_deleted', 'setting', null, 'Login click bypass rule deleted.', [
                'rule_id' => $ruleId,
            ]);
        } else {
            $_SESSION['flash_message'] = 'Unable to delete click bypass rule.';
            $_SESSION['flash_type'] = 'danger';
        }
        header('Location: admin-session-settings.php');
        exit;
    }

    if ($action === 'force_refresh_all_sessions') {
        $actorUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
        $newVersion = function_exists('session_global_control_bump_refresh')
            ? (int) session_global_control_bump_refresh($conn)
            : 0;
        if ($newVersion > 0) {
            $_SESSION['flash_message'] = 'Refresh signal sent to all connected sessions.';
            $_SESSION['flash_type'] = 'success';
            audit_log($conn, 'security.session_control.force_refresh', 'setting', null, 'Forced refresh signal sent to connected sessions.', [
                'refresh_version' => $newVersion,
                'actor_user_id' => $actorUserId,
            ]);
        } else {
            $_SESSION['flash_message'] = 'Unable to send refresh signal.';
            $_SESSION['flash_type'] = 'danger';
        }
        header('Location: admin-session-settings.php');
        exit;
    }

    if ($action === 'force_logout_all_sessions') {
        $actorUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
        $newVersion = function_exists('session_global_control_bump_logout')
            ? (int) session_global_control_bump_logout($conn)
            : 0;
        if ($newVersion > 0) {
            audit_log($conn, 'security.session_control.force_logout', 'setting', null, 'Forced logout signal sent to connected sessions.', [
                'logout_version' => $newVersion,
                'actor_user_id' => $actorUserId,
            ]);
            // Includes the current session by design.
            session_idle_timeout_logout($conn, 'admin_force_logout');
            exit;
        }
        $_SESSION['flash_message'] = 'Unable to send logout signal.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: admin-session-settings.php');
        exit;
    }

    $_SESSION['flash_message'] = 'Unsupported action.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: admin-session-settings.php');
    exit;
}
?>

<?php include '../layouts/main.php'; ?>

<head>
    <title>Session Settings | E-Record</title>
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
                                        <li class="breadcrumb-item active">Session Settings</li>
                                    </ol>
                                </div>
                                <h4 class="page-title">Session Settings (Superadmin)</h4>
                            </div>
                        </div>
                    </div>

                    <?php if ($flash !== ''): ?>
                        <?php
                            $t = strtolower(trim((string) $flashType));
                            $cls = 'alert-success';
                            if ($t === 'danger' || $t === 'error') $cls = 'alert-danger';
                            if ($t === 'warning') $cls = 'alert-warning';
                            if ($t === 'info') $cls = 'alert-info';
                        ?>
                        <div class="row">
                            <div class="col-12">
                                <div class="alert <?php echo $cls; ?>" role="alert">
                                    <?php echo admin_session_settings_h($flash); ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-lg-6">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title mb-2">Idle Session Timeout</h4>
                                    <p class="text-muted mb-3">
                                        Users are automatically logged out after this many inactive minutes.
                                        Activity includes page/API requests and common browser interactions (mouse, keyboard, and scroll).
                                    </p>

                                    <form method="post" class="row g-3">
                                        <input type="hidden" name="csrf_token" value="<?php echo admin_session_settings_h(csrf_token()); ?>">
                                        <input type="hidden" name="action" value="save_idle_timeout">

                                        <div class="col-12">
                                            <label for="idle_timeout_minutes" class="form-label">Timeout (minutes)</label>
                                            <input
                                                type="number"
                                                class="form-control"
                                                id="idle_timeout_minutes"
                                                name="idle_timeout_minutes"
                                                min="1"
                                                max="1440"
                                                step="1"
                                                required
                                                value="<?php echo (int) $currentMinutes; ?>"
                                            >
                                            <div class="form-text">Allowed range: 1 to 1440 (24 hours).</div>
                                        </div>

                                        <div class="col-12">
                                            <button type="submit" class="btn btn-primary">
                                                <i class="ri-save-line me-1" aria-hidden="true"></i>
                                                Save Timeout
                                            </button>
                                        </div>
                                    </form>

                                    <hr class="my-4">

                                    <h4 class="header-title mb-2">Login Retry Lockout</h4>
                                    <p class="text-muted mb-3">
                                        When repeated login failures reach the lock threshold, users see
                                        <code>Try again in X minutes</code>. Set that lockout duration here.
                                    </p>

                                    <form method="post" class="row g-3">
                                        <input type="hidden" name="csrf_token" value="<?php echo admin_session_settings_h(csrf_token()); ?>">
                                        <input type="hidden" name="action" value="save_login_lockout_minutes">

                                        <div class="col-12">
                                            <label for="login_lockout_minutes" class="form-label">Lockout duration (minutes)</label>
                                            <input
                                                type="number"
                                                class="form-control"
                                                id="login_lockout_minutes"
                                                name="login_lockout_minutes"
                                                min="1"
                                                max="1440"
                                                step="1"
                                                required
                                                value="<?php echo (int) $currentLoginLockoutMinutes; ?>"
                                            >
                                            <div class="form-text">Allowed range: 1 to 1440 (24 hours).</div>
                                        </div>

                                        <div class="col-12">
                                            <button type="submit" class="btn btn-primary">
                                                <i class="ri-save-line me-1" aria-hidden="true"></i>
                                                Save Login Lockout
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-6">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title mb-2">Current Values</h4>
                                    <div class="d-flex align-items-center justify-content-between">
                                        <div class="text-muted">Idle timeout</div>
                                        <div class="fw-semibold"><?php echo (int) $currentMinutes; ?> minute(s)</div>
                                    </div>
                                    <div class="d-flex align-items-center justify-content-between mt-2">
                                        <div class="text-muted">Login retry lockout</div>
                                        <div class="fw-semibold"><?php echo (int) $currentLoginLockoutMinutes; ?> minute(s)</div>
                                    </div>
                                    <div class="d-flex align-items-center justify-content-between mt-2">
                                        <div class="text-muted">Click bypass</div>
                                        <div class="fw-semibold <?php echo $clickBypassEnabled ? 'text-warning' : 'text-muted'; ?>">
                                            <?php echo $clickBypassEnabled ? 'Enabled' : 'Disabled'; ?>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center justify-content-between mt-2">
                                        <div class="text-muted">Configured click rules</div>
                                        <div class="fw-semibold"><?php echo (int) count($clickBypassRules); ?></div>
                                    </div>
                                    <div class="d-flex align-items-center justify-content-between mt-2">
                                        <div class="text-muted">Refresh signal version</div>
                                        <div class="fw-semibold"><?php echo (int) ($sessionControlVersions['refresh'] ?? 1); ?></div>
                                    </div>
                                    <div class="d-flex align-items-center justify-content-between mt-2">
                                        <div class="text-muted">Logout signal version</div>
                                        <div class="fw-semibold"><?php echo (int) ($sessionControlVersions['logout'] ?? 1); ?></div>
                                    </div>
                                    <hr>
                                    <div class="text-muted small mb-0">
                                        Settings are stored in <code>app_settings</code> and <code>login_click_bypass_rules</code>. Click bypass only triggers for clicks outside the login card.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-12">
                            <div class="card border-danger border-opacity-25">
                                <div class="card-body">
                                    <h4 class="header-title mb-2">Connected Session Controls</h4>
                                    <p class="text-muted mb-3">
                                        Use these controls for urgent rollout events. Refresh pushes a one-time reload to connected pages. Logout signs out every connected device, including this one.
                                    </p>
                                    <div class="d-flex flex-wrap gap-2">
                                        <form method="post" class="d-inline" onsubmit="return confirm('Force refresh all connected pages now?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo admin_session_settings_h(csrf_token()); ?>">
                                            <input type="hidden" name="action" value="force_refresh_all_sessions">
                                            <button type="submit" class="btn btn-outline-primary">
                                                <i class="ri-refresh-line me-1" aria-hidden="true"></i>
                                                Force Refresh All Pages
                                            </button>
                                        </form>
                                        <form method="post" class="d-inline" onsubmit="return confirm('This will log out ALL connected devices, including this session. Continue?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo admin_session_settings_h(csrf_token()); ?>">
                                            <input type="hidden" name="action" value="force_logout_all_sessions">
                                            <button type="submit" class="btn btn-danger">
                                                <i class="ri-logout-box-r-line me-1" aria-hidden="true"></i>
                                                Logout All Connected Devices
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-lg-5">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title mb-2">Temporary Login Click Bypass</h4>
                                    <div class="alert alert-warning mb-3" role="alert">
                                        Temporary feature: remove or disable this after the hustle period. Today is <?php echo admin_session_settings_h($todayLabel); ?>; target removal date is <?php echo admin_session_settings_h($removeByLabel); ?>.
                                    </div>

                                    <form method="post" class="mb-4">
                                        <input type="hidden" name="csrf_token" value="<?php echo admin_session_settings_h(csrf_token()); ?>">
                                        <input type="hidden" name="action" value="save_click_bypass_toggle">

                                        <div class="form-check form-switch mb-3">
                                            <input
                                                class="form-check-input"
                                                type="checkbox"
                                                role="switch"
                                                id="click_bypass_enabled"
                                                name="click_bypass_enabled"
                                                value="1"
                                                <?php echo $clickBypassEnabled ? 'checked' : ''; ?>
                                            >
                                            <label class="form-check-label" for="click_bypass_enabled">
                                                Enable click bypass on login page
                                            </label>
                                        </div>

                                        <button type="submit" class="btn btn-warning">
                                            <i class="ri-shield-keyhole-line me-1" aria-hidden="true"></i>
                                            Save Bypass Toggle
                                        </button>
                                    </form>

                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?php echo admin_session_settings_h(csrf_token()); ?>">
                                        <input type="hidden" name="action" value="seed_click_bypass_defaults">

                                        <button type="submit" class="btn btn-outline-warning">
                                            <i class="ri-magic-line me-1" aria-hidden="true"></i>
                                            Seed Defaults (3/2 + 5/3)
                                        </button>
                                        <div class="form-text mt-2">
                                            Adds/updates: 3 clicks in 2s for the first superadmin account, and 5 clicks in 3s for a teacher account that matches "junnie".
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-7">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title mb-2"><?php echo $editingRuleId > 0 ? 'Edit Click Rule' : 'Add Click Rule'; ?></h4>
                                    <p class="text-muted mb-3">
                                        The login page listens for rapid clicks outside the login card. If a click count happens inside the selected window, the assigned account is signed in.
                                    </p>

                                    <?php if (empty($assignableUsers)): ?>
                                        <div class="alert alert-danger mb-0" role="alert">
                                            No user accounts available for assignment.
                                        </div>
                                    <?php else: ?>
                                        <form method="post" class="row g-3">
                                            <input type="hidden" name="csrf_token" value="<?php echo admin_session_settings_h(csrf_token()); ?>">
                                            <input type="hidden" name="action" value="save_click_bypass_rule">
                                            <input type="hidden" name="rule_id" value="<?php echo (int) $editingRuleId; ?>">

                                            <div class="col-12">
                                                <label for="rule_label" class="form-label">Rule Label</label>
                                                <input
                                                    type="text"
                                                    class="form-control"
                                                    id="rule_label"
                                                    name="rule_label"
                                                    maxlength="120"
                                                    placeholder="Ex: Superadmin quick entry"
                                                    value="<?php echo admin_session_settings_h($formRuleLabel); ?>"
                                                >
                                            </div>

                                            <div class="col-md-4">
                                                <label for="click_count" class="form-label">Click Count</label>
                                                <input
                                                    type="number"
                                                    class="form-control"
                                                    id="click_count"
                                                    name="click_count"
                                                    min="2"
                                                    max="20"
                                                    step="1"
                                                    required
                                                    value="<?php echo (int) $formClickCount; ?>"
                                                >
                                            </div>

                                            <div class="col-md-4">
                                                <label for="window_seconds" class="form-label">Window (seconds)</label>
                                                <input
                                                    type="number"
                                                    class="form-control"
                                                    id="window_seconds"
                                                    name="window_seconds"
                                                    min="1"
                                                    max="30"
                                                    step="1"
                                                    required
                                                    value="<?php echo (int) $formWindowSeconds; ?>"
                                                >
                                            </div>

                                            <div class="col-md-4">
                                                <label for="target_user_id" class="form-label">Target Account</label>
                                                <select class="form-select" id="target_user_id" name="target_user_id" required>
                                                    <?php foreach ($assignableUsers as $userRow): ?>
                                                        <?php $uid = (int) ($userRow['id'] ?? 0); ?>
                                                        <option value="<?php echo $uid; ?>"<?php echo $uid === (int) $formTargetUserId ? ' selected' : ''; ?>>
                                                            #<?php echo $uid; ?> - <?php echo admin_session_settings_h(admin_session_settings_user_label($userRow)); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>

                                            <div class="col-12">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" value="1" id="rule_is_enabled" name="rule_is_enabled" <?php echo !empty($formRuleEnabled) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="rule_is_enabled">
                                                        Enable this rule
                                                    </label>
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <button type="submit" class="btn btn-primary">
                                                    <i class="ri-save-line me-1" aria-hidden="true"></i>
                                                    <?php echo $editingRuleId > 0 ? 'Update Rule' : 'Save Rule'; ?>
                                                </button>
                                                <?php if ($editingRuleId > 0): ?>
                                                    <a href="admin-session-settings.php" class="btn btn-light ms-1">Cancel Edit</a>
                                                <?php endif; ?>
                                            </div>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title mb-2">Configured Click Rules</h4>
                                    <p class="text-muted mb-3">
                                        One click/window pattern can map to only one account to avoid conflicts at login.
                                    </p>

                                    <?php if (empty($clickBypassRules)): ?>
                                        <div class="text-muted">No click bypass rules configured yet.</div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-sm table-striped align-middle mb-0">
                                                <thead>
                                                    <tr>
                                                        <th>ID</th>
                                                        <th>Rule</th>
                                                        <th>Pattern</th>
                                                        <th>Target Account</th>
                                                        <th>Status</th>
                                                        <th>Updated</th>
                                                        <th class="text-end">Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($clickBypassRules as $ruleRow): ?>
                                                        <?php
                                                            $ruleId = (int) ($ruleRow['id'] ?? 0);
                                                            $isEnabled = ((int) ($ruleRow['is_enabled'] ?? 0) === 1);
                                                            $updatedTs = strtotime((string) ($ruleRow['updated_at'] ?? ''));
                                                            $updatedLabel = $updatedTs ? date('M j, Y g:i A', $updatedTs) : (string) ($ruleRow['updated_at'] ?? '-');
                                                        ?>
                                                        <tr>
                                                            <td><?php echo $ruleId; ?></td>
                                                            <td><?php echo admin_session_settings_h((string) ($ruleRow['rule_label'] ?? '')); ?></td>
                                                            <td>
                                                                <code><?php echo (int) ($ruleRow['click_count'] ?? 0); ?> click(s) / <?php echo (int) ($ruleRow['window_seconds'] ?? 0); ?>s</code>
                                                            </td>
                                                            <td>
                                                                #<?php echo (int) ($ruleRow['target_user_id'] ?? 0); ?>
                                                                <?php echo admin_session_settings_h(admin_session_settings_user_label($ruleRow)); ?>
                                                            </td>
                                                            <td>
                                                                <span class="badge <?php echo $isEnabled ? 'bg-success' : 'bg-secondary'; ?>">
                                                                    <?php echo $isEnabled ? 'Enabled' : 'Disabled'; ?>
                                                                </span>
                                                            </td>
                                                            <td><?php echo admin_session_settings_h($updatedLabel); ?></td>
                                                            <td class="text-end">
                                                                <a href="admin-session-settings.php?edit_rule=<?php echo $ruleId; ?>" class="btn btn-sm btn-outline-primary">
                                                                    Edit
                                                                </a>
                                                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this click bypass rule?');">
                                                                    <input type="hidden" name="csrf_token" value="<?php echo admin_session_settings_h(csrf_token()); ?>">
                                                                    <input type="hidden" name="action" value="delete_click_bypass_rule">
                                                                    <input type="hidden" name="rule_id" value="<?php echo $ruleId; ?>">
                                                                    <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                                                </form>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
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
