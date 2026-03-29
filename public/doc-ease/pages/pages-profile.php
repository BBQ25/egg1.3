<?php include '../layouts/session.php'; ?>
<?php require_any_role(['admin', 'teacher', 'student']); ?>

<?php
require_once __DIR__ . '/../includes/profile.php';
require_once __DIR__ . '/../includes/messages.php';
require_once __DIR__ . '/../includes/audit.php';

ensure_profile_tables($conn);
ensure_message_tables($conn);
ensure_audit_logs_table($conn);
ensure_users_password_policy_columns($conn);

$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
if ($userId <= 0) deny_access(401, 'Unauthorized.');

$role = isset($_SESSION['user_role']) ? normalize_role($_SESSION['user_role']) : '';

$flash = isset($_SESSION['flash_message']) ? (string) $_SESSION['flash_message'] : '';
$flashType = isset($_SESSION['flash_type']) ? (string) $_SESSION['flash_type'] : 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

function h($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function avatar_url($path) {
    $path = trim((string) $path);
    return $path !== '' ? $path : 'assets/images/users/avatar-1.jpg';
}
function heat_level($count) {
    $c = (int) $count;
    if ($c <= 0) return 0;
    if ($c <= 1) return 1;
    if ($c <= 3) return 2;
    if ($c <= 6) return 3;
    return 4;
}

$activeTab = isset($_GET['tab']) ? strtolower(trim((string) $_GET['tab'])) : 'about';
if (!in_array($activeTab, ['about', 'timeline', 'settings'], true)) $activeTab = 'about';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    if (!csrf_validate($csrf)) {
        $_SESSION['flash_message'] = 'Security check failed (CSRF). Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: pages-profile.php?tab=settings');
        exit;
    }

    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

    if ($action === 'change_password') {
        $currentPassword = isset($_POST['current_password']) ? (string) $_POST['current_password'] : '';
        $newPassword = isset($_POST['new_password']) ? (string) $_POST['new_password'] : '';
        $confirmPassword = isset($_POST['confirm_password']) ? (string) $_POST['confirm_password'] : '';

        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            $_SESSION['flash_message'] = 'Please fill in all password fields.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: pages-profile.php?tab=settings');
            exit;
        }
        if ($newPassword !== $confirmPassword) {
            $_SESSION['flash_message'] = 'New password confirmation does not match.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: pages-profile.php?tab=settings');
            exit;
        }
        if (strlen($newPassword) < 8) {
            $_SESSION['flash_message'] = 'New password must be at least 8 characters.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: pages-profile.php?tab=settings');
            exit;
        }

        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
        if (!$stmt) {
            $_SESSION['flash_message'] = 'Unable to validate your account. Please try again.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: pages-profile.php?tab=settings');
            exit;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!$row) {
            $_SESSION['flash_message'] = 'Account not found.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: pages-profile.php?tab=settings');
            exit;
        }
        $hashNow = (string) ($row['password'] ?? '');
        if (!password_verify($currentPassword, $hashNow)) {
            $_SESSION['flash_message'] = 'Current password is incorrect.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: pages-profile.php?tab=settings');
            exit;
        }
        if (password_verify($newPassword, $hashNow)) {
            $_SESSION['flash_message'] = 'New password must be different from your current password.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: pages-profile.php?tab=settings');
            exit;
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $upd = $conn->prepare(
            "UPDATE users
             SET password = ?, must_change_password = 0, password_changed_at = NOW()
             WHERE id = ?
             LIMIT 1"
        );
        if (!$upd) {
            $_SESSION['flash_message'] = 'Unable to update password. Please try again.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: pages-profile.php?tab=settings');
            exit;
        }
        $upd->bind_param('si', $newHash, $userId);
        $ok = false;
        try { $ok = $upd->execute(); } catch (Throwable $e) { $ok = false; }
        $upd->close();

        if ($ok) {
            $_SESSION['force_password_change'] = 0;
            audit_log($conn, 'auth.password.changed', 'user', $userId, null);
            $_SESSION['flash_message'] = 'Password changed successfully.';
            $_SESSION['flash_type'] = 'success';
        } else {
            $_SESSION['flash_message'] = 'Unable to update password. Please try again.';
            $_SESSION['flash_type'] = 'danger';
        }

        header('Location: pages-profile.php?tab=settings');
        exit;
    }

    if ($action === 'submit_change_request') {
        $pending = profile_pending_request($conn, $userId);
        if ($pending) {
            $_SESSION['flash_message'] = 'You already have a pending profile update request.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: pages-profile.php?tab=settings');
            exit;
        }

        $requestedProgramChairUserId = 0;
        $requestedSubjectProgramChairMap = [];
        if ($role === 'teacher') {
            $allowedSubjectOptions = profile_teacher_subject_options($conn, $userId);
            $allowedSubjectMap = [];
            foreach ($allowedSubjectOptions as $allowedSubjectLabel) {
                $allowedNorm = profile_normalize_subject_label($allowedSubjectLabel);
                if ($allowedNorm === '') continue;
                $allowedSubjectMap[$allowedNorm] = (string) $allowedSubjectLabel;
                $requestedSubjectProgramChairMap[$allowedNorm] = [
                    'subject_label' => (string) $allowedSubjectLabel,
                    'program_chair_user_id' => null,
                ];
            }

            $postedSubjectLabels = (isset($_POST['subject_program_chair_subject']) && is_array($_POST['subject_program_chair_subject']))
                ? $_POST['subject_program_chair_subject']
                : [];
            $postedProgramChairIds = (isset($_POST['subject_program_chair_user_id']) && is_array($_POST['subject_program_chair_user_id']))
                ? $_POST['subject_program_chair_user_id']
                : [];

            $rowCount = max(count($postedSubjectLabels), count($postedProgramChairIds));
            for ($idx = 0; $idx < $rowCount; $idx++) {
                $postedSubjectLabel = trim((string) ($postedSubjectLabels[$idx] ?? ''));
                $postedSubjectNorm = profile_normalize_subject_label($postedSubjectLabel);
                if ($postedSubjectNorm === '' || !isset($allowedSubjectMap[$postedSubjectNorm])) continue;

                $subjectLabel = (string) $allowedSubjectMap[$postedSubjectNorm];
                $rawProgramChairUserId = trim((string) ($postedProgramChairIds[$idx] ?? ''));
                $subjectProgramChairUserId = 0;
                if ($rawProgramChairUserId !== '') {
                    $subjectProgramChairUserId = (int) $rawProgramChairUserId;
                    if ($subjectProgramChairUserId <= 0 || !profile_is_valid_program_chair_id($conn, $subjectProgramChairUserId)) {
                        $_SESSION['flash_message'] = 'Invalid Program Chair selection for subject "' . $subjectLabel . '".';
                        $_SESSION['flash_type'] = 'warning';
                        header('Location: pages-profile.php?tab=settings');
                        exit;
                    }
                }

                $requestedSubjectProgramChairMap[$postedSubjectNorm] = [
                    'subject_label' => $subjectLabel,
                    'program_chair_user_id' => $subjectProgramChairUserId > 0 ? $subjectProgramChairUserId : null,
                ];
            }

            $selectedProgramChairIds = [];
            foreach ($requestedSubjectProgramChairMap as $subjectProgramChairRow) {
                if (!is_array($subjectProgramChairRow)) continue;
                $subjectProgramChairUserId = (int) ($subjectProgramChairRow['program_chair_user_id'] ?? 0);
                if ($subjectProgramChairUserId > 0) $selectedProgramChairIds[$subjectProgramChairUserId] = true;
            }
            if (count($selectedProgramChairIds) === 1) {
                $keys = array_keys($selectedProgramChairIds);
                $requestedProgramChairUserId = (int) ($keys[0] ?? 0);
            }
        }

        $payload = [
            'first_name' => trim((string) ($_POST['first_name'] ?? '')),
            'last_name' => trim((string) ($_POST['last_name'] ?? '')),
            'bio' => trim((string) ($_POST['bio'] ?? '')),
            'phone' => trim((string) ($_POST['phone'] ?? '')),
            'location' => trim((string) ($_POST['location'] ?? '')),
            'github_username' => trim((string) ($_POST['github_username'] ?? '')),
        ];
        if ($role === 'teacher') {
            $payload['program_chair_user_id'] = $requestedProgramChairUserId > 0 ? $requestedProgramChairUserId : null;
            $payload['subject_program_chair_map'] = array_values($requestedSubjectProgramChairMap);
        }

        // Optional avatar upload (staged until admin approval).
        $stagedAvatarRel = null;
        if (isset($_FILES['avatar']) && is_array($_FILES['avatar']) && ($_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $err = (int) ($_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($err !== UPLOAD_ERR_OK) {
                $_SESSION['flash_message'] = 'Avatar upload failed.';
                $_SESSION['flash_type'] = 'danger';
                header('Location: pages-profile.php?tab=settings');
                exit;
            }

            $tmp = (string) ($_FILES['avatar']['tmp_name'] ?? '');
            $size = (int) ($_FILES['avatar']['size'] ?? 0);
            if ($tmp === '' || $size <= 0) {
                $_SESSION['flash_message'] = 'Invalid avatar upload.';
                $_SESSION['flash_type'] = 'danger';
                header('Location: pages-profile.php?tab=settings');
                exit;
            }
            if ($size > 2 * 1024 * 1024) {
                $_SESSION['flash_message'] = 'Avatar must be 2MB or less.';
                $_SESSION['flash_type'] = 'warning';
                header('Location: pages-profile.php?tab=settings');
                exit;
            }

            $imgInfo = @getimagesize($tmp);
            $mime = is_array($imgInfo) ? (string) ($imgInfo['mime'] ?? '') : '';
            $allowed = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
            ];
            if (!isset($allowed[$mime])) {
                $_SESSION['flash_message'] = 'Avatar must be an image (JPG, PNG, GIF, or WEBP).';
                $_SESSION['flash_type'] = 'warning';
                header('Location: pages-profile.php?tab=settings');
                exit;
            }

            $root = realpath(__DIR__ . '/..');
            if (!$root) {
                $_SESSION['flash_message'] = 'Unable to store avatar.';
                $_SESSION['flash_type'] = 'danger';
                header('Location: pages-profile.php?tab=settings');
                exit;
            }

            $stageDirRel = 'uploads/profile_pictures/staged';
            $stageDirAbs = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $stageDirRel);
            if (!is_dir($stageDirAbs)) @mkdir($stageDirAbs, 0775, true);

            $rand = bin2hex(random_bytes(6));
            $name = 'user_' . $userId . '_' . date('Ymd_His') . '_' . $rand . '.' . $allowed[$mime];
            $stagedAvatarRel = $stageDirRel . '/' . $name;
            $dstAbs = $stageDirAbs . DIRECTORY_SEPARATOR . $name;

            if (!@move_uploaded_file($tmp, $dstAbs)) {
                $_SESSION['flash_message'] = 'Unable to save avatar.';
                $_SESSION['flash_type'] = 'danger';
                header('Location: pages-profile.php?tab=settings');
                exit;
            }
        }

        [$ok, $res] = profile_create_change_request($conn, $userId, $payload, $stagedAvatarRel);
        if ($ok) {
            $newId = (int) $res;
            audit_log($conn, 'profile.change.requested', 'profile_change_request', $newId, null);
            $_SESSION['flash_message'] = 'Profile update submitted. Waiting for admin approval.';
            $_SESSION['flash_type'] = 'success';
        } else {
            $_SESSION['flash_message'] = (string) $res;
            $_SESSION['flash_type'] = 'danger';
        }

        header('Location: pages-profile.php?tab=settings');
        exit;
    }

    if ($action === 'cancel_change_request') {
        $requestId = isset($_POST['request_id']) ? (int) $_POST['request_id'] : 0;
        if ($requestId <= 0) {
            $_SESSION['flash_message'] = 'Invalid request.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: pages-profile.php?tab=settings');
            exit;
        }

        $ok = profile_cancel_change_request($conn, $userId, $requestId);
        if ($ok) {
            audit_log($conn, 'profile.change.cancelled', 'profile_change_request', $requestId, null);
            $_SESSION['flash_message'] = 'Request cancelled.';
            $_SESSION['flash_type'] = 'success';
        } else {
            $_SESSION['flash_message'] = 'Unable to cancel request.';
            $_SESSION['flash_type'] = 'danger';
        }

        header('Location: pages-profile.php?tab=settings');
        exit;
    }

    $_SESSION['flash_message'] = 'Invalid request.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: pages-profile.php?tab=' . $activeTab);
    exit;
}

$me = profile_load($conn, $userId);
if (!$me) deny_access(404, 'User not found.');

$programChairOptions = profile_program_chair_options($conn);
$programChairOptionMap = [];
foreach ($programChairOptions as $opt) {
    $optId = (int) ($opt['id'] ?? 0);
    if ($optId <= 0) continue;
    $programChairOptionMap[$optId] = $opt;
}
$currentProgramChairUserId = isset($me['program_chair_user_id']) ? (int) $me['program_chair_user_id'] : 0;
$currentProgramChairDisplay = trim((string) ($me['program_chair_display_name'] ?? ''));
if ($currentProgramChairDisplay === '' && $currentProgramChairUserId > 0 && isset($programChairOptionMap[$currentProgramChairUserId])) {
    $currentProgramChairDisplay = trim((string) ($programChairOptionMap[$currentProgramChairUserId]['display_name'] ?? ''));
}
$currentProgramChairEmail = trim((string) ($me['program_chair_email'] ?? ''));
if ($currentProgramChairEmail === '' && $currentProgramChairUserId > 0 && isset($programChairOptionMap[$currentProgramChairUserId])) {
    $currentProgramChairEmail = trim((string) ($programChairOptionMap[$currentProgramChairUserId]['useremail'] ?? ''));
}

$teacherSubjectOptions = [];
$teacherSubjectProgramChairMap = [];
if ($role === 'teacher') {
    $teacherSubjectOptions = profile_teacher_subject_options($conn, $userId);
    $subjectProgramChairAssignments = profile_teacher_subject_program_chair_assignments($conn, $userId);
    foreach ($teacherSubjectOptions as $subjectLabel) {
        $subjectNorm = profile_normalize_subject_label($subjectLabel);
        if ($subjectNorm === '') continue;
        if (isset($teacherSubjectProgramChairMap[$subjectNorm])) continue;

        $programChairUserId = 0;
        $programChairDisplay = '';
        $programChairEmail = '';
        if (isset($subjectProgramChairAssignments[$subjectNorm])) {
            $assignment = $subjectProgramChairAssignments[$subjectNorm];
            $programChairUserId = (int) ($assignment['program_chair_user_id'] ?? 0);
            $programChairDisplay = trim((string) ($assignment['program_chair_display_name'] ?? ''));
            $programChairEmail = trim((string) ($assignment['program_chair_email'] ?? ''));
        }

        if ($programChairUserId <= 0 && $currentProgramChairUserId > 0) {
            $programChairUserId = $currentProgramChairUserId;
            $programChairDisplay = $currentProgramChairDisplay;
            $programChairEmail = $currentProgramChairEmail;
        }

        $teacherSubjectProgramChairMap[$subjectNorm] = [
            'subject_label' => (string) $subjectLabel,
            'program_chair_user_id' => $programChairUserId > 0 ? $programChairUserId : 0,
            'program_chair_display_name' => $programChairDisplay,
            'program_chair_email' => $programChairEmail,
        ];
    }

    foreach ($subjectProgramChairAssignments as $subjectNorm => $assignment) {
        if (!is_array($assignment) || isset($teacherSubjectProgramChairMap[$subjectNorm])) continue;
        $subjectLabel = trim((string) ($assignment['subject_label'] ?? ''));
        if ($subjectLabel === '') continue;

        $teacherSubjectOptions[] = $subjectLabel;
        $teacherSubjectProgramChairMap[$subjectNorm] = [
            'subject_label' => $subjectLabel,
            'program_chair_user_id' => (int) ($assignment['program_chair_user_id'] ?? 0),
            'program_chair_display_name' => trim((string) ($assignment['program_chair_display_name'] ?? '')),
            'program_chair_email' => trim((string) ($assignment['program_chair_email'] ?? '')),
        ];
    }
}

$fullName = profile_full_name($me);
$email = (string) ($me['useremail'] ?? '');
$avatar = avatar_url($me['profile_picture'] ?? '');
$pending = profile_pending_request($conn, $userId);

$threads = message_list_threads($conn, $userId, 5);

// Audit timeline.
$auditRows = [];
$stmt = $conn->prepare("SELECT id, action, message, created_at FROM audit_logs WHERE actor_user_id = ? ORDER BY id DESC LIMIT 80");
if ($stmt) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) $auditRows[] = $row;
    $stmt->close();
}

// Heatmap (last ~12 months).
$counts = [];
$stmt = $conn->prepare(
    "SELECT DATE(created_at) AS d, COUNT(*) AS c
     FROM audit_logs
     WHERE actor_user_id = ?
       AND created_at >= (CURDATE() - INTERVAL 400 DAY)
     GROUP BY DATE(created_at)"
);
if ($stmt) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
        $d = (string) ($row['d'] ?? '');
        $c = (int) ($row['c'] ?? 0);
        if ($d !== '') $counts[$d] = $c;
    }
    $stmt->close();
}

$end = new DateTimeImmutable('today');
$start = $end->sub(new DateInterval('P364D'));
$gridStart = $start->sub(new DateInterval('P' . (int) $start->format('w') . 'D')); // align to Sunday
$tail = 6 - (int) $end->format('w');
$gridEnd = $tail > 0 ? $end->add(new DateInterval('P' . $tail . 'D')) : $end; // align to Saturday

$dates = [];
for ($d = $gridStart; $d <= $gridEnd; $d = $d->add(new DateInterval('P1D'))) {
    $key = $d->format('Y-m-d');
    $dates[] = [
        'date' => $d,
        'count' => isset($counts[$key]) ? (int) $counts[$key] : 0,
        'in_range' => ($d >= $start && $d <= $end),
        'is_future' => ($d > $end),
    ];
}
?>

<?php include '../layouts/main.php'; ?>

<head>
    <title>Profile | E-Record</title>
    <?php include '../layouts/title-meta.php'; ?>
    <?php include '../layouts/head-css.php'; ?>
    <style>
        .heatmap-wrap { overflow-x: auto; padding-bottom: 4px; }
        .heatmap-grid { display: grid; grid-auto-flow: column; grid-template-rows: repeat(7, 12px); gap: 3px; }
        .heat-cell { width: 12px; height: 12px; border-radius: 2px; background: #ebedf0; border: 1px solid rgba(0,0,0,0.05); }
        .heat-cell.level-1 { background: #9be9a8; }
        .heat-cell.level-2 { background: #40c463; }
        .heat-cell.level-3 { background: #30a14e; }
        .heat-cell.level-4 { background: #216e39; }
        .heat-cell.out { opacity: 0.45; }
        .heat-legend { display: flex; gap: 6px; align-items: center; }
        .heat-legend .box { width: 12px; height: 12px; border-radius: 2px; border: 1px solid rgba(0,0,0,0.05); }
        .heat-legend .l0 { background: #ebedf0; }
        .heat-legend .l1 { background: #9be9a8; }
        .heat-legend .l2 { background: #40c463; }
        .heat-legend .l3 { background: #30a14e; }
        .heat-legend .l4 { background: #216e39; }
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
                                    <li class="breadcrumb-item active">Profile</li>
                                </ol>
                            </div>
                            <h4 class="page-title">Profile</h4>
                        </div>
                    </div>
                </div>

                <?php if ($flash): ?>
                    <div class="alert alert-<?php echo h($flashType); ?>"><?php echo h($flash); ?></div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-xl-4 col-lg-5">
                        <div class="card text-center">
                            <div class="card-body">
                                <img src="<?php echo h($avatar); ?>" class="rounded-circle avatar-lg img-thumbnail" alt="profile-image">
                                <h4 class="mb-1 mt-2"><?php echo h($fullName); ?></h4>
                                <p class="text-muted mb-1"><?php echo h($role !== '' ? ucfirst($role) : 'User'); ?></p>
                                <?php if ($pending): ?>
                                    <span class="badge bg-warning-subtle text-warning">Pending Profile Update</span>
                                <?php endif; ?>

                                <div class="d-flex justify-content-center gap-2 mt-2 flex-wrap">
                                    <a href="pages-profile.php?tab=settings" class="btn btn-success btn-sm">
                                        <i class="ri-edit-2-line me-1"></i>Edit (Admin Approval)
                                    </a>
                                    <a href="messages.php" class="btn btn-danger btn-sm">
                                        <i class="ri-message-3-line me-1"></i>Messages
                                    </a>
                                </div>

                                <div class="text-start mt-3">
                                    <h4 class="fs-13 text-uppercase">About Me</h4>
                                    <p class="text-muted mb-3"><?php echo h((string) ($me['bio'] ?? '') ?: 'No bio yet.'); ?></p>
                                    <p class="text-muted mb-2"><strong>Email :</strong> <span class="ms-2"><?php echo h($email ?: 'N/A'); ?></span></p>
                                    <p class="text-muted mb-2"><strong>Mobile :</strong> <span class="ms-2"><?php echo h((string) ($me['phone'] ?? '') ?: 'N/A'); ?></span></p>
                                    <p class="text-muted mb-2"><strong>Location :</strong> <span class="ms-2"><?php echo h((string) ($me['location'] ?? '') ?: 'N/A'); ?></span></p>
                                    <p class="text-muted mb-0"><strong>GitHub :</strong> <span class="ms-2">
                                        <?php if (!empty($me['github_username'])): ?>
                                            <a href="<?php echo h('https://github.com/' . trim((string) $me['github_username'])); ?>" target="_blank" rel="noreferrer"><?php echo h((string) $me['github_username']); ?></a>
                                        <?php else: ?>N/A<?php endif; ?>
                                    </span></p>
                                </div>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h4 class="header-title">Messages</h4>
                                    <a class="text-muted small" href="messages.php">View all</a>
                                </div>
                                <div class="inbox-widget">
                                    <?php if (count($threads) === 0): ?>
                                        <div class="text-muted">No conversations yet.</div>
                                    <?php endif; ?>
                                    <?php foreach ($threads as $t): ?>
                                        <?php
                                        $tid = (int) ($t['thread_id'] ?? 0);
                                        $other = trim((string) ($t['other_first_name'] ?? '') . ' ' . (string) ($t['other_last_name'] ?? ''));
                                        if ($other === '') $other = (string) ($t['other_username'] ?? 'User');
                                        $pic = avatar_url($t['other_profile_picture'] ?? '');
                                        $txt = trim((string) ($t['last_body'] ?? ''));
                                        if ($txt === '') $txt = 'No messages yet.';
                                        if (strlen($txt) > 42) $txt = substr($txt, 0, 42) . '...';
                                        ?>
                                        <div class="inbox-item">
                                            <div class="inbox-item-img"><img src="<?php echo h($pic); ?>" class="rounded-circle" alt=""></div>
                                            <p class="inbox-item-author"><?php echo h($other); ?></p>
                                            <p class="inbox-item-text"><?php echo h($txt); ?></p>
                                            <p class="inbox-item-date">
                                                <a href="messages.php?thread_id=<?php echo (int) $tid; ?>" class="btn btn-sm btn-link text-info fs-13">Reply</a>
                                            </p>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-8 col-lg-7">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                    <div>
                                        <h4 class="header-title mb-0">History Log</h4>
                                        <div class="text-muted small">Last 12 months (audit events).</div>
                                    </div>
                                    <div class="heat-legend text-muted small">
                                        <span>Less</span>
                                        <span class="box l0"></span><span class="box l1"></span><span class="box l2"></span><span class="box l3"></span><span class="box l4"></span>
                                        <span>More</span>
                                    </div>
                                </div>
                                <div class="heatmap-wrap mt-3">
                                    <div class="heatmap-grid" aria-label="Activity heatmap">
                                        <?php foreach ($dates as $cell): ?>
                                            <?php
                                            $c = (int) $cell['count'];
                                            $lvl = heat_level($c);
                                            $d = $cell['date'];
                                            $label = $c . ' ' . ($c === 1 ? 'activity' : 'activities') . ' on ' . $d->format('M j, Y');
                                            $cls = 'heat-cell level-' . $lvl;
                                            if (!$cell['in_range'] || $cell['is_future']) $cls .= ' out';
                                            ?>
                                            <div class="<?php echo h($cls); ?>" title="<?php echo h($label); ?>"></div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="card">
                            <div class="card-body">
                                <ul class="nav nav-pills bg-nav-pills nav-justified mb-3">
                                    <li class="nav-item">
                                        <a href="pages-profile.php?tab=about" class="nav-link rounded-start rounded-0 <?php echo $activeTab === 'about' ? 'active' : ''; ?>">About</a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="pages-profile.php?tab=timeline" class="nav-link rounded-0 <?php echo $activeTab === 'timeline' ? 'active' : ''; ?>">Timeline</a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="pages-profile.php?tab=settings" class="nav-link rounded-end rounded-0 <?php echo $activeTab === 'settings' ? 'active' : ''; ?>">Settings</a>
                                    </li>
                                </ul>

                                <div class="tab-content">
                                    <div class="tab-pane <?php echo $activeTab === 'about' ? 'show active' : ''; ?>">
                                        <h5 class="text-uppercase mb-3"><i class="ri-account-circle-line me-1"></i> Profile</h5>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="text-muted mb-2"><strong>Username:</strong> <?php echo h((string) ($me['username'] ?? '')); ?></div>
                                                <div class="text-muted mb-2"><strong>Email:</strong> <?php echo h($email ?: 'N/A'); ?></div>
                                                <div class="text-muted mb-2"><strong>Role:</strong> <?php echo h($role !== '' ? ucfirst($role) : 'User'); ?></div>
                                                <?php if ($role === 'teacher'): ?>
                                                    <div class="text-muted mb-2">
                                                        <strong>Program Chair by Subject:</strong>
                                                        <?php if (count($teacherSubjectProgramChairMap) === 0): ?>
                                                            <span class="d-block small">No subject found.</span>
                                                        <?php else: ?>
                                                            <?php foreach ($teacherSubjectProgramChairMap as $subjectAssignment): ?>
                                                                <?php
                                                                $subjectLabel = trim((string) ($subjectAssignment['subject_label'] ?? 'Subject'));
                                                                $subjectChairName = trim((string) ($subjectAssignment['program_chair_display_name'] ?? ''));
                                                                $subjectChairEmail = trim((string) ($subjectAssignment['program_chair_email'] ?? ''));
                                                                if ($subjectChairName === '') $subjectChairName = 'Not assigned';
                                                                if ($subjectChairEmail !== '' && $subjectChairName !== 'Not assigned') {
                                                                    $subjectChairName .= ' (' . $subjectChairEmail . ')';
                                                                }
                                                                ?>
                                                                <span class="d-block small"><?php echo h($subjectLabel); ?>: <?php echo h($subjectChairName); ?></span>
                                                            <?php endforeach; ?>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php elseif ($currentProgramChairUserId > 0): ?>
                                                    <div class="text-muted mb-2">
                                                        <strong>Program Chair:</strong>
                                                        <?php
                                                        $pcLabel = $currentProgramChairDisplay !== '' ? $currentProgramChairDisplay : 'Not assigned';
                                                        if ($currentProgramChairEmail !== '') $pcLabel .= ' (' . $currentProgramChairEmail . ')';
                                                        echo h($pcLabel);
                                                        ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="text-muted mb-2"><strong>Created:</strong> <?php echo h((string) ($me['created_at'] ?? '')); ?></div>
                                                <div class="text-muted mb-2"><strong>Updated:</strong> <?php echo h((string) ($me['updated_at'] ?? '')); ?></div>
                                            </div>
                                        </div>
                                        <?php if ($pending): ?>
                                            <div class="alert alert-warning mb-0">
                                                <div class="fw-semibold">Pending profile update request</div>
                                                <div class="small">Submitted: <?php echo h((string) ($pending['requested_at'] ?? '')); ?></div>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="tab-pane <?php echo $activeTab === 'timeline' ? 'show active' : ''; ?>">
                                        <h5 class="text-uppercase mb-3"><i class="ri-history-line me-1"></i> History & Audit Log</h5>
                                        <?php if (count($auditRows) === 0): ?>
                                            <div class="text-muted">No audit events yet.</div>
                                        <?php else: ?>
                                            <div class="timeline-alt pb-0">
                                                <?php foreach ($auditRows as $a): ?>
                                                    <?php
                                                    $action = (string) ($a['action'] ?? '');
                                                    $when = (string) ($a['created_at'] ?? '');
                                                    $badge = 'text-bg-secondary';
                                                    if (strpos($action, 'auth.') === 0) $badge = 'text-bg-info';
                                                    if (strpos($action, 'message.') === 0) $badge = 'text-bg-primary';
                                                    if (strpos($action, 'profile.change.') === 0) $badge = 'text-bg-warning';
                                                    ?>
                                                    <div class="timeline-item">
                                                        <i class="ri-record-circle-line <?php echo h($badge); ?> timeline-icon"></i>
                                                        <div class="timeline-item-info">
                                                            <h5 class="mt-0 mb-1"><?php echo h($action !== '' ? $action : 'activity'); ?></h5>
                                                            <p class="fs-14 text-muted mb-0"><?php echo h($when); ?></p>
                                                            <?php if (!empty($a['message'])): ?>
                                                                <p class="text-muted mt-2 mb-0"><?php echo h((string) $a['message']); ?></p>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="tab-pane <?php echo $activeTab === 'settings' ? 'show active' : ''; ?>">
                                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                                            <h5 class="text-uppercase mb-0"><i class="ri-settings-4-line me-1"></i> Profile Settings</h5>
                                            <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#changePasswordModal">
                                                <i class="ri-lock-password-line me-1"></i>Change Password
                                            </button>
                                        </div>
                                        <div class="text-muted small mb-3">Profile changes require admin approval. Password changes apply immediately.</div>

                                        <?php if ($pending): ?>
                                            <?php
                                            $payload = json_decode((string) ($pending['payload_json'] ?? ''), true);
                                            if (!is_array($payload)) $payload = [];
                                            ?>
                                            <div class="alert alert-warning">
                                                <div class="fw-semibold">Pending request</div>
                                                <div class="small">Submitted: <?php echo h((string) ($pending['requested_at'] ?? '')); ?></div>
                                                <?php if (!empty($pending['staged_avatar_path'])): ?>
                                                    <img src="<?php echo h((string) $pending['staged_avatar_path']); ?>" class="rounded-circle img-thumbnail mt-2" alt="pending avatar" style="width: 72px; height: 72px; object-fit: cover;">
                                                <?php endif; ?>
                                                <div class="small mt-2">
                                                    <?php foreach ($payload as $k => $v): ?>
                                                        <?php
                                                        if ($k === 'program_chair_user_id') {
                                                            $pcId = (int) $v;
                                                            $pcText = 'Not assigned';
                                                            if ($pcId > 0) {
                                                                if (isset($programChairOptionMap[$pcId])) {
                                                                    $opt = $programChairOptionMap[$pcId];
                                                                    $pcText = trim((string) ($opt['display_name'] ?? 'Program Chair'));
                                                                    $optEmail = trim((string) ($opt['useremail'] ?? ''));
                                                                    if ($optEmail !== '') $pcText .= ' (' . $optEmail . ')';
                                                                } else {
                                                                    $pcText = 'User #' . $pcId;
                                                                }
                                                            }
                                                            ?>
                                                            <div><strong>Program Chair:</strong> <?php echo h($pcText); ?></div>
                                                            <?php
                                                            continue;
                                                        }
                                                        if ($k === 'subject_program_chair_map') {
                                                            $subjectProgramChairRows = is_array($v) ? $v : [];
                                                            ?>
                                                            <div><strong>Program Chair by Subject:</strong></div>
                                                            <?php if (count($subjectProgramChairRows) === 0): ?>
                                                                <div class="ms-3">No subject mapping submitted.</div>
                                                            <?php else: ?>
                                                                <?php foreach ($subjectProgramChairRows as $subjectProgramChairRow): ?>
                                                                    <?php
                                                                    if (!is_array($subjectProgramChairRow)) continue;
                                                                    $subjectLabel = trim((string) ($subjectProgramChairRow['subject_label'] ?? 'Subject'));
                                                                    $subjectPcId = (int) ($subjectProgramChairRow['program_chair_user_id'] ?? 0);
                                                                    $subjectPcText = 'Not assigned';
                                                                    if ($subjectPcId > 0) {
                                                                        if (isset($programChairOptionMap[$subjectPcId])) {
                                                                            $subjectOpt = $programChairOptionMap[$subjectPcId];
                                                                            $subjectPcText = trim((string) ($subjectOpt['display_name'] ?? 'Program Chair'));
                                                                            $subjectPcEmail = trim((string) ($subjectOpt['useremail'] ?? ''));
                                                                            if ($subjectPcEmail !== '') $subjectPcText .= ' (' . $subjectPcEmail . ')';
                                                                        } else {
                                                                            $subjectPcText = 'User #' . $subjectPcId;
                                                                        }
                                                                    }
                                                                    ?>
                                                                    <div class="ms-3"><strong><?php echo h($subjectLabel); ?>:</strong> <?php echo h($subjectPcText); ?></div>
                                                                <?php endforeach; ?>
                                                            <?php endif; ?>
                                                            <?php
                                                            continue;
                                                        }
                                                        if ($v === null || (is_string($v) && trim($v) === '')) continue;
                                                        ?>
                                                        <div><strong><?php echo h($k); ?>:</strong> <?php echo h(is_scalar($v) ? (string) $v : json_encode($v)); ?></div>
                                                    <?php endforeach; ?>
                                                </div>
                                                <form method="post" class="mt-3">
                                                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                                                    <input type="hidden" name="action" value="cancel_change_request">
                                                    <input type="hidden" name="request_id" value="<?php echo (int) ($pending['id'] ?? 0); ?>">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm" onclick="return confirm('Cancel your pending request?');">
                                                        <i class="ri-close-line me-1"></i>Cancel Request
                                                    </button>
                                                    <?php if ($role === 'admin'): ?>
                                                        <a class="btn btn-sm btn-outline-primary ms-2" href="admin-profile-approvals.php">
                                                            <i class="ri-shield-user-line me-1"></i>Open Approvals
                                                        </a>
                                                    <?php endif; ?>
                                                </form>
                                            </div>
                                        <?php else: ?>
                                            <form method="post" enctype="multipart/form-data">
                                                <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                                                <input type="hidden" name="action" value="submit_change_request">

                                                <div class="row">
                                                    <div class="col-md-6 mb-3">
                                                        <label class="form-label">First Name</label>
                                                        <input type="text" class="form-control" name="first_name" maxlength="50" value="<?php echo h((string) ($me['first_name'] ?? '')); ?>" required>
                                                    </div>
                                                    <div class="col-md-6 mb-3">
                                                        <label class="form-label">Last Name</label>
                                                        <input type="text" class="form-control" name="last_name" maxlength="50" value="<?php echo h((string) ($me['last_name'] ?? '')); ?>" required>
                                                    </div>
                                                </div>

                                                <div class="mb-3">
                                                    <label class="form-label">Bio</label>
                                                    <textarea class="form-control" name="bio" rows="4" maxlength="2000"><?php echo h((string) ($me['bio'] ?? '')); ?></textarea>
                                                </div>

                                                <div class="row">
                                                    <div class="col-md-6 mb-3">
                                                        <label class="form-label">Mobile</label>
                                                        <input type="text" class="form-control" name="phone" maxlength="50" value="<?php echo h((string) ($me['phone'] ?? '')); ?>">
                                                    </div>
                                                    <div class="col-md-6 mb-3">
                                                        <label class="form-label">Location</label>
                                                        <input type="text" class="form-control" name="location" maxlength="120" value="<?php echo h((string) ($me['location'] ?? '')); ?>">
                                                    </div>
                                                </div>

                                                <?php if ($role === 'teacher'): ?>
                                                    <div class="mb-3">
                                                        <label class="form-label">Program Chair by Subject</label>
                                                        <?php if (count($teacherSubjectProgramChairMap) === 0): ?>
                                                            <div class="text-muted small">No subject found for this account yet.</div>
                                                        <?php else: ?>
                                                            <div class="d-flex flex-column gap-2">
                                                                <?php foreach ($teacherSubjectProgramChairMap as $subjectRow): ?>
                                                                    <?php
                                                                    if (!is_array($subjectRow)) continue;
                                                                    $subjectLabel = trim((string) ($subjectRow['subject_label'] ?? ''));
                                                                    if ($subjectLabel === '') continue;
                                                                    $subjectProgramChairId = (int) ($subjectRow['program_chair_user_id'] ?? 0);
                                                                    ?>
                                                                    <div class="border rounded p-2">
                                                                        <div class="small text-muted mb-1"><?php echo h($subjectLabel); ?></div>
                                                                        <input type="hidden" name="subject_program_chair_subject[]" value="<?php echo h($subjectLabel); ?>">
                                                                        <select class="form-select form-select-sm" name="subject_program_chair_user_id[]">
                                                                            <option value="">Not assigned</option>
                                                                            <?php foreach ($programChairOptions as $pc): ?>
                                                                                <?php
                                                                                $pcId = (int) ($pc['id'] ?? 0);
                                                                                if ($pcId <= 0) continue;
                                                                                $pcLabel = trim((string) ($pc['display_name'] ?? 'Program Chair'));
                                                                                $pcEmail = trim((string) ($pc['useremail'] ?? ''));
                                                                                if ($pcEmail !== '') $pcLabel .= ' (' . $pcEmail . ')';
                                                                                ?>
                                                                                <option value="<?php echo $pcId; ?>" <?php echo $pcId === $subjectProgramChairId ? 'selected' : ''; ?>>
                                                                                    <?php echo h($pcLabel); ?>
                                                                                </option>
                                                                            <?php endforeach; ?>
                                                                        </select>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        <div class="text-muted small mt-1">Any change is submitted for admin approval first.</div>
                                                        <?php if (count($programChairOptions) === 0): ?>
                                                            <div class="text-warning small mt-1">No active Program Chair account is available yet.</div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>

                                                <div class="row">
                                                    <div class="col-md-6 mb-3">
                                                        <label class="form-label">GitHub Username</label>
                                                        <input type="text" class="form-control" name="github_username" maxlength="80" value="<?php echo h((string) ($me['github_username'] ?? '')); ?>">
                                                    </div>
                                                    <div class="col-md-6 mb-3">
                                                        <label class="form-label">Profile Picture</label>
                                                        <input class="form-control" type="file" name="avatar" accept="image/*">
                                                        <div class="text-muted small mt-1">Optional. Max 2MB.</div>
                                                    </div>
                                                </div>

                                                <div class="text-end">
                                                    <button type="submit" class="btn btn-success">
                                                        <i class="ri-send-plane-2-line me-1"></i>Submit for Approval
                                                    </button>
                                                </div>
                                            </form>
                                        <?php endif; ?>
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

<div class="modal fade" id="changePasswordModal" tabindex="-1" aria-labelledby="changePasswordModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" autocomplete="off">
                <div class="modal-header">
                    <h5 class="modal-title" id="changePasswordModalLabel">Change Password</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="action" value="change_password">

                    <div class="mb-3">
                        <label class="form-label" for="profileCurrentPassword">Current Password</label>
                        <input type="password" class="form-control" id="profileCurrentPassword" name="current_password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="profileNewPassword">New Password</label>
                        <input type="password" class="form-control" id="profileNewPassword" name="new_password" minlength="8" required>
                        <div class="form-text">Minimum 8 characters.</div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label" for="profileConfirmPassword">Confirm New Password</label>
                        <input type="password" class="form-control" id="profileConfirmPassword" name="confirm_password" minlength="8" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Password</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="assets/js/app.min.js"></script>
</body>
</html>
