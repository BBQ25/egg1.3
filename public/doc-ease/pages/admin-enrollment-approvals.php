<?php include '../layouts/session.php'; ?>
<?php require_role('admin'); ?>

<?php
require_once __DIR__ . '/../includes/audit.php';
ensure_audit_logs_table($conn);

$adminId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$adminIsSuperadmin = current_user_is_superadmin();
$adminCampusId = current_user_campus_id();
if (!$adminIsSuperadmin && $adminCampusId <= 0) {
    deny_access(403, 'Campus admin account has no campus assignment.');
}

$flash = isset($_SESSION['flash_message']) ? (string) $_SESSION['flash_message'] : '';
$flashType = isset($_SESSION['flash_type']) ? (string) $_SESSION['flash_type'] : 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

if (!function_exists('ea_h')) {
    function ea_h($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('ea_parse_request_tag')) {
    function ea_parse_request_tag($createdBy) {
        $createdBy = trim((string) $createdBy);
        $meta = [
            'teacher_id' => 0,
            'class_record_id' => 0,
            'previous_status' => 'Active',
        ];

        if (preg_match('/teacher_request:t(\d+):c(\d+)(?::s([a-z]+))?/i', $createdBy, $m)) {
            $meta['teacher_id'] = (int) ($m[1] ?? 0);
            $meta['class_record_id'] = (int) ($m[2] ?? 0);
            $prev = strtolower(trim((string) ($m[3] ?? '')));
            if ($prev === 'pending') $meta['previous_status'] = 'Pending';
            if ($prev === 'active') $meta['previous_status'] = 'Active';
        }

        return $meta;
    }
}

if (!function_exists('ea_user_campus_id')) {
    function ea_user_campus_id(mysqli $conn, $userId) {
        static $cache = [];
        $userId = (int) $userId;
        if ($userId <= 0) return 0;
        if (isset($cache[$userId])) return (int) $cache[$userId];

        $campusId = 0;
        $stmt = $conn->prepare("SELECT campus_id FROM users WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows === 1) {
                $campusId = (int) (($res->fetch_assoc()['campus_id'] ?? 0));
            }
            $stmt->close();
        }

        $cache[$userId] = $campusId;
        return (int) $campusId;
    }
}

if (!function_exists('ea_student_campus_id')) {
    function ea_student_campus_id(mysqli $conn, $studentNo) {
        static $cache = [];
        $studentNo = trim((string) $studentNo);
        if ($studentNo === '') return 0;
        if (array_key_exists($studentNo, $cache)) return (int) $cache[$studentNo];

        $campusId = 0;
        $stmt = $conn->prepare("SELECT campus_id FROM students WHERE StudentNo = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $studentNo);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows === 1) {
                $campusId = (int) (($res->fetch_assoc()['campus_id'] ?? 0));
            }
            $stmt->close();
        }

        $cache[$studentNo] = $campusId;
        return (int) $campusId;
    }
}

if (!function_exists('ea_class_record_campus_id')) {
    function ea_class_record_campus_id(mysqli $conn, $classRecordId) {
        static $cache = [];
        $classRecordId = (int) $classRecordId;
        if ($classRecordId <= 0) return 0;
        if (isset($cache[$classRecordId])) return (int) $cache[$classRecordId];

        $campusId = 0;
        $stmt = $conn->prepare(
            "SELECT COALESCE(
                        MAX(CASE
                            WHEN ta.status = 'active' AND u_ta.campus_id IS NOT NULL AND u_ta.campus_id > 0
                            THEN u_ta.campus_id
                            ELSE NULL
                        END),
                        NULLIF(u_cr.campus_id, 0),
                        NULLIF(u_cb.campus_id, 0),
                        0
                    ) AS campus_id
             FROM class_records cr
             LEFT JOIN teacher_assignments ta ON ta.class_record_id = cr.id
             LEFT JOIN users u_ta ON u_ta.id = ta.teacher_id
             LEFT JOIN users u_cr ON u_cr.id = cr.teacher_id
             LEFT JOIN users u_cb ON u_cb.id = cr.created_by
             WHERE cr.id = ?
             GROUP BY cr.id, u_cr.campus_id, u_cb.campus_id
             LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param('i', $classRecordId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows === 1) {
                $campusId = (int) (($res->fetch_assoc()['campus_id'] ?? 0));
            }
            $stmt->close();
        }

        $cache[$classRecordId] = $campusId;
        return (int) $campusId;
    }
}

if (!function_exists('ea_section_tokens')) {
    function ea_section_tokens($value) {
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

if (!function_exists('ea_section_matches_alias')) {
    function ea_section_matches_alias($classSection, $targetSection) {
        $classSection = strtolower(trim((string) $classSection));
        $targetSection = strtolower(trim((string) $targetSection));
        if ($classSection === '' || $targetSection === '') return false;
        if ($classSection === $targetSection) return true;

        $classTokens = ea_section_tokens($classSection);
        $targetTokens = ea_section_tokens($targetSection);
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

if (!function_exists('ea_find_class_record_id')) {
    function ea_find_class_record_id(mysqli $conn, $subjectId, $academicYear, $semester, $section, $preferredClassRecordId = 0) {
        $subjectId = (int) $subjectId;
        $academicYear = trim((string) $academicYear);
        $semester = trim((string) $semester);
        $section = trim((string) $section);
        $preferredClassRecordId = (int) $preferredClassRecordId;

        if ($subjectId <= 0 || $academicYear === '' || $semester === '' || $section === '') {
            return 0;
        }

        if ($preferredClassRecordId > 0) {
            $preferredStmt = $conn->prepare(
                "SELECT id
                 FROM class_records
                 WHERE id = ?
                   AND subject_id = ?
                   AND academic_year = ?
                   AND semester = ?
                   AND status = 'active'
                 LIMIT 1"
            );
            if ($preferredStmt) {
                $preferredStmt->bind_param('iiss', $preferredClassRecordId, $subjectId, $academicYear, $semester);
                $preferredStmt->execute();
                $preferredRes = $preferredStmt->get_result();
                if ($preferredRes && $preferredRes->num_rows === 1) {
                    $preferredStmt->close();
                    return $preferredClassRecordId;
                }
                $preferredStmt->close();
            }
        }

        $fallbackStmt = $conn->prepare(
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
        if ($fallbackStmt) {
            $fallbackStmt->bind_param('iss', $subjectId, $academicYear, $semester);
            $fallbackStmt->execute();
            $fallbackRes = $fallbackStmt->get_result();
            $best = null;
            while ($fallbackRes && ($row = $fallbackRes->fetch_assoc())) {
                $candidateId = (int) ($row['id'] ?? 0);
                $candidateSection = trim((string) ($row['section'] ?? ''));
                $hasTeacher = (int) ($row['has_teacher'] ?? 0) === 1;
                if ($candidateId <= 0 || $candidateSection === '') continue;

                $isExact = strcasecmp($candidateSection, $section) === 0;
                $isAlias = ea_section_matches_alias($candidateSection, $section);
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
                        'bucket' => $bucket,
                    ];
                }
            }
            $fallbackStmt->close();

            if (is_array($best)) {
                return (int) ($best['id'] ?? 0);
            }
        }

        return 0;
    }
}

if (!function_exists('ea_resolve_request_campus_id')) {
    function ea_resolve_request_campus_id(mysqli $conn, $studentNo, array $meta, $subjectId = 0, $academicYear = '', $semester = '', $section = '') {
        $studentNo = trim((string) $studentNo);
        $subjectId = (int) $subjectId;
        $academicYear = trim((string) $academicYear);
        $semester = trim((string) $semester);
        $section = trim((string) $section);

        $campusId = ea_student_campus_id($conn, $studentNo);
        if ($campusId > 0) return $campusId;

        $teacherId = (int) ($meta['teacher_id'] ?? 0);
        if ($teacherId > 0) {
            $campusId = ea_user_campus_id($conn, $teacherId);
            if ($campusId > 0) return $campusId;
        }

        $classRecordId = (int) ($meta['class_record_id'] ?? 0);
        if ($classRecordId > 0) {
            $campusId = ea_class_record_campus_id($conn, $classRecordId);
            if ($campusId > 0) return $campusId;
        }

        if ($subjectId > 0 && $academicYear !== '' && $semester !== '' && $section !== '') {
            $fallbackClassRecordId = ea_find_class_record_id($conn, $subjectId, $academicYear, $semester, $section, $classRecordId);
            if ($fallbackClassRecordId > 0) {
                $campusId = ea_class_record_campus_id($conn, $fallbackClassRecordId);
                if ($campusId > 0) return $campusId;
            }
        }

        return 0;
    }
}

if (!function_exists('ea_apply_enrollment_request')) {
    function ea_apply_enrollment_request(mysqli $conn, $enrollmentId, $action, $adminId, $isSuperadmin = false, $adminCampusId = 0) {
        $enrollmentId = (int) $enrollmentId;
        $action = trim((string) $action);
        $adminId = (int) $adminId;
        $isSuperadmin = (bool) $isSuperadmin;
        $adminCampusId = (int) $adminCampusId;

        if ($enrollmentId <= 0 || !in_array($action, ['approve', 'reject'], true)) {
            return [false, 'Invalid request action.'];
        }

        $conn->begin_transaction();
        try {
            $row = null;
            $reqStmt = $conn->prepare(
                "SELECT id, student_no, subject_id, academic_year, semester, section, status, created_by
                 FROM enrollments
                 WHERE id = ?
                 LIMIT 1
                 FOR UPDATE"
            );
            if (!$reqStmt) throw new RuntimeException('Unable to load enrollment request.');
            $reqStmt->bind_param('i', $enrollmentId);
            $reqStmt->execute();
            $reqRes = $reqStmt->get_result();
            if ($reqRes && $reqRes->num_rows === 1) {
                $row = $reqRes->fetch_assoc();
            }
            $reqStmt->close();

            if (!is_array($row)) {
                throw new RuntimeException('Enrollment request not found.');
            }

            $status = trim((string) ($row['status'] ?? ''));
            $studentNo = trim((string) ($row['student_no'] ?? ''));
            $subjectId = (int) ($row['subject_id'] ?? 0);
            $academicYear = trim((string) ($row['academic_year'] ?? ''));
            $semester = trim((string) ($row['semester'] ?? ''));
            $section = trim((string) ($row['section'] ?? ''));
            $createdBy = trim((string) ($row['created_by'] ?? ''));
            $meta = ea_parse_request_tag($createdBy);
            $requestCampusId = ea_resolve_request_campus_id($conn, $studentNo, $meta, $subjectId, $academicYear, $semester, $section);
            if (!$isSuperadmin) {
                if ($requestCampusId <= 0 || $requestCampusId !== $adminCampusId) {
                    throw new RuntimeException('Request is not available for your campus scope.');
                }
            }

            $statusNorm = strtolower($status);
            if ($statusNorm === 'pending') {
                if ($action !== 'reject') {
                    throw new RuntimeException('Self-enrollment requests can only be denied from this page.');
                }

                $denyTagSource = $createdBy !== '' ? $createdBy : 'self_enroll';
                $denyTag = substr($denyTagSource . '|denied:a' . $adminId, 0, 100);
                $deny = $conn->prepare("UPDATE enrollments SET status = 'Denied', created_by = ? WHERE id = ? AND status = 'Pending'");
                if (!$deny) throw new RuntimeException('Unable to deny request.');
                $deny->bind_param('si', $denyTag, $enrollmentId);
                $deny->execute();
                $affected = (int) $deny->affected_rows;
                $deny->close();

                if ($affected !== 1) {
                    throw new RuntimeException('Request was not updated.');
                }

                $conn->commit();
                return [true, 'Self-enrollment request denied.'];
            }

            if ($statusNorm !== 'teacherpending') {
                throw new RuntimeException('Request is no longer pending admin approval.');
            }

            if ($action === 'reject') {
                $rejectTag = substr($createdBy . '|rejected:a' . $adminId, 0, 100);
                $revertStatus = (string) ($meta['previous_status'] ?? 'Active');
                if (!in_array($revertStatus, ['Pending', 'Active'], true)) $revertStatus = 'Active';
                $rej = $conn->prepare("UPDATE enrollments SET status = ?, created_by = ? WHERE id = ?");
                if (!$rej) throw new RuntimeException('Unable to reject request.');
                $rej->bind_param('ssi', $revertStatus, $rejectTag, $enrollmentId);
                $rej->execute();
                $affected = (int) $rej->affected_rows;
                $rej->close();

                if ($affected !== 1) {
                    throw new RuntimeException('Request was not updated.');
                }

                $conn->commit();
                return [true, 'Enrollment request rejected.'];
            }

            $studentId = 0;
            $studentStmt = $conn->prepare("SELECT id FROM students WHERE StudentNo = ? LIMIT 1");
            if ($studentStmt) {
                $studentStmt->bind_param('s', $studentNo);
                $studentStmt->execute();
                $studentRes = $studentStmt->get_result();
                if ($studentRes && $studentRes->num_rows === 1) {
                    $studentId = (int) ($studentRes->fetch_assoc()['id'] ?? 0);
                }
                $studentStmt->close();
            }
            if ($studentId <= 0) {
                throw new RuntimeException('Linked student record not found.');
            }

            $classRecordId = ea_find_class_record_id(
                $conn,
                $subjectId,
                $academicYear,
                $semester,
                $section,
                (int) ($meta['class_record_id'] ?? 0)
            );
            if ($classRecordId <= 0) {
                throw new RuntimeException('No matching active class record was found for this request.');
            }
            if (!$isSuperadmin) {
                $classCampusId = ea_class_record_campus_id($conn, $classRecordId);
                if ($classCampusId <= 0 || $classCampusId !== $adminCampusId) {
                    throw new RuntimeException('Target class is outside your campus scope.');
                }
            }

            $teacherId = (int) ($meta['teacher_id'] ?? 0);
            if ($teacherId > 0) {
                $taId = 0;
                $taStatus = '';
                $taCheck = $conn->prepare(
                    "SELECT id, status
                     FROM teacher_assignments
                     WHERE teacher_id = ? AND class_record_id = ?
                     LIMIT 1"
                );
                if ($taCheck) {
                    $taCheck->bind_param('ii', $teacherId, $classRecordId);
                    $taCheck->execute();
                    $taRes = $taCheck->get_result();
                    if ($taRes && $taRes->num_rows === 1) {
                        $taRow = $taRes->fetch_assoc();
                        $taId = (int) ($taRow['id'] ?? 0);
                        $taStatus = trim((string) ($taRow['status'] ?? ''));
                    }
                    $taCheck->close();
                }

                if ($taId > 0 && strcasecmp($taStatus, 'active') !== 0) {
                    $taUpd = $conn->prepare("UPDATE teacher_assignments SET status = 'active', assigned_by = ?, assigned_at = CURRENT_TIMESTAMP WHERE id = ?");
                    if ($taUpd) {
                        $taUpd->bind_param('ii', $adminId, $taId);
                        $taUpd->execute();
                        $taUpd->close();
                    }
                } elseif ($taId <= 0) {
                    $taIns = $conn->prepare(
                        "INSERT INTO teacher_assignments
                            (teacher_id, teacher_role, class_record_id, assigned_by, status)
                         VALUES (?, 'primary', ?, ?, 'active')"
                    );
                    if ($taIns) {
                        $taIns->bind_param('iii', $teacherId, $classRecordId, $adminId);
                        $taIns->execute();
                        $taIns->close();
                    }
                }
            }

            $dropOld = $conn->prepare(
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
            if ($dropOld) {
                $dropOld->bind_param('iissi', $studentId, $subjectId, $academicYear, $semester, $classRecordId);
                $dropOld->execute();
                $dropOld->close();
            }

            $today = date('Y-m-d');
            $insClassEnroll = $conn->prepare(
                "INSERT INTO class_enrollments
                    (class_record_id, student_id, enrollment_date, status, created_by, class_id)
                 VALUES (?, ?, ?, 'enrolled', ?, ?)
                 ON DUPLICATE KEY UPDATE
                    status = 'enrolled',
                    class_id = VALUES(class_id),
                    updated_at = CURRENT_TIMESTAMP"
            );
            if (!$insClassEnroll) throw new RuntimeException('Unable to approve request into class roster.');
            $insClassEnroll->bind_param('iisii', $classRecordId, $studentId, $today, $adminId, $classRecordId);
            $insClassEnroll->execute();
            $insClassEnroll->close();

            $approvedTag = substr($createdBy . '|approved:a' . $adminId, 0, 100);
            $updEnroll = $conn->prepare("UPDATE enrollments SET status = 'Claimed', created_by = ? WHERE id = ?");
            if (!$updEnroll) throw new RuntimeException('Unable to finalize enrollment status.');
            $updEnroll->bind_param('si', $approvedTag, $enrollmentId);
            $updEnroll->execute();
            $updEnroll->close();

            $conn->commit();
            return [true, 'Enrollment request approved and added to class roster.'];
        } catch (Throwable $e) {
            $conn->rollback();
            return [false, $e->getMessage()];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    if (!csrf_validate($csrf)) {
        $_SESSION['flash_message'] = 'Security check failed (CSRF). Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: admin-enrollment-approvals.php');
        exit;
    }

    $action = isset($_POST['action']) ? trim((string) $_POST['action']) : '';
    $enrollmentId = isset($_POST['enrollment_id']) ? (int) $_POST['enrollment_id'] : 0;

    [$ok, $msg] = ea_apply_enrollment_request($conn, $enrollmentId, $action, $adminId, $adminIsSuperadmin, $adminCampusId);
    $_SESSION['flash_message'] = $msg;
    $_SESSION['flash_type'] = $ok ? 'success' : 'danger';

    if ($ok) {
        $auditAction = 'enrollment.request.rejected';
        if ($action === 'approve') {
            $auditAction = 'enrollment.request.approved';
        } elseif (stripos($msg, 'denied') !== false) {
            $auditAction = 'enrollment.request.denied';
        }
        audit_log(
            $conn,
            $auditAction,
            'enrollment',
            $enrollmentId,
            $msg,
            ['action' => $action]
        );
    }

    header('Location: admin-enrollment-approvals.php');
    exit;
}

$requests = [];
$rq = $conn->prepare(
    "SELECT e.id AS enrollment_id,
            e.student_no,
            e.subject_id,
            e.academic_year,
            e.semester,
            e.section,
            e.status AS enrollment_status,
            e.enrollment_date,
            e.created_by,
            st.id AS student_id,
            st.campus_id AS student_campus_id,
            st.Surname AS surname,
            st.FirstName AS firstname,
            st.MiddleName AS middlename,
            s.subject_code,
            s.subject_name
     FROM enrollments e
     LEFT JOIN students st ON st.StudentNo = e.student_no
     LEFT JOIN subjects s ON s.id = e.subject_id
     WHERE e.status IN ('TeacherPending', 'Pending')
     ORDER BY e.enrollment_date ASC, e.id ASC"
);
if ($rq) {
    $rq->execute();
    $res = $rq->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
        $meta = ea_parse_request_tag((string) ($row['created_by'] ?? ''));
        $row['_request_teacher_id'] = (int) ($meta['teacher_id'] ?? 0);
        $row['_request_class_record_id'] = (int) ($meta['class_record_id'] ?? 0);
        $row['_request_campus_id'] = ea_resolve_request_campus_id(
            $conn,
            (string) ($row['student_no'] ?? ''),
            $meta,
            (int) ($row['subject_id'] ?? 0),
            (string) ($row['academic_year'] ?? ''),
            (string) ($row['semester'] ?? ''),
            (string) ($row['section'] ?? '')
        );
        if (!$adminIsSuperadmin) {
            $requestCampusId = (int) ($row['_request_campus_id'] ?? 0);
            if ($requestCampusId <= 0 || $requestCampusId !== $adminCampusId) {
                continue;
            }
        }
        $requests[] = $row;
    }
    $rq->close();
}

$teacherMap = [];
$teacherIds = [];
$classRecordIds = [];
foreach ($requests as $r) {
    $tid = (int) ($r['_request_teacher_id'] ?? 0);
    $cid = (int) ($r['_request_class_record_id'] ?? 0);
    if ($tid > 0) $teacherIds[$tid] = true;
    if ($cid > 0) $classRecordIds[$cid] = true;
}

if (count($teacherIds) > 0) {
    $teacherIdList = implode(',', array_map('intval', array_keys($teacherIds)));
    $tr = $conn->query(
        "SELECT id, username, useremail, first_name, last_name
         FROM users
         WHERE id IN (" . $teacherIdList . ")"
    );
    while ($tr && ($row = $tr->fetch_assoc())) {
        $teacherMap[(int) ($row['id'] ?? 0)] = $row;
    }
}

$classMap = [];
if (count($classRecordIds) > 0) {
    $classIdList = implode(',', array_map('intval', array_keys($classRecordIds)));
    $cr = $conn->query(
        "SELECT cr.id AS class_record_id,
                cr.section,
                cr.academic_year,
                cr.semester,
                cr.status,
                s.subject_code,
                s.subject_name
         FROM class_records cr
         JOIN subjects s ON s.id = cr.subject_id
         WHERE cr.id IN (" . $classIdList . ")"
    );
    while ($cr && ($row = $cr->fetch_assoc())) {
        $classMap[(int) ($row['class_record_id'] ?? 0)] = $row;
    }
}

$teacherPendingCount = 0;
$selfPendingCount = 0;
foreach ($requests as $r) {
    $statusNorm = strtolower(trim((string) ($r['enrollment_status'] ?? '')));
    if ($statusNorm === 'teacherpending') {
        $teacherPendingCount++;
    } else {
        $selfPendingCount++;
    }
}

function ea_user_label(array $u) {
    $fn = trim((string) ($u['first_name'] ?? ''));
    $ln = trim((string) ($u['last_name'] ?? ''));
    $full = trim($fn . ' ' . $ln);
    if ($full !== '') return $full;
    return trim((string) ($u['username'] ?? 'Teacher'));
}
?>

<?php include '../layouts/main.php'; ?>

<head>
    <title>Enrollment Approvals | E-Record</title>
    <?php include '../layouts/title-meta.php'; ?>
    <?php include '../layouts/head-css.php'; ?>
    <link rel="stylesheet" href="assets/css/admin-ops-ui.css">
    <style>
        .approvals-hero {
            background: linear-gradient(140deg, #13253f 0%, #3a3f9d 52%, #6b8ff1 100%);
        }

        .approvals-hero::after {
            content: "";
            position: absolute;
            inset: 0;
            background:
                radial-gradient(50% 65% at 16% 20%, rgba(255, 255, 255, 0.13) 0%, rgba(255, 255, 255, 0) 65%),
                repeating-linear-gradient(
                    140deg,
                    rgba(255, 255, 255, 0.06) 0px,
                    rgba(255, 255, 255, 0.06) 1px,
                    rgba(255, 255, 255, 0) 10px,
                    rgba(255, 255, 255, 0) 21px
                );
            opacity: 0.42;
            pointer-events: none;
        }

        .approval-action-cell {
            white-space: nowrap;
        }
    </style>
</head>

<body>
<div class="wrapper">
    <?php include '../layouts/menu.php'; ?>

    <div class="content-page">
        <div class="content">
            <div class="container-fluid">

                <div class="row">
                    <div class="col-12">
                        <div class="page-title-box">
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="admin-dashboard.php">Admin</a></li>
                                    <li class="breadcrumb-item active">Enrollment Approvals</li>
                                </ol>
                            </div>
                            <h4 class="page-title">Enrollment Approvals</h4>
                        </div>
                    </div>
                </div>

                <div class="ops-hero approvals-hero ops-page-shell" data-ops-parallax>
                    <div class="ops-hero__bg" data-ops-parallax-layer aria-hidden="true"></div>
                    <div class="ops-hero__content">
                        <div class="d-flex align-items-end justify-content-between flex-wrap gap-2">
                            <div>
                                <div class="ops-hero__kicker">Administration</div>
                                <h1 class="ops-hero__title h3">Enrollment Approvals</h1>
                                <div class="ops-hero__subtitle">
                                    Review requests before they become official class enrollments. Approve only verified teacher-routed requests.
                                </div>
                            </div>
                            <div class="ops-hero__chips">
                                <div class="ops-chip">
                                    <span>Total Pending</span>
                                    <strong><?php echo (int) count($requests); ?></strong>
                                </div>
                                <div class="ops-chip">
                                    <span>Teacher Requests</span>
                                    <strong><?php echo (int) $teacherPendingCount; ?></strong>
                                </div>
                                <div class="ops-chip">
                                    <span>Self-Enroll</span>
                                    <strong><?php echo (int) $selfPendingCount; ?></strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($flash): ?>
                    <div class="alert alert-<?php echo ea_h($flashType); ?>"><?php echo ea_h($flash); ?></div>
                <?php endif; ?>

                <div class="card ops-card ops-page-shell">
                    <div class="card-body" id="pendingEnrollmentApprovalsCard">
                        <div class="ops-toolbar">
                            <div>
                                <h4 class="header-title mb-0">Pending Enrollment Requests</h4>
                                <div class="text-muted small">Review teacher-added and self-enrolled student requests.</div>
                            </div>
                            <div class="d-flex align-items-center flex-wrap gap-2">
                                <div class="input-group input-group-sm ops-search">
                                    <span class="input-group-text">
                                        <i class="ri-search-line" aria-hidden="true"></i>
                                    </span>
                                    <input
                                        id="enrollmentApprovalsSearch"
                                        class="form-control"
                                        type="search"
                                        placeholder="Search student, subject, section..."
                                        aria-label="Search enrollment approvals"
                                    >
                                    <button id="enrollmentApprovalsClear" class="btn btn-outline-secondary" type="button">Clear</button>
                                </div>
                                <div class="text-muted small">Pending: <strong><?php echo (int) count($requests); ?></strong></div>
                            </div>
                        </div>

                        <div class="table-responsive mt-3">
                            <table class="table table-sm table-striped table-hover align-middle mb-0 ops-table" id="enrollmentApprovalsTable">
                                <thead>
                                    <tr>
                                        <th>Requested</th>
                                        <th>Student</th>
                                        <th>Subject / Term</th>
                                        <th>Teacher Request</th>
                                        <th>Target Class</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($requests) === 0): ?>
                                        <tr id="enrollmentApprovalsEmptyRow">
                                            <td colspan="6" class="ops-empty">No pending enrollment requests.</td>
                                        </tr>
                                    <?php endif; ?>

                                    <?php foreach ($requests as $r): ?>
                                        <?php
                                        $enrollmentId = (int) ($r['enrollment_id'] ?? 0);
                                        $studentName = trim(
                                            (string) ($r['surname'] ?? '') . ', ' .
                                            (string) ($r['firstname'] ?? '') . ' ' .
                                            (string) ($r['middlename'] ?? '')
                                        );
                                        if ($studentName === '') $studentName = trim((string) ($r['student_no'] ?? 'Student'));

                                        $teacherId = (int) ($r['_request_teacher_id'] ?? 0);
                                        $classRecordId = (int) ($r['_request_class_record_id'] ?? 0);
                                        $teacherRow = isset($teacherMap[$teacherId]) ? $teacherMap[$teacherId] : null;
                                        $classRow = isset($classMap[$classRecordId]) ? $classMap[$classRecordId] : null;
                                        $requestStatus = strtolower(trim((string) ($r['enrollment_status'] ?? '')));
                                        $isTeacherRequest = $requestStatus === 'teacherpending';
                                        $rejectLabel = $isTeacherRequest ? 'Reject' : 'Deny';
                                        $rejectConfirm = $isTeacherRequest
                                            ? 'Reject this enrollment request?'
                                            : 'Deny this self-enrollment request?';
                                        ?>
                                        <tr
                                            class="enrollment-approval-row"
                                            data-enrollment-id="<?php echo (int) $enrollmentId; ?>"
                                            data-student-no="<?php echo ea_h(strtolower((string) ($r['student_no'] ?? ''))); ?>"
                                        >
                                            <td class="small text-muted"><?php echo ea_h((string) ($r['enrollment_date'] ?? '')); ?></td>
                                            <td>
                                                <div class="fw-semibold"><?php echo ea_h($studentName); ?></div>
                                                <div class="text-muted small"><?php echo ea_h((string) ($r['student_no'] ?? '')); ?></div>
                                            </td>
                                            <td>
                                                <div class="fw-semibold">
                                                    <?php echo ea_h((string) ($r['subject_name'] ?? 'Unknown Subject')); ?>
                                                    <span class="text-muted">(<?php echo ea_h((string) ($r['subject_code'] ?? 'N/A')); ?>)</span>
                                                </div>
                                                <div class="text-muted small">
                                                    <?php echo ea_h((string) ($r['academic_year'] ?? 'N/A')); ?> |
                                                    <?php echo ea_h((string) ($r['semester'] ?? 'N/A')); ?> |
                                                    Section <?php echo ea_h((string) ($r['section'] ?? 'N/A')); ?>
                                                </div>
                                                <div class="text-muted small">
                                                    Status:
                                                    <span class="badge <?php echo $isTeacherRequest ? 'bg-info-subtle text-info' : 'bg-warning-subtle text-warning'; ?>">
                                                        <?php echo ea_h((string) ($r['enrollment_status'] ?? 'Pending')); ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($isTeacherRequest && is_array($teacherRow)): ?>
                                                    <div class="fw-semibold"><?php echo ea_h(ea_user_label($teacherRow)); ?></div>
                                                    <div class="text-muted small"><?php echo ea_h((string) ($teacherRow['useremail'] ?? '')); ?></div>
                                                    <div class="text-muted small">Teacher ID: <?php echo (int) $teacherId; ?></div>
                                                <?php elseif ($isTeacherRequest): ?>
                                                    <div class="text-muted small">Teacher ID: <?php echo (int) $teacherId; ?></div>
                                                <?php else: ?>
                                                    <div class="fw-semibold">Student Self-Enrollment</div>
                                                    <div class="text-muted small"><?php echo ea_h((string) ($r['created_by'] ?? '')); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($isTeacherRequest && is_array($classRow)): ?>
                                                    <div class="fw-semibold">Class #<?php echo (int) $classRecordId; ?></div>
                                                    <div class="text-muted small">
                                                        <?php echo ea_h((string) ($classRow['subject_code'] ?? '')); ?> |
                                                        <?php echo ea_h((string) ($classRow['section'] ?? '')); ?>
                                                    </div>
                                                    <div class="text-muted small">
                                                        <?php echo ea_h((string) ($classRow['academic_year'] ?? '')); ?> |
                                                        <?php echo ea_h((string) ($classRow['semester'] ?? '')); ?> |
                                                        <?php echo ea_h((string) ($classRow['status'] ?? '')); ?>
                                                    </div>
                                                <?php elseif ($isTeacherRequest): ?>
                                                    <div class="text-muted small">Class #<?php echo (int) $classRecordId; ?> (not found)</div>
                                                <?php else: ?>
                                                    <div class="text-muted small">Not teacher-routed.</div>
                                                    <div class="text-muted small">Use Admin Users to approve account access.</div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end approval-action-cell">
                                                <span class="ops-actions justify-content-end">
                                                <?php if ($isTeacherRequest): ?>
                                                    <form method="post" class="d-inline">
                                                        <input type="hidden" name="csrf_token" value="<?php echo ea_h(csrf_token()); ?>">
                                                        <input type="hidden" name="action" value="approve">
                                                        <input type="hidden" name="enrollment_id" value="<?php echo (int) $enrollmentId; ?>">
                                                        <button class="btn btn-sm btn-success js-approve-request-btn" type="submit" onclick="return confirm('Approve this enrollment request?');">
                                                            <i class="ri-check-line me-1" aria-hidden="true"></i>Approve
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?php echo ea_h(csrf_token()); ?>">
                                                    <input type="hidden" name="action" value="reject">
                                                    <input type="hidden" name="enrollment_id" value="<?php echo (int) $enrollmentId; ?>">
                                                    <button class="btn btn-sm <?php echo $isTeacherRequest ? 'btn-outline-danger js-reject-request-btn' : 'btn-danger js-deny-request-btn'; ?>" type="submit" onclick="return confirm('<?php echo ea_h($rejectConfirm); ?>');">
                                                        <i class="ri-close-line me-1" aria-hidden="true"></i><?php echo ea_h($rejectLabel); ?>
                                                    </button>
                                                </form>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                    </div>
                </div>

            </div>
        </div>

        <?php include '../layouts/footer.php'; ?>
    </div>
</div>

<?php include '../layouts/right-sidebar.php'; ?>
<?php include '../layouts/footer-scripts.php'; ?>
<script src="assets/js/app.min.js"></script>
<script src="assets/js/admin-ops-ui.js"></script>
<script>
    (function () {
        var searchInput = document.getElementById('enrollmentApprovalsSearch');
        var clearBtn = document.getElementById('enrollmentApprovalsClear');
        var table = document.getElementById('enrollmentApprovalsTable');

        function filterRows() {
            if (!table || !searchInput) return;
            var query = (searchInput.value || '').toLowerCase().trim();
            var rows = table.querySelectorAll('tbody tr');

            for (var i = 0; i < rows.length; i++) {
                var row = rows[i];
                if (row.querySelector('td[colspan]')) continue;
                var text = (row.textContent || '').toLowerCase();
                row.style.display = query === '' || text.indexOf(query) !== -1 ? '' : 'none';
            }
        }

        if (searchInput) searchInput.addEventListener('input', filterRows);
        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                if (!searchInput) return;
                searchInput.value = '';
                searchInput.focus();
                filterRows();
            });
        }
    })();
</script>
</body>
</html>
