<?php
require_once __DIR__ . '/attendance_attachments.php';
require_once __DIR__ . '/grading.php';
require_once __DIR__ . '/face_profiles.php';
require_once __DIR__ . '/attendance_geofence.php';

if (!function_exists('attendance_checkin_ensure_tables')) {
    function attendance_checkin_ensure_tables(mysqli $conn) {
        $conn->query(
            "CREATE TABLE IF NOT EXISTS attendance_sessions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                class_record_id INT NOT NULL,
                teacher_id INT NOT NULL,
                session_label VARCHAR(120) NOT NULL,
                session_date DATE NOT NULL,
                attendance_code VARCHAR(64) NOT NULL,
                checkin_method VARCHAR(16) NOT NULL DEFAULT 'code',
                face_verify_required TINYINT(1) NOT NULL DEFAULT 0,
                face_threshold DECIMAL(5,3) NOT NULL DEFAULT 0.550,
                starts_at DATETIME NOT NULL,
                present_until DATETIME NOT NULL,
                late_until DATETIME NOT NULL,
                late_minutes INT UNSIGNED NOT NULL DEFAULT 15,
                is_deleted TINYINT(1) NOT NULL DEFAULT 0,
                deleted_at DATETIME NULL,
                is_closed TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_attsess_class_date (class_record_id, session_date, starts_at),
                KEY idx_attsess_teacher_date (teacher_id, session_date),
                KEY idx_attsess_window (starts_at, late_until),
                KEY idx_attsess_deleted (is_deleted, starts_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        if (function_exists('attendance_db_has_column')) {
            if (!attendance_db_has_column($conn, 'attendance_sessions', 'checkin_method')) {
                $conn->query("ALTER TABLE attendance_sessions ADD COLUMN checkin_method VARCHAR(16) NOT NULL DEFAULT 'code' AFTER attendance_code");
            }
            if (!attendance_db_has_column($conn, 'attendance_sessions', 'face_verify_required')) {
                $conn->query("ALTER TABLE attendance_sessions ADD COLUMN face_verify_required TINYINT(1) NOT NULL DEFAULT 0 AFTER checkin_method");
            }
            if (!attendance_db_has_column($conn, 'attendance_sessions', 'face_threshold')) {
                $conn->query("ALTER TABLE attendance_sessions ADD COLUMN face_threshold DECIMAL(5,3) NOT NULL DEFAULT 0.550 AFTER face_verify_required");
            }
            if (!attendance_db_has_column($conn, 'attendance_sessions', 'is_deleted')) {
                $conn->query("ALTER TABLE attendance_sessions ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0 AFTER late_minutes");
            }
            if (!attendance_db_has_column($conn, 'attendance_sessions', 'deleted_at')) {
                $conn->query("ALTER TABLE attendance_sessions ADD COLUMN deleted_at DATETIME NULL AFTER is_deleted");
            }
        }

        $conn->query(
            "CREATE TABLE IF NOT EXISTS attendance_submissions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                session_id BIGINT UNSIGNED NOT NULL,
                class_record_id INT NOT NULL,
                student_id INT NOT NULL,
                submitted_by INT NOT NULL,
                submitted_code VARCHAR(64) NOT NULL,
                submission_method VARCHAR(16) NOT NULL DEFAULT 'code',
                status ENUM('present','late') NOT NULL,
                submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                face_image_path VARCHAR(1024) NULL,
                face_image_mime VARCHAR(100) NULL,
                face_image_size BIGINT UNSIGNED NULL,
                face_captured_at DATETIME NULL,
                face_match_passed TINYINT(1) NULL,
                face_match_distance DECIMAL(6,4) NULL,
                location_latitude DECIMAL(10,8) NULL,
                location_longitude DECIMAL(11,8) NULL,
                location_accuracy_m DECIMAL(8,2) NULL,
                location_captured_at DATETIME NULL,
                location_distance_m DECIMAL(10,2) NULL,
                location_within_boundary TINYINT(1) NULL,
                location_boundary_radius_m INT UNSIGNED NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_attsub_session_student (session_id, student_id),
                KEY idx_attsub_class_student (class_record_id, student_id),
                KEY idx_attsub_submitted (submitted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        if (function_exists('attendance_db_has_column')) {
            if (!attendance_db_has_column($conn, 'attendance_submissions', 'submission_method')) {
                $conn->query("ALTER TABLE attendance_submissions ADD COLUMN submission_method VARCHAR(16) NOT NULL DEFAULT 'code' AFTER submitted_code");
            }
            if (!attendance_db_has_column($conn, 'attendance_submissions', 'face_image_path')) {
                $conn->query("ALTER TABLE attendance_submissions ADD COLUMN face_image_path VARCHAR(1024) NULL AFTER submitted_at");
            }
            if (!attendance_db_has_column($conn, 'attendance_submissions', 'face_image_mime')) {
                $conn->query("ALTER TABLE attendance_submissions ADD COLUMN face_image_mime VARCHAR(100) NULL AFTER face_image_path");
            }
            if (!attendance_db_has_column($conn, 'attendance_submissions', 'face_image_size')) {
                $conn->query("ALTER TABLE attendance_submissions ADD COLUMN face_image_size BIGINT UNSIGNED NULL AFTER face_image_mime");
            }
            if (!attendance_db_has_column($conn, 'attendance_submissions', 'face_captured_at')) {
                $conn->query("ALTER TABLE attendance_submissions ADD COLUMN face_captured_at DATETIME NULL AFTER face_image_size");
            }
            if (!attendance_db_has_column($conn, 'attendance_submissions', 'face_match_passed')) {
                $conn->query("ALTER TABLE attendance_submissions ADD COLUMN face_match_passed TINYINT(1) NULL AFTER face_captured_at");
            }
            if (!attendance_db_has_column($conn, 'attendance_submissions', 'face_match_distance')) {
                $conn->query("ALTER TABLE attendance_submissions ADD COLUMN face_match_distance DECIMAL(6,4) NULL AFTER face_match_passed");
            }
            if (!attendance_db_has_column($conn, 'attendance_submissions', 'location_latitude')) {
                $conn->query("ALTER TABLE attendance_submissions ADD COLUMN location_latitude DECIMAL(10,8) NULL AFTER face_match_distance");
            }
            if (!attendance_db_has_column($conn, 'attendance_submissions', 'location_longitude')) {
                $conn->query("ALTER TABLE attendance_submissions ADD COLUMN location_longitude DECIMAL(11,8) NULL AFTER location_latitude");
            }
            if (!attendance_db_has_column($conn, 'attendance_submissions', 'location_accuracy_m')) {
                $conn->query("ALTER TABLE attendance_submissions ADD COLUMN location_accuracy_m DECIMAL(8,2) NULL AFTER location_longitude");
            }
            if (!attendance_db_has_column($conn, 'attendance_submissions', 'location_captured_at')) {
                $conn->query("ALTER TABLE attendance_submissions ADD COLUMN location_captured_at DATETIME NULL AFTER location_accuracy_m");
            }
            if (!attendance_db_has_column($conn, 'attendance_submissions', 'location_distance_m')) {
                $conn->query("ALTER TABLE attendance_submissions ADD COLUMN location_distance_m DECIMAL(10,2) NULL AFTER location_captured_at");
            }
            if (!attendance_db_has_column($conn, 'attendance_submissions', 'location_within_boundary')) {
                $conn->query("ALTER TABLE attendance_submissions ADD COLUMN location_within_boundary TINYINT(1) NULL AFTER location_distance_m");
            }
            if (!attendance_db_has_column($conn, 'attendance_submissions', 'location_boundary_radius_m')) {
                $conn->query("ALTER TABLE attendance_submissions ADD COLUMN location_boundary_radius_m INT UNSIGNED NULL AFTER location_within_boundary");
            }
        }

        $conn->query(
            "CREATE TABLE IF NOT EXISTS attendance_session_assessments (
                session_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                assessment_id INT NOT NULL,
                grading_component_id INT NOT NULL,
                synced_at DATETIME NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_asa_assessment (assessment_id),
                KEY idx_asa_component (grading_component_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // Keep gradebook tables available because attendance sessions can sync to assessments.
        ensure_grading_tables($conn);

        // Face registration tables (used when facial sessions require verification).
        face_profiles_ensure_tables($conn);

        // Campus-level geofence settings for attendance check-in.
        attendance_geo_ensure_table($conn);
        attendance_geo_ensure_class_table($conn);
    }
}

if (!function_exists('attendance_checkin_has_deleted_column')) {
    function attendance_checkin_has_deleted_column(mysqli $conn) {
        static $cached = null;
        if ($cached !== null) return (bool) $cached;

        if (function_exists('attendance_db_has_column')) {
            $cached = attendance_db_has_column($conn, 'attendance_sessions', 'is_deleted');
            return (bool) $cached;
        }

        $cached = false;
        return false;
    }
}

if (!function_exists('attendance_checkin_allowed_methods')) {
    function attendance_checkin_allowed_methods() {
        return ['code', 'qr', 'face'];
    }
}

if (!function_exists('attendance_checkin_normalize_method')) {
    function attendance_checkin_normalize_method($value) {
        $v = strtolower(trim((string) $value));
        if ($v === '') return 'code';
        if (in_array($v, attendance_checkin_allowed_methods(), true)) return $v;
        return 'code';
    }
}

if (!function_exists('attendance_checkin_generate_code')) {
    function attendance_checkin_generate_code($length = 10) {
        $length = (int) $length;
        if ($length < 6) $length = 6;
        if ($length > 32) $length = 32;

        // Exclude ambiguous characters (0/O, 1/I) for easier reading.
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $max = strlen($alphabet) - 1;
        $out = '';

        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }

        return $out;
    }
}

if (!function_exists('attendance_checkin_normalize_code')) {
    function attendance_checkin_normalize_code($raw) {
        $code = trim((string) $raw);
        return $code;
    }
}

if (!function_exists('attendance_checkin_validate_code')) {
    function attendance_checkin_validate_code($code) {
        $code = (string) $code;
        if ($code === '') return [false, 'Attendance code is required.'];
        if (strlen($code) < 3 || strlen($code) > 64) {
            return [false, 'Attendance code must be 3 to 64 characters.'];
        }
        if (!preg_match('/^[\x21-\x7E]+$/', $code)) {
            return [false, 'Attendance code must use visible ASCII characters only (no spaces).'];
        }
        return [true, ''];
    }
}

if (!function_exists('attendance_checkin_datetime_from_parts')) {
    function attendance_checkin_datetime_from_parts($date, $time) {
        $date = trim((string) $date);
        $time = trim((string) $time);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return '';
        if (!preg_match('/^\d{2}:\d{2}$/', $time)) return '';
        return $date . ' ' . $time . ':00';
    }
}

if (!function_exists('attendance_checkin_phase')) {
    function attendance_checkin_phase(array $session, $nowTs = null) {
        $nowTs = is_int($nowTs) ? $nowTs : time();
        $startTs = strtotime((string) ($session['starts_at'] ?? ''));
        $presentUntilTs = strtotime((string) ($session['present_until'] ?? ''));
        $lateUntilTs = strtotime((string) ($session['late_until'] ?? ''));
        $isClosed = ((int) ($session['is_closed'] ?? 0) === 1);

        if ($startTs <= 0 || $presentUntilTs <= 0 || $lateUntilTs <= 0) {
            return 'closed';
        }
        if ($isClosed) return 'closed';
        if ($nowTs < $startTs) return 'upcoming';
        if ($nowTs <= $presentUntilTs) return 'present_window';
        if ($nowTs <= $lateUntilTs) return 'late_window';
        return 'closed';
    }
}

if (!function_exists('attendance_checkin_phase_label')) {
    function attendance_checkin_phase_label($phase) {
        $phase = trim((string) $phase);
        if ($phase === 'upcoming') return 'Upcoming';
        if ($phase === 'present_window') return 'Present Window';
        if ($phase === 'late_window') return 'Late Window';
        return 'Closed';
    }
}

if (!function_exists('attendance_checkin_has_term_column')) {
    function attendance_checkin_has_term_column(mysqli $conn) {
        static $cached = null;
        if ($cached !== null) return (bool) $cached;

        if (function_exists('attendance_db_has_column')) {
            $cached = attendance_db_has_column($conn, 'section_grading_configs', 'term');
            return (bool) $cached;
        }

        $cached = false;
        return false;
    }
}

if (!function_exists('attendance_checkin_class_context')) {
    function attendance_checkin_class_context(mysqli $conn, $classRecordId) {
        $classRecordId = (int) $classRecordId;
        if ($classRecordId <= 0) return null;

        $stmt = $conn->prepare(
            "SELECT cr.id AS class_record_id,
                    cr.subject_id,
                    cr.section,
                    cr.academic_year,
                    cr.semester,
                    COALESCE(NULLIF(TRIM(cr.year_level), ''), 'N/A') AS year_key,
                    COALESCE(NULLIF(TRIM(s.course), ''), 'N/A') AS course_key
             FROM class_records cr
             JOIN subjects s ON s.id = cr.subject_id
             WHERE cr.id = ?
             LIMIT 1"
        );
        if (!$stmt) return null;
        $stmt->bind_param('i', $classRecordId);
        $stmt->execute();
        $res = $stmt->get_result();
        $ctx = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
        $stmt->close();
        return is_array($ctx) ? $ctx : null;
    }
}

if (!function_exists('attendance_checkin_attendance_components')) {
    function attendance_checkin_attendance_components(mysqli $conn, $classRecordId) {
        $ctx = attendance_checkin_class_context($conn, $classRecordId);
        if (!is_array($ctx)) return [];

        $subjectId = (int) ($ctx['subject_id'] ?? 0);
        $section = trim((string) ($ctx['section'] ?? ''));
        $academicYear = trim((string) ($ctx['academic_year'] ?? ''));
        $semester = trim((string) ($ctx['semester'] ?? ''));
        $courseKey = trim((string) ($ctx['course_key'] ?? 'N/A'));
        $yearKey = trim((string) ($ctx['year_key'] ?? 'N/A'));
        if ($courseKey === '') $courseKey = 'N/A';
        if ($yearKey === '') $yearKey = 'N/A';

        if ($subjectId <= 0 || $section === '' || $academicYear === '' || $semester === '') {
            return [];
        }

        $withTerm = attendance_checkin_has_term_column($conn);
        $termSelect = $withTerm
            ? "LOWER(TRIM(COALESCE(sgc.term, 'midterm')))"
            : "'midterm'";

        $baseSelect =
            "SELECT gc.id AS component_id,
                    sgc.id AS section_config_id,
                    " . $termSelect . " AS term_key,
                    gc.display_order
             FROM section_grading_configs sgc
             JOIN grading_components gc ON gc.section_config_id = sgc.id
             LEFT JOIN grading_categories cat ON cat.id = gc.category_id
             WHERE sgc.subject_id = ?
               AND sgc.section = ?
               AND sgc.academic_year = ?
               AND sgc.semester = ?
               AND gc.is_active = 1
               AND (
                    LOWER(TRIM(COALESCE(gc.component_name, ''))) LIKE '%attendance%'
                    OR LOWER(TRIM(COALESCE(gc.component_code, ''))) LIKE '%attendance%'
                    OR LOWER(TRIM(COALESCE(gc.component_type, ''))) = 'attendance'
                    OR LOWER(TRIM(COALESCE(cat.category_name, ''))) LIKE '%attendance%'
               )";

        $orderBy =
            " ORDER BY CASE
                    WHEN term_key = 'midterm' THEN 0
                    WHEN term_key = 'final' THEN 1
                    ELSE 2
                END ASC,
                gc.display_order ASC,
                gc.id ASC
              LIMIT 25";

        $rows = [];
        $exact = $conn->prepare($baseSelect . " AND sgc.course = ? AND sgc.year = ?" . $orderBy);
        if ($exact) {
            $exact->bind_param('isssss', $subjectId, $section, $academicYear, $semester, $courseKey, $yearKey);
            $exact->execute();
            $res = $exact->get_result();
            while ($res && ($row = $res->fetch_assoc())) $rows[] = $row;
            $exact->close();
        }

        if (count($rows) === 0) {
            $fallback = $conn->prepare($baseSelect . $orderBy);
            if ($fallback) {
                $fallback->bind_param('isss', $subjectId, $section, $academicYear, $semester);
                $fallback->execute();
                $res = $fallback->get_result();
                while ($res && ($row = $res->fetch_assoc())) $rows[] = $row;
                $fallback->close();
            }
        }

        if (count($rows) === 0) return [];

        $seen = [];
        $dedup = [];
        foreach ($rows as $row) {
            $cid = (int) ($row['component_id'] ?? 0);
            if ($cid <= 0 || isset($seen[$cid])) continue;
            $seen[$cid] = true;
            $dedup[] = $row;
        }
        return $dedup;
    }
}

if (!function_exists('attendance_checkin_next_assessment_order')) {
    function attendance_checkin_next_assessment_order(mysqli $conn, $componentId) {
        $componentId = (int) $componentId;
        if ($componentId <= 0) return 1;

        $next = 1;
        $stmt = $conn->prepare(
            "SELECT COALESCE(MAX(display_order), 0) + 1 AS next_order
             FROM grading_assessments
             WHERE grading_component_id = ?"
        );
        if ($stmt) {
            $stmt->bind_param('i', $componentId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows === 1) {
                $next = (int) ($res->fetch_assoc()['next_order'] ?? 1);
            }
            $stmt->close();
        }

        return $next > 0 ? $next : 1;
    }
}

if (!function_exists('attendance_checkin_assessment_name')) {
    function attendance_checkin_assessment_name($sessionLabel, $sessionDate) {
        $sessionLabel = trim((string) $sessionLabel);
        $sessionDate = trim((string) $sessionDate);
        if ($sessionLabel === '') $sessionLabel = 'Attendance';

        if ($sessionDate !== '') {
            if (stripos($sessionLabel, $sessionDate) === false) {
                $sessionLabel .= ' (' . $sessionDate . ')';
            }
        }

        if (strlen($sessionLabel) > 120) {
            $sessionLabel = substr($sessionLabel, 0, 120);
        }

        return $sessionLabel;
    }
}

if (!function_exists('attendance_checkin_session_assessment_binding')) {
    function attendance_checkin_session_assessment_binding(mysqli $conn, $sessionId) {
        $sessionId = (int) $sessionId;
        if ($sessionId <= 0) return null;

        $stmt = $conn->prepare(
            "SELECT asa.session_id,
                    asa.assessment_id,
                    asa.grading_component_id
             FROM attendance_session_assessments asa
             JOIN grading_assessments ga ON ga.id = asa.assessment_id
             WHERE asa.session_id = ?
             LIMIT 1"
        );
        if (!$stmt) return null;
        $stmt->bind_param('i', $sessionId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
        $stmt->close();
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('attendance_checkin_bind_session_assessment')) {
    function attendance_checkin_bind_session_assessment(mysqli $conn, $sessionId, $assessmentId, $componentId) {
        $sessionId = (int) $sessionId;
        $assessmentId = (int) $assessmentId;
        $componentId = (int) $componentId;
        if ($sessionId <= 0 || $assessmentId <= 0 || $componentId <= 0) return false;

        $stmt = $conn->prepare(
            "INSERT INTO attendance_session_assessments
                (session_id, assessment_id, grading_component_id, synced_at)
             VALUES (?, ?, ?, NULL)
             ON DUPLICATE KEY UPDATE
                assessment_id = VALUES(assessment_id),
                grading_component_id = VALUES(grading_component_id),
                updated_at = CURRENT_TIMESTAMP"
        );
        if (!$stmt) return false;
        $stmt->bind_param('iii', $sessionId, $assessmentId, $componentId);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool) $ok;
    }
}

if (!function_exists('attendance_checkin_resolve_assessment')) {
    function attendance_checkin_resolve_assessment(
        mysqli $conn,
        $classRecordId,
        $teacherId,
        $sessionId,
        $sessionDate,
        $sessionLabel
    ) {
        $classRecordId = (int) $classRecordId;
        $teacherId = (int) $teacherId;
        $sessionId = (int) $sessionId;
        $sessionDate = trim((string) $sessionDate);
        $sessionLabel = trim((string) $sessionLabel);
        if ($classRecordId <= 0 || $teacherId <= 0 || $sessionId <= 0) return [0, 0];

        $bound = attendance_checkin_session_assessment_binding($conn, $sessionId);
        if (is_array($bound)) {
            $aid = (int) ($bound['assessment_id'] ?? 0);
            $cid = (int) ($bound['grading_component_id'] ?? 0);
            if ($aid > 0 && $cid > 0) return [$aid, $cid];
        }

        $components = attendance_checkin_attendance_components($conn, $classRecordId);
        if (count($components) === 0) return [0, 0];

        $picked = null;
        $bestDiff = null;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $sessionDate)) {
            foreach ($components as $componentRow) {
                $componentId = (int) ($componentRow['component_id'] ?? 0);
                if ($componentId <= 0) continue;

                $exact = $conn->prepare(
                    "SELECT 1
                     FROM grading_assessments
                     WHERE grading_component_id = ?
                       AND is_active = 1
                       AND assessment_date = ?
                     LIMIT 1"
                );
                if ($exact) {
                    $exact->bind_param('is', $componentId, $sessionDate);
                    $exact->execute();
                    $res = $exact->get_result();
                    $hasExact = ($res && $res->num_rows === 1);
                    $exact->close();
                    if ($hasExact) {
                        $picked = $componentRow;
                        break;
                    }
                }

                $near = $conn->prepare(
                    "SELECT MIN(ABS(DATEDIFF(assessment_date, ?))) AS diff_days
                     FROM grading_assessments
                     WHERE grading_component_id = ?
                       AND is_active = 1
                       AND assessment_date IS NOT NULL"
                );
                if ($near) {
                    $near->bind_param('si', $sessionDate, $componentId);
                    $near->execute();
                    $res = $near->get_result();
                    $diff = null;
                    if ($res && $res->num_rows === 1) {
                        $row = $res->fetch_assoc();
                        if (isset($row['diff_days']) && $row['diff_days'] !== null && is_numeric($row['diff_days'])) {
                            $diff = (int) $row['diff_days'];
                        }
                    }
                    $near->close();

                    if ($diff !== null && ($bestDiff === null || $diff < $bestDiff)) {
                        $bestDiff = $diff;
                        $picked = $componentRow;
                    }
                }
            }
        }

        if (!is_array($picked)) $picked = $components[0];
        $componentId = (int) ($picked['component_id'] ?? 0);
        if ($componentId <= 0) return [0, 0];

        $assessmentName = attendance_checkin_assessment_name($sessionLabel, $sessionDate);
        $assessmentId = 0;

        $existing = $conn->prepare(
            "SELECT id
             FROM grading_assessments
             WHERE grading_component_id = ?
               AND is_active = 1
               AND name = ?
               AND (assessment_date <=> ?)
             ORDER BY id DESC
             LIMIT 1"
        );
        if ($existing) {
            $dateVal = $sessionDate !== '' ? $sessionDate : null;
            $existing->bind_param('iss', $componentId, $assessmentName, $dateVal);
            $existing->execute();
            $res = $existing->get_result();
            if ($res && $res->num_rows === 1) {
                $assessmentId = (int) ($res->fetch_assoc()['id'] ?? 0);
            }
            $existing->close();
        }

        if ($assessmentId <= 0) {
            $displayOrder = attendance_checkin_next_assessment_order($conn, $componentId);
            $maxScore = 1.0;
            $moduleType = 'assessment';
            $dateVal = $sessionDate !== '' ? $sessionDate : null;

            $ins = $conn->prepare(
                "INSERT INTO grading_assessments
                    (grading_component_id, name, max_score, assessment_date, module_type, is_active, display_order, created_by)
                 VALUES (?, ?, ?, ?, ?, 1, ?, ?)"
            );
            if ($ins) {
                $ins->bind_param(
                    'isdssii',
                    $componentId,
                    $assessmentName,
                    $maxScore,
                    $dateVal,
                    $moduleType,
                    $displayOrder,
                    $teacherId
                );
                $ok = $ins->execute();
                $assessmentId = $ok ? (int) $ins->insert_id : 0;
                $ins->close();
            }
        }

        if ($assessmentId <= 0) return [0, 0];
        attendance_checkin_bind_session_assessment($conn, $sessionId, $assessmentId, $componentId);
        return [$assessmentId, $componentId];
    }
}

if (!function_exists('attendance_checkin_status_score')) {
    function attendance_checkin_status_score($status) {
        $status = strtolower(trim((string) $status));
        if ($status === 'present') return 1.0;
        if ($status === 'late') return 0.5;
        return 0.0;
    }
}

if (!function_exists('attendance_checkin_sync_submission_gradebook')) {
    function attendance_checkin_sync_submission_gradebook(mysqli $conn, array $session, $studentId, $status) {
        $studentId = (int) $studentId;
        $status = strtolower(trim((string) $status));
        $out = ['enabled' => false, 'saved' => false, 'assessment_id' => 0];

        if ($studentId <= 0 || !in_array($status, ['present', 'late', 'absent'], true)) return $out;

        $classRecordId = (int) ($session['class_record_id'] ?? 0);
        $teacherId = (int) ($session['teacher_id'] ?? 0);
        $sessionId = (int) ($session['id'] ?? 0);
        $sessionDate = trim((string) ($session['session_date'] ?? ''));
        $sessionLabel = trim((string) ($session['session_label'] ?? ''));
        if ($classRecordId <= 0 || $teacherId <= 0 || $sessionId <= 0) return $out;

        [$assessmentId, $componentId] = attendance_checkin_resolve_assessment(
            $conn,
            $classRecordId,
            $teacherId,
            $sessionId,
            $sessionDate,
            $sessionLabel
        );
        if ($assessmentId <= 0 || $componentId <= 0) return $out;

        $out['enabled'] = true;
        $out['assessment_id'] = $assessmentId;

        $score = attendance_checkin_status_score($status);
        $out['saved'] = grading_upsert_assessment_score($conn, $assessmentId, $studentId, $score);
        return $out;
    }
}

if (!function_exists('attendance_checkin_mark_binding_synced')) {
    function attendance_checkin_mark_binding_synced(mysqli $conn, $sessionId) {
        $sessionId = (int) $sessionId;
        if ($sessionId <= 0) return false;

        $stmt = $conn->prepare(
            "UPDATE attendance_session_assessments
             SET synced_at = NOW(),
                 updated_at = CURRENT_TIMESTAMP
             WHERE session_id = ?
             LIMIT 1"
        );
        if (!$stmt) return false;
        $stmt->bind_param('i', $sessionId);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool) $ok;
    }
}

if (!function_exists('attendance_checkin_sync_session_gradebook')) {
    function attendance_checkin_sync_session_gradebook(mysqli $conn, array $session) {
        $out = [
            'enabled' => false,
            'assessment_id' => 0,
            'synced_students' => 0,
            'present' => 0,
            'late' => 0,
            'absent' => 0,
        ];

        $sessionId = (int) ($session['id'] ?? 0);
        $classRecordId = (int) ($session['class_record_id'] ?? 0);
        $teacherId = (int) ($session['teacher_id'] ?? 0);
        $sessionDate = trim((string) ($session['session_date'] ?? ''));
        $sessionLabel = trim((string) ($session['session_label'] ?? ''));
        if ($sessionId <= 0 || $classRecordId <= 0 || $teacherId <= 0) return $out;

        [$assessmentId, $componentId] = attendance_checkin_resolve_assessment(
            $conn,
            $classRecordId,
            $teacherId,
            $sessionId,
            $sessionDate,
            $sessionLabel
        );
        if ($assessmentId <= 0 || $componentId <= 0) return $out;

        $out['enabled'] = true;
        $out['assessment_id'] = $assessmentId;

        $phase = attendance_checkin_phase($session, time());
        $isClosedPhase = ($phase === 'closed');

        $stmt = $conn->prepare(
            "SELECT ce.student_id,
                    sb.status AS submitted_status
             FROM class_enrollments ce
             LEFT JOIN attendance_submissions sb
                    ON sb.session_id = ?
                   AND sb.student_id = ce.student_id
             WHERE ce.class_record_id = ?
               AND ce.status = 'enrolled'"
        );
        if (!$stmt) return $out;
        $stmt->bind_param('ii', $sessionId, $classRecordId);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($res && ($row = $res->fetch_assoc())) {
            $studentId = (int) ($row['student_id'] ?? 0);
            if ($studentId <= 0) continue;

            $submitted = strtolower(trim((string) ($row['submitted_status'] ?? '')));
            $status = '';
            if ($submitted === 'present' || $submitted === 'late') {
                $status = $submitted;
            } elseif ($isClosedPhase) {
                $status = 'absent';
            }
            if ($status === '') continue;

            $score = attendance_checkin_status_score($status);
            $saved = grading_upsert_assessment_score($conn, $assessmentId, $studentId, $score);
            if (!$saved) continue;

            $out['synced_students']++;
            if ($status === 'present') $out['present']++;
            elseif ($status === 'late') $out['late']++;
            else $out['absent']++;
        }

        $stmt->close();
        attendance_checkin_mark_binding_synced($conn, $sessionId);
        return $out;
    }
}

if (!function_exists('attendance_checkin_create_session')) {
    function attendance_checkin_create_session(
        mysqli $conn,
        $teacherId,
        $classRecordId,
        $sessionDate,
        $sessionLabel,
        $attendanceCode,
        $startTime,
        $endTime,
        $lateMinutes,
        $checkinMethod = 'code',
        $faceVerifyRequired = 0,
        $faceThreshold = 0.550
    ) {
        $teacherId = (int) $teacherId;
        $classRecordId = (int) $classRecordId;
        $sessionDate = trim((string) $sessionDate);
        $sessionLabel = trim((string) $sessionLabel);
        $attendanceCode = attendance_checkin_normalize_code($attendanceCode);
        $startTime = trim((string) $startTime);
        $endTime = trim((string) $endTime);
        $lateMinutes = (int) $lateMinutes;
        $checkinMethod = attendance_checkin_normalize_method($checkinMethod);
        $faceVerifyRequired = (int) $faceVerifyRequired;
        $faceThreshold = (float) $faceThreshold;

        if ($teacherId <= 0) return [false, 'Invalid teacher session.'];
        if ($classRecordId <= 0) return [false, 'Select a class first.'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sessionDate)) return [false, 'Session date is invalid.'];
        if ($sessionLabel === '') $sessionLabel = 'Attendance ' . $sessionDate;
        if (strlen($sessionLabel) > 120) $sessionLabel = substr($sessionLabel, 0, 120);

        if ($checkinMethod === 'code') {
            [$okCode, $codeError] = attendance_checkin_validate_code($attendanceCode);
            if (!$okCode) return [false, $codeError];
        } else {
            // QR + Face sessions auto-generate a secure code, so teachers don't need to type one.
            $attendanceCode = attendance_checkin_generate_code(10);
        }

        if (!preg_match('/^\d{2}:\d{2}$/', $startTime) || !preg_match('/^\d{2}:\d{2}$/', $endTime)) {
            return [false, 'Start and end time are required.'];
        }

        if ($lateMinutes < 0) $lateMinutes = 0;
        if ($lateMinutes > 360) $lateMinutes = 360;

        if ($checkinMethod !== 'face') {
            $faceVerifyRequired = 0;
        } else {
            $faceVerifyRequired = $faceVerifyRequired ? 1 : 0;
            if ($faceThreshold < 0.30) $faceThreshold = 0.30;
            if ($faceThreshold > 0.90) $faceThreshold = 0.90;
        }

        $startsAt = attendance_checkin_datetime_from_parts($sessionDate, $startTime);
        $presentUntil = attendance_checkin_datetime_from_parts($sessionDate, $endTime);
        if ($startsAt === '' || $presentUntil === '') {
            return [false, 'Invalid session date/time values.'];
        }

        $startTs = strtotime($startsAt);
        $presentTs = strtotime($presentUntil);
        if ($startTs === false || $presentTs === false || $presentTs <= $startTs) {
            return [false, 'End time must be later than start time.'];
        }

        $lateUntilTs = $presentTs + ($lateMinutes * 60);
        $lateUntil = date('Y-m-d H:i:s', $lateUntilTs);

        $stmt = $conn->prepare(
            "INSERT INTO attendance_sessions
                (class_record_id, teacher_id, session_label, session_date, attendance_code, checkin_method, face_verify_required, face_threshold, starts_at, present_until, late_until, late_minutes, is_closed)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)"
        );
        if (!$stmt) return [false, 'Unable to create attendance session.'];
        $stmt->bind_param(
            'iissssidsssi',
            $classRecordId,
            $teacherId,
            $sessionLabel,
            $sessionDate,
            $attendanceCode,
            $checkinMethod,
            $faceVerifyRequired,
            $faceThreshold,
            $startsAt,
            $presentUntil,
            $lateUntil,
            $lateMinutes
        );
        $ok = $stmt->execute();
        $newId = $ok ? (int) $stmt->insert_id : 0;
        $stmt->close();

        if (!$ok || $newId <= 0) return [false, 'Unable to create attendance session.'];
        return [true, $newId];
    }
}

if (!function_exists('attendance_checkin_close_session')) {
    function attendance_checkin_close_session(mysqli $conn, $teacherId, $sessionId) {
        $teacherId = (int) $teacherId;
        $sessionId = (int) $sessionId;
        if ($teacherId <= 0 || $sessionId <= 0) return [false, 'Invalid session request.'];

        $notDeleted = attendance_checkin_has_deleted_column($conn) ? " AND COALESCE(is_deleted, 0) = 0" : "";
        $stmt = $conn->prepare(
            "UPDATE attendance_sessions
             SET is_closed = 1,
                 late_until = CASE WHEN late_until > NOW() THEN NOW() ELSE late_until END
             WHERE id = ?
               AND teacher_id = ?
               " . $notDeleted . "
             LIMIT 1"
        );
        if (!$stmt) return [false, 'Unable to close session.'];
        $stmt->bind_param('ii', $sessionId, $teacherId);
        $ok = $stmt->execute();
        $affected = $ok ? (int) $stmt->affected_rows : 0;
        $stmt->close();

        if (!$ok) return [false, 'Unable to close session.'];
        if ($affected <= 0) return [false, 'Session not found or already closed.'];

        $syncMessage = '';
        $session = attendance_checkin_get_session_for_teacher($conn, $sessionId, $teacherId);
        if (is_array($session)) {
            $sync = attendance_checkin_sync_session_gradebook($conn, $session);
            if (!empty($sync['enabled'])) {
                $syncMessage = ' Gradebook synced (P:' . (int) ($sync['present'] ?? 0) .
                    ', L:' . (int) ($sync['late'] ?? 0) .
                    ', A:' . (int) ($sync['absent'] ?? 0) . ').';
            }
        }

        return [true, 'Session closed.' . $syncMessage];
    }
}

if (!function_exists('attendance_checkin_update_session')) {
    function attendance_checkin_update_session(
        mysqli $conn,
        $teacherId,
        $sessionId,
        $sessionDate,
        $sessionLabel,
        $startTime,
        $endTime,
        $lateMinutes
    ) {
        $teacherId = (int) $teacherId;
        $sessionId = (int) $sessionId;
        $sessionDate = trim((string) $sessionDate);
        $sessionLabel = trim((string) $sessionLabel);
        $startTime = trim((string) $startTime);
        $endTime = trim((string) $endTime);
        $lateMinutes = (int) $lateMinutes;

        if ($teacherId <= 0 || $sessionId <= 0) return [false, 'Invalid session request.'];
        if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $sessionDate)) return [false, 'Session date is invalid.'];
        if (!preg_match('/^\\d{2}:\\d{2}$/', $startTime) || !preg_match('/^\\d{2}:\\d{2}$/', $endTime)) {
            return [false, 'Start and end time are required.'];
        }

        if ($lateMinutes < 0) $lateMinutes = 0;
        if ($lateMinutes > 360) $lateMinutes = 360;

        $existing = attendance_checkin_get_session_for_teacher($conn, $sessionId, $teacherId);
        if (!is_array($existing)) return [false, 'Session not found.'];

        $oldLabel = trim((string) ($existing['session_label'] ?? ''));
        $oldDate = trim((string) ($existing['session_date'] ?? ''));

        if ($sessionLabel === '') $sessionLabel = 'Attendance ' . $sessionDate;
        if (strlen($sessionLabel) > 120) $sessionLabel = substr($sessionLabel, 0, 120);

        $startsAt = attendance_checkin_datetime_from_parts($sessionDate, $startTime);
        $presentUntil = attendance_checkin_datetime_from_parts($sessionDate, $endTime);
        if ($startsAt === '' || $presentUntil === '') return [false, 'Invalid session date/time values.'];

        $startTs = strtotime($startsAt);
        $presentTs = strtotime($presentUntil);
        if ($startTs === false || $presentTs === false || $presentTs <= $startTs) {
            return [false, 'End time must be later than start time.'];
        }

        $lateUntilTs = $presentTs + ($lateMinutes * 60);
        $lateUntil = date('Y-m-d H:i:s', $lateUntilTs);

        $notDeleted = attendance_checkin_has_deleted_column($conn) ? " AND COALESCE(is_deleted, 0) = 0" : "";
        $stmt = $conn->prepare(
            "UPDATE attendance_sessions
             SET session_label = ?,
                 session_date = ?,
                 starts_at = ?,
                 present_until = ?,
                 late_until = ?,
                 late_minutes = ?,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ?
               AND teacher_id = ?
               " . $notDeleted . "
             LIMIT 1"
        );
        if (!$stmt) return [false, 'Unable to update session.'];
        $stmt->bind_param(
            'sssssiii',
            $sessionLabel,
            $sessionDate,
            $startsAt,
            $presentUntil,
            $lateUntil,
            $lateMinutes,
            $sessionId,
            $teacherId
        );
        $ok = $stmt->execute();
        $affected = $ok ? (int) $stmt->affected_rows : 0;
        $stmt->close();
        if (!$ok) return [false, 'Unable to update session.'];
        if ($affected <= 0) return [false, 'Session not found or unchanged.'];

        // If this session is bound to a gradebook assessment and it looks auto-generated for attendance,
        // keep the assessment name/date aligned to the edited session label/date.
        $binding = attendance_checkin_session_assessment_binding($conn, $sessionId);
        if (is_array($binding)) {
            $assessmentId = (int) ($binding['assessment_id'] ?? 0);
            $componentId = (int) ($binding['grading_component_id'] ?? 0);
            if ($assessmentId > 0 && $componentId > 0) {
                $expectedOld = attendance_checkin_assessment_name($oldLabel, $oldDate);
                $expectedNew = attendance_checkin_assessment_name($sessionLabel, $sessionDate);

                $ga = $conn->prepare(
                    "SELECT id, grading_component_id, name, max_score, created_by, assessment_date
                     FROM grading_assessments
                     WHERE id = ?
                     LIMIT 1"
                );
                if ($ga) {
                    $ga->bind_param('i', $assessmentId);
                    $ga->execute();
                    $res = $ga->get_result();
                    $row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
                    $ga->close();

                    if (is_array($row)) {
                        $gaName = (string) ($row['name'] ?? '');
                        $gaMax = (float) ($row['max_score'] ?? 0);
                        $gaBy = (int) ($row['created_by'] ?? 0);
                        $gaComp = (int) ($row['grading_component_id'] ?? 0);
                        $gaDate = isset($row['assessment_date']) ? (string) ($row['assessment_date'] ?? '') : '';

                        $looksAuto =
                            ($gaComp === $componentId) &&
                            ($gaBy === $teacherId) &&
                            (abs($gaMax - 1.0) < 0.0001) &&
                            ($gaName === $expectedOld);

                        if ($looksAuto) {
                            $newDateVal = $sessionDate !== '' ? $sessionDate : null;
                            $upd = $conn->prepare(
                                "UPDATE grading_assessments
                                 SET name = ?,
                                     assessment_date = ?,
                                     updated_at = CURRENT_TIMESTAMP
                                 WHERE id = ?
                                 LIMIT 1"
                            );
                            if ($upd) {
                                // Only move the date if it previously matched the session date (avoid clobbering intentional edits).
                                $dateToSet = ($gaDate === $oldDate) ? $newDateVal : ($gaDate !== '' ? $gaDate : $newDateVal);
                                $upd->bind_param('ssi', $expectedNew, $dateToSet, $assessmentId);
                                $upd->execute();
                                $upd->close();
                            }
                        }
                    }
                }
            }
        }

        // Keep submissions consistent if the window changed (present vs late).
        $recalc = $conn->prepare(
            "UPDATE attendance_submissions
             SET status = CASE
                 WHEN submitted_at <= ? THEN 'present'
                 ELSE 'late'
             END,
             updated_at = CURRENT_TIMESTAMP
             WHERE session_id = ?"
        );
        if ($recalc) {
            $recalc->bind_param('si', $presentUntil, $sessionId);
            $recalc->execute();
            $recalc->close();
        }

        $session = attendance_checkin_get_session_for_teacher($conn, $sessionId, $teacherId);
        if (is_array($session)) {
            // Best-effort: resync now that window/labels may have changed.
            attendance_checkin_sync_session_gradebook($conn, $session);
        }

        return [true, 'Session updated.'];
    }
}

if (!function_exists('attendance_checkin_delete_session')) {
    function attendance_checkin_delete_session(mysqli $conn, $teacherId, $sessionId) {
        $teacherId = (int) $teacherId;
        $sessionId = (int) $sessionId;
        if ($teacherId <= 0 || $sessionId <= 0) return [false, 'Invalid session request.'];

        $session = attendance_checkin_get_session_for_teacher($conn, $sessionId, $teacherId);
        if (!is_array($session)) return [false, 'Session not found.'];

        $supportsDelete = attendance_checkin_has_deleted_column($conn);
        if (!$supportsDelete) return [false, 'Delete is not available (schema not updated). Refresh and try again.'];

        $stmt = $conn->prepare(
            "UPDATE attendance_sessions
             SET is_deleted = 1,
                 deleted_at = NOW(),
                 is_closed = 1,
                 late_until = CASE WHEN late_until > NOW() THEN NOW() ELSE late_until END,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ?
               AND teacher_id = ?
               AND COALESCE(is_deleted, 0) = 0
             LIMIT 1"
        );
        if (!$stmt) return [false, 'Unable to delete session.'];
        $stmt->bind_param('ii', $sessionId, $teacherId);
        $ok = $stmt->execute();
        $affected = $ok ? (int) $stmt->affected_rows : 0;
        $stmt->close();
        if (!$ok) return [false, 'Unable to delete session.'];
        if ($affected <= 0) return [false, 'Session not found or already deleted.'];

        // Detach gradebook binding and hide the assessment if it looks auto-created for this session.
        $binding = attendance_checkin_session_assessment_binding($conn, $sessionId);
        if (is_array($binding)) {
            $assessmentId = (int) ($binding['assessment_id'] ?? 0);
            $componentId = (int) ($binding['grading_component_id'] ?? 0);

            $delBind = $conn->prepare("DELETE FROM attendance_session_assessments WHERE session_id = ? LIMIT 1");
            if ($delBind) {
                $delBind->bind_param('i', $sessionId);
                $delBind->execute();
                $delBind->close();
            }

            if ($assessmentId > 0 && $componentId > 0) {
                $expected = attendance_checkin_assessment_name(
                    (string) ($session['session_label'] ?? ''),
                    (string) ($session['session_date'] ?? '')
                );

                $ga = $conn->prepare(
                    "SELECT id, grading_component_id, name, max_score, created_by
                     FROM grading_assessments
                     WHERE id = ?
                     LIMIT 1"
                );
                if ($ga) {
                    $ga->bind_param('i', $assessmentId);
                    $ga->execute();
                    $res = $ga->get_result();
                    $row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
                    $ga->close();

                    if (is_array($row)) {
                        $gaName = (string) ($row['name'] ?? '');
                        $gaMax = (float) ($row['max_score'] ?? 0);
                        $gaBy = (int) ($row['created_by'] ?? 0);
                        $gaComp = (int) ($row['grading_component_id'] ?? 0);

                        $looksAuto =
                            ($gaComp === $componentId) &&
                            ($gaBy === $teacherId) &&
                            (abs($gaMax - 1.0) < 0.0001) &&
                            ($gaName === $expected);

                        if ($looksAuto) {
                            $hide = $conn->prepare(
                                "UPDATE grading_assessments
                                 SET is_active = 0,
                                     updated_at = CURRENT_TIMESTAMP
                                 WHERE id = ?
                                 LIMIT 1"
                            );
                            if ($hide) {
                                $hide->bind_param('i', $assessmentId);
                                $hide->execute();
                                $hide->close();
                            }
                        }
                    }
                }
            }
        }

        return [true, 'Session deleted.'];
    }
}

if (!function_exists('attendance_checkin_get_teacher_sessions')) {
    function attendance_checkin_get_teacher_sessions(mysqli $conn, $teacherId, $classRecordId, $limit = 100) {
        $teacherId = (int) $teacherId;
        $classRecordId = (int) $classRecordId;
        $limit = (int) $limit;
        if ($limit <= 0) $limit = 100;
        if ($limit > 500) $limit = 500;
        if ($teacherId <= 0 || $classRecordId <= 0) return [];

        $whereDeleted = attendance_checkin_has_deleted_column($conn) ? " AND COALESCE(s.is_deleted, 0) = 0" : "";
        $sql =
            "SELECT s.id,
                    s.class_record_id,
                    s.teacher_id,
                    s.session_label,
                    s.session_date,
                    s.attendance_code,
                    s.checkin_method,
                    s.face_verify_required,
                    s.face_threshold,
                    s.starts_at,
                    s.present_until,
                    s.late_until,
                    s.late_minutes,
                    s.is_closed,
                    s.created_at,
                    COALESCE(ec.total_students, 0) AS total_students,
                    COALESCE(sc.present_count, 0) AS present_count,
                    COALESCE(sc.late_count, 0) AS late_count
             FROM attendance_sessions s
             LEFT JOIN (
                SELECT class_record_id, COUNT(*) AS total_students
                FROM class_enrollments
                WHERE status = 'enrolled'
                GROUP BY class_record_id
             ) ec ON ec.class_record_id = s.class_record_id
             LEFT JOIN (
                SELECT session_id,
                       SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) AS present_count,
                       SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) AS late_count
                FROM attendance_submissions
                GROUP BY session_id
             ) sc ON sc.session_id = s.id
             WHERE s.teacher_id = ?
               AND s.class_record_id = ?
               " . $whereDeleted . "
             ORDER BY s.starts_at DESC, s.id DESC
             LIMIT ?";

        $stmt = $conn->prepare($sql);
        if (!$stmt) return [];
        $stmt->bind_param('iii', $teacherId, $classRecordId, $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($res && ($row = $res->fetch_assoc())) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('attendance_checkin_get_student_sessions')) {
    function attendance_checkin_get_student_sessions(mysqli $conn, $studentId, $classRecordId, $limit = 100) {
        $studentId = (int) $studentId;
        $classRecordId = (int) $classRecordId;
        $limit = (int) $limit;
        if ($limit <= 0) $limit = 100;
        if ($limit > 500) $limit = 500;
        if ($studentId <= 0 || $classRecordId <= 0) return [];

        $whereDeleted = attendance_checkin_has_deleted_column($conn) ? " AND COALESCE(s.is_deleted, 0) = 0" : "";
        $sql =
            "SELECT s.id,
                    s.class_record_id,
                    s.teacher_id,
                    s.session_label,
                    s.session_date,
                    s.checkin_method,
                    s.face_verify_required,
                    s.face_threshold,
                    s.starts_at,
                    s.present_until,
                    s.late_until,
                    s.late_minutes,
                    s.is_closed,
                    s.created_at,
                    sb.status AS submitted_status,
                    sb.submitted_at
             FROM attendance_sessions s
             LEFT JOIN attendance_submissions sb
                    ON sb.session_id = s.id
                   AND sb.student_id = ?
             WHERE s.class_record_id = ?
               " . $whereDeleted . "
             ORDER BY s.starts_at DESC, s.id DESC
             LIMIT ?";

        $stmt = $conn->prepare($sql);
        if (!$stmt) return [];
        $stmt->bind_param('iii', $studentId, $classRecordId, $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($res && ($row = $res->fetch_assoc())) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('attendance_checkin_get_session_for_student')) {
    function attendance_checkin_get_session_for_student(mysqli $conn, $sessionId, $studentId) {
        $sessionId = (int) $sessionId;
        $studentId = (int) $studentId;
        if ($sessionId <= 0 || $studentId <= 0) return null;

        $whereDeleted = attendance_checkin_has_deleted_column($conn) ? " AND COALESCE(s.is_deleted, 0) = 0" : "";
        $stmt = $conn->prepare(
            "SELECT s.id,
                    s.class_record_id,
                    s.teacher_id,
                    s.session_label,
                    s.session_date,
                    s.attendance_code,
                    s.checkin_method,
                    s.face_verify_required,
                    s.face_threshold,
                    s.starts_at,
                    s.present_until,
                    s.late_until,
                    s.late_minutes,
                    s.is_closed,
                    sb.status AS submitted_status,
                    sb.submitted_at
             FROM attendance_sessions s
             LEFT JOIN attendance_submissions sb
                    ON sb.session_id = s.id
                   AND sb.student_id = ?
             WHERE s.id = ?
               " . $whereDeleted . "
             LIMIT 1"
        );
        if (!$stmt) return null;
        $stmt->bind_param('ii', $studentId, $sessionId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
        $stmt->close();
        return $row;
    }
}

if (!function_exists('attendance_checkin_get_session_for_teacher')) {
    function attendance_checkin_get_session_for_teacher(mysqli $conn, $sessionId, $teacherId) {
        $sessionId = (int) $sessionId;
        $teacherId = (int) $teacherId;
        if ($sessionId <= 0 || $teacherId <= 0) return null;

        $whereDeleted = attendance_checkin_has_deleted_column($conn) ? " AND COALESCE(is_deleted, 0) = 0" : "";
        $stmt = $conn->prepare(
            "SELECT id,
                    class_record_id,
                    teacher_id,
                    session_label,
                    session_date,
                    attendance_code,
                    checkin_method,
                    face_verify_required,
                    face_threshold,
                    starts_at,
                    present_until,
                    late_until,
                    late_minutes,
                    is_closed
             FROM attendance_sessions
             WHERE id = ?
               AND teacher_id = ?
               " . $whereDeleted . "
             LIMIT 1"
        );
        if (!$stmt) return null;
        $stmt->bind_param('ii', $sessionId, $teacherId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
        $stmt->close();
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('attendance_checkin_log_geofence_denied')) {
    function attendance_checkin_log_geofence_denied(mysqli $conn, $sessionId, $classRecordId, $studentId, array $geoEval) {
        if (!function_exists('audit_log')) {
            $auditPath = __DIR__ . '/audit.php';
            if (is_file($auditPath)) require_once $auditPath;
        }
        if (!function_exists('audit_log')) return;

        $distance = isset($geoEval['distance_m']) && $geoEval['distance_m'] !== null ? (float) $geoEval['distance_m'] : null;
        $radius = isset($geoEval['radius_meters']) && $geoEval['radius_meters'] !== null ? (int) $geoEval['radius_meters'] : null;
        $meta = [
            'session_id' => (int) $sessionId,
            'class_record_id' => (int) $classRecordId,
            'student_id' => (int) $studentId,
            'campus_id' => (int) ($geoEval['campus_id'] ?? 0),
            'boundary_scope' => (string) ($geoEval['boundary_scope'] ?? 'campus'),
            'latitude' => $geoEval['latitude'] ?? null,
            'longitude' => $geoEval['longitude'] ?? null,
            'accuracy_m' => $geoEval['accuracy_m'] ?? null,
            'distance_m' => $distance,
            'radius_meters' => $radius,
        ];
        audit_log($conn, 'attendance.geofence.denied', 'attendance_session', (int) $sessionId, (string) ($geoEval['message'] ?? 'Attendance geofence rejected.'), $meta);
    }
}

if (!function_exists('attendance_checkin_submit_code')) {
    function attendance_checkin_submit_code(mysqli $conn, $studentId, $userId, $sessionId, $inputCode, $submissionMethod = 'code', array $geoLocation = []) {
        $studentId = (int) $studentId;
        $userId = (int) $userId;
        $sessionId = (int) $sessionId;
        $inputCode = attendance_checkin_normalize_code($inputCode);
        $submissionMethod = attendance_checkin_normalize_method($submissionMethod);
        if (!in_array($submissionMethod, ['code', 'qr'], true)) $submissionMethod = 'code';

        if ($studentId <= 0 || $userId <= 0) return [false, 'Invalid student account context.'];
        if ($sessionId <= 0) return [false, 'Select an attendance session.'];

        $session = attendance_checkin_get_session_for_student($conn, $sessionId, $studentId);
        if (!is_array($session)) return [false, 'Attendance session not found.'];

        $method = attendance_checkin_normalize_method((string) ($session['checkin_method'] ?? 'code'));
        if ($method === 'face') {
            return [false, 'This attendance session requires facial check-in.'];
        }
        if ($submissionMethod === 'qr' && $method !== 'qr') {
            return [false, 'This attendance session does not accept QR check-in.'];
        }
        if ($submissionMethod === 'code' && $method !== 'code') {
            return [false, 'This attendance session does not accept code entry.'];
        }

        $classRecordId = (int) ($session['class_record_id'] ?? 0);
        if ($classRecordId <= 0 || !attendance_is_student_enrolled($conn, $studentId, $classRecordId)) {
            return [false, 'You are not enrolled in this class session.'];
        }

        $submittedStatus = trim((string) ($session['submitted_status'] ?? ''));
        if ($submittedStatus !== '') {
            $submittedAt = trim((string) ($session['submitted_at'] ?? ''));
            return [false, 'Attendance already submitted as ' . strtolower($submittedStatus) . ($submittedAt !== '' ? (' at ' . $submittedAt) : '') . '.'];
        }

        if ((int) ($session['is_closed'] ?? 0) === 1) {
            return [false, 'Attendance session is already closed.'];
        }

        [$okCode, $codeError] = attendance_checkin_validate_code($inputCode);
        if (!$okCode) return [false, $codeError];

        $savedCode = (string) ($session['attendance_code'] ?? '');
        if (!function_exists('hash_equals')) {
            if ($savedCode !== $inputCode) return [false, 'Incorrect attendance code.'];
        } else {
            if (!hash_equals($savedCode, $inputCode)) return [false, 'Incorrect attendance code.'];
        }

        $nowTs = time();
        $startTs = strtotime((string) ($session['starts_at'] ?? ''));
        $presentTs = strtotime((string) ($session['present_until'] ?? ''));
        $lateTs = strtotime((string) ($session['late_until'] ?? ''));
        if ($startTs === false || $presentTs === false || $lateTs === false) {
            return [false, 'Attendance session window is invalid.'];
        }

        if ($nowTs < $startTs) return [false, 'Attendance has not started yet.'];
        if ($nowTs > $lateTs) {
            attendance_checkin_sync_session_gradebook($conn, $session);
            return [false, 'Attendance session already expired. You are marked absent.'];
        }

        $geoEval = attendance_geo_evaluate_submission($conn, $session, is_array($geoLocation) ? $geoLocation : []);
        if (!$geoEval['allowed']) {
            attendance_checkin_log_geofence_denied($conn, $sessionId, $classRecordId, $studentId, $geoEval);
            return [false, (string) ($geoEval['message'] ?? 'Location check failed.')];
        }

        $status = ($nowTs <= $presentTs) ? 'present' : 'late';
        $submittedAt = date('Y-m-d H:i:s', $nowTs);

        $geoLat = ($geoEval['latitude'] !== null) ? number_format((float) $geoEval['latitude'], 8, '.', '') : null;
        $geoLng = ($geoEval['longitude'] !== null) ? number_format((float) $geoEval['longitude'], 8, '.', '') : null;
        $geoAccuracy = ($geoEval['accuracy_m'] !== null) ? number_format((float) $geoEval['accuracy_m'], 2, '.', '') : null;
        $geoCapturedAt = isset($geoEval['captured_at']) ? (string) $geoEval['captured_at'] : null;
        $geoDistance = ($geoEval['distance_m'] !== null) ? number_format((float) $geoEval['distance_m'], 2, '.', '') : null;
        $geoWithin = isset($geoEval['within_boundary']) && $geoEval['within_boundary'] !== null ? (string) ((int) $geoEval['within_boundary']) : null;
        $geoRadius = isset($geoEval['radius_meters']) && $geoEval['radius_meters'] !== null ? (string) ((int) $geoEval['radius_meters']) : null;

        $stmt = $conn->prepare(
            "INSERT INTO attendance_submissions
                (session_id, class_record_id, student_id, submitted_by, submitted_code, submission_method, status, submitted_at,
                 location_latitude, location_longitude, location_accuracy_m, location_captured_at, location_distance_m, location_within_boundary, location_boundary_radius_m)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) return [false, 'Unable to save attendance submission.'];
        $stmt->bind_param(
            'iiiisssssssssss',
            $sessionId,
            $classRecordId,
            $studentId,
            $userId,
            $inputCode,
            $submissionMethod,
            $status,
            $submittedAt,
            $geoLat,
            $geoLng,
            $geoAccuracy,
            $geoCapturedAt,
            $geoDistance,
            $geoWithin,
            $geoRadius
        );
        $ok = $stmt->execute();
        $errno = (int) $stmt->errno;
        $stmt->close();

        if (!$ok) {
            if ($errno === 1062) return [false, 'Attendance already submitted for this session.'];
            return [false, 'Unable to save attendance submission.'];
        }

        $sync = attendance_checkin_sync_submission_gradebook($conn, $session, $studentId, $status);
        return [true, [
            'status' => $status,
            'submitted_at' => $submittedAt,
            'submission_method' => $submissionMethod,
            'location_enforced' => !empty($geoEval['enforced']),
            'location_distance_m' => $geoEval['distance_m'],
            'gradebook_sync_enabled' => !empty($sync['enabled']),
            'gradebook_synced' => !empty($sync['saved']),
        ]];
    }
}

if (!function_exists('attendance_checkin_face_upload_dir')) {
    function attendance_checkin_face_upload_dir() {
        return __DIR__ . '/../uploads/attendance_faces';
    }
}

if (!function_exists('attendance_checkin_face_upload_dir_rel')) {
    function attendance_checkin_face_upload_dir_rel() {
        return 'uploads/attendance_faces';
    }
}

if (!function_exists('attendance_checkin_face_save_upload')) {
    function attendance_checkin_face_save_upload($file, $sessionId, $studentId) {
        if (!is_array($file) || empty($file['tmp_name'])) return [false, 'Face image is required.', null];
        if (!isset($file['error']) || (int) $file['error'] !== UPLOAD_ERR_OK) return [false, 'Face image upload failed.', null];

        $size = isset($file['size']) ? (int) $file['size'] : 0;
        if ($size <= 0) return [false, 'Face image is empty.', null];
        if ($size > 5 * 1024 * 1024) return [false, 'Face image is too large (max 5MB).', null];

        $tmp = (string) $file['tmp_name'];
        if (!is_uploaded_file($tmp)) return [false, 'Invalid upload.', null];

        $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
        $mime = $finfo ? (string) finfo_file($finfo, $tmp) : '';
        if ($finfo) finfo_close($finfo);

        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
        if (!isset($allowed[$mime])) {
            return [false, 'Unsupported image type. Use JPG, PNG, or WEBP.', null];
        }
        $ext = $allowed[$mime];

        $dir = attendance_checkin_face_upload_dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (!is_dir($dir)) return [false, 'Unable to create upload directory.', null];

        $sessionId = (int) $sessionId;
        $studentId = (int) $studentId;
        $rand = bin2hex(random_bytes(8));
        $ts = date('Ymd_His');
        $base = 'face_s' . $sessionId . '_st' . $studentId . '_' . $ts . '_' . $rand . '.' . $ext;

        $rel = attendance_checkin_face_upload_dir_rel() . '/' . $base;
        $dst = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $base;

        $ok = @move_uploaded_file($tmp, $dst);
        if (!$ok || !is_file($dst)) return [false, 'Unable to save uploaded image.', null];

        return [true, '', [
            'path' => $rel,
            'mime' => $mime,
            'size' => $size,
        ]];
    }
}

if (!function_exists('attendance_checkin_submit_face')) {
    function attendance_checkin_submit_face(mysqli $conn, $studentId, $userId, $sessionId, $file, $descriptorJson = '', array $geoLocation = []) {
        $studentId = (int) $studentId;
        $userId = (int) $userId;
        $sessionId = (int) $sessionId;
        $descriptorJson = (string) $descriptorJson;

        if ($studentId <= 0 || $userId <= 0) return [false, 'Invalid student account context.'];
        if ($sessionId <= 0) return [false, 'Select an attendance session.'];

        $session = attendance_checkin_get_session_for_student($conn, $sessionId, $studentId);
        if (!is_array($session)) return [false, 'Attendance session not found.'];

        $method = attendance_checkin_normalize_method((string) ($session['checkin_method'] ?? 'code'));
        if ($method !== 'face') return [false, 'This attendance session does not accept facial check-in.'];
        $verifyRequired = !empty($session['face_verify_required']);
        $threshold = isset($session['face_threshold']) ? (float) $session['face_threshold'] : 0.550;
        if ($threshold <= 0) $threshold = 0.550;
        if ($threshold < 0.30) $threshold = 0.30;
        if ($threshold > 0.90) $threshold = 0.90;

        $classRecordId = (int) ($session['class_record_id'] ?? 0);
        if ($classRecordId <= 0 || !attendance_is_student_enrolled($conn, $studentId, $classRecordId)) {
            return [false, 'You are not enrolled in this class session.'];
        }

        $submittedStatus = trim((string) ($session['submitted_status'] ?? ''));
        if ($submittedStatus !== '') {
            $submittedAt = trim((string) ($session['submitted_at'] ?? ''));
            return [false, 'Attendance already submitted as ' . strtolower($submittedStatus) . ($submittedAt !== '' ? (' at ' . $submittedAt) : '') . '.'];
        }

        if ((int) ($session['is_closed'] ?? 0) === 1) {
            return [false, 'Attendance session is already closed.'];
        }

        $matchPassed = null;
        $matchDistance = null;
        if ($verifyRequired) {
            $probe = face_profiles_parse_descriptor($descriptorJson);
            if (!is_array($probe)) {
                return [false, 'Face verification is required for this session. Please allow the camera and try again.'];
            }

            $profile = face_profiles_get($conn, $studentId);
            if (!is_array($profile)) {
                return [false, 'No face is registered for your account yet. Please register your face first.'];
            }
            $ref = face_profiles_parse_descriptor((string) ($profile['descriptor_json'] ?? ''));
            if (!is_array($ref)) {
                return [false, 'Your registered face data is invalid. Please re-register your face.'];
            }

            $matchDistance = face_profiles_distance($probe, $ref);
            $matchPassed = ($matchDistance <= $threshold) ? 1 : 0;
            if (!$matchPassed) {
                return [false, 'Face verification failed. Please try again in better lighting and face the camera directly.'];
            }
        }

        [$okUp, $upErr, $meta] = attendance_checkin_face_save_upload($file, $sessionId, $studentId);
        if (!$okUp) return [false, $upErr];
        $meta = is_array($meta) ? $meta : [];

        $nowTs = time();
        $startTs = strtotime((string) ($session['starts_at'] ?? ''));
        $presentTs = strtotime((string) ($session['present_until'] ?? ''));
        $lateTs = strtotime((string) ($session['late_until'] ?? ''));
        if ($startTs === false || $presentTs === false || $lateTs === false) {
            return [false, 'Attendance session window is invalid.'];
        }

        if ($nowTs < $startTs) return [false, 'Attendance has not started yet.'];
        if ($nowTs > $lateTs) {
            attendance_checkin_sync_session_gradebook($conn, $session);
            return [false, 'Attendance session already expired. You are marked absent.'];
        }

        $geoEval = attendance_geo_evaluate_submission($conn, $session, is_array($geoLocation) ? $geoLocation : []);
        if (!$geoEval['allowed']) {
            attendance_checkin_log_geofence_denied($conn, $sessionId, $classRecordId, $studentId, $geoEval);
            return [false, (string) ($geoEval['message'] ?? 'Location check failed.')];
        }

        $status = ($nowTs <= $presentTs) ? 'present' : 'late';
        $submittedAt = date('Y-m-d H:i:s', $nowTs);
        $submittedCode = 'FACE-' . attendance_checkin_generate_code(10);

        $geoLat = ($geoEval['latitude'] !== null) ? number_format((float) $geoEval['latitude'], 8, '.', '') : null;
        $geoLng = ($geoEval['longitude'] !== null) ? number_format((float) $geoEval['longitude'], 8, '.', '') : null;
        $geoAccuracy = ($geoEval['accuracy_m'] !== null) ? number_format((float) $geoEval['accuracy_m'], 2, '.', '') : null;
        $geoCapturedAt = isset($geoEval['captured_at']) ? (string) $geoEval['captured_at'] : null;
        $geoDistance = ($geoEval['distance_m'] !== null) ? number_format((float) $geoEval['distance_m'], 2, '.', '') : null;
        $geoWithin = isset($geoEval['within_boundary']) && $geoEval['within_boundary'] !== null ? (string) ((int) $geoEval['within_boundary']) : null;
        $geoRadius = isset($geoEval['radius_meters']) && $geoEval['radius_meters'] !== null ? (string) ((int) $geoEval['radius_meters']) : null;

        $stmt = $conn->prepare(
            "INSERT INTO attendance_submissions
                (session_id, class_record_id, student_id, submitted_by, submitted_code, submission_method, status, submitted_at,
                 face_image_path, face_image_mime, face_image_size, face_captured_at, face_match_passed, face_match_distance,
                 location_latitude, location_longitude, location_accuracy_m, location_captured_at, location_distance_m, location_within_boundary, location_boundary_radius_m)
             VALUES (?, ?, ?, ?, ?, 'face', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) return [false, 'Unable to save attendance submission.'];

        $facePath = (string) ($meta['path'] ?? '');
        $faceMime = (string) ($meta['mime'] ?? '');
        $faceSize = isset($meta['size']) ? (int) $meta['size'] : 0;
        $capturedAt = $submittedAt;

        $matchPassedVal = ($verifyRequired && $matchPassed !== null) ? (string) ((int) $matchPassed) : null;
        $matchDistanceVal = ($verifyRequired && $matchDistance !== null) ? number_format((float) $matchDistance, 4, '.', '') : null;

        $stmt->bind_param(
            'iiiisssssisssssssssss',
            $sessionId,
            $classRecordId,
            $studentId,
            $userId,
            $submittedCode,
            $status,
            $submittedAt,
            $facePath,
            $faceMime,
            $faceSize,
            $capturedAt,
            $matchPassedVal,
            $matchDistanceVal,
            $geoLat,
            $geoLng,
            $geoAccuracy,
            $geoCapturedAt,
            $geoDistance,
            $geoWithin,
            $geoRadius
        );

        $ok = $stmt->execute();
        $errno = (int) $stmt->errno;
        $stmt->close();

        if (!$ok) {
            if ($errno === 1062) return [false, 'Attendance already submitted for this session.'];
            return [false, 'Unable to save attendance submission.'];
        }

        $sync = attendance_checkin_sync_submission_gradebook($conn, $session, $studentId, $status);
        return [true, [
            'status' => $status,
            'submitted_at' => $submittedAt,
            'submission_method' => 'face',
            'face_image_path' => $facePath,
            'location_enforced' => !empty($geoEval['enforced']),
            'location_distance_m' => $geoEval['distance_m'],
            'gradebook_sync_enabled' => !empty($sync['enabled']),
            'gradebook_synced' => !empty($sync['saved']),
        ]];
    }
}

if (!function_exists('attendance_checkin_get_session_roster')) {
    function attendance_checkin_get_session_roster(mysqli $conn, $teacherId, $sessionId) {
        $teacherId = (int) $teacherId;
        $sessionId = (int) $sessionId;
        if ($teacherId <= 0 || $sessionId <= 0) return [null, []];

        $whereDeleted = attendance_checkin_has_deleted_column($conn) ? " AND COALESCE(s.is_deleted, 0) = 0" : "";
        $sessionStmt = $conn->prepare(
            "SELECT s.id,
                    s.class_record_id,
                    s.teacher_id,
                    s.session_label,
                    s.session_date,
                    s.attendance_code,
                    s.checkin_method,
                    s.starts_at,
                    s.present_until,
                    s.late_until,
                    s.late_minutes,
                    s.is_closed,
                    s.created_at,
                    cr.section,
                    cr.academic_year,
                    cr.semester,
                    subj.subject_code,
                    subj.subject_name
             FROM attendance_sessions s
             JOIN class_records cr ON cr.id = s.class_record_id
             JOIN subjects subj ON subj.id = cr.subject_id
             WHERE s.id = ?
               AND s.teacher_id = ?
               " . $whereDeleted . "
             LIMIT 1"
        );
        if (!$sessionStmt) return [null, []];
        $sessionStmt->bind_param('ii', $sessionId, $teacherId);
        $sessionStmt->execute();
        $sessionRes = $sessionStmt->get_result();
        $session = ($sessionRes && $sessionRes->num_rows === 1) ? $sessionRes->fetch_assoc() : null;
        $sessionStmt->close();
        if (!is_array($session)) return [null, []];

        $classRecordId = (int) ($session['class_record_id'] ?? 0);
        if ($classRecordId <= 0) return [$session, []];

        $rosterStmt = $conn->prepare(
            "SELECT st.id AS student_id,
                    st.StudentNo AS student_no,
                    st.Surname AS surname,
                    st.FirstName AS firstname,
                    st.MiddleName AS middlename,
                    sb.status AS submitted_status,
                    sb.submitted_at,
                    sb.submission_method,
                    sb.face_image_path,
                    sb.location_latitude,
                    sb.location_longitude,
                    sb.location_accuracy_m,
                    sb.location_distance_m,
                    sb.location_within_boundary
             FROM class_enrollments ce
             JOIN students st ON st.id = ce.student_id
             LEFT JOIN attendance_submissions sb
                     ON sb.session_id = ?
                    AND sb.student_id = st.id
             WHERE ce.class_record_id = ?
               AND ce.status = 'enrolled'
             ORDER BY st.Surname ASC, st.FirstName ASC, st.MiddleName ASC, st.StudentNo ASC"
        );
        if (!$rosterStmt) return [$session, []];
        $rosterStmt->bind_param('ii', $sessionId, $classRecordId);
        $rosterStmt->execute();
        $res = $rosterStmt->get_result();
        $rows = [];
        while ($res && ($row = $res->fetch_assoc())) {
            $rows[] = $row;
        }
        $rosterStmt->close();

        return [$session, $rows];
    }
}
