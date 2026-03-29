<?php
/**
 * Verify enrollment counts for Embedded Systems (IT 316 + IT 316L) in BSIT - 3A/3B.
 *
 * Run:
 *   php tools/verify_embedded_enrollment.php
 */

require __DIR__ . '/../config/db.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

function out($s) { fwrite(STDOUT, $s . PHP_EOL); }

$academicYear = '2025 - 2026';
$semester = '2nd Semester';
$sections = ['BSIT - 3A', 'BSIT - 3B'];
$subjectIds = [55, 56]; // IT 316 + IT 316L in current DB

out("Verify Embedded enrollments: AY {$academicYear} | {$semester}");

out('');
out('class_enrollments counts (active class_records):');
$stmt = $conn->prepare(
    "SELECT cr.subject_id, cr.section, COUNT(1) AS c
     FROM class_enrollments ce
     JOIN class_records cr ON cr.id = ce.class_record_id
     WHERE cr.status = 'active'
       AND cr.academic_year = ?
       AND cr.semester = ?
       AND cr.subject_id IN (55,56)
       AND cr.section IN (?, ?)
     GROUP BY cr.subject_id, cr.section
     ORDER BY cr.subject_id, cr.section"
);
$stmt->bind_param('ssss', $academicYear, $semester, $sections[0], $sections[1]);
$stmt->execute();
$res = $stmt->get_result();
while ($res && ($row = $res->fetch_assoc())) {
    out('  subj#' . (int) $row['subject_id'] . ' sec=' . (string) $row['section'] . ' count=' . (int) $row['c']);
}
$stmt->close();

out('');
out('enrollments counts:');
$stmt = $conn->prepare(
    "SELECT subject_id, section, status, COUNT(1) AS c
     FROM enrollments
     WHERE academic_year = ?
       AND semester = ?
       AND subject_id IN (55,56)
       AND section IN (?, ?)
     GROUP BY subject_id, section, status
     ORDER BY subject_id, section, status"
);
$stmt->bind_param('ssss', $academicYear, $semester, $sections[0], $sections[1]);
$stmt->execute();
$res = $stmt->get_result();
while ($res && ($row = $res->fetch_assoc())) {
    out('  subj#' . (int) $row['subject_id'] . ' sec=' . (string) $row['section'] . ' status=' . (string) $row['status'] . ' count=' . (int) $row['c']);
}
$stmt->close();

