<?php include '../layouts/session.php'; ?>
<?php require_role('admin'); ?>

<?php
require_once __DIR__ . '/../includes/audit.php';
ensure_audit_logs_table($conn);

function h($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function actor_name($r) {
    $fn = trim((string) ($r['first_name'] ?? ''));
    $ln = trim((string) ($r['last_name'] ?? ''));
    $full = trim($fn . ' ' . $ln);
    if ($full !== '') return $full;
    return (string) ($r['username'] ?? 'Unknown');
}

$actorId = isset($_GET['actor_user_id']) ? (int) $_GET['actor_user_id'] : 0;
$actionQ = isset($_GET['action']) ? trim((string) $_GET['action']) : '';
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 300;
if ($limit < 50) $limit = 50;
if ($limit > 1000) $limit = 1000;

$rows = [];
$sql =
    "SELECT a.id, a.created_at, a.actor_user_id, a.actor_role, a.action, a.entity_type, a.entity_id, a.message, a.ip,
            u.username, u.first_name, u.last_name
     FROM audit_logs a
     LEFT JOIN users u ON u.id = a.actor_user_id";

if ($actorId > 0 && $actionQ !== '') {
    $sql .= " WHERE a.actor_user_id = ? AND a.action LIKE ?";
    $sql .= " ORDER BY a.id DESC LIMIT " . $limit;
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $like = '%' . $actionQ . '%';
        $stmt->bind_param('is', $actorId, $like);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($r = $res->fetch_assoc())) $rows[] = $r;
        $stmt->close();
    }
} elseif ($actorId > 0) {
    $sql .= " WHERE a.actor_user_id = ?";
    $sql .= " ORDER BY a.id DESC LIMIT " . $limit;
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('i', $actorId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($r = $res->fetch_assoc())) $rows[] = $r;
        $stmt->close();
    }
} elseif ($actionQ !== '') {
    $sql .= " WHERE a.action LIKE ?";
    $sql .= " ORDER BY a.id DESC LIMIT " . $limit;
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $like = '%' . $actionQ . '%';
        $stmt->bind_param('s', $like);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($r = $res->fetch_assoc())) $rows[] = $r;
        $stmt->close();
    }
} else {
    $sql .= " ORDER BY a.id DESC LIMIT " . $limit;
    $res = $conn->query($sql);
    while ($res && ($r = $res->fetch_assoc())) $rows[] = $r;
}
?>

<?php include '../layouts/main.php'; ?>

<head>
    <title>Audit Log | E-Record</title>
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
                                    <li class="breadcrumb-item"><a href="admin-dashboard.php">Admin</a></li>
                                    <li class="breadcrumb-item active">Audit Log</li>
                                </ol>
                            </div>
                            <h4 class="page-title">Audit Log</h4>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <form class="row g-2 align-items-end" method="get">
                            <div class="col-md-3">
                                <label class="form-label">Actor User ID</label>
                                <input type="number" class="form-control" name="actor_user_id" value="<?php echo (int) $actorId; ?>" min="0" placeholder="e.g. 2">
                            </div>
                            <div class="col-md-5">
                                <label class="form-label">Action Contains</label>
                                <input type="text" class="form-control" name="action" value="<?php echo h($actionQ); ?>" placeholder="e.g. profile.change">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Limit</label>
                                <input type="number" class="form-control" name="limit" value="<?php echo (int) $limit; ?>" min="50" max="1000">
                            </div>
                            <div class="col-md-2 d-grid">
                                <button class="btn btn-primary" type="submit">
                                    <i class="ri-search-line me-1"></i>Filter
                                </button>
                            </div>
                        </form>

                        <div class="table-responsive mt-3">
                            <table class="table table-sm table-striped table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Time</th>
                                        <th>Actor</th>
                                        <th>Action</th>
                                        <th>Entity</th>
                                        <th>Message</th>
                                        <th>IP</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($rows) === 0): ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted">No audit logs found.</td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php foreach ($rows as $r): ?>
                                        <?php
                                        $entity = '';
                                        if (!empty($r['entity_type'])) {
                                            $entity = (string) $r['entity_type'];
                                            if (!empty($r['entity_id'])) $entity .= '#' . (string) $r['entity_id'];
                                        }
                                        $msg = (string) ($r['message'] ?? '');
                                        if (strlen($msg) > 140) $msg = substr($msg, 0, 140) . '...';
                                        ?>
                                        <tr>
                                            <td class="text-muted small"><?php echo h((string) ($r['created_at'] ?? '')); ?></td>
                                            <td>
                                                <div class="fw-semibold"><?php echo h(actor_name($r)); ?></div>
                                                <div class="text-muted small">#<?php echo (int) ($r['actor_user_id'] ?? 0); ?> | <?php echo h((string) ($r['actor_role'] ?? '')); ?></div>
                                            </td>
                                            <td><code><?php echo h((string) ($r['action'] ?? '')); ?></code></td>
                                            <td class="text-muted small"><?php echo h($entity !== '' ? $entity : '-'); ?></td>
                                            <td class="text-muted small"><?php echo h($msg !== '' ? $msg : '-'); ?></td>
                                            <td class="text-muted small"><?php echo h((string) ($r['ip'] ?? '') ?: '-'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
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

