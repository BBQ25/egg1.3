-- Attendance self check-in sessions (teacher-managed code windows)

CREATE TABLE IF NOT EXISTS attendance_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    class_record_id INT NOT NULL,
    teacher_id INT NOT NULL,
    session_label VARCHAR(120) NOT NULL,
    session_date DATE NOT NULL,
    attendance_code VARCHAR(64) NOT NULL,
    starts_at DATETIME NOT NULL,
    present_until DATETIME NOT NULL,
    late_until DATETIME NOT NULL,
    late_minutes INT UNSIGNED NOT NULL DEFAULT 15,
    is_closed TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_attsess_class_date (class_record_id, session_date, starts_at),
    KEY idx_attsess_teacher_date (teacher_id, session_date),
    KEY idx_attsess_window (starts_at, late_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS attendance_submissions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    session_id BIGINT UNSIGNED NOT NULL,
    class_record_id INT NOT NULL,
    student_id INT NOT NULL,
    submitted_by INT NOT NULL,
    submitted_code VARCHAR(64) NOT NULL,
    status ENUM('present','late') NOT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_attsub_session_student (session_id, student_id),
    KEY idx_attsub_class_student (class_record_id, student_id),
    KEY idx_attsub_submitted (submitted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Maps each attendance check-in session to exactly one Class Record assessment row.
CREATE TABLE IF NOT EXISTS attendance_session_assessments (
    session_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    assessment_id INT NOT NULL,
    grading_component_id INT NOT NULL,
    synced_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_asa_assessment (assessment_id),
    KEY idx_asa_component (grading_component_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
