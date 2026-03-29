<?php
// Audit logging helpers (history, activity heatmap, admin audit).

if (!function_exists('ensure_audit_logs_table')) {
    function ensure_audit_logs_table(mysqli $conn) {
        // Keep schema simple and portable across MariaDB/MySQL versions.
        $conn->query(
            "CREATE TABLE IF NOT EXISTS audit_logs (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                actor_user_id INT NULL,
                actor_role VARCHAR(30) NULL,
                action VARCHAR(120) NOT NULL,
                entity_type VARCHAR(60) NULL,
                entity_id BIGINT NULL,
                message VARCHAR(255) NULL,
                metadata_json TEXT NULL,
                ip VARCHAR(45) NULL,
                user_agent VARCHAR(255) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_audit_actor_time (actor_user_id, created_at),
                KEY idx_audit_action_time (action, created_at),
                KEY idx_audit_entity (entity_type, entity_id),
                CONSTRAINT fk_audit_actor
                    FOREIGN KEY (actor_user_id) REFERENCES users(id)
                    ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
}

if (!function_exists('audit_log')) {
    /**
     * Write an audit log row. Best-effort (never throws).
     */
    function audit_log(mysqli $conn, $action, $entityType = null, $entityId = null, $message = null, array $metadata = []) {
        try {
            ensure_audit_logs_table($conn);
        } catch (Throwable $e) {
            // Non-fatal: if schema differs, do not break the request.
            return;
        }

        $actorId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        $actorRole = isset($_SESSION['user_role']) ? (string) $_SESSION['user_role'] : null;
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : null;
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : null;

        $action = trim((string) $action);
        if ($action === '') return;

        $entityType = $entityType !== null ? trim((string) $entityType) : null;
        $entityId = $entityId !== null ? (int) $entityId : null;
        $message = $message !== null ? trim((string) $message) : null;

        $metaJson = null;
        if (!empty($metadata)) {
            $json = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($json)) $metaJson = $json;
        }

        $stmt = $conn->prepare(
            "INSERT INTO audit_logs (actor_user_id, actor_role, action, entity_type, entity_id, message, metadata_json, ip, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) return;

        // actor_user_id and entity_id are nullable.
        $actorIdParam = $actorId > 0 ? $actorId : null;
        $entityIdParam = $entityId !== null && $entityId > 0 ? $entityId : null;

        $stmt->bind_param(
            'isssissss',
            $actorIdParam,
            $actorRole,
            $action,
            $entityType,
            $entityIdParam,
            $message,
            $metaJson,
            $ip,
            $ua
        );

        try { $stmt->execute(); } catch (Throwable $e) { /* ignore */ }
        $stmt->close();
    }
}

