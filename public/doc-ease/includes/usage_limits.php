<?php
// Usage limits helpers (AI credits + class record build usage).

if (!function_exists('usage_limit_has_column')) {
    function usage_limit_has_column(mysqli $conn, $table, $column) {
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

if (!function_exists('usage_limit_table_exists')) {
    function usage_limit_table_exists(mysqli $conn, $table) {
        $table = trim((string) $table);
        if ($table === '') return false;

        $stmt = $conn->prepare(
            "SELECT 1
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
             LIMIT 1"
        );
        if (!$stmt) return false;
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $res = $stmt->get_result();
        $ok = ($res && $res->num_rows === 1);
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('usage_limit_ensure_user_columns')) {
    function usage_limit_ensure_user_columns(mysqli $conn) {
        if (!usage_limit_has_column($conn, 'users', 'class_record_build_limit')) {
            $conn->query("ALTER TABLE users ADD COLUMN class_record_build_limit INT NOT NULL DEFAULT 4");
        }

        if (!usage_limit_has_column($conn, 'users', 'class_record_build_usage_used')) {
            $conn->query("ALTER TABLE users ADD COLUMN class_record_build_usage_used INT NOT NULL DEFAULT 0");

            // Seed existing teacher usage from currently saved builds so migration keeps behavior intuitive.
            if (usage_limit_table_exists($conn, 'class_record_builds')) {
                $conn->query(
                    "UPDATE users u
                     LEFT JOIN (
                         SELECT teacher_id, COUNT(*) AS total_builds
                         FROM class_record_builds
                         GROUP BY teacher_id
                     ) b ON b.teacher_id = u.id
                     SET u.class_record_build_usage_used = COALESCE(b.total_builds, 0)
                     WHERE u.role = 'teacher'"
                );
            }
        }
    }
}

if (!function_exists('usage_limit_ensure_events_table')) {
    function usage_limit_ensure_events_table(mysqli $conn) {
        $conn->query(
            "CREATE TABLE IF NOT EXISTS usage_limit_events (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                actor_user_id INT NULL,
                event_type VARCHAR(64) NOT NULL,
                resource_type VARCHAR(32) NOT NULL,
                event_amount INT NOT NULL DEFAULT 0,
                before_value INT NULL,
                after_value INT NULL,
                limit_value INT NULL,
                notes VARCHAR(255) NULL,
                metadata_json TEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_usage_user_created (user_id, created_at),
                KEY idx_usage_resource_created (resource_type, created_at),
                KEY idx_usage_event_created (event_type, created_at),
                KEY idx_usage_actor_created (actor_user_id, created_at),
                CONSTRAINT fk_usage_limit_user
                    FOREIGN KEY (user_id) REFERENCES users(id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_usage_limit_actor
                    FOREIGN KEY (actor_user_id) REFERENCES users(id)
                    ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
}

if (!function_exists('usage_limit_ensure_system')) {
    function usage_limit_ensure_system(mysqli $conn) {
        static $ensured = false;
        if ($ensured) return;

        usage_limit_ensure_user_columns($conn);
        usage_limit_ensure_events_table($conn);
        $ensured = true;
    }
}

if (!function_exists('usage_limit_actor_user_id')) {
    function usage_limit_actor_user_id() {
        $actor = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
        return $actor > 0 ? $actor : null;
    }
}

if (!function_exists('usage_limit_log_event')) {
    function usage_limit_log_event(
        mysqli $conn,
        $userId,
        $eventType,
        $resourceType,
        $eventAmount = 0,
        $beforeValue = null,
        $afterValue = null,
        $limitValue = null,
        $notes = null,
        array $metadata = [],
        $actorUserId = null
    ) {
        try {
            usage_limit_ensure_system($conn);
        } catch (Throwable $e) {
            return;
        }

        $userId = (int) $userId;
        if ($userId <= 0) return;

        $eventType = trim((string) $eventType);
        $resourceType = trim((string) $resourceType);
        if ($eventType === '' || $resourceType === '') return;

        $eventAmount = (int) $eventAmount;
        $beforeValue = $beforeValue !== null ? (int) $beforeValue : null;
        $afterValue = $afterValue !== null ? (int) $afterValue : null;
        $limitValue = $limitValue !== null ? (int) $limitValue : null;

        $notes = $notes !== null ? trim((string) $notes) : null;
        if ($notes === '') $notes = null;

        $metaJson = null;
        if (!empty($metadata)) {
            $json = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($json)) $metaJson = $json;
        }

        $actorUserId = $actorUserId !== null ? (int) $actorUserId : usage_limit_actor_user_id();
        if ($actorUserId !== null && $actorUserId <= 0) $actorUserId = null;

        $stmt = $conn->prepare(
            "INSERT INTO usage_limit_events
                (user_id, actor_user_id, event_type, resource_type, event_amount, before_value, after_value, limit_value, notes, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) return;

        $stmt->bind_param(
            'iissiiiiss',
            $userId,
            $actorUserId,
            $eventType,
            $resourceType,
            $eventAmount,
            $beforeValue,
            $afterValue,
            $limitValue,
            $notes,
            $metaJson
        );

        try { $stmt->execute(); } catch (Throwable $e) { /* ignore */ }
        $stmt->close();
    }
}

if (!function_exists('usage_limit_get_build_status')) {
    /**
     * Returns [ok(bool), status(array)|message(string)].
     * status: ['limit'=>int, 'used'=>int, 'remaining'=>int|null, 'total_builds'=>int, 'is_unlimited'=>bool]
     */
    function usage_limit_get_build_status(mysqli $conn, $teacherId) {
        $teacherId = (int) $teacherId;
        if ($teacherId <= 0) return [false, 'Invalid teacher account.'];

        usage_limit_ensure_system($conn);

        $stmt = $conn->prepare(
            "SELECT class_record_build_limit, class_record_build_usage_used
             FROM users
             WHERE id = ?
               AND role = 'teacher'
             LIMIT 1"
        );
        if (!$stmt) return [false, 'Unable to load build usage status.'];
        $stmt->bind_param('i', $teacherId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
        $stmt->close();
        if (!$row) return [false, 'Teacher account not found.'];

        $limit = (int) ($row['class_record_build_limit'] ?? 0);
        if ($limit < 0) $limit = 0;
        $used = (int) ($row['class_record_build_usage_used'] ?? 0);
        if ($used < 0) $used = 0;

        $totalBuilds = 0;
        if (usage_limit_table_exists($conn, 'class_record_builds')) {
            $cntStmt = $conn->prepare("SELECT COUNT(*) AS c FROM class_record_builds WHERE teacher_id = ?");
            if ($cntStmt) {
                $cntStmt->bind_param('i', $teacherId);
                $cntStmt->execute();
                $cntRes = $cntStmt->get_result();
                if ($cntRes && $cntRes->num_rows === 1) {
                    $totalBuilds = (int) ($cntRes->fetch_assoc()['c'] ?? 0);
                }
                $cntStmt->close();
            }
        }

        $isUnlimited = $limit === 0;
        $remaining = $isUnlimited ? null : max($limit - $used, 0);

        return [true, [
            'limit' => $limit,
            'used' => $used,
            'remaining' => $remaining,
            'total_builds' => max(0, $totalBuilds),
            'is_unlimited' => $isUnlimited,
        ]];
    }
}

if (!function_exists('usage_limit_try_consume_build')) {
    /**
     * Atomically consume build usage slots. Returns [ok(bool), message(string)].
     */
    function usage_limit_try_consume_build(mysqli $conn, $teacherId, $count = 1) {
        $teacherId = (int) $teacherId;
        $count = (int) $count;
        if ($teacherId <= 0) return [false, 'Invalid teacher account.'];
        if ($count <= 0) return [false, 'Invalid build usage amount.'];

        usage_limit_ensure_system($conn);

        $stmt = $conn->prepare(
            "UPDATE users
             SET class_record_build_usage_used = class_record_build_usage_used + ?
             WHERE id = ?
               AND role = 'teacher'
               AND (
                    class_record_build_limit = 0
                    OR (class_record_build_usage_used + ?) <= class_record_build_limit
               )
             LIMIT 1"
        );
        if (!$stmt) return [false, 'Unable to consume build usage.'];
        $stmt->bind_param('iii', $count, $teacherId, $count);
        $ok = false;
        try { $ok = $stmt->execute(); } catch (Throwable $e) { $ok = false; }
        $affected = $ok ? (int) $stmt->affected_rows : 0;
        $stmt->close();
        if (!$ok) return [false, 'Unable to consume build usage.'];
        if ($affected !== 1) return [false, 'Build limit reached. Ask Admin to refresh or raise your build limit.'];

        [$okStatus, $statusOrMessage] = usage_limit_get_build_status($conn, $teacherId);
        if ($okStatus) {
            $st = is_array($statusOrMessage) ? $statusOrMessage : [];
            $after = (int) ($st['used'] ?? 0);
            $before = max($after - $count, 0);
            $limit = (int) ($st['limit'] ?? 0);
            usage_limit_log_event(
                $conn,
                $teacherId,
                'build_consume',
                'build',
                $count,
                $before,
                $after,
                $limit,
                'Build usage consumed.'
            );
        }

        return [true, 'Build usage consumed.'];
    }
}

if (!function_exists('usage_limit_refresh_build_usage')) {
    /**
     * Reset build usage back to zero for one teacher.
     * Returns [ok(bool), message(string)].
     */
    function usage_limit_refresh_build_usage(mysqli $conn, $teacherId, $actorUserId = null) {
        $teacherId = (int) $teacherId;
        if ($teacherId <= 0) return [false, 'Invalid teacher account.'];

        usage_limit_ensure_system($conn);

        [$okStatus, $statusOrMessage] = usage_limit_get_build_status($conn, $teacherId);
        if (!$okStatus) return [false, is_string($statusOrMessage) ? $statusOrMessage : 'Unable to read build usage.'];
        $status = is_array($statusOrMessage) ? $statusOrMessage : [];
        $before = (int) ($status['used'] ?? 0);
        $limit = (int) ($status['limit'] ?? 0);

        $stmt = $conn->prepare(
            "UPDATE users
             SET class_record_build_usage_used = 0
             WHERE id = ?
               AND role = 'teacher'
             LIMIT 1"
        );
        if (!$stmt) return [false, 'Unable to refresh build usage.'];
        $stmt->bind_param('i', $teacherId);
        $ok = false;
        try { $ok = $stmt->execute(); } catch (Throwable $e) { $ok = false; }
        $affected = $ok ? (int) $stmt->affected_rows : 0;
        $stmt->close();
        if (!$ok) return [false, 'Unable to refresh build usage.'];
        if ($affected < 1 && $before === 0) {
            // Nothing changed, but this is still a successful refresh request.
            return [true, 'Build usage already at zero.'];
        }

        usage_limit_log_event(
            $conn,
            $teacherId,
            'build_refresh',
            'build',
            $before,
            $before,
            0,
            $limit,
            'Build usage refreshed to zero.',
            [],
            $actorUserId
        );

        return [true, 'Build usage refreshed.'];
    }
}

if (!function_exists('usage_limit_refresh_ai_usage')) {
    /**
     * Reset AI usage back to zero for one user.
     * Returns [ok(bool), message(string)].
     */
    function usage_limit_refresh_ai_usage(mysqli $conn, $userId, $actorUserId = null) {
        $userId = (int) $userId;
        if ($userId <= 0) return [false, 'Invalid user account.'];

        usage_limit_ensure_system($conn);
        if (function_exists('ai_credit_ensure_system')) {
            ai_credit_ensure_system($conn);
        }

        $stmtStatus = $conn->prepare(
            "SELECT ai_rephrase_credit_limit, ai_rephrase_credit_used
             FROM users
             WHERE id = ?
               AND role <> 'admin'
             LIMIT 1"
        );
        if (!$stmtStatus) return [false, 'Unable to load AI usage.'];
        $stmtStatus->bind_param('i', $userId);
        $stmtStatus->execute();
        $res = $stmtStatus->get_result();
        $row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
        $stmtStatus->close();
        if (!$row) return [false, 'User not found or not allowed.'];

        $before = round((float) ($row['ai_rephrase_credit_used'] ?? 0), 2);
        if ($before < 0) $before = 0.0;
        $limit = round((float) ($row['ai_rephrase_credit_limit'] ?? 0), 2);
        if ($limit < 0) $limit = 0.0;

        $stmt = $conn->prepare(
            "UPDATE users
             SET ai_rephrase_credit_used = 0
             WHERE id = ?
               AND role <> 'admin'
             LIMIT 1"
        );
        if (!$stmt) return [false, 'Unable to refresh AI usage.'];
        $stmt->bind_param('i', $userId);
        $ok = false;
        try { $ok = $stmt->execute(); } catch (Throwable $e) { $ok = false; }
        $affected = $ok ? (int) $stmt->affected_rows : 0;
        $stmt->close();
        if (!$ok) return [false, 'Unable to refresh AI usage.'];
        if ($affected < 1 && $before <= 0.0) {
            return [true, 'AI usage already at zero.'];
        }

        $scale = 100;
        usage_limit_log_event(
            $conn,
            $userId,
            'ai_refresh',
            'ai_credit',
            (int) round($before * $scale),
            (int) round($before * $scale),
            0,
            (int) round($limit * $scale),
            'AI usage refreshed to zero. Values are logged in centi-credits (x100).',
            ['unit' => 'credit_x100', 'credit_amount' => $before],
            $actorUserId
        );

        return [true, 'AI usage refreshed.'];
    }
}

if (!function_exists('usage_limit_fetch_events_for_users')) {
    /**
     * Returns map[userId] => event rows.
     */
    function usage_limit_fetch_events_for_users(mysqli $conn, array $userIds, $perUser = 40, $days = 180) {
        usage_limit_ensure_system($conn);

        $ids = [];
        foreach ($userIds as $id) {
            $v = (int) $id;
            if ($v > 0) $ids[$v] = true;
        }
        $ids = array_keys($ids);
        if (count($ids) === 0) return [];

        $perUser = (int) $perUser;
        if ($perUser < 1) $perUser = 1;
        if ($perUser > 100) $perUser = 100;

        $days = (int) $days;
        if ($days < 1) $days = 1;
        if ($days > 3650) $days = 3650;

        $maxRows = max(200, min(12000, $perUser * count($ids) * 2));
        $idList = implode(',', array_map('intval', $ids));

        $sql = "SELECT e.id, e.user_id, e.event_type, e.resource_type, e.event_amount,
                       e.before_value, e.after_value, e.limit_value, e.notes, e.metadata_json, e.created_at,
                       actor.username AS actor_name
                FROM usage_limit_events e
                LEFT JOIN users actor ON actor.id = e.actor_user_id
                WHERE e.user_id IN ($idList)
                  AND e.created_at >= DATE_SUB(NOW(), INTERVAL $days DAY)
                ORDER BY e.created_at DESC, e.id DESC
                LIMIT $maxRows";

        $res = $conn->query($sql);
        if (!$res) return [];

        $map = [];
        while ($row = $res->fetch_assoc()) {
            $uid = (int) ($row['user_id'] ?? 0);
            if ($uid <= 0) continue;
            if (!isset($map[$uid])) $map[$uid] = [];
            if (count($map[$uid]) >= $perUser) continue;

            $map[$uid][] = [
                'id' => (int) ($row['id'] ?? 0),
                'event_type' => (string) ($row['event_type'] ?? ''),
                'resource_type' => (string) ($row['resource_type'] ?? ''),
                'event_amount' => (int) ($row['event_amount'] ?? 0),
                'before_value' => isset($row['before_value']) ? (int) $row['before_value'] : null,
                'after_value' => isset($row['after_value']) ? (int) $row['after_value'] : null,
                'limit_value' => isset($row['limit_value']) ? (int) $row['limit_value'] : null,
                'notes' => (string) ($row['notes'] ?? ''),
                'metadata_json' => (string) ($row['metadata_json'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'actor_name' => (string) ($row['actor_name'] ?? ''),
            ];
        }
        $res->free();

        return $map;
    }
}
