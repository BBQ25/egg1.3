<?php
$userRole = isset($_SESSION['user_role']) ? normalize_role($_SESSION['user_role']) : '';
$u = (isset($conn) && $conn instanceof mysqli) ? current_user_row($conn) : null;
$displayName = $u ? current_user_display_name($u) : (isset($_SESSION['user_name']) ? $_SESSION['user_name'] : (isset($_SESSION['student_name']) ? $_SESSION['student_name'] : 'Account'));
$displayId = $u && !empty($u['useremail']) ? $u['useremail'] : (isset($_SESSION['user_email']) ? $_SESSION['user_email'] : (isset($_SESSION['student_no']) ? $_SESSION['student_no'] : 'ID No.'));
$avatarUrl = $u ? current_user_avatar_url($u['profile_picture'] ?? '') : 'assets/images/users/avatar-1.jpg';
$logoVersion = '1';
$logoFile = __DIR__ . '/../assets/images/logo.png';
if (is_file($logoFile)) {
    $logoVersion = (string) filemtime($logoFile);
}

$notificationData = ['items' => [], 'groups' => [], 'unread_count' => 0, 'has_more' => false];
$notificationUnreadCount = 0;
$notificationGroups = [];
$notificationHasMore = false;
$notificationViewAllHref = ($userRole === 'admin') ? 'admin-audit-log.php' : 'pages-profile.php?tab=timeline';

if (isset($conn) && $conn instanceof mysqli && isset($_SESSION['user_id']) && (int) $_SESSION['user_id'] > 0) {
    $notifPath = __DIR__ . '/../includes/notifications.php';
    if (is_file($notifPath)) {
        require_once $notifPath;
        if (function_exists('notification_fetch_for_user')) {
            $notificationData = notification_fetch_for_user($conn, (int) $_SESSION['user_id'], $userRole, 12);
        }
    }
}

$notificationUnreadCount = (int) ($notificationData['unread_count'] ?? 0);
$notificationGroups = is_array($notificationData['groups'] ?? null) ? $notificationData['groups'] : [];
$notificationHasMore = !empty($notificationData['has_more']);
$notificationCsrf = function_exists('csrf_token') ? (string) csrf_token() : '';
?>
<style>
.notification-list .noti-icon-badge.noti-count-badge {
    top: 12px;
    right: -2px;
    min-width: 18px;
    height: 18px;
    padding: 0 4px;
    border-radius: 999px;
    background-color: var(--ct-danger);
    color: #fff;
    border: 1px solid #fff;
    box-shadow: 0 2px 6px rgba(17, 24, 39, 0.28);
    font-size: 11px;
    font-weight: 700;
    line-height: 1;
}
.doc-topbar-live-box {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    min-width: 190px;
    padding: 6px 10px;
    border: 1px solid var(--ct-border-color);
    border-radius: 10px;
    background: var(--ct-topbar-search-bg);
    color: var(--ct-topbar-item-color);
}
.doc-topbar-live-icon {
    width: 28px;
    height: 28px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 999px;
    background: rgba(var(--ct-primary-rgb), 0.14);
    color: var(--ct-primary);
    flex: 0 0 auto;
}
.doc-topbar-live-icon i {
    font-size: 16px;
    line-height: 1;
}
.doc-topbar-live-lines {
    display: inline-flex;
    flex-direction: column;
    line-height: 1.15;
}
.doc-topbar-live-date {
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.02em;
}
.doc-topbar-live-time {
    font-size: 11px;
    opacity: 0.82;
}
</style>
<div class="navbar-custom">
    <div class="topbar container-fluid">
        <div class="d-flex align-items-center gap-lg-2 gap-1">

           
            <div class="logo-topbar">
                
                <a href="index.php" class="logo-light">
                    <span class="logo-lg">
                        <img src="assets/images/logo.png?v=<?php echo urlencode($logoVersion); ?>" alt="logo" class="brand-logo-top-lg">
                    </span>
                    <span class="logo-sm">
                        <img src="assets/images/logo-sm.png?v=<?php echo urlencode($logoVersion); ?>" alt="small logo" class="brand-logo-top-sm">
                    </span>
                </a>

                
                <a href="index.php" class="logo-dark">
                    <span class="logo-lg">
                        <img src="assets/images/logo-dark.png?v=<?php echo urlencode($logoVersion); ?>" alt="dark logo" class="brand-logo-top-lg">
                    </span>
                    <span class="logo-sm">
                        <img src="assets/images/logo-sm.png?v=<?php echo urlencode($logoVersion); ?>" alt="small logo" class="brand-logo-top-sm">
                    </span>
                </a>
            </div>

            
            <button class="button-toggle-menu">
                <i class="ri-menu-2-fill"></i>
            </button>

            
            <button class="navbar-toggle" data-bs-toggle="collapse" data-bs-target="#topnav-menu-content">
                <div class="lines">
                    <span></span>
                    <span></span>
                    <span></span>
                </div>
            </button>

            
            <div class="app-search dropdown d-none d-lg-block">
                <form>
                    <div class="input-group">
                        <input type="search" class="form-control dropdown-toggle" placeholder="Search..." id="top-search">
                        <span class="ri-search-line search-icon"></span>
                    </div>
                </form>

                <div class="dropdown-menu dropdown-menu-animated dropdown-lg" id="search-dropdown">
                    
                    <div class="dropdown-header noti-title">
                        <h5 class="text-overflow mb-1">Found <b class="text-decoration-underline">08</b> results</h5>
                    </div>

                    
                    <a href="javascript:void(0);" class="dropdown-item notify-item">
                        <i class="ri-file-chart-line fs-16 me-1"></i>
                        <span>Analytics Report</span>
                    </a>

                    
                    <a href="javascript:void(0);" class="dropdown-item notify-item">
                        <i class="ri-lifebuoy-line fs-16 me-1"></i>
                        <span>How can I help you?</span>
                    </a>

                    
                    <a href="javascript:void(0);" class="dropdown-item notify-item">
                        <i class="ri-user-settings-line fs-16 me-1"></i>
                        <span>Account profile settings</span>
                    </a>

                    
                    <div class="dropdown-header noti-title">
                        <h6 class="text-overflow mt-2 mb-1 text-uppercase">Accounts</h6>
                    </div>

                    <div class="notification-list">
                        
                        <a href="javascript:void(0);" class="dropdown-item notify-item">
                            <div class="d-flex">
                                <img class="d-flex me-2 rounded-circle" src="assets/images/users/avatar-2.jpg" alt="Generic placeholder image" height="32">
                                <div class="w-100">
                                    <h5 class="m-0 fs-14">Erwin Brown</h5>
                                    <span class="fs-12 mb-0">UI Designer</span>
                                </div>
                            </div>
                        </a>

                        
                        <a href="javascript:void(0);" class="dropdown-item notify-item">
                            <div class="d-flex">
                                <img class="d-flex me-2 rounded-circle" src="assets/images/users/avatar-5.jpg" alt="Generic placeholder image" height="32">
                                <div class="w-100">
                                    <h5 class="m-0 fs-14">Jacob Deo</h5>
                                    <span class="fs-12 mb-0">Developer</span>
                                </div>
                            </div>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <ul class="topbar-menu d-flex align-items-center gap-3">
            <li class="dropdown d-lg-none">
                <a class="nav-link dropdown-toggle arrow-none" data-bs-toggle="dropdown" href="#" role="button" aria-haspopup="false" aria-expanded="false">
                    <i class="ri-search-line fs-22"></i>
                </a>
                <div class="dropdown-menu dropdown-menu-animated dropdown-lg p-0">
                    <form class="p-3">
                        <input type="search" class="form-control" placeholder="Search ..." aria-label="Recipient's username">
                    </form>
                </div>
            </li>

            <li class="d-none d-md-flex align-items-center">
                <div class="doc-topbar-live-box" title="Today and live time">
                    <span class="doc-topbar-live-icon" aria-hidden="true">
                        <i class="ri-emotion-happy-line"></i>
                    </span>
                    <span class="doc-topbar-live-lines">
                        <span class="doc-topbar-live-date" id="docTopbarLiveDate">--/--/----</span>
                        <span class="doc-topbar-live-time" id="docTopbarLiveTime">--:--:-- --</span>
                    </span>
                </div>
            </li>

            <li class="dropdown">
                <a class="nav-link dropdown-toggle arrow-none" data-bs-toggle="dropdown" href="#" role="button" aria-haspopup="false" aria-expanded="false">
                    <img src="assets/images/flags/us.jpg" alt="user-image" class="me-0 me-sm-1" height="12">
                    <span class="align-middle d-none d-lg-inline-block">English</span> <i class="ri-arrow-down-s-line d-none d-sm-inline-block align-middle"></i>
                </a>
                <div class="dropdown-menu dropdown-menu-end dropdown-menu-animated">

                    
                    <a href="javascript:void(0);" class="dropdown-item">
                        <img src="assets/images/flags/germany.jpg" alt="user-image" class="me-1" height="12"> <span class="align-middle">German</span>
                    </a>

                    
                    <a href="javascript:void(0);" class="dropdown-item">
                        <img src="assets/images/flags/italy.jpg" alt="user-image" class="me-1" height="12"> <span class="align-middle">Italian</span>
                    </a>

                    
                    <a href="javascript:void(0);" class="dropdown-item">
                        <img src="assets/images/flags/spain.jpg" alt="user-image" class="me-1" height="12"> <span class="align-middle">Spanish</span>
                    </a>

                    
                    <a href="javascript:void(0);" class="dropdown-item">
                        <img src="assets/images/flags/russia.jpg" alt="user-image" class="me-1" height="12"> <span class="align-middle">Russian</span>
                    </a>

                </div>
            </li>

            <li class="dropdown notification-list" id="docEaseNotifRoot">
                <a class="nav-link dropdown-toggle arrow-none" data-bs-toggle="dropdown" href="#" role="button" aria-haspopup="false" aria-expanded="false">
                    <i class="ri-notification-3-line fs-22"></i>
                    <?php if ($notificationUnreadCount > 0): ?>
                        <span class="noti-icon-badge noti-count-badge d-flex align-items-center justify-content-center" title="<?php echo (int) $notificationUnreadCount; ?> unread">
                            <?php echo (int) min(99, $notificationUnreadCount); ?>
                        </span>
                    <?php endif; ?>
                </a>
                <div class="dropdown-menu dropdown-menu-end dropdown-menu-animated dropdown-lg py-0">
                    <div class="p-2 border-top-0 border-start-0 border-end-0 border-dashed border">
                        <div class="row align-items-center">
                            <div class="col">
                                <h6 class="m-0 fs-16 fw-semibold">Notification</h6>
                                <div class="small text-muted">
                                    <?php echo (int) $notificationUnreadCount; ?> unread
                                </div>
                            </div>
                            <div class="col-auto">
                                <a
                                    href="#"
                                    class="text-dark text-decoration-underline<?php echo count($notificationGroups) === 0 ? ' disabled' : ''; ?>"
                                    id="docEaseNotifClearAll"
                                >
                                    <small>Clear All</small>
                                </a>
                            </div>
                        </div>
                    </div>

                    <div style="max-height: 300px;" data-simplebar id="docEaseNotifList">
                        <?php if (count($notificationGroups) === 0): ?>
                            <div class="p-3 text-center text-muted small">No notifications yet.</div>
                        <?php else: ?>
                            <?php foreach ($notificationGroups as $group): ?>
                                <?php
                                $groupLabel = trim((string) ($group['label'] ?? 'OLDER'));
                                $groupItems = is_array($group['items'] ?? null) ? $group['items'] : [];
                                ?>
                                <h5 class="text-muted fs-12 fw-bold p-2 text-uppercase mb-0"><?php echo htmlspecialchars($groupLabel, ENT_QUOTES, 'UTF-8'); ?></h5>
                                <?php foreach ($groupItems as $item): ?>
                                    <?php
                                    $itemTitle = trim((string) ($item['title'] ?? 'Activity'));
                                    $itemSubtitle = trim((string) ($item['subtitle'] ?? ''));
                                    $itemTime = trim((string) ($item['time_ago'] ?? ''));
                                    $itemAction = trim((string) ($item['action_label'] ?? ''));
                                    $itemIcon = trim((string) ($item['icon'] ?? 'ri-information-line'));
                                    $itemIconBg = trim((string) ($item['icon_bg'] ?? 'bg-secondary'));
                                    $itemUnread = !empty($item['is_unread']);
                                    ?>
                                    <a href="<?php echo htmlspecialchars($notificationViewAllHref, ENT_QUOTES, 'UTF-8'); ?>" class="dropdown-item p-0 notify-item <?php echo $itemUnread ? 'unread-noti' : 'read-noti'; ?> card m-0 shadow-none">
                                        <div class="card-body">
                                            <div class="d-flex align-items-center">
                                                <div class="flex-shrink-0">
                                                    <div class="notify-icon <?php echo htmlspecialchars($itemIconBg, ENT_QUOTES, 'UTF-8'); ?>">
                                                        <i class="<?php echo htmlspecialchars($itemIcon, ENT_QUOTES, 'UTF-8'); ?> fs-18"></i>
                                                    </div>
                                                </div>
                                                <div class="flex-grow-1 text-truncate ms-2">
                                                    <h5 class="noti-item-title fw-semibold fs-14">
                                                        <?php echo htmlspecialchars($itemTitle, ENT_QUOTES, 'UTF-8'); ?>
                                                        <?php if ($itemTime !== ''): ?>
                                                            <small class="fw-normal text-muted float-end ms-1"><?php echo htmlspecialchars($itemTime, ENT_QUOTES, 'UTF-8'); ?></small>
                                                        <?php endif; ?>
                                                    </h5>
                                                    <small class="noti-item-subtitle text-muted"><?php echo htmlspecialchars($itemSubtitle !== '' ? $itemSubtitle : $itemAction, ENT_QUOTES, 'UTF-8'); ?></small>
                                                </div>
                                            </div>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <a href="<?php echo htmlspecialchars($notificationViewAllHref, ENT_QUOTES, 'UTF-8'); ?>" class="dropdown-item text-center text-primary text-decoration-underline fw-bold notify-item border-top border-light py-2">
                        View All
                    </a>
                </div>
            </li>

            <li class="dropdown d-none d-sm-inline-block">
                <a class="nav-link dropdown-toggle arrow-none" data-bs-toggle="dropdown" href="#" role="button" aria-haspopup="false" aria-expanded="false">
                    <i class="ri-apps-2-line fs-22"></i>
                </a>
                <div class="dropdown-menu dropdown-menu-end dropdown-menu-animated dropdown-lg p-0">

                    <div class="p-2">
                        <div class="row g-0">
                            <div class="col">
                                <a class="dropdown-icon-item" href="#">
                                    <img src="assets/images/brands/github.png" alt="Github">
                                    <span>GitHub</span>
                                </a>
                            </div>
                            <div class="col">
                                <a class="dropdown-icon-item" href="#">
                                    <img src="assets/images/brands/bitbucket.png" alt="bitbucket">
                                    <span>Bitbucket</span>
                                </a>
                            </div>
                            <div class="col">
                                <a class="dropdown-icon-item" href="#">
                                    <img src="assets/images/brands/dropbox.png" alt="dropbox">
                                    <span>Dropbox</span>
                                </a>
                            </div>
                        </div>

                        <div class="row g-0">
                            <div class="col">
                                <a class="dropdown-icon-item" href="#">
                                    <img src="assets/images/brands/slack.png" alt="slack">
                                    <span>Slack</span>
                                </a>
                            </div>
                            <div class="col">
                                <a class="dropdown-icon-item" href="#">
                                    <img src="assets/images/brands/dribbble.png" alt="dribbble">
                                    <span>Dribbble</span>
                                </a>
                            </div>
                            <div class="col">
                                <a class="dropdown-icon-item" href="#">
                                    <img src="assets/images/brands/behance.png" alt="Behance">
                                    <span>Behance</span>
                                </a>
                            </div>
                        </div> 
                    </div>

                </div>
            </li>

            <li class="d-none d-sm-inline-block">
                <a class="nav-link" data-bs-toggle="offcanvas" href="#theme-settings-offcanvas">
                    <i class="ri-settings-3-line fs-22"></i>
                </a>
            </li>

            <li class="d-none d-sm-inline-block">
                <div class="nav-link" id="light-dark-mode" data-bs-toggle="tooltip" data-bs-placement="left" title="Theme Mode">
                    <i class="ri-moon-line fs-22"></i>
                </div>
            </li>


            <li class="d-none d-md-inline-block">
                <a class="nav-link" href="" data-toggle="fullscreen">
                    <i class="ri-fullscreen-line fs-22"></i>
                </a>
            </li>

            <li class="dropdown">
                <a class="nav-link dropdown-toggle arrow-none nav-user px-2" data-bs-toggle="dropdown" href="#" role="button" aria-haspopup="false" aria-expanded="false">
                    <span class="account-user-avatar">
                        <img src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="user-image" width="32" class="rounded-circle">
                    </span>
                    <span class="d-lg-flex flex-column gap-1 d-none">
                        <h5 class="my-0"><?php echo htmlspecialchars($displayName); ?></h5>
                        <h6 class="my-0 fw-normal"><?php echo htmlspecialchars($displayId); ?></h6>
                    </span>
                </a>
                <div class="dropdown-menu dropdown-menu-end dropdown-menu-animated profile-dropdown">
                    
                    <div class=" dropdown-header noti-title">
                        <h6 class="text-overflow m-0">Welcome !</h6>
                    </div>

                    
                    <a href="pages-profile.php" class="dropdown-item">
                        <i class="ri-account-circle-line fs-18 align-middle me-1"></i>
                        <span>My Account</span>
                    </a>

                    
                    <a href="pages-profile.php" class="dropdown-item">
                        <i class="ri-settings-4-line fs-18 align-middle me-1"></i>
                        <span>Settings</span>
                    </a>

                    
                    <a href="support.php" class="dropdown-item">
                        <i class="ri-customer-service-2-line fs-18 align-middle me-1"></i>
                        <span>Support</span>
                    </a>

                    
                    <a href="auth-lock-screen.php" class="dropdown-item">
                        <i class="ri-lock-password-line fs-18 align-middle me-1"></i>
                        <span>Lock Screen</span>
                    </a>

                    
                    <form action="auth-logout.php" method="post" class="m-0 p-0">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($notificationCsrf, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="reason" value="logout">
                        <button type="submit" class="dropdown-item border-0 bg-transparent w-100 text-start">
                            <i class="ri-logout-box-line fs-18 align-middle me-1"></i>
                            <span>Logout</span>
                        </button>
                    </form>
                </div>
            </li>
        </ul>
    </div>
</div>
<script>
(function () {
    var clearAllBtn = document.getElementById('docEaseNotifClearAll');
    if (!clearAllBtn) return;

    var busy = false;
    var csrf = <?php echo json_encode($notificationCsrf, JSON_UNESCAPED_SLASHES); ?>;
    var endpoint = "includes/notifications_action.php";

    clearAllBtn.addEventListener('click', function (e) {
        e.preventDefault();
        if (busy || clearAllBtn.classList.contains('disabled')) return;
        busy = true;
        clearAllBtn.classList.add('disabled');

        fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                action: 'clear_all',
                csrf_token: csrf
            })
        })
        .then(function (res) { return res.json().catch(function () { return null; }); })
        .then(function (data) {
            if (data && data.status === 'ok') {
                window.location.reload();
                return;
            }
            busy = false;
            clearAllBtn.classList.remove('disabled');
            alert((data && data.message) ? data.message : 'Unable to clear notifications.');
        })
        .catch(function () {
            busy = false;
            clearAllBtn.classList.remove('disabled');
            alert('Unable to clear notifications right now.');
        });
    });
})();

(function () {
    var dateEl = document.getElementById('docTopbarLiveDate');
    var timeEl = document.getElementById('docTopbarLiveTime');
    if (!dateEl || !timeEl) return;

    var tz = 'Asia/Manila';
    var dateFmt = new Intl.DateTimeFormat('en-US', {
        timeZone: tz,
        month: '2-digit',
        day: '2-digit',
        year: 'numeric'
    });
    var timeFmt = new Intl.DateTimeFormat('en-US', {
        timeZone: tz,
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: true
    });

    function refreshClock() {
        var now = new Date();
        dateEl.textContent = dateFmt.format(now);
        timeEl.textContent = timeFmt.format(now);
    }

    refreshClock();
    window.setInterval(refreshClock, 1000);
})();
</script>
