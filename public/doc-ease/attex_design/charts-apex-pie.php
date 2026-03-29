<?php include 'layouts/session.php'; ?>
<?php include 'layouts/main.php'; ?>

<head>
    <title>Apex Pie Chart | Attex - Bootstrap 5 Admin & Dashboard Template</title>
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
                                        <li class="breadcrumb-item"><a href="javascript: void(0);">Apex</a></li>
                                        <li class="breadcrumb-item active">Pie Chart</li>
                                    </ol>
                                </div>
                                <h4 class="page-title">Pie Chart</h4>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-xl-6">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title">Simple Pie Chart</h4>
                                    <div dir="ltr">
                                        <div id="simple-pie" class="apex-charts" data-colors="#3e60d5,#6c757d,#47ad77,#fa5c7c,#e3eaef"></div>
                                    </div>
                                </div>
                                <!-- end card body-->
                            </div>
                            <!-- end card -->
                        </div>
                        <!-- end col-->

                        <div class="col-xl-6">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title">Simple Donut Chart</h4>
                                    <div dir="ltr">
                                        <div id="simple-donut" class="apex-charts" data-colors="#39afd1,#ffbc00,#313a46,#fa5c7c,#47ad77"></div>
                                    </div>
                                </div>
                                <!-- end card body-->
                            </div>
                            <!-- end card -->
                        </div>
                        <!-- end col-->
                    </div>
                    <!-- end row-->

                    <div class="row">
                        <div class="col-xl-6">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title mb-4">Monochrome Pie Chart</h4>
                                    <div dir="ltr">
                                        <div id="monochrome-pie" class="apex-charts"></div>
                                    </div>
                                </div>
                                <!-- end card body-->
                            </div>
                            <!-- end card -->
                        </div>
                        <!-- end col-->

                        <div class="col-xl-6">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title mb-4">Gradient Donut Chart</h4>
                                    <div dir="ltr">
                                        <div id="gradient-donut" class="apex-charts" data-colors="#3e60d5,#6c757d,#47ad77,#fa5c7c,#e3eaef"></div>
                                    </div>
                                </div>
                                <!-- end card body-->
                            </div>
                            <!-- end card -->
                        </div>
                        <!-- end col-->
                    </div>
                    <!-- end row-->

                    <div class="row">
                        <div class="col-xl-6">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title mb-4">Patterned Donut Chart</h4>
                                    <div dir="ltr">
                                        <div id="patterned-donut" class="apex-charts" data-colors="#39afd1,#ffbc00,#313a46,#fa5c7c,#47ad77"></div>
                                    </div>
                                </div>
                                <!-- end card body-->
                            </div>
                            <!-- end card -->
                        </div>
                        <!-- end col-->

                        <div class="col-xl-6">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title mb-4">Pie Chart with Image fill</h4>
                                    <div dir="ltr">
                                        <div id="image-pie" class="apex-charts" data-colors="#39afd1,#ffbc00,#3e60d5,#47ad77"></div>
                                    </div>
                                </div>
                                <!-- end card body-->
                            </div>
                            <!-- end card -->
                        </div>
                        <!-- end col-->
                    </div>
                    <!-- end row-->

                    <div class="row">
                        <div class="col-xl-6">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title mb-4">Donut Update</h4>
                                    <div dir="ltr">
                                        <div id="update-donut" class="apex-charts" data-colors="#3e60d5,#6c757d,#47ad77,#fa5c7c"></div>
                                    </div>

                                    <div class="text-center mt-2">
                                        <button class="btn btn-sm btn-primary" id="randomize">RANDOMIZE</button>
                                        <button class="btn btn-sm btn-primary" id="add">ADD</button>
                                        <button class="btn btn-sm btn-primary" id="remove">REMOVE</button>
                                        <button class="btn btn-sm btn-primary" id="reset">RESET</button>
                                    </div>

                                </div>
                                <!-- end card body-->
                            </div>
                            <!-- end card -->
                        </div>
                        <!-- end col-->
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

    <!-- Apex Chart js -->
    <script src="assets/vendor/apexcharts/apexcharts.min.js"></script>

    <!-- Apex Chart Pie Demo js -->
    <script src="assets/js/pages/demo.apex-pie.js"></script>

    <!-- App js -->
    <script src="assets/js/app.min.js"></script>

</body>

</html>