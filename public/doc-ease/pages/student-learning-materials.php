<?php include '../layouts/session.php'; ?>
<?php require_approved_student(); ?>
<?php include '../layouts/main.php'; ?>

<?php
require_once __DIR__ . '/../includes/learning_materials.php';
ensure_learning_material_tables($conn);

$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$classRecordId = isset($_GET['class_record_id']) ? (int) $_GET['class_record_id'] : 0;
if ($classRecordId <= 0) {
    $_SESSION['flash_message'] = 'Invalid class.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: student-dashboard.php');
    exit;
}

$flash = isset($_SESSION['flash_message']) ? (string) $_SESSION['flash_message'] : '';
$flashType = isset($_SESSION['flash_type']) ? (string) $_SESSION['flash_type'] : 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

if (!function_exists('slm_h')) {
    function slm_h($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('slm_fmt_datetime')) {
    function slm_fmt_datetime($value) {
        $value = trim((string) $value);
        if ($value === '') return '-';
        $ts = strtotime($value);
        return $ts ? date('Y-m-d H:i', $ts) : $value;
    }
}

$student = null;
$st = $conn->prepare(
    "SELECT id
     FROM students
     WHERE user_id = ?
     LIMIT 1"
);
if ($st) {
    $st->bind_param('i', $userId);
    $st->execute();
    $res = $st->get_result();
    if ($res && $res->num_rows === 1) $student = $res->fetch_assoc();
    $st->close();
}
if (!is_array($student)) {
    deny_access(403, 'Student profile is not linked to this account.');
}
$studentId = (int) ($student['id'] ?? 0);

$ctx = null;
$ctxQ = $conn->prepare(
    "SELECT cr.id AS class_record_id,
            cr.section, cr.academic_year, cr.semester,
            s.subject_code, s.subject_name
     FROM class_enrollments ce
     JOIN class_records cr ON cr.id = ce.class_record_id
     JOIN subjects s ON s.id = cr.subject_id
     WHERE ce.student_id = ?
       AND ce.class_record_id = ?
       AND ce.status = 'enrolled'
       AND cr.status = 'active'
     LIMIT 1"
);
if ($ctxQ) {
    $ctxQ->bind_param('ii', $studentId, $classRecordId);
    $ctxQ->execute();
    $res = $ctxQ->get_result();
    if ($res && $res->num_rows === 1) $ctx = $res->fetch_assoc();
    $ctxQ->close();
}
if (!is_array($ctx)) {
    deny_access(403, 'Forbidden: this class is not in your enrolled records.');
}

$materials = [];
$q = $conn->prepare(
    "SELECT lm.id, lm.title, lm.summary, lm.content_html, lm.published_at, lm.updated_at,
            (
                SELECT COUNT(*)
                FROM learning_material_files lmf
                WHERE lmf.material_id = lm.id
            ) AS attachment_count
     FROM learning_materials lm
     WHERE lm.class_record_id = ?
       AND lm.status = 'published'
     ORDER BY lm.display_order ASC, lm.id ASC"
);
if ($q) {
    $q->bind_param('i', $classRecordId);
    $q->execute();
    $res = $q->get_result();
    while ($res && ($row = $res->fetch_assoc())) $materials[] = $row;
    $q->close();
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
                                        <li class="breadcrumb-item"><a href="student-dashboard.php">My Grades &amp; Scores</a></li>
                                        <li class="breadcrumb-item active">Learning Materials</li>
                                    </ol>
                                </div>
                                <h4 class="page-title">Learning Materials</h4>
                            </div>
                        </div>
                    </div>

                    <?php if ($flash !== ''): ?>
                        <div class="alert alert-<?php echo slm_h($flashType); ?> alert-dismissible fade show" role="alert">
                            <?php echo slm_h($flash); ?>
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
                                                <?php echo slm_h((string) ($ctx['subject_name'] ?? 'Subject')); ?>
                                                <?php if (!empty($ctx['subject_code'])): ?>
                                                    <span class="text-muted">(<?php echo slm_h((string) ($ctx['subject_code'] ?? '')); ?>)</span>
                                                <?php endif; ?>
                                            </h5>
                                            <p class="text-muted mb-0">
                                                Section: <?php echo slm_h((string) ($ctx['section'] ?? '')); ?> |
                                                <?php echo slm_h((string) ($ctx['academic_year'] ?? '')); ?> |
                                                <?php echo slm_h((string) ($ctx['semester'] ?? '')); ?>
                                            </p>
                                        </div>
                                        <div class="d-flex gap-2 flex-wrap">
                                            <a class="btn btn-outline-primary" href="student-live-slide.php?class_record_id=<?php echo (int) $classRecordId; ?>">
                                                <i class="ri-broadcast-line me-1" aria-hidden="true"></i>
                                                Join Live Slide
                                            </a>
                                            <a class="btn btn-outline-secondary" href="student-dashboard.php">
                                                <i class="ri-arrow-left-line me-1" aria-hidden="true"></i>
                                                Back to Dashboard
                                            </a>
                                        </div>
                                    </div>

                                    <?php if (count($materials) === 0): ?>
                                        <div class="alert alert-light border mb-0">
                                            No published learning materials yet for this class.
                                        </div>
                                    <?php else: ?>
                                        <div class="row">
                                            <?php foreach ($materials as $material): ?>
                                                <?php
                                                $materialId = (int) ($material['id'] ?? 0);
                                                $excerpt = learning_material_excerpt(
                                                    (string) ($material['summary'] ?? ''),
                                                    (string) ($material['content_html'] ?? ''),
                                                    280
                                                );
                                                ?>
                                                <div class="col-lg-6 mb-3">
                                                    <div class="card border h-100 mb-0">
                                                        <div class="card-body d-flex flex-column">
                                                            <h5 class="mb-2"><?php echo slm_h((string) ($material['title'] ?? 'Untitled')); ?></h5>
                                                            <div class="text-muted small mb-2">
                                                                Published: <?php echo slm_h(slm_fmt_datetime((string) ($material['published_at'] ?? ''))); ?>
                                                            </div>
                                                            <div class="text-muted small mb-2">
                                                                <i class="ri-attachment-2 me-1" aria-hidden="true"></i>
                                                                <?php echo (int) ($material['attachment_count'] ?? 0); ?> attachment(s)
                                                            </div>
                                                            <p class="text-muted flex-grow-1 mb-3">
                                                                <?php echo slm_h($excerpt !== '' ? $excerpt : 'Open this material to read the full content.'); ?>
                                                            </p>
                                                            <div>
                                                                <a class="btn btn-sm btn-primary" href="student-learning-material.php?material_id=<?php echo $materialId; ?>">
                                                                    Read Material
                                                                </a>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
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
</body>

</html>
