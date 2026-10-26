<?php
session_start();
if(!isset($_SESSION['userId']) && empty($_SESSION['userId'])){
    // header('location:login');
    // die();
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

include('../include/db_config.php');
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Add Holiday</title>
    <?php include('../partials/_links.php'); ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">

    <style>
        .btn-secondary {
            color: white;
        }
        .required { color: red; }
        .form-control { border-radius: 6px; }
        .card-header { font-size: 1.1rem; font-weight: 600; }
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
                                    <h4 class="card-title" id="pageTitle">
                                        <i class="fa-solid fa-calendar"></i> Add Holiday
                                    </h4>
                                    <form class="forms-sample" action="holiday_DB.php" method="post">
                                        <!-- Holiday Title -->
                                        <div class="form-group">
                                            <label for="title">Title <span class="required">*</span></label>
                                            <input type="text" class="form-control" id="title" name="title" placeholder="Enter holiday title" required>
                                        </div>

                                        <!-- From Date -->
                                        <div class="form-group">
                                            <label for="from_date">From Date <span class="required">*</span></label>
                                            <input type="date" class="form-control" id="from_date" name="from_date" min="<?php echo date('Y-m-d'); ?>" required>
                                        </div>

                                        <!-- To Date -->
                                        <div class="form-group">
                                            <label for="to_date">To Date <span class="required">*</span></label>
                                            <input type="date" class="form-control" id="to_date" name="to_date" min="<?php echo date('Y-m-d'); ?>" required>
                                        </div>

                                        <!-- Description -->
                                        <div class="form-group">
                                            <label for="description">Description</label>
                                            <textarea class="form-control" id="description" name="description" rows="4" placeholder="Enter description"></textarea>
                                        </div>

                                        <!-- Buttons -->
                                        <button type="submit" class="btn btn-primary mr-2" name="addHoliday">Submit</button>

                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Vendor scripts -->
    <script src="../vendors/typeahead.js/typeahead.bundle.min.js"></script>
    <script src="../vendors/select2/select2.min.js"></script>
    <script src="../js/select2.js"></script>
    <script src="../vendors/datatables.net/jquery.dataTables.js"></script>
    <script src="../vendors/datatables.net-bs4/dataTables.bootstrap4.js"></script>
    <script src="../vendors/js/vendor.bundle.base.js"></script>
    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>
    <script src="../js/settings.js"></script>

    <!-- Flatpickr Date Picker -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

    <script>
        // Date range logic
        const fromDateInput = document.getElementById('from_date');
        const toDateInput = document.getElementById('to_date');

        fromDateInput.addEventListener('change', function() {
            const fromDate = new Date(this.value);
            toDateInput.disabled = false;
            const minDateString = fromDate.toISOString().slice(0,10);
            toDateInput.min = minDateString;

            if (new Date(toDateInput.value) < fromDate) {
                toDateInput.value = minDateString;
            }
        });
    </script>

    <?php
    require_once('../include/sweetAlert.php');
    if(isset($_SESSION['status'])){
        $status = $_SESSION['status'];
        $msg = $_SESSION['msg'];
        if($status != true){
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
