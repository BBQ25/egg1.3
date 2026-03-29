<?php
// Simple 1:1 messaging helpers (threads + messages).

if (!function_exists('ensure_message_tables')) {
    function ensure_message_tables(mysqli $conn) {
        $conn->query(
            "CREATE TABLE IF NOT EXISTS message_threads (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_low_id INT NOT NULL,
                user_high_id INT NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_message_at TIMESTAMP NULL DEFAULT NULL,
                UNIQUE KEY uq_thread_pair (user_low_id, user_high_id),
                KEY idx_thread_last_message (last_message_at),
                CONSTRAINT fk_thread_low
                    FOREIGN KEY (user_low_id) REFERENCES users(id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_thread_high
                    FOREIGN KEY (user_high_id) REFERENCES users(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $conn->query(
            "CREATE TABLE IF NOT EXISTS message_messages (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                thread_id BIGINT NOT NULL,
                sender_id INT NOT NULL,
                body TEXT NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_msg_thread_time (thread_id, created_at),
                CONSTRAINT fk_msg_thread
                    FOREIGN KEY (thread_id) REFERENCES message_threads(id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_msg_sender
                    FOREIGN KEY (sender_id) REFERENCES users(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
}

if (!function_exists('message_thread_pair')) {
    function message_thread_pair($a, $b) {
        $a = (int) $a;
        $b = (int) $b;
        if ($a <= 0 || $b <= 0 || $a === $b) return [0, 0];
        return ($a < $b) ? [$a, $b] : [$b, $a];
    }
}

if (!function_exists('message_get_or_create_thread')) {
    function message_get_or_create_thread(mysqli $conn, $userA, $userB) {
        [$low, $high] = message_thread_pair($userA, $userB);
        if ($low <= 0 || $high <= 0) return 0;

        $threadId = 0;
        $find = $conn->prepare("SELECT id FROM message_threads WHERE user_low_id = ? AND user_high_id = ? LIMIT 1");
        if ($find) {
            $find->bind_param('ii', $low, $high);
            $find->execute();
            $res = $find->get_result();
            if ($res && $res->num_rows === 1) $threadId = (int) ($res->fetch_assoc()['id'] ?? 0);
            $find->close();
        }
        if ($threadId > 0) return $threadId;

        $ins = $conn->prepare("INSERT INTO message_threads (user_low_id, user_high_id, last_message_at) VALUES (?, ?, NULL)");
        if (!$ins) return 0;
        $ins->bind_param('ii', $low, $high);
        try { $ins->execute(); } catch (Throwable $e) { /* ignore duplicates */ }
        $threadId = (int) $conn->insert_id;
        $ins->close();

        if ($threadId > 0) return $threadId;

        // If insert raced, re-read.
        $find2 = $conn->prepare("SELECT id FROM message_threads WHERE user_low_id = ? AND user_high_id = ? LIMIT 1");
        if ($find2) {
            $find2->bind_param('ii', $low, $high);
            $find2->execute();
            $res = $find2->get_result();
            if ($res && $res->num_rows === 1) $threadId = (int) ($res->fetch_assoc()['id'] ?? 0);
            $find2->close();
        }
        return $threadId;
    }
}

if (!function_exists('message_thread_has_user')) {
    function message_thread_has_user(mysqli $conn, $threadId, $userId) {
        $threadId = (int) $threadId;
        $userId = (int) $userId;
        if ($threadId <= 0 || $userId <= 0) return false;

        $ok = false;
        $stmt = $conn->prepare("SELECT 1 FROM message_threads WHERE id = ? AND (user_low_id = ? OR user_high_id = ?) LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('iii', $threadId, $userId, $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            $ok = ($res && $res->num_rows === 1);
            $stmt->close();
        }
        return $ok;
    }
}

if (!function_exists('message_send')) {
    function message_send(mysqli $conn, $threadId, $senderId, $body) {
        $threadId = (int) $threadId;
        $senderId = (int) $senderId;
        $body = trim((string) $body);
        if ($threadId <= 0 || $senderId <= 0 || $body === '') return false;

        $stmt = $conn->prepare("INSERT INTO message_messages (thread_id, sender_id, body) VALUES (?, ?, ?)");
        if (!$stmt) return false;
        $stmt->bind_param('iis', $threadId, $senderId, $body);
        $ok = false;
        try { $ok = $stmt->execute(); } catch (Throwable $e) { $ok = false; }
        $stmt->close();

        if ($ok) {
            $upd = $conn->prepare("UPDATE message_threads SET last_message_at = CURRENT_TIMESTAMP WHERE id = ?");
            if ($upd) {
                $upd->bind_param('i', $threadId);
                try { $upd->execute(); } catch (Throwable $e) { /* ignore */ }
                $upd->close();
            }
        }

        return $ok;
    }
}

if (!function_exists('message_list_threads')) {
    function message_list_threads(mysqli $conn, $userId, $limit = 30) {
        $userId = (int) $userId;
        $limit = (int) $limit;
        if ($limit <= 0) $limit = 30;
        if ($limit > 200) $limit = 200;

        $rows = [];
        $sql =
            "SELECT t.id AS thread_id,
                    CASE WHEN t.user_low_id = ? THEN u2.id ELSE u1.id END AS other_user_id,
                    CASE WHEN t.user_low_id = ? THEN u2.username ELSE u1.username END AS other_username,
                    CASE WHEN t.user_low_id = ? THEN u2.first_name ELSE u1.first_name END AS other_first_name,
                    CASE WHEN t.user_low_id = ? THEN u2.last_name ELSE u1.last_name END AS other_last_name,
                    CASE WHEN t.user_low_id = ? THEN u2.profile_picture ELSE u1.profile_picture END AS other_profile_picture,
                    m.body AS last_body,
                    m.created_at AS last_at,
                    t.created_at AS thread_created_at
             FROM message_threads t
             JOIN users u1 ON u1.id = t.user_low_id
             JOIN users u2 ON u2.id = t.user_high_id
             LEFT JOIN message_messages m
                ON m.id = (
                    SELECT mm.id
                    FROM message_messages mm
                    WHERE mm.thread_id = t.id
                    ORDER BY mm.id DESC
                    LIMIT 1
                )
             WHERE t.user_low_id = ? OR t.user_high_id = ?
             ORDER BY COALESCE(m.created_at, t.last_message_at, t.created_at) DESC
             LIMIT " . $limit;

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('iiiiiii', $userId, $userId, $userId, $userId, $userId, $userId, $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res && ($r = $res->fetch_assoc())) $rows[] = $r;
            $stmt->close();
        }
        return $rows;
    }
}

if (!function_exists('message_list_thread_messages')) {
    function message_list_thread_messages(mysqli $conn, $threadId, $limit = 200) {
        $threadId = (int) $threadId;
        $limit = (int) $limit;
        if ($limit <= 0) $limit = 200;
        if ($limit > 1000) $limit = 1000;

        $rows = [];
        $sql =
            "SELECT m.id, m.sender_id, m.body, m.created_at,
                    u.username, u.first_name, u.last_name, u.profile_picture
             FROM message_messages m
             JOIN users u ON u.id = m.sender_id
             WHERE m.thread_id = ?
             ORDER BY m.id DESC
             LIMIT " . $limit;
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('i', $threadId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res && ($r = $res->fetch_assoc())) $rows[] = $r;
            $stmt->close();
        }

        // oldest -> newest
        return array_reverse($rows);
    }
}
