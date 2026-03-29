<?php
declare(strict_types=1);

/**
 * Revise IT 307 (IF-3-A-8, AY 2025-2026, 1st Sem) assessment structure + scores.
 *
 * Requested rules:
 * - Quiz: 3 assessments with max scores 20, 10, 15
 * - Attendance: 12 assessments, each max 1 (1 present, 0 absent, 0.5 incomplete/late)
 * - Recitation: single assessment, max 5
 * - Project: single assessment, max 100, score=100 for all students
 *
 * Also keeps MT/FT target outcomes from provided transmuted grades by converting
 * transmuted grade -> term mark (55..100) and distributing scores accordingly.
 *
 * Usage:
 *   php tools/revise_it307_if3a8_component_structure.php --dry-run
 *   php tools/revise_it307_if3a8_component_structure.php
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

function clamp_float(float $v, float $min, float $max): float {
    if ($v < $min) return $min;
    if ($v > $max) return $max;
    return $v;
}

function build_transmuted_to_mark_map(): array {
    $map = [];
    $mark = 55;
    for ($gradeTenths = 50; $gradeTenths >= 13; $gradeTenths--) {
        $grade = number_format($gradeTenths / 10, 1, '.', '');
        $map[$grade] = $mark;
        $mark++;
    }
    $map['1.2'] = 95;
    $map['1.1'] = 98;
    $map['1.0'] = 100;
    return $map;
}

function transmuted_from_initial_mark(float $initialMark): string {
    $score = (int) round($initialMark, 0);
    if ($score <= 0) return '';
    if ($score < 1) $score = 1;
    if ($score > 100) $score = 100;

    if ($score <= 55) return '5.0';
    if ($score >= 99) return '1.0';
    if ($score >= 96) return '1.1';
    if ($score >= 93) return '1.2';

    $transmuted = 5.0 - (($score - 55) * 0.1);
    return number_format($transmuted, 1, '.', '');
}

function solve_term_exam_score_for_target(float $baseOtherPoints, string $targetTransmuted): int {
    $targetTransmuted = normalize_grade_key($targetTransmuted);
    $bestScore = 0;
    $bestDelta = PHP_FLOAT_MAX;

    for ($exam = 0; $exam <= 100; $exam++) {
        $initial = $baseOtherPoints + (0.40 * (float) $exam);
        $transmuted = transmuted_from_initial_mark($initial);
        if ($transmuted === $targetTransmuted) {
            return $exam;
        }

        $delta = abs((float) $transmuted - (float) $targetTransmuted);
        if ($delta < $bestDelta) {
            $bestDelta = $delta;
            $bestScore = $exam;
        }
    }

    return $bestScore;
}

function build_attendance_pattern(float $targetPoints, string $seed): array {
    $targetPoints = clamp_float(round($targetPoints * 2.0) / 2.0, 0.0, 12.0);
    $present = (int) floor($targetPoints);
    $remaining = $targetPoints - (float) $present;
    $late = (abs($remaining - 0.5) < 0.001) ? 1 : 0;
    $absent = 12 - $present - $late;
    if ($absent < 0) $absent = 0;

    $scores = [];
    for ($i = 0; $i < $present; $i++) $scores[] = 1.0;
    for ($i = 0; $i < $late; $i++) $scores[] = 0.5;
    while (count($scores) < 12) $scores[] = 0.0;

    // Deterministic rotation so all students don't get the same absence slots.
    $offset = abs((int) crc32($seed)) % 12;
    if ($offset > 0) {
        $scores = array_merge(array_slice($scores, $offset), array_slice($scores, 0, $offset));
    }
    return array_slice($scores, 0, 12);
}

function ensure_component_assessments(
    mysqli $conn,
    int $componentId,
    array $definitions,
    int $teacherId,
    array &$summary
): array {
    $existing = [];
    $q = $conn->prepare(
        "SELECT id, name
         FROM grading_assessments
         WHERE grading_component_id = ?
         ORDER BY id ASC"
    );
    if (!$q) throw new RuntimeException('Failed preparing assessment select');
    $q->bind_param('i', $componentId);
    $q->execute();
    $res = $q->get_result();
    while ($res && ($r = $res->fetch_assoc())) {
        $existing[] = ['id' => (int) ($r['id'] ?? 0), 'name' => (string) ($r['name'] ?? '')];
    }
    $q->close();

    $byName = [];
    foreach ($existing as $row) {
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') continue;
        if (!isset($byName[$name])) $byName[$name] = [];
        $byName[$name][] = (int) ($row['id'] ?? 0);
    }

    $usedIds = [];
    $out = [];

    $ins = $conn->prepare(
        "INSERT INTO grading_assessments
         (grading_component_id, name, max_score, assessment_date, module_type, is_active, display_order, created_by)
         VALUES (?, ?, ?, NULL, 'assessment', 1, ?, ?)"
    );
    $upd = $conn->prepare(
        "UPDATE grading_assessments
         SET name = ?, max_score = ?, module_type = 'assessment', is_active = 1, display_order = ?, created_by = ?
         WHERE id = ?"
    );
    $deact = $conn->prepare(
        "UPDATE grading_assessments
         SET is_active = 0
         WHERE id = ?"
    );
    if (!$ins || !$upd || !$deact) throw new RuntimeException('Failed preparing assessment upsert statements');

    $displayOrder = 1;
    foreach ($definitions as $def) {
        $name = trim((string) ($def['name'] ?? ''));
        $maxScore = (float) ($def['max_score'] ?? 100.0);
        if ($name === '') continue;

        $chosenId = 0;
        if (isset($byName[$name])) {
            foreach ($byName[$name] as $candidateId) {
                if (!in_array($candidateId, $usedIds, true)) {
                    $chosenId = (int) $candidateId;
                    break;
                }
            }
        }

        if ($chosenId <= 0) {
            foreach ($existing as $row) {
                $candidateId = (int) ($row['id'] ?? 0);
                if ($candidateId <= 0) continue;
                if (in_array($candidateId, $usedIds, true)) continue;
                $chosenId = $candidateId;
                break;
            }
        }

        if ($chosenId > 0) {
            $upd->bind_param('sdiii', $name, $maxScore, $displayOrder, $teacherId, $chosenId);
            $upd->execute();
            $summary['assessments_updated']++;
        } else {
            $ins->bind_param('isdii', $componentId, $name, $maxScore, $displayOrder, $teacherId);
            $ins->execute();
            $chosenId = (int) $conn->insert_id;
            $summary['assessments_created']++;
        }

        if ($chosenId <= 0) throw new RuntimeException("Failed ensuring assessment: {$name}");

        $usedIds[] = $chosenId;
        $out[] = ['id' => $chosenId, 'name' => $name, 'max_score' => $maxScore];
        $displayOrder++;
    }

    foreach ($existing as $row) {
        $existingId = (int) ($row['id'] ?? 0);
        if ($existingId <= 0) continue;
        if (in_array($existingId, $usedIds, true)) continue;
        $deact->bind_param('i', $existingId);
        $deact->execute();
        $summary['assessments_deactivated']++;
    }

    $ins->close();
    $upd->close();
    $deact->close();

    return $out;
}

function upsert_score(
    mysqli $conn,
    int $assessmentId,
    int $studentId,
    float $score,
    int $teacherId,
    array &$summary
): void {
    static $sel = null;
    static $ins = null;
    static $upd = null;

    if ($sel === null) {
        $sel = $conn->prepare(
            "SELECT id
             FROM grading_assessment_scores
             WHERE assessment_id = ? AND student_id = ?
             LIMIT 1"
        );
        $ins = $conn->prepare(
            "INSERT INTO grading_assessment_scores
             (assessment_id, student_id, score, recorded_by)
             VALUES (?, ?, ?, ?)"
        );
        $upd = $conn->prepare(
            "UPDATE grading_assessment_scores
             SET score = ?, recorded_by = ?
             WHERE id = ?"
        );
        if (!$sel || !$ins || !$upd) throw new RuntimeException('Failed preparing score upsert statements');
    }

    $score = round($score, 2);

    $sel->bind_param('ii', $assessmentId, $studentId);
    $sel->execute();
    $res = $sel->get_result();
    $row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;

    if (is_array($row)) {
        $scoreId = (int) ($row['id'] ?? 0);
        $upd->bind_param('dii', $score, $teacherId, $scoreId);
        $upd->execute();
        $summary['scores_updated']++;
    } else {
        $ins->bind_param('iidi', $assessmentId, $studentId, $score, $teacherId);
        $ins->execute();
        $summary['scores_created']++;
    }
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

out('Revise IT 307 IF-3-A-8 component structure and scores');
out('Mode: ' . ($dryRun ? 'DRY RUN' : 'APPLY'));

$gradeToMark = build_transmuted_to_mark_map();
foreach ($transmutedByStudentNo as $studentNo => $terms) {
    foreach (['mt', 'ft'] as $k) {
        $g = normalize_grade_key($terms[$k] ?? null);
        if (!isset($gradeToMark[$g])) {
            throw new RuntimeException("Unsupported transmuted grade {$g} for {$studentNo} {$k}");
        }
    }
}

$summary = [
    'assessments_created' => 0,
    'assessments_updated' => 0,
    'assessments_deactivated' => 0,
    'scores_created' => 0,
    'scores_updated' => 0,
    'class_enrollment_avg_updates' => 0,
];

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
    if (!$classStmt) throw new RuntimeException('Failed preparing class lookup');
    $classStmt->bind_param('ssss', $targetSection, $targetAcademicYear, $targetSemester, $targetSubjectCode);
    $classStmt->execute();
    $classRes = $classStmt->get_result();
    $classRow = ($classRes && $classRes->num_rows === 1) ? $classRes->fetch_assoc() : null;
    $classStmt->close();
    if (!is_array($classRow)) throw new RuntimeException('Target class not found');
    if (trim((string) ($classRow['subject_name'] ?? '')) !== $targetSubjectName) {
        throw new RuntimeException('Subject name mismatch');
    }

    $classRecordId = (int) ($classRow['class_record_id'] ?? 0);
    $subjectId = (int) ($classRow['subject_id'] ?? 0);
    $course = trim((string) ($classRow['course'] ?? 'N/A'));
    $yearLevel = trim((string) ($classRow['year_level'] ?? 'N/A'));
    $section = trim((string) ($classRow['section'] ?? ''));
    $academicYear = trim((string) ($classRow['academic_year'] ?? ''));
    $semester = trim((string) ($classRow['semester'] ?? ''));
    out('Target class_record_id: ' . $classRecordId);

    $teacherId = 1;
    $tq = $conn->prepare(
        "SELECT teacher_id
         FROM teacher_assignments
         WHERE class_record_id = ? AND status = 'active'
         ORDER BY id ASC
         LIMIT 1"
    );
    if (!$tq) throw new RuntimeException('Failed preparing teacher lookup');
    $tq->bind_param('i', $classRecordId);
    $tq->execute();
    $tr = $tq->get_result();
    if ($tr && $tr->num_rows === 1) $teacherId = (int) (($tr->fetch_assoc()['teacher_id'] ?? 1));
    $tq->close();

    $studentMap = [];
    $sq = $conn->prepare(
        "SELECT ce.student_id, st.StudentNo
         FROM class_enrollments ce
         JOIN students st ON st.id = ce.student_id
         WHERE ce.class_record_id = ? AND ce.status = 'enrolled'"
    );
    if (!$sq) throw new RuntimeException('Failed preparing roster lookup');
    $sq->bind_param('i', $classRecordId);
    $sq->execute();
    $sr = $sq->get_result();
    while ($sr && ($row = $sr->fetch_assoc())) {
        $studentNo = trim((string) ($row['StudentNo'] ?? ''));
        if ($studentNo === '') continue;
        $studentMap[$studentNo] = (int) ($row['student_id'] ?? 0);
    }
    $sq->close();

    if (count($studentMap) !== count($transmutedByStudentNo)) {
        throw new RuntimeException('Roster/input count mismatch: roster=' . count($studentMap) . ' input=' . count($transmutedByStudentNo));
    }

    $configByTerm = [];
    $cfg = $conn->prepare(
        "SELECT id, term
         FROM section_grading_configs
         WHERE subject_id = ? AND course = ? AND year = ? AND section = ? AND academic_year = ? AND semester = ? AND term = ?
         LIMIT 1"
    );
    if (!$cfg) throw new RuntimeException('Failed preparing config lookup');
    foreach ($targetTerms as $term) {
        $cfg->bind_param('issssss', $subjectId, $course, $yearLevel, $section, $academicYear, $semester, $term);
        $cfg->execute();
        $rr = $cfg->get_result();
        $row = ($rr && $rr->num_rows === 1) ? $rr->fetch_assoc() : null;
        if (!is_array($row)) throw new RuntimeException("Config not found for {$term}");
        $configByTerm[$term] = (int) ($row['id'] ?? 0);
    }
    $cfg->close();

    $assessmentMapByTerm = ['midterm' => [], 'final' => []];

    foreach ($configByTerm as $term => $configId) {
        $components = [];
        $cq = $conn->prepare(
            "SELECT id, component_code, component_name
             FROM grading_components
             WHERE section_config_id = ?"
        );
        if (!$cq) throw new RuntimeException('Failed preparing component lookup');
        $cq->bind_param('i', $configId);
        $cq->execute();
        $cr = $cq->get_result();
        while ($cr && ($row = $cr->fetch_assoc())) {
            $code = strtoupper(trim((string) ($row['component_code'] ?? '')));
            if ($code === '') continue;
            $components[$code] = [
                'id' => (int) ($row['id'] ?? 0),
                'name' => trim((string) ($row['component_name'] ?? $code)),
            ];
        }
        $cq->close();

        $requiredCodes = ['QUIZ', 'ASSIGN', 'RECIT', 'ATTEND', 'ACT', 'PROJ', 'TEXAM'];
        foreach ($requiredCodes as $code) {
            if (!isset($components[$code])) throw new RuntimeException("Missing component {$code} for {$term}");
        }

        $definitionsByCode = [
            'QUIZ' => [
                ['name' => 'Quiz 1', 'max_score' => 20.0],
                ['name' => 'Quiz 2', 'max_score' => 10.0],
                ['name' => 'Quiz 3', 'max_score' => 15.0],
            ],
            'ASSIGN' => [
                ['name' => 'Assignment 1', 'max_score' => 25.0],
                ['name' => 'Assignment 2', 'max_score' => 15.0],
                ['name' => 'Assignment 3', 'max_score' => 30.0],
            ],
            'RECIT' => [
                ['name' => 'Recitation', 'max_score' => 5.0],
            ],
            'ATTEND' => [
                ['name' => 'Attendance 1', 'max_score' => 1.0],
                ['name' => 'Attendance 2', 'max_score' => 1.0],
                ['name' => 'Attendance 3', 'max_score' => 1.0],
                ['name' => 'Attendance 4', 'max_score' => 1.0],
                ['name' => 'Attendance 5', 'max_score' => 1.0],
                ['name' => 'Attendance 6', 'max_score' => 1.0],
                ['name' => 'Attendance 7', 'max_score' => 1.0],
                ['name' => 'Attendance 8', 'max_score' => 1.0],
                ['name' => 'Attendance 9', 'max_score' => 1.0],
                ['name' => 'Attendance 10', 'max_score' => 1.0],
                ['name' => 'Attendance 11', 'max_score' => 1.0],
                ['name' => 'Attendance 12', 'max_score' => 1.0],
            ],
            'ACT' => [
                ['name' => 'Activity 1', 'max_score' => 25.0],
                ['name' => 'Activity 2', 'max_score' => 15.0],
                ['name' => 'Activity 3', 'max_score' => 30.0],
            ],
            'PROJ' => [
                ['name' => 'Project', 'max_score' => 100.0],
            ],
            'TEXAM' => [
                ['name' => 'Term Exam', 'max_score' => 100.0],
            ],
        ];

        foreach ($definitionsByCode as $code => $defs) {
            $componentId = (int) ($components[$code]['id'] ?? 0);
            $ensured = ensure_component_assessments($conn, $componentId, $defs, $teacherId, $summary);
            $assessmentMapByTerm[$term][$code] = $ensured;
        }
    }

    foreach ($transmutedByStudentNo as $studentNo => $grades) {
        $studentId = (int) ($studentMap[$studentNo] ?? 0);
        if ($studentId <= 0) throw new RuntimeException("Student not found in roster: {$studentNo}");

        foreach ($targetTerms as $term) {
            $termKey = $term === 'midterm' ? 'mt' : 'ft';
            $transmuted = normalize_grade_key($grades[$termKey] ?? null);
            $targetMark = (float) ($gradeToMark[$transmuted] ?? 0.0);

            // Start from non-project baseline, then lock attendance to discrete values.
            $p0 = (($targetMark - 20.0) / 0.8);
            $p0 = clamp_float($p0, 0.0, 100.0);
            $attendancePoints = round(($p0 / 100.0 * 12.0) * 2.0) / 2.0;
            $attendancePoints = clamp_float($attendancePoints, 0.0, 12.0);
            $attendancePct = ($attendancePoints / 12.0) * 100.0;

            // Force exact target mark using common pct for non-attendance/non-project components.
            $pCore = ($targetMark - 20.0 - (0.05 * $attendancePct)) / 0.75;
            $pCore = clamp_float($pCore, 0.0, 100.0);

            $quiz1 = round(20.0 * $pCore / 100.0, 0);
            $quiz2 = round(10.0 * $pCore / 100.0, 0);
            $quiz3 = round(15.0 * $pCore / 100.0, 0);
            $recitation = round(5.0 * $pCore / 100.0, 0);
            $assignment1 = round(25.0 * $pCore / 100.0, 0);
            $assignment2 = round(15.0 * $pCore / 100.0, 0);
            $assignment3 = round(30.0 * $pCore / 100.0, 0);
            $activity1 = round(25.0 * $pCore / 100.0, 0);
            $activity2 = round(15.0 * $pCore / 100.0, 0);
            $activity3 = round(30.0 * $pCore / 100.0, 0);
            $projectScore = 100.0;

            $attPattern = build_attendance_pattern($attendancePoints, $studentNo . ':' . $term);

            // Quiz
            $quizAssessments = $assessmentMapByTerm[$term]['QUIZ'] ?? [];
            if (count($quizAssessments) !== 3) throw new RuntimeException("Quiz assessments mismatch for {$term}");
            upsert_score($conn, (int) $quizAssessments[0]['id'], $studentId, $quiz1, $teacherId, $summary);
            upsert_score($conn, (int) $quizAssessments[1]['id'], $studentId, $quiz2, $teacherId, $summary);
            upsert_score($conn, (int) $quizAssessments[2]['id'], $studentId, $quiz3, $teacherId, $summary);

            // Assignment x3
            $assignAssessments = $assessmentMapByTerm[$term]['ASSIGN'] ?? [];
            if (count($assignAssessments) !== 3) throw new RuntimeException("Assignment assessments mismatch for {$term}");
            upsert_score($conn, (int) ($assignAssessments[0]['id'] ?? 0), $studentId, $assignment1, $teacherId, $summary);
            upsert_score($conn, (int) ($assignAssessments[1]['id'] ?? 0), $studentId, $assignment2, $teacherId, $summary);
            upsert_score($conn, (int) ($assignAssessments[2]['id'] ?? 0), $studentId, $assignment3, $teacherId, $summary);

            // Recitation x1
            $recitAssessments = $assessmentMapByTerm[$term]['RECIT'] ?? [];
            if (count($recitAssessments) !== 1) throw new RuntimeException("Recitation assessments mismatch for {$term}");
            upsert_score($conn, (int) $recitAssessments[0]['id'], $studentId, $recitation, $teacherId, $summary);

            // Attendance x12
            $attAssessments = $assessmentMapByTerm[$term]['ATTEND'] ?? [];
            if (count($attAssessments) !== 12) throw new RuntimeException("Attendance assessments mismatch for {$term}");
            for ($i = 0; $i < 12; $i++) {
                upsert_score($conn, (int) ($attAssessments[$i]['id'] ?? 0), $studentId, (float) ($attPattern[$i] ?? 0.0), $teacherId, $summary);
            }

            // Activity x3
            $actAssessments = $assessmentMapByTerm[$term]['ACT'] ?? [];
            if (count($actAssessments) !== 3) throw new RuntimeException("Activity assessments mismatch for {$term}");
            upsert_score($conn, (int) ($actAssessments[0]['id'] ?? 0), $studentId, $activity1, $teacherId, $summary);
            upsert_score($conn, (int) ($actAssessments[1]['id'] ?? 0), $studentId, $activity2, $teacherId, $summary);
            upsert_score($conn, (int) ($actAssessments[2]['id'] ?? 0), $studentId, $activity3, $teacherId, $summary);

            // Project x1
            $projAssessments = $assessmentMapByTerm[$term]['PROJ'] ?? [];
            if (count($projAssessments) !== 1) throw new RuntimeException("Project assessments mismatch for {$term}");
            upsert_score($conn, (int) $projAssessments[0]['id'], $studentId, $projectScore, $teacherId, $summary);

            // Solve term exam score so transmuted MT/FT exactly matches the target.
            $quizPct = (($quiz1 + $quiz2 + $quiz3) / 45.0) * 100.0;
            $assignPct = (($assignment1 + $assignment2 + $assignment3) / 70.0) * 100.0;
            $actPct = (($activity1 + $activity2 + $activity3) / 70.0) * 100.0;
            $recitPct = ($recitation / 5.0) * 100.0;
            $baseOtherPoints =
                (0.10 * $quizPct) +
                (0.10 * $assignPct) +
                (0.10 * $actPct) +
                (0.05 * $recitPct) +
                (0.05 * $attendancePct) +
                20.0; // project fixed at 100 with 20% weight
            $termExamScore = solve_term_exam_score_for_target($baseOtherPoints, $transmuted);

            // Term exam x1
            $examAssessments = $assessmentMapByTerm[$term]['TEXAM'] ?? [];
            if (count($examAssessments) !== 1) throw new RuntimeException("Term exam assessments mismatch for {$term}");
            upsert_score($conn, (int) $examAssessments[0]['id'], $studentId, $termExamScore, $teacherId, $summary);
        }
    }

    // Keep AVG (transmuted) in class_enrollments.grade aligned to source sheet.
    $avgUpd = $conn->prepare(
        "UPDATE class_enrollments ce
         JOIN students st ON st.id = ce.student_id
         SET ce.grade = ?
         WHERE ce.class_record_id = ? AND ce.status = 'enrolled' AND st.StudentNo = ?"
    );
    if (!$avgUpd) throw new RuntimeException('Failed preparing AVG update');
    foreach ($transmutedByStudentNo as $studentNo => $grades) {
        $avg = (float) ($grades['avg'] ?? 0.0);
        $avgUpd->bind_param('dis', $avg, $classRecordId, $studentNo);
        $avgUpd->execute();
        $summary['class_enrollment_avg_updates']++;
    }
    $avgUpd->close();

    if ($dryRun) {
        $conn->rollback();
        out('Dry run complete. Transaction rolled back.');
    } else {
        $conn->commit();
        out('Apply complete. Transaction committed.');
    }

    out('Summary:');
    out('  Assessments created: ' . $summary['assessments_created']);
    out('  Assessments updated: ' . $summary['assessments_updated']);
    out('  Assessments deactivated: ' . $summary['assessments_deactivated']);
    out('  Scores created: ' . $summary['scores_created']);
    out('  Scores updated: ' . $summary['scores_updated']);
    out('  Class enrollment AVG updates: ' . $summary['class_enrollment_avg_updates']);
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
