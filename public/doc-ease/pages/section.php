<?php include '../layouts/session.php'; ?>
<?php require_role('admin'); ?>
<?php include '../layouts/main.php'; ?>
<?php include_once '../includes/reference.php'; ?>
<?php
ensure_reference_tables($conn);
$profileSyncStats = function_exists('ref_sync_profile_sections_from_students') ? ref_sync_profile_sections_from_students($conn) : ['inserted' => 0, 'updated' => 0];
$classSyncStats = function_exists('ref_sync_class_sections_from_records') ? ref_sync_class_sections_from_records($conn) : ['inserted' => 0, 'updated' => 0];

$flashError = isset($_SESSION['error']) ? (string) $_SESSION['error'] : '';
$flashSuccess = isset($_SESSION['success']) ? (string) $_SESSION['success'] : '';
unset($_SESSION['error'], $_SESSION['success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    if (!csrf_validate($csrf)) {
        $_SESSION['error'] = 'Security check failed (CSRF). Please try again.';
        header('Location: section.php');
        exit;
    }

    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'create' || $action === 'update') {
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
        $description = trim((string) ($_POST['description'] ?? ''));
        $status = trim((string) ($_POST['status'] ?? 'active'));
        if (!in_array($status, ['active', 'inactive'], true)) $status = 'active';

        if ($code === '' || !function_exists('ref_is_if_section') || !ref_is_if_section($code)) {
            $_SESSION['error'] = 'Class section must use IF format (example: IF-2-B-6).';
        } elseif ($action === 'create') {
            $stmt = $conn->prepare("INSERT INTO class_sections (code, description, status, source) VALUES (?, ?, ?, 'manual')");
            if ($stmt) {
                try {
                    $stmt->bind_param('sss', $code, $description, $status);
                    $stmt->execute();
                    $_SESSION['success'] = 'Class section added.';
                } catch (mysqli_sql_exception $e) {
                    $_SESSION['error'] = ((int) $e->getCode() === 1062) ? 'Class section already exists.' : ('Add failed: ' . $e->getMessage());
                }
                $stmt->close();
            }
        } elseif ($id > 0) {
            $stmt = $conn->prepare("UPDATE class_sections SET code = ?, description = ?, status = ? WHERE id = ?");
            if ($stmt) {
                try {
                    $stmt->bind_param('sssi', $code, $description, $status, $id);
                    $stmt->execute();
                    $_SESSION['success'] = 'Class section updated.';
                } catch (mysqli_sql_exception $e) {
                    $_SESSION['error'] = ((int) $e->getCode() === 1062) ? 'Class section code already exists.' : ('Update failed: ' . $e->getMessage());
                }
                $stmt->close();
            }
        } else {
            $_SESSION['error'] = 'Invalid class section id.';
        }
    } elseif ($action === 'delete') {
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if ($id <= 0) {
            $_SESSION['error'] = 'Invalid class section id.';
        } else {
            $stmt = $conn->prepare("DELETE FROM class_sections WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $_SESSION['success'] = 'Class section deleted.';
                $stmt->close();
            }
        }
    } elseif ($action === 'assign') {
        $classSectionId = isset($_POST['class_section_id']) ? (int) $_POST['class_section_id'] : 0;
        $subjectId = isset($_POST['subject_id']) ? (int) $_POST['subject_id'] : 0;
        if ($classSectionId <= 0 || $subjectId <= 0) {
            $_SESSION['error'] = 'Class section and subject are required.';
        } else {
            $stmt = $conn->prepare("INSERT INTO class_section_subjects (class_section_id, subject_id) VALUES (?, ?)");
            if ($stmt) {
                try {
                    $stmt->bind_param('ii', $classSectionId, $subjectId);
                    $stmt->execute();
                    $_SESSION['success'] = 'Subject assigned.';
                } catch (mysqli_sql_exception $e) {
                    $_SESSION['error'] = ((int) $e->getCode() === 1062) ? 'Subject already assigned to this class section.' : ('Assign failed: ' . $e->getMessage());
                }
                $stmt->close();
            }
        }
    } elseif ($action === 'unassign') {
        $mapId = isset($_POST['map_id']) ? (int) $_POST['map_id'] : 0;
        if ($mapId > 0) {
            $stmt = $conn->prepare("DELETE FROM class_section_subjects WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('i', $mapId);
                $stmt->execute();
                $_SESSION['success'] = 'Assignment removed.';
                $stmt->close();
            }
        }
    }

    header('Location: section.php');
    exit;
}

$classSections = [];
$resSections = $conn->query("SELECT id, code, description, status, created_at FROM class_sections ORDER BY code ASC");
if ($resSections) while ($r = $resSections->fetch_assoc()) $classSections[] = $r;

$subjects = [];
$resSubjects = $conn->query("SELECT id, subject_code, subject_name FROM subjects WHERE status = 'active' ORDER BY subject_name ASC");
if ($resSubjects) while ($r = $resSubjects->fetch_assoc()) $subjects[] = $r;

$assignments = [];
$resAssignments = $conn->query(
    "SELECT css.id AS map_id, cs.code, s.subject_name, s.subject_code
     FROM class_section_subjects css
     JOIN class_sections cs ON cs.id = css.class_section_id
     JOIN subjects s ON s.id = css.subject_id
     ORDER BY cs.code ASC, s.subject_name ASC"
);
if ($resAssignments) while ($r = $resAssignments->fetch_assoc()) $assignments[] = $r;

$rosterCounts = [];
$rosterRes = $conn->query(
    "SELECT cr.section, COUNT(DISTINCT ce.student_id) AS c
     FROM class_enrollments ce
     JOIN class_records cr ON cr.id = ce.class_record_id
     WHERE ce.status = 'enrolled'
       AND cr.status = 'active'
       AND cr.section IS NOT NULL AND cr.section <> ''
     GROUP BY cr.section"
);
if ($rosterRes) {
    while ($r = $rosterRes->fetch_assoc()) {
        $k = strtoupper(trim((string) ($r['section'] ?? '')));
        if ($k !== '') $rosterCounts[$k] = (int) ($r['c'] ?? 0);
    }
}

$profileSections = function_exists('ref_list_student_section_profiles') ? ref_list_student_section_profiles($conn) : [];
usort($profileSections, static function ($a, $b) {
    return strtolower((string) ($a['label'] ?? '')) <=> strtolower((string) ($b['label'] ?? ''));
});

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editing = null;
if ($editId > 0) {
    foreach ($classSections as $row) {
        if ((int) ($row['id'] ?? 0) === $editId) {
            $editing = $row;
            break;
        }
    }
}
?>

<head>
    <title>Sections | E-Record</title>
    <?php include '../layouts/title-meta.php'; ?>
    <?php include '../layouts/head-css.php'; ?>
    <link rel="stylesheet" href="assets/css/admin-ops-ui.css">
    <style>
        .sections-hero {
            background: linear-gradient(140deg, #1e293b 0%, #1d4d8f 52%, #0f766e 100%);
        }

        .sections-hero::after {
            content: "";
            position: absolute;
            inset: 0;
            background:
                radial-gradient(52% 68% at 80% 18%, rgba(255, 255, 255, 0.1) 0%, rgba(255, 255, 255, 0) 65%),
                repeating-linear-gradient(
                    135deg,
                    rgba(255, 255, 255, 0.05) 0px,
                    rgba(255, 255, 255, 0.05) 1px,
                    rgba(255, 255, 255, 0) 9px,
                    rgba(255, 255, 255, 0) 20px
                );
            opacity: 0.45;
            pointer-events: none;
        }

        .section-actions-cell {
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
                <div class="page-title-box">
                    <h4 class="page-title">Sections</h4>
                </div>

                <div class="ops-hero sections-hero ops-page-shell" data-ops-parallax>
                    <div class="ops-hero__bg" data-ops-parallax-layer aria-hidden="true"></div>
                    <div class="ops-hero__content">
                        <div class="d-flex align-items-end justify-content-between flex-wrap gap-2">
                            <div>
                                <div class="ops-hero__kicker">Administration</div>
                                <h1 class="ops-hero__title h3">Section Manager</h1>
                                <div class="ops-hero__subtitle">
                                    Maintain IF-format class sections and control the subject mapping for each section profile.
                                </div>
                            </div>
                            <div class="ops-hero__chips">
                                <div class="ops-chip">
                                    <span>Class Sections</span>
                                    <strong><?php echo (int) count($classSections); ?></strong>
                                </div>
                                <div class="ops-chip">
                                    <span>Assignments</span>
                                    <strong><?php echo (int) count($assignments); ?></strong>
                                </div>
                                <div class="ops-chip">
                                    <span>Profiles</span>
                                    <strong><?php echo (int) count($profileSections); ?></strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($flashError !== ''): ?><div class="alert alert-danger"><?php echo htmlspecialchars($flashError); ?></div><?php endif; ?>
                <?php if ($flashSuccess !== ''): ?><div class="alert alert-success"><?php echo htmlspecialchars($flashSuccess); ?></div><?php endif; ?>

                <div class="alert alert-info">
                    Split model enabled: <strong>Class Sections</strong> (IF codes) are separate from <strong>Profile Sections</strong> (Course/Year/Section).
                    Sync changes: profile +<?php echo (int) ($profileSyncStats['inserted'] ?? 0); ?>/<?php echo (int) ($profileSyncStats['updated'] ?? 0); ?>, class +<?php echo (int) ($classSyncStats['inserted'] ?? 0); ?>/<?php echo (int) ($classSyncStats['updated'] ?? 0); ?>.
                </div>

                <div class="row g-3">
                    <div class="col-lg-6">
                        <div class="card ops-card ops-page-shell">
                            <div class="card-body">
                            <h4 class="header-title"><?php echo $editing ? 'Edit Class Section' : 'Add Class Section'; ?></h4>
                            <form method="post" action="section.php">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES); ?>">
                                <input type="hidden" name="action" value="<?php echo $editing ? 'update' : 'create'; ?>">
                                <?php if ($editing): ?><input type="hidden" name="id" value="<?php echo (int) ($editing['id'] ?? 0); ?>"><?php endif; ?>
                                <div class="ops-form-row">
                                    <label class="form-label">Class Section Code</label>
                                    <input
                                        class="form-control"
                                        name="code"
                                        required
                                        placeholder="IF-2-B-6"
                                        value="<?php echo htmlspecialchars((string) ($editing['code'] ?? '')); ?>"
                                    >
                                </div>
                                <div class="ops-form-row">
                                    <label class="form-label">Description</label>
                                    <textarea class="form-control" name="description" rows="2"><?php echo htmlspecialchars((string) ($editing['description'] ?? '')); ?></textarea>
                                </div>
                                <div class="ops-form-row">
                                    <label class="form-label d-block">Status</label>
                                    <?php $st = (string) ($editing['status'] ?? 'active'); ?>
                                    <div class="ops-choice-group">
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" id="sectionStatusActive" type="radio" name="status" value="active" <?php echo $st === 'inactive' ? '' : 'checked'; ?>>
                                            <label class="form-check-label" for="sectionStatusActive">Active</label>
                                        </div>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" id="sectionStatusInactive" type="radio" name="status" value="inactive" <?php echo $st === 'inactive' ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="sectionStatusInactive">Inactive</label>
                                        </div>
                                    </div>
                                </div>
                                <div class="ops-actions">
                                    <button class="btn btn-primary" type="submit">
                                        <?php echo $editing ? 'Save Changes' : 'Add Section'; ?>
                                    </button>
                                    <?php if ($editing): ?><a class="btn btn-light" href="section.php">Cancel</a><?php endif; ?>
                                </div>
                            </form>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6">
                        <div class="card ops-card ops-page-shell">
                            <div class="card-body">
                            <h4 class="header-title">Assign Subject to Class Section</h4>
                            <form method="post" action="section.php">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES); ?>">
                                <input type="hidden" name="action" value="assign">
                                <div class="ops-form-row">
                                    <label class="form-label">Class Section</label>
                                    <select class="form-select" name="class_section_id" required>
                                        <option value="">Select</option>
                                        <?php foreach ($classSections as $sec): ?>
                                            <option value="<?php echo (int) ($sec['id'] ?? 0); ?>"><?php echo htmlspecialchars((string) ($sec['code'] ?? '')); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="ops-form-row">
                                    <label class="form-label">Subject</label>
                                    <select class="form-select" name="subject_id" required>
                                        <option value="">Select</option>
                                        <?php foreach ($subjects as $sub): ?>
                                            <option value="<?php echo (int) ($sub['id'] ?? 0); ?>"><?php echo htmlspecialchars((string) ($sub['subject_name'] ?? '') . ' (' . (string) ($sub['subject_code'] ?? '') . ')'); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="ops-actions">
                                    <button class="btn btn-primary" type="submit">Assign Subject</button>
                                </div>
                            </form>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mt-3 ops-card ops-page-shell">
                    <div class="card-body">
                    <h4 class="header-title">Class Sections</h4>
                    <div class="table-responsive"><table class="table table-striped mb-0 ops-table"><thead><tr><th>Code</th><th>Description</th><th>Status</th><th>Enrolled</th><th class="text-end">Action</th></tr></thead><tbody>
                    <?php if (count($classSections) === 0): ?><tr><td colspan="5" class="text-center text-muted">No class sections found.</td></tr><?php endif; ?>
                    <?php foreach ($classSections as $sec): $code = strtoupper(trim((string) ($sec['code'] ?? ''))); ?>
                        <tr>
                            <td><?php echo htmlspecialchars($code); ?></td>
                            <td><?php echo htmlspecialchars((string) ($sec['description'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string) ($sec['status'] ?? 'active')); ?></td>
                            <td><?php echo (int) ($rosterCounts[$code] ?? 0); ?></td>
                            <td class="text-end section-actions-cell">
                                <span class="ops-actions justify-content-end">
                                <a class="btn btn-sm btn-outline-primary" href="section.php?edit=<?php echo (int) ($sec['id'] ?? 0); ?>">
                                    <i class="ri-edit-line me-1" aria-hidden="true"></i>Edit
                                </a>
                                <form method="post" action="section.php" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES); ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo (int) ($sec['id'] ?? 0); ?>">
                                    <button class="btn btn-sm btn-outline-danger" type="submit" onclick="return confirm('Delete this class section?');">
                                        <i class="ri-delete-bin-6-line me-1" aria-hidden="true"></i>Delete
                                    </button>
                                </form>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                    </div>
                </div>

                <div class="card mt-3 ops-card ops-page-shell">
                    <div class="card-body">
                    <h4 class="header-title">Class Section Subject Assignments</h4>
                    <div class="table-responsive"><table class="table table-striped mb-0 ops-table"><thead><tr><th>Class Section</th><th>Subject</th><th>Code</th><th class="text-end">Action</th></tr></thead><tbody>
                    <?php if (count($assignments) === 0): ?><tr><td colspan="4" class="text-center text-muted">No assignments found.</td></tr><?php endif; ?>
                    <?php foreach ($assignments as $a): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string) ($a['code'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string) ($a['subject_name'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string) ($a['subject_code'] ?? '')); ?></td>
                            <td class="text-end section-actions-cell">
                                <span class="ops-actions justify-content-end">
                                <form method="post" action="section.php" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES); ?>">
                                    <input type="hidden" name="action" value="unassign">
                                    <input type="hidden" name="map_id" value="<?php echo (int) ($a['map_id'] ?? 0); ?>">
                                    <button class="btn btn-sm btn-outline-danger" type="submit" onclick="return confirm('Remove this subject assignment?');">
                                        <i class="ri-link-unlink-m me-1" aria-hidden="true"></i>Remove
                                    </button>
                                </form>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                    </div>
                </div>

                <div class="card mt-3 ops-card ops-page-shell">
                    <div class="card-body">
                    <h4 class="header-title">Profile Sections (Read-only)</h4>
                    <div class="table-responsive"><table class="table table-striped mb-0 ops-table"><thead><tr><th>Program</th><th>Year</th><th>Profile Section</th><th>Label</th><th>Students</th></tr></thead><tbody>
                    <?php if (count($profileSections) === 0): ?><tr><td colspan="5" class="text-center text-muted">No profile sections found.</td></tr><?php endif; ?>
                    <?php foreach ($profileSections as $p): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string) ($p['course'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string) ($p['year'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string) ($p['section'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string) ($p['label'] ?? '')); ?></td>
                            <td><?php echo (int) ($p['student_count'] ?? 0); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
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
</body>
</html>
