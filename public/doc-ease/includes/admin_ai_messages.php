<?php
// Admin Messages AI assistant helpers.
// Scope:
// - Clarify-first chat for admin operations.
// - Optional execution for whitelisted actions after admin confirmation.

require_once __DIR__ . '/env_secrets.php';

if (!function_exists('admin_ai_msg_read_api_key')) {
    function admin_ai_msg_read_api_key($envName, $path, $startsWith = '') {
        $env = function_exists('doc_ease_env_value')
            ? trim((string) doc_ease_env_value((string) $envName))
            : trim((string) getenv((string) $envName));
        if ($env === '') return '';
        if ($startsWith !== '' && strpos($env, $startsWith) !== 0) return '';
        return $env;
    }
}

if (!function_exists('admin_ai_msg_openai_api_key')) {
    function admin_ai_msg_openai_api_key() {
        return admin_ai_msg_read_api_key('OPENAI_API_KEY', '', 'sk-');
    }
}

if (!function_exists('admin_ai_msg_clean_text')) {
    function admin_ai_msg_clean_text($value, $maxLen = 1000) {
        $value = trim((string) $value);
        $value = preg_replace('/\s+/', ' ', $value);
        if (!is_string($value)) $value = '';
        if ($maxLen > 0 && strlen($value) > $maxLen) {
            $value = trim((string) substr($value, 0, $maxLen));
        }
        return $value;
    }
}

if (!function_exists('admin_ai_msg_has_ryhn_intro_prefix')) {
    function admin_ai_msg_has_ryhn_intro_prefix($message) {
        $message = trim((string) $message);
        if ($message === '') return false;
        return (bool) preg_match('/^hi,\s*i\s*(?:\'m|am)\s*ryhn\b[:.!-]?\s*/i', $message);
    }
}

if (!function_exists('admin_ai_msg_strip_ryhn_intro_prefix')) {
    function admin_ai_msg_strip_ryhn_intro_prefix($message) {
        $message = trim((string) $message);
        if ($message === '') return '';
        $stripped = preg_replace('/^hi,\s*i\s*(?:\'m|am)\s*ryhn\b[:.!-]?\s*/i', '', $message, 1);
        if (!is_string($stripped)) $stripped = '';
        return trim($stripped);
    }
}

if (!function_exists('admin_ai_msg_compact_repeated_ryhn_intro')) {
    function admin_ai_msg_compact_repeated_ryhn_intro($message) {
        $message = trim((string) $message);
        if ($message === '') return '';

        $message = preg_replace(
            '/^(hi,\s*i\s*(?:\'m|am)\s*ryhn\b[:.!-]?\s*)(?:hello,\s*)?(?:hi,\s*)?(?:i\s*(?:\'m|am)\s*ryhn\b[,:]?\s*)/i',
            '$1',
            $message
        );
        if (is_string($message)) {
            $message = preg_replace('/^(hi,\s*i\s*(?:\'m|am)\s*ryhn\b[:.!-]?\s*)the\b/i', '$1I am the', $message);
        }
        if (!is_string($message)) $message = '';
        return trim($message);
    }
}

if (!function_exists('admin_ai_msg_history_has_assistant_reply')) {
    function admin_ai_msg_history_has_assistant_reply($history) {
        if (!is_array($history)) return false;
        foreach ($history as $row) {
            if (!is_array($row)) continue;
            $role = strtolower(trim((string) ($row['role'] ?? '')));
            if (!in_array($role, ['assistant', 'ai'], true)) continue;
            $content = trim((string) ($row['content'] ?? ''));
            if ($content !== '') return true;
        }
        return false;
    }
}

if (!function_exists('admin_ai_msg_with_ryhn_intro')) {
    function admin_ai_msg_with_ryhn_intro($message, $history = null, $introOnce = false) {
        $message = admin_ai_msg_compact_repeated_ryhn_intro((string) $message);
        if ($message === '') $message = 'How can I help?';

        $hasIntro = admin_ai_msg_has_ryhn_intro_prefix($message);
        $prefixed = $hasIntro ? $message : ("Hi, I'm Ryhn. " . $message);
        $prefixed = admin_ai_msg_compact_repeated_ryhn_intro($prefixed);
        if (!$introOnce) return $prefixed;

        $hasPriorAssistant = admin_ai_msg_history_has_assistant_reply($history);
        if (!$hasPriorAssistant) return $prefixed;

        $withoutIntro = admin_ai_msg_strip_ryhn_intro_prefix($prefixed);
        return $withoutIntro !== '' ? $withoutIntro : 'How can I help?';
    }
}

if (!function_exists('admin_ai_msg_is_generic_assistant_message')) {
    function admin_ai_msg_is_generic_assistant_message($message) {
        $text = strtolower(trim((string) preg_replace('/\s+/', ' ', (string) $message)));
        if ($text === '') return true;
        $text = preg_replace('/^hi,\s*i\s*(?:\'m|am)\s*ryhn\b[:.!-]?\s*/i', '', $text);
        if (!is_string($text)) $text = '';
        $text = trim($text);
        if ($text === '') return true;

        $genericPatterns = [
            '/^how can i assist you today\??$/',
            '/^how can i help\??$/',
            '/^how may i help\??$/',
            '/^what can i do for you\??$/',
        ];
        foreach ($genericPatterns as $p) {
            if (preg_match($p, $text)) return true;
        }
        return false;
    }
}

if (!function_exists('admin_ai_msg_extract_json_object')) {
    function admin_ai_msg_extract_json_object($content) {
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

if (!function_exists('admin_ai_msg_if_code_valid')) {
    function admin_ai_msg_if_code_valid($code) {
        $code = strtoupper(admin_ai_msg_clean_text($code, 60));
        if ($code === '') return false;
        if (function_exists('ref_is_if_section')) return (bool) ref_is_if_section($code);
        return (bool) preg_match('/^IF-\d+-[A-Z]-\d+$/', $code);
    }
}

if (!function_exists('admin_ai_msg_normalize_subject_identifiers')) {
    function admin_ai_msg_normalize_subject_identifiers($raw, $maxItems = 20) {
        $items = [];
        if (is_array($raw)) $items = $raw;
        elseif (is_string($raw)) $items = preg_split('/[\n,;]+/', $raw);

        if (!is_array($items)) $items = [];
        $maxItems = (int) $maxItems;
        if ($maxItems < 1) $maxItems = 1;
        if ($maxItems > 50) $maxItems = 50;

        $out = [];
        $seen = [];
        foreach ($items as $row) {
            $type = '';
            $value = '';

            if (is_array($row)) {
                $type = strtolower(trim((string) ($row['type'] ?? '')));
                $value = trim((string) ($row['value'] ?? ($row['subject'] ?? '')));
            } else {
                $value = trim((string) $row);
            }

            if ($value === '') continue;
            if ($type === '') {
                if (preg_match('/^\d+$/', $value)) {
                    $type = 'id';
                } elseif (preg_match('/^[A-Za-z]{1,8}\s*\d{1,4}[A-Za-z]?(?:-[A-Za-z0-9]+)?$/', $value)) {
                    $type = 'code';
                } else {
                    $type = 'name';
                }
            }

            if (!in_array($type, ['id', 'code', 'name'], true)) $type = 'name';
            $value = admin_ai_msg_clean_text($value, 180);
            if ($value === '') continue;
            if ($type === 'code') $value = strtoupper($value);

            $key = $type . '|' . strtolower($value);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = ['type' => $type, 'value' => $value];
            if (count($out) >= $maxItems) break;
        }

        return $out;
    }
}

if (!function_exists('admin_ai_msg_normalize_user_identifier')) {
    function admin_ai_msg_normalize_user_identifier($raw) {
        $type = '';
        $value = '';
        if (is_array($raw)) {
            $type = strtolower(trim((string) ($raw['type'] ?? '')));
            $value = trim((string) ($raw['value'] ?? ($raw['user'] ?? '')));
        } else {
            $value = trim((string) $raw);
        }

        if ($value === '') return ['type' => '', 'value' => ''];
        if ($type === '') {
            if (preg_match('/^\d+$/', $value)) $type = 'id';
            elseif (strpos($value, '@') !== false) $type = 'email';
            elseif (strpos($value, ' ') !== false) $type = 'name';
            else $type = 'username';
        }
        if (!in_array($type, ['id', 'email', 'username', 'name'], true)) $type = 'name';

        $value = admin_ai_msg_clean_text($value, 180);
        if ($type === 'email') $value = strtolower($value);
        return ['type' => $type, 'value' => $value];
    }
}

if (!function_exists('admin_ai_msg_parse_bool')) {
    function admin_ai_msg_parse_bool($value, $defaultValue = false) {
        if (is_bool($value)) return $value;
        if (is_int($value) || is_float($value)) return ((int) $value) === 1;

        $raw = strtolower(trim((string) $value));
        if ($raw === '') return (bool) $defaultValue;
        if (in_array($raw, ['1', 'true', 'yes', 'y', 'on', 'active', 'enabled'], true)) return true;
        if (in_array($raw, ['0', 'false', 'no', 'n', 'off', 'inactive', 'disabled'], true)) return false;
        return (bool) $defaultValue;
    }
}

if (!function_exists('admin_ai_msg_normalize_email')) {
    function admin_ai_msg_normalize_email($value, $maxLen = 255) {
        $value = strtolower(admin_ai_msg_clean_text($value, (int) $maxLen));
        return $value;
    }
}

if (!function_exists('admin_ai_msg_allowed_user_roles')) {
    function admin_ai_msg_allowed_user_roles() {
        return ['student', 'teacher', 'registrar', 'program_chair', 'college_dean', 'guardian'];
    }
}

if (!function_exists('admin_ai_msg_normalize_user_role')) {
    function admin_ai_msg_normalize_user_role($value, $defaultRole = 'student') {
        $role = normalize_role((string) $value);
        $allowed = admin_ai_msg_allowed_user_roles();
        if (!in_array($role, $allowed, true)) {
            $role = in_array($defaultRole, $allowed, true) ? $defaultRole : 'student';
        }
        return $role;
    }
}

if (!function_exists('admin_ai_msg_normalize_student_no')) {
    function admin_ai_msg_normalize_student_no($value) {
        $value = trim((string) $value);
        $value = preg_replace('/\s+/', '', $value);
        if (!is_string($value)) $value = '';
        if (strlen($value) > 30) $value = substr($value, 0, 30);
        return $value;
    }
}

if (!function_exists('admin_ai_msg_normalize_student_sex')) {
    function admin_ai_msg_normalize_student_sex($value) {
        $value = strtoupper(trim((string) $value));
        return in_array($value, ['M', 'F'], true) ? $value : 'M';
    }
}

if (!function_exists('admin_ai_msg_normalize_student_status')) {
    function admin_ai_msg_normalize_student_status($value) {
        $raw = strtolower(trim((string) $value));
        if ($raw === 'inactive') return 'Inactive';
        if ($raw === 'graduated') return 'Graduated';
        if ($raw === 'dropped') return 'Dropped';
        return 'Active';
    }
}

if (!function_exists('admin_ai_msg_normalize_date')) {
    function admin_ai_msg_normalize_date($value) {
        $value = trim((string) $value);
        if ($value === '') return '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return $value;

        $ts = strtotime($value);
        if ($ts === false) return '';
        return date('Y-m-d', $ts);
    }
}

if (!function_exists('admin_ai_msg_normalize_remarks_status')) {
    function admin_ai_msg_normalize_remarks_status($value) {
        $raw = strtolower(trim((string) $value));
        if ($raw === '') return 'Accomplished';
        if (strpos($raw, 'on-going') !== false) return 'On-going';
        if (strpos($raw, 'ongoing') !== false) return 'On-going';
        if (strpos($raw, 'on going') !== false) return 'On-going';
        if (strpos($raw, 'in progress') !== false) return 'On-going';
        return 'Accomplished';
    }
}

if (!function_exists('admin_ai_msg_normalize_positive_int')) {
    function admin_ai_msg_normalize_positive_int($value, $defaultValue = 20, $min = 1, $max = 100) {
        $n = (int) $value;
        $defaultValue = (int) $defaultValue;
        $min = (int) $min;
        $max = (int) $max;
        if ($min <= 0) $min = 1;
        if ($max < $min) $max = $min;
        if ($defaultValue < $min || $defaultValue > $max) $defaultValue = $min;
        if ($n < $min || $n > $max) return $defaultValue;
        return $n;
    }
}

if (!function_exists('admin_ai_msg_sanitize_action_plan')) {
    function admin_ai_msg_sanitize_action_plan($plan) {
        if (!is_array($plan)) return null;

        $type = strtolower(trim((string) ($plan['type'] ?? '')));
        if ($type === 'create_section_and_assign_subjects') $type = 'create_class_section_and_assign_subjects';
        if ($type === 'create_section_assign_subjects') $type = 'create_class_section_and_assign_subjects';
        if ($type === 'create_teacher_accomplishment') $type = 'create_accomplishment_for_user';
        if ($type === 'create_accomplishment_on_behalf') $type = 'create_accomplishment_for_user';
        if ($type === 'create_accomplishment_for_teacher') $type = 'create_accomplishment_for_user';
        if ($type === 'show_teacher_accomplishments') $type = 'list_accomplishments_for_user';
        if ($type === 'get_teacher_accomplishments') $type = 'list_accomplishments_for_user';
        if ($type === 'view_accomplishments_for_user') $type = 'list_accomplishments_for_user';
        if ($type === 'show_accomplishments_for_user') $type = 'list_accomplishments_for_user';
        if ($type === 'count_students') $type = 'count_enrolled_students';
        if ($type === 'count_student_enrollments') $type = 'count_enrolled_students';
        if ($type === 'get_enrolled_students_count') $type = 'count_enrolled_students';
        if ($type === 'show_enrolled_students_count') $type = 'count_enrolled_students';
        if ($type === 'create_user') $type = 'create_user_account';
        if ($type === 'create_account') $type = 'create_user_account';
        if ($type === 'create_student_account') $type = 'create_user_account';
        if ($type === 'create_student') $type = 'create_student_user_and_profile';
        if ($type === 'create_student_profile') $type = 'create_student_user_and_profile';
        if ($type === 'create_student_with_account') $type = 'create_student_user_and_profile';

        if ($type === 'create_class_section_and_assign_subjects') {
            $sectionCode = strtoupper(admin_ai_msg_clean_text((string) ($plan['section_code'] ?? ''), 60));
            $sectionDescription = admin_ai_msg_clean_text((string) ($plan['section_description'] ?? ''), 1000);
            $sectionStatus = strtolower(trim((string) ($plan['section_status'] ?? 'active')));
            if (!in_array($sectionStatus, ['active', 'inactive'], true)) $sectionStatus = 'active';

            $subjectIdentifiers = admin_ai_msg_normalize_subject_identifiers(
                $plan['subject_identifiers'] ?? ($plan['subjects'] ?? []),
                25
            );

            return [
                'type' => $type,
                'section_code' => $sectionCode,
                'section_description' => $sectionDescription,
                'section_status' => $sectionStatus,
                'subject_identifiers' => $subjectIdentifiers,
            ];
        }

        if ($type === 'create_accomplishment_for_user') {
            $entry = is_array($plan['entry'] ?? null) ? $plan['entry'] : [];
            $targetRaw = $plan['target_user_identifier']
                ?? ($plan['target_user'] ?? ($entry['target_user'] ?? ''));
            $targetUserIdentifier = admin_ai_msg_normalize_user_identifier($targetRaw);

            $entryDateRaw = (string) ($plan['entry_date'] ?? ($entry['date'] ?? ($entry['entry_date'] ?? '')));
            $entryDate = admin_ai_msg_normalize_date($entryDateRaw);

            $subjectLabel = admin_ai_msg_clean_text((string) ($plan['subject_label'] ?? ($entry['subject_label'] ?? '')), 255);
            $title = admin_ai_msg_clean_text((string) ($plan['title'] ?? ($entry['title'] ?? '')), 255);
            $details = trim((string) ($plan['details'] ?? ($entry['details'] ?? ($entry['description'] ?? ''))));
            $details = admin_ai_msg_clean_text($details, 5000);
            $remarksRaw = (string) ($plan['remarks'] ?? ($entry['remarks'] ?? ''));
            $remarks = admin_ai_msg_normalize_remarks_status($remarksRaw);

            return [
                'type' => $type,
                'target_user_identifier' => $targetUserIdentifier,
                'entry_date' => $entryDate,
                'subject_label' => $subjectLabel,
                'title' => $title,
                'details' => $details,
                'remarks' => $remarks,
            ];
        }

        if ($type === 'list_accomplishments_for_user') {
            $query = is_array($plan['query'] ?? null) ? $plan['query'] : [];
            $targetRaw = $plan['target_user_identifier']
                ?? ($plan['target_user'] ?? ($query['target_user'] ?? ''));
            $targetUserIdentifier = admin_ai_msg_normalize_user_identifier($targetRaw);

            $dateRaw = (string) ($plan['date'] ?? ($query['date'] ?? ''));
            $dateFromRaw = (string) ($plan['date_from'] ?? ($plan['from'] ?? ($query['date_from'] ?? $dateRaw)));
            $dateToRaw = (string) ($plan['date_to'] ?? ($plan['to'] ?? ($query['date_to'] ?? $dateRaw)));

            $dateFrom = admin_ai_msg_normalize_date($dateFromRaw);
            $dateTo = admin_ai_msg_normalize_date($dateToRaw);
            if ($dateFrom === '' && $dateTo !== '') $dateFrom = $dateTo;
            if ($dateTo === '' && $dateFrom !== '') $dateTo = $dateFrom;

            $limit = admin_ai_msg_normalize_positive_int(
                $plan['limit'] ?? ($query['limit'] ?? 20),
                20,
                1,
                100
            );

            return [
                'type' => $type,
                'target_user_identifier' => $targetUserIdentifier,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'limit' => $limit,
            ];
        }

        if ($type === 'count_enrolled_students') {
            $academicYear = admin_ai_msg_clean_text((string) ($plan['academic_year'] ?? ''), 32);
            $semester = admin_ai_msg_clean_text((string) ($plan['semester'] ?? ''), 32);
            $sectionCode = strtoupper(admin_ai_msg_clean_text((string) ($plan['section_code'] ?? ($plan['section'] ?? '')), 60));
            $classRecordId = (int) ($plan['class_record_id'] ?? 0);

            $subjectIdentifiers = admin_ai_msg_normalize_subject_identifiers(
                $plan['subject_identifiers'] ?? ($plan['subject_identifier'] ?? ($plan['subjects'] ?? [])),
                1
            );
            $subjectIdentifier = count($subjectIdentifiers) > 0 ? $subjectIdentifiers[0] : null;

            return [
                'type' => $type,
                'academic_year' => $academicYear,
                'semester' => $semester,
                'section_code' => $sectionCode,
                'class_record_id' => $classRecordId > 0 ? $classRecordId : 0,
                'subject_identifier' => is_array($subjectIdentifier) ? $subjectIdentifier : null,
            ];
        }

        if ($type === 'create_user_account') {
            $role = admin_ai_msg_normalize_user_role(
                $plan['user_role'] ?? ($plan['role'] ?? ($plan['account_role'] ?? 'student')),
                'student'
            );
            $userEmail = admin_ai_msg_normalize_email($plan['user_email'] ?? ($plan['email'] ?? ''), 255);
            $username = admin_ai_msg_clean_text((string) ($plan['username'] ?? ($plan['name'] ?? '')), 100);
            if ($username === '' && $userEmail !== '' && strpos($userEmail, '@') !== false) {
                $username = admin_ai_msg_clean_text((string) strstr($userEmail, '@', true), 100);
            }

            $initialPassword = trim((string) ($plan['initial_password'] ?? ($plan['password'] ?? '')));
            if (strlen($initialPassword) > 120) $initialPassword = substr($initialPassword, 0, 120);

            $isActive = admin_ai_msg_parse_bool($plan['is_active'] ?? ($plan['active'] ?? true), true);
            $forcePasswordChange = admin_ai_msg_parse_bool(
                $plan['force_password_change'] ?? ($plan['must_change_password'] ?? ($role === 'student')),
                $role === 'student'
            );
            $firstName = admin_ai_msg_clean_text((string) ($plan['first_name'] ?? ''), 80);
            $lastName = admin_ai_msg_clean_text((string) ($plan['last_name'] ?? ''), 80);

            return [
                'type' => $type,
                'user_role' => $role,
                'user_email' => $userEmail,
                'username' => $username,
                'initial_password' => $initialPassword,
                'is_active' => $isActive ? 1 : 0,
                'force_password_change' => $forcePasswordChange ? 1 : 0,
                'first_name' => $firstName,
                'last_name' => $lastName,
            ];
        }

        if ($type === 'create_student_user_and_profile') {
            $studentNo = admin_ai_msg_normalize_student_no(
                $plan['student_no']
                    ?? ($plan['student_id'] ?? ($plan['StudentNo'] ?? ($plan['student_number'] ?? '')))
            );
            $surname = admin_ai_msg_clean_text((string) ($plan['surname'] ?? ($plan['last_name'] ?? '')), 80);
            $firstName = admin_ai_msg_clean_text((string) ($plan['first_name'] ?? ($plan['firstname'] ?? '')), 80);
            $middleName = admin_ai_msg_clean_text((string) ($plan['middle_name'] ?? ($plan['middlename'] ?? '')), 80);

            $sex = admin_ai_msg_normalize_student_sex($plan['sex'] ?? 'M');
            $course = admin_ai_msg_clean_text((string) ($plan['course'] ?? ($plan['program'] ?? '')), 100);
            $major = admin_ai_msg_clean_text((string) ($plan['major'] ?? ''), 100);
            $yearLevel = admin_ai_msg_clean_text((string) ($plan['year_level'] ?? ($plan['year'] ?? '')), 20);
            $section = admin_ai_msg_clean_text((string) ($plan['section'] ?? ''), 20);
            $studentStatus = admin_ai_msg_normalize_student_status($plan['student_status'] ?? ($plan['status'] ?? 'Active'));
            $studentEmail = admin_ai_msg_normalize_email($plan['student_email'] ?? ($plan['email'] ?? ''), 255);

            if (function_exists('ref_normalize_course_name')) {
                $course = ref_normalize_course_name($course);
            }
            if (function_exists('ref_normalize_year_level')) {
                $yearLevel = ref_normalize_year_level($yearLevel);
            }
            if (function_exists('ref_normalize_section_code')) {
                $section = ref_normalize_section_code($section);
            }

            $targetUserRaw = $plan['target_user_identifier']
                ?? ($plan['target_user'] ?? ($plan['user_identifier'] ?? ''));
            $targetUserIdentifier = admin_ai_msg_normalize_user_identifier($targetUserRaw);

            $createUserIfMissing = admin_ai_msg_parse_bool($plan['create_user_if_missing'] ?? true, true);
            $userRole = admin_ai_msg_normalize_user_role($plan['user_role'] ?? 'student', 'student');
            if ($userRole !== 'student') $userRole = 'student';

            $userEmail = admin_ai_msg_normalize_email($plan['user_email'] ?? $studentEmail, 255);
            $username = admin_ai_msg_clean_text((string) ($plan['username'] ?? $studentNo), 100);
            $initialPassword = trim((string) ($plan['initial_password'] ?? ($plan['password'] ?? '')));
            if (strlen($initialPassword) > 120) $initialPassword = substr($initialPassword, 0, 120);
            $isActive = admin_ai_msg_parse_bool($plan['is_active'] ?? true, true);
            $forcePasswordChange = admin_ai_msg_parse_bool(
                $plan['force_password_change'] ?? ($plan['must_change_password'] ?? true),
                true
            );

            return [
                'type' => $type,
                'student_no' => $studentNo,
                'surname' => $surname,
                'first_name' => $firstName,
                'middle_name' => $middleName,
                'sex' => $sex,
                'course' => $course,
                'major' => $major,
                'year_level' => $yearLevel,
                'section' => $section,
                'student_status' => $studentStatus,
                'student_email' => $studentEmail,
                'target_user_identifier' => $targetUserIdentifier,
                'create_user_if_missing' => $createUserIfMissing ? 1 : 0,
                'user_role' => $userRole,
                'user_email' => $userEmail,
                'username' => $username,
                'initial_password' => $initialPassword,
                'is_active' => $isActive ? 1 : 0,
                'force_password_change' => $forcePasswordChange ? 1 : 0,
            ];
        }

        return null;
    }
}

if (!function_exists('admin_ai_msg_fetch_subject_catalog')) {
    function admin_ai_msg_fetch_subject_catalog(mysqli $conn, $limit = 140) {
        $limit = (int) $limit;
        if ($limit < 20) $limit = 20;
        if ($limit > 300) $limit = 300;

        $rows = [];
        $sql = "SELECT id, subject_code, subject_name
                FROM subjects
                WHERE status = 'active'
                ORDER BY subject_code ASC, subject_name ASC
                LIMIT " . $limit;
        $res = $conn->query($sql);
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $code = strtoupper(trim((string) ($r['subject_code'] ?? '')));
                $name = admin_ai_msg_clean_text((string) ($r['subject_name'] ?? ''), 120);
                if ($code === '' && $name === '') continue;
                $rows[] = [
                    'id' => (int) ($r['id'] ?? 0),
                    'code' => $code,
                    'name' => $name,
                ];
            }
        }
        return $rows;
    }
}

if (!function_exists('admin_ai_msg_fetch_teacher_catalog')) {
    function admin_ai_msg_fetch_teacher_catalog(mysqli $conn, $limit = 120) {
        $limit = (int) $limit;
        if ($limit < 20) $limit = 20;
        if ($limit > 300) $limit = 300;

        $rows = [];
        $sql = "SELECT id, username, useremail, first_name, last_name
                FROM users
                WHERE role = 'teacher'
                  AND is_active = 1
                ORDER BY first_name ASC, last_name ASC, username ASC
                LIMIT " . $limit;
        $res = $conn->query($sql);
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $id = (int) ($r['id'] ?? 0);
                if ($id <= 0) continue;
                $fn = trim((string) ($r['first_name'] ?? ''));
                $ln = trim((string) ($r['last_name'] ?? ''));
                $username = trim((string) ($r['username'] ?? ''));
                $email = strtolower(trim((string) ($r['useremail'] ?? '')));
                $display = trim($fn . ' ' . $ln);
                if ($display === '') $display = $username !== '' ? $username : ('Teacher #' . $id);
                $rows[] = [
                    'id' => $id,
                    'username' => $username,
                    'email' => $email,
                    'display_name' => $display,
                ];
            }
        }
        return $rows;
    }
}

if (!function_exists('admin_ai_msg_parse_fallback_section_subject_plan')) {
    function admin_ai_msg_parse_fallback_section_subject_plan($message) {
        $message = (string) $message;
        $sectionCode = '';
        if (preg_match('/\b(IF-\d+-[A-Z]-\d+)\b/i', $message, $m)) {
            $sectionCode = strtoupper(trim((string) ($m[1] ?? '')));
        }

        $identifiers = [];
        $chunks = preg_split('/[\n,;]+/', $message);
        if (is_array($chunks)) {
            foreach ($chunks as $c) {
                $c = trim((string) $c);
                if ($c === '') continue;
                if (preg_match('/^[A-Za-z]{1,8}\s*\d{1,4}[A-Za-z]?(?:-[A-Za-z0-9]+)?$/', $c)) {
                    $identifiers[] = ['type' => 'code', 'value' => strtoupper($c)];
                }
            }
        }
        $identifiers = admin_ai_msg_normalize_subject_identifiers($identifiers, 15);

        return [
            'type' => 'create_class_section_and_assign_subjects',
            'section_code' => $sectionCode,
            'section_description' => '',
            'section_status' => 'active',
            'subject_identifiers' => $identifiers,
        ];
    }
}

if (!function_exists('admin_ai_msg_fallback_response')) {
    function admin_ai_msg_fallback_response($message, $history = []) {
        $message = trim((string) $message);
        $lower = strtolower($message);

        $looksLikeEnrollmentCount = (
            (
                strpos($lower, 'count') !== false ||
                strpos($lower, 'number') !== false ||
                strpos($lower, 'how many') !== false
            ) &&
            strpos($lower, 'student') !== false &&
            (
                strpos($lower, 'enrolled') !== false ||
                strpos($lower, 'enrollment') !== false ||
                strpos($lower, 'roster') !== false
            )
        );
        if ($looksLikeEnrollmentCount) {
            $academicYear = '';
            if (preg_match('/\b(20\d{2}\s*-\s*20\d{2})\b/', $message, $mAy)) {
                $academicYear = admin_ai_msg_clean_text((string) ($mAy[1] ?? ''), 32);
            }

            $semester = '';
            if (preg_match('/\b(1st|first)\s*semester\b/i', $message)) $semester = '1st Semester';
            if (preg_match('/\b(2nd|second)\s*semester\b/i', $message)) $semester = '2nd Semester';
            if ($semester === '' && preg_match('/\bmidterm\b/i', $message)) $semester = 'Midterm';
            if ($semester === '' && preg_match('/\bfinal\b/i', $message)) $semester = 'Final';

            $sectionCode = '';
            if (preg_match('/\b(IF-\d+-[A-Z]-\d+)\b/i', $message, $mSec)) {
                $sectionCode = strtoupper(trim((string) ($mSec[1] ?? '')));
            }

            $subjectIdentifier = null;
            if (preg_match('/\bsubject\s*id\s*[:=]?\s*(\d+)\b/i', $message, $mSubId)) {
                $subjectIdentifier = ['type' => 'id', 'value' => (string) ($mSubId[1] ?? '')];
            } elseif (preg_match('/\b([A-Za-z]{1,8}\s*\d{1,4}[A-Za-z]?(?:-[A-Za-z0-9]+)?)\b/', $message, $mSubCode)) {
                $subjectIdentifier = ['type' => 'code', 'value' => strtoupper(trim((string) ($mSubCode[1] ?? '')))];
            }

            $plan = [
                'type' => 'count_enrolled_students',
                'academic_year' => $academicYear,
                'semester' => $semester,
                'section_code' => $sectionCode,
                'subject_identifier' => $subjectIdentifier,
            ];
            $plan = admin_ai_msg_sanitize_action_plan($plan);

            return [
                'assistant_message' => admin_ai_msg_with_ryhn_intro(
                    'I prepared the enrollment count query. Review and click Execute.',
                    $history,
                    true
                ),
                'needs_details' => false,
                'missing_fields' => [],
                'action_ready' => is_array($plan),
                'action_plan' => is_array($plan) ? $plan : null,
            ];
        }

        $looksLikeCreateUser = (
            (strpos($lower, 'create') !== false || strpos($lower, 'add') !== false) &&
            (
                strpos($lower, 'user account') !== false ||
                strpos($lower, 'account for') !== false ||
                strpos($lower, 'create user') !== false
            )
        );
        if ($looksLikeCreateUser) {
            $email = '';
            if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $message, $m)) {
                $email = admin_ai_msg_normalize_email((string) ($m[0] ?? ''), 255);
            }
            $role = 'student';
            if (strpos($lower, 'teacher') !== false) $role = 'teacher';
            if (strpos($lower, 'registrar') !== false) $role = 'registrar';
            if (strpos($lower, 'program chair') !== false) $role = 'program_chair';
            if (strpos($lower, 'college dean') !== false || strpos($lower, 'dean') !== false) $role = 'college_dean';
            if (strpos($lower, 'guardian') !== false) $role = 'guardian';

            $username = '';
            if (preg_match('/\busername\s*[:=]?\s*([A-Za-z0-9._\-]+)/i', $message, $mUser)) {
                $username = admin_ai_msg_clean_text((string) ($mUser[1] ?? ''), 100);
            } elseif ($email !== '' && strpos($email, '@') !== false) {
                $username = admin_ai_msg_clean_text((string) strstr($email, '@', true), 100);
            }

            $plan = [
                'type' => 'create_user_account',
                'user_role' => $role,
                'user_email' => $email,
                'username' => $username,
                'is_active' => 1,
                'force_password_change' => $role === 'student' ? 1 : 0,
            ];
            $plan = admin_ai_msg_sanitize_action_plan($plan);

            $missing = [];
            if (!is_array($plan)) {
                $missing[] = 'Account details.';
            } else {
                if (!filter_var((string) ($plan['user_email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
                    $missing[] = 'User email (valid format).';
                }
                if (trim((string) ($plan['username'] ?? '')) === '') {
                    $missing[] = 'Username.';
                }
            }

            $ready = count($missing) === 0 && is_array($plan);
            return [
                'assistant_message' => admin_ai_msg_with_ryhn_intro(
                    $ready
                        ? 'I prepared the account-creation action plan. Review and click Execute.'
                        : ("I can create that account. Please provide:\n- " . implode("\n- ", $missing)),
                    $history,
                    true
                ),
                'needs_details' => !$ready,
                'missing_fields' => $missing,
                'action_ready' => $ready,
                'action_plan' => $ready ? $plan : null,
            ];
        }

        $looksLikeCreateStudent = (
            strpos($lower, 'create student') !== false ||
            strpos($lower, 'add student') !== false ||
            strpos($lower, 'new student profile') !== false ||
            strpos($lower, 'enroll student') !== false
        );
        if ($looksLikeCreateStudent) {
            $studentNo = '';
            if (preg_match('/\b\d{4,}[A-Za-z0-9\-]*-\d+\b/', $message, $mNo)) {
                $studentNo = admin_ai_msg_normalize_student_no((string) ($mNo[0] ?? ''));
            }

            $email = '';
            if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $message, $mEmail)) {
                $email = admin_ai_msg_normalize_email((string) ($mEmail[0] ?? ''), 255);
            }

            $plan = admin_ai_msg_sanitize_action_plan([
                'type' => 'create_student_user_and_profile',
                'student_no' => $studentNo,
                'surname' => '',
                'first_name' => '',
                'middle_name' => '',
                'sex' => 'M',
                'course' => '',
                'major' => '',
                'year_level' => '',
                'section' => '',
                'student_status' => 'Active',
                'student_email' => $email,
                'create_user_if_missing' => 1,
                'user_email' => $email,
                'username' => $studentNo,
                'is_active' => 1,
                'force_password_change' => 1,
            ]);

            $missing = [
                'Student ID / Student No.',
                'Surname and First Name.',
                'Course/Program, Year Level, and Profile Section.',
                'Student email (for linked login account).',
            ];

            return [
                'assistant_message' => admin_ai_msg_with_ryhn_intro(
                    "I can create the student profile and linked account. Please provide:\n- " . implode("\n- ", $missing),
                    $history,
                    true
                ),
                'needs_details' => true,
                'missing_fields' => $missing,
                'action_ready' => false,
                'action_plan' => null,
            ];
        }

        $looksLikeAccomplishmentQuery = (
            strpos($lower, 'accomplishment') !== false &&
            (
                strpos($lower, 'show') !== false ||
                strpos($lower, 'list') !== false ||
                strpos($lower, 'view') !== false ||
                strpos($lower, 'rendered') !== false ||
                strpos($lower, 'today') !== false
            )
        );
        if ($looksLikeAccomplishmentQuery) {
            $target = ['type' => '', 'value' => ''];
            if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $message, $m)) {
                $target = admin_ai_msg_normalize_user_identifier(['type' => 'email', 'value' => (string) ($m[0] ?? '')]);
            }
            $today = date('Y-m-d');
            $dateFrom = '';
            $dateTo = '';
            if (strpos($lower, 'today') !== false) {
                $dateFrom = $today;
                $dateTo = $today;
            } elseif (preg_match('/\b\d{4}-\d{2}-\d{2}\b/', $message, $mDate)) {
                $d = admin_ai_msg_normalize_date((string) ($mDate[0] ?? ''));
                $dateFrom = $d;
                $dateTo = $d;
            }

            $plan = [
                'type' => 'list_accomplishments_for_user',
                'target_user_identifier' => $target,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'limit' => 20,
            ];

            $missing = [];
            if (trim((string) ($target['value'] ?? '')) === '') {
                $missing[] = 'Target teacher identifier (email, username, or id).';
            }
            if ($dateFrom === '' || $dateTo === '') {
                $missing[] = 'Date or date range (YYYY-MM-DD), or say "today".';
            }

            $ready = count($missing) === 0;
            return [
                'assistant_message' => admin_ai_msg_with_ryhn_intro(
                    $ready
                        ? 'I prepared a query plan to fetch accomplishment records. Review and click Execute.'
                        : ("I can fetch that now. Please provide:\n- " . implode("\n- ", $missing)),
                    $history,
                    true
                ),
                'needs_details' => !$ready,
                'missing_fields' => $missing,
                'action_ready' => $ready,
                'action_plan' => $ready ? $plan : null,
            ];
        }

        if (
            strpos($lower, 'accomplishment') !== false ||
            strpos($lower, 'on behalf') !== false ||
            strpos($lower, 'for teacher') !== false
        ) {
            $missing = [
                'Target teacher identifier (id, username, or email).',
                'Entry date (YYYY-MM-DD).',
                'Subject label.',
                'Title.',
                'Details/description.',
                'Remarks status (Accomplished or On-going).',
            ];
            return [
                'assistant_message' => admin_ai_msg_with_ryhn_intro(
                    "I can prepare that on behalf of a teacher. Please provide:\n- " . implode("\n- ", $missing),
                    $history,
                    true
                ),
                'needs_details' => true,
                'missing_fields' => $missing,
                'action_ready' => false,
                'action_plan' => null,
            ];
        }

        $plan = admin_ai_msg_parse_fallback_section_subject_plan($message);
        $missing = [];
        if (!admin_ai_msg_if_code_valid((string) ($plan['section_code'] ?? ''))) {
            $missing[] = 'Class section code in IF format (example: IF-2-B-6).';
        }
        if (count($plan['subject_identifiers']) === 0) {
            $missing[] = 'At least one subject identifier (subject code, id, or exact subject name).';
        }

        $ready = count($missing) === 0;
        $assistantMessage = $ready
            ? "I have enough details to proceed. Review the pending action plan and click Execute when ready."
            : "I can do that. Please provide:\n- " . implode("\n- ", $missing);

        return [
            'assistant_message' => admin_ai_msg_with_ryhn_intro($assistantMessage, $history, true),
            'needs_details' => !$ready,
            'missing_fields' => $missing,
            'action_ready' => $ready,
            'action_plan' => $ready ? $plan : null,
        ];
    }
}

if (!function_exists('admin_ai_msg_chat_respond')) {
    function admin_ai_msg_chat_respond(mysqli $conn, array $history, $message) {
        if (!function_exists('ai_access_can_use') || !ai_access_can_use()) {
            if (function_exists('ai_access_denied_message')) return [false, ai_access_denied_message()];
            return [false, 'AI access is not allowed for this account.'];
        }
        $role = isset($_SESSION['user_role']) ? normalize_role((string) $_SESSION['user_role']) : '';
        if ($role !== 'admin') return [false, 'This AI assistant in Messages is available for Admin only.'];

        $message = trim((string) $message);
        if ($message === '') return [false, 'Message is empty.'];
        if (strlen($message) > 5000) $message = substr($message, 0, 5000);

        $apiKey = admin_ai_msg_openai_api_key();
        if ($apiKey === '' || !function_exists('curl_init')) {
            return [true, admin_ai_msg_fallback_response($message, $history)];
        }

        $historyLines = [];
        foreach ($history as $row) {
            if (!is_array($row)) continue;
            $roleRow = strtolower(trim((string) ($row['role'] ?? '')));
            $content = admin_ai_msg_clean_text((string) ($row['content'] ?? ''), 700);
            if ($content === '') continue;
            $prefix = ($roleRow === 'assistant' || $roleRow === 'ai') ? 'AI' : 'Admin';
            $historyLines[] = $prefix . ': ' . $content;
            if (count($historyLines) >= 14) break;
        }

        $subjectCatalog = admin_ai_msg_fetch_subject_catalog($conn, 140);
        $teacherCatalog = admin_ai_msg_fetch_teacher_catalog($conn, 120);
        $subjectCatalogLines = [];
        foreach ($subjectCatalog as $s) {
            $subjectCatalogLines[] = [
                'id' => (int) ($s['id'] ?? 0),
                'code' => (string) ($s['code'] ?? ''),
                'name' => (string) ($s['name'] ?? ''),
            ];
        }
        $teacherCatalogLines = [];
        foreach ($teacherCatalog as $t) {
            $teacherCatalogLines[] = [
                'id' => (int) ($t['id'] ?? 0),
                'username' => (string) ($t['username'] ?? ''),
                'email' => (string) ($t['email'] ?? ''),
                'display_name' => (string) ($t['display_name'] ?? ''),
            ];
        }

        $contextPayload = [
            'supported_action_types' => [
                'create_class_section_and_assign_subjects',
                'create_accomplishment_for_user',
                'list_accomplishments_for_user',
                'count_enrolled_students',
                'create_user_account',
                'create_student_user_and_profile',
            ],
            'allowed_user_roles' => admin_ai_msg_allowed_user_roles(),
            'admin_latest_message' => admin_ai_msg_clean_text($message, 1800),
            'recent_chat' => $historyLines,
            'subject_catalog' => $subjectCatalogLines,
            'teacher_catalog' => $teacherCatalogLines,
            'rules' => [
                'intro_once_per_conversation' => true,
                'section_code_format' => 'IF-{number}-{letter}-{number} (example: IF-2-B-6)',
                'accomplishment_required_fields' => ['target_teacher', 'entry_date', 'subject_label', 'title', 'details', 'remarks'],
                'accomplishment_query_required_fields' => ['target_teacher', 'date_from', 'date_to'],
                'enrollment_count_optional_filters' => ['academic_year', 'semester', 'section_code', 'subject_identifier', 'class_record_id'],
                'create_user_required_fields' => ['user_email', 'username', 'user_role'],
                'create_student_required_fields' => ['student_no', 'surname', 'first_name', 'course', 'year_level', 'section', 'user_email_or_target_user_identifier'],
                'must_ask_clarifying_questions_before_action_ready_when_required_fields_are_missing' => true,
                'do_not_execute_directly' => true,
            ],
        ];

        $systemPrompt = "You are Ryhn, the Admin Operations Assistant for E-Record. Focus on school-admin operations topics (sections, subjects, class records, schedules, enrollment, teachers, users, accomplishment records, and student profiles/accounts). For executable tasks, never execute anything yourself: gather missing details first, then produce a structured action plan. Supported action types: create_class_section_and_assign_subjects, create_accomplishment_for_user, list_accomplishments_for_user, count_enrolled_students, create_user_account, create_student_user_and_profile. Admin requests in this scope are authoritative; do not refuse supported admin actions. Ask concise clarifying questions until required fields are complete. Introduce yourself once at the start of a conversation only. Tone: friendly yet straightforward, concise, practical. Never provide database/schema/credentials/API-key/server/filesystem/internal-system guidance. Return strict JSON only.";
        $userPrompt = "Return strict JSON with this shape:\n{\n  \"assistant_message\": \"chat reply\",\n  \"intent\": \"create_class_section_and_assign_subjects|create_accomplishment_for_user|list_accomplishments_for_user|count_enrolled_students|create_user_account|create_student_user_and_profile|general_admin_qna|unsupported\",\n  \"needs_details\": boolean,\n  \"missing_fields\": [\"...\"],\n  \"action_ready\": boolean,\n  \"action_plan\": {\n    \"type\": \"create_class_section_and_assign_subjects|create_accomplishment_for_user|list_accomplishments_for_user|count_enrolled_students|create_user_account|create_student_user_and_profile\",\n    \"section_code\": \"IF-2-B-6\",\n    \"section_description\": \"optional\",\n    \"section_status\": \"active|inactive\",\n    \"subject_identifiers\": [\n      {\"type\": \"code|id|name\", \"value\": \"...\"}\n    ],\n    \"target_user_identifier\": {\"type\": \"id|email|username|name\", \"value\": \"...\"},\n    \"entry_date\": \"YYYY-MM-DD\",\n    \"subject_label\": \"...\",\n    \"title\": \"...\",\n    \"details\": \"...\",\n    \"remarks\": \"Accomplished|On-going\",\n    \"date_from\": \"YYYY-MM-DD\",\n    \"date_to\": \"YYYY-MM-DD\",\n    \"limit\": 1-100,\n    \"academic_year\": \"optional\",\n    \"semester\": \"optional\",\n    \"class_record_id\": 0,\n    \"subject_identifier\": {\"type\": \"code|id|name\", \"value\": \"...\"},\n    \"user_role\": \"student|teacher|registrar|program_chair|college_dean|guardian\",\n    \"user_email\": \"email@domain\",\n    \"username\": \"login_name\",\n    \"initial_password\": \"optional\",\n    \"is_active\": true,\n    \"force_password_change\": true,\n    \"first_name\": \"optional\",\n    \"last_name\": \"optional\",\n    \"student_no\": \"2410233-1\",\n    \"surname\": \"BASAS\",\n    \"middle_name\": \"optional\",\n    \"sex\": \"M|F\",\n    \"course\": \"BSInfoTech\",\n    \"major\": \"optional\",\n    \"year_level\": \"2nd Year\",\n    \"section\": \"B\",\n    \"student_status\": \"Active|Inactive|Graduated|Dropped\",\n    \"student_email\": \"optional\",\n    \"create_user_if_missing\": true\n  }\n}\nRules:\n- Introduce yourself in assistant_message only when recent_chat has no prior AI reply; otherwise do not re-introduce.\n- Ask clarifying follow-up questions until required fields are complete.\n- Set action_ready=true only when action_plan is complete and valid.\n- If action_ready=false, action_plan may be null.\n- Required for create_class_section_and_assign_subjects: valid IF section_code and >=1 subject_identifiers.\n- Required for create_accomplishment_for_user: target_user_identifier, entry_date, subject_label, title, details, remarks.\n- Required for list_accomplishments_for_user: target_user_identifier, date_from, date_to.\n- count_enrolled_students requires no mandatory fields; filters are optional.\n- Required for create_user_account: user_role, user_email, username.\n- Required for create_student_user_and_profile: student_no, surname, first_name, course, year_level, section, and either target_user_identifier or user_email (with create_user_if_missing=true).\n- For unsupported requests, briefly redirect to the nearest supported admin action.\n\nContext JSON:\n" . json_encode($contextPayload, JSON_UNESCAPED_SLASHES);

        $payload = [
            'model' => 'gpt-4o-mini',
            'temperature' => 0.2,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ];

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        if (!$ch) return [true, admin_ai_msg_fallback_response($message, $history)];
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 50);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false) {
            return [false, 'AI request failed: ' . ($err !== '' ? $err : 'network error')];
        }
        if ($http >= 400) {
            if ($http === 401) return [false, 'AI authentication failed.'];
            if ($http === 429) return [false, 'AI rate limit reached.'];
            if ($http >= 500) return [false, 'AI service temporarily unavailable.'];
            return [false, 'AI request failed (HTTP ' . $http . ').'];
        }

        $decoded = json_decode((string) $resp, true);
        $content = trim((string) ($decoded['choices'][0]['message']['content'] ?? ''));
        if ($content === '') return [false, 'AI returned an empty response.'];

        $json = admin_ai_msg_extract_json_object($content);
        if (!is_array($json)) return [false, 'AI returned an invalid response format.'];

        $assistantMessage = admin_ai_msg_clean_text((string) ($json['assistant_message'] ?? ''), 2500);
        if ($assistantMessage === '') $assistantMessage = 'Please provide additional details so I can prepare a safe action plan.';
        $assistantMessage = admin_ai_msg_with_ryhn_intro($assistantMessage, $history, true);

        $needsDetails = !empty($json['needs_details']);
        $actionReady = !empty($json['action_ready']);
        $missingFields = [];
        if (is_array($json['missing_fields'] ?? null)) {
            foreach ($json['missing_fields'] as $item) {
                $txt = admin_ai_msg_clean_text((string) $item, 180);
                if ($txt !== '') $missingFields[] = $txt;
                if (count($missingFields) >= 8) break;
            }
        }

        $plan = admin_ai_msg_sanitize_action_plan($json['action_plan'] ?? null);
        if (!$plan) {
            $actionReady = false;
        }

        if ($plan) {
            $planType = (string) ($plan['type'] ?? '');
            if ($planType === 'create_class_section_and_assign_subjects') {
                if (!admin_ai_msg_if_code_valid((string) ($plan['section_code'] ?? ''))) {
                    $actionReady = false;
                    $needsDetails = true;
                    $missingFields[] = 'Class section code must be IF format (example: IF-2-B-6).';
                }
                if (count((array) ($plan['subject_identifiers'] ?? [])) === 0) {
                    $actionReady = false;
                    $needsDetails = true;
                    $missingFields[] = 'Provide at least one subject identifier.';
                }
            } elseif ($planType === 'create_accomplishment_for_user') {
                $target = is_array($plan['target_user_identifier'] ?? null) ? $plan['target_user_identifier'] : ['type' => '', 'value' => ''];
                if (trim((string) ($target['value'] ?? '')) === '') {
                    $actionReady = false;
                    $needsDetails = true;
                    $missingFields[] = 'Provide target teacher identifier.';
                }
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($plan['entry_date'] ?? ''))) {
                    $actionReady = false;
                    $needsDetails = true;
                    $missingFields[] = 'Provide entry date in YYYY-MM-DD format.';
                }
                if (trim((string) ($plan['subject_label'] ?? '')) === '') {
                    $actionReady = false;
                    $needsDetails = true;
                    $missingFields[] = 'Provide subject label.';
                }
                if (trim((string) ($plan['title'] ?? '')) === '') {
                    $actionReady = false;
                    $needsDetails = true;
                    $missingFields[] = 'Provide accomplishment title.';
                }
                if (trim((string) ($plan['details'] ?? '')) === '') {
                    $actionReady = false;
                    $needsDetails = true;
                    $missingFields[] = 'Provide accomplishment details.';
                }
            } elseif ($planType === 'list_accomplishments_for_user') {
                $target = is_array($plan['target_user_identifier'] ?? null) ? $plan['target_user_identifier'] : ['type' => '', 'value' => ''];
                if (trim((string) ($target['value'] ?? '')) === '') {
                    $actionReady = false;
                    $needsDetails = true;
                    $missingFields[] = 'Provide target teacher identifier.';
                }
                $dateFrom = (string) ($plan['date_from'] ?? '');
                $dateTo = (string) ($plan['date_to'] ?? '');
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
                    $actionReady = false;
                    $needsDetails = true;
                    $missingFields[] = 'Provide valid date_from/date_to (YYYY-MM-DD).';
                } elseif (strtotime($dateFrom) > strtotime($dateTo)) {
                    $actionReady = false;
                    $needsDetails = true;
                    $missingFields[] = 'date_from cannot be later than date_to.';
                }
            } elseif ($planType === 'count_enrolled_students') {
                $subjectIdentifier = $plan['subject_identifier'] ?? null;
                if (is_array($subjectIdentifier)) {
                    $subType = strtolower(trim((string) ($subjectIdentifier['type'] ?? '')));
                    $subValue = trim((string) ($subjectIdentifier['value'] ?? ''));
                    if ($subType === '' || $subValue === '') {
                        $actionReady = false;
                        $needsDetails = true;
                        $missingFields[] = 'If subject filter is provided, include both subject_identifier.type and value.';
                    }
                }
            } elseif ($planType === 'create_user_account') {
                $roleNow = admin_ai_msg_normalize_user_role((string) ($plan['user_role'] ?? 'student'), 'student');
                if (!in_array($roleNow, admin_ai_msg_allowed_user_roles(), true)) {
                    $actionReady = false;
                    $needsDetails = true;
                    $missingFields[] = 'Provide a valid user role.';
                }

                $emailNow = admin_ai_msg_normalize_email((string) ($plan['user_email'] ?? ''), 255);
                if (!filter_var($emailNow, FILTER_VALIDATE_EMAIL)) {
                    $actionReady = false;
                    $needsDetails = true;
                    $missingFields[] = 'Provide a valid user email.';
                }

                $usernameNow = trim((string) ($plan['username'] ?? ''));
                if ($usernameNow === '') {
                    $actionReady = false;
                    $needsDetails = true;
                    $missingFields[] = 'Provide a username.';
                }
            } elseif ($planType === 'create_student_user_and_profile') {
                if (trim((string) ($plan['student_no'] ?? '')) === '') {
                    $actionReady = false;
                    $needsDetails = true;
                    $missingFields[] = 'Provide student number (Student ID).';
                }
                if (trim((string) ($plan['surname'] ?? '')) === '') {
                    $actionReady = false;
                    $needsDetails = true;
                    $missingFields[] = 'Provide student surname.';
                }
                if (trim((string) ($plan['first_name'] ?? '')) === '') {
                    $actionReady = false;
                    $needsDetails = true;
                    $missingFields[] = 'Provide student first name.';
                }
                if (trim((string) ($plan['course'] ?? '')) === '') {
                    $actionReady = false;
                    $needsDetails = true;
                    $missingFields[] = 'Provide student course/program.';
                }
                if (trim((string) ($plan['year_level'] ?? '')) === '') {
                    $actionReady = false;
                    $needsDetails = true;
                    $missingFields[] = 'Provide student year level.';
                }
                if (trim((string) ($plan['section'] ?? '')) === '') {
                    $actionReady = false;
                    $needsDetails = true;
                    $missingFields[] = 'Provide student profile section.';
                }

                $target = is_array($plan['target_user_identifier'] ?? null) ? $plan['target_user_identifier'] : ['type' => '', 'value' => ''];
                $hasTargetUser = trim((string) ($target['value'] ?? '')) !== '';
                $createUserIfMissing = !empty($plan['create_user_if_missing']);
                $emailNow = admin_ai_msg_normalize_email((string) ($plan['user_email'] ?? ''), 255);
                if (!$hasTargetUser) {
                    if (!$createUserIfMissing) {
                        $actionReady = false;
                        $needsDetails = true;
                        $missingFields[] = 'Provide target_user_identifier or enable create_user_if_missing.';
                    }
                    if (!filter_var($emailNow, FILTER_VALIDATE_EMAIL)) {
                        $actionReady = false;
                        $needsDetails = true;
                        $missingFields[] = 'Provide a valid user_email for the linked account.';
                    }
                }

                if ($hasTargetUser) {
                    $idType = strtolower(trim((string) ($target['type'] ?? '')));
                    if ($idType === '') {
                        $actionReady = false;
                        $needsDetails = true;
                        $missingFields[] = 'Provide target_user_identifier type (id/email/username/name).';
                    }
                }
            }
        }

        if ($actionReady && !$plan) $actionReady = false;
        if ($actionReady) $needsDetails = false;

        if ((!$actionReady || !$plan) && admin_ai_msg_is_generic_assistant_message($assistantMessage)) {
            $fallback = admin_ai_msg_fallback_response($message, $history);
            if (is_array($fallback)) {
                return [true, $fallback];
            }
        }

            return [true, [
                'assistant_message' => $assistantMessage,
                'needs_details' => $needsDetails,
                'missing_fields' => array_values(array_unique($missingFields)),
                'action_ready' => $actionReady,
                'action_plan' => $actionReady ? $plan : null,
            ]];
        }
}

if (!function_exists('admin_ai_msg_find_subject_by_identifier')) {
    function admin_ai_msg_find_subject_by_identifier(mysqli $conn, array $identifier) {
        $type = strtolower(trim((string) ($identifier['type'] ?? '')));
        $value = trim((string) ($identifier['value'] ?? ''));
        if ($value === '') return [false, 'Empty subject identifier.', null];

        if ($type === 'id' && preg_match('/^\d+$/', $value)) {
            $subjectId = (int) $value;
            $stmt = $conn->prepare("SELECT id, subject_code, subject_name FROM subjects WHERE id = ? AND status = 'active' LIMIT 1");
            if (!$stmt) return [false, 'Unable to resolve subject by id.', null];
            $stmt->bind_param('i', $subjectId);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
            $stmt->close();
            if (!is_array($row)) return [false, 'No active subject found for id ' . $subjectId . '.', null];
            return [true, '', $row];
        }

        if ($type === 'code') {
            $code = strtoupper($value);
            $stmt = $conn->prepare("SELECT id, subject_code, subject_name FROM subjects WHERE UPPER(subject_code) = UPPER(?) AND status = 'active' LIMIT 1");
            if (!$stmt) return [false, 'Unable to resolve subject by code.', null];
            $stmt->bind_param('s', $code);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
            $stmt->close();
            if (!is_array($row)) return [false, 'No active subject found for code ' . $code . '.', null];
            return [true, '', $row];
        }

        $name = $value;
        $stmt = $conn->prepare(
            "SELECT id, subject_code, subject_name
             FROM subjects
             WHERE UPPER(subject_name) = UPPER(?)
               AND status = 'active'
             LIMIT 2"
        );
        if ($stmt) {
            $stmt->bind_param('s', $name);
            $stmt->execute();
            $res = $stmt->get_result();
            $rows = [];
            while ($res && ($r = $res->fetch_assoc())) $rows[] = $r;
            $stmt->close();
            if (count($rows) === 1) return [true, '', $rows[0]];
            if (count($rows) > 1) return [false, 'Multiple subjects match name "' . $name . '". Please use subject code.', null];
        }

        $like = '%' . $name . '%';
        $stmt = $conn->prepare(
            "SELECT id, subject_code, subject_name
             FROM subjects
             WHERE subject_name LIKE ?
               AND status = 'active'
             ORDER BY subject_name ASC
             LIMIT 2"
        );
        if (!$stmt) return [false, 'Unable to resolve subject by name.', null];
        $stmt->bind_param('s', $like);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($res && ($r = $res->fetch_assoc())) $rows[] = $r;
        $stmt->close();
        if (count($rows) === 1) return [true, '', $rows[0]];
        if (count($rows) > 1) return [false, 'Multiple subjects match name "' . $name . '". Please use subject code.', null];
        return [false, 'No active subject found for name "' . $name . '".', null];
    }
}

if (!function_exists('admin_ai_msg_find_teacher_by_identifier')) {
    function admin_ai_msg_find_teacher_by_identifier(mysqli $conn, array $identifier) {
        $idType = strtolower(trim((string) ($identifier['type'] ?? '')));
        $idValue = trim((string) ($identifier['value'] ?? ''));
        if ($idValue === '') return [false, 'Target teacher identifier is empty.', null];

        if ($idType === 'id' && preg_match('/^\d+$/', $idValue)) {
            $userId = (int) $idValue;
            $stmt = $conn->prepare(
                "SELECT id, username, useremail, first_name, last_name
                 FROM users
                 WHERE id = ?
                   AND role = 'teacher'
                 LIMIT 1"
            );
            if (!$stmt) return [false, 'Unable to resolve teacher by id.', null];
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
            $stmt->close();
            if (!is_array($row)) return [false, 'Teacher id not found: ' . $userId . '.', null];
            return [true, '', $row];
        }

        if ($idType === 'email') {
            $email = strtolower($idValue);
            $stmt = $conn->prepare(
                "SELECT id, username, useremail, first_name, last_name
                 FROM users
                 WHERE LOWER(useremail) = LOWER(?)
                   AND role = 'teacher'
                 LIMIT 1"
            );
            if (!$stmt) return [false, 'Unable to resolve teacher by email.', null];
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
            $stmt->close();
            if (!is_array($row)) return [false, 'Teacher email not found: ' . $email . '.', null];
            return [true, '', $row];
        }

        if ($idType === 'username') {
            $username = $idValue;
            $stmt = $conn->prepare(
                "SELECT id, username, useremail, first_name, last_name
                 FROM users
                 WHERE username = ?
                   AND role = 'teacher'
                 LIMIT 1"
            );
            if (!$stmt) return [false, 'Unable to resolve teacher by username.', null];
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
            $stmt->close();
            if (!is_array($row)) return [false, 'Teacher username not found: ' . $username . '.', null];
            return [true, '', $row];
        }

        $name = $idValue;
        $stmt = $conn->prepare(
            "SELECT id, username, useremail, first_name, last_name
             FROM users
             WHERE role = 'teacher'
               AND LOWER(TRIM(CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,'')))) = LOWER(TRIM(?))
             LIMIT 2"
        );
        if ($stmt) {
            $stmt->bind_param('s', $name);
            $stmt->execute();
            $res = $stmt->get_result();
            $rows = [];
            while ($res && ($r = $res->fetch_assoc())) $rows[] = $r;
            $stmt->close();
            if (count($rows) === 1) return [true, '', $rows[0]];
            if (count($rows) > 1) return [false, 'Multiple teachers match "' . $name . '". Please use email or username.', null];
        }

        $like = '%' . $name . '%';
        $stmt = $conn->prepare(
            "SELECT id, username, useremail, first_name, last_name
             FROM users
             WHERE role = 'teacher'
               AND (
                    username LIKE ?
                    OR useremail LIKE ?
                    OR CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,'')) LIKE ?
               )
             ORDER BY first_name ASC, last_name ASC
             LIMIT 2"
        );
        if (!$stmt) return [false, 'Unable to resolve teacher by name.', null];
        $stmt->bind_param('sss', $like, $like, $like);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($res && ($r = $res->fetch_assoc())) $rows[] = $r;
        $stmt->close();
        if (count($rows) === 1) return [true, '', $rows[0]];
        if (count($rows) > 1) return [false, 'Multiple teachers match "' . $name . '". Please use email or username.', null];
        return [false, 'Teacher not found: ' . $name . '.', null];
    }
}

if (!function_exists('admin_ai_msg_db_has_column')) {
    function admin_ai_msg_db_has_column(mysqli $conn, $table, $column) {
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
        $ok = $res && $res->num_rows === 1;
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('admin_ai_msg_generate_temp_password')) {
    function admin_ai_msg_generate_temp_password($length = 12) {
        $length = (int) $length;
        if ($length < 8) $length = 8;
        if ($length > 64) $length = 64;

        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }
}

if (!function_exists('admin_ai_msg_find_user_by_identifier')) {
    function admin_ai_msg_find_user_by_identifier(mysqli $conn, array $identifier, $excludeAdmin = true) {
        $idType = strtolower(trim((string) ($identifier['type'] ?? '')));
        $idValue = trim((string) ($identifier['value'] ?? ''));
        if ($idValue === '') return [false, 'Target user identifier is empty.', null];

        $excludeAdmin = (bool) $excludeAdmin;
        $adminGuardSql = $excludeAdmin ? " AND role <> 'admin'" : '';

        if ($idType === 'id' && preg_match('/^\d+$/', $idValue)) {
            $userId = (int) $idValue;
            $stmt = $conn->prepare(
                "SELECT id, username, useremail, role, first_name, last_name
                 FROM users
                 WHERE id = ?" . $adminGuardSql . "
                 LIMIT 1"
            );
            if (!$stmt) return [false, 'Unable to resolve user by id.', null];
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
            $stmt->close();
            if (!is_array($row)) return [false, 'User id not found: ' . $userId . '.', null];
            return [true, '', $row];
        }

        if ($idType === 'email') {
            $email = strtolower($idValue);
            $stmt = $conn->prepare(
                "SELECT id, username, useremail, role, first_name, last_name
                 FROM users
                 WHERE LOWER(useremail) = LOWER(?)" . $adminGuardSql . "
                 LIMIT 1"
            );
            if (!$stmt) return [false, 'Unable to resolve user by email.', null];
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
            $stmt->close();
            if (!is_array($row)) return [false, 'User email not found: ' . $email . '.', null];
            return [true, '', $row];
        }

        if ($idType === 'username') {
            $username = $idValue;
            $stmt = $conn->prepare(
                "SELECT id, username, useremail, role, first_name, last_name
                 FROM users
                 WHERE username = ?" . $adminGuardSql . "
                 LIMIT 1"
            );
            if (!$stmt) return [false, 'Unable to resolve user by username.', null];
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
            $stmt->close();
            if (!is_array($row)) return [false, 'User username not found: ' . $username . '.', null];
            return [true, '', $row];
        }

        $name = $idValue;
        $stmt = $conn->prepare(
            "SELECT id, username, useremail, role, first_name, last_name
             FROM users
             WHERE LOWER(TRIM(CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,'')))) = LOWER(TRIM(?))" . $adminGuardSql . "
             LIMIT 2"
        );
        if ($stmt) {
            $stmt->bind_param('s', $name);
            $stmt->execute();
            $res = $stmt->get_result();
            $rows = [];
            while ($res && ($r = $res->fetch_assoc())) $rows[] = $r;
            $stmt->close();
            if (count($rows) === 1) return [true, '', $rows[0]];
            if (count($rows) > 1) return [false, 'Multiple users match "' . $name . '". Please use email or username.', null];
        }

        $like = '%' . $name . '%';
        $stmt = $conn->prepare(
            "SELECT id, username, useremail, role, first_name, last_name
             FROM users
             WHERE (username LIKE ? OR useremail LIKE ? OR CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,'')) LIKE ?)" . $adminGuardSql . "
             ORDER BY first_name ASC, last_name ASC, username ASC
             LIMIT 2"
        );
        if (!$stmt) return [false, 'Unable to resolve user by name.', null];
        $stmt->bind_param('sss', $like, $like, $like);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($res && ($r = $res->fetch_assoc())) $rows[] = $r;
        $stmt->close();
        if (count($rows) === 1) return [true, '', $rows[0]];
        if (count($rows) > 1) return [false, 'Multiple users match "' . $name . '". Please use email or username.', null];
        return [false, 'User not found: ' . $name . '.', null];
    }
}

if (!function_exists('admin_ai_msg_create_user_account_record')) {
    function admin_ai_msg_create_user_account_record(mysqli $conn, array $payload) {
        if (function_exists('ensure_users_password_policy_columns')) {
            ensure_users_password_policy_columns($conn);
        }

        $role = admin_ai_msg_normalize_user_role((string) ($payload['user_role'] ?? 'student'), 'student');
        $email = admin_ai_msg_normalize_email((string) ($payload['user_email'] ?? ''), 255);
        $username = admin_ai_msg_clean_text((string) ($payload['username'] ?? ''), 100);
        $firstName = admin_ai_msg_clean_text((string) ($payload['first_name'] ?? ''), 80);
        $lastName = admin_ai_msg_clean_text((string) ($payload['last_name'] ?? ''), 80);
        $isActive = !empty($payload['is_active']) ? 1 : 0;
        $legacyStatus = $isActive === 1 ? 'active' : 'inactive';
        $forcePasswordChange = !empty($payload['force_password_change']) ? 1 : 0;

        if ($username === '' && $email !== '' && strpos($email, '@') !== false) {
            $username = admin_ai_msg_clean_text((string) strstr($email, '@', true), 100);
        }

        if (!in_array($role, admin_ai_msg_allowed_user_roles(), true)) {
            return [false, 'Invalid user role for account creation.', null];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [false, 'User email is invalid.', null];
        }
        if ($username === '') {
            return [false, 'Username is required.', null];
        }

        $dup = $conn->prepare("SELECT id FROM users WHERE LOWER(useremail) = LOWER(?) LIMIT 1");
        if (!$dup) return [false, 'Unable to validate email uniqueness.', null];
        $dup->bind_param('s', $email);
        $dup->execute();
        $dupRes = $dup->get_result();
        $hasEmail = $dupRes && $dupRes->num_rows > 0;
        $dup->close();
        if ($hasEmail) return [false, 'Email already exists.', null];

        $dup = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        if (!$dup) return [false, 'Unable to validate username uniqueness.', null];
        $dup->bind_param('s', $username);
        $dup->execute();
        $dupRes = $dup->get_result();
        $hasUsername = $dupRes && $dupRes->num_rows > 0;
        $dup->close();
        if ($hasUsername) return [false, 'Username already exists.', null];

        $plainPassword = trim((string) ($payload['initial_password'] ?? ''));
        $generatedPassword = false;
        if ($plainPassword === '') {
            $plainPassword = admin_ai_msg_generate_temp_password(12);
            $generatedPassword = true;
        }
        if (strlen($plainPassword) < 8) {
            return [false, 'Initial password must be at least 8 characters.', null];
        }

        $hash = password_hash($plainPassword, PASSWORD_DEFAULT);
        $userId = 0;

        $stmt = $conn->prepare(
            "INSERT INTO users (useremail, username, password, role, is_active, status, must_change_password)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        if ($stmt) {
            $stmt->bind_param('ssssisi', $email, $username, $hash, $role, $isActive, $legacyStatus, $forcePasswordChange);
            $ok = $stmt->execute();
            $userId = (int) $conn->insert_id;
            $stmt->close();
            if (!$ok || $userId <= 0) return [false, 'User creation failed.', null];
        } else {
            // Fallback for older schemas without status/must_change_password.
            $stmtBasic = $conn->prepare(
                "INSERT INTO users (useremail, username, password, role, is_active)
                 VALUES (?, ?, ?, ?, ?)"
            );
            if (!$stmtBasic) return [false, 'User creation failed.', null];
            $stmtBasic->bind_param('ssssi', $email, $username, $hash, $role, $isActive);
            $ok = $stmtBasic->execute();
            $userId = (int) $conn->insert_id;
            $stmtBasic->close();
            if (!$ok || $userId <= 0) return [false, 'User creation failed.', null];

            if (admin_ai_msg_db_has_column($conn, 'users', 'status')) {
                $updStatus = $conn->prepare("UPDATE users SET status = ? WHERE id = ? LIMIT 1");
                if ($updStatus) {
                    $updStatus->bind_param('si', $legacyStatus, $userId);
                    $updStatus->execute();
                    $updStatus->close();
                }
            }
            if (admin_ai_msg_db_has_column($conn, 'users', 'must_change_password')) {
                $updMust = $conn->prepare("UPDATE users SET must_change_password = ? WHERE id = ? LIMIT 1");
                if ($updMust) {
                    $updMust->bind_param('ii', $forcePasswordChange, $userId);
                    $updMust->execute();
                    $updMust->close();
                }
            }
        }

        if (($firstName !== '' || $lastName !== '') && admin_ai_msg_db_has_column($conn, 'users', 'first_name') && admin_ai_msg_db_has_column($conn, 'users', 'last_name')) {
            $updNames = $conn->prepare("UPDATE users SET first_name = ?, last_name = ? WHERE id = ? LIMIT 1");
            if ($updNames) {
                $updNames->bind_param('ssi', $firstName, $lastName, $userId);
                $updNames->execute();
                $updNames->close();
            }
        }

        return [true, '', [
            'user_id' => $userId,
            'username' => $username,
            'user_email' => $email,
            'user_role' => $role,
            'is_active' => $isActive,
            'force_password_change' => $forcePasswordChange,
            'generated_password' => $generatedPassword,
            'initial_password' => $plainPassword,
        ]];
    }
}

if (!function_exists('admin_ai_msg_execute_action_plan')) {
    function admin_ai_msg_execute_action_plan(mysqli $conn, array $plan) {
        if (!function_exists('ai_access_can_use') || !ai_access_can_use()) {
            if (function_exists('ai_access_denied_message')) return [false, ai_access_denied_message()];
            return [false, 'AI access is not allowed for this account.'];
        }
        $role = isset($_SESSION['user_role']) ? normalize_role((string) $_SESSION['user_role']) : '';
        if ($role !== 'admin') return [false, 'Only Admin can execute AI action plans from Messages.'];

        if (!function_exists('ensure_reference_tables')) {
            require_once __DIR__ . '/reference.php';
        }
        if (function_exists('ensure_reference_tables')) ensure_reference_tables($conn);

        $safePlan = admin_ai_msg_sanitize_action_plan($plan);
        if (!is_array($safePlan)) return [false, 'Invalid action plan.'];
        $actionType = (string) ($safePlan['type'] ?? '');

        if ($actionType === 'create_class_section_and_assign_subjects') {
            $sectionCode = strtoupper(trim((string) ($safePlan['section_code'] ?? '')));
            if (!admin_ai_msg_if_code_valid($sectionCode)) {
                return [false, 'Section code is invalid. Use IF format (example: IF-2-B-6).'];
            }

            $subjectIdentifiers = is_array($safePlan['subject_identifiers'] ?? null) ? $safePlan['subject_identifiers'] : [];
            if (count($subjectIdentifiers) === 0) return [false, 'No subject identifiers in action plan.'];

            $resolvedSubjects = [];
            $unresolved = [];
            $seenSubjectIds = [];
            foreach ($subjectIdentifiers as $identifier) {
                [$okFind, $findMsg, $row] = admin_ai_msg_find_subject_by_identifier($conn, is_array($identifier) ? $identifier : []);
                if (!$okFind || !is_array($row)) {
                    $unresolved[] = $findMsg !== '' ? $findMsg : 'Unable to resolve one subject identifier.';
                    continue;
                }
                $sid = (int) ($row['id'] ?? 0);
                if ($sid <= 0 || isset($seenSubjectIds[$sid])) continue;
                $seenSubjectIds[$sid] = true;
                $resolvedSubjects[] = [
                    'id' => $sid,
                    'subject_code' => strtoupper(trim((string) ($row['subject_code'] ?? ''))),
                    'subject_name' => admin_ai_msg_clean_text((string) ($row['subject_name'] ?? ''), 120),
                ];
            }

            if (count($resolvedSubjects) === 0) {
                $detail = count($unresolved) > 0 ? (' ' . implode(' ', array_slice($unresolved, 0, 3))) : '';
                return [false, 'No subjects resolved from the provided identifiers.' . $detail];
            }

            $sectionDescription = trim((string) ($safePlan['section_description'] ?? ''));
            $sectionStatus = strtolower(trim((string) ($safePlan['section_status'] ?? 'active')));
            if (!in_array($sectionStatus, ['active', 'inactive'], true)) $sectionStatus = 'active';

            $sectionId = 0;
            $sectionCreated = false;
            $sectionUpdated = false;
            $assignedNew = 0;
            $assignedExisting = 0;

            try {
                $conn->begin_transaction();

                $findSection = $conn->prepare("SELECT id, description, status FROM class_sections WHERE UPPER(code) = UPPER(?) LIMIT 1");
                if (!$findSection) throw new Exception('Unable to prepare class section lookup.');
                $findSection->bind_param('s', $sectionCode);
                $findSection->execute();
                $res = $findSection->get_result();
                $existingSection = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
                $findSection->close();

                if (is_array($existingSection)) {
                    $sectionId = (int) ($existingSection['id'] ?? 0);
                    $prevDescription = trim((string) ($existingSection['description'] ?? ''));
                    $prevStatus = strtolower(trim((string) ($existingSection['status'] ?? 'active')));
                    if ($sectionId <= 0) throw new Exception('Existing class section id is invalid.');

                    $shouldUpdate = false;
                    if ($sectionDescription !== '' && $sectionDescription !== $prevDescription) $shouldUpdate = true;
                    if ($sectionStatus !== $prevStatus) $shouldUpdate = true;

                    if ($shouldUpdate) {
                        $newDescription = $sectionDescription !== '' ? $sectionDescription : $prevDescription;
                        $updateSection = $conn->prepare("UPDATE class_sections SET description = ?, status = ? WHERE id = ?");
                        if (!$updateSection) throw new Exception('Unable to prepare class section update.');
                        $updateSection->bind_param('ssi', $newDescription, $sectionStatus, $sectionId);
                        $okUpdate = $updateSection->execute();
                        $updateSection->close();
                        if (!$okUpdate) throw new Exception('Failed to update class section.');
                        $sectionUpdated = true;
                    }
                } else {
                    $insertSection = $conn->prepare(
                        "INSERT INTO class_sections (code, description, status, source)
                         VALUES (?, ?, ?, 'manual')"
                    );
                    if (!$insertSection) throw new Exception('Unable to prepare class section insert.');
                    $insertSection->bind_param('sss', $sectionCode, $sectionDescription, $sectionStatus);
                    $okInsertSection = $insertSection->execute();
                    $sectionId = (int) $conn->insert_id;
                    $insertSection->close();
                    if (!$okInsertSection || $sectionId <= 0) throw new Exception('Failed to create class section.');
                    $sectionCreated = true;
                }

                $assignStmt = $conn->prepare(
                    "INSERT IGNORE INTO class_section_subjects (class_section_id, subject_id)
                     VALUES (?, ?)"
                );
                if (!$assignStmt) throw new Exception('Unable to prepare subject assignment.');
                foreach ($resolvedSubjects as $subject) {
                    $subjectId = (int) ($subject['id'] ?? 0);
                    if ($subjectId <= 0) continue;
                    $assignStmt->bind_param('ii', $sectionId, $subjectId);
                    $okAssign = $assignStmt->execute();
                    if (!$okAssign) throw new Exception('Failed assigning one or more subjects.');
                    if ((int) $assignStmt->affected_rows > 0) $assignedNew++;
                    else $assignedExisting++;
                }
                $assignStmt->close();

                $conn->commit();
            } catch (Throwable $e) {
                try { $conn->rollback(); } catch (Throwable $ignored) { /* ignore */ }
                return [false, 'Execution failed: ' . $e->getMessage()];
            }

            return [true, [
                'action_type' => $actionType,
                'section_id' => $sectionId,
                'section_code' => $sectionCode,
                'section_created' => $sectionCreated,
                'section_updated' => $sectionUpdated,
                'assigned_new' => $assignedNew,
                'assigned_existing' => $assignedExisting,
                'resolved_subjects' => $resolvedSubjects,
                'unresolved_subjects' => array_values(array_unique($unresolved)),
            ]];
        }

        if ($actionType === 'create_accomplishment_for_user') {
            $targetIdentifier = is_array($safePlan['target_user_identifier'] ?? null)
                ? $safePlan['target_user_identifier']
                : ['type' => '', 'value' => ''];
            [$okTeacher, $teacherMsg, $teacherRow] = admin_ai_msg_find_teacher_by_identifier($conn, $targetIdentifier);
            if (!$okTeacher || !is_array($teacherRow)) {
                return [false, $teacherMsg !== '' ? $teacherMsg : 'Unable to resolve target teacher.'];
            }

            $targetUserId = (int) ($teacherRow['id'] ?? 0);
            if ($targetUserId <= 0) return [false, 'Resolved teacher id is invalid.'];

            $entryDate = admin_ai_msg_normalize_date((string) ($safePlan['entry_date'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate)) {
                return [false, 'Entry date must be YYYY-MM-DD.'];
            }

            $subjectLabel = admin_ai_msg_clean_text((string) ($safePlan['subject_label'] ?? ''), 255);
            $title = admin_ai_msg_clean_text((string) ($safePlan['title'] ?? ''), 255);
            $details = admin_ai_msg_clean_text((string) ($safePlan['details'] ?? ''), 5000);
            $remarks = admin_ai_msg_normalize_remarks_status((string) ($safePlan['remarks'] ?? 'Accomplished'));

            if ($subjectLabel === '') return [false, 'Subject label is required.'];
            if ($title === '') return [false, 'Title is required.'];
            if ($details === '') return [false, 'Details are required.'];

            if (!function_exists('ensure_accomplishment_tables') || !function_exists('acc_create_entry')) {
                require_once __DIR__ . '/accomplishments.php';
            }
            if (function_exists('ensure_accomplishment_tables')) ensure_accomplishment_tables($conn);
            if (!function_exists('acc_create_entry')) return [false, 'Accomplishment helper is unavailable.'];

            [$okCreate, $createRes] = acc_create_entry(
                $conn,
                $targetUserId,
                $entryDate,
                $title,
                $details,
                $subjectLabel,
                $remarks
            );
            if (!$okCreate) {
                return [false, is_string($createRes) ? $createRes : 'Unable to create accomplishment entry.'];
            }
            $entryId = (int) $createRes;

            $targetName = trim((string) ($teacherRow['first_name'] ?? '') . ' ' . (string) ($teacherRow['last_name'] ?? ''));
            if ($targetName === '') $targetName = trim((string) ($teacherRow['username'] ?? 'Teacher #' . $targetUserId));

            return [true, [
                'action_type' => $actionType,
                'target_user_id' => $targetUserId,
                'target_username' => (string) ($teacherRow['username'] ?? ''),
                'target_email' => (string) ($teacherRow['useremail'] ?? ''),
                'target_display_name' => $targetName,
                'entry_id' => $entryId,
                'entry_date' => $entryDate,
                'subject_label' => $subjectLabel,
                'title' => $title,
                'remarks' => $remarks,
            ]];
        }

        if ($actionType === 'list_accomplishments_for_user') {
            $targetIdentifier = is_array($safePlan['target_user_identifier'] ?? null)
                ? $safePlan['target_user_identifier']
                : ['type' => '', 'value' => ''];
            [$okTeacher, $teacherMsg, $teacherRow] = admin_ai_msg_find_teacher_by_identifier($conn, $targetIdentifier);
            if (!$okTeacher || !is_array($teacherRow)) {
                return [false, $teacherMsg !== '' ? $teacherMsg : 'Unable to resolve target teacher.'];
            }

            $targetUserId = (int) ($teacherRow['id'] ?? 0);
            if ($targetUserId <= 0) return [false, 'Resolved teacher id is invalid.'];

            $dateFrom = admin_ai_msg_normalize_date((string) ($safePlan['date_from'] ?? ''));
            $dateTo = admin_ai_msg_normalize_date((string) ($safePlan['date_to'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
                return [false, 'date_from and date_to are required in YYYY-MM-DD format.'];
            }
            if (strtotime($dateFrom) > strtotime($dateTo)) {
                return [false, 'date_from cannot be later than date_to.'];
            }
            $limit = admin_ai_msg_normalize_positive_int((int) ($safePlan['limit'] ?? 20), 20, 1, 100);

            if (!function_exists('ensure_accomplishment_tables')) {
                require_once __DIR__ . '/accomplishments.php';
            }
            if (function_exists('ensure_accomplishment_tables')) ensure_accomplishment_tables($conn);

            $targetName = trim((string) ($teacherRow['first_name'] ?? '') . ' ' . (string) ($teacherRow['last_name'] ?? ''));
            if ($targetName === '') $targetName = trim((string) ($teacherRow['username'] ?? 'Teacher #' . $targetUserId));

            $totalInRange = 0;
            $countStmt = $conn->prepare(
                "SELECT COUNT(*) AS c
                 FROM accomplishment_entries
                 WHERE user_id = ?
                   AND entry_date >= ?
                   AND entry_date <= ?"
            );
            if ($countStmt) {
                $countStmt->bind_param('iss', $targetUserId, $dateFrom, $dateTo);
                $countStmt->execute();
                $countRes = $countStmt->get_result();
                if ($countRes && $countRes->num_rows === 1) {
                    $totalInRange = (int) (($countRes->fetch_assoc()['c'] ?? 0));
                }
                $countStmt->close();
            }

            $items = [];
            $sql = "SELECT e.id, e.entry_date, e.subject_label, e.title, e.details, e.remarks, e.created_at,
                           (SELECT COUNT(*) FROM accomplishment_proofs p WHERE p.entry_id = e.id) AS proof_count
                    FROM accomplishment_entries e
                    WHERE e.user_id = ?
                      AND e.entry_date >= ?
                      AND e.entry_date <= ?
                    ORDER BY e.entry_date DESC, e.id DESC
                    LIMIT " . (int) $limit;
            $stmt = $conn->prepare($sql);
            if (!$stmt) return [false, 'Unable to prepare accomplishment query.'];
            $stmt->bind_param('iss', $targetUserId, $dateFrom, $dateTo);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                $items[] = [
                    'entry_id' => (int) ($row['id'] ?? 0),
                    'entry_date' => (string) ($row['entry_date'] ?? ''),
                    'subject_label' => admin_ai_msg_clean_text((string) ($row['subject_label'] ?? ''), 255),
                    'title' => admin_ai_msg_clean_text((string) ($row['title'] ?? ''), 255),
                    'details' => admin_ai_msg_clean_text((string) ($row['details'] ?? ''), 800),
                    'remarks' => admin_ai_msg_clean_text((string) ($row['remarks'] ?? ''), 160),
                    'proof_count' => (int) ($row['proof_count'] ?? 0),
                    'created_at' => (string) ($row['created_at'] ?? ''),
                ];
            }
            $stmt->close();

            return [true, [
                'action_type' => $actionType,
                'target_user_id' => $targetUserId,
                'target_username' => (string) ($teacherRow['username'] ?? ''),
                'target_email' => (string) ($teacherRow['useremail'] ?? ''),
                'target_display_name' => $targetName,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'total_in_range' => $totalInRange,
                'returned' => count($items),
                'items' => $items,
            ]];
        }

        if ($actionType === 'count_enrolled_students') {
            $filters = [];
            $where = [
                "ce.status = 'enrolled'",
                "cr.status = 'active'",
            ];
            $types = '';
            $params = [];

            $classRecordId = (int) ($safePlan['class_record_id'] ?? 0);
            if ($classRecordId > 0) {
                $where[] = 'ce.class_record_id = ?';
                $types .= 'i';
                $params[] = $classRecordId;
                $filters['class_record_id'] = $classRecordId;
            }

            $academicYear = admin_ai_msg_clean_text((string) ($safePlan['academic_year'] ?? ''), 32);
            if ($academicYear !== '') {
                $where[] = 'cr.academic_year = ?';
                $types .= 's';
                $params[] = $academicYear;
                $filters['academic_year'] = $academicYear;
            }

            $semester = admin_ai_msg_clean_text((string) ($safePlan['semester'] ?? ''), 32);
            if ($semester !== '') {
                $where[] = 'cr.semester = ?';
                $types .= 's';
                $params[] = $semester;
                $filters['semester'] = $semester;
            }

            $sectionCode = strtoupper(admin_ai_msg_clean_text((string) ($safePlan['section_code'] ?? ''), 60));
            if ($sectionCode !== '') {
                $where[] = 'UPPER(cr.section) = UPPER(?)';
                $types .= 's';
                $params[] = $sectionCode;
                $filters['section_code'] = $sectionCode;
            }

            $subjectFilter = null;
            $subjectIdentifier = $safePlan['subject_identifier'] ?? null;
            if (is_array($subjectIdentifier)) {
                [$okSub, $subMsg, $subRow] = admin_ai_msg_find_subject_by_identifier($conn, $subjectIdentifier);
                if (!$okSub || !is_array($subRow)) {
                    return [false, $subMsg !== '' ? $subMsg : 'Unable to resolve subject filter.'];
                }
                $subjectId = (int) ($subRow['id'] ?? 0);
                if ($subjectId <= 0) return [false, 'Resolved subject id is invalid.'];
                $where[] = 'cr.subject_id = ?';
                $types .= 'i';
                $params[] = $subjectId;
                $subjectFilter = [
                    'id' => $subjectId,
                    'subject_code' => strtoupper(trim((string) ($subRow['subject_code'] ?? ''))),
                    'subject_name' => admin_ai_msg_clean_text((string) ($subRow['subject_name'] ?? ''), 120),
                ];
            }

            $sql =
                "SELECT COUNT(DISTINCT ce.student_id) AS enrolled_students,
                        COUNT(*) AS enrollment_rows
                 FROM class_enrollments ce
                 JOIN class_records cr ON cr.id = ce.class_record_id
                 WHERE " . implode(' AND ', $where);
            $stmt = $conn->prepare($sql);
            if (!$stmt) return [false, 'Unable to prepare enrollment count query.'];

            if ($types !== '') {
                $bindValues = [];
                foreach ($params as $idx => $val) $bindValues[$idx] = $val;
                $bindArgs = [$types];
                foreach ($bindValues as $idx => &$v) $bindArgs[] = &$v;
                call_user_func_array([$stmt, 'bind_param'], $bindArgs);
            }

            $stmt->execute();
            $res = $stmt->get_result();
            $row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : [];
            $stmt->close();

            return [true, [
                'action_type' => $actionType,
                'enrolled_students' => (int) ($row['enrolled_students'] ?? 0),
                'enrollment_rows' => (int) ($row['enrollment_rows'] ?? 0),
                'filters' => $filters,
                'subject_filter' => $subjectFilter,
            ]];
        }

        if ($actionType === 'create_user_account') {
            [$okCreate, $createMsg, $createMeta] = admin_ai_msg_create_user_account_record($conn, $safePlan);
            if (!$okCreate || !is_array($createMeta)) {
                return [false, $createMsg !== '' ? $createMsg : 'Unable to create user account.'];
            }
            return [true, [
                'action_type' => $actionType,
                'user_id' => (int) ($createMeta['user_id'] ?? 0),
                'username' => (string) ($createMeta['username'] ?? ''),
                'user_email' => (string) ($createMeta['user_email'] ?? ''),
                'user_role' => (string) ($createMeta['user_role'] ?? ''),
                'is_active' => (int) ($createMeta['is_active'] ?? 0),
                'force_password_change' => (int) ($createMeta['force_password_change'] ?? 0),
                'generated_password' => !empty($createMeta['generated_password']),
                'initial_password' => (string) ($createMeta['initial_password'] ?? ''),
            ]];
        }

        if ($actionType === 'create_student_user_and_profile') {
            $studentNo = admin_ai_msg_normalize_student_no((string) ($safePlan['student_no'] ?? ''));
            $surname = admin_ai_msg_clean_text((string) ($safePlan['surname'] ?? ''), 80);
            $firstName = admin_ai_msg_clean_text((string) ($safePlan['first_name'] ?? ''), 80);
            $middleName = admin_ai_msg_clean_text((string) ($safePlan['middle_name'] ?? ''), 80);
            $sex = admin_ai_msg_normalize_student_sex((string) ($safePlan['sex'] ?? 'M'));
            $course = admin_ai_msg_clean_text((string) ($safePlan['course'] ?? ''), 100);
            $major = admin_ai_msg_clean_text((string) ($safePlan['major'] ?? ''), 100);
            $yearLevel = admin_ai_msg_clean_text((string) ($safePlan['year_level'] ?? ''), 20);
            $section = admin_ai_msg_clean_text((string) ($safePlan['section'] ?? ''), 20);
            $studentStatus = admin_ai_msg_normalize_student_status((string) ($safePlan['student_status'] ?? 'Active'));
            $studentEmail = admin_ai_msg_normalize_email((string) ($safePlan['student_email'] ?? ''), 255);

            if ($studentNo === '') return [false, 'Student number is required.'];
            if ($surname === '') return [false, 'Student surname is required.'];
            if ($firstName === '') return [false, 'Student first name is required.'];
            if ($course === '') return [false, 'Student course/program is required.'];
            if ($yearLevel === '') return [false, 'Student year level is required.'];
            if ($section === '') return [false, 'Student section is required.'];
            if ($studentEmail !== '' && !filter_var($studentEmail, FILTER_VALIDATE_EMAIL)) {
                return [false, 'Student email is invalid.'];
            }

            $targetIdentifier = is_array($safePlan['target_user_identifier'] ?? null)
                ? $safePlan['target_user_identifier']
                : ['type' => '', 'value' => ''];
            $hasTargetIdentifier = trim((string) ($targetIdentifier['value'] ?? '')) !== '';

            $createUserIfMissing = !empty($safePlan['create_user_if_missing']);
            $userEmail = admin_ai_msg_normalize_email((string) ($safePlan['user_email'] ?? ''), 255);
            if ($userEmail === '' && $studentEmail !== '') $userEmail = $studentEmail;
            $username = admin_ai_msg_clean_text((string) ($safePlan['username'] ?? ''), 100);
            if ($username === '') $username = $studentNo;

            $userId = 0;
            $userCreated = false;
            $userCreatedMeta = null;
            $studentProfileId = 0;
            $studentProfileCreated = false;
            $studentProfileUpdated = false;

            try {
                $conn->begin_transaction();

                if ($hasTargetIdentifier) {
                    [$okUser, $userMsg, $userRow] = admin_ai_msg_find_user_by_identifier($conn, $targetIdentifier, true);
                    if (!$okUser || !is_array($userRow)) {
                        throw new RuntimeException($userMsg !== '' ? $userMsg : 'Unable to resolve target user.');
                    }
                    $resolvedRole = normalize_role((string) ($userRow['role'] ?? ''));
                    if (!in_array($resolvedRole, ['student', 'user', ''], true)) {
                        throw new RuntimeException('Target user is not a student account. Use a student/user account or let AI create one.');
                    }
                    $userId = (int) ($userRow['id'] ?? 0);
                } else {
                    if (!$createUserIfMissing) {
                        throw new RuntimeException('No target user provided and create_user_if_missing is disabled.');
                    }
                    if (!filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
                        throw new RuntimeException('A valid user email is required to create the linked account.');
                    }

                    [$okCreate, $createMsg, $createMeta] = admin_ai_msg_create_user_account_record($conn, [
                        'user_role' => 'student',
                        'user_email' => $userEmail,
                        'username' => $username !== '' ? $username : $studentNo,
                        'initial_password' => (string) ($safePlan['initial_password'] ?? ''),
                        'is_active' => !empty($safePlan['is_active']) ? 1 : 0,
                        'force_password_change' => !empty($safePlan['force_password_change']) ? 1 : 0,
                        'first_name' => $firstName,
                        'last_name' => $surname,
                    ]);
                    if (!$okCreate || !is_array($createMeta)) {
                        throw new RuntimeException($createMsg !== '' ? $createMsg : 'Unable to create linked student account.');
                    }
                    $userId = (int) ($createMeta['user_id'] ?? 0);
                    $userCreated = true;
                    $userCreatedMeta = $createMeta;
                }

                if ($userId <= 0) throw new RuntimeException('Linked user id is invalid.');

                $findStudent = $conn->prepare("SELECT id, user_id FROM students WHERE StudentNo = ? LIMIT 1");
                if (!$findStudent) throw new RuntimeException('Unable to prepare student profile lookup.');
                $findStudent->bind_param('s', $studentNo);
                $findStudent->execute();
                $studentRes = $findStudent->get_result();
                $existingStudent = ($studentRes && $studentRes->num_rows === 1) ? $studentRes->fetch_assoc() : null;
                $findStudent->close();

                $profileEmail = $studentEmail !== '' ? $studentEmail : $userEmail;
                if (is_array($existingStudent)) {
                    $studentProfileId = (int) ($existingStudent['id'] ?? 0);
                    $existingUserId = (int) ($existingStudent['user_id'] ?? 0);
                    if ($existingUserId > 0 && $existingUserId !== $userId) {
                        throw new RuntimeException('Student profile already linked to another user account.');
                    }

                    $updStudent = $conn->prepare(
                        "UPDATE students
                         SET user_id = ?, Surname = ?, FirstName = ?, MiddleName = ?, Sex = ?, Course = ?, Major = ?,
                             Status = ?, Year = ?, Section = ?, email = ?
                         WHERE id = ?
                         LIMIT 1"
                    );
                    if (!$updStudent) throw new RuntimeException('Unable to prepare student profile update.');
                    $updStudent->bind_param(
                        'issssssssssi',
                        $userId,
                        $surname,
                        $firstName,
                        $middleName,
                        $sex,
                        $course,
                        $major,
                        $studentStatus,
                        $yearLevel,
                        $section,
                        $profileEmail,
                        $studentProfileId
                    );
                    $okUpdStudent = $updStudent->execute();
                    $updStudent->close();
                    if (!$okUpdStudent) throw new RuntimeException('Failed to update student profile.');
                    $studentProfileUpdated = true;
                } else {
                    $createdBy = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
                    if ($createdBy <= 0) $createdBy = 1;

                    $insStudent = $conn->prepare(
                        "INSERT INTO students
                            (user_id, StudentNo, Surname, FirstName, MiddleName, Sex, Course, Major, Status, Year, Section, email, created_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                    );
                    if (!$insStudent) throw new RuntimeException('Unable to prepare student profile insert.');
                    $insStudent->bind_param(
                        'isssssssssssi',
                        $userId,
                        $studentNo,
                        $surname,
                        $firstName,
                        $middleName,
                        $sex,
                        $course,
                        $major,
                        $studentStatus,
                        $yearLevel,
                        $section,
                        $profileEmail,
                        $createdBy
                    );
                    $okInsStudent = $insStudent->execute();
                    $studentProfileId = (int) $conn->insert_id;
                    $insStudent->close();
                    if (!$okInsStudent || $studentProfileId <= 0) throw new RuntimeException('Failed to create student profile.');
                    $studentProfileCreated = true;
                }

                if (admin_ai_msg_db_has_column($conn, 'users', 'first_name') && admin_ai_msg_db_has_column($conn, 'users', 'last_name')) {
                    $updUser = $conn->prepare(
                        "UPDATE users
                         SET first_name = ?, last_name = ?,
                             role = CASE WHEN role = 'user' THEN 'student' ELSE role END
                         WHERE id = ?
                         LIMIT 1"
                    );
                    if ($updUser) {
                        $updUser->bind_param('ssi', $firstName, $surname, $userId);
                        $updUser->execute();
                        $updUser->close();
                    }
                } else {
                    $updRole = $conn->prepare(
                        "UPDATE users
                         SET role = CASE WHEN role = 'user' THEN 'student' ELSE role END
                         WHERE id = ?
                         LIMIT 1"
                    );
                    if ($updRole) {
                        $updRole->bind_param('i', $userId);
                        $updRole->execute();
                        $updRole->close();
                    }
                }

                $conn->commit();
            } catch (Throwable $e) {
                try { $conn->rollback(); } catch (Throwable $ignored) { /* ignore */ }
                return [false, 'Execution failed: ' . $e->getMessage()];
            }

            return [true, [
                'action_type' => $actionType,
                'student_profile_id' => $studentProfileId,
                'student_no' => $studentNo,
                'student_name' => trim($surname . ', ' . $firstName . ($middleName !== '' ? (' ' . $middleName) : '')),
                'user_id' => $userId,
                'user_created' => $userCreated,
                'student_profile_created' => $studentProfileCreated,
                'student_profile_updated' => $studentProfileUpdated,
                'generated_password' => is_array($userCreatedMeta) ? !empty($userCreatedMeta['generated_password']) : false,
                'initial_password' => is_array($userCreatedMeta) ? (string) ($userCreatedMeta['initial_password'] ?? '') : '',
            ]];
        }

        return [false, 'Unsupported action type.'];
    }
}

if (!function_exists('admin_ai_msg_plan_preview_text')) {
    function admin_ai_msg_plan_preview_text(array $plan) {
        $safePlan = admin_ai_msg_sanitize_action_plan($plan);
        if (!is_array($safePlan)) return 'No valid action plan.';

        $actionType = (string) ($safePlan['type'] ?? '');
        $lines = [];

        if ($actionType === 'create_class_section_and_assign_subjects') {
            $subjects = [];
            foreach ((array) ($safePlan['subject_identifiers'] ?? []) as $s) {
                if (!is_array($s)) continue;
                $type = strtolower(trim((string) ($s['type'] ?? '')));
                $value = trim((string) ($s['value'] ?? ''));
                if ($value === '') continue;
                $subjects[] = strtoupper($type) . ': ' . $value;
            }
            if (count($subjects) === 0) $subjects[] = '(none)';

            $lines[] = 'Type: create_class_section_and_assign_subjects';
            $lines[] = 'Section Code: ' . (string) ($safePlan['section_code'] ?? '');
            $lines[] = 'Section Status: ' . (string) ($safePlan['section_status'] ?? 'active');
            if (trim((string) ($safePlan['section_description'] ?? '')) !== '') {
                $lines[] = 'Section Description: ' . (string) ($safePlan['section_description'] ?? '');
            }
            $lines[] = 'Subjects:';
            foreach ($subjects as $subjectLine) {
                $lines[] = '- ' . $subjectLine;
            }
            return implode("\n", $lines);
        }

        if ($actionType === 'create_accomplishment_for_user') {
            $target = is_array($safePlan['target_user_identifier'] ?? null) ? $safePlan['target_user_identifier'] : ['type' => '', 'value' => ''];
            $lines[] = 'Type: create_accomplishment_for_user';
            $lines[] = 'Target Teacher: ' . strtoupper(trim((string) ($target['type'] ?? ''))) . ' ' . trim((string) ($target['value'] ?? ''));
            $lines[] = 'Entry Date: ' . (string) ($safePlan['entry_date'] ?? '');
            $lines[] = 'Subject: ' . (string) ($safePlan['subject_label'] ?? '');
            $lines[] = 'Title: ' . (string) ($safePlan['title'] ?? '');
            $lines[] = 'Remarks: ' . (string) ($safePlan['remarks'] ?? 'Accomplished');
            $lines[] = 'Details:';
            $lines[] = (string) ($safePlan['details'] ?? '');
            return implode("\n", $lines);
        }

        if ($actionType === 'list_accomplishments_for_user') {
            $target = is_array($safePlan['target_user_identifier'] ?? null) ? $safePlan['target_user_identifier'] : ['type' => '', 'value' => ''];
            $lines[] = 'Type: list_accomplishments_for_user';
            $lines[] = 'Target Teacher: ' . strtoupper(trim((string) ($target['type'] ?? ''))) . ' ' . trim((string) ($target['value'] ?? ''));
            $lines[] = 'Date From: ' . (string) ($safePlan['date_from'] ?? '');
            $lines[] = 'Date To: ' . (string) ($safePlan['date_to'] ?? '');
            $lines[] = 'Limit: ' . (int) ($safePlan['limit'] ?? 20);
            return implode("\n", $lines);
        }

        if ($actionType === 'count_enrolled_students') {
            $lines[] = 'Type: count_enrolled_students';
            $classRecordId = (int) ($safePlan['class_record_id'] ?? 0);
            if ($classRecordId > 0) $lines[] = 'Class Record ID: ' . $classRecordId;
            if (trim((string) ($safePlan['academic_year'] ?? '')) !== '') {
                $lines[] = 'Academic Year: ' . (string) ($safePlan['academic_year'] ?? '');
            }
            if (trim((string) ($safePlan['semester'] ?? '')) !== '') {
                $lines[] = 'Semester: ' . (string) ($safePlan['semester'] ?? '');
            }
            if (trim((string) ($safePlan['section_code'] ?? '')) !== '') {
                $lines[] = 'Section: ' . (string) ($safePlan['section_code'] ?? '');
            }
            $subjectIdentifier = $safePlan['subject_identifier'] ?? null;
            if (is_array($subjectIdentifier)) {
                $lines[] = 'Subject Filter: ' .
                    strtoupper(trim((string) ($subjectIdentifier['type'] ?? ''))) . ' ' .
                    trim((string) ($subjectIdentifier['value'] ?? ''));
            }
            if (count($lines) === 1) {
                $lines[] = 'Scope: all active class enrollments (status=enrolled)';
            }
            return implode("\n", $lines);
        }

        if ($actionType === 'create_user_account') {
            $lines[] = 'Type: create_user_account';
            $lines[] = 'Role: ' . (string) ($safePlan['user_role'] ?? 'student');
            $lines[] = 'Email: ' . (string) ($safePlan['user_email'] ?? '');
            $lines[] = 'Username: ' . (string) ($safePlan['username'] ?? '');
            $lines[] = 'Active: ' . (!empty($safePlan['is_active']) ? 'Yes' : 'No');
            $lines[] = 'Force Password Change: ' . (!empty($safePlan['force_password_change']) ? 'Yes' : 'No');
            if (trim((string) ($safePlan['initial_password'] ?? '')) !== '') {
                $lines[] = 'Initial Password: [provided]';
            } else {
                $lines[] = 'Initial Password: [auto-generate]';
            }
            return implode("\n", $lines);
        }

        if ($actionType === 'create_student_user_and_profile') {
            $target = is_array($safePlan['target_user_identifier'] ?? null) ? $safePlan['target_user_identifier'] : ['type' => '', 'value' => ''];
            $lines[] = 'Type: create_student_user_and_profile';
            $lines[] = 'Student No: ' . (string) ($safePlan['student_no'] ?? '');
            $lines[] = 'Name: ' . trim((string) ($safePlan['surname'] ?? '') . ', ' . (string) ($safePlan['first_name'] ?? '') . ' ' . (string) ($safePlan['middle_name'] ?? ''));
            $lines[] = 'Course/Year/Section: ' . (string) ($safePlan['course'] ?? '') . ' / ' . (string) ($safePlan['year_level'] ?? '') . ' / ' . (string) ($safePlan['section'] ?? '');
            $lines[] = 'Student Email: ' . (string) ($safePlan['student_email'] ?? '');
            if (trim((string) ($target['value'] ?? '')) !== '') {
                $lines[] = 'Target User: ' . strtoupper(trim((string) ($target['type'] ?? ''))) . ' ' . trim((string) ($target['value'] ?? ''));
            } else {
                $lines[] = 'Create User If Missing: ' . (!empty($safePlan['create_user_if_missing']) ? 'Yes' : 'No');
                $lines[] = 'Linked User Email: ' . (string) ($safePlan['user_email'] ?? '');
                $lines[] = 'Linked Username: ' . (string) ($safePlan['username'] ?? '');
            }
            return implode("\n", $lines);
        }

        return 'No valid action plan.';
    }
}

if (!function_exists('admin_ai_msg_execution_summary_text')) {
    function admin_ai_msg_execution_summary_text(array $result) {
        $actionType = trim((string) ($result['action_type'] ?? ''));
        if ($actionType === 'create_accomplishment_for_user') {
            $lines = [];
            $lines[] = 'Execution completed.';
            $lines[] = 'Action: create_accomplishment_for_user';
            $lines[] = 'Target: ' . trim((string) ($result['target_display_name'] ?? 'Teacher'));
            $lines[] = 'Entry ID: ' . (int) ($result['entry_id'] ?? 0);
            $lines[] = 'Date: ' . trim((string) ($result['entry_date'] ?? ''));
            $lines[] = 'Subject: ' . trim((string) ($result['subject_label'] ?? ''));
            $lines[] = 'Title: ' . trim((string) ($result['title'] ?? ''));
            $lines[] = 'Remarks: ' . trim((string) ($result['remarks'] ?? ''));
            return implode("\n", $lines);
        }

        if ($actionType === 'list_accomplishments_for_user') {
            $lines = [];
            $lines[] = 'Query completed.';
            $lines[] = 'Action: list_accomplishments_for_user';
            $lines[] = 'Target: ' . trim((string) ($result['target_display_name'] ?? 'Teacher'));
            $lines[] = 'Range: ' . trim((string) ($result['date_from'] ?? '')) . ' to ' . trim((string) ($result['date_to'] ?? ''));
            $lines[] = 'Total in range: ' . (int) ($result['total_in_range'] ?? 0);
            $lines[] = 'Returned: ' . (int) ($result['returned'] ?? 0);

            $items = is_array($result['items'] ?? null) ? $result['items'] : [];
            if (count($items) === 0) {
                $lines[] = 'No accomplishment entries found in this range.';
                return implode("\n", $lines);
            }

            $lines[] = 'Entries:';
            $count = 0;
            foreach ($items as $item) {
                if (!is_array($item)) continue;
                $count++;
                if ($count > 8) break;
                $entryId = (int) ($item['entry_id'] ?? 0);
                $entryDate = trim((string) ($item['entry_date'] ?? ''));
                $subject = trim((string) ($item['subject_label'] ?? ''));
                $title = trim((string) ($item['title'] ?? ''));
                $remarks = trim((string) ($item['remarks'] ?? ''));
                $proofCount = (int) ($item['proof_count'] ?? 0);
                $details = admin_ai_msg_clean_text((string) ($item['details'] ?? ''), 120);
                $lines[] = '- #' . $entryId . ' | ' . $entryDate . ' | ' . $subject . ' | ' . $title . ' | ' . $remarks . ' | proofs: ' . $proofCount;
                if ($details !== '') $lines[] = '  ' . $details;
            }
            if (count($items) > 8) {
                $lines[] = '... and ' . (count($items) - 8) . ' more result(s).';
            }
            return implode("\n", $lines);
        }

        if ($actionType === 'count_enrolled_students') {
            $lines = [];
            $lines[] = 'Query completed.';
            $lines[] = 'Action: count_enrolled_students';
            $lines[] = 'Distinct enrolled students: ' . (int) ($result['enrolled_students'] ?? 0);
            $lines[] = 'Enrollment rows matched: ' . (int) ($result['enrollment_rows'] ?? 0);

            $filters = is_array($result['filters'] ?? null) ? $result['filters'] : [];
            $subjectFilter = is_array($result['subject_filter'] ?? null) ? $result['subject_filter'] : null;
            if (count($filters) > 0 || is_array($subjectFilter)) {
                $lines[] = 'Filters:';
                foreach ($filters as $k => $v) {
                    $lines[] = '- ' . admin_ai_msg_clean_text((string) $k, 40) . ': ' . admin_ai_msg_clean_text((string) $v, 80);
                }
                if (is_array($subjectFilter)) {
                    $lines[] = '- subject: ' .
                        admin_ai_msg_clean_text((string) ($subjectFilter['subject_code'] ?? ''), 50) .
                        ' ' .
                        admin_ai_msg_clean_text((string) ($subjectFilter['subject_name'] ?? ''), 120);
                }
            } else {
                $lines[] = 'Filters: none (all active class enrollments).';
            }
            return implode("\n", $lines);
        }

        if ($actionType === 'create_user_account') {
            $lines = [];
            $lines[] = 'Execution completed.';
            $lines[] = 'Action: create_user_account';
            $lines[] = 'User ID: ' . (int) ($result['user_id'] ?? 0);
            $lines[] = 'Role: ' . trim((string) ($result['user_role'] ?? ''));
            $lines[] = 'Username: ' . trim((string) ($result['username'] ?? ''));
            $lines[] = 'Email: ' . trim((string) ($result['user_email'] ?? ''));
            $lines[] = 'Active: ' . (!empty($result['is_active']) ? 'Yes' : 'No');
            $lines[] = 'Force Password Change: ' . (!empty($result['force_password_change']) ? 'Yes' : 'No');
            if (!empty($result['generated_password'])) {
                $lines[] = 'Generated Initial Password: ' . trim((string) ($result['initial_password'] ?? ''));
                $lines[] = 'Important: provide this securely to the user and change/reset after first login if needed.';
            }
            return implode("\n", $lines);
        }

        if ($actionType === 'create_student_user_and_profile') {
            $lines = [];
            $lines[] = 'Execution completed.';
            $lines[] = 'Action: create_student_user_and_profile';
            $lines[] = 'Student Profile ID: ' . (int) ($result['student_profile_id'] ?? 0);
            $lines[] = 'Student No: ' . trim((string) ($result['student_no'] ?? ''));
            $lines[] = 'Student Name: ' . trim((string) ($result['student_name'] ?? ''));
            $lines[] = 'Linked User ID: ' . (int) ($result['user_id'] ?? 0);
            $lines[] = 'User Created: ' . (!empty($result['user_created']) ? 'Yes' : 'No');
            $lines[] = 'Profile Created: ' . (!empty($result['student_profile_created']) ? 'Yes' : 'No');
            $lines[] = 'Profile Updated: ' . (!empty($result['student_profile_updated']) ? 'Yes' : 'No');
            if (!empty($result['generated_password'])) {
                $lines[] = 'Generated Initial Password: ' . trim((string) ($result['initial_password'] ?? ''));
                $lines[] = 'Important: provide this securely to the student.';
            }
            return implode("\n", $lines);
        }

        $sectionCode = trim((string) ($result['section_code'] ?? ''));
        $assignedNew = (int) ($result['assigned_new'] ?? 0);
        $assignedExisting = (int) ($result['assigned_existing'] ?? 0);
        $created = !empty($result['section_created']);
        $updated = !empty($result['section_updated']);

        $lines = [];
        $lines[] = 'Execution completed.';
        $lines[] = 'Section: ' . ($sectionCode !== '' ? $sectionCode : 'N/A');
        $lines[] = 'Section created: ' . ($created ? 'Yes' : 'No');
        $lines[] = 'Section updated: ' . ($updated ? 'Yes' : 'No');
        $lines[] = 'Subjects newly assigned: ' . $assignedNew;
        $lines[] = 'Subjects already assigned: ' . $assignedExisting;

        $unresolved = is_array($result['unresolved_subjects'] ?? null) ? $result['unresolved_subjects'] : [];
        if (count($unresolved) > 0) {
            $lines[] = 'Unresolved:';
            foreach (array_slice($unresolved, 0, 6) as $item) {
                $item = admin_ai_msg_clean_text((string) $item, 180);
                if ($item !== '') $lines[] = '- ' . $item;
            }
        }
        return implode("\n", $lines);
    }
}
