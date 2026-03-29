<?php include 'layouts/session.php'; ?>
<?php include 'layouts/main.php'; ?>

<head>
    <title>Apex Mixed Chart | Attex - Bootstrap 5 Admin & Dashboard Template</title>
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
                                        <li class="breadcrumb-item active">Mixed Chart</li>
                                    </ol>
                                </div>
                                <h4 class="page-title">Mixed Chart</h4>
                            </div>
                        </div>
                    </div>


                    <div class="row">
                        <div class="col-xl-6">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="header-title">Line & Column Chart</h4>
                                    <div dir="ltr">
                                        <div id="line-column-mixed" class="apex-charts" data-colors="#3e60d5,#47ad77"></div>
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
                                    <h4 class="header-title">Multiple Y-Axis Chart</h4>
                                    <div dir="ltr">
                                        <div id="multiple-yaxis-mixed" class="apex-charts" data-colors="#3e60d5,#39afd1,#fa5c7c"></div>
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
                                    <h4 class="header-title">Line & Area Chart</h4>
                                    <div dir="ltr">
                                        <div id="line-area-mixed" class="apex-charts" data-colors="#47ad77,#fa5c7c"></div>
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
                                    <h4 class="header-title">Line, Column & Area Chart</h4>
                                    <div dir="ltr">
                                        <div id="all-mixed" class="apex-charts" data-colors="#3e60d5,#39afd1,#fa5c7c"></div>
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

    <!-- Apex Charts js -->
    <script src="assets/vendor/apexcharts/apexcharts.min.js"></script>
    <!-- Apex Chart Mixed Demo js -->
    <script src="assets/js/pages/demo.apex-mixed.js"></script>

    <!-- App js -->
    <script src="assets/js/app.min.js"></script>

</body>

</html>