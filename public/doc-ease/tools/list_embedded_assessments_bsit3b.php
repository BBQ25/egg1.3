<?php
/**
 * List Embedded Systems assessments (IT 316/IT 316L) for BSIT - 3B and show score counts.
 *
 * Run:
 *   php tools/list_embedded_assessments_bsit3b.php
 */

require __DIR__ . '/../config/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

function out($s) { fwrite(STDOUT, $s . PHP_EOL); }

$academicYear = '2025 - 2026';
$semester = '2nd Semester';
$section = 'BSIT - 3B';
$codes = ['IT 316', 'IT 316L'];

$ids = [];
$st = $conn->prepare("SELECT id FROM subjects WHERE status='active' AND subject_code = ? LIMIT 1");
foreach ($codes as $c) {
    $st->bind_param('s', $c);
    $st->execute();
    $r = $st->get_result();
    if ($r && $r->num_rows === 1) $ids[] = (int) ($r->fetch_assoc()['id'] ?? 0);
}
$st->close();
$ids = array_values(array_filter(array_unique($ids)));
if (count($ids) === 0) {
    out('No subjects found.');
    exit(0);
}
$idList = implode(',', array_map('intval', $ids));

out("Assessments for {$section} | {$academicYear} | {$semester}");

$sql =
    "SELECT s.subject_code, s.subject_name,
            sgc.term,
            gc.component_name,
            ga.id AS assessment_id, ga.name AS assessment_name, ga.max_score,
            (SELECT COUNT(1) FROM grading_assessment_scores gas WHERE gas.assessment_id = ga.id AND gas.score IS NOT NULL) AS scored_count
     FROM grading_assessments ga
     JOIN grading_components gc ON gc.id = ga.grading_component_id AND gc.is_active = 1
     JOIN section_grading_configs sgc ON sgc.id = gc.section_config_id
     JOIN subjects s ON s.id = sgc.subject_id
     WHERE sgc.section = ?
       AND sgc.academic_year = ?
       AND sgc.semester = ?
       AND sgc.subject_id IN ({$idList})
       AND ga.is_active = 1
     ORDER BY s.subject_code, sgc.term, gc.display_order, ga.display_order, ga.id";

$q = $conn->prepare($sql);
$q->bind_param('sss', $section, $academicYear, $semester);
$q->execute();
$res = $q->get_result();
$rows = [];
while ($res && ($r = $res->fetch_assoc())) $rows[] = $r;
$q->close();

if (count($rows) === 0) {
    out('No assessments found.');
    exit(0);
}

foreach ($rows as $r) {
    out(
        ($r['subject_code'] ?? '') . ' | ' .
        ($r['term'] ?? '') . ' | A#' . (int) ($r['assessment_id'] ?? 0) . ' | ' .
        ($r['assessment_name'] ?? '') . ' | max=' . ($r['max_score'] ?? '0') . ' | comp=' . ($r['component_name'] ?? '') .
        ' | scored=' . (int) ($r['scored_count'] ?? 0)
    );
}

