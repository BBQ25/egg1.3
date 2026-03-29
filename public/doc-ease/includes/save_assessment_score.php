<?php
// AJAX endpoint: save a single student's score for an assessment.

include '../layouts/session.php';
require_active_role('teacher');

require_once __DIR__ . '/grading.php';
ensure_grading_tables($conn);

header('Content-Type: application/json; charset=utf-8');

function json_out($status, $payload = [], $httpCode = 200) {
    http_response_code((int) $httpCode);
    $out = array_merge(['status' => $status], is_array($payload) ? $payload : []);
    echo json_encode($out);
    exit;
}

function parse_decimal_strict($raw) {
    if (!is_string($raw) && !is_numeric($raw)) return [false, null];
    $raw = trim((string) $raw);
    if ($raw === '') return [true, null]; // blank => NULL score
    $raw = str_replace(',', '.', $raw);

    // Accept leading-dot decimals like ".5" by normalizing to "0.5".
    if (preg_match('/^\\.\\d*$/', $raw)) $raw = '0' . $raw;

    // Strictly digits with optional decimal point. Reject exponent, signs, and letters.
    // Allow "10." by permitting an empty decimal tail, but still require at least one digit.
    if (!preg_match('/^\\d+(?:\\.\\d*)?$/', $raw)) return [false, null];
    if (substr($raw, -1) === '.') $raw = substr($raw, 0, -1);
    return [true, (float) $raw];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out('error', ['message' => 'Method not allowed.'], 405);
}

$csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
if (!csrf_validate($csrf)) {
    json_out('error', ['message' => 'Security check failed (CSRF).'], 403);
}

$teacherId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$assessmentId = isset($_POST['assessment_id']) ? (int) $_POST['assessment_id'] : 0;
$studentId = isset($_POST['student_id']) ? (int) $_POST['student_id'] : 0;
$rawScore = isset($_POST['score']) ? (string) $_POST['score'] : '';

if ($teacherId <= 0 || $assessmentId <= 0 || $studentId <= 0) {
    json_out('error', ['message' => 'Invalid request.'], 400);
}

// Authorize: assessment must belong to a class assigned to the teacher, and student must be enrolled.
$ctx = null;
$stmt = $conn->prepare(
    "SELECT ga.id AS assessment_id, ga.max_score, ga.require_proof_upload,
            cr.id AS class_record_id
     FROM grading_assessments ga
     JOIN grading_components gc ON gc.id = ga.grading_component_id
     JOIN section_grading_configs sgc ON sgc.id = gc.section_config_id
     JOIN class_records cr
        ON cr.subject_id = sgc.subject_id
       AND cr.section = sgc.section
       AND cr.academic_year = sgc.academic_year
       AND cr.semester = sgc.semester
       AND cr.status = 'active'
     JOIN teacher_assignments ta
        ON ta.class_record_id = cr.id
       AND ta.teacher_id = ?
       AND ta.status = 'active'
     JOIN class_enrollments ce
        ON ce.class_record_id = cr.id
       AND ce.student_id = ?
       AND ce.status = 'enrolled'
     WHERE ga.id = ?
     LIMIT 1"
);
if ($stmt) {
    $stmt->bind_param('iii', $teacherId, $studentId, $assessmentId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows === 1) $ctx = $res->fetch_assoc();
    $stmt->close();
}

if (!$ctx) {
    json_out('error', ['message' => 'Forbidden.'], 403);
}

$maxScore = (float) ($ctx['max_score'] ?? 0);
if ($maxScore < 0) $maxScore = 0;
$requireProofUpload = !empty($ctx['require_proof_upload']);

[$ok, $parsed] = parse_decimal_strict($rawScore);
if (!$ok) {
    json_out('error', ['message' => 'Invalid score. Numbers/decimals only.'], 422);
}

// NULL score is allowed (not recorded yet / cleared).
if ($parsed === null) {
    $up = $conn->prepare(
        "INSERT INTO grading_assessment_scores (assessment_id, student_id, score, recorded_by)
         VALUES (?, ?, NULL, ?)
         ON DUPLICATE KEY UPDATE score = NULL, recorded_by = VALUES(recorded_by), updated_at = CURRENT_TIMESTAMP"
    );
    if (!$up) json_out('error', ['message' => 'Save failed.'], 500);
    $up->bind_param('iii', $assessmentId, $studentId, $teacherId);
    $up->execute();
    $up->close();
    json_out('ok', ['saved_score' => null]);
}

$v = (float) $parsed;
if ($v < 0) {
    json_out('error', ['message' => 'Score cannot be negative.'], 422);
}
if ($maxScore >= 0 && $v > $maxScore) {
    json_out('error', ['message' => 'Score cannot exceed max score (' . $maxScore . ').'], 422);
}
if ($requireProofUpload) {
    $proofCount = 0;
    $pq = $conn->prepare(
        "SELECT COUNT(*) AS c
         FROM grading_assignment_submissions ss
         JOIN grading_assignment_submission_files sf ON sf.submission_id = ss.id
         WHERE ss.assessment_id = ?
           AND ss.student_id = ?
           AND sf.uploaded_by_role = 'student'"
    );
    if ($pq) {
        $pq->bind_param('ii', $assessmentId, $studentId);
        $pq->execute();
        $res = $pq->get_result();
        if ($res && $res->num_rows === 1) $proofCount = (int) ($res->fetch_assoc()['c'] ?? 0);
        $pq->close();
    }
    if ($proofCount <= 0) {
        json_out('error', ['message' => 'Proof upload is required before recording a score for this student.'], 422);
    }
}

// Persist with 2 decimal places (DECIMAL(8,2)).
$v = round($v, 2);

$up = $conn->prepare(
    "INSERT INTO grading_assessment_scores (assessment_id, student_id, score, recorded_by)
     VALUES (?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE score = VALUES(score), recorded_by = VALUES(recorded_by), updated_at = CURRENT_TIMESTAMP"
);
if (!$up) json_out('error', ['message' => 'Save failed.'], 500);

$up->bind_param('iidi', $assessmentId, $studentId, $v, $teacherId);
$up->execute();
$up->close();

json_out('ok', ['saved_score' => $v]);
