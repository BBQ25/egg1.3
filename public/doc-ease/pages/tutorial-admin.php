<?php include '../layouts/session.php'; ?>
<?php require_role('admin'); ?>
<?php include '../layouts/main.php'; ?>
<?php require_once __DIR__ . '/../includes/tutorials.php'; ?>

<head>
    <title>Tutorial: Admin Guide | E-Record</title>
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
                                        <i class="ri-arrow-left-line me-1" aria-hidden="true"></i>
                                        Tutorials Hub
                                    </a>
                                    <ol class="breadcrumb m-0">
                                        <li class="breadcrumb-item"><a href="admin-dashboard.php">Admin</a></li>
                                        <li class="breadcrumb-item">Tutorials</li>
                                        <li class="breadcrumb-item active">Admin Guide</li>
                                    </ol>
                                </div>
                                <h4 class="page-title">Tutorial: Admin Guide (All Features)</h4>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-12">
                            <div class="tutorial-callout mb-3">
                                <div class="fw-semibold">How this guide works</div>
                                <div class="text-muted small">
                                    This is a screenshot-based tutorial (no Intro.js). Click any screenshot to open the full image in a new tab.
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title mb-2">Quick Links</h4>
                                    <div class="d-flex flex-wrap gap-2">
                                        <a class="btn btn-primary" href="admin-dashboard.php">Admin Dashboard</a>
                                        <a class="btn btn-outline-primary" href="admin-users-students.php">Student Accounts</a>
                                        <a class="btn btn-outline-primary" href="admin-users-teachers.php">Teacher Accounts</a>
                                        <a class="btn btn-outline-secondary" href="admin-users.php">All Accounts</a>
                                        <a class="btn btn-outline-primary" href="admin-assign-teachers.php">Assign Teachers</a>
                                        <a class="btn btn-outline-primary" href="admin-schedules.php">Schedules</a>
                                        <a class="btn btn-outline-primary" href="admin-schedule-approvals.php">Schedule Approvals</a>
                                        <a class="btn btn-outline-primary" href="admin-enrollment-approvals.php">Enrollment Approvals</a>
                                        <a class="btn btn-outline-primary" href="admin-profile-approvals.php">Profile Approvals</a>
                                        <a class="btn btn-outline-secondary" href="admin-audit-log.php">Audit Log</a>
                                        <a class="btn btn-light" href="admin-references.php">References</a>
                                        <a class="btn btn-light" href="add-subject.php">Subjects</a>
                                        <a class="btn btn-light" href="section.php">Sections</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-lg-8">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title mb-2">1) Accounts (Students, Teachers, All Accounts)</h4>
                                    <p class="text-muted mb-3">
                                        Use Accounts pages to create login accounts, link them to profiles (Student/Teacher), activate users, and manage roles.
                                    </p>

                                    <div class="tutorial-step">
                                        <div class="fw-semibold">Student Accounts</div>
                                        <ol class="mb-0 ps-3">
                                            <li>Create a student login row (or find an existing one).</li>
                                            <li>If unlinked, use <strong>Create/Link Profile</strong> to attach the login to the student profile record.</li>
                                            <li>Activate the account so the student can access student features.</li>
                                        </ol>
                                    </div>

                                    <div class="tutorial-step">
                                        <div class="fw-semibold">Teacher Accounts</div>
                                        <ol class="mb-0 ps-3">
                                            <li>Create a teacher login row.</li>
                                            <li>Link it to a teacher profile (or create the profile first).</li>
                                            <li>Activate the account so the teacher can access teacher features.</li>
                                        </ol>
                                    </div>

                                    <div class="tutorial-step">
                                        <div class="fw-semibold">Other Roles (Registrar / Program Chair / College Dean / Guardian)</div>
                                        <ol class="mb-0 ps-3">
                                            <li>Create the account in <strong>All Accounts</strong> and set the role.</li>
                                            <li>Activate the account.</li>
                                            <li>Note: Role-specific dashboards may not be implemented yet in this build.</li>
                                        </ol>
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
                                            'assets/images/tutorials/admin/accounts/student-accounts.png',
                                            'Student Accounts page',
                                            'Student Accounts: search, Create/Link Profile, activate accounts, and bulk actions.'
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
                                    <h4 class="header-title mb-2">2) Enrollments</h4>
                                    <p class="text-muted mb-3">
                                        Enrollments connect students to class records. Admin can bulk enroll; teacher-submitted requests require admin approval.
                                    </p>
                                    <ol class="mb-0 ps-3">
                                        <li>Open <strong>Student Accounts</strong> and select one or more students.</li>
                                        <li>Use <strong>Bulk Enroll</strong> to pick Subject, Academic Year, Semester, and Section.</li>
                                        <li>Verify enrollment rows in the student details page.</li>
                                        <li>For teacher-initiated requests, approve in <strong>Enrollment Approvals</strong> so the student becomes active in roster.</li>
                                    </ol>
                                    <div class="mt-3">
                                        <a class="btn btn-sm btn-outline-primary" href="tutorial-enroll-student.php">Open Enroll Student Tutorial</a>
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
                                            'assets/images/tutorials/admin/enrollments/bulk-enroll.png',
                                            'Bulk enroll panel',
                                            'Bulk Enroll: select students, choose class details, and enroll.'
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
                                    <h4 class="header-title mb-2">3) Assign Teachers</h4>
                                    <p class="text-muted mb-3">
                                        Teacher assignments control who can access class tools (including co-teachers).
                                    </p>
                                    <ol class="mb-0 ps-3">
                                        <li>Open <strong>Assign Teachers</strong>.</li>
                                        <li>Select Subject, Section, Academic Year, and Semester.</li>
                                        <li>Select a teacher and assign as primary or co-teacher.</li>
                                        <li>Confirm the teacher sees it under <strong>Teacher Dashboard</strong> or <strong>My Classes</strong>.</li>
                                    </ol>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-4">
                            <div class="card h-100">
                                <div class="card-body">
                                    <h4 class="header-title mb-3">Screenshot</h4>
                                    <?php
                                        echo tutorial_shot(
                                            'assets/images/tutorials/admin/assign-teachers/assign-teachers.png',
                                            'Assign Teachers page',
                                            'Assign Teachers: create or update teacher assignments for a class record.'
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
                                    <h4 class="header-title mb-2">4) Scheduling</h4>
                                    <p class="text-muted mb-3">
                                        Admin can create schedule slots directly. Teachers can request schedule changes which need admin approval.
                                    </p>
                                    <div class="tutorial-step">
                                        <div class="fw-semibold">Create schedules directly (Admin)</div>
                                        <ol class="mb-0 ps-3">
                                            <li>Open <strong>Schedules</strong>.</li>
                                            <li>Select the target class record.</li>
                                            <li>Add schedule slots (day/time/room), then save.</li>
                                        </ol>
                                    </div>
                                    <div class="tutorial-step">
                                        <div class="fw-semibold">Approve teacher schedule requests</div>
                                        <ol class="mb-0 ps-3">
                                            <li>Open <strong>Schedule Approvals</strong>.</li>
                                            <li>Review create/update/delete requests.</li>
                                            <li>Approve or reject; approved slots apply to the class schedule.</li>
                                        </ol>
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
                                            'assets/images/tutorials/admin/scheduling/schedules.png',
                                            'Admin schedules page',
                                            'Schedules: add active schedule slots per class record.'
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
                                    <h4 class="header-title mb-2">5) Approvals (Enrollment, Profile, Schedule)</h4>
                                    <p class="text-muted mb-3">
                                        Admin approvals are used to keep data integrity and control profile changes and schedule updates.
                                    </p>
                                    <ol class="mb-0 ps-3">
                                        <li><strong>Enrollment Approvals</strong>: approve teacher-submitted enrollment requests.</li>
                                        <li><strong>Profile Approvals</strong>: approve/reject user profile update requests.</li>
                                        <li><strong>Schedule Approvals</strong>: approve/reject teacher schedule change requests.</li>
                                    </ol>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-4">
                            <div class="card h-100">
                                <div class="card-body">
                                    <h4 class="header-title mb-3">Screenshot</h4>
                                    <?php
                                        echo tutorial_shot(
                                            'assets/images/tutorials/admin/approvals/profile-approvals.png',
                                            'Admin profile approvals',
                                            'Profile Approvals: review pending profile change requests.'
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
                                    <h4 class="header-title mb-2">6) Audit Log</h4>
                                    <p class="text-muted mb-3">
                                        Use Audit Log to review important events (logins, edits, messaging, approvals).
                                    </p>
                                    <ol class="mb-0 ps-3">
                                        <li>Open <strong>Audit Log</strong>.</li>
                                        <li>Filter by user, action, or date range.</li>
                                        <li>Use audit trails when troubleshooting access or data issues.</li>
                                    </ol>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-4">
                            <div class="card h-100">
                                <div class="card-body">
                                    <h4 class="header-title mb-3">Screenshot</h4>
                                    <?php
                                        echo tutorial_shot(
                                            'assets/images/tutorials/admin/audit/audit-log.png',
                                            'Admin audit log',
                                            'Audit Log: track actions and troubleshoot.'
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
                                    <h4 class="header-title mb-2">7) References, Subjects, and Sections</h4>
                                    <p class="text-muted mb-3">
                                        References and master data control what appears in enrollments, schedules, and class records.
                                    </p>
                                    <ol class="mb-0 ps-3">
                                        <li>Use <strong>References</strong> to maintain Academic Years and Semesters.</li>
                                        <li>Use <strong>Subjects</strong> to add/edit subjects available for enrollment and scheduling.</li>
                                        <li>Use <strong>Sections</strong> to add/edit section definitions.</li>
                                    </ol>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-4">
                            <div class="card h-100">
                                <div class="card-body">
                                    <h4 class="header-title mb-3">Screenshot</h4>
                                    <?php
                                        echo tutorial_shot(
                                            'assets/images/tutorials/admin/references/references.png',
                                            'Admin references page',
                                            'References: Academic Years and Semesters.'
                                        );
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title mb-2">Program Chair Setup (for Reports)</h4>
                                    <p class="text-muted mb-3">
                                        Program Chair is used as the approver label/signature in Monthly Accomplishment Reports (and can be assigned per subject for teachers).
                                    </p>
                                    <ol class="mb-0 ps-3">
                                        <li>Create an account with role <code>program_chair</code> and activate it.</li>
                                        <li>Assign Program Chair in teacher profile requests (or teacher subject mapping if available).</li>
                                        <li>When printing Monthly Accomplishment, the approver name can resolve per subject.</li>
                                    </ol>
                                    <div class="mt-3">
                                        <a class="btn btn-sm btn-outline-primary" href="tutorial-program-chair.php">Open Program Chair Tutorial</a>
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

