<?php include '../layouts/session.php'; ?>
<?php require_any_role(['student', 'admin']); ?>
<?php include '../layouts/main.php'; ?>
<?php require_once __DIR__ . '/../includes/tutorials.php'; ?>

<head>
    <title>Tutorial: Student Guide | E-Record</title>
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
                                    <a class="btn btn-sm btn-outline-primary mb-2" href="tutorials.php?role=student">
                                        <i class="ri-arrow-left-line me-1" aria-hidden="true"></i>
                                        Tutorials Hub
                                    </a>
                                    <ol class="breadcrumb m-0">
                                        <li class="breadcrumb-item"><a href="student-dashboard.php">Student</a></li>
                                        <li class="breadcrumb-item">Tutorials</li>
                                        <li class="breadcrumb-item active">Student Guide</li>
                                    </ol>
                                </div>
                                <h4 class="page-title">Tutorial: Student Guide (All Features)</h4>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-12">
                            <div class="tutorial-callout mb-3">
                                <div class="fw-semibold">Before you start</div>
                                <div class="text-muted small">
                                    Your account must be activated and linked to your student profile. If you cannot access the dashboard or your classes are missing, contact the admin.
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
                                        <a class="btn btn-primary" href="student-dashboard.php">My Grades & Scores</a>
                                        <a class="btn btn-outline-primary" href="student-learning-materials.php">Learning Materials</a>
                                        <a class="btn btn-outline-primary" href="student-attendance.php">Attendance Check-In</a>
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
                                    <h4 class="header-title mb-2">1) My Grades & Scores (Dashboard)</h4>
                                    <p class="text-muted mb-3">
                                        Use the dashboard to view your enrolled subjects and track scores per assessment/component for Midterm and Final terms.
                                    </p>
                                    <ol class="mb-0 ps-3">
                                        <li>Open <strong>My Grades & Scores</strong>.</li>
                                        <li>Select a subject/class record card to view its term breakdown.</li>
                                        <li>Review components, assessments, and your scores/status.</li>
                                        <li>Open quizzes/assignments from the assessment list when available.</li>
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
                                            'assets/images/tutorials/student/dashboard/student-dashboard.png',
                                            'Student dashboard',
                                            'Student Dashboard: subjects, term breakdown, assessments, and score status.'
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
                                        Learning Materials are posted by your teacher per class.
                                    </p>
                                    <ol class="mb-0 ps-3">
                                        <li>Open <strong>Learning Materials</strong>.</li>
                                        <li>Select a subject/class record.</li>
                                        <li>Open a material to read content and download attachments (if any).</li>
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
                                            'assets/images/tutorials/student/learning-materials/student-learning-materials.png',
                                            'Student learning materials list',
                                            'Learning Materials: browse materials by class and open details.'
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
                                    <h4 class="header-title mb-2">3) Quizzes and Assessments</h4>
                                    <p class="text-muted mb-3">
                                        Some assessments are timed and may limit attempts. Read the assessment settings before starting.
                                    </p>
                                    <ol class="mb-0 ps-3">
                                        <li>From <strong>My Grades & Scores</strong>, find the assessment under a component.</li>
                                        <li>Click the assessment to open the module.</li>
                                        <li>Start the attempt. If there is a time limit, submit before it ends.</li>
                                        <li>After submission, return to the dashboard to view updated status/score (if released).</li>
                                    </ol>
                                    <div class="text-muted small mt-2">
                                        Tip: If you have an in-progress attempt, reopen it from the assessment list.
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
                                            'assets/images/tutorials/student/assessments/student-quiz-attempt.png',
                                            'Student quiz attempt',
                                            'Quiz Attempt: answer questions, track remaining time, then submit.'
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
                                    <h4 class="header-title mb-2">4) Assignments</h4>
                                    <p class="text-muted mb-3">
                                        Assignments may require file uploads. Always confirm your submission status after uploading.
                                    </p>
                                    <ol class="mb-0 ps-3">
                                        <li>Open the assignment from your assessment list (Dashboard).</li>
                                        <li>Read instructions and allowed submission window (open/close).</li>
                                        <li>Upload the required file(s), then submit.</li>
                                        <li>Confirm status shows <code>submitted</code> (or similar) after submission.</li>
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
                                            'assets/images/tutorials/student/assignments/student-assignment.png',
                                            'Student assignment submission',
                                            'Assignment: upload and submit files, then verify status.'
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
                                    <h4 class="header-title mb-2">5) Attendance Check-In</h4>
                                    <p class="text-muted mb-3">
                                        Attendance Check-In requires an active attendance session and a code provided by your teacher.
                                    </p>
                                    <ol class="mb-0 ps-3">
                                        <li>Open <strong>Attendance Check-In</strong>.</li>
                                        <li>Select the correct class record/subject.</li>
                                        <li>Select the active session, then enter the attendance code.</li>
                                        <li>Submit and confirm the result (present/late).</li>
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
                                            'assets/images/tutorials/student/attendance/student-attendance.png',
                                            'Student attendance check-in',
                                            'Attendance Check-In: choose class/session and submit the code.'
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

