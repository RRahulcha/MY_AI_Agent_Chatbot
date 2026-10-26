<?php
session_start();
if (!isset($_SESSION['userId']) && empty($_SESSION['userId'])) {
    // header('location:login');
    // die();
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

// Role-based access control
if ($_SESSION['userRole'] !== 'senior_intern' && ($_SESSION['designation'] ?? '') !== 'hr' && ($_SESSION['userRole'] ?? '') !== 'hr') {
    echo '<script>
        alert("You are not authorized to access this page.");
        window.location.href = "login.php";
    </script>';
    die();
}

include('../include/db_config.php');
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Change Status</title>
    <?php include('../partials/_links.php'); ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-beta.1/css/select2.min.css" rel="stylesheet" />
    <style>
        .select2-container .select2-selection--single {
            box-sizing: border-box;
            cursor: pointer;
            padding-top: 3%;
            display: block;
            height: 46px;  
            user-select: none;
        }

        .select2-container--default .select2-selection--multiple .select2-selection__choice {
            font-size: 1rem;
            padding: 0.25rem;
        }

        .select2-selection__arrow{
            margin: 10px;
        }

        .select2-selection__clear{
            margin-right: 39px !important;
        }
    </style>
    <style>
        .btn-secondary {
            color: white;
        }

        #cancelBtn {
            display: none;
        }

        .swal2-modal .swal2-icon,
        .swal2-modal .swal2-success-ring {
            margin-bottom: 0px;
        }

        #projectId {
            height: 46px;
        }
        
        optgroup[label="Active"] {
            font-weight: bold;
        }
        optgroup[label="Inactive"] {
            font-weight: bold;
        }
    </style>
</head>

<body>
    <div class="container-scroller">
        <?php include('../partials/_navbar.php'); ?>

        <div class="container-fluid page-body-wrapper">
            <?php
            include('../partials/_sidebar.php');
            include('../partials/_settings-panel.php');
            ?>

            <div class="main-panel">
                <div class="content-wrapper">
                    <div class="row">
                        <div class="col-12 grid-margin stretch-card">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title" id="pageTitle">Change Status</h4>

                                    <form class="forms-sample" action="jrStatusChange-db.php" method="post">
                                        <div class="row">
                                            <!-- Junior Intern Selection -->
                                            <div class="col-md-4">
                                                <div class="form-group">
                                                    <label for="projectName">Jr. Intern Name</label>
                                                  
<select class="js-example-basic-single w-100"
        id="projectId"
        name="internId"
        onchange="fetchInternStatus()"
        required>

    <option value="" selected disabled>-- Select Name --</option>

    <optgroup label="Active">
        <?php
        $active = mysqli_query($con,"
            SELECT userId, fullName
            FROM signup
            WHERE designation='juniorIntern'
              AND status='Active'
            ORDER BY fullName
        ");
        while ($row = mysqli_fetch_assoc($active)) {
        ?>
            <option value="<?= $row['userId']; ?>" data-type="Active">
                <?= $row['fullName']; ?>
            </option>
        <?php } ?>
    </optgroup>

    <optgroup label="Inactive">
        <?php
        $inactive = mysqli_query($con,"
            SELECT userId, fullName
            FROM signup
            WHERE designation='juniorIntern'
              AND status='Inactive'
            ORDER BY fullName
        ");
        while ($row = mysqli_fetch_assoc($inactive)) {
        ?>
            <option value="<?= $row['userId']; ?>" data-type="Inactive">
                <?= $row['fullName']; ?>
            </option>
        <?php } ?>
    </optgroup>


   

                                                    </select>
                                                </div>
                                            </div>

                                            <!-- Current Status Display -->
                                            <div class="col-md-4">
                                                <div class="form-group">
                                                    <label for="type">Current Status</label>
                                                    <input type="text" class="form-control" id="statusShow" readonly>
                                                </div>
                                            </div>

                                            <!-- Hidden Input for Current Status -->
                                            <input type="hidden" id="selectedInternStatus" name="selectedInternStatus">

                                            <!-- Set New Status -->
                                            <div class="col-md-4">
                                                <div class="form-group">
                                                    <label for="status-change">Set New Status</label>
                                                    <div class="form-group row">
                                                        <div class="col-4">
                                                            <div class="form-check">
                                                                <label class="form-check-label" for="inactiveStatus">
                                                                    <input type="radio" class="form-check-input" name="status" id="inactiveStatus" value="Inactive" required>
                                                                    Inactive<i class="input-helper"></i>
                                                                </label>
                                                            </div>
                                                        </div>
                                                        <div class="col-5">
                                                            <div class="form-check">
                                                                <label class="form-check-label" for="activeStatus">
                                                                    <input type="radio" class="form-check-input" name="status" id="activeStatus" value="Active">
                                                                    Active<i class="input-helper"></i>
                                                                </label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>


                                        </div>

                                        <button type="submit" class="btn btn-primary mr-2" name="switch">Switch</button>
                                        <a href="projectMaster" id="cancelBtn" class="btn btn-light">Cancel</a>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="../vendors/js/vendor.bundle.base.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-beta.1/js/select2.min.js"></script>
    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>
    <script src="../js/settings.js"></script>
    <script src="../js/todolist.js"></script>
    <script src="../vendors/datatables.net/jquery.dataTables.js"></script>
    <script src="../vendors/datatables.net-bs4/dataTables.bootstrap4.js"></script>

    <script>
        function fetchInternStatus() {
            const selectElement = document.getElementById('projectId');
            const selectedOption = selectElement.options[selectElement.selectedIndex];

            if (selectedOption) {
                const internStatus = selectedOption.getAttribute('data-type');
                document.getElementById('statusShow').value = internStatus;
                document.getElementById('selectedInternStatus').value = internStatus;
            }
        }
    </script>
    <script>
        $(document).ready(function() {
            // Make both dropdowns searchable using Select2
            $('.js-example-basic-single').select2({
                placeholder: "Select Intern",
                allowClear: true
            });

            $('.js-example-basic-multiple').select2({
                placeholder: "Select Employees",
                allowClear: true
            });
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