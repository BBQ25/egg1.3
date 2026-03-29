<?php include '../layouts/session.php'; ?>
<?php require_role('admin'); ?>
<?php include '../layouts/main.php'; ?>
<?php require_once __DIR__ . '/../includes/tutorials.php'; ?>

<head>
    <title>Tutorial: Enroll Student | E-Record</title>
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
                                        <li class="breadcrumb-item active">Enroll Student</li>
                                    </ol>
                                </div>
                                <h4 class="page-title">Tutorial: Enroll Student</h4>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title mb-2">Step-by-Step</h4>
                                    <p class="text-muted mb-3">
                                        Use this flow to enroll students correctly and make sure they appear in teacher class records.
                                    </p>

                                    <ol class="mb-0 ps-3">
                                        <li class="mb-2">
                                            Open <strong>Student Accounts</strong> and search the student.
                                            <div class="text-muted small">Menu: Accounts > Student Accounts.</div>
                                        </li>
                                        <li class="mb-2">
                                            Confirm the row is linked to a student profile.
                                            <div class="text-muted small">
                                                If not linked, use <strong>Create/Link Profile</strong> from the row action first.
                                            </div>
                                        </li>
                                        <li class="mb-2">
                                            Select the student row checkbox, then use the <strong>Bulk Enroll</strong> panel.
                                            <div class="text-muted small">
                                                Set Subject, Academic Year, Semester, and Section before clicking <strong>Enroll Selected Students</strong>.
                                            </div>
                                        </li>
                                        <li class="mb-2">
                                            Open the student details page and verify enrollment rows.
                                            <div class="text-muted small">
                                                Check both class enrollments and enrollment queue status.
                                            </div>
                                        </li>
                                        <li class="mb-2">
                                            If enrollment was teacher-initiated, go to <strong>Enrollment Approvals</strong>.
                                            <div class="text-muted small">
                                                Approve requests in <code>TeacherPending</code> so the student is added to class roster.
                                            </div>
                                        </li>
                                        <li>
                                            Validate final placement in class roster and class record.
                                            <div class="text-muted small">
                                                Open the target class and confirm the student appears under active enrollments.
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
                                    <h4 class="header-title mb-3">Quick Links</h4>
                                    <div class="d-flex flex-wrap gap-2">
                                        <a class="btn btn-primary" href="admin-users-students.php">Student Accounts</a>
                                        <a class="btn btn-outline-primary" href="admin-enrollment-approvals.php">Enrollment Approvals</a>
                                        <a class="btn btn-outline-secondary" href="admin-dashboard.php">Data Integrity Check</a>
                                    </div>
                                    <p class="text-muted small mt-3 mb-0">
                                        Tip: From Student Accounts, use the row action <strong>View Subjects</strong> to open enrollment details for a specific student.
                                    </p>
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
                                                    'assets/images/tutorials/admin/enrollments/student-accounts-search.png',
                                                    'Student Accounts search',
                                                    'Step 1: Find the student in Student Accounts.'
                                                );
                                            ?>
                                        </div>
                                        <div class="col-md-6 col-xl-4">
                                            <?php
                                                echo tutorial_shot(
                                                    'assets/images/tutorials/admin/enrollments/bulk-enroll.png',
                                                    'Bulk enroll panel',
                                                    'Step 3: Use Bulk Enroll to enroll selected students.'
                                                );
                                            ?>
                                        </div>
                                        <div class="col-md-6 col-xl-4">
                                            <?php
                                                echo tutorial_shot(
                                                    'assets/images/tutorials/admin/enrollments/enrollment-approvals.png',
                                                    'Enrollment approvals',
                                                    'Step 5: Approve TeacherPending requests in Enrollment Approvals.'
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
