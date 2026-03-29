<?php include 'layouts/session.php'; ?>
<?php include 'layouts/main.php'; ?>

<head>
    <title>Form Editor | Attex - Bootstrap 5 Admin & Dashboard Template</title>
    <?php include 'layouts/title-meta.php'; ?>
    <!-- Quill css -->
    <link href="assets/vendor/quill/quill.core.css" rel="stylesheet" type="text/css" />
    <link href="assets/vendor/quill/quill.snow.css" rel="stylesheet" type="text/css" />
    <link href="assets/vendor/quill/quill.bubble.css" rel="stylesheet" type="text/css" />
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

                    <!-- start page title -->
                    <div class="row">
                        <div class="col-12">
                            <div class="page-title-box">
                                <div class="page-title-right">
                                    <ol class="breadcrumb m-0">
                                        <li class="breadcrumb-item"><a href="javascript: void(0);">Attex</a></li>
                                        <li class="breadcrumb-item"><a href="javascript: void(0);">Form</a></li>
                                        <li class="breadcrumb-item active">Form Editor</li>
                                    </ol>
                                </div>
                                <h4 class="page-title">Form Editor</h4>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <ul class="list-group list-group-flush">
                                    <li class="list-group-item">
                                        <div class="mb-2">
                                            <h4 class="header-title mt-2">Quill Editor</h4>
                                            <p class="text-muted fs-14">Snow is a clean, flat toolbar theme.</p>

                                            <div id="snow-editor" style="height: 300px;">
                                                <h3><span class="ql-size-large">Hello World!</span></h3>
                                                <p><br></p>
                                                <h3>This is an simple editable area.</h3>
                                                <p><br></p>
                                                <ul>
                                                    <li>
                                                        Select a text to reveal the toolbar.
                                                    </li>
                                                    <li>
                                                        Edit rich document on-the-fly, so elastic!
                                                    </li>
                                                </ul>
                                                <p><br></p>
                                                <p>
                                                    End of simple area
                                                </p>
                                            </div><!-- end Snow-editor-->
                                        </div>
                                    </li>

                                    <li class="list-group-item">
                                        <div class="mb-2">
                                            <h5 class="mb-1">Bubble Editor</h5>
                                            <p class="text-muted fs-14">Bubble is a simple tooltip based theme.</p>

                                            <div id="bubble-editor" style="height: 300px;">
                                                <h3><span class="ql-size-large">Hello World!</span></h3>
                                                <p><br></p>
                                                <h3>This is an simple editable area.</h3>
                                                <p><br></p>
                                                <ul>
                                                    <li>
                                                        Select a text to reveal the toolbar.
                                                    </li>
                                                    <li>
                                                        Edit rich document on-the-fly, so elastic!
                                                    </li>
                                                </ul>
                                                <p><br></p>
                                                <p>
                                                    End of simple area
                                                </p>
                                            </div> <!-- end Snow-editor-->

                                        </div>
                                    </li>
                                </ul> <!-- end list-->
                            </div> <!-- end card-->
                        </div> <!-- end col-->
                    </div>
                    <!-- end row -->

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

    <!-- Quill Editor js -->
    <script src="assets/vendor/quill/quill.min.js"></script>

    <!-- Quill Demo js -->
    <script src="assets/js/pages/demo.quilljs.js"></script>

    <!-- App js -->
    <script src="assets/js/app.min.js"></script>

</body>

</html>