<?php
declare(strict_types=1);

$setupDbEnabled = trim((string) getenv('DOC_EASE_ENABLE_SETUP_DB'));
if ($setupDbEnabled !== '1') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found.';
    exit;
}

// ---------------------------------------------------------------------
// One-time MySQL database creator (for VPS installs).
//
// SECURITY MODEL:
// - Disabled by default unless config/setup-db.php exists and enabled=true.
// - Requires a secret token (?token=...).
// - Optional IP allow list.
// - Creates a lock file after a successful run to prevent reuse.
//
// This is intentionally NOT wired into in-app "superadmin" because the
// database may not exist yet (so session-based auth can't work).
// ---------------------------------------------------------------------

function setup_h($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function setup_mysql_ident(string $name): string {
    // Defensive quoting for identifiers. We still validate strict patterns below.
    return '`' . str_replace('`', '``', $name) . '`';
}

function setup_read_config(): array {
    $cfgFile = __DIR__ . '/config/setup-db.php';
    if (!is_file($cfgFile)) return [];
    $cfg = include $cfgFile;
    return is_array($cfg) ? $cfg : [];
}

function setup_abort(int $statusCode, string $message): void {
    http_response_code($statusCode);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

function setup_detect_web_server(): array {
    $software = (string) ($_SERVER['SERVER_SOFTWARE'] ?? '');
    $sw = strtolower($software);

    $name = 'unknown';
    $label = 'Unknown';

    if (strpos($sw, 'openresty') !== false || strpos($sw, 'nginx') !== false) {
        $name = 'nginx';
        $label = 'Nginx';
    } elseif (strpos($sw, 'openlitespeed') !== false || strpos($sw, 'litespeed') !== false) {
        $name = 'litespeed';
        $label = 'LiteSpeed';
    } elseif (strpos($sw, 'apache') !== false) {
        $name = 'apache';
        $label = 'Apache';
    } elseif (strpos($sw, 'caddy') !== false) {
        $name = 'caddy';
        $label = 'Caddy';
    } elseif (function_exists('apache_get_version')) {
        $name = 'apache';
        $label = 'Apache';
    }

    return [
        'name' => $name,
        'label' => $label,
        'server_software' => $software,
        'sapi' => (string) php_sapi_name(),
    ];
}

$cfg = setup_read_config();
$enabled = (bool) ($cfg['enabled'] ?? false);
if (!$enabled) {
    setup_abort(404, 'Not found.');
}

$token = (string) ($cfg['token'] ?? '');
if ($token === '' || $token === 'CHANGE_ME') {
    setup_abort(500, 'Setup is enabled but token is not configured.');
}

$allowedIps = $cfg['allowed_ips'] ?? [];
if (!is_array($allowedIps)) $allowedIps = [];
$lockFile = (string) ($cfg['lock_file'] ?? (__DIR__ . '/config/setup-db.lock'));
if ($lockFile === '') $lockFile = (__DIR__ . '/config/setup-db.lock');

if (is_file($lockFile)) {
    setup_abort(410, 'Setup is locked (already executed). Remove the lock file only if you know what you are doing.');
}

$remoteIp = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
if (count($allowedIps) > 0 && !in_array($remoteIp, $allowedIps, true)) {
    setup_abort(403, 'Forbidden (IP not allowed).');
}

$serverInfo = setup_detect_web_server();
$serverName = (string) ($serverInfo['name'] ?? 'unknown');
$serverLabel = (string) ($serverInfo['label'] ?? 'Unknown');
$serverSoftware = (string) ($serverInfo['server_software'] ?? '');

$providedToken = '';
if (isset($_POST['token'])) $providedToken = (string) $_POST['token'];
if ($providedToken === '' && isset($_GET['token'])) $providedToken = (string) $_GET['token'];
if ($providedToken === '' || !hash_equals($token, $providedToken)) {
    setup_abort(403, 'Forbidden (invalid token).');
}

$flash = '';
$flashType = 'info';
$resultLines = [];

$defaults = [
    'mysql_host' => 'localhost',
    'mysql_port' => '3306',
    'admin_user' => 'root',
    'admin_pass' => '',
    'db_name' => 'doc_ease',
    'create_app_user' => '1',
    'app_user' => 'doc_ease_app',
    'app_pass' => '',
    'app_host' => 'localhost',
    'create_lock' => '1',
];

$values = $defaults;
foreach ($defaults as $k => $v) {
    if (isset($_POST[$k])) {
        $values[$k] = is_string($_POST[$k]) ? (string) $_POST[$k] : $v;
    } elseif (isset($_GET[$k])) {
        $values[$k] = is_string($_GET[$k]) ? (string) $_GET[$k] : $v;
    }
}

$nginxSnippet = <<<'NGINX'
# Example Nginx rules for Doc-Ease (project root as web root)
#
# IMPORTANT:
# - Nginx ignores .htaccess, so you must configure rewrites here.
# - Adjust "fastcgi_pass" to match your PHP-FPM socket/version.
#

# Deny sensitive folders (especially backups)
location ^~ /config/ { deny all; }
location ^~ /sql/ { deny all; }
location ^~ /tools/ { deny all; }

# Hide internal app pages folder from direct access (allow internal rewrites only)
location ~ ^/pages/.*\.php$ {
    internal;
    include snippets/fastcgi-php.conf;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
}
location ~ ^/pages/ { internal; return 404; }

# Root aliases for moved pages
location = /auth-login.php { rewrite ^ /pages/auth/auth-login.php last; }
location = /auth-logout.php { rewrite ^ /pages/auth/auth-logout.php last; }
location = /auth-register.php { rewrite ^ /pages/auth/auth-register.php last; }
location = /auth-student-enroll.php { rewrite ^ /pages/auth/auth-student-enroll.php last; }

location = /get_monthly_uploads.php { rewrite ^ /pages/api/get_monthly_uploads.php last; }
location = /get_student_name.php { rewrite ^ /pages/api/get_student_name.php last; }

location = /todays_act.php { rewrite ^ /pages/student/todays_act.php last; }

index index.php;

location / {
    try_files $uri $uri/ =404;
}

location ~ \.php$ {
    try_files $uri /pages$uri =404;
    include snippets/fastcgi-php.conf;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
}
NGINX;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mysqlHost = trim((string) ($values['mysql_host'] ?? 'localhost'));
    $mysqlPort = (int) trim((string) ($values['mysql_port'] ?? '3306'));
    $adminUser = trim((string) ($values['admin_user'] ?? 'root'));
    $adminPass = (string) ($values['admin_pass'] ?? '');
    $dbName = trim((string) ($values['db_name'] ?? 'doc_ease'));

    $createAppUser = !empty($values['create_app_user']);
    $appUser = trim((string) ($values['app_user'] ?? ''));
    $appPass = (string) ($values['app_pass'] ?? '');
    $appHost = trim((string) ($values['app_host'] ?? 'localhost'));

    $createLock = !empty($values['create_lock']);

    if ($mysqlHost === '') {
        $flashType = 'danger';
        $flash = 'MySQL host is required.';
    } elseif ($mysqlPort <= 0 || $mysqlPort > 65535) {
        $flashType = 'danger';
        $flash = 'MySQL port must be a valid number (1-65535).';
    } elseif ($adminUser === '') {
        $flashType = 'danger';
        $flash = 'Admin username is required.';
    } elseif ($dbName === '') {
        $flashType = 'danger';
        $flash = 'Database name is required.';
    } elseif (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
        $flashType = 'danger';
        $flash = 'Database name must match: A-Z a-z 0-9 _';
    } elseif ($createAppUser && $appUser === '') {
        $flashType = 'danger';
        $flash = 'App username is required when "Create app DB user" is enabled.';
    } elseif ($createAppUser && !preg_match('/^[A-Za-z0-9_]+$/', $appUser)) {
        $flashType = 'danger';
        $flash = 'App username must match: A-Z a-z 0-9 _';
    } elseif ($createAppUser && $appHost === '') {
        $flashType = 'danger';
        $flash = 'App host is required (usually localhost).';
    } elseif ($createAppUser && !preg_match('/^[A-Za-z0-9._%\\-]+$/', $appHost)) {
        $flashType = 'danger';
        $flash = 'App host contains invalid characters.';
    } else {
        mysqli_report(MYSQLI_REPORT_OFF);
        $mysqli = @new mysqli($mysqlHost, $adminUser, $adminPass, '', $mysqlPort);
        if ($mysqli->connect_errno) {
            $flashType = 'danger';
            $flash = 'MySQL connection failed: ' . $mysqli->connect_error;
        } else {
            $mysqli->set_charset('utf8mb4');
            $dbIdent = setup_mysql_ident($dbName);

            $ok = true;
            $createDbSql = "CREATE DATABASE IF NOT EXISTS {$dbIdent} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
            if (!$mysqli->query($createDbSql)) {
                $ok = false;
                $resultLines[] = 'ERROR creating database: ' . $mysqli->error;
            } else {
                $resultLines[] = 'OK: Database ensured: ' . $dbName;
            }

            if ($ok && $createAppUser) {
                $userEsc = $mysqli->real_escape_string($appUser);
                $hostEsc = $mysqli->real_escape_string($appHost);
                $passEsc = $mysqli->real_escape_string($appPass);

                // CREATE USER (best-effort, supports older servers by falling back).
                $createUserSql = "CREATE USER IF NOT EXISTS '{$userEsc}'@'{$hostEsc}' IDENTIFIED BY '{$passEsc}'";
                if (!$mysqli->query($createUserSql)) {
                    $fallbackOk = false;
                    $fallbackSql = "CREATE USER '{$userEsc}'@'{$hostEsc}' IDENTIFIED BY '{$passEsc}'";
                    if ($mysqli->query($fallbackSql)) {
                        $fallbackOk = true;
                    } else {
                        // If user already exists, MySQL returns 1396.
                        if ((int) $mysqli->errno === 1396) $fallbackOk = true;
                    }

                    if ($fallbackOk) {
                        $resultLines[] = 'OK: App user ensured (existing or created): ' . $appUser . '@' . $appHost;
                    } else {
                        $ok = false;
                        $resultLines[] = 'ERROR creating app user: ' . $mysqli->error;
                    }
                } else {
                    $resultLines[] = 'OK: App user ensured: ' . $appUser . '@' . $appHost;
                }

                if ($ok) {
                    $grantSql = "GRANT ALL PRIVILEGES ON {$dbIdent}.* TO '{$userEsc}'@'{$hostEsc}'";
                    if (!$mysqli->query($grantSql)) {
                        $ok = false;
                        $resultLines[] = 'ERROR granting privileges: ' . $mysqli->error;
                    } else {
                        $resultLines[] = 'OK: Granted privileges on ' . $dbName . '.*';
                    }
                }

                if ($ok) {
                    // Non-fatal on some platforms, but useful.
                    if (!$mysqli->query("FLUSH PRIVILEGES")) {
                        $resultLines[] = 'WARN: FLUSH PRIVILEGES failed: ' . $mysqli->error;
                    } else {
                        $resultLines[] = 'OK: Flushed privileges';
                    }
                }
            }

            if ($ok && $createLock) {
                $lockDir = dirname($lockFile);
                if (!is_dir($lockDir)) {
                    $resultLines[] = 'WARN: Lock directory does not exist: ' . $lockDir;
                } else {
                    $wrote = @file_put_contents($lockFile, "locked\n");
                    if ($wrote === false) {
                        $resultLines[] = 'WARN: Could not write lock file: ' . $lockFile;
                    } else {
                        $resultLines[] = 'OK: Wrote lock file: ' . $lockFile;
                    }
                }
            }

            if ($ok) {
                $flashType = 'success';
                $flash = 'Database setup completed.';
            } else {
                $flashType = 'danger';
                $flash = 'Database setup failed. See details below.';
            }

            $mysqli->close();
        }
    }
}

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Setup DB | E-Record</title>
  <style>
    :root { color-scheme: light; }
    body { margin: 0; font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background: #f6f8fb; color: #0f172a; }
    .wrap { max-width: 980px; margin: 0 auto; padding: 28px 16px; }
    .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 18px; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06); }
    .row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    @media (max-width: 860px) { .row { grid-template-columns: 1fr; } }
    h1 { margin: 0 0 10px 0; font-size: 20px; }
    p { margin: 0 0 10px 0; color: #334155; }
    label { display: block; font-size: 13px; font-weight: 600; color: #0f172a; margin-bottom: 6px; }
    input, select { width: 100%; padding: 10px 10px; border: 1px solid #cbd5e1; border-radius: 10px; font-size: 14px; }
    input[type="password"] { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace; }
    .actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 12px; }
    button { border: 0; border-radius: 10px; padding: 10px 14px; font-weight: 700; cursor: pointer; }
    .btn-primary { background: #2563eb; color: #fff; }
    .btn-muted { background: #e2e8f0; color: #0f172a; }
    .note { background: #f1f5f9; border: 1px dashed #cbd5e1; border-radius: 12px; padding: 12px; color: #334155; font-size: 13px; }
    .alert { border-radius: 12px; padding: 12px; margin: 12px 0; border: 1px solid transparent; }
    .alert-success { background: #ecfdf5; border-color: #10b981; color: #065f46; }
    .alert-danger { background: #fef2f2; border-color: #ef4444; color: #7f1d1d; }
    .alert-info { background: #eff6ff; border-color: #3b82f6; color: #1e3a8a; }
    pre { margin: 0; white-space: pre-wrap; word-break: break-word; font-size: 12px; background: #0b1220; color: #e2e8f0; padding: 12px; border-radius: 12px; overflow: auto; }
    code { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace; }
    .muted { color: #64748b; font-size: 12px; }
    .checkrow { display: flex; gap: 10px; align-items: center; margin-top: 10px; }
    .checkrow input { width: auto; }
    .footer { margin-top: 16px; color: #64748b; font-size: 12px; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <h1>One-Time DB Setup</h1>
      <p>Create a MySQL database (and optionally an app DB user) from the browser. Recommended for VPS installs only.</p>
      <div class="note">
        <div><strong>Important:</strong> Disable this page after use by deleting <code>config/setup-db.php</code> and keeping the lock file.</div>
        <div class="muted">Your IP: <code><?php echo setup_h($remoteIp); ?></code></div>
        <div class="muted">
          Server detected: <code><?php echo setup_h($serverLabel); ?></code>
          <?php if ($serverSoftware !== ''): ?>
            <span>(<?php echo setup_h($serverSoftware); ?>)</span>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($serverName === 'nginx'): ?>
        <div class="alert alert-danger">
          <strong>Nginx detected.</strong> This project uses <code>.htaccess</code> rewrite rules. Nginx ignores <code>.htaccess</code>, so you must add equivalent Nginx rules (and deny access to <code>/config/</code>, especially <code>/config/database/backup/</code>).
          <details style="margin-top: 10px;">
            <summary style="cursor: pointer; font-weight: 700;">Show example Nginx snippet</summary>
            <div class="muted" style="margin-top: 8px;">Adjust <code>fastcgi_pass</code> to match your PHP-FPM socket/version.</div>
            <pre style="margin-top: 8px;"><?php echo setup_h($nginxSnippet); ?></pre>
          </details>
        </div>
      <?php endif; ?>

      <?php if ($flash !== ''): ?>
        <div class="alert <?php echo $flashType === 'success' ? 'alert-success' : ($flashType === 'danger' ? 'alert-danger' : 'alert-info'); ?>">
          <?php echo setup_h($flash); ?>
        </div>
      <?php endif; ?>

      <form method="post" autocomplete="off">
        <input type="hidden" name="token" value="<?php echo setup_h($providedToken); ?>" />

        <h2 style="margin: 14px 0 10px 0; font-size: 16px;">MySQL Admin Connection</h2>
        <div class="row">
          <div>
            <label for="mysql_host">MySQL Host</label>
            <input id="mysql_host" name="mysql_host" value="<?php echo setup_h($values['mysql_host']); ?>" />
          </div>
          <div>
            <label for="mysql_port">MySQL Port</label>
            <input id="mysql_port" name="mysql_port" value="<?php echo setup_h($values['mysql_port']); ?>" />
          </div>
          <div>
            <label for="admin_user">Admin Username</label>
            <input id="admin_user" name="admin_user" value="<?php echo setup_h($values['admin_user']); ?>" />
          </div>
          <div>
            <label for="admin_pass">Admin Password</label>
            <input id="admin_pass" name="admin_pass" type="password" value="<?php echo setup_h($values['admin_pass']); ?>" />
          </div>
        </div>

        <h2 style="margin: 14px 0 10px 0; font-size: 16px;">Database</h2>
        <div class="row">
          <div>
            <label for="db_name">Database Name</label>
            <input id="db_name" name="db_name" value="<?php echo setup_h($values['db_name']); ?>" />
            <div class="muted">Allowed: <code>A-Z a-z 0-9 _</code></div>
          </div>
          <div></div>
        </div>

        <h2 style="margin: 14px 0 10px 0; font-size: 16px;">App DB User (Optional)</h2>
        <div class="checkrow">
          <input id="create_app_user" name="create_app_user" type="checkbox" value="1" <?php echo !empty($values['create_app_user']) ? 'checked' : ''; ?> />
          <label for="create_app_user" style="margin: 0;">Create app DB user and grant privileges</label>
        </div>
        <div class="row" style="margin-top: 10px;">
          <div>
            <label for="app_user">App Username</label>
            <input id="app_user" name="app_user" value="<?php echo setup_h($values['app_user']); ?>" />
            <div class="muted">Allowed: <code>A-Z a-z 0-9 _</code></div>
          </div>
          <div>
            <label for="app_host">App Host</label>
            <input id="app_host" name="app_host" value="<?php echo setup_h($values['app_host']); ?>" />
            <div class="muted">Typical: <code>localhost</code></div>
          </div>
          <div>
            <label for="app_pass">App Password</label>
            <input id="app_pass" name="app_pass" type="password" value="<?php echo setup_h($values['app_pass']); ?>" />
          </div>
          <div></div>
        </div>

        <div class="checkrow">
          <input id="create_lock" name="create_lock" type="checkbox" value="1" <?php echo !empty($values['create_lock']) ? 'checked' : ''; ?> />
          <label for="create_lock" style="margin: 0;">Create lock file after success</label>
        </div>

        <div class="actions">
          <button class="btn-primary" type="submit">Create Database</button>
          <a class="btn-muted" href="<?php echo setup_h('setup-db.php?token=' . rawurlencode($providedToken)); ?>" style="display:inline-block; text-decoration:none; line-height: 1.6; padding: 10px 14px;">Reset</a>
        </div>
      </form>

      <?php if (count($resultLines) > 0): ?>
        <h2 style="margin: 14px 0 10px 0; font-size: 16px;">Result</h2>
        <pre><?php echo setup_h(implode("\n", $resultLines)); ?></pre>
      <?php endif; ?>

      <div class="footer">
        Next step: update <code>config/db.php</code> to point to this DB (and the app user, if created). Then import your schema/tables.
      </div>
    </div>
  </div>
</body>
</html>
