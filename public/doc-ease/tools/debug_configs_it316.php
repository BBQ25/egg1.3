<?php
require __DIR__ . '/../config/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

function out($s){ fwrite(STDOUT, $s.PHP_EOL); }

$r = $conn->query("SELECT id, subject_id, course, year, section, academic_year, semester, term FROM section_grading_configs WHERE subject_id IN (SELECT id FROM subjects WHERE subject_code LIKE 'IT 316%' ) ORDER BY id DESC");
while($r && ($row=$r->fetch_assoc())){
  out('CFG#'.$row['id'].' subj#'.$row['subject_id'].' course='.$row['course'].' year='.$row['year'].' sec='.$row['section'].' ay='.$row['academic_year'].' sem='.$row['semester'].' term='.$row['term']);
}

