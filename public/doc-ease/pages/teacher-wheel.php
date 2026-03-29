<?php include '../layouts/session.php'; ?>
<?php require_active_role('teacher'); ?>
<?php include '../layouts/main.php'; ?>

<?php
require_once __DIR__ . '/../includes/reverse_class_record.php';
if (!function_exists('tw_h')) {
    function tw_h($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

$teacherId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$canUseReverseClassRecord = reverse_class_record_can_teacher_use($conn, $teacherId);
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
            $assigned[] = $row;
        }
        $stmt->close();
    }
}

$classMap = [];
foreach ($assigned as $row) {
    $cid = (int) ($row['class_record_id'] ?? 0);
    if ($cid > 0) $classMap[$cid] = $row;
}

$selectedClassId = isset($_GET['class_record_id']) ? (int) $_GET['class_record_id'] : 0;
if ($selectedClassId <= 0 || !isset($classMap[$selectedClassId])) {
    $selectedClassId = 0;
    if (count($assigned) > 0) {
        $selectedClassId = (int) ($assigned[0]['class_record_id'] ?? 0);
    }
}

$selectedClass = ($selectedClassId > 0 && isset($classMap[$selectedClassId])) ? $classMap[$selectedClassId] : null;

$students = [];
if ($selectedClassId > 0 && is_array($selectedClass)) {
    $st = $conn->prepare(
        "SELECT ce.student_id,
                st.StudentNo AS student_no,
                st.Surname AS surname,
                st.FirstName AS firstname,
                st.MiddleName AS middlename
         FROM class_enrollments ce
         JOIN students st ON st.id = ce.student_id
         WHERE ce.class_record_id = ?
           AND ce.status = 'enrolled'
         ORDER BY st.Surname ASC, st.FirstName ASC, st.MiddleName ASC, st.StudentNo ASC"
    );
    if ($st) {
        $st->bind_param('i', $selectedClassId);
        $st->execute();
        $res = $st->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $studentId = (int) ($row['student_id'] ?? 0);
            if ($studentId <= 0) continue;

            $studentNo = trim((string) ($row['student_no'] ?? ''));
            $surname = trim((string) ($row['surname'] ?? ''));
            $firstname = trim((string) ($row['firstname'] ?? ''));
            $middlename = trim((string) ($row['middlename'] ?? ''));

            $fullName = trim($surname . ', ' . $firstname . ' ' . $middlename);
            $fullName = preg_replace('/\s+/', ' ', $fullName);
            if (!is_string($fullName)) $fullName = '';
            $fullName = trim($fullName, " \t\n\r\0\x0B,");
            if ($fullName === '') $fullName = $studentNo !== '' ? $studentNo : ('Student #' . $studentId);

            $students[] = [
                'id' => $studentId,
                'student_no' => $studentNo,
                'name' => $fullName,
            ];
        }
        $st->close();
    }
}

$studentsJson = json_encode(
    $students,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
if (!is_string($studentsJson)) $studentsJson = '[]';
?>

<head>
    <title>Class Wheel | E-Record</title>
    <?php include '../layouts/title-meta.php'; ?>
    <?php include '../layouts/head-css.php'; ?>
    <style>
        .wheel-panel {
            border: 1px solid rgba(0, 0, 0, 0.12);
            border-radius: 0.75rem;
            background: linear-gradient(180deg, #ffffff 0%, #f7faff 100%);
        }

        .wheel-wrap {
            position: relative;
            margin: 0 auto;
            width: min(100%, 520px);
            aspect-ratio: 1 / 1;
        }

        .wheel-canvas {
            width: 100%;
            height: 100%;
            display: block;
            border-radius: 50%;
        }

        .wheel-pointer {
            position: absolute;
            left: 50%;
            top: -4px;
            transform: translateX(-50%);
            width: 0;
            height: 0;
            border-left: 14px solid transparent;
            border-right: 14px solid transparent;
            border-top: 28px solid #f44336;
            filter: drop-shadow(0 3px 4px rgba(0, 0, 0, 0.24));
            z-index: 2;
        }

        .wheel-controls .btn {
            min-width: 110px;
        }

        .wheel-winner {
            min-height: 58px;
        }

        .wheel-list {
            max-height: 260px;
            overflow: auto;
        }

        .wheel-chip {
            display: inline-block;
            border-radius: 999px;
            border: 1px solid rgba(0, 0, 0, 0.12);
            padding: 2px 8px;
            margin: 0 6px 6px 0;
            font-size: 12px;
            background: #fff;
        }
    </style>
</head>

<body data-wheel-teacher-id="<?php echo (int) $teacherId; ?>" data-wheel-class-id="<?php echo (int) $selectedClassId; ?>">
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
                                        <li class="breadcrumb-item active">Class Wheel</li>
                                    </ol>
                                </div>
                                <h4 class="page-title">Class Wheel</h4>
                            </div>
                        </div>
                    </div>

                    <?php if (count($assigned) === 0): ?>
                        <div class="alert alert-info">No assigned classes were found. Claim or request class assignments first.</div>
                    <?php else: ?>
                        <div class="row g-3">
                            <div class="col-12">
                                <div class="card">
                                    <div class="card-body">
                                        <form method="get" class="row g-2 align-items-end">
                                            <div class="col-xl-8">
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
                                                        <option value="<?php echo (int) $cid; ?>" <?php echo $cid === $selectedClassId ? 'selected' : ''; ?>>
                                                            <?php echo tw_h($label); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-xl-4 d-flex gap-2">
                                                <button class="btn btn-primary" type="submit">
                                                    <i class="ri-filter-3-line me-1" aria-hidden="true"></i>
                                                    Load Class
                                                </button>
                                                <?php if (is_array($selectedClass)): ?>
                                                    <a class="btn btn-outline-secondary"
                                                        href="teacher-grading-config.php?class_record_id=<?php echo (int) $selectedClassId; ?>">
                                                        <i class="ri-scales-3-line me-1" aria-hidden="true"></i>
                                                        Components
                                                    </a>
                                                    <?php if ($canUseReverseClassRecord): ?>
                                                        <a class="btn btn-outline-danger"
                                                            href="teacher-reverse-class-record.php?class_record_id=<?php echo (int) $selectedClassId; ?>">
                                                            <i class="ri-magic-line me-1" aria-hidden="true"></i>
                                                            Reverse
                                                        </a>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <div class="col-xl-8">
                                <div class="card wheel-panel" id="wheelPanel">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                                            <div>
                                                <h4 class="header-title mb-0">Wheel of Names</h4>
                                                <?php if (is_array($selectedClass)): ?>
                                                    <div class="text-muted small mt-1">
                                                        <?php echo tw_h((string) ($selectedClass['subject_code'] ?? '')); ?> -
                                                        <?php echo tw_h((string) ($selectedClass['subject_name'] ?? '')); ?> |
                                                        Section <?php echo tw_h((string) ($selectedClass['section'] ?? '')); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <button class="btn btn-sm btn-outline-secondary" type="button" id="wheelFullscreenBtn">
                                                <i class="ri-fullscreen-line me-1" aria-hidden="true"></i>Full Screen
                                            </button>
                                        </div>

                                        <div class="wheel-wrap">
                                            <div class="wheel-pointer" aria-hidden="true"></div>
                                            <canvas id="wheelCanvas" class="wheel-canvas" width="520" height="520"></canvas>
                                        </div>

                                        <div class="wheel-controls d-flex flex-wrap justify-content-center gap-2 mt-3">
                                            <button class="btn btn-primary" type="button" id="wheelSpinBtn">
                                                <i class="ri-refresh-line me-1" aria-hidden="true"></i>Spin
                                            </button>
                                            <button class="btn btn-outline-danger" type="button" id="wheelRemoveBtn">
                                                <i class="ri-user-unfollow-line me-1" aria-hidden="true"></i>Remove Winner
                                            </button>
                                            <button class="btn btn-outline-secondary" type="button" id="wheelUndoBtn">
                                                <i class="ri-arrow-go-back-line me-1" aria-hidden="true"></i>Undo
                                            </button>
                                            <button class="btn btn-outline-warning" type="button" id="wheelResetBtn">
                                                <i class="ri-restart-line me-1" aria-hidden="true"></i>Reset Round
                                            </button>
                                            <button class="btn btn-outline-info" type="button" id="wheelShuffleBtn">
                                                <i class="ri-shuffle-line me-1" aria-hidden="true"></i>Shuffle
                                            </button>
                                        </div>

                                        <div class="wheel-winner text-center mt-3">
                                            <div class="text-muted small">Winner</div>
                                            <div id="wheelWinnerName" class="fs-5 fw-semibold">-</div>
                                            <div id="wheelStatusText" class="small text-muted mt-1"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-xl-4">
                                <div class="card">
                                    <div class="card-body">
                                        <h4 class="header-title mb-2">Round Summary</h4>
                                        <div class="small text-muted mb-2">No-repeat mode is enabled until everyone in the active pool is picked.</div>
                                        <div id="wheelStats" class="small"></div>
                                        <hr>
                                        <div class="mb-2">
                                            <div class="fw-semibold mb-1">Remaining</div>
                                            <div id="wheelRemainingList" class="wheel-list small"></div>
                                        </div>
                                        <div>
                                            <div class="fw-semibold mb-1">Picked</div>
                                            <div id="wheelPickedList" class="wheel-list small"></div>
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
    <script>
        window.__TEACHER_WHEEL_STUDENTS__ = <?php echo $studentsJson; ?>;
    </script>
    <script src="assets/js/app.min.js"></script>
    <script>
        (function () {
            "use strict";

            var body = document.body;
            var classId = Number(body ? body.getAttribute("data-wheel-class-id") : 0);
            var teacherId = Number(body ? body.getAttribute("data-wheel-teacher-id") : 0);
            var students = Array.isArray(window.__TEACHER_WHEEL_STUDENTS__) ? window.__TEACHER_WHEEL_STUDENTS__ : [];

            var canvas = document.getElementById("wheelCanvas");
            if (!canvas || classId <= 0 || teacherId <= 0) return;

            var ctx = canvas.getContext("2d");
            if (!ctx) return;

            var spinBtn = document.getElementById("wheelSpinBtn");
            var removeBtn = document.getElementById("wheelRemoveBtn");
            var undoBtn = document.getElementById("wheelUndoBtn");
            var resetBtn = document.getElementById("wheelResetBtn");
            var shuffleBtn = document.getElementById("wheelShuffleBtn");
            var fullScreenBtn = document.getElementById("wheelFullscreenBtn");

            var winnerNameEl = document.getElementById("wheelWinnerName");
            var statusTextEl = document.getElementById("wheelStatusText");
            var statsEl = document.getElementById("wheelStats");
            var remainingListEl = document.getElementById("wheelRemainingList");
            var pickedListEl = document.getElementById("wheelPickedList");

            var panelEl = document.getElementById("wheelPanel");

            var palette = [
                "#205295", "#3d7ea6", "#4f86c6", "#16697a", "#2a9d8f", "#e76f51",
                "#f4a261", "#ffb703", "#90be6d", "#6d597a", "#457b9d", "#1d3557"
            ];

            var studentById = {};
            var studentIds = [];
            for (var i = 0; i < students.length; i++) {
                var st = students[i] || {};
                var sid = Number(st.id || 0);
                if (!sid || studentById[sid]) continue;
                studentById[sid] = {
                    id: sid,
                    name: String(st.name || ("Student #" + sid)),
                    student_no: String(st.student_no || "")
                };
                studentIds.push(sid);
            }

            function storageKey() {
                return "teacherWheelState:v1:" + teacherId + ":" + classId;
            }

            function cloneArray(values) {
                return Array.isArray(values) ? values.slice() : [];
            }

            function sanitizeIdArray(values) {
                var arr = cloneArray(values);
                var seen = {};
                var out = [];
                for (var i = 0; i < arr.length; i++) {
                    var id = Number(arr[i] || 0);
                    if (!id || !studentById[id] || seen[id]) continue;
                    seen[id] = true;
                    out.push(id);
                }
                return out;
            }

            function defaultState() {
                return {
                    picked_ids: [],
                    removed_ids: [],
                    history_ids: [],
                    last_winner_id: 0,
                    ordered_ids: studentIds.slice()
                };
            }

            function toSet(values) {
                var set = {};
                for (var i = 0; i < values.length; i++) set[values[i]] = true;
                return set;
            }

            function readState() {
                var base = defaultState();
                try {
                    var raw = localStorage.getItem(storageKey());
                    if (!raw) return base;
                    var parsed = JSON.parse(raw);
                    if (!parsed || typeof parsed !== "object") return base;

                    base.picked_ids = sanitizeIdArray(parsed.picked_ids);
                    base.removed_ids = sanitizeIdArray(parsed.removed_ids);
                    base.history_ids = sanitizeIdArray(parsed.history_ids);
                    base.last_winner_id = Number(parsed.last_winner_id || 0);
                    base.ordered_ids = sanitizeIdArray(parsed.ordered_ids);

                    if (base.ordered_ids.length === 0) base.ordered_ids = studentIds.slice();
                    var orderedSet = toSet(base.ordered_ids);
                    for (var i = 0; i < studentIds.length; i++) {
                        var sid = studentIds[i];
                        if (!orderedSet[sid]) base.ordered_ids.push(sid);
                    }

                    var removedSet = toSet(base.removed_ids);
                    base.picked_ids = base.picked_ids.filter(function (id) { return !removedSet[id]; });
                    base.history_ids = base.history_ids.filter(function (id) { return !removedSet[id]; });
                    if (!studentById[base.last_winner_id] || removedSet[base.last_winner_id]) {
                        base.last_winner_id = base.history_ids.length > 0 ? base.history_ids[base.history_ids.length - 1] : 0;
                    }
                    return base;
                } catch (err) {
                    return base;
                }
            }

            function writeState() {
                try {
                    localStorage.setItem(storageKey(), JSON.stringify(state));
                } catch (err) {
                    // ignore localStorage issues (private mode/quota)
                }
            }

            function inOrder(ids) {
                var wanted = toSet(ids);
                var ordered = [];
                for (var i = 0; i < state.ordered_ids.length; i++) {
                    var id = state.ordered_ids[i];
                    if (wanted[id]) ordered.push(id);
                }
                return ordered;
            }

            function eligibleIds() {
                var removedSet = toSet(state.removed_ids);
                var ids = [];
                for (var i = 0; i < studentIds.length; i++) {
                    var id = studentIds[i];
                    if (!removedSet[id]) ids.push(id);
                }
                return ids;
            }

            function remainingIds() {
                var eligible = eligibleIds();
                var pickedSet = toSet(state.picked_ids);
                var ids = [];
                for (var i = 0; i < eligible.length; i++) {
                    var id = eligible[i];
                    if (!pickedSet[id]) ids.push(id);
                }
                return inOrder(ids);
            }

            function winnerObject() {
                var id = Number(state.last_winner_id || 0);
                return id > 0 && studentById[id] ? studentById[id] : null;
            }

            function escapeHtml(text) {
                return String(text || "")
                    .replace(/&/g, "&amp;")
                    .replace(/</g, "&lt;")
                    .replace(/>/g, "&gt;")
                    .replace(/\"/g, "&quot;")
                    .replace(/'/g, "&#039;");
            }

            function displayChip(student) {
                var extra = student.student_no ? (" <span class=\"text-muted\">(" + escapeHtml(student.student_no) + ")</span>") : "";
                return "<span class=\"wheel-chip\">" + escapeHtml(student.name) + extra + "</span>";
            }

            function draw(entries) {
                var rect = canvas.getBoundingClientRect();
                var dpr = window.devicePixelRatio || 1;
                var size = Math.max(240, Math.floor(Math.min(rect.width || 520, 520)));
                canvas.width = Math.floor(size * dpr);
                canvas.height = Math.floor(size * dpr);
                ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

                var center = size / 2;
                var radius = center - 8;

                ctx.clearRect(0, 0, size, size);

                if (entries.length === 0) {
                    ctx.beginPath();
                    ctx.arc(center, center, radius, 0, Math.PI * 2);
                    ctx.fillStyle = "#eef3f8";
                    ctx.fill();
                    ctx.strokeStyle = "#d5dee8";
                    ctx.lineWidth = 2;
                    ctx.stroke();

                    ctx.fillStyle = "#5d6b79";
                    ctx.font = "600 16px sans-serif";
                    ctx.textAlign = "center";
                    ctx.fillText("No students to spin", center, center);
                    return;
                }

                var seg = (Math.PI * 2) / entries.length;
                for (var i = 0; i < entries.length; i++) {
                    var start = rotation + i * seg;
                    var end = start + seg;
                    var color = palette[i % palette.length];
                    var isWinner = Number(entries[i].id || 0) === Number(state.last_winner_id || 0);
                    if (isWinner) color = "#ff7043";

                    ctx.beginPath();
                    ctx.moveTo(center, center);
                    ctx.arc(center, center, radius, start, end);
                    ctx.closePath();
                    ctx.fillStyle = color;
                    ctx.fill();
                    ctx.strokeStyle = "#ffffff";
                    ctx.lineWidth = 1.5;
                    ctx.stroke();

                    var label = String(entries[i].name || "");
                    if (label.length > 24) label = label.slice(0, 22) + "...";
                    var mid = start + seg / 2;
                    var tx = center + Math.cos(mid) * (radius * 0.68);
                    var ty = center + Math.sin(mid) * (radius * 0.68);
                    ctx.save();
                    ctx.translate(tx, ty);
                    ctx.rotate(mid);
                    ctx.fillStyle = "#ffffff";
                    ctx.font = "600 13px sans-serif";
                    ctx.textAlign = "center";
                    ctx.textBaseline = "middle";
                    ctx.fillText(label, 0, 0);
                    ctx.restore();
                }

                ctx.beginPath();
                ctx.arc(center, center, Math.max(22, radius * 0.1), 0, Math.PI * 2);
                ctx.fillStyle = "#fff";
                ctx.fill();
                ctx.strokeStyle = "#d6dde7";
                ctx.lineWidth = 2;
                ctx.stroke();
            }

            function shuffled(values) {
                var arr = values.slice();
                for (var i = arr.length - 1; i > 0; i--) {
                    var j = Math.floor(Math.random() * (i + 1));
                    var tmp = arr[i];
                    arr[i] = arr[j];
                    arr[j] = tmp;
                }
                return arr;
            }

            function resetRound(clearHistory) {
                state.picked_ids = [];
                if (clearHistory) state.history_ids = [];
                state.last_winner_id = 0;
                writeState();
            }

            function roundEntries() {
                var ids = remainingIds();
                var entries = [];
                for (var i = 0; i < ids.length; i++) {
                    var st = studentById[ids[i]];
                    if (st) entries.push(st);
                }
                return entries;
            }

            function refresh() {
                var eligible = eligibleIds();
                var remaining = remainingIds();

                if (eligible.length > 0 && remaining.length === 0 && !spinning) {
                    statusTextEl.textContent = "Round complete. Next spin starts a new round.";
                } else {
                    statusTextEl.textContent = "";
                }

                var winner = winnerObject();
                winnerNameEl.textContent = winner ? winner.name : "-";

                var statsHtml = "";
                statsHtml += "<div>Active students: <strong>" + eligible.length + "</strong></div>";
                statsHtml += "<div>Remaining this round: <strong>" + remaining.length + "</strong></div>";
                statsHtml += "<div>Picked this round: <strong>" + state.picked_ids.length + "</strong></div>";
                statsHtml += "<div>Removed: <strong>" + state.removed_ids.length + "</strong></div>";
                statsEl.innerHTML = statsHtml;

                if (remaining.length === 0) {
                    remainingListEl.innerHTML = "<span class=\"text-muted\">None.</span>";
                } else {
                    var remHtml = [];
                    for (var i = 0; i < remaining.length; i++) {
                        remHtml.push(displayChip(studentById[remaining[i]]));
                    }
                    remainingListEl.innerHTML = remHtml.join("");
                }

                if (state.history_ids.length === 0) {
                    pickedListEl.innerHTML = "<span class=\"text-muted\">None.</span>";
                } else {
                    var pickedHtml = [];
                    for (var j = state.history_ids.length - 1; j >= 0; j--) {
                        var pickedId = state.history_ids[j];
                        if (!studentById[pickedId]) continue;
                        pickedHtml.push(displayChip(studentById[pickedId]));
                    }
                    pickedListEl.innerHTML = pickedHtml.join("");
                }

                removeBtn.disabled = Number(state.last_winner_id || 0) <= 0;
                undoBtn.disabled = state.history_ids.length === 0;
                resetBtn.disabled = state.picked_ids.length === 0 && state.history_ids.length === 0;
                spinBtn.disabled = spinning || eligible.length === 0;
                shuffleBtn.disabled = spinning || studentIds.length < 2;

                draw(roundEntries());
            }

            function addPicked(id) {
                var sid = Number(id || 0);
                if (!sid || !studentById[sid]) return;
                if (state.picked_ids.indexOf(sid) === -1) state.picked_ids.push(sid);
                state.history_ids.push(sid);
                state.last_winner_id = sid;
                writeState();
            }

            function spin() {
                if (spinning) return;
                var eligible = eligibleIds();
                if (eligible.length === 0) return;

                var entries = roundEntries();
                if (entries.length === 0) {
                    resetRound(true);
                    entries = roundEntries();
                }
                if (entries.length === 0) {
                    refresh();
                    return;
                }

                spinning = true;
                refresh();

                var winnerIndex = Math.floor(Math.random() * entries.length);
                var winner = entries[winnerIndex];
                var seg = (Math.PI * 2) / entries.length;
                var pointerAngle = -Math.PI / 2;
                var winnerCenter = winnerIndex * seg + seg / 2;
                var desired = pointerAngle - winnerCenter;
                var turns = 5 + Math.floor(Math.random() * 3);
                var startRotation = rotation;
                var targetRotation = desired + turns * Math.PI * 2;

                while (targetRotation <= startRotation) targetRotation += Math.PI * 2;

                var duration = 3200;
                var start = null;

                function easeOutCubic(t) {
                    return 1 - Math.pow(1 - t, 3);
                }

                function step(ts) {
                    if (start === null) start = ts;
                    var elapsed = ts - start;
                    var pct = elapsed / duration;
                    if (pct > 1) pct = 1;
                    var eased = easeOutCubic(pct);
                    rotation = startRotation + (targetRotation - startRotation) * eased;
                    draw(entries);
                    if (pct < 1) {
                        window.requestAnimationFrame(step);
                        return;
                    }
                    rotation = targetRotation % (Math.PI * 2);
                    addPicked(winner.id);
                    spinning = false;
                    refresh();
                }

                window.requestAnimationFrame(step);
            }

            function removeWinner() {
                var id = Number(state.last_winner_id || 0);
                if (!id || !studentById[id]) return;
                if (state.removed_ids.indexOf(id) === -1) state.removed_ids.push(id);
                state.picked_ids = state.picked_ids.filter(function (v) { return Number(v) !== id; });
                state.history_ids = state.history_ids.filter(function (v) { return Number(v) !== id; });
                state.last_winner_id = state.history_ids.length > 0 ? state.history_ids[state.history_ids.length - 1] : 0;
                writeState();
                refresh();
            }

            function undoLast() {
                if (state.history_ids.length === 0) return;
                var id = Number(state.history_ids.pop() || 0);
                if (id > 0) {
                    state.picked_ids = state.picked_ids.filter(function (v) { return Number(v) !== id; });
                }
                state.last_winner_id = state.history_ids.length > 0 ? state.history_ids[state.history_ids.length - 1] : 0;
                writeState();
                refresh();
            }

            function shuffleOrder() {
                state.ordered_ids = shuffled(studentIds);
                writeState();
                refresh();
            }

            function toggleFullscreen() {
                if (!panelEl) return;
                if (document.fullscreenElement) {
                    document.exitFullscreen().catch(function () {});
                    return;
                }
                panelEl.requestFullscreen().catch(function () {});
            }

            var state = readState();
            var rotation = 0;
            var spinning = false;

            if (!Array.isArray(state.ordered_ids) || state.ordered_ids.length === 0) {
                state.ordered_ids = studentIds.slice();
                writeState();
            }

            spinBtn.addEventListener("click", spin);
            removeBtn.addEventListener("click", removeWinner);
            undoBtn.addEventListener("click", undoLast);
            resetBtn.addEventListener("click", function () {
                resetRound(true);
                refresh();
            });
            shuffleBtn.addEventListener("click", shuffleOrder);
            if (fullScreenBtn) fullScreenBtn.addEventListener("click", toggleFullscreen);

            window.addEventListener("resize", function () {
                refresh();
            });
            document.addEventListener("fullscreenchange", function () {
                refresh();
            });

            refresh();
        })();
    </script>
</body>
</html>
