<?php
session_start();
if (!isset($_SESSION['userId']) && empty($_SESSION['userId'])) {
    // header('location:login');
    // die();
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}
if ($_SESSION['userRole'] !== 'admin' && $_SESSION['designation'] !== 'hr' && $_SESSION['designation'] !== 'md') {
    // Display alert and then redirect
    echo "<script>
        alert('You cannot open this page. Please log in with the correct role.');
        window.location.href = 'login.php';
    </script>";
    exit(); // Ensure the script stops execution after the redirect
}
include('../include/db_config.php');


?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Inactive Interns Report</title>
    <?php
    include('../partials/_links.php')
        ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/4.5.2/css/bootstrap.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap4.min.css">
    <style>
        .star {
            font-size: 18px;
            color: red;
        }

        .btn.btn-icon {
            padding: 5px;
        }

        .btn i {
            font-size: 30px;
            /* Adjust icon size */
        }

        .btn:hover i {
            font-size: 40px;
        }


        #employee_data {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            background: #fff;
            border-radius: 8px;
            overflow: hidden;
            font-family: 'Segoe UI', sans-serif;
            font-size: 14px;
        }


        /* #reportTable {
    width: 100%;
    border-collapse: collapse; 
    background: #fff;
    font-family: 'Segoe UI', sans-serif;
    font-size: 14px;
    border: 1px solid #ddd; outer border
} */

        /* Table header style */
        #employee_data thead {
            background-color: #103d9eff;
            color: #ffffffff;
        }

        #employee_data thead th {
            padding: 12px 15px;
            font-weight: 600;
            text-align: left;
            border: 1px solid #ddd;
            /* header cell border */
            white-space: nowrap;
            padding-right: 30px !important;
        }

        #employee_data tbody tr:nth-child(odd) {
            background-color: #f1f5ff;
        }

        #employee_data tbody tr:hover {
            background-color: #dce4f8ff;
        }

        /* Table body cells */
        #employee_data tbody td {
            padding: 10px 15px;
            border: 1px solid #ddd;
            /* cell border */
            vertical-align: middle;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }



        /* Center the SweetAlert2 icon */
        .swal2-icon {
            margin: 0 auto !important;
        }

        /* .swal2-question {
    border-color: #3085d6 !important; 
    color: #3085d6 !important;        
} */
    </style>
</head>

<body>
    <div class="container-scroller">

        <?php
        include('../partials/_navbar.php')
            ?>

        <div class="container-fluid page-body-wrapper">

            <?php
            include('../partials/_sidebar.php');
            ?>

            <div class="main-panel">
                <div class="content-wrapper">
                    <div class="row">
                        <?php

                        $from = $_REQUEST['from'] ?? '';
                        $to = $_REQUEST['to'] ?? '';
                        $intern = $_REQUEST['intern'] ?? '';
                        $mode = $_REQUEST['type'] ?? '';
                        $intType = $_REQUEST['int_type'] ?? '';
                        $condition = '';
                        $intern_filter = '';

                        if (!empty($from) && !empty($to)) {
                            $condition = " AND date BETWEEN '$from' AND '$to' ";
                        }

                        if (!empty($intern)) {
                            $intern_filter = " AND attendance.userId = '$intern' ";
                        }




                        $mode = $_REQUEST['type'] ?? 'All';

                        if (empty($mode) && isset($_SESSION['int_type'])) {
                            $mode = $_SESSION['int_type'];
                        }

                        //  if (!empty($mode)) {
                        //     $mode = "type = '$mode'";
                        // }
                        
                        ?>
                        <div class="col-lg-12 grid-margin stretch-card">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title">Inactive Interns Report</h4>
                                    <div class="d-flex justify-content-end">
                                        <a href="adminDashboard.php"> <button style="margin-bottom:7px"
                                                class="btn btn-primary" name="back" id="back">Back</button>
                                        </a>
                                    </div>
                                    <div class="d-flex justify-content-end mb-3">
                                        <a href="#" id="exportExcel" class="btn mx-2 btn-success btn-icon"
                                            title="Export to Excel">
                                            <i class="fas fa-file-excel"></i>
                                        </a>
                                        <a href="#" id="downloadPdf" class="btn mx-2 btn-danger btn-icon"
                                            title="Download as PDF">
                                            <i class="fas fa-file-pdf"></i>
                                        </a>
                                    </div>

                                    <!-- <form action="deactivated-interns.php" method="POST" id="myForm">
                                        <div class="row">
                                            <div class="col-md-4">

                                                <label>Mode of Internship</label>
                                                <select class="js-example-basic-single w-100 remov" style="width: 100%;"
                                                    id="type" name="type" data-placeholder="Select Mode of Internship">

                                                    /*<option value="All" <?php echo ($mode == 'All') ? 'selected' : ''; ?>>
                                                        All</option>*/



                                 <option value="" selected="" disabled="">-- Select Mode --</option>

                                                    <?php
                                                    // Query to fetch distinct 'type' from the database
                                                    $query1 = mysqli_query($con, "SELECT DISTINCT(type) FROM `delete_data` WHERE designation != 'admin'");

                                                    // Populate the dropdown with options from the query result
                                                    while ($fetch = mysqli_fetch_assoc($query1)) {
                                                        $selected = ($mode == $fetch['type']) ? 'selected' : ''; // Check if this option should be selected
                                                        ?>
                                                        <option value="<?php echo htmlspecialchars($fetch['type']); ?>"
                                                            <?php echo $selected; ?>>
                                                            <?php echo htmlspecialchars($fetch['type']); ?>
                                                        </option>
                                                        <?php
                                                    }
                                                    ?>
                                                </select>

                                            </div>

                                            <div class="col-md-6" style="padding-top:30px;">
                                                <button type="submit" name="show" id="show"
                                                    class="btn btn-primary">Show</button>
                                                <a class="btn btn-danger" href="deactivated-interns.php">
                                                    Reset
                                                </a>
                                                
                                            </div>


                                        </div>
                                    </form>-->



                                    <form action="deactivated-interns.php" method="POST" id="myForm">
                                        <div class="row">
                                            <div class="col-md-4">
                                                <label>Mode of Internship</label>
                                                <select class="js-example-basic-single w-100 remov" style="width: 100%;"
                                                    id="type" name="type" data-placeholder="Select Mode of Internship">

                                                    <!-- Default option -->
                                                    <option value="All" <?php echo (isset($mode) && $mode == 'All') ? 'selected' : ''; ?>>All</option>

                                                    <?php
                                                    // Query to fetch distinct 'type' from the database
                                                    $query1 = mysqli_query($con, "SELECT DISTINCT(type) FROM `delete_data` WHERE designation != 'admin'");

                                                    // Populate dropdown
                                                    while ($fetch = mysqli_fetch_assoc($query1)) {
                                                        $selected = (isset($mode) && $mode == $fetch['type']) ? 'selected' : '';
                                                        ?>
                                                        <option value="<?php echo htmlspecialchars($fetch['type']); ?>"
                                                            <?php echo $selected; ?>>
                                                            <?php echo htmlspecialchars($fetch['type']); ?>
                                                        </option>
                                                        <?php
                                                    }
                                                    ?>
                                                </select>
                                            </div>

                                            <div class="col-md-6" style="padding-top:30px;">
                                                <!-- <button type="submit" name="show" id="show" class="btn btn-primary">Show</button> -->
                                                <button type="submit" name="show" id="show" class="btn text-white"
                                                    style="background-color:#0066CC; border-color:#0066CC;">
                                                    Show
                                                </button>
                                                <a class="btn btn-outline-primary" style="font-weight:500;"
                                                    href="deactivated-interns.php">Reset</a>
                                            </div>
                                        </div>
                                    </form>

                                    <?php
                                    // Get selected mode
                                    $mode = isset($_POST['type']) ? $_POST['type'] : 'All';

                                    // If filter applied (Show clicked + not All)
                                    if (!empty($_POST['show']) && $mode != 'All') {
                                        $query = mysqli_query($con, "
        SELECT * FROM delete_data 
        WHERE type = '$mode'
        AND designation != 'admin'
        ORDER BY delete_date DESC

    ") or die($con->error);
                                    } else {
                                        // Default: show all data
                                        $query = mysqli_query($con, "
    SELECT * FROM delete_data 
    WHERE designation != 'admin'
    ORDER BY delete_date DESC

") or die($con->error);

                                    }
                                    ?>

                                    <div class="table-responsive mt-4">

                                        <table class="table" id="employee_data" style="width:100%">
                                            <thead>
                                                <tr>
                                                    <th style="text-align: center;">Sr.No.</th>
                                                    <th style="text-align: center;">Intern Name</th>
                                                    <th style="text-align: center;">Email</th>
                                                    <th style="text-align: center;">Mobile Number</th>
                                                    <th style="text-align: center;">Mode of Internship</th>
                                                    <th style="text-align: center;">Office</th>
                                                    <th style="text-align: center;">Actions</th>
                                                </tr>
                                            </thead>

                                            <tbody>
                                                <?php
                                                $id = 1;

                                                // Check if a valid mode is selected
                                                if (!empty($mode) && $mode != 'Reset') {
                                                    if ($mode == "All") {
                                                        $query = mysqli_query($con, "SELECT * FROM delete_data WHERE type != '' AND designation != 'admin'");
                                                    } else {
                                                        $query = mysqli_query($con, "SELECT * FROM delete_data WHERE type = '$mode' AND designation != 'admin'");
                                                    }

                                                    // Display results if the query returns rows
                                                    if (mysqli_num_rows($query) > 0) {
                                                        while ($res = mysqli_fetch_assoc($query)) {
                                                            ?>
                                                            <tr>
                                                                <td style="text-align: center;"><?php echo $id++; ?></td>
                                                                <td><?php echo htmlspecialchars($res['fullName']); ?></td>
                                                                <td><?php echo htmlspecialchars($res['email']); ?></td>
                                                                <td style="text-align: right;">
                                                                    <?php echo htmlspecialchars($res['mobileNo']); ?>
                                                                </td>
                                                                <td><?php echo htmlspecialchars($res['type']); ?></td>
                                                                <td><?php echo htmlspecialchars($res['office']); ?></td>
                                                                <td style="text-align: center;">
                                                                    <button type="button"
                                                                        class="btn btn-success btn-sm activate-btn"
                                                                        data-userid="<?= $res['userId']; ?>"
                                                                        data-fullname="<?= htmlspecialchars($res['fullName'], ENT_QUOTES) ?>">
                                                                        Active
                                                                    </button>


                                                                </td>
                                                            </tr>
                                                            <?php

                                                        }
                                                    } else {
                                                        // No data available for the selected mode
                                                        echo '<tr><td colspan="5" class="text-center">No data available for the selected mode.</td></tr>';
                                                    }
                                                } else {
                                                    // No mode selected, show a placeholder message
                                                    echo '<tr><td colspan="5" class="text-center">Please select a mode to display data.</td></tr>';
                                                }
                                                ?>
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
    </div>

    <!-- plugins:js -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="../vendors/js/vendor.bundle.base.js"></script>

    <!-- endinject -->
    <!-- Plugin js for this page -->
    <script src="../vendors/chart.js/Chart.min.js"></script>
    <script src="../vendors/datatables.net/jquery.dataTables.js"></script>
    <script src="../vendors/datatables.net-bs4/dataTables.bootstrap4.js"></script>
    <script src="../js/dataTables.select.min.js"></script>


    <script>


        $(document).ready(function () {

            $('#employee_data').DataTable({
                order: [[0, 'desc']]
            });

            // Export to Excel
            $('#exportExcel').on('click', function () {
                const mode = $('#type').val();
                if (!mode) {
                    alert('Please select a mode of internship before exporting.');
                    return;
                }
                window.location.href = `deactived_excel.php?mode=${mode}&type=excel`;
            });

            // Download PDF
            $('#downloadPdf').on('click', function () {
                const mode = $('#type').val();
                if (!mode) {
                    alert('Please select a mode of internship before downloading.');
                    return;
                }
                window.location.href = `deactived_excel.php?mode=${mode}&type=pdf`;
            });
        });

        function goBack() {
            if (document.referrer) {
                window.history.back(); // Go to the previous page
            } else {
                window.location.href = "adminDashboard.php"; // Fallback if no history
            }
        }

        // Handle Active button (delete_data → signup)
        $(document).on('click', '.activate-btn', function () {

            const userId = $(this).data('userid');
            const fullName = $(this).data('fullname');

            if (!userId) {
                Swal.fire('Error', 'Missing userId — cannot continue', 'error');
                return;
            }

            Swal.fire({
                title: 'Activate this intern?',
                text: fullName,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes, activate',
            }).then((result) => {
                if (!result.isConfirmed) return;

                $.ajax({
                    url: 'intern_action.php',
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'activate',
                        userId: userId
                    },
                    success: function (res) {
                        if (res.ok) {
                            Swal.fire('Done', 'Moved to Active list', 'success')
                                .then(() => location.reload());
                        } else {
                            Swal.fire('Error', res.msg || 'Failed', 'error');
                        }
                    },
                    error: function (xhr) {
                        Swal.fire('Server Error', xhr.responseText || 'Try again', 'error');
                    }
                });

            });
        });


    </script>
    <!-- End plugin js for this page -->
    <!-- inject:js -->
    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>
    <script src="../js/settings.js"></script>
    <script src="../js/todolist.js"></script>
    <!-- endinject -->
    <!-- Custom js for this page-->
    <script src="../js/dashboard.js?v=1.0.1"></script>
    <script src="../js/Chart.roundedBarCharts.js"></script>
    <script src="../vendors/select2/select2.min.js"></script>
    <script src="../js/select2.js"></script>

    <!-- End custom js for this page-->

    <?php
    require_once('../include/sweetAlert.php');
    if (isset($_SESSION['msg'])) {
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
    <script>
        $(document).ready(function () {
            $('#employee_data').DataTable();
        });
    </script>
</body>

</html>