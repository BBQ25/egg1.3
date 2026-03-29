<?php include '../layouts/session.php'; ?>
<?php include '../layouts/main.php'; ?>

<head>
    <title>Calendar | Attex - Bootstrap 5 Admin & Dashboard Template</title>
    <?php include '../layouts/title-meta.php'; ?>

    <!-- Fullcalendar css -->
    <link href="assets/vendor/fullcalendar/main.min.css" rel="stylesheet" type="text/css" />

    <?php include '../layouts/head-css.php'; ?>
    <style>
        :root {
            --cal-primary: #3e60d5;
            --cal-primary-dark: #3657c9;
            --cal-danger: #f15776;
            --cal-soft-border: rgba(15, 23, 42, 0.1);
            --cal-soft-bg: #f8f9fc;
        }

        .calendar-shell.card {
            border: 1px solid var(--cal-soft-border);
            border-radius: 14px;
        }

        .calendar-shell .card-body {
            padding: 1.15rem;
        }

        #btn-new-event {
            border: 0;
            border-radius: 10px;
            font-weight: 600;
            background: var(--cal-danger);
            box-shadow: 0 10px 24px rgba(241, 87, 118, 0.28);
            transition: transform 140ms ease, box-shadow 140ms ease, filter 140ms ease;
        }

        #btn-new-event:hover {
            transform: translateY(-1px);
            box-shadow: 0 13px 28px rgba(241, 87, 118, 0.33);
            filter: brightness(0.98);
        }

        #external-events {
            margin-top: 1rem !important;
        }

        #external-events .external-event {
            border-radius: 8px;
            border: 1px solid transparent;
            font-size: 0.95rem;
            padding: 12px 14px;
            margin-bottom: 10px;
            transition: transform 140ms ease, box-shadow 140ms ease;
        }

        #external-events .external-event:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 18px rgba(15, 23, 42, 0.11);
        }

        .calendar-help {
            border-top: 1px dashed rgba(15, 23, 42, 0.16);
            padding-top: 1rem;
        }

        #calendar .fc {
            --fc-border-color: #dce1ec;
            --fc-page-bg-color: #ffffff;
            --fc-button-bg-color: var(--cal-primary);
            --fc-button-border-color: var(--cal-primary);
            --fc-button-hover-bg-color: var(--cal-primary-dark);
            --fc-button-hover-border-color: var(--cal-primary-dark);
            --fc-button-active-bg-color: var(--cal-primary-dark);
            --fc-button-active-border-color: var(--cal-primary-dark);
            --fc-today-bg-color: #fff8d7;
            --fc-event-border-color: transparent;
            --fc-event-text-color: #fff;
        }

        #calendar .fc .fc-toolbar {
            margin-bottom: 1rem !important;
        }

        #calendar .fc .fc-toolbar-title {
            text-transform: uppercase;
            letter-spacing: 0.02em;
            font-weight: 700;
            color: #556277;
        }

        #calendar .fc .fc-button {
            border-radius: 6px;
            font-weight: 600;
            box-shadow: none;
        }

        #calendar .fc .fc-col-header-cell-cushion {
            color: #5d6b80;
            font-weight: 700;
            text-transform: none;
            padding: 0.6rem 0;
        }

        #calendar .fc .fc-daygrid-day-number {
            border-radius: 999px;
            color: #677488;
            font-size: 0.86rem;
            margin: 4px;
            width: 24px;
            height: 24px;
            text-align: center;
            line-height: 24px;
        }

        #calendar .fc .fc-daygrid-day.fc-day-today .fc-daygrid-day-number {
            background: rgba(62, 96, 213, 0.14);
            color: var(--cal-primary);
            font-weight: 700;
        }

        #calendar .fc .fc-daygrid-day-frame {
            min-height: 90px;
        }

        #calendar .fc .fc-event {
            border-radius: 4px;
            font-weight: 600;
            padding: 2px 6px;
        }

        @media (max-width: 991.98px) {
            #calendar .fc .fc-toolbar {
                gap: 8px;
            }

            #calendar .fc .fc-toolbar.fc-header-toolbar {
                display: flex;
                flex-direction: column;
                align-items: stretch;
            }

            #calendar .fc .fc-toolbar-chunk {
                display: flex;
                justify-content: center;
                flex-wrap: wrap;
                gap: 6px;
            }
        }
    </style>
</head>

<body>
    <!-- Begin page -->
    <div class="wrapper">

        <?php include '../layouts/menu.php'; ?>

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
                                        <li class="breadcrumb-item"><a href="javascript: void(0);">Apps</a></li>
                                        <li class="breadcrumb-item active">Calendar</li>
                                    </ol>
                                </div>
                                <h4 class="page-title">Calendar</h4>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-12">

                            <div class="card calendar-shell">
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-lg-3">
                                            <div class="d-grid">
                                                <button class="btn btn-lg fs-16 btn-danger" id="btn-new-event">
                                                    <i class="ri-add-circle-fill"></i> Create New Event
                                                </button>
                                            </div>
                                            <div id="external-events" class="mt-3">
                                                <p class="text-muted">Drag and drop your event or click in the calendar</p>
                                                <div class="external-event bg-success-subtle text-success" data-class="bg-success"><i class="ri-focus-fill me-2 vertical-middle"></i>New Theme Release</div>
                                                <div class="external-event bg-info-subtle text-info" data-class="bg-info"><i class="ri-focus-fill me-2 vertical-middle"></i>My Event</div>
                                                <div class="external-event bg-warning-subtle text-warning" data-class="bg-warning"><i class="ri-focus-fill me-2 vertical-middle"></i>Meet manager</div>
                                                <div class="external-event bg-danger-subtle text-danger" data-class="bg-danger"><i class="ri-focus-fill me-2 vertical-middle"></i>Create New theme</div>
                                            </div>

                                            <div class="mt-5 d-none d-xl-block calendar-help">
                                                <h5 class="text-center">How It Works ?</h5>

                                                <ul class="ps-3">
                                                    <li class="text-muted mb-3">
                                                        It has survived not only five centuries, but also the leap into electronic typesetting, remaining essentially unchanged.
                                                    </li>
                                                    <li class="text-muted mb-3">
                                                        Richard McClintock, a Latin professor at Hampden-Sydney College in Virginia, looked up one of the more obscure Latin words, consectetur, from a Lorem Ipsum passage.
                                                    </li>
                                                    <li class="text-muted mb-3">
                                                        It has survived not only five centuries, but also the leap into electronic typesetting, remaining essentially unchanged.
                                                    </li>
                                                </ul>
                                            </div>

                                        </div> <!-- end col-->

                                        <div class="col-lg-9">
                                            <div class="mt-4 mt-lg-0">
                                                <div id="calendar"></div>
                                            </div>
                                        </div> <!-- end col -->

                                    </div> <!-- end row -->
                                </div> <!-- end card body-->
                            </div> <!-- end card -->

                            <!-- Add New Event MODAL -->
                            <div class="modal fade" id="event-modal" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <form class="needs-validation" name="event-form" id="form-event" novalidate>
                                            <div class="modal-header py-3 px-4 border-bottom-0">
                                                <h5 class="modal-title" id="modal-title">Event</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                            </div>
                                            <div class="modal-body px-4 pb-4 pt-0">
                                                <div class="row">
                                                    <div class="col-12">
                                                        <div class="mb-3">
                                                            <label class="control-label form-label">Event Name</label>
                                                            <input class="form-control" placeholder="Insert Event Name" type="text" name="title" id="event-title" required />
                                                            <div class="invalid-feedback">Please provide a valid event name</div>
                                                        </div>
                                                    </div>
                                                    <div class="col-12">
                                                        <div class="mb-3">
                                                            <label class="control-label form-label">Category</label>
                                                            <select class="form-select" name="category" id="event-category" required>
                                                                <option value="bg-danger" selected>Danger</option>
                                                                <option value="bg-success">Success</option>
                                                                <option value="bg-primary">Primary</option>
                                                                <option value="bg-info">Info</option>
                                                                <option value="bg-dark">Dark</option>
                                                                <option value="bg-warning">Warning</option>
                                                            </select>
                                                            <div class="invalid-feedback">Please select a valid event category</div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="row">
                                                    <div class="col-6">
                                                        <button type="button" class="btn btn-danger" id="btn-delete-event">Delete</button>
                                                    </div>
                                                    <div class="col-6 text-end">
                                                        <button type="button" class="btn btn-light me-1" data-bs-dismiss="modal">Close</button>
                                                        <button type="submit" class="btn btn-success" id="btn-save-event">Save</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </form>
                                    </div> <!-- end modal-content-->
                                </div> <!-- end modal dialog-->
                            </div>
                            <!-- end modal-->
                        </div>
                        <!-- end col-12 -->
                    </div> <!-- end row -->

                </div> <!-- container -->

            </div> <!-- content -->

            <?php include '../layouts/footer.php'; ?>

        </div>

        <!-- ============================================================== -->
        <!-- End Page content -->
        <!-- ============================================================== -->

    </div>
    <!-- END wrapper -->

    <?php include '../layouts/right-sidebar.php'; ?>

    <?php include '../layouts/footer-scripts.php'; ?>

    <!-- Fullcalendar js -->
    <script src="assets/vendor/fullcalendar/main.min.js"></script>

    <!-- Calendar App Demo js -->
    <script src="assets/js/pages/demo.calendar.js"></script>

    <!-- App js -->
    <script src="assets/js/app.min.js"></script>

</body>

</html>
