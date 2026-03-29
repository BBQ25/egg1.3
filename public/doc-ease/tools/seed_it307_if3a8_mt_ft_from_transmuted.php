<?php
declare(strict_types=1);

/**
 * Seed MT/FT gradebook assessments + scores for:
 *   IT 307 - Event Driven Programming
 *   Section IF-3-A-8
 *   AY 2025 - 2026, 1st Semester
 *
 * Input grades below are transmuted term grades (5.0 .. 1.0).
 * They are converted to term marks (55 .. 100) using SLSU mapping.
 *
 * Usage:
 *   php tools/seed_it307_if3a8_mt_ft_from_transmuted.php --dry-run
 *   php tools/seed_it307_if3a8_mt_ft_from_transmuted.php
 */

require_once __DIR__ . '/../config/db.php';

function out(string $msg): void {
    echo $msg . PHP_EOL;
}

function parse_args(array $argv): array {
    $dryRun = false;
    foreach ($argv as $arg) {
        if ($arg === '--dry-run') $dryRun = true;
    }
    return ['dry_run' => $dryRun];
}

function normalize_grade_key($value): string {
    return number_format((float) $value, 1, '.', '');
}

function build_transmuted_to_mark_map(): array {
    $map = [];

    // 55..92 <-> 5.0..1.3 (step 0.1)
    $mark = 55;
    for ($gradeTenths = 50; $gradeTenths >= 13; $gradeTenths--) {
        $grade = number_format($gradeTenths / 10, 1, '.', '');
        $map[$grade] = $mark;
        $mark++;
    }

    // Compressed top ranges.
    $map['1.2'] = 95;
    $map['1.1'] = 98;
    $map['1.0'] = 100;

    return $map;
}

function planned_assessment_count(array $component): int {
    $name = strtolower(trim((string) ($component['component_name'] ?? '')));
    $code = strtolower(trim((string) ($component['component_code'] ?? '')));
    $type = strtolower(trim((string) ($component['component_type'] ?? '')));

    if ($type === 'project' || $type === 'exam') return 1;
    if (strpos($name, 'project') !== false || strpos($name, 'exam') !== false) return 1;
    if ($code === 'proj' || $code === 'texam' || $code === 'exam') return 1;
    return 3;
}

function assessment_names_for_component(array $component): array {
    $name = trim((string) ($component['component_name'] ?? 'Component'));
    if ($name === '') $name = 'Component';
    $count = planned_assessment_count($component);

    if ($count <= 1) return [$name];
    $names = [];
    for ($i = 1; $i <= $count; $i++) {
        $names[] = $name . ' ' . $i;
    }
    return $names;
}

$args = parse_args($argv);
$dryRun = (bool) ($args['dry_run'] ?? false);

$targetSection = 'IF-3-A-8';
$targetAcademicYear = '2025 - 2026';
$targetSemester = '1st Semester';
$targetSubjectCode = 'IT 307';
$targetSubjectName = 'Event Driven Programming';
$targetTerms = ['midterm', 'final'];

$transmutedByStudentNo = [
    '2310106-2' => ['mt' => 1.8, 'ft' => 1.6, 'avg' => 1.7],
    '2310069-1' => ['mt' => 1.5, 'ft' => 1.5, 'avg' => 1.5],
    '2310002-2' => ['mt' => 1.7, 'ft' => 1.5, 'avg' => 1.6],
    '2310067-2' => ['mt' => 1.5, 'ft' => 1.5, 'avg' => 1.5],
    '2310017-2' => ['mt' => 1.6, 'ft' => 1.5, 'avg' => 1.6],
    '2310050-2' => ['mt' => 1.8, 'ft' => 1.5, 'avg' => 1.7],
    '2310027-1' => ['mt' => 1.6, 'ft' => 1.5, 'avg' => 1.6],
    '2310091-2' => ['mt' => 1.4, 'ft' => 1.5, 'avg' => 1.5],
    '2310045-2' => ['mt' => 2.0, 'ft' => 1.5, 'avg' => 1.8],
    '2310018-1' => ['mt' => 1.7, 'ft' => 1.5, 'avg' => 1.6],
    '2310007-2' => ['mt' => 1.7, 'ft' => 1.6, 'avg' => 1.7],
    '2310038-2' => ['mt' => 1.5, 'ft' => 1.6, 'avg' => 1.6],
    '2310005-2' => ['mt' => 1.7, 'ft' => 1.5, 'avg' => 1.6],
    '2310016-2' => ['mt' => 1.9, 'ft' => 1.5, 'avg' => 1.7],
    '2310072-1' => ['mt' => 2.6, 'ft' => 2.6, 'avg' => 2.6],
    '2310026-2' => ['mt' => 1.5, 'ft' => 1.5, 'avg' => 1.5],
    '2310036-2' => ['mt' => 2.0, 'ft' => 1.6, 'avg' => 1.8],
    '2310042-2' => ['mt' => 1.5, 'ft' => 1.5, 'avg' => 1.5],
    '2310094-1' => ['mt' => 2.7, 'ft' => 1.6, 'avg' => 2.2],
    '2310099-1' => ['mt' => 2.2, 'ft' => 1.8, 'avg' => 2.0],
    '2310025-2' => ['mt' => 2.0, 'ft' => 1.6, 'avg' => 1.8],
    '2310115-1' => ['mt' => 1.6, 'ft' => 1.6, 'avg' => 1.6],
    '2310047-2' => ['mt' => 1.9, 'ft' => 1.5, 'avg' => 1.7],
    '2310060-1' => ['mt' => 2.4, 'ft' => 1.6, 'avg' => 2.0],
    '2310008-2' => ['mt' => 2.2, 'ft' => 1.6, 'avg' => 1.9],
    '2310028-2' => ['mt' => 2.2, 'ft' => 1.8, 'avg' => 2.0],
];

out('Seed IT 307 IF-3-A-8 MT/FT from transmuted grades');
out('Mode: ' . ($dryRun ? 'DRY RUN' : 'APPLY'));

$gradeToMark = build_transmuted_to_mark_map();

foreach ($transmutedByStudentNo as $studentNo => $termData) {
    foreach (['mt', 'ft', 'avg'] as $k) {
        if (!isset($termData[$k])) {
            throw new RuntimeException("Missing {$k} for student {$studentNo}");
        }
        $gradeKey = normalize_grade_key($termData[$k]);
        if (!isset($gradeToMark[$gradeKey])) {
            throw new RuntimeException("Unsupported transmuted grade {$gradeKey} for student {$studentNo} ({$k})");
        }
    }
}

$conn->begin_transaction();

try {
    $classStmt = $conn->prepare(
        "SELECT cr.id AS class_record_id,
                cr.subject_id,
                cr.section,
                cr.academic_year,
                cr.semester,
                COALESCE(NULLIF(TRIM(cr.year_level), ''), 'N/A') AS year_level,
                COALESCE(NULLIF(TRIM(s.course), ''), 'N/A') AS course,
                s.subject_code,
                s.subject_name
         FROM class_records cr
         JOIN subjects s ON s.id = cr.subject_id
         WHERE cr.status = 'active'
           AND cr.section = ?
           AND cr.academic_year = ?
           AND cr.semester = ?
           AND s.subject_code = ?
         LIMIT 1"
    );
    if (!$classStmt) throw new RuntimeException('Failed to prepare class query');
    $classStmt->bind_param('ssss', $targetSection, $targetAcademicYear, $targetSemester, $targetSubjectCode);
    $classStmt->execute();
    $classRes = $classStmt->get_result();
    $classRow = ($classRes && $classRes->num_rows === 1) ? $classRes->fetch_assoc() : null;
    $classStmt->close();

    if (!is_array($classRow)) {
        throw new RuntimeException('Target class record not found.');
    }
    if (trim((string) ($classRow['subject_name'] ?? '')) !== $targetSubjectName) {
        throw new RuntimeException('Subject name mismatch for target class.');
    }

    $classRecordId = (int) ($classRow['class_record_id'] ?? 0);
    $subjectId = (int) ($classRow['subject_id'] ?? 0);
    $course = trim((string) ($classRow['course'] ?? 'N/A'));
    $yearLevel = trim((string) ($classRow['year_level'] ?? 'N/A'));
    $section = trim((string) ($classRow['section'] ?? ''));
    $academicYear = trim((string) ($classRow['academic_year'] ?? ''));
    $semester = trim((string) ($classRow['semester'] ?? ''));

    out("Target class_record_id: {$classRecordId}");

    $teacherId = 0;
    $teacherStmt = $conn->prepare(
        "SELECT teacher_id
         FROM teacher_assignments
         WHERE class_record_id = ? AND status = 'active'
         ORDER BY id ASC
         LIMIT 1"
    );
    if (!$teacherStmt) throw new RuntimeException('Failed to prepare teacher query');
    $teacherStmt->bind_param('i', $classRecordId);
    $teacherStmt->execute();
    $teacherRes = $teacherStmt->get_result();
    if ($teacherRes && $teacherRes->num_rows === 1) {
        $teacherId = (int) (($teacherRes->fetch_assoc()['teacher_id'] ?? 0));
    }
    $teacherStmt->close();
    if ($teacherId <= 0) $teacherId = 1;

    $rosterByStudentNo = [];
    $rosterStmt = $conn->prepare(
        "SELECT ce.student_id, st.StudentNo
         FROM class_enrollments ce
         JOIN students st ON st.id = ce.student_id
         WHERE ce.class_record_id = ? AND ce.status = 'enrolled'"
    );
    if (!$rosterStmt) throw new RuntimeException('Failed to prepare roster query');
    $rosterStmt->bind_param('i', $classRecordId);
    $rosterStmt->execute();
    $rosterRes = $rosterStmt->get_result();
    while ($rosterRes && ($r = $rosterRes->fetch_assoc())) {
        $studentNo = trim((string) ($r['StudentNo'] ?? ''));
        if ($studentNo === '') continue;
        $rosterByStudentNo[$studentNo] = (int) ($r['student_id'] ?? 0);
    }
    $rosterStmt->close();

    if (count($rosterByStudentNo) !== count($transmutedByStudentNo)) {
        throw new RuntimeException(
            'Roster count mismatch. roster=' . count($rosterByStudentNo) . ' input=' . count($transmutedByStudentNo)
        );
    }

    $missingInput = [];
    foreach ($transmutedByStudentNo as $studentNo => $_tmp) {
        if (!isset($rosterByStudentNo[$studentNo])) $missingInput[] = $studentNo;
    }
    if (count($missingInput) > 0) {
        throw new RuntimeException('Input contains student(s) not in roster: ' . implode(', ', $missingInput));
    }

    $configByTerm = [];
    $cfgStmt = $conn->prepare(
        "SELECT id, term
         FROM section_grading_configs
         WHERE subject_id = ?
           AND course = ?
           AND year = ?
           AND section = ?
           AND academic_year = ?
           AND semester = ?
           AND term = ?"
    );
    if (!$cfgStmt) throw new RuntimeException('Failed to prepare config query');
    foreach ($targetTerms as $term) {
        $cfgStmt->bind_param('issssss', $subjectId, $course, $yearLevel, $section, $academicYear, $semester, $term);
        $cfgStmt->execute();
        $cfgRes = $cfgStmt->get_result();
        $cfgRow = ($cfgRes && $cfgRes->num_rows === 1) ? $cfgRes->fetch_assoc() : null;
        if (!is_array($cfgRow)) {
            throw new RuntimeException("Missing section_grading_config for term {$term}");
        }
        $configByTerm[$term] = (int) ($cfgRow['id'] ?? 0);
    }
    $cfgStmt->close();

    $componentsByTerm = [];
    $compStmt = $conn->prepare(
        "SELECT id, component_name, component_code, component_type, display_order
         FROM grading_components
         WHERE section_config_id = ?
         ORDER BY display_order ASC, id ASC"
    );
    if (!$compStmt) throw new RuntimeException('Failed to prepare component query');
    foreach ($configByTerm as $term => $configId) {
        $list = [];
        $compStmt->bind_param('i', $configId);
        $compStmt->execute();
        $compRes = $compStmt->get_result();
        while ($compRes && ($compRow = $compRes->fetch_assoc())) {
            $list[] = $compRow;
        }
        if (count($list) === 0) {
            throw new RuntimeException("No components found for term {$term}");
        }
        $componentsByTerm[$term] = $list;
    }
    $compStmt->close();

    $assessmentIdsByTerm = ['midterm' => [], 'final' => []];
    $createdAssessments = 0;
    $updatedAssessments = 0;

    $findAssessmentStmt = $conn->prepare(
        "SELECT id
         FROM grading_assessments
         WHERE grading_component_id = ? AND name = ?
         LIMIT 1"
    );
    $insertAssessmentStmt = $conn->prepare(
        "INSERT INTO grading_assessments
         (grading_component_id, name, max_score, assessment_date, module_type, is_active, display_order, created_by)
         VALUES (?, ?, 100.00, NULL, 'assessment', 1, ?, ?)"
    );
    $updateAssessmentStmt = $conn->prepare(
        "UPDATE grading_assessments
         SET max_score = 100.00,
             module_type = 'assessment',
             is_active = 1,
             display_order = ?,
             created_by = ?
         WHERE id = ?"
    );
    if (!$findAssessmentStmt || !$insertAssessmentStmt || !$updateAssessmentStmt) {
        throw new RuntimeException('Failed to prepare assessment statements');
    }

    foreach ($componentsByTerm as $term => $components) {
        foreach ($components as $component) {
            $componentId = (int) ($component['id'] ?? 0);
            if ($componentId <= 0) continue;

            $names = assessment_names_for_component($component);
            $displayOrder = 1;
            foreach ($names as $assessmentName) {
                $assessmentId = 0;

                $findAssessmentStmt->bind_param('is', $componentId, $assessmentName);
                $findAssessmentStmt->execute();
                $findRes = $findAssessmentStmt->get_result();
                $existing = ($findRes && $findRes->num_rows === 1) ? $findRes->fetch_assoc() : null;

                if (is_array($existing)) {
                    $assessmentId = (int) ($existing['id'] ?? 0);
                    $updateAssessmentStmt->bind_param('iii', $displayOrder, $teacherId, $assessmentId);
                    $updateAssessmentStmt->execute();
                    $updatedAssessments++;
                } else {
                    $insertAssessmentStmt->bind_param('isii', $componentId, $assessmentName, $displayOrder, $teacherId);
                    $insertAssessmentStmt->execute();
                    $assessmentId = (int) $conn->insert_id;
                    $createdAssessments++;
                }

                if ($assessmentId <= 0) {
                    throw new RuntimeException("Failed to resolve assessment ID for {$term} / {$assessmentName}");
                }

                $assessmentIdsByTerm[$term][] = $assessmentId;
                $displayOrder++;
            }
        }
    }

    $findScoreStmt = $conn->prepare(
        "SELECT id
         FROM grading_assessment_scores
         WHERE assessment_id = ? AND student_id = ?
         LIMIT 1"
    );
    $insertScoreStmt = $conn->prepare(
        "INSERT INTO grading_assessment_scores
         (assessment_id, student_id, score, recorded_by)
         VALUES (?, ?, ?, ?)"
    );
    $updateScoreStmt = $conn->prepare(
        "UPDATE grading_assessment_scores
         SET score = ?, recorded_by = ?
         WHERE id = ?"
    );
    if (!$findScoreStmt || !$insertScoreStmt || !$updateScoreStmt) {
        throw new RuntimeException('Failed to prepare score statements');
    }

    $createdScores = 0;
    $updatedScores = 0;
    foreach ($transmutedByStudentNo as $studentNo => $grades) {
        $studentId = (int) ($rosterByStudentNo[$studentNo] ?? 0);
        if ($studentId <= 0) continue;

        $mtMark = (float) ($gradeToMark[normalize_grade_key($grades['mt'])] ?? 0);
        $ftMark = (float) ($gradeToMark[normalize_grade_key($grades['ft'])] ?? 0);

        foreach ($assessmentIdsByTerm['midterm'] as $assessmentId) {
            $findScoreStmt->bind_param('ii', $assessmentId, $studentId);
            $findScoreStmt->execute();
            $findRes = $findScoreStmt->get_result();
            $existing = ($findRes && $findRes->num_rows === 1) ? $findRes->fetch_assoc() : null;

            if (is_array($existing)) {
                $scoreId = (int) ($existing['id'] ?? 0);
                $updateScoreStmt->bind_param('dii', $mtMark, $teacherId, $scoreId);
                $updateScoreStmt->execute();
                $updatedScores++;
            } else {
                $insertScoreStmt->bind_param('iidi', $assessmentId, $studentId, $mtMark, $teacherId);
                $insertScoreStmt->execute();
                $createdScores++;
            }
        }

        foreach ($assessmentIdsByTerm['final'] as $assessmentId) {
            $findScoreStmt->bind_param('ii', $assessmentId, $studentId);
            $findScoreStmt->execute();
            $findRes = $findScoreStmt->get_result();
            $existing = ($findRes && $findRes->num_rows === 1) ? $findRes->fetch_assoc() : null;

            if (is_array($existing)) {
                $scoreId = (int) ($existing['id'] ?? 0);
                $updateScoreStmt->bind_param('dii', $ftMark, $teacherId, $scoreId);
                $updateScoreStmt->execute();
                $updatedScores++;
            } else {
                $insertScoreStmt->bind_param('iidi', $assessmentId, $studentId, $ftMark, $teacherId);
                $insertScoreStmt->execute();
                $createdScores++;
            }
        }
    }

    $findScoreStmt->close();
    $insertScoreStmt->close();
    $updateScoreStmt->close();
    $findAssessmentStmt->close();
    $insertAssessmentStmt->close();
    $updateAssessmentStmt->close();

    $avgUpdated = 0;
    $updateAvgStmt = $conn->prepare(
        "UPDATE class_enrollments ce
         JOIN students st ON st.id = ce.student_id
         SET ce.grade = ?
         WHERE ce.class_record_id = ? AND ce.status = 'enrolled' AND st.StudentNo = ?"
    );
    if (!$updateAvgStmt) throw new RuntimeException('Failed to prepare avg update statement');
    foreach ($transmutedByStudentNo as $studentNo => $grades) {
        $avg = (float) ($grades['avg'] ?? 0);
        $updateAvgStmt->bind_param('dis', $avg, $classRecordId, $studentNo);
        $updateAvgStmt->execute();
        if ($updateAvgStmt->affected_rows >= 0) $avgUpdated++;
    }
    $updateAvgStmt->close();

    if ($dryRun) {
        $conn->rollback();
        out('Dry run complete. Transaction rolled back.');
    } else {
        $conn->commit();
        out('Apply complete. Transaction committed.');
    }

    out('Summary:');
    out('  Assessments created: ' . $createdAssessments);
    out('  Assessments updated: ' . $updatedAssessments);
    out('  Scores created: ' . $createdScores);
    out('  Scores updated: ' . $updatedScores);
    out('  AVG grade rows touched: ' . $avgUpdated);
    out('  Midterm assessments planned: ' . count($assessmentIdsByTerm['midterm']));
    out('  Final assessments planned: ' . count($assessmentIdsByTerm['final']));
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

