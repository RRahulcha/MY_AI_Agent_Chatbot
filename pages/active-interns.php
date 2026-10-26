<?php
session_start();
include('../include/db_config.php');

if (!isset($_SESSION['userId'])) {
    // header('location:login');
    // die();
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}
// if ($_SESSION['userRole'] !== 'admin') {
//     // Display alert and then redirect
//     echo "<script>
//         alert('You cannot open this page. Please log in with the correct role.');
//         window.location.href = 'login.php';
//     </script>";
//     exit(); // Ensure the script stops execution after the redirect
// }

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Active Interns Report</title>
    <?php
    include('../partials/_links.php')
        ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">

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

        .swal2-icon {
            margin: 0 auto !important;
        }
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

                        $from   = $_REQUEST['from'] ?? '';
                        $to     = $_REQUEST['to'] ?? '';
                        $intern = $_REQUEST['intern'] ?? '';
                        $mode   = $_REQUEST['type'] ?? '';
                        $batch  = $_REQUEST['batch'] ?? '';

                        if (!empty($_REQUEST['from']) && !empty($_REQUEST['to'])) {
                            $condition = " AND date between '$from' AND '$to' ";
                        }
                        if (!empty($intern)) {
                            $intern_filter = " AND attendance.userId='$intern' ";
                        }

                        if ($mode == "" && isset($_SESSION['int_type'])) {
                            $mode = $_SESSION['int_type'];
                        }
                        
                        ?>
                        <div class="col-lg-12 grid-margin stretch-card">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title">Active Interns Report</h4>
                                <?php
$role = strtolower($_SESSION['userRole'] ?? '');

$backPage = ($role == 'hr')
    ? 'hrDashboard.php'
    : 'adminDashboard.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <button type="button" id="bulkDeactivateBtn" class="btn btn-danger btn-sm text-white font-weight-bold" style="display:none; border-radius:6px; padding:7px 14px;">
            <i class="fas fa-user-slash me-1"></i> Deactivate Selected (<span id="selectedCount">0</span>)
        </button>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a href="#" id="exportExcel" class="btn mx-1 btn-success btn-icon" title="Export to Excel">
            <i class="fas fa-file-excel"></i>
        </a>
        <a href="#" id="downloadPdf" title="Download PDF" class="btn mx-1 btn-danger btn-icon">
            <i class="fas fa-file-pdf fa-2x"></i>
        </a>
        <a href="<?= $backPage ?>" class="ms-2">
            <button class="btn btn-primary" name="back" id="back">
                Back
            </button>
        </a>
    </div>
</div>

                                    <form action="active-interns.php" method="POST" id="myForm">
                                        <div class="row align-items-end">
                                            <div class="col-md-4 mb-2">
                                                <label class="font-weight-bold">Mode of Internship</label>
                                                <select class="js-example-basic-single w-100 remov" style="width:100%"
                                                    id="type" name="type" data-placeholder="Select Mode of Internship">
                                                    <option value="">-- All Modes --</option>

                                                    <?php
                                                    $query1 = mysqli_query($con, "SELECT DISTINCT(type) FROM `signup` WHERE designation !='admin' AND type IS NOT NULL AND type != ''");
                                                    while ($fetch = mysqli_fetch_assoc($query1)) {
                                                        $sel = ($mode == $fetch['type']) ? 'selected' : '';
                                                        ?>
                                                        <option <?= $sel ?> value="<?php echo htmlspecialchars($fetch['type'], ENT_QUOTES); ?>">
                                                            <?php echo htmlspecialchars($fetch['type']); ?>
                                                        </option>
                                                        <?php
                                                    }
                                                    ?>
                                                </select>
                                            </div>
                                            <div class="col-md-4 mb-2">
                                                <label class="font-weight-bold">Batch</label>
                                                <select class="js-example-basic-single w-100 remov" style="width:100%"
                                                    id="batch" name="batch" data-placeholder="Select Batch">
                                                    <option value="">-- All Batches --</option>

                                                    <?php
                                                    $queryBatch = mysqli_query($con, "SELECT DISTINCT batch_number FROM `signup` WHERE designation !='admin' AND batch_number IS NOT NULL AND batch_number != '' ORDER BY batch_number ASC");
                                                    while ($bFetch = mysqli_fetch_assoc($queryBatch)) {
                                                        $bVal = $bFetch['batch_number'];
                                                        $bSel = ($batch == $bVal) ? 'selected' : '';
                                                        ?>
                                                        <option <?= $bSel ?> value="<?php echo htmlspecialchars($bVal, ENT_QUOTES); ?>">
                                                            <?php echo htmlspecialchars($bVal); ?>
                                                        </option>
                                                        <?php
                                                    }
                                                    ?>
                                                </select>
                                            </div>
                                            <div class="col-md-4 mb-2 d-flex align-items-center">
                                                <button type="submit" name="show" id="show" class="btn text-white me-2"
                                                    style="background-color:#0066CC; border-color:#0066CC;">
                                                    Show
                                                </button>
                                                <a class="btn btn-outline-primary" style="font-weight:500;"
                                                    href="active-interns.php">Reset</a>
                                            </div>
                                        </div>
                                    </form>

                                    <div class="table-responsive mt-4">

                                        <table class="table" id="employee_data" style="width:100%">
                                            <thead>
                                                <tr>
                                                    <th style="text-align: center; width: 40px;">
                                                        <input type="checkbox" id="selectAll" title="Select All">
                                                    </th>
                                                    <th style="text-align: center;">Sr.No.</th>
                                                    <th style="text-align: center;">Intern Name</th>
                                                    <th style="text-align: center;">Email</th>
                                                    <th style="text-align: center;">Mobile Number</th>
                                                    <th style="text-align: center;">Batch</th>
                                                    <th style="text-align: center;">Mode of Internship</th>
                                                    <th style="text-align: center;">Office</th>
                                                    <th style="text-align: center;">Created On</th>
                                                    <th style="text-align: center;">Action</th>
                                                    <th style="text-align: center;">Edit</th>
                                                </tr>

                                            </thead>

                                            <tbody>
                                                <?php
                                                $id = 1;

                                                $whereConditions = ["designation != 'admin'", "LOWER(designation) != 'staff'", "LOWER(userRole) != 'staff'", "status = 'Active'"];

                                                if (!empty($mode) && $mode != 'Reset') {
                                                    $safeMode = mysqli_real_escape_string($con, $mode);
                                                    $whereConditions[] = "type = '$safeMode'";
                                                }

                                                if (!empty($batch) && $batch != 'Reset') {
                                                    $safeBatch = mysqli_real_escape_string($con, $batch);
                                                    $whereConditions[] = "batch_number = '$safeBatch'";
                                                }

                                                $whereSql = implode(' AND ', $whereConditions);

                                                $query = mysqli_query($con, "SELECT * FROM signup WHERE $whereSql ORDER BY CASE WHEN status = 'offline' THEN 1 WHEN status = 'online' THEN 2 ELSE 3 END, srNo DESC") or die($con->error);

                                                while ($res = mysqli_fetch_assoc($query)) {
                                                    ?>
                                                    <tr>
                                                        <td style="text-align: center;">
                                                            <input type="checkbox" class="intern-checkbox" value="<?= htmlspecialchars($res['userId'], ENT_QUOTES) ?>" data-fullname="<?= htmlspecialchars($res['fullName'], ENT_QUOTES) ?>">
                                                        </td>
                                                        <td style="text-align: center;">
                                                            <?php echo $id++ ?>
                                                        </td>
                                                        <td>
                                                            <?php echo htmlspecialchars($res['fullName']) ?>
                                                        </td>
                                                        <td>
                                                            <?php echo htmlspecialchars($res['email']) ?>
                                                        </td>
                                                        <td style="text-align: right;">
                                                            <?php echo htmlspecialchars($res['mobileNo']) ?>
                                                        </td>
                                                        <td style="text-align: center;">
                                                            <span class="badge badge-light border"><?php echo htmlspecialchars($res['batch_number'] ?? '—') ?></span>
                                                        </td>
                                                        <td>
                                                            <?php echo htmlspecialchars($res['type']) ?>
                                                        </td>
                                                        <td>
                                                            <?php echo htmlspecialchars($res['office']) ?>
                                                        </td>

                                                        <td>
                                                            <?php echo date('d-m-Y', strtotime($res['createdDate'])) ?>
                                                        </td>

                                                        <td style="text-align: center;">

                                                            <button type="button"
                                                                class="btn btn-warning btn-sm deactivate-btn"
                                                                data-userid="<?= htmlspecialchars($res['userId'], ENT_QUOTES) ?>"
                                                                data-fullname="<?= htmlspecialchars($res['fullName'], ENT_QUOTES) ?>">Inactive</button>
                                                        </td>

                                                        <td style="text-align: center;">
                                                            <a href="editProfile_Admin.php?userId=<?= htmlspecialchars($res['userId'], ENT_QUOTES) ?>"
                                                                class="btn btn-primary btn-sm">
                                                                <i class="fa fa-edit"></i> Edit
                                                            </a>
                                                        </td>

                                                    </tr>
                                                    <?php

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

    <!-- End plugin js for this page -->
    <!-- inject:js -->
    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>
    <script src="../js/settings.js"></script>
    <script src="../js/todolist.js"></script>
    <script src="../vendors/select2/select2.min.js"></script>
    <script src="../js/select2.js"></script>
    <script>
        $(document).ready(function () {
            const table = $('#employee_data').DataTable({
                columnDefs: [
                    { orderable: false, targets: 0 }
                ],
                order: [[1, 'asc']]
            });

            // Export to Excel
            $('#exportExcel').on('click', function () {
                const mode = $('#type').val() || '';
                const batch = $('#batch').val() || '';
                window.location.href = `active-interns_excel.php?mode=${encodeURIComponent(mode)}&batch=${encodeURIComponent(batch)}&type=excel`;
            });

            // Download PDF
            $('#downloadPdf').on('click', function () {
                const mode = $('#type').val() || '';
                const batch = $('#batch').val() || '';
                window.location.href = `active-interns_excel.php?mode=${encodeURIComponent(mode)}&batch=${encodeURIComponent(batch)}&type=pdf`;
            });
        });


        // --- Checkbox & Bulk Deactivation Handler ---
        function updateBulkButton() {
            const checkedCount = $('.intern-checkbox:checked').length;
            $('#selectedCount').text(checkedCount);
            if (checkedCount > 0) {
                $('#bulkDeactivateBtn').fadeIn(200);
            } else {
                $('#bulkDeactivateBtn').fadeOut(200);
                $('#selectAll').prop('checked', false);
            }
        }

        // Prevent header sorting when clicking checkbox & toggle all
        $(document).on('click', '#selectAll', function (e) {
            e.stopPropagation();
        });

        $(document).on('change', '#selectAll', function (e) {
            e.stopPropagation();
            const isChecked = $(this).is(':checked');
            $('.intern-checkbox').prop('checked', isChecked);
            updateBulkButton();
        });

        // Individual checkbox change
        $(document).on('click', '.intern-checkbox', function (e) {
            e.stopPropagation();
        });

        $(document).on('change', '.intern-checkbox', function (e) {
            e.stopPropagation();
            updateBulkButton();
            const totalCount = $('.intern-checkbox').length;
            const checkedCount = $('.intern-checkbox:checked').length;
            $('#selectAll').prop('checked', totalCount > 0 && totalCount === checkedCount);
        });

        // Re-sync checkboxes when DataTables changes page/draws
        $(document).ready(function() {
            const dt = $('#employee_data').DataTable();
            dt.on('draw', function () {
                $('#selectAll').prop('checked', false);
                updateBulkButton();
            });
        });

        // Bulk Deactivate Action
        $(document).on('click', '#bulkDeactivateBtn', function () {
            const selectedIds = [];
            const selectedNames = [];

            $('.intern-checkbox:checked').each(function () {
                selectedIds.push($(this).val());
                const name = $(this).data('fullname');
                if (name) selectedNames.push(name);
            });

            if (selectedIds.length === 0) {
                Swal.fire('Warning', 'No interns selected for deactivation.', 'warning');
                return;
            }

            const previewList = selectedNames.slice(0, 5).join(', ') + (selectedNames.length > 5 ? '... and ' + (selectedNames.length - 5) + ' more' : '');

            Swal.fire({
                title: `Deactivate ${selectedIds.length} intern(s)?`,
                html: `Are you sure you want to deactivate the selected ${selectedIds.length} intern(s)?<br><br><small class="text-muted">Selected: ${previewList}</small>`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, deactivate all',
                confirmButtonColor: '#d33'
            }).then((result) => {
                if (!result.isConfirmed) return;

                $.ajax({
                    url: 'intern_action.php',
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'bulk_deactivate',
                        userIds: selectedIds,
                        authority: '<?= $_SESSION["userRole"] ?? "admin" ?>'
                    },
                    success: function (res) {
                        if (res.ok) {
                            selectedIds.forEach(function(id) {
                                const row = $('.intern-checkbox[value="' + id + '"]').closest('tr');
                                row.fadeOut(400, function () { row.remove(); });
                            });
                            $('#selectAll').prop('checked', false);
                            updateBulkButton();
                            Swal.fire('Done', res.msg || 'Selected interns deactivated successfully.', 'success');
                        } else {
                            Swal.fire('Error', res.msg || 'Bulk deactivation failed.', 'error');
                        }
                    },
                    error: function (xhr) {
                        Swal.fire('Server Error', xhr.responseText || 'Something went wrong.', 'error');
                    }
                });
            });
        });

        // Handle Inactive button (signup → delete_data)
        $(document).on('click', '.deactivate-btn', function () {
            const fullName = $(this).data('fullname');
            const userId = $(this).data('userid');
            if (!fullName) {
                Swal.fire('Error', 'Missing fullName on button', 'error');
                return;
            }

            Swal.fire({
                title: 'Deactivate this intern?',
                text: fullName,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, deactivate',
            }).then((result) => {
                if (!result.isConfirmed) return;

                $.ajax({
                    url: 'intern_action.php',
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'deactivate',
                        userId: userId,
                        authority: '<?= $_SESSION["userRole"] ?? "admin" ?>'
                    },
                    success: function (res) {
                        if (res.ok) {
                            const row = $('[data-userid="' + userId + '"]').closest('tr');
                            row.fadeOut(400, function () { row.remove(); });
                            updateBulkButton();
                            Swal.fire('Done', 'Intern marked as Inactive', 'success');
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



    <!-- endinject -->
    <!-- Custom js for this page-->
    <script src="../js/dashboard.js?v=1.0.1"></script>
    <script src="../js/Chart.roundedBarCharts.js"></script>
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
</body>

</html>