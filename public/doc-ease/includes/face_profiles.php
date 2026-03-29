<?php
// Student face registration + lightweight verification helpers.
//
// NOTE: This is not "liveness" and can be bypassed by a determined attacker because
// the descriptor is produced client-side. It is still useful to reduce casual fraud.

if (!function_exists('face_profiles_ensure_tables')) {
    function face_profiles_ensure_tables(mysqli $conn) {
        $conn->query(
            "CREATE TABLE IF NOT EXISTS student_face_profiles (
                student_id INT NOT NULL PRIMARY KEY,
                descriptor_json MEDIUMTEXT NOT NULL,
                model VARCHAR(32) NOT NULL DEFAULT 'face-api.js',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                image_path VARCHAR(1024) NULL,
                image_mime VARCHAR(100) NULL,
                image_size BIGINT UNSIGNED NULL,
                KEY idx_sfp_updated (updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // Optional online migrations if the helper exists (it does in attendance_checkin.php).
        if (function_exists('attendance_db_has_column')) {
            if (!attendance_db_has_column($conn, 'student_face_profiles', 'image_path')) {
                $conn->query("ALTER TABLE student_face_profiles ADD COLUMN image_path VARCHAR(1024) NULL AFTER updated_at");
            }
            if (!attendance_db_has_column($conn, 'student_face_profiles', 'image_mime')) {
                $conn->query("ALTER TABLE student_face_profiles ADD COLUMN image_mime VARCHAR(100) NULL AFTER image_path");
            }
            if (!attendance_db_has_column($conn, 'student_face_profiles', 'image_size')) {
                $conn->query("ALTER TABLE student_face_profiles ADD COLUMN image_size BIGINT UNSIGNED NULL AFTER image_mime");
            }
        }
    }
}

if (!function_exists('face_profiles_upload_dir')) {
    function face_profiles_upload_dir() {
        return __DIR__ . '/../uploads/face_profiles';
    }
}

if (!function_exists('face_profiles_upload_dir_rel')) {
    function face_profiles_upload_dir_rel() {
        return 'uploads/face_profiles';
    }
}

if (!function_exists('face_profiles_save_upload')) {
    function face_profiles_save_upload($file, $studentId) {
        if (!is_array($file) || empty($file['tmp_name'])) return [false, 'Face image is required.', null];
        if (!isset($file['error']) || (int) $file['error'] !== UPLOAD_ERR_OK) return [false, 'Face image upload failed.', null];

        $size = isset($file['size']) ? (int) $file['size'] : 0;
        if ($size <= 0) return [false, 'Face image is empty.', null];
        if ($size > 5 * 1024 * 1024) return [false, 'Face image is too large (max 5MB).', null];

        $tmp = (string) $file['tmp_name'];
        if (!is_uploaded_file($tmp)) return [false, 'Invalid upload.', null];

        $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
        $mime = $finfo ? (string) finfo_file($finfo, $tmp) : '';
        if ($finfo) finfo_close($finfo);

        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
        if (!isset($allowed[$mime])) return [false, 'Unsupported image type. Use JPG, PNG, or WEBP.', null];
        $ext = $allowed[$mime];

        $dir = face_profiles_upload_dir();
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        if (!is_dir($dir)) return [false, 'Unable to create upload directory.', null];

        $studentId = (int) $studentId;
        $rand = bin2hex(random_bytes(8));
        $ts = date('Ymd_His');
        $base = 'face_reg_st' . $studentId . '_' . $ts . '_' . $rand . '.' . $ext;

        $rel = face_profiles_upload_dir_rel() . '/' . $base;
        $dst = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $base;

        $ok = @move_uploaded_file($tmp, $dst);
        if (!$ok || !is_file($dst)) return [false, 'Unable to save uploaded image.', null];

        return [true, '', [
            'path' => $rel,
            'mime' => $mime,
            'size' => $size,
        ]];
    }
}

if (!function_exists('face_profiles_parse_descriptor')) {
    /**
     * @return array<int, float>|null
     */
    function face_profiles_parse_descriptor($raw) {
        $raw = is_string($raw) ? trim($raw) : '';
        if ($raw === '') return null;
        $tmp = json_decode($raw, true);
        if (!is_array($tmp)) return null;
        if (count($tmp) < 64 || count($tmp) > 256) return null;

        $out = [];
        foreach ($tmp as $v) {
            if (!is_numeric($v)) return null;
            $f = (float) $v;
            if (!is_finite($f)) return null;
            $out[] = $f;
        }
        return $out;
    }
}

if (!function_exists('face_profiles_distance')) {
    /**
     * Euclidean distance between two descriptor vectors.
     */
    function face_profiles_distance(array $a, array $b) {
        $na = count($a);
        $nb = count($b);
        if ($na <= 0 || $na !== $nb) return 999.0;
        $sum = 0.0;
        for ($i = 0; $i < $na; $i++) {
            $da = is_numeric($a[$i]) ? (float) $a[$i] : 0.0;
            $db = is_numeric($b[$i]) ? (float) $b[$i] : 0.0;
            $d = $da - $db;
            $sum += $d * $d;
        }
        return sqrt($sum);
    }
}

if (!function_exists('face_profiles_get')) {
    function face_profiles_get(mysqli $conn, $studentId) {
        $studentId = (int) $studentId;
        if ($studentId <= 0) return null;
        $stmt = $conn->prepare(
            "SELECT student_id, descriptor_json, model, created_at, updated_at, image_path, image_mime, image_size
             FROM student_face_profiles
             WHERE student_id = ?
             LIMIT 1"
        );
        if (!$stmt) return null;
        $stmt->bind_param('i', $studentId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
        $stmt->close();
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('face_profiles_upsert')) {
    /**
     * @param array<int, float> $descriptor
     * @param array<string, mixed> $meta
     */
    function face_profiles_upsert(mysqli $conn, $studentId, array $descriptor, array $meta = []) {
        $studentId = (int) $studentId;
        if ($studentId <= 0) return [false, 'Invalid student context.'];

        if (count($descriptor) < 64 || count($descriptor) > 256) {
            return [false, 'Face descriptor is invalid. Please try again.'];
        }
        $descriptorJson = json_encode(array_values(array_map('floatval', $descriptor)));
        if (!is_string($descriptorJson) || $descriptorJson === '' || $descriptorJson === 'null') {
            return [false, 'Unable to encode face descriptor.'];
        }

        $model = isset($meta['model']) ? trim((string) $meta['model']) : 'face-api.js';
        if ($model === '' || strlen($model) > 32) $model = 'face-api.js';

        $imagePath = isset($meta['path']) ? (string) $meta['path'] : null;
        $imageMime = isset($meta['mime']) ? (string) $meta['mime'] : null;
        $imageSize = isset($meta['size']) ? (int) $meta['size'] : null;

        $stmt = $conn->prepare(
            "INSERT INTO student_face_profiles (student_id, descriptor_json, model, image_path, image_mime, image_size)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                descriptor_json = VALUES(descriptor_json),
                model = VALUES(model),
                image_path = VALUES(image_path),
                image_mime = VALUES(image_mime),
                image_size = VALUES(image_size),
                updated_at = CURRENT_TIMESTAMP"
        );
        if (!$stmt) return [false, 'Unable to save face registration.'];
        $stmt->bind_param('issssi', $studentId, $descriptorJson, $model, $imagePath, $imageMime, $imageSize);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) return [false, 'Unable to save face registration.'];
        return [true, 'Face registered successfully.'];
    }
}

