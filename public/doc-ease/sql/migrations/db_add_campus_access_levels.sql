-- Campus-based access level bootstrap
-- Adds campus scope and superadmin flag while preserving existing role='admin' behavior.

CREATE TABLE IF NOT EXISTS campuses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  campus_code VARCHAR(40) NOT NULL,
  campus_name VARCHAR(160) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_campuses_code (campus_code),
  UNIQUE KEY uq_campuses_name (campus_name),
  KEY idx_campuses_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS campus_id BIGINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS is_superadmin TINYINT(1) NOT NULL DEFAULT 0;

-- Optional index for campus scoped reads.
SET @users_campus_idx_exists := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND INDEX_NAME = 'idx_users_campus_id'
);
SET @users_campus_idx_sql := IF(
  @users_campus_idx_exists > 0,
  'SELECT 1',
  'CREATE INDEX idx_users_campus_id ON users (campus_id)'
);
PREPARE stmt_users_campus_idx FROM @users_campus_idx_sql;
EXECUTE stmt_users_campus_idx;
DEALLOCATE PREPARE stmt_users_campus_idx;

-- Add campus_id to students/teachers only when those tables exist.
SET @students_exists := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students'
);
SET @students_sql := IF(
  @students_exists > 0,
  'ALTER TABLE students ADD COLUMN IF NOT EXISTS campus_id BIGINT UNSIGNED NULL',
  'SELECT 1'
);
PREPARE stmt_students FROM @students_sql;
EXECUTE stmt_students;
DEALLOCATE PREPARE stmt_students;

SET @teachers_exists := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'teachers'
);
SET @teachers_sql := IF(
  @teachers_exists > 0,
  'ALTER TABLE teachers ADD COLUMN IF NOT EXISTS campus_id BIGINT UNSIGNED NULL',
  'SELECT 1'
);
PREPARE stmt_teachers FROM @teachers_sql;
EXECUTE stmt_teachers;
DEALLOCATE PREPARE stmt_teachers;

-- Seed default campus when empty.
INSERT INTO campuses (campus_code, campus_name, is_active)
SELECT 'MAIN', 'Main Campus', 1
WHERE NOT EXISTS (SELECT 1 FROM campuses LIMIT 1);

SET @default_campus_id := (
  SELECT id
  FROM campuses
  ORDER BY id ASC
  LIMIT 1
);

-- Ensure at least one superadmin from existing admin rows.
SET @superadmin_count := (
  SELECT COUNT(*)
  FROM users
  WHERE role = 'admin' AND is_superadmin = 1
);
SET @first_admin_id := (
  SELECT id
  FROM users
  WHERE role = 'admin'
  ORDER BY id ASC
  LIMIT 1
);
UPDATE users
SET is_superadmin = 1
WHERE @superadmin_count = 0
  AND @first_admin_id IS NOT NULL
  AND id = @first_admin_id;

-- Backfill campus_id for all non-superadmin accounts.
UPDATE users
SET campus_id = @default_campus_id
WHERE campus_id IS NULL
  AND @default_campus_id IS NOT NULL
  AND (is_superadmin = 0 OR role <> 'admin');

-- Sync profile-campus from linked user account first.
SET @sync_students_sql := IF(
  @students_exists > 0,
  'UPDATE students s JOIN users u ON u.id = s.user_id SET s.campus_id = u.campus_id WHERE s.campus_id IS NULL AND u.campus_id IS NOT NULL',
  'SELECT 1'
);
PREPARE stmt_sync_students FROM @sync_students_sql;
EXECUTE stmt_sync_students;
DEALLOCATE PREPARE stmt_sync_students;

SET @sync_teachers_sql := IF(
  @teachers_exists > 0,
  'UPDATE teachers t JOIN users u ON u.id = t.user_id SET t.campus_id = u.campus_id WHERE t.campus_id IS NULL AND u.campus_id IS NOT NULL',
  'SELECT 1'
);
PREPARE stmt_sync_teachers FROM @sync_teachers_sql;
EXECUTE stmt_sync_teachers;
DEALLOCATE PREPARE stmt_sync_teachers;

-- Backfill remaining NULL profile campus rows to default campus.
SET @backfill_students_sql := IF(
  @students_exists > 0,
  CONCAT('UPDATE students SET campus_id = ', IFNULL(@default_campus_id, 0), ' WHERE campus_id IS NULL'),
  'SELECT 1'
);
PREPARE stmt_backfill_students FROM @backfill_students_sql;
EXECUTE stmt_backfill_students;
DEALLOCATE PREPARE stmt_backfill_students;

SET @backfill_teachers_sql := IF(
  @teachers_exists > 0,
  CONCAT('UPDATE teachers SET campus_id = ', IFNULL(@default_campus_id, 0), ' WHERE campus_id IS NULL'),
  'SELECT 1'
);
PREPARE stmt_backfill_teachers FROM @backfill_teachers_sql;
EXECUTE stmt_backfill_teachers;
DEALLOCATE PREPARE stmt_backfill_teachers;
