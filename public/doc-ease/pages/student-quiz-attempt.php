<?php include '../layouts/session.php'; ?>
<?php require_approved_student(); ?>
<?php include '../layouts/main.php'; ?>

<?php
require_once __DIR__ . '/../includes/grading.php';
ensure_grading_tables($conn);

$flash = isset($_SESSION['flash_message']) ? (string) $_SESSION['flash_message'] : '';
$flashType = isset($_SESSION['flash_type']) ? (string) $_SESSION['flash_type'] : 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$assessmentId = isset($_GET['assessment_id']) ? (int) $_GET['assessment_id'] : 0;
if ($assessmentId <= 0) {
    $_SESSION['flash_message'] = 'Invalid assessment.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: student-dashboard.php');
    exit;
}

if (!function_exists('sq_h')) {
    function sq_h($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('sq_fmt2')) {
    function sq_fmt2($number) {
        return rtrim(rtrim(number_format((float) $number, 2, '.', ''), '0'), '.');
    }
}

if (!function_exists('sq_format_datetime')) {
    function sq_format_datetime($value) {
        $value = trim((string) $value);
        if ($value === '') return '-';
        $ts = strtotime($value);
        if (!$ts) return $value;
        return date('Y-m-d H:i', $ts);
    }
}

if (!function_exists('sq_decode_overall_feedback')) {
    function sq_decode_overall_feedback($raw) {
        $json = grading_decode_json_array((string) $raw);
        $bands = is_array($json['bands'] ?? null) ? $json['bands'] : [];
        $out = [];
        foreach ($bands as $band) {
            if (!is_array($band)) continue;
            $min = is_numeric($band['min'] ?? null) ? (float) $band['min'] : null;
            $max = is_numeric($band['max'] ?? null) ? (float) $band['max'] : null;
            $text = trim((string) ($band['text'] ?? ''));
            if ($min === null || $max === null || $text === '') continue;
            $out[] = ['min' => $min, 'max' => $max, 'text' => $text];
        }
        usort($out, static function ($a, $b) {
            return ($a['min'] <=> $b['min']);
        });
        return $out;
    }
}

if (!function_exists('sq_pick_overall_feedback_text')) {
    function sq_pick_overall_feedback_text(array $bands, $score, $maxScore) {
        if ($score === null || !is_numeric($score)) return '';
        $maxScore = (float) $maxScore;
        if ($maxScore <= 0) return '';
        $pct = (((float) $score) / $maxScore) * 100.0;
        foreach ($bands as $band) {
            $min = (float) ($band['min'] ?? 0);
            $max = (float) ($band['max'] ?? 100);
            if ($pct >= $min && $pct <= $max) {
                return (string) ($band['text'] ?? '');
            }
        }
        return '';
    }
}

if (!function_exists('sq_parse_int_list_json')) {
    function sq_parse_int_list_json($raw) {
        $out = [];
        $arr = grading_decode_json_array($raw);
        foreach ($arr as $v) {
            $n = (int) $v;
            if ($n <= 0) continue;
            if (isset($out[$n])) continue;
            $out[$n] = $n;
        }
        return array_values($out);
    }
}

if (!function_exists('sq_encode_int_list_json')) {
    function sq_encode_int_list_json(array $values) {
        $clean = [];
        foreach ($values as $v) {
            $n = (int) $v;
            if ($n <= 0) continue;
            if (isset($clean[$n])) continue;
            $clean[$n] = $n;
        }
        $json = json_encode(array_values($clean), JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : '[]';
    }
}

if (!function_exists('sq_is_quiz_open')) {
    function sq_is_quiz_open($openAt, $closeAt, $nowTs, &$message = '') {
        $message = '';
        $openAt = trim((string) $openAt);
        $closeAt = trim((string) $closeAt);

        if ($openAt !== '') {
            $openTs = strtotime($openAt);
            if ($openTs && $nowTs < $openTs) {
                $message = 'Quiz is not open yet. Opens at ' . date('Y-m-d H:i', $openTs) . '.';
                return false;
            }
        }

        if ($closeAt !== '') {
            $closeTs = strtotime($closeAt);
            if ($closeTs && $nowTs > $closeTs) {
                $message = 'Quiz is already closed. Closed at ' . date('Y-m-d H:i', $closeTs) . '.';
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('sq_attempt_remaining_seconds')) {
    function sq_attempt_remaining_seconds(array $attemptRow, array $assessmentRow, $nowTs) {
        $deadlines = [];

        $timeLimit = (int) ($attemptRow['time_limit_minutes'] ?? 0);
        if ($timeLimit <= 0) {
            $timeLimit = (int) ($assessmentRow['time_limit_minutes'] ?? 0);
        }
        $startedAt = trim((string) ($attemptRow['started_at'] ?? ''));
        if ($timeLimit > 0 && $startedAt !== '') {
            $startedTs = strtotime($startedAt);
            if ($startedTs) {
                $deadlines[] = $startedTs + ($timeLimit * 60);
            }
        }

        $closeAt = trim((string) ($assessmentRow['close_at'] ?? ''));
        if ($closeAt !== '') {
            $closeTs = strtotime($closeAt);
            if ($closeTs) $deadlines[] = $closeTs;
        }

        if (count($deadlines) === 0) return null;
        $deadline = min($deadlines);
        return (int) ($deadline - (int) $nowTs);
    }
}

if (!function_exists('sq_attempt_is_expired')) {
    function sq_attempt_is_expired(array $attemptRow, array $assessmentRow, $nowTs) {
        $remaining = sq_attempt_remaining_seconds($attemptRow, $assessmentRow, $nowTs);
        if ($remaining === null) return false;
        return $remaining <= 0;
    }
}

if (!function_exists('sq_format_duration_short')) {
    function sq_format_duration_short($seconds) {
        $seconds = (int) $seconds;
        if ($seconds < 0) $seconds = 0;
        $hours = (int) floor($seconds / 3600);
        $minutes = (int) floor(($seconds % 3600) / 60);
        $secs = (int) ($seconds % 60);
        $parts = [];
        if ($hours > 0) $parts[] = $hours . 'h';
        if ($minutes > 0) $parts[] = $minutes . 'm';
        if ($hours === 0 && $minutes === 0) $parts[] = $secs . 's';
        return implode(' ', $parts);
    }
}

if (!function_exists('sq_count_student_proof_files')) {
    function sq_count_student_proof_files(mysqli $conn, $assessmentId, $studentId) {
        $assessmentId = (int) $assessmentId;
        $studentId = (int) $studentId;
        if ($assessmentId <= 0 || $studentId <= 0) return 0;
        $count = 0;
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS c
             FROM grading_assignment_submissions ss
             JOIN grading_assignment_submission_files sf ON sf.submission_id = ss.id
             WHERE ss.assessment_id = ?
               AND ss.student_id = ?
               AND sf.uploaded_by_role = 'student'"
        );
        if ($stmt) {
            $stmt->bind_param('ii', $assessmentId, $studentId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows === 1) $count = (int) ($res->fetch_assoc()['c'] ?? 0);
            $stmt->close();
        }
        return $count;
    }
}

if (!function_exists('sq_build_question_order')) {
    function sq_build_question_order(array $questionsById, $shuffleQuestions) {
        $ids = array_keys($questionsById);
        $ids = array_values(array_filter(array_map('intval', $ids), static function ($n) {
            return $n > 0;
        }));
        if ($shuffleQuestions && count($ids) > 1) {
            shuffle($ids);
        }
        return $ids;
    }
}

if (!function_exists('sq_sort_choices_for_attempt')) {
    function sq_sort_choices_for_attempt(array $choices, $attemptId, $questionId) {
        $out = [];
        foreach ($choices as $idx => $choice) {
            if (!is_array($choice)) continue;
            $text = (string) ($choice['text'] ?? '');
            $isCorrect = ((int) ($choice['is_correct'] ?? 0)) === 1 ? 1 : 0;
            $key = sha1((string) $attemptId . '|' . (string) $questionId . '|' . (string) $idx);
            $out[] = [
                'orig_idx' => (int) $idx,
                'text' => $text,
                'is_correct' => $isCorrect,
                'sort_key' => $key,
            ];
        }
        usort($out, static function ($a, $b) {
            return strcmp((string) ($a['sort_key'] ?? ''), (string) ($b['sort_key'] ?? ''));
        });
        return $out;
    }
}

if (!function_exists('sq_load_attempts')) {
    function sq_load_attempts(mysqli $conn, $assessmentId, $studentId) {
        $list = [];
        $byId = [];
        $stmt = $conn->prepare(
            "SELECT id, attempt_no, status, question_order_json, started_at, submitted_at, time_limit_minutes, score_raw, score_scaled
             FROM grading_assessment_attempts
             WHERE assessment_id = ? AND student_id = ?
             ORDER BY attempt_no DESC, id DESC"
        );
        if ($stmt) {
            $stmt->bind_param('ii', $assessmentId, $studentId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                $list[] = $row;
                $aid = (int) ($row['id'] ?? 0);
                if ($aid > 0) $byId[$aid] = $row;
            }
            $stmt->close();
        }
        return [$list, $byId];
    }
}

if (!function_exists('sq_summarize_attempts')) {
    function sq_summarize_attempts(array $attemptsList) {
        $maxAttemptNo = 0;
        $completedAttempts = 0;
        $inProgressAttempt = null;
        $latestSubmittedTs = null;

        foreach ($attemptsList as $row) {
            if (!is_array($row)) continue;

            $attemptNo = (int) ($row['attempt_no'] ?? 0);
            if ($attemptNo > $maxAttemptNo) $maxAttemptNo = $attemptNo;

            $status = strtolower(trim((string) ($row['status'] ?? '')));
            if (in_array($status, ['submitted', 'autosubmitted'], true)) {
                $completedAttempts++;
                $submittedAt = trim((string) ($row['submitted_at'] ?? ''));
                if ($submittedAt !== '') {
                    $submittedTs = strtotime($submittedAt);
                    if ($submittedTs) {
                        if ($latestSubmittedTs === null || $submittedTs > $latestSubmittedTs) {
                            $latestSubmittedTs = $submittedTs;
                        }
                    }
                }
            }

            if ($status === 'in_progress' && !is_array($inProgressAttempt)) {
                $inProgressAttempt = $row;
            }
        }

        return [$maxAttemptNo, $completedAttempts, $inProgressAttempt, $latestSubmittedTs];
    }
}

if (!function_exists('sq_load_current_score')) {
    function sq_load_current_score(mysqli $conn, $assessmentId, $studentId) {
        $score = null;
        $stmt = $conn->prepare("SELECT score FROM grading_assessment_scores WHERE assessment_id = ? AND student_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('ii', $assessmentId, $studentId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows === 1) {
                $row = $res->fetch_assoc();
                if (($row['score'] ?? null) !== null && is_numeric($row['score'])) {
                    $score = (float) $row['score'];
                }
            }
            $stmt->close();
        }
        return $score;
    }
}

if (!function_exists('sq_load_attempt_response_map')) {
    function sq_load_attempt_response_map(mysqli $conn, $attemptId) {
        $map = [];
        $stmt = $conn->prepare(
            "SELECT question_id, response_text, is_correct, awarded_mark, max_mark
             FROM grading_assessment_attempt_answers
             WHERE attempt_id = ?"
        );
        if ($stmt) {
            $stmt->bind_param('i', $attemptId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                $qid = (int) ($row['question_id'] ?? 0);
                if ($qid <= 0) continue;
                $map[$qid] = [
                    'response_text' => (string) ($row['response_text'] ?? ''),
                    'is_correct' => isset($row['is_correct']) ? (int) $row['is_correct'] : null,
                    'awarded_mark' => (float) ($row['awarded_mark'] ?? 0),
                    'max_mark' => (float) ($row['max_mark'] ?? 0),
                ];
            }
            $stmt->close();
        }
        return $map;
    }
}

if (!function_exists('sq_eval_question_response')) {
    function sq_eval_question_response(array $questionRow, $rawResponse) {
        $type = strtolower(trim((string) ($questionRow['question_type'] ?? 'multiple_choice')));
        $maxMark = clamp_decimal((float) ($questionRow['default_mark'] ?? 0), 0, 100000);
        $options = grading_decode_json_array((string) ($questionRow['options_json'] ?? ''));
        $answerText = trim((string) ($questionRow['answer_text'] ?? ''));

        $responseText = trim((string) $rawResponse);
        $isCorrect = 0;
        $awarded = 0.0;

        if ($type === 'multiple_choice') {
            $choices = is_array($options['choices'] ?? null) ? $options['choices'] : [];
            $selectedIdx = null;
            if ($responseText !== '' && preg_match('/^\d+$/', $responseText)) {
                $selectedIdx = (int) $responseText;
            }

            $correctIdx = null;
            foreach ($choices as $idx => $choice) {
                if (!is_array($choice)) continue;
                if (((int) ($choice['is_correct'] ?? 0)) === 1) {
                    $correctIdx = (int) $idx;
                    break;
                }
            }

            if ($selectedIdx !== null && isset($choices[$selectedIdx]) && is_array($choices[$selectedIdx])) {
                $responseText = trim((string) ($choices[$selectedIdx]['text'] ?? ''));
            }

            if ($selectedIdx !== null && $correctIdx !== null && $selectedIdx === $correctIdx) {
                $isCorrect = 1;
                $awarded = $maxMark;
            }
        } elseif ($type === 'true_false') {
            $v = strtolower(trim($responseText));
            if (!in_array($v, ['true', 'false'], true)) $v = '';
            $responseText = $v;

            $correct = strtolower($answerText);
            if (!in_array($correct, ['true', 'false'], true)) {
                $choices = is_array($options['choices'] ?? null) ? $options['choices'] : [];
                foreach ($choices as $choice) {
                    if (!is_array($choice)) continue;
                    if (((int) ($choice['is_correct'] ?? 0)) === 1) {
                        $label = strtolower(trim((string) ($choice['text'] ?? '')));
                        if (in_array($label, ['true', 'false'], true)) {
                            $correct = $label;
                            break;
                        }
                    }
                }
            }

            if ($v !== '' && $correct !== '' && $v === $correct) {
                $isCorrect = 1;
                $awarded = $maxMark;
            }
        } else {
            $accepted = [];
            $acceptedRaw = is_array($options['accepted_answers'] ?? null) ? $options['accepted_answers'] : [];
            foreach ($acceptedRaw as $a) {
                $vv = trim((string) $a);
                if ($vv === '') continue;
                $accepted[] = $vv;
            }
            if (count($accepted) === 0 && $answerText !== '') $accepted[] = $answerText;

            $caseSensitive = !empty($options['case_sensitive']);
            $candidate = trim($responseText);
            $responseText = $candidate;

            if ($candidate !== '' && count($accepted) > 0) {
                foreach ($accepted as $a) {
                    if ($caseSensitive) {
                        if ($candidate === $a) {
                            $isCorrect = 1;
                            break;
                        }
                    } else {
                        $left = function_exists('mb_strtolower') ? mb_strtolower($candidate, 'UTF-8') : strtolower($candidate);
                        $right = function_exists('mb_strtolower') ? mb_strtolower($a, 'UTF-8') : strtolower($a);
                        if ($left === $right) {
                            $isCorrect = 1;
                            break;
                        }
                    }
                }
            }
            if ($isCorrect === 1) $awarded = $maxMark;
        }

        return [
            'response_text' => $responseText,
            'is_correct' => $isCorrect,
            'awarded_mark' => $awarded,
            'max_mark' => $maxMark,
        ];
    }
}

if (!function_exists('sq_finalize_attempt')) {
    function sq_finalize_attempt(mysqli $conn, array $attemptRow, array $assessmentRow, array $questionsById, array $responses, $finalStatus = 'submitted') {
        $attemptId = (int) ($attemptRow['id'] ?? 0);
        $assessmentId = (int) ($attemptRow['assessment_id'] ?? 0);
        $studentId = (int) ($attemptRow['student_id'] ?? 0);
        if ($attemptId <= 0 || $assessmentId <= 0 || $studentId <= 0) {
            return [false, 'Invalid attempt context.'];
        }

        if (!in_array($finalStatus, ['submitted', 'autosubmitted'], true)) {
            $finalStatus = 'submitted';
        }

        $order = sq_parse_int_list_json((string) ($attemptRow['question_order_json'] ?? ''));
        if (count($order) === 0) {
            $order = sq_build_question_order($questionsById, false);
        }

        $sumAwarded = 0.0;
        $sumMax = 0.0;
        $conn->begin_transaction();

        try {
            $upAnswer = $conn->prepare(
                "INSERT INTO grading_assessment_attempt_answers (attempt_id, question_id, response_text, is_correct, awarded_mark, max_mark)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    response_text = VALUES(response_text),
                    is_correct = VALUES(is_correct),
                    awarded_mark = VALUES(awarded_mark),
                    max_mark = VALUES(max_mark),
                    updated_at = CURRENT_TIMESTAMP"
            );
            if (!$upAnswer) throw new RuntimeException('Unable to prepare attempt answers statement.');

            foreach ($order as $qid) {
                $qid = (int) $qid;
                if ($qid <= 0 || !isset($questionsById[$qid]) || !is_array($questionsById[$qid])) continue;
                $question = $questionsById[$qid];

                $raw = '';
                if (array_key_exists((string) $qid, $responses)) {
                    $raw = $responses[(string) $qid];
                } elseif (array_key_exists($qid, $responses)) {
                    $raw = $responses[$qid];
                }
                if (is_array($raw)) $raw = '';

                $eval = sq_eval_question_response($question, $raw);
                $responseText = (string) ($eval['response_text'] ?? '');
                $isCorrect = (int) ($eval['is_correct'] ?? 0);
                $awardedMark = (float) ($eval['awarded_mark'] ?? 0);
                $maxMark = (float) ($eval['max_mark'] ?? 0);

                $sumAwarded += $awardedMark;
                $sumMax += $maxMark;

                $upAnswer->bind_param('iisidd', $attemptId, $qid, $responseText, $isCorrect, $awardedMark, $maxMark);
                $upAnswer->execute();
            }
            $upAnswer->close();

            $assessmentMax = clamp_decimal((float) ($assessmentRow['max_score'] ?? 0), 0, 100000);
            if ($assessmentMax <= 0) $assessmentMax = $sumMax;
            $scoreScaled = 0.0;
            if ($sumMax > 0 && $assessmentMax >= 0) {
                $scoreScaled = ($sumAwarded / $sumMax) * $assessmentMax;
            }
            $sumAwarded = round($sumAwarded, 2);
            $scoreScaled = round($scoreScaled, 2);

            $upAttempt = $conn->prepare(
                "UPDATE grading_assessment_attempts
                 SET status = ?, submitted_at = NOW(), score_raw = ?, score_scaled = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND student_id = ? AND status = 'in_progress'
                 LIMIT 1"
            );
            if (!$upAttempt) throw new RuntimeException('Unable to update attempt status.');
            $upAttempt->bind_param('sddii', $finalStatus, $sumAwarded, $scoreScaled, $attemptId, $studentId);
            $upAttempt->execute();
            $affected = (int) $upAttempt->affected_rows;
            $upAttempt->close();
            if ($affected < 0) throw new RuntimeException('Attempt update failed.');

            $gradingMethod = strtolower(trim((string) ($assessmentRow['grading_method'] ?? 'highest')));
            [$okScoreSync, $picked] = grading_refresh_assessment_score_from_attempts($conn, $assessmentId, $studentId, $gradingMethod);
            if (!$okScoreSync) throw new RuntimeException('Unable to refresh assessment score.');

            $conn->commit();
            return [true, ['score_raw' => $sumAwarded, 'score_scaled' => $scoreScaled, 'picked_score' => $picked]];
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('[student-quiz-attempt] finalize failed: ' . $e->getMessage());
            return [false, 'Unable to submit attempt.'];
        }
    }
}

$student = null;
$stmt = $conn->prepare(
    "SELECT id, StudentNo, Surname, FirstName, MiddleName
     FROM students
     WHERE user_id = ?
     LIMIT 1"
);
if ($stmt) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows === 1) $student = $res->fetch_assoc();
    $stmt->close();
}
if (!is_array($student)) {
    deny_access(403, 'Student profile is not linked to this account.');
}

$studentId = (int) ($student['id'] ?? 0);
if ($studentId <= 0) {
    deny_access(403, 'Student profile is invalid.');
}

$assessment = null;
$stmt = $conn->prepare(
    "SELECT ga.id AS assessment_id,
            ga.name AS assessment_name,
            ga.max_score,
            ga.assessment_mode,
            ga.module_type,
            ga.instructions,
            ga.module_settings_json,
            ga.require_proof_upload,
            ga.open_at,
            ga.close_at,
            ga.time_limit_minutes,
            ga.attempts_allowed,
            ga.grading_method,
            ga.shuffle_questions,
            ga.shuffle_choices,
            ga.questions_per_page,
            ga.navigation_method,
            ga.require_password,
            ga.review_show_response,
            ga.review_show_marks,
            ga.review_show_correct_answers,
            ga.grade_to_pass,
            ga.overall_feedback_json,
            ga.safe_exam_mode,
            ga.safe_require_fullscreen,
            ga.safe_block_shortcuts,
            ga.safe_auto_submit_on_blur,
            ga.safe_blur_grace_seconds,
            ga.access_lock_when_passed,
            ga.access_cooldown_minutes,
            ga.assessment_date,
            gc.id AS grading_component_id,
            gc.component_name,
            gc.component_code,
            COALESCE(c.category_name, 'Uncategorized') AS category_name,
            sgc.term,
            sgc.section,
            sgc.academic_year,
            sgc.semester,
            cr.id AS class_record_id,
            s.subject_code,
            s.subject_name
     FROM grading_assessments ga
     JOIN grading_components gc ON gc.id = ga.grading_component_id
     JOIN section_grading_configs sgc ON sgc.id = gc.section_config_id
     JOIN class_records cr
        ON cr.subject_id = sgc.subject_id
       AND cr.section = sgc.section
       AND cr.academic_year = sgc.academic_year
       AND cr.semester = sgc.semester
       AND cr.status = 'active'
     JOIN class_enrollments ce
        ON ce.class_record_id = cr.id
       AND ce.student_id = ?
       AND ce.status = 'enrolled'
     JOIN subjects s ON s.id = sgc.subject_id
     LEFT JOIN grading_categories c ON c.id = gc.category_id
     WHERE ga.id = ?
       AND ga.is_active = 1
       AND gc.is_active = 1
     LIMIT 1"
);
if ($stmt) {
    $stmt->bind_param('ii', $studentId, $assessmentId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows === 1) $assessment = $res->fetch_assoc();
    $stmt->close();
}
if (!is_array($assessment)) {
    deny_access(403, 'Forbidden: assessment is not available for your enrolled classes.');
}

$term = strtolower(trim((string) ($assessment['term'] ?? 'midterm')));
$termLabel = $term === 'final' ? 'Final Term' : 'Midterm';
$assessmentMode = strtolower(trim((string) ($assessment['assessment_mode'] ?? 'manual')));
$moduleType = grading_normalize_module_type((string) ($assessment['module_type'] ?? 'assessment'));
$moduleInfo = grading_module_info($moduleType);
$moduleLabel = (string) ($moduleInfo['label'] ?? 'Assessment');
$moduleKind = strtolower((string) ($moduleInfo['kind'] ?? 'assessment'));
$moduleClass = 'bg-secondary-subtle text-secondary';
if ($moduleKind === 'activity') $moduleClass = 'bg-primary-subtle text-primary';
elseif ($moduleKind === 'resource') $moduleClass = 'bg-info-subtle text-info';
$moduleSettings = grading_decode_json_array((string) ($assessment['module_settings_json'] ?? ''));
$moduleSummary = trim((string) ($moduleSettings['summary'] ?? ''));
$moduleLaunchUrl = trim((string) ($moduleSettings['launch_url'] ?? ''));
$moduleLaunchHref = '';
if ($moduleLaunchUrl !== '' && preg_match('/^https?:\\/\\//i', $moduleLaunchUrl)) {
    $moduleLaunchHref = $moduleLaunchUrl;
}
$requireProofUpload = !empty($assessment['require_proof_upload']);
$studentProofUploadCount = sq_count_student_proof_files($conn, $assessmentId, $studentId);
$hasRequiredProofUpload = ($studentProofUploadCount > 0);
$attemptsAllowed = (int) ($assessment['attempts_allowed'] ?? 1);
if ($attemptsAllowed < 1) $attemptsAllowed = 1;
if ($attemptsAllowed > 20) $attemptsAllowed = 20;
$questionsPerPage = (int) ($assessment['questions_per_page'] ?? 0);
if ($questionsPerPage < 0) $questionsPerPage = 0;
if ($questionsPerPage > 100) $questionsPerPage = 100;
$navigationMethod = strtolower(trim((string) ($assessment['navigation_method'] ?? 'free')));
if (!in_array($navigationMethod, ['free', 'sequential'], true)) $navigationMethod = 'free';
$requirePassword = trim((string) ($assessment['require_password'] ?? ''));
$reviewShowResponse = !empty($assessment['review_show_response']);
$reviewShowMarks = !empty($assessment['review_show_marks']);
$reviewShowCorrect = !empty($assessment['review_show_correct_answers']);
$safeExamMode = strtolower(trim((string) ($assessment['safe_exam_mode'] ?? 'off')));
if (!in_array($safeExamMode, ['off', 'recommended', 'required'], true)) $safeExamMode = 'off';
$safeRequireFullscreen = ($safeExamMode !== 'off') && !empty($assessment['safe_require_fullscreen']);
$safeBlockShortcuts = ($safeExamMode !== 'off') && !empty($assessment['safe_block_shortcuts']);
$safeAutoSubmitOnBlur = ($safeExamMode !== 'off') && !empty($assessment['safe_auto_submit_on_blur']);
$safeBlurGraceSeconds = (int) ($assessment['safe_blur_grace_seconds'] ?? 10);
if ($safeBlurGraceSeconds < 1) $safeBlurGraceSeconds = 10;
if ($safeBlurGraceSeconds > 300) $safeBlurGraceSeconds = 300;
$accessLockWhenPassed = !empty($assessment['access_lock_when_passed']);
$accessCooldownMinutes = (int) ($assessment['access_cooldown_minutes'] ?? 0);
if ($accessCooldownMinutes < 0) $accessCooldownMinutes = 0;
if ($accessCooldownMinutes > 10080) $accessCooldownMinutes = 10080;
$gradeToPass = ($assessment['grade_to_pass'] ?? null);
if ($gradeToPass !== null && is_numeric($gradeToPass)) {
    $gradeToPass = (float) $gradeToPass;
} else {
    $gradeToPass = null;
}
$overallFeedbackBands = sq_decode_overall_feedback((string) ($assessment['overall_feedback_json'] ?? ''));

$allQuestionsById = [];
$activeQuestionsById = [];
$stmt = $conn->prepare(
    "SELECT id, question_type, question_text, options_json, answer_text, default_mark, display_order, is_required, is_active
     FROM grading_assessment_questions
     WHERE assessment_id = ?
     ORDER BY display_order ASC, id ASC"
);
if ($stmt) {
    $stmt->bind_param('i', $assessmentId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
        $qid = (int) ($row['id'] ?? 0);
        if ($qid <= 0) continue;
        $row['question_type'] = strtolower(trim((string) ($row['question_type'] ?? 'multiple_choice')));
        $row['options'] = grading_decode_json_array((string) ($row['options_json'] ?? ''));
        $allQuestionsById[$qid] = $row;
        if ((int) ($row['is_active'] ?? 0) === 1) {
            $activeQuestionsById[$qid] = $row;
        }
    }
    $stmt->close();
}

[$attemptsList, $attemptsById] = sq_load_attempts($conn, $assessmentId, $studentId);
[$maxAttemptNo, $completedAttempts, $inProgressAttempt, $latestSubmittedAttemptTs] = sq_summarize_attempts($attemptsList);

$nowTs = time();
$scheduleMessage = '';
$isQuizOpen = sq_is_quiz_open((string) ($assessment['open_at'] ?? ''), (string) ($assessment['close_at'] ?? ''), $nowTs, $scheduleMessage);
$currentScoreForStartRules = sq_load_current_score($conn, $assessmentId, $studentId);
$hasPassedForStartRules = ($accessLockWhenPassed && $gradeToPass !== null && $currentScoreForStartRules !== null)
    ? ((float) $currentScoreForStartRules >= (float) $gradeToPass)
    : false;
$cooldownRemainingForStartRules = 0;
$cooldownEndsAtForStartRules = null;
if ($accessCooldownMinutes > 0 && $latestSubmittedAttemptTs !== null) {
    $cooldownEndsAtForStartRules = (int) $latestSubmittedAttemptTs + ($accessCooldownMinutes * 60);
    if ($cooldownEndsAtForStartRules > $nowTs) {
        $cooldownRemainingForStartRules = (int) ($cooldownEndsAtForStartRules - $nowTs);
    }
}

if ($assessmentMode === 'quiz' && is_array($inProgressAttempt) && sq_attempt_is_expired($inProgressAttempt, $assessment, $nowTs)
    && (!$requireProofUpload || $hasRequiredProofUpload)) {
    $inProgressAttempt['assessment_id'] = $assessmentId;
    $inProgressAttempt['student_id'] = $studentId;

    [$okFinalize, $finalizeResult] = sq_finalize_attempt(
        $conn,
        $inProgressAttempt,
        $assessment,
        $allQuestionsById,
        [],
        'autosubmitted'
    );

    if ($okFinalize) {
        $_SESSION['flash_message'] = 'An expired in-progress attempt was auto-submitted.';
        $_SESSION['flash_type'] = 'warning';
    } else {
        $_SESSION['flash_message'] = is_string($finalizeResult) ? $finalizeResult : 'Unable to auto-submit expired attempt.';
        $_SESSION['flash_type'] = 'danger';
    }

    header('Location: student-quiz-attempt.php?assessment_id=' . $assessmentId);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    if (!csrf_validate($csrf)) {
        $_SESSION['flash_message'] = 'Security check failed (CSRF). Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: student-quiz-attempt.php?assessment_id=' . $assessmentId);
        exit;
    }

    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

    if ($action === 'start_attempt') {
        if ($assessmentMode !== 'quiz') {
            $_SESSION['flash_message'] = 'This assessment is not configured as a quiz.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: student-quiz-attempt.php?assessment_id=' . $assessmentId);
            exit;
        }

        if (count($activeQuestionsById) === 0) {
            $_SESSION['flash_message'] = 'This quiz has no active questions yet.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: student-quiz-attempt.php?assessment_id=' . $assessmentId);
            exit;
        }

        if (!$isQuizOpen) {
            $_SESSION['flash_message'] = $scheduleMessage !== '' ? $scheduleMessage : 'Quiz is not open at this time.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: student-quiz-attempt.php?assessment_id=' . $assessmentId);
            exit;
        }

        $postedPassword = trim((string) ($_POST['attempt_password'] ?? ''));
        if ($requirePassword !== '' && !hash_equals((string) $requirePassword, (string) $postedPassword)) {
            $_SESSION['flash_message'] = 'Quiz password is incorrect.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: student-quiz-attempt.php?assessment_id=' . $assessmentId);
            exit;
        }

        if (is_array($inProgressAttempt)) {
            $_SESSION['flash_message'] = 'You already have an in-progress attempt. Resume it below.';
            $_SESSION['flash_type'] = 'info';
            header('Location: student-quiz-attempt.php?assessment_id=' . $assessmentId);
            exit;
        }

        if ($maxAttemptNo >= $attemptsAllowed) {
            $_SESSION['flash_message'] = 'Attempt limit reached for this quiz.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: student-quiz-attempt.php?assessment_id=' . $assessmentId);
            exit;
        }

        if ($hasPassedForStartRules) {
            $_SESSION['flash_message'] = 'New attempts are locked because you already reached the passing grade.';
            $_SESSION['flash_type'] = 'info';
            header('Location: student-quiz-attempt.php?assessment_id=' . $assessmentId);
            exit;
        }

        if ($cooldownRemainingForStartRules > 0) {
            $waitText = sq_format_duration_short($cooldownRemainingForStartRules);
            $untilText = $cooldownEndsAtForStartRules ? date('Y-m-d H:i', (int) $cooldownEndsAtForStartRules) : '';
            $_SESSION['flash_message'] = 'Please wait ' . $waitText . ' before starting another attempt.' . ($untilText !== '' ? ' You can start again at ' . $untilText . '.' : '');
            $_SESSION['flash_type'] = 'warning';
            header('Location: student-quiz-attempt.php?assessment_id=' . $assessmentId);
            exit;
        }

        $newAttemptNo = $maxAttemptNo + 1;
        $questionOrder = sq_build_question_order($activeQuestionsById, !empty($assessment['shuffle_questions']));
        $questionOrderJson = sq_encode_int_list_json($questionOrder);
        $timeLimit = (int) ($assessment['time_limit_minutes'] ?? 0);
        if ($timeLimit <= 0) $timeLimit = null;

        if ($timeLimit === null) {
            $ins = $conn->prepare(
                "INSERT INTO grading_assessment_attempts
                    (assessment_id, student_id, attempt_no, status, question_order_json, time_limit_minutes, started_at)
                 VALUES (?, ?, ?, 'in_progress', ?, NULL, NOW())"
            );
            if ($ins) {
                $ins->bind_param('iiis', $assessmentId, $studentId, $newAttemptNo, $questionOrderJson);
                $ok = $ins->execute();
                $newAttemptId = (int) $conn->insert_id;
                $ins->close();
                if ($ok && $newAttemptId > 0) {
                    $_SESSION['flash_message'] = 'Attempt started. Good luck.';
                    $_SESSION['flash_type'] = 'success';
                    header('Location: student-quiz-attempt.php?assessment_id=' . $assessmentId . '&attempt_id=' . $newAttemptId);
                    exit;
                }
            }
        } else {
            $ins = $conn->prepare(
                "INSERT INTO grading_assessment_attempts
                    (assessment_id, student_id, attempt_no, status, question_order_json, time_limit_minutes, started_at)
                 VALUES (?, ?, ?, 'in_progress', ?, ?, NOW())"
            );
            if ($ins) {
                $ins->bind_param('iiisi', $assessmentId, $studentId, $newAttemptNo, $questionOrderJson, $timeLimit);
                $ok = $ins->execute();
                $newAttemptId = (int) $conn->insert_id;
                $ins->close();
                if ($ok && $newAttemptId > 0) {
                    $_SESSION['flash_message'] = 'Attempt started. Good luck.';
                    $_SESSION['flash_type'] = 'success';
                    header('Location: student-quiz-attempt.php?assessment_id=' . $assessmentId . '&attempt_id=' . $newAttemptId);
                    exit;
                }
            }
        }

        $_SESSION['flash_message'] = 'Unable to start attempt.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: student-quiz-attempt.php?assessment_id=' . $assessmentId);
        exit;
    }

    if ($action === 'submit_attempt') {
        $attemptId = isset($_POST['attempt_id']) ? (int) $_POST['attempt_id'] : 0;
        $attempt = ($attemptId > 0 && isset($attemptsById[$attemptId]) && is_array($attemptsById[$attemptId])) ? $attemptsById[$attemptId] : null;

        if (!is_array($attempt) || strtolower(trim((string) ($attempt['status'] ?? ''))) !== 'in_progress') {
            $_SESSION['flash_message'] = 'No active attempt found to submit.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: student-quiz-attempt.php?assessment_id=' . $assessmentId);
            exit;
        }
        if ($requireProofUpload) {
            $proofCountNow = sq_count_student_proof_files($conn, $assessmentId, $studentId);
            if ($proofCountNow <= 0) {
                $_SESSION['flash_message'] = 'Upload at least one proof file/photo before submitting this quiz.';
                $_SESSION['flash_type'] = 'warning';
                header('Location: student-quiz-attempt.php?assessment_id=' . $assessmentId . '&attempt_id=' . $attemptId);
                exit;
            }
        }

        $postedResponses = (isset($_POST['response']) && is_array($_POST['response'])) ? $_POST['response'] : [];
        $forceAutosubmit = isset($_POST['force_autosubmit']) && (string) $_POST['force_autosubmit'] === '1';
        $finalStatus = ($forceAutosubmit || sq_attempt_is_expired($attempt, $assessment, time())) ? 'autosubmitted' : 'submitted';

        $attempt['assessment_id'] = $assessmentId;
        $attempt['student_id'] = $studentId;

        [$okSubmit, $submitResult] = sq_finalize_attempt(
            $conn,
            $attempt,
            $assessment,
            $allQuestionsById,
            $postedResponses,
            $finalStatus
        );

        if ($okSubmit) {
            $scaled = is_array($submitResult) ? (float) ($submitResult['score_scaled'] ?? 0) : 0.0;
            $raw = is_array($submitResult) ? (float) ($submitResult['score_raw'] ?? 0) : 0.0;
            $_SESSION['flash_message'] = 'Attempt submitted. Score: ' . sq_fmt2($scaled) . ' (raw ' . sq_fmt2($raw) . ').';
            $_SESSION['flash_type'] = ($finalStatus === 'autosubmitted') ? 'warning' : 'success';
            header('Location: student-quiz-attempt.php?assessment_id=' . $assessmentId . '&attempt_id=' . $attemptId);
            exit;
        }

        $_SESSION['flash_message'] = is_string($submitResult) ? $submitResult : 'Unable to submit attempt.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: student-quiz-attempt.php?assessment_id=' . $assessmentId);
        exit;
    }

    $_SESSION['flash_message'] = 'Invalid request.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: student-quiz-attempt.php?assessment_id=' . $assessmentId);
    exit;
}

[$attemptsList, $attemptsById] = sq_load_attempts($conn, $assessmentId, $studentId);
[$maxAttemptNo, $completedAttempts, $inProgressAttempt, $latestSubmittedAttemptTs] = sq_summarize_attempts($attemptsList);

$remainingStarts = $attemptsAllowed - $maxAttemptNo;
if ($remainingStarts < 0) $remainingStarts = 0;

$currentScore = sq_load_current_score($conn, $assessmentId, $studentId);

$isPassed = null;
if ($currentScore !== null && $gradeToPass !== null) {
    $isPassed = ((float) $currentScore >= (float) $gradeToPass);
}
$cooldownRemainingSeconds = 0;
$cooldownUntilText = '';
if ($accessCooldownMinutes > 0 && $latestSubmittedAttemptTs !== null) {
    $cooldownUntilTs = (int) $latestSubmittedAttemptTs + ($accessCooldownMinutes * 60);
    $nowForCooldownTs = time();
    if ($cooldownUntilTs > $nowForCooldownTs) {
        $cooldownRemainingSeconds = (int) ($cooldownUntilTs - $nowForCooldownTs);
        $cooldownUntilText = date('Y-m-d H:i', $cooldownUntilTs);
    }
}
$isBlockedByPassLock = ($accessLockWhenPassed && $isPassed === true);
$isBlockedByCooldown = ($cooldownRemainingSeconds > 0);
$overallFeedbackText = sq_pick_overall_feedback_text(
    $overallFeedbackBands,
    $currentScore,
    (float) ($assessment['max_score'] ?? 0)
);

$viewAttemptId = isset($_GET['attempt_id']) ? (int) $_GET['attempt_id'] : 0;
$reviewAttempt = null;
if ($viewAttemptId > 0 && isset($attemptsById[$viewAttemptId]) && is_array($attemptsById[$viewAttemptId])) {
    $candidate = $attemptsById[$viewAttemptId];
    $status = strtolower(trim((string) ($candidate['status'] ?? '')));
    if (in_array($status, ['submitted', 'autosubmitted'], true)) {
        $reviewAttempt = $candidate;
    }
}

$activeAttempt = is_array($inProgressAttempt) ? $inProgressAttempt : null;
$activeRemainingSeconds = null;
$activeQuestionList = [];
$activeResponseMap = [];
$activeQuestionPageById = [];
$activeQuestionPageCount = 1;
if (is_array($activeAttempt)) {
    $activeRemainingSeconds = sq_attempt_remaining_seconds($activeAttempt, $assessment, time());

    $order = sq_parse_int_list_json((string) ($activeAttempt['question_order_json'] ?? ''));
    if (count($order) === 0) {
        $order = sq_build_question_order($activeQuestionsById, false);
    }

    foreach ($order as $qid) {
        $qid = (int) $qid;
        if ($qid <= 0 || !isset($allQuestionsById[$qid]) || !is_array($allQuestionsById[$qid])) continue;
        $activeQuestionList[$qid] = $allQuestionsById[$qid];
    }

    $activeResponseMap = sq_load_attempt_response_map($conn, (int) ($activeAttempt['id'] ?? 0));

    $activeIds = array_keys($activeQuestionList);
    $activeIds = array_values(array_filter(array_map('intval', $activeIds), static function ($v) {
        return $v > 0;
    }));
    if ($questionsPerPage > 0 && count($activeIds) > 0) {
        $chunks = array_chunk($activeIds, $questionsPerPage);
        $activeQuestionPageCount = max(1, (int) count($chunks));
        foreach ($chunks as $pageIdx => $chunkIds) {
            $pageNo = (int) $pageIdx + 1;
            foreach ($chunkIds as $qidInPage) {
                $activeQuestionPageById[(int) $qidInPage] = $pageNo;
            }
        }
    } else {
        $activeQuestionPageCount = 1;
        foreach ($activeIds as $qidInPage) {
            $activeQuestionPageById[(int) $qidInPage] = 1;
        }
    }
}

$reviewAnswerMap = [];
$reviewQuestionList = [];
if (is_array($reviewAttempt)) {
    $reviewAnswerMap = sq_load_attempt_response_map($conn, (int) ($reviewAttempt['id'] ?? 0));
    $order = sq_parse_int_list_json((string) ($reviewAttempt['question_order_json'] ?? ''));
    if (count($order) === 0) {
        $order = sq_build_question_order($allQuestionsById, false);
    }
    foreach ($order as $qid) {
        $qid = (int) $qid;
        if ($qid <= 0 || !isset($allQuestionsById[$qid]) || !is_array($allQuestionsById[$qid])) continue;
        $reviewQuestionList[$qid] = $allQuestionsById[$qid];
    }
}

$nowTs = time();
$scheduleMessage = '';
$isQuizOpen = sq_is_quiz_open((string) ($assessment['open_at'] ?? ''), (string) ($assessment['close_at'] ?? ''), $nowTs, $scheduleMessage);

$startBlockedReason = '';
$startBlockedReasonType = 'muted';
if (!$isQuizOpen) {
    $startBlockedReason = $scheduleMessage !== '' ? $scheduleMessage : 'Quiz is currently closed.';
    $startBlockedReasonType = 'warning';
} elseif (count($activeQuestionsById) === 0) {
    $startBlockedReason = 'This quiz has no active questions yet.';
} elseif ($remainingStarts <= 0) {
    $startBlockedReason = 'Attempt limit reached.';
} elseif ($isBlockedByPassLock) {
    $startBlockedReason = 'New attempts are locked because you already reached the passing grade.';
    $startBlockedReasonType = 'info';
} elseif ($isBlockedByCooldown) {
    $startBlockedReason = 'Cooldown is active. Wait ' . sq_format_duration_short($cooldownRemainingSeconds) . ($cooldownUntilText !== '' ? ' (until ' . $cooldownUntilText . ').' : '.');
    $startBlockedReasonType = 'warning';
}

$canStart = (
    $assessmentMode === 'quiz' &&
    $isQuizOpen &&
    count($activeQuestionsById) > 0 &&
    !is_array($activeAttempt) &&
    $remainingStarts > 0 &&
    !$isBlockedByPassLock &&
    !$isBlockedByCooldown
);
?>

<head>
    <title>Quiz Attempt | E-Record</title>
    <?php include '../layouts/title-meta.php'; ?>
    <?php include '../layouts/head-css.php'; ?>
    <style>
        .sq-question-card {
            border: 1px solid rgba(0, 0, 0, 0.08);
        }

        .sq-countdown {
            font-weight: 700;
            letter-spacing: 0.03em;
        }
    </style>
</head>

<body>
    <div class="wrapper">
        <?php include '../layouts/menu.php'; ?>

        <div class="content-page">
            <div class="content">
                <div class="container-fluid">

                    <div class="row">
                        <div class="col-12">
                            <div class="page-title-box">
                                <div class="page-title-right">
                                    <ol class="breadcrumb m-0">
                                        <li class="breadcrumb-item"><a href="student-dashboard.php">Student</a></li>
                                        <li class="breadcrumb-item active">Quiz Attempt</li>
                                    </ol>
                                </div>
                                <h4 class="page-title">Quiz Attempt</h4>
                            </div>
                        </div>
                    </div>

                    <?php if ($flash): ?>
                        <div class="alert alert-<?php echo sq_h($flashType); ?>"><?php echo sq_h($flash); ?></div>
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                                        <div>
                                            <h5 class="mb-1"><?php echo sq_h((string) ($assessment['assessment_name'] ?? 'Assessment')); ?></h5>
                                            <div class="text-muted small">
                                                <?php echo sq_h((string) ($assessment['subject_name'] ?? '')); ?>
                                                (<?php echo sq_h((string) ($assessment['subject_code'] ?? '')); ?>)
                                                | <?php echo sq_h((string) ($assessment['section'] ?? '')); ?>
                                                | <?php echo sq_h((string) ($assessment['academic_year'] ?? '')); ?>, <?php echo sq_h((string) ($assessment['semester'] ?? '')); ?>
                                                | <?php echo sq_h($termLabel); ?>
                                            </div>
                                            <div class="mt-2 d-flex flex-wrap gap-2">
                                                <span class="badge bg-light text-dark border">
                                                    <?php echo sq_h((string) ($assessment['component_name'] ?? 'Component')); ?>
                                                </span>
                                                <span class="badge bg-secondary-subtle text-secondary">
                                                    <?php echo sq_h((string) ($assessment['category_name'] ?? 'Category')); ?>
                                                </span>
                                                <?php if ($assessmentMode === 'quiz'): ?>
                                                    <span class="badge bg-primary-subtle text-primary">Quiz Mode</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary-subtle text-secondary">Manual Assessment</span>
                                                <?php endif; ?>
                                                <?php if ($moduleType !== 'assessment'): ?>
                                                    <span class="badge <?php echo sq_h($moduleClass); ?>"><?php echo sq_h($moduleLabel); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="text-end small">
                                            <div>Max Score: <strong><?php echo sq_h(sq_fmt2((float) ($assessment['max_score'] ?? 0))); ?></strong></div>
                                            <div>Attempts Used: <strong><?php echo (int) $maxAttemptNo; ?></strong> / <?php echo (int) $attemptsAllowed; ?></div>
                                            <div>Completed: <strong><?php echo (int) $completedAttempts; ?></strong></div>
                                            <div>Remaining Starts: <strong><?php echo (int) $remainingStarts; ?></strong></div>
                                            <div class="mt-1">Current Selected Score: <strong><?php echo $currentScore === null ? '-' : sq_h(sq_fmt2((float) $currentScore)); ?></strong></div>
                                            <?php if ($gradeToPass !== null): ?>
                                                <div>Grade To Pass: <strong><?php echo sq_h(sq_fmt2((float) $gradeToPass)); ?></strong></div>
                                                <div>
                                                    Status:
                                                    <?php if ($isPassed === true): ?>
                                                        <span class="badge bg-success">Passed</span>
                                                    <?php elseif ($isPassed === false): ?>
                                                        <span class="badge bg-danger">Not yet passed</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">Pending</span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="mt-3 text-muted small">
                                        <div>Open: <strong><?php echo sq_h(sq_format_datetime((string) ($assessment['open_at'] ?? ''))); ?></strong></div>
                                        <div>Close: <strong><?php echo sq_h(sq_format_datetime((string) ($assessment['close_at'] ?? ''))); ?></strong></div>
                                        <div>Time Limit: <strong><?php echo ((int) ($assessment['time_limit_minutes'] ?? 0)) > 0 ? (int) ($assessment['time_limit_minutes'] ?? 0) . ' minute(s)' : 'No time limit'; ?></strong></div>
                                        <div>Grading Method: <strong><?php echo sq_h(ucfirst((string) ($assessment['grading_method'] ?? 'highest'))); ?></strong></div>
                                        <?php if ($accessLockWhenPassed): ?>
                                            <div>Pass Lock: <strong>Enabled</strong></div>
                                        <?php endif; ?>
                                        <?php if ($accessCooldownMinutes > 0): ?>
                                            <div>Attempt Cooldown: <strong><?php echo (int) $accessCooldownMinutes; ?> minute(s)</strong></div>
                                            <?php if ($cooldownRemainingSeconds > 0): ?>
                                                <div>Cooldown Status: <strong>Wait <?php echo sq_h(sq_format_duration_short($cooldownRemainingSeconds)); ?><?php echo $cooldownUntilText !== '' ? ' (until ' . sq_h($cooldownUntilText) . ')' : ''; ?></strong></div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <?php if ($requireProofUpload): ?>
                                            <div>
                                                Proof Upload Requirement:
                                                <strong><?php echo $hasRequiredProofUpload ? 'Satisfied' : 'Missing'; ?></strong>
                                                (<?php echo (int) $studentProofUploadCount; ?> file<?php echo $studentProofUploadCount === 1 ? '' : 's'; ?>)
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($safeExamMode !== 'off'): ?>
                                            <div>
                                                Safe Exam Policy:
                                                <strong><?php echo sq_h(ucfirst($safeExamMode)); ?></strong>
                                                <?php if ($safeRequireFullscreen): ?>, fullscreen required<?php endif; ?>
                                                <?php if ($safeBlockShortcuts): ?>, shortcut blocking enabled<?php endif; ?>
                                                <?php if ($safeAutoSubmitOnBlur): ?>, auto-submit on focus loss<?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (trim((string) ($assessment['instructions'] ?? '')) !== ''): ?>
                                            <div class="mt-2"><strong>Instructions:</strong></div>
                                            <div><?php echo nl2br(sq_h((string) ($assessment['instructions'] ?? ''))); ?></div>
                                        <?php endif; ?>
                                        <?php if ($moduleSummary !== ''): ?>
                                            <div class="mt-2"><strong>Module Summary:</strong></div>
                                            <div><?php echo nl2br(sq_h($moduleSummary)); ?></div>
                                        <?php endif; ?>
                                        <?php if ($moduleLaunchHref !== ''): ?>
                                            <div class="mt-2">
                                                <a class="btn btn-sm btn-outline-info" href="<?php echo sq_h($moduleLaunchHref); ?>" target="_blank" rel="noopener noreferrer">
                                                    Open Module Link
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($overallFeedbackText !== ''): ?>
                                            <div class="mt-2"><strong>Overall Feedback:</strong></div>
                                            <div><?php echo nl2br(sq_h($overallFeedbackText)); ?></div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="mt-3 d-flex flex-wrap gap-2">
                                        <a class="btn btn-sm btn-outline-secondary" href="student-dashboard.php">
                                            <i class="ri-arrow-left-line me-1" aria-hidden="true"></i>
                                            Back to Dashboard
                                        </a>
                                        <a class="btn btn-sm btn-outline-secondary" href="student-assessment-module.php?assessment_id=<?php echo (int) $assessmentId; ?>">
                                            <i class="ri-image-add-line me-1" aria-hidden="true"></i>
                                            Upload Proof
                                        </a>
                                        <?php if ($assessmentMode === 'quiz'): ?>
                                            <?php if ($isQuizOpen): ?>
                                                <span class="badge bg-success-subtle text-success align-self-center">Quiz window is open</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning-subtle text-warning align-self-center"><?php echo sq_h($scheduleMessage !== '' ? $scheduleMessage : 'Quiz is not open.'); ?></span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($assessmentMode !== 'quiz'): ?>
                        <div class="row">
                            <div class="col-12">
                                <div class="alert alert-info">
                                    This assessment is manual. Your score is recorded by your teacher from the class record.
                                </div>
                            </div>
                        </div>
                    <?php else: ?>

                        <?php if (count($activeQuestionsById) === 0): ?>
                            <div class="row">
                                <div class="col-12">
                                    <div class="alert alert-warning">This quiz has no active questions yet.</div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="row g-3">
                            <div class="col-xl-8">
                                <?php if (is_array($activeAttempt)): ?>
                                    <div class="card">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                                                <h5 class="mb-0">Attempt #<?php echo (int) ($activeAttempt['attempt_no'] ?? 0); ?> In Progress</h5>
                                                <div class="text-end">
                                                    <?php if ($activeRemainingSeconds !== null): ?>
                                                        <div class="small text-muted">Time Remaining</div>
                                                        <div class="sq-countdown fs-5 text-danger" id="sqCountdown">--:--:--</div>
                                                    <?php else: ?>
                                                        <span class="badge bg-info-subtle text-info">No countdown limit</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>

                                            <?php if ($safeExamMode !== 'off'): ?>
                                                <div class="alert <?php echo $safeExamMode === 'required' ? 'alert-warning' : 'alert-info'; ?> py-2 small">
                                                    Safe Exam Policy is <strong><?php echo sq_h(ucfirst($safeExamMode)); ?></strong>. Browser lockdown is best-effort in regular browsers.
                                                    <?php if ($safeRequireFullscreen): ?> Fullscreen is required for this attempt.<?php endif; ?>
                                                    <?php if ($safeAutoSubmitOnBlur): ?> Leaving the quiz tab/window may auto-submit after <?php echo (int) $safeBlurGraceSeconds; ?> second(s).<?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($safeRequireFullscreen): ?>
                                                <div class="alert <?php echo $safeExamMode === 'required' ? 'alert-warning' : 'alert-info'; ?> py-2 small mb-3" id="sqFullscreenAlert">
                                                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                                        <span>Fullscreen is required while answering this quiz.</span>
                                                        <button type="button" class="btn btn-sm btn-outline-dark" id="sqEnterFullscreenBtn">Enter Fullscreen</button>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($safeAutoSubmitOnBlur): ?>
                                                <div class="alert alert-danger py-2 small mb-3 d-none" id="sqBlurAlert">
                                                    Focus lost. Auto-submit in <strong><span id="sqBlurCountdown"><?php echo (int) $safeBlurGraceSeconds; ?></span>s</strong> if focus is not restored.
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($safeBlockShortcuts): ?>
                                                <div class="small text-muted mb-3">Common keyboard shortcuts and copy/paste are restricted during this attempt.</div>
                                            <?php endif; ?>
                                            <?php if ($requireProofUpload && !$hasRequiredProofUpload): ?>
                                                <div class="alert alert-warning py-2 small mb-3">
                                                    Proof upload is required before final submit. Upload at least one file from the <strong>Upload Proof</strong> button above.
                                                </div>
                                            <?php endif; ?>

                                            <?php if (count($activeQuestionList) === 0): ?>
                                                <div class="alert alert-warning mb-0">
                                                    This attempt has no available questions to render.
                                                </div>
                                                <form method="post" class="mt-3">
                                                    <input type="hidden" name="csrf_token" value="<?php echo sq_h(csrf_token()); ?>">
                                                    <input type="hidden" name="action" value="submit_attempt">
                                                    <input type="hidden" name="attempt_id" value="<?php echo (int) ($activeAttempt['id'] ?? 0); ?>">
                                                    <button class="btn btn-primary" type="submit">
                                                        Submit Empty Attempt
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <form method="post" id="sqAttemptForm">
                                                    <input type="hidden" name="csrf_token" value="<?php echo sq_h(csrf_token()); ?>">
                                                    <input type="hidden" name="action" value="submit_attempt">
                                                    <input type="hidden" name="attempt_id" value="<?php echo (int) ($activeAttempt['id'] ?? 0); ?>">
                                                    <input type="hidden" name="force_autosubmit" value="0" id="sqForceAutoSubmit">

                                                    <?php $qNo = 1; ?>
                                                    <?php foreach ($activeQuestionList as $qid => $q): ?>
                                                        <?php
                                                        $type = strtolower((string) ($q['question_type'] ?? 'multiple_choice'));
                                                        $resp = isset($activeResponseMap[$qid]) && is_array($activeResponseMap[$qid])
                                                            ? (string) ($activeResponseMap[$qid]['response_text'] ?? '')
                                                            : '';
                                                        $pageNo = (int) ($activeQuestionPageById[(int) $qid] ?? 1);
                                                        ?>
                                                        <div class="card sq-question-card mb-3 sq-question-item" data-page="<?php echo (int) $pageNo; ?>">
                                                            <div class="card-body">
                                                                <div class="d-flex justify-content-between gap-2 flex-wrap">
                                                                    <h6 class="mb-1">Q<?php echo (int) $qNo; ?>. <?php echo sq_h((string) ($q['question_text'] ?? 'Question')); ?></h6>
                                                                    <span class="badge bg-light text-dark border"><?php echo sq_h(sq_fmt2((float) ($q['default_mark'] ?? 0))); ?> pt(s)</span>
                                                                </div>

                                                                <?php if ($type === 'multiple_choice'): ?>
                                                                    <?php
                                                                    $choicesRaw = is_array($q['options']['choices'] ?? null) ? $q['options']['choices'] : [];
                                                                    $choices = !empty($assessment['shuffle_choices'])
                                                                        ? sq_sort_choices_for_attempt($choicesRaw, (int) ($activeAttempt['id'] ?? 0), (int) $qid)
                                                                        : [];
                                                                    if (count($choices) === 0) {
                                                                        foreach ($choicesRaw as $idx => $choice) {
                                                                            if (!is_array($choice)) continue;
                                                                            $choices[] = [
                                                                                'orig_idx' => (int) $idx,
                                                                                'text' => (string) ($choice['text'] ?? ''),
                                                                                'is_correct' => ((int) ($choice['is_correct'] ?? 0)) === 1 ? 1 : 0,
                                                                            ];
                                                                        }
                                                                    }
                                                                    ?>
                                                                    <div class="mt-2">
                                                                        <?php foreach ($choices as $c): ?>
                                                                            <?php
                                                                            $origIdx = (int) ($c['orig_idx'] ?? 0);
                                                                            $checked = ($resp !== '' && $resp === (string) $origIdx) ? 'checked' : '';
                                                                            ?>
                                                                            <div class="form-check mt-1">
                                                                                <input class="form-check-input" type="radio" name="response[<?php echo (int) $qid; ?>]" id="q_<?php echo (int) $qid; ?>_<?php echo (int) $origIdx; ?>" value="<?php echo (int) $origIdx; ?>" <?php echo $checked; ?>>
                                                                                <label class="form-check-label" for="q_<?php echo (int) $qid; ?>_<?php echo (int) $origIdx; ?>">
                                                                                    <?php echo sq_h((string) ($c['text'] ?? '')); ?>
                                                                                </label>
                                                                            </div>
                                                                        <?php endforeach; ?>
                                                                    </div>
                                                                <?php elseif ($type === 'true_false'): ?>
                                                                    <?php $respLower = strtolower($resp); ?>
                                                                    <div class="mt-2">
                                                                        <div class="form-check mt-1">
                                                                            <input class="form-check-input" type="radio" name="response[<?php echo (int) $qid; ?>]" id="q_<?php echo (int) $qid; ?>_true" value="true" <?php echo $respLower === 'true' ? 'checked' : ''; ?>>
                                                                            <label class="form-check-label" for="q_<?php echo (int) $qid; ?>_true">True</label>
                                                                        </div>
                                                                        <div class="form-check mt-1">
                                                                            <input class="form-check-input" type="radio" name="response[<?php echo (int) $qid; ?>]" id="q_<?php echo (int) $qid; ?>_false" value="false" <?php echo $respLower === 'false' ? 'checked' : ''; ?>>
                                                                            <label class="form-check-label" for="q_<?php echo (int) $qid; ?>_false">False</label>
                                                                        </div>
                                                                    </div>
                                                                <?php else: ?>
                                                                    <div class="mt-2">
                                                                        <input class="form-control" type="text" name="response[<?php echo (int) $qid; ?>]" value="<?php echo sq_h($resp); ?>" placeholder="Type your answer">
                                                                    </div>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                        <?php $qNo++; ?>
                                                    <?php endforeach; ?>

                                                    <?php if ($activeQuestionPageCount > 1): ?>
                                                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                                                            <div class="btn-group" role="group">
                                                                <button type="button" class="btn btn-outline-secondary btn-sm" id="sqPrevPageBtn">Previous</button>
                                                                <button type="button" class="btn btn-outline-secondary btn-sm" id="sqNextPageBtn">Next</button>
                                                            </div>
                                                            <div class="small text-muted" id="sqPageIndicator">Page 1 of <?php echo (int) $activeQuestionPageCount; ?></div>
                                                        </div>
                                                        <?php if ($navigationMethod === 'free'): ?>
                                                            <div class="mb-3" id="sqPageJumpWrap">
                                                                <?php for ($p = 1; $p <= $activeQuestionPageCount; $p++): ?>
                                                                    <button type="button" class="btn btn-sm btn-outline-primary me-1 mb-1 sq-page-jump-btn" data-target-page="<?php echo (int) $p; ?>">
                                                                        <?php echo (int) $p; ?>
                                                                    </button>
                                                                <?php endfor; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    <?php endif; ?>

                                                    <div class="d-flex gap-2 flex-wrap">
                                                        <button class="btn btn-primary" type="submit" id="sqSubmitBtn">
                                                            <i class="ri-send-plane-2-line me-1" aria-hidden="true"></i>
                                                            Submit Attempt
                                                        </button>
                                                        <span class="text-muted small align-self-center">Submitting will auto-check objective items and update your score.</span>
                                                    </div>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="card">
                                        <div class="card-body">
                                            <h5 class="mb-2">Ready to start?</h5>
                                            <p class="text-muted mb-3">
                                                Start a new attempt when the quiz window is open and you still have remaining attempts.
                                            </p>

                                            <form method="post" class="mt-2" style="max-width: 340px;">
                                                <input type="hidden" name="csrf_token" value="<?php echo sq_h(csrf_token()); ?>">
                                                <input type="hidden" name="action" value="start_attempt">
                                                <?php if ($requirePassword !== ''): ?>
                                                    <div class="mb-2">
                                                        <label class="form-label">Quiz Password</label>
                                                        <input class="form-control" type="password" name="attempt_password" autocomplete="off" placeholder="Enter quiz password">
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($safeExamMode !== 'off'): ?>
                                                    <div class="alert <?php echo $safeExamMode === 'required' ? 'alert-warning' : 'alert-info'; ?> py-2 small">
                                                        Safe Exam Policy: <strong><?php echo sq_h(ucfirst($safeExamMode)); ?></strong>.
                                                        <?php if ($safeRequireFullscreen): ?> Fullscreen is required during the attempt.<?php endif; ?>
                                                        <?php if ($safeAutoSubmitOnBlur): ?> Leaving the quiz tab/window may auto-submit.<?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                                <button class="btn btn-primary" type="submit" <?php echo $canStart ? '' : 'disabled'; ?>>
                                                    <i class="ri-play-circle-line me-1" aria-hidden="true"></i>
                                                    Start Attempt
                                                </button>
                                            </form>

                                            <?php if (!$canStart): ?>
                                                <div class="small mt-2 text-<?php echo sq_h($startBlockedReasonType); ?>">
                                                    <?php echo sq_h($startBlockedReason !== '' ? $startBlockedReason : 'Start is currently unavailable.'); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="col-xl-4">
                                <div class="card">
                                    <div class="card-body">
                                        <h5 class="mb-2">Attempt History</h5>
                                        <?php if (count($attemptsList) === 0): ?>
                                            <div class="text-muted">No attempts yet.</div>
                                        <?php else: ?>
                                            <div class="table-responsive">
                                                <table class="table table-sm table-striped mb-0">
                                                    <thead>
                                                        <tr>
                                                            <th>#</th>
                                                            <th>Status</th>
                                                            <th>Score</th>
                                                            <th></th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($attemptsList as $at): ?>
                                                            <?php
                                                            $status = strtolower(trim((string) ($at['status'] ?? '')));
                                                            $badgeClass = 'bg-secondary';
                                                            if ($status === 'submitted') $badgeClass = 'bg-success';
                                                            elseif ($status === 'autosubmitted') $badgeClass = 'bg-warning';
                                                            elseif ($status === 'in_progress') $badgeClass = 'bg-info';
                                                            ?>
                                                            <tr>
                                                                <td><?php echo (int) ($at['attempt_no'] ?? 0); ?></td>
                                                                <td><span class="badge <?php echo sq_h($badgeClass); ?>"><?php echo sq_h(ucfirst($status)); ?></span></td>
                                                                <td>
                                                                    <?php
                                                                    $scaled = $at['score_scaled'] ?? null;
                                                                    echo ($scaled !== null && is_numeric($scaled)) ? sq_h(sq_fmt2((float) $scaled)) : '-';
                                                                    ?>
                                                                </td>
                                                                <td class="text-end">
                                                                    <?php if (in_array($status, ['submitted', 'autosubmitted'], true)): ?>
                                                                        <a class="btn btn-sm btn-outline-primary" href="student-quiz-attempt.php?assessment_id=<?php echo (int) $assessmentId; ?>&attempt_id=<?php echo (int) ($at['id'] ?? 0); ?>#review">Review</a>
                                                                    <?php elseif ($status === 'in_progress'): ?>
                                                                        <a class="btn btn-sm btn-outline-info" href="student-quiz-attempt.php?assessment_id=<?php echo (int) $assessmentId; ?>">Resume</a>
                                                                    <?php endif; ?>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if (is_array($reviewAttempt)): ?>
                            <div class="row" id="review">
                                <div class="col-12">
                                    <div class="card">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                                                <h5 class="mb-0">Attempt #<?php echo (int) ($reviewAttempt['attempt_no'] ?? 0); ?> Review</h5>
                                                <div class="text-end small text-muted">
                                                    <div>Status: <strong><?php echo sq_h(ucfirst((string) ($reviewAttempt['status'] ?? 'submitted'))); ?></strong></div>
                                                    <div>Submitted: <strong><?php echo sq_h(sq_format_datetime((string) ($reviewAttempt['submitted_at'] ?? ''))); ?></strong></div>
                                                    <?php if ($reviewShowMarks): ?>
                                                        <div>Score: <strong><?php echo sq_h(sq_fmt2((float) ($reviewAttempt['score_scaled'] ?? 0))); ?></strong></div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <?php if (!$reviewShowResponse || !$reviewShowMarks || !$reviewShowCorrect): ?>
                                                <div class="alert alert-light border small py-2">
                                                    Review visibility is restricted by quiz settings.
                                                </div>
                                            <?php endif; ?>

                                            <?php if (count($reviewQuestionList) === 0): ?>
                                                <div class="text-muted">No review items available.</div>
                                            <?php else: ?>
                                                <?php $rNo = 1; ?>
                                                <?php foreach ($reviewQuestionList as $qid => $q): ?>
                                                    <?php
                                                    $ans = isset($reviewAnswerMap[$qid]) ? $reviewAnswerMap[$qid] : null;
                                                    $respText = is_array($ans) ? (string) ($ans['response_text'] ?? '') : '';
                                                    $isCorrect = is_array($ans) && isset($ans['is_correct']) ? (int) $ans['is_correct'] : null;
                                                    $awarded = is_array($ans) ? (float) ($ans['awarded_mark'] ?? 0) : 0;
                                                    $maxMark = is_array($ans) ? (float) ($ans['max_mark'] ?? 0) : (float) ($q['default_mark'] ?? 0);
                                                    $qType = strtolower((string) ($q['question_type'] ?? 'multiple_choice'));
                                                    $correctText = '';
                                                    if ($qType === 'multiple_choice') {
                                                        $choices = is_array($q['options']['choices'] ?? null) ? $q['options']['choices'] : [];
                                                        foreach ($choices as $choice) {
                                                            if (!is_array($choice)) continue;
                                                            if (((int) ($choice['is_correct'] ?? 0)) === 1) {
                                                                $correctText = (string) ($choice['text'] ?? '');
                                                                break;
                                                            }
                                                        }
                                                    } elseif ($qType === 'true_false') {
                                                        $correctText = (string) ($q['answer_text'] ?? '');
                                                    } else {
                                                        $accepted = is_array($q['options']['accepted_answers'] ?? null) ? $q['options']['accepted_answers'] : [];
                                                        if (count($accepted) === 0 && trim((string) ($q['answer_text'] ?? '')) !== '') {
                                                            $accepted[] = (string) ($q['answer_text'] ?? '');
                                                        }
                                                        $correctText = implode(', ', array_map('strval', $accepted));
                                                    }
                                                    ?>
                                                    <div class="card sq-question-card mb-2">
                                                        <div class="card-body">
                                                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                                                <div>
                                                                    <div class="fw-semibold">Q<?php echo (int) $rNo; ?>. <?php echo sq_h((string) ($q['question_text'] ?? 'Question')); ?></div>
                                                                    <?php if ($reviewShowResponse): ?>
                                                                        <div class="small text-muted mt-1">Your answer: <?php echo $respText !== '' ? sq_h($respText) : '<em>Blank</em>'; ?></div>
                                                                    <?php endif; ?>
                                                                    <?php if ($reviewShowCorrect): ?>
                                                                        <div class="small text-muted">Correct answer: <?php echo $correctText !== '' ? sq_h($correctText) : '-'; ?></div>
                                                                    <?php endif; ?>
                                                                </div>
                                                                <div class="text-end">
                                                                    <?php if ($reviewShowCorrect): ?>
                                                                        <?php if ($isCorrect === 1): ?>
                                                                            <span class="badge bg-success">Correct</span>
                                                                        <?php elseif ($isCorrect === 0): ?>
                                                                            <span class="badge bg-danger">Wrong</span>
                                                                        <?php else: ?>
                                                                            <span class="badge bg-secondary">No check</span>
                                                                        <?php endif; ?>
                                                                    <?php else: ?>
                                                                        <span class="badge bg-secondary">Submitted</span>
                                                                    <?php endif; ?>
                                                                    <?php if ($reviewShowMarks): ?>
                                                                        <div class="small mt-1"><?php echo sq_h(sq_fmt2($awarded)); ?> / <?php echo sq_h(sq_fmt2($maxMark)); ?></div>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <?php $rNo++; ?>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                    <?php endif; ?>

                </div>
            </div>

            <?php include '../layouts/footer.php'; ?>
        </div>
    </div>

    <?php include '../layouts/right-sidebar.php'; ?>
    <?php include '../layouts/footer-scripts.php'; ?>
    <script src="assets/js/app.min.js"></script>
    <script>
        (function () {
            var remaining = <?php echo $activeRemainingSeconds === null ? 'null' : (int) $activeRemainingSeconds; ?>;
            if (remaining === null) return;

            var timer = document.getElementById('sqCountdown');
            var form = document.getElementById('sqAttemptForm');
            var submitBtn = document.getElementById('sqSubmitBtn');
            var forceAuto = document.getElementById('sqForceAutoSubmit');
            if (!timer || !form) return;

            function pad(v) {
                v = parseInt(v, 10) || 0;
                return v < 10 ? '0' + v : '' + v;
            }

            function render(sec) {
                if (sec < 0) sec = 0;
                var h = Math.floor(sec / 3600);
                var m = Math.floor((sec % 3600) / 60);
                var s = sec % 60;
                timer.textContent = pad(h) + ':' + pad(m) + ':' + pad(s);
            }

            function tick() {
                render(remaining);
                if (remaining <= 0) {
                    if (forceAuto) forceAuto.value = '1';
                    if (submitBtn) submitBtn.disabled = true;
                    form.submit();
                    return;
                }
                remaining -= 1;
                window.setTimeout(tick, 1000);
            }

            tick();
        })();

        (function () {
            var items = document.querySelectorAll('.sq-question-item[data-page]');
            if (!items || items.length === 0) return;

            var pageCount = <?php echo (int) $activeQuestionPageCount; ?>;
            if (!pageCount || pageCount < 1) pageCount = 1;
            var navMethod = <?php echo json_encode($navigationMethod); ?>;
            var currentPage = 1;

            var prevBtn = document.getElementById('sqPrevPageBtn');
            var nextBtn = document.getElementById('sqNextPageBtn');
            var indicator = document.getElementById('sqPageIndicator');
            var jumpButtons = document.querySelectorAll('.sq-page-jump-btn');

            function toInt(v, d) {
                var n = parseInt(v, 10);
                return isNaN(n) ? d : n;
            }

            function renderPage(pageNo) {
                if (pageNo < 1) pageNo = 1;
                if (pageNo > pageCount) pageNo = pageCount;
                currentPage = pageNo;

                for (var i = 0; i < items.length; i++) {
                    var el = items[i];
                    var p = toInt(el.getAttribute('data-page'), 1);
                    el.style.display = (p === currentPage) ? '' : 'none';
                }

                if (indicator) indicator.textContent = 'Page ' + currentPage + ' of ' + pageCount;
                if (prevBtn) prevBtn.disabled = currentPage <= 1 || navMethod === 'sequential';
                if (nextBtn) nextBtn.disabled = currentPage >= pageCount;

                if (jumpButtons && jumpButtons.length > 0) {
                    for (var j = 0; j < jumpButtons.length; j++) {
                        var jb = jumpButtons[j];
                        var target = toInt(jb.getAttribute('data-target-page'), 1);
                        if (target === currentPage) {
                            jb.classList.remove('btn-outline-primary');
                            jb.classList.add('btn-primary');
                        } else {
                            jb.classList.remove('btn-primary');
                            jb.classList.add('btn-outline-primary');
                        }
                    }
                }
            }

            if (prevBtn) {
                prevBtn.addEventListener('click', function () {
                    if (navMethod === 'sequential') return;
                    renderPage(currentPage - 1);
                });
            }
            if (nextBtn) {
                nextBtn.addEventListener('click', function () {
                    renderPage(currentPage + 1);
                });
            }
            if (jumpButtons && jumpButtons.length > 0) {
                for (var k = 0; k < jumpButtons.length; k++) {
                    jumpButtons[k].addEventListener('click', function () {
                        var t = toInt(this.getAttribute('data-target-page'), 1);
                        renderPage(t);
                    });
                }
            }

            renderPage(1);
        })();

        (function () {
            var form = document.getElementById('sqAttemptForm');
            if (!form) return;

            var safeMode = <?php echo json_encode((string) $safeExamMode); ?>;
            if (!safeMode || safeMode === 'off') return;

            var requireFullscreen = <?php echo $safeRequireFullscreen ? 'true' : 'false'; ?>;
            var blockShortcuts = <?php echo $safeBlockShortcuts ? 'true' : 'false'; ?>;
            var autoSubmitOnBlur = <?php echo $safeAutoSubmitOnBlur ? 'true' : 'false'; ?>;
            var blurGraceSeconds = <?php echo (int) $safeBlurGraceSeconds; ?>;
            if (!blurGraceSeconds || blurGraceSeconds < 1) blurGraceSeconds = 10;

            var submitBtn = document.getElementById('sqSubmitBtn');
            var forceAuto = document.getElementById('sqForceAutoSubmit');
            var blurAlert = document.getElementById('sqBlurAlert');
            var blurCountdown = document.getElementById('sqBlurCountdown');
            var fullscreenAlert = document.getElementById('sqFullscreenAlert');
            var enterFullscreenBtn = document.getElementById('sqEnterFullscreenBtn');

            var blurTimer = null;
            var blurRemaining = 0;
            var submitting = false;
            var fullscreenWasEntered = false;

            function isFullscreen() {
                return !!(document.fullscreenElement || document.webkitFullscreenElement || document.msFullscreenElement);
            }

            function submitAsAuto() {
                if (submitting) return;
                submitting = true;
                if (forceAuto) forceAuto.value = '1';
                if (submitBtn) submitBtn.disabled = true;
                form.submit();
            }

            function stopBlurCountdown() {
                if (blurTimer) {
                    window.clearInterval(blurTimer);
                    blurTimer = null;
                }
                if (blurAlert) blurAlert.classList.add('d-none');
            }

            function tickBlurCountdown() {
                if (blurCountdown) blurCountdown.textContent = String(blurRemaining);
                if (blurRemaining <= 0) {
                    stopBlurCountdown();
                    submitAsAuto();
                    return;
                }
                blurRemaining -= 1;
            }

            function startBlurCountdown() {
                if (!autoSubmitOnBlur || submitting) return;
                if (blurTimer) return;
                blurRemaining = blurGraceSeconds;
                if (blurAlert) {
                    blurAlert.classList.remove('d-none');
                    if (blurCountdown) blurCountdown.textContent = String(blurRemaining);
                }
                blurTimer = window.setInterval(tickBlurCountdown, 1000);
            }

            function updateFullscreenNotice() {
                if (!fullscreenAlert) return;
                if (!requireFullscreen) {
                    fullscreenAlert.classList.add('d-none');
                    return;
                }
                if (isFullscreen()) {
                    fullscreenAlert.classList.add('d-none');
                } else {
                    fullscreenAlert.classList.remove('d-none');
                }
            }

            function requestFullscreen() {
                if (!requireFullscreen) return;
                var docEl = document.documentElement;
                if (docEl.requestFullscreen) {
                    docEl.requestFullscreen();
                } else if (docEl.webkitRequestFullscreen) {
                    docEl.webkitRequestFullscreen();
                } else if (docEl.msRequestFullscreen) {
                    docEl.msRequestFullscreen();
                }
            }

            function handleFocusVisibility() {
                if (requireFullscreen && safeMode === 'required' && fullscreenWasEntered && !isFullscreen()) {
                    startBlurCountdown();
                    return;
                }

                var focused = true;
                if (document.visibilityState && document.visibilityState !== 'visible') {
                    focused = false;
                } else if (typeof document.hasFocus === 'function' && !document.hasFocus()) {
                    focused = false;
                }

                if (focused) {
                    stopBlurCountdown();
                } else {
                    startBlurCountdown();
                }
            }

            function handleFullscreenChange() {
                var fs = isFullscreen();
                if (fs) {
                    fullscreenWasEntered = true;
                    stopBlurCountdown();
                } else if (safeMode === 'required' && autoSubmitOnBlur && fullscreenWasEntered) {
                    startBlurCountdown();
                }
                updateFullscreenNotice();
            }

            form.addEventListener('submit', function () {
                submitting = true;
                stopBlurCountdown();
            });

            if (enterFullscreenBtn) {
                enterFullscreenBtn.addEventListener('click', requestFullscreen);
            }

            if (autoSubmitOnBlur) {
                window.addEventListener('blur', startBlurCountdown);
                window.addEventListener('focus', handleFocusVisibility);
                document.addEventListener('visibilitychange', handleFocusVisibility);
            }

            if (requireFullscreen) {
                fullscreenWasEntered = isFullscreen();
                document.addEventListener('fullscreenchange', handleFullscreenChange);
                document.addEventListener('webkitfullscreenchange', handleFullscreenChange);
                document.addEventListener('MSFullscreenChange', handleFullscreenChange);
                updateFullscreenNotice();
            }

            if (blockShortcuts) {
                var blockedSimple = { f12: true };
                var blockedCtrl = { a: true, c: true, p: true, s: true, u: true, v: true, x: true };
                var blockedCtrlShift = { c: true, i: true, j: true, k: true };

                document.addEventListener('keydown', function (e) {
                    var key = (e.key || '').toLowerCase();
                    var ctrlOrMeta = !!(e.ctrlKey || e.metaKey);
                    var shouldBlock = false;
                    if (blockedSimple[key]) shouldBlock = true;
                    if (ctrlOrMeta && blockedCtrl[key]) shouldBlock = true;
                    if (ctrlOrMeta && e.shiftKey && blockedCtrlShift[key]) shouldBlock = true;
                    if (shouldBlock) {
                        e.preventDefault();
                        e.stopPropagation();
                        return false;
                    }
                }, true);

                var suppress = function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    return false;
                };
                document.addEventListener('copy', suppress, true);
                document.addEventListener('cut', suppress, true);
                document.addEventListener('paste', suppress, true);
                document.addEventListener('contextmenu', suppress, true);
            }
        })();
    </script>
</body>
</html>
