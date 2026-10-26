<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}
include('../include/db_config.php');

$userId = $_SESSION['userId'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $projectIds = [];
    if (isset($_POST['projectId'])) {
        if (is_array($_POST['projectId'])) {
            $projectIds = $_POST['projectId'];
        } else {
            $projectIds = [$_POST['projectId']];
        }
    }

    $projectIds = array_values(array_unique(array_filter($projectIds, function($v){
        return ctype_digit((string)$v);
    })));

    $reason = mysqli_real_escape_string($con, $_POST['reason']);
    $fromDate = $_POST['from_date'];
    $toDate = $_POST['to_date'];
    $approvedBy = '';
    $status = 'Approved';           // 🔥 Auto-approved
    $dateTime = date('Y-m-d H:i:s');
    $rejectionReason = '';

    if (empty($projectIds)) {
        $_SESSION['status'] = false;
        $_SESSION['msg'] = "Please select at least one project.";
        echo "<script>window.location.href = 'apply_tour.php';</script>";
        exit();
    }

    // Check for overlapping leave requests
    $leaveCheck = mysqli_query($con, "
        SELECT leave_id FROM leaves
        WHERE userId = '$userId'
          AND status NOT IN ('Rejected', 'Cancelled')
          AND leave_name NOT LIKE '%Auto Leave%'
          AND from_date <= '$toDate' AND to_date >= '$fromDate'
    ");
    if ($leaveCheck && mysqli_num_rows($leaveCheck) > 0) {
        $_SESSION['status'] = false;
        $_SESSION['msg'] = "You have an active or pending Leave during this date range. Tour cannot be applied.";
        echo "<script>window.location.href = 'apply_tour.php';</script>";
        exit();
    }

    $checkStmt = $con->prepare("SELECT id FROM tour WHERE userId = ? AND projectId = ? AND fromDate = ? AND toDate = ?");
    $insertStmt = $con->prepare(
        "INSERT INTO tour (userId, projectId, reason, fromDate, toDate, approvedBy, status, dateTime, rejectionReason, cancelReason) 
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    $anyInserted = false;
    $duplicates = [];
    $errors = [];

    foreach ($projectIds as $pid) {
        $pidInt = (int)$pid;

        $checkStmt->bind_param("siis", $userId, $pidInt, $fromDate, $toDate);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();

        if ($checkResult && $checkResult->num_rows > 0) {
            $duplicates[] = $pidInt;
            continue;
        }

        $cancelReason = '';
        $insertStmt->bind_param(
            "sissssssss", 
            $userId, $pidInt, $reason, $fromDate, $toDate, $approvedBy, $status, $dateTime, $rejectionReason, $cancelReason
        );

        if ($insertStmt->execute()) {
            $anyInserted = true;
        } else {
            $errors[] = "Project ID {$pidInt}: " . $insertStmt->error;
        }
    }

    $checkStmt->close();
    $insertStmt->close();

    if ($anyInserted) {
        require_once('../include/notification_helper.php');
        $userQuery = mysqli_query($con, "SELECT fullName FROM signup WHERE userId = '$userId' LIMIT 1");
        $userData = mysqli_fetch_assoc($userQuery);
        $employeeName = $userData['fullName'] ?? 'Employee';

        sendNotificationToAdmins(
            $con,
            "New Tour Applied ✈️",
            "{$employeeName} has applied for a tour.",
            "tour_applied",
            ""
        );
    }

    if ($anyInserted && empty($errors)) {
        if (!empty($duplicates)) {
            $_SESSION['status'] = true;
            $_SESSION['msg'] = "Some projects applied successfully. Duplicate projects were skipped.";
        } else {
            $_SESSION['status'] = true;
            $_SESSION['msg'] = "Tour application(s) approved successfully."; // 🔥 Updated message
        }
    } elseif ($anyInserted && !empty($errors)) {
        $_SESSION['status'] = false;
        $_SESSION['msg'] = "Partial success. Errors: " . implode(' | ', $errors);
    } elseif (!$anyInserted && !empty($duplicates)) {
        $_SESSION['status'] = false;
        $_SESSION['msg'] = "You have already applied for the selected project(s) for the same dates. No new application created.";
    } else {
        $_SESSION['status'] = false;
        $_SESSION['msg'] = "Unable to apply for selected projects. " . (!empty($errors) ? implode(' | ', $errors) : '');
    }

    echo "<script>window.location.href = 'apply_tour.php';</script>";
    exit();
}
?>
<style>
    .required {
        color: red;
        font-weight: bold;
    }
</style>
<!DOCTYPE html>
<html lang="en">

<head>
    <title>Apply Tour</title>
    <?php include('../partials/_links.php'); ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
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
                                    <h4 class="card-title">Apply for Tour</h4>
                                    <form class="forms-sample" method="POST" action="apply_tour.php">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="projectId">Projects <span
                                                            class="required">*</span></label>
                                                    <select name="projectId[]"
                                                        class="js-example-basic-multiple form-control"
                                                        multiple="multiple" required>
                                                        <?php
                                                        $projQuery = mysqli_query($con, "SELECT projectId, projectName, client FROM projectMaster WHERE status = 1");
                                                        while ($proj = mysqli_fetch_assoc($projQuery)) {
                                                            echo "<option value='{$proj['projectId']}'>{$proj['projectName']} - {$proj['client']}</option>";
                                                        }
                                                        ?>
                                                    </select>
                                                    <small class="form-text text-muted">Hold CTRL (or CMD on Mac) to
                                                        select multiple.</small>
                                                </div>

                                                <div class="form-group">
                                                    <label for="from_date">From Date <span
                                                            class="required">*</span></label>
                                                    <input type="date" class="form-control" name="from_date"
                                                        id="from_date" min="<?php echo date('Y-m-d'); ?>" required>
                                                </div>

                                                <div class="form-group">
                                                    <label for="to_date">To Date <span class="required">*</span></label>
                                                    <input type="date" class="form-control" name="to_date" id="to_date"
                                                        min="<?php echo date('Y-m-d'); ?>" required>
                                                </div>

                                                <div class="form-group">
                                                    <label for="reason">Reason <span class="required">*</span></label>
                                                    <textarea name="reason" class="form-control" rows="4"
                                                        required></textarea>
                                                </div>

                                                <button type="submit" class="btn btn-primary mr-2">Apply</button>
                                            </div>
                                            <div class="col-md-6">
                                                <center>
                                                    <img src="../images/edit_profile.png" style="width:90%;">
                                                </center>
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

    <script src="../vendors/js/vendor.bundle.base.js"></script>
    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>
    <script src="../js/settings.js"></script>
    <script src="../js/todolist.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <script>
        const fromDateInput = document.getElementById('from_date');
        const toDateInput = document.getElementById('to_date');
        fromDateInput.addEventListener('change', function () {
            const fromDate = new Date(this.value);
            toDateInput.disabled = false;
            const minDateString = fromDate.toISOString().slice(0, 10);
            toDateInput.min = minDateString;
            if (new Date(toDateInput.value) < fromDate) {
                toDateInput.value = minDateString;
            }
        });

        $(document).ready(function () {
            $('.js-example-basic-multiple').select2({ width: '100%' });
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