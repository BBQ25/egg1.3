<?php
/**
 * Quick DB browser for `students` table.
 *
 * Run:
 *   php tools/browse_students.php
 */

require __DIR__ . '/../config/db.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

function out($s) { fwrite(STDOUT, $s . PHP_EOL); }

out('Students table overview');

$r = $conn->query("SELECT COUNT(1) AS c FROM students");
$row = $r ? $r->fetch_assoc() : null;
out('Total students: ' . (int) ($row['c'] ?? 0));

out('');
out('Top Years (count):');
$r = $conn->query("SELECT Year, COUNT(1) AS c FROM students GROUP BY Year ORDER BY c DESC, Year ASC LIMIT 20");
while ($r && ($row = $r->fetch_assoc())) {
    out('  ' . (string) ($row['Year'] ?? '') . ' : ' . (int) ($row['c'] ?? 0));
}

out('');
out('Top Sections (count):');
$r = $conn->query("SELECT Section, COUNT(1) AS c FROM students WHERE Section IS NOT NULL AND Section <> '' GROUP BY Section ORDER BY c DESC, Section ASC LIMIT 40");
while ($r && ($row = $r->fetch_assoc())) {
    out('  ' . (string) ($row['Section'] ?? '') . ' : ' . (int) ($row['c'] ?? 0));
}

out('');
out('Top Courses (count):');
$r = $conn->query("SELECT Course, COUNT(1) AS c FROM students GROUP BY Course ORDER BY c DESC, Course ASC LIMIT 20");
while ($r && ($row = $r->fetch_assoc())) {
    out('  ' . (string) ($row['Course'] ?? '') . ' : ' . (int) ($row['c'] ?? 0));
}

out('');
out("Sample students where Section LIKE '%3%':");
$stmt = $conn->prepare("SELECT StudentNo, Surname, FirstName, Year, Section, Course FROM students WHERE Section LIKE ? ORDER BY Section, Surname, FirstName LIMIT 25");
$like = '%3%';
$stmt->bind_param('s', $like);
$stmt->execute();
$res = $stmt->get_result();
while ($res && ($row = $res->fetch_assoc())) {
    out('  ' . (string) $row['StudentNo'] . ' | ' . (string) $row['Surname'] . ', ' . (string) $row['FirstName'] . ' | ' . (string) $row['Year'] . ' | ' . (string) $row['Section'] . ' | ' . (string) $row['Course']);
}
$stmt->close();
