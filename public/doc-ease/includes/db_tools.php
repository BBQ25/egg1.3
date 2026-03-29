<?php

if (!function_exists('db_tools_h')) {
    function db_tools_h($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('db_tools_detect_web_server')) {
    /**
     * Best-effort detection of the front web server from PHP's perspective.
     *
     * @return array{name:string,label:string,server_software:string,sapi:string}
     */
    function db_tools_detect_web_server() {
        $software = (string) ($_SERVER['SERVER_SOFTWARE'] ?? '');
        $sw = strtolower($software);
        $sapi = (string) php_sapi_name();

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
        } elseif (strpos($sw, 'iis') !== false) {
            $name = 'iis';
            $label = 'IIS';
        } elseif (function_exists('apache_get_version')) {
            // SERVER_SOFTWARE can be blank behind some proxies; this is a weak hint only.
            $name = 'apache';
            $label = 'Apache';
        }

        return [
            'name' => $name,
            'label' => $label,
            'server_software' => $software,
            'sapi' => $sapi,
        ];
    }
}

if (!function_exists('db_tools_path_is_within')) {
    /**
     * True if $child resolves inside $parent after realpath normalization.
     */
    function db_tools_path_is_within($child, $parent) {
        $child = (string) $child;
        $parent = (string) $parent;
        if ($child === '' || $parent === '') return false;

        $childReal = realpath($child);
        $parentReal = realpath($parent);
        if ($childReal === false || $parentReal === false) return false;

        $childReal = str_replace('\\', '/', $childReal);
        $parentReal = str_replace('\\', '/', $parentReal);

        $childReal = rtrim($childReal, '/') . '/';
        $parentReal = rtrim($parentReal, '/') . '/';

        return strncmp($childReal, $parentReal, strlen($parentReal)) === 0;
    }
}

if (!function_exists('db_tools_backup_dir')) {
    function db_tools_backup_dir() {
        return __DIR__ . '/../config/database/backup';
    }
}

if (!function_exists('db_tools_ensure_dir')) {
    function db_tools_ensure_dir($path) {
        $path = (string) $path;
        if ($path === '') return false;
        if (is_dir($path)) return true;
        return @mkdir($path, 0755, true);
    }
}

if (!function_exists('db_tools_list_backups')) {
    /**
     * @return array<int, array{basename:string, path:string, size:int, mtime:int}>
     */
    function db_tools_list_backups($dir) {
        $dir = (string) $dir;
        $items = [];
        if (!is_dir($dir)) return $items;

        $files = @scandir($dir);
        if (!is_array($files)) return $items;

        foreach ($files as $name) {
            $name = (string) $name;
            if ($name === '.' || $name === '..') continue;
            if ($name === '.htaccess' || $name === '.gitignore' || $name === '.gitkeep') continue;
            if (!preg_match('/\\.sql$/i', $name)) continue;
            $path = $dir . '/' . $name;
            if (!is_file($path)) continue;
            $items[] = [
                'basename' => $name,
                'path' => $path,
                'size' => (int) @filesize($path),
                'mtime' => (int) @filemtime($path),
            ];
        }

        usort($items, static function ($a, $b) {
            return ($b['mtime'] ?? 0) <=> ($a['mtime'] ?? 0);
        });

        return $items;
    }
}

if (!function_exists('db_tools_read_db_php_config')) {
    /**
     * Best-effort parser for config/db.php assignments:
     * - $servername = "...";
     * - $username = "...";
     * - $password = "...";
     * - $dbname = "...";
     *
     * @return array{servername:string, username:string, password:string, dbname:string}
     */
    function db_tools_read_db_php_config($filePath) {
        $out = [
            'servername' => '',
            'username' => '',
            'password' => '',
            'dbname' => '',
        ];

        $filePath = (string) $filePath;
        if (!is_file($filePath)) return $out;

        $src = @file_get_contents($filePath);
        if (!is_string($src) || $src === '') return $out;

        foreach (array_keys($out) as $key) {
            $pattern = '/\\$' . preg_quote($key, '/') . '\\s*=\\s*([\\\"\\\'])(.*?)\\1\\s*;/s';
            if (preg_match($pattern, $src, $m)) {
                $out[$key] = (string) ($m[2] ?? '');
            }
        }

        return $out;
    }
}

if (!function_exists('db_tools_write_db_php_config')) {
    /**
     * Update config/db.php connection values.
     *
     * @param array{servername:string, username:string, password:string, dbname:string} $values
     * @return array{ok:bool, message:string}
     */
    function db_tools_write_db_php_config($filePath, array $values) {
        $filePath = (string) $filePath;
        if ($filePath === '') return ['ok' => false, 'message' => 'Missing config/db.php path.'];
        if (!is_file($filePath)) return ['ok' => false, 'message' => 'config/db.php not found.'];

        $src = @file_get_contents($filePath);
        if (!is_string($src) || $src === '') return ['ok' => false, 'message' => 'Unable to read config/db.php.'];

        $replacements = [
            'servername' => (string) ($values['servername'] ?? ''),
            'username' => (string) ($values['username'] ?? ''),
            'password' => (string) ($values['password'] ?? ''),
            'dbname' => (string) ($values['dbname'] ?? ''),
        ];

        foreach ($replacements as $key => $val) {
            // Use var_export for safe PHP string literals (avoids "$" interpolation).
            $literal = var_export((string) $val, true);
            $pattern = '/(\\$' . preg_quote($key, '/') . '\\s*=\\s*)([\\\"\\\'])(.*?)\\2\\s*;/s';
            if (preg_match($pattern, $src)) {
                $src = preg_replace($pattern, '$1' . $literal . ';', $src, 1);
            } else {
                // Append if not found.
                $src .= "\n\$$key = " . $literal . ";\n";
            }
        }

        $bakDir = db_tools_backup_dir();
        db_tools_ensure_dir($bakDir);
        $ts = date('Ymd_His');
        $bakPath = $bakDir . '/config_db_' . $ts . '.php.bak';
        @copy($filePath, $bakPath);

        $tmpPath = $filePath . '.tmp.' . bin2hex(random_bytes(6));
        $wrote = @file_put_contents($tmpPath, $src);
        if ($wrote === false) {
            @unlink($tmpPath);
            return ['ok' => false, 'message' => 'Unable to write temp config file.'];
        }

        if (!@rename($tmpPath, $filePath)) {
            @unlink($tmpPath);
            return ['ok' => false, 'message' => 'Unable to replace config/db.php. Check file permissions.'];
        }

        return ['ok' => true, 'message' => 'Updated config/db.php (backup saved to ' . basename($bakPath) . ').'];
    }
}

if (!function_exists('db_tools_find_binary')) {
    /**
     * Return an absolute binary path when we can guess it, otherwise return the basename (PATH lookup).
     */
    function db_tools_find_binary($basename) {
        $basename = trim((string) $basename);
        if ($basename === '') return '';

        $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        if ($isWin) {
            $exe = $basename . '.exe';
            $candidates = [];
            $laragon = glob('C:/laragon/bin/mysql/*/bin/' . $exe);
            if (is_array($laragon)) $candidates = array_merge($candidates, $laragon);

            $best = '';
            $bestMtime = 0;
            foreach ($candidates as $p) {
                if (!is_string($p) || $p === '') continue;
                if (!is_file($p)) continue;
                $mt = (int) @filemtime($p);
                if ($mt >= $bestMtime) {
                    $best = $p;
                    $bestMtime = $mt;
                }
            }

            return $best !== '' ? $best : $basename;
        }

        $paths = [
            '/usr/bin/' . $basename,
            '/usr/local/bin/' . $basename,
            '/bin/' . $basename,
        ];
        foreach ($paths as $p) {
            if (is_file($p)) return $p;
        }
        return $basename;
    }
}

if (!function_exists('db_tools_proc_run')) {
    /**
     * @param array<int, string> $cmd
     * @param array<int, mixed> $descriptors
     * @return array{ok:bool, code:int, stdout:string, stderr:string}
     */
    function db_tools_proc_run(array $cmd, array $descriptors, $cwd = null) {
        $stdout = '';
        $stderr = '';
        $exitCode = 1;

        if (!function_exists('proc_open')) {
            return ['ok' => false, 'code' => 127, 'stdout' => '', 'stderr' => 'proc_open is disabled in PHP.'];
        }

        $options = [];
        if (PHP_VERSION_ID >= 70400) {
            $options['bypass_shell'] = true;
        }

        $proc = @proc_open($cmd, $descriptors, $pipes, $cwd, null, $options);
        if (!is_resource($proc)) {
            return ['ok' => false, 'code' => 127, 'stdout' => '', 'stderr' => 'Unable to start process.'];
        }

        if (isset($pipes[0]) && is_resource($pipes[0])) fclose($pipes[0]);

        if (isset($pipes[1]) && is_resource($pipes[1])) {
            $stdout = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
        }

        if (isset($pipes[2]) && is_resource($pipes[2])) {
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[2]);
        }

        $exitCode = (int) proc_close($proc);

        return [
            'ok' => $exitCode === 0,
            'code' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }
}

if (!function_exists('db_tools_make_mysql_defaults_file')) {
    /**
     * Create a temporary MySQL defaults file for --defaults-extra-file.
     */
    function db_tools_make_mysql_defaults_file($host, $port, $user, $pass) {
        $host = trim((string) $host);
        $port = (int) $port;
        $user = (string) $user;
        $pass = (string) $pass;
        if ($port <= 0) $port = 3306;

        $dir = sys_get_temp_dir();
        $path = $dir . DIRECTORY_SEPARATOR . 'doc_ease_mysql_' . bin2hex(random_bytes(8)) . '.cnf';

        $content = "[client]\n";
        $content .= "host=" . $host . "\n";
        $content .= "port=" . $port . "\n";
        $content .= "user=" . $user . "\n";
        $content .= "password=" . $pass . "\n";
        $content .= "protocol=tcp\n";

        @file_put_contents($path, $content);

        // Best-effort permissions (Linux).
        @chmod($path, 0600);

        return $path;
    }
}

if (!function_exists('db_tools_mysqldump_backup')) {
    /**
     * @return array{ok:bool, message:string, stderr:string, out_file:string}
     */
    function db_tools_mysqldump_backup($mysqlHost, $mysqlPort, $user, $pass, $dbName, $outFile) {
        $mysqlHost = trim((string) $mysqlHost);
        $mysqlPort = (int) $mysqlPort;
        $user = (string) $user;
        $pass = (string) $pass;
        $dbName = trim((string) $dbName);
        $outFile = (string) $outFile;

        if ($mysqlHost === '' || $user === '' || $dbName === '' || $outFile === '') {
            return ['ok' => false, 'message' => 'Missing required values for backup.', 'stderr' => '', 'out_file' => $outFile];
        }
        if ($mysqlPort <= 0) $mysqlPort = 3306;

        $defaults = db_tools_make_mysql_defaults_file($mysqlHost, $mysqlPort, $user, $pass);
        $mysqldump = db_tools_find_binary('mysqldump');

        $cmd = [
            $mysqldump,
            '--defaults-extra-file=' . $defaults,
            '--single-transaction',
            '--quick',
            '--routines',
            '--triggers',
            '--events',
            $dbName,
        ];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $outFile, 'w'],
            2 => ['pipe', 'w'],
        ];

        $res = db_tools_proc_run($cmd, $descriptors);
        @unlink($defaults);

        if (!$res['ok']) {
            return [
                'ok' => false,
                'message' => 'mysqldump failed (exit ' . (int) $res['code'] . ').',
                'stderr' => (string) $res['stderr'],
                'out_file' => $outFile,
            ];
        }

        if (!is_file($outFile) || (int) @filesize($outFile) <= 0) {
            return [
                'ok' => false,
                'message' => 'Backup file was not created or is empty.',
                'stderr' => (string) $res['stderr'],
                'out_file' => $outFile,
            ];
        }

        return [
            'ok' => true,
            'message' => 'Backup created successfully.',
            'stderr' => (string) $res['stderr'],
            'out_file' => $outFile,
        ];
    }
}

if (!function_exists('db_tools_mysql_import')) {
    /**
     * Import a .sql file into a target database.
     *
     * @return array{ok:bool, message:string, stdout:string, stderr:string}
     */
    function db_tools_mysql_import($mysqlHost, $mysqlPort, $user, $pass, $dbName, $sqlFile) {
        $mysqlHost = trim((string) $mysqlHost);
        $mysqlPort = (int) $mysqlPort;
        $user = (string) $user;
        $pass = (string) $pass;
        $dbName = trim((string) $dbName);
        $sqlFile = (string) $sqlFile;

        if ($mysqlHost === '' || $user === '' || $dbName === '' || $sqlFile === '') {
            return ['ok' => false, 'message' => 'Missing required values for import.', 'stdout' => '', 'stderr' => ''];
        }
        if (!is_file($sqlFile)) {
            return ['ok' => false, 'message' => 'SQL file not found.', 'stdout' => '', 'stderr' => ''];
        }
        if ($mysqlPort <= 0) $mysqlPort = 3306;

        $defaults = db_tools_make_mysql_defaults_file($mysqlHost, $mysqlPort, $user, $pass);
        $mysql = db_tools_find_binary('mysql');

        $cmd = [
            $mysql,
            '--defaults-extra-file=' . $defaults,
            $dbName,
        ];

        $descriptors = [
            0 => ['file', $sqlFile, 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $res = db_tools_proc_run($cmd, $descriptors);
        @unlink($defaults);

        if (!$res['ok']) {
            return [
                'ok' => false,
                'message' => 'Import failed (exit ' . (int) $res['code'] . ').',
                'stdout' => (string) $res['stdout'],
                'stderr' => (string) $res['stderr'],
            ];
        }

        return [
            'ok' => true,
            'message' => 'Import completed.',
            'stdout' => (string) $res['stdout'],
            'stderr' => (string) $res['stderr'],
        ];
    }
}

if (!function_exists('db_tools_mysql_connect')) {
    /**
     * @return array{ok:bool, conn:?mysqli, message:string}
     */
    function db_tools_mysql_connect($host, $port, $user, $pass, $dbName = '') {
        $host = trim((string) $host);
        $port = (int) $port;
        $user = (string) $user;
        $pass = (string) $pass;
        $dbName = (string) $dbName;
        if ($port <= 0) $port = 3306;

        mysqli_report(MYSQLI_REPORT_OFF);
        $conn = @new mysqli($host, $user, $pass, $dbName, $port);
        if ($conn->connect_errno) {
            return ['ok' => false, 'conn' => null, 'message' => 'MySQL connect failed: ' . $conn->connect_error];
        }
        $conn->set_charset('utf8mb4');
        return ['ok' => true, 'conn' => $conn, 'message' => 'OK'];
    }
}

if (!function_exists('db_tools_mysql_create_database')) {
    /**
     * @return array{ok:bool, message:string}
     */
    function db_tools_mysql_create_database(mysqli $conn, $dbName) {
        $dbName = trim((string) $dbName);
        if ($dbName === '' || !preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
            return ['ok' => false, 'message' => 'Invalid database name. Use only A-Z a-z 0-9 _'];
        }

        $dbIdent = '`' . str_replace('`', '``', $dbName) . '`';
        $sql = "CREATE DATABASE IF NOT EXISTS {$dbIdent} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
        if (!$conn->query($sql)) {
            return ['ok' => false, 'message' => 'CREATE DATABASE failed: ' . $conn->error];
        }
        return ['ok' => true, 'message' => 'Database ensured: ' . $dbName];
    }
}

if (!function_exists('db_tools_mysql_create_user_and_grant')) {
    /**
     * @return array{ok:bool, message:string}
     */
    function db_tools_mysql_create_user_and_grant(mysqli $conn, $dbName, $appUser, $appHost, $appPass) {
        $dbName = trim((string) $dbName);
        $appUser = trim((string) $appUser);
        $appHost = trim((string) $appHost);
        $appPass = (string) $appPass;

        if ($dbName === '' || !preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
            return ['ok' => false, 'message' => 'Invalid database name.'];
        }
        if ($appUser === '' || !preg_match('/^[A-Za-z0-9_]+$/', $appUser)) {
            return ['ok' => false, 'message' => 'Invalid app username. Use only A-Z a-z 0-9 _'];
        }
        if ($appHost === '' || !preg_match('/^[A-Za-z0-9._%\\-]+$/', $appHost)) {
            return ['ok' => false, 'message' => 'Invalid app host.'];
        }

        $userEsc = $conn->real_escape_string($appUser);
        $hostEsc = $conn->real_escape_string($appHost);
        $passEsc = $conn->real_escape_string($appPass);
        $dbIdent = '`' . str_replace('`', '``', $dbName) . '`';

        $ok = true;

        $createUserSql = "CREATE USER IF NOT EXISTS '{$userEsc}'@'{$hostEsc}' IDENTIFIED BY '{$passEsc}'";
        if (!$conn->query($createUserSql)) {
            // Fallback for servers without IF NOT EXISTS.
            $fallbackSql = "CREATE USER '{$userEsc}'@'{$hostEsc}' IDENTIFIED BY '{$passEsc}'";
            if (!$conn->query($fallbackSql)) {
                // 1396 = user already exists (MySQL)
                if ((int) $conn->errno !== 1396) {
                    $ok = false;
                    return ['ok' => false, 'message' => 'CREATE USER failed: ' . $conn->error];
                }
            }
        }

        $grantSql = "GRANT ALL PRIVILEGES ON {$dbIdent}.* TO '{$userEsc}'@'{$hostEsc}'";
        if (!$conn->query($grantSql)) {
            $ok = false;
            return ['ok' => false, 'message' => 'GRANT failed: ' . $conn->error];
        }

        $conn->query("FLUSH PRIVILEGES");

        return ['ok' => $ok, 'message' => 'App user ensured and granted privileges: ' . $appUser . '@' . $appHost];
    }
}

if (!function_exists('db_tools_table_exists')) {
    function db_tools_table_exists(mysqli $conn, $tableName) {
        $tableName = trim((string) $tableName);
        if ($tableName === '') return false;
        $stmt = $conn->prepare(
            "SELECT 1
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
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

if (!function_exists('db_tools_column_exists')) {
    function db_tools_column_exists(mysqli $conn, $tableName, $columnName) {
        $tableName = trim((string) $tableName);
        $columnName = trim((string) $columnName);
        if ($tableName === '' || $columnName === '') return false;
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

if (!function_exists('db_tools_seed_first_admin')) {
    /**
     * Create an admin account only if none exists.
     *
     * @return array{ok:bool, message:string}
     */
    function db_tools_seed_first_admin(mysqli $conn, $email, $username, $password) {
        $email = trim((string) $email);
        $username = trim((string) $username);
        $password = (string) $password;

        if (!db_tools_table_exists($conn, 'users')) {
            return ['ok' => false, 'message' => 'users table not found in the target database. Import schema first.'];
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'Valid admin email is required.'];
        }
        if ($username === '') {
            return ['ok' => false, 'message' => 'Admin username is required.'];
        }
        if (strlen($password) < 8) {
            return ['ok' => false, 'message' => 'Admin password must be at least 8 characters.'];
        }

        // Ensure columns used by auth flows exist (best-effort).
        if (!db_tools_column_exists($conn, 'users', 'role')) {
            $conn->query("ALTER TABLE users ADD COLUMN role VARCHAR(40) NOT NULL DEFAULT 'student'");
        }
        if (!db_tools_column_exists($conn, 'users', 'is_active')) {
            $conn->query("ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 0");
        }
        if (!db_tools_column_exists($conn, 'users', 'must_change_password')) {
            $conn->query("ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0");
        }
        if (!db_tools_column_exists($conn, 'users', 'password_changed_at')) {
            $conn->query("ALTER TABLE users ADD COLUMN password_changed_at DATETIME NULL DEFAULT NULL");
        }
        if (!db_tools_column_exists($conn, 'users', 'campus_id')) {
            $conn->query("ALTER TABLE users ADD COLUMN campus_id BIGINT UNSIGNED NULL DEFAULT NULL");
        }
        if (!db_tools_column_exists($conn, 'users', 'is_superadmin')) {
            $conn->query("ALTER TABLE users ADD COLUMN is_superadmin TINYINT(1) NOT NULL DEFAULT 0");
        }

        $cnt = 0;
        $res = $conn->query("SELECT COUNT(*) AS c FROM users WHERE role = 'admin' LIMIT 1");
        if ($res && $res->num_rows === 1) {
            $cnt = (int) (($res->fetch_assoc()['c'] ?? 0));
        }
        if ($res instanceof mysqli_result) $res->free();

        if ($cnt > 0) {
            return ['ok' => true, 'message' => 'Admin account already exists; no changes made.'];
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        if ($hash === false) {
            return ['ok' => false, 'message' => 'Unable to hash admin password.'];
        }

        $stmt = $conn->prepare(
            "INSERT INTO users (useremail, username, password, role, is_active, is_superadmin, must_change_password)
             VALUES (?, ?, ?, 'admin', 1, 1, 0)"
        );
        if (!$stmt) {
            return ['ok' => false, 'message' => 'Unable to prepare admin insert: ' . $conn->error];
        }
        $stmt->bind_param('sss', $email, $username, $hash);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            return ['ok' => false, 'message' => 'Unable to create admin: ' . $err];
        }
        $stmt->close();

        return ['ok' => true, 'message' => 'Created initial admin account: ' . $email];
    }
}
