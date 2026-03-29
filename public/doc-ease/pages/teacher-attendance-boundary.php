<?php include '../layouts/session.php'; ?>
<?php require_active_role('teacher'); ?>

<?php
require_once __DIR__ . '/../includes/attendance_geofence.php';
require_once __DIR__ . '/../includes/audit.php';
attendance_geo_ensure_table($conn);
attendance_geo_ensure_class_table($conn);
ensure_audit_logs_table($conn);

if (!function_exists('tgeo_h')) {
    function tgeo_h($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('tgeo_flash_class')) {
    function tgeo_flash_class($type) {
        $t = strtolower(trim((string) $type));
        if ($t === 'danger' || $t === 'error') return 'alert-danger';
        if ($t === 'warning') return 'alert-warning';
        if ($t === 'info') return 'alert-info';
        return 'alert-success';
    }
}

$flash = isset($_SESSION['flash_message']) ? (string) $_SESSION['flash_message'] : '';
$flashType = isset($_SESSION['flash_type']) ? (string) $_SESSION['flash_type'] : 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

$teacherId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$assigned = [];
if ($teacherId > 0) {
    $stmt = $conn->prepare(
        "SELECT cr.id AS class_record_id,
                cr.section,
                cr.academic_year,
                cr.semester,
                s.subject_code,
                s.subject_name
         FROM teacher_assignments ta
         JOIN class_records cr ON cr.id = ta.class_record_id
         JOIN subjects s ON s.id = cr.subject_id
         WHERE ta.teacher_id = ?
           AND ta.status = 'active'
           AND cr.status = 'active'
         ORDER BY cr.academic_year DESC, cr.semester DESC, s.subject_code ASC, s.subject_name ASC"
    );
    if ($stmt) {
        $stmt->bind_param('i', $teacherId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $classRecordId = (int) ($row['class_record_id'] ?? 0);
            if ($classRecordId <= 0) continue;
            $row['class_record_id'] = $classRecordId;
            $row['campus_id'] = attendance_geo_class_record_campus_id($conn, $classRecordId);
            $assigned[] = $row;
        }
        $stmt->close();
    }
}

$classMap = [];
$campusIds = [];
foreach ($assigned as $row) {
    $classRecordId = (int) ($row['class_record_id'] ?? 0);
    if ($classRecordId <= 0) continue;
    $classMap[$classRecordId] = $row;

    $campusId = (int) ($row['campus_id'] ?? 0);
    if ($campusId > 0) $campusIds[$campusId] = $campusId;
}

$campusMap = [];
if (count($campusIds) > 0) {
    $stmt = $conn->prepare(
        "SELECT id, campus_code, campus_name
         FROM campuses
         WHERE id = ?
         LIMIT 1"
    );
    if ($stmt) {
        foreach ($campusIds as $campusId) {
            $stmt->bind_param('i', $campusId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows === 1) {
                $r = $res->fetch_assoc();
                $cid = (int) ($r['id'] ?? 0);
                if ($cid > 0) {
                    $campusMap[$cid] = [
                        'id' => $cid,
                        'campus_code' => (string) ($r['campus_code'] ?? ''),
                        'campus_name' => (string) ($r['campus_name'] ?? ''),
                    ];
                }
            }
        }
        $stmt->close();
    }
}

$selectedClassId = isset($_GET['class_record_id']) ? (int) $_GET['class_record_id'] : 0;
if (isset($_POST['class_record_id'])) $selectedClassId = (int) $_POST['class_record_id'];
if ($selectedClassId <= 0 || !isset($classMap[$selectedClassId])) {
    $selectedClassId = count($assigned) > 0 ? (int) ($assigned[0]['class_record_id'] ?? 0) : 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    if (!csrf_validate($csrf)) {
        $_SESSION['flash_message'] = 'Security check failed (CSRF). Please refresh and try again.';
        $_SESSION['flash_type'] = 'danger';
        $redirect = 'teacher-attendance-boundary.php';
        if ($selectedClassId > 0) $redirect .= '?class_record_id=' . $selectedClassId;
        header('Location: ' . $redirect);
        exit;
    }

    $action = isset($_POST['action']) ? trim((string) $_POST['action']) : '';
    if ($action !== 'save_policy') {
        $_SESSION['flash_message'] = 'Invalid request.';
        $_SESSION['flash_type'] = 'danger';
        $redirect = 'teacher-attendance-boundary.php';
        if ($selectedClassId > 0) $redirect .= '?class_record_id=' . $selectedClassId;
        header('Location: ' . $redirect);
        exit;
    }

    $selectedClassId = (int) ($_POST['class_record_id'] ?? $selectedClassId);
    if ($selectedClassId <= 0 || !isset($classMap[$selectedClassId])) {
        $_SESSION['flash_message'] = 'Select a valid assigned class.';
        $_SESSION['flash_type'] = 'warning';
        $redirect = 'teacher-attendance-boundary.php';
        if ($selectedClassId > 0) $redirect .= '?class_record_id=' . $selectedClassId;
        header('Location: ' . $redirect);
        exit;
    }

    $input = [
        'geofence_enabled' => !empty($_POST['geofence_enabled']) ? 1 : 0,
        'center_latitude' => trim((string) ($_POST['center_latitude'] ?? '')),
        'center_longitude' => trim((string) ($_POST['center_longitude'] ?? '')),
        'radius_meters' => (int) ($_POST['radius_meters'] ?? 60),
        'max_accuracy_m' => trim((string) ($_POST['max_accuracy_m'] ?? '')),
        'boundary_type' => trim((string) ($_POST['boundary_type'] ?? 'circle')),
        'boundary_polygon' => trim((string) ($_POST['boundary_polygon'] ?? '')),
        'boundary_shapes' => trim((string) ($_POST['boundary_shapes'] ?? '')),
    ];

    [$ok, $saveResult] = attendance_geo_save_class_policy($conn, $selectedClassId, $input, $teacherId, false);
    if ($ok) {
        $saved = is_array($saveResult) ? $saveResult : attendance_geo_get_class_policy($conn, $selectedClassId);
        $savedType = attendance_geo_normalize_boundary_type($saved['boundary_type'] ?? 'circle');
        $msg = !empty($saved['geofence_enabled'])
            ? ('Classroom premises boundary enabled (' . ($savedType === 'polygon' ? 'polygon' : ((int) ($saved['radius_meters'] ?? 60) . 'm circle')) . ').')
            : 'Classroom premises boundary disabled.';
        if (!empty($saved['max_accuracy_m'])) {
            $msg .= ' Max accuracy: +/-' . (int) $saved['max_accuracy_m'] . 'm.';
        }

        $_SESSION['flash_message'] = $msg;
        $_SESSION['flash_type'] = 'success';

        audit_log(
            $conn,
            'attendance.geofence.class_policy.updated',
            'class_record',
            $selectedClassId,
            $msg,
            [
                'class_record_id' => $selectedClassId,
                'campus_id' => (int) ($classMap[$selectedClassId]['campus_id'] ?? 0),
                'geofence_enabled' => !empty($saved['geofence_enabled']) ? 1 : 0,
                'boundary_type' => $savedType,
                'boundary_shapes' => $saved['boundary_shapes'] ?? null,
                'max_accuracy_m' => isset($saved['max_accuracy_m']) ? (int) $saved['max_accuracy_m'] : null,
            ]
        );
    } else {
        $_SESSION['flash_message'] = is_string($saveResult) ? $saveResult : 'Unable to save classroom premises boundary.';
        $_SESSION['flash_type'] = 'danger';
    }

    $redirect = 'teacher-attendance-boundary.php';
    if ($selectedClassId > 0) $redirect .= '?class_record_id=' . $selectedClassId;
    header('Location: ' . $redirect);
    exit;
}

$selectedClass = ($selectedClassId > 0 && isset($classMap[$selectedClassId])) ? $classMap[$selectedClassId] : null;
$selectedCampusId = is_array($selectedClass) ? (int) ($selectedClass['campus_id'] ?? 0) : 0;
$selectedCampus = ($selectedCampusId > 0 && isset($campusMap[$selectedCampusId])) ? $campusMap[$selectedCampusId] : null;

$classPolicy = attendance_geo_get_class_policy($conn, $selectedClassId);
$classShapes = attendance_geo_policy_shapes($classPolicy);
$classLegacy = attendance_geo_shapes_first_legacy($classShapes);
$classBoundaryType = attendance_geo_normalize_boundary_type($classLegacy['boundary_type'] ?? ($classPolicy['boundary_type'] ?? 'circle'));
$classBoundaryPolygon = is_array($classLegacy['boundary_polygon'] ?? null) ? $classLegacy['boundary_polygon'] : [];
$classBoundaryPolygonJson = json_encode($classBoundaryPolygon, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$classBoundaryShapesJson = json_encode($classShapes, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

$campusPolicy = attendance_geo_get_policy($conn, $selectedCampusId);
$campusShapes = attendance_geo_policy_shapes($campusPolicy);
$campusShapesJson = json_encode($campusShapes, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$campusBoundaryCount = count($campusShapes);
$campusBoundaryReady = ((int) ($campusPolicy['geofence_enabled'] ?? 0) === 1) && ($campusBoundaryCount > 0);
$maxAccuracyValue = isset($classPolicy['max_accuracy_m']) && $classPolicy['max_accuracy_m'] !== null
    ? (string) ((int) $classPolicy['max_accuracy_m'])
    : '';
?>

<?php include '../layouts/main.php'; ?>

<head>
    <title>Classroom Boundary | E-Record</title>
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
                                    <li class="breadcrumb-item"><a href="teacher-dashboard.php">Teacher</a></li>
                                    <li class="breadcrumb-item active">Classroom Boundary</li>
                                </ol>
                            </div>
                            <h4 class="page-title">Classroom Premises Boundary</h4>
                        </div>
                    </div>
                </div>

                <?php if ($flash !== ''): ?>
                    <div class="row">
                        <div class="col-12">
                            <div class="alert <?php echo tgeo_h(tgeo_flash_class($flashType)); ?>" role="alert">
                                <?php echo tgeo_h($flash); ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (count($assigned) === 0): ?>
                    <div class="alert alert-info">No active assigned classes found for this teacher account.</div>
                <?php else: ?>
                    <div class="card mb-3">
                        <div class="card-body">
                            <form method="get" class="row g-2 align-items-end">
                                <div class="col-lg-9">
                                    <label class="form-label mb-1">Class Record</label>
                                    <select class="form-select" name="class_record_id" required>
                                        <?php foreach ($assigned as $row): ?>
                                            <?php
                                            $cid = (int) ($row['class_record_id'] ?? 0);
                                            $label = trim((string) ($row['subject_code'] ?? ''));
                                            $subjectName = trim((string) ($row['subject_name'] ?? ''));
                                            if ($subjectName !== '') $label .= ' - ' . $subjectName;
                                            $label .= ' | ' . trim((string) ($row['section'] ?? ''));
                                            $label .= ' | ' . trim((string) ($row['academic_year'] ?? '')) . ' ' . trim((string) ($row['semester'] ?? ''));
                                            ?>
                                            <option value="<?php echo $cid; ?>" <?php echo $cid === $selectedClassId ? 'selected' : ''; ?>>
                                                <?php echo tgeo_h($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-lg-3 d-grid">
                                    <button class="btn btn-primary" type="submit">Load Class</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <?php if (!is_array($selectedClass)): ?>
                        <div class="alert alert-warning">Select a valid class to continue.</div>
                    <?php else: ?>
                        <?php if ($campusBoundaryReady): ?>
                            <div class="alert alert-success py-2">
                                Campus boundary is configured. Your classroom boundary must stay inside it.
                            </div>
                        <?php else: ?>
                            <div class="alert alert-warning py-2">
                                Campus boundary is not ready yet. You can draft, but you cannot enable classroom boundary until campus admin configures and enables the campus boundary.
                            </div>
                        <?php endif; ?>

                        <div class="row">
                            <div class="col-lg-8">
                                <div class="card">
                                    <div class="card-body">
                                        <h4 class="header-title mb-2">Classroom Boundary</h4>
                                        <div class="text-muted small mb-3">Set one classroom boundary only (circle or polygon).</div>

                                        <form method="post" id="tgeoPolicyForm" class="row g-3">
                                            <input type="hidden" name="csrf_token" value="<?php echo tgeo_h(csrf_token()); ?>">
                                            <input type="hidden" name="action" value="save_policy">
                                            <input type="hidden" name="class_record_id" value="<?php echo (int) $selectedClassId; ?>">
                                            <input type="hidden" name="boundary_type" id="tgeoBoundaryType" value="<?php echo tgeo_h($classBoundaryType); ?>">
                                            <input type="hidden" name="boundary_polygon" id="tgeoBoundaryPolygon" value="<?php echo tgeo_h($classBoundaryPolygonJson); ?>">
                                            <input type="hidden" name="boundary_shapes" id="tgeoBoundaryShapes" value="<?php echo tgeo_h($classBoundaryShapesJson); ?>">

                                            <div class="col-12">
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" role="switch" id="tgeoEnabled" name="geofence_enabled" value="1" <?php echo !empty($classPolicy['geofence_enabled']) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="tgeoEnabled">Enable classroom premises boundary</label>
                                                </div>
                                            </div>

                                            <div class="col-md-6">
                                                <label class="form-label d-block">Boundary Mode</label>
                                                <div class="btn-group btn-group-sm" role="group" aria-label="Boundary mode">
                                                    <input type="radio" class="btn-check" name="tgeoBoundaryMode" id="tgeoModeCircle" value="circle" autocomplete="off">
                                                    <label class="btn btn-outline-primary" for="tgeoModeCircle">Circle</label>
                                                    <input type="radio" class="btn-check" name="tgeoBoundaryMode" id="tgeoModePolygon" value="polygon" autocomplete="off">
                                                    <label class="btn btn-outline-primary" for="tgeoModePolygon">Polygon</label>
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div id="tgeoBoundaryMap" style="height: 440px; border: 1px solid #e4e8f1; border-radius: 6px;"></div>
                                                <div class="form-text mt-2" id="tgeoMapHint"></div>
                                                <div class="form-text mt-1" id="tgeoBoundaryStats"></div>
                                            </div>

                                            <div id="tgeoCircleControls1" class="col-md-6">
                                                <label class="form-label">Center Latitude</label>
                                                <input type="text" class="form-control" name="center_latitude" id="tgeoLat"
                                                       value="<?php echo tgeo_h($classLegacy['center_latitude'] !== null ? number_format((float) $classLegacy['center_latitude'], 8, '.', '') : ''); ?>">
                                            </div>
                                            <div id="tgeoCircleControls2" class="col-md-6">
                                                <label class="form-label">Center Longitude</label>
                                                <input type="text" class="form-control" name="center_longitude" id="tgeoLng"
                                                       value="<?php echo tgeo_h($classLegacy['center_longitude'] !== null ? number_format((float) $classLegacy['center_longitude'], 8, '.', '') : ''); ?>">
                                            </div>
                                            <div id="tgeoCircleControls3" class="col-md-6">
                                                <label class="form-label">Allowed Radius (meters)</label>
                                                <input type="number" class="form-control" name="radius_meters" id="tgeoRadius"
                                                       min="25" max="50000" step="1"
                                                       value="<?php echo (int) ($classLegacy['radius_meters'] ?? 60); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Max GPS Accuracy (optional)</label>
                                                <input type="number" class="form-control" name="max_accuracy_m" id="tgeoMaxAccuracy"
                                                       min="5" max="5000" step="1"
                                                       value="<?php echo tgeo_h($maxAccuracyValue); ?>" placeholder="Leave blank to disable">
                                            </div>

                                            <div class="col-12 d-flex gap-2">
                                                <button type="button" class="btn btn-outline-secondary" id="tgeoUseMyLocation">Use My Location</button>
                                                <a href="#" class="btn btn-outline-primary" id="tgeoMapPreview" target="_blank" rel="noopener">Open Map Preview</a>
                                                <button type="button" class="btn btn-outline-danger" id="tgeoClearShape">Clear Shape</button>
                                            </div>

                                            <div class="col-12">
                                                <button type="submit" class="btn btn-primary"><i class="ri-save-line me-1"></i>Save Classroom Boundary</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <div class="col-lg-4">
                                <div class="card">
                                    <div class="card-body">
                                        <h4 class="header-title mb-2">Selected Class</h4>
                                        <div class="fw-semibold"><?php echo tgeo_h((string) ($selectedClass['subject_code'] ?? '')); ?> - <?php echo tgeo_h((string) ($selectedClass['subject_name'] ?? '')); ?></div>
                                        <div class="text-muted small mb-3"><?php echo tgeo_h((string) ($selectedClass['section'] ?? '')); ?> | <?php echo tgeo_h((string) ($selectedClass['academic_year'] ?? '')); ?> <?php echo tgeo_h((string) ($selectedClass['semester'] ?? '')); ?></div>

                                        <div class="d-flex justify-content-between small mb-1">
                                            <span class="text-muted">Campus</span>
                                            <span class="fw-semibold text-end">
                                                <?php
                                                if (is_array($selectedCampus)) {
                                                    $campusLabel = trim((string) ($selectedCampus['campus_code'] ?? '') . ' - ' . (string) ($selectedCampus['campus_name'] ?? ''));
                                                    if ($campusLabel === '') $campusLabel = (string) ($selectedCampus['campus_name'] ?? '-');
                                                    echo tgeo_h($campusLabel);
                                                } else {
                                                    echo '-';
                                                }
                                                ?>
                                            </span>
                                        </div>
                                        <div class="d-flex justify-content-between small mb-1">
                                            <span class="text-muted">Campus Boundaries</span>
                                            <span class="fw-semibold"><?php echo (int) $campusBoundaryCount; ?></span>
                                        </div>
                                        <div class="d-flex justify-content-between small mb-1">
                                            <span class="text-muted">Class Policy</span>
                                            <span class="fw-semibold"><?php echo !empty($classPolicy['geofence_enabled']) ? 'Enabled' : 'Disabled'; ?></span>
                                        </div>
                                        <div class="d-flex justify-content-between small mb-1">
                                            <span class="text-muted">Boundary Type</span>
                                            <span class="fw-semibold"><?php echo count($classShapes) > 0 ? tgeo_h(ucfirst($classBoundaryType)) : 'Not set'; ?></span>
                                        </div>
                                        <div class="d-flex justify-content-between small">
                                            <span class="text-muted">Max Accuracy</span>
                                            <span class="fw-semibold"><?php echo !empty($classPolicy['max_accuracy_m']) ? ('&plusmn;' . (int) $classPolicy['max_accuracy_m'] . 'm') : 'Disabled'; ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
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
    var form = document.getElementById('tgeoPolicyForm');
    var enabledToggle = document.getElementById('tgeoEnabled');
    var boundaryTypeInput = document.getElementById('tgeoBoundaryType');
    var boundaryPolygonInput = document.getElementById('tgeoBoundaryPolygon');
    var boundaryShapesInput = document.getElementById('tgeoBoundaryShapes');
    var lat = document.getElementById('tgeoLat');
    var lng = document.getElementById('tgeoLng');
    var radius = document.getElementById('tgeoRadius');
    var preview = document.getElementById('tgeoMapPreview');
    var useLocationBtn = document.getElementById('tgeoUseMyLocation');
    var clearBtn = document.getElementById('tgeoClearShape');
    var modeCircle = document.getElementById('tgeoModeCircle');
    var modePolygon = document.getElementById('tgeoModePolygon');
    var maxAccuracyInput = document.getElementById('tgeoMaxAccuracy');
    var mapHint = document.getElementById('tgeoMapHint');
    var mapStats = document.getElementById('tgeoBoundaryStats');
    var mapContainer = document.getElementById('tgeoBoundaryMap');
    var circleControls1 = document.getElementById('tgeoCircleControls1');
    var circleControls2 = document.getElementById('tgeoCircleControls2');
    var circleControls3 = document.getElementById('tgeoCircleControls3');
    var campusShapes = <?php echo $campusShapesJson ? $campusShapesJson : '[]'; ?>;
    var campusReady = <?php echo $campusBoundaryReady ? 'true' : 'false'; ?>;

    if (!form || !mapContainer || !lat || !lng || !radius || typeof L === 'undefined') return;

    function parsePolygon(raw) {
        var parsed = raw;
        if (typeof parsed === 'string') {
            try { parsed = JSON.parse(parsed); } catch (e) { parsed = []; }
        }
        if (!Array.isArray(parsed)) return [];
        var out = [];
        for (var i = 0; i < parsed.length; i++) {
            var p = parsed[i] || {};
            var pLat = parseFloat(p.lat);
            var pLng = parseFloat(p.lng);
            if (!isFinite(pLat) && Array.isArray(p)) pLat = parseFloat(p[0]);
            if (!isFinite(pLng) && Array.isArray(p)) pLng = parseFloat(p[1]);
            if (!isFinite(pLat) || !isFinite(pLng)) continue;
            if (pLat < -90 || pLat > 90 || pLng < -180 || pLng > 180) continue;
            out.push({ lat: Number(pLat), lng: Number(pLng) });
        }
        return out;
    }

    function normalizeShape(shape) {
        if (!shape || typeof shape !== 'object') return null;
        var type = String(shape.type || shape.boundary_type || 'circle').toLowerCase() === 'polygon' ? 'polygon' : 'circle';
        if (type === 'polygon') {
            var points = parsePolygon(shape.points || shape.boundary_polygon || []);
            if (points.length < 3) return null;
            return { type: 'polygon', points: points };
        }
        var cLat = parseFloat(shape.center_latitude);
        var cLng = parseFloat(shape.center_longitude);
        var cRadius = parseInt(shape.radius_meters, 10);
        if (!isFinite(cLat) || !isFinite(cLng)) return null;
        if (!isFinite(cRadius) || cRadius < 25) cRadius = 60;
        if (cRadius > 50000) cRadius = 50000;
        return {
            type: 'circle',
            center_latitude: Number(cLat.toFixed(8)),
            center_longitude: Number(cLng.toFixed(8)),
            radius_meters: cRadius
        };
    }

    function parseShapes(raw) {
        var parsed = raw;
        if (typeof parsed === 'string') {
            try { parsed = JSON.parse(parsed); } catch (e) { parsed = []; }
        }
        if (!Array.isArray(parsed)) return [];
        var out = [];
        for (var i = 0; i < parsed.length; i++) {
            var shape = normalizeShape(parsed[i]);
            if (shape) out.push(shape);
            if (out.length >= 5) break;
        }
        return out;
    }

    function normalizeMaxAccuracyInput(value) {
        var n = parseInt(String(value == null ? '' : value).trim(), 10);
        if (!isFinite(n) || n <= 0) return '';
        if (n < 5) n = 5;
        if (n > 5000) n = 5000;
        return String(n);
    }

    var storedShapes = parseShapes(boundaryShapesInput ? boundaryShapesInput.value : '[]');
    if (storedShapes.length < 1) {
        var legacyType = (boundaryTypeInput && boundaryTypeInput.value === 'polygon') ? 'polygon' : 'circle';
        if (legacyType === 'polygon') {
            var legacyPoly = parsePolygon(boundaryPolygonInput ? boundaryPolygonInput.value : '[]');
            if (legacyPoly.length >= 3) storedShapes = [{ type: 'polygon', points: legacyPoly }];
        } else {
            var legacyLat = parseFloat(lat.value);
            var legacyLng = parseFloat(lng.value);
            var legacyRadius = parseInt(radius.value, 10);
            if (isFinite(legacyLat) && isFinite(legacyLng)) {
                if (!isFinite(legacyRadius) || legacyRadius < 25) legacyRadius = 60;
                if (legacyRadius > 50000) legacyRadius = 50000;
                storedShapes = [{
                    type: 'circle',
                    center_latitude: Number(legacyLat.toFixed(8)),
                    center_longitude: Number(legacyLng.toFixed(8)),
                    radius_meters: legacyRadius
                }];
            }
        }
    }

    var initLat = parseFloat(lat.value);
    var initLng = parseFloat(lng.value);
    if (!isFinite(initLat)) initLat = 14.59951232;
    if (!isFinite(initLng)) initLng = 120.98422244;
    if (storedShapes.length > 0) {
        if (storedShapes[0].type === 'circle') {
            initLat = Number(storedShapes[0].center_latitude);
            initLng = Number(storedShapes[0].center_longitude);
        } else if (storedShapes[0].points.length > 0) {
            initLat = Number(storedShapes[0].points[0].lat);
            initLng = Number(storedShapes[0].points[0].lng);
        }
    }

    var map = L.map('tgeoBoundaryMap').setView([initLat, initLng], 15);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors',
        maxZoom: 19
    }).addTo(map);

    var campusLayerGroup = L.featureGroup().addTo(map);
    var classLayerGroup = new L.FeatureGroup();
    map.addLayer(classLayerGroup);
    var currentLayer = null;
    var currentType = '';

    function drawShape(shape, group, style) {
        style = style || {};
        if (shape.type === 'polygon') {
            return L.polygon(shape.points, {
                color: style.color || '#2563eb',
                fillColor: style.fillColor || '#93c5fd',
                fillOpacity: isFinite(style.fillOpacity) ? style.fillOpacity : 0.25,
                dashArray: style.dashArray || null,
                weight: isFinite(style.weight) ? style.weight : 2
            }).addTo(group);
        }
        return L.circle([shape.center_latitude, shape.center_longitude], {
            radius: Math.max(25, parseInt(shape.radius_meters, 10) || 60),
            color: style.color || '#2563eb',
            fillColor: style.fillColor || '#93c5fd',
            fillOpacity: isFinite(style.fillOpacity) ? style.fillOpacity : 0.25,
            dashArray: style.dashArray || null,
            weight: isFinite(style.weight) ? style.weight : 2
        }).addTo(group);
    }

    function loadCampusOverlay() {
        campusLayerGroup.clearLayers();
        var shapes = parseShapes(campusShapes || []);
        var bounds = null;
        for (var i = 0; i < shapes.length; i++) {
            var layer = drawShape(shapes[i], campusLayerGroup, {
                color: '#16a34a',
                fillColor: '#86efac',
                fillOpacity: 0.12,
                dashArray: '6 6'
            });
            if (layer && layer.getBounds) {
                if (!bounds) bounds = layer.getBounds();
                else bounds.extend(layer.getBounds());
            }
        }
        return bounds;
    }

    function layerToShape(layer, type) {
        if (!layer) return null;
        if (type === 'polygon') {
            var rings = layer.getLatLngs();
            if (!Array.isArray(rings) || rings.length < 1 || !Array.isArray(rings[0])) return null;
            var points = [];
            for (var i = 0; i < rings[0].length; i++) {
                var p = rings[0][i];
                if (!p || !isFinite(p.lat) || !isFinite(p.lng)) continue;
                points.push({ lat: Number(p.lat), lng: Number(p.lng) });
            }
            if (points.length < 3) return null;
            return { type: 'polygon', points: points };
        }
        var center = layer.getLatLng ? layer.getLatLng() : null;
        if (!center || !isFinite(center.lat) || !isFinite(center.lng)) return null;
        var r = layer.getRadius ? Math.round(layer.getRadius()) : parseInt(radius.value, 10);
        if (!isFinite(r) || r < 25) r = 60;
        if (r > 50000) r = 50000;
        return {
            type: 'circle',
            center_latitude: Number(center.lat.toFixed(8)),
            center_longitude: Number(center.lng.toFixed(8)),
            radius_meters: r
        };
    }

    function currentShape() {
        return layerToShape(currentLayer, currentType);
    }

    function applyMode(type) {
        var mode = type === 'polygon' ? 'polygon' : 'circle';
        if (boundaryTypeInput) boundaryTypeInput.value = mode;
        if (modeCircle) modeCircle.checked = mode === 'circle';
        if (modePolygon) modePolygon.checked = mode === 'polygon';
        var isCircle = mode === 'circle';
        if (circleControls1) circleControls1.style.display = isCircle ? 'block' : 'none';
        if (circleControls2) circleControls2.style.display = isCircle ? 'block' : 'none';
        if (circleControls3) circleControls3.style.display = isCircle ? 'block' : 'none';
        if (mapHint) {
            mapHint.textContent = isCircle
                ? 'Circle mode: draw one classroom circle. Campus boundaries are shown in green.'
                : 'Polygon mode: draw one classroom polygon. Campus boundaries are shown in green.';
        }
        drawControl.setDrawingOptions({
            circle: isCircle ? {
                shapeOptions: { color: '#2563eb', fillColor: '#93c5fd', fillOpacity: 0.25 },
                showRadius: true
            } : false,
            polygon: isCircle ? false : {
                allowIntersection: false,
                showArea: true,
                shapeOptions: { color: '#2563eb', fillColor: '#93c5fd', fillOpacity: 0.25 }
            }
        });
    }

    function applyHiddenEmpty() {
        if (boundaryShapesInput) boundaryShapesInput.value = '[]';
        if (boundaryPolygonInput) boundaryPolygonInput.value = '[]';
    }

    function syncInputsFromLayer() {
        var shape = currentShape();
        if (!shape) {
            applyHiddenEmpty();
            if (mapStats) mapStats.textContent = 'No classroom boundary drawn yet.';
            if (preview) {
                preview.setAttribute('href', '#');
                preview.classList.add('disabled');
            }
            return;
        }

        if (boundaryShapesInput) boundaryShapesInput.value = JSON.stringify([shape]);
        if (boundaryTypeInput) boundaryTypeInput.value = shape.type === 'polygon' ? 'polygon' : 'circle';

        if (shape.type === 'polygon') {
            if (boundaryPolygonInput) boundaryPolygonInput.value = JSON.stringify(shape.points || []);
            lat.value = '';
            lng.value = '';
            radius.value = '';
            if (mapStats) mapStats.textContent = 'Polygon vertices: ' + shape.points.length + '.';
            if (preview && shape.points.length > 0) {
                preview.setAttribute('href', 'https://www.google.com/maps?q=' + encodeURIComponent(shape.points[0].lat + ',' + shape.points[0].lng));
                preview.classList.remove('disabled');
            }
            return;
        }

        if (boundaryPolygonInput) boundaryPolygonInput.value = '[]';
        lat.value = Number(shape.center_latitude).toFixed(8);
        lng.value = Number(shape.center_longitude).toFixed(8);
        radius.value = String(parseInt(shape.radius_meters, 10));
        if (mapStats) {
            mapStats.textContent = 'Circle center: ' +
                Number(shape.center_latitude).toFixed(6) + ', ' +
                Number(shape.center_longitude).toFixed(6) +
                ' | Radius: ' + parseInt(shape.radius_meters, 10) + 'm';
        }
        if (preview) {
            preview.setAttribute('href', 'https://www.google.com/maps?q=' + encodeURIComponent(shape.center_latitude + ',' + shape.center_longitude));
            preview.classList.remove('disabled');
        }
    }

    function setClassShape(shape, fit) {
        classLayerGroup.clearLayers();
        currentLayer = null;
        currentType = '';
        if (!shape) {
            applyHiddenEmpty();
            syncInputsFromLayer();
            return;
        }
        currentLayer = drawShape(shape, classLayerGroup, {});
        currentType = shape.type === 'polygon' ? 'polygon' : 'circle';
        applyMode(currentType);
        syncInputsFromLayer();
        if (fit && currentLayer && currentLayer.getBounds) {
            map.fitBounds(currentLayer.getBounds(), { maxZoom: 18 });
        }
    }

    function updateCircleFromInputs() {
        if (!currentLayer || currentType !== 'circle') return;
        var nextLat = parseFloat(lat.value);
        var nextLng = parseFloat(lng.value);
        var nextRadius = parseInt(radius.value, 10);
        if (!isFinite(nextLat) || !isFinite(nextLng) || !isFinite(nextRadius)) return;
        if (nextRadius < 25) nextRadius = 25;
        if (nextRadius > 50000) nextRadius = 50000;
        currentLayer.setLatLng([nextLat, nextLng]);
        currentLayer.setRadius(nextRadius);
        syncInputsFromLayer();
    }

    function ensureCircle() {
        if (currentLayer && currentType === 'circle') return;
        var cLat = parseFloat(lat.value);
        var cLng = parseFloat(lng.value);
        var cRadius = parseInt(radius.value, 10);
        if (!isFinite(cLat) || !isFinite(cLng)) {
            cLat = initLat;
            cLng = initLng;
        }
        if (!isFinite(cRadius) || cRadius < 25) cRadius = 60;
        if (cRadius > 50000) cRadius = 50000;
        setClassShape({
            type: 'circle',
            center_latitude: Number(cLat.toFixed(8)),
            center_longitude: Number(cLng.toFixed(8)),
            radius_meters: cRadius
        }, false);
    }

    var drawControl = new L.Control.Draw({
        draw: {
            polyline: false,
            rectangle: false,
            marker: false,
            circlemarker: false,
            circle: {
                shapeOptions: { color: '#2563eb', fillColor: '#93c5fd', fillOpacity: 0.25 },
                showRadius: true
            },
            polygon: false
        },
        edit: {
            featureGroup: classLayerGroup,
            remove: true
        }
    });
    map.addControl(drawControl);

    map.on(L.Draw.Event.CREATED, function (event) {
        classLayerGroup.clearLayers();
        currentLayer = event.layer;
        currentType = event.layerType === 'polygon' ? 'polygon' : 'circle';
        currentLayer.addTo(classLayerGroup);
        applyMode(currentType);
        syncInputsFromLayer();
    });

    map.on(L.Draw.Event.EDITED, function () {
        syncInputsFromLayer();
    });

    map.on(L.Draw.Event.DELETED, function () {
        currentLayer = null;
        currentType = '';
        applyHiddenEmpty();
        syncInputsFromLayer();
    });

    if (modeCircle) {
        modeCircle.addEventListener('change', function () {
            if (!modeCircle.checked) return;
            applyMode('circle');
            ensureCircle();
        });
    }
    if (modePolygon) {
        modePolygon.addEventListener('change', function () {
            if (!modePolygon.checked) return;
            classLayerGroup.clearLayers();
            currentLayer = null;
            currentType = '';
            applyMode('polygon');
            applyHiddenEmpty();
            syncInputsFromLayer();
        });
    }

    if (lat) {
        lat.addEventListener('input', updateCircleFromInputs);
        lat.addEventListener('change', updateCircleFromInputs);
    }
    if (lng) {
        lng.addEventListener('input', updateCircleFromInputs);
        lng.addEventListener('change', updateCircleFromInputs);
    }
    if (radius) {
        radius.addEventListener('input', updateCircleFromInputs);
        radius.addEventListener('change', updateCircleFromInputs);
    }

    if (maxAccuracyInput) {
        maxAccuracyInput.addEventListener('blur', function () {
            maxAccuracyInput.value = normalizeMaxAccuracyInput(maxAccuracyInput.value);
        });
    }

    if (useLocationBtn) {
        useLocationBtn.addEventListener('click', function () {
            if (!navigator.geolocation || !navigator.geolocation.getCurrentPosition) {
                alert('Geolocation is not supported by this browser.');
                return;
            }
            var old = useLocationBtn.textContent;
            useLocationBtn.disabled = true;
            useLocationBtn.textContent = 'Locating...';
            navigator.geolocation.getCurrentPosition(
                function (pos) {
                    useLocationBtn.disabled = false;
                    useLocationBtn.textContent = old;
                    if (!pos || !pos.coords) return;
                    var cLat = Number(pos.coords.latitude.toFixed(8));
                    var cLng = Number(pos.coords.longitude.toFixed(8));
                    map.setView([cLat, cLng], 17);
                    lat.value = cLat.toFixed(8);
                    lng.value = cLng.toFixed(8);
                    if (!modePolygon || !modePolygon.checked) ensureCircle();
                },
                function () {
                    useLocationBtn.disabled = false;
                    useLocationBtn.textContent = old;
                    alert('Unable to get your current location.');
                },
                {
                    enableHighAccuracy: true,
                    timeout: 8000,
                    maximumAge: 10000
                }
            );
        });
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            classLayerGroup.clearLayers();
            currentLayer = null;
            currentType = '';
            applyMode('circle');
            applyHiddenEmpty();
            lat.value = '';
            lng.value = '';
            radius.value = '60';
            syncInputsFromLayer();
        });
    }

    form.addEventListener('submit', function (event) {
        if (maxAccuracyInput) {
            maxAccuracyInput.value = normalizeMaxAccuracyInput(maxAccuracyInput.value);
        }

        var enabled = enabledToggle ? !!enabledToggle.checked : true;
        var shape = currentShape();
        if (enabled && !campusReady) {
            event.preventDefault();
            alert('Campus boundary is not configured yet. Ask campus admin to enable it first.');
            return;
        }

        if (enabled && !shape) {
            if (boundaryTypeInput && boundaryTypeInput.value === 'circle') {
                var fLat = parseFloat(lat.value);
                var fLng = parseFloat(lng.value);
                var fRadius = parseInt(radius.value, 10);
                if (isFinite(fLat) && isFinite(fLng)) {
                    if (!isFinite(fRadius) || fRadius < 25) fRadius = 60;
                    if (fRadius > 50000) fRadius = 50000;
                    shape = {
                        type: 'circle',
                        center_latitude: Number(fLat.toFixed(8)),
                        center_longitude: Number(fLng.toFixed(8)),
                        radius_meters: fRadius
                    };
                }
            }
        }

        if (enabled && !shape) {
            event.preventDefault();
            alert('Draw one classroom boundary before saving.');
            return;
        }

        if (!enabled && !shape) {
            applyHiddenEmpty();
            if (boundaryTypeInput) boundaryTypeInput.value = 'circle';
            return;
        }

        if (!shape) return;
        if (boundaryShapesInput) boundaryShapesInput.value = JSON.stringify([shape]);
        if (boundaryTypeInput) boundaryTypeInput.value = shape.type === 'polygon' ? 'polygon' : 'circle';
        if (shape.type === 'polygon') {
            if (boundaryPolygonInput) boundaryPolygonInput.value = JSON.stringify(shape.points || []);
            lat.value = '';
            lng.value = '';
        } else {
            if (boundaryPolygonInput) boundaryPolygonInput.value = '[]';
            lat.value = Number(shape.center_latitude).toFixed(8);
            lng.value = Number(shape.center_longitude).toFixed(8);
            radius.value = String(parseInt(shape.radius_meters, 10));
        }
    });

    var campusBounds = loadCampusOverlay();
    if (storedShapes.length > 0) {
        setClassShape(storedShapes[0], false);
    } else {
        applyMode('circle');
        syncInputsFromLayer();
    }

    if (campusBounds && campusBounds.isValid && campusBounds.isValid()) {
        map.fitBounds(campusBounds, { maxZoom: 17 });
        if (storedShapes.length > 0 && currentLayer && currentLayer.getBounds) {
            map.fitBounds(currentLayer.getBounds(), { maxZoom: 18 });
        }
    }
})();
</script>
</body>
</html>
