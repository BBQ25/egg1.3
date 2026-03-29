<?php include __DIR__ . '/../../../layouts/session.php'; ?>
<?php require_active_role('teacher'); ?>

<?php
require_once __DIR__ . '/accomplishments.php';
require_once __DIR__ . '/../../../includes/ai_credits.php';
require_once __DIR__ . '/../../../includes/audit.php';

ensure_accomplishment_tables($conn);
ai_credit_ensure_system($conn);
ensure_audit_logs_table($conn);

$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
if ($userId <= 0) deny_access(401, 'Unauthorized.');

$u = current_user_row($conn);
$displayName = $u ? current_user_display_name($u) : 'Account';

function h($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
if (!function_exists('acc_norm_label')) {
    function acc_norm_label($v) {
        $s = preg_replace('/\s+/', ' ', trim((string) $v));
        return strtolower(trim((string) $s));
    }
}
if (!function_exists('acc_build_subject_query_suffix')) {
    function acc_build_subject_query_suffix(array $subjects) {
        $subjects = acc_collect_subject_labels($subjects);
        if (count($subjects) === 0) return '';
        return '&' . http_build_query(['subject' => array_values($subjects)]);
    }
}
if (!function_exists('acc_text_change_ratio')) {
    /**
     * Returns approximate change ratio from 0.0 to 1.0 between two text blobs.
     */
    function acc_text_change_ratio($before, $after) {
        $before = preg_replace('/\s+/', ' ', trim((string) $before));
        $after = preg_replace('/\s+/', ' ', trim((string) $after));
        if ($before === $after) return 0.0;

        $beforeLen = strlen($before);
        $afterLen = strlen($after);
        if ($beforeLen === 0 && $afterLen === 0) return 0.0;
        if ($beforeLen === 0 && $afterLen > 0) return 1.0;

        // Prefer Levenshtein for short/medium strings. Fall back to similar_text.
        $maxLen = max($beforeLen, $afterLen);
        if (function_exists('levenshtein') && $maxLen <= 1800) {
            $dist = levenshtein($before, $after);
            if (is_int($dist) && $dist >= 0) {
                return max(0.0, min(1.0, $dist / max(1, $beforeLen)));
            }
        }

        $pct = 0.0;
        similar_text($before, $after, $pct);
        $ratio = (100.0 - (float) $pct) / 100.0;
        return max(0.0, min(1.0, $ratio));
    }
}
if (!function_exists('acc_allowed_entry_types')) {
    function acc_allowed_entry_types() {
        return ['Lecture', 'Laboratory', 'Lecture & Laboratory'];
    }
}
if (!function_exists('acc_normalize_entry_type')) {
    function acc_normalize_entry_type($value) {
        $raw = strtolower(trim((string) $value));
        $allowed = acc_allowed_entry_types();
        foreach ($allowed as $type) {
            if (strtolower($type) === $raw) return $type;
        }

        $hasLecture = strpos($raw, 'lecture') !== false;
        $hasLab = (
            strpos($raw, 'laboratory') !== false ||
            strpos($raw, 'lab ') !== false ||
            preg_match('/\blab\b/', $raw)
        );
        if (strpos($raw, 'both') !== false || ($hasLecture && $hasLab)) return 'Lecture & Laboratory';
        if ($hasLab) return 'Laboratory';
        if ($hasLecture) return 'Lecture';
        return '';
    }
}
$accomplishmentTypes = acc_allowed_entry_types();

// Month selection.
$month = date('Y-m');
$requestedMonth = isset($_GET['month']) ? trim((string) $_GET['month']) : '';
$requestedYear = isset($_GET['year']) ? (int) $_GET['year'] : 0;
$requestedMonthNum = isset($_GET['month_num']) ? (int) $_GET['month_num'] : 0;
if ($requestedYear >= 1900 && $requestedYear <= 2100 && $requestedMonthNum >= 1 && $requestedMonthNum <= 12) {
    $month = sprintf('%04d-%02d', $requestedYear, $requestedMonthNum);
} elseif (preg_match('/^\d{4}-\d{2}$/', $requestedMonth)) {
    $month = $requestedMonth;
}
[$firstDay, $lastDay, $month] = acc_month_bounds($month);
$selectedYear = (int) substr($month, 0, 4);
$selectedMonthNum = (int) substr($month, 5, 2);
$monthNameOptions = [
    1 => 'January',
    2 => 'February',
    3 => 'March',
    4 => 'April',
    5 => 'May',
    6 => 'June',
    7 => 'July',
    8 => 'August',
    9 => 'September',
    10 => 'October',
    11 => 'November',
    12 => 'December',
];
$currentYear = (int) date('Y');
$yearStart = min($selectedYear, $currentYear) - 5;
$yearEnd = max($selectedYear, $currentYear) + 2;
$subjectOptions = acc_user_subject_options($conn, $userId);
if (count($subjectOptions) === 0) {
    $subjectOptions[] = 'Monthly Accomplishment';
}
$subjectOptionMap = [];
foreach ($subjectOptions as $opt) {
    $subjectOptionMap[acc_norm_label($opt)] = (string) $opt;
}

$requestedSubjects = acc_collect_subject_labels($_GET['subject'] ?? []);
$selectedSubjects = [];
foreach ($requestedSubjects as $requestedSubject) {
    $requestedNorm = acc_norm_label($requestedSubject);
    if (isset($subjectOptionMap[$requestedNorm])) {
        $selectedSubjects[] = (string) $subjectOptionMap[$requestedNorm];
    }
}
$selectedSubjects = acc_collect_subject_labels($selectedSubjects);
if (count($selectedSubjects) === 0) {
    $selectedSubjects[] = (string) $subjectOptions[0];
}

$selectedSubjectKeys = [];
foreach ($selectedSubjects as $selectedSubject) {
    $selectedSubjectKeys[acc_norm_label($selectedSubject)] = true;
}

$subject = (string) $selectedSubjects[0];
$subjectSummary = implode(', ', $selectedSubjects);
$subjectQuerySuffix = acc_build_subject_query_suffix($selectedSubjects);
$returnUrl = 'monthly-accomplishment.php?month=' . urlencode($month) . $subjectQuerySuffix;
$printBaseUrl = 'monthly-accomplishment-print.php?month=' . urlencode($month) . $subjectQuerySuffix;
$rephraseProviders = [
    'openai' => [
        'label' => acc_ai_provider_label('openai'),
        'configured' => acc_ai_provider_has_key('openai'),
    ],
    'gemini' => [
        'label' => acc_ai_provider_label('gemini'),
        'configured' => acc_ai_provider_has_key('gemini'),
    ],
];

$flash = isset($_SESSION['flash_message']) ? (string) $_SESSION['flash_message'] : '';
$flashType = isset($_SESSION['flash_type']) ? (string) $_SESSION['flash_type'] : 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    if (!csrf_validate($csrf)) {
        $_SESSION['flash_message'] = 'Security check failed (CSRF). Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . $returnUrl);
        exit;
    }

    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

    if ($action === 'create_entry') {
        $entryDate = isset($_POST['entry_date']) ? (string) $_POST['entry_date'] : '';
        $type = isset($_POST['type']) ? (string) $_POST['type'] : (isset($_POST['title']) ? (string) $_POST['title'] : '');
        $description = isset($_POST['description']) ? (string) $_POST['description'] : (isset($_POST['details']) ? (string) $_POST['details'] : '');
        $remarks = isset($_POST['remarks']) ? (string) $_POST['remarks'] : '';

        // Enforce entry date inside selected month.
        if ($entryDate < $firstDay || $entryDate > $lastDay) {
            $_SESSION['flash_message'] = 'Entry date must be within the selected month.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: ' . $returnUrl);
            exit;
        }

        $entrySubject = isset($_POST['subject']) ? trim((string) $_POST['subject']) : $subject;
        if ($entrySubject === '') $entrySubject = $subject;
        $entrySubjectNorm = acc_norm_label($entrySubject);
        $isAllowedSubject = false;
        foreach ($subjectOptions as $opt) {
            if (acc_norm_label($opt) === $entrySubjectNorm) {
                $isAllowedSubject = true;
                break;
            }
        }
        if (!$isAllowedSubject) {
            $_SESSION['flash_message'] = 'Invalid subject selection. Please choose from assigned subjects.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: ' . $returnUrl);
            exit;
        }
        $title = acc_normalize_entry_type($type);
        if ($title === '') {
            $_SESSION['flash_message'] = 'Invalid type. Please select Lecture, Laboratory, or Lecture & Laboratory.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: ' . $returnUrl);
            exit;
        }
        $details = trim((string) $description);
        if ($details === '') {
            $_SESSION['flash_message'] = 'Description is required.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: ' . $returnUrl);
            exit;
        }
        [$ok, $res] = acc_create_entry($conn, $userId, $entryDate, $title, $details, $entrySubject, $remarks);
        if (!$ok) {
            $_SESSION['flash_message'] = (string) $res;
            $_SESSION['flash_type'] = 'danger';
            header('Location: ' . $returnUrl);
            exit;
        }

        $entryId = (int) $res;
        $saved = 0;
        $errs = [];
        if (isset($_FILES['proofs']) && is_array($_FILES['proofs'])) {
            [$ok2, $res2] = acc_add_proofs($conn, $entryId, $userId, $_FILES['proofs']);
            if ($ok2 && is_array($res2)) {
                $saved = (int) ($res2['saved'] ?? 0);
                $errs = is_array($res2['errors'] ?? null) ? $res2['errors'] : [];
            }
        }

        audit_log($conn, 'accomplishment.entry.created', 'accomplishment_entry', $entryId, null, [
            'month' => $month,
            'proofs_saved' => $saved,
        ]);

        $msg = 'Entry created.';
        if ($saved > 0) $msg .= ' Proofs uploaded: ' . $saved . '.';
        if (!empty($errs)) $msg .= ' Some files were skipped.';
        $_SESSION['flash_message'] = $msg;
        $_SESSION['flash_type'] = !empty($errs) ? 'warning' : 'success';
        header('Location: ' . $returnUrl);
        exit;
    }

    if ($action === 'add_proofs') {
        $entryId = isset($_POST['entry_id']) ? (int) $_POST['entry_id'] : 0;
        if ($entryId <= 0) {
            $_SESSION['flash_message'] = 'Invalid entry.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: ' . $returnUrl);
            exit;
        }

        if (!isset($_FILES['proofs']) || !is_array($_FILES['proofs'])) {
            $_SESSION['flash_message'] = 'No files received.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: ' . $returnUrl);
            exit;
        }

        [$ok, $res] = acc_add_proofs($conn, $entryId, $userId, $_FILES['proofs']);
        if (!$ok) {
            $_SESSION['flash_message'] = (string) $res;
            $_SESSION['flash_type'] = 'danger';
            header('Location: ' . $returnUrl);
            exit;
        }

        $saved = (int) ($res['saved'] ?? 0);
        $errs = is_array($res['errors'] ?? null) ? $res['errors'] : [];
        audit_log($conn, 'accomplishment.proof.added', 'accomplishment_entry', $entryId, null, [
            'month' => $month,
            'proofs_saved' => $saved,
        ]);

        $msg = ($saved > 0) ? ('Proofs uploaded: ' . $saved . '.') : 'No proofs uploaded.';
        if (!empty($errs)) $msg .= ' Some files were skipped.';
        $_SESSION['flash_message'] = $msg;
        $_SESSION['flash_type'] = !empty($errs) ? 'warning' : (($saved > 0) ? 'success' : 'warning');
        header('Location: ' . $returnUrl);
        exit;
    }

    if ($action === 'update_entry') {
        $entryId = isset($_POST['entry_id']) ? (int) $_POST['entry_id'] : 0;
        $entryDate = isset($_POST['entry_date']) ? trim((string) $_POST['entry_date']) : '';
        $type = isset($_POST['type']) ? (string) $_POST['type'] : (isset($_POST['title']) ? (string) $_POST['title'] : '');
        $description = isset($_POST['description']) ? (string) $_POST['description'] : (isset($_POST['details']) ? (string) $_POST['details'] : '');
        $remarks = isset($_POST['remarks']) ? (string) $_POST['remarks'] : '';

        if ($entryId <= 0) {
            $_SESSION['flash_message'] = 'Invalid entry.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: ' . $returnUrl);
            exit;
        }

        if ($entryDate < $firstDay || $entryDate > $lastDay) {
            $_SESSION['flash_message'] = 'Entry date must be within the selected month.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: ' . $returnUrl);
            exit;
        }
        $title = acc_normalize_entry_type($type);
        if ($title === '') {
            $_SESSION['flash_message'] = 'Invalid type. Please select Lecture, Laboratory, or Lecture & Laboratory.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: ' . $returnUrl);
            exit;
        }
        $details = trim((string) $description);
        if ($details === '') {
            $_SESSION['flash_message'] = 'Description is required.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: ' . $returnUrl);
            exit;
        }

        [$ok, $res] = acc_update_entry($conn, $entryId, $userId, $entryDate, $title, $details, $remarks);
        if (!$ok) {
            $_SESSION['flash_message'] = (string) $res;
            $_SESSION['flash_type'] = 'danger';
            header('Location: ' . $returnUrl);
            exit;
        }

        audit_log($conn, 'accomplishment.entry.updated', 'accomplishment_entry', $entryId, null, [
            'month' => $month,
        ]);

        $_SESSION['flash_message'] = 'Entry updated.';
        $_SESSION['flash_type'] = 'success';
        header('Location: ' . $returnUrl);
        exit;
    }

    if ($action === 'reassign_entry_subject') {
        $entryId = isset($_POST['entry_id']) ? (int) $_POST['entry_id'] : 0;
        $newSubject = trim((string) ($_POST['subject'] ?? ''));
        if ($entryId <= 0) {
            $_SESSION['flash_message'] = 'Invalid entry.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: ' . $returnUrl);
            exit;
        }
        if ($newSubject === '') {
            $_SESSION['flash_message'] = 'Select a valid subject.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: ' . $returnUrl);
            exit;
        }

        $newSubjectNorm = acc_norm_label($newSubject);
        $matchedSubject = '';
        foreach ($subjectOptions as $opt) {
            if (acc_norm_label($opt) === $newSubjectNorm) {
                $matchedSubject = (string) $opt;
                break;
            }
        }
        if ($matchedSubject === '') {
            $_SESSION['flash_message'] = 'Invalid subject selection. Please choose from assigned subjects.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: ' . $returnUrl);
            exit;
        }

        [$okRead, $entryOrMsg] = acc_get_entry_for_user($conn, $entryId, $userId);
        if (!$okRead) {
            $_SESSION['flash_message'] = (string) $entryOrMsg;
            $_SESSION['flash_type'] = 'danger';
            header('Location: ' . $returnUrl);
            exit;
        }

        $entry = is_array($entryOrMsg) ? $entryOrMsg : [];
        $oldSubject = trim((string) ($entry['subject_label'] ?? ''));
        if ($oldSubject === '') $oldSubject = 'Monthly Accomplishment';

        if (acc_norm_label($oldSubject) === acc_norm_label($matchedSubject)) {
            $_SESSION['flash_message'] = 'This entry is already under the selected subject.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: ' . $returnUrl);
            exit;
        }

        [$okAssign, $assignMsg] = acc_update_entry_subject($conn, $entryId, $userId, $matchedSubject);
        if (!$okAssign) {
            $_SESSION['flash_message'] = is_string($assignMsg) ? $assignMsg : 'Unable to reassign subject.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: ' . $returnUrl);
            exit;
        }

        audit_log($conn, 'accomplishment.entry.reassigned_subject', 'accomplishment_entry', $entryId, null, [
            'month' => $month,
            'from_subject' => $oldSubject,
            'to_subject' => $matchedSubject,
        ]);

        $msg = 'Entry reassigned to ' . $matchedSubject . '.';
        if (!isset($selectedSubjectKeys[acc_norm_label($matchedSubject)])) {
            $msg .= ' It may be hidden by the current subject filter.';
        }
        $_SESSION['flash_message'] = $msg;
        $_SESSION['flash_type'] = 'success';
        header('Location: ' . $returnUrl);
        exit;
    }

    if ($action === 'rephrase_entry_ai') {
        $entryId = isset($_POST['entry_id']) ? (int) $_POST['entry_id'] : 0;
        $aiProvider = acc_ai_normalize_provider($_POST['ai_provider'] ?? 'openai');
        $aiProviderLabel = acc_ai_provider_label($aiProvider);
        if ($entryId <= 0) {
            $_SESSION['flash_message'] = 'Invalid entry.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: ' . $returnUrl);
            exit;
        }
        if (!acc_ai_provider_has_key($aiProvider)) {
            $_SESSION['flash_message'] = $aiProviderLabel . ' API key is not configured.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: ' . $returnUrl);
            exit;
        }

        [$found, $entryOrMsg] = acc_get_entry_for_user($conn, $entryId, $userId);
        if (!$found) {
            $_SESSION['flash_message'] = (string) $entryOrMsg;
            $_SESSION['flash_type'] = 'danger';
            header('Location: ' . $returnUrl);
            exit;
        }

        $entry = is_array($entryOrMsg) ? $entryOrMsg : [];
        $oldTitle = trim((string) ($entry['title'] ?? ''));
        $oldDetails = trim((string) ($entry['details'] ?? ''));
        $oldRemarks = trim((string) ($entry['remarks'] ?? ''));
        $entrySubject = trim((string) ($entry['subject_label'] ?? ''));
        if ($entrySubject === '') $entrySubject = $subject;
        if ($oldDetails === '' && $oldRemarks === '') {
            $_SESSION['flash_message'] = 'Nothing to re-phrase for this entry.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: ' . $returnUrl);
            exit;
        }

        [$okConsume, $consumeMsg] = ai_credit_try_consume($conn, $userId);
        if (!$okConsume) {
            $_SESSION['flash_message'] = (string) $consumeMsg;
            $_SESSION['flash_type'] = 'warning';
            header('Location: ' . $returnUrl);
            exit;
        }

        $entryProofs = [];
        [$okProofs, $proofsOrMsg] = acc_list_entry_proofs_for_user($conn, $entryId, $userId);
        if ($okProofs && is_array($proofsOrMsg)) {
            $entryProofs = $proofsOrMsg;
        }

        [$okAi, $aiResult] = acc_rephrase_entry_with_ai($entrySubject, $oldDetails, $oldRemarks, $entryProofs, $aiProvider);
        if (!$okAi) {
            ai_credit_refund($conn, $userId, 1);
            $_SESSION['flash_message'] = (string) $aiResult;
            $_SESSION['flash_type'] = 'warning';
            header('Location: ' . $returnUrl);
            exit;
        }

        $newDetails = acc_normalize_text_value($aiResult['details'] ?? $oldDetails, $oldDetails);
        $newRemarks = acc_normalize_text_value($aiResult['remarks'] ?? $oldRemarks, $oldRemarks);
        $oldDetailsNormalized = acc_normalize_text_value($oldDetails);
        $oldRemarksNormalized = acc_normalize_text_value($oldRemarks);
        if ($newDetails === '' && $oldDetailsNormalized !== '') $newDetails = $oldDetailsNormalized;
        if ($newRemarks === '' && $oldRemarksNormalized !== '') $newRemarks = $oldRemarksNormalized;

        [$okUpdate, $updateRes] = acc_update_entry_text($conn, $entryId, $userId, $oldTitle, $newDetails, $newRemarks);
        if (!$okUpdate) {
            ai_credit_refund($conn, $userId, 1);
            $_SESSION['flash_message'] = is_string($updateRes) ? $updateRes : 'Unable to update entry.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: ' . $returnUrl);
            exit;
        }

        $changed = ($newDetails !== $oldDetails) || ($newRemarks !== $oldRemarks);
        $oldCombined = trim($oldDetails . "\n" . $oldRemarks);
        $newCombined = trim($newDetails . "\n" . $newRemarks);
        $changeRatio = acc_text_change_ratio($oldCombined, $newCombined);
        $changePct = round($changeRatio * 100, 1);
        $noChargeThreshold = 0.15;
        $creditCharged = $changeRatio > $noChargeThreshold;
        if (!$creditCharged) {
            ai_credit_refund($conn, $userId, 1);
        }
        [$okCredit, $creditStateOrMsg] = ai_credit_get_user_status($conn, $userId);
        $remainingAfter = ($okCredit && is_array($creditStateOrMsg))
            ? (float) ($creditStateOrMsg['remaining'] ?? 0)
            : 0;
        $remainingAfterText = number_format((float) $remainingAfter, 2, '.', '');
        audit_log($conn, 'accomplishment.entry.rephrased_ai', 'accomplishment_entry', $entryId, null, [
            'month' => $month,
            'subject' => $entrySubject,
            'ai_provider' => $aiProvider,
            'proofs_considered' => count($entryProofs),
            'changed' => $changed ? 1 : 0,
            'change_pct' => $changePct,
            'credit_charged' => $creditCharged ? 1 : 0,
            'ai_credit_remaining' => $remainingAfter,
        ]);

        if ($changed) {
            if ($creditCharged) {
                $_SESSION['flash_message'] = 'Description and remarks re-phrased with ' . $aiProviderLabel . ' AI. Change: ' . $changePct . '%. 1 credit charged. Remaining AI credits: ' . $remainingAfterText . '.';
            } else {
                $_SESSION['flash_message'] = 'Description and remarks re-phrased with ' . $aiProviderLabel . ' AI. Change: ' . $changePct . '% (<= 15%), no credit charged. Remaining AI credits: ' . $remainingAfterText . '.';
            }
        } else {
            $_SESSION['flash_message'] = $aiProviderLabel . ' AI reviewed the entry; no text changes were needed. No credit charged. Remaining AI credits: ' . $remainingAfterText . '.';
        }
        $_SESSION['flash_type'] = 'success';
        header('Location: ' . $returnUrl);
        exit;
    }

    if ($action === 'delete_proof') {
        $proofId = isset($_POST['proof_id']) ? (int) $_POST['proof_id'] : 0;
        [$ok, $msg] = acc_delete_proof($conn, $proofId, $userId);
        if ($ok) {
            audit_log($conn, 'accomplishment.proof.deleted', 'accomplishment_proof', $proofId, null, ['month' => $month]);
            $_SESSION['flash_message'] = (string) $msg;
            $_SESSION['flash_type'] = 'success';
        } else {
            $_SESSION['flash_message'] = (string) $msg;
            $_SESSION['flash_type'] = 'danger';
        }
        header('Location: ' . $returnUrl);
        exit;
    }

    if ($action === 'delete_entry') {
        $entryId = isset($_POST['entry_id']) ? (int) $_POST['entry_id'] : 0;
        [$ok, $msg] = acc_delete_entry($conn, $entryId, $userId);
        if ($ok) {
            audit_log($conn, 'accomplishment.entry.deleted', 'accomplishment_entry', $entryId, null, ['month' => $month]);
            $_SESSION['flash_message'] = (string) $msg;
            $_SESSION['flash_type'] = 'success';
        } else {
            $_SESSION['flash_message'] = (string) $msg;
            $_SESSION['flash_type'] = 'danger';
        }
        header('Location: ' . $returnUrl);
        exit;
    }

    $_SESSION['flash_message'] = 'Invalid request.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $returnUrl);
    exit;
}

$entries = acc_list_month($conn, $userId, $month, $selectedSubjects);
[$okCredit, $creditStateOrMsg] = ai_credit_get_user_status($conn, $userId);
$aiCredit = ['limit' => 0.0, 'used' => 0.0, 'remaining' => 0.0];
if ($okCredit && is_array($creditStateOrMsg)) {
    $aiCredit = [
        'limit' => (float) ($creditStateOrMsg['limit'] ?? 0),
        'used' => (float) ($creditStateOrMsg['used'] ?? 0),
        'remaining' => (float) ($creditStateOrMsg['remaining'] ?? 0),
    ];
}
$canDownloadReports = ((float) ($aiCredit['remaining'] ?? 0)) >= 2.0;
$canRephraseWithOpenAi = ((float) ($aiCredit['remaining'] ?? 0)) > 0.0 && !empty($rephraseProviders['openai']['configured']);
$canRephraseWithGemini = ((float) ($aiCredit['remaining'] ?? 0)) > 0.0 && !empty($rephraseProviders['gemini']['configured']);
$today = date('Y-m-d');
$defaultEntryDate = ($today >= $firstDay && $today <= $lastDay) ? $today : $firstDay;
$afterLastDay = date('Y-m-d', strtotime($lastDay . ' +1 day'));

$calendarDayCounts = [];
$entryEditMap = [];
foreach ($entries as $e) {
    $eid = (int) ($e['id'] ?? 0);
    $ed = (string) ($e['entry_date'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ed)) {
        if (!isset($calendarDayCounts[$ed])) {
            $calendarDayCounts[$ed] = 0;
        }
        $calendarDayCounts[$ed]++;
    }

    if ($eid > 0) {
        $entryEditMap[(string) $eid] = [
            'id' => $eid,
            'subject_label' => (string) ($e['subject_label'] ?? ''),
            'entry_date' => $ed,
            'title' => (string) ($e['title'] ?? ''),
            'details' => (string) ($e['details'] ?? ''),
            'remarks' => (string) ($e['remarks'] ?? ''),
        ];
    }
}
$calendarDayCountsJson = json_encode(
    $calendarDayCounts,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
if (!is_string($calendarDayCountsJson) || $calendarDayCountsJson === '') $calendarDayCountsJson = '{}';

$entryEditMapJson = json_encode(
    $entryEditMap,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
if (!is_string($entryEditMapJson) || $entryEditMapJson === '') $entryEditMapJson = '{}';
?>

<?php include __DIR__ . '/../../../layouts/main.php'; ?>

<head>
    <title>Monthly Accomplishment | E-Record</title>
    <?php include __DIR__ . '/../../../layouts/title-meta.php'; ?>
    <link href="assets/vendor/fullcalendar/main.min.css" rel="stylesheet" type="text/css" />
    <?php include __DIR__ . '/../../../layouts/head-css.php'; ?>
    <link rel="stylesheet" href="assets/css/admin-ops-ui.css">
    <style>
        .accomp-hero {
            background: linear-gradient(140deg, #18233d 0%, #214981 54%, #0f766e 100%);
        }
        .accomp-hero::after {
            content: "";
            position: absolute;
            inset: 0;
            background: repeating-linear-gradient(
                140deg,
                rgba(255, 255, 255, 0.06) 0px,
                rgba(255, 255, 255, 0.06) 1px,
                rgba(255, 255, 255, 0) 10px,
                rgba(255, 255, 255, 0) 20px
            );
            opacity: 0.35;
            pointer-events: none;
        }
        .accomp-credit-banner {
            border-radius: 12px;
            border: 1px solid rgba(14, 116, 144, 0.20);
            background: linear-gradient(135deg, rgba(14, 116, 144, 0.12), rgba(2, 132, 199, 0.06));
            color: #0f172a;
        }
        .accomp-credit-kpi {
            font-size: 1rem;
            font-weight: 700;
            line-height: 1.2;
        }
        .accomp-credit-note {
            font-size: .82rem;
            color: #334155;
        }
        .accomp-provider-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 9px;
            border-radius: 999px;
            border: 1px solid rgba(15, 23, 42, 0.14);
            background: rgba(255, 255, 255, 0.78);
            font-size: .75rem;
            font-weight: 600;
            margin-right: 6px;
            margin-bottom: 6px;
        }
        .accomp-provider-pill i {
            font-size: .8rem;
        }
        .accomp-provider-pill.is-ready {
            border-color: rgba(5, 150, 105, 0.35);
            color: #065f46;
        }
        .accomp-provider-pill.is-missing {
            border-color: rgba(220, 38, 38, 0.25);
            color: #991b1b;
        }
        .proof-thumb {
            width: 92px;
            height: 72px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid rgba(0,0,0,0.10);
            background: #f8f9fb;
        }
        .proof-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: flex-start;
        }
        .proof-item {
            width: 92px;
        }
        .proof-actions {
            margin-top: 6px;
            display: flex;
            gap: 6px;
            justify-content: center;
        }
        #accomplishment-calendar {
            border: 1px solid rgba(15, 23, 42, 0.10);
            border-radius: 14px;
            padding: 10px;
            background: linear-gradient(145deg, #f8fbff 0%, #f4f8ff 48%, #f8f7ff 100%);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.75);
        }
        #accomplishment-calendar .fc {
            --fc-border-color: rgba(15, 23, 42, 0.08);
            --fc-page-bg-color: transparent;
            --fc-neutral-bg-color: rgba(255, 255, 255, 0.6);
        }
        #accomplishment-calendar .fc .fc-toolbar.fc-header-toolbar {
            margin: 0 0 10px;
            padding: 8px 10px;
            border-radius: 10px;
            background: linear-gradient(90deg, #1f3558 0%, #29466f 100%);
            border: 1px solid rgba(15, 23, 42, 0.25);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.08);
        }
        #accomplishment-calendar .fc .fc-toolbar-title {
            font-size: 1rem;
            font-weight: 700;
            letter-spacing: 0.01em;
            color: #f8fafc;
        }
        #accomplishment-calendar .fc .fc-col-header-cell {
            background: linear-gradient(180deg, #f7f1dc 0%, #f2e9cb 100%);
        }
        #accomplishment-calendar .fc .fc-col-header-cell-cushion {
            color: #6b5a2f;
            font-size: .68rem;
            letter-spacing: .08em;
            text-transform: uppercase;
            font-weight: 700;
            padding: 8px 0;
        }
        #accomplishment-calendar .fc .fc-daygrid-day {
            transition: background-color .15s ease;
            cursor: pointer;
        }
        #accomplishment-calendar .fc .fc-daygrid-day:hover {
            background: rgba(255, 255, 255, 0.45);
        }
        #accomplishment-calendar .fc .fc-scrollgrid-sync-table tbody tr:nth-child(1) .fc-daygrid-day { background-color: rgba(245, 158, 11, 0.15); }
        #accomplishment-calendar .fc .fc-scrollgrid-sync-table tbody tr:nth-child(2) .fc-daygrid-day { background-color: rgba(249, 115, 22, 0.14); }
        #accomplishment-calendar .fc .fc-scrollgrid-sync-table tbody tr:nth-child(3) .fc-daygrid-day { background-color: rgba(239, 68, 68, 0.13); }
        #accomplishment-calendar .fc .fc-scrollgrid-sync-table tbody tr:nth-child(4) .fc-daygrid-day { background-color: rgba(217, 70, 239, 0.13); }
        #accomplishment-calendar .fc .fc-scrollgrid-sync-table tbody tr:nth-child(5) .fc-daygrid-day { background-color: rgba(59, 130, 246, 0.13); }
        #accomplishment-calendar .fc .fc-scrollgrid-sync-table tbody tr:nth-child(6) .fc-daygrid-day { background-color: rgba(16, 185, 129, 0.14); }
        #accomplishment-calendar .fc .fc-daygrid-day.fc-day-other {
            opacity: 0.55;
            filter: saturate(.7);
        }
        #accomplishment-calendar .fc .fc-daygrid-day-frame {
            min-height: 58px;
        }
        #accomplishment-calendar .fc .fc-daygrid-day-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 4px;
            padding-top: 2px;
        }
        #accomplishment-calendar .fc .fc-daygrid-day-number {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            margin: 4px;
            border-radius: 999px;
            color: #0f172a;
            text-decoration: none;
            font-size: .84rem;
            font-weight: 600;
            transition: background-color .15s ease, color .15s ease, box-shadow .15s ease;
        }
        #accomplishment-calendar .fc .fc-daygrid-day:hover .fc-daygrid-day-number {
            background: rgba(15, 23, 42, 0.10);
        }
        #accomplishment-calendar .fc .fc-daygrid-day.fc-day-today {
            background: rgba(37, 99, 235, 0.14) !important;
        }
        #accomplishment-calendar .fc .fc-daygrid-day.fc-day-today .fc-daygrid-day-number {
            background: #1d4ed8;
            color: #ffffff;
            box-shadow: 0 6px 14px rgba(29, 78, 216, 0.30);
        }
        #accomplishment-calendar .fc .fc-daygrid-day-events {
            margin-top: 0;
            min-height: 0;
        }
        .accomp-day-count {
            font-size: 10px;
            line-height: 1;
            padding: 3px 6px;
            margin: 4px 4px 0 0;
            background: rgba(255, 255, 255, 0.72);
            color: #334155;
            border: 1px solid rgba(15, 23, 42, 0.16);
            border-radius: 999px;
            font-weight: 700;
            letter-spacing: 0.01em;
        }
        .icon-btn {
            width: 32px;
            height: 32px;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 9px;
            transition: transform .15s ease, box-shadow .15s ease, background-color .15s ease, border-color .15s ease, color .15s ease;
        }
        .icon-btn i {
            font-size: 1rem;
            line-height: 1;
        }
        .icon-btn.kebab-toggle {
            width: 32px;
            min-width: 32px;
            padding: 0;
        }
        .icon-btn.kebab-toggle::after {
            display: none;
        }
        .icon-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 14px rgba(15, 23, 42, 0.14);
        }
        .icon-btn.btn-outline-secondary:hover {
            background: rgba(100, 116, 139, 0.10);
            color: #334155;
            border-color: rgba(100, 116, 139, 0.35);
        }
        .icon-btn.btn-outline-primary:hover {
            background: rgba(37, 99, 235, 0.10);
            color: #1d4ed8;
            border-color: rgba(29, 78, 216, 0.35);
        }
        .icon-btn.btn-outline-danger:hover {
            background: rgba(220, 38, 38, 0.10);
            color: #b91c1c;
            border-color: rgba(185, 28, 28, 0.35);
        }
        .icon-btn.btn-success:hover {
            background: #0f9964;
            border-color: #0f9964;
        }
        .icon-btn.disabled,
        .icon-btn:disabled {
            transform: none;
            box-shadow: none;
            pointer-events: none;
            opacity: 0.55;
        }
        .icon-menu-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .icon-menu-item i {
            font-size: 1rem;
        }
        .entries-table th {
            white-space: nowrap;
            font-size: .76rem;
            letter-spacing: .03em;
            text-transform: uppercase;
        }
        .entries-table td {
            vertical-align: top;
        }
        .entry-date-cell {
            white-space: nowrap;
            min-width: 120px;
        }
        .entry-snippet {
            white-space: pre-wrap;
            line-height: 1.35;
            max-height: 6.8em;
            overflow: hidden;
        }
        .entry-actions {
            min-width: 56px;
            text-align: right;
        }
        .proof-detail-row td {
            background: #f8fafc;
        }
        .accomp-subject-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 8px;
        }
        .accomp-subject-actions {
            display: inline-flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .accomp-subject-actions .btn {
            border-radius: 999px;
            padding: 4px 12px;
        }
        .accomp-subject-search {
            position: relative;
            margin-bottom: 8px;
        }
        .accomp-subject-search i {
            position: absolute;
            top: 50%;
            left: 10px;
            transform: translateY(-50%);
            color: #64748b;
            pointer-events: none;
        }
        .accomp-subject-search-input {
            padding-left: 32px;
            border-radius: 10px;
        }
        .accomp-subject-checklist {
            max-height: 260px;
            overflow: auto;
            border: 1px solid rgba(15, 23, 42, 0.12);
            border-radius: 10px;
            padding: 10px;
            background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
        }
        .accomp-subject-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 9px 10px;
            border-radius: 10px;
            border: 1px solid transparent;
            margin: 0 0 6px;
            cursor: pointer;
            transition: background-color .12s ease, border-color .12s ease, box-shadow .12s ease;
        }
        .accomp-subject-item:last-child {
            margin-bottom: 0;
        }
        .accomp-subject-item:hover {
            background: rgba(37, 99, 235, 0.09);
            border-color: rgba(37, 99, 235, 0.20);
        }
        .accomp-subject-item.is-selected {
            background: rgba(37, 99, 235, 0.12);
            border-color: rgba(37, 99, 235, 0.26);
        }
        .accomp-subject-item .form-check-input {
            margin: 2px 0 0;
            float: none;
            flex-shrink: 0;
        }
        .accomp-subject-item .form-check-label {
            line-height: 1.35;
            overflow-wrap: anywhere;
        }
        .accomp-subject-empty {
            display: none;
            border: 1px dashed rgba(15, 23, 42, 0.14);
            border-radius: 10px;
            padding: 10px 12px;
            background: #ffffff;
        }
        .accomp-subject-empty.is-visible {
            display: block;
        }
        .accomp-selected-chip-row {
            margin-top: 8px;
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            min-height: 26px;
        }
        .accomp-selected-chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            border-radius: 999px;
            border: 1px solid rgba(37, 99, 235, 0.25);
            background: rgba(37, 99, 235, 0.09);
            color: #1d4ed8;
            font-size: .74rem;
            font-weight: 600;
            padding: 3px 9px;
            max-width: 100%;
        }
        .accomp-selected-chip-text {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            max-width: 200px;
        }
        .accomp-subject-summary {
            margin-top: 6px;
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        .accomp-subject-pill {
            font-weight: 500;
            border-radius: 999px;
            border: 1px solid rgba(15, 23, 42, 0.12);
            background: #f8fafc;
            color: #334155;
            max-width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            display: inline-block;
            padding: .35rem .62rem;
        }
        .accomp-empty-state {
            border: 1px dashed rgba(15, 23, 42, 0.16);
            border-radius: 12px;
            padding: 22px;
            background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
        }
        .accomp-period-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }
        .accomp-modal-note {
            font-size: 12px;
            color: #6b7280;
        }
        .accomp-modal-form {
            display: flex;
            flex-direction: column;
            min-height: 0;
            height: 100%;
        }
        .accomp-modal-form .modal-body {
            overflow-y: auto;
        }
        .accomp-modal-form .modal-footer {
            margin-top: auto;
        }
        @media (max-width: 575.98px) {
            .accomp-period-grid {
                grid-template-columns: 1fr;
            }
            .accomp-selected-chip-text {
                max-width: 150px;
            }
        }
        @media print {
            .no-print { display: none !important; }
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
                                <div class="page-title-right no-print">
                                    <div class="dropdown">
                                        <button type="button"
                                            class="btn btn-outline-secondary dropdown-toggle icon-btn kebab-toggle"
                                            data-bs-toggle="dropdown"
                                            aria-expanded="false"
                                            title="Report actions"
                                            aria-label="Report actions">
                                            <i class="ri-more-2-fill" aria-hidden="true"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li>
                                                <a class="dropdown-item icon-menu-item"
                                                   href="<?php echo h($printBaseUrl); ?>"
                                                   target="_blank" rel="noreferrer">
                                                    <i class="ri-printer-line text-muted" aria-hidden="true"></i>
                                                    Print / Preview
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item icon-menu-item" href="pages-profile.php?tab=settings">
                                                    <i class="ri-user-settings-line text-muted" aria-hidden="true"></i>
                                                    Edit Program Chair by Subject (Admin Approval)
                                                </a>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item icon-menu-item<?php echo $canDownloadReports ? '' : ' disabled'; ?>"
                                                   href="<?php echo $canDownloadReports ? h($printBaseUrl . '&download=docx') : '#'; ?>"
                                                   <?php echo $canDownloadReports ? 'target="_blank" rel="noreferrer"' : 'aria-disabled="true" onclick="return false;"'; ?>>
                                                    <i class="ri-file-word-2-line text-primary" aria-hidden="true"></i>
                                                    Download DOCX (2 credits)
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item icon-menu-item<?php echo $canDownloadReports ? '' : ' disabled'; ?>"
                                                   href="<?php echo $canDownloadReports ? h($printBaseUrl . '&download=xlsx') : '#'; ?>"
                                                   <?php echo $canDownloadReports ? 'target="_blank" rel="noreferrer"' : 'aria-disabled="true" onclick="return false;"'; ?>>
                                                    <i class="ri-file-excel-2-line text-success" aria-hidden="true"></i>
                                                    Download XLSX (2 credits)
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item icon-menu-item<?php echo $canDownloadReports ? '' : ' disabled'; ?>"
                                                   href="<?php echo $canDownloadReports ? h($printBaseUrl . '&download=pdf') : '#'; ?>"
                                                   <?php echo $canDownloadReports ? 'target="_blank" rel="noreferrer"' : 'aria-disabled="true" onclick="return false;"'; ?>>
                                                    <i class="ri-file-pdf-2-line text-danger" aria-hidden="true"></i>
                                                    Download PDF (2 credits)
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item icon-menu-item<?php echo $canDownloadReports ? '' : ' disabled'; ?>"
                                                   href="<?php echo $canDownloadReports ? h($printBaseUrl . '&download=csv') : '#'; ?>"
                                                   <?php echo $canDownloadReports ? 'target="_blank" rel="noreferrer"' : 'aria-disabled="true" onclick="return false;"'; ?>>
                                                    <i class="ri-file-list-3-line text-muted" aria-hidden="true"></i>
                                                    Download CSV (2 credits)
                                                </a>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                                <h4 class="page-title">Monthly Accomplishment</h4>
                            </div>
                        </div>
                    </div>

                    <div class="ops-hero accomp-hero ops-page-shell no-print" data-ops-parallax>
                        <div class="ops-hero__bg" data-ops-parallax-layer aria-hidden="true"></div>
                        <div class="ops-hero__content">
                            <div class="d-flex align-items-end justify-content-between flex-wrap gap-2">
                                <div>
                                    <div class="ops-hero__kicker">Teacher Workspace</div>
                                    <h1 class="ops-hero__title h3">Monthly Accomplishment</h1>
                                    <div class="ops-hero__subtitle">
                                        Track accomplishments by month and subject, then generate printable reports from the same workspace.
                                    </div>
                                </div>
                                <div class="ops-hero__chips">
                                    <div class="ops-chip">
                                        <span>Month</span>
                                        <strong><?php echo h(date('M Y', strtotime($firstDay))); ?></strong>
                                    </div>
                                    <div class="ops-chip">
                                        <span>Subjects</span>
                                        <strong><?php echo (int) count($selectedSubjects); ?></strong>
                                    </div>
                                    <div class="ops-chip">
                                        <span>Total Entries</span>
                                        <strong><?php echo (int) count($entries); ?></strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($flash): ?>
                        <div class="alert alert-<?php echo h($flashType); ?>" role="alert">
                            <?php echo h($flash); ?>
                        </div>
                    <?php endif; ?>
                    <div class="alert accomp-credit-banner no-print ops-page-shell" role="status">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                            <div>
                                <div class="text-uppercase fw-semibold small text-muted mb-1">Credits</div>
                                <div class="accomp-credit-kpi">
                                    <?php echo number_format((float) $aiCredit['remaining'], 2, '.', ''); ?> remaining
                                    <span class="text-muted fw-normal">of <?php echo number_format((float) $aiCredit['limit'], 2, '.', ''); ?> (used: <?php echo number_format((float) $aiCredit['used'], 2, '.', ''); ?>)</span>
                                </div>
                                <div class="accomp-credit-note mt-1">
                                    AI re-phrase uses 1 credit when charged. Report downloads (DOCX/XLSX/PDF/CSV) cost 2 credits.
                                </div>
                            </div>
                            <div>
                                <?php $openAiReady = !empty($rephraseProviders['openai']['configured']); ?>
                                <?php $geminiReady = !empty($rephraseProviders['gemini']['configured']); ?>
                                <span class="accomp-provider-pill <?php echo $openAiReady ? 'is-ready' : 'is-missing'; ?>">
                                    <i class="<?php echo $openAiReady ? 'ri-checkbox-circle-line' : 'ri-error-warning-line'; ?>" aria-hidden="true"></i>
                                    <?php echo h((string) ($rephraseProviders['openai']['label'] ?? 'Model 1')); ?>
                                </span>
                                <span class="accomp-provider-pill <?php echo $geminiReady ? 'is-ready' : 'is-missing'; ?>">
                                    <i class="<?php echo $geminiReady ? 'ri-checkbox-circle-line' : 'ri-error-warning-line'; ?>" aria-hidden="true"></i>
                                    <?php echo h((string) ($rephraseProviders['gemini']['label'] ?? 'Model 2')); ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-xl-4">
                            <div class="card ops-card ops-page-shell">
                                <div class="card-body">
                                    <h4 class="header-title">Report Month</h4>
                                    <p class="text-muted mb-3">Select year, month, and subjects to view or print.</p>

                                    <form method="get" action="monthly-accomplishment.php" class="no-print" id="accomp-filter-form">
                                        <div class="accomp-period-grid">
                                            <div>
                                                <label class="form-label">Year</label>
                                                <select class="form-select" name="year" required>
                                                    <?php for ($y = $yearEnd; $y >= $yearStart; $y--): ?>
                                                        <option value="<?php echo (int) $y; ?>" <?php echo $y === $selectedYear ? 'selected' : ''; ?>>
                                                            <?php echo (int) $y; ?>
                                                        </option>
                                                    <?php endfor; ?>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="form-label">Month</label>
                                                <select class="form-select" name="month_num" required>
                                                    <?php foreach ($monthNameOptions as $monthNum => $monthName): ?>
                                                        <option value="<?php echo (int) $monthNum; ?>" <?php echo $monthNum === $selectedMonthNum ? 'selected' : ''; ?>>
                                                            <?php echo h($monthName); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="mt-3">
                                            <label class="form-label">Subjects</label>
                                            <div class="accomp-subject-toolbar">
                                                <div class="text-muted small">
                                                    <span id="accomp-selected-count"><?php echo (int) count($selectedSubjects); ?></span> selected
                                                </div>
                                                <div class="accomp-subject-actions">
                                                    <button type="button" class="btn btn-sm btn-outline-secondary js-subject-select-all">Select All</button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary js-subject-select-visible">Select Visible</button>
                                                    <button type="button" class="btn btn-sm btn-light js-subject-clear-all">Clear All</button>
                                                </div>
                                            </div>
                                            <div class="accomp-subject-search">
                                                <i class="ri-search-line" aria-hidden="true"></i>
                                                <input type="search"
                                                    id="accomp-subject-search"
                                                    class="form-control form-control-sm accomp-subject-search-input"
                                                    placeholder="Search subject..."
                                                    autocomplete="off">
                                            </div>
                                            <div class="accomp-subject-checklist" id="accomp-subject-checklist">
                                                <?php foreach ($subjectOptions as $idx => $opt): ?>
                                                    <?php
                                                    $subjectId = 'subject-filter-' . (int) $idx;
                                                    $selected = isset($selectedSubjectKeys[acc_norm_label($opt)]);
                                                    ?>
                                                    <div class="form-check accomp-subject-item<?php echo $selected ? ' is-selected' : ''; ?> js-subject-item"
                                                        data-subject-label="<?php echo h(strtolower((string) $opt)); ?>">
                                                        <input class="form-check-input js-subject-check"
                                                               type="checkbox"
                                                               name="subject[]"
                                                               id="<?php echo h($subjectId); ?>"
                                                               value="<?php echo h($opt); ?>"
                                                            <?php echo $selected ? 'checked' : ''; ?>>
                                                        <label class="form-check-label" for="<?php echo h($subjectId); ?>">
                                                            <?php echo h($opt); ?>
                                                        </label>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <div class="accomp-subject-empty mt-2" id="accomp-subject-empty">
                                                No subjects match your search.
                                            </div>
                                            <div class="accomp-selected-chip-row" id="accomp-selected-chip-row"></div>
                                            <div class="form-text">Use checkboxes to combine subjects. Ctrl/Cmd click is not needed.</div>
                                        </div>
                                        <div class="mt-3">
                                            <button class="btn btn-primary" type="submit">
                                                <i class="ri-search-line me-1" aria-hidden="true"></i>
                                                Load
                                            </button>
                                        </div>
                                    </form>

                                    <hr class="my-4">

                                    <div class="d-flex justify-content-between align-items-center gap-2">
                                        <h4 class="header-title mb-0">Accomplishment Calendar</h4>
                                        <button type="button"
                                            class="btn btn-success btn-sm no-print icon-btn"
                                            id="btn-open-accomp-modal"
                                            title="Add accomplishment"
                                            aria-label="Add accomplishment">
                                            <i class="ri-add-line" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                    <p class="text-muted mb-3">Click any date to open the accomplishment form.</p>
                                    <div id="accomplishment-calendar" class="no-print"></div>
                                    <div class="mt-2 small text-muted no-print">
                                        Selected date:
                                        <strong id="accomp-selected-date-label"><?php echo h(date('M j, Y', strtotime($defaultEntryDate))); ?></strong>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-8">
                            <div class="card ops-card ops-page-shell">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                        <div>
                                            <h4 class="header-title mb-1">Entries for <?php echo h(date('F Y', strtotime($firstDay))); ?></h4>
                                            <div class="text-muted small"><?php echo h((count($selectedSubjects) > 1 ? 'Subjects: ' : 'Subject: ') . $subjectSummary); ?></div>
                                            <div class="accomp-subject-summary no-print" aria-label="Selected subjects">
                                                <?php foreach ($selectedSubjects as $summarySubject): ?>
                                                    <span class="accomp-subject-pill"><?php echo h($summarySubject); ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                            <div class="text-muted small">Owner: <?php echo h($displayName); ?></div>
                                        </div>
                                        <div class="text-muted small">
                                            Total entries: <strong><?php echo (int) count($entries); ?></strong>
                                        </div>
                                    </div>

                                    <div class="mt-3">
                                        <?php if (count($entries) === 0): ?>
                                            <div class="accomp-empty-state">
                                                <div class="fw-semibold mb-1">No accomplishments yet for this filter.</div>
                                                <div class="text-muted small mb-3">Start by adding your first entry for the selected month and subject(s).</div>
                                                <button type="button"
                                                    class="btn btn-primary btn-sm no-print"
                                                    id="btn-open-accomp-modal-empty">
                                                    <i class="ri-add-line me-1" aria-hidden="true"></i>
                                                    Add First Entry
                                                </button>
                                            </div>
                                        <?php else: ?>
                                            <div class="table-responsive">
                                                <table class="table table-sm table-hover entries-table mb-0 ops-table">
                                                    <thead>
                                                        <tr>
                                                            <th>Date</th>
                                                            <th>Type</th>
                                                            <th>Description</th>
                                                            <th>Remarks</th>
                                                            <th>Proofs</th>
                                                            <th class="no-print">Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($entries as $e): ?>
                                                            <?php
                                                            $eid = (int) ($e['id'] ?? 0);
                                                            $d = (string) ($e['entry_date'] ?? '');
                                                            $type = acc_normalize_entry_type((string) ($e['title'] ?? ''));
                                                            if ($type === '') $type = trim((string) ($e['title'] ?? ''));
                                                            if ($type === '') $type = 'Lecture';
                                                            $entrySubjectLabel = trim((string) ($e['subject_label'] ?? ''));
                                                            if ($entrySubjectLabel === '') $entrySubjectLabel = 'Monthly Accomplishment';
                                                            $description = (string) ($e['details'] ?? '');
                                                            $remarks = (string) ($e['remarks'] ?? '');
                                                            $proofs = is_array($e['proofs'] ?? null) ? $e['proofs'] : [];
                                                            $proofCount = count($proofs);
                                                            $proofCollapseId = 'proof-row-' . $eid;
                                                            ?>
                                                            <tr>
                                                                <td class="entry-date-cell">
                                                                    <?php echo h(date('D, M j, Y', strtotime($d))); ?>
                                                                </td>
                                                                <td>
                                                                    <div class="fw-semibold"><?php echo h($type); ?></div>
                                                                    <div class="small text-muted"><?php echo h($entrySubjectLabel); ?></div>
                                                                </td>
                                                                <td>
                                                                    <div class="entry-snippet text-muted small"><?php echo h(trim($description) !== '' ? $description : '-'); ?></div>
                                                                </td>
                                                                <td>
                                                                    <div class="entry-snippet text-muted small"><?php echo h(trim($remarks) !== '' ? $remarks : '-'); ?></div>
                                                                </td>
                                                                <td>
                                                                    <span class="badge bg-info-subtle text-info"><?php echo (int) $proofCount; ?></span>
                                                                    <button type="button"
                                                                        class="btn btn-sm btn-outline-primary ms-1 no-print icon-btn"
                                                                        data-bs-toggle="collapse"
                                                                        data-bs-target="#<?php echo h($proofCollapseId); ?>"
                                                                        aria-expanded="false"
                                                                        aria-controls="<?php echo h($proofCollapseId); ?>"
                                                                        title="Manage proofs"
                                                                        aria-label="Manage proofs">
                                                                        <i class="ri-folder-open-line" aria-hidden="true"></i>
                                                                    </button>
                                                                </td>
                                                                <td class="no-print entry-actions">
                                                                    <div class="dropdown">
                                                                        <button type="button"
                                                                            class="btn btn-sm btn-outline-secondary dropdown-toggle icon-btn kebab-toggle"
                                                                            data-bs-toggle="dropdown"
                                                                            aria-expanded="false"
                                                                            title="Entry actions"
                                                                            aria-label="Entry actions">
                                                                            <i class="ri-more-2-fill" aria-hidden="true"></i>
                                                                        </button>
                                                                        <ul class="dropdown-menu dropdown-menu-end">
                                                                            <li>
                                                                                <button type="button"
                                                                                    class="dropdown-item icon-menu-item js-open-edit"
                                                                                    data-entry-id="<?php echo (int) $eid; ?>">
                                                                                    <i class="ri-pencil-line text-muted" aria-hidden="true"></i>
                                                                                    Edit accomplishment
                                                                                </button>
                                                                            </li>
                                                                            <li>
                                                                                <button type="button"
                                                                                    class="dropdown-item icon-menu-item js-open-reassign"
                                                                                    data-entry-id="<?php echo (int) $eid; ?>">
                                                                                    <i class="ri-folder-transfer-line text-info" aria-hidden="true"></i>
                                                                                    Reassign subject
                                                                                </button>
                                                                            </li>
                                                                            <li>
                                                                                <form method="post" class="m-0">
                                                                                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                                                                                    <input type="hidden" name="action" value="rephrase_entry_ai">
                                                                                    <input type="hidden" name="ai_provider" value="openai">
                                                                                    <input type="hidden" name="entry_id" value="<?php echo (int) $eid; ?>">
                                                                                    <button class="dropdown-item icon-menu-item<?php echo $canRephraseWithOpenAi ? '' : ' disabled'; ?>"
                                                                                        type="submit"
                                                                                        <?php echo $canRephraseWithOpenAi ? '' : 'disabled aria-disabled="true"'; ?>
                                                                                        <?php echo $canRephraseWithOpenAi ? 'onclick="return confirm(\'Re-phrase description and remarks with Model 1?\');"' : ''; ?>>
                                                                                        <i class="ri-magic-line text-primary" aria-hidden="true"></i>
                                                                                        AI re-phrase (Model 1)
                                                                                    </button>
                                                                                </form>
                                                                            </li>
                                                                            <li>
                                                                                <form method="post" class="m-0">
                                                                                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                                                                                    <input type="hidden" name="action" value="rephrase_entry_ai">
                                                                                    <input type="hidden" name="ai_provider" value="gemini">
                                                                                    <input type="hidden" name="entry_id" value="<?php echo (int) $eid; ?>">
                                                                                    <button class="dropdown-item icon-menu-item<?php echo $canRephraseWithGemini ? '' : ' disabled'; ?>"
                                                                                        type="submit"
                                                                                        <?php echo $canRephraseWithGemini ? '' : 'disabled aria-disabled="true"'; ?>
                                                                                        <?php echo $canRephraseWithGemini ? 'onclick="return confirm(\'Re-phrase description and remarks with Model 2?\');"' : ''; ?>>
                                                                                        <i class="ri-magic-line text-success" aria-hidden="true"></i>
                                                                                        AI re-phrase (Model 2)
                                                                                    </button>
                                                                                </form>
                                                                            </li>
                                                                            <li><hr class="dropdown-divider"></li>
                                                                            <li>
                                                                                <form method="post" class="m-0">
                                                                                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                                                                                    <input type="hidden" name="action" value="delete_entry">
                                                                                    <input type="hidden" name="entry_id" value="<?php echo (int) $eid; ?>">
                                                                                    <button class="dropdown-item icon-menu-item text-danger"
                                                                                        type="submit"
                                                                                        onclick="return confirm('Delete this entry and all its proofs?');">
                                                                                        <i class="ri-delete-bin-line" aria-hidden="true"></i>
                                                                                        Delete accomplishment
                                                                                    </button>
                                                                                </form>
                                                                            </li>
                                                                        </ul>
                                                                    </div>
                                                                </td>
                                                            </tr>
                                                            <tr class="collapse proof-detail-row no-print" id="<?php echo h($proofCollapseId); ?>">
                                                                <td colspan="6">
                                                                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                                                        <div class="text-muted small">
                                                                            Upload or remove proof images for <strong><?php echo h($type); ?></strong>.
                                                                        </div>
                                                                        <form method="post" enctype="multipart/form-data" class="d-flex gap-2 flex-wrap align-items-center">
                                                                            <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                                                                            <input type="hidden" name="action" value="add_proofs">
                                                                            <input type="hidden" name="entry_id" value="<?php echo (int) $eid; ?>">
                                                                            <input class="form-control form-control-sm" style="max-width: 320px;"
                                                                                type="file" name="proofs[]" accept="image/*" multiple>
                                                                            <button class="btn btn-sm btn-outline-primary icon-btn"
                                                                                type="submit"
                                                                                title="Upload proof images"
                                                                                aria-label="Upload proof images">
                                                                                <i class="ri-upload-cloud-2-line" aria-hidden="true"></i>
                                                                            </button>
                                                                        </form>
                                                                    </div>

                                                                    <?php if ($proofCount > 0): ?>
                                                                        <div class="proof-grid mt-3">
                                                                            <?php foreach ($proofs as $p): ?>
                                                                                <?php
                                                                                $pid = (int) ($p['id'] ?? 0);
                                                                                $rel = (string) ($p['file_path'] ?? '');
                                                                                ?>
                                                                                <div class="proof-item">
                                                                                    <a href="<?php echo h($rel); ?>" target="_blank" rel="noreferrer" title="<?php echo h((string) ($p['original_name'] ?? '')); ?>">
                                                                                        <img class="proof-thumb" src="<?php echo h($rel); ?>" alt="proof">
                                                                                    </a>
                                                                                    <div class="proof-actions">
                                                                                        <form method="post">
                                                                                            <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                                                                                            <input type="hidden" name="action" value="delete_proof">
                                                                                            <input type="hidden" name="proof_id" value="<?php echo (int) $pid; ?>">
                                                                                            <button class="btn btn-sm btn-outline-danger icon-btn"
                                                                                                type="submit"
                                                                                                onclick="return confirm('Delete this proof?');"
                                                                                                title="Delete proof"
                                                                                                aria-label="Delete proof">
                                                                                                <i class="ri-close-line" aria-hidden="true"></i>
                                                                                            </button>
                                                                                        </form>
                                                                                    </div>
                                                                                </div>
                                                                            <?php endforeach; ?>
                                                                        </div>
                                                                    <?php else: ?>
                                                                        <div class="small text-muted mt-2">No proofs uploaded yet.</div>
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
                    </div>

                    <div class="modal fade" id="accomplishment-form-modal" tabindex="-1" aria-labelledby="accomplishmentFormLabel" aria-hidden="true">
                        <div class="modal-dialog modal-lg modal-dialog-scrollable">
                            <div class="modal-content">
                                <form method="post" enctype="multipart/form-data" class="no-print accomp-modal-form">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="accomplishmentFormLabel">Add Accomplishment</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                                        <input type="hidden" name="action" value="create_entry">

                                        <div class="mb-3">
                                            <label class="form-label">Subject</label>
                                            <select class="form-select" name="subject" required>
                                                <?php foreach ($selectedSubjects as $entrySubjectOpt): ?>
                                                    <?php $isEntrySubjectDefault = (acc_norm_label($entrySubjectOpt) === acc_norm_label($subject)); ?>
                                                    <option value="<?php echo h($entrySubjectOpt); ?>" <?php echo $isEntrySubjectDefault ? 'selected' : ''; ?>>
                                                        <?php echo h($entrySubjectOpt); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div class="accomp-modal-note mt-1">Each entry is saved under one subject.</div>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">Date</label>
                                            <input type="date"
                                                class="form-control"
                                                id="accomp-modal-entry-date"
                                                name="entry_date"
                                                value="<?php echo h($defaultEntryDate); ?>"
                                                min="<?php echo h($firstDay); ?>"
                                                max="<?php echo h($lastDay); ?>"
                                                required>
                                            <div class="accomp-modal-note mt-1">Date is limited to the selected month.</div>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">Type</label>
                                            <select class="form-select" name="type" required>
                                                <?php foreach ($accomplishmentTypes as $optType): ?>
                                                    <option value="<?php echo h($optType); ?>"><?php echo h($optType); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">Description</label>
                                            <textarea class="form-control" name="description" rows="4" maxlength="5000" placeholder="Short description, outputs, highlights..." required></textarea>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">Remarks (optional)</label>
                                            <textarea class="form-control" name="remarks" rows="3" maxlength="5000" placeholder="Progress notes, issues, or deviations from plan..."></textarea>
                                        </div>

                                        <div class="mb-0">
                                            <label class="form-label">Proof Images (optional)</label>
                                            <input class="form-control" type="file" name="proofs[]" accept="image/*" multiple>
                                            <div class="form-text">Max 5MB per image. JPG/PNG/GIF/WEBP.</div>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                                        <button class="btn btn-success" type="submit">
                                            <i class="ri-add-line me-1" aria-hidden="true"></i>
                                            Create Entry
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="modal fade" id="accomplishment-edit-modal" tabindex="-1" aria-labelledby="accomplishmentEditLabel" aria-hidden="true">
                        <div class="modal-dialog modal-lg modal-dialog-scrollable">
                            <div class="modal-content">
                                <form method="post" class="no-print accomp-modal-form" id="accomplishment-edit-form">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="accomplishmentEditLabel">Edit Accomplishment</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                                        <input type="hidden" name="action" value="update_entry">
                                        <input type="hidden" name="entry_id" id="accomp-edit-entry-id" value="">

                                        <div class="mb-3">
                                            <label class="form-label" for="accomp-edit-entry-date">Date</label>
                                            <input type="date"
                                                class="form-control"
                                                id="accomp-edit-entry-date"
                                                name="entry_date"
                                                min="<?php echo h($firstDay); ?>"
                                                max="<?php echo h($lastDay); ?>"
                                                required>
                                            <div class="accomp-modal-note mt-1">Date is limited to the selected month.</div>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label" for="accomp-edit-type">Type</label>
                                            <select class="form-select" id="accomp-edit-type" name="type" required>
                                                <?php foreach ($accomplishmentTypes as $optType): ?>
                                                    <option value="<?php echo h($optType); ?>"><?php echo h($optType); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label" for="accomp-edit-description">Description</label>
                                            <textarea class="form-control"
                                                id="accomp-edit-description"
                                                name="description"
                                                rows="4"
                                                maxlength="5000"
                                                required></textarea>
                                        </div>

                                        <div class="mb-0">
                                            <label class="form-label" for="accomp-edit-remarks">Remarks (optional)</label>
                                            <textarea class="form-control"
                                                id="accomp-edit-remarks"
                                                name="remarks"
                                                rows="3"
                                                maxlength="5000"></textarea>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                                        <button class="btn btn-primary" type="submit">
                                            <i class="ri-save-line me-1" aria-hidden="true"></i>
                                            Save Changes
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="modal fade" id="accomplishment-reassign-modal" tabindex="-1" aria-labelledby="accomplishmentReassignLabel" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content">
                                <form method="post" class="no-print accomp-modal-form" id="accomplishment-reassign-form">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="accomplishmentReassignLabel">Reassign Subject</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                                        <input type="hidden" name="action" value="reassign_entry_subject">
                                        <input type="hidden" name="entry_id" id="accomp-reassign-entry-id" value="">

                                        <div class="mb-3">
                                            <label class="form-label">Current subject</label>
                                            <div class="form-control bg-light" id="accomp-reassign-current-subject">-</div>
                                        </div>

                                        <div class="mb-0">
                                            <label class="form-label" for="accomp-reassign-subject">Move this entry to</label>
                                            <select class="form-select" id="accomp-reassign-subject" name="subject" required>
                                                <?php foreach ($subjectOptions as $subjectOpt): ?>
                                                    <option value="<?php echo h($subjectOpt); ?>"><?php echo h($subjectOpt); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div class="accomp-modal-note mt-1">Choose the correct subject, then save.</div>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                                        <button class="btn btn-primary" type="submit">
                                            <i class="ri-check-line me-1" aria-hidden="true"></i>
                                            Reassign
                                        </button>
                                    </div>
                                </form>
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
    <script src="assets/vendor/fullcalendar/main.min.js"></script>
    <script src="assets/js/admin-ops-ui.js"></script>
    <script>
        (function () {
            const calendarEl = document.getElementById('accomplishment-calendar');
            const modalEl = document.getElementById('accomplishment-form-modal');
            const editModalEl = document.getElementById('accomplishment-edit-modal');
            const reassignModalEl = document.getElementById('accomplishment-reassign-modal');
            const openBtn = document.getElementById('btn-open-accomp-modal');
            const openEmptyBtn = document.getElementById('btn-open-accomp-modal-empty');
            const dateInput = document.getElementById('accomp-modal-entry-date');
            const dateLabel = document.getElementById('accomp-selected-date-label');
            const editEntryIdInput = document.getElementById('accomp-edit-entry-id');
            const editDateInput = document.getElementById('accomp-edit-entry-date');
            const editTypeInput = document.getElementById('accomp-edit-type');
            const editDescriptionInput = document.getElementById('accomp-edit-description');
            const editRemarksInput = document.getElementById('accomp-edit-remarks');
            const editButtons = document.querySelectorAll('.js-open-edit');
            const reassignButtons = document.querySelectorAll('.js-open-reassign');
            const reassignEntryIdInput = document.getElementById('accomp-reassign-entry-id');
            const reassignCurrentSubjectEl = document.getElementById('accomp-reassign-current-subject');
            const reassignSubjectInput = document.getElementById('accomp-reassign-subject');
            const filterForm = document.getElementById('accomp-filter-form');
            const subjectChecks = document.querySelectorAll('.js-subject-check');
            const subjectItems = document.querySelectorAll('.js-subject-item');
            const subjectSearchInput = document.getElementById('accomp-subject-search');
            const selectedCountEl = document.getElementById('accomp-selected-count');
            const selectedChipRow = document.getElementById('accomp-selected-chip-row');
            const subjectEmptyEl = document.getElementById('accomp-subject-empty');
            const selectAllSubjectsBtn = document.querySelector('.js-subject-select-all');
            const selectVisibleSubjectsBtn = document.querySelector('.js-subject-select-visible');
            const clearAllSubjectsBtn = document.querySelector('.js-subject-clear-all');

            const firstDay = <?php echo json_encode($firstDay); ?>;
            const lastDay = <?php echo json_encode($lastDay); ?>;
            const afterLastDay = <?php echo json_encode($afterLastDay); ?>;
            const defaultDate = <?php echo json_encode($defaultEntryDate); ?>;
            const dayCounts = <?php echo $calendarDayCountsJson; ?>;
            const entryEditMap = <?php echo $entryEditMapJson; ?>;

            let selectedDate = defaultDate;
            const modal = (modalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal)
                ? bootstrap.Modal.getOrCreateInstance(modalEl)
                : null;
            const editModal = (editModalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal)
                ? bootstrap.Modal.getOrCreateInstance(editModalEl)
                : null;
            const reassignModal = (reassignModalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal)
                ? bootstrap.Modal.getOrCreateInstance(reassignModalEl)
                : null;

            const toYmd = (dt) => {
                if (!(dt instanceof Date)) return '';
                const year = dt.getFullYear();
                const month = String(dt.getMonth() + 1).padStart(2, '0');
                const day = String(dt.getDate()).padStart(2, '0');
                return year + '-' + month + '-' + day;
            };

            const getCountForDate = (ymd) => {
                if (!ymd || !dayCounts || typeof dayCounts !== 'object') return 0;
                if (!Object.prototype.hasOwnProperty.call(dayCounts, ymd)) return 0;
                const n = Number(dayCounts[ymd] || 0);
                return Number.isFinite(n) ? n : 0;
            };

            const formatHumanDate = (ymd) => {
                const dt = new Date(ymd + 'T00:00:00');
                if (Number.isNaN(dt.getTime())) return ymd;
                return dt.toLocaleDateString(undefined, {
                    month: 'short',
                    day: 'numeric',
                    year: 'numeric'
                });
            };

            const setSelectedDate = (ymd) => {
                if (!ymd) return;
                if (ymd < firstDay || ymd > lastDay) return;
                selectedDate = ymd;
                if (dateInput) dateInput.value = ymd;
                if (dateLabel) dateLabel.textContent = formatHumanDate(ymd);
            };

            const normalizeSubjectToken = (value) => String(value || '')
                .toLowerCase()
                .replace(/\s+/g, ' ')
                .trim();

            const getSubjectLabel = (checkbox) => {
                if (!checkbox) return '';
                const item = checkbox.closest('.js-subject-item');
                const label = item ? item.querySelector('.form-check-label') : null;
                const text = label ? label.textContent : checkbox.value;
                return String(text || '').trim();
            };

            const updateSelectedSubjectUi = () => {
                const checked = Array.from(subjectChecks).filter(function (el) { return !!el.checked; });
                if (selectedCountEl) selectedCountEl.textContent = String(checked.length);

                subjectItems.forEach(function (item) {
                    const cb = item.querySelector('.js-subject-check');
                    item.classList.toggle('is-selected', !!(cb && cb.checked));
                });

                if (!selectedChipRow) return;
                selectedChipRow.innerHTML = '';

                if (checked.length === 0) {
                    const emptyChip = document.createElement('span');
                    emptyChip.className = 'text-muted small';
                    emptyChip.textContent = 'No subject selected.';
                    selectedChipRow.appendChild(emptyChip);
                    return;
                }

                const maxPreview = 4;
                checked.slice(0, maxPreview).forEach(function (cb) {
                    const chip = document.createElement('span');
                    chip.className = 'accomp-selected-chip';
                    const text = document.createElement('span');
                    text.className = 'accomp-selected-chip-text';
                    text.textContent = getSubjectLabel(cb);
                    chip.appendChild(text);
                    selectedChipRow.appendChild(chip);
                });

                if (checked.length > maxPreview) {
                    const chip = document.createElement('span');
                    chip.className = 'accomp-selected-chip';
                    chip.textContent = '+' + String(checked.length - maxPreview) + ' more';
                    selectedChipRow.appendChild(chip);
                }
            };

            const filterSubjectList = () => {
                const searchTerm = normalizeSubjectToken(subjectSearchInput ? subjectSearchInput.value : '');
                let visibleCount = 0;
                subjectItems.forEach(function (item) {
                    const haystack = normalizeSubjectToken(item.getAttribute('data-subject-label') || item.textContent);
                    const isVisible = searchTerm === '' || haystack.indexOf(searchTerm) !== -1;
                    item.classList.toggle('d-none', !isVisible);
                    if (isVisible) visibleCount++;
                });
                if (subjectEmptyEl) subjectEmptyEl.classList.toggle('is-visible', visibleCount === 0);
            };

            const openModalForDate = (ymd) => {
                setSelectedDate(ymd || selectedDate);
                if (modal) modal.show();
            };

            const openEditModal = (entryId) => {
                const key = String(entryId || '');
                if (!key || !entryEditMap || typeof entryEditMap !== 'object') return;
                const entry = entryEditMap[key];
                if (!entry) return;

                if (editEntryIdInput) editEntryIdInput.value = key;
                if (editDateInput) editDateInput.value = String(entry.entry_date || '');
                if (editTypeInput) {
                    const currentType = String(entry.title || '');
                    const hasTypeOption = Array.from(editTypeInput.options || []).some((opt) => String(opt.value) === currentType);
                    editTypeInput.value = hasTypeOption ? currentType : 'Lecture';
                }
                if (editDescriptionInput) editDescriptionInput.value = String(entry.details || '');
                if (editRemarksInput) editRemarksInput.value = String(entry.remarks || '');

                if (editModal) editModal.show();
            };

            const selectSubjectOption = (subjectValue) => {
                if (!reassignSubjectInput) return;
                const normalizedTarget = normalizeSubjectToken(subjectValue);
                if (!normalizedTarget) return;

                Array.from(reassignSubjectInput.querySelectorAll('option[data-temp-current="1"]')).forEach(function (opt) {
                    opt.remove();
                });

                let selectedValue = '';
                Array.from(reassignSubjectInput.options || []).forEach(function (opt) {
                    if (selectedValue !== '') return;
                    if (normalizeSubjectToken(opt.value) === normalizedTarget) selectedValue = String(opt.value || '');
                });

                if (selectedValue === '') {
                    const tempOption = document.createElement('option');
                    tempOption.value = subjectValue;
                    tempOption.textContent = subjectValue + ' (Current)';
                    tempOption.setAttribute('data-temp-current', '1');
                    reassignSubjectInput.insertBefore(tempOption, reassignSubjectInput.firstChild);
                    selectedValue = subjectValue;
                }

                reassignSubjectInput.value = selectedValue;
            };

            const openReassignModal = (entryId) => {
                const key = String(entryId || '');
                if (!key || !entryEditMap || typeof entryEditMap !== 'object') return;
                const entry = entryEditMap[key];
                if (!entry) return;

                const currentSubject = String(entry.subject_label || '').trim() || 'Monthly Accomplishment';
                if (reassignEntryIdInput) reassignEntryIdInput.value = key;
                if (reassignCurrentSubjectEl) reassignCurrentSubjectEl.textContent = currentSubject;
                selectSubjectOption(currentSubject);

                if (reassignModal) reassignModal.show();
            };

            setSelectedDate(defaultDate);

            if (selectAllSubjectsBtn && subjectChecks.length > 0) {
                selectAllSubjectsBtn.addEventListener('click', function () {
                    subjectChecks.forEach(function (el) { el.checked = true; });
                    updateSelectedSubjectUi();
                });
            }

            if (selectVisibleSubjectsBtn && subjectItems.length > 0) {
                selectVisibleSubjectsBtn.addEventListener('click', function () {
                    subjectItems.forEach(function (item) {
                        if (item.classList.contains('d-none')) return;
                        const cb = item.querySelector('.js-subject-check');
                        if (cb) cb.checked = true;
                    });
                    updateSelectedSubjectUi();
                });
            }

            if (clearAllSubjectsBtn && subjectChecks.length > 0) {
                clearAllSubjectsBtn.addEventListener('click', function () {
                    subjectChecks.forEach(function (el) { el.checked = false; });
                    updateSelectedSubjectUi();
                });
            }

            if (subjectChecks.length > 0) {
                subjectChecks.forEach(function (el) {
                    el.addEventListener('change', updateSelectedSubjectUi);
                });
            }

            if (subjectSearchInput) {
                subjectSearchInput.addEventListener('input', filterSubjectList);
                subjectSearchInput.addEventListener('keydown', function (event) {
                    if (event.key === 'Escape') {
                        subjectSearchInput.value = '';
                        filterSubjectList();
                    }
                });
            }

            if (filterForm && subjectChecks.length > 0) {
                filterForm.addEventListener('submit', function (event) {
                    const hasAnyChecked = Array.from(subjectChecks).some(function (el) { return !!el.checked; });
                    if (hasAnyChecked) return;
                    event.preventDefault();
                    window.alert('Select at least one subject.');
                });
            }

            if (openBtn) {
                openBtn.addEventListener('click', function () {
                    openModalForDate(selectedDate);
                });
            }

            if (openEmptyBtn) {
                openEmptyBtn.addEventListener('click', function () {
                    openModalForDate(selectedDate);
                });
            }

            editButtons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    openEditModal(btn.getAttribute('data-entry-id'));
                });
            });

            reassignButtons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    openReassignModal(btn.getAttribute('data-entry-id'));
                });
            });

            filterSubjectList();
            updateSelectedSubjectUi();

            if (calendarEl && typeof FullCalendar !== 'undefined') {
                const calendar = new FullCalendar.Calendar(calendarEl, {
                    initialView: 'dayGridMonth',
                    initialDate: firstDay,
                    headerToolbar: {
                        left: '',
                        center: 'title',
                        right: ''
                    },
                    height: 'auto',
                    fixedWeekCount: false,
                    validRange: {
                        start: firstDay,
                        end: afterLastDay
                    },
                    dateClick: function (info) {
                        openModalForDate(info.dateStr);
                    },
                    dayCellDidMount: function (info) {
                        const key = toYmd(info.date);
                        const count = getCountForDate(key);
                        if (count <= 0) return;

                        const topEl = info.el.querySelector('.fc-daygrid-day-top');
                        if (!topEl) return;

                        const badge = document.createElement('span');
                        badge.className = 'accomp-day-count badge rounded-pill';
                        badge.textContent = String(count);
                        badge.title = count === 1 ? '1 entry' : (String(count) + ' entries');
                        topEl.appendChild(badge);
                    }
                });
                calendar.render();
            }
        })();
    </script>
    <script src="assets/js/app.min.js"></script>
</body>
</html>
