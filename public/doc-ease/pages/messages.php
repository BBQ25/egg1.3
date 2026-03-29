<?php include '../layouts/session.php'; ?>
<?php require_any_role(['admin', 'teacher']); ?>
<?php include '../layouts/main.php'; ?>

<?php
require_once __DIR__ . '/../includes/messages.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/admin_ai_messages.php';
ensure_message_tables($conn);
ensure_audit_logs_table($conn);

$role = isset($_SESSION['user_role']) ? normalize_role($_SESSION['user_role']) : '';
if ($role !== 'admin' && empty($_SESSION['is_active'])) {
    deny_access(403, 'Forbidden: account not approved.');
}

$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
if ($userId <= 0) {
    deny_access(401, 'Unauthorized.');
}
$isAdmin = ($role === 'admin');

$flash = isset($_SESSION['flash_message']) ? (string) $_SESSION['flash_message'] : '';
$flashType = isset($_SESSION['flash_type']) ? (string) $_SESSION['flash_type'] : 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

$aiHistorySessionKey = 'messages_ai_history_' . $userId;
$aiPendingPlanSessionKey = 'messages_ai_pending_plan_' . $userId;
$aiUpdatedAtSessionKey = 'messages_ai_updated_at_' . $userId;
$aiHistory = (isset($_SESSION[$aiHistorySessionKey]) && is_array($_SESSION[$aiHistorySessionKey]))
    ? $_SESSION[$aiHistorySessionKey]
    : [];
$aiPendingPlan = (isset($_SESSION[$aiPendingPlanSessionKey]) && is_array($_SESSION[$aiPendingPlanSessionKey]))
    ? $_SESSION[$aiPendingPlanSessionKey]
    : null;
$aiUpdatedAt = isset($_SESSION[$aiUpdatedAtSessionKey]) ? (string) $_SESSION[$aiUpdatedAtSessionKey] : '';

// Start/open a conversation with a user.
$withUserId = isset($_GET['with_user_id']) ? (int) $_GET['with_user_id'] : 0;
if ($withUserId > 0 && $withUserId !== $userId) {
    $tid = message_get_or_create_thread($conn, $userId, $withUserId);
    if ($tid > 0) {
        header('Location: messages.php?thread_id=' . $tid);
        exit;
    }
}

$threadParam = isset($_GET['thread_id']) ? trim((string) $_GET['thread_id']) : '';
$isAiThread = $isAdmin && strtolower($threadParam) === 'ai';
$threadId = $isAiThread ? 0 : (int) $threadParam;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    $redirectParam = $isAiThread ? '?thread_id=ai' : ($threadId > 0 ? ('?thread_id=' . $threadId) : '');
    if (!csrf_validate($csrf)) {
        $_SESSION['flash_message'] = 'Security check failed (CSRF). Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: messages.php' . $redirectParam);
        exit;
    }

    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';
    if ($action === 'send') {
        $threadId = isset($_POST['thread_id']) ? (int) $_POST['thread_id'] : 0;
        $body = isset($_POST['body']) ? trim((string) $_POST['body']) : '';

        if ($threadId <= 0 || $body === '') {
            $_SESSION['flash_message'] = 'Message cannot be empty.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: messages.php' . ($threadId > 0 ? ('?thread_id=' . $threadId) : ''));
            exit;
        }

        if (!message_thread_has_user($conn, $threadId, $userId)) {
            deny_access(403, 'Forbidden: not part of this thread.');
        }

        $ok = message_send($conn, $threadId, $userId, $body);
        if ($ok) {
            audit_log($conn, 'message.sent', 'thread', $threadId, null, ['len' => strlen($body)]);
        }

        header('Location: messages.php?thread_id=' . $threadId);
        exit;
    }

    if ($action === 'ai_send') {
        if (!$isAdmin) deny_access(403, 'Forbidden: Admin only.');

        $body = isset($_POST['ai_body']) ? trim((string) $_POST['ai_body']) : '';
        if ($body === '') {
            $_SESSION['flash_message'] = 'Type a message before sending.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: messages.php?thread_id=ai');
            exit;
        }
        if (strlen($body) > 5000) $body = substr($body, 0, 5000);

        $history = (isset($_SESSION[$aiHistorySessionKey]) && is_array($_SESSION[$aiHistorySessionKey]))
            ? $_SESSION[$aiHistorySessionKey]
            : [];
        $history[] = [
            'role' => 'user',
            'content' => $body,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        if (count($history) > 80) $history = array_slice($history, -80);

        [$okAi, $aiDataOrMsg] = admin_ai_msg_chat_respond($conn, $history, $body);
        if (!$okAi) {
            $errorText = is_string($aiDataOrMsg) ? $aiDataOrMsg : 'AI request failed.';
            $_SESSION['flash_message'] = $errorText;
            $_SESSION['flash_type'] = 'danger';
            $history[] = [
                'role' => 'assistant',
                'content' => function_exists('admin_ai_msg_with_ryhn_intro')
                    ? admin_ai_msg_with_ryhn_intro('I could not process that right now. ' . $errorText, $history, true)
                    : ('Hi, I\'m Ryhn. I could not process that right now. ' . $errorText),
                'created_at' => date('Y-m-d H:i:s'),
            ];
        } else {
            $aiData = is_array($aiDataOrMsg) ? $aiDataOrMsg : [];
            $assistantMessage = trim((string) ($aiData['assistant_message'] ?? ''));
            if ($assistantMessage === '') $assistantMessage = 'Please provide more details.';
            $history[] = [
                'role' => 'assistant',
                'content' => $assistantMessage,
                'created_at' => date('Y-m-d H:i:s'),
            ];

            $plan = is_array($aiData['action_plan'] ?? null) ? $aiData['action_plan'] : null;
            $isReady = !empty($aiData['action_ready']) && is_array($plan);
            if ($isReady) {
                $_SESSION[$aiPendingPlanSessionKey] = $plan;
                $_SESSION['flash_message'] = 'AI prepared an action plan. Review and click Execute when ready.';
                $_SESSION['flash_type'] = 'info';
                audit_log($conn, 'message.ai.plan_ready', 'messages_ai', $userId, null, [
                    'plan_type' => (string) ($plan['type'] ?? ''),
                    'subject_count' => count((array) ($plan['subject_identifiers'] ?? [])),
                ]);
            } else {
                unset($_SESSION[$aiPendingPlanSessionKey]);
            }
        }

        if (count($history) > 80) $history = array_slice($history, -80);
        $_SESSION[$aiHistorySessionKey] = $history;
        $_SESSION[$aiUpdatedAtSessionKey] = date('Y-m-d H:i:s');
        audit_log($conn, 'message.ai.sent', 'messages_ai', $userId, null, ['len' => strlen($body)]);
        header('Location: messages.php?thread_id=ai');
        exit;
    }

    if ($action === 'ai_execute') {
        if (!$isAdmin) deny_access(403, 'Forbidden: Admin only.');

        $pendingPlan = (isset($_SESSION[$aiPendingPlanSessionKey]) && is_array($_SESSION[$aiPendingPlanSessionKey]))
            ? $_SESSION[$aiPendingPlanSessionKey]
            : null;
        if (!is_array($pendingPlan)) {
            $_SESSION['flash_message'] = 'No pending AI action plan to execute.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: messages.php?thread_id=ai');
            exit;
        }

        [$okExec, $execDataOrMsg] = admin_ai_msg_execute_action_plan($conn, $pendingPlan);
        $history = (isset($_SESSION[$aiHistorySessionKey]) && is_array($_SESSION[$aiHistorySessionKey]))
            ? $_SESSION[$aiHistorySessionKey]
            : [];
        if ($okExec) {
            $summary = admin_ai_msg_execution_summary_text(is_array($execDataOrMsg) ? $execDataOrMsg : []);
            $history[] = [
                'role' => 'assistant',
                'content' => $summary,
                'created_at' => date('Y-m-d H:i:s'),
            ];
            unset($_SESSION[$aiPendingPlanSessionKey]);
            $_SESSION['flash_message'] = 'AI action executed successfully.';
            $_SESSION['flash_type'] = 'success';
            audit_log($conn, 'message.ai.executed', 'messages_ai', $userId, null, is_array($execDataOrMsg) ? $execDataOrMsg : []);
        } else {
            $err = is_string($execDataOrMsg) ? $execDataOrMsg : 'Execution failed.';
            $history[] = [
                'role' => 'assistant',
                'content' => 'Execution failed. ' . $err,
                'created_at' => date('Y-m-d H:i:s'),
            ];
            $_SESSION['flash_message'] = $err;
            $_SESSION['flash_type'] = 'danger';
        }

        if (count($history) > 80) $history = array_slice($history, -80);
        $_SESSION[$aiHistorySessionKey] = $history;
        $_SESSION[$aiUpdatedAtSessionKey] = date('Y-m-d H:i:s');
        header('Location: messages.php?thread_id=ai');
        exit;
    }

    if ($action === 'ai_clear') {
        if (!$isAdmin) deny_access(403, 'Forbidden: Admin only.');
        unset($_SESSION[$aiHistorySessionKey], $_SESSION[$aiPendingPlanSessionKey], $_SESSION[$aiUpdatedAtSessionKey]);
        $_SESSION['flash_message'] = 'AI conversation cleared.';
        $_SESSION['flash_type'] = 'info';
        header('Location: messages.php?thread_id=ai');
        exit;
    }

    $_SESSION['flash_message'] = 'Invalid request.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: messages.php' . $redirectParam);
    exit;
}

// Threads list.
$threads = message_list_threads($conn, $userId, 50);

// Selected thread.
$selected = null;
$messages = [];
if (!$isAiThread && $threadId > 0 && message_thread_has_user($conn, $threadId, $userId)) {
    foreach ($threads as $t) {
        if ((int) ($t['thread_id'] ?? 0) === $threadId) { $selected = $t; break; }
    }
    $messages = message_list_thread_messages($conn, $threadId, 300);
}

function avatar_url($path) {
    $path = trim((string) $path);
    if ($path === '') return 'assets/images/users/avatar-1.jpg';
    return $path;
}

function display_name_row($r) {
    $fn = trim((string) ($r['other_first_name'] ?? ''));
    $ln = trim((string) ($r['other_last_name'] ?? ''));
    $full = trim($fn . ' ' . $ln);
    if ($full !== '') return $full;
    return (string) ($r['other_username'] ?? 'User');
}

// Build a "new message" recipient list.
$recipients = [];
$stmt = $conn->prepare(
    "SELECT id, username, useremail, first_name, last_name, profile_picture, role, is_active
     FROM users
     WHERE id <> ?
       AND (role = 'admin' OR is_active = 1)
     ORDER BY role = 'admin' DESC, username ASC
     LIMIT 200"
);
if ($stmt) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) $recipients[] = $row;
    $stmt->close();
}

function recipient_label($u) {
    $fn = trim((string) ($u['first_name'] ?? ''));
    $ln = trim((string) ($u['last_name'] ?? ''));
    $full = trim($fn . ' ' . $ln);
    $name = $full !== '' ? $full : (string) ($u['username'] ?? 'User');
    $role = (string) ($u['role'] ?? '');
    $email = (string) ($u['useremail'] ?? '');
    $bits = [$name];
    if ($role !== '') $bits[] = $role;
    if ($email !== '') $bits[] = $email;
    return implode(' | ', $bits);
}
?>

<head>
    <title>Messages | E-Record</title>
    <?php include '../layouts/title-meta.php'; ?>
    <?php include '../layouts/head-css.php'; ?>
    <style>
        .msg-thread { cursor: pointer; }
        .msg-thread.active { background: rgba(0, 0, 0, 0.04); }
        .msg-bubble {
            max-width: 78%;
            padding: 10px 12px;
            border-radius: 14px;
            border: 1px solid rgba(0,0,0,0.08);
            background: #fff;
        }
        .msg-bubble.me {
            margin-left: auto;
            background: rgba(114, 124, 245, 0.10);
            border-color: rgba(114, 124, 245, 0.25);
        }
        .msg-bubble.ai {
            border-color: rgba(13, 110, 253, 0.28);
            background: rgba(13, 110, 253, 0.06);
        }
        .msg-meta { font-size: 12px; opacity: 0.75; }
        .msg-scroll { max-height: 520px; overflow: auto; }
        .msg-plan-box {
            background: rgba(25, 135, 84, 0.08);
            border: 1px solid rgba(25, 135, 84, 0.25);
            border-radius: 12px;
            padding: 12px;
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
                                        <li class="breadcrumb-item"><a href="javascript: void(0);">E-Record</a></li>
                                        <li class="breadcrumb-item active">Messages</li>
                                    </ol>
                                </div>
                                <h4 class="page-title">Messages</h4>
                            </div>
                        </div>
                    </div>

                    <?php if ($flash): ?>
                        <div class="alert alert-<?php echo htmlspecialchars($flashType); ?>"><?php echo htmlspecialchars($flash); ?></div>
                    <?php endif; ?>

                    <div class="row g-3">
                        <div class="col-xl-4 col-lg-5">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <h4 class="header-title mb-0">Conversations</h4>
                                    </div>

                                    <form method="get" class="mt-3">
                                        <label class="form-label">New Message</label>
                                        <div class="input-group">
                                            <select class="form-select" name="with_user_id" required>
                                                <option value="">Select user</option>
                                                <?php foreach ($recipients as $u): ?>
                                                    <option value="<?php echo (int) ($u['id'] ?? 0); ?>">
                                                        <?php echo htmlspecialchars(recipient_label($u)); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button class="btn btn-outline-primary" type="submit">
                                                <i class="ri-chat-new-line me-1" aria-hidden="true"></i>
                                                Start
                                            </button>
                                        </div>
                                        <div class="text-muted small mt-1">Only approved accounts are listed.</div>
                                    </form>

                                    <?php if ($isAdmin): ?>
                                        <div class="mt-3">
                                            <a class="btn btn-sm btn-outline-info" href="messages.php?thread_id=ai">
                                                <i class="ri-robot-2-line me-1" aria-hidden="true"></i>
                                                Chat With Ryhn
                                            </a>
                                            <div class="text-muted small mt-1">Admin-only AI operations chat (e.g., create sections, create student accounts/profiles, create teacher accomplishments on behalf).</div>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="list-group list-group-flush">
                                    <?php if ($isAdmin): ?>
                                        <?php
                                        $aiLastBody = '';
                                        $aiWhen = $aiUpdatedAt !== '' ? $aiUpdatedAt : '';
                                        if (is_array($aiHistory) && count($aiHistory) > 0) {
                                            $lastAiRow = $aiHistory[count($aiHistory) - 1];
                                            if (is_array($lastAiRow)) {
                                                $aiLastBody = trim((string) ($lastAiRow['content'] ?? ''));
                                                if ($aiWhen === '') $aiWhen = trim((string) ($lastAiRow['created_at'] ?? ''));
                                            }
                                        }
                                        if (strlen($aiLastBody) > 60) $aiLastBody = substr($aiLastBody, 0, 60) . '...';
                                        ?>
                                        <a class="list-group-item list-group-item-action msg-thread <?php echo $isAiThread ? 'active' : ''; ?>"
                                           href="messages.php?thread_id=ai">
                                            <div class="d-flex align-items-start gap-2">
                                                <div class="rounded-circle d-inline-flex align-items-center justify-content-center bg-info-subtle text-info" style="height:40px;width:40px;">
                                                    <i class="ri-robot-2-line" aria-hidden="true"></i>
                                                </div>
                                                <div class="w-100">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <div class="fw-semibold">Ryhn</div>
                                                        <div class="text-muted small"><?php echo htmlspecialchars($aiWhen); ?></div>
                                                    </div>
                                                    <div class="text-muted small"><?php echo htmlspecialchars($aiLastBody !== '' ? $aiLastBody : 'Create sections, assign subjects, and ask admin operations questions.'); ?></div>
                                                </div>
                                            </div>
                                        </a>
                                    <?php endif; ?>

                                    <?php if (count($threads) === 0): ?>
                                        <div class="p-3 text-muted">No user conversations yet.</div>
                                    <?php endif; ?>
                                    <?php foreach ($threads as $t): ?>
                                        <?php
                                        $tid = (int) ($t['thread_id'] ?? 0);
                                        $active = !$isAiThread && $tid > 0 && $tid === $threadId;
                                        $otherName = display_name_row($t);
                                        $pic = avatar_url($t['other_profile_picture'] ?? '');
                                        $last = trim((string) ($t['last_body'] ?? ''));
                                        if (strlen($last) > 60) $last = substr($last, 0, 60) . '...';
                                        $when = (string) ($t['last_at'] ?? $t['thread_created_at'] ?? '');
                                        ?>
                                        <a class="list-group-item list-group-item-action msg-thread <?php echo $active ? 'active' : ''; ?>"
                                           href="messages.php?thread_id=<?php echo $tid; ?>">
                                            <div class="d-flex align-items-start gap-2">
                                                <img src="<?php echo htmlspecialchars($pic); ?>" class="rounded-circle" height="40" width="40" alt="avatar">
                                                <div class="w-100">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <div class="fw-semibold"><?php echo htmlspecialchars($otherName); ?></div>
                                                        <div class="text-muted small"><?php echo htmlspecialchars($when); ?></div>
                                                    </div>
                                                    <div class="text-muted small"><?php echo htmlspecialchars($last !== '' ? $last : 'No messages yet.'); ?></div>
                                                </div>
                                            </div>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-8 col-lg-7">
                            <div class="card">
                                <div class="card-body">
                                    <?php if ($isAiThread && $isAdmin): ?>
                                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                            <div>
                                                <div class="fw-semibold">Ryhn</div>
                                                <div class="text-muted small">Clarify-first operations chat. AI prepares a plan first, then you execute.</div>
                                            </div>
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                                <input type="hidden" name="action" value="ai_clear">
                                                <button class="btn btn-sm btn-outline-secondary" type="submit">Clear Chat</button>
                                            </form>
                                        </div>

                                        <div class="msg-scroll mt-3 p-2 border rounded-3 bg-light-subtle">
                                            <?php if (count($aiHistory) === 0): ?>
                                                <div class="text-muted">
                                                    Start with a command like:
                                                    <code>Create IF-2-B-6 and assign IT 208, IT 208L.</code>
                                                    <br>
                                                    or
                                                    <code>Create an accomplishment for teacher junnie@example.com on 2026-02-12.</code>
                                                    <br>
                                                    or
                                                    <code>Show accomplishments rendered today by teacher junnie@example.com.</code>
                                                    <br>
                                                    or
                                                    <code>Create student profile and account for Student ID 2410233-1, BASAS, NIEL JOHN, BSInfoTech, 2nd Year, section B, nbasas@example.com.</code>
                                                    <br>
                                                    or
                                                    <code>Show numbers of all enrolled students.</code>
                                                </div>
                                            <?php endif; ?>
                                            <?php foreach ($aiHistory as $m): ?>
                                                <?php
                                                if (!is_array($m)) continue;
                                                $roleMsg = strtolower(trim((string) ($m['role'] ?? 'user')));
                                                $isMe = ($roleMsg === 'user' || $roleMsg === 'admin');
                                                $sender = $isMe ? 'You' : 'Ryhn';
                                                $bubbleClass = $isMe ? 'me' : 'ai';
                                                ?>
                                                <div class="mb-2">
                                                    <div class="msg-bubble <?php echo $bubbleClass; ?>">
                                                        <div class="msg-meta mb-1">
                                                            <?php echo htmlspecialchars($sender); ?> |
                                                            <?php echo htmlspecialchars((string) ($m['created_at'] ?? '')); ?>
                                                        </div>
                                                        <div style="white-space: pre-wrap;"><?php echo htmlspecialchars((string) ($m['content'] ?? '')); ?></div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>

                                        <?php if (is_array($aiPendingPlan)): ?>
                                            <div class="msg-plan-box mt-3">
                                                <div class="fw-semibold mb-1">Pending Action Plan</div>
                                                <pre class="mb-2" style="white-space: pre-wrap;"><?php echo htmlspecialchars(admin_ai_msg_plan_preview_text($aiPendingPlan)); ?></pre>
                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                                    <input type="hidden" name="action" value="ai_execute">
                                                    <button class="btn btn-success btn-sm" type="submit">
                                                        <i class="ri-play-circle-line me-1" aria-hidden="true"></i>Execute Plan
                                                    </button>
                                                </form>
                                                <form method="post" class="d-inline ms-1">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                                    <input type="hidden" name="action" value="ai_clear">
                                                    <button class="btn btn-outline-secondary btn-sm" type="submit">Cancel Plan</button>
                                                </form>
                                            </div>
                                        <?php endif; ?>

                                        <form method="post" class="mt-3">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                            <input type="hidden" name="action" value="ai_send">

                                            <div class="input-group">
                                                <textarea class="form-control" name="ai_body" rows="2" maxlength="5000" placeholder="Ask AI to prepare an admin action plan (including on-behalf teacher actions)..." required></textarea>
                                                <button class="btn btn-primary" type="submit">
                                                    <i class="ri-send-plane-2-line me-1" aria-hidden="true"></i>
                                                    Send
                                                </button>
                                            </div>
                                            <div class="text-muted small mt-1">AI asks clarifying details first. Admin explicitly executes the prepared plan.</div>
                                        </form>
                                    <?php elseif (!$threadId || !message_thread_has_user($conn, $threadId, $userId)): ?>
                                        <div class="text-muted">Select a conversation to start chatting.</div>
                                    <?php else: ?>
                                        <?php
                                        $otherName = $selected ? display_name_row($selected) : 'Conversation';
                                        ?>
                                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                            <div>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($otherName); ?></div>
                                                <div class="text-muted small">Thread #<?php echo (int) $threadId; ?></div>
                                            </div>
                                        </div>

                                        <div class="msg-scroll mt-3 p-2 border rounded-3 bg-light-subtle">
                                            <?php if (count($messages) === 0): ?>
                                                <div class="text-muted">No messages yet.</div>
                                            <?php endif; ?>
                                            <?php foreach ($messages as $m): ?>
                                                <?php
                                                $isMe = ((int) ($m['sender_id'] ?? 0) === $userId);
                                                $sender = trim((string) ($m['first_name'] ?? '') . ' ' . (string) ($m['last_name'] ?? ''));
                                                if ($sender === '') $sender = (string) ($m['username'] ?? 'User');
                                                ?>
                                                <div class="mb-2">
                                                    <div class="msg-bubble <?php echo $isMe ? 'me' : ''; ?>">
                                                        <div class="msg-meta mb-1">
                                                            <?php echo htmlspecialchars($isMe ? 'You' : $sender); ?> |
                                                            <?php echo htmlspecialchars((string) ($m['created_at'] ?? '')); ?>
                                                        </div>
                                                        <div style="white-space: pre-wrap;"><?php echo htmlspecialchars((string) ($m['body'] ?? '')); ?></div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>

                                        <form method="post" class="mt-3">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                            <input type="hidden" name="action" value="send">
                                            <input type="hidden" name="thread_id" value="<?php echo (int) $threadId; ?>">

                                            <div class="input-group">
                                                <textarea class="form-control" name="body" rows="2" maxlength="5000" placeholder="Type a message..." required></textarea>
                                                <button class="btn btn-primary" type="submit">
                                                    <i class="ri-send-plane-2-line me-1" aria-hidden="true"></i>
                                                    Send
                                                </button>
                                            </div>
                                            <div class="text-muted small mt-1">Max 5000 characters.</div>
                                        </form>
                                    <?php endif; ?>
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
</body>
</html>
