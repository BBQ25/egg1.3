<?php include '../layouts/session.php'; ?>
<?php require_active_role('teacher'); ?>
<?php include '../layouts/main.php'; ?>

<?php
require_once __DIR__ . '/../includes/tos_tqs.php';
require_once __DIR__ . '/../includes/grading.php';

ttq_ensure_tables($conn);
ensure_grading_tables($conn);

if (!function_exists('ttq_page_h')) {
    function ttq_page_h($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('ttq_page_class_label')) {
    function ttq_page_class_label(array $row) {
        $subjectCode = trim((string) ($row['subject_code'] ?? ''));
        $subjectName = trim((string) ($row['subject_name'] ?? ''));
        $section = trim((string) ($row['section'] ?? ''));
        $year = trim((string) ($row['academic_year'] ?? ''));
        $semester = trim((string) ($row['semester'] ?? ''));
        $parts = [];
        if ($subjectCode !== '' || $subjectName !== '') $parts[] = trim($subjectCode . ' - ' . $subjectName, ' -');
        if ($section !== '') $parts[] = $section;
        if ($year !== '' || $semester !== '') $parts[] = trim($year . ' ' . $semester);
        return implode(' | ', $parts);
    }
}

if (!function_exists('ttq_page_default_track_from_class')) {
    function ttq_page_default_track_from_class(array $classRow) {
        $track = ttq_default_track();
        $track['subject_code'] = ttq_clean_text((string) ($classRow['subject_code'] ?? ''), 40);
        $track['subject_name'] = ttq_clean_text((string) ($classRow['subject_name'] ?? ''), 160);
        $section = ttq_clean_text((string) ($classRow['section'] ?? ''), 40);
        if ($section !== '') $track['track_label'] = 'Section ' . $section;
        return $track;
    }
}

if (!function_exists('ttq_page_merge_track_optional_sections')) {
    function ttq_page_merge_track_optional_sections(array $trackPosted, array $trackExisting) {
        if ((!isset($trackPosted['rubric_levels']) || !is_array($trackPosted['rubric_levels']) || count($trackPosted['rubric_levels']) === 0)
            && isset($trackExisting['rubric_levels']) && is_array($trackExisting['rubric_levels'])) {
            $trackPosted['rubric_levels'] = $trackExisting['rubric_levels'];
        }
        if ((!isset($trackPosted['rubric_criteria']) || !is_array($trackPosted['rubric_criteria']) || count($trackPosted['rubric_criteria']) === 0)
            && isset($trackExisting['rubric_criteria']) && is_array($trackExisting['rubric_criteria'])) {
            $trackPosted['rubric_criteria'] = $trackExisting['rubric_criteria'];
        }
        return $trackPosted;
    }
}

if (!function_exists('ttq_page_collect_payload')) {
    function ttq_page_collect_payload(array $post, array $files, $currentDoc, array $classMap, $teacherDisplayName, &$error = '') {
        $error = '';

        $classRecordId = isset($post['class_record_id']) ? (int) $post['class_record_id'] : 0;
        if ($classRecordId <= 0 || !isset($classMap[$classRecordId])) $classRecordId = 0;
        $classRow = $classRecordId > 0 ? $classMap[$classRecordId] : null;

        $term = ttq_term_enum((string) ($post['term'] ?? ($currentDoc['term'] ?? 'midterm')));
        $documentMode = ttq_document_mode((string) ($post['document_mode'] ?? ($currentDoc['document_mode'] ?? 'standalone')));
        $title = ttq_clean_text((string) ($post['title'] ?? ($currentDoc['title'] ?? '')), 180);
        $academicYear = ttq_clean_text((string) ($post['academic_year'] ?? ($currentDoc['academic_year'] ?? '')), 40);
        $semester = ttq_clean_text((string) ($post['semester'] ?? ($currentDoc['semester'] ?? '')), 40);
        $examEndAt = ttq_parse_dt_input((string) ($post['exam_end_at'] ?? ''));
        $release = isset($post['student_answer_key_release']) ? 1 : 0;

        if ($academicYear === '' && is_array($classRow)) $academicYear = ttq_clean_text((string) ($classRow['academic_year'] ?? ''), 40);
        if ($semester === '' && is_array($classRow)) $semester = ttq_clean_text((string) ($classRow['semester'] ?? ''), 40);

        $preparedByName = ttq_clean_text((string) ($post['prepared_by_name'] ?? ($currentDoc['prepared_by_name'] ?? $teacherDisplayName)), 120);
        $approvedByName = ttq_clean_text((string) ($post['approved_by_name'] ?? ($currentDoc['approved_by_name'] ?? '')), 120);

        $preparedSignaturePath = ttq_clean_text((string) ($post['prepared_signature_current'] ?? ($currentDoc['prepared_signature_path'] ?? '')), 255);
        $approvedSignaturePath = ttq_clean_text((string) ($post['approved_signature_current'] ?? ($currentDoc['approved_signature_path'] ?? '')), 255);

        $sigErr = '';
        if (isset($files['prepared_signature']) && is_array($files['prepared_signature'])) {
            $uploaded = ttq_save_signature_upload($files['prepared_signature'], 'prepared', $sigErr);
            if ($sigErr !== '') {
                $error = $sigErr;
                return [];
            }
            if ($uploaded !== '') $preparedSignaturePath = $uploaded;
        }

        $sigErr = '';
        if (isset($files['approved_signature']) && is_array($files['approved_signature'])) {
            $uploaded = ttq_save_signature_upload($files['approved_signature'], 'approved', $sigErr);
            if ($sigErr !== '') {
                $error = $sigErr;
                return [];
            }
            if ($uploaded !== '') $approvedSignaturePath = $uploaded;
        }

        $currentContent = isset($currentDoc['content']) && is_array($currentDoc['content'])
            ? ttq_normalize_content($currentDoc['content'])
            : ttq_default_content();

        $schoolName = ttq_clean_text((string) ($post['school_name'] ?? ($currentContent['school_name'] ?? '')), 220);
        $documentLabel = ttq_clean_text((string) ($post['document_label'] ?? ($currentContent['document_label'] ?? '')), 180);
        if ($schoolName === '') $schoolName = ttq_default_content()['school_name'];
        if ($documentLabel === '') $documentLabel = ttq_default_content()['document_label'];

        $existingTracks = isset($currentContent['tracks']) && is_array($currentContent['tracks']) ? $currentContent['tracks'] : [];
        $tracksRaw = isset($post['tracks']) && is_array($post['tracks']) ? $post['tracks'] : [];
        $tracks = [];
        foreach ($tracksRaw as $idx => $trackRow) {
            $idx = (int) $idx;
            if (isset($existingTracks[$idx]) && is_array($existingTracks[$idx])) {
                $trackRow = ttq_page_merge_track_optional_sections((array) $trackRow, (array) $existingTracks[$idx]);
            }
            $tracks[] = ttq_normalize_track($trackRow, $idx);
        }

        if (count($tracks) === 0) {
            if (is_array($classRow)) $tracks[] = ttq_page_default_track_from_class($classRow);
            else $tracks[] = ttq_default_track();
        }

        if ($documentMode === 'standalone' && count($tracks) > 1) {
            $tracks = [ttq_normalize_track($tracks[0], 0)];
        }
        if ($documentMode === 'standalone' && is_array($classRow) && isset($tracks[0])) {
            if (trim((string) ($tracks[0]['subject_code'] ?? '')) === '') $tracks[0]['subject_code'] = ttq_clean_text((string) ($classRow['subject_code'] ?? ''), 40);
            if (trim((string) ($tracks[0]['subject_name'] ?? '')) === '') $tracks[0]['subject_name'] = ttq_clean_text((string) ($classRow['subject_name'] ?? ''), 160);
        }

        if ($title === '') {
            $parts = [];
            if (is_array($classRow)) {
                $parts[] = ttq_clean_text((string) ($classRow['subject_code'] ?? ''), 40);
                $parts[] = ttq_clean_text((string) ($classRow['subject_name'] ?? ''), 120);
            }
            $parts[] = ttq_term_label($term);
            $parts[] = 'TOS/TQS';
            $title = ttq_clean_text(implode(' ', array_filter($parts, static function ($v) {
                return trim((string) $v) !== '';
            })), 180);
            if ($title === '') $title = 'TOS/TQS';
        }

        return [
            'class_record_id' => $classRecordId > 0 ? $classRecordId : null,
            'term' => $term,
            'document_mode' => $documentMode,
            'title' => $title,
            'academic_year' => $academicYear,
            'semester' => $semester,
            'exam_end_at' => $examEndAt,
            'student_answer_key_release' => $release,
            'prepared_by_name' => $preparedByName,
            'prepared_signature_path' => $preparedSignaturePath,
            'approved_by_name' => $approvedByName,
            'approved_signature_path' => $approvedSignaturePath,
            'content' => [
                'school_name' => $schoolName,
                'document_label' => $documentLabel,
                'tracks' => $tracks,
            ],
        ];
    }
}

$flash = isset($_SESSION['flash_message']) ? (string) $_SESSION['flash_message'] : '';
$flashType = isset($_SESSION['flash_type']) ? (string) $_SESSION['flash_type'] : 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

$teacherId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$teacherRow = current_user_row($conn);
$teacherDisplayName = $teacherRow ? current_user_display_name($teacherRow) : ((string) ($_SESSION['user_name'] ?? 'Teacher'));

$classOptions = ttq_fetch_teacher_class_options($conn, $teacherId);
$classMap = [];
$classLabels = [];
foreach ($classOptions as $co) {
    $cid = (int) ($co['class_record_id'] ?? 0);
    if ($cid <= 0) continue;
    $classMap[$cid] = $co;
    $classLabels[$cid] = ttq_page_class_label($co);
}

$selectedClassId = isset($_GET['class_record_id']) ? (int) $_GET['class_record_id'] : 0;
if ($selectedClassId > 0 && !isset($classMap[$selectedClassId])) $selectedClassId = 0;
$selectedDocumentId = isset($_GET['document_id']) ? (int) $_GET['document_id'] : 0;

$redirectTo = static function ($docId, $classId) {
    $params = [];
    $docId = (int) $docId;
    $classId = (int) $classId;
    if ($classId > 0) $params['class_record_id'] = $classId;
    if ($docId > 0) $params['document_id'] = $docId;
    $url = 'teacher-tos-tqs.php';
    if (count($params) > 0) $url .= '?' . http_build_query($params);
    header('Location: ' . $url);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    if (!csrf_validate($csrf)) {
        $_SESSION['flash_message'] = 'Security check failed (CSRF).';
        $_SESSION['flash_type'] = 'danger';
        $redirectTo($selectedDocumentId, $selectedClassId);
    }

    $action = trim((string) ($_POST['action'] ?? ''));
    $postDocId = isset($_POST['document_id']) ? (int) $_POST['document_id'] : 0;
    $postClassId = isset($_POST['class_record_id']) ? (int) $_POST['class_record_id'] : $selectedClassId;
    if ($postClassId > 0 && !isset($classMap[$postClassId])) $postClassId = 0;
    $currentDoc = $postDocId > 0 ? ttq_fetch_document($conn, $postDocId, $teacherId) : null;

    if (in_array($action, ['save_document', 'approve_document', 'recommend_track_ai'], true)) {
        $payloadErr = '';
        $payload = ttq_page_collect_payload($_POST, $_FILES, $currentDoc, $classMap, $teacherDisplayName, $payloadErr);
        if ($payloadErr !== '') {
            $_SESSION['flash_message'] = $payloadErr;
            $_SESSION['flash_type'] = 'danger';
            $redirectTo($postDocId, $postClassId);
        }
        $activeClassId = (int) ($payload['class_record_id'] ?? 0);

        if ($action === 'recommend_track_ai') {
            $trackIndex = isset($_POST['recommend_track_index']) ? (int) $_POST['recommend_track_index'] : -1;
            $content = ttq_normalize_content((array) ($payload['content'] ?? []));
            $tracks = isset($content['tracks']) && is_array($content['tracks']) ? $content['tracks'] : [];
            if ($trackIndex < 0 || !isset($tracks[$trackIndex])) {
                $_SESSION['flash_message'] = 'Invalid track selected for AI recommendation.';
                $_SESSION['flash_type'] = 'warning';
                $redirectTo($postDocId, $activeClassId);
            }
            $recommendation = ttq_ai_track_recommendation((array) $tracks[$trackIndex]);
            $tracks[$trackIndex] = ttq_apply_track_recommendation((array) $tracks[$trackIndex], $recommendation);
            $content['tracks'] = $tracks;
            $payload['content'] = $content;
        }

        $saveErr = '';
        $targetDocId = $postDocId;
        if ($postDocId > 0) {
            $ok = ttq_update_document($conn, $postDocId, $teacherId, $payload, $saveErr);
            if (!$ok) {
                $_SESSION['flash_message'] = $saveErr !== '' ? $saveErr : 'Unable to save document.';
                $_SESSION['flash_type'] = 'danger';
                $redirectTo($postDocId, $activeClassId);
            }
        } else {
            $targetDocId = ttq_create_document($conn, $teacherId, $payload, $saveErr);
            if ($targetDocId <= 0) {
                $_SESSION['flash_message'] = $saveErr !== '' ? $saveErr : 'Unable to create document.';
                $_SESSION['flash_type'] = 'danger';
                $redirectTo(0, $activeClassId);
            }
        }

        if ($action === 'save_document') {
            $_SESSION['flash_message'] = 'TOS/TQS document saved.';
            $_SESSION['flash_type'] = 'success';
            $redirectTo($targetDocId, $activeClassId);
        }

        if ($action === 'recommend_track_ai') {
            $_SESSION['flash_message'] = 'AI recommendation applied.';
            $_SESSION['flash_type'] = 'success';
            $redirectTo($targetDocId, $activeClassId);
        }

        $note = ttq_clean_text((string) ($_POST['approval_note'] ?? ''), 255);
        $approveErr = '';
        $ok = ttq_approve_document(
            $conn,
            $targetDocId,
            $teacherId,
            (string) ($payload['approved_by_name'] ?? ''),
            (string) ($payload['approved_signature_path'] ?? ''),
            $note,
            $approveErr
        );
        $_SESSION['flash_message'] = $ok ? 'Document approved.' : ($approveErr !== '' ? $approveErr : 'Unable to approve document.');
        $_SESSION['flash_type'] = $ok ? 'success' : 'danger';
        $redirectTo($targetDocId, $activeClassId);
    }

    if ($action === 'mark_exam_finished') {
        $err = '';
        $ok = ttq_mark_exam_finished($conn, $postDocId, $teacherId, $err);
        $_SESSION['flash_message'] = $ok ? 'Exam marked as finished.' : ($err !== '' ? $err : 'Unable to update exam status.');
        $_SESSION['flash_type'] = $ok ? 'success' : 'danger';
        $redirectTo($postDocId, $postClassId);
    }

    if ($action === 'toggle_answer_release') {
        $enabled = isset($_POST['answer_release_enabled']) ? (int) $_POST['answer_release_enabled'] : 0;
        $err = '';
        $ok = ttq_set_student_answer_key_release($conn, $postDocId, $teacherId, $enabled === 1, $err);
        $_SESSION['flash_message'] = $ok
            ? ($enabled === 1 ? 'Student answer key visibility enabled.' : 'Student answer key visibility disabled.')
            : ($err !== '' ? $err : 'Unable to update answer key visibility.');
        $_SESSION['flash_type'] = $ok ? 'success' : 'danger';
        $redirectTo($postDocId, $postClassId);
    }

    if ($action === 'create_assessment') {
        $componentId = isset($_POST['component_id']) ? (int) $_POST['component_id'] : 0;
        $trackIndex = isset($_POST['track_index']) ? (int) $_POST['track_index'] : -1;
        $doc = ttq_fetch_document($conn, $postDocId, $teacherId);
        if (!$doc) {
            $_SESSION['flash_message'] = 'Document not found.';
            $_SESSION['flash_type'] = 'danger';
            $redirectTo(0, $postClassId);
        }
        $err = '';
        $assessmentId = ttq_create_assessment_from_document($conn, $teacherId, $doc, $componentId, $trackIndex, $err);
        if ($assessmentId > 0) {
            $_SESSION['flash_message'] = 'Assessment created from TQS.';
            $_SESSION['flash_type'] = 'success';
            header('Location: teacher-assessment-builder.php?assessment_id=' . (int) $assessmentId);
            exit;
        }
        $_SESSION['flash_message'] = $err !== '' ? $err : 'Unable to create assessment.';
        $_SESSION['flash_type'] = 'danger';
        $redirectTo($postDocId, $postClassId);
    }

    if ($action === 'delete_document') {
        $stmt = $conn->prepare("DELETE FROM tos_tqs_documents WHERE id = ? AND teacher_id = ? LIMIT 1");
        $ok = false;
        if ($stmt) {
            $stmt->bind_param('ii', $postDocId, $teacherId);
            $ok = $stmt->execute();
            $stmt->close();
        }
        $_SESSION['flash_message'] = $ok ? 'Document deleted.' : 'Unable to delete document.';
        $_SESSION['flash_type'] = $ok ? 'success' : 'danger';
        $redirectTo(0, $postClassId);
    }

    $_SESSION['flash_message'] = 'Unsupported action.';
    $_SESSION['flash_type'] = 'warning';
    $redirectTo($postDocId, $postClassId);
}

$documents = ttq_fetch_teacher_documents($conn, $teacherId, $selectedClassId);
$currentDoc = $selectedDocumentId > 0 ? ttq_fetch_document($conn, $selectedDocumentId, $teacherId) : null;
if (!$currentDoc) {
    $seedClass = ($selectedClassId > 0 && isset($classMap[$selectedClassId])) ? $classMap[$selectedClassId] : null;
    $defaultContent = ttq_default_content();
    if (is_array($seedClass)) $defaultContent['tracks'] = [ttq_page_default_track_from_class($seedClass)];
    $currentDoc = [
        'id' => 0,
        'class_record_id' => $seedClass ? (int) ($seedClass['class_record_id'] ?? 0) : 0,
        'term' => 'midterm',
        'document_mode' => 'standalone',
        'title' => '',
        'academic_year' => $seedClass ? (string) ($seedClass['academic_year'] ?? '') : '',
        'semester' => $seedClass ? (string) ($seedClass['semester'] ?? '') : '',
        'exam_end_at' => null,
        'exam_finished_at' => null,
        'student_answer_key_release' => 0,
        'prepared_by_name' => $teacherDisplayName,
        'prepared_signature_path' => '',
        'approved_by_name' => '',
        'approved_signature_path' => '',
        'status' => 'draft',
        'version_no' => 1,
        'approval_note' => '',
        'content' => $defaultContent,
    ];
}

$docId = (int) ($currentDoc['id'] ?? 0);
$docClassId = (int) ($currentDoc['class_record_id'] ?? 0);
$effectiveClassId = $docClassId > 0 ? $docClassId : $selectedClassId;
$content = isset($currentDoc['content']) && is_array($currentDoc['content']) ? ttq_normalize_content($currentDoc['content']) : ttq_decode_content_json((string) ($currentDoc['content_json'] ?? ''));
$tracks = isset($content['tracks']) && is_array($content['tracks']) ? $content['tracks'] : [ttq_default_track()];

$mode = ttq_document_mode((string) ($currentDoc['document_mode'] ?? 'standalone'));
$term = ttq_term_enum((string) ($currentDoc['term'] ?? 'midterm'));
$status = (string) ($currentDoc['status'] ?? 'draft');
$statusLabel = ttq_doc_status_label($status);
$statusBadgeClass = ttq_doc_status_badge_class($status);
$isExamFinished = ttq_is_exam_finished($currentDoc);
$studentAnswerVisible = ttq_student_answer_key_visible($currentDoc);
$releaseToggleTarget = $studentAnswerVisible ? 0 : 1;
$releaseToggleLabel = $studentAnswerVisible ? 'Hide Student Answer Key' : 'Enable Student Answer Key';

$componentTerm = in_array($term, ['midterm', 'final'], true) ? $term : '';
$componentOptions = ttq_fetch_teacher_component_options($conn, $teacherId, $effectiveClassId, $componentTerm);
$dimensionKeys = ttq_dimension_keys();
$dimensionLabels = ttq_dimension_labels();

$exportCsrf = csrf_token();
?>
<head>
    <title>TOS/TQS Builder | E-Record</title>
    <?php include '../layouts/title-meta.php'; ?>
    <?php include '../layouts/head-css.php'; ?>
    <style>
        .ttq-doc-list-row.active { background: rgba(37, 99, 235, .08); }
        .ttq-track-card { border: 1px solid #d9e1ec; border-radius: .5rem; margin-bottom: 1rem; }
        .ttq-track-header { border-bottom: 1px solid #d9e1ec; background: #f8fafc; padding: .65rem .85rem; }
        .ttq-track-body { padding: .85rem; }
        .ttq-subsection-title { font-size: .82rem; text-transform: uppercase; letter-spacing: .05em; color: #475569; margin: .25rem 0 .5rem; font-weight: 700; }
        .ttq-sign-preview { max-height: 64px; max-width: 220px; object-fit: contain; border: 1px solid #dee2e6; border-radius: .35rem; background: #fff; padding: .2rem; }
        .ttq-scroll-wrap { max-height: 58vh; overflow: auto; }
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
                                        <li class="breadcrumb-item active">TOS / TQS Builder</li>
                                    </ol>
                                </div>
                                <h4 class="page-title">Table of Specifications (TOS) and Test Questionnaire (TQS)</h4>
                            </div>
                        </div>
                    </div>

                    <?php if ($flash !== ''): ?>
                        <div class="alert alert-<?php echo ttq_page_h($flashType); ?>"><?php echo ttq_page_h($flash); ?></div>
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-xl-4">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <h4 class="header-title mb-0">Saved Documents</h4>
                                        <a class="btn btn-sm btn-outline-primary" href="teacher-tos-tqs.php<?php echo $selectedClassId > 0 ? ('?class_record_id=' . (int) $selectedClassId) : ''; ?>">New</a>
                                    </div>
                                    <form method="get" class="mb-2">
                                        <label class="form-label mb-1">Class Filter</label>
                                        <select class="form-select form-select-sm" name="class_record_id">
                                            <option value="0">All My Classes</option>
                                            <?php foreach ($classOptions as $co): ?>
                                                <?php $cid = (int) ($co['class_record_id'] ?? 0); ?>
                                                <option value="<?php echo $cid; ?>" <?php echo $cid === $selectedClassId ? 'selected' : ''; ?>>
                                                    <?php echo ttq_page_h((string) ($classLabels[$cid] ?? ('Class #' . $cid))); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn-sm btn-primary mt-2 w-100">Apply</button>
                                    </form>
                                    <div class="table-responsive ttq-scroll-wrap">
                                        <table class="table table-sm align-middle mb-0">
                                            <tbody>
                                                <?php if (count($documents) === 0): ?>
                                                    <tr><td class="text-muted text-center py-3">No TOS/TQS documents yet.</td></tr>
                                                <?php endif; ?>
                                                <?php foreach ($documents as $doc): ?>
                                                    <?php
                                                    $rid = (int) ($doc['id'] ?? 0);
                                                    $isActive = $rid === $docId;
                                                    $classId = (int) ($doc['class_record_id'] ?? 0);
                                                    $classLabel = $classId > 0 && isset($classLabels[$classId]) ? $classLabels[$classId] : 'Reusable / Unbound';
                                                    ?>
                                                    <tr class="ttq-doc-list-row<?php echo $isActive ? ' active' : ''; ?>">
                                                        <td>
                                                            <div class="fw-semibold"><?php echo ttq_page_h((string) ($doc['title'] ?? 'Untitled')); ?></div>
                                                            <div class="small text-muted"><?php echo ttq_page_h($classLabel); ?></div>
                                                            <div class="d-flex gap-1 mt-1">
                                                                <span class="badge <?php echo ttq_page_h(ttq_doc_status_badge_class((string) ($doc['status'] ?? 'draft'))); ?>"><?php echo ttq_page_h(ttq_doc_status_label((string) ($doc['status'] ?? 'draft'))); ?></span>
                                                                <span class="badge bg-light text-dark border">v<?php echo (int) ($doc['version_no'] ?? 1); ?></span>
                                                            </div>
                                                            <a class="btn btn-sm btn-outline-primary mt-2" href="teacher-tos-tqs.php?<?php echo http_build_query(['class_record_id' => $classId, 'document_id' => $rid]); ?>">Open</a>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-8">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                                        <div>
                                            <h4 class="header-title mb-1">Builder</h4>
                                            <div class="text-muted small">Flexible and reusable structured form for TOS and TQS.</div>
                                        </div>
                                        <div>
                                            <span class="badge <?php echo ttq_page_h($statusBadgeClass); ?>"><?php echo ttq_page_h($statusLabel); ?></span>
                                            <span class="badge bg-light text-dark border">v<?php echo (int) ($currentDoc['version_no'] ?? 1); ?></span>
                                        </div>
                                    </div>

                                    <?php if ($docId > 0): ?>
                                        <div class="alert alert-light border mb-3">
                                            <div class="d-flex flex-wrap gap-1">
                                                <a class="btn btn-sm btn-outline-primary" href="teacher-tos-tqs-export.php?<?php echo http_build_query(['document_id' => $docId, 'format' => 'html', 'viewer' => 'teacher', 'csrf_token' => $exportCsrf]); ?>">Teacher HTML</a>
                                                <a class="btn btn-sm btn-outline-primary" href="teacher-tos-tqs-export.php?<?php echo http_build_query(['document_id' => $docId, 'format' => 'pdf', 'viewer' => 'teacher', 'csrf_token' => $exportCsrf]); ?>">Teacher PDF</a>
                                                <a class="btn btn-sm btn-outline-primary" href="teacher-tos-tqs-export.php?<?php echo http_build_query(['document_id' => $docId, 'format' => 'docx', 'viewer' => 'teacher', 'csrf_token' => $exportCsrf]); ?>">Teacher DOCX</a>
                                                <a class="btn btn-sm btn-outline-secondary" href="teacher-tos-tqs-export.php?<?php echo http_build_query(['document_id' => $docId, 'format' => 'html', 'viewer' => 'student', 'csrf_token' => $exportCsrf]); ?>">Student HTML</a>
                                                <a class="btn btn-sm btn-outline-secondary" href="teacher-tos-tqs-export.php?<?php echo http_build_query(['document_id' => $docId, 'format' => 'pdf', 'viewer' => 'student', 'csrf_token' => $exportCsrf]); ?>">Student PDF</a>
                                                <a class="btn btn-sm btn-outline-secondary" href="teacher-tos-tqs-export.php?<?php echo http_build_query(['document_id' => $docId, 'format' => 'docx', 'viewer' => 'student', 'csrf_token' => $exportCsrf]); ?>">Student DOCX</a>
                                            </div>
                                            <div class="small mt-2">
                                                <span class="badge <?php echo $isExamFinished ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning'; ?>">Exam <?php echo $isExamFinished ? 'Finished' : 'In Progress'; ?></span>
                                                <span class="badge <?php echo $studentAnswerVisible ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary'; ?>">Student Answer Key: <?php echo $studentAnswerVisible ? 'Visible' : 'Hidden'; ?></span>
                                            </div>
                                        </div>

                                        <div class="d-flex flex-wrap gap-2 mb-3">
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?php echo ttq_page_h(csrf_token()); ?>">
                                                <input type="hidden" name="action" value="mark_exam_finished">
                                                <input type="hidden" name="document_id" value="<?php echo $docId; ?>">
                                                <input type="hidden" name="class_record_id" value="<?php echo (int) $effectiveClassId; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-success" <?php echo $isExamFinished ? 'disabled' : ''; ?>>Mark Exam Finished</button>
                                            </form>
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?php echo ttq_page_h(csrf_token()); ?>">
                                                <input type="hidden" name="action" value="toggle_answer_release">
                                                <input type="hidden" name="document_id" value="<?php echo $docId; ?>">
                                                <input type="hidden" name="class_record_id" value="<?php echo (int) $effectiveClassId; ?>">
                                                <input type="hidden" name="answer_release_enabled" value="<?php echo (int) $releaseToggleTarget; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-info"><?php echo ttq_page_h($releaseToggleLabel); ?></button>
                                            </form>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($status === 'approved'): ?>
                                        <div class="alert alert-warning py-2">This document is approved. Editing and saving will set it to <strong>Pending Re-Approval</strong>.</div>
                                    <?php endif; ?>

                                    <form method="post" enctype="multipart/form-data" id="ttqMainForm">
                                        <input type="hidden" name="csrf_token" value="<?php echo ttq_page_h(csrf_token()); ?>">
                                        <input type="hidden" name="document_id" value="<?php echo $docId; ?>">
                                        <input type="hidden" id="ttqRecommendTrackIndex" name="recommend_track_index" value="-1">
                                        <input type="hidden" name="prepared_signature_current" value="<?php echo ttq_page_h((string) ($currentDoc['prepared_signature_path'] ?? '')); ?>">
                                        <input type="hidden" name="approved_signature_current" value="<?php echo ttq_page_h((string) ($currentDoc['approved_signature_path'] ?? '')); ?>">

                                        <div class="row g-2 mb-2">
                                            <div class="col-md-6">
                                                <label class="form-label">Class (optional)</label>
                                                <select class="form-select" name="class_record_id">
                                                    <option value="0">Not bound to a class</option>
                                                    <?php foreach ($classOptions as $co): ?>
                                                        <?php $cid = (int) ($co['class_record_id'] ?? 0); ?>
                                                        <option value="<?php echo $cid; ?>" <?php echo $cid === $effectiveClassId ? 'selected' : ''; ?>><?php echo ttq_page_h((string) ($classLabels[$cid] ?? ('Class #' . $cid))); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">Term</label>
                                                <select class="form-select" name="term">
                                                    <option value="midterm" <?php echo $term === 'midterm' ? 'selected' : ''; ?>>Midterm</option>
                                                    <option value="final" <?php echo $term === 'final' ? 'selected' : ''; ?>>Final</option>
                                                    <option value="custom" <?php echo $term === 'custom' ? 'selected' : ''; ?>>Custom</option>
                                                </select>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">Mode</label>
                                                <select class="form-select" name="document_mode" id="ttqDocumentMode">
                                                    <option value="standalone" <?php echo $mode === 'standalone' ? 'selected' : ''; ?>>Stand-Alone</option>
                                                    <option value="combined" <?php echo $mode === 'combined' ? 'selected' : ''; ?>>Combined</option>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="row g-2 mb-2">
                                            <div class="col-md-7"><label class="form-label">Title</label><input class="form-control" name="title" maxlength="180" value="<?php echo ttq_page_h((string) ($currentDoc['title'] ?? '')); ?>" required></div>
                                            <div class="col-md-3"><label class="form-label">Academic Year</label><input class="form-control" name="academic_year" maxlength="40" value="<?php echo ttq_page_h((string) ($currentDoc['academic_year'] ?? '')); ?>"></div>
                                            <div class="col-md-2"><label class="form-label">Semester</label><input class="form-control" name="semester" maxlength="40" value="<?php echo ttq_page_h((string) ($currentDoc['semester'] ?? '')); ?>"></div>
                                        </div>
                                        <div class="row g-2 mb-2">
                                            <div class="col-md-7"><label class="form-label">School Name</label><input class="form-control" name="school_name" maxlength="220" value="<?php echo ttq_page_h((string) ($content['school_name'] ?? '')); ?>"></div>
                                            <div class="col-md-5"><label class="form-label">Document Label</label><input class="form-control" name="document_label" maxlength="180" value="<?php echo ttq_page_h((string) ($content['document_label'] ?? '')); ?>"></div>
                                        </div>
                                        <div class="row g-2 mb-3">
                                            <div class="col-md-4"><label class="form-label">Exam End</label><input type="datetime-local" class="form-control" name="exam_end_at" value="<?php echo ttq_page_h(ttq_format_dt_input((string) ($currentDoc['exam_end_at'] ?? ''))); ?>"></div>
                                            <div class="col-md-4"><label class="form-label">Prepared By</label><input class="form-control" name="prepared_by_name" maxlength="120" value="<?php echo ttq_page_h((string) ($currentDoc['prepared_by_name'] ?? $teacherDisplayName)); ?>"></div>
                                            <div class="col-md-4"><label class="form-label">Prepared Signature</label><input type="file" class="form-control" name="prepared_signature" accept="image/png,image/jpeg,image/webp"></div>
                                        </div>
                                        <?php $prepPath = ttq_safe_file_relative_path((string) ($currentDoc['prepared_signature_path'] ?? '')); ?>
                                        <?php if ($prepPath !== ''): ?><div class="mb-2"><img class="ttq-sign-preview" src="<?php echo ttq_page_h($prepPath); ?>" alt="Prepared signature"></div><?php endif; ?>
                                        <div class="row g-2 mb-3">
                                            <div class="col-md-4"><label class="form-label">Approved By</label><input class="form-control" name="approved_by_name" maxlength="120" value="<?php echo ttq_page_h((string) ($currentDoc['approved_by_name'] ?? '')); ?>"></div>
                                            <div class="col-md-4"><label class="form-label">Approval Signature</label><input type="file" class="form-control" name="approved_signature" accept="image/png,image/jpeg,image/webp"></div>
                                            <div class="col-md-4"><label class="form-label">Approval Note</label><input class="form-control" name="approval_note" maxlength="255" value="<?php echo ttq_page_h((string) ($currentDoc['approval_note'] ?? '')); ?>"></div>
                                        </div>
                                        <?php $apprPath = ttq_safe_file_relative_path((string) ($currentDoc['approved_signature_path'] ?? '')); ?>
                                        <?php if ($apprPath !== ''): ?><div class="mb-2"><img class="ttq-sign-preview" src="<?php echo ttq_page_h($apprPath); ?>" alt="Approval signature"></div><?php endif; ?>

                                        <div class="form-check mb-3">
                                            <input class="form-check-input" type="checkbox" id="ttqReleaseOnSave" name="student_answer_key_release" value="1" <?php echo !empty($currentDoc['student_answer_key_release']) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="ttqReleaseOnSave">Request answer key visibility for student copy (applies only when exam is finished).</label>
                                        </div>

                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <h5 class="mb-0">Tracks</h5>
                                            <button type="button" id="ttqAddTrackBtn" class="btn btn-sm btn-outline-primary">Add Track</button>
                                        </div>

                                        <div id="ttqTracks">
                                            <?php foreach ($tracks as $ti => $track): ?>
                                                <?php
                                                $ti = (int) $ti;
                                                $track = ttq_normalize_track((array) $track, $ti);
                                                $questions = isset($track['questions']) && is_array($track['questions']) ? $track['questions'] : [ttq_default_question()];
                                                $tosRows = isset($track['tos_rows']) && is_array($track['tos_rows']) ? $track['tos_rows'] : [ttq_default_tos_row()];
                                                ?>
                                                <div class="ttq-track-card js-track-card" data-track-index="<?php echo $ti; ?>">
                                                    <div class="ttq-track-header d-flex justify-content-between align-items-center">
                                                        <strong>Track #<?php echo $ti + 1; ?></strong>
                                                        <button type="button" class="btn btn-sm btn-outline-danger js-remove-track">Remove Track</button>
                                                    </div>
                                                    <div class="ttq-track-body">
                                                        <input type="hidden" name="tracks[<?php echo $ti; ?>][id]" value="<?php echo ttq_page_h((string) ($track['id'] ?? '')); ?>">
                                                        <div class="row g-2 mb-2">
                                                            <div class="col-md-3"><label class="form-label">Subject Code</label><input class="form-control form-control-sm" name="tracks[<?php echo $ti; ?>][subject_code]" value="<?php echo ttq_page_h((string) ($track['subject_code'] ?? '')); ?>"></div>
                                                            <div class="col-md-5"><label class="form-label">Subject Name</label><input class="form-control form-control-sm" name="tracks[<?php echo $ti; ?>][subject_name]" value="<?php echo ttq_page_h((string) ($track['subject_name'] ?? '')); ?>"></div>
                                                            <div class="col-md-2"><label class="form-label">Track Label</label><input class="form-control form-control-sm" name="tracks[<?php echo $ti; ?>][track_label]" value="<?php echo ttq_page_h((string) ($track['track_label'] ?? '')); ?>"></div>
                                                            <div class="col-md-2"><label class="form-label">Exam Type</label>
                                                                <select class="form-select form-select-sm" name="tracks[<?php echo $ti; ?>][exam_type]">
                                                                    <option value="written" <?php echo ((string) ($track['exam_type'] ?? 'written')) === 'written' ? 'selected' : ''; ?>>Written</option>
                                                                    <option value="practical" <?php echo ((string) ($track['exam_type'] ?? 'written')) === 'practical' ? 'selected' : ''; ?>>Practical</option>
                                                                    <option value="mixed" <?php echo ((string) ($track['exam_type'] ?? 'written')) === 'mixed' ? 'selected' : ''; ?>>Mixed</option>
                                                                </select>
                                                            </div>
                                                        </div>
                                                        <div class="mb-2"><label class="form-label">General Instruction</label><textarea class="form-control form-control-sm" rows="2" name="tracks[<?php echo $ti; ?>][general_instruction]"><?php echo ttq_page_h((string) ($track['general_instruction'] ?? '')); ?></textarea></div>

                                                        <div class="ttq-subsection-title">Test Questionnaire</div>
                                                        <div class="table-responsive mb-2">
                                                            <table class="table table-sm table-bordered align-middle mb-0">
                                                                <thead class="table-light"><tr><th>Question</th><th style="width:85px;">Points</th><th style="width:130px;">Dimension</th><th>Answer Key</th><th style="width:60px;"></th></tr></thead>
                                                                <tbody class="js-question-rows" data-next-index="<?php echo count($questions); ?>">
                                                                    <?php foreach ($questions as $qi => $q): ?>
                                                                        <?php $q = ttq_normalize_question((array) $q); ?>
                                                                        <tr class="js-question-row">
                                                                            <td><textarea class="form-control form-control-sm" rows="2" name="tracks[<?php echo $ti; ?>][questions][<?php echo (int) $qi; ?>][question_text]"><?php echo ttq_page_h((string) $q['question_text']); ?></textarea></td>
                                                                            <td><input type="number" step="0.01" min="0" class="form-control form-control-sm" name="tracks[<?php echo $ti; ?>][questions][<?php echo (int) $qi; ?>][points]" value="<?php echo ttq_page_h((string) $q['points']); ?>"></td>
                                                                            <td><select class="form-select form-select-sm" name="tracks[<?php echo $ti; ?>][questions][<?php echo (int) $qi; ?>][cognitive_dimension]"><?php foreach ($dimensionKeys as $dk): ?><option value="<?php echo ttq_page_h($dk); ?>" <?php echo $dk === (string) ($q['cognitive_dimension'] ?? '') ? 'selected' : ''; ?>><?php echo ttq_page_h((string) ($dimensionLabels[$dk] ?? ucfirst($dk))); ?></option><?php endforeach; ?></select></td>
                                                                            <td><textarea class="form-control form-control-sm" rows="2" name="tracks[<?php echo $ti; ?>][questions][<?php echo (int) $qi; ?>][answer_key]"><?php echo ttq_page_h((string) $q['answer_key']); ?></textarea></td>
                                                                            <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger js-remove-row">&times;</button></td>
                                                                        </tr>
                                                                    <?php endforeach; ?>
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                        <button type="button" class="btn btn-sm btn-outline-primary js-add-question mb-3">Add Question</button>

                                                        <div class="ttq-subsection-title">Table of Specifications Allocation</div>
                                                        <div class="table-responsive mb-2">
                                                            <table class="table table-sm table-bordered align-middle mb-0">
                                                                <thead class="table-light"><tr><th>Topic</th><th style="width:85px;">Hours</th><th style="width:80px;">%</th><?php foreach ($dimensionKeys as $dk): ?><th style="width:82px;"><?php echo ttq_page_h((string) ($dimensionLabels[$dk] ?? ucfirst($dk))); ?></th><?php endforeach; ?><th style="width:55px;">AI</th><th style="width:60px;"></th></tr></thead>
                                                                <tbody class="js-tos-rows" data-next-index="<?php echo count($tosRows); ?>">
                                                                    <?php foreach ($tosRows as $ri => $r): ?>
                                                                        <?php $r = ttq_normalize_tos_row((array) $r); ?>
                                                                        <tr class="js-tos-row">
                                                                            <td><input class="form-control form-control-sm" name="tracks[<?php echo $ti; ?>][tos_rows][<?php echo (int) $ri; ?>][topic]" value="<?php echo ttq_page_h((string) $r['topic']); ?>"></td>
                                                                            <td><input class="form-control form-control-sm" name="tracks[<?php echo $ti; ?>][tos_rows][<?php echo (int) $ri; ?>][hours]" value="<?php echo ttq_page_h((string) $r['hours']); ?>"></td>
                                                                            <td><input type="number" step="0.01" min="0" class="form-control form-control-sm" name="tracks[<?php echo $ti; ?>][tos_rows][<?php echo (int) $ri; ?>][percentage]" value="<?php echo ttq_page_h((string) $r['percentage']); ?>"></td>
                                                                            <?php foreach ($dimensionKeys as $dk): ?><td><input type="number" step="0.01" min="0" class="form-control form-control-sm" name="tracks[<?php echo $ti; ?>][tos_rows][<?php echo (int) $ri; ?>][<?php echo ttq_page_h($dk); ?>]" value="<?php echo ttq_page_h((string) $r[$dk]); ?>"></td><?php endforeach; ?>
                                                                            <td class="text-center"><input class="form-check-input mt-0" type="checkbox" name="tracks[<?php echo $ti; ?>][tos_rows][<?php echo (int) $ri; ?>][is_recommended]" value="1" <?php echo !empty($r['is_recommended']) ? 'checked' : ''; ?>></td>
                                                                            <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger js-remove-row">&times;</button></td>
                                                                        </tr>
                                                                    <?php endforeach; ?>
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                        <button type="button" class="btn btn-sm btn-outline-primary js-add-tos-row mb-2">Add TOS Row</button>
                                                        <div class="mb-2"><label class="form-label">Track Notes</label><textarea class="form-control form-control-sm" rows="2" name="tracks[<?php echo $ti; ?>][notes]"><?php echo ttq_page_h((string) ($track['notes'] ?? '')); ?></textarea></div>
                                                        <?php
                                                        $rubricLevels = isset($track['rubric_levels']) && is_array($track['rubric_levels']) ? $track['rubric_levels'] : [];
                                                        $rubricCriteria = isset($track['rubric_criteria']) && is_array($track['rubric_criteria']) ? $track['rubric_criteria'] : [];
                                                        ?>
                                                        <div class="d-none">
                                                            <?php foreach ($rubricLevels as $li => $lv): ?>
                                                                <?php $lv = ttq_normalize_rubric_level((array) $lv); ?>
                                                                <input type="hidden" name="tracks[<?php echo $ti; ?>][rubric_levels][<?php echo (int) $li; ?>][score]" value="<?php echo ttq_page_h((string) $lv['score']); ?>">
                                                                <input type="hidden" name="tracks[<?php echo $ti; ?>][rubric_levels][<?php echo (int) $li; ?>][label]" value="<?php echo ttq_page_h((string) $lv['label']); ?>">
                                                                <input type="hidden" name="tracks[<?php echo $ti; ?>][rubric_levels][<?php echo (int) $li; ?>][description]" value="<?php echo ttq_page_h((string) $lv['description']); ?>">
                                                                <input type="hidden" name="tracks[<?php echo $ti; ?>][rubric_levels][<?php echo (int) $li; ?>][criteria]" value="<?php echo ttq_page_h((string) $lv['criteria']); ?>">
                                                            <?php endforeach; ?>
                                                            <?php foreach ($rubricCriteria as $ci => $rc): ?>
                                                                <?php $rc = ttq_normalize_rubric_criterion((array) $rc); ?>
                                                                <input type="hidden" name="tracks[<?php echo $ti; ?>][rubric_criteria][<?php echo (int) $ci; ?>][criterion]" value="<?php echo ttq_page_h((string) $rc['criterion']); ?>">
                                                                <input type="hidden" name="tracks[<?php echo $ti; ?>][rubric_criteria][<?php echo (int) $ci; ?>][excellent_points]" value="<?php echo ttq_page_h((string) $rc['excellent_points']); ?>">
                                                                <input type="hidden" name="tracks[<?php echo $ti; ?>][rubric_criteria][<?php echo (int) $ci; ?>][good_points]" value="<?php echo ttq_page_h((string) $rc['good_points']); ?>">
                                                                <input type="hidden" name="tracks[<?php echo $ti; ?>][rubric_criteria][<?php echo (int) $ci; ?>][fair_points]" value="<?php echo ttq_page_h((string) $rc['fair_points']); ?>">
                                                                <input type="hidden" name="tracks[<?php echo $ti; ?>][rubric_criteria][<?php echo (int) $ci; ?>][needs_points]" value="<?php echo ttq_page_h((string) $rc['needs_points']); ?>">
                                                                <input type="hidden" name="tracks[<?php echo $ti; ?>][rubric_criteria][<?php echo (int) $ci; ?>][notes]" value="<?php echo ttq_page_h((string) $rc['notes']); ?>">
                                                            <?php endforeach; ?>
                                                        </div>
                                                        <button type="submit" name="action" value="recommend_track_ai" class="btn btn-sm btn-outline-info js-recommend-track" data-track-index="<?php echo $ti; ?>">AI Recommend for This Track</button>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>

                                        <div class="d-flex gap-2 mt-2">
                                            <button type="submit" name="action" value="save_document" class="btn btn-primary">Save Draft</button>
                                            <button type="submit" name="action" value="approve_document" class="btn btn-success">Save and Approve</button>
                                            <?php if ($docId > 0): ?><button type="submit" name="action" value="delete_document" class="btn btn-outline-danger" onclick="return confirm('Delete this document?');">Delete</button><?php endif; ?>
                                        </div>
                                    </form>

                                    <?php if ($docId > 0): ?>
                                        <hr>
                                        <h5 class="mb-2">Feed to Class Record / Assessment</h5>
                                        <form method="post" class="row g-2 align-items-end">
                                            <input type="hidden" name="csrf_token" value="<?php echo ttq_page_h(csrf_token()); ?>">
                                            <input type="hidden" name="action" value="create_assessment">
                                            <input type="hidden" name="document_id" value="<?php echo $docId; ?>">
                                            <input type="hidden" name="class_record_id" value="<?php echo (int) $effectiveClassId; ?>">
                                            <div class="col-md-5">
                                                <label class="form-label">Target Component</label>
                                                <select class="form-select" name="component_id" required>
                                                    <option value="">Select...</option>
                                                    <?php foreach ($componentOptions as $co): ?>
                                                        <?php $componentId = (int) ($co['component_id'] ?? 0); ?>
                                                        <option value="<?php echo $componentId; ?>"><?php echo ttq_page_h((string) ($co['component_name'] ?? 'Component') . ' | ' . ttq_page_class_label($co)); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Track Scope</label>
                                                <select class="form-select" name="track_index">
                                                    <option value="-1">All Tracks</option>
                                                    <?php foreach ($tracks as $ti => $track): ?><option value="<?php echo (int) $ti; ?>"><?php echo ttq_page_h(ttq_track_display_title((array) $track, (int) $ti)); ?></option><?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-3"><button type="submit" class="btn btn-outline-primary w-100" <?php echo count($componentOptions) === 0 ? 'disabled' : ''; ?>>Create Assessment</button></div>
                                        </form>
                                        <?php if (count($componentOptions) === 0): ?><div class="small text-muted mt-2">No active grading components found for this class/term.</div><?php endif; ?>
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

    <template id="ttqTrackTemplate">
        <div class="ttq-track-card js-track-card" data-track-index="__TRACK__">
            <div class="ttq-track-header d-flex justify-content-between align-items-center">
                <strong>Track</strong>
                <button type="button" class="btn btn-sm btn-outline-danger js-remove-track">Remove Track</button>
            </div>
            <div class="ttq-track-body">
                <input type="hidden" name="tracks[__TRACK__][id]" value="">
                <div class="row g-2 mb-2">
                    <div class="col-md-3"><label class="form-label">Subject Code</label><input class="form-control form-control-sm" name="tracks[__TRACK__][subject_code]"></div>
                    <div class="col-md-5"><label class="form-label">Subject Name</label><input class="form-control form-control-sm" name="tracks[__TRACK__][subject_name]"></div>
                    <div class="col-md-2"><label class="form-label">Track Label</label><input class="form-control form-control-sm" name="tracks[__TRACK__][track_label]"></div>
                    <div class="col-md-2"><label class="form-label">Exam Type</label><select class="form-select form-select-sm" name="tracks[__TRACK__][exam_type]"><option value="written" selected>Written</option><option value="practical">Practical</option><option value="mixed">Mixed</option></select></div>
                </div>
                <div class="mb-2"><label class="form-label">General Instruction</label><textarea class="form-control form-control-sm" rows="2" name="tracks[__TRACK__][general_instruction]"></textarea></div>
                <div class="ttq-subsection-title">Test Questionnaire</div>
                <div class="table-responsive mb-2">
                    <table class="table table-sm table-bordered align-middle mb-0">
                        <thead class="table-light"><tr><th>Question</th><th style="width:85px;">Points</th><th style="width:130px;">Dimension</th><th>Answer Key</th><th style="width:60px;"></th></tr></thead>
                        <tbody class="js-question-rows" data-next-index="1">
                            <tr class="js-question-row">
                                <td><textarea class="form-control form-control-sm" rows="2" name="tracks[__TRACK__][questions][0][question_text]"></textarea></td>
                                <td><input type="number" step="0.01" min="0" class="form-control form-control-sm" name="tracks[__TRACK__][questions][0][points]" value="1"></td>
                                <td><select class="form-select form-select-sm" name="tracks[__TRACK__][questions][0][cognitive_dimension]"><?php foreach ($dimensionKeys as $dk): ?><option value="<?php echo ttq_page_h($dk); ?>"><?php echo ttq_page_h((string) ($dimensionLabels[$dk] ?? ucfirst($dk))); ?></option><?php endforeach; ?></select></td>
                                <td><textarea class="form-control form-control-sm" rows="2" name="tracks[__TRACK__][questions][0][answer_key]"></textarea></td>
                                <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger js-remove-row">&times;</button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary js-add-question mb-3">Add Question</button>
                <div class="ttq-subsection-title">Table of Specifications Allocation</div>
                <div class="table-responsive mb-2">
                    <table class="table table-sm table-bordered align-middle mb-0">
                        <thead class="table-light"><tr><th>Topic</th><th style="width:85px;">Hours</th><th style="width:80px;">%</th><?php foreach ($dimensionKeys as $dk): ?><th style="width:82px;"><?php echo ttq_page_h((string) ($dimensionLabels[$dk] ?? ucfirst($dk))); ?></th><?php endforeach; ?><th style="width:55px;">AI</th><th style="width:60px;"></th></tr></thead>
                        <tbody class="js-tos-rows" data-next-index="1">
                            <tr class="js-tos-row">
                                <td><input class="form-control form-control-sm" name="tracks[__TRACK__][tos_rows][0][topic]"></td>
                                <td><input class="form-control form-control-sm" name="tracks[__TRACK__][tos_rows][0][hours]"></td>
                                <td><input type="number" step="0.01" min="0" class="form-control form-control-sm" name="tracks[__TRACK__][tos_rows][0][percentage]" value="0"></td>
                                <?php foreach ($dimensionKeys as $dk): ?><td><input type="number" step="0.01" min="0" class="form-control form-control-sm" name="tracks[__TRACK__][tos_rows][0][<?php echo ttq_page_h($dk); ?>]" value="0"></td><?php endforeach; ?>
                                <td class="text-center"><input class="form-check-input mt-0" type="checkbox" name="tracks[__TRACK__][tos_rows][0][is_recommended]" value="1"></td>
                                <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger js-remove-row">&times;</button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary js-add-tos-row mb-2">Add TOS Row</button>
                <div class="mb-2"><label class="form-label">Track Notes</label><textarea class="form-control form-control-sm" rows="2" name="tracks[__TRACK__][notes]"></textarea></div>
                <div class="d-none">
                    <input type="hidden" name="tracks[__TRACK__][rubric_levels][0][score]" value="1.00">
                    <input type="hidden" name="tracks[__TRACK__][rubric_levels][0][label]" value="Correct">
                    <input type="hidden" name="tracks[__TRACK__][rubric_levels][0][description]" value="">
                    <input type="hidden" name="tracks[__TRACK__][rubric_levels][0][criteria]" value="">
                    <input type="hidden" name="tracks[__TRACK__][rubric_criteria][0][criterion]" value="">
                    <input type="hidden" name="tracks[__TRACK__][rubric_criteria][0][excellent_points]" value="0">
                    <input type="hidden" name="tracks[__TRACK__][rubric_criteria][0][good_points]" value="0">
                    <input type="hidden" name="tracks[__TRACK__][rubric_criteria][0][fair_points]" value="0">
                    <input type="hidden" name="tracks[__TRACK__][rubric_criteria][0][needs_points]" value="0">
                    <input type="hidden" name="tracks[__TRACK__][rubric_criteria][0][notes]" value="">
                </div>
                <button type="submit" name="action" value="recommend_track_ai" class="btn btn-sm btn-outline-info js-recommend-track" data-track-index="__TRACK__">AI Recommend for This Track</button>
            </div>
        </div>
    </template>

    <template id="ttqQuestionRowTemplate">
        <tr class="js-question-row">
            <td><textarea class="form-control form-control-sm" rows="2" name="tracks[__TRACK__][questions][__ROW__][question_text]"></textarea></td>
            <td><input type="number" step="0.01" min="0" class="form-control form-control-sm" name="tracks[__TRACK__][questions][__ROW__][points]" value="1"></td>
            <td><select class="form-select form-select-sm" name="tracks[__TRACK__][questions][__ROW__][cognitive_dimension]"><?php foreach ($dimensionKeys as $dk): ?><option value="<?php echo ttq_page_h($dk); ?>"><?php echo ttq_page_h((string) ($dimensionLabels[$dk] ?? ucfirst($dk))); ?></option><?php endforeach; ?></select></td>
            <td><textarea class="form-control form-control-sm" rows="2" name="tracks[__TRACK__][questions][__ROW__][answer_key]"></textarea></td>
            <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger js-remove-row">&times;</button></td>
        </tr>
    </template>

    <template id="ttqTosRowTemplate">
        <tr class="js-tos-row">
            <td><input class="form-control form-control-sm" name="tracks[__TRACK__][tos_rows][__ROW__][topic]"></td>
            <td><input class="form-control form-control-sm" name="tracks[__TRACK__][tos_rows][__ROW__][hours]"></td>
            <td><input type="number" step="0.01" min="0" class="form-control form-control-sm" name="tracks[__TRACK__][tos_rows][__ROW__][percentage]" value="0"></td>
            <?php foreach ($dimensionKeys as $dk): ?><td><input type="number" step="0.01" min="0" class="form-control form-control-sm" name="tracks[__TRACK__][tos_rows][__ROW__][<?php echo ttq_page_h($dk); ?>]" value="0"></td><?php endforeach; ?>
            <td class="text-center"><input class="form-check-input mt-0" type="checkbox" name="tracks[__TRACK__][tos_rows][__ROW__][is_recommended]" value="1"></td>
            <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger js-remove-row">&times;</button></td>
        </tr>
    </template>

    <script>
        (function () {
            var tracksWrap = document.getElementById('ttqTracks');
            var addTrackBtn = document.getElementById('ttqAddTrackBtn');
            var modeSelect = document.getElementById('ttqDocumentMode');
            var recommendTrackInput = document.getElementById('ttqRecommendTrackIndex');
            if (!tracksWrap || !addTrackBtn || !modeSelect) return;

            function fillTemplate(html, map) {
                var out = html;
                Object.keys(map).forEach(function (k) { out = out.split(k).join(String(map[k])); });
                return out;
            }

            var nextTrackIndex = (function () {
                var max = -1;
                tracksWrap.querySelectorAll('.js-track-card').forEach(function (card) {
                    var idx = parseInt(card.getAttribute('data-track-index') || '-1', 10);
                    if (idx > max) max = idx;
                });
                return max + 1;
            })();

            function trackCount() { return tracksWrap.querySelectorAll('.js-track-card').length; }
            function syncModeState() {
                var isCombined = modeSelect.value === 'combined';
                addTrackBtn.disabled = !isCombined;
                tracksWrap.querySelectorAll('.js-remove-track').forEach(function (btn) {
                    btn.disabled = (!isCombined) || trackCount() <= 1;
                });
            }
            syncModeState();
            modeSelect.addEventListener('change', syncModeState);

            addTrackBtn.addEventListener('click', function () {
                if (modeSelect.value !== 'combined') return;
                var tpl = document.getElementById('ttqTrackTemplate');
                if (!tpl) return;
                var html = fillTemplate(tpl.innerHTML, { '__TRACK__': nextTrackIndex });
                tracksWrap.insertAdjacentHTML('beforeend', html);
                nextTrackIndex += 1;
                syncModeState();
            });

            function appendRow(trackCard, bodySelector, templateId) {
                var body = trackCard.querySelector(bodySelector);
                if (!body) return;
                var trackIndex = parseInt(trackCard.getAttribute('data-track-index') || '-1', 10);
                if (trackIndex < 0) return;
                var next = parseInt(body.getAttribute('data-next-index') || '0', 10);
                var tpl = document.getElementById(templateId);
                if (!tpl) return;
                var html = fillTemplate(tpl.innerHTML, { '__TRACK__': trackIndex, '__ROW__': next });
                body.insertAdjacentHTML('beforeend', html);
                body.setAttribute('data-next-index', String(next + 1));
            }

            document.addEventListener('click', function (e) {
                var btn;
                btn = e.target.closest('.js-recommend-track');
                if (btn && recommendTrackInput) {
                    recommendTrackInput.value = btn.getAttribute('data-track-index') || '-1';
                    return;
                }

                btn = e.target.closest('.js-remove-track');
                if (btn) {
                    var card = btn.closest('.js-track-card');
                    if (!card) return;
                    if (trackCount() <= 1) { alert('At least one track is required.'); return; }
                    card.remove();
                    syncModeState();
                    return;
                }

                var card = e.target.closest('.js-track-card');
                if (!card) return;

                btn = e.target.closest('.js-add-question');
                if (btn) { appendRow(card, '.js-question-rows', 'ttqQuestionRowTemplate'); return; }
                btn = e.target.closest('.js-add-tos-row');
                if (btn) { appendRow(card, '.js-tos-rows', 'ttqTosRowTemplate'); return; }

                btn = e.target.closest('.js-remove-row');
                if (btn) {
                    var row = btn.closest('tr');
                    var body = btn.closest('tbody');
                    if (!row || !body) return;
                    if (body.querySelectorAll('tr').length <= 1) { alert('At least one row is required.'); return; }
                    row.remove();
                }
            });

            var mainForm = document.getElementById('ttqMainForm');
            if (mainForm && recommendTrackInput) {
                mainForm.addEventListener('click', function (e) {
                    var actionButton = e.target.closest('button[name="action"]');
                    if (!actionButton) return;
                    if (actionButton.value !== 'recommend_track_ai') recommendTrackInput.value = '-1';
                });
            }
        })();
    </script>
</body>

</html>
