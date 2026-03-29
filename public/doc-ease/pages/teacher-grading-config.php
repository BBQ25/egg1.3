<?php include '../layouts/session.php'; ?>
<?php require_active_role('teacher'); ?>
<?php include '../layouts/main.php'; ?>

<?php
require_once __DIR__ . '/../includes/class_record_builds.php';
require_once __DIR__ . '/../includes/usage_limits.php';
require_once __DIR__ . '/../includes/grading.php';
require_once __DIR__ . '/../includes/section_sync.php';
require_once __DIR__ . '/../includes/ai_chat_credits.php';
require_once __DIR__ . '/../includes/teacher_build_ai.php';
require_once __DIR__ . '/../includes/teacher_activity_events.php';
ensure_users_build_limit_column($conn);
ensure_section_grading_term($conn);
ensure_class_record_build_tables($conn);
ensure_grading_tables($conn);
usage_limit_ensure_system($conn);
ai_chat_credit_ensure_system($conn);
teacher_activity_ensure_tables($conn);

$flash = isset($_SESSION['flash_message']) ? (string) $_SESSION['flash_message'] : '';
$flashType = isset($_SESSION['flash_type']) ? (string) $_SESSION['flash_type'] : 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

$teacherId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$teacherName = isset($_SESSION['user_name']) ? (string) $_SESSION['user_name'] : (string) $teacherId;

$classRecordId = isset($_GET['class_record_id']) ? (int) $_GET['class_record_id'] : 0;
if ($classRecordId <= 0) {
    $_SESSION['flash_message'] = 'Invalid class record.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: teacher-dashboard.php');
    exit;
}

// Verify the teacher is assigned to this class record (primary or co-teacher).
$class = null;
$stmt = $conn->prepare(
    "SELECT cr.id AS class_record_id,
            cr.subject_id, cr.section, cr.academic_year, cr.semester, cr.year_level,
            s.subject_code, s.subject_name, s.course,
            ta.teacher_role
     FROM teacher_assignments ta
     JOIN class_records cr ON cr.id = ta.class_record_id
     JOIN subjects s ON s.id = cr.subject_id
     WHERE ta.teacher_id = ?
       AND ta.status = 'active'
       AND cr.status = 'active'
       AND cr.id = ?
     LIMIT 1"
);
if ($stmt) {
    $stmt->bind_param('ii', $teacherId, $classRecordId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows === 1) $class = $res->fetch_assoc();
    $stmt->close();
}

if (!$class) {
    deny_access(403, 'Forbidden: not assigned to this class record.');
}

$subjectId = (int) ($class['subject_id'] ?? 0);
$section = trim((string) ($class['section'] ?? ''));
$academicYear = trim((string) ($class['academic_year'] ?? ''));
$semester = trim((string) ($class['semester'] ?? ''));

$term = isset($_GET['term']) ? strtolower(trim((string) $_GET['term'])) : 'midterm';
if (!in_array($term, ['midterm', 'final'], true)) $term = 'midterm';
$termLabel = $term === 'final' ? 'Final Term' : 'Midterm';

$course = trim((string) ($class['course'] ?? ''));
if ($course === '') $course = 'N/A';

$yearLevel = trim((string) ($class['year_level'] ?? ''));
if ($yearLevel === '') $yearLevel = 'N/A';
$syncPeerSections = section_sync_get_teacher_peer_sections(
    $conn,
    $teacherId,
    $subjectId,
    $academicYear,
    $semester,
    $classRecordId
);
$syncPreference = section_sync_get_preference($conn, $teacherId, $subjectId, $academicYear, $semester);
$syncPreferredPeerIds = section_sync_preferred_peer_ids($syncPreference, $syncPeerSections);

$aiChatRatePer100 = 0.1;
$aiChatHistorySessionKey = tgc_ai_session_key('ai_chat_history', $teacherId, $classRecordId, $term);
$aiDraftSessionKey = tgc_ai_session_key('ai_chat_draft', $teacherId, $classRecordId, $term);
$aiFeedbackSessionKey = tgc_ai_session_key('ai_chat_feedback', $teacherId, $classRecordId, $term);

// Ensure a section_grading_config exists for this class (unique per subject/course/year/section/term).
$configId = null;
$totalWeight = 100.00;
$cfg = $conn->prepare(
    "SELECT id, total_weight
     FROM section_grading_configs
     WHERE subject_id = ? AND course = ? AND year = ? AND section = ? AND academic_year = ? AND semester = ? AND term = ?
     LIMIT 1"
);
if ($cfg) {
    $cfg->bind_param('issssss', $subjectId, $course, $yearLevel, $section, $academicYear, $semester, $term);
    $cfg->execute();
    $cfgRes = $cfg->get_result();
    if ($cfgRes && $cfgRes->num_rows === 1) {
        $row = $cfgRes->fetch_assoc();
        $configId = (int) ($row['id'] ?? 0);
        $totalWeight = (float) ($row['total_weight'] ?? 100.0);
    }
    $cfg->close();
}

if (!$configId) {
    $insCfg = $conn->prepare(
        "INSERT INTO section_grading_configs (subject_id, course, year, section, academic_year, semester, term, total_weight, is_active, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, 100.00, 1, ?)"
    );
    if ($insCfg) {
        // subject_id (i) + 7 strings (course, year, section, academic_year, semester, term, created_by)
        $insCfg->bind_param('isssssss', $subjectId, $course, $yearLevel, $section, $academicYear, $semester, $term, $teacherName);
        $insCfg->execute();
        $configId = (int) $conn->insert_id;
        $insCfg->close();
    }
}

function to_decimal($value) {
    if (!is_string($value) && !is_numeric($value)) return null;
    $value = trim((string) $value);
    if ($value === '') return null;
    if (!preg_match('/^-?\\d+(?:\\.\\d+)?$/', $value)) return null;
    return (float) $value;
}

function allowed_component_types() {
    return ['quiz', 'assignment', 'project', 'exam', 'participation', 'other'];
}

function current_active_weight(mysqli $conn, $configId, $excludeComponentId = 0) {
    $sql = "SELECT COALESCE(SUM(weight), 0) AS total
            FROM grading_components
            WHERE section_config_id = ? AND is_active = 1";
    if ($excludeComponentId > 0) $sql .= " AND id <> " . (int) $excludeComponentId;

    $stmt = $conn->prepare($sql);
    if (!$stmt) return 0.0;
    $stmt->bind_param('i', $configId);
    $stmt->execute();
    $res = $stmt->get_result();
    $total = 0.0;
    if ($res && $res->num_rows === 1) $total = (float) ($res->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    return $total;
}

function tgc_ai_session_key($prefix, $teacherId, $classRecordId, $term) {
    return 'tgc_' . $prefix . '_' . (int) $teacherId . '_' . (int) $classRecordId . '_' . strtolower(trim((string) $term));
}

function tgc_ensure_section_config_for_target(mysqli $conn, $subjectId, $course, $yearLevel, $section, $academicYear, $semester, $term, $teacherName) {
    $subjectId = (int) $subjectId;
    $course = trim((string) $course);
    $yearLevel = trim((string) $yearLevel);
    $section = trim((string) $section);
    $academicYear = trim((string) $academicYear);
    $semester = trim((string) $semester);
    $term = trim((string) $term);
    if ($course === '') $course = 'N/A';
    if ($yearLevel === '') $yearLevel = 'N/A';
    if ($subjectId <= 0 || $section === '' || $academicYear === '' || $semester === '' || $term === '') return [0, 100.0];

    $configId = 0;
    $targetTotalWeight = 100.0;
    $cfg = $conn->prepare(
        "SELECT id, total_weight
         FROM section_grading_configs
         WHERE subject_id = ? AND course = ? AND year = ? AND section = ? AND academic_year = ? AND semester = ? AND term = ?
         LIMIT 1"
    );
    if ($cfg) {
        $cfg->bind_param('issssss', $subjectId, $course, $yearLevel, $section, $academicYear, $semester, $term);
        $cfg->execute();
        $res = $cfg->get_result();
        if ($res && $res->num_rows === 1) {
            $row = $res->fetch_assoc();
            $configId = (int) ($row['id'] ?? 0);
            $targetTotalWeight = (float) ($row['total_weight'] ?? 100.0);
        }
        $cfg->close();
    }

    if ($configId <= 0) {
        $ins = $conn->prepare(
            "INSERT INTO section_grading_configs (subject_id, course, year, section, academic_year, semester, term, total_weight, is_active, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, 100.00, 1, ?)"
        );
        if ($ins) {
            $ins->bind_param('isssssss', $subjectId, $course, $yearLevel, $section, $academicYear, $semester, $term, $teacherName);
            $ins->execute();
            $configId = (int) $conn->insert_id;
            $targetTotalWeight = 100.0;
            $ins->close();
        }
    }

    return [$configId, $targetTotalWeight];
}

function tgc_apply_component_rows_to_section(
    mysqli $conn,
    array $componentRows,
    $subjectId,
    $course,
    $yearLevel,
    $section,
    $academicYear,
    $semester,
    $term,
    $teacherName,
    $replaceExisting
) {
    [$targetConfigId, $targetTotalWeight] = tgc_ensure_section_config_for_target(
        $conn,
        $subjectId,
        $course,
        $yearLevel,
        $section,
        $academicYear,
        $semester,
        $term,
        $teacherName
    );
    if ($targetConfigId <= 0) return [false, 'config_unavailable'];

    $existingCount = 0;
    $cnt = $conn->prepare("SELECT COUNT(*) AS c FROM grading_components WHERE section_config_id = ?");
    if ($cnt) {
        $cnt->bind_param('i', $targetConfigId);
        $cnt->execute();
        $res = $cnt->get_result();
        if ($res && $res->num_rows === 1) $existingCount = (int) ($res->fetch_assoc()['c'] ?? 0);
        $cnt->close();
    }
    if ($existingCount > 0 && !$replaceExisting) return [false, 'has_existing'];

    $activeWeight = 0.0;
    foreach ($componentRows as $row) {
        if (!is_array($row)) continue;
        $w = (float) ($row['weight'] ?? 0);
        $isActive = isset($row['is_active']) ? (int) $row['is_active'] : 1;
        if ($isActive === 1) $activeWeight += $w;
    }
    if ($activeWeight > ($targetTotalWeight + 0.0001)) return [false, 'weight_exceeds_target'];

    $conn->begin_transaction();
    try {
        if ($replaceExisting) {
            $del = $conn->prepare("DELETE FROM grading_components WHERE section_config_id = ?");
            if ($del) {
                $del->bind_param('i', $targetConfigId);
                $del->execute();
                $del->close();
            }
        }

        $findCat = $conn->prepare("SELECT id FROM grading_categories WHERE subject_id = ? AND category_name = ? LIMIT 1");
        $insCat = $conn->prepare("INSERT INTO grading_categories (category_name, subject_id, default_weight, is_active, created_by) VALUES (?, ?, 0.00, 1, ?)");
        $insComp = $conn->prepare(
            "INSERT INTO grading_components
                (subject_id, section_config_id, academic_year, semester, course, year, section, category_id,
                 component_name, component_code, component_type, weight, is_active, display_order, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$findCat || !$insCat || !$insComp) throw new Exception('prepare_failed');

        $catCache = [];
        $inserted = 0;
        foreach ($componentRows as $row) {
            if (!is_array($row)) continue;
            $catName = trim((string) ($row['category_name'] ?? 'General'));
            if ($catName === '') $catName = 'General';
            if (strlen($catName) > 100) $catName = substr($catName, 0, 100);

            if (!isset($catCache[$catName])) {
                $catId = 0;
                $findCat->bind_param('is', $subjectId, $catName);
                $findCat->execute();
                $cr = $findCat->get_result();
                if ($cr && $cr->num_rows === 1) {
                    $catId = (int) ($cr->fetch_assoc()['id'] ?? 0);
                } else {
                    $insCat->bind_param('sis', $catName, $subjectId, $teacherName);
                    $insCat->execute();
                    $catId = (int) $conn->insert_id;
                }
                $catCache[$catName] = $catId;
            }

            $name = trim((string) ($row['component_name'] ?? ''));
            if ($name === '') continue;
            if (strlen($name) > 100) $name = substr($name, 0, 100);
            $code = trim((string) ($row['component_code'] ?? ''));
            if (strlen($code) > 50) $code = substr($code, 0, 50);
            $type = trim((string) ($row['component_type'] ?? 'other'));
            if (!in_array($type, allowed_component_types(), true)) $type = 'other';
            $weight = (float) ($row['weight'] ?? 0);
            if ($weight <= 0) continue;
            if ($weight > 100) $weight = 100.0;
            $isActive = isset($row['is_active']) ? ((int) $row['is_active'] ? 1 : 0) : 1;
            $displayOrder = isset($row['display_order']) ? (int) $row['display_order'] : 0;
            $catId = (int) ($catCache[$catName] ?? 0);

            $insComp->bind_param(
                'iisssssisssdiis',
                $subjectId,
                $targetConfigId,
                $academicYear,
                $semester,
                $course,
                $yearLevel,
                $section,
                $catId,
                $name,
                $code,
                $type,
                $weight,
                $isActive,
                $displayOrder,
                $teacherName
            );
            $insComp->execute();
            $inserted++;
        }
        if ($inserted <= 0) throw new Exception('no_components');

        $findCat->close();
        $insCat->close();
        $insComp->close();

        $conn->commit();
        return [true, 'ok'];
    } catch (Throwable $e) {
        $conn->rollback();
        return [false, 'apply_failed'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    if (!csrf_validate($csrf)) {
        $_SESSION['flash_message'] = 'Security check failed (CSRF). Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: teacher-grading-config.php?class_record_id=' . $classRecordId . '&term=' . urlencode($term));
        exit;
    }

    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

    if ($action === 'ai_chat_reset') {
        unset($_SESSION[$aiChatHistorySessionKey], $_SESSION[$aiDraftSessionKey], $_SESSION[$aiFeedbackSessionKey]);
        $_SESSION['flash_message'] = 'AI chat reset.';
        $_SESSION['flash_type'] = 'info';
        header('Location: teacher-grading-config.php?class_record_id=' . $classRecordId . '&term=' . urlencode($term));
        exit;
    }

    if ($action === 'ai_chat_send') {
        $teacherMessage = trim((string) ($_POST['ai_chat_message'] ?? ''));
        if ($teacherMessage === '') {
            $_SESSION['flash_message'] = 'Type a message before sending.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: teacher-grading-config.php?class_record_id=' . $classRecordId . '&term=' . urlencode($term));
            exit;
        }
        if (strlen($teacherMessage) > 5000) {
            $teacherMessage = substr($teacherMessage, 0, 5000);
        }

        $history = isset($_SESSION[$aiChatHistorySessionKey]) && is_array($_SESSION[$aiChatHistorySessionKey])
            ? $_SESSION[$aiChatHistorySessionKey]
            : [];
        if (count($history) > 40) $history = array_slice($history, -40);

        $context = [
            'subject_code' => (string) ($class['subject_code'] ?? ''),
            'subject_name' => (string) ($class['subject_name'] ?? ''),
            'section' => $section,
            'academic_year' => $academicYear,
            'semester' => $semester,
            'year_level' => $yearLevel,
            'term' => $term,
        ];
        if (function_exists('tgc_ai_validate_teacher_topic_message')) {
            [$okTopic, $topicMessage] = tgc_ai_validate_teacher_topic_message($context, $history, $teacherMessage);
            if (!$okTopic) {
                $_SESSION['flash_message'] = is_string($topicMessage) && $topicMessage !== ''
                    ? $topicMessage
                    : 'Message is outside allowed AI topic scope.';
                $_SESSION['flash_type'] = 'warning';
                header('Location: teacher-grading-config.php?class_record_id=' . $classRecordId . '&term=' . urlencode($term));
                exit;
            }
        }

        $charCount = ai_chat_credit_chars_len($teacherMessage);
        $chatCost = ai_chat_credit_cost_for_chars($charCount);
        [$okConsume, $consumeMsg] = ai_chat_credit_try_consume($conn, $teacherId, $chatCost);
        if (!$okConsume) {
            $_SESSION['flash_message'] = is_string($consumeMsg) ? $consumeMsg : 'Not enough AI credits.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: teacher-grading-config.php?class_record_id=' . $classRecordId . '&term=' . urlencode($term));
            exit;
        }

        $history[] = [
            'role' => 'teacher',
            'content' => $teacherMessage,
            'at' => date('Y-m-d H:i:s'),
        ];
        if (count($history) > 40) $history = array_slice($history, -40);

        [$okAi, $aiDataOrMsg] = tgc_ai_chat_collaborate($context, $history, $teacherMessage, $totalWeight, allowed_component_types());
        if (!$okAi) {
            ai_chat_credit_refund($conn, $teacherId, $chatCost);
            $history[] = [
                'role' => 'assistant',
                'content' => function_exists('tgc_ai_with_ryhn_intro')
                    ? tgc_ai_with_ryhn_intro('I could not process that right now. Please try again.', $history, true)
                    : 'Hi, I\'m Ryhn. I could not process that right now. Please try again.',
                'at' => date('Y-m-d H:i:s'),
            ];
            if (count($history) > 40) $history = array_slice($history, -40);
            $_SESSION[$aiChatHistorySessionKey] = $history;
            $_SESSION['flash_message'] = (is_string($aiDataOrMsg) ? $aiDataOrMsg : 'AI chat failed.') . ' AI credits were refunded.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: teacher-grading-config.php?class_record_id=' . $classRecordId . '&term=' . urlencode($term));
            exit;
        }

        $aiData = is_array($aiDataOrMsg) ? $aiDataOrMsg : [];
        $assistantMessage = trim((string) ($aiData['assistant_message'] ?? ''));
        if ($assistantMessage === '') $assistantMessage = 'I processed your message.';
        $history[] = [
            'role' => 'assistant',
            'content' => $assistantMessage,
            'at' => date('Y-m-d H:i:s'),
        ];
        if (count($history) > 40) $history = array_slice($history, -40);
        $_SESSION[$aiChatHistorySessionKey] = $history;

        $_SESSION[$aiFeedbackSessionKey] = [
            'ready' => !empty($aiData['ready']),
            'readiness_score' => (int) ($aiData['readiness_score'] ?? 0),
            'summary' => (string) ($aiData['summary'] ?? ''),
            'knowledge_gaps' => is_array($aiData['knowledge_gaps'] ?? null) ? $aiData['knowledge_gaps'] : [],
            'follow_up_questions' => is_array($aiData['follow_up_questions'] ?? null) ? $aiData['follow_up_questions'] : [],
        ];

        if (!empty($aiData['ready'])) {
            $components = is_array($aiData['components'] ?? null) ? $aiData['components'] : [];
            if (count($components) > 0) {
                $_SESSION[$aiDraftSessionKey] = [
                    'build_name' => (string) ($aiData['build_name'] ?? ''),
                    'build_description' => (string) ($aiData['build_description'] ?? ''),
                    'components' => $components,
                    'target_weight' => (float) $totalWeight,
                    'generated_at' => date('Y-m-d H:i:s'),
                ];
            }
        } else {
            unset($_SESSION[$aiDraftSessionKey]);
        }

        $remainingMsg = '';
        [$okChatCredit, $chatCreditOrMsg] = ai_chat_credit_get_user_status($conn, $teacherId);
        if ($okChatCredit && is_array($chatCreditOrMsg)) {
            $remainingMsg = ' Remaining: ' . number_format((float) ($chatCreditOrMsg['remaining'] ?? 0), 2, '.', '') . ' credits.';
        }
        $_SESSION['flash_message'] = 'Message sent. Charged ' . number_format($chatCost, 2, '.', '') . ' AI credits (' . (int) $charCount . ' chars).' . $remainingMsg;
        $_SESSION['flash_type'] = 'success';
        header('Location: teacher-grading-config.php?class_record_id=' . $classRecordId . '&term=' . urlencode($term));
        exit;
    }

    if ($action === 'ai_chat_apply_draft') {
        $replaceExisting = !empty($_POST['replace_existing']);
        $postedSelectedForPreference = section_sync_filter_selected_ids($_POST['apply_section_ids'] ?? [], $syncPeerSections);
        $autoApplyPref = !empty($_POST['sync_auto_apply']);
        section_sync_save_preference(
            $conn,
            $teacherId,
            $subjectId,
            $academicYear,
            $semester,
            $postedSelectedForPreference,
            $autoApplyPref
        );
        $syncTargetIds = section_sync_resolve_target_ids(
            $_POST['apply_section_ids'] ?? [],
            $syncPeerSections,
            $syncPreference,
            true
        );

        $draft = isset($_SESSION[$aiDraftSessionKey]) && is_array($_SESSION[$aiDraftSessionKey]) ? $_SESSION[$aiDraftSessionKey] : null;
        if (!is_array($draft)) {
            $_SESSION['flash_message'] = 'No AI draft available. Continue chatting until AI confirms readiness.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: teacher-grading-config.php?class_record_id=' . $classRecordId . '&term=' . urlencode($term));
            exit;
        }

        $componentsDraft = is_array($draft['components'] ?? null) ? $draft['components'] : [];
        if (count($componentsDraft) === 0) {
            $_SESSION['flash_message'] = 'AI draft has no components to apply.';
            $_SESSION['flash_type'] = 'warning';
            unset($_SESSION[$aiDraftSessionKey]);
            header('Location: teacher-grading-config.php?class_record_id=' . $classRecordId . '&term=' . urlencode($term));
            exit;
        }

        $existingCount = 0;
        $cnt = $conn->prepare("SELECT COUNT(*) AS c FROM grading_components WHERE section_config_id = ?");
        if ($cnt) {
            $cnt->bind_param('i', $configId);
            $cnt->execute();
            $res = $cnt->get_result();
            if ($res && $res->num_rows === 1) $existingCount = (int) ($res->fetch_assoc()['c'] ?? 0);
            $cnt->close();
        }
        if ($existingCount > 0 && !$replaceExisting) {
            $_SESSION['flash_message'] = 'This term already has components. Check "Replace existing components" before applying AI draft.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: teacher-grading-config.php?class_record_id=' . $classRecordId . '&term=' . urlencode($term));
            exit;
        }

        $conn->begin_transaction();
        try {
            if ($replaceExisting) {
                $del = $conn->prepare("DELETE FROM grading_components WHERE section_config_id = ?");
                if ($del) {
                    $del->bind_param('i', $configId);
                    $del->execute();
                    $del->close();
                }
            }

            $findCat = $conn->prepare("SELECT id FROM grading_categories WHERE subject_id = ? AND category_name = ? LIMIT 1");
            $insCat = $conn->prepare("INSERT INTO grading_categories (category_name, subject_id, default_weight, is_active, created_by) VALUES (?, ?, 0.00, 1, ?)");
            $insComp = $conn->prepare(
                "INSERT INTO grading_components
                    (subject_id, section_config_id, academic_year, semester, course, year, section, category_id,
                     component_name, component_code, component_type, weight, is_active, display_order, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)"
            );
            if (!$findCat || !$insCat || !$insComp) throw new Exception('Unable to prepare AI apply statements.');

            $catCache = [];
            $order = 0;
            foreach ($componentsDraft as $row) {
                if (!is_array($row)) continue;
                $catName = trim((string) ($row['category_name'] ?? 'General'));
                if ($catName === '') $catName = 'General';
                if (strlen($catName) > 100) $catName = substr($catName, 0, 100);

                if (!isset($catCache[$catName])) {
                    $catId = 0;
                    $findCat->bind_param('is', $subjectId, $catName);
                    $findCat->execute();
                    $cr = $findCat->get_result();
                    if ($cr && $cr->num_rows === 1) {
                        $catId = (int) ($cr->fetch_assoc()['id'] ?? 0);
                    } else {
                        $insCat->bind_param('sis', $catName, $subjectId, $teacherName);
                        $insCat->execute();
                        $catId = (int) $conn->insert_id;
                    }
                    $catCache[$catName] = $catId;
                }

                $name = trim((string) ($row['component_name'] ?? ''));
                if ($name === '') continue;
                if (strlen($name) > 100) $name = substr($name, 0, 100);

                $code = trim((string) ($row['component_code'] ?? ''));
                if (strlen($code) > 50) $code = substr($code, 0, 50);

                $type = trim((string) ($row['component_type'] ?? 'other'));
                if (!in_array($type, allowed_component_types(), true)) $type = 'other';

                $weight = (float) ($row['weight'] ?? 0);
                if ($weight <= 0) continue;
                if ($weight > 100) $weight = 100.0;
                $displayOrder = (int) ($row['display_order'] ?? $order);

                $catId = (int) ($catCache[$catName] ?? 0);
                $insComp->bind_param(
                    'iisssssisssdis',
                    $subjectId,
                    $configId,
                    $academicYear,
                    $semester,
                    $course,
                    $yearLevel,
                    $section,
                    $catId,
                    $name,
                    $code,
                    $type,
                    $weight,
                    $displayOrder,
                    $teacherName
                );
                $insComp->execute();
                $order++;
            }
            if ($order <= 0) throw new Exception('AI draft had no valid components to apply.');

            $findCat->close();
            $insCat->close();
            $insComp->close();

            $conn->commit();
            $syncCopied = 0;
            $syncSkipped = 0;
            if (count($syncTargetIds) > 0) {
                $targetQ = $conn->prepare(
                    "SELECT cr.id, cr.section, cr.academic_year, cr.semester, cr.year_level
                     FROM teacher_assignments ta
                     JOIN class_records cr ON cr.id = ta.class_record_id
                     WHERE ta.teacher_id = ?
                       AND ta.status = 'active'
                       AND cr.status = 'active'
                       AND cr.subject_id = ?
                       AND cr.academic_year = ?
                       AND cr.semester = ?
                       AND cr.id = ?
                     LIMIT 1"
                );
                if ($targetQ) {
                    foreach ($syncTargetIds as $targetClassId) {
                        $targetClassId = (int) $targetClassId;
                        $targetQ->bind_param('iissi', $teacherId, $subjectId, $academicYear, $semester, $targetClassId);
                        $targetQ->execute();
                        $targetRes = $targetQ->get_result();
                        $targetRow = ($targetRes && $targetRes->num_rows === 1) ? $targetRes->fetch_assoc() : null;
                        if (!is_array($targetRow)) {
                            $syncSkipped++;
                            continue;
                        }

                        $targetSection = trim((string) ($targetRow['section'] ?? ''));
                        $targetAy = trim((string) ($targetRow['academic_year'] ?? $academicYear));
                        $targetSem = trim((string) ($targetRow['semester'] ?? $semester));
                        $targetYear = trim((string) ($targetRow['year_level'] ?? ''));
                        if ($targetYear === '') $targetYear = 'N/A';

                        [$okApplyPeer, $applyPeerMessage] = tgc_apply_component_rows_to_section(
                            $conn,
                            $componentsDraft,
                            $subjectId,
                            $course,
                            $targetYear,
                            $targetSection,
                            $targetAy,
                            $targetSem,
                            $term,
                            $teacherName,
                            $replaceExisting
                        );
                        if ($okApplyPeer) $syncCopied++;
                        else $syncSkipped++;
                    }
                    $targetQ->close();
                } else {
                    $syncSkipped += count($syncTargetIds);
                }
            }

            $flashMessage = 'AI draft applied to ' . $termLabel . '.';
            if ($syncCopied > 0) $flashMessage .= ' Applied to ' . $syncCopied . ' other section(s).';
            if ($syncSkipped > 0) $flashMessage .= ' Skipped ' . $syncSkipped . ' section(s).';
            $_SESSION['flash_message'] = $flashMessage;
            $_SESSION['flash_type'] = 'success';
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('[teacher-grading-config] ai_chat_apply_draft failed: ' . $e->getMessage());
            $_SESSION['flash_message'] = 'Unable to apply AI draft.';
            $_SESSION['flash_type'] = 'danger';
        }

        header('Location: teacher-grading-config.php?class_record_id=' . $classRecordId . '&term=' . urlencode($term));
        exit;
    }

    if ($action === 'apply_build') {
        $buildId = isset($_POST['build_id']) ? (int) $_POST['build_id'] : 0;
        $replaceExisting = !empty($_POST['replace_existing']);
        $postedSelectedForPreference = section_sync_filter_selected_ids($_POST['apply_section_ids'] ?? [], $syncPeerSections);
        $autoApplyPref = !empty($_POST['sync_auto_apply']);
        section_sync_save_preference(
            $conn,
            $teacherId,
            $subjectId,
            $academicYear,
            $semester,
            $postedSelectedForPreference,
            $autoApplyPref
        );
        $syncTargetIds = section_sync_resolve_target_ids(
            $_POST['apply_section_ids'] ?? [],
            $syncPeerSections,
            $syncPreference,
            true
        );

        if ($buildId <= 0) {
            $_SESSION['flash_message'] = 'Select a build to apply.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: teacher-grading-config.php?class_record_id=' . $classRecordId . '&term=' . urlencode($term));
            exit;
        }

        // Verify build ownership (and active).
        $chk = $conn->prepare("SELECT id FROM class_record_builds WHERE id = ? AND teacher_id = ? AND status = 'active' LIMIT 1");
        $okBuild = false;
        if ($chk) {
            $chk->bind_param('ii', $buildId, $teacherId);
            $chk->execute();
            $r = $chk->get_result();
            $okBuild = ($r && $r->num_rows === 1);
            $chk->close();
        }
        if (!$okBuild) {
            $_SESSION['flash_message'] = 'Build not found (or inactive).';
            $_SESSION['flash_type'] = 'danger';
            header('Location: teacher-grading-config.php?class_record_id=' . $classRecordId . '&term=' . urlencode($term));
            exit;
        }

        $existingCount = 0;
        $cnt = $conn->prepare("SELECT COUNT(*) AS c FROM grading_components WHERE section_config_id = ?");
        if ($cnt) {
            $cnt->bind_param('i', $configId);
            $cnt->execute();
            $res = $cnt->get_result();
            if ($res && $res->num_rows === 1) $existingCount = (int) ($res->fetch_assoc()['c'] ?? 0);
            $cnt->close();
        }

        if ($existingCount > 0 && !$replaceExisting) {
            $_SESSION['flash_message'] = 'This term already has components. Check "Replace existing" to apply the build.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: teacher-grading-config.php?class_record_id=' . $classRecordId . '&term=' . urlencode($term));
            exit;
        }

        // Load build definition for this term.
        $rows = [];
        $q = $conn->prepare(
            "SELECT p.id AS parameter_id, p.name AS parameter_name, p.weight AS parameter_weight, p.display_order AS parameter_order,
                    c.name AS component_name, c.code AS component_code, c.component_type, c.weight AS component_weight, c.display_order AS component_order
             FROM class_record_build_parameters p
             LEFT JOIN class_record_build_components c ON c.parameter_id = p.id
             WHERE p.build_id = ? AND p.term = ?
             ORDER BY p.display_order ASC, p.id ASC, c.display_order ASC, c.id ASC"
        );
        if ($q) {
            $q->bind_param('is', $buildId, $term);
            $q->execute();
            $res = $q->get_result();
            while ($res && ($r = $res->fetch_assoc())) $rows[] = $r;
            $q->close();
        }

        $params = []; // name => ['weight'=>..., 'order'=>..., 'components'=>[]]
        foreach ($rows as $r) {
            $pName = trim((string) ($r['parameter_name'] ?? ''));
            if ($pName === '') continue;
            if (!isset($params[$pName])) {
                $params[$pName] = [
                    'weight' => (float) ($r['parameter_weight'] ?? 0),
                    'order' => (int) ($r['parameter_order'] ?? 0),
                    'components' => [],
                ];
            }
            $cName = trim((string) ($r['component_name'] ?? ''));
            if ($cName === '') continue;
            $params[$pName]['components'][] = [
                'name' => $cName,
                'code' => trim((string) ($r['component_code'] ?? '')),
                'type' => (string) ($r['component_type'] ?? 'other'),
                'weight' => (float) ($r['component_weight'] ?? 0),
                'order' => (int) ($r['component_order'] ?? 0),
            ];
        }

        $total = 0.0;
        foreach ($params as $p) {
            foreach ($p['components'] as $c) $total += (float) ($c['weight'] ?? 0);
        }
        if (count($params) === 0 || $total <= 0.0) {
            $_SESSION['flash_message'] = 'This build has no components for ' . $termLabel . '.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: teacher-grading-config.php?class_record_id=' . $classRecordId . '&term=' . urlencode($term));
            exit;
        }
        if ($total > ($totalWeight + 0.0001)) {
            $_SESSION['flash_message'] = 'Build weights exceed the target total (' . rtrim(rtrim(number_format($totalWeight, 2, '.', ''), '0'), '.') . ').';
            $_SESSION['flash_type'] = 'danger';
            header('Location: teacher-grading-config.php?class_record_id=' . $classRecordId . '&term=' . urlencode($term));
            exit;
        }

        $conn->begin_transaction();
        try {
            // Replace components for this section_config_id (term is implied by config).
            if ($replaceExisting) {
                $del = $conn->prepare("DELETE FROM grading_components WHERE section_config_id = ?");
                if ($del) {
                    $del->bind_param('i', $configId);
                    $del->execute();
                    $del->close();
                }
            }

            $findCat = $conn->prepare("SELECT id FROM grading_categories WHERE subject_id = ? AND category_name = ? LIMIT 1");
            $insCat = $conn->prepare("INSERT INTO grading_categories (category_name, subject_id, default_weight, is_active, created_by) VALUES (?, ?, ?, 1, ?)");

            $insComp = $conn->prepare(
                "INSERT INTO grading_components
                    (subject_id, section_config_id, academic_year, semester, course, year, section, category_id,
                     component_name, component_code, component_type, weight, is_active, display_order, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)"
            );
            if (!$findCat || !$insCat || !$insComp) throw new Exception('Prepare failed.');

            // Apply in parameter order.
            uasort($params, function ($a, $b) {
                return (int) ($a['order'] ?? 0) <=> (int) ($b['order'] ?? 0);
            });

            foreach ($params as $pName => $p) {
                $catId = 0;
                $findCat->bind_param('is', $subjectId, $pName);
                $findCat->execute();
                $rr = $findCat->get_result();
                if ($rr && $rr->num_rows === 1) {
                    $catId = (int) ($rr->fetch_assoc()['id'] ?? 0);
                } else {
                    $w = (float) ($p['weight'] ?? 0);
                    $insCat->bind_param('sids', $pName, $subjectId, $w, $teacherName);
                    $insCat->execute();
                    $catId = (int) $conn->insert_id;
                }

                foreach ($p['components'] as $c) {
                    $cName = (string) ($c['name'] ?? '');
                    if ($cName === '') continue;
                    $cCode = (string) ($c['code'] ?? '');
                    $cType = (string) ($c['type'] ?? 'other');
                    if (!in_array($cType, allowed_component_types(), true)) $cType = 'other';
                    $cWeight = (float) ($c['weight'] ?? 0);
                    $cOrder = (int) ($c['order'] ?? 0);

                    $insComp->bind_param(
                        'iisssssisssdis',
                        $subjectId,
                        $configId,
                        $academicYear,
                        $semester,
                        $course,
                        $yearLevel,
                        $section,
                        $catId,
                        $cName,
                        $cCode,
                        $cType,
                        $cWeight,
                        $cOrder,
                        $teacherName
                    );
                    $insComp->execute();
                }
            }

            $findCat->close();
            $insCat->close();
            $insComp->close();

            $conn->commit();
            $componentRows = [];
            foreach ($params as $pName => $p) {
                foreach (($p['components'] ?? []) as $c) {
                    $componentRows[] = [
                        'category_name' => $pName,
                        'component_name' => (string) ($c['name'] ?? ''),
                        'component_code' => (string) ($c['code'] ?? ''),
                        'component_type' => (string) ($c['type'] ?? 'other'),
                        'weight' => (float) ($c['weight'] ?? 0),
                        'is_active' => 1,
                        'display_order' => (int) ($c['order'] ?? 0),
                    ];
                }
            }

            $syncCopied = 0;
            $syncSkipped = 0;
            if (count($syncTargetIds) > 0 && count($componentRows) > 0) {
                $targetQ = $conn->prepare(
                    "SELECT cr.id, cr.section, cr.academic_year, cr.semester, cr.year_level
                     FROM teacher_assignments ta
                     JOIN class_records cr ON cr.id = ta.class_record_id
                     WHERE ta.teacher_id = ?
                       AND ta.status = 'active'
                       AND cr.status = 'active'
                       AND cr.subject_id = ?
                       AND cr.academic_year = ?
                       AND cr.semester = ?
                       AND cr.id = ?
                     LIMIT 1"
                );
                if ($targetQ) {
                    foreach ($syncTargetIds as $targetClassId) {
                        $targetClassId = (int) $targetClassId;
                        $targetQ->bind_param('iissi', $teacherId, $subjectId, $academicYear, $semester, $targetClassId);
                        $targetQ->execute();
                        $targetRes = $targetQ->get_result();
                        $targetRow = ($targetRes && $targetRes->num_rows === 1) ? $targetRes->fetch_assoc() : null;
                        if (!is_array($targetRow)) {
                            $syncSkipped++;
                            continue;
                        }

                        $targetSection = trim((string) ($targetRow['section'] ?? ''));
                        $targetAy = trim((string) ($targetRow['academic_year'] ?? $academicYear));
                        $targetSem = trim((string) ($targetRow['semester'] ?? $semester));
                        $targetYear = trim((string) ($targetRow['year_level'] ?? ''));
                        if ($targetYear === '') $targetYear = 'N/A';

                        [$okApplyPeer, $applyPeerMessage] = tgc_apply_component_rows_to_section(
                            $conn,
                            $componentRows,
                            $subjectId,
                            $course,
                            $targetYear,
                            $targetSection,
                            $targetAy,
                            $targetSem,
                            $term,
                            $teacherName,
                            $replaceExisting
                        );
                        if ($okApplyPeer) $syncCopied++;
                        else $syncSkipped++;
                    }
                    $targetQ->close();
                } else {
                    $syncSkipped += count($syncTargetIds);
                }
            }

            $flashMessage = 'Build applied for ' . $termLabel . '.';
            if ($syncCopied > 0) $flashMessage .= ' Applied to ' . $syncCopied . ' other section(s).';
            if ($syncSkipped > 0) $flashMessage .= ' Skipped ' . $syncSkipped . ' section(s).';
            $_SESSION['flash_message'] = $flashMessage;
            $_SESSION['flash_type'] = 'success';

            $evtDate = date('Y-m-d');
            $evtTitle = 'Build applied: ' . $termLabel;
            $evtId = teacher_activity_create_event(
                $conn,
                $teacherId,
                $classRecordId,
                'grading_build_applied',
                $evtDate,
                $evtTitle,
                [
                    'build_id' => (int) $buildId,
                    'term' => (string) $term,
                    'replace_existing' => $replaceExisting ? 1 : 0,
                    'applied_to_peer_sections' => (int) $syncCopied,
                ]
            );
            if ($evtId > 0 && function_exists('teacher_activity_queue_add')) {
                teacher_activity_queue_add($evtId, $evtTitle, $evtDate);
            }
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('[teacher-grading-config] apply_build failed: ' . $e->getMessage());
            $_SESSION['flash_message'] = 'Apply build failed.';
            $_SESSION['flash_type'] = 'danger';
        }

        header('Location: teacher-grading-config.php?class_record_id=' . $classRecordId . '&term=' . urlencode($term));
        exit;
    }

    if ($action === 'save_build_term') {
        $targetBuildId = isset($_POST['target_build_id']) ? (int) $_POST['target_build_id'] : 0;
        $buildName = trim((string) ($_POST['build_name'] ?? ''));
        $buildDesc = trim((string) ($_POST['build_description'] ?? ''));

        // Read current config into groups by category.
        $cfgRows = [];
        $gq = $conn->prepare(
            "SELECT c.id AS category_id, c.category_name,
                    gc.component_name, gc.component_code, gc.component_type, gc.weight, gc.display_order
             FROM grading_components gc
             LEFT JOIN grading_categories c ON c.id = gc.category_id
             WHERE gc.section_config_id = ? AND gc.is_active = 1
             ORDER BY gc.display_order ASC, gc.id ASC"
        );
        if ($gq) {
            $gq->bind_param('i', $configId);
            $gq->execute();
            $res = $gq->get_result();
            while ($res && ($r = $res->fetch_assoc())) $cfgRows[] = $r;
            $gq->close();
        }

        if (count($cfgRows) === 0) {
            $_SESSION['flash_message'] = 'No active components to save. Add components first.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: teacher-grading-config.php?class_record_id=' . $classRecordId . '&term=' . urlencode($term));
            exit;
        }

        if ($targetBuildId <= 0) {
            if ($buildName === '') {
                $_SESSION['flash_message'] = 'Build name is required.';
                $_SESSION['flash_type'] = 'warning';
                header('Location: teacher-grading-config.php?class_record_id=' . $classRecordId . '&term=' . urlencode($term));
                exit;
            }
        } else {
            // Verify ownership for updates.
            $chk = $conn->prepare("SELECT id FROM class_record_builds WHERE id = ? AND teacher_id = ? LIMIT 1");
            $ok = false;
            if ($chk) {
                $chk->bind_param('ii', $targetBuildId, $teacherId);
                $chk->execute();
                $rr = $chk->get_result();
                $ok = ($rr && $rr->num_rows === 1);
                $chk->close();
            }
            if (!$ok) {
                $_SESSION['flash_message'] = 'Build not found.';
                $_SESSION['flash_type'] = 'danger';
                header('Location: teacher-grading-config.php?class_record_id=' . $classRecordId . '&term=' . urlencode($term));
                exit;
            }
        }

        // Group: category => components.
        $groups = [];
        foreach ($cfgRows as $r) {
            $cat = trim((string) ($r['category_name'] ?? 'Uncategorized'));
            if ($cat === '') $cat = 'Uncategorized';
            if (!isset($groups[$cat])) $groups[$cat] = [];
            $groups[$cat][] = $r;
        }

        $conn->begin_transaction();
        try {
            if ($targetBuildId <= 0) {
                [$okConsume, $consumeMsg] = usage_limit_try_consume_build($conn, $teacherId, 1);
                if (!$okConsume) {
                    $msg = is_string($consumeMsg) ? $consumeMsg : 'Build limit reached.';
                    throw new RuntimeException($msg);
                }

                $ins = $conn->prepare("INSERT INTO class_record_builds (teacher_id, name, description, status) VALUES (?, ?, ?, 'active')");
                if (!$ins) throw new Exception('Unable to create build.');
                $ins->bind_param('iss', $teacherId, $buildName, $buildDesc);
                $ins->execute();
                $targetBuildId = (int) $conn->insert_id;
                $ins->close();
            }

            // Replace this term's definition in the build.
            $del = $conn->prepare("DELETE FROM class_record_build_parameters WHERE build_id = ? AND term = ?");
            if ($del) {
                $del->bind_param('is', $targetBuildId, $term);
                $del->execute();
                $del->close();
            }

            $insP = $conn->prepare("INSERT INTO class_record_build_parameters (build_id, term, name, weight, display_order) VALUES (?, ?, ?, ?, ?)");
            $insC = $conn->prepare("INSERT INTO class_record_build_components (parameter_id, name, code, component_type, weight, display_order) VALUES (?, ?, ?, ?, ?, ?)");
            if (!$insP || !$insC) throw new Exception('Prepare failed.');

            $pOrder = 0;
            foreach ($groups as $cat => $comps) {
                $pWeight = 0.0;
                foreach ($comps as $c) $pWeight += (float) ($c['weight'] ?? 0);
                $insP->bind_param('issdi', $targetBuildId, $term, $cat, $pWeight, $pOrder);
                $insP->execute();
                $paramId = (int) $conn->insert_id;

                foreach ($comps as $c) {
                    $cName = trim((string) ($c['component_name'] ?? ''));
                    if ($cName === '') continue;
                    $cCode = trim((string) ($c['component_code'] ?? ''));
                    $cType = (string) ($c['component_type'] ?? 'other');
                    if (!in_array($cType, allowed_component_types(), true)) $cType = 'other';
                    $cWeight = (float) ($c['weight'] ?? 0);
                    $cOrder = (int) ($c['display_order'] ?? 0);
                    $insC->bind_param('isssdi', $paramId, $cName, $cCode, $cType, $cWeight, $cOrder);
                    $insC->execute();
                }

                $pOrder++;
            }

            $insP->close();
            $insC->close();

            $conn->commit();
            $_SESSION['flash_message'] = 'Saved ' . $termLabel . ' build.';
            $_SESSION['flash_type'] = 'success';
        } catch (Throwable $e) {
            $conn->rollback();
            $message = trim((string) $e->getMessage());
            if (stripos($message, 'limit') !== false) {
                $_SESSION['flash_message'] = $message;
                $_SESSION['flash_type'] = 'warning';
            } else {
                error_log('[teacher-grading-config] save_build_term failed: ' . $e->getMessage());
                $_SESSION['flash_message'] = 'Save build failed.';
                $_SESSION['flash_type'] = 'danger';
            }
        }

        header('Location: teacher-grading-config.php?class_record_id=' . $classRecordId . '&term=' . urlencode($term));
        exit;
    }

    if ($action === 'copy_term_to') {
        $targetTerm = isset($_POST['target_term']) ? strtolower(trim((string) $_POST['target_term'])) : '';
        if (!in_array($targetTerm, ['midterm', 'final'], true)) $targetTerm = ($term === 'midterm') ? 'final' : 'midterm';
        if ($targetTerm === $term) $targetTerm = ($term === 'midterm') ? 'final' : 'midterm';

        $replaceExisting = !empty($_POST['replace_existing']);

        // Ensure target section_grading_config exists.
        $targetConfigId = null;
        $cfg2 = $conn->prepare(
            "SELECT id, total_weight
             FROM section_grading_configs
             WHERE subject_id = ? AND course = ? AND year = ? AND section = ? AND academic_year = ? AND semester = ? AND term = ?
             LIMIT 1"
        );
        if ($cfg2) {
            $cfg2->bind_param('issssss', $subjectId, $course, $yearLevel, $section, $academicYear, $semester, $targetTerm);
            $cfg2->execute();
            $r = $cfg2->get_result();
            if ($r && $r->num_rows === 1) {
                $row = $r->fetch_assoc();
                $targetConfigId = (int) ($row['id'] ?? 0);
            }
            $cfg2->close();
        }

        if (!$targetConfigId) {
            $insCfg2 = $conn->prepare(
                "INSERT INTO section_grading_configs (subject_id, course, year, section, academic_year, semester, term, total_weight, is_active, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 100.00, 1, ?)"
            );
            if ($insCfg2) {
                $insCfg2->bind_param('isssssss', $subjectId, $course, $yearLevel, $section, $academicYear, $semester, $targetTerm, $teacherName);
                $insCfg2->execute();
                $targetConfigId = (int) $conn->insert_id;
                $insCfg2->close();
            }
        }

        if ($targetConfigId <= 0) {
            $_SESSION['flash_message'] = 'Unable to prepare target term config.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: teacher-grading-config.php?class_record_id=' . $classRecordId . '&term=' . urlencode($term));
            exit;
        }

        // If target has components, require replace flag.
        $existingCount = 0;
        $cnt = $conn->prepare("SELECT COUNT(*) AS c FROM grading_components WHERE section_config_id = ?");
        if ($cnt) {
            $cnt->bind_param('i', $targetConfigId);
            $cnt->execute();
            $res = $cnt->get_result();
            if ($res && $res->num_rows === 1) $existingCount = (int) ($res->fetch_assoc()['c'] ?? 0);
            $cnt->close();
        }
        if ($existingCount > 0 && !$replaceExisting) {
            $_SESSION['flash_message'] = 'Target term already has components. Check "Replace existing" to copy.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: teacher-grading-config.php?class_record_id=' . $classRecordId . '&term=' . urlencode($term));
            exit;
        }

        // Load source components (copy all, including disabled).
        $src = [];
        $q = $conn->prepare(
            "SELECT subject_id, academic_year, semester, course, year, section, category_id,
                    component_name, component_code, component_type, weight, is_active, display_order, created_by
             FROM grading_components
             WHERE section_config_id = ?
             ORDER BY display_order ASC, id ASC"
        );
        if ($q) {
            $q->bind_param('i', $configId);
            $q->execute();
            $res = $q->get_result();
            while ($res && ($r = $res->fetch_assoc())) $src[] = $r;
            $q->close();
        }

        if (count($src) === 0) {
            $_SESSION['flash_message'] = 'No components to copy from this term.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: teacher-grading-config.php?class_record_id=' . $classRecordId . '&term=' . urlencode($term));
            exit;
        }

        $conn->begin_transaction();
        try {
            if ($replaceExisting) {
                $del = $conn->prepare("DELETE FROM grading_components WHERE section_config_id = ?");
                if ($del) {
                    $del->bind_param('i', $targetConfigId);
                    $del->execute();
                    $del->close();
                }
            }

            $ins = $conn->prepare(
                "INSERT INTO grading_components
                    (subject_id, section_config_id, academic_year, semester, course, year, section, category_id,
                     component_name, component_code, component_type, weight, is_active, display_order, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            if (!$ins) throw new Exception('Prepare failed.');

            foreach ($src as $r) {
                $sid = (int) ($r['subject_id'] ?? 0);
                $ay = (string) ($r['academic_year'] ?? $academicYear);
                $sem = (string) ($r['semester'] ?? $semester);
                $crs = (string) ($r['course'] ?? $course);
                $yr = (string) ($r['year'] ?? $yearLevel);
                $sec = (string) ($r['section'] ?? $section);
                $cat = isset($r['category_id']) ? (int) $r['category_id'] : 0;
                $name = (string) ($r['component_name'] ?? '');
                $code = (string) ($r['component_code'] ?? '');
                $type = (string) ($r['component_type'] ?? 'other');
                $w = (float) ($r['weight'] ?? 0);
                $active = isset($r['is_active']) ? (int) $r['is_active'] : 1;
                $order = isset($r['display_order']) ? (int) $r['display_order'] : 0;
                $by = (string) ($r['created_by'] ?? $teacherName);

                $ins->bind_param('iisssssisssdiis', $sid, $targetConfigId, $ay, $sem, $crs, $yr, $sec, $cat, $name, $code, $type, $w, $active, $order, $by);
                $ins->execute();
            }

            $ins->close();
            $conn->commit();

            $targetLabel = $targetTerm === 'final' ? 'Final Term' : 'Midterm';
            $_SESSION['flash_message'] = 'Copied ' . $termLabel . ' to ' . $targetLabel . '.';
            $_SESSION['flash_type'] = 'success';
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('[teacher-grading-config] copy_term_to failed: ' . $e->getMessage());
            $_SESSION['flash_message'] = 'Copy failed.';
            $_SESSION['flash_type'] = 'danger';
        }

        header('Location: teacher-grading-config.php?class_record_id=' . $classRecordId . '&term=' . urlencode($targetTerm));
        exit;
    }

    if ($action === 'copy_from_class') {
        $sourceClassRecordId = isset($_POST['source_class_record_id']) ? (int) $_POST['source_class_record_id'] : 0;
        $sourceTerm = isset($_POST['source_term']) ? strtolower(trim((string) $_POST['source_term'])) : '';
        if (!in_array($sourceTerm, ['midterm', 'final'], true)) $sourceTerm = $term;
        $replaceExisting = !empty($_POST['replace_existing']);

        if ($sourceClassRecordId <= 0) {
            $_SESSION['flash_message'] = 'Select a source class to copy from.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: teacher-grading-config.php?class_record_id=' . $classRecordId . '&term=' . urlencode($term));
            exit;
        }
        if ($sourceClassRecordId === $classRecordId) {
            $_SESSION['flash_message'] = 'Source class must be different from the current class.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: teacher-grading-config.php?class_record_id=' . $classRecordId . '&term=' . urlencode($term));
            exit;
        }

        // Verify the teacher is assigned to the source class record too.
        $source = null;
        $srcStmt = $conn->prepare(
            "SELECT cr.id AS class_record_id,
                    cr.subject_id, cr.section, cr.academic_year, cr.semester, cr.year_level,
                    s.course, s.subject_code, s.subject_name
             FROM teacher_assignments ta
             JOIN class_records cr ON cr.id = ta.class_record_id
             JOIN subjects s ON s.id = cr.subject_id
             WHERE ta.teacher_id = ?
               AND ta.status = 'active'
               AND cr.status = 'active'
               AND cr.id = ?
             LIMIT 1"
        );
        if ($srcStmt) {
            $srcStmt->bind_param('ii', $teacherId, $sourceClassRecordId);
            $srcStmt->execute();
            $r = $srcStmt->get_result();
            if ($r && $r->num_rows === 1) $source = $r->fetch_assoc();
            $srcStmt->close();
        }
        if (!$source) {
            $_SESSION['flash_message'] = 'Forbidden: you are not assigned to the selected source class.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: teacher-grading-config.php?class_record_id=' . $classRecordId . '&term=' . urlencode($term));
            exit;
        }

        $srcSubjectId = (int) ($source['subject_id'] ?? 0);
        $srcSection = trim((string) ($source['section'] ?? ''));
        $srcAcademicYear = trim((string) ($source['academic_year'] ?? ''));
        $srcSemester = trim((string) ($source['semester'] ?? ''));
        $srcCourse = trim((string) ($source['course'] ?? ''));
        if ($srcCourse === '') $srcCourse = 'N/A';
        $srcYearLevel = trim((string) ($source['year_level'] ?? ''));
        if ($srcYearLevel === '') $srcYearLevel = 'N/A';

        // Resolve source term config id.
        $srcConfigId = 0;
        $srcCfg = $conn->prepare(
            "SELECT id
             FROM section_grading_configs
             WHERE subject_id = ? AND course = ? AND year = ? AND section = ? AND academic_year = ? AND semester = ? AND term = ?
             LIMIT 1"
        );
        if ($srcCfg) {
            $srcCfg->bind_param('issssss', $srcSubjectId, $srcCourse, $srcYearLevel, $srcSection, $srcAcademicYear, $srcSemester, $sourceTerm);
            $srcCfg->execute();
            $rr = $srcCfg->get_result();
            if ($rr && $rr->num_rows === 1) $srcConfigId = (int) ($rr->fetch_assoc()['id'] ?? 0);
            $srcCfg->close();
        }

        if ($srcConfigId <= 0) {
            $_SESSION['flash_message'] = 'The source class has no components configured for the selected term.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: teacher-grading-config.php?class_record_id=' . $classRecordId . '&term=' . urlencode($term));
            exit;
        }

        // If target has components, require replace flag.
        $existingCount = 0;
        $cnt = $conn->prepare("SELECT COUNT(*) AS c FROM grading_components WHERE section_config_id = ?");
        if ($cnt) {
            $cnt->bind_param('i', $configId);
            $cnt->execute();
            $res = $cnt->get_result();
            if ($res && $res->num_rows === 1) $existingCount = (int) ($res->fetch_assoc()['c'] ?? 0);
            $cnt->close();
        }
        if ($existingCount > 0 && !$replaceExisting) {
            $_SESSION['flash_message'] = 'This term already has components. Check \"Replace existing\" to copy.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: teacher-grading-config.php?class_record_id=' . $classRecordId . '&term=' . urlencode($term));
            exit;
        }

        // Load source components + category names.
        $srcRows = [];
        $q = $conn->prepare(
            "SELECT gc.component_name, gc.component_code, gc.component_type, gc.weight, gc.is_active, gc.display_order,
                    c.category_name
             FROM grading_components gc
             LEFT JOIN grading_categories c ON c.id = gc.category_id
             WHERE gc.section_config_id = ?
             ORDER BY gc.display_order ASC, gc.id ASC"
        );
        if ($q) {
            $q->bind_param('i', $srcConfigId);
            $q->execute();
            $res = $q->get_result();
            while ($res && ($r = $res->fetch_assoc())) $srcRows[] = $r;
            $q->close();
        }

        if (count($srcRows) === 0) {
            $_SESSION['flash_message'] = 'No components found to copy from the source class.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: teacher-grading-config.php?class_record_id=' . $classRecordId . '&term=' . urlencode($term));
            exit;
        }

        $conn->begin_transaction();
        try {
            if ($replaceExisting) {
                $del = $conn->prepare("DELETE FROM grading_components WHERE section_config_id = ?");
                if ($del) {
                    $del->bind_param('i', $configId);
                    $del->execute();
                    $del->close();
                }
            }

            // Map category_name -> category_id for this subject (create if missing).
            $findCat = $conn->prepare("SELECT id FROM grading_categories WHERE subject_id = ? AND category_name = ? LIMIT 1");
            $insCat = $conn->prepare("INSERT INTO grading_categories (category_name, subject_id, default_weight, is_active, created_by) VALUES (?, ?, 0.00, 1, ?)");

            $ins = $conn->prepare(
                "INSERT INTO grading_components
                    (subject_id, section_config_id, academic_year, semester, course, year, section, category_id,
                     component_name, component_code, component_type, weight, is_active, display_order, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            if (!$findCat || !$insCat || !$ins) throw new Exception('Prepare failed.');

            $catCache = []; // name => id
            foreach ($srcRows as $r) {
                $catName = trim((string) ($r['category_name'] ?? ''));
                if ($catName === '') $catName = 'General';

                if (!isset($catCache[$catName])) {
                    $catId = 0;
                    $findCat->bind_param('is', $subjectId, $catName);
                    $findCat->execute();
                    $cr = $findCat->get_result();
                    if ($cr && $cr->num_rows === 1) {
                        $catId = (int) ($cr->fetch_assoc()['id'] ?? 0);
                    } else {
                        $insCat->bind_param('sis', $catName, $subjectId, $teacherName);
                        $insCat->execute();
                        $catId = (int) $conn->insert_id;
                    }
                    $catCache[$catName] = $catId;
                }

                $catId = (int) ($catCache[$catName] ?? 0);
                $name = trim((string) ($r['component_name'] ?? ''));
                if ($name === '') continue;
                $code = trim((string) ($r['component_code'] ?? ''));
                $type = trim((string) ($r['component_type'] ?? 'other'));
                if (!in_array($type, allowed_component_types(), true)) $type = 'other';
                $w = (float) ($r['weight'] ?? 0);
                $active = isset($r['is_active']) ? (int) $r['is_active'] : 1;
                $order = isset($r['display_order']) ? (int) $r['display_order'] : 0;

                $ins->bind_param(
                    'iisssssisssdiis',
                    $subjectId,
                    $configId,
                    $academicYear,
                    $semester,
                    $course,
                    $yearLevel,
                    $section,
                    $catId,
                    $name,
                    $code,
                    $type,
                    $w,
                    $active,
                    $order,
                    $teacherName
                );
                $ins->execute();
            }

            $findCat->close();
            $insCat->close();
            $ins->close();

            $conn->commit();
            $_SESSION['flash_message'] = 'Copied components from ' . (string) ($source['subject_code'] ?? 'source class') . ' into ' . $termLabel . '.';
            $_SESSION['flash_type'] = 'success';
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('[teacher-grading-config] copy_from_class failed: ' . $e->getMessage());
            $_SESSION['flash_message'] = 'Copy from class failed.';
            $_SESSION['flash_type'] = 'danger';
        }

        header('Location: teacher-grading-config.php?class_record_id=' . $classRecordId . '&term=' . urlencode($term));
        exit;
    }

    if ($action === 'add_category') {
        $name = trim((string) ($_POST['category_name'] ?? ''));
        $defWeight = to_decimal((string) ($_POST['default_weight'] ?? ''));
        if ($defWeight === null) $defWeight = 0.0;

        if ($name === '') {
            $_SESSION['flash_message'] = 'Category name is required.';
            $_SESSION['flash_type'] = 'danger';
        } else {
            $ins = $conn->prepare(
                "INSERT INTO grading_categories (category_name, subject_id, default_weight, is_active, created_by)
                 VALUES (?, ?, ?, 1, ?)"
            );
            if ($ins) {
                $ins->bind_param('sids', $name, $subjectId, $defWeight, $teacherName);
                try {
                    $ins->execute();
                    $newId = (int) $conn->insert_id;
                    $_SESSION['flash_message'] = 'Category added.';
                    $_SESSION['flash_type'] = 'success';

                    $evtDate = date('Y-m-d');
                    $evtTitle = 'Grading category added: ' . $name;
                    if (strlen($evtTitle) > 255) $evtTitle = substr($evtTitle, 0, 255);
                    $evtId = teacher_activity_create_event(
                        $conn,
                        $teacherId,
                        $classRecordId,
                        'grading_category_created',
                        $evtDate,
                        $evtTitle,
                        ['grading_category_id' => $newId, 'category_name' => $name]
                    );
                    if ($evtId > 0 && function_exists('teacher_activity_queue_add')) {
                        teacher_activity_queue_add($evtId, $evtTitle, $evtDate);
                    }
                } catch (mysqli_sql_exception $e) {
                    if ((int) $e->getCode() === 1062) {
                        $_SESSION['flash_message'] = 'Category already exists for this subject.';
                        $_SESSION['flash_type'] = 'warning';
                    } else {
                        error_log('[teacher-grading-config] add_category failed: ' . $e->getMessage());
                        $_SESSION['flash_message'] = 'Unable to add category.';
                        $_SESSION['flash_type'] = 'danger';
                    }
                }
                $ins->close();
            } else {
                $_SESSION['flash_message'] = 'Unable to add category.';
                $_SESSION['flash_type'] = 'danger';
            }
        }

        header('Location: teacher-grading-config.php?class_record_id=' . $classRecordId . '&term=' . urlencode($term));
        exit;
    }

    if ($action === 'add_component') {
        $name = trim((string) ($_POST['component_name'] ?? ''));
        $code = trim((string) ($_POST['component_code'] ?? ''));
        $type = trim((string) ($_POST['component_type'] ?? ''));
        $weight = to_decimal((string) ($_POST['weight'] ?? ''));
        $order = isset($_POST['display_order']) ? (int) $_POST['display_order'] : 0;
        $categoryId = isset($_POST['category_id']) ? (int) $_POST['category_id'] : 0;

        if ($name === '' || $weight === null) {
            $_SESSION['flash_message'] = 'Component name and weight are required.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: teacher-grading-config.php?class_record_id=' . $classRecordId . '&term=' . urlencode($term));
            exit;
        }

        if (!in_array($type, allowed_component_types(), true)) {
            $_SESSION['flash_message'] = 'Invalid component type.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: teacher-grading-config.php?class_record_id=' . $classRecordId . '&term=' . urlencode($term));
            exit;
        }

        if ($weight < 0 || $weight > 100) {
            $_SESSION['flash_message'] = 'Weight must be between 0 and 100.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: teacher-grading-config.php?class_record_id=' . $classRecordId . '&term=' . urlencode($term));
            exit;
        }

        // Validate category belongs to subject (optional).
        if ($categoryId > 0) {
            $chk = $conn->prepare("SELECT id FROM grading_categories WHERE id = ? AND subject_id = ? AND is_active = 1 LIMIT 1");
            if ($chk) {
                $chk->bind_param('ii', $categoryId, $subjectId);
                $chk->execute();
                $cr = $chk->get_result();
                if (!$cr || $cr->num_rows !== 1) $categoryId = 0;
                $chk->close();
            } else {
                $categoryId = 0;
            }
        }

        $current = current_active_weight($conn, $configId, 0);
        if (($current + $weight) > ($totalWeight + 0.0001)) {
            $_SESSION['flash_message'] = 'Total active weights would exceed ' . rtrim(rtrim(number_format($totalWeight, 2, '.', ''), '0'), '.') . '.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: teacher-grading-config.php?class_record_id=' . $classRecordId . '&term=' . urlencode($term));
            exit;
        }

        $ins = $conn->prepare(
            "INSERT INTO grading_components
                (subject_id, section_config_id, academic_year, semester, course, year, section, category_id,
                 component_name, component_code, component_type, weight, is_active, display_order, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)"
        );
        if ($ins) {
            $ins->bind_param(
                'iisssssisssdis',
                $subjectId,
                $configId,
                $academicYear,
                $semester,
                $course,
                $yearLevel,
                $section,
                $categoryId,
                $name,
                $code,
                $type,
                $weight,
                $order,
                $teacherName
            );
            try {
                $ins->execute();
                $newId = (int) $conn->insert_id;

                $evtDate = date('Y-m-d');
                $evtTitle = 'Grading component added: ' . $name;
                if (strlen($evtTitle) > 255) $evtTitle = substr($evtTitle, 0, 255);
                $evtId = teacher_activity_create_event(
                    $conn,
                    $teacherId,
                    $classRecordId,
                    'grading_component_created',
                    $evtDate,
                    $evtTitle,
                    [
                        'grading_component_id' => $newId,
                        'component_name' => $name,
                        'component_code' => $code,
                        'component_type' => $type,
                        'weight' => (float) $weight,
                        'category_id' => (int) $categoryId,
                        'term' => (string) $term,
                    ]
                );
                if ($evtId > 0 && function_exists('teacher_activity_queue_add')) {
                    teacher_activity_queue_add($evtId, $evtTitle, $evtDate);
                }

                $syncSelectedIds = section_sync_resolve_target_ids(
                    $_POST['apply_section_ids'] ?? [],
                    $syncPeerSections,
                    $syncPreference,
                    true
                );
                $postedSelectedForPreference = section_sync_filter_selected_ids($_POST['apply_section_ids'] ?? [], $syncPeerSections);
                $autoApplyPref = !empty($_POST['sync_auto_apply']);
                section_sync_save_preference(
                    $conn,
                    $teacherId,
                    $subjectId,
                    $academicYear,
                    $semester,
                    $postedSelectedForPreference,
                    $autoApplyPref
                );
                $syncCopied = 0;
                $syncSkipped = 0;
                if (count($syncSelectedIds) > 0) {
                    $targetQ = $conn->prepare(
                        "SELECT cr.id, cr.section, cr.academic_year, cr.semester, cr.year_level
                         FROM teacher_assignments ta
                         JOIN class_records cr ON cr.id = ta.class_record_id
                         WHERE ta.teacher_id = ?
                           AND ta.status = 'active'
                           AND cr.status = 'active'
                           AND cr.subject_id = ?
                           AND cr.academic_year = ?
                           AND cr.semester = ?
                           AND cr.id = ?
                         LIMIT 1"
                    );
                    $insertPeer = $conn->prepare(
                        "INSERT INTO grading_components
                            (subject_id, section_config_id, academic_year, semester, course, year, section, category_id,
                             component_name, component_code, component_type, weight, is_active, display_order, created_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)"
                    );
                    if ($targetQ && $insertPeer) {
                        foreach ($syncSelectedIds as $targetClassId) {
                            $targetClassId = (int) $targetClassId;
                            $targetQ->bind_param('iissi', $teacherId, $subjectId, $academicYear, $semester, $targetClassId);
                            $targetQ->execute();
                            $targetRes = $targetQ->get_result();
                            $targetRow = ($targetRes && $targetRes->num_rows === 1) ? $targetRes->fetch_assoc() : null;
                            if (!is_array($targetRow)) {
                                $syncSkipped++;
                                continue;
                            }

                            $targetSection = trim((string) ($targetRow['section'] ?? ''));
                            $targetAy = trim((string) ($targetRow['academic_year'] ?? $academicYear));
                            $targetSem = trim((string) ($targetRow['semester'] ?? $semester));
                            $targetYear = trim((string) ($targetRow['year_level'] ?? ''));
                            if ($targetYear === '') $targetYear = 'N/A';

                            [$targetConfigId, $targetTotalWeight] = tgc_ensure_section_config_for_target(
                                $conn,
                                $subjectId,
                                $course,
                                $targetYear,
                                $targetSection,
                                $targetAy,
                                $targetSem,
                                $term,
                                $teacherName
                            );
                            if ($targetConfigId <= 0) {
                                $syncSkipped++;
                                continue;
                            }

                            $targetCurrentActiveWeight = current_active_weight($conn, $targetConfigId, 0);
                            if (($targetCurrentActiveWeight + $weight) > ($targetTotalWeight + 0.0001)) {
                                $syncSkipped++;
                                continue;
                            }

                            $insertPeer->bind_param(
                                'iisssssisssdis',
                                $subjectId,
                                $targetConfigId,
                                $targetAy,
                                $targetSem,
                                $course,
                                $targetYear,
                                $targetSection,
                                $categoryId,
                                $name,
                                $code,
                                $type,
                                $weight,
                                $order,
                                $teacherName
                            );
                            if ($insertPeer->execute()) $syncCopied++;
                            else $syncSkipped++;
                        }
                        $targetQ->close();
                        $insertPeer->close();
                    } else {
                        $syncSkipped += count($syncSelectedIds);
                        if ($targetQ) $targetQ->close();
                        if ($insertPeer) $insertPeer->close();
                    }
                }

                $flashMessage = 'Component added.';
                if ($syncCopied > 0) $flashMessage .= ' Applied to ' . $syncCopied . ' other section(s).';
                if ($syncSkipped > 0) $flashMessage .= ' Skipped ' . $syncSkipped . ' section(s).';
                $_SESSION['flash_message'] = $flashMessage;
                $_SESSION['flash_type'] = 'success';
            } catch (Throwable $e) {
                error_log('[teacher-grading-config] add_component failed: ' . $e->getMessage());
                $_SESSION['flash_message'] = 'Unable to add component.';
                $_SESSION['flash_type'] = 'danger';
            }
            $ins->close();
        } else {
            $_SESSION['flash_message'] = 'Unable to add component.';
            $_SESSION['flash_type'] = 'danger';
        }

        header('Location: teacher-grading-config.php?class_record_id=' . $classRecordId . '&term=' . urlencode($term));
        exit;
    }

    if ($action === 'update_component') {
        $componentId = isset($_POST['component_id']) ? (int) $_POST['component_id'] : 0;
        $name = trim((string) ($_POST['component_name'] ?? ''));
        $code = trim((string) ($_POST['component_code'] ?? ''));
        $type = trim((string) ($_POST['component_type'] ?? ''));
        $weight = to_decimal((string) ($_POST['weight'] ?? ''));
        $order = isset($_POST['display_order']) ? (int) $_POST['display_order'] : 0;
        $isActive = isset($_POST['is_active']) ? (int) $_POST['is_active'] : 0;
        $categoryId = isset($_POST['category_id']) ? (int) $_POST['category_id'] : 0;

        if ($componentId <= 0 || $weight === null || $name === '') {
            $_SESSION['flash_message'] = 'Invalid component update.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: teacher-grading-config.php?class_record_id=' . $classRecordId . '&term=' . urlencode($term));
            exit;
        }

        if (!in_array($type, allowed_component_types(), true)) {
            $_SESSION['flash_message'] = 'Invalid component type.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: teacher-grading-config.php?class_record_id=' . $classRecordId . '&term=' . urlencode($term));
            exit;
        }

        if ($weight < 0 || $weight > 100) {
            $_SESSION['flash_message'] = 'Weight must be between 0 and 100.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: teacher-grading-config.php?class_record_id=' . $classRecordId . '&term=' . urlencode($term));
            exit;
        }

        $isActive = $isActive ? 1 : 0;

        // Ensure this component belongs to the same section_config_id.
        $own = $conn->prepare("SELECT id FROM grading_components WHERE id = ? AND section_config_id = ? LIMIT 1");
        $isOwned = false;
        if ($own) {
            $own->bind_param('ii', $componentId, $configId);
            $own->execute();
            $or = $own->get_result();
            $isOwned = ($or && $or->num_rows === 1);
            $own->close();
        }
        if (!$isOwned) {
            $_SESSION['flash_message'] = 'Invalid component.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: teacher-grading-config.php?class_record_id=' . $classRecordId . '&term=' . urlencode($term));
            exit;
        }

        // Validate category belongs to subject (optional).
        if ($categoryId > 0) {
            $chk = $conn->prepare("SELECT id FROM grading_categories WHERE id = ? AND subject_id = ? AND is_active = 1 LIMIT 1");
            if ($chk) {
                $chk->bind_param('ii', $categoryId, $subjectId);
                $chk->execute();
                $cr = $chk->get_result();
                if (!$cr || $cr->num_rows !== 1) $categoryId = 0;
                $chk->close();
            } else {
                $categoryId = 0;
            }
        }

        $current = current_active_weight($conn, $configId, $componentId);
        $newTotal = $current + ($isActive ? $weight : 0.0);
        if ($newTotal > ($totalWeight + 0.0001)) {
            $_SESSION['flash_message'] = 'Total active weights would exceed ' . rtrim(rtrim(number_format($totalWeight, 2, '.', ''), '0'), '.') . '.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: teacher-grading-config.php?class_record_id=' . $classRecordId . '&term=' . urlencode($term));
            exit;
        }

        $upd = $conn->prepare(
            "UPDATE grading_components
             SET category_id = ?, component_name = ?, component_code = ?, component_type = ?, weight = ?, is_active = ?, display_order = ?
             WHERE id = ? AND section_config_id = ?"
        );
        if ($upd) {
            $upd->bind_param('isssdiiii', $categoryId, $name, $code, $type, $weight, $isActive, $order, $componentId, $configId);
            $ok = $upd->execute();
            $upd->close();
            if ($ok) {
                $syncCopied = 0;
                $syncSkipped = 0;
                $syncTargets = section_sync_resolve_target_ids([], $syncPeerSections, $syncPreference, true);
                if (count($syncTargets) > 0) {
                    $targetQ = $conn->prepare(
                        "SELECT cr.id, cr.section, cr.academic_year, cr.semester, cr.year_level
                         FROM teacher_assignments ta
                         JOIN class_records cr ON cr.id = ta.class_record_id
                         WHERE ta.teacher_id = ?
                           AND ta.status = 'active'
                           AND cr.status = 'active'
                           AND cr.subject_id = ?
                           AND cr.academic_year = ?
                           AND cr.semester = ?
                           AND cr.id = ?
                         LIMIT 1"
                    );
                    $cfgQ = $conn->prepare(
                        "SELECT id
                         FROM section_grading_configs
                         WHERE subject_id = ? AND course = ? AND year = ? AND section = ? AND academic_year = ? AND semester = ? AND term = ?
                         LIMIT 1"
                    );
                    $cfgWeightQ = $conn->prepare("SELECT total_weight FROM section_grading_configs WHERE id = ? LIMIT 1");
                    $byCodeQ = $conn->prepare(
                        "SELECT id, weight FROM grading_components
                         WHERE section_config_id = ? AND component_code = ?
                         ORDER BY is_active DESC, id ASC
                         LIMIT 1"
                    );
                    $byNameTypeQ = $conn->prepare(
                        "SELECT id, weight FROM grading_components
                         WHERE section_config_id = ? AND component_name = ? AND component_type = ?
                         ORDER BY is_active DESC, id ASC
                         LIMIT 1"
                    );
                    $byNameQ = $conn->prepare(
                        "SELECT id, weight FROM grading_components
                         WHERE section_config_id = ? AND component_name = ?
                         ORDER BY is_active DESC, id ASC
                         LIMIT 1"
                    );
                    $updStatusQ = $conn->prepare(
                        "UPDATE grading_components
                         SET is_active = ?
                         WHERE id = ? AND section_config_id = ?"
                    );
                    if ($targetQ && $cfgQ && $cfgWeightQ && $byCodeQ && $byNameTypeQ && $byNameQ && $updStatusQ) {
                        foreach ($syncTargets as $targetClassId) {
                            $targetClassId = (int) $targetClassId;
                            $syncAy = $academicYear;
                            $syncSem = $semester;
                            $targetQ->bind_param('iissi', $teacherId, $subjectId, $syncAy, $syncSem, $targetClassId);
                            $targetQ->execute();
                            $targetRes = $targetQ->get_result();
                            $targetRow = ($targetRes && $targetRes->num_rows === 1) ? $targetRes->fetch_assoc() : null;
                            if (!is_array($targetRow)) {
                                $syncSkipped++;
                                continue;
                            }

                            $targetSection = trim((string) ($targetRow['section'] ?? ''));
                            $targetAy = trim((string) ($targetRow['academic_year'] ?? $academicYear));
                            $targetSem = trim((string) ($targetRow['semester'] ?? $semester));
                            $targetYear = trim((string) ($targetRow['year_level'] ?? ''));
                            if ($targetYear === '') $targetYear = 'N/A';

                            $targetConfigId = 0;
                            $cfgQ->bind_param('issssss', $subjectId, $course, $targetYear, $targetSection, $targetAy, $targetSem, $term);
                            $cfgQ->execute();
                            $cfgRes = $cfgQ->get_result();
                            if ($cfgRes && $cfgRes->num_rows === 1) {
                                $targetConfigId = (int) ($cfgRes->fetch_assoc()['id'] ?? 0);
                            }
                            if ($targetConfigId <= 0) {
                                $syncSkipped++;
                                continue;
                            }

                            $targetComponentId = 0;
                            $targetComponentWeight = 0.0;
                            if ($code !== '') {
                                $byCodeQ->bind_param('is', $targetConfigId, $code);
                                $byCodeQ->execute();
                                $resCode = $byCodeQ->get_result();
                                if ($resCode && $resCode->num_rows === 1) {
                                    $rowCode = $resCode->fetch_assoc();
                                    $targetComponentId = (int) ($rowCode['id'] ?? 0);
                                    $targetComponentWeight = (float) ($rowCode['weight'] ?? 0);
                                }
                            }
                            if ($targetComponentId <= 0) {
                                $byNameTypeQ->bind_param('iss', $targetConfigId, $name, $type);
                                $byNameTypeQ->execute();
                                $resNameType = $byNameTypeQ->get_result();
                                if ($resNameType && $resNameType->num_rows === 1) {
                                    $rowNameType = $resNameType->fetch_assoc();
                                    $targetComponentId = (int) ($rowNameType['id'] ?? 0);
                                    $targetComponentWeight = (float) ($rowNameType['weight'] ?? 0);
                                }
                            }
                            if ($targetComponentId <= 0) {
                                $byNameQ->bind_param('is', $targetConfigId, $name);
                                $byNameQ->execute();
                                $resName = $byNameQ->get_result();
                                if ($resName && $resName->num_rows === 1) {
                                    $rowName = $resName->fetch_assoc();
                                    $targetComponentId = (int) ($rowName['id'] ?? 0);
                                    $targetComponentWeight = (float) ($rowName['weight'] ?? 0);
                                }
                            }
                            if ($targetComponentId <= 0) {
                                $syncSkipped++;
                                continue;
                            }

                            if ($isActive === 1) {
                                $targetTotalWeight = 100.0;
                                $cfgWeightQ->bind_param('i', $targetConfigId);
                                $cfgWeightQ->execute();
                                $cfgWeightRes = $cfgWeightQ->get_result();
                                if ($cfgWeightRes && $cfgWeightRes->num_rows === 1) {
                                    $targetTotalWeight = (float) ($cfgWeightRes->fetch_assoc()['total_weight'] ?? 100.0);
                                }
                                $targetCurrent = current_active_weight($conn, $targetConfigId, $targetComponentId);
                                $targetNewTotal = $targetCurrent + $targetComponentWeight;
                                if ($targetNewTotal > ($targetTotalWeight + 0.0001)) {
                                    $syncSkipped++;
                                    continue;
                                }
                            }

                            $updStatusQ->bind_param('iii', $isActive, $targetComponentId, $targetConfigId);
                            if ($updStatusQ->execute() && (int) $updStatusQ->affected_rows >= 0) $syncCopied++;
                            else $syncSkipped++;
                        }

                        $targetQ->close();
                        $cfgQ->close();
                        $cfgWeightQ->close();
                        $byCodeQ->close();
                        $byNameTypeQ->close();
                        $byNameQ->close();
                        $updStatusQ->close();
                    } else {
                        $syncSkipped += count($syncTargets);
                        if ($targetQ) $targetQ->close();
                        if ($cfgQ) $cfgQ->close();
                        if ($cfgWeightQ) $cfgWeightQ->close();
                        if ($byCodeQ) $byCodeQ->close();
                        if ($byNameTypeQ) $byNameTypeQ->close();
                        if ($byNameQ) $byNameQ->close();
                        if ($updStatusQ) $updStatusQ->close();
                    }
                }

                $flashMessage = 'Component updated.';
                if ($syncCopied > 0) $flashMessage .= ' Status synced to ' . $syncCopied . ' section(s).';
                if ($syncSkipped > 0) $flashMessage .= ' Skipped ' . $syncSkipped . ' section(s).';
                $_SESSION['flash_message'] = $flashMessage;
                $_SESSION['flash_type'] = 'success';
            } else {
                $_SESSION['flash_message'] = 'Update failed.';
                $_SESSION['flash_type'] = 'danger';
            }
        } else {
            $_SESSION['flash_message'] = 'Update failed.';
            $_SESSION['flash_type'] = 'danger';
        }

        header('Location: teacher-grading-config.php?class_record_id=' . $classRecordId . '&term=' . urlencode($term));
        exit;
    }

    if ($action === 'delete_component') {
        $componentId = isset($_POST['component_id']) ? (int) $_POST['component_id'] : 0;
        if ($componentId > 0) {
            $del = $conn->prepare("DELETE FROM grading_components WHERE id = ? AND section_config_id = ? LIMIT 1");
            if ($del) {
                $del->bind_param('ii', $componentId, $configId);
                $del->execute();
                $del->close();
                $_SESSION['flash_message'] = 'Component deleted.';
                $_SESSION['flash_type'] = 'success';
            }
        }
        header('Location: teacher-grading-config.php?class_record_id=' . $classRecordId . '&term=' . urlencode($term));
        exit;
    }
}

// Load categories for this subject (active).
$categories = [];
$catStmt = $conn->prepare("SELECT id, category_name FROM grading_categories WHERE subject_id = ? AND is_active = 1 ORDER BY category_name ASC");
if ($catStmt) {
    $catStmt->bind_param('i', $subjectId);
    $catStmt->execute();
    $catRes = $catStmt->get_result();
    while ($catRes && ($r = $catRes->fetch_assoc())) $categories[] = $r;
    $catStmt->close();
}

// Load components for this config.
$components = [];
$cmp = $conn->prepare(
    "SELECT gc.id, gc.component_name, gc.component_code, gc.component_type, gc.weight, gc.is_active, gc.display_order,
            gc.category_id, c.category_name,
            (SELECT COUNT(*) FROM grading_assessments ga WHERE ga.grading_component_id = gc.id) AS assessments_count
     FROM grading_components gc
     LEFT JOIN grading_categories c ON c.id = gc.category_id
     WHERE gc.section_config_id = ?
     ORDER BY gc.display_order ASC, gc.id ASC"
);
if ($cmp) {
    $cmp->bind_param('i', $configId);
    $cmp->execute();
    $cmpRes = $cmp->get_result();
    while ($cmpRes && ($r = $cmpRes->fetch_assoc())) $components[] = $r;
    $cmp->close();
}

$activeWeight = current_active_weight($conn, $configId, 0);
$pct = ($totalWeight > 0.0) ? min(100.0, max(0.0, ($activeWeight / $totalWeight) * 100.0)) : 0.0;

$buildTemplates = [];
$b = $conn->prepare("SELECT id, name FROM class_record_builds WHERE teacher_id = ? AND status = 'active' ORDER BY name ASC");
if ($b) {
    $b->bind_param('i', $teacherId);
    $b->execute();
    $res = $b->get_result();
    while ($res && ($r = $res->fetch_assoc())) $buildTemplates[] = $r;
    $b->close();
}

// For cross-subject copy: list other classes assigned to this teacher.
$assignedClasses = [];
$a = $conn->prepare(
    "SELECT cr.id AS class_record_id,
            cr.section, cr.academic_year, cr.semester,
            s.subject_code, s.subject_name
     FROM teacher_assignments ta
     JOIN class_records cr ON cr.id = ta.class_record_id
     JOIN subjects s ON s.id = cr.subject_id
     WHERE ta.teacher_id = ?
       AND ta.status = 'active'
       AND cr.status = 'active'
     ORDER BY cr.academic_year DESC, cr.semester ASC, s.subject_name ASC, cr.section ASC
     LIMIT 80"
);
if ($a) {
    $a->bind_param('i', $teacherId);
    $a->execute();
    $res = $a->get_result();
    while ($res && ($r = $res->fetch_assoc())) {
        $cid = (int) ($r['class_record_id'] ?? 0);
        if ($cid > 0 && $cid !== $classRecordId) $assignedClasses[] = $r;
    }
    $a->close();
}

$aiChatHistory = (isset($_SESSION[$aiChatHistorySessionKey]) && is_array($_SESSION[$aiChatHistorySessionKey]))
    ? $_SESSION[$aiChatHistorySessionKey]
    : [];
$aiDraft = (isset($_SESSION[$aiDraftSessionKey]) && is_array($_SESSION[$aiDraftSessionKey]))
    ? $_SESSION[$aiDraftSessionKey]
    : null;
$aiFeedback = (isset($_SESSION[$aiFeedbackSessionKey]) && is_array($_SESSION[$aiFeedbackSessionKey]))
    ? $_SESSION[$aiFeedbackSessionKey]
    : null;

$aiChatCredits = ['limit' => 0.0, 'used' => 0.0, 'remaining' => 0.0, 'is_exempt' => false];
[$okAiCredits, $aiCreditStateOrMsg] = ai_chat_credit_get_user_status($conn, $teacherId);
if ($okAiCredits && is_array($aiCreditStateOrMsg)) {
    $aiChatCredits = [
        'limit' => (float) ($aiCreditStateOrMsg['limit'] ?? 0),
        'used' => (float) ($aiCreditStateOrMsg['used'] ?? 0),
        'remaining' => (float) ($aiCreditStateOrMsg['remaining'] ?? 0),
        'is_exempt' => !empty($aiCreditStateOrMsg['is_exempt']),
    ];
}
?>

<head>
    <title>Grading Components | E-Record</title>
    <?php include '../layouts/title-meta.php'; ?>
    <?php include '../layouts/head-css.php'; ?>
    <style>
        .gc-hero {
            border-radius: 16px;
            padding: 18px 18px;
            background: linear-gradient(135deg, #0f172a 0%, #123c6a 55%, #1f7a5d 100%);
            color: #fff;
            box-shadow: 0 14px 40px rgba(15, 23, 42, 0.25);
        }

        .gc-hero small {
            color: rgba(255, 255, 255, 0.78);
        }

        .gc-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 10px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.10);
            border: 1px solid rgba(255, 255, 255, 0.18);
        }

        .gc-chip strong {
            font-weight: 800;
        }

        .gc-table td {
            vertical-align: middle;
        }

        .gc-inline {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .gc-inline > * {
            flex: 1 1 auto;
        }

        .gc-wizard-card {
            border: 1px solid #d9e2ec;
            border-radius: 14px;
        }

        .gc-wizard-nav {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 8px;
        }

        .gc-wizard-nav .nav-link {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            text-align: left;
            border: 1px solid #dbe2ea;
            border-radius: 10px;
            color: #213547;
            background: #fff;
            padding: 10px 12px;
        }

        .gc-wizard-nav .nav-link:hover {
            border-color: #93c5fd;
            background: #f8fbff;
        }

        .gc-wizard-nav .nav-link.active {
            background: #0b57d0;
            border-color: #0b57d0;
            color: #fff;
            box-shadow: 0 6px 16px rgba(11, 87, 208, 0.25);
        }

        .gc-wizard-nav .nav-link.active .text-muted {
            color: rgba(255, 255, 255, 0.85) !important;
        }

        .gc-step-index {
            width: 24px;
            height: 24px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 12px;
            background: #e8eef8;
            color: #0b57d0;
            flex: 0 0 24px;
            margin-top: 1px;
        }

        .gc-wizard-nav .nav-link.active .gc-step-index {
            background: rgba(255, 255, 255, 0.18);
            color: #fff;
        }

        .gc-wizard-layout.gc-step-1-active .gc-right-col,
        .gc-wizard-layout.gc-step-2-active .gc-right-col {
            display: none;
        }

        .gc-wizard-layout.gc-step-1-active .gc-left-col,
        .gc-wizard-layout.gc-step-2-active .gc-left-col,
        .gc-wizard-layout.gc-step-3-active .gc-right-col {
            flex: 0 0 100%;
            max-width: 100%;
        }

        .gc-wizard-layout.gc-step-3-active .gc-left-col {
            display: none;
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
                                        <li class="breadcrumb-item"><a href="teacher-dashboard.php">Teacher</a></li>
                                        <li class="breadcrumb-item active">Grading Components</li>
                                    </ol>
                                </div>
                                <h4 class="page-title">Grading Components</h4>
                            </div>
                        </div>
                    </div>

                    <div class="gc-hero mb-3">
                        <div class="d-flex align-items-end justify-content-between flex-wrap gap-2">
                            <div>
                                <div class="fw-semibold">
                                    <?php echo htmlspecialchars((string) ($class['subject_name'] ?? '')); ?>
                                    <small>(<?php echo htmlspecialchars((string) ($class['subject_code'] ?? '')); ?>)</small>
                                </div>
                                <small>
                                    Section <?php echo htmlspecialchars($section); ?> |
                                    <?php echo htmlspecialchars($academicYear); ?>, <?php echo htmlspecialchars($semester); ?>
                                    | <?php echo htmlspecialchars($termLabel); ?>
                                </small>
                            </div>
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                <span class="gc-chip">
                                    <span class="text-white-50">Active weight</span>
                                    <strong><?php echo rtrim(rtrim(number_format($activeWeight, 2, '.', ''), '0'), '.'); ?></strong>
                                </span>
                                <span class="gc-chip">
                                    <span class="text-white-50">Target</span>
                                    <strong><?php echo rtrim(rtrim(number_format($totalWeight, 2, '.', ''), '0'), '.'); ?></strong>
                                </span>
                                <div class="btn-group btn-group-sm" role="group" aria-label="Term selector">
                                    <a class="btn <?php echo $term === 'midterm' ? 'btn-light' : 'btn-outline-light'; ?>"
                                        href="teacher-grading-config.php?class_record_id=<?php echo (int) $classRecordId; ?>&term=midterm">
                                        Midterm
                                    </a>
                                    <a class="btn <?php echo $term === 'final' ? 'btn-light' : 'btn-outline-light'; ?>"
                                        href="teacher-grading-config.php?class_record_id=<?php echo (int) $classRecordId; ?>&term=final">
                                        Final
                                    </a>
                                </div>
                                <a class="btn btn-sm btn-outline-light"
                                    href="teacher-wheel.php?class_record_id=<?php echo (int) $classRecordId; ?>">
                                    <i class="ri-disc-line me-1" aria-hidden="true"></i>
                                    Wheel
                                </a>
                            </div>
                        </div>
                        <div class="mt-2">
                            <div class="progress" style="height: 10px;">
                                <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo htmlspecialchars(number_format($pct, 2, '.', '')); ?>%"
                                    aria-valuenow="<?php echo htmlspecialchars(number_format($pct, 2, '.', '')); ?>" aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                        </div>
                    </div>

                    <?php if ($flash): ?>
                        <div class="alert alert-<?php echo htmlspecialchars($flashType); ?>"><?php echo htmlspecialchars($flash); ?></div>
                    <?php endif; ?>

                    <div class="card mb-3 gc-wizard-card">
                        <div class="card-body py-3">
                            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                                <div class="fw-semibold">Grading Setup Wizard</div>
                                <div class="text-muted small">Use the tabs to focus on one task at a time.</div>
                            </div>
                            <div class="nav gc-wizard-nav mt-2" role="tablist" aria-label="Grading setup steps">
                                <button class="nav-link active" type="button" data-gc-step="1" aria-selected="true">
                                    <span class="gc-step-index">1</span>
                                    <span>
                                        <span class="d-block fw-semibold">Set Up Components</span>
                                        <span class="small text-muted">Add categories and initial components</span>
                                    </span>
                                </button>
                                <button class="nav-link" type="button" data-gc-step="2" aria-selected="false">
                                    <span class="gc-step-index">2</span>
                                    <span>
                                        <span class="d-block fw-semibold">AI and Templates</span>
                                        <span class="small text-muted">Chat, apply, copy, and save builds</span>
                                    </span>
                                </button>
                                <button class="nav-link" type="button" data-gc-step="3" aria-selected="false">
                                    <span class="gc-step-index">3</span>
                                    <span>
                                        <span class="d-block fw-semibold">Review and Finalize</span>
                                        <span class="small text-muted">Edit order, weights, and assessment links</span>
                                    </span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div id="gcWizardLayout" class="row g-3 gc-wizard-layout gc-step-1-active">
                        <div class="col-xl-4 gc-left-col">
                            <div class="gc-step-pane" data-step="1">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title">Add Category (Optional)</h4>
                                    <p class="text-muted mb-3">Categories help group components (e.g. Quizzes, Exams).</p>

                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                        <input type="hidden" name="action" value="add_category">

                                        <div class="mb-2">
                                            <label class="form-label">Category Name</label>
                                            <input class="form-control" name="category_name" maxlength="100" required>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Default Weight (optional)</label>
                                            <input class="form-control" name="default_weight" inputmode="decimal" placeholder="e.g. 20">
                                        </div>

                                        <button class="btn btn-primary" type="submit">
                                            <i class="ri-add-line me-1" aria-hidden="true"></i>
                                            Add Category
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title">Add Component</h4>
                                    <p class="text-muted mb-3">Define a component (e.g. Quiz) and its weight. Individual items like Quiz 1..n are added under Assessments.</p>

                                    <form method="post" id="tgcAddComponentForm">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                        <input type="hidden" name="action" value="add_component">

                                        <div class="mb-2">
                                            <label class="form-label">Category (optional)</label>
                                            <select class="form-select" name="category_id">
                                                <option value="0">None</option>
                                                <?php foreach ($categories as $c): ?>
                                                    <option value="<?php echo (int) $c['id']; ?>"><?php echo htmlspecialchars((string) $c['category_name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="mb-2">
                                            <label class="form-label">Component Name</label>
                                            <input class="form-control" name="component_name" maxlength="100" required placeholder="e.g. Quiz">
                                        </div>

                                        <div class="mb-2">
                                            <label class="form-label">Component Code (optional)</label>
                                            <input class="form-control" name="component_code" maxlength="50" placeholder="e.g. QZ1">
                                        </div>

                                        <div class="row g-2">
                                            <div class="col-6">
                                                <label class="form-label">Type</label>
                                                <select class="form-select" name="component_type" required>
                                                    <?php foreach (allowed_component_types() as $t): ?>
                                                        <option value="<?php echo htmlspecialchars($t); ?>"><?php echo htmlspecialchars(ucfirst($t)); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-6">
                                                <label class="form-label">Weight</label>
                                                <input class="form-control" name="weight" inputmode="decimal" required placeholder="e.g. 10">
                                            </div>
                                        </div>

                                        <div class="mt-2">
                                            <label class="form-label">Display Order</label>
                                            <input class="form-control" name="display_order" type="number" value="0">
                                        </div>

                                        <?php if (count($syncPeerSections) > 0): ?>
                                            <div class="mt-2 border rounded p-2">
                                                <div class="fw-semibold small">Apply To Other Sections</div>
                                                <div class="text-muted small mb-1">Same subject and term.</div>
                                                <div class="d-flex flex-wrap gap-2">
                                                    <?php foreach ($syncPeerSections as $peerSection): ?>
                                                        <?php $peerClassId = (int) ($peerSection['class_record_id'] ?? 0); ?>
                                                        <label class="form-check form-check-inline m-0">
                                                            <input class="form-check-input js-tgc-sync-target" type="checkbox" name="apply_section_ids[]" value="<?php echo $peerClassId; ?>" <?php echo in_array($peerClassId, $syncPreferredPeerIds, true) ? 'checked' : ''; ?>>
                                                            <span class="form-check-label"><?php echo htmlspecialchars((string) ($peerSection['section'] ?? ('Section ' . $peerClassId))); ?></span>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </div>
                                                <div class="form-check mt-2 mb-0">
                                                    <input class="form-check-input" type="checkbox" id="tgcSyncAutoApply" name="sync_auto_apply" value="1" <?php echo !empty($syncPreference['auto_apply']) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label small" for="tgcSyncAutoApply">
                                                        Always apply to selected sections for this subject/term
                                                    </label>
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                        <div class="mt-3">
                                            <button class="btn btn-primary" type="submit">
                                                <i class="ri-save-3-line me-1" aria-hidden="true"></i>
                                                Add Component
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                            </div>

                            <div class="gc-step-pane d-none" data-step="2">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title">Build Templates</h4>
                                    <p class="text-muted mb-3">Builds are teacher-owned templates. You can apply them to any of your classes, even if the subject is different.</p>

                                    <div class="mb-3 p-3 rounded-3 border bg-body">
                                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                            <div class="fw-semibold">Ryhn Build Assistant (Chat)</div>
                                            <span class="badge bg-primary-subtle text-primary">Cost: <?php echo number_format((float) $aiChatRatePer100, 1, '.', ''); ?> credit / 100 chars</span>
                                        </div>
                                        <div class="small text-muted mt-1">
                                            Chat naturally with Ryhn. Ryhn asks follow-up questions when details are insufficient, then proposes a build draft once ready.
                                        </div>
                                        <div class="small mt-2">
                                            <?php if (!empty($aiChatCredits['is_exempt'])): ?>
                                                AI credits: <strong>Exempt (Admin)</strong>
                                            <?php else: ?>
                                                Remaining AI credits: <strong><?php echo number_format((float) ($aiChatCredits['remaining'] ?? 0), 2, '.', ''); ?></strong>
                                                <span class="text-muted">(used <?php echo number_format((float) ($aiChatCredits['used'] ?? 0), 2, '.', ''); ?> / <?php echo number_format((float) ($aiChatCredits['limit'] ?? 0), 2, '.', ''); ?>)</span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="mt-3 border rounded p-2" style="max-height: 360px; overflow-y: auto; background: #f8fafc;">
                                            <?php if (count($aiChatHistory) === 0): ?>
                                                <div class="text-muted small">
                                                    Start with something like:<br>
                                                    <em>"Hey, can you make me a class record build? I want categories: Written Works 20%, Performance Tasks 20%, Project 20%, Term Exam 40%..."</em>
                                                </div>
                                            <?php endif; ?>
                                            <?php foreach ($aiChatHistory as $msg): ?>
                                                <?php
                                                $isTeacherMsg = strtolower(trim((string) ($msg['role'] ?? ''))) === 'teacher';
                                                $bubbleClass = $isTeacherMsg ? 'bg-primary text-white ms-auto' : 'bg-white border';
                                                $alignClass = $isTeacherMsg ? 'text-end' : 'text-start';
                                                ?>
                                                <div class="mb-2 <?php echo $alignClass; ?>">
                                                    <div class="d-inline-block px-3 py-2 rounded-3 <?php echo $bubbleClass; ?>" style="max-width: 92%;">
                                                        <div class="small"><?php echo nl2br(htmlspecialchars((string) ($msg['content'] ?? ''))); ?></div>
                                                    </div>
                                                    <div class="small text-muted mt-1">
                                                        <?php echo htmlspecialchars((string) ($msg['at'] ?? '')); ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>

                                        <form method="post" class="mt-2">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                            <input type="hidden" name="action" value="ai_chat_send">
                                            <div class="input-group">
                                                <textarea class="form-control" name="ai_chat_message" rows="2" maxlength="5000" placeholder="Type your message to Ryhn..." required></textarea>
                                                <button class="btn btn-primary" type="submit">
                                                    <i class="ri-send-plane-2-line me-1" aria-hidden="true"></i>
                                                    Send
                                                </button>
                                            </div>
                                            <div class="small text-muted mt-1">
                                                Charge per sent message = <code>ceil(characters/100) * 0.1</code> AI credits from the shared pool.
                                            </div>
                                        </form>

                                        <form method="post" class="mt-2">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                            <input type="hidden" name="action" value="ai_chat_reset">
                                            <button class="btn btn-sm btn-outline-secondary" type="submit">Reset Chat</button>
                                        </form>

                                        <?php if (is_array($aiFeedback)): ?>
                                            <?php
                                            $ready = !empty($aiFeedback['ready']);
                                            $score = (int) ($aiFeedback['readiness_score'] ?? 0);
                                            $summary = (string) ($aiFeedback['summary'] ?? '');
                                            $gaps = is_array($aiFeedback['knowledge_gaps'] ?? null) ? $aiFeedback['knowledge_gaps'] : [];
                                            $questions = is_array($aiFeedback['follow_up_questions'] ?? null) ? $aiFeedback['follow_up_questions'] : [];
                                            ?>
                                            <div class="mt-3 p-2 rounded border <?php echo $ready ? 'border-success-subtle bg-success-subtle' : 'border-warning-subtle bg-warning-subtle'; ?>">
                                                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                                    <div class="fw-semibold"><?php echo $ready ? 'Ryhn is ready with a draft' : 'Ryhn needs more details'; ?></div>
                                                    <span class="badge <?php echo $ready ? 'bg-success' : 'bg-warning text-dark'; ?>">Score: <?php echo $score; ?>/100</span>
                                                </div>
                                                <?php if ($summary !== ''): ?>
                                                    <div class="small mt-1"><?php echo htmlspecialchars($summary); ?></div>
                                                <?php endif; ?>
                                                <?php if (count($gaps) > 0): ?>
                                                    <div class="small mt-2"><strong>Gaps:</strong></div>
                                                    <ul class="small mb-1 ps-3">
                                                        <?php foreach ($gaps as $gap): ?>
                                                            <li><?php echo htmlspecialchars((string) $gap); ?></li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                <?php endif; ?>
                                                <?php if (count($questions) > 0): ?>
                                                    <div class="small mt-2"><strong>Follow-up questions:</strong></div>
                                                    <ul class="small mb-1 ps-3">
                                                        <?php foreach ($questions as $q): ?>
                                                            <li><?php echo htmlspecialchars((string) $q); ?></li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (is_array($aiDraft) && is_array($aiDraft['components'] ?? null) && count((array) ($aiDraft['components'] ?? [])) > 0): ?>
                                            <?php
                                            $draftName = trim((string) ($aiDraft['build_name'] ?? 'AI Chat Build Draft'));
                                            $draftDesc = trim((string) ($aiDraft['build_description'] ?? ''));
                                            $draftComponents = (array) ($aiDraft['components'] ?? []);
                                            $draftTotal = 0.0;
                                            foreach ($draftComponents as $c) {
                                                $draftTotal += (float) ($c['weight'] ?? 0);
                                            }
                                            ?>
                                            <div class="mt-3 p-2 rounded border border-primary-subtle bg-light">
                                                <div class="fw-semibold"><?php echo htmlspecialchars($draftName !== '' ? $draftName : 'AI Chat Build Draft'); ?></div>
                                                <?php if ($draftDesc !== ''): ?>
                                                    <div class="small text-muted mt-1"><?php echo htmlspecialchars($draftDesc); ?></div>
                                                <?php endif; ?>
                                                <div class="small mt-1">
                                                    Visual Draft | Generated: <?php echo htmlspecialchars((string) ($aiDraft['generated_at'] ?? '')); ?> |
                                                    Total Weight: <strong><?php echo rtrim(rtrim(number_format($draftTotal, 2, '.', ''), '0'), '.'); ?></strong>
                                                </div>
                                                <div class="table-responsive mt-2">
                                                    <table class="table table-sm table-striped mb-2">
                                                        <thead>
                                                            <tr>
                                                                <th>Category</th>
                                                                <th>Component</th>
                                                                <th>Type</th>
                                                                <th style="width: 90px;">Weight</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($draftComponents as $dc): ?>
                                                                <tr>
                                                                    <td><?php echo htmlspecialchars((string) ($dc['category_name'] ?? 'General')); ?></td>
                                                                    <td>
                                                                        <?php echo htmlspecialchars((string) ($dc['component_name'] ?? '')); ?>
                                                                        <?php if (trim((string) ($dc['component_code'] ?? '')) !== ''): ?>
                                                                            <span class="text-muted small">(<?php echo htmlspecialchars((string) ($dc['component_code'] ?? '')); ?>)</span>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                    <td><?php echo htmlspecialchars(ucfirst((string) ($dc['component_type'] ?? 'other'))); ?></td>
                                                                    <td><?php echo rtrim(rtrim(number_format((float) ($dc['weight'] ?? 0), 2, '.', ''), '0'), '.'); ?></td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>

                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                                    <input type="hidden" name="action" value="ai_chat_apply_draft">
                                                    <div class="form-check mb-2">
                                                        <input class="form-check-input" type="checkbox" value="1" id="aiReplaceExisting" name="replace_existing">
                                                        <label class="form-check-label" for="aiReplaceExisting">
                                                            Replace existing components for this term
                                                        </label>
                                                    </div>
                                                    <?php if (count($syncPeerSections) > 0): ?>
                                                        <div class="border rounded p-2 mb-2">
                                                            <div class="fw-semibold small">Apply To Other Sections</div>
                                                            <div class="text-muted small mb-1">Same subject and term.</div>
                                                            <div class="d-flex flex-wrap gap-2">
                                                                <?php foreach ($syncPeerSections as $peerSection): ?>
                                                                    <?php $peerClassId = (int) ($peerSection['class_record_id'] ?? 0); ?>
                                                                    <label class="form-check form-check-inline m-0">
                                                                        <input class="form-check-input js-tgc-sync-target-shared" type="checkbox" name="apply_section_ids[]" value="<?php echo $peerClassId; ?>" <?php echo in_array($peerClassId, $syncPreferredPeerIds, true) ? 'checked' : ''; ?>>
                                                                        <span class="form-check-label"><?php echo htmlspecialchars((string) ($peerSection['section'] ?? ('Section ' . $peerClassId))); ?></span>
                                                                    </label>
                                                                <?php endforeach; ?>
                                                            </div>
                                                            <div class="form-check mt-2 mb-0">
                                                                <input class="form-check-input js-tgc-sync-auto-shared" type="checkbox" id="tgcSyncAutoApplyDraft" name="sync_auto_apply" value="1" <?php echo !empty($syncPreference['auto_apply']) ? 'checked' : ''; ?>>
                                                                <label class="form-check-label small" for="tgcSyncAutoApplyDraft">
                                                                    Always apply to selected sections for this subject/term
                                                                </label>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>
                                                    <button class="btn btn-primary" type="submit">
                                                        <i class="ri-check-line me-1" aria-hidden="true"></i>
                                                        Apply Draft To <?php echo htmlspecialchars($termLabel); ?>
                                                    </button>
                                                </form>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="mb-3 p-3 rounded-3 border bg-body">
                                        <div class="fw-semibold mb-2">Apply Build (<?php echo htmlspecialchars($termLabel); ?>)</div>
                                        <form method="post">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                            <input type="hidden" name="action" value="apply_build">

                                            <div class="mb-2">
                                                <label class="form-label">Build</label>
                                                <select class="form-select" name="build_id" required>
                                                    <option value="">Select</option>
                                                    <?php foreach ($buildTemplates as $bt): ?>
                                                        <option value="<?php echo (int) ($bt['id'] ?? 0); ?>">
                                                            <?php echo htmlspecialchars((string) ($bt['name'] ?? '')); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <?php if (count($buildTemplates) === 0): ?>
                                                    <div class="text-muted small mt-1">
                                                        No active builds yet. Configure components, then use "Save Term as Build" below.
                                                    </div>
                                                <?php endif; ?>
                                            </div>

                                            <div class="form-check mb-2">
                                                <input class="form-check-input" type="checkbox" value="1" id="replaceExisting" name="replace_existing">
                                                <label class="form-check-label" for="replaceExisting">
                                                    Replace existing components for this term
                                                </label>
                                            </div>

                                            <?php if (count($syncPeerSections) > 0): ?>
                                                <div class="border rounded p-2 mb-2">
                                                    <div class="fw-semibold small">Apply To Other Sections</div>
                                                    <div class="text-muted small mb-1">Same subject and term.</div>
                                                    <div class="d-flex flex-wrap gap-2">
                                                        <?php foreach ($syncPeerSections as $peerSection): ?>
                                                            <?php $peerClassId = (int) ($peerSection['class_record_id'] ?? 0); ?>
                                                            <label class="form-check form-check-inline m-0">
                                                                <input class="form-check-input js-tgc-sync-target-shared" type="checkbox" name="apply_section_ids[]" value="<?php echo $peerClassId; ?>" <?php echo in_array($peerClassId, $syncPreferredPeerIds, true) ? 'checked' : ''; ?>>
                                                                <span class="form-check-label"><?php echo htmlspecialchars((string) ($peerSection['section'] ?? ('Section ' . $peerClassId))); ?></span>
                                                            </label>
                                                        <?php endforeach; ?>
                                                    </div>
                                                    <div class="form-check mt-2 mb-0">
                                                        <input class="form-check-input js-tgc-sync-auto-shared" type="checkbox" id="tgcSyncAutoApplyBuild" name="sync_auto_apply" value="1" <?php echo !empty($syncPreference['auto_apply']) ? 'checked' : ''; ?>>
                                                        <label class="form-check-label small" for="tgcSyncAutoApplyBuild">
                                                            Always apply to selected sections for this subject/term
                                                        </label>
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                            <button class="btn btn-outline-primary" type="submit">
                                                <i class="ri-magic-line me-1" aria-hidden="true"></i>
                                                Apply Build
                                            </button>
                                        </form>
                                    </div>

                                    <div class="mb-3 p-3 rounded-3 border bg-body">
                                        <div class="fw-semibold mb-2">Copy Components From Another Subject/Class</div>
                                        <form method="post">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                            <input type="hidden" name="action" value="copy_from_class">

                                            <div class="mb-2">
                                                <label class="form-label">Source Class</label>
                                                <select class="form-select" name="source_class_record_id" required>
                                                    <option value="">Select</option>
                                                    <?php foreach ($assignedClasses as $ac): ?>
                                                        <option value="<?php echo (int) ($ac['class_record_id'] ?? 0); ?>">
                                                            <?php
                                                            $label =
                                                                (string) ($ac['subject_code'] ?? '') . ' - ' .
                                                                (string) ($ac['subject_name'] ?? '') . ' | ' .
                                                                (string) ($ac['section'] ?? '') . ' | ' .
                                                                (string) ($ac['academic_year'] ?? '') . ', ' .
                                                                (string) ($ac['semester'] ?? '');
                                                            echo htmlspecialchars(trim($label, " |,"));
                                                            ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <?php if (count($assignedClasses) === 0): ?>
                                                    <div class="text-muted small mt-1">
                                                        No other assigned classes found.
                                                    </div>
                                                <?php endif; ?>
                                            </div>

                                            <div class="row g-2">
                                                <div class="col-md-6">
                                                    <label class="form-label">Source Term</label>
                                                    <select class="form-select" name="source_term" required>
                                                        <option value="midterm" <?php echo $term === 'midterm' ? 'selected' : ''; ?>>Midterm</option>
                                                        <option value="final" <?php echo $term === 'final' ? 'selected' : ''; ?>>Final Term</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-6 d-flex align-items-end">
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" value="1" id="replaceFromClass" name="replace_existing">
                                                        <label class="form-check-label" for="replaceFromClass">
                                                            Replace existing components for this term
                                                        </label>
                                                    </div>
                                                </div>
                                            </div>

                                            <button class="btn btn-outline-secondary mt-3" type="submit" <?php echo count($assignedClasses) === 0 ? 'disabled' : ''; ?>>
                                                <i class="ri-file-transfer-line me-1" aria-hidden="true"></i>
                                                Copy Into <?php echo htmlspecialchars($termLabel); ?>
                                            </button>

                                            <div class="text-muted small mt-2">
                                                Copies categories + components (weights/order/active). Assessments and scores are not copied.
                                            </div>
                                        </form>
                                    </div>

                                    <div class="mb-3 p-3 rounded-3 border bg-body">
                                        <?php $otherTerm = $term === 'midterm' ? 'final' : 'midterm'; ?>
                                        <?php $otherLabel = $otherTerm === 'final' ? 'Final Term' : 'Midterm'; ?>
                                        <div class="fw-semibold mb-2">Reuse This Build for <?php echo htmlspecialchars($otherLabel); ?></div>
                                        <form method="post">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                            <input type="hidden" name="action" value="copy_term_to">
                                            <input type="hidden" name="target_term" value="<?php echo htmlspecialchars($otherTerm); ?>">

                                            <div class="form-check mb-2">
                                                <input class="form-check-input" type="checkbox" value="1" id="replaceOtherTerm" name="replace_existing">
                                                <label class="form-check-label" for="replaceOtherTerm">
                                                    Replace existing components in <?php echo htmlspecialchars($otherLabel); ?>
                                                </label>
                                            </div>

                                            <button class="btn btn-outline-secondary" type="submit">
                                                <i class="ri-arrow-right-line me-1" aria-hidden="true"></i>
                                                Copy <?php echo htmlspecialchars($termLabel); ?> to <?php echo htmlspecialchars($otherLabel); ?>
                                            </button>
                                        </form>
                                        <div class="text-muted small mt-2">
                                            This copies components (and weights) from the current term to the other term. Assessments and scores are separate per term.
                                        </div>
                                    </div>

                                    <div class="p-3 rounded-3 border bg-body">
                                        <div class="fw-semibold mb-2">Save Term as Build (<?php echo htmlspecialchars($termLabel); ?>)</div>
                                        <form method="post">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                            <input type="hidden" name="action" value="save_build_term">

                                            <div class="mb-2">
                                                <label class="form-label">Save Into</label>
                                                <select class="form-select" name="target_build_id">
                                                    <option value="0">New build</option>
                                                    <?php foreach ($buildTemplates as $bt): ?>
                                                        <option value="<?php echo (int) ($bt['id'] ?? 0); ?>">
                                                            Update: <?php echo htmlspecialchars((string) ($bt['name'] ?? '')); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <div class="text-muted small mt-1">
                                                    Choosing an existing build will replace its <?php echo htmlspecialchars($termLabel); ?> definition.
                                                </div>
                                            </div>

                                            <div class="mb-2">
                                                <label class="form-label">Build Name (for new build)</label>
                                                <input class="form-control" name="build_name" maxlength="120" placeholder="e.g. BSIT Default Build">
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label">Description (optional)</label>
                                                <textarea class="form-control" name="build_description" rows="2" placeholder="Optional notes for this build"></textarea>
                                            </div>

                                            <button class="btn btn-primary" type="submit">
                                                <i class="ri-save-3-line me-1" aria-hidden="true"></i>
                                                Save Build
                                            </button>
                                            <a class="btn btn-outline-secondary ms-2" href="teacher-builds.php">
                                                <i class="ri-file-list-3-line me-1" aria-hidden="true"></i>
                                                Manage Builds
                                            </a>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            </div>
                        </div>

                        <div class="col-xl-8 gc-right-col">
                            <div class="gc-step-pane d-none" data-step="3">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                                        <h4 class="header-title mb-0">Components &amp; Weights</h4>
                                        <a class="btn btn-sm btn-outline-secondary" href="teacher-dashboard.php">
                                            <i class="ri-arrow-left-line me-1" aria-hidden="true"></i>
                                            Back
                                        </a>
                                    </div>
                                    <div class="table-responsive mt-2">
                                        <table class="table table-sm table-striped table-hover align-middle mb-0 gc-table">
                                            <thead>
                                                <tr>
                                                    <th>Component</th>
                                                    <th>Category</th>
                                                    <th>Type</th>
                                                    <th style="width: 110px;">Weight</th>
                                                    <th style="width: 110px;">Order</th>
                                                    <th style="width: 120px;">Status</th>
                                                    <th class="text-end" style="width: 140px;">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (count($components) === 0): ?>
                                                    <tr>
                                                        <td colspan="7" class="text-center text-muted">No components yet. Add your first component on the left.</td>
                                                    </tr>
                                                <?php endif; ?>

                                                <?php foreach ($components as $gc): ?>
                                                    <tr>
                                                        <td>
                                                            <?php $formId = 'gc-upd-' . (int) ($gc['id'] ?? 0); ?>
                                                            <input
                                                                class="form-control form-control-sm"
                                                                name="component_name"
                                                                form="<?php echo htmlspecialchars($formId); ?>"
                                                                maxlength="100"
                                                                required
                                                                value="<?php echo htmlspecialchars((string) ($gc['component_name'] ?? '')); ?>">
                                                            <input
                                                                class="form-control form-control-sm mt-1"
                                                                name="component_code"
                                                                form="<?php echo htmlspecialchars($formId); ?>"
                                                                maxlength="50"
                                                                placeholder="Code (optional)"
                                                                value="<?php echo htmlspecialchars((string) ($gc['component_code'] ?? '')); ?>">
                                                        </td>
                                                        <td>
                                                                <select class="form-select form-select-sm" name="category_id" form="<?php echo htmlspecialchars($formId); ?>">
                                                                    <option value="0">None</option>
                                                                    <?php foreach ($categories as $c): ?>
                                                                        <option value="<?php echo (int) $c['id']; ?>" <?php echo ((int) ($gc['category_id'] ?? 0) === (int) $c['id']) ? 'selected' : ''; ?>>
                                                                            <?php echo htmlspecialchars((string) $c['category_name']); ?>
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                        </td>
                                                        <td>
                                                                <select class="form-select form-select-sm" name="component_type" form="<?php echo htmlspecialchars($formId); ?>" required>
                                                                    <?php foreach (allowed_component_types() as $t): ?>
                                                                        <option value="<?php echo htmlspecialchars($t); ?>" <?php echo ((string) ($gc['component_type'] ?? '') === $t) ? 'selected' : ''; ?>>
                                                                            <?php echo htmlspecialchars(ucfirst($t)); ?>
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                        </td>
                                                        <td>
                                                                <input class="form-control form-control-sm" name="weight" form="<?php echo htmlspecialchars($formId); ?>" inputmode="decimal" value="<?php echo htmlspecialchars((string) ($gc['weight'] ?? '0')); ?>" required>
                                                        </td>
                                                        <td>
                                                                <input class="form-control form-control-sm" name="display_order" form="<?php echo htmlspecialchars($formId); ?>" type="number" value="<?php echo (int) ($gc['display_order'] ?? 0); ?>">
                                                        </td>
                                                        <td>
                                                                <select class="form-select form-select-sm" name="is_active" form="<?php echo htmlspecialchars($formId); ?>">
                                                                    <option value="1" <?php echo ((int) ($gc['is_active'] ?? 0) === 1) ? 'selected' : ''; ?>>Active</option>
                                                                    <option value="0" <?php echo ((int) ($gc['is_active'] ?? 0) === 0) ? 'selected' : ''; ?>>Disabled</option>
                                                                </select>
                                                        </td>
                                                        <td class="text-end">
                                                                <form id="<?php echo htmlspecialchars($formId); ?>" method="post" class="d-inline">
                                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                                                    <input type="hidden" name="action" value="update_component">
                                                                    <input type="hidden" name="component_id" value="<?php echo (int) ($gc['id'] ?? 0); ?>">
                                                                </form>
                                                                <a class="btn btn-sm btn-outline-primary me-2"
                                                                    href="teacher-component-assessments.php?grading_component_id=<?php echo (int) ($gc['id'] ?? 0); ?>"
                                                                    title="Manage assessments (e.g. Quiz 1..n)">
                                                                    <i class="ri-list-check-3 me-1" aria-hidden="true"></i>
                                                                    Assessments
                                                                    <span class="badge bg-primary ms-1"><?php echo (int) ($gc['assessments_count'] ?? 0); ?></span>
                                                                </a>
                                                                <button class="btn btn-sm btn-primary" type="submit" form="<?php echo htmlspecialchars($formId); ?>">
                                                                    <i class="ri-save-3-line me-1" aria-hidden="true"></i>
                                                                    Save
                                                                </button>

                                                            <form method="post" class="d-inline">
                                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                                                <input type="hidden" name="action" value="delete_component">
                                                                <input type="hidden" name="component_id" value="<?php echo (int) ($gc['id'] ?? 0); ?>">
                                                                <button class="btn btn-sm btn-outline-danger" type="submit" onclick="return confirm('Delete this component?');">
                                                                    <i class="ri-delete-bin-6-line" aria-hidden="true"></i>
                                                                </button>
                                                            </form>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="mt-3 text-muted small">
                                        Notes: Weights are enforced so the total of active components does not exceed the target total. You can disable a component to exclude it from the total.
                                    </div>
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
    <script>
        (function () {
            var layout = document.getElementById('gcWizardLayout');
            if (!layout) return;

            var panes = document.querySelectorAll('.gc-step-pane[data-step]');
            var tabs = document.querySelectorAll('[data-gc-step]');
            if (!panes.length || !tabs.length) return;

            var validSteps = { '1': true, '2': true, '3': true };
            var storageKey = 'gc_wizard_step_<?php echo (int) $classRecordId; ?>_<?php echo htmlspecialchars($term, ENT_QUOTES, 'UTF-8'); ?>';
            var params = null;
            var urlStep = '';
            var urlFocus = '';
            try {
                params = new URLSearchParams(window.location.search);
                urlStep = String(params.get('step') || '');
                urlFocus = String(params.get('focus') || '');
            } catch (e) {
                urlStep = '';
                urlFocus = '';
            }
            var savedStep = '';
            try {
                savedStep = String(localStorage.getItem(storageKey) || '');
            } catch (e) {
                savedStep = '';
            }

            function applyStep(step) {
                if (!validSteps[step]) step = '1';

                layout.classList.remove('gc-step-1-active', 'gc-step-2-active', 'gc-step-3-active');
                layout.classList.add('gc-step-' + step + '-active');

                panes.forEach(function (pane) {
                    pane.classList.toggle('d-none', String(pane.getAttribute('data-step')) !== step);
                });
                tabs.forEach(function (tab) {
                    var active = String(tab.getAttribute('data-gc-step')) === step;
                    tab.classList.toggle('active', active);
                    tab.setAttribute('aria-selected', active ? 'true' : 'false');
                });

                try {
                    localStorage.setItem(storageKey, step);
                } catch (e) {
                    // ignore storage errors
                }
            }

            tabs.forEach(function (tab) {
                tab.addEventListener('click', function () {
                    applyStep(String(tab.getAttribute('data-gc-step') || '1'));
                });
            });

            var addComponentForm = document.getElementById('tgcAddComponentForm');
            if (addComponentForm) {
                addComponentForm.addEventListener('submit', function () {
                    var syncTargets = addComponentForm.querySelectorAll('.js-tgc-sync-target');
                    if (!syncTargets || syncTargets.length === 0) return;

                    var checkedCount = 0;
                    syncTargets.forEach(function (box) {
                        if (box.checked) checkedCount++;
                    });
                    if (checkedCount > 0) return;

                    var autoApplyInput = addComponentForm.querySelector('#tgcSyncAutoApply');
                    if (autoApplyInput && autoApplyInput.checked) return;

                    var applyAll = window.confirm('Apply this new component to your other sections in the same subject/term?');
                    if (!applyAll) return;
                    syncTargets.forEach(function (box) {
                        box.checked = true;
                    });
                });
            }

            var sharedSyncForms = document.querySelectorAll('form');
            sharedSyncForms.forEach(function (form) {
                var actionInput = form.querySelector('input[name="action"]');
                if (!actionInput) return;
                var actionValue = String(actionInput.value || '');
                if (actionValue !== 'apply_build' && actionValue !== 'ai_chat_apply_draft') return;

                form.addEventListener('submit', function () {
                    var syncTargets = form.querySelectorAll('.js-tgc-sync-target-shared');
                    if (!syncTargets || syncTargets.length === 0) return;

                    var checkedCount = 0;
                    syncTargets.forEach(function (box) {
                        if (box.checked) checkedCount++;
                    });
                    if (checkedCount > 0) return;

                    var autoInput = form.querySelector('.js-tgc-sync-auto-shared');
                    if (autoInput && autoInput.checked) return;

                    var applyAll = window.confirm('Apply this change to your other sections in the same subject/term?');
                    if (!applyAll) return;
                    syncTargets.forEach(function (box) {
                        box.checked = true;
                    });
                });
            });

            var initialStep = validSteps[urlStep] ? urlStep : (validSteps[savedStep] ? savedStep : '1');
            applyStep(initialStep);

            // Optional deep-link support (e.g. from Attendance Check-In -> Manual Entry).
            if (String(urlFocus || '').toLowerCase() === 'attendance') {
                applyStep('3');
                window.setTimeout(function () {
                    try {
                        var nameInputs = document.querySelectorAll('.gc-table input[name=\"component_name\"]');
                        var targetInput = null;
                        nameInputs.forEach(function (inp) {
                            if (targetInput) return;
                            var v = String((inp && inp.value) || '').toLowerCase();
                            if (v.indexOf('attendance') !== -1) targetInput = inp;
                        });
                        if (!targetInput) return;

                        var tr = targetInput.closest('tr');
                        if (!tr) return;

                        tr.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        tr.classList.add('table-warning');
                        window.setTimeout(function () { tr.classList.remove('table-warning'); }, 4000);
                    } catch (e) {
                        // ignore focus errors
                    }
                }, 80);
            }
        })();
    </script>
    <script src="assets/js/app.min.js"></script>
</body>
</html>
