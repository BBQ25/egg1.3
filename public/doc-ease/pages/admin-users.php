<?php include '../layouts/session.php'; ?>
<?php require_role('admin'); ?>

<?php
require_once __DIR__ . '/../includes/class_record_builds.php';
require_once __DIR__ . '/../includes/usage_limits.php';
require_once __DIR__ . '/../includes/ai_credits.php';
require_once __DIR__ . '/../includes/reference.php';
ensure_users_build_limit_column($conn);
ensure_users_password_policy_columns($conn);
ensure_class_record_build_tables($conn);
ai_credit_ensure_system($conn);
usage_limit_ensure_system($conn);
ensure_reference_tables($conn);

$managedRole = isset($ACCOUNT_ROLE_SCOPE) ? normalize_role((string) $ACCOUNT_ROLE_SCOPE) : '';
if (!in_array($managedRole, ['student', 'teacher'], true)) {
    $managedRole = '';
}
$isScopedRolePage = $managedRole !== '';

$redirectPage = isset($ACCOUNT_REDIRECT_PAGE) ? trim((string) $ACCOUNT_REDIRECT_PAGE) : 'admin-users.php';
if (!preg_match('/^[a-z0-9\\-]+\\.php$/i', $redirectPage)) {
    $redirectPage = 'admin-users.php';
}

$roleLabelSingular = $managedRole === 'student' ? 'Student' : ($managedRole === 'teacher' ? 'Teacher' : 'User');
$roleLabelPlural = $managedRole === 'student' ? 'Students' : ($managedRole === 'teacher' ? 'Teachers' : 'Accounts');
$isStudentScopedPage = $isScopedRolePage && $managedRole === 'student';
$pageHeading = $isScopedRolePage ? ($roleLabelPlural . ' Accounts') : 'Account Access';
$toolbarHeading = $isScopedRolePage ? ('Pending and Active ' . $roleLabelPlural . ' Accounts') : 'Pending and Active Accounts';
$createButtonLabel = $isScopedRolePage ? ('Create ' . $roleLabelSingular) : 'Create User';
$searchPlaceholder = $isScopedRolePage ? 'Search name or email...' : 'Search name, email, role...';
$accountsMetaLabel = $isScopedRolePage ? strtolower($roleLabelPlural) . ' account(s)' : 'account(s)';
$toolbarDescription = $isScopedRolePage
    ? ('Approve, edit, and manage limits for ' . strtolower($roleLabelPlural) . ' accounts.')
    : 'Approve student/teacher/staff accounts, then manage role and usage limits in one place.';
if ($isStudentScopedPage) {
    $toolbarHeading = 'Student Master List and Login Accounts';
    $createButtonLabel = 'Create Student Login';
    $searchPlaceholder = 'Search student no, name, course, major, year, section, or account email...';
    $accountsMetaLabel = 'student record(s)';
    $toolbarDescription = 'Shows all records from the students table plus student login accounts that are not yet linked to a student profile. Use "Create/Link Profile" on unlinked rows for quick linking.';
} elseif ($isScopedRolePage && $managedRole === 'teacher') {
    $toolbarHeading = 'Teacher Profiles and Login Accounts';
    $searchPlaceholder = 'Search teacher no, name, department, position, or account email...';
    $accountsMetaLabel = 'teacher account(s)';
    $toolbarDescription = 'Manage teacher login accounts and linked teacher profiles in one place. Use "Create/Link Profile" for unlinked rows.';
}

$flashMessage = isset($_SESSION['flash_message']) ? (string) $_SESSION['flash_message'] : '';
$flashType = isset($_SESSION['flash_type']) ? (string) $_SESSION['flash_type'] : 'success';

$flashTempPasswordUser = isset($_SESSION['flash_temp_password_user']) ? (string) $_SESSION['flash_temp_password_user'] : '';
$flashTempPassword = isset($_SESSION['flash_temp_password']) ? (string) $_SESSION['flash_temp_password'] : '';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);
unset($_SESSION['flash_temp_password_user'], $_SESSION['flash_temp_password']);

if (!function_exists('admin_users_table_exists')) {
    function admin_users_table_exists(mysqli $conn, $tableName) {
        $tableName = trim((string) $tableName);
        if ($tableName === '') return false;
        $stmt = $conn->prepare(
            "SELECT 1
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
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

if (!function_exists('admin_users_guess_name_parts')) {
    function admin_users_guess_name_parts($rawName) {
        $result = [
            'surname' => '',
            'firstname' => '',
            'middlename' => '',
        ];
        $name = trim((string) $rawName);
        if ($name === '') return $result;
        $name = preg_replace('/\s+/', ' ', $name);
        if (!is_string($name)) return $result;

        if (strpos($name, ',') !== false) {
            $parts = explode(',', $name, 2);
            $result['surname'] = trim((string) ($parts[0] ?? ''));
            $right = trim((string) ($parts[1] ?? ''));
            if ($right !== '') {
                $tokens = array_values(array_filter(explode(' ', $right), static function ($v) {
                    return trim((string) $v) !== '';
                }));
                if (count($tokens) >= 1) $result['firstname'] = trim((string) $tokens[0]);
                if (count($tokens) >= 2) $result['middlename'] = trim((string) implode(' ', array_slice($tokens, 1)));
            }
            return $result;
        }

        $tokens = array_values(array_filter(explode(' ', $name), static function ($v) {
            return trim((string) $v) !== '';
        }));
        if (count($tokens) === 1) {
            $result['firstname'] = trim((string) $tokens[0]);
            return $result;
        }
        if (count($tokens) >= 2) {
            $result['firstname'] = trim((string) $tokens[0]);
            $result['surname'] = trim((string) $tokens[count($tokens) - 1]);
            if (count($tokens) >= 3) {
                $result['middlename'] = trim((string) implode(' ', array_slice($tokens, 1, -1)));
            }
        }
        return $result;
    }
}

if (!function_exists('admin_users_compose_display_name')) {
    function admin_users_compose_display_name($surname, $firstName, $middleName = '') {
        $surname = trim((string) $surname);
        $firstName = trim((string) $firstName);
        $middleName = trim((string) $middleName);
        $full = trim($surname . ($surname !== '' ? ', ' : '') . $firstName . ($middleName !== '' ? (' ' . $middleName) : ''));
        $full = preg_replace('/\s+/', ' ', $full);
        if (!is_string($full)) return '';
        return trim($full, " \t\n\r\0\x0B,");
    }
}

if (!function_exists('ensure_teachers_table')) {
    function ensure_teachers_table(mysqli $conn) {
        if (!admin_users_table_exists($conn, 'teachers')) {
            $sql = "CREATE TABLE IF NOT EXISTS teachers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL UNIQUE,
            campus_id BIGINT UNSIGNED NULL,
            TeacherNo VARCHAR(30) NOT NULL UNIQUE,
            Surname VARCHAR(50) NOT NULL,
            FirstName VARCHAR(50) NOT NULL,
            MiddleName VARCHAR(50) NULL,
            Sex ENUM('M','F') NULL DEFAULT 'M',
            Department VARCHAR(100) NULL,
            Position VARCHAR(100) NULL,
            EmploymentStatus ENUM('Full-time','Part-time','Contractual','Visiting') NULL DEFAULT 'Full-time',
            Status ENUM('Active','Inactive','OnLeave','Retired') NULL DEFAULT 'Active',
            BirthDate DATE NULL,
            Barangay VARCHAR(100) NULL,
            Municipality VARCHAR(100) NULL,
            Province VARCHAR(100) NULL,
            email VARCHAR(100) NULL,
            phone VARCHAR(20) NULL,
            profile_picture VARCHAR(255) NULL,
            created_by INT NOT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_teachers_name (Surname, FirstName),
            KEY idx_teachers_user (user_id),
            KEY idx_teachers_campus (campus_id),
            KEY idx_teachers_department (Department),
            KEY idx_teachers_status (Status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            $conn->query($sql);
        }

        if (function_exists('session_table_has_column') && !session_table_has_column($conn, 'teachers', 'campus_id')) {
            $conn->query("ALTER TABLE teachers ADD COLUMN campus_id BIGINT UNSIGNED NULL DEFAULT NULL");
        }
    }
}

if (!function_exists('admin_users_sync_linked_account')) {
    function admin_users_sync_linked_account(
        mysqli $conn,
        $userId,
        $roleIfLegacyUser,
        $usernameCandidate,
        $firstName,
        $lastName,
        $emailCandidate,
        &$notes
    ) {
        $notes = [];
        $userId = (int) $userId;
        if ($userId <= 0) return false;

        $roleIfLegacyUser = normalize_role((string) $roleIfLegacyUser);
        if (!in_array($roleIfLegacyUser, ['student', 'teacher'], true)) {
            $roleIfLegacyUser = '';
        }
        $roleExpr = "role";
        if ($roleIfLegacyUser !== '') {
            $roleExpr = "CASE WHEN role = 'user' THEN '" . $roleIfLegacyUser . "' ELSE role END";
        }

        $firstName = trim((string) $firstName);
        $lastName = trim((string) $lastName);
        if ($firstName === '') $firstName = 'N/A';
        if ($lastName === '') $lastName = 'N/A';

        $usernameCandidate = trim((string) $usernameCandidate);
        $canSyncUsername = false;
        if ($usernameCandidate !== '') {
            if (strlen($usernameCandidate) > 50) {
                $notes[] = 'Account username was not updated because it exceeds 50 characters.';
            } else {
                $dupUser = $conn->prepare("SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1");
                if ($dupUser) {
                    $dupUser->bind_param('si', $usernameCandidate, $userId);
                    $dupUser->execute();
                    $dupRes = $dupUser->get_result();
                    $exists = $dupRes && $dupRes->num_rows > 0;
                    $dupUser->close();
                    if ($exists) {
                        $notes[] = 'Account username was not updated because the target value is already used.';
                    } else {
                        $canSyncUsername = true;
                    }
                }
            }
        }

        $emailCandidate = trim((string) $emailCandidate);
        $canSyncEmail = false;
        if ($emailCandidate !== '') {
            if (!filter_var($emailCandidate, FILTER_VALIDATE_EMAIL)) {
                $notes[] = 'Account email was not updated because the email format is invalid.';
            } elseif (strlen($emailCandidate) > 255) {
                $notes[] = 'Account email was not updated because it exceeds 255 characters.';
            } else {
                $dupEmail = $conn->prepare("SELECT id FROM users WHERE useremail = ? AND id <> ? LIMIT 1");
                if ($dupEmail) {
                    $dupEmail->bind_param('si', $emailCandidate, $userId);
                    $dupEmail->execute();
                    $dupRes = $dupEmail->get_result();
                    $exists = $dupRes && $dupRes->num_rows > 0;
                    $dupEmail->close();
                    if ($exists) {
                        $notes[] = 'Account email was not updated because another account already uses it.';
                    } else {
                        $canSyncEmail = true;
                    }
                }
            }
        }

        $ok = false;
        if ($canSyncUsername && $canSyncEmail) {
            $stmt = $conn->prepare(
                "UPDATE users
                 SET first_name = ?, last_name = ?, role = " . $roleExpr . ", username = ?, useremail = ?
                 WHERE id = ?
                 LIMIT 1"
            );
            if ($stmt) {
                $stmt->bind_param('ssssi', $firstName, $lastName, $usernameCandidate, $emailCandidate, $userId);
                $ok = $stmt->execute();
                $stmt->close();
            }
        } elseif ($canSyncUsername) {
            $stmt = $conn->prepare(
                "UPDATE users
                 SET first_name = ?, last_name = ?, role = " . $roleExpr . ", username = ?
                 WHERE id = ?
                 LIMIT 1"
            );
            if ($stmt) {
                $stmt->bind_param('sssi', $firstName, $lastName, $usernameCandidate, $userId);
                $ok = $stmt->execute();
                $stmt->close();
            }
        } elseif ($canSyncEmail) {
            $stmt = $conn->prepare(
                "UPDATE users
                 SET first_name = ?, last_name = ?, role = " . $roleExpr . ", useremail = ?
                 WHERE id = ?
                 LIMIT 1"
            );
            if ($stmt) {
                $stmt->bind_param('sssi', $firstName, $lastName, $emailCandidate, $userId);
                $ok = $stmt->execute();
                $stmt->close();
            }
        } else {
            $stmt = $conn->prepare(
                "UPDATE users
                 SET first_name = ?, last_name = ?, role = " . $roleExpr . "
                 WHERE id = ?
                 LIMIT 1"
            );
            if ($stmt) {
                $stmt->bind_param('ssi', $firstName, $lastName, $userId);
                $ok = $stmt->execute();
                $stmt->close();
            }
        }

        return $ok;
    }
}

if (!function_exists('admin_users_sync_student_no_references')) {
    function admin_users_sync_student_no_references(mysqli $conn, $oldStudentNo, $newStudentNo) {
        $oldStudentNo = trim((string) $oldStudentNo);
        $newStudentNo = trim((string) $newStudentNo);
        if ($oldStudentNo === '' || $newStudentNo === '' || $oldStudentNo === $newStudentNo) return;

        if (admin_users_table_exists($conn, 'enrollments')) {
            $updEnroll = $conn->prepare("UPDATE enrollments SET student_no = ? WHERE student_no = ?");
            if ($updEnroll) {
                $updEnroll->bind_param('ss', $newStudentNo, $oldStudentNo);
                $updEnroll->execute();
                $updEnroll->close();
            }
        }
        if (admin_users_table_exists($conn, 'uploaded_files')) {
            $updFiles = $conn->prepare("UPDATE uploaded_files SET StudentNo = ? WHERE StudentNo = ?");
            if ($updFiles) {
                $updFiles->bind_param('ss', $newStudentNo, $oldStudentNo);
                $updFiles->execute();
                $updFiles->close();
            }
        }
    }
}

if (!function_exists('admin_users_section_tokens')) {
    function admin_users_section_tokens($value) {
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

if (!function_exists('admin_users_section_matches_alias')) {
    function admin_users_section_matches_alias($classSection, $targetSection) {
        $classSection = strtolower(trim((string) $classSection));
        $targetSection = strtolower(trim((string) $targetSection));
        if ($classSection === '' || $targetSection === '') return false;
        if ($classSection === $targetSection) return true;

        $classTokens = admin_users_section_tokens($classSection);
        $targetTokens = admin_users_section_tokens($targetSection);
        if (count($classTokens) === 0 || count($targetTokens) === 0) return false;

        if (count($targetTokens) === 1) {
            return in_array($targetTokens[0], $classTokens, true);
        }

        foreach ($targetTokens as $token) {
            if (!in_array($token, $classTokens, true)) return false;
        }
        return true;
    }
}

if (!function_exists('admin_users_resolve_class_record_target')) {
    function admin_users_resolve_class_record_target(mysqli $conn, $subjectId, $targetSection, $academicYear, $semester) {
        $subjectId = (int) $subjectId;
        $targetSection = trim((string) $targetSection);
        $academicYear = trim((string) $academicYear);
        $semester = trim((string) $semester);

        $result = [
            'class_record_id' => 0,
            'resolved_section' => $targetSection,
            'match_bucket' => 0,
        ];

        if ($subjectId <= 0 || $targetSection === '' || $academicYear === '' || $semester === '') {
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

            $isExact = strcasecmp($candidateSection, $targetSection) === 0;
            $isAlias = admin_users_section_matches_alias($candidateSection, $targetSection);
            if (!$isExact && !$isAlias) continue;

            // Priority:
            // 1) exact + teacher
            // 2) alias + teacher (e.g. target "B" maps to "IF-2-B-6")
            // 3) exact (no teacher)
            // 4) alias (no teacher)
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
                    'bucket' => $bucket,
                ];
            }
        }
        $stmt->close();

        if (is_array($best)) {
            $result['class_record_id'] = (int) ($best['id'] ?? 0);
            $result['resolved_section'] = (string) ($best['section'] ?? $targetSection);
            $result['match_bucket'] = (int) ($best['bucket'] ?? 0);
        }

        return $result;
    }
}

ensure_teachers_table($conn);

if (!function_exists('admin_users_fetch_target_scope')) {
    function admin_users_fetch_target_scope(mysqli $conn, $userId) {
        $userId = (int) $userId;
        if ($userId <= 0) return null;

        $stmt = $conn->prepare(
            "SELECT id, role, campus_id, is_superadmin
             FROM users
             WHERE id = ?
             LIMIT 1"
        );
        if (!$stmt) return null;

        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!is_array($row)) return null;
        return [
            'id' => (int) ($row['id'] ?? 0),
            'role' => normalize_role((string) ($row['role'] ?? '')),
            'campus_id' => (int) ($row['campus_id'] ?? 0),
            'is_superadmin' => ((int) ($row['is_superadmin'] ?? 0) === 1) ? 1 : 0,
        ];
    }
}

if (!function_exists('admin_users_can_manage_target')) {
    function admin_users_can_manage_target($actorUserId, $actorIsSuperadmin, $actorCampusId, array $targetRow) {
        $actorUserId = (int) $actorUserId;
        $actorIsSuperadmin = (bool) $actorIsSuperadmin;
        $actorCampusId = (int) $actorCampusId;

        $targetUserId = (int) ($targetRow['id'] ?? 0);
        $targetCampusId = (int) ($targetRow['campus_id'] ?? 0);
        $targetIsSuperadmin = ((int) ($targetRow['is_superadmin'] ?? 0) === 1);
        $targetRole = normalize_role((string) ($targetRow['role'] ?? ''));

        if ($targetUserId <= 0) return false;

        // Avoid role escalation edge-cases and self lockout paths through this page.
        if ($targetIsSuperadmin) return false;

        if ($actorIsSuperadmin) return true;

        if ($targetCampusId <= 0 || $actorCampusId <= 0 || $targetCampusId !== $actorCampusId) {
            return false;
        }

        // Campus admins should not manage admin accounts from this panel.
        if ($targetRole === 'admin') {
            return false;
        }

        return true;
    }
}

if (!function_exists('admin_users_existing_campus_admin_id')) {
    function admin_users_existing_campus_admin_id(mysqli $conn, $campusId, $excludeUserId = 0) {
        $campusId = (int) $campusId;
        $excludeUserId = (int) $excludeUserId;
        if ($campusId <= 0) return 0;

        if ($excludeUserId > 0) {
            $stmt = $conn->prepare(
                "SELECT id
                 FROM users
                 WHERE role = 'admin'
                   AND is_superadmin = 0
                   AND campus_id = ?
                   AND id <> ?
                 LIMIT 1"
            );
            if (!$stmt) return 0;
            $stmt->bind_param('ii', $campusId, $excludeUserId);
        } else {
            $stmt = $conn->prepare(
                "SELECT id
                 FROM users
                 WHERE role = 'admin'
                   AND is_superadmin = 0
                   AND campus_id = ?
                 LIMIT 1"
            );
            if (!$stmt) return 0;
            $stmt->bind_param('i', $campusId);
        }

        $stmt->execute();
        $res = $stmt->get_result();
        $existingId = ($res && $res->num_rows === 1) ? (int) ($res->fetch_assoc()['id'] ?? 0) : 0;
        $stmt->close();
        return $existingId;
    }
}

$currentAdminUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$adminIsSuperadmin = current_user_is_superadmin();
$adminCampusId = current_user_campus_id();
$defaultCampusId = campus_default_id($conn);
if (!$adminIsSuperadmin && $adminCampusId <= 0) {
    $adminCampusId = $defaultCampusId;
}
$campusOptions = campus_list($conn, false);
$campusOptionsById = [];
foreach ($campusOptions as $campusRow) {
    $campusRowId = (int) ($campusRow['id'] ?? 0);
    if ($campusRowId <= 0) continue;
    $campusOptionsById[$campusRowId] = $campusRow;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    $postedCampusId = isset($_POST['campus_id']) ? (int) $_POST['campus_id'] : 0;
    $availableRoles = $isScopedRolePage
        ? [$managedRole]
        : ($adminIsSuperadmin
            ? ['student', 'teacher', 'registrar', 'program_chair', 'college_dean', 'guardian', 'admin']
            : ['student', 'teacher', 'registrar', 'program_chair', 'college_dean', 'guardian']);

    if (!csrf_validate($csrf)) {
        $_SESSION['flash_message'] = 'Security check failed (CSRF). Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . $redirectPage);
        exit;
    }

    if ($isScopedRolePage && $userId > 0 && $action !== 'create_user') {
        $scopedRoleOk = false;
        $roleStmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
        if ($roleStmt) {
            $roleStmt->bind_param('i', $userId);
            $roleStmt->execute();
            $roleRes = $roleStmt->get_result();
            if ($roleRes && $roleRes->num_rows === 1) {
                $dbRole = normalize_role((string) ($roleRes->fetch_assoc()['role'] ?? ''));
                if ($managedRole === 'student') {
                    // Student Accounts includes legacy `user` role rows that should still be manageable here.
                    $scopedRoleOk = in_array($dbRole, ['student', 'user'], true);
                } else {
                    $scopedRoleOk = $managedRole === $dbRole;
                }
            }
            $roleStmt->close();
        }

        if (!$scopedRoleOk) {
            $_SESSION['flash_message'] = 'This page can only manage ' . strtolower($roleLabelPlural) . ' accounts.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: ' . $redirectPage);
            exit;
        }
    }

    if ($userId > 0 && $action !== 'create_user') {
        $targetScope = admin_users_fetch_target_scope($conn, $userId);
        if (!is_array($targetScope) || !admin_users_can_manage_target($currentAdminUserId, $adminIsSuperadmin, $adminCampusId, $targetScope)) {
            $_SESSION['flash_message'] = 'You do not have permission to manage this account.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: ' . $redirectPage);
            exit;
        }
    }

    if ($isStudentScopedPage && $action === 'bulk_enroll_students') {
        $subjectId = isset($_POST['bulk_subject_id']) ? (int) $_POST['bulk_subject_id'] : 0;
        $targetSectionRaw = trim((string) ($_POST['bulk_section'] ?? ''));
        $targetSection = $targetSectionRaw;
        $targetCourse = '';
        $targetYearLevel = '';
        if (strpos($targetSectionRaw, '||') !== false) {
            $parts = explode('||', $targetSectionRaw, 3);
            if (count($parts) === 3) {
                $targetCourse = trim((string) $parts[0]);
                $targetYearLevel = trim((string) $parts[1]);
                $targetSection = trim((string) $parts[2]);
            }
        }
        if (function_exists('ref_normalize_course_name')) {
            $targetCourse = ref_normalize_course_name($targetCourse);
        }
        if (function_exists('ref_normalize_year_level')) {
            $targetYearLevel = ref_normalize_year_level($targetYearLevel);
        }
        if (function_exists('ref_normalize_section_code')) {
            $targetSection = ref_normalize_section_code($targetSection);
        }
        $targetAcademicYear = trim((string) ($_POST['bulk_academic_year'] ?? ''));
        $targetSemester = trim((string) ($_POST['bulk_semester'] ?? ''));
        $selectedStudentIdsRaw = isset($_POST['student_ids']) && is_array($_POST['student_ids']) ? $_POST['student_ids'] : [];

        $selectedStudentIds = [];
        foreach ($selectedStudentIdsRaw as $sidRaw) {
            $sid = (int) $sidRaw;
            if ($sid > 0) $selectedStudentIds[$sid] = true;
        }
        $selectedStudentIds = array_keys($selectedStudentIds);

        if (count($selectedStudentIds) === 0) {
            $_SESSION['flash_message'] = 'Select at least one student record for bulk enrollment.';
            $_SESSION['flash_type'] = 'warning';
        } elseif ($subjectId <= 0 || $targetSection === '') {
            $_SESSION['flash_message'] = 'Subject and target section are required.';
            $_SESSION['flash_type'] = 'warning';
        } elseif (strlen($targetSection) > 10) {
            $_SESSION['flash_message'] = 'Target section is too long. Please use the section code (for example: A, B, C).';
            $_SESSION['flash_type'] = 'warning';
        } else {
            $subjectStmt = $conn->prepare(
                "SELECT id, subject_name, subject_code, academic_year, semester
                 FROM subjects
                 WHERE id = ? AND status = 'active'
                 LIMIT 1"
            );
            $subject = null;
            if ($subjectStmt) {
                $subjectStmt->bind_param('i', $subjectId);
                $subjectStmt->execute();
                $subjectRes = $subjectStmt->get_result();
                if ($subjectRes && $subjectRes->num_rows === 1) {
                    $subject = $subjectRes->fetch_assoc();
                }
                $subjectStmt->close();
            }

            if (!$subject) {
                $_SESSION['flash_message'] = 'Selected subject is not available.';
                $_SESSION['flash_type'] = 'warning';
            } else {
                $subjectAcademicYear = trim((string) ($subject['academic_year'] ?? ''));
                $subjectSemester = trim((string) ($subject['semester'] ?? ''));
                if ($targetAcademicYear === '' && $subjectAcademicYear !== '') $targetAcademicYear = $subjectAcademicYear;
                if ($targetSemester === '' && $subjectSemester !== '') $targetSemester = $subjectSemester;

                if ($targetAcademicYear === '' || $targetSemester === '') {
                    $_SESSION['flash_message'] = 'Academic Year and Semester are required for enrollment.';
                    $_SESSION['flash_type'] = 'warning';
                } else {
                    $studentIdListSql = implode(',', array_map('intval', $selectedStudentIds));
                    $selectedStudents = [];
                    $studentSelectionSql =
                        "SELECT id, StudentNo
                         FROM students
                         WHERE id IN (" . $studentIdListSql . ")";
                    if (!$adminIsSuperadmin) {
                        $studentSelectionSql .= " AND campus_id = " . (int) $adminCampusId;
                    }
                    $studentSelectionSql .= " ORDER BY Surname ASC, FirstName ASC, MiddleName ASC";
                    $stRes = $conn->query($studentSelectionSql);
                    while ($stRes && ($row = $stRes->fetch_assoc())) {
                        $sid = (int) ($row['id'] ?? 0);
                        $sno = trim((string) ($row['StudentNo'] ?? ''));
                        if ($sid > 0 && $sno !== '') {
                            $selectedStudents[] = ['id' => $sid, 'student_no' => $sno];
                        }
                    }

                    if (count($selectedStudents) === 0) {
                        $_SESSION['flash_message'] = 'No valid student records found in the selection.';
                        $_SESSION['flash_type'] = 'warning';
                    } else {
                        $adminId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
                        $createdBy = isset($_SESSION['user_name']) ? trim((string) $_SESSION['user_name']) : '';
                        if ($createdBy === '') $createdBy = isset($_SESSION['user_email']) ? trim((string) $_SESSION['user_email']) : '';
                        if ($createdBy === '') $createdBy = 'admin:' . (string) $adminId;

                        $conn->begin_transaction();
                        try {
                            $classRecordId = 0;
                            $resolvedClass = admin_users_resolve_class_record_target(
                                $conn,
                                $subjectId,
                                $targetSection,
                                $targetAcademicYear,
                                $targetSemester
                            );
                            $classRecordId = (int) ($resolvedClass['class_record_id'] ?? 0);
                            $enrollmentSection = trim((string) ($resolvedClass['resolved_section'] ?? $targetSection));
                            if ($enrollmentSection === '') $enrollmentSection = $targetSection;

                            if ($classRecordId <= 0) {
                                $sectionHint = function_exists('ref_section_lookup_hint')
                                    ? strtoupper(ref_section_lookup_hint($targetSection))
                                    : strtoupper($targetSection);
                                $looksAmbiguousSectionCode =
                                    preg_match('/^[A-Z]$/', $sectionHint) === 1 ||
                                    preg_match('/^[1-4][A-Z]$/', $sectionHint) === 1;
                                if ($looksAmbiguousSectionCode) {
                                    throw new RuntimeException(
                                        'No matching class record found for section code ' . $targetSection .
                                        '. Assign a teacher/class section first (for example IF-2-B-6), then retry bulk enroll.'
                                    );
                                }

                                $insClass = $conn->prepare(
                                    "INSERT INTO class_records
                                        (subject_id, teacher_id, record_type, section, academic_year, semester, created_by, status)
                                     VALUES (?, NULL, 'assigned', ?, ?, ?, ?, 'active')"
                                );
                                if (!$insClass) throw new RuntimeException('Unable to create class record for enrollment.');
                                $insClass->bind_param('isssi', $subjectId, $targetSection, $targetAcademicYear, $targetSemester, $adminId);
                                $insClass->execute();
                                $classRecordId = (int) $conn->insert_id;
                                $insClass->close();
                                $enrollmentSection = $targetSection;
                            }

                            $insEnroll = $conn->prepare(
                                "INSERT INTO enrollments
                                    (student_no, subject_id, academic_year, semester, section, status, created_by)
                                 VALUES (?, ?, ?, ?, ?, 'Active', ?)
                                 ON DUPLICATE KEY UPDATE
                                    section = VALUES(section),
                                    status = 'Active',
                                    created_by = VALUES(created_by)"
                            );
                            $insClassEnroll = $conn->prepare(
                                "INSERT INTO class_enrollments
                                    (class_record_id, student_id, enrollment_date, status, created_by, class_id)
                                 VALUES (?, ?, ?, 'enrolled', ?, ?)
                                 ON DUPLICATE KEY UPDATE
                                    status = 'enrolled',
                                    class_id = VALUES(class_id),
                                    updated_at = CURRENT_TIMESTAMP"
                            );
                            $dropOldClassEnrollments = $conn->prepare(
                                "UPDATE class_enrollments ce
                                 JOIN class_records cr ON cr.id = ce.class_record_id
                                 SET ce.status = 'dropped',
                                     ce.updated_at = CURRENT_TIMESTAMP
                                 WHERE ce.student_id = ?
                                   AND ce.status = 'enrolled'
                                   AND cr.subject_id = ?
                                   AND cr.academic_year = ?
                                   AND cr.semester = ?
                                   AND ce.class_record_id <> ?"
                            );
                            $updStudentSection = $conn->prepare("UPDATE students SET Section = ? WHERE id = ?");
                            if (!$insEnroll || !$insClassEnroll || !$dropOldClassEnrollments || !$updStudentSection) {
                                throw new RuntimeException('Unable to prepare bulk enrollment statements.');
                            }

                            $today = date('Y-m-d');
                            $processed = 0;
                            $enrollmentInserts = 0;
                            $enrollmentUpdates = 0;
                            $classEnrollInserts = 0;
                            $classEnrollUpdates = 0;
                            $classEnrollDrops = 0;

                            foreach ($selectedStudents as $st) {
                                $studentDbId = (int) ($st['id'] ?? 0);
                                $studentNo = (string) ($st['student_no'] ?? '');
                                if ($studentDbId <= 0 || $studentNo === '') continue;

                                $insEnroll->bind_param('sissss', $studentNo, $subjectId, $targetAcademicYear, $targetSemester, $enrollmentSection, $createdBy);
                                $insEnroll->execute();
                                $eAffected = (int) $insEnroll->affected_rows;
                                if ($eAffected === 1) {
                                    $enrollmentInserts++;
                                } elseif ($eAffected >= 2) {
                                    $enrollmentUpdates++;
                                }

                                // Re-enrolling the same subject/term in another section should close prior active class rows.
                                $dropOldClassEnrollments->bind_param('iissi', $studentDbId, $subjectId, $targetAcademicYear, $targetSemester, $classRecordId);
                                $dropOldClassEnrollments->execute();
                                $dropAffected = (int) $dropOldClassEnrollments->affected_rows;
                                if ($dropAffected > 0) {
                                    $classEnrollDrops += $dropAffected;
                                }

                                $insClassEnroll->bind_param('iisii', $classRecordId, $studentDbId, $today, $adminId, $classRecordId);
                                $insClassEnroll->execute();
                                $cAffected = (int) $insClassEnroll->affected_rows;
                                if ($cAffected === 1) {
                                    $classEnrollInserts++;
                                } elseif ($cAffected >= 2) {
                                    $classEnrollUpdates++;
                                }

                                $updStudentSection->bind_param('si', $targetSection, $studentDbId);
                                $updStudentSection->execute();
                                $processed++;
                            }

                            $insEnroll->close();
                            $insClassEnroll->close();
                            $dropOldClassEnrollments->close();
                            $updStudentSection->close();

                            $conn->commit();

                            $subjectLabel = trim((string) ($subject['subject_code'] ?? '') . ' ' . (string) ($subject['subject_name'] ?? ''));
                            $targetSectionLabel = $targetSection;
                            if ($targetCourse !== '' && $targetYearLevel !== '') {
                                $targetSectionLabel = $targetCourse . ' - ' . $targetYearLevel . ' - ' . $targetSection;
                            }
                            $mappedSectionNote = '';
                            if (strcasecmp($enrollmentSection, $targetSection) !== 0) {
                                $mappedSectionNote = ' Class section used: ' . $enrollmentSection . '.';
                            }
                            $_SESSION['flash_message'] =
                                'Bulk enrollment complete. ' .
                                'Processed ' . $processed . ' student(s) to section ' . $targetSectionLabel .
                                ' under ' . ($subjectLabel !== '' ? $subjectLabel : 'selected subject') .
                                ' (' . $targetAcademicYear . ', ' . $targetSemester . '). ' .
                                'Enrollments: ' . $enrollmentInserts . ' new, ' . $enrollmentUpdates . ' updated. ' .
                                'Class roster: ' . $classEnrollInserts . ' new, ' . $classEnrollUpdates . ' updated, ' . $classEnrollDrops . ' re-assigned.' .
                                $mappedSectionNote;
                            $_SESSION['flash_type'] = 'success';
                        } catch (Throwable $e) {
                            $conn->rollback();
                            $_SESSION['flash_message'] = 'Bulk enrollment failed: ' . $e->getMessage();
                            $_SESSION['flash_type'] = 'danger';
                        }
                    }
                }
            }
        }
    } elseif ($isStudentScopedPage && $action === 'update_student_profile') {
        $studentId = isset($_POST['student_id']) ? (int) $_POST['student_id'] : 0;
        $studentNo = trim((string) ($_POST['student_no'] ?? ''));
        $surname = trim((string) ($_POST['surname'] ?? ''));
        $firstName = trim((string) ($_POST['firstname'] ?? ''));
        $middleName = trim((string) ($_POST['middlename'] ?? ''));
        $sex = strtoupper(trim((string) ($_POST['sex'] ?? 'M')));
        $course = trim((string) ($_POST['course'] ?? ''));
        $major = trim((string) ($_POST['major'] ?? ''));
        $studentStatus = trim((string) ($_POST['student_status'] ?? 'New'));
        $yearLevel = trim((string) ($_POST['year'] ?? ''));
        $section = trim((string) ($_POST['section'] ?? ''));
        if (function_exists('ref_normalize_course_name')) {
            $course = ref_normalize_course_name($course);
        }
        if (function_exists('ref_normalize_year_level')) {
            $yearLevel = ref_normalize_year_level($yearLevel);
        }
        if (function_exists('ref_normalize_section_code')) {
            $section = ref_normalize_section_code($section);
        }
        $birthDate = trim((string) ($_POST['birth_date'] ?? ''));
        $barangay = trim((string) ($_POST['barangay'] ?? ''));
        $municipality = trim((string) ($_POST['municipality'] ?? ''));
        $province = trim((string) ($_POST['province'] ?? ''));
        $studentEmail = trim((string) ($_POST['student_email'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));

        $allowedStatuses = ['Continuing', 'New', 'Transferee'];
        if (!in_array($studentStatus, $allowedStatuses, true)) {
            $studentStatus = 'New';
        }
        if (!in_array($sex, ['M', 'F'], true)) {
            $sex = 'M';
        }

        if ($studentId <= 0) {
            $_SESSION['flash_message'] = 'Invalid student profile selected.';
            $_SESSION['flash_type'] = 'warning';
        } elseif ($studentNo === '' || $surname === '' || $firstName === '' || $course === '' || $yearLevel === '') {
            $_SESSION['flash_message'] = 'Student ID, Surname, First Name, Course, and Year are required.';
            $_SESSION['flash_type'] = 'warning';
        } elseif (strlen($studentNo) > 20) {
            $_SESSION['flash_message'] = 'Student ID is too long (max 20 characters).';
            $_SESSION['flash_type'] = 'warning';
        } elseif (strlen($surname) > 50 || strlen($firstName) > 50 || strlen($middleName) > 50) {
            $_SESSION['flash_message'] = 'Name fields exceed the maximum allowed length.';
            $_SESSION['flash_type'] = 'warning';
        } elseif (strlen($course) > 100 || strlen($major) > 100) {
            $_SESSION['flash_message'] = 'Course or Major is too long.';
            $_SESSION['flash_type'] = 'warning';
        } elseif (strlen($yearLevel) > 20 || strlen($section) > 20) {
            $_SESSION['flash_message'] = 'Year or Section is too long.';
            $_SESSION['flash_type'] = 'warning';
        } elseif (strlen($barangay) > 100 || strlen($municipality) > 100 || strlen($province) > 100) {
            $_SESSION['flash_message'] = 'Address fields exceed the maximum length.';
            $_SESSION['flash_type'] = 'warning';
        } elseif (strlen($phone) > 20) {
            $_SESSION['flash_message'] = 'Phone value is too long.';
            $_SESSION['flash_type'] = 'warning';
        } elseif ($studentEmail !== '' && !filter_var($studentEmail, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_message'] = 'Student email format is invalid.';
            $_SESSION['flash_type'] = 'warning';
        } elseif (strlen($studentEmail) > 100) {
            $_SESSION['flash_message'] = 'Student email exceeds 100 characters.';
            $_SESSION['flash_type'] = 'warning';
        } elseif ($birthDate !== '' && (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $birthDate) || strtotime($birthDate) === false)) {
            $_SESSION['flash_message'] = 'Birth date must be a valid date in YYYY-MM-DD format.';
            $_SESSION['flash_type'] = 'warning';
        } else {
            if ($adminIsSuperadmin) {
                $findStudent = $conn->prepare("SELECT id, user_id, StudentNo, campus_id FROM students WHERE id = ? LIMIT 1");
            } else {
                $findStudent = $conn->prepare("SELECT id, user_id, StudentNo, campus_id FROM students WHERE id = ? AND campus_id = ? LIMIT 1");
            }
            $studentRow = null;
            if ($findStudent) {
                if ($adminIsSuperadmin) {
                    $findStudent->bind_param('i', $studentId);
                } else {
                    $findStudent->bind_param('ii', $studentId, $adminCampusId);
                }
                $findStudent->execute();
                $studentRes = $findStudent->get_result();
                if ($studentRes && $studentRes->num_rows === 1) {
                    $studentRow = $studentRes->fetch_assoc();
                }
                $findStudent->close();
            }

            if (!$studentRow) {
                $_SESSION['flash_message'] = 'Student profile not found.';
                $_SESSION['flash_type'] = 'warning';
            } else {
                $dupNoStmt = $conn->prepare("SELECT id FROM students WHERE StudentNo = ? AND id <> ? LIMIT 1");
                $dupNoExists = false;
                if ($dupNoStmt) {
                    $dupNoStmt->bind_param('si', $studentNo, $studentId);
                    $dupNoStmt->execute();
                    $dupNoRes = $dupNoStmt->get_result();
                    $dupNoExists = $dupNoRes && $dupNoRes->num_rows > 0;
                    $dupNoStmt->close();
                }

                if ($dupNoExists) {
                    $_SESSION['flash_message'] = 'That Student ID already exists on another profile.';
                    $_SESSION['flash_type'] = 'warning';
                } else {
                    $oldStudentNo = trim((string) ($studentRow['StudentNo'] ?? ''));
                    $linkedUserId = (int) ($studentRow['user_id'] ?? 0);
                    $syncNotes = [];
                    $updated = false;

                    $conn->begin_transaction();
                    try {
                        $updStudent = $conn->prepare(
                            "UPDATE students
                             SET StudentNo = ?, Surname = ?, FirstName = ?, MiddleName = ?, Sex = ?, Course = ?, Major = ?,
                                 Status = ?, Year = ?, Section = ?, BirthDate = ?, Barangay = ?, Municipality = ?,
                                 Province = ?, email = ?, phone = ?
                             WHERE id = ?"
                        );
                        if (!$updStudent) {
                            throw new RuntimeException('Unable to prepare student update.');
                        }
                        $birthDateSql = $birthDate !== '' ? $birthDate : null;
                        $updStudent->bind_param(
                            'ssssssssssssssssi',
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
                            $birthDateSql,
                            $barangay,
                            $municipality,
                            $province,
                            $studentEmail,
                            $phone,
                            $studentId
                        );
                        if (!$updStudent->execute()) {
                            $err = $updStudent->error;
                            $updStudent->close();
                            throw new RuntimeException($err !== '' ? $err : 'Student profile update failed.');
                        }
                        $updStudent->close();

                        admin_users_sync_student_no_references($conn, $oldStudentNo, $studentNo);

                        if ($linkedUserId > 0) {
                            $syncOk = admin_users_sync_linked_account(
                                $conn,
                                $linkedUserId,
                                'student',
                                $studentNo,
                                $firstName,
                                $surname,
                                $studentEmail,
                                $syncNotes
                            );
                            if (!$syncOk) {
                                throw new RuntimeException('Linked login account sync failed.');
                            }
                        }

                        $conn->commit();
                        $updated = true;
                    } catch (Throwable $e) {
                        $conn->rollback();
                        $_SESSION['flash_message'] = 'Student profile update failed: ' . $e->getMessage();
                        $_SESSION['flash_type'] = 'danger';
                        $updated = false;
                    }

                    if ($updated) {
                        $msg = 'Student profile updated successfully.';
                        if (count($syncNotes) > 0) {
                            $msg .= ' ' . implode(' ', $syncNotes);
                        }
                        $_SESSION['flash_message'] = $msg;
                        $_SESSION['flash_type'] = 'success';
                    }
                }
            }
        }
    } elseif ($isStudentScopedPage && $userId > 0 && $action === 'link_or_create_student_profile') {
        $profileMode = strtolower(trim((string) ($_POST['profile_mode'] ?? 'create')));
        if (!in_array($profileMode, ['link', 'create'], true)) {
            $profileMode = 'create';
        }

        $studentNo = trim((string) ($_POST['student_no'] ?? ''));
        $surname = trim((string) ($_POST['surname'] ?? ''));
        $firstName = trim((string) ($_POST['firstname'] ?? ''));
        $middleName = trim((string) ($_POST['middlename'] ?? ''));
        $sex = strtoupper(trim((string) ($_POST['sex'] ?? 'M')));
        $course = trim((string) ($_POST['course'] ?? ''));
        $major = trim((string) ($_POST['major'] ?? ''));
        $yearLevel = trim((string) ($_POST['year'] ?? ''));
        $section = trim((string) ($_POST['section'] ?? ''));
        if (function_exists('ref_normalize_course_name')) {
            $course = ref_normalize_course_name($course);
        }
        if (function_exists('ref_normalize_year_level')) {
            $yearLevel = ref_normalize_year_level($yearLevel);
        }
        if (function_exists('ref_normalize_section_code')) {
            $section = ref_normalize_section_code($section);
        }
        $studentEmailInput = trim((string) ($_POST['student_email'] ?? ''));
        $studentStatus = trim((string) ($_POST['student_status'] ?? 'New'));
        $allowedStudentStatuses = ['Continuing', 'New', 'Transferee'];
        if (!in_array($studentStatus, $allowedStudentStatuses, true)) {
            $studentStatus = 'New';
        }
        if (!in_array($sex, ['M', 'F'], true)) {
            $sex = 'M';
        }

        $accountStmt = $conn->prepare("SELECT id, useremail, username, role, campus_id FROM users WHERE id = ? LIMIT 1");
        $accountRow = null;
        if ($accountStmt) {
            $accountStmt->bind_param('i', $userId);
            $accountStmt->execute();
            $accountRes = $accountStmt->get_result();
            if ($accountRes && $accountRes->num_rows === 1) {
                $accountRow = $accountRes->fetch_assoc();
            }
            $accountStmt->close();
        }

        if (!$accountRow) {
            $_SESSION['flash_message'] = 'Selected account does not exist.';
            $_SESSION['flash_type'] = 'warning';
        } else {
            $accountRoleNow = normalize_role((string) ($accountRow['role'] ?? ''));
            if (!in_array($accountRoleNow, ['student', 'user'], true)) {
                $_SESSION['flash_message'] = 'Only student accounts can be linked to student profiles.';
                $_SESSION['flash_type'] = 'warning';
            } else {
                $alreadyLinkedStmt = $conn->prepare("SELECT id, StudentNo FROM students WHERE user_id = ? LIMIT 1");
                $alreadyLinked = null;
                if ($alreadyLinkedStmt) {
                    $alreadyLinkedStmt->bind_param('i', $userId);
                    $alreadyLinkedStmt->execute();
                    $alreadyLinkedRes = $alreadyLinkedStmt->get_result();
                    if ($alreadyLinkedRes && $alreadyLinkedRes->num_rows === 1) {
                        $alreadyLinked = $alreadyLinkedRes->fetch_assoc();
                    }
                    $alreadyLinkedStmt->close();
                }

                $accountEmail = trim((string) ($accountRow['useremail'] ?? ''));
                $accountCampusId = (int) ($accountRow['campus_id'] ?? 0);
                if ($accountCampusId <= 0) $accountCampusId = $adminCampusId;
                if ($studentEmailInput === '') {
                    $studentEmailInput = $accountEmail;
                }

                if ($alreadyLinked) {
                    $alreadyStudentNo = trim((string) ($alreadyLinked['StudentNo'] ?? ''));
                    $_SESSION['flash_message'] = $alreadyStudentNo !== ''
                        ? ('This account is already linked to Student ID ' . $alreadyStudentNo . '.')
                        : 'This account is already linked to a student profile.';
                    $_SESSION['flash_type'] = 'info';
                } elseif ($studentNo === '') {
                    $_SESSION['flash_message'] = 'Student ID (Student No) is required.';
                    $_SESSION['flash_type'] = 'warning';
                } elseif (strlen($studentNo) > 20) {
                    $_SESSION['flash_message'] = 'Student ID is too long (max 20 characters).';
                    $_SESSION['flash_type'] = 'warning';
                } elseif ($profileMode === 'create') {
                    if ($surname === '' || $firstName === '') {
                        $guess = admin_users_guess_name_parts((string) ($accountRow['username'] ?? ''));
                        if ($surname === '') $surname = trim((string) ($guess['surname'] ?? ''));
                        if ($firstName === '') $firstName = trim((string) ($guess['firstname'] ?? ''));
                        if ($middleName === '') $middleName = trim((string) ($guess['middlename'] ?? ''));
                    }

                    if ($surname === '' || $firstName === '' || $course === '' || $yearLevel === '') {
                        $_SESSION['flash_message'] = 'Surname, First Name, Course, and Year are required to create a student profile.';
                        $_SESSION['flash_type'] = 'warning';
                    } elseif (strlen($surname) > 50 || strlen($firstName) > 50 || strlen($middleName) > 50) {
                        $_SESSION['flash_message'] = 'Name fields exceed maximum length.';
                        $_SESSION['flash_type'] = 'warning';
                    } elseif (strlen($course) > 100 || strlen($major) > 100) {
                        $_SESSION['flash_message'] = 'Program fields exceed maximum length.';
                        $_SESSION['flash_type'] = 'warning';
                    } elseif (strlen($yearLevel) > 20 || strlen($section) > 20) {
                        $_SESSION['flash_message'] = 'Year or Section is too long.';
                        $_SESSION['flash_type'] = 'warning';
                    } elseif ($studentEmailInput !== '' && !filter_var($studentEmailInput, FILTER_VALIDATE_EMAIL)) {
                        $_SESSION['flash_message'] = 'Student email is invalid.';
                        $_SESSION['flash_type'] = 'warning';
                    } elseif (strlen($studentEmailInput) > 100) {
                        $_SESSION['flash_message'] = 'Student email is too long.';
                        $_SESSION['flash_type'] = 'warning';
                    } else {
                        $existingByNoStmt = $conn->prepare("SELECT id, user_id FROM students WHERE StudentNo = ? LIMIT 1");
                        $existingByNo = null;
                        if ($existingByNoStmt) {
                            $existingByNoStmt->bind_param('s', $studentNo);
                            $existingByNoStmt->execute();
                            $existingByNoRes = $existingByNoStmt->get_result();
                            if ($existingByNoRes && $existingByNoRes->num_rows === 1) {
                                $existingByNo = $existingByNoRes->fetch_assoc();
                            }
                            $existingByNoStmt->close();
                        }

                        if ($existingByNo) {
                            $_SESSION['flash_message'] = 'Student ID already exists. Use Link Existing mode instead.';
                            $_SESSION['flash_type'] = 'warning';
                        } else {
                            $createdBy = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
                            if ($createdBy <= 0) $createdBy = 1;
                            $created = false;

                            $conn->begin_transaction();
                            try {
                                $insertStmt = $conn->prepare(
                                    "INSERT INTO students
                                        (user_id, campus_id, StudentNo, Surname, FirstName, MiddleName, Sex, Course, Major, Status, Year, Section, email, created_by)
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                                );
                                if (!$insertStmt) {
                                    throw new RuntimeException('Unable to prepare student profile creation.');
                                }
                                $insertStmt->bind_param(
                                    'iisssssssssssi',
                                    $userId,
                                    $accountCampusId,
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
                                    $studentEmailInput,
                                    $createdBy
                                );
                                $created = $insertStmt->execute();
                                $insertStmt->close();
                                if (!$created) {
                                    throw new RuntimeException('Student profile creation failed.');
                                }

                                $tmpNotes = [];
                                $syncOk = admin_users_sync_linked_account(
                                    $conn,
                                    $userId,
                                    'student',
                                    $studentNo,
                                    $firstName,
                                    $surname,
                                    $studentEmailInput,
                                    $tmpNotes
                                );
                                if (!$syncOk) {
                                    throw new RuntimeException('Linked student account sync failed.');
                                }
                                $conn->commit();
                            } catch (Throwable $e) {
                                $conn->rollback();
                                $_SESSION['flash_message'] = 'Unable to create student profile: ' . $e->getMessage();
                                $_SESSION['flash_type'] = 'danger';
                                $created = false;
                            }

                            if ($created) {
                                $_SESSION['flash_message'] = 'Student profile created and linked (Student ID: ' . $studentNo . ').';
                                $_SESSION['flash_type'] = 'success';
                            }
                        }
                    }
                } else {
                    if ($adminIsSuperadmin) {
                        $existingByNoStmt = $conn->prepare("SELECT id, user_id FROM students WHERE StudentNo = ? LIMIT 1");
                    } else {
                        $existingByNoStmt = $conn->prepare("SELECT id, user_id FROM students WHERE StudentNo = ? AND campus_id = ? LIMIT 1");
                    }
                    $targetStudent = null;
                    if ($existingByNoStmt) {
                        if ($adminIsSuperadmin) {
                            $existingByNoStmt->bind_param('s', $studentNo);
                        } else {
                            $existingByNoStmt->bind_param('si', $studentNo, $adminCampusId);
                        }
                        $existingByNoStmt->execute();
                        $existingByNoRes = $existingByNoStmt->get_result();
                        if ($existingByNoRes && $existingByNoRes->num_rows === 1) {
                            $targetStudent = $existingByNoRes->fetch_assoc();
                        }
                        $existingByNoStmt->close();
                    }

                    if (!$targetStudent) {
                        $_SESSION['flash_message'] = 'No student profile found with that Student ID.';
                        $_SESSION['flash_type'] = 'warning';
                    } else {
                        $linkedUserId = (int) ($targetStudent['user_id'] ?? 0);
                        if ($linkedUserId > 0 && $linkedUserId !== $userId) {
                            $_SESSION['flash_message'] = 'That student profile is already linked to another account.';
                            $_SESSION['flash_type'] = 'warning';
                        } else {
                            $linked = false;
                            $targetStudentId = (int) ($targetStudent['id'] ?? 0);
                            $conn->begin_transaction();
                            try {
                                $linkStmt = $conn->prepare(
                                    "UPDATE students
                                     SET user_id = ?,
                                         campus_id = COALESCE(campus_id, ?),
                                         email = COALESCE(NULLIF(email,''), NULLIF(?, ''))
                                     WHERE id = ?"
                                );
                                if (!$linkStmt) {
                                    throw new RuntimeException('Unable to prepare student link action.');
                                }
                                $linkStmt->bind_param('iisi', $userId, $accountCampusId, $studentEmailInput, $targetStudentId);
                                $linked = $linkStmt->execute();
                                $linkStmt->close();
                                if (!$linked) {
                                    throw new RuntimeException('Student profile link failed.');
                                }

                                $tmpNotes = [];
                                $syncFirstName = '';
                                $syncSurname = '';
                                $syncStudent = $conn->prepare("SELECT FirstName, Surname, email FROM students WHERE id = ? LIMIT 1");
                                if ($syncStudent) {
                                    $syncStudent->bind_param('i', $targetStudentId);
                                    $syncStudent->execute();
                                    $syncRes = $syncStudent->get_result();
                                    if ($syncRes && $syncRes->num_rows === 1) {
                                        $syncRow = $syncRes->fetch_assoc();
                                        $syncFirstName = trim((string) ($syncRow['FirstName'] ?? ''));
                                        $syncSurname = trim((string) ($syncRow['Surname'] ?? ''));
                                        if ($studentEmailInput === '') {
                                            $studentEmailInput = trim((string) ($syncRow['email'] ?? ''));
                                        }
                                    }
                                    $syncStudent->close();
                                }
                                $syncOk = admin_users_sync_linked_account(
                                    $conn,
                                    $userId,
                                    'student',
                                    $studentNo,
                                    $syncFirstName,
                                    $syncSurname,
                                    $studentEmailInput,
                                    $tmpNotes
                                );
                                if (!$syncOk) {
                                    throw new RuntimeException('Linked student account sync failed.');
                                }

                                $conn->commit();
                            } catch (Throwable $e) {
                                $conn->rollback();
                                $_SESSION['flash_message'] = 'Unable to link student profile: ' . $e->getMessage();
                                $_SESSION['flash_type'] = 'danger';
                                $linked = false;
                            }

                            if ($linked) {
                                $_SESSION['flash_message'] = 'Student profile linked successfully (Student ID: ' . $studentNo . ').';
                                $_SESSION['flash_type'] = 'success';
                            }
                        }
                    }
                }
            }
        }
    } elseif ($isScopedRolePage && $managedRole === 'teacher' && $action === 'update_teacher_profile') {
        $teacherId = isset($_POST['teacher_id']) ? (int) $_POST['teacher_id'] : 0;
        $teacherNo = trim((string) ($_POST['teacher_no'] ?? ''));
        $surname = trim((string) ($_POST['surname'] ?? ''));
        $firstName = trim((string) ($_POST['firstname'] ?? ''));
        $middleName = trim((string) ($_POST['middlename'] ?? ''));
        $sex = strtoupper(trim((string) ($_POST['sex'] ?? 'M')));
        $department = trim((string) ($_POST['department'] ?? ''));
        $position = trim((string) ($_POST['position'] ?? ''));
        $employmentStatus = trim((string) ($_POST['employment_status'] ?? 'Full-time'));
        $teacherStatus = trim((string) ($_POST['teacher_status'] ?? 'Active'));
        $birthDate = trim((string) ($_POST['birth_date'] ?? ''));
        $barangay = trim((string) ($_POST['barangay'] ?? ''));
        $municipality = trim((string) ($_POST['municipality'] ?? ''));
        $province = trim((string) ($_POST['province'] ?? ''));
        $teacherEmail = trim((string) ($_POST['teacher_email'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));

        $allowedEmployment = ['Full-time', 'Part-time', 'Contractual', 'Visiting'];
        $allowedTeacherStatus = ['Active', 'Inactive', 'OnLeave', 'Retired'];
        if (!in_array($employmentStatus, $allowedEmployment, true)) {
            $employmentStatus = 'Full-time';
        }
        if (!in_array($teacherStatus, $allowedTeacherStatus, true)) {
            $teacherStatus = 'Active';
        }
        if (!in_array($sex, ['M', 'F'], true)) {
            $sex = 'M';
        }

        if ($teacherId <= 0) {
            $_SESSION['flash_message'] = 'Invalid teacher profile selected.';
            $_SESSION['flash_type'] = 'warning';
        } elseif ($teacherNo === '' || $surname === '' || $firstName === '') {
            $_SESSION['flash_message'] = 'Teacher No, Surname, and First Name are required.';
            $_SESSION['flash_type'] = 'warning';
        } elseif (strlen($teacherNo) > 30) {
            $_SESSION['flash_message'] = 'Teacher No is too long (max 30 characters).';
            $_SESSION['flash_type'] = 'warning';
        } elseif (strlen($surname) > 50 || strlen($firstName) > 50 || strlen($middleName) > 50) {
            $_SESSION['flash_message'] = 'Name fields exceed maximum length.';
            $_SESSION['flash_type'] = 'warning';
        } elseif (strlen($department) > 100 || strlen($position) > 100) {
            $_SESSION['flash_message'] = 'Department or Position is too long.';
            $_SESSION['flash_type'] = 'warning';
        } elseif (strlen($barangay) > 100 || strlen($municipality) > 100 || strlen($province) > 100) {
            $_SESSION['flash_message'] = 'Address fields exceed the maximum length.';
            $_SESSION['flash_type'] = 'warning';
        } elseif (strlen($phone) > 20) {
            $_SESSION['flash_message'] = 'Phone value is too long.';
            $_SESSION['flash_type'] = 'warning';
        } elseif ($teacherEmail !== '' && !filter_var($teacherEmail, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_message'] = 'Teacher email format is invalid.';
            $_SESSION['flash_type'] = 'warning';
        } elseif (strlen($teacherEmail) > 100) {
            $_SESSION['flash_message'] = 'Teacher email exceeds 100 characters.';
            $_SESSION['flash_type'] = 'warning';
        } elseif ($birthDate !== '' && (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $birthDate) || strtotime($birthDate) === false)) {
            $_SESSION['flash_message'] = 'Birth date must be a valid date in YYYY-MM-DD format.';
            $_SESSION['flash_type'] = 'warning';
        } else {
            if ($adminIsSuperadmin) {
                $findTeacher = $conn->prepare("SELECT id, user_id, campus_id FROM teachers WHERE id = ? LIMIT 1");
            } else {
                $findTeacher = $conn->prepare("SELECT id, user_id, campus_id FROM teachers WHERE id = ? AND campus_id = ? LIMIT 1");
            }
            $teacherRow = null;
            if ($findTeacher) {
                if ($adminIsSuperadmin) {
                    $findTeacher->bind_param('i', $teacherId);
                } else {
                    $findTeacher->bind_param('ii', $teacherId, $adminCampusId);
                }
                $findTeacher->execute();
                $teacherRes = $findTeacher->get_result();
                if ($teacherRes && $teacherRes->num_rows === 1) {
                    $teacherRow = $teacherRes->fetch_assoc();
                }
                $findTeacher->close();
            }

            if (!$teacherRow) {
                $_SESSION['flash_message'] = 'Teacher profile not found.';
                $_SESSION['flash_type'] = 'warning';
            } else {
                $dupNoStmt = $conn->prepare("SELECT id FROM teachers WHERE TeacherNo = ? AND id <> ? LIMIT 1");
                $dupNoExists = false;
                if ($dupNoStmt) {
                    $dupNoStmt->bind_param('si', $teacherNo, $teacherId);
                    $dupNoStmt->execute();
                    $dupNoRes = $dupNoStmt->get_result();
                    $dupNoExists = $dupNoRes && $dupNoRes->num_rows > 0;
                    $dupNoStmt->close();
                }

                if ($dupNoExists) {
                    $_SESSION['flash_message'] = 'That Teacher No already exists on another profile.';
                    $_SESSION['flash_type'] = 'warning';
                } else {
                    $linkedUserId = (int) ($teacherRow['user_id'] ?? 0);
                    $syncNotes = [];
                    $updated = false;

                    $conn->begin_transaction();
                    try {
                        $updTeacher = $conn->prepare(
                            "UPDATE teachers
                             SET TeacherNo = ?, Surname = ?, FirstName = ?, MiddleName = ?, Sex = ?, Department = ?, Position = ?,
                                 EmploymentStatus = ?, Status = ?, BirthDate = ?, Barangay = ?, Municipality = ?,
                                 Province = ?, email = ?, phone = ?
                             WHERE id = ?"
                        );
                        if (!$updTeacher) {
                            throw new RuntimeException('Unable to prepare teacher update.');
                        }
                        $birthDateSql = $birthDate !== '' ? $birthDate : null;
                        $updTeacher->bind_param(
                            'sssssssssssssssi',
                            $teacherNo,
                            $surname,
                            $firstName,
                            $middleName,
                            $sex,
                            $department,
                            $position,
                            $employmentStatus,
                            $teacherStatus,
                            $birthDateSql,
                            $barangay,
                            $municipality,
                            $province,
                            $teacherEmail,
                            $phone,
                            $teacherId
                        );
                        if (!$updTeacher->execute()) {
                            $err = $updTeacher->error;
                            $updTeacher->close();
                            throw new RuntimeException($err !== '' ? $err : 'Teacher profile update failed.');
                        }
                        $updTeacher->close();

                        if ($linkedUserId > 0) {
                            $syncOk = admin_users_sync_linked_account(
                                $conn,
                                $linkedUserId,
                                'teacher',
                                $teacherNo,
                                $firstName,
                                $surname,
                                $teacherEmail,
                                $syncNotes
                            );
                            if (!$syncOk) {
                                throw new RuntimeException('Linked teacher account sync failed.');
                            }
                        }

                        $conn->commit();
                        $updated = true;
                    } catch (Throwable $e) {
                        $conn->rollback();
                        $_SESSION['flash_message'] = 'Teacher profile update failed: ' . $e->getMessage();
                        $_SESSION['flash_type'] = 'danger';
                        $updated = false;
                    }

                    if ($updated) {
                        $msg = 'Teacher profile updated successfully.';
                        if (count($syncNotes) > 0) {
                            $msg .= ' ' . implode(' ', $syncNotes);
                        }
                        $_SESSION['flash_message'] = $msg;
                        $_SESSION['flash_type'] = 'success';
                    }
                }
            }
        }
    } elseif ($isScopedRolePage && $managedRole === 'teacher' && $userId > 0 && $action === 'link_or_create_teacher_profile') {
        $profileMode = strtolower(trim((string) ($_POST['profile_mode'] ?? 'create')));
        if (!in_array($profileMode, ['link', 'create'], true)) {
            $profileMode = 'create';
        }

        $teacherNo = trim((string) ($_POST['teacher_no'] ?? ''));
        $surname = trim((string) ($_POST['surname'] ?? ''));
        $firstName = trim((string) ($_POST['firstname'] ?? ''));
        $middleName = trim((string) ($_POST['middlename'] ?? ''));
        $sex = strtoupper(trim((string) ($_POST['sex'] ?? 'M')));
        $department = trim((string) ($_POST['department'] ?? ''));
        $position = trim((string) ($_POST['position'] ?? ''));
        $employmentStatus = trim((string) ($_POST['employment_status'] ?? 'Full-time'));
        $teacherStatus = trim((string) ($_POST['teacher_status'] ?? 'Active'));
        $birthDate = trim((string) ($_POST['birth_date'] ?? ''));
        $barangay = trim((string) ($_POST['barangay'] ?? ''));
        $municipality = trim((string) ($_POST['municipality'] ?? ''));
        $province = trim((string) ($_POST['province'] ?? ''));
        $teacherEmailInput = trim((string) ($_POST['teacher_email'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));

        $allowedEmployment = ['Full-time', 'Part-time', 'Contractual', 'Visiting'];
        $allowedTeacherStatus = ['Active', 'Inactive', 'OnLeave', 'Retired'];
        if (!in_array($employmentStatus, $allowedEmployment, true)) $employmentStatus = 'Full-time';
        if (!in_array($teacherStatus, $allowedTeacherStatus, true)) $teacherStatus = 'Active';
        if (!in_array($sex, ['M', 'F'], true)) $sex = 'M';

        $accountStmt = $conn->prepare("SELECT id, useremail, username, role, campus_id FROM users WHERE id = ? LIMIT 1");
        $accountRow = null;
        if ($accountStmt) {
            $accountStmt->bind_param('i', $userId);
            $accountStmt->execute();
            $accountRes = $accountStmt->get_result();
            if ($accountRes && $accountRes->num_rows === 1) {
                $accountRow = $accountRes->fetch_assoc();
            }
            $accountStmt->close();
        }

        if (!$accountRow) {
            $_SESSION['flash_message'] = 'Selected account does not exist.';
            $_SESSION['flash_type'] = 'warning';
        } else {
            $accountRoleNow = normalize_role((string) ($accountRow['role'] ?? ''));
            if ($accountRoleNow !== 'teacher') {
                $_SESSION['flash_message'] = 'Only teacher accounts can be linked to teacher profiles.';
                $_SESSION['flash_type'] = 'warning';
            } else {
                $accountCampusId = (int) ($accountRow['campus_id'] ?? 0);
                if ($accountCampusId <= 0) $accountCampusId = $adminCampusId;
                $alreadyLinkedStmt = $conn->prepare("SELECT id, TeacherNo FROM teachers WHERE user_id = ? LIMIT 1");
                $alreadyLinked = null;
                if ($alreadyLinkedStmt) {
                    $alreadyLinkedStmt->bind_param('i', $userId);
                    $alreadyLinkedStmt->execute();
                    $alreadyLinkedRes = $alreadyLinkedStmt->get_result();
                    if ($alreadyLinkedRes && $alreadyLinkedRes->num_rows === 1) {
                        $alreadyLinked = $alreadyLinkedRes->fetch_assoc();
                    }
                    $alreadyLinkedStmt->close();
                }

                if ($teacherEmailInput === '') {
                    $teacherEmailInput = trim((string) ($accountRow['useremail'] ?? ''));
                }

                if ($alreadyLinked) {
                    $alreadyTeacherNo = trim((string) ($alreadyLinked['TeacherNo'] ?? ''));
                    $_SESSION['flash_message'] = $alreadyTeacherNo !== ''
                        ? ('This account is already linked to Teacher No ' . $alreadyTeacherNo . '.')
                        : 'This account is already linked to a teacher profile.';
                    $_SESSION['flash_type'] = 'info';
                } elseif ($teacherNo === '') {
                    $_SESSION['flash_message'] = 'Teacher No is required.';
                    $_SESSION['flash_type'] = 'warning';
                } elseif (strlen($teacherNo) > 30) {
                    $_SESSION['flash_message'] = 'Teacher No is too long (max 30 characters).';
                    $_SESSION['flash_type'] = 'warning';
                } elseif ($teacherEmailInput !== '' && !filter_var($teacherEmailInput, FILTER_VALIDATE_EMAIL)) {
                    $_SESSION['flash_message'] = 'Teacher email format is invalid.';
                    $_SESSION['flash_type'] = 'warning';
                } elseif (strlen($teacherEmailInput) > 100) {
                    $_SESSION['flash_message'] = 'Teacher email exceeds 100 characters.';
                    $_SESSION['flash_type'] = 'warning';
                } elseif ($birthDate !== '' && (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $birthDate) || strtotime($birthDate) === false)) {
                    $_SESSION['flash_message'] = 'Birth date must be a valid date in YYYY-MM-DD format.';
                    $_SESSION['flash_type'] = 'warning';
                } elseif ($profileMode === 'create') {
                    if ($surname === '' || $firstName === '') {
                        $guess = admin_users_guess_name_parts((string) ($accountRow['username'] ?? ''));
                        if ($surname === '') $surname = trim((string) ($guess['surname'] ?? ''));
                        if ($firstName === '') $firstName = trim((string) ($guess['firstname'] ?? ''));
                        if ($middleName === '') $middleName = trim((string) ($guess['middlename'] ?? ''));
                    }

                    if ($surname === '' || $firstName === '') {
                        $_SESSION['flash_message'] = 'Surname and First Name are required to create a teacher profile.';
                        $_SESSION['flash_type'] = 'warning';
                    } elseif (strlen($surname) > 50 || strlen($firstName) > 50 || strlen($middleName) > 50) {
                        $_SESSION['flash_message'] = 'Name fields exceed maximum length.';
                        $_SESSION['flash_type'] = 'warning';
                    } elseif (strlen($department) > 100 || strlen($position) > 100) {
                        $_SESSION['flash_message'] = 'Department or Position is too long.';
                        $_SESSION['flash_type'] = 'warning';
                    } elseif (strlen($barangay) > 100 || strlen($municipality) > 100 || strlen($province) > 100) {
                        $_SESSION['flash_message'] = 'Address fields exceed the maximum length.';
                        $_SESSION['flash_type'] = 'warning';
                    } elseif (strlen($phone) > 20) {
                        $_SESSION['flash_message'] = 'Phone value is too long.';
                        $_SESSION['flash_type'] = 'warning';
                    } else {
                        $existingByNoStmt = $conn->prepare("SELECT id FROM teachers WHERE TeacherNo = ? LIMIT 1");
                        $existingByNo = null;
                        if ($existingByNoStmt) {
                            $existingByNoStmt->bind_param('s', $teacherNo);
                            $existingByNoStmt->execute();
                            $existingByNoRes = $existingByNoStmt->get_result();
                            if ($existingByNoRes && $existingByNoRes->num_rows === 1) {
                                $existingByNo = $existingByNoRes->fetch_assoc();
                            }
                            $existingByNoStmt->close();
                        }

                        if ($existingByNo) {
                            $_SESSION['flash_message'] = 'Teacher No already exists. Use Link Existing mode instead.';
                            $_SESSION['flash_type'] = 'warning';
                        } else {
                            $createdBy = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
                            if ($createdBy <= 0) $createdBy = 1;
                            $syncNotes = [];
                            $created = false;

                            $conn->begin_transaction();
                            try {
                                $insTeacher = $conn->prepare(
                                    "INSERT INTO teachers
                                        (user_id, campus_id, TeacherNo, Surname, FirstName, MiddleName, Sex, Department, Position, EmploymentStatus,
                                         Status, BirthDate, Barangay, Municipality, Province, email, phone, created_by)
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                                );
                                if (!$insTeacher) {
                                    throw new RuntimeException('Unable to prepare teacher profile creation.');
                                }
                                $birthDateSql = $birthDate !== '' ? $birthDate : null;
                                $insTeacher->bind_param(
                                    'iisssssssssssssssi',
                                    $userId,
                                    $accountCampusId,
                                    $teacherNo,
                                    $surname,
                                    $firstName,
                                    $middleName,
                                    $sex,
                                    $department,
                                    $position,
                                    $employmentStatus,
                                    $teacherStatus,
                                    $birthDateSql,
                                    $barangay,
                                    $municipality,
                                    $province,
                                    $teacherEmailInput,
                                    $phone,
                                    $createdBy
                                );
                                if (!$insTeacher->execute()) {
                                    $err = $insTeacher->error;
                                    $insTeacher->close();
                                    throw new RuntimeException($err !== '' ? $err : 'Teacher profile creation failed.');
                                }
                                $insTeacher->close();

                                $syncOk = admin_users_sync_linked_account(
                                    $conn,
                                    $userId,
                                    'teacher',
                                    $teacherNo,
                                    $firstName,
                                    $surname,
                                    $teacherEmailInput,
                                    $syncNotes
                                );
                                if (!$syncOk) {
                                    throw new RuntimeException('Linked teacher account sync failed.');
                                }

                                $conn->commit();
                                $created = true;
                            } catch (Throwable $e) {
                                $conn->rollback();
                                $_SESSION['flash_message'] = 'Unable to create teacher profile: ' . $e->getMessage();
                                $_SESSION['flash_type'] = 'danger';
                                $created = false;
                            }

                            if ($created) {
                                $msg = 'Teacher profile created and linked (Teacher No: ' . $teacherNo . ').';
                                if (count($syncNotes) > 0) {
                                    $msg .= ' ' . implode(' ', $syncNotes);
                                }
                                $_SESSION['flash_message'] = $msg;
                                $_SESSION['flash_type'] = 'success';
                            }
                        }
                    }
                } else {
                    if ($adminIsSuperadmin) {
                        $existingByNoStmt = $conn->prepare(
                            "SELECT id, user_id, Surname, FirstName, MiddleName, email
                             FROM teachers
                             WHERE TeacherNo = ?
                             LIMIT 1"
                        );
                    } else {
                        $existingByNoStmt = $conn->prepare(
                            "SELECT id, user_id, Surname, FirstName, MiddleName, email
                             FROM teachers
                             WHERE TeacherNo = ?
                               AND campus_id = ?
                             LIMIT 1"
                        );
                    }
                    $targetTeacher = null;
                    if ($existingByNoStmt) {
                        if ($adminIsSuperadmin) {
                            $existingByNoStmt->bind_param('s', $teacherNo);
                        } else {
                            $existingByNoStmt->bind_param('si', $teacherNo, $adminCampusId);
                        }
                        $existingByNoStmt->execute();
                        $existingByNoRes = $existingByNoStmt->get_result();
                        if ($existingByNoRes && $existingByNoRes->num_rows === 1) {
                            $targetTeacher = $existingByNoRes->fetch_assoc();
                        }
                        $existingByNoStmt->close();
                    }

                    if (!$targetTeacher) {
                        $_SESSION['flash_message'] = 'No teacher profile found with that Teacher No.';
                        $_SESSION['flash_type'] = 'warning';
                    } else {
                        $linkedUserId = (int) ($targetTeacher['user_id'] ?? 0);
                        if ($linkedUserId > 0 && $linkedUserId !== $userId) {
                            $_SESSION['flash_message'] = 'That teacher profile is already linked to another account.';
                            $_SESSION['flash_type'] = 'warning';
                        } else {
                            $linked = false;
                            $targetTeacherId = (int) ($targetTeacher['id'] ?? 0);
                            $syncNotes = [];
                            $teacherFirstName = trim((string) ($targetTeacher['FirstName'] ?? ''));
                            $teacherSurname = trim((string) ($targetTeacher['Surname'] ?? ''));
                            $teacherEmailForSync = $teacherEmailInput !== '' ? $teacherEmailInput : trim((string) ($targetTeacher['email'] ?? ''));

                            $conn->begin_transaction();
                            try {
                                $linkStmt = $conn->prepare(
                                    "UPDATE teachers
                                     SET user_id = ?,
                                         campus_id = COALESCE(campus_id, ?),
                                         email = COALESCE(NULLIF(email,''), NULLIF(?, ''))
                                     WHERE id = ?"
                                );
                                if (!$linkStmt) {
                                    throw new RuntimeException('Unable to prepare teacher link action.');
                                }
                                $linkStmt->bind_param('iisi', $userId, $accountCampusId, $teacherEmailInput, $targetTeacherId);
                                $linked = $linkStmt->execute();
                                $linkStmt->close();
                                if (!$linked) {
                                    throw new RuntimeException('Teacher profile link failed.');
                                }

                                $syncOk = admin_users_sync_linked_account(
                                    $conn,
                                    $userId,
                                    'teacher',
                                    $teacherNo,
                                    $teacherFirstName,
                                    $teacherSurname,
                                    $teacherEmailForSync,
                                    $syncNotes
                                );
                                if (!$syncOk) {
                                    throw new RuntimeException('Linked teacher account sync failed.');
                                }

                                $conn->commit();
                            } catch (Throwable $e) {
                                $conn->rollback();
                                $_SESSION['flash_message'] = 'Unable to link teacher profile: ' . $e->getMessage();
                                $_SESSION['flash_type'] = 'danger';
                                $linked = false;
                            }

                            if ($linked) {
                                $msg = 'Teacher profile linked successfully (Teacher No: ' . $teacherNo . ').';
                                if (count($syncNotes) > 0) {
                                    $msg .= ' ' . implode(' ', $syncNotes);
                                }
                                $_SESSION['flash_message'] = $msg;
                                $_SESSION['flash_type'] = 'success';
                            }
                        }
                    }
                }
            }
        }
    } elseif ($action === 'create_user') {
        $name = isset($_POST['username']) ? trim((string) $_POST['username']) : '';
        $email = isset($_POST['useremail']) ? trim((string) $_POST['useremail']) : '';
        $role = $isScopedRolePage
            ? $managedRole
            : (isset($_POST['role']) ? normalize_role((string) $_POST['role']) : 'student');
        $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
        $campusId = $adminIsSuperadmin ? $postedCampusId : $adminCampusId;
        if ($campusId <= 0) $campusId = $defaultCampusId;
        $isActive = isset($_POST['is_active']) ? (int) $_POST['is_active'] : 0;
        $isActive = $isActive === 1 ? 1 : 0;
        $legacyStatus = $isActive === 1 ? 'active' : 'inactive';

        if ($name === '' || $email === '' || $password === '') {
            $_SESSION['flash_message'] = 'Name, email, and password are required.';
            $_SESSION['flash_type'] = 'warning';
        } elseif (!$adminIsSuperadmin && $role === 'admin') {
            $_SESSION['flash_message'] = 'Only superadmin can create campus admin accounts.';
            $_SESSION['flash_type'] = 'danger';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_message'] = 'Please enter a valid email address.';
            $_SESSION['flash_type'] = 'warning';
        } elseif (strlen($password) < 8) {
            $_SESSION['flash_message'] = 'Password must be at least 8 characters.';
            $_SESSION['flash_type'] = 'warning';
        } elseif (!in_array($role, $availableRoles, true)) {
            $_SESSION['flash_message'] = 'Invalid role selected.';
            $_SESSION['flash_type'] = 'danger';
        } elseif ($campusId <= 0 || !isset($campusOptionsById[$campusId])) {
            $_SESSION['flash_message'] = 'A valid campus is required.';
            $_SESSION['flash_type'] = 'warning';
        } elseif ($role === 'admin' && admin_users_existing_campus_admin_id($conn, $campusId) > 0) {
            $_SESSION['flash_message'] = 'This campus already has an assigned admin.';
            $_SESSION['flash_type'] = 'warning';
        } else {
            $dup = $conn->prepare("SELECT id FROM users WHERE useremail = ? LIMIT 1");
            if (!$dup) {
                $_SESSION['flash_message'] = 'Unable to validate email uniqueness.';
                $_SESSION['flash_type'] = 'danger';
            } else {
                $dup->bind_param('s', $email);
                $dup->execute();
                $dupRes = $dup->get_result();
                $exists = $dupRes && $dupRes->num_rows > 0;
                $dup->close();

                if ($exists) {
                    $_SESSION['flash_message'] = 'Email already exists.';
                    $_SESSION['flash_type'] = 'warning';
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $mustChangePassword = $role === 'student' ? 1 : 0;
                    $stmt = $conn->prepare(
                        "INSERT INTO users (useremail, username, password, role, campus_id, is_superadmin, is_active, status, must_change_password)
                         VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?)"
                    );
                    if (!$stmt) {
                        $_SESSION['flash_message'] = 'User creation failed. Please try again.';
                        $_SESSION['flash_type'] = 'danger';
                    } else {
                        $stmt->bind_param('ssssiisi', $email, $name, $hash, $role, $campusId, $isActive, $legacyStatus, $mustChangePassword);
                        if ($stmt->execute()) {
                            $_SESSION['flash_message'] = 'Account created successfully.';
                            $_SESSION['flash_type'] = 'success';
                        } else {
                            $_SESSION['flash_message'] = 'User creation failed. Please try again.';
                            $_SESSION['flash_type'] = 'danger';
                        }
                        $stmt->close();
                    }
                }
            }
        }
    } elseif ($userId > 0 && $action === 'edit_user') {
        $name = isset($_POST['username']) ? trim((string) $_POST['username']) : '';
        $email = isset($_POST['useremail']) ? trim((string) $_POST['useremail']) : '';
        $role = $isScopedRolePage
            ? $managedRole
            : (isset($_POST['role']) ? normalize_role((string) $_POST['role']) : '');
        $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
        $targetScopeForEdit = admin_users_fetch_target_scope($conn, $userId);
        $campusId = $adminIsSuperadmin ? $postedCampusId : $adminCampusId;
        if ($campusId <= 0 && is_array($targetScopeForEdit)) {
            $campusId = (int) ($targetScopeForEdit['campus_id'] ?? 0);
        }
        if ($campusId <= 0) $campusId = $defaultCampusId;
        $isActive = isset($_POST['is_active']) ? (int) $_POST['is_active'] : 0;
        $isActive = $isActive === 1 ? 1 : 0;
        $legacyStatus = $isActive === 1 ? 'active' : 'inactive';

        if ($name === '' || $email === '') {
            $_SESSION['flash_message'] = 'Name and email are required.';
            $_SESSION['flash_type'] = 'warning';
        } elseif (!$adminIsSuperadmin && $role === 'admin') {
            $_SESSION['flash_message'] = 'Only superadmin can assign admin role.';
            $_SESSION['flash_type'] = 'danger';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_message'] = 'Please enter a valid email address.';
            $_SESSION['flash_type'] = 'warning';
        } elseif (!in_array($role, $availableRoles, true)) {
            $_SESSION['flash_message'] = 'Invalid role selected.';
            $_SESSION['flash_type'] = 'danger';
        } elseif ($campusId <= 0 || !isset($campusOptionsById[$campusId])) {
            $_SESSION['flash_message'] = 'A valid campus is required.';
            $_SESSION['flash_type'] = 'warning';
        } elseif ($role === 'admin' && admin_users_existing_campus_admin_id($conn, $campusId, $userId) > 0) {
            $_SESSION['flash_message'] = 'This campus already has an assigned admin.';
            $_SESSION['flash_type'] = 'warning';
        } elseif ($password !== '' && strlen($password) < 8) {
            $_SESSION['flash_message'] = 'If provided, password must be at least 8 characters.';
            $_SESSION['flash_type'] = 'warning';
        } else {
            if ($isScopedRolePage && $managedRole === 'student') {
                $target = $conn->prepare("SELECT id FROM users WHERE id = ? AND role IN ('student', 'user') LIMIT 1");
            } elseif ($isScopedRolePage) {
                $target = $conn->prepare("SELECT id FROM users WHERE id = ? AND role = ? LIMIT 1");
            } else {
                $target = $conn->prepare("SELECT id FROM users WHERE id = ? AND is_superadmin = 0 LIMIT 1");
            }
            if (!$target) {
                $_SESSION['flash_message'] = 'User update failed. Please try again.';
                $_SESSION['flash_type'] = 'danger';
            } else {
                if ($isScopedRolePage && $managedRole === 'student') {
                    $target->bind_param('i', $userId);
                } elseif ($isScopedRolePage) {
                    $target->bind_param('is', $userId, $managedRole);
                } else {
                    $target->bind_param('i', $userId);
                }
                $target->execute();
                $targetRes = $target->get_result();
                $foundTarget = $targetRes && $targetRes->num_rows === 1;
                $target->close();

                if (!$foundTarget) {
                    $_SESSION['flash_message'] = 'User not found or cannot be edited.';
                    $_SESSION['flash_type'] = 'danger';
                } else {
                    $dup = $conn->prepare("SELECT id FROM users WHERE useremail = ? AND id <> ? LIMIT 1");
                    if (!$dup) {
                        $_SESSION['flash_message'] = 'Unable to validate email uniqueness.';
                        $_SESSION['flash_type'] = 'danger';
                    } else {
                        $dup->bind_param('si', $email, $userId);
                        $dup->execute();
                        $dupRes = $dup->get_result();
                        $exists = $dupRes && $dupRes->num_rows > 0;
                        $dup->close();

                        if ($exists) {
                            $_SESSION['flash_message'] = 'Email is already used by another account.';
                            $_SESSION['flash_type'] = 'warning';
                        } else {
                            if ($password !== '') {
                                $hash = password_hash($password, PASSWORD_DEFAULT);
                                $mustChangePassword = $role === 'student' ? 1 : 0;
                                $stmt = $conn->prepare(
                                    "UPDATE users
                                     SET username = ?,
                                         useremail = ?,
                                         role = ?,
                                         campus_id = ?,
                                         is_superadmin = 0,
                                         is_active = ?,
                                         status = ?,
                                         password = ?,
                                         must_change_password = CASE WHEN ? = 1 THEN 1 ELSE must_change_password END
                                     WHERE id = ? AND is_superadmin = 0"
                                );
                                if (!$stmt) {
                                    $_SESSION['flash_message'] = 'User update failed. Please try again.';
                                    $_SESSION['flash_type'] = 'danger';
                                } else {
                                    $stmt->bind_param('sssiissii', $name, $email, $role, $campusId, $isActive, $legacyStatus, $hash, $mustChangePassword, $userId);
                                    if ($stmt->execute()) {
                                        $_SESSION['flash_message'] = 'Account updated successfully.';
                                        $_SESSION['flash_type'] = 'success';
                                    } else {
                                        $_SESSION['flash_message'] = 'User update failed. Please try again.';
                                        $_SESSION['flash_type'] = 'danger';
                                    }
                                    $stmt->close();
                                }
                            } else {
                                $stmt = $conn->prepare(
                                    "UPDATE users
                                     SET username = ?,
                                         useremail = ?,
                                         role = ?,
                                         campus_id = ?,
                                         is_superadmin = 0,
                                         is_active = ?,
                                         status = ?
                                     WHERE id = ? AND is_superadmin = 0"
                                );
                                if (!$stmt) {
                                    $_SESSION['flash_message'] = 'User update failed. Please try again.';
                                    $_SESSION['flash_type'] = 'danger';
                                } else {
                                    $stmt->bind_param('sssiisi', $name, $email, $role, $campusId, $isActive, $legacyStatus, $userId);
                                    if ($stmt->execute()) {
                                        $_SESSION['flash_message'] = 'Account updated successfully.';
                                        $_SESSION['flash_type'] = 'success';
                                    } else {
                                        $_SESSION['flash_message'] = 'User update failed. Please try again.';
                                        $_SESSION['flash_type'] = 'danger';
                                    }
                                    $stmt->close();
                                }
                            }
                        }
                    }
                }
            }
        }
    } elseif ($userId > 0 && in_array($action, ['approve', 'revoke'], true)) {
        $newStatus = $action === 'approve' ? 1 : 0;
        // Keep both legacy `status` and app `is_active` in sync (DB triggers may enforce this).
        $stmt = $conn->prepare("UPDATE users SET is_active = ?, status = ? WHERE id = ? AND is_superadmin = 0");
        if ($stmt) {
            $newLegacyStatus = $newStatus === 1 ? 'active' : 'inactive';
            $stmt->bind_param("isi", $newStatus, $newLegacyStatus, $userId);
            if ($stmt->execute()) {
                // Option B: approving an account also activates their pending enrollments (if student record is linked).
                if ($newStatus === 1) {
                    $studentNo = null;
                    $findStudent = $conn->prepare("SELECT StudentNo FROM students WHERE user_id = ? LIMIT 1");
                    if ($findStudent) {
                        $findStudent->bind_param("i", $userId);
                        $findStudent->execute();
                        $sr = $findStudent->get_result();
                        if ($sr && $sr->num_rows === 1) {
                            $studentNo = $sr->fetch_assoc()['StudentNo'];
                        }
                        $findStudent->close();
                    }

                    if ($studentNo) {
                        $updEnroll = $conn->prepare("UPDATE enrollments SET status = 'Active' WHERE student_no = ? AND status = 'Pending'");
                        if ($updEnroll) {
                            $updEnroll->bind_param("s", $studentNo);
                            $updEnroll->execute();
                            $updEnroll->close();
                        }
                    }
                }

                $_SESSION['flash_message'] = $newStatus === 1 ? 'Account access granted.' : 'Account access revoked.';
                $_SESSION['flash_type'] = 'success';
            } else {
                $_SESSION['flash_message'] = 'Update failed. Please try again.';
                $_SESSION['flash_type'] = 'danger';
            }
            $stmt->close();
        } else {
            $_SESSION['flash_message'] = 'Update failed. Please try again.';
            $_SESSION['flash_type'] = 'danger';
        }
    } elseif (!$isScopedRolePage && $userId > 0 && $action === 'set_role') {
        $role = isset($_POST['role']) ? trim($_POST['role']) : '';
        $role = normalize_role($role);
        if (!in_array($role, $availableRoles, true)) {
            $_SESSION['flash_message'] = 'Invalid role selected.';
            $_SESSION['flash_type'] = 'danger';
        } elseif (!$adminIsSuperadmin && $role === 'admin') {
            $_SESSION['flash_message'] = 'Only superadmin can assign admin role.';
            $_SESSION['flash_type'] = 'danger';
        } else {
            // Policy: only allow role changes after approval to reduce confusion and accidental privilege staging.
            $chk = $conn->prepare("SELECT is_active, role, campus_id, is_superadmin FROM users WHERE id = ? LIMIT 1");
            if ($chk) {
                $chk->bind_param("i", $userId);
                $chk->execute();
                $row = $chk->get_result()->fetch_assoc();
                $chk->close();

                $isActiveNow = isset($row['is_active']) ? (int) $row['is_active'] : 0;
                $roleNow = isset($row['role']) ? normalize_role((string) $row['role']) : '';
                $campusIdNow = isset($row['campus_id']) ? (int) $row['campus_id'] : 0;
                $isSuperadminNow = ((int) ($row['is_superadmin'] ?? 0) === 1);

                if ($isSuperadminNow) {
                    $_SESSION['flash_message'] = 'Cannot change superadmin role here.';
                    $_SESSION['flash_type'] = 'danger';
                } elseif ($roleNow === 'admin' && !$adminIsSuperadmin) {
                    $_SESSION['flash_message'] = 'Campus admins cannot change admin roles.';
                    $_SESSION['flash_type'] = 'danger';
                } elseif ($isActiveNow !== 1) {
                    $_SESSION['flash_message'] = 'Approve the account first before changing role.';
                    $_SESSION['flash_type'] = 'warning';
                } elseif ($role === 'admin' && $campusIdNow <= 0) {
                    $_SESSION['flash_message'] = 'The account needs a campus before it can become campus admin.';
                    $_SESSION['flash_type'] = 'warning';
                } elseif ($role === 'admin' && admin_users_existing_campus_admin_id($conn, $campusIdNow, $userId) > 0) {
                    $_SESSION['flash_message'] = 'This campus already has an assigned admin.';
                    $_SESSION['flash_type'] = 'warning';
                } else {
                    $stmt = $conn->prepare("UPDATE users SET role = ?, is_superadmin = 0 WHERE id = ? AND is_superadmin = 0");
                    if ($stmt) {
                        $stmt->bind_param("si", $role, $userId);
                        if ($stmt->execute()) {
                            $_SESSION['flash_message'] = 'Role updated.';
                            $_SESSION['flash_type'] = 'success';
                        } else {
                            $_SESSION['flash_message'] = 'Role update failed. Please try again.';
                            $_SESSION['flash_type'] = 'danger';
                        }
                        $stmt->close();
                    } else {
                        $_SESSION['flash_message'] = 'Role update failed. Please try again.';
                        $_SESSION['flash_type'] = 'danger';
                    }
                }
            } else {
                $_SESSION['flash_message'] = 'Role update failed. Please try again.';
                $_SESSION['flash_type'] = 'danger';
            }
        }
    } elseif ($userId > 0 && $action === 'set_build_limit') {
        $limit = isset($_POST['class_record_build_limit']) ? (int) $_POST['class_record_build_limit'] : 1;
        if ($limit < 0) $limit = 0;
        if ($limit > 50) $limit = 50;

        // Only teachers can have Class Record Build limits.
        $beforeLimit = null;
        $beforeUsed = null;
        $beforeStmt = $conn->prepare(
            "SELECT class_record_build_limit, class_record_build_usage_used
             FROM users
             WHERE id = ?
               AND role = 'teacher'
             LIMIT 1"
        );
        if (!$beforeStmt) {
            $_SESSION['flash_message'] = 'Update failed. Please try again.';
            $_SESSION['flash_type'] = 'danger';
        } else {
            $beforeStmt->bind_param('i', $userId);
            $beforeStmt->execute();
            $beforeRes = $beforeStmt->get_result();
            $beforeRow = ($beforeRes && $beforeRes->num_rows === 1) ? $beforeRes->fetch_assoc() : null;
            $beforeStmt->close();

            if (!$beforeRow) {
                $_SESSION['flash_message'] = 'Teacher account not found.';
                $_SESSION['flash_type'] = 'warning';
            } else {
                $beforeLimit = (int) ($beforeRow['class_record_build_limit'] ?? 0);
                $beforeUsed = (int) ($beforeRow['class_record_build_usage_used'] ?? 0);
                if ($beforeUsed < 0) $beforeUsed = 0;

                $stmt = $conn->prepare("UPDATE users SET class_record_build_limit = ? WHERE id = ? AND role = 'teacher'");
                if ($stmt) {
                    $stmt->bind_param('ii', $limit, $userId);
                    if ($stmt->execute()) {
                        if (function_exists('usage_limit_log_event')) {
                            usage_limit_log_event(
                                $conn,
                                $userId,
                                'build_limit_set',
                                'build',
                                $limit - $beforeLimit,
                                $beforeLimit,
                                $limit,
                                $limit,
                                'Class Record Build limit updated.',
                                ['build_usage_used' => $beforeUsed]
                            );
                        }
                        $_SESSION['flash_message'] = 'Class Record Build limit updated.';
                        $_SESSION['flash_type'] = 'success';
                    } else {
                        $_SESSION['flash_message'] = 'Update failed. Please try again.';
                        $_SESSION['flash_type'] = 'danger';
                    }
                    $stmt->close();
                } else {
                    $_SESSION['flash_message'] = 'Update failed. Please try again.';
                    $_SESSION['flash_type'] = 'danger';
                }
            }
        }
    } elseif ($userId > 0 && $action === 'set_ai_credit_limit') {
        $limit = isset($_POST['ai_rephrase_credit_limit']) ? (float) $_POST['ai_rephrase_credit_limit'] : ai_credit_hard_default_limit();
        $limit = ai_credit_clamp_limit($limit);
        [$okCredit, $creditMsg] = ai_credit_set_user_limit($conn, $userId, $limit);
        if ($okCredit) {
            $_SESSION['flash_message'] = 'AI re-phrase credit limit updated.';
            $_SESSION['flash_type'] = 'success';
        } else {
            $_SESSION['flash_message'] = is_string($creditMsg) ? $creditMsg : 'Update failed. Please try again.';
            $_SESSION['flash_type'] = 'danger';
        }
    } elseif ($userId > 0 && in_array($action, ['refresh_ai_usage', 'refresh_build_usage', 'refresh_usage_all'], true)) {
        $roleNow = '';
        $roleStmt = $conn->prepare("SELECT role FROM users WHERE id = ? AND role <> 'admin' LIMIT 1");
        if ($roleStmt) {
            $roleStmt->bind_param('i', $userId);
            $roleStmt->execute();
            $roleRes = $roleStmt->get_result();
            if ($roleRes && $roleRes->num_rows === 1) {
                $roleNow = normalize_role((string) ($roleRes->fetch_assoc()['role'] ?? ''));
            }
            $roleStmt->close();
        }
        if ($roleNow === '') {
            $_SESSION['flash_message'] = 'User not found or not allowed.';
            $_SESSION['flash_type'] = 'warning';
        } else {
            $actorId = function_exists('usage_limit_actor_user_id') ? usage_limit_actor_user_id() : null;
            if ($action === 'refresh_ai_usage') {
                [$okRefresh, $refreshMsg] = usage_limit_refresh_ai_usage($conn, $userId, $actorId);
                $_SESSION['flash_message'] = is_string($refreshMsg) ? $refreshMsg : ($okRefresh ? 'AI usage refreshed.' : 'Unable to refresh AI usage.');
                $_SESSION['flash_type'] = $okRefresh ? 'success' : 'danger';
            } elseif ($action === 'refresh_build_usage') {
                if ($roleNow !== 'teacher') {
                    $_SESSION['flash_message'] = 'Build usage refresh is available for teacher accounts only.';
                    $_SESSION['flash_type'] = 'warning';
                } else {
                    [$okRefresh, $refreshMsg] = usage_limit_refresh_build_usage($conn, $userId, $actorId);
                    $_SESSION['flash_message'] = is_string($refreshMsg) ? $refreshMsg : ($okRefresh ? 'Build usage refreshed.' : 'Unable to refresh build usage.');
                    $_SESSION['flash_type'] = $okRefresh ? 'success' : 'danger';
                }
            } else {
                [$okAi, $msgAi] = usage_limit_refresh_ai_usage($conn, $userId, $actorId);
                $okBuild = true;
                $msgBuild = '';
                if ($roleNow === 'teacher') {
                    [$okBuild, $msgBuild] = usage_limit_refresh_build_usage($conn, $userId, $actorId);
                }

                if ($okAi && $okBuild) {
                    if (function_exists('usage_limit_log_event')) {
                        usage_limit_log_event(
                            $conn,
                            $userId,
                            'usage_refresh_all',
                            'all',
                            1,
                            null,
                            null,
                            null,
                            'AI and build usage refreshed by admin.',
                            ['role' => $roleNow],
                            $actorId
                        );
                    }
                    $_SESSION['flash_message'] = $roleNow === 'teacher'
                        ? 'AI and build usage refreshed.'
                        : 'AI usage refreshed.';
                    $_SESSION['flash_type'] = 'success';
                } else {
                    $parts = [];
                    if (!$okAi) $parts[] = is_string($msgAi) ? $msgAi : 'AI refresh failed.';
                    if (!$okBuild) $parts[] = is_string($msgBuild) ? $msgBuild : 'Build refresh failed.';
                    if (count($parts) === 0) $parts[] = 'Refresh failed.';
                    $_SESSION['flash_message'] = implode(' ', $parts);
                    $_SESSION['flash_type'] = 'danger';
                }
            }
        }
    } elseif ($userId > 0 && $action === 'reset_password') {
        // Admin resets password by setting a new temporary password (cannot view existing password hashes).
        $newPassword = isset($_POST['new_password']) ? (string) $_POST['new_password'] : '';
        $mode = isset($_POST['reset_mode']) ? (string) $_POST['reset_mode'] : 'generate';

        // Prevent resetting admin passwords through this UI (reduce accidental lockouts).
        $chk = $conn->prepare("SELECT useremail, username, role FROM users WHERE id = ? LIMIT 1");
        if (!$chk) {
            $_SESSION['flash_message'] = 'Password reset failed. Please try again.';
            $_SESSION['flash_type'] = 'danger';
        } else {
            $chk->bind_param("i", $userId);
            $chk->execute();
            $row = $chk->get_result()->fetch_assoc();
            $chk->close();

            $roleNow = normalize_role((string) ($row['role'] ?? ''));
            if ($roleNow === 'admin') {
                $_SESSION['flash_message'] = 'Cannot reset admin password from this page.';
                $_SESSION['flash_type'] = 'danger';
            } else {
                $usedStudentNoDefault = false;
                if ($mode === 'manual') {
                    $newPassword = trim($newPassword);
                    if (strlen($newPassword) < 8) {
                        $_SESSION['flash_message'] = 'Manual password must be at least 8 characters.';
                        $_SESSION['flash_type'] = 'warning';
                        header('Location: ' . $redirectPage);
                        exit;
                    }
                } else {
                    $newPassword = '';
                    if ($roleNow === 'student') {
                        $findStudentNo = $conn->prepare("SELECT StudentNo FROM students WHERE user_id = ? LIMIT 1");
                        if ($findStudentNo) {
                            $findStudentNo->bind_param('i', $userId);
                            $findStudentNo->execute();
                            $studentRes = $findStudentNo->get_result();
                            if ($studentRes && $studentRes->num_rows === 1) {
                                $newPassword = trim((string) ($studentRes->fetch_assoc()['StudentNo'] ?? ''));
                                $usedStudentNoDefault = $newPassword !== '';
                            }
                            $findStudentNo->close();
                        }
                    }

                    if ($newPassword === '') {
                        // Fallback: generate a strong temporary password.
                        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%&*?';
                        $buf = '';
                        for ($i = 0; $i < 12; $i++) {
                            $buf .= $alphabet[random_int(0, strlen($alphabet) - 1)];
                        }
                        $newPassword = $buf;
                    }
                }

                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                $mustChangePassword = $roleNow === 'student' ? 1 : 0;
                $upd = $conn->prepare("UPDATE users SET password = ?, must_change_password = CASE WHEN ? = 1 THEN 1 ELSE must_change_password END WHERE id = ?");
                if ($upd) {
                    $upd->bind_param("sii", $hash, $mustChangePassword, $userId);
                    if ($upd->execute()) {
                        $who = isset($row['useremail']) && $row['useremail'] !== '' ? $row['useremail'] : ((string) ($row['username'] ?? ''));
                        if ($roleNow === 'student' && $usedStudentNoDefault) {
                            $_SESSION['flash_message'] = 'Password reset successful. Default password was set to Student ID and must be changed on first login.';
                        } else {
                            $_SESSION['flash_message'] = 'Password reset successful. Share the temporary password with the user.';
                        }
                        $_SESSION['flash_type'] = 'success';
                        $_SESSION['flash_temp_password_user'] = $who;
                        $_SESSION['flash_temp_password'] = $newPassword;
                    } else {
                        $_SESSION['flash_message'] = 'Password reset failed. Please try again.';
                        $_SESSION['flash_type'] = 'danger';
                    }
                    $upd->close();
                } else {
                    $_SESSION['flash_message'] = 'Password reset failed. Please try again.';
                    $_SESSION['flash_type'] = 'danger';
                }
            }
        }
    } else {
        $_SESSION['flash_message'] = 'Invalid request.';
        $_SESSION['flash_type'] = 'danger';
    }

    // PRG: prevent repeat submissions on refresh/back.
    header('Location: ' . $redirectPage);
    exit;
}

$users = [];
$sql = "SELECT id, useremail, username, role, campus_id, is_superadmin, is_active, created_at, class_record_build_limit, class_record_build_usage_used, ai_rephrase_credit_limit, ai_rephrase_credit_used FROM users";
if ($isScopedRolePage) {
    if ($managedRole === 'student') {
        $studentSql = "
            SELECT
                s.id AS student_id,
                s.user_id AS linked_user_id,
                s.campus_id AS student_campus_id,
                s.StudentNo AS student_no,
                s.Surname AS student_surname,
                s.FirstName AS student_firstname,
                s.MiddleName AS student_middlename,
                s.Course AS student_course,
                s.Major AS student_major,
                s.Year AS student_year,
                s.Section AS student_section,
                s.Sex AS student_sex,
                s.Status AS student_profile_status,
                s.BirthDate AS student_birth_date,
                s.Barangay AS student_barangay,
                s.Municipality AS student_municipality,
                s.Province AS student_province,
                s.email AS student_email,
                s.phone AS student_phone,
                s.created_at AS student_created_at,
                u.id AS account_id,
                u.useremail AS account_email,
                u.username AS account_username,
                u.role AS account_role,
                u.campus_id AS account_campus_id,
                u.is_superadmin AS account_is_superadmin,
                u.is_active AS account_is_active,
                u.created_at AS account_created_at,
                u.class_record_build_limit,
                u.class_record_build_usage_used,
                u.ai_rephrase_credit_limit,
                u.ai_rephrase_credit_used
            FROM students s
            LEFT JOIN users u ON u.id = s.user_id
        ";
        if (!$adminIsSuperadmin) {
            $studentSql .= " WHERE s.campus_id = " . (int) $adminCampusId;
        }
        $studentSql .= " ORDER BY s.Surname ASC, s.FirstName ASC, s.MiddleName ASC, s.StudentNo ASC";
        $result = $conn->query($studentSql);
        $linkedAccountIds = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $accountId = (int) ($row['account_id'] ?? 0);
                $accountRole = normalize_role((string) ($row['account_role'] ?? 'student'));
                if ($accountRole === '' || $accountRole === 'user') {
                    $accountRole = 'student';
                }
                if ($accountId > 0) $linkedAccountIds[$accountId] = true;

                $fullName = trim(
                    (string) ($row['student_surname'] ?? '') . ', ' .
                    (string) ($row['student_firstname'] ?? '') . ' ' .
                    (string) ($row['student_middlename'] ?? '')
                );
                $fullName = preg_replace('/\s+/', ' ', $fullName);
                if (!is_string($fullName)) {
                    $fullName = '';
                }
                $fullName = trim($fullName, " \t\n\r\0\x0B,");

                $createdAt = (string) ($row['account_created_at'] ?? '');
                if ($createdAt === '') {
                    $createdAt = (string) ($row['student_created_at'] ?? '');
                }

                $accountStatus = 'no_account';
                if ($accountId > 0) {
                    $accountStatus = ((int) ($row['account_is_active'] ?? 0) === 1) ? 'active' : 'pending';
                }

                $users[] = [
                    'id' => $accountId,
                    'useremail' => (string) ($row['account_email'] ?? ''),
                    'username' => (string) ($row['account_username'] ?? $fullName),
                    'role' => 'student',
                    'is_active' => $accountId > 0 ? (int) ($row['account_is_active'] ?? 0) : 0,
                    'campus_id' => $accountId > 0 ? (int) ($row['account_campus_id'] ?? 0) : (int) ($row['student_campus_id'] ?? 0),
                    'is_superadmin' => $accountId > 0 ? (int) ($row['account_is_superadmin'] ?? 0) : 0,
                    'created_at' => $createdAt,
                    'class_record_build_limit' => (int) ($row['class_record_build_limit'] ?? 0),
                    'class_record_build_usage_used' => (int) ($row['class_record_build_usage_used'] ?? 0),
                    'ai_rephrase_credit_limit' => (float) ($row['ai_rephrase_credit_limit'] ?? 0),
                    'ai_rephrase_credit_used' => (float) ($row['ai_rephrase_credit_used'] ?? 0),
                    'student_id' => (int) ($row['student_id'] ?? 0),
                    'student_no' => (string) ($row['student_no'] ?? ''),
                    'student_surname' => (string) ($row['student_surname'] ?? ''),
                    'student_firstname' => (string) ($row['student_firstname'] ?? ''),
                    'student_middlename' => (string) ($row['student_middlename'] ?? ''),
                    'student_name' => $fullName,
                    'student_course' => (string) ($row['student_course'] ?? ''),
                    'student_major' => (string) ($row['student_major'] ?? ''),
                    'student_year' => (string) ($row['student_year'] ?? ''),
                    'student_section' => (string) ($row['student_section'] ?? ''),
                    'student_sex' => (string) ($row['student_sex'] ?? 'M'),
                    'student_profile_status' => (string) ($row['student_profile_status'] ?? 'New'),
                    'student_birth_date' => (string) ($row['student_birth_date'] ?? ''),
                    'student_barangay' => (string) ($row['student_barangay'] ?? ''),
                    'student_municipality' => (string) ($row['student_municipality'] ?? ''),
                    'student_province' => (string) ($row['student_province'] ?? ''),
                    'student_email' => (string) ($row['student_email'] ?? ''),
                    'student_phone' => (string) ($row['student_phone'] ?? ''),
                    'has_account' => $accountId > 0 ? 1 : 0,
                    'account_status' => $accountStatus,
                    'account_role' => $accountRole,
                ];
            }
        }

        $extraSql =
            "SELECT
                u.id,
                u.useremail,
                u.username,
                u.role,
                u.campus_id,
                u.is_superadmin,
                u.is_active,
                u.created_at,
                u.class_record_build_limit,
                u.class_record_build_usage_used,
                u.ai_rephrase_credit_limit,
                u.ai_rephrase_credit_used
             FROM users u
             WHERE u.role IN ('student', 'user')
        ";
        if (!$adminIsSuperadmin) {
            $extraSql .= " AND u.campus_id = " . (int) $adminCampusId;
        }
        $extraSql .= "
               AND NOT EXISTS (
                   SELECT 1
                   FROM students s
                   WHERE s.user_id = u.id
               )
             ORDER BY u.created_at DESC";
        $extraRes = $conn->query($extraSql);
        if ($extraRes) {
            while ($row = $extraRes->fetch_assoc()) {
                $accountId = (int) ($row['id'] ?? 0);
                if ($accountId <= 0) continue;
                if (isset($linkedAccountIds[$accountId])) continue;

                $accountRole = normalize_role((string) ($row['role'] ?? 'student'));
                if ($accountRole === '' || $accountRole === 'user') {
                    $accountRole = 'student';
                }

                $isActive = isset($row['is_active']) ? (int) $row['is_active'] : 0;
                $accountStatus = $isActive === 1 ? 'active' : 'pending';

                $users[] = [
                    'id' => $accountId,
                    'useremail' => (string) ($row['useremail'] ?? ''),
                    'username' => (string) ($row['username'] ?? ''),
                    'role' => 'student',
                    'is_active' => $isActive,
                    'campus_id' => (int) ($row['campus_id'] ?? 0),
                    'is_superadmin' => (int) ($row['is_superadmin'] ?? 0),
                    'created_at' => (string) ($row['created_at'] ?? ''),
                    'class_record_build_limit' => (int) ($row['class_record_build_limit'] ?? 0),
                    'class_record_build_usage_used' => (int) ($row['class_record_build_usage_used'] ?? 0),
                    'ai_rephrase_credit_limit' => (float) ($row['ai_rephrase_credit_limit'] ?? 0),
                    'ai_rephrase_credit_used' => (float) ($row['ai_rephrase_credit_used'] ?? 0),
                    'student_id' => 0,
                    'student_no' => '',
                    'student_surname' => '',
                    'student_firstname' => '',
                    'student_middlename' => '',
                    'student_name' => (string) ($row['username'] ?? ''),
                    'student_course' => '',
                    'student_major' => '',
                    'student_year' => '',
                    'student_section' => '',
                    'student_sex' => 'M',
                    'student_profile_status' => 'New',
                    'student_birth_date' => '',
                    'student_barangay' => '',
                    'student_municipality' => '',
                    'student_province' => '',
                    'student_email' => '',
                    'student_phone' => '',
                    'has_account' => 1,
                    'account_status' => $accountStatus,
                    'account_role' => $accountRole,
                ];
            }
        }
    } elseif ($managedRole === 'teacher') {
        $teacherSql = "
            SELECT
                u.id,
                u.useremail,
                u.username,
                u.role,
                u.campus_id,
                u.is_superadmin,
                u.is_active,
                u.created_at,
                u.class_record_build_limit,
                u.class_record_build_usage_used,
                u.ai_rephrase_credit_limit,
                u.ai_rephrase_credit_used,
                t.id AS teacher_id,
                t.TeacherNo AS teacher_no,
                t.Surname AS teacher_surname,
                t.FirstName AS teacher_firstname,
                t.MiddleName AS teacher_middlename,
                t.Sex AS teacher_sex,
                t.Department AS teacher_department,
                t.Position AS teacher_position,
                t.EmploymentStatus AS teacher_employment_status,
                t.Status AS teacher_profile_status,
                t.BirthDate AS teacher_birth_date,
                t.Barangay AS teacher_barangay,
                t.Municipality AS teacher_municipality,
                t.Province AS teacher_province,
                t.email AS teacher_email,
                t.phone AS teacher_phone
            FROM users u
            LEFT JOIN teachers t ON t.user_id = u.id
            WHERE u.role = 'teacher'
        ";
        if (!$adminIsSuperadmin) {
            $teacherSql .= " AND u.campus_id = " . (int) $adminCampusId;
        }
        $teacherSql .= " ORDER BY u.created_at DESC";
        $teacherRes = $conn->query($teacherSql);
        if ($teacherRes) {
            while ($row = $teacherRes->fetch_assoc()) {
                $fullName = admin_users_compose_display_name(
                    (string) ($row['teacher_surname'] ?? ''),
                    (string) ($row['teacher_firstname'] ?? ''),
                    (string) ($row['teacher_middlename'] ?? '')
                );
                if ($fullName === '') {
                    $fullName = trim((string) ($row['username'] ?? ''));
                }
                $row['username'] = (string) ($row['username'] ?? $fullName);
                $row['role'] = normalize_role((string) ($row['role'] ?? 'teacher'));
                $row['teacher_name'] = $fullName;
                $row['teacher_id'] = (int) ($row['teacher_id'] ?? 0);
                $row['has_teacher_profile'] = ((int) ($row['teacher_id'] ?? 0) > 0) ? 1 : 0;
                if ((string) ($row['teacher_email'] ?? '') === '') {
                    $row['teacher_email'] = (string) ($row['useremail'] ?? '');
                }
                $users[] = $row;
            }
        }
    } else {
        if ($adminIsSuperadmin) {
            $sql .= " WHERE role = ? AND is_superadmin = 0 ORDER BY created_at DESC";
        } else {
            $sql .= " WHERE role = ? AND campus_id = ? ORDER BY created_at DESC";
        }
        $stmtUsers = $conn->prepare($sql);
        if ($stmtUsers) {
            if ($adminIsSuperadmin) {
                $stmtUsers->bind_param('s', $managedRole);
            } else {
                $stmtUsers->bind_param('si', $managedRole, $adminCampusId);
            }
            $stmtUsers->execute();
            $result = $stmtUsers->get_result();
            while ($result && ($row = $result->fetch_assoc())) {
                $row['role'] = normalize_role($row['role'] ?? '');
                $users[] = $row;
            }
            $stmtUsers->close();
        }
    }
} else {
    if ($adminIsSuperadmin) {
        $sql .= " WHERE is_superadmin = 0 ORDER BY created_at DESC";
        $result = $conn->query($sql);
    } else {
        $sql .= " WHERE role <> 'admin' AND campus_id = ? ORDER BY created_at DESC";
        $stmtUsers = $conn->prepare($sql);
        $result = false;
        if ($stmtUsers) {
            $stmtUsers->bind_param('i', $adminCampusId);
            $stmtUsers->execute();
            $result = $stmtUsers->get_result();
        }
    }
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $row['role'] = normalize_role($row['role'] ?? '');
            $users[] = $row;
        }
    }
    if (isset($stmtUsers) && $stmtUsers instanceof mysqli_stmt) {
        $stmtUsers->close();
    }
}

$studentSectionFilterOptions = [];
$studentSectionProfiles = [];
$studentSectionOnlyOptions = [];
$studentYearOptions = [];
$bulkEnrollSubjects = [];
$bulkEnrollAcademicYears = [];
$bulkEnrollSemesters = [];
$bulkEnrollSectionOptions = [];

if ($isStudentScopedPage) {
    foreach ($users as $u) {
        $sec = trim((string) ($u['student_section'] ?? ''));
        $yr = trim((string) ($u['student_year'] ?? ''));
        $course = trim((string) ($u['student_course'] ?? ''));
        if ($sec !== '') $studentSectionOnlyOptions[$sec] = true;
        if ($course !== '' && $yr !== '' && $sec !== '') {
            $profileKey = strtolower($course . '|' . $yr . '|' . $sec);
            $studentSectionProfiles[$profileKey] = [
                'course' => $course,
                'year' => $yr,
                'section' => $sec,
                'label' => $course . ' - ' . $yr . ' - ' . $sec,
                'key' => $profileKey,
            ];
        }
        if ($yr !== '') $studentYearOptions[$yr] = true;
    }

    if (count($studentSectionProfiles) === 0 && function_exists('ref_list_student_section_profiles')) {
        $dbProfiles = ref_list_student_section_profiles($conn);
        foreach ($dbProfiles as $p) {
            $pCourse = trim((string) ($p['course'] ?? ''));
            $pYear = trim((string) ($p['year'] ?? ''));
            $pSection = trim((string) ($p['section'] ?? ''));
            if ($pCourse === '' || $pYear === '' || $pSection === '') continue;
            $profileKey = strtolower($pCourse . '|' . $pYear . '|' . $pSection);
            $studentSectionProfiles[$profileKey] = [
                'course' => $pCourse,
                'year' => $pYear,
                'section' => $pSection,
                'label' => $pCourse . ' - ' . $pYear . ' - ' . $pSection,
                'key' => $profileKey,
            ];
        }
    }

    $subRes = $conn->query(
        "SELECT id, subject_code, subject_name, academic_year, semester
         FROM subjects
         WHERE status = 'active'
         ORDER BY subject_name ASC, subject_code ASC"
    );
    while ($subRes && ($r = $subRes->fetch_assoc())) {
        $bulkEnrollSubjects[] = $r;
    }

    $bulkEnrollAcademicYears = ref_list_active_names($conn, 'academic_years');
    if (count($bulkEnrollAcademicYears) === 0) {
        $fallbackYears = [];
        $ay1 = $conn->query("SELECT DISTINCT academic_year FROM subjects WHERE academic_year IS NOT NULL AND academic_year <> ''");
        if ($ay1) while ($r = $ay1->fetch_assoc()) $fallbackYears[] = trim((string) ($r['academic_year'] ?? ''));
        $ay2 = $conn->query("SELECT DISTINCT academic_year FROM enrollments WHERE academic_year IS NOT NULL AND academic_year <> ''");
        if ($ay2) while ($r = $ay2->fetch_assoc()) $fallbackYears[] = trim((string) ($r['academic_year'] ?? ''));
        $ay3 = $conn->query("SELECT DISTINCT academic_year FROM class_records WHERE academic_year IS NOT NULL AND academic_year <> ''");
        if ($ay3) while ($r = $ay3->fetch_assoc()) $fallbackYears[] = trim((string) ($r['academic_year'] ?? ''));
        $fallbackYears = array_values(array_unique(array_filter($fallbackYears, static function ($v) { return $v !== ''; })));
        rsort($fallbackYears);
        $bulkEnrollAcademicYears = $fallbackYears;
    }

    $bulkEnrollSemesters = ref_list_active_names($conn, 'semesters');
    if (count($bulkEnrollSemesters) === 0) {
        $fallbackSem = [];
        $sm1 = $conn->query("SELECT DISTINCT semester FROM subjects WHERE semester IS NOT NULL AND semester <> ''");
        if ($sm1) while ($r = $sm1->fetch_assoc()) $fallbackSem[] = trim((string) ($r['semester'] ?? ''));
        $sm2 = $conn->query("SELECT DISTINCT semester FROM enrollments WHERE semester IS NOT NULL AND semester <> ''");
        if ($sm2) while ($r = $sm2->fetch_assoc()) $fallbackSem[] = trim((string) ($r['semester'] ?? ''));
        $sm3 = $conn->query("SELECT DISTINCT semester FROM class_records WHERE semester IS NOT NULL AND semester <> ''");
        if ($sm3) while ($r = $sm3->fetch_assoc()) $fallbackSem[] = trim((string) ($r['semester'] ?? ''));
        $bulkEnrollSemesters = array_values(array_unique(array_filter($fallbackSem, static function ($v) { return $v !== ''; })));
        sort($bulkEnrollSemesters);
    }

    if (count($studentSectionProfiles) > 0) {
        $sectionProfileList = array_values($studentSectionProfiles);
        usort($sectionProfileList, static function ($a, $b) {
            $aLabel = strtolower((string) ($a['label'] ?? ''));
            $bLabel = strtolower((string) ($b['label'] ?? ''));
            return $aLabel <=> $bLabel;
        });
        foreach ($sectionProfileList as $profile) {
            $profileKey = (string) ($profile['key'] ?? '');
            $profileLabel = (string) ($profile['label'] ?? '');
            if ($profileKey === '' || $profileLabel === '') continue;
            $studentSectionFilterOptions[] = [
                'value' => $profileKey,
                'label' => $profileLabel,
            ];
            $bulkEnrollSectionOptions[] = [
                'value' => (string) ($profile['course'] ?? '') . '||' . (string) ($profile['year'] ?? '') . '||' . (string) ($profile['section'] ?? ''),
                'label' => $profileLabel,
            ];
        }
    }

    if (count($bulkEnrollSectionOptions) === 0) {
        $sectionTargets = [];
        if (function_exists('ref_list_profile_sections')) {
            $refProfiles = ref_list_profile_sections($conn, true);
            foreach ($refProfiles as $p) {
                $label = trim((string) ($p['label'] ?? ''));
                if ($label !== '') $sectionTargets[] = $label;
            }
        }
        foreach (array_keys($studentSectionOnlyOptions) as $sec) {
            $sec = trim((string) $sec);
            if ($sec !== '') $sectionTargets[] = $sec;
        }
        if (count($sectionTargets) === 0 && function_exists('ref_list_class_sections')) {
            $classSections = ref_list_class_sections($conn, true, true);
            foreach ($classSections as $cs) {
                $code = trim((string) ($cs['code'] ?? ''));
                if ($code !== '') $sectionTargets[] = $code;
            }
        }
        if (count($sectionTargets) === 0) {
            $sr2 = $conn->query("SELECT DISTINCT section FROM class_records WHERE section IS NOT NULL AND section <> ''");
            if ($sr2) while ($r = $sr2->fetch_assoc()) $sectionTargets[] = trim((string) ($r['section'] ?? ''));
            $sr3 = $conn->query("SELECT DISTINCT section FROM enrollments WHERE section IS NOT NULL AND section <> ''");
            if ($sr3) while ($r = $sr3->fetch_assoc()) $sectionTargets[] = trim((string) ($r['section'] ?? ''));
        }
        $sectionTargets = array_values(array_unique(array_filter($sectionTargets, static function ($v) { return $v !== ''; })));
        natcasesort($sectionTargets);
        foreach ($sectionTargets as $sectionName) {
            $sectionName = (string) $sectionName;
            $bulkEnrollSectionOptions[] = [
                'value' => $sectionName,
                'label' => $sectionName,
            ];
        }
    }

    if (count($studentSectionFilterOptions) === 0) {
        foreach (array_keys($studentSectionOnlyOptions) as $sectionOnly) {
            $sectionOnly = trim((string) $sectionOnly);
            if ($sectionOnly === '') continue;
            $studentSectionFilterOptions[] = [
                'value' => strtolower($sectionOnly),
                'label' => $sectionOnly,
            ];
        }
        foreach ($bulkEnrollSectionOptions as $opt) {
            $label = trim((string) ($opt['label'] ?? ''));
            if ($label === '') continue;
            $studentSectionFilterOptions[] = [
                'value' => strtolower($label),
                'label' => $label,
            ];
        }
        $studentSectionFilterOptions = array_values(array_unique($studentSectionFilterOptions, SORT_REGULAR));
    }

    $yearOptions = array_keys($studentYearOptions);
    natcasesort($yearOptions);
    $studentYearOptions = array_values($yearOptions);
}

$teacherBuildCounts = [];
if (function_exists('usage_limit_table_exists') && usage_limit_table_exists($conn, 'class_record_builds')) {
    $buildCountRes = $conn->query(
        "SELECT teacher_id, COUNT(*) AS total_builds
         FROM class_record_builds
         GROUP BY teacher_id"
    );
    if ($buildCountRes) {
        while ($buildRow = $buildCountRes->fetch_assoc()) {
            $teacherBuildId = (int) ($buildRow['teacher_id'] ?? 0);
            if ($teacherBuildId > 0) {
                $teacherBuildCounts[$teacherBuildId] = (int) ($buildRow['total_builds'] ?? 0);
            }
        }
        $buildCountRes->free();
    }
}

$userIds = [];
foreach ($users as $u) {
    $id = isset($u['id']) ? (int) $u['id'] : 0;
    if ($id > 0) $userIds[] = $id;
}

$usageHistoryMap = function_exists('usage_limit_fetch_events_for_users')
    ? usage_limit_fetch_events_for_users($conn, $userIds, 40, 365)
    : [];
$usageHistoryJson = json_encode(
    $usageHistoryMap,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
if (!is_string($usageHistoryJson)) $usageHistoryJson = '{}';

$totalUsers = count($users);
$activeUsers = 0;
$pendingUsers = 0;
$noAccountUsers = 0;
$teacherUsers = 0;
$studentUsers = 0;

foreach ($users as $u) {
    $accountStatus = isset($u['account_status']) ? (string) $u['account_status'] : '';
    $isActive = isset($u['is_active']) ? (int) $u['is_active'] : 0;
    $role = isset($u['role']) ? (string) $u['role'] : '';

    if ($isStudentScopedPage && $accountStatus === 'no_account') {
        $noAccountUsers++;
    } elseif ($isActive === 1) {
        $activeUsers++;
    } else {
        $pendingUsers++;
    }
    if ($role === 'teacher') $teacherUsers++;
    if ($role === 'student') $studentUsers++;
}

$tableColspan = $isStudentScopedPage ? 9 : 7;
?>

<?php include '../layouts/main.php'; ?>

<head>
    <title><?php echo htmlspecialchars($pageHeading); ?> | E-Record</title>
    <?php include '../layouts/title-meta.php'; ?>
    <?php include '../layouts/head-css.php'; ?>

    <style>
        :root {
            --users-accent: #2563eb;
            --users-accent-soft: rgba(37, 99, 235, 0.12);
            --users-ink-soft: #64748b;
        }

        .users-hero {
            position: relative;
            overflow: hidden;
            border-radius: 14px;
            padding: 16px;
            margin-bottom: 18px;
            border: 1px solid rgba(37, 99, 235, 0.18);
            background:
                radial-gradient(120px 120px at 88% 22%, rgba(16, 185, 129, 0.16), transparent 65%),
                linear-gradient(125deg, rgba(37, 99, 235, 0.14), rgba(6, 182, 212, 0.08));
        }

        .users-hero::before {
            content: "";
            position: absolute;
            inset: auto auto -34px -40px;
            width: 180px;
            height: 180px;
            border-radius: 999px;
            background: radial-gradient(circle, rgba(37, 99, 235, 0.17), transparent 66%);
            pointer-events: none;
        }

        .users-metric-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            gap: 12px;
            position: relative;
            z-index: 1;
        }

        .users-metric {
            border-radius: 12px;
            background: #ffffff;
            border: 1px solid rgba(15, 23, 42, 0.08);
            padding: 10px 12px;
            box-shadow: 0 10px 24px -20px rgba(15, 23, 42, 0.6);
        }

        .users-metric .label {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: var(--users-ink-soft);
            margin-bottom: 4px;
        }

        .users-metric .value {
            font-size: 1.35rem;
            font-weight: 700;
            line-height: 1;
            color: #0f172a;
        }

        .users-card {
            border: 1px solid rgba(15, 23, 42, 0.08);
            box-shadow: 0 12px 28px -22px rgba(15, 23, 42, 0.75);
        }

        .users-toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            justify-content: space-between;
            align-items: end;
            margin-bottom: 12px;
        }

        .users-filter-group {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }

        .users-filter-group .input-group {
            min-width: 220px;
        }

        .users-filter-group .form-select {
            min-width: 160px;
        }

        .users-bulk-panel {
            border: 1px solid rgba(37, 99, 235, 0.2);
            border-radius: 12px;
            background: linear-gradient(135deg, rgba(37, 99, 235, 0.08), rgba(14, 165, 233, 0.04));
            padding: 12px;
            margin-bottom: 12px;
        }

        .users-bulk-panel .form-select,
        .users-bulk-panel .form-control {
            min-width: 150px;
        }

        .bulk-check-col {
            width: 42px;
            text-align: center;
        }

        .bulk-check-col .form-check-input {
            margin-top: 0;
        }

        .sort-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 0;
            border: 0;
            background: transparent;
            color: inherit;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
        }

        .sort-btn i {
            font-size: 14px;
            color: #94a3b8;
            transition: color 120ms ease;
        }

        .sort-btn.active {
            color: #1e293b;
        }

        .sort-btn.active i {
            color: var(--users-accent);
        }

        .users-meta {
            font-size: 12px;
            color: var(--users-ink-soft);
            margin-bottom: 10px;
        }

        .users-table-wrap {
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 12px;
        }

        .table-users {
            margin-bottom: 0;
            min-width: 1120px;
        }

        .table-users thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: #f8fafc;
            border-bottom: 1px solid rgba(15, 23, 42, 0.1);
            font-size: 11px;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #475569;
            white-space: nowrap;
        }

        .table-users tbody td {
            vertical-align: middle;
        }

        .table-users tbody tr {
            transition: background-color 120ms ease;
        }

        .table-users tbody tr:hover {
            background-color: rgba(37, 99, 235, 0.05);
        }

        .user-ident {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 180px;
        }

        .user-avatar {
            width: 34px;
            height: 34px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            color: #1e3a8a;
            background: linear-gradient(135deg, rgba(37, 99, 235, 0.2), rgba(14, 165, 233, 0.18));
            border: 1px solid rgba(37, 99, 235, 0.25);
        }

        .status-pill {
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.02em;
            padding: 0.38rem 0.62rem;
            border: 1px solid transparent;
        }

        .status-pill.active {
            color: #166534;
            background: #dcfce7;
            border-color: #86efac;
        }

        .status-pill.pending {
            color: #854d0e;
            background: #fef9c3;
            border-color: #fde047;
        }

        .status-pill.no-account {
            color: #475569;
            background: #e2e8f0;
            border-color: #cbd5e1;
        }

        .inline-compact .form-control,
        .inline-compact .form-select {
            min-height: 31px;
        }

        .inline-compact .limit-input {
            max-width: 104px;
        }

        .inline-compact .credit-input {
            max-width: 116px;
        }

        .rows-per-page {
            min-width: 96px !important;
        }

        .users-pagination {
            margin-top: 12px;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
        }

        .icon-btn {
            width: 34px;
            height: 34px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0;
        }

        .icon-btn i {
            font-size: 16px;
            transition: transform 160ms ease, opacity 160ms ease;
        }

        .icon-btn:hover i {
            transform: translateY(-1px) scale(1.08);
        }

        .icon-btn.action-reset:hover i { transform: rotate(18deg) scale(1.08); }
        .icon-btn.action-revoke:hover i { transform: rotate(-10deg) scale(1.08); }
        .icon-btn.action-approve:hover i { transform: rotate(10deg) scale(1.08); }
        .icon-btn.action-save:hover i { transform: translateY(-1px) scale(1.12); }
        .icon-btn.action-view:hover i { transform: translateY(-1px) scale(1.12); }
        .icon-btn.action-edit:hover i { transform: translateY(-1px) scale(1.12); }

        /* Make the account-management modal feel less "default Bootstrap". */
        .modal-hero {
            position: relative;
            overflow: hidden;
            border-radius: 12px;
            padding: 14px 14px;
            background: linear-gradient(135deg, rgba(48, 81, 255, 0.12), rgba(48, 81, 255, 0.02));
            border: 1px solid rgba(48, 81, 255, 0.18);
        }

        .modal-hero::after {
            content: "";
            position: absolute;
            inset: -40px -60px auto auto;
            width: 180px;
            height: 180px;
            background: radial-gradient(circle at 30% 30%, rgba(48, 81, 255, 0.25), transparent 60%);
            transform: rotate(18deg);
            pointer-events: none;
        }

        .modal-hero .hero-icon {
            width: 48px;
            height: 48px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            background: rgba(48, 81, 255, 0.14);
            border: 1px solid rgba(48, 81, 255, 0.22);
        }

        .modal-hero .hero-icon i {
            font-size: 22px;
        }

        .usage-cell-metric {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-right: 10px;
        }

        .usage-cell-metric .label {
            color: var(--users-ink-soft);
            font-size: 11px;
        }

        .usage-cell-metric .value {
            font-weight: 700;
            color: #0f172a;
            font-size: 12px;
        }

        .usage-kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 10px;
        }

        .usage-kpi {
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 12px;
            padding: 10px 12px;
            background: #fff;
        }

        .usage-kpi .label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #64748b;
            font-weight: 700;
            margin-bottom: 2px;
        }

        .usage-kpi .value {
            font-size: 1.1rem;
            font-weight: 700;
            color: #0f172a;
            line-height: 1.15;
        }

        .usage-progress {
            margin-top: 8px;
            height: 8px;
            border-radius: 999px;
            overflow: hidden;
            background: #e2e8f0;
        }

        .usage-progress > span {
            display: block;
            height: 100%;
            width: 0%;
            transition: width 180ms ease;
            border-radius: 999px;
        }

        .usage-progress .ai-bar {
            background: linear-gradient(90deg, #0ea5e9, #2563eb);
        }

        .usage-progress .build-bar {
            background: linear-gradient(90deg, #22c55e, #16a34a);
        }

        .usage-infographic {
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 12px;
            padding: 10px 12px;
            background: #fff;
        }

        .usage-trend-chart {
            display: flex;
            align-items: flex-end;
            gap: 6px;
            min-height: 150px;
            overflow-x: auto;
            padding-bottom: 6px;
        }

        .usage-day {
            width: 24px;
            flex: 0 0 24px;
            text-align: center;
        }

        .usage-day .stack {
            height: 110px;
            border-radius: 8px;
            background: #f1f5f9;
            display: flex;
            flex-direction: column-reverse;
            overflow: hidden;
            border: 1px solid rgba(148, 163, 184, 0.35);
        }

        .usage-day .seg-ai {
            background: #38bdf8;
        }

        .usage-day .seg-build {
            background: #22c55e;
        }

        .usage-day .seg-refresh {
            background: #f59e0b;
        }

        .usage-day .day-label {
            margin-top: 5px;
            font-size: 10px;
            color: #64748b;
        }

        .usage-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 8px;
            font-size: 12px;
            color: #475569;
        }

        .usage-legend .dot {
            width: 9px;
            height: 9px;
            border-radius: 999px;
            display: inline-block;
            margin-right: 5px;
        }

        .usage-history-wrap {
            max-height: 280px;
            overflow: auto;
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 12px;
        }

        .usage-history-table {
            margin-bottom: 0;
            font-size: 12px;
        }

        .usage-history-table thead th {
            position: sticky;
            top: 0;
            background: #f8fafc;
            z-index: 1;
            font-size: 11px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .receipt-note {
            font-size: 12px;
            color: #64748b;
        }

        @media (max-width: 768px) {
            .users-toolbar {
                align-items: stretch;
            }

            .users-filter-group,
            .users-filter-group .input-group,
            .users-filter-group .form-select {
                width: 100%;
                min-width: 0;
            }
        }
    </style>
</head>

<body>
    <!-- Begin page -->
    <div class="wrapper">

        <?php include '../layouts/menu.php'; ?>

        <!-- ============================================================== -->
        <!-- Start Page Content here -->
        <!-- ============================================================== -->

        <div class="content-page">
            <div class="content">

                <!-- Start Content-->
                <div class="container-fluid">

                    <div class="row">
                        <div class="col-12">
                            <div class="page-title-box">
                                <div class="page-title-right">
                                    <ol class="breadcrumb m-0">
                                        <li class="breadcrumb-item"><a href="javascript: void(0);">E-Record</a></li>
                                        <li class="breadcrumb-item active"><?php echo htmlspecialchars($pageHeading); ?></li>
                                    </ol>
                                </div>
                                <h4 class="page-title"><?php echo htmlspecialchars($pageHeading); ?></h4>
                            </div>
                        </div>
                    </div>

                    <?php if ($flashMessage): ?>
                        <div class="alert alert-<?php echo htmlspecialchars($flashType); ?>" role="alert">
                            <?php echo htmlspecialchars($flashMessage); ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($flashTempPassword): ?>
                        <div class="alert alert-warning" role="alert">
                            <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                                <div>
                                    <div class="fw-semibold">Temporary Password Created</div>
                                    <div class="text-muted small">For: <?php echo htmlspecialchars($flashTempPasswordUser); ?></div>
                                    <div class="mt-2 font-monospace">
                                        <span id="tmpPwMasked">••••••••••••</span>
                                        <span id="tmpPwPlain" class="d-none"><?php echo htmlspecialchars($flashTempPassword); ?></span>
                                    </div>
                                </div>
                                <div class="d-flex gap-2">
                                    <button type="button" class="btn btn-sm btn-outline-dark icon-btn action-view" id="toggleTmpPw" data-bs-toggle="tooltip" title="View">
                                        <i class="ri-eye-line" aria-hidden="true"></i>
                                        <span class="visually-hidden">View</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="users-hero">
                        <div class="users-metric-grid">
                            <div class="users-metric">
                                <div class="label">Total <?php echo htmlspecialchars($roleLabelPlural); ?></div>
                                <div class="value"><?php echo (int) $totalUsers; ?></div>
                            </div>
                            <div class="users-metric">
                                <div class="label">Pending</div>
                                <div class="value"><?php echo (int) $pendingUsers; ?></div>
                            </div>
                            <div class="users-metric">
                                <div class="label">Active</div>
                                <div class="value"><?php echo (int) $activeUsers; ?></div>
                            </div>
                            <?php if ($isStudentScopedPage): ?>
                                <div class="users-metric">
                                    <div class="label">No Login Account</div>
                                    <div class="value"><?php echo (int) $noAccountUsers; ?></div>
                                </div>
                            <?php endif; ?>
                            <?php if (!$isScopedRolePage): ?>
                                <div class="users-metric">
                                    <div class="label">Teachers</div>
                                    <div class="value"><?php echo (int) $teacherUsers; ?></div>
                                </div>
                                <div class="users-metric">
                                    <div class="label">Students</div>
                                    <div class="value"><?php echo (int) $studentUsers; ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-12">
                            <div class="card users-card">
                            <div class="card-body">
                                    <div class="users-toolbar">
                                        <div>
                                            <h4 class="header-title mb-1"><?php echo htmlspecialchars($toolbarHeading); ?></h4>
                                            <p class="text-muted mb-0"><?php echo htmlspecialchars($toolbarDescription); ?></p>
                                        </div>
                                        <div class="users-filter-group">
                                            <div class="input-group input-group-sm">
                                                <span class="input-group-text bg-white border-end-0">
                                                    <i class="ri-search-line" aria-hidden="true"></i>
                                                </span>
                                                <input type="search" id="accountSearch" class="form-control border-start-0" placeholder="<?php echo htmlspecialchars($searchPlaceholder); ?>">
                                            </div>
                                            <select id="statusFilter" class="form-select form-select-sm" aria-label="Filter by status">
                                                <option value="all">All Status</option>
                                                <option value="active">Active</option>
                                                <option value="pending">Pending</option>
                                                <?php if ($isStudentScopedPage): ?>
                                                    <option value="no_account">No Account</option>
                                                <?php endif; ?>
                                            </select>
                                            <?php if ($isStudentScopedPage): ?>
                                                <select id="sectionFilter" class="form-select form-select-sm" aria-label="Filter by section">
                                                    <option value="all">All Sectioning</option>
                                                    <?php foreach ($studentSectionFilterOptions as $secOpt): ?>
                                                        <option value="<?php echo htmlspecialchars((string) ($secOpt['value'] ?? '')); ?>">
                                                            <?php echo htmlspecialchars((string) ($secOpt['label'] ?? '')); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            <?php endif; ?>
                                            <?php if (!$isScopedRolePage): ?>
                                                <select id="roleFilter" class="form-select form-select-sm" aria-label="Filter by role">
                                                    <option value="all">All Roles</option>
                                                    <option value="student">Student</option>
                                                    <option value="teacher">Teacher</option>
                                                    <option value="registrar">Registrar</option>
                                                    <option value="program_chair">Program Chair</option>
                                                    <option value="college_dean">College Dean</option>
                                                    <option value="guardian">Guardian</option>
                                                    <?php if ($adminIsSuperadmin): ?>
                                                        <option value="admin">Campus Admin</option>
                                                    <?php endif; ?>
                                                </select>
                                            <?php endif; ?>
                                            <select id="pageSizeSelect" class="form-select form-select-sm rows-per-page" aria-label="Rows per page">
                                                <option value="10" selected>10 / page</option>
                                                <option value="20">20 / page</option>
                                                <option value="50">50 / page</option>
                                                <option value="100">100 / page</option>
                                            </select>
                                            <?php if (!$isStudentScopedPage): ?>
                                                <button type="button" class="btn btn-sm btn-primary" id="createUserAccountBtn" data-bs-toggle="modal" data-bs-target="#createUserModal">
                                                    <i class="ri-user-add-line me-1" aria-hidden="true"></i>
                                                    <?php echo htmlspecialchars($createButtonLabel); ?>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <?php if ($isStudentScopedPage): ?>
                                        <form method="post" id="bulkEnrollForm" class="users-bulk-panel">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                            <input type="hidden" name="action" value="bulk_enroll_students">

                                            <div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-2">
                                                <div>
                                                    <div class="fw-semibold">Bulk Enroll</div>
                                                    <div class="text-muted small">Select students from the table, then assign them to a target subject and sectioning (Course - Year - Section).</div>
                                                </div>
                                                <div class="small text-muted" id="bulkSelectedCount">0 selected</div>
                                            </div>
                                            <div class="alert alert-light border py-2 px-3 mb-2 small">
                                                Hierarchy: 1 Academic Year -> 2 Semester -> 3 Subject -> 4 Sectioning -> 5 Enroll Selected Students
                                            </div>

                                            <div class="row g-2 align-items-end">
                                                <div class="col-md-3 col-lg-2">
                                                    <label class="form-label mb-1">1) Academic Year</label>
                                                    <select class="form-select form-select-sm" name="bulk_academic_year" id="bulkAcademicYearSelect" required>
                                                        <option value="">Select</option>
                                                        <?php foreach ($bulkEnrollAcademicYears as $ay): ?>
                                                            <option value="<?php echo htmlspecialchars((string) $ay); ?>"><?php echo htmlspecialchars((string) $ay); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-3 col-lg-2">
                                                    <label class="form-label mb-1">2) Semester</label>
                                                    <select class="form-select form-select-sm" name="bulk_semester" id="bulkSemesterSelect" required>
                                                        <option value="">Select</option>
                                                        <?php foreach ($bulkEnrollSemesters as $sem): ?>
                                                            <option value="<?php echo htmlspecialchars((string) $sem); ?>"><?php echo htmlspecialchars((string) $sem); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-4 col-lg-3">
                                                    <label class="form-label mb-1">3) Subject</label>
                                                    <select class="form-select form-select-sm" name="bulk_subject_id" id="bulkSubjectSelect" required>
                                                        <option value="">Select Subject</option>
                                                        <?php foreach ($bulkEnrollSubjects as $sub): ?>
                                                            <?php
                                                            $subYear = trim((string) ($sub['academic_year'] ?? ''));
                                                            $subSem = trim((string) ($sub['semester'] ?? ''));
                                                            $subLabel = trim((string) ($sub['subject_name'] ?? '') . ' (' . (string) ($sub['subject_code'] ?? '') . ')');
                                                            ?>
                                                            <option
                                                                value="<?php echo (int) ($sub['id'] ?? 0); ?>"
                                                                data-academic-year="<?php echo htmlspecialchars($subYear, ENT_QUOTES); ?>"
                                                                data-semester="<?php echo htmlspecialchars($subSem, ENT_QUOTES); ?>"
                                                            >
                                                                <?php echo htmlspecialchars($subLabel !== '' ? $subLabel : ('Subject #' . (int) ($sub['id'] ?? 0))); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-2 col-lg-2">
                                                    <label class="form-label mb-1">4) Sectioning</label>
                                                    <select class="form-select form-select-sm" name="bulk_section" id="bulkSectionSelect" required>
                                                        <option value="">Select</option>
                                                        <?php foreach ($bulkEnrollSectionOptions as $sec): ?>
                                                            <option value="<?php echo htmlspecialchars((string) ($sec['value'] ?? '')); ?>">
                                                                <?php echo htmlspecialchars((string) ($sec['label'] ?? '')); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-12 col-lg-3">
                                                    <button class="btn btn-sm btn-primary w-100" type="submit" id="bulkEnrollSubmitBtn">
                                                        <i class="ri-user-follow-line me-1" aria-hidden="true"></i>
                                                        Enroll Selected Students
                                                    </button>
                                                </div>
                                            </div>
                                        </form>
                                    <?php endif; ?>

                                    <div class="users-meta">
                                        Showing <strong id="visibleCount"><?php echo (int) $totalUsers; ?></strong> of
                                        <strong id="totalCount"><?php echo (int) $totalUsers; ?></strong> <?php echo htmlspecialchars($accountsMetaLabel); ?>
                                    </div>

                                    <div class="table-responsive users-table-wrap">
                                        <table class="table table-striped table-hover align-middle table-users" id="usersTable">
                                             <thead>
                                                  <tr>
                                                     <?php if ($isStudentScopedPage): ?>
                                                         <th class="bulk-check-col">
                                                             <input class="form-check-input" type="checkbox" id="bulkSelectVisible" aria-label="Select visible students">
                                                         </th>
                                                         <th><button type="button" class="sort-btn" data-sort="name">Student <i class="ri-arrow-up-down-line" aria-hidden="true"></i></button></th>
                                                         <th><button type="button" class="sort-btn" data-sort="program">Program <i class="ri-arrow-up-down-line" aria-hidden="true"></i></button></th>
                                                         <th><button type="button" class="sort-btn" data-sort="year">Year <i class="ri-arrow-up-down-line" aria-hidden="true"></i></button></th>
                                                         <th><button type="button" class="sort-btn" data-sort="section">Section <i class="ri-arrow-up-down-line" aria-hidden="true"></i></button></th>
                                                         <th><button type="button" class="sort-btn" data-sort="email">Login Account <i class="ri-arrow-up-down-line" aria-hidden="true"></i></button></th>
                                                         <th><button type="button" class="sort-btn" data-sort="status">Account Status <i class="ri-arrow-up-down-line" aria-hidden="true"></i></button></th>
                                                         <th><button type="button" class="sort-btn" data-sort="registered">Added <i class="ri-arrow-up-down-line" aria-hidden="true"></i></button></th>
                                                         <th>Actions</th>
                                                     <?php else: ?>
                                                         <th><button type="button" class="sort-btn" data-sort="name">Name <i class="ri-arrow-up-down-line" aria-hidden="true"></i></button></th>
                                                         <th><button type="button" class="sort-btn" data-sort="email">Email <i class="ri-arrow-up-down-line" aria-hidden="true"></i></button></th>
                                                         <th>
                                                            <?php if ($isScopedRolePage): ?>
                                                                Role
                                                            <?php else: ?>
                                                                <button type="button" class="sort-btn" data-sort="role">Role <i class="ri-arrow-up-down-line" aria-hidden="true"></i></button>
                                                            <?php endif; ?>
                                                         </th>
                                                         <th><button type="button" class="sort-btn" data-sort="ai_remaining">Usage Limits <i class="ri-arrow-up-down-line" aria-hidden="true"></i></button></th>
                                                         <th><button type="button" class="sort-btn" data-sort="status">Status <i class="ri-arrow-up-down-line" aria-hidden="true"></i></button></th>
                                                         <th><button type="button" class="sort-btn" data-sort="registered">Registered <i class="ri-arrow-up-down-line" aria-hidden="true"></i></button></th>
                                                         <th>Actions</th>
                                                     <?php endif; ?>
                                                  </tr>
                                             </thead>
                                            <tbody>
                                                <?php if (empty($users)): ?>
                                                    <tr>
                                                        <td colspan="<?php echo (int) $tableColspan; ?>" class="text-center text-muted py-4">
                                                            <?php echo $isStudentScopedPage ? 'No student records found.' : 'No accounts found.'; ?>
                                                        </td>
                                                    </tr>
                                                <?php else: ?>
                                                    <?php foreach ($users as $user): ?>
                                                        <?php
                                                        $isActive = isset($user['is_active']) ? (int) $user['is_active'] : 0;
                                                        $roleValue = isset($user['role']) ? (string) $user['role'] : '';
                                                        $campusIdValue = (int) ($user['campus_id'] ?? 0);
                                                        $isSuperadminValue = ((int) ($user['is_superadmin'] ?? 0) === 1) ? 1 : 0;
                                                        $statusValue = isset($user['account_status']) ? (string) $user['account_status'] : ($isActive === 1 ? 'active' : 'pending');
                                                        if (!in_array($statusValue, ['active', 'pending', 'no_account'], true)) {
                                                            $statusValue = $isActive === 1 ? 'active' : 'pending';
                                                        }
                                                        $roleLabel = $roleValue === 'admin'
                                                            ? 'Campus Admin'
                                                            : ucwords(str_replace('_', ' ', $roleValue));
                                                        $accountUsernameValue = isset($user['username']) ? (string) $user['username'] : '';
                                                        $nameValue = $accountUsernameValue;
                                                        $emailValue = isset($user['useremail']) ? (string) $user['useremail'] : '';
                                                        $userIdValue = (int) ($user['id'] ?? 0);
                                                        $hasLinkedAccount = isset($user['has_account'])
                                                            ? ((int) $user['has_account'] === 1)
                                                            : ($userIdValue > 0);
                                                        $accountRoleValue = normalize_role((string) ($user['account_role'] ?? $roleValue));
                                                        $canManageLinkedAccount = $hasLinkedAccount && $accountRoleValue === 'student';
                                                        $canManageTeacherLinkedAccount = $hasLinkedAccount && $accountRoleValue === 'teacher';
                                                        $studentIdValue = (int) ($user['student_id'] ?? 0);
                                                        $studentNoValue = trim((string) ($user['student_no'] ?? ''));
                                                        $studentSurnameValue = trim((string) ($user['student_surname'] ?? ''));
                                                        $studentFirstnameValue = trim((string) ($user['student_firstname'] ?? ''));
                                                        $studentMiddlenameValue = trim((string) ($user['student_middlename'] ?? ''));
                                                        $studentSexValue = trim((string) ($user['student_sex'] ?? 'M'));
                                                        if (!in_array($studentSexValue, ['M', 'F'], true)) $studentSexValue = 'M';
                                                        $studentProfileStatusValue = trim((string) ($user['student_profile_status'] ?? 'New'));
                                                        if (!in_array($studentProfileStatusValue, ['Continuing', 'New', 'Transferee'], true)) {
                                                            $studentProfileStatusValue = 'New';
                                                        }
                                                        $studentBirthDateValue = trim((string) ($user['student_birth_date'] ?? ''));
                                                        $studentBarangayValue = trim((string) ($user['student_barangay'] ?? ''));
                                                        $studentMunicipalityValue = trim((string) ($user['student_municipality'] ?? ''));
                                                        $studentProvinceValue = trim((string) ($user['student_province'] ?? ''));
                                                        $studentEmailValue = trim((string) ($user['student_email'] ?? ''));
                                                        $studentPhoneValue = trim((string) ($user['student_phone'] ?? ''));
                                                        $studentNameValue = trim((string) ($user['student_name'] ?? ''));
                                                        if ($isStudentScopedPage && $studentNameValue !== '') {
                                                            $nameValue = $studentNameValue;
                                                        }
                                                        $teacherIdValue = (int) ($user['teacher_id'] ?? 0);
                                                        $teacherNoValue = trim((string) ($user['teacher_no'] ?? ''));
                                                        $teacherSurnameValue = trim((string) ($user['teacher_surname'] ?? ''));
                                                        $teacherFirstnameValue = trim((string) ($user['teacher_firstname'] ?? ''));
                                                        $teacherMiddlenameValue = trim((string) ($user['teacher_middlename'] ?? ''));
                                                        $teacherSexValue = trim((string) ($user['teacher_sex'] ?? 'M'));
                                                        if (!in_array($teacherSexValue, ['M', 'F'], true)) $teacherSexValue = 'M';
                                                        $teacherDepartmentValue = trim((string) ($user['teacher_department'] ?? ''));
                                                        $teacherPositionValue = trim((string) ($user['teacher_position'] ?? ''));
                                                        $teacherEmploymentStatusValue = trim((string) ($user['teacher_employment_status'] ?? 'Full-time'));
                                                        if (!in_array($teacherEmploymentStatusValue, ['Full-time', 'Part-time', 'Contractual', 'Visiting'], true)) {
                                                            $teacherEmploymentStatusValue = 'Full-time';
                                                        }
                                                        $teacherProfileStatusValue = trim((string) ($user['teacher_profile_status'] ?? 'Active'));
                                                        if (!in_array($teacherProfileStatusValue, ['Active', 'Inactive', 'OnLeave', 'Retired'], true)) {
                                                            $teacherProfileStatusValue = 'Active';
                                                        }
                                                        $teacherBirthDateValue = trim((string) ($user['teacher_birth_date'] ?? ''));
                                                        $teacherBarangayValue = trim((string) ($user['teacher_barangay'] ?? ''));
                                                        $teacherMunicipalityValue = trim((string) ($user['teacher_municipality'] ?? ''));
                                                        $teacherProvinceValue = trim((string) ($user['teacher_province'] ?? ''));
                                                        $teacherEmailValue = trim((string) ($user['teacher_email'] ?? ''));
                                                        $teacherPhoneValue = trim((string) ($user['teacher_phone'] ?? ''));
                                                        $teacherNameValue = trim((string) ($user['teacher_name'] ?? ''));
                                                        if ($isScopedRolePage && $managedRole === 'teacher' && $teacherNameValue !== '') {
                                                            $nameValue = $teacherNameValue;
                                                        }
                                                        $courseValue = trim((string) ($user['student_course'] ?? ''));
                                                        $majorValue = trim((string) ($user['student_major'] ?? ''));
                                                        $programValue = $courseValue;
                                                        if ($majorValue !== '') {
                                                            $programValue = trim($programValue . ' - ' . $majorValue);
                                                        }
                                                        $yearValue = trim((string) ($user['student_year'] ?? ''));
                                                        $sectionValue = trim((string) ($user['student_section'] ?? ''));
                                                        $sectionProfileKeyValue = strtolower($sectionValue);
                                                        if ($courseValue !== '' && $yearValue !== '' && $sectionValue !== '') {
                                                            $sectionProfileKeyValue = strtolower($courseValue . '|' . $yearValue . '|' . $sectionValue);
                                                        }
                                                        $yearSectionValue = trim($yearValue . ($sectionValue !== '' ? (' / ' . $sectionValue) : ''));
                                                        $statusLabel = $statusValue === 'active'
                                                            ? 'Active'
                                                            : ($statusValue === 'pending' ? 'Pending' : 'No Account');
                                                        $statusClass = $statusValue === 'active'
                                                            ? 'active'
                                                            : ($statusValue === 'pending' ? 'pending' : 'no-account');
                                                        $accountDisplayEmail = trim($emailValue !== '' ? $emailValue : $studentEmailValue);
                                                        if ($accountDisplayEmail === '' && $teacherEmailValue !== '') {
                                                            $accountDisplayEmail = $teacherEmailValue;
                                                        }
                                                        $studentNoGuess = '';
                                                        $emailLocalPart = '';
                                                        if ($emailValue !== '' && strpos($emailValue, '@') !== false) {
                                                            $emailLocalPartRaw = strstr($emailValue, '@', true);
                                                            $emailLocalPart = is_string($emailLocalPartRaw) ? trim($emailLocalPartRaw) : '';
                                                        }
                                                        if ($emailLocalPart !== '' && preg_match('/^[0-9][0-9A-Za-z\\-]{3,19}$/', $emailLocalPart)) {
                                                            $studentNoGuess = $emailLocalPart;
                                                        } elseif ($accountUsernameValue !== '' && preg_match('/^[0-9][0-9A-Za-z\\-]{3,19}$/', $accountUsernameValue)) {
                                                            $studentNoGuess = $accountUsernameValue;
                                                        }
                                                        $teacherNoGuess = '';
                                                        if ($emailLocalPart !== '' && preg_match('/^[0-9A-Za-z][0-9A-Za-z\\-]{2,29}$/', $emailLocalPart)) {
                                                            $teacherNoGuess = $emailLocalPart;
                                                        } elseif ($accountUsernameValue !== '' && preg_match('/^[0-9A-Za-z][0-9A-Za-z\\-]{2,29}$/', $accountUsernameValue)) {
                                                            $teacherNoGuess = $accountUsernameValue;
                                                        }
                                                        $searchText = strtolower(trim(
                                                            $nameValue . ' ' .
                                                            $accountDisplayEmail . ' ' .
                                                            $roleLabel . ' ' .
                                                            $statusValue . ' ' .
                                                            $studentNoValue . ' ' .
                                                            $teacherNoValue . ' ' .
                                                            $programValue . ' ' .
                                                            $yearSectionValue . ' ' .
                                                            $teacherDepartmentValue . ' ' .
                                                            $teacherPositionValue
                                                        ));
                                                        if ($nameValue === '' && $studentNoValue !== '') {
                                                            $nameValue = $studentNoValue;
                                                        }
                                                        $avatarSource = trim($nameValue !== '' ? $nameValue : $emailValue);
                                                        $avatarText = $avatarSource !== '' ? strtoupper(substr($avatarSource, 0, 1)) : '?';
                                                        $aiLimit = ai_credit_clamp_limit((float) ($user['ai_rephrase_credit_limit'] ?? 0));
                                                        $aiUsed = ai_credit_round((float) ($user['ai_rephrase_credit_used'] ?? 0));
                                                        $buildLimitValue = $roleValue === 'teacher' ? (int) ($user['class_record_build_limit'] ?? 4) : -1;
                                                        $buildUsedValue = $roleValue === 'teacher' ? (int) ($user['class_record_build_usage_used'] ?? 0) : -1;
                                                        $buildTotalValue = $roleValue === 'teacher' ? (int) ($teacherBuildCounts[$userIdValue] ?? 0) : 0;
                                                        $createdSort = strtotime((string) ($user['created_at'] ?? ''));
                                                        if ($createdSort === false) $createdSort = 0;
                                                        if ($aiUsed < 0) $aiUsed = 0;
                                                        if ($aiUsed > $aiLimit) $aiUsed = $aiLimit;
                                                        $aiRemaining = ai_credit_round($aiLimit - $aiUsed);
                                                        if ($aiRemaining < 0) $aiRemaining = 0;
                                                        if ($buildUsedValue < 0) $buildUsedValue = 0;
                                                        $buildIsUnlimited = $roleValue === 'teacher' && $buildLimitValue === 0;
                                                        $buildRemainingValue = $roleValue === 'teacher'
                                                            ? ($buildIsUnlimited ? -1 : max($buildLimitValue - $buildUsedValue, 0))
                                                            : -1;
                                                        if ($isStudentScopedPage) {
                                                            ?>
                                                            <tr
                                                                class="user-row"
                                                                data-status="<?php echo htmlspecialchars($statusValue, ENT_QUOTES); ?>"
                                                                data-role="student"
                                                                data-name="<?php echo htmlspecialchars(strtolower($nameValue), ENT_QUOTES); ?>"
                                                                data-email="<?php echo htmlspecialchars(strtolower($accountDisplayEmail), ENT_QUOTES); ?>"
                                                                data-course="<?php echo htmlspecialchars(strtolower($courseValue), ENT_QUOTES); ?>"
                                                                data-program="<?php echo htmlspecialchars(strtolower($programValue), ENT_QUOTES); ?>"
                                                                data-year="<?php echo htmlspecialchars(strtolower($yearValue), ENT_QUOTES); ?>"
                                                                data-section="<?php echo htmlspecialchars(strtolower($sectionValue), ENT_QUOTES); ?>"
                                                                data-section-only="<?php echo htmlspecialchars(strtolower($sectionValue), ENT_QUOTES); ?>"
                                                                data-section-key="<?php echo htmlspecialchars($sectionProfileKeyValue, ENT_QUOTES); ?>"
                                                                data-build-limit="-1"
                                                                data-build-used="-1"
                                                                data-build-remaining="-1"
                                                                data-build-total="0"
                                                                data-ai-limit="0"
                                                                data-ai-used="0"
                                                                data-ai-remaining="0"
                                                                data-created="<?php echo (int) $createdSort; ?>"
                                                                data-search="<?php echo htmlspecialchars($searchText, ENT_QUOTES); ?>"
                                                                data-student-no="<?php echo htmlspecialchars(strtolower($studentNoValue), ENT_QUOTES); ?>"
                                                            >
                                                                <td class="bulk-check-col">
                                                                    <input
                                                                        class="form-check-input bulk-student-checkbox"
                                                                        type="checkbox"
                                                                        value="<?php echo (int) $studentIdValue; ?>"
                                                                        data-student-no="<?php echo htmlspecialchars($studentNoValue, ENT_QUOTES); ?>"
                                                                        <?php echo $studentIdValue > 0 ? '' : 'disabled'; ?>
                                                                        aria-label="Select <?php echo htmlspecialchars($nameValue, ENT_QUOTES); ?>">
                                                                </td>
                                                                <td>
                                                                    <div class="user-ident">
                                                                        <span class="user-avatar"><?php echo htmlspecialchars($avatarText); ?></span>
                                                                        <div class="lh-sm">
                                                                            <div class="fw-semibold"><?php echo htmlspecialchars($nameValue); ?></div>
                                                                            <div class="text-muted small">
                                                                                <?php echo $studentNoValue !== '' ? htmlspecialchars($studentNoValue) : 'No Student Number'; ?>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                </td>
                                                                <td class="text-muted">
                                                                    <?php if ($programValue !== ''): ?>
                                                                        <?php echo htmlspecialchars($programValue); ?>
                                                                    <?php else: ?>
                                                                        <span class="text-muted">Not specified</span>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td class="text-muted">
                                                                    <?php if ($yearValue !== ''): ?>
                                                                        <?php echo htmlspecialchars($yearValue); ?>
                                                                    <?php else: ?>
                                                                        <span class="text-muted">Not specified</span>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td class="text-muted">
                                                                    <?php if ($sectionValue !== ''): ?>
                                                                        <?php echo htmlspecialchars($sectionValue); ?>
                                                                    <?php else: ?>
                                                                        <span class="text-muted">Not specified</span>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td>
                                                                    <?php if ($hasLinkedAccount): ?>
                                                                        <div class="fw-semibold"><?php echo htmlspecialchars($emailValue !== '' ? $emailValue : 'No account email'); ?></div>
                                                                        <div class="text-muted small">
                                                                            Username: <?php echo htmlspecialchars($accountUsernameValue !== '' ? $accountUsernameValue : $nameValue); ?>
                                                                        </div>
                                                                        <?php if ($accountRoleValue !== 'student'): ?>
                                                                            <div class="text-warning small">
                                                                                Linked role: <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $accountRoleValue))); ?>
                                                                            </div>
                                                                        <?php endif; ?>
                                                                        <div class="text-muted small">
                                                                            User ID: <?php echo (int) $userIdValue; ?>
                                                                        </div>
                                                                    <?php else: ?>
                                                                        <span class="status-pill no-account">No linked login account</span>
                                                                        <?php if ($studentEmailValue !== ''): ?>
                                                                            <div class="text-muted small mt-1">Student email: <?php echo htmlspecialchars($studentEmailValue); ?></div>
                                                                        <?php endif; ?>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td><span class="status-pill <?php echo htmlspecialchars($statusClass); ?>"><?php echo htmlspecialchars($statusLabel); ?></span></td>
                                                                <td><?php echo htmlspecialchars((string) ($user['created_at'] ?? '')); ?></td>
                                                                <td>
                                                                    <div class="d-flex align-items-center justify-content-end gap-1 flex-wrap">
                                                                        <?php if ($studentIdValue <= 0 && $hasLinkedAccount && $canManageLinkedAccount): ?>
                                                                            <button
                                                                                type="button"
                                                                                class="btn btn-sm btn-outline-warning js-create-link-profile-btn"
                                                                                data-bs-toggle="modal"
                                                                                data-bs-target="#linkStudentProfileModal"
                                                                                data-user-id="<?php echo (int) $userIdValue; ?>"
                                                                                data-user-name="<?php echo htmlspecialchars($accountUsernameValue !== '' ? $accountUsernameValue : $nameValue, ENT_QUOTES); ?>"
                                                                                data-user-email="<?php echo htmlspecialchars((string) ($emailValue !== '' ? $emailValue : $studentEmailValue), ENT_QUOTES); ?>"
                                                                                data-student-no="<?php echo htmlspecialchars($studentNoGuess, ENT_QUOTES); ?>"
                                                                            >
                                                                                <i class="ri-links-line me-1" aria-hidden="true"></i>
                                                                                Create/Link Profile
                                                                            </button>
                                                                        <?php endif; ?>
                                                                        <?php if ($studentIdValue > 0): ?>
                                                                            <button
                                                                                type="button"
                                                                                class="btn btn-sm btn-outline-warning js-edit-student-profile-btn"
                                                                                data-bs-toggle="modal"
                                                                                data-bs-target="#editStudentProfileModal"
                                                                                data-student-id="<?php echo (int) $studentIdValue; ?>"
                                                                                data-user-id="<?php echo (int) $userIdValue; ?>"
                                                                                data-student-no="<?php echo htmlspecialchars($studentNoValue, ENT_QUOTES); ?>"
                                                                                data-surname="<?php echo htmlspecialchars($studentSurnameValue, ENT_QUOTES); ?>"
                                                                                data-firstname="<?php echo htmlspecialchars($studentFirstnameValue, ENT_QUOTES); ?>"
                                                                                data-middlename="<?php echo htmlspecialchars($studentMiddlenameValue, ENT_QUOTES); ?>"
                                                                                data-sex="<?php echo htmlspecialchars($studentSexValue, ENT_QUOTES); ?>"
                                                                                data-course="<?php echo htmlspecialchars($courseValue, ENT_QUOTES); ?>"
                                                                                data-major="<?php echo htmlspecialchars($majorValue, ENT_QUOTES); ?>"
                                                                                data-status-profile="<?php echo htmlspecialchars($studentProfileStatusValue, ENT_QUOTES); ?>"
                                                                                data-year="<?php echo htmlspecialchars($yearValue, ENT_QUOTES); ?>"
                                                                                data-section="<?php echo htmlspecialchars($sectionValue, ENT_QUOTES); ?>"
                                                                                data-birth-date="<?php echo htmlspecialchars($studentBirthDateValue, ENT_QUOTES); ?>"
                                                                                data-barangay="<?php echo htmlspecialchars($studentBarangayValue, ENT_QUOTES); ?>"
                                                                                data-municipality="<?php echo htmlspecialchars($studentMunicipalityValue, ENT_QUOTES); ?>"
                                                                                data-province="<?php echo htmlspecialchars($studentProvinceValue, ENT_QUOTES); ?>"
                                                                                data-student-email="<?php echo htmlspecialchars($studentEmailValue, ENT_QUOTES); ?>"
                                                                                data-student-phone="<?php echo htmlspecialchars($studentPhoneValue, ENT_QUOTES); ?>"
                                                                            >
                                                                                <i class="ri-profile-line me-1" aria-hidden="true"></i>
                                                                                Edit Profile
                                                                            </button>
                                                                        <?php endif; ?>
                                                                        <?php if ($studentIdValue > 0): ?>
                                                                            <a
                                                                                class="btn btn-sm btn-outline-primary js-view-subjects-btn"
                                                                                href="admin-student-enrollment-details.php?student_id=<?php echo (int) $studentIdValue; ?>"
                                                                            >
                                                                                View Subjects
                                                                            </a>
                                                                        <?php endif; ?>

                                                                    <?php if ($canManageLinkedAccount): ?>
                                                                        <div class="dropdown">
                                                                            <button
                                                                                class="btn btn-sm btn-outline-secondary icon-btn"
                                                                                type="button"
                                                                                data-bs-toggle="dropdown"
                                                                                aria-expanded="false"
                                                                                aria-label="Account actions"
                                                                            >
                                                                                <i class="ri-more-2-fill" aria-hidden="true"></i>
                                                                            </button>
                                                                            <ul class="dropdown-menu dropdown-menu-end">
                                                                                <li>
                                                                                    <button
                                                                                        type="button"
                                                                                        class="dropdown-item d-flex align-items-center gap-2"
                                                                                        data-bs-toggle="modal"
                                                                                        data-bs-target="#editUserModal"
                                                                                        data-user-id="<?php echo (int) $userIdValue; ?>"
                                                                                        data-user-name="<?php echo htmlspecialchars($accountUsernameValue !== '' ? $accountUsernameValue : $nameValue, ENT_QUOTES); ?>"
                                                                                        data-user-email="<?php echo htmlspecialchars((string) ($emailValue !== '' ? $emailValue : $studentEmailValue), ENT_QUOTES); ?>"
                                                                                        data-user-role="student"
                                                                                        data-user-active="<?php echo (int) $isActive; ?>"
                                                                                        data-user-campus-id="<?php echo (int) $campusIdValue; ?>"
                                                                                    >
                                                                                        <i class="ri-edit-line" aria-hidden="true"></i>
                                                                                        <span>Edit Account</span>
                                                                                    </button>
                                                                                </li>
                                                                                <li>
                                                                                    <button
                                                                                        type="button"
                                                                                        class="dropdown-item d-flex align-items-center gap-2"
                                                                                        data-bs-toggle="modal"
                                                                                        data-bs-target="#resetPasswordModal"
                                                                                        data-user-id="<?php echo (int) $userIdValue; ?>"
                                                                                        data-user-name="<?php echo htmlspecialchars($accountUsernameValue !== '' ? $accountUsernameValue : $nameValue, ENT_QUOTES); ?>"
                                                                                        data-user-email="<?php echo htmlspecialchars((string) ($emailValue !== '' ? $emailValue : $studentEmailValue), ENT_QUOTES); ?>"
                                                                                    >
                                                                                        <i class="ri-lock-unlock-line" aria-hidden="true"></i>
                                                                                        <span>Reset Password</span>
                                                                                    </button>
                                                                                </li>
                                                                                <li><hr class="dropdown-divider"></li>
                                                                                <li>
                                                                                    <form method="post" class="m-0">
                                                                                        <input type="hidden" name="user_id" value="<?php echo (int) $userIdValue; ?>">
                                                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                                                                        <?php if ($isActive === 1): ?>
                                                                                            <input type="hidden" name="action" value="revoke">
                                                                                            <button class="dropdown-item text-danger d-flex align-items-center gap-2" type="submit">
                                                                                                <i class="ri-forbid-2-line" aria-hidden="true"></i>
                                                                                                <span>Revoke Access</span>
                                                                                            </button>
                                                                                        <?php else: ?>
                                                                                            <input type="hidden" name="action" value="approve">
                                                                                            <button class="dropdown-item text-success d-flex align-items-center gap-2" type="submit">
                                                                                                <i class="ri-check-line" aria-hidden="true"></i>
                                                                                                <span>Approve Access</span>
                                                                                            </button>
                                                                                        <?php endif; ?>
                                                                                    </form>
                                                                                </li>
                                                                            </ul>
                                                                        </div>
                                                                    <?php elseif ($hasLinkedAccount): ?>
                                                                        <span class="text-muted small">Manage this account from All Accounts.</span>
                                                                    <?php else: ?>
                                                                        <span class="text-muted small">No account actions available</span>
                                                                    <?php endif; ?>
                                                                    </div>
                                                                </td>
                                                            </tr>
                                                            <?php
                                                            continue;
                                                        }
                                                        ?>
                                                        <tr
                                                            class="user-row"
                                                            data-status="<?php echo htmlspecialchars($statusValue, ENT_QUOTES); ?>"
                                                            data-role="<?php echo htmlspecialchars($roleValue, ENT_QUOTES); ?>"
                                                            data-name="<?php echo htmlspecialchars(strtolower($nameValue), ENT_QUOTES); ?>"
                                                            data-email="<?php echo htmlspecialchars(strtolower($emailValue), ENT_QUOTES); ?>"
                                                            data-build-limit="<?php echo (int) $buildLimitValue; ?>"
                                                            data-build-used="<?php echo (int) $buildUsedValue; ?>"
                                                            data-build-remaining="<?php echo (int) $buildRemainingValue; ?>"
                                                            data-build-total="<?php echo (int) $buildTotalValue; ?>"
                                                            data-ai-limit="<?php echo htmlspecialchars(number_format((float) $aiLimit, 2, '.', ''), ENT_QUOTES); ?>"
                                                            data-ai-used="<?php echo htmlspecialchars(number_format((float) $aiUsed, 2, '.', ''), ENT_QUOTES); ?>"
                                                            data-ai-remaining="<?php echo htmlspecialchars(number_format((float) $aiRemaining, 2, '.', ''), ENT_QUOTES); ?>"
                                                            data-created="<?php echo (int) $createdSort; ?>"
                                                            data-search="<?php echo htmlspecialchars($searchText, ENT_QUOTES); ?>"
                                                        >
                                                            <td>
                                                                <div class="user-ident">
                                                                    <span class="user-avatar"><?php echo htmlspecialchars($avatarText); ?></span>
                                                                    <div class="lh-sm">
                                                                        <div class="fw-semibold"><?php echo htmlspecialchars($nameValue); ?></div>
                                                                        <div class="text-muted small d-md-none"><?php echo htmlspecialchars($accountDisplayEmail); ?></div>
                                                                    </div>
                                                                </div>
                                                            </td>
                                                            <td class="text-muted"><?php echo htmlspecialchars($accountDisplayEmail); ?></td>
                                                            <td>
                                                                <?php if ($isScopedRolePage): ?>
                                                                    <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($roleLabel); ?></span>
                                                                <?php else: ?>
                                                                    <form method="post" class="d-flex gap-2 align-items-center inline-compact">
                                                                        <input type="hidden" name="user_id" value="<?php echo (int) $user['id']; ?>">
                                                                        <input type="hidden" name="action" value="set_role">
                                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                                                        <select class="form-select form-select-sm" name="role">
                                                                            <option value="student" <?php echo (($roleValue) === 'student') ? 'selected' : ''; ?>>Student</option>
                                                                            <option value="teacher" <?php echo (($roleValue) === 'teacher') ? 'selected' : ''; ?>>Teacher</option>
                                                                            <option value="registrar" <?php echo (($roleValue) === 'registrar') ? 'selected' : ''; ?>>Registrar</option>
                                                                            <option value="program_chair" <?php echo (($roleValue) === 'program_chair') ? 'selected' : ''; ?>>Program Chair</option>
                                                                            <option value="college_dean" <?php echo (($roleValue) === 'college_dean') ? 'selected' : ''; ?>>College Dean</option>
                                                                            <option value="guardian" <?php echo (($roleValue) === 'guardian') ? 'selected' : ''; ?>>Guardian</option>
                                                                            <?php if ($adminIsSuperadmin): ?>
                                                                                <option value="admin" <?php echo (($roleValue) === 'admin') ? 'selected' : ''; ?>>Campus Admin</option>
                                                                            <?php endif; ?>
                                                                        </select>
                                                                        <button class="btn btn-sm btn-outline-secondary icon-btn action-save" type="submit" data-bs-toggle="tooltip" title="Save" aria-label="Save">
                                                                            <i class="ri-save-3-line" aria-hidden="true"></i>
                                                                        </button>
                                                                    </form>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <div class="d-flex align-items-start justify-content-between gap-2">
                                                                    <div class="small text-muted lh-sm">
                                                                        <div class="usage-cell-metric">
                                                                            <span class="label">Build</span>
                                                                            <span class="value">
                                                                                <?php if ($roleValue === 'teacher'): ?>
                                                                                    <?php echo (int) $buildUsedValue; ?> / <?php echo $buildIsUnlimited ? 'Unlimited' : (int) $buildLimitValue; ?>
                                                                                <?php else: ?>
                                                                                    N/A
                                                                                <?php endif; ?>
                                                                            </span>
                                                                        </div>
                                                                        <?php if ($roleValue === 'teacher'): ?>
                                                                            <div class="small text-muted">Templates: <strong><?php echo (int) $buildTotalValue; ?></strong></div>
                                                                        <?php endif; ?>
                                                                        <div class="usage-cell-metric mt-1">
                                                                            <span class="label">AI</span>
                                                                            <span class="value"><?php echo number_format((float) $aiUsed, 2, '.', ''); ?> used / <?php echo number_format((float) $aiLimit, 2, '.', ''); ?></span>
                                                                        </div>
                                                                        <div class="small text-muted">Remaining: <strong><?php echo number_format((float) $aiRemaining, 2, '.', ''); ?></strong></div>
                                                                    </div>
                                                                    <div class="dropdown">
                                                                        <button
                                                                            class="btn btn-sm btn-outline-secondary icon-btn"
                                                                            type="button"
                                                                            data-bs-toggle="dropdown"
                                                                            data-bs-auto-close="outside"
                                                                            aria-expanded="false"
                                                                            aria-label="Usage limits actions"
                                                                        >
                                                                            <i class="ri-more-2-fill" aria-hidden="true"></i>
                                                                        </button>
                                                                        <div class="dropdown-menu dropdown-menu-end p-2" style="min-width: 300px;">
                                                                            <?php if ($roleValue === 'teacher'): ?>
                                                                                <form method="post" class="d-flex gap-2 align-items-center inline-compact mb-2">
                                                                                    <input type="hidden" name="user_id" value="<?php echo $userIdValue; ?>">
                                                                                    <input type="hidden" name="action" value="set_build_limit">
                                                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                                                                    <label class="small text-muted mb-0" for="build-limit-<?php echo (int) $user['id']; ?>">Build</label>
                                                                                    <input
                                                                                        id="build-limit-<?php echo (int) $user['id']; ?>"
                                                                                        class="form-control form-control-sm limit-input"
                                                                                        type="number"
                                                                                        name="class_record_build_limit"
                                                                                        min="0"
                                                                                        max="50"
                                                                                        value="<?php echo (int) ($user['class_record_build_limit'] ?? 4); ?>"
                                                                                        aria-label="Class Record Build limit"
                                                                                    >
                                                                                    <button class="btn btn-sm btn-outline-secondary icon-btn action-save" type="submit" title="Save build limit" aria-label="Save build limit">
                                                                                        <i class="ri-save-3-line" aria-hidden="true"></i>
                                                                                    </button>
                                                                                </form>
                                                                            <?php endif; ?>
                                                                            <form method="post" class="d-flex gap-2 align-items-center inline-compact mb-2">
                                                                                <input type="hidden" name="user_id" value="<?php echo $userIdValue; ?>">
                                                                                <input type="hidden" name="action" value="set_ai_credit_limit">
                                                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                                                                <label class="small text-muted mb-0" for="ai-limit-<?php echo (int) $user['id']; ?>">AI</label>
                                                                                <input
                                                                                    id="ai-limit-<?php echo (int) $user['id']; ?>"
                                                                                    class="form-control form-control-sm credit-input"
                                                                                    type="number"
                                                                                    name="ai_rephrase_credit_limit"
                                                                                    min="0"
                                                                                    max="10000"
                                                                                    step="0.10"
                                                                                    value="<?php echo htmlspecialchars(number_format((float) $aiLimit, 2, '.', '')); ?>"
                                                                                    aria-label="AI re-phrase credit limit"
                                                                                >
                                                                                <button class="btn btn-sm btn-outline-secondary icon-btn action-save" type="submit" title="Save AI credit limit" aria-label="Save AI credit limit">
                                                                                    <i class="ri-save-3-line" aria-hidden="true"></i>
                                                                                </button>
                                                                            </form>
                                                                            <hr class="dropdown-divider my-2">
                                                                            <form method="post" class="m-0">
                                                                                <input type="hidden" name="user_id" value="<?php echo $userIdValue; ?>">
                                                                                <input type="hidden" name="action" value="refresh_ai_usage">
                                                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                                                                <button type="submit" class="dropdown-item d-flex align-items-center gap-2">
                                                                                    <i class="ri-refresh-line" aria-hidden="true"></i>
                                                                                    <span>Refresh AI Usage</span>
                                                                                </button>
                                                                            </form>
                                                                            <?php if ($roleValue === 'teacher'): ?>
                                                                                <form method="post" class="m-0">
                                                                                    <input type="hidden" name="user_id" value="<?php echo $userIdValue; ?>">
                                                                                    <input type="hidden" name="action" value="refresh_build_usage">
                                                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                                                                    <button type="submit" class="dropdown-item d-flex align-items-center gap-2">
                                                                                        <i class="ri-refresh-line" aria-hidden="true"></i>
                                                                                        <span>Refresh Build Usage</span>
                                                                                    </button>
                                                                                </form>
                                                                            <?php endif; ?>
                                                                            <form method="post" class="m-0">
                                                                                <input type="hidden" name="user_id" value="<?php echo $userIdValue; ?>">
                                                                                <input type="hidden" name="action" value="refresh_usage_all">
                                                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                                                                <button type="submit" class="dropdown-item d-flex align-items-center gap-2">
                                                                                    <i class="ri-refresh-double-line" aria-hidden="true"></i>
                                                                                    <span>Refresh All Usage</span>
                                                                                </button>
                                                                            </form>
                                                                            <hr class="dropdown-divider my-2">
                                                                            <button
                                                                                type="button"
                                                                                class="dropdown-item d-flex align-items-center gap-2 usage-dashboard-trigger"
                                                                                data-bs-toggle="modal"
                                                                                data-bs-target="#usageInsightsModal"
                                                                                data-user-id="<?php echo $userIdValue; ?>"
                                                                                data-user-name="<?php echo htmlspecialchars($nameValue, ENT_QUOTES); ?>"
                                                                                data-user-email="<?php echo htmlspecialchars($accountDisplayEmail, ENT_QUOTES); ?>"
                                                                                data-user-role="<?php echo htmlspecialchars($roleValue, ENT_QUOTES); ?>"
                                                                                data-ai-limit="<?php echo htmlspecialchars(number_format((float) $aiLimit, 2, '.', ''), ENT_QUOTES); ?>"
                                                                                data-ai-used="<?php echo htmlspecialchars(number_format((float) $aiUsed, 2, '.', ''), ENT_QUOTES); ?>"
                                                                                data-ai-remaining="<?php echo htmlspecialchars(number_format((float) $aiRemaining, 2, '.', ''), ENT_QUOTES); ?>"
                                                                                data-build-limit="<?php echo (int) $buildLimitValue; ?>"
                                                                                data-build-used="<?php echo (int) $buildUsedValue; ?>"
                                                                                data-build-remaining="<?php echo (int) $buildRemainingValue; ?>"
                                                                                data-build-total="<?php echo (int) $buildTotalValue; ?>"
                                                                            >
                                                                                <i class="ri-bar-chart-box-line" aria-hidden="true"></i>
                                                                                <span>Usage Dashboard</span>
                                                                            </button>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </td>
                                                            <td>
                                                                <?php if ($isActive === 1): ?>
                                                                    <span class="status-pill active">Active</span>
                                                                <?php else: ?>
                                                                    <span class="status-pill pending">Pending</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td><?php echo htmlspecialchars($user['created_at']); ?></td>
                                                            <td>
                                                                <div class="d-flex align-items-center justify-content-end gap-1 flex-wrap">
                                                                    <?php if ($isScopedRolePage && $managedRole === 'teacher' && $teacherIdValue <= 0 && $hasLinkedAccount && $canManageTeacherLinkedAccount): ?>
                                                                        <button
                                                                            type="button"
                                                                            class="btn btn-sm btn-outline-warning"
                                                                            data-bs-toggle="modal"
                                                                            data-bs-target="#linkTeacherProfileModal"
                                                                            data-user-id="<?php echo (int) $userIdValue; ?>"
                                                                            data-user-name="<?php echo htmlspecialchars($accountUsernameValue !== '' ? $accountUsernameValue : $nameValue, ENT_QUOTES); ?>"
                                                                            data-user-email="<?php echo htmlspecialchars((string) ($emailValue !== '' ? $emailValue : $teacherEmailValue), ENT_QUOTES); ?>"
                                                                            data-teacher-no="<?php echo htmlspecialchars($teacherNoGuess, ENT_QUOTES); ?>"
                                                                        >
                                                                            <i class="ri-links-line me-1" aria-hidden="true"></i>
                                                                            Create/Link Profile
                                                                        </button>
                                                                    <?php endif; ?>
                                                                    <?php if ($isScopedRolePage && $managedRole === 'teacher' && $teacherIdValue > 0): ?>
                                                                        <button
                                                                            type="button"
                                                                            class="btn btn-sm btn-outline-warning"
                                                                            data-bs-toggle="modal"
                                                                            data-bs-target="#editTeacherProfileModal"
                                                                            data-teacher-id="<?php echo (int) $teacherIdValue; ?>"
                                                                            data-user-id="<?php echo (int) $userIdValue; ?>"
                                                                            data-teacher-no="<?php echo htmlspecialchars($teacherNoValue, ENT_QUOTES); ?>"
                                                                            data-surname="<?php echo htmlspecialchars($teacherSurnameValue, ENT_QUOTES); ?>"
                                                                            data-firstname="<?php echo htmlspecialchars($teacherFirstnameValue, ENT_QUOTES); ?>"
                                                                            data-middlename="<?php echo htmlspecialchars($teacherMiddlenameValue, ENT_QUOTES); ?>"
                                                                            data-sex="<?php echo htmlspecialchars($teacherSexValue, ENT_QUOTES); ?>"
                                                                            data-department="<?php echo htmlspecialchars($teacherDepartmentValue, ENT_QUOTES); ?>"
                                                                            data-position="<?php echo htmlspecialchars($teacherPositionValue, ENT_QUOTES); ?>"
                                                                            data-employment-status="<?php echo htmlspecialchars($teacherEmploymentStatusValue, ENT_QUOTES); ?>"
                                                                            data-status-profile="<?php echo htmlspecialchars($teacherProfileStatusValue, ENT_QUOTES); ?>"
                                                                            data-birth-date="<?php echo htmlspecialchars($teacherBirthDateValue, ENT_QUOTES); ?>"
                                                                            data-barangay="<?php echo htmlspecialchars($teacherBarangayValue, ENT_QUOTES); ?>"
                                                                            data-municipality="<?php echo htmlspecialchars($teacherMunicipalityValue, ENT_QUOTES); ?>"
                                                                            data-province="<?php echo htmlspecialchars($teacherProvinceValue, ENT_QUOTES); ?>"
                                                                            data-teacher-email="<?php echo htmlspecialchars($teacherEmailValue, ENT_QUOTES); ?>"
                                                                            data-teacher-phone="<?php echo htmlspecialchars($teacherPhoneValue, ENT_QUOTES); ?>"
                                                                        >
                                                                            <i class="ri-profile-line me-1" aria-hidden="true"></i>
                                                                            Edit Profile
                                                                        </button>
                                                                    <?php endif; ?>
                                                                    <div class="dropdown">
                                                                        <button
                                                                            class="btn btn-sm btn-outline-secondary icon-btn"
                                                                            type="button"
                                                                            data-bs-toggle="dropdown"
                                                                            aria-expanded="false"
                                                                            aria-label="Account actions"
                                                                        >
                                                                            <i class="ri-more-2-fill" aria-hidden="true"></i>
                                                                        </button>
                                                                        <ul class="dropdown-menu dropdown-menu-end">
                                                                            <li>
                                                                                <button
                                                                                    type="button"
                                                                                    class="dropdown-item d-flex align-items-center gap-2"
                                                                                    data-bs-toggle="modal"
                                                                                    data-bs-target="#editUserModal"
                                                                                    data-user-id="<?php echo (int) $user['id']; ?>"
                                                                                    data-user-name="<?php echo htmlspecialchars($nameValue, ENT_QUOTES); ?>"
                                                                                    data-user-email="<?php echo htmlspecialchars($accountDisplayEmail, ENT_QUOTES); ?>"
                                                                                    data-user-role="<?php echo htmlspecialchars($roleValue, ENT_QUOTES); ?>"
                                                                                    data-user-active="<?php echo (int) $isActive; ?>"
                                                                                    data-user-campus-id="<?php echo (int) $campusIdValue; ?>"
                                                                                >
                                                                                    <i class="ri-edit-line" aria-hidden="true"></i>
                                                                                    <span>Edit Account</span>
                                                                                </button>
                                                                            </li>
                                                                            <li>
                                                                                <button
                                                                                    type="button"
                                                                                    class="dropdown-item d-flex align-items-center gap-2"
                                                                                    data-bs-toggle="modal"
                                                                                    data-bs-target="#resetPasswordModal"
                                                                                    data-user-id="<?php echo (int) $user['id']; ?>"
                                                                                    data-user-name="<?php echo htmlspecialchars($nameValue, ENT_QUOTES); ?>"
                                                                                    data-user-email="<?php echo htmlspecialchars($accountDisplayEmail, ENT_QUOTES); ?>"
                                                                                >
                                                                                    <i class="ri-lock-unlock-line" aria-hidden="true"></i>
                                                                                    <span>Reset Password</span>
                                                                                </button>
                                                                            </li>
                                                                            <li><hr class="dropdown-divider"></li>
                                                                            <li>
                                                                                <form method="post" class="m-0">
                                                                                    <input type="hidden" name="user_id" value="<?php echo (int) $user['id']; ?>">
                                                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                                                                    <?php if ($isActive === 1): ?>
                                                                                        <input type="hidden" name="action" value="revoke">
                                                                                        <button class="dropdown-item text-danger d-flex align-items-center gap-2" type="submit">
                                                                                            <i class="ri-forbid-2-line" aria-hidden="true"></i>
                                                                                            <span>Revoke Access</span>
                                                                                        </button>
                                                                                    <?php else: ?>
                                                                                        <input type="hidden" name="action" value="approve">
                                                                                        <button class="dropdown-item text-success d-flex align-items-center gap-2" type="submit">
                                                                                            <i class="ri-check-line" aria-hidden="true"></i>
                                                                                            <span>Approve Access</span>
                                                                                        </button>
                                                                                    <?php endif; ?>
                                                                                </form>
                                                                            </li>
                                                                        </ul>
                                                                    </div>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                    <tr id="filterEmptyRow" class="d-none">
                                                        <td colspan="<?php echo (int) $tableColspan; ?>" class="text-center text-muted py-4">
                                                            <?php echo $isStudentScopedPage ? 'No student records match the selected filters.' : 'No accounts match the selected filters.'; ?>
                                                        </td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <?php if (!empty($users)): ?>
                                        <div class="users-pagination">
                                            <div class="small text-muted" id="paginationInfo"></div>
                                            <div class="d-flex align-items-center gap-2">
                                                <button type="button" class="btn btn-sm btn-outline-secondary" id="pagePrevBtn">Previous</button>
                                                <span class="small text-muted fw-semibold" id="pageLabel">Page 1 of 1</span>
                                                <button type="button" class="btn btn-sm btn-outline-secondary" id="pageNextBtn">Next</button>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                </div> <!-- end card-body -->
                            </div> <!-- end card -->
                        </div> <!-- end col -->
                    </div>

                </div> <!-- container -->

            </div> <!-- content -->

            <?php include '../layouts/footer.php'; ?>

        </div>

        <!-- ============================================================== -->
        <!-- End Page content -->
        <!-- ============================================================== -->

    </div>
    <!-- END wrapper -->

    <?php include '../layouts/right-sidebar.php'; ?>
    <?php include '../layouts/footer-scripts.php'; ?>

    <!-- Create User Modal -->
    <div class="modal fade" id="createUserModal" tabindex="-1" aria-labelledby="createUserModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-primary">
                <form method="post">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="createUserModalLabel">
                            <i class="ri-user-add-line me-1" aria-hidden="true"></i>
                            <?php echo htmlspecialchars($createButtonLabel); ?>
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                        <input type="hidden" name="action" value="create_user">

                        <div class="mb-3">
                            <label class="form-label" for="createUsername">Name</label>
                            <input type="text" class="form-control" id="createUsername" name="username" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="createUserEmail">Email</label>
                            <input type="email" class="form-control" id="createUserEmail" name="useremail" required>
                        </div>
                        <?php if ($isScopedRolePage): ?>
                            <input type="hidden" name="role" value="<?php echo htmlspecialchars($managedRole); ?>">
                            <div class="mb-3">
                                <label class="form-label">Role</label>
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($roleLabelSingular); ?>" readonly>
                            </div>
                        <?php else: ?>
                            <div class="mb-3">
                                <label class="form-label" for="createUserRole">Role</label>
                                <select class="form-select" id="createUserRole" name="role" required>
                                    <option value="student">Student</option>
                                    <option value="teacher">Teacher</option>
                                    <option value="registrar">Registrar</option>
                                    <option value="program_chair">Program Chair</option>
                                    <option value="college_dean">College Dean</option>
                                    <option value="guardian">Guardian</option>
                                    <?php if ($adminIsSuperadmin): ?>
                                        <option value="admin">Campus Admin</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                        <?php if ($adminIsSuperadmin): ?>
                            <div class="mb-3">
                                <label class="form-label" for="createCampusId">Campus</label>
                                <select class="form-select" id="createCampusId" name="campus_id" required>
                                    <?php foreach ($campusOptions as $campus): ?>
                                        <?php $cid = (int) ($campus['id'] ?? 0); ?>
                                        <option value="<?php echo $cid; ?>" <?php echo ($cid === (int) $defaultCampusId) ? 'selected' : ''; ?>>
                                            <?php
                                            $campusName = trim((string) ($campus['campus_name'] ?? 'Campus'));
                                            $campusCode = trim((string) ($campus['campus_code'] ?? ''));
                                            echo htmlspecialchars($campusCode !== '' ? ($campusName . ' (' . $campusCode . ')') : $campusName);
                                            ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php else: ?>
                            <input type="hidden" name="campus_id" value="<?php echo (int) $adminCampusId; ?>">
                        <?php endif; ?>
                        <div class="mb-3">
                            <label class="form-label" for="createIsActive">Status</label>
                            <select class="form-select" id="createIsActive" name="is_active">
                                <option value="0" selected>Pending</option>
                                <option value="1">Active</option>
                            </select>
                        </div>
                        <div class="mb-0">
                            <label class="form-label" for="createUserPassword">Password</label>
                            <input type="password" class="form-control" id="createUserPassword" name="password" minlength="8" required>
                            <small class="text-muted">Minimum 8 characters.</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="ri-check-line me-1" aria-hidden="true"></i>
                            <?php echo htmlspecialchars($createButtonLabel); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-primary">
                <form method="post">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="editUserModalLabel">
                            <i class="ri-edit-2-line me-1" aria-hidden="true"></i>
                            Edit <?php echo htmlspecialchars($roleLabelSingular); ?>
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                        <input type="hidden" name="action" value="edit_user">
                        <input type="hidden" name="user_id" id="editUserId" value="">

                        <div class="mb-3">
                            <label class="form-label" for="editUsername">Name</label>
                            <input type="text" class="form-control" id="editUsername" name="username" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="editUserEmail">Email</label>
                            <input type="email" class="form-control" id="editUserEmail" name="useremail" required>
                        </div>
                        <?php if ($isScopedRolePage): ?>
                            <input type="hidden" name="role" value="<?php echo htmlspecialchars($managedRole); ?>">
                            <div class="mb-3">
                                <label class="form-label">Role</label>
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($roleLabelSingular); ?>" readonly>
                            </div>
                        <?php else: ?>
                            <div class="mb-3">
                                <label class="form-label" for="editUserRole">Role</label>
                                <select class="form-select" id="editUserRole" name="role" required>
                                    <option value="student">Student</option>
                                    <option value="teacher">Teacher</option>
                                    <option value="registrar">Registrar</option>
                                    <option value="program_chair">Program Chair</option>
                                    <option value="college_dean">College Dean</option>
                                    <option value="guardian">Guardian</option>
                                    <?php if ($adminIsSuperadmin): ?>
                                        <option value="admin">Campus Admin</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                        <?php if ($adminIsSuperadmin): ?>
                            <div class="mb-3">
                                <label class="form-label" for="editCampusId">Campus</label>
                                <select class="form-select" id="editCampusId" name="campus_id" required>
                                    <?php foreach ($campusOptions as $campus): ?>
                                        <?php $cid = (int) ($campus['id'] ?? 0); ?>
                                        <option value="<?php echo $cid; ?>">
                                            <?php
                                            $campusName = trim((string) ($campus['campus_name'] ?? 'Campus'));
                                            $campusCode = trim((string) ($campus['campus_code'] ?? ''));
                                            echo htmlspecialchars($campusCode !== '' ? ($campusName . ' (' . $campusCode . ')') : $campusName);
                                            ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php else: ?>
                            <input type="hidden" name="campus_id" value="<?php echo (int) $adminCampusId; ?>">
                        <?php endif; ?>
                        <div class="mb-3">
                            <label class="form-label" for="editIsActive">Status</label>
                            <select class="form-select" id="editIsActive" name="is_active">
                                <option value="0">Pending</option>
                                <option value="1">Active</option>
                            </select>
                        </div>
                        <div class="mb-0">
                            <label class="form-label" for="editUserPassword">New Password (optional)</label>
                            <input type="password" class="form-control" id="editUserPassword" name="password" minlength="8">
                            <small class="text-muted">Leave blank to keep current password.</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="ri-save-3-line me-1" aria-hidden="true"></i>
                            Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php if ($isStudentScopedPage): ?>
    <!-- Link/Create Student Profile Modal -->
    <div class="modal fade" id="linkStudentProfileModal" tabindex="-1" aria-labelledby="linkStudentProfileModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-warning">
                <form method="post" id="linkStudentProfileForm">
                    <div class="modal-header bg-warning text-dark">
                        <h5 class="modal-title" id="linkStudentProfileModalLabel">
                            <i class="ri-user-shared-line me-1" aria-hidden="true"></i>
                            Create/Link Student Profile
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                        <input type="hidden" name="action" value="link_or_create_student_profile">
                        <input type="hidden" name="user_id" id="linkProfileUserId" value="">

                        <div class="alert alert-warning py-2 mb-3">
                            <div class="fw-semibold" id="linkProfileAccountLabel">Student account</div>
                            <div class="small text-muted" id="linkProfileAccountEmail"></div>
                        </div>

                        <div class="row g-2">
                            <div class="col-md-5">
                                <label class="form-label" for="profileMode">Action</label>
                                <select class="form-select" id="profileMode" name="profile_mode">
                                    <option value="create" selected>Create new profile and link</option>
                                    <option value="link">Link to existing profile</option>
                                </select>
                            </div>
                            <div class="col-md-7">
                                <label class="form-label" for="profileStudentNo">Student ID (Student No)</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    id="profileStudentNo"
                                    name="student_no"
                                    maxlength="20"
                                    required
                                    placeholder="e.g. 2110069-1">
                            </div>
                        </div>

                        <div id="createProfileFields" class="mt-3">
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <label class="form-label" for="profileSurname">Surname</label>
                                    <input type="text" class="form-control" id="profileSurname" name="surname" maxlength="50" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="profileFirstname">First Name</label>
                                    <input type="text" class="form-control" id="profileFirstname" name="firstname" maxlength="50" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="profileMiddlename">Middle Name</label>
                                    <input type="text" class="form-control" id="profileMiddlename" name="middlename" maxlength="50">
                                </div>
                            </div>

                            <div class="row g-2 mt-1">
                                <div class="col-md-3">
                                    <label class="form-label" for="profileSex">Sex</label>
                                    <select class="form-select" id="profileSex" name="sex">
                                        <option value="M" selected>M</option>
                                        <option value="F">F</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="profileStatus">Status</label>
                                    <select class="form-select" id="profileStatus" name="student_status">
                                        <option value="New" selected>New</option>
                                        <option value="Continuing">Continuing</option>
                                        <option value="Transferee">Transferee</option>
                                    </select>
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label" for="profileEmail">Student Email</label>
                                    <input type="email" class="form-control" id="profileEmail" name="student_email" maxlength="100" placeholder="Optional">
                                </div>
                            </div>

                            <div class="row g-2 mt-1">
                                <div class="col-md-4">
                                    <label class="form-label" for="profileCourse">Course</label>
                                    <input type="text" class="form-control" id="profileCourse" name="course" maxlength="100" required placeholder="e.g. BSInfoTech">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="profileMajor">Major</label>
                                    <input type="text" class="form-control" id="profileMajor" name="major" maxlength="100" placeholder="Optional">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label" for="profileYear">Year</label>
                                    <input type="text" class="form-control" id="profileYear" name="year" maxlength="20" required placeholder="e.g. 3rd Year">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label" for="profileSection">Section</label>
                                    <input type="text" class="form-control" id="profileSection" name="section" maxlength="20" placeholder="e.g. B">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning text-dark">
                            <i class="ri-check-line me-1" aria-hidden="true"></i>
                            Save Link
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <!-- Edit Student Profile Modal -->
    <div class="modal fade" id="editStudentProfileModal" tabindex="-1" aria-labelledby="editStudentProfileModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content border-warning">
                <form method="post" id="editStudentProfileForm">
                    <div class="modal-header bg-warning text-dark">
                        <h5 class="modal-title" id="editStudentProfileModalLabel">
                            <i class="ri-profile-line me-1" aria-hidden="true"></i>
                            Edit Student Profile
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                        <input type="hidden" name="action" value="update_student_profile">
                        <input type="hidden" name="student_id" id="editStudentProfileId" value="">
                        <input type="hidden" name="user_id" id="editStudentProfileUserId" value="">

                        <div class="row g-2">
                            <div class="col-md-3">
                                <label class="form-label" for="editStudentNo">Student ID</label>
                                <input type="text" class="form-control" id="editStudentNo" name="student_no" maxlength="20" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="editStudentSurname">Surname</label>
                                <input type="text" class="form-control" id="editStudentSurname" name="surname" maxlength="50" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="editStudentFirstname">First Name</label>
                                <input type="text" class="form-control" id="editStudentFirstname" name="firstname" maxlength="50" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="editStudentMiddlename">Middle Name</label>
                                <input type="text" class="form-control" id="editStudentMiddlename" name="middlename" maxlength="50">
                            </div>
                        </div>

                        <div class="row g-2 mt-1">
                            <div class="col-md-2">
                                <label class="form-label" for="editStudentSex">Sex</label>
                                <select class="form-select" id="editStudentSex" name="sex">
                                    <option value="M">M</option>
                                    <option value="F">F</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="editStudentStatus">Status</label>
                                <select class="form-select" id="editStudentStatus" name="student_status">
                                    <option value="New">New</option>
                                    <option value="Continuing">Continuing</option>
                                    <option value="Transferee">Transferee</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="editStudentBirthDate">Birth Date</label>
                                <input type="date" class="form-control" id="editStudentBirthDate" name="birth_date">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="editStudentEmail">Student Email</label>
                                <input type="email" class="form-control" id="editStudentEmail" name="student_email" maxlength="100">
                            </div>
                        </div>

                        <div class="row g-2 mt-1">
                            <div class="col-md-4">
                                <label class="form-label" for="editStudentCourse">Course</label>
                                <input type="text" class="form-control" id="editStudentCourse" name="course" maxlength="100" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="editStudentMajor">Major</label>
                                <input type="text" class="form-control" id="editStudentMajor" name="major" maxlength="100">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label" for="editStudentYear">Year</label>
                                <input type="text" class="form-control" id="editStudentYear" name="year" maxlength="20" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label" for="editStudentSection">Section</label>
                                <input type="text" class="form-control" id="editStudentSection" name="section" maxlength="20">
                            </div>
                        </div>

                        <div class="row g-2 mt-1">
                            <div class="col-md-4">
                                <label class="form-label" for="editStudentBarangay">Barangay</label>
                                <input type="text" class="form-control" id="editStudentBarangay" name="barangay" maxlength="100">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="editStudentMunicipality">Municipality</label>
                                <input type="text" class="form-control" id="editStudentMunicipality" name="municipality" maxlength="100">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="editStudentProvince">Province</label>
                                <input type="text" class="form-control" id="editStudentProvince" name="province" maxlength="100">
                            </div>
                        </div>

                        <div class="row g-2 mt-1">
                            <div class="col-md-4">
                                <label class="form-label" for="editStudentPhone">Phone</label>
                                <input type="text" class="form-control" id="editStudentPhone" name="phone" maxlength="20">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning text-dark">
                            <i class="ri-save-3-line me-1" aria-hidden="true"></i>
                            Save Profile
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($isScopedRolePage && $managedRole === 'teacher'): ?>
    <!-- Link/Create Teacher Profile Modal -->
    <div class="modal fade" id="linkTeacherProfileModal" tabindex="-1" aria-labelledby="linkTeacherProfileModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content border-warning">
                <form method="post" id="linkTeacherProfileForm">
                    <div class="modal-header bg-warning text-dark">
                        <h5 class="modal-title" id="linkTeacherProfileModalLabel">
                            <i class="ri-user-shared-line me-1" aria-hidden="true"></i>
                            Create/Link Teacher Profile
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                        <input type="hidden" name="action" value="link_or_create_teacher_profile">
                        <input type="hidden" name="user_id" id="linkTeacherProfileUserId" value="">

                        <div class="alert alert-warning py-2 mb-3">
                            <div class="fw-semibold" id="linkTeacherAccountLabel">Teacher account</div>
                            <div class="small text-muted" id="linkTeacherAccountEmail"></div>
                        </div>

                        <div class="row g-2">
                            <div class="col-md-4">
                                <label class="form-label" for="teacherProfileMode">Action</label>
                                <select class="form-select" id="teacherProfileMode" name="profile_mode">
                                    <option value="create" selected>Create new profile and link</option>
                                    <option value="link">Link to existing profile</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="teacherProfileNo">Teacher No</label>
                                <input type="text" class="form-control" id="teacherProfileNo" name="teacher_no" maxlength="30" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="teacherProfileEmail">Teacher Email</label>
                                <input type="email" class="form-control" id="teacherProfileEmail" name="teacher_email" maxlength="100">
                            </div>
                        </div>

                        <div id="createTeacherProfileFields" class="mt-3">
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <label class="form-label" for="teacherProfileSurname">Surname</label>
                                    <input type="text" class="form-control" id="teacherProfileSurname" name="surname" maxlength="50" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="teacherProfileFirstname">First Name</label>
                                    <input type="text" class="form-control" id="teacherProfileFirstname" name="firstname" maxlength="50" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="teacherProfileMiddlename">Middle Name</label>
                                    <input type="text" class="form-control" id="teacherProfileMiddlename" name="middlename" maxlength="50">
                                </div>
                            </div>

                            <div class="row g-2 mt-1">
                                <div class="col-md-2">
                                    <label class="form-label" for="teacherProfileSex">Sex</label>
                                    <select class="form-select" id="teacherProfileSex" name="sex">
                                        <option value="M" selected>M</option>
                                        <option value="F">F</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" for="teacherProfileEmploymentStatus">Employment</label>
                                    <select class="form-select" id="teacherProfileEmploymentStatus" name="employment_status">
                                        <option value="Full-time" selected>Full-time</option>
                                        <option value="Part-time">Part-time</option>
                                        <option value="Contractual">Contractual</option>
                                        <option value="Visiting">Visiting</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" for="teacherProfileStatus">Profile Status</label>
                                    <select class="form-select" id="teacherProfileStatus" name="teacher_status">
                                        <option value="Active" selected>Active</option>
                                        <option value="Inactive">Inactive</option>
                                        <option value="OnLeave">On Leave</option>
                                        <option value="Retired">Retired</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="teacherProfileBirthDate">Birth Date</label>
                                    <input type="date" class="form-control" id="teacherProfileBirthDate" name="birth_date">
                                </div>
                            </div>

                            <div class="row g-2 mt-1">
                                <div class="col-md-4">
                                    <label class="form-label" for="teacherProfileDepartment">Department</label>
                                    <input type="text" class="form-control" id="teacherProfileDepartment" name="department" maxlength="100">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="teacherProfilePosition">Position</label>
                                    <input type="text" class="form-control" id="teacherProfilePosition" name="position" maxlength="100">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="teacherProfilePhone">Phone</label>
                                    <input type="text" class="form-control" id="teacherProfilePhone" name="phone" maxlength="20">
                                </div>
                            </div>

                            <div class="row g-2 mt-1">
                                <div class="col-md-4">
                                    <label class="form-label" for="teacherProfileBarangay">Barangay</label>
                                    <input type="text" class="form-control" id="teacherProfileBarangay" name="barangay" maxlength="100">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="teacherProfileMunicipality">Municipality</label>
                                    <input type="text" class="form-control" id="teacherProfileMunicipality" name="municipality" maxlength="100">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="teacherProfileProvince">Province</label>
                                    <input type="text" class="form-control" id="teacherProfileProvince" name="province" maxlength="100">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning text-dark">
                            <i class="ri-check-line me-1" aria-hidden="true"></i>
                            Save Link
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Teacher Profile Modal -->
    <div class="modal fade" id="editTeacherProfileModal" tabindex="-1" aria-labelledby="editTeacherProfileModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content border-warning">
                <form method="post" id="editTeacherProfileForm">
                    <div class="modal-header bg-warning text-dark">
                        <h5 class="modal-title" id="editTeacherProfileModalLabel">
                            <i class="ri-profile-line me-1" aria-hidden="true"></i>
                            Edit Teacher Profile
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                        <input type="hidden" name="action" value="update_teacher_profile">
                        <input type="hidden" name="teacher_id" id="editTeacherProfileId" value="">
                        <input type="hidden" name="user_id" id="editTeacherProfileUserId" value="">

                        <div class="row g-2">
                            <div class="col-md-3">
                                <label class="form-label" for="editTeacherNo">Teacher No</label>
                                <input type="text" class="form-control" id="editTeacherNo" name="teacher_no" maxlength="30" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="editTeacherSurname">Surname</label>
                                <input type="text" class="form-control" id="editTeacherSurname" name="surname" maxlength="50" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="editTeacherFirstname">First Name</label>
                                <input type="text" class="form-control" id="editTeacherFirstname" name="firstname" maxlength="50" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="editTeacherMiddlename">Middle Name</label>
                                <input type="text" class="form-control" id="editTeacherMiddlename" name="middlename" maxlength="50">
                            </div>
                        </div>

                        <div class="row g-2 mt-1">
                            <div class="col-md-2">
                                <label class="form-label" for="editTeacherSex">Sex</label>
                                <select class="form-select" id="editTeacherSex" name="sex">
                                    <option value="M">M</option>
                                    <option value="F">F</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="editTeacherEmploymentStatus">Employment</label>
                                <select class="form-select" id="editTeacherEmploymentStatus" name="employment_status">
                                    <option value="Full-time">Full-time</option>
                                    <option value="Part-time">Part-time</option>
                                    <option value="Contractual">Contractual</option>
                                    <option value="Visiting">Visiting</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="editTeacherStatus">Profile Status</label>
                                <select class="form-select" id="editTeacherStatus" name="teacher_status">
                                    <option value="Active">Active</option>
                                    <option value="Inactive">Inactive</option>
                                    <option value="OnLeave">On Leave</option>
                                    <option value="Retired">Retired</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="editTeacherBirthDate">Birth Date</label>
                                <input type="date" class="form-control" id="editTeacherBirthDate" name="birth_date">
                            </div>
                        </div>

                        <div class="row g-2 mt-1">
                            <div class="col-md-4">
                                <label class="form-label" for="editTeacherDepartment">Department</label>
                                <input type="text" class="form-control" id="editTeacherDepartment" name="department" maxlength="100">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="editTeacherPosition">Position</label>
                                <input type="text" class="form-control" id="editTeacherPosition" name="position" maxlength="100">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="editTeacherEmail">Teacher Email</label>
                                <input type="email" class="form-control" id="editTeacherEmail" name="teacher_email" maxlength="100">
                            </div>
                        </div>

                        <div class="row g-2 mt-1">
                            <div class="col-md-4">
                                <label class="form-label" for="editTeacherBarangay">Barangay</label>
                                <input type="text" class="form-control" id="editTeacherBarangay" name="barangay" maxlength="100">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="editTeacherMunicipality">Municipality</label>
                                <input type="text" class="form-control" id="editTeacherMunicipality" name="municipality" maxlength="100">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="editTeacherProvince">Province</label>
                                <input type="text" class="form-control" id="editTeacherProvince" name="province" maxlength="100">
                            </div>
                        </div>

                        <div class="row g-2 mt-1">
                            <div class="col-md-4">
                                <label class="form-label" for="editTeacherPhone">Phone</label>
                                <input type="text" class="form-control" id="editTeacherPhone" name="phone" maxlength="20">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning text-dark">
                            <i class="ri-save-3-line me-1" aria-hidden="true"></i>
                            Save Profile
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Reset Password Modal -->
    <div class="modal fade" id="resetPasswordModal" tabindex="-1" aria-labelledby="resetPasswordModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-primary">
                <form method="post">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="resetPasswordModalLabel">
                            <i class="ri-shield-keyhole-line me-1" aria-hidden="true"></i>
                            Reset Password
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                        <input type="hidden" name="action" value="reset_password">
                        <input type="hidden" name="user_id" id="resetUserId" value="">

                        <div class="modal-hero mb-3">
                            <div class="d-flex align-items-start gap-3">
                                <div class="hero-icon flex-shrink-0">
                                    <i class="ri-lock-unlock-line text-primary" aria-hidden="true"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="fw-semibold" id="resetUserLabel">Account</div>
                                    <div class="text-muted small" id="resetUserEmailLabel"></div>
                                    <div class="text-muted small mt-1">
                                        Choose a reset mode. Generated passwords will be shown after you submit.
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="p-3 rounded-3 border bg-body">
                            <div class="mb-3">
                                <label class="form-label d-block mb-2">Reset Mode</label>
                                <div class="d-flex flex-wrap gap-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="reset_mode" id="resetModeGenerate" value="generate" checked>
                                        <label class="form-check-label" for="resetModeGenerate">Generate temporary password</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="reset_mode" id="resetModeManual" value="manual">
                                        <label class="form-check-label" for="resetModeManual">Set manually</label>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-0">
                                <label class="form-label" for="newPassword">New Password</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="newPassword" name="new_password" placeholder="At least 8 characters" disabled>
                                    <button class="btn btn-outline-secondary icon-btn action-view" type="button" id="toggleManualPw" data-bs-toggle="tooltip" title="View" aria-label="View">
                                        <i class="ri-eye-line" aria-hidden="true"></i>
                                    </button>
                                </div>
                                <small class="text-muted">Manual passwords must be at least 8 characters.</small>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">
                            <i class="ri-close-line me-1" aria-hidden="true"></i>
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="ri-refresh-line me-1" aria-hidden="true"></i>
                            Reset
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Usage Insights Modal -->
    <div class="modal fade" id="usageInsightsModal" tabindex="-1" aria-labelledby="usageInsightsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content border-primary">
                <div class="modal-header bg-primary text-white">
                    <div>
                        <h5 class="modal-title" id="usageInsightsModalLabel">
                            <i class="ri-line-chart-line me-1" aria-hidden="true"></i>
                            Usage Dashboard
                        </h5>
                        <div class="small text-white-50" id="usageModalMeta">Account usage details</div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex align-items-start justify-content-between gap-2 flex-wrap mb-3">
                        <div>
                            <div class="fw-semibold" id="usageModalUserLabel">User</div>
                            <div class="text-muted small">Limits, refresh events, and usage history</div>
                        </div>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="usagePrintReceiptBtn">
                            <i class="ri-printer-line me-1" aria-hidden="true"></i>
                            Print 58mm Receipt
                        </button>
                    </div>

                    <div class="usage-kpi-grid mb-3">
                        <div class="usage-kpi">
                            <div class="label">AI Limit</div>
                            <div class="value" id="usageAiLimit">0</div>
                        </div>
                        <div class="usage-kpi">
                            <div class="label">AI Used</div>
                            <div class="value" id="usageAiUsed">0</div>
                            <div class="usage-progress"><span class="ai-bar" id="usageAiBar"></span></div>
                        </div>
                        <div class="usage-kpi">
                            <div class="label">AI Remaining</div>
                            <div class="value" id="usageAiRemaining">0</div>
                        </div>
                        <div class="usage-kpi">
                            <div class="label">Build Limit</div>
                            <div class="value" id="usageBuildLimit">N/A</div>
                        </div>
                        <div class="usage-kpi">
                            <div class="label">Build Used</div>
                            <div class="value" id="usageBuildUsed">0</div>
                            <div class="usage-progress"><span class="build-bar" id="usageBuildBar"></span></div>
                        </div>
                        <div class="usage-kpi">
                            <div class="label">Build Remaining</div>
                            <div class="value" id="usageBuildRemaining">N/A</div>
                        </div>
                        <div class="usage-kpi">
                            <div class="label">Saved Builds</div>
                            <div class="value" id="usageBuildTotal">0</div>
                        </div>
                        <div class="usage-kpi">
                            <div class="label">Refresh Events</div>
                            <div class="value" id="usageRefreshCount">0</div>
                        </div>
                    </div>

                    <div class="usage-infographic mb-3">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                            <div class="fw-semibold">Usage Activity (Last 14 Days)</div>
                            <div class="usage-legend">
                                <span><span class="dot" style="background:#38bdf8;"></span>AI Consumed</span>
                                <span><span class="dot" style="background:#22c55e;"></span>Build Consumed</span>
                                <span><span class="dot" style="background:#f59e0b;"></span>Refresh</span>
                            </div>
                        </div>
                        <div id="usageTrendChart" class="usage-trend-chart"></div>
                        <div class="small text-muted mt-2" id="usageTrendSummary">No usage activity yet.</div>
                    </div>

                    <div class="usage-history-wrap">
                        <table class="table table-sm table-striped align-middle usage-history-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Event</th>
                                    <th>Resource</th>
                                    <th>Value</th>
                                    <th>Actor</th>
                                </tr>
                            </thead>
                            <tbody id="usageHistoryBody">
                                <tr id="usageHistoryEmpty">
                                    <td colspan="5" class="text-center text-muted py-3">No usage history available.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="receipt-note mt-2">
                        Receipt output uses 58mm thermal width and includes usage KPIs plus latest history rows.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- App js -->
    <script src="assets/js/app.min.js"></script>

    <script>
        const usageHistoryMap = <?php echo $usageHistoryJson; ?>;
        (function () {
            const modalEl = document.getElementById('resetPasswordModal');
            const editModalEl = document.getElementById('editUserModal');
            const usageModalEl = document.getElementById('usageInsightsModal');
            const resetUserId = document.getElementById('resetUserId');
            const resetUserLabel = document.getElementById('resetUserLabel');
            const resetUserEmailLabel = document.getElementById('resetUserEmailLabel');
            const modeGenerate = document.getElementById('resetModeGenerate');
            const modeManual = document.getElementById('resetModeManual');
            const newPassword = document.getElementById('newPassword');
            const toggleManualPw = document.getElementById('toggleManualPw');
            const editUserId = document.getElementById('editUserId');
            const editUsername = document.getElementById('editUsername');
            const editUserEmail = document.getElementById('editUserEmail');
            const editUserRole = document.getElementById('editUserRole');
            const editCampusId = document.getElementById('editCampusId');
            const editIsActive = document.getElementById('editIsActive');
            const editUserPassword = document.getElementById('editUserPassword');
            const linkProfileModalEl = document.getElementById('linkStudentProfileModal');
            const linkProfileUserId = document.getElementById('linkProfileUserId');
            const linkProfileAccountLabel = document.getElementById('linkProfileAccountLabel');
            const linkProfileAccountEmail = document.getElementById('linkProfileAccountEmail');
            const profileMode = document.getElementById('profileMode');
            const profileStudentNo = document.getElementById('profileStudentNo');
            const createProfileFields = document.getElementById('createProfileFields');
            const profileSurname = document.getElementById('profileSurname');
            const profileFirstname = document.getElementById('profileFirstname');
            const profileMiddlename = document.getElementById('profileMiddlename');
            const profileSex = document.getElementById('profileSex');
            const profileStatus = document.getElementById('profileStatus');
            const profileEmail = document.getElementById('profileEmail');
            const profileCourse = document.getElementById('profileCourse');
            const profileMajor = document.getElementById('profileMajor');
            const profileYear = document.getElementById('profileYear');
            const profileSection = document.getElementById('profileSection');
            const editStudentProfileModalEl = document.getElementById('editStudentProfileModal');
            const editStudentProfileId = document.getElementById('editStudentProfileId');
            const editStudentProfileUserId = document.getElementById('editStudentProfileUserId');
            const editStudentNo = document.getElementById('editStudentNo');
            const editStudentSurname = document.getElementById('editStudentSurname');
            const editStudentFirstname = document.getElementById('editStudentFirstname');
            const editStudentMiddlename = document.getElementById('editStudentMiddlename');
            const editStudentSex = document.getElementById('editStudentSex');
            const editStudentStatus = document.getElementById('editStudentStatus');
            const editStudentCourse = document.getElementById('editStudentCourse');
            const editStudentMajor = document.getElementById('editStudentMajor');
            const editStudentYear = document.getElementById('editStudentYear');
            const editStudentSection = document.getElementById('editStudentSection');
            const editStudentBirthDate = document.getElementById('editStudentBirthDate');
            const editStudentBarangay = document.getElementById('editStudentBarangay');
            const editStudentMunicipality = document.getElementById('editStudentMunicipality');
            const editStudentProvince = document.getElementById('editStudentProvince');
            const editStudentEmail = document.getElementById('editStudentEmail');
            const editStudentPhone = document.getElementById('editStudentPhone');

            const linkTeacherProfileModalEl = document.getElementById('linkTeacherProfileModal');
            const linkTeacherProfileUserId = document.getElementById('linkTeacherProfileUserId');
            const linkTeacherAccountLabel = document.getElementById('linkTeacherAccountLabel');
            const linkTeacherAccountEmail = document.getElementById('linkTeacherAccountEmail');
            const teacherProfileMode = document.getElementById('teacherProfileMode');
            const teacherProfileNo = document.getElementById('teacherProfileNo');
            const teacherProfileEmail = document.getElementById('teacherProfileEmail');
            const createTeacherProfileFields = document.getElementById('createTeacherProfileFields');
            const teacherProfileSurname = document.getElementById('teacherProfileSurname');
            const teacherProfileFirstname = document.getElementById('teacherProfileFirstname');
            const teacherProfileMiddlename = document.getElementById('teacherProfileMiddlename');
            const teacherProfileSex = document.getElementById('teacherProfileSex');
            const teacherProfileDepartment = document.getElementById('teacherProfileDepartment');
            const teacherProfilePosition = document.getElementById('teacherProfilePosition');
            const teacherProfileEmploymentStatus = document.getElementById('teacherProfileEmploymentStatus');
            const teacherProfileStatus = document.getElementById('teacherProfileStatus');
            const teacherProfileBirthDate = document.getElementById('teacherProfileBirthDate');
            const teacherProfileBarangay = document.getElementById('teacherProfileBarangay');
            const teacherProfileMunicipality = document.getElementById('teacherProfileMunicipality');
            const teacherProfileProvince = document.getElementById('teacherProfileProvince');
            const teacherProfilePhone = document.getElementById('teacherProfilePhone');

            const editTeacherProfileModalEl = document.getElementById('editTeacherProfileModal');
            const editTeacherProfileId = document.getElementById('editTeacherProfileId');
            const editTeacherProfileUserId = document.getElementById('editTeacherProfileUserId');
            const editTeacherNo = document.getElementById('editTeacherNo');
            const editTeacherSurname = document.getElementById('editTeacherSurname');
            const editTeacherFirstname = document.getElementById('editTeacherFirstname');
            const editTeacherMiddlename = document.getElementById('editTeacherMiddlename');
            const editTeacherSex = document.getElementById('editTeacherSex');
            const editTeacherDepartment = document.getElementById('editTeacherDepartment');
            const editTeacherPosition = document.getElementById('editTeacherPosition');
            const editTeacherEmploymentStatus = document.getElementById('editTeacherEmploymentStatus');
            const editTeacherStatus = document.getElementById('editTeacherStatus');
            const editTeacherBirthDate = document.getElementById('editTeacherBirthDate');
            const editTeacherBarangay = document.getElementById('editTeacherBarangay');
            const editTeacherMunicipality = document.getElementById('editTeacherMunicipality');
            const editTeacherProvince = document.getElementById('editTeacherProvince');
            const editTeacherEmail = document.getElementById('editTeacherEmail');
            const editTeacherPhone = document.getElementById('editTeacherPhone');

            const toggleTmpPw = document.getElementById('toggleTmpPw');
            const tmpPwMasked = document.getElementById('tmpPwMasked');
            const tmpPwPlain = document.getElementById('tmpPwPlain');
            const usersTable = document.getElementById('usersTable');
            const accountSearch = document.getElementById('accountSearch');
            const statusFilter = document.getElementById('statusFilter');
            const sectionFilter = document.getElementById('sectionFilter');
            const roleFilter = document.getElementById('roleFilter');
            const pageSizeSelect = document.getElementById('pageSizeSelect');
            const pagePrevBtn = document.getElementById('pagePrevBtn');
            const pageNextBtn = document.getElementById('pageNextBtn');
            const pageLabel = document.getElementById('pageLabel');
            const paginationInfo = document.getElementById('paginationInfo');
            const sortButtons = usersTable ? usersTable.querySelectorAll('.sort-btn[data-sort]') : [];
            const visibleCountEl = document.getElementById('visibleCount');
            const filterEmptyRow = document.getElementById('filterEmptyRow');
            const bulkSelectVisible = document.getElementById('bulkSelectVisible');
            const bulkEnrollForm = document.getElementById('bulkEnrollForm');
            const bulkSelectedCount = document.getElementById('bulkSelectedCount');
            const bulkEnrollSubmitBtn = document.getElementById('bulkEnrollSubmitBtn');
            const bulkSubjectSelect = document.getElementById('bulkSubjectSelect');
            const bulkAcademicYearSelect = document.getElementById('bulkAcademicYearSelect');
            const bulkSemesterSelect = document.getElementById('bulkSemesterSelect');
            const bulkSectionSelect = document.getElementById('bulkSectionSelect');

            const usageModalUserLabel = document.getElementById('usageModalUserLabel');
            const usageModalMeta = document.getElementById('usageModalMeta');
            const usageAiLimit = document.getElementById('usageAiLimit');
            const usageAiUsed = document.getElementById('usageAiUsed');
            const usageAiRemaining = document.getElementById('usageAiRemaining');
            const usageBuildLimit = document.getElementById('usageBuildLimit');
            const usageBuildUsed = document.getElementById('usageBuildUsed');
            const usageBuildRemaining = document.getElementById('usageBuildRemaining');
            const usageBuildTotal = document.getElementById('usageBuildTotal');
            const usageRefreshCount = document.getElementById('usageRefreshCount');
            const usageAiBar = document.getElementById('usageAiBar');
            const usageBuildBar = document.getElementById('usageBuildBar');
            const usageTrendChart = document.getElementById('usageTrendChart');
            const usageTrendSummary = document.getElementById('usageTrendSummary');
            const usageHistoryBody = document.getElementById('usageHistoryBody');
            const usageHistoryEmpty = document.getElementById('usageHistoryEmpty');
            const usagePrintReceiptBtn = document.getElementById('usagePrintReceiptBtn');
            let usageReceiptState = null;

            if (toggleTmpPw && tmpPwMasked && tmpPwPlain) {
                const tmpPwIcon = toggleTmpPw.querySelector('i');
                toggleTmpPw.addEventListener('click', () => {
                    const showing = !tmpPwPlain.classList.contains('d-none');
                    if (showing) {
                        tmpPwPlain.classList.add('d-none');
                        tmpPwMasked.classList.remove('d-none');
                        if (tmpPwIcon) tmpPwIcon.className = 'ri-eye-line';
                        toggleTmpPw.setAttribute('title', 'View');
                        toggleTmpPw.setAttribute('data-bs-original-title', 'View');
                    } else {
                        tmpPwMasked.classList.add('d-none');
                        tmpPwPlain.classList.remove('d-none');
                        if (tmpPwIcon) tmpPwIcon.className = 'ri-eye-off-line';
                        toggleTmpPw.setAttribute('title', 'Hide');
                        toggleTmpPw.setAttribute('data-bs-original-title', 'Hide');
                    }
                });
            }

            const setManualEnabled = (enabled) => {
                if (!newPassword) return;
                if (enabled) {
                    newPassword.removeAttribute('disabled');
                    newPassword.setAttribute('required', 'required');
                } else {
                    newPassword.value = '';
                    newPassword.setAttribute('disabled', 'disabled');
                    newPassword.removeAttribute('required');
                    newPassword.type = 'password';
                    if (toggleManualPw) {
                        const icon = toggleManualPw.querySelector('i');
                        if (icon) icon.className = 'ri-eye-line';
                        toggleManualPw.setAttribute('title', 'View');
                        toggleManualPw.setAttribute('data-bs-original-title', 'View');
                    }
                }
            };

            if (modeGenerate && modeManual) {
                modeGenerate.addEventListener('change', () => setManualEnabled(false));
                modeManual.addEventListener('change', () => setManualEnabled(true));
            }

            if (toggleManualPw && newPassword) {
                const manualIcon = toggleManualPw.querySelector('i');
                toggleManualPw.addEventListener('click', () => {
                    const isPw = newPassword.type === 'password';
                    newPassword.type = isPw ? 'text' : 'password';
                    if (manualIcon) manualIcon.className = isPw ? 'ri-eye-off-line' : 'ri-eye-line';
                    toggleManualPw.setAttribute('title', isPw ? 'Hide' : 'View');
                    toggleManualPw.setAttribute('data-bs-original-title', isPw ? 'Hide' : 'View');
                });
            }

            const normalize = (value) => String(value || '').trim().toLowerCase();
            const asNumber = (value, fallback = 0) => {
                const n = Number(value);
                return Number.isFinite(n) ? n : fallback;
            };
            const splitNameParts = (fullName) => {
                const value = String(fullName || '').trim().replace(/\s+/g, ' ');
                const out = { surname: '', firstname: '', middlename: '' };
                if (!value) return out;
                if (value.includes(',')) {
                    const parts = value.split(',');
                    out.surname = (parts.shift() || '').trim();
                    const right = parts.join(',').trim();
                    const tokens = right ? right.split(' ').filter(Boolean) : [];
                    if (tokens.length > 0) out.firstname = tokens[0];
                    if (tokens.length > 1) out.middlename = tokens.slice(1).join(' ');
                    return out;
                }
                const tokens = value.split(' ').filter(Boolean);
                if (tokens.length === 1) {
                    out.firstname = tokens[0];
                    return out;
                }
                if (tokens.length >= 2) {
                    out.firstname = tokens[0];
                    out.surname = tokens[tokens.length - 1];
                }
                if (tokens.length >= 3) {
                    out.middlename = tokens.slice(1, -1).join(' ');
                }
                return out;
            };
            const guessStudentNoFromAccount = (explicit, accountName, accountEmail) => {
                const isLikelyStudentNo = (value) => /^[0-9][0-9A-Za-z-]{3,19}$/.test(String(value || '').trim());
                if (isLikelyStudentNo(explicit)) return String(explicit).trim();
                const email = String(accountEmail || '').trim();
                const local = email.includes('@') ? email.slice(0, email.indexOf('@')).trim() : '';
                if (isLikelyStudentNo(local)) return local;
                const name = String(accountName || '').trim();
                if (isLikelyStudentNo(name)) return name;
                return '';
            };
            const guessTeacherNoFromAccount = (explicit, accountName, accountEmail) => {
                const isLikelyTeacherNo = (value) => /^[0-9A-Za-z][0-9A-Za-z-]{2,29}$/.test(String(value || '').trim());
                if (isLikelyTeacherNo(explicit)) return String(explicit).trim();
                const email = String(accountEmail || '').trim();
                const local = email.includes('@') ? email.slice(0, email.indexOf('@')).trim() : '';
                if (isLikelyTeacherNo(local)) return local;
                const name = String(accountName || '').trim();
                if (isLikelyTeacherNo(name)) return name;
                return '';
            };
            const toggleCreateProfileFields = () => {
                const mode = normalize(profileMode ? profileMode.value : 'create');
                const isLinkOnly = mode === 'link';
                if (createProfileFields) createProfileFields.classList.toggle('d-none', isLinkOnly);
                [profileSurname, profileFirstname, profileCourse, profileYear].forEach((input) => {
                    if (!input) return;
                    if (isLinkOnly) {
                        input.removeAttribute('required');
                    } else {
                        input.setAttribute('required', 'required');
                    }
                });
            };
            const toggleCreateTeacherProfileFields = () => {
                const mode = normalize(teacherProfileMode ? teacherProfileMode.value : 'create');
                const isLinkOnly = mode === 'link';
                if (createTeacherProfileFields) createTeacherProfileFields.classList.toggle('d-none', isLinkOnly);
                [teacherProfileSurname, teacherProfileFirstname].forEach((input) => {
                    if (!input) return;
                    if (isLinkOnly) {
                        input.removeAttribute('required');
                    } else {
                        input.setAttribute('required', 'required');
                    }
                });
            };

            const formatDateTime = (value) => {
                if (!value) return 'N/A';
                const d = new Date(String(value).replace(' ', 'T'));
                if (Number.isNaN(d.getTime())) return String(value);
                return d.toLocaleString();
            };

            const escapeHtml = (value) => String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');

            const allStudentCheckboxes = () => (
                usersTable ? Array.from(usersTable.querySelectorAll('.bulk-student-checkbox')) : []
            );
            const visibleStudentCheckboxes = () => allStudentCheckboxes().filter((checkbox) => {
                const row = checkbox.closest('tr.user-row');
                return row && !row.classList.contains('d-none');
            });
            const selectedStudentCheckboxes = () => allStudentCheckboxes().filter((checkbox) => checkbox.checked);
            const bulkFormFiltersReady = () => {
                const ay = normalize(bulkAcademicYearSelect ? bulkAcademicYearSelect.value : '');
                const sem = normalize(bulkSemesterSelect ? bulkSemesterSelect.value : '');
                const subject = normalize(bulkSubjectSelect ? bulkSubjectSelect.value : '');
                const section = normalize(bulkSectionSelect ? bulkSectionSelect.value : '');
                return ay !== '' && sem !== '' && subject !== '' && section !== '';
            };

            const updateBulkSelectionUi = () => {
                const selectedCount = selectedStudentCheckboxes().length;
                if (bulkSelectedCount) {
                    bulkSelectedCount.textContent = `${selectedCount} selected`;
                }
                if (bulkEnrollSubmitBtn) {
                    bulkEnrollSubmitBtn.disabled = selectedCount === 0 || !bulkFormFiltersReady();
                }
                if (!bulkSelectVisible) return;
                const visible = visibleStudentCheckboxes();
                if (visible.length === 0) {
                    bulkSelectVisible.checked = false;
                    bulkSelectVisible.indeterminate = false;
                    bulkSelectVisible.disabled = true;
                    return;
                }
                bulkSelectVisible.disabled = false;
                const visibleChecked = visible.filter((checkbox) => checkbox.checked).length;
                bulkSelectVisible.checked = visibleChecked === visible.length;
                bulkSelectVisible.indeterminate = visibleChecked > 0 && visibleChecked < visible.length;
            };

            const eventLabel = (eventType) => {
                const key = normalize(eventType);
                const map = {
                    ai_consume: 'AI Consumed',
                    ai_refund: 'AI Refunded',
                    ai_refresh: 'AI Refreshed',
                    ai_limit_set: 'AI Limit Set',
                    build_consume: 'Build Consumed',
                    build_refresh: 'Build Refreshed',
                    build_limit_set: 'Build Limit Set',
                    usage_refresh_all: 'All Usage Refreshed',
                };
                return map[key] || String(eventType || 'Activity');
            };

            const resourceLabel = (resourceType) => {
                const key = normalize(resourceType);
                if (key === 'ai_credit') return 'AI Credit';
                if (key === 'build') return 'Build';
                if (key === 'all') return 'All';
                return String(resourceType || 'N/A');
            };

            const isRefreshEvent = (eventType) => {
                const key = normalize(eventType);
                return key === 'ai_refresh' || key === 'build_refresh' || key === 'usage_refresh_all';
            };

            const getUserEvents = (userId) => {
                const id = String(asNumber(userId, 0));
                const list = usageHistoryMap && typeof usageHistoryMap === 'object' ? usageHistoryMap[id] : null;
                return Array.isArray(list) ? list : [];
            };

            const renderUsageHistory = (events) => {
                if (!usageHistoryBody || !usageHistoryEmpty) return;
                usageHistoryBody.querySelectorAll('tr.usage-row').forEach((row) => row.remove());
                const items = Array.isArray(events) ? events.slice(0, 30) : [];
                usageHistoryEmpty.classList.toggle('d-none', items.length > 0);
                if (items.length === 0) return;

                const frag = document.createDocumentFragment();
                items.forEach((evt) => {
                    const tr = document.createElement('tr');
                    tr.className = 'usage-row';

                    const createdAt = evt && evt.created_at ? formatDateTime(evt.created_at) : 'N/A';
                    const label = eventLabel(evt ? evt.event_type : '');
                    const resource = resourceLabel(evt ? evt.resource_type : '');
                    const amount = asNumber(evt ? evt.event_amount : 0, 0);
                    const actor = evt && evt.actor_name ? String(evt.actor_name) : 'System';

                    const tdDate = document.createElement('td');
                    tdDate.textContent = createdAt;
                    const tdEvent = document.createElement('td');
                    tdEvent.textContent = label;
                    const tdResource = document.createElement('td');
                    tdResource.textContent = resource;
                    const tdValue = document.createElement('td');
                    tdValue.textContent = String(amount);
                    const tdActor = document.createElement('td');
                    tdActor.textContent = actor;

                    tr.appendChild(tdDate);
                    tr.appendChild(tdEvent);
                    tr.appendChild(tdResource);
                    tr.appendChild(tdValue);
                    tr.appendChild(tdActor);
                    frag.appendChild(tr);
                });
                usageHistoryBody.appendChild(frag);
            };

            const renderUsageTrend = (events) => {
                if (!usageTrendChart || !usageTrendSummary) return;
                usageTrendChart.innerHTML = '';
                const today = new Date();
                today.setHours(0, 0, 0, 0);
                const dayMap = {};
                for (let i = 13; i >= 0; i -= 1) {
                    const d = new Date(today);
                    d.setDate(today.getDate() - i);
                    const key = d.toISOString().slice(0, 10);
                    dayMap[key] = { date: d, ai: 0, build: 0, refresh: 0 };
                }

                (Array.isArray(events) ? events : []).forEach((evt) => {
                    const rawDate = evt && evt.created_at ? String(evt.created_at).slice(0, 10) : '';
                    if (!dayMap[rawDate]) return;
                    const amount = Math.max(asNumber(evt ? evt.event_amount : 0, 0), 0);
                    const et = normalize(evt ? evt.event_type : '');
                    if (et === 'ai_consume') dayMap[rawDate].ai += amount;
                    if (et === 'build_consume') {
                        dayMap[rawDate].build += amount;
                    }
                    if (isRefreshEvent(et)) {
                        dayMap[rawDate].refresh += 1;
                    }
                });

                const days = Object.values(dayMap);
                const maxTotal = days.reduce((max, d) => Math.max(max, d.ai + d.build + d.refresh), 0);
                const safeMax = maxTotal > 0 ? maxTotal : 1;

                let sumAi = 0;
                let sumBuild = 0;
                let sumRefresh = 0;
                days.forEach((d) => {
                    sumAi += d.ai;
                    sumBuild += d.build;
                    sumRefresh += d.refresh;

                    const total = d.ai + d.build + d.refresh;
                    const col = document.createElement('div');
                    col.className = 'usage-day';
                    const stack = document.createElement('div');
                    stack.className = 'stack';

                    const aiPct = total > 0 ? (d.ai / safeMax) * 100 : 0;
                    const buildPct = total > 0 ? (d.build / safeMax) * 100 : 0;
                    const refreshPct = total > 0 ? (d.refresh / safeMax) * 100 : 0;

                    if (refreshPct > 0) {
                        const seg = document.createElement('span');
                        seg.className = 'seg-refresh';
                        seg.style.height = `${Math.max(refreshPct, 4)}%`;
                        stack.appendChild(seg);
                    }
                    if (buildPct > 0) {
                        const seg = document.createElement('span');
                        seg.className = 'seg-build';
                        seg.style.height = `${Math.max(buildPct, 4)}%`;
                        stack.appendChild(seg);
                    }
                    if (aiPct > 0) {
                        const seg = document.createElement('span');
                        seg.className = 'seg-ai';
                        seg.style.height = `${Math.max(aiPct, 4)}%`;
                        stack.appendChild(seg);
                    }

                    const label = document.createElement('div');
                    label.className = 'day-label';
                    label.textContent = d.date.toLocaleDateString(undefined, { month: 'numeric', day: 'numeric' });

                    col.appendChild(stack);
                    col.appendChild(label);
                    usageTrendChart.appendChild(col);
                });

                if (sumAi === 0 && sumBuild === 0 && sumRefresh === 0) {
                    usageTrendSummary.textContent = 'No usage activity recorded in the last 14 days.';
                } else {
                    usageTrendSummary.textContent = `14-day totals: AI ${sumAi}, Build ${sumBuild}, Refresh ${sumRefresh}.`;
                }
            };

            const setUsageProgress = (el, used, limit, unlimited = false) => {
                if (!el) return;
                if (unlimited || limit <= 0) {
                    el.style.width = '0%';
                    return;
                }
                const pct = Math.max(0, Math.min(100, (used / limit) * 100));
                el.style.width = `${pct}%`;
            };
            const formatCredits = (value) => {
                const n = Number(value);
                return Number.isFinite(n) ? n.toFixed(2) : '0.00';
            };

            const renderUsageModal = (button) => {
                if (!button) return;
                const userId = asNumber(button.getAttribute('data-user-id'), 0);
                const name = button.getAttribute('data-user-name') || 'Account';
                const email = button.getAttribute('data-user-email') || '';
                const role = button.getAttribute('data-user-role') || '';
                const aiLimitValue = asNumber(button.getAttribute('data-ai-limit'), 0);
                const aiUsedValue = asNumber(button.getAttribute('data-ai-used'), 0);
                const aiRemainingValue = asNumber(button.getAttribute('data-ai-remaining'), 0);
                const buildLimitValue = asNumber(button.getAttribute('data-build-limit'), -1);
                const buildUsedValue = asNumber(button.getAttribute('data-build-used'), 0);
                const buildRemainingValue = asNumber(button.getAttribute('data-build-remaining'), -1);
                const buildTotalValue = asNumber(button.getAttribute('data-build-total'), 0);
                const buildUnlimited = role === 'teacher' && buildLimitValue === 0;

                const events = getUserEvents(userId);
                const refreshCount = events.filter((evt) => isRefreshEvent(evt ? evt.event_type : '')).length;

                if (usageModalUserLabel) usageModalUserLabel.textContent = `${name}`;
                if (usageModalMeta) usageModalMeta.textContent = `${email} | ${String(role || 'N/A').replace('_', ' ')}`;
                if (usageAiLimit) usageAiLimit.textContent = formatCredits(aiLimitValue);
                if (usageAiUsed) usageAiUsed.textContent = formatCredits(aiUsedValue);
                if (usageAiRemaining) usageAiRemaining.textContent = formatCredits(aiRemainingValue);
                if (usageBuildLimit) usageBuildLimit.textContent = role === 'teacher'
                    ? (buildUnlimited ? 'Unlimited' : String(buildLimitValue))
                    : 'N/A';
                if (usageBuildUsed) usageBuildUsed.textContent = role === 'teacher' ? String(buildUsedValue) : 'N/A';
                if (usageBuildRemaining) usageBuildRemaining.textContent = role === 'teacher'
                    ? (buildUnlimited ? 'Unlimited' : String(Math.max(buildRemainingValue, 0)))
                    : 'N/A';
                if (usageBuildTotal) usageBuildTotal.textContent = role === 'teacher' ? String(buildTotalValue) : 'N/A';
                if (usageRefreshCount) usageRefreshCount.textContent = String(refreshCount);

                setUsageProgress(usageAiBar, aiUsedValue, aiLimitValue, false);
                setUsageProgress(usageBuildBar, buildUsedValue, buildLimitValue, buildUnlimited || role !== 'teacher');

                renderUsageTrend(events);
                renderUsageHistory(events);

                usageReceiptState = {
                    userId,
                    name,
                    email,
                    role,
                    aiLimitValue,
                    aiUsedValue,
                    aiRemainingValue,
                    buildLimitValue,
                    buildUsedValue,
                    buildRemainingValue,
                    buildTotalValue,
                    buildUnlimited,
                    refreshCount,
                    events: events.slice(0, 10),
                    generatedAt: new Date(),
                };
            };

            const buildReceiptHtml = (state) => {
                const nowLabel = state && state.generatedAt instanceof Date
                    ? state.generatedAt.toLocaleString()
                    : new Date().toLocaleString();
                const lines = [];
                lines.push(`${escapeHtml(state.name)} (${escapeHtml(state.role)})`);
                lines.push(escapeHtml(state.email));
                lines.push(`Generated: ${escapeHtml(nowLabel)}`);
                lines.push('');
                lines.push(`AI Limit   : ${escapeHtml(formatCredits(state.aiLimitValue))}`);
                lines.push(`AI Used    : ${escapeHtml(formatCredits(state.aiUsedValue))}`);
                lines.push(`AI Remain  : ${escapeHtml(formatCredits(state.aiRemainingValue))}`);
                lines.push('');
                if (normalize(state.role) === 'teacher') {
                    lines.push(`Build Limit: ${state.buildUnlimited ? 'Unlimited' : escapeHtml(state.buildLimitValue)}`);
                    lines.push(`Build Used : ${escapeHtml(state.buildUsedValue)}`);
                    lines.push(`Build Rem. : ${state.buildUnlimited ? 'Unlimited' : escapeHtml(Math.max(state.buildRemainingValue, 0))}`);
                    lines.push(`Saved      : ${escapeHtml(state.buildTotalValue)}`);
                    lines.push('');
                }
                lines.push(`Refreshes  : ${escapeHtml(state.refreshCount)}`);
                lines.push('');
                lines.push('Recent History');
                (Array.isArray(state.events) ? state.events : []).forEach((evt) => {
                    const dt = evt && evt.created_at ? String(evt.created_at).slice(0, 16) : '';
                    const label = eventLabel(evt ? evt.event_type : '');
                    const amount = asNumber(evt ? evt.event_amount : 0, 0);
                    lines.push(`${escapeHtml(dt)} | ${escapeHtml(label)} | ${escapeHtml(amount)}`);
                });
                lines.push('');
                lines.push('Ryhn Solutions');

                const bodyText = lines.join('\n');
                return `<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Usage Receipt</title>
<style>
body{margin:0;padding:0;font-family:Consolas,Monaco,'Courier New',monospace;background:#fff;color:#000;}
.wrap{width:58mm;max-width:58mm;padding:2mm 2.5mm;box-sizing:border-box;}
.title{text-align:center;font-weight:700;margin-bottom:1mm;}
.line{border-top:1px dashed #000;margin:1.5mm 0;}
pre{margin:0;white-space:pre-wrap;word-break:break-word;font-size:10px;line-height:1.25;}
</style>
</head>
<body>
<div class="wrap">
<div class="title">Usage Receipt</div>
<div class="line"></div>
<pre>${bodyText}</pre>
</div>
</body>
</html>`;
            };

            if (usagePrintReceiptBtn) {
                usagePrintReceiptBtn.addEventListener('click', () => {
                    if (!usageReceiptState) return;
                    const receiptWindow = window.open('', '_blank', 'width=420,height=760');
                    if (!receiptWindow) {
                        window.alert('Unable to open print window. Please allow pop-ups for this site.');
                        return;
                    }
                    receiptWindow.document.open();
                    receiptWindow.document.write(buildReceiptHtml(usageReceiptState));
                    receiptWindow.document.close();
                    receiptWindow.focus();
                    window.setTimeout(() => {
                        receiptWindow.print();
                    }, 220);
                });
            }

            let sortKey = 'registered';
            let sortDir = 'desc';
            let currentPage = 1;
            let pageSize = asNumber(pageSizeSelect ? pageSizeSelect.value : 10, 10);
            if (pageSize <= 0) pageSize = 10;

            const compareRows = (a, b) => {
                const textCompare = (av, bv) => av.localeCompare(bv, undefined, { sensitivity: 'base' });
                let result = 0;

                if (sortKey === 'name') result = textCompare(normalize(a.dataset.name), normalize(b.dataset.name));
                if (sortKey === 'email') result = textCompare(normalize(a.dataset.email), normalize(b.dataset.email));
                if (sortKey === 'program') result = textCompare(normalize(a.dataset.program), normalize(b.dataset.program));
                if (sortKey === 'year') result = textCompare(normalize(a.dataset.year), normalize(b.dataset.year));
                if (sortKey === 'section') result = textCompare(normalize(a.dataset.section), normalize(b.dataset.section));
                if (sortKey === 'role') result = textCompare(normalize(a.dataset.role), normalize(b.dataset.role));
                if (sortKey === 'status') result = textCompare(normalize(a.dataset.status), normalize(b.dataset.status));
                if (sortKey === 'build_limit') result = asNumber(a.dataset.buildLimit, -1) - asNumber(b.dataset.buildLimit, -1);
                if (sortKey === 'ai_remaining') result = asNumber(a.dataset.aiRemaining, 0) - asNumber(b.dataset.aiRemaining, 0);
                if (sortKey === 'registered') result = asNumber(a.dataset.created, 0) - asNumber(b.dataset.created, 0);

                if (result === 0) {
                    result = textCompare(normalize(a.dataset.name), normalize(b.dataset.name));
                }
                return sortDir === 'asc' ? result : -result;
            };

            const updateSortButtons = () => {
                sortButtons.forEach((btn) => {
                    const key = btn.getAttribute('data-sort');
                    const icon = btn.querySelector('i');
                    const isActive = key === sortKey;
                    btn.classList.toggle('active', isActive);
                    if (!icon) return;
                    if (!isActive) {
                        icon.className = 'ri-arrow-up-down-line';
                        return;
                    }
                    icon.className = sortDir === 'asc' ? 'ri-arrow-up-line' : 'ri-arrow-down-line';
                });
            };

            const renderUsersTable = (resetPage = false) => {
                if (!usersTable) return;
                const tbody = usersTable.querySelector('tbody');
                if (!tbody) return;

                if (pageSizeSelect) {
                    pageSize = asNumber(pageSizeSelect.value, 10);
                    if (pageSize <= 0) pageSize = 10;
                }
                if (resetPage) currentPage = 1;

                const allRows = Array.from(tbody.querySelectorAll('tr.user-row'));
                if (!allRows.length) return;

                allRows.sort(compareRows);
                allRows.forEach((row) => tbody.appendChild(row));
                if (filterEmptyRow) tbody.appendChild(filterEmptyRow);

                const query = normalize(accountSearch ? accountSearch.value : '');
                const status = normalize(statusFilter ? statusFilter.value : 'all');
                const role = normalize(roleFilter ? roleFilter.value : 'all');
                const section = normalize(sectionFilter ? sectionFilter.value : 'all');
                const filteredRows = allRows.filter((row) => {
                    const rowStatus = normalize(row.dataset.status);
                    const rowRole = normalize(row.dataset.role);
                    const rowSection = normalize(row.dataset.sectionKey || row.dataset.sectionOnly || row.dataset.section);
                    const haystack = normalize(row.dataset.search);
                    const matchesQuery = query === '' || haystack.includes(query);
                    const matchesStatus = status === 'all' || rowStatus === status;
                    const matchesRole = role === 'all' || rowRole === role;
                    const matchesSection = section === 'all' || rowSection === section;
                    return matchesQuery && matchesStatus && matchesRole && matchesSection;
                });

                const totalVisible = filteredRows.length;
                const totalPages = totalVisible > 0 ? Math.ceil(totalVisible / pageSize) : 1;
                if (currentPage > totalPages) currentPage = totalPages;
                if (currentPage < 1) currentPage = 1;

                const start = (currentPage - 1) * pageSize;
                const end = start + pageSize;
                const pageRows = filteredRows.slice(start, end);

                allRows.forEach((row) => row.classList.add('d-none'));
                pageRows.forEach((row) => row.classList.remove('d-none'));

                if (visibleCountEl) visibleCountEl.textContent = String(totalVisible);
                if (filterEmptyRow) filterEmptyRow.classList.toggle('d-none', totalVisible !== 0);

                if (pageLabel) {
                    pageLabel.textContent = totalVisible === 0 ? 'Page 0 of 0' : `Page ${currentPage} of ${totalPages}`;
                }
                if (paginationInfo) {
                    if (totalVisible === 0) {
                        paginationInfo.textContent = 'No matching accounts';
                    } else {
                        paginationInfo.textContent = `Showing ${start + 1}-${Math.min(end, totalVisible)} of ${totalVisible}`;
                    }
                }
                if (pagePrevBtn) pagePrevBtn.disabled = totalVisible === 0 || currentPage <= 1;
                if (pageNextBtn) pageNextBtn.disabled = totalVisible === 0 || currentPage >= totalPages;
                updateBulkSelectionUi();

                updateSortButtons();
            };

            if (accountSearch) accountSearch.addEventListener('input', () => renderUsersTable(true));
            if (statusFilter) statusFilter.addEventListener('change', () => renderUsersTable(true));
            if (sectionFilter) sectionFilter.addEventListener('change', () => renderUsersTable(true));
            if (roleFilter) roleFilter.addEventListener('change', () => renderUsersTable(true));
            if (pageSizeSelect) pageSizeSelect.addEventListener('change', () => renderUsersTable(true));
            if (pagePrevBtn) pagePrevBtn.addEventListener('click', () => { currentPage -= 1; renderUsersTable(false); });
            if (pageNextBtn) pageNextBtn.addEventListener('click', () => { currentPage += 1; renderUsersTable(false); });

            if (usersTable) {
                usersTable.addEventListener('change', (event) => {
                    const target = event.target;
                    if (!(target instanceof HTMLInputElement)) return;
                    if (!target.classList.contains('bulk-student-checkbox')) return;
                    updateBulkSelectionUi();
                });
            }

            if (bulkSelectVisible) {
                bulkSelectVisible.addEventListener('change', () => {
                    const checked = !!bulkSelectVisible.checked;
                    visibleStudentCheckboxes().forEach((checkbox) => {
                        checkbox.checked = checked;
                    });
                    updateBulkSelectionUi();
                });
            }

            if (bulkEnrollForm) {
                bulkEnrollForm.addEventListener('submit', (event) => {
                    const selected = selectedStudentCheckboxes();
                    if (selected.length === 0) {
                        event.preventDefault();
                        window.alert('Select at least one student before bulk enrollment.');
                        return;
                    }
                    bulkEnrollForm.querySelectorAll('input[name="student_ids[]"]').forEach((input) => input.remove());
                    const frag = document.createDocumentFragment();
                    selected.forEach((checkbox) => {
                        const hidden = document.createElement('input');
                        hidden.type = 'hidden';
                        hidden.name = 'student_ids[]';
                        hidden.value = String(checkbox.value || '');
                        frag.appendChild(hidden);
                    });
                    bulkEnrollForm.appendChild(frag);
                });
            }

            if (bulkSubjectSelect && bulkAcademicYearSelect && bulkSemesterSelect && bulkSectionSelect) {
                const rebuildBulkSubjectOptions = () => {
                    const ay = normalize(bulkAcademicYearSelect.value);
                    const sem = normalize(bulkSemesterSelect.value);
                    const selectedValue = String(bulkSubjectSelect.value || '');
                    const canShowSubjects = ay !== '' && sem !== '';

                    Array.from(bulkSubjectSelect.options).forEach((opt, idx) => {
                        if (idx === 0) {
                            opt.hidden = false;
                            opt.disabled = false;
                            return;
                        }
                        const subjectAy = normalize(opt.getAttribute('data-academic-year'));
                        const subjectSem = normalize(opt.getAttribute('data-semester'));
                        const ayMatch = subjectAy === '' || subjectAy === ay;
                        const semMatch = subjectSem === '' || subjectSem === sem;
                        let visible = ayMatch && semMatch;
                        if (!canShowSubjects) visible = false;

                        opt.hidden = !visible;
                        opt.disabled = !visible;
                    });

                    const selectedOpt = bulkSubjectSelect.options[bulkSubjectSelect.selectedIndex];
                    if (!selectedOpt || selectedOpt.disabled) bulkSubjectSelect.value = '';
                    if (selectedValue !== '' && String(bulkSubjectSelect.value || '') === '') bulkSubjectSelect.value = '';
                };

                const applyBulkHierarchy = () => {
                    const hasAy = normalize(bulkAcademicYearSelect.value) !== '';
                    const hasSem = normalize(bulkSemesterSelect.value) !== '';
                    let hasSubject = normalize(bulkSubjectSelect.value) !== '';

                    bulkSemesterSelect.disabled = !hasAy;
                    if (!hasAy) bulkSemesterSelect.value = '';

                    bulkSubjectSelect.disabled = !(hasAy && hasSem);
                    if (bulkSubjectSelect.disabled) bulkSubjectSelect.value = '';
                    hasSubject = normalize(bulkSubjectSelect.value) !== '';

                    bulkSectionSelect.disabled = !hasSubject;
                    if (bulkSectionSelect.disabled) bulkSectionSelect.value = '';

                    updateBulkSelectionUi();
                };

                bulkSubjectSelect.addEventListener('change', applyBulkHierarchy);
                bulkAcademicYearSelect.addEventListener('change', () => {
                    rebuildBulkSubjectOptions();
                    applyBulkHierarchy();
                });
                bulkSemesterSelect.addEventListener('change', () => {
                    rebuildBulkSubjectOptions();
                    applyBulkHierarchy();
                });
                bulkSectionSelect.addEventListener('change', updateBulkSelectionUi);

                rebuildBulkSubjectOptions();
                applyBulkHierarchy();
            }

            if (profileMode) {
                profileMode.addEventListener('change', () => toggleCreateProfileFields());
            }
            toggleCreateProfileFields();
            if (teacherProfileMode) {
                teacherProfileMode.addEventListener('change', () => toggleCreateTeacherProfileFields());
            }
            toggleCreateTeacherProfileFields();

            sortButtons.forEach((btn) => {
                btn.addEventListener('click', () => {
                    const key = btn.getAttribute('data-sort');
                    if (!key) return;
                    if (sortKey === key) {
                        sortDir = sortDir === 'asc' ? 'desc' : 'asc';
                    } else {
                        sortKey = key;
                        sortDir = key === 'registered' ? 'desc' : 'asc';
                    }
                    renderUsersTable(false);
                });
            });
            renderUsersTable(true);

            // Initialize Bootstrap tooltips on this page.
            if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
                document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => {
                    bootstrap.Tooltip.getOrCreateInstance(el);
                });
                document.querySelectorAll('[data-tooltip]').forEach((el) => {
                    bootstrap.Tooltip.getOrCreateInstance(el, {
                        title: el.getAttribute('data-tooltip') || '',
                    });
                });
            }

            if (modalEl) {
                modalEl.addEventListener('show.bs.modal', (event) => {
                    const button = event.relatedTarget;
                    if (!button) return;
                    const uid = button.getAttribute('data-user-id') || '';
                    const name = button.getAttribute('data-user-name') || 'Account';
                    const email = button.getAttribute('data-user-email') || '';

                    if (resetUserId) resetUserId.value = uid;
                    if (resetUserLabel) resetUserLabel.textContent = `Reset password for: ${name}`;
                    if (resetUserEmailLabel) resetUserEmailLabel.textContent = email;

                    if (modeGenerate) modeGenerate.checked = true;
                    if (modeManual) modeManual.checked = false;
                    setManualEnabled(false);
                });
            }

            if (linkProfileModalEl) {
                linkProfileModalEl.addEventListener('show.bs.modal', (event) => {
                    const button = event.relatedTarget;
                    if (!button) return;
                    const uid = button.getAttribute('data-user-id') || '';
                    const accountName = button.getAttribute('data-user-name') || 'Student account';
                    const accountEmail = button.getAttribute('data-user-email') || '';
                    const explicitStudentNo = button.getAttribute('data-student-no') || '';
                    const nameParts = splitNameParts(accountName);

                    if (linkProfileUserId) linkProfileUserId.value = uid;
                    if (linkProfileAccountLabel) linkProfileAccountLabel.textContent = accountName;
                    if (linkProfileAccountEmail) linkProfileAccountEmail.textContent = accountEmail;
                    if (profileMode) profileMode.value = 'create';
                    if (profileStudentNo) profileStudentNo.value = guessStudentNoFromAccount(explicitStudentNo, accountName, accountEmail);
                    if (profileSurname) profileSurname.value = nameParts.surname || '';
                    if (profileFirstname) profileFirstname.value = nameParts.firstname || '';
                    if (profileMiddlename) profileMiddlename.value = nameParts.middlename || '';
                    if (profileSex) profileSex.value = 'M';
                    if (profileStatus) profileStatus.value = 'New';
                    if (profileEmail) profileEmail.value = accountEmail;
                    if (profileCourse) profileCourse.value = '';
                    if (profileMajor) profileMajor.value = '';
                    if (profileYear) profileYear.value = '';
                    if (profileSection) profileSection.value = '';
                    toggleCreateProfileFields();
                });
            }

            if (editStudentProfileModalEl) {
                editStudentProfileModalEl.addEventListener('show.bs.modal', (event) => {
                    const button = event.relatedTarget;
                    if (!button) return;
                    if (editStudentProfileId) editStudentProfileId.value = button.getAttribute('data-student-id') || '';
                    if (editStudentProfileUserId) editStudentProfileUserId.value = button.getAttribute('data-user-id') || '';
                    if (editStudentNo) editStudentNo.value = button.getAttribute('data-student-no') || '';
                    if (editStudentSurname) editStudentSurname.value = button.getAttribute('data-surname') || '';
                    if (editStudentFirstname) editStudentFirstname.value = button.getAttribute('data-firstname') || '';
                    if (editStudentMiddlename) editStudentMiddlename.value = button.getAttribute('data-middlename') || '';
                    if (editStudentSex) editStudentSex.value = button.getAttribute('data-sex') || 'M';
                    if (editStudentStatus) editStudentStatus.value = button.getAttribute('data-status-profile') || 'New';
                    if (editStudentCourse) editStudentCourse.value = button.getAttribute('data-course') || '';
                    if (editStudentMajor) editStudentMajor.value = button.getAttribute('data-major') || '';
                    if (editStudentYear) editStudentYear.value = button.getAttribute('data-year') || '';
                    if (editStudentSection) editStudentSection.value = button.getAttribute('data-section') || '';
                    if (editStudentBirthDate) editStudentBirthDate.value = button.getAttribute('data-birth-date') || '';
                    if (editStudentBarangay) editStudentBarangay.value = button.getAttribute('data-barangay') || '';
                    if (editStudentMunicipality) editStudentMunicipality.value = button.getAttribute('data-municipality') || '';
                    if (editStudentProvince) editStudentProvince.value = button.getAttribute('data-province') || '';
                    if (editStudentEmail) editStudentEmail.value = button.getAttribute('data-student-email') || '';
                    if (editStudentPhone) editStudentPhone.value = button.getAttribute('data-student-phone') || '';
                });
            }

            if (linkTeacherProfileModalEl) {
                linkTeacherProfileModalEl.addEventListener('show.bs.modal', (event) => {
                    const button = event.relatedTarget;
                    if (!button) return;
                    const uid = button.getAttribute('data-user-id') || '';
                    const accountName = button.getAttribute('data-user-name') || 'Teacher account';
                    const accountEmail = button.getAttribute('data-user-email') || '';
                    const explicitTeacherNo = button.getAttribute('data-teacher-no') || '';
                    const nameParts = splitNameParts(accountName);

                    if (linkTeacherProfileUserId) linkTeacherProfileUserId.value = uid;
                    if (linkTeacherAccountLabel) linkTeacherAccountLabel.textContent = accountName;
                    if (linkTeacherAccountEmail) linkTeacherAccountEmail.textContent = accountEmail;
                    if (teacherProfileMode) teacherProfileMode.value = 'create';
                    if (teacherProfileNo) teacherProfileNo.value = guessTeacherNoFromAccount(explicitTeacherNo, accountName, accountEmail);
                    if (teacherProfileEmail) teacherProfileEmail.value = accountEmail;
                    if (teacherProfileSurname) teacherProfileSurname.value = nameParts.surname || '';
                    if (teacherProfileFirstname) teacherProfileFirstname.value = nameParts.firstname || '';
                    if (teacherProfileMiddlename) teacherProfileMiddlename.value = nameParts.middlename || '';
                    if (teacherProfileSex) teacherProfileSex.value = 'M';
                    if (teacherProfileDepartment) teacherProfileDepartment.value = '';
                    if (teacherProfilePosition) teacherProfilePosition.value = '';
                    if (teacherProfileEmploymentStatus) teacherProfileEmploymentStatus.value = 'Full-time';
                    if (teacherProfileStatus) teacherProfileStatus.value = 'Active';
                    if (teacherProfileBirthDate) teacherProfileBirthDate.value = '';
                    if (teacherProfileBarangay) teacherProfileBarangay.value = '';
                    if (teacherProfileMunicipality) teacherProfileMunicipality.value = '';
                    if (teacherProfileProvince) teacherProfileProvince.value = '';
                    if (teacherProfilePhone) teacherProfilePhone.value = '';
                    toggleCreateTeacherProfileFields();
                });
            }

            if (editTeacherProfileModalEl) {
                editTeacherProfileModalEl.addEventListener('show.bs.modal', (event) => {
                    const button = event.relatedTarget;
                    if (!button) return;
                    if (editTeacherProfileId) editTeacherProfileId.value = button.getAttribute('data-teacher-id') || '';
                    if (editTeacherProfileUserId) editTeacherProfileUserId.value = button.getAttribute('data-user-id') || '';
                    if (editTeacherNo) editTeacherNo.value = button.getAttribute('data-teacher-no') || '';
                    if (editTeacherSurname) editTeacherSurname.value = button.getAttribute('data-surname') || '';
                    if (editTeacherFirstname) editTeacherFirstname.value = button.getAttribute('data-firstname') || '';
                    if (editTeacherMiddlename) editTeacherMiddlename.value = button.getAttribute('data-middlename') || '';
                    if (editTeacherSex) editTeacherSex.value = button.getAttribute('data-sex') || 'M';
                    if (editTeacherDepartment) editTeacherDepartment.value = button.getAttribute('data-department') || '';
                    if (editTeacherPosition) editTeacherPosition.value = button.getAttribute('data-position') || '';
                    if (editTeacherEmploymentStatus) editTeacherEmploymentStatus.value = button.getAttribute('data-employment-status') || 'Full-time';
                    if (editTeacherStatus) editTeacherStatus.value = button.getAttribute('data-status-profile') || 'Active';
                    if (editTeacherBirthDate) editTeacherBirthDate.value = button.getAttribute('data-birth-date') || '';
                    if (editTeacherBarangay) editTeacherBarangay.value = button.getAttribute('data-barangay') || '';
                    if (editTeacherMunicipality) editTeacherMunicipality.value = button.getAttribute('data-municipality') || '';
                    if (editTeacherProvince) editTeacherProvince.value = button.getAttribute('data-province') || '';
                    if (editTeacherEmail) editTeacherEmail.value = button.getAttribute('data-teacher-email') || '';
                    if (editTeacherPhone) editTeacherPhone.value = button.getAttribute('data-teacher-phone') || '';
                });
            }

            if (usageModalEl) {
                usageModalEl.addEventListener('show.bs.modal', (event) => {
                    const button = event.relatedTarget;
                    renderUsageModal(button);
                });
            }

            if (editModalEl) {
                editModalEl.addEventListener('show.bs.modal', (event) => {
                    const button = event.relatedTarget;
                    if (!button) return;
                    const uid = button.getAttribute('data-user-id') || '';
                    const name = button.getAttribute('data-user-name') || '';
                    const email = button.getAttribute('data-user-email') || '';
                    const role = button.getAttribute('data-user-role') || 'student';
                    const active = button.getAttribute('data-user-active') || '0';
                    const campusId = button.getAttribute('data-user-campus-id') || '';

                    if (editUserId) editUserId.value = uid;
                    if (editUsername) editUsername.value = name;
                    if (editUserEmail) editUserEmail.value = email;
                    if (editUserRole) editUserRole.value = role;
                    if (editCampusId) {
                        if (campusId !== '') {
                            editCampusId.value = campusId;
                        } else if (editCampusId.options.length > 0) {
                            editCampusId.value = editCampusId.options[0].value;
                        }
                    }
                    if (editIsActive) editIsActive.value = active === '1' ? '1' : '0';
                    if (editUserPassword) editUserPassword.value = '';
                });
            }
        })();
    </script>

</body>

</html>

