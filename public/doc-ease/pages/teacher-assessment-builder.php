<?php include '../layouts/session.php'; ?>
<?php require_active_role('teacher'); ?>
<?php include '../layouts/main.php'; ?>

<?php
require_once __DIR__ . '/../includes/grading.php';
ensure_grading_tables($conn);

$flash = isset($_SESSION['flash_message']) ? (string) $_SESSION['flash_message'] : '';
$flashType = isset($_SESSION['flash_type']) ? (string) $_SESSION['flash_type'] : 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

$teacherId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$assessmentId = isset($_GET['assessment_id']) ? (int) $_GET['assessment_id'] : 0;
if ($assessmentId <= 0) {
    $_SESSION['flash_message'] = 'Invalid assessment.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: teacher-dashboard.php');
    exit;
}

if (!function_exists('tab_to_input_datetime')) {
    function tab_to_input_datetime($value) {
        $value = trim((string) $value);
        if ($value === '') return '';
        $ts = strtotime($value);
        if (!$ts) return '';
        return date('Y-m-d\\TH:i', $ts);
    }
}

if (!function_exists('tab_from_input_datetime')) {
    function tab_from_input_datetime($value) {
        $value = trim((string) $value);
        if ($value === '') return null;
        $dt = DateTime::createFromFormat('Y-m-d\\TH:i', $value);
        if (!$dt) return null;
        return $dt->format('Y-m-d H:i:s');
    }
}

if (!function_exists('tab_next_question_order')) {
    function tab_next_question_order(mysqli $conn, $assessmentId) {
        $next = 1;
        $stmt = $conn->prepare("SELECT COALESCE(MAX(display_order), 0) + 1 AS next_order FROM grading_assessment_questions WHERE assessment_id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $assessmentId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows === 1) $next = (int) ($res->fetch_assoc()['next_order'] ?? 1);
            $stmt->close();
        }
        return $next > 0 ? $next : 1;
    }
}

if (!function_exists('tab_active_question_points')) {
    function tab_active_question_points(mysqli $conn, $assessmentId) {
        $sum = 0.0;
        $stmt = $conn->prepare("SELECT COALESCE(SUM(default_mark), 0) AS total FROM grading_assessment_questions WHERE assessment_id = ? AND is_active = 1");
        if ($stmt) {
            $stmt->bind_param('i', $assessmentId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows === 1) $sum = (float) ($res->fetch_assoc()['total'] ?? 0);
            $stmt->close();
        }
        return $sum < 0 ? 0.0 : $sum;
    }
}

if (!function_exists('tab_sync_max_score')) {
    function tab_sync_max_score(mysqli $conn, $assessmentId) {
        $sum = tab_active_question_points($conn, $assessmentId);
        $stmt = $conn->prepare("UPDATE grading_assessments SET max_score = ? WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('di', $sum, $assessmentId);
            $stmt->execute();
            $stmt->close();
        }
        return $sum;
    }
}

if (!function_exists('tab_question_label')) {
    function tab_question_label($type) {
        $type = strtolower(trim((string) $type));
        if ($type === 'multiple_choice') return 'Multiple Choice';
        if ($type === 'true_false') return 'True / False';
        if ($type === 'short_answer') return 'Short Answer';
        return 'Question';
    }
}

if (!function_exists('tab_decode_overall_feedback')) {
    function tab_decode_overall_feedback($raw) {
        $result = [
            'low_text' => '',
            'mid_text' => '',
            'high_text' => '',
            'mid_min' => 50.0,
            'high_min' => 75.0,
        ];
        if (!is_string($raw) || trim($raw) === '') return $result;

        $json = json_decode($raw, true);
        if (!is_array($json)) return $result;
        $bands = is_array($json['bands'] ?? null) ? $json['bands'] : [];
        if (count($bands) < 3) return $result;

        $low = $bands[0];
        $mid = $bands[1];
        $high = $bands[2];

        $midMin = is_numeric($mid['min'] ?? null) ? (float) $mid['min'] : 50.0;
        $highMin = is_numeric($high['min'] ?? null) ? (float) $high['min'] : 75.0;
        if ($midMin < 0) $midMin = 0.0;
        if ($highMin > 100) $highMin = 100.0;
        if ($midMin >= $highMin) {
            $midMin = 50.0;
            $highMin = 75.0;
        }

        $result['low_text'] = trim((string) ($low['text'] ?? ''));
        $result['mid_text'] = trim((string) ($mid['text'] ?? ''));
        $result['high_text'] = trim((string) ($high['text'] ?? ''));
        $result['mid_min'] = $midMin;
        $result['high_min'] = $highMin;
        return $result;
    }
}

if (!function_exists('tab_encode_overall_feedback')) {
    function tab_encode_overall_feedback($lowText, $midText, $highText, $midMin, $highMin) {
        $lowText = trim((string) $lowText);
        $midText = trim((string) $midText);
        $highText = trim((string) $highText);

        $midMin = is_numeric($midMin) ? (float) $midMin : 50.0;
        $highMin = is_numeric($highMin) ? (float) $highMin : 75.0;
        if ($midMin < 0) $midMin = 0.0;
        if ($midMin > 99.99) $midMin = 99.99;
        if ($highMin < 0.01) $highMin = 0.01;
        if ($highMin > 100.0) $highMin = 100.0;
        if ($midMin >= $highMin) {
            $midMin = 50.0;
            $highMin = 75.0;
        }

        if ($lowText === '' && $midText === '' && $highText === '') return null;

        $payload = [
            'bands' => [
                ['min' => 0.0, 'max' => $midMin, 'text' => $lowText],
                ['min' => $midMin, 'max' => $highMin, 'text' => $midText],
                ['min' => $highMin, 'max' => 100.0, 'text' => $highText],
            ],
        ];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($json) ? $json : null;
    }
}

$ctx = null;
$stmt = $conn->prepare(
    "SELECT ga.id AS assessment_id, ga.name AS assessment_name, ga.max_score, ga.assessment_date,
            ga.assessment_mode, ga.module_type, ga.instructions, ga.module_settings_json, ga.require_proof_upload, ga.open_at, ga.close_at,
            ga.time_limit_minutes, ga.attempts_allowed, ga.grading_method,
            ga.shuffle_questions, ga.shuffle_choices,
            ga.questions_per_page, ga.navigation_method,
            ga.require_password,
            ga.review_show_response, ga.review_show_marks, ga.review_show_correct_answers,
            ga.grade_to_pass, ga.overall_feedback_json,
            ga.safe_exam_mode, ga.safe_require_fullscreen, ga.safe_block_shortcuts,
            ga.safe_auto_submit_on_blur, ga.safe_blur_grace_seconds,
            ga.access_lock_when_passed, ga.access_cooldown_minutes,
            gc.id AS grading_component_id, gc.component_name, gc.component_code,
            c.category_name,
            sgc.term, sgc.section, sgc.academic_year, sgc.semester,
            s.subject_code, s.subject_name
     FROM grading_assessments ga
     JOIN grading_components gc ON gc.id = ga.grading_component_id
     JOIN section_grading_configs sgc ON sgc.id = gc.section_config_id
     JOIN class_records cr
        ON cr.subject_id = sgc.subject_id
       AND cr.section = sgc.section
       AND cr.academic_year = sgc.academic_year
       AND cr.semester = sgc.semester
       AND cr.status = 'active'
     JOIN teacher_assignments ta ON ta.class_record_id = cr.id AND ta.teacher_id = ? AND ta.status = 'active'
     JOIN subjects s ON s.id = sgc.subject_id
     LEFT JOIN grading_categories c ON c.id = gc.category_id
     WHERE ga.id = ?
     LIMIT 1"
);
if ($stmt) {
    $stmt->bind_param('ii', $teacherId, $assessmentId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows === 1) $ctx = $res->fetch_assoc();
    $stmt->close();
}
if (!$ctx) {
    deny_access(403, 'Forbidden: not assigned to this assessment.');
}

$componentId = (int) ($ctx['grading_component_id'] ?? 0);
$term = (string) ($ctx['term'] ?? 'midterm');
$termLabel = $term === 'final' ? 'Final Term' : 'Midterm';
$moduleCatalog = grading_module_catalog();
$moduleTypeLoaded = grading_normalize_module_type((string) ($ctx['module_type'] ?? 'assessment'));
if ($moduleTypeLoaded === 'assignment') {
    header('Location: teacher-assignment-builder.php?assessment_id=' . $assessmentId);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    if (!csrf_validate($csrf)) {
        $_SESSION['flash_message'] = 'Security check failed (CSRF).';
        $_SESSION['flash_type'] = 'danger';
        header('Location: teacher-assessment-builder.php?assessment_id=' . $assessmentId);
        exit;
    }

    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

    if ($action === 'update_settings') {
        $name = trim((string) ($_POST['assessment_name'] ?? ''));
        $mode = strtolower(trim((string) ($_POST['assessment_mode'] ?? 'manual')));
        $moduleType = grading_normalize_module_type((string) ($_POST['module_type'] ?? 'assessment'));
        $maxRaw = trim((string) ($_POST['max_score'] ?? '0'));
        $date = trim((string) ($_POST['assessment_date'] ?? ''));
        $instructions = trim((string) ($_POST['instructions'] ?? ''));
        $moduleSummary = trim((string) ($_POST['module_summary'] ?? ''));
        $moduleLaunchUrl = trim((string) ($_POST['module_launch_url'] ?? ''));
        $moduleNotes = trim((string) ($_POST['module_notes'] ?? ''));
        $requireProofUpload = isset($_POST['require_proof_upload']) ? 1 : 0;
        $openAt = tab_from_input_datetime((string) ($_POST['open_at'] ?? ''));
        $closeAt = tab_from_input_datetime((string) ($_POST['close_at'] ?? ''));
        $timeLimitRaw = trim((string) ($_POST['time_limit_minutes'] ?? ''));
        $attemptsRaw = trim((string) ($_POST['attempts_allowed'] ?? '1'));
        $gradingMethod = strtolower(trim((string) ($_POST['grading_method'] ?? 'highest')));
        $shuffleQuestions = isset($_POST['shuffle_questions']) ? 1 : 0;
        $shuffleChoices = isset($_POST['shuffle_choices']) ? 1 : 0;
        $questionsPerPageRaw = trim((string) ($_POST['questions_per_page'] ?? '0'));
        $navigationMethod = strtolower(trim((string) ($_POST['navigation_method'] ?? 'free')));
        $requirePassword = trim((string) ($_POST['require_password'] ?? ''));
        $reviewShowResponse = isset($_POST['review_show_response']) ? 1 : 0;
        $reviewShowMarks = isset($_POST['review_show_marks']) ? 1 : 0;
        $reviewShowCorrect = isset($_POST['review_show_correct_answers']) ? 1 : 0;
        $gradeToPassRaw = trim((string) ($_POST['grade_to_pass'] ?? ''));
        $feedbackLowText = trim((string) ($_POST['feedback_low_text'] ?? ''));
        $feedbackMidText = trim((string) ($_POST['feedback_mid_text'] ?? ''));
        $feedbackHighText = trim((string) ($_POST['feedback_high_text'] ?? ''));
        $feedbackMidMinRaw = trim((string) ($_POST['feedback_mid_min'] ?? '50'));
        $feedbackHighMinRaw = trim((string) ($_POST['feedback_high_min'] ?? '75'));
        $safeExamMode = strtolower(trim((string) ($_POST['safe_exam_mode'] ?? 'off')));
        $safeRequireFullscreen = isset($_POST['safe_require_fullscreen']) ? 1 : 0;
        $safeBlockShortcuts = isset($_POST['safe_block_shortcuts']) ? 1 : 0;
        $safeAutoSubmitOnBlur = isset($_POST['safe_auto_submit_on_blur']) ? 1 : 0;
        $safeBlurGraceRaw = trim((string) ($_POST['safe_blur_grace_seconds'] ?? ''));
        $accessLockWhenPassed = isset($_POST['access_lock_when_passed']) ? 1 : 0;
        $accessCooldownRaw = trim((string) ($_POST['access_cooldown_minutes'] ?? ''));
        $syncMax = isset($_POST['sync_max_score']);

        if ($name === '') $name = 'Assessment';
        if (strlen($name) > 120) $name = substr($name, 0, 120);
        if (!in_array($mode, ['manual', 'quiz'], true)) $mode = 'manual';
        if (!in_array($gradingMethod, ['highest', 'average', 'first', 'last'], true)) $gradingMethod = 'highest';
        if (!in_array($navigationMethod, ['free', 'sequential'], true)) $navigationMethod = 'free';
        if (!in_array($safeExamMode, ['off', 'recommended', 'required'], true)) $safeExamMode = 'off';
        $moduleType = grading_normalize_module_type($moduleType);

        $maxNorm = str_replace(',', '.', $maxRaw);
        if (!preg_match('/^\\d+(?:\\.\\d+)?$/', $maxNorm)) $maxNorm = '0';
        $maxScore = clamp_decimal((float) $maxNorm, 0, 100000);
        $dateVal = preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $date) ? $date : null;

        $timeLimitVal = null;
        if ($timeLimitRaw !== '' && ctype_digit($timeLimitRaw)) {
            $tmp = (int) $timeLimitRaw;
            if ($tmp > 0) {
                if ($tmp > 1440) $tmp = 1440;
                $timeLimitVal = (string) $tmp;
            }
        }

        $attemptsAllowed = 1;
        if ($attemptsRaw !== '' && ctype_digit($attemptsRaw)) {
            $attemptsAllowed = (int) $attemptsRaw;
            if ($attemptsAllowed < 1) $attemptsAllowed = 1;
            if ($attemptsAllowed > 20) $attemptsAllowed = 20;
        }

        $questionsPerPageVal = null;
        if ($questionsPerPageRaw !== '' && preg_match('/^\\d+$/', $questionsPerPageRaw)) {
            $qpp = (int) $questionsPerPageRaw;
            if ($qpp <= 0) {
                $questionsPerPageVal = null;
            } else {
                if ($qpp > 100) $qpp = 100;
                $questionsPerPageVal = (string) $qpp;
            }
        }

        if (strlen($requirePassword) > 191) $requirePassword = substr($requirePassword, 0, 191);
        $requirePasswordVal = $requirePassword === '' ? null : $requirePassword;
        if (strlen($moduleSummary) > 12000) $moduleSummary = substr($moduleSummary, 0, 12000);
        if (strlen($moduleLaunchUrl) > 2000) $moduleLaunchUrl = substr($moduleLaunchUrl, 0, 2000);
        if (strlen($moduleNotes) > 12000) $moduleNotes = substr($moduleNotes, 0, 12000);
        $moduleSettings = [];
        if ($moduleSummary !== '') $moduleSettings['summary'] = $moduleSummary;
        if ($moduleLaunchUrl !== '') $moduleSettings['launch_url'] = $moduleLaunchUrl;
        if ($moduleNotes !== '') $moduleSettings['notes'] = $moduleNotes;
        $moduleSettingsJson = null;
        if (count($moduleSettings) > 0) {
            $tmpJson = json_encode($moduleSettings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($tmpJson) && $tmpJson !== '') $moduleSettingsJson = $tmpJson;
        }

        if ($safeExamMode === 'off') {
            $safeRequireFullscreen = 0;
            $safeBlockShortcuts = 0;
            $safeAutoSubmitOnBlur = 0;
        }
        $safeBlurGraceVal = null;
        if ($safeBlurGraceRaw !== '' && preg_match('/^\\d+$/', $safeBlurGraceRaw)) {
            $safeTmp = (int) $safeBlurGraceRaw;
            if ($safeTmp > 0) {
                if ($safeTmp > 300) $safeTmp = 300;
                $safeBlurGraceVal = (string) $safeTmp;
            }
        }
        if ($safeBlurGraceVal === null && $safeAutoSubmitOnBlur) {
            $safeBlurGraceVal = '10';
        }

        $accessCooldownVal = null;
        if ($accessCooldownRaw !== '' && preg_match('/^\\d+$/', $accessCooldownRaw)) {
            $acTmp = (int) $accessCooldownRaw;
            if ($acTmp > 0) {
                if ($acTmp > 10080) $acTmp = 10080;
                $accessCooldownVal = (string) $acTmp;
            }
        }

        $gradeToPassText = '';
        if ($gradeToPassRaw !== '') {
            $g = str_replace(',', '.', $gradeToPassRaw);
            if (preg_match('/^\\d+(?:\\.\\d+)?$/', $g)) {
                $gradeToPassVal = (float) $g;
                if ($gradeToPassVal < 0) $gradeToPassVal = 0;
                if ($gradeToPassVal > $maxScore) $gradeToPassVal = $maxScore;
                $gradeToPassVal = round((float) $gradeToPassVal, 2);
                $gradeToPassText = number_format((float) $gradeToPassVal, 2, '.', '');
            }
        }

        if (strlen($feedbackLowText) > 4000) $feedbackLowText = substr($feedbackLowText, 0, 4000);
        if (strlen($feedbackMidText) > 4000) $feedbackMidText = substr($feedbackMidText, 0, 4000);
        if (strlen($feedbackHighText) > 4000) $feedbackHighText = substr($feedbackHighText, 0, 4000);
        $overallFeedbackJson = tab_encode_overall_feedback(
            $feedbackLowText,
            $feedbackMidText,
            $feedbackHighText,
            $feedbackMidMinRaw,
            $feedbackHighMinRaw
        );

        if ($openAt && $closeAt && strtotime($openAt) > strtotime($closeAt)) {
            $_SESSION['flash_message'] = 'Close date/time must be later than open date/time.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: teacher-assessment-builder.php?assessment_id=' . $assessmentId);
            exit;
        }

        $instructionsVal = $instructions === '' ? null : substr($instructions, 0, 12000);
        $upd = $conn->prepare(
            "UPDATE grading_assessments
             SET name = ?, max_score = ?, assessment_date = ?, assessment_mode = ?, module_type = ?, instructions = ?, module_settings_json = ?,
                 require_proof_upload = ?, open_at = ?, close_at = ?, time_limit_minutes = ?, attempts_allowed = ?, grading_method = ?,
                 shuffle_questions = ?, shuffle_choices = ?, questions_per_page = ?, navigation_method = ?,
                 require_password = ?, review_show_response = ?, review_show_marks = ?, review_show_correct_answers = ?,
                 grade_to_pass = NULLIF(?, ''), overall_feedback_json = ?,
                 safe_exam_mode = ?, safe_require_fullscreen = ?, safe_block_shortcuts = ?, safe_auto_submit_on_blur = ?,
                 safe_blur_grace_seconds = NULLIF(?, ''),
                 access_lock_when_passed = ?, access_cooldown_minutes = NULLIF(?, '')
             WHERE id = ? LIMIT 1"
        );
        if ($upd) {
            $upd->bind_param(
                'sdsssssisssisiisssiiisssiiisisi',
                $name,
                $maxScore,
                $dateVal,
                $mode,
                $moduleType,
                $instructionsVal,
                $moduleSettingsJson,
                $requireProofUpload,
                $openAt,
                $closeAt,
                $timeLimitVal,
                $attemptsAllowed,
                $gradingMethod,
                $shuffleQuestions,
                $shuffleChoices,
                $questionsPerPageVal,
                $navigationMethod,
                $requirePasswordVal,
                $reviewShowResponse,
                $reviewShowMarks,
                $reviewShowCorrect,
                $gradeToPassText,
                $overallFeedbackJson,
                $safeExamMode,
                $safeRequireFullscreen,
                $safeBlockShortcuts,
                $safeAutoSubmitOnBlur,
                $safeBlurGraceVal,
                $accessLockWhenPassed,
                $accessCooldownVal,
                $assessmentId
            );
            $upd->execute();
            $upd->close();
        }

        if ($syncMax) {
            $synced = tab_sync_max_score($conn, $assessmentId);
            $_SESSION['flash_message'] = 'Settings updated. Max score synced to ' . number_format((float) $synced, 2, '.', '') . '.';
        } else {
            $_SESSION['flash_message'] = 'Settings updated.';
        }
        $_SESSION['flash_type'] = 'success';
        header('Location: teacher-assessment-builder.php?assessment_id=' . $assessmentId);
        exit;
    }

    if ($action === 'add_question') {
        $questionType = strtolower(trim((string) ($_POST['question_type'] ?? 'multiple_choice')));
        $questionText = trim((string) ($_POST['question_text'] ?? ''));
        $markRaw = trim((string) ($_POST['default_mark'] ?? '1'));
        $orderRaw = trim((string) ($_POST['display_order'] ?? ''));
        $isRequired = isset($_POST['is_required']) ? 1 : 0;
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $syncMax = isset($_POST['sync_max_score']);

        if (!in_array($questionType, ['multiple_choice', 'true_false', 'short_answer'], true)) $questionType = 'multiple_choice';
        if ($questionText === '') {
            $_SESSION['flash_message'] = 'Question text is required.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: teacher-assessment-builder.php?assessment_id=' . $assessmentId);
            exit;
        }
        $questionText = substr($questionText, 0, 20000);

        $markNorm = str_replace(',', '.', $markRaw);
        if (!preg_match('/^\\d+(?:\\.\\d+)?$/', $markNorm)) $markNorm = '1';
        $defaultMark = clamp_decimal((float) $markNorm, 0.01, 100000);
        $displayOrder = (ctype_digit($orderRaw) && (int) $orderRaw > 0) ? (int) $orderRaw : tab_next_question_order($conn, $assessmentId);

        $options = [];
        $answerText = null;

        if ($questionType === 'multiple_choice') {
            $rawChoices = [];
            for ($i = 1; $i <= 6; $i++) {
                $v = trim((string) ($_POST['mc_choice_' . $i] ?? ''));
                if ($v === '') continue;
                $rawChoices[] = ['idx' => $i, 'text' => substr($v, 0, 300)];
            }
            if (count($rawChoices) < 2) {
                $_SESSION['flash_message'] = 'Multiple choice needs at least 2 choices.';
                $_SESSION['flash_type'] = 'warning';
                header('Location: teacher-assessment-builder.php?assessment_id=' . $assessmentId);
                exit;
            }
            $correct = isset($_POST['mc_correct']) ? (int) $_POST['mc_correct'] : 1;
            $hasCorrect = false;
            $choices = [];
            foreach ($rawChoices as $rc) {
                $isCorrectChoice = ((int) $rc['idx'] === $correct) ? 1 : 0;
                if ($isCorrectChoice === 1) $hasCorrect = true;
                $choices[] = ['text' => $rc['text'], 'is_correct' => $isCorrectChoice];
            }
            if (!$hasCorrect) {
                $_SESSION['flash_message'] = 'Choose a correct answer.';
                $_SESSION['flash_type'] = 'warning';
                header('Location: teacher-assessment-builder.php?assessment_id=' . $assessmentId);
                exit;
            }
            $options = ['choices' => $choices];
        } elseif ($questionType === 'true_false') {
            $tf = strtolower(trim((string) ($_POST['tf_correct'] ?? 'true')));
            if (!in_array($tf, ['true', 'false'], true)) $tf = 'true';
            $answerText = $tf;
            $options = ['choices' => [['text' => 'True', 'is_correct' => $tf === 'true' ? 1 : 0], ['text' => 'False', 'is_correct' => $tf === 'false' ? 1 : 0]]];
        } else {
            $answersRaw = trim((string) ($_POST['sa_answers'] ?? ''));
            $parts = preg_split('/\\r\\n|\\r|\\n/', $answersRaw);
            $answers = [];
            foreach ($parts as $p) {
                $v = trim((string) $p);
                if ($v === '') continue;
                $answers[] = substr($v, 0, 500);
            }
            $answers = array_values(array_unique($answers));
            if (count($answers) === 0) {
                $_SESSION['flash_message'] = 'Short answer needs at least one accepted answer.';
                $_SESSION['flash_type'] = 'warning';
                header('Location: teacher-assessment-builder.php?assessment_id=' . $assessmentId);
                exit;
            }
            $answerText = (string) $answers[0];
            $options = ['accepted_answers' => $answers, 'case_sensitive' => isset($_POST['sa_case_sensitive']) ? 1 : 0];
        }

        $optionsJson = json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($optionsJson) || $optionsJson === '') $optionsJson = '{}';

        $ins = $conn->prepare(
            "INSERT INTO grading_assessment_questions
                (assessment_id, question_type, question_text, options_json, answer_text, default_mark, display_order, is_required, is_active, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if ($ins) {
            $ins->bind_param('issssdiiii', $assessmentId, $questionType, $questionText, $optionsJson, $answerText, $defaultMark, $displayOrder, $isRequired, $isActive, $teacherId);
            $ins->execute();
            $ins->close();
            if ($syncMax) tab_sync_max_score($conn, $assessmentId);
            $_SESSION['flash_message'] = 'Question added.';
            $_SESSION['flash_type'] = 'success';
        } else {
            $_SESSION['flash_message'] = 'Unable to add question.';
            $_SESSION['flash_type'] = 'danger';
        }
        header('Location: teacher-assessment-builder.php?assessment_id=' . $assessmentId);
        exit;
    }

    if ($action === 'toggle_question') {
        $questionId = isset($_POST['question_id']) ? (int) $_POST['question_id'] : 0;
        if ($questionId > 0) {
            $upd = $conn->prepare("UPDATE grading_assessment_questions SET is_active = IF(is_active = 1, 0, 1) WHERE id = ? AND assessment_id = ? LIMIT 1");
            if ($upd) {
                $upd->bind_param('ii', $questionId, $assessmentId);
                $upd->execute();
                $upd->close();
            }
        }
        $_SESSION['flash_message'] = 'Question status updated.';
        $_SESSION['flash_type'] = 'success';
        header('Location: teacher-assessment-builder.php?assessment_id=' . $assessmentId);
        exit;
    }

    if ($action === 'delete_question') {
        $questionId = isset($_POST['question_id']) ? (int) $_POST['question_id'] : 0;
        if ($questionId > 0) {
            $del = $conn->prepare("DELETE FROM grading_assessment_questions WHERE id = ? AND assessment_id = ? LIMIT 1");
            if ($del) {
                $del->bind_param('ii', $questionId, $assessmentId);
                $del->execute();
                $del->close();
            }
        }
        $_SESSION['flash_message'] = 'Question deleted.';
        $_SESSION['flash_type'] = 'success';
        header('Location: teacher-assessment-builder.php?assessment_id=' . $assessmentId);
        exit;
    }

    if ($action === 'sync_max_score') {
        $synced = tab_sync_max_score($conn, $assessmentId);
        $_SESSION['flash_message'] = 'Max score synced to ' . number_format((float) $synced, 2, '.', '') . '.';
        $_SESSION['flash_type'] = 'success';
        header('Location: teacher-assessment-builder.php?assessment_id=' . $assessmentId);
        exit;
    }
}

$questions = [];
$list = $conn->prepare(
    "SELECT id, question_type, question_text, options_json, answer_text, default_mark, display_order, is_active
     FROM grading_assessment_questions
     WHERE assessment_id = ?
     ORDER BY display_order ASC, id ASC"
);
if ($list) {
    $list->bind_param('i', $assessmentId);
    $list->execute();
    $res = $list->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
        $decoded = json_decode((string) ($row['options_json'] ?? ''), true);
        $row['options'] = is_array($decoded) ? $decoded : [];
        $questions[] = $row;
    }
    $list->close();
}

$activeQuestionCount = 0;
foreach ($questions as $qRow) {
    if ((int) ($qRow['is_active'] ?? 0) === 1) $activeQuestionCount++;
}
$activePoints = tab_active_question_points($conn, $assessmentId);
$nextOrder = tab_next_question_order($conn, $assessmentId);
$overallFeedback = tab_decode_overall_feedback((string) ($ctx['overall_feedback_json'] ?? ''));
$moduleTypeCurrent = grading_normalize_module_type((string) ($ctx['module_type'] ?? 'assessment'));
$moduleInfoCurrent = grading_module_info($moduleTypeCurrent);
$moduleSettingsCurrent = grading_decode_json_array((string) ($ctx['module_settings_json'] ?? ''));
$moduleSummaryCurrent = trim((string) ($moduleSettingsCurrent['summary'] ?? ''));
$moduleLaunchUrlCurrent = trim((string) ($moduleSettingsCurrent['launch_url'] ?? ''));
$moduleNotesCurrent = trim((string) ($moduleSettingsCurrent['notes'] ?? ''));
?>

<head>
    <title>Assessment Builder | E-Record</title>
    <?php include '../layouts/title-meta.php'; ?>
    <?php include '../layouts/head-css.php'; ?>
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
                                        <li class="breadcrumb-item"><a href="teacher-dashboard.php">Teacher</a></li>
                                        <li class="breadcrumb-item"><a href="teacher-component-assessments.php?grading_component_id=<?php echo (int) $componentId; ?>">Assessments</a></li>
                                        <li class="breadcrumb-item active">Assessment Builder</li>
                                    </ol>
                                </div>
                                <h4 class="page-title">Assessment Builder</h4>
                            </div>
                        </div>
                    </div>

                    <?php if ($flash): ?>
                        <div class="alert alert-<?php echo htmlspecialchars($flashType); ?>"><?php echo htmlspecialchars($flash); ?></div>
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                        <div>
                                            <div class="fw-semibold fs-5"><?php echo htmlspecialchars((string) ($ctx['assessment_name'] ?? 'Assessment')); ?></div>
                                            <div class="text-muted small">
                                                <?php echo htmlspecialchars((string) ($ctx['subject_name'] ?? '')); ?> (<?php echo htmlspecialchars((string) ($ctx['subject_code'] ?? '')); ?>) |
                                                <?php echo htmlspecialchars((string) ($ctx['section'] ?? '')); ?> |
                                                <?php echo htmlspecialchars((string) ($ctx['academic_year'] ?? '')); ?>, <?php echo htmlspecialchars((string) ($ctx['semester'] ?? '')); ?> |
                                                <?php echo htmlspecialchars($termLabel); ?>
                                            </div>
                                        </div>
                                        <div class="text-end">
                                            <span class="badge bg-primary-subtle text-primary">Mode: <?php echo htmlspecialchars(strtoupper((string) ($ctx['assessment_mode'] ?? 'manual'))); ?></span>
                                            <span class="badge bg-info-subtle text-info ms-1">Module: <?php echo htmlspecialchars((string) ($moduleInfoCurrent['label'] ?? 'Assessment')); ?></span>
                                            <div class="small text-muted mt-1">
                                                Max: <strong><?php echo htmlspecialchars((string) ($ctx['max_score'] ?? '0')); ?></strong> |
                                                Active questions: <strong><?php echo (int) $activeQuestionCount; ?></strong> |
                                                Active points: <strong><?php echo number_format((float) $activePoints, 2, '.', ''); ?></strong>
                                            </div>
                                            <div class="mt-2">
                                                <a class="btn btn-sm btn-outline-secondary" href="teacher-component-assessments.php?grading_component_id=<?php echo (int) $componentId; ?>">Back</a>
                                                <a class="btn btn-sm btn-outline-primary ms-1" href="teacher-assessment-scores.php?assessment_id=<?php echo (int) $assessmentId; ?>">Scores</a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-xl-5">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title">Assessment & Module Settings</h4>
                                    <p class="text-muted mb-3">Configure the assessment mode plus Moodle-style module profile, then adjust timing, grade, layout, behavior, review, restrictions, and feedback.</p>
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                        <input type="hidden" name="action" value="update_settings">
                                        <div class="fw-semibold text-uppercase small text-muted mb-2">General</div>
                                        <div class="mb-2">
                                            <label class="form-label">Assessment Name</label>
                                            <input class="form-control" name="assessment_name" maxlength="120" value="<?php echo htmlspecialchars((string) ($ctx['assessment_name'] ?? '')); ?>" required>
                                        </div>
                                        <div class="row g-2">
                                            <div class="col-lg-4">
                                                <label class="form-label">Mode</label>
                                                <?php $modeCurrent = strtolower((string) ($ctx['assessment_mode'] ?? 'manual')); ?>
                                                <select class="form-select" name="assessment_mode">
                                                    <option value="manual" <?php echo $modeCurrent === 'manual' ? 'selected' : ''; ?>>Manual</option>
                                                    <option value="quiz" <?php echo $modeCurrent === 'quiz' ? 'selected' : ''; ?>>Quiz</option>
                                                </select>
                                            </div>
                                            <div class="col-lg-4">
                                                <label class="form-label">Module Type</label>
                                                <select class="form-select" name="module_type">
                                                    <?php foreach ($moduleCatalog as $moduleKey => $moduleInfo): ?>
                                                        <option value="<?php echo htmlspecialchars((string) $moduleKey); ?>" <?php echo $moduleTypeCurrent === $moduleKey ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars((string) ($moduleInfo['label'] ?? $moduleKey)); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-lg-4">
                                                <label class="form-label">Max Score</label>
                                                <input class="form-control" name="max_score" type="number" min="0" step="0.01" value="<?php echo htmlspecialchars((string) ($ctx['max_score'] ?? '0')); ?>" required>
                                            </div>
                                        </div>
                                        <div class="mt-2 small text-muted">
                                            Selected module: <strong><?php echo htmlspecialchars((string) ($moduleInfoCurrent['label'] ?? 'Assessment')); ?></strong>
                                            (<?php echo htmlspecialchars((string) ($moduleInfoCurrent['kind'] ?? 'assessment')); ?>).
                                            <?php echo htmlspecialchars((string) ($moduleInfoCurrent['description'] ?? '')); ?>
                                        </div>
                                        <div class="mt-2">
                                            <label class="form-label">Description / Instructions</label>
                                            <textarea class="form-control" name="instructions" rows="3"><?php echo htmlspecialchars((string) ($ctx['instructions'] ?? '')); ?></textarea>
                                        </div>
                                        <div class="mt-2">
                                            <label class="form-label">Module Summary</label>
                                            <textarea class="form-control" name="module_summary" rows="2" placeholder="Short module overview shown to students"><?php echo htmlspecialchars($moduleSummaryCurrent); ?></textarea>
                                        </div>
                                        <div class="mt-2">
                                            <label class="form-label">Module Launch URL (optional)</label>
                                            <input class="form-control" type="url" maxlength="2000" name="module_launch_url" value="<?php echo htmlspecialchars($moduleLaunchUrlCurrent); ?>" placeholder="https://example.com/resource">
                                        </div>
                                        <div class="mt-2">
                                            <label class="form-label">Module Notes</label>
                                            <textarea class="form-control" name="module_notes" rows="2" placeholder="Additional teacher notes or student instructions"><?php echo htmlspecialchars($moduleNotesCurrent); ?></textarea>
                                        </div>
                                        <div class="mt-2">
                                            <label class="form-label">Assessment Date</label>
                                            <input class="form-control" type="date" name="assessment_date" value="<?php echo htmlspecialchars((string) ($ctx['assessment_date'] ?? '')); ?>">
                                        </div>

                                        <hr class="my-3">
                                        <div class="fw-semibold text-uppercase small text-muted mb-2">Timing</div>
                                        <div class="row g-2 mt-0">
                                            <div class="col-6">
                                                <label class="form-label">Open At</label>
                                                <input class="form-control" type="datetime-local" name="open_at" value="<?php echo htmlspecialchars(tab_to_input_datetime((string) ($ctx['open_at'] ?? ''))); ?>">
                                            </div>
                                            <div class="col-6">
                                                <label class="form-label">Close At</label>
                                                <input class="form-control" type="datetime-local" name="close_at" value="<?php echo htmlspecialchars(tab_to_input_datetime((string) ($ctx['close_at'] ?? ''))); ?>">
                                            </div>
                                        </div>
                                        <div class="row g-2 mt-0">
                                            <div class="col-6">
                                                <label class="form-label">Time Limit (min)</label>
                                                <input class="form-control" type="number" min="1" max="1440" step="1" name="time_limit_minutes" value="<?php echo htmlspecialchars((string) ($ctx['time_limit_minutes'] ?? '')); ?>">
                                            </div>
                                            <div class="col-6">
                                                <label class="form-label">Questions Per Page</label>
                                                <input class="form-control" type="number" min="0" max="100" step="1" name="questions_per_page" value="<?php echo htmlspecialchars((string) ($ctx['questions_per_page'] ?? '0')); ?>" placeholder="0 = all">
                                            </div>
                                        </div>

                                        <hr class="my-3">
                                        <div class="fw-semibold text-uppercase small text-muted mb-2">Grade</div>
                                        <div class="row g-2 mt-0">
                                            <div class="col-6">
                                                <label class="form-label">Attempts</label>
                                                <input class="form-control" type="number" min="1" max="20" step="1" name="attempts_allowed" value="<?php echo htmlspecialchars((string) ($ctx['attempts_allowed'] ?? '1')); ?>">
                                            </div>
                                            <div class="col-6">
                                                <label class="form-label">Grade To Pass</label>
                                                <input class="form-control" type="number" min="0" step="0.01" name="grade_to_pass" value="<?php echo htmlspecialchars((string) ($ctx['grade_to_pass'] ?? '')); ?>" placeholder="Optional">
                                            </div>
                                        </div>
                                        <div class="mt-2">
                                            <label class="form-label">Grading Method</label>
                                            <?php $gm = strtolower((string) ($ctx['grading_method'] ?? 'highest')); ?>
                                            <select class="form-select" name="grading_method">
                                                <option value="highest" <?php echo $gm === 'highest' ? 'selected' : ''; ?>>Highest attempt</option>
                                                <option value="average" <?php echo $gm === 'average' ? 'selected' : ''; ?>>Average grade</option>
                                                <option value="first" <?php echo $gm === 'first' ? 'selected' : ''; ?>>First attempt</option>
                                                <option value="last" <?php echo $gm === 'last' ? 'selected' : ''; ?>>Last attempt</option>
                                            </select>
                                        </div>

                                        <hr class="my-3">
                                        <div class="fw-semibold text-uppercase small text-muted mb-2">Layout</div>
                                        <?php $navMethod = strtolower((string) ($ctx['navigation_method'] ?? 'free')); ?>
                                        <div class="mt-2">
                                            <label class="form-label">Navigation Method</label>
                                            <select class="form-select" name="navigation_method">
                                                <option value="free" <?php echo $navMethod === 'free' ? 'selected' : ''; ?>>Free</option>
                                                <option value="sequential" <?php echo $navMethod === 'sequential' ? 'selected' : ''; ?>>Sequential</option>
                                            </select>
                                        </div>

                                        <hr class="my-3">
                                        <div class="fw-semibold text-uppercase small text-muted mb-2">Question Behaviour</div>
                                        <div class="form-check mt-2">
                                            <input class="form-check-input" type="checkbox" id="shuffleQ" name="shuffle_questions" <?php echo !empty($ctx['shuffle_questions']) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="shuffleQ">Shuffle questions</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="shuffleC" name="shuffle_choices" <?php echo !empty($ctx['shuffle_choices']) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="shuffleC">Shuffle choices</label>
                                        </div>

                                        <hr class="my-3">
                                        <div class="fw-semibold text-uppercase small text-muted mb-2">Review Options</div>
                                        <div class="form-check mt-2">
                                            <input class="form-check-input" type="checkbox" id="reviewShowResponse" name="review_show_response" <?php echo !empty($ctx['review_show_response']) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="reviewShowResponse">Show student response in review</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="reviewShowMarks" name="review_show_marks" <?php echo !empty($ctx['review_show_marks']) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="reviewShowMarks">Show marks in review</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="reviewShowCorrect" name="review_show_correct_answers" <?php echo !empty($ctx['review_show_correct_answers']) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="reviewShowCorrect">Show correct answers in review</label>
                                        </div>

                                        <hr class="my-3">
                                        <div class="fw-semibold text-uppercase small text-muted mb-2">Extra Restrictions On Attempts</div>
                                        <div class="form-check mt-2">
                                            <input class="form-check-input" type="checkbox" id="requireProofUpload" name="require_proof_upload" <?php echo !empty($ctx['require_proof_upload']) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="requireProofUpload">Require at least one proof upload before final submission/scoring</label>
                                        </div>
                                        <div class="mt-2">
                                            <label class="form-label">Require Password</label>
                                            <input class="form-control" type="text" maxlength="191" name="require_password" value="<?php echo htmlspecialchars((string) ($ctx['require_password'] ?? '')); ?>" placeholder="Optional quiz access password">
                                        </div>
                                        <div class="row g-2 mt-0">
                                            <div class="col-6">
                                                <label class="form-label">Cooldown Between Attempts (min)</label>
                                                <input class="form-control" type="number" min="0" max="10080" step="1" name="access_cooldown_minutes" value="<?php echo htmlspecialchars((string) ($ctx['access_cooldown_minutes'] ?? '0')); ?>" placeholder="0 = none">
                                            </div>
                                            <div class="col-6 d-flex align-items-end">
                                                <div class="form-check mb-2">
                                                    <input class="form-check-input" type="checkbox" id="accessLockWhenPassed" name="access_lock_when_passed" <?php echo !empty($ctx['access_lock_when_passed']) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="accessLockWhenPassed">
                                                        Lock new attempts once student passed
                                                    </label>
                                                </div>
                                            </div>
                                        </div>

                                        <hr class="my-3">
                                        <div class="fw-semibold text-uppercase small text-muted mb-2">Safe Exam Browser</div>
                                        <?php $safeMode = strtolower((string) ($ctx['safe_exam_mode'] ?? 'off')); ?>
                                        <div class="mt-2">
                                            <label class="form-label">Safe Exam Mode</label>
                                            <select class="form-select" name="safe_exam_mode">
                                                <option value="off" <?php echo $safeMode === 'off' ? 'selected' : ''; ?>>Off</option>
                                                <option value="recommended" <?php echo $safeMode === 'recommended' ? 'selected' : ''; ?>>Recommended</option>
                                                <option value="required" <?php echo $safeMode === 'required' ? 'selected' : ''; ?>>Required</option>
                                            </select>
                                            <div class="form-text">
                                                Browser lockdown in normal web browsers is best-effort only.
                                            </div>
                                        </div>
                                        <div class="form-check mt-2">
                                            <input class="form-check-input" type="checkbox" id="safeRequireFullscreen" name="safe_require_fullscreen" <?php echo !empty($ctx['safe_require_fullscreen']) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="safeRequireFullscreen">Require fullscreen during attempt</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="safeBlockShortcuts" name="safe_block_shortcuts" <?php echo !empty($ctx['safe_block_shortcuts']) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="safeBlockShortcuts">Block common keyboard shortcuts and copy/paste</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="safeAutoBlur" name="safe_auto_submit_on_blur" <?php echo !empty($ctx['safe_auto_submit_on_blur']) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="safeAutoBlur">Auto-submit when window/tab loses focus</label>
                                        </div>
                                        <div class="mt-2">
                                            <label class="form-label">Focus-Loss Grace (seconds)</label>
                                            <input class="form-control" type="number" min="1" max="300" step="1" name="safe_blur_grace_seconds" value="<?php echo htmlspecialchars((string) ($ctx['safe_blur_grace_seconds'] ?? '10')); ?>" placeholder="Used when auto-submit on blur is enabled">
                                        </div>

                                        <hr class="my-3">
                                        <div class="fw-semibold text-uppercase small text-muted mb-2">Overall Feedback</div>
                                        <div class="row g-2">
                                            <div class="col-6">
                                                <label class="form-label">Mid Feedback Starts At (%)</label>
                                                <input class="form-control" type="number" min="0" max="99.99" step="0.01" name="feedback_mid_min" value="<?php echo htmlspecialchars((string) ($overallFeedback['mid_min'] ?? 50)); ?>">
                                            </div>
                                            <div class="col-6">
                                                <label class="form-label">High Feedback Starts At (%)</label>
                                                <input class="form-control" type="number" min="0.01" max="100" step="0.01" name="feedback_high_min" value="<?php echo htmlspecialchars((string) ($overallFeedback['high_min'] ?? 75)); ?>">
                                            </div>
                                        </div>
                                        <div class="mt-2">
                                            <label class="form-label">Low Band Feedback</label>
                                            <textarea class="form-control" rows="2" name="feedback_low_text" placeholder="Shown for lower scores"><?php echo htmlspecialchars((string) ($overallFeedback['low_text'] ?? '')); ?></textarea>
                                        </div>
                                        <div class="mt-2">
                                            <label class="form-label">Mid Band Feedback</label>
                                            <textarea class="form-control" rows="2" name="feedback_mid_text" placeholder="Shown for mid scores"><?php echo htmlspecialchars((string) ($overallFeedback['mid_text'] ?? '')); ?></textarea>
                                        </div>
                                        <div class="mt-2">
                                            <label class="form-label">High Band Feedback</label>
                                            <textarea class="form-control" rows="2" name="feedback_high_text" placeholder="Shown for high scores"><?php echo htmlspecialchars((string) ($overallFeedback['high_text'] ?? '')); ?></textarea>
                                        </div>

                                        <div class="form-check mt-2">
                                            <input class="form-check-input" type="checkbox" id="syncOnSettings" name="sync_max_score">
                                            <label class="form-check-label" for="syncOnSettings">Sync max score to active question points</label>
                                        </div>
                                        <div class="mt-3">
                                            <button class="btn btn-primary" type="submit">Save Settings</button>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title">Add Question</h4>
                                    <?php if (strtolower((string) ($ctx['assessment_mode'] ?? 'manual')) !== 'quiz'): ?>
                                        <div class="alert alert-light border py-2 small">
                                            Question bank is used when this assessment is in <strong>Quiz</strong> mode.
                                        </div>
                                    <?php endif; ?>
                                    <form method="post" id="addQuestionForm">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                        <input type="hidden" name="action" value="add_question">
                                        <div class="mb-2">
                                            <label class="form-label">Type</label>
                                            <select class="form-select" id="questionType" name="question_type">
                                                <option value="multiple_choice">Multiple Choice</option>
                                                <option value="true_false">True / False</option>
                                                <option value="short_answer">Short Answer</option>
                                            </select>
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label">Question</label>
                                            <textarea class="form-control" name="question_text" rows="3" required></textarea>
                                        </div>
                                        <div class="row g-2">
                                            <div class="col-6">
                                                <label class="form-label">Points</label>
                                                <input class="form-control" name="default_mark" type="number" min="0.01" step="0.01" value="1.00" required>
                                            </div>
                                            <div class="col-6">
                                                <label class="form-label">Order</label>
                                                <input class="form-control" name="display_order" type="number" min="1" step="1" value="<?php echo (int) $nextOrder; ?>">
                                            </div>
                                        </div>
                                        <div class="mt-2" id="mcBlock">
                                            <label class="form-label">Choices</label>
                                            <?php for ($i = 1; $i <= 6; $i++): ?>
                                                <input class="form-control form-control-sm mt-1" name="mc_choice_<?php echo $i; ?>" placeholder="Choice <?php echo $i; ?>">
                                            <?php endfor; ?>
                                            <div class="mt-2">
                                                <label class="form-label">Correct Choice</label>
                                                <select class="form-select form-select-sm" name="mc_correct">
                                                    <?php for ($i = 1; $i <= 6; $i++): ?>
                                                        <option value="<?php echo $i; ?>">Choice <?php echo $i; ?></option>
                                                    <?php endfor; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="mt-2" id="tfBlock">
                                            <label class="form-label">Correct Answer</label>
                                            <select class="form-select form-select-sm" name="tf_correct">
                                                <option value="true">True</option>
                                                <option value="false">False</option>
                                            </select>
                                        </div>
                                        <div class="mt-2" id="saBlock">
                                            <label class="form-label">Accepted Answers (one per line)</label>
                                            <textarea class="form-control" name="sa_answers" rows="3"></textarea>
                                            <div class="form-check mt-2">
                                                <input class="form-check-input" type="checkbox" id="saCase" name="sa_case_sensitive">
                                                <label class="form-check-label" for="saCase">Case sensitive</label>
                                            </div>
                                        </div>
                                        <div class="form-check mt-2">
                                            <input class="form-check-input" type="checkbox" id="qRequired" name="is_required" checked>
                                            <label class="form-check-label" for="qRequired">Required</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="qActive" name="is_active" checked>
                                            <label class="form-check-label" for="qActive">Active</label>
                                        </div>
                                        <div class="form-check mt-2">
                                            <input class="form-check-input" type="checkbox" id="syncOnQuestion" name="sync_max_score" checked>
                                            <label class="form-check-label" for="syncOnQuestion">Sync max score after add</label>
                                        </div>
                                        <div class="mt-3">
                                            <button class="btn btn-primary" type="submit">Add Question</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-7">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                        <h4 class="header-title mb-0">Question List</h4>
                                        <form method="post">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                            <input type="hidden" name="action" value="sync_max_score">
                                            <button class="btn btn-sm btn-outline-primary" type="submit">Sync Max Score</button>
                                        </form>
                                    </div>
                                    <div class="table-responsive mt-3">
                                        <table class="table table-sm table-striped table-hover align-middle mb-0">
                                            <thead>
                                                <tr>
                                                    <th style="width: 70px;">Order</th>
                                                    <th style="width: 150px;">Type</th>
                                                    <th>Question</th>
                                                    <th style="width: 100px;">Points</th>
                                                    <th style="width: 100px;">Status</th>
                                                    <th class="text-end" style="width: 170px;">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (count($questions) === 0): ?>
                                                    <tr>
                                                        <td colspan="6" class="text-center text-muted">No questions yet.</td>
                                                    </tr>
                                                <?php endif; ?>
                                                <?php foreach ($questions as $qRow): ?>
                                                    <?php
                                                    $qType = (string) ($qRow['question_type'] ?? '');
                                                    $qOptions = is_array($qRow['options'] ?? null) ? $qRow['options'] : [];
                                                    ?>
                                                    <tr>
                                                        <td><?php echo (int) ($qRow['display_order'] ?? 0); ?></td>
                                                        <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars(tab_question_label($qType)); ?></span></td>
                                                        <td>
                                                            <div class="fw-semibold"><?php echo nl2br(htmlspecialchars((string) ($qRow['question_text'] ?? ''))); ?></div>
                                                            <div class="text-muted small mt-1">
                                                                <?php if ($qType === 'multiple_choice'): ?>
                                                                    <?php $choices = is_array($qOptions['choices'] ?? null) ? $qOptions['choices'] : []; ?>
                                                                    <?php foreach ($choices as $ch): ?>
                                                                        <div>
                                                                            <?php if ((int) ($ch['is_correct'] ?? 0) === 1): ?><i class="ri-check-line text-success" aria-hidden="true"></i><?php endif; ?>
                                                                            <?php echo htmlspecialchars((string) ($ch['text'] ?? '')); ?>
                                                                        </div>
                                                                    <?php endforeach; ?>
                                                                <?php elseif ($qType === 'true_false'): ?>
                                                                    Correct: <strong><?php echo htmlspecialchars(strtoupper((string) ($qRow['answer_text'] ?? ''))); ?></strong>
                                                                <?php else: ?>
                                                                    <?php $accepted = is_array($qOptions['accepted_answers'] ?? null) ? $qOptions['accepted_answers'] : []; ?>
                                                                    Accepted: <strong><?php echo htmlspecialchars(implode(', ', array_map('strval', $accepted))); ?></strong>
                                                                <?php endif; ?>
                                                            </div>
                                                        </td>
                                                        <td><?php echo htmlspecialchars((string) ($qRow['default_mark'] ?? '0')); ?></td>
                                                        <td>
                                                            <?php if ((int) ($qRow['is_active'] ?? 0) === 1): ?>
                                                                <span class="badge bg-success">Active</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-secondary">Disabled</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="text-end">
                                                            <form method="post" class="d-inline">
                                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                                                <input type="hidden" name="action" value="toggle_question">
                                                                <input type="hidden" name="question_id" value="<?php echo (int) ($qRow['id'] ?? 0); ?>">
                                                                <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="ri-eye-line" aria-hidden="true"></i></button>
                                                            </form>
                                                            <form method="post" class="d-inline">
                                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                                                <input type="hidden" name="action" value="delete_question">
                                                                <input type="hidden" name="question_id" value="<?php echo (int) ($qRow['id'] ?? 0); ?>">
                                                                <button class="btn btn-sm btn-outline-danger" type="submit" onclick="return confirm('Delete this question?');"><i class="ri-delete-bin-6-line" aria-hidden="true"></i></button>
                                                            </form>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
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
            var type = document.getElementById('questionType');
            var mc = document.getElementById('mcBlock');
            var tf = document.getElementById('tfBlock');
            var sa = document.getElementById('saBlock');
            if (!type || !mc || !tf || !sa) return;

            function updateBlocks() {
                var v = (type.value || '').toLowerCase();
                mc.style.display = v === 'multiple_choice' ? '' : 'none';
                tf.style.display = v === 'true_false' ? '' : 'none';
                sa.style.display = v === 'short_answer' ? '' : 'none';
            }

            type.addEventListener('change', updateBlocks);
            updateBlocks();
        })();
    </script>
</body>
</html>
