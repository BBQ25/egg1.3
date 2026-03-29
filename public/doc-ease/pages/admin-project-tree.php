<?php include '../layouts/session.php'; ?>
<?php require_role('superadmin'); ?>
<?php require_once __DIR__ . '/../includes/project_tree.php'; ?>

<?php
$projectRoot = realpath(__DIR__ . '/..');
if (!is_string($projectRoot) || $projectRoot === '') $projectRoot = dirname(__DIR__);

$depth = project_tree_clamp_int((int) ($_GET['depth'] ?? 3), 1, 8);
$items = project_tree_clamp_int((int) ($_GET['items'] ?? 30), 5, 300);
$lines = project_tree_collect_lines($projectRoot, $depth, $items, project_tree_default_excludes());
$generatedAt = date('Y-m-d H:i:s');

if ((string) ($_GET['ajax'] ?? '') === '1') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'status' => 'ok',
        'root' => $projectRoot,
        'depth' => $depth,
        'items' => $items,
        'generated_at' => $generatedAt,
        'line_count' => count($lines),
        'lines' => $lines,
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if (!function_exists('project_tree_h')) {
    function project_tree_h($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

$initialPayload = [
    'status' => 'ok',
    'root' => $projectRoot,
    'depth' => $depth,
    'items' => $items,
    'generated_at' => $generatedAt,
    'line_count' => count($lines),
    'lines' => $lines,
];
?>
<?php include '../layouts/main.php'; ?>

<head>
    <title>Project Tree | E-Record</title>
    <?php include '../layouts/title-meta.php'; ?>
    <?php include '../layouts/head-css.php'; ?>
    <style>
        .tree-wrap {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 60%, #0b1120 100%);
            border-radius: 14px;
            border: 1px solid rgba(148, 163, 184, 0.25);
            padding: 16px;
        }
        .tree-head {
            border-bottom: 1px solid rgba(148, 163, 184, 0.2);
            margin-bottom: 12px;
            padding-bottom: 12px;
        }
        .tree-title {
            color: #f8fafc;
            font-weight: 700;
            margin: 0;
        }
        .tree-subtitle {
            color: #94a3b8;
            margin: 4px 0 0;
            font-size: 12px;
            word-break: break-all;
        }
        .tree-controls .form-select,
        .tree-controls .form-check-label {
            font-size: 13px;
        }
        .tree-lines {
            min-height: 380px;
            max-height: 70vh;
            overflow: auto;
            font-family: Consolas, "Courier New", monospace;
            font-size: 13px;
            line-height: 1.45;
            background: rgba(15, 23, 42, 0.5);
            border-radius: 10px;
            border: 1px solid rgba(100, 116, 139, 0.25);
            padding: 12px;
            white-space: pre;
        }
        .tree-line {
            display: block;
        }
        .tree-status {
            color: #94a3b8;
            font-size: 12px;
            margin-top: 10px;
        }
        .tree-error {
            color: #fca5a5;
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
                                        <li class="breadcrumb-item"><a href="javascript:void(0);">E-Record</a></li>
                                        <li class="breadcrumb-item"><a href="javascript:void(0);">Superadmin</a></li>
                                        <li class="breadcrumb-item active">Project Tree</li>
                                    </ol>
                                </div>
                                <h4 class="page-title">Project Tree (Live)</h4>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-12">
                            <div class="tree-wrap">
                                <div class="tree-head">
                                    <h5 class="tree-title">Live Filesystem Snapshot</h5>
                                    <p class="tree-subtitle">
                                        Root: <span id="treeRoot"><?php echo project_tree_h($projectRoot); ?></span>
                                    </p>
                                </div>

                                <div class="row g-2 align-items-end tree-controls">
                                    <div class="col-sm-3 col-md-2">
                                        <label class="form-label text-light mb-1" for="treeDepth">Depth</label>
                                        <select id="treeDepth" class="form-select form-select-sm">
                                            <?php for ($i = 1; $i <= 8; $i++): ?>
                                                <option value="<?php echo (int) $i; ?>"<?php echo $i === $depth ? ' selected' : ''; ?>>
                                                    <?php echo (int) $i; ?>
                                                </option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                    <div class="col-sm-3 col-md-2">
                                        <label class="form-label text-light mb-1" for="treeItems">Items/Dir</label>
                                        <select id="treeItems" class="form-select form-select-sm">
                                            <?php foreach ([10, 20, 30, 50, 80, 120, 200] as $itemLimit): ?>
                                                <option value="<?php echo (int) $itemLimit; ?>"<?php echo $itemLimit === $items ? ' selected' : ''; ?>>
                                                    <?php echo (int) $itemLimit; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-sm-3 col-md-3">
                                        <div class="form-check mt-4">
                                            <input class="form-check-input" type="checkbox" id="treeAutoRefresh">
                                            <label class="form-check-label text-light" for="treeAutoRefresh">
                                                Auto refresh (10s)
                                            </label>
                                        </div>
                                    </div>
                                    <div class="col-sm-3 col-md-2">
                                        <button id="treeRefreshBtn" class="btn btn-sm btn-primary w-100">
                                            <i class="ri-refresh-line"></i> Refresh Now
                                        </button>
                                    </div>
                                </div>

                                <div id="treeLines" class="tree-lines mt-3"></div>
                                <div id="treeStatus" class="tree-status"></div>
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
    <script>
    (function () {
        var payload = <?php echo json_encode($initialPayload, JSON_UNESCAPED_SLASHES); ?>;
        var linesEl = document.getElementById('treeLines');
        var statusEl = document.getElementById('treeStatus');
        var rootEl = document.getElementById('treeRoot');
        var refreshBtn = document.getElementById('treeRefreshBtn');
        var depthEl = document.getElementById('treeDepth');
        var itemsEl = document.getElementById('treeItems');
        var autoEl = document.getElementById('treeAutoRefresh');

        var palette = ['#93c5fd', '#a5b4fc', '#6ee7b7', '#fcd34d', '#fca5a5', '#c4b5fd'];
        var timerId = null;

        function esc(text) {
            return String(text)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function lineColor(line) {
            if (!line || typeof line !== 'object') return '#cbd5e1';
            if (line.kind === 'warn') return '#fbbf24';
            if (line.kind === 'error') return '#f87171';
            if (line.kind === 'meta') return '#94a3b8';
            if (!line.is_dir) return '#cbd5e1';
            var depth = Number(line.depth || 0);
            return palette[depth % palette.length];
        }

        function render(data) {
            payload = data || payload;
            var lines = Array.isArray(payload.lines) ? payload.lines : [];
            var html = '';
            for (var i = 0; i < lines.length; i++) {
                var line = lines[i] || {};
                html += '<span class="tree-line" style="color:' + lineColor(line) + ';">' + esc(line.text || '') + '</span>';
            }
            linesEl.innerHTML = html;

            if (rootEl) rootEl.textContent = payload.root || '';
            statusEl.classList.remove('tree-error');
            statusEl.textContent =
                'Generated: ' + (payload.generated_at || '-') +
                ' | Lines: ' + String(payload.line_count || lines.length) +
                ' | Depth: ' + String(payload.depth || depthEl.value) +
                ' | Items/Dir: ' + String(payload.items || itemsEl.value);
        }

        function setError(message) {
            statusEl.classList.add('tree-error');
            statusEl.textContent = message;
        }

        function buildUrl() {
            var depth = encodeURIComponent(depthEl.value || '3');
            var items = encodeURIComponent(itemsEl.value || '30');
            var nonce = Date.now();
            return 'admin-project-tree.php?ajax=1&depth=' + depth + '&items=' + items + '&_t=' + nonce;
        }

        function refreshTree() {
            refreshBtn.disabled = true;
            return fetch(buildUrl(), {
                method: 'GET',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            })
            .then(function (res) {
                if (!res.ok) throw new Error('Request failed (' + res.status + ')');
                return res.json();
            })
            .then(function (data) {
                if (!data || data.status !== 'ok') {
                    throw new Error((data && data.message) ? data.message : 'Unexpected response.');
                }
                render(data);
            })
            .catch(function (err) {
                setError('Unable to refresh project tree: ' + (err && err.message ? err.message : 'Unknown error'));
            })
            .finally(function () {
                refreshBtn.disabled = false;
            });
        }

        function stopAuto() {
            if (timerId) {
                window.clearInterval(timerId);
                timerId = null;
            }
        }

        function startAuto() {
            stopAuto();
            timerId = window.setInterval(refreshTree, 10000);
        }

        depthEl.addEventListener('change', refreshTree);
        itemsEl.addEventListener('change', refreshTree);
        refreshBtn.addEventListener('click', function () { refreshTree(); });
        autoEl.addEventListener('change', function () {
            if (autoEl.checked) startAuto();
            else stopAuto();
        });

        render(payload);
    })();
    </script>
</body>
</html>

