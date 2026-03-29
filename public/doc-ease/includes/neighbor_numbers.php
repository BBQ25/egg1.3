<?php

if (!function_exists('nn_history_max_per_key')) {
    function nn_history_max_per_key() {
        // Keep a high cap so multi-year table histories (e.g., 3,357+ rows) remain visible for pagination.
        return 50000;
    }
}

if (!function_exists('nn_table_size_min')) {
    function nn_table_size_min() {
        return 2;
    }
}

if (!function_exists('nn_table_size_max')) {
    function nn_table_size_max() {
        return 20;
    }
}

if (!function_exists('nn_supported_algorithms')) {
    function nn_supported_algorithms() {
        return [
            'random_forest',
            'xgboost',
            'neural_network',
            'linear',
            'knn',
            'naive_bayes',
            'sma',
            'sgma',
        ];
    }
}

if (!function_exists('nn_supported_accuracy_styles')) {
    function nn_supported_accuracy_styles() {
        return [
            'hybrid',
            'balanced',
            'conservative',
            'momentum',
            'exploratory',
        ];
    }
}

if (!function_exists('nn_normalize_algorithm_key')) {
    function nn_normalize_algorithm_key($raw) {
        $algo = strtolower(trim((string) $raw));
        return in_array($algo, nn_supported_algorithms(), true) ? $algo : '';
    }
}

if (!function_exists('nn_normalize_accuracy_style_key')) {
    function nn_normalize_accuracy_style_key($raw) {
        $style = strtolower(trim((string) $raw));
        return in_array($style, nn_supported_accuracy_styles(), true) ? $style : 'hybrid';
    }
}

if (!function_exists('nn_settings_default_table_sizes')) {
    function nn_settings_default_table_sizes() {
        return [3, 4, 5, 6, 7];
    }
}

if (!function_exists('nn_settings_normalize_size_list')) {
    function nn_settings_normalize_size_list($raw) {
        $parts = [];
        if (is_array($raw)) {
            $parts = $raw;
        } else {
            $parts = preg_split('/[^0-9]+/', (string) $raw);
        }
        if (!is_array($parts)) $parts = [];

        $sizes = [];
        foreach ($parts as $part) {
            if (!is_numeric($part)) continue;
            $n = (int) $part;
            if ($n < nn_table_size_min() || $n > nn_table_size_max()) continue;
            $sizes[$n] = true;
        }
        $out = array_map('intval', array_keys($sizes));
        sort($out, SORT_NUMERIC);
        return $out;
    }
}

if (!function_exists('nn_settings_normalize_repeat_sizes')) {
    function nn_settings_normalize_repeat_sizes($raw, array $allowedSizes) {
        $allowedMap = [];
        foreach ($allowedSizes as $s) {
            $allowedMap[(int) $s] = true;
        }
        $candidate = nn_settings_normalize_size_list($raw);
        $out = [];
        foreach ($candidate as $size) {
            if (!isset($allowedMap[(int) $size])) continue;
            $out[] = (int) $size;
        }
        sort($out, SORT_NUMERIC);
        return array_values(array_unique($out));
    }
}

if (!function_exists('nn_settings_defaults')) {
    function nn_settings_defaults() {
        $allowed = nn_settings_default_table_sizes();
        return [
            'allowed_table_sizes' => $allowed,
            'repeatable_sizes' => [],
            'updated_by' => 0,
            'updated_at' => '',
        ];
    }
}

if (!function_exists('nn_settings_sanitize')) {
    function nn_settings_sanitize($rawSettings) {
        $defaults = nn_settings_defaults();
        $source = is_array($rawSettings) ? $rawSettings : [];

        $allowed = nn_settings_normalize_size_list($source['allowed_table_sizes'] ?? $source['allowed_sizes'] ?? '');
        if (count($allowed) < 1) {
            $allowed = $defaults['allowed_table_sizes'];
        }

        $repeat = nn_settings_normalize_repeat_sizes($source['repeatable_sizes'] ?? $source['repeat_sizes'] ?? '', $allowed);

        return [
            'allowed_table_sizes' => $allowed,
            'repeatable_sizes' => $repeat,
            'updated_by' => (int) ($source['updated_by'] ?? 0),
            'updated_at' => (string) ($source['updated_at'] ?? ''),
        ];
    }
}

if (!function_exists('nn_history_valid_bounds')) {
    function nn_history_valid_bounds($by, $minValue, $maxValue) {
        $by = (int) $by;
        $minValue = (int) $minValue;
        $maxValue = (int) $maxValue;
        if ($by < nn_table_size_min() || $by > nn_table_size_max()) return false;
        if ($minValue < 0 || $minValue > 55) return false;
        if ($maxValue < 0 || $maxValue > 55) return false;
        if ($minValue > $maxValue) return false;
        return true;
    }
}

if (!function_exists('nn_history_store_key')) {
    function nn_history_store_key($by, $minValue, $maxValue) {
        return 'b' . (int) $by . '_min' . (int) $minValue . '_max' . (int) $maxValue;
    }
}

if (!function_exists('nn_history_cfg_from_store_key')) {
    function nn_history_cfg_from_store_key($rawKey) {
        $rawKey = trim((string) $rawKey);
        if (!preg_match('/^b(\d+)_min(\d+)_max(\d+)$/i', $rawKey, $m)) return null;
        $by = (int) $m[1];
        $minValue = (int) $m[2];
        $maxValue = (int) $m[3];
        if (!nn_history_valid_bounds($by, $minValue, $maxValue)) return null;
        return [
            'by' => $by,
            'min' => $minValue,
            'max' => $maxValue,
            'store_key' => nn_history_store_key($by, $minValue, $maxValue),
        ];
    }
}

if (!function_exists('nn_history_parse_combo_string')) {
    function nn_history_parse_combo_string($raw) {
        $text = trim((string) $raw);
        if ($text === '') return [];
        $parts = preg_split('/[^0-9]+/', $text);
        if (!is_array($parts)) return [];
        $out = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '') continue;
            if (!is_numeric($part)) continue;
            $out[] = (int) $part;
        }
        return $out;
    }
}

if (!function_exists('nn_history_parse_timestamp_ms')) {
    function nn_history_parse_timestamp_ms($raw, $fallbackMs) {
        if (is_numeric($raw)) {
            $n = (int) $raw;
            if ($n > 0) return $n;
        }
        $text = trim((string) $raw);
        if ($text !== '') {
            $ts = strtotime($text);
            if ($ts !== false && $ts > 0) {
                return (int) ($ts * 1000);
            }
        }
        return (int) $fallbackMs;
    }
}

if (!function_exists('nn_history_sanitize_combo')) {
    function nn_history_sanitize_combo($comboRaw, array $cfg) {
        if (is_string($comboRaw)) {
            $comboRaw = nn_history_parse_combo_string($comboRaw);
        }
        if (!is_array($comboRaw)) return null;

        $by = (int) ($cfg['by'] ?? 0);
        $minValue = (int) ($cfg['min'] ?? 0);
        $maxValue = (int) ($cfg['max'] ?? 0);
        if ($by < nn_table_size_min() || $by > nn_table_size_max()) return null;

        $out = [];
        foreach ($comboRaw as $value) {
            if (!is_numeric($value)) continue;
            $n = (int) $value;
            if ($n < $minValue || $n > $maxValue) continue;
            $out[] = $n;
        }
        if (count($out) !== $by) return null;
        sort($out, SORT_NUMERIC);
        return $out;
    }
}

if (!function_exists('nn_history_sanitize_entries')) {
    function nn_history_sanitize_entries($rawEntries, array $cfg) {
        $entries = is_array($rawEntries) ? $rawEntries : [];
        $cleaned = [];
        $skipped = 0;
        $baseMs = (int) floor(microtime(true) * 1000);

        foreach ($entries as $index => $entry) {
            $comboRaw = null;
            $tsRaw = null;

            if (is_array($entry)) {
                $comboRaw = $entry['combo'] ?? null;
                if ($comboRaw === null) {
                    if (array_key_exists('value_1', $entry)) {
                        $comboRaw = [];
                        for ($i = 1; $i <= (int) $cfg['by']; $i++) {
                            $comboRaw[] = $entry['value_' . $i] ?? null;
                        }
                    } elseif (array_key_exists('values', $entry) && is_array($entry['values'])) {
                        $comboRaw = $entry['values'];
                    }
                }
                $tsRaw = $entry['ts'] ?? $entry['timestamp_ms'] ?? $entry['timestamp_iso'] ?? $entry['timestamp'] ?? null;
            } elseif (is_string($entry)) {
                $comboRaw = $entry;
            }

            $combo = nn_history_sanitize_combo($comboRaw, $cfg);
            if (!is_array($combo)) {
                $skipped++;
                continue;
            }

            $fallbackTs = $baseMs + (int) $index;
            $ts = nn_history_parse_timestamp_ms($tsRaw, $fallbackTs);
            $cleaned[] = [
                'ts' => $ts,
                'combo' => $combo,
            ];
        }

        $maxPerKey = nn_history_max_per_key();
        if (count($cleaned) > $maxPerKey) {
            $cleaned = array_slice($cleaned, -$maxPerKey);
        }

        return ['cleaned' => $cleaned, 'skipped' => $skipped];
    }
}

if (!function_exists('nn_history_build_summary')) {
    function nn_history_build_summary(array $store, $skippedKeys = 0, $skippedRows = 0) {
        $keyCount = 0;
        $entryCount = 0;
        foreach ($store as $key => $entries) {
            if (!is_array($entries) || count($entries) < 1) continue;
            $keyCount++;
            $entryCount += count($entries);
        }
        return [
            'store' => $store,
            'key_count' => $keyCount,
            'entry_count' => $entryCount,
            'skipped_keys' => (int) $skippedKeys,
            'skipped_rows' => (int) $skippedRows,
        ];
    }
}

if (!function_exists('nn_history_sanitize_store')) {
    function nn_history_sanitize_store($rawStore) {
        $source = [];
        if (is_array($rawStore)) {
            if (isset($rawStore['keys']) && is_array($rawStore['keys'])) {
                $source = $rawStore['keys'];
            } else {
                $source = $rawStore;
            }
        }

        $cleanStore = [];
        $skippedKeys = 0;
        $skippedRows = 0;

        foreach ($source as $rawKey => $rawEntries) {
            $cfg = nn_history_cfg_from_store_key($rawKey);
            if (!is_array($cfg)) {
                $skippedKeys++;
                continue;
            }

            $sanitized = nn_history_sanitize_entries($rawEntries, $cfg);
            $skippedRows += (int) ($sanitized['skipped'] ?? 0);
            $cleaned = (array) ($sanitized['cleaned'] ?? []);
            if (count($cleaned) < 1) continue;

            $cleanStore[$cfg['store_key']] = $cleaned;
        }

        return nn_history_build_summary($cleanStore, $skippedKeys, $skippedRows);
    }
}

if (!function_exists('nn_settings_ensure_table')) {
    function nn_settings_ensure_table(mysqli $conn) {
        static $done = false;
        if ($done) return;
        $done = true;

        $conn->query(
            "CREATE TABLE IF NOT EXISTS neighbor_numbers_settings (
                id TINYINT UNSIGNED NOT NULL,
                allowed_table_sizes_json TEXT NOT NULL,
                repeatable_sizes_json TEXT NOT NULL,
                updated_by INT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $defaults = nn_settings_defaults();
        $allowedJson = json_encode($defaults['allowed_table_sizes'], JSON_UNESCAPED_SLASHES);
        $repeatJson = json_encode($defaults['repeatable_sizes'], JSON_UNESCAPED_SLASHES);
        if (!is_string($allowedJson) || $allowedJson === '') $allowedJson = '[3,4,5,6,7]';
        if (!is_string($repeatJson) || $repeatJson === '') $repeatJson = '[]';

        $stmt = $conn->prepare(
            "INSERT IGNORE INTO neighbor_numbers_settings
                (id, allowed_table_sizes_json, repeatable_sizes_json, updated_by)
             VALUES (1, ?, ?, NULL)"
        );
        if ($stmt) {
            $stmt->bind_param('ss', $allowedJson, $repeatJson);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('nn_settings_load')) {
    function nn_settings_load(mysqli $conn) {
        nn_settings_ensure_table($conn);
        $defaults = nn_settings_defaults();

        $stmt = $conn->prepare(
            "SELECT allowed_table_sizes_json, repeatable_sizes_json, updated_by, updated_at
             FROM neighbor_numbers_settings
             WHERE id = 1
             LIMIT 1"
        );
        if (!$stmt) return $defaults;

        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!is_array($row)) return $defaults;

        $allowedRaw = json_decode((string) ($row['allowed_table_sizes_json'] ?? ''), true);
        $repeatRaw = json_decode((string) ($row['repeatable_sizes_json'] ?? ''), true);
        $settings = nn_settings_sanitize([
            'allowed_table_sizes' => is_array($allowedRaw) ? $allowedRaw : $defaults['allowed_table_sizes'],
            'repeatable_sizes' => is_array($repeatRaw) ? $repeatRaw : [],
            'updated_by' => (int) ($row['updated_by'] ?? 0),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ]);
        return $settings;
    }
}

if (!function_exists('nn_settings_save')) {
    function nn_settings_save(mysqli $conn, $rawSettings, $updatedByUserId) {
        nn_settings_ensure_table($conn);
        $settings = nn_settings_sanitize($rawSettings);
        $updatedByUserId = (int) $updatedByUserId;
        if ($updatedByUserId <= 0) $updatedByUserId = 0;

        $allowedJson = json_encode(array_values($settings['allowed_table_sizes']), JSON_UNESCAPED_SLASHES);
        $repeatJson = json_encode(array_values($settings['repeatable_sizes']), JSON_UNESCAPED_SLASHES);
        if (!is_string($allowedJson) || $allowedJson === '') $allowedJson = '[3,4,5,6,7]';
        if (!is_string($repeatJson) || $repeatJson === '') $repeatJson = '[]';

        $stmt = $conn->prepare(
            "INSERT INTO neighbor_numbers_settings (id, allowed_table_sizes_json, repeatable_sizes_json, updated_by)
             VALUES (1, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                allowed_table_sizes_json = VALUES(allowed_table_sizes_json),
                repeatable_sizes_json = VALUES(repeatable_sizes_json),
                updated_by = VALUES(updated_by)"
        );
        if (!$stmt) {
            return [false, nn_settings_load($conn), 'Unable to prepare settings update.'];
        }

        $stmt->bind_param('ssi', $allowedJson, $repeatJson, $updatedByUserId);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) {
            return [false, nn_settings_load($conn), 'Unable to save settings.'];
        }
        return [true, nn_settings_load($conn), ''];
    }
}

if (!function_exists('nn_snapshot_ensure_table')) {
    function nn_snapshot_ensure_table(mysqli $conn) {
        static $done = false;
        if ($done) return;
        $done = true;

        $conn->query(
            "CREATE TABLE IF NOT EXISTS neighbor_numbers_algo_snapshots (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT NOT NULL,
                table_by TINYINT UNSIGNED NOT NULL,
                min_value TINYINT UNSIGNED NOT NULL,
                max_value TINYINT UNSIGNED NOT NULL,
                accuracy_style VARCHAR(32) NOT NULL,
                history_signature VARCHAR(255) NOT NULL,
                algorithm VARCHAR(48) NOT NULL,
                snapshot_json TEXT NOT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_nnas_user_key_style_algo (user_id, table_by, min_value, max_value, accuracy_style, algorithm),
                KEY idx_nnas_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
}

if (!function_exists('nn_snapshot_sanitize_top_five')) {
    function nn_snapshot_sanitize_top_five($raw, array $cfg) {
        if (!is_array($raw)) return [];
        $out = [];
        foreach ($raw as $item) {
            if (!is_array($item)) continue;
            $n = isset($item['number']) ? (int) $item['number'] : null;
            if (!is_int($n)) continue;
            if ($n < (int) $cfg['min'] || $n > (int) $cfg['max']) continue;
            $score = isset($item['score']) && is_numeric($item['score']) ? (float) $item['score'] : 0.0;
            if ($score < 0) $score = 0.0;
            $out[] = [
                'number' => $n,
                'score' => $score,
            ];
            if (count($out) >= 5) break;
        }
        return $out;
    }
}

if (!function_exists('nn_snapshot_sanitize_payload')) {
    function nn_snapshot_sanitize_payload($rawSnapshots, array $cfg) {
        $source = is_array($rawSnapshots) ? $rawSnapshots : [];
        $out = [];
        foreach (nn_supported_algorithms() as $algo) {
            $raw = $source[$algo] ?? null;
            if (!is_array($raw)) continue;

            $combo = nn_history_sanitize_combo($raw['combo'] ?? null, $cfg);
            if (!is_array($combo)) continue;

            $topFive = nn_snapshot_sanitize_top_five($raw['top_five'] ?? $raw['topFive'] ?? [], $cfg);
            $hitRate = null;
            if (isset($raw['hit_rate']) && is_numeric($raw['hit_rate'])) {
                $hitRate = (float) $raw['hit_rate'];
            } elseif (isset($raw['hitRate']) && is_numeric($raw['hitRate'])) {
                $hitRate = (float) $raw['hitRate'];
            }
            if ($hitRate !== null) {
                if ($hitRate < 0) $hitRate = 0.0;
                if ($hitRate > 1) $hitRate = 1.0;
            }

            $formula = trim((string) ($raw['formula'] ?? ''));
            if (strlen($formula) > 220) {
                $formula = substr($formula, 0, 220);
            }

            $computedAt = nn_history_parse_timestamp_ms($raw['computed_at_ms'] ?? $raw['computedAt'] ?? null, (int) floor(microtime(true) * 1000));

            $out[$algo] = [
                'algorithm' => $algo,
                'hit_rate' => $hitRate,
                'combo' => $combo,
                'top_five' => $topFive,
                'formula' => $formula,
                'computed_at_ms' => $computedAt,
            ];
        }
        return $out;
    }
}

if (!function_exists('nn_snapshot_save_user_key_style')) {
    function nn_snapshot_save_user_key_style(mysqli $conn, $userId, array $cfg, $accuracyStyle, $historySignature, $rawSnapshots) {
        nn_snapshot_ensure_table($conn);
        $userId = (int) $userId;
        if ($userId <= 0) return [false, 0, 'Invalid user session.'];

        $style = nn_normalize_accuracy_style_key($accuracyStyle);
        $historySignature = trim((string) $historySignature);
        if ($historySignature === '') $historySignature = '0';
        if (strlen($historySignature) > 255) {
            $historySignature = substr($historySignature, 0, 255);
        }

        $snapshots = nn_snapshot_sanitize_payload($rawSnapshots, $cfg);
        $saved = 0;

        $conn->begin_transaction();
        try {
            $deleteStmt = $conn->prepare(
                "DELETE FROM neighbor_numbers_algo_snapshots
                 WHERE user_id = ? AND table_by = ? AND min_value = ? AND max_value = ? AND accuracy_style = ?"
            );
            if (!$deleteStmt) throw new RuntimeException('Unable to prepare snapshot cleanup.');

            $tableBy = (int) $cfg['by'];
            $minValue = (int) $cfg['min'];
            $maxValue = (int) $cfg['max'];
            $deleteStmt->bind_param('iiiis', $userId, $tableBy, $minValue, $maxValue, $style);
            if (!$deleteStmt->execute()) {
                $deleteStmt->close();
                throw new RuntimeException('Unable to clear existing snapshots.');
            }
            $deleteStmt->close();

            if (count($snapshots) > 0) {
                $insertStmt = $conn->prepare(
                    "INSERT INTO neighbor_numbers_algo_snapshots
                        (user_id, table_by, min_value, max_value, accuracy_style, history_signature, algorithm, snapshot_json)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );
                if (!$insertStmt) throw new RuntimeException('Unable to prepare snapshot insert.');

                foreach ($snapshots as $algo => $snapshot) {
                    $algoKey = nn_normalize_algorithm_key($algo);
                    if ($algoKey === '') continue;
                    $snapshotJson = json_encode($snapshot, JSON_UNESCAPED_SLASHES);
                    if (!is_string($snapshotJson) || $snapshotJson === '') continue;
                    $insertStmt->bind_param(
                        'iiiissss',
                        $userId,
                        $tableBy,
                        $minValue,
                        $maxValue,
                        $style,
                        $historySignature,
                        $algoKey,
                        $snapshotJson
                    );
                    if (!$insertStmt->execute()) {
                        throw new RuntimeException('Unable to insert algorithm snapshot.');
                    }
                    $saved++;
                }
                $insertStmt->close();
            }

            $conn->commit();
            return [true, $saved, ''];
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $rollbackError) {}
            return [false, $saved, $e->getMessage()];
        }
    }
}

if (!function_exists('nn_snapshot_load_user')) {
    function nn_snapshot_load_user(mysqli $conn, $userId) {
        nn_snapshot_ensure_table($conn);
        $userId = (int) $userId;
        if ($userId <= 0) return [];

        $stmt = $conn->prepare(
            "SELECT table_by, min_value, max_value, accuracy_style, history_signature, algorithm, snapshot_json, updated_at
             FROM neighbor_numbers_algo_snapshots
             WHERE user_id = ?
             ORDER BY id ASC"
        );
        if (!$stmt) return [];

        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();

        $out = [];
        while ($res && ($row = $res->fetch_assoc())) {
            $cfg = nn_history_cfg_from_store_key(
                nn_history_store_key(
                    (int) ($row['table_by'] ?? 0),
                    (int) ($row['min_value'] ?? 0),
                    (int) ($row['max_value'] ?? 0)
                )
            );
            if (!is_array($cfg)) continue;

            $style = nn_normalize_accuracy_style_key($row['accuracy_style'] ?? 'hybrid');
            $algo = nn_normalize_algorithm_key($row['algorithm'] ?? '');
            if ($algo === '') continue;

            $bucketKey = $cfg['store_key'] . '|' . $style;
            if (!isset($out[$bucketKey]) || !is_array($out[$bucketKey])) {
                $out[$bucketKey] = [
                    'store_key' => $cfg['store_key'],
                    'table_by' => (int) $cfg['by'],
                    'min' => (int) $cfg['min'],
                    'max' => (int) $cfg['max'],
                    'accuracy_style' => $style,
                    'history_signature' => '',
                    'updated_at_ms' => 0,
                    'algorithms' => [],
                ];
            }

            $parsed = json_decode((string) ($row['snapshot_json'] ?? ''), true);
            if (!is_array($parsed)) continue;

            $updatedAtMs = 0;
            $updatedAtRaw = (string) ($row['updated_at'] ?? '');
            if ($updatedAtRaw !== '') {
                $ts = strtotime($updatedAtRaw);
                if ($ts !== false && $ts > 0) {
                    $updatedAtMs = (int) ($ts * 1000);
                }
            }

            $out[$bucketKey]['algorithms'][$algo] = $parsed;
            $out[$bucketKey]['history_signature'] = (string) ($row['history_signature'] ?? '');
            if ($updatedAtMs > (int) ($out[$bucketKey]['updated_at_ms'] ?? 0)) {
                $out[$bucketKey]['updated_at_ms'] = $updatedAtMs;
            }
        }
        $stmt->close();
        return $out;
    }
}

if (!function_exists('nn_history_ensure_table')) {
    function nn_history_ensure_table(mysqli $conn) {
        static $done = false;
        if ($done) return;
        $done = true;

        $conn->query(
            "CREATE TABLE IF NOT EXISTS neighbor_numbers_history (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT NOT NULL,
                table_by TINYINT UNSIGNED NOT NULL,
                min_value TINYINT UNSIGNED NOT NULL,
                max_value TINYINT UNSIGNED NOT NULL,
                entry_ts_ms BIGINT UNSIGNED NOT NULL,
                combo_json VARCHAR(255) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_nnh_user_key_ts (user_id, table_by, min_value, max_value, entry_ts_ms),
                KEY idx_nnh_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
}

if (!function_exists('nn_history_load_user_store')) {
    function nn_history_load_user_store(mysqli $conn, $userId) {
        nn_history_ensure_table($conn);
        $userId = (int) $userId;
        if ($userId <= 0) return nn_history_build_summary([], 0, 0);

        $store = [];
        $skippedRows = 0;

        $stmt = $conn->prepare(
            "SELECT table_by, min_value, max_value, entry_ts_ms, combo_json
             FROM neighbor_numbers_history
             WHERE user_id = ?
             ORDER BY id ASC"
        );
        if (!$stmt) return nn_history_build_summary([], 0, 0);

        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($res && ($row = $res->fetch_assoc())) {
            $by = (int) ($row['table_by'] ?? 0);
            $minValue = (int) ($row['min_value'] ?? 0);
            $maxValue = (int) ($row['max_value'] ?? 0);
            if (!nn_history_valid_bounds($by, $minValue, $maxValue)) {
                $skippedRows++;
                continue;
            }

            $cfg = [
                'by' => $by,
                'min' => $minValue,
                'max' => $maxValue,
                'store_key' => nn_history_store_key($by, $minValue, $maxValue),
            ];

            $comboRaw = json_decode((string) ($row['combo_json'] ?? ''), true);
            if (!is_array($comboRaw)) {
                $comboRaw = nn_history_parse_combo_string((string) ($row['combo_json'] ?? ''));
            }
            $combo = nn_history_sanitize_combo($comboRaw, $cfg);
            if (!is_array($combo)) {
                $skippedRows++;
                continue;
            }

            $ts = (int) ($row['entry_ts_ms'] ?? 0);
            if ($ts <= 0) {
                $ts = (int) floor(microtime(true) * 1000);
            }

            $key = $cfg['store_key'];
            if (!isset($store[$key]) || !is_array($store[$key])) $store[$key] = [];
            $store[$key][] = [
                'ts' => $ts,
                'combo' => $combo,
            ];
        }
        $stmt->close();

        $maxPerKey = nn_history_max_per_key();
        foreach ($store as $key => $entries) {
            if (!is_array($entries) || count($entries) < 1) {
                unset($store[$key]);
                continue;
            }
            if (count($entries) > $maxPerKey) {
                $store[$key] = array_slice($entries, -$maxPerKey);
            }
        }

        return nn_history_build_summary($store, 0, $skippedRows);
    }
}

if (!function_exists('nn_history_replace_user_store')) {
    function nn_history_replace_user_store(mysqli $conn, $userId, $rawStore) {
        nn_history_ensure_table($conn);
        $userId = (int) $userId;
        $summary = nn_history_sanitize_store($rawStore);
        $store = (array) ($summary['store'] ?? []);
        if ($userId <= 0) return [false, $summary, 'Invalid user session.'];

        $error = '';
        $ok = false;

        $conn->begin_transaction();
        try {
            $deleteStmt = $conn->prepare("DELETE FROM neighbor_numbers_history WHERE user_id = ?");
            if (!$deleteStmt) {
                throw new RuntimeException('Unable to prepare history reset.');
            }
            $deleteStmt->bind_param('i', $userId);
            if (!$deleteStmt->execute()) {
                $deleteStmt->close();
                throw new RuntimeException('Unable to clear existing history.');
            }
            $deleteStmt->close();

            $insertStmt = $conn->prepare(
                "INSERT INTO neighbor_numbers_history
                    (user_id, table_by, min_value, max_value, entry_ts_ms, combo_json)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            if (!$insertStmt) {
                throw new RuntimeException('Unable to prepare history insert.');
            }

            foreach ($store as $storeKey => $entries) {
                $cfg = nn_history_cfg_from_store_key($storeKey);
                if (!is_array($cfg) || !is_array($entries)) continue;

                $by = (int) $cfg['by'];
                $minValue = (int) $cfg['min'];
                $maxValue = (int) $cfg['max'];

                foreach ($entries as $entry) {
                    if (!is_array($entry)) continue;
                    $combo = nn_history_sanitize_combo($entry['combo'] ?? null, $cfg);
                    if (!is_array($combo)) continue;
                    $ts = nn_history_parse_timestamp_ms($entry['ts'] ?? null, (int) floor(microtime(true) * 1000));
                    $tsText = (string) $ts;
                    $comboJson = json_encode(array_values($combo), JSON_UNESCAPED_SLASHES);
                    if (!is_string($comboJson) || $comboJson === '') continue;

                    $insertStmt->bind_param('iiiiss', $userId, $by, $minValue, $maxValue, $tsText, $comboJson);
                    if (!$insertStmt->execute()) {
                        throw new RuntimeException('Unable to insert history entry.');
                    }
                }
            }

            $insertStmt->close();
            $conn->commit();
            $ok = true;
        } catch (Throwable $e) {
            $error = $e->getMessage();
            try { $conn->rollback(); } catch (Throwable $rollbackError) {}
        }

        return [$ok, $summary, $error];
    }
}
