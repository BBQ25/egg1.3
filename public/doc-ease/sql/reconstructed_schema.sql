-- Reconstructed schema for current E-Record code paths
-- Date: 2026-02-07
-- Source: inferred from runtime SQL usage in PHP files
-- Target DB from runtime config: doc_ease

CREATE DATABASE IF NOT EXISTS `doc_ease`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `doc_ease`;

-- ---------------------------------------------------------------------
-- users
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `useremail` VARCHAR(255) NOT NULL,
  `username` VARCHAR(100) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `role` ENUM('admin', 'user') NOT NULL DEFAULT 'user',
  `is_active` TINYINT(1) NOT NULL DEFAULT 0,
  `token` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_useremail` (`useremail`),
  KEY `idx_users_role_active` (`role`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- students
-- Note: Current code queries both by student number and by students.id
-- (`todays_act.php` maps session user_id to students.id).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `students` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `studentno` VARCHAR(30) NOT NULL,
  `surname` VARCHAR(100) NOT NULL,
  `firstname` VARCHAR(100) NOT NULL,
  `middlename` VARCHAR(100) NULL,
  `course` VARCHAR(100) NULL,
  `major` VARCHAR(100) NULL,
  `section` VARCHAR(100) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_students_studentno` (`studentno`),
  KEY `idx_students_course_major` (`course`, `major`),
  KEY `idx_students_section` (`section`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- subjects
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `subjects` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `subject_code` VARCHAR(50) NOT NULL,
  `subject_name` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `course` VARCHAR(100) NULL,
  `major` VARCHAR(100) NULL,
  `academic_year` VARCHAR(20) NULL,
  `semester` VARCHAR(30) NULL,
  `units` DECIMAL(4,1) NOT NULL DEFAULT 3.0,
  `type` ENUM('Lecture', 'Laboratory', 'Lec&Lab') NOT NULL DEFAULT 'Lecture',
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_subjects_subject_code` (`subject_code`),
  KEY `idx_subjects_status` (`status`),
  KEY `idx_subjects_course_major` (`course`, `major`),
  KEY `idx_subjects_created_by` (`created_by`),
  CONSTRAINT `fk_subjects_created_by_users`
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- sections
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sections` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `description` TEXT NULL,
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sections_name` (`name`),
  KEY `idx_sections_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- section_subjects (many-to-many)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `section_subjects` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `section_id` BIGINT UNSIGNED NOT NULL,
  `subject_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_section_subject_pair` (`section_id`, `subject_id`),
  KEY `idx_section_subjects_subject` (`subject_id`),
  CONSTRAINT `fk_section_subjects_section`
    FOREIGN KEY (`section_id`) REFERENCES `sections`(`id`)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_section_subjects_subject`
    FOREIGN KEY (`subject_id`) REFERENCES `subjects`(`id`)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- uploaded_files
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `uploaded_files` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `StudentNo` VARCHAR(30) NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `file_name` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(1024) NOT NULL,
  `file_size` BIGINT UNSIGNED NOT NULL,
  `file_type` VARCHAR(100) NOT NULL,
  `notes` TEXT NULL,
  `location_latitude` DECIMAL(10,7) NULL,
  `location_longitude` DECIMAL(10,7) NULL,
  `checklist` VARCHAR(255) NULL,
  `upload_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_uploaded_files_studentno` (`StudentNo`),
  KEY `idx_uploaded_files_upload_date` (`upload_date`),
  KEY `idx_uploaded_files_created_at` (`created_at`),
  CONSTRAINT `fk_uploaded_files_studentno_students`
    FOREIGN KEY (`StudentNo`) REFERENCES `students`(`studentno`)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Compatibility ALTERs for existing environments that started from
-- legacy attex-php.sql.
-- ---------------------------------------------------------------------
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `role` ENUM('admin', 'user') NOT NULL DEFAULT 'user',
  ADD COLUMN IF NOT EXISTS `is_active` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE `subjects`
  ADD COLUMN IF NOT EXISTS `type` ENUM('Lecture', 'Laboratory', 'Lec&Lab') NOT NULL DEFAULT 'Lecture',
  ADD COLUMN IF NOT EXISTS `created_by` BIGINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  ADD COLUMN IF NOT EXISTS `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ADD COLUMN IF NOT EXISTS `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE `uploaded_files`
  ADD COLUMN IF NOT EXISTS `checklist` VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ADD COLUMN IF NOT EXISTS `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
