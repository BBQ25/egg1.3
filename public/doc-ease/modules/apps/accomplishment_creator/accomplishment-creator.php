<?php include __DIR__ . '/../../../layouts/session.php'; ?>
<?php require_any_role(['admin', 'teacher']); ?>

<?php
require_once __DIR__ . '/../accomplishment_report/accomplishments.php';
require_once __DIR__ . '/../../../includes/ai_credits.php';
require_once __DIR__ . '/../../../includes/accomplishment_creator.php';
require_once __DIR__ . '/../../../includes/audit.php';

ensure_accomplishment_tables($conn);
ai_credit_ensure_system($conn);
ensure_audit_logs_table($conn);

$role = isset($_SESSION['user_role']) ? normalize_role((string) $_SESSION['user_role']) : '';
if ($role !== 'admin' && empty($_SESSION['is_active'])) {
    deny_access(403, 'Forbidden: account not approved.');
}

$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
if ($userId <= 0) {
    deny_access(401, 'Unauthorized.');
}

if (!function_exists('acc_creator_h')) {
    function acc_creator_h($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('acc_creator_norm_label')) {
    function acc_creator_norm_label($value) {
        $s = preg_replace('/\s+/', ' ', trim((string) $value));
        return strtolower(trim((string) $s));
    }
}

if (!function_exists('acc_creator_parse_dates')) {
    function acc_creator_parse_dates($rawCsv) {
        $rawCsv = trim((string) $rawCsv);
        if ($rawCsv === '') return [];

        $parts = preg_split('/\s*,\s*/', $rawCsv);
        if (!is_array($parts)) return [];

        $dates = [];
        $seen = [];
        foreach ($parts as $part) {
            $date = trim((string) $part);
            if ($date === '') continue;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
            if (isset($seen[$date])) continue;
            $seen[$date] = true;
            $dates[] = $date;
        }

        sort($dates);
        return $dates;
    }
}

if (!function_exists('acc_creator_dates_from_rows')) {
    function acc_creator_dates_from_rows(array $rows) {
        $dates = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $date = trim((string) ($row['date'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
            if (isset($seen[$date])) continue;
            $seen[$date] = true;
            $dates[] = $date;
        }
        sort($dates);
        return $dates;
    }
}

if (!function_exists('acc_creator_style_options')) {
    function acc_creator_style_options() {
        if (function_exists('acc_ai_creator_style_options')) {
            $opts = acc_ai_creator_style_options();
            if (is_array($opts) && count($opts) > 0) return $opts;
        }
        return [
            'concise' => 'Concise',
            'balanced' => 'Balanced',
            'detailed' => 'Detailed',
            'exaggerate' => 'Exaggerate',
        ];
    }
}

if (!function_exists('acc_creator_normalize_style_hint')) {
    function acc_creator_normalize_style_hint($styleHint) {
        if (function_exists('acc_ai_creator_normalize_style_hint')) {
            return (string) acc_ai_creator_normalize_style_hint($styleHint);
        }
        $styleHint = strtolower(trim((string) $styleHint));
        $opts = acc_creator_style_options();
        return isset($opts[$styleHint]) ? $styleHint : 'balanced';
    }
}

if (!function_exists('acc_creator_default_clarifying_questions')) {
    function acc_creator_default_clarifying_questions() {
        if (function_exists('acc_ai_creator_default_clarifying_questions')) {
            $q = acc_ai_creator_default_clarifying_questions();
            if (is_array($q) && count($q) > 0) {
                return array_slice(array_values($q), 0, 5);
            }
        }
        return [
            'What specific learner outcome did you target for this session?',
            'What concrete classroom or laboratory tasks were actually completed?',
            'What evidence of student progress or participation did you observe?',
            'What constraints or issues occurred, and how did you address them?',
            'What follow-up action should be reflected in the next entry?',
        ];
    }
}

if (!function_exists('acc_creator_parse_clarifying_qas')) {
    function acc_creator_parse_clarifying_qas($rawJson, $maxItems = 5) {
        $rawJson = trim((string) $rawJson);
        $maxItems = (int) $maxItems;
        if ($maxItems < 1) $maxItems = 1;
        if ($maxItems > 5) $maxItems = 5;
        if ($rawJson === '') return [];
        if (strlen($rawJson) > 20000) {
            $rawJson = substr($rawJson, 0, 20000);
        }

        $decoded = json_decode($rawJson, true);
        if (!is_array($decoded)) return [];

        $rows = [];
        $seen = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) continue;
            $question = trim((string) preg_replace('/\s+/', ' ', (string) ($item['question'] ?? '')));
            $answer = trim((string) preg_replace('/\s+/', ' ', (string) ($item['answer'] ?? '')));
            if ($question === '' || $answer === '') continue;

            $question = preg_replace('/^\s*(?:[-*]+|\d+[.)])\s*/', '', (string) $question);
            $question = trim((string) $question, " \t\n\r\0\x0B-:;");
            if ($question === '') continue;
            if (!preg_match('/[?.!]$/', $question)) $question .= '?';

            if (strlen($question) > 220) $question = rtrim(substr($question, 0, 217)) . '...';
            if (strlen($answer) > 800) $answer = rtrim(substr($answer, 0, 797)) . '...';

            $key = strtolower($question);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            $rows[] = ['question' => $question, 'answer' => $answer];
            if (count($rows) >= $maxItems) break;
        }

        return $rows;
    }
}

$subjectOptions = acc_user_subject_options($conn, $userId);
if (count($subjectOptions) === 0) {
    $subjectOptions = ['Monthly Accomplishment'];
}

$subjectMap = [];
foreach ($subjectOptions as $opt) {
    $subjectMap[acc_creator_norm_label($opt)] = $opt;
}

$outputTtlMinutes = acc_creator_output_ttl_get_minutes($conn);
$activeBatch = acc_creator_get_active_batch($userId);
$batchSubject = is_array($activeBatch) ? trim((string) ($activeBatch['subject'] ?? '')) : '';
$batchContext = is_array($activeBatch) ? trim((string) ($activeBatch['context'] ?? '')) : '';
$batchStyleHint = is_array($activeBatch) ? trim((string) ($activeBatch['style_hint'] ?? '')) : '';
$batchDates = is_array($activeBatch) ? acc_creator_dates_from_rows((array) ($activeBatch['rows'] ?? [])) : [];

$selectedSubject = isset($_POST['subject'])
    ? trim((string) $_POST['subject'])
    : ($batchSubject !== '' ? $batchSubject : (string) $subjectOptions[0]);
$selectedSubjectKey = acc_creator_norm_label($selectedSubject);
if ($selectedSubjectKey === '' || !isset($subjectMap[$selectedSubjectKey])) {
    $selectedSubject = (string) $subjectOptions[0];
} else {
    $selectedSubject = (string) $subjectMap[$selectedSubjectKey];
}

$activityContext = isset($_POST['activity_context'])
    ? trim((string) $_POST['activity_context'])
    : $batchContext;
$selectedDatesRaw = isset($_POST['selected_dates'])
    ? trim((string) $_POST['selected_dates'])
    : implode(', ', $batchDates);
$selectedDates = acc_creator_parse_dates($selectedDatesRaw);
$selectedDatesRaw = implode(', ', $selectedDates);
$styleHint = isset($_POST['style_hint'])
    ? trim((string) $_POST['style_hint'])
    : ($batchStyleHint !== '' ? $batchStyleHint : 'balanced');
$styleHint = acc_creator_normalize_style_hint($styleHint);
$styleOptions = acc_creator_style_options();
$defaultClarifyingQuestions = acc_creator_default_clarifying_questions();
$clarifyingQaRaw = isset($_POST['clarifying_qa_json'])
    ? trim((string) $_POST['clarifying_qa_json'])
    : '';
$clarifyingQas = acc_creator_parse_clarifying_qas($clarifyingQaRaw, 5);

$flash = '';
$flashType = 'info';
$creditCost = count($selectedDates);
$isCreditExempt = ($role === 'admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjaxRequest = strtolower(trim((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''))) === 'xmlhttprequest';
    $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    if (!csrf_validate($csrf)) {
        if ($isAjaxRequest) {
            header('Content-Type: application/json; charset=UTF-8');
            http_response_code(403);
            echo json_encode([
                'ok' => false,
                'message' => 'Security check failed (CSRF). Please refresh and try again.',
                'questions' => [],
            ]);
            exit;
        }
        $flash = 'Security check failed (CSRF). Please try again.';
        $flashType = 'danger';
    } else {
        $action = isset($_POST['action']) ? (string) $_POST['action'] : '';
        if ($action === 'generate_clarifying_questions') {
            $payload = ['ok' => false, 'message' => '', 'questions' => []];
            if ($activityContext === '') {
                $payload['message'] = 'Please enter activity/context input.';
            } elseif (strlen($activityContext) > 5000) {
                $payload['message'] = 'Activity/context is too long. Max 5000 characters.';
            } elseif (count($selectedDates) === 0) {
                $payload['message'] = 'Select at least one date in the calendar.';
            } elseif (count($selectedDates) > 31) {
                $payload['message'] = 'You can generate up to 31 dates per request.';
            } else {
                [$okQuestions, $questionsOrMsg] = acc_generate_creator_clarifying_questions_with_ai(
                    $selectedSubject,
                    $activityContext,
                    $selectedDates,
                    $styleHint,
                    5
                );

                if ($okQuestions) {
                    $questions = is_array($questionsOrMsg) ? array_values($questionsOrMsg) : [];
                    if (count($questions) === 0) {
                        $questions = $defaultClarifyingQuestions;
                    }
                    $payload['ok'] = true;
                    $payload['questions'] = array_slice($questions, 0, 5);
                } else {
                    $payload['message'] = (string) $questionsOrMsg;
                    $payload['questions'] = $defaultClarifyingQuestions;
                }
            }

            if ($isAjaxRequest) {
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode($payload);
                exit;
            }

            $flash = $payload['ok'] ? 'Clarifying questions prepared.' : ($payload['message'] !== '' ? $payload['message'] : 'Unable to prepare clarifying questions.');
            $flashType = $payload['ok'] ? 'info' : 'warning';
        } elseif ($action === 'generate_descriptions') {
            if ($activityContext === '') {
                $flash = 'Please enter activity/context input.';
                $flashType = 'warning';
            } elseif (strlen($activityContext) > 5000) {
                $flash = 'Activity/context is too long. Max 5000 characters.';
                $flashType = 'warning';
            } elseif (count($selectedDates) === 0) {
                $flash = 'Select at least one date in the calendar.';
                $flashType = 'warning';
            } elseif (count($selectedDates) > 31) {
                $flash = 'You can generate up to 31 dates per request.';
                $flashType = 'warning';
            } else {
                $creditCost = count($selectedDates);
                $chargedCredits = 0;
                $canCallAi = true;

                if (!$isCreditExempt) {
                    [$okConsume, $consumeMsg] = ai_credit_try_consume_count($conn, $userId, $creditCost);
                    if (!$okConsume) {
                        $flash = (string) $consumeMsg;
                        $flashType = 'warning';
                        $canCallAi = false;
                    } else {
                        $chargedCredits = $creditCost;
                    }
                }

                if ($canCallAi) {
                    [$okAi, $rowsOrMsg] = acc_generate_descriptions_with_ai(
                        $selectedSubject,
                        $activityContext,
                        $selectedDates,
                        $styleHint,
                        $clarifyingQas
                    );

                    if (!$okAi) {
                        if ($chargedCredits > 0) {
                            ai_credit_refund($conn, $userId, $chargedCredits);
                        }
                        $flash = (string) $rowsOrMsg;
                        $flashType = 'warning';
                    } else {
                        $generatedRows = is_array($rowsOrMsg) ? $rowsOrMsg : [];
                        if (count($generatedRows) === 0) {
                            if ($chargedCredits > 0) {
                                ai_credit_refund($conn, $userId, $chargedCredits);
                            }
                            $flash = 'AI did not return usable descriptions. Please try again.';
                            $flashType = 'warning';
                        } else {
                            $savedBatch = acc_creator_store_batch(
                                $userId,
                                $selectedSubject,
                                $activityContext,
                                $styleHint,
                                $generatedRows,
                                $outputTtlMinutes
                            );
                            if (!is_array($savedBatch)) {
                                if ($chargedCredits > 0) {
                                    ai_credit_refund($conn, $userId, $chargedCredits);
                                }
                                $flash = 'Unable to store generated output. Please try again.';
                                $flashType = 'danger';
                                $activeBatch = null;
                            } else {
                                $activeBatch = $savedBatch;
                                $selectedDates = acc_creator_dates_from_rows((array) ($activeBatch['rows'] ?? []));
                                $selectedDatesRaw = implode(', ', $selectedDates);
                            }

                            $remainingText = '';
                            if (!$isCreditExempt) {
                                [$okCreditNow, $creditNowOrMsg] = ai_credit_get_user_status($conn, $userId);
                                if ($okCreditNow && is_array($creditNowOrMsg)) {
                                    $remainingText = ' Remaining AI credits: ' . number_format((float) ($creditNowOrMsg['remaining'] ?? 0), 2, '.', '') . '.';
                                }
                            }

                            if (is_array($activeBatch)) {
                                $flash = 'Generated ' . count($generatedRows) . ' accomplishment description(s). Available for ' .
                                    (int) $outputTtlMinutes . ' minute(s).' .
                                    ($isCreditExempt
                                        ? ' Admin account is credit-exempt.'
                                        : (' ' . $creditCost . ' AI credit(s) used.' . $remainingText));
                                $flashType = 'success';

                                audit_log($conn, 'accomplishment.creator.generated', 'accomplishment_creator', $userId, null, [
                                    'subject' => $selectedSubject,
                                    'date_count' => count($selectedDates),
                                    'credit_cost' => $isCreditExempt ? 0 : $creditCost,
                                    'ttl_minutes' => (int) $outputTtlMinutes,
                                ]);
                            }
                        }
                    }
                }
            }
        } elseif ($action === 'move_to_accomplishment_db') {
            $activeBatch = acc_creator_get_active_batch($userId);
            if (!is_array($activeBatch)) {
                $flash = 'Generated output already expired. Please generate again.';
                $flashType = 'warning';
            } else {
                $selectedIds = [];
                if (isset($_POST['output_ids']) && is_array($_POST['output_ids'])) {
                    foreach ($_POST['output_ids'] as $rawId) {
                        $id = strtolower(trim((string) $rawId));
                        if ($id !== '') $selectedIds[$id] = true;
                    }
                }
                $postedTypes = [];
                if (isset($_POST['output_type']) && is_array($_POST['output_type'])) {
                    foreach ($_POST['output_type'] as $rawId => $rawType) {
                        $id = strtolower(trim((string) $rawId));
                        if ($id === '') continue;
                        $postedTypes[$id] = acc_creator_normalize_output_type((string) $rawType, '');
                    }
                }

                if (count($selectedIds) === 0) {
                    $flash = 'Select at least one generated output to move.';
                    $flashType = 'warning';
                } else {
                    $rowsToMove = [];
                    foreach ((array) ($activeBatch['rows'] ?? []) as $row) {
                        $rowId = strtolower(trim((string) ($row['id'] ?? '')));
                        if ($rowId === '' || !isset($selectedIds[$rowId])) continue;
                        $rowsToMove[] = $row;
                    }

                    if (count($rowsToMove) === 0) {
                        $flash = 'Selected output is no longer available.';
                        $flashType = 'warning';
                    } else {
                        $subjectForMove = trim((string) ($activeBatch['subject'] ?? $selectedSubject));
                        if ($subjectForMove === '') $subjectForMove = $selectedSubject;
                        $moved = 0;
                        $failed = [];
                        $movedIds = [];

                        foreach ($rowsToMove as $row) {
                            $rowId = strtolower(trim((string) ($row['id'] ?? '')));
                            $entryDate = trim((string) ($row['date'] ?? ''));
                            $description = trim((string) ($row['description'] ?? ''));
                            if ($description === '') {
                                $failed[] = $entryDate . ': Description is required.';
                                continue;
                            }
                            $typeFromRow = (string) ($row['type'] ?? ($row['title'] ?? ''));
                            $type = isset($postedTypes[$rowId])
                                ? acc_creator_normalize_output_type((string) $postedTypes[$rowId], $description)
                                : acc_creator_normalize_output_type($typeFromRow, $description);
                            $remarks = acc_creator_normalize_output_remarks_full((string) ($row['remarks'] ?? ''), $description);
                            [$okMove, $moveRes] = acc_create_entry(
                                $conn,
                                $userId,
                                $entryDate,
                                $type,
                                $description,
                                $subjectForMove,
                                $remarks
                            );
                            if ($okMove) {
                                $moved++;
                                if ($rowId !== '') $movedIds[] = $rowId;
                            } else {
                                $failed[] = $entryDate . ': ' . (string) $moveRes;
                            }
                        }

                        if ($moved > 0) {
                            acc_creator_remove_batch_rows($userId, $movedIds);
                            $activeBatch = acc_creator_get_active_batch($userId);

                            $flash = 'Moved ' . $moved . ' generated output(s) to the Accomplishment database.';
                            if (!empty($failed)) {
                                $flash .= ' Some items failed.';
                                $flashType = 'warning';
                            } else {
                                $flashType = 'success';
                            }

                            audit_log($conn, 'accomplishment.creator.moved_to_db', 'accomplishment_creator', $userId, null, [
                                'subject' => $subjectForMove,
                                'moved_count' => $moved,
                                'failed_count' => count($failed),
                            ]);
                        } else {
                            $flash = !empty($failed)
                                ? ('Unable to move selected output. First error: ' . (string) $failed[0])
                                : 'Unable to move selected output.';
                            $flashType = 'danger';
                        }
                    }
                }
            }
        } elseif ($action === 'clear_generated_output') {
            acc_creator_clear_batch($userId);
            $activeBatch = null;
            $flash = 'Generated output cleared.';
            $flashType = 'success';
        } else {
            $flash = 'Invalid request.';
            $flashType = 'danger';
        }
    }
}

[$okCredit, $creditStateOrMsg] = ai_credit_get_user_status($conn, $userId);
$creditState = ['limit' => 0.0, 'used' => 0.0, 'remaining' => 0.0];
if ($okCredit && is_array($creditStateOrMsg)) {
    $creditState = [
        'limit' => (float) ($creditStateOrMsg['limit'] ?? 0),
        'used' => (float) ($creditStateOrMsg['used'] ?? 0),
        'remaining' => (float) ($creditStateOrMsg['remaining'] ?? 0),
    ];
}

$activeBatch = acc_creator_get_active_batch($userId);
$generatedRows = is_array($activeBatch) ? (array) ($activeBatch['rows'] ?? []) : [];
$batchRemainingSeconds = is_array($activeBatch) ? (int) ($activeBatch['remaining_seconds'] ?? 0) : 0;
$batchExpiresAt = is_array($activeBatch) ? (int) ($activeBatch['expires_at'] ?? 0) : 0;

if (!isset($_POST['selected_dates'])) {
    $selectedDates = is_array($activeBatch) ? acc_creator_dates_from_rows($generatedRows) : [];
    $selectedDatesRaw = implode(', ', $selectedDates);
}
if (!isset($_POST['activity_context']) && is_array($activeBatch)) {
    $activityContext = trim((string) ($activeBatch['context'] ?? $activityContext));
}
if (!isset($_POST['style_hint']) && is_array($activeBatch)) {
    $styleHint = trim((string) ($activeBatch['style_hint'] ?? $styleHint));
    $styleHint = acc_creator_normalize_style_hint($styleHint);
}

$copyAllText = '';
if (count($generatedRows) > 0) {
    $lines = [];
    foreach ($generatedRows as $row) {
        $typeValue = acc_creator_normalize_output_type((string) ($row['type'] ?? ($row['title'] ?? '')), (string) ($row['description'] ?? ''));
        $remarksValue = acc_creator_normalize_output_remarks((string) ($row['remarks'] ?? ''));
        $lines[] = ((string) ($row['date'] ?? '')) .
            ' | ' . $typeValue .
            ' | ' . $remarksValue .
            ' | ' . ((string) ($row['description'] ?? ''));
    }
    $copyAllText = implode("\n", $lines);
}
?>

<?php include __DIR__ . '/../../../layouts/main.php'; ?>

<head>
    <title>Accomplishment Creator | E-Record</title>
    <?php include __DIR__ . '/../../../layouts/title-meta.php'; ?>
    <link href="assets/vendor/flatpickr/flatpickr.min.css" rel="stylesheet" type="text/css" />
    <?php include __DIR__ . '/../../../layouts/head-css.php'; ?>
    <style>
        .creator-card { border-radius: 14px; }
        .creator-card .card-header { border-bottom: 1px solid rgba(0, 0, 0, 0.05); }
        .creator-note { font-size: 12px; color: #6c757d; }
        .creator-credit-box {
            border: 1px dashed rgba(59, 125, 221, 0.35);
            border-radius: 12px;
            background: rgba(59, 125, 221, 0.06);
            padding: 12px;
        }
        .generated-description {
            white-space: pre-wrap;
            line-height: 1.35;
        }
        .date-chip {
            display: inline-flex;
            align-items: center;
            font-size: 12px;
            font-weight: 600;
            border-radius: 999px;
            padding: 3px 10px;
            background: rgba(16, 185, 129, 0.14);
            color: #047857;
            border: 1px solid rgba(16, 185, 129, 0.22);
        }
        .creator-qa-item {
            border: 1px solid rgba(59, 125, 221, 0.24);
            border-radius: 12px;
            background: rgba(59, 125, 221, 0.05);
            padding: 12px;
        }
        .creator-qa-question {
            font-weight: 600;
            color: #1d3557;
            font-size: 13px;
            margin-bottom: 8px;
        }
        .creator-qa-answer {
            min-height: 92px;
        }
        .creator-qa-loader {
            border: 1px dashed rgba(59, 125, 221, 0.3);
            border-radius: 10px;
            padding: 10px 12px;
            background: rgba(59, 125, 221, 0.05);
            color: #1d4e89;
        }
    </style>
</head>

<body>
<div class="wrapper">
    <?php include __DIR__ . '/../../../layouts/menu.php'; ?>

    <div class="content-page">
        <div class="content">
            <div class="container-fluid">

                <div class="row">
                    <div class="col-12">
                        <div class="page-title-box">
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="javascript: void(0);">Apps</a></li>
                                    <li class="breadcrumb-item active">Accomplishment Creator</li>
                                </ol>
                            </div>
                            <h4 class="page-title">Accomplishment Creator</h4>
                        </div>
                    </div>
                </div>

                <?php if ($flash !== ''): ?>
                    <div class="alert alert-<?php echo acc_creator_h($flashType); ?>" role="alert">
                        <?php echo acc_creator_h($flash); ?>
                    </div>
                <?php endif; ?>

                <div class="row g-3">
                    <div class="col-lg-8">
                        <div class="card creator-card h-100">
                            <div class="card-header">
                                <h5 class="mb-0">Generate Descriptions</h5>
                            </div>
                            <div class="card-body">
                                <form method="post" id="accomplishment-creator-form">
                                    <input type="hidden" name="csrf_token" value="<?php echo acc_creator_h(csrf_token()); ?>">
                                    <input type="hidden" name="action" value="generate_descriptions">
                                    <input type="hidden" name="clarifying_qa_json" id="creator-clarifying-qa-json" value="<?php echo acc_creator_h($clarifyingQaRaw); ?>">

                                    <div class="mb-3">
                                        <label class="form-label">Subject</label>
                                        <select class="form-select" name="subject" required>
                                            <?php foreach ($subjectOptions as $option): ?>
                                                <option value="<?php echo acc_creator_h($option); ?>" <?php echo $selectedSubject === $option ? 'selected' : ''; ?>>
                                                    <?php echo acc_creator_h($option); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Activity / Context</label>
                                        <textarea class="form-control"
                                                  name="activity_context"
                                                  rows="5"
                                                  maxlength="5000"
                                                  placeholder="Example: Facilitated lab activities on loops and arrays, checked outputs, and provided feedback for revisions."
                                                  required><?php echo acc_creator_h($activityContext); ?></textarea>
                                        <div class="creator-note mt-1">Describe what happened so AI can produce grounded descriptions.</div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Style</label>
                                        <select class="form-select" name="style_hint">
                                            <?php foreach ($styleOptions as $styleKey => $styleLabel): ?>
                                                <option value="<?php echo acc_creator_h($styleKey); ?>" <?php echo $styleHint === $styleKey ? 'selected' : ''; ?>>
                                                    <?php echo acc_creator_h($styleLabel); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="creator-note mt-1">
                                            Type choices are <strong>Lecture</strong>, <strong>Laboratory</strong>, and <strong>Lecture &amp; Laboratory</strong>. Remarks is <strong>Accomplished</strong> or <strong>On-going</strong>.
                                            <br>When <strong>Exaggerate</strong> is selected, each generated description is formatted as at least <strong>3 continuation bullet points</strong>.
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Select Dates (Calendar)</label>
                                        <input type="text"
                                               class="form-control"
                                               id="creator-dates"
                                               name="selected_dates"
                                               value="<?php echo acc_creator_h($selectedDatesRaw); ?>"
                                               placeholder="YYYY-MM-DD, YYYY-MM-DD"
                                               required>
                                        <div class="creator-note mt-1">
                                            <span id="creator-date-count"><?php echo (int) count($selectedDates); ?></span> date(s) selected.
                                            <?php if (!$isCreditExempt): ?>
                                                1 AI credit per date.
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                        <a href="monthly-accomplishment.php" class="btn btn-light">
                                            <i class="ri-file-text-line me-1"></i> Monthly Accomplishment
                                        </a>
                                        <button type="submit" class="btn btn-primary" id="creator-generate-btn">
                                            <i class="ri-magic-line me-1"></i> Generate
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-4">
                        <div class="card creator-card h-100">
                            <div class="card-header">
                                <h5 class="mb-0">Usage</h5>
                            </div>
                            <div class="card-body">
                                <div class="creator-credit-box">
                                    <?php if ($isCreditExempt): ?>
                                        <div class="fw-semibold">Admin account</div>
                                        <div class="small text-muted">Credit-exempt for this generator.</div>
                                    <?php else: ?>
                                        <div class="fw-semibold">AI Credits</div>
                                        <div class="small">Remaining: <strong><?php echo number_format((float) ($creditState['remaining'] ?? 0), 2, '.', ''); ?></strong></div>
                                        <div class="small">Used: <?php echo number_format((float) ($creditState['used'] ?? 0), 2, '.', ''); ?> / <?php echo number_format((float) ($creditState['limit'] ?? 0), 2, '.', ''); ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="mt-3 small text-muted">
                                    Tip: pick all target dates first, then submit once. AI generates one row per date with Type, Description, and Remarks. Exaggerate style outputs multi-bullet descriptions.
                                </div>
                                <div class="mt-2 small text-muted">
                                    Generated output availability: <strong><?php echo (int) $outputTtlMinutes; ?> minute(s)</strong> (set by admin).
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card creator-card mt-3">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            <h5 class="mb-0">Generated Output</h5>
                            <?php if (count($generatedRows) > 0): ?>
                                <div class="small text-muted mt-1"
                                     id="creator-output-expiry"
                                     data-remaining="<?php echo (int) $batchRemainingSeconds; ?>"
                                     data-expires-at="<?php echo (int) $batchExpiresAt; ?>">
                                    Available for <strong id="creator-output-remaining-text"></strong>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php if ($copyAllText !== '' && count($generatedRows) > 0): ?>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-sm btn-outline-primary js-copy-all">
                                    <i class="ri-file-copy-line me-1"></i> Copy All
                                </button>
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo acc_creator_h(csrf_token()); ?>">
                                    <input type="hidden" name="action" value="clear_generated_output">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="ri-delete-bin-line me-1"></i> Clear
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if (count($generatedRows) === 0): ?>
                            <div class="text-muted">No generated descriptions yet. Submit the form above to generate output.</div>
                        <?php else: ?>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?php echo acc_creator_h(csrf_token()); ?>">
                                <input type="hidden" name="action" value="move_to_accomplishment_db">

                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mb-0">
                                        <thead>
                                        <tr>
                                            <th style="width: 44px;">
                                                <div class="form-check mb-0">
                                                    <input class="form-check-input" type="checkbox" id="creator-check-all" checked>
                                                </div>
                                            </th>
                                            <th style="width: 170px;">Date</th>
                                            <th style="width: 220px;">Type</th>
                                            <th>Description</th>
                                            <th style="width: 130px;">Remarks</th>
                                            <th class="text-end" style="width: 120px;">Action</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($generatedRows as $idx => $row): ?>
                                            <?php
                                            $copyId = 'copy-desc-' . (int) $idx;
                                            $outputId = (string) ($row['id'] ?? '');
                                            $dateValue = (string) ($row['date'] ?? '');
                                            $typeValue = acc_creator_normalize_output_type((string) ($row['type'] ?? ($row['title'] ?? '')), (string) ($row['description'] ?? ''));
                                            $descValue = (string) ($row['description'] ?? '');
                                            $remarksValue = acc_creator_normalize_output_remarks((string) ($row['remarks'] ?? ''));
                                            ?>
                                            <tr>
                                                <td>
                                                    <div class="form-check mb-0">
                                                        <input class="form-check-input js-output-check"
                                                               type="checkbox"
                                                               name="output_ids[]"
                                                               value="<?php echo acc_creator_h($outputId); ?>"
                                                                checked>
                                                    </div>
                                                </td>
                                                <td><span class="date-chip"><?php echo acc_creator_h($dateValue); ?></span></td>
                                                <td>
                                                    <select class="form-select form-select-sm" name="output_type[<?php echo acc_creator_h($outputId); ?>]">
                                                        <option value="Lecture" <?php echo $typeValue === 'Lecture' ? 'selected' : ''; ?>>Lecture</option>
                                                        <option value="Laboratory" <?php echo $typeValue === 'Laboratory' ? 'selected' : ''; ?>>Laboratory</option>
                                                        <option value="Lecture &amp; Laboratory" <?php echo $typeValue === 'Lecture & Laboratory' ? 'selected' : ''; ?>>Lecture &amp; Laboratory</option>
                                                    </select>
                                                </td>
                                                <td>
                                                    <div class="generated-description"><?php echo nl2br(acc_creator_h($descValue)); ?></div>
                                                    <textarea class="d-none" id="<?php echo acc_creator_h($copyId); ?>"><?php echo acc_creator_h($descValue); ?></textarea>
                                                </td>
                                                <td>
                                                    <span class="badge <?php echo $remarksValue === 'On-going' ? 'bg-warning-subtle text-warning' : 'bg-success-subtle text-success'; ?>">
                                                        <?php echo acc_creator_h($remarksValue); ?>
                                                    </span>
                                                </td>
                                                <td class="text-end">
                                                    <button type="button" class="btn btn-sm btn-outline-secondary js-copy-one" data-copy-id="<?php echo acc_creator_h($copyId); ?>">
                                                        <i class="ri-file-copy-line me-1"></i>Copy
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3">
                                    <div class="small text-muted">
                                        Move selected outputs into the Accomplishment database as new entries.
                                    </div>
                                    <button type="submit" class="btn btn-success">
                                        <i class="ri-database-2-line me-1"></i> Move Selected to Accomplishment DB
                                    </button>
                                </div>
                            </form>
                            <textarea class="d-none" id="copy-all-text"><?php echo acc_creator_h($copyAllText); ?></textarea>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="modal fade" id="creator-clarifying-modal" tabindex="-1" aria-labelledby="creatorClarifyingModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="creatorClarifyingModalLabel">
                                    Doctor of Education AI: Clarifying Questions
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div class="small text-muted mb-2">
                                    Answer briefly so generation can be more accurate and context-grounded. Answer at least one question to continue.
                                </div>
                                <div class="alert alert-warning d-none" id="creator-qa-warning" role="alert"></div>
                                <div class="creator-qa-loader d-none" id="creator-qa-loading">
                                    <i class="ri-loader-4-line me-1"></i>
                                    Preparing up to 5 expert clarifying questions...
                                </div>
                                <div class="d-grid gap-2 mt-2" id="creator-qa-list"></div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                                <button type="button" class="btn btn-primary" id="creator-qa-continue-btn">
                                    <i class="ri-send-plane-line me-1"></i> Use Answers &amp; Generate
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <?php include __DIR__ . '/../../../layouts/footer.php'; ?>
    </div>
</div>

<?php include __DIR__ . '/../../../layouts/right-sidebar.php'; ?>
<?php include __DIR__ . '/../../../layouts/footer-scripts.php'; ?>
<script src="assets/vendor/flatpickr/flatpickr.min.js"></script>
<script>
    (function () {
        const creatorForm = document.getElementById('accomplishment-creator-form');
        const csrfInput = creatorForm ? creatorForm.querySelector('input[name="csrf_token"]') : null;
        const subjectInput = creatorForm ? creatorForm.querySelector('[name="subject"]') : null;
        const contextInput = creatorForm ? creatorForm.querySelector('[name="activity_context"]') : null;
        const styleInput = creatorForm ? creatorForm.querySelector('[name="style_hint"]') : null;
        const clarifyingQaInput = document.getElementById('creator-clarifying-qa-json');
        const generateBtn = document.getElementById('creator-generate-btn');

        const dateInput = document.getElementById('creator-dates');
        const dateCount = document.getElementById('creator-date-count');
        const copyButtons = document.querySelectorAll('.js-copy-one');
        const copyAllBtn = document.querySelector('.js-copy-all');
        const copyAllText = document.getElementById('copy-all-text');
        const checkAllEl = document.getElementById('creator-check-all');
        const rowChecks = document.querySelectorAll('.js-output-check');
        const expiryEl = document.getElementById('creator-output-expiry');
        const expiryTextEl = document.getElementById('creator-output-remaining-text');

        const clarifyingModalEl = document.getElementById('creator-clarifying-modal');
        const qaListEl = document.getElementById('creator-qa-list');
        const qaWarningEl = document.getElementById('creator-qa-warning');
        const qaLoadingEl = document.getElementById('creator-qa-loading');
        const qaContinueBtn = document.getElementById('creator-qa-continue-btn');
        const clarifyingModal = (clarifyingModalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal)
            ? bootstrap.Modal.getOrCreateInstance(clarifyingModalEl)
            : null;

        const defaultClarifyingQuestions = <?php echo json_encode(array_values(array_slice($defaultClarifyingQuestions, 0, 5))); ?>;
        let modalReadyToSubmit = false;
        let qaRequestSeq = 0;

        const normalizeDateList = (value) => {
            const raw = String(value || '');
            const parts = raw.split(',');
            const seen = Object.create(null);
            const rows = [];
            parts.forEach((part) => {
                const token = part.trim();
                if (!/^\d{4}-\d{2}-\d{2}$/.test(token)) return;
                if (seen[token]) return;
                seen[token] = true;
                rows.push(token);
            });
            rows.sort();
            return rows;
        };

        const refreshDateCount = () => {
            if (!dateInput || !dateCount) return;
            const dates = normalizeDateList(dateInput.value);
            dateCount.textContent = String(dates.length);
        };

        const setQaWarning = (message) => {
            if (!qaWarningEl) return;
            const text = String(message || '').trim();
            qaWarningEl.textContent = text;
            qaWarningEl.classList.toggle('d-none', text === '');
        };

        const setQaLoading = (isLoading) => {
            if (qaLoadingEl) qaLoadingEl.classList.toggle('d-none', !isLoading);
            if (qaContinueBtn) qaContinueBtn.disabled = !!isLoading;
            if (generateBtn) generateBtn.disabled = !!isLoading;
        };

        const renderClarifyingQuestions = (questions) => {
            if (!qaListEl) return 0;
            qaListEl.innerHTML = '';
            const rows = Array.isArray(questions) ? questions.slice(0, 5) : [];
            let count = 0;

            rows.forEach((question, index) => {
                const q = String(question || '').trim();
                if (!q) return;

                const block = document.createElement('div');
                block.className = 'creator-qa-item';

                const qLabel = document.createElement('div');
                qLabel.className = 'creator-qa-question';
                qLabel.textContent = (index + 1) + '. ' + q;
                block.appendChild(qLabel);

                const answerInput = document.createElement('textarea');
                answerInput.className = 'form-control creator-qa-answer';
                answerInput.rows = 3;
                answerInput.maxLength = 800;
                answerInput.placeholder = 'Your answer...';
                answerInput.setAttribute('data-question', q);
                block.appendChild(answerInput);

                qaListEl.appendChild(block);
                count += 1;
            });

            return count;
        };

        const requestClarifyingQuestions = async () => {
            const body = new URLSearchParams();
            body.set('csrf_token', csrfInput ? String(csrfInput.value || '') : '');
            body.set('action', 'generate_clarifying_questions');
            body.set('subject', subjectInput ? String(subjectInput.value || '') : '');
            body.set('activity_context', contextInput ? String(contextInput.value || '') : '');
            body.set('style_hint', styleInput ? String(styleInput.value || '') : '');
            body.set('selected_dates', dateInput ? String(dateInput.value || '') : '');

            const response = await fetch(window.location.pathname + window.location.search, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: body.toString()
            });

            const text = await response.text();
            let json = null;
            try {
                json = JSON.parse(text);
            } catch (err) {
                throw new Error('Unable to parse clarifying question response.');
            }
            if (!json || typeof json !== 'object') {
                throw new Error('Invalid clarifying question response.');
            }
            return {
                ok: !!json.ok,
                message: String(json.message || ''),
                questions: Array.isArray(json.questions) ? json.questions : []
            };
        };

        if (dateInput && typeof flatpickr !== 'undefined') {
            flatpickr(dateInput, {
                mode: 'multiple',
                dateFormat: 'Y-m-d',
                allowInput: true,
                onChange: function (selectedDates, dateStr) {
                    dateInput.value = dateStr;
                    refreshDateCount();
                },
                onClose: function (selectedDates, dateStr) {
                    dateInput.value = dateStr;
                    refreshDateCount();
                }
            });
            refreshDateCount();
        }

        const copyText = async (text) => {
            const value = String(text || '');
            if (!value) return false;

            if (navigator.clipboard && navigator.clipboard.writeText) {
                try {
                    await navigator.clipboard.writeText(value);
                    return true;
                } catch (err) {
                    // Fallback below.
                }
            }

            const temp = document.createElement('textarea');
            temp.value = value;
            document.body.appendChild(temp);
            temp.select();
            const ok = document.execCommand('copy');
            document.body.removeChild(temp);
            return !!ok;
        };

        copyButtons.forEach((btn) => {
            btn.addEventListener('click', async function () {
                const sourceId = this.getAttribute('data-copy-id') || '';
                const source = sourceId ? document.getElementById(sourceId) : null;
                const text = source ? source.value : '';
                const ok = await copyText(text);
                if (!ok) return;
                const old = this.innerHTML;
                this.innerHTML = '<i class="ri-check-line me-1"></i>Copied';
                setTimeout(() => { this.innerHTML = old; }, 1200);
            });
        });

        if (copyAllBtn && copyAllText) {
            copyAllBtn.addEventListener('click', async function () {
                const ok = await copyText(copyAllText.value);
                if (!ok) return;
                const old = this.innerHTML;
                this.innerHTML = '<i class="ri-check-line me-1"></i>Copied';
                setTimeout(() => { this.innerHTML = old; }, 1200);
            });
        }

        if (creatorForm) {
            creatorForm.addEventListener('submit', async function (event) {
                if (modalReadyToSubmit) return;
                event.preventDefault();

                if (!creatorForm.checkValidity()) {
                    creatorForm.reportValidity();
                    return;
                }

                const selectedDates = normalizeDateList(dateInput ? dateInput.value : '');
                if (selectedDates.length === 0) {
                    if (dateInput) dateInput.focus();
                    return;
                }

                if (!clarifyingModal) {
                    if (clarifyingQaInput) clarifyingQaInput.value = '';
                    modalReadyToSubmit = true;
                    creatorForm.submit();
                    return;
                }

                setQaWarning('');
                renderClarifyingQuestions([]);
                setQaLoading(true);
                clarifyingModal.show();

                const requestId = ++qaRequestSeq;
                let questions = [];
                let warningMessage = '';

                try {
                    const resp = await requestClarifyingQuestions();
                    questions = resp.questions;
                    if (!resp.ok && resp.message) warningMessage = resp.message;
                } catch (err) {
                    warningMessage = err && err.message
                        ? String(err.message)
                        : 'Unable to load AI clarifying questions.';
                }

                if (requestId !== qaRequestSeq) return;

                if (!Array.isArray(questions) || questions.length === 0) {
                    questions = defaultClarifyingQuestions;
                    if (!warningMessage) {
                        warningMessage = 'AI clarifying questions are unavailable right now. Default questions are shown.';
                    }
                }

                const count = renderClarifyingQuestions(questions);
                setQaLoading(false);
                setQaWarning(warningMessage);
                if (qaContinueBtn) qaContinueBtn.disabled = (count === 0);
            });
        }

        if (qaContinueBtn && creatorForm) {
            qaContinueBtn.addEventListener('click', function () {
                const qas = [];
                if (qaListEl) {
                    const fields = qaListEl.querySelectorAll('textarea[data-question]');
                    fields.forEach((el) => {
                        const question = String(el.getAttribute('data-question') || '').trim();
                        const answer = String(el.value || '').trim();
                        if (!question || !answer) return;
                        qas.push({ question: question, answer: answer });
                    });
                }

                if (qas.length === 0) {
                    setQaWarning('Answer at least one question before generating.');
                    return;
                }

                if (clarifyingQaInput) {
                    clarifyingQaInput.value = JSON.stringify(qas.slice(0, 5));
                }
                if (clarifyingModal) clarifyingModal.hide();
                setQaLoading(true);
                modalReadyToSubmit = true;
                creatorForm.submit();
            });
        }

        if (clarifyingModalEl) {
            clarifyingModalEl.addEventListener('hidden.bs.modal', function () {
                setQaLoading(false);
            });
        }

        if (checkAllEl && rowChecks.length > 0) {
            const syncHeaderCheck = () => {
                const allChecked = Array.from(rowChecks).every((el) => el.checked);
                checkAllEl.checked = allChecked;
            };

            checkAllEl.addEventListener('change', function () {
                rowChecks.forEach((el) => { el.checked = checkAllEl.checked; });
            });
            rowChecks.forEach((el) => {
                el.addEventListener('change', syncHeaderCheck);
            });
            syncHeaderCheck();
        }

        if (expiryEl && expiryTextEl) {
            let remaining = Number(expiryEl.getAttribute('data-remaining') || '0');
            if (!Number.isFinite(remaining) || remaining < 0) remaining = 0;

            const formatTime = (seconds) => {
                const total = Math.max(0, Math.floor(seconds));
                const mins = Math.floor(total / 60);
                const secs = total % 60;
                return String(mins).padStart(2, '0') + ':' + String(secs).padStart(2, '0');
            };

            const renderRemaining = () => {
                expiryTextEl.textContent = formatTime(remaining);
                if (remaining <= 0) {
                    expiryEl.classList.add('text-danger');
                    return false;
                }
                return true;
            };

            if (renderRemaining()) {
                const t = setInterval(() => {
                    remaining -= 1;
                    if (!renderRemaining()) {
                        clearInterval(t);
                        setTimeout(() => window.location.reload(), 900);
                    }
                }, 1000);
            }
        }
    })();
</script>
<script src="assets/js/app.min.js"></script>
</body>
</html>
