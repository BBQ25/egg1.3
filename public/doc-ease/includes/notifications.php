<?php
// Notification helpers built on top of audit_logs.
// - Data source: audit_logs
// - Read state: user_notification_state (per-user high-water mark)

if (!function_exists('notification_normalize_role')) {
    function notification_normalize_role($role) {
        if (function_exists('normalize_role')) return normalize_role($role);
        $role = strtolower(trim((string) $role));
        if ($role === 'user') return 'student';
        return $role;
    }
}

if (!function_exists('notification_ensure_tables')) {
    function notification_ensure_tables(mysqli $conn) {
        static $done = false;
        if ($done) return;
        $done = true;

        if (!function_exists('ensure_audit_logs_table')) {
            $auditPath = __DIR__ . '/audit.php';
            if (is_file($auditPath)) require_once $auditPath;
        }
        if (function_exists('ensure_audit_logs_table')) {
            ensure_audit_logs_table($conn);
        }

        $conn->query(
            "CREATE TABLE IF NOT EXISTS user_notification_state (
                user_id INT NOT NULL PRIMARY KEY,
                last_seen_audit_id BIGINT NOT NULL DEFAULT 0,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_user_notification_seen (last_seen_audit_id),
                CONSTRAINT fk_user_notification_state_user
                    FOREIGN KEY (user_id) REFERENCES users(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
}

if (!function_exists('notification_is_admin_role')) {
    function notification_is_admin_role($role) {
        return notification_normalize_role($role) === 'admin';
    }
}

if (!function_exists('notification_get_last_seen_id')) {
    function notification_get_last_seen_id(mysqli $conn, $userId) {
        notification_ensure_tables($conn);
        $userId = (int) $userId;
        if ($userId <= 0) return 0;

        $stmt = $conn->prepare(
            "SELECT last_seen_audit_id
             FROM user_notification_state
             WHERE user_id = ?
             LIMIT 1"
        );
        if (!$stmt) return 0;

        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $lastSeen = 0;
        if ($res && $res->num_rows === 1) {
            $row = $res->fetch_assoc();
            $lastSeen = (int) ($row['last_seen_audit_id'] ?? 0);
        }
        $stmt->close();
        return $lastSeen > 0 ? $lastSeen : 0;
    }
}

if (!function_exists('notification_set_last_seen_id')) {
    function notification_set_last_seen_id(mysqli $conn, $userId, $lastSeenAuditId) {
        notification_ensure_tables($conn);
        $userId = (int) $userId;
        $lastSeenAuditId = (int) $lastSeenAuditId;
        if ($userId <= 0) return false;
        if ($lastSeenAuditId < 0) $lastSeenAuditId = 0;

        $stmt = $conn->prepare(
            "INSERT INTO user_notification_state (user_id, last_seen_audit_id)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE
                last_seen_audit_id = GREATEST(last_seen_audit_id, VALUES(last_seen_audit_id))"
        );
        if (!$stmt) return false;

        $stmt->bind_param('ii', $userId, $lastSeenAuditId);
        $ok = false;
        try { $ok = $stmt->execute(); } catch (Throwable $e) { $ok = false; }
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('notification_actor_name')) {
    function notification_actor_name(array $row, $viewerUserId = 0) {
        $viewerUserId = (int) $viewerUserId;
        $actorId = (int) ($row['actor_user_id'] ?? 0);
        if ($viewerUserId > 0 && $actorId === $viewerUserId) return 'You';

        $fn = trim((string) ($row['first_name'] ?? ''));
        $ln = trim((string) ($row['last_name'] ?? ''));
        $full = trim($fn . ' ' . $ln);
        if ($full !== '') return $full;

        $username = trim((string) ($row['username'] ?? ''));
        if ($username !== '') return $username;

        return 'System';
    }
}

if (!function_exists('notification_humanize_action')) {
    function notification_humanize_action($action) {
        $action = trim((string) $action);
        if ($action === '') return 'Activity';

        static $map = [
            'auth.login' => 'Signed in',
            'auth.logout' => 'Signed out',
            'auth.session_timeout' => 'Session timed out',
            'auth.password.changed' => 'Password changed',
            'profile.change.requested' => 'Requested profile update',
            'profile.change.cancelled' => 'Cancelled profile update request',
            'profile.change.approved' => 'Approved profile update',
            'profile.change.rejected' => 'Rejected profile update',
            'enrollment.request.approved' => 'Approved enrollment request',
            'enrollment.request.denied' => 'Denied enrollment request',
            'enrollment.request.rejected' => 'Rejected enrollment request',
            'schedule.requested' => 'Submitted schedule request',
            'schedule.request.approved' => 'Approved schedule request',
            'schedule.request.rejected' => 'Rejected schedule request',
            'schedule.request.cancelled' => 'Cancelled schedule request',
            'schedule.slot.created' => 'Created schedule slot',
            'schedule.slot.deactivated' => 'Deactivated schedule slot',
            'campus.created' => 'Created campus',
            'campus.admin.assigned' => 'Assigned campus admin',
            'security.session_timeout.updated' => 'Updated session timeout',
            'message.sent' => 'Sent message',
            'message.ai.plan_ready' => 'AI draft ready',
            'message.ai.sent' => 'Sent AI message',
            'message.ai.executed' => 'Executed AI action',
            'attendance.geofence.denied' => 'Attendance denied by geofence',
            'attendance.geofence.policy.updated' => 'Updated attendance boundary policy',
        ];
        if (isset($map[$action])) return $map[$action];

        $label = str_replace(['.', '_', '-'], ' ', $action);
        $label = preg_replace('/\s+/', ' ', $label);
        $label = trim((string) $label);
        if ($label === '') return 'Activity';
        return ucwords($label);
    }
}

if (!function_exists('notification_entity_label')) {
    function notification_entity_label($entityType, $entityId) {
        $entityType = trim((string) $entityType);
        $entityId = (int) $entityId;
        if ($entityType === '') return '';
        if ($entityId > 0) return $entityType . '#' . $entityId;
        return $entityType;
    }
}

if (!function_exists('notification_trim_text')) {
    function notification_trim_text($value, $maxLen = 140) {
        $value = trim((string) $value);
        $maxLen = (int) $maxLen;
        if ($maxLen < 10) $maxLen = 10;
        if (strlen($value) <= $maxLen) return $value;
        return rtrim(substr($value, 0, $maxLen - 3)) . '...';
    }
}

if (!function_exists('notification_relative_time')) {
    function notification_relative_time($createdAt, $nowTs = null) {
        $createdAt = trim((string) $createdAt);
        $ts = strtotime($createdAt);
        if ($ts === false) return '';

        if ($nowTs === null) $nowTs = time();
        $nowTs = (int) $nowTs;
        $diff = $nowTs - (int) $ts;
        if ($diff < 0) $diff = 0;

        if ($diff < 60) return 'just now';
        if ($diff < 3600) {
            $m = (int) floor($diff / 60);
            return $m . ' min ago';
        }
        if ($diff < 86400) {
            $h = (int) floor($diff / 3600);
            return $h . ' hr ago';
        }
        if ($diff < 172800) return '1 day ago';
        if ($diff < 604800) {
            $d = (int) floor($diff / 86400);
            return $d . ' days ago';
        }
        return date('M j, Y', (int) $ts);
    }
}

if (!function_exists('notification_group_label')) {
    function notification_group_label($createdAt, $nowTs = null) {
        $createdAt = trim((string) $createdAt);
        $ts = strtotime($createdAt);
        if ($ts === false) return 'OLDER';

        if ($nowTs === null) $nowTs = time();
        $nowTs = (int) $nowTs;

        $today = date('Y-m-d', $nowTs);
        $yesterday = date('Y-m-d', strtotime('-1 day', $nowTs));
        $day = date('Y-m-d', (int) $ts);

        if ($day === $today) return 'TODAY';
        if ($day === $yesterday) return 'YESTERDAY';
        return strtoupper(date('j M Y', (int) $ts));
    }
}

if (!function_exists('notification_icon_for_action')) {
    function notification_icon_for_action($action) {
        $action = strtolower(trim((string) $action));

        if (strpos($action, 'auth.') === 0) {
            return ['icon' => 'ri-shield-user-line', 'bg' => 'bg-info'];
        }
        if (strpos($action, 'profile.') === 0) {
            return ['icon' => 'ri-account-circle-line', 'bg' => 'bg-warning'];
        }
        if (strpos($action, 'enrollment.') === 0) {
            return ['icon' => 'ri-graduation-cap-line', 'bg' => 'bg-success'];
        }
        if (strpos($action, 'schedule.') === 0) {
            return ['icon' => 'ri-calendar-check-line', 'bg' => 'bg-primary'];
        }
        if (strpos($action, 'message.') === 0) {
            return ['icon' => 'ri-message-2-line', 'bg' => 'bg-primary'];
        }
        if (strpos($action, 'campus.') === 0) {
            return ['icon' => 'ri-building-2-line', 'bg' => 'bg-secondary'];
        }
        if (strpos($action, 'attendance.') === 0) {
            return ['icon' => 'ri-map-pin-range-line', 'bg' => 'bg-warning'];
        }

        return ['icon' => 'ri-information-line', 'bg' => 'bg-secondary'];
    }
}

if (!function_exists('notification_fetch_raw_rows')) {
    function notification_fetch_raw_rows(mysqli $conn, $userId, $role, $limitPlusOne) {
        notification_ensure_tables($conn);

        $userId = (int) $userId;
        $limitPlusOne = (int) $limitPlusOne;
        if ($limitPlusOne < 1) $limitPlusOne = 1;
        if ($limitPlusOne > 200) $limitPlusOne = 200;

        $rows = [];
        $baseSql =
            "SELECT a.id, a.created_at, a.actor_user_id, a.actor_role, a.action, a.entity_type, a.entity_id, a.message,
                    u.username, u.first_name, u.last_name
             FROM audit_logs a
             LEFT JOIN users u ON u.id = a.actor_user_id
             WHERE a.action NOT LIKE 'notification.%'";

        if (notification_is_admin_role($role)) {
            $sql = $baseSql . " ORDER BY a.id DESC LIMIT " . $limitPlusOne;
            $res = $conn->query($sql);
            while ($res && ($row = $res->fetch_assoc())) $rows[] = $row;
            return $rows;
        }

        $sql = $baseSql . " AND a.actor_user_id = ? ORDER BY a.id DESC LIMIT " . $limitPlusOne;
        $stmt = $conn->prepare($sql);
        if (!$stmt) return $rows;
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) $rows[] = $row;
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('notification_get_unread_count')) {
    function notification_get_unread_count(mysqli $conn, $userId, $role, $lastSeenAuditId) {
        notification_ensure_tables($conn);
        $userId = (int) $userId;
        $lastSeenAuditId = (int) $lastSeenAuditId;
        if ($userId <= 0) return 0;

        if (notification_is_admin_role($role)) {
            $stmt = $conn->prepare(
                "SELECT COUNT(*) AS c
                 FROM audit_logs a
                 WHERE a.action NOT LIKE 'notification.%'
                   AND a.id > ?"
            );
            if (!$stmt) return 0;
            $stmt->bind_param('i', $lastSeenAuditId);
        } else {
            $stmt = $conn->prepare(
                "SELECT COUNT(*) AS c
                 FROM audit_logs a
                 WHERE a.action NOT LIKE 'notification.%'
                   AND a.actor_user_id = ?
                   AND a.id > ?"
            );
            if (!$stmt) return 0;
            $stmt->bind_param('ii', $userId, $lastSeenAuditId);
        }

        $stmt->execute();
        $res = $stmt->get_result();
        $count = 0;
        if ($res && $res->num_rows === 1) {
            $row = $res->fetch_assoc();
            $count = (int) ($row['c'] ?? 0);
        }
        $stmt->close();
        return $count > 0 ? $count : 0;
    }
}

if (!function_exists('notification_max_visible_audit_id')) {
    function notification_max_visible_audit_id(mysqli $conn, $userId, $role) {
        notification_ensure_tables($conn);
        $userId = (int) $userId;
        if ($userId <= 0) return 0;

        if (notification_is_admin_role($role)) {
            $res = $conn->query(
                "SELECT MAX(a.id) AS max_id
                 FROM audit_logs a
                 WHERE a.action NOT LIKE 'notification.%'"
            );
            if ($res && $res->num_rows === 1) {
                $row = $res->fetch_assoc();
                return (int) ($row['max_id'] ?? 0);
            }
            return 0;
        }

        $stmt = $conn->prepare(
            "SELECT MAX(a.id) AS max_id
             FROM audit_logs a
             WHERE a.action NOT LIKE 'notification.%'
               AND a.actor_user_id = ?"
        );
        if (!$stmt) return 0;
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $maxId = 0;
        if ($res && $res->num_rows === 1) {
            $row = $res->fetch_assoc();
            $maxId = (int) ($row['max_id'] ?? 0);
        }
        $stmt->close();
        return $maxId > 0 ? $maxId : 0;
    }
}

if (!function_exists('notification_mark_all_read')) {
    function notification_mark_all_read(mysqli $conn, $userId, $role) {
        $userId = (int) $userId;
        if ($userId <= 0) return 0;

        $maxId = notification_max_visible_audit_id($conn, $userId, $role);
        notification_set_last_seen_id($conn, $userId, $maxId);
        return $maxId;
    }
}

if (!function_exists('notification_fetch_for_user')) {
    function notification_fetch_for_user(mysqli $conn, $userId, $role, $limit = 12) {
        notification_ensure_tables($conn);
        $userId = (int) $userId;
        if ($userId <= 0) {
            return [
                'items' => [],
                'groups' => [],
                'unread_count' => 0,
                'has_more' => false,
                'last_seen_id' => 0,
            ];
        }

        $limit = (int) $limit;
        if ($limit < 1) $limit = 1;
        if ($limit > 50) $limit = 50;

        $lastSeenId = notification_get_last_seen_id($conn, $userId);
        $rows = notification_fetch_raw_rows($conn, $userId, $role, $limit + 1);
        $hasMore = count($rows) > $limit;
        if ($hasMore) array_pop($rows);

        $nowTs = time();
        $items = [];
        foreach ($rows as $row) {
            $action = trim((string) ($row['action'] ?? ''));
            $message = trim((string) ($row['message'] ?? ''));
            $actionLabel = notification_humanize_action($action);

            if ($message === '') {
                $entity = notification_entity_label($row['entity_type'] ?? '', $row['entity_id'] ?? null);
                $message = $actionLabel;
                if ($entity !== '') $message .= ' (' . $entity . ')';
            }

            $iconMeta = notification_icon_for_action($action);
            $id = (int) ($row['id'] ?? 0);

            $items[] = [
                'id' => $id,
                'title' => notification_actor_name($row, $userId),
                'subtitle' => notification_trim_text($message, 120),
                'action_label' => $actionLabel,
                'created_at' => (string) ($row['created_at'] ?? ''),
                'time_ago' => notification_relative_time($row['created_at'] ?? '', $nowTs),
                'group_label' => notification_group_label($row['created_at'] ?? '', $nowTs),
                'is_unread' => ($id > $lastSeenId),
                'icon' => (string) ($iconMeta['icon'] ?? 'ri-information-line'),
                'icon_bg' => (string) ($iconMeta['bg'] ?? 'bg-secondary'),
            ];
        }

        $groups = [];
        foreach ($items as $item) {
            $label = (string) ($item['group_label'] ?? 'OLDER');
            if (!isset($groups[$label])) $groups[$label] = [];
            $groups[$label][] = $item;
        }

        $grouped = [];
        foreach ($groups as $label => $groupItems) {
            $grouped[] = ['label' => $label, 'items' => $groupItems];
        }

        return [
            'items' => $items,
            'groups' => $grouped,
            'unread_count' => notification_get_unread_count($conn, $userId, $role, $lastSeenId),
            'has_more' => $hasMore,
            'last_seen_id' => $lastSeenId,
        ];
    }
}
