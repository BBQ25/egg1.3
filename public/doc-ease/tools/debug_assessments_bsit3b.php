<?php
/**
 * Debug: list all grading assessments for BSIT - 3B (IT316/IT316L) with config/component linkage.
 */
require __DIR__ . '/../config/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

function out($s){ fwrite(STDOUT, $s.PHP_EOL); }

$section = 'BSIT - 3B';
$ay = '2025 - 2026';
$sem = '2nd Semester';

$subjIds = [];
$st = $conn->prepare("SELECT id, subject_code FROM subjects WHERE subject_code IN ('IT 316','IT 316L') AND status='active'");
$st->execute();
$r = $st->get_result();
while($r && ($row=$r->fetch_assoc())) $subjIds[] = (int)$row['id'];
$st->close();
if(count($subjIds)===0){ out('no subjects'); exit; }
$idList = implode(',', array_map('intval',$subjIds));

$sql = "
SELECT s.subject_code,
       sgc.id AS config_id, sgc.term, sgc.academic_year, sgc.semester, sgc.section,
       gc.id AS component_id, gc.component_name, gc.component_type, gc.is_active AS comp_active,
       ga.id AS assessment_id, ga.name AS assessment_name, ga.max_score, ga.is_active AS assess_active
FROM section_grading_configs sgc
JOIN subjects s ON s.id = sgc.subject_id
JOIN grading_components gc ON gc.section_config_id = sgc.id
LEFT JOIN grading_assessments ga ON ga.grading_component_id = gc.id
WHERE sgc.section = ?
  AND sgc.subject_id IN ($idList)
ORDER BY s.subject_code, sgc.term, gc.display_order, gc.id, ga.display_order, ga.id";

$q = $conn->prepare($sql);
$q->bind_param('s', $section);
$q->execute();
$res = $q->get_result();
$rows = [];
while($res && ($row=$res->fetch_assoc())) $rows[] = $row;
$q->close();

out('rows=' . count($rows));
foreach($rows as $row){
  out(
    ($row['subject_code']??'') .
    " | CFG#" . (int)($row['config_id']??0) . " " . ($row['term']??'') .
    " | ay=" . ($row['academic_year']??'') . " sem=" . ($row['semester']??'') .
    " | comp#" . (int)($row['component_id']??0) . " " . ($row['component_name']??'') . " [" . ($row['component_type']??'') . "] active=" . (int)($row['comp_active']??0) .
    " | A#" . (int)($row['assessment_id']??0) . " " . ($row['assessment_name']??'') . " max=" . ($row['max_score']??'') . " active=" . (int)($row['assess_active']??0)
  );
}

