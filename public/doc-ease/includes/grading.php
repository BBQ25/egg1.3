<?php
// Grading helpers (assessments + score recording).

if (!function_exists('grading_db_has_column')) {
    function grading_db_has_column(mysqli $conn, $table, $column) {
        $table = (string) $table;
        $column = (string) $column;
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

if (!function_exists('ensure_grading_tables')) {
    function ensure_grading_tables(mysqli $conn) {
        // Assessment instances under a grading component (e.g. Quiz 1..n under "Quiz" component).
        $conn->query(
            "CREATE TABLE IF NOT EXISTS grading_assessments (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                grading_component_id INT NOT NULL,
                name VARCHAR(120) NOT NULL,
                max_score DECIMAL(8,2) NOT NULL DEFAULT 0.00,
                assessment_date DATE NULL,
                require_proof_upload TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                display_order INT NOT NULL DEFAULT 0,
                created_by INT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_ga_component (grading_component_id),
                CONSTRAINT fk_ga_component
                    FOREIGN KEY (grading_component_id) REFERENCES grading_components(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // Moodle-like assessment settings (best-effort schema evolution).
        if (!grading_db_has_column($conn, 'grading_assessments', 'assessment_mode')) {
            $conn->query("ALTER TABLE grading_assessments ADD COLUMN assessment_mode ENUM('manual','quiz') NOT NULL DEFAULT 'manual' AFTER assessment_date");
        }
        if (!grading_db_has_column($conn, 'grading_assessments', 'module_type')) {
            $conn->query("ALTER TABLE grading_assessments ADD COLUMN module_type VARCHAR(50) NOT NULL DEFAULT 'assessment' AFTER assessment_mode");
        }
        if (!grading_db_has_column($conn, 'grading_assessments', 'instructions')) {
            $conn->query("ALTER TABLE grading_assessments ADD COLUMN instructions TEXT NULL AFTER assessment_mode");
        }
        if (!grading_db_has_column($conn, 'grading_assessments', 'module_settings_json')) {
            $conn->query("ALTER TABLE grading_assessments ADD COLUMN module_settings_json LONGTEXT NULL AFTER instructions");
        }
        if (!grading_db_has_column($conn, 'grading_assessments', 'require_proof_upload')) {
            $conn->query("ALTER TABLE grading_assessments ADD COLUMN require_proof_upload TINYINT(1) NOT NULL DEFAULT 0 AFTER module_settings_json");
        }
        if (!grading_db_has_column($conn, 'grading_assessments', 'open_at')) {
            $conn->query("ALTER TABLE grading_assessments ADD COLUMN open_at DATETIME NULL AFTER instructions");
        }
        if (!grading_db_has_column($conn, 'grading_assessments', 'close_at')) {
            $conn->query("ALTER TABLE grading_assessments ADD COLUMN close_at DATETIME NULL AFTER open_at");
        }
        if (!grading_db_has_column($conn, 'grading_assessments', 'time_limit_minutes')) {
            $conn->query("ALTER TABLE grading_assessments ADD COLUMN time_limit_minutes INT NULL AFTER close_at");
        }
        if (!grading_db_has_column($conn, 'grading_assessments', 'attempts_allowed')) {
            $conn->query("ALTER TABLE grading_assessments ADD COLUMN attempts_allowed INT NOT NULL DEFAULT 1 AFTER time_limit_minutes");
        }
        if (!grading_db_has_column($conn, 'grading_assessments', 'grading_method')) {
            $conn->query("ALTER TABLE grading_assessments ADD COLUMN grading_method ENUM('highest','average','first','last') NOT NULL DEFAULT 'highest' AFTER attempts_allowed");
        }
        if (!grading_db_has_column($conn, 'grading_assessments', 'shuffle_questions')) {
            $conn->query("ALTER TABLE grading_assessments ADD COLUMN shuffle_questions TINYINT(1) NOT NULL DEFAULT 0 AFTER grading_method");
        }
        if (!grading_db_has_column($conn, 'grading_assessments', 'shuffle_choices')) {
            $conn->query("ALTER TABLE grading_assessments ADD COLUMN shuffle_choices TINYINT(1) NOT NULL DEFAULT 0 AFTER shuffle_questions");
        }
        if (!grading_db_has_column($conn, 'grading_assessments', 'questions_per_page')) {
            $conn->query("ALTER TABLE grading_assessments ADD COLUMN questions_per_page INT NULL AFTER shuffle_choices");
        }
        if (!grading_db_has_column($conn, 'grading_assessments', 'navigation_method')) {
            $conn->query("ALTER TABLE grading_assessments ADD COLUMN navigation_method ENUM('free','sequential') NOT NULL DEFAULT 'free' AFTER questions_per_page");
        }
        if (!grading_db_has_column($conn, 'grading_assessments', 'require_password')) {
            $conn->query("ALTER TABLE grading_assessments ADD COLUMN require_password VARCHAR(191) NULL AFTER navigation_method");
        }
        if (!grading_db_has_column($conn, 'grading_assessments', 'review_show_response')) {
            $conn->query("ALTER TABLE grading_assessments ADD COLUMN review_show_response TINYINT(1) NOT NULL DEFAULT 1 AFTER require_password");
        }
        if (!grading_db_has_column($conn, 'grading_assessments', 'review_show_marks')) {
            $conn->query("ALTER TABLE grading_assessments ADD COLUMN review_show_marks TINYINT(1) NOT NULL DEFAULT 1 AFTER review_show_response");
        }
        if (!grading_db_has_column($conn, 'grading_assessments', 'review_show_correct_answers')) {
            $conn->query("ALTER TABLE grading_assessments ADD COLUMN review_show_correct_answers TINYINT(1) NOT NULL DEFAULT 1 AFTER review_show_marks");
        }
        if (!grading_db_has_column($conn, 'grading_assessments', 'grade_to_pass')) {
            $conn->query("ALTER TABLE grading_assessments ADD COLUMN grade_to_pass DECIMAL(8,2) NULL AFTER review_show_correct_answers");
        }
        if (!grading_db_has_column($conn, 'grading_assessments', 'overall_feedback_json')) {
            $conn->query("ALTER TABLE grading_assessments ADD COLUMN overall_feedback_json LONGTEXT NULL AFTER grade_to_pass");
        }
        if (!grading_db_has_column($conn, 'grading_assessments', 'safe_exam_mode')) {
            $conn->query("ALTER TABLE grading_assessments ADD COLUMN safe_exam_mode ENUM('off','recommended','required') NOT NULL DEFAULT 'off' AFTER overall_feedback_json");
        }
        if (!grading_db_has_column($conn, 'grading_assessments', 'safe_require_fullscreen')) {
            $conn->query("ALTER TABLE grading_assessments ADD COLUMN safe_require_fullscreen TINYINT(1) NOT NULL DEFAULT 0 AFTER safe_exam_mode");
        }
        if (!grading_db_has_column($conn, 'grading_assessments', 'safe_block_shortcuts')) {
            $conn->query("ALTER TABLE grading_assessments ADD COLUMN safe_block_shortcuts TINYINT(1) NOT NULL DEFAULT 0 AFTER safe_require_fullscreen");
        }
        if (!grading_db_has_column($conn, 'grading_assessments', 'safe_auto_submit_on_blur')) {
            $conn->query("ALTER TABLE grading_assessments ADD COLUMN safe_auto_submit_on_blur TINYINT(1) NOT NULL DEFAULT 0 AFTER safe_block_shortcuts");
        }
        if (!grading_db_has_column($conn, 'grading_assessments', 'safe_blur_grace_seconds')) {
            $conn->query("ALTER TABLE grading_assessments ADD COLUMN safe_blur_grace_seconds INT NULL AFTER safe_auto_submit_on_blur");
        }
        if (!grading_db_has_column($conn, 'grading_assessments', 'access_lock_when_passed')) {
            $conn->query("ALTER TABLE grading_assessments ADD COLUMN access_lock_when_passed TINYINT(1) NOT NULL DEFAULT 0 AFTER safe_blur_grace_seconds");
        }
        if (!grading_db_has_column($conn, 'grading_assessments', 'access_cooldown_minutes')) {
            $conn->query("ALTER TABLE grading_assessments ADD COLUMN access_cooldown_minutes INT NULL AFTER access_lock_when_passed");
        }

        // Question bank per assessment (teacher-authored quiz items).
        $conn->query(
            "CREATE TABLE IF NOT EXISTS grading_assessment_questions (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                assessment_id INT NOT NULL,
                question_type ENUM('multiple_choice','true_false','short_answer') NOT NULL DEFAULT 'multiple_choice',
                question_text TEXT NOT NULL,
                options_json LONGTEXT NULL,
                answer_text TEXT NULL,
                default_mark DECIMAL(8,2) NOT NULL DEFAULT 1.00,
                display_order INT NOT NULL DEFAULT 0,
                is_required TINYINT(1) NOT NULL DEFAULT 1,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_by INT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_gaq_assessment (assessment_id),
                KEY idx_gaq_assessment_order (assessment_id, display_order, id),
                CONSTRAINT fk_gaq_assessment
                    FOREIGN KEY (assessment_id) REFERENCES grading_assessments(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // Student quiz attempts (per assessment and student).
        $conn->query(
            "CREATE TABLE IF NOT EXISTS grading_assessment_attempts (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                assessment_id INT NOT NULL,
                student_id INT NOT NULL,
                attempt_no INT NOT NULL DEFAULT 1,
                status ENUM('in_progress','submitted','autosubmitted','abandoned') NOT NULL DEFAULT 'in_progress',
                question_order_json LONGTEXT NULL,
                started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                submitted_at DATETIME NULL,
                time_limit_minutes INT NULL,
                score_raw DECIMAL(10,2) NULL,
                score_scaled DECIMAL(10,2) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_gaa_assessment_student_attempt (assessment_id, student_id, attempt_no),
                KEY idx_gaa_lookup (assessment_id, student_id, status),
                KEY idx_gaa_student (student_id),
                CONSTRAINT fk_gaa_assessment
                    FOREIGN KEY (assessment_id) REFERENCES grading_assessments(id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_gaa_student
                    FOREIGN KEY (student_id) REFERENCES students(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // Assignment-style submissions (1 active row per student per assessment).
        $conn->query(
            "CREATE TABLE IF NOT EXISTS grading_assignment_submissions (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                assessment_id INT NOT NULL,
                student_id INT NOT NULL,
                status ENUM('draft','submitted','graded') NOT NULL DEFAULT 'draft',
                submission_text LONGTEXT NULL,
                attempt_no INT NOT NULL DEFAULT 1,
                is_late TINYINT(1) NOT NULL DEFAULT 0,
                submitted_at DATETIME NULL,
                last_modified_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                graded_score DECIMAL(10,2) NULL,
                feedback_comment LONGTEXT NULL,
                graded_by INT NULL,
                graded_at DATETIME NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_gasub_assessment_student (assessment_id, student_id),
                KEY idx_gasub_assessment_status (assessment_id, status),
                KEY idx_gasub_student (student_id),
                CONSTRAINT fk_gasub_assessment
                    FOREIGN KEY (assessment_id) REFERENCES grading_assessments(id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_gasub_student
                    FOREIGN KEY (student_id) REFERENCES students(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // Files uploaded as part of assignment submissions.
        $conn->query(
            "CREATE TABLE IF NOT EXISTS grading_assignment_submission_files (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                submission_id INT NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                file_name VARCHAR(255) NOT NULL,
                file_path VARCHAR(500) NOT NULL,
                file_size BIGINT NOT NULL DEFAULT 0,
                mime_type VARCHAR(120) NULL,
                uploaded_by_role ENUM('student','teacher') NOT NULL DEFAULT 'student',
                uploaded_by INT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_gasf_submission (submission_id),
                CONSTRAINT fk_gasf_submission
                    FOREIGN KEY (submission_id) REFERENCES grading_assignment_submissions(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // Teacher-provided resource files for assignment modules.
        $conn->query(
            "CREATE TABLE IF NOT EXISTS grading_assignment_resources (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                assessment_id INT NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                file_name VARCHAR(255) NOT NULL,
                file_path VARCHAR(500) NOT NULL,
                file_size BIGINT NOT NULL DEFAULT 0,
                mime_type VARCHAR(120) NULL,
                uploaded_by INT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_gar_assessment (assessment_id),
                CONSTRAINT fk_gar_assessment
                    FOREIGN KEY (assessment_id) REFERENCES grading_assessments(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // Per-question responses per attempt.
        $conn->query(
            "CREATE TABLE IF NOT EXISTS grading_assessment_attempt_answers (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                attempt_id INT NOT NULL,
                question_id INT NOT NULL,
                response_text TEXT NULL,
                is_correct TINYINT(1) NULL,
                awarded_mark DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                max_mark DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_gaaa_attempt_question (attempt_id, question_id),
                KEY idx_gaaa_attempt (attempt_id),
                KEY idx_gaaa_question (question_id),
                CONSTRAINT fk_gaaa_attempt
                    FOREIGN KEY (attempt_id) REFERENCES grading_assessment_attempts(id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_gaaa_question
                    FOREIGN KEY (question_id) REFERENCES grading_assessment_questions(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // Scores per assessment per student (nullable score means "not recorded yet").
        $conn->query(
            "CREATE TABLE IF NOT EXISTS grading_assessment_scores (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                assessment_id INT NOT NULL,
                student_id INT NOT NULL,
                score DECIMAL(8,2) NULL,
                recorded_by INT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_assessment_student (assessment_id, student_id),
                KEY idx_gas_assessment (assessment_id),
                KEY idx_gas_student (student_id),
                CONSTRAINT fk_gas_assessment
                    FOREIGN KEY (assessment_id) REFERENCES grading_assessments(id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_gas_student
                    FOREIGN KEY (student_id) REFERENCES students(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
}

if (!function_exists('clamp_decimal')) {
    function clamp_decimal($value, $min, $max) {
        if (!is_numeric($value)) return $min;
        $v = (float) $value;
        if ($v < (float) $min) $v = (float) $min;
        if ($v > (float) $max) $v = (float) $max;
        return $v;
    }
}

if (!function_exists('grading_module_catalog')) {
    function grading_module_catalog() {
        return [
            'assessment' => [
                'label' => 'Assessment',
                'kind' => 'assessment',
                'gradeable' => 1,
                'description' => 'Standard assessment item recorded in the gradebook.',
            ],
            'assignment' => [
                'label' => 'Assignment',
                'kind' => 'activity',
                'gradeable' => 1,
                'description' => 'Collect student submissions and provide grades or feedback.',
            ],
            'book' => [
                'label' => 'Book',
                'kind' => 'resource',
                'gradeable' => 0,
                'description' => 'Multi-page learning material organized by chapters.',
            ],
            'choice' => [
                'label' => 'Choice',
                'kind' => 'activity',
                'gradeable' => 1,
                'description' => 'Single-question poll with selectable options.',
            ],
            'database' => [
                'label' => 'Database',
                'kind' => 'activity',
                'gradeable' => 1,
                'description' => 'Collaborative collection of structured entries.',
            ],
            'external_tool' => [
                'label' => 'External Tool',
                'kind' => 'activity',
                'gradeable' => 1,
                'description' => 'Launch third-party learning tools through external integration.',
            ],
            'feedback' => [
                'label' => 'Feedback',
                'kind' => 'activity',
                'gradeable' => 0,
                'description' => 'Collect survey responses using custom feedback forms.',
            ],
            'file' => [
                'label' => 'File',
                'kind' => 'resource',
                'gradeable' => 0,
                'description' => 'Share a downloadable file as a course resource.',
            ],
            'folder' => [
                'label' => 'Folder',
                'kind' => 'resource',
                'gradeable' => 0,
                'description' => 'Group related files into a single course resource.',
            ],
            'forum' => [
                'label' => 'Forum',
                'kind' => 'activity',
                'gradeable' => 1,
                'description' => 'Asynchronous discussions for class communication and interaction.',
            ],
            'glossary' => [
                'label' => 'Glossary',
                'kind' => 'activity',
                'gradeable' => 1,
                'description' => 'Collaborative list of terms, definitions, and references.',
            ],
            'h5p' => [
                'label' => 'H5P',
                'kind' => 'activity',
                'gradeable' => 1,
                'description' => 'Interactive HTML5 content such as quizzes, videos, and games.',
            ],
            'lesson' => [
                'label' => 'Lesson',
                'kind' => 'activity',
                'gradeable' => 1,
                'description' => 'Adaptive content flow with branching pages and questions.',
            ],
            'page' => [
                'label' => 'Page',
                'kind' => 'resource',
                'gradeable' => 0,
                'description' => 'Standalone content page with text and media.',
            ],
            'survey' => [
                'label' => 'Survey',
                'kind' => 'activity',
                'gradeable' => 0,
                'description' => 'Use predefined survey instruments for course insights.',
            ],
            'text_media_area' => [
                'label' => 'Text and Media Area',
                'kind' => 'resource',
                'gradeable' => 0,
                'description' => 'Inline text and media content displayed in the course section.',
            ],
            'url' => [
                'label' => 'URL',
                'kind' => 'resource',
                'gradeable' => 0,
                'description' => 'Link to external web content or online references.',
            ],
            'workshop' => [
                'label' => 'Workshop',
                'kind' => 'activity',
                'gradeable' => 1,
                'description' => 'Peer assessment workflow for submissions and reviews.',
            ],
        ];
    }
}

if (!function_exists('grading_normalize_module_type')) {
    function grading_normalize_module_type($raw, $fallback = 'assessment') {
        $type = strtolower(trim((string) $raw));
        $catalog = grading_module_catalog();
        if ($type !== '' && isset($catalog[$type])) return $type;
        $fallback = strtolower(trim((string) $fallback));
        return isset($catalog[$fallback]) ? $fallback : 'assessment';
    }
}

if (!function_exists('grading_module_info')) {
    function grading_module_info($moduleType) {
        $catalog = grading_module_catalog();
        $type = grading_normalize_module_type($moduleType, 'assessment');
        return $catalog[$type] ?? $catalog['assessment'];
    }
}

if (!function_exists('grading_module_label')) {
    function grading_module_label($moduleType) {
        $info = grading_module_info($moduleType);
        return (string) ($info['label'] ?? 'Assessment');
    }
}

if (!function_exists('grading_assignment_default_settings')) {
    function grading_assignment_default_settings() {
        return [
            'description' => '',
            'activity_instructions' => '',
            'due_at' => '',
            'remind_grade_by_at' => '',
            'always_show_description' => 1,
            'submission_online_text' => 1,
            'submission_file' => 1,
            'max_uploaded_files' => 20,
            'max_submission_size_mb' => 10,
            'accepted_file_types' => '',
            'feedback_comments' => 1,
            'feedback_files' => 0,
            'comment_inline' => 0,
            'require_submit_button' => 0,
            'require_accept_statement' => 0,
            'attempts_reopened' => 'never',
            'group_submission' => 0,
            'notify_graders_submission' => 0,
            'notify_graders_late' => 0,
            'default_notify_student' => 1,
            'grade_method' => 'simple',
            'grade_category' => '',
            'anonymous_submissions' => 0,
            'hide_grader_identity' => 0,
            'marking_workflow' => 0,
            'availability' => 'show',
            'id_number' => '',
            'force_language' => '',
            'group_mode' => 'no_groups',
            'completion_tracking' => 'manual',
            'expect_completed_on' => '',
            'tags' => '',
            'send_content_change_notification' => 0,
        ];
    }
}

if (!function_exists('grading_assignment_settings')) {
    function grading_assignment_settings($settingsRaw) {
        $defaults = grading_assignment_default_settings();
        $decoded = grading_decode_json_array((string) $settingsRaw);
        if (!is_array($decoded)) return $defaults;

        $boolKeys = [
            'always_show_description',
            'submission_online_text',
            'submission_file',
            'feedback_comments',
            'feedback_files',
            'comment_inline',
            'require_submit_button',
            'require_accept_statement',
            'group_submission',
            'notify_graders_submission',
            'notify_graders_late',
            'default_notify_student',
            'anonymous_submissions',
            'hide_grader_identity',
            'marking_workflow',
            'send_content_change_notification',
        ];

        $out = $defaults;
        foreach ($defaults as $k => $defaultVal) {
            if (!array_key_exists($k, $decoded)) continue;
            $val = $decoded[$k];
            if (in_array($k, $boolKeys, true)) {
                $out[$k] = (int) $val ? 1 : 0;
            } elseif (is_int($defaultVal)) {
                $out[$k] = is_numeric($val) ? (int) $val : (int) $defaultVal;
            } elseif (is_float($defaultVal)) {
                $out[$k] = is_numeric($val) ? (float) $val : $defaultVal;
            } else {
                $out[$k] = is_scalar($val) ? trim((string) $val) : $defaultVal;
            }
        }

        if (!in_array((string) $out['attempts_reopened'], ['never', 'manual', 'until_pass'], true)) {
            $out['attempts_reopened'] = 'never';
        }
        if (!in_array((string) $out['availability'], ['show', 'hide'], true)) {
            $out['availability'] = 'show';
        }
        if (!in_array((string) $out['group_mode'], ['no_groups', 'separate_groups', 'visible_groups'], true)) {
            $out['group_mode'] = 'no_groups';
        }
        if (!in_array((string) $out['completion_tracking'], ['none', 'manual', 'automatic'], true)) {
            $out['completion_tracking'] = 'manual';
        }
        if (!in_array((string) $out['grade_method'], ['simple', 'rubric', 'marking_guide'], true)) {
            $out['grade_method'] = 'simple';
        }

        $mf = (int) ($out['max_uploaded_files'] ?? 20);
        if ($mf < 1) $mf = 1;
        if ($mf > 50) $mf = 50;
        $out['max_uploaded_files'] = $mf;

        $ms = (int) ($out['max_submission_size_mb'] ?? 10);
        if ($ms < 1) $ms = 1;
        if ($ms > 200) $ms = 200;
        $out['max_submission_size_mb'] = $ms;

        return $out;
    }
}

if (!function_exists('grading_assignment_settings_json')) {
    function grading_assignment_settings_json(array $settings) {
        $base = grading_assignment_default_settings();
        $boolKeys = [
            'always_show_description',
            'submission_online_text',
            'submission_file',
            'feedback_comments',
            'feedback_files',
            'comment_inline',
            'require_submit_button',
            'require_accept_statement',
            'group_submission',
            'notify_graders_submission',
            'notify_graders_late',
            'default_notify_student',
            'anonymous_submissions',
            'hide_grader_identity',
            'marking_workflow',
            'send_content_change_notification',
        ];

        $clean = [];
        foreach ($base as $k => $defaultVal) {
            $val = array_key_exists($k, $settings) ? $settings[$k] : $defaultVal;
            if (in_array($k, $boolKeys, true)) {
                $clean[$k] = (int) $val ? 1 : 0;
            } elseif (is_int($defaultVal)) {
                $clean[$k] = is_numeric($val) ? (int) $val : (int) $defaultVal;
            } elseif (is_float($defaultVal)) {
                $clean[$k] = is_numeric($val) ? (float) $val : $defaultVal;
            } else {
                $clean[$k] = is_scalar($val) ? trim((string) $val) : $defaultVal;
            }
        }
        $json = json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($json) ? $json : json_encode($base, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

if (!function_exists('grading_datetime_input_to_mysql')) {
    function grading_datetime_input_to_mysql($value) {
        $value = trim((string) $value);
        if ($value === '') return '';
        $dt = DateTime::createFromFormat('Y-m-d\TH:i', $value);
        if (!$dt) return '';
        return $dt->format('Y-m-d H:i:s');
    }
}

if (!function_exists('grading_datetime_mysql_to_input')) {
    function grading_datetime_mysql_to_input($value) {
        $value = trim((string) $value);
        if ($value === '') return '';
        $ts = strtotime($value);
        if (!$ts) return '';
        return date('Y-m-d\TH:i', $ts);
    }
}

if (!function_exists('grading_decode_json_array')) {
    function grading_decode_json_array($raw) {
        if (!is_string($raw)) return [];
        $raw = trim($raw);
        if ($raw === '') return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('grading_attempt_final_statuses')) {
    function grading_attempt_final_statuses() {
        return ['submitted', 'autosubmitted'];
    }
}

if (!function_exists('grading_pick_assessment_score_from_attempts')) {
    function grading_pick_assessment_score_from_attempts(mysqli $conn, $assessmentId, $studentId, $gradingMethod = 'highest') {
        $assessmentId = (int) $assessmentId;
        $studentId = (int) $studentId;
        $gradingMethod = strtolower(trim((string) $gradingMethod));
        if (!in_array($gradingMethod, ['highest', 'average', 'first', 'last'], true)) {
            $gradingMethod = 'highest';
        }
        if ($assessmentId <= 0 || $studentId <= 0) return null;

        $score = null;
        if ($gradingMethod === 'highest') {
            $stmt = $conn->prepare(
                "SELECT MAX(score_scaled) AS picked
                 FROM grading_assessment_attempts
                 WHERE assessment_id = ?
                   AND student_id = ?
                   AND status IN ('submitted','autosubmitted')"
            );
        } elseif ($gradingMethod === 'average') {
            $stmt = $conn->prepare(
                "SELECT AVG(score_scaled) AS picked
                 FROM grading_assessment_attempts
                 WHERE assessment_id = ?
                   AND student_id = ?
                   AND status IN ('submitted','autosubmitted')"
            );
        } elseif ($gradingMethod === 'first') {
            $stmt = $conn->prepare(
                "SELECT score_scaled AS picked
                 FROM grading_assessment_attempts
                 WHERE assessment_id = ?
                   AND student_id = ?
                   AND status IN ('submitted','autosubmitted')
                 ORDER BY attempt_no ASC, id ASC
                 LIMIT 1"
            );
        } else {
            $stmt = $conn->prepare(
                "SELECT score_scaled AS picked
                 FROM grading_assessment_attempts
                 WHERE assessment_id = ?
                   AND student_id = ?
                   AND status IN ('submitted','autosubmitted')
                 ORDER BY submitted_at DESC, attempt_no DESC, id DESC
                 LIMIT 1"
            );
        }
        if (!$stmt) return null;

        $stmt->bind_param('ii', $assessmentId, $studentId);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows >= 1) {
            $row = $res->fetch_assoc();
            $picked = $row['picked'] ?? null;
            if ($picked !== null && is_numeric($picked)) $score = (float) $picked;
        }
        $stmt->close();
        return $score;
    }
}

if (!function_exists('grading_upsert_assessment_score')) {
    function grading_upsert_assessment_score(mysqli $conn, $assessmentId, $studentId, $score) {
        $assessmentId = (int) $assessmentId;
        $studentId = (int) $studentId;
        if ($assessmentId <= 0 || $studentId <= 0) return false;

        if ($score === null) {
            $up = $conn->prepare(
                "INSERT INTO grading_assessment_scores (assessment_id, student_id, score, recorded_by)
                 VALUES (?, ?, NULL, NULL)
                 ON DUPLICATE KEY UPDATE score = NULL, recorded_by = NULL, updated_at = CURRENT_TIMESTAMP"
            );
            if (!$up) return false;
            $up->bind_param('ii', $assessmentId, $studentId);
            $ok = $up->execute();
            $up->close();
            return (bool) $ok;
        }

        $v = round((float) $score, 2);
        $up = $conn->prepare(
            "INSERT INTO grading_assessment_scores (assessment_id, student_id, score, recorded_by)
             VALUES (?, ?, ?, NULL)
             ON DUPLICATE KEY UPDATE score = VALUES(score), recorded_by = NULL, updated_at = CURRENT_TIMESTAMP"
        );
        if (!$up) return false;
        $up->bind_param('iid', $assessmentId, $studentId, $v);
        $ok = $up->execute();
        $up->close();
        return (bool) $ok;
    }
}

if (!function_exists('grading_refresh_assessment_score_from_attempts')) {
    function grading_refresh_assessment_score_from_attempts(mysqli $conn, $assessmentId, $studentId, $gradingMethod = 'highest') {
        $picked = grading_pick_assessment_score_from_attempts($conn, $assessmentId, $studentId, $gradingMethod);
        $ok = grading_upsert_assessment_score($conn, $assessmentId, $studentId, $picked);
        return [$ok, $picked];
    }
}
