<?php
// Table of Specifications (TOS) and Test Questionnaire (TQS) helpers.

require_once __DIR__ . '/env_secrets.php';

if (!function_exists('ttq_db_has_column')) {
    function ttq_db_has_column(mysqli $conn, $table, $column) {
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

if (!function_exists('ttq_ensure_tables')) {
    function ttq_ensure_tables(mysqli $conn) {
        $conn->query(
            "CREATE TABLE IF NOT EXISTS tos_tqs_documents (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                teacher_id INT NOT NULL,
                class_record_id INT NULL,
                term ENUM('midterm','final','custom') NOT NULL DEFAULT 'custom',
                document_mode ENUM('combined','standalone') NOT NULL DEFAULT 'standalone',
                title VARCHAR(180) NOT NULL DEFAULT '',
                academic_year VARCHAR(40) NOT NULL DEFAULT '',
                semester VARCHAR(40) NOT NULL DEFAULT '',
                exam_end_at DATETIME NULL,
                exam_finished_at DATETIME NULL,
                student_answer_key_release TINYINT(1) NOT NULL DEFAULT 0,
                prepared_by_name VARCHAR(120) NOT NULL DEFAULT '',
                prepared_signature_path VARCHAR(255) NULL,
                approved_by_name VARCHAR(120) NOT NULL DEFAULT '',
                approved_signature_path VARCHAR(255) NULL,
                status ENUM('draft','pending_reapproval','approved') NOT NULL DEFAULT 'draft',
                version_no INT NOT NULL DEFAULT 1,
                approved_at DATETIME NULL,
                approved_by_user_id INT NULL,
                approval_note VARCHAR(255) NULL,
                content_json LONGTEXT NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_ttq_teacher (teacher_id),
                KEY idx_ttq_class_record (class_record_id),
                KEY idx_ttq_status (status),
                CONSTRAINT fk_ttq_teacher
                    FOREIGN KEY (teacher_id) REFERENCES users(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        if (!ttq_db_has_column($conn, 'tos_tqs_documents', 'class_record_id')) {
            $conn->query("ALTER TABLE tos_tqs_documents ADD COLUMN class_record_id INT NULL AFTER teacher_id");
        }
        if (!ttq_db_has_column($conn, 'tos_tqs_documents', 'term')) {
            $conn->query("ALTER TABLE tos_tqs_documents ADD COLUMN term ENUM('midterm','final','custom') NOT NULL DEFAULT 'custom' AFTER class_record_id");
        }
        if (!ttq_db_has_column($conn, 'tos_tqs_documents', 'document_mode')) {
            $conn->query("ALTER TABLE tos_tqs_documents ADD COLUMN document_mode ENUM('combined','standalone') NOT NULL DEFAULT 'standalone' AFTER term");
        }
        if (!ttq_db_has_column($conn, 'tos_tqs_documents', 'exam_end_at')) {
            $conn->query("ALTER TABLE tos_tqs_documents ADD COLUMN exam_end_at DATETIME NULL AFTER semester");
        }
        if (!ttq_db_has_column($conn, 'tos_tqs_documents', 'exam_finished_at')) {
            $conn->query("ALTER TABLE tos_tqs_documents ADD COLUMN exam_finished_at DATETIME NULL AFTER exam_end_at");
        }
        if (!ttq_db_has_column($conn, 'tos_tqs_documents', 'student_answer_key_release')) {
            $conn->query("ALTER TABLE tos_tqs_documents ADD COLUMN student_answer_key_release TINYINT(1) NOT NULL DEFAULT 0 AFTER exam_finished_at");
        }
        if (!ttq_db_has_column($conn, 'tos_tqs_documents', 'prepared_by_name')) {
            $conn->query("ALTER TABLE tos_tqs_documents ADD COLUMN prepared_by_name VARCHAR(120) NOT NULL DEFAULT '' AFTER student_answer_key_release");
        }
        if (!ttq_db_has_column($conn, 'tos_tqs_documents', 'prepared_signature_path')) {
            $conn->query("ALTER TABLE tos_tqs_documents ADD COLUMN prepared_signature_path VARCHAR(255) NULL AFTER prepared_by_name");
        }
        if (!ttq_db_has_column($conn, 'tos_tqs_documents', 'approved_by_name')) {
            $conn->query("ALTER TABLE tos_tqs_documents ADD COLUMN approved_by_name VARCHAR(120) NOT NULL DEFAULT '' AFTER prepared_signature_path");
        }
        if (!ttq_db_has_column($conn, 'tos_tqs_documents', 'approved_signature_path')) {
            $conn->query("ALTER TABLE tos_tqs_documents ADD COLUMN approved_signature_path VARCHAR(255) NULL AFTER approved_by_name");
        }
        if (!ttq_db_has_column($conn, 'tos_tqs_documents', 'status')) {
            $conn->query("ALTER TABLE tos_tqs_documents ADD COLUMN status ENUM('draft','pending_reapproval','approved') NOT NULL DEFAULT 'draft' AFTER approved_signature_path");
        }
        if (!ttq_db_has_column($conn, 'tos_tqs_documents', 'version_no')) {
            $conn->query("ALTER TABLE tos_tqs_documents ADD COLUMN version_no INT NOT NULL DEFAULT 1 AFTER status");
        }
        if (!ttq_db_has_column($conn, 'tos_tqs_documents', 'approved_at')) {
            $conn->query("ALTER TABLE tos_tqs_documents ADD COLUMN approved_at DATETIME NULL AFTER version_no");
        }
        if (!ttq_db_has_column($conn, 'tos_tqs_documents', 'approved_by_user_id')) {
            $conn->query("ALTER TABLE tos_tqs_documents ADD COLUMN approved_by_user_id INT NULL AFTER approved_at");
        }
        if (!ttq_db_has_column($conn, 'tos_tqs_documents', 'approval_note')) {
            $conn->query("ALTER TABLE tos_tqs_documents ADD COLUMN approval_note VARCHAR(255) NULL AFTER approved_by_user_id");
        }

        $conn->query(
            "CREATE TABLE IF NOT EXISTS tos_tqs_approval_events (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                document_id INT NOT NULL,
                version_no INT NOT NULL DEFAULT 1,
                action ENUM('approved','revoked') NOT NULL,
                acted_by INT NULL,
                note VARCHAR(255) NULL,
                acted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_ttq_ae_doc (document_id),
                KEY idx_ttq_ae_actor (acted_by),
                CONSTRAINT fk_ttq_ae_doc
                    FOREIGN KEY (document_id) REFERENCES tos_tqs_documents(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
}

if (!function_exists('ttq_h')) {
    function ttq_h($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('ttq_clean_text')) {
    function ttq_clean_text($value, $maxLen = 500) {
        $value = trim((string) $value);
        $value = preg_replace('/\s+/', ' ', $value);
        if (!is_string($value)) $value = '';
        $maxLen = (int) $maxLen;
        if ($maxLen > 0 && strlen($value) > $maxLen) {
            $value = trim((string) substr($value, 0, $maxLen));
        }
        return $value;
    }
}

if (!function_exists('ttq_clean_multiline')) {
    function ttq_clean_multiline($value, $maxLen = 6000) {
        $value = str_replace(["\r\n", "\r"], "\n", (string) $value);
        $value = preg_replace('/[ \t]+/', ' ', $value);
        $value = preg_replace("/\n{3,}/", "\n\n", $value);
        if (!is_string($value)) $value = '';
        $value = trim($value);
        $maxLen = (int) $maxLen;
        if ($maxLen > 0 && strlen($value) > $maxLen) {
            $value = trim((string) substr($value, 0, $maxLen));
        }
        return $value;
    }
}

if (!function_exists('ttq_term_label')) {
    function ttq_term_label($term) {
        $term = strtolower(trim((string) $term));
        if ($term === 'midterm') return 'Midterm';
        if ($term === 'final') return 'Final';
        return 'Custom';
    }
}

if (!function_exists('ttq_term_enum')) {
    function ttq_term_enum($value) {
        $value = strtolower(trim((string) $value));
        if (in_array($value, ['midterm', 'final', 'custom'], true)) return $value;
        return 'custom';
    }
}

if (!function_exists('ttq_document_mode')) {
    function ttq_document_mode($value) {
        $value = strtolower(trim((string) $value));
        return $value === 'combined' ? 'combined' : 'standalone';
    }
}

if (!function_exists('ttq_format_dt_input')) {
    function ttq_format_dt_input($value) {
        $value = trim((string) $value);
        if ($value === '') return '';
        $ts = strtotime($value);
        if (!$ts) return '';
        return date('Y-m-d\\TH:i', $ts);
    }
}

if (!function_exists('ttq_parse_dt_input')) {
    function ttq_parse_dt_input($value) {
        $value = trim((string) $value);
        if ($value === '') return null;
        $dt = DateTime::createFromFormat('Y-m-d\\TH:i', $value);
        if (!$dt) return null;
        return $dt->format('Y-m-d H:i:s');
    }
}

if (!function_exists('ttq_dimension_keys')) {
    function ttq_dimension_keys() {
        return ['remember', 'understand', 'apply', 'analyze', 'evaluate', 'create'];
    }
}

if (!function_exists('ttq_dimension_labels')) {
    function ttq_dimension_labels() {
        return [
            'remember' => 'Remember',
            'understand' => 'Understand',
            'apply' => 'Apply',
            'analyze' => 'Analyze',
            'evaluate' => 'Evaluate',
            'create' => 'Create',
        ];
    }
}

if (!function_exists('ttq_dimension_guess')) {
    function ttq_dimension_guess($questionText) {
        $q = strtolower(trim((string) $questionText));
        if ($q === '') return 'remember';

        $map = [
            'create' => ['create', 'design', 'develop', 'propose', 'build', 'construct', 'invent'],
            'evaluate' => ['evaluate', 'justify', 'assess', 'critique', 'judge', 'defend'],
            'analyze' => ['analyze', 'compare', 'differentiate', 'distinguish', 'break down', 'inspect', 'examine'],
            'apply' => ['apply', 'use', 'implement', 'demonstrate', 'solve', 'perform', 'execute'],
            'understand' => ['explain', 'describe', 'summarize', 'interpret', 'discuss', 'how'],
            'remember' => ['what', 'which', 'define', 'identify', 'list', 'name', 'recall'],
        ];

        foreach ($map as $dim => $terms) {
            foreach ($terms as $t) {
                if (strpos($q, $t) !== false) return $dim;
            }
        }
        return 'remember';
    }
}

if (!function_exists('ttq_safe_float')) {
    function ttq_safe_float($value, $min = 0.0, $max = 1000000.0) {
        if (!is_numeric($value)) return (float) $min;
        $v = (float) $value;
        if ($v < (float) $min) $v = (float) $min;
        if ($v > (float) $max) $v = (float) $max;
        return round($v, 2);
    }
}

if (!function_exists('ttq_generate_track_id')) {
    function ttq_generate_track_id() {
        try {
            return 'trk_' . bin2hex(random_bytes(4));
        } catch (Throwable $e) {
            return 'trk_' . str_replace('.', '', uniqid('', true));
        }
    }
}

if (!function_exists('ttq_default_question')) {
    function ttq_default_question() {
        return [
            'question_text' => '',
            'answer_key' => '',
            'points' => 1.00,
            'cognitive_dimension' => 'remember',
        ];
    }
}

if (!function_exists('ttq_default_tos_row')) {
    function ttq_default_tos_row() {
        return [
            'topic' => '',
            'hours' => '',
            'percentage' => 0.00,
            'remember' => 0.00,
            'understand' => 0.00,
            'apply' => 0.00,
            'analyze' => 0.00,
            'evaluate' => 0.00,
            'create' => 0.00,
            'is_recommended' => 0,
        ];
    }
}

if (!function_exists('ttq_default_rubric_level')) {
    function ttq_default_rubric_level() {
        return [
            'score' => '',
            'label' => '',
            'description' => '',
            'criteria' => '',
        ];
    }
}

if (!function_exists('ttq_default_rubric_criterion')) {
    function ttq_default_rubric_criterion() {
        return [
            'criterion' => '',
            'excellent_points' => 0.00,
            'good_points' => 0.00,
            'fair_points' => 0.00,
            'needs_points' => 0.00,
            'notes' => '',
        ];
    }
}

if (!function_exists('ttq_default_track')) {
    function ttq_default_track() {
        return [
            'id' => ttq_generate_track_id(),
            'subject_code' => '',
            'subject_name' => '',
            'track_label' => '',
            'exam_type' => 'written',
            'general_instruction' => '',
            'questions' => [ttq_default_question()],
            'tos_rows' => [ttq_default_tos_row()],
            'rubric_levels' => [
                ['score' => '1.00', 'label' => 'Precise Answer', 'description' => '', 'criteria' => ''],
                ['score' => '0.75', 'label' => 'Almost Correct', 'description' => '', 'criteria' => ''],
                ['score' => '0.50', 'label' => 'Intermediate', 'description' => '', 'criteria' => ''],
                ['score' => '0.00', 'label' => 'Wrong / No Answer', 'description' => '', 'criteria' => ''],
            ],
            'rubric_criteria' => [ttq_default_rubric_criterion()],
            'notes' => '',
        ];
    }
}

if (!function_exists('ttq_default_content')) {
    function ttq_default_content() {
        return [
            'school_name' => 'College of Computer Studies and Information Technology',
            'document_label' => 'Table of Specifications and Test Questionnaire',
            'tracks' => [ttq_default_track()],
        ];
    }
}

if (!function_exists('ttq_normalize_question')) {
    function ttq_normalize_question($row) {
        $base = ttq_default_question();
        if (!is_array($row)) return $base;

        $base['question_text'] = ttq_clean_multiline((string) ($row['question_text'] ?? ''), 2500);
        $base['answer_key'] = ttq_clean_multiline((string) ($row['answer_key'] ?? ''), 2000);
        $base['points'] = ttq_safe_float($row['points'] ?? 1.0, 0.0, 1000.0);
        $dim = strtolower(trim((string) ($row['cognitive_dimension'] ?? '')));
        $allowed = ttq_dimension_keys();
        $base['cognitive_dimension'] = in_array($dim, $allowed, true) ? $dim : ttq_dimension_guess($base['question_text']);
        return $base;
    }
}

if (!function_exists('ttq_normalize_tos_row')) {
    function ttq_normalize_tos_row($row) {
        $base = ttq_default_tos_row();
        if (!is_array($row)) return $base;

        $base['topic'] = ttq_clean_text((string) ($row['topic'] ?? ''), 220);
        $base['hours'] = ttq_clean_text((string) ($row['hours'] ?? ''), 80);
        $base['percentage'] = ttq_safe_float($row['percentage'] ?? 0.0, 0.0, 1000.0);
        foreach (ttq_dimension_keys() as $k) {
            $base[$k] = ttq_safe_float($row[$k] ?? 0.0, 0.0, 10000.0);
        }
        $base['is_recommended'] = !empty($row['is_recommended']) ? 1 : 0;
        return $base;
    }
}

if (!function_exists('ttq_normalize_rubric_level')) {
    function ttq_normalize_rubric_level($row) {
        $base = ttq_default_rubric_level();
        if (!is_array($row)) return $base;
        $base['score'] = ttq_clean_text((string) ($row['score'] ?? ''), 40);
        $base['label'] = ttq_clean_text((string) ($row['label'] ?? ''), 120);
        $base['description'] = ttq_clean_multiline((string) ($row['description'] ?? ''), 1200);
        $base['criteria'] = ttq_clean_multiline((string) ($row['criteria'] ?? ''), 1200);
        return $base;
    }
}

if (!function_exists('ttq_normalize_rubric_criterion')) {
    function ttq_normalize_rubric_criterion($row) {
        $base = ttq_default_rubric_criterion();
        if (!is_array($row)) return $base;
        $base['criterion'] = ttq_clean_text((string) ($row['criterion'] ?? ''), 180);
        $base['excellent_points'] = ttq_safe_float($row['excellent_points'] ?? 0.0, 0.0, 1000.0);
        $base['good_points'] = ttq_safe_float($row['good_points'] ?? 0.0, 0.0, 1000.0);
        $base['fair_points'] = ttq_safe_float($row['fair_points'] ?? 0.0, 0.0, 1000.0);
        $base['needs_points'] = ttq_safe_float($row['needs_points'] ?? 0.0, 0.0, 1000.0);
        $base['notes'] = ttq_clean_multiline((string) ($row['notes'] ?? ''), 1200);
        return $base;
    }
}

if (!function_exists('ttq_normalize_track')) {
    function ttq_normalize_track($track, $idx = 0) {
        $base = ttq_default_track();
        if (!is_array($track)) return $base;

        $base['id'] = ttq_clean_text((string) ($track['id'] ?? ''), 40);
        if ($base['id'] === '') $base['id'] = 'trk_' . ((int) $idx + 1);
        $base['subject_code'] = ttq_clean_text((string) ($track['subject_code'] ?? ''), 40);
        $base['subject_name'] = ttq_clean_text((string) ($track['subject_name'] ?? ''), 160);
        $base['track_label'] = ttq_clean_text((string) ($track['track_label'] ?? ''), 80);

        $examType = strtolower(trim((string) ($track['exam_type'] ?? 'written')));
        if (!in_array($examType, ['written', 'practical', 'mixed'], true)) $examType = 'written';
        $base['exam_type'] = $examType;

        $base['general_instruction'] = ttq_clean_multiline((string) ($track['general_instruction'] ?? ''), 5000);
        $base['notes'] = ttq_clean_multiline((string) ($track['notes'] ?? ''), 3000);

        $questions = [];
        if (isset($track['questions']) && is_array($track['questions'])) {
            foreach ($track['questions'] as $q) {
                $nq = ttq_normalize_question($q);
                if ($nq['question_text'] === '' && $nq['answer_key'] === '' && (float) $nq['points'] <= 0) continue;
                $questions[] = $nq;
                if (count($questions) >= 300) break;
            }
        }
        if (count($questions) === 0) $questions[] = ttq_default_question();
        $base['questions'] = $questions;

        $tosRows = [];
        if (isset($track['tos_rows']) && is_array($track['tos_rows'])) {
            foreach ($track['tos_rows'] as $r) {
                $nr = ttq_normalize_tos_row($r);
                $empty = ($nr['topic'] === '' && $nr['hours'] === '');
                $sumDims = 0.0;
                foreach (ttq_dimension_keys() as $dk) $sumDims += (float) $nr[$dk];
                if ($empty && $sumDims <= 0.0 && (float) $nr['percentage'] <= 0.0) continue;
                $tosRows[] = $nr;
                if (count($tosRows) >= 200) break;
            }
        }
        if (count($tosRows) === 0) $tosRows[] = ttq_default_tos_row();
        $base['tos_rows'] = $tosRows;

        $rubricLevels = [];
        if (isset($track['rubric_levels']) && is_array($track['rubric_levels'])) {
            foreach ($track['rubric_levels'] as $r) {
                $nr = ttq_normalize_rubric_level($r);
                if ($nr['score'] === '' && $nr['label'] === '' && $nr['description'] === '' && $nr['criteria'] === '') continue;
                $rubricLevels[] = $nr;
                if (count($rubricLevels) >= 40) break;
            }
        }
        if (count($rubricLevels) === 0) $rubricLevels[] = ttq_default_rubric_level();
        $base['rubric_levels'] = $rubricLevels;

        $rubricCriteria = [];
        if (isset($track['rubric_criteria']) && is_array($track['rubric_criteria'])) {
            foreach ($track['rubric_criteria'] as $r) {
                $nr = ttq_normalize_rubric_criterion($r);
                $empty = ($nr['criterion'] === '' && $nr['notes'] === '');
                $sum = (float) $nr['excellent_points'] + (float) $nr['good_points'] + (float) $nr['fair_points'] + (float) $nr['needs_points'];
                if ($empty && $sum <= 0) continue;
                $rubricCriteria[] = $nr;
                if (count($rubricCriteria) >= 120) break;
            }
        }
        if (count($rubricCriteria) === 0) $rubricCriteria[] = ttq_default_rubric_criterion();
        $base['rubric_criteria'] = $rubricCriteria;

        return $base;
    }
}

if (!function_exists('ttq_normalize_content')) {
    function ttq_normalize_content($content) {
        $base = ttq_default_content();
        if (!is_array($content)) return $base;

        $base['school_name'] = ttq_clean_text((string) ($content['school_name'] ?? $base['school_name']), 220);
        if ($base['school_name'] === '') $base['school_name'] = ttq_default_content()['school_name'];

        $base['document_label'] = ttq_clean_text((string) ($content['document_label'] ?? $base['document_label']), 180);
        if ($base['document_label'] === '') $base['document_label'] = 'Table of Specifications and Test Questionnaire';

        $tracks = [];
        if (isset($content['tracks']) && is_array($content['tracks'])) {
            foreach ($content['tracks'] as $i => $t) {
                $tracks[] = ttq_normalize_track($t, (int) $i);
                if (count($tracks) >= 20) break;
            }
        }
        if (count($tracks) === 0) $tracks[] = ttq_default_track();
        $base['tracks'] = $tracks;
        return $base;
    }
}

if (!function_exists('ttq_decode_content_json')) {
    function ttq_decode_content_json($raw) {
        $raw = trim((string) $raw);
        if ($raw === '') return ttq_default_content();
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) return ttq_default_content();
        return ttq_normalize_content($decoded);
    }
}

if (!function_exists('ttq_encode_content_json')) {
    function ttq_encode_content_json(array $content) {
        $normalized = ttq_normalize_content($content);
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : json_encode(ttq_default_content());
    }
}

if (!function_exists('ttq_track_total_points')) {
    function ttq_track_total_points(array $track) {
        $sum = 0.0;
        foreach ((array) ($track['questions'] ?? []) as $q) {
            $sum += ttq_safe_float($q['points'] ?? 0, 0, 1000000.0);
        }
        return round($sum, 2);
    }
}

if (!function_exists('ttq_document_total_points')) {
    function ttq_document_total_points(array $docRow) {
        $content = ttq_decode_content_json((string) ($docRow['content_json'] ?? ''));
        $sum = 0.0;
        foreach ((array) ($content['tracks'] ?? []) as $track) {
            $sum += ttq_track_total_points((array) $track);
        }
        return round($sum, 2);
    }
}

if (!function_exists('ttq_is_exam_finished')) {
    function ttq_is_exam_finished(array $row) {
        $finishedAt = trim((string) ($row['exam_finished_at'] ?? ''));
        if ($finishedAt !== '') return true;

        $endAt = trim((string) ($row['exam_end_at'] ?? ''));
        if ($endAt === '') return false;

        $endTs = strtotime($endAt);
        if (!$endTs) return false;
        return time() >= $endTs;
    }
}

if (!function_exists('ttq_student_answer_key_visible')) {
    function ttq_student_answer_key_visible(array $row) {
        $release = !empty($row['student_answer_key_release']);
        if (!$release) return false;
        return ttq_is_exam_finished($row);
    }
}

if (!function_exists('ttq_fetch_teacher_documents')) {
    function ttq_fetch_teacher_documents(mysqli $conn, $teacherId, $classRecordId = 0) {
        $teacherId = (int) $teacherId;
        $classRecordId = (int) $classRecordId;
        if ($teacherId <= 0) return [];

        $rows = [];
        if ($classRecordId > 0) {
            $stmt = $conn->prepare(
                "SELECT id, class_record_id, term, document_mode, title, academic_year, semester,
                        status, version_no, approved_at, created_at, updated_at
                 FROM tos_tqs_documents
                 WHERE teacher_id = ? AND class_record_id = ?
                 ORDER BY updated_at DESC, id DESC
                 LIMIT 300"
            );
            if ($stmt) $stmt->bind_param('ii', $teacherId, $classRecordId);
        } else {
            $stmt = $conn->prepare(
                "SELECT id, class_record_id, term, document_mode, title, academic_year, semester,
                        status, version_no, approved_at, created_at, updated_at
                 FROM tos_tqs_documents
                 WHERE teacher_id = ?
                 ORDER BY updated_at DESC, id DESC
                 LIMIT 300"
            );
            if ($stmt) $stmt->bind_param('i', $teacherId);
        }
        if (!$stmt) return $rows;

        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($r = $res->fetch_assoc())) $rows[] = $r;
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('ttq_fetch_document')) {
    function ttq_fetch_document(mysqli $conn, $documentId, $teacherId) {
        $documentId = (int) $documentId;
        $teacherId = (int) $teacherId;
        if ($documentId <= 0 || $teacherId <= 0) return null;

        $stmt = $conn->prepare(
            "SELECT *
             FROM tos_tqs_documents
             WHERE id = ? AND teacher_id = ?
             LIMIT 1"
        );
        if (!$stmt) return null;

        $stmt->bind_param('ii', $documentId, $teacherId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
        $stmt->close();
        if (!is_array($row)) return null;

        $row['content'] = ttq_decode_content_json((string) ($row['content_json'] ?? ''));
        return $row;
    }
}

if (!function_exists('ttq_insert_approval_event')) {
    function ttq_insert_approval_event(mysqli $conn, $documentId, $versionNo, $action, $actedBy, $note = '') {
        $documentId = (int) $documentId;
        $versionNo = (int) $versionNo;
        $action = strtolower(trim((string) $action));
        if ($documentId <= 0 || $versionNo <= 0 || !in_array($action, ['approved', 'revoked'], true)) return false;
        $actedBy = (int) $actedBy;
        if ($actedBy <= 0) $actedBy = null;
        $note = ttq_clean_text((string) $note, 255);

        $stmt = $conn->prepare(
            "INSERT INTO tos_tqs_approval_events (document_id, version_no, action, acted_by, note)
             VALUES (?, ?, ?, ?, ?)"
        );
        if (!$stmt) return false;
        $stmt->bind_param('iisis', $documentId, $versionNo, $action, $actedBy, $note);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool) $ok;
    }
}

if (!function_exists('ttq_create_document')) {
    function ttq_create_document(mysqli $conn, $teacherId, array $payload, &$error = '') {
        $error = '';
        $teacherId = (int) $teacherId;
        if ($teacherId <= 0) {
            $error = 'Invalid teacher.';
            return 0;
        }

        $classRecordId = isset($payload['class_record_id']) ? (int) $payload['class_record_id'] : null;
        if ($classRecordId !== null && $classRecordId <= 0) $classRecordId = null;
        $term = ttq_term_enum((string) ($payload['term'] ?? 'custom'));
        $mode = ttq_document_mode((string) ($payload['document_mode'] ?? 'standalone'));
        $title = ttq_clean_text((string) ($payload['title'] ?? ''), 180);
        $academicYear = ttq_clean_text((string) ($payload['academic_year'] ?? ''), 40);
        $semester = ttq_clean_text((string) ($payload['semester'] ?? ''), 40);
        $examEndAt = isset($payload['exam_end_at']) ? $payload['exam_end_at'] : null;
        if (!is_string($examEndAt) || trim($examEndAt) === '') $examEndAt = null;
        $examFinishedAt = isset($payload['exam_finished_at']) ? $payload['exam_finished_at'] : null;
        if (!is_string($examFinishedAt) || trim($examFinishedAt) === '') $examFinishedAt = null;
        $release = !empty($payload['student_answer_key_release']) ? 1 : 0;

        $preparedByName = ttq_clean_text((string) ($payload['prepared_by_name'] ?? ''), 120);
        $preparedSig = ttq_clean_text((string) ($payload['prepared_signature_path'] ?? ''), 255);
        if ($preparedSig === '') $preparedSig = null;
        $approvedByName = ttq_clean_text((string) ($payload['approved_by_name'] ?? ''), 120);
        $approvedSig = ttq_clean_text((string) ($payload['approved_signature_path'] ?? ''), 255);
        if ($approvedSig === '') $approvedSig = null;

        $contentJson = ttq_encode_content_json((array) ($payload['content'] ?? []));
        $status = 'draft';
        $versionNo = 1;

        $stmt = $conn->prepare(
            "INSERT INTO tos_tqs_documents
                (teacher_id, class_record_id, term, document_mode, title, academic_year, semester,
                 exam_end_at, exam_finished_at, student_answer_key_release,
                 prepared_by_name, prepared_signature_path, approved_by_name, approved_signature_path,
                 status, version_no, content_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            $error = 'Unable to prepare create statement.';
            return 0;
        }

        $stmt->bind_param(
            'iisssssssisssssis',
            $teacherId,
            $classRecordId,
            $term,
            $mode,
            $title,
            $academicYear,
            $semester,
            $examEndAt,
            $examFinishedAt,
            $release,
            $preparedByName,
            $preparedSig,
            $approvedByName,
            $approvedSig,
            $status,
            $versionNo,
            $contentJson
        );
        $ok = $stmt->execute();
        if (!$ok) {
            $error = 'Unable to create document.';
            $stmt->close();
            return 0;
        }
        $id = (int) $conn->insert_id;
        $stmt->close();
        return $id;
    }
}

if (!function_exists('ttq_update_document')) {
    function ttq_update_document(mysqli $conn, $documentId, $teacherId, array $payload, &$error = '') {
        $error = '';
        $documentId = (int) $documentId;
        $teacherId = (int) $teacherId;
        if ($documentId <= 0 || $teacherId <= 0) {
            $error = 'Invalid document reference.';
            return false;
        }

        $current = ttq_fetch_document($conn, $documentId, $teacherId);
        if (!$current) {
            $error = 'Document not found.';
            return false;
        }

        $classRecordId = isset($payload['class_record_id']) ? (int) $payload['class_record_id'] : (int) ($current['class_record_id'] ?? 0);
        if ($classRecordId <= 0) $classRecordId = null;
        $term = ttq_term_enum((string) ($payload['term'] ?? ($current['term'] ?? 'custom')));
        $mode = ttq_document_mode((string) ($payload['document_mode'] ?? ($current['document_mode'] ?? 'standalone')));
        $title = ttq_clean_text((string) ($payload['title'] ?? ($current['title'] ?? '')), 180);
        $academicYear = ttq_clean_text((string) ($payload['academic_year'] ?? ($current['academic_year'] ?? '')), 40);
        $semester = ttq_clean_text((string) ($payload['semester'] ?? ($current['semester'] ?? '')), 40);

        $examEndAt = isset($payload['exam_end_at']) ? $payload['exam_end_at'] : ($current['exam_end_at'] ?? null);
        if (!is_string($examEndAt) || trim($examEndAt) === '') $examEndAt = null;
        $examFinishedAt = isset($payload['exam_finished_at']) ? $payload['exam_finished_at'] : ($current['exam_finished_at'] ?? null);
        if (!is_string($examFinishedAt) || trim($examFinishedAt) === '') $examFinishedAt = null;
        $release = isset($payload['student_answer_key_release'])
            ? (!empty($payload['student_answer_key_release']) ? 1 : 0)
            : (!empty($current['student_answer_key_release']) ? 1 : 0);

        $preparedByName = ttq_clean_text((string) ($payload['prepared_by_name'] ?? ($current['prepared_by_name'] ?? '')), 120);
        $preparedSig = ttq_clean_text((string) ($payload['prepared_signature_path'] ?? ($current['prepared_signature_path'] ?? '')), 255);
        if ($preparedSig === '') $preparedSig = null;
        $approvedByName = ttq_clean_text((string) ($payload['approved_by_name'] ?? ($current['approved_by_name'] ?? '')), 120);
        $approvedSig = ttq_clean_text((string) ($payload['approved_signature_path'] ?? ($current['approved_signature_path'] ?? '')), 255);
        if ($approvedSig === '') $approvedSig = null;

        $contentJson = ttq_encode_content_json((array) ($payload['content'] ?? ($current['content'] ?? [])));
        $nextVersion = max(1, ((int) ($current['version_no'] ?? 1)) + 1);
        $status = (string) ($current['status'] ?? 'draft');
        $approvedAt = $current['approved_at'] ?? null;
        $approvedByUserId = isset($current['approved_by_user_id']) ? (int) $current['approved_by_user_id'] : null;

        $approvalCleared = false;
        if ($status === 'approved') {
            $status = 'pending_reapproval';
            $approvedAt = null;
            $approvedByUserId = null;
            $approvalCleared = true;
        }

        $stmt = $conn->prepare(
            "UPDATE tos_tqs_documents
             SET class_record_id = ?,
                 term = ?,
                 document_mode = ?,
                 title = ?,
                 academic_year = ?,
                 semester = ?,
                 exam_end_at = ?,
                 exam_finished_at = ?,
                 student_answer_key_release = ?,
                 prepared_by_name = ?,
                 prepared_signature_path = ?,
                 approved_by_name = ?,
                 approved_signature_path = ?,
                 status = ?,
                 version_no = ?,
                 approved_at = ?,
                 approved_by_user_id = ?,
                 content_json = ?
             WHERE id = ? AND teacher_id = ?
             LIMIT 1"
        );
        if (!$stmt) {
            $error = 'Unable to prepare update statement.';
            return false;
        }

        $stmt->bind_param(
            'isssssssisssssisssii',
            $classRecordId,
            $term,
            $mode,
            $title,
            $academicYear,
            $semester,
            $examEndAt,
            $examFinishedAt,
            $release,
            $preparedByName,
            $preparedSig,
            $approvedByName,
            $approvedSig,
            $status,
            $nextVersion,
            $approvedAt,
            $approvedByUserId,
            $contentJson,
            $documentId,
            $teacherId
        );

        $ok = $stmt->execute();
        $affected = (int) $stmt->affected_rows;
        $stmt->close();
        if (!$ok) {
            $error = 'Unable to save document.';
            return false;
        }
        if ($affected < 0) {
            $error = 'No updates were applied.';
            return false;
        }

        if ($approvalCleared) {
            ttq_insert_approval_event($conn, $documentId, (int) ($current['version_no'] ?? 1), 'revoked', $teacherId, 'Document edited; re-approval required.');
        }
        return true;
    }
}

if (!function_exists('ttq_approve_document')) {
    function ttq_approve_document(mysqli $conn, $documentId, $teacherId, $approvedByName = '', $approvedSignaturePath = '', $note = '', &$error = '') {
        $error = '';
        $documentId = (int) $documentId;
        $teacherId = (int) $teacherId;
        if ($documentId <= 0 || $teacherId <= 0) {
            $error = 'Invalid document reference.';
            return false;
        }

        $current = ttq_fetch_document($conn, $documentId, $teacherId);
        if (!$current) {
            $error = 'Document not found.';
            return false;
        }

        $approvedByName = ttq_clean_text((string) $approvedByName, 120);
        if ($approvedByName === '') $approvedByName = ttq_clean_text((string) ($current['approved_by_name'] ?? ''), 120);
        if ($approvedByName === '') $approvedByName = ttq_clean_text((string) ($_SESSION['user_name'] ?? ''), 120);

        $approvedSignaturePath = ttq_clean_text((string) $approvedSignaturePath, 255);
        if ($approvedSignaturePath === '') $approvedSignaturePath = ttq_clean_text((string) ($current['approved_signature_path'] ?? ''), 255);
        if ($approvedSignaturePath === '') $approvedSignaturePath = null;
        $note = ttq_clean_text((string) $note, 255);
        if ($note === '') $note = null;

        $versionNo = (int) ($current['version_no'] ?? 1);
        if ($versionNo <= 0) $versionNo = 1;
        $now = date('Y-m-d H:i:s');

        $stmt = $conn->prepare(
            "UPDATE tos_tqs_documents
             SET status = 'approved',
                 approved_by_name = ?,
                 approved_signature_path = ?,
                 approved_at = ?,
                 approved_by_user_id = ?,
                 approval_note = ?
             WHERE id = ? AND teacher_id = ?
             LIMIT 1"
        );
        if (!$stmt) {
            $error = 'Unable to prepare approval statement.';
            return false;
        }
        $stmt->bind_param('sssisis', $approvedByName, $approvedSignaturePath, $now, $teacherId, $note, $documentId, $teacherId);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) {
            $error = 'Unable to approve document.';
            return false;
        }

        ttq_insert_approval_event($conn, $documentId, $versionNo, 'approved', $teacherId, $note ?: 'Approved by teacher.');
        return true;
    }
}

if (!function_exists('ttq_mark_exam_finished')) {
    function ttq_mark_exam_finished(mysqli $conn, $documentId, $teacherId, &$error = '') {
        $error = '';
        $documentId = (int) $documentId;
        $teacherId = (int) $teacherId;
        if ($documentId <= 0 || $teacherId <= 0) {
            $error = 'Invalid document reference.';
            return false;
        }
        $now = date('Y-m-d H:i:s');
        $stmt = $conn->prepare(
            "UPDATE tos_tqs_documents
             SET exam_finished_at = ?
             WHERE id = ? AND teacher_id = ?
             LIMIT 1"
        );
        if (!$stmt) {
            $error = 'Unable to prepare finish statement.';
            return false;
        }
        $stmt->bind_param('sii', $now, $documentId, $teacherId);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) {
            $error = 'Unable to mark exam as finished.';
            return false;
        }
        return true;
    }
}

if (!function_exists('ttq_set_student_answer_key_release')) {
    function ttq_set_student_answer_key_release(mysqli $conn, $documentId, $teacherId, $enabled, &$error = '') {
        $error = '';
        $documentId = (int) $documentId;
        $teacherId = (int) $teacherId;
        $enabled = !empty($enabled) ? 1 : 0;
        if ($documentId <= 0 || $teacherId <= 0) {
            $error = 'Invalid document reference.';
            return false;
        }

        $doc = ttq_fetch_document($conn, $documentId, $teacherId);
        if (!$doc) {
            $error = 'Document not found.';
            return false;
        }
        if ($enabled === 1 && !ttq_is_exam_finished($doc)) {
            $error = 'Cannot release answer key yet. Mark exam as finished or wait for exam end time.';
            return false;
        }

        $stmt = $conn->prepare(
            "UPDATE tos_tqs_documents
             SET student_answer_key_release = ?
             WHERE id = ? AND teacher_id = ?
             LIMIT 1"
        );
        if (!$stmt) {
            $error = 'Unable to prepare release statement.';
            return false;
        }
        $stmt->bind_param('iii', $enabled, $documentId, $teacherId);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) {
            $error = 'Unable to update answer key visibility.';
            return false;
        }
        return true;
    }
}

if (!function_exists('ttq_signatures_base_dir')) {
    function ttq_signatures_base_dir() {
        return __DIR__ . '/../uploads/tos_tqs_signatures';
    }
}

if (!function_exists('ttq_save_signature_upload')) {
    function ttq_save_signature_upload($fileField, $namePrefix = 'sig', &$error = '') {
        $error = '';
        if (!is_array($fileField) || !isset($fileField['tmp_name'])) return '';

        $errCode = isset($fileField['error']) ? (int) $fileField['error'] : UPLOAD_ERR_NO_FILE;
        if ($errCode === UPLOAD_ERR_NO_FILE) return '';
        if ($errCode !== UPLOAD_ERR_OK) {
            $error = 'Signature upload failed.';
            return '';
        }

        $tmp = (string) ($fileField['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) {
            $error = 'Uploaded signature file is invalid.';
            return '';
        }

        $size = isset($fileField['size']) ? (int) $fileField['size'] : 0;
        if ($size <= 0 || $size > (2 * 1024 * 1024)) {
            $error = 'Signature file must be <= 2MB.';
            return '';
        }

        $mime = '';
        if (function_exists('finfo_open')) {
            $fi = @finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) {
                $mime = (string) @finfo_file($fi, $tmp);
                @finfo_close($fi);
            }
        }
        if ($mime === '' && function_exists('mime_content_type')) {
            $mime = (string) @mime_content_type($tmp);
        }
        $mime = strtolower(trim($mime));
        $extMap = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
        ];
        if (!isset($extMap[$mime])) {
            $error = 'Only PNG/JPG/WEBP signatures are allowed.';
            return '';
        }
        $ext = $extMap[$mime];

        $baseDir = ttq_signatures_base_dir();
        $subDir = date('Y') . '/' . date('m');
        $targetDir = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $subDir);
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            $error = 'Unable to create signature directory.';
            return '';
        }

        $token = '';
        try {
            $token = bin2hex(random_bytes(6));
        } catch (Throwable $e) {
            $token = str_replace('.', '', uniqid('', true));
        }
        $safePrefix = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $namePrefix);
        if (!is_string($safePrefix) || trim($safePrefix) === '') $safePrefix = 'sig';

        $filename = $safePrefix . '_' . date('Ymd_His') . '_' . $token . '.' . $ext;
        $absPath = $targetDir . DIRECTORY_SEPARATOR . $filename;
        if (!@move_uploaded_file($tmp, $absPath)) {
            if (!@rename($tmp, $absPath)) {
                $error = 'Unable to store uploaded signature.';
                return '';
            }
        }

        return 'uploads/tos_tqs_signatures/' . $subDir . '/' . $filename;
    }
}

if (!function_exists('ttq_safe_file_relative_path')) {
    function ttq_safe_file_relative_path($path) {
        $path = trim((string) $path);
        if ($path === '') return '';
        $path = str_replace('\\', '/', $path);
        while (strpos($path, '../') === 0) {
            $path = substr($path, 3);
        }
        if (preg_match('/^[a-zA-Z]:\//', $path)) return '';
        if (preg_match('/^[a-zA-Z]+:\/\//', $path)) return '';
        return ltrim($path, '/');
    }
}

if (!function_exists('ttq_image_data_uri')) {
    function ttq_image_data_uri($relativePath) {
        $relativePath = ttq_safe_file_relative_path($relativePath);
        if ($relativePath === '') return '';
        $absolute = realpath(__DIR__ . '/../' . $relativePath);
        if ($absolute === false || !is_file($absolute)) return '';
        $size = @filesize($absolute);
        if (!is_int($size) || $size <= 0 || $size > (2 * 1024 * 1024)) return '';
        $mime = '';
        if (function_exists('finfo_open')) {
            $fi = @finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) {
                $mime = (string) @finfo_file($fi, $absolute);
                @finfo_close($fi);
            }
        }
        $mime = strtolower(trim($mime));
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) return '';
        $bin = @file_get_contents($absolute);
        if (!is_string($bin) || $bin === '') return '';
        return 'data:' . $mime . ';base64,' . base64_encode($bin);
    }
}

if (!function_exists('ttq_openai_api_key')) {
    function ttq_openai_api_key() {
        if (function_exists('tgc_ai_openai_api_key')) {
            return (string) tgc_ai_openai_api_key();
        }
        $env = function_exists('doc_ease_env_value')
            ? trim((string) doc_ease_env_value('OPENAI_API_KEY'))
            : trim((string) getenv('OPENAI_API_KEY'));
        if ($env !== '') return $env;
        return '';
    }
}

if (!function_exists('ttq_extract_json_object')) {
    function ttq_extract_json_object($content) {
        $content = trim((string) $content);
        if ($content === '') return null;

        $decoded = json_decode($content, true);
        if (is_array($decoded)) return $decoded;

        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/is', $content, $m)) {
            $decoded = json_decode((string) ($m[1] ?? ''), true);
            if (is_array($decoded)) return $decoded;
        }

        $a = strpos($content, '{');
        $b = strrpos($content, '}');
        if ($a !== false && $b !== false && $b > $a) {
            $decoded = json_decode((string) substr($content, $a, $b - $a + 1), true);
            if (is_array($decoded)) return $decoded;
        }
        return null;
    }
}

if (!function_exists('ttq_local_track_recommendation')) {
    function ttq_local_track_recommendation(array $track) {
        $dims = ttq_dimension_keys();
        $counts = array_fill_keys($dims, 0.0);
        $questionsOut = [];
        $questions = isset($track['questions']) && is_array($track['questions']) ? $track['questions'] : [];

        foreach ($questions as $q) {
            $qText = (string) ($q['question_text'] ?? '');
            $dim = strtolower(trim((string) ($q['cognitive_dimension'] ?? '')));
            if (!in_array($dim, $dims, true)) $dim = ttq_dimension_guess($qText);
            $points = ttq_safe_float($q['points'] ?? 1.0, 0.0, 1000.0);
            $counts[$dim] += ($points > 0 ? $points : 1.0);

            $q['cognitive_dimension'] = $dim;
            $questionsOut[] = ttq_normalize_question($q);
        }

        return [
            'source' => 'heuristic',
            'questions' => $questionsOut,
            'allocation' => $counts,
            'rationale' => 'Auto-allocation based on question verbs and existing cognitive tags.',
        ];
    }
}

if (!function_exists('ttq_ai_track_recommendation')) {
    function ttq_ai_track_recommendation(array $track) {
        $fallback = ttq_local_track_recommendation($track);

        if (function_exists('ai_access_can_use') && !ai_access_can_use()) return $fallback;
        $apiKey = ttq_openai_api_key();
        if ($apiKey === '' || !function_exists('curl_init')) return $fallback;

        $dims = ttq_dimension_keys();
        $questionRows = [];
        foreach ((array) ($track['questions'] ?? []) as $i => $q) {
            $txt = ttq_clean_multiline((string) ($q['question_text'] ?? ''), 800);
            if ($txt === '') continue;
            $questionRows[] = [
                'index' => (int) $i,
                'question' => $txt,
                'points' => ttq_safe_float($q['points'] ?? 1.0, 0.0, 1000.0),
                'existing_dimension' => ttq_clean_text((string) ($q['cognitive_dimension'] ?? ''), 30),
            ];
            if (count($questionRows) >= 300) break;
        }
        if (count($questionRows) === 0) return $fallback;

        $payloadContext = [
            'track_subject_code' => ttq_clean_text((string) ($track['subject_code'] ?? ''), 40),
            'track_subject_name' => ttq_clean_text((string) ($track['subject_name'] ?? ''), 160),
            'track_label' => ttq_clean_text((string) ($track['track_label'] ?? ''), 80),
            'exam_type' => ttq_clean_text((string) ($track['exam_type'] ?? ''), 20),
            'questions' => $questionRows,
            'dimensions' => $dims,
        ];

        $systemPrompt = "You classify exam questions into Bloom-style cognitive dimensions and suggest a practical TOS allocation. Stay strictly in assessment design scope. Return strict JSON only.";
        $userPrompt = "Return strict JSON object:\n{\n  \"questions\": [{\"index\": number, \"dimension\": \"remember|understand|apply|analyze|evaluate|create\"}],\n  \"allocation\": {\"remember\": number, \"understand\": number, \"apply\": number, \"analyze\": number, \"evaluate\": number, \"create\": number},\n  \"rationale\": \"short rationale\"\n}\nRules:\n- Classify each question index once.\n- Use the six dimensions only.\n- allocation values should represent weighted count (points-based) and be >= 0.\n- Keep rationale concise.\n\nContext JSON:\n" . json_encode($payloadContext, JSON_UNESCAPED_SLASHES);

        $payload = [
            'model' => 'gpt-4o-mini',
            'temperature' => 0.1,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ];

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        if (!$ch) return $fallback;
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 45);
        $resp = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($resp) || trim($resp) === '' || $status >= 400) return $fallback;

        $decoded = json_decode($resp, true);
        $content = trim((string) ($decoded['choices'][0]['message']['content'] ?? ''));
        if ($content === '') return $fallback;

        $json = ttq_extract_json_object($content);
        if (!is_array($json)) return $fallback;

        $mapByIndex = [];
        if (is_array($json['questions'] ?? null)) {
            foreach ($json['questions'] as $qj) {
                if (!is_array($qj)) continue;
                $index = isset($qj['index']) ? (int) $qj['index'] : -1;
                $dim = strtolower(trim((string) ($qj['dimension'] ?? '')));
                if ($index < 0 || !in_array($dim, $dims, true)) continue;
                $mapByIndex[$index] = $dim;
            }
        }

        $questionsOut = [];
        $alloc = array_fill_keys($dims, 0.0);
        foreach ((array) ($track['questions'] ?? []) as $i => $q) {
            $i = (int) $i;
            $dim = isset($mapByIndex[$i]) ? $mapByIndex[$i] : ttq_dimension_guess((string) ($q['question_text'] ?? ''));
            if (!in_array($dim, $dims, true)) $dim = 'remember';
            $q['cognitive_dimension'] = $dim;
            $nq = ttq_normalize_question($q);
            $questionsOut[] = $nq;
            $alloc[$dim] += ((float) ($nq['points'] ?? 0) > 0.0) ? (float) $nq['points'] : 1.0;
        }

        $jsonAlloc = is_array($json['allocation'] ?? null) ? $json['allocation'] : [];
        foreach ($dims as $k) {
            if (isset($jsonAlloc[$k]) && is_numeric($jsonAlloc[$k])) {
                $alloc[$k] = ttq_safe_float($jsonAlloc[$k], 0.0, 100000.0);
            } else {
                $alloc[$k] = round($alloc[$k], 2);
            }
        }

        $rationale = ttq_clean_text((string) ($json['rationale'] ?? ''), 220);
        if ($rationale === '') $rationale = 'AI-based classification from TQS question intent.';

        return [
            'source' => 'ai',
            'questions' => $questionsOut,
            'allocation' => $alloc,
            'rationale' => $rationale,
        ];
    }
}

if (!function_exists('ttq_apply_track_recommendation')) {
    function ttq_apply_track_recommendation(array $track, array $recommendation) {
        $track = ttq_normalize_track($track, 0);
        $dims = ttq_dimension_keys();

        $questions = isset($recommendation['questions']) && is_array($recommendation['questions']) ? $recommendation['questions'] : [];
        if (count($questions) > 0) {
            $nq = [];
            foreach ($questions as $q) $nq[] = ttq_normalize_question($q);
            $track['questions'] = $nq;
        }

        $alloc = is_array($recommendation['allocation'] ?? null) ? $recommendation['allocation'] : [];
        $row = ttq_default_tos_row();
        $row['topic'] = 'AI Recommended Allocation';
        $row['hours'] = '';
        $row['percentage'] = 100.00;
        $row['is_recommended'] = 1;
        foreach ($dims as $k) {
            $row[$k] = ttq_safe_float($alloc[$k] ?? 0.0, 0.0, 100000.0);
        }

        $existing = [];
        foreach ((array) ($track['tos_rows'] ?? []) as $r) {
            $nr = ttq_normalize_tos_row($r);
            if (!empty($nr['is_recommended'])) continue;
            $existing[] = $nr;
        }
        array_unshift($existing, $row);
        $track['tos_rows'] = array_slice($existing, 0, 200);
        if (count($track['tos_rows']) === 0) $track['tos_rows'][] = $row;

        $track['notes'] = trim((string) $track['notes']);
        $rationale = ttq_clean_text((string) ($recommendation['rationale'] ?? ''), 220);
        if ($rationale !== '') {
            $prefix = '[Recommendation] ';
            $line = $prefix . $rationale;
            $notes = trim((string) $track['notes']);
            if ($notes === '') $notes = $line;
            elseif (strpos($notes, $line) === false) $notes .= "\n" . $line;
            $track['notes'] = ttq_clean_multiline($notes, 3000);
        }

        return $track;
    }
}

if (!function_exists('ttq_fetch_teacher_class_options')) {
    function ttq_fetch_teacher_class_options(mysqli $conn, $teacherId) {
        $teacherId = (int) $teacherId;
        if ($teacherId <= 0) return [];
        $rows = [];
        $stmt = $conn->prepare(
            "SELECT cr.id AS class_record_id,
                    cr.section, cr.academic_year, cr.semester,
                    COALESCE(NULLIF(TRIM(cr.year_level), ''), 'N/A') AS year_level,
                    s.subject_code, s.subject_name, s.course,
                    ta.teacher_role
             FROM teacher_assignments ta
             JOIN class_records cr ON cr.id = ta.class_record_id
             JOIN subjects s ON s.id = cr.subject_id
             WHERE ta.teacher_id = ?
               AND ta.status = 'active'
               AND cr.status = 'active'
             ORDER BY cr.academic_year DESC, cr.semester ASC, s.subject_code ASC, cr.section ASC
             LIMIT 500"
        );
        if (!$stmt) return $rows;
        $stmt->bind_param('i', $teacherId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($r = $res->fetch_assoc())) {
            $rows[] = $r;
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('ttq_fetch_teacher_component_options')) {
    function ttq_fetch_teacher_component_options(mysqli $conn, $teacherId, $classRecordId = 0, $term = '') {
        $teacherId = (int) $teacherId;
        $classRecordId = (int) $classRecordId;
        $term = strtolower(trim((string) $term));
        if ($teacherId <= 0) return [];
        if (!in_array($term, ['midterm', 'final'], true)) $term = '';

        $rows = [];
        $sql =
            "SELECT gc.id AS component_id,
                    gc.component_name,
                    gc.component_code,
                    gc.component_type,
                    gc.weight AS component_weight,
                    sgc.term,
                    cr.id AS class_record_id,
                    cr.section,
                    cr.academic_year,
                    cr.semester,
                    s.subject_code,
                    s.subject_name
             FROM teacher_assignments ta
             JOIN class_records cr ON cr.id = ta.class_record_id AND cr.status = 'active'
             JOIN subjects s ON s.id = cr.subject_id
             JOIN section_grading_configs sgc
                ON sgc.subject_id = cr.subject_id
               AND sgc.section = cr.section
               AND sgc.academic_year = cr.academic_year
               AND sgc.semester = cr.semester
             JOIN grading_components gc ON gc.section_config_id = sgc.id AND gc.is_active = 1
             WHERE ta.teacher_id = ?
               AND ta.status = 'active'";
        if ($classRecordId > 0) $sql .= " AND cr.id = " . $classRecordId;
        if ($term !== '') $sql .= " AND sgc.term = '" . $conn->real_escape_string($term) . "'";
        $sql .= " ORDER BY cr.academic_year DESC, cr.semester ASC, s.subject_code ASC, cr.section ASC, gc.display_order ASC, gc.id ASC
                  LIMIT 2000";

        $stmt = $conn->prepare($sql);
        if (!$stmt) return $rows;
        $stmt->bind_param('i', $teacherId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($r = $res->fetch_assoc())) $rows[] = $r;
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('ttq_component_belongs_to_teacher')) {
    function ttq_component_belongs_to_teacher(mysqli $conn, $teacherId, $componentId) {
        $teacherId = (int) $teacherId;
        $componentId = (int) $componentId;
        if ($teacherId <= 0 || $componentId <= 0) return false;
        $stmt = $conn->prepare(
            "SELECT gc.id
             FROM grading_components gc
             JOIN section_grading_configs sgc ON sgc.id = gc.section_config_id
             JOIN class_records cr
                ON cr.subject_id = sgc.subject_id
               AND cr.section = sgc.section
               AND cr.academic_year = sgc.academic_year
               AND cr.semester = sgc.semester
               AND cr.status = 'active'
             JOIN teacher_assignments ta ON ta.class_record_id = cr.id
             WHERE gc.id = ?
               AND gc.is_active = 1
               AND ta.teacher_id = ?
               AND ta.status = 'active'
             LIMIT 1"
        );
        if (!$stmt) return false;
        $stmt->bind_param('ii', $componentId, $teacherId);
        $stmt->execute();
        $res = $stmt->get_result();
        $ok = ($res && $res->num_rows === 1);
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('ttq_create_assessment_from_document')) {
    function ttq_create_assessment_from_document(mysqli $conn, $teacherId, array $documentRow, $componentId, $trackIndex = -1, &$error = '') {
        $error = '';
        $teacherId = (int) $teacherId;
        $componentId = (int) $componentId;
        $trackIndex = (int) $trackIndex;
        if ($teacherId <= 0 || $componentId <= 0) {
            $error = 'Invalid assessment target.';
            return 0;
        }
        if (!ttq_component_belongs_to_teacher($conn, $teacherId, $componentId)) {
            $error = 'Selected component is not accessible for this teacher.';
            return 0;
        }

        $content = ttq_decode_content_json((string) ($documentRow['content_json'] ?? ''));
        $tracks = isset($content['tracks']) && is_array($content['tracks']) ? $content['tracks'] : [];
        if (count($tracks) === 0) {
            $error = 'No track content found.';
            return 0;
        }

        $selectedTracks = [];
        if ($trackIndex >= 0 && isset($tracks[$trackIndex])) {
            $selectedTracks[] = ttq_normalize_track($tracks[$trackIndex], $trackIndex);
        } else {
            foreach ($tracks as $i => $t) $selectedTracks[] = ttq_normalize_track($t, $i);
        }
        if (count($selectedTracks) === 0) {
            $error = 'No track selected.';
            return 0;
        }

        $title = ttq_clean_text((string) ($documentRow['title'] ?? ''), 120);
        if ($title === '') $title = 'TQS Assessment';
        if (count($selectedTracks) === 1) {
            $sc = ttq_clean_text((string) ($selectedTracks[0]['subject_code'] ?? ''), 40);
            if ($sc !== '') $title .= ' - ' . $sc;
        } else {
            $title .= ' - Combined';
        }
        if (strlen($title) > 120) $title = substr($title, 0, 120);

        $instructions = [];
        $questionRows = [];
        $totalMax = 0.0;
        $qOrder = 1;

        foreach ($selectedTracks as $ti => $track) {
            $subjectLabel = trim((string) ($track['subject_code'] ?? ''));
            if ($subjectLabel !== '') $subjectLabel .= ' - ';
            $subjectLabel .= trim((string) ($track['subject_name'] ?? ''));
            if ($subjectLabel === '') $subjectLabel = 'Track ' . ((int) $ti + 1);

            $inst = trim((string) ($track['general_instruction'] ?? ''));
            if ($inst !== '') {
                $instructions[] = $subjectLabel . ': ' . $inst;
            } else {
                $instructions[] = $subjectLabel . ': Follow the teacher instructions in the source TQS.';
            }

            $questions = isset($track['questions']) && is_array($track['questions']) ? $track['questions'] : [];
            foreach ($questions as $q) {
                $nq = ttq_normalize_question($q);
                $qt = trim((string) $nq['question_text']);
                if ($qt === '') continue;
                $points = ttq_safe_float($nq['points'] ?? 1.0, 0.0, 1000.0);
                if ($points <= 0) $points = 1.0;
                $answer = ttq_clean_multiline((string) ($nq['answer_key'] ?? ''), 1500);
                $questionRows[] = [
                    'question_text' => '[' . $subjectLabel . '] ' . $qt,
                    'answer_text' => $answer,
                    'default_mark' => $points,
                    'display_order' => $qOrder++,
                ];
                $totalMax += $points;
            }
        }

        if ($totalMax <= 0.0) $totalMax = ttq_document_total_points($documentRow);
        if ($totalMax <= 0.0) $totalMax = 100.0;
        $totalMax = round($totalMax, 2);

        $instructionText = ttq_clean_multiline(implode("\n\n", $instructions), 5000);
        if ($instructionText === '') $instructionText = 'TQS-generated assessment.';

        $displayOrder = 1;
        $orderStmt = $conn->prepare("SELECT COALESCE(MAX(display_order), 0) + 1 AS next_order FROM grading_assessments WHERE grading_component_id = ?");
        if ($orderStmt) {
            $orderStmt->bind_param('i', $componentId);
            $orderStmt->execute();
            $res = $orderStmt->get_result();
            if ($res && $res->num_rows === 1) $displayOrder = (int) ($res->fetch_assoc()['next_order'] ?? 1);
            $orderStmt->close();
        }
        if ($displayOrder <= 0) $displayOrder = 1;

        $conn->begin_transaction();
        try {
            $assessmentId = 0;
            $ins = $conn->prepare(
                "INSERT INTO grading_assessments
                    (grading_component_id, name, max_score, assessment_date, assessment_mode, module_type, instructions, display_order, created_by, require_proof_upload, is_active)
                 VALUES (?, ?, ?, CURDATE(), 'quiz', 'assessment', ?, ?, ?, 0, 1)"
            );
            if (!$ins) throw new Exception('assessment_insert_prepare_failed');
            $ins->bind_param('isdsii', $componentId, $title, $totalMax, $instructionText, $displayOrder, $teacherId);
            if (!$ins->execute()) {
                $ins->close();
                throw new Exception('assessment_insert_failed');
            }
            $assessmentId = (int) $conn->insert_id;
            $ins->close();
            if ($assessmentId <= 0) throw new Exception('assessment_insert_no_id');

            if (count($questionRows) > 0) {
                $insQ = $conn->prepare(
                    "INSERT INTO grading_assessment_questions
                        (assessment_id, question_type, question_text, options_json, answer_text, default_mark, display_order, is_required, is_active, created_by)
                     VALUES (?, 'short_answer', ?, NULL, ?, ?, ?, 1, 1, ?)"
                );
                if (!$insQ) throw new Exception('question_insert_prepare_failed');
                foreach ($questionRows as $qr) {
                    $qt = (string) ($qr['question_text'] ?? '');
                    $at = (string) ($qr['answer_text'] ?? '');
                    $mk = ttq_safe_float($qr['default_mark'] ?? 1.0, 0.0, 1000.0);
                    if ($mk <= 0.0) $mk = 1.0;
                    $ord = (int) ($qr['display_order'] ?? 0);
                    if ($ord <= 0) $ord = 1;
                    $insQ->bind_param('issdii', $assessmentId, $qt, $at, $mk, $ord, $teacherId);
                    if (!$insQ->execute()) {
                        $insQ->close();
                        throw new Exception('question_insert_failed');
                    }
                }
                $insQ->close();
            }

            $conn->commit();
            return $assessmentId;
        } catch (Throwable $e) {
            $conn->rollback();
            $error = 'Unable to create assessment from TQS.';
            return 0;
        }
    }
}

if (!function_exists('ttq_doc_status_label')) {
    function ttq_doc_status_label($status) {
        $status = strtolower(trim((string) $status));
        if ($status === 'approved') return 'Approved';
        if ($status === 'pending_reapproval') return 'Pending Re-Approval';
        return 'Draft';
    }
}

if (!function_exists('ttq_doc_status_badge_class')) {
    function ttq_doc_status_badge_class($status) {
        $status = strtolower(trim((string) $status));
        if ($status === 'approved') return 'bg-success-subtle text-success';
        if ($status === 'pending_reapproval') return 'bg-warning-subtle text-warning';
        return 'bg-secondary-subtle text-secondary';
    }
}

if (!function_exists('ttq_slugify')) {
    function ttq_slugify($value, $fallback = 'tos-tqs') {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value);
        if (!is_string($value)) $value = '';
        $value = trim($value, '-');
        if ($value === '') $value = trim((string) $fallback);
        if ($value === '') $value = 'tos-tqs';
        if (strlen($value) > 80) $value = substr($value, 0, 80);
        return $value;
    }
}

if (!function_exists('ttq_document_filename_base')) {
    function ttq_document_filename_base(array $row) {
        $title = ttq_clean_text((string) ($row['title'] ?? ''), 120);
        if ($title === '') $title = 'TOS-TQS';
        $term = ttq_term_enum((string) ($row['term'] ?? 'custom'));
        $suffix = $term === 'custom' ? 'custom' : $term;
        return ttq_slugify($title . '-' . $suffix);
    }
}

if (!function_exists('ttq_nf')) {
    function ttq_nf($value, $decimals = 2) {
        $decimals = max(0, (int) $decimals);
        return number_format((float) $value, $decimals, '.', ',');
    }
}

if (!function_exists('ttq_track_display_title')) {
    function ttq_track_display_title(array $track, $index = 0) {
        $track = ttq_normalize_track($track, (int) $index);
        $subjectCode = trim((string) ($track['subject_code'] ?? ''));
        $subjectName = trim((string) ($track['subject_name'] ?? ''));
        $trackLabel = trim((string) ($track['track_label'] ?? ''));
        $parts = [];
        if ($subjectCode !== '') $parts[] = $subjectCode;
        if ($subjectName !== '') $parts[] = $subjectName;
        $title = trim(implode(' - ', $parts));
        if ($title === '') $title = 'Track ' . ((int) $index + 1);
        if ($trackLabel !== '') $title .= ' (' . $trackLabel . ')';
        return $title;
    }
}

if (!function_exists('ttq_render_tos_table_html')) {
    function ttq_render_tos_table_html(array $track) {
        $track = ttq_normalize_track($track, 0);
        $dims = ttq_dimension_keys();
        $labels = ttq_dimension_labels();
        $rows = isset($track['tos_rows']) && is_array($track['tos_rows']) ? $track['tos_rows'] : [];

        $totals = [
            'percentage' => 0.0,
        ];
        foreach ($dims as $dk) $totals[$dk] = 0.0;

        $out = '';
        $out .= '<table class="ttq-table">';
        $out .= '<thead><tr>';
        $out .= '<th style="min-width:180px;">Content Topic</th>';
        $out .= '<th style="width:90px;">Hours</th>';
        $out .= '<th style="width:90px;">%</th>';
        foreach ($dims as $dk) {
            $out .= '<th style="width:84px;">' . ttq_h((string) ($labels[$dk] ?? ucfirst($dk))) . '</th>';
        }
        $out .= '</tr></thead><tbody>';

        if (count($rows) === 0) {
            $out .= '<tr><td colspan="' . (3 + count($dims)) . '" class="ttq-muted">No TOS rows yet.</td></tr>';
        } else {
            foreach ($rows as $row) {
                $nr = ttq_normalize_tos_row($row);
                $rowClass = !empty($nr['is_recommended']) ? ' class="ttq-recommended"' : '';
                $out .= '<tr' . $rowClass . '>';
                $out .= '<td>' . ttq_h((string) $nr['topic']) . '</td>';
                $out .= '<td class="ttq-center">' . ttq_h((string) $nr['hours']) . '</td>';
                $out .= '<td class="ttq-right">' . ttq_nf($nr['percentage']) . '</td>';
                $totals['percentage'] += (float) $nr['percentage'];
                foreach ($dims as $dk) {
                    $val = ttq_safe_float($nr[$dk] ?? 0.0, 0.0, 1000000.0);
                    $totals[$dk] += $val;
                    $out .= '<td class="ttq-right">' . ttq_nf($val) . '</td>';
                }
                $out .= '</tr>';
            }
        }

        $out .= '<tr class="ttq-total">';
        $out .= '<td colspan="2">Totals</td>';
        $out .= '<td class="ttq-right">' . ttq_nf($totals['percentage']) . '</td>';
        foreach ($dims as $dk) {
            $out .= '<td class="ttq-right">' . ttq_nf($totals[$dk]) . '</td>';
        }
        $out .= '</tr>';
        $out .= '</tbody></table>';
        return $out;
    }
}

if (!function_exists('ttq_render_questions_table_html')) {
    function ttq_render_questions_table_html(array $track, $showAnswerKey = true) {
        $track = ttq_normalize_track($track, 0);
        $labels = ttq_dimension_labels();
        $questions = isset($track['questions']) && is_array($track['questions']) ? $track['questions'] : [];
        $sum = 0.0;

        $out = '';
        $out .= '<table class="ttq-table">';
        $out .= '<thead><tr>';
        $out .= '<th style="width:50px;">#</th>';
        $out .= '<th>Question</th>';
        $out .= '<th style="width:90px;">Points</th>';
        $out .= '<th style="width:130px;">Dimension</th>';
        if ($showAnswerKey) $out .= '<th style="min-width:220px;">Answer Key</th>';
        $out .= '</tr></thead><tbody>';

        if (count($questions) === 0) {
            $colspan = $showAnswerKey ? 5 : 4;
            $out .= '<tr><td colspan="' . $colspan . '" class="ttq-muted">No questions yet.</td></tr>';
        } else {
            foreach ($questions as $i => $q) {
                $nq = ttq_normalize_question($q);
                $sum += (float) $nq['points'];
                $out .= '<tr>';
                $out .= '<td class="ttq-center">' . ((int) $i + 1) . '</td>';
                $out .= '<td>' . nl2br(ttq_h((string) $nq['question_text'])) . '</td>';
                $out .= '<td class="ttq-right">' . ttq_nf($nq['points']) . '</td>';
                $dim = (string) ($labels[(string) ($nq['cognitive_dimension'] ?? '')] ?? ucfirst((string) ($nq['cognitive_dimension'] ?? 'remember')));
                $out .= '<td class="ttq-center">' . ttq_h($dim) . '</td>';
                if ($showAnswerKey) {
                    $out .= '<td>' . nl2br(ttq_h((string) $nq['answer_key'])) . '</td>';
                }
                $out .= '</tr>';
            }
        }

        $out .= '<tr class="ttq-total">';
        $out .= '<td colspan="' . ($showAnswerKey ? '2' : '1') . '">Total Points</td>';
        $out .= '<td class="ttq-right">' . ttq_nf($sum) . '</td>';
        $out .= '<td' . ($showAnswerKey ? ' colspan="2"' : '') . '></td>';
        $out .= '</tr>';

        $out .= '</tbody></table>';
        return $out;
    }
}

if (!function_exists('ttq_render_rubric_levels_table_html')) {
    function ttq_render_rubric_levels_table_html(array $track) {
        $track = ttq_normalize_track($track, 0);
        $levels = isset($track['rubric_levels']) && is_array($track['rubric_levels']) ? $track['rubric_levels'] : [];

        $out = '';
        $out .= '<table class="ttq-table">';
        $out .= '<thead><tr>';
        $out .= '<th style="width:120px;">Score</th>';
        $out .= '<th style="width:180px;">Label</th>';
        $out .= '<th>Description</th>';
        $out .= '<th>Criteria</th>';
        $out .= '</tr></thead><tbody>';

        if (count($levels) === 0) {
            $out .= '<tr><td colspan="4" class="ttq-muted">No rubric levels yet.</td></tr>';
        } else {
            foreach ($levels as $lv) {
                $nl = ttq_normalize_rubric_level($lv);
                $out .= '<tr>';
                $out .= '<td class="ttq-center">' . ttq_h((string) $nl['score']) . '</td>';
                $out .= '<td>' . ttq_h((string) $nl['label']) . '</td>';
                $out .= '<td>' . nl2br(ttq_h((string) $nl['description'])) . '</td>';
                $out .= '<td>' . nl2br(ttq_h((string) $nl['criteria'])) . '</td>';
                $out .= '</tr>';
            }
        }

        $out .= '</tbody></table>';
        return $out;
    }
}

if (!function_exists('ttq_render_rubric_criteria_table_html')) {
    function ttq_render_rubric_criteria_table_html(array $track) {
        $track = ttq_normalize_track($track, 0);
        $rows = isset($track['rubric_criteria']) && is_array($track['rubric_criteria']) ? $track['rubric_criteria'] : [];

        $out = '';
        $out .= '<table class="ttq-table">';
        $out .= '<thead><tr>';
        $out .= '<th>Criterion</th>';
        $out .= '<th style="width:100px;">Excellent</th>';
        $out .= '<th style="width:100px;">Good</th>';
        $out .= '<th style="width:100px;">Fair</th>';
        $out .= '<th style="width:100px;">Needs</th>';
        $out .= '<th>Notes</th>';
        $out .= '</tr></thead><tbody>';

        if (count($rows) === 0) {
            $out .= '<tr><td colspan="6" class="ttq-muted">No rubric criteria yet.</td></tr>';
        } else {
            foreach ($rows as $rc) {
                $nr = ttq_normalize_rubric_criterion($rc);
                $out .= '<tr>';
                $out .= '<td>' . ttq_h((string) $nr['criterion']) . '</td>';
                $out .= '<td class="ttq-right">' . ttq_nf($nr['excellent_points']) . '</td>';
                $out .= '<td class="ttq-right">' . ttq_nf($nr['good_points']) . '</td>';
                $out .= '<td class="ttq-right">' . ttq_nf($nr['fair_points']) . '</td>';
                $out .= '<td class="ttq-right">' . ttq_nf($nr['needs_points']) . '</td>';
                $out .= '<td>' . nl2br(ttq_h((string) $nr['notes'])) . '</td>';
                $out .= '</tr>';
            }
        }

        $out .= '</tbody></table>';
        return $out;
    }
}

if (!function_exists('ttq_render_signature_block_html')) {
    function ttq_render_signature_block_html($label, $name, $signaturePath) {
        $label = ttq_clean_text((string) $label, 80);
        $name = ttq_clean_text((string) $name, 120);
        $signaturePath = ttq_clean_text((string) $signaturePath, 255);
        $imgData = $signaturePath !== '' ? ttq_image_data_uri($signaturePath) : '';

        $out = '<div class="ttq-sign-block">';
        $out .= '<div class="ttq-sign-caption">' . ttq_h($label) . '</div>';
        if ($imgData !== '') {
            $out .= '<div class="ttq-sign-image"><img src="' . ttq_h($imgData) . '" alt="' . ttq_h($label) . ' signature"></div>';
        } else {
            $out .= '<div class="ttq-sign-line"></div>';
        }
        $out .= '<div class="ttq-sign-name">' . ttq_h($name !== '' ? $name : ' ') . '</div>';
        $out .= '</div>';
        return $out;
    }
}

if (!function_exists('ttq_render_document_html')) {
    function ttq_render_document_html(array $row, array $options = []) {
        $viewer = strtolower(trim((string) ($options['viewer'] ?? 'teacher')));
        if (!in_array($viewer, ['teacher', 'student'], true)) $viewer = 'teacher';

        $showAnswers = isset($options['show_answer_key'])
            ? !empty($options['show_answer_key'])
            : ($viewer === 'teacher' ? true : ttq_student_answer_key_visible($row));

        $title = ttq_clean_text((string) ($row['title'] ?? ''), 180);
        if ($title === '') $title = 'Table of Specifications and Test Questionnaire';
        $term = ttq_term_label((string) ($row['term'] ?? 'custom'));
        $mode = ttq_document_mode((string) ($row['document_mode'] ?? 'standalone'));
        $modeLabel = $mode === 'combined' ? 'Combined Subject' : 'Stand-Alone Subject';
        $statusLabel = ttq_doc_status_label((string) ($row['status'] ?? 'draft'));
        $academicYear = ttq_clean_text((string) ($row['academic_year'] ?? ''), 40);
        $semester = ttq_clean_text((string) ($row['semester'] ?? ''), 40);
        $examEndAt = trim((string) ($row['exam_end_at'] ?? ''));
        $examFinishedAt = trim((string) ($row['exam_finished_at'] ?? ''));
        $preparedBy = ttq_clean_text((string) ($row['prepared_by_name'] ?? ''), 120);
        $preparedSig = ttq_clean_text((string) ($row['prepared_signature_path'] ?? ''), 255);
        $approvedBy = ttq_clean_text((string) ($row['approved_by_name'] ?? ''), 120);
        $approvedSig = ttq_clean_text((string) ($row['approved_signature_path'] ?? ''), 255);

        $content = isset($row['content']) && is_array($row['content'])
            ? ttq_normalize_content($row['content'])
            : ttq_decode_content_json((string) ($row['content_json'] ?? ''));

        $schoolName = ttq_clean_text((string) ($content['school_name'] ?? ''), 220);
        $docLabel = ttq_clean_text((string) ($content['document_label'] ?? ''), 180);
        $tracks = isset($content['tracks']) && is_array($content['tracks']) ? $content['tracks'] : [];

        $subtitleParts = [];
        if ($term !== '') $subtitleParts[] = $term;
        if ($academicYear !== '') $subtitleParts[] = 'A.Y. ' . $academicYear;
        if ($semester !== '') $subtitleParts[] = $semester;
        $subtitleParts[] = $modeLabel;
        $subtitle = implode(' | ', array_filter($subtitleParts, static function ($v) {
            return trim((string) $v) !== '';
        }));

        $answerVisibility = $showAnswers
            ? 'Answer key is visible in this copy.'
            : 'Answer key is hidden in this student copy.';

        $out = '<!doctype html><html lang="en"><head><meta charset="UTF-8">';
        $out .= '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
        $out .= '<title>' . ttq_h($title) . '</title>';
        $out .= '<style>
            :root { --ink:#0f172a; --muted:#475569; --line:#cbd5e1; --shade:#f8fafc; --accent:#1d4ed8; }
            * { box-sizing:border-box; }
            body { margin:0; font-family:Arial, Helvetica, sans-serif; color:var(--ink); background:#fff; }
            .ttq-page { max-width:1020px; margin:0 auto; padding:24px 20px 40px; }
            .ttq-center { text-align:center; }
            .ttq-right { text-align:right; }
            .ttq-muted { color:var(--muted); }
            .ttq-header { border:1px solid var(--line); border-radius:8px; padding:14px 16px; margin-bottom:14px; }
            .ttq-school { font-weight:700; font-size:14px; text-transform:uppercase; letter-spacing:.05em; }
            .ttq-doc-label { font-size:13px; margin-top:2px; color:var(--muted); }
            .ttq-title { margin:8px 0 4px; font-size:22px; line-height:1.2; }
            .ttq-subtitle { color:var(--muted); font-size:13px; }
            .ttq-meta { width:100%; border-collapse:collapse; margin-top:12px; font-size:12px; }
            .ttq-meta td { border:1px solid var(--line); padding:6px 8px; vertical-align:top; }
            .ttq-chip { display:inline-block; border-radius:999px; padding:3px 9px; font-size:11px; border:1px solid var(--line); background:var(--shade); }
            .ttq-note { margin-top:8px; color:var(--muted); font-size:12px; }
            .ttq-track { margin-top:18px; border:1px solid var(--line); border-radius:8px; overflow:hidden; }
            .ttq-track-head { background:var(--shade); padding:10px 12px; border-bottom:1px solid var(--line); }
            .ttq-track-head h3 { margin:0; font-size:16px; }
            .ttq-track-head .meta { margin-top:4px; font-size:12px; color:var(--muted); }
            .ttq-block { padding:10px 12px; border-bottom:1px solid var(--line); }
            .ttq-block:last-child { border-bottom:0; }
            .ttq-block h4 { margin:0 0 8px; font-size:13px; letter-spacing:.04em; text-transform:uppercase; color:#334155; }
            .ttq-text { font-size:12px; color:#1e293b; white-space:pre-wrap; }
            .ttq-table { width:100%; border-collapse:collapse; font-size:12px; }
            .ttq-table th, .ttq-table td { border:1px solid var(--line); padding:6px 7px; vertical-align:top; }
            .ttq-table th { background:#f1f5f9; text-align:center; }
            .ttq-table .ttq-total td { font-weight:700; background:#f8fafc; }
            .ttq-table .ttq-recommended td { background:#eef2ff; }
            .ttq-signatures { display:flex; gap:16px; margin-top:18px; }
            .ttq-sign-block { flex:1; border:1px dashed var(--line); border-radius:8px; padding:8px; min-height:126px; }
            .ttq-sign-caption { font-size:11px; color:var(--muted); margin-bottom:8px; }
            .ttq-sign-image { height:58px; display:flex; align-items:flex-end; justify-content:center; margin-bottom:4px; }
            .ttq-sign-image img { max-height:54px; max-width:100%; object-fit:contain; }
            .ttq-sign-line { border-bottom:1px solid #475569; height:58px; margin-bottom:4px; }
            .ttq-sign-name { text-align:center; font-size:12px; font-weight:700; min-height:16px; }
            .ttq-footer { margin-top:12px; font-size:11px; color:var(--muted); }
            .ttq-page-break { page-break-before: always; break-before: page; }
        </style>';
        $out .= '</head><body><div class="ttq-page">';

        $out .= '<section class="ttq-header">';
        if ($schoolName !== '') $out .= '<div class="ttq-school">' . ttq_h($schoolName) . '</div>';
        if ($docLabel !== '') $out .= '<div class="ttq-doc-label">' . ttq_h($docLabel) . '</div>';
        $out .= '<h1 class="ttq-title">' . ttq_h($title) . '</h1>';
        if ($subtitle !== '') $out .= '<div class="ttq-subtitle">' . ttq_h($subtitle) . '</div>';
        $out .= '<table class="ttq-meta"><tbody>';
        $out .= '<tr><td><strong>Status</strong><br>' . ttq_h($statusLabel) . '</td>';
        $out .= '<td><strong>Version</strong><br>v' . (int) ($row['version_no'] ?? 1) . '</td>';
        $out .= '<td><strong>Viewer</strong><br>' . ttq_h(ucfirst($viewer)) . '</td>';
        $out .= '<td><strong>Answer Key</strong><br>' . ($showAnswers ? 'Visible' : 'Hidden') . '</td></tr>';
        $out .= '<tr><td><strong>Term</strong><br>' . ttq_h($term) . '</td>';
        $out .= '<td><strong>Academic Year</strong><br>' . ttq_h($academicYear) . '</td>';
        $out .= '<td><strong>Semester</strong><br>' . ttq_h($semester) . '</td>';
        $out .= '<td><strong>Mode</strong><br>' . ttq_h($modeLabel) . '</td></tr>';
        $out .= '<tr><td><strong>Exam End</strong><br>' . ttq_h($examEndAt) . '</td>';
        $out .= '<td><strong>Exam Finished</strong><br>' . ttq_h($examFinishedAt !== '' ? $examFinishedAt : 'Not yet') . '</td>';
        $out .= '<td colspan="2"><strong>Visibility Rule</strong><br>' . ttq_h($answerVisibility) . '</td></tr>';
        $out .= '</tbody></table>';
        $out .= '</section>';

        foreach ($tracks as $ti => $trackRow) {
            $track = ttq_normalize_track($trackRow, (int) $ti);
            $out .= '<section class="ttq-track' . ($ti > 0 ? ' ttq-page-break' : '') . '">';
            $out .= '<div class="ttq-track-head">';
            $out .= '<h3>' . ttq_h(ttq_track_display_title($track, (int) $ti)) . '</h3>';
            $meta = [];
            $examType = ucfirst((string) ($track['exam_type'] ?? 'written'));
            if ($examType !== '') $meta[] = 'Exam Type: ' . $examType;
            $meta[] = 'Total Points: ' . ttq_nf(ttq_track_total_points($track));
            $out .= '<div class="meta">' . ttq_h(implode(' | ', $meta)) . '</div>';
            $out .= '</div>';

            $instruction = trim((string) ($track['general_instruction'] ?? ''));
            if ($instruction !== '') {
                $out .= '<div class="ttq-block"><h4>General Instruction</h4><div class="ttq-text">' . nl2br(ttq_h($instruction)) . '</div></div>';
            }

            $out .= '<div class="ttq-block"><h4>Table of Specifications</h4>' . ttq_render_tos_table_html($track) . '</div>';
            $out .= '<div class="ttq-block"><h4>Test Questionnaire</h4>' . ttq_render_questions_table_html($track, $showAnswers) . '</div>';
            $out .= '<div class="ttq-block"><h4>Rubric Levels</h4>' . ttq_render_rubric_levels_table_html($track) . '</div>';
            $out .= '<div class="ttq-block"><h4>Rubric Criteria Allocation</h4>' . ttq_render_rubric_criteria_table_html($track) . '</div>';

            $notes = trim((string) ($track['notes'] ?? ''));
            if ($notes !== '') {
                $out .= '<div class="ttq-block"><h4>Track Notes</h4><div class="ttq-text">' . nl2br(ttq_h($notes)) . '</div></div>';
            }

            $out .= '</section>';
        }

        $out .= '<section class="ttq-signatures">';
        $out .= ttq_render_signature_block_html('Prepared By', $preparedBy, $preparedSig);
        $out .= ttq_render_signature_block_html('Approved By', $approvedBy, $approvedSig);
        $out .= '</section>';

        $generatedAt = date('Y-m-d H:i:s');
        $out .= '<div class="ttq-footer">';
        $out .= 'Generated on ' . ttq_h($generatedAt) . ' | ';
        $out .= 'Document ID: ' . (int) ($row['id'] ?? 0);
        $out .= '</div>';

        $out .= '</div></body></html>';
        return $out;
    }
}

if (!function_exists('ttq_document_export_filename')) {
    function ttq_document_export_filename(array $row, $ext = 'html', $viewer = 'teacher') {
        $ext = strtolower(trim((string) $ext));
        if (!in_array($ext, ['html', 'pdf', 'docx'], true)) $ext = 'html';
        $viewer = strtolower(trim((string) $viewer));
        if (!in_array($viewer, ['teacher', 'student'], true)) $viewer = 'teacher';

        $base = ttq_document_filename_base($row);
        if ($viewer === 'student') $base .= '-student';
        else $base .= '-teacher';
        return $base . '.' . $ext;
    }
}
