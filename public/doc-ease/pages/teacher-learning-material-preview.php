<?php include '../layouts/session.php'; ?>
<?php require_active_role('teacher'); ?>
<?php include '../layouts/main.php'; ?>

<?php
require_once __DIR__ . '/../includes/learning_materials.php';
ensure_learning_material_tables($conn);

$teacherId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$materialId = isset($_GET['material_id']) ? (int) $_GET['material_id'] : 0;
if ($materialId <= 0) {
    $_SESSION['flash_message'] = 'Invalid learning material.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: teacher-my-classes.php');
    exit;
}

if (!function_exists('tlmp_h')) {
    function tlmp_h($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('tlmp_fmt_datetime')) {
    function tlmp_fmt_datetime($value) {
        $value = trim((string) $value);
        if ($value === '') return '-';
        $ts = strtotime($value);
        return $ts ? date('Y-m-d H:i', $ts) : $value;
    }
}
if (!function_exists('tlmp_read_minutes')) {
    function tlmp_read_minutes($contentHtml) {
        $text = learning_material_plain_text($contentHtml);
        $words = $text === '' ? 0 : count(preg_split('/\s+/', $text));
        if ($words <= 0) return 1;
        return max(1, (int) ceil($words / 220));
    }
}

$material = null;
$q = $conn->prepare(
    "SELECT lm.id,
            lm.class_record_id,
            lm.title,
            lm.summary,
            lm.content_html,
            lm.status,
            lm.published_at,
            lm.updated_at,
            cr.section, cr.academic_year, cr.semester,
            s.subject_code, s.subject_name
     FROM learning_materials lm
     JOIN class_records cr ON cr.id = lm.class_record_id
     JOIN subjects s ON s.id = cr.subject_id
     JOIN teacher_assignments ta
        ON ta.class_record_id = cr.id
       AND ta.teacher_id = ?
       AND ta.status = 'active'
     WHERE lm.id = ?
       AND cr.status = 'active'
     LIMIT 1"
);
if ($q) {
    $q->bind_param('ii', $teacherId, $materialId);
    $q->execute();
    $res = $q->get_result();
    if ($res && $res->num_rows === 1) $material = $res->fetch_assoc();
    $q->close();
}
if (!is_array($material)) {
    deny_access(403, 'Forbidden: this material is not available for your assigned classes.');
}

$safeContentHtml = learning_material_sanitize_html((string) ($material['content_html'] ?? ''));
$readMinutes = tlmp_read_minutes($safeContentHtml);
$classRecordId = (int) ($material['class_record_id'] ?? 0);
$status = learning_material_normalize_status((string) ($material['status'] ?? 'draft'));
$attachments = learning_material_fetch_attachments($conn, $materialId);
?>

<head>
    <title><?php echo tlmp_h((string) ($material['title'] ?? 'Learning Material')); ?> | E-Record</title>
    <?php include '../layouts/title-meta.php'; ?>
    <?php include '../layouts/head-css.php'; ?>
    <style>
        .slmv-shell {
            max-width: 960px;
            margin: 0 auto;
        }

        .slmv-header {
            background: linear-gradient(135deg, #f3f7ff 0%, #ffffff 88%);
            border: 1px solid #dfe6f5;
            border-radius: 0.75rem;
            padding: 1.25rem 1.25rem 1rem;
        }

        .slmv-article {
            background: #fff;
            border: 1px solid #e4e8f2;
            border-radius: 0.75rem;
            padding: 1.5rem;
        }

        .slmv-content {
            color: #23344f;
            font-size: 1rem;
            line-height: 1.72;
        }

        .slmv-content h1,
        .slmv-content h2,
        .slmv-content h3,
        .slmv-content h4,
        .slmv-content h5,
        .slmv-content h6 {
            color: #102342;
            margin-top: 1.25rem;
            margin-bottom: 0.7rem;
        }

        .slmv-content img {
            max-width: 100%;
            height: auto;
            border-radius: 0.5rem;
            border: 1px solid #e6eaf2;
            margin: 0.75rem 0;
        }

        .slmv-content pre {
            background: #0f172a;
            color: #f8fafc;
            border-radius: 0.5rem;
            padding: 0.9rem;
            overflow: auto;
        }

        .slmv-content code {
            background: #f5f7fb;
            color: #23344f;
            padding: 0.1rem 0.28rem;
            border-radius: 0.2rem;
            font-size: 0.92rem;
        }

        .slmv-content pre code {
            background: transparent;
            color: inherit;
            padding: 0;
        }

        .slmv-content table {
            width: 100%;
            border-collapse: collapse;
            margin: 1rem 0;
        }

        .slmv-content table th,
        .slmv-content table td {
            border: 1px solid #e2e8f0;
            padding: 0.5rem 0.6rem;
            vertical-align: top;
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
                                        <li class="breadcrumb-item"><a href="teacher-my-classes.php">My Classes</a></li>
                                        <li class="breadcrumb-item"><a href="teacher-learning-materials.php?class_record_id=<?php echo (int) $classRecordId; ?>">Learning Materials</a></li>
                                        <li class="breadcrumb-item active">Preview</li>
                                    </ol>
                                </div>
                                <h4 class="page-title">Learning Material Preview</h4>
                            </div>
                        </div>
                    </div>

                    <div class="slmv-shell">
                        <div class="slmv-header mb-3">
                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                <div>
                                    <h3 class="mb-1"><?php echo tlmp_h((string) ($material['title'] ?? 'Learning Material')); ?></h3>
                                    <div class="text-muted">
                                        <?php echo tlmp_h((string) ($material['subject_name'] ?? 'Subject')); ?>
                                        <?php if (!empty($material['subject_code'])): ?>
                                            (<?php echo tlmp_h((string) ($material['subject_code'] ?? '')); ?>)
                                        <?php endif; ?>
                                        | Section: <?php echo tlmp_h((string) ($material['section'] ?? '')); ?>
                                    </div>
                                </div>
                                <a class="btn btn-outline-secondary btn-sm" href="teacher-learning-material-editor.php?class_record_id=<?php echo (int) $classRecordId; ?>&material_id=<?php echo (int) $materialId; ?>">
                                    <i class="ri-arrow-left-line me-1" aria-hidden="true"></i>
                                    Back to editor
                                </a>
                            </div>
                            <div class="text-muted small mt-2">
                                <span class="badge <?php echo $status === 'published' ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary'; ?>">
                                    <?php echo $status === 'published' ? 'Published' : 'Draft Preview'; ?>
                                </span>
                                | Published: <?php echo tlmp_h(tlmp_fmt_datetime((string) ($material['published_at'] ?? ''))); ?> |
                                Updated: <?php echo tlmp_h(tlmp_fmt_datetime((string) ($material['updated_at'] ?? ''))); ?> |
                                Approx. read time: <?php echo (int) $readMinutes; ?> min
                            </div>
                            <?php if (!empty($material['summary'])): ?>
                                <p class="mb-0 mt-2 text-muted"><?php echo tlmp_h((string) ($material['summary'] ?? '')); ?></p>
                            <?php endif; ?>
                        </div>

                        <article class="slmv-article mb-3">
                            <div class="slmv-content">
                                <?php echo $safeContentHtml; ?>
                            </div>
                        </article>

                        <?php if (count($attachments) > 0): ?>
                            <div class="card">
                                <div class="card-body">
                                    <h5 class="mb-3">Material Files</h5>
                                    <div class="list-group">
                                        <?php foreach ($attachments as $attachment): ?>
                                            <?php $href = learning_material_public_file_href((string) ($attachment['file_path'] ?? '')); ?>
                                            <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center flex-wrap gap-2" href="<?php echo tlmp_h($href); ?>" target="_blank" download>
                                                <span>
                                                    <i class="ri-attachment-2 me-1" aria-hidden="true"></i>
                                                    <?php echo tlmp_h((string) ($attachment['original_name'] ?? 'Attachment')); ?>
                                                </span>
                                                <span class="text-muted small"><?php echo tlmp_h(learning_material_fmt_bytes((int) ($attachment['file_size'] ?? 0))); ?></span>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
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
