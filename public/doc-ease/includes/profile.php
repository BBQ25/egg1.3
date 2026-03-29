<?php
// Profile helpers: editable profile fields via admin-approved change requests.

if (!function_exists('ensure_profile_tables')) {
    function ensure_profile_tables(mysqli $conn) {
        $conn->query(
            "CREATE TABLE IF NOT EXISTS user_profiles (
                user_id INT NOT NULL PRIMARY KEY,
                bio TEXT NULL,
                phone VARCHAR(50) NULL,
                location VARCHAR(120) NULL,
                github_username VARCHAR(80) NULL,
                program_chair_user_id INT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_user_profiles_user
                    FOREIGN KEY (user_id) REFERENCES users(id)
                    ON DELETE CASCADE,
                KEY idx_user_profiles_program_chair (program_chair_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $res = $conn->query("SHOW COLUMNS FROM user_profiles LIKE 'program_chair_user_id'");
        $hasProgramChairColumn = ($res instanceof mysqli_result) ? ($res->num_rows > 0) : false;
        if ($res instanceof mysqli_result) $res->close();
        if (!$hasProgramChairColumn) {
            $conn->query("ALTER TABLE user_profiles ADD COLUMN program_chair_user_id INT NULL AFTER github_username");
        }

        $res = $conn->query("SHOW INDEX FROM user_profiles WHERE Key_name='idx_user_profiles_program_chair'");
        $hasProgramChairIndex = ($res instanceof mysqli_result) ? ($res->num_rows > 0) : false;
        if ($res instanceof mysqli_result) $res->close();
        if (!$hasProgramChairIndex) {
            $conn->query("ALTER TABLE user_profiles ADD KEY idx_user_profiles_program_chair (program_chair_user_id)");
        }

        $dbName = '';
        $dbRes = $conn->query("SELECT DATABASE() AS db_name");
        if ($dbRes instanceof mysqli_result) {
            $row = $dbRes->fetch_assoc();
            $dbName = trim((string) ($row['db_name'] ?? ''));
            $dbRes->close();
        }
        if ($dbName !== '') {
            $dbEsc = $conn->real_escape_string($dbName);
            $fkRes = $conn->query(
                "SELECT 1
                 FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = '{$dbEsc}'
                   AND TABLE_NAME = 'user_profiles'
                   AND CONSTRAINT_NAME = 'fk_user_profiles_program_chair'
                 LIMIT 1"
            );
            $hasProgramChairFk = ($fkRes instanceof mysqli_result) ? ($fkRes->num_rows > 0) : false;
            if ($fkRes instanceof mysqli_result) $fkRes->close();
            if (!$hasProgramChairFk) {
                // Keep this best-effort in case some environments still have legacy inconsistent types.
                @$conn->query(
                    "ALTER TABLE user_profiles
                     ADD CONSTRAINT fk_user_profiles_program_chair
                     FOREIGN KEY (program_chair_user_id) REFERENCES users(id)
                     ON DELETE SET NULL"
                );
            }
        }

        $conn->query(
            "CREATE TABLE IF NOT EXISTS teacher_subject_program_chairs (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                teacher_user_id INT NOT NULL,
                subject_label VARCHAR(255) NOT NULL,
                program_chair_user_id INT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_tspc_teacher_subject (teacher_user_id, subject_label),
                KEY idx_tspc_teacher (teacher_user_id),
                KEY idx_tspc_program_chair (program_chair_user_id),
                CONSTRAINT fk_tspc_teacher
                    FOREIGN KEY (teacher_user_id) REFERENCES users(id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_tspc_program_chair
                    FOREIGN KEY (program_chair_user_id) REFERENCES users(id)
                    ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $conn->query(
            "CREATE TABLE IF NOT EXISTS user_profile_change_requests (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
                payload_json TEXT NOT NULL,
                staged_avatar_path VARCHAR(255) NULL,
                review_note TEXT NULL,
                requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                reviewed_at TIMESTAMP NULL DEFAULT NULL,
                reviewed_by INT NULL DEFAULT NULL,
                KEY idx_upcr_user_status (user_id, status),
                KEY idx_upcr_status_time (status, requested_at),
                CONSTRAINT fk_upcr_user
                    FOREIGN KEY (user_id) REFERENCES users(id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_upcr_reviewed_by
                    FOREIGN KEY (reviewed_by) REFERENCES users(id)
                    ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
}

if (!function_exists('profile_load')) {
    function profile_load(mysqli $conn, $userId) {
        $userId = (int) $userId;
        $user = null;
        $stmt = $conn->prepare(
            "SELECT id, username, useremail, role, is_active,
                    first_name, last_name, profile_picture, created_at, updated_at
             FROM users
             WHERE id = ?
             LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows === 1) $user = $res->fetch_assoc();
            $stmt->close();
        }
        if (!$user) return null;

        // Optional extended profile.
        $profile = [
            'bio' => '',
            'phone' => '',
            'location' => '',
            'github_username' => '',
            'program_chair_user_id' => 0,
            'program_chair_display_name' => '',
            'program_chair_email' => '',
        ];
        $p = $conn->prepare(
            "SELECT up.bio, up.phone, up.location, up.github_username, up.program_chair_user_id,
                    pc.username AS program_chair_username,
                    pc.useremail AS program_chair_useremail,
                    pc.first_name AS program_chair_first_name,
                    pc.last_name AS program_chair_last_name
             FROM user_profiles up
             LEFT JOIN users pc ON pc.id = up.program_chair_user_id
             WHERE up.user_id = ?
             LIMIT 1"
        );
        if ($p) {
            $p->bind_param('i', $userId);
            $p->execute();
            $res = $p->get_result();
            if ($res && $res->num_rows === 1) {
                $row = $res->fetch_assoc();
                foreach (['bio', 'phone', 'location', 'github_username'] as $k) {
                    if (isset($row[$k])) $profile[$k] = (string) ($row[$k] ?? '');
                }

                $programChairUserId = isset($row['program_chair_user_id']) ? (int) $row['program_chair_user_id'] : 0;
                if ($programChairUserId > 0) {
                    $profile['program_chair_user_id'] = $programChairUserId;
                    $profile['program_chair_email'] = trim((string) ($row['program_chair_useremail'] ?? ''));
                    $pcFn = trim((string) ($row['program_chair_first_name'] ?? ''));
                    $pcLn = trim((string) ($row['program_chair_last_name'] ?? ''));
                    $pcUsername = trim((string) ($row['program_chair_username'] ?? ''));
                    $pcName = trim($pcFn . ' ' . $pcLn);
                    if ($pcName === '') $pcName = $pcUsername;
                    $profile['program_chair_display_name'] = $pcName;
                }
            }
            $p->close();
        }

        return array_merge($user, $profile);
    }
}

if (!function_exists('profile_full_name')) {
    function profile_full_name(array $u) {
        $fn = trim((string) ($u['first_name'] ?? ''));
        $ln = trim((string) ($u['last_name'] ?? ''));
        $full = trim($fn . ' ' . $ln);
        if ($full !== '') return $full;
        return trim((string) ($u['username'] ?? ''));
    }
}

if (!function_exists('profile_program_chair_options')) {
    function profile_program_chair_options(mysqli $conn) {
        $out = [];
        $stmt = $conn->prepare(
            "SELECT id, username, useremail, first_name, last_name
             FROM users
             WHERE role = 'program_chair' AND is_active = 1
             ORDER BY first_name ASC, last_name ASC, username ASC"
        );
        if (!$stmt) return $out;

        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) continue;
            $fn = trim((string) ($row['first_name'] ?? ''));
            $ln = trim((string) ($row['last_name'] ?? ''));
            $username = trim((string) ($row['username'] ?? ''));
            $display = trim($fn . ' ' . $ln);
            if ($display === '') $display = $username;
            $out[] = [
                'id' => $id,
                'display_name' => $display,
                'username' => $username,
                'useremail' => trim((string) ($row['useremail'] ?? '')),
            ];
        }
        $stmt->close();
        return $out;
    }
}

if (!function_exists('profile_is_valid_program_chair_id')) {
    function profile_is_valid_program_chair_id(mysqli $conn, $programChairUserId) {
        $programChairUserId = (int) $programChairUserId;
        if ($programChairUserId <= 0) return false;
        $stmt = $conn->prepare(
            "SELECT id
             FROM users
             WHERE id = ? AND role = 'program_chair' AND is_active = 1
             LIMIT 1"
        );
        if (!$stmt) return false;
        $stmt->bind_param('i', $programChairUserId);
        $stmt->execute();
        $res = $stmt->get_result();
        $ok = ($res && $res->num_rows === 1);
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('profile_normalize_subject_label')) {
    function profile_normalize_subject_label($value) {
        $value = trim((string) preg_replace('/\s+/', ' ', trim((string) $value)));
        return strtolower($value);
    }
}

if (!function_exists('profile_collect_subject_labels')) {
    function profile_collect_subject_labels($values) {
        $rawValues = is_array($values) ? $values : (($values === null) ? [] : [$values]);
        $out = [];
        $seen = [];
        foreach ($rawValues as $raw) {
            if (is_array($raw) || is_object($raw)) continue;
            $label = trim((string) preg_replace('/\s+/', ' ', trim((string) $raw)));
            if ($label === '') continue;
            $norm = profile_normalize_subject_label($label);
            if ($norm === '' || isset($seen[$norm])) continue;
            $seen[$norm] = true;
            $out[] = $label;
        }
        return $out;
    }
}

if (!function_exists('profile_teacher_subject_options')) {
    function profile_teacher_subject_options(mysqli $conn, $teacherUserId) {
        $teacherUserId = (int) $teacherUserId;
        if ($teacherUserId <= 0) return [];

        $labels = [];
        $seen = [];
        $add = function ($rawLabel) use (&$labels, &$seen) {
            $label = trim((string) preg_replace('/\s+/', ' ', trim((string) $rawLabel)));
            if ($label === '') return;
            $norm = profile_normalize_subject_label($label);
            if ($norm === '' || isset($seen[$norm])) return;
            $seen[$norm] = true;
            $labels[] = $label;
        };

        $stmt = $conn->prepare(
            "SELECT DISTINCT TRIM(COALESCE(s.subject_code,'')) AS subject_code,
                    TRIM(COALESCE(s.subject_name,'')) AS subject_name
             FROM teacher_assignments ta
             JOIN class_records cr ON cr.id = ta.class_record_id
             JOIN subjects s ON s.id = cr.subject_id
             WHERE ta.teacher_id = ? AND ta.status = 'active' AND cr.status = 'active'
             ORDER BY subject_name ASC, subject_code ASC"
        );
        if ($stmt) {
            $stmt->bind_param('i', $teacherUserId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                $code = trim((string) ($row['subject_code'] ?? ''));
                $name = trim((string) ($row['subject_name'] ?? ''));
                if ($code !== '' && $name !== '') $add($code . ' - ' . $name);
                elseif ($name !== '') $add($name);
                elseif ($code !== '') $add($code);
            }
            $stmt->close();
        }

        $stmt = $conn->prepare(
            "SELECT DISTINCT TRIM(COALESCE(subject_label,'')) AS subject_label
             FROM accomplishment_entries
             WHERE user_id = ? AND TRIM(COALESCE(subject_label,'')) <> ''
             ORDER BY subject_label ASC"
        );
        if ($stmt) {
            $stmt->bind_param('i', $teacherUserId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                $add((string) ($row['subject_label'] ?? ''));
            }
            $stmt->close();
        }

        $stmt = $conn->prepare(
            "SELECT DISTINCT TRIM(COALESCE(subject_label,'')) AS subject_label
             FROM teacher_subject_program_chairs
             WHERE teacher_user_id = ? AND TRIM(COALESCE(subject_label,'')) <> ''
             ORDER BY subject_label ASC"
        );
        if ($stmt) {
            $stmt->bind_param('i', $teacherUserId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                $add((string) ($row['subject_label'] ?? ''));
            }
            $stmt->close();
        }

        return $labels;
    }
}

if (!function_exists('profile_teacher_subject_program_chair_assignments')) {
    function profile_teacher_subject_program_chair_assignments(mysqli $conn, $teacherUserId) {
        $teacherUserId = (int) $teacherUserId;
        $map = [];
        if ($teacherUserId <= 0) return $map;

        $stmt = $conn->prepare(
            "SELECT tspc.subject_label, tspc.program_chair_user_id,
                    pc.username AS program_chair_username,
                    pc.useremail AS program_chair_useremail,
                    pc.first_name AS program_chair_first_name,
                    pc.last_name AS program_chair_last_name
             FROM teacher_subject_program_chairs tspc
             LEFT JOIN users pc ON pc.id = tspc.program_chair_user_id
             WHERE tspc.teacher_user_id = ?
             ORDER BY tspc.subject_label ASC"
        );
        if (!$stmt) return $map;

        $stmt->bind_param('i', $teacherUserId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $subjectLabel = trim((string) ($row['subject_label'] ?? ''));
            $norm = profile_normalize_subject_label($subjectLabel);
            if ($norm === '') continue;

            $programChairUserId = isset($row['program_chair_user_id']) ? (int) $row['program_chair_user_id'] : 0;
            $displayName = '';
            $email = '';
            if ($programChairUserId > 0) {
                $email = trim((string) ($row['program_chair_useremail'] ?? ''));
                $fn = trim((string) ($row['program_chair_first_name'] ?? ''));
                $ln = trim((string) ($row['program_chair_last_name'] ?? ''));
                $username = trim((string) ($row['program_chair_username'] ?? ''));
                $displayName = trim($fn . ' ' . $ln);
                if ($displayName === '') $displayName = $username;
            }

            $map[$norm] = [
                'subject_label' => $subjectLabel,
                'program_chair_user_id' => $programChairUserId,
                'program_chair_display_name' => $displayName,
                'program_chair_email' => $email,
            ];
        }
        $stmt->close();

        return $map;
    }
}

if (!function_exists('profile_resolve_program_chair_for_subjects')) {
    /**
     * Resolve one Program Chair for selected subject labels.
     * Returns:
     * - has_assignment(bool)
     * - multiple(bool): true when selected subjects resolve to different Program Chairs
     * - program_chair_user_id(int)
     * - program_chair_display_name(string)
     * - program_chair_email(string)
     */
    function profile_resolve_program_chair_for_subjects(mysqli $conn, $teacherUserId, array $subjectLabels) {
        $teacherUserId = (int) $teacherUserId;
        $result = [
            'has_assignment' => false,
            'multiple' => false,
            'program_chair_user_id' => 0,
            'program_chair_display_name' => '',
            'program_chair_email' => '',
        ];
        if ($teacherUserId <= 0) return $result;

        $selectedSubjects = profile_collect_subject_labels($subjectLabels);
        if (count($selectedSubjects) === 0) return $result;

        $subjectAssignments = profile_teacher_subject_program_chair_assignments($conn, $teacherUserId);
        $profileRow = profile_load($conn, $teacherUserId);
        $fallbackProgramChairId = is_array($profileRow) ? (int) ($profileRow['program_chair_user_id'] ?? 0) : 0;
        $fallbackProgramChairName = is_array($profileRow) ? trim((string) ($profileRow['program_chair_display_name'] ?? '')) : '';
        $fallbackProgramChairEmail = is_array($profileRow) ? trim((string) ($profileRow['program_chair_email'] ?? '')) : '';

        $resolvedIds = [];
        foreach ($selectedSubjects as $subjectLabel) {
            $norm = profile_normalize_subject_label($subjectLabel);
            if ($norm === '') continue;

            $programChairUserId = 0;
            $programChairName = '';
            $programChairEmail = '';
            if (isset($subjectAssignments[$norm])) {
                $row = $subjectAssignments[$norm];
                $programChairUserId = (int) ($row['program_chair_user_id'] ?? 0);
                $programChairName = trim((string) ($row['program_chair_display_name'] ?? ''));
                $programChairEmail = trim((string) ($row['program_chair_email'] ?? ''));
            } elseif ($fallbackProgramChairId > 0) {
                $programChairUserId = $fallbackProgramChairId;
                $programChairName = $fallbackProgramChairName;
                $programChairEmail = $fallbackProgramChairEmail;
            }

            if ($programChairUserId > 0) {
                $resolvedIds[$programChairUserId] = [
                    'program_chair_user_id' => $programChairUserId,
                    'program_chair_display_name' => $programChairName,
                    'program_chair_email' => $programChairEmail,
                ];
            }
        }

        if (count($resolvedIds) === 0) return $result;
        if (count($resolvedIds) > 1) {
            $result['multiple'] = true;
            return $result;
        }

        $single = reset($resolvedIds);
        if (!is_array($single)) return $result;
        $result['has_assignment'] = true;
        $result['program_chair_user_id'] = (int) ($single['program_chair_user_id'] ?? 0);
        $result['program_chair_display_name'] = trim((string) ($single['program_chair_display_name'] ?? ''));
        $result['program_chair_email'] = trim((string) ($single['program_chair_email'] ?? ''));
        return $result;
    }
}

if (!function_exists('profile_pending_request')) {
    function profile_pending_request(mysqli $conn, $userId) {
        $userId = (int) $userId;
        $req = null;
        $stmt = $conn->prepare(
            "SELECT id, status, payload_json, staged_avatar_path, review_note, requested_at, reviewed_at, reviewed_by
             FROM user_profile_change_requests
             WHERE user_id = ? AND status = 'pending'
             ORDER BY requested_at DESC, id DESC
             LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows === 1) $req = $res->fetch_assoc();
            $stmt->close();
        }
        return $req;
    }
}

if (!function_exists('profile_create_change_request')) {
    function profile_create_change_request(mysqli $conn, $userId, array $payload, $stagedAvatarPath = null) {
        $userId = (int) $userId;
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) $json = '{}';
        $stagedAvatarPath = $stagedAvatarPath !== null ? (string) $stagedAvatarPath : null;

        $stmt = $conn->prepare(
            "INSERT INTO user_profile_change_requests (user_id, status, payload_json, staged_avatar_path)
             VALUES (?, 'pending', ?, ?)"
        );
        if (!$stmt) return [false, 'Unable to prepare request.'];
        $stmt->bind_param('iss', $userId, $json, $stagedAvatarPath);
        $ok = false;
        try { $ok = $stmt->execute(); } catch (Throwable $e) { $ok = false; }
        $newId = $ok ? (int) $conn->insert_id : 0;
        $stmt->close();

        return $ok ? [true, $newId] : [false, 'Unable to submit request.'];
    }
}

if (!function_exists('profile_cancel_change_request')) {
    function profile_cancel_change_request(mysqli $conn, $userId, $requestId) {
        $userId = (int) $userId;
        $requestId = (int) $requestId;
        if ($userId <= 0 || $requestId <= 0) return false;

        $stmt = $conn->prepare(
            "UPDATE user_profile_change_requests
             SET status = 'cancelled', reviewed_at = CURRENT_TIMESTAMP, reviewed_by = ?
             WHERE id = ? AND user_id = ? AND status = 'pending'"
        );
        if (!$stmt) return false;
        $stmt->bind_param('iii', $userId, $requestId, $userId);
        $ok = false;
        try { $ok = $stmt->execute(); } catch (Throwable $e) { $ok = false; }
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('profile_apply_change_request')) {
    /**
     * Admin: approve/reject a request. When approving, applies the payload to users + user_profiles.
     * Returns [ok(bool), message(string)].
     */
    function profile_apply_change_request(mysqli $conn, $requestId, $adminId, $approve, $reviewNote = '') {
        $requestId = (int) $requestId;
        $adminId = (int) $adminId;
        $approve = (bool) $approve;
        $reviewNote = trim((string) $reviewNote);

        if ($requestId <= 0 || $adminId <= 0) return [false, 'Invalid request.'];

        $req = null;
        $stmt = $conn->prepare(
            "SELECT id, user_id, status, payload_json, staged_avatar_path
             FROM user_profile_change_requests
             WHERE id = ?
             LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param('i', $requestId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows === 1) $req = $res->fetch_assoc();
            $stmt->close();
        }
        if (!$req) return [false, 'Request not found.'];
        if ((string) ($req['status'] ?? '') !== 'pending') return [false, 'Request is not pending.'];

        $userId = (int) ($req['user_id'] ?? 0);
        if ($userId <= 0) return [false, 'Invalid request owner.'];

        $payload = json_decode((string) ($req['payload_json'] ?? ''), true);
        if (!is_array($payload)) $payload = [];

        $requestOwnerRole = '';
        $ownerRoleStmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
        if ($ownerRoleStmt) {
            $ownerRoleStmt->bind_param('i', $userId);
            $ownerRoleStmt->execute();
            $ownerRoleRes = $ownerRoleStmt->get_result();
            if ($ownerRoleRes && $ownerRoleRes->num_rows === 1) {
                $ownerRoleRow = $ownerRoleRes->fetch_assoc();
                $requestOwnerRole = strtolower(trim((string) ($ownerRoleRow['role'] ?? '')));
            }
            $ownerRoleStmt->close();
        }
        $isTeacherOwner = ($requestOwnerRole === 'teacher');

        $conn->begin_transaction();
        try {
            if ($approve) {
                $firstName = isset($payload['first_name']) ? trim((string) $payload['first_name']) : '';
                $lastName = isset($payload['last_name']) ? trim((string) $payload['last_name']) : '';
                $bio = isset($payload['bio']) ? trim((string) $payload['bio']) : '';
                $phone = isset($payload['phone']) ? trim((string) $payload['phone']) : '';
                $location = isset($payload['location']) ? trim((string) $payload['location']) : '';
                $github = isset($payload['github_username']) ? trim((string) $payload['github_username']) : '';
                $hasProgramChairSelection = array_key_exists('program_chair_user_id', $payload);
                $programChairUserId = 0;
                if ($hasProgramChairSelection) {
                    $rawProgramChairUserId = $payload['program_chair_user_id'];
                    if ($rawProgramChairUserId === null || (is_string($rawProgramChairUserId) && trim($rawProgramChairUserId) === '')) {
                        $programChairUserId = 0;
                    } else {
                        $programChairUserId = (int) $rawProgramChairUserId;
                        if ($programChairUserId > 0 && !profile_is_valid_program_chair_id($conn, $programChairUserId)) {
                            throw new RuntimeException('Invalid Program Chair selection.');
                        }
                    }
                }

                $hasSubjectProgramChairMap = array_key_exists('subject_program_chair_map', $payload);
                $subjectProgramChairMap = [];
                $subjectProgramChairDistinctIds = [];
                if ($hasSubjectProgramChairMap) {
                    if (!$isTeacherOwner) {
                        throw new RuntimeException('Subject Program Chair mapping is only available for teacher profiles.');
                    }
                    $rawSubjectMap = $payload['subject_program_chair_map'];
                    if (!is_array($rawSubjectMap)) $rawSubjectMap = [];

                    foreach ($rawSubjectMap as $subjectKey => $subjectItem) {
                        $subjectLabel = '';
                        $rawSubjectProgramChairId = null;
                        if (is_array($subjectItem)) {
                            $subjectLabel = trim((string) ($subjectItem['subject_label'] ?? ''));
                            if (array_key_exists('program_chair_user_id', $subjectItem)) {
                                $rawSubjectProgramChairId = $subjectItem['program_chair_user_id'];
                            }
                        } elseif (is_string($subjectKey)) {
                            $subjectLabel = trim((string) $subjectKey);
                            $rawSubjectProgramChairId = $subjectItem;
                        }

                        $subjectLabel = trim((string) preg_replace('/\s+/', ' ', $subjectLabel));
                        if ($subjectLabel === '') continue;
                        if (strlen($subjectLabel) > 255) $subjectLabel = substr($subjectLabel, 0, 255);
                        $subjectNorm = profile_normalize_subject_label($subjectLabel);
                        if ($subjectNorm === '') continue;

                        $subjectProgramChairId = 0;
                        if (!($rawSubjectProgramChairId === null || (is_string($rawSubjectProgramChairId) && trim($rawSubjectProgramChairId) === ''))) {
                            $subjectProgramChairId = (int) $rawSubjectProgramChairId;
                            if ($subjectProgramChairId > 0 && !profile_is_valid_program_chair_id($conn, $subjectProgramChairId)) {
                                throw new RuntimeException('Invalid Program Chair selection for subject "' . $subjectLabel . '".');
                            }
                        }

                        if ($subjectProgramChairId > 0) $subjectProgramChairDistinctIds[$subjectProgramChairId] = true;
                        $subjectProgramChairMap[$subjectNorm] = [
                            'subject_label' => $subjectLabel,
                            'program_chair_user_id' => $subjectProgramChairId,
                        ];
                    }
                }

                if (!$hasProgramChairSelection && $hasSubjectProgramChairMap) {
                    $derivedProgramChairUserId = 0;
                    if (count($subjectProgramChairDistinctIds) === 1) {
                        $keys = array_keys($subjectProgramChairDistinctIds);
                        $derivedProgramChairUserId = (int) ($keys[0] ?? 0);
                    }
                    $programChairUserId = $derivedProgramChairUserId;
                    $hasProgramChairSelection = true;
                }

                // Apply user core fields (do not touch login/email/role here).
                $updUser = $conn->prepare("UPDATE users SET first_name = ?, last_name = ? WHERE id = ? LIMIT 1");
                if ($updUser) {
                    $updUser->bind_param('ssi', $firstName, $lastName, $userId);
                    $updUser->execute();
                    $updUser->close();
                }

                // Apply extended fields (upsert).
                if ($hasProgramChairSelection) {
                    $up = $conn->prepare(
                        "INSERT INTO user_profiles (user_id, bio, phone, location, github_username, program_chair_user_id)
                         VALUES (?, ?, ?, ?, ?, NULLIF(?, 0))
                         ON DUPLICATE KEY UPDATE
                            bio = VALUES(bio),
                            phone = VALUES(phone),
                            location = VALUES(location),
                            github_username = VALUES(github_username),
                            program_chair_user_id = VALUES(program_chair_user_id)"
                    );
                    if ($up) {
                        $up->bind_param('issssi', $userId, $bio, $phone, $location, $github, $programChairUserId);
                        $up->execute();
                        $up->close();
                    }
                } else {
                    $up = $conn->prepare(
                        "INSERT INTO user_profiles (user_id, bio, phone, location, github_username)
                         VALUES (?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE
                            bio = VALUES(bio),
                            phone = VALUES(phone),
                            location = VALUES(location),
                            github_username = VALUES(github_username)"
                    );
                    if ($up) {
                        $up->bind_param('issss', $userId, $bio, $phone, $location, $github);
                        $up->execute();
                        $up->close();
                    }
                }

                if ($hasSubjectProgramChairMap) {
                    foreach ($subjectProgramChairMap as $subjectRow) {
                        if (!is_array($subjectRow)) continue;
                        $subjectLabel = trim((string) ($subjectRow['subject_label'] ?? ''));
                        if ($subjectLabel === '') continue;
                        $subjectProgramChairId = (int) ($subjectRow['program_chair_user_id'] ?? 0);

                        if ($subjectProgramChairId > 0) {
                            $upSubjectChair = $conn->prepare(
                                "INSERT INTO teacher_subject_program_chairs (teacher_user_id, subject_label, program_chair_user_id)
                                 VALUES (?, ?, ?)
                                 ON DUPLICATE KEY UPDATE
                                    program_chair_user_id = VALUES(program_chair_user_id)"
                            );
                            if ($upSubjectChair) {
                                $upSubjectChair->bind_param('isi', $userId, $subjectLabel, $subjectProgramChairId);
                                $upSubjectChair->execute();
                                $upSubjectChair->close();
                            }
                        } else {
                            $delSubjectChair = $conn->prepare(
                                "DELETE FROM teacher_subject_program_chairs
                                 WHERE teacher_user_id = ?
                                   AND LOWER(TRIM(subject_label)) = LOWER(TRIM(?))
                                 LIMIT 1"
                            );
                            if ($delSubjectChair) {
                                $delSubjectChair->bind_param('is', $userId, $subjectLabel);
                                $delSubjectChair->execute();
                                $delSubjectChair->close();
                            }
                        }
                    }
                }

                // Apply avatar (if provided).
                $staged = isset($req['staged_avatar_path']) ? (string) $req['staged_avatar_path'] : '';
                if ($staged !== '') {
                    $root = realpath(__DIR__ . '/..');
                    $src = $root ? ($root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $staged)) : null;
                    if ($src && is_file($src)) {
                        $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
                        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) $ext = 'jpg';
                        // Keep consistent with existing avatar naming used in the project: uploads/profile_pictures/{id}.ext
                        $dstRel = 'uploads/profile_pictures/' . $userId . '.' . $ext;
                        $dst = ($root ? ($root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $dstRel)) : null);
                        if ($dst) {
                            $dir = dirname($dst);
                            if (!is_dir($dir)) @mkdir($dir, 0775, true);
                            // Copy to preserve staged file for traceability.
                            @copy($src, $dst);

                            $updPic = $conn->prepare("UPDATE users SET profile_picture = ? WHERE id = ? LIMIT 1");
                            if ($updPic) {
                                $updPic->bind_param('si', $dstRel, $userId);
                                $updPic->execute();
                                $updPic->close();
                            }
                        }
                    }
                }
            }

            $newStatus = $approve ? 'approved' : 'rejected';
            $upd = $conn->prepare(
                "UPDATE user_profile_change_requests
                 SET status = ?, review_note = ?, reviewed_at = CURRENT_TIMESTAMP, reviewed_by = ?
                 WHERE id = ? AND status = 'pending'"
            );
            if ($upd) {
                $upd->bind_param('ssii', $newStatus, $reviewNote, $adminId, $requestId);
                $upd->execute();
                $upd->close();
            }

            $conn->commit();
            return [true, $approve ? 'Request approved.' : 'Request rejected.'];
        } catch (Throwable $e) {
            $conn->rollback();
            return [false, 'Update failed.'];
        }
    }
}
