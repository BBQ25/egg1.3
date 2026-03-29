<?php include '../layouts/session.php'; ?>
<?php require_role('superadmin'); ?>
<?php include '../layouts/main.php'; ?>
<?php require_once __DIR__ . '/../includes/db_tools.php'; ?>

<?php
set_time_limit(0);

$backupDir = db_tools_backup_dir();
db_tools_ensure_dir($backupDir);

$configPath = __DIR__ . '/../config/db.php';
$cfg = db_tools_read_db_php_config($configPath);

$serverInfo = db_tools_detect_web_server();
$serverName = (string) ($serverInfo['name'] ?? 'unknown');
$serverLabel = (string) ($serverInfo['label'] ?? 'Unknown');
$serverSoftware = (string) ($serverInfo['server_software'] ?? '');
$documentRoot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
$backupInDocRoot = ($documentRoot !== '' && db_tools_path_is_within($backupDir, $documentRoot));
$backupHasHtaccess = is_file($backupDir . '/.htaccess');

$messages = [];
$resultLog = [];

$lastBackup = (isset($_SESSION['db_tools_last_backup']) && is_array($_SESSION['db_tools_last_backup']))
    ? $_SESSION['db_tools_last_backup']
    : null;

if (!function_exists('db_tools_add_message')) {
    function db_tools_add_message(array &$messages, $type, $text) {
        $messages[] = [
            'type' => (string) $type,
            'text' => (string) $text,
        ];
    }
}

if (!function_exists('db_tools_sanitize_backup_basename')) {
    function db_tools_sanitize_backup_basename($basename) {
        $basename = (string) $basename;
        $basename = str_replace(['..', '/', '\\'], '', $basename);
        return trim($basename);
    }
}

if (!function_exists('db_tools_backup_path')) {
    function db_tools_backup_path($backupDir, $basename) {
        $backupDir = rtrim((string) $backupDir, '/\\');
        $basename = db_tools_sanitize_backup_basename($basename);
        return $backupDir . '/' . $basename;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    if (!csrf_validate($csrf)) {
        db_tools_add_message($messages, 'danger', 'Security check failed (CSRF). Please refresh and try again.');
    } else {
        $action = trim((string) ($_POST['action'] ?? ''));

        if ($action === 'backup_now') {
            $dbHost = trim((string) ($_POST['current_db_host'] ?? ($cfg['servername'] ?? 'localhost')));
            $dbPort = (int) ($_POST['current_db_port'] ?? 3306);
            $dbUser = trim((string) ($_POST['current_db_user'] ?? ($cfg['username'] ?? '')));
            $dbPass = (string) ($_POST['current_db_pass'] ?? '');
            if ($dbPass === '') $dbPass = (string) ($cfg['password'] ?? '');
            $dbName = trim((string) ($_POST['current_db_name'] ?? ($cfg['dbname'] ?? '')));

            if ($dbName === '' || $dbUser === '' || $dbHost === '') {
                db_tools_add_message($messages, 'danger', 'Backup requires DB host, username, and database name.');
            } else {
                $ts = date('Ymd_His');
                $safeDb = preg_replace('/[^A-Za-z0-9_\\-]+/', '_', $dbName);
                if (!is_string($safeDb) || $safeDb === '') $safeDb = 'db';
                $outFile = $backupDir . '/backup_' . $safeDb . '_' . $ts . '.sql';

                $res = db_tools_mysqldump_backup($dbHost, $dbPort, $dbUser, $dbPass, $dbName, $outFile);
                if (!empty($res['ok'])) {
                    $_SESSION['db_tools_last_backup'] = [
                        'basename' => basename($outFile),
                        'path' => $outFile,
                        'mtime' => time(),
                    ];
                    $lastBackup = $_SESSION['db_tools_last_backup'];

                    db_tools_add_message($messages, 'success', 'Backup created: ' . basename($outFile));
                    if (!empty($res['stderr'])) {
                        $resultLog[] = 'mysqldump warnings:';
                        $resultLog[] = (string) $res['stderr'];
                    }
                } else {
                    db_tools_add_message($messages, 'danger', (string) ($res['message'] ?? 'Backup failed.'));
                    if (!empty($res['stderr'])) $resultLog[] = (string) $res['stderr'];
                }
            }
        } elseif ($action === 'apply_new_db') {
            $lbBasename = is_array($lastBackup) ? (string) ($lastBackup['basename'] ?? '') : '';
            $lbPath = $lbBasename !== '' ? db_tools_backup_path($backupDir, $lbBasename) : '';
            if ($lbPath === '' || !is_file($lbPath)) {
                db_tools_add_message($messages, 'danger', 'Backup required: click "Backup Now" first.');
            } else {
                $adminHost = trim((string) ($_POST['admin_host'] ?? 'localhost'));
                $adminPort = (int) ($_POST['admin_port'] ?? 3306);
                $adminUser = trim((string) ($_POST['admin_user'] ?? 'root'));
                $adminPass = (string) ($_POST['admin_pass'] ?? '');

                $newDbName = trim((string) ($_POST['new_db_name'] ?? ''));
                $createAppUser = !empty($_POST['create_app_user']);
                $appUser = trim((string) ($_POST['app_user'] ?? ''));
                $appPass = (string) ($_POST['app_pass'] ?? '');
                $appHost = trim((string) ($_POST['app_host'] ?? 'localhost'));

                $importSource = trim((string) ($_POST['import_source'] ?? 'last_backup'));
                $selectedBackup = db_tools_sanitize_backup_basename((string) ($_POST['import_backup_file'] ?? ''));

                $seedAdmin = !empty($_POST['seed_admin']);
                $seedEmail = trim((string) ($_POST['seed_admin_email'] ?? ''));
                $seedUsername = trim((string) ($_POST['seed_admin_username'] ?? ''));
                $seedPassword = (string) ($_POST['seed_admin_password'] ?? '');

                $updateConfig = !empty($_POST['update_config']);

                if ($adminHost === '' || $adminUser === '') {
                    db_tools_add_message($messages, 'danger', 'Admin host and admin username are required.');
                } elseif ($newDbName === '' || !preg_match('/^[A-Za-z0-9_]+$/', $newDbName)) {
                    db_tools_add_message($messages, 'danger', 'New database name is required and must match: A-Z a-z 0-9 _.');
                } elseif ($updateConfig && ($appUser === '' || $appPass === '')) {
                    db_tools_add_message($messages, 'danger', 'To update config/db.php, set the App DB username and password.');
                } else {
                    $resultLog[] = 'Connecting to MySQL admin...';
                    $adminConnRes = db_tools_mysql_connect($adminHost, $adminPort, $adminUser, $adminPass, '');
                    if (empty($adminConnRes['ok']) || !($adminConnRes['conn'] instanceof mysqli)) {
                        db_tools_add_message($messages, 'danger', (string) ($adminConnRes['message'] ?? 'MySQL admin connection failed.'));
                    } else {
                        $adminConn = $adminConnRes['conn'];

                        $created = db_tools_mysql_create_database($adminConn, $newDbName);
                        $resultLog[] = (string) ($created['message'] ?? '');
                        if (empty($created['ok'])) {
                            db_tools_add_message($messages, 'danger', (string) ($created['message'] ?? 'Unable to create database.'));
                        } else {
                            $canContinue = true;
                            if ($createAppUser) {
                                $resultLog[] = 'Ensuring app DB user and grants...';
                                $uRes = db_tools_mysql_create_user_and_grant($adminConn, $newDbName, $appUser, $appHost, $appPass);
                                $resultLog[] = (string) ($uRes['message'] ?? '');
                                if (empty($uRes['ok'])) {
                                    db_tools_add_message($messages, 'danger', (string) ($uRes['message'] ?? 'Unable to create/grant app user.'));
                                    $canContinue = false;
                                }
                            }

                            $importOk = false;
                            $sqlFile = '';
                            if (!$canContinue) {
                                db_tools_add_message($messages, 'danger', 'Aborting apply: app DB user creation failed.');
                            } else {
                                if ($importSource === 'upload') {
                                    $f = (isset($_FILES['sql_upload']) && is_array($_FILES['sql_upload'])) ? $_FILES['sql_upload'] : null;
                                    $err = is_array($f) ? (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
                                    if (!is_array($f) || $err !== UPLOAD_ERR_OK) {
                                        db_tools_add_message($messages, 'danger', 'Upload failed. Provide a valid .sql file.');
                                    } else {
                                        $tmp = (string) ($f['tmp_name'] ?? '');
                                        $name = (string) ($f['name'] ?? '');
                                        if ($tmp === '' || !is_file($tmp)) {
                                            db_tools_add_message($messages, 'danger', 'Uploaded file is missing.');
                                        } elseif (!preg_match('/\\.sql$/i', $name)) {
                                            db_tools_add_message($messages, 'danger', 'Uploaded file must be a .sql file.');
                                        } else {
                                            $sqlFile = $tmp;
                                            $resultLog[] = 'Import source: uploaded file (' . $name . ')';
                                        }
                                    }
                                } elseif ($importSource === 'backup_file') {
                                    if ($selectedBackup === '') {
                                        db_tools_add_message($messages, 'danger', 'Select a backup file to import.');
                                    } else {
                                        $candidate = db_tools_backup_path($backupDir, $selectedBackup);
                                        if (!is_file($candidate)) {
                                            db_tools_add_message($messages, 'danger', 'Selected backup file does not exist.');
                                        } else {
                                            $sqlFile = $candidate;
                                            $resultLog[] = 'Import source: backup file (' . basename($candidate) . ')';
                                        }
                                    }
                                } else {
                                    $sqlFile = $lbPath;
                                    $resultLog[] = 'Import source: last backup (' . basename($sqlFile) . ')';
                                }

                                if ($sqlFile !== '') {
                                    $resultLog[] = 'Importing SQL into ' . $newDbName . '...';
                                    $imp = db_tools_mysql_import($adminHost, $adminPort, $adminUser, $adminPass, $newDbName, $sqlFile);
                                    $resultLog[] = (string) ($imp['message'] ?? '');
                                    if (empty($imp['ok'])) {
                                        db_tools_add_message($messages, 'danger', (string) ($imp['message'] ?? 'Import failed.'));
                                        if (!empty($imp['stderr'])) $resultLog[] = (string) $imp['stderr'];
                                    } else {
                                        $importOk = true;
                                        if (!empty($imp['stderr'])) {
                                            $resultLog[] = 'mysql warnings:';
                                            $resultLog[] = (string) $imp['stderr'];
                                        }
                                    }
                                } else {
                                    db_tools_add_message($messages, 'danger', 'No import source selected.');
                                }
                            }

                            if ($importOk && $seedAdmin) {
                                $resultLog[] = 'Seeding initial admin (only if none exists)...';
                                $seedConnRes = db_tools_mysql_connect($adminHost, $adminPort, $adminUser, $adminPass, $newDbName);
                                if (empty($seedConnRes['ok']) || !($seedConnRes['conn'] instanceof mysqli)) {
                                    db_tools_add_message($messages, 'danger', 'Unable to connect to new DB for seeding: ' . (string) ($seedConnRes['message'] ?? ''));
                                } else {
                                    $seedConn = $seedConnRes['conn'];
                                    $seedRes = db_tools_seed_first_admin($seedConn, $seedEmail, $seedUsername, $seedPassword);
                                    $resultLog[] = (string) ($seedRes['message'] ?? '');
                                    if (empty($seedRes['ok'])) {
                                        db_tools_add_message($messages, 'danger', (string) ($seedRes['message'] ?? 'Unable to seed admin.'));
                                    }
                                    $seedConn->close();
                                }
                            }

                            if ($importOk && $updateConfig) {
                                $resultLog[] = 'Validating app DB credentials...';
                                $testAppConn = db_tools_mysql_connect($adminHost, $adminPort, $appUser, $appPass, $newDbName);
                                if (empty($testAppConn['ok']) || !($testAppConn['conn'] instanceof mysqli)) {
                                    db_tools_add_message($messages, 'danger', 'App DB login test failed. config/db.php was NOT updated. Error: ' . (string) ($testAppConn['message'] ?? ''));
                                } else {
                                    $testAppConn['conn']->close();
                                $resultLog[] = 'Updating config/db.php...';
                                $w = db_tools_write_db_php_config($configPath, [
                                    'servername' => $adminHost,
                                    'username' => $appUser,
                                    'password' => $appPass,
                                    'dbname' => $newDbName,
                                ]);
                                $resultLog[] = (string) ($w['message'] ?? '');
                                if (empty($w['ok'])) {
                                    db_tools_add_message($messages, 'danger', (string) ($w['message'] ?? 'Unable to update config/db.php.'));
                                } else {
                                    db_tools_add_message($messages, 'success', 'New database applied. You should log out and log back in.');
                                }
                                }
                            } elseif ($importOk) {
                                db_tools_add_message($messages, 'success', 'New database created/imported. (config/db.php was not changed)');
                            }
                        }

                        $adminConn->close();
                    }
                }
            }
        }
    }
}

$backups = db_tools_list_backups($backupDir);
$lastBackupLabel = is_array($lastBackup) ? (string) ($lastBackup['basename'] ?? '') : '';
$lastBackupExists = ($lastBackupLabel !== '' && is_file(db_tools_backup_path($backupDir, $lastBackupLabel)));

?>

<head>
    <title>DB Tools | E-Record</title>
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
                                        <li class="breadcrumb-item active">DB Tools</li>
                                    </ol>
                                </div>
                                <h4 class="page-title">DB Tools (Superadmin)</h4>
                            </div>
                        </div>
                    </div>

                    <?php foreach ($messages as $m): ?>
                        <?php
                            $t = (string) ($m['type'] ?? 'info');
                            $cls = 'alert-info';
                            if ($t === 'success') $cls = 'alert-success';
                            if ($t === 'danger') $cls = 'alert-danger';
                            if ($t === 'warning') $cls = 'alert-warning';
                        ?>
                        <div class="row">
                            <div class="col-12">
                                <div class="alert <?php echo $cls; ?>" role="alert">
                                    <?php echo db_tools_h((string) ($m['text'] ?? '')); ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title mb-2">1) Backup Current Database (Required)</h4>
                                    <p class="text-muted mb-3">
                                        This creates a <code>.sql</code> dump into <code>config/database/backup</code>.
                                        This backup is required before applying a new database.
                                    </p>
                                    <div class="small text-muted mb-3">
                                        Server detected: <code><?php echo db_tools_h($serverLabel); ?></code>
                                        <?php if ($serverSoftware !== ''): ?>
                                            <span class="ms-1">(<?php echo db_tools_h($serverSoftware); ?>)</span>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($documentRoot === ''): ?>
                                        <div class="alert alert-warning" role="alert">
                                            Could not read <code>DOCUMENT_ROOT</code>, so this page cannot verify whether <code>config/database/backup</code> is publicly accessible.
                                            If your site is on Nginx, remember that <code>.htaccess</code> is ignored and you must block this path in your server config.
                                        </div>
                                    <?php elseif ($backupInDocRoot): ?>
                                        <div class="alert <?php echo $serverName === 'nginx' ? 'alert-danger' : 'alert-warning'; ?>" role="alert">
                                            <div class="fw-semibold mb-1">Backup folder exposure check</div>
                                            <div>
                                                Your backup folder (<code>config/database/backup</code>) appears to be inside your web document root.
                                                Make sure it is not downloadable from the internet.
                                            </div>
                                            <?php if ($serverName === 'nginx'): ?>
                                                <div class="mt-2">
                                                    Nginx does not use <code>.htaccess</code>, so the deny rule in <code>config/database/backup/.htaccess</code> will not protect these backups.
                                                    Add a deny rule in your Nginx site config (example):
                                                </div>
                                                <pre class="bg-light p-3 rounded border small mb-0" style="white-space: pre-wrap;"><code>location ^~ /config/database/backup/ { deny all; }</code></pre>
                                            <?php elseif (($serverName === 'apache' || $serverName === 'litespeed') && $backupHasHtaccess): ?>
                                                <div class="mt-2">
                                                    Apache/LiteSpeed should enforce <code>config/database/backup/.htaccess</code>. If the folder is still accessible, enable <code>AllowOverride</code> for this site or block the path in the main vhost config.
                                                </div>
                                            <?php else: ?>
                                                <div class="mt-2">
                                                    If this server is Nginx (or Nginx is in front of Apache), you must block <code>/config/database/backup/</code> in Nginx because <code>.htaccess</code> may be ignored.
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>

                                    <form method="post" class="row g-3">
                                        <input type="hidden" name="csrf_token" value="<?php echo db_tools_h(csrf_token()); ?>">
                                        <input type="hidden" name="action" value="backup_now">

                                        <div class="col-md-4">
                                            <label class="form-label">DB Host</label>
                                            <input class="form-control" name="current_db_host" value="<?php echo db_tools_h($cfg['servername'] ?: 'localhost'); ?>">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Port</label>
                                            <input class="form-control" name="current_db_port" value="3306">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">DB Username</label>
                                            <input class="form-control" name="current_db_user" value="<?php echo db_tools_h($cfg['username']); ?>">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">DB Password</label>
                                            <input class="form-control" type="password" name="current_db_pass" placeholder="(leave blank to use config/db.php password)">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Database Name</label>
                                            <input class="form-control" name="current_db_name" value="<?php echo db_tools_h($cfg['dbname']); ?>">
                                        </div>
                                        <div class="col-12">
                                            <button type="submit" class="btn btn-primary">
                                                <i class="ri-download-2-line me-1" aria-hidden="true"></i>
                                                Backup Now
                                            </button>
                                            <?php if ($lastBackupExists): ?>
                                                <span class="text-muted ms-2">Last backup: <code><?php echo db_tools_h($lastBackupLabel); ?></code></span>
                                            <?php endif; ?>
                                        </div>
                                    </form>

                                    <?php if (count($backups) > 0): ?>
                                        <div class="mt-4">
                                            <div class="fw-semibold mb-2">Existing Backups</div>
                                            <div class="table-responsive">
                                                <table class="table table-sm table-striped mb-0">
                                                    <thead>
                                                        <tr>
                                                            <th>File</th>
                                                            <th>Size</th>
                                                            <th>Modified</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($backups as $b): ?>
                                                            <tr>
                                                                <td><code><?php echo db_tools_h($b['basename']); ?></code></td>
                                                                <td><?php echo number_format((int) ($b['size'] ?? 0)); ?> bytes</td>
                                                                <td><?php echo db_tools_h(date('Y-m-d H:i:s', (int) ($b['mtime'] ?? 0))); ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title mb-2">2) Apply New Database</h4>
                                    <p class="text-muted mb-3">
                                        Creates a new database, imports a SQL dump, optionally seeds the first admin, and can update <code>config/db.php</code>.
                                    </p>

                                    <?php if (!$lastBackupExists): ?>
                                        <div class="alert alert-warning" role="alert">
                                            Backup is required before applying a new database. Create a backup above first.
                                        </div>
                                    <?php endif; ?>

                                    <form method="post" enctype="multipart/form-data" class="row g-3">
                                        <input type="hidden" name="csrf_token" value="<?php echo db_tools_h(csrf_token()); ?>">
                                        <input type="hidden" name="action" value="apply_new_db">

                                        <div class="col-md-3">
                                            <label class="form-label">MySQL Admin Host</label>
                                            <input class="form-control" name="admin_host" value="localhost">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Port</label>
                                            <input class="form-control" name="admin_port" value="3306">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Admin Username</label>
                                            <input class="form-control" name="admin_user" value="root">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Admin Password</label>
                                            <input class="form-control" type="password" name="admin_pass" placeholder="MySQL root/admin password">
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label">New Database Name</label>
                                            <input class="form-control" name="new_db_name" placeholder="doc_ease_new">
                                            <div class="form-text">Allowed: A-Z a-z 0-9 _</div>
                                        </div>

                                        <div class="col-12">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" value="1" id="create_app_user" name="create_app_user" checked>
                                                <label class="form-check-label" for="create_app_user">Create app DB user and grant privileges</label>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">App DB Username</label>
                                            <input class="form-control" name="app_user" value="doc_ease_app">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">App DB Password</label>
                                            <input class="form-control" type="password" name="app_pass" placeholder="Strong password">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">App DB Host</label>
                                            <input class="form-control" name="app_host" value="localhost">
                                        </div>

                                        <div class="col-12">
                                            <label class="form-label d-block">Import Source</label>
                                            <div class="d-flex flex-wrap gap-3">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="radio" name="import_source" id="import_last_backup" value="last_backup" checked>
                                                    <label class="form-check-label" for="import_last_backup">Use last backup</label>
                                                </div>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="radio" name="import_source" id="import_backup_file" value="backup_file">
                                                    <label class="form-check-label" for="import_backup_file">Select from backups</label>
                                                </div>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="radio" name="import_source" id="import_upload" value="upload">
                                                    <label class="form-check-label" for="import_upload">Upload .sql</label>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Backup File</label>
                                            <select class="form-select" name="import_backup_file">
                                                <option value="">Select backup...</option>
                                                <?php foreach ($backups as $b): ?>
                                                    <option value="<?php echo db_tools_h($b['basename']); ?>"><?php echo db_tools_h($b['basename']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Upload SQL</label>
                                            <input class="form-control" type="file" name="sql_upload" accept=".sql">
                                        </div>

                                        <div class="col-12">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" value="1" id="seed_admin" name="seed_admin" checked>
                                                <label class="form-check-label" for="seed_admin">Seed first admin account (only if none exists)</label>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Admin Email</label>
                                            <input class="form-control" name="seed_admin_email" placeholder="admin@example.com">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Admin Username</label>
                                            <input class="form-control" name="seed_admin_username" placeholder="Super Admin">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Admin Password</label>
                                            <input class="form-control" type="password" name="seed_admin_password" placeholder="Min 8 chars">
                                        </div>

                                        <div class="col-12">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" value="1" id="update_config" name="update_config" checked>
                                                <label class="form-check-label" for="update_config">Update <code>config/db.php</code> to point to the new DB (this is the "apply")</label>
                                            </div>
                                        </div>

                                        <div class="col-12">
                                            <button type="submit" class="btn btn-danger" <?php echo $lastBackupExists ? '' : 'disabled'; ?>>
                                                <i class="ri-database-2-line me-1" aria-hidden="true"></i>
                                                Apply New Database
                                            </button>
                                        </div>
                                    </form>

                                    <?php if (count($resultLog) > 0): ?>
                                        <div class="mt-4">
                                            <div class="fw-semibold mb-2">Result Log</div>
                                            <pre class="bg-light p-3 rounded border small mb-0" style="white-space: pre-wrap;"><?php echo db_tools_h(implode("\n", $resultLog)); ?></pre>
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
