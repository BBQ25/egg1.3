<?php
$userRole = isset($_SESSION['user_role']) ? normalize_role($_SESSION['user_role']) : '';
$u = (isset($conn) && $conn instanceof mysqli) ? current_user_row($conn) : null;
$displayName = $u ? current_user_display_name($u) : (isset($_SESSION['user_name']) ? $_SESSION['user_name'] : (isset($_SESSION['student_name']) ? $_SESSION['student_name'] : 'Account'));
$displayId = $u && !empty($u['useremail']) ? $u['useremail'] : (isset($_SESSION['user_email']) ? $_SESSION['user_email'] : (isset($_SESSION['student_no']) ? $_SESSION['student_no'] : 'ID No.'));
$avatarUrl = $u ? current_user_avatar_url($u['profile_picture'] ?? '') : 'assets/images/users/avatar-1.jpg';
$isActive = !empty($_SESSION['is_active']);
$isSuperadmin = current_user_is_superadmin();
$currentPage = isset($_SERVER['PHP_SELF']) ? basename((string) $_SERVER['PHP_SELF']) : '';
$showReverseClassRecordLink = true;
$reverseClassRecordHelperPath = __DIR__ . '/../includes/reverse_class_record.php';
if (
  $userRole === 'teacher' &&
  isset($conn) &&
  $conn instanceof mysqli &&
  is_file($reverseClassRecordHelperPath)
) {
  require_once $reverseClassRecordHelperPath;
  $teacherUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
  if ($teacherUserId <= 0) {
    $showReverseClassRecordLink = false;
  } elseif (function_exists('reverse_class_record_can_teacher_use')) {
    $showReverseClassRecordLink = reverse_class_record_can_teacher_use($conn, $teacherUserId);
  }
}
$accountMenuPages = ['admin-users-students.php', 'admin-users-teachers.php', 'admin-users.php', 'admin-student-enrollment-details.php'];
$isAccountsMenuOpen = in_array($currentPage, $accountMenuPages, true);
$isStudentAccountsPage = in_array($currentPage, ['admin-users-students.php', 'admin-student-enrollment-details.php'], true);
$schedulingMenuPages = ['admin-schedules.php', 'admin-schedule-approvals.php', 'admin-enrollment-approvals.php'];
$isSchedulingMenuOpen = in_array($currentPage, $schedulingMenuPages, true);
$tutorialMenuPages = ['tutorials.php', 'tutorial-admin.php', 'tutorial-teacher.php', 'tutorial-student.php', 'tutorial-program-chair.php', 'tutorial-enroll-student.php', 'tutorial-users.php'];
$isTutorialMenuOpen = in_array($currentPage, $tutorialMenuPages, true);
$infoPageConfig = [
    'news' => ['href' => 'news.php', 'icon' => 'ri-newspaper-line', 'label' => 'News', 'published' => 1],
    'about' => ['href' => 'about.php', 'icon' => 'ri-information-line', 'label' => 'About', 'published' => 1],
    'support' => ['href' => 'support.php', 'icon' => 'ri-customer-service-2-line', 'label' => 'Support', 'published' => 1],
    'contact' => ['href' => 'contact-us.php', 'icon' => 'ri-mail-send-line', 'label' => 'Contact Us', 'published' => 1],
];
$sitePagesHelperPath = __DIR__ . '/../includes/site_pages.php';
if (isset($conn) && $conn instanceof mysqli && is_file($sitePagesHelperPath)) {
  require_once $sitePagesHelperPath;
  if (function_exists('site_pages_rows')) {
    $pageRows = site_pages_rows($conn);
    foreach ($infoPageConfig as $key => $meta) {
      $row = isset($pageRows[$key]) && is_array($pageRows[$key]) ? $pageRows[$key] : [];
      if ($row) {
        $label = trim((string) ($row['nav_label'] ?? ''));
        if ($label !== '') $infoPageConfig[$key]['label'] = $label;
        $infoPageConfig[$key]['published'] = ((int) ($row['is_published'] ?? 0) === 1) ? 1 : 0;
      }
    }
  }
}
$logoVersion = '1';
$logoFile = __DIR__ . '/../assets/images/logo.png';
if (is_file($logoFile)) {
  $logoVersion = (string) filemtime($logoFile);
}
$sidebarCategoryClassMap = [
  'Navigation' => 'category-badge-navigation',
  'Tutorial' => 'category-badge-tutorial',
  'Learning' => 'category-badge-learning',
  'Settings' => 'category-badge-settings',
  'Info' => 'category-badge-info',
  'Apps' => 'category-badge-apps',
  'Classes' => 'category-badge-classes',
];
$renderSidebarCategory = static function (string $label) use ($sidebarCategoryClassMap): void {
  $class = isset($sidebarCategoryClassMap[$label]) ? $sidebarCategoryClassMap[$label] : 'category-badge-default';
  echo '<li class="side-nav-title side-nav-title-badge"><span class="category-badge ' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span></li>';
};
?>
<style>
  .leftside-menu .side-nav-title.side-nav-title-badge {
    opacity: 1;
    margin: 8px 0 6px;
    padding: 0 16px;
    line-height: 1;
  }
  .leftside-menu .side-nav-title.side-nav-title-badge .category-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 999px;
    color: rgba(255, 255, 255, 0.88);
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    border: 1px solid rgba(255, 255, 255, 0.14);
  }
  .leftside-menu .category-badge-navigation {
    background: linear-gradient(90deg, rgba(37, 99, 235, 0.24), rgba(56, 189, 248, 0.16));
    border-color: rgba(96, 165, 250, 0.26);
  }
  .leftside-menu .category-badge-tutorial {
    background: linear-gradient(90deg, rgba(109, 40, 217, 0.22), rgba(139, 92, 246, 0.16));
    border-color: rgba(167, 139, 250, 0.24);
  }
  .leftside-menu .category-badge-learning {
    background: linear-gradient(90deg, rgba(8, 145, 178, 0.22), rgba(14, 165, 233, 0.16));
    border-color: rgba(103, 232, 249, 0.22);
  }
  .leftside-menu .category-badge-settings {
    background: linear-gradient(90deg, rgba(180, 83, 9, 0.24), rgba(234, 88, 12, 0.16));
    border-color: rgba(251, 191, 36, 0.24);
  }
  .leftside-menu .category-badge-info {
    background: linear-gradient(90deg, rgba(15, 118, 110, 0.22), rgba(20, 184, 166, 0.15));
    border-color: rgba(94, 234, 212, 0.22);
  }
  .leftside-menu .category-badge-apps {
    background: linear-gradient(90deg, rgba(77, 124, 15, 0.24), rgba(101, 163, 13, 0.16));
    border-color: rgba(163, 230, 53, 0.24);
  }
  .leftside-menu .category-badge-classes {
    background: linear-gradient(90deg, rgba(157, 23, 77, 0.24), rgba(225, 29, 72, 0.16));
    border-color: rgba(251, 113, 133, 0.24);
  }
  .leftside-menu .category-badge-default {
    background: linear-gradient(90deg, rgba(51, 65, 85, 0.28), rgba(71, 85, 105, 0.2));
    border-color: rgba(148, 163, 184, 0.22);
  }
  .leftside-menu .side-nav .side-nav-link.active {
    background: rgba(62, 96, 213, 0.14);
    border-left: 3px solid var(--ct-menu-item-active-color);
    padding-left: calc(var(--ct-menu-item-padding-x) - 3px);
    color: var(--ct-menu-item-active-color) !important;
    font-weight: 600;
  }
  .leftside-menu .side-nav .side-nav-link.active i {
    color: inherit;
  }
  .leftside-menu .side-nav-second-level li.active > a,
  .leftside-menu .side-nav-second-level .side-nav-item.active > a,
  .leftside-menu .side-nav-second-level li a.active {
    color: var(--ct-menu-item-active-color) !important;
    font-weight: 700;
    background: rgba(62, 96, 213, 0.1);
    border-radius: 8px;
  }
  html[data-menu-color="dark"] .leftside-menu .side-nav .side-nav-link.active,
  html[data-menu-color="brand"] .leftside-menu .side-nav .side-nav-link.active,
  html[data-bs-theme="dark"] .leftside-menu .side-nav .side-nav-link.active {
    background: rgba(255, 255, 255, 0.14);
    color: var(--ct-menu-item-active-color) !important;
  }
  html[data-menu-color="dark"] .leftside-menu .side-nav-second-level li.active > a,
  html[data-menu-color="dark"] .leftside-menu .side-nav-second-level .side-nav-item.active > a,
  html[data-menu-color="dark"] .leftside-menu .side-nav-second-level li a.active,
  html[data-menu-color="brand"] .leftside-menu .side-nav-second-level li.active > a,
  html[data-menu-color="brand"] .leftside-menu .side-nav-second-level .side-nav-item.active > a,
  html[data-menu-color="brand"] .leftside-menu .side-nav-second-level li a.active,
  html[data-bs-theme="dark"] .leftside-menu .side-nav-second-level li.active > a,
  html[data-bs-theme="dark"] .leftside-menu .side-nav-second-level .side-nav-item.active > a,
  html[data-bs-theme="dark"] .leftside-menu .side-nav-second-level li a.active {
    background: rgba(255, 255, 255, 0.1);
  }
  body.sidebar-parallax-enter .leftside-menu .side-nav,
  body.sidebar-parallax-enter .content-page .content,
  body.sidebar-parallax-exit .leftside-menu .side-nav,
  body.sidebar-parallax-exit .content-page .content {
    will-change: transform, opacity;
  }
  body.sidebar-parallax-enter .leftside-menu .side-nav {
    transform: translateY(10px);
    opacity: 0.86;
  }
  body.sidebar-parallax-enter .content-page .content {
    transform: translateY(14px);
    opacity: 0.9;
  }
  body.sidebar-parallax-enter.sidebar-parallax-enter-active .leftside-menu .side-nav,
  body.sidebar-parallax-enter.sidebar-parallax-enter-active .content-page .content {
    transform: translateY(0);
    opacity: 1;
    transition: transform 220ms ease, opacity 220ms ease;
  }
  body.sidebar-parallax-exit .leftside-menu .side-nav {
    transform: translateY(-8px);
    opacity: 0.9;
    transition: transform 170ms ease, opacity 170ms ease;
  }
  body.sidebar-parallax-exit .content-page .content {
    transform: translateY(10px);
    opacity: 0.94;
    transition: transform 170ms ease, opacity 170ms ease;
  }
  @media (prefers-reduced-motion: reduce) {
    body.sidebar-parallax-enter .leftside-menu .side-nav,
    body.sidebar-parallax-enter .content-page .content,
    body.sidebar-parallax-exit .leftside-menu .side-nav,
    body.sidebar-parallax-exit .content-page .content {
      transition: none !important;
      transform: none !important;
      opacity: 1 !important;
    }
  }
</style>
<!-- ========== Left Sidebar Start ========== -->
<div class="leftside-menu">
  <a href="index.php" class="logo logo-light">
    <span class="logo-lg">
      <img src="assets/images/logo.png?v=<?php echo urlencode($logoVersion); ?>" alt="logo" class="brand-logo-lg" />
    </span>
    <span class="logo-sm">
      <img src="assets/images/logo-sm.png?v=<?php echo urlencode($logoVersion); ?>" alt="small logo" class="brand-logo-sm" />
    </span>
  </a>

  <a href="index.php" class="logo logo-dark">
    <span class="logo-lg">
      <img src="assets/images/logo-dark.png?v=<?php echo urlencode($logoVersion); ?>" alt="dark logo" class="brand-logo-lg" />
    </span>
    <span class="logo-sm">
      <img src="assets/images/logo-sm.png?v=<?php echo urlencode($logoVersion); ?>" alt="small logo" class="brand-logo-sm" />
    </span>
  </a>

  <div
    class="button-sm-hover"
    data-bs-toggle="tooltip"
    data-bs-placement="right"
    title="Show Full Sidebar"
  >
    <i class="ri-checkbox-blank-circle-line align-middle"></i>
  </div>

  <div class="button-close-fullsidebar">
    <i class="ri-close-fill align-middle"></i>
  </div>

  <div class="h-100" id="leftside-menu-container" data-simplebar>
    <div class="leftbar-user">
      <a href="pages-profile.php">
        <img
          src="<?php echo htmlspecialchars($avatarUrl); ?>"
          alt="user-image"
          height="42"
          class="rounded-circle shadow-sm"
        />
        <span class="leftbar-user-name mt-2"><?php echo htmlspecialchars($displayName); ?></span>
        <small class="text-muted d-block"><?php echo htmlspecialchars($displayId); ?></small>
      </a>
    </div>

    <ul class="side-nav">
      <?php $renderSidebarCategory('Navigation'); ?>

      <?php if ($userRole === 'admin'): ?>
      <li class="side-nav-item">
        <a href="admin-dashboard.php" class="side-nav-link">
          <i class="ri-shield-user-line"></i>
          <span> Admin Dashboard </span>
        </a>
      </li>
      <li class="side-nav-item">
        <a
          data-bs-toggle="collapse"
          data-bs-target="#sidebarAccounts"
          href="javascript:void(0);"
          aria-expanded="<?php echo $isAccountsMenuOpen ? 'true' : 'false'; ?>"
          aria-controls="sidebarAccounts"
          class="side-nav-link"
        >
          <i class="ri-user-settings-line"></i>
          <span> Accounts </span>
          <span class="menu-arrow"></span>
        </a>
        <div class="collapse<?php echo $isAccountsMenuOpen ? ' show' : ''; ?>" id="sidebarAccounts">
          <ul class="side-nav-second-level">
            <li>
              <a href="admin-users-students.php" class="<?php echo $isStudentAccountsPage ? 'active' : ''; ?>">Student Accounts</a>
            </li>
            <li>
              <a href="admin-users-teachers.php" class="<?php echo $currentPage === 'admin-users-teachers.php' ? 'active' : ''; ?>">Teacher Accounts</a>
            </li>
            <li>
              <a href="admin-users.php" class="<?php echo $currentPage === 'admin-users.php' ? 'active' : ''; ?>">All Accounts</a>
            </li>
          </ul>
        </div>
      </li>
      <li class="side-nav-item">
        <a href="admin-assign-teachers.php" class="side-nav-link">
          <i class="ri-user-follow-line"></i>
          <span> Assign Teachers </span>
        </a>
      </li>
      <li class="side-nav-item">
        <a
          data-bs-toggle="collapse"
          data-bs-target="#sidebarScheduling"
          href="javascript:void(0);"
          aria-expanded="<?php echo $isSchedulingMenuOpen ? 'true' : 'false'; ?>"
          aria-controls="sidebarScheduling"
          class="side-nav-link"
        >
          <i class="ri-calendar-line"></i>
          <span> Scheduling </span>
          <span class="menu-arrow"></span>
        </a>
        <div class="collapse<?php echo $isSchedulingMenuOpen ? ' show' : ''; ?>" id="sidebarScheduling">
          <ul class="side-nav-second-level">
            <li>
              <a href="admin-schedules.php" class="<?php echo $currentPage === 'admin-schedules.php' ? 'active' : ''; ?>">Schedules</a>
            </li>
            <li>
              <a href="admin-schedule-approvals.php" class="<?php echo $currentPage === 'admin-schedule-approvals.php' ? 'active' : ''; ?>">Schedule Approvals</a>
            </li>
            <li>
              <a href="admin-enrollment-approvals.php" class="<?php echo $currentPage === 'admin-enrollment-approvals.php' ? 'active' : ''; ?>">Enrollment Approvals</a>
            </li>
          </ul>
        </div>
      </li>
      <li class="side-nav-item">
        <a href="admin-profile-approvals.php" class="side-nav-link">
          <i class="ri-user-received-2-line"></i>
          <span> Profile Approvals </span>
        </a>
      </li>
      <li class="side-nav-item">
        <a href="admin-audit-log.php" class="side-nav-link">
          <i class="ri-history-line"></i>
          <span> Audit Log </span>
        </a>
      </li>

      <?php $renderSidebarCategory('Apps'); ?>
      <li class="side-nav-item">
        <a href="admin-neighbor-numbers.php" class="side-nav-link<?php echo $currentPage === 'admin-neighbor-numbers.php' ? ' active' : ''; ?>">
          <i class="ri-gamepad-line"></i>
          <span> Neighbor Numbers </span>
        </a>
      </li>
      <li class="side-nav-item">
        <a href="accomplishment-creator.php" class="side-nav-link<?php echo $currentPage === 'accomplishment-creator.php' ? ' active' : ''; ?>">
          <i class="ri-magic-line"></i>
          <span> Accomplishment Creator </span>
        </a>
      </li>
      <li class="side-nav-item">
        <a href="messages.php" class="side-nav-link<?php echo $currentPage === 'messages.php' ? ' active' : ''; ?>">
          <i class="ri-message-3-line"></i>
          <span> Messages </span>
        </a>
      </li>
      <li class="side-nav-item">
        <a href="add-subject.php" class="side-nav-link<?php echo $currentPage === 'add-subject.php' ? ' active' : ''; ?>">
          <i class="ri-book-line"></i>
          <span> Subjects </span>
        </a>
      </li>
      <li class="side-nav-item">
        <a href="section.php" class="side-nav-link<?php echo $currentPage === 'section.php' ? ' active' : ''; ?>">
          <i class="ri-layout-3-line"></i>
          <span> Sections </span>
        </a>
      </li>
      <?php elseif ($userRole === 'teacher' && $isActive): ?>
      <li class="side-nav-item">
        <a href="teacher-dashboard.php" class="side-nav-link">
          <i class="ri-user-star-line"></i>
          <span> Dashboard </span>
        </a>
      </li>
      <?php $renderSidebarCategory('Classes'); ?>
      <li class="side-nav-item">
        <a href="teacher-my-classes.php" class="side-nav-link<?php echo ($currentPage === 'teacher-my-classes.php' || $currentPage === 'teacher-learning-materials.php' || $currentPage === 'teacher-learning-material-editor.php' || $currentPage === 'teacher-learning-material-preview.php') ? ' active' : ''; ?>">
          <i class="ri-book-open-line"></i>
          <span> My Classes </span>
        </a>
      </li>
      <li class="side-nav-item">
        <a href="teacher-claim.php" class="side-nav-link<?php echo $currentPage === 'teacher-claim.php' ? ' active' : ''; ?>">
          <i class="ri-team-line"></i>
          <span> Enrollment Requests </span>
        </a>
      </li>
      <li class="side-nav-item">
        <a href="teacher-builds.php" class="side-nav-link<?php echo $currentPage === 'teacher-builds.php' ? ' active' : ''; ?>">
          <i class="ri-file-list-3-line"></i>
          <span> Class Record Builds </span>
        </a>
      </li>
      <li class="side-nav-item">
        <a href="grade-record-seed-preview.php" class="side-nav-link<?php echo $currentPage === 'grade-record-seed-preview.php' ? ' active' : ''; ?>">
          <i class="ri-eye-line"></i>
          <span> Grade Seed Preview </span>
        </a>
      </li>
      <?php if ($showReverseClassRecordLink): ?>
      <li class="side-nav-item">
        <a href="teacher-reverse-class-record.php" class="side-nav-link<?php echo $currentPage === 'teacher-reverse-class-record.php' ? ' active' : ''; ?>">
          <i class="ri-magic-line"></i>
          <span> Reverse Class Record </span>
        </a>
      </li>
      <?php endif; ?>
      <li class="side-nav-item">
        <a href="teacher-schedule.php" class="side-nav-link<?php echo $currentPage === 'teacher-schedule.php' ? ' active' : ''; ?>">
          <i class="ri-calendar-line"></i>
          <span> Schedule </span>
        </a>
      </li>
      <li class="side-nav-item">
        <a href="teacher-attendance-uploads.php" class="side-nav-link<?php echo $currentPage === 'teacher-attendance-uploads.php' ? ' active' : ''; ?>">
          <i class="ri-key-2-line"></i>
          <span> Attendance Check-In </span>
        </a>
      </li>
      <li class="side-nav-item">
        <a href="teacher-attendance-boundary.php" class="side-nav-link<?php echo $currentPage === 'teacher-attendance-boundary.php' ? ' active' : ''; ?>">
          <i class="ri-map-pin-range-line"></i>
          <span> Classroom Boundary </span>
        </a>
      </li>
      <li class="side-nav-item">
        <a href="teacher-wheel.php" class="side-nav-link<?php echo $currentPage === 'teacher-wheel.php' ? ' active' : ''; ?>">
          <i class="ri-disc-line"></i>
          <span> Class Wheel </span>
        </a>
      </li>
      <?php $renderSidebarCategory('Apps'); ?>
      <li class="side-nav-item">
        <a href="teacher-tos-tqs.php" class="side-nav-link<?php echo ($currentPage === 'teacher-tos-tqs.php' || $currentPage === 'teacher-tos-tqs-export.php') ? ' active' : ''; ?>">
          <i class="ri-file-chart-line"></i>
          <span> TOS / TQS Builder </span>
        </a>
      </li>
      <li class="side-nav-item">
        <a href="monthly-accomplishment.php" class="side-nav-link">
          <i class="ri-file-text-line"></i>
          <span> Monthly Accomplishment </span>
        </a>
      </li>
      <li class="side-nav-item">
        <a href="accomplishment-creator.php" class="side-nav-link<?php echo $currentPage === 'accomplishment-creator.php' ? ' active' : ''; ?>">
          <i class="ri-magic-line"></i>
          <span> Accomplishment Creator </span>
        </a>
      </li>
      <li class="side-nav-item">
        <a href="messages.php" class="side-nav-link">
          <i class="ri-message-3-line"></i>
          <span> Messages </span>
        </a>
      </li>
      <?php $renderSidebarCategory('Tutorial'); ?>
      <li class="side-nav-item">
        <a href="tutorials.php?role=teacher" class="side-nav-link<?php echo strpos($currentPage, 'tutorial') === 0 ? ' active' : ''; ?>">
          <i class="ri-book-open-line"></i>
          <span> Tutorials </span>
        </a>
      </li>
<?php elseif ($userRole === 'student' && $isActive): ?>
      <?php $renderSidebarCategory('Learning'); ?>
      <li class="side-nav-item">
        <a href="student-dashboard.php" class="side-nav-link<?php echo ($currentPage === 'student-dashboard.php' || $currentPage === 'user-dashboard.php' || $currentPage === 'student-quiz-attempt.php' || $currentPage === 'student-assessment-module.php' || $currentPage === 'student-learning-materials.php' || $currentPage === 'student-learning-material.php') ? ' active' : ''; ?>">
          <i class="ri-book-open-line"></i>
          <span> My Grades & Scores </span>
        </a>
      </li>
      <li class="side-nav-item">
        <a href="student-attendance.php" class="side-nav-link<?php echo $currentPage === 'student-attendance.php' ? ' active' : ''; ?>">
          <i class="ri-checkbox-circle-line"></i>
          <span> Attendance Check-In </span>
        </a>
      </li>
      <?php $renderSidebarCategory('Tutorial'); ?>
      <li class="side-nav-item">
        <a href="tutorials.php?role=student" class="side-nav-link<?php echo strpos($currentPage, 'tutorial') === 0 ? ' active' : ''; ?>">
          <i class="ri-book-open-line"></i>
          <span> Tutorials </span>
        </a>
      </li>
      <?php else: ?>
      <?php $renderSidebarCategory('Tutorial'); ?>
      <li class="side-nav-item">
        <a href="tutorials.php" class="side-nav-link<?php echo strpos($currentPage, 'tutorial') === 0 ? ' active' : ''; ?>">
          <i class="ri-book-open-line"></i>
          <span> Tutorials </span>
        </a>
      </li>
      <?php endif; ?>

      <?php if ($userRole === 'admin'): ?>
      <?php $renderSidebarCategory('Settings'); ?>
      <li class="side-nav-item">
        <a href="admin-references.php" class="side-nav-link">
          <i class="ri-bookmark-3-line"></i>
          <span> References </span>
        </a>
      </li>
      <li class="side-nav-item">
        <a href="admin-attendance-boundary.php" class="side-nav-link<?php echo $currentPage === 'admin-attendance-boundary.php' ? ' active' : ''; ?>">
          <i class="ri-map-pin-range-line"></i>
          <span> Attendance Boundary </span>
        </a>
      </li>
      <?php if ($isSuperadmin): ?>
      <li class="side-nav-item">
        <a href="admin-site-pages.php" class="side-nav-link<?php echo $currentPage === 'admin-site-pages.php' ? ' active' : ''; ?>">
          <i class="ri-file-edit-line"></i>
          <span> Site Pages </span>
        </a>
      </li>
      <li class="side-nav-item">
        <a href="admin-session-settings.php" class="side-nav-link<?php echo $currentPage === 'admin-session-settings.php' ? ' active' : ''; ?>">
          <i class="ri-timer-line"></i>
          <span> Session Settings </span>
        </a>
      </li>
      <li class="side-nav-item">
        <a href="admin-report-templates.php" class="side-nav-link<?php echo $currentPage === 'admin-report-templates.php' ? ' active' : ''; ?>">
          <i class="ri-file-list-3-line"></i>
          <span> Report Templates </span>
        </a>
      </li>
      <li class="side-nav-item">
        <a href="admin-db-tools.php" class="side-nav-link<?php echo $currentPage === 'admin-db-tools.php' ? ' active' : ''; ?>">
          <i class="ri-database-2-line"></i>
          <span> DB Tools </span>
        </a>
      </li>
      <li class="side-nav-item">
        <a href="admin-project-tree.php" class="side-nav-link<?php echo $currentPage === 'admin-project-tree.php' ? ' active' : ''; ?>">
          <i class="ri-git-branch-line"></i>
          <span> Project Tree </span>
        </a>
      </li>
      <li class="side-nav-item">
        <a href="admin-campuses.php" class="side-nav-link<?php echo $currentPage === 'admin-campuses.php' ? ' active' : ''; ?>">
          <i class="ri-building-2-line"></i>
          <span> Campuses </span>
        </a>
      </li>
      <?php endif; ?>
      <?php endif; ?>

      <?php if ($userRole === 'admin'): ?>
      <?php $renderSidebarCategory('Tutorial'); ?>
      <li class="side-nav-item">
        <a
          data-bs-toggle="collapse"
          data-bs-target="#sidebarTutorials"
          href="javascript:void(0);"
          aria-expanded="<?php echo $isTutorialMenuOpen ? 'true' : 'false'; ?>"
          aria-controls="sidebarTutorials"
          class="side-nav-link"
        >
          <i class="ri-book-open-line"></i>
          <span> Tutorials </span>
          <span class="menu-arrow"></span>
        </a>
        <div class="collapse<?php echo $isTutorialMenuOpen ? ' show' : ''; ?>" id="sidebarTutorials">
          <ul class="side-nav-second-level">
            <li>
              <a href="tutorials.php?role=admin" class="<?php echo $currentPage === 'tutorials.php' ? 'active' : ''; ?>">Tutorials Hub</a>
            </li>
            <li>
              <a href="tutorial-admin.php" class="<?php echo $currentPage === 'tutorial-admin.php' ? 'active' : ''; ?>">Admin Guide</a>
            </li>
            <li>
              <a href="tutorial-enroll-student.php" class="<?php echo $currentPage === 'tutorial-enroll-student.php' ? 'active' : ''; ?>">Enroll Student</a>
            </li>
            <li>
              <a href="tutorial-users.php" class="<?php echo $currentPage === 'tutorial-users.php' ? 'active' : ''; ?>">Users</a>
            </li>
            <li>
              <a href="tutorial-program-chair.php" class="<?php echo $currentPage === 'tutorial-program-chair.php' ? 'active' : ''; ?>">Program Chair</a>
            </li>
          </ul>
        </div>
      </li>
      <?php endif; ?>

      <?php
        $hasInfoLinks = false;
        foreach ($infoPageConfig as $infoMeta) {
          $href = (string) ($infoMeta['href'] ?? '');
          $published = ((int) ($infoMeta['published'] ?? 0) === 1);
          if ($href !== '' && ($published || $isSuperadmin)) {
            $hasInfoLinks = true;
            break;
          }
        }
      ?>
      <?php if ($hasInfoLinks && !($userRole === 'teacher' && $isActive)): ?>
      <?php $renderSidebarCategory('Info'); ?>
      <?php foreach ($infoPageConfig as $meta): ?>
      <?php
        $href = (string) ($meta['href'] ?? '');
        $icon = (string) ($meta['icon'] ?? 'ri-information-line');
        $label = (string) ($meta['label'] ?? 'Info');
        $published = ((int) ($meta['published'] ?? 0) === 1);
        if ($href === '' || (!$published && !$isSuperadmin)) continue;
      ?>
      <li class="side-nav-item">
        <a href="<?php echo htmlspecialchars($href); ?>" class="side-nav-link<?php echo $currentPage === basename($href) ? ' active' : ''; ?>">
          <i class="<?php echo htmlspecialchars($icon); ?>"></i>
          <span> <?php echo htmlspecialchars($label); ?> </span>
        </a>
      </li>
      <?php endforeach; ?>
      <?php endif; ?>

      <?php if ($userRole === 'teacher' && $isActive && $hasInfoLinks): ?>
      <?php $renderSidebarCategory('Info'); ?>
      <?php foreach ($infoPageConfig as $meta): ?>
      <?php
        $href = (string) ($meta['href'] ?? '');
        $icon = (string) ($meta['icon'] ?? 'ri-information-line');
        $label = (string) ($meta['label'] ?? 'Info');
        $published = ((int) ($meta['published'] ?? 0) === 1);
        if ($href === '' || (!$published && !$isSuperadmin)) continue;
      ?>
      <li class="side-nav-item">
        <a href="<?php echo htmlspecialchars($href); ?>" class="side-nav-link<?php echo $currentPage === basename($href) ? ' active' : ''; ?>">
          <i class="<?php echo htmlspecialchars($icon); ?>"></i>
          <span> <?php echo htmlspecialchars($label); ?> </span>
        </a>
      </li>
      <?php endforeach; ?>
      <?php endif; ?>
    </ul>

    <div class="clearfix"></div>
  </div>
</div>
<!-- ========== Left Sidebar End ========== -->
<script>
  (function () {
    var sidebarStorageKey = 'docEase.sidebar.scrollTop';
    var parallaxEnterKey = 'docEase.sidebar.parallaxEnter';
    var parallaxDurationMs = 180;
    var transitionBusy = false;

    function prefersReducedMotion() {
      return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }

    function normalizePath(pathname) {
      var text = String(pathname || '');
      if (text === '') return '';
      text = text.split('?')[0].split('#')[0];
      if (text.length > 1 && text.charAt(text.length - 1) === '/') text = text.slice(0, -1);
      var parts = text.split('/');
      var last = parts.length > 0 ? parts[parts.length - 1] : '';
      if (!last) last = 'index';
      if (last.toLowerCase().slice(-4) === '.php') last = last.slice(0, -4);
      return last.toLowerCase();
    }

    function sidebarScroller() {
      var container = document.getElementById('leftside-menu-container');
      if (!container) return null;
      var simplebarWrapper = container.querySelector('.simplebar-content-wrapper');
      return simplebarWrapper || container;
    }

    function saveSidebarScroll() {
      var scroller = sidebarScroller();
      if (!scroller || !window.sessionStorage) return;
      window.sessionStorage.setItem(sidebarStorageKey, String(scroller.scrollTop || 0));
    }

    function restoreSidebarScroll(retriesLeft) {
      var scroller = sidebarScroller();
      if (!scroller || !window.sessionStorage) {
        if ((retriesLeft || 0) > 0) {
          setTimeout(function () { restoreSidebarScroll((retriesLeft || 0) - 1); }, 70);
        }
        return;
      }
      var raw = window.sessionStorage.getItem(sidebarStorageKey);
      if (raw === null || raw === '') return;
      var value = parseInt(raw, 10);
      if (!Number.isFinite(value)) return;
      scroller.scrollTop = value;
    }

    function markLinkParentsActive(link) {
      if (!link) return;
      var linkItem = link.closest('li');
      if (linkItem) linkItem.classList.add('active');

      var navItem = link.closest('.side-nav-item');
      if (navItem) navItem.classList.add('menuitem-active');

      var collapseParent = link.closest('.collapse');
      while (collapseParent) {
        collapseParent.classList.add('show');
        var toggle = document.querySelector('.leftside-menu .side-nav-link[data-bs-target="#' + collapseParent.id + '"]');
        if (!toggle) break;
        toggle.classList.add('active');
        toggle.setAttribute('aria-expanded', 'true');
        var toggleItem = toggle.closest('.side-nav-item');
        if (toggleItem) toggleItem.classList.add('menuitem-active');
        collapseParent = toggle.closest('.collapse');
      }
    }

    function activateCurrentMenu() {
      var current = normalizePath(window.location.pathname);
      if (current === '') return;

      var links = document.querySelectorAll('.leftside-menu .side-nav a[href]');
      for (var i = 0; i < links.length; i++) {
        var link = links[i];
        var href = String(link.getAttribute('href') || '').trim();
        if (!href || href === '#' || href.indexOf('javascript:') === 0) continue;

        var resolved;
        try {
          resolved = new URL(href, window.location.origin);
        } catch (e) {
          continue;
        }
        if (resolved.origin !== window.location.origin) continue;

        var target = normalizePath(resolved.pathname);
        if (target !== current) continue;

        link.classList.add('active');
        markLinkParentsActive(link);
      }
    }

    function isNavigableSidebarLink(link) {
      if (!link) return false;
      var href = String(link.getAttribute('href') || '').trim();
      if (href === '' || href === '#' || href.indexOf('javascript:') === 0) return false;
      if (href.indexOf('mailto:') === 0 || href.indexOf('tel:') === 0) return false;
      if (link.hasAttribute('download')) return false;
      var target = String(link.getAttribute('target') || '').toLowerCase();
      if (target !== '' && target !== '_self') return false;

      var resolved;
      try {
        resolved = new URL(href, window.location.href);
      } catch (e) {
        return false;
      }
      if (resolved.origin !== window.location.origin) return false;
      return true;
    }

    function applyEnterParallaxIfNeeded() {
      if (prefersReducedMotion()) return;
      if (!window.sessionStorage) return;

      var shouldAnimate = window.sessionStorage.getItem(parallaxEnterKey) === '1';
      if (!shouldAnimate) return;
      window.sessionStorage.removeItem(parallaxEnterKey);

      document.body.classList.add('sidebar-parallax-enter');
      requestAnimationFrame(function () {
        document.body.classList.add('sidebar-parallax-enter-active');
        setTimeout(function () {
          document.body.classList.remove('sidebar-parallax-enter');
          document.body.classList.remove('sidebar-parallax-enter-active');
        }, parallaxDurationMs + 120);
      });
    }

    function navigateWithParallax(link) {
      if (!isNavigableSidebarLink(link)) return false;
      if (prefersReducedMotion()) return false;
      if (transitionBusy) return true;

      var destination;
      try {
        destination = new URL(String(link.getAttribute('href') || ''), window.location.href).href;
      } catch (e) {
        return false;
      }
      if (destination === window.location.href) return false;

      transitionBusy = true;
      if (window.sessionStorage) window.sessionStorage.setItem(parallaxEnterKey, '1');
      saveSidebarScroll();

      document.body.classList.add('sidebar-parallax-exit');
      setTimeout(function () {
        window.location.href = destination;
      }, parallaxDurationMs);
      return true;
    }

    document.addEventListener('DOMContentLoaded', function () {
      activateCurrentMenu();
      restoreSidebarScroll(8);
      applyEnterParallaxIfNeeded();

      var sidebar = document.querySelector('.leftside-menu');
      if (sidebar) {
        sidebar.addEventListener('click', function (event) {
          var link = event.target && event.target.closest ? event.target.closest('a[href]') : null;
          if (!link) return;

          saveSidebarScroll();

          if (link.classList.contains('side-nav-link') && link.getAttribute('data-bs-toggle') === 'collapse') {
            return;
          }

          if (event.defaultPrevented) return;
          if (typeof event.button === 'number' && event.button !== 0) return;
          if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

          if (navigateWithParallax(link)) event.preventDefault();
        }, true);
      }

      window.addEventListener('beforeunload', saveSidebarScroll);
    });
  })();
</script>
