<?php
require dirname(__DIR__) . '/config/db.php';
$tables = ['class_records','teacher_assignments','subjects','class_enrollments','section_grading_configs','grading_components','grading_assessments','grading_assessment_scores','class_record_builds','class_record_build_parameters','class_record_build_components','app_settings'];
foreach ($tables as $t) {
    echo "\n== $t ==\n";
    $r = $conn->query("SHOW COLUMNS FROM `$t`");
    if (!$r) {
        echo "(missing)\n";
        continue;
    }
    while ($row = $r->fetch_assoc()) {
        $def = $row['Default'];
        if ($def === null) $def = 'NULL';
        echo $row['Field'] . "\t" . $row['Type'] . "\t" . $row['Null'] . "\t" . $def . "\n";
    }
}

echo "\n";
foreach (['class_records','teacher_assignments','class_enrollments'] as $t) {
    echo "\n== indexes $t ==\n";
    $r = $conn->query("SHOW INDEX FROM `$t`");
    if (!$r) {
        echo "(missing)\n";
        continue;
    }
    while ($row = $r->fetch_assoc()) {
        echo $row['Key_name'] . "\t" . $row['Column_name'] . "\t" . $row['Non_unique'] . "\n";
    }
}
