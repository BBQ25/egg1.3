<?php
// Compatibility wrapper:
// Teacher AI chat now uses the same shared AI credit pool as accomplishment features.
// Billing rule retained: 0.1 credit per 100 teacher-entered characters.

require_once __DIR__ . '/ai_credits.php';

if (!function_exists('ai_chat_credit_legacy_table_exists')) {
    function ai_chat_credit_legacy_table_exists(mysqli $conn) {
        $stmt = $conn->prepare(
            "SELECT 1
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'ai_chat_credits'
             LIMIT 1"
        );
        if (!$stmt) return false;
        $stmt->execute();
        $res = $stmt->get_result();
        $ok = ($res && $res->num_rows === 1);
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('ai_chat_credit_migrate_legacy_pool')) {
    function ai_chat_credit_migrate_legacy_pool(mysqli $conn) {
        static $migrated = false;
        if ($migrated) return;
        $migrated = true;

        if (!ai_chat_credit_legacy_table_exists($conn)) return;

        // Best-effort migration: keep the larger limit/usage values so no account loses effective allowance
        // when switching from the old dedicated chat wallet to the shared AI wallet.
        try {
            $conn->query(
                "UPDATE users u
                 JOIN ai_chat_credits c ON c.user_id = u.id
                 SET
                   u.ai_rephrase_credit_limit = GREATEST(ROUND(u.ai_rephrase_credit_limit, 2), ROUND(c.credit_limit, 2)),
                   u.ai_rephrase_credit_used = LEAST(
                       GREATEST(ROUND(u.ai_rephrase_credit_used, 2), ROUND(c.credit_used, 2)),
                       GREATEST(ROUND(u.ai_rephrase_credit_limit, 2), ROUND(c.credit_limit, 2))
                   )
                 WHERE u.role <> 'admin'"
            );
        } catch (Throwable $e) {
            // Non-fatal migration.
        }
    }
}

if (!function_exists('ai_chat_credit_default_limit')) {
    function ai_chat_credit_default_limit() {
        return (float) ai_credit_hard_default_limit();
    }
}

if (!function_exists('ai_chat_credit_round')) {
    function ai_chat_credit_round($value) {
        return ai_credit_round($value);
    }
}

if (!function_exists('ai_chat_credit_chars_len')) {
    function ai_chat_credit_chars_len($text) {
        $text = (string) $text;
        if (function_exists('mb_strlen')) return (int) mb_strlen($text, 'UTF-8');
        return (int) strlen($text);
    }
}

if (!function_exists('ai_chat_credit_cost_for_chars')) {
    function ai_chat_credit_cost_for_chars($charCount) {
        $charCount = (int) $charCount;
        if ($charCount <= 0) return 0.0;
        $units = (int) ceil(((float) $charCount) / 100.0);
        if ($units < 1) $units = 1;
        return ai_chat_credit_round($units * 0.1);
    }
}

if (!function_exists('ai_chat_credit_ensure_system')) {
    function ai_chat_credit_ensure_system(mysqli $conn) {
        ai_credit_ensure_system($conn);
        ai_chat_credit_migrate_legacy_pool($conn);
    }
}

if (!function_exists('ai_chat_credit_ensure_user_row')) {
    function ai_chat_credit_ensure_user_row(mysqli $conn, $userId) {
        // Shared pool lives in users.ai_rephrase_credit_* columns.
        // Nothing else to provision per user.
        $userId = (int) $userId;
        return $userId > 0;
    }
}

if (!function_exists('ai_chat_credit_get_user_status')) {
    /**
     * Returns [ok(bool), status(array)|message(string)].
     * status keys: limit, used, remaining, is_exempt
     */
    function ai_chat_credit_get_user_status(mysqli $conn, $userId) {
        return ai_credit_get_user_status($conn, $userId);
    }
}

if (!function_exists('ai_chat_credit_try_consume')) {
    /**
     * Returns [ok(bool), message(string)].
     */
    function ai_chat_credit_try_consume(mysqli $conn, $userId, $amount) {
        return ai_credit_try_consume_count($conn, $userId, $amount);
    }
}

if (!function_exists('ai_chat_credit_refund')) {
    function ai_chat_credit_refund(mysqli $conn, $userId, $amount) {
        return ai_credit_refund($conn, $userId, $amount);
    }
}
