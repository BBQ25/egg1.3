<?php include '../layouts/session.php'; ?>
<?php require_active_role('teacher'); ?>
<?php include '../layouts/main.php'; ?>

<?php
require_once __DIR__ . '/../includes/grading.php';
ensure_grading_tables($conn);

$flash = isset($_SESSION['flash_message']) ? (string) $_SESSION['flash_message'] : '';
$flashType = isset($_SESSION['flash_type']) ? (string) $_SESSION['flash_type'] : 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

$teacherId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;

$assessmentId = isset($_GET['assessment_id']) ? (int) $_GET['assessment_id'] : 0;
if ($assessmentId <= 0) {
    $_SESSION['flash_message'] = 'Invalid assessment.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: teacher-dashboard.php');
    exit;
}

// Load context + authorize by assignment.
$ctx = null;
$stmt = $conn->prepare(
    "SELECT ga.id AS assessment_id, ga.name AS assessment_name, ga.max_score, ga.assessment_date, ga.module_type, ga.require_proof_upload,
            gc.id AS grading_component_id, gc.component_name, gc.component_code, gc.weight AS component_weight,
            c.category_name,
            sgc.term, sgc.section, sgc.academic_year, sgc.semester,
            cr.id AS class_record_id,
            s.subject_code, s.subject_name
     FROM grading_assessments ga
     JOIN grading_components gc ON gc.id = ga.grading_component_id
     JOIN section_grading_configs sgc ON sgc.id = gc.section_config_id
     JOIN class_records cr
        ON cr.subject_id = sgc.subject_id
       AND cr.section = sgc.section
       AND cr.academic_year = sgc.academic_year
       AND cr.semester = sgc.semester
       AND cr.status = 'active'
     JOIN teacher_assignments ta ON ta.class_record_id = cr.id AND ta.teacher_id = ? AND ta.status = 'active'
     JOIN subjects s ON s.id = sgc.subject_id
     LEFT JOIN grading_categories c ON c.id = gc.category_id
     WHERE ga.id = ?
     LIMIT 1"
);
if ($stmt) {
    $stmt->bind_param('ii', $teacherId, $assessmentId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows === 1) $ctx = $res->fetch_assoc();
    $stmt->close();
}
if (!$ctx) {
    deny_access(403, 'Forbidden: not assigned to this assessment.');
}

$classRecordId = (int) ($ctx['class_record_id'] ?? 0);
$componentId = (int) ($ctx['grading_component_id'] ?? 0);
$maxScore = (float) ($ctx['max_score'] ?? 0);
if ($maxScore < 0) $maxScore = 0;
$moduleType = grading_normalize_module_type((string) ($ctx['module_type'] ?? 'assessment'));
$moduleLabel = grading_module_label($moduleType);
$requireProofUpload = !empty($ctx['require_proof_upload']);
if ($moduleType === 'assignment') {
    header('Location: teacher-assignment-submissions.php?assessment_id=' . $assessmentId);
    exit;
}

// Load enrolled students.
$students = [];
$en = $conn->prepare(
    "SELECT ce.student_id,
            st.StudentNo AS student_no,
            st.surname, st.firstname, st.middlename
     FROM class_enrollments ce
     JOIN students st ON st.id = ce.student_id
     WHERE ce.class_record_id = ?
       AND ce.status = 'enrolled'
     ORDER BY st.surname ASC, st.firstname ASC, st.middlename ASC"
);
if ($en) {
    $en->bind_param('i', $classRecordId);
    $en->execute();
    $res = $en->get_result();
    while ($res && ($r = $res->fetch_assoc())) $students[] = $r;
    $en->close();
}

// Load existing scores.
$scores = [];
$sc = $conn->prepare("SELECT student_id, score FROM grading_assessment_scores WHERE assessment_id = ?");
if ($sc) {
    $sc->bind_param('i', $assessmentId);
    $sc->execute();
    $res = $sc->get_result();
    while ($res && ($r = $res->fetch_assoc())) {
        $sid = (int) ($r['student_id'] ?? 0);
        $scores[$sid] = $r['score'];
    }
    $sc->close();
}

$proofsByStudent = [];
$proofQ = $conn->prepare(
    "SELECT ss.student_id,
            sf.id,
            sf.original_name,
            sf.file_path,
            sf.file_size,
            sf.created_at
     FROM grading_assignment_submissions ss
     JOIN grading_assignment_submission_files sf ON sf.submission_id = ss.id
     WHERE ss.assessment_id = ?
       AND sf.uploaded_by_role = 'student'
     ORDER BY ss.student_id ASC, sf.created_at DESC, sf.id DESC"
);
if ($proofQ) {
    $proofQ->bind_param('i', $assessmentId);
    $proofQ->execute();
    $res = $proofQ->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
        $sid = (int) ($row['student_id'] ?? 0);
        if ($sid <= 0) continue;
        if (!isset($proofsByStudent[$sid]) || !is_array($proofsByStudent[$sid])) {
            $proofsByStudent[$sid] = [];
        }
        $proofsByStudent[$sid][] = $row;
    }
    $proofQ->close();
}
$proofCountByStudent = [];
foreach ($proofsByStudent as $sidPf => $rowsPf) {
    $sidPf = (int) $sidPf;
    if ($sidPf <= 0 || !is_array($rowsPf)) continue;
    $proofCountByStudent[$sidPf] = count($rowsPf);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    if (!csrf_validate($csrf)) {
        $_SESSION['flash_message'] = 'Security check failed (CSRF). Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: teacher-assessment-scores.php?assessment_id=' . $assessmentId);
        exit;
    }

    $posted = isset($_POST['score']) && is_array($_POST['score']) ? $_POST['score'] : [];

    $upScore = $conn->prepare(
        "INSERT INTO grading_assessment_scores (assessment_id, student_id, score, recorded_by)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE score = VALUES(score), recorded_by = VALUES(recorded_by), updated_at = CURRENT_TIMESTAMP"
    );
    $upNull = $conn->prepare(
        "INSERT INTO grading_assessment_scores (assessment_id, student_id, score, recorded_by)
         VALUES (?, ?, NULL, ?)
         ON DUPLICATE KEY UPDATE score = NULL, recorded_by = VALUES(recorded_by), updated_at = CURRENT_TIMESTAMP"
    );
    if (!$upScore || !$upNull) {
        $_SESSION['flash_message'] = 'Save failed.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: teacher-assessment-scores.php?assessment_id=' . $assessmentId);
        exit;
    }

    $invalidCount = 0;
    $missingProofCount = 0;
    foreach ($students as $st) {
        $sid = (int) ($st['student_id'] ?? 0);
        if ($sid <= 0) continue;

        $raw = isset($posted[(string) $sid]) ? trim((string) $posted[(string) $sid]) : '';

        // Blank means "not recorded yet" => store NULL.
        if ($raw === '') {
            $upNull->bind_param('iii', $assessmentId, $sid, $teacherId);
            $upNull->execute();
            continue;
        }

        $rawNorm = str_replace(',', '.', $raw);
        if (strpos($rawNorm, '.') === 0) $rawNorm = '0' . $rawNorm;
        if (substr($rawNorm, -1) === '.') $rawNorm = substr($rawNorm, 0, -1);

        // Reject exponent/letters/etc. (strict).
        if (!preg_match('/^\\d+(?:\\.\\d+)?$/', $rawNorm)) {
            $invalidCount++;
            continue;
        }

        $v = (float) $rawNorm;
        if ($v < 0 || ($maxScore >= 0 && $v > $maxScore)) {
            $invalidCount++;
            continue;
        }
        if ($requireProofUpload && (int) ($proofCountByStudent[$sid] ?? 0) <= 0) {
            $missingProofCount++;
            continue;
        }

        $v = round($v, 2);
        $upScore->bind_param('iidi', $assessmentId, $sid, $v, $teacherId);
        $upScore->execute();
    }

    $upScore->close();
    $upNull->close();

    $parts = [];
    if ($invalidCount > 0) $parts[] = 'Ignored ' . $invalidCount . ' invalid value(s)';
    if ($missingProofCount > 0) $parts[] = 'Skipped ' . $missingProofCount . ' score(s) with missing required proof uploads';
    $_SESSION['flash_message'] = count($parts) > 0 ? ('Scores saved. ' . implode('. ', $parts) . '.') : 'Scores saved.';
    $_SESSION['flash_type'] = count($parts) > 0 ? 'warning' : 'success';
    header('Location: teacher-assessment-scores.php?assessment_id=' . $assessmentId);
    exit;
}

$term = (string) ($ctx['term'] ?? 'midterm');
$termLabel = $term === 'final' ? 'Final Term' : 'Midterm';
?>

<head>
    <title>Record Scores | E-Record</title>
    <?php include '../layouts/title-meta.php'; ?>
    <?php include '../layouts/head-css.php'; ?>
    <style>
        .score-input {
            max-width: 110px;
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
                                        <li class="breadcrumb-item active">Record Scores</li>
                                    </ol>
                                </div>
                                <h4 class="page-title">Record Scores</h4>
                            </div>
                        </div>
                    </div>

                    <?php if ($flash): ?>
                        <div class="alert alert-<?php echo htmlspecialchars($flashType); ?>"><?php echo htmlspecialchars($flash); ?></div>
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex align-items-start justify-content-between flex-wrap gap-2">
                                        <div>
                                            <div class="fw-semibold">
                                                <?php echo htmlspecialchars((string) ($ctx['subject_name'] ?? '')); ?>
                                                <span class="text-muted">(<?php echo htmlspecialchars((string) ($ctx['subject_code'] ?? '')); ?>)</span>
                                            </div>
                                            <div class="text-muted small">
                                                <?php echo htmlspecialchars((string) ($ctx['section'] ?? '')); ?> |
                                                <?php echo htmlspecialchars((string) ($ctx['academic_year'] ?? '')); ?>, <?php echo htmlspecialchars((string) ($ctx['semester'] ?? '')); ?> |
                                                <?php echo htmlspecialchars($termLabel); ?>
                                            </div>
                                            <div class="mt-2">
                                                <span class="badge bg-dark">
                                                    <?php echo htmlspecialchars((string) ($ctx['component_name'] ?? '')); ?>
                                                </span>
                                                <span class="badge bg-light text-dark border">
                                                    <?php echo htmlspecialchars((string) ($ctx['category_name'] ?? '')); ?>
                                                </span>
                                                <?php if ($moduleType !== 'assessment'): ?>
                                                    <span class="badge bg-info-subtle text-info">
                                                        <?php echo htmlspecialchars((string) $moduleLabel); ?>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($requireProofUpload): ?>
                                                    <span class="badge bg-warning-subtle text-warning">Proof Required</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="text-end">
                                            <div class="fw-semibold"><?php echo htmlspecialchars((string) ($ctx['assessment_name'] ?? '')); ?></div>
                                            <div class="text-muted small">
                                                Max: <?php echo htmlspecialchars((string) ($ctx['max_score'] ?? '0')); ?>
                                                <?php if (!empty($ctx['assessment_date'])): ?>
                                                    | Date: <?php echo htmlspecialchars((string) ($ctx['assessment_date'] ?? '')); ?>
                                                <?php endif; ?>
                                            </div>
                                            <div class="mt-2">
                                                <a class="btn btn-sm btn-outline-dark"
                                                    href="teacher-assessment-builder.php?assessment_id=<?php echo (int) $assessmentId; ?>">
                                                    <i class="ri-quill-pen-line me-1" aria-hidden="true"></i>
                                                    Builder
                                                </a>
                                                <a class="btn btn-sm btn-outline-secondary"
                                                    href="teacher-component-assessments.php?grading_component_id=<?php echo (int) $componentId; ?>">
                                                    <i class="ri-arrow-left-line me-1" aria-hidden="true"></i>
                                                    Back
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                    <?php if ($requireProofUpload): ?>
                                        <div class="alert alert-warning py-2 mt-3 mb-0">
                                            Score recording requires at least one student proof upload per student.
                                        </div>
                                    <?php endif; ?>

                                    <form method="post" class="mt-3">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">

                                        <div class="table-responsive">
                                            <table class="table table-sm table-striped table-hover align-middle mb-0">
                                                <thead>
                                                    <tr>
                                                        <th>Student</th>
                                                        <th style="width: 140px;">Score</th>
                                                        <th>Proof Uploads</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php if (count($students) === 0): ?>
                                                        <tr>
                                                            <td colspan="3" class="text-center text-muted">No enrolled students found for this class record.</td>
                                                        </tr>
                                                    <?php endif; ?>
                                                    <?php foreach ($students as $st): ?>
                                                        <?php $sid = (int) ($st['student_id'] ?? 0); ?>
                                                        <?php
                                                        $name = trim(
                                                            (string) ($st['surname'] ?? '') . ', ' .
                                                            (string) ($st['firstname'] ?? '') . ' ' .
                                                            (string) ($st['middlename'] ?? '')
                                                        );
                                                        ?>
                                                        <tr>
                                                            <td>
                                                                <div class="fw-semibold"><?php echo htmlspecialchars($name); ?></div>
                                                                <div class="text-muted small"><?php echo htmlspecialchars((string) ($st['student_no'] ?? '')); ?></div>
                                                            </td>
                                                            <td>
                                                                <input
                                                                    class="form-control form-control-sm score-input er-score-input"
                                                                    name="score[<?php echo (int) $sid; ?>]"
                                                                    data-student-id="<?php echo (int) $sid; ?>"
                                                                    type="number"
                                                                    min="0"
                                                                    <?php if ($maxScore > 0): ?>
                                                                        max="<?php echo htmlspecialchars((string) $maxScore); ?>"
                                                                    <?php endif; ?>
                                                                    step="0.01"
                                                                    inputmode="decimal"
                                                                    pattern="[0-9]*[.,]?[0-9]*"
                                                                    value="<?php echo htmlspecialchars((string) ($scores[$sid] ?? '')); ?>"
                                                                    placeholder="0 - <?php echo htmlspecialchars((string) $maxScore); ?>"
                                                                >
                                                                <div class="invalid-feedback"></div>
                                                                <div class="form-text small text-muted er-score-state"></div>
                                                            </td>
                                                            <td>
                                                                <?php $proofList = isset($proofsByStudent[$sid]) && is_array($proofsByStudent[$sid]) ? $proofsByStudent[$sid] : []; ?>
                                                                <?php if (count($proofList) === 0): ?>
                                                                    <?php if ($requireProofUpload): ?>
                                                                        <span class="text-warning small">No uploads (required)</span>
                                                                    <?php else: ?>
                                                                        <span class="text-muted small">No uploads</span>
                                                                    <?php endif; ?>
                                                                <?php else: ?>
                                                                    <?php $visibleProofs = array_slice($proofList, 0, 3); ?>
                                                                    <div class="d-flex flex-column gap-1">
                                                                        <?php foreach ($visibleProofs as $pf): ?>
                                                                            <?php
                                                                            $href = ltrim((string) ($pf['file_path'] ?? ''), '/');
                                                                            $namePf = trim((string) ($pf['original_name'] ?? 'proof'));
                                                                            if ($namePf === '') $namePf = 'proof';
                                                                            $namePfShort = (strlen($namePf) > 40) ? (substr($namePf, 0, 37) . '...') : $namePf;
                                                                            $tsPf = strtotime((string) ($pf['created_at'] ?? ''));
                                                                            $whenPf = $tsPf ? date('Y-m-d H:i', $tsPf) : (string) ($pf['created_at'] ?? '');
                                                                            ?>
                                                                            <a class="small" href="<?php echo htmlspecialchars($href); ?>" target="_blank" rel="noopener">
                                                                                <?php echo htmlspecialchars($namePfShort); ?>
                                                                            </a>
                                                                            <span class="text-muted small"><?php echo htmlspecialchars($whenPf); ?> | <?php echo htmlspecialchars(number_format(((int) ($pf['file_size'] ?? 0)) / 1024, 1)); ?> KB</span>
                                                                        <?php endforeach; ?>
                                                                        <?php if (count($proofList) > count($visibleProofs)): ?>
                                                                            <span class="text-muted small">+<?php echo (int) (count($proofList) - count($visibleProofs)); ?> more</span>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>

                                        <div class="mt-3">
                                            <button class="btn btn-primary" type="submit">
                                                <i class="ri-save-3-line me-1" aria-hidden="true"></i>
                                                Save Scores
                                            </button>
                                        </div>
                                        <div class="text-muted small mt-2">
                                            Auto-save is enabled: scores are saved as you type. The Save button is optional.
                                            <br>
                                            Leaving a score blank means "not recorded yet". Component totals are computed using total <code>max_score</code>.
                                        </div>
                                    </form>
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
    <script>
        (function () {
            // Keep score inputs strictly numeric (no letters), while still allowing blanks for NULL.
            var inputs = document.querySelectorAll('.er-score-input');
            if (!inputs || inputs.length === 0) return;

            var saveUrl = 'includes/save_assessment_score.php';
            var csrfToken = <?php echo json_encode((string) csrf_token()); ?>;
            var assessmentId = <?php echo (int) $assessmentId; ?>;
            var maxScore = <?php echo json_encode((float) $maxScore); ?>;

            function normalizeInput(el, onBlur) {
                var v = (el.value || '').toString();
                if (v === '') return { ok: true, value: '' };

                // Normalize commas to dot for decimals.
                v = v.replace(/,/g, '.').trim();

                // Reject exponent/signs/letters/etc (do not silently transform).
                if (/[eE+-]/.test(v) || /[^0-9.]/.test(v)) {
                    return { ok: false, error: 'Numbers/decimals only.' };
                }

                // Only one decimal point allowed.
                if ((v.match(/\./g) || []).length > 1) {
                    return { ok: false, error: 'Only one decimal point is allowed.' };
                }

                // Accept leading dot by normalizing to 0.x
                if (/^\.\d*$/.test(v)) {
                    v = '0' + v;
                }

                // On blur, normalize "10." to "10".
                if (onBlur && /^\d+\.$/.test(v)) {
                    v = v.slice(0, -1);
                }

                el.value = v;
                return { ok: true, value: v };
            }

            function setState(el, state, msg) {
                var cell = el.closest('td');
                if (!cell) return;
                var stateEl = cell.querySelector('.er-score-state');
                if (stateEl) stateEl.textContent = msg || '';
                el.dataset.erState = state || '';
            }

            function setInvalid(el, msg) {
                el.classList.add('is-invalid');
                el.classList.remove('is-valid');
                var cell = el.closest('td');
                if (!cell) return;
                var fb = cell.querySelector('.invalid-feedback');
                if (fb) fb.textContent = msg || 'Invalid value.';
                setState(el, 'error', msg || 'Invalid value.');
            }

            function clearInvalid(el) {
                el.classList.remove('is-invalid');
                var cell = el.closest('td');
                if (!cell) return;
                var fb = cell.querySelector('.invalid-feedback');
                if (fb) fb.textContent = '';
            }

            function setValid(el, msg) {
                el.classList.remove('is-invalid');
                el.classList.add('is-valid');
                setState(el, 'saved', msg || 'Saved');
            }

            function parseValue(el) {
                var raw = (el.value || '').toString().trim();
                if (raw === '') return { ok: true, value: null };

                raw = raw.replace(/,/g, '.');
                // Allow "in-progress" decimals like "10." without error/saving yet.
                if (/^\\d+\\.$/.test(raw)) return { ok: false, pending: true };
                if (!/^\d+(\.\d+)?$/.test(raw)) return { ok: false, error: 'Numbers/decimals only.' };

                var num = parseFloat(raw);
                if (!isFinite(num)) return { ok: false, error: 'Invalid number.' };
                if (num < 0) return { ok: false, error: 'Score cannot be negative.' };
                if (maxScore >= 0 && num > maxScore) return { ok: false, error: 'Score cannot exceed max score (' + maxScore + ').' };
                return { ok: true, value: num };
            }

            function save(el) {
                var norm = normalizeInput(el, false);
                if (!norm.ok) {
                    setInvalid(el, norm.error);
                    return;
                }
                clearInvalid(el);

                var studentId = parseInt(el.getAttribute('data-student-id') || '0', 10);
                if (!studentId) return;

                var parsed = parseValue(el);
                if (!parsed.ok && parsed.pending) {
                    setState(el, 'typing', '');
                    el.classList.remove('is-valid');
                    return;
                }
                if (!parsed.ok) {
                    setInvalid(el, parsed.error);
                    return;
                }

                // Avoid spamming server if unchanged.
                var currentKey = (parsed.value === null) ? '' : String(parsed.value);
                if ((el.dataset.erLastSaved || '') === currentKey) {
                    setState(el, 'idle', '');
                    el.classList.remove('is-valid');
                    return;
                }

                setState(el, 'saving', 'Saving...');

                var body = new URLSearchParams();
                body.set('csrf_token', csrfToken);
                body.set('assessment_id', String(assessmentId));
                body.set('student_id', String(studentId));
                body.set('score', parsed.value === null ? '' : String(parsed.value));

                fetch(saveUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: body.toString(),
                    credentials: 'same-origin'
                })
                    .then(function (r) { return r.json().catch(function () { return null; }).then(function (j) { return { ok: r.ok, j: j }; }); })
                    .then(function (resp) {
                        if (!resp || !resp.j) throw new Error('Bad response');
                        if (resp.j.status !== 'ok') {
                            var m = resp.j.message || 'Save failed.';
                            setInvalid(el, m);
                            return;
                        }

                        var saved = resp.j.saved_score;
                        if (saved === null || saved === undefined) {
                            el.value = '';
                            el.dataset.erLastSaved = '';
                            el.classList.remove('is-valid');
                            setState(el, 'saved', 'Cleared');
                            return;
                        }

                        // Server rounds to 2 decimals.
                        el.value = String(saved);
                        el.dataset.erLastSaved = String(saved);
                        setValid(el, 'Saved');
                    })
                    .catch(function () {
                        setInvalid(el, 'Network error. Not saved.');
                    });
            }

            inputs.forEach(function (el) {
                // initialize last-saved from initial value
                var initial = (el.value || '').toString().trim();
                el.dataset.erLastSaved = initial;
                el.dataset.erLastOk = initial;

                var t = null;
                el.addEventListener('focus', function () {
                    el.dataset.erLastOk = (el.value || '').toString().trim();
                });

                el.addEventListener('keydown', function (ev) {
                    var k = ev.key;
                    if (k === 'e' || k === 'E' || k === '+' || k === '-') {
                        ev.preventDefault();
                        return;
                    }
                    if (k === '.' || k === ',') {
                        var v = (el.value || '').toString();
                        if (v.indexOf('.') !== -1 || v.indexOf(',') !== -1) {
                            ev.preventDefault();
                        }
                    }
                });

                el.addEventListener('paste', function (ev) {
                    var text = (ev.clipboardData && ev.clipboardData.getData) ? ev.clipboardData.getData('text') : '';
                    text = (text || '').toString().trim();
                    if (text === '') return;
                    var candidate = text.replace(/,/g, '.');
                    if (/^\.\d*$/.test(candidate)) candidate = '0' + candidate;
                    if (/[eE+-]/.test(candidate) || /[^0-9.]/.test(candidate) || (candidate.match(/\./g) || []).length > 1) {
                        ev.preventDefault();
                        setInvalid(el, 'Numbers/decimals only.');
                    }
                });

                el.addEventListener('input', function () {
                    var norm = normalizeInput(el, false);
                    if (!norm.ok) {
                        // Revert to last known good string so we never silently corrupt the value (e.g. "1e3").
                        el.value = (el.dataset.erLastOk || '');
                        setInvalid(el, norm.error);
                        if (t) window.clearTimeout(t);
                        return;
                    }

                    el.dataset.erLastOk = (el.value || '').toString().trim();
                    clearInvalid(el);

                    var parsed = parseValue(el);
                    if (!parsed.ok && parsed.pending) {
                        setState(el, 'typing', '');
                        el.classList.remove('is-valid');
                        if (t) window.clearTimeout(t);
                        return;
                    }
                    if (!parsed.ok) {
                        setInvalid(el, parsed.error);
                        if (t) window.clearTimeout(t);
                        return;
                    }

                    setState(el, 'typing', '');
                    if (t) window.clearTimeout(t);
                    t = window.setTimeout(function () { save(el); }, 450);
                });
                el.addEventListener('blur', function () {
                    if (t) window.clearTimeout(t);
                    normalizeInput(el, true);
                    save(el);
                });
            });
        })();
    </script>
</body>
</html>
