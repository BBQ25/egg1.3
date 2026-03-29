<?php include '../layouts/session.php'; ?>
<?php require_active_role('teacher'); ?>
<?php include '../layouts/main.php'; ?>

<?php
require_once __DIR__ . '/../includes/grading.php';
require_once __DIR__ . '/../includes/teacher_activity_events.php';
ensure_grading_tables($conn);
teacher_activity_ensure_tables($conn);

$flash = isset($_SESSION['flash_message']) ? (string) $_SESSION['flash_message'] : '';
$flashType = isset($_SESSION['flash_type']) ? (string) $_SESSION['flash_type'] : 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

$teacherId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$assessmentId = isset($_GET['assessment_id']) ? (int) $_GET['assessment_id'] : 0;
if ($assessmentId <= 0) {
    $_SESSION['flash_message'] = 'Invalid assessment.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: teacher-dashboard.php');
    exit;
}

if (!function_exists('tas_h')) {
    function tas_h($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('tas_fmt_datetime')) {
    function tas_fmt_datetime($value) {
        $value = trim((string) $value);
        if ($value === '') return '-';
        $ts = strtotime($value);
        return $ts ? date('Y-m-d H:i', $ts) : $value;
    }
}
if (!function_exists('tas_fmt_bytes')) {
    function tas_fmt_bytes($bytes) {
        $bytes = (int) $bytes;
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1024 * 1024) return number_format($bytes / 1024, 2) . ' KB';
        return number_format($bytes / (1024 * 1024), 2) . ' MB';
    }
}
if (!function_exists('tas_name')) {
    function tas_name(array $row) {
        return trim((string) ($row['surname'] ?? '') . ', ' . (string) ($row['firstname'] ?? '') . ' ' . (string) ($row['middlename'] ?? ''));
    }
}
if (!function_exists('tas_clean_filename')) {
    function tas_clean_filename($name) {
        $name = trim((string) $name);
        if ($name === '') return 'file';
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
        $name = trim((string) $name, '._-');
        return $name === '' ? 'file' : substr($name, 0, 180);
    }
}
if (!function_exists('tas_root')) {
    function tas_root() {
        return dirname(__DIR__);
    }
}
if (!function_exists('tas_unlink_rel')) {
    function tas_unlink_rel($path) {
        $path = trim(str_replace('\\', '/', (string) $path));
        if ($path === '' || strpos($path, 'uploads/assignments/') !== 0) return;
        $abs = tas_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (is_file($abs)) @unlink($abs);
    }
}
if (!function_exists('tas_status_badge')) {
    function tas_status_badge($status) {
        $status = strtolower(trim((string) $status));
        if ($status === 'graded') return ['class' => 'bg-success-subtle text-success', 'label' => 'Graded'];
        if ($status === 'submitted') return ['class' => 'bg-primary-subtle text-primary', 'label' => 'Submitted'];
        if ($status === 'draft') return ['class' => 'bg-warning-subtle text-warning', 'label' => 'Draft'];
        return ['class' => 'bg-secondary-subtle text-secondary', 'label' => 'No submission'];
    }
}
if (!function_exists('tas_get_or_create_submission_id')) {
    function tas_get_or_create_submission_id(mysqli $conn, $assessmentId, $studentId) {
        $assessmentId = (int) $assessmentId;
        $studentId = (int) $studentId;
        if ($assessmentId <= 0 || $studentId <= 0) return 0;
        $stmt = $conn->prepare(
            "INSERT INTO grading_assignment_submissions (assessment_id, student_id, status, last_modified_at)
             VALUES (?, ?, 'draft', NOW())
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), last_modified_at = last_modified_at"
        );
        if (!$stmt) return 0;
        $stmt->bind_param('ii', $assessmentId, $studentId);
        $ok = $stmt->execute();
        $id = $ok ? (int) $conn->insert_id : 0;
        $stmt->close();
        return $id;
    }
}
if (!function_exists('tas_store_feedback_file')) {
    function tas_store_feedback_file($assessmentId, $studentId, array $file, &$error = '') {
        $error = '';
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) {
            $error = 'Please choose a feedback file.';
            return null;
        }
        if ($err !== UPLOAD_ERR_OK) {
            $error = 'Upload failed (error code: ' . $err . ').';
            return null;
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            $error = 'Invalid upload payload.';
            return null;
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            $error = 'Uploaded file is empty.';
            return null;
        }
        if ($size > (100 * 1024 * 1024)) {
            $error = 'File exceeds 100MB.';
            return null;
        }
        $original = (string) ($file['name'] ?? 'feedback');
        $clean = tas_clean_filename($original);
        $ext = strtolower((string) pathinfo($clean, PATHINFO_EXTENSION));
        $blocked = ['php', 'php3', 'php4', 'php5', 'phtml', 'phar', 'exe', 'bat', 'cmd', 'com', 'js', 'jar', 'msi', 'vbs', 'sh', 'ps1'];
        if ($ext !== '' && in_array($ext, $blocked, true)) {
            $error = 'This file type is blocked.';
            return null;
        }
        try {
            $token = bin2hex(random_bytes(8));
        } catch (Throwable $e) {
            $token = substr(md5(uniqid((string) mt_rand(), true)), 0, 16);
        }
        $stored = date('YmdHis') . '-' . $token . '-' . $clean;
        $relDir = 'uploads/assignments/submissions/a_' . (int) $assessmentId . '/s_' . (int) $studentId . '/feedback';
        $relPath = $relDir . '/' . $stored;
        $absDir = tas_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relDir);
        if (!is_dir($absDir) && !@mkdir($absDir, 0775, true) && !is_dir($absDir)) {
            $error = 'Unable to create feedback directory.';
            return null;
        }
        $absPath = tas_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
        if (!@move_uploaded_file($tmp, $absPath)) {
            $error = 'Unable to store uploaded file.';
            return null;
        }
        $mime = (string) ($file['type'] ?? 'application/octet-stream');
        if (function_exists('finfo_open')) {
            $fi = @finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) {
                $det = @finfo_file($fi, $absPath);
                if (is_string($det) && $det !== '') $mime = $det;
                @finfo_close($fi);
            }
        }
        return [
            'original_name' => substr($original, 0, 255),
            'file_name' => substr($stored, 0, 255),
            'file_path' => substr($relPath, 0, 500),
            'file_size' => $size,
            'mime_type' => substr($mime, 0, 120),
        ];
    }
}

$ctx = null;
$ctxQ = $conn->prepare(
    "SELECT ga.id AS assessment_id, ga.name AS assessment_name, ga.max_score, ga.module_type, ga.module_settings_json,
            gc.id AS grading_component_id, gc.component_name, COALESCE(c.category_name, 'Uncategorized') AS category_name,
            sgc.term, sgc.section, sgc.academic_year, sgc.semester,
            cr.id AS class_record_id, s.subject_code, s.subject_name
     FROM grading_assessments ga
     JOIN grading_components gc ON gc.id = ga.grading_component_id
     JOIN section_grading_configs sgc ON sgc.id = gc.section_config_id
     JOIN class_records cr
        ON cr.subject_id = sgc.subject_id
       AND cr.section = sgc.section
       AND cr.academic_year = sgc.academic_year
       AND cr.semester = sgc.semester
       AND cr.status = 'active'
     JOIN teacher_assignments ta ON ta.class_record_id = cr.id AND ta.teacher_id = ? AND ta.status = 'active'
     JOIN subjects s ON s.id = sgc.subject_id
     LEFT JOIN grading_categories c ON c.id = gc.category_id
     WHERE ga.id = ?
     LIMIT 1"
);
if ($ctxQ) {
    $ctxQ->bind_param('ii', $teacherId, $assessmentId);
    $ctxQ->execute();
    $res = $ctxQ->get_result();
    if ($res && $res->num_rows === 1) $ctx = $res->fetch_assoc();
    $ctxQ->close();
}
if (!is_array($ctx)) {
    deny_access(403, 'Forbidden: not assigned to this assignment.');
}
$moduleType = grading_normalize_module_type((string) ($ctx['module_type'] ?? 'assessment'));
if ($moduleType !== 'assignment') {
    header('Location: teacher-assessment-scores.php?assessment_id=' . $assessmentId);
    exit;
}

$componentId = (int) ($ctx['grading_component_id'] ?? 0);
$classRecordId = (int) ($ctx['class_record_id'] ?? 0);
$term = strtolower(trim((string) ($ctx['term'] ?? 'midterm')));
$termLabel = $term === 'final' ? 'Final Term' : 'Midterm';
$settings = grading_assignment_settings((string) ($ctx['module_settings_json'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    if (!csrf_validate($csrf)) {
        $_SESSION['flash_message'] = 'Security check failed (CSRF).';
        $_SESSION['flash_type'] = 'danger';
        header('Location: teacher-assignment-submissions.php?assessment_id=' . $assessmentId);
        exit;
    }

    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';
    $studentId = isset($_POST['student_id']) ? (int) $_POST['student_id'] : 0;
    $redirectUrl = 'teacher-assignment-submissions.php?assessment_id=' . $assessmentId;
    if ($studentId > 0) $redirectUrl .= '&student_id=' . $studentId;

    $isEnrolled = false;
    if ($studentId > 0) {
        $chk = $conn->prepare("SELECT 1 FROM class_enrollments WHERE class_record_id = ? AND student_id = ? AND status = 'enrolled' LIMIT 1");
        if ($chk) {
            $chk->bind_param('ii', $classRecordId, $studentId);
            $chk->execute();
            $res = $chk->get_result();
            $isEnrolled = $res && $res->num_rows === 1;
            $chk->close();
        }
    }

    if (($action === 'grade_submission' || $action === 'upload_feedback_file' || $action === 'reopen_submission') && !$isEnrolled) {
        $_SESSION['flash_message'] = 'Student is not enrolled in this class.';
        $_SESSION['flash_type'] = 'warning';
        header('Location: ' . $redirectUrl);
        exit;
    }

    if ($action === 'grade_submission') {
        $submissionId = tas_get_or_create_submission_id($conn, $assessmentId, $studentId);
        if ($submissionId <= 0) {
            $_SESSION['flash_message'] = 'Unable to load submission record.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: ' . $redirectUrl);
            exit;
        }

        $scoreRaw = trim((string) ($_POST['graded_score'] ?? ''));
        $feedback = substr(trim((string) ($_POST['feedback_comment'] ?? '')), 0, 12000);
        $scoreVal = null;
        if ($scoreRaw !== '') {
            $scoreNorm = str_replace(',', '.', $scoreRaw);
            if (preg_match('/^\\d+(?:\\.\\d+)?$/', $scoreNorm)) {
                $scoreVal = (float) $scoreNorm;
                if ($scoreVal < 0) $scoreVal = 0.0;
                $maxScore = (float) ($ctx['max_score'] ?? 0);
                if ($scoreVal > $maxScore) $scoreVal = $maxScore;
                $scoreVal = round($scoreVal, 2);
            } else {
                $_SESSION['flash_message'] = 'Invalid score value.';
                $_SESSION['flash_type'] = 'warning';
                header('Location: ' . $redirectUrl);
                exit;
            }
        }

        if ($scoreVal === null) {
            $upd = $conn->prepare(
                "UPDATE grading_assignment_submissions
                 SET feedback_comment = NULLIF(?, ''),
                     graded_score = NULL,
                     graded_by = NULL,
                     graded_at = NULL,
                     status = CASE WHEN status = 'draft' THEN 'draft' ELSE 'submitted' END,
                     last_modified_at = NOW()
                 WHERE id = ?
                 LIMIT 1"
            );
            if ($upd) {
                $upd->bind_param('si', $feedback, $submissionId);
                $upd->execute();
                $upd->close();
            }
        } else {
            $upd = $conn->prepare(
                "UPDATE grading_assignment_submissions
                 SET feedback_comment = NULLIF(?, ''),
                     graded_score = ?,
                     graded_by = ?,
                     graded_at = NOW(),
                     status = 'graded',
                     last_modified_at = NOW()
                 WHERE id = ?
                 LIMIT 1"
            );
            if ($upd) {
                $upd->bind_param('sdii', $feedback, $scoreVal, $teacherId, $submissionId);
                $upd->execute();
                $upd->close();
            }
        }

        grading_upsert_assessment_score($conn, $assessmentId, $studentId, $scoreVal);
        $_SESSION['flash_message'] = 'Submission grade updated.';
        $_SESSION['flash_type'] = 'success';
        header('Location: ' . $redirectUrl);
        exit;
    }

    if ($action === 'reopen_submission') {
        $attemptsReopened = strtolower(trim((string) ($settings['attempts_reopened'] ?? 'never')));
        if ($attemptsReopened !== 'manual') {
            $_SESSION['flash_message'] = 'Manual reopening is not enabled for this assignment.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: ' . $redirectUrl);
            exit;
        }

        $submissionId = tas_get_or_create_submission_id($conn, $assessmentId, $studentId);
        if ($submissionId <= 0) {
            $_SESSION['flash_message'] = 'Unable to load submission record.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: ' . $redirectUrl);
            exit;
        }

        $upd = $conn->prepare(
            "UPDATE grading_assignment_submissions
             SET status = 'draft',
                 graded_score = NULL,
                 graded_by = NULL,
                 graded_at = NULL,
                 last_modified_at = NOW()
             WHERE id = ?
             LIMIT 1"
        );
        if ($upd) {
            $upd->bind_param('i', $submissionId);
            $upd->execute();
            $upd->close();
        }
        grading_upsert_assessment_score($conn, $assessmentId, $studentId, null);
        $_SESSION['flash_message'] = 'Submission reopened for student editing.';
        $_SESSION['flash_type'] = 'success';
        header('Location: ' . $redirectUrl);
        exit;
    }

    if ($action === 'upload_feedback_file') {
        $submissionId = tas_get_or_create_submission_id($conn, $assessmentId, $studentId);
        if ($submissionId <= 0) {
            $_SESSION['flash_message'] = 'Unable to load submission record.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: ' . $redirectUrl);
            exit;
        }
        $upload = isset($_FILES['feedback_file']) && is_array($_FILES['feedback_file']) ? $_FILES['feedback_file'] : null;
        $error = '';
        $stored = $upload ? tas_store_feedback_file($assessmentId, $studentId, $upload, $error) : null;
        if (!is_array($stored)) {
            $_SESSION['flash_message'] = $error !== '' ? $error : 'Unable to upload feedback file.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: ' . $redirectUrl);
            exit;
        }
        $ins = $conn->prepare(
            "INSERT INTO grading_assignment_submission_files
                (submission_id, original_name, file_name, file_path, file_size, mime_type, uploaded_by_role, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, 'teacher', ?)"
        );
        if ($ins) {
            $ins->bind_param('isssisi', $submissionId, $stored['original_name'], $stored['file_name'], $stored['file_path'], $stored['file_size'], $stored['mime_type'], $teacherId);
            $ok = $ins->execute();
            $newId = $ok ? (int) $conn->insert_id : 0;
            $ins->close();
            $_SESSION['flash_message'] = $ok ? 'Feedback file uploaded.' : 'Unable to save feedback file metadata.';
            $_SESSION['flash_type'] = $ok ? 'success' : 'danger';

            if ($ok) {
                $evtDate = date('Y-m-d');
                $evtTitle = 'Assignment feedback uploaded: ' . (string) ($stored['original_name'] ?? 'file');
                if (strlen($evtTitle) > 255) $evtTitle = substr($evtTitle, 0, 255);
                $evtId = teacher_activity_create_event(
                    $conn,
                    $teacherId,
                    $classRecordId,
                    'assignment_feedback_uploaded',
                    $evtDate,
                    $evtTitle,
                    [
                        'assessment_id' => $assessmentId,
                        'submission_id' => $submissionId,
                        'student_id' => $studentId,
                        'feedback_file_id' => $newId,
                        'original_name' => (string) ($stored['original_name'] ?? ''),
                        'file_path' => (string) ($stored['file_path'] ?? ''),
                    ]
                );
                if ($evtId > 0 && function_exists('teacher_activity_queue_add')) {
                    teacher_activity_queue_add($evtId, $evtTitle, $evtDate);
                }
            }
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    if ($action === 'delete_feedback_file') {
        $fileId = isset($_POST['file_id']) ? (int) $_POST['file_id'] : 0;
        $studentId = isset($_POST['student_id']) ? (int) $_POST['student_id'] : 0;
        $redirectUrl = 'teacher-assignment-submissions.php?assessment_id=' . $assessmentId;
        if ($studentId > 0) $redirectUrl .= '&student_id=' . $studentId;

        $row = null;
        $find = $conn->prepare(
            "SELECT sf.id, sf.file_path
             FROM grading_assignment_submission_files sf
             JOIN grading_assignment_submissions ss ON ss.id = sf.submission_id
             WHERE sf.id = ?
               AND sf.uploaded_by_role = 'teacher'
               AND ss.assessment_id = ?
             LIMIT 1"
        );
        if ($find) {
            $find->bind_param('ii', $fileId, $assessmentId);
            $find->execute();
            $res = $find->get_result();
            if ($res && $res->num_rows === 1) $row = $res->fetch_assoc();
            $find->close();
        }
        if (!is_array($row)) {
            $_SESSION['flash_message'] = 'Feedback file not found.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: ' . $redirectUrl);
            exit;
        }
        $del = $conn->prepare("DELETE FROM grading_assignment_submission_files WHERE id = ? LIMIT 1");
        if ($del) {
            $del->bind_param('i', $fileId);
            $ok = $del->execute();
            $del->close();
            if ($ok) {
                tas_unlink_rel((string) ($row['file_path'] ?? ''));
                $_SESSION['flash_message'] = 'Feedback file deleted.';
                $_SESSION['flash_type'] = 'success';
            } else {
                $_SESSION['flash_message'] = 'Unable to delete feedback file.';
                $_SESSION['flash_type'] = 'danger';
            }
        }
        header('Location: ' . $redirectUrl);
        exit;
    }
}

$students = [];
$list = $conn->prepare(
    "SELECT st.id AS student_id,
            st.StudentNo AS student_no,
            st.surname, st.firstname, st.middlename,
            ss.id AS submission_id,
            ss.status,
            ss.submitted_at,
            ss.last_modified_at,
            ss.is_late,
            ss.graded_score,
            ss.feedback_comment,
            ss.graded_at,
            gas.score AS final_score,
            COALESCE(SUM(CASE WHEN sf.uploaded_by_role = 'student' THEN 1 ELSE 0 END), 0) AS student_file_count,
            COALESCE(SUM(CASE WHEN sf.uploaded_by_role = 'teacher' THEN 1 ELSE 0 END), 0) AS feedback_file_count
     FROM class_enrollments ce
     JOIN students st ON st.id = ce.student_id
     LEFT JOIN grading_assignment_submissions ss ON ss.assessment_id = ? AND ss.student_id = st.id
     LEFT JOIN grading_assessment_scores gas ON gas.assessment_id = ? AND gas.student_id = st.id
     LEFT JOIN grading_assignment_submission_files sf ON sf.submission_id = ss.id
     WHERE ce.class_record_id = ?
       AND ce.status = 'enrolled'
     GROUP BY st.id, st.StudentNo, st.surname, st.firstname, st.middlename, ss.id, ss.status, ss.submitted_at, ss.last_modified_at, ss.is_late, ss.graded_score, ss.feedback_comment, ss.graded_at, gas.score
     ORDER BY st.surname ASC, st.firstname ASC, st.middlename ASC"
);
if ($list) {
    $list->bind_param('iii', $assessmentId, $assessmentId, $classRecordId);
    $list->execute();
    $res = $list->get_result();
    while ($res && ($row = $res->fetch_assoc())) $students[] = $row;
    $list->close();
}

$selectedStudentId = isset($_GET['student_id']) ? (int) $_GET['student_id'] : 0;
if ($selectedStudentId <= 0 && count($students) > 0) $selectedStudentId = (int) ($students[0]['student_id'] ?? 0);
$selectedStudent = null;
foreach ($students as $row) {
    if ((int) ($row['student_id'] ?? 0) === $selectedStudentId) {
        $selectedStudent = $row;
        break;
    }
}

$selectedSubmission = null;
$studentFiles = [];
$feedbackFiles = [];
if ($selectedStudentId > 0) {
    $sq = $conn->prepare("SELECT * FROM grading_assignment_submissions WHERE assessment_id = ? AND student_id = ? LIMIT 1");
    if ($sq) {
        $sq->bind_param('ii', $assessmentId, $selectedStudentId);
        $sq->execute();
        $res = $sq->get_result();
        if ($res && $res->num_rows === 1) $selectedSubmission = $res->fetch_assoc();
        $sq->close();
    }
    $submissionId = (int) ($selectedSubmission['id'] ?? 0);
    if ($submissionId > 0) {
        $fq = $conn->prepare("SELECT id, original_name, file_path, file_size, uploaded_by_role, created_at FROM grading_assignment_submission_files WHERE submission_id = ? ORDER BY id ASC");
        if ($fq) {
            $fq->bind_param('i', $submissionId);
            $fq->execute();
            $res = $fq->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                if (strtolower((string) ($row['uploaded_by_role'] ?? 'student')) === 'teacher') $feedbackFiles[] = $row;
                else $studentFiles[] = $row;
            }
            $fq->close();
        }
    }
}
?>

<head>
    <title>Assignment Submissions | E-Record</title>
    <?php include '../layouts/title-meta.php'; ?>
    <?php include '../layouts/head-css.php'; ?>
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
                                        <li class="breadcrumb-item"><a href="teacher-dashboard.php">Teacher</a></li>
                                        <li class="breadcrumb-item"><a href="teacher-component-assessments.php?grading_component_id=<?php echo (int) $componentId; ?>">Assessments</a></li>
                                        <li class="breadcrumb-item active">Assignment Submissions</li>
                                    </ol>
                                </div>
                                <h4 class="page-title">Assignment Submissions</h4>
                            </div>
                        </div>
                    </div>

                    <?php if ($flash): ?>
                        <div class="alert alert-<?php echo tas_h($flashType); ?>"><?php echo tas_h($flash); ?></div>
                    <?php endif; ?>

                    <div class="row g-3">
                        <div class="col-xl-7">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                                        <div>
                                            <h5 class="mb-1"><?php echo tas_h((string) ($ctx['assessment_name'] ?? 'Assignment')); ?></h5>
                                            <div class="text-muted small">
                                                <?php echo tas_h((string) ($ctx['subject_name'] ?? '')); ?> (<?php echo tas_h((string) ($ctx['subject_code'] ?? '')); ?>)
                                                | <?php echo tas_h((string) ($ctx['section'] ?? '')); ?>
                                                | <?php echo tas_h((string) ($ctx['academic_year'] ?? '')); ?>, <?php echo tas_h((string) ($ctx['semester'] ?? '')); ?>
                                                | <?php echo tas_h($termLabel); ?>
                                            </div>
                                        </div>
                                        <div class="text-end">
                                            <a class="btn btn-sm btn-outline-primary" href="teacher-assignment-builder.php?assessment_id=<?php echo (int) $assessmentId; ?>">Settings</a>
                                            <a class="btn btn-sm btn-outline-secondary" href="teacher-component-assessments.php?grading_component_id=<?php echo (int) $componentId; ?>">Back</a>
                                        </div>
                                    </div>

                                    <div class="table-responsive">
                                        <table class="table table-sm table-hover align-middle mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Student</th>
                                                    <th>Status</th>
                                                    <th>Files</th>
                                                    <th>Submitted</th>
                                                    <th>Final grade</th>
                                                    <th class="text-end">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (count($students) === 0): ?>
                                                    <tr>
                                                        <td colspan="6" class="text-center text-muted py-4">No enrolled students found.</td>
                                                    </tr>
                                                <?php endif; ?>
                                                <?php foreach ($students as $row): ?>
                                                    <?php
                                                    $sid = (int) ($row['student_id'] ?? 0);
                                                    $status = (string) ($row['status'] ?? '');
                                                    $badge = tas_status_badge($status);
                                                    $selectedClass = $sid === $selectedStudentId ? 'table-active' : '';
                                                    $score = $row['final_score'];
                                                    ?>
                                                    <tr class="<?php echo tas_h($selectedClass); ?>">
                                                        <td>
                                                            <div class="fw-semibold"><?php echo tas_h(tas_name($row)); ?></div>
                                                            <div class="text-muted small"><?php echo tas_h((string) ($row['student_no'] ?? '')); ?></div>
                                                        </td>
                                                        <td>
                                                            <span class="badge <?php echo tas_h((string) ($badge['class'] ?? 'bg-secondary')); ?>">
                                                                <?php echo tas_h((string) ($badge['label'] ?? 'No submission')); ?>
                                                            </span>
                                                            <?php if ((int) ($row['is_late'] ?? 0) === 1): ?>
                                                                <div><span class="badge bg-danger-subtle text-danger mt-1">Late</span></div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php echo (int) ($row['student_file_count'] ?? 0); ?> submission
                                                            <br>
                                                            <span class="text-muted small"><?php echo (int) ($row['feedback_file_count'] ?? 0); ?> feedback</span>
                                                        </td>
                                                        <td class="small"><?php echo tas_h(tas_fmt_datetime((string) ($row['submitted_at'] ?? ''))); ?></td>
                                                        <td>
                                                            <?php
                                                            echo ($score !== null && is_numeric($score))
                                                                ? tas_h(number_format((float) $score, 2, '.', ''))
                                                                : '-';
                                                            ?>
                                                        </td>
                                                        <td class="text-end">
                                                            <a class="btn btn-sm btn-outline-primary" href="teacher-assignment-submissions.php?assessment_id=<?php echo (int) $assessmentId; ?>&student_id=<?php echo $sid; ?>">
                                                                Grade
                                                            </a>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-5">
                            <div class="card">
                                <div class="card-body">
                                    <h5 class="mb-2">Submission Details</h5>
                                    <?php if (!is_array($selectedStudent)): ?>
                                        <div class="text-muted">Select a student to view submission details.</div>
                                    <?php else: ?>
                                        <div class="mb-3">
                                            <div class="fw-semibold"><?php echo tas_h(tas_name($selectedStudent)); ?></div>
                                            <div class="text-muted small"><?php echo tas_h((string) ($selectedStudent['student_no'] ?? '')); ?></div>
                                        </div>

                                        <?php if (!is_array($selectedSubmission)): ?>
                                            <div class="alert alert-light border">No submission yet.</div>
                                        <?php else: ?>
                                            <?php $statusBadge = tas_status_badge((string) ($selectedSubmission['status'] ?? '')); ?>
                                            <div class="mb-2">
                                                <span class="badge <?php echo tas_h((string) ($statusBadge['class'] ?? 'bg-secondary')); ?>"><?php echo tas_h((string) ($statusBadge['label'] ?? '')); ?></span>
                                                <?php if ((int) ($selectedSubmission['is_late'] ?? 0) === 1): ?>
                                                    <span class="badge bg-danger-subtle text-danger">Late</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="small text-muted mb-2">
                                                Submitted: <?php echo tas_h(tas_fmt_datetime((string) ($selectedSubmission['submitted_at'] ?? ''))); ?><br>
                                                Last modified: <?php echo tas_h(tas_fmt_datetime((string) ($selectedSubmission['last_modified_at'] ?? ''))); ?>
                                            </div>
                                            <?php if (trim((string) ($selectedSubmission['submission_text'] ?? '')) !== ''): ?>
                                                <div class="border rounded p-2 mb-3" style="max-height: 200px; overflow: auto;">
                                                    <?php echo nl2br(tas_h((string) ($selectedSubmission['submission_text'] ?? ''))); ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php endif; ?>

                                        <div class="mb-3">
                                            <div class="fw-semibold mb-1">Student files</div>
                                            <?php if (count($studentFiles) === 0): ?>
                                                <div class="text-muted small">No student files uploaded.</div>
                                            <?php else: ?>
                                                <ul class="list-unstyled mb-0">
                                                    <?php foreach ($studentFiles as $file): ?>
                                                        <?php $href = ltrim((string) ($file['file_path'] ?? ''), '/'); ?>
                                                        <li class="mb-1">
                                                            <a href="<?php echo tas_h($href); ?>" target="_blank" rel="noopener"><?php echo tas_h((string) ($file['original_name'] ?? 'file')); ?></a>
                                                            <span class="text-muted small">(<?php echo tas_h(tas_fmt_bytes((int) ($file['file_size'] ?? 0))); ?>)</span>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php endif; ?>
                                        </div>

                                        <hr>
                                        <div class="fw-semibold mb-2">Grade & feedback</div>
                                        <form method="post" class="mb-3">
                                            <input type="hidden" name="csrf_token" value="<?php echo tas_h(csrf_token()); ?>">
                                            <input type="hidden" name="action" value="grade_submission">
                                            <input type="hidden" name="student_id" value="<?php echo (int) $selectedStudentId; ?>">
                                            <div class="mb-2">
                                                <label class="form-label">Grade (max <?php echo tas_h((string) ($ctx['max_score'] ?? '0')); ?>)</label>
                                                <?php
                                                $gradeValue = '';
                                                if (is_array($selectedSubmission) && $selectedSubmission['graded_score'] !== null && is_numeric($selectedSubmission['graded_score'])) {
                                                    $gradeValue = number_format((float) $selectedSubmission['graded_score'], 2, '.', '');
                                                } elseif ($selectedStudent['final_score'] !== null && is_numeric($selectedStudent['final_score'])) {
                                                    $gradeValue = number_format((float) $selectedStudent['final_score'], 2, '.', '');
                                                }
                                                ?>
                                                <input class="form-control" type="number" min="0" step="0.01" name="graded_score" value="<?php echo tas_h($gradeValue); ?>" placeholder="Leave blank to clear grade">
                                            </div>
                                            <div class="mb-2">
                                                <label class="form-label">Feedback comment</label>
                                                <textarea class="form-control" name="feedback_comment" rows="4"><?php echo tas_h((string) ($selectedSubmission['feedback_comment'] ?? '')); ?></textarea>
                                            </div>
                                            <button class="btn btn-primary" type="submit">Save grade</button>
                                        </form>
                                        <?php
                                        $attemptsReopenedSetting = strtolower(trim((string) ($settings['attempts_reopened'] ?? 'never')));
                                        $canReopenManually = $attemptsReopenedSetting === 'manual' && is_array($selectedSubmission) && strtolower(trim((string) ($selectedSubmission['status'] ?? ''))) === 'graded';
                                        ?>
                                        <?php if ($canReopenManually): ?>
                                            <form method="post" class="mb-3">
                                                <input type="hidden" name="csrf_token" value="<?php echo tas_h(csrf_token()); ?>">
                                                <input type="hidden" name="action" value="reopen_submission">
                                                <input type="hidden" name="student_id" value="<?php echo (int) $selectedStudentId; ?>">
                                                <button class="btn btn-outline-warning btn-sm" type="submit">Reopen submission for student</button>
                                            </form>
                                        <?php endif; ?>

                                        <div class="fw-semibold mb-2">Feedback files</div>
                                        <?php if (!empty($settings['feedback_files'])): ?>
                                            <form method="post" enctype="multipart/form-data" class="mb-2">
                                                <input type="hidden" name="csrf_token" value="<?php echo tas_h(csrf_token()); ?>">
                                                <input type="hidden" name="action" value="upload_feedback_file">
                                                <input type="hidden" name="student_id" value="<?php echo (int) $selectedStudentId; ?>">
                                                <input class="form-control mb-2" type="file" name="feedback_file" required>
                                                <button class="btn btn-outline-primary btn-sm" type="submit">Upload feedback file</button>
                                            </form>
                                        <?php else: ?>
                                            <div class="text-muted small mb-2">Feedback files are disabled in assignment settings.</div>
                                        <?php endif; ?>

                                        <?php if (count($feedbackFiles) === 0): ?>
                                            <div class="text-muted small">No feedback files uploaded.</div>
                                        <?php else: ?>
                                            <ul class="list-unstyled mb-0">
                                                <?php foreach ($feedbackFiles as $file): ?>
                                                    <?php $href = ltrim((string) ($file['file_path'] ?? ''), '/'); ?>
                                                    <li class="mb-1 d-flex justify-content-between align-items-center gap-2">
                                                        <div>
                                                            <a href="<?php echo tas_h($href); ?>" target="_blank" rel="noopener"><?php echo tas_h((string) ($file['original_name'] ?? 'file')); ?></a>
                                                            <span class="text-muted small">(<?php echo tas_h(tas_fmt_bytes((int) ($file['file_size'] ?? 0))); ?>)</span>
                                                        </div>
                                                        <form method="post" class="m-0">
                                                            <input type="hidden" name="csrf_token" value="<?php echo tas_h(csrf_token()); ?>">
                                                            <input type="hidden" name="action" value="delete_feedback_file">
                                                            <input type="hidden" name="student_id" value="<?php echo (int) $selectedStudentId; ?>">
                                                            <input type="hidden" name="file_id" value="<?php echo (int) ($file['id'] ?? 0); ?>">
                                                            <button class="btn btn-sm btn-outline-danger" type="submit" onclick="return confirm('Delete this feedback file?');">
                                                                <i class="ri-delete-bin-6-line" aria-hidden="true"></i>
                                                            </button>
                                                        </form>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
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
</body>
</html>
