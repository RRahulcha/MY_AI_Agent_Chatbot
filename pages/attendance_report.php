<?php
session_start();
include('../include/db_config.php');
$punchInBasePath  = '../uploads/punchIn/';
$punchOutBasePath = '../uploads/punchOut/';

if (!isset($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

require_once '../include/r2_config.php';

if (!function_exists('getDatesFromRange')) {
    function getDatesFromRange($start, $end, $format = 'Y-m-d') {
        $array    = [];
        $interval = new DateInterval('P1D');
        $realEnd  = new DateTime($end);
        $realEnd->add($interval);
        $period = new DatePeriod(new DateTime($start), $interval, $realEnd);
        foreach ($period as $date) { $array[] = $date->format($format); }
        return $array;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Attendance Analytics & Report</title>
    <?php include('../partials/_links.php'); ?>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css">

    <style>
        :root {
            --primary-bg: #f4f6fc;
            --card-bg: #ffffff;
            --brand-primary: #4F46E5;
            --brand-primary-hover: #4338CA;
            --brand-secondary: #0EA5E9;
            --text-dark: #1E293B;
            --text-muted: #64748B;
            --border-color: #E2E8F0;
        }

        body {
            background-color: var(--primary-bg);
            color: var(--text-dark);
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
        }

        /* ── Page Hero Banner ── */
        .att-banner {
            background: linear-gradient(135deg, #3730A3 0%, #4F46E5 50%, #0EA5E9 100%);
            border-radius: 16px;
            padding: 24px 28px;
            color: #ffffff;
            margin-bottom: 24px;
            box-shadow: 0 10px 25px -5px rgba(79, 70, 229, 0.25);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            position: relative;
            overflow: hidden;
        }

        .att-banner::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 300px;
            height: 300px;
            background: rgba(255, 255, 255, 0.08);
            border-radius: 50%;
            pointer-events: none;
        }

        .att-banner-title {
            font-size: 1.5rem;
            font-weight: 800;
            margin: 0 0 6px 0;
            display: flex;
            align-items: center;
            gap: 10px;
            letter-spacing: -0.02em;
        }

        .att-banner-sub {
            font-size: 0.88rem;
            color: rgba(255, 255, 255, 0.85);
            margin: 0;
        }

        .att-banner-actions {
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
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        /* ── Filter Card ── */
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
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.12);
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
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
        }

        .btn-submit-filter:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(79, 70, 229, 0.35);
            color: #ffffff;
        }

        /* ── KPI Analytics Grid ── */
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

        .icon-indigo { background: #EEF2FF; color: #4F46E5; }
        .icon-emerald { background: #ECFDF5; color: #10B981; }
        .icon-rose    { background: #FFE4E6; color: #F43F5E; }
        .icon-sky     { background: #E0F2FE; color: #0284C7; }
        .icon-amber   { background: #FEF3C7; color: #D97706; }

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

        /* ── Profile Summary Bar ── */
        .profile-summary-bar {
            background: #ffffff;
            border-radius: 14px;
            border: 1px solid var(--border-color);
            padding: 16px 20px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }

        .user-pill-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .user-avatar-circle {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4F46E5 0%, #0EA5E9 100%);
            color: #ffffff;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            box-shadow: 0 4px 10px rgba(79, 70, 229, 0.2);
        }

        .user-name-title {
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--text-dark);
            margin: 0;
        }

        .user-meta-sub {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin: 0;
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .tag-meta-pill {
            background: #F1F5F9;
            color: #475569;
            font-size: 0.72rem;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 6px;
            border: 1px solid #E2E8F0;
        }

        /* ── Table Card ── */
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

        .att-table {
            width: 100% !important;
            margin-bottom: 0;
            border-collapse: separate;
            border-spacing: 0;
        }

        .att-table thead tr {
            background: linear-gradient(135deg, #3730A3 0%, #4F46E5 100%);
        }

        .att-table thead th {
            color: #ffffff !important;
            font-size: 0.75rem !important;
            font-weight: 700 !important;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            padding: 14px 16px !important;
            border: none !important;
            text-align: center;
        }

        .att-table tbody td {
            font-size: 0.85rem;
            vertical-align: middle;
            padding: 12px 16px;
            border-bottom: 1px solid #F1F5F9;
            text-align: center;
            color: #334155;
        }

        .att-table tbody tr:hover {
            background-color: #F8FAFC;
        }

        /* Status Badges */
        .att-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        .status-present { background: #DCFCE7; color: #15803D; border: 1px solid #BBF7D0; }
        .status-absent  { background: #FFE4E6; color: #BE123C; border: 1px solid #FECDD3; }
        .status-tour    { background: #E0F2FE; color: #0369A1; border: 1px solid #BAE6FD; }
        .status-holiday { background: #FEF3C7; color: #B45309; border: 1px solid #FDE68A; }
        .status-leave   { background: #F3E8FF; color: #7E22CE; border: 1px solid #E9D5FF; }

        /* Selfie Pill Button */
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
            background: #4F46E5;
            color: #ffffff;
            border-color: #4F46E5;
            box-shadow: 0 2px 6px rgba(79, 70, 229, 0.3);
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

        /* ── Select2 Overrides ── */
        .select2-container--default .select2-selection--single {
            height: 42px !important;
            border: 1px solid #CBD5E1 !important;
            border-radius: 10px !important;
            background-color: #F8FAFC !important;
            display: flex !important;
            align-items: center !important;
        }

        .select2-container--default .select2-selection--single .select2-selection__rendered {
            color: var(--text-dark) !important;
            font-size: 0.875rem !important;
            padding-left: 12px !important;
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 40px !important;
        }

        /* Print Media Style */
        @media print {
            .att-banner-actions, .filter-card, .sidebar, .navbar, .btn-selfie-pill {
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

                <!-- ── Page Banner Header ── -->
                <div class="att-banner">
                    <div>
                        <h3 class="att-banner-title"><i class="fas fa-chart-pie"></i> Attendance Analytics & Report</h3>
                        <p class="att-banner-sub">View comprehensive attendance metrics, working hours, and selfie audit logs</p>
                    </div>
                    <div class="att-banner-actions">
                        <?php if (isset($_REQUEST['search'])): ?>
                        <button type="button" class="btn-banner-action" onclick="window.print()">
                            <i class="fas fa-print"></i> Print Report
                        </button>
                        <?php endif; ?>
                        <a href="attendance_report.php" class="btn-banner-action">
                            <i class="fas fa-undo"></i> Reset Filters
                        </a>
                    </div>
                </div>

                <!-- ── Filter Form ── -->
                <div class="filter-card">
                    <div class="filter-card-header">
                        <i class="fas fa-filter text-primary"></i> Filter Report Criteria
                    </div>
                    <form action="attendance_report.php" method="GET">
                        <div class="row g-3 align-items-end">

                            <div class="col-md-3">
                                <label><i class="fas fa-building text-primary mr-1"></i> Office Location <span class="text-danger">*</span></label>
                                <select class="js-example-basic-single form-select w-100" id="officeSelect" name="office" required>
                                    <?php if (($_SESSION['userRole'] ?? '') === 'receptionist'): ?>
                                        <option value="kinwat" selected>Settribe Kinwat</option>
                                    <?php else: ?>
                                        <option value="" disabled <?= !isset($_REQUEST['office']) ? 'selected' : '' ?>>-- Select Office --</option>
                                        <option value="kinwat" <?= (isset($_REQUEST['office']) && $_REQUEST['office']=='kinwat') ? 'selected' : '' ?>>Settribe Kinwat</option>
                                        <option value="pune"   <?= (isset($_REQUEST['office']) && $_REQUEST['office']=='pune')   ? 'selected' : '' ?>>Settribe Pune</option>
                                    <?php endif; ?>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label><i class="fas fa-laptop-house text-info mr-1"></i> Employee Mode <span class="text-danger">*</span></label>
                                <select class="js-example-basic-single form-select w-100" id="modeSelect" name="mode" required>
                                    <option value="" disabled <?= !isset($_REQUEST['mode']) ? 'selected' : '' ?>>-- Select Mode --</option>
                                    <option value="Online"  <?= (isset($_REQUEST['mode']) && $_REQUEST['mode']=='Online')  ? 'selected' : '' ?>>Online</option>
                                    <option value="Offline" <?= (isset($_REQUEST['mode']) && $_REQUEST['mode']=='Offline') ? 'selected' : '' ?>>Offline</option>
                                    <option value="Reset"   <?= (isset($_REQUEST['mode']) && $_REQUEST['mode']=='Reset')   ? 'selected' : '' ?>>Reset / All</option>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label><i class="fas fa-user-tie text-success mr-1"></i> Employee <span class="text-danger">*</span></label>
                                <select class="js-example-basic-single form-select w-100" id="employeesDropdown" name="intern" required>
                                    <option value="" disabled selected>-- Select Employee --</option>
                                    <?php
                                    $type   = $_REQUEST['mode']   ?? '';
                                    $office = $_REQUEST['office'] ?? '';
                                    if (($_SESSION['userRole'] ?? '') === 'receptionist') {
                                        $office = 'kinwat';
                                    }
                                    if (!empty($office)) {
                                        $query = "SELECT userId, fullName FROM signup WHERE (TRIM(LOWER(status)) = 'active' OR status = '1' OR status = 1)";
                                        $query .= " AND office = '" . mysqli_real_escape_string($con, $office) . "'";
                                        if (!empty($type) && $type !== 'Reset') {
                                            $query .= " AND type = '" . mysqli_real_escape_string($con, $type) . "'";
                                        }
                                        $query .= " ORDER BY fullName ASC";
                                        $q = mysqli_query($con, $query);
                                        while ($r = mysqli_fetch_assoc($q)) {
                                            $sel = (isset($_REQUEST['intern']) && $_REQUEST['intern'] == $r['userId']) ? 'selected' : '';
                                            echo "<option value='{$r['userId']}' $sel>" . htmlspecialchars($r['fullName']) . "</option>";
                                        }
                                    }
                                    ?>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label><i class="fas fa-calendar-alt text-warning mr-1"></i> From Date <span class="text-danger">*</span></label>
                                <input type="date" name="fdate" class="form-control" id="fromDate" required
                                    value="<?= isset($_REQUEST['fdate']) ? htmlspecialchars($_REQUEST['fdate']) : date('Y-m-01') ?>">
                            </div>

                            <div class="col-md-3 mt-3">
                                <label><i class="fas fa-calendar-check text-danger mr-1"></i> To Date <span class="text-danger">*</span></label>
                                <input type="date" name="tdate" class="form-control" id="toDate" required
                                    value="<?= isset($_REQUEST['tdate']) ? htmlspecialchars($_REQUEST['tdate']) : date('Y-m-d') ?>">
                            </div>

                            <div class="col-md-3 mt-3">
                                <button type="submit" id="submitBtn" class="btn-submit-filter" name="search">
                                    <i class="fas fa-search"></i> Generate Analytics Report
                                </button>
                            </div>

                        </div>
                    </form>
                </div>

                <?php if (isset($_REQUEST['search'])): ?>
                <?php
                    $empId  = mysqli_real_escape_string($con, $_REQUEST['intern'] ?? '');
                    $office = mysqli_real_escape_string($con, $_REQUEST['office'] ?? '');

                    // Fetch employee profile
                    $qEmp = mysqli_query($con, "SELECT userId, fullName, userRole, createdDate, type, emp_id, internId FROM signup WHERE userId = '$empId' LIMIT 1");
                    $rEmp = mysqli_fetch_assoc($qEmp);

                    if (!$rEmp) {
                        echo "<div class='alert alert-warning rounded-3'><i class='fas fa-exclamation-triangle mr-2'></i> Selected employee record not found.</div>";
                    } else {
                        $fullName   = $rEmp['fullName'];
                        $empRole    = ucwords(str_replace('_', ' ', $rEmp['userRole']));
                        $empDisplayId = !empty($rEmp['emp_id']) ? $rEmp['emp_id'] : (!empty($rEmp['internId']) ? $rEmp['internId'] : $rEmp['userId']);
                        $isOfflineEmp = (strtolower($rEmp['type'] ?? '') === 'offline');

                        // Date range logic
                        $requestedFrom = $_REQUEST['fdate'];
                        $requestedTo   = $_REQUEST['tdate'];
                        $signupDate    = date('Y-m-d', strtotime($rEmp['createdDate']));
                        $fdate = (strtotime($requestedFrom) < strtotime($signupDate)) ? $signupDate : $requestedFrom;
                        $tdate = $requestedTo;

                        if (strtotime($fdate) > strtotime($tdate)) {
                            $Dates = [];
                        } else {
                            $Dates = getDatesFromRange($fdate, $tdate);
                        }

                        // Bulk Query 1: Attendance Map
                        $attendanceMap = [];
                        if (!empty($Dates)) {
                            $qAtt = mysqli_query($con, "
                                SELECT date, punchInTime, punchOutTime, manHours, punchInImage, punchOutImage
                                FROM   attendance
                                WHERE  userId = '$empId'
                                  AND  date BETWEEN '$fdate' AND '$tdate'
                            ");
                            while ($row = mysqli_fetch_assoc($qAtt)) {
                                $attendanceMap[$row['date']] = $row;
                            }
                        }

                        // Bulk Query 2: Tour Map
                        $tourMap = [];
                        if (!empty($Dates)) {
                            $qTour = mysqli_query($con, "
                                SELECT date, punchInTime, punchOutTime, manHours
                                FROM   tourAttendance
                                WHERE  userId = '$empId'
                                  AND  date BETWEEN '$fdate' AND '$tdate'
                            ");
                            while ($row = mysqli_fetch_assoc($qTour)) {
                                $tourMap[$row['date']] = $row;
                            }
                        }

                        // Bulk Query 3: Approved Leaves Map
                        $leaveMap = [];
                        if (!empty($Dates)) {
                            $qLeave = mysqli_query($con, "
                                SELECT leave_name, leave_type, from_date, to_date
                                FROM   leaves
                                WHERE  userId    = '$empId'
                                  AND  status    = 'Approved'
                                  AND  from_date <= '$tdate'
                                  AND  to_date   >= '$fdate'
                            ");
                            while ($lRow = mysqli_fetch_assoc($qLeave)) {
                                $ls = strtotime($lRow['from_date']);
                                $le = strtotime($lRow['to_date']);
                                for ($ld = $ls; $ld <= $le; $ld += 86400) {
                                    $lDate = date('Y-m-d', $ld);
                                    if (!isset($leaveMap[$lDate])) {
                                        $leaveMap[$lDate] = [
                                            'leave_name' => $lRow['leave_name'],
                                            'leave_type' => $lRow['leave_type'],
                                        ];
                                    }
                                }
                            }
                        }

                        // Pre-compute valid RO dates for offline interns
                        $validRODates = [];
                        if ($isOfflineEmp && !empty($Dates)) {
                            $roByMonth = [];
                            foreach ($leaveMap as $lDate => $lInfo) {
                                if ($lInfo['leave_type'] === 'RO') {
                                    $mk = substr($lDate, 0, 7);
                                    $roByMonth[$mk][] = $lDate;
                                }
                            }
                            foreach ($roByMonth as $mk => $days) {
                                sort($days);
                                foreach (array_slice($days, 0, 2) as $validDay) {
                                    $validRODates[] = $validDay;
                                }
                            }
                        }

                        // Process report rows and calculate analytics
                        $reportRows = [];
                        $totalDaysCount   = count($Dates);
                        $presentCount     = 0;
                        $absentCount      = 0;
                        $tourCount        = 0;
                        $holidayCount     = 0;
                        $leaveCount       = 0;
                        $totalWorkMinutes = 0;

                        for ($i = 0; $i < count($Dates); $i++) {
                            $date = $Dates[$i];
                            $present_result = $attendanceMap[$date] ?? null;

                            $punchInTime  = (!empty($present_result['punchInTime']  ?? null)) ? date('H:i', strtotime($present_result['punchInTime']))  : '-';
                            $punchOutTime = (!empty($present_result['punchOutTime'] ?? null)) ? date('H:i', strtotime($present_result['punchOutTime'])) : '-';
                            $manHours     = (!empty($present_result['manHours']     ?? null)) ? date('H:i', strtotime($present_result['manHours']))     : '-';

                            $presenty  = 'Absent';
                            $dayOfWeek = (int) date('w', strtotime($date));

                            if ($present_result !== null) {
                                $presenty = 'Present';
                                $presentCount++;
                                if (!empty($present_result['manHours']) && $present_result['manHours'] !== '00:00:00') {
                                    $hp = explode(':', date('H:i', strtotime($present_result['manHours'])));
                                    $totalWorkMinutes += (int)$hp[0] * 60 + (int)($hp[1] ?? 0);
                                }
                            } else {
                                $tour_query = $tourMap[$date] ?? null;
                                if ($tour_query !== null) {
                                    $punchInTime  = ($tour_query['punchInTime']  != '00:00:00') ? date('H:i', strtotime($tour_query['punchInTime']))  : '-';
                                    $punchOutTime = ($tour_query['punchOutTime'] != '00:00:00') ? date('H:i', strtotime($tour_query['punchOutTime'])) : '-';
                                    $manHours     = ($tour_query['manHours']     != '00:00:00') ? date('H:i', strtotime($tour_query['manHours']))     : '-';
                                    $presenty     = 'Tour';
                                    $tourCount++;
                                    if (!empty($tour_query['manHours']) && $tour_query['manHours'] !== '00:00:00') {
                                        $hp = explode(':', date('H:i', strtotime($tour_query['manHours'])));
                                        $totalWorkMinutes += (int)$hp[0] * 60 + (int)($hp[1] ?? 0);
                                    }
                                } else {
                                    $leave_result = $leaveMap[$date] ?? null;
                                    if ($leave_result !== null) {
                                        if ($leave_result['leave_type'] === 'RO' && $isOfflineEmp) {
                                            if (in_array($date, $validRODates)) {
                                                $presenty = $leave_result['leave_name'];
                                                $leaveCount++;
                                            } else {
                                                $presenty = 'Absent';
                                                $absentCount++;
                                            }
                                        } else {
                                            $presenty = $leave_result['leave_name'];
                                            $leaveCount++;
                                        }
                                    } else {
                                        if ($isOfflineEmp) {
                                            if ($dayOfWeek === 0) {
                                                $presenty = 'Holiday';
                                                $holidayCount++;
                                            } else {
                                                $presenty = 'Absent';
                                                $absentCount++;
                                            }
                                        } else {
                                            if ($office === 'kinwat' && ($dayOfWeek === 0 || $dayOfWeek === 6)) {
                                                $presenty = 'Holiday';
                                                $holidayCount++;
                                            } elseif ($office === 'pune' && $dayOfWeek === 0) {
                                                $presenty = 'Holiday';
                                                $holidayCount++;
                                            } else {
                                                $presenty = 'Absent';
                                                $absentCount++;
                                            }
                                        }
                                    }
                                }
                            }

                            $reportRows[] = [
                                'srNo'          => $i + 1,
                                'date'          => $date,
                                'presenty'      => $presenty,
                                'punchInTime'   => $punchInTime,
                                'punchOutTime'  => $punchOutTime,
                                'manHours'      => $manHours,
                                'punchInImage'  => $present_result['punchInImage'] ?? null,
                                'punchOutImage' => $present_result['punchOutImage'] ?? null,
                            ];
                        }

                        // Compute Summary KPIs
                        $totalWorkingDays = $presentCount + $tourCount;
                        $attendanceRate   = $totalDaysCount > 0 ? round(($totalWorkingDays / $totalDaysCount) * 100, 1) : 0;

                        $workHours = floor($totalWorkMinutes / 60);
                        $workMins  = $totalWorkMinutes % 60;
                        $totalHoursFormatted = sprintf("%dh %02dm", $workHours, $workMins);

                        $avgMinutes = $totalWorkingDays > 0 ? round($totalWorkMinutes / $totalWorkingDays) : 0;
                        $avgHoursFormatted = sprintf("%dh %02dm", floor($avgMinutes / 60), $avgMinutes % 60);
                ?>

                <!-- ── KPI Executive Summary Cards ── -->
                <div class="analytics-grid">
                    <div class="analytics-card">
                        <div class="analytics-icon icon-indigo"><i class="fas fa-calendar-alt"></i></div>
                        <div>
                            <div class="analytics-label">Total Period Days</div>
                            <div class="analytics-value"><?= $totalDaysCount ?></div>
                            <div class="analytics-subtext text-muted"><?= date('d M', strtotime($fdate)) ?> – <?= date('d M Y', strtotime($tdate)) ?></div>
                        </div>
                    </div>

                    <div class="analytics-card">
                        <div class="analytics-icon icon-emerald"><i class="fas fa-user-check"></i></div>
                        <div>
                            <div class="analytics-label">Present &amp; Tour</div>
                            <div class="analytics-value"><?= $totalWorkingDays ?></div>
                            <div class="analytics-subtext text-success">
                                <i class="fas fa-arrow-up mr-1"></i><?= $attendanceRate ?>% Attendance
                            </div>
                        </div>
                    </div>

                    <div class="analytics-card">
                        <div class="analytics-icon icon-rose"><i class="fas fa-user-times"></i></div>
                        <div>
                            <div class="analytics-label">Absent Days</div>
                            <div class="analytics-value"><?= $absentCount ?></div>
                            <div class="analytics-subtext text-danger">Unexcused Absences</div>
                        </div>
                    </div>

                    <div class="analytics-card">
                        <div class="analytics-icon icon-sky"><i class="fas fa-business-time"></i></div>
                        <div>
                            <div class="analytics-label">Total Working Hours</div>
                            <div class="analytics-value"><?= $totalHoursFormatted ?></div>
                            <div class="analytics-subtext text-info">Logged Man-Hours</div>
                        </div>
                    </div>

                    <div class="analytics-card">
                        <div class="analytics-icon icon-amber"><i class="fas fa-clock"></i></div>
                        <div>
                            <div class="analytics-label">Avg. Daily Hours</div>
                            <div class="analytics-value"><?= $avgHoursFormatted ?></div>
                            <div class="analytics-subtext text-warning">Per Present Day</div>
                        </div>
                    </div>
                </div>

                <!-- ── Profile Summary Bar ── -->
                <div class="profile-summary-bar">
                    <div class="user-pill-info">
                        <div class="user-avatar-circle"><?= strtoupper(substr($fullName, 0, 1)) ?></div>
                        <div>
                            <h5 class="user-name-title"><?= htmlspecialchars($fullName) ?></h5>
                            <p class="user-meta-sub">
                                <span>ID: <strong><?= htmlspecialchars($empDisplayId) ?></strong></span> • 
                                <span>Role: <strong><?= htmlspecialchars($empRole) ?></strong></span>
                            </p>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="tag-meta-pill"><i class="fas fa-building mr-1"></i> Office: <?= htmlspecialchars(ucfirst($office)) ?></span>
                        <span class="tag-meta-pill"><i class="fas fa-wifi mr-1"></i> Mode: <?= htmlspecialchars(ucfirst($rEmp['type'] ?? 'Standard')) ?></span>
                        <span class="tag-meta-pill"><i class="fas fa-plane mr-1"></i> Tour: <?= $tourCount ?></span>
                        <span class="tag-meta-pill"><i class="fas fa-umbrella-beach mr-1"></i> Holidays/Leaves: <?= $holidayCount + $leaveCount ?></span>
                    </div>
                </div>

                <!-- ── Attendance Data Table Card ── -->
                <div class="report-card">
                    <div class="report-card-header">
                        <h4 class="report-card-title"><i class="fas fa-list-alt text-primary"></i> Daily Attendance Breakdown</h4>
                        <span class="badge bg-light text-dark font-weight-bold" style="font-size:.82rem;">
                            <?= count($reportRows) ?> Entries Listed
                        </span>
                    </div>
                    <div class="table-responsive">
                        <table class="table att-table" id="attendanceReportTable">
                            <thead>
                                <tr>
                                    <th>Sr. No.</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th>Punch In</th>
                                    <th>Punch Out</th>
                                    <th>Working Hours</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($reportRows as $row): 
                                $stClass = 'status-absent';
                                $stIcon  = 'fa-times-circle';
                                $pStatus = strtolower($row['presenty']);

                                if ($pStatus === 'present') {
                                    $stClass = 'status-present'; $stIcon = 'fa-check-circle';
                                } elseif ($pStatus === 'tour') {
                                    $stClass = 'status-tour'; $stIcon = 'fa-plane';
                                } elseif ($pStatus === 'holiday') {
                                    $stClass = 'status-holiday'; $stIcon = 'fa-umbrella-beach';
                                } elseif ($pStatus !== 'absent') {
                                    $stClass = 'status-leave'; $stIcon = 'fa-calendar-minus';
                                }
                            ?>
                                <tr>
                                    <td><span class="fw-bold text-secondary"><?= $row['srNo'] ?></span></td>
                                    <td>
                                        <div class="fw-bold"><?= date('d M Y', strtotime($row['date'])) ?></div>
                                        <small class="text-muted"><?= date('l', strtotime($row['date'])) ?></small>
                                    </td>
                                    <td>
                                        <span class="att-status-badge <?= $stClass ?>">
                                            <i class="fas <?= $stIcon ?>"></i> <?= htmlspecialchars($row['presenty']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="fw-bold"><?= $row['punchInTime'] ?></div>
                                        <?php if (!empty($row['punchInImage'])): ?>
                                            <button type="button" class="btn btn-selfie-pill view-selfie"
                                                data-image="<?= getSelfieUrl($row['punchInImage'], $punchInBasePath) ?>"
                                                data-title="Punch In Selfie — <?= date('d M Y', strtotime($row['date'])) ?>">
                                                <i class="fas fa-camera"></i> Selfie
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="fw-bold"><?= $row['punchOutTime'] ?></div>
                                        <?php if (!empty($row['punchOutImage'])): ?>
                                            <button type="button" class="btn btn-selfie-pill view-selfie"
                                                data-image="<?= getSelfieUrl($row['punchOutImage'], $punchOutBasePath) ?>"
                                                data-title="Punch Out Selfie — <?= date('d M Y', strtotime($row['date'])) ?>">
                                                <i class="fas fa-camera"></i> Selfie
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($row['manHours'] !== '-'): ?>
                                            <span class="hours-badge"><i class="fas fa-stopwatch text-primary mr-1"></i><?= $row['manHours'] ?> hrs</span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php } // end if $rEmp ?>
                <?php endif; // end if isset search ?>

            </div><!-- /.content-wrapper -->
        </div><!-- /.main-panel -->
    </div><!-- /.page-body-wrapper -->
</div><!-- /.container-scroller -->

<!-- ── Selfie Audit Modal ── -->
<div class="modal fade" id="selfieModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-dark text-white border-0 py-3">
                <h5 class="modal-title font-weight-bold" id="selfieTitle" style="font-size:1rem;">Selfie Verification Log</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4 text-center bg-light">
                <img id="selfieImage" src="" class="img-fluid rounded-3 shadow" alt="Punch Verification Selfie" style="max-height: 450px; object-fit: contain;">
            </div>
        </div>
    </div>
</div>

<!-- Scripts -->
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
<script src="../vendors/select2/select2.min.js"></script>
<script src="../js/off-canvas.js"></script>
<script src="../js/hoverable-collapse.js"></script>
<script src="../js/template.js"></script>
<script src="../js/settings.js"></script>

<script>
    let selfieModal, lastFocusedButton = null;

    document.addEventListener('DOMContentLoaded', function () {
        const modalEl = document.getElementById('selfieModal');
        if (modalEl) {
            selfieModal = new bootstrap.Modal(modalEl);
            modalEl.addEventListener('hidden.bs.modal', function () {
                if (lastFocusedButton) { lastFocusedButton.focus(); lastFocusedButton = null; }
            });
        }
    });

    $(document).on('click', '.view-selfie', function () {
        lastFocusedButton = this;
        $('#selfieTitle').text($(this).data('title'));
        $('#selfieImage').attr('src', $(this).data('image'));
        if (selfieModal) selfieModal.show();
    });

    // Initialize Select2
    $(document).ready(function() {
        $('.js-example-basic-single').select2({ placeholder: '-- Select Option --', allowClear: true, width: '100%' });

        if ($('#attendanceReportTable').length && $.fn.DataTable) {
            $('#attendanceReportTable').DataTable({
                paging: true,
                searching: true,
                ordering: true,
                info: true,
                pageLength: 15,
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
                    search: "Filter Daily Logs:",
                    lengthMenu: "Show _MENU_ entries",
                    paginate: {
                        previous: '&laquo; Prev',
                        next: 'Next &raquo;'
                    }
                },
                pagingType: 'simple_numbers'
            });
        }
    });

    // Dynamic Employee Dropdown based on Office & Mode
    $(document).ready(function () {
        $('#officeSelect, #modeSelect').on('change', function () {
            var office = $('#officeSelect').val();
            var mode   = $('#modeSelect').val();
            if (office && mode) {
                $.ajax({
                    url: 'filter_emp.php', type: 'POST',
                    data: { office: office, mode: mode }, dataType: 'json',
                    success: function (response) {
                        var $dd = $('#employeesDropdown');
                        $dd.empty().append('<option value="" selected disabled>-- Select Employee --</option>');

                        var list = [];
                        if (response) {
                            if (Array.isArray(response.employees)) { list = response.employees; }
                            else if (Array.isArray(response)) { list = response; }
                        }

                        if (list.length > 0) {
                            $.each(list, function (i, emp) {
                                $dd.append('<option value="' + emp.userId + '">' + emp.fullName + '</option>');
                            });
                        } else {
                            $dd.append('<option value="" disabled>No employees found</option>');
                        }

                        try { $dd.select2({ placeholder: '-- Select Employee --', allowClear: true, width: '100%' }); } catch (e) {}
                    },
                    error: function (xhr, status, err) {
                        console.error('AJAX error:', status, err);
                    }
                });
            } else {
                $('#employeesDropdown').empty().append('<option value="" selected disabled>-- Select Employee --</option>');
            }
        });
    });

    // Cap date inputs at today
    const today = new Date().toISOString().split('T')[0];
    const fromEl = document.getElementById('fromDate');
    const toEl   = document.getElementById('toDate');
    if (fromEl) fromEl.setAttribute('max', today);
    if (toEl)   toEl.setAttribute('max', today);
</script>

<?php
require_once('../include/sweetAlert.php');
if (isset($_SESSION['status'])) {
    $status = $_SESSION['status']; $msg = $_SESSION['msg'];
    if ($status != true) { toastMsg('error', $msg); } else { toastMsg('success', $msg); }
    unset($_SESSION['status'], $_SESSION['msg']);
}
?>
</body>
</html>