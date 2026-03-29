-- Attendance attachment storage for student attendance proof uploads.
-- Safe to run multiple times.

CREATE TABLE IF NOT EXISTS attendance_attachments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    class_record_id INT NOT NULL,
    student_id INT NOT NULL,
    uploaded_by INT NOT NULL,
    session_date DATE NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(1024) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    notes TEXT NULL,
    ai_description TEXT NULL,
    ai_status ENUM('pending','generated','failed','skipped') NOT NULL DEFAULT 'pending',
    ai_error VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_att_class_date (class_record_id, session_date),
    KEY idx_att_student_class (student_id, class_record_id, session_date),
    KEY idx_att_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE attendance_attachments
    ADD COLUMN IF NOT EXISTS ai_description TEXT NULL AFTER notes,
    ADD COLUMN IF NOT EXISTS ai_status ENUM('pending','generated','failed','skipped') NOT NULL DEFAULT 'pending' AFTER ai_description,
    ADD COLUMN IF NOT EXISTS ai_error VARCHAR(255) NULL AFTER ai_status;
