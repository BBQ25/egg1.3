<?php include '../layouts/session.php'; ?>
<?php require_role('admin'); ?>

<?php
require_once __DIR__ . '/../includes/attendance_geofence.php';
require_once __DIR__ . '/../includes/audit.php';
attendance_geo_ensure_table($conn);
ensure_audit_logs_table($conn);

$adminId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$isSuperadmin = current_user_is_superadmin();
$adminCampusId = current_user_campus_id();
if (!$isSuperadmin && $adminCampusId <= 0) {
    deny_access(403, 'Campus admin account has no campus assignment.');
}

$flash = isset($_SESSION['flash_message']) ? (string) $_SESSION['flash_message'] : '';
$flashType = isset($_SESSION['flash_type']) ? (string) $_SESSION['flash_type'] : 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

if (!function_exists('ageo_h')) {
    function ageo_h($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('ageo_flash_class')) {
    function ageo_flash_class($type) {
        $t = strtolower(trim((string) $type));
        if ($t === 'danger' || $t === 'error') return 'alert-danger';
        if ($t === 'warning') return 'alert-warning';
        if ($t === 'info') return 'alert-info';
        return 'alert-success';
    }
}

$campuses = [];
if ($isSuperadmin) {
    $res = $conn->query(
        "SELECT id, campus_code, campus_name, is_active
         FROM campuses
         ORDER BY campus_name ASC, id ASC"
    );
    while ($res && ($r = $res->fetch_assoc())) {
        $campuses[] = [
            'id' => (int) ($r['id'] ?? 0),
            'campus_code' => (string) ($r['campus_code'] ?? ''),
            'campus_name' => (string) ($r['campus_name'] ?? ''),
            'is_active' => (int) ($r['is_active'] ?? 0),
        ];
    }
} else {
    $stmt = $conn->prepare(
        "SELECT id, campus_code, campus_name, is_active
         FROM campuses
         WHERE id = ?
         LIMIT 1"
    );
    if ($stmt) {
        $stmt->bind_param('i', $adminCampusId);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows === 1) {
            $r = $res->fetch_assoc();
            $campuses[] = [
                'id' => (int) ($r['id'] ?? 0),
                'campus_code' => (string) ($r['campus_code'] ?? ''),
                'campus_name' => (string) ($r['campus_name'] ?? ''),
                'is_active' => (int) ($r['is_active'] ?? 0),
            ];
        }
        $stmt->close();
    }
}

$campusMap = [];
foreach ($campuses as $c) {
    $cid = (int) ($c['id'] ?? 0);
    if ($cid > 0) $campusMap[$cid] = $c;
}

$selectedCampusId = $isSuperadmin
    ? (isset($_GET['campus_id']) ? (int) $_GET['campus_id'] : 0)
    : $adminCampusId;

if (!$isSuperadmin) {
    $selectedCampusId = $adminCampusId;
}
if ($selectedCampusId <= 0 || !isset($campusMap[$selectedCampusId])) {
    $selectedCampusId = count($campuses) > 0 ? (int) ($campuses[0]['id'] ?? 0) : 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    if (!csrf_validate($csrf)) {
        $_SESSION['flash_message'] = 'Security check failed (CSRF). Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: admin-attendance-boundary.php' . ($isSuperadmin && $selectedCampusId > 0 ? '?campus_id=' . (int) $selectedCampusId : ''));
        exit;
    }

    $action = isset($_POST['action']) ? trim((string) $_POST['action']) : '';
    $isCloneAction = ($action === 'clone_policy');
    if ($action !== 'save_policy' && !$isCloneAction) {
        $_SESSION['flash_message'] = 'Invalid request.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: admin-attendance-boundary.php' . ($isSuperadmin && $selectedCampusId > 0 ? '?campus_id=' . (int) $selectedCampusId : ''));
        exit;
    }
    if ($isCloneAction && !$isSuperadmin) {
        deny_access(403, 'Only superadmin can clone campus boundaries.');
    }

    $targetCampusId = $isSuperadmin
        ? (int) ($_POST['campus_id'] ?? 0)
        : $adminCampusId;

    if ($targetCampusId <= 0 || !isset($campusMap[$targetCampusId])) {
        $_SESSION['flash_message'] = 'Select a valid campus.';
        $_SESSION['flash_type'] = 'warning';
        header('Location: admin-attendance-boundary.php' . ($isSuperadmin && $selectedCampusId > 0 ? '?campus_id=' . (int) $selectedCampusId : ''));
        exit;
    }
    if (!$isSuperadmin && !current_user_can_manage_campus($targetCampusId)) {
        deny_access(403, 'Campus scope violation.');
    }

    if ($isCloneAction) {
        $sourceCampusId = (int) ($_POST['source_campus_id'] ?? 0);
        if ($sourceCampusId <= 0 || !isset($campusMap[$sourceCampusId])) {
            $_SESSION['flash_message'] = 'Select a valid source campus to clone from.';
            $_SESSION['flash_type'] = 'warning';
            $selectedCampusId = $targetCampusId;
        } elseif ($sourceCampusId === $targetCampusId) {
            $_SESSION['flash_message'] = 'Source and target campus cannot be the same.';
            $_SESSION['flash_type'] = 'warning';
            $selectedCampusId = $targetCampusId;
        } else {
            $sourcePolicy = attendance_geo_get_policy($conn, $sourceCampusId);
            [$ok, $saveResult] = attendance_geo_save_policy($conn, $targetCampusId, $sourcePolicy, $adminId, true);
            if ($ok) {
                $saved = is_array($saveResult) ? $saveResult : attendance_geo_get_policy($conn, $targetCampusId);
                $sourceLabel = trim((string) (($campusMap[$sourceCampusId]['campus_code'] ?? '') . ' - ' . ($campusMap[$sourceCampusId]['campus_name'] ?? '')));
                $targetLabel = trim((string) (($campusMap[$targetCampusId]['campus_code'] ?? '') . ' - ' . ($campusMap[$targetCampusId]['campus_name'] ?? '')));
                if ($sourceLabel === '') $sourceLabel = 'Campus #' . $sourceCampusId;
                if ($targetLabel === '') $targetLabel = 'Campus #' . $targetCampusId;

                $msg = 'Attendance boundary cloned from ' . $sourceLabel . ' to ' . $targetLabel . '. Superadmin override applied.';
                $_SESSION['flash_message'] = $msg;
                $_SESSION['flash_type'] = 'success';
                audit_log(
                    $conn,
                    'attendance.geofence.policy.cloned',
                    'campus',
                    $targetCampusId,
                    $msg,
                    [
                        'source_campus_id' => $sourceCampusId,
                        'target_campus_id' => $targetCampusId,
                        'geofence_enabled' => !empty($saved['geofence_enabled']) ? 1 : 0,
                        'center_latitude' => $saved['center_latitude'] ?? null,
                        'center_longitude' => $saved['center_longitude'] ?? null,
                        'radius_meters' => isset($saved['radius_meters']) ? (int) $saved['radius_meters'] : null,
                        'max_accuracy_m' => isset($saved['max_accuracy_m']) ? (int) $saved['max_accuracy_m'] : null,
                        'boundary_type' => $saved['boundary_type'] ?? 'circle',
                        'boundary_polygon' => $saved['boundary_polygon'] ?? null,
                        'boundary_shapes' => $saved['boundary_shapes'] ?? null,
                        'updated_by_superadmin' => 1,
                    ]
                );
            } else {
                $_SESSION['flash_message'] = is_string($saveResult) ? $saveResult : 'Unable to clone attendance boundary.';
                $_SESSION['flash_type'] = 'danger';
            }
            $selectedCampusId = $targetCampusId;
        }

        $redirect = 'admin-attendance-boundary.php';
        if ($isSuperadmin && $selectedCampusId > 0) $redirect .= '?campus_id=' . (int) $selectedCampusId;
        header('Location: ' . $redirect);
        exit;
    }

    $input = [
        'geofence_enabled' => !empty($_POST['geofence_enabled']) ? 1 : 0,
        'center_latitude' => trim((string) ($_POST['center_latitude'] ?? '')),
        'center_longitude' => trim((string) ($_POST['center_longitude'] ?? '')),
        'radius_meters' => (int) ($_POST['radius_meters'] ?? 250),
        'max_accuracy_m' => trim((string) ($_POST['max_accuracy_m'] ?? '')),
        'boundary_type' => trim((string) ($_POST['boundary_type'] ?? 'circle')),
        'boundary_polygon' => trim((string) ($_POST['boundary_polygon'] ?? '')),
        'boundary_shapes' => trim((string) ($_POST['boundary_shapes'] ?? '')),
    ];

    [$ok, $saveResult] = attendance_geo_save_policy($conn, $targetCampusId, $input, $adminId, $isSuperadmin);
    if ($ok) {
        $saved = is_array($saveResult) ? $saveResult : attendance_geo_get_policy($conn, $targetCampusId);
        $savedShapes = attendance_geo_policy_shapes($saved);
        $savedShapeCount = count($savedShapes);
        $enabled = !empty($saved['geofence_enabled']);
        if ($enabled) {
            if ($savedShapeCount > 1) {
                $msg = 'Attendance geofence enabled (' . $savedShapeCount . ' campus boundaries).';
            } else {
                $savedType = attendance_geo_normalize_boundary_type($saved['boundary_type'] ?? 'circle');
                if ($savedType === 'polygon') {
                    $msg = 'Attendance geofence enabled (custom polygon boundary).';
                } else {
                    $msg = 'Attendance geofence enabled (' . (int) ($saved['radius_meters'] ?? 250) . 'm radius).';
                }
            }
        } else {
            $msg = 'Attendance geofence disabled.';
        }
        if (!empty($saved['max_accuracy_m'])) {
            $msg .= ' Max accuracy: +/-' . (int) $saved['max_accuracy_m'] . 'm.';
        }
        if ($isSuperadmin) $msg .= ' Superadmin override applied.';

        $_SESSION['flash_message'] = $msg;
        $_SESSION['flash_type'] = 'success';
        audit_log(
            $conn,
            'attendance.geofence.policy.updated',
            'campus',
            $targetCampusId,
            $msg,
            [
                'campus_id' => $targetCampusId,
                'geofence_enabled' => !empty($saved['geofence_enabled']) ? 1 : 0,
                'center_latitude' => $saved['center_latitude'] ?? null,
                'center_longitude' => $saved['center_longitude'] ?? null,
                'radius_meters' => (int) ($saved['radius_meters'] ?? 250),
                'max_accuracy_m' => isset($saved['max_accuracy_m']) ? (int) $saved['max_accuracy_m'] : null,
                'boundary_type' => $saved['boundary_type'] ?? 'circle',
                'boundary_polygon' => $saved['boundary_polygon'] ?? null,
                'boundary_shapes' => $saved['boundary_shapes'] ?? null,
                'boundary_shape_count' => $savedShapeCount,
                'updated_by_superadmin' => $isSuperadmin ? 1 : 0,
            ]
        );
        $selectedCampusId = $targetCampusId;
    } else {
        $_SESSION['flash_message'] = is_string($saveResult) ? $saveResult : 'Unable to save attendance boundary.';
        $_SESSION['flash_type'] = 'danger';
        $selectedCampusId = $targetCampusId;
    }

    $redirect = 'admin-attendance-boundary.php';
    if ($isSuperadmin && $selectedCampusId > 0) $redirect .= '?campus_id=' . (int) $selectedCampusId;
    header('Location: ' . $redirect);
    exit;
}

$selectedCampus = ($selectedCampusId > 0 && isset($campusMap[$selectedCampusId])) ? $campusMap[$selectedCampusId] : null;
$policy = attendance_geo_get_policy($conn, $selectedCampusId);
$boundaryShapes = attendance_geo_policy_shapes($policy);
$boundaryLegacy = attendance_geo_shapes_first_legacy($boundaryShapes);
$boundaryType = attendance_geo_normalize_boundary_type($boundaryLegacy['boundary_type'] ?? ($policy['boundary_type'] ?? 'circle'));
$boundaryPolygon = is_array($boundaryLegacy['boundary_polygon'] ?? null) ? $boundaryLegacy['boundary_polygon'] : [];
$boundaryPolygonJson = json_encode($boundaryPolygon, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$boundaryShapesJson = json_encode($boundaryShapes, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$boundaryCount = count($boundaryShapes);
$circleBoundaryCount = 0;
$polygonBoundaryCount = 0;
foreach ($boundaryShapes as $shape) {
    $stype = attendance_geo_normalize_boundary_type($shape['type'] ?? 'circle');
    if ($stype === 'polygon') $polygonBoundaryCount++;
    else $circleBoundaryCount++;
}
$maxAccuracyValue = isset($policy['max_accuracy_m']) && $policy['max_accuracy_m'] !== null
    ? (string) ((int) $policy['max_accuracy_m'])
    : '';

$updatedByLabel = '-';
$updatedById = isset($policy['updated_by']) ? (int) $policy['updated_by'] : 0;
if ($updatedById > 0) {
    $stmt = $conn->prepare(
        "SELECT username, first_name, last_name
         FROM users
         WHERE id = ?
         LIMIT 1"
    );
    if ($stmt) {
        $stmt->bind_param('i', $updatedById);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows === 1) {
            $u = $res->fetch_assoc();
            $full = trim((string) ($u['first_name'] ?? '') . ' ' . (string) ($u['last_name'] ?? ''));
            if ($full === '') $full = trim((string) ($u['username'] ?? ''));
            if ($full !== '') $updatedByLabel = $full;
        }
        $stmt->close();
    }
}
?>

<?php include '../layouts/main.php'; ?>

<head>
    <title>Attendance Boundary | E-Record</title>
    <?php include '../layouts/title-meta.php'; ?>
    <?php include '../layouts/head-css.php'; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet.draw/1.0.4/leaflet.draw.css">
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
                                    <li class="breadcrumb-item active">Attendance Boundary</li>
                                </ol>
                            </div>
                            <h4 class="page-title">Attendance Boundary</h4>
                        </div>
                    </div>
                </div>

                <?php if ($flash !== ''): ?>
                    <div class="row">
                        <div class="col-12">
                            <div class="alert <?php echo ageo_h(ageo_flash_class($flashType)); ?>" role="alert">
                                <?php echo ageo_h($flash); ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($selectedCampusId <= 0 || !is_array($selectedCampus)): ?>
                    <div class="alert alert-warning">No campus found for this account.</div>
                <?php else: ?>
                    <div class="row">
                        <div class="col-lg-8">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                                        <div>
                                            <h4 class="header-title mb-1">Geofence Policy</h4>
                                            <div class="text-muted small">
                                                Attendance submissions are accepted only when the student location is within the configured campus boundaries (max <?php echo (int) attendance_geo_max_boundary_shapes(); ?> circles/polygons).
                                            </div>
                                        </div>
                                        <?php if ($isSuperadmin): ?>
                                            <span class="badge bg-warning-subtle text-warning border">Superadmin Override</span>
                                        <?php else: ?>
                                            <span class="badge bg-primary-subtle text-primary border">Campus Admin Scope</span>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($isSuperadmin): ?>
                                        <form method="get" class="row g-2 align-items-end mb-3">
                                            <div class="col-md-8">
                                                <label class="form-label">Campus</label>
                                                <select class="form-select" name="campus_id" required>
                                                    <?php foreach ($campuses as $c): ?>
                                                        <?php $cid = (int) ($c['id'] ?? 0); ?>
                                                        <option value="<?php echo $cid; ?>" <?php echo $cid === $selectedCampusId ? 'selected' : ''; ?>>
                                                            <?php echo ageo_h((string) ($c['campus_code'] ?? '')); ?> - <?php echo ageo_h((string) ($c['campus_name'] ?? '')); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-4 d-grid">
                                                <button type="submit" class="btn btn-outline-primary">Load Campus</button>
                                            </div>
                                        </form>
                                        <div class="alert alert-info py-2 small mb-3">
                                            Superadmin mode: pick a campus to view its saved boundary, then draw/edit to overwrite that campus policy.
                                        </div>
                                        <form method="post" class="row g-2 align-items-end mb-3" id="ageoCloneForm">
                                            <input type="hidden" name="csrf_token" value="<?php echo ageo_h(csrf_token()); ?>">
                                            <input type="hidden" name="action" value="clone_policy">
                                            <input type="hidden" name="campus_id" value="<?php echo (int) $selectedCampusId; ?>">
                                            <div class="col-md-8">
                                                <label class="form-label">Clone Policy From Campus</label>
                                                <select class="form-select" name="source_campus_id" id="ageoCloneSource" required>
                                                    <?php foreach ($campuses as $c): ?>
                                                        <?php $srcId = (int) ($c['id'] ?? 0); ?>
                                                        <?php if ($srcId <= 0 || $srcId === $selectedCampusId) continue; ?>
                                                        <option value="<?php echo $srcId; ?>">
                                                            <?php echo ageo_h((string) ($c['campus_code'] ?? '')); ?> - <?php echo ageo_h((string) ($c['campus_name'] ?? '')); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-4 d-grid">
                                                <button
                                                    type="submit"
                                                    class="btn btn-outline-dark"
                                                    <?php echo count($campuses) <= 1 ? 'disabled' : ''; ?>
                                                >
                                                    Clone Into Loaded Campus
                                                </button>
                                            </div>
                                            <?php if (count($campuses) <= 1): ?>
                                                <div class="col-12">
                                                    <div class="form-text">No other campus is available as clone source.</div>
                                                </div>
                                            <?php endif; ?>
                                        </form>
                                    <?php endif; ?>

                                    <form method="post" id="ageoPolicyForm" class="row g-3">
                                        <input type="hidden" name="boundary_type" id="ageoBoundaryType" value="<?php echo ageo_h($boundaryType); ?>">
                                        <input type="hidden" name="boundary_polygon" id="ageoBoundaryPolygon" value="<?php echo ageo_h($boundaryPolygonJson); ?>">
                                        <input type="hidden" name="boundary_shapes" id="ageoBoundaryShapes" value="<?php echo ageo_h($boundaryShapesJson); ?>">

                                        <input type="hidden" name="csrf_token" value="<?php echo ageo_h(csrf_token()); ?>">
                                        <input type="hidden" name="action" value="save_policy">
                                        <input type="hidden" name="campus_id" value="<?php echo (int) $selectedCampusId; ?>">

                                        <div class="col-12">
                                            <div class="form-check form-switch">
                                                <input
                                                    class="form-check-input"
                                                    type="checkbox"
                                                    role="switch"
                                                    id="ageoEnabled"
                                                    name="geofence_enabled"
                                                    value="1"
                                                    <?php echo !empty($policy['geofence_enabled']) ? 'checked' : ''; ?>
                                                >
                                                <label class="form-check-label" for="ageoEnabled">Enable attendance geofence for this campus</label>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label d-block">Boundary Mode</label>
                                            <div class="btn-group btn-group-sm" role="group" aria-label="Boundary mode">
                                                <input
                                                    type="radio"
                                                    class="btn-check"
                                                    name="ageoBoundaryMode"
                                                    id="ageoModeCircle"
                                                    value="circle"
                                                    autocomplete="off"
                                                >
                                                <label class="btn btn-outline-primary" for="ageoModeCircle">Circle (Default)</label>
                                                <input
                                                    type="radio"
                                                    class="btn-check"
                                                    name="ageoBoundaryMode"
                                                    id="ageoModePolygon"
                                                    value="polygon"
                                                    autocomplete="off"
                                                >
                                                <label class="btn btn-outline-primary" for="ageoModePolygon">Specific Polygon</label>
                                            </div>
                                        </div>

                                        <div class="col-12">
                                            <div id="ageoBoundaryMap" style="height: 420px; border: 1px solid #e4e8f1; border-radius: 6px;"></div>
                                            <div class="form-text mt-2" id="ageoMapHint"></div>
                                            <div class="form-text mt-1" id="ageoBoundaryStats"></div>
                                        </div>

                                        <div id="ageoCircleControls" class="col-md-6">
                                            <label class="form-label">Center Latitude</label>
                                            <input
                                                type="text"
                                                class="form-control"
                                                name="center_latitude"
                                                id="ageoLat"
                                                value="<?php echo ageo_h($boundaryLegacy['center_latitude'] !== null ? number_format((float) $boundaryLegacy['center_latitude'], 8, '.', '') : ''); ?>"
                                                placeholder="e.g. 14.59951232"
                                            >
                                        </div>
                                        <div id="ageoCircleControls2" class="col-md-6">
                                            <label class="form-label">Center Longitude</label>
                                            <input
                                                type="text"
                                                class="form-control"
                                                name="center_longitude"
                                                id="ageoLng"
                                                value="<?php echo ageo_h($boundaryLegacy['center_longitude'] !== null ? number_format((float) $boundaryLegacy['center_longitude'], 8, '.', '') : ''); ?>"
                                                placeholder="e.g. 120.98422244"
                                            >
                                        </div>
                                        <div id="ageoCircleControls3" class="col-md-6">
                                            <label class="form-label">Allowed Radius (meters)</label>
                                            <input
                                                type="number"
                                                class="form-control"
                                                name="radius_meters"
                                                id="ageoRadius"
                                                min="25"
                                                max="50000"
                                                step="1"
                                                value="<?php echo (int) ($boundaryLegacy['radius_meters'] ?? 250); ?>"
                                            >
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Max GPS Accuracy (meters, optional)</label>
                                            <input
                                                type="number"
                                                class="form-control"
                                                name="max_accuracy_m"
                                                id="ageoMaxAccuracy"
                                                min="5"
                                                max="5000"
                                                step="1"
                                                value="<?php echo ageo_h($maxAccuracyValue); ?>"
                                                placeholder="Leave blank to disable"
                                            >
                                            <div class="form-text">Reject submissions with accuracy worse than this value (example: 80m).</div>
                                        </div>
                                        <div class="col-md-6 d-flex align-items-end">
                                            <div class="d-flex gap-2 w-100">
                                                <button type="button" class="btn btn-outline-secondary flex-fill" id="ageoUseMyLocation">Use My Location</button>
                                                <a href="#" class="btn btn-outline-primary flex-fill" id="ageoMapPreview" target="_blank" rel="noopener">Open Map Preview</a>
                                                <button type="button" class="btn btn-outline-danger flex-fill" id="ageoClearShape">Clear Shape</button>
                                            </div>
                                        </div>

                                        <div class="col-12">
                                            <label class="form-label">Boundary JSON (Import/Export)</label>
                                            <textarea
                                                class="form-control"
                                                id="ageoBoundaryJson"
                                                rows="7"
                                                placeholder='{"boundary_shapes":[{"type":"circle","center_latitude":0,"center_longitude":0,"radius_meters":250}],"max_accuracy_m":80}'
                                            ></textarea>
                                            <div class="d-flex gap-2 mt-2">
                                                <button type="button" class="btn btn-outline-secondary" id="ageoExportJson">Export Current Form JSON</button>
                                                <button type="button" class="btn btn-outline-primary" id="ageoApplyJson">Apply JSON to Form</button>
                                            </div>
                                            <div class="form-text">Use this to import/export up to <?php echo (int) attendance_geo_max_boundary_shapes(); ?> campus boundaries (circles and/or polygons).</div>
                                        </div>

                                        <div class="col-12">
                                            <button type="submit" class="btn btn-primary">
                                                <i class="ri-save-line me-1"></i>Save Policy
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-4">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title mb-2">Current Campus</h4>
                                    <div class="fw-semibold">
                                        <?php echo ageo_h((string) ($selectedCampus['campus_name'] ?? 'Campus')); ?>
                                    </div>
                                    <div class="text-muted small mb-3">
                                        <?php echo ageo_h((string) ($selectedCampus['campus_code'] ?? '')); ?>
                                    </div>

                                    <div class="d-flex justify-content-between small mb-1">
                                        <span class="text-muted">Boundary Type</span>
                                        <span class="fw-semibold">
                                            <?php
                                            if ($boundaryCount > 1) {
                                                echo 'Multi-shape';
                                            } elseif ($boundaryCount === 1) {
                                                echo ageo_h(ucfirst((string) $boundaryType));
                                            } else {
                                                echo 'Not set';
                                            }
                                            ?>
                                        </span>
                                    </div>
                                    <div class="d-flex justify-content-between small mb-1">
                                        <span class="text-muted">Policy</span>
                                        <span class="fw-semibold"><?php echo !empty($policy['geofence_enabled']) ? 'Enabled' : 'Disabled'; ?></span>
                                    </div>
                                    <div class="d-flex justify-content-between small mb-1">
                                        <span class="text-muted">Boundaries</span>
                                        <span class="fw-semibold"><?php echo (int) $boundaryCount; ?> (C:<?php echo (int) $circleBoundaryCount; ?> / P:<?php echo (int) $polygonBoundaryCount; ?>)</span>
                                    </div>
                                    <div class="d-flex justify-content-between small mb-1">
                                        <span class="text-muted">Radius</span>
                                        <span class="fw-semibold">
                                            <?php
                                            if ($boundaryCount !== 1 || $boundaryType === 'polygon') {
                                                echo 'N/A';
                                            } else {
                                                echo (int) ($boundaryLegacy['radius_meters'] ?? 250) . 'm';
                                            }
                                            ?>
                                        </span>
                                    </div>
                                    <div class="d-flex justify-content-between small mb-1">
                                        <span class="text-muted">Max Accuracy</span>
                                        <span class="fw-semibold">
                                            <?php
                                            if (!empty($policy['max_accuracy_m'])) {
                                                echo '&plusmn;' . (int) $policy['max_accuracy_m'] . 'm';
                                            } else {
                                                echo 'Disabled';
                                            }
                                            ?>
                                        </span>
                                    </div>
                                    <div class="d-flex justify-content-between small mb-1">
                                        <span class="text-muted">Polygon Vertices</span>
                                        <span class="fw-semibold"><?php echo (int) count($boundaryPolygon); ?><?php echo $boundaryCount > 1 ? ' (first shape)' : ''; ?></span>
                                    </div>
                                    <div class="d-flex justify-content-between small mb-1">
                                        <span class="text-muted">Center</span>
                                        <span class="fw-semibold text-end">
                                            <?php
                                            $latText = $boundaryLegacy['center_latitude'] !== null ? number_format((float) $boundaryLegacy['center_latitude'], 6, '.', '') : '-';
                                            $lngText = $boundaryLegacy['center_longitude'] !== null ? number_format((float) $boundaryLegacy['center_longitude'], 6, '.', '') : '-';
                                            echo ageo_h($latText . ', ' . $lngText);
                                            ?>
                                        </span>
                                    </div>
                                    <div class="d-flex justify-content-between small mb-1">
                                        <span class="text-muted">Last Updated</span>
                                        <span class="fw-semibold"><?php echo ageo_h((string) ($policy['updated_at'] ?? '-') ?: '-'); ?></span>
                                    </div>
                                    <div class="d-flex justify-content-between small">
                                        <span class="text-muted">Updated By</span>
                                        <span class="fw-semibold text-end"><?php echo ageo_h($updatedByLabel); ?></span>
                                    </div>
                                    <?php if (!empty($policy['updated_by_superadmin'])): ?>
                                        <div class="alert alert-warning py-2 mt-3 mb-0 small">
                                            This policy was last saved using superadmin override.
                                        </div>
                                    <?php endif; ?>

                                    <div class="mt-3">
                                        <div class="small text-muted mb-1">Stored Boundary Preview</div>
                                        <div id="ageoCampusMiniMap" style="height: 220px; border: 1px solid #e4e8f1; border-radius: 6px;"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php include '../layouts/footer.php'; ?>
    </div>
</div>

<?php include '../layouts/right-sidebar.php'; ?>
<?php include '../layouts/footer-scripts.php'; ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet.draw/1.0.4/leaflet.draw.js"></script>
<script src="assets/js/app.min.js"></script>
<script>
(function () {
    var form = document.getElementById('ageoPolicyForm');
    var enabledToggle = document.getElementById('ageoEnabled');
    var boundaryTypeInput = document.getElementById('ageoBoundaryType');
    var boundaryPolygonInput = document.getElementById('ageoBoundaryPolygon');
    var boundaryShapesInput = document.getElementById('ageoBoundaryShapes');
    var lat = document.getElementById('ageoLat');
    var lng = document.getElementById('ageoLng');
    var preview = document.getElementById('ageoMapPreview');
    var useBtn = document.getElementById('ageoUseMyLocation');
    var clearBtn = document.getElementById('ageoClearShape');
    var radius = document.getElementById('ageoRadius');
    var maxAccuracyInput = document.getElementById('ageoMaxAccuracy');
    var mapContainer = document.getElementById('ageoBoundaryMap');
    var mapHint = document.getElementById('ageoMapHint');
    var mapStats = document.getElementById('ageoBoundaryStats');
    var miniMapContainer = document.getElementById('ageoCampusMiniMap');
    var boundaryJsonText = document.getElementById('ageoBoundaryJson');
    var exportJsonBtn = document.getElementById('ageoExportJson');
    var applyJsonBtn = document.getElementById('ageoApplyJson');
    var cloneForm = document.getElementById('ageoCloneForm');
    var cloneSource = document.getElementById('ageoCloneSource');
    var modeCircle = document.getElementById('ageoModeCircle');
    var modePolygon = document.getElementById('ageoModePolygon');
    var circleControls = document.getElementById('ageoCircleControls');
    var circleControls2 = document.getElementById('ageoCircleControls2');
    var circleControls3 = document.getElementById('ageoCircleControls3');
    var maxBoundaryShapes = <?php echo (int) attendance_geo_max_boundary_shapes(); ?>;

    if (!lat || !lng || !preview || !mapContainer || typeof L === 'undefined') {
        if (preview) {
            preview.classList.add('disabled');
            preview.setAttribute('href', '#');
        }
        return;
    }

    var initialType = (boundaryTypeInput && boundaryTypeInput.value === 'polygon') ? 'polygon' : 'circle';
    if (modeCircle) modeCircle.checked = initialType === 'circle';
    if (modePolygon) modePolygon.checked = initialType === 'polygon';

    var hasInitialCircle = String(lat.value || '').trim() !== '' && String(lng.value || '').trim() !== '';
    var latNum = parseFloat(lat.value);
    var lngNum = parseFloat(lng.value);
    var radiusNum = parseInt(radius.value || '250', 10);

    if (!isFinite(latNum)) latNum = 14.59951232;
    if (!isFinite(lngNum)) lngNum = 120.98422244;
    if (!isFinite(radiusNum) || radiusNum < 25) radiusNum = 250;
    lat.value = (lat.value === '') ? latNum.toFixed(8) : lat.value;
    lng.value = (lng.value === '') ? lngNum.toFixed(8) : lng.value;
    radius.value = radiusNum;

    if (boundaryPolygonInput && boundaryPolygonInput.value === '') {
        boundaryPolygonInput.value = '[]';
    }
    if (boundaryShapesInput && boundaryShapesInput.value === '') {
        boundaryShapesInput.value = '[]';
    }

    var initialPolygon = [];
    try {
        initialPolygon = JSON.parse(boundaryPolygonInput ? boundaryPolygonInput.value : '[]');
        if (!Array.isArray(initialPolygon)) initialPolygon = [];
    } catch (e) {
        initialPolygon = [];
    }
    var initialShapes = parseBoundaryShapes(boundaryShapesInput ? boundaryShapesInput.value : '[]');

    var map = L.map('ageoBoundaryMap').setView([latNum, lngNum], 14);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors',
        maxZoom: 19
    }).addTo(map);

    var drawnItems = new L.FeatureGroup();
    map.addLayer(drawnItems);

    var miniMap = null;
    var miniMapShapesLayer = null;
    if (miniMapContainer) {
        miniMap = L.map('ageoCampusMiniMap', {
            zoomControl: false,
            attributionControl: false,
            dragging: false,
            scrollWheelZoom: false,
            doubleClickZoom: false,
            boxZoom: false,
            keyboard: false,
            touchZoom: false
        });
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19
        }).addTo(miniMap);
        miniMapShapesLayer = L.featureGroup().addTo(miniMap);
        miniMap.setView([latNum, lngNum], 12);
    }

    function refreshMiniMapSize() {
        if (!miniMap) return;
        miniMap.invalidateSize(false);
    }

    if (miniMap) {
        setTimeout(refreshMiniMapSize, 150);
        setTimeout(refreshMiniMapSize, 500);
        window.addEventListener('resize', refreshMiniMapSize);
    }

    function toggleCircleInputs() {
        var currentType = (boundaryTypeInput && boundaryTypeInput.value === 'circle') ? 'circle' : 'polygon';
        var isCircle = currentType === 'circle';
        if (circleControls) circleControls.style.display = isCircle ? 'block' : 'none';
        if (circleControls2) circleControls2.style.display = isCircle ? 'block' : 'none';
        if (circleControls3) circleControls3.style.display = isCircle ? 'block' : 'none';
        if (lat) lat.readOnly = !isCircle;
        if (lng) lng.readOnly = !isCircle;
        if (radius) radius.readOnly = !isCircle;
        if (mapHint) {
            mapHint.textContent = isCircle
                ? ('Circle mode: draw campus circles directly on the map. Maximum total boundaries: ' + maxBoundaryShapes + '.')
                : ('Polygon mode: draw closed campus polygons directly on the map. Maximum total boundaries: ' + maxBoundaryShapes + '.');
        }
    }

    var circleDrawOptions = {
        shapeOptions: { color: '#3b82f6' },
        showRadius: true
    };
    var polygonDrawOptions = {
        allowIntersection: false,
        showArea: true,
        shapeOptions: { color: '#3b82f6' }
    };

    var drawControl = new L.Control.Draw({
        draw: {
            polyline: false,
            rectangle: false,
            marker: false,
            circlemarker: false,
            circle: circleDrawOptions,
            polygon: polygonDrawOptions
        },
        edit: {
            featureGroup: drawnItems,
            remove: true
        }
    });
    map.addControl(drawControl);

    var currentLayer = null;
    var currentType = '';

    function toLatLngList(layer) {
        var points = [];
        if (!layer || !layer.getLatLngs) return points;
        var raw = layer.getLatLngs();
        if (!Array.isArray(raw) || raw.length === 0) return points;
        var ring = raw[0];
        if (!Array.isArray(ring)) return points;
        for (var i = 0; i < ring.length; i++) {
            var p = ring[i];
            if (p && isFinite(p.lat) && isFinite(p.lng)) {
                points.push({ lat: Number(p.lat), lng: Number(p.lng) });
            }
        }
        return points;
    }

    function isCircleLayer(layer) {
        return !!(layer && typeof layer.getLatLng === 'function' && typeof layer.getRadius === 'function');
    }

    function isPolygonLayer(layer) {
        return !!(layer && typeof layer.getLatLngs === 'function' && !isCircleLayer(layer));
    }

    function shapeFromLayer(layer) {
        if (!layer) return null;

        if (isCircleLayer(layer)) {
            var center = layer.getLatLng();
            if (!center || !isFinite(center.lat) || !isFinite(center.lng)) return null;
            var cRadius = Math.round(layer.getRadius());
            if (!isFinite(cRadius) || cRadius < 25) cRadius = 250;
            if (cRadius > 50000) cRadius = 50000;
            return {
                type: 'circle',
                center_latitude: Number(center.lat.toFixed(8)),
                center_longitude: Number(center.lng.toFixed(8)),
                radius_meters: cRadius
            };
        }

        if (isPolygonLayer(layer)) {
            var pts = toLatLngList(layer);
            if (pts.length < 3) return null;
            return { type: 'polygon', points: pts };
        }

        return null;
    }

    function collectMapShapes() {
        var shapes = [];
        drawnItems.eachLayer(function (layer) {
            if (shapes.length >= maxBoundaryShapes) return;
            var shape = shapeFromLayer(layer);
            if (shape) shapes.push(shape);
        });
        return shapes;
    }

    function syncHiddenFromMapShapes() {
        var shapes = collectMapShapes();
        if (boundaryShapesInput) boundaryShapesInput.value = JSON.stringify(shapes);
        renderMiniMapShapes(shapes);

        if (shapes.length < 1) {
            if (boundaryPolygonInput) boundaryPolygonInput.value = '[]';
            return shapes;
        }

        var first = shapes[0];
        if (boundaryTypeInput) boundaryTypeInput.value = first.type === 'polygon' ? 'polygon' : 'circle';
        if (first.type === 'polygon') {
            if (boundaryPolygonInput) boundaryPolygonInput.value = JSON.stringify(first.points || []);
        } else {
            if (boundaryPolygonInput) boundaryPolygonInput.value = '[]';
        }

        return shapes;
    }

    function renderMiniMapShapes(shapes) {
        if (!miniMap || !miniMapShapesLayer) return;

        miniMapShapesLayer.clearLayers();
        var list = Array.isArray(shapes) ? shapes : [];
        var bounds = null;
        for (var i = 0; i < list.length; i++) {
            var shape = list[i] || {};
            var layer = null;
            if (shape.type === 'polygon') {
                layer = L.polygon(shape.points || [], {
                    color: '#16a34a',
                    fillColor: '#86efac',
                    fillOpacity: 0.25
                }).addTo(miniMapShapesLayer);
            } else {
                layer = L.circle([shape.center_latitude, shape.center_longitude], {
                    radius: Math.max(25, parseInt(shape.radius_meters, 10) || 250),
                    color: '#16a34a',
                    fillColor: '#86efac',
                    fillOpacity: 0.25
                }).addTo(miniMapShapesLayer);
            }
            if (layer && layer.getBounds) {
                if (!bounds) bounds = layer.getBounds();
                else bounds.extend(layer.getBounds());
            }
        }

        if (bounds && bounds.isValid && bounds.isValid()) {
            miniMap.fitBounds(bounds, { maxZoom: 16 });
        } else {
            miniMap.setView([latNum, lngNum], 12);
        }
    }

    function addShapeLayer(shape) {
        if (!shape || typeof shape !== 'object') return null;
        if (shape.type === 'polygon') {
            return L.polygon(shape.points || [], {
                color: '#3b82f6',
                fillColor: '#93c5fd',
                fillOpacity: 0.25
            }).addTo(drawnItems);
        }
        return L.circle([shape.center_latitude, shape.center_longitude], {
            radius: Math.max(25, parseInt(shape.radius_meters, 10) || 250),
            color: '#3b82f6',
            fillColor: '#93c5fd',
            fillOpacity: 0.25
        }).addTo(drawnItems);
    }

    function focusFirstLayer() {
        var layers = drawnItems.getLayers ? drawnItems.getLayers() : [];
        if (!Array.isArray(layers) || layers.length < 1) {
            currentLayer = null;
            currentType = '';
            return;
        }
        currentLayer = layers[0];
        currentType = isCircleLayer(currentLayer) ? 'circle' : 'polygon';
    }

    function updateBoundaryStats() {
        if (!mapStats) return;

        var shapes = collectMapShapes();
        if (shapes.length > 1) {
            var circleCount = 0;
            var polygonCount = 0;
            for (var i = 0; i < shapes.length; i++) {
                if (shapes[i].type === 'polygon') polygonCount++;
                else circleCount++;
            }
            mapStats.textContent = 'Boundaries configured: ' + shapes.length + ' / ' + maxBoundaryShapes +
                ' (C:' + circleCount + ', P:' + polygonCount + ').';
            return;
        }

        if (shapes.length === 1 && shapes[0].type === 'circle') {
            mapStats.textContent = 'Circle center: ' +
                Number(shapes[0].center_latitude).toFixed(6) + ', ' +
                Number(shapes[0].center_longitude).toFixed(6) +
                ' | Radius: ' + parseInt(shapes[0].radius_meters, 10) + 'm';
            return;
        }

        if (shapes.length === 1 && shapes[0].type === 'polygon') {
            mapStats.textContent = 'Polygon vertices: ' + (shapes[0].points || []).length + ' | Drag vertices to fine-tune shape.';
            return;
        }

        mapStats.textContent = 'No boundary drawn yet.';
    }

    function clearPolygonDraft() {
        if (boundaryPolygonInput) boundaryPolygonInput.value = '[]';
        if (boundaryShapesInput) boundaryShapesInput.value = '[]';
        lat.value = '';
        lng.value = '';
        radius.value = '';
        renderMiniMapShapes([]);
        updatePreview();
        updateBoundaryStats();
    }

    function applyDrawMode(type) {
        if (!drawControl || !drawControl.setDrawingOptions) return;
        var isCircle = type !== 'polygon';
        drawControl.setDrawingOptions({
            circle: isCircle ? circleDrawOptions : false,
            polygon: isCircle ? false : polygonDrawOptions
        });
    }

    function normalizeMaxAccuracyInput(value) {
        var n = parseInt(String(value == null ? '' : value).trim(), 10);
        if (!isFinite(n) || n <= 0) return '';
        if (n < 5) n = 5;
        if (n > 5000) n = 5000;
        return String(n);
    }

    function parsePolygonJson(raw) {
        var parsed = raw;
        if (typeof parsed === 'string') {
            try {
                parsed = JSON.parse(parsed);
            } catch (e) {
                parsed = [];
            }
        }
        if (!Array.isArray(parsed)) return [];
        var normalized = [];
        for (var i = 0; i < parsed.length; i++) {
            var p = parsed[i] || {};
            var pLat = parseFloat(p.lat);
            var pLng = parseFloat(p.lng);
            if (!isFinite(pLat) && Array.isArray(p)) pLat = parseFloat(p[0]);
            if (!isFinite(pLng) && Array.isArray(p)) pLng = parseFloat(p[1]);
            if (!isFinite(pLat) || !isFinite(pLng)) continue;
            if (pLat < -90 || pLat > 90 || pLng < -180 || pLng > 180) continue;
            normalized.push({ lat: pLat, lng: pLng });
            if (normalized.length >= 512) break;
        }
        return normalized;
    }

    function parseBoundaryShapes(raw) {
        var parsed = raw;
        if (typeof parsed === 'string') {
            try {
                parsed = JSON.parse(parsed);
            } catch (e) {
                parsed = [];
            }
        }
        if (!Array.isArray(parsed)) return [];

        var normalized = [];
        for (var i = 0; i < parsed.length; i++) {
            var shape = parsed[i] || {};
            var shapeType = String(shape.type || shape.boundary_type || 'circle').toLowerCase() === 'polygon' ? 'polygon' : 'circle';
            if (shapeType === 'polygon') {
                var poly = parsePolygonJson(shape.points || shape.boundary_polygon || []);
                if (poly.length < 3) continue;
                normalized.push({ type: 'polygon', points: poly });
            } else {
                var cLat = parseFloat(shape.center_latitude);
                var cLng = parseFloat(shape.center_longitude);
                var cRadius = parseInt(shape.radius_meters, 10);
                if (!isFinite(cLat) || !isFinite(cLng)) continue;
                if (!isFinite(cRadius) || cRadius < 25) cRadius = 250;
                if (cRadius > 50000) cRadius = 50000;
                normalized.push({
                    type: 'circle',
                    center_latitude: Number(cLat.toFixed(8)),
                    center_longitude: Number(cLng.toFixed(8)),
                    radius_meters: cRadius
                });
            }
            if (normalized.length >= maxBoundaryShapes) break;
        }
        return normalized;
    }

    function currentBoundaryPayload() {
        var activeMode = (boundaryTypeInput && boundaryTypeInput.value === 'polygon') ? 'polygon' : 'circle';
        var maxAcc = maxAccuracyInput ? normalizeMaxAccuracyInput(maxAccuracyInput.value) : '';
        var payload = {
            geofence_enabled: enabledToggle ? (enabledToggle.checked ? 1 : 0) : 1,
            boundary_type: activeMode,
            center_latitude: null,
            center_longitude: null,
            radius_meters: null,
            max_accuracy_m: maxAcc === '' ? null : parseInt(maxAcc, 10),
            boundary_polygon: [],
            boundary_shapes: []
        };

        payload.boundary_shapes = collectMapShapes();
        if (payload.boundary_shapes.length > maxBoundaryShapes) {
            payload.boundary_shapes = payload.boundary_shapes.slice(0, maxBoundaryShapes);
        }

        if (payload.boundary_shapes.length > 0) {
            var first = payload.boundary_shapes[0];
            payload.boundary_type = first.type === 'polygon' ? 'polygon' : 'circle';
            if (first.type === 'polygon') {
                payload.boundary_polygon = first.points || [];
            } else {
                payload.center_latitude = first.center_latitude;
                payload.center_longitude = first.center_longitude;
                payload.radius_meters = parseInt(first.radius_meters, 10);
            }
            return payload;
        }

        if (activeMode === 'circle') {
            var pLat = parseFloat(lat.value);
            var pLng = parseFloat(lng.value);
            var pRadius = parseInt(radius.value, 10);
            payload.center_latitude = isFinite(pLat) ? Number(pLat.toFixed(8)) : null;
            payload.center_longitude = isFinite(pLng) ? Number(pLng.toFixed(8)) : null;
            if (!isFinite(pRadius) || pRadius < 25) pRadius = 250;
            if (pRadius > 50000) pRadius = 50000;
            payload.radius_meters = pRadius;
            if (payload.center_latitude !== null && payload.center_longitude !== null) {
                payload.boundary_shapes = [{
                    type: 'circle',
                    center_latitude: payload.center_latitude,
                    center_longitude: payload.center_longitude,
                    radius_meters: payload.radius_meters
                }];
            }
        } else {
            payload.boundary_polygon = parsePolygonJson(boundaryPolygonInput ? boundaryPolygonInput.value : '[]');
            if (payload.boundary_polygon.length >= 3) {
                payload.boundary_shapes = [{ type: 'polygon', points: payload.boundary_polygon }];
            }
        }
        return payload;
    }

    function setMode(type) {
        boundaryTypeInput.value = type === 'polygon' ? 'polygon' : 'circle';
        if (modeCircle) modeCircle.checked = type === 'circle';
        if (modePolygon) modePolygon.checked = type === 'polygon';
        applyDrawMode(type);
        toggleCircleInputs();
    }

    function applyCircleInputs() {
        if (!currentLayer || currentType !== 'circle') return;
        var c = currentLayer.getLatLng();
        var cLat = Number(c.lat.toFixed(8));
        var cLng = Number(c.lng.toFixed(8));
        var cRadius = Math.round(currentLayer.getRadius());
        lat.value = cLat.toFixed(8);
        lng.value = cLng.toFixed(8);
        radius.value = cRadius;
        if (boundaryPolygonInput) boundaryPolygonInput.value = '[]';
        syncHiddenFromMapShapes();
        updatePreview();
        updateBoundaryStats();
    }

    function applyPolygonInputs() {
        if (!currentLayer || currentType !== 'polygon') return;
        var points = toLatLngList(currentLayer);
        if (boundaryPolygonInput) boundaryPolygonInput.value = Array.isArray(points) && points.length ? JSON.stringify(points) : '[]';
        syncHiddenFromMapShapes();
        lat.value = '';
        lng.value = '';
        radius.value = '';
        updatePreview();
        updateBoundaryStats();
    }

    function clearShape() {
        drawnItems.clearLayers();
        currentLayer = null;
        currentType = '';
    }

    function addCircle() {
        clearShape();
        currentType = 'circle';
        setMode('circle');
        var nextLat = parseFloat(lat.value);
        var nextLng = parseFloat(lng.value);
        if (!isFinite(nextLat) || !isFinite(nextLng)) {
            nextLat = latNum;
            nextLng = lngNum;
        }
        var nextRadius = parseInt(radius.value, 10);
        if (!isFinite(nextRadius) || nextRadius < 25) nextRadius = 250;
        currentLayer = L.circle([nextLat, nextLng], {
            radius: nextRadius,
            color: '#3b82f6',
            fillColor: '#93c5fd',
            fillOpacity: 0.25
        }).addTo(drawnItems);
        applyCircleInputs();
    }

    function addPolygon(points) {
        clearShape();
        currentType = 'polygon';
        setMode('polygon');
        currentLayer = L.polygon(points, {
            color: '#3b82f6',
            fillColor: '#93c5fd',
            fillOpacity: 0.25
        }).addTo(drawnItems);
        applyPolygonInputs();
        if (currentLayer.getBounds) {
            map.fitBounds(currentLayer.getBounds(), { maxZoom: 16 });
        }
    }

    function syncFromPolygonPoints(points) {
        if (!points.length) return;
        addPolygon(points);
    }

    function applyInitialShape() {
        drawnItems.clearLayers();

        if (initialShapes.length > 0) {
            var firstLayer = null;
            var mapBounds = null;
            for (var i = 0; i < initialShapes.length; i++) {
                var layer = addShapeLayer(initialShapes[i]);
                if (!layer) continue;
                if (!firstLayer) firstLayer = layer;
                if (layer.getBounds) {
                    if (!mapBounds) mapBounds = layer.getBounds();
                    else mapBounds.extend(layer.getBounds());
                }
            }

            if (!firstLayer) {
                setMode(initialType);
                clearPolygonDraft();
                return;
            }

            currentLayer = firstLayer;
            currentType = isCircleLayer(firstLayer) ? 'circle' : 'polygon';
            setMode(currentType || initialType);
            syncHiddenFromMapShapes();
            if (currentLayer && currentType === 'circle') applyCircleInputs();
            if (currentLayer && currentType === 'polygon') applyPolygonInputs();
            if (mapBounds && mapBounds.isValid && mapBounds.isValid()) {
                map.fitBounds(mapBounds, { maxZoom: 16 });
            }
            return;
        }

        if (boundaryTypeInput && boundaryTypeInput.value === 'polygon') {
            setMode('polygon');
            if (initialPolygon.length >= 3) {
                syncFromPolygonPoints(initialPolygon);
            } else {
                clearShape();
                clearPolygonDraft();
            }
            return;
        }
        setMode('circle');
        addCircle();
        syncHiddenFromMapShapes();
    }

    function onCircleInputChange() {
        if (!currentLayer || currentType !== 'circle') return;
        var nextLat = parseFloat(lat.value);
        var nextLng = parseFloat(lng.value);
        var nextRadius = parseInt(radius.value, 10);
        if (!isFinite(nextLat) || !isFinite(nextLng) || !isFinite(nextRadius)) return;
        currentLayer.setLatLng([nextLat, nextLng]);
        currentLayer.setRadius(nextRadius);
        applyCircleInputs();
    }

    map.on(L.Draw.Event.CREATED, function (event) {
        if (event.layerType !== 'circle' && event.layerType !== 'polygon') return;
        if (drawnItems.getLayers().length >= maxBoundaryShapes) {
            alert('Only up to ' + maxBoundaryShapes + ' campus boundaries are allowed.');
            return;
        }

        currentLayer = event.layer;
        currentType = event.layerType;
        if (currentLayer.setStyle) {
            currentLayer.setStyle({ color: '#3b82f6', fillColor: '#93c5fd' });
        }
        currentLayer.addTo(drawnItems);

        if (drawnItems.getLayers().length > maxBoundaryShapes) {
            drawnItems.removeLayer(currentLayer);
            currentLayer = null;
            currentType = '';
            alert('Only up to ' + maxBoundaryShapes + ' campus boundaries are allowed.');
            return;
        }

        setMode(currentType);
        syncHiddenFromMapShapes();
        if (currentType === 'circle') applyCircleInputs();
        else applyPolygonInputs();
    });

    map.on(L.Draw.Event.EDITED, function (event) {
        if (event && event.layers && event.layers.eachLayer) {
            var editedLayer = null;
            event.layers.eachLayer(function (layer) {
                if (!editedLayer) editedLayer = layer;
            });
            if (editedLayer) {
                currentLayer = editedLayer;
                currentType = isCircleLayer(editedLayer) ? 'circle' : 'polygon';
            }
        }
        if (!currentLayer) focusFirstLayer();
        syncHiddenFromMapShapes();
        if (currentType === 'circle') {
            applyCircleInputs();
        } else if (currentType === 'polygon') {
            applyPolygonInputs();
        } else {
            updatePreview();
            updateBoundaryStats();
        }
    });

    map.on(L.Draw.Event.DELETED, function () {
        focusFirstLayer();
        syncHiddenFromMapShapes();
        if (currentType === 'circle') {
            applyCircleInputs();
        } else if (currentType === 'polygon') {
            applyPolygonInputs();
        } else {
            updatePreview();
            updateBoundaryStats();
        }
    });

    if (modeCircle) {
        modeCircle.addEventListener('change', function () {
            if (modeCircle.checked) {
                setMode('circle');
                if (drawnItems.getLayers().length === 0) addCircle();
            }
        });
    }
    if (modePolygon) {
        modePolygon.addEventListener('change', function () {
            if (modePolygon.checked) {
                setMode('polygon');
                if (drawnItems.getLayers().length === 0) clearPolygonDraft();
            }
        });
    }

    if (radius) {
        radius.addEventListener('input', onCircleInputChange);
        radius.addEventListener('change', onCircleInputChange);
    }
    if (lat) {
        lat.addEventListener('input', onCircleInputChange);
        lat.addEventListener('change', onCircleInputChange);
    }
    if (lng) {
        lng.addEventListener('input', onCircleInputChange);
        lng.addEventListener('change', onCircleInputChange);
    }

    if (preview) {
        if (lat && lng) {
            lat.addEventListener('input', updatePreview);
            lng.addEventListener('input', updatePreview);
        }
    }

    function updatePreview() {
        var la = String(lat.value || '').trim();
        var lo = String(lng.value || '').trim();
        if (la === '' || lo === '') {
            preview.setAttribute('href', '#');
            preview.classList.add('disabled');
            return;
        }
        preview.setAttribute('href', 'https://www.google.com/maps?q=' + encodeURIComponent(la + ',' + lo));
        preview.classList.remove('disabled');
    }

    function useCurrentLocation() {
        if (!navigator.geolocation || !navigator.geolocation.getCurrentPosition) {
            alert('Geolocation is not supported by this browser.');
            return;
        }
        useBtn.disabled = true;
        var old = useBtn.textContent;
        useBtn.textContent = 'Locating...';
        navigator.geolocation.getCurrentPosition(
            function (pos) {
                useBtn.disabled = false;
                useBtn.textContent = old;
                if (!pos || !pos.coords) return;
                map.setView([pos.coords.latitude, pos.coords.longitude], 16);
                if (currentLayer && currentType === 'circle') {
                    currentLayer.setLatLng([pos.coords.latitude, pos.coords.longitude]);
                    applyCircleInputs();
                } else {
                    if (!boundaryTypeInput || boundaryTypeInput.value === 'circle') {
                        lat.value = Number(pos.coords.latitude).toFixed(8);
                        lng.value = Number(pos.coords.longitude).toFixed(8);
                        radiusNum = Math.max(25, parseInt(radius.value, 10) || 250);
                        if (drawnItems.getLayers().length >= maxBoundaryShapes) {
                            alert('Maximum boundaries reached (' + maxBoundaryShapes + '). Delete one boundary before adding another.');
                            updatePreview();
                            return;
                        }
                        var added = addShapeLayer({
                            type: 'circle',
                            center_latitude: Number(pos.coords.latitude.toFixed(8)),
                            center_longitude: Number(pos.coords.longitude.toFixed(8)),
                            radius_meters: radiusNum
                        });
                        currentLayer = added;
                        currentType = 'circle';
                        setMode('circle');
                        applyCircleInputs();
                    }
                    updatePreview();
                }
            },
            function () {
                useBtn.disabled = false;
                useBtn.textContent = old;
                alert('Unable to get your current location.');
            },
            {
                enableHighAccuracy: true,
                timeout: 8000,
                maximumAge: 10000
            }
        );
    }

    if (useBtn) {
        useBtn.addEventListener('click', useCurrentLocation);
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            clearShape();
            clearPolygonDraft();
            setMode((modePolygon && modePolygon.checked) ? 'polygon' : 'circle');
        });
    }

    if (form) {
        form.addEventListener('submit', function (event) {
            var geofenceEnabled = enabledToggle ? !!enabledToggle.checked : true;
            if (maxAccuracyInput) {
                maxAccuracyInput.value = normalizeMaxAccuracyInput(maxAccuracyInput.value);
            }

            var payload = currentBoundaryPayload();
            var shapes = parseBoundaryShapes(payload.boundary_shapes || []);

            if (geofenceEnabled && shapes.length < 1) {
                // Backward-compatible fallback from manual circle inputs.
                var latValue = parseFloat(lat.value);
                var lngValue = parseFloat(lng.value);
                var radiusValue = parseInt(radius.value, 10);
                if (isFinite(latValue) && isFinite(lngValue)) {
                    if (!isFinite(radiusValue) || radiusValue < 25) radiusValue = 250;
                    if (radiusValue > 50000) radiusValue = 50000;
                    shapes = [{
                        type: 'circle',
                        center_latitude: Number(latValue.toFixed(8)),
                        center_longitude: Number(lngValue.toFixed(8)),
                        radius_meters: radiusValue
                    }];
                }
            }

            if (shapes.length > maxBoundaryShapes) {
                event.preventDefault();
                alert('Only up to ' + maxBoundaryShapes + ' campus boundaries are allowed.');
                return;
            }

            if (geofenceEnabled && shapes.length < 1) {
                event.preventDefault();
                alert('Draw at least one boundary (circle or polygon) before saving.');
                return;
            }

            if (boundaryShapesInput) boundaryShapesInput.value = JSON.stringify(shapes);
            if (shapes.length > 0) {
                var first = shapes[0];
                if (boundaryTypeInput) boundaryTypeInput.value = first.type === 'polygon' ? 'polygon' : 'circle';
                if (first.type === 'polygon') {
                    if (boundaryPolygonInput) boundaryPolygonInput.value = JSON.stringify(first.points || []);
                    if (lat) lat.value = '';
                    if (lng) lng.value = '';
                } else {
                    if (boundaryPolygonInput) boundaryPolygonInput.value = '[]';
                    if (lat) lat.value = Number(first.center_latitude).toFixed(8);
                    if (lng) lng.value = Number(first.center_longitude).toFixed(8);
                    if (radius) radius.value = String(parseInt(first.radius_meters, 10));
                }
            }
        });
    }

    if (maxAccuracyInput) {
        maxAccuracyInput.addEventListener('blur', function () {
            maxAccuracyInput.value = normalizeMaxAccuracyInput(maxAccuracyInput.value);
        });
    }

    if (exportJsonBtn && boundaryJsonText) {
        exportJsonBtn.addEventListener('click', function () {
            var payload = currentBoundaryPayload();
            boundaryJsonText.value = JSON.stringify(payload, null, 2);
        });
    }

    if (applyJsonBtn && boundaryJsonText) {
        applyJsonBtn.addEventListener('click', function () {
            var imported;
            try {
                imported = JSON.parse(String(boundaryJsonText.value || '{}'));
            } catch (e) {
                alert('Invalid JSON format.');
                return;
            }
            if (!imported || typeof imported !== 'object') {
                alert('Invalid JSON object.');
                return;
            }

            if (enabledToggle && imported.geofence_enabled !== undefined && imported.geofence_enabled !== null) {
                enabledToggle.checked = !!Number(imported.geofence_enabled);
            }

            if (maxAccuracyInput) {
                maxAccuracyInput.value = normalizeMaxAccuracyInput(imported.max_accuracy_m);
            }

            var importedShapes = parseBoundaryShapes(imported.boundary_shapes || []);
            if (importedShapes.length === 0) {
                var importedType = String(imported.boundary_type || 'circle').toLowerCase() === 'polygon' ? 'polygon' : 'circle';
                if (importedType === 'polygon') {
                    var poly = parsePolygonJson(imported.boundary_polygon || []);
                    if (poly.length < 3) {
                        alert('Imported polygon must have at least 3 valid vertices.');
                        return;
                    }
                    importedShapes = [{ type: 'polygon', points: poly }];
                } else {
                    var iLat = parseFloat(imported.center_latitude);
                    var iLng = parseFloat(imported.center_longitude);
                    var iRadius = parseInt(imported.radius_meters, 10);
                    if (!isFinite(iLat) || !isFinite(iLng)) {
                        alert('Imported circle requires valid center_latitude and center_longitude.');
                        return;
                    }
                    if (!isFinite(iRadius) || iRadius < 25) iRadius = 250;
                    if (iRadius > 50000) iRadius = 50000;
                    importedShapes = [{
                        type: 'circle',
                        center_latitude: Number(iLat.toFixed(8)),
                        center_longitude: Number(iLng.toFixed(8)),
                        radius_meters: iRadius
                    }];
                }
            }

            if (importedShapes.length > maxBoundaryShapes) {
                alert('Imported boundaries exceed limit of ' + maxBoundaryShapes + '.');
                return;
            }

            drawnItems.clearLayers();
            currentLayer = null;
            currentType = '';

            var firstLayer = null;
            var loadedBounds = null;
            for (var s = 0; s < importedShapes.length; s++) {
                var added = addShapeLayer(importedShapes[s]);
                if (!added) continue;
                if (!firstLayer) firstLayer = added;
                if (added.getBounds) {
                    if (!loadedBounds) loadedBounds = added.getBounds();
                    else loadedBounds.extend(added.getBounds());
                }
            }

            if (firstLayer) {
                currentLayer = firstLayer;
                currentType = isCircleLayer(firstLayer) ? 'circle' : 'polygon';
                setMode(currentType);
                syncHiddenFromMapShapes();
                if (currentType === 'circle') applyCircleInputs();
                else applyPolygonInputs();
            } else {
                setMode('circle');
                clearPolygonDraft();
            }

            if (loadedBounds && loadedBounds.isValid && loadedBounds.isValid()) {
                map.fitBounds(loadedBounds, { maxZoom: 16 });
            }
        });
    }

    if (cloneForm) {
        cloneForm.addEventListener('submit', function (event) {
            if (!cloneSource || String(cloneSource.value || '').trim() === '') {
                event.preventDefault();
                alert('Select a source campus to clone from.');
                return;
            }
            var sourceText = cloneSource.options && cloneSource.selectedIndex >= 0
                ? String(cloneSource.options[cloneSource.selectedIndex].text || 'selected campus')
                : 'selected campus';
            var ok = confirm('Clone attendance boundary from "' + sourceText + '" into the currently loaded campus? This will overwrite the current campus policy.');
            if (!ok) {
                event.preventDefault();
            }
        });
    }

    applyInitialShape();
    updatePreview();
    updateBoundaryStats();
    if (boundaryJsonText) {
        boundaryJsonText.value = JSON.stringify(currentBoundaryPayload(), null, 2);
    }

})();
</script>
</body>
</html>
