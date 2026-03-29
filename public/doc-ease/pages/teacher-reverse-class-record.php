
<?php include '../layouts/session.php'; ?>
<?php require_active_role('teacher'); ?>
<?php include '../layouts/main.php'; ?>
<?php
require_once __DIR__ . '/../includes/class_record_builds.php';
require_once __DIR__ . '/../includes/grading.php';
require_once __DIR__ . '/../includes/reverse_class_record.php';
require_once __DIR__ . '/../includes/ai_credits.php';

ensure_section_grading_term($conn);
ensure_class_record_build_tables($conn);
ensure_grading_tables($conn);
reverse_class_record_ensure_settings_table($conn);
ai_credit_ensure_system($conn);
if (!defined('RCR_PREVIEW_SESSION_KEY')) define('RCR_PREVIEW_SESSION_KEY', 'reverse_class_record_preview_payload');

function rcr_h($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function rcr_allowed_types() { return ['quiz', 'assignment', 'project', 'exam', 'participation', 'other']; }
function rcr_key($v) {
    $v = (string) $v;
    if (substr($v, 0, 3) === "\xEF\xBB\xBF") $v = substr($v, 3);
    $v = trim($v);
    if ($v === '') return '';
    $v = function_exists('mb_strtolower') ? mb_strtolower($v, 'UTF-8') : strtolower($v);
    $v = preg_replace('/[^a-z0-9]+/i', ' ', $v);
    return trim((string) preg_replace('/\s+/', ' ', (string) $v));
}
function rcr_grade($raw) {
    if (!is_string($raw) && !is_numeric($raw)) return null;
    $raw = trim((string) $raw);
    if ($raw === '') return null;
    $raw = str_replace(',', '.', $raw);
    if (strpos($raw, '.') === 0) $raw = '0' . $raw;
    if (substr($raw, -1) === '.') $raw = substr($raw, 0, -1);
    if (!preg_match('/^-?\d+(?:\.\d+)?$/', $raw)) return null;
    return (float) $raw;
}
function rcr_split_line($line) {
    $line = (string) $line;
    if (strpos($line, "\t") !== false) return array_map('trim', explode("\t", $line));
    return array_map('trim', str_getcsv($line));
}
function rcr_parse_csv_upload($tmpPath) {
    $rows = []; $errors = [];
    $h = @fopen((string) $tmpPath, 'rb');
    if (!$h) return [[], ['Unable to read uploaded CSV file.'], ['mode' => 'csv']];
    $rowNo = 0;
    while (($cols = fgetcsv($h)) !== false) {
        $rowNo++;
        $name = trim((string) ($cols[0] ?? ''));
        $raw = trim((string) ($cols[1] ?? ''));
        if ($rowNo === 1 && substr($name, 0, 3) === "\xEF\xBB\xBF") $name = substr($name, 3);
        if ($name === '' && $raw === '') continue;
        if ($rowNo === 1 && rcr_grade($raw) === null && preg_match('/name|student|grade|score/i', strtolower($name . ' ' . $raw))) continue;
        if ($name === '') { $errors[] = 'Row ' . $rowNo . ': empty student name.'; continue; }
        $g = rcr_grade($raw);
        if ($g === null) { $errors[] = 'Row ' . $rowNo . ': invalid grade "' . $raw . '".'; continue; }
        $rows[] = ['row_no' => $rowNo, 'name' => $name, 'grade' => $g];
    }
    fclose($h);
    if (count($rows) === 0 && count($errors) === 0) $errors[] = 'CSV has no usable rows.';
    return [$rows, $errors, ['mode' => 'csv']];
}
function rcr_parse_simple_paste($text) {
    $rows = []; $errors = [];
    $lines = preg_split('/\r\n|\r|\n/', (string) $text);
    $rowNo = 0;
    foreach ($lines as $line) {
        $rowNo++;
        $line = trim((string) $line);
        if ($line === '') continue;
        $cols = rcr_split_line($line);
        $name = trim((string) ($cols[0] ?? ''));
        $raw = trim((string) ($cols[1] ?? ''));
        if ($name === '' && $raw === '') continue;
        if ($rowNo === 1 && rcr_grade($raw) === null && preg_match('/name|student|grade|score/i', strtolower($name . ' ' . $raw))) continue;
        if ($name === '') { $errors[] = 'Line ' . $rowNo . ': empty student name.'; continue; }
        $g = rcr_grade($raw);
        if ($g === null) { $errors[] = 'Line ' . $rowNo . ': invalid grade "' . $raw . '".'; continue; }
        $rows[] = ['row_no' => $rowNo, 'name' => $name, 'grade' => $g];
    }
    if (count($rows) === 0 && count($errors) === 0) $errors[] = 'Pasted content has no usable rows.';
    return [$rows, $errors, ['mode' => 'simple_paste']];
}
function rcr_parse_excel_paste($text, $term, $expectedSection, $expectedSubjectCode) {
    $rows = []; $errors = [];
    $meta = ['mode' => 'excel_paste', 'detected_section' => '', 'detected_subject_code' => ''];
    $matrix = [];
    $lines = preg_split('/\r\n|\r|\n/', (string) $text);
    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line === '') continue;
        $cells = rcr_split_line($line);
        if (count($cells) > 0) $matrix[] = $cells;
    }
    if (count($matrix) === 0) return [[], ['Paste content is empty.'], $meta];

    foreach ($matrix as $row) {
        $first = strtoupper(trim((string) ($row[0] ?? '')));
        if (preg_match('/^IF-\d+-[A-Z]-\d+$/', $first)) {
            $meta['detected_section'] = $first;
            $meta['detected_subject_code'] = strtoupper(trim((string) ($row[1] ?? '')));
            break;
        }
    }

    $expectedSection = strtoupper(trim((string) $expectedSection));
    $expectedSubjectCode = strtoupper(trim((string) $expectedSubjectCode));
    if ($meta['detected_section'] !== '' && $expectedSection !== '' && $meta['detected_section'] !== $expectedSection) $errors[] = 'Section mismatch. Expected ' . $expectedSection . ' but found ' . $meta['detected_section'] . '.';
    if ($meta['detected_subject_code'] !== '' && $expectedSubjectCode !== '' && $meta['detected_subject_code'] !== $expectedSubjectCode) $errors[] = 'Subject mismatch. Expected ' . $expectedSubjectCode . ' but found ' . $meta['detected_subject_code'] . '.';

    $headerIndex = -1;
    foreach ($matrix as $i => $row) {
        $h0 = strtolower(trim((string) ($row[0] ?? '')));
        $h1 = strtolower(trim((string) ($row[1] ?? '')));
        if ($h0 === '#' && strpos($h1, 'student') !== false) { $headerIndex = (int) $i; break; }
    }
    if ($headerIndex < 0) return [[], $errors, $meta];

    $header = $matrix[$headerIndex];
    $nameIdx = -1; $mtIdx = -1; $ftIdx = -1; $avgIdx = -1;
    foreach ($header as $idx => $cell) {
        $k = strtolower(trim((string) $cell));
        if ($k === 'name') $nameIdx = (int) $idx;
        if ($k === 'mt') $mtIdx = (int) $idx;
        if ($k === 'ft') $ftIdx = (int) $idx;
        if ($k === 'avg') $avgIdx = (int) $idx;
    }
    if ($nameIdx < 0) return [[], array_merge($errors, ['Name column was not found in pasted header.']), $meta];
    $gradeIdx = strtolower((string) $term) === 'final' ? $ftIdx : $mtIdx;

    for ($i = $headerIndex + 1; $i < count($matrix); $i++) {
        $row = $matrix[$i];
        $studentNo = trim((string) ($row[1] ?? ''));
        $name = trim((string) ($row[$nameIdx] ?? ''));
        if ($studentNo === '' && $name === '') continue;
        $raw = '';
        if ($gradeIdx >= 0) $raw = trim((string) ($row[$gradeIdx] ?? ''));
        if ($raw === '' && $avgIdx >= 0) $raw = trim((string) ($row[$avgIdx] ?? ''));
        if ($name === '') { $errors[] = 'Line ' . ($i + 1) . ': empty student name.'; continue; }
        $g = rcr_grade($raw);
        if ($g === null) { $errors[] = 'Line ' . ($i + 1) . ': invalid grade for ' . $name . '.'; continue; }
        $rows[] = ['row_no' => ($i + 1), 'name' => $name, 'grade' => $g];
    }

    if (count($rows) === 0 && count($errors) === 0) $errors[] = 'No usable student rows found in pasted block.';
    return [$rows, $errors, $meta];
}
function rcr_norm_weights(array $rows, $target = 100.0) {
    $sum = 0.0;
    foreach ($rows as $r) $sum += max(0.0, (float) ($r['component_weight'] ?? 0));
    if ($sum <= 0.0 || count($rows) === 0) return [];
    $out = []; $scaled = 0.0;
    foreach ($rows as $i => $r) { $out[$i] = round((((float) ($r['component_weight'] ?? 0)) / $sum) * $target, 2); $scaled += (float) $out[$i]; }
    $delta = round($target - $scaled, 2);
    if (abs($delta) >= 0.01) {
        $mx = 0; $mv = -1.0;
        foreach ($out as $i => $w) if ((float) $w > $mv) { $mv = (float) $w; $mx = (int) $i; }
        $out[$mx] = round(((float) $out[$mx]) + $delta, 2);
    }
    return $out;
}
function rcr_ensure_cfg(mysqli $conn, $subjectId, $course, $year, $section, $ay, $sem, $term, $teacherName) {
    $cfgId = 0;
    $q = $conn->prepare("SELECT id FROM section_grading_configs WHERE subject_id=? AND course=? AND year=? AND section=? AND academic_year=? AND semester=? AND term=? LIMIT 1");
    if ($q) {
        $q->bind_param('issssss', $subjectId, $course, $year, $section, $ay, $sem, $term);
        $q->execute(); $res = $q->get_result();
        if ($res && $res->num_rows === 1) $cfgId = (int) (($res->fetch_assoc()['id'] ?? 0));
        $q->close();
    }
    if ($cfgId <= 0) {
        $i = $conn->prepare("INSERT INTO section_grading_configs (subject_id, course, year, section, academic_year, semester, term, total_weight, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, 100.00, 1, ?)");
        if ($i) {
            $i->bind_param('isssssss', $subjectId, $course, $year, $section, $ay, $sem, $term, $teacherName);
            $i->execute(); $cfgId = (int) $conn->insert_id; $i->close();
        }
    }
    return $cfgId;
}
function rcr_source_components_from_class(mysqli $conn, $teacherId, $sourceClassRecordId, $sourceTerm, &$message) {
    $message = ''; $out = []; $source = null;
    $srcStmt = $conn->prepare(
        "SELECT cr.id AS class_record_id, cr.subject_id, cr.section, cr.academic_year, cr.semester, cr.year_level,
                s.course, s.subject_code, s.subject_name
         FROM teacher_assignments ta
         JOIN class_records cr ON cr.id = ta.class_record_id
         JOIN subjects s ON s.id = cr.subject_id
         WHERE ta.teacher_id = ? AND ta.status = 'active' AND cr.status = 'active' AND cr.id = ?
         LIMIT 1"
    );
    if ($srcStmt) {
        $srcStmt->bind_param('ii', $teacherId, $sourceClassRecordId);
        $srcStmt->execute(); $r = $srcStmt->get_result();
        if ($r && $r->num_rows === 1) $source = $r->fetch_assoc();
        $srcStmt->close();
    }
    if (!$source) { $message = 'Invalid source class.'; return []; }

    $srcSubjectId = (int) ($source['subject_id'] ?? 0);
    $srcSection = trim((string) ($source['section'] ?? ''));
    $srcAcademicYear = trim((string) ($source['academic_year'] ?? ''));
    $srcSemester = trim((string) ($source['semester'] ?? ''));
    $srcCourse = trim((string) ($source['course'] ?? '')); if ($srcCourse === '') $srcCourse = 'N/A';
    $srcYearLevel = trim((string) ($source['year_level'] ?? '')); if ($srcYearLevel === '') $srcYearLevel = 'N/A';

    $srcConfigId = 0;
    $cfg = $conn->prepare("SELECT id FROM section_grading_configs WHERE subject_id=? AND course=? AND year=? AND section=? AND academic_year=? AND semester=? AND term=? LIMIT 1");
    if ($cfg) {
        $cfg->bind_param('issssss', $srcSubjectId, $srcCourse, $srcYearLevel, $srcSection, $srcAcademicYear, $srcSemester, $sourceTerm);
        $cfg->execute(); $res = $cfg->get_result();
        if ($res && $res->num_rows === 1) $srcConfigId = (int) (($res->fetch_assoc()['id'] ?? 0));
        $cfg->close();
    }
    if ($srcConfigId <= 0) { $message = 'Source class has no components for selected source term.'; return []; }

    $q = $conn->prepare(
        "SELECT gc.id AS component_id,
                COALESCE(NULLIF(TRIM(c.category_name), ''), 'General') AS parameter_name,
                COALESCE(c.default_weight, 0.00) AS parameter_weight,
                gc.component_name, gc.component_code, gc.component_type, gc.weight AS component_weight, gc.display_order AS component_order
         FROM grading_components gc
         LEFT JOIN grading_categories c ON c.id = gc.category_id
         WHERE gc.section_config_id = ?
         ORDER BY gc.display_order ASC, gc.id ASC"
    );
    if ($q) {
        $q->bind_param('i', $srcConfigId);
        $q->execute(); $res = $q->get_result();
        while ($res && ($row = $res->fetch_assoc())) $out[] = $row;
        $q->close();
    }
    if (count($out) === 0) $message = 'No source components found.';
    return $out;
}
function rcr_match_rows_to_students(array $rows, array $students) {
    $lookup = [];
    foreach ($students as $s) {
        $sid = (int) ($s['student_id'] ?? 0);
        $sn = trim((string) ($s['student_no'] ?? ''));
        $ln = trim((string) ($s['surname'] ?? ''));
        $fn = trim((string) ($s['firstname'] ?? ''));
        $mn = trim((string) ($s['middlename'] ?? ''));
        $mi = $mn !== '' ? substr($mn, 0, 1) : '';
        rcr_add_map($lookup, rcr_key($ln . ', ' . $fn . ' ' . $mn), $sid);
        rcr_add_map($lookup, rcr_key($ln . ', ' . $fn), $sid);
        rcr_add_map($lookup, rcr_key($fn . ' ' . $mn . ' ' . $ln), $sid);
        rcr_add_map($lookup, rcr_key($fn . ' ' . $ln), $sid);
        if ($mi !== '') { rcr_add_map($lookup, rcr_key($ln . ', ' . $fn . ' ' . $mi), $sid); rcr_add_map($lookup, rcr_key($fn . ' ' . $mi . ' ' . $ln), $sid); }
        if ($sn !== '') rcr_add_map($lookup, rcr_key($sn), $sid);
    }

    $matched = []; $unmatched = 0; $ambiguous = 0; $clamped = 0; $duplicates = 0;
    foreach ($rows as $r) {
        $name = (string) ($r['name'] ?? '');
        $grade = (float) ($r['grade'] ?? 0);
        if ($grade < 0) { $grade = 0.0; $clamped++; } elseif ($grade > 100) { $grade = 100.0; $clamped++; }
        $ids = $lookup[rcr_key($name)] ?? [];
        $ids = is_array($ids) ? array_values(array_unique($ids)) : [];
        if (count($ids) === 1) {
            $sid = (int) ($ids[0] ?? 0);
            if (isset($matched[$sid])) $duplicates++;
            $matched[$sid] = round($grade, 2);
        } elseif (count($ids) > 1) $ambiguous++;
        else $unmatched++;
    }
    return ['matched' => $matched, 'unmatched' => $unmatched, 'ambiguous' => $ambiguous, 'clamped' => $clamped, 'duplicates' => $duplicates];
}
function rcr_generate_preview(array $context) {
    $class = (array) ($context['class'] ?? []);
    $term = (string) ($context['term'] ?? 'midterm');
    $replace = !empty($context['replace_existing']);
    $selectedRows = (array) ($context['selected_rows'] ?? []);
    $matched = (array) ($context['matched'] ?? []);
    $students = (array) ($context['students'] ?? []);
    $parseStats = (array) ($context['parse_stats'] ?? []);
    $ai = (array) ($context['ai'] ?? []);

    $globalCount = isset($ai['global_count']) ? (int) $ai['global_count'] : 3;
    if ($globalCount < 1) $globalCount = 1; if ($globalCount > 12) $globalCount = 12;
    $maxScore = isset($ai['max_score']) ? (float) $ai['max_score'] : 100.0;
    if ($maxScore < 1) $maxScore = 1; if ($maxScore > 1000) $maxScore = 1000;
    $variance = isset($ai['variance_pct']) ? (float) $ai['variance_pct'] : 6.0;
    if ($variance < 0) $variance = 0; if ($variance > 35) $variance = 35;
    $prefix = trim((string) ($ai['name_prefix'] ?? 'Reverse'));
    if ($prefix === '') $prefix = 'Reverse'; if (strlen($prefix) > 40) $prefix = substr($prefix, 0, 40);
    $keepProjectExamSingle = !empty($ai['keep_project_exam_single']);
    $componentCounts = (array) ($ai['component_counts'] ?? []);

    $seed = isset($context['seed']) ? (int) $context['seed'] : random_int(1, 2147483000);
    if ($seed <= 0) $seed = random_int(1, 2147483000);
    mt_srand($seed);

    $weights = rcr_norm_weights($selectedRows, 100.0);
    if (count($weights) !== count($selectedRows)) throw new RuntimeException('Unable to normalize component weights.');

    $components = []; $assessmentTotal = 0; $varianceScore = ($variance / 100.0) * $maxScore;
    foreach ($selectedRows as $i => $r) {
        $cid = (int) ($r['component_id'] ?? 0);
        $name = trim((string) ($r['component_name'] ?? 'Component')); if ($name === '') $name = 'Component';
        $type = trim((string) ($r['component_type'] ?? 'other'));

        $count = isset($componentCounts[$cid]) ? (int) $componentCounts[$cid] : $globalCount;
        if ($keepProjectExamSingle) {
            $n = strtolower($name); $t = strtolower($type);
            if ($t === 'project' || $t === 'exam' || strpos($n, 'project') !== false || strpos($n, 'exam') !== false) $count = 1;
        }
        if ($count < 1) $count = 1; if ($count > 12) $count = 12;

        $assessments = []; $running = [];
        for ($a = 1; $a <= $count; $a++) {
            $assName = $prefix . ' - ' . $name . ($count > 1 ? (' ' . $a) : '');
            if (strlen($assName) > 120) $assName = substr($assName, 0, 120);
            $scores = [];
            foreach ($matched as $sid => $targetGrade) {
                $sid = (int) $sid;
                $targetPct = (float) $targetGrade; if ($targetPct < 0) $targetPct = 0; if ($targetPct > 100) $targetPct = 100;
                $targetScore = ($targetPct / 100.0) * $maxScore;
                if (!isset($running[$sid])) $running[$sid] = 0.0;
                if ($a < $count) {
                    $raw = $targetScore + ((mt_rand() / mt_getrandmax()) * 2 * $varianceScore) - $varianceScore;
                    if ($raw < 0) $raw = 0; if ($raw > $maxScore) $raw = $maxScore;
                    $score = round($raw, 2);
                } else {
                    $need = ($targetScore * $count) - $running[$sid];
                    if ($need < 0) $need = 0; if ($need > $maxScore) $need = $maxScore;
                    $score = round($need, 2);
                }
                $running[$sid] += (float) $score;
                $scores[$sid] = $score;
            }
            $assessments[] = ['name' => $assName, 'max_score' => round($maxScore, 2), 'display_order' => $a, 'scores' => $scores];
            $assessmentTotal++;
        }

        $components[] = [
            'component_id' => $cid,
            'parameter_name' => trim((string) ($r['parameter_name'] ?? 'General')),
            'parameter_weight' => (float) ($r['parameter_weight'] ?? 0),
            'component_name' => $name,
            'component_code' => trim((string) ($r['component_code'] ?? '')),
            'component_type' => $type,
            'component_weight' => (float) ($weights[$i] ?? 0),
            'component_order' => (int) ($r['component_order'] ?? ($i + 1)),
            'assessment_count' => $count,
            'assessments' => $assessments,
        ];
    }

    $byStudent = [];
    foreach ($students as $s) { $sid = (int) ($s['student_id'] ?? 0); if ($sid > 0) $byStudent[$sid] = $s; }
    $studentPreview = [];
    foreach ($matched as $sid => $targetGrade) {
        $sid = (int) $sid; $pred = 0.0;
        foreach ($components as $comp) {
            $w = (float) ($comp['component_weight'] ?? 0); $sumPct = 0.0; $cnt = 0;
            foreach ((array) ($comp['assessments'] ?? []) as $ass) {
                $max = max(1.0, (float) ($ass['max_score'] ?? 100));
                if (!isset($ass['scores'][$sid])) continue;
                $sumPct += ((float) $ass['scores'][$sid] / $max) * 100.0;
                $cnt++;
            }
            if ($cnt > 0) $pred += (($sumPct / $cnt) * $w) / 100.0;
        }
        $st = (array) ($byStudent[$sid] ?? []);
        $studentPreview[] = ['student_id' => $sid, 'student_no' => trim((string) ($st['student_no'] ?? '')), 'name' => trim((string) ($st['surname'] ?? '') . ', ' . (string) ($st['firstname'] ?? '') . ' ' . (string) ($st['middlename'] ?? '')), 'target' => round((float) $targetGrade, 2), 'predicted' => round($pred, 2)];
    }
    usort($studentPreview, function ($a, $b) { return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')); });

    return ['class_record_id' => (int) ($class['class_record_id'] ?? 0), 'class' => $class, 'term' => $term, 'replace_existing' => $replace ? 1 : 0, 'seed' => $seed, 'components' => $components, 'assessment_total' => $assessmentTotal, 'matched' => $matched, 'students' => $students, 'student_preview' => $studentPreview, 'parse_stats' => $parseStats, 'ai' => ['global_count' => $globalCount, 'max_score' => round($maxScore, 2), 'variance_pct' => round($variance, 2), 'name_prefix' => $prefix, 'keep_project_exam_single' => $keepProjectExamSingle ? 1 : 0, 'component_counts' => $componentCounts], 'created_at' => date('Y-m-d H:i:s')];
}
function rcr_apply_preview(mysqli $conn, array $preview, $teacherId, $teacherName) {
    $class = (array) ($preview['class'] ?? []);
    $components = (array) ($preview['components'] ?? []);
    $matched = (array) ($preview['matched'] ?? []);
    if (count($components) === 0) throw new RuntimeException('No components to apply.');
    if (count($matched) === 0) throw new RuntimeException('No matched students to apply.');

    $subjectId = (int) ($class['subject_id'] ?? 0);
    $section = trim((string) ($class['section'] ?? ''));
    $academicYear = trim((string) ($class['academic_year'] ?? ''));
    $semester = trim((string) ($class['semester'] ?? ''));
    $course = trim((string) ($class['course'] ?? '')); if ($course === '') $course = 'N/A';
    $yearLevel = trim((string) ($class['year_level'] ?? '')); if ($yearLevel === '') $yearLevel = 'N/A';
    $term = trim((string) ($preview['term'] ?? 'midterm'));
    $replace = !empty($preview['replace_existing']);

    $cfgId = rcr_ensure_cfg($conn, $subjectId, $course, $yearLevel, $section, $academicYear, $semester, $term, $teacherName);
    if ($cfgId <= 0) throw new RuntimeException('Unable to prepare grading config.');

    $existing = 0;
    $c = $conn->prepare("SELECT COUNT(*) AS c FROM grading_components WHERE section_config_id = ?");
    if ($c) {
        $c->bind_param('i', $cfgId); $c->execute(); $res = $c->get_result();
        if ($res && $res->num_rows === 1) $existing = (int) (($res->fetch_assoc()['c'] ?? 0));
        $c->close();
    }
    if ($existing > 0 && !$replace) throw new RuntimeException('This term already has components. Enable replace option.');

    $conn->begin_transaction();
    try {
        $u = $conn->prepare("UPDATE section_grading_configs SET total_weight = 100.00 WHERE id = ?");
        if ($u) { $u->bind_param('i', $cfgId); $u->execute(); $u->close(); }
        if ($replace) {
            $d = $conn->prepare("DELETE FROM grading_components WHERE section_config_id = ?");
            if ($d) { $d->bind_param('i', $cfgId); $d->execute(); $d->close(); }
        }

        $findCat = $conn->prepare("SELECT id FROM grading_categories WHERE subject_id = ? AND category_name = ? LIMIT 1");
        $insCat = $conn->prepare("INSERT INTO grading_categories (category_name, subject_id, default_weight, is_active, created_by) VALUES (?, ?, ?, 1, ?)");
        $insComp = $conn->prepare("INSERT INTO grading_components (subject_id, section_config_id, academic_year, semester, course, year, section, category_id, component_name, component_code, component_type, weight, is_active, display_order, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)");
        $insAss = $conn->prepare("INSERT INTO grading_assessments (grading_component_id, name, max_score, assessment_date, module_type, is_active, display_order, created_by) VALUES (?, ?, ?, ?, 'assessment', 1, ?, ?)");
        $upScore = $conn->prepare("INSERT INTO grading_assessment_scores (assessment_id, student_id, score, recorded_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE score=VALUES(score), recorded_by=VALUES(recorded_by), updated_at=CURRENT_TIMESTAMP");
        if (!$findCat || !$insCat || !$insComp || !$insAss || !$upScore) throw new RuntimeException('Unable to prepare statements.');

        $catCache = []; $componentCount = 0; $assessmentCount = 0; $writes = 0; $assDate = date('Y-m-d');
        foreach ($components as $i => $comp) {
            $cat = trim((string) ($comp['parameter_name'] ?? 'General')); if ($cat === '') $cat = 'General'; if (strlen($cat) > 100) $cat = substr($cat, 0, 100);
            if (!isset($catCache[$cat])) {
                $catId = 0;
                $findCat->bind_param('is', $subjectId, $cat); $findCat->execute(); $rr = $findCat->get_result();
                if ($rr && $rr->num_rows === 1) $catId = (int) (($rr->fetch_assoc()['id'] ?? 0));
                else {
                    $pw = (float) ($comp['parameter_weight'] ?? 0);
                    $insCat->bind_param('sids', $cat, $subjectId, $pw, $teacherName);
                    $insCat->execute(); $catId = (int) $conn->insert_id;
                }
                if ($catId <= 0) throw new RuntimeException('Category resolve failed.');
                $catCache[$cat] = $catId;
            }
            $catId = (int) $catCache[$cat];

            $name = trim((string) ($comp['component_name'] ?? '')); if ($name === '') continue; if (strlen($name) > 100) $name = substr($name, 0, 100);
            $code = trim((string) ($comp['component_code'] ?? '')); if (strlen($code) > 50) $code = substr($code, 0, 50);
            $type = trim((string) ($comp['component_type'] ?? 'other')); if (!in_array($type, rcr_allowed_types(), true)) $type = 'other';
            $w = (float) ($comp['component_weight'] ?? 0);
            $ord = (int) ($comp['component_order'] ?? ($i + 1)); if ($ord <= 0) $ord = $i + 1;

            $insComp->bind_param('iisssssisssdis', $subjectId, $cfgId, $academicYear, $semester, $course, $yearLevel, $section, $catId, $name, $code, $type, $w, $ord, $teacherName);
            $insComp->execute(); $newCompId = (int) $conn->insert_id; if ($newCompId <= 0) throw new RuntimeException('Component insert failed.');
            $componentCount++;

            foreach ((array) ($comp['assessments'] ?? []) as $aIdx => $ass) {
                $assName = trim((string) ($ass['name'] ?? 'Assessment')); if ($assName === '') $assName = 'Assessment'; if (strlen($assName) > 120) $assName = substr($assName, 0, 120);
                $maxScore = (float) ($ass['max_score'] ?? 100.0); if ($maxScore < 1) $maxScore = 1; if ($maxScore > 1000) $maxScore = 1000;
                $aOrd = (int) ($ass['display_order'] ?? ($aIdx + 1)); if ($aOrd <= 0) $aOrd = $aIdx + 1;
                $insAss->bind_param('isdsii', $newCompId, $assName, $maxScore, $assDate, $aOrd, $teacherId);
                $insAss->execute(); $newAssId = (int) $conn->insert_id; if ($newAssId <= 0) throw new RuntimeException('Assessment insert failed.');
                $assessmentCount++;

                foreach ((array) ($ass['scores'] ?? []) as $sidRaw => $scoreRaw) {
                    $sid = (int) $sidRaw; if ($sid <= 0) continue;
                    $score = round((float) $scoreRaw, 2); if ($score < 0) $score = 0; if ($score > $maxScore) $score = $maxScore;
                    $upScore->bind_param('iidi', $newAssId, $sid, $score, $teacherId);
                    $upScore->execute(); $writes++;
                }
            }
        }

        $conn->commit();
        return ['matched' => count($matched), 'components' => $componentCount, 'assessments' => $assessmentCount, 'writes' => $writes];
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}
$flash = isset($_SESSION['flash_message']) ? (string) $_SESSION['flash_message'] : '';
$flashType = isset($_SESSION['flash_type']) ? (string) $_SESSION['flash_type'] : 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

$teacherId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$teacherName = trim((string) ($_SESSION['user_name'] ?? ''));
if ($teacherName === '') $teacherName = (string) $teacherId;

if (!reverse_class_record_can_teacher_use($conn, $teacherId)) {
    $globalEnabled = reverse_class_record_is_enabled($conn);
    $teacherEnabled = reverse_class_record_teacher_is_enabled($conn, $teacherId, true);
    if (!$globalEnabled) $_SESSION['flash_message'] = 'Reverse Class Record is currently disabled by superadmin.';
    elseif (!$teacherEnabled) $_SESSION['flash_message'] = 'Reverse Class Record is currently disabled for your account by admin.';
    else $_SESSION['flash_message'] = 'Reverse Class Record is currently unavailable.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: teacher-dashboard.php');
    exit;
}

$creditCost = reverse_class_record_get_credit_cost($conn);
[$okCreditStatus, $creditStatusOrMsg] = ai_credit_get_user_status($conn, $teacherId);
$creditInfo = $okCreditStatus ? (array) $creditStatusOrMsg : null;

$assigned = [];
$stmt = $conn->prepare(
    "SELECT cr.id AS class_record_id, cr.subject_id, cr.section, cr.academic_year, cr.semester,
            COALESCE(NULLIF(TRIM(cr.year_level), ''), 'N/A') AS year_level,
            COALESCE(NULLIF(TRIM(s.course), ''), 'N/A') AS course,
            s.subject_code, s.subject_name, COALESCE(NULLIF(TRIM(s.type), ''), 'Lecture') AS subject_type
     FROM teacher_assignments ta
     JOIN class_records cr ON cr.id = ta.class_record_id
     JOIN subjects s ON s.id = cr.subject_id
     WHERE ta.teacher_id = ? AND ta.status='active' AND cr.status='active'
     ORDER BY cr.academic_year DESC, cr.semester ASC, s.subject_name ASC, cr.section ASC"
);
if ($stmt) {
    $stmt->bind_param('i', $teacherId);
    $stmt->execute(); $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) $assigned[] = $row;
    $stmt->close();
}

$classById = [];
$rcrFilterRows = [];
$optAy = []; $optSem = []; $optType = []; $optSubject = []; $optSection = [];
foreach ($assigned as $a) {
    $cid = (int) ($a['class_record_id'] ?? 0); if ($cid <= 0) continue;
    $classById[$cid] = $a;
    $ay = trim((string) ($a['academic_year'] ?? '')); if ($ay !== '') $optAy[$ay] = true;
    $sem = trim((string) ($a['semester'] ?? '')); if ($sem !== '') $optSem[$sem] = true;
    $tp = trim((string) ($a['subject_type'] ?? 'Lecture')); if ($tp !== '') $optType[$tp] = true;
    $subId = (int) ($a['subject_id'] ?? 0);
    $subCode = trim((string) ($a['subject_code'] ?? ''));
    $subName = trim((string) ($a['subject_name'] ?? ''));
    if ($subId > 0) $optSubject[$subId] = ['subject_id' => $subId, 'subject_code' => $subCode, 'subject_name' => $subName, 'subject_type' => $tp];
    $sec = trim((string) ($a['section'] ?? '')); if ($sec !== '') $optSection[$sec] = true;
    $subjectLabel = trim($subCode . ($subCode !== '' && $subName !== '' ? ' - ' : '') . $subName);
    if ($subjectLabel === '') $subjectLabel = 'Subject #' . $subId;
    $classLabel = $subjectLabel;
    if ($sec !== '') $classLabel .= ' | ' . $sec;
    $classTermLabel = trim($ay . ', ' . $sem, ' ,');
    if ($classTermLabel !== '') $classLabel .= ' | ' . $classTermLabel;
    $rcrFilterRows[] = [
        'class_record_id' => $cid,
        'academic_year' => $ay,
        'semester' => $sem,
        'subject_type' => $tp,
        'subject_id' => $subId,
        'section' => $sec,
        'subject_label' => $subjectLabel,
        'class_label' => $classLabel,
    ];
}
$optAy = array_keys($optAy); sort($optAy);
$optSem = array_keys($optSem); sort($optSem);
$optType = array_keys($optType); sort($optType);
$optSection = array_keys($optSection); sort($optSection);
ksort($optSubject);

$first = count($assigned) > 0 ? $assigned[0] : null;
$selectedClassRecordId = isset($_REQUEST['class_record_id']) ? (int) $_REQUEST['class_record_id'] : 0;
if ($selectedClassRecordId <= 0 && is_array($first)) $selectedClassRecordId = (int) ($first['class_record_id'] ?? 0);
if ($selectedClassRecordId > 0 && !isset($classById[$selectedClassRecordId])) $selectedClassRecordId = 0;

$selectedAcademicYear = trim((string) ($_REQUEST['academic_year'] ?? ''));
$selectedSemester = trim((string) ($_REQUEST['semester'] ?? ''));
$selectedSubjectType = trim((string) ($_REQUEST['subject_type'] ?? ''));
$selectedSubjectId = isset($_REQUEST['subject_id']) ? (int) $_REQUEST['subject_id'] : 0;
$selectedSection = trim((string) ($_REQUEST['section'] ?? ''));

if ($selectedClassRecordId > 0 && isset($classById[$selectedClassRecordId])) {
    $picked = $classById[$selectedClassRecordId];
    if ($selectedAcademicYear === '') $selectedAcademicYear = trim((string) ($picked['academic_year'] ?? ''));
    if ($selectedSemester === '') $selectedSemester = trim((string) ($picked['semester'] ?? ''));
    if ($selectedSubjectType === '') $selectedSubjectType = trim((string) ($picked['subject_type'] ?? ''));
    if ($selectedSubjectId <= 0) $selectedSubjectId = (int) ($picked['subject_id'] ?? 0);
    if ($selectedSection === '') $selectedSection = trim((string) ($picked['section'] ?? ''));
}

$filteredIds = [];
foreach ($assigned as $a) {
    $cid = (int) ($a['class_record_id'] ?? 0); if ($cid <= 0) continue;
    $ok = true;
    if ($selectedAcademicYear !== '' && trim((string) ($a['academic_year'] ?? '')) !== $selectedAcademicYear) $ok = false;
    if ($ok && $selectedSemester !== '' && trim((string) ($a['semester'] ?? '')) !== $selectedSemester) $ok = false;
    if ($ok && $selectedSubjectType !== '' && trim((string) ($a['subject_type'] ?? '')) !== $selectedSubjectType) $ok = false;
    if ($ok && $selectedSubjectId > 0 && (int) ($a['subject_id'] ?? 0) !== $selectedSubjectId) $ok = false;
    if ($ok && $selectedSection !== '' && trim((string) ($a['section'] ?? '')) !== $selectedSection) $ok = false;
    if ($ok) $filteredIds[] = $cid;
}
if ($selectedClassRecordId <= 0 && count($filteredIds) > 0) $selectedClassRecordId = (int) $filteredIds[0];
if ($selectedClassRecordId > 0 && !in_array($selectedClassRecordId, $filteredIds, true) && count($filteredIds) > 0) $selectedClassRecordId = (int) $filteredIds[0];
$selectedClass = ($selectedClassRecordId > 0 && isset($classById[$selectedClassRecordId])) ? $classById[$selectedClassRecordId] : null;
if (is_array($selectedClass)) {
    $selectedAcademicYear = trim((string) ($selectedClass['academic_year'] ?? ''));
    $selectedSemester = trim((string) ($selectedClass['semester'] ?? ''));
    $selectedSubjectType = trim((string) ($selectedClass['subject_type'] ?? ''));
    $selectedSubjectId = (int) ($selectedClass['subject_id'] ?? 0);
    $selectedSection = trim((string) ($selectedClass['section'] ?? ''));
}

$term = isset($_REQUEST['term']) ? strtolower(trim((string) $_REQUEST['term'])) : 'midterm';
if (!in_array($term, ['midterm', 'final'], true)) $term = 'midterm';
$termLabel = $term === 'final' ? 'Final Term' : 'Midterm';

$sourceMode = trim((string) ($_REQUEST['source_mode'] ?? 'build'));
if (!in_array($sourceMode, ['build', 'class_copy'], true)) $sourceMode = 'build';
$sourceTerm = isset($_REQUEST['source_term']) ? strtolower(trim((string) $_REQUEST['source_term'])) : $term;
if (!in_array($sourceTerm, ['midterm', 'final'], true)) $sourceTerm = $term;

$builds = [];
$b = $conn->prepare("SELECT id, name FROM class_record_builds WHERE teacher_id = ? AND status = 'active' ORDER BY name ASC");
if ($b) { $b->bind_param('i', $teacherId); $b->execute(); $res = $b->get_result(); while ($res && ($r = $res->fetch_assoc())) $builds[] = $r; $b->close(); }
$buildAllowed = []; foreach ($builds as $x) $buildAllowed[(int) ($x['id'] ?? 0)] = (string) ($x['name'] ?? '');
$selectedBuildId = isset($_REQUEST['build_id']) ? (int) $_REQUEST['build_id'] : 0;
if ($selectedBuildId <= 0 && count($builds) > 0) $selectedBuildId = (int) ($builds[0]['id'] ?? 0);
if ($selectedBuildId > 0 && !isset($buildAllowed[$selectedBuildId])) $selectedBuildId = count($builds) > 0 ? (int) ($builds[0]['id'] ?? 0) : 0;

$sourceClassRecordId = isset($_REQUEST['source_class_record_id']) ? (int) $_REQUEST['source_class_record_id'] : 0;
if ($sourceClassRecordId <= 0 && count($assigned) > 0) foreach ($assigned as $a) { $cid = (int) ($a['class_record_id'] ?? 0); if ($cid > 0 && $cid !== $selectedClassRecordId) { $sourceClassRecordId = $cid; break; } }
if ($sourceClassRecordId > 0 && !isset($classById[$sourceClassRecordId])) $sourceClassRecordId = 0;

$sourceMessage = ''; $sourceComponents = [];
if ($sourceMode === 'build') {
    if ($selectedBuildId > 0) {
        $q = $conn->prepare(
            "SELECT p.name AS parameter_name, p.weight AS parameter_weight,
                    c.id AS component_id, c.name AS component_name, c.code AS component_code, c.component_type, c.weight AS component_weight, c.display_order AS component_order
             FROM class_record_build_parameters p
             JOIN class_record_build_components c ON c.parameter_id = p.id
             WHERE p.build_id = ? AND p.term = ?
             ORDER BY p.display_order ASC, p.id ASC, c.display_order ASC, c.id ASC"
        );
        if ($q) { $q->bind_param('is', $selectedBuildId, $term); $q->execute(); $res = $q->get_result(); while ($res && ($r = $res->fetch_assoc())) $sourceComponents[] = $r; $q->close(); }
    }
} else {
    if ($sourceClassRecordId > 0) $sourceComponents = rcr_source_components_from_class($conn, $teacherId, $sourceClassRecordId, $sourceTerm, $sourceMessage);
    else $sourceMessage = 'Select a source class.';
}

$students = [];
if (is_array($selectedClass)) {
    $classRecordId = (int) ($selectedClass['class_record_id'] ?? 0);
    $e = $conn->prepare(
        "SELECT ce.student_id, st.StudentNo AS student_no, st.Surname AS surname, st.FirstName AS firstname, st.MiddleName AS middlename
         FROM class_enrollments ce
         JOIN students st ON st.id = ce.student_id
         WHERE ce.class_record_id = ? AND ce.status = 'enrolled'
         ORDER BY st.Surname ASC, st.FirstName ASC, st.MiddleName ASC, st.StudentNo ASC"
    );
    if ($e) { $e->bind_param('i', $classRecordId); $e->execute(); $res = $e->get_result(); while ($res && ($r = $res->fetch_assoc())) $students[] = $r; $e->close(); }
}

$selectedComponentIdsUi = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') foreach ((array) ($_POST['component_ids'] ?? []) as $raw) { $cid = (int) $raw; if ($cid > 0) $selectedComponentIdsUi[$cid] = true; }
if (count($selectedComponentIdsUi) === 0) foreach ($sourceComponents as $r) { $cid = (int) ($r['component_id'] ?? 0); if ($cid > 0) $selectedComponentIdsUi[$cid] = true; }

$preview = null;
$sessionPreview = $_SESSION[RCR_PREVIEW_SESSION_KEY] ?? null;
if (is_array($sessionPreview) && (int) ($sessionPreview['class_record_id'] ?? 0) === $selectedClassRecordId) $preview = $sessionPreview;

$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    $csrf = (string) ($_POST['csrf_token'] ?? '');

    if (!csrf_validate($csrf)) {
        $flash = 'Security check failed (CSRF).';
        $flashType = 'danger';
    } elseif ($action === 'generate_preview') {
        if (!is_array($selectedClass)) { $flash = 'Select a valid class context first.'; $flashType = 'danger'; }
        elseif (count($sourceComponents) === 0) { $flash = 'Selected source has no components.'; $flashType = 'warning'; }
        elseif (count($students) === 0) { $flash = 'No enrolled students found for selected class.'; $flashType = 'warning'; }
        else {
            $selectedSet = [];
            foreach ((array) ($_POST['component_ids'] ?? []) as $raw) { $cid = (int) $raw; if ($cid > 0) $selectedSet[$cid] = true; }
            $selectedRows = [];
            foreach ($sourceComponents as $r) { $cid = (int) ($r['component_id'] ?? 0); if ($cid > 0 && isset($selectedSet[$cid])) $selectedRows[] = $r; }
            if (count($selectedRows) === 0) { $flash = 'Select at least one component.'; $flashType = 'warning'; }
            else {
                $gradeInputMode = trim((string) ($_POST['grade_input_mode'] ?? 'paste'));
                if (!in_array($gradeInputMode, ['csv', 'paste'], true)) $gradeInputMode = 'paste';

                $inputRows = []; $parseErrors = []; $parseMeta = ['mode' => $gradeInputMode];
                if ($gradeInputMode === 'csv') {
                    $csv = $_FILES['grades_csv'] ?? null;
                    $err = is_array($csv) ? (int) ($csv['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
                    $tmp = is_array($csv) ? (string) ($csv['tmp_name'] ?? '') : '';
                    $ext = strtolower(pathinfo((string) (is_array($csv) ? ($csv['name'] ?? '') : ''), PATHINFO_EXTENSION));
                    if ($err !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) $parseErrors[] = 'Upload a valid CSV file.';
                    elseif ($ext !== 'csv') $parseErrors[] = 'File must be .csv.';
                    else [$inputRows, $parseErrors, $parseMeta] = rcr_parse_csv_upload($tmp);
                } else {
                    $paste = trim((string) ($_POST['grades_paste'] ?? ''));
                    if ($paste === '') $parseErrors[] = 'Paste content is required.';
                    else {
                        [$rowsExcel, $errsExcel, $metaExcel] = rcr_parse_excel_paste($paste, $term, (string) ($selectedClass['section'] ?? ''), (string) ($selectedClass['subject_code'] ?? ''));
                        if (count($rowsExcel) > 0) { $inputRows = $rowsExcel; $parseErrors = $errsExcel; $parseMeta = $metaExcel; }
                        else { [$rowsSimple, $errsSimple, $metaSimple] = rcr_parse_simple_paste($paste); $inputRows = $rowsSimple; $parseErrors = array_merge($errsExcel, $errsSimple); $parseMeta = $metaSimple; }
                    }
                }

                if (count($inputRows) === 0) {
                    $flash = count($parseErrors) > 0 ? implode(' ', array_slice($parseErrors, 0, 3)) : 'No usable input rows found.';
                    $flashType = 'danger';
                } else {
                    $matchStats = rcr_match_rows_to_students($inputRows, $students);
                    $matched = (array) ($matchStats['matched'] ?? []);
                    if (count($matched) === 0) { $flash = 'No input names matched enrolled students.'; $flashType = 'danger'; }
                    else {
                        $aiGlobal = isset($_POST['ai_global_count']) ? (int) $_POST['ai_global_count'] : 3;
                        if ($aiGlobal < 1) $aiGlobal = 1; if ($aiGlobal > 12) $aiGlobal = 12;
                        $aiMax = isset($_POST['ai_max_score']) ? (float) $_POST['ai_max_score'] : 100.0;
                        if ($aiMax < 1) $aiMax = 1; if ($aiMax > 1000) $aiMax = 1000;
                        $aiVar = isset($_POST['ai_variance_pct']) ? (float) $_POST['ai_variance_pct'] : 6.0;
                        if ($aiVar < 0) $aiVar = 0; if ($aiVar > 35) $aiVar = 35;
                        $aiPrefix = trim((string) ($_POST['ai_name_prefix'] ?? 'Reverse'));
                        if ($aiPrefix === '') $aiPrefix = 'Reverse'; if (strlen($aiPrefix) > 40) $aiPrefix = substr($aiPrefix, 0, 40);
                        $aiKeepSingle = isset($_POST['ai_keep_project_exam_single']) && (string) $_POST['ai_keep_project_exam_single'] === '1';
                        $aiCounts = [];
                        foreach ((array) ($_POST['component_count'] ?? []) as $k => $v) {
                            $cid = (int) $k; $cnt = (int) $v; if ($cid <= 0) continue;
                            if ($cnt < 1) $cnt = 1; if ($cnt > 12) $cnt = 12;
                            $aiCounts[$cid] = $cnt;
                        }

                        [$okConsume, $consumeMsg] = ai_credit_try_consume_count($conn, $teacherId, $creditCost);
                        if (!$okConsume) {
                            $flash = is_string($consumeMsg) ? $consumeMsg : 'Not enough AI credits.';
                            $flashType = 'danger';
                        } else {
                            try {
                                $payload = rcr_generate_preview([
                                    'class' => $selectedClass,
                                    'term' => $term,
                                    'replace_existing' => isset($_POST['replace_existing']) && (string) $_POST['replace_existing'] === '1',
                                    'selected_rows' => $selectedRows,
                                    'matched' => $matched,
                                    'students' => $students,
                                    'seed' => random_int(1, 2147483000),
                                    'parse_stats' => [
                                        'input_mode' => $gradeInputMode,
                                        'parse_mode' => (string) ($parseMeta['mode'] ?? $gradeInputMode),
                                        'csv_errors' => count($parseErrors),
                                        'input_rows' => count($inputRows),
                                        'matched' => count($matched),
                                        'unmatched' => (int) ($matchStats['unmatched'] ?? 0),
                                        'ambiguous' => (int) ($matchStats['ambiguous'] ?? 0),
                                        'duplicates' => (int) ($matchStats['duplicates'] ?? 0),
                                        'clamped' => (int) ($matchStats['clamped'] ?? 0),
                                        'detected_section' => (string) ($parseMeta['detected_section'] ?? ''),
                                        'detected_subject_code' => (string) ($parseMeta['detected_subject_code'] ?? ''),
                                    ],
                                    'ai' => [
                                        'global_count' => $aiGlobal,
                                        'max_score' => $aiMax,
                                        'variance_pct' => $aiVar,
                                        'name_prefix' => $aiPrefix,
                                        'keep_project_exam_single' => $aiKeepSingle ? 1 : 0,
                                        'component_counts' => $aiCounts,
                                    ],
                                ]);
                                $_SESSION[RCR_PREVIEW_SESSION_KEY] = $payload;
                                $preview = $payload;
                                $flash = 'Composition generated. Review and regenerate if needed, then apply when satisfied.';
                                $flashType = 'success';
                                [$okAfter, $after] = ai_credit_get_user_status($conn, $teacherId);
                                if ($okAfter) $creditInfo = (array) $after;
                            } catch (Throwable $e) {
                                ai_credit_refund($conn, $teacherId, $creditCost);
                                error_log('[teacher-reverse-class-record] preview generation failed: ' . $e->getMessage());
                                $flash = 'Generation failed: ' . $e->getMessage();
                                $flashType = 'danger';
                            }
                        }
                    }
                }
            }
        }
    } elseif ($action === 'regenerate_preview') {
        $live = $_SESSION[RCR_PREVIEW_SESSION_KEY] ?? null;
        if (!is_array($live)) { $flash = 'No generated preview found.'; $flashType = 'warning'; }
        elseif ((int) ($live['class_record_id'] ?? 0) !== $selectedClassRecordId) { $flash = 'Preview context mismatch. Generate again.'; $flashType = 'warning'; }
        else {
            [$okConsume, $consumeMsg] = ai_credit_try_consume_count($conn, $teacherId, $creditCost);
            if (!$okConsume) { $flash = is_string($consumeMsg) ? $consumeMsg : 'Not enough AI credits.'; $flashType = 'danger'; }
            else {
                try {
                    $reRows = [];
                    foreach ((array) ($live['components'] ?? []) as $comp) {
                        $reRows[] = ['component_id' => (int) ($comp['component_id'] ?? 0), 'parameter_name' => (string) ($comp['parameter_name'] ?? 'General'), 'parameter_weight' => (float) ($comp['parameter_weight'] ?? 0), 'component_name' => (string) ($comp['component_name'] ?? ''), 'component_code' => (string) ($comp['component_code'] ?? ''), 'component_type' => (string) ($comp['component_type'] ?? 'other'), 'component_weight' => (float) ($comp['component_weight'] ?? 0), 'component_order' => (int) ($comp['component_order'] ?? 0)];
                    }
                    $payload = rcr_generate_preview(['class' => (array) ($live['class'] ?? []), 'term' => (string) ($live['term'] ?? $term), 'replace_existing' => !empty($live['replace_existing']), 'selected_rows' => $reRows, 'matched' => (array) ($live['matched'] ?? []), 'students' => (array) ($live['students'] ?? []), 'seed' => random_int(1, 2147483000), 'parse_stats' => (array) ($live['parse_stats'] ?? []), 'ai' => (array) ($live['ai'] ?? [])]);
                    $_SESSION[RCR_PREVIEW_SESSION_KEY] = $payload;
                    $preview = $payload;
                    $flash = 'Regenerated successfully.';
                    $flashType = 'success';
                    [$okAfter, $after] = ai_credit_get_user_status($conn, $teacherId);
                    if ($okAfter) $creditInfo = (array) $after;
                } catch (Throwable $e) {
                    ai_credit_refund($conn, $teacherId, $creditCost);
                    error_log('[teacher-reverse-class-record] regenerate failed: ' . $e->getMessage());
                    $flash = 'Regenerate failed: ' . $e->getMessage();
                    $flashType = 'danger';
                }
            }
        }
    } elseif ($action === 'apply_preview') {
        $live = $_SESSION[RCR_PREVIEW_SESSION_KEY] ?? null;
        if (!is_array($live)) { $flash = 'No generated preview found.'; $flashType = 'warning'; }
        elseif ((int) ($live['class_record_id'] ?? 0) !== $selectedClassRecordId) { $flash = 'Preview context mismatch. Generate again.'; $flashType = 'warning'; }
        elseif (!isset($classById[$selectedClassRecordId])) { $flash = 'Class assignment no longer valid.'; $flashType = 'danger'; }
        else {
            try {
                $result = rcr_apply_preview($conn, $live, $teacherId, $teacherName);
                unset($_SESSION[RCR_PREVIEW_SESSION_KEY]);
                $preview = null;
                $flash = 'Reverse Class Record applied successfully.';
                $flashType = 'success';
            } catch (Throwable $e) {
                error_log('[teacher-reverse-class-record] apply failed: ' . $e->getMessage());
                $flash = 'Apply failed: ' . $e->getMessage();
                $flashType = 'danger';
            }
        }
    }
}

$defaultGlobalCount = is_array($preview) ? (int) (($preview['ai']['global_count'] ?? 3)) : 3;
if ($defaultGlobalCount < 1) $defaultGlobalCount = 1; if ($defaultGlobalCount > 12) $defaultGlobalCount = 12;
$defaultMaxScore = is_array($preview) ? (float) (($preview['ai']['max_score'] ?? 100.0)) : 100.0;
if ($defaultMaxScore < 1) $defaultMaxScore = 1; if ($defaultMaxScore > 1000) $defaultMaxScore = 1000;
$defaultVariance = is_array($preview) ? (float) (($preview['ai']['variance_pct'] ?? 6.0)) : 6.0;
if ($defaultVariance < 0) $defaultVariance = 0; if ($defaultVariance > 35) $defaultVariance = 35;
$defaultNamePrefix = is_array($preview) ? trim((string) (($preview['ai']['name_prefix'] ?? 'Reverse'))) : 'Reverse';
if ($defaultNamePrefix === '') $defaultNamePrefix = 'Reverse';
$defaultKeepProjectExamSingle = is_array($preview) ? !empty($preview['ai']['keep_project_exam_single']) : false;
$componentCountsPreview = is_array($preview) ? (array) ($preview['ai']['component_counts'] ?? []) : [];
$replaceChecked = is_array($preview) ? !empty($preview['replace_existing']) : true;
?>
<head>
    <title>Reverse Class Record | E-Record</title>
    <?php include '../layouts/title-meta.php'; ?>
    <?php include '../layouts/head-css.php'; ?>
    <style>
        .rcr-hero { border-radius: 14px; padding: 16px 18px; background: linear-gradient(135deg, #1e293b 0%, #0f4c81 56%, #0f766e 100%); color: #fff; }
        .rcr-list { max-height: 320px; overflow: auto; border: 1px solid #e5e7eb; border-radius: 10px; padding: 10px; background: #fff; }
        .rcr-chip { display: inline-flex; align-items: center; gap: 6px; border-radius: 999px; padding: 6px 10px; background: rgba(13, 110, 253, 0.12); color: #0d6efd; font-weight: 600; font-size: 12px; }
        .rcr-hierarchy-note {
            border: 1px dashed #bfd5ff;
            background: #f4f8ff;
            color: #244a8f;
            border-radius: 10px;
            padding: 8px 10px;
            font-size: 12px;
            font-weight: 600;
        }
        .rcr-step-label {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 20px;
            height: 20px;
            border-radius: 999px;
            background: #0d6efd;
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            margin-right: 6px;
        }
    </style>
</head>
<body>
<div class="wrapper">
    <?php include '../layouts/menu.php'; ?>
    <div class="content-page"><div class="content"><div class="container-fluid">
        <div class="row"><div class="col-12"><div class="page-title-box">
            <div class="page-title-right"><ol class="breadcrumb m-0"><li class="breadcrumb-item"><a href="teacher-dashboard.php">Teacher</a></li><li class="breadcrumb-item"><a href="teacher-my-classes.php">My Classes</a></li><li class="breadcrumb-item active">Reverse Class Record</li></ol></div>
            <h4 class="page-title">Reverse Class Record</h4>
        </div></div></div>

        <?php if ($flash !== ''): ?><div class="alert alert-<?php echo rcr_h($flashType); ?>"><?php echo rcr_h($flash); ?></div><?php endif; ?>
        <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
            <span class="rcr-chip"><i class="ri-coin-line" aria-hidden="true"></i> Cost per generation: <?php echo number_format((float) $creditCost, 2, '.', ''); ?> credits</span>
            <span class="rcr-chip"><i class="ri-battery-charge-line" aria-hidden="true"></i> Remaining:
                <?php if (is_array($creditInfo)): ?>
                    <?php if (!empty($creditInfo['is_exempt'])): ?>Exempt (Admin)<?php else: ?><?php echo number_format((float) ($creditInfo['remaining'] ?? 0), 2, '.', ''); ?><?php endif; ?>
                <?php else: ?>N/A<?php endif; ?>
            </span>
        </div>

        <div class="card mb-3"><div class="card-body">
            <h4 class="header-title mb-2">1) Class Context</h4>
            <p class="text-muted mb-3">Select school year, semester, subject (Lecture/Laboratory), and section where a class record already exists.</p>
            <form method="get" class="row g-2 align-items-end" id="rcrContextForm">
                <div class="col-12">
                    <div class="rcr-hierarchy-note">
                        Hierarchy: 1 Academic Year -> 2 Semester -> 3 Subject Type -> 4 Subject -> 5 Section -> 6 Class Record
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <label class="form-label"><span class="rcr-step-label">1</span>Academic Year</label>
                    <select class="form-select" name="academic_year" id="rcrAy">
                        <option value="">Select Academic Year</option>
                        <?php foreach ($optAy as $ay): ?>
                            <option value="<?php echo rcr_h($ay); ?>" <?php echo $ay === $selectedAcademicYear ? 'selected' : ''; ?>><?php echo rcr_h($ay); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-xl-3 col-md-6">
                    <label class="form-label"><span class="rcr-step-label">2</span>Semester</label>
                    <select class="form-select" name="semester" id="rcrSem">
                        <option value="">Select Semester</option>
                        <?php foreach ($optSem as $sem): ?>
                            <option value="<?php echo rcr_h($sem); ?>" <?php echo $sem === $selectedSemester ? 'selected' : ''; ?>><?php echo rcr_h($sem); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-xl-3 col-md-6">
                    <label class="form-label"><span class="rcr-step-label">3</span>Subject Type</label>
                    <select class="form-select" name="subject_type" id="rcrType">
                        <option value="">Select Subject Type</option>
                        <?php foreach ($optType as $tp): ?>
                            <option value="<?php echo rcr_h($tp); ?>" <?php echo $tp === $selectedSubjectType ? 'selected' : ''; ?>><?php echo rcr_h($tp); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-xl-3 col-md-6">
                    <label class="form-label">Term</label>
                    <select class="form-select" name="term" id="rcrTerm">
                        <option value="midterm" <?php echo $term === 'midterm' ? 'selected' : ''; ?>>Midterm</option>
                        <option value="final" <?php echo $term === 'final' ? 'selected' : ''; ?>>Final</option>
                    </select>
                </div>
                <div class="col-xl-6 col-md-8">
                    <label class="form-label"><span class="rcr-step-label">4</span>Subject</label>
                    <select class="form-select" name="subject_id" id="rcrSubject">
                        <option value="0">Select Subject</option>
                        <?php foreach ($optSubject as $s): $sid = (int) ($s['subject_id'] ?? 0); ?>
                            <option value="<?php echo $sid; ?>" <?php echo $sid === $selectedSubjectId ? 'selected' : ''; ?>>
                                <?php echo rcr_h((string) ($s['subject_code'] ?? '')); ?> - <?php echo rcr_h((string) ($s['subject_name'] ?? '')); ?> (<?php echo rcr_h((string) ($s['subject_type'] ?? '')); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-xl-3 col-md-4">
                    <label class="form-label"><span class="rcr-step-label">5</span>Section</label>
                    <select class="form-select" name="section" id="rcrSection">
                        <option value="">Select Section</option>
                        <?php foreach ($optSection as $sec): ?>
                            <option value="<?php echo rcr_h($sec); ?>" <?php echo $sec === $selectedSection ? 'selected' : ''; ?>><?php echo rcr_h($sec); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-xl-9 col-md-8">
                    <label class="form-label"><span class="rcr-step-label">6</span>Class Record</label>
                    <select class="form-select" name="class_record_id" id="rcrClassRecord" <?php echo count($filteredIds) > 0 ? '' : 'disabled'; ?>>
                        <?php if (count($filteredIds) === 0): ?>
                            <option value="0">No class records for selected filters</option>
                        <?php else: ?>
                            <?php foreach ($filteredIds as $cid): $row = $classById[$cid] ?? null; if (!is_array($row)) continue; ?>
                                <option value="<?php echo (int) $cid; ?>" <?php echo $cid === $selectedClassRecordId ? 'selected' : ''; ?>>
                                    <?php echo rcr_h((string) ($row['subject_code'] ?? '')); ?> - <?php echo rcr_h((string) ($row['subject_name'] ?? '')); ?> | <?php echo rcr_h((string) ($row['section'] ?? '')); ?> | <?php echo rcr_h((string) ($row['academic_year'] ?? '')); ?>, <?php echo rcr_h((string) ($row['semester'] ?? '')); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="col-xl-3 col-md-4">
                    <button class="btn btn-primary w-100" type="submit" id="rcrLoadBtn">
                        <i class="ri-filter-3-line me-1" aria-hidden="true"></i> Load Context
                    </button>
                </div>
            </form>
        </div></div>

        <?php if (is_array($selectedClass)): ?>
            <div class="rcr-hero mb-3"><div class="d-flex align-items-end justify-content-between flex-wrap gap-2"><div><div class="fw-semibold"><?php echo rcr_h((string) ($selectedClass['subject_name'] ?? '')); ?> <small class="text-white-50">(<?php echo rcr_h((string) ($selectedClass['subject_code'] ?? '')); ?>)</small></div><div class="text-white-50 small">Section <?php echo rcr_h((string) ($selectedClass['section'] ?? '')); ?> | <?php echo rcr_h((string) ($selectedClass['academic_year'] ?? '')); ?>, <?php echo rcr_h((string) ($selectedClass['semester'] ?? '')); ?> | <?php echo rcr_h($termLabel); ?> | <?php echo rcr_h((string) ($selectedClass['subject_type'] ?? 'Lecture')); ?></div><div class="text-white-50 small">Course / Year: <?php echo rcr_h((string) ($selectedClass['course'] ?? 'N/A')); ?> / <?php echo rcr_h((string) ($selectedClass['year_level'] ?? 'N/A')); ?></div></div><a class="btn btn-sm btn-outline-light" href="teacher-grading-config.php?class_record_id=<?php echo (int) $selectedClassRecordId; ?>&term=<?php echo rcr_h($term); ?>">Components</a></div></div>
        <?php endif; ?>

        <div class="row g-3">
            <div class="col-xl-4">
                <div class="card"><div class="card-body">
                    <h4 class="header-title mb-2">2) Source Composition</h4>
                    <p class="text-muted mb-3">Copy from Build template or from already-created class configuration.</p>
                    <form method="get">
                        <input type="hidden" name="class_record_id" value="<?php echo (int) $selectedClassRecordId; ?>">
                        <input type="hidden" name="academic_year" value="<?php echo rcr_h($selectedAcademicYear); ?>">
                        <input type="hidden" name="semester" value="<?php echo rcr_h($selectedSemester); ?>">
                        <input type="hidden" name="subject_type" value="<?php echo rcr_h($selectedSubjectType); ?>">
                        <input type="hidden" name="subject_id" value="<?php echo (int) $selectedSubjectId; ?>">
                        <input type="hidden" name="section" value="<?php echo rcr_h($selectedSection); ?>">
                        <input type="hidden" name="term" value="<?php echo rcr_h($term); ?>">
                        <div class="mb-2"><label class="form-label">Source Mode</label><select class="form-select" name="source_mode" id="rcrSourceMode"><option value="build" <?php echo $sourceMode === 'build' ? 'selected' : ''; ?>>Build Template</option><option value="class_copy" <?php echo $sourceMode === 'class_copy' ? 'selected' : ''; ?>>Copy Existing Class Config</option></select></div>
                        <div class="mb-2" id="rcrBuildWrap" <?php echo $sourceMode === 'build' ? '' : 'style="display:none;"'; ?>><label class="form-label">Build</label><select class="form-select" name="build_id"><option value="0">Select build...</option><?php foreach ($builds as $bt): $bid = (int) ($bt['id'] ?? 0); ?><option value="<?php echo $bid; ?>" <?php echo $bid === $selectedBuildId ? 'selected' : ''; ?>><?php echo rcr_h((string) ($bt['name'] ?? '')); ?></option><?php endforeach; ?></select><?php if (count($builds) === 0): ?><div class="form-text text-warning">No active builds found.</div><?php endif; ?></div>
                        <div class="mb-2" id="rcrClassWrap" <?php echo $sourceMode === 'class_copy' ? '' : 'style="display:none;"'; ?>><label class="form-label">Source Class</label><select class="form-select" name="source_class_record_id"><option value="0">Select class...</option><?php foreach ($assigned as $ac): $cid = (int) ($ac['class_record_id'] ?? 0); ?><option value="<?php echo $cid; ?>" <?php echo $cid === $sourceClassRecordId ? 'selected' : ''; ?>><?php echo rcr_h((string) ($ac['subject_code'] ?? '')); ?> - <?php echo rcr_h((string) ($ac['subject_name'] ?? '')); ?> | <?php echo rcr_h((string) ($ac['section'] ?? '')); ?> | <?php echo rcr_h((string) ($ac['academic_year'] ?? '')); ?>, <?php echo rcr_h((string) ($ac['semester'] ?? '')); ?></option><?php endforeach; ?></select><div class="mt-2"><label class="form-label">Source Term</label><select class="form-select" name="source_term"><option value="midterm" <?php echo $sourceTerm === 'midterm' ? 'selected' : ''; ?>>Midterm</option><option value="final" <?php echo $sourceTerm === 'final' ? 'selected' : ''; ?>>Final</option></select></div></div>
                        <button class="btn btn-primary w-100" type="submit">Load Source Components</button>
                    </form>
                    <div class="border rounded p-2 mt-3"><div class="small text-muted">Loaded Components:</div><div class="fw-semibold"><?php echo (int) count($sourceComponents); ?></div><?php if ($sourceMessage !== ''): ?><div class="small text-warning"><?php echo rcr_h($sourceMessage); ?></div><?php endif; ?></div>
                </div></div>
                <div class="card"><div class="card-body"><h4 class="header-title mb-2">Input Format</h4><div class="small text-muted">1) CSV upload: <code>Name, Grade</code><br>2) Paste from Excel (tab-delimited): section/subject row + <code>#, StudentNo, Name, MT, FT, AVG</code>.</div></div></div>
            </div>

            <div class="col-xl-8">
                <div class="card"><div class="card-body">
                    <h4 class="header-title mb-2">3) Generate Preview</h4>
                    <p class="text-muted mb-3">Modal AI questions collect assessment count and composition details. Regenerate until satisfied.</p>
                    <form method="post" enctype="multipart/form-data" id="rcrGenerateForm">
                        <input type="hidden" name="csrf_token" value="<?php echo rcr_h(csrf_token()); ?>">
                        <input type="hidden" name="action" value="generate_preview">
                        <input type="hidden" name="class_record_id" value="<?php echo (int) $selectedClassRecordId; ?>">
                        <input type="hidden" name="academic_year" value="<?php echo rcr_h($selectedAcademicYear); ?>">
                        <input type="hidden" name="semester" value="<?php echo rcr_h($selectedSemester); ?>">
                        <input type="hidden" name="subject_type" value="<?php echo rcr_h($selectedSubjectType); ?>">
                        <input type="hidden" name="subject_id" value="<?php echo (int) $selectedSubjectId; ?>">
                        <input type="hidden" name="section" value="<?php echo rcr_h($selectedSection); ?>">
                        <input type="hidden" name="term" value="<?php echo rcr_h($term); ?>">
                        <input type="hidden" name="source_mode" value="<?php echo rcr_h($sourceMode); ?>">
                        <input type="hidden" name="build_id" value="<?php echo (int) $selectedBuildId; ?>">
                        <input type="hidden" name="source_class_record_id" value="<?php echo (int) $sourceClassRecordId; ?>">
                        <input type="hidden" name="source_term" value="<?php echo rcr_h($sourceTerm); ?>">
                        <div class="row g-3">
                            <div class="col-md-6"><label class="form-label">Input Mode</label><select class="form-select" name="grade_input_mode" id="rcrInputMode"><option value="paste">Paste from Excel</option><option value="csv">Upload CSV</option></select></div>
                            <div class="col-md-6"><label class="form-label">Replace Existing</label><div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="rcrReplaceExisting" name="replace_existing" value="1" <?php echo $replaceChecked ? 'checked' : ''; ?>><label class="form-check-label" for="rcrReplaceExisting">Delete term components before apply</label></div></div>
                        </div>

                        <div class="mt-3" id="rcrPasteWrap"><label class="form-label">Paste Excel Content</label><textarea class="form-control" name="grades_paste" rows="8" placeholder="Paste tab-delimited rows copied from Excel..."></textarea><div class="form-text">If section/subject row is included, it must match selected class context.</div></div>
                        <div class="mt-3" id="rcrCsvWrap" style="display:none;"><label class="form-label">CSV File</label><input class="form-control" type="file" name="grades_csv" accept=".csv,text/csv"></div>

                        <div class="mt-3"><div class="d-flex justify-content-between align-items-center mb-2"><label class="form-label mb-0">Components</label><button class="btn btn-sm btn-outline-secondary" type="button" id="rcrToggleAllBtn">Toggle All</button></div><div class="rcr-list"><?php if (count($sourceComponents) === 0): ?><div class="text-muted">No components found for selected source.</div><?php else: ?><?php foreach ($sourceComponents as $i => $bc): $cid = (int) ($bc['component_id'] ?? 0); ?><div class="form-check mb-2"><input class="form-check-input rcr-check" type="checkbox" name="component_ids[]" value="<?php echo $cid; ?>" id="rcrComp<?php echo $i; ?>" <?php echo isset($selectedComponentIdsUi[$cid]) ? 'checked' : ''; ?>><label class="form-check-label" for="rcrComp<?php echo $i; ?>"><span class="fw-semibold"><?php echo rcr_h((string) ($bc['component_name'] ?? '')); ?></span> <span class="text-muted small">(<?php echo rcr_h((string) ($bc['component_type'] ?? 'other')); ?>)</span><div class="text-muted small"><?php echo rcr_h((string) ($bc['parameter_name'] ?? 'General')); ?> | Weight: <?php echo number_format((float) ($bc['component_weight'] ?? 0), 2, '.', ''); ?>%</div></label></div><?php endforeach; ?><?php endif; ?></div></div>

                        <div class="mt-3"><button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#rcrAiModal" <?php echo (is_array($selectedClass) && count($sourceComponents) > 0) ? '' : 'disabled'; ?>><i class="ri-magic-line me-1" aria-hidden="true"></i> AI Questions & Generate</button></div>

                        <div class="modal fade" id="rcrAiModal" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header"><h5 class="modal-title">AI Questions for Reverse Generation</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                                    <div class="modal-body">
                                        <div class="row g-3">
                                            <div class="col-md-4"><label class="form-label">Default assessments/component</label><input class="form-control" type="number" min="1" max="12" id="rcrAiGlobalCount" name="ai_global_count" value="<?php echo (int) $defaultGlobalCount; ?>" required></div>
                                            <div class="col-md-4"><label class="form-label">Assessment max score</label><input class="form-control" type="number" min="1" max="1000" step="0.01" name="ai_max_score" value="<?php echo number_format((float) $defaultMaxScore, 2, '.', ''); ?>" required></div>
                                            <div class="col-md-4"><label class="form-label">Variance (%)</label><input class="form-control" type="number" min="0" max="35" step="0.1" name="ai_variance_pct" value="<?php echo number_format((float) $defaultVariance, 1, '.', ''); ?>" required></div>
                                            <div class="col-md-8"><label class="form-label">Assessment name prefix</label><input class="form-control" type="text" maxlength="40" name="ai_name_prefix" value="<?php echo rcr_h($defaultNamePrefix); ?>" required></div>
                                            <div class="col-md-4 d-flex align-items-end"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="rcrAiKeepProject" name="ai_keep_project_exam_single" value="1" <?php echo $defaultKeepProjectExamSingle ? 'checked' : ''; ?>><label class="form-check-label" for="rcrAiKeepProject">Force Project/Exam = 1</label></div></div>
                                        </div>

                                        <hr>
                                        <div class="d-flex align-items-center justify-content-between mb-2"><div class="fw-semibold">Per-Component Assessment Count</div><button class="btn btn-sm btn-outline-secondary" type="button" id="rcrApplyGlobalCountBtn">Apply Default To Checked</button></div>
                                        <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Component</th><th>Type</th><th>Count</th></tr></thead><tbody><?php foreach ($sourceComponents as $bc): $cid = (int) ($bc['component_id'] ?? 0); if ($cid <= 0) continue; $countVal = isset($componentCountsPreview[$cid]) ? (int) $componentCountsPreview[$cid] : (in_array(strtolower((string) ($bc['component_type'] ?? 'other')), ['project', 'exam'], true) && $defaultKeepProjectExamSingle ? 1 : $defaultGlobalCount); if ($countVal < 1) $countVal = 1; if ($countVal > 12) $countVal = 12; ?><tr><td><?php echo rcr_h((string) ($bc['component_name'] ?? '')); ?></td><td><?php echo rcr_h((string) ($bc['component_type'] ?? 'other')); ?></td><td style="max-width:140px;"><input class="form-control form-control-sm rcr-comp-count" data-rcr-comp-id="<?php echo $cid; ?>" type="number" min="1" max="12" name="component_count[<?php echo $cid; ?>]" value="<?php echo $countVal; ?>"></td></tr><?php endforeach; ?></tbody></table></div>
                                    </div>
                                    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Generate Preview</button></div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div></div>
                <?php if (is_array($preview)): $pstats = (array) ($preview['parse_stats'] ?? []); ?>
                    <div class="card"><div class="card-body">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2"><h4 class="header-title mb-0">4) Preview & Finalize</h4><div class="small text-muted">Seed <?php echo (int) ($preview['seed'] ?? 0); ?></div></div>
                        <div class="row g-2 mb-3">
                            <div class="col-md-3"><div class="border rounded p-2"><div class="small text-muted">Matched</div><div class="fw-semibold"><?php echo (int) (($pstats['matched'] ?? 0)); ?></div></div></div>
                            <div class="col-md-3"><div class="border rounded p-2"><div class="small text-muted">Components</div><div class="fw-semibold"><?php echo (int) count((array) ($preview['components'] ?? [])); ?></div></div></div>
                            <div class="col-md-3"><div class="border rounded p-2"><div class="small text-muted">Assessments</div><div class="fw-semibold"><?php echo (int) ($preview['assessment_total'] ?? 0); ?></div></div></div>
                            <div class="col-md-3"><div class="border rounded p-2"><div class="small text-muted">Parse Mode</div><div class="fw-semibold"><?php echo rcr_h((string) ($pstats['parse_mode'] ?? '')); ?></div></div></div>
                        </div>
                        <div class="table-responsive mb-3"><table class="table table-sm table-bordered align-middle mb-0"><thead><tr><th>Student</th><th>Student No</th><th>Target</th><th>Predicted</th></tr></thead><tbody><?php $rows = array_slice((array) ($preview['student_preview'] ?? []), 0, 20); foreach ($rows as $sr): ?><tr><td><?php echo rcr_h((string) ($sr['name'] ?? '')); ?></td><td><?php echo rcr_h((string) ($sr['student_no'] ?? '')); ?></td><td><?php echo number_format((float) ($sr['target'] ?? 0), 2, '.', ''); ?></td><td><?php echo number_format((float) ($sr['predicted'] ?? 0), 2, '.', ''); ?></td></tr><?php endforeach; ?><?php if (count((array) ($preview['student_preview'] ?? [])) > 20): ?><tr><td colspan="4" class="text-muted small">Showing first 20 students.</td></tr><?php endif; ?></tbody></table></div>
                        <div class="d-flex flex-wrap gap-2">
                            <form method="post" class="d-inline"><input type="hidden" name="csrf_token" value="<?php echo rcr_h(csrf_token()); ?>"><input type="hidden" name="action" value="regenerate_preview"><input type="hidden" name="class_record_id" value="<?php echo (int) $selectedClassRecordId; ?>"><button class="btn btn-outline-primary" type="submit"><i class="ri-refresh-line me-1" aria-hidden="true"></i> Regenerate</button></form>
                            <form method="post" class="d-inline"><input type="hidden" name="csrf_token" value="<?php echo rcr_h(csrf_token()); ?>"><input type="hidden" name="action" value="apply_preview"><input type="hidden" name="class_record_id" value="<?php echo (int) $selectedClassRecordId; ?>"><button class="btn btn-success" type="submit"><i class="ri-check-double-line me-1" aria-hidden="true"></i> Apply (Satisfied)</button></form>
                        </div>
                    </div></div>
                <?php endif; ?>

                <?php if (is_array($result)): ?><div class="card"><div class="card-body"><h4 class="header-title mb-2">Apply Result</h4><div class="row g-2"><div class="col-md-3"><div class="border rounded p-2"><div class="small text-muted">Matched</div><div class="fw-semibold"><?php echo (int) ($result['matched'] ?? 0); ?></div></div></div><div class="col-md-3"><div class="border rounded p-2"><div class="small text-muted">Components</div><div class="fw-semibold"><?php echo (int) ($result['components'] ?? 0); ?></div></div></div><div class="col-md-3"><div class="border rounded p-2"><div class="small text-muted">Assessments</div><div class="fw-semibold"><?php echo (int) ($result['assessments'] ?? 0); ?></div></div></div><div class="col-md-3"><div class="border rounded p-2"><div class="small text-muted">Score Writes</div><div class="fw-semibold"><?php echo (int) ($result['writes'] ?? 0); ?></div></div></div></div></div></div><?php endif; ?>
            </div>
        </div>
    </div></div>
    <?php include '../layouts/footer.php'; ?>
</div></div>

<?php include '../layouts/right-sidebar.php'; ?>
<?php include '../layouts/footer-scripts.php'; ?>
<script src="assets/js/app.min.js"></script>
<script>
(function () {
    var toggleBtn = document.getElementById('rcrToggleAllBtn');
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function () {
            var boxes = document.querySelectorAll('.rcr-check');
            if (!boxes || boxes.length === 0) return;
            var allOn = true;
            boxes.forEach(function (b) { if (!b.checked) allOn = false; });
            boxes.forEach(function (b) { b.checked = !allOn; });
        });
    }

    var sourceMode = document.getElementById('rcrSourceMode');
    var buildWrap = document.getElementById('rcrBuildWrap');
    var classWrap = document.getElementById('rcrClassWrap');
    function applySourceMode() {
        if (!sourceMode) return;
        var mode = sourceMode.value;
        if (buildWrap) buildWrap.style.display = mode === 'build' ? '' : 'none';
        if (classWrap) classWrap.style.display = mode === 'class_copy' ? '' : 'none';
    }
    if (sourceMode) sourceMode.addEventListener('change', applySourceMode);
    applySourceMode();

    var inputMode = document.getElementById('rcrInputMode');
    var pasteWrap = document.getElementById('rcrPasteWrap');
    var csvWrap = document.getElementById('rcrCsvWrap');
    function applyInputMode() {
        if (!inputMode) return;
        var mode = inputMode.value;
        if (pasteWrap) pasteWrap.style.display = mode === 'paste' ? '' : 'none';
        if (csvWrap) csvWrap.style.display = mode === 'csv' ? '' : 'none';
    }
    if (inputMode) inputMode.addEventListener('change', applyInputMode);
    applyInputMode();

    var filterRows = <?php echo json_encode($rcrFilterRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    if (!Array.isArray(filterRows)) filterRows = [];

    var ayFilter = document.getElementById('rcrAy');
    var semFilter = document.getElementById('rcrSem');
    var typeFilter = document.getElementById('rcrType');
    var subjectFilter = document.getElementById('rcrSubject');
    var sectionFilter = document.getElementById('rcrSection');
    var classFilter = document.getElementById('rcrClassRecord');
    var loadBtn = document.getElementById('rcrLoadBtn');

    function norm(v) {
        return String(v || '').trim().toLowerCase();
    }

    function rowMatches(row, state, ignoreKey) {
        if (!row || typeof row !== 'object') return false;
        if (ignoreKey !== 'ay' && state.ay !== '' && norm(row.academic_year) !== norm(state.ay)) return false;
        if (ignoreKey !== 'sem' && state.sem !== '' && norm(row.semester) !== norm(state.sem)) return false;
        if (ignoreKey !== 'type' && state.type !== '' && norm(row.subject_type) !== norm(state.type)) return false;
        if (ignoreKey !== 'subject' && state.subject !== '' && state.subject !== '0' && String(row.subject_id || '') !== state.subject) return false;
        if (ignoreKey !== 'section' && state.section !== '' && norm(row.section) !== norm(state.section)) return false;
        return true;
    }

    function getState() {
        return {
            ay: ayFilter ? String(ayFilter.value || '').trim() : '',
            sem: semFilter ? String(semFilter.value || '').trim() : '',
            type: typeFilter ? String(typeFilter.value || '').trim() : '',
            subject: subjectFilter ? String(subjectFilter.value || '0').trim() : '0',
            section: sectionFilter ? String(sectionFilter.value || '').trim() : '',
            classRecord: classFilter ? String(classFilter.value || '').trim() : '',
        };
    }

    function renderOptions(selectEl, items, defaultValue, defaultLabel, preferredValue) {
        if (!selectEl) return '';
        selectEl.innerHTML = '';
        var defaultOpt = document.createElement('option');
        defaultOpt.value = String(defaultValue);
        defaultOpt.textContent = String(defaultLabel);
        selectEl.appendChild(defaultOpt);

        items.forEach(function (item) {
            var opt = document.createElement('option');
            opt.value = String(item.value);
            opt.textContent = String(item.label);
            selectEl.appendChild(opt);
        });

        var wanted = String(preferredValue || '');
        var exists = Array.prototype.some.call(selectEl.options, function (opt) { return String(opt.value) === wanted; });
        selectEl.value = exists ? wanted : String(defaultValue);
        return String(selectEl.value || '');
    }

    function disableSelect(selectEl, value, label) {
        if (!selectEl) return;
        selectEl.disabled = true;
        selectEl.innerHTML = '';
        var opt = document.createElement('option');
        opt.value = String(value);
        opt.textContent = String(label);
        selectEl.appendChild(opt);
        selectEl.value = String(value);
    }

    function rebuildClassFilters() {
        if (!ayFilter || !semFilter || !typeFilter || !subjectFilter || !sectionFilter || !classFilter) return;

        var state = getState();

        var ayMap = {};
        filterRows.forEach(function (row) {
            var ay = String(row.academic_year || '').trim();
            if (ay !== '') ayMap[ay] = ay;
        });
        var ayItems = Object.keys(ayMap).sort(function (a, b) { return a.localeCompare(b); }).map(function (ay) { return { value: ay, label: ay }; });
        state.ay = renderOptions(ayFilter, ayItems, '', 'Select Academic Year', state.ay);
        ayFilter.disabled = false;
        if (state.ay === '') {
            disableSelect(semFilter, '', 'Select academic year first');
            disableSelect(typeFilter, '', 'Select semester first');
            disableSelect(subjectFilter, '0', 'Select subject type first');
            disableSelect(sectionFilter, '', 'Select subject first');
            disableSelect(classFilter, '0', 'Select section first');
            if (loadBtn) loadBtn.disabled = true;
            return;
        }

        var semState = { ay: state.ay, sem: '', type: '', subject: '0', section: '' };
        var semMap = {};
        filterRows.forEach(function (row) {
            if (!rowMatches(row, semState, 'sem')) return;
            var sem = String(row.semester || '').trim();
            if (sem !== '') semMap[sem] = sem;
        });
        var semItems = Object.keys(semMap).sort(function (a, b) { return a.localeCompare(b); }).map(function (sem) { return { value: sem, label: sem }; });
        state.sem = renderOptions(semFilter, semItems, '', 'Select Semester', state.sem);
        semFilter.disabled = false;
        if (state.sem === '') {
            disableSelect(typeFilter, '', 'Select semester first');
            disableSelect(subjectFilter, '0', 'Select subject type first');
            disableSelect(sectionFilter, '', 'Select subject first');
            disableSelect(classFilter, '0', 'Select section first');
            if (loadBtn) loadBtn.disabled = true;
            return;
        }

        var typeState = { ay: state.ay, sem: state.sem, type: '', subject: '0', section: '' };
        var typeMap = {};
        filterRows.forEach(function (row) {
            if (!rowMatches(row, typeState, 'type')) return;
            var type = String(row.subject_type || '').trim();
            if (type !== '') typeMap[type] = type;
        });
        var typeItems = Object.keys(typeMap).sort(function (a, b) { return a.localeCompare(b); }).map(function (type) { return { value: type, label: type }; });
        state.type = renderOptions(typeFilter, typeItems, '', 'Select Subject Type', state.type);
        typeFilter.disabled = false;
        if (state.type === '') {
            disableSelect(subjectFilter, '0', 'Select subject type first');
            disableSelect(sectionFilter, '', 'Select subject first');
            disableSelect(classFilter, '0', 'Select section first');
            if (loadBtn) loadBtn.disabled = true;
            return;
        }

        var subjectState = { ay: state.ay, sem: state.sem, type: state.type, subject: '0', section: '' };
        var subjectMap = {};
        filterRows.forEach(function (row) {
            if (!rowMatches(row, subjectState, 'subject')) return;
            var sid = String(row.subject_id || '').trim();
            if (sid === '' || sid === '0') return;
            var label = String(row.subject_label || '').trim();
            if (label === '') label = 'Subject #' + sid;
            subjectMap[sid] = label;
        });
        var subjectItems = Object.keys(subjectMap)
            .sort(function (a, b) { return String(subjectMap[a]).localeCompare(String(subjectMap[b])); })
            .map(function (sid) { return { value: sid, label: subjectMap[sid] }; });
        state.subject = renderOptions(subjectFilter, subjectItems, '0', 'Select Subject', state.subject);
        subjectFilter.disabled = false;
        if (state.subject === '' || state.subject === '0') {
            disableSelect(sectionFilter, '', 'Select subject first');
            disableSelect(classFilter, '0', 'Select section first');
            if (loadBtn) loadBtn.disabled = true;
            return;
        }

        var sectionState = { ay: state.ay, sem: state.sem, type: state.type, subject: state.subject, section: '' };
        var sectionMap = {};
        filterRows.forEach(function (row) {
            if (!rowMatches(row, sectionState, 'section')) return;
            var section = String(row.section || '').trim();
            if (section !== '') sectionMap[section] = section;
        });
        var sectionItems = Object.keys(sectionMap).sort(function (a, b) { return a.localeCompare(b); }).map(function (section) { return { value: section, label: section }; });
        state.section = renderOptions(sectionFilter, sectionItems, '', 'Select Section', state.section);
        sectionFilter.disabled = false;
        if (state.section === '') {
            disableSelect(classFilter, '0', 'Select section first');
            if (loadBtn) loadBtn.disabled = true;
            return;
        }

        var classState = { ay: state.ay, sem: state.sem, type: state.type, subject: state.subject, section: state.section };
        var classMap = {};
        filterRows.forEach(function (row) {
            if (!rowMatches(row, classState, '')) return;
            var cid = String(row.class_record_id || '').trim();
            if (cid === '' || cid === '0') return;
            var label = String(row.class_label || '').trim();
            if (label === '') label = 'Class #' + cid;
            classMap[cid] = label;
        });
        var classItems = Object.keys(classMap)
            .sort(function (a, b) { return String(classMap[a]).localeCompare(String(classMap[b])); })
            .map(function (cid) { return { value: cid, label: classMap[cid] }; });

        classFilter.innerHTML = '';
        if (classItems.length === 0) {
            classFilter.disabled = true;
            var emptyOpt = document.createElement('option');
            emptyOpt.value = '0';
            emptyOpt.textContent = 'No class records for selected filters';
            classFilter.appendChild(emptyOpt);
            classFilter.value = '0';
            if (loadBtn) loadBtn.disabled = true;
            return;
        }

        classFilter.disabled = false;
        classItems.forEach(function (item) {
            var opt = document.createElement('option');
            opt.value = String(item.value);
            opt.textContent = String(item.label);
            classFilter.appendChild(opt);
        });

        var desiredClass = String(state.classRecord || '');
        var hasDesiredClass = classItems.some(function (item) { return String(item.value) === desiredClass; });
        classFilter.value = hasDesiredClass ? desiredClass : String(classItems[0].value || '');
        if (loadBtn) loadBtn.disabled = String(classFilter.value || '') === '' || String(classFilter.value || '') === '0';
    }

    [ayFilter, semFilter, typeFilter, subjectFilter, sectionFilter].forEach(function (el) {
        if (!el) return;
        el.addEventListener('change', rebuildClassFilters);
    });
    rebuildClassFilters();

    var applyGlobalBtn = document.getElementById('rcrApplyGlobalCountBtn');
    if (applyGlobalBtn) {
        applyGlobalBtn.addEventListener('click', function () {
            var globalInput = document.getElementById('rcrAiGlobalCount');
            var value = globalInput ? parseInt(globalInput.value, 10) : 1;
            if (!Number.isFinite(value)) value = 1;
            if (value < 1) value = 1;
            if (value > 12) value = 12;
            document.querySelectorAll('.rcr-comp-count').forEach(function (el) {
                var compId = el.getAttribute('data-rcr-comp-id');
                var check = document.querySelector('.rcr-check[value="' + compId + '"]');
                if (check && !check.checked) return;
                el.value = String(value);
            });
        });
    }
})();
</script>
</body>
</html>
