<?php
session_start();
date_default_timezone_set('Asia/Kolkata');

if (!isset($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

include('../include/db_config.php');

// ── Helper: generate all dates in a range ──────────────────────────
function getDatesInRange($start, $end)
{
    $dates = [];
    $interval = new DateInterval('P1D');
    $realEnd = new DateTime($end);
    $realEnd->add($interval);
    $period = new DatePeriod(new DateTime($start), $interval, $realEnd);
    foreach ($period as $d) {
        $dates[] = $d->format('Y-m-d');
    }
    return $dates;
}

// ── Filter inputs ──────────────────────────────────────────────────
$fromDate = isset($_GET['from_date']) ? mysqli_real_escape_string($con, trim($_GET['from_date'])) : '';
$toDate = isset($_GET['to_date']) ? mysqli_real_escape_string($con, trim($_GET['to_date'])) : '';
$batchFilter = isset($_GET['batch']) ? mysqli_real_escape_string($con, trim($_GET['batch'])) : '';
$branchFilter = isset($_GET['branch']) ? mysqli_real_escape_string($con, trim($_GET['branch'])) : '';
$modeFilter = isset($_GET['mode']) ? mysqli_real_escape_string($con, trim($_GET['mode'])) : '';
$doSearch = isset($_GET['search']);

// Auto-default date range to current week (last 7 days) if not provided
if (!$fromDate || !$toDate) {
    $toDate   = date('Y-m-d');
    $fromDate = date('Y-m-d', strtotime('-6 days'));
    $doSearch = true;
}

// ── Fetch filter options ───────────────────────────────────────────
$batchRows = mysqli_query($con, "SELECT DISTINCT batch_number FROM signup WHERE batch_number IS NOT NULL AND batch_number != '' ORDER BY batch_number");
$branchRows = mysqli_query($con, "SELECT DISTINCT branch FROM signup WHERE branch IS NOT NULL AND branch != '' ORDER BY branch");

// ── If searching, fetch data ───────────────────────────────────────
$dates = [];
$persons = [];
$attendanceMap = []; // userId => [date => row]
$expectedPunchInMap = []; // userId => expectedPunchIn (HH:MM:SS)

if ($doSearch && $fromDate && $toDate) {
    $dates = getDatesInRange($fromDate, $toDate);

    // Build person query — also fetch expectedPunchIn
    $personSql = "SELECT userId, fullName, employment_type, branch, batch_number, mode, expectedPunchIn
                  FROM signup
                  WHERE regstatus = 'Approved' AND status = 'Active' AND fullName != ''";

    if ($batchFilter)
        $personSql .= " AND batch_number = '$batchFilter'";
    if ($branchFilter)
        $personSql .= " AND (branch = '$branchFilter' or office = '$branchFilter')";
    if ($modeFilter)
        $personSql .= " AND (mode = '$modeFilter' or type='$modeFilter')";
    $personSql .= " ORDER BY fullName ASC";

    $pResult = mysqli_query($con, $personSql);
    while ($row = mysqli_fetch_assoc($pResult)) {
        $persons[] = $row;
        $expectedPunchInMap[$row['userId']] = $row['expectedPunchIn'] ?? '';
    }

    if (!empty($persons) && !empty($dates)) {
        $userIds = array_map(fn($p) => "'" . mysqli_real_escape_string($con, $p['userId']) . "'", $persons);
        $userIdList = implode(',', $userIds);

        // Bulk-fetch attendance
        $attResult = mysqli_query($con, "
            SELECT userId, date, punchInTime, punchOutTime, status
            FROM attendance
            WHERE userId IN ($userIdList)
              AND date BETWEEN '$fromDate' AND '$toDate'
        ");
        while ($row = mysqli_fetch_assoc($attResult)) {
            $attendanceMap[$row['userId']][$row['date']] = $row;
        }

        // Bulk-fetch tour attendance (fallback if no main attendance)
        $tourResult = mysqli_query($con, "
            SELECT userId, date, punchInTime, punchOutTime
            FROM tourAttendance
            WHERE userId IN ($userIdList)
              AND date BETWEEN '$fromDate' AND '$toDate'
        ");
        while ($row = mysqli_fetch_assoc($tourResult)) {
            if (!isset($attendanceMap[$row['userId']][$row['date']])) {
                $attendanceMap[$row['userId']][$row['date']] = array_merge($row, ['status' => 'Tour']);
            }
        }
    }
}

// ── Bulk-fetch holidays that overlap the selected date range ───────
$holidayDates = []; // date (Y-m-d) => holiday title
if ($doSearch && $fromDate && $toDate) {
    $holResult = mysqli_query($con, "
        SELECT title, from_date, to_date
        FROM   holidays
        WHERE  from_date <= '$toDate'
          AND  to_date   >= '$fromDate'
    ");
    if ($holResult) {
        while ($hRow = mysqli_fetch_assoc($holResult)) {
            $hStart = strtotime($hRow['from_date']);
            $hEnd = strtotime($hRow['to_date']);
            for ($ts = $hStart; $ts <= $hEnd; $ts += 86400) {
                $hDate = date('Y-m-d', $ts);
                if (!isset($holidayDates[$hDate])) {
                    $holidayDates[$hDate] = $hRow['title'];
                }
            }
        }
    }
}

// Helper: format time or return '-'
function fmtTime($t)
{
    if (empty($t) || $t === '00:00:00')
        return '';
    return date('H:i', strtotime($t));
}

// Helper: true if actual punch-in is LATER than expected (i.e., late)
function isLate($actual, $expected)
{
    if (empty($actual) || $actual === '00:00:00' || empty($expected))
        return false;
    return strtotime($actual) > strtotime($expected);
}

// ── Analytics Metrics Pre-Computation ──────────────────────────────
$totalPresentCount = 0;
$totalAbsentCount  = 0;
$totalTourCount    = 0;
$totalHolidayCount = 0;
$totalLateCount    = 0;
$dailyLateMap      = [];
$dailyOnTimeMap    = [];

foreach ($dates as $d) {
    $dailyLateMap[$d]   = 0;
    $dailyOnTimeMap[$d] = 0;
}

if ($doSearch && !empty($persons) && !empty($dates)) {
    foreach ($persons as $person) {
        $uid = $person['userId'];
        $expPunchIn = $expectedPunchInMap[$uid] ?? '';

        foreach ($dates as $d) {
            $att = $attendanceMap[$uid][$d] ?? null;
            if ($att !== null) {
                $piRaw = $att['punchInTime'] ?? '';
                $status = !empty($att['status']) ? $att['status'] : 'Present';
            } else {
                $piRaw = '';
                $dayOfWeek = (int) date('w', strtotime($d));
                if ($dayOfWeek === 0 || $dayOfWeek === 6 || isset($holidayDates[$d])) {
                    $status = 'Holiday';
                } else {
                    $status = 'Absent';
                }
            }

            $stLower = strtolower($status);
            if ($stLower === 'present') {
                $totalPresentCount++;
                if (isLate($piRaw, $expPunchIn)) {
                    $totalLateCount++;
                    $dailyLateMap[$d]++;
                } else {
                    $dailyOnTimeMap[$d]++;
                }
            } elseif ($stLower === 'tour') {
                $totalTourCount++;
            } elseif ($stLower === 'holiday') {
                $totalHolidayCount++;
            } else {
                $totalAbsentCount++;
            }
        }
    }
}

$punctualityRate = ($totalPresentCount > 0) ? round((($totalPresentCount - $totalLateCount) / $totalPresentCount) * 100, 1) : 100;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <title>SETTribe · Day-Wise Attendance &amp; Punctuality Matrix</title>
    <?php include('../partials/_links.php'); ?>
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
            --border: #e2e8f0;
            --radius-lg: 14px;
            --radius-md: 10px;
        }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: #f1f5f9;
            color: #1e293b;
        }

        /* ── Header Card ── */
        .analytics-header-card {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #334155 100%);
            border-radius: var(--radius-lg);
            padding: 24px 28px;
            color: #ffffff;
            margin-bottom: 24px;
            box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.3);
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
            background: rgba(255, 255, 255, 0.05);
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
            color: #475569;
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
        .kpi-card::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; }
        .kpi-blue::before    { background: var(--primary); }
        .kpi-green::before   { background: var(--success); }
        .kpi-red::before     { background: var(--danger); }
        .kpi-amber::before   { background: var(--warning); }

        .kpi-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
        .kpi-title { font-size: 0.78rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.4px; }
        .kpi-icon-wrap { width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1rem; }
        .icon-blue   { background: var(--primary-light); color: var(--primary); }
        .icon-green  { background: var(--success-light); color: var(--success); }
        .icon-red    { background: var(--danger-light); color: var(--danger); }
        .icon-amber  { background: var(--warning-light); color: var(--warning); }

        .kpi-value { font-size: 1.7rem; font-weight: 800; color: #0f172a; line-height: 1.1; margin-bottom: 6px; }
        .kpi-sub { font-size: 0.75rem; color: #64748b; font-weight: 600; }

        /* ── Matrix Table Styling ── */
        .fip-table-card {
            background: #ffffff;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            padding: 24px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.03);
            margin-bottom: 30px;
        }

        .fip-table-wrap { overflow-x: auto; max-width: 100%; border-radius: 10px; border: 1px solid var(--border); }
        .fip-table { border-collapse: separate; border-spacing: 0; min-width: 100%; font-size: 0.8rem; }
        
        .fip-table th, .fip-table td {
            border-bottom: 1px solid #f1f5f9;
            border-right: 1px solid #f1f5f9;
            padding: 6px 10px;
            white-space: nowrap;
            text-align: center;
            vertical-align: middle;
        }

        .fip-table thead th.date-header {
            background: linear-gradient(135deg, #1e293b, #334155);
            color: #ffffff;
            font-weight: 700;
            font-size: 0.78rem;
        }
        .fip-table thead th.sub-header {
            background: #475569;
            color: #f8fafc;
            font-size: 0.70rem;
            font-weight: 600;
        }
        .fip-table thead th.fixed-col {
            background: #0f172a;
            color: #ffffff;
            font-weight: 800;
        }

        /* Sticky Columns */
        .fip-table th.col-sr, .fip-table td.col-sr {
            position: sticky; left: 0; z-index: 2; background: #f8fafc; min-width: 46px; font-weight: 700; color: #64748b;
        }
        .fip-table th.col-name, .fip-table td.col-name {
            position: sticky; left: 46px; z-index: 2; background: #ffffff; min-width: 190px; text-align: left; font-weight: 600; color: #0f172a;
            box-shadow: 3px 0 6px -2px rgba(0,0,0,0.05);
        }
        .fip-table thead th.col-sr, .fip-table thead th.col-name { background: #0f172a; color: #fff; z-index: 3; }
        .fip-table tbody tr:hover td { background-color: #f1f5f9; }

        /* Status Pills */
        .status-pill { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 0.7rem; font-weight: 800; }
        .pill-p { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .pill-a { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
        .pill-t { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
        .pill-h { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }

        .time-val { font-size: 0.76rem; color: #334155; }
        .time-late { color: #dc2626; font-weight: 800; }
        .no-data { color: #cbd5e1; font-size: 0.7rem; }

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
        .btn-gradient-primary:hover { transform: translateY(-1px); color: #fff; }
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
        .btn-gradient-success:hover { transform: translateY(-1px); color: #fff; }
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

                    <!-- ── HEADER BANNER ── -->
                    <div class="analytics-header-card">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                            <div>
                                <div class="header-title-badge">
                                    <i class="fas fa-calendar-alt"></i> Attendance Matrix &amp; Punctuality Analytics
                                </div>
                                <h2 style="font-size: 1.6rem; font-weight: 800; margin-bottom: 4px;">
                                    Day-Wise Punch-In / Punch-Out Report
                                </h2>
                                <p style="font-size: 0.85rem; opacity: 0.88; margin: 0;">
                                    Daily attendance status, punch-in &amp; punch-out time tracking, late entry identification, and matrix export.
                                </p>
                            </div>
                            <div class="text-right">
                                <span class="badge badge-light px-3 py-2" style="font-size:0.82rem; font-weight:700; border-radius:20px;">
                                    <i class="fas fa-calendar-day text-primary mr-1"></i>
                                    <?= date('d M Y', strtotime($fromDate)) ?> &nbsp;—&nbsp; <?= date('d M Y', strtotime($toDate)) ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- ── FILTER CARD ── -->
                    <div class="filter-card">
                        <form method="GET" action="fip_attendance_report.php" id="filterForm">
                            <div class="row align-items-end">
                                <div class="col-lg-3 col-md-6 mb-3 mb-lg-0">
                                    <div class="d-flex align-items-center justify-content-between mb-1">
                                        <label class="filter-label mb-0"><i class="fas fa-calendar-day text-primary mr-1"></i> Date Range</label>
                                        <div class="d-flex gap-1">
                                            <button type="button" class="preset-btn" onclick="setPreset('today')">Today</button>
                                            <button type="button" class="preset-btn" onclick="setPreset('last_7')">7 Days</button>
                                            <button type="button" class="preset-btn" onclick="setPreset('this_month')">Month</button>
                                        </div>
                                    </div>
                                    <div class="input-group">
                                        <input type="date" name="from_date" class="form-control" id="fromDate" required value="<?= htmlspecialchars($fromDate) ?>" max="<?= date('Y-m-d') ?>" style="border-radius:8px 0 0 8px;">
                                        <input type="date" name="to_date" class="form-control" id="toDate" required value="<?= htmlspecialchars($toDate) ?>" max="<?= date('Y-m-d') ?>" style="border-radius:0 8px 8px 0;">
                                    </div>
                                </div>

                                <div class="col-lg-2 col-md-6 mb-3 mb-lg-0">
                                    <label class="filter-label"><i class="fas fa-layer-group text-purple mr-1"></i> Batch</label>
                                    <select name="batch" class="form-control select2" id="batchSelect" style="width:100%;">
                                        <option value="">All Batches</option>
                                        <?php
                                        if ($batchRows) {
                                            mysqli_data_seek($batchRows, 0);
                                            while ($b = mysqli_fetch_assoc($batchRows)):
                                                $sel = ($batchFilter === $b['batch_number']) ? 'selected' : '';
                                        ?>
                                                <option value="<?= htmlspecialchars($b['batch_number']) ?>" <?= $sel ?>>
                                                    <?= htmlspecialchars($b['batch_number']) ?>
                                                </option>
                                        <?php endwhile; } ?>
                                    </select>
                                </div>

                                <div class="col-lg-2 col-md-6 mb-3 mb-lg-0">
                                    <label class="filter-label"><i class="fas fa-building text-info mr-1"></i> Branch / Office</label>
                                    <select name="branch" class="form-control select2" id="branchSelect" style="width:100%;">
                                        <option value="">All Branches</option>
                                        <?php
                                        if ($branchRows) {
                                            mysqli_data_seek($branchRows, 0);
                                            while ($br = mysqli_fetch_assoc($branchRows)):
                                                $sel = ($branchFilter === $br['branch']) ? 'selected' : '';
                                        ?>
                                                <option value="<?= htmlspecialchars($br['branch']) ?>" <?= $sel ?>>
                                                    <?= htmlspecialchars($br['branch']) ?>
                                                </option>
                                        <?php endwhile; } ?>
                                    </select>
                                </div>

                                <div class="col-lg-2 col-md-6 mb-3 mb-lg-0">
                                    <label class="filter-label"><i class="fas fa-laptop-house text-success mr-1"></i> Mode</label>
                                    <select name="mode" class="form-control select2" id="modeSelect" style="width:100%;">
                                        <option value="">All Modes</option>
                                        <option value="Online" <?= $modeFilter === 'Online' ? 'selected' : '' ?>>Online</option>
                                        <option value="Offline" <?= $modeFilter === 'Offline' ? 'selected' : '' ?>>Offline</option>
                                    </select>
                                </div>

                                <div class="col-lg-3 col-md-12 text-right">
                                    <button type="submit" name="search" class="btn btn-gradient-primary mr-1">
                                        <i class="fas fa-filter mr-1"></i> Show Report
                                    </button>
                                    <?php if (!empty($persons)): ?>
                                        <button type="button" id="exportExcelBtn" class="btn btn-gradient-success">
                                            <i class="fas fa-file-excel mr-1"></i> Export
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </form>
                    </div>

                    <?php if ($doSearch): ?>
                        <!-- ── KPI METRICS CARDS ── -->
                        <div class="row mb-4">
                            <div class="col-xl-3 col-md-6 mb-3 mb-xl-0">
                                <div class="kpi-card kpi-blue">
                                    <div class="kpi-header">
                                        <span class="kpi-title">Team in Scope</span>
                                        <div class="kpi-icon-wrap icon-blue"><i class="fas fa-users"></i></div>
                                    </div>
                                    <div>
                                        <div class="kpi-value"><?= count($persons) ?></div>
                                        <div class="kpi-sub"><?= count($dates) ?> Calendar Days Tracked</div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-xl-3 col-md-6 mb-3 mb-xl-0">
                                <div class="kpi-card kpi-green">
                                    <div class="kpi-header">
                                        <span class="kpi-title">Punctuality Rate</span>
                                        <div class="kpi-icon-wrap icon-green"><i class="fas fa-check-double"></i></div>
                                    </div>
                                    <div>
                                        <div class="kpi-value"><?= $punctualityRate ?>%</div>
                                        <div class="kpi-sub">On-Time Punch-In Ratio</div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-xl-3 col-md-6 mb-3 mb-xl-0">
                                <div class="kpi-card kpi-red">
                                    <div class="kpi-header">
                                        <span class="kpi-title">Late Punch-Ins</span>
                                        <div class="kpi-icon-wrap icon-red"><i class="fas fa-user-clock"></i></div>
                                    </div>
                                    <div>
                                        <div class="kpi-value"><?= $totalLateCount ?></div>
                                        <div class="kpi-sub">Exceeded Expected Time</div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-xl-3 col-md-6 mb-3 mb-xl-0">
                                <div class="kpi-card kpi-amber">
                                    <div class="kpi-header">
                                        <span class="kpi-title">Attendance Split</span>
                                        <div class="kpi-icon-wrap icon-amber"><i class="fas fa-layer-group"></i></div>
                                    </div>
                                    <div>
                                        <div class="kpi-value" style="font-size:1.25rem;">
                                            <span class="text-success"><?= $totalPresentCount ?>P</span> /
                                            <span class="text-danger"><?= $totalAbsentCount ?>A</span> /
                                            <span class="text-warning"><?= $totalTourCount ?>T</span>
                                        </div>
                                        <div class="kpi-sub">Present / Absent / Tour</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ── ANALYTICS CHARTS ── -->
                        <div class="row mb-4">
                            <!-- Daily Punctuality Trend Chart -->
                            <div class="col-lg-7 mb-3 mb-lg-0">
                                <div class="fip-table-card h-100" style="padding:20px;">
                                    <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
                                        <h5 style="font-size:.95rem; font-weight:800; color:#0f172a; margin:0;">
                                            <i class="fas fa-chart-bar text-primary mr-1"></i> Daily Punctuality Trend (On-Time vs Late)
                                        </h5>
                                        <span class="badge badge-light border" style="font-size:.72rem;">Per-Day Comparison</span>
                                    </div>
                                    <div style="position: relative; height: 230px;">
                                        <canvas id="punctualityTrendChart"></canvas>
                                    </div>
                                </div>
                            </div>

                            <!-- Attendance Matrix Distribution Chart -->
                            <div class="col-lg-5">
                                <div class="fip-table-card h-100" style="padding:20px;">
                                    <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
                                        <h5 style="font-size:.95rem; font-weight:800; color:#0f172a; margin:0;">
                                            <i class="fas fa-chart-pie text-purple mr-1"></i> Overall Status Matrix
                                        </h5>
                                        <span class="badge badge-light border" style="font-size:.72rem;">Proportional Split</span>
                                    </div>
                                    <div style="position: relative; height: 230px;">
                                        <canvas id="statusDoughnutChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ── MATRIX TABLE CARD ── -->
                        <div class="fip-table-card">
                            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                                <div>
                                    <h5 class="mb-0 font-weight-bold" style="color:#0f172a; font-size:1.05rem;">
                                        <i class="fas fa-table text-primary mr-1"></i> Day-Wise Punch Log Matrix
                                    </h5>
                                    <div class="d-flex align-items-center gap-3 mt-1 flex-wrap" style="font-size:0.78rem;">
                                        <span><span class="status-pill pill-p">P</span> Present</span>
                                        <span><span class="status-pill pill-a">A</span> Absent</span>
                                        <span><span class="status-pill pill-t">T</span> Tour</span>
                                        <span><span class="status-pill pill-h">H</span> Holiday</span>
                                        <span class="text-danger font-weight-bold"><i class="fas fa-clock mr-1"></i>Red Text = Late Punch-In</span>
                                    </div>
                                </div>
                                <div class="text-muted" style="font-size:0.8rem;">
                                    <strong><?= count($persons) ?></strong> Persons &nbsp;|&nbsp; <strong><?= count($dates) ?></strong> Days
                                </div>
                            </div>

                            <?php if (empty($persons)): ?>
                                <div class="alert alert-warning text-center">
                                    <i class="fas fa-user-slash mr-2"></i> No employees/interns found matching the selected filters.
                                </div>
                            <?php elseif (empty($dates)): ?>
                                <div class="alert alert-warning text-center">
                                    Invalid date range. Please select valid From and To dates.
                                </div>
                            <?php else: ?>

                                <!-- Matrix Table -->
                                <div class="fip-table-wrap">
                                    <table class="fip-table" id="fipTable">
                                        <thead>
                                            <!-- Row 1: date headers -->
                                            <tr>
                                                <th class="col-sr fixed-col" rowspan="2" style="vertical-align:middle;">Sr.<br>No.</th>
                                                <th class="col-name fixed-col" rowspan="2" style="vertical-align:middle; text-align:left;">Employee Name</th>
                                                <?php foreach ($dates as $d): ?>
                                                    <th class="date-header" colspan="3">
                                                        <?= date('d M Y', strtotime($d)) ?>
                                                    </th>
                                                <?php endforeach; ?>
                                            </tr>
                                            <!-- Row 2: sub-headers -->
                                            <tr>
                                                <?php foreach ($dates as $d): ?>
                                                    <th class="sub-header">Status</th>
                                                    <th class="sub-header">Punch In</th>
                                                    <th class="sub-header">Punch Out</th>
                                                <?php endforeach; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $srNo = 1;
                                            foreach ($persons as $person):
                                                $uid = $person['userId'];
                                                $name = htmlspecialchars((string)$person['fullName']);
                                                $empType = $person['employment_type'];
                                                $rowClass = strtolower((string)$empType) . '-row';
                                                $expPunchIn = $expectedPunchInMap[$uid] ?? '';
                                            ?>
                                                <tr class="<?= $rowClass ?>">
                                                    <td class="col-sr"><?= $srNo++ ?></td>
                                                    <td class="col-name">
                                                        <?= $name ?>
                                                        <br><small class="text-muted" style="font-size:0.65rem;">
                                                            <?= htmlspecialchars((string)$empType) ?>
                                                            <?= !empty($person['mode']) ? ' &bull; ' . htmlspecialchars((string)$person['mode']) : '' ?>
                                                        </small>
                                                    </td>
                                                    <?php
                                                    foreach ($dates as $d):
                                                        $att = $attendanceMap[$uid][$d] ?? null;

                                                        if ($att !== null) {
                                                            $piRaw = $att['punchInTime'] ?? '';
                                                            $poRaw = $att['punchOutTime'] ?? '';
                                                            $pi = fmtTime($piRaw);
                                                            $po = fmtTime($poRaw);
                                                            $status = !empty($att['status']) ? $att['status'] : 'Present';
                                                        } else {
                                                            $piRaw = '';
                                                            $poRaw = '';
                                                            $pi = '';
                                                            $po = '';
                                                            $dayOfWeek = (int) date('w', strtotime($d));
                                                            if ($dayOfWeek === 0 || $dayOfWeek === 6 || isset($holidayDates[$d])) {
                                                                $status = 'Holiday';
                                                            } else {
                                                                $status = 'Absent';
                                                            }
                                                        }

                                                        $late = isLate($piRaw, $expPunchIn);
                                                        $pillClass = match (strtolower($status)) {
                                                            'present' => 'pill-p',
                                                            'tour' => 'pill-t',
                                                            'holiday' => 'pill-h',
                                                            default => 'pill-a',
                                                        };
                                                        $statusLabel = match (strtolower($status)) {
                                                            'present' => 'P',
                                                            'absent' => 'A',
                                                            'tour' => 'T',
                                                            'holiday' => 'H',
                                                            default => strtoupper(substr($status, 0, 1)),
                                                        };

                                                        $piClass = $late ? 'time-late' : 'time-val';
                                                        $piHtml = $pi ? "<strong class=\"{$piClass}\">{$pi}</strong>" : '<span class="no-data">-</span>';
                                                        $poHtml = $po ? $po : '<span class="no-data">-</span>';
                                                    ?>
                                                        <td><span class="status-pill <?= $pillClass ?>"><?= $statusLabel ?></span></td>
                                                        <td class="time-val"><?= $piHtml ?></td>
                                                        <td class="time-val"><?= $poHtml ?></td>
                                                    <?php endforeach; ?>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; // doSearch ?>

                </div><!-- /.content-wrapper -->
            </div><!-- /.main-panel -->
        </div>
    </div>

    <!-- SheetJS & Vendor Scripts -->
    <script src="../vendors/js/vendor.bundle.base.js"></script>
    <script src="../vendors/chart.js/Chart.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>
    <script src="../js/settings.js"></script>
    <script src="../vendors/select2/select2.min.js"></script>
    <script src="../js/select2.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const fromInput = document.getElementById('fromDate');
            const toInput   = document.getElementById('toDate');

            if (fromInput && toInput) {
                fromInput.addEventListener('change', function () {
                    if (toInput.value && toInput.value < fromInput.value) {
                        toInput.value = fromInput.value;
                    }
                    toInput.min = fromInput.value;
                });
            }

            // Punctuality Trend Chart
            <?php if ($doSearch && !empty($dates)): ?>
            var ctxTrend = document.getElementById('punctualityTrendChart').getContext('2d');
            new Chart(ctxTrend, {
                type: 'bar',
                data: {
                    labels: <?= json_encode(array_map(fn($d) => date('d M', strtotime($d)), $dates)) ?>,
                    datasets: [
                        {
                            label: 'On-Time Punch-Ins',
                            data: <?= json_encode(array_values($dailyOnTimeMap)) ?>,
                            backgroundColor: '#10b981',
                            borderRadius: 4
                        },
                        {
                            label: 'Late Punch-Ins',
                            data: <?= json_encode(array_values($dailyLateMap)) ?>,
                            backgroundColor: '#ef4444',
                            borderRadius: 4
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    legend: { position: 'top', labels: { boxWidth: 12, fontSize: 11 } },
                    scales: {
                        xAxes: [{ gridLines: { display: false } }],
                        yAxes: [{ ticks: { beginAtZero: true, stepSize: 1 } }]
                    }
                }
            });

            // Status Doughnut Chart
            var ctxStatus = document.getElementById('statusDoughnutChart').getContext('2d');
            new Chart(ctxStatus, {
                type: 'doughnut',
                data: {
                    labels: ['Present', 'Absent', 'Tour', 'Holiday'],
                    datasets: [{
                        data: [
                            <?= $totalPresentCount ?>,
                            <?= $totalAbsentCount ?>,
                            <?= $totalTourCount ?>,
                            <?= $totalHolidayCount ?>
                        ],
                        backgroundColor: ['#10b981', '#ef4444', '#f59e0b', '#06b6d4'],
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    legend: { position: 'right', labels: { boxWidth: 12, fontSize: 10 } }
                }
            });
            <?php endif; ?>

            // Export Excel
            const exportBtn = document.getElementById('exportExcelBtn');
            if (exportBtn && typeof XLSX !== 'undefined') {
                exportBtn.addEventListener('click', function () {
                    const table = document.getElementById('fipTable');
                    if (!table) { alert('No data to export.'); return; }

                    const clone = table.cloneNode(true);
                    clone.querySelectorAll('.no-data').forEach(el => el.replaceWith(document.createTextNode('-')));
                    clone.querySelectorAll('strong.time-late, strong.time-val, strong').forEach(el => el.replaceWith(document.createTextNode(el.textContent)));
                    clone.querySelectorAll('.status-pill').forEach(el => el.replaceWith(document.createTextNode(el.textContent)));

                    const wb = XLSX.utils.book_new();
                    const ws = XLSX.utils.table_to_sheet(clone, { raw: false });

                    const colWidths = [];
                    const rows = clone.querySelectorAll('tr');
                    if (rows.length > 0) {
                        rows[0].querySelectorAll('th').forEach((th, i) => {
                            colWidths.push({ wch: i < 2 ? (i === 0 ? 6 : 22) : 12 });
                        });
                    }
                    ws['!cols'] = colWidths;

                    XLSX.utils.book_append_sheet(wb, ws, 'DayWise_Attendance');
                    const fromVal = fromInput ? fromInput.value.replace(/-/g, '') : 'export';
                    const toVal   = toInput ? toInput.value.replace(/-/g, '') : '';
                    XLSX.writeFile(wb, 'DayWise_Attendance_' + fromVal + '_' + toVal + '.xlsx');
                });
            }
        });

        function setPreset(type) {
            let today = new Date();
            let fromInput = document.getElementById("fromDate");
            let toInput   = document.getElementById("toDate");

            if (type === 'today') {
                fromInput.value = today.toISOString().split('T')[0];
                toInput.value   = today.toISOString().split('T')[0];
            } else if (type === 'last_7') {
                let past7 = new Date();
                past7.setDate(today.getDate() - 6);
                fromInput.value = past7.toISOString().split('T')[0];
                toInput.value   = today.toISOString().split('T')[0];
            } else if (type === 'this_month') {
                let firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
                fromInput.value = firstDay.toISOString().split('T')[0];
                toInput.value   = today.toISOString().split('T')[0];
            }
        }
    </script>

    <?php
    require_once('../include/sweetAlert.php');
    if (isset($_SESSION['status'])) {
        $status = $_SESSION['status'];
        $msg = $_SESSION['msg'];
        if (!$status) {
            toastMsg('error', $msg);
        } else {
            toastMsg('success', $msg);
        }
        unset($_SESSION['status'], $_SESSION['msg']);
    }
    ?>
</body>
</html>