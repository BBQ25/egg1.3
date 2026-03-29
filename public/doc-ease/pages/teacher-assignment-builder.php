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

if (!function_exists('taba_h')) {
    function taba_h($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('taba_fmt_bytes')) {
    function taba_fmt_bytes($bytes) {
        $bytes = (int) $bytes;
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1024 * 1024) return number_format($bytes / 1024, 2) . ' KB';
        return number_format($bytes / (1024 * 1024), 2) . ' MB';
    }
}

if (!function_exists('taba_clean_filename')) {
    function taba_clean_filename($name) {
        $name = trim((string) $name);
        if ($name === '') return 'file';
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
        $name = trim((string) $name, '._-');
        return $name === '' ? 'file' : substr($name, 0, 180);
    }
}

if (!function_exists('taba_root')) {
    function taba_root() {
        return dirname(__DIR__);
    }
}

if (!function_exists('taba_store_resource')) {
    function taba_store_resource($assessmentId, $teacherId, array $file, &$error = '') {
        $error = '';
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) {
            $error = 'Please choose a file.';
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
            $error = 'File is empty.';
            return null;
        }
        if ($size > (100 * 1024 * 1024)) {
            $error = 'File exceeds 100MB.';
            return null;
        }

        $original = (string) ($file['name'] ?? 'resource');
        $clean = taba_clean_filename($original);
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
        $relDir = 'uploads/assignments/resources/a_' . (int) $assessmentId;
        $relPath = $relDir . '/' . $stored;

        $absDir = taba_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relDir);
        if (!is_dir($absDir) && !@mkdir($absDir, 0775, true) && !is_dir($absDir)) {
            $error = 'Unable to create upload directory.';
            return null;
        }

        $absPath = taba_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
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
            'assessment_id' => (int) $assessmentId,
            'original_name' => substr($original, 0, 255),
            'file_name' => substr($stored, 0, 255),
            'file_path' => substr($relPath, 0, 500),
            'file_size' => $size,
            'mime_type' => substr($mime, 0, 120),
            'uploaded_by' => (int) $teacherId,
        ];
    }
}

if (!function_exists('taba_unlink_rel')) {
    function taba_unlink_rel($path) {
        $path = trim(str_replace('\\', '/', (string) $path));
        if ($path === '' || strpos($path, 'uploads/assignments/') !== 0) return;
        $abs = taba_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (is_file($abs)) @unlink($abs);
    }
}

$ctx = null;
$ctxQ = $conn->prepare(
    "SELECT ga.id AS assessment_id,
            ga.name AS assessment_name,
            ga.max_score,
            ga.assessment_date,
            ga.module_type,
            ga.instructions,
            ga.module_settings_json,
            ga.require_proof_upload,
            ga.open_at,
            ga.close_at,
            ga.grade_to_pass,
            gc.id AS grading_component_id,
            gc.component_name,
            COALESCE(c.category_name, 'Uncategorized') AS category_name,
            sgc.term,
            sgc.section,
            sgc.academic_year,
            sgc.semester,
            cr.id AS class_record_id,
            s.subject_code,
            s.subject_name
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
    deny_access(403, 'Forbidden: not assigned to this assessment.');
}

$componentId = (int) ($ctx['grading_component_id'] ?? 0);
$classRecordId = (int) ($ctx['class_record_id'] ?? 0);
$term = strtolower(trim((string) ($ctx['term'] ?? 'midterm')));
$termLabel = $term === 'final' ? 'Final Term' : 'Midterm';
$moduleType = grading_normalize_module_type((string) ($ctx['module_type'] ?? 'assessment'));
if ($moduleType !== 'assignment') {
    header('Location: teacher-assessment-builder.php?assessment_id=' . $assessmentId);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    if (!csrf_validate($csrf)) {
        $_SESSION['flash_message'] = 'Security check failed (CSRF).';
        $_SESSION['flash_type'] = 'danger';
        header('Location: teacher-assignment-builder.php?assessment_id=' . $assessmentId);
        exit;
    }

    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

    if ($action === 'upload_resource') {
        $upload = isset($_FILES['resource_file']) && is_array($_FILES['resource_file']) ? $_FILES['resource_file'] : null;
        $error = '';
        $stored = $upload ? taba_store_resource($assessmentId, $teacherId, $upload, $error) : null;
        if (!is_array($stored)) {
            $_SESSION['flash_message'] = $error !== '' ? $error : 'Unable to upload file.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: teacher-assignment-builder.php?assessment_id=' . $assessmentId);
            exit;
        }
        $ins = $conn->prepare(
            "INSERT INTO grading_assignment_resources
                (assessment_id, original_name, file_name, file_path, file_size, mime_type, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        if ($ins) {
            $ins->bind_param(
                'isssisi',
                $stored['assessment_id'],
                $stored['original_name'],
                $stored['file_name'],
                $stored['file_path'],
                $stored['file_size'],
                $stored['mime_type'],
                $stored['uploaded_by']
            );
            $ok = $ins->execute();
            $newId = $ok ? (int) $conn->insert_id : 0;
            $ins->close();
            $_SESSION['flash_message'] = $ok ? 'Resource file uploaded.' : 'Unable to save resource metadata.';
            $_SESSION['flash_type'] = $ok ? 'success' : 'danger';

            if ($ok) {
                $evtDate = '';
                $ad = trim((string) ($ctx['assessment_date'] ?? ''));
                if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $ad)) $evtDate = $ad;
                if ($evtDate === '') $evtDate = date('Y-m-d');

                $evtTitle = 'Assignment resource uploaded: ' . (string) ($stored['original_name'] ?? 'file');
                if (strlen($evtTitle) > 255) $evtTitle = substr($evtTitle, 0, 255);
                $evtId = teacher_activity_create_event(
                    $conn,
                    $teacherId,
                    $classRecordId,
                    'assignment_resource_uploaded',
                    $evtDate,
                    $evtTitle,
                    [
                        'assessment_id' => $assessmentId,
                        'resource_id' => $newId,
                        'original_name' => (string) ($stored['original_name'] ?? ''),
                        'file_path' => (string) ($stored['file_path'] ?? ''),
                        'file_size' => (int) ($stored['file_size'] ?? 0),
                        'mime_type' => (string) ($stored['mime_type'] ?? ''),
                    ]
                );
                if ($evtId > 0 && function_exists('teacher_activity_queue_add')) {
                    teacher_activity_queue_add($evtId, $evtTitle, $evtDate);
                }
            }
        } else {
            $_SESSION['flash_message'] = 'Unable to save resource metadata.';
            $_SESSION['flash_type'] = 'danger';
        }
        header('Location: teacher-assignment-builder.php?assessment_id=' . $assessmentId);
        exit;
    }

    if ($action === 'delete_resource') {
        $resourceId = isset($_POST['resource_id']) ? (int) $_POST['resource_id'] : 0;
        $row = null;
        $find = $conn->prepare("SELECT id, file_path FROM grading_assignment_resources WHERE id = ? AND assessment_id = ? LIMIT 1");
        if ($find) {
            $find->bind_param('ii', $resourceId, $assessmentId);
            $find->execute();
            $res = $find->get_result();
            if ($res && $res->num_rows === 1) $row = $res->fetch_assoc();
            $find->close();
        }
        if (!is_array($row)) {
            $_SESSION['flash_message'] = 'Resource not found.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: teacher-assignment-builder.php?assessment_id=' . $assessmentId);
            exit;
        }
        $del = $conn->prepare("DELETE FROM grading_assignment_resources WHERE id = ? AND assessment_id = ? LIMIT 1");
        if ($del) {
            $del->bind_param('ii', $resourceId, $assessmentId);
            $ok = $del->execute();
            $del->close();
            if ($ok) {
                taba_unlink_rel((string) ($row['file_path'] ?? ''));
                $_SESSION['flash_message'] = 'Resource deleted.';
                $_SESSION['flash_type'] = 'success';
            } else {
                $_SESSION['flash_message'] = 'Unable to delete resource.';
                $_SESSION['flash_type'] = 'danger';
            }
        }
        header('Location: teacher-assignment-builder.php?assessment_id=' . $assessmentId);
        exit;
    }

    if ($action === 'save_settings') {
        $name = trim((string) ($_POST['assessment_name'] ?? ''));
        if ($name === '') $name = 'Assignment';
        if (strlen($name) > 120) $name = substr($name, 0, 120);

        $maxRaw = str_replace(',', '.', trim((string) ($_POST['max_score'] ?? '0')));
        if (!preg_match('/^\\d+(?:\\.\\d+)?$/', $maxRaw)) $maxRaw = '0';
        $maxScore = clamp_decimal((float) $maxRaw, 0, 100000);
        $requireProofUpload = isset($_POST['require_proof_upload']) ? 1 : 0;

        $gradePass = '';
        $gradePassRaw = trim((string) ($_POST['grade_to_pass'] ?? ''));
        if ($gradePassRaw !== '') {
            $gradePassRaw = str_replace(',', '.', $gradePassRaw);
            if (preg_match('/^\\d+(?:\\.\\d+)?$/', $gradePassRaw)) {
                $tmp = (float) $gradePassRaw;
                if ($tmp < 0) $tmp = 0;
                if ($tmp > $maxScore) $tmp = $maxScore;
                $gradePass = number_format($tmp, 2, '.', '');
            }
        }

        $openAt = grading_datetime_input_to_mysql((string) ($_POST['open_at'] ?? ''));
        $dueAt = grading_datetime_input_to_mysql((string) ($_POST['due_at'] ?? ''));
        $closeAt = grading_datetime_input_to_mysql((string) ($_POST['close_at'] ?? ''));
        if ($openAt !== '' && $dueAt !== '' && strtotime($openAt) > strtotime($dueAt)) {
            $_SESSION['flash_message'] = 'Due date must be later than open date.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: teacher-assignment-builder.php?assessment_id=' . $assessmentId);
            exit;
        }
        if ($openAt !== '' && $closeAt !== '' && strtotime($openAt) > strtotime($closeAt)) {
            $_SESSION['flash_message'] = 'Cut-off date must be later than open date.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: teacher-assignment-builder.php?assessment_id=' . $assessmentId);
            exit;
        }
        if ($dueAt !== '' && $closeAt !== '' && strtotime($dueAt) > strtotime($closeAt)) {
            $_SESSION['flash_message'] = 'Cut-off date must be later than due date.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: teacher-assignment-builder.php?assessment_id=' . $assessmentId);
            exit;
        }

        $settings = grading_assignment_settings((string) ($ctx['module_settings_json'] ?? ''));
        $settings['description'] = substr(trim((string) ($_POST['description'] ?? '')), 0, 12000);
        $settings['activity_instructions'] = substr(trim((string) ($_POST['activity_instructions'] ?? '')), 0, 12000);
        $settings['due_at'] = $dueAt;
        $settings['remind_grade_by_at'] = grading_datetime_input_to_mysql((string) ($_POST['remind_grade_by_at'] ?? ''));
        $settings['always_show_description'] = isset($_POST['always_show_description']) ? 1 : 0;
        $settings['submission_online_text'] = isset($_POST['submission_online_text']) ? 1 : 0;
        $settings['submission_file'] = isset($_POST['submission_file']) ? 1 : 0;
        $settings['max_uploaded_files'] = isset($_POST['max_uploaded_files']) ? (int) $_POST['max_uploaded_files'] : (int) ($settings['max_uploaded_files'] ?? 20);
        $settings['max_submission_size_mb'] = isset($_POST['max_submission_size_mb']) ? (int) $_POST['max_submission_size_mb'] : (int) ($settings['max_submission_size_mb'] ?? 10);
        $settings['accepted_file_types'] = substr(trim((string) ($_POST['accepted_file_types'] ?? '')), 0, 500);
        $settings['feedback_comments'] = isset($_POST['feedback_comments']) ? 1 : 0;
        $settings['feedback_files'] = isset($_POST['feedback_files']) ? 1 : 0;
        $settings['comment_inline'] = isset($_POST['comment_inline']) ? 1 : 0;
        $settings['require_submit_button'] = isset($_POST['require_submit_button']) ? 1 : 0;
        $settings['require_accept_statement'] = isset($_POST['require_accept_statement']) ? 1 : 0;
        $settings['attempts_reopened'] = trim((string) ($_POST['attempts_reopened'] ?? 'never'));
        $settings['group_submission'] = isset($_POST['group_submission']) ? 1 : 0;
        $settings['notify_graders_submission'] = isset($_POST['notify_graders_submission']) ? 1 : 0;
        $settings['notify_graders_late'] = isset($_POST['notify_graders_late']) ? 1 : 0;
        $settings['default_notify_student'] = isset($_POST['default_notify_student']) ? 1 : 0;
        $settings['grade_method'] = trim((string) ($_POST['grade_method'] ?? 'simple'));
        $settings['grade_category'] = substr(trim((string) ($_POST['grade_category'] ?? '')), 0, 120);
        $settings['anonymous_submissions'] = isset($_POST['anonymous_submissions']) ? 1 : 0;
        $settings['hide_grader_identity'] = isset($_POST['hide_grader_identity']) ? 1 : 0;
        $settings['marking_workflow'] = isset($_POST['marking_workflow']) ? 1 : 0;
        $settings['availability'] = trim((string) ($_POST['availability'] ?? 'show'));
        $settings['id_number'] = substr(trim((string) ($_POST['id_number'] ?? '')), 0, 120);
        $settings['force_language'] = substr(trim((string) ($_POST['force_language'] ?? '')), 0, 40);
        $settings['group_mode'] = trim((string) ($_POST['group_mode'] ?? 'no_groups'));
        $settings['completion_tracking'] = trim((string) ($_POST['completion_tracking'] ?? 'manual'));
        $settings['expect_completed_on'] = grading_datetime_input_to_mysql((string) ($_POST['expect_completed_on'] ?? ''));
        $settings['tags'] = substr(trim((string) ($_POST['tags'] ?? '')), 0, 500);
        $settings['send_content_change_notification'] = isset($_POST['send_content_change_notification']) ? 1 : 0;
        if ($requireProofUpload) {
            $settings['submission_file'] = 1;
        }

        if ((int) $settings['submission_online_text'] !== 1 && (int) $settings['submission_file'] !== 1) {
            $_SESSION['flash_message'] = 'Enable at least one submission type.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: teacher-assignment-builder.php?assessment_id=' . $assessmentId);
            exit;
        }

        $settingsJson = grading_assignment_settings_json($settings);
        $assessmentDate = trim((string) ($_POST['assessment_date'] ?? ''));
        $assessmentDate = preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $assessmentDate) ? $assessmentDate : '';
        $description = (string) ($settings['description'] ?? '');

        $upd = $conn->prepare(
            "UPDATE grading_assessments
             SET name = ?,
                 max_score = ?,
                 assessment_date = NULLIF(?, ''),
                 assessment_mode = 'manual',
                 instructions = NULLIF(?, ''),
                 module_settings_json = ?,
                 require_proof_upload = ?,
                 open_at = NULLIF(?, ''),
                 close_at = NULLIF(?, ''),
                 grade_to_pass = NULLIF(?, '')
             WHERE id = ?
             LIMIT 1"
        );
        if ($upd) {
            $upd->bind_param('sdsssisssi', $name, $maxScore, $assessmentDate, $description, $settingsJson, $requireProofUpload, $openAt, $closeAt, $gradePass, $assessmentId);
            $ok = $upd->execute();
            $upd->close();
            $_SESSION['flash_message'] = $ok ? 'Assignment settings updated.' : 'Unable to update settings.';
            $_SESSION['flash_type'] = $ok ? 'success' : 'danger';
        } else {
            $_SESSION['flash_message'] = 'Unable to update settings.';
            $_SESSION['flash_type'] = 'danger';
        }
        if (isset($_POST['save_and_return']) && (string) $_POST['save_and_return'] === '1') {
            header('Location: teacher-component-assessments.php?grading_component_id=' . $componentId);
            exit;
        }
        header('Location: teacher-assignment-builder.php?assessment_id=' . $assessmentId);
        exit;
    }
}

$settings = grading_assignment_settings((string) ($ctx['module_settings_json'] ?? ''));
$description = trim((string) ($settings['description'] ?? ''));
if ($description === '') $description = trim((string) ($ctx['instructions'] ?? ''));
$resources = [];
$rq = $conn->prepare("SELECT id, original_name, file_path, file_size, created_at FROM grading_assignment_resources WHERE assessment_id = ? ORDER BY id DESC");
if ($rq) {
    $rq->bind_param('i', $assessmentId);
    $rq->execute();
    $res = $rq->get_result();
    while ($res && ($row = $res->fetch_assoc())) $resources[] = $row;
    $rq->close();
}
$summary = ['participants' => 0, 'drafts' => 0, 'submitted' => 0, 'needs_grading' => 0, 'graded' => 0, 'late' => 0];
$p = $conn->prepare("SELECT COUNT(*) AS c FROM class_enrollments WHERE class_record_id = ? AND status = 'enrolled'");
if ($p) {
    $p->bind_param('i', $classRecordId);
    $p->execute();
    $r = $p->get_result();
    if ($r && $r->num_rows === 1) $summary['participants'] = (int) ($r->fetch_assoc()['c'] ?? 0);
    $p->close();
}
$s = $conn->prepare(
    "SELECT
        COALESCE(SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END),0) AS drafts,
        COALESCE(SUM(CASE WHEN status IN ('submitted','graded') THEN 1 ELSE 0 END),0) AS submitted,
        COALESCE(SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END),0) AS needs_grading,
        COALESCE(SUM(CASE WHEN status = 'graded' THEN 1 ELSE 0 END),0) AS graded,
        COALESCE(SUM(CASE WHEN is_late = 1 AND status IN ('submitted','graded') THEN 1 ELSE 0 END),0) AS late
     FROM grading_assignment_submissions
     WHERE assessment_id = ?"
);
if ($s) {
    $s->bind_param('i', $assessmentId);
    $s->execute();
    $r = $s->get_result();
    if ($r && $r->num_rows === 1) {
        $row = $r->fetch_assoc();
        $summary['drafts'] = (int) ($row['drafts'] ?? 0);
        $summary['submitted'] = (int) ($row['submitted'] ?? 0);
        $summary['needs_grading'] = (int) ($row['needs_grading'] ?? 0);
        $summary['graded'] = (int) ($row['graded'] ?? 0);
        $summary['late'] = (int) ($row['late'] ?? 0);
    }
    $s->close();
}
?>

<head>
    <title>Assignment Builder | E-Record</title>
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
                                        <li class="breadcrumb-item active">Assignment Builder</li>
                                    </ol>
                                </div>
                                <h4 class="page-title">Assignment Builder</h4>
                            </div>
                        </div>
                    </div>

                    <?php if ($flash): ?>
                        <div class="alert alert-<?php echo taba_h($flashType); ?>"><?php echo taba_h($flash); ?></div>
                    <?php endif; ?>

                    <div class="row g-3">
                        <div class="col-xl-8">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                                        <div>
                                            <h5 class="mb-1"><?php echo taba_h((string) ($ctx['assessment_name'] ?? 'Assignment')); ?></h5>
                                            <div class="text-muted small">
                                                <?php echo taba_h((string) ($ctx['subject_name'] ?? '')); ?> (<?php echo taba_h((string) ($ctx['subject_code'] ?? '')); ?>)
                                                | <?php echo taba_h((string) ($ctx['section'] ?? '')); ?>
                                                | <?php echo taba_h((string) ($ctx['academic_year'] ?? '')); ?>, <?php echo taba_h((string) ($ctx['semester'] ?? '')); ?>
                                                | <?php echo taba_h($termLabel); ?>
                                            </div>
                                            <div class="mt-2 d-flex flex-wrap gap-2">
                                                <span class="badge bg-primary-subtle text-primary">Assignment</span>
                                                <span class="badge bg-light text-dark border"><?php echo taba_h((string) ($ctx['component_name'] ?? '')); ?></span>
                                                <span class="badge bg-secondary-subtle text-secondary"><?php echo taba_h((string) ($ctx['category_name'] ?? '')); ?></span>
                                            </div>
                                        </div>
                                        <div class="text-end">
                                            <a class="btn btn-sm btn-outline-primary" href="teacher-assignment-submissions.php?assessment_id=<?php echo (int) $assessmentId; ?>">Submissions</a>
                                            <a class="btn btn-sm btn-outline-secondary" href="teacher-component-assessments.php?grading_component_id=<?php echo (int) $componentId; ?>">Back</a>
                                        </div>
                                    </div>

                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?php echo taba_h(csrf_token()); ?>">
                                        <input type="hidden" name="action" value="save_settings">

                                        <div class="border rounded p-3 mb-3">
                                            <div class="fw-semibold mb-2">General</div>
                                            <div class="mb-2">
                                                <label class="form-label">Assignment name</label>
                                                <input class="form-control" name="assessment_name" maxlength="120" value="<?php echo taba_h((string) ($ctx['assessment_name'] ?? '')); ?>" required>
                                            </div>
                                            <div class="mb-2">
                                                <label class="form-label">Description</label>
                                                <textarea class="form-control" name="description" rows="3"><?php echo taba_h($description); ?></textarea>
                                            </div>
                                            <div class="mb-0">
                                                <label class="form-label">Activity instructions</label>
                                                <textarea class="form-control" name="activity_instructions" rows="3"><?php echo taba_h((string) ($settings['activity_instructions'] ?? '')); ?></textarea>
                                            </div>
                                        </div>

                                        <div class="border rounded p-3 mb-3">
                                            <div class="fw-semibold mb-2">Availability</div>
                                            <div class="row g-2">
                                                <div class="col-lg-6">
                                                    <label class="form-label">Allow submissions from</label>
                                                    <input class="form-control" type="datetime-local" name="open_at" value="<?php echo taba_h(grading_datetime_mysql_to_input((string) ($ctx['open_at'] ?? ''))); ?>">
                                                </div>
                                                <div class="col-lg-6">
                                                    <label class="form-label">Due date</label>
                                                    <input class="form-control" type="datetime-local" name="due_at" value="<?php echo taba_h(grading_datetime_mysql_to_input((string) ($settings['due_at'] ?? ''))); ?>">
                                                </div>
                                            </div>
                                            <div class="row g-2 mt-0">
                                                <div class="col-lg-6">
                                                    <label class="form-label">Cut-off date</label>
                                                    <input class="form-control" type="datetime-local" name="close_at" value="<?php echo taba_h(grading_datetime_mysql_to_input((string) ($ctx['close_at'] ?? ''))); ?>">
                                                </div>
                                                <div class="col-lg-6">
                                                    <label class="form-label">Remind me to grade by</label>
                                                    <input class="form-control" type="datetime-local" name="remind_grade_by_at" value="<?php echo taba_h(grading_datetime_mysql_to_input((string) ($settings['remind_grade_by_at'] ?? ''))); ?>">
                                                </div>
                                            </div>
                                            <div class="form-check mt-2 mb-0">
                                                <input class="form-check-input" type="checkbox" id="alwaysShowDescription" name="always_show_description" <?php echo !empty($settings['always_show_description']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="alwaysShowDescription">Always show description</label>
                                            </div>
                                        </div>

                                        <div class="border rounded p-3 mb-3">
                                            <div class="fw-semibold mb-2">Submission Types</div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="submissionOnlineText" name="submission_online_text" <?php echo !empty($settings['submission_online_text']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="submissionOnlineText">Online text</label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="submissionFile" name="submission_file" <?php echo !empty($settings['submission_file']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="submissionFile">File submissions</label>
                                            </div>
                                            <div class="row g-2 mt-0">
                                                <div class="col-lg-4">
                                                    <label class="form-label">Maximum uploaded files</label>
                                                    <input class="form-control" type="number" min="1" max="50" name="max_uploaded_files" value="<?php echo (int) ($settings['max_uploaded_files'] ?? 20); ?>">
                                                </div>
                                                <div class="col-lg-4">
                                                    <label class="form-label">Maximum submission size (MB)</label>
                                                    <input class="form-control" type="number" min="1" max="200" name="max_submission_size_mb" value="<?php echo (int) ($settings['max_submission_size_mb'] ?? 10); ?>">
                                                    <div class="form-text">For image uploads, files above 5MB are automatically optimized to 5MB or less.</div>
                                                </div>
                                                <div class="col-lg-4">
                                                    <label class="form-label">Accepted file types</label>
                                                    <input class="form-control" name="accepted_file_types" value="<?php echo taba_h((string) ($settings['accepted_file_types'] ?? '')); ?>" placeholder=".pdf,.docx,.zip">
                                                </div>
                                            </div>
                                        </div>

                                        <div class="border rounded p-3 mb-3">
                                            <div class="fw-semibold mb-2">Feedback Types</div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="feedbackComments" name="feedback_comments" <?php echo !empty($settings['feedback_comments']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="feedbackComments">Feedback comments</label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="commentInline" name="comment_inline" <?php echo !empty($settings['comment_inline']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="commentInline">Comment inline</label>
                                            </div>
                                            <div class="form-check mb-0">
                                                <input class="form-check-input" type="checkbox" id="feedbackFiles" name="feedback_files" <?php echo !empty($settings['feedback_files']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="feedbackFiles">Feedback files</label>
                                            </div>
                                        </div>

                                        <div class="border rounded p-3 mb-3">
                                            <div class="fw-semibold mb-2">Submission Settings</div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="requireSubmitButton" name="require_submit_button" <?php echo !empty($settings['require_submit_button']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="requireSubmitButton">Require students to click submit button</label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="requireAcceptStatement" name="require_accept_statement" <?php echo !empty($settings['require_accept_statement']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="requireAcceptStatement">Require submission statement acceptance</label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="requireProofUpload" name="require_proof_upload" <?php echo !empty($ctx['require_proof_upload']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="requireProofUpload">Require at least one proof upload before final submission/scoring</label>
                                            </div>
                                            <div class="form-text">When enabled, file submissions stay available so students can upload proof.</div>
                                            <div class="mt-2">
                                                <label class="form-label">Attempts reopened</label>
                                                <?php $attemptsReopened = (string) ($settings['attempts_reopened'] ?? 'never'); ?>
                                                <select class="form-select" name="attempts_reopened">
                                                    <option value="never" <?php echo $attemptsReopened === 'never' ? 'selected' : ''; ?>>Never</option>
                                                    <option value="manual" <?php echo $attemptsReopened === 'manual' ? 'selected' : ''; ?>>Manually</option>
                                                    <option value="until_pass" <?php echo $attemptsReopened === 'until_pass' ? 'selected' : ''; ?>>Automatically until pass</option>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="border rounded p-3 mb-3">
                                            <div class="fw-semibold mb-2">Group Submission Settings</div>
                                            <div class="form-check mb-0">
                                                <input class="form-check-input" type="checkbox" id="groupSubmission" name="group_submission" <?php echo !empty($settings['group_submission']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="groupSubmission">Students submit in groups</label>
                                            </div>
                                        </div>

                                        <div class="border rounded p-3 mb-3">
                                            <div class="fw-semibold mb-2">Notifications</div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="notifyGradersSubmission" name="notify_graders_submission" <?php echo !empty($settings['notify_graders_submission']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="notifyGradersSubmission">Notify graders about submissions</label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="notifyGradersLate" name="notify_graders_late" <?php echo !empty($settings['notify_graders_late']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="notifyGradersLate">Notify graders about late submissions</label>
                                            </div>
                                            <div class="form-check mb-0">
                                                <input class="form-check-input" type="checkbox" id="defaultNotifyStudent" name="default_notify_student" <?php echo !empty($settings['default_notify_student']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="defaultNotifyStudent">Default for notify students</label>
                                            </div>
                                        </div>

                                        <div class="border rounded p-3 mb-3">
                                            <div class="fw-semibold mb-2">Grade</div>
                                            <div class="row g-2">
                                                <div class="col-lg-4">
                                                    <label class="form-label">Type</label>
                                                    <input class="form-control" value="Point" disabled>
                                                </div>
                                                <div class="col-lg-4">
                                                    <label class="form-label">Maximum grade</label>
                                                    <input class="form-control" type="number" min="0" step="0.01" name="max_score" value="<?php echo taba_h((string) ($ctx['max_score'] ?? '0')); ?>">
                                                </div>
                                                <div class="col-lg-4">
                                                    <label class="form-label">Grade to pass</label>
                                                    <input class="form-control" type="number" min="0" step="0.01" name="grade_to_pass" value="<?php echo taba_h((string) ($ctx['grade_to_pass'] ?? '')); ?>">
                                                </div>
                                            </div>
                                            <div class="row g-2 mt-0">
                                                <div class="col-lg-4">
                                                    <label class="form-label">Grading method</label>
                                                    <?php $gradeMethod = (string) ($settings['grade_method'] ?? 'simple'); ?>
                                                    <select class="form-select" name="grade_method">
                                                        <option value="simple" <?php echo $gradeMethod === 'simple' ? 'selected' : ''; ?>>Simple direct grading</option>
                                                        <option value="rubric" <?php echo $gradeMethod === 'rubric' ? 'selected' : ''; ?>>Rubric</option>
                                                        <option value="marking_guide" <?php echo $gradeMethod === 'marking_guide' ? 'selected' : ''; ?>>Marking guide</option>
                                                    </select>
                                                </div>
                                                <div class="col-lg-4">
                                                    <label class="form-label">Grade category</label>
                                                    <input class="form-control" name="grade_category" value="<?php echo taba_h((string) ($settings['grade_category'] ?? '')); ?>" placeholder="Uncategorized">
                                                </div>
                                                <div class="col-lg-4">
                                                    <label class="form-label">Assessment date</label>
                                                    <input class="form-control" type="date" name="assessment_date" value="<?php echo taba_h((string) ($ctx['assessment_date'] ?? '')); ?>">
                                                </div>
                                            </div>
                                            <div class="form-check mt-2">
                                                <input class="form-check-input" type="checkbox" id="anonymousSubmissions" name="anonymous_submissions" <?php echo !empty($settings['anonymous_submissions']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="anonymousSubmissions">Anonymous submissions</label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="hideGraderIdentity" name="hide_grader_identity" <?php echo !empty($settings['hide_grader_identity']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="hideGraderIdentity">Hide grader identity from students</label>
                                            </div>
                                            <div class="form-check mb-0">
                                                <input class="form-check-input" type="checkbox" id="markingWorkflow" name="marking_workflow" <?php echo !empty($settings['marking_workflow']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="markingWorkflow">Use marking workflow</label>
                                            </div>
                                        </div>

                                        <div class="border rounded p-3 mb-3">
                                            <div class="fw-semibold mb-2">Common Module Settings</div>
                                            <?php $availability = (string) ($settings['availability'] ?? 'show'); ?>
                                            <?php $groupMode = (string) ($settings['group_mode'] ?? 'no_groups'); ?>
                                            <div class="row g-2">
                                                <div class="col-lg-3">
                                                    <label class="form-label">Availability</label>
                                                    <select class="form-select" name="availability">
                                                        <option value="show" <?php echo $availability === 'show' ? 'selected' : ''; ?>>Show on course page</option>
                                                        <option value="hide" <?php echo $availability === 'hide' ? 'selected' : ''; ?>>Hide from students</option>
                                                    </select>
                                                </div>
                                                <div class="col-lg-3">
                                                    <label class="form-label">ID number</label>
                                                    <input class="form-control" name="id_number" value="<?php echo taba_h((string) ($settings['id_number'] ?? '')); ?>">
                                                </div>
                                                <div class="col-lg-3">
                                                    <label class="form-label">Force language</label>
                                                    <input class="form-control" name="force_language" value="<?php echo taba_h((string) ($settings['force_language'] ?? '')); ?>">
                                                </div>
                                                <div class="col-lg-3">
                                                    <label class="form-label">Group mode</label>
                                                    <select class="form-select" name="group_mode">
                                                        <option value="no_groups" <?php echo $groupMode === 'no_groups' ? 'selected' : ''; ?>>No groups</option>
                                                        <option value="separate_groups" <?php echo $groupMode === 'separate_groups' ? 'selected' : ''; ?>>Separate groups</option>
                                                        <option value="visible_groups" <?php echo $groupMode === 'visible_groups' ? 'selected' : ''; ?>>Visible groups</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="border rounded p-3 mb-3">
                                            <div class="fw-semibold mb-2">Restrict Access</div>
                                            <div class="text-muted small">Rule builder will follow in next update. Current access control uses open/due/cut-off dates.</div>
                                        </div>

                                        <div class="border rounded p-3 mb-3">
                                            <div class="fw-semibold mb-2">Activity Completion</div>
                                            <?php $completionTracking = (string) ($settings['completion_tracking'] ?? 'manual'); ?>
                                            <div class="row g-2">
                                                <div class="col-lg-6">
                                                    <label class="form-label">Completion tracking</label>
                                                    <select class="form-select" name="completion_tracking">
                                                        <option value="none" <?php echo $completionTracking === 'none' ? 'selected' : ''; ?>>Do not indicate completion</option>
                                                        <option value="manual" <?php echo $completionTracking === 'manual' ? 'selected' : ''; ?>>Students manually mark complete</option>
                                                        <option value="automatic" <?php echo $completionTracking === 'automatic' ? 'selected' : ''; ?>>Automatic completion</option>
                                                    </select>
                                                </div>
                                                <div class="col-lg-6">
                                                    <label class="form-label">Expect completed on</label>
                                                    <input class="form-control" type="datetime-local" name="expect_completed_on" value="<?php echo taba_h(grading_datetime_mysql_to_input((string) ($settings['expect_completed_on'] ?? ''))); ?>">
                                                </div>
                                            </div>
                                        </div>

                                        <div class="border rounded p-3 mb-3">
                                            <div class="fw-semibold mb-2">Tags</div>
                                            <input class="form-control" name="tags" value="<?php echo taba_h((string) ($settings['tags'] ?? '')); ?>" placeholder="comma,separated,tags">
                                        </div>

                                        <div class="form-check mt-2">
                                            <input class="form-check-input" type="checkbox" id="sendContentChangeNotification" name="send_content_change_notification" <?php echo !empty($settings['send_content_change_notification']) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="sendContentChangeNotification">Send content change notification</label>
                                        </div>

                                        <div class="d-flex flex-wrap gap-2 mt-3">
                                            <button class="btn btn-primary" type="submit">Save and display</button>
                                            <button class="btn btn-outline-primary" type="submit" name="save_and_return" value="1">Save and return to assessments</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-4">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title">Additional Files</h4>
                                    <p class="text-muted">Upload files students can download from this assignment.</p>
                                    <form method="post" enctype="multipart/form-data" class="mb-3">
                                        <input type="hidden" name="csrf_token" value="<?php echo taba_h(csrf_token()); ?>">
                                        <input type="hidden" name="action" value="upload_resource">
                                        <input class="form-control mb-2" type="file" name="resource_file" required>
                                        <button class="btn btn-outline-primary btn-sm" type="submit">Upload file</button>
                                    </form>

                                    <?php if (count($resources) === 0): ?>
                                        <div class="text-muted small">No additional files uploaded yet.</div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-sm align-middle mb-0">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>File</th>
                                                        <th class="text-end">Action</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($resources as $resource): ?>
                                                        <?php $fileHref = ltrim((string) ($resource['file_path'] ?? ''), '/'); ?>
                                                        <tr>
                                                            <td>
                                                                <a href="<?php echo taba_h($fileHref); ?>" target="_blank" rel="noopener">
                                                                    <?php echo taba_h((string) ($resource['original_name'] ?? 'resource')); ?>
                                                                </a>
                                                                <div class="text-muted small"><?php echo taba_h(taba_fmt_bytes((int) ($resource['file_size'] ?? 0))); ?></div>
                                                            </td>
                                                            <td class="text-end">
                                                                <form method="post" class="d-inline">
                                                                    <input type="hidden" name="csrf_token" value="<?php echo taba_h(csrf_token()); ?>">
                                                                    <input type="hidden" name="action" value="delete_resource">
                                                                    <input type="hidden" name="resource_id" value="<?php echo (int) ($resource['id'] ?? 0); ?>">
                                                                    <button class="btn btn-sm btn-outline-danger" type="submit" onclick="return confirm('Delete this file?');">
                                                                        <i class="ri-delete-bin-6-line" aria-hidden="true"></i>
                                                                    </button>
                                                                </form>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title">Grading Summary</h4>
                                    <table class="table table-sm table-bordered align-middle mb-0">
                                        <tbody>
                                            <tr><th style="width: 52%;">Participants</th><td><?php echo (int) ($summary['participants'] ?? 0); ?></td></tr>
                                            <tr><th>Drafts</th><td><?php echo (int) ($summary['drafts'] ?? 0); ?></td></tr>
                                            <tr><th>Submitted</th><td><?php echo (int) ($summary['submitted'] ?? 0); ?></td></tr>
                                            <tr><th>Needs grading</th><td><?php echo (int) ($summary['needs_grading'] ?? 0); ?></td></tr>
                                            <tr><th>Graded</th><td><?php echo (int) ($summary['graded'] ?? 0); ?></td></tr>
                                            <tr><th>Late submissions</th><td><?php echo (int) ($summary['late'] ?? 0); ?></td></tr>
                                        </tbody>
                                    </table>
                                    <a class="btn btn-outline-primary btn-sm mt-3" href="teacher-assignment-submissions.php?assessment_id=<?php echo (int) $assessmentId; ?>">
                                        View submissions
                                    </a>
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
