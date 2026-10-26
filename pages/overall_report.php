<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}
if ($_SESSION['userRole'] !== 'admin' && $_SESSION['designation'] !== 'hr' && $_SESSION['userRole'] !== 'receptionist' && $_SESSION['designation'] !== 'receptionist' && $_SESSION['designation'] !== 'md') {
    echo "<script>
        alert('You cannot open this page. Please log in with the correct role.');
        window.location.href = 'login.php';
    </script>";
    exit();
}
include('../include/db_config.php');

$type   = $_REQUEST['types']  ?? '';
$mode   = $_REQUEST['mode']   ?? '';
$from   = $_REQUEST['from']   ?? '';
$to     = $_REQUEST['to']     ?? '';
$intern = $_REQUEST['intern'] ?? '';

if (($_SESSION['userRole'] ?? '') === 'receptionist') {
    $type = 'kinwat';
}

// Default date range to current month if not explicitly provided
if (empty($from) || empty($to)) {
    $start_date = date('Y-m-01');
    $end_date   = date('Y-m-d');
    $from       = $start_date;
    $to         = $end_date;
} else {
    $start_date = $from;
    $end_date   = $to;
}

$date1 = new DateTime($start_date);
$date2 = new DateTime($end_date);
$date2->modify('+1 day');
$daysDifference = $date1->diff($date2)->days;

$filter  = "AND date BETWEEN '$start_date' AND '$end_date'";
$filter1 = "";

if ($type == "Reset" || $type == "") {
    $office_val = "";
} else {
    $office_val = $type;
}

if ($office_val != "" && $mode == "") {
    $filter1 = "AND office='$office_val'";
} elseif ($office_val != "" && $mode != "" && $mode != "Reset" && $intern == "") {
    $filter1 = "AND office='$office_val' AND type='$mode'";
} elseif ($office_val != "" && $mode != "" && $mode != "Reset" && $intern != "") {
    $filter1 = "AND office='$office_val' AND type='$mode' AND userId='$intern'";
} elseif ($intern != "") {
    $filter1 = "AND userId='$intern'";
}

// ── Check if 'intimated' column exists in attendance table ──
$intimated_column_exists = false;
$check_column = mysqli_query($con, "SHOW COLUMNS FROM `attendance` LIKE 'intimated'");
if ($check_column && mysqli_num_rows($check_column) > 0) {
    $intimated_column_exists = true;
}

// ── Gather Analytical Data ──
$reportData = [];
$totalEmpCount = 0;
$totalPresentDays = 0;
$totalAbsentDays = 0;
$totalLateMarks = 0;
$totalIntimatedLate = 0;
$totalActualLate = 0;
$totalLeavesDays = 0;
$totalManSeconds = 0;

$query_emp = mysqli_query($con, "SELECT fullName, userId, office, type FROM `signup` WHERE fullName != 'admin' AND status = 'Active' $filter1 ORDER BY fullName") or die($con->error);

while ($emp = mysqli_fetch_assoc($query_emp)) {
    $uid = $emp['userId'];

    // Present days
    $q_present = mysqli_query($con, "SELECT COUNT(DISTINCT date) AS count FROM `attendance` WHERE userId = '$uid' AND status NOT LIKE '%Half Day%' $filter");
    $r_present = mysqli_fetch_assoc($q_present);
    $p_count_full = (int)($r_present['count'] ?? 0);

    // Half days
    $q_half = mysqli_query($con, "SELECT COUNT(DISTINCT date) AS count FROM `attendance` WHERE userId = '$uid' AND status LIKE '%Half Day%' $filter");
    $r_half = mysqli_fetch_assoc($q_half);
    $half_count = (int)($r_half['count'] ?? 0);
    
    $p_count = $p_count_full + ($half_count * 0.5);

    // Approved leaves
    $q_leaves = mysqli_query($con, "SELECT COUNT(*) AS count FROM `leaves` WHERE userId = '$uid' AND status = 'Approved' AND from_date BETWEEN '$start_date' AND '$end_date'");
    $r_leaves = mysqli_fetch_assoc($q_leaves);
    $l_count  = (int)($r_leaves['count'] ?? 0);

    // Late marks
    $q_late = mysqli_query($con, "SELECT COUNT(*) AS count FROM `attendance` WHERE lateMark = 'yes' AND userId = '$uid' $filter");
    $r_late = mysqli_fetch_assoc($q_late);
    $late_count = (int)($r_late['count'] ?? 0);

    $penaltyHalfDays = 0;
    if ($late_count >= 4) {
        $penaltyHalfDays = floor(($late_count - 2) / 2);
    }

    // Absent days calculation
    $absent_count = max(0, $daysDifference - $p_count - $l_count);
    $absent_count += ($penaltyHalfDays * 0.5);

    // Intimated late marks
    $intimated_count = 0;
    if ($intimated_column_exists) {
        $q_intimated = mysqli_query($con, "SELECT COUNT(*) AS count FROM `attendance` WHERE lateMark = 'yes' AND intimated = 'yes' AND userId = '$uid' $filter");
        $r_intimated = mysqli_fetch_assoc($q_intimated);
        $intimated_count = (int)($r_intimated['count'] ?? 0);
    }
    $actual_late = max(0, $late_count - $intimated_count);

    // Working hours
    $q_hours = mysqli_query($con, "SELECT SEC_TO_TIME(SUM(TIME_TO_SEC(manHours))) AS totalManHours, SUM(TIME_TO_SEC(manHours)) AS totalSec FROM `attendance` WHERE userId = '$uid' $filter");
    $r_hours = mysqli_fetch_assoc($q_hours);
    $man_hours_str = $r_hours['totalManHours'] ?? '00:00:00';
    $man_sec       = (int)($r_hours['totalSec'] ?? 0);

    // Attendance Rate % (excluding approved leaves from expected days)
    $expected_days = max(1, $daysDifference - $l_count);
    $att_rate      = min(100, round(($p_count / $expected_days) * 100, 1));

    $reportData[] = [
        'userId'          => $uid,
        'fullName'        => $emp['fullName'],
        'office'          => $emp['office'],
        'type'            => $emp['type'],
        'present'         => $p_count,
        'absent'          => $absent_count,
        'late'            => $late_count,
        'intimated_late'  => $intimated_count,
        'actual_late'     => $actual_late,
        'leaves'          => $l_count,
        'man_hours'       => $man_hours_str,
        'man_seconds'     => $man_sec,
        'att_rate'        => $att_rate
    ];

    $totalEmpCount++;
    $totalPresentDays  += $p_count;
    $totalAbsentDays   += $absent_count;
    $totalLateMarks    += $late_count;
    $totalIntimatedLate+= $intimated_count;
    $totalActualLate   += $actual_late;
    $totalLeavesDays   += $l_count;
    $totalManSeconds   += $man_sec;
}

// Summary Metrics
$overallExpectedDays = max(1, $totalPresentDays + $totalAbsentDays);
$overallAvgAttRate   = round(($totalPresentDays / $overallExpectedDays) * 100, 1);
$totalManHoursFormatted = sprintf('%d hrs %d mins', floor($totalManSeconds / 3600), floor(($totalManSeconds % 3600) / 60));

// Sort top late comers for chart
$lateLeaderboard = $reportData;
usort($lateLeaderboard, function($a, $b) {
    return $b['late'] <=> $a['late'];
});
$topLateData = array_slice($lateLeaderboard, 0, 8);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>SETTribe · Attendance Analytics Dashboard</title>
    <?php include('../partials/_links.php'); ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/4.5.2/css/bootstrap.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap4.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4f46e5;
            --primary-dark: #3730a3;
            --primary-light: #eef2ff;
            --success: #10b981;
            --success-light: #ecfdf5;
            --warning: #f59e0b;
            --warning-light: #fffbeb;
            --danger: #ef4444;
            --danger-light: #fef2f2;
            --info: #06b6d4;
            --info-light: #ecfeff;
            --purple: #8b5cf6;
            --purple-light: #f5f3ff;
            --dark: #0f172a;
            --slate: #475569;
            --light-bg: #f8fafc;
            --border: #e2e8f0;
            --radius-lg: 14px;
            --radius-md: 10px;
        }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: #f1f5f9;
            color: #1e293b;
        }

        /* ── Page Header ── */
        .analytics-header-card {
            background: linear-gradient(135deg, #1e1b4b 0%, #312e81 50%, #4338ca 100%);
            border-radius: var(--radius-lg);
            padding: 24px 28px;
            color: #ffffff;
            margin-bottom: 24px;
            box-shadow: 0 10px 25px -5px rgba(49, 46, 129, 0.3);
            position: relative;
            overflow: hidden;
        }
        .analytics-header-card::after {
            content: '';
            position: absolute;
            right: -50px;
            top: -50px;
            width: 220px;
            height: 220px;
            background: rgba(255, 255, 255, 0.06);
            border-radius: 50%;
            pointer-events: none;
        }
        .header-title-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.76rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        /* ── Filter Card ── */
        .filter-card {
            background: #ffffff;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            padding: 20px 24px;
            margin-bottom: 24px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.03);
        }
        .filter-label {
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--slate);
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-bottom: 6px;
        }
        .preset-btn {
            font-size: 0.73rem;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 6px;
            border: 1px solid #cbd5e1;
            background: #f8fafc;
            color: #475569;
            cursor: pointer;
            transition: all 0.2s;
        }
        .preset-btn:hover {
            background: var(--primary-light);
            color: var(--primary);
            border-color: #c7d2fe;
        }

        /* ── KPI Grid ── */
        .kpi-card {
            background: #ffffff;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            padding: 18px 20px;
            height: 100%;
            box-shadow: 0 4px 14px rgba(0,0,0,0.03);
            transition: all 0.25s ease;
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .kpi-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 24px rgba(0,0,0,0.06);
            border-color: #cbd5e1;
        }
        .kpi-card::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
        }
        .kpi-blue::before    { background: var(--primary); }
        .kpi-green::before   { background: var(--success); }
        .kpi-purple::before  { background: var(--purple); }
        .kpi-cyan::before    { background: var(--info); }
        .kpi-amber::before   { background: var(--warning); }

        .kpi-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        .kpi-title {
            font-size: 0.78rem;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        .kpi-icon-wrap {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }
        .icon-blue   { background: var(--primary-light); color: var(--primary); }
        .icon-green  { background: var(--success-light); color: var(--success); }
        .icon-purple { background: var(--purple-light); color: var(--purple); }
        .icon-cyan   { background: var(--info-light); color: var(--info); }
        .icon-amber  { background: var(--warning-light); color: var(--warning); }

        .kpi-value {
            font-size: 1.7rem;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.1;
            margin-bottom: 6px;
        }
        .kpi-sub {
            font-size: 0.75rem;
            color: #64748b;
            font-weight: 600;
        }

        /* ── Charts Card ── */
        .chart-card {
            background: #ffffff;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            padding: 20px 22px;
            height: 100%;
            box-shadow: 0 4px 14px rgba(0,0,0,0.03);
        }
        .chart-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 12px;
            margin-bottom: 16px;
        }
        .chart-card-title {
            font-size: 0.95rem;
            font-weight: 800;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* ── Table Styling ── */
        .analytics-table-card {
            background: #ffffff;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            padding: 24px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.03);
            margin-bottom: 30px;
        }
        
        #employee_data {
            width: 100% !important;
            border-collapse: separate;
            border-spacing: 0;
        }
        #employee_data thead th {
            background: linear-gradient(135deg, #1e293b, #334155);
            color: #ffffff;
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            padding: 12px 14px;
            border: none;
            text-align: center;
        }
        #employee_data thead th:first-child { border-top-left-radius: 8px; }
        #employee_data thead th:last-child { border-top-right-radius: 8px; }

        #employee_data tbody td {
            font-size: 0.84rem;
            padding: 12px 14px;
            vertical-align: middle;
            border-bottom: 1px solid #f1f5f9;
            text-align: center;
            color: #334155;
        }
        #employee_data tbody tr:hover {
            background-color: #f8fafc;
            transition: background-color 0.2s;
        }

        /* ── Status Badges ── */
        .att-badge-num {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 700;
        }
        .badge-p { background: var(--success-light); color: var(--success); border: 1px solid #a7f3d0; }
        .badge-a { background: var(--danger-light); color: var(--danger); border: 1px solid #fecaca; }
        .badge-l { background: var(--purple-light); color: var(--purple); border: 1px solid #ddd6fe; }
        .badge-il { background: var(--warning-light); color: #d97706; border: 1px solid #fef3c7; }
        .badge-al { background: #fff1f2; color: #e11d48; border: 1px solid #fecdd3; }
        .badge-lv { background: var(--info-light); color: var(--info); border: 1px solid #a5f3fc; }
        .badge-hrs { background: var(--primary-light); color: var(--primary); border: 1px solid #c7d2fe; font-family: monospace; font-size: 0.82rem; }

        /* ── Health Score Pill ── */
        .health-score-wrap {
            display: inline-flex;
            flex-direction: column;
            align-items: center;
            gap: 3px;
        }
        .health-badge {
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.74rem;
            font-weight: 800;
        }
        .health-high   { background: #dcfce7; color: #15803d; }
        .health-med    { background: #fef3c7; color: #b45309; }
        .health-low    { background: #fee2e2; color: #b91c1c; }

        .health-progress-bar {
            width: 50px;
            height: 4px;
            background: #e2e8f0;
            border-radius: 2px;
            overflow: hidden;
        }
        .health-progress-fill {
            height: 100%;
            border-radius: 2px;
        }

        .btn-gradient-primary {
            background: linear-gradient(135deg, #4f46e5 0%, #3730a3 100%);
            color: #ffffff;
            border: none;
            font-weight: 700;
            padding: 8px 18px;
            border-radius: var(--radius-md);
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);
            transition: all 0.2s;
        }
        .btn-gradient-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(79, 70, 229, 0.35);
            color: #ffffff;
        }
        .btn-gradient-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: #ffffff;
            border: none;
            font-weight: 700;
            padding: 8px 18px;
            border-radius: var(--radius-md);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25);
            transition: all 0.2s;
        }
        .btn-gradient-success:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(16, 185, 129, 0.35);
            color: #ffffff;
        }
    </style>
</head>

<body>
<div class="container-scroller">

    <?php include('../partials/_navbar.php'); ?>

    <div class="container-fluid page-body-wrapper">

        <?php include('../partials/_sidebar.php'); ?>

        <div class="main-panel">
            <div class="content-wrapper">

                <!-- ── HEADER BANNER ── -->
                <div class="analytics-header-card">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                        <div>
                            <div class="header-title-badge">
                                <i class="fas fa-chart-line"></i> Executive Attendance Analytics
                            </div>
                            <h2 style="font-size: 1.6rem; font-weight: 800; margin-bottom: 4px;">
                                Overall Attendance &amp; Late Mark Report
                            </h2>
                            <p style="font-size: 0.85rem; opacity: 0.85; margin: 0;">
                                Comprehensive team attendance metrics, punctuality analysis, leave tracking, and man-hour logs.
                            </p>
                        </div>
                        <div class="text-right">
                            <span class="badge badge-light px-3 py-2" style="font-size:0.82rem; font-weight:700; border-radius:20px;">
                                <i class="fas fa-calendar-alt text-primary mr-1"></i>
                                <?= date('d M Y', strtotime($start_date)) ?> &nbsp;—&nbsp; <?= date('d M Y', strtotime($end_date)) ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- ── FILTER PANEL ── -->
                <div class="filter-card">
                    <form action="overall_report.php" method="POST" id="filterForm">
                        <div class="row align-items-end">
                            <div class="col-lg-3 col-md-6 mb-3 mb-lg-0">
                                <div class="d-flex align-items-center justify-content-between mb-1">
                                    <label class="filter-label mb-0"><i class="fas fa-calendar-day text-primary mr-1"></i> Date Range</label>
                                    <div class="d-flex gap-1">
                                        <button type="button" class="preset-btn" onclick="setPreset('this_month')">This Month</button>
                                        <button type="button" class="preset-btn" onclick="setPreset('last_30')">30 Days</button>
                                    </div>
                                </div>
                                <div class="input-group">
                                    <input type="date" class="form-control" name="from" id="fromDate" required value="<?= htmlspecialchars($from); ?>" style="border-radius:8px 0 0 8px;">
                                    <input type="date" class="form-control" name="to" id="toDate" required value="<?= htmlspecialchars($to); ?>" style="border-radius:0 8px 8px 0;">
                                </div>
                            </div>

                            <div class="col-lg-3 col-md-6 mb-3 mb-lg-0">
                                <label class="filter-label"><i class="fas fa-building text-info mr-1"></i> Office Location</label>
                                <select class="form-control select2" id="projectId2" name="types" onchange="fetch_data()" style="width:100%; border-radius:8px;">
                                    <?php if (($_SESSION['userRole'] ?? '') === 'receptionist'): ?>
                                        <option value="kinwat" selected>Settribe Kinwat</option>
                                    <?php else: ?>
                                        <option value="" <?= !$type ? 'selected' : '' ?>>All Offices</option>
                                        <option value="kinwat" <?= ($type == 'kinwat') ? 'selected' : ''; ?>>Settribe Kinwat</option>
                                        <option value="pune"   <?= ($type == 'pune')   ? 'selected' : ''; ?>>Settribe Pune</option>
                                    <?php endif; ?>
                                </select>
                            </div>

                            <div class="col-lg-2 col-md-6 mb-3 mb-lg-0">
                                <label class="filter-label"><i class="fas fa-laptop-house text-purple mr-1"></i> Mode</label>
                                <select class="form-control select2" id="projectId4" name="mode" onchange="fetch_data()" style="width:100%; border-radius:8px;">
                                    <option value="" <?= !$mode || $mode=='Reset' ? 'selected' : '' ?>>All Modes</option>
                                    <option value="Online"  <?= ($mode == 'Online')  ? 'selected' : ''; ?>>Online</option>
                                    <option value="Offline" <?= ($mode == 'Offline') ? 'selected' : ''; ?>>Offline</option>
                                </select>
                            </div>

                            <div class="col-lg-2 col-md-6 mb-3 mb-lg-0">
                                <label class="filter-label"><i class="fas fa-user-friends text-success mr-1"></i> Employee</label>
                                <select class="form-control select2" id="empId" name="intern" style="width:100%; border-radius:8px;">
                                    <option value="" selected>All Employees</option>
                                    <?php
                                    $employee_conditions = [];
                                    if (!empty($type) && $type != "Reset") $employee_conditions[] = "office='$type'";
                                    if (!empty($mode) && $mode != 'Reset') $employee_conditions[] = "type='$mode'";
                                    $employee_filter = !empty($employee_conditions) ? "AND " . implode(" AND ", $employee_conditions) : "";

                                    $query1 = mysqli_query($con, "SELECT userId, fullName FROM signup WHERE designation != 'admin' AND status = 'Active' $employee_filter ORDER BY fullName");
                                    while ($fetch = mysqli_fetch_assoc($query1)) {
                                    ?>
                                        <option value="<?= $fetch['userId']; ?>" <?= ($intern == $fetch['userId']) ? 'selected' : ''; ?>>
                                            <?= htmlspecialchars($fetch['fullName']); ?>
                                        </option>
                                    <?php } ?>
                                </select>
                            </div>

                            <div class="col-lg-2 col-md-12 text-right">
                                <button type="submit" name="show" id="show" class="btn btn-gradient-primary btn-block">
                                    <i class="fas fa-filter mr-1"></i> Filter
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- ── KPI METRICS CARDS ── -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6 mb-3 mb-xl-0">
                        <div class="kpi-card kpi-blue">
                            <div class="kpi-header">
                                <span class="kpi-title">Team in Scope</span>
                                <div class="kpi-icon-wrap icon-blue"><i class="fas fa-users"></i></div>
                            </div>
                            <div>
                                <div class="kpi-value"><?= $totalEmpCount ?></div>
                                <div class="kpi-sub">
                                    <span class="badge badge-light border text-dark" style="font-size:.7rem;">
                                        <?= $daysDifference ?> Calendar Days
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-3 mb-xl-0">
                        <div class="kpi-card kpi-green">
                            <div class="kpi-header">
                                <span class="kpi-title">Avg. Attendance Rate</span>
                                <div class="kpi-icon-wrap icon-green"><i class="fas fa-check-circle"></i></div>
                            </div>
                            <div>
                                <div class="kpi-value"><?= $overallAvgAttRate ?>%</div>
                                <div class="kpi-sub d-flex align-items-center justify-content-between">
                                    <span>Total Present: <strong><?= $totalPresentDays ?></strong></span>
                                    <span>Absent: <strong><?= $totalAbsentDays ?></strong></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-3 mb-xl-0">
                        <div class="kpi-card kpi-purple">
                            <div class="kpi-header">
                                <span class="kpi-title">Total Late Marks</span>
                                <div class="kpi-icon-wrap icon-purple"><i class="fas fa-clock"></i></div>
                            </div>
                            <div>
                                <div class="kpi-value"><?= $totalLateMarks ?></div>
                                <div class="kpi-sub d-flex align-items-center gap-2">
                                    <span class="badge badge-warning text-dark px-2" style="font-size:.7rem;">
                                        Intimated: <?= $totalIntimatedLate ?>
                                    </span>
                                    <span class="badge badge-danger px-2" style="font-size:.7rem;">
                                        Actual: <?= $totalActualLate ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-3 mb-xl-0">
                        <div class="kpi-card kpi-cyan">
                            <div class="kpi-header">
                                <span class="kpi-title">Productive Hours</span>
                                <div class="kpi-icon-wrap icon-cyan"><i class="fas fa-business-time"></i></div>
                            </div>
                            <div>
                                <div class="kpi-value" style="font-size:1.45rem; font-family:monospace;"><?= $totalManHoursFormatted ?></div>
                                <div class="kpi-sub">
                                    <span>Approved Leaves: <strong><?= $totalLeavesDays ?> days</strong></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── CHARTS SECTION ── -->
                <div class="row mb-4">
                    <!-- Chart 1: Attendance Distribution -->
                    <div class="col-lg-5 mb-3 mb-lg-0">
                        <div class="chart-card">
                            <div class="chart-card-header">
                                <div class="chart-card-title">
                                    <i class="fas fa-chart-pie text-primary"></i> Attendance Distribution
                                </div>
                                <span class="badge badge-light border" style="font-size:.72rem;">Proportional Split</span>
                            </div>
                            <div style="position: relative; height: 260px;">
                                <canvas id="attendanceDoughnutChart"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Chart 2: Top Late Marks Leaderboard -->
                    <div class="col-lg-7">
                        <div class="chart-card">
                            <div class="chart-card-header">
                                <div class="chart-card-title">
                                    <i class="fas fa-user-clock text-purple"></i> Late Mark Breakdown (Intimated vs Actual)
                                </div>
                                <span class="badge badge-light border" style="font-size:.72rem;">Highest Late-Comers</span>
                            </div>
                            <div style="position: relative; height: 260px;">
                                <canvas id="lateBarChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── DETAILED TABLE CARD ── -->
                <div class="analytics-table-card">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                        <div>
                            <h5 style="font-size: 1.1rem; font-weight: 800; color: #0f172a; margin: 0;">
                                <i class="fas fa-table text-primary mr-1"></i> Detailed Attendance &amp; Punctuality Report
                            </h5>
                            <p style="font-size: 0.8rem; color: #64748b; margin: 2px 0 0 0;">
                                Showing performance metrics for <?= count($reportData) ?> employees
                            </p>
                        </div>
                        <div>
                            <button type="button" onclick="exportToExcel('employee_data1', 'Attendance_Analytics_Report')" class="btn btn-gradient-success">
                                <i class="fas fa-file-excel mr-1"></i> Export to Excel
                            </button>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table" id="employee_data">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th style="text-align:left;">Employee Name</th>
                                    <th>Health Score</th>
                                    <th>Present</th>
                                    <th>Absent</th>
                                    <th>Total Late</th>
                                    <th>Intimated Late</th>
                                    <th>Actual Late</th>
                                    <th>Leaves</th>
                                    <th>Working Hours</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $sr = 1;
                                foreach ($reportData as $row):
                                    $rate = $row['att_rate'];
                                    $healthClass = ($rate >= 90) ? 'health-high' : (($rate >= 75) ? 'health-med' : 'health-low');
                                    $progressBg  = ($rate >= 90) ? '#10b981' : (($rate >= 75) ? '#f59e0b' : '#ef4444');
                                ?>
                                    <tr>
                                        <td class="font-weight-bold text-muted"><?= $sr++; ?></td>
                                        <td style="text-align:left;">
                                            <strong style="color:#0f172a; font-size:.88rem;"><?= htmlspecialchars((string)($row['fullName'] ?? '')); ?></strong>
                                            <?php if (!empty($row['office'])): ?>
                                                <div style="font-size:.72rem; color:#64748b; text-transform:capitalize;">
                                                    <i class="fas fa-map-marker-alt text-danger mr-1" style="font-size:.65rem;"></i><?= htmlspecialchars((string)($row['office'] ?? '')); ?>
                                                    <?php if (!empty($row['type'])): ?> · <?= htmlspecialchars((string)$row['type']); ?><?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="health-score-wrap">
                                                <span class="health-badge <?= $healthClass ?>"><?= $rate ?>%</span>
                                                <div class="health-progress-bar">
                                                    <div class="health-progress-fill" style="width: <?= $rate ?>%; background: <?= $progressBg ?>;"></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td><span class="att-badge-num badge-p"><?= $row['present']; ?></span></td>
                                        <td><span class="att-badge-num badge-a"><?= $row['absent']; ?></span></td>
                                        <td><span class="att-badge-num badge-l"><?= $row['late']; ?></span></td>
                                        <td><span class="att-badge-num badge-il"><?= $row['intimated_late']; ?></span></td>
                                        <td><span class="att-badge-num badge-al"><?= $row['actual_late']; ?></span></td>
                                        <td><span class="att-badge-num badge-lv"><?= $row['leaves']; ?></span></td>
                                        <td><span class="att-badge-num badge-hrs"><?= htmlspecialchars((string)($row['man_hours'] ?? '00:00:00')); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Hidden Table for Excel Export -->
                <table class="table table-bordered" border="1" id="employee_data1" style="display:none">
                    <thead>
                        <tr>
                            <th colspan="10" style="text-align:center; font-size:14pt; font-weight:bold;">
                                Attendance &amp; Late Mark Report OF SETTribe (<?= htmlspecialchars($start_date) ?> To <?= htmlspecialchars($end_date) ?>)
                            </th>
                        </tr>
                        <tr>
                            <th>Sr.No</th>
                            <th>Intern / Employee Name</th>
                            <th>Attendance Rate (%)</th>
                            <th>Present</th>
                            <th>Absent</th>
                            <th>Total Late Mark</th>
                            <th>Intimated Late Mark</th>
                            <th>Actual Late Mark</th>
                            <th>Leaves</th>
                            <th>Working Hours</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $sr2 = 1;
                        foreach ($reportData as $row):
                        ?>
                            <tr>
                                <td><?= $sr2++; ?></td>
                                <td><?= htmlspecialchars($row['fullName']); ?></td>
                                <td><?= $row['att_rate']; ?>%</td>
                                <td><?= $row['present']; ?></td>
                                <td><?= $row['absent']; ?></td>
                                <td><?= $row['late']; ?></td>
                                <td><?= $row['intimated_late']; ?></td>
                                <td><?= $row['actual_late']; ?></td>
                                <td><?= $row['leaves']; ?></td>
                                <td><?= htmlspecialchars($row['man_hours']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

            </div>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="../vendors/js/vendor.bundle.base.js"></script>
<script src="../vendors/chart.js/Chart.min.js"></script>
<script src="../vendors/datatables.net/jquery.dataTables.js"></script>
<script src="../vendors/datatables.net-bs4/dataTables.bootstrap4.js"></script>
<script src="../js/off-canvas.js"></script>
<script src="../js/hoverable-collapse.js"></script>
<script src="../js/template.js"></script>
<script src="../js/settings.js"></script>
<script src="../vendors/select2/select2.min.js"></script>
<script src="../js/select2.js"></script>
<script type="text/javascript" src="https://unpkg.com/xlsx@0.15.1/dist/xlsx.full.min.js"></script>

<script>
    document.addEventListener("DOMContentLoaded", function () {
        let today    = new Date().toISOString().split('T')[0];
        let fromDate = document.getElementById("fromDate");
        let toDate   = document.getElementById("toDate");

        toDate.setAttribute("max", today);
        fromDate.addEventListener("change", function () { toDate.min   = fromDate.value; });
        toDate.addEventListener("change",   function () { fromDate.max = toDate.value; });
        if (fromDate.value) toDate.min   = fromDate.value;
        if (toDate.value)   fromDate.max = toDate.value;

        // Initialize DataTable
        $('#employee_data').DataTable({
            "pageLength": 25,
            "order": [[ 2, "desc" ]], // Default sort by Health Score %
            "language": {
                "search": "<i class='fas fa-search text-muted mr-1'></i> Search Team Member:"
            }
        });

        // Initialize Doughnut Chart
        var ctxDoughnut = document.getElementById('attendanceDoughnutChart').getContext('2d');
        new Chart(ctxDoughnut, {
            type: 'doughnut',
            data: {
                labels: ['Present Days', 'Absent Days', 'Approved Leaves', 'Intimated Late', 'Actual Late'],
                datasets: [{
                    data: [
                        <?= $totalPresentDays ?>,
                        <?= $totalAbsentDays ?>,
                        <?= $totalLeavesDays ?>,
                        <?= $totalIntimatedLate ?>,
                        <?= $totalActualLate ?>
                    ],
                    backgroundColor: [
                        '#10b981', // Present (Green)
                        '#ef4444', // Absent (Red)
                        '#06b6d4', // Leaves (Cyan)
                        '#f59e0b', // Intimated Late (Amber)
                        '#8b5cf6'  // Actual Late (Purple)
                    ],
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                legend: {
                    position: 'right',
                    labels: {
                        boxWidth: 12,
                        fontFamily: "'Plus Jakarta Sans', sans-serif",
                        fontSize: 11
                    }
                },
                tooltips: {
                    cornerRadius: 8,
                    bodyFontFamily: "'Plus Jakarta Sans', sans-serif"
                }
            }
        });

        // Initialize Top Late Marks Bar Chart
        <?php
        $topNames = array_map(fn($item) => $item['fullName'], $topLateData);
        $topIntimated = array_map(fn($item) => $item['intimated_late'], $topLateData);
        $topActual = array_map(fn($item) => $item['actual_late'], $topLateData);
        ?>
        var ctxBar = document.getElementById('lateBarChart').getContext('2d');
        new Chart(ctxBar, {
            type: 'bar',
            data: {
                labels: <?= json_encode($topNames) ?>,
                datasets: [
                    {
                        label: 'Intimated Late',
                        data: <?= json_encode($topIntimated) ?>,
                        backgroundColor: '#f59e0b',
                        borderRadius: 4
                    },
                    {
                        label: 'Actual (Unintimated) Late',
                        data: <?= json_encode($topActual) ?>,
                        backgroundColor: '#ef4444',
                        borderRadius: 4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                legend: {
                    position: 'top',
                    labels: {
                        boxWidth: 12,
                        fontFamily: "'Plus Jakarta Sans', sans-serif",
                        fontSize: 11
                    }
                },
                scales: {
                    xAxes: [{
                        ticks: {
                            fontFamily: "'Plus Jakarta Sans', sans-serif",
                            fontSize: 10
                        },
                        gridLines: { display: false }
                    }],
                    yAxes: [{
                        ticks: {
                            beginAtZero: true,
                            stepSize: 1,
                            fontFamily: "'Plus Jakarta Sans', sans-serif",
                            fontSize: 10
                        },
                        gridLines: { color: '#f1f5f9' }
                    }]
                }
            }
        });
    });

    function setPreset(type) {
        let today = new Date();
        let fromInput = document.getElementById("fromDate");
        let toInput   = document.getElementById("toDate");

        if (type === 'this_month') {
            let firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
            fromInput.value = firstDay.toISOString().split('T')[0];
            toInput.value   = today.toISOString().split('T')[0];
        } else if (type === 'last_30') {
            let past30 = new Date();
            past30.setDate(today.getDate() - 30);
            fromInput.value = past30.toISOString().split('T')[0];
            toInput.value   = today.toISOString().split('T')[0];
        }
    }

    function fetch_data() {
        var adm = document.getElementById('projectId2').value;
        var avs = document.getElementById('projectId4').value;

        $.ajax({
            url: 'select_office_emp.php',
            type: 'POST',
            data: { adm: adm, avs: avs },
            success: function (data) {
                document.getElementById('empId').innerHTML = data;
            }
        });
    }

    function exportToExcel(tableID, filename) {
        filename = (filename || 'Attendance_Analytics_Report') + '.xls';
        var dataFileType  = 'application/vnd.ms-excel';
        var tableSelect   = document.getElementById('employee_data1');
        var tableHTMLData = tableSelect.outerHTML.replace(/ /g, '%20');
        var downloadurl   = document.createElement("a");
        document.body.appendChild(downloadurl);

        if (navigator.msSaveOrOpenBlob) {
            var blob = new Blob(['\ufeff', tableHTMLData], { type: dataFileType });
            navigator.msSaveOrOpenBlob(blob, filename);
        } else {
            downloadurl.href     = 'data:' + dataFileType + ', ' + tableHTMLData;
            downloadurl.download = filename;
            downloadurl.click();
        }
    }
</script>

<?php
require_once('../include/sweetAlert.php');
if (isset($_SESSION['msg'])) {
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