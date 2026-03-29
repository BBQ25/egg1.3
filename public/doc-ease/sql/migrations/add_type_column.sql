USE attex-php;

ALTER TABLE subjects ADD COLUMN type ENUM('Lecture', 'Laboratory') NOT NULL DEFAULT 'Lecture' AFTER subject_name;
