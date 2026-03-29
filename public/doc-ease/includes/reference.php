<?php
// Reference data helpers (Academic Years, Semesters).

if (!function_exists('ensure_reference_tables')) {
    function ensure_reference_tables(mysqli $conn) {
        // Academic Years
        $conn->query(
            "CREATE TABLE IF NOT EXISTS academic_years (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(32) NOT NULL,
                status ENUM('active','inactive') NOT NULL DEFAULT 'active',
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_academic_years_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // Semesters
        $conn->query(
            "CREATE TABLE IF NOT EXISTS semesters (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(32) NOT NULL,
                status ENUM('active','inactive') NOT NULL DEFAULT 'active',
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_semesters_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // Section references (split profile sections vs class sections).
        ref_ensure_section_reference_tables($conn);

        // Report template footer settings.
        ref_ensure_report_template_settings($conn);
    }
}

if (!function_exists('ref_list_active_names')) {
    function ref_list_active_names(mysqli $conn, $table) {
        $table = (string) $table;
        if (!in_array($table, ['academic_years', 'semesters'], true)) return [];

        $names = [];
        $sql = "SELECT name FROM {$table} WHERE status = 'active' ORDER BY sort_order ASC, name ASC";
        $res = $conn->query($sql);
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $n = isset($r['name']) ? trim((string) $r['name']) : '';
                if ($n !== '') $names[] = $n;
            }
        }
        return $names;
    }
}

if (!function_exists('ref_table_exists')) {
    function ref_table_exists(mysqli $conn, $tableName) {
        $tableName = trim((string) $tableName);
        if ($tableName === '') return false;

        $stmt = $conn->prepare(
            "SELECT 1
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
             LIMIT 1"
        );
        if (!$stmt) return false;

        $stmt->bind_param('s', $tableName);
        $stmt->execute();
        $res = $stmt->get_result();
        $exists = $res && $res->num_rows === 1;
        $stmt->close();
        return $exists;
    }
}

if (!function_exists('ref_ensure_section_reference_tables')) {
    function ref_ensure_section_reference_tables(mysqli $conn) {
        static $done = false;
        if ($done) return;
        $done = true;

        $conn->query(
            "CREATE TABLE IF NOT EXISTS profile_sections (
                id INT AUTO_INCREMENT PRIMARY KEY,
                course VARCHAR(100) NOT NULL,
                year_level VARCHAR(20) NOT NULL,
                section_code VARCHAR(20) NOT NULL,
                label VARCHAR(180) NOT NULL,
                status ENUM('active','inactive') NOT NULL DEFAULT 'active',
                source ENUM('students','legacy_sections','manual') NOT NULL DEFAULT 'students',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_profile_section_triplet (course, year_level, section_code),
                UNIQUE KEY uq_profile_section_label (label),
                KEY idx_profile_sections_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $conn->query(
            "CREATE TABLE IF NOT EXISTS class_sections (
                id INT AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(50) NOT NULL,
                description TEXT NULL,
                status ENUM('active','inactive') NOT NULL DEFAULT 'active',
                source ENUM('class_records','legacy_sections','manual') NOT NULL DEFAULT 'class_records',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_class_section_code (code),
                KEY idx_class_sections_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $conn->query(
            "CREATE TABLE IF NOT EXISTS class_section_subjects (
                id INT AUTO_INCREMENT PRIMARY KEY,
                class_section_id INT NOT NULL,
                subject_id INT NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_class_section_subject (class_section_id, subject_id),
                KEY idx_css_class_section (class_section_id),
                KEY idx_css_subject (subject_id),
                CONSTRAINT fk_css_class_section FOREIGN KEY (class_section_id) REFERENCES class_sections(id) ON DELETE CASCADE,
                CONSTRAINT fk_css_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $conn->query(
            "CREATE TABLE IF NOT EXISTS profile_section_subjects (
                id INT AUTO_INCREMENT PRIMARY KEY,
                profile_section_id INT NOT NULL,
                subject_id INT NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_profile_section_subject (profile_section_id, subject_id),
                KEY idx_pss_profile_section (profile_section_id),
                KEY idx_pss_subject (subject_id),
                CONSTRAINT fk_pss_profile_section FOREIGN KEY (profile_section_id) REFERENCES profile_sections(id) ON DELETE CASCADE,
                CONSTRAINT fk_pss_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
}

if (!function_exists('ref_list_profile_sections')) {
    function ref_list_profile_sections(mysqli $conn, $activeOnly = true) {
        ref_ensure_section_reference_tables($conn);

        $rows = [];
        $where = $activeOnly ? "WHERE status = 'active'" : '';
        $res = $conn->query(
            "SELECT id, course, year_level, section_code, label, status
             FROM profile_sections
             {$where}
             ORDER BY course ASC, year_level ASC, section_code ASC"
        );
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $course = trim((string) ($r['course'] ?? ''));
                $year = trim((string) ($r['year_level'] ?? ''));
                $section = trim((string) ($r['section_code'] ?? ''));
                if ($course === '' || $year === '' || $section === '') continue;

                $label = trim((string) ($r['label'] ?? ''));
                if ($label === '') $label = $course . ' - ' . $year . ' - ' . $section;

                $rows[] = [
                    'id' => (int) ($r['id'] ?? 0),
                    'course' => $course,
                    'year' => $year,
                    'section' => $section,
                    'label' => $label,
                    'status' => trim((string) ($r['status'] ?? 'active')),
                    'key' => strtolower($course . '|' . $year . '|' . $section),
                ];
            }
        }

        return $rows;
    }
}

if (!function_exists('ref_list_class_sections')) {
    function ref_list_class_sections(mysqli $conn, $activeOnly = true, $ifOnly = false) {
        ref_ensure_section_reference_tables($conn);

        $rows = [];
        $where = $activeOnly ? "WHERE status = 'active'" : '';
        $res = $conn->query(
            "SELECT id, code, description, status
             FROM class_sections
             {$where}
             ORDER BY code ASC"
        );
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $code = strtoupper(trim((string) ($r['code'] ?? '')));
                if ($code === '') continue;
                if ($ifOnly && !ref_is_if_section($code)) continue;

                $rows[] = [
                    'id' => (int) ($r['id'] ?? 0),
                    'code' => $code,
                    'label' => $code,
                    'description' => trim((string) ($r['description'] ?? '')),
                    'status' => trim((string) ($r['status'] ?? 'active')),
                ];
            }
        }

        if (count($rows) > 0) return $rows;

        // Legacy fallback when class_sections has not been migrated yet.
        if (ref_table_exists($conn, 'sections')) {
            $legacy = $conn->query(
                "SELECT DISTINCT name
                 FROM sections
                 WHERE status = 'active'
                   AND name IS NOT NULL
                   AND name <> ''
                 ORDER BY name ASC"
            );
            if ($legacy) {
                while ($r = $legacy->fetch_assoc()) {
                    $code = strtoupper(trim((string) ($r['name'] ?? '')));
                    if ($code === '') continue;
                    if ($ifOnly && !ref_is_if_section($code)) continue;
                    $rows[] = [
                        'id' => 0,
                        'code' => $code,
                        'label' => $code,
                        'description' => '',
                        'status' => 'active',
                    ];
                }
            }
        }

        if (count($rows) === 0 && ref_table_exists($conn, 'class_records')) {
            $fallback = $conn->query(
                "SELECT DISTINCT section
                 FROM class_records
                 WHERE status = 'active'
                   AND subject_id IS NOT NULL
                   AND section IS NOT NULL
                   AND section <> ''
                 ORDER BY section ASC"
            );
            if ($fallback) {
                while ($r = $fallback->fetch_assoc()) {
                    $code = strtoupper(trim((string) ($r['section'] ?? '')));
                    if ($code === '') continue;
                    if ($ifOnly && !ref_is_if_section($code)) continue;
                    $rows[] = [
                        'id' => 0,
                        'code' => $code,
                        'label' => $code,
                        'description' => '',
                        'status' => 'active',
                    ];
                }
            }
        }

        return $rows;
    }
}

if (!function_exists('ref_sync_profile_sections_from_students')) {
    function ref_sync_profile_sections_from_students(mysqli $conn) {
        ref_ensure_section_reference_tables($conn);

        if (!ref_table_exists($conn, 'students')) return ['inserted' => 0, 'updated' => 0];

        $res = $conn->query(
            "SELECT DISTINCT
                TRIM(COALESCE(Course, '')) AS course,
                TRIM(COALESCE(Year, '')) AS year_level,
                TRIM(COALESCE(Section, '')) AS section_code
             FROM students
             WHERE Course IS NOT NULL AND Course <> ''
               AND Year IS NOT NULL AND Year <> ''
               AND Section IS NOT NULL AND Section <> ''"
        );
        if (!$res) return ['inserted' => 0, 'updated' => 0];

        $upsert = $conn->prepare(
            "INSERT INTO profile_sections (course, year_level, section_code, label, status, source)
             VALUES (?, ?, ?, ?, 'active', 'students')
             ON DUPLICATE KEY UPDATE
                label = VALUES(label),
                status = 'active',
                updated_at = CURRENT_TIMESTAMP"
        );
        if (!$upsert) return ['inserted' => 0, 'updated' => 0];

        $inserted = 0;
        $updated = 0;

        while ($row = $res->fetch_assoc()) {
            $course = ref_normalize_course_name((string) ($row['course'] ?? ''));
            $year = ref_normalize_year_level((string) ($row['year_level'] ?? ''));
            $section = ref_normalize_section_code((string) ($row['section_code'] ?? ''));
            if ($course === '' || $year === '' || $section === '') continue;

            $label = $course . ' - ' . $year . ' - ' . $section;
            $upsert->bind_param('ssss', $course, $year, $section, $label);
            if ($upsert->execute()) {
                $affected = (int) $upsert->affected_rows;
                if ($affected === 1) $inserted++;
                if ($affected === 2) $updated++;
            }
        }

        $upsert->close();
        return ['inserted' => $inserted, 'updated' => $updated];
    }
}

if (!function_exists('ref_sync_class_sections_from_records')) {
    function ref_sync_class_sections_from_records(mysqli $conn) {
        ref_ensure_section_reference_tables($conn);

        $codes = [];
        if (ref_table_exists($conn, 'class_records')) {
            $res = $conn->query(
                "SELECT DISTINCT section
                 FROM class_records
                 WHERE section IS NOT NULL
                   AND section <> ''"
            );
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $sec = strtoupper(trim((string) ($row['section'] ?? '')));
                    if ($sec === '') continue;
                    if (!ref_is_if_section($sec)) continue;
                    $codes[$sec] = true;
                }
            }
        }

        if (ref_table_exists($conn, 'sections')) {
            $legacy = $conn->query("SELECT DISTINCT name FROM sections WHERE name IS NOT NULL AND name <> ''");
            if ($legacy) {
                while ($row = $legacy->fetch_assoc()) {
                    $sec = strtoupper(trim((string) ($row['name'] ?? '')));
                    if ($sec === '') continue;
                    if (!ref_is_if_section($sec)) continue;
                    $codes[$sec] = true;
                }
            }
        }

        if (count($codes) === 0) return ['inserted' => 0, 'updated' => 0];

        $upsert = $conn->prepare(
            "INSERT INTO class_sections (code, description, status, source)
             VALUES (?, '', 'active', 'class_records')
             ON DUPLICATE KEY UPDATE
                status = 'active',
                updated_at = CURRENT_TIMESTAMP"
        );
        if (!$upsert) return ['inserted' => 0, 'updated' => 0];

        $inserted = 0;
        $updated = 0;
        foreach (array_keys($codes) as $code) {
            $upsert->bind_param('s', $code);
            if ($upsert->execute()) {
                $affected = (int) $upsert->affected_rows;
                if ($affected === 1) $inserted++;
                if ($affected === 2) $updated++;
            }
        }
        $upsert->close();

        return ['inserted' => $inserted, 'updated' => $updated];
    }
}

if (!function_exists('ref_list_student_section_profiles')) {
    function ref_list_student_section_profiles(mysqli $conn) {
        $profiles = [];

        // Prefer canonical profile_sections table when available.
        if (function_exists('ref_list_profile_sections')) {
            $canonical = ref_list_profile_sections($conn, true);
            if (count($canonical) > 0) {
                $countMap = [];
                $countRes = $conn->query(
                    "SELECT
                        TRIM(COALESCE(Course, '')) AS course,
                        TRIM(COALESCE(Year, '')) AS year_level,
                        TRIM(COALESCE(Section, '')) AS section_code,
                        COUNT(*) AS student_count
                     FROM students
                     WHERE Course IS NOT NULL AND Course <> ''
                       AND Year IS NOT NULL AND Year <> ''
                       AND Section IS NOT NULL AND Section <> ''
                     GROUP BY
                        TRIM(COALESCE(Course, '')),
                        TRIM(COALESCE(Year, '')),
                        TRIM(COALESCE(Section, ''))"
                );
                if ($countRes) {
                    while ($cr = $countRes->fetch_assoc()) {
                        $cCourse = ref_normalize_course_name((string) ($cr['course'] ?? ''));
                        $cYear = ref_normalize_year_level((string) ($cr['year_level'] ?? ''));
                        $cSection = ref_normalize_section_code((string) ($cr['section_code'] ?? ''));
                        if ($cCourse === '' || $cYear === '' || $cSection === '') continue;
                        $key = strtolower($cCourse . '|' . $cYear . '|' . $cSection);
                        $countMap[$key] = (int) ($cr['student_count'] ?? 0);
                    }
                }

                foreach ($canonical as $row) {
                    $course = trim((string) ($row['course'] ?? ''));
                    $year = trim((string) ($row['year'] ?? ''));
                    $section = trim((string) ($row['section'] ?? ''));
                    if ($course === '' || $year === '' || $section === '') continue;

                    $key = strtolower($course . '|' . $year . '|' . $section);
                    $profiles[] = [
                        'course' => $course,
                        'year' => $year,
                        'section' => $section,
                        'label' => trim((string) ($row['label'] ?? ($course . ' - ' . $year . ' - ' . $section))),
                        'key' => $key,
                        'student_count' => (int) ($countMap[$key] ?? 0),
                    ];
                }
                return $profiles;
            }
        }

        $res = $conn->query(
            "SELECT
                TRIM(COALESCE(Course, '')) AS course,
                TRIM(COALESCE(Year, '')) AS year_level,
                TRIM(COALESCE(Section, '')) AS section_name,
                COUNT(*) AS student_count
             FROM students
             WHERE Course IS NOT NULL AND Course <> ''
               AND Year IS NOT NULL AND Year <> ''
               AND Section IS NOT NULL AND Section <> ''
             GROUP BY
                TRIM(COALESCE(Course, '')),
                TRIM(COALESCE(Year, '')),
                TRIM(COALESCE(Section, ''))
             ORDER BY
                TRIM(COALESCE(Course, '')) ASC,
                TRIM(COALESCE(Year, '')) ASC,
                TRIM(COALESCE(Section, '')) ASC"
        );
        if (!$res) return $profiles;

        while ($row = $res->fetch_assoc()) {
            $course = trim((string) ($row['course'] ?? ''));
            $year = trim((string) ($row['year_level'] ?? ''));
            $section = trim((string) ($row['section_name'] ?? ''));
            if ($course === '' || $year === '' || $section === '') continue;
            $label = $course . ' - ' . $year . ' - ' . $section;
            $profiles[] = [
                'course' => $course,
                'year' => $year,
                'section' => $section,
                'label' => $label,
                'key' => strtolower($course . '|' . $year . '|' . $section),
                'student_count' => (int) ($row['student_count'] ?? 0),
            ];
        }

        return $profiles;
    }
}

if (!function_exists('ref_ensure_report_template_settings')) {
    function ref_ensure_report_template_settings(mysqli $conn) {
        $conn->query(
            "CREATE TABLE IF NOT EXISTS report_template_settings (
                setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
                setting_value VARCHAR(255) NOT NULL DEFAULT '',
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $conn->query(
            "INSERT IGNORE INTO report_template_settings (setting_key, setting_value) VALUES
                ('doc_code', 'SLSU-QF-IN41'),
                ('revision', '01'),
                ('issue_date', '14 October 2019')"
        );
    }
}

if (!function_exists('ref_get_report_template_settings')) {
    function ref_get_report_template_settings(mysqli $conn) {
        ref_ensure_report_template_settings($conn);

        $defaults = [
            'doc_code' => 'SLSU-QF-IN41',
            'revision' => '01',
            'issue_date' => '14 October 2019',
        ];

        $res = $conn->query(
            "SELECT setting_key, setting_value
             FROM report_template_settings
             WHERE setting_key IN ('doc_code','revision','issue_date')"
        );
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $k = (string) ($r['setting_key'] ?? '');
                if (!array_key_exists($k, $defaults)) continue;
                $defaults[$k] = trim((string) ($r['setting_value'] ?? ''));
            }
        }

        return $defaults;
    }
}

if (!function_exists('ref_save_report_template_settings')) {
    function ref_save_report_template_settings(mysqli $conn, array $settings) {
        ref_ensure_report_template_settings($conn);

        $stmt = $conn->prepare(
            "INSERT INTO report_template_settings (setting_key, setting_value)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        if (!$stmt) return false;

        $allowed = ['doc_code', 'revision', 'issue_date'];
        foreach ($allowed as $k) {
            $v = trim((string) ($settings[$k] ?? ''));
            $stmt->bind_param('ss', $k, $v);
            if (!$stmt->execute()) {
                $stmt->close();
                return false;
            }
        }

        $stmt->close();
        return true;
    }
}

if (!function_exists('ref_normalize_course_name')) {
    function ref_normalize_course_name($course) {
        $course = trim((string) $course);
        if ($course === '') return '';

        $flat = strtoupper(preg_replace('/[^A-Z0-9]+/', '', $course));
        if (!is_string($flat)) $flat = strtoupper($course);

        $map = [
            'BSIT' => 'BSInfoTech',
            'BSINFOTECH' => 'BSInfoTech',
            'BSINFORMATIONTECHNOLOGY' => 'BSInfoTech',
            'BSINFORMATIONTECH' => 'BSInfoTech',
            'BSINFO' => 'BSInfoTech',
            'BSMB' => 'BSMB',
            'BSA' => 'BSA',
            'BSFI' => 'BSFi',
            'BAT' => 'BAT',
        ];
        if (isset($map[$flat])) return $map[$flat];

        return preg_replace('/\s+/', ' ', $course);
    }
}

if (!function_exists('ref_normalize_year_level')) {
    function ref_normalize_year_level($yearLevel) {
        $yearLevel = trim((string) $yearLevel);
        if ($yearLevel === '') return '';

        $lower = strtolower($yearLevel);
        if (preg_match('/\b(1|1st|first)\b/', $lower)) return '1st Year';
        if (preg_match('/\b(2|2nd|second)\b/', $lower)) return '2nd Year';
        if (preg_match('/\b(3|3rd|third)\b/', $lower)) return '3rd Year';
        if (preg_match('/\b(4|4th|fourth)\b/', $lower)) return '4th Year';

        return preg_replace('/\s+/', ' ', $yearLevel);
    }
}

if (!function_exists('ref_is_if_section')) {
    function ref_is_if_section($section) {
        $section = strtoupper(trim((string) $section));
        if ($section === '') return false;
        return preg_match('/^IF-\d+-[A-Z]-\d+$/', $section) === 1;
    }
}

if (!function_exists('ref_normalize_section_code')) {
    function ref_normalize_section_code($section) {
        $section = trim((string) $section);
        if ($section === '') return '';

        $sectionUpper = strtoupper($section);
        if (ref_is_if_section($sectionUpper)) return $sectionUpper;

        $sectionUpper = preg_replace('/\s+/', ' ', $sectionUpper);
        if (!is_string($sectionUpper)) $sectionUpper = strtoupper($section);
        $sectionUpper = trim((string) $sectionUpper);

        if (preg_match('/^(SECTION|SEC)\s*[-:]?\s*([A-Z0-9]+)$/', $sectionUpper, $m)) {
            $sectionUpper = trim((string) ($m[2] ?? ''));
        }

        if (preg_match('/^[1-4]\s*([A-Z])$/', $sectionUpper, $m)) {
            return trim((string) ($m[1] ?? ''));
        }
        if (preg_match('/^([A-Z])[1-4]$/', $sectionUpper, $m)) {
            return trim((string) ($m[1] ?? ''));
        }
        if (preg_match('/^[A-Z]$/', $sectionUpper)) return $sectionUpper;
        if (preg_match('/^[A-Z0-9]{1,20}$/', $sectionUpper)) return $sectionUpper;

        return trim((string) $section);
    }
}

if (!function_exists('ref_parse_profile_section_label')) {
    function ref_parse_profile_section_label($value) {
        $value = trim((string) $value);
        if ($value === '') return null;

        // Supports labels like "BSInfoTech - 2nd Year - B"
        if (preg_match('/^(.+?)\s*-\s*(.+?)\s*-\s*([A-Za-z0-9]+)$/', $value, $m)) {
            return [
                'course' => ref_normalize_course_name((string) ($m[1] ?? '')),
                'year' => ref_normalize_year_level((string) ($m[2] ?? '')),
                'section' => ref_normalize_section_code((string) ($m[3] ?? '')),
            ];
        }
        return null;
    }
}

if (!function_exists('ref_section_lookup_hint')) {
    function ref_section_lookup_hint($value) {
        $value = trim((string) $value);
        if ($value === '') return '';
        if (ref_is_if_section($value)) return strtoupper($value);

        if (strpos($value, '||') !== false) {
            $parts = explode('||', $value, 3);
            if (count($parts) === 3) {
                return ref_normalize_section_code((string) ($parts[2] ?? ''));
            }
        }

        $profile = ref_parse_profile_section_label($value);
        if (is_array($profile)) {
            return (string) ($profile['section'] ?? '');
        }

        return ref_normalize_section_code($value);
    }
}

if (!function_exists('ref_section_tokens')) {
    function ref_section_tokens($value) {
        $value = strtolower(trim((string) $value));
        if ($value === '') return [];
        $parts = preg_split('/[^a-z0-9]+/i', $value);
        if (!is_array($parts)) return [];

        $tokens = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part !== '') $tokens[] = $part;
        }
        return $tokens;
    }
}

if (!function_exists('ref_section_alias_match')) {
    function ref_section_alias_match($candidateSection, $inputSection) {
        $candidateHint = strtolower(ref_section_lookup_hint($candidateSection));
        $inputHint = strtolower(ref_section_lookup_hint($inputSection));
        if ($candidateHint === '' || $inputHint === '') return false;
        if ($candidateHint === $inputHint) return true;

        $candidateTokens = ref_section_tokens($candidateHint);
        $inputTokens = ref_section_tokens($inputHint);
        if (count($candidateTokens) === 0 || count($inputTokens) === 0) return false;

        if (count($inputTokens) === 1) {
            return in_array($inputTokens[0], $candidateTokens, true);
        }
        foreach ($inputTokens as $token) {
            if (!in_array($token, $candidateTokens, true)) return false;
        }
        return true;
    }
}

if (!function_exists('ref_resolve_class_record_target')) {
    function ref_resolve_class_record_target(mysqli $conn, $subjectId, $academicYear, $semester, $sectionInput) {
        $subjectId = (int) $subjectId;
        $academicYear = trim((string) $academicYear);
        $semester = trim((string) $semester);
        $sectionInput = trim((string) $sectionInput);

        $result = [
            'class_record_id' => 0,
            'section' => $sectionInput,
            'has_teacher' => false,
            'match_bucket' => 0,
        ];

        if ($subjectId <= 0 || $academicYear === '' || $semester === '' || $sectionInput === '') {
            return $result;
        }

        $stmt = $conn->prepare(
            "SELECT cr.id,
                    cr.section,
                    EXISTS(
                        SELECT 1
                        FROM teacher_assignments ta
                        WHERE ta.class_record_id = cr.id
                          AND ta.status = 'active'
                    ) AS has_teacher
             FROM class_records cr
             WHERE cr.subject_id = ?
               AND cr.academic_year = ?
               AND cr.semester = ?
               AND cr.status = 'active'
             ORDER BY cr.id DESC"
        );
        if (!$stmt) return $result;

        $stmt->bind_param('iss', $subjectId, $academicYear, $semester);
        $stmt->execute();
        $res = $stmt->get_result();

        $best = null;
        while ($res && ($row = $res->fetch_assoc())) {
            $candidateId = (int) ($row['id'] ?? 0);
            $candidateSection = trim((string) ($row['section'] ?? ''));
            $hasTeacher = (int) ($row['has_teacher'] ?? 0) === 1;
            if ($candidateId <= 0 || $candidateSection === '') continue;

            $isExact = strcasecmp($candidateSection, $sectionInput) === 0;
            $isAlias = ref_section_alias_match($candidateSection, $sectionInput);
            if (!$isExact && !$isAlias) continue;

            $bucket = 4;
            if ($isExact && $hasTeacher) {
                $bucket = 1;
            } elseif ($isAlias && $hasTeacher) {
                $bucket = 2;
            } elseif ($isExact) {
                $bucket = 3;
            }

            if (!is_array($best) || $bucket < (int) ($best['bucket'] ?? 99)) {
                $best = [
                    'id' => $candidateId,
                    'section' => $candidateSection,
                    'has_teacher' => $hasTeacher,
                    'bucket' => $bucket,
                ];
            }
        }
        $stmt->close();

        if (is_array($best)) {
            $result['class_record_id'] = (int) ($best['id'] ?? 0);
            $result['section'] = (string) ($best['section'] ?? $sectionInput);
            $result['has_teacher'] = (bool) ($best['has_teacher'] ?? false);
            $result['match_bucket'] = (int) ($best['bucket'] ?? 0);
        }

        return $result;
    }
}
