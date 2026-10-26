<?php
include('../include/db_config.php');

if (!isset($_SESSION['userId']) && isset($_COOKIE['userId'])) {
    $_SESSION['userId'] = $_COOKIE['userId'];
}

if (!isset($_SESSION['userId'])) {
    header("Location: login.php");
    exit;
}

$userId = $_SESSION['userId'];

// Fetch logged-in user details
$userRow = mysqli_fetch_assoc(mysqli_query($con, "SELECT userId, fullName, emp_id, internId, userRole, designation FROM signup WHERE userId='$userId' LIMIT 1"));
if (!$userRow) {
    $userRow = mysqli_fetch_assoc(mysqli_query($con, "SELECT internId as userId, fullName, internId as emp_id, internId, 'intern' as userRole, 'intern' as designation FROM interns WHERE internId='$userId' LIMIT 1"));
}
$userRoleVal   = strtolower($userRow['userRole'] ?? '');
$userDesigVal  = strtolower($userRow['designation'] ?? '');
$userFullName  = $userRow['fullName'] ?? 'User';
$userEmpId     = $userRow['emp_id'] ?? '';
$userInternId  = $userRow['internId'] ?? '';

// Access control: Admin, HR, Resource Head, Team Lead, Senior Intern allowed
$rhRoles  = ['hr', 'admin', 'superadmin', 'resource_head', 'resourcehead', 'resourceallocator', 'resource_allocator', 'resource allocator', 'resourcemanager', 'resource_manager'];
$leadRoles = ['team_lead', 'teamlead', 'senior_intern', 'seniorintern', 'project_manager', 'pm'];

$isHR     = in_array($userRoleVal, $rhRoles) || in_array($userDesigVal, $rhRoles);
$isLeader = in_array($userRoleVal, $leadRoles) || in_array($userDesigVal, $leadRoles);
$canViewDashboard = $isHR || $isLeader;

if (!$canViewDashboard) {
    echo "<script>alert('Access Denied: You do not have permission to view Meeting Analytics.'); window.location.href = 'meetingHistory.php';</script>";
    exit;
}

// ── GET FILTERS ──
$filterBranch = isset($_GET['branch']) ? trim((string)$_GET['branch']) : 'all';
$filterBatch  = isset($_GET['batch']) ? trim((string)$_GET['batch']) : 'all';
$filterRange  = isset($_GET['range']) ? trim((string)$_GET['range']) : '30days';
$startDateReq = isset($_GET['start_date']) ? trim((string)$_GET['start_date']) : '';
$endDateReq   = isset($_GET['end_date'])   ? trim((string)$_GET['end_date'])   : '';

// Calculate Date Window
$todayStr = date('Y-m-d');
if ($filterRange === 'today') {
    $startDate = $todayStr;
    $endDate   = $todayStr;
} elseif ($filterRange === '7days') {
    $startDate = date('Y-m-d', strtotime('-7 days'));
    $endDate   = $todayStr;
} elseif ($filterRange === '30days') {
    $startDate = date('Y-m-d', strtotime('-30 days'));
    $endDate   = $todayStr;
} elseif ($filterRange === 'this_month') {
    $startDate = date('Y-m-01');
    $endDate   = date('Y-m-t');
} elseif ($filterRange === 'custom' && !empty($startDateReq) && !empty($endDateReq)) {
    $startDate = $startDateReq;
    $endDate   = $endDateReq;
} else {
    $startDate = date('Y-m-d', strtotime('-30 days'));
    $endDate   = $todayStr;
}

// Fetch all Batches for filter dropdown
$batchesQ = mysqli_query($con, "SELECT id, batchName, batchCode FROM batches WHERE status='Active' OR status IS NULL ORDER BY batchName ASC");
$batchesList = [];
if ($batchesQ) {
    while ($b = mysqli_fetch_assoc($batchesQ)) $batchesList[] = $b;
}

// Fetch all Branches for filter dropdown
$branchesQ = mysqli_query($con, "SELECT DISTINCT branch FROM meetings WHERE branch IS NOT NULL AND TRIM(branch) != '' ORDER BY branch ASC");
$branchesList = [];
if ($branchesQ) {
    while ($br = mysqli_fetch_assoc($branchesQ)) $branchesList[] = $br['branch'];
}

// ── BUILD SQL CLAUSES FOR MEETINGS ──
$meetingWhere = "WHERE 1=1";
if ($filterBranch !== 'all') {
    $escapedBr = mysqli_real_escape_string($con, $filterBranch);
    $meetingWhere .= " AND (m.branch = '$escapedBr' OR m.branch = 'All')";
}

// Filter occurrences by date range
$occWhere = "WHERE o.occurrence_date BETWEEN '$startDate' AND '$endDate'";
if ($filterBranch !== 'all') {
    $escapedBr = mysqli_real_escape_string($con, $filterBranch);
    $occWhere .= " AND (m.branch = '$escapedBr' OR m.branch = 'All')";
}

// ── METRIC 1: MEETINGS & OCCURRENCES COUNTS ──
$totalMeetings = mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as cnt FROM meetings m $meetingWhere"))['cnt'] ?? 0;

$occStats = mysqli_fetch_assoc(mysqli_query($con, "
    SELECT 
        COUNT(o.id) as total_occs,
        SUM(CASE WHEN o.status = 'completed' THEN 1 ELSE 0 END) as completed_occs,
        SUM(CASE WHEN o.occurrence_date > '$todayStr' AND (o.status IS NULL OR o.status != 'cancelled') AND (m.status IS NULL OR m.status != 'cancelled') THEN 1 ELSE 0 END) as upcoming_occs,
        SUM(CASE WHEN o.occurrence_date <= '$todayStr' AND (o.status IS NULL OR o.status NOT IN ('completed', 'cancelled')) AND (m.status IS NULL OR m.status != 'cancelled') THEN 1 ELSE 0 END) as pending_occs
    FROM meeting_occurrences o
    JOIN meetings m ON m.id = o.meeting_id
    $occWhere
"));

$totalOccs     = (int)($occStats['total_occs'] ?? 0);
$completedOccs = (int)($occStats['completed_occs'] ?? 0);
$upcomingOccs  = (int)($occStats['upcoming_occs'] ?? 0);
$pendingOccs   = (int)($occStats['pending_occs'] ?? 0);

// ── METRIC 2: ATTENDANCE COUNTS & DISTRIBUTION ──
$attWhere = "WHERE 1=1";
if ($filterBranch !== 'all') {
    $escapedBr = mysqli_real_escape_string($con, $filterBranch);
    $attWhere .= " AND (m.branch = '$escapedBr' OR m.branch = 'All')";
}
if (!empty($startDate) && !empty($endDate)) {
    $attWhere .= " AND (DATE(ma.markedAt) BETWEEN '$startDate' AND '$endDate' OR ma.markedAt IS NULL)";
}

$attDistribution = mysqli_fetch_assoc(mysqli_query($con, "
    SELECT 
        SUM(CASE WHEN ma.status = 'present' THEN 1 ELSE 0 END) as cnt_present,
        SUM(CASE WHEN ma.status = 'absent' THEN 1 ELSE 0 END) as cnt_absent,
        SUM(CASE WHEN ma.status = 'absent_intimated' THEN 1 ELSE 0 END) as cnt_intimated
    FROM meeting_attendance ma
    JOIN meetings m ON m.id = CAST(ma.meetingId AS UNSIGNED)
    $attWhere
"));

$cntPresent   = (int)($attDistribution['cnt_present'] ?? 0);
$cntAbsent    = (int)($attDistribution['cnt_absent'] ?? 0);
$cntIntimated = (int)($attDistribution['cnt_intimated'] ?? 0);
$totalAttLogs = $cntPresent + $cntAbsent + $cntIntimated;

$overallAttRate = $totalAttLogs > 0 ? round(($cntPresent / $totalAttLogs) * 100, 1) : 0;

// ── METRIC 3: MOM COMPLIANCE RATE ──
$momStats = mysqli_fetch_assoc(mysqli_query($con, "
    SELECT COUNT(DISTINCT o.id) as mom_logged_count
    FROM meeting_mom mm
    JOIN meeting_occurrences o ON o.id = mm.occurrence_id
    JOIN meetings m ON m.id = o.meeting_id
    $occWhere AND mm.discussion_points IS NOT NULL AND TRIM(mm.discussion_points) != ''
"));
$momLoggedCount = (int)($momStats['mom_logged_count'] ?? 0);
$momComplianceRate = $completedOccs > 0 ? round(($momLoggedCount / $completedOccs) * 100, 1) : 0;

// ── METRIC 4: BATCH-WISE ATTENDANCE COMPARISON ──
$batchChartData = [];
$batchCompareQ = mysqli_query($con, "
    SELECT 
        COALESCE(NULLIF(TRIM(i.batch), ''), NULLIF(TRIM(s.batch_number), ''), 'Unassigned') AS batch_name,
        SUM(CASE WHEN ma.status = 'present' THEN 1 ELSE 0 END) AS present_cnt,
        SUM(CASE WHEN ma.status = 'absent' THEN 1 ELSE 0 END) AS absent_cnt,
        SUM(CASE WHEN ma.status = 'absent_intimated' THEN 1 ELSE 0 END) AS intimated_cnt,
        COUNT(ma.id) AS total_cnt
    FROM meeting_attendance ma
    LEFT JOIN signup s ON s.userId = ma.userId
    LEFT JOIN interns i ON (i.internId = ma.userId OR i.internId = s.internId)
    JOIN meetings m ON m.id = CAST(ma.meetingId AS UNSIGNED)
    $attWhere
    GROUP BY batch_name
    HAVING total_cnt > 0
    ORDER BY present_cnt DESC
");

if ($batchCompareQ) {
    while ($bRow = mysqli_fetch_assoc($batchCompareQ)) {
        $tot = (int)$bRow['total_cnt'];
        $pres = (int)$bRow['present_cnt'];
        $rate = $tot > 0 ? round(($pres / $tot) * 100, 1) : 0;
        $batchChartData[] = [
            'batch'      => $bRow['batch_name'],
            'present'    => $pres,
            'absent'     => (int)$bRow['absent_cnt'],
            'intimated'  => (int)$bRow['intimated_cnt'],
            'total'      => $tot,
            'rate'       => $rate
        ];
    }
}

// ── METRIC 5: PARTICIPANT ATTENDANCE BREAKDOWN TABLE ──
$participantStats = [];
$partWhere = "WHERE 1=1";
if ($filterBatch !== 'all') {
    $escapedBatch = mysqli_real_escape_string($con, strtolower(trim($filterBatch)));
    $partWhere .= " AND (LOWER(TRIM(i.batch)) = '$escapedBatch' OR LOWER(TRIM(s.batch_number)) = '$escapedBatch')";
}

$partQ = mysqli_query($con, "
    SELECT 
        ma.userId,
        MAX(COALESCE(NULLIF(s.fullName, ''), NULLIF(i.fullName, ''), ma.participantName, ma.userId)) AS fullName,
        MAX(COALESCE(NULLIF(s.emp_id, ''), s.internId, i.internId, '—')) AS display_id,
        MAX(COALESCE(NULLIF(TRIM(i.batch), ''), NULLIF(TRIM(s.batch_number), ''), 'Unassigned')) AS batch_name,
        SUM(CASE WHEN ma.status = 'present' THEN 1 ELSE 0 END) AS count_present,
        SUM(CASE WHEN ma.status = 'absent' THEN 1 ELSE 0 END) AS count_absent,
        SUM(CASE WHEN ma.status = 'absent_intimated' THEN 1 ELSE 0 END) AS count_intimated,
        COUNT(ma.id) AS total_invited,
        MAX(ma.markedAt) AS last_marked
    FROM meeting_attendance ma
    LEFT JOIN signup s ON s.userId = ma.userId
    LEFT JOIN interns i ON (i.internId = ma.userId OR i.internId = s.internId)
    $partWhere
    GROUP BY ma.userId
    ORDER BY count_absent DESC, count_present ASC
");

if ($partQ) {
    while ($p = mysqli_fetch_assoc($partQ)) {
        $tot  = (int)$p['total_invited'];
        $pres = (int)$p['count_present'];
        $abs  = (int)$p['count_absent'];
        $inti = (int)$p['count_intimated'];
        $rate = $tot > 0 ? round(($pres / $tot) * 100, 1) : 0;
        $p['att_rate'] = $rate;
        $participantStats[] = $p;
    }
}

// ── METRIC 6: HIGH ABSENTEEISM WATCHLIST (Absent rate >= 25% & >= 2 missed meetings) ──
$watchlist = array_filter($participantStats, function($p) {
    return ($p['count_absent'] >= 2) && (($p['count_absent'] / max(1, $p['total_invited'])) >= 0.25);
});

// CSV Export Action
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=meeting_attendance_report_' . date('Y-m-d') . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['User ID', 'Full Name', 'Display ID', 'Batch', 'Total Invited', 'Present', 'Absent', 'Absent (Intimated)', 'Attendance Rate (%)', 'Last Marked']);
    foreach ($participantStats as $row) {
        fputcsv($output, [
            $row['userId'],
            $row['fullName'],
            $row['display_id'],
            $row['batch_name'],
            $row['total_invited'],
            $row['count_present'],
            $row['count_absent'],
            $row['count_intimated'],
            $row['att_rate'] . '%',
            $row['last_marked'] ?: 'N/A'
        ]);
    }
    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>SETTribe · Meeting Analytics & Reports Dashboard</title>
    <?php include('../partials/_links.php'); ?>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #2f55ff;
            --primary-light: #eef1ff;
            --success: #15803d;
            --danger: #c62828;
            --warning: #b45309;
            --border: #e0e4ef;
            --radius: 12px;
        }
        body { background: #f4f6fa; font-family: "Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .dashboard-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; }
        .dashboard-title { font-size: 1.45rem; font-weight: 800; color: #1e293b; margin: 0; display: flex; align-items: center; gap: 10px; }
        .dashboard-subtitle { font-size: .83rem; color: #64748b; margin-top: 2px; }
        
        /* KPI Cards Grid */
        .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .kpi-card { background: #ffffff; border: 1px solid var(--border); border-radius: var(--radius); padding: 18px 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.03); transition: transform .2s ease, box-shadow .2s ease; position: relative; overflow: hidden; }
        .kpi-card:hover { transform: translateY(-3px); box-shadow: 0 8px 24px rgba(47,85,255,0.08); }
        .kpi-card::before { content: ''; position: absolute; top: 0; left: 0; width: 4px; height: 100%; background: var(--primary); }
        .kpi-card.kpi-success::before { background: #10b981; }
        .kpi-card.kpi-warning::before { background: #f59e0b; }
        .kpi-card.kpi-danger::before { background: #ef4444; }
        .kpi-card.kpi-info::before { background: #06b6d4; }
        
        .kpi-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
        .kpi-label { font-size: .78rem; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: #64748b; }
        .kpi-icon { width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1rem; background: var(--primary-light); color: var(--primary); }
        .kpi-card.kpi-success .kpi-icon { background: #d1fae5; color: #059669; }
        .kpi-card.kpi-warning .kpi-icon { background: #fef3c7; color: #d97706; }
        .kpi-card.kpi-danger .kpi-icon { background: #fee2e2; color: #dc2626; }
        .kpi-card.kpi-info .kpi-icon { background: #cff4fc; color: #0891b2; }

        .kpi-value { font-size: 1.75rem; font-weight: 800; color: #0f172a; line-height: 1.2; }
        .kpi-subtext { font-size: .75rem; color: #94a3b8; margin-top: 4px; }
        
        /* Filter Bar */
        .filter-card { background: #ffffff; border: 1px solid var(--border); border-radius: var(--radius); padding: 16px 20px; margin-bottom: 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.02); }
        .filter-form { display: flex; align-items: center; flex-wrap: wrap; gap: 14px; }
        .filter-group { display: flex; flex-direction: column; gap: 4px; }
        .filter-group label { font-size: .74rem; font-weight: 700; color: #475569; text-transform: uppercase; margin: 0; }
        .filter-control { border: 1px solid #cbd5e1; border-radius: 8px; padding: 6px 12px; font-size: .83rem; color: #334155; outline: none; background: #fff; transition: border-color .15s; }
        .filter-control:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(47,85,255,0.12); }
        .btn-filter-apply { background: var(--primary); color: #fff; border: none; border-radius: 8px; padding: 8px 18px; font-size: .83rem; font-weight: 600; cursor: pointer; transition: background .15s; display: inline-flex; align-items: center; gap: 6px; }
        .btn-filter-apply:hover { background: #1d4ed8; }
        .btn-export { background: #10b981; color: #fff; border: none; border-radius: 8px; padding: 8px 18px; font-size: .83rem; font-weight: 600; cursor: pointer; text-decoration: none; transition: background .15s; display: inline-flex; align-items: center; gap: 6px; margin-left: auto; }
        .btn-export:hover { background: #059669; color: #fff; text-decoration: none; }

        /* Charts Grid */
        .charts-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(420px, 1fr)); gap: 20px; margin-bottom: 24px; }
        .chart-card { background: #ffffff; border: 1px solid var(--border); border-radius: var(--radius); padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.02); }
        .chart-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; border-bottom: 1px solid #f1f5f9; padding-bottom: 10px; }
        .chart-title { font-size: .95rem; font-weight: 700; color: #1e293b; margin: 0; display: flex; align-items: center; gap: 8px; }
        .chart-container { position: relative; height: 260px; width: 100%; }

        /* Section Cards */
        .section-card { background: #ffffff; border: 1px solid var(--border); border-radius: var(--radius); padding: 20px; margin-bottom: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.02); }
        .section-card-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; border-bottom: 1px solid #f1f5f9; padding-bottom: 12px; }
        .section-card-title { font-size: 1.05rem; font-weight: 800; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 8px; }

        /* Table Styling */
        .data-table { width: 100%; border-collapse: collapse; font-size: .84rem; }
        .data-table th { background: #f8fafc; color: #475569; font-weight: 700; text-transform: uppercase; font-size: .73rem; padding: 10px 14px; border-bottom: 2px solid #e2e8f0; text-align: left; }
        .data-table td { padding: 12px 14px; border-bottom: 1px solid #f1f5f9; color: #334155; vertical-align: middle; }
        .data-table tr:hover { background: #f8fafc; }
        
        .progress-bar-wrap { width: 100%; background: #e2e8f0; height: 7px; border-radius: 10px; overflow: hidden; margin-top: 4px; }
        .progress-bar-fill { height: 100%; border-radius: 10px; transition: width .3s ease; }
        .progress-green { background: #10b981; }
        .progress-yellow { background: #f59e0b; }
        .progress-red { background: #ef4444; }

        .badge-rate { font-size: .74rem; font-weight: 700; padding: 2px 8px; border-radius: 12px; display: inline-block; }
        .rate-high { background: #d1fae5; color: #065f46; }
        .rate-medium { background: #fef3c7; color: #92400e; }
        .rate-low { background: #fee2e2; color: #991b1b; }

        /* Watchlist Item */
        .watchlist-item { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; border: 1px solid #fee2e2; background: #fff5f5; border-radius: 10px; margin-bottom: 10px; }
        .watchlist-user { display: flex; align-items: center; gap: 12px; }
        .watchlist-avatar { width: 36px; height: 36px; border-radius: 50%; background: #ef4444; color: #fff; font-weight: 700; font-size: .85rem; display: flex; align-items: center; justify-content: center; }
        .watchlist-name { font-weight: 700; color: #991b1b; font-size: .88rem; }
        .watchlist-meta { font-size: .75rem; color: #7f1d1d; }
    </style>
</head>
<body>
<div class="container-scroller">
    <?php include('../partials/_navbar.php'); ?>
    <div class="container-fluid page-body-wrapper">
        <?php include('../partials/_sidebar.php'); ?>
        <div class="main-panel">
            <div class="content-wrapper">

                <!-- Header -->
                <div class="dashboard-header">
                    <div>
                        <h3 class="dashboard-title"><i class="fas fa-chart-line" style="color:var(--primary);"></i> Meeting Analytics &amp; Performance Dashboard</h3>
                        <div class="dashboard-subtitle">Comprehensive Insights for Resource Managers, HR &amp; Executive Leadership</div>
                    </div>
                    <div style="display:flex; gap:10px;">
                        <a href="meetingComparisonReport.php" class="btn btn-outline-info btn-sm" style="border-radius:8px; font-weight:600;"><i class="fas fa-layer-group mr-1"></i> Recurring Comparison Report</a>
                        <a href="meetingHistory.php" class="btn btn-outline-primary btn-sm" style="border-radius:8px; font-weight:600;"><i class="fas fa-calendar-alt mr-1"></i> Meeting History / Calendar</a>
                        <a href="createMeeting.php" class="btn btn-primary btn-sm" style="border-radius:8px; font-weight:600;"><i class="fas fa-plus-circle mr-1"></i> Schedule Meeting</a>
                    </div>
                </div>

                <!-- Filter Bar -->
                <div class="filter-card">
                    <form method="GET" action="meetingDashboard.php" class="filter-form">
                        <div class="filter-group">
                            <label><i class="fas fa-calendar mr-1"></i> Date Range</label>
                            <select name="range" class="filter-control" onchange="toggleCustomDates(this.value)">
                                <option value="today" <?= $filterRange==='today'?'selected':'' ?>>Today</option>
                                <option value="7days" <?= $filterRange==='7days'?'selected':'' ?>>Last 7 Days</option>
                                <option value="30days" <?= $filterRange==='30days'?'selected':'' ?>>Last 30 Days</option>
                                <option value="this_month" <?= $filterRange==='this_month'?'selected':'' ?>>This Month</option>
                                <option value="custom" <?= $filterRange==='custom'?'selected':'' ?>>Custom Range</option>
                            </select>
                        </div>

                        <div class="filter-group" id="customDateStartGroup" style="<?= $filterRange==='custom'?'':'display:none;' ?>">
                            <label>Start Date</label>
                            <input type="date" name="start_date" class="filter-control" value="<?= htmlspecialchars($startDate) ?>">
                        </div>

                        <div class="filter-group" id="customDateEndGroup" style="<?= $filterRange==='custom'?'':'display:none;' ?>">
                            <label>End Date</label>
                            <input type="date" name="end_date" class="filter-control" value="<?= htmlspecialchars($endDate) ?>">
                        </div>

                        <div class="filter-group">
                            <label><i class="fas fa-building mr-1"></i> Branch</label>
                            <select name="branch" class="filter-control">
                                <option value="all">All Branches</option>
                                <?php foreach ($branchesList as $br): ?>
                                <option value="<?= htmlspecialchars($br) ?>" <?= $filterBranch===$br?'selected':'' ?>><?= htmlspecialchars($br) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label><i class="fas fa-layer-group mr-1"></i> Batch</label>
                            <select name="batch" class="filter-control">
                                <option value="all">All Batches</option>
                                <?php foreach ($batchesList as $b): ?>
                                <option value="<?= htmlspecialchars($b['batchName']) ?>" <?= $filterBatch===$b['batchName']?'selected':'' ?>><?= htmlspecialchars($b['batchName']) ?> (<?= htmlspecialchars($b['batchCode']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-group" style="align-self:flex-end;">
                            <button type="submit" class="btn-filter-apply"><i class="fas fa-filter"></i> Apply Filters</button>
                        </div>

                        <a href="meetingDashboard.php?action=export_csv&<?= http_build_query($_GET) ?>" class="btn-export" title="Download Excel/CSV Report">
                            <i class="fas fa-file-csv"></i> Export CSV Report
                        </a>
                    </form>
                </div>

                <!-- KPI Cards Grid -->
                <div class="kpi-grid">
                    <div class="kpi-card kpi-info">
                        <div class="kpi-header">
                            <span class="kpi-label">Scheduled Meetings</span>
                            <div class="kpi-icon"><i class="fas fa-calendar-check"></i></div>
                        </div>
                        <div class="kpi-value"><?= number_format($totalMeetings) ?></div>
                        <div class="kpi-subtext"><?= number_format($totalOccs) ?> total session slots</div>
                    </div>

                    <div class="kpi-card kpi-success">
                        <div class="kpi-header">
                            <span class="kpi-label">Overall Attendance Rate</span>
                            <div class="kpi-icon"><i class="fas fa-user-check"></i></div>
                        </div>
                        <div class="kpi-value"><?= $overallAttRate ?>%</div>
                        <div class="kpi-subtext"><?= number_format($cntPresent) ?> present out of <?= number_format($totalAttLogs) ?> logged</div>
                    </div>

                    <div class="kpi-card kpi-warning">
                        <div class="kpi-header">
                            <span class="kpi-label">Absent &amp; Intimated</span>
                            <div class="kpi-icon"><i class="fas fa-user-times"></i></div>
                        </div>
                        <div class="kpi-value"><?= number_format($cntAbsent + $cntIntimated) ?></div>
                        <div class="kpi-subtext"><?= number_format($cntAbsent) ?> unexcused · <?= number_format($cntIntimated) ?> intimated</div>
                    </div>

                    <div class="kpi-card kpi-danger">
                        <div class="kpi-header">
                            <span class="kpi-label">MOM Finalization Rate</span>
                            <div class="kpi-icon"><i class="fas fa-clipboard-check"></i></div>
                        </div>
                        <div class="kpi-value"><?= $momComplianceRate ?>%</div>
                        <div class="kpi-subtext"><?= number_format($momLoggedCount) ?> / <?= number_format($completedOccs) ?> completed sessions</div>
                    </div>
                </div>

                <!-- Charts Grid -->
                <div class="charts-grid">
                    <!-- Donut Chart: Attendance Distribution -->
                    <div class="chart-card">
                        <div class="chart-header">
                            <h5 class="chart-title"><i class="fas fa-chart-pie" style="color:#0891b2;"></i> Attendance Status Breakdown</h5>
                        </div>
                        <div class="chart-container">
                            <canvas id="chartAttendanceDist"></canvas>
                        </div>
                    </div>

                    <!-- Bar Chart: Batch-wise Comparison -->
                    <div class="chart-card">
                        <div class="chart-header">
                            <h5 class="chart-title"><i class="fas fa-chart-bar" style="color:var(--primary);"></i> Batch-wise Attendance Comparison (%)</h5>
                        </div>
                        <div class="chart-container">
                            <canvas id="chartBatchCompare"></canvas>
                        </div>
                    </div>
                </div>

                <!-- High Absenteeism Watchlist Section -->
                <?php if (!empty($watchlist)): ?>
                <div class="section-card" style="border-left:4px solid #ef4444;">
                    <div class="section-card-header">
                        <h4 class="section-card-title" style="color:#991b1b;"><i class="fas fa-exclamation-triangle" style="color:#ef4444;"></i> High Absenteeism Watchlist (&ge;25% Missed Meetings)</h4>
                        <span class="badge badge-danger"><?= count($watchlist) ?> Interns/Employees Alert</span>
                    </div>
                    <div>
                        <?php foreach ($watchlist as $item): ?>
                        <div class="watchlist-item">
                            <div class="watchlist-user">
                                <div class="watchlist-avatar"><?= strtoupper(substr($item['fullName'], 0, 1)) ?></div>
                                <div>
                                    <div class="watchlist-name"><?= htmlspecialchars($item['fullName']) ?> <small class="text-muted">(<?= htmlspecialchars($item['display_id']) ?>)</small></div>
                                    <div class="watchlist-meta">Batch: <strong><?= htmlspecialchars($item['batch_name']) ?></strong> · Attendance Rate: <strong><?= $item['att_rate'] ?>%</strong></div>
                                </div>
                            </div>
                            <div>
                                <span class="badge badge-danger" style="font-size:.78rem; padding:4px 10px;"><?= $item['count_absent'] ?> Unexcused Absences</span>
                                <?php if ($item['count_intimated'] > 0): ?>
                                <span class="badge badge-warning" style="font-size:.78rem; padding:4px 10px;"><?= $item['count_intimated'] ?> Intimated</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Participant Attendance Breakdown Table -->
                <div class="section-card">
                    <div class="section-card-header">
                        <h4 class="section-card-title"><i class="fas fa-users-cog" style="color:var(--primary);"></i> Intern &amp; Employee Meeting Performance Report</h4>
                        <span class="text-muted" style="font-size:.8rem;"><?= count($participantStats) ?> total records found</span>
                    </div>
                    <div class="table-responsive">
                        <table class="data-table" id="participantTable">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Participant Name</th>
                                    <th>ID</th>
                                    <th>Batch</th>
                                    <th>Invited</th>
                                    <th>Present</th>
                                    <th>Absent</th>
                                    <th>Intimated</th>
                                    <th>Attendance Rate</th>
                                    <th>Last Activity</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($participantStats as $idx => $row): 
                                    $rate = $row['att_rate'];
                                    $barClass = $rate >= 85 ? 'progress-green' : ($rate >= 70 ? 'progress-yellow' : 'progress-red');
                                    $badgeClass = $rate >= 85 ? 'rate-high' : ($rate >= 70 ? 'rate-medium' : 'rate-low');
                                ?>
                                <tr>
                                    <td class="text-muted"><?= $idx + 1 ?></td>
                                    <td><strong><?= htmlspecialchars($row['fullName']) ?></strong></td>
                                    <td><code><?= htmlspecialchars($row['display_id']) ?></code></td>
                                    <td><span class="badge badge-light" style="border:1px solid #cbd5e1;"><?= htmlspecialchars($row['batch_name']) ?></span></td>
                                    <td><strong><?= $row['total_invited'] ?></strong></td>
                                    <td><span class="text-success font-weight-bold"><?= $row['count_present'] ?></span></td>
                                    <td><span class="text-danger font-weight-bold"><?= $row['count_absent'] ?></span></td>
                                    <td><span class="text-warning font-weight-bold"><?= $row['count_intimated'] ?></span></td>
                                    <td style="min-width:140px;">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <span class="badge-rate <?= $badgeClass ?>"><?= $rate ?>%</span>
                                        </div>
                                        <div class="progress-bar-wrap">
                                            <div class="progress-bar-fill <?= $barClass ?>" style="width: <?= min(100, max(5, $rate)) ?>%;"></div>
                                        </div>
                                    </td>
                                    <td class="text-muted" style="font-size:.78rem;"><?= $row['last_marked'] ? date('d M Y, h:i A', strtotime($row['last_marked'])) : '—' ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<script src="../vendors/js/vendor.bundle.base.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>

<script>
function toggleCustomDates(val) {
    var sGrp = document.getElementById('customDateStartGroup');
    var eGrp = document.getElementById('customDateEndGroup');
    if (val === 'custom') {
        sGrp.style.display = 'flex';
        eGrp.style.display = 'flex';
    } else {
        sGrp.style.display = 'none';
        eGrp.style.display = 'none';
    }
}

$(document).ready(function() {
    $('#participantTable').DataTable({
        "pageLength": 15,
        "order": [[ 6, "desc" ]], // Sort by Absent count descending by default
        "language": {
            "search": "Search Participant / ID / Batch:"
        }
    });

    // ── Chart 1: Attendance Distribution (Donut Chart) ──
    var ctxDist = document.getElementById('chartAttendanceDist').getContext('2d');
    new Chart(ctxDist, {
        type: 'doughnut',
        data: {
            labels: ['Present', 'Absent (Unexcused)', 'Absent (Intimated)'],
            datasets: [{
                data: [<?= $cntPresent ?>, <?= $cntAbsent ?>, <?= $cntIntimated ?>],
                backgroundColor: ['#10b981', '#ef4444', '#f59e0b'],
                borderWidth: 2,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' }
            }
        }
    });

    // ── Chart 2: Batch Comparison (Bar Chart) ──
    var batchLabels = <?= json_encode(array_column($batchChartData, 'batch')) ?>;
    var batchRates  = <?= json_encode(array_column($batchChartData, 'rate')) ?>;

    var ctxBatch = document.getElementById('chartBatchCompare').getContext('2d');
    new Chart(ctxBatch, {
        type: 'bar',
        data: {
            labels: batchLabels.length ? batchLabels : ['No Batch Data'],
            datasets: [{
                label: 'Attendance Rate (%)',
                data: batchRates.length ? batchRates : [0],
                backgroundColor: 'rgba(47, 85, 255, 0.75)',
                borderColor: 'var(--primary)',
                borderWidth: 1.5,
                borderRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { beginAtZero: true, max: 100, title: { display: true, text: 'Attendance Rate (%)' } },
                x: { title: { display: true, text: 'Batches' } }
            },
            plugins: {
                legend: { display: false }
            }
        }
    });
});
</script>
</body>
</html>
