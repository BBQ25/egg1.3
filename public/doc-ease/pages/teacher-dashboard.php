<?php include '../layouts/session.php'; ?>
<?php require_active_role('teacher'); ?>
<?php include '../layouts/main.php'; ?>

<?php
$teacherId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;

if (!function_exists('td_h')) {
    function td_h($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('td_normalize_sex_label')) {
    function td_normalize_sex_label($value) {
        $v = strtoupper(trim((string) $value));
        if ($v === 'M' || $v === 'MALE') return 'Male';
        if ($v === 'F' || $v === 'FEMALE') return 'Female';
        if ($v === 'OTHER') return 'Other';
        return 'N/A';
    }
}

if (!function_exists('td_build_distribution')) {
    function td_build_distribution(array $rows, $labelKey, $valueKey, $maxItems = 0) {
        $counts = [];
        foreach ($rows as $row) {
            $label = trim((string) ($row[$labelKey] ?? 'N/A'));
            if ($label === '') $label = 'N/A';
            $value = (int) ($row[$valueKey] ?? 0);
            $counts[$label] = $value;
        }
        arsort($counts);

        if ((int) $maxItems > 0 && count($counts) > (int) $maxItems) {
            $items = array_slice($counts, 0, (int) $maxItems, true);
            $others = array_slice($counts, (int) $maxItems, null, true);
            $otherTotal = 0;
            foreach ($others as $v) $otherTotal += (int) $v;
            if ($otherTotal > 0) $items['Other'] = $otherTotal;
            $counts = $items;
        }

        if (count($counts) === 0) {
            return [
                'labels' => ['No Data'],
                'series' => [0],
            ];
        }

        return [
            'labels' => array_values(array_keys($counts)),
            'series' => array_values(array_map('intval', array_values($counts))),
        ];
    }
}

$totalEnrolledStudents = 0;
$sexRows = [];
$courseRows = [];
$yearRows = [];

if ($teacherId > 0) {
    // Distinct students across this teacher's active classes (active enrollments only).
    $baseFrom = " FROM class_enrollments ce
                  JOIN class_records cr ON cr.id = ce.class_record_id
                  JOIN teacher_assignments ta ON ta.class_record_id = cr.id
                  JOIN students st ON st.id = ce.student_id
                  WHERE ta.teacher_id = ?
                    AND ta.status = 'active'
                    AND cr.status = 'active'
                    AND ce.status = 'enrolled' ";

    $countSql = "SELECT COUNT(DISTINCT st.id) AS total_students " . $baseFrom;
    $countStmt = $conn->prepare($countSql);
    if ($countStmt) {
        $countStmt->bind_param('i', $teacherId);
        $countStmt->execute();
        $countRes = $countStmt->get_result();
        if ($countRes && $countRes->num_rows === 1) {
            $totalEnrolledStudents = (int) (($countRes->fetch_assoc()['total_students'] ?? 0));
        }
        $countStmt->close();
    }

    $sexSql = "SELECT x.sex_label, COUNT(*) AS total_count
               FROM (
                    SELECT DISTINCT st.id,
                           CASE
                               WHEN UPPER(TRIM(COALESCE(st.Sex, ''))) IN ('M', 'MALE') THEN 'Male'
                               WHEN UPPER(TRIM(COALESCE(st.Sex, ''))) IN ('F', 'FEMALE') THEN 'Female'
                               WHEN UPPER(TRIM(COALESCE(st.Sex, ''))) = 'OTHER' THEN 'Other'
                               ELSE 'N/A'
                           END AS sex_label
                    " . $baseFrom . "
               ) x
               GROUP BY x.sex_label
               ORDER BY total_count DESC, x.sex_label ASC";
    $sexStmt = $conn->prepare($sexSql);
    if ($sexStmt) {
        $sexStmt->bind_param('i', $teacherId);
        $sexStmt->execute();
        $sexRes = $sexStmt->get_result();
        while ($sexRes && ($row = $sexRes->fetch_assoc())) $sexRows[] = $row;
        $sexStmt->close();
    }

    $courseSql = "SELECT x.course_label, COUNT(*) AS total_count
                  FROM (
                        SELECT DISTINCT st.id,
                               COALESCE(NULLIF(TRIM(COALESCE(st.Course, '')), ''), 'N/A') AS course_label
                        " . $baseFrom . "
                  ) x
                  GROUP BY x.course_label
                  ORDER BY total_count DESC, x.course_label ASC";
    $courseStmt = $conn->prepare($courseSql);
    if ($courseStmt) {
        $courseStmt->bind_param('i', $teacherId);
        $courseStmt->execute();
        $courseRes = $courseStmt->get_result();
        while ($courseRes && ($row = $courseRes->fetch_assoc())) $courseRows[] = $row;
        $courseStmt->close();
    }

    $yearSql = "SELECT x.year_label, COUNT(*) AS total_count
                FROM (
                    SELECT DISTINCT st.id,
                           COALESCE(NULLIF(TRIM(COALESCE(st.Year, '')), ''), 'N/A') AS year_label
                    " . $baseFrom . "
                ) x
                GROUP BY x.year_label
                ORDER BY total_count DESC, x.year_label ASC";
    $yearStmt = $conn->prepare($yearSql);
    if ($yearStmt) {
        $yearStmt->bind_param('i', $teacherId);
        $yearStmt->execute();
        $yearRes = $yearStmt->get_result();
        while ($yearRes && ($row = $yearRes->fetch_assoc())) $yearRows[] = $row;
        $yearStmt->close();
    }
}

$sexChart = td_build_distribution($sexRows, 'sex_label', 'total_count');
$courseChart = td_build_distribution($courseRows, 'course_label', 'total_count', 8);
$yearChart = td_build_distribution($yearRows, 'year_label', 'total_count');
?>

<head>
    <title>Dashboard | E-Record</title>
    <?php include '../layouts/title-meta.php'; ?>
    <?php include '../layouts/head-css.php'; ?>
    <style>
        .teacher-dashboard-chart {
            min-height: 320px;
        }
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
                                        <li class="breadcrumb-item active">Dashboard</li>
                                    </ol>
                                </div>
                                <h4 class="page-title">Dashboard</h4>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                        <div>
                                            <h4 class="header-title mb-1">Notes</h4>
                                            <div class="text-muted small">Quick reminders for your workflow.</div>
                                        </div>
                                        <div class="d-flex gap-2 flex-wrap">
                                            <a class="btn btn-sm btn-outline-primary" href="teacher-my-classes.php">
                                                <i class="ri-book-open-line me-1" aria-hidden="true"></i>
                                                My Classes
                                            </a>
                                            <a class="btn btn-sm btn-outline-primary" href="teacher-claim.php">
                                                <i class="ri-team-line me-1" aria-hidden="true"></i>
                                                Enrollment Requests
                                            </a>
                                            <a class="btn btn-sm btn-outline-primary" href="teacher-schedule.php">
                                                <i class="ri-calendar-schedule-line me-1" aria-hidden="true"></i>
                                                Schedule
                                            </a>
                                            <a class="btn btn-sm btn-outline-primary" href="grade-record-seed-preview.php">
                                                <i class="ri-eye-line me-1" aria-hidden="true"></i>
                                                Grade Seed Preview
                                            </a>
                                            <a class="btn btn-sm btn-outline-secondary" href="messages.php">
                                                <i class="ri-message-3-line me-1" aria-hidden="true"></i>
                                                Messages
                                            </a>
                                        </div>
                                    </div>

                                    <div class="mt-3">
                                        <div class="alert alert-info mb-0">
                                            <ul class="mb-0 ps-3">
                                                <li>Class Record Builds can be reused across any subjects you handle.</li>
                                                <li>Use <strong>Components &amp; Weights</strong> to copy builds from your other assigned classes (even different subjects).</li>
                                                <li>Print uses A4 landscape view for quick hardcopy checking.</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                                        <div>
                                            <h4 class="header-title mb-1">Enrolled Student Demographics</h4>
                                            <div class="text-muted small">Distinct students across your active assigned classes.</div>
                                        </div>
                                        <span class="badge bg-primary-subtle text-primary">
                                            Total Enrolled: <?php echo (int) $totalEnrolledStudents; ?>
                                        </span>
                                    </div>

                                    <div class="row g-3">
                                        <div class="col-xl-4 col-md-6">
                                            <div class="border rounded p-2 h-100">
                                                <h5 class="mb-2">Sex / Gender</h5>
                                                <div id="teacher-sex-chart" class="teacher-dashboard-chart"></div>
                                            </div>
                                        </div>
                                        <div class="col-xl-4 col-md-6">
                                            <div class="border rounded p-2 h-100">
                                                <h5 class="mb-2">Course</h5>
                                                <div id="teacher-course-chart" class="teacher-dashboard-chart"></div>
                                            </div>
                                        </div>
                                        <div class="col-xl-4 col-md-12">
                                            <div class="border rounded p-2 h-100">
                                                <h5 class="mb-2">Year Level</h5>
                                                <div id="teacher-year-chart" class="teacher-dashboard-chart"></div>
                                            </div>
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
    <script src="assets/vendor/apexcharts/apexcharts.min.js"></script>
    <script>
        (function () {
            if (typeof ApexCharts === 'undefined') return;

            var sexData = {
                labels: <?php echo json_encode((array) ($sexChart['labels'] ?? [])); ?>,
                series: <?php echo json_encode((array) ($sexChart['series'] ?? [])); ?>
            };
            var courseData = {
                labels: <?php echo json_encode((array) ($courseChart['labels'] ?? [])); ?>,
                series: <?php echo json_encode((array) ($courseChart['series'] ?? [])); ?>
            };
            var yearData = {
                labels: <?php echo json_encode((array) ($yearChart['labels'] ?? [])); ?>,
                series: <?php echo json_encode((array) ($yearChart['series'] ?? [])); ?>
            };

            function renderBar(selector, color, dataObj) {
                var el = document.querySelector(selector);
                if (!el || !dataObj || !Array.isArray(dataObj.labels) || !Array.isArray(dataObj.series)) return;

                var options = {
                    chart: {
                        type: 'bar',
                        height: 320,
                        toolbar: { show: false }
                    },
                    series: [{
                        name: 'Students',
                        data: dataObj.series
                    }],
                    xaxis: {
                        categories: dataObj.labels,
                        labels: {
                            trim: true
                        }
                    },
                    colors: [color],
                    plotOptions: {
                        bar: {
                            borderRadius: 4,
                            horizontal: false,
                            columnWidth: '52%'
                        }
                    },
                    yaxis: {
                        min: 0,
                        forceNiceScale: true,
                        labels: {
                            formatter: function (val) {
                                return Math.round(val);
                            }
                        }
                    },
                    tooltip: {
                        y: {
                            formatter: function (val) {
                                return String(Math.round(val)) + ' student(s)';
                            }
                        }
                    },
                    dataLabels: {
                        enabled: true,
                        formatter: function (val) {
                            return String(Math.round(val));
                        }
                    },
                    noData: {
                        text: 'No enrollment data'
                    }
                };

                new ApexCharts(el, options).render();
            }

            renderBar('#teacher-sex-chart', '#3e60d5', sexData);
            renderBar('#teacher-course-chart', '#16a7e9', courseData);
            renderBar('#teacher-year-chart', '#47ad77', yearData);
        })();
    </script>
    <script src="assets/js/app.min.js"></script>
</body>
</html>


