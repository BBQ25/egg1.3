<?php include '../layouts/session.php'; ?>
<?php require_active_role('teacher'); ?>
<?php include '../layouts/main.php'; ?>

<?php
require_once __DIR__ . '/../includes/learning_materials.php';
require_once __DIR__ . '/../includes/section_sync.php';
ensure_learning_material_tables($conn);

$flash = isset($_SESSION['flash_message']) ? (string) $_SESSION['flash_message'] : '';
$flashType = isset($_SESSION['flash_type']) ? (string) $_SESSION['flash_type'] : 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

$teacherId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$classRecordId = isset($_GET['class_record_id']) ? (int) $_GET['class_record_id'] : 0;
if ($classRecordId <= 0) {
    $_SESSION['flash_message'] = 'Invalid class.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: teacher-my-classes.php');
    exit;
}

if (!function_exists('tlm_h')) {
    function tlm_h($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('tlm_fmt_datetime')) {
    function tlm_fmt_datetime($value) {
        $value = trim((string) $value);
        if ($value === '') return '-';
        $ts = strtotime($value);
        return $ts ? date('Y-m-d H:i', $ts) : $value;
    }
}
if (!function_exists('tlm_status_badge')) {
    function tlm_status_badge($status) {
        $status = learning_material_normalize_status($status);
        if ($status === 'published') return ['class' => 'bg-success-subtle text-success', 'label' => 'Published'];
        return ['class' => 'bg-secondary-subtle text-secondary', 'label' => 'Draft'];
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    if (!csrf_validate($csrf)) {
        $_SESSION['flash_message'] = 'Security check failed (CSRF).';
        $_SESSION['flash_type'] = 'danger';
        header('Location: teacher-learning-materials.php?class_record_id=' . $classRecordId);
        exit;
    }

    $action = isset($_POST['action']) ? trim((string) $_POST['action']) : '';
    if ($action === 'set_status') {
        $materialId = isset($_POST['material_id']) ? (int) $_POST['material_id'] : 0;
        $status = learning_material_normalize_status($_POST['status'] ?? '');
        if ($materialId > 0) {
            $sourceTitle = '';
            $titleQ = $conn->prepare(
                "SELECT title
                 FROM learning_materials
                 WHERE id = ?
                   AND class_record_id = ?
                 LIMIT 1"
            );
            if ($titleQ) {
                $titleQ->bind_param('ii', $materialId, $classRecordId);
                $titleQ->execute();
                $titleRes = $titleQ->get_result();
                if ($titleRes && $titleRes->num_rows === 1) {
                    $sourceTitle = trim((string) (($titleRes->fetch_assoc()['title'] ?? '')));
                }
                $titleQ->close();
            }

            $publishedAt = $status === 'published' ? date('Y-m-d H:i:s') : null;
            $up = $conn->prepare(
                "UPDATE learning_materials
                 SET status = ?,
                     published_at = CASE
                        WHEN ? = 'published' THEN COALESCE(published_at, ?)
                        ELSE NULL
                     END,
                     updated_by = ?
                 WHERE id = ?
                   AND class_record_id = ?
                 LIMIT 1"
            );
            if ($up) {
                $up->bind_param('sssiii', $status, $status, $publishedAt, $teacherId, $materialId, $classRecordId);
                $ok = $up->execute();
                $affected = $up->affected_rows;
                $up->close();
                if ($ok && $affected >= 0) {
                    $syncCopied = 0;
                    $syncSkipped = 0;
                    $syncTargets = section_sync_resolve_target_ids([], $syncPeerSections, $syncPreference, true);
                    if ($sourceTitle !== '' && count($syncTargets) > 0) {
                        $syncUp = $conn->prepare(
                            "UPDATE learning_materials
                             SET status = ?,
                                 published_at = CASE
                                    WHEN ? = 'published' THEN COALESCE(published_at, ?)
                                    ELSE NULL
                                 END,
                                 updated_by = ?
                             WHERE class_record_id = ?
                               AND title = ?"
                        );
                        if ($syncUp) {
                            foreach ($syncTargets as $targetClassId) {
                                $targetClassId = (int) $targetClassId;
                                $syncUp->bind_param('sssiis', $status, $status, $publishedAt, $teacherId, $targetClassId, $sourceTitle);
                                if ($syncUp->execute()) {
                                    if ((int) $syncUp->affected_rows > 0) $syncCopied++;
                                    else $syncSkipped++;
                                } else {
                                    $syncSkipped++;
                                }
                            }
                            $syncUp->close();
                        } else {
                            $syncSkipped += count($syncTargets);
                        }
                    }

                    $flashMessage = $status === 'published' ? 'Material published.' : 'Material moved to draft.';
                    if ($syncCopied > 0) $flashMessage .= ' Synced to ' . $syncCopied . ' section(s).';
                    if ($syncSkipped > 0) $flashMessage .= ' Skipped ' . $syncSkipped . ' section(s).';
                    $_SESSION['flash_message'] = $flashMessage;
                    $_SESSION['flash_type'] = 'success';
                } else {
                    $_SESSION['flash_message'] = 'Unable to update material status.';
                    $_SESSION['flash_type'] = 'danger';
                }
            } else {
                $_SESSION['flash_message'] = 'Unable to update material status.';
                $_SESSION['flash_type'] = 'danger';
            }
        }
        header('Location: teacher-learning-materials.php?class_record_id=' . $classRecordId);
        exit;
    }

    if ($action === 'delete_material') {
        $materialId = isset($_POST['material_id']) ? (int) $_POST['material_id'] : 0;
        if ($materialId > 0) {
            $owned = false;
            $check = $conn->prepare(
                "SELECT id
                 FROM learning_materials
                 WHERE id = ?
                   AND class_record_id = ?
                 LIMIT 1"
            );
            if ($check) {
                $check->bind_param('ii', $materialId, $classRecordId);
                $check->execute();
                $res = $check->get_result();
                $owned = $res && $res->num_rows === 1;
                $check->close();
            }
            if ($owned) {
                learning_material_delete_all_attachments($conn, $materialId);
            }
            $del = $conn->prepare(
                "DELETE FROM learning_materials
                 WHERE id = ?
                   AND class_record_id = ?
                 LIMIT 1"
            );
            if ($del) {
                $del->bind_param('ii', $materialId, $classRecordId);
                $ok = $del->execute();
                $affected = $del->affected_rows;
                $del->close();
                if ($ok && $affected > 0) {
                    $_SESSION['flash_message'] = 'Learning material deleted.';
                    $_SESSION['flash_type'] = 'success';
                } else {
                    $_SESSION['flash_message'] = 'Material not found or already deleted.';
                    $_SESSION['flash_type'] = 'warning';
                }
            } else {
                $_SESSION['flash_message'] = 'Delete failed.';
                $_SESSION['flash_type'] = 'danger';
            }
        }
        header('Location: teacher-learning-materials.php?class_record_id=' . $classRecordId);
        exit;
    }

    $_SESSION['flash_message'] = 'Invalid request.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: teacher-learning-materials.php?class_record_id=' . $classRecordId);
    exit;
}

$materials = [];
$q = $conn->prepare(
    "SELECT lm.id,
            lm.title,
            lm.summary,
            lm.status,
            lm.display_order,
            lm.published_at,
            lm.updated_at,
            (
                SELECT COUNT(*)
                FROM learning_material_files lmf
                WHERE lmf.material_id = lm.id
            ) AS attachment_count
     FROM learning_materials lm
     WHERE lm.class_record_id = ?
     ORDER BY lm.display_order ASC, lm.id ASC"
);
if ($q) {
    $q->bind_param('i', $classRecordId);
    $q->execute();
    $res = $q->get_result();
    while ($res && ($row = $res->fetch_assoc())) $materials[] = $row;
    $q->close();
}

$publishedCount = 0;
foreach ($materials as $materialRow) {
    if (learning_material_normalize_status($materialRow['status'] ?? '') === 'published') {
        $publishedCount++;
    }
}
?>

<head>
    <title>Learning Materials | E-Record</title>
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
                                        <li class="breadcrumb-item"><a href="teacher-my-classes.php">My Classes</a></li>
                                        <li class="breadcrumb-item active">Learning Materials</li>
                                    </ol>
                                </div>
                                <h4 class="page-title">Learning Materials</h4>
                            </div>
                        </div>
                    </div>

                    <?php if ($flash !== ''): ?>
                        <div class="alert alert-<?php echo tlm_h($flashType); ?> alert-dismissible fade show" role="alert">
                            <?php echo tlm_h($flash); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                                        <div>
                                            <h5 class="mb-1">
                                                <?php echo tlm_h((string) ($ctx['subject_name'] ?? 'Subject')); ?>
                                                <?php if (!empty($ctx['subject_code'])): ?>
                                                    <span class="text-muted">(<?php echo tlm_h((string) ($ctx['subject_code'] ?? '')); ?>)</span>
                                                <?php endif; ?>
                                            </h5>
                                            <p class="text-muted mb-0">
                                                Section: <?php echo tlm_h((string) ($ctx['section'] ?? '')); ?> |
                                                <?php echo tlm_h((string) ($ctx['academic_year'] ?? '')); ?> |
                                                <?php echo tlm_h((string) ($ctx['semester'] ?? '')); ?>
                                            </p>
                                        </div>
                                        <div class="d-flex align-items-center gap-2 flex-wrap">
                                            <span class="badge bg-secondary-subtle text-secondary">Total: <?php echo (int) count($materials); ?></span>
                                            <span class="badge bg-success-subtle text-success">Published: <?php echo (int) $publishedCount; ?></span>
                                            <a class="btn btn-primary" href="teacher-learning-material-editor.php?class_record_id=<?php echo (int) $classRecordId; ?>">
                                                <i class="ri-add-line me-1" aria-hidden="true"></i>
                                                New Material
                                            </a>
                                        </div>
                                    </div>

                                    <div class="table-responsive">
                                        <table class="table table-sm table-striped table-hover align-middle mb-0">
                                            <thead>
                                                <tr>
                                                    <th style="width: 80px;">Order</th>
                                                    <th>Title</th>
                                                    <th style="width: 130px;">Status</th>
                                                    <th style="width: 170px;">Published</th>
                                                    <th style="width: 170px;">Updated</th>
                                                    <th class="text-end" style="width: 560px;">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (count($materials) === 0): ?>
                                                    <tr>
                                                        <td colspan="6" class="text-center text-muted py-4">
                                                            No learning materials yet.
                                                            <div class="mt-2">
                                                                <a class="btn btn-sm btn-outline-primary" href="teacher-learning-material-editor.php?class_record_id=<?php echo (int) $classRecordId; ?>">
                                                                    Create your first material
                                                                </a>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endif; ?>
                                                <?php foreach ($materials as $material): ?>
                                                    <?php
                                                    $materialId = (int) ($material['id'] ?? 0);
                                                    $status = learning_material_normalize_status($material['status'] ?? '');
                                                    $statusInfo = tlm_status_badge($status);
                                                    $summary = learning_material_excerpt(
                                                        (string) ($material['summary'] ?? ''),
                                                        '',
                                                        160
                                                    );
                                                    ?>
                                                    <tr>
                                                        <td><?php echo (int) ($material['display_order'] ?? 0); ?></td>
                                                        <td>
                                                            <div class="fw-semibold"><?php echo tlm_h((string) ($material['title'] ?? 'Untitled')); ?></div>
                                                            <?php if ($summary !== ''): ?>
                                                                <div class="text-muted small"><?php echo tlm_h($summary); ?></div>
                                                            <?php endif; ?>
                                                            <div class="text-muted small mt-1">
                                                                <i class="ri-attachment-2 me-1" aria-hidden="true"></i>
                                                                <?php echo (int) ($material['attachment_count'] ?? 0); ?> attachment(s)
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <span class="badge <?php echo tlm_h((string) ($statusInfo['class'] ?? 'bg-secondary-subtle text-secondary')); ?>">
                                                                <?php echo tlm_h((string) ($statusInfo['label'] ?? 'Draft')); ?>
                                                            </span>
                                                        </td>
                                                        <td><?php echo tlm_h(tlm_fmt_datetime((string) ($material['published_at'] ?? ''))); ?></td>
                                                        <td><?php echo tlm_h(tlm_fmt_datetime((string) ($material['updated_at'] ?? ''))); ?></td>
                                                        <td class="text-end">
                                                            <a class="btn btn-sm btn-outline-primary"
                                                                href="teacher-learning-material-editor.php?class_record_id=<?php echo (int) $classRecordId; ?>&material_id=<?php echo $materialId; ?>">
                                                                <i class="ri-quill-pen-line me-1" aria-hidden="true"></i>
                                                                Edit
                                                            </a>
                                                            <a class="btn btn-sm btn-outline-info"
                                                                href="teacher-learning-material-preview.php?material_id=<?php echo $materialId; ?>"
                                                                target="_blank">
                                                                <i class="ri-eye-line me-1" aria-hidden="true"></i>
                                                                Preview
                                                            </a>
                                                            <a class="btn btn-sm btn-outline-warning"
                                                                href="teacher-live-slides.php?class_record_id=<?php echo (int) $classRecordId; ?>&material_id=<?php echo $materialId; ?>">
                                                                <i class="ri-slideshow-line me-1" aria-hidden="true"></i>
                                                                Live Slides
                                                            </a>

                                                            <form method="post" class="d-inline">
                                                                <input type="hidden" name="csrf_token" value="<?php echo tlm_h(csrf_token()); ?>">
                                                                <input type="hidden" name="action" value="set_status">
                                                                <input type="hidden" name="material_id" value="<?php echo $materialId; ?>">
                                                                <input type="hidden" name="status" value="<?php echo $status === 'published' ? 'draft' : 'published'; ?>">
                                                                <button class="btn btn-sm <?php echo $status === 'published' ? 'btn-outline-secondary' : 'btn-outline-success'; ?>" type="submit">
                                                                    <?php echo $status === 'published' ? 'Move to Draft' : 'Publish'; ?>
                                                                </button>
                                                            </form>

                                                            <form method="post" class="d-inline">
                                                                <input type="hidden" name="csrf_token" value="<?php echo tlm_h(csrf_token()); ?>">
                                                                <input type="hidden" name="action" value="delete_material">
                                                                <input type="hidden" name="material_id" value="<?php echo $materialId; ?>">
                                                                <button class="btn btn-sm btn-outline-danger" type="submit" onclick="return confirm('Delete this learning material?');">
                                                                    <i class="ri-delete-bin-6-line" aria-hidden="true"></i>
                                                                </button>
                                                            </form>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <div class="mt-3">
                                        <a class="btn btn-outline-secondary" href="teacher-my-classes.php">
                                            <i class="ri-arrow-left-line me-1" aria-hidden="true"></i>
                                            Back to My Classes
                                        </a>
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

    <?php include '../layouts/right-sidebar.php'; ?>
    <?php include '../layouts/footer-scripts.php'; ?>
    <script src="assets/js/app.min.js"></script>
</body>

</html>
