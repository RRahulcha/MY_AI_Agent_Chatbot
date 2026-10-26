<?php
session_start();
if(!isset($_SESSION['userId']) || empty($_SESSION['userId'])){
    // header('location:login.php');
    // die();
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

if ($_SESSION['userRole'] !== 'admin') {
    echo "<script>
        alert('You do not have permission to access this page.');
        window.location.href = 'view_holidays.php';
    </script>";
    exit();
}

include('../include/db_config.php');

// Validate holiday ID
if (!isset($_GET['id']) || empty($_GET['id'])) {
    // header("Location: view_holidays.php");
    // exit();
    echo "<script>window.location.href = 'view_holidays.php';</script>";
    exit();
}

$holidayId = intval($_GET['id']);

// Fetch existing record
$query = mysqli_query($con, "SELECT * FROM holidays WHERE id = $holidayId");
if (mysqli_num_rows($query) == 0) {
    echo "<script>alert('Record not found!'); window.location.href='view_holidays.php';</script>";
    exit();
}
$holiday = mysqli_fetch_assoc($query);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = mysqli_real_escape_string($con, $_POST['title']);
    $from_date = mysqli_real_escape_string($con, $_POST['from_date']);
    $to_date = mysqli_real_escape_string($con, $_POST['to_date']);
    $description = mysqli_real_escape_string($con, $_POST['description']);

    if (empty($title) || empty($from_date) || empty($to_date)) {
        $error = "Please fill all required fields.";
    } else {
        $updateQuery = "UPDATE holidays 
                        SET title = '$title',
                            from_date = '$from_date',
                            to_date = '$to_date',
                            description = '$description'
                             
                        WHERE id = $holidayId";

        if (mysqli_query($con, $updateQuery)) {
            $_SESSION['status'] = true;
             $_SESSION['msg'] = "Holiday updated successfully!";
            // header("Location: view_holidays.php");
            // exit();
            echo "<script>window.location.href = 'view_holidays.php';</script>";
    exit();
        } else {
            $error = "Error updating record: " . mysqli_error($con);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Edit Holiday</title>
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
        .content-wrapper {
            background-color: #f8f9fa;
        }
    </style>
</head>

<body>
    <div class="container-scroller">

        <!-- Navbar -->
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
                                        <i class="fa-solid fa-calendar-edit"></i> Edit Holiday
                                    </h4>

                                    <?php if (!empty($error)): ?>
                                        <div class="alert alert-danger"><?php echo $error; ?></div>
                                    <?php endif; ?>

                                    <form class="forms-sample" method="POST">
                                        <!-- Holiday Title -->
                                        <div class="form-group">
                                            <label for="title">Title <span class="required">*</span></label>
                                            <input type="text" class="form-control" id="title" name="title" 
                                                placeholder="Enter holiday title" 
                                                value="<?php echo htmlspecialchars($holiday['title']); ?>" required>
                                        </div>

                                        <!-- From Date -->
                                        <div class="form-group">
                                            <label for="from_date">From Date <span class="required">*</span></label>
                                            <input type="date" class="form-control" id="from_date" name="from_date" 
                                                min="<?php echo date('Y-m-d'); ?>" 
                                                value="<?php echo htmlspecialchars($holiday['from_date']); ?>" required>
                                        </div>

                                        <!-- To Date -->
                                        <div class="form-group">
                                            <label for="to_date">To Date <span class="required">*</span></label>
                                            <input type="date" class="form-control" id="to_date" name="to_date" 
                                                min="<?php echo date('Y-m-d'); ?>" 
                                                value="<?php echo htmlspecialchars($holiday['to_date']); ?>" required>
                                        </div>

                                        <!-- Description -->
                                        <div class="form-group">
                                            <label for="description">Description</label>
                                            <textarea class="form-control" id="description" name="description" rows="4" 
                                                placeholder="Enter description"><?php echo htmlspecialchars($holiday['description']); ?></textarea>
                                        </div>

                                        <!-- Buttons -->
                                        <button type="submit" class="btn btn-primary mr-2" name="updateHoliday">Update</button>
                                        <a href="view_holidays.php" class="btn btn-secondary">Cancel</a>
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

  
    <!-- // SweetAlert toast notifications
    // require_once('../include/sweetAlert.php');
    // if(isset($_SESSION['status'])){
    //     $status = $_SESSION['status'];
    //     $msg = $_SESSION['msg'];
    //     if($status != true){
    //         toastMsg('error', $msg);
    //     } else {
    //         toastMsg('success', $msg);
    //     }
    //     unset($_SESSION['status']);
    //     unset($_SESSION['msg']);
    // } -->
 
</body>
</html>