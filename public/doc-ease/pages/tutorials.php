<?php include '../layouts/session.php'; ?>
<?php include '../layouts/main.php'; ?>
<?php require_once __DIR__ . '/../includes/tutorials.php'; ?>

<?php
$userRole = isset($_SESSION['user_role']) ? normalize_role((string) $_SESSION['user_role']) : '';
$isAdmin = ($userRole === 'admin');

$roleLabels = [
    'admin' => 'Admin',
    'teacher' => 'Teacher',
    'student' => 'Student',
    'program_chair' => 'Program Chair',
    'registrar' => 'Registrar',
    'college_dean' => 'College Dean',
    'guardian' => 'Guardian',
];

$requestedRole = isset($_GET['role']) ? normalize_role((string) $_GET['role']) : '';
if ($requestedRole === '') $requestedRole = $userRole;
if (!$isAdmin) $requestedRole = $userRole;
if (!isset($roleLabels[$requestedRole])) $requestedRole = $userRole;
if (!isset($roleLabels[$requestedRole])) $requestedRole = 'student';

$tutorials = [
    [
        'role' => 'admin',
        'title' => 'Admin Guide (All Features)',
        'url' => 'tutorial-admin.php',
        'desc' => 'Accounts, enrollments, teacher assignments, scheduling, approvals, audit, and references.',
    ],
    [
        'role' => 'admin',
        'title' => 'Enroll Student (Workflow)',
        'url' => 'tutorial-enroll-student.php',
        'desc' => 'Step-by-step enrollment flow so students appear in class rosters and records.',
    ],
    [
        'role' => 'admin',
        'title' => 'Users (Accounts Setup)',
        'url' => 'tutorial-users.php',
        'desc' => 'How Student Accounts, Teacher Accounts, and All Accounts work together.',
    ],
    [
        'role' => 'teacher',
        'title' => 'Teacher Guide (All Features)',
        'url' => 'tutorial-teacher.php',
        'desc' => 'My Classes, learning materials, assessments, grading builds/config, schedule, attendance, and reports.',
    ],
    [
        'role' => 'student',
        'title' => 'Student Guide (All Features)',
        'url' => 'tutorial-student.php',
        'desc' => 'Grades & scores, learning materials, quizzes/assignments, attendance check-in, uploads, and reports.',
    ],
    [
        'role' => 'program_chair',
        'title' => 'Program Chair (Current Capabilities)',
        'url' => 'tutorial-program-chair.php',
        'desc' => 'What the Program Chair role is currently used for (and what is not yet implemented).',
    ],
    [
        'role' => 'registrar',
        'title' => 'Registrar (Coming Soon)',
        'url' => '',
        'desc' => 'Role is supported in Accounts, but registrar pages are not implemented yet in this build.',
    ],
    [
        'role' => 'college_dean',
        'title' => 'College Dean (Coming Soon)',
        'url' => '',
        'desc' => 'Role is supported in Accounts, but dean pages are not implemented yet in this build.',
    ],
    [
        'role' => 'guardian',
        'title' => 'Guardian (Coming Soon)',
        'url' => '',
        'desc' => 'Role is supported in Accounts, but guardian pages are not implemented yet in this build.',
    ],
];

$visibleTutorials = [];
foreach ($tutorials as $t) {
    if (($t['role'] ?? '') !== $requestedRole) continue;
    $visibleTutorials[] = $t;
}

$pageTitleRoleLabel = $roleLabels[$requestedRole] ?? 'Tutorials';
?>

<head>
    <title>Tutorials | E-Record</title>
    <?php include '../layouts/title-meta.php'; ?>
    <?php include '../layouts/head-css.php'; ?>
    <link href="assets/css/tutorials.css" rel="stylesheet" type="text/css" />
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
                                        <li class="breadcrumb-item"><a href="index.php">E-Record</a></li>
                                        <li class="breadcrumb-item active">Tutorials</li>
                                    </ol>
                                </div>
                                <h4 class="page-title">Tutorials: <?php echo tutorial_h($pageTitleRoleLabel); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-12">
                            <div class="tutorial-callout mb-3">
                                <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between">
                                    <div>
                                        <div class="fw-semibold">Screenshot-based guides (no Intro.js)</div>
                                        <div class="text-muted small">
                                            Missing screenshots show a placeholder. Add screenshots under <code>assets/images/tutorials/</code> and refresh.
                                        </div>
                                    </div>
                                    <div class="d-flex flex-wrap gap-2">
                                        <a class="btn btn-sm btn-outline-secondary" href="pages-profile.php">
                                            <i class="ri-user-3-line me-1" aria-hidden="true"></i>
                                            My Profile
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($isAdmin): ?>
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title mb-2">Select Role</h4>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php foreach ($roleLabels as $roleKey => $label): ?>
                                            <?php
                                                $isActive = ($roleKey === $requestedRole);
                                                $btnClass = $isActive ? 'btn-primary' : 'btn-outline-primary';
                                            ?>
                                            <a class="btn btn-sm <?php echo $btnClass; ?>" href="tutorials.php?role=<?php echo rawurlencode($roleKey); ?>">
                                                <?php echo tutorial_h($label); ?>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="text-muted small mt-2">
                                        Tip: Admin can view guides for all roles.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title mb-3">Available Guides</h4>

                                    <?php if (count($visibleTutorials) === 0): ?>
                                        <div class="text-muted">No guides are available for this role yet.</div>
                                    <?php else: ?>
                                        <div class="row g-3">
                                            <?php foreach ($visibleTutorials as $t): ?>
                                                <?php
                                                    $title = (string) ($t['title'] ?? 'Tutorial');
                                                    $desc = (string) ($t['desc'] ?? '');
                                                    $url = trim((string) ($t['url'] ?? ''));
                                                ?>
                                                <div class="col-md-6 col-xl-4">
                                                    <div class="card h-100 border">
                                                        <div class="card-body">
                                                            <div class="d-flex align-items-start justify-content-between gap-2">
                                                                <div>
                                                                    <h5 class="mb-1"><?php echo tutorial_h($title); ?></h5>
                                                                    <div class="text-muted small"><?php echo tutorial_h($desc); ?></div>
                                                                </div>
                                                                <div class="text-muted">
                                                                    <i class="ri-book-open-line fs-18" aria-hidden="true"></i>
                                                                </div>
                                                            </div>

                                                            <div class="mt-3">
                                                                <?php if ($url !== ''): ?>
                                                                    <a class="btn btn-sm btn-primary" href="<?php echo tutorial_h($url); ?>">
                                                                        Open
                                                                    </a>
                                                                <?php else: ?>
                                                                    <button type="button" class="btn btn-sm btn-outline-secondary" disabled>Not Available</button>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
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

