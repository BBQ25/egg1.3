<?php
// Accomplishment Creator helpers:
// - Admin-configurable generated-output TTL
// - Session-backed generated output batch management

if (!function_exists('acc_creator_output_ttl_default_minutes')) {
    function acc_creator_output_ttl_default_minutes() {
        return 5;
    }
}

if (!function_exists('acc_creator_output_ttl_clamp_minutes')) {
    function acc_creator_output_ttl_clamp_minutes($minutes) {
        $minutes = (int) $minutes;
        if ($minutes < 1) $minutes = 1;
        if ($minutes > 1440) $minutes = 1440;
        return $minutes;
    }
}

if (!function_exists('acc_creator_allowed_output_types')) {
    function acc_creator_allowed_output_types() {
        return ['Lecture', 'Laboratory', 'Lecture & Laboratory'];
    }
}

if (!function_exists('acc_creator_normalize_output_type')) {
    function acc_creator_normalize_output_type($value, $description = '') {
        $raw = strtolower(trim((string) $value));
        $desc = strtolower(trim((string) $description));
        $combined = $raw . ' ' . $desc;

        $hasLecture = (strpos($combined, 'lecture') !== false);
        $hasLab = (
            strpos($combined, 'laboratory') !== false ||
            strpos($combined, 'lab ') !== false ||
            preg_match('/\blab\b/', $combined)
        );

        if (strpos($raw, 'both') !== false || ($hasLecture && $hasLab)) return 'Lecture & Laboratory';
        if ($hasLab) return 'Laboratory';
        return 'Lecture';
    }
}

if (!function_exists('acc_creator_normalize_output_title')) {
    // Backward-compatible alias.
    function acc_creator_normalize_output_title($value, $description = '') {
        return acc_creator_normalize_output_type($value, $description);
    }
}

if (!function_exists('acc_creator_normalize_output_remarks')) {
    function acc_creator_normalize_output_remarks($value) {
        $raw = strtolower(trim((string) $value));
        if ($raw === '') return 'Accomplished';
        if (
            strpos($raw, 'on-going') !== false ||
            strpos($raw, 'ongoing') !== false ||
            strpos($raw, 'on going') !== false ||
            strpos($raw, 'in progress') !== false ||
            strpos($raw, 'ongo') !== false
        ) {
            return 'On-going';
        }
        return 'Accomplished';
    }
}

if (!function_exists('acc_creator_normalize_output_remarks_full')) {
    // Preserve a short progress/deviation note while ensuring the status stays normalized.
    function acc_creator_normalize_output_remarks_full($value, $description = '') {
        $raw = trim((string) $value);

        // Prefer richer helpers when available (from accomplishment_report/accomplishments.php).
        if (function_exists('acc_normalize_remarks_status') && function_exists('acc_extract_remarks_support_text') && function_exists('acc_compose_remarks_with_support')) {
            $status = acc_normalize_remarks_status($raw);
            $support = acc_extract_remarks_support_text($raw);
            if (function_exists('acc_normalize_remarks_support_note')) {
                $support = acc_normalize_remarks_support_note($support, (string) $description, $status);
            } else {
                $support = trim((string) preg_replace('/\\s+/', ' ', (string) $support));
                if (strlen($support) > 220) $support = rtrim(substr($support, 0, 217)) . '...';
                if ($support === '') {
                    $support = ($status === 'On-going')
                        ? 'Achieved: partial progress; Pending: follow-up/completion.'
                        : 'Achieved: completed as planned; Pending: none.';
                }
            }
            $out = acc_compose_remarks_with_support($status, $support);
            if (strlen($out) > 5000) $out = substr($out, 0, 5000);
            return $out;
        }

        // Fallback: status only.
        return acc_creator_normalize_output_remarks($raw);
    }
}

if (!function_exists('acc_creator_ensure_settings_table')) {
    function acc_creator_ensure_settings_table(mysqli $conn) {
        if (function_exists('ai_credit_ensure_settings_table')) {
            ai_credit_ensure_settings_table($conn);
            return;
        }

        $conn->query(
            "CREATE TABLE IF NOT EXISTS app_settings (
                setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
                setting_value VARCHAR(255) NOT NULL DEFAULT '',
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
}

if (!function_exists('acc_creator_output_ttl_get_minutes')) {
    function acc_creator_output_ttl_get_minutes(mysqli $conn) {
        acc_creator_ensure_settings_table($conn);

        $default = acc_creator_output_ttl_default_minutes();
        $defaultText = (string) $default;

        $ins = $conn->prepare(
            "INSERT IGNORE INTO app_settings (setting_key, setting_value)
             VALUES ('accomplishment_creator_output_ttl_minutes', ?)"
        );
        if ($ins) {
            $ins->bind_param('s', $defaultText);
            try { $ins->execute(); } catch (Throwable $e) { /* ignore */ }
            $ins->close();
        }

        $stmt = $conn->prepare(
            "SELECT setting_value
             FROM app_settings
             WHERE setting_key = 'accomplishment_creator_output_ttl_minutes'
             LIMIT 1"
        );
        if (!$stmt) return $default;
        $stmt->execute();
        $res = $stmt->get_result();
        $value = $default;
        if ($res && $res->num_rows === 1) {
            $row = $res->fetch_assoc();
            $value = acc_creator_output_ttl_clamp_minutes((int) ($row['setting_value'] ?? $default));
        }
        $stmt->close();
        return $value;
    }
}

if (!function_exists('acc_creator_output_ttl_save_minutes')) {
    function acc_creator_output_ttl_save_minutes(mysqli $conn, $minutes) {
        acc_creator_ensure_settings_table($conn);
        $minutes = acc_creator_output_ttl_clamp_minutes($minutes);
        $valueText = (string) $minutes;

        $stmt = $conn->prepare(
            "INSERT INTO app_settings (setting_key, setting_value)
             VALUES ('accomplishment_creator_output_ttl_minutes', ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        if (!$stmt) return false;
        $stmt->bind_param('s', $valueText);
        $ok = false;
        try { $ok = $stmt->execute(); } catch (Throwable $e) { $ok = false; }
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('acc_creator_session_key')) {
    function acc_creator_session_key($userId) {
        return 'acc_creator_generated_batch_u' . (int) $userId;
    }
}

if (!function_exists('acc_creator_clear_batch')) {
    function acc_creator_clear_batch($userId) {
        $key = acc_creator_session_key($userId);
        unset($_SESSION[$key]);
    }
}

if (!function_exists('acc_creator_get_active_batch')) {
    function acc_creator_get_active_batch($userId) {
        $key = acc_creator_session_key($userId);
        $batch = isset($_SESSION[$key]) ? $_SESSION[$key] : null;
        if (!is_array($batch)) return null;

        $expiresAt = isset($batch['expires_at']) ? (int) $batch['expires_at'] : 0;
        if ($expiresAt <= time()) {
            unset($_SESSION[$key]);
            return null;
        }

        $rows = [];
        $rawRows = isset($batch['rows']) && is_array($batch['rows']) ? $batch['rows'] : [];
        foreach ($rawRows as $row) {
            if (!is_array($row)) continue;
            $id = trim((string) ($row['id'] ?? ''));
            $date = trim((string) ($row['date'] ?? ''));
            $type = trim((string) ($row['type'] ?? ($row['title'] ?? '')));
            $description = trim((string) ($row['description'] ?? ''));
            $remarks = trim((string) ($row['remarks'] ?? ''));

            if ($id === '' || !preg_match('/^[a-f0-9]{8,40}$/i', $id)) continue;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
            if ($description === '') continue;
            $type = acc_creator_normalize_output_type($type, $description);
            $remarks = acc_creator_normalize_output_remarks_full($remarks, $description);
            if (strlen($description) > 5000) $description = substr($description, 0, 5000);

            $rows[] = [
                'id' => strtolower($id),
                'date' => $date,
                'type' => $type,
                'description' => $description,
                'remarks' => $remarks,
            ];
        }

        if (count($rows) === 0) {
            unset($_SESSION[$key]);
            return null;
        }

        $subject = trim((string) ($batch['subject'] ?? 'Monthly Accomplishment'));
        $context = trim((string) ($batch['context'] ?? ''));
        $styleHint = trim((string) ($batch['style_hint'] ?? 'balanced'));
        $createdAt = isset($batch['created_at']) ? (int) $batch['created_at'] : 0;
        $ttlMinutes = isset($batch['ttl_minutes']) ? (int) $batch['ttl_minutes'] : acc_creator_output_ttl_default_minutes();
        $ttlMinutes = acc_creator_output_ttl_clamp_minutes($ttlMinutes);

        $clean = [
            'subject' => $subject !== '' ? $subject : 'Monthly Accomplishment',
            'context' => $context,
            'style_hint' => $styleHint !== '' ? $styleHint : 'balanced',
            'created_at' => $createdAt > 0 ? $createdAt : (time() - 1),
            'expires_at' => $expiresAt,
            'ttl_minutes' => $ttlMinutes,
            'rows' => $rows,
            'remaining_seconds' => max(1, $expiresAt - time()),
        ];

        $_SESSION[$key] = [
            'subject' => $clean['subject'],
            'context' => $clean['context'],
            'style_hint' => $clean['style_hint'],
            'created_at' => $clean['created_at'],
            'expires_at' => $clean['expires_at'],
            'ttl_minutes' => $clean['ttl_minutes'],
            'rows' => $clean['rows'],
        ];

        return $clean;
    }
}

if (!function_exists('acc_creator_store_batch')) {
    function acc_creator_store_batch($userId, $subject, $context, $styleHint, array $rows, $ttlMinutes) {
        $ttlMinutes = acc_creator_output_ttl_clamp_minutes($ttlMinutes);
        $now = time();
        $expiresAt = $now + ($ttlMinutes * 60);

        $cleanRows = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $date = trim((string) ($row['date'] ?? ''));
            $type = trim((string) ($row['type'] ?? ($row['title'] ?? '')));
            $description = trim((string) ($row['description'] ?? ''));
            $remarks = trim((string) ($row['remarks'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
            if ($description === '') continue;
            $type = acc_creator_normalize_output_type($type, $description);
            $remarks = acc_creator_normalize_output_remarks_full($remarks, $description);
            if (strlen($description) > 5000) $description = substr($description, 0, 5000);

            try {
                $id = bin2hex(random_bytes(8));
            } catch (Throwable $e) {
                $id = strtolower(str_replace('.', '', uniqid('', true)));
            }

            $cleanRows[] = [
                'id' => $id,
                'date' => $date,
                'type' => $type,
                'description' => $description,
                'remarks' => $remarks,
            ];
        }

        if (count($cleanRows) === 0) {
            acc_creator_clear_batch($userId);
            return null;
        }

        $payload = [
            'subject' => trim((string) $subject) !== '' ? trim((string) $subject) : 'Monthly Accomplishment',
            'context' => trim((string) $context),
            'style_hint' => trim((string) $styleHint) !== '' ? trim((string) $styleHint) : 'balanced',
            'created_at' => $now,
            'expires_at' => $expiresAt,
            'ttl_minutes' => $ttlMinutes,
            'rows' => $cleanRows,
        ];

        $_SESSION[acc_creator_session_key($userId)] = $payload;
        return acc_creator_get_active_batch($userId);
    }
}

if (!function_exists('acc_creator_remove_batch_rows')) {
    function acc_creator_remove_batch_rows($userId, array $rowIds) {
        $batch = acc_creator_get_active_batch($userId);
        if (!is_array($batch)) return null;

        $remove = [];
        foreach ($rowIds as $id) {
            $id = strtolower(trim((string) $id));
            if ($id === '') continue;
            $remove[$id] = true;
        }
        if (count($remove) === 0) return $batch;

        $remaining = [];
        foreach ($batch['rows'] as $row) {
            $rowId = strtolower(trim((string) ($row['id'] ?? '')));
            if ($rowId === '' || isset($remove[$rowId])) continue;
            $remaining[] = $row;
        }

        if (count($remaining) === 0) {
            acc_creator_clear_batch($userId);
            return null;
        }

        $stored = [
            'subject' => (string) ($batch['subject'] ?? 'Monthly Accomplishment'),
            'context' => (string) ($batch['context'] ?? ''),
            'style_hint' => (string) ($batch['style_hint'] ?? 'balanced'),
            'created_at' => (int) ($batch['created_at'] ?? time()),
            'expires_at' => (int) ($batch['expires_at'] ?? (time() + 60)),
            'ttl_minutes' => (int) ($batch['ttl_minutes'] ?? acc_creator_output_ttl_default_minutes()),
            'rows' => $remaining,
        ];
        $_SESSION[acc_creator_session_key($userId)] = $stored;
        return acc_creator_get_active_batch($userId);
    }
}
