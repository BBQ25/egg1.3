CREATE TABLE IF NOT EXISTS neighbor_numbers_settings (
    id TINYINT UNSIGNED NOT NULL,
    allowed_table_sizes_json TEXT NOT NULL,
    repeatable_sizes_json TEXT NOT NULL,
    updated_by INT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO neighbor_numbers_settings
    (id, allowed_table_sizes_json, repeatable_sizes_json, updated_by)
VALUES
    (1, '[3,4,5,6,7]', '[]', NULL);

CREATE TABLE IF NOT EXISTS neighbor_numbers_algo_snapshots (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
