<?php include '../layouts/session.php'; ?>
<?php require_any_role(['teacher', 'admin']); ?>
<?php include '../layouts/main.php'; ?>
<?php require_once __DIR__ . '/../includes/tutorials.php'; ?>

<head>
    <title>Tutorial: Teacher Guide | E-Record</title>
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
                                    <a class="btn btn-sm btn-outline-primary mb-2" href="tutorials.php?role=teacher">
                                        <i class="ri-arrow-left-line me-1" aria-hidden="true"></i>
                                        Tutorials Hub
                                    </a>
                                    <ol class="breadcrumb m-0">
                                        <li class="breadcrumb-item"><a href="teacher-dashboard.php">Teacher</a></li>
                                        <li class="breadcrumb-item">Tutorials</li>
                                        <li class="breadcrumb-item active">Teacher Guide</li>
                                    </ol>
                                </div>
                                <h4 class="page-title">Tutorial: Teacher Guide (All Features)</h4>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-12">
                            <div class="tutorial-callout mb-3">
                                <div class="fw-semibold">Notes</div>
                                <div class="text-muted small">
                                    Teacher access depends on admin assignment and activation. If you do not see a class, ask admin to assign you to the subject/section/term.
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
                                        <a class="btn btn-primary" href="teacher-dashboard.php">Dashboard</a>
                                        <a class="btn btn-outline-primary" href="teacher-my-classes.php">My Classes</a>
                                        <a class="btn btn-outline-primary" href="teacher-learning-materials.php">Learning Materials</a>
                                        <a class="btn btn-outline-primary" href="teacher-assessment-builder.php">Assessment Builder</a>
                                        <a class="btn btn-outline-primary" href="teacher-assignment-builder.php">Assignment Builder</a>
                                        <a class="btn btn-outline-primary" href="teacher-builds.php">Class Record Builds</a>
                                        <a class="btn btn-outline-primary" href="teacher-grading-config.php">Grading Config</a>
                                        <a class="btn btn-outline-primary" href="teacher-claim.php">Enrollment Requests</a>
                                        <a class="btn btn-outline-primary" href="teacher-schedule.php">Schedule</a>
                                        <a class="btn btn-outline-primary" href="teacher-attendance-uploads.php">Attendance Check-In</a>
                                        <a class="btn btn-outline-secondary" href="teacher-wheel.php">Class Wheel</a>
                                        <a class="btn btn-light" href="monthly-accomplishment.php">Monthly Accomplishment</a>
                                        <a class="btn btn-light" href="accomplishment-creator.php">Accomplishment Creator</a>
                                        <a class="btn btn-light" href="messages.php">Messages</a>
                                        <a class="btn btn-light" href="pages-profile.php">Profile</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-lg-8">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title mb-2">1) My Classes</h4>
                                    <p class="text-muted mb-3">
                                        My Classes is your entrypoint to class records, learning materials, and assessment tools for each assigned subject/section/term.
                                    </p>
                                    <ol class="mb-0 ps-3">
                                        <li>Open <strong>My Classes</strong>.</li>
                                        <li>Pick the class record you want to manage.</li>
                                        <li>Use the class actions to open learning materials, grading config, assessments, and submissions.</li>
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
                                            'assets/images/tutorials/teacher/my-classes/my-classes.png',
                                            'Teacher My Classes page',
                                            'My Classes: shows assigned classes and shortcuts to class tools.'
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
                                    <h4 class="header-title mb-2">2) Learning Materials</h4>
                                    <p class="text-muted mb-3">
                                        Create and publish learning materials per class, then students can view them inside the student portal.
                                    </p>
                                    <ol class="mb-0 ps-3">
                                        <li>Open <strong>Learning Materials</strong>.</li>
                                        <li>Select the class record.</li>
                                        <li>Create a material (title + content + attachments if supported).</li>
                                        <li>Preview then publish so students can see it.</li>
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
                                            'assets/images/tutorials/teacher/learning-materials/learning-materials.png',
                                            'Teacher Learning Materials',
                                            'Learning Materials: create, edit, preview, and publish.'
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
                                    <h4 class="header-title mb-2">3) Assessments and Assignments</h4>
                                    <p class="text-muted mb-3">
                                        Assessments belong to grading components, and student scores appear in the student dashboard.
                                    </p>
                                    <div class="tutorial-step">
                                        <div class="fw-semibold">Create an assessment (Quiz / Module)</div>
                                        <ol class="mb-0 ps-3">
                                            <li>Open <strong>Assessment Builder</strong>.</li>
                                            <li>Select a class record and component.</li>
                                            <li>Create the assessment (name, max score, open/close, attempts, time limit).</li>
                                            <li>Add questions/items, then publish.</li>
                                        </ol>
                                    </div>
                                    <div class="tutorial-step">
                                        <div class="fw-semibold">Create an assignment</div>
                                        <ol class="mb-0 ps-3">
                                            <li>Open <strong>Assignment Builder</strong>.</li>
                                            <li>Select class record and component.</li>
                                            <li>Create assignment instructions and set due/open/close windows.</li>
                                            <li>Review submissions in <strong>Assignment Submissions</strong>.</li>
                                        </ol>
                                    </div>
                                    <div class="tutorial-step">
                                        <div class="fw-semibold">View scores</div>
                                        <ol class="mb-0 ps-3">
                                            <li>Open <strong>Assessment Scores</strong>.</li>
                                            <li>Select assessment and review student attempts/scores.</li>
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
                                            'assets/images/tutorials/teacher/assessments/assessment-builder.png',
                                            'Assessment Builder',
                                            'Assessment Builder: create quizzes/modules and publish them to students.'
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
                                    <h4 class="header-title mb-2">4) Grading Config and Class Record Builds</h4>
                                    <p class="text-muted mb-3">
                                        Configure components and weights per class/term. Use Builds to reuse grading structures across multiple classes.
                                    </p>
                                    <ol class="mb-0 ps-3">
                                        <li>Open <strong>Grading Config</strong> for the target class record.</li>
                                        <li>Create or edit categories and components (with weights).</li>
                                        <li>Add assessments under components (quizzes, assignments, etc.).</li>
                                        <li>Use <strong>Class Record Builds</strong> to create reusable templates and copy builds across classes.</li>
                                    </ol>
                                    <div class="text-muted small mt-2">
                                        Tip: Copy build can reuse from any assigned class (even different subjects).
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
                                            'assets/images/tutorials/teacher/grading/grading-config.png',
                                            'Teacher grading config',
                                            'Grading Config: manage categories, components, weights, and assessments.'
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
                                    <h4 class="header-title mb-2">5) Enrollment Requests</h4>
                                    <p class="text-muted mb-3">
                                        Teachers can submit enrollment requests, but admin must approve them before students become active in roster/class record.
                                    </p>
                                    <ol class="mb-0 ps-3">
                                        <li>Open <strong>Enrollment Requests</strong>.</li>
                                        <li>Review pending requests.</li>
                                        <li>Submit/claim requests (status becomes <code>TeacherPending</code>).</li>
                                        <li>Follow up with admin to approve in <strong>Enrollment Approvals</strong>.</li>
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
                                            'assets/images/tutorials/teacher/enrollment-requests/teacher-claim.png',
                                            'Teacher enrollment requests',
                                            'Enrollment Requests: review and submit enrollment changes to admin.'
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
                                    <h4 class="header-title mb-2">6) Schedule</h4>
                                    <p class="text-muted mb-3">
                                        View your schedule in daily/weekly views and submit schedule slot change requests for admin approval.
                                    </p>
                                    <ol class="mb-0 ps-3">
                                        <li>Open <strong>Schedule</strong>.</li>
                                        <li>Switch between Daily / Weekly views.</li>
                                        <li>Create/update/delete slot requests (admin must approve).</li>
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
                                            'assets/images/tutorials/teacher/schedule/teacher-schedule.png',
                                            'Teacher schedule dashboard',
                                            'Schedule: daily/weekly views and request workflow.'
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
                                    <h4 class="header-title mb-2">7) Attendance Check-In</h4>
                                    <p class="text-muted mb-3">
                                        Manage attendance check-in configurations and view uploads/check-ins per class (if enabled).
                                    </p>
                                    <ol class="mb-0 ps-3">
                                        <li>Open <strong>Attendance Check-In</strong>.</li>
                                        <li>Select the class record.</li>
                                        <li>Follow the page controls to start/stop check-in windows and review submissions.</li>
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
                                            'assets/images/tutorials/teacher/attendance/attendance-checkin.png',
                                            'Teacher attendance check-in',
                                            'Attendance Check-In: manage check-in and review student submissions.'
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
                                    <h4 class="header-title mb-2">8) Monthly Accomplishment and Reports</h4>
                                    <p class="text-muted mb-3">
                                        Create Monthly Accomplishment Reports with image proofs, and print/export when complete.
                                    </p>
                                    <ol class="mb-0 ps-3">
                                        <li>Open <strong>Monthly Accomplishment</strong>.</li>
                                        <li>Create entries with dates, title, and proof images.</li>
                                        <li>Print the report using the print view.</li>
                                    </ol>
                                    <div class="text-muted small mt-2">
                                        Tip: Program Chair approver label may resolve per subject (if configured by admin).
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
                                            'assets/images/tutorials/teacher/accomplishment/monthly-accomplishment.png',
                                            'Monthly Accomplishment page',
                                            'Monthly Accomplishment: add entries with proof images and print.'
                                        );
                                    ?>
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

