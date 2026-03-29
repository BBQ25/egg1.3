<?php
// Shared AI credit wallet helpers (supports decimal credits).
// This wallet is now shared by accomplishment AI and class-record AI chat/build flows.

if (!function_exists('usage_limit_ensure_system')) {
    $usageLimitsHelper = __DIR__ . '/usage_limits.php';
    if (is_file($usageLimitsHelper)) {
        require_once $usageLimitsHelper;
    }
}

if (!function_exists('ai_credit_hard_default_limit')) {
    function ai_credit_hard_default_limit() {
        return 10.0;
    }
}

if (!function_exists('ai_credit_round')) {
    function ai_credit_round($value) {
        $v = (float) $value;
        if ($v < 0) $v = 0.0;
        return round($v, 2);
    }
}

if (!function_exists('ai_credit_clamp_limit')) {
    function ai_credit_clamp_limit($limit) {
        $limit = (float) $limit;
        if ($limit < 0) $limit = 0.0;
        if ($limit > 10000) $limit = 10000.0;
        return ai_credit_round($limit);
    }
}

if (!function_exists('ai_credit_db_has_column')) {
    function ai_credit_db_has_column(mysqli $conn, $table, $column) {
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

if (!function_exists('ai_credit_user_role')) {
    function ai_credit_user_role(mysqli $conn, $userId) {
        $userId = (int) $userId;
        if ($userId <= 0) return '';
        $stmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
        if (!$stmt) return '';
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
        $stmt->close();
        return strtolower(trim((string) ($row['role'] ?? '')));
    }
}

if (!function_exists('ai_credit_ensure_settings_table')) {
    function ai_credit_ensure_settings_table(mysqli $conn) {
        $conn->query(
            "CREATE TABLE IF NOT EXISTS app_settings (
                setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
                setting_value VARCHAR(255) NOT NULL DEFAULT '',
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $defaultLimit = (string) ai_credit_round(ai_credit_hard_default_limit());
        $stmt = $conn->prepare(
            "INSERT IGNORE INTO app_settings (setting_key, setting_value)
             VALUES ('ai_rephrase_default_limit', ?)"
        );
        if (!$stmt) return;
        $stmt->bind_param('s', $defaultLimit);
        try { $stmt->execute(); } catch (Throwable $e) { /* ignore */ }
        $stmt->close();
    }
}

if (!function_exists('ai_credit_get_default_limit')) {
    function ai_credit_get_default_limit(mysqli $conn) {
        ai_credit_ensure_settings_table($conn);

        $default = ai_credit_hard_default_limit();
        $stmt = $conn->prepare(
            "SELECT setting_value
             FROM app_settings
             WHERE setting_key = 'ai_rephrase_default_limit'
             LIMIT 1"
        );
        if (!$stmt) return ai_credit_round($default);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows === 1) {
            $row = $res->fetch_assoc();
            $default = ai_credit_clamp_limit((float) ($row['setting_value'] ?? $default));
        }
        $stmt->close();
        return ai_credit_round($default);
    }
}

if (!function_exists('ai_credit_save_default_limit')) {
    function ai_credit_save_default_limit(mysqli $conn, $limit) {
        ai_credit_ensure_settings_table($conn);
        $limit = ai_credit_clamp_limit($limit);
        $limitText = number_format($limit, 2, '.', '');

        $stmt = $conn->prepare(
            "INSERT INTO app_settings (setting_key, setting_value)
             VALUES ('ai_rephrase_default_limit', ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        if (!$stmt) return false;
        $stmt->bind_param('s', $limitText);
        $ok = false;
        try { $ok = $stmt->execute(); } catch (Throwable $e) { $ok = false; }
        $stmt->close();
        if (!$ok) return false;

        // Keep DB default aligned for new users.
        try {
            $conn->query("ALTER TABLE users MODIFY ai_rephrase_credit_limit DECIMAL(10,2) NOT NULL DEFAULT " . $limitText);
        } catch (Throwable $e) {
            // Non-fatal if table shape differs; setting is still saved.
        }

        return true;
    }
}

if (!function_exists('ai_credit_ensure_user_columns')) {
    function ai_credit_ensure_user_columns(mysqli $conn, $defaultLimit = null) {
        $defaultLimit = $defaultLimit === null
            ? ai_credit_get_default_limit($conn)
            : ai_credit_clamp_limit($defaultLimit);
        $defaultText = number_format((float) $defaultLimit, 2, '.', '');

        if (!ai_credit_db_has_column($conn, 'users', 'ai_rephrase_credit_limit')) {
            $conn->query("ALTER TABLE users ADD COLUMN ai_rephrase_credit_limit DECIMAL(10,2) NOT NULL DEFAULT " . $defaultText);
        } else {
            // Normalize legacy INT columns to decimal.
            try {
                $conn->query("ALTER TABLE users MODIFY ai_rephrase_credit_limit DECIMAL(10,2) NOT NULL DEFAULT " . $defaultText);
            } catch (Throwable $e) { /* ignore */ }
        }

        if (!ai_credit_db_has_column($conn, 'users', 'ai_rephrase_credit_used')) {
            $conn->query("ALTER TABLE users ADD COLUMN ai_rephrase_credit_used DECIMAL(10,2) NOT NULL DEFAULT 0.00");
        } else {
            try {
                $conn->query("ALTER TABLE users MODIFY ai_rephrase_credit_used DECIMAL(10,2) NOT NULL DEFAULT 0.00");
            } catch (Throwable $e) { /* ignore */ }
        }

        try {
            $conn->query(
                "UPDATE users
                 SET ai_rephrase_credit_limit = GREATEST(ROUND(ai_rephrase_credit_limit, 2), 0.00),
                     ai_rephrase_credit_used = GREATEST(ROUND(ai_rephrase_credit_used, 2), 0.00)"
            );
            $conn->query(
                "UPDATE users
                 SET ai_rephrase_credit_used = LEAST(ai_rephrase_credit_used, ai_rephrase_credit_limit)
                 WHERE role <> 'admin'"
            );
        } catch (Throwable $e) {
            // Best effort cleanup.
        }
    }
}

if (!function_exists('ai_credit_ensure_system')) {
    function ai_credit_ensure_system(mysqli $conn) {
        static $ensured = false;
        if ($ensured) return;

        ai_credit_ensure_settings_table($conn);
        $default = ai_credit_get_default_limit($conn);
        ai_credit_ensure_user_columns($conn, $default);
        if (function_exists('usage_limit_ensure_system')) {
            usage_limit_ensure_system($conn);
        }
        $ensured = true;
    }
}

if (!function_exists('ai_credit_get_user_status')) {
    /**
     * Returns [ok(bool), status(array)|message(string)].
     * status: ['limit' => float, 'used' => float, 'remaining' => float, 'is_exempt' => bool]
     */
    function ai_credit_get_user_status(mysqli $conn, $userId) {
        $userId = (int) $userId;
        if ($userId <= 0) return [false, 'Invalid user.'];

        ai_credit_ensure_system($conn);

        $stmt = $conn->prepare(
            "SELECT role, ai_rephrase_credit_limit, ai_rephrase_credit_used
             FROM users
             WHERE id = ?
             LIMIT 1"
        );
        if (!$stmt) return [false, 'Unable to load AI credits.'];
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
        $stmt->close();
        if (!$row) return [false, 'User not found.'];

        $isExempt = strtolower(trim((string) ($row['role'] ?? ''))) === 'admin';
        $limit = ai_credit_clamp_limit((float) ($row['ai_rephrase_credit_limit'] ?? 0));
        $used = ai_credit_round((float) ($row['ai_rephrase_credit_used'] ?? 0));
        if (!$isExempt && $used > $limit) $used = $limit;
        if ($used < 0) $used = 0.0;
        $remaining = $isExempt ? 999999.0 : ai_credit_round($limit - $used);
        if (!$isExempt && $remaining < 0) $remaining = 0.0;

        return [true, [
            'limit' => $isExempt ? 999999.0 : $limit,
            'used' => $isExempt ? 0.0 : $used,
            'remaining' => $remaining,
            'is_exempt' => $isExempt,
        ]];
    }
}

if (!function_exists('ai_credit_try_consume')) {
    /**
     * Tries to consume 1.00 credit atomically.
     */
    function ai_credit_try_consume(mysqli $conn, $userId) {
        return ai_credit_try_consume_count($conn, $userId, 1.0);
    }
}

if (!function_exists('ai_credit_try_consume_count')) {
    /**
     * Tries to consume N credits atomically. Supports decimals (2dp).
     * Returns [ok(bool), message(string)].
     */
    function ai_credit_try_consume_count(mysqli $conn, $userId, $count = 1.0) {
        $userId = (int) $userId;
        $count = ai_credit_round($count);
        if ($userId <= 0) return [false, 'Invalid user.'];
        if ($count <= 0) return [false, 'Invalid credit amount.'];

        ai_credit_ensure_system($conn);

        $role = ai_credit_user_role($conn, $userId);
        if ($role === 'admin') {
            return [true, 'Admin is AI-credit-exempt.'];
        }
        if ($role === 'student' || $role === 'user') {
            if (function_exists('ai_access_denied_message')) {
                return [false, ai_access_denied_message()];
            }
            return [false, 'AI features are restricted. Student/user accounts are not allowed to use AI.'];
        }

        $stmt = $conn->prepare(
            "UPDATE users
             SET ai_rephrase_credit_used = ROUND(ai_rephrase_credit_used + ?, 2)
             WHERE id = ?
               AND role <> 'admin'
               AND ROUND(ai_rephrase_credit_used + ?, 2) <= ai_rephrase_credit_limit
             LIMIT 1"
        );
        if (!$stmt) return [false, 'Unable to consume AI credits.'];
        $stmt->bind_param('did', $count, $userId, $count);
        $ok = false;
        try { $ok = $stmt->execute(); } catch (Throwable $e) { $ok = false; }
        $affected = $ok ? (int) $stmt->affected_rows : 0;
        $stmt->close();

        if (!$ok) return [false, 'Unable to consume AI credits.'];
        if ($affected !== 1) {
            return [false, 'Not enough AI credits remaining. Please request more credits from Admin.'];
        }

        if (function_exists('usage_limit_log_event')) {
            [$okStatus, $statusOrMsg] = ai_credit_get_user_status($conn, $userId);
            if ($okStatus && is_array($statusOrMsg)) {
                $after = (float) ($statusOrMsg['used'] ?? 0.0);
                $before = max($after - $count, 0.0);
                $limit = (float) ($statusOrMsg['limit'] ?? 0.0);
                $scale = 100;
                usage_limit_log_event(
                    $conn,
                    $userId,
                    'ai_consume',
                    'ai_credit',
                    (int) round($count * $scale),
                    (int) round($before * $scale),
                    (int) round($after * $scale),
                    (int) round($limit * $scale),
                    'AI credits consumed. Values are logged in centi-credits (x100).',
                    ['unit' => 'credit_x100', 'credit_amount' => $count]
                );
            }
        }

        return [true, 'AI credits consumed.'];
    }
}

if (!function_exists('ai_credit_refund')) {
    function ai_credit_refund(mysqli $conn, $userId, $count = 1.0) {
        $userId = (int) $userId;
        $count = ai_credit_round($count);
        if ($userId <= 0 || $count <= 0) return false;

        ai_credit_ensure_system($conn);

        $stmt = $conn->prepare(
            "UPDATE users
             SET ai_rephrase_credit_used = GREATEST(ROUND(ai_rephrase_credit_used - ?, 2), 0.00)
             WHERE id = ?
             LIMIT 1"
        );
        if (!$stmt) return false;
        $stmt->bind_param('di', $count, $userId);
        $ok = false;
        try { $ok = $stmt->execute(); } catch (Throwable $e) { $ok = false; }
        $stmt->close();
        if ($ok && function_exists('usage_limit_log_event')) {
            [$okStatus, $statusOrMsg] = ai_credit_get_user_status($conn, $userId);
            if ($okStatus && is_array($statusOrMsg)) {
                $after = (float) ($statusOrMsg['used'] ?? 0.0);
                $before = max($after + $count, 0.0);
                $limit = (float) ($statusOrMsg['limit'] ?? 0.0);
                if ($before > $limit) $before = $limit;
                $scale = 100;
                usage_limit_log_event(
                    $conn,
                    $userId,
                    'ai_refund',
                    'ai_credit',
                    (int) round($count * $scale),
                    (int) round($before * $scale),
                    (int) round($after * $scale),
                    (int) round($limit * $scale),
                    'AI credits refunded. Values are logged in centi-credits (x100).',
                    ['unit' => 'credit_x100', 'credit_amount' => $count]
                );
            }
        }
        return $ok;
    }
}

if (!function_exists('ai_credit_set_user_limit')) {
    /**
     * Sets total AI credit limit for one user and clamps used <= limit.
     */
    function ai_credit_set_user_limit(mysqli $conn, $userId, $limit) {
        $userId = (int) $userId;
        if ($userId <= 0) return [false, 'Invalid user.'];

        ai_credit_ensure_system($conn);
        $limit = ai_credit_clamp_limit($limit);

        $previousLimit = 0.0;
        $previousUsed = 0.0;
        $prevStmt = $conn->prepare(
            "SELECT ai_rephrase_credit_limit, ai_rephrase_credit_used
             FROM users
             WHERE id = ?
               AND role <> 'admin'
             LIMIT 1"
        );
        if (!$prevStmt) return [false, 'Unable to update user AI credits.'];
        $prevStmt->bind_param('i', $userId);
        $prevStmt->execute();
        $prevRes = $prevStmt->get_result();
        $prevRow = ($prevRes && $prevRes->num_rows === 1) ? $prevRes->fetch_assoc() : null;
        $prevStmt->close();
        if (!$prevRow) return [false, 'User not found or not allowed.'];

        $previousLimit = ai_credit_clamp_limit((float) ($prevRow['ai_rephrase_credit_limit'] ?? 0));
        $previousUsed = ai_credit_round((float) ($prevRow['ai_rephrase_credit_used'] ?? 0));
        if ($previousUsed > $previousLimit) $previousUsed = $previousLimit;

        $stmt = $conn->prepare(
            "UPDATE users
             SET ai_rephrase_credit_limit = ?,
                 ai_rephrase_credit_used = LEAST(ai_rephrase_credit_used, ?)
             WHERE id = ?
               AND role <> 'admin'
             LIMIT 1"
        );
        if (!$stmt) return [false, 'Unable to update user AI credits.'];
        $stmt->bind_param('ddi', $limit, $limit, $userId);
        $ok = false;
        try { $ok = $stmt->execute(); } catch (Throwable $e) { $ok = false; }
        $affected = $ok ? (int) $stmt->affected_rows : 0;
        $stmt->close();
        if (!$ok) return [false, 'Unable to update user AI credits.'];
        if ($affected < 1) {
            $chk = $conn->prepare("SELECT 1 FROM users WHERE id = ? AND role <> 'admin' LIMIT 1");
            if (!$chk) return [false, 'User not found or not allowed.'];
            $chk->bind_param('i', $userId);
            $chk->execute();
            $res = $chk->get_result();
            $exists = ($res && $res->num_rows === 1);
            $chk->close();
            if (!$exists) return [false, 'User not found or not allowed.'];
        }

        if (function_exists('usage_limit_log_event')) {
            $usedAfter = min($previousUsed, $limit);
            $scale = 100;
            usage_limit_log_event(
                $conn,
                $userId,
                'ai_limit_set',
                'ai_credit',
                (int) round(($limit - $previousLimit) * $scale),
                (int) round($previousLimit * $scale),
                (int) round($limit * $scale),
                (int) round($limit * $scale),
                'AI credit limit updated. Values are logged in centi-credits (x100).',
                ['unit' => 'credit_x100', 'used_after_clamp' => $usedAfter]
            );
        }

        return [true, 'User AI credit limit updated.'];
    }
}
