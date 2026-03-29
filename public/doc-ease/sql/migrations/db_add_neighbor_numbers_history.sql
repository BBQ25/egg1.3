CREATE TABLE IF NOT EXISTS neighbor_numbers_history (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
