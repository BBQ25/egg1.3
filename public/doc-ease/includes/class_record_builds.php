<?php
// Class Record Build helpers (teacher-owned grading structure templates).

if (!function_exists('db_has_column')) {
    function db_has_column(mysqli $conn, $table, $column) {
        $table = (string) $table;
        $column = (string) $column;
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

if (!function_exists('ensure_users_build_limit_column')) {
    function ensure_users_build_limit_column(mysqli $conn) {
        if (!db_has_column($conn, 'users', 'class_record_build_limit')) {
            // Default 4 builds per teacher unless Admin sets otherwise.
            // Keep it nullable? No: make enforcement simpler.
            $conn->query("ALTER TABLE users ADD COLUMN class_record_build_limit INT NOT NULL DEFAULT 4");
            return;
        }

        // Ensure default is 4 for new rows (do not overwrite existing per-user limits).
        try {
            $conn->query("ALTER TABLE users MODIFY class_record_build_limit INT NOT NULL DEFAULT 4");
        } catch (Throwable $e) {
            // Non-fatal: schema may differ across environments.
            error_log('[class_record_builds] ensure default build limit failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('ensure_section_grading_term')) {
    function ensure_section_grading_term(mysqli $conn) {
        if (!db_has_column($conn, 'section_grading_configs', 'term')) {
            $conn->query("ALTER TABLE section_grading_configs ADD COLUMN term ENUM('midterm','final') NOT NULL DEFAULT 'midterm' AFTER semester");
        }

        // Ensure the unique key includes term. Existing DB may have `unique_section_config` without term.
        $idx = [];
        $res = $conn->query("SHOW INDEX FROM section_grading_configs WHERE Key_name = 'unique_section_config'");
        if ($res) {
            while ($r = $res->fetch_assoc()) $idx[] = (string) ($r['Column_name'] ?? '');
        }

        $need = ['subject_id', 'course', 'year', 'section', 'academic_year', 'semester', 'term'];
        $hasAll = count(array_diff($need, $idx)) === 0;
        if (!$hasAll) {
            // Ensure an index exists for the FK on subject_id before dropping the unique index.
            // Some schemas use the unique index as the only index on subject_id, and MySQL will block dropping it.
            $hasSubjectIdxOther = false;
            $sr = $conn->query("SHOW INDEX FROM section_grading_configs WHERE Column_name = 'subject_id'");
            if ($sr) {
                while ($r = $sr->fetch_assoc()) {
                    $key = (string) ($r['Key_name'] ?? '');
                    if ($key !== '' && $key !== 'unique_section_config') { $hasSubjectIdxOther = true; break; }
                }
            }
            if (!$hasSubjectIdxOther) {
                $conn->query("ALTER TABLE section_grading_configs ADD KEY idx_section_grading_subject (subject_id)");
            }

            // Drop and recreate (best-effort).
            if (count($idx) > 0) {
                $conn->query("ALTER TABLE section_grading_configs DROP INDEX unique_section_config");
            }
            $conn->query("ALTER TABLE section_grading_configs ADD UNIQUE KEY unique_section_config (subject_id, course, year, section, academic_year, semester, term)");
        }
    }
}

if (!function_exists('ensure_class_record_build_tables')) {
    function ensure_class_record_build_tables(mysqli $conn) {
        // Header table: build ownership + metadata.
        $conn->query(
            "CREATE TABLE IF NOT EXISTS class_record_builds (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                teacher_id INT NOT NULL,
                name VARCHAR(120) NOT NULL,
                description TEXT NULL,
                status ENUM('active','inactive') NOT NULL DEFAULT 'active',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_builds_teacher (teacher_id),
                CONSTRAINT fk_builds_teacher
                    FOREIGN KEY (teacher_id) REFERENCES users(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // Parameters: major categories per term (Midterm/Final).
        $conn->query(
            "CREATE TABLE IF NOT EXISTS class_record_build_parameters (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                build_id INT NOT NULL,
                term ENUM('midterm','final') NOT NULL,
                name VARCHAR(100) NOT NULL,
                weight DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                display_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_build_term_param (build_id, term, name),
                KEY idx_params_build (build_id),
                CONSTRAINT fk_build_params_build
                    FOREIGN KEY (build_id) REFERENCES class_record_builds(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // Components: individual graded items under a parameter.
        $conn->query(
            "CREATE TABLE IF NOT EXISTS class_record_build_components (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                parameter_id INT NOT NULL,
                name VARCHAR(100) NOT NULL,
                code VARCHAR(50) NULL,
                component_type ENUM('quiz','assignment','project','exam','participation','other') NOT NULL DEFAULT 'other',
                weight DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                display_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_components_param (parameter_id),
                CONSTRAINT fk_build_components_param
                    FOREIGN KEY (parameter_id) REFERENCES class_record_build_parameters(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
}
