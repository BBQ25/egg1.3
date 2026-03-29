<?php include 'layouts/session.php'; ?>
<?php include 'layouts/main.php'; ?>

<head>
    <title>ChartJs Other Chart | Attex - Bootstrap 5 Admin & Dashboard Template</title>
    <?php include 'layouts/title-meta.php'; ?>
    <?php include 'layouts/head-css.php'; ?>
</head>

<body>
    <!-- Begin page -->
    <div class="wrapper">

        <?php include 'layouts/menu.php'; ?>

        <!-- ============================================================== -->
        <!-- Start Page Content here -->
        <!-- ============================================================== -->

        <div class="content-page">
            <div class="content">

                <!-- Start Content-->
                <div class="container-fluid">

                    <div class="row">
                        <div class="col-12">
                            <div class="page-title-box">
                                <div class="page-title-right">
                                    <ol class="breadcrumb m-0">
                                        <li class="breadcrumb-item"><a href="javascript: void(0);">Attex</a></li>
                                        <li class="breadcrumb-item"><a href="javascript: void(0);">ChartJs</a></li>
                                        <li class="breadcrumb-item active">Other Chart</li>
                                    </ol>
                                </div>
                                <h4 class="page-title">Other Chart</h4>
                            </div>
                        </div>
                    </div>



                    <div class="row">
                        <div class="col-xl-6">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title mb-4">Bubble</h4>

                                    <div dir="ltr">
                                        <div class="mt-3 chartjs-chart" style="height: 320px;">
                                            <canvas id="bubble-example" data-colors="#3e60d5,#47ad77"></canvas>
                                        </div>
                                    </div>
                                </div> <!-- end card body-->
                            </div> <!-- end card -->
                        </div><!-- end col-->

                        <div class="col-xl-6">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title mb-4">DONUT</h4>

                                    <div dir="ltr">
                                        <div class="mt-3 chartjs-chart" style="height: 320px;">
                                            <canvas id="donut-example" data-colors="#3e60d5,#fa5c7c,#47ad77,#ebeff2"></canvas>
                                        </div>
                                    </div>
                                </div> <!-- end card body-->
                            </div> <!-- end card -->
                        </div><!-- end col-->
                    </div>
                    <!-- end row-->


                    <div class="row">
                        <div class="col-xl-6">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title mb-4">Pie</h4>

                                    <div dir="ltr">
                                        <div class="mt-3 chartjs-chart" style="height: 320px;">
                                            <canvas id="pie-example" data-colors="#3e60d5,#fa5c7c,#47ad77,#ebeff2, #f56f36"></canvas>
                                        </div>
                                    </div>
                                </div> <!-- end card body-->
                            </div> <!-- end card -->
                        </div><!-- end col-->

                        <div class="col-xl-6">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title mb-4">Polar Area</h4>

                                    <div dir="ltr">
                                        <div class="mt-3 chartjs-chart" style="height: 320px;">
                                            <canvas id="polar-area-example" data-colors="#3e60d5,#fa5c7c,#47ad77,#ebeff2, #f56f36"></canvas>
                                        </div>
                                    </div>
                                </div> <!-- end card body-->
                            </div> <!-- end card -->
                        </div><!-- end col-->
                    </div>
                    <!-- end row-->

                    <div class="row">
                        <div class="col-xl-6">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title mb-4">Radar</h4>

                                    <div dir="ltr">
                                        <div class="mt-3 chartjs-chart" style="height: 320px;">
                                            <canvas id="radar-example" data-colors="#3e60d5,#47ad77"></canvas>
                                        </div>
                                    </div>
                                </div> <!-- end card body-->
                            </div> <!-- end card -->
                        </div><!-- end col-->

                        <div class="col-xl-6">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title mb-4">Scatter</h4>

                                    <div dir="ltr">
                                        <div class="mt-3 chartjs-chart" style="height: 320px;">
                                            <canvas id="scatter-example" data-colors="#3e60d5,#47ad77"></canvas>
                                        </div>
                                    </div>
                                </div> <!-- end card body-->
                            </div> <!-- end card -->
                        </div><!-- end col-->
                    </div>

                    <!-- end row-->
                    <div class="row">
                        <div class="col-xl-6">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title mb-4">Stacked Bar/Line</h4>

                                    <div dir="ltr">
                                        <div class="mt-3 chartjs-chart" style="height: 320px;">
                                            <canvas id="bar-line-example" data-colors="#3e60d5,#47ad77"></canvas>
                                        </div>
                                    </div>
                                </div> <!-- end card body-->
                            </div> <!-- end card -->
                        </div><!-- end col-->

                    </div>
                    <!-- end row-->

                </div> <!-- container -->

            </div> <!-- content -->

            <?php include 'layouts/footer.php'; ?>

        </div>

        <!-- ============================================================== -->
        <!-- End Page content -->
        <!-- ============================================================== -->
    </div>

    </div>
    <!-- END wrapper -->

    <?php include 'layouts/right-sidebar.php'; ?>

    <?php include 'layouts/footer-scripts.php'; ?>


    <!-- Chart.js -->
    <script src="assets/vendor/chart.js/chart.min.js"></script>

    <!-- Chart.js Other Demo js -->
    <script src="assets/js/pages/demo.chartjs-other.js"></script>
    <!-- App js -->
    <script src="assets/js/app.min.js"></script>

</body>

</html>