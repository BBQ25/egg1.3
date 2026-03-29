<?php include '../layouts/session.php'; ?>
<?php require_active_role('teacher'); ?>
<?php include '../layouts/main.php'; ?>

<?php
require_once __DIR__ . '/../includes/grading.php';
require_once __DIR__ . '/../includes/section_sync.php';
require_once __DIR__ . '/../includes/teacher_activity_events.php';
ensure_grading_tables($conn);
teacher_activity_ensure_tables($conn);

$flash = isset($_SESSION['flash_message']) ? (string) $_SESSION['flash_message'] : '';
$flashType = isset($_SESSION['flash_type']) ? (string) $_SESSION['flash_type'] : 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

$teacherId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;

$componentId = isset($_GET['grading_component_id']) ? (int) $_GET['grading_component_id'] : 0;
if ($componentId <= 0) {
    $_SESSION['flash_message'] = 'Invalid component.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: teacher-dashboard.php');
    exit;
}

// Authorize by walking: grading_component -> section_config -> class_record -> teacher_assignment
$ctx = null;
$stmt = $conn->prepare(
    "SELECT gc.id AS grading_component_id,
            gc.component_name, gc.component_code, gc.component_type, gc.weight,
            c.category_name,
            sgc.subject_id, sgc.term, sgc.section, sgc.academic_year, sgc.semester, sgc.course, sgc.year,
            cr.id AS class_record_id,
            s.subject_code, s.subject_name
     FROM grading_components gc
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
     WHERE gc.id = ?
     LIMIT 1"
);
if ($stmt) {
    $stmt->bind_param('ii', $teacherId, $componentId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows === 1) $ctx = $res->fetch_assoc();
    $stmt->close();
}
if (!$ctx) {
    deny_access(403, 'Forbidden: not assigned to this class component.');
}

$classRecordId = (int) ($ctx['class_record_id'] ?? 0);
$subjectId = (int) ($ctx['subject_id'] ?? 0);
$term = (string) ($ctx['term'] ?? 'midterm');
$termLabel = $term === 'final' ? 'Final Term' : 'Midterm';
$moduleCatalog = grading_module_catalog();
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

if (!function_exists('teacher_next_assessment_order')) {
    function teacher_next_assessment_order(mysqli $conn, $componentId) {
        $componentId = (int) $componentId;
        if ($componentId <= 0) return 1;

        $next = 1;
        $stmt = $conn->prepare(
            "SELECT COALESCE(MAX(display_order), 0) + 1 AS next_order
             FROM grading_assessments
             WHERE grading_component_id = ?"
        );
        if ($stmt) {
            $stmt->bind_param('i', $componentId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows === 1) {
                $next = (int) (($res->fetch_assoc()['next_order'] ?? 1));
            }
            $stmt->close();
        }

        return $next > 0 ? $next : 1;
    }
}

$nextDisplayOrder = teacher_next_assessment_order($conn, $componentId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    if (!csrf_validate($csrf)) {
        $_SESSION['flash_message'] = 'Security check failed (CSRF). Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: teacher-component-assessments.php?grading_component_id=' . $componentId);
        exit;
    }

    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

    if ($action === 'add_assessment') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $max = isset($_POST['max_score']) ? trim((string) $_POST['max_score']) : '';
        $date = isset($_POST['assessment_date']) ? trim((string) $_POST['assessment_date']) : '';
        $moduleType = grading_normalize_module_type((string) ($_POST['module_type'] ?? 'assessment'));
        $orderRaw = isset($_POST['display_order']) ? trim((string) $_POST['display_order']) : '';
        $order = $orderRaw === '' ? 0 : (int) $orderRaw;

        $maxNorm = str_replace(',', '.', $max);
        if ($name === '' || $maxNorm === '' || !preg_match('/^\\d+(?:\\.\\d+)?$/', $maxNorm)) {
            $_SESSION['flash_message'] = 'Assessment name and max score are required.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: teacher-component-assessments.php?grading_component_id=' . $componentId);
            exit;
        }

        $maxScore = clamp_decimal((float) $maxNorm, 0, 100000);
        $dateVal = ($date !== '') ? $date : null;
        if ($order <= 0) {
            $order = teacher_next_assessment_order($conn, $componentId);
        }

        $ins = $conn->prepare(
            "INSERT INTO grading_assessments (grading_component_id, name, max_score, assessment_date, module_type, is_active, display_order, created_by)
             VALUES (?, ?, ?, ?, ?, 1, ?, ?)"
        );
        if ($ins) {
            $ins->bind_param('isdssii', $componentId, $name, $maxScore, $dateVal, $moduleType, $order, $teacherId);
            $ok = $ins->execute();
            $newAssessmentId = $ok ? (int) $conn->insert_id : 0;
            $ins->close();
            if ($ok) {
                $evtDate = ($dateVal !== null && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', (string) $dateVal)) ? (string) $dateVal : date('Y-m-d');
                $evtTitle = ($moduleType === 'assignment' ? 'Assignment created: ' : 'Assessment created: ') . $name;
                if (strlen($evtTitle) > 255) $evtTitle = substr($evtTitle, 0, 255);
                $evtType = $moduleType === 'assignment' ? 'grading_assignment_created' : 'grading_assessment_created';
                $evtId = teacher_activity_create_event(
                    $conn,
                    $teacherId,
                    $classRecordId,
                    $evtType,
                    $evtDate,
                    $evtTitle,
                    [
                        'assessment_id' => $newAssessmentId,
                        'grading_component_id' => $componentId,
                        'module_type' => $moduleType,
                        'name' => $name,
                        'max_score' => (float) $maxScore,
                    ]
                );
                if ($evtId > 0 && function_exists('teacher_activity_queue_add')) {
                    teacher_activity_queue_add($evtId, $evtTitle, $evtDate);
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
                $syncSelectedIds = section_sync_resolve_target_ids(
                    $_POST['apply_section_ids'] ?? [],
                    $syncPeerSections,
                    $syncPreference,
                    true
                );
                $syncCopied = 0;
                $syncSkipped = 0;
                if (count($syncSelectedIds) > 0) {
                    $targetQ = $conn->prepare(
                        "SELECT cr.id, cr.section, cr.academic_year, cr.semester, cr.year_level
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
                    $cfgQ = $conn->prepare(
                        "SELECT id
                         FROM section_grading_configs
                         WHERE subject_id = ? AND course = ? AND year = ? AND section = ? AND academic_year = ? AND semester = ? AND term = ?
                         LIMIT 1"
                    );
                    $compByCodeQ = $conn->prepare(
                        "SELECT id FROM grading_components
                         WHERE section_config_id = ? AND component_code = ?
                         ORDER BY is_active DESC, id ASC
                         LIMIT 1"
                    );
                    $compByNameTypeQ = $conn->prepare(
                        "SELECT id FROM grading_components
                         WHERE section_config_id = ? AND component_name = ? AND component_type = ?
                         ORDER BY is_active DESC, id ASC
                         LIMIT 1"
                    );
                    $compByNameQ = $conn->prepare(
                        "SELECT id FROM grading_components
                         WHERE section_config_id = ? AND component_name = ?
                         ORDER BY is_active DESC, id ASC
                         LIMIT 1"
                    );
                    $insPeerAssessment = $conn->prepare(
                        "INSERT INTO grading_assessments (grading_component_id, name, max_score, assessment_date, module_type, is_active, display_order, created_by)
                         VALUES (?, ?, ?, ?, ?, 1, ?, ?)"
                    );

                    if ($targetQ && $cfgQ && $compByCodeQ && $compByNameTypeQ && $compByNameQ && $insPeerAssessment) {
                        $sourceComponentName = trim((string) ($ctx['component_name'] ?? ''));
                        $sourceComponentCode = trim((string) ($ctx['component_code'] ?? ''));
                        $sourceComponentType = trim((string) ($ctx['component_type'] ?? 'other'));
                        $sourceCourse = trim((string) ($ctx['course'] ?? ''));
                        if ($sourceCourse === '') $sourceCourse = 'N/A';

                        foreach ($syncSelectedIds as $targetClassId) {
                            $targetClassId = (int) $targetClassId;
                            $syncAcademicYear = (string) ($ctx['academic_year'] ?? '');
                            $syncSemester = (string) ($ctx['semester'] ?? '');
                            $targetQ->bind_param('iissi', $teacherId, $subjectId, $syncAcademicYear, $syncSemester, $targetClassId);
                            $targetQ->execute();
                            $targetRes = $targetQ->get_result();
                            $targetRow = ($targetRes && $targetRes->num_rows === 1) ? $targetRes->fetch_assoc() : null;
                            if (!is_array($targetRow)) {
                                $syncSkipped++;
                                continue;
                            }

                            $targetSection = trim((string) ($targetRow['section'] ?? ''));
                            $targetAy = trim((string) ($targetRow['academic_year'] ?? (string) ($ctx['academic_year'] ?? '')));
                            $targetSem = trim((string) ($targetRow['semester'] ?? (string) ($ctx['semester'] ?? '')));
                            $targetYear = trim((string) ($targetRow['year_level'] ?? ''));
                            if ($targetYear === '') $targetYear = 'N/A';

                            $targetConfigId = 0;
                            $cfgQ->bind_param('issssss', $subjectId, $sourceCourse, $targetYear, $targetSection, $targetAy, $targetSem, $term);
                            $cfgQ->execute();
                            $cfgRes = $cfgQ->get_result();
                            if ($cfgRes && $cfgRes->num_rows === 1) {
                                $targetConfigId = (int) ($cfgRes->fetch_assoc()['id'] ?? 0);
                            }
                            if ($targetConfigId <= 0) {
                                $syncSkipped++;
                                continue;
                            }

                            $targetComponentId = 0;
                            if ($sourceComponentCode !== '') {
                                $compByCodeQ->bind_param('is', $targetConfigId, $sourceComponentCode);
                                $compByCodeQ->execute();
                                $resCode = $compByCodeQ->get_result();
                                if ($resCode && $resCode->num_rows === 1) $targetComponentId = (int) ($resCode->fetch_assoc()['id'] ?? 0);
                            }
                            if ($targetComponentId <= 0 && $sourceComponentName !== '') {
                                $compByNameTypeQ->bind_param('iss', $targetConfigId, $sourceComponentName, $sourceComponentType);
                                $compByNameTypeQ->execute();
                                $resNameType = $compByNameTypeQ->get_result();
                                if ($resNameType && $resNameType->num_rows === 1) $targetComponentId = (int) ($resNameType->fetch_assoc()['id'] ?? 0);
                            }
                            if ($targetComponentId <= 0 && $sourceComponentName !== '') {
                                $compByNameQ->bind_param('is', $targetConfigId, $sourceComponentName);
                                $compByNameQ->execute();
                                $resName = $compByNameQ->get_result();
                                if ($resName && $resName->num_rows === 1) $targetComponentId = (int) ($resName->fetch_assoc()['id'] ?? 0);
                            }
                            if ($targetComponentId <= 0) {
                                $syncSkipped++;
                                continue;
                            }

                            $targetOrder = teacher_next_assessment_order($conn, $targetComponentId);
                            $insPeerAssessment->bind_param('isdssii', $targetComponentId, $name, $maxScore, $dateVal, $moduleType, $targetOrder, $teacherId);
                            if ($insPeerAssessment->execute()) $syncCopied++;
                            else $syncSkipped++;
                        }

                        $targetQ->close();
                        $cfgQ->close();
                        $compByCodeQ->close();
                        $compByNameTypeQ->close();
                        $compByNameQ->close();
                        $insPeerAssessment->close();
                    } else {
                        $syncSkipped += count($syncSelectedIds);
                        if ($targetQ) $targetQ->close();
                        if ($cfgQ) $cfgQ->close();
                        if ($compByCodeQ) $compByCodeQ->close();
                        if ($compByNameTypeQ) $compByNameTypeQ->close();
                        if ($compByNameQ) $compByNameQ->close();
                        if ($insPeerAssessment) $insPeerAssessment->close();
                    }
                }

                $flashMsg = 'Assessment added.';
                if ($syncCopied > 0) $flashMsg .= ' Applied to ' . $syncCopied . ' other section(s).';
                if ($syncSkipped > 0) $flashMsg .= ' Skipped ' . $syncSkipped . ' section(s).';
                $_SESSION['flash_message'] = $flashMsg;
                $_SESSION['flash_type'] = 'success';
            } else {
                $_SESSION['flash_message'] = 'Add failed.';
                $_SESSION['flash_type'] = 'danger';
            }
        } else {
            $_SESSION['flash_message'] = 'Add failed.';
            $_SESSION['flash_type'] = 'danger';
        }

        header('Location: teacher-component-assessments.php?grading_component_id=' . $componentId);
        exit;
    }

    if ($action === 'update_assessment') {
        $aid = isset($_POST['assessment_id']) ? (int) $_POST['assessment_id'] : 0;
        $name = trim((string) ($_POST['name'] ?? ''));
        $max = isset($_POST['max_score']) ? trim((string) $_POST['max_score']) : '';
        $date = isset($_POST['assessment_date']) ? trim((string) $_POST['assessment_date']) : '';
        $moduleType = grading_normalize_module_type((string) ($_POST['module_type'] ?? 'assessment'));
        $order = isset($_POST['display_order']) ? (int) $_POST['display_order'] : 0;
        $isActive = isset($_POST['is_active']) ? (int) $_POST['is_active'] : 0;

        $maxNorm = str_replace(',', '.', $max);
        if ($aid <= 0 || $name === '' || $maxNorm === '' || !preg_match('/^\\d+(?:\\.\\d+)?$/', $maxNorm)) {
            $_SESSION['flash_message'] = 'Invalid assessment update.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: teacher-component-assessments.php?grading_component_id=' . $componentId);
            exit;
        }

        $maxScore = clamp_decimal((float) $maxNorm, 0, 100000);
        $dateVal = ($date !== '') ? $date : null;
        $isActive = $isActive ? 1 : 0;

        $upd = $conn->prepare(
            "UPDATE grading_assessments
             SET name = ?, max_score = ?, assessment_date = ?, module_type = ?, display_order = ?, is_active = ?
             WHERE id = ? AND grading_component_id = ?"
        );
        if ($upd) {
            $upd->bind_param('sdssiiii', $name, $maxScore, $dateVal, $moduleType, $order, $isActive, $aid, $componentId);
            $ok = $upd->execute();
            $upd->close();
            if ($ok) {
                $syncCopied = 0;
                $syncSkipped = 0;
                $syncTargets = section_sync_resolve_target_ids([], $syncPeerSections, $syncPreference, true);
                if (count($syncTargets) > 0) {
                    $targetQ = $conn->prepare(
                        "SELECT cr.id, cr.section, cr.academic_year, cr.semester, cr.year_level
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
                    $cfgQ = $conn->prepare(
                        "SELECT id
                         FROM section_grading_configs
                         WHERE subject_id = ? AND course = ? AND year = ? AND section = ? AND academic_year = ? AND semester = ? AND term = ?
                         LIMIT 1"
                    );
                    $compByCodeQ = $conn->prepare(
                        "SELECT id FROM grading_components
                         WHERE section_config_id = ? AND component_code = ?
                         ORDER BY is_active DESC, id ASC
                         LIMIT 1"
                    );
                    $compByNameTypeQ = $conn->prepare(
                        "SELECT id FROM grading_components
                         WHERE section_config_id = ? AND component_name = ? AND component_type = ?
                         ORDER BY is_active DESC, id ASC
                         LIMIT 1"
                    );
                    $compByNameQ = $conn->prepare(
                        "SELECT id FROM grading_components
                         WHERE section_config_id = ? AND component_name = ?
                         ORDER BY is_active DESC, id ASC
                         LIMIT 1"
                    );
                    $updStatusQ = $conn->prepare(
                        "UPDATE grading_assessments
                         SET is_active = ?
                         WHERE grading_component_id = ?
                           AND name = ?"
                    );

                    if ($targetQ && $cfgQ && $compByCodeQ && $compByNameTypeQ && $compByNameQ && $updStatusQ) {
                        $sourceComponentName = trim((string) ($ctx['component_name'] ?? ''));
                        $sourceComponentCode = trim((string) ($ctx['component_code'] ?? ''));
                        $sourceComponentType = trim((string) ($ctx['component_type'] ?? 'other'));
                        $sourceCourse = trim((string) ($ctx['course'] ?? ''));
                        if ($sourceCourse === '') $sourceCourse = 'N/A';

                        foreach ($syncTargets as $targetClassId) {
                            $targetClassId = (int) $targetClassId;
                            $syncAy = (string) ($ctx['academic_year'] ?? '');
                            $syncSem = (string) ($ctx['semester'] ?? '');
                            $targetQ->bind_param('iissi', $teacherId, $subjectId, $syncAy, $syncSem, $targetClassId);
                            $targetQ->execute();
                            $targetRes = $targetQ->get_result();
                            $targetRow = ($targetRes && $targetRes->num_rows === 1) ? $targetRes->fetch_assoc() : null;
                            if (!is_array($targetRow)) {
                                $syncSkipped++;
                                continue;
                            }

                            $targetSection = trim((string) ($targetRow['section'] ?? ''));
                            $targetAy = trim((string) ($targetRow['academic_year'] ?? $syncAy));
                            $targetSem = trim((string) ($targetRow['semester'] ?? $syncSem));
                            $targetYear = trim((string) ($targetRow['year_level'] ?? ''));
                            if ($targetYear === '') $targetYear = 'N/A';

                            $targetConfigId = 0;
                            $cfgQ->bind_param('issssss', $subjectId, $sourceCourse, $targetYear, $targetSection, $targetAy, $targetSem, $term);
                            $cfgQ->execute();
                            $cfgRes = $cfgQ->get_result();
                            if ($cfgRes && $cfgRes->num_rows === 1) $targetConfigId = (int) ($cfgRes->fetch_assoc()['id'] ?? 0);
                            if ($targetConfigId <= 0) {
                                $syncSkipped++;
                                continue;
                            }

                            $targetComponentId = 0;
                            if ($sourceComponentCode !== '') {
                                $compByCodeQ->bind_param('is', $targetConfigId, $sourceComponentCode);
                                $compByCodeQ->execute();
                                $resCode = $compByCodeQ->get_result();
                                if ($resCode && $resCode->num_rows === 1) $targetComponentId = (int) ($resCode->fetch_assoc()['id'] ?? 0);
                            }
                            if ($targetComponentId <= 0 && $sourceComponentName !== '') {
                                $compByNameTypeQ->bind_param('iss', $targetConfigId, $sourceComponentName, $sourceComponentType);
                                $compByNameTypeQ->execute();
                                $resNameType = $compByNameTypeQ->get_result();
                                if ($resNameType && $resNameType->num_rows === 1) $targetComponentId = (int) ($resNameType->fetch_assoc()['id'] ?? 0);
                            }
                            if ($targetComponentId <= 0 && $sourceComponentName !== '') {
                                $compByNameQ->bind_param('is', $targetConfigId, $sourceComponentName);
                                $compByNameQ->execute();
                                $resName = $compByNameQ->get_result();
                                if ($resName && $resName->num_rows === 1) $targetComponentId = (int) ($resName->fetch_assoc()['id'] ?? 0);
                            }
                            if ($targetComponentId <= 0) {
                                $syncSkipped++;
                                continue;
                            }

                            $updStatusQ->bind_param('iis', $isActive, $targetComponentId, $name);
                            if ($updStatusQ->execute() && (int) $updStatusQ->affected_rows >= 0) $syncCopied++;
                            else $syncSkipped++;
                        }

                        $targetQ->close();
                        $cfgQ->close();
                        $compByCodeQ->close();
                        $compByNameTypeQ->close();
                        $compByNameQ->close();
                        $updStatusQ->close();
                    } else {
                        $syncSkipped += count($syncTargets);
                        if ($targetQ) $targetQ->close();
                        if ($cfgQ) $cfgQ->close();
                        if ($compByCodeQ) $compByCodeQ->close();
                        if ($compByNameTypeQ) $compByNameTypeQ->close();
                        if ($compByNameQ) $compByNameQ->close();
                        if ($updStatusQ) $updStatusQ->close();
                    }
                }

                $flashMsg = 'Assessment updated.';
                if ($syncCopied > 0) $flashMsg .= ' Status synced to ' . $syncCopied . ' section(s).';
                if ($syncSkipped > 0) $flashMsg .= ' Skipped ' . $syncSkipped . ' section(s).';
                $_SESSION['flash_message'] = $flashMsg;
                $_SESSION['flash_type'] = 'success';
            } else {
                $_SESSION['flash_message'] = 'Update failed.';
                $_SESSION['flash_type'] = 'danger';
            }
        } else {
            $_SESSION['flash_message'] = 'Update failed.';
            $_SESSION['flash_type'] = 'danger';
        }

        header('Location: teacher-component-assessments.php?grading_component_id=' . $componentId);
        exit;
    }

    if ($action === 'delete_assessment') {
        $aid = isset($_POST['assessment_id']) ? (int) $_POST['assessment_id'] : 0;
        if ($aid > 0) {
            $del = $conn->prepare("DELETE FROM grading_assessments WHERE id = ? AND grading_component_id = ? LIMIT 1");
            if ($del) {
                $del->bind_param('ii', $aid, $componentId);
                $del->execute();
                $del->close();
                $_SESSION['flash_message'] = 'Assessment deleted.';
                $_SESSION['flash_type'] = 'success';
            }
        }
        header('Location: teacher-component-assessments.php?grading_component_id=' . $componentId);
        exit;
    }

    $_SESSION['flash_message'] = 'Invalid request.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: teacher-component-assessments.php?grading_component_id=' . $componentId);
    exit;
}

$assessments = [];
$q = $conn->prepare(
    "SELECT id, name, max_score, assessment_date, module_type, is_active, display_order
     FROM grading_assessments
     WHERE grading_component_id = ?
     ORDER BY display_order ASC, id ASC"
);
if ($q) {
    $q->bind_param('i', $componentId);
    $q->execute();
    $res = $q->get_result();
    while ($res && ($r = $res->fetch_assoc())) $assessments[] = $r;
    $q->close();
}
?>

<head>
    <title>Assessments | E-Record</title>
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
                                        <li class="breadcrumb-item active">Assessments</li>
                                    </ol>
                                </div>
                                <h4 class="page-title">Assessments</h4>
                            </div>
                        </div>
                    </div>

                    <?php if ($flash): ?>
                        <div class="alert alert-<?php echo htmlspecialchars($flashType); ?>"><?php echo htmlspecialchars($flash); ?></div>
                    <?php endif; ?>

                    <div class="row g-3">
                        <div class="col-xl-4">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title">Add Assessment</h4>
                                    <p class="text-muted mb-3">Example: Quiz 1: The CRUD Operations</p>

                                    <form method="post" id="tcaAddAssessmentForm">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                        <input type="hidden" name="action" value="add_assessment">

                                        <div class="mb-2">
                                            <label class="form-label">Name</label>
                                            <input class="form-control" name="name" maxlength="120" required placeholder="e.g. Quiz 1: The CRUD Operations">
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label">Module Type</label>
                                            <select class="form-select" name="module_type">
                                                <?php foreach ($moduleCatalog as $moduleKey => $moduleInfo): ?>
                                                    <option value="<?php echo htmlspecialchars((string) $moduleKey); ?>" <?php echo $moduleKey === 'assessment' ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars((string) ($moduleInfo['label'] ?? $moduleKey)); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div class="form-text">Choose <strong>Assessment</strong> for a plain score item, or select a Moodle-style module profile.</div>
                                        </div>

                                        <div class="row g-2">
                                            <div class="col-6">
                                                <label class="form-label">Max Score</label>
                                                <input class="form-control" name="max_score" type="number" min="0" step="0.01" inputmode="decimal" required placeholder="e.g. 20">
                                            </div>
                                            <div class="col-6">
                                                <label class="form-label">Order</label>
                                                <input class="form-control" name="display_order" type="number" min="1" step="1" value="<?php echo (int) $nextDisplayOrder; ?>">
                                            </div>
                                        </div>

                                        <div class="mt-2">
                                            <label class="form-label">Date (optional)</label>
                                            <input class="form-control" type="date" name="assessment_date">
                                        </div>

                                        <?php if (count($syncPeerSections) > 0): ?>
                                            <div class="mt-2 border rounded p-2">
                                                <div class="fw-semibold small">Apply To Other Sections</div>
                                                <div class="text-muted small mb-1">Same subject and term, if matching component exists.</div>
                                                <div class="d-flex flex-wrap gap-2">
                                                        <?php foreach ($syncPeerSections as $peerSection): ?>
                                                            <?php $peerClassId = (int) ($peerSection['class_record_id'] ?? 0); ?>
                                                            <label class="form-check form-check-inline m-0">
                                                                <input class="form-check-input js-tca-sync-target" type="checkbox" name="apply_section_ids[]" value="<?php echo $peerClassId; ?>" <?php echo in_array($peerClassId, $syncPreferredPeerIds, true) ? 'checked' : ''; ?>>
                                                                <span class="form-check-label"><?php echo htmlspecialchars((string) ($peerSection['section'] ?? ('Section ' . $peerClassId))); ?></span>
                                                            </label>
                                                        <?php endforeach; ?>
                                                    </div>
                                                    <div class="form-check mt-2 mb-0">
                                                        <input class="form-check-input" type="checkbox" id="tcaSyncAutoApply" name="sync_auto_apply" value="1" <?php echo !empty($syncPreference['auto_apply']) ? 'checked' : ''; ?>>
                                                        <label class="form-check-label small" for="tcaSyncAutoApply">
                                                            Always apply to selected sections for this subject/term
                                                        </label>
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                        <div class="mt-3">
                                            <button class="btn btn-primary" type="submit">
                                                <i class="ri-add-line me-1" aria-hidden="true"></i>
                                                Add
                                            </button>
                                            <a class="btn btn-outline-secondary ms-2"
                                                href="teacher-grading-config.php?class_record_id=<?php echo (int) $classRecordId; ?>&term=<?php echo htmlspecialchars($term); ?>">
                                                <i class="ri-arrow-left-line me-1" aria-hidden="true"></i>
                                                Back
                                            </a>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title">Context</h4>
                                    <div class="text-muted small">
                                        <div><strong>Subject:</strong> <?php echo htmlspecialchars((string) ($ctx['subject_name'] ?? '')); ?> (<?php echo htmlspecialchars((string) ($ctx['subject_code'] ?? '')); ?>)</div>
                                        <div><strong>Term:</strong> <?php echo htmlspecialchars($termLabel); ?></div>
                                        <div><strong>Section:</strong> <?php echo htmlspecialchars((string) ($ctx['section'] ?? '')); ?></div>
                                        <div><strong>Component:</strong> <?php echo htmlspecialchars((string) ($ctx['component_name'] ?? '')); ?> (<?php echo htmlspecialchars((string) ($ctx['component_code'] ?? '')); ?>)</div>
                                        <div><strong>Weight:</strong> <?php echo htmlspecialchars((string) ($ctx['weight'] ?? '0')); ?>%</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-8">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title">Assessment List</h4>
                                    <div class="table-responsive mt-2">
                                        <table class="table table-sm table-striped table-hover align-middle mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Assessment</th>
                                                    <th style="width: 170px;">Module</th>
                                                    <th style="width: 120px;">Max</th>
                                                    <th style="width: 160px;">Date</th>
                                                    <th style="width: 110px;">Order</th>
                                                    <th style="width: 120px;">Status</th>
                                                    <th class="text-end" style="width: 320px;">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (count($assessments) === 0): ?>
                                                    <tr>
                                                        <td colspan="7" class="text-center text-muted">No assessments yet.</td>
                                                    </tr>
                                                <?php endif; ?>
                                                <?php foreach ($assessments as $a): ?>
                                                    <tr>
                                                        <td class="fw-semibold"><?php echo htmlspecialchars((string) ($a['name'] ?? '')); ?></td>
                                                        <td>
                                                            <?php
                                                            $moduleType = grading_normalize_module_type((string) ($a['module_type'] ?? 'assessment'));
                                                            $moduleInfo = $moduleCatalog[$moduleType] ?? grading_module_info($moduleType);
                                                            $moduleLabel = (string) ($moduleInfo['label'] ?? 'Assessment');
                                                            $moduleKind = strtolower((string) ($moduleInfo['kind'] ?? 'assessment'));
                                                            $moduleClass = 'bg-secondary-subtle text-secondary';
                                                            if ($moduleKind === 'activity') $moduleClass = 'bg-primary-subtle text-primary';
                                                            elseif ($moduleKind === 'resource') $moduleClass = 'bg-info-subtle text-info';
                                                            ?>
                                                            <span class="badge <?php echo htmlspecialchars($moduleClass); ?>"><?php echo htmlspecialchars($moduleLabel); ?></span>
                                                        </td>
                                                        <td><?php echo htmlspecialchars((string) ($a['max_score'] ?? '0')); ?></td>
                                                        <td class="text-muted small"><?php echo htmlspecialchars((string) ($a['assessment_date'] ?? '')); ?></td>
                                                        <td><?php echo (int) ($a['display_order'] ?? 0); ?></td>
                                                        <td>
                                                            <?php if (((int) ($a['is_active'] ?? 0)) === 1): ?>
                                                                <span class="badge bg-success">Active</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-secondary">Disabled</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="text-end">
                                                            <?php
                                                            $assessmentRowId = (int) ($a['id'] ?? 0);
                                                            $builderUrl = $moduleType === 'assignment'
                                                                ? ('teacher-assignment-builder.php?assessment_id=' . $assessmentRowId)
                                                                : ('teacher-assessment-builder.php?assessment_id=' . $assessmentRowId);
                                                            $scoreUrl = $moduleType === 'assignment'
                                                                ? ('teacher-assignment-submissions.php?assessment_id=' . $assessmentRowId)
                                                                : ('teacher-assessment-scores.php?assessment_id=' . $assessmentRowId);
                                                            $scoreLabel = $moduleType === 'assignment' ? 'Submissions' : 'Scores';
                                                            $scoreIcon = $moduleType === 'assignment' ? 'ri-task-line' : 'ri-edit-2-line';
                                                            ?>
                                                            <a class="btn btn-sm btn-outline-dark"
                                                                href="<?php echo htmlspecialchars($builderUrl); ?>">
                                                                <i class="ri-quill-pen-line me-1" aria-hidden="true"></i>
                                                                Builder
                                                            </a>

                                                            <a class="btn btn-sm btn-outline-primary"
                                                                href="<?php echo htmlspecialchars($scoreUrl); ?>">
                                                                <i class="<?php echo htmlspecialchars($scoreIcon); ?> me-1" aria-hidden="true"></i>
                                                                <?php echo htmlspecialchars($scoreLabel); ?>
                                                            </a>

                                                            <button
                                                                type="button"
                                                                class="btn btn-sm btn-outline-secondary"
                                                                data-bs-toggle="modal"
                                                                data-bs-target="#editAssessmentModal"
                                                                data-assessment-id="<?php echo (int) ($a['id'] ?? 0); ?>"
                                                                data-name="<?php echo htmlspecialchars((string) ($a['name'] ?? ''), ENT_QUOTES); ?>"
                                                                data-max="<?php echo htmlspecialchars((string) ($a['max_score'] ?? '0'), ENT_QUOTES); ?>"
                                                                data-date="<?php echo htmlspecialchars((string) ($a['assessment_date'] ?? ''), ENT_QUOTES); ?>"
                                                                data-module-type="<?php echo htmlspecialchars((string) ($moduleType ?? 'assessment'), ENT_QUOTES); ?>"
                                                                data-order="<?php echo (int) ($a['display_order'] ?? 0); ?>"
                                                                data-active="<?php echo (int) ($a['is_active'] ?? 0); ?>"
                                                            >
                                                                <i class="ri-settings-3-line" aria-hidden="true"></i>
                                                            </button>

                                                            <form method="post" class="d-inline">
                                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                                                <input type="hidden" name="action" value="delete_assessment">
                                                                <input type="hidden" name="assessment_id" value="<?php echo (int) ($a['id'] ?? 0); ?>">
                                                                <button class="btn btn-sm btn-outline-danger" type="submit" onclick="return confirm('Delete this assessment?');">
                                                                    <i class="ri-delete-bin-6-line" aria-hidden="true"></i>
                                                                </button>
                                                            </form>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="text-muted small mt-2">
                                        Tip: Scores inside a component are weighted by total <code>max_score</code> across its assessments.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <?php include '../layouts/footer.php'; ?>
        </div>
    </div>

    <!-- Edit Assessment Modal -->
    <div class="modal fade" id="editAssessmentModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Assessment</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                        <input type="hidden" name="action" value="update_assessment">
                        <input type="hidden" name="assessment_id" id="editAssessmentId" value="">

                        <div class="mb-2">
                            <label class="form-label">Name</label>
                            <input class="form-control" name="name" id="editAssessmentName" maxlength="120" required>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Max Score</label>
                            <input class="form-control" name="max_score" id="editAssessmentMax" type="number" min="0" step="0.01" inputmode="decimal" required>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Module Type</label>
                            <select class="form-select" name="module_type" id="editAssessmentModuleType">
                                <?php foreach ($moduleCatalog as $moduleKey => $moduleInfo): ?>
                                    <option value="<?php echo htmlspecialchars((string) $moduleKey); ?>">
                                        <?php echo htmlspecialchars((string) ($moduleInfo['label'] ?? $moduleKey)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Date (optional)</label>
                            <input class="form-control" type="date" name="assessment_date" id="editAssessmentDate">
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Order</label>
                            <input class="form-control" type="number" name="display_order" id="editAssessmentOrder" value="0">
                        </div>
                        <div class="mb-0">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="is_active" id="editAssessmentActive">
                                <option value="1">Active</option>
                                <option value="0">Disabled</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="ri-save-3-line me-1" aria-hidden="true"></i>
                            Save
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php include '../layouts/right-sidebar.php'; ?>
    <?php include '../layouts/footer-scripts.php'; ?>
    <script src="assets/js/app.min.js"></script>
    <script>
        (function () {
            var addAssessmentForm = document.getElementById('tcaAddAssessmentForm');
            if (addAssessmentForm) {
                addAssessmentForm.addEventListener('submit', function () {
                    var syncTargets = addAssessmentForm.querySelectorAll('.js-tca-sync-target');
                    if (!syncTargets || syncTargets.length === 0) return;

                var checkedCount = 0;
                syncTargets.forEach(function (box) {
                    if (box.checked) checkedCount++;
                });
                if (checkedCount > 0) return;

                var autoInput = addAssessmentForm.querySelector('#tcaSyncAutoApply');
                if (autoInput && autoInput.checked) return;

                var applyAll = window.confirm('Apply this new assessment to your other sections in the same subject/term?');
                if (!applyAll) return;
                    syncTargets.forEach(function (box) {
                        box.checked = true;
                    });
                });
            }

            var modal = document.getElementById('editAssessmentModal');
            if (!modal) return;
            modal.addEventListener('show.bs.modal', function (event) {
                var btn = event.relatedTarget;
                if (!btn) return;
                document.getElementById('editAssessmentId').value = btn.getAttribute('data-assessment-id') || '';
                document.getElementById('editAssessmentName').value = btn.getAttribute('data-name') || '';
                document.getElementById('editAssessmentMax').value = btn.getAttribute('data-max') || '0';
                document.getElementById('editAssessmentDate').value = btn.getAttribute('data-date') || '';
                document.getElementById('editAssessmentModuleType').value = btn.getAttribute('data-module-type') || 'assessment';
                document.getElementById('editAssessmentOrder').value = btn.getAttribute('data-order') || '0';
                document.getElementById('editAssessmentActive').value = btn.getAttribute('data-active') || '1';
            });
        })();
    </script>
</body>
</html>
