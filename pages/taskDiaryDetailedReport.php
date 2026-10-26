<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}
include('../include/db_config.php');
include('../include/checkPasswordStatus.php');

date_default_timezone_set('Asia/Kolkata');

$userId = $_SESSION['userId'];
$userRole = strtolower(str_replace(['_', ' '], '', $_SESSION['userRole'] ?? ''));
$designation = strtolower(str_replace(['_', ' '], '', $_SESSION['designation'] ?? ''));

// Authorization check: MD, HR, Admin, Resource Allocator, Manager, Department Head, Team Lead
$allowedRoles = ['md', 'hr', 'admin', 'manager', 'departmenthead', 'resourceallocator', 'teamlead'];
if (!in_array($userRole, $allowedRoles) && !in_array($designation, $allowedRoles)) {
    echo '<script>alert("You are not authorized to access this page.");window.location.href="login.php";</script>';
    die();
}

// --- Filter Inputs ---
$from_date = isset($_GET['from_date']) && !empty($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) && !empty($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$selected_emp = isset($_GET['employee_id']) ? $_GET['employee_id'] : '';
$selected_status = isset($_GET['status']) ? $_GET['status'] : '';

// Ensure from_date <= to_date
if (strtotime($from_date) > strtotime($to_date)) {
    $temp = $from_date;
    $from_date = $to_date;
    $to_date = $temp;
}

// Generate Date Range array
$dates = [];
$currentTs = strtotime($from_date);
$endTs = strtotime($to_date);

// Cap maximum range to 62 days to ensure matrix responsiveness
$daysDiff = floor(($endTs - $currentTs) / 86400);
if ($daysDiff > 62) {
    $endTs = strtotime('+62 days', $currentTs);
    $to_date = date('Y-m-d', $endTs);
}

while ($currentTs <= $endTs) {
    $dates[] = date('Y-m-d', $currentTs);
    $currentTs = strtotime('+1 day', $currentTs);
}

// --- Fetch Senior Interns & Resource Allocators Only ---
$targetRoleWhere = "WHERE (status = 'Active' OR regstatus = 'Approved') 
    AND (
        LOWER(REPLACE(REPLACE(designation, ' ', ''), '_', '')) IN ('seniorintern', 'resourceallocator', 'resourcehead', 'resourcemanager')
        OR LOWER(REPLACE(REPLACE(userRole, ' ', ''), '_', '')) IN ('seniorintern', 'resourceallocator', 'resourcehead', 'resourcemanager')
    )";

// Employees for filter dropdown
$empDropdownRes = mysqli_query($con, "SELECT userId, fullName, designation FROM signup $targetRoleWhere ORDER BY fullName ASC");
$allEmployeesForSelect = [];
if ($empDropdownRes) {
    while ($er = mysqli_fetch_assoc($empDropdownRes)) {
        $allEmployeesForSelect[] = $er;
    }
}

// Filtered Employees List for Report
$empReportWhere = $targetRoleWhere;
if (!empty($selected_emp)) {
    $empEsc = mysqli_real_escape_string($con, $selected_emp);
    $empReportWhere .= " AND userId = '$empEsc'";
}
$qEmp = mysqli_query($con, "SELECT userId, fullName, office, designation FROM signup $empReportWhere ORDER BY fullName ASC");
$employees = [];
if ($qEmp) {
    while ($r = mysqli_fetch_assoc($qEmp)) {
        $employees[] = $r;
    }
}

// --- Fetch Approved Leaves in Date Range (with leave_type) ---
$from_date_esc = mysqli_real_escape_string($con, $from_date);
$to_date_esc = mysqli_real_escape_string($con, $to_date);

$qLeaves = mysqli_query($con, "
    SELECT userId, from_date, to_date, leave_type 
    FROM leaves 
    WHERE status = 'Approved' 
      AND from_date <= '$to_date_esc' 
      AND to_date >= '$from_date_esc'
");

$empLeaves = [];
if ($qLeaves) {
    while ($lRow = mysqli_fetch_assoc($qLeaves)) {
        $lUser = $lRow['userId'];
        $lType = !empty($lRow['leave_type']) ? trim($lRow['leave_type']) : 'Leave';
        $lStart = strtotime($lRow['from_date']);
        $lEnd = strtotime($lRow['to_date']);

        foreach ($dates as $dt) {
            $dtTs = strtotime($dt);
            if ($dtTs >= $lStart && $dtTs <= $lEnd) {
                $empLeaves[$lUser][$dt] = $lType;
            }
        }
    }
}

// --- Fetch Tasks from task_diary (Excluding Deleted Tasks) ---
$taskWhere = "WHERE date BETWEEN '$from_date_esc' AND '$to_date_esc' 
  AND (is_deleted = 0 OR is_deleted IS NULL) 
  AND status != 'Deleted'";

if (!empty($selected_emp)) {
    $empEsc = mysqli_real_escape_string($con, $selected_emp);
    $taskWhere .= " AND userId = '$empEsc'";
}

if (!empty($selected_status) && in_array($selected_status, ['Completed', 'Pending', 'Postponed'])) {
    $statusEsc = mysqli_real_escape_string($con, $selected_status);
    $taskWhere .= " AND status = '$statusEsc'";
}

$qTasks = mysqli_query($con, "
    SELECT id, taskid, userId, date, task_description, task_type, status 
    FROM task_diary 
    $taskWhere
    ORDER BY date ASC, id ASC
");

$matrixData = [];
$empStats = [];
$overallStats = [
    'total' => 0,
    'completed' => 0,
    'postponed' => 0,
    'pending' => 0
];

if ($qTasks) {
    while ($t = mysqli_fetch_assoc($qTasks)) {
        $uId = $t['userId'];
        $d = $t['date'];
        $st = ucfirst(strtolower(trim($t['status'])));

        // Check if day is Sunday or employee is on leave
        $isSunday = (date('N', strtotime($d)) == 7);
        $isOnLeave = !empty($empLeaves[$uId][$d]);

        // Do not process/count tasks on Sunday or Leave
        if ($isSunday || $isOnLeave) {
            continue;
        }

        if (!isset($matrixData[$uId])) {
            $matrixData[$uId] = [];
        }
        if (!isset($matrixData[$uId][$d])) {
            $matrixData[$uId][$d] = [];
        }
        $matrixData[$uId][$d][] = $t;

        if (!isset($empStats[$uId])) {
            $empStats[$uId] = ['total' => 0, 'completed' => 0, 'postponed' => 0, 'pending' => 0];
        }

        $empStats[$uId]['total']++;
        $overallStats['total']++;

        if ($st === 'Completed') {
            $empStats[$uId]['completed']++;
            $overallStats['completed']++;
        } elseif ($st === 'Postponed') {
            $empStats[$uId]['postponed']++;
            $overallStats['postponed']++;
        } else {
            $empStats[$uId]['pending']++;
            $overallStats['pending']++;
        }
    }
}

// Compute overall ratios
$overallCompRatio = $overallStats['total'] > 0 ? round(($overallStats['completed'] / $overallStats['total']) * 100, 1) : 0;
$overallPostRatio = $overallStats['total'] > 0 ? round(($overallStats['postponed'] / $overallStats['total']) * 100, 1) : 0;

// --- SERVER-SIDE CSV EXPORT ---
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Task_Diary_Detailed_Report_' . date('Ymd') . '.csv"');

    $output = fopen('php://output', 'w');
    // Output UTF-8 BOM for proper Excel encoding
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    // Header row
    $csvHeader = ['Sr. No.', 'Employee Name'];
    foreach ($dates as $dt) {
        $csvHeader[] = date('d-m-Y', strtotime($dt));
    }
    $csvHeader[] = 'Total Tasks';
    $csvHeader[] = 'Completed';
    $csvHeader[] = 'Postponed';
    $csvHeader[] = 'Pending';
    $csvHeader[] = 'Completion Ratio (%)';
    $csvHeader[] = 'Postponed Ratio (%)';

    fputcsv($output, $csvHeader);

    $srNo = 1;
    foreach ($employees as $emp) {
        $eId = $emp['userId'];
        $eName = $emp['fullName'];

        $row = [$srNo++, $eName];

        foreach ($dates as $dt) {
            $cellItems = [];
            $isSunday = (date('N', strtotime($dt)) == 7);
            $leaveType = $empLeaves[$eId][$dt] ?? null;
            $isOnLeave = !empty($leaveType);
            $hasTasks = isset($matrixData[$eId][$dt]) && !empty($matrixData[$eId][$dt]);

            if ($isOnLeave) {
                $cellItems[] = "[$leaveType]";
            } elseif ($isSunday) {
                $cellItems[] = '[Sunday]';
            } elseif ($hasTasks) {
                foreach ($matrixData[$eId][$dt] as $tRow) {
                    $desc = trim($tRow['task_description']);
                    $rawSt = trim($tRow['status']);
                    $stText = (strtolower($rawSt) === 'completed') ? 'completed' : ((strtolower($rawSt) === 'postponed') ? 'postponed' : 'Pending');
                    $cellItems[] = "$desc [$stText]";
                }
            }

            // Join multiple tasks with newline "\n" so they display one under the other inside the CSV cell
            $row[] = !empty($cellItems) ? implode("\n", $cellItems) : '-';
        }

        $tTotal = $empStats[$eId]['total'] ?? 0;
        $tComp = $empStats[$eId]['completed'] ?? 0;
        $tPost = $empStats[$eId]['postponed'] ?? 0;
        $tPend = $empStats[$eId]['pending'] ?? 0;

        $compRatio = $tTotal > 0 ? round(($tComp / $tTotal) * 100, 1) . '%' : '0%';
        $postRatio = $tTotal > 0 ? round(($tPost / $tTotal) * 100, 1) . '%' : '0%';

        $row[] = $tTotal;
        $row[] = $tComp;
        $row[] = $tPost;
        $row[] = $tPend;
        $row[] = $compRatio;
        $row[] = $postRatio;

        fputcsv($output, $row);
    }

    // Grand Total Row
    $totalRow = ['-', 'Total / Overall Ratios:'];
    foreach ($dates as $dt) {
        $totalRow[] = '';
    }
    $totalRow[] = $overallStats['total'];
    $totalRow[] = $overallStats['completed'];
    $totalRow[] = $overallStats['postponed'];
    $totalRow[] = $overallStats['pending'];
    $totalRow[] = $overallCompRatio . '%';
    $totalRow[] = $overallPostRatio . '%';

    fputcsv($output, $totalRow);
    fclose($output);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Task Diary Detailed Report</title>
    <?php include('../partials/_links.php'); ?>
    <style>
        :root {
            --primary-color: #1e293b;
            --accent-blue: #2563eb;
            --accent-green: #16a34a;
            --accent-orange: #ea580c;
            --accent-yellow: #ca8a04;
            --bg-light: #f8fafc;
            --border-color: #cbd5e1;
        }

        body {
            background-color: #f1f5f9;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
        }

        .report-card {
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.04);
            border: 1px solid #e2e8f0;
            margin-bottom: 24px;
            overflow: hidden;
        }

        .report-card-header {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: #ffffff;
            padding: 20px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }

        .report-title {
            font-size: 1.4rem;
            font-weight: 700;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Filter Panel */
        .filter-box {
            background: #ffffff;
            border-radius: 12px;
            padding: 20px;
            border: 1px solid #e2e8f0;
            margin-bottom: 24px;
        }

        /* Table Styling */
        .matrix-table-wrapper {
            overflow-x: auto;
            max-width: 100%;
        }

        .matrix-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.88rem;
            background: #ffffff;
        }

        .matrix-table th,
        .matrix-table td {
            border: 1px solid #cbd5e1;
            padding: 10px 12px;
            vertical-align: top;
        }

        .matrix-table thead th {
            background-color: #f8fafc;
            color: #0f172a;
            font-weight: 700;
            text-align: center;
            vertical-align: middle;
            white-space: nowrap;
        }

        .matrix-table thead tr:first-child th {
            font-size: 0.95rem;
            background-color: #e2e8f0;
        }

        .matrix-table tbody tr:hover {
            background-color: #f8fafc;
        }

        .emp-name-cell {
            font-weight: 600;
            color: #0f172a;
            white-space: nowrap;
            min-width: 160px;
            background-color: #ffffff;
        }

        .task-cell {
            min-width: 220px;
            max-width: 320px;
            line-height: 1.5;
        }

        .task-item {
            margin-bottom: 6px;
            padding-bottom: 6px;
            border-bottom: 1px dashed #e2e8f0;
            font-size: 0.85rem;
            word-wrap: break-word;
        }

        .task-item:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }

        /* Status Tags */
        .status-tag {
            font-weight: 600;
            font-size: 0.78rem;
            padding: 2px 6px;
            border-radius: 4px;
            display: inline-block;
            margin-left: 4px;
        }

        .status-tag.completed {
            color: #15803d;
            background-color: #dcfce7;
            border: 1px solid #bbf7d0;
        }

        .status-tag.postponed {
            color: #c2410c;
            background-color: #ffedd5;
            border: 1px solid #fed7aa;
        }

        .status-tag.pending {
            color: #854d0e;
            background-color: #fef9c3;
            border: 1px solid #fef08a;
        }

        .status-tag.leave {
            color: #9333ea;
            background-color: #f3e8ff;
            border: 1px solid #e9d5ff;
        }

        .status-tag.sunday {
            color: #475569;
            background-color: #f1f5f9;
            border: 1px solid #cbd5e1;
        }

        /* Ratio Badges */
        .ratio-badge-comp {
            background-color: #dcfce7;
            color: #15803d;
            font-weight: 700;
            padding: 4px 8px;
            border-radius: 6px;
            display: inline-block;
        }

        .ratio-badge-post {
            background-color: #ffedd5;
            color: #c2410c;
            font-weight: 700;
            padding: 4px 8px;
            border-radius: 6px;
            display: inline-block;
        }

        .total-summary-row {
            background-color: #f1f5f9;
            font-weight: 700;
            color: #0f172a;
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

                    <!-- Title & Header Card -->
                    <div class="report-card mb-4">
                        <div class="report-card-header">
                            <div>
                                <h3 class="report-title">
                                    <i class="fa-solid fa-journal-whills"></i> Task Diary Detailed Report
                                </h3>
                                <p class="mb-0 text-white-50 small mt-1">
                                    Employee task matrix (Date Range:
                                    <?php echo date('d-m-Y', strtotime($from_date)); ?> to
                                    <?php echo date('d-m-Y', strtotime($to_date)); ?>)
                                </p>
                            </div>
                            <div class="d-flex gap-2">
                                <?php
                                $csvExportUrl = "taskDiaryDetailedReport.php?export=csv&from_date=" . urlencode($from_date) . "&to_date=" . urlencode($to_date) . "&employee_id=" . urlencode($selected_emp) . "&status=" . urlencode($selected_status);
                                ?>
                                <a href="<?php echo $csvExportUrl; ?>"
                                    class="btn btn-success text-white btn-sm font-weight-bold">
                                    <i class="fa-solid fa-file-csv me-1"></i> Export to CSV
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Filter Panel with Searchable Dropdown & Live Search -->
                    <div class="filter-box">
                        <form method="GET" action="taskDiaryDetailedReport.php" class="row align-items-end g-3">
                            <div class="col-md-3">
                                <label class="form-label font-weight-bold text-dark mb-1">From Date</label>
                                <input type="date" class="form-control" name="from_date"
                                    value="<?php echo htmlspecialchars($from_date); ?>" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label font-weight-bold text-dark mb-1">To Date</label>
                                <input type="date" class="form-control" name="to_date"
                                    value="<?php echo htmlspecialchars($to_date); ?>" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label font-weight-bold text-dark mb-1">Search / Select
                                    Employee</label>
                                <select class="form-control select2" name="employee_id" id="employeeSelect">
                                    <option value="">-- All Senior Interns & Resource Allocators --</option>
                                    <?php foreach ($allEmployeesForSelect as $empItem): ?>
                                        <option value="<?php echo $empItem['userId']; ?>" <?php if ($selected_emp === $empItem['userId'])
                                               echo 'selected'; ?>>
                                            <?php echo htmlspecialchars($empItem['fullName']) . ' (' . htmlspecialchars($empItem['designation']) . ')'; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label font-weight-bold text-dark mb-1">Status Filter</label>
                                <select class="form-control" name="status">
                                    <option value="">-- All Statuses --</option>
                                    <option value="Completed" <?php if ($selected_status === 'Completed')
                                        echo 'selected'; ?>>Completed</option>
                                    <option value="Pending" <?php if ($selected_status === 'Pending')
                                        echo 'selected'; ?>>
                                        Pending</option>
                                    <option value="Postponed" <?php if ($selected_status === 'Postponed')
                                        echo 'selected'; ?>>Postponed</option>
                                </select>
                            </div>
                            <div class="col-md-1 d-flex gap-2">
                                <button type="submit" class="btn btn-primary w-100 font-weight-bold">
                                    <i class="fa-solid fa-filter me-1"></i> Filter
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Main Report Matrix Table -->
                    <div class="report-card">
                        <!-- Table Quick Search Header -->
                        <div
                            class="p-3 bg-light border-bottom d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <div class="d-flex align-items-center gap-2" style="min-width: 280px; max-width: 400px;">
                                <i class="fa-solid fa-magnifying-glass text-muted"></i>
                                <input type="text" id="tableSearchInput" class="form-control form-control-sm"
                                    placeholder="Quick search employee name in table...">
                            </div>
                        </div>

                        <div class="card-body p-0">
                            <div class="matrix-table-wrapper">
                                <table class="matrix-table text-start align-top mb-0" id="detailedReportTable">
                                    <thead>
                                        <!-- Main Title Banner in Table -->
                                        <tr>
                                            <th colspan="<?php echo count($dates) + 8; ?>"
                                                class="bg-dark text-white py-2 text-center text-uppercase font-weight-bold">
                                                Task Diary Detailed Report
                                            </th>
                                        </tr>
                                        <!-- Level 1 Super Header -->
                                        <tr>
                                            <th rowspan="2" style="width: 50px;">Sr. No.</th>
                                            <th rowspan="2" class="emp-name-cell">Employee Name</th>
                                            <th colspan="<?php echo count($dates); ?>" class="bg-light">Date</th>
                                            <th colspan="6" class="bg-secondary text-white">Summary Statistics & Ratio
                                            </th>
                                        </tr>
                                        <!-- Level 2 Sub Header (Dates & Metrics) -->
                                        <tr>
                                            <?php foreach ($dates as $dt): ?>
                                                <?php $isSundayHeader = (date('N', strtotime($dt)) == 7); ?>
                                                <th class="<?php echo $isSundayHeader ? 'bg-warning-subtle text-dark' : 'bg-white'; ?>"
                                                    style="min-width: 180px;">
                                                    <?php echo date('d-m-Y', strtotime($dt)); ?>
                                                    <?php if ($isSundayHeader): ?>
                                                        <br><small class="text-muted font-weight-bold">(Sunday)</small>
                                                    <?php endif; ?>
                                                </th>
                                            <?php endforeach; ?>

                                            <th class="bg-light">Total Tasks</th>
                                            <th class="bg-light text-success">Completed</th>
                                            <th class="bg-light text-danger">Postponed</th>
                                            <th class="bg-light text-warning">Pending</th>
                                            <th class="bg-light text-success">Completion Ratio (%)</th>
                                            <th class="bg-light text-danger">Postponed Ratio (%)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($employees)): ?>
                                            <tr>
                                                <td colspan="<?php echo count($dates) + 8; ?>"
                                                    class="text-center py-4 text-muted">
                                                    No Senior Interns or Resource Allocators found matching the selected
                                                    filter criteria.
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php
                                            $srNo = 1;
                                            foreach ($employees as $emp):
                                                $eId = $emp['userId'];
                                                $eName = htmlspecialchars($emp['fullName']);

                                                $tTotal = $empStats[$eId]['total'] ?? 0;
                                                $tComp = $empStats[$eId]['completed'] ?? 0;
                                                $tPost = $empStats[$eId]['postponed'] ?? 0;
                                                $tPend = $empStats[$eId]['pending'] ?? 0;

                                                $compRatio = $tTotal > 0 ? round(($tComp / $tTotal) * 100, 1) : 0;
                                                $postRatio = $tTotal > 0 ? round(($tPost / $tTotal) * 100, 1) : 0;
                                                ?>
                                                <tr>
                                                    <td class="text-center font-weight-bold"><?php echo $srNo++; ?></td>
                                                    <td class="emp-name-cell"><?php echo $eName; ?></td>

                                                    <!-- Date Cells -->
                                                    <?php foreach ($dates as $dt): ?>
                                                        <?php
                                                        $isSunday = (date('N', strtotime($dt)) == 7);
                                                        $leaveType = $empLeaves[$eId][$dt] ?? null;
                                                        $isOnLeave = !empty($leaveType);
                                                        $hasTasks = isset($matrixData[$eId][$dt]) && !empty($matrixData[$eId][$dt]);
                                                        ?>
                                                        <td class="task-cell <?php if ($isSunday)
                                                            echo 'bg-light'; ?>">
                                                            <?php
                                                            if ($isOnLeave):
                                                                ?>
                                                                <div class="task-item">
                                                                    <span
                                                                        class="status-tag leave">[<?php echo htmlspecialchars($leaveType); ?>]</span>
                                                                </div>
                                                                <?php
                                                            elseif ($isSunday):
                                                                ?>
                                                                <div class="task-item">
                                                                    <span class="status-tag sunday">[Sunday]</span>
                                                                </div>
                                                                <?php
                                                            elseif ($hasTasks):
                                                                foreach ($matrixData[$eId][$dt] as $tRow):
                                                                    $desc = htmlspecialchars($tRow['task_description']);
                                                                    $rawSt = trim($tRow['status']);
                                                                    $stCls = strtolower($rawSt);
                                                                    $stText = ($stCls === 'completed') ? 'completed' : (($stCls === 'postponed') ? 'postponed' : 'Pending');
                                                                    ?>
                                                                    <div class="task-item">
                                                                        <span><?php echo $desc; ?></span>
                                                                        <span
                                                                            class="status-tag <?php echo $stCls; ?>">[<?php echo $stText; ?>]</span>
                                                                    </div>
                                                                    <?php
                                                                endforeach;
                                                            else:
                                                                echo '<span class="text-muted small">-</span>';
                                                            endif;
                                                            ?>
                                                        </td>
                                                    <?php endforeach; ?>

                                                    <!-- Summary Ratio Columns per Employee -->
                                                    <td class="text-center font-weight-bold"><?php echo $tTotal; ?></td>
                                                    <td class="text-center font-weight-bold text-success"><?php echo $tComp; ?>
                                                    </td>
                                                    <td class="text-center font-weight-bold text-danger"><?php echo $tPost; ?>
                                                    </td>
                                                    <td class="text-center font-weight-bold text-warning"><?php echo $tPend; ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="ratio-badge-comp"><?php echo $compRatio; ?>%</span>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="ratio-badge-post"><?php echo $postRatio; ?>%</span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>

                                            <!-- Bottom Grand Total Summary Row -->
                                            <tr class="total-summary-row">
                                                <td colspan="2" class="text-end font-weight-bold py-3">Total / Overall
                                                    Ratios:</td>
                                                <td colspan="<?php echo count($dates); ?>"
                                                    class="text-center text-muted small">
                                                    (Summary for Date Range:
                                                    <?php echo date('d-m-Y', strtotime($from_date)); ?> to
                                                    <?php echo date('d-m-Y', strtotime($to_date)); ?>)
                                                </td>
                                                <td class="text-center font-weight-bold py-3 fs-6">
                                                    <?php echo $overallStats['total']; ?>
                                                </td>
                                                <td class="text-center font-weight-bold text-success py-3 fs-6">
                                                    <?php echo $overallStats['completed']; ?>
                                                </td>
                                                <td class="text-center font-weight-bold text-danger py-3 fs-6">
                                                    <?php echo $overallStats['postponed']; ?>
                                                </td>
                                                <td class="text-center font-weight-bold text-warning py-3 fs-6">
                                                    <?php echo $overallStats['pending']; ?>
                                                </td>
                                                <td class="text-center py-3">
                                                    <span
                                                        class="ratio-badge-comp fs-6"><?php echo $overallCompRatio; ?>%</span>
                                                </td>
                                                <td class="text-center py-3">
                                                    <span
                                                        class="ratio-badge-post fs-6"><?php echo $overallPostRatio; ?>%</span>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div>
                <!-- content-wrapper ends -->
                <?php include('../partials/_footer.php'); ?>
            </div>
            <!-- main-panel ends -->
        </div>
        <!-- page-body-wrapper ends -->
    </div>
    <!-- container-scroller -->

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="../vendors/select2/select2.min.js"></script>
    <script>
        $(document).ready(function () {
            // Enable Select2 for employee search
            if ($.fn.select2) {
                $('.select2').select2({
                    width: '100%',
                    placeholder: '-- All Senior Interns & Resource Allocators --',
                    allowClear: true
                });
            }

            // Quick Live Table Search Filter
            $('#tableSearchInput').on('keyup', function () {
                var value = $(this).val().toLowerCase();
                $('#detailedReportTable tbody tr:not(.total-summary-row)').filter(function () {
                    $(this).toggle($(this).find('.emp-name-cell').text().toLowerCase().indexOf(value) > -1);
                });
            });
        });
    </script>
</body>

</html>