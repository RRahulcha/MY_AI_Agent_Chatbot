<?php
session_start();
if (!isset($_SESSION['userId']) && empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}
include('../include/db_config.php');

date_default_timezone_set('Asia/Kolkata');

// Initialize filters
$officeFilter = isset($_GET['office']) ? $_GET['office'] : '';
$empFilter = isset($_GET['employee']) ? $_GET['employee'] : '';
$dateFilter = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// --- 1. Fetch Employees based on filters ---
$empQuery = "SELECT userId, fullName, office FROM signup WHERE  designation NOT IN ('admin', 'developer', 'md')  AND status = 'Active'";

if (!empty($officeFilter)) {
    $empQuery .= " AND office = '" . mysqli_real_escape_string($con, $officeFilter) . "'";
}
if (!empty($empFilter)) {
    $empQuery .= " AND userId = '" . mysqli_real_escape_string($con, $empFilter) . "'";
}

$empQuery .= " ORDER BY fullName ASC";
$empResult = mysqli_query($con, $empQuery);

$employees = [];
$empIds = [];
while ($row = mysqli_fetch_assoc($empResult)) {
    $employees[$row['userId']] = [
        'name' => $row['fullName'],
        'office' => $row['office'],
        'projects' => [],
        'totalSeconds' => 0
    ];
    $empIds[] = $row['userId'];
}

// --- 2. Fetch Tasks for these employees ---
if (!empty($empIds)) {
    $empIdsStr = implode("','", $empIds);
    $taskQuery = "SELECT userId, projectName, workType, totalDuration, startTime, endTime, status FROM TaskEntryNew WHERE userId IN ('$empIdsStr')";

    if (!empty($dateFilter)) {
        // Filter by date (comparing date part of startTime)
        $date = mysqli_real_escape_string($con, $dateFilter);
        $taskQuery .= " AND DATE(startTime) = '$date'";
    }

    $taskResult = mysqli_query($con, $taskQuery);

    while ($task = mysqli_fetch_assoc($taskResult)) {
        $uid = $task['userId'];
        $pName = !empty($task['projectName']) ? $task['projectName'] : $task['workType'];

        // Calculate duration
        $duration = (int) $task['totalDuration'];

        // If task is running, add elapsed time
        if ($task['status'] == 'Running' && !empty($task['startTime'])) {
            $elapsed = time() - strtotime($task['startTime']);
            if ($elapsed > 0) {
                $duration += $elapsed;
            }

            $pName = $pName . " (Running)";
        }

        // Ensure duration is not negative
        if ($duration < 0) {
            $duration = 0;
        }

        if (!isset($employees[$uid]['projects'][$pName])) {
            $employees[$uid]['projects'][$pName] = 0;
        }
        $employees[$uid]['projects'][$pName] += $duration;
        $employees[$uid]['totalSeconds'] += $duration;
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>SETTribe AMS - Daily Task Report</title>
    <?php include('../partials/_links.php') ?>
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.dataTables.min.css">
    <style>
        .table th,
        .table td {
            vertical-align: middle;
        }

        .total-row {
            font-weight: bold;
            background-color: #f8f9fa;
        }
    </style>
</head>

<body>
    <div class="container-scroller">
        <?php include('../partials/_navbar.php') ?>

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
                                    <h4 class="card-title">Daily Task Report</h4>

                                    <!-- Filter Form -->
                                    <form method="GET" class="mb-4">
                                        <div class="row">
                                            <div class="col-md-3">
                                                <label>Select Location</label>
                                                <select class="form-control" name="office" id="officeSelect">
                                                    <option selected disabled value=""> -- Select Location -- </option>
                                                    <option value="kinwat" <?php if ($officeFilter == 'kinwat')
                                                        echo 'selected'; ?>>SETTribe Kinwat</option>
                                                    <option value="pune" <?php if ($officeFilter == 'pune')
                                                        echo 'selected'; ?>>SETTribe Pune</option>
                                                </select>
                                            </div>

                                            <div class="col-md-3">
                                                <label>Select Employee</label>
                                                <select class="form-control" name="employee" id="employeeSelect">
                                                    <option value="">-- All Employees --</option>
                                                    <?php
                                                    // Re-fetch all employees for the dropdown to ensure full list is available for selection
                                                    // regardless of current filter, or just use the filtered list? 
                                                    // Better to show all active employees or filtered by office if office is selected.
                                                    

                                                    $dropdownQuery = "SELECT userId, fullName FROM signup WHERE designation != 'admin' AND status='Active'";
                                                    if (!empty($officeFilter)) {
                                                        $dropdownQuery .= " AND office = '" . mysqli_real_escape_string($con, $officeFilter) . "'";
                                                    }
                                                    $dropdownQuery .= " ORDER BY fullName ASC";
                                                    $dropdownRes = mysqli_query($con, $dropdownQuery);
                                                    while ($dRow = mysqli_fetch_assoc($dropdownRes)) {
                                                        $selected = ($empFilter == $dRow['userId']) ? 'selected' : '';
                                                        echo "<option value='{$dRow['userId']}' $selected>{$dRow['fullName']}</option>";
                                                    }

                                                    ?>
                                                </select>
                                            </div>

                                            <div class="col-md-3">
                                                <label>Select Date</label>
                                                <input type="date" class="form-control" name="date"
                                                    value="<?php echo htmlspecialchars($dateFilter); ?>"
                                                    max="<?= date('Y-m-d') ?>">
                                            </div>

                                            <div class="col-md-3 d-flex align-items-end">
                                                <button type="submit" class="btn btn-primary mr-2">Show</button>
                                                <a href="dailyTaskReport.php" class="btn btn-secondary">Reset</a>
                                            </div>
                                        </div>
                                    </form>

                                    <!-- Report Table -->
                                    <div class="table-responsive">
                                        <table class="table table-bordered" id="dailyTaskTable">
                                            <thead>
                                                <tr>
                                                    <th style="width: 30%;">Employee Name</th>
                                                    <th style="width: 50%;">Project Name</th>
                                                    <th style="width: 20%;">Man Hours (hh:mm)</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                if (empty($employees)) {
                                                    echo "<tr><td colspan='3' class='text-center'>No records found</td></tr>";
                                                } else {
                                                    foreach ($employees as $userId => $data) {
                                                        // Skip employees with no tasks if that's preferred, 
                                                        // OR show them with 0 hours. The requirement implies showing report.
                                                        // Let's show only if they have tasks OR if specific employee filter is applied.
                                                        // If "All Employees" is selected, showing everyone might be too much if they didn't work.
                                                        // But "Daily Task Report" usually implies showing who worked.
                                                        // Let's show everyone fetched by the initial query (which respects filters).
                                                
                                                        // If an employee has no projects, show one row with empty project/time
                                                        if (empty($data['projects'])) {
                                                            echo "<tr>";
                                                            echo "<td><strong>{$data['name']}</strong></td>";
                                                            echo "<td>-</td>";
                                                            echo "<td>00:00</td>";
                                                            echo "</tr>";
                                                            continue;
                                                        }

                                                        $first = true;
                                                        foreach ($data['projects'] as $projectName => $seconds) {
                                                            echo "<tr>";
                                                            if ($first) {
                                                                echo "<td><strong>{$data['name']}</strong></td>";
                                                                $first = false;
                                                            } else {
                                                                echo "<td></td>"; // Empty cell for name
                                                            }
                                                            echo "<td>{$projectName}</td>";
                                                            echo "<td>" . gmdate('H:i', $seconds) . "</td>";
                                                            echo "</tr>";
                                                        }
                                                        // Total Row
                                                        echo "<tr class='total-row'>";
                                                        echo "<td></td>"; // Empty name
                                                        echo "<td class='text-right'>Total</td>";
                                                        echo "<td>" . gmdate('H:i', $data['totalSeconds']) . "</td>";
                                                        echo "</tr>";
                                                    }
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

    <!-- Scripts -->
    <script src="../vendors/js/vendor.bundle.base.js"></script>
    <script src="../vendors/select2/select2.min.js"></script>
    <script src="../js/template.js"></script>

    <!-- DataTables -->
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap4.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>

    <script>
        $(document).ready(function () {

            $(document).ready(function () {
                // 1. Target the location dropdown by its ID
                $('#officeSelect').on('change', function () {
                    // Get the selected value from the location dropdown
                    var selectedOffice = $(this).val();

                    // 2. Make the AJAX request to the PHP script
                    $.ajax({
                        url: '../ajax/fetchEmp.php', // IMPORTANT: Replace with the correct path to the file you created
                        type: 'POST',
                        data: {
                            office: selectedOffice
                        },
                        beforeSend: function () {
                            // Optional: Show a loading message while waiting for the response
                            $('#employeeSelect').html('<option value="">Loading...</option>').prop('disabled', true);
                        },
                        success: function (response) {
                            // 3. Update the employee dropdown with the new options
                            $('#employeeSelect').html(response);
                        },
                        error: function (xhr, status, error) {
                            // Handle any error during the AJAX call
                            console.error("AJAX Error: " + status + error);
                            $('#employeeSelect').html('<option value="">Error loading employees</option>');
                        },
                        complete: function () {
                            // Re-enable the dropdown regardless of success/failure
                            $('#employeeSelect').prop('disabled', false);
                        }
                    });
                });
            });


            // Initialize Select2
            $('#employeeSelect').select2({
                placeholder: "-- All Employees --",
                allowClear: true
            });

            // Initialize DataTable
            $('#dailyTaskTable').DataTable({
                dom: 'Bfrtip',
                buttons: [
                    'excel', 'pdf'
                ],
                pageLength: 25,
                ordering: false // Disable ordering to keep the grouped structure intact
            });
        });


    </script>
</body>

</html>