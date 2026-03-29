<?php include 'layouts/session.php'; ?>
<?php include 'layouts/main.php'; ?>

<head>
    <title>ChartJs Line Chart | Attex - Bootstrap 5 Admin & Dashboard Template</title>
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
                                        <li class="breadcrumb-item active">Line Chart</li>
                                    </ol>
                                </div>
                                <h4 class="page-title">Line Chart</h4>
                            </div>
                        </div>
                    </div>


                    <div class="row">
                        <div class="col-xl-6">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title mb-4">Interpolation</h4>

                                    <div dir="ltr">
                                        <div class="mt-3 chartjs-chart" style="height: 320px;">
                                            <canvas id="interpolation-example" data-colors="#3e60d5,#47ad77"></canvas>
                                        </div>
                                    </div>
                                </div> <!-- end card body-->
                            </div> <!-- end card -->
                        </div><!-- end col-->

                        <div class="col-xl-6">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title mb-4">Line</h4>

                                    <div dir="ltr">
                                        <div class="mt-3 chartjs-chart" style="height: 320px;">
                                            <canvas id="line-example" data-colors="#3e60d5,#47ad77"></canvas>
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
                                    <h4 class="header-title mb-4">Multi-Axes</h4>

                                    <div dir="ltr">
                                        <div class="mt-3 chartjs-chart" style="height: 320px;">
                                            <canvas id="multi-axes-example" data-colors="#3e60d5,#47ad77"></canvas>
                                        </div>
                                    </div>
                                </div> <!-- end card body-->
                            </div> <!-- end card -->
                        </div><!-- end col-->

                        <div class="col-xl-6">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title mb-4">Point Styling</h4>

                                    <div dir="ltr">
                                        <div class="mt-3 chartjs-chart" style="height: 320px;">
                                            <canvas id="point-styling-example" data-colors="#3e60d5,#47ad77"></canvas>
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
                                    <h4 class="header-title mb-4">Line Segment</h4>

                                    <div dir="ltr">
                                        <div class="mt-3 chartjs-chart" style="height: 320px;">
                                            <canvas id="line-segment-example" data-colors="#3e60d5,#47ad77"></canvas>
                                        </div>
                                    </div>
                                </div> <!-- end card body-->
                            </div> <!-- end card -->
                        </div><!-- end col-->

                        <div class="col-xl-6">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title mb-4">Stepped</h4>

                                    <div dir="ltr">
                                        <div class="mt-3 chartjs-chart" style="height: 320px;">
                                            <canvas id="stepped-example" data-colors="#3e60d5,#47ad77"></canvas>
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

    <!-- Chart.js-->
    <script src="assets/vendor/chart.js/chart.min.js"></script>

    <!-- Chart.js Line Demo js -->
    <script src="assets/js/pages/demo.chartjs-line.js"></script>

    <!-- App js -->
    <script src="assets/js/app.min.js"></script>
</body>

</html>