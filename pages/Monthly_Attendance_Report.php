<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}
date_default_timezone_set('Asia/Kolkata');

// Default Month: current month
$selectedMonth = $_GET['month'] ?? date('Y-m');
include('../include/db_config.php'); // ADD THIS - YOU MISSED IT

$monthName = date("F Y", strtotime($selectedMonth . "-01"));

// Fetch employees
// FILTER LOGIC
if (!isset($_GET['filter']) || ($_SESSION['userRole'] ?? '') === 'receptionist') {
    $_GET['filter'] = "kinwat-offline";
}
$filter = $_GET['filter'] ?? '';
$where = " WHERE status='active' AND designation NOT IN ('admin', 'developer', 'md')";

if ($filter == "pune-offline") {
    $where .= " AND (office='Pune' OR office='pune') AND (mode='Offline' OR mode='offline')";
} else if ($filter == "kinwat-offline") {
    $where .= " AND (office='Kinwat' OR office='kinwat') AND (mode='Offline' OR mode='offline')";
} else if ($filter == "online") {
    $where .= " AND mode='Online' ";
}
$sql = "SELECT fullName, userId, designation, office, mode FROM signup $where";
$employees = mysqli_query($con, $sql);

// Store employee data in array for reuse
$employeeData = [];
if ($employees) {
    while ($row = mysqli_fetch_assoc($employees)) {
        $employeeData[] = $row;
    }
}

// EXPORT CODE - AFTER DATA IS FETCHED
if (isset($_GET['export']) && $_GET['export'] == 'excel') {
    // Set Excel headers
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="monthly_report_' . $selectedMonth . '.xls"');

    // Start Excel output with proper styling
    echo '<html><head><meta charset="UTF-8"><title>Monthly Attendance Report</title>';
    echo '<style>
        body { font-family: Arial, sans-serif; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 20px; }
        th { background-color: #103d9e; color: white; padding: 8px; text-align: center; border: 1px solid #ddd; font-weight: bold; }
        td { padding: 6px; border: 1px solid #ddd; text-align: center; }
        .header { text-align: center; margin-bottom: 20px; }
        .title { font-size: 18px; font-weight: bold; margin-bottom: 5px; }
        .subtitle { font-size: 14px; color: #666; margin-bottom: 10px; }
    </style>';
    echo '</head><body>';

    echo '<div class="header">';
    echo '<div class="title">Monthly Attendance Report</div>';
    echo '<div class="subtitle">' . date("F Y", strtotime($selectedMonth . "-01")) . ' | Generated: ' . date('Y-m-d H:i:s') . '</div>';
    echo '</div>';
    echo '<div style="margin-bottom: 15px; padding: 10px; background-color: #f8f9fa; border-left: 4px solid #103d9e;">';
    echo '<strong>Location(mode):</strong> ';
    if ($filter == "pune-offline") {
        echo 'Pune (Offline)';
    } else if ($filter == "kinwat-offline") {
        echo 'Kinwat (Offline)';
    } else if ($filter == "online") {
        echo 'Online';
    } else {
        echo 'All Employees';
    }
    echo '<br>';
    echo '<strong>Total Employees:</strong> ' . count($employeeData);
    echo '</div>';
    echo '<table border="1">';
    echo '<tr>
            <th>Name</th>
            <th># Days</th>
            <th>Weekly Off</th>
            <th>Rostering Off</th>
            <th># Present Days</th>
            <th># Leave Days</th>
            <th># Absent Days</th>
            <th># Holidays</th>
            <th># Tour Days</th>
            <th># Late Marks</th>
          </tr>';

    // Loop through employees (same as your table)
    if (!empty($employeeData)) {
        foreach ($employeeData as $emp) {

            $totalDays = date("t", strtotime($selectedMonth . "-01"));

            $startDate = $selectedMonth . "-01";
            $endDate = date("Y-m-t", strtotime($startDate));


            $today = date('Y-m-d');                     // current server date
            $calcUpTo = min($today, $endDate);          // never go beyond month end

            // Convert to day number
            $upToDay = (int) date('d', strtotime($calcUpTo));

            // Total days we are actually considering (1 → today or month end)
            $daysToConsider = $upToDay;

            // COUNT SUNDAYS
            $year = date("Y", strtotime($selectedMonth . "-01"));
            $month = date("m", strtotime($selectedMonth . "-01"));

            $sundays = 0;
            for ($day = 1; $day <= $totalDays; $day++) {
                $date = $year . "-" . $month . "-" . $day;
                if (date("w", strtotime($date)) == 0) {
                    $sundays++;
                }
            }


            // ------------------------------------------------------------------
            // Count weekly offs → only Saturdays, up to today/current date
            // ------------------------------------------------------------------
            $saturdays = 0;
            for ($day = 1; $day <= $daysToConsider; $day++) {
                $dateStr = sprintf("%s-%s-%02d", $year, $month, $day);

                $weekday = (int) date("w", strtotime($dateStr));  // 0=Sunday, 6=Saturday

                if ($weekday === 6) {   // Saturday only
                    $saturdays++;
                }
            }

            // Rostering Off
            $rosteringOff = 0;

            $roQuery = mysqli_query($con, "
                                                    SELECT from_date, to_date 
                                                    FROM leaves 
                                                    WHERE userId = '{$emp['userId']}'
                                                    AND leave_name = 'Rostering Off'
                                                    AND status = 'Approved'
                                                    AND (
                                                            (from_date BETWEEN '$startDate' AND '$endDate')
                                                            OR
                                                            (to_date BETWEEN '$startDate' AND '$endDate')
                                                            OR
                                                            ('$startDate' BETWEEN from_date AND to_date)
                                                        )
                                                ");

            while ($ro = mysqli_fetch_assoc($roQuery)) {
                $from = max($ro['from_date'], $startDate);
                $to = min($ro['to_date'], $endDate);
                $days = (strtotime($to) - strtotime($from)) / 86400 + 1;
                if ($days > 0)
                    $rosteringOff += $days;
            }

            // ------------------------------
            // NEW LOGIC → Count Present & Late Marks
            // ------------------------------

            $present = 0;
            $late = 0;
            $leaveDays = 0;

            // Fetch attendance records
            $attQuery = mysqli_query($con, "
                SELECT date, punchInTime, lateMark, status
                FROM attendance
                WHERE userId = '{$emp['userId']}'
                AND date BETWEEN '$startDate' AND '$endDate'
                ORDER BY date ASC, punchInTime ASC
            ");

            $presentDates = [];
            $processedLateDates = []; // Track dates where we checked Late Mark

            while ($att = mysqli_fetch_assoc($attQuery)) {

                // Present: punchInTime exists or status=Present
                if (!empty($att['punchInTime']) || in_array(strtolower($att['status']), ['present', 'online', 'present (online)'])) {
                    $presentDates[] = $att['date'];
                }

                // Late marks - ONLY CONSIDER FIRST PUNCH OF THE DAY (Older one)
                if (!in_array($att['date'], $processedLateDates)) {
                    if (strtolower($att['lateMark']) === "yes") {
                        $late++;
                    }
                    $processedLateDates[] = $att['date'];
                }
            }

            $present = count(array_unique($presentDates));


            // ------------------------------
            // NEW LOGIC → Count Tour
            // ------------------------------

            $tour = 0;

            // Fetch attendance records
            $tourQuery = mysqli_query($con, "
                                                    SELECT date, punchInTime, lateMark, status
                                                    FROM tourAttendance
                                                    WHERE userId = '{$emp['userId']}'
                                                    AND date BETWEEN '$startDate' AND '$endDate'
                                                ");

            $tourDates = [];

            while ($tourResult = mysqli_fetch_assoc($tourQuery)) {

                // Present: punchInTime exists or status=Present
                if (!empty($tourResult['punchInTime']) || strtolower($tourResult['status']) == 'present') {
                    $tourDates[] = $tourResult['date'];
                }
            }

            $tour = count(array_unique($tourDates));



            // ------------------------------
            // NEW LOGIC → Count Leave Days
            // ------------------------------

            $leaveQuery = mysqli_query($con, "
                                                    SELECT from_date, to_date
                                                    FROM leaves
                                                    WHERE userId = '{$emp['userId']}'
                                                    AND status = 'Approved'
                                                    AND leave_name != 'Rostering Off'
                                                    AND (
                                                        (from_date BETWEEN '$startDate' AND '$endDate')
                                                        OR
                                                        (to_date BETWEEN '$startDate' AND '$endDate')
                                                        OR
                                                        ('$startDate' BETWEEN from_date AND to_date)
                                                    )
                                                ");

            while ($lv = mysqli_fetch_assoc($leaveQuery)) {
                $from = max($lv['from_date'], $startDate);
                $to = min($lv['to_date'], $endDate);
                $days = (strtotime($to) - strtotime($from)) / 86400 + 1;
                if ($days > 0)
                    $leaveDays += $days;
            }

            // ------------------------------
            // NEW LOGIC → Count Holidays
            // ------------------------------
            $holidays = 0; // For Display
            $deductibleHolidays = 0; // For Calculation

            $holidayQuery = mysqli_query($con, "
                SELECT from_date, to_date, title, description
                FROM holidays
                WHERE (from_date BETWEEN '$startDate' AND '$endDate')
                   OR (to_date BETWEEN '$startDate' AND '$endDate')
                   OR ('$startDate' BETWEEN from_date AND to_date)
            ");

            while ($hol = mysqli_fetch_assoc($holidayQuery)) {
                // Branch filter
                $titleDesc = strtolower($hol['title'] . ' ' . $hol['description']);
                $officeLower = strtolower($emp['office']);

                if (
                    (strpos($titleDesc, 'kinwat') !== false && $officeLower != 'kinwat') ||
                    (strpos($titleDesc, 'pune') !== false && $officeLower != 'pune')
                ) {
                    continue;
                }

                $hStart = max($hol['from_date'], $startDate);
                $hEnd = min($hol['to_date'], $endDate);

                $currentDate = $hStart;
                while (strtotime($currentDate) <= strtotime($hEnd)) {
                    $holidays++; // It is a holiday

                    $dayOfWeek = date('w', strtotime($currentDate));
                    $isWeekend = false;
                    if ($emp['designation'] == "seniorIntern" || $emp['office'] == "Kinwat") {
                        if ($dayOfWeek == 0)
                            $isWeekend = true;
                    } else {
                        if ($dayOfWeek == 0 || $dayOfWeek == 6)
                            $isWeekend = true;
                    }

                    if (!$isWeekend) {
                        $deductibleHolidays++;
                    }
                    $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
                }
            }

            // ------------------------------
            // NEW LOGIC → Absent Days
            // ------------------------------
            $designation = $emp['designation'];
            $office = $emp['office'];

            if ($designation == "seniorIntern" || $office == "Kinwat") {
                $weeklyOff = $sundays;
            } else {
                $weeklyOff = $saturdays + $sundays;
            }

            $absent = $daysToConsider - $weeklyOff - $rosteringOff - $present - $leaveDays - $tour - $deductibleHolidays;
            if ($absent < 0)
                $absent = 0;

            ?>
            <tr>
                <td><?= $emp['fullName'] ?></td>
                <td><?= $totalDays ?></td>
                <td><?= $weeklyOff ?></td>
                <td><?= $rosteringOff ?></td>
                <td><?= $present ?></td>
                <td><?= $leaveDays ?></td>
                <td><?= $absent ?></td>
                <td><?= $holidays ?></td>
                <td><?= $tour ?></td>
                <td><?= $late ?></td>
            </tr>
            <?php
        }
    } else {
        echo '<tr><td colspan="9" style="text-align: center;">No employees found for the selected filter.</td></tr>';
    }

    echo '</table></body></html>';
    exit;
}
// END OF EXPORT CODE

// Default Month: current month
$selectedMonth = $_GET['month'] ?? date('Y-m');
include('../include/db_config.php');
date_default_timezone_set('Asia/Kolkata');

// Default Month: current month
$selectedMonth = $_GET['month'] ?? date('Y-m');
$monthName = date("F Y", strtotime($selectedMonth . "-01"));

// Fetch employees
// FILTER LOGIC
if (!isset($_GET['filter']) || ($_SESSION['userRole'] ?? '') === 'receptionist') {
    $_GET['filter'] = "kinwat-offline";
}
$filter = $_GET['filter'] ?? '';
$where = " WHERE status='active' AND designation NOT IN ('admin', 'developer', 'md') ";

if ($filter == "pune-offline") {
    $where .= " AND (office='Pune' OR office='pune') AND (mode='Offline' OR mode='offline')";
} else if ($filter == "kinwat-offline") {
    $where .= " AND (office='Kinwat' OR office='kinwat') AND (mode='Offline' OR mode='offline')";
} else if ($filter == "online") {
    $where .= " AND mode='Online' ";
}
$sql = "SELECT fullName, userId, designation, office, mode FROM signup $where";
$employees = mysqli_query($con, $sql);

// Store employee data in array for reuse
$employeeData = [];
if ($employees) {
    while ($row = mysqli_fetch_assoc($employees)) {
        $employeeData[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Monthly Attendance Report</title>
    <?php include('../partials/_links.php') ?>

    <!-- Plugins -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

    <style>
        .card {
            border: none;
            border-radius: 12px;
            background: #fff;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            padding: 15px;
        }

        .required {
            color: red;
        }

        .table thead th {
            background-color: #103d9e !important;
            color: white !important;
        }

        .export-btn {
            background-color: #28a745;
            border-color: #28a745;
            color: white;
            margin-left: 10px;
        }

        .export-btn:hover {
            background-color: #218838;
            border-color: #1e7e34;
            color: white;
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

                    <div class="container mt-4">

                        <!-- Title -->
                        <h3 class="fw-bold mb-3">Report 1 : Monthly Attendance Report</h3>

                        <!-- Month Picker -->
                        <!-- Filter Form -->
                        <form method="GET" class="mb-4 d-flex align-items-end gap-3">

                            <!-- Month Picker -->
                            <div>
                                <label class="fw-semibold">Select Month</label>
                                <input type="month" name="month" value="<?= $selectedMonth ?>" class="form-control"
                                    required />
                            </div>&nbsp;&nbsp;&nbsp;

                            <!-- Office / Mode Filter -->
                            <div>
                                <label class="fw-semibold">Filter by Location / Mode</label>
                                <select name="filter" class="form-control select2">
                                    <?php if (($_SESSION['userRole'] ?? '') === 'receptionist'): ?>
                                        <option value="kinwat-offline" selected>Kinwat (Offline)</option>
                                    <?php else: ?>
                                        <option value="">All Employees</option>
                                        <option value="pune-offline" <?= ($_GET['filter'] ?? '') == 'pune-offline' ? 'selected' : '' ?>>
                                            Pune (Offline)
                                        </option>
                                        <option value="kinwat-offline" <?= ($_GET['filter'] ?? '') == 'kinwat-offline' ? 'selected' : '' ?>>
                                            Kinwat (Offline)
                                        </option>
                                        <option value="online" <?= ($_GET['filter'] ?? '') == 'online' ? 'selected' : '' ?>>
                                            Online
                                        </option>
                                    <?php endif; ?>
                                </select>
                            </div>&nbsp;&nbsp;&nbsp;

                            <!-- Submit Button -->
                            <div>
                                <button class="btn btn-primary mt-4" type="submit">
                                    Generate Report
                                </button>
                                <!-- Add Export to Excel button -->
                                <a href="?export=excel&month=<?php echo urlencode($selectedMonth); ?>&filter=<?php echo urlencode($filter); ?>"
                                    class="btn export-btn mt-4">
                                    <i class="mdi mdi-file-excel"></i> Export to Excel
                                </a>
                            </div>

                        </form>


                        <!-- Report Title -->
                        <h5 class="fw-bold mb-3">Attendance Report - <?= $monthName ?></h5>

                        <!-- Attendance Table -->
                        <div class="card p-3">
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th># Days</th>
                                            <th>Weekly Off</th>
                                            <th>Rostering Off</th>
                                            <th># Present Days</th>
                                            <th># Leave Days</th>
                                            <th># Absent Days</th>
                                            <th># Holidays</th>
                                            <th># Tour Days</th>
                                            <th># Late Marks</th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        <?php
                                        if (empty($employeeData)) {
                                            echo '<tr><td colspan="9" class="text-center">No employees found for the selected filter.</td></tr>';
                                        } else {
                                            foreach ($employeeData as $emp) {

                                                $totalDays = date("t", strtotime($selectedMonth . "-01"));

                                                $startDate = $selectedMonth . "-01";
                                                $endDate = date("Y-m-t", strtotime($startDate));


                                                $today = date('Y-m-d');                     // current server date
                                                $calcUpTo = min($today, $endDate);          // never go beyond month end
                                        
                                                // Convert to day number
                                                $upToDay = (int) date('d', strtotime($calcUpTo));

                                                // Total days we are actually considering (1 → today or month end)
                                                $daysToConsider = $upToDay;

                                                // COUNT SUNDAYS
                                                $year = date("Y", strtotime($selectedMonth . "-01"));
                                                $month = date("m", strtotime($selectedMonth . "-01"));

                                                $sundays = 0;
                                                for ($day = 1; $day <= $totalDays; $day++) {
                                                    $date = $year . "-" . $month . "-" . $day;
                                                    if (date("w", strtotime($date)) == 0) {
                                                        $sundays++;
                                                    }
                                                }


                                                // ------------------------------------------------------------------
                                                // Count weekly offs → only Saturdays, up to today/current date
                                                // ------------------------------------------------------------------
                                                $saturdays = 0;
                                                for ($day = 1; $day <= $daysToConsider; $day++) {
                                                    $dateStr = sprintf("%s-%s-%02d", $year, $month, $day);

                                                    $weekday = (int) date("w", strtotime($dateStr));  // 0=Sunday, 6=Saturday
                                        
                                                    if ($weekday === 6) {   // Saturday only
                                                        $saturdays++;
                                                    }
                                                }

                                                // Rostering Off
                                                $rosteringOff = 0;

                                                $roQuery = mysqli_query($con, "
                                                    SELECT from_date, to_date 
                                                    FROM leaves 
                                                    WHERE userId = '{$emp['userId']}'
                                                    AND leave_name = 'Rostering Off'
                                                    AND status = 'Approved'
                                                    AND (
                                                            (from_date BETWEEN '$startDate' AND '$endDate')
                                                            OR
                                                            (to_date BETWEEN '$startDate' AND '$endDate')
                                                            OR
                                                            ('$startDate' BETWEEN from_date AND to_date)
                                                        )
                                                ");

                                                while ($ro = mysqli_fetch_assoc($roQuery)) {
                                                    $from = max($ro['from_date'], $startDate);
                                                    $to = min($ro['to_date'], $endDate);
                                                    $days = (strtotime($to) - strtotime($from)) / 86400 + 1;
                                                    if ($days > 0)
                                                        $rosteringOff += $days;
                                                }

                                                // ------------------------------
                                                // NEW LOGIC → Count Present & Late Marks
                                                // ------------------------------
                                        
                                                $present = 0;
                                                $late = 0;
                                                $leaveDays = 0;

                                                // Fetch attendance records
                                                $attQuery = mysqli_query($con, "
                                                    SELECT date, punchInTime, lateMark, status
                                                    FROM attendance
                                                    WHERE userId = '{$emp['userId']}'
                                                    AND date BETWEEN '$startDate' AND '$endDate'
                                                    ORDER BY date ASC, punchInTime ASC
                                                ");

                                                $presentDates = [];
                                                $processedLateDates = []; // Track dates where we checked Late Mark
                                        
                                                while ($att = mysqli_fetch_assoc($attQuery)) {

                                                    // Present: punchInTime exists or status=Present
                                                    if (!empty($att['punchInTime']) || in_array(strtolower($att['status']), ['present', 'online', 'present (online)'])) {
                                                        $presentDates[] = $att['date'];
                                                    }

                                                    // Late marks - ONLY CONSIDER FIRST PUNCH OF THE DAY (Older one)
                                                    if (!in_array($att['date'], $processedLateDates)) {
                                                        if (strtolower($att['lateMark']) === "yes") {
                                                            $late++;
                                                        }
                                                        $processedLateDates[] = $att['date'];
                                                    }
                                                }

                                                $present = count(array_unique($presentDates));


                                                // ------------------------------
                                                // NEW LOGIC → Count Tour
                                                // ------------------------------
                                        
                                                $tour = 0;

                                                // Fetch attendance records
                                                $tourQuery = mysqli_query($con, "
                                                    SELECT date, punchInTime, lateMark, status
                                                    FROM tourAttendance
                                                    WHERE userId = '{$emp['userId']}'
                                                    AND date BETWEEN '$startDate' AND '$endDate'
                                                ");

                                                $tourDates = [];

                                                while ($tourResult = mysqli_fetch_assoc($tourQuery)) {

                                                    // Present: punchInTime exists or status=Present
                                                    if (!empty($tourResult['punchInTime']) || strtolower($tourResult['status']) == 'present') {
                                                        $tourDates[] = $tourResult['date'];
                                                    }
                                                }

                                                $tour = count(array_unique($tourDates));



                                                // ------------------------------
                                                // NEW LOGIC → Count Leave Days
                                                // ------------------------------
                                        
                                                $leaveQuery = mysqli_query($con, "
                                                    SELECT from_date, to_date
                                                    FROM leaves
                                                    WHERE userId = '{$emp['userId']}'
                                                    AND status = 'Approved'
                                                    AND leave_name != 'Rostering Off'
                                                    AND (
                                                        (from_date BETWEEN '$startDate' AND '$endDate')
                                                        OR
                                                        (to_date BETWEEN '$startDate' AND '$endDate')
                                                        OR
                                                        ('$startDate' BETWEEN from_date AND to_date)
                                                    )
                                                ");

                                                while ($lv = mysqli_fetch_assoc($leaveQuery)) {
                                                    $from = max($lv['from_date'], $startDate);
                                                    $to = min($lv['to_date'], $endDate);
                                                    $days = (strtotime($to) - strtotime($from)) / 86400 + 1;
                                                    if ($days > 0)
                                                        $leaveDays += $days;
                                                }

                                                // ------------------------------
                                                // NEW LOGIC → Count Holidays
                                                // ------------------------------
                                                $holidays = 0; // For Display
                                                $deductibleHolidays = 0; // For Calculation
                                        
                                                $holidayQuery = mysqli_query($con, "
                                                    SELECT from_date, to_date, title, description
                                                    FROM holidays
                                                    WHERE (from_date BETWEEN '$startDate' AND '$endDate')
                                                       OR (to_date BETWEEN '$startDate' AND '$endDate')
                                                       OR ('$startDate' BETWEEN from_date AND to_date)
                                                ");

                                                while ($hol = mysqli_fetch_assoc($holidayQuery)) {
                                                    // Branch filter
                                                    $titleDesc = strtolower($hol['title'] . ' ' . $hol['description']);
                                                    $officeLower = strtolower($emp['office']);

                                                    if (
                                                        (strpos($titleDesc, 'kinwat') !== false && $officeLower != 'kinwat') ||
                                                        (strpos($titleDesc, 'pune') !== false && $officeLower != 'pune')
                                                    ) {
                                                        continue;
                                                    }

                                                    $hStart = max($hol['from_date'], $startDate);
                                                    $hEnd = min($hol['to_date'], $endDate);

                                                    $currentDate = $hStart;
                                                    while (strtotime($currentDate) <= strtotime($hEnd)) {
                                                        $holidays++; // It is a holiday
                                        
                                                        $dayOfWeek = date('w', strtotime($currentDate));
                                                        $isWeekend = false;
                                                        if ($emp['designation'] == "seniorIntern" || $emp['office'] == "Kinwat") {
                                                            if ($dayOfWeek == 0)
                                                                $isWeekend = true;
                                                        } else {
                                                            if ($dayOfWeek == 0 || $dayOfWeek == 6)
                                                                $isWeekend = true;
                                                        }

                                                        if (!$isWeekend) {
                                                            $deductibleHolidays++;
                                                        }
                                                        $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
                                                    }
                                                }

                                                // ------------------------------
                                                // NEW LOGIC → Absent Days
                                                // ------------------------------
                                                $designation = $emp['designation'];
                                                $office = $emp['office'];

                                                if ($designation == "seniorIntern" || $office == "Kinwat") {
                                                    $weeklyOff = $sundays;
                                                } else {
                                                    $weeklyOff = $saturdays + $sundays;
                                                }

                                                $absent = $daysToConsider - $weeklyOff - $rosteringOff - $present - $leaveDays - $tour - $deductibleHolidays;
                                                if ($absent < 0)
                                                    $absent = 0;

                                                ?>
                                                <tr>
                                                    <td><?= $emp['fullName'] ?></td>
                                                    <td><?= $totalDays ?></td>
                                                    <td><?= $weeklyOff ?></td>
                                                    <td><?= $rosteringOff ?></td>
                                                    <td><?= $present ?></td>
                                                    <td><?= $leaveDays ?></td>
                                                    <td><?= $absent ?></td>
                                                    <td><?= $holidays ?></td>
                                                    <td><?= $tour ?></td>
                                                    <td><?= $late ?></td>
                                                </tr>
                                                <?php
                                            }
                                        } ?>
                                    </tbody>

                                </table>
                            </div>
                        </div>

                    </div> <!-- container -->

                </div> <!-- content-wrapper -->
            </div> <!-- main-panel -->
        </div> <!-- page-body-wrapper -->
    </div> <!-- container-scroller -->

    <!-- Core JS -->
    <script src="../vendors/js/vendor.bundle.base.js"></script>
    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>
    <script src="../js/settings.js"></script>
    <script src="../js/todolist.js"></script>

    <!-- Plugins JS -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

</body>

</html>