<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

include('../include/db_config.php');

$userId   = $_SESSION['userId'];
$userRole = strtolower(str_replace(['_', ' '], '', $_SESSION['userRole'] ?? ''));
$designation = strtolower(str_replace(['_', ' '], '', $_SESSION['designation'] ?? ''));

// ── Determine viewer mode ─────────────────────────────────────────────────────
// Admin roles see the overall team analytics
$adminRoles = ['admin', 'hr', 'md', 'manager', 'departmenthead', 'resourceallocator'];
$isAdminView = in_array($userRole, $adminRoles) || in_array($designation, $adminRoles);

// If admin explicitly passes ?view=self they can also see their own self-analysis
$viewMode = $_GET['view'] ?? ($isAdminView ? 'admin' : 'self');

// Fetch current logged-in user info
$userRes = mysqli_query($con, "SELECT fullName, designation, userRole FROM signup WHERE userId = '" . mysqli_real_escape_string($con, $userId) . "' LIMIT 1");
$userRow  = mysqli_fetch_assoc($userRes);
$userName = htmlspecialchars($userRow['fullName'] ?? 'User');

// Fetch the target employee's name (when admin drills into a specific employee)
$viewedEmpId   = (isset($_GET['emp_id']) && !empty($_GET['emp_id'])) ? mysqli_real_escape_string($con, $_GET['emp_id']) : null;
$viewedEmpName = $userName; // default to self
if ($viewedEmpId && $viewedEmpId !== $userId) {
    $empRes = mysqli_query($con, "SELECT fullName, designation FROM signup WHERE userId = '$viewedEmpId' LIMIT 1");
    if ($empRow = mysqli_fetch_assoc($empRes)) {
        $viewedEmpName = htmlspecialchars($empRow['fullName']);
        $viewedEmpDesig = htmlspecialchars($empRow['designation'] ?? '');
    }
}

// ── Date Range Filter ─────────────────────────────────────────────────────────
$range     = $_GET['range'] ?? 'this_month';
$startDate = date('Y-m-01');
$endDate   = date('Y-m-t');

if ($range === 'last_month') {
    $startDate = date('Y-m-01', strtotime('first day of last month'));
    $endDate   = date('Y-m-t',  strtotime('last day of last month'));
} elseif ($range === 'last_30') {
    $startDate = date('Y-m-d', strtotime('-30 days'));
    $endDate   = date('Y-m-d');
} elseif ($range === 'all') {
    $startDate = '2020-01-01';
    $endDate   = date('Y-m-d');
} elseif (!empty($_GET['start_date']) && !empty($_GET['end_date'])) {
    $startDate = mysqli_real_escape_string($con, $_GET['start_date']);
    $endDate   = mysqli_real_escape_string($con, $_GET['end_date']);
    $range     = 'custom';
}

// ──────────────────────────────────────────────────────────────────────────────
// SELF-ANALYSIS DATA (for individual user)
// ──────────────────────────────────────────────────────────────────────────────
// If admin drills into a specific employee via ?emp_id=..., use that; otherwise use the logged-in user
$targetUserId = (isset($_GET['emp_id']) && !empty($_GET['emp_id'])) 
    ? mysqli_real_escape_string($con, $_GET['emp_id']) 
    : $userId;

$summaryQuery = mysqli_query($con, "
    SELECT 
        SUM(CASE WHEN LOWER(status) IN ('completed', 'pending') THEN 1 ELSE 0 END) as totalTasks,
        SUM(CASE WHEN LOWER(status) = 'completed' THEN 1 ELSE 0 END) as completedTasks,
        SUM(CASE WHEN LOWER(status) = 'pending' THEN 1 ELSE 0 END) as pendingTasks,
        SUM(CASE WHEN LOWER(status) IN ('completed', 'pending') AND task_type = 'Planned' THEN 1 ELSE 0 END) as plannedTasks,
        SUM(CASE WHEN LOWER(status) IN ('completed', 'pending') AND task_type = 'Unplanned' THEN 1 ELSE 0 END) as unplannedTasks,
        COUNT(DISTINCT date) as activeDays
    FROM task_diary 
    WHERE userId = '$targetUserId'
      AND date BETWEEN '$startDate' AND '$endDate'
      AND (is_deleted = 0 OR is_deleted IS NULL)
");
$stats = mysqli_fetch_assoc($summaryQuery) ?? [];

// Fetch latest carry forward count for the target employee in the selected period (on their most recent active date)
$carriedQuery = mysqli_query($con, "
    SELECT COUNT(*) as latestCarried
    FROM task_diary
    WHERE userId = '$targetUserId'
      AND date = (
          SELECT MAX(date)
          FROM task_diary
          WHERE userId = '$targetUserId'
            AND date BETWEEN '$startDate' AND '$endDate'
      )
      AND carried_from_date IS NOT NULL
      AND carried_from_date > '1000-01-01'
      AND LOWER(status) IN ('completed', 'pending')
      AND (is_deleted = 0 OR is_deleted IS NULL)
");
$carriedRow = mysqli_fetch_assoc($carriedQuery) ?? [];

$totalTasks     = (int)($stats['totalTasks'] ?? 0);
$completedTasks = (int)($stats['completedTasks'] ?? 0);
$pendingTasks   = (int)($stats['pendingTasks'] ?? 0);
$plannedTasks   = (int)($stats['plannedTasks'] ?? 0);
$unplannedTasks = (int)($stats['unplannedTasks'] ?? 0);
$carriedTasks   = (int)($carriedRow['latestCarried'] ?? 0);
$activeDays     = (int)($stats['activeDays'] ?? 0);
$completionRate = $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100, 1) : 0;
$avgDailyTasks  = $activeDays > 0 ? round($totalTasks / $activeDays, 1) : 0;
$plannedPct     = $totalTasks > 0 ? round(($plannedTasks / $totalTasks) * 100) : 0;

// Daily breakdown for self-analysis
$dailyQuery = mysqli_query($con, "
    SELECT date,
        SUM(CASE WHEN LOWER(status) IN ('completed', 'pending') THEN 1 ELSE 0 END) as total,
        SUM(CASE WHEN LOWER(status) = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN LOWER(status) = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN LOWER(status) IN ('completed', 'pending') AND task_type = 'Planned' THEN 1 ELSE 0 END) as planned,
        SUM(CASE WHEN LOWER(status) IN ('completed', 'pending') AND task_type = 'Unplanned' THEN 1 ELSE 0 END) as unplanned,
        SUM(CASE WHEN carried_from_date IS NOT NULL AND carried_from_date > '1000-01-01' AND LOWER(status) IN ('completed', 'pending') THEN 1 ELSE 0 END) as carried
    FROM task_diary 
    WHERE userId = '$targetUserId'
      AND date BETWEEN '$startDate' AND '$endDate'
      AND (is_deleted = 0 OR is_deleted IS NULL)
    GROUP BY date ORDER BY date ASC
");
$dailyData = [];
$chartDates = $chartCompleted = $chartPending = [];
if ($dailyQuery) {
    while ($row = mysqli_fetch_assoc($dailyQuery)) {
        $dailyData[]      = $row;
        $chartDates[]     = date('d M', strtotime($row['date']));
        $chartCompleted[] = (int)$row['completed'];
        $chartPending[]   = (int)$row['pending'];
    }
}

// ──────────────────────────────────────────────────────────────────────────────
// ADMIN / TEAM-WIDE ANALYTICS DATA
// ──────────────────────────────────────────────────────────────────────────────
$teamData = [];
$overallTotal = $overallCompleted = $overallPending = $overallPlanned = $overallUnplanned = 0;
$empBarNames = $empBarCompleted = $empBarPending = [];

if ($viewMode === 'admin') {
    // Per-employee aggregated stats
    $teamQuery = mysqli_query($con, "
        SELECT s.userId, s.fullName, s.designation,
            SUM(CASE WHEN LOWER(td.status) IN ('completed', 'pending') THEN 1 ELSE 0 END) as totalTasks,
            SUM(CASE WHEN LOWER(td.status) = 'completed' THEN 1 ELSE 0 END) as completedTasks,
            SUM(CASE WHEN LOWER(td.status) = 'pending' THEN 1 ELSE 0 END) as pendingTasks,
            SUM(CASE WHEN LOWER(td.status) IN ('completed', 'pending') AND td.task_type = 'Planned' THEN 1 ELSE 0 END) as plannedTasks,
            SUM(CASE WHEN LOWER(td.status) IN ('completed', 'pending') AND td.task_type = 'Unplanned' THEN 1 ELSE 0 END) as unplannedTasks,
            COUNT(DISTINCT td.date) as activeDays
        FROM signup s
        LEFT JOIN task_diary td ON s.userId COLLATE utf8mb4_unicode_ci = td.userId COLLATE utf8mb4_unicode_ci
            AND td.date BETWEEN '$startDate' AND '$endDate'
            AND (td.is_deleted = 0 OR td.is_deleted IS NULL)
        WHERE s.status = 'Active'
        GROUP BY s.userId, s.fullName, s.designation
        HAVING totalTasks > 0
        ORDER BY completedTasks DESC
    ");

    if ($teamQuery) {
        while ($r = mysqli_fetch_assoc($teamQuery)) {
            $r['completionRate'] = $r['totalTasks'] > 0 ? round(($r['completedTasks'] / $r['totalTasks']) * 100, 1) : 0;
            $r['avgDaily'] = $r['activeDays'] > 0 ? round($r['totalTasks'] / $r['activeDays'], 1) : 0;
            $teamData[] = $r;

            $overallTotal     += (int)$r['totalTasks'];
            $overallCompleted += (int)$r['completedTasks'];
            $overallPending   += (int)$r['pendingTasks'];
            $overallPlanned   += (int)$r['plannedTasks'];
            $overallUnplanned += (int)$r['unplannedTasks'];

            // Chart data (top 10 by name)
            if (count($empBarNames) < 10) {
                $empBarNames[]     = $r['fullName'];
                $empBarCompleted[] = (int)$r['completedTasks'];
                $empBarPending[]   = (int)$r['pendingTasks'];
            }
        }
    }

    $overallCompletionRate = $overallTotal > 0 ? round(($overallCompleted / $overallTotal) * 100, 1) : 0;
    $totalActiveEmployees  = count($teamData);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <title><?= $viewMode === 'admin' ? 'Team Task Diary Overview – Admin Analytics' : 'Task Diary Analytics & Self Analysis' ?></title>
    <?php include('../partials/_links.php'); ?>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        body { background-color: #f4f6fb !important; }

        .page-header-box {
            background: #fff;
            border-radius: 14px;
            padding: 22px 26px;
            border: 1px solid #e2e8f0;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
        }

        .kpi-card {
            background: #fff;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            padding: 16px 20px;
            transition: all 0.2s ease;
            height: 100%;
            box-shadow: 0 2px 6px rgba(0,0,0,0.03);
        }
        .kpi-card:hover { transform: translateY(-2px); box-shadow: 0 8px 18px rgba(0,0,0,0.07); }
        .kpi-icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0; }
        .kpi-val { font-size: 1.55rem; font-weight: 800; color: #0f172a; line-height: 1.1; margin-top: 4px; }
        .kpi-lbl { font-size: 0.72rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; }
        .kpi-sub { font-size: 0.72rem; color: #94a3b8; margin-top: 3px; }

        .progress-card {
            background: #fff;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            padding: 18px 22px;
            margin-bottom: 24px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.03);
        }
        .progress-bar-wrap { height: 10px; background: #e2e8f0; border-radius: 99px; overflow: hidden; margin-top: 10px; }
        .progress-bar-fill { height: 100%; border-radius: 99px; background: linear-gradient(90deg, #4b49ac 0%, #6366f1 100%); transition: width 0.6s ease; }

        .insight-card {
            background: linear-gradient(135deg, #eef2ff 0%, #f5f3ff 100%);
            border: 1px solid #c7d2fe;
            border-radius: 14px;
            padding: 20px 24px;
            margin-bottom: 24px;
        }

        .card-chart {
            background: #fff;
            border-radius: 14px;
            border: 1px solid #e2e8f0;
            padding: 20px 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
            height: 100%;
        }

        .card-table {
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            background: #fff;
            padding: 22px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
            margin-bottom: 24px;
        }
        .table thead th {
            background-color: #f8fafc !important;
            color: #475569 !important;
            font-size: 0.74rem !important;
            font-weight: 700 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.04em !important;
            padding: 12px 14px !important;
            border-bottom: 2px solid #e2e8f0 !important;
        }
        .table tbody td { vertical-align: middle !important; font-size: 0.84rem !important; padding: 11px 14px !important; border-bottom: 1px solid #f1f5f9 !important; color: #334155; }
        .table tbody tr:hover { background-color: #f8fafc !important; }

        .filter-btn {
            border: 1px solid #cbd5e1; background: #fff; color: #475569; font-weight: 600; font-size: 0.78rem;
            padding: 6px 14px; border-radius: 8px; cursor: pointer; text-decoration: none; transition: all 0.15s;
        }
        .filter-btn:hover { background: #f8fafc; color: #0f172a; text-decoration: none; }
        .filter-btn.active { background: #4b49ac; color: #fff; border-color: #4b49ac; }

        .view-toggle-btn {
            border: 1px solid #c7d2fe; background: #eef2ff; color: #4b49ac; font-weight: 700; font-size: 0.78rem;
            padding: 6px 16px; border-radius: 8px; cursor: pointer; text-decoration: none; transition: all 0.15s;
        }
        .view-toggle-btn:hover { background: #4b49ac; color: #fff; border-color: #4b49ac; text-decoration: none; }

        /* Admin table completion bar */
        .emp-completion-bar { height: 6px; border-radius: 99px; background: #e2e8f0; width: 100%; }
        .emp-completion-fill { height: 100%; border-radius: 99px; background: linear-gradient(90deg, #10b981, #34d399); }

        .designation-badge {
            font-size: 0.7rem; font-weight: 600; padding: 2px 8px; border-radius: 12px;
            background: #f1f5f9; color: #475569; display: inline-block;
        }
    </style>
</head>

<body>
    <div class="container-scroller">
        <?php include('../partials/_navbar.php'); ?>
        <div class="container-fluid page-body-wrapper">
            <?php include('../partials/_sidebar.php'); ?>

            <div class="main-panel">
                <div class="content-wrapper" style="background:#f4f6fb !important;">

                    <!-- ── Header Banner ────────────────────────────────────────────────── -->
                    <div class="page-header-box d-flex align-items-center justify-content-between flex-wrap gap-3">
                        <div>
                            <h3 class="fw-bold text-dark mb-1" style="font-size:1.35rem;">
                                <?php if ($viewMode === 'admin'): ?>
                                    <i class="fa-solid fa-users text-primary me-2"></i> Team Task Diary — Admin Overview
                                <?php else: ?>
                                    <i class="fa-solid fa-chart-pie text-primary me-2"></i>
                                    <?= htmlspecialchars($viewedEmpName) ?>'s Task Diary Analysis
                                <?php endif; ?>
                            </h3>
                            <p class="text-muted small mb-0">
                                <?php if ($viewMode === 'admin'): ?>
                                    Overall team-wide productivity analytics, per-employee completion tracking, and workload distribution.
                                <?php else: ?>
                                    <?php if ($viewedEmpId && $viewedEmpId !== $userId): ?>
                                        Viewing task diary analytics for <strong><?= htmlspecialchars($viewedEmpName) ?></strong><?= !empty($viewedEmpDesig) ? ' — ' . htmlspecialchars($viewedEmpDesig) : '' ?>.
                                    <?php else: ?>
                                        Analyze your personal task completion rate, planned vs. unplanned workload, and productivity trends.
                                    <?php endif; ?>
                                <?php endif; ?>
                            </p>
                        </div>

                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <!-- Range Filters (preserve emp_id if drilling into an employee) -->
                            <?php $empParam = $viewedEmpId ? '&emp_id=' . $viewedEmpId : ''; ?>
                            <a href="?view=<?= $viewMode ?>&range=this_month<?= $empParam ?>" class="filter-btn <?= $range === 'this_month' ? 'active' : '' ?>">This Month</a>
                            <a href="?view=<?= $viewMode ?>&range=last_month<?= $empParam ?>" class="filter-btn <?= $range === 'last_month' ? 'active' : '' ?>">Last Month</a>
                            <a href="?view=<?= $viewMode ?>&range=last_30<?= $empParam ?>" class="filter-btn <?= $range === 'last_30' ? 'active' : '' ?>">Last 30 Days</a>
                            <a href="?view=<?= $viewMode ?>&range=all<?= $empParam ?>" class="filter-btn <?= $range === 'all' ? 'active' : '' ?>">All Time</a>

                            <?php if ($isAdminView && $viewMode === 'self'): ?>
                                <!-- Back to team overview when admin drills into an employee -->
                                <a href="?view=admin&range=<?= $range ?>" class="view-toggle-btn ms-1">
                                    <i class="fa-solid fa-arrow-left me-1"></i> Back to Team Overview
                                </a>
                            <?php endif; ?>

                            <?php if (!$isAdminView): ?>
                            <a href="taskDiary.php" class="btn btn-outline-primary btn-sm px-3 ms-1" style="border-radius:8px; font-weight:600;">
                                <i class="fa-solid fa-book-open me-1"></i> Open Task Diary
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($viewMode === 'admin'): ?>
                    <!-- ════════════════════════════════════════════════════════════════════
                         ADMIN / TEAM OVERVIEW MODE
                    ════════════════════════════════════════════════════════════════════ -->

                    <!-- Team KPI Summary Cards -->
                    <div class="row mb-4 g-3">
                        <div class="col-md-2 col-sm-4 col-6">
                            <div class="kpi-card">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div class="kpi-lbl">Active Employees</div>
                                    <div class="kpi-icon" style="background:#EEF2FF; color:#4B49AC;"><i class="fa-solid fa-users"></i></div>
                                </div>
                                <div class="kpi-val"><?= $totalActiveEmployees ?></div>
                                <div class="kpi-sub">Employees with logs</div>
                            </div>
                        </div>

                        <div class="col-md-2 col-sm-4 col-6">
                            <div class="kpi-card">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div class="kpi-lbl">Total Logged</div>
                                    <div class="kpi-icon" style="background:#F0F9FF; color:#0284C7;"><i class="fa-solid fa-book-bookmark"></i></div>
                                </div>
                                <div class="kpi-val" style="color:#0284C7;"><?= $overallTotal ?></div>
                                <div class="kpi-sub">Tasks logged by all</div>
                            </div>
                        </div>

                        <div class="col-md-2 col-sm-4 col-6">
                            <div class="kpi-card">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div class="kpi-lbl">Completed</div>
                                    <div class="kpi-icon" style="background:#E6F4EA; color:#1E8E3E;"><i class="fa-solid fa-circle-check"></i></div>
                                </div>
                                <div class="kpi-val text-success"><?= $overallCompleted ?></div>
                                <div class="kpi-sub"><?= $overallCompletionRate ?>% completion</div>
                            </div>
                        </div>

                        <div class="col-md-2 col-sm-4 col-6">
                            <div class="kpi-card">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div class="kpi-lbl">Pending</div>
                                    <div class="kpi-icon" style="background:#FEF7E0; color:#B06000;"><i class="fa-solid fa-hourglass-half"></i></div>
                                </div>
                                <div class="kpi-val" style="color:#B06000;"><?= $overallPending ?></div>
                                <div class="kpi-sub">Unfinished items</div>
                            </div>
                        </div>

                        <div class="col-md-2 col-sm-4 col-6">
                            <div class="kpi-card">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div class="kpi-lbl">Planned Tasks</div>
                                    <div class="kpi-icon" style="background:#E0F2FE; color:#0284C7;"><i class="fa-solid fa-calendar-check"></i></div>
                                </div>
                                <div class="kpi-val" style="color:#0284C7;"><?= $overallPlanned ?></div>
                                <div class="kpi-sub">Pre-planned entries</div>
                            </div>
                        </div>

                        <div class="col-md-2 col-sm-4 col-6">
                            <div class="kpi-card">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div class="kpi-lbl">Unplanned Tasks</div>
                                    <div class="kpi-icon" style="background:#FEE2E2; color:#DC2626;"><i class="fa-solid fa-bolt"></i></div>
                                </div>
                                <div class="kpi-val text-danger"><?= $overallUnplanned ?></div>
                                <div class="kpi-sub">Reactive/ad-hoc</div>
                            </div>
                        </div>
                    </div>

                    <!-- Overall Completion Bar -->
                    <div class="progress-card">
                        <div class="d-flex align-items-center justify-content-between">
                            <span class="fw-bold text-dark" style="font-size:0.85rem;">
                                <i class="fa-solid fa-award text-primary me-2"></i> Overall Team Completion Rate (<?= date('d M', strtotime($startDate)) ?> – <?= date('d M Y', strtotime($endDate)) ?>)
                            </span>
                            <span class="fw-bold" style="font-size:0.85rem; color:#4b49ac;">
                                <?= $overallCompleted ?> / <?= $overallTotal ?> Tasks (<?= $overallCompletionRate ?>%)
                            </span>
                        </div>
                        <div class="progress-bar-wrap">
                            <div class="progress-bar-fill" style="width: <?= $overallCompletionRate ?>%;"></div>
                        </div>
                    </div>

                    <!-- Charts Row -->
                    <div class="row mb-4 g-3">
                        <!-- Per-Employee Completed vs Pending Bar Chart -->
                        <div class="col-md-8">
                            <div class="card-chart">
                                <h5 class="fw-bold text-dark mb-3" style="font-size:1rem;">
                                    <i class="fa-solid fa-chart-column me-2 text-primary"></i> Employee Completion vs. Pending Tasks (Top 10)
                                </h5>
                                <div style="height:300px; position:relative;">
                                    <canvas id="teamBarChart"></canvas>
                                </div>
                            </div>
                        </div>

                        <!-- Planned vs Unplanned Doughnut -->
                        <div class="col-md-4">
                            <div class="card-chart">
                                <h5 class="fw-bold text-dark mb-3" style="font-size:1rem;">
                                    <i class="fa-solid fa-chart-pie me-2 text-primary"></i> Team Planned vs. Unplanned Split
                                </h5>
                                <div style="height:300px; position:relative;" class="d-flex align-items-center justify-content-center">
                                    <canvas id="teamTypeChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Per-Employee Table -->
                    <div class="card-table">
                        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                            <div>
                                <h4 class="fw-bold mb-0 text-dark" style="font-size:1.15rem;">
                                    <i class="fa-solid fa-table-list me-2 text-primary"></i> Per-Employee Productivity Breakdown
                                </h4>
                                <small class="text-muted">Individual task logging, completion rates, and workload analysis for the selected period.</small>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover w-100" id="teamTable">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Employee Name</th>
                                        <th>Designation</th>
                                        <th class="text-center">Total Logged</th>
                                        <th class="text-center">Completed</th>
                                        <th class="text-center">Pending</th>
                                        <th class="text-center">Planned</th>
                                        <th class="text-center">Unplanned</th>
                                        <th class="text-center">Active Days</th>
                                        <th style="min-width:130px;">Completion %</th>
                                        <th class="text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($teamData as $idx => $emp): ?>
                                    <tr>
                                        <td class="text-muted fw-bold"><?= $idx + 1 ?></td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div style="width:32px; height:32px; border-radius:50%; background:#e2e8f0; display:flex; align-items:center; justify-content:center; font-weight:700; color:#475569; flex-shrink:0; font-size:0.85rem;">
                                                    <?= strtoupper(substr($emp['fullName'], 0, 1)) ?>
                                                </div>
                                                <span class="fw-bold text-dark" style="font-size:0.85rem;"><?= htmlspecialchars($emp['fullName']) ?></span>
                                            </div>
                                        </td>
                                        <td><span class="designation-badge"><?= htmlspecialchars($emp['designation']) ?></span></td>
                                        <td class="text-center fw-bold text-primary"><?= $emp['totalTasks'] ?></td>
                                        <td class="text-center"><span class="badge bg-success text-white px-2 py-1"><?= $emp['completedTasks'] ?></span></td>
                                        <td class="text-center"><span class="badge bg-warning text-dark px-2 py-1"><?= $emp['pendingTasks'] ?></span></td>
                                        <td class="text-center text-muted"><?= $emp['plannedTasks'] ?></td>
                                        <td class="text-center text-muted"><?= $emp['unplannedTasks'] ?></td>
                                        <td class="text-center text-muted"><?= $emp['activeDays'] ?></td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="emp-completion-bar" style="flex:1;">
                                                    <div class="emp-completion-fill" style="width:<?= $emp['completionRate'] ?>%;"></div>
                                                </div>
                                                <span class="fw-bold" style="font-size:0.78rem; color:#0f172a; min-width:36px; text-align:right;"><?= $emp['completionRate'] ?>%</span>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <a href="?view=self&range=<?= $range ?>&emp_id=<?= $emp['userId'] ?>" class="btn btn-outline-primary btn-sm px-2" title="View Self-Analysis" style="border-radius:6px; font-size:0.75rem;">
                                                <i class="fa-solid fa-eye me-1"></i> Details
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($teamData)): ?>
                                        <tr><td colspan="11" class="text-center text-muted py-4">No task diary records found for the selected period.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <?php else: ?>
                    <!-- ════════════════════════════════════════════════════════════════════
                         SELF-ANALYSIS MODE (individual employee view)
                    ════════════════════════════════════════════════════════════════════ -->

                    <!-- KPI Cards -->
                    <div class="row mb-4 g-3">
                        <div class="col-md-2 col-sm-4 col-6">
                            <div class="kpi-card">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div class="kpi-lbl">Total Logged</div>
                                    <div class="kpi-icon" style="background:#EEF2FF; color:#4B49AC;"><i class="fa-solid fa-book-bookmark"></i></div>
                                </div>
                                <div class="kpi-val"><?= $totalTasks ?></div>
                                <div class="kpi-sub">Tasks in period</div>
                            </div>
                        </div>
                        <div class="col-md-2 col-sm-4 col-6">
                            <div class="kpi-card">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div class="kpi-lbl">Completed</div>
                                    <div class="kpi-icon" style="background:#E6F4EA; color:#1E8E3E;"><i class="fa-solid fa-circle-check"></i></div>
                                </div>
                                <div class="kpi-val text-success"><?= $completedTasks ?></div>
                                <div class="kpi-sub"><?= $completionRate ?>% finished</div>
                            </div>
                        </div>
                        <div class="col-md-2 col-sm-4 col-6">
                            <div class="kpi-card">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div class="kpi-lbl">Pending</div>
                                    <div class="kpi-icon" style="background:#FEF7E0; color:#B06000;"><i class="fa-solid fa-hourglass-half"></i></div>
                                </div>
                                <div class="kpi-val" style="color:#B06000;"><?= $pendingTasks ?></div>
                                <div class="kpi-sub">Unfinished items</div>
                            </div>
                        </div>
                        <div class="col-md-2 col-sm-4 col-6">
                            <div class="kpi-card">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div class="kpi-lbl">Planned Focus</div>
                                    <div class="kpi-icon" style="background:#E0F2FE; color:#0284C7;"><i class="fa-solid fa-calendar-check"></i></div>
                                </div>
                                <div class="kpi-val" style="color:#0284C7;"><?= $plannedPct ?>%</div>
                                <div class="kpi-sub"><?= $plannedTasks ?> planned tasks</div>
                            </div>
                        </div>
                        <div class="col-md-2 col-sm-4 col-6">
                            <div class="kpi-card">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div class="kpi-lbl">Carried Forward</div>
                                    <div class="kpi-icon" style="background:#F3E8FF; color:#7E22CE;"><i class="fa-solid fa-arrows-spin"></i></div>
                                </div>
                                <div class="kpi-val" style="color:#7E22CE;"><?= $carriedTasks ?></div>
                                <div class="kpi-sub">Latest carry forward</div>
                            </div>
                        </div>
                        <div class="col-md-2 col-sm-4 col-6">
                            <div class="kpi-card">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div class="kpi-lbl">Daily Avg</div>
                                    <div class="kpi-icon" style="background:#FEE2E2; color:#DC2626;"><i class="fa-solid fa-chart-line"></i></div>
                                </div>
                                <div class="kpi-val text-danger"><?= $avgDailyTasks ?></div>
                                <div class="kpi-sub">Tasks / active day</div>
                            </div>
                        </div>
                    </div>

                    <!-- Completion Progress Bar -->
                    <div class="progress-card">
                        <div class="d-flex align-items-center justify-content-between">
                            <span class="fw-bold text-dark" style="font-size:0.85rem;">
                                <i class="fa-solid fa-award text-primary me-2"></i> Overall Task Completion Performance
                            </span>
                            <span class="fw-bold" style="font-size:0.85rem; color:#4b49ac;">
                                <?= $completedTasks ?> / <?= $totalTasks ?> Tasks Completed (<?= $completionRate ?>%)
                            </span>
                        </div>
                        <div class="progress-bar-wrap">
                            <div class="progress-bar-fill" style="width: <?= $completionRate ?>%;"></div>
                        </div>
                    </div>

                    <!-- Self-Analysis Insights -->
                    <div class="insight-card">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <i class="fa-solid fa-lightbulb text-warning" style="font-size:1.2rem;"></i>
                            <h5 class="fw-bold text-dark mb-0" style="font-size:1.1rem;">Personal Productivity Insights</h5>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <div class="bg-white p-3 rounded-3 border">
                                    <div class="fw-bold text-dark small mb-1"><i class="fa-solid fa-bullseye me-1 text-primary"></i> Planning Efficiency</div>
                                    <div class="text-muted small">
                                        <?php if ($plannedPct >= 70): ?>
                                            Excellent! <strong><?= $plannedPct ?>%</strong> of tasks were pre-planned. Strong time management!
                                        <?php else: ?>
                                            <strong><?= 100 - $plannedPct ?>%</strong> of tasks were unplanned. Try logging planned tasks at shift start.
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="bg-white p-3 rounded-3 border">
                                    <div class="fw-bold text-dark small mb-1"><i class="fa-solid fa-circle-check me-1 text-success"></i> Completion Ratio</div>
                                    <div class="text-muted small">
                                        <?php if ($completionRate >= 80): ?>
                                            Great execution rate of <strong><?= $completionRate ?>%</strong>! Most logged items are completed.
                                        <?php else: ?>
                                            <strong><?= $pendingTasks ?></strong> pending tasks remain. Complete active items before adding new ones.
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="bg-white p-3 rounded-3 border">
                                    <div class="fw-bold text-dark small mb-1"><i class="fa-solid fa-arrows-spin me-1" style="color:#7e22ce;"></i> Carry Forward Frequency</div>
                                    <div class="text-muted small">
                                        <?php if ($carriedTasks === 0): ?>
                                            Zero carried tasks! You finish your work on the same day.
                                        <?php else: ?>
                                            <strong><?= $carriedTasks ?></strong> tasks carried forward. Break work into smaller daily milestones.
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Charts -->
                    <div class="row mb-4 g-3">
                        <div class="col-md-8">
                            <div class="card-chart">
                                <h5 class="fw-bold text-dark mb-3" style="font-size:1rem;">
                                    <i class="fa-solid fa-chart-column me-2 text-primary"></i> Daily Task Completion Trend
                                </h5>
                                <div style="height:280px; position:relative;">
                                    <canvas id="dailyTrendChart"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card-chart">
                                <h5 class="fw-bold text-dark mb-3" style="font-size:1rem;">
                                    <i class="fa-solid fa-chart-pie me-2 text-primary"></i> Planned vs. Unplanned Ratio
                                </h5>
                                <div style="height:280px; position:relative;" class="d-flex align-items-center justify-content-center">
                                    <canvas id="typeRatioChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Daily Breakdown Table -->
                    <div class="card-table">
                        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                            <div>
                                <h4 class="fw-bold mb-0 text-dark" style="font-size:1.15rem;">
                                    <i class="fa-solid fa-table-list me-2 text-primary"></i> Daily Task Breakdown
                                </h4>
                                <small class="text-muted">Day-by-day record of tasks logged, completed, and carried forward.</small>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover w-100" id="dailyBreakdownTable">
                                <thead>
                                    <tr>
                                        <th style="width:18%">Date</th>
                                        <th style="width:14%">Day</th>
                                        <th style="width:14%" class="text-center">Total Logged</th>
                                        <th style="width:14%" class="text-center">Completed</th>
                                        <th style="width:14%" class="text-center">Pending</th>
                                        <th style="width:13%" class="text-center">Planned</th>
                                        <th style="width:13%" class="text-center">Unplanned</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($dailyData as $row):
                                        $dObj = new DateTime($row['date']);
                                    ?>
                                    <tr>
                                        <td class="fw-bold text-dark"><?= $dObj->format('d M Y') ?></td>
                                        <td class="text-muted"><?= $dObj->format('l') ?></td>
                                        <td class="text-center fw-bold text-primary"><?= $row['total'] ?></td>
                                        <td class="text-center"><span class="badge bg-success text-white px-2 py-1"><?= $row['completed'] ?></span></td>
                                        <td class="text-center"><span class="badge bg-warning text-dark px-2 py-1"><?= $row['pending'] ?></span></td>
                                        <td class="text-center text-muted"><?= $row['planned'] ?></td>
                                        <td class="text-center text-muted"><?= $row['unplanned'] ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <?php endif; ?>

                </div>
                <?php include("../partials/_footer.php"); ?>
            </div>
        </div>
    </div>

    <script src="../vendors/js/vendor.bundle.base.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>

    <script>
        $(document).ready(function () {

            <?php if ($viewMode === 'admin'): ?>

            // ── Team Bar Chart ──
            new Chart(document.getElementById('teamBarChart').getContext('2d'), {
                type: 'bar',
                data: {
                    labels: <?= json_encode($empBarNames) ?>,
                    datasets: [
                        { label: 'Completed', data: <?= json_encode($empBarCompleted) ?>, backgroundColor: '#10b981', borderRadius: 5 },
                        { label: 'Pending',   data: <?= json_encode($empBarPending) ?>,   backgroundColor: '#f59e0b', borderRadius: 5 }
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    scales: { x: { stacked: false, grid: { display: false } }, y: { beginAtZero: true, ticks: { precision: 0 } } },
                    plugins: { legend: { position: 'top' } }
                }
            });

            // ── Team Doughnut Chart ──
            new Chart(document.getElementById('teamTypeChart').getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: ['Planned Tasks', 'Unplanned Tasks'],
                    datasets: [{ data: [<?= $overallPlanned ?>, <?= $overallUnplanned ?>], backgroundColor: ['#4b49ac', '#38bdf8'], borderWidth: 0 }]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
            });

            // ── Team Table ──
            $('#teamTable').DataTable({
                order: [[4, 'desc']],
                pageLength: 15,
                language: { search: 'Search employees:', emptyTable: 'No task diary records found for this period.' }
            });

            <?php else: ?>

            // ── Individual Daily Trend Chart ──
            new Chart(document.getElementById('dailyTrendChart').getContext('2d'), {
                type: 'bar',
                data: {
                    labels: <?= json_encode($chartDates) ?>,
                    datasets: [
                        { label: 'Completed', data: <?= json_encode($chartCompleted) ?>, backgroundColor: '#10b981', borderRadius: 5 },
                        { label: 'Pending',   data: <?= json_encode($chartPending) ?>,   backgroundColor: '#f59e0b', borderRadius: 5 }
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    scales: { x: { stacked: true, grid: { display: false } }, y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } } },
                    plugins: { legend: { position: 'top' } }
                }
            });

            // ── Individual Planned vs Unplanned Chart ──
            new Chart(document.getElementById('typeRatioChart').getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: ['Planned Tasks', 'Unplanned Tasks'],
                    datasets: [{ data: [<?= $plannedTasks ?>, <?= $unplannedTasks ?>], backgroundColor: ['#4b49ac', '#38bdf8'], borderWidth: 0 }]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
            });

            // ── Daily Breakdown Table ──
            $('#dailyBreakdownTable').DataTable({
                order: [[0, 'desc']],
                pageLength: 10,
                language: { search: 'Search dates:', emptyTable: 'No task diary records found for the selected period.' }
            });

            <?php endif; ?>

        });
    </script>
</body>
</html>
