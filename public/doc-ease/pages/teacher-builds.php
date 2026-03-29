<?php include '../layouts/session.php'; ?>
<?php require_active_role('teacher'); ?>
<?php include '../layouts/main.php'; ?>

<?php
require_once __DIR__ . '/../includes/class_record_builds.php';
require_once __DIR__ . '/../includes/usage_limits.php';
ensure_users_build_limit_column($conn);
ensure_class_record_build_tables($conn);
usage_limit_ensure_system($conn);

$flash = isset($_SESSION['flash_message']) ? (string) $_SESSION['flash_message'] : '';
$flashType = isset($_SESSION['flash_type']) ? (string) $_SESSION['flash_type'] : 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

$teacherId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;

$limit = 0;
$usedCount = 0;
$isUnlimited = false;

function build_count(mysqli $conn, $teacherId) {
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM class_record_builds WHERE teacher_id = ?");
    if (!$stmt) return 0;
    $stmt->bind_param('i', $teacherId);
    $stmt->execute();
    $res = $stmt->get_result();
    $c = 0;
    if ($res && $res->num_rows === 1) $c = (int) ($res->fetch_assoc()['c'] ?? 0);
    $stmt->close();
    return $c;
}

[$okBuildStatus, $buildStatusOrMsg] = usage_limit_get_build_status($conn, $teacherId);
if ($okBuildStatus && is_array($buildStatusOrMsg)) {
    $limit = (int) ($buildStatusOrMsg['limit'] ?? 0);
    if ($limit < 0) $limit = 0;
    $usedCount = (int) ($buildStatusOrMsg['used'] ?? 0);
    if ($usedCount < 0) $usedCount = 0;
    $isUnlimited = (bool) ($buildStatusOrMsg['is_unlimited'] ?? false);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    if (!csrf_validate($csrf)) {
        $_SESSION['flash_message'] = 'Security check failed (CSRF). Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: teacher-builds.php');
        exit;
    }

    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';
    $buildId = isset($_POST['build_id']) ? (int) $_POST['build_id'] : 0;

    if ($buildId > 0 && $action === 'toggle') {
        $stmt = $conn->prepare("UPDATE class_record_builds SET status = IF(status='active','inactive','active') WHERE id = ? AND teacher_id = ?");
        if ($stmt) {
            $stmt->bind_param('ii', $buildId, $teacherId);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash_message'] = 'Build updated.';
            $_SESSION['flash_type'] = 'success';
        }
        header('Location: teacher-builds.php');
        exit;
    }

    if ($buildId > 0 && $action === 'delete') {
        $stmt = $conn->prepare("DELETE FROM class_record_builds WHERE id = ? AND teacher_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('ii', $buildId, $teacherId);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash_message'] = 'Build deleted.';
            $_SESSION['flash_type'] = 'success';
        }
        header('Location: teacher-builds.php');
        exit;
    }

    if ($buildId > 0 && $action === 'clone') {
        $conn->begin_transaction();
        try {
            $src = $conn->prepare("SELECT name, description FROM class_record_builds WHERE id = ? AND teacher_id = ? LIMIT 1");
            if (!$src) throw new Exception('Unable to read build.');
            $src->bind_param('ii', $buildId, $teacherId);
            $src->execute();
            $res = $src->get_result();
            $row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
            $src->close();
            if (!$row) throw new Exception('Build not found.');

            $newName = 'Copy of ' . (string) ($row['name'] ?? 'Build');
            if (strlen($newName) > 120) $newName = substr($newName, 0, 120);
            $desc = (string) ($row['description'] ?? '');

            [$okConsume, $consumeMsg] = usage_limit_try_consume_build($conn, $teacherId, 1);
            if (!$okConsume) {
                $msg = is_string($consumeMsg) ? $consumeMsg : 'Build limit reached.';
                throw new RuntimeException($msg);
            }

            $ins = $conn->prepare("INSERT INTO class_record_builds (teacher_id, name, description, status) VALUES (?, ?, ?, 'active')");
            if (!$ins) throw new Exception('Unable to create build.');
            $ins->bind_param('iss', $teacherId, $newName, $desc);
            $ins->execute();
            $newBuildId = (int) $conn->insert_id;
            $ins->close();

            // Copy parameters.
            $params = [];
            $p = $conn->prepare("SELECT id, term, name, weight, display_order FROM class_record_build_parameters WHERE build_id = ? ORDER BY term ASC, display_order ASC, id ASC");
            if (!$p) throw new Exception('Unable to read parameters.');
            $p->bind_param('i', $buildId);
            $p->execute();
            $pr = $p->get_result();
            while ($pr && ($r = $pr->fetch_assoc())) $params[] = $r;
            $p->close();

            $map = []; // old_param_id => new_param_id
            $insP = $conn->prepare("INSERT INTO class_record_build_parameters (build_id, term, name, weight, display_order) VALUES (?, ?, ?, ?, ?)");
            if (!$insP) throw new Exception('Unable to create parameters.');
            foreach ($params as $pp) {
                $term = (string) ($pp['term'] ?? 'midterm');
                $name = (string) ($pp['name'] ?? '');
                $weight = (float) ($pp['weight'] ?? 0);
                $order = (int) ($pp['display_order'] ?? 0);
                $insP->bind_param('issdi', $newBuildId, $term, $name, $weight, $order);
                $insP->execute();
                $map[(int) $pp['id']] = (int) $conn->insert_id;
            }
            $insP->close();

            // Copy components.
            $c = $conn->prepare("SELECT parameter_id, name, code, component_type, weight, display_order FROM class_record_build_components WHERE parameter_id IN (SELECT id FROM class_record_build_parameters WHERE build_id = ?)");
            if (!$c) throw new Exception('Unable to read components.');
            $c->bind_param('i', $buildId);
            $c->execute();
            $cr = $c->get_result();
            $insC = $conn->prepare("INSERT INTO class_record_build_components (parameter_id, name, code, component_type, weight, display_order) VALUES (?, ?, ?, ?, ?, ?)");
            if (!$insC) throw new Exception('Unable to create components.');
            while ($cr && ($cc = $cr->fetch_assoc())) {
                $oldPid = (int) ($cc['parameter_id'] ?? 0);
                $newPid = isset($map[$oldPid]) ? (int) $map[$oldPid] : 0;
                if ($newPid <= 0) continue;
                $name = (string) ($cc['name'] ?? '');
                $code = (string) ($cc['code'] ?? '');
                $type = (string) ($cc['component_type'] ?? 'other');
                $weight = (float) ($cc['weight'] ?? 0);
                $order = (int) ($cc['display_order'] ?? 0);
                $insC->bind_param('isssdi', $newPid, $name, $code, $type, $weight, $order);
                $insC->execute();
            }
            $insC->close();
            $c->close();

            $conn->commit();
            $_SESSION['flash_message'] = 'Build cloned.';
            $_SESSION['flash_type'] = 'success';
        } catch (Throwable $e) {
            $conn->rollback();
            $message = trim((string) $e->getMessage());
            if (stripos($message, 'limit') !== false) {
                $_SESSION['flash_message'] = $message;
                $_SESSION['flash_type'] = 'warning';
            } else {
                error_log('[teacher-builds] clone failed: ' . $e->getMessage());
                $_SESSION['flash_message'] = 'Clone failed.';
                $_SESSION['flash_type'] = 'danger';
            }
        }

        header('Location: teacher-builds.php');
        exit;
    }

    $_SESSION['flash_message'] = 'Invalid request.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: teacher-builds.php');
    exit;
}

$builds = [];
$stmt = $conn->prepare("SELECT id, name, description, status, created_at FROM class_record_builds WHERE teacher_id = ? ORDER BY created_at DESC, id DESC");
if ($stmt) {
    $stmt->bind_param('i', $teacherId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($r = $res->fetch_assoc())) $builds[] = $r;
    $stmt->close();
}

$totalBuilds = build_count($conn, $teacherId);
if ($okBuildStatus && is_array($buildStatusOrMsg)) {
    $totalBuilds = (int) ($buildStatusOrMsg['total_builds'] ?? $totalBuilds);
} else {
    $usedCount = $totalBuilds;
    $isUnlimited = $limit === 0;
}
?>

<head>
    <title>Class Record Builds | E-Record</title>
    <?php include '../layouts/title-meta.php'; ?>
    <?php include '../layouts/head-css.php'; ?>
    <link rel="stylesheet" href="assets/css/admin-ops-ui.css">
    <style>
        .tb-hero {
            background: linear-gradient(140deg, #17213b 0%, #24498f 54%, #0f766e 100%);
        }

        .tb-hero::after {
            content: "";
            position: absolute;
            inset: 0;
            background: repeating-linear-gradient(
                140deg,
                rgba(255, 255, 255, 0.06) 0px,
                rgba(255, 255, 255, 0.06) 1px,
                rgba(255, 255, 255, 0) 10px,
                rgba(255, 255, 255, 0) 20px
            );
            opacity: 0.35;
            pointer-events: none;
        }

        .tb-actions-cell {
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
                                        <li class="breadcrumb-item"><a href="teacher-dashboard.php">Teacher</a></li>
                                        <li class="breadcrumb-item active">Class Record Builds</li>
                                    </ol>
                                </div>
                                <h4 class="page-title">Class Record Builds</h4>
                            </div>
                        </div>
                    </div>

                    <?php if ($flash): ?>
                        <div class="alert alert-<?php echo htmlspecialchars($flashType); ?>"><?php echo htmlspecialchars($flash); ?></div>
                    <?php endif; ?>

                    <div class="ops-hero tb-hero ops-page-shell" data-ops-parallax>
                        <div class="ops-hero__bg" data-ops-parallax-layer aria-hidden="true"></div>
                        <div class="ops-hero__content">
                            <div class="d-flex align-items-end justify-content-between flex-wrap gap-2">
                                <div>
                                    <div class="ops-hero__kicker">Teacher Workspace</div>
                                    <h1 class="ops-hero__title h3">Class Record Builds</h1>
                                    <div class="ops-hero__subtitle">
                                        Reusable templates for grading components and weights across all your handled classes.
                                    </div>
                                </div>
                                <div class="ops-hero__chips">
                                    <div class="ops-chip">
                                        <span>Limit</span>
                                        <strong><?php echo $isUnlimited ? 'Unlimited' : (int) $limit; ?></strong>
                                    </div>
                                    <div class="ops-chip">
                                        <span>Used</span>
                                        <strong><?php echo (int) $usedCount; ?></strong>
                                    </div>
                                    <div class="ops-chip">
                                        <span>Saved</span>
                                        <strong><?php echo (int) $totalBuilds; ?></strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-xl-8">
                            <div class="card ops-card ops-page-shell">
                                <div class="card-body">
                                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                                        <div>
                                            <h4 class="header-title mb-0">Your Builds</h4>
                                            <div class="text-muted small">Teacher-owned templates you can reuse across any subjects/classes you handle (not limited to the subject where you created it).</div>
                                        </div>
                                        <div class="text-muted small">
                                            Limit: <strong><?php echo $isUnlimited ? 'Unlimited' : (int) $limit; ?></strong> |
                                            Used: <strong><?php echo (int) $usedCount; ?></strong> |
                                            Saved Builds: <strong><?php echo (int) $totalBuilds; ?></strong>
                                        </div>
                                    </div>

                                    <div class="table-responsive mt-2">
                                        <table class="table table-sm table-striped table-hover align-middle mb-0 ops-table">
                                            <thead>
                                                <tr>
                                                    <th>Name</th>
                                                    <th>Status</th>
                                                    <th>Created</th>
                                                    <th class="text-end">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (count($builds) === 0): ?>
                                                    <tr>
                                                        <td colspan="4" class="text-center text-muted">No builds yet.</td>
                                                    </tr>
                                                <?php endif; ?>
                                                <?php foreach ($builds as $b): ?>
                                                    <tr>
                                                        <td>
                                                            <div class="fw-semibold"><?php echo htmlspecialchars((string) ($b['name'] ?? '')); ?></div>
                                                            <div class="text-muted small"><?php echo htmlspecialchars((string) ($b['description'] ?? '')); ?></div>
                                                        </td>
                                                        <td>
                                                            <?php if (((string) ($b['status'] ?? '')) === 'active'): ?>
                                                                <span class="badge bg-success">Active</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-secondary">Inactive</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="text-muted small"><?php echo htmlspecialchars((string) ($b['created_at'] ?? '')); ?></td>
                                                        <td class="text-end tb-actions-cell">
                                                            <span class="ops-actions justify-content-end">
                                                            <form method="post" class="d-inline">
                                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                                                <input type="hidden" name="action" value="clone">
                                                                <input type="hidden" name="build_id" value="<?php echo (int) ($b['id'] ?? 0); ?>">
                                                                <button class="btn btn-sm btn-outline-primary" type="submit">
                                                                    <i class="ri-file-copy-line me-1" aria-hidden="true"></i>
                                                                    Clone
                                                                </button>
                                                            </form>
                                                            <form method="post" class="d-inline">
                                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                                                <input type="hidden" name="action" value="toggle">
                                                                <input type="hidden" name="build_id" value="<?php echo (int) ($b['id'] ?? 0); ?>">
                                                                <button class="btn btn-sm btn-outline-secondary" type="submit">
                                                                    <i class="ri-swap-line me-1" aria-hidden="true"></i>
                                                                    Toggle
                                                                </button>
                                                            </form>
                                                            <form method="post" class="d-inline">
                                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                                                <input type="hidden" name="action" value="delete">
                                                                <input type="hidden" name="build_id" value="<?php echo (int) ($b['id'] ?? 0); ?>">
                                                                <button class="btn btn-sm btn-outline-danger" type="submit" onclick="return confirm('Delete this build?');">
                                                                    <i class="ri-delete-bin-6-line me-1" aria-hidden="true"></i>
                                                                    Delete
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

                        <div class="col-xl-4">
                            <div class="card ops-card ops-page-shell">
                                <div class="card-body">
                                    <h4 class="header-title">How To Create Builds</h4>
                                    <div class="text-muted small">
                                        1. Go to <code>Teacher Dashboard</code>.<br>
                                        2. Click <code>Components &amp; Weights</code> for a class.<br>
                                        3. Configure Midterm/Final term components.<br>
                                        4. Save the build from that page, then reuse it for other classes.
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
    <script src="assets/js/admin-ops-ui.js"></script>
</body>
</html>
