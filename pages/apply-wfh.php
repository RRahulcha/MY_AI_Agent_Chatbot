<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
   header("Location: login.php");
exit();

} 
include('../include/db_config.php');

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $userId = $_SESSION['userId'];
    $reason = mysqli_real_escape_string($con, $_POST['reason']);
    $fromDate = $_POST['from_date'];
    $toDate = $_POST['to_date'];
    $approvedBy = $_POST['approved_by'];
    
    
    if (empty($approvedBy) || $approvedBy == '0') {
        $_SESSION['status'] = false;
        $_SESSION['msg'] = "Please select an approver.";
        header("Location: apply-wfh.php");
        exit();
    }
    
    // Validate date range
    if (strtotime($toDate) < strtotime($fromDate)) {
        $_SESSION['status'] = false;
        $_SESSION['msg'] = "To Date cannot be earlier than From Date.";
        header("Location: apply-wfh.php");
        exit();
    }

    // Check for overlapping requests
    // Overlap condition: (StartA <= EndB) and (EndA >= StartB)
    $checkQuery = "
        SELECT wfh_id FROM wfhRequests 
        WHERE userid = '$userId' 
        AND (
            (fromDate <= '$toDate' AND toDate >= '$fromDate')
        )
        AND status != 'Rejected'
    ";
    
    $checkResult = mysqli_query($con, $checkQuery);
    
    if ($checkResult && mysqli_num_rows($checkResult) > 0) {
        $_SESSION['status'] = false;
        $_SESSION['msg'] = "You have already applied for WFH during this date range.";
        header("Location: apply-wfh.php");
        exit();
    }

    // Check for overlapping leave requests
    $leaveCheckQuery = "
        SELECT leave_id FROM leaves 
        WHERE userId = '$userId' 
          AND status NOT IN ('Rejected', 'Cancelled')
          AND leave_name NOT LIKE '%Auto Leave%'
          AND from_date <= '$toDate' AND to_date >= '$fromDate'
    ";
    $leaveCheckResult = mysqli_query($con, $leaveCheckQuery);
    if ($leaveCheckResult && mysqli_num_rows($leaveCheckResult) > 0) {
        $_SESSION['status'] = false;
        $_SESSION['msg'] = "You have an active or pending Leave during this date range. WFH cannot be applied.";
        header("Location: apply-wfh.php");
        exit();
    }
        
    // Insert Logic - Single Record
    // Columns: wfh_id (auto), userid, fromDate, toDate, reason, documentPath, status, createdDate, updatedDate, approvedBy, rejectionReason
    $documentPath = '-'; // No file upload in this form yet
    
    $insertQuery = "
    INSERT INTO wfhRequests (
        userid,
        fromDate,
        toDate,
        wfhDate,
        reason,
        documentPath,
        status,
        createdDate,
        updatedDate,
        approvedBy,
        rejectionReason
    ) VALUES (
        '$userId',
        '$fromDate',
        '$toDate',
        '$fromDate',
        '$reason',
        '$documentPath',
        'Pending',
        NOW(),
        NOW(),
        '$approvedBy',
        NULL
    )
";
    
    $insertResult = mysqli_query($con, $insertQuery);

if ($insertResult) {
        $_SESSION['status'] = true;
        $_SESSION['msg'] = "WFH application submitted successfully from $fromDate to $toDate.";
    } else {
        error_log(mysqli_error($con));
        $_SESSION['status'] = false;
        $_SESSION['msg'] = "Error applying for WFH: " . mysqli_error($con);
    }
    
    header("Location: apply-wfh.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <title>Apply Work From Home</title>
    <?php include('../partials/_links.php'); ?>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        .required {
            color: red;
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

            // Get user designation for conditional query
            $userId = $_SESSION['userId'];
            $userQuery = mysqli_query($con, "SELECT designation FROM signup WHERE userId = '$userId'");
            $myGet = mysqli_fetch_assoc($userQuery);
            ?>

            <div class="main-panel">
                <div class="content-wrapper">
                    <div class="row">
                        <div class="col-12 grid-margin stretch-card">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title">Apply for Work From Home</h4>
                                    <form class="forms-sample" method="POST" action="apply-wfh.php">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="from_date">From Date <span
                                                            class="required">*</span></label>
                                                    <input type="date" class="form-control" name="from_date"
                                                        id="from_date" min="<?php echo date('Y-m-d'); ?>" required>
                                                </div>
                                            </div>

                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="to_date">To Date <span class="required">*</span></label>
                                                    <input type="date" class="form-control" name="to_date" id="to_date"
                                                        min="<?php echo date('Y-m-d'); ?>" required>
                                                </div>
                                            </div>

                                            <div class="col-md-12">
                                                <div class="form-group">
                                                    <label for="reason">Reason for WFH <span
                                                            class="required">*</span></label>
                                                    <textarea name="reason" class="form-control" rows="4" required
                                                        placeholder="Enter reason for work from home"></textarea>
                                                </div>
                                            </div>

                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="approved_by">Approval From <span
                                                            class="required">*</span></label>
                                                    <select id="approved_by" class="form-control" name="approved_by"
                                                        required>
                                                        <option value="" selected disabled>Select Approver</option>
                                                        <?php
                                                        if ($myGet['designation'] == 'seniorIntern' || $myGet['designation'] == 'resourceAllocator') {
                                                            $query = mysqli_query($con, "SELECT * FROM signup WHERE (designation = 'Admin' OR designation = 'hr') AND status = 'Active'");
                                                        } else {
                                                            $query = mysqli_query($con, "SELECT * FROM signup WHERE userId != '$userId' AND (designation = 'seniorIntern' OR designation = 'Admin' OR designation = 'hr' OR designation = 'resourceAllocator') AND status = 'Active'");
                                                        }
                                                        while ($fetch = mysqli_fetch_assoc($query)) {
                                                            $userIdValue = $fetch['userId'];
                                                            echo "<option value='{$userIdValue}'>{$fetch['fullName']}</option>";
                                                        }
                                                        ?>
                                                    </select>
                                                </div>
                                            </div>

                                            <div class="col-md-12">
                                                <button type="submit" id="submitBtn" class="btn btn-primary mr-2">
                                                    Submit
                                                </button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="../vendors/js/vendor.bundle.base.js"></script>
    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>
    <script src="../js/settings.js"></script>
    <script src="../js/todolist.js"></script>

    <!-- <script>
        $(document).ready(function () {
            // Date validation
            const fromDateInput = document.getElementById('from_date');
            const toDateInput = document.getElementById('to_date');

            fromDateInput.addEventListener('change', function () {
                const fromDate = new Date(this.value);

                // Enable to_date field
                toDateInput.disabled = false;

                // Set minimum to_date to from_date
                const minDateString = fromDate.toISOString().split('T')[0];
                toDateInput.min = minDateString;

                // If to_date is earlier than from_date, reset it
                if (toDateInput.value && new Date(toDateInput.value) < fromDate) {
                    toDateInput.value = minDateString;
                }
            });

            // Also validate when to_date changes
            toDateInput.addEventListener('change', function () {
                const fromDate = new Date(fromDateInput.value);
                const toDate = new Date(this.value);

                if (toDate < fromDate) {
                    alert("To Date cannot be earlier than From Date.");
                    this.value = fromDateInput.value;
                }
            });
        });
    </script> -->

    <script>

$(document).ready(function () {

    $('.forms-sample').on('submit', function (e) {

        const fromDate = $('#from_date').val();
        const toDate = $('#to_date').val();
        const reason = $('textarea[name="reason"]').val().trim();
        const approvedBy = $('#approved_by').val();

        // Validation
        if (!fromDate || !toDate || !reason || !approvedBy) {

            e.preventDefault();

            Swal.fire({
                icon: 'error',
                title: 'Validation Error',
                text: 'Please fill all required fields.'
            });

            return false;
        }

        // Date validation
        if (new Date(toDate) < new Date(fromDate)) {

            e.preventDefault();

            Swal.fire({
                icon: 'error',
                title: 'Date Error',
                text: 'To Date cannot be earlier than From Date.'
            });

            return false;
        }

        // Button loading (wrapped in setTimeout to prevent browser from cancelling form submission)
        setTimeout(function() {
            $('#submitBtn').prop('disabled', true);
            $('#submitBtn').html('Submitting...');
        }, 10);

    });

});

</script>

<?php
if (isset($_SESSION['status']) && isset($_SESSION['msg'])) {

    $status = $_SESSION['status'];
    $msg = $_SESSION['msg'];

?>
<script>

window.onload = function () {

    Swal.fire({
        icon: '<?php echo ($status ? "success" : "error"); ?>',
        title: '<?php echo ($status ? "Success" : "Error"); ?>',
        text: '<?php echo $msg; ?>',
        confirmButtonColor: '#3085d6'
    });

};

</script>
<?php

unset($_SESSION['status']);
unset($_SESSION['msg']);

}
?>
</body>



</html>