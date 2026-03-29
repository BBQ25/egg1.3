<?php
// Attendance attachment helpers:
// - Schema bootstrap
// - Attendance component gating checks
// - Secure image upload handling
// - AI image-to-description utility

require_once __DIR__ . '/env_secrets.php';

if (!function_exists('attendance_db_has_column')) {
    function attendance_db_has_column(mysqli $conn, $table, $column) {
        $table = trim((string) $table);
        $column = trim((string) $column);
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

if (!function_exists('attendance_ensure_settings_table')) {
    function attendance_ensure_settings_table(mysqli $conn) {
        $conn->query(
            "CREATE TABLE IF NOT EXISTS app_settings (
                setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
                setting_value VARCHAR(255) NOT NULL DEFAULT '',
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $defaultEnabled = '1';
        $stmt = $conn->prepare(
            "INSERT IGNORE INTO app_settings (setting_key, setting_value)
             VALUES ('attendance_ai_transcribe_enabled', ?)"
        );
        if ($stmt) {
            $stmt->bind_param('s', $defaultEnabled);
            try { $stmt->execute(); } catch (Throwable $e) { /* ignore */ }
            $stmt->close();
        }
    }
}

if (!function_exists('attendance_ai_transcribe_is_enabled')) {
    function attendance_ai_transcribe_is_enabled(mysqli $conn) {
        attendance_ensure_settings_table($conn);
        $stmt = $conn->prepare(
            "SELECT setting_value
             FROM app_settings
             WHERE setting_key = 'attendance_ai_transcribe_enabled'
             LIMIT 1"
        );
        if (!$stmt) return true;
        $stmt->execute();
        $res = $stmt->get_result();
        $value = '1';
        if ($res && $res->num_rows === 1) {
            $row = $res->fetch_assoc();
            $value = trim((string) ($row['setting_value'] ?? '1'));
        }
        $stmt->close();
        return $value !== '0';
    }
}

if (!function_exists('attendance_save_ai_transcribe_enabled')) {
    function attendance_save_ai_transcribe_enabled(mysqli $conn, $enabled) {
        attendance_ensure_settings_table($conn);
        $value = $enabled ? '1' : '0';
        $stmt = $conn->prepare(
            "INSERT INTO app_settings (setting_key, setting_value)
             VALUES ('attendance_ai_transcribe_enabled', ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        if (!$stmt) return false;
        $stmt->bind_param('s', $value);
        $ok = false;
        try { $ok = $stmt->execute(); } catch (Throwable $e) { $ok = false; }
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('attendance_text_length')) {
    function attendance_text_length($text) {
        $text = (string) $text;
        if (function_exists('mb_strlen')) return (int) mb_strlen($text, 'UTF-8');
        return (int) strlen($text);
    }
}

if (!function_exists('attendance_ensure_tables')) {
    function attendance_ensure_tables(mysqli $conn) {
        attendance_ensure_settings_table($conn);
        $conn->query(
            "CREATE TABLE IF NOT EXISTS attendance_attachments (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                class_record_id INT NOT NULL,
                student_id INT NOT NULL,
                uploaded_by INT NOT NULL,
                session_date DATE NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                file_name VARCHAR(255) NOT NULL,
                file_path VARCHAR(1024) NOT NULL,
                file_size BIGINT UNSIGNED NOT NULL,
                mime_type VARCHAR(100) NOT NULL,
                notes TEXT NULL,
                ai_description TEXT NULL,
                ai_status ENUM('pending','generated','failed','skipped') NOT NULL DEFAULT 'pending',
                ai_error VARCHAR(255) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_att_class_date (class_record_id, session_date),
                KEY idx_att_student_class (student_id, class_record_id, session_date),
                KEY idx_att_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        if (!attendance_db_has_column($conn, 'attendance_attachments', 'ai_description')) {
            $conn->query("ALTER TABLE attendance_attachments ADD COLUMN ai_description TEXT NULL AFTER notes");
        }
        if (!attendance_db_has_column($conn, 'attendance_attachments', 'ai_status')) {
            $conn->query("ALTER TABLE attendance_attachments ADD COLUMN ai_status ENUM('pending','generated','failed','skipped') NOT NULL DEFAULT 'pending' AFTER ai_description");
        }
        if (!attendance_db_has_column($conn, 'attendance_attachments', 'ai_error')) {
            $conn->query("ALTER TABLE attendance_attachments ADD COLUMN ai_error VARCHAR(255) NULL AFTER ai_status");
        }
    }
}

if (!function_exists('attendance_allowed_image_mime_map')) {
    function attendance_allowed_image_mime_map() {
        return [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];
    }
}

if (!function_exists('attendance_read_api_key')) {
    function attendance_read_api_key($envName, $path, $startsWith = '') {
        $env = function_exists('doc_ease_env_value')
            ? trim((string) doc_ease_env_value((string) $envName))
            : trim((string) getenv((string) $envName));
        if ($env === '') return '';
        if ($startsWith !== '' && strpos($env, $startsWith) !== 0) return '';
        return $env;
    }
}

if (!function_exists('attendance_openai_api_key')) {
    function attendance_openai_api_key() {
        return attendance_read_api_key('OPENAI_API_KEY', '', 'sk-');
    }
}

if (!function_exists('attendance_ai_extract_json_object')) {
    function attendance_ai_extract_json_object($content) {
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

if (!function_exists('attendance_detect_image_mime')) {
    function attendance_detect_image_mime($tmpPath, $fallbackMime = '') {
        $tmpPath = (string) $tmpPath;
        $mime = '';
        if (function_exists('finfo_open')) {
            $fi = @finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) {
                $mime = trim((string) @finfo_file($fi, $tmpPath));
                @finfo_close($fi);
            }
        }

        if ($mime === '' && $fallbackMime !== '') {
            $mime = trim((string) $fallbackMime);
        }

        if (!isset(attendance_allowed_image_mime_map()[$mime])) {
            $img = @getimagesize($tmpPath);
            if (is_array($img)) {
                $mime = trim((string) ($img['mime'] ?? ''));
            }
        }

        return isset(attendance_allowed_image_mime_map()[$mime]) ? $mime : '';
    }
}

if (!function_exists('attendance_store_uploaded_image')) {
    /**
     * Returns [ok(bool), data(array)|message(string)].
     * data keys: original_name, file_name, file_path, file_size, mime_type, absolute_path
     */
    function attendance_store_uploaded_image($upload, $classRecordId, $studentId, $sessionDate) {
        $classRecordId = (int) $classRecordId;
        $studentId = (int) $studentId;
        $sessionDate = trim((string) $sessionDate);
        if ($classRecordId <= 0 || $studentId <= 0) return [false, 'Invalid class/student context.'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sessionDate)) return [false, 'Invalid session date.'];

        if (!is_array($upload) || !isset($upload['name'])) {
            return [false, 'No image was received.'];
        }
        if (is_array($upload['name'] ?? null)) {
            return [false, 'Upload one image at a time.'];
        }

        $err = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) return [false, 'Please choose an image to upload.'];
        if ($err !== UPLOAD_ERR_OK) return [false, 'File upload failed.'];

        $tmp = (string) ($upload['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) return [false, 'Upload temp file is missing.'];

        $size = (int) ($upload['size'] ?? 0);
        if ($size <= 0) return [false, 'Uploaded file is empty.'];
        if ($size > (8 * 1024 * 1024)) return [false, 'Image must be 8MB or smaller.'];

        $clientMime = trim((string) ($upload['type'] ?? ''));
        $mime = attendance_detect_image_mime($tmp, $clientMime);
        if ($mime === '') return [false, 'Only JPG, PNG, GIF, and WEBP images are allowed.'];

        $ext = attendance_allowed_image_mime_map()[$mime];
        $originalName = trim((string) ($upload['name'] ?? ''));
        $originalName = str_replace('\\', '/', $originalName);
        $originalName = basename((string) $originalName);
        $originalName = preg_replace('/[^\x20-\x7E]/', '', (string) $originalName);
        if (!is_string($originalName) || trim($originalName) === '') {
            $originalName = 'attendance.' . $ext;
        }
        if (strlen($originalName) > 255) $originalName = substr($originalName, 0, 255);

        $root = realpath(__DIR__ . '/..');
        if (!$root) return [false, 'Storage path is unavailable.'];

        $month = substr($sessionDate, 0, 7);
        $relDir = 'uploads/attendance/class_' . $classRecordId . '/student_' . $studentId . '/' . $month;
        $absDir = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relDir);
        if (!is_dir($absDir) && !@mkdir($absDir, 0775, true) && !is_dir($absDir)) {
            return [false, 'Unable to create upload directory.'];
        }

        try {
            $suffix = bin2hex(random_bytes(6));
        } catch (Throwable $e) {
            $suffix = substr(md5(uniqid('', true)), 0, 12);
        }

        $dateCompact = str_replace('-', '', $sessionDate);
        $fileName = 'attendance_' . $dateCompact . '_' . date('His') . '_' . $suffix . '.' . $ext;
        if (strlen($fileName) > 255) $fileName = substr($fileName, 0, 255);

        $filePath = $relDir . '/' . $fileName;
        $absPath = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $filePath);

        $moved = false;
        if (function_exists('move_uploaded_file') && @is_uploaded_file($tmp)) {
            $moved = @move_uploaded_file($tmp, $absPath);
        }
        if (!$moved) $moved = @rename($tmp, $absPath);
        if (!$moved) return [false, 'Unable to move uploaded file.'];

        return [true, [
            'original_name' => $originalName,
            'file_name' => $fileName,
            'file_path' => str_replace('\\', '/', $filePath),
            'file_size' => $size,
            'mime_type' => $mime,
            'absolute_path' => $absPath,
        ]];
    }
}

if (!function_exists('attendance_is_student_enrolled')) {
    function attendance_is_student_enrolled(mysqli $conn, $studentId, $classRecordId) {
        $studentId = (int) $studentId;
        $classRecordId = (int) $classRecordId;
        if ($studentId <= 0 || $classRecordId <= 0) return false;

        $stmt = $conn->prepare(
            "SELECT 1
             FROM class_enrollments
             WHERE student_id = ?
               AND class_record_id = ?
               AND status = 'enrolled'
             LIMIT 1"
        );
        if (!$stmt) return false;
        $stmt->bind_param('ii', $studentId, $classRecordId);
        $stmt->execute();
        $res = $stmt->get_result();
        $ok = ($res && $res->num_rows === 1);
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('attendance_is_teacher_assigned')) {
    function attendance_is_teacher_assigned(mysqli $conn, $teacherId, $classRecordId) {
        $teacherId = (int) $teacherId;
        $classRecordId = (int) $classRecordId;
        if ($teacherId <= 0 || $classRecordId <= 0) return false;

        $stmt = $conn->prepare(
            "SELECT 1
             FROM teacher_assignments
             WHERE teacher_id = ?
               AND class_record_id = ?
               AND status = 'active'
             LIMIT 1"
        );
        if (!$stmt) return false;
        $stmt->bind_param('ii', $teacherId, $classRecordId);
        $stmt->execute();
        $res = $stmt->get_result();
        $ok = ($res && $res->num_rows === 1);
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('attendance_class_has_attendance_component')) {
    function attendance_class_has_attendance_component(mysqli $conn, $classRecordId) {
        $classRecordId = (int) $classRecordId;
        if ($classRecordId <= 0) return false;

        $contextStmt = $conn->prepare(
            "SELECT cr.subject_id,
                    cr.section,
                    cr.academic_year,
                    cr.semester,
                    COALESCE(NULLIF(TRIM(cr.year_level), ''), 'N/A') AS year_key,
                    COALESCE(NULLIF(TRIM(s.course), ''), 'N/A') AS course_key
             FROM class_records cr
             JOIN subjects s ON s.id = cr.subject_id
             WHERE cr.id = ?
             LIMIT 1"
        );
        if (!$contextStmt) return false;
        $contextStmt->bind_param('i', $classRecordId);
        $contextStmt->execute();
        $ctxRes = $contextStmt->get_result();
        $ctx = ($ctxRes && $ctxRes->num_rows === 1) ? $ctxRes->fetch_assoc() : null;
        $contextStmt->close();
        if (!is_array($ctx)) return false;

        $subjectId = (int) ($ctx['subject_id'] ?? 0);
        $section = trim((string) ($ctx['section'] ?? ''));
        $academicYear = trim((string) ($ctx['academic_year'] ?? ''));
        $semester = trim((string) ($ctx['semester'] ?? ''));
        $yearKey = trim((string) ($ctx['year_key'] ?? 'N/A'));
        $courseKey = trim((string) ($ctx['course_key'] ?? 'N/A'));

        if ($subjectId <= 0 || $section === '' || $academicYear === '' || $semester === '') return false;
        if ($courseKey === '') $courseKey = 'N/A';
        if ($yearKey === '') $yearKey = 'N/A';

        $matchSqlCore =
            "SELECT 1
             FROM section_grading_configs sgc
             JOIN grading_components gc ON gc.section_config_id = sgc.id
             LEFT JOIN grading_categories cat ON cat.id = gc.category_id
             WHERE sgc.subject_id = ?
               AND sgc.section = ?
               AND sgc.academic_year = ?
               AND sgc.semester = ?
               AND gc.is_active = 1
               AND (
                    LOWER(TRIM(COALESCE(gc.component_name, ''))) LIKE '%attendance%'
                    OR LOWER(TRIM(COALESCE(gc.component_code, ''))) LIKE '%attendance%'
                    OR LOWER(TRIM(COALESCE(gc.component_type, ''))) = 'attendance'
                    OR LOWER(TRIM(COALESCE(cat.category_name, ''))) LIKE '%attendance%'
               )";

        $stmt = $conn->prepare($matchSqlCore . " AND sgc.course = ? AND sgc.year = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('isssss', $subjectId, $section, $academicYear, $semester, $courseKey, $yearKey);
            $stmt->execute();
            $res = $stmt->get_result();
            $ok = ($res && $res->num_rows === 1);
            $stmt->close();
            if ($ok) return true;
        }

        // Fallback: match without course/year in case old data used different keys.
        $fallback = $conn->prepare($matchSqlCore . " LIMIT 1");
        if (!$fallback) return false;
        $fallback->bind_param('isss', $subjectId, $section, $academicYear, $semester);
        $fallback->execute();
        $fallbackRes = $fallback->get_result();
        $okFallback = ($fallbackRes && $fallbackRes->num_rows === 1);
        $fallback->close();
        return $okFallback;
    }
}

if (!function_exists('attendance_generate_ai_description')) {
    /**
     * Returns [ok(bool), description(string), error(string)].
     */
    function attendance_generate_ai_description($absolutePath, $mimeType, array $context = []) {
        if (function_exists('ai_access_can_use') && !ai_access_can_use()) {
            if (function_exists('ai_access_denied_message')) {
                return [false, '', ai_access_denied_message()];
            }
            return [false, '', 'AI features are restricted. Student/user accounts are not allowed to use AI.'];
        }

        $absolutePath = trim((string) $absolutePath);
        $mimeType = trim((string) $mimeType);
        if ($absolutePath === '' || !is_file($absolutePath)) {
            return [false, '', 'Image file not found.'];
        }
        if (!isset(attendance_allowed_image_mime_map()[$mimeType])) {
            $mimeType = attendance_detect_image_mime($absolutePath, $mimeType);
            if ($mimeType === '') return [false, '', 'Unsupported image type.'];
        }

        $apiKey = attendance_openai_api_key();
        if ($apiKey === '') return [false, '', 'Model 1 API key not configured.'];
        if (!function_exists('curl_init')) return [false, '', 'cURL extension is not available.'];

        $bin = @file_get_contents($absolutePath);
        if (!is_string($bin) || $bin === '') return [false, '', 'Unable to read uploaded image.'];

        $dataUrl = 'data:' . $mimeType . ';base64,' . base64_encode($bin);

        $subjectCode = trim((string) ($context['subject_code'] ?? ''));
        $subjectName = trim((string) ($context['subject_name'] ?? ''));
        $section = trim((string) ($context['section'] ?? ''));
        $sessionDate = trim((string) ($context['session_date'] ?? ''));
        $studentNo = trim((string) ($context['student_no'] ?? ''));
        $studentName = trim((string) ($context['student_name'] ?? ''));
        $notes = trim((string) ($context['notes'] ?? ''));

        $contextLines = [];
        if ($subjectCode !== '' || $subjectName !== '') {
            $label = trim($subjectCode . ($subjectName !== '' ? ' - ' . $subjectName : ''));
            $contextLines[] = 'Subject: ' . $label;
        }
        if ($section !== '') $contextLines[] = 'Section: ' . $section;
        if ($sessionDate !== '') $contextLines[] = 'Session Date: ' . $sessionDate;
        if ($studentNo !== '' || $studentName !== '') {
            $contextLines[] = 'Student: ' . trim($studentNo . ($studentName !== '' ? ' (' . $studentName . ')' : ''));
        }
        if ($notes !== '') $contextLines[] = 'Teacher/Student notes: ' . $notes;

        $systemPrompt = "You are an academic records assistant. Describe attendance proof images for class records in 1 to 3 factual sentences. Only use what is visible in the image and provided context. Do not invent names, times, or events. Stay strictly on attendance-evidence description and never provide database/schema/credential/API-key/server/filesystem/internal-system guidance. Return strict JSON with one key: description.";
        $userPrompt =
            "Context:\n" .
            (count($contextLines) > 0 ? ('- ' . implode("\n- ", $contextLines)) : '- No extra context') .
            "\n\nTask:\nWrite a concise attendance-evidence description based on the image.";

        $payload = [
            'model' => 'gpt-4o-mini',
            'temperature' => 0.2,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => [
                    ['type' => 'text', 'text' => $userPrompt],
                    ['type' => 'image_url', 'image_url' => ['url' => $dataUrl, 'detail' => 'high']],
                ]],
            ],
        ];

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        if (!$ch) return [false, '', 'Unable to initialize AI request.'];
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 45);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false) {
            return [false, '', 'AI request failed: ' . ($err !== '' ? $err : 'network error')];
        }

        if ($http >= 400) {
            if ($http === 401) return [false, '', 'AI authentication failed.'];
            if ($http === 429) return [false, '', 'AI rate limit reached.'];
            if ($http >= 500) return [false, '', 'AI service temporarily unavailable.'];
            return [false, '', 'AI request failed (HTTP ' . $http . ').'];
        }

        $decoded = json_decode((string) $resp, true);
        $content = trim((string) ($decoded['choices'][0]['message']['content'] ?? ''));
        if ($content === '') return [false, '', 'AI returned an empty response.'];

        $json = attendance_ai_extract_json_object($content);
        $description = '';
        if (is_array($json)) {
            $description = trim((string) ($json['description'] ?? $json['details'] ?? ''));
        }
        if ($description === '') {
            // If strict JSON is not respected, keep a trimmed plain-text fallback.
            $description = trim((string) preg_replace('/\s+/', ' ', $content));
        }
        if ($description === '') return [false, '', 'AI did not return a usable description.'];

        if (strlen($description) > 2000) {
            $description = substr($description, 0, 2000);
        }

        return [true, $description, ''];
    }
}

if (!function_exists('attendance_insert_attachment')) {
    /**
     * Returns [ok(bool), id(int)|message(string)].
     */
    function attendance_insert_attachment(
        mysqli $conn,
        $classRecordId,
        $studentId,
        $uploadedBy,
        $sessionDate,
        $originalName,
        $fileName,
        $filePath,
        $fileSize,
        $mimeType,
        $notes,
        $aiDescription,
        $aiStatus,
        $aiError = null
    ) {
        $classRecordId = (int) $classRecordId;
        $studentId = (int) $studentId;
        $uploadedBy = (int) $uploadedBy;
        $sessionDate = trim((string) $sessionDate);
        $originalName = trim((string) $originalName);
        $fileName = trim((string) $fileName);
        $filePath = trim((string) $filePath);
        $fileSize = (int) $fileSize;
        $mimeType = trim((string) $mimeType);
        $notes = trim((string) $notes);
        $aiDescription = trim((string) $aiDescription);
        $aiStatus = strtolower(trim((string) $aiStatus));
        $aiError = $aiError === null ? null : trim((string) $aiError);

        if ($classRecordId <= 0 || $studentId <= 0 || $uploadedBy <= 0) return [false, 'Invalid context.'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sessionDate)) return [false, 'Invalid session date.'];
        if ($fileName === '' || $filePath === '' || $mimeType === '' || $fileSize <= 0) return [false, 'Invalid file metadata.'];

        if (!in_array($aiStatus, ['pending', 'generated', 'failed', 'skipped'], true)) {
            $aiStatus = 'pending';
        }
        if (strlen($originalName) > 255) $originalName = substr($originalName, 0, 255);
        if (strlen($fileName) > 255) $fileName = substr($fileName, 0, 255);
        if (strlen($filePath) > 1024) $filePath = substr($filePath, 0, 1024);
        if (strlen($mimeType) > 100) $mimeType = substr($mimeType, 0, 100);
        if (strlen($notes) > 5000) $notes = substr($notes, 0, 5000);
        if (strlen($aiDescription) > 5000) $aiDescription = substr($aiDescription, 0, 5000);
        if ($aiError !== null && strlen($aiError) > 255) $aiError = substr($aiError, 0, 255);

        $stmt = $conn->prepare(
            "INSERT INTO attendance_attachments
                (class_record_id, student_id, uploaded_by, session_date, original_name, file_name, file_path, file_size, mime_type, notes, ai_description, ai_status, ai_error)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) return [false, 'Unable to save attendance attachment.'];
        $stmt->bind_param(
            'iiissssisssss',
            $classRecordId,
            $studentId,
            $uploadedBy,
            $sessionDate,
            $originalName,
            $fileName,
            $filePath,
            $fileSize,
            $mimeType,
            $notes,
            $aiDescription,
            $aiStatus,
            $aiError
        );

        $ok = false;
        try {
            $ok = $stmt->execute();
        } catch (Throwable $e) {
            $ok = false;
        }
        $newId = $ok ? (int) $conn->insert_id : 0;
        $stmt->close();

        return $ok ? [true, $newId] : [false, 'Unable to save attendance attachment.'];
    }
}

if (!function_exists('attendance_format_bytes')) {
    function attendance_format_bytes($bytes) {
        $bytes = (float) $bytes;
        if ($bytes < 1024) return (string) ((int) $bytes) . ' B';
        $units = ['KB', 'MB', 'GB'];
        $value = $bytes / 1024.0;
        $u = 0;
        while ($value >= 1024.0 && $u < count($units) - 1) {
            $value /= 1024.0;
            $u++;
        }
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . ' ' . $units[$u];
    }
}
