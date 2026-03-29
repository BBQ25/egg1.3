<?php
// Learning materials helpers.

require_once __DIR__ . '/env_secrets.php';

if (!function_exists('learning_materials_db_has_column')) {
    function learning_materials_db_has_column(mysqli $conn, $table, $column) {
        $table = (string) $table;
        $column = (string) $column;
        if ($table === '' || $column === '') return false;

        $stmt = $conn->prepare(
            "SELECT 1
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
             LIMIT 1"
        );
        if (!$stmt) return false;

        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $res = $stmt->get_result();
        $ok = ($res && $res->num_rows === 1);
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('ensure_learning_material_tables')) {
    function ensure_learning_material_tables(mysqli $conn) {
        $conn->query(
            "CREATE TABLE IF NOT EXISTS learning_materials (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                class_record_id INT NOT NULL,
                title VARCHAR(200) NOT NULL,
                summary TEXT NULL,
                content_html LONGTEXT NULL,
                status ENUM('draft','published') NOT NULL DEFAULT 'draft',
                display_order INT NOT NULL DEFAULT 0,
                published_at DATETIME NULL,
                created_by INT NULL,
                updated_by INT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_lm_class (class_record_id),
                KEY idx_lm_class_status (class_record_id, status),
                KEY idx_lm_class_order (class_record_id, display_order, id),
                CONSTRAINT fk_lm_class_record
                    FOREIGN KEY (class_record_id) REFERENCES class_records(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $conn->query(
            "CREATE TABLE IF NOT EXISTS learning_material_files (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                material_id INT NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                file_name VARCHAR(255) NOT NULL,
                file_path VARCHAR(500) NOT NULL,
                file_size BIGINT NOT NULL DEFAULT 0,
                mime_type VARCHAR(120) NULL,
                uploaded_by INT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_lmf_material (material_id),
                CONSTRAINT fk_lmf_material
                    FOREIGN KEY (material_id) REFERENCES learning_materials(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $conn->query(
            "CREATE TABLE IF NOT EXISTS learning_material_live_broadcasts (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                class_record_id INT NOT NULL,
                material_id INT NOT NULL,
                attachment_id INT NOT NULL,
                teacher_id INT NOT NULL,
                source_ext VARCHAR(16) NOT NULL DEFAULT '',
                source_file_path VARCHAR(500) NOT NULL DEFAULT '',
                slides_dir VARCHAR(500) NOT NULL DEFAULT '',
                access_code CHAR(6) NOT NULL,
                status ENUM('live','ended') NOT NULL DEFAULT 'live',
                slide_count INT NOT NULL DEFAULT 1,
                current_slide INT NOT NULL DEFAULT 1,
                started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ended_at DATETIME NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_lmlb_class (class_record_id),
                KEY idx_lmlb_material (material_id),
                KEY idx_lmlb_teacher_status (teacher_id, status),
                KEY idx_lmlb_code_status (access_code, status),
                CONSTRAINT fk_lmlb_class_record
                    FOREIGN KEY (class_record_id) REFERENCES class_records(id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_lmlb_material
                    FOREIGN KEY (material_id) REFERENCES learning_materials(id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_lmlb_attachment
                    FOREIGN KEY (attachment_id) REFERENCES learning_material_files(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        if (!learning_materials_db_has_column($conn, 'learning_materials', 'summary')) {
            $conn->query("ALTER TABLE learning_materials ADD COLUMN summary TEXT NULL AFTER title");
        }
        if (!learning_materials_db_has_column($conn, 'learning_materials', 'content_html')) {
            $conn->query("ALTER TABLE learning_materials ADD COLUMN content_html LONGTEXT NULL AFTER summary");
        }
        if (!learning_materials_db_has_column($conn, 'learning_materials', 'status')) {
            $conn->query("ALTER TABLE learning_materials ADD COLUMN status ENUM('draft','published') NOT NULL DEFAULT 'draft' AFTER content_html");
        }
        if (!learning_materials_db_has_column($conn, 'learning_materials', 'display_order')) {
            $conn->query("ALTER TABLE learning_materials ADD COLUMN display_order INT NOT NULL DEFAULT 0 AFTER status");
        }
        if (!learning_materials_db_has_column($conn, 'learning_materials', 'published_at')) {
            $conn->query("ALTER TABLE learning_materials ADD COLUMN published_at DATETIME NULL AFTER display_order");
        }
        if (!learning_materials_db_has_column($conn, 'learning_materials', 'created_by')) {
            $conn->query("ALTER TABLE learning_materials ADD COLUMN created_by INT NULL AFTER published_at");
        }
        if (!learning_materials_db_has_column($conn, 'learning_materials', 'updated_by')) {
            $conn->query("ALTER TABLE learning_materials ADD COLUMN updated_by INT NULL AFTER created_by");
        }
    }
}

if (!function_exists('learning_material_normalize_status')) {
    function learning_material_normalize_status($status) {
        $status = strtolower(trim((string) $status));
        return $status === 'published' ? 'published' : 'draft';
    }
}

if (!function_exists('learning_material_plain_text')) {
    function learning_material_plain_text($value, $maxLength = 0) {
        $value = strip_tags((string) $value);
        $value = preg_replace('/\s+/', ' ', (string) $value);
        $value = trim((string) $value);

        $maxLength = (int) $maxLength;
        if ($maxLength > 0) {
            if (function_exists('mb_strlen') && function_exists('mb_substr')) {
                if (mb_strlen($value, 'UTF-8') > $maxLength) {
                    $value = mb_substr($value, 0, $maxLength, 'UTF-8');
                }
            } elseif (strlen($value) > $maxLength) {
                $value = substr($value, 0, $maxLength);
            }
        }

        return $value;
    }
}

if (!function_exists('learning_material_clean_filename')) {
    function learning_material_clean_filename($name) {
        $name = trim((string) $name);
        if ($name === '') return 'file';
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
        $name = trim((string) $name, '._-');
        if ($name === '') $name = 'file';
        if (strlen($name) > 180) $name = substr($name, 0, 180);
        return $name;
    }
}

if (!function_exists('learning_material_root')) {
    function learning_material_root() {
        return dirname(__DIR__);
    }
}

if (!function_exists('learning_material_safe_rel_file_path')) {
    function learning_material_safe_rel_file_path($path) {
        $path = trim(str_replace('\\', '/', (string) $path));
        if ($path === '') return '';
        if (strpos($path, '..') !== false) return '';
        if (strpos($path, 'uploads/learning_materials/') !== 0) return '';
        return $path;
    }
}

if (!function_exists('learning_material_public_file_href')) {
    function learning_material_public_file_href($path) {
        $safe = learning_material_safe_rel_file_path($path);
        return $safe !== '' ? $safe : '#';
    }
}

if (!function_exists('learning_material_unlink_rel')) {
    function learning_material_unlink_rel($path) {
        $safe = learning_material_safe_rel_file_path($path);
        if ($safe === '') return;
        $abs = learning_material_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $safe);
        if (is_file($abs)) @unlink($abs);
    }
}

if (!function_exists('learning_material_store_attachment')) {
    function learning_material_store_attachment($materialId, array $file, $uploadedBy, &$error = '') {
        $error = '';
        $materialId = (int) $materialId;
        $uploadedBy = (int) $uploadedBy;
        if ($materialId <= 0) {
            $error = 'Invalid learning material.';
            return null;
        }

        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) {
            $error = 'Please choose a file.';
            return null;
        }
        if ($err !== UPLOAD_ERR_OK) {
            $error = 'Upload failed (error code: ' . $err . ').';
            return null;
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            $error = 'Invalid upload payload.';
            return null;
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            $error = 'Uploaded file is empty.';
            return null;
        }
        if ($size > (100 * 1024 * 1024)) {
            $error = 'File exceeds 100MB.';
            return null;
        }

        $original = (string) ($file['name'] ?? 'attachment');
        $clean = learning_material_clean_filename($original);
        $ext = strtolower((string) pathinfo($clean, PATHINFO_EXTENSION));
        $blocked = ['php', 'php3', 'php4', 'php5', 'phtml', 'phar', 'exe', 'bat', 'cmd', 'com', 'js', 'jar', 'msi', 'vbs', 'sh', 'ps1'];
        if ($ext !== '' && in_array($ext, $blocked, true)) {
            $error = 'This file type is blocked.';
            return null;
        }

        try {
            $token = bin2hex(random_bytes(8));
        } catch (Throwable $e) {
            $token = substr(md5(uniqid((string) mt_rand(), true)), 0, 16);
        }
        $stored = date('YmdHis') . '-' . $token . '-' . $clean;
        $relDir = 'uploads/learning_materials/m_' . $materialId;
        $relPath = $relDir . '/' . $stored;
        $absDir = learning_material_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relDir);
        if (!is_dir($absDir) && !@mkdir($absDir, 0775, true) && !is_dir($absDir)) {
            $error = 'Unable to create upload directory.';
            return null;
        }
        $absPath = learning_material_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
        if (!@move_uploaded_file($tmp, $absPath)) {
            $error = 'Unable to store uploaded file.';
            return null;
        }

        $mime = (string) ($file['type'] ?? 'application/octet-stream');
        if (function_exists('finfo_open')) {
            $fi = @finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) {
                $det = @finfo_file($fi, $absPath);
                if (is_string($det) && $det !== '') $mime = $det;
                @finfo_close($fi);
            }
        }

        return [
            'material_id' => $materialId,
            'original_name' => substr($original, 0, 255),
            'file_name' => substr($stored, 0, 255),
            'file_path' => substr($relPath, 0, 500),
            'file_size' => $size,
            'mime_type' => substr($mime, 0, 120),
            'uploaded_by' => $uploadedBy > 0 ? $uploadedBy : null,
        ];
    }
}

if (!function_exists('learning_material_fmt_bytes')) {
    function learning_material_fmt_bytes($bytes) {
        $bytes = (int) $bytes;
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1024 * 1024) return number_format($bytes / 1024, 2) . ' KB';
        return number_format($bytes / (1024 * 1024), 2) . ' MB';
    }
}

if (!function_exists('learning_material_fetch_attachments')) {
    function learning_material_fetch_attachments(mysqli $conn, $materialId) {
        $materialId = (int) $materialId;
        if ($materialId <= 0) return [];

        $rows = [];
        $stmt = $conn->prepare(
            "SELECT id, material_id, original_name, file_name, file_path, file_size, mime_type, uploaded_by, created_at
             FROM learning_material_files
             WHERE material_id = ?
             ORDER BY created_at DESC, id DESC"
        );
        if (!$stmt) return $rows;

        $stmt->bind_param('i', $materialId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $row['file_path'] = learning_material_safe_rel_file_path((string) ($row['file_path'] ?? ''));
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('learning_material_delete_attachment')) {
    function learning_material_delete_attachment(mysqli $conn, $attachmentId, $materialId) {
        $attachmentId = (int) $attachmentId;
        $materialId = (int) $materialId;
        if ($attachmentId <= 0 || $materialId <= 0) return false;

        $row = null;
        $find = $conn->prepare(
            "SELECT id, file_path
             FROM learning_material_files
             WHERE id = ?
               AND material_id = ?
             LIMIT 1"
        );
        if ($find) {
            $find->bind_param('ii', $attachmentId, $materialId);
            $find->execute();
            $res = $find->get_result();
            if ($res && $res->num_rows === 1) $row = $res->fetch_assoc();
            $find->close();
        }
        if (!is_array($row)) return false;

        $del = $conn->prepare(
            "DELETE FROM learning_material_files
             WHERE id = ?
               AND material_id = ?
             LIMIT 1"
        );
        if (!$del) return false;
        $del->bind_param('ii', $attachmentId, $materialId);
        $ok = $del->execute();
        $affected = (int) $del->affected_rows;
        $del->close();

        if ($ok && $affected > 0) {
            learning_material_unlink_rel((string) ($row['file_path'] ?? ''));
            return true;
        }
        return false;
    }
}

if (!function_exists('learning_material_delete_all_attachments')) {
    function learning_material_delete_all_attachments(mysqli $conn, $materialId) {
        $materialId = (int) $materialId;
        if ($materialId <= 0) return;

        $rows = learning_material_fetch_attachments($conn, $materialId);
        $del = $conn->prepare("DELETE FROM learning_material_files WHERE material_id = ?");
        if ($del) {
            $del->bind_param('i', $materialId);
            try { $del->execute(); } catch (Throwable $e) { /* ignore */ }
            $del->close();
        }

        foreach ($rows as $row) {
            learning_material_unlink_rel((string) ($row['file_path'] ?? ''));
        }
    }
}

if (!function_exists('learning_material_live_supported_extensions')) {
    function learning_material_live_supported_extensions() {
        return ['pdf', 'ppt', 'pptx', 'pptm', 'pps', 'ppsx'];
    }
}

if (!function_exists('learning_material_live_attachment_extension')) {
    function learning_material_live_attachment_extension(array $attachment) {
        $candidates = [
            (string) ($attachment['original_name'] ?? ''),
            (string) ($attachment['file_name'] ?? ''),
            (string) ($attachment['file_path'] ?? ''),
        ];
        foreach ($candidates as $name) {
            $name = trim($name);
            if ($name === '') continue;
            $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
            if ($ext !== '') return $ext;
        }
        return '';
    }
}

if (!function_exists('learning_material_live_filter_attachment_candidates')) {
    function learning_material_live_filter_attachment_candidates(array $attachments) {
        $allowed = array_flip(learning_material_live_supported_extensions());
        $rows = [];
        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) continue;
            $ext = learning_material_live_attachment_extension($attachment);
            if ($ext !== '' && isset($allowed[$ext])) {
                $attachment['live_ext'] = $ext;
                $rows[] = $attachment;
            }
        }
        return $rows;
    }
}

if (!function_exists('learning_material_live_normalize_code')) {
    function learning_material_live_normalize_code($code) {
        $code = preg_replace('/[^0-9]/', '', (string) $code);
        if (!is_string($code)) $code = '';
        if (strlen($code) > 6) $code = substr($code, 0, 6);
        return $code;
    }
}

if (!function_exists('learning_material_live_code_is_valid')) {
    function learning_material_live_code_is_valid($code) {
        $code = learning_material_live_normalize_code($code);
        return preg_match('/^[0-9]{6}$/', $code) === 1;
    }
}

if (!function_exists('learning_material_live_safe_rel_dir')) {
    function learning_material_live_safe_rel_dir($path) {
        $path = trim(str_replace('\\', '/', (string) $path));
        if ($path === '') return '';
        if (strpos($path, '..') !== false) return '';
        if (strpos($path, 'uploads/learning_materials/live_broadcasts/') !== 0) return '';
        return rtrim($path, '/');
    }
}

if (!function_exists('learning_material_live_rel_dir_abs')) {
    function learning_material_live_rel_dir_abs($path) {
        $safe = learning_material_live_safe_rel_dir($path);
        if ($safe === '') return '';
        return learning_material_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $safe);
    }
}

if (!function_exists('learning_material_live_slide_href')) {
    function learning_material_live_slide_href($slidesDir, $slideNo = 1) {
        $safeDir = learning_material_live_safe_rel_dir($slidesDir);
        if ($safeDir === '') return '';
        $slideNo = (int) $slideNo;
        if ($slideNo <= 0) $slideNo = 1;
        $name = 'slide-' . str_pad((string) $slideNo, 3, '0', STR_PAD_LEFT) . '.png';
        $rel = $safeDir . '/' . $name;
        $abs = learning_material_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (is_file($abs)) return $rel;

        $fallbackRel = $safeDir . '/slide-001.png';
        $fallbackAbs = learning_material_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $fallbackRel);
        return is_file($fallbackAbs) ? $fallbackRel : '';
    }
}

if (!function_exists('learning_material_live_tmp_dir_create')) {
    function learning_material_live_tmp_dir_create($prefix = 'lm_live_') {
        $base = sys_get_temp_dir();
        if (!is_string($base) || $base === '') return '';

        $token = '';
        try {
            $token = bin2hex(random_bytes(7));
        } catch (Throwable $e) {
            $token = preg_replace('/[^A-Za-z0-9._-]/', '', (string) uniqid('', true));
        }
        if (!is_string($token) || trim($token) === '') $token = (string) mt_rand(100000, 999999);

        $dir = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . $prefix . $token;
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) return '';
        return $dir;
    }
}

if (!function_exists('learning_material_live_tmp_dir_delete')) {
    function learning_material_live_tmp_dir_delete($dir) {
        $dir = (string) $dir;
        if ($dir === '' || !is_dir($dir)) return;
        $items = @scandir($dir);
        if (!is_array($items)) {
            @rmdir($dir);
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                learning_material_live_tmp_dir_delete($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}

if (!function_exists('learning_material_live_exec_available')) {
    function learning_material_live_exec_available() {
        if (!function_exists('exec')) return false;
        $disabledRaw = strtolower((string) ini_get('disable_functions'));
        if ($disabledRaw !== '') {
            $disabled = array_map('trim', explode(',', $disabledRaw));
            if (in_array('exec', $disabled, true)) return false;
        }
        return true;
    }
}

if (!function_exists('learning_material_live_cmd_wrap')) {
    function learning_material_live_cmd_wrap($binary, array $args = []) {
        $binary = trim((string) $binary);
        if ($binary === '') return '';
        $cmd = (preg_match('/^[A-Za-z0-9._-]+$/', $binary) === 1)
            ? $binary
            : escapeshellarg($binary);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg((string) $arg);
        }
        return $cmd;
    }
}

if (!function_exists('learning_material_live_find_soffice')) {
    function learning_material_live_find_soffice() {
        static $cached = null;
        if ($cached !== null) return (string) $cached;
        if (!learning_material_live_exec_available()) {
            $cached = '';
            return '';
        }

        $candidates = [];
        foreach (['SOFFICE_BIN', 'LIBREOFFICE_BIN'] as $envName) {
            $env = trim((string) getenv($envName));
            if ($env !== '') $candidates[] = $env;
        }
        $candidates[] = 'soffice';
        $candidates[] = 'libreoffice';

        if (stripos(PHP_OS, 'WIN') === 0) {
            $candidates[] = 'C:\\Program Files\\LibreOffice\\program\\soffice.exe';
            $candidates[] = 'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe';
        }

        $candidates = array_values(array_unique(array_filter($candidates, function ($v) {
            return trim((string) $v) !== '';
        })));

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '') continue;
            $looksLikePath = (strpos($candidate, '\\') !== false || strpos($candidate, '/') !== false);
            if ($looksLikePath && !is_file($candidate)) continue;

            $out = [];
            $status = 1;
            $cmd = learning_material_live_cmd_wrap($candidate, ['--version']);
            if ($cmd === '') continue;
            @exec($cmd . ' 2>&1', $out, $status);
            if ($status === 0) {
                $cached = $candidate;
                return (string) $cached;
            }
        }

        $cached = '';
        return '';
    }
}

if (!function_exists('learning_material_live_find_pdftoppm')) {
    function learning_material_live_find_pdftoppm() {
        static $cached = null;
        if ($cached !== null) return (string) $cached;
        if (!learning_material_live_exec_available()) {
            $cached = '';
            return '';
        }

        $candidates = [];
        $env = trim((string) getenv('PDFTOPPM_BIN'));
        if ($env !== '') $candidates[] = $env;
        $candidates[] = 'pdftoppm';

        if (stripos(PHP_OS, 'WIN') === 0) {
            $candidates[] = 'C:\\Program Files\\poppler\\Library\\bin\\pdftoppm.exe';
            $candidates[] = 'C:\\Program Files (x86)\\poppler\\Library\\bin\\pdftoppm.exe';

            $localAppData = trim((string) getenv('LOCALAPPDATA'));
            if ($localAppData !== '') {
                $pattern = rtrim(str_replace('/', '\\', $localAppData), '\\')
                    . '\\Microsoft\\WinGet\\Packages\\oschwartz10612.Poppler*\\poppler*\\Library\\bin\\pdftoppm.exe';
                $matched = glob($pattern);
                if (is_array($matched) && count($matched) > 0) {
                    usort($matched, function ($a, $b) {
                        return (int) @filemtime((string) $b) <=> (int) @filemtime((string) $a);
                    });
                    foreach ($matched as $m) {
                        if (is_string($m) && trim($m) !== '') $candidates[] = $m;
                    }
                }
            }

            $whereOut = [];
            $whereStatus = 1;
            @exec('where pdftoppm 2>NUL', $whereOut, $whereStatus);
            if ($whereStatus === 0) {
                foreach ($whereOut as $wherePath) {
                    $wherePath = trim((string) $wherePath);
                    if ($wherePath !== '') $candidates[] = $wherePath;
                }
            }
        }

        $candidates = array_values(array_unique(array_filter($candidates, function ($v) {
            return trim((string) $v) !== '';
        })));

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '') continue;
            $looksLikePath = (strpos($candidate, '\\') !== false || strpos($candidate, '/') !== false);
            if ($looksLikePath && !is_file($candidate)) continue;

            $out = [];
            $status = 1;
            $cmd = learning_material_live_cmd_wrap($candidate, ['-v']);
            if ($cmd === '') continue;
            @exec($cmd . ' 2>&1', $out, $status);
            $probe = strtolower(trim(implode(' ', $out)));
            $hasVersionOutput = (preg_match('/pdftoppm\\s+version/i', (string) $probe) === 1);
            if ($status === 0 || $hasVersionOutput) {
                $cached = $candidate;
                return (string) $cached;
            }
        }

        $cached = '';
        return '';
    }
}

if (!function_exists('learning_material_live_convert_presentation_to_pdf')) {
    function learning_material_live_convert_presentation_to_pdf($sourceAbsPath, $tmpDir, $sofficeBinary, &$error = '') {
        $error = '';
        $sourceAbsPath = (string) $sourceAbsPath;
        $tmpDir = (string) $tmpDir;
        $sofficeBinary = trim((string) $sofficeBinary);

        if (!learning_material_live_exec_available()) {
            $error = 'Server command execution is disabled.';
            return '';
        }
        if ($sofficeBinary === '') {
            $error = 'LibreOffice command is not available.';
            return '';
        }
        if ($sourceAbsPath === '' || !is_file($sourceAbsPath)) {
            $error = 'Presentation source file was not found.';
            return '';
        }
        if ($tmpDir === '' || !is_dir($tmpDir)) {
            $error = 'Temporary conversion directory is not available.';
            return '';
        }

        $cmd = learning_material_live_cmd_wrap($sofficeBinary, [
            '--headless',
            '--nologo',
            '--nofirststartwizard',
            '--convert-to',
            'pdf',
            '--outdir',
            $tmpDir,
            $sourceAbsPath,
        ]);
        if ($cmd === '') {
            $error = 'Unable to build LibreOffice command.';
            return '';
        }

        $out = [];
        $status = 1;
        @exec($cmd . ' 2>&1', $out, $status);
        if ($status !== 0) {
            $detail = trim(implode(' ', $out));
            if ($detail !== '' && strlen($detail) > 240) $detail = substr($detail, 0, 240) . '...';
            $error = 'Presentation to PDF conversion failed.' . ($detail !== '' ? (' ' . $detail) : '');
            return '';
        }

        $baseName = (string) pathinfo($sourceAbsPath, PATHINFO_FILENAME);
        $candidate = $tmpDir . DIRECTORY_SEPARATOR . $baseName . '.pdf';
        if (is_file($candidate) && filesize($candidate) > 0) {
            return $candidate;
        }

        $pdfFiles = glob($tmpDir . DIRECTORY_SEPARATOR . '*.pdf');
        if (!is_array($pdfFiles) || count($pdfFiles) === 0) {
            $error = 'No PDF file was generated by LibreOffice.';
            return '';
        }

        usort($pdfFiles, function ($a, $b) {
            return (int) @filemtime((string) $b) <=> (int) @filemtime((string) $a);
        });
        $first = (string) ($pdfFiles[0] ?? '');
        if ($first === '' || !is_file($first)) {
            $error = 'Generated PDF file could not be resolved.';
            return '';
        }
        return $first;
    }
}

if (!function_exists('learning_material_live_convert_pdf_to_png')) {
    function learning_material_live_convert_pdf_to_png($pdfAbsPath, $tmpDir, $pdftoppmBinary, &$error = '') {
        $error = '';
        $pdfAbsPath = (string) $pdfAbsPath;
        $tmpDir = (string) $tmpDir;
        $pdftoppmBinary = trim((string) $pdftoppmBinary);

        if (!learning_material_live_exec_available()) {
            $error = 'Server command execution is disabled.';
            return [];
        }
        if ($pdftoppmBinary === '') {
            $error = 'pdftoppm command is not available.';
            return [];
        }
        if ($pdfAbsPath === '' || !is_file($pdfAbsPath)) {
            $error = 'PDF source file was not found.';
            return [];
        }
        if ($tmpDir === '' || !is_dir($tmpDir)) {
            $error = 'Temporary conversion directory is not available.';
            return [];
        }

        $prefix = $tmpDir . DIRECTORY_SEPARATOR . 'slide';
        $cmd = learning_material_live_cmd_wrap($pdftoppmBinary, [
            '-png',
            '-r',
            '160',
            $pdfAbsPath,
            $prefix,
        ]);
        if ($cmd === '') {
            $error = 'Unable to build pdftoppm command.';
            return [];
        }

        $out = [];
        $status = 1;
        @exec($cmd . ' 2>&1', $out, $status);
        if ($status !== 0) {
            $detail = trim(implode(' ', $out));
            if ($detail !== '' && strlen($detail) > 240) $detail = substr($detail, 0, 240) . '...';
            $error = 'PDF to slide conversion failed.' . ($detail !== '' ? (' ' . $detail) : '');
            return [];
        }

        $pngFiles = glob($tmpDir . DIRECTORY_SEPARATOR . 'slide-*.png');
        if (!is_array($pngFiles) || count($pngFiles) === 0) {
            $error = 'No slide images were produced.';
            return [];
        }

        natsort($pngFiles);
        return array_values($pngFiles);
    }
}

if (!function_exists('learning_material_live_store_png_slides')) {
    function learning_material_live_store_png_slides(array $pngFiles, $classRecordId, $materialId, &$error = '') {
        $error = '';
        $classRecordId = (int) $classRecordId;
        $materialId = (int) $materialId;
        if ($classRecordId <= 0 || $materialId <= 0) {
            $error = 'Invalid class/material reference.';
            return null;
        }
        if (count($pngFiles) === 0) {
            $error = 'No slide images to store.';
            return null;
        }

        $token = '';
        try {
            $token = bin2hex(random_bytes(6));
        } catch (Throwable $e) {
            $token = preg_replace('/[^A-Za-z0-9]/', '', (string) uniqid('', true));
        }
        if (!is_string($token) || trim($token) === '') $token = (string) mt_rand(100000, 999999);

        $relDir = 'uploads/learning_materials/live_broadcasts'
            . '/c_' . $classRecordId
            . '/m_' . $materialId
            . '/b_' . date('YmdHis') . '_' . $token;
        $absDir = learning_material_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relDir);
        if (!is_dir($absDir) && !@mkdir($absDir, 0775, true) && !is_dir($absDir)) {
            $error = 'Unable to create live slide directory.';
            return null;
        }

        $savedCount = 0;
        foreach ($pngFiles as $index => $src) {
            $src = (string) $src;
            if ($src === '' || !is_file($src)) continue;
            $slideNo = (int) $index + 1;
            $dest = $absDir . DIRECTORY_SEPARATOR . 'slide-' . str_pad((string) $slideNo, 3, '0', STR_PAD_LEFT) . '.png';
            if (!@copy($src, $dest)) {
                learning_material_live_tmp_dir_delete($absDir);
                $error = 'Unable to store slide image files.';
                return null;
            }
            $savedCount++;
        }

        if ($savedCount <= 0) {
            learning_material_live_tmp_dir_delete($absDir);
            $error = 'No slide images were saved.';
            return null;
        }

        return [
            'slides_dir' => $relDir,
            'slide_count' => $savedCount,
        ];
    }
}

if (!function_exists('learning_material_live_delete_slides_dir')) {
    function learning_material_live_delete_slides_dir($slidesDir) {
        $safe = learning_material_live_safe_rel_dir($slidesDir);
        if ($safe === '') return;
        $abs = learning_material_live_rel_dir_abs($safe);
        if ($abs !== '' && is_dir($abs)) {
            learning_material_live_tmp_dir_delete($abs);
        }
    }
}

if (!function_exists('learning_material_live_generate_code')) {
    function learning_material_live_generate_code(mysqli $conn) {
        $check = $conn->prepare(
            "SELECT id
             FROM learning_material_live_broadcasts
             WHERE access_code = ?
               AND status = 'live'
             LIMIT 1"
        );

        for ($i = 0; $i < 35; $i++) {
            try {
                $code = (string) random_int(100000, 999999);
            } catch (Throwable $e) {
                $code = (string) mt_rand(100000, 999999);
            }
            if (!learning_material_live_code_is_valid($code)) continue;

            if (!$check) return $code;
            $check->bind_param('s', $code);
            $check->execute();
            $res = $check->get_result();
            if (!($res && $res->num_rows > 0)) {
                $check->close();
                return $code;
            }
        }

        if ($check) $check->close();
        return str_pad((string) mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('learning_material_live_slide_href_from_row')) {
    function learning_material_live_slide_href_from_row(array $row) {
        $slidesDir = (string) ($row['slides_dir'] ?? '');
        $slideNo = (int) ($row['current_slide'] ?? 1);
        return learning_material_live_slide_href($slidesDir, $slideNo);
    }
}

if (!function_exists('learning_material_live_prepare_row')) {
    function learning_material_live_prepare_row(array $row) {
        $row['id'] = (int) ($row['id'] ?? 0);
        $row['class_record_id'] = (int) ($row['class_record_id'] ?? 0);
        $row['material_id'] = (int) ($row['material_id'] ?? 0);
        $row['attachment_id'] = (int) ($row['attachment_id'] ?? 0);
        $row['teacher_id'] = (int) ($row['teacher_id'] ?? 0);
        $row['slide_count'] = (int) ($row['slide_count'] ?? 1);
        if ($row['slide_count'] <= 0) $row['slide_count'] = 1;
        $row['current_slide'] = (int) ($row['current_slide'] ?? 1);
        if ($row['current_slide'] <= 0) $row['current_slide'] = 1;
        if ($row['current_slide'] > $row['slide_count']) $row['current_slide'] = $row['slide_count'];
        $row['source_ext'] = strtolower(trim((string) ($row['source_ext'] ?? '')));
        $row['source_file_path'] = learning_material_safe_rel_file_path((string) ($row['source_file_path'] ?? ''));
        $row['slides_dir'] = learning_material_live_safe_rel_dir((string) ($row['slides_dir'] ?? ''));
        $row['access_code'] = learning_material_live_normalize_code((string) ($row['access_code'] ?? ''));
        $row['slide_href'] = learning_material_live_slide_href_from_row($row);
        return $row;
    }
}

if (!function_exists('learning_material_live_fetch_teacher_attachment_context')) {
    function learning_material_live_fetch_teacher_attachment_context(mysqli $conn, $teacherId, $classRecordId, $materialId, $attachmentId) {
        $teacherId = (int) $teacherId;
        $classRecordId = (int) $classRecordId;
        $materialId = (int) $materialId;
        $attachmentId = (int) $attachmentId;
        if ($teacherId <= 0 || $classRecordId <= 0 || $materialId <= 0 || $attachmentId <= 0) return null;

        $row = null;
        $stmt = $conn->prepare(
            "SELECT lm.class_record_id,
                    lm.id AS material_id,
                    lm.title AS material_title,
                    lmf.id AS attachment_id,
                    lmf.original_name,
                    lmf.file_name,
                    lmf.file_path
             FROM learning_materials lm
             JOIN learning_material_files lmf
               ON lmf.material_id = lm.id
             JOIN class_records cr
               ON cr.id = lm.class_record_id
             JOIN teacher_assignments ta
               ON ta.class_record_id = lm.class_record_id
              AND ta.teacher_id = ?
              AND ta.status = 'active'
             WHERE lm.class_record_id = ?
               AND lm.id = ?
               AND lmf.id = ?
               AND cr.status = 'active'
             LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param('iiii', $teacherId, $classRecordId, $materialId, $attachmentId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows === 1) $row = $res->fetch_assoc();
            $stmt->close();
        }
        if (!is_array($row)) return null;

        $safePath = learning_material_safe_rel_file_path((string) ($row['file_path'] ?? ''));
        if ($safePath === '') return null;
        $ext = learning_material_live_attachment_extension($row);
        if (!in_array($ext, learning_material_live_supported_extensions(), true)) return null;
        $absPath = learning_material_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $safePath);
        if (!is_file($absPath)) return null;

        $row['class_record_id'] = (int) ($row['class_record_id'] ?? 0);
        $row['material_id'] = (int) ($row['material_id'] ?? 0);
        $row['attachment_id'] = (int) ($row['attachment_id'] ?? 0);
        $row['file_path'] = $safePath;
        $row['live_ext'] = $ext;
        $row['source_abs_path'] = $absPath;
        return $row;
    }
}

if (!function_exists('learning_material_student_id_from_user')) {
    function learning_material_student_id_from_user(mysqli $conn, $userId) {
        $userId = (int) $userId;
        if ($userId <= 0) return 0;
        $studentId = 0;
        $stmt = $conn->prepare(
            "SELECT id
             FROM students
             WHERE user_id = ?
             LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows === 1) {
                $studentId = (int) (($res->fetch_assoc()['id'] ?? 0));
            }
            $stmt->close();
        }
        return $studentId;
    }
}

if (!function_exists('learning_material_live_get_active_for_material')) {
    function learning_material_live_get_active_for_material(mysqli $conn, $classRecordId, $materialId) {
        $classRecordId = (int) $classRecordId;
        $materialId = (int) $materialId;
        if ($classRecordId <= 0 || $materialId <= 0) return null;

        $row = null;
        $stmt = $conn->prepare(
            "SELECT *
             FROM learning_material_live_broadcasts
             WHERE class_record_id = ?
               AND material_id = ?
               AND status = 'live'
             ORDER BY id DESC
             LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param('ii', $classRecordId, $materialId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows === 1) $row = $res->fetch_assoc();
            $stmt->close();
        }
        return is_array($row) ? learning_material_live_prepare_row($row) : null;
    }
}

if (!function_exists('learning_material_live_get_teacher_broadcast_by_id')) {
    function learning_material_live_get_teacher_broadcast_by_id(mysqli $conn, $teacherId, $broadcastId, $requireLive = false) {
        $teacherId = (int) $teacherId;
        $broadcastId = (int) $broadcastId;
        $requireLive = (bool) $requireLive;
        if ($teacherId <= 0 || $broadcastId <= 0) return null;

        $row = null;
        $sql = "SELECT lb.*,
                       lm.title AS material_title,
                       lmf.original_name AS attachment_name,
                       cr.section,
                       s.subject_code,
                       s.subject_name
                FROM learning_material_live_broadcasts lb
                JOIN learning_materials lm ON lm.id = lb.material_id
                JOIN class_records cr ON cr.id = lb.class_record_id
                JOIN subjects s ON s.id = cr.subject_id
                LEFT JOIN learning_material_files lmf ON lmf.id = lb.attachment_id
                WHERE lb.id = ?
                  AND lb.teacher_id = ?";
        if ($requireLive) {
            $sql .= " AND lb.status = 'live'";
        }
        $sql .= " LIMIT 1";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('ii', $broadcastId, $teacherId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows === 1) $row = $res->fetch_assoc();
            $stmt->close();
        }

        return is_array($row) ? learning_material_live_prepare_row($row) : null;
    }
}

if (!function_exists('learning_material_live_get_teacher_broadcast')) {
    function learning_material_live_get_teacher_broadcast(mysqli $conn, $teacherId, $classRecordId, $materialId) {
        $teacherId = (int) $teacherId;
        $classRecordId = (int) $classRecordId;
        $materialId = (int) $materialId;
        if ($teacherId <= 0 || $classRecordId <= 0 || $materialId <= 0) return null;

        $row = null;
        $stmt = $conn->prepare(
            "SELECT lb.*,
                    lm.title AS material_title,
                    lmf.original_name AS attachment_name,
                    cr.section,
                    s.subject_code,
                    s.subject_name
             FROM learning_material_live_broadcasts lb
             JOIN learning_materials lm ON lm.id = lb.material_id
             JOIN class_records cr ON cr.id = lb.class_record_id
             JOIN subjects s ON s.id = cr.subject_id
             LEFT JOIN learning_material_files lmf ON lmf.id = lb.attachment_id
             JOIN teacher_assignments ta
               ON ta.class_record_id = lb.class_record_id
              AND ta.teacher_id = ?
              AND ta.status = 'active'
             WHERE lb.teacher_id = ?
               AND lb.class_record_id = ?
               AND lb.material_id = ?
               AND lb.status = 'live'
             ORDER BY lb.id DESC
             LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param('iiii', $teacherId, $teacherId, $classRecordId, $materialId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows === 1) $row = $res->fetch_assoc();
            $stmt->close();
        }

        return is_array($row) ? learning_material_live_prepare_row($row) : null;
    }
}

if (!function_exists('learning_material_live_get_student_broadcast_by_code')) {
    function learning_material_live_get_student_broadcast_by_code(mysqli $conn, $studentId, $accessCode) {
        $studentId = (int) $studentId;
        $accessCode = learning_material_live_normalize_code($accessCode);
        if ($studentId <= 0 || !learning_material_live_code_is_valid($accessCode)) return null;

        $row = null;
        $stmt = $conn->prepare(
            "SELECT lb.*,
                    lm.title AS material_title,
                    lmf.original_name AS attachment_name,
                    cr.section,
                    cr.academic_year,
                    cr.semester,
                    s.subject_code,
                    s.subject_name
             FROM learning_material_live_broadcasts lb
             JOIN learning_materials lm ON lm.id = lb.material_id
             JOIN class_records cr ON cr.id = lb.class_record_id
             JOIN subjects s ON s.id = cr.subject_id
             LEFT JOIN learning_material_files lmf ON lmf.id = lb.attachment_id
             JOIN class_enrollments ce
               ON ce.class_record_id = lb.class_record_id
              AND ce.student_id = ?
              AND ce.status = 'enrolled'
             WHERE lb.access_code = ?
               AND lb.status = 'live'
               AND cr.status = 'active'
             ORDER BY lb.id DESC
             LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param('is', $studentId, $accessCode);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows === 1) $row = $res->fetch_assoc();
            $stmt->close();
        }
        return is_array($row) ? learning_material_live_prepare_row($row) : null;
    }
}

if (!function_exists('learning_material_live_end_existing_for_material')) {
    function learning_material_live_end_existing_for_material(mysqli $conn, $classRecordId, $materialId) {
        $classRecordId = (int) $classRecordId;
        $materialId = (int) $materialId;
        if ($classRecordId <= 0 || $materialId <= 0) return;

        $rows = [];
        $find = $conn->prepare(
            "SELECT id, slides_dir
             FROM learning_material_live_broadcasts
             WHERE class_record_id = ?
               AND material_id = ?
               AND status = 'live'"
        );
        if ($find) {
            $find->bind_param('ii', $classRecordId, $materialId);
            $find->execute();
            $res = $find->get_result();
            while ($res && ($r = $res->fetch_assoc())) $rows[] = $r;
            $find->close();
        }

        if (count($rows) === 0) return;
        $up = $conn->prepare(
            "UPDATE learning_material_live_broadcasts
             SET status = 'ended',
                 ended_at = NOW(),
                 slides_dir = ''
             WHERE id = ?
               AND status = 'live'
             LIMIT 1"
        );
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0 && $up) {
                $up->bind_param('i', $id);
                try { $up->execute(); } catch (Throwable $e) { /* ignore */ }
            }
            learning_material_live_delete_slides_dir((string) ($row['slides_dir'] ?? ''));
        }
        if ($up) $up->close();
    }
}

if (!function_exists('learning_material_live_start_from_attachment')) {
    function learning_material_live_start_from_attachment(mysqli $conn, $teacherId, $classRecordId, $materialId, $attachmentId, &$error = '') {
        $error = '';
        $teacherId = (int) $teacherId;
        $classRecordId = (int) $classRecordId;
        $materialId = (int) $materialId;
        $attachmentId = (int) $attachmentId;
        if ($teacherId <= 0 || $classRecordId <= 0 || $materialId <= 0 || $attachmentId <= 0) {
            $error = 'Invalid live broadcast request.';
            return null;
        }

        $ctx = learning_material_live_fetch_teacher_attachment_context($conn, $teacherId, $classRecordId, $materialId, $attachmentId);
        if (!is_array($ctx)) {
            $error = 'Selected file is not available for live slides.';
            return null;
        }

        $ext = strtolower((string) ($ctx['live_ext'] ?? ''));
        $sourceAbs = (string) ($ctx['source_abs_path'] ?? '');
        if ($sourceAbs === '' || !is_file($sourceAbs)) {
            $error = 'Live source file does not exist on server.';
            return null;
        }

        $tmpDir = learning_material_live_tmp_dir_create('lm_live_build_');
        if ($tmpDir === '') {
            $error = 'Unable to create temporary conversion directory.';
            return null;
        }

        $savedSlidesDir = '';
        $keepSlides = false;

        try {
            $workExt = $ext !== '' ? $ext : 'bin';
            $workSource = $tmpDir . DIRECTORY_SEPARATOR . 'source.' . $workExt;
            if (!@copy($sourceAbs, $workSource)) {
                $error = 'Unable to prepare source file for conversion.';
                return null;
            }

            $pdfPath = '';
            if ($ext === 'pdf') {
                $pdfPath = $workSource;
            } else {
                $soffice = learning_material_live_find_soffice();
                if ($soffice === '') {
                    $error = 'LibreOffice is not available on server. Install it to broadcast PPT/PPTX files.';
                    return null;
                }
                $pdfPath = learning_material_live_convert_presentation_to_pdf($workSource, $tmpDir, $soffice, $error);
                if ($pdfPath === '') return null;
            }

            $pdftoppm = learning_material_live_find_pdftoppm();
            if ($pdftoppm === '') {
                $error = 'pdftoppm is not available on server. Install poppler to generate slide images.';
                return null;
            }

            $pngFiles = learning_material_live_convert_pdf_to_png($pdfPath, $tmpDir, $pdftoppm, $error);
            if (count($pngFiles) === 0) {
                if ($error === '') $error = 'No slide images were generated.';
                return null;
            }

            $stored = learning_material_live_store_png_slides($pngFiles, $classRecordId, $materialId, $error);
            if (!is_array($stored)) return null;

            $savedSlidesDir = (string) ($stored['slides_dir'] ?? '');
            $slideCount = (int) ($stored['slide_count'] ?? 0);
            if ($savedSlidesDir === '' || $slideCount <= 0) {
                $error = 'Failed to save live slide assets.';
                return null;
            }

            learning_material_live_end_existing_for_material($conn, $classRecordId, $materialId);

            $code = learning_material_live_generate_code($conn);
            $sourcePath = (string) ($ctx['file_path'] ?? '');
            $ins = $conn->prepare(
                "INSERT INTO learning_material_live_broadcasts
                    (class_record_id, material_id, attachment_id, teacher_id, source_ext, source_file_path, slides_dir, access_code, status, slide_count, current_slide, started_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'live', ?, 1, NOW())"
            );
            if (!$ins) {
                $error = 'Unable to create live broadcast session.';
                return null;
            }
            $ins->bind_param(
                'iiiissssi',
                $classRecordId,
                $materialId,
                $attachmentId,
                $teacherId,
                $ext,
                $sourcePath,
                $savedSlidesDir,
                $code,
                $slideCount
            );
            $ok = $ins->execute();
            $broadcastId = (int) $conn->insert_id;
            $ins->close();
            if (!$ok || $broadcastId <= 0) {
                $error = 'Unable to save live broadcast session.';
                return null;
            }

            $keepSlides = true;
            $row = learning_material_live_get_teacher_broadcast_by_id($conn, $teacherId, $broadcastId, true);
            if (!is_array($row)) {
                $row = learning_material_live_get_teacher_broadcast($conn, $teacherId, $classRecordId, $materialId);
            }
            if (!is_array($row)) {
                $error = 'Live session started but failed to load its state.';
                return null;
            }
            return $row;
        } finally {
            learning_material_live_tmp_dir_delete($tmpDir);
            if (!$keepSlides && $savedSlidesDir !== '') {
                learning_material_live_delete_slides_dir($savedSlidesDir);
            }
        }
    }
}

if (!function_exists('learning_material_live_set_slide_for_teacher')) {
    function learning_material_live_set_slide_for_teacher(mysqli $conn, $teacherId, $broadcastId, $slideNo, &$error = '') {
        $error = '';
        $teacherId = (int) $teacherId;
        $broadcastId = (int) $broadcastId;
        $slideNo = (int) $slideNo;
        if ($teacherId <= 0 || $broadcastId <= 0) {
            $error = 'Invalid live broadcast.';
            return null;
        }

        $row = null;
        $find = $conn->prepare(
            "SELECT id, slide_count
             FROM learning_material_live_broadcasts
             WHERE id = ?
               AND teacher_id = ?
               AND status = 'live'
             LIMIT 1"
        );
        if ($find) {
            $find->bind_param('ii', $broadcastId, $teacherId);
            $find->execute();
            $res = $find->get_result();
            if ($res && $res->num_rows === 1) $row = $res->fetch_assoc();
            $find->close();
        }
        if (!is_array($row)) {
            $error = 'Live broadcast was not found or already ended.';
            return null;
        }

        $slideCount = (int) ($row['slide_count'] ?? 1);
        if ($slideCount <= 0) $slideCount = 1;
        if ($slideNo <= 0) $slideNo = 1;
        if ($slideNo > $slideCount) $slideNo = $slideCount;

        $up = $conn->prepare(
            "UPDATE learning_material_live_broadcasts
             SET current_slide = ?
             WHERE id = ?
               AND teacher_id = ?
               AND status = 'live'
             LIMIT 1"
        );
        if (!$up) {
            $error = 'Unable to update live slide position.';
            return null;
        }
        $up->bind_param('iii', $slideNo, $broadcastId, $teacherId);
        $ok = $up->execute();
        $up->close();
        if (!$ok) {
            $error = 'Unable to update live slide position.';
            return null;
        }

        $updated = learning_material_live_get_teacher_broadcast_by_id($conn, $teacherId, $broadcastId, true);
        if (!is_array($updated)) {
            $error = 'Failed to load live broadcast state.';
            return null;
        }
        return $updated;
    }
}

if (!function_exists('learning_material_live_step_slide_for_teacher')) {
    function learning_material_live_step_slide_for_teacher(mysqli $conn, $teacherId, $broadcastId, $delta, &$error = '') {
        $error = '';
        $teacherId = (int) $teacherId;
        $broadcastId = (int) $broadcastId;
        $delta = (int) $delta;
        if ($teacherId <= 0 || $broadcastId <= 0) {
            $error = 'Invalid live broadcast.';
            return null;
        }

        $row = learning_material_live_get_teacher_broadcast_by_id($conn, $teacherId, $broadcastId, true);
        if (!is_array($row)) {
            $error = 'Live broadcast was not found or already ended.';
            return null;
        }
        if ($delta === 0) return $row;

        $targetSlide = (int) ($row['current_slide'] ?? 1) + $delta;
        return learning_material_live_set_slide_for_teacher($conn, $teacherId, $broadcastId, $targetSlide, $error);
    }
}

if (!function_exists('learning_material_live_end_for_teacher')) {
    function learning_material_live_end_for_teacher(mysqli $conn, $teacherId, $broadcastId, &$error = '') {
        $error = '';
        $teacherId = (int) $teacherId;
        $broadcastId = (int) $broadcastId;
        if ($teacherId <= 0 || $broadcastId <= 0) {
            $error = 'Invalid live broadcast.';
            return false;
        }

        $row = learning_material_live_get_teacher_broadcast_by_id($conn, $teacherId, $broadcastId, true);
        if (!is_array($row)) {
            $error = 'Live broadcast was not found or already ended.';
            return false;
        }

        $up = $conn->prepare(
            "UPDATE learning_material_live_broadcasts
             SET status = 'ended',
                 ended_at = NOW(),
                 slides_dir = ''
             WHERE id = ?
               AND teacher_id = ?
               AND status = 'live'
             LIMIT 1"
        );
        if (!$up) {
            $error = 'Unable to end live broadcast.';
            return false;
        }
        $up->bind_param('ii', $broadcastId, $teacherId);
        $ok = $up->execute();
        $affected = (int) $up->affected_rows;
        $up->close();

        if (!$ok || $affected <= 0) {
            $error = 'Unable to end live broadcast.';
            return false;
        }

        learning_material_live_delete_slides_dir((string) ($row['slides_dir'] ?? ''));
        return true;
    }
}

if (!function_exists('learning_material_next_display_order')) {
    function learning_material_next_display_order(mysqli $conn, $classRecordId) {
        $classRecordId = (int) $classRecordId;
        if ($classRecordId <= 0) return 1;

        $next = 1;
        $stmt = $conn->prepare(
            "SELECT COALESCE(MAX(display_order), 0) + 1 AS next_order
             FROM learning_materials
             WHERE class_record_id = ?"
        );
        if ($stmt) {
            $stmt->bind_param('i', $classRecordId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows === 1) {
                $next = (int) (($res->fetch_assoc()['next_order'] ?? 1));
            }
            $stmt->close();
        }

        return $next > 0 ? $next : 1;
    }
}

if (!function_exists('learning_material_is_safe_url')) {
    function learning_material_is_safe_url($url, $forImage = false) {
        $url = trim((string) $url);
        if ($url === '') return false;

        if ($url[0] === '#') return true;
        if (strpos($url, '/') === 0 && strpos($url, '//') !== 0) return true;
        if (preg_match('/^(https?|mailto|tel):/i', $url)) return true;

        if ($forImage) {
            if (preg_match('/^data:image\/(png|jpe?g|gif|webp);base64,[a-z0-9+\/=\s]+$/i', $url)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('learning_material_filter_class_tokens')) {
    function learning_material_filter_class_tokens($rawClass) {
        $rawClass = trim((string) $rawClass);
        if ($rawClass === '') return '';

        $tokens = preg_split('/\s+/', $rawClass);
        $keep = [];
        foreach ($tokens as $token) {
            $token = trim((string) $token);
            if ($token === '') continue;

            $allowed = false;
            if ($token === 'ql-syntax') $allowed = true;
            if (preg_match('/^ql-align-(right|center|justify)$/', $token)) $allowed = true;
            if (preg_match('/^ql-direction-(rtl|ltr)$/', $token)) $allowed = true;
            if (preg_match('/^ql-indent-[0-9]{1,2}$/', $token)) $allowed = true;
            if (preg_match('/^ql-size-(small|large|huge)$/', $token)) $allowed = true;
            if (preg_match('/^ql-font-[a-z0-9-]+$/', $token)) $allowed = true;

            if ($allowed) $keep[$token] = $token;
        }

        return implode(' ', array_values($keep));
    }
}

if (!function_exists('learning_material_sanitize_node')) {
    function learning_material_sanitize_node(DOMNode $node, array $allowedTags, array $allowedAttrs) {
        if ($node->nodeType === XML_COMMENT_NODE) {
            if ($node->parentNode) {
                $node->parentNode->removeChild($node);
            }
            return;
        }

        if ($node instanceof DOMElement) {
            $tag = strtolower((string) $node->tagName);
            if (!isset($allowedTags[$tag])) {
                $parent = $node->parentNode;
                if ($parent) {
                    while ($node->firstChild) {
                        $parent->insertBefore($node->firstChild, $node);
                    }
                    $parent->removeChild($node);
                }
                return;
            }

            $allowedForTag = $allowedAttrs[$tag] ?? [];
            $attrNames = [];
            if ($node->hasAttributes()) {
                foreach ($node->attributes as $attr) {
                    $attrNames[] = (string) $attr->name;
                }
            }

            foreach ($attrNames as $attrName) {
                $name = strtolower(trim($attrName));
                $value = (string) $node->getAttribute($attrName);

                if ($name === '' || strpos($name, 'on') === 0) {
                    $node->removeAttribute($attrName);
                    continue;
                }

                if (!in_array($name, $allowedForTag, true)) {
                    $node->removeAttribute($attrName);
                    continue;
                }

                if ($name === 'href') {
                    if (!learning_material_is_safe_url($value, false)) {
                        $node->removeAttribute($attrName);
                    } else {
                        $node->setAttribute('href', trim($value));
                    }
                    continue;
                }

                if ($name === 'src') {
                    if (!learning_material_is_safe_url($value, true)) {
                        $node->removeAttribute($attrName);
                    } else {
                        $node->setAttribute('src', trim($value));
                    }
                    continue;
                }

                if ($name === 'class') {
                    $safeClass = learning_material_filter_class_tokens($value);
                    if ($safeClass === '') {
                        $node->removeAttribute($attrName);
                    } else {
                        $node->setAttribute('class', $safeClass);
                    }
                    continue;
                }

                if ($name === 'target') {
                    $target = strtolower(trim($value));
                    if (!in_array($target, ['', '_self', '_blank'], true)) {
                        $node->removeAttribute($attrName);
                    } else {
                        $node->setAttribute('target', $target);
                    }
                    continue;
                }

                if (($name === 'width' || $name === 'height') && !preg_match('/^[0-9]{1,4}$/', trim($value))) {
                    $node->removeAttribute($attrName);
                    continue;
                }

                $node->setAttribute($attrName, trim((string) preg_replace('/\s+/', ' ', $value)));
            }

            if ($tag === 'a' && strtolower((string) $node->getAttribute('target')) === '_blank') {
                $node->setAttribute('rel', 'noopener noreferrer');
            }

            if ($tag === 'img' && !$node->hasAttribute('loading')) {
                $node->setAttribute('loading', 'lazy');
            }
        }

        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }
        foreach ($children as $child) {
            learning_material_sanitize_node($child, $allowedTags, $allowedAttrs);
        }
    }
}

if (!function_exists('learning_material_sanitize_html')) {
    function learning_material_sanitize_html($html) {
        $html = trim((string) $html);
        if ($html === '') return '';

        if (!class_exists('DOMDocument')) {
            $clean = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
            $clean = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', (string) $clean);
            $allowed = '<p><br><h1><h2><h3><h4><h5><h6><strong><b><em><i><u><s><a><ul><ol><li><blockquote><pre><code><img><hr><table><thead><tbody><tr><th><td><span><div>';
            $clean = strip_tags((string) $clean, $allowed);
            $clean = preg_replace('/\son[a-z]+\s*=\s*([\"\']).*?\1/i', '', (string) $clean);
            $clean = preg_replace('/\s(href|src)\s*=\s*([\"\'])\s*javascript:.*?\2/i', '', (string) $clean);
            return trim((string) $clean);
        }

        $allowedTags = [
            'p' => true,
            'br' => true,
            'h1' => true,
            'h2' => true,
            'h3' => true,
            'h4' => true,
            'h5' => true,
            'h6' => true,
            'strong' => true,
            'b' => true,
            'em' => true,
            'i' => true,
            'u' => true,
            's' => true,
            'a' => true,
            'ul' => true,
            'ol' => true,
            'li' => true,
            'blockquote' => true,
            'pre' => true,
            'code' => true,
            'img' => true,
            'hr' => true,
            'table' => true,
            'thead' => true,
            'tbody' => true,
            'tr' => true,
            'th' => true,
            'td' => true,
            'span' => true,
            'div' => true,
        ];

        $allowedAttrs = [
            'a' => ['href', 'target', 'rel', 'title'],
            'img' => ['src', 'alt', 'title', 'width', 'height', 'class'],
            'p' => ['class'],
            'h1' => ['class'],
            'h2' => ['class'],
            'h3' => ['class'],
            'h4' => ['class'],
            'h5' => ['class'],
            'h6' => ['class'],
            'ul' => ['class'],
            'ol' => ['class'],
            'li' => ['class'],
            'blockquote' => ['class'],
            'pre' => ['class'],
            'code' => ['class'],
            'table' => ['class'],
            'thead' => ['class'],
            'tbody' => ['class'],
            'tr' => ['class'],
            'th' => ['class'],
            'td' => ['class', 'colspan', 'rowspan'],
            'span' => ['class'],
            'div' => ['class'],
        ];

        $dom = new DOMDocument('1.0', 'UTF-8');
        $wrapped = '<!doctype html><html><body><div id="lm-root">' . $html . '</div></body></html>';

        $internalErrors = libxml_use_internal_errors(true);
        $loadOptions = 0;
        if (defined('LIBXML_HTML_NOIMPLIED')) $loadOptions |= LIBXML_HTML_NOIMPLIED;
        if (defined('LIBXML_HTML_NODEFDTD')) $loadOptions |= LIBXML_HTML_NODEFDTD;
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $wrapped, $loadOptions);

        if (!$loaded) {
            libxml_clear_errors();
            libxml_use_internal_errors($internalErrors);
            return '';
        }

        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//*[@id="lm-root"]');
        $root = ($nodes && $nodes->length > 0) ? $nodes->item(0) : null;
        if (!($root instanceof DOMNode)) {
            libxml_clear_errors();
            libxml_use_internal_errors($internalErrors);
            return '';
        }

        learning_material_sanitize_node($root, $allowedTags, $allowedAttrs);

        $safeHtml = '';
        foreach ($root->childNodes as $child) {
            $safeHtml .= $dom->saveHTML($child);
        }

        libxml_clear_errors();
        libxml_use_internal_errors($internalErrors);
        return trim((string) $safeHtml);
    }
}

if (!function_exists('learning_material_excerpt')) {
    function learning_material_excerpt($summary, $contentHtml, $maxLength = 220) {
        $maxLength = (int) $maxLength;
        if ($maxLength <= 0) $maxLength = 220;

        $summaryText = learning_material_plain_text($summary, $maxLength);
        if ($summaryText !== '') return $summaryText;
        return learning_material_plain_text($contentHtml, $maxLength);
    }
}

if (!function_exists('learning_material_ai_read_api_key')) {
    function learning_material_ai_read_api_key($envName, $path, $startsWith = '') {
        $env = function_exists('doc_ease_env_value')
            ? trim((string) doc_ease_env_value((string) $envName))
            : trim((string) getenv((string) $envName));
        if ($env === '') return '';
        if ($startsWith !== '' && strpos($env, $startsWith) !== 0) return '';
        return $env;
    }
}

if (!function_exists('learning_material_ai_openai_api_key')) {
    function learning_material_ai_openai_api_key() {
        return learning_material_ai_read_api_key('OPENAI_API_KEY', '', 'sk-');
    }
}

if (!function_exists('learning_material_ai_extract_json_object')) {
    function learning_material_ai_extract_json_object($content) {
        $content = trim((string) $content);
        if ($content === '') return null;

        $decoded = json_decode($content, true);
        if (is_array($decoded)) return $decoded;

        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/is', $content, $m)) {
            $decoded = json_decode((string) ($m[1] ?? ''), true);
            if (is_array($decoded)) return $decoded;
        }

        $a = strpos($content, '{');
        $b = strrpos($content, '}');
        if ($a !== false && $b !== false && $b > $a) {
            $decoded = json_decode((string) substr($content, $a, $b - $a + 1), true);
            if (is_array($decoded)) return $decoded;
        }

        return null;
    }
}

if (!function_exists('learning_material_ai_format_cost')) {
    function learning_material_ai_format_cost($title, $summary, $contentHtml) {
        $all = trim((string) $title . ' ' . (string) $summary . ' ' . learning_material_plain_text($contentHtml));
        if ($all === '') return 1.0;

        $chars = strlen($all);
        $cost = (float) ceil($chars / 1200);
        if ($cost < 1.0) $cost = 1.0;
        if ($cost > 10.0) $cost = 10.0;
        return round($cost, 2);
    }
}

if (!function_exists('learning_material_ai_timeout_seconds')) {
    function learning_material_ai_timeout_seconds($title, $summary, $contentHtml) {
        $size = strlen((string) $title) + strlen((string) $summary) + strlen((string) $contentHtml);
        $extraChunks = (int) floor($size / 4000);
        $timeout = 90 + ($extraChunks * 15);
        if ($timeout < 90) $timeout = 90;
        if ($timeout > 180) $timeout = 180;
        return $timeout;
    }
}

if (!function_exists('learning_material_ai_is_timeout_error')) {
    function learning_material_ai_is_timeout_error($curlErrNo, $curlErrMsg) {
        $curlErrNo = (int) $curlErrNo;
        $curlErrMsg = strtolower(trim((string) $curlErrMsg));
        if ($curlErrNo === 28) return true; // CURLE_OPERATION_TIMEDOUT
        return $curlErrMsg !== '' && strpos($curlErrMsg, 'timed out') !== false;
    }
}

if (!function_exists('learning_material_ai_error_message')) {
    function learning_material_ai_error_message($http, $curlErrNo, $curlErrMsg, $apiErrorMsg, $timeoutSec, $attemptsTried, $parseFailed) {
        $http = (int) $http;
        $curlErrNo = (int) $curlErrNo;
        $curlErrMsg = trim((string) $curlErrMsg);
        $apiErrorMsg = trim((string) $apiErrorMsg);
        $timeoutSec = (int) $timeoutSec;
        $attemptsTried = (int) $attemptsTried;
        $parseFailed = (bool) $parseFailed;
        $attemptLabel = $attemptsTried > 1 ? (' after ' . $attemptsTried . ' attempts') : '';

        if ($http === 401) {
            return 'AI authentication failed. Check OPENAI_API_KEY and try again.';
        }
        if ($http === 429) {
            return 'AI is temporarily rate-limited' . $attemptLabel . '. Please retry in 1-2 minutes or shorten the content.';
        }
        if ($http >= 500) {
            return 'AI service is temporarily unavailable' . $attemptLabel . '. Please retry in a few minutes.';
        }
        if (learning_material_ai_is_timeout_error($curlErrNo, $curlErrMsg)) {
            return 'AI formatting timed out around ' . $timeoutSec . 's' . $attemptLabel . '. Try shorter content, check internet/firewall, then retry.';
        }
        if ($curlErrNo !== 0 || $curlErrMsg !== '') {
            return 'AI request failed due to a network issue' . $attemptLabel . '. Check connection, firewall/proxy, and DNS, then retry.';
        }
        if ($parseFailed) {
            return 'AI returned an unexpected response format' . $attemptLabel . '. Please retry. If it persists, shorten content and try again.';
        }
        if ($http >= 400) {
            if ($apiErrorMsg !== '') {
                return 'AI request failed (HTTP ' . $http . '): ' . $apiErrorMsg;
            }
            return 'AI request failed (HTTP ' . $http . '). Please retry.';
        }

        return 'AI request failed. Please retry shortly.';
    }
}

if (!function_exists('learning_material_ai_polish_content')) {
    function learning_material_ai_polish_content($title, $summary, $contentHtml) {
        $title = learning_material_plain_text($title, 200);
        $summary = learning_material_plain_text($summary, 1200);
        $contentHtml = learning_material_sanitize_html($contentHtml);
        if ($contentHtml === '') return [false, 'Content is required.'];

        $apiKey = learning_material_ai_openai_api_key();
        if ($apiKey === '') return [false, 'Model 1 API key not configured.'];
        if (!function_exists('curl_init')) return [false, 'cURL extension is not available.'];

        $context = [
            'title' => $title,
            'summary' => $summary,
            'content_html' => $contentHtml,
            'requirements' => [
                'Keep factual meaning and technical intent.',
                'Improve indentation, spacing, grammar, and professional tone.',
                'Preserve code snippets and command examples.',
                'Use clear headings, short paragraphs, and clean list formatting.',
                'Do not add ads, sponsor text, or unrelated content.',
            ],
        ];

        $systemPrompt = "You are a professional instructional content editor for LMS learning materials. Rewrite HTML content to be clean, well-structured, and professionally edited while preserving meaning. Keep code blocks intact and readable. Return strict JSON only.";
        $userPrompt = "Return strict JSON with this exact shape:\n{\n  \"summary\": \"string\",\n  \"content_html\": \"string\"\n}\nRules:\n- Keep original technical meaning and sequence.\n- Improve formatting, spacing, indentation, and readability.\n- Keep important examples, steps, warnings, and code snippets.\n- Keep output as safe HTML (no script/style tags).\n- Do not include markdown fences.\n\nInput JSON:\n" . json_encode($context, JSON_UNESCAPED_SLASHES);

        $payload = [
            'model' => 'gpt-4o-mini',
            'temperature' => 0.2,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ];

        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($payloadJson) || $payloadJson === '') {
            return [false, 'Unable to prepare AI request payload.'];
        }

        $timeoutSec = learning_material_ai_timeout_seconds($title, $summary, $contentHtml);
        $maxAttempts = 3; // initial + up to 2 retries
        $backoffSeconds = [1, 2];

        $lastHttp = 0;
        $lastCurlErrNo = 0;
        $lastCurlErr = '';
        $lastApiError = '';
        $parseFailed = false;
        $attemptsTried = 0;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $attemptsTried = $attempt;
            $ch = curl_init('https://api.openai.com/v1/chat/completions');
            if (!$ch) {
                return [false, 'Unable to initialize AI request.'];
            }

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadJson);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSec);

            $resp = curl_exec($ch);
            $lastCurlErrNo = (int) curl_errno($ch);
            $lastCurlErr = (string) curl_error($ch);
            $lastHttp = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $lastApiError = '';
            $retryable = false;

            if ($resp === false) {
                $retryable = true;
            } else {
                $decoded = json_decode((string) $resp, true);
                if (is_array($decoded) && isset($decoded['error']['message'])) {
                    $lastApiError = learning_material_plain_text((string) $decoded['error']['message'], 240);
                }

                if ($lastHttp >= 400) {
                    if ($lastHttp === 429 || $lastHttp >= 500) {
                        $retryable = true;
                    } else {
                        return [false, learning_material_ai_error_message(
                            $lastHttp,
                            $lastCurlErrNo,
                            $lastCurlErr,
                            $lastApiError,
                            $timeoutSec,
                            $attemptsTried,
                            $parseFailed
                        )];
                    }
                } else {
                    $content = trim((string) ($decoded['choices'][0]['message']['content'] ?? ''));
                    if ($content === '') {
                        $parseFailed = true;
                        $retryable = true;
                    } else {
                        $json = learning_material_ai_extract_json_object($content);
                        if (!is_array($json)) {
                            $parseFailed = true;
                            $retryable = true;
                        } else {
                            $newSummary = learning_material_plain_text((string) ($json['summary'] ?? ''), 1200);
                            if ($newSummary === '') $newSummary = $summary;

                            $newHtmlRaw = (string) ($json['content_html'] ?? '');
                            $newHtml = learning_material_sanitize_html($newHtmlRaw);
                            if ($newHtml === '') {
                                $parseFailed = true;
                                $retryable = true;
                            } else {
                                return [true, [
                                    'summary' => $newSummary,
                                    'content_html' => $newHtml,
                                ]];
                            }
                        }
                    }
                }
            }

            if (!$retryable || $attempt >= $maxAttempts) {
                break;
            }

            $sleepIndex = $attempt - 1;
            $sleepSeconds = isset($backoffSeconds[$sleepIndex]) ? (int) $backoffSeconds[$sleepIndex] : 2;
            if ($sleepSeconds > 0) sleep($sleepSeconds);
        }

        return [false, learning_material_ai_error_message(
            $lastHttp,
            $lastCurlErrNo,
            $lastCurlErr,
            $lastApiError,
            $timeoutSec,
            $attemptsTried,
            $parseFailed
        )];
    }
}
