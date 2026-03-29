<?php include '../layouts/session.php'; ?>
<?php require_role('admin'); ?>
<?php include '../layouts/main.php'; ?>
<?php require_once __DIR__ . '/../includes/tutorials.php'; ?>

<head>
    <title>Tutorial: Users | E-Record</title>
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
                                    <a class="btn btn-sm btn-outline-primary mb-2" href="tutorials.php?role=admin">
                                        <i class="ri-book-open-line me-1" aria-hidden="true"></i>
                                        Tutorials Hub
                                    </a>
                                    <ol class="breadcrumb m-0">
                                        <li class="breadcrumb-item"><a href="admin-dashboard.php">Admin</a></li>
                                        <li class="breadcrumb-item">Tutorials</li>
                                        <li class="breadcrumb-item active">Users</li>
                                    </ol>
                                </div>
                                <h4 class="page-title">Tutorial: Users</h4>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-xl-6">
                            <div class="card h-100">
                                <div class="card-body">
                                    <h4 class="header-title mb-2">Know the 3 User Pages</h4>
                                    <ol class="mb-0 ps-3">
                                        <li class="mb-2">
                                            <strong>Student Accounts</strong>
                                            <div class="text-muted small">
                                                Full student list from <code>students</code> plus unlinked student login rows.
                                            </div>
                                        </li>
                                        <li class="mb-2">
                                            <strong>Teacher Accounts</strong>
                                            <div class="text-muted small">
                                                Teacher login rows and linked teacher profiles from <code>teachers</code>.
                                            </div>
                                        </li>
                                        <li>
                                            <strong>All Accounts</strong>
                                            <div class="text-muted small">
                                                Global login accounts in <code>users</code> across roles.
                                            </div>
                                        </li>
                                    </ol>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-6">
                            <div class="card h-100">
                                <div class="card-body">
                                    <h4 class="header-title mb-2">Step-by-Step User Setup</h4>
                                    <ol class="mb-0 ps-3">
                                        <li class="mb-2">
                                            Create the login account in the correct page (Student or Teacher Accounts).
                                        </li>
                                        <li class="mb-2">
                                            If the row is unlinked, click <strong>Create/Link Profile</strong>.
                                        </li>
                                        <li class="mb-2">
                                            Complete profile data (name, contact, program or department).
                                        </li>
                                        <li class="mb-2">
                                            Activate the account so the user can access dashboard features.
                                        </li>
                                        <li>
                                            Use <strong>Edit Profile</strong> for future changes.
                                            <div class="text-muted small">
                                                Profile edits sync important fields back to <code>users</code> automatically.
                                            </div>
                                        </li>
                                    </ol>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title mb-2">Teacher-Added Enrollment Rule</h4>
                                    <p class="text-muted mb-2">
                                        Teacher can submit enrollment requests, but admin must approve them before they become active in class roster.
                                    </p>
                                    <ol class="mb-0 ps-3">
                                        <li>Teacher submits request (status becomes <code>TeacherPending</code>).</li>
                                        <li>Admin reviews in Enrollment Approvals.</li>
                                        <li>On approve, student is inserted to class roster and request is marked claimed.</li>
                                    </ol>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title mb-3">Quick Links</h4>
                                    <div class="d-flex flex-wrap gap-2">
                                        <a class="btn btn-primary" href="admin-users-students.php">Student Accounts</a>
                                        <a class="btn btn-outline-primary" href="admin-users-teachers.php">Teacher Accounts</a>
                                        <a class="btn btn-outline-secondary" href="admin-users.php">All Accounts</a>
                                        <a class="btn btn-outline-primary" href="admin-enrollment-approvals.php">Enrollment Approvals</a>
                                        <a class="btn btn-light" href="admin-dashboard.php">Data Integrity Check</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title mb-3">Screenshots</h4>
                                    <div class="row g-3">
                                        <div class="col-md-6 col-xl-4">
                                            <?php
                                                echo tutorial_shot(
                                                    'assets/images/tutorials/admin/accounts/student-accounts.png',
                                                    'Student Accounts',
                                                    'Student Accounts: student list + linking profiles.'
                                                );
                                            ?>
                                        </div>
                                        <div class="col-md-6 col-xl-4">
                                            <?php
                                                echo tutorial_shot(
                                                    'assets/images/tutorials/admin/accounts/teacher-accounts.png',
                                                    'Teacher Accounts',
                                                    'Teacher Accounts: create teacher logins and link profiles.'
                                                );
                                            ?>
                                        </div>
                                        <div class="col-md-6 col-xl-4">
                                            <?php
                                                echo tutorial_shot(
                                                    'assets/images/tutorials/admin/accounts/all-accounts-roles.png',
                                                    'All Accounts role selection',
                                                    'All Accounts: manage roles, activation, and global login rows.'
                                                );
                                            ?>
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
    <script src="assets/js/app.min.js"></script>
</body>

</html>
