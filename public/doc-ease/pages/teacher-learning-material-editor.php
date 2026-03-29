<?php include '../layouts/session.php'; ?>
<?php require_active_role('teacher'); ?>

<?php
require_once __DIR__ . '/../includes/learning_materials.php';
require_once __DIR__ . '/../includes/ai_credits.php';
require_once __DIR__ . '/../includes/section_sync.php';
require_once __DIR__ . '/../includes/teacher_activity_events.php';
ensure_learning_material_tables($conn);
ai_credit_ensure_system($conn);
teacher_activity_ensure_tables($conn);

$flash = isset($_SESSION['flash_message']) ? (string) $_SESSION['flash_message'] : '';
$flashType = isset($_SESSION['flash_type']) ? (string) $_SESSION['flash_type'] : 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

$teacherId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$classRecordId = isset($_GET['class_record_id']) ? (int) $_GET['class_record_id'] : 0;
$materialId = isset($_GET['material_id']) ? (int) $_GET['material_id'] : 0;

if ($classRecordId <= 0) {
    $_SESSION['flash_message'] = 'Invalid class.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: teacher-my-classes.php');
    exit;
}

if (!function_exists('tlme_h')) {
    function tlme_h($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('tlme_fmt_datetime')) {
    function tlme_fmt_datetime($value) {
        $value = trim((string) $value);
        if ($value === '') return '-';
        $ts = strtotime($value);
        return $ts ? date('Y-m-d H:i', $ts) : $value;
    }
}
if (!function_exists('tlme_fmt_credit')) {
    function tlme_fmt_credit($value) {
        return number_format((float) $value, 2, '.', '');
    }
}

$ctx = null;
$ctxQ = $conn->prepare(
    "SELECT cr.id AS class_record_id,
            cr.subject_id,
            ta.teacher_role,
            cr.section, cr.academic_year, cr.semester,
            s.subject_code, s.subject_name
     FROM teacher_assignments ta
     JOIN class_records cr ON cr.id = ta.class_record_id
     JOIN subjects s ON s.id = cr.subject_id
     WHERE ta.teacher_id = ?
       AND ta.class_record_id = ?
       AND ta.status = 'active'
       AND cr.status = 'active'
     LIMIT 1"
);
if ($ctxQ) {
    $ctxQ->bind_param('ii', $teacherId, $classRecordId);
    $ctxQ->execute();
    $res = $ctxQ->get_result();
    if ($res && $res->num_rows === 1) $ctx = $res->fetch_assoc();
    $ctxQ->close();
}
if (!is_array($ctx)) {
    deny_access(403, 'Forbidden: not assigned to this class.');
}
$subjectId = (int) ($ctx['subject_id'] ?? 0);
$syncPeerSections = section_sync_get_teacher_peer_sections(
    $conn,
    $teacherId,
    $subjectId,
    (string) ($ctx['academic_year'] ?? ''),
    (string) ($ctx['semester'] ?? ''),
    $classRecordId
);
$syncPreference = section_sync_get_preference(
    $conn,
    $teacherId,
    $subjectId,
    (string) ($ctx['academic_year'] ?? ''),
    (string) ($ctx['semester'] ?? '')
);
$syncPreferredPeerIds = section_sync_preferred_peer_ids($syncPreference, $syncPeerSections);

$nextOrder = learning_material_next_display_order($conn, $classRecordId);
$material = [
    'id' => 0,
    'title' => '',
    'summary' => '',
    'content_html' => '',
    'status' => 'draft',
    'display_order' => $nextOrder,
    'published_at' => '',
    'updated_at' => '',
];
$isEdit = false;

if ($materialId > 0) {
    $q = $conn->prepare(
        "SELECT id, title, summary, content_html, status, display_order, published_at, updated_at
         FROM learning_materials
         WHERE id = ?
           AND class_record_id = ?
         LIMIT 1"
    );
    if ($q) {
        $q->bind_param('ii', $materialId, $classRecordId);
        $q->execute();
        $res = $q->get_result();
        if ($res && $res->num_rows === 1) {
            $material = $res->fetch_assoc();
            $isEdit = true;
        }
        $q->close();
    }

    if (!$isEdit) {
        $_SESSION['flash_message'] = 'Learning material not found.';
        $_SESSION['flash_type'] = 'warning';
        header('Location: teacher-learning-materials.php?class_record_id=' . $classRecordId);
        exit;
    }
}

$attachments = $isEdit ? learning_material_fetch_attachments($conn, $materialId) : [];

$aiCreditInfo = null;
[$okAiCredit, $aiCreditStatusOrMsg] = ai_credit_get_user_status($conn, $teacherId);
if ($okAiCredit && is_array($aiCreditStatusOrMsg)) {
    $aiCreditInfo = $aiCreditStatusOrMsg;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjaxRequest = strtolower(trim((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''))) === 'xmlhttprequest';
    $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    if (!csrf_validate($csrf)) {
        if ($isAjaxRequest) {
            header('Content-Type: application/json; charset=UTF-8');
            http_response_code(403);
            echo json_encode([
                'ok' => false,
                'message' => 'Security check failed (CSRF). Please refresh and try again.',
            ]);
            exit;
        }
        $errors[] = 'Security check failed (CSRF). Please retry.';
    } else {
        $action = trim((string) ($_POST['action'] ?? 'save_material'));

        if ($action === 'ai_format') {
            header('Content-Type: application/json; charset=UTF-8');
            if (!$isAjaxRequest) {
                echo json_encode(['ok' => false, 'message' => 'Invalid request type.']);
                exit;
            }

            $title = learning_material_plain_text($_POST['title'] ?? '', 200);
            $summary = learning_material_plain_text($_POST['summary'] ?? '', 1200);
            $contentRaw = (string) ($_POST['content_html'] ?? '');
            $contentHtml = learning_material_sanitize_html($contentRaw);
            if ($title === '') {
                echo json_encode(['ok' => false, 'message' => 'Title is required before AI formatting.']);
                exit;
            }
            if ($contentHtml === '') {
                echo json_encode(['ok' => false, 'message' => 'Content is required before AI formatting.']);
                exit;
            }

            $creditCost = learning_material_ai_format_cost($title, $summary, $contentHtml);
            $charged = false;
            [$okConsume, $consumeMsg] = ai_credit_try_consume_count($conn, $teacherId, $creditCost);
            if (!$okConsume) {
                echo json_encode(['ok' => false, 'message' => (string) $consumeMsg]);
                exit;
            }
            $charged = true;

            [$okPolish, $polishDataOrMsg] = learning_material_ai_polish_content($title, $summary, $contentHtml);
            if (!$okPolish) {
                if ($charged) ai_credit_refund($conn, $teacherId, $creditCost);
                echo json_encode(['ok' => false, 'message' => (string) $polishDataOrMsg]);
                exit;
            }

            $formatted = is_array($polishDataOrMsg) ? $polishDataOrMsg : [];
            [$okRemain, $remainOrMsg] = ai_credit_get_user_status($conn, $teacherId);
            $remaining = null;
            if ($okRemain && is_array($remainOrMsg)) {
                $remaining = (float) ($remainOrMsg['remaining'] ?? 0);
            }

            echo json_encode([
                'ok' => true,
                'message' => 'AI formatting completed.',
                'summary' => (string) ($formatted['summary'] ?? ''),
                'content_html' => (string) ($formatted['content_html'] ?? ''),
                'credit_cost' => (float) $creditCost,
                'remaining' => $remaining,
            ]);
            exit;
        }

        if ($action === 'upload_attachment') {
            if (!$isEdit || $materialId <= 0) {
                $_SESSION['flash_message'] = 'Save the learning material first before adding attachments.';
                $_SESSION['flash_type'] = 'warning';
                header('Location: teacher-learning-material-editor.php?class_record_id=' . $classRecordId);
                exit;
            }

            $upload = isset($_FILES['attachment_file']) && is_array($_FILES['attachment_file']) ? $_FILES['attachment_file'] : null;
            $error = '';
            $stored = $upload ? learning_material_store_attachment($materialId, $upload, $teacherId, $error) : null;
            if (!is_array($stored)) {
                $_SESSION['flash_message'] = $error !== '' ? $error : 'Unable to upload attachment.';
                $_SESSION['flash_type'] = 'warning';
                header('Location: teacher-learning-material-editor.php?class_record_id=' . $classRecordId . '&material_id=' . $materialId);
                exit;
            }

            $ins = $conn->prepare(
                "INSERT INTO learning_material_files
                    (material_id, original_name, file_name, file_path, file_size, mime_type, uploaded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            if ($ins) {
                $ins->bind_param(
                    'isssisi',
                    $stored['material_id'],
                    $stored['original_name'],
                    $stored['file_name'],
                    $stored['file_path'],
                    $stored['file_size'],
                    $stored['mime_type'],
                    $stored['uploaded_by']
                );
                $ok = $ins->execute();
                $ins->close();
                $_SESSION['flash_message'] = $ok ? 'Attachment uploaded.' : 'Unable to save attachment metadata.';
                $_SESSION['flash_type'] = $ok ? 'success' : 'danger';
            } else {
                $_SESSION['flash_message'] = 'Unable to save attachment metadata.';
                $_SESSION['flash_type'] = 'danger';
            }
            header('Location: teacher-learning-material-editor.php?class_record_id=' . $classRecordId . '&material_id=' . $materialId);
            exit;
        }

        if ($action === 'delete_attachment') {
            if (!$isEdit || $materialId <= 0) {
                $_SESSION['flash_message'] = 'Invalid attachment request.';
                $_SESSION['flash_type'] = 'warning';
                header('Location: teacher-learning-material-editor.php?class_record_id=' . $classRecordId);
                exit;
            }

            $attachmentId = isset($_POST['attachment_id']) ? (int) $_POST['attachment_id'] : 0;
            $ok = learning_material_delete_attachment($conn, $attachmentId, $materialId);
            $_SESSION['flash_message'] = $ok ? 'Attachment deleted.' : 'Attachment not found.';
            $_SESSION['flash_type'] = $ok ? 'success' : 'warning';
            header('Location: teacher-learning-material-editor.php?class_record_id=' . $classRecordId . '&material_id=' . $materialId);
            exit;
        }

        if ($action === 'copy_to_sections') {
            if (!$isEdit || $materialId <= 0) {
                $_SESSION['flash_message'] = 'Save the material first before copying.';
                $_SESSION['flash_type'] = 'warning';
                header('Location: teacher-learning-material-editor.php?class_record_id=' . $classRecordId);
                exit;
            }

            $postedSelectedForPreference = section_sync_filter_selected_ids($_POST['apply_section_ids'] ?? [], $syncPeerSections);
            $autoApplyPref = !empty($_POST['sync_auto_apply']);
            section_sync_save_preference(
                $conn,
                $teacherId,
                $subjectId,
                (string) ($ctx['academic_year'] ?? ''),
                (string) ($ctx['semester'] ?? ''),
                $postedSelectedForPreference,
                $autoApplyPref
            );
            $selectedSyncIds = section_sync_resolve_target_ids(
                $_POST['apply_section_ids'] ?? [],
                $syncPeerSections,
                $syncPreference,
                true
            );
            if (count($selectedSyncIds) === 0) {
                $_SESSION['flash_message'] = 'Select at least one target section to copy.';
                $_SESSION['flash_type'] = 'warning';
                header('Location: teacher-learning-material-editor.php?class_record_id=' . $classRecordId . '&material_id=' . $materialId);
                exit;
            }

            $source = null;
            $srcQ = $conn->prepare(
                "SELECT id, title, summary, content_html, status, published_at
                 FROM learning_materials
                 WHERE id = ?
                   AND class_record_id = ?
                 LIMIT 1"
            );
            if ($srcQ) {
                $srcQ->bind_param('ii', $materialId, $classRecordId);
                $srcQ->execute();
                $srcRes = $srcQ->get_result();
                if ($srcRes && $srcRes->num_rows === 1) $source = $srcRes->fetch_assoc();
                $srcQ->close();
            }
            if (!is_array($source)) {
                $_SESSION['flash_message'] = 'Material not found for copy.';
                $_SESSION['flash_type'] = 'danger';
                header('Location: teacher-learning-material-editor.php?class_record_id=' . $classRecordId . '&material_id=' . $materialId);
                exit;
            }

            $syncCopied = 0;
            $syncSkipped = 0;
            $targetQ = $conn->prepare(
                "SELECT cr.id
                 FROM teacher_assignments ta
                 JOIN class_records cr ON cr.id = ta.class_record_id
                 WHERE ta.teacher_id = ?
                   AND ta.status = 'active'
                   AND cr.status = 'active'
                   AND cr.subject_id = ?
                   AND cr.academic_year = ?
                   AND cr.semester = ?
                   AND cr.id = ?
                 LIMIT 1"
            );
            $copyIns = $conn->prepare(
                "INSERT INTO learning_materials
                    (class_record_id, title, summary, content_html, status, display_order, published_at, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            if ($targetQ && $copyIns) {
                $syncAcademicYear = (string) ($ctx['academic_year'] ?? '');
                $syncSemester = (string) ($ctx['semester'] ?? '');
                $sourceTitle = (string) ($source['title'] ?? '');
                $sourceSummary = (string) ($source['summary'] ?? '');
                $sourceHtml = (string) ($source['content_html'] ?? '');
                $sourceStatus = learning_material_normalize_status($source['status'] ?? 'draft');
                $sourcePublishedAt = trim((string) ($source['published_at'] ?? '')) !== '' ? (string) $source['published_at'] : null;

                foreach ($selectedSyncIds as $targetClassId) {
                    $targetClassId = (int) $targetClassId;
                    $targetQ->bind_param('iissi', $teacherId, $subjectId, $syncAcademicYear, $syncSemester, $targetClassId);
                    $targetQ->execute();
                    $targetRes = $targetQ->get_result();
                    $targetOk = $targetRes && $targetRes->num_rows === 1;
                    if (!$targetOk) {
                        $syncSkipped++;
                        continue;
                    }

                    $targetOrder = learning_material_next_display_order($conn, $targetClassId);
                    $copyIns->bind_param(
                        'issssisii',
                        $targetClassId,
                        $sourceTitle,
                        $sourceSummary,
                        $sourceHtml,
                        $sourceStatus,
                        $targetOrder,
                        $sourcePublishedAt,
                        $teacherId,
                        $teacherId
                    );
                    if ($copyIns->execute()) $syncCopied++;
                    else $syncSkipped++;
                }
                $targetQ->close();
                $copyIns->close();
            } else {
                $syncSkipped += count($selectedSyncIds);
                if ($targetQ) $targetQ->close();
                if ($copyIns) $copyIns->close();
            }

            $msg = 'Copy completed.';
            if ($syncCopied > 0) $msg .= ' Copied to ' . $syncCopied . ' section(s).';
            if ($syncSkipped > 0) $msg .= ' Skipped ' . $syncSkipped . ' section(s).';
            $_SESSION['flash_message'] = $msg;
            $_SESSION['flash_type'] = $syncCopied > 0 ? 'success' : 'warning';
            header('Location: teacher-learning-material-editor.php?class_record_id=' . $classRecordId . '&material_id=' . $materialId);
            exit;
        }

        $title = learning_material_plain_text($_POST['title'] ?? '', 200);
        $summary = learning_material_plain_text($_POST['summary'] ?? '', 1200);
        $contentRaw = (string) ($_POST['content_html'] ?? '');
        $contentHtml = learning_material_sanitize_html($contentRaw);
        $prevStatus = learning_material_normalize_status($material['status'] ?? 'draft');
        $status = learning_material_normalize_status($_POST['status'] ?? 'draft');
        $displayOrder = isset($_POST['display_order']) ? (int) $_POST['display_order'] : 0;
        if ($displayOrder <= 0) {
            $displayOrder = learning_material_next_display_order($conn, $classRecordId);
        }

        $material['title'] = $title;
        $material['summary'] = $summary;
        $material['content_html'] = $contentHtml;
        $material['status'] = $status;
        $material['display_order'] = $displayOrder;

        if ($title === '') {
            $errors[] = 'Title is required.';
        }
        if ($contentHtml === '') {
            $errors[] = 'Content is required.';
        }

        if (count($errors) === 0) {
            $wasEdit = $isEdit;
            $publishedAt = null;
            if ($status === 'published') {
                $oldPublishedAt = trim((string) ($material['published_at'] ?? ''));
                $publishedAt = $oldPublishedAt !== '' ? $oldPublishedAt : date('Y-m-d H:i:s');
            }

            $saveOk = false;
            if ($isEdit) {
                $up = $conn->prepare(
                    "UPDATE learning_materials
                     SET title = ?,
                         summary = ?,
                         content_html = ?,
                         status = ?,
                         display_order = ?,
                         published_at = ?,
                         updated_by = ?
                     WHERE id = ?
                       AND class_record_id = ?
                     LIMIT 1"
                );
                if ($up) {
                    $up->bind_param(
                        'ssssisiii',
                        $title,
                        $summary,
                        $contentHtml,
                        $status,
                        $displayOrder,
                        $publishedAt,
                        $teacherId,
                        $materialId,
                        $classRecordId
                    );
                    $saveOk = $up->execute();
                    $up->close();
                }
            } else {
                $ins = $conn->prepare(
                    "INSERT INTO learning_materials
                        (class_record_id, title, summary, content_html, status, display_order, published_at, created_by, updated_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                if ($ins) {
                    $ins->bind_param(
                        'issssisii',
                        $classRecordId,
                        $title,
                        $summary,
                        $contentHtml,
                        $status,
                        $displayOrder,
                        $publishedAt,
                        $teacherId,
                        $teacherId
                    );
                    $saveOk = $ins->execute();
                    if ($saveOk) {
                        $materialId = (int) $conn->insert_id;
                        $isEdit = true;
                    }
                    $ins->close();
                }
            }

            if ($saveOk) {
                // Trigger accomplishment prompt for created/published learning material.
                $evtDate = date('Y-m-d');
                $evtTitle = '';
                $evtType = '';
                $payload = [
                    'material_id' => (int) $materialId,
                    'title' => $title,
                    'status' => $status,
                ];

                if (!$wasEdit) {
                    $evtType = 'learning_material_created';
                    $evtTitle = 'Learning material created: ' . $title;
                } elseif ($status === 'published' && $prevStatus !== 'published') {
                    $evtType = 'learning_material_published';
                    $evtTitle = 'Learning material published: ' . $title;
                }

                if ($evtType !== '' && $evtTitle !== '') {
                    if (strlen($evtTitle) > 255) $evtTitle = substr($evtTitle, 0, 255);
                    $evtId = teacher_activity_create_event(
                        $conn,
                        $teacherId,
                        $classRecordId,
                        $evtType,
                        $evtDate,
                        $evtTitle,
                        $payload
                    );
                    if ($evtId > 0 && function_exists('teacher_activity_queue_add')) {
                        teacher_activity_queue_add($evtId, $evtTitle, $evtDate);
                    }
                }

                $syncCopied = 0;
                $syncSkipped = 0;
                if (!$wasEdit && $materialId > 0) {
                    $selectedSyncIds = section_sync_resolve_target_ids(
                        $_POST['apply_section_ids'] ?? [],
                        $syncPeerSections,
                        $syncPreference,
                        true
                    );
                    $postedSelectedForPreference = section_sync_filter_selected_ids($_POST['apply_section_ids'] ?? [], $syncPeerSections);
                    $autoApplyPref = !empty($_POST['sync_auto_apply']);
                    section_sync_save_preference(
                        $conn,
                        $teacherId,
                        $subjectId,
                        (string) ($ctx['academic_year'] ?? ''),
                        (string) ($ctx['semester'] ?? ''),
                        $postedSelectedForPreference,
                        $autoApplyPref
                    );
                    if (count($selectedSyncIds) > 0) {
                        $targetQ = $conn->prepare(
                            "SELECT cr.id
                             FROM teacher_assignments ta
                             JOIN class_records cr ON cr.id = ta.class_record_id
                             WHERE ta.teacher_id = ?
                               AND ta.status = 'active'
                               AND cr.status = 'active'
                               AND cr.subject_id = ?
                               AND cr.academic_year = ?
                               AND cr.semester = ?
                               AND cr.id = ?
                             LIMIT 1"
                        );
                        $copyIns = $conn->prepare(
                            "INSERT INTO learning_materials
                                (class_record_id, title, summary, content_html, status, display_order, published_at, created_by, updated_by)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                        );
                        if ($targetQ && $copyIns) {
                            foreach ($selectedSyncIds as $targetClassId) {
                                $targetClassId = (int) $targetClassId;
                                $targetOk = false;
                                $syncAcademicYear = (string) ($ctx['academic_year'] ?? '');
                                $syncSemester = (string) ($ctx['semester'] ?? '');
                                $targetQ->bind_param(
                                    'iissi',
                                    $teacherId,
                                    $subjectId,
                                    $syncAcademicYear,
                                    $syncSemester,
                                    $targetClassId
                                );
                                $targetQ->execute();
                                $targetRes = $targetQ->get_result();
                                $targetOk = $targetRes && $targetRes->num_rows === 1;
                                if (!$targetOk) {
                                    $syncSkipped++;
                                    continue;
                                }

                                $targetOrder = learning_material_next_display_order($conn, $targetClassId);
                                $copyIns->bind_param(
                                    'issssisii',
                                    $targetClassId,
                                    $title,
                                    $summary,
                                    $contentHtml,
                                    $status,
                                    $targetOrder,
                                    $publishedAt,
                                    $teacherId,
                                    $teacherId
                                );
                                if ($copyIns->execute()) $syncCopied++;
                                else $syncSkipped++;
                            }
                            $targetQ->close();
                            $copyIns->close();
                        } else {
                            $syncSkipped += count($selectedSyncIds);
                            if ($targetQ) $targetQ->close();
                            if ($copyIns) $copyIns->close();
                        }
                    }
                }

                $baseMessage = $wasEdit ? 'Learning material saved.' : 'Learning material created.';
                if ($syncCopied > 0) {
                    $baseMessage .= ' Applied to ' . $syncCopied . ' other section(s).';
                }
                if ($syncSkipped > 0) {
                    $baseMessage .= ' Skipped ' . $syncSkipped . ' section(s).';
                }
                $_SESSION['flash_message'] = $baseMessage;
                $_SESSION['flash_type'] = 'success';
                $saveAndReturn = isset($_POST['save_and_return']) && (int) $_POST['save_and_return'] === 1;
                if ($saveAndReturn) {
                    header('Location: teacher-learning-materials.php?class_record_id=' . $classRecordId);
                } else {
                    header('Location: teacher-learning-material-editor.php?class_record_id=' . $classRecordId . '&material_id=' . $materialId);
                }
                exit;
            }

            $errors[] = 'Unable to save learning material.';
        }
    }
}
?>

<?php include '../layouts/main.php'; ?>

<head>
    <title><?php echo $isEdit ? 'Edit Learning Material' : 'New Learning Material'; ?> | E-Record</title>
    <?php include '../layouts/title-meta.php'; ?>
    <?php include '../layouts/head-css.php'; ?>
    <link href="assets/vendor/quill/quill.core.css" rel="stylesheet" type="text/css" />
    <link href="assets/vendor/quill/quill.snow.css" rel="stylesheet" type="text/css" />
    <style>
        #materialEditor {
            min-height: 420px;
            background: #fff;
        }

        .lm-editor-help {
            font-size: 0.86rem;
            color: #5f6983;
        }

        .lm-attachment-item {
            border: 1px solid #e3e8f4;
            border-radius: 0.5rem;
            padding: 0.6rem 0.75rem;
            background: #fff;
        }

        .lm-ai-credit-chip {
            border: 1px solid #d5deef;
            background: #f6f8fc;
            color: #35507a;
            border-radius: 999px;
            padding: 0.3rem 0.65rem;
            font-size: 0.82rem;
        }

        .lm-ai-progress-pulse {
            position: relative;
            overflow: hidden;
            height: 0.35rem;
            border-radius: 999px;
            background: #e7edf8;
        }

        .lm-ai-progress-pulse::before {
            content: '';
            position: absolute;
            top: 0;
            left: -40%;
            width: 40%;
            height: 100%;
            background: linear-gradient(90deg, #3f67cf, #6f93f0);
            animation: lm-ai-progress-slide 1.2s ease-in-out infinite;
        }

        @keyframes lm-ai-progress-slide {
            from { left: -40%; }
            to { left: 100%; }
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
                                        <li class="breadcrumb-item"><a href="teacher-dashboard.php">Teacher</a></li>
                                        <li class="breadcrumb-item"><a href="teacher-my-classes.php">My Classes</a></li>
                                        <li class="breadcrumb-item"><a href="teacher-learning-materials.php?class_record_id=<?php echo (int) $classRecordId; ?>">Learning Materials</a></li>
                                        <li class="breadcrumb-item active"><?php echo $isEdit ? 'Edit' : 'Create'; ?></li>
                                    </ol>
                                </div>
                                <h4 class="page-title"><?php echo $isEdit ? 'Edit Learning Material' : 'Create Learning Material'; ?></h4>
                            </div>
                        </div>
                    </div>

                    <?php if ($flash !== ''): ?>
                        <div class="alert alert-<?php echo tlme_h($flashType); ?> alert-dismissible fade show" role="alert">
                            <?php echo tlme_h($flash); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <?php if (count($errors) > 0): ?>
                        <div class="alert alert-danger">
                            <?php foreach ($errors as $err): ?>
                                <div><?php echo tlme_h((string) $err); ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-lg-9">
                            <div class="card">
                                <div class="card-body">
                                    <form method="post" class="js-material-form" data-is-edit="<?php echo $isEdit ? '1' : '0'; ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo tlme_h(csrf_token()); ?>">
                                        <input type="hidden" name="action" value="save_material">
                                        <input type="hidden" name="material_id" value="<?php echo (int) ($material['id'] ?? 0); ?>">

                                        <div class="mb-3">
                                            <label class="form-label">Title <span class="text-danger">*</span></label>
                                            <input class="form-control" id="materialTitleInput" name="title" maxlength="200" value="<?php echo tlme_h((string) ($material['title'] ?? '')); ?>" required>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">Summary</label>
                                            <textarea class="form-control" id="materialSummaryInput" name="summary" rows="3" maxlength="1200" placeholder="Optional short description shown on the materials list."><?php echo tlme_h((string) ($material['summary'] ?? '')); ?></textarea>
                                        </div>

                                        <div class="mb-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
                                            <label class="form-label mb-0">Content <span class="text-danger">*</span></label>
                                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                                <span class="lm-ai-credit-chip" id="lmAiCreditChip">
                                                    AI credits:
                                                    <?php echo $aiCreditInfo ? tlme_h(tlme_fmt_credit((float) ($aiCreditInfo['remaining'] ?? 0))) : 'N/A'; ?>
                                                </span>
                                                <button class="btn btn-sm btn-outline-primary" type="button" id="lmAiPolishBtn">
                                                    <i class="ri-magic-line me-1" aria-hidden="true"></i>
                                                    AI Polish (max 10 credits)
                                                </button>
                                            </div>
                                        </div>
                                        <div class="lm-editor-help mb-2">Make indentation, spacing, and wording professional. AI usage is capped to 10 credits per run.</div>
                                        <div id="lmAiAlert" class="d-none"></div>
                                        <div id="materialEditor"></div>
                                        <textarea id="contentHtmlInput" name="content_html" class="d-none"><?php echo tlme_h((string) ($material['content_html'] ?? '')); ?></textarea>

                                        <div class="row mt-3">
                                            <div class="col-md-4 mb-3 mb-md-0">
                                                <label class="form-label">Status</label>
                                                <select class="form-select" name="status">
                                                    <option value="draft" <?php echo learning_material_normalize_status($material['status'] ?? '') === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                                    <option value="published" <?php echo learning_material_normalize_status($material['status'] ?? '') === 'published' ? 'selected' : ''; ?>>Published</option>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Display Order</label>
                                                <input class="form-control" type="number" name="display_order" min="1" step="1" value="<?php echo (int) ($material['display_order'] ?? $nextOrder); ?>">
                                            </div>
                                        </div>

                                        <?php if (!$isEdit && count($syncPeerSections) > 0): ?>
                                            <div class="mt-3 border rounded p-3">
                                                <div class="fw-semibold mb-1">Apply To Other Sections</div>
                                                <div class="text-muted small mb-2">Same subject and term. Current section is excluded.</div>
                                                <div class="d-flex flex-wrap gap-2">
                                                    <?php foreach ($syncPeerSections as $peerSection): ?>
                                                        <?php $peerClassId = (int) ($peerSection['class_record_id'] ?? 0); ?>
                                                        <label class="form-check form-check-inline m-0">
                                                            <input class="form-check-input js-lm-sync-target" type="checkbox" name="apply_section_ids[]" value="<?php echo $peerClassId; ?>" <?php echo in_array($peerClassId, $syncPreferredPeerIds, true) ? 'checked' : ''; ?>>
                                                            <span class="form-check-label"><?php echo tlme_h((string) ($peerSection['section'] ?? ('Section ' . $peerClassId))); ?></span>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </div>
                                                <div class="form-check mt-2 mb-0">
                                                    <input class="form-check-input" type="checkbox" id="lmSyncAutoApply" name="sync_auto_apply" value="1" <?php echo !empty($syncPreference['auto_apply']) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label small" for="lmSyncAutoApply">
                                                        Always apply to selected sections for this subject/term
                                                    </label>
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                        <div class="mt-4 d-flex flex-wrap gap-2">
                                            <button class="btn btn-primary" type="submit">
                                                <i class="ri-save-3-line me-1" aria-hidden="true"></i>
                                                Save
                                            </button>
                                            <button class="btn btn-outline-primary" type="submit" name="save_and_return" value="1">
                                                Save and return
                                            </button>
                                            <?php if ($isEdit): ?>
                                                <a class="btn btn-outline-info" href="teacher-learning-material-preview.php?material_id=<?php echo (int) $materialId; ?>" target="_blank">
                                                    <i class="ri-eye-line me-1" aria-hidden="true"></i>
                                                    Preview
                                                </a>
                                            <?php endif; ?>
                                            <a class="btn btn-outline-secondary" href="teacher-learning-materials.php?class_record_id=<?php echo (int) $classRecordId; ?>">
                                                Cancel
                                            </a>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-3">
                            <div class="card">
                                <div class="card-body">
                                    <h5 class="mb-2">Class Context</h5>
                                    <div class="fw-semibold"><?php echo tlme_h((string) ($ctx['subject_name'] ?? 'Subject')); ?></div>
                                    <?php if (!empty($ctx['subject_code'])): ?>
                                        <div class="text-muted mb-2"><?php echo tlme_h((string) ($ctx['subject_code'] ?? '')); ?></div>
                                    <?php endif; ?>
                                    <div class="text-muted small">
                                        Section: <?php echo tlme_h((string) ($ctx['section'] ?? '')); ?><br>
                                        AY: <?php echo tlme_h((string) ($ctx['academic_year'] ?? '')); ?><br>
                                        Semester: <?php echo tlme_h((string) ($ctx['semester'] ?? '')); ?>
                                    </div>

                                    <?php if ($isEdit): ?>
                                        <hr>
                                        <div class="small text-muted">
                                            Published: <?php echo tlme_h(tlme_fmt_datetime((string) ($material['published_at'] ?? ''))); ?><br>
                                            Updated: <?php echo tlme_h(tlme_fmt_datetime((string) ($material['updated_at'] ?? ''))); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if ($isEdit && count($syncPeerSections) > 0): ?>
                                <div class="card">
                                    <div class="card-body">
                                        <h5 class="mb-2">Copy To Sections</h5>
                                        <div class="text-muted small mb-2">Copy this saved material to your other sections with the same subject and term.</div>
                                        <form method="post" class="js-lm-copy-form">
                                            <input type="hidden" name="csrf_token" value="<?php echo tlme_h(csrf_token()); ?>">
                                            <input type="hidden" name="action" value="copy_to_sections">
                                            <input type="hidden" name="material_id" value="<?php echo (int) $materialId; ?>">
                                            <div class="d-flex flex-wrap gap-2">
                                                <?php foreach ($syncPeerSections as $peerSection): ?>
                                                    <?php $peerClassId = (int) ($peerSection['class_record_id'] ?? 0); ?>
                                                    <label class="form-check form-check-inline m-0">
                                                        <input class="form-check-input js-lm-copy-target" type="checkbox" name="apply_section_ids[]" value="<?php echo $peerClassId; ?>" <?php echo in_array($peerClassId, $syncPreferredPeerIds, true) ? 'checked' : ''; ?>>
                                                        <span class="form-check-label"><?php echo tlme_h((string) ($peerSection['section'] ?? ('Section ' . $peerClassId))); ?></span>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                            <div class="form-check mt-2 mb-2">
                                                <input class="form-check-input js-lm-copy-auto" type="checkbox" id="lmCopySyncAutoApply" name="sync_auto_apply" value="1" <?php echo !empty($syncPreference['auto_apply']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label small" for="lmCopySyncAutoApply">
                                                    Always apply to selected sections for this subject/term
                                                </label>
                                            </div>
                                            <button class="btn btn-sm btn-outline-primary" type="submit">
                                                <i class="ri-file-copy-line me-1" aria-hidden="true"></i>
                                                Copy Material
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="card">
                                <div class="card-body">
                                    <h5 class="mb-2">Attachments</h5>
                                    <?php if (!$isEdit): ?>
                                        <div class="text-muted small">Save this material first, then upload files.</div>
                                    <?php else: ?>
                                        <form method="post" enctype="multipart/form-data" class="mb-3">
                                            <input type="hidden" name="csrf_token" value="<?php echo tlme_h(csrf_token()); ?>">
                                            <input type="hidden" name="action" value="upload_attachment">
                                            <input type="hidden" name="material_id" value="<?php echo (int) $materialId; ?>">
                                            <div class="input-group">
                                                <input class="form-control form-control-sm" type="file" name="attachment_file" required>
                                                <button class="btn btn-sm btn-outline-primary" type="submit">Upload</button>
                                            </div>
                                            <div class="text-muted small mt-1">Max 100MB.</div>
                                        </form>

                                        <?php if (count($attachments) === 0): ?>
                                            <div class="text-muted small">No attachments yet.</div>
                                        <?php else: ?>
                                            <div class="d-flex flex-column gap-2">
                                                <?php foreach ($attachments as $attachment): ?>
                                                    <?php
                                                    $attachmentId = (int) ($attachment['id'] ?? 0);
                                                    $attachmentHref = learning_material_public_file_href((string) ($attachment['file_path'] ?? ''));
                                                    ?>
                                                    <div class="lm-attachment-item">
                                                        <div class="fw-semibold small"><?php echo tlme_h((string) ($attachment['original_name'] ?? 'Attachment')); ?></div>
                                                        <div class="text-muted small mb-2"><?php echo tlme_h(learning_material_fmt_bytes((int) ($attachment['file_size'] ?? 0))); ?></div>
                                                        <div class="d-flex gap-1">
                                                            <a class="btn btn-sm btn-outline-secondary" href="<?php echo tlme_h($attachmentHref); ?>" target="_blank" download>
                                                                <i class="ri-download-2-line" aria-hidden="true"></i>
                                                            </a>
                                                            <form method="post" class="d-inline">
                                                                <input type="hidden" name="csrf_token" value="<?php echo tlme_h(csrf_token()); ?>">
                                                                <input type="hidden" name="action" value="delete_attachment">
                                                                <input type="hidden" name="material_id" value="<?php echo (int) $materialId; ?>">
                                                                <input type="hidden" name="attachment_id" value="<?php echo $attachmentId; ?>">
                                                                <button class="btn btn-sm btn-outline-danger" type="submit" onclick="return confirm('Delete this attachment?');">
                                                                    <i class="ri-delete-bin-6-line" aria-hidden="true"></i>
                                                                </button>
                                                            </form>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
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

    <div class="modal fade" id="lmAiProgressModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">AI Formatting In Progress</h5>
                </div>
                <div class="modal-body">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <div class="spinner-border text-primary" role="status" aria-hidden="true"></div>
                        <div id="lmAiProgressText" class="fw-semibold">Preparing content for AI...</div>
                    </div>
                    <div class="lm-ai-progress-pulse"></div>
                </div>
            </div>
        </div>
    </div>

    <?php include '../layouts/right-sidebar.php'; ?>
    <?php include '../layouts/footer-scripts.php'; ?>
    <script src="assets/vendor/quill/quill.min.js"></script>
    <script src="assets/js/app.min.js"></script>
    <script>
        (function() {
            var editorEl = document.getElementById('materialEditor');
            var contentInput = document.getElementById('contentHtmlInput');
            var titleInput = document.getElementById('materialTitleInput');
            var summaryInput = document.getElementById('materialSummaryInput');
            var aiBtn = document.getElementById('lmAiPolishBtn');
            var aiAlert = document.getElementById('lmAiAlert');
            var aiCreditChip = document.getElementById('lmAiCreditChip');
            var progressText = document.getElementById('lmAiProgressText');
            var progressModalEl = document.getElementById('lmAiProgressModal');
            var progressModal = (progressModalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal)
                ? bootstrap.Modal.getOrCreateInstance(progressModalEl)
                : null;

            if (!editorEl || !contentInput || typeof Quill === 'undefined') return;

            var quill = new Quill(editorEl, {
                theme: 'snow',
                placeholder: 'Write your learning material content...',
                modules: {
                    toolbar: [
                        [{ header: [1, 2, 3, false] }],
                        ['bold', 'italic', 'underline', 'strike'],
                        [{ list: 'ordered' }, { list: 'bullet' }],
                        [{ align: [] }],
                        ['blockquote', 'code-block'],
                        ['link', 'image'],
                        ['clean']
                    ]
                }
            });

            if (contentInput.value.trim() !== '') {
                quill.clipboard.dangerouslyPasteHTML(contentInput.value);
            }

            document.querySelectorAll('.js-material-form').forEach(function(form) {
                form.addEventListener('submit', function(event) {
                    contentInput.value = quill.root.innerHTML;

                    var isEditForm = String(form.getAttribute('data-is-edit') || '0') === '1';
                    if (isEditForm) return;

                    var syncBoxes = form.querySelectorAll('.js-lm-sync-target');
                    if (!syncBoxes || syncBoxes.length === 0) return;

                    var checkedCount = 0;
                    syncBoxes.forEach(function(box) {
                        if (box.checked) checkedCount++;
                    });

                    if (checkedCount > 0) return;

                    var autoApplyInput = form.querySelector('#lmSyncAutoApply');
                    if (autoApplyInput && autoApplyInput.checked) return;

                    var shouldApplyAll = window.confirm('Apply this new material to your other sections in the same subject/term?');
                    if (!shouldApplyAll) return;

                    syncBoxes.forEach(function(box) {
                        box.checked = true;
                    });
                });
            });

            document.querySelectorAll('.js-lm-copy-form').forEach(function(form) {
                form.addEventListener('submit', function() {
                    var syncBoxes = form.querySelectorAll('.js-lm-copy-target');
                    if (!syncBoxes || syncBoxes.length === 0) return;

                    var checkedCount = 0;
                    syncBoxes.forEach(function(box) {
                        if (box.checked) checkedCount++;
                    });
                    if (checkedCount > 0) return;

                    var autoApply = form.querySelector('.js-lm-copy-auto');
                    if (autoApply && autoApply.checked) return;

                    var shouldApplyAll = window.confirm('Copy this material to all available sibling sections?');
                    if (!shouldApplyAll) return;

                    syncBoxes.forEach(function(box) {
                        box.checked = true;
                    });
                });
            });

            function showAiAlert(type, message) {
                if (!aiAlert) return;
                var text = String(message || '').trim();
                if (text === '') {
                    aiAlert.className = 'd-none';
                    aiAlert.innerHTML = '';
                    return;
                }
                var safeType = String(type || 'info');
                aiAlert.className = 'alert alert-' + safeType + ' mt-2';
                aiAlert.textContent = text;
            }

            function setAiBusy(isBusy) {
                if (aiBtn) aiBtn.disabled = !!isBusy;
            }

            if (aiBtn) {
                aiBtn.addEventListener('click', async function() {
                    var title = titleInput ? String(titleInput.value || '').trim() : '';
                    var summary = summaryInput ? String(summaryInput.value || '') : '';
                    var html = String(quill.root.innerHTML || '').trim();
                    if (title === '') {
                        showAiAlert('warning', 'Please provide a title before AI formatting.');
                        if (titleInput) titleInput.focus();
                        return;
                    }
                    if (html === '') {
                        showAiAlert('warning', 'Please provide content before AI formatting.');
                        return;
                    }

                    var csrf = document.querySelector('input[name="csrf_token"]');
                    if (!csrf || String(csrf.value || '').trim() === '') {
                        showAiAlert('danger', 'Missing CSRF token. Refresh the page and retry.');
                        return;
                    }

                    setAiBusy(true);
                    showAiAlert('', '');

                    var progressMessages = [
                        'Preparing content for AI...',
                        'Analyzing paragraph flow and structure...',
                        'Refining indentation, spacing, and tone...',
                        'Finalizing polished output...'
                    ];
                    var progressIndex = 0;
                    if (progressText) progressText.textContent = progressMessages[0];
                    if (progressModal) progressModal.show();
                    var progressTimer = setInterval(function() {
                        progressIndex = (progressIndex + 1) % progressMessages.length;
                        if (progressText) progressText.textContent = progressMessages[progressIndex];
                    }, 1300);

                    try {
                        var body = new URLSearchParams();
                        body.set('csrf_token', String(csrf.value || ''));
                        body.set('action', 'ai_format');
                        body.set('title', title);
                        body.set('summary', summary);
                        body.set('content_html', html);

                        var response = await fetch(window.location.pathname + window.location.search, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: body.toString()
                        });

                        var rawText = await response.text();
                        var json = null;
                        try {
                            json = JSON.parse(rawText);
                        } catch (e) {
                            throw new Error('AI returned an unexpected server response. Please retry. If this continues, refresh the page and check for PHP warnings/errors.');
                        }
                        if (!json || typeof json !== 'object') {
                            throw new Error('Invalid AI response.');
                        }
                        if (!json.ok) {
                            throw new Error(String(json.message || 'AI formatting failed.'));
                        }

                        var newSummary = String(json.summary || '');
                        var newHtml = String(json.content_html || '');
                        if (newHtml.trim() === '') {
                            throw new Error('AI returned empty formatted content.');
                        }

                        if (summaryInput) summaryInput.value = newSummary;
                        quill.setContents([]);
                        quill.clipboard.dangerouslyPasteHTML(newHtml);
                        contentInput.value = newHtml;

                        if (aiCreditChip && json.remaining !== null && json.remaining !== undefined && !isNaN(Number(json.remaining))) {
                            aiCreditChip.textContent = 'AI credits: ' + Number(json.remaining).toFixed(2);
                        }

                        var creditCost = (json.credit_cost !== null && json.credit_cost !== undefined) ? Number(json.credit_cost) : null;
                        var msg = 'AI formatting completed.';
                        if (creditCost !== null && !isNaN(creditCost)) {
                            msg += ' Credits used: ' + creditCost.toFixed(2) + '.';
                        }
                        showAiAlert('success', msg);
                    } catch (err) {
                        showAiAlert('danger', err && err.message ? String(err.message) : 'AI formatting failed.');
                    } finally {
                        clearInterval(progressTimer);
                        if (progressModal) progressModal.hide();
                        setAiBusy(false);
                    }
                });
            }
        })();
    </script>
</body>

</html>
