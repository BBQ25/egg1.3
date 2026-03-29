<?php
// Reverse Class Record feature toggle helpers.
// Shared by Admin settings and Teacher workflow pages.

if (!function_exists('reverse_class_record_setting_key')) {
    function reverse_class_record_setting_key() {
        return 'reverse_class_record_enabled';
    }
}

if (!function_exists('reverse_class_record_credit_cost_setting_key')) {
    function reverse_class_record_credit_cost_setting_key() {
        return 'reverse_class_record_credit_cost';
    }
}

if (!function_exists('reverse_class_record_teacher_access_table')) {
    function reverse_class_record_teacher_access_table() {
        return 'reverse_class_record_teacher_access';
    }
}

if (!function_exists('reverse_class_record_ensure_settings_table')) {
    function reverse_class_record_ensure_settings_table(mysqli $conn) {
        static $done = false;
        if ($done) return;
        $done = true;

        $conn->query(
            "CREATE TABLE IF NOT EXISTS app_settings (
                setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
                setting_value VARCHAR(255) NOT NULL DEFAULT '',
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $defaultValue = '0';
        $key = reverse_class_record_setting_key();
        $stmt = $conn->prepare(
            "INSERT IGNORE INTO app_settings (setting_key, setting_value)
             VALUES (?, ?)"
        );
        if ($stmt) {
            $stmt->bind_param('ss', $key, $defaultValue);
            try { $stmt->execute(); } catch (Throwable $e) { /* ignore */ }
            $stmt->close();
        }

        $creditKey = reverse_class_record_credit_cost_setting_key();
        $defaultCreditCost = (string) reverse_class_record_credit_cost_default();
        $creditStmt = $conn->prepare(
            "INSERT IGNORE INTO app_settings (setting_key, setting_value)
             VALUES (?, ?)"
        );
        if ($creditStmt) {
            $creditStmt->bind_param('ss', $creditKey, $defaultCreditCost);
            try { $creditStmt->execute(); } catch (Throwable $e) { /* ignore */ }
            $creditStmt->close();
        }

        $accessTable = reverse_class_record_teacher_access_table();
        $conn->query(
            "CREATE TABLE IF NOT EXISTS {$accessTable} (
                teacher_user_id INT NOT NULL PRIMARY KEY,
                is_enabled TINYINT(1) NOT NULL DEFAULT 1,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_rcr_teacher_access_user
                    FOREIGN KEY (teacher_user_id) REFERENCES users(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
}

if (!function_exists('reverse_class_record_credit_cost_default')) {
    function reverse_class_record_credit_cost_default() {
        return 1.0;
    }
}

if (!function_exists('reverse_class_record_credit_cost_round')) {
    function reverse_class_record_credit_cost_round($value) {
        return round((float) $value, 2);
    }
}

if (!function_exists('reverse_class_record_credit_cost_clamp')) {
    function reverse_class_record_credit_cost_clamp($value) {
        $value = reverse_class_record_credit_cost_round($value);
        if ($value < 0) $value = 0.0;
        if ($value > 100) $value = 100.0;
        return reverse_class_record_credit_cost_round($value);
    }
}

if (!function_exists('reverse_class_record_bool_from_text')) {
    function reverse_class_record_bool_from_text($value, $fallback = false) {
        $raw = strtolower(trim((string) $value));
        if (in_array($raw, ['1', 'true', 'yes', 'y', 'on', 'active', 'enabled'], true)) return true;
        if (in_array($raw, ['0', 'false', 'no', 'n', 'off', 'inactive', 'disabled'], true)) return false;
        return !empty($fallback);
    }
}

if (!function_exists('reverse_class_record_is_enabled')) {
    function reverse_class_record_is_enabled(mysqli $conn) {
        reverse_class_record_ensure_settings_table($conn);
        $key = reverse_class_record_setting_key();
        $stmt = $conn->prepare(
            "SELECT setting_value
             FROM app_settings
             WHERE setting_key = ?
             LIMIT 1"
        );
        if (!$stmt) return false;
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $res = $stmt->get_result();
        $raw = '0';
        if ($res && $res->num_rows === 1) {
            $row = $res->fetch_assoc();
            $raw = (string) ($row['setting_value'] ?? '0');
        }
        $stmt->close();
        return reverse_class_record_bool_from_text($raw, false);
    }
}

if (!function_exists('reverse_class_record_set_enabled')) {
    function reverse_class_record_set_enabled(mysqli $conn, $enabled) {
        reverse_class_record_ensure_settings_table($conn);
        $key = reverse_class_record_setting_key();
        $value = !empty($enabled) ? '1' : '0';
        $stmt = $conn->prepare(
            "INSERT INTO app_settings (setting_key, setting_value)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        if (!$stmt) return false;
        $stmt->bind_param('ss', $key, $value);
        $ok = false;
        try { $ok = $stmt->execute(); } catch (Throwable $e) { $ok = false; }
        $stmt->close();
        return (bool) $ok;
    }
}

if (!function_exists('reverse_class_record_get_credit_cost')) {
    function reverse_class_record_get_credit_cost(mysqli $conn) {
        reverse_class_record_ensure_settings_table($conn);
        $key = reverse_class_record_credit_cost_setting_key();
        $default = reverse_class_record_credit_cost_default();

        $stmt = $conn->prepare(
            "SELECT setting_value
             FROM app_settings
             WHERE setting_key = ?
             LIMIT 1"
        );
        if (!$stmt) return reverse_class_record_credit_cost_clamp($default);
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $res = $stmt->get_result();

        $cost = $default;
        if ($res && $res->num_rows === 1) {
            $row = $res->fetch_assoc();
            $cost = (float) ($row['setting_value'] ?? $default);
        }
        $stmt->close();
        return reverse_class_record_credit_cost_clamp($cost);
    }
}

if (!function_exists('reverse_class_record_set_credit_cost')) {
    function reverse_class_record_set_credit_cost(mysqli $conn, $cost) {
        reverse_class_record_ensure_settings_table($conn);
        $key = reverse_class_record_credit_cost_setting_key();
        $cost = reverse_class_record_credit_cost_clamp($cost);
        $value = number_format($cost, 2, '.', '');

        $stmt = $conn->prepare(
            "INSERT INTO app_settings (setting_key, setting_value)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        if (!$stmt) return false;
        $stmt->bind_param('ss', $key, $value);
        $ok = false;
        try { $ok = $stmt->execute(); } catch (Throwable $e) { $ok = false; }
        $stmt->close();
        return (bool) $ok;
    }
}

if (!function_exists('reverse_class_record_teacher_is_enabled')) {
    function reverse_class_record_teacher_is_enabled(mysqli $conn, $teacherUserId, $fallback = true) {
        reverse_class_record_ensure_settings_table($conn);
        $teacherUserId = (int) $teacherUserId;
        if ($teacherUserId <= 0) return !empty($fallback);

        $accessTable = reverse_class_record_teacher_access_table();
        $stmt = $conn->prepare(
            "SELECT is_enabled
             FROM {$accessTable}
             WHERE teacher_user_id = ?
             LIMIT 1"
        );
        if (!$stmt) return !empty($fallback);
        $stmt->bind_param('i', $teacherUserId);
        $stmt->execute();
        $res = $stmt->get_result();
        if (!($res && $res->num_rows === 1)) {
            $stmt->close();
            return !empty($fallback);
        }
        $row = $res->fetch_assoc();
        $stmt->close();
        return ((int) ($row['is_enabled'] ?? 0) === 1);
    }
}

if (!function_exists('reverse_class_record_teacher_set_enabled')) {
    function reverse_class_record_teacher_set_enabled(mysqli $conn, $teacherUserId, $enabled) {
        reverse_class_record_ensure_settings_table($conn);
        $teacherUserId = (int) $teacherUserId;
        if ($teacherUserId <= 0) return false;

        $teacherCheck = $conn->prepare(
            "SELECT id
             FROM users
             WHERE id = ? AND role = 'teacher'
             LIMIT 1"
        );
        if (!$teacherCheck) return false;
        $teacherCheck->bind_param('i', $teacherUserId);
        $teacherCheck->execute();
        $teacherRes = $teacherCheck->get_result();
        $isTeacher = ($teacherRes && $teacherRes->num_rows === 1);
        $teacherCheck->close();
        if (!$isTeacher) return false;

        $accessTable = reverse_class_record_teacher_access_table();
        $isEnabled = !empty($enabled) ? 1 : 0;
        $stmt = $conn->prepare(
            "INSERT INTO {$accessTable} (teacher_user_id, is_enabled)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled)"
        );
        if (!$stmt) return false;
        $stmt->bind_param('ii', $teacherUserId, $isEnabled);
        $ok = false;
        try { $ok = $stmt->execute(); } catch (Throwable $e) { $ok = false; }
        $stmt->close();
        return (bool) $ok;
    }
}

if (!function_exists('reverse_class_record_teacher_set_all_enabled')) {
    function reverse_class_record_teacher_set_all_enabled(mysqli $conn, $enabled) {
        reverse_class_record_ensure_settings_table($conn);
        $accessTable = reverse_class_record_teacher_access_table();
        $isEnabled = !empty($enabled) ? 1 : 0;

        $stmt = $conn->prepare(
            "INSERT INTO {$accessTable} (teacher_user_id, is_enabled)
             SELECT u.id, ?
             FROM users u
             WHERE u.role = 'teacher'
             ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled)"
        );
        if (!$stmt) return [0, false];
        $stmt->bind_param('i', $isEnabled);
        $ok = false;
        $affected = 0;
        try {
            $ok = $stmt->execute();
            $affected = (int) $stmt->affected_rows;
        } catch (Throwable $e) {
            $ok = false;
        }
        $stmt->close();
        return [$affected, (bool) $ok];
    }
}

if (!function_exists('reverse_class_record_teacher_access_rows')) {
    function reverse_class_record_teacher_access_rows(mysqli $conn) {
        reverse_class_record_ensure_settings_table($conn);

        $rows = [];
        $accessTable = reverse_class_record_teacher_access_table();
        $stmt = $conn->prepare(
            "SELECT u.id,
                    u.username,
                    u.useremail,
                    u.first_name,
                    u.last_name,
                    u.is_active,
                    COALESCE(ta.is_enabled, 1) AS reverse_enabled
             FROM users u
             LEFT JOIN {$accessTable} ta
                    ON ta.teacher_user_id = u.id
             WHERE u.role = 'teacher'
             ORDER BY u.is_active DESC, u.first_name ASC, u.last_name ASC, u.username ASC, u.id ASC"
        );
        if (!$stmt) return $rows;
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) continue;
            $firstName = trim((string) ($row['first_name'] ?? ''));
            $lastName = trim((string) ($row['last_name'] ?? ''));
            $username = trim((string) ($row['username'] ?? ''));
            $displayName = trim($firstName . ' ' . $lastName);
            if ($displayName === '') $displayName = $username;

            $rows[] = [
                'id' => $id,
                'username' => $username,
                'useremail' => trim((string) ($row['useremail'] ?? '')),
                'display_name' => $displayName,
                'is_active' => ((int) ($row['is_active'] ?? 0) === 1) ? 1 : 0,
                'reverse_enabled' => ((int) ($row['reverse_enabled'] ?? 0) === 1) ? 1 : 0,
            ];
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('reverse_class_record_can_teacher_use')) {
    function reverse_class_record_can_teacher_use(mysqli $conn, $teacherUserId) {
        if (!reverse_class_record_is_enabled($conn)) return false;
        return reverse_class_record_teacher_is_enabled($conn, $teacherUserId, true);
    }
}
