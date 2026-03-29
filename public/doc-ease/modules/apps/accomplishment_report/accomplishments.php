<?php
// Monthly Accomplishment helpers.

require_once __DIR__ . '/../../../includes/env_secrets.php';

if (!function_exists('ensure_accomplishment_tables')) {
    function ensure_accomplishment_tables(mysqli $conn) {
        $conn->query("CREATE TABLE IF NOT EXISTS accomplishment_entries (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            subject_label VARCHAR(255) NOT NULL DEFAULT '',
            entry_date DATE NOT NULL,
            title VARCHAR(255) NOT NULL,
            details TEXT NULL,
            remarks TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_acc_user_date (user_id, entry_date),
            KEY idx_acc_user_subject_date (user_id, subject_label, entry_date),
            CONSTRAINT fk_acc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $res = $conn->query("SHOW COLUMNS FROM accomplishment_entries LIKE 'subject_label'");
        $has = ($res instanceof mysqli_result) ? ($res->num_rows > 0) : false;
        if ($res instanceof mysqli_result) $res->close();
        if (!$has) $conn->query("ALTER TABLE accomplishment_entries ADD COLUMN subject_label VARCHAR(255) NOT NULL DEFAULT '' AFTER user_id");

        $res = $conn->query("SHOW COLUMNS FROM accomplishment_entries LIKE 'remarks'");
        $has = ($res instanceof mysqli_result) ? ($res->num_rows > 0) : false;
        if ($res instanceof mysqli_result) $res->close();
        if (!$has) $conn->query("ALTER TABLE accomplishment_entries ADD COLUMN remarks TEXT NULL AFTER details");

        $res = $conn->query("SHOW INDEX FROM accomplishment_entries WHERE Key_name='idx_acc_user_subject_date'");
        $has = ($res instanceof mysqli_result) ? ($res->num_rows > 0) : false;
        if ($res instanceof mysqli_result) $res->close();
        if (!$has) $conn->query("ALTER TABLE accomplishment_entries ADD KEY idx_acc_user_subject_date (user_id, subject_label, entry_date)");

        $conn->query("CREATE TABLE IF NOT EXISTS accomplishment_proofs (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            entry_id BIGINT NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            file_size BIGINT UNSIGNED NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_proof_entry (entry_id),
            CONSTRAINT fk_proof_entry FOREIGN KEY (entry_id) REFERENCES accomplishment_entries(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

if (!function_exists('acc_month_bounds')) {
    function acc_month_bounds($ym) {
        $ym = trim((string) $ym);
        if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = date('Y-m');
        $first = $ym . '-01';
        return [$first, date('Y-m-t', strtotime($first)), $ym];
    }
}

if (!function_exists('acc_collect_subject_labels')) {
    function acc_collect_subject_labels($raw) {
        $values = is_array($raw) ? $raw : (($raw === null) ? [] : [(string) $raw]);
        $out = [];
        $seen = [];
        foreach ($values as $v) {
            if (is_array($v) || is_object($v)) continue;
            $s = trim((string) preg_replace('/\s+/', ' ', trim((string) $v)));
            if ($s === '') continue;
            $k = strtolower($s);
            if (isset($seen[$k])) continue;
            $seen[$k] = true;
            $out[] = $s;
        }
        return $out;
    }
}

if (!function_exists('acc_stmt_bind_values')) {
    function acc_stmt_bind_values(mysqli_stmt $stmt, $types, array $values) {
        $types = (string) $types;
        if ($types === '') return true;
        if (strlen($types) !== count($values)) return false;
        $args = [$types];
        foreach ($values as $i => $v) $args[] = &$values[$i];
        return (bool) call_user_func_array([$stmt, 'bind_param'], $args);
    }
}

if (!function_exists('acc_user_subject_options')) {
    function acc_user_subject_options(mysqli $conn, $userId) {
        $userId = (int) $userId;
        if ($userId <= 0) return [];

        $labels = [];
        $seen = [];
        $add = function ($raw) use (&$labels, &$seen) {
            $label = trim((string) preg_replace('/\s+/', ' ', trim((string) $raw)));
            if ($label === '') return;
            $k = strtolower($label);
            if (isset($seen[$k])) return;
            $seen[$k] = true;
            $labels[] = $label;
        };

        $sql = "SELECT DISTINCT TRIM(COALESCE(s.subject_code,'')) AS subject_code, TRIM(COALESCE(s.subject_name,'')) AS subject_name
                FROM teacher_assignments ta
                JOIN class_records cr ON cr.id = ta.class_record_id
                JOIN subjects s ON s.id = cr.subject_id
                WHERE ta.teacher_id = ? AND ta.status='active' AND cr.status='active'
                ORDER BY subject_name ASC, subject_code ASC";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res && ($r = $res->fetch_assoc())) {
                $code = trim((string) ($r['subject_code'] ?? ''));
                $name = trim((string) ($r['subject_name'] ?? ''));
                if ($code !== '' && $name !== '') $add($code . ' - ' . $name);
                elseif ($name !== '') $add($name);
                elseif ($code !== '') $add($code);
            }
            $stmt->close();
        }

        $stmt = $conn->prepare("SELECT DISTINCT TRIM(COALESCE(subject_label,'')) AS subject_label
                                FROM accomplishment_entries
                                WHERE user_id = ? AND TRIM(COALESCE(subject_label,'')) <> ''
                                ORDER BY subject_label ASC");
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res && ($r = $res->fetch_assoc())) $add((string) ($r['subject_label'] ?? ''));
            $stmt->close();
        }

        return $labels;
    }
}

if (!function_exists('acc_list_month')) {
    function acc_list_month(mysqli $conn, $userId, $ym, $subjectLabel = '') {
        $userId = (int) $userId;
        [$first, $last, $ym] = acc_month_bounds($ym);
        $subjectLabels = acc_collect_subject_labels($subjectLabel);

        $sql = "SELECT e.id AS entry_id, e.subject_label, e.entry_date, e.title, e.details, e.remarks, e.created_at AS entry_created_at,
                       p.id AS proof_id, p.original_name, p.file_name, p.file_path, p.file_size, p.mime_type, p.created_at AS proof_created_at
                FROM accomplishment_entries e
                LEFT JOIN accomplishment_proofs p ON p.entry_id = e.id
                WHERE e.user_id = ? AND e.entry_date >= ? AND e.entry_date <= ?";
        $bind = [$userId, $first, $last];
        $types = 'iss';

        if (!empty($subjectLabels)) {
            $parts = [];
            foreach ($subjectLabels as $s) {
                $norm = strtolower(trim((string) preg_replace('/\s+/', ' ', $s)));
                if (in_array($norm, ['monthly accomplishment', 'monthly accomplishment report'], true)) {
                    $parts[] = "(LOWER(TRIM(COALESCE(e.subject_label,''))) = LOWER(TRIM(?)) OR TRIM(COALESCE(e.subject_label,''))='')";
                } else {
                    $parts[] = "LOWER(TRIM(COALESCE(e.subject_label,''))) = LOWER(TRIM(?))";
                }
                $bind[] = $s;
                $types .= 's';
            }
            $sql .= ' AND (' . implode(' OR ', $parts) . ')';
        }
        $sql .= " ORDER BY e.entry_date DESC, e.id DESC, p.id ASC";

        $rows = [];
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            if (acc_stmt_bind_values($stmt, $types, $bind)) {
                $stmt->execute();
                $res = $stmt->get_result();
                while ($res && ($r = $res->fetch_assoc())) $rows[] = $r;
            }
            $stmt->close();
        }

        $out = [];
        foreach ($rows as $r) {
            $eid = (int) ($r['entry_id'] ?? 0);
            if ($eid <= 0) continue;
            if (!isset($out[$eid])) {
                $out[$eid] = [
                    'id' => $eid,
                    'subject_label' => (string) ($r['subject_label'] ?? ''),
                    'entry_date' => (string) ($r['entry_date'] ?? ''),
                    'title' => (string) ($r['title'] ?? ''),
                    'details' => acc_normalize_text_value($r['details'] ?? ''),
                    'remarks' => acc_normalize_text_value($r['remarks'] ?? ''),
                    'created_at' => (string) ($r['entry_created_at'] ?? ''),
                    'proofs' => [],
                ];
            }
            $pid = (int) ($r['proof_id'] ?? 0);
            if ($pid > 0) {
                $out[$eid]['proofs'][] = [
                    'id' => $pid,
                    'original_name' => (string) ($r['original_name'] ?? ''),
                    'file_name' => (string) ($r['file_name'] ?? ''),
                    'file_path' => (string) ($r['file_path'] ?? ''),
                    'file_size' => (int) ($r['file_size'] ?? 0),
                    'mime_type' => (string) ($r['mime_type'] ?? ''),
                    'created_at' => (string) ($r['proof_created_at'] ?? ''),
                ];
            }
        }
        return array_values($out);
    }
}

if (!function_exists('acc_create_entry')) {
    function acc_create_entry(mysqli $conn, $userId, $entryDate, $title, $details = '', $subjectLabel = '', $remarks = '') {
        $userId = (int) $userId;
        $entryDate = trim((string) $entryDate);
        $title = trim((string) $title);
        $details = acc_normalize_text_value($details);
        $remarks = acc_normalize_text_value($remarks);
        $subjectLabel = trim((string) preg_replace('/\s+/', ' ', (string) $subjectLabel));
        if ($userId <= 0) return [false, 'Unauthorized.'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate)) return [false, 'Invalid date.'];
        if ($title === '') return [false, 'Title is required.'];
        if ($subjectLabel === '') $subjectLabel = 'Monthly Accomplishment';
        if (strlen($title) > 255) $title = substr($title, 0, 255);
        if (strlen($details) > 5000) $details = substr($details, 0, 5000);
        if (strlen($remarks) > 5000) $remarks = substr($remarks, 0, 5000);
        if (strlen($subjectLabel) > 255) $subjectLabel = substr($subjectLabel, 0, 255);

        $stmt = $conn->prepare("INSERT INTO accomplishment_entries (user_id, subject_label, entry_date, title, details, remarks) VALUES (?, ?, ?, ?, ?, ?)");
        if (!$stmt) return [false, 'Unable to create entry.'];
        $stmt->bind_param('isssss', $userId, $subjectLabel, $entryDate, $title, $details, $remarks);
        $ok = false;
        try { $ok = $stmt->execute(); } catch (Throwable $e) { $ok = false; }
        $id = $ok ? (int) $conn->insert_id : 0;
        $stmt->close();
        return $ok ? [true, $id] : [false, 'Unable to create entry.'];
    }
}

if (!function_exists('acc_allowed_image_mime_map')) {
    function acc_allowed_image_mime_map() {
        return ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    }
}

if (!function_exists('acc_add_proofs')) {
    function acc_add_proofs(mysqli $conn, $entryId, $userId, $files) {
        $entryId = (int) $entryId;
        $userId = (int) $userId;
        if ($entryId <= 0 || $userId <= 0) return [false, 'Invalid entry.'];
        if (!is_array($files) || !isset($files['name']) || !is_array($files['name'])) return [false, 'No files received.'];

        $entry = null;
        $stmt = $conn->prepare("SELECT id, user_id, entry_date FROM accomplishment_entries WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $entryId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows === 1) $entry = $res->fetch_assoc();
            $stmt->close();
        }
        if (!$entry) return [false, 'Entry not found.'];
        if ((int) ($entry['user_id'] ?? 0) !== $userId) return [false, 'Forbidden.'];

        $entryDate = (string) ($entry['entry_date'] ?? date('Y-m-d'));
        $month = preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate) ? substr($entryDate, 0, 7) : date('Y-m');
        $relDir = 'uploads/accomplishments/user_' . $userId . '/' . $month . '/entry_' . $entryId;
        $root = realpath(__DIR__ . '/../../..');
        if (!$root) return [false, 'Storage path unavailable.'];
        $absDir = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relDir);
        if (!is_dir($absDir) && !@mkdir($absDir, 0775, true) && !is_dir($absDir)) return [false, 'Unable to create upload directory.'];

        $allowed = acc_allowed_image_mime_map();
        $maxBytes = 5 * 1024 * 1024;
        $saved = 0;
        $errors = [];
        $n = count($files['name']);
        for ($i = 0; $i < $n; $i++) {
            $orig = trim((string) ($files['name'][$i] ?? ''));
            $tmp = (string) ($files['tmp_name'][$i] ?? '');
            $size = (int) ($files['size'][$i] ?? 0);
            $err = (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            $clientMime = trim((string) ($files['type'][$i] ?? ''));

            if ($err === UPLOAD_ERR_NO_FILE) continue;
            if ($err !== UPLOAD_ERR_OK) { $errors[] = ($orig !== '' ? $orig . ': ' : '') . 'Upload failed.'; continue; }
            if ($tmp === '' || !is_file($tmp)) { $errors[] = ($orig !== '' ? $orig . ': ' : '') . 'Upload temp file missing.'; continue; }
            if ($size <= 0 || $size > $maxBytes) { $errors[] = ($orig !== '' ? $orig . ': ' : '') . 'File must be 1 byte to 5MB.'; continue; }

            $mime = '';
            if (function_exists('finfo_open')) {
                $fi = @finfo_open(FILEINFO_MIME_TYPE);
                if ($fi) {
                    $mime = trim((string) @finfo_file($fi, $tmp));
                    @finfo_close($fi);
                }
            }
            if ($mime === '' && $clientMime !== '') $mime = $clientMime;
            if (!isset($allowed[$mime])) {
                $img = @getimagesize($tmp);
                if (is_array($img)) $mime = trim((string) ($img['mime'] ?? ''));
            }
            if (!isset($allowed[$mime])) { $errors[] = ($orig !== '' ? $orig . ': ' : '') . 'Only JPG/PNG/GIF/WEBP allowed.'; continue; }

            $ext = $allowed[$mime];
            $safeOrig = $orig !== '' ? $orig : ('proof.' . $ext);
            if (strlen($safeOrig) > 255) $safeOrig = substr($safeOrig, 0, 255);
            try { $suffix = bin2hex(random_bytes(6)); } catch (Throwable $e) { $suffix = substr(md5(uniqid('', true)), 0, 12); }
            $name = 'proof_' . date('Ymd_His') . '_' . $suffix . '.' . $ext;
            if (strlen($name) > 255) $name = substr($name, 0, 255);
            $relPath = $relDir . '/' . $name;
            $absPath = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relPath);

            $moved = false;
            if (function_exists('move_uploaded_file') && @is_uploaded_file($tmp)) $moved = @move_uploaded_file($tmp, $absPath);
            if (!$moved) $moved = @rename($tmp, $absPath);
            if (!$moved) { $errors[] = ($orig !== '' ? $orig . ': ' : '') . 'Unable to move file.'; continue; }

            $ins = $conn->prepare("INSERT INTO accomplishment_proofs (entry_id, original_name, file_name, file_path, file_size, mime_type) VALUES (?, ?, ?, ?, ?, ?)");
            if (!$ins) { @unlink($absPath); $errors[] = ($orig !== '' ? $orig . ': ' : '') . 'DB insert failed.'; continue; }
            $ins->bind_param('isssis', $entryId, $safeOrig, $name, $relPath, $size, $mime);
            $ok = false;
            try { $ok = $ins->execute(); } catch (Throwable $e) { $ok = false; }
            $ins->close();
            if (!$ok) { @unlink($absPath); $errors[] = ($orig !== '' ? $orig . ': ' : '') . 'DB insert failed.'; continue; }
            $saved++;
        }

        return [true, ['saved' => $saved, 'errors' => $errors]];
    }
}

if (!function_exists('acc_get_entry_for_user')) {
    function acc_get_entry_for_user(mysqli $conn, $entryId, $userId) {
        $entryId = (int) $entryId;
        $userId = (int) $userId;
        if ($entryId <= 0 || $userId <= 0) return [false, 'Invalid request.'];
        $stmt = $conn->prepare("SELECT id, user_id, subject_label, entry_date, title, details, remarks, created_at, updated_at FROM accomplishment_entries WHERE id = ? AND user_id = ? LIMIT 1");
        if (!$stmt) return [false, 'Unable to read entry.'];
        $stmt->bind_param('ii', $entryId, $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
        $stmt->close();
        return $row ? [true, $row] : [false, 'Entry not found.'];
    }
}

if (!function_exists('acc_list_entry_proofs_for_user')) {
    function acc_list_entry_proofs_for_user(mysqli $conn, $entryId, $userId) {
        $entryId = (int) $entryId;
        $userId = (int) $userId;
        if ($entryId <= 0 || $userId <= 0) return [false, 'Invalid request.'];
        $rows = [];
        $stmt = $conn->prepare("SELECT p.id, p.entry_id, p.original_name, p.file_name, p.file_path, p.file_size, p.mime_type, p.created_at
                                FROM accomplishment_proofs p
                                JOIN accomplishment_entries e ON e.id = p.entry_id
                                WHERE p.entry_id = ? AND e.user_id = ?
                                ORDER BY p.id ASC");
        if (!$stmt) return [false, 'Unable to list proofs.'];
        $stmt->bind_param('ii', $entryId, $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($r = $res->fetch_assoc())) {
            $rows[] = [
                'id' => (int) ($r['id'] ?? 0),
                'entry_id' => (int) ($r['entry_id'] ?? 0),
                'original_name' => (string) ($r['original_name'] ?? ''),
                'file_name' => (string) ($r['file_name'] ?? ''),
                'file_path' => (string) ($r['file_path'] ?? ''),
                'file_size' => (int) ($r['file_size'] ?? 0),
                'mime_type' => (string) ($r['mime_type'] ?? ''),
                'created_at' => (string) ($r['created_at'] ?? ''),
            ];
        }
        $stmt->close();
        return [true, $rows];
    }
}

if (!function_exists('acc_update_entry')) {
    function acc_update_entry(mysqli $conn, $entryId, $userId, $entryDate, $title, $details, $remarks = '') {
        $entryId = (int) $entryId;
        $userId = (int) $userId;
        $entryDate = trim((string) $entryDate);
        $title = trim((string) $title);
        $details = acc_normalize_text_value($details);
        $remarks = acc_normalize_text_value($remarks);
        if ($entryId <= 0 || $userId <= 0) return [false, 'Invalid request.'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate)) return [false, 'Invalid date.'];
        if ($title === '') return [false, 'Title cannot be empty.'];
        if (strlen($title) > 255) $title = substr($title, 0, 255);
        if (strlen($details) > 5000) $details = substr($details, 0, 5000);
        if (strlen($remarks) > 5000) $remarks = substr($remarks, 0, 5000);

        $stmt = $conn->prepare("UPDATE accomplishment_entries SET entry_date = ?, title = ?, details = ?, remarks = ? WHERE id = ? AND user_id = ? LIMIT 1");
        if (!$stmt) return [false, 'Unable to update entry.'];
        $stmt->bind_param('ssssii', $entryDate, $title, $details, $remarks, $entryId, $userId);
        $ok = false;
        try { $ok = $stmt->execute(); } catch (Throwable $e) { $ok = false; }
        $aff = $ok ? (int) $stmt->affected_rows : 0;
        $stmt->close();
        return $ok ? [true, ['affected_rows' => $aff]] : [false, 'Unable to update entry.'];
    }
}

if (!function_exists('acc_update_entry_subject')) {
    function acc_update_entry_subject(mysqli $conn, $entryId, $userId, $subjectLabel) {
        $entryId = (int) $entryId;
        $userId = (int) $userId;
        $subjectLabel = trim((string) preg_replace('/\s+/', ' ', (string) $subjectLabel));
        if ($entryId <= 0 || $userId <= 0) return [false, 'Invalid request.'];
        if ($subjectLabel === '') return [false, 'Subject is required.'];
        if (strlen($subjectLabel) > 255) $subjectLabel = substr($subjectLabel, 0, 255);

        $stmt = $conn->prepare("UPDATE accomplishment_entries SET subject_label = ? WHERE id = ? AND user_id = ? LIMIT 1");
        if (!$stmt) return [false, 'Unable to reassign subject.'];
        $stmt->bind_param('sii', $subjectLabel, $entryId, $userId);
        $ok = false;
        try { $ok = $stmt->execute(); } catch (Throwable $e) { $ok = false; }
        $aff = $ok ? (int) $stmt->affected_rows : 0;
        $stmt->close();
        return $ok ? [true, ['affected_rows' => $aff]] : [false, 'Unable to reassign subject.'];
    }
}

if (!function_exists('acc_update_entry_text')) {
    function acc_update_entry_text(mysqli $conn, $entryId, $userId, $title, $details, $remarks = '') {
        $entryId = (int) $entryId;
        $userId = (int) $userId;
        $title = trim((string) $title);
        $details = acc_normalize_text_value($details);
        $remarks = acc_normalize_text_value($remarks);
        if ($entryId <= 0 || $userId <= 0) return [false, 'Invalid request.'];
        if ($title === '') return [false, 'Title cannot be empty.'];
        if (strlen($title) > 255) $title = substr($title, 0, 255);
        if (strlen($details) > 5000) $details = substr($details, 0, 5000);
        if (strlen($remarks) > 5000) $remarks = substr($remarks, 0, 5000);

        $stmt = $conn->prepare("UPDATE accomplishment_entries SET title = ?, details = ?, remarks = ? WHERE id = ? AND user_id = ? LIMIT 1");
        if (!$stmt) return [false, 'Unable to update entry.'];
        $stmt->bind_param('sssii', $title, $details, $remarks, $entryId, $userId);
        $ok = false;
        try { $ok = $stmt->execute(); } catch (Throwable $e) { $ok = false; }
        $aff = $ok ? (int) $stmt->affected_rows : 0;
        $stmt->close();
        return $ok ? [true, ['affected_rows' => $aff]] : [false, 'Unable to update entry.'];
    }
}

if (!function_exists('acc_read_api_key')) {
    function acc_read_api_key($envName, $path, $startsWith = '') {
        $env = function_exists('doc_ease_env_value')
            ? trim((string) doc_ease_env_value((string) $envName))
            : trim((string) getenv((string) $envName));
        if ($env === '') return '';
        if ($startsWith !== '' && strpos($env, $startsWith) !== 0) return '';
        return $env;
    }
}

if (!function_exists('acc_openai_api_key')) {
    function acc_openai_api_key() { return acc_read_api_key('OPENAI_API_KEY', '', 'sk-'); }
}
if (!function_exists('acc_gemini_api_key')) {
    function acc_gemini_api_key() { return acc_read_api_key('GEMINI_API_KEY', '', 'AIza'); }
}

if (!function_exists('acc_ai_supported_providers')) {
    function acc_ai_supported_providers() { return ['openai', 'gemini']; }
}
if (!function_exists('acc_ai_normalize_provider')) {
    function acc_ai_normalize_provider($provider) {
        $p = strtolower(trim((string) $provider));
        return in_array($p, acc_ai_supported_providers(), true) ? $p : 'openai';
    }
}
if (!function_exists('acc_ai_provider_label')) {
    function acc_ai_provider_label($provider) { return acc_ai_normalize_provider($provider) === 'gemini' ? 'Model 2' : 'Model 1'; }
}
if (!function_exists('acc_ai_provider_has_key')) {
    function acc_ai_provider_has_key($provider) { return acc_ai_normalize_provider($provider) === 'gemini' ? (acc_gemini_api_key() !== '') : (acc_openai_api_key() !== ''); }
}

if (!function_exists('acc_text_from_mixed_value')) {
    function acc_text_from_mixed_value($value, $maxDepth = 4) {
        $maxDepth = (int) $maxDepth;
        if ($maxDepth < 0) return '';

        if (is_null($value)) return '';
        if (is_int($value) || is_float($value)) return trim((string) $value);
        if (is_bool($value)) return $value ? 'true' : 'false';

        if (is_string($value)) {
            $text = str_replace(["\r\n", "\r"], "\n", $value);
            $text = trim((string) $text);
            if ($text === '') return '';
            if (strcasecmp($text, 'array') === 0 || strcasecmp($text, 'object') === 0) return '';

            $len = strlen($text);
            if ($len >= 2) {
                $first = $text[0];
                $last = $text[$len - 1];
                if (($first === '{' && $last === '}') || ($first === '[' && $last === ']')) {
                    $decoded = json_decode($text, true);
                    if (is_array($decoded)) {
                        $decodedText = acc_text_from_mixed_value($decoded, $maxDepth - 1);
                        if ($decodedText !== '') return $decodedText;
                    }
                }
            }
            return $text;
        }

        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                $text = trim((string) $value);
                if ($text !== '' && strcasecmp($text, 'array') !== 0 && strcasecmp($text, 'object') !== 0) {
                    return $text;
                }
            }
            $value = (array) $value;
        }

        if (!is_array($value)) return '';
        if ($maxDepth === 0) return '';

        $priorityKeys = ['details', 'description', 'remarks', 'text', 'content', 'value', 'output', 'result', 'message', 'summary'];
        $parts = [];
        $seen = [];

        foreach ($priorityKeys as $key) {
            if (!array_key_exists($key, $value)) continue;
            $part = acc_text_from_mixed_value($value[$key], $maxDepth - 1);
            if ($part === '' || isset($seen[$part])) continue;
            $seen[$part] = true;
            $parts[] = $part;
        }

        if (count($parts) === 0) {
            foreach ($value as $item) {
                $part = acc_text_from_mixed_value($item, $maxDepth - 1);
                if ($part === '' || isset($seen[$part])) continue;
                $seen[$part] = true;
                $parts[] = $part;
            }
        }

        return trim(implode("\n", $parts));
    }
}
if (!function_exists('acc_normalize_text_value')) {
    function acc_normalize_text_value($value, $fallback = '') {
        $text = acc_text_from_mixed_value($value, 4);
        if ($text === '') $text = acc_text_from_mixed_value($fallback, 2);

        $text = str_replace(["\r\n", "\r"], "\n", (string) $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace("/\n{3,}/", "\n\n", (string) $text);
        $text = trim((string) $text);

        if (strcasecmp($text, 'array') === 0 || strcasecmp($text, 'object') === 0) {
            return '';
        }
        return $text;
    }
}

if (!function_exists('acc_normalize_remarks_status')) {
    function acc_normalize_remarks_status($v) {
        $s = strtolower(trim((string) $v));
        if ($s === '') return 'Accomplished';
        if (strpos($s, 'on-going') !== false || strpos($s, 'ongoing') !== false || strpos($s, 'on going') !== false || strpos($s, 'in progress') !== false || strpos($s, 'ongo') !== false) return 'On-going';
        return 'Accomplished';
    }
}
if (!function_exists('acc_extract_remarks_support_text')) {
    function acc_extract_remarks_support_text($v) {
        $t = acc_normalize_text_value($v);
        if ($t === '') return '';
        $t = trim((string) preg_replace('/\s+/', ' ', $t));
        $s = preg_replace('/^\s*(accomplished|on[- ]?going)\s*[:;\-]?\s*/iu', '', $t);
        if (!is_string($s)) $s = $t;
        return trim((string) $s, " \t\n\r\0\x0B-:;.,");
    }
}
if (!function_exists('acc_compose_remarks_with_support')) {
    function acc_compose_remarks_with_support($status, $supportText = '') {
        $status = acc_normalize_remarks_status($status);
        $supportText = trim((string) $supportText);
        return $supportText === '' ? $status : ($status . ' - ' . $supportText);
    }
}

if (!function_exists('acc_is_remarks_copy_of_details')) {
    function acc_is_remarks_copy_of_details($supportText, $details) {
        $a = strtolower(trim((string) preg_replace('/\\s+/', ' ', (string) $supportText)));
        $b = strtolower(trim((string) preg_replace('/\\s+/', ' ', (string) $details)));
        if ($a === '' || $b === '') return false;
        if ($a === $b) return true;

        // If one is basically a substring of the other, treat it as "copied".
        if (strpos($b, $a) !== false && strlen($a) >= 24) return true;
        if (strpos($a, $b) !== false && strlen($b) >= 24) return true;
        return false;
    }
}

if (!function_exists('acc_normalize_remarks_support_note')) {
    // Normalize "progress/deviation/pending" note. Keep it short and avoid copying the full details.
    function acc_normalize_remarks_support_note($supportText, $details = '', $status = 'Accomplished') {
        $supportText = acc_normalize_text_value($supportText);
        $supportText = trim((string) preg_replace('/\\s+/', ' ', (string) $supportText));
        $supportText = trim((string) $supportText, " \t\n\r\0\x0B-:;,."); // strip trailing punctuation

        // Remove leading status if model echoed it.
        $supportText = preg_replace('/^\\s*(accomplished|on[- ]?going)\\s*[:;\\-]\\s*/iu', '', (string) $supportText);
        $supportText = trim((string) $supportText, " \t\n\r\0\x0B-:;,."); 

        // Hard limit for reports (remarks column can wrap, but keep it compact).
        if (strlen($supportText) > 220) $supportText = rtrim(substr($supportText, 0, 217)) . '...';

        // If it looks like it just copied the details, fall back to a compact generic note.
        if (acc_is_remarks_copy_of_details($supportText, $details)) {
            $supportText = '';
        }

        if ($supportText === '') {
            $status = acc_normalize_remarks_status($status);
            return ($status === 'On-going')
                ? 'Achieved: partial progress; Pending: follow-up/completion.'
                : 'Achieved: completed as planned; Pending: none.';
        }

        return $supportText;
    }
}

if (!function_exists('acc_ai_extract_json_object')) {
    function acc_ai_extract_json_object($content) {
        $content = trim((string) $content);
        if ($content === '') return null;
        $j = json_decode($content, true);
        if (is_array($j)) return $j;
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/is', $content, $m)) {
            $j = json_decode((string) ($m[1] ?? ''), true);
            if (is_array($j)) return $j;
        }
        $a = strpos($content, '{');
        $b = strrpos($content, '}');
        if ($a !== false && $b !== false && $b > $a) {
            $j = json_decode(substr($content, $a, $b - $a + 1), true);
            if (is_array($j)) return $j;
        }
        return null;
    }
}

if (!function_exists('acc_ai_prepare_proof_images')) {
    function acc_ai_prepare_proof_images(array $proofs, $maxImages = 2, $maxBytesPerImage = 1600000) {
        $maxImages = max(0, min(5, (int) $maxImages));
        $maxBytesPerImage = max(200000, min(5000000, (int) $maxBytesPerImage));
        $allowed = acc_allowed_image_mime_map();
        $root = realpath(__DIR__ . '/../../..');
        if (!$root) return ['images' => [], 'total' => count($proofs), 'sent' => 0, 'skipped' => count($proofs)];

        $imgs = [];
        foreach ($proofs as $p) {
            if (count($imgs) >= $maxImages) break;
            if (!is_array($p)) continue;
            $rel = trim((string) ($p['file_path'] ?? ''));
            if ($rel === '') continue;
            $abs = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel);
            if (!is_file($abs)) continue;
            $size = (int) @filesize($abs);
            if ($size <= 0 || $size > $maxBytesPerImage) continue;
            $mime = trim((string) ($p['mime_type'] ?? ''));
            if (!isset($allowed[$mime])) {
                $gi = @getimagesize($abs);
                $mime = is_array($gi) ? trim((string) ($gi['mime'] ?? '')) : '';
            }
            if (!isset($allowed[$mime])) continue;
            $bin = @file_get_contents($abs);
            if (!is_string($bin) || $bin === '') continue;
            $imgs[] = [
                'label' => trim((string) ($p['original_name'] ?? basename($abs))),
                'mime_type' => $mime,
                'data_url' => 'data:' . $mime . ';base64,' . base64_encode($bin),
            ];
        }
        return ['images' => $imgs, 'total' => count($proofs), 'sent' => count($imgs), 'skipped' => max(0, count($proofs) - count($imgs))];
    }
}
if (!function_exists('acc_ai_contains_sensitive_access_intent')) {
    function acc_ai_contains_sensitive_access_intent($value) {
        $text = strtolower(trim((string) preg_replace('/\s+/', ' ', (string) $value)));
        if ($text === '') return false;

        $verbs = '(?:show|give|reveal|expose|dump|extract|read|list|display|send|share|access|open|provide|leak|steal|bypass|hack|crack|fetch|pull)';
        $targets = '(?:database|\bdb\b|schema|users?\s*table|credentials?|passwords?|api\s*keys?|tokens?|secrets?|server|filesystem|source\s*code|\.env|phpmyadmin|mysql)';

        if (preg_match('/\b' . $verbs . '\b[^\n\r]{0,90}\b' . $targets . '\b/i', $text)) return true;
        if (preg_match('/\b' . $targets . '\b[^\n\r]{0,90}\b' . $verbs . '\b/i', $text)) return true;
        return false;
    }
}
if (!function_exists('acc_ai_validate_topic_input')) {
    function acc_ai_validate_topic_input($value) {
        if (acc_ai_contains_sensitive_access_intent($value)) {
            return [false, 'Request blocked: this AI tool is limited to accomplishment-topic writing and cannot assist with database/system access, credentials, or internal secrets.'];
        }
        return [true, ''];
    }
}
if (!function_exists('acc_rephrase_entry_with_ai')) {
    function acc_rephrase_entry_with_ai($subjectLabel, $details, $remarks = '', array $proofs = [], $provider = 'openai') {
        if (function_exists('ai_access_can_use') && !ai_access_can_use()) {
            if (function_exists('ai_access_denied_message')) return [false, ai_access_denied_message()];
            return [false, 'AI features are restricted. Student/user accounts are not allowed to use AI.'];
        }

        $details = trim((string) $details);
        $remarks = trim((string) $remarks);
        $subjectLabel = trim((string) $subjectLabel);
        $provider = acc_ai_normalize_provider($provider);
        if ($details === '' && $remarks === '') return [false, 'Nothing to re-phrase.'];
        [$okTopic, $topicMessage] = acc_ai_validate_topic_input($subjectLabel . "\n" . $details . "\n" . $remarks);
        if (!$okTopic) return [false, $topicMessage];
        if (!function_exists('curl_init')) return [false, 'cURL extension is not available.'];
        if (!acc_ai_provider_has_key($provider)) return [false, acc_ai_provider_label($provider) . ' API key not configured.'];

        $status = acc_normalize_remarks_status($remarks);
        $existingSupport = acc_extract_remarks_support_text($remarks);
        $pack = acc_ai_prepare_proof_images($proofs, 2, 1600000);
        $proofImages = is_array($pack['images'] ?? null) ? $pack['images'] : [];

        $systemPrompt = "Rewrite only details and remarks for teacher accomplishments. Do not add facts. Keep dates and quantities unchanged. Use plain professional English and Bloom-style action verbs. For remarks: keep the remarks status exactly as Accomplished or On-going (provided), and write a short progress/deviation note that does NOT restate the details. Format remarks as: \"<STATUS> - Achieved: ...; Pending: ...\". Keep the note <= 18 words. If proof images are provided, read visible text and use only supported info. Stay strictly on accomplishment-entry content. Never provide database/schema/credential/API-key/server/filesystem/internal-system guidance. Return strict JSON with keys details and remarks.";
        $userPrompt = "Subject: " . ($subjectLabel !== '' ? $subjectLabel : 'N/A') . "\nDetails:\n" . ($details !== '' ? $details : '(empty)') . "\nRemarks status (must stay exactly this): " . $status . "\nExisting remarks support text (optional): " . ($existingSupport !== '' ? $existingSupport : '(empty)') . "\nProof images: " . (int) ($pack['sent'] ?? 0) . " sent of " . (int) ($pack['total'] ?? 0) . ".";

        $content = '';
        if ($provider === 'gemini') {
            $apiKey = acc_gemini_api_key();
            $parts = [['text' => $userPrompt]];
            foreach ($proofImages as $img) {
                $d = (string) ($img['data_url'] ?? '');
                $c = strpos($d, ',');
                if ($c === false) continue;
                $b64 = trim((string) substr($d, $c + 1));
                if ($b64 === '') continue;
                $parts[] = ['inlineData' => ['mimeType' => (string) ($img['mime_type'] ?? 'image/jpeg'), 'data' => $b64]];
            }
            $payload = [
                'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
                'contents' => [['role' => 'user', 'parts' => $parts]],
                'generationConfig' => ['temperature' => 0.3, 'responseMimeType' => 'application/json'],
            ];

            $decoded = null;
            $http = 0;
            $modelUsed = '';
            foreach (['gemini-2.5-flash', 'gemini-2.0-flash', 'gemini-flash-latest'] as $model) {
                $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);
                $ch = curl_init($url);
                if (!$ch) return [false, 'Unable to initialize AI request.'];
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
                curl_setopt($ch, CURLOPT_TIMEOUT, 40);
                $resp = curl_exec($ch);
                $err = curl_error($ch);
                $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($resp === false) return [false, 'AI request failed: ' . ($err !== '' ? $err : 'network error')];
                $decoded = json_decode((string) $resp, true);
                if ($http === 404) continue;
                $modelUsed = $model;
                break;
            }
            if ($modelUsed === '' && $http === 404) return [false, 'AI request failed (HTTP 404). Model 2 is not available for this API key.'];
            if ($http >= 400) {
                if ($http === 401 || $http === 403) return [false, 'AI authentication failed. Update GEMINI_API_KEY for Model 2.'];
                if ($http === 429) return [false, 'AI rate limit reached. Please try again in a moment.'];
                if ($http >= 500) return [false, 'AI service is temporarily unavailable. Please try again later.'];
                return [false, 'AI request failed (HTTP ' . $http . ').'];
            }
            $partsOut = $decoded['candidates'][0]['content']['parts'] ?? [];
            if (is_array($partsOut)) {
                foreach ($partsOut as $p) {
                    if (!is_array($p)) continue;
                    $t = trim((string) ($p['text'] ?? ''));
                    if ($t === '') continue;
                    $content .= ($content === '' ? '' : "\n") . $t;
                }
            }
        } else {
            $apiKey = acc_openai_api_key();
            $userContent = [['type' => 'text', 'text' => $userPrompt]];
            foreach ($proofImages as $img) {
                $url = (string) ($img['data_url'] ?? '');
                if ($url === '') continue;
                $userContent[] = ['type' => 'image_url', 'image_url' => ['url' => $url, 'detail' => 'high']];
            }
            $payload = [
                'model' => 'gpt-4o-mini',
                'temperature' => 0.3,
                'response_format' => ['type' => 'json_object'],
                'messages' => [['role' => 'system', 'content' => $systemPrompt], ['role' => 'user', 'content' => $userContent]],
            ];
            $ch = curl_init('https://api.openai.com/v1/chat/completions');
            if (!$ch) return [false, 'Unable to initialize AI request.'];
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_TIMEOUT, 40);
            $resp = curl_exec($ch);
            $err = curl_error($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($resp === false) return [false, 'AI request failed: ' . ($err !== '' ? $err : 'network error')];
            $decoded = json_decode((string) $resp, true);
            if ($http >= 400) {
                if ($http === 401) return [false, 'AI authentication failed. Update OPENAI_API_KEY for Model 1.'];
                if ($http === 429) return [false, 'AI rate limit reached. Please try again in a moment.'];
                if ($http >= 500) return [false, 'AI service is temporarily unavailable. Please try again later.'];
                return [false, 'AI request failed (HTTP ' . $http . ').'];
            }
            $content = trim((string) ($decoded['choices'][0]['message']['content'] ?? ''));
        }

        if ($content === '') return [false, 'AI returned an empty response.'];
        $json = acc_ai_extract_json_object($content);
        if (!is_array($json)) return [false, 'AI response format was invalid.'];

        $oldDetailsNormalized = acc_normalize_text_value($details);
        $newDetails = acc_normalize_text_value($json['details'] ?? '', $oldDetailsNormalized);
        $newRemarksRaw = acc_normalize_text_value($json['remarks'] ?? '');
        if ($newDetails === '') $newDetails = $oldDetailsNormalized;

        $support = acc_extract_remarks_support_text($newRemarksRaw);
        if ($support === '') $support = $existingSupport;
        $support = acc_normalize_remarks_support_note($support, $newDetails, $status);
        $newRemarks = acc_compose_remarks_with_support($status, $support);
        if (strlen($newDetails) > 5000) $newDetails = substr($newDetails, 0, 5000);
        if (strlen($newRemarks) > 5000) $newRemarks = substr($newRemarks, 0, 5000);

        return [true, ['details' => $newDetails, 'remarks' => $newRemarks]];
    }
}

if (!function_exists('acc_ai_creator_style_options')) {
    function acc_ai_creator_style_options() {
        return ['concise' => 'Concise', 'balanced' => 'Balanced', 'detailed' => 'Detailed', 'exaggerate' => 'Exaggerate'];
    }
}
if (!function_exists('acc_ai_creator_normalize_style_hint')) {
    function acc_ai_creator_normalize_style_hint($styleHint) {
        $s = strtolower(trim((string) $styleHint));
        $opts = acc_ai_creator_style_options();
        return isset($opts[$s]) ? $s : 'balanced';
    }
}

if (!function_exists('acc_ai_creator_default_clarifying_questions')) {
    function acc_ai_creator_default_clarifying_questions() {
        return [
            'What specific learner outcome did you target for this session?',
            'What concrete classroom or laboratory tasks were actually completed?',
            'What evidence of student progress or participation did you observe?',
            'What constraints or issues occurred, and how did you address them?',
            'What follow-up action should be reflected in the next entry?',
        ];
    }
}

if (!function_exists('acc_ai_creator_normalize_clarifying_questions')) {
    function acc_ai_creator_normalize_clarifying_questions(array $rawQuestions, $maxQuestions = 5) {
        $maxQuestions = (int) $maxQuestions;
        if ($maxQuestions < 1) $maxQuestions = 1;
        if ($maxQuestions > 5) $maxQuestions = 5;

        $normalized = [];
        $seen = [];
        foreach ($rawQuestions as $raw) {
            $q = '';
            if (is_string($raw)) {
                $q = $raw;
            } elseif (is_array($raw)) {
                $q = (string) ($raw['question'] ?? ($raw['text'] ?? ''));
            }
            $q = trim((string) preg_replace('/\s+/', ' ', $q));
            $q = preg_replace('/^\s*(?:[-*]+|\d+[.)])\s*/', '', (string) $q);
            $q = trim((string) $q, " \t\n\r\0\x0B-:;");
            if ($q === '') continue;
            if (strlen($q) > 220) $q = rtrim(substr($q, 0, 217)) . '...';
            if (!preg_match('/[?.!]$/', $q)) $q .= '?';
            $key = strtolower($q);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $normalized[] = $q;
            if (count($normalized) >= $maxQuestions) break;
        }

        if (count($normalized) === 0) {
            $fallback = array_slice(acc_ai_creator_default_clarifying_questions(), 0, $maxQuestions);
            foreach ($fallback as $q) {
                $q = trim((string) $q);
                if ($q === '') continue;
                $normalized[] = $q;
            }
        }

        return $normalized;
    }
}

if (!function_exists('acc_ai_creator_normalize_clarifying_qas')) {
    function acc_ai_creator_normalize_clarifying_qas(array $rawQas, $maxItems = 5) {
        $maxItems = (int) $maxItems;
        if ($maxItems < 1) $maxItems = 1;
        if ($maxItems > 5) $maxItems = 5;

        $out = [];
        $seen = [];
        foreach ($rawQas as $row) {
            if (!is_array($row)) continue;
            $q = trim((string) preg_replace('/\s+/', ' ', (string) ($row['question'] ?? '')));
            $a = trim((string) preg_replace('/\s+/', ' ', (string) ($row['answer'] ?? '')));
            if ($q === '' || $a === '') continue;
            $q = preg_replace('/^\s*(?:[-*]+|\d+[.)])\s*/', '', (string) $q);
            $q = trim((string) $q, " \t\n\r\0\x0B-:;");
            if ($q === '') continue;
            if (strlen($q) > 220) $q = rtrim(substr($q, 0, 217)) . '...';
            if (strlen($a) > 800) $a = rtrim(substr($a, 0, 797)) . '...';
            if (!preg_match('/[?.!]$/', $q)) $q .= '?';
            $key = strtolower($q);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = ['question' => $q, 'answer' => $a];
            if (count($out) >= $maxItems) break;
        }
        return $out;
    }
}

if (!function_exists('acc_generate_creator_clarifying_questions_with_ai')) {
    function acc_generate_creator_clarifying_questions_with_ai($subjectLabel, $context, array $dates, $styleHint = '', $maxQuestions = 5) {
        if (function_exists('ai_access_can_use') && !ai_access_can_use()) {
            if (function_exists('ai_access_denied_message')) return [false, ai_access_denied_message()];
            return [false, 'AI features are restricted. Student/user accounts are not allowed to use AI.'];
        }

        $subjectLabel = trim((string) $subjectLabel);
        $context = trim((string) $context);
        $styleHint = acc_ai_creator_normalize_style_hint($styleHint);
        $maxQuestions = (int) $maxQuestions;
        if ($maxQuestions < 1) $maxQuestions = 1;
        if ($maxQuestions > 5) $maxQuestions = 5;

        $unique = [];
        $seenDate = [];
        foreach ($dates as $d) {
            $d = trim((string) $d);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) continue;
            if (isset($seenDate[$d])) continue;
            $seenDate[$d] = true;
            $unique[] = $d;
        }
        sort($unique);

        if (count($unique) === 0) return [false, 'Select at least one valid date.'];
        if ($context === '') return [false, 'Please enter activity/context input.'];
        [$okTopic, $topicMessage] = acc_ai_validate_topic_input($subjectLabel . "\n" . $context);
        if (!$okTopic) return [false, $topicMessage];
        if (strlen($subjectLabel) > 255) $subjectLabel = substr($subjectLabel, 0, 255);
        if (strlen($context) > 5000) $context = substr($context, 0, 5000);

        $fallback = acc_ai_creator_normalize_clarifying_questions(
            acc_ai_creator_default_clarifying_questions(),
            $maxQuestions
        );

        $apiKey = acc_openai_api_key();
        if ($apiKey === '' || !function_exists('curl_init')) {
            return [true, $fallback];
        }

        $sys = "You are a Doctor of Education supporting a teacher. Ask concise clarification questions that will materially improve the quality and factual grounding of generated accomplishment entries. Ask practical questions tied to instruction, evidence, outcomes, constraints, and follow-up actions. Avoid repetitive or generic wording. Stay strictly on accomplishment context and never provide database/schema/credential/API-key/server/filesystem/internal-system guidance. Return strict JSON only: {\"questions\":[\"...\"]}.";
        $usr = "Generate up to " . (int) $maxQuestions . " clarifying questions.\nSubject: " . ($subjectLabel !== '' ? $subjectLabel : 'N/A') . "\nContext:\n" . $context . "\nStyle hint: " . $styleHint . "\nDates to generate for:\n- " . implode("\n- ", $unique);
        $payload = [
            'model' => 'gpt-4o-mini',
            'temperature' => 0.35,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => $sys],
                ['role' => 'user', 'content' => $usr],
            ],
        ];

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        if (!$ch) return [true, $fallback];
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 40);
        $resp = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false || $http >= 400) return [true, $fallback];

        $decoded = json_decode((string) $resp, true);
        $content = trim((string) ($decoded['choices'][0]['message']['content'] ?? ''));
        if ($content === '') return [true, $fallback];

        $json = acc_ai_extract_json_object($content);
        if (!is_array($json)) return [true, $fallback];

        $candidateQuestions = [];
        if (isset($json['questions']) && is_array($json['questions'])) {
            $candidateQuestions = $json['questions'];
        } elseif (isset($json['items']) && is_array($json['items'])) {
            foreach ($json['items'] as $row) {
                if (!is_array($row)) continue;
                $q = (string) ($row['question'] ?? ($row['text'] ?? ''));
                if ($q !== '') $candidateQuestions[] = $q;
            }
        }

        $normalized = acc_ai_creator_normalize_clarifying_questions($candidateQuestions, $maxQuestions);
        return [true, $normalized];
    }
}

if (!function_exists('acc_ai_normalize_exaggerated_description')) {
    function acc_ai_normalize_exaggerated_description($description) {
        $source = trim((string) str_replace("\r\n", "\n", (string) $description));
        if ($source === '') return '';
        $source = trim((string) preg_replace('/[ \t]+/', ' ', $source));

        $segments = [];
        $push = function ($text) use (&$segments) {
            $text = trim((string) $text);
            if ($text === '') return;
            $text = preg_replace('/^\s*(?:[-*]+|\d+[.)])\s*/', '', $text);
            $text = trim((string) $text, " \t\n\r\0\x0B-:;");
            if ($text === '') return;
            if (!preg_match('/[.!?]$/', $text)) $text .= '.';
            $segments[] = $text;
        };

        $lines = preg_split('/\n+/', $source);
        if (is_array($lines)) foreach ($lines as $line) $push($line);
        if (count($segments) < 3) {
            $sentences = preg_split('/(?<=[.!?])\s+/', preg_replace('/\s+/', ' ', $source));
            if (is_array($sentences)) foreach ($sentences as $s) $push($s);
        }
        if (count($segments) < 3) {
            $clauses = preg_split('/\s*[;,]\s+/', preg_replace('/\s+/', ' ', $source));
            if (is_array($clauses)) foreach ($clauses as $c) $push($c);
        }
        while (count($segments) < 3) {
            if (count($segments) === 0) $segments[] = 'Completed the activity based on the provided context.';
            elseif (count($segments) === 1) $segments[] = 'Continued the same workflow with additional process-level details.';
            else $segments[] = 'Concluded the sequence by reinforcing outputs and follow-through steps.';
        }
        if (count($segments) > 5) $segments = array_slice($segments, 0, 5);

        $bullets = [];
        foreach ($segments as $s) $bullets[] = '- ' . $s;
        return implode("\n", $bullets);
    }
}

if (!function_exists('acc_generate_descriptions_with_ai')) {
    function acc_generate_descriptions_with_ai($subjectLabel, $context, array $dates, $styleHint = '', array $clarifyingQas = []) {
        if (function_exists('ai_access_can_use') && !ai_access_can_use()) {
            if (function_exists('ai_access_denied_message')) return [false, ai_access_denied_message()];
            return [false, 'AI features are restricted. Student/user accounts are not allowed to use AI.'];
        }

        $subjectLabel = trim((string) $subjectLabel);
        $context = trim((string) $context);
        $styleHint = acc_ai_creator_normalize_style_hint($styleHint);
        $clarifyingQas = acc_ai_creator_normalize_clarifying_qas($clarifyingQas, 5);

        $unique = [];
        $seen = [];
        foreach ($dates as $d) {
            $d = trim((string) $d);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) continue;
            if (isset($seen[$d])) continue;
            $seen[$d] = true;
            $unique[] = $d;
        }
        sort($unique);

        if (count($unique) === 0) return [false, 'Select at least one valid date.'];
        if (count($unique) > 31) return [false, 'You can generate up to 31 dates per request.'];
        if ($context === '') return [false, 'Please enter activity/context input.'];
        $topicSource = $subjectLabel . "\n" . $context . "\n" . json_encode($clarifyingQas, JSON_UNESCAPED_SLASHES);
        [$okTopic, $topicMessage] = acc_ai_validate_topic_input($topicSource);
        if (!$okTopic) return [false, $topicMessage];
        if (strlen($subjectLabel) > 255) $subjectLabel = substr($subjectLabel, 0, 255);
        if (strlen($context) > 5000) $context = substr($context, 0, 5000);

        $apiKey = acc_openai_api_key();
        if ($apiKey === '') return [false, 'Model 1 API key not configured.'];
        if (!function_exists('curl_init')) return [false, 'cURL extension is not available.'];

        $styleRules = [
            'concise' => 'Style instruction: concise (1-2 short sentences).',
            'balanced' => 'Style instruction: balanced (2-3 sentences).',
            'detailed' => 'Style instruction: detailed (3-4 sentences with clearer steps).',
            'exaggerate' => "Style instruction: exaggerate. Use approximately 3x the detail of detailed style. Description must be multi-line bullets using '- ' with at least 3 bullets. Bullets must continue the sequence, not synonym/paraphrase duplicates.",
        ];
        $sys = "You create accomplishment descriptions for dated report entries. Generate exactly one description per date. Keep content factual to provided context and do not invent major facts. Use Bloom-style measurable verbs. Set type to exactly one of Lecture, Laboratory, Lecture & Laboratory. For remarks: output a compact progress/deviation note, not a copy of the description. Format remarks as: \"Accomplished - Achieved: ...; Pending: ...\" or \"On-going - Achieved: ...; Pending: ...\". Keep the Achieved/Pending note <= 18 words total. Use teacher clarifying answers if provided, and do not contradict them. Stay strictly on accomplishment context and never provide database/schema/credential/API-key/server/filesystem/internal-system guidance. " . ($styleRules[$styleHint] ?? $styleRules['balanced']) . " Return strict JSON: {\"items\":[{\"date\":\"YYYY-MM-DD\",\"type\":\"Lecture|Laboratory|Lecture & Laboratory\",\"description\":\"...\",\"remarks\":\"<STATUS> - Achieved: ...; Pending: ...\"}]}";

        $clarifyingBlock = '';
        if (count($clarifyingQas) > 0) {
            $lines = [];
            foreach ($clarifyingQas as $idx => $qa) {
                if (!is_array($qa)) continue;
                $q = trim((string) ($qa['question'] ?? ''));
                $a = trim((string) ($qa['answer'] ?? ''));
                if ($q === '' || $a === '') continue;
                $lines[] = ($idx + 1) . '. Q: ' . $q . "\n   A: " . $a;
            }
            if (count($lines) > 0) {
                $clarifyingBlock = "\nClarifying answers from teacher:\n" . implode("\n", $lines);
            }
        }

        $usr = "Subject: " . ($subjectLabel !== '' ? $subjectLabel : 'N/A') . "\nContext:\n" . $context . "\nStyle hint: " . $styleHint . "\nDates (must all be present in output):\n- " . implode("\n- ", $unique) . $clarifyingBlock;

        $payload = [
            'model' => 'gpt-4o-mini',
            'temperature' => 0.4,
            'response_format' => ['type' => 'json_object'],
            'messages' => [['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $usr]],
        ];
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        if (!$ch) return [false, 'Unable to initialize AI request.'];
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false) return [false, 'AI request failed: ' . ($err !== '' ? $err : 'network error')];

        $decoded = json_decode((string) $resp, true);
        if ($http >= 400) {
            if ($http === 401) return [false, 'AI authentication failed. Update OPENAI_API_KEY for Model 1.'];
            if ($http === 429) return [false, 'AI rate limit reached. Please try again in a moment.'];
            if ($http >= 500) return [false, 'AI service is temporarily unavailable. Please try again later.'];
            return [false, 'AI request failed (HTTP ' . $http . ').'];
        }

        $content = trim((string) ($decoded['choices'][0]['message']['content'] ?? ''));
        if ($content === '') return [false, 'AI returned an empty response.'];
        $json = acc_ai_extract_json_object($content);
        if (!is_array($json)) return [false, 'AI response format was invalid.'];
        $items = isset($json['items']) && is_array($json['items']) ? $json['items'] : [];

        $normType = function ($value, $description = '') {
            $raw = strtolower(trim((string) $value));
            $desc = strtolower(trim((string) $description));
            $c = $raw . ' ' . $desc;
            $hasLecture = strpos($c, 'lecture') !== false;
            $hasLab = (strpos($c, 'laboratory') !== false || strpos($c, 'lab ') !== false || preg_match('/\blab\b/', $c));
            if (strpos($raw, 'both') !== false || ($hasLecture && $hasLab)) return 'Lecture & Laboratory';
            if ($hasLab) return 'Laboratory';
            return 'Lecture';
        };
        $normRemarksStatus = function ($value) {
            return acc_normalize_remarks_status($value);
        };
        $normRemarksFull = function ($value, $description) use ($normRemarksStatus) {
            $status = $normRemarksStatus($value);
            $support = acc_extract_remarks_support_text($value);
            $support = acc_normalize_remarks_support_note($support, $description, $status);
            return acc_compose_remarks_with_support($status, $support);
        };

        $byDate = [];
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $date = trim((string) ($it['date'] ?? ''));
            $desc = acc_normalize_text_value($it['description'] ?? ($it['details'] ?? ''));
            $type = trim((string) ($it['type'] ?? ($it['title'] ?? '')));
            $remarksRaw = acc_normalize_text_value($it['remarks'] ?? ($it['status'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
            if (!isset($seen[$date])) continue;
            if ($styleHint === 'exaggerate') $desc = acc_ai_normalize_exaggerated_description($desc);
            if ($desc === '') continue;
            if (strlen($desc) > 5000) $desc = substr($desc, 0, 5000);
            $byDate[$date] = ['type' => $normType($type, $desc), 'description' => $desc, 'remarks' => $normRemarksFull($remarksRaw, $desc)];
        }

        $rows = [];
        foreach ($unique as $date) {
            if (!isset($byDate[$date]) || !is_array($byDate[$date])) return [false, 'AI returned incomplete results. Please try again.'];
            $row = $byDate[$date];
            $desc = acc_normalize_text_value($row['description'] ?? '');
            if ($desc === '') return [false, 'AI returned incomplete results. Please try again.'];
            $rows[] = [
                'date' => $date,
                'type' => (string) ($row['type'] ?? 'Lecture'),
                'description' => $desc,
                'remarks' => acc_normalize_text_value($row['remarks'] ?? 'Accomplished', 'Accomplished'),
            ];
        }

        return count($rows) > 0 ? [true, $rows] : [false, 'AI did not return usable descriptions.'];
    }
}

if (!function_exists('acc_delete_proof')) {
    function acc_delete_proof(mysqli $conn, $proofId, $userId) {
        $proofId = (int) $proofId;
        $userId = (int) $userId;
        if ($proofId <= 0 || $userId <= 0) return [false, 'Invalid request.'];

        $row = null;
        $stmt = $conn->prepare("SELECT p.id, p.file_path, e.user_id FROM accomplishment_proofs p JOIN accomplishment_entries e ON e.id = p.entry_id WHERE p.id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $proofId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows === 1) $row = $res->fetch_assoc();
            $stmt->close();
        }
        if (!$row) return [false, 'Proof not found.'];
        if ((int) ($row['user_id'] ?? 0) !== $userId) return [false, 'Forbidden.'];

        $root = realpath(__DIR__ . '/../../..');
        $rel = (string) ($row['file_path'] ?? '');
        if ($root && $rel !== '') {
            $abs = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel);
            if (is_file($abs)) @unlink($abs);
        }

        $del = $conn->prepare("DELETE FROM accomplishment_proofs WHERE id = ? LIMIT 1");
        if (!$del) return [false, 'Unable to delete.'];
        $del->bind_param('i', $proofId);
        $ok = false;
        try { $ok = $del->execute(); } catch (Throwable $e) { $ok = false; }
        $del->close();
        return $ok ? [true, 'Proof deleted.'] : [false, 'Unable to delete.'];
    }
}

if (!function_exists('acc_delete_entry')) {
    function acc_delete_entry(mysqli $conn, $entryId, $userId) {
        $entryId = (int) $entryId;
        $userId = (int) $userId;
        if ($entryId <= 0 || $userId <= 0) return [false, 'Invalid request.'];

        $paths = [];
        $stmt = $conn->prepare("SELECT id FROM accomplishment_entries WHERE id = ? AND user_id = ? LIMIT 1");
        if (!$stmt) return [false, 'Unable to delete.'];
        $stmt->bind_param('ii', $entryId, $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $okOwner = ($res && $res->num_rows === 1);
        $stmt->close();
        if (!$okOwner) return [false, 'Not found (or forbidden).'];

        $p = $conn->prepare("SELECT file_path FROM accomplishment_proofs WHERE entry_id = ?");
        if ($p) {
            $p->bind_param('i', $entryId);
            $p->execute();
            $res = $p->get_result();
            while ($res && ($r = $res->fetch_assoc())) {
                $rel = (string) ($r['file_path'] ?? '');
                if ($rel !== '') $paths[] = $rel;
            }
            $p->close();
        }

        $root = realpath(__DIR__ . '/../../..');
        if ($root) {
            foreach ($paths as $rel) {
                $abs = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel);
                if (is_file($abs)) @unlink($abs);
            }
        }

        $del = $conn->prepare("DELETE FROM accomplishment_entries WHERE id = ? AND user_id = ? LIMIT 1");
        if (!$del) return [false, 'Unable to delete.'];
        $del->bind_param('ii', $entryId, $userId);
        $ok = false;
        try { $ok = $del->execute(); } catch (Throwable $e) { $ok = false; }
        $del->close();
        return $ok ? [true, 'Entry deleted.'] : [false, 'Unable to delete.'];
    }
}
