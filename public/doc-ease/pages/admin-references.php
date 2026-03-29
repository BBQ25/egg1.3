<?php include '../layouts/session.php'; ?>
<?php require_role('admin'); ?>
<?php include '../includes/reference.php'; ?>
<?php include '../includes/ai_credits.php'; ?>
<?php include '../includes/accomplishment_creator.php'; ?>
<?php include '../includes/attendance_attachments.php'; ?>
<?php include '../includes/reverse_class_record.php'; ?>
<?php include '../layouts/main.php'; ?>

<?php
ensure_reference_tables($conn);
ai_credit_ensure_system($conn);
attendance_ensure_tables($conn);
$isSuperadmin = current_user_is_superadmin();

$flash = isset($_SESSION['flash_message']) ? (string) $_SESSION['flash_message'] : '';
$flashType = isset($_SESSION['flash_type']) ? (string) $_SESSION['flash_type'] : 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

function ref_valid_name($name) {
    $name = trim((string) $name);
    if ($name === '') return [false, 'Name is required.'];
    if (strlen($name) > 32) return [false, 'Name must be 32 characters or less.'];
    return [true, $name];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    if (!csrf_validate($csrf)) {
        $_SESSION['flash_message'] = 'Security check failed (CSRF). Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: admin-references.php');
        exit;
    }

    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

    $handleAdd = function ($table, $field) use ($conn) {
        $raw = isset($_POST[$field]) ? $_POST[$field] : '';
        [$ok, $valOrErr] = ref_valid_name($raw);
        if (!$ok) {
            $_SESSION['flash_message'] = $valOrErr;
            $_SESSION['flash_type'] = 'danger';
            return;
        }

        $name = $valOrErr;
        $sort = isset($_POST['sort_order']) ? (int) $_POST['sort_order'] : 0;
        $stmt = $conn->prepare("INSERT INTO {$table} (name, sort_order, status) VALUES (?, ?, 'active')");
        if (!$stmt) {
            $_SESSION['flash_message'] = 'Insert failed. Please try again.';
            $_SESSION['flash_type'] = 'danger';
            return;
        }
        $stmt->bind_param('si', $name, $sort);
        try {
            if ($stmt->execute()) {
                $_SESSION['flash_message'] = 'Saved.';
                $_SESSION['flash_type'] = 'success';
            } else {
                $_SESSION['flash_message'] = 'Insert failed. Please try again.';
                $_SESSION['flash_type'] = 'danger';
            }
        } catch (mysqli_sql_exception $e) {
            if ((int) $e->getCode() === 1062) {
                $_SESSION['flash_message'] = 'That name already exists.';
                $_SESSION['flash_type'] = 'warning';
            } else {
                $_SESSION['flash_message'] = 'Insert failed: ' . $e->getMessage();
                $_SESSION['flash_type'] = 'danger';
            }
        }
        $stmt->close();
    };

    $handleEdit = function ($table, $idField, $nameField) use ($conn) {
        $id = isset($_POST[$idField]) ? (int) $_POST[$idField] : 0;
        if ($id <= 0) {
            $_SESSION['flash_message'] = 'Invalid item.';
            $_SESSION['flash_type'] = 'danger';
            return;
        }
        $raw = isset($_POST[$nameField]) ? $_POST[$nameField] : '';
        [$ok, $valOrErr] = ref_valid_name($raw);
        if (!$ok) {
            $_SESSION['flash_message'] = $valOrErr;
            $_SESSION['flash_type'] = 'danger';
            return;
        }
        $name = $valOrErr;
        $sort = isset($_POST['sort_order']) ? (int) $_POST['sort_order'] : 0;
        $stmt = $conn->prepare("UPDATE {$table} SET name = ?, sort_order = ? WHERE id = ?");
        if (!$stmt) {
            $_SESSION['flash_message'] = 'Update failed. Please try again.';
            $_SESSION['flash_type'] = 'danger';
            return;
        }
        $stmt->bind_param('sii', $name, $sort, $id);
        try {
            if ($stmt->execute()) {
                $_SESSION['flash_message'] = 'Updated.';
                $_SESSION['flash_type'] = 'success';
            } else {
                $_SESSION['flash_message'] = 'Update failed. Please try again.';
                $_SESSION['flash_type'] = 'danger';
            }
        } catch (mysqli_sql_exception $e) {
            if ((int) $e->getCode() === 1062) {
                $_SESSION['flash_message'] = 'That name already exists.';
                $_SESSION['flash_type'] = 'warning';
            } else {
                $_SESSION['flash_message'] = 'Update failed: ' . $e->getMessage();
                $_SESSION['flash_type'] = 'danger';
            }
        }
        $stmt->close();
    };

    $handleToggle = function ($table, $idField) use ($conn) {
        $id = isset($_POST[$idField]) ? (int) $_POST[$idField] : 0;
        if ($id <= 0) {
            $_SESSION['flash_message'] = 'Invalid item.';
            $_SESSION['flash_type'] = 'danger';
            return;
        }

        $stmt = $conn->prepare("UPDATE {$table} SET status = IF(status='active','inactive','active') WHERE id = ?");
        if (!$stmt) {
            $_SESSION['flash_message'] = 'Update failed. Please try again.';
            $_SESSION['flash_type'] = 'danger';
            return;
        }
        $stmt->bind_param('i', $id);
        if ($stmt->execute()) {
            $_SESSION['flash_message'] = 'Updated.';
            $_SESSION['flash_type'] = 'success';
        } else {
            $_SESSION['flash_message'] = 'Update failed. Please try again.';
            $_SESSION['flash_type'] = 'danger';
        }
        $stmt->close();
    };

    if ($action === 'add_ay') $handleAdd('academic_years', 'ay_name');
    elseif ($action === 'edit_ay') $handleEdit('academic_years', 'ay_id', 'ay_name');
    elseif ($action === 'toggle_ay') $handleToggle('academic_years', 'ay_id');
    elseif ($action === 'add_sem') $handleAdd('semesters', 'sem_name');
    elseif ($action === 'edit_sem') $handleEdit('semesters', 'sem_id', 'sem_name');
    elseif ($action === 'toggle_sem') $handleToggle('semesters', 'sem_id');
    elseif ($action === 'save_report_footer') {
        $docCode = trim((string) ($_POST['doc_code'] ?? ''));
        $revision = trim((string) ($_POST['revision'] ?? ''));
        $issueDate = trim((string) ($_POST['issue_date'] ?? ''));

        if ($docCode === '') {
            $_SESSION['flash_message'] = 'Doc Code is required.';
            $_SESSION['flash_type'] = 'danger';
        } elseif (strlen($docCode) > 64 || strlen($revision) > 64 || strlen($issueDate) > 120) {
            $_SESSION['flash_message'] = 'One or more fields are too long.';
            $_SESSION['flash_type'] = 'danger';
        } else {
            $ok = ref_save_report_template_settings($conn, [
                'doc_code' => $docCode,
                'revision' => $revision,
                'issue_date' => $issueDate,
            ]);
            if ($ok) {
                $_SESSION['flash_message'] = 'Report footer settings saved.';
                $_SESSION['flash_type'] = 'success';
            } else {
                $_SESSION['flash_message'] = 'Failed to save report footer settings.';
                $_SESSION['flash_type'] = 'danger';
            }
        }
    }
    elseif ($action === 'save_ai_rephrase_default') {
        $defaultLimit = isset($_POST['ai_rephrase_default_limit']) ? (float) $_POST['ai_rephrase_default_limit'] : ai_credit_hard_default_limit();
        $defaultLimit = ai_credit_clamp_limit($defaultLimit);
        $ok = ai_credit_save_default_limit($conn, $defaultLimit);
        if ($ok) {
            $_SESSION['flash_message'] = 'Default AI re-phrase credits saved.';
            $_SESSION['flash_type'] = 'success';
        } else {
            $_SESSION['flash_message'] = 'Failed to save default AI re-phrase credits.';
            $_SESSION['flash_type'] = 'danger';
        }
    }
    elseif ($action === 'save_creator_output_ttl') {
        $ttlMinutes = isset($_POST['creator_output_ttl_minutes'])
            ? (int) $_POST['creator_output_ttl_minutes']
            : acc_creator_output_ttl_default_minutes();
        $ttlMinutes = acc_creator_output_ttl_clamp_minutes($ttlMinutes);
        $ok = acc_creator_output_ttl_save_minutes($conn, $ttlMinutes);
        if ($ok) {
            $_SESSION['flash_message'] = 'Accomplishment Creator output availability saved.';
            $_SESSION['flash_type'] = 'success';
        } else {
            $_SESSION['flash_message'] = 'Failed to save Accomplishment Creator output availability.';
            $_SESSION['flash_type'] = 'danger';
        }
    }
    elseif ($action === 'save_attendance_ai_transcribe') {
        $enabled = isset($_POST['attendance_ai_transcribe_enabled']) && (string) $_POST['attendance_ai_transcribe_enabled'] === '1';
        $ok = attendance_save_ai_transcribe_enabled($conn, $enabled);
        if ($ok) {
            $_SESSION['flash_message'] = $enabled
                ? 'Attendance image transcription is now ON.'
                : 'Attendance image transcription is now OFF. Students must provide a manual description.';
            $_SESSION['flash_type'] = 'success';
        } else {
            $_SESSION['flash_message'] = 'Failed to save attendance transcription setting.';
            $_SESSION['flash_type'] = 'danger';
        }
    }
    elseif ($action === 'save_reverse_class_record_toggle') {
        if (!$isSuperadmin) {
            $_SESSION['flash_message'] = 'Only superadmin can change Reverse Class Record setting.';
            $_SESSION['flash_type'] = 'danger';
        } else {
            $enabled = isset($_POST['reverse_class_record_enabled']) && (string) $_POST['reverse_class_record_enabled'] === '1';
            $ok = reverse_class_record_set_enabled($conn, $enabled);
            if ($ok) {
                $_SESSION['flash_message'] = $enabled
                    ? 'Reverse Class Record is now ON.'
                    : 'Reverse Class Record is now OFF.';
                $_SESSION['flash_type'] = 'success';
            } else {
                $_SESSION['flash_message'] = 'Failed to save Reverse Class Record setting.';
                $_SESSION['flash_type'] = 'danger';
            }
        }
    }
    elseif ($action === 'save_reverse_class_record_credit_cost') {
        if (!$isSuperadmin) {
            $_SESSION['flash_message'] = 'Only superadmin can change Reverse Class Record credit cost.';
            $_SESSION['flash_type'] = 'danger';
        } else {
            $creditCost = isset($_POST['reverse_class_record_credit_cost'])
                ? (float) $_POST['reverse_class_record_credit_cost']
                : reverse_class_record_credit_cost_default();
            $creditCost = reverse_class_record_credit_cost_clamp($creditCost);
            $ok = reverse_class_record_set_credit_cost($conn, $creditCost);
            if ($ok) {
                $_SESSION['flash_message'] = 'Reverse Class Record generation credit cost saved.';
                $_SESSION['flash_type'] = 'success';
            } else {
                $_SESSION['flash_message'] = 'Failed to save Reverse Class Record generation credit cost.';
                $_SESSION['flash_type'] = 'danger';
            }
        }
    }
    elseif ($action === 'set_reverse_class_record_teacher_access_all') {
        $mode = trim((string) ($_POST['reverse_class_record_bulk_mode'] ?? ''));
        $enableAll = ($mode === 'enable_all');
        if (!in_array($mode, ['enable_all', 'disable_all'], true)) {
            $_SESSION['flash_message'] = 'Invalid bulk action.';
            $_SESSION['flash_type'] = 'danger';
        } else {
            [$affected, $ok] = reverse_class_record_teacher_set_all_enabled($conn, $enableAll);
            if ($ok) {
                $_SESSION['flash_message'] = $enableAll
                    ? 'Reverse Class Record access enabled for all teacher accounts.'
                    : 'Reverse Class Record access disabled for all teacher accounts.';
                $_SESSION['flash_type'] = 'success';
            } else {
                $_SESSION['flash_message'] = 'Failed to update bulk teacher access.';
                $_SESSION['flash_type'] = 'danger';
            }
        }
    }
    elseif ($action === 'save_reverse_class_record_teacher_access') {
        $teacherIdsRaw = isset($_POST['reverse_class_record_teacher_ids']) ? (array) $_POST['reverse_class_record_teacher_ids'] : [];
        $enabledIdsRaw = isset($_POST['reverse_class_record_teacher_enabled']) ? (array) $_POST['reverse_class_record_teacher_enabled'] : [];

        $teacherIds = [];
        foreach ($teacherIdsRaw as $raw) {
            $id = (int) $raw;
            if ($id > 0) $teacherIds[$id] = true;
        }
        $enabledSet = [];
        foreach ($enabledIdsRaw as $raw) {
            $id = (int) $raw;
            if ($id > 0) $enabledSet[$id] = true;
        }

        if (count($teacherIds) === 0) {
            $_SESSION['flash_message'] = 'No teacher accounts were submitted.';
            $_SESSION['flash_type'] = 'warning';
        } else {
            $saved = 0;
            $failed = 0;
            foreach (array_keys($teacherIds) as $teacherUserId) {
                $enabled = isset($enabledSet[$teacherUserId]);
                $ok = reverse_class_record_teacher_set_enabled($conn, $teacherUserId, $enabled);
                if ($ok) $saved++;
                else $failed++;
            }

            if ($failed === 0) {
                $_SESSION['flash_message'] = 'Reverse Class Record access updated for ' . $saved . ' teacher account(s).';
                $_SESSION['flash_type'] = 'success';
            } elseif ($saved > 0) {
                $_SESSION['flash_message'] = 'Updated ' . $saved . ' teacher account(s), but ' . $failed . ' update(s) failed.';
                $_SESSION['flash_type'] = 'warning';
            } else {
                $_SESSION['flash_message'] = 'Failed to update teacher access settings.';
                $_SESSION['flash_type'] = 'danger';
            }
        }
    }
    else {
        $_SESSION['flash_message'] = 'Invalid request.';
        $_SESSION['flash_type'] = 'danger';
    }

    header('Location: admin-references.php');
    exit;
}

$academicYears = [];
$resAy = $conn->query("SELECT id, name, status, sort_order FROM academic_years ORDER BY sort_order ASC, name ASC");
if ($resAy) while ($r = $resAy->fetch_assoc()) $academicYears[] = $r;

$semesters = [];
$resSem = $conn->query("SELECT id, name, status, sort_order FROM semesters ORDER BY sort_order ASC, name ASC");
if ($resSem) while ($r = $resSem->fetch_assoc()) $semesters[] = $r;

$reportFooterSettings = ref_get_report_template_settings($conn);
$aiRephraseDefaultLimit = ai_credit_get_default_limit($conn);
$creatorOutputTtlMinutes = acc_creator_output_ttl_get_minutes($conn);
$attendanceAiTranscribeEnabled = attendance_ai_transcribe_is_enabled($conn);
$reverseClassRecordEnabled = reverse_class_record_is_enabled($conn);
$reverseClassRecordCreditCost = reverse_class_record_get_credit_cost($conn);
$reverseClassRecordTeacherRows = reverse_class_record_teacher_access_rows($conn);
$reverseClassRecordTeachersEnabledCount = 0;
foreach ($reverseClassRecordTeacherRows as $teacherRow) {
    if ((int) ($teacherRow['reverse_enabled'] ?? 0) === 1) $reverseClassRecordTeachersEnabledCount++;
}
?>

<head>
    <title>Reference | E-Record</title>
    <?php include '../layouts/title-meta.php'; ?>
    <?php include '../layouts/head-css.php'; ?>

    <style>
        .ref-hero {
            border-radius: 14px;
            overflow: hidden;
            border: 1px solid rgba(48, 81, 255, 0.16);
            background: linear-gradient(135deg, rgba(48, 81, 255, 0.10), rgba(48, 81, 255, 0.02));
        }
        .ref-hero .ref-hero-inner {
            padding: 16px 16px;
        }
        .ref-hero h2 {
            margin: 0;
            font-weight: 800;
            line-height: 1.1;
        }
        .ref-hero p {
            margin: 6px 0 0 0;
            color: rgba(31, 41, 55, 0.75);
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
        .icon-btn:hover i { transform: translateY(-1px) scale(1.08); }
        .ref-switch {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            border: 1px solid rgba(15, 23, 42, 0.10);
            border-radius: 12px;
            padding: 12px;
            background: #f8fafc;
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
                                        <li class="breadcrumb-item active">Reference</li>
                                    </ol>
                                </div>
                                <h4 class="page-title">Reference</h4>
                            </div>
                        </div>
                    </div>

                    <?php if ($flash): ?>
                        <div class="alert alert-<?php echo htmlspecialchars($flashType); ?>"><?php echo htmlspecialchars($flash); ?></div>
                    <?php endif; ?>

                    <div class="ref-hero mb-3">
                        <div class="ref-hero-inner">
                            <h2>Academic Year and Semesters</h2>
                            <p>These values power dropdowns across enrollment, subjects, and class records.</p>
                        </div>
                    </div>

                    <ul class="nav nav-tabs nav-justified mb-3">
                        <li class="nav-item">
                            <a href="#academic-years" data-bs-toggle="tab" aria-expanded="true" class="nav-link active">
                                <i class="ri-calendar-line me-1" aria-hidden="true"></i> Academic Years
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#semesters" data-bs-toggle="tab" aria-expanded="false" class="nav-link">
                                <i class="ri-timer-2-line me-1" aria-hidden="true"></i> Semesters
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#report-template" data-bs-toggle="tab" aria-expanded="false" class="nav-link">
                                <i class="ri-file-text-line me-1" aria-hidden="true"></i> Report Template
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#ai-credits" data-bs-toggle="tab" aria-expanded="false" class="nav-link">
                                <i class="ri-magic-line me-1" aria-hidden="true"></i> AI Credits
                            </a>
                        </li>
                    </ul>

                    <div class="tab-content">
                        <div class="tab-pane show active" id="academic-years">
                            <div class="row g-3">
                                <div class="col-xl-4">
                                    <div class="card">
                                        <div class="card-body">
                                            <h4 class="header-title">Add Academic Year</h4>
                                            <form method="post" class="mt-3">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                                <input type="hidden" name="action" value="add_ay">
                                                <div class="mb-3">
                                                    <label class="form-label">Name</label>
                                                    <input class="form-control" name="ay_name" placeholder="e.g. 2025 - 2026" required maxlength="32">
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Sort Order</label>
                                                    <input class="form-control" name="sort_order" type="number" value="0">
                                                </div>
                                                <button class="btn btn-primary" type="submit">
                                                    <i class="ri-save-3-line me-1" aria-hidden="true"></i> Save
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-xl-8">
                                    <div class="card">
                                        <div class="card-body">
                                            <h4 class="header-title">List</h4>
                                            <div class="table-responsive">
                                                <table class="table table-sm table-striped table-hover align-middle mb-0">
                                                    <thead>
                                                        <tr>
                                                            <th>Name</th>
                                                            <th>Sort</th>
                                                            <th>Status</th>
                                                            <th class="text-end">Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php if (count($academicYears) === 0): ?>
                                                            <tr><td colspan="4" class="text-center text-muted">No academic years yet.</td></tr>
                                                        <?php endif; ?>
                                                        <?php foreach ($academicYears as $ay): ?>
                                                            <?php $active = ((string) ($ay['status'] ?? '')) === 'active'; ?>
                                                            <tr>
                                                                <td class="fw-semibold"><?php echo htmlspecialchars((string) ($ay['name'] ?? '')); ?></td>
                                                                <td><?php echo (int) ($ay['sort_order'] ?? 0); ?></td>
                                                                <td>
                                                                    <?php if ($active): ?>
                                                                        <span class="badge bg-success">Active</span>
                                                                    <?php else: ?>
                                                                        <span class="badge bg-secondary">Inactive</span>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td class="text-end">
                                                                    <button
                                                                        type="button"
                                                                        class="btn btn-sm btn-outline-primary icon-btn"
                                                                        data-bs-toggle="modal"
                                                                        data-bs-target="#editAyModal"
                                                                        data-id="<?php echo (int) ($ay['id'] ?? 0); ?>"
                                                                        data-name="<?php echo htmlspecialchars((string) ($ay['name'] ?? ''), ENT_QUOTES); ?>"
                                                                        data-sort="<?php echo (int) ($ay['sort_order'] ?? 0); ?>"
                                                                        title="Edit"
                                                                    >
                                                                        <i class="ri-pencil-line" aria-hidden="true"></i>
                                                                    </button>
                                                                    <form method="post" class="d-inline">
                                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                                                        <input type="hidden" name="action" value="toggle_ay">
                                                                        <input type="hidden" name="ay_id" value="<?php echo (int) ($ay['id'] ?? 0); ?>">
                                                                        <button class="btn btn-sm btn-outline-<?php echo $active ? 'danger' : 'success'; ?> icon-btn" type="submit" title="<?php echo $active ? 'Deactivate' : 'Activate'; ?>">
                                                                            <i class="<?php echo $active ? 'ri-forbid-2-line' : 'ri-check-line'; ?>" aria-hidden="true"></i>
                                                                        </button>
                                                                    </form>
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
                        </div>

                        <div class="tab-pane" id="semesters">
                            <div class="row g-3">
                                <div class="col-xl-4">
                                    <div class="card">
                                        <div class="card-body">
                                            <h4 class="header-title">Add Semester</h4>
                                            <form method="post" class="mt-3">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                                <input type="hidden" name="action" value="add_sem">
                                                <div class="mb-3">
                                                    <label class="form-label">Name</label>
                                                    <input class="form-control" name="sem_name" placeholder="e.g. 1st Semester" required maxlength="32">
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Sort Order</label>
                                                    <input class="form-control" name="sort_order" type="number" value="0">
                                                </div>
                                                <button class="btn btn-primary" type="submit">
                                                    <i class="ri-save-3-line me-1" aria-hidden="true"></i> Save
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-xl-8">
                                    <div class="card">
                                        <div class="card-body">
                                            <h4 class="header-title">List</h4>
                                            <div class="table-responsive">
                                                <table class="table table-sm table-striped table-hover align-middle mb-0">
                                                    <thead>
                                                        <tr>
                                                            <th>Name</th>
                                                            <th>Sort</th>
                                                            <th>Status</th>
                                                            <th class="text-end">Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php if (count($semesters) === 0): ?>
                                                            <tr><td colspan="4" class="text-center text-muted">No semesters yet.</td></tr>
                                                        <?php endif; ?>
                                                        <?php foreach ($semesters as $sem): ?>
                                                            <?php $active = ((string) ($sem['status'] ?? '')) === 'active'; ?>
                                                            <tr>
                                                                <td class="fw-semibold"><?php echo htmlspecialchars((string) ($sem['name'] ?? '')); ?></td>
                                                                <td><?php echo (int) ($sem['sort_order'] ?? 0); ?></td>
                                                                <td>
                                                                    <?php if ($active): ?>
                                                                        <span class="badge bg-success">Active</span>
                                                                    <?php else: ?>
                                                                        <span class="badge bg-secondary">Inactive</span>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td class="text-end">
                                                                    <button
                                                                        type="button"
                                                                        class="btn btn-sm btn-outline-primary icon-btn"
                                                                        data-bs-toggle="modal"
                                                                        data-bs-target="#editSemModal"
                                                                        data-id="<?php echo (int) ($sem['id'] ?? 0); ?>"
                                                                        data-name="<?php echo htmlspecialchars((string) ($sem['name'] ?? ''), ENT_QUOTES); ?>"
                                                                        data-sort="<?php echo (int) ($sem['sort_order'] ?? 0); ?>"
                                                                        title="Edit"
                                                                    >
                                                                        <i class="ri-pencil-line" aria-hidden="true"></i>
                                                                    </button>
                                                                    <form method="post" class="d-inline">
                                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                                                        <input type="hidden" name="action" value="toggle_sem">
                                                                        <input type="hidden" name="sem_id" value="<?php echo (int) ($sem['id'] ?? 0); ?>">
                                                                        <button class="btn btn-sm btn-outline-<?php echo $active ? 'danger' : 'success'; ?> icon-btn" type="submit" title="<?php echo $active ? 'Deactivate' : 'Activate'; ?>">
                                                                            <i class="<?php echo $active ? 'ri-forbid-2-line' : 'ri-check-line'; ?>" aria-hidden="true"></i>
                                                                        </button>
                                                                    </form>
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
                        </div>

                        <div class="tab-pane" id="report-template">
                            <div class="row g-3">
                                <div class="col-xl-6">
                                    <div class="card">
                                        <div class="card-body">
                                            <h4 class="header-title">Monthly Accomplishment Footer</h4>
                                            <p class="text-muted mb-3">These values appear on the left side of the print footer.</p>

                                            <form method="post">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                                <input type="hidden" name="action" value="save_report_footer">

                                                <div class="mb-3">
                                                    <label class="form-label">Doc Code</label>
                                                    <input class="form-control" name="doc_code" maxlength="64"
                                                        value="<?php echo htmlspecialchars((string) ($reportFooterSettings['doc_code'] ?? '')); ?>" required>
                                                </div>

                                                <div class="mb-3">
                                                    <label class="form-label">Revision</label>
                                                    <input class="form-control" name="revision" maxlength="64"
                                                        value="<?php echo htmlspecialchars((string) ($reportFooterSettings['revision'] ?? '')); ?>">
                                                </div>

                                                <div class="mb-3">
                                                    <label class="form-label">Date</label>
                                                    <input class="form-control" name="issue_date" maxlength="120"
                                                        value="<?php echo htmlspecialchars((string) ($reportFooterSettings['issue_date'] ?? '')); ?>">
                                                </div>

                                                <button class="btn btn-primary" type="submit">
                                                    <i class="ri-save-3-line me-1" aria-hidden="true"></i> Save
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="tab-pane" id="ai-credits">
                            <div class="row g-3">
                                <div class="col-xl-6">
                                    <div class="card">
                                        <div class="card-body">
                                            <h4 class="header-title">Default AI Re-phrase Credits</h4>
                                            <p class="text-muted mb-3">This default applies to new user accounts. You can adjust per-user allocation in All Accounts / Student Accounts / Teacher Accounts.</p>

                                            <form method="post">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                                <input type="hidden" name="action" value="save_ai_rephrase_default">

                                                <div class="mb-3">
                                                    <label class="form-label">Default Credits Per User</label>
                                                    <input class="form-control" type="number" name="ai_rephrase_default_limit" min="0" max="10000" step="0.10"
                                                        value="<?php echo htmlspecialchars(number_format((float) $aiRephraseDefaultLimit, 2, '.', '')); ?>" required>
                                                </div>

                                                <button class="btn btn-primary" type="submit">
                                                    <i class="ri-save-3-line me-1" aria-hidden="true"></i> Save
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-xl-6">
                                    <div class="card">
                                        <div class="card-body">
                                            <h4 class="header-title">Accomplishment Creator Output Availability</h4>
                                            <p class="text-muted mb-3">Generated output is temporary before moving to the Accomplishment database.</p>

                                            <form method="post">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                                <input type="hidden" name="action" value="save_creator_output_ttl">

                                                <div class="mb-3">
                                                    <label class="form-label">Availability (minutes)</label>
                                                    <input class="form-control" type="number" name="creator_output_ttl_minutes" min="1" max="1440"
                                                        value="<?php echo (int) $creatorOutputTtlMinutes; ?>" required>
                                                    <div class="form-text">Default is 5 minutes.</div>
                                                </div>

                                                <button class="btn btn-primary" type="submit">
                                                    <i class="ri-save-3-line me-1" aria-hidden="true"></i> Save
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-xl-6">
                                    <div class="card">
                                        <div class="card-body">
                                            <h4 class="header-title">Attendance Image Transcription</h4>
                                            <p class="text-muted mb-3">When OFF, students must manually write at least 30 characters for attendance description.</p>

                                            <form method="post">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                                <input type="hidden" name="action" value="save_attendance_ai_transcribe">

                                                <div class="ref-switch mb-3">
                                                    <div>
                                                        <div class="fw-semibold">Enable AI image-to-description</div>
                                                        <div class="small text-muted">Applies to student attendance uploads.</div>
                                                    </div>
                                                    <div class="form-check form-switch m-0">
                                                        <input class="form-check-input"
                                                            type="checkbox"
                                                            role="switch"
                                                            id="attendanceAiSwitch"
                                                            name="attendance_ai_transcribe_enabled"
                                                            value="1"
                                                            <?php echo $attendanceAiTranscribeEnabled ? 'checked' : ''; ?>>
                                                    </div>
                                                </div>

                                                <button class="btn btn-primary" type="submit">
                                                    <i class="ri-save-3-line me-1" aria-hidden="true"></i> Save
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-xl-6">
                                    <div class="card">
                                        <div class="card-body">
                                            <h4 class="header-title">Reverse Class Record</h4>
                                            <p class="text-muted mb-3">Controls global availability, generation credit cost, and per-teacher access for Reverse Class Record automation.</p>

                                            <form method="post" class="mb-3">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                                <input type="hidden" name="action" value="save_reverse_class_record_toggle">

                                                <div class="ref-switch mb-3">
                                                    <div>
                                                        <div class="fw-semibold">Enable Reverse Class Record</div>
                                                        <div class="small text-muted">When ON, teachers can upload student names + target subject grades and auto-generate class record entries.</div>
                                                    </div>
                                                    <div class="form-check form-switch m-0">
                                                        <input class="form-check-input"
                                                            type="checkbox"
                                                            role="switch"
                                                            id="reverseClassRecordSwitch"
                                                            name="reverse_class_record_enabled"
                                                            value="1"
                                                            <?php echo $reverseClassRecordEnabled ? 'checked' : ''; ?>
                                                            <?php echo $isSuperadmin ? '' : 'disabled'; ?>>
                                                    </div>
                                                </div>

                                                <?php if (!$isSuperadmin): ?>
                                                    <div class="alert alert-warning py-2 mb-3">
                                                        Only superadmin can change this setting.
                                                    </div>
                                                <?php endif; ?>

                                                <button class="btn btn-primary" type="submit" <?php echo $isSuperadmin ? '' : 'disabled'; ?>>
                                                    <i class="ri-save-3-line me-1" aria-hidden="true"></i> Save
                                                </button>
                                            </form>

                                            <form method="post" class="mb-3">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                                <input type="hidden" name="action" value="save_reverse_class_record_credit_cost">
                                                <div class="mb-2">
                                                    <label class="form-label mb-1">Credit Cost Per Generation</label>
                                                    <input
                                                        class="form-control"
                                                        type="number"
                                                        min="0"
                                                        max="100"
                                                        step="0.01"
                                                        name="reverse_class_record_credit_cost"
                                                        value="<?php echo number_format((float) $reverseClassRecordCreditCost, 2, '.', ''); ?>"
                                                        <?php echo $isSuperadmin ? '' : 'disabled'; ?>
                                                        required>
                                                    <div class="form-text">Charged whenever a teacher generates or regenerates a Reverse Class Record composition.</div>
                                                </div>
                                                <button class="btn btn-outline-primary" type="submit" <?php echo $isSuperadmin ? '' : 'disabled'; ?>>
                                                    <i class="ri-coin-line me-1" aria-hidden="true"></i> Save Credit Cost
                                                </button>
                                            </form>

                                            <hr class="my-3">

                                            <form method="post" class="d-flex flex-wrap gap-2 mb-3">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                                <input type="hidden" name="action" value="set_reverse_class_record_teacher_access_all">
                                                <button class="btn btn-sm btn-outline-success" type="submit" name="reverse_class_record_bulk_mode" value="enable_all">
                                                    <i class="ri-check-double-line me-1" aria-hidden="true"></i> Enable All Teachers
                                                </button>
                                                <button class="btn btn-sm btn-outline-secondary" type="submit" name="reverse_class_record_bulk_mode" value="disable_all">
                                                    <i class="ri-forbid-2-line me-1" aria-hidden="true"></i> Disable All Teachers
                                                </button>
                                            </form>

                                            <form method="post">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                                <input type="hidden" name="action" value="save_reverse_class_record_teacher_access">

                                                <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
                                                    <div class="fw-semibold">Teacher Access</div>
                                                    <div class="small text-muted">
                                                        Enabled <?php echo (int) $reverseClassRecordTeachersEnabledCount; ?> / <?php echo (int) count($reverseClassRecordTeacherRows); ?>
                                                    </div>
                                                </div>

                                                <?php if (count($reverseClassRecordTeacherRows) === 0): ?>
                                                    <div class="alert alert-warning py-2 mb-3">No teacher accounts found.</div>
                                                <?php else: ?>
                                                    <div class="table-responsive border rounded mb-3">
                                                        <table class="table table-sm table-striped align-middle mb-0">
                                                            <thead class="table-light">
                                                                <tr>
                                                                    <th>Teacher</th>
                                                                    <th>Account</th>
                                                                    <th>Status</th>
                                                                    <th class="text-center">Allow Reverse</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <?php foreach ($reverseClassRecordTeacherRows as $teacherRow): ?>
                                                                    <?php
                                                                        $teacherUserId = (int) ($teacherRow['id'] ?? 0);
                                                                        if ($teacherUserId <= 0) continue;
                                                                    ?>
                                                                    <tr>
                                                                        <td>
                                                                            <div class="fw-semibold"><?php echo htmlspecialchars((string) ($teacherRow['display_name'] ?? 'Teacher')); ?></div>
                                                                            <div class="small text-muted">#<?php echo $teacherUserId; ?></div>
                                                                        </td>
                                                                        <td>
                                                                            <div><?php echo htmlspecialchars((string) ($teacherRow['username'] ?? '')); ?></div>
                                                                            <div class="small text-muted"><?php echo htmlspecialchars((string) ($teacherRow['useremail'] ?? '')); ?></div>
                                                                        </td>
                                                                        <td>
                                                                            <?php if ((int) ($teacherRow['is_active'] ?? 0) === 1): ?>
                                                                                <span class="badge bg-success-subtle text-success">Active</span>
                                                                            <?php else: ?>
                                                                                <span class="badge bg-secondary-subtle text-secondary">Inactive</span>
                                                                            <?php endif; ?>
                                                                        </td>
                                                                        <td class="text-center">
                                                                            <input type="hidden" name="reverse_class_record_teacher_ids[]" value="<?php echo $teacherUserId; ?>">
                                                                            <div class="form-check form-switch d-inline-block m-0">
                                                                                <input
                                                                                    class="form-check-input"
                                                                                    type="checkbox"
                                                                                    role="switch"
                                                                                    name="reverse_class_record_teacher_enabled[]"
                                                                                    value="<?php echo $teacherUserId; ?>"
                                                                                    <?php echo ((int) ($teacherRow['reverse_enabled'] ?? 0) === 1) ? 'checked' : ''; ?>
                                                                                >
                                                                            </div>
                                                                        </td>
                                                                    </tr>
                                                                <?php endforeach; ?>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                <?php endif; ?>

                                                <button class="btn btn-primary" type="submit">
                                                    <i class="ri-save-3-line me-1" aria-hidden="true"></i> Save Teacher Access
                                                </button>
                                            </form>
                                        </div>
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

    <!-- Edit AY Modal -->
    <div class="modal fade" id="editAyModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-primary">
                <form method="post">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title"><i class="ri-calendar-line me-1" aria-hidden="true"></i> Edit Academic Year</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                        <input type="hidden" name="action" value="edit_ay">
                        <input type="hidden" name="ay_id" id="editAyId" value="">
                        <div class="mb-3">
                            <label class="form-label">Name</label>
                            <input class="form-control" name="ay_name" id="editAyName" required maxlength="32">
                        </div>
                        <div class="mb-0">
                            <label class="form-label">Sort Order</label>
                            <input class="form-control" name="sort_order" id="editAySort" type="number" value="0">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Semester Modal -->
    <div class="modal fade" id="editSemModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-primary">
                <form method="post">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title"><i class="ri-timer-2-line me-1" aria-hidden="true"></i> Edit Semester</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                        <input type="hidden" name="action" value="edit_sem">
                        <input type="hidden" name="sem_id" id="editSemId" value="">
                        <div class="mb-3">
                            <label class="form-label">Name</label>
                            <input class="form-control" name="sem_name" id="editSemName" required maxlength="32">
                        </div>
                        <div class="mb-0">
                            <label class="form-label">Sort Order</label>
                            <input class="form-control" name="sort_order" id="editSemSort" type="number" value="0">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const ayModal = document.getElementById('editAyModal');
            if (ayModal) {
                ayModal.addEventListener('show.bs.modal', (event) => {
                    const btn = event.relatedTarget;
                    if (!btn) return;
                    document.getElementById('editAyId').value = btn.getAttribute('data-id') || '';
                    document.getElementById('editAyName').value = btn.getAttribute('data-name') || '';
                    document.getElementById('editAySort').value = btn.getAttribute('data-sort') || '0';
                });
            }

            const semModal = document.getElementById('editSemModal');
            if (semModal) {
                semModal.addEventListener('show.bs.modal', (event) => {
                    const btn = event.relatedTarget;
                    if (!btn) return;
                    document.getElementById('editSemId').value = btn.getAttribute('data-id') || '';
                    document.getElementById('editSemName').value = btn.getAttribute('data-name') || '';
                    document.getElementById('editSemSort').value = btn.getAttribute('data-sort') || '0';
                });
            }
        })();
    </script>
</body>
</html>
