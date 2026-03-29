<?php include '../layouts/session.php'; ?>
<?php require_any_role(['program_chair', 'admin']); ?>
<?php include '../layouts/main.php'; ?>
<?php require_once __DIR__ . '/../includes/tutorials.php'; ?>

<?php
$role = isset($_SESSION['user_role']) ? normalize_role((string) $_SESSION['user_role']) : '';
$isAdmin = ($role === 'admin');
?>

<head>
    <title>Tutorial: Program Chair | E-Record</title>
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
                                    <a class="btn btn-sm btn-outline-primary mb-2" href="tutorials.php?role=program_chair">
                                        <i class="ri-arrow-left-line me-1" aria-hidden="true"></i>
                                        Tutorials Hub
                                    </a>
                                    <ol class="breadcrumb m-0">
                                        <li class="breadcrumb-item"><a href="tutorials.php">Tutorials</a></li>
                                        <li class="breadcrumb-item active">Program Chair</li>
                                    </ol>
                                </div>
                                <h4 class="page-title">Tutorial: Program Chair (Current Capabilities)</h4>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-12">
                            <div class="tutorial-callout mb-3">
                                <div class="fw-semibold">Current scope</div>
                                <div class="text-muted small">
                                    In this build, the Program Chair role is mainly used as a reference approver for reports (for example, Monthly Accomplishment printouts).
                                    Dedicated Program Chair dashboard pages are not implemented yet.
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-lg-8">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title mb-2">What Program Chair Affects</h4>
                                    <ol class="mb-0 ps-3">
                                        <li>Appears as approver label/name in printed reports (when configured).</li>
                                        <li>Can be assigned to teacher profiles as a default Program Chair.</li>
                                        <li>Can be assigned per subject for a teacher (Program Chair by Subject), so printouts can resolve per subject.</li>
                                    </ol>
                                    <div class="text-muted small mt-2">
                                        If you need Program Chair portal features (approvals, dashboards), those pages need to be implemented first.
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-4">
                            <div class="card h-100">
                                <div class="card-body">
                                    <h4 class="header-title mb-3">Screenshot</h4>
                                    <?php
                                        echo tutorial_shot(
                                            'assets/images/tutorials/program-chair/print/approved-by.png',
                                            'Program Chair in print',
                                            'Example placement: Program Chair as approver on print output.'
                                        );
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($isAdmin): ?>
                    <div class="row">
                        <div class="col-lg-8">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title mb-2">Admin Setup: Create Program Chair Account</h4>
                                    <ol class="mb-0 ps-3">
                                        <li>Open <strong>All Accounts</strong>.</li>
                                        <li>Create a new account and set role to <code>program_chair</code>.</li>
                                        <li>Activate the account (so it can be selected in profile dropdowns).</li>
                                    </ol>
                                    <div class="mt-3">
                                        <a class="btn btn-sm btn-outline-primary" href="admin-users.php">Open All Accounts</a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-4">
                            <div class="card h-100">
                                <div class="card-body">
                                    <h4 class="header-title mb-3">Screenshot</h4>
                                    <?php
                                        echo tutorial_shot(
                                            'assets/images/tutorials/admin/accounts/program-chair-role.png',
                                            'Select Program Chair role',
                                            'All Accounts: set role to Program Chair and activate.'
                                        );
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-lg-8">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title mb-2">Admin Setup: Assign Program Chair to Teacher</h4>
                                    <p class="text-muted mb-3">
                                        Program Chair assignment is managed via teacher profile data (admin-approved profile requests).
                                    </p>
                                    <ol class="mb-0 ps-3">
                                        <li>Open the teacher profile page.</li>
                                        <li>Submit a profile update request (or edit via admin tools if available).</li>
                                        <li>Set <strong>Program Chair</strong> (default) and/or <strong>Program Chair by Subject</strong>.</li>
                                        <li>Approve the request in <strong>Profile Approvals</strong>.</li>
                                    </ol>
                                    <div class="mt-3">
                                        <a class="btn btn-sm btn-outline-primary" href="admin-profile-approvals.php">Open Profile Approvals</a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-4">
                            <div class="card h-100">
                                <div class="card-body">
                                    <h4 class="header-title mb-3">Screenshot</h4>
                                    <?php
                                        echo tutorial_shot(
                                            'assets/images/tutorials/admin/profile/program-chair-by-subject.png',
                                            'Program Chair by Subject selector',
                                            'Teacher Profile: Program Chair (default) and Program Chair by Subject mapping.'
                                        );
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

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

