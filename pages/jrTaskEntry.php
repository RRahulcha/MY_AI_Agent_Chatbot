<?php
session_start();
error_reporting(0);
ini_set('display_errors', 0);


if (!isset($_SESSION['userId']) && empty($_SESSION['userId'])) {
    // header('location:login');
    // die();
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}
// role based access
else if ($_SESSION['userRole'] !== 'senior_intern' && $_SESSION['userRole'] !== 'hr') {
    echo '<script>
        alert("You are not authorized to access this page.");
        window.location.href = "login.php";
        </script>';
    // header("location:not-authorized.php");
    die();
}
include('../include/db_config.php');
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Task Entry</title>
    <?php
    include('../partials/_links.php')
        ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap4.min.css">
    <style>
        .btn-secondary {
            color: white;
        }

        #cancelBtn {
            display: none;
        }
    </style>
    <style>
        .form-control {
            height: 2.3rem;
        }

        .select2-container .select2-selection--single {
            box-sizing: border-box;
            cursor: pointer;
            padding: 1%;
            display: block;
            height: 37px;
            user-select: none;
            -webkit-user-select: none;
        }

        .select2-container--default .select2-selection--multiple .select2-selection__choice {
            font-size: 1rem;
        }

        .task {
            display: none;
        }

        .required {
            color: red;
        }

        /* Table headers */
        #taskEntryTable thead th {
            background-color: #103d9eff;
            color: #ffffffff;
            font-weight: 600;
            text-align: center;
            /* padding: 0.65rem 0.75rem; */
            border: 1px solid #dee2e6;
            /* Keep borders */
        }

        /* Table body cells */
        #taskEntryTable tbody td {
            border: 1px solid #dee2e6;
            /* Keep borders */
        }

        /* Hover effect */
        #taskEntryTable tbody tr:hover {
            background-color: #dce4f8ff;
        }

        /* Optional: alternate row shading */
        #taskEntryTable tbody tr:nth-child(odd) {
            background-color: #f1f5ff;
        }

        /* Hide icon on desktop when nothing is hidden */
        #taskEntryTable.dtr-inline:not(.collapsed) td.dtr-control::before {
            display: none !important;
        }

        /* Remove default arrow */
        #taskEntryTable td.dtr-control::before {
            background-image: none !important;
            -webkit-mask-image: none !important;
            mask-image: none !important;
        }

        /* PLUS */
        #taskEntryTable.dtr-inline.collapsed tr:not(.parent) td.dtr-control::before {
            content: '+';
            width: 18px;
            height: 18px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #007bff;
            color: #fff;
            font-weight: 700;
        }

        /* MINUS */
        #taskEntryTable.dtr-inline.collapsed tr.parent td.dtr-control::before {
            content: '−';
            background: #dc3545;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
        }

        #taskEntryTable tbody tr.child td {
            text-align: left !important;
            white-space: normal !important;
            padding-left: 16px;
        }

        /* Make label–value layout like screenshot */
        #taskEntryTable tbody tr.child ul.dtr-details {
            width: 100%;
            padding: 0;
            margin: 0;
        }

        #taskEntryTable tbody tr.child ul.dtr-details li {
            display: grid;
            grid-template-columns: 160px 1fr;
            /* label | value */
            gap: 12px;
            padding: 8px 0;
            border-bottom: 1px solid #e5e5e5;
        }

        #taskEntryTable tbody tr.child span.dtr-title {
            font-weight: 600;
            color: #222;
            text-align: left;
        }

        #taskEntryTable tbody tr.child span.dtr-data {
            text-align: left;
            color: #555;
        }

        /* Force icon to left, regardless of content */
        #taskEntryTable td.dtr-control::before {
            position: absolute;
            left: 12px;
            transform: translateY(-50%);
        }

        /* Ensure consistent row height */
        #taskEntryTable tbody tr {
            min-height: 48px;
        }

        /* Control column cell */
        #taskEntryTable td.dtr-control {
            position: relative;
            min-height: 48px;
            padding-left: 44px !important;
            vertical-align: middle !important;
            text-align: left !important;
        }

        /* Keep icon vertically centered */
        #taskEntryTable td.dtr-control::before {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
        }
    </style>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script>
        $(document).ready(function () {
        });
    </script>
</head>

<body>
    <div class="container-scroller">

        <?php
        include('../partials/_navbar.php')
            ?>

        <div class="container-fluid page-body-wrapper">

            <?php
            include('../partials/_sidebar.php');
            include('../partials/_settings-panel.php');
            ?>

            <div class="main-panel">
                <div class="content-wrapper">
                    <div class="row">


                        <div class="col-lg-12 grid-margin stretch-card">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title">Tasks Allocated By Me</h4>
                                    <table class="table table-hover" id="taskEntryTable">
                                        <thead>
                                            <tr>
                                                <th class="all">Intern</th>
                                                <th class="all">Project Name</th>
                                                <th class="min-desktop">Task</th>
                                                <th class="min-desktop">Expected Date & Time</th>
                                                <th class="min-desktop">Man Hr.</th>
                                                <th class="none">Location</th>
                                                <th class="none">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>

                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="../vendors/js/vendor.bundle.base.js"></script>
    <script>

        $(document).ready(() => {
            let dataTable = $('#taskEntryTable').DataTable({
                'order': [[2, 'desc']],
                'serverSide': true,
                'serverMethod': 'post',
                'ajax': {
                    'url': '../ajax/jrTaskEntry.php?dataTable='
                },
                'responsive': true,
                'columns': [
                    { data: 'fullName' },  // Change from 'intern' to 'fullName'
                    { data: 'projectName' },
                    { data: 'task' },
                    { data: 'expectedDeadline' },
                    { data: 'manHours' },   // Change from 'manHr' to 'manHours'
                    { data: 'location' },
                    { data: 'status' }
                ],
                'columnDefs': [
                    { "orderable": false, "targets": [6] }
                ]
            });

            dataTable.draw();
        });


        $("#taskDiv").hide();

        let taskFun = () => {

            if ($("#task").val() == "task") {
                $("#taskDiv").show();
                $("#newTask").attr('required', 'required');
            } else {
                $("#newTask").removeAttr('required').val('');
                $("#taskDiv").hide();
            }
        }

        <?php

        if (isset($_GET['taskId'])) {
            echo "$('#task').val('task');taskFun()";
        }

        ?>

    </script>

    <script src="../vendors/typeahead.js/typeahead.bundle.min.js"></script>
    <script src="../vendors/select2/select2.min.js"></script>
    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>

    <script src="../js/template.js"></script>
    <script src="../js/settings.js"></script>
    <script src="../js/select2.js"></script>

    <!-- Date picker script -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <!-- Date picker script end -->

    <!-- This page script -->

    <!-- This page script end -->

    <!-- Data Table Script Link -->
    <script src="../vendors/datatables.net/jquery.dataTables.js"></script>
    <script src="../vendors/datatables.net-bs4/dataTables.bootstrap4.js"></script>
    <!-- Data Table Script Link End -->
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap4.min.js"></script>

    <script>
        flatpickr(".js-datepicker", {
            enableTime: true,
            dateFormat: "d-m-Y H:i",
            minDate: "<?php echo date('d-m-Y H:i') ?>"
            // minTime: ""
        });

        window.addEventListener('onbeforeunload', function (e) {
            status = document.getElementById("body").value;
            if (status == "running") {
                e.preventDefault();
                e.returnValue = '';
            }
        })



        $("#projectId").on("change", function () {
            var projectId = $("#projectId").val();

            $.ajax({
                type: 'POST',
                url: '../ajax/taskEntry',
                data: { projectId: projectId },
                success: function (result) {
                    console.log(result);
                    $("#task").html(result);
                }
            });
        });

        $(".remov option").each(function () {
            $(this).siblings('[value="' + this.value + '"]').remove();
        });
    </script>

    <?php
    require_once('../include/sweetAlert.php');
    if (isset($_SESSION['status'])) {
        $status = $_SESSION['status'];
        $msg = $_SESSION['msg'];
        if ($status != true) {
            toastMsg('error', $msg);
        } else {
            toastMsg('success', $msg);
        }
        unset($_SESSION['status']);
        unset($_SESSION['msg']);
    }
    ?>
</body>

</html>