<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = '../login.php';</script>";
    exit;
}

include('../include/db_config.php');

$punchInBasePath  = '../uploads/tour/punchIn/';
$punchOutBasePath = '../uploads/tour/punchOut/';

// Get filters
$fromDate = isset($_GET['fromDate']) ? trim($_GET['fromDate']) : '';
$toDate   = isset($_GET['toDate'])   ? trim($_GET['toDate'])   : '';
$empType  = isset($_GET['empType'])  ? trim($_GET['empType'])  : '';
$empName  = isset($_GET['empName'])  ? trim($_GET['empName'])  : '';

// Base query
$sql = "SELECT ta.*, s.fullName, s.type AS employeeType, s.emp_id, s.internId,
               t.id AS tId, t.projectId, t.reason AS tourReason,
               p.projectName AS ProjectName,
               ta.punchInSelfie, ta.punchOutSelfie
        FROM tourAttendance ta
        JOIN signup        s ON ta.userId    = s.userId
        JOIN tour          t ON ta.tourId    = t.id
        JOIN projectMaster p ON t.projectId  = p.projectId
        WHERE 1=1";

if ($fromDate && $toDate) {
    $sql .= " AND ta.date BETWEEN '" . mysqli_real_escape_string($con, $fromDate) . "' AND '" . mysqli_real_escape_string($con, $toDate) . "'";
}
if ($empType) {
    $sql .= " AND s.type = '" . mysqli_real_escape_string($con, $empType) . "'";
}
if ($empName) {
    $sql .= " AND s.fullName LIKE '%" . mysqli_real_escape_string($con, $empName) . "%'";
}

$sql .= " ORDER BY ta.date DESC, ta.id DESC";

$attendanceResult = mysqli_query($con, $sql);
if (!$attendanceResult) {
    die("Main query failed: " . mysqli_error($con));
}

$typeResult = mysqli_query($con, "SELECT DISTINCT type FROM signup WHERE type IS NOT NULL AND type != '' ORDER BY type ASC");
if (!$typeResult) {
    die("Type query failed: " . mysqli_error($con));
}

// ── Helper: calculate man hours live from punchIn / punchOut ────
function calcManHours($punchIn, $punchOut, $date) {
    if (
        empty($punchIn)  || $punchIn  === '00:00:00' ||
        empty($punchOut) || $punchOut === '00:00:00'
    ) {
        return '—';
    }
    try {
        $inDT  = new DateTime($date . ' ' . $punchIn);
        $outDT = new DateTime($date . ' ' . $punchOut);
        if ($outDT < $inDT) {
            $outDT->modify('+1 day'); // past-midnight edge case
        }
        $diff       = $inDT->diff($outDT);
        $totalHours = ($diff->days * 24) + $diff->h;
        return str_pad($totalHours, 2, '0', STR_PAD_LEFT)
             . ':' . str_pad($diff->i, 2, '0', STR_PAD_LEFT)
             . ':' . str_pad($diff->s, 2, '0', STR_PAD_LEFT);
    } catch (Exception $e) {
        return '—';
    }
}

// Process rows into array and calculate analytics
$tourRows           = [];
$totalLogCount      = 0;
$completedCount     = 0;
$inProgressCount    = 0;
$absentCount        = 0;
$totalWorkMinutes   = 0;
$distinctProjects   = [];
$distinctEmployees  = [];

while ($row = mysqli_fetch_assoc($attendanceResult)) {
    $punchIn  = $row['punchInTime'];
    $punchOut = $row['punchOutTime'];
    $rowDate  = $row['date'];

    $manHoursDisplay = calcManHours($punchIn, $punchOut, $rowDate);

    // Accumulate total minutes
    if ($manHoursDisplay !== '—') {
        $hp = explode(':', $manHoursDisplay);
        $totalWorkMinutes += ((int)$hp[0] * 60) + (int)($hp[1] ?? 0);
    }

    // Status logic
    if (!empty($punchIn) && $punchIn !== '00:00:00' && !empty($punchOut) && $punchOut !== '00:00:00') {
        $statusKey = 'completed';
        $completedCount++;
    } elseif (!empty($punchIn) && $punchIn !== '00:00:00') {
        $statusKey = 'inprogress';
        $inProgressCount++;
    } else {
        $statusKey = 'absent';
        $absentCount++;
    }

    $totalLogCount++;
    if (!empty($row['ProjectName'])) {
        $distinctProjects[$row['ProjectName']] = true;
    }
    if (!empty($row['fullName'])) {
        $distinctEmployees[$row['fullName']] = true;
    }

    require_once '../include/r2_config.php';
    
    $punchInImagePath = getSelfieUrl($row['punchInSelfie'], $punchInBasePath);
    $punchInImageExists = !empty($row['punchInSelfie']) && (strpos($row['punchInSelfie'], 'selfies/') === 0 || file_exists($punchInImagePath));

    $punchOutImagePath = getSelfieUrl($row['punchOutSelfie'], $punchOutBasePath);
    $punchOutImageExists = !empty($row['punchOutSelfie']) && (strpos($row['punchOutSelfie'], 'selfies/') === 0 || file_exists($punchOutImagePath));

    $row['_manHoursDisplay']     = $manHoursDisplay;
    $row['_statusKey']           = $statusKey;
    $row['_punchInImagePath']    = $punchInImagePath;
    $row['_punchOutImagePath']   = $punchOutImagePath;
    $row['_punchInImageExists']  = $punchInImageExists;
    $row['_punchOutImageExists'] = $punchOutImageExists;

    $tourRows[] = $row;
}

$workHours = floor($totalWorkMinutes / 60);
$workMins  = $totalWorkMinutes % 60;
$totalHoursFormatted = sprintf("%dh %02dm", $workHours, $workMins);
$uniqueProjectCount  = count($distinctProjects);
$uniqueEmpCount      = count($distinctEmployees);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Tour Attendance Analytics & Report</title>
    <?php include('../partials/_links.php'); ?>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css">

    <style>
        :root {
            --primary-bg: #f4f6fc;
            --card-bg: #ffffff;
            --brand-primary: #4338CA;
            --brand-primary-hover: #3730A3;
            --brand-secondary: #0284C7;
            --text-dark: #1E293B;
            --text-muted: #64748B;
            --border-color: #E2E8F0;
        }

        body {
            background-color: var(--primary-bg);
            color: var(--text-dark);
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
        }

        /* ── Hero Banner ── */
        .tour-banner {
            background: linear-gradient(135deg, #1E1B4B 0%, #312E81 50%, #4338CA 100%);
            border-radius: 16px;
            padding: 24px 28px;
            color: #ffffff;
            margin-bottom: 24px;
            box-shadow: 0 10px 25px -5px rgba(67, 56, 202, 0.3);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            position: relative;
            overflow: hidden;
        }

        .tour-banner::before {
            content: '';
            position: absolute;
            top: -40%;
            right: -10%;
            width: 320px;
            height: 320px;
            background: rgba(255, 255, 255, 0.07);
            border-radius: 50%;
            pointer-events: none;
        }

        .tour-banner-title {
            font-size: 1.5rem;
            font-weight: 800;
            margin: 0 0 6px 0;
            display: flex;
            align-items: center;
            gap: 12px;
            letter-spacing: -0.02em;
        }

        .tour-banner-sub {
            font-size: 0.88rem;
            color: rgba(255, 255, 255, 0.85);
            margin: 0;
        }

        .tour-banner-actions {
            display: flex;
            gap: 10px;
            z-index: 1;
        }

        .btn-banner-action {
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: #ffffff;
            font-weight: 600;
            font-size: 0.83rem;
            padding: 8px 18px;
            border-radius: 10px;
            backdrop-filter: blur(8px);
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-banner-action:hover {
            background: #ffffff;
            color: var(--brand-primary);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        /* ── Filter Panel ── */
        .filter-card {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid var(--border-color);
            box-shadow: 0 4px 16px rgba(0,0,0,0.03);
            padding: 24px;
            margin-bottom: 24px;
        }

        .filter-card-header {
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 8px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .filter-card label {
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 6px;
            display: block;
        }

        .filter-card .form-control,
        .filter-card .form-select {
            border: 1px solid #CBD5E1;
            border-radius: 10px;
            font-size: 0.875rem;
            height: 42px;
            color: var(--text-dark);
            background-color: #F8FAFC;
            transition: all 0.2s ease;
        }

        .filter-card .form-control:focus,
        .filter-card .form-select:focus {
            border-color: var(--brand-primary);
            background-color: #ffffff;
            box-shadow: 0 0 0 3px rgba(67, 56, 202, 0.12);
        }

        .btn-submit-filter {
            background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-primary-hover) 100%);
            color: #ffffff;
            font-weight: 600;
            font-size: 0.88rem;
            height: 42px;
            padding: 0 24px;
            border-radius: 10px;
            border: none;
            box-shadow: 0 4px 12px rgba(67, 56, 202, 0.25);
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
        }

        .btn-submit-filter:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(67, 56, 202, 0.35);
            color: #ffffff;
        }

        /* ── Analytics KPI Stat Grid ── */
        .analytics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .analytics-card {
            background: #ffffff;
            border-radius: 14px;
            border: 1px solid var(--border-color);
            padding: 18px 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
            display: flex;
            align-items: center;
            gap: 16px;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .analytics-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.06);
        }

        .analytics-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
        }

        .icon-indigo  { background: #EEF2FF; color: #4338CA; }
        .icon-emerald { background: #ECFDF5; color: #10B981; }
        .icon-amber   { background: #FEF3C7; color: #D97706; }
        .icon-sky     { background: #E0F2FE; color: #0284C7; }
        .icon-purple  { background: #F3E8FF; color: #7E22CE; }

        .analytics-label {
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 2px;
        }

        .analytics-value {
            font-size: 1.35rem;
            font-weight: 800;
            color: var(--text-dark);
            line-height: 1.2;
        }

        .analytics-subtext {
            font-size: 0.72rem;
            font-weight: 600;
            margin-top: 3px;
        }

        /* ── Report Card & Table ── */
        .report-card {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid var(--border-color);
            box-shadow: 0 4px 16px rgba(0,0,0,0.03);
            overflow: hidden;
            margin-bottom: 30px;
        }

        .report-card-header {
            padding: 18px 24px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .report-card-title {
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--text-dark);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .tour-table {
            width: 100% !important;
            margin-bottom: 0;
            border-collapse: separate;
            border-spacing: 0;
        }

        .tour-table thead tr {
            background: linear-gradient(135deg, #1E1B4B 0%, #4338CA 100%);
        }

        .tour-table thead th {
            color: #ffffff !important;
            font-size: 0.75rem !important;
            font-weight: 700 !important;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            padding: 14px 16px !important;
            border: none !important;
            text-align: center;
        }

        .tour-table tbody td {
            font-size: 0.85rem;
            vertical-align: middle;
            padding: 12px 16px;
            border-bottom: 1px solid #F1F5F9;
            text-align: center;
            color: #334155;
        }

        .tour-table tbody tr:hover {
            background-color: #F8FAFC;
        }

        /* Badges */
        .status-badge-success { background: #DCFCE7; color: #15803D; border: 1px solid #BBF7D0; }
        .status-badge-warning { background: #FEF3C7; color: #B45309; border: 1px solid #FDE68A; }
        .status-badge-danger  { background: #FFE4E6; color: #BE123C; border: 1px solid #FECDD3; }

        .btn-selfie-pill {
            background: #EEF2FF;
            border: 1px solid #C7D2FE;
            color: #4338CA;
            font-size: 0.72rem;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 12px;
            transition: all 0.15s ease;
            margin-top: 4px;
        }

        .btn-selfie-pill:hover {
            background: #4338CA;
            color: #ffffff;
            border-color: #4338CA;
            box-shadow: 0 2px 6px rgba(67, 56, 202, 0.3);
        }

        .project-tag-pill {
            background: #F1F5F9;
            color: #1E293B;
            font-size: 0.78rem;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 6px;
            border: 1px solid #CBD5E1;
            display: inline-block;
        }

        .hours-badge {
            background: #F1F5F9;
            color: #334155;
            font-weight: 700;
            font-size: 0.8rem;
            padding: 3px 10px;
            border-radius: 6px;
            border: 1px solid #E2E8F0;
        }

        /* Print Media Style */
        @media print {
            .tour-banner-actions, .filter-card, .sidebar, .navbar, .btn-selfie-pill, .dt-buttons {
                display: none !important;
            }
            .content-wrapper { padding: 0 !important; }
            .report-card { border: none !important; box-shadow: none !important; }
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

                <!-- ── Hero Banner ── -->
                <div class="tour-banner">
                    <div>
                        <h3 class="tour-banner-title"><i class="fas fa-plane-departure"></i> Tour Attendance Analytics</h3>
                        <p class="tour-banner-sub">Comprehensive field tour tracking, project logs, man-hours analytics, and selfie audits</p>
                    </div>
                    <div class="tour-banner-actions">
                        <button type="button" class="btn-banner-action" onclick="window.print()">
                            <i class="fas fa-print"></i> Print Report
                        </button>
                        <a href="tourAttendance_report.php" class="btn-banner-action">
                            <i class="fas fa-undo"></i> Reset Filters
                        </a>
                    </div>
                </div>

                <!-- ── Search Filter Card ── -->
                <div class="filter-card">
                    <div class="filter-card-header">
                        <i class="fas fa-filter text-primary"></i> Filter Tour Logs
                    </div>
                    <form method="GET" action="tourAttendance_report.php">
                        <div class="row g-3 align-items-end">

                            <div class="col-md-3">
                                <label><i class="fas fa-calendar-alt text-primary mr-1"></i> From Date</label>
                                <input type="date" name="fromDate" value="<?= htmlspecialchars($fromDate) ?>" class="form-control">
                            </div>

                            <div class="col-md-3">
                                <label><i class="fas fa-calendar-check text-info mr-1"></i> To Date</label>
                                <input type="date" name="toDate" value="<?= htmlspecialchars($toDate) ?>" class="form-control">
                            </div>

                            <div class="col-md-3">
                                <label><i class="fas fa-user-tag text-success mr-1"></i> Employee Type</label>
                                <select name="empType" class="form-select select2">
                                    <option value="">All Types</option>
                                    <?php while ($typeRow = mysqli_fetch_assoc($typeResult)): ?>
                                        <option value="<?= htmlspecialchars($typeRow['type']) ?>" <?= ($empType == $typeRow['type']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars(ucfirst($typeRow['type'])) ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label><i class="fas fa-search text-warning mr-1"></i> Employee Name</label>
                                <input type="text" name="empName" value="<?= htmlspecialchars($empName) ?>" class="form-control" placeholder="Search by name...">
                            </div>

                            <div class="col-md-3 mt-3">
                                <button type="submit" class="btn-submit-filter">
                                    <i class="fas fa-search"></i> Apply Filters
                                </button>
                            </div>

                        </div>
                    </form>
                </div>

                <!-- ── Analytics KPI Stat Grid ── -->
                <div class="analytics-grid">
                    <div class="analytics-card">
                        <div class="analytics-icon icon-indigo"><i class="fas fa-clipboard-list"></i></div>
                        <div>
                            <div class="analytics-label">Total Tour Logs</div>
                            <div class="analytics-value"><?= $totalLogCount ?></div>
                            <div class="analytics-subtext text-muted"><?= $uniqueEmpCount ?> Unique Employee<?= $uniqueEmpCount !== 1 ? 's' : '' ?></div>
                        </div>
                    </div>

                    <div class="analytics-card">
                        <div class="analytics-icon icon-emerald"><i class="fas fa-check-circle"></i></div>
                        <div>
                            <div class="analytics-label">Completed Tours</div>
                            <div class="analytics-value"><?= $completedCount ?></div>
                            <div class="analytics-subtext text-success"><i class="fas fa-check mr-1"></i>Punched Out</div>
                        </div>
                    </div>

                    <div class="analytics-card">
                        <div class="analytics-icon icon-amber"><i class="fas fa-running"></i></div>
                        <div>
                            <div class="analytics-label">In Progress</div>
                            <div class="analytics-value"><?= $inProgressCount ?></div>
                            <div class="analytics-subtext text-warning"><i class="fas fa-clock mr-1"></i>Active Shifts</div>
                        </div>
                    </div>

                    <div class="analytics-card">
                        <div class="analytics-icon icon-sky"><i class="fas fa-business-time"></i></div>
                        <div>
                            <div class="analytics-label">Total Tour Hours</div>
                            <div class="analytics-value"><?= $totalHoursFormatted ?></div>
                            <div class="analytics-subtext text-info">Logged Duration</div>
                        </div>
                    </div>

                    <div class="analytics-card">
                        <div class="analytics-icon icon-purple"><i class="fas fa-city"></i></div>
                        <div>
                            <div class="analytics-label">Projects Visited</div>
                            <div class="analytics-value"><?= $uniqueProjectCount ?></div>
                            <div class="analytics-subtext text-purple">Distinct Project Sites</div>
                        </div>
                    </div>
                </div>

                <!-- ── Tour Report Table Card ── -->
                <div class="report-card">
                    <div class="report-card-header">
                        <h4 class="report-card-title"><i class="fas fa-list-alt text-primary"></i> Tour Attendance Audit Logs</h4>
                        <span class="badge bg-light text-dark font-weight-bold" style="font-size:.82rem;">
                            <?= count($tourRows) ?> Total Entries
                        </span>
                    </div>
                    <div class="table-responsive p-3">
                        <table id="reportTable" class="table tour-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Employee</th>
                                    <th>Role / Type</th>
                                    <th>Project Site</th>
                                    <th>Tour Reason</th>
                                    <th>Punch In</th>
                                    <th>Punch Out</th>
                                    <th>Man Hours</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($tourRows as $row): 
                                $statusClass = 'status-badge-danger';
                                $statusLabel = 'Absent';
                                $statusIcon  = 'fa-times-circle';

                                if ($row['_statusKey'] === 'completed') {
                                    $statusClass = 'status-badge-success';
                                    $statusLabel = 'Completed';
                                    $statusIcon  = 'fa-check-circle';
                                } elseif ($row['_statusKey'] === 'inprogress') {
                                    $statusClass = 'status-badge-warning';
                                    $statusLabel = 'In Progress';
                                    $statusIcon  = 'fa-clock';
                                }
                            ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold"><?= date('d M Y', strtotime($row['date'])) ?></div>
                                        <small class="text-muted"><?= date('l', strtotime($row['date'])) ?></small>
                                    </td>
                                    <td>
                                        <div class="fw-bold"><?= htmlspecialchars($row['fullName']) ?></div>
                                        <?php if (!empty($row['emp_id']) || !empty($row['internId'])): ?>
                                            <small class="text-muted"><?= htmlspecialchars($row['emp_id'] ?: $row['internId']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border"><?= htmlspecialchars(ucfirst($row['employeeType'])) ?></span>
                                    </td>
                                    <td>
                                        <span class="project-tag-pill"><i class="fas fa-building text-primary mr-1"></i><?= htmlspecialchars($row['ProjectName']) ?></span>
                                    </td>
                                    <td>
                                        <div style="max-width: 200px; font-size:.82rem;" title="<?= htmlspecialchars($row['tourReason']) ?>">
                                            <?= htmlspecialchars($row['tourReason']) ?>
                                        </div>
                                    </td>

                                    <!-- Punch In -->
                                    <td>
                                        <div class="fw-bold"><?= (!empty($row['punchInTime']) && $row['punchInTime'] !== '00:00:00') ? date('H:i', strtotime($row['punchInTime'])) : '-' ?></div>
                                        <button type="button"
                                            class="btn btn-selfie-pill view-selfie"
                                            data-image="<?= $row['_punchInImageExists'] ? htmlspecialchars($row['_punchInImagePath']) : '' ?>"
                                            data-has-image="<?= $row['_punchInImageExists'] ? '1' : '0' ?>"
                                            data-title="Punch In Selfie — <?= htmlspecialchars($row['fullName']) ?> (<?= date('d M Y', strtotime($row['date'])) ?>)">
                                            <i class="fas fa-camera"></i> Selfie
                                        </button>
                                    </td>

                                    <!-- Punch Out -->
                                    <td>
                                        <div class="fw-bold"><?= (!empty($row['punchOutTime']) && $row['punchOutTime'] !== '00:00:00') ? date('H:i', strtotime($row['punchOutTime'])) : '-' ?></div>
                                        <button type="button"
                                            class="btn btn-selfie-pill view-selfie"
                                            data-image="<?= $row['_punchOutImageExists'] ? htmlspecialchars($row['_punchOutImagePath']) : '' ?>"
                                            data-has-image="<?= $row['_punchOutImageExists'] ? '1' : '0' ?>"
                                            data-title="Punch Out Selfie — <?= htmlspecialchars($row['fullName']) ?> (<?= date('d M Y', strtotime($row['date'])) ?>)">
                                            <i class="fas fa-camera"></i> Selfie
                                        </button>
                                    </td>

                                    <!-- Man Hours -->
                                    <td>
                                        <?php if ($row['_manHoursDisplay'] !== '—'): ?>
                                            <span class="hours-badge"><i class="fas fa-stopwatch text-primary mr-1"></i><?= htmlspecialchars($row['_manHoursDisplay']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Status -->
                                    <td>
                                        <span class="badge <?= $statusClass ?>" style="padding:5px 12px; border-radius:20px; font-size:.75rem;">
                                            <i class="fas <?= $statusIcon ?> mr-1"></i> <?= $statusLabel ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
            <?php include('../partials/_footer.php'); ?>
        </div>
    </div>
</div>

<!-- ── Selfie Modal ── -->
<div class="modal fade" id="selfieModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-dark text-white border-0 py-3">
                <h5 class="modal-title font-weight-bold" id="selfieTitle" style="font-size:1rem;">Selfie Verification Log</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4 text-center bg-light">
                <img id="selfieImage" src="" class="img-fluid rounded-3 shadow" alt="Punch Verification Selfie" style="max-height: 450px; object-fit: contain; display:none;">

                <div id="noImageMsg" class="text-center py-4" style="display:none;">
                    <i class="fas fa-image text-muted" style="font-size:3.5rem; opacity:0.4;"></i>
                    <p class="mt-3 text-muted font-weight-bold mb-0">No selfie image available for this log entry.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- DataTables + Export JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>

<script src="../js/off-canvas.js"></script>
<script src="../js/hoverable-collapse.js"></script>
<script src="../js/template.js"></script>
<script src="../js/settings.js"></script>
<script src="../js/select2.js"></script>

<script>
    let selfieModal;

    document.addEventListener('DOMContentLoaded', function () {
        const modalEl = document.getElementById('selfieModal');
        if (modalEl) {
            selfieModal = new bootstrap.Modal(modalEl);
        }
    });

    $(document).ready(function () {
        $('#reportTable').DataTable({
            order: [],
            dom: '<"d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2"B l f>rt<"d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2"i p>',
            buttons: [
                {
                    extend: 'excelHtml5',
                    text: '<i class="fas fa-file-excel mr-1"></i> Excel',
                    className: 'btn btn-success btn-sm font-weight-bold'
                },
                {
                    extend: 'pdfHtml5',
                    text: '<i class="fas fa-file-pdf mr-1"></i> PDF',
                    className: 'btn btn-danger btn-sm font-weight-bold'
                },
                {
                    extend: 'print',
                    text: '<i class="fas fa-print mr-1"></i> Print',
                    className: 'btn btn-info btn-sm text-white font-weight-bold'
                }
            ],
            language: {
                paginate: {
                    previous: '&laquo; Prev',
                    next: 'Next &raquo;'
                },
                lengthMenu: 'Show _MENU_ entries',
                search: 'Search Tour Logs:'
            },
            pagingType: 'simple_numbers'
        });
    });

    // ── Selfie modal handler ──
    $(document).on('click', '.view-selfie', function () {
        const hasImage  = $(this).data('has-image') == '1';
        const imagePath = $(this).data('image');
        const title     = $(this).data('title');

        $('#selfieTitle').text(title);

        if (hasImage && imagePath !== '') {
            $('#selfieImage').attr('src', imagePath).show();
            $('#noImageMsg').hide();
        } else {
            $('#selfieImage').attr('src', '').hide();
            $('#noImageMsg').show();
        }

        if (selfieModal) selfieModal.show();
    });
</script>

<?php
require_once('../include/sweetAlert.php');
if (isset($_SESSION['status'])) {
    $status = $_SESSION['status'];
    $msg    = $_SESSION['msg'];
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