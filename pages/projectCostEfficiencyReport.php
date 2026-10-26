<?php
session_start();

if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

include('../include/db_config.php');
date_default_timezone_set('Asia/Kolkata');

$userRole  = strtolower($_SESSION['userRole'] ?? '');
$userDesig = strtolower($_SESSION['designation'] ?? '');
$allowedRoles = ['resourceallocator', 'resource_allocator', 'resourcehead', 'resource_head', 'resource allocator', 'resourcemanager', 'resource_manager', 'admin', 'superadmin', 'hr', 'md'];

if (!in_array($userRole, $allowedRoles) && !in_array($userDesig, $allowedRoles)) {
    echo "<script>alert('Access Denied. Resource Managers and Admins only.'); window.location.href = 'jrEmpDashboard.php';</script>";
    exit;
}

// ── 1. Filters & Defaults ──
$fromDate      = isset($_GET['from_date']) && !empty($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$toDate        = isset($_GET['to_date']) && !empty($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$projectFilter = isset($_GET['project_name']) ? trim((string)$_GET['project_name']) : '';
$statusFilter  = isset($_GET['status_filter']) ? trim((string)$_GET['status_filter']) : 'all';

$fromDateEsc      = mysqli_real_escape_string($con, $fromDate);
$toDateEsc        = mysqli_real_escape_string($con, $toDate);
$projectFilterEsc = mysqli_real_escape_string($con, $projectFilter);

// ── 2. Project List Dropdown ──
$projectsList = [];
$pRes = mysqli_query($con, "SELECT projectId, projectName, client, COALESCE(budget_hours, 100.00) AS budget_hours, COALESCE(hourly_rate, 250.00) AS hourly_rate FROM projectMaster WHERE status=1 ORDER BY projectName ASC");
if ($pRes) {
    while ($r = mysqli_fetch_assoc($pRes)) {
        $projectsList[] = $r;
    }
}

// ── 3. Data Matrix Processing ──
$reportData = [];

foreach ($projectsList as $p) {
    $pName = $p['projectName'];
    
    if (!empty($projectFilterEsc) && strtolower((string)($pName ?? '')) !== strtolower((string)($projectFilterEsc ?? ''))) {
        continue;
    }

    $bHours = (float)$p['budget_hours'];
    $hRate  = (float)$p['hourly_rate'];
    $bCost  = $bHours * $hRate;

    // Allocated Hours from internAllocations
    $qAlloc = mysqli_query($con, "
        SELECT SUM(dailyHours) AS allocHrs
        FROM internAllocations
        WHERE projectName = '" . mysqli_real_escape_string($con, $pName) . "'
          AND startDate <= '$toDateEsc' AND endDate >= '$fromDateEsc'
    ");
    $rAlloc = mysqli_fetch_assoc($qAlloc);
    $allocHrs = (float)($rAlloc['allocHrs'] ?? 0);

    // Actual Logged Manhours from TaskEntryNew
    $qTasks = mysqli_query($con, "
        SELECT SUM(manHr) AS loggedHrs, COUNT(taskId) AS taskCnt,
               COUNT(DISTINCT userId) AS internCnt
        FROM TaskEntryNew
        WHERE projectName = '" . mysqli_real_escape_string($con, $pName) . "'
          AND DATE(startTime) BETWEEN '$fromDateEsc' AND '$toDateEsc'
    ");
    $rTasks = mysqli_fetch_assoc($qTasks);
    $loggedHrs = (float)($rTasks['loggedHrs'] ?? 0);
    $taskCnt   = (int)($rTasks['taskCnt'] ?? 0);
    $internCnt = (int)($rTasks['internCnt'] ?? 0);

    $actualCost = $loggedHrs * $hRate;
    $costVariance = $bCost - $actualCost; // positive = under budget, negative = over budget
    $burnRate = $bHours > 0 ? round(($loggedHrs / $bHours) * 100, 1) : 0;

    // Status Category
    if ($burnRate > 100) {
        $statusCat = 'over_budget';
    } elseif ($burnRate >= 80) {
        $statusCat = 'near_limit';
    } else {
        $statusCat = 'under_budget';
    }

    if ($statusFilter !== 'all' && $statusFilter !== $statusCat) {
        continue;
    }

    $reportData[] = [
        'projectId'   => $p['projectId'],
        'projectName' => $pName,
        'client'      => $p['client'],
        'budgetHours' => $bHours,
        'hourlyRate'  => $hRate,
        'budgetCost'  => $bCost,
        'allocHours'  => $allocHrs,
        'loggedHours' => $loggedHrs,
        'actualCost'  => $actualCost,
        'costVariance'=> $costVariance,
        'burnRate'    => $burnRate,
        'taskCount'   => $taskCnt,
        'internCount' => $internCnt,
        'statusCat'   => $statusCat
    ];
}

// Executive KPI Totals
$totalBudgetCost = 0;
$totalActualCost = 0;
$totalBudgetHrs  = 0;
$totalLoggedHrs  = 0;

foreach ($reportData as $row) {
    $totalBudgetCost += $row['budgetCost'];
    $totalActualCost += $row['actualCost'];
    $totalBudgetHrs  += $row['budgetHours'];
    $totalLoggedHrs  += $row['loggedHours'];
}

$overallBurnRate = $totalBudgetHrs > 0 ? round(($totalLoggedHrs / $totalBudgetHrs) * 100, 1) : 0;
$overallVariance = $totalBudgetCost - $totalActualCost;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Project Cost Efficiency Report (Option 3 Hybrid) | SETTribe AMS</title>
    <?php include('../partials/_links.php'); ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css">

    <style>
        .kpi-card {
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            background: #fff;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.03);
            transition: transform 0.2s ease;
        }
        .kpi-card:hover { transform: translateY(-2px); }
        .kpi-title { font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; color: #64748b; }
        .kpi-val { font-size: 1.7rem; font-weight: 800; color: #1e293b; margin-top: 4px; }
        .kpi-icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; }
        
        .badge-under { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; font-weight:700; }
        .badge-near  { background: #fef9c3; color: #a16207; border: 1px solid #fef08a; font-weight:700; }
        .badge-over  { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; font-weight:700; }

        .progress-burn { height: 8px; border-radius: 4px; background: #e2e8f0; overflow: hidden; }
        .progress-bar-under { background: #10b981; }
        .progress-bar-near  { background: #f59e0b; }
        .progress-bar-over  { background: #ef4444; }

        .table-responsive { border-radius: 10px; overflow: hidden; border: 1px solid #e2e8f0; }

        /* Custom Filter Controls & Dropdown Styling */
        .custom-filter-input, .custom-filter-select {
            height: 42px !important;
            width: 100% !important;
            max-width: 100% !important;
            box-sizing: border-box !important;
            border-radius: 8px !important;
            border: 1px solid #cbd5e1 !important;
            font-size: 0.88rem !important;
            font-weight: 500 !important;
            color: #1e293b !important;
            background-color: #ffffff !important;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04) !important;
            transition: all 0.2s ease-in-out !important;
        }
        .custom-filter-select {
            padding: 8px 36px 8px 14px !important;
            appearance: none !important;
            -webkit-appearance: none !important;
            -moz-appearance: none !important;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E") !important;
            background-repeat: no-repeat !important;
            background-position: right 14px center !important;
            background-size: 16px !important;
        }
        .custom-filter-input:focus, .custom-filter-select:focus {
            border-color: #4b49ac !important;
            box-shadow: 0 0 0 3px rgba(75, 73, 172, 0.15) !important;
            outline: none !important;
        }
        .btn-filter-submit {
            height: 42px !important;
            border-radius: 8px !important;
            background: linear-gradient(135deg, #4b49ac 0%, #3f3da3 100%) !important;
            color: #ffffff !important;
            font-weight: 600 !important;
            font-size: 0.88rem !important;
            border: none !important;
            box-shadow: 0 2px 6px rgba(75, 73, 172, 0.25) !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 6px !important;
            transition: all 0.2s ease-in-out !important;
        }
        .btn-filter-submit:hover {
            background: linear-gradient(135deg, #3f3da3 0%, #35338a 100%) !important;
            box-shadow: 0 4px 12px rgba(75, 73, 172, 0.35) !important;
            color: #ffffff !important;
        }
        .btn-filter-reset {
            height: 42px !important;
            width: 42px !important;
            border-radius: 8px !important;
            border: 1px solid #cbd5e1 !important;
            background-color: #ffffff !important;
            color: #64748b !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            transition: all 0.2s ease-in-out !important;
            flex-shrink: 0 !important;
        }
        .btn-filter-reset:hover {
            background-color: #f8fafc !important;
            color: #0f172a !important;
            border-color: #94a3b8 !important;
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

                    <!-- Header -->
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
                        <div>
                            <h3 class="fw-bold mb-1" style="color:#2d3748;">
                                💼 Project Resource Consumption & Cost Efficiency Report
                            </h3>
                            <p class="text-muted mb-0" style="font-size:0.85rem;">
                                Executive Hybrid Analysis: <strong>Budget Hours vs Logged Manhours vs Financial Cost (₹)</strong>
                            </p>
                        </div>
                        <span class="badge px-3 py-2" style="border-radius:20px; font-size:0.8rem; font-weight:600; background-color:#10b981 !important; color:#ffffff !important; box-shadow: 0 2px 8px rgba(16,185,129,0.3);">
                            💰 Option 3 Hybrid Model
                        </span>
                    </div>

                    <!-- Filters -->
                    <div class="card mb-4" style="border-radius:12px; border:1px solid #e2e8f0; box-shadow:0 2px 8px rgba(0,0,0,0.02);">
                        <div class="card-body p-3">
                            <form method="GET" action="projectCostEfficiencyReport.php" class="row g-3 align-items-end">
                                <div class="col-lg-3 col-md-3 col-12">
                                    <label class="form-label fw-bold text-muted mb-1" style="font-size:0.78rem;">From Date</label>
                                    <input type="date" name="from_date" class="form-control custom-filter-input" value="<?= htmlspecialchars((string)$fromDate) ?>">
                                </div>
                                <div class="col-lg-3 col-md-3 col-12">
                                    <label class="form-label fw-bold text-muted mb-1" style="font-size:0.78rem;">To Date</label>
                                    <input type="date" name="to_date" class="form-control custom-filter-input" value="<?= htmlspecialchars((string)$toDate) ?>">
                                </div>
                                <div class="col-lg-2 col-md-3 col-12">
                                    <label class="form-label fw-bold text-muted mb-1" style="font-size:0.78rem;">Project</label>
                                    <select name="project_name" class="form-select custom-filter-select">
                                        <option value="">All Projects</option>
                                        <?php foreach ($projectsList as $pItem): 
                                            $selP = ($projectFilter === $pItem['projectName']) ? 'selected' : '';
                                            $pName = $pItem['projectName'];
                                            $pClient = trim($pItem['client'] ?? '');
                                            $pLabel = (!empty($pClient) && strtoupper($pClient) !== 'NA') ? ($pName . ' - ' . $pClient) : $pName;
                                        ?>
                                            <option value="<?= htmlspecialchars((string)$pName) ?>" <?= $selP ?>><?= htmlspecialchars((string)$pLabel) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-lg-2 col-md-3 col-12">
                                    <label class="form-label fw-bold text-muted mb-1" style="font-size:0.78rem;">Status</label>
                                    <select name="status_filter" class="form-select custom-filter-select">
                                        <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                                        <option value="under_budget" <?= $statusFilter === 'under_budget' ? 'selected' : '' ?>>🟢 Under Budget (<80%)</option>
                                        <option value="near_limit" <?= $statusFilter === 'near_limit' ? 'selected' : '' ?>>🟡 Near Limit (80-100%)</option>
                                        <option value="over_budget" <?= $statusFilter === 'over_budget' ? 'selected' : '' ?>>🔴 Over Budget (>100%)</option>
                                    </select>
                                </div>
                                <div class="col-lg-2 col-md-12 col-12 d-flex gap-2">
                                    <button type="submit" class="btn btn-filter-submit flex-grow-1"><i class="fa fa-filter me-1"></i> Filter</button>
                                    <a href="projectCostEfficiencyReport.php" class="btn btn-filter-reset" title="Reset Filters"><i class="fa fa-undo"></i></a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Executive KPI Summary -->
                    <div class="row mb-4">
                        <div class="col-md-3 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-title">Total Budget (Cost)</div>
                                    <div class="kpi-val text-primary">₹<?= number_format($totalBudgetCost, 0) ?></div>
                                    <small class="text-muted"><?= number_format($totalBudgetHrs, 1) ?> Budgeted Hours</small>
                                </div>
                                <div class="kpi-icon bg-light-primary text-primary"><i class="fa fa-calculator"></i></div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-title">Actual Consumed (Cost)</div>
                                    <div class="kpi-val text-purple" style="color:#7c3aed;">₹<?= number_format($totalActualCost, 0) ?></div>
                                    <small class="text-muted"><?= number_format($totalLoggedHrs, 1) ?> Logged Manhours</small>
                                </div>
                                <div class="kpi-icon text-purple" style="background:#f3e8ff; color:#7c3aed;"><i class="fa fa-wallet"></i></div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-title">Overall Financial Surplus</div>
                                    <div class="kpi-val <?= $overallVariance >= 0 ? 'text-success' : 'text-danger' ?>">
                                        <?= $overallVariance >= 0 ? '+₹' . number_format($overallVariance, 0) : '-₹' . number_format(abs($overallVariance), 0) ?>
                                    </div>
                                    <small class="text-muted"><?= $overallVariance >= 0 ? 'Cost Savings' : 'Cost Overrun' ?></small>
                                </div>
                                <div class="kpi-icon bg-light-success text-success"><i class="fa fa-coins"></i></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-title">Overall Budget Burn Rate</div>
                                    <div class="kpi-val <?= $overallBurnRate > 100 ? 'text-danger' : ($overallBurnRate >= 80 ? 'text-warning' : 'text-success') ?>">
                                        <?= $overallBurnRate ?>%
                                    </div>
                                    <small class="text-muted">Target Capacity Utilization</small>
                                </div>
                                <div class="kpi-icon bg-light-info text-info"><i class="fa fa-fire"></i></div>
                            </div>
                        </div>
                    </div>

                    <!-- Main Report Data Table -->
                    <div class="card" style="border-radius:12px; border:1px solid #e2e8f0;">
                        <div class="card-body p-4">
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <h5 class="fw-bold text-dark mb-0"><i class="fa fa-chart-pie me-2 text-primary"></i> Project Financial & Capacity Consumption Matrix</h5>
                                <span class="badge bg-light text-dark border px-3 py-2" style="font-size:0.8rem; border-radius:20px;">
                                    Showing <?= count($reportData) ?> Projects
                                </span>
                            </div>

                            <div class="table-responsive">
                                <table id="costTable" class="table table-hover align-middle mb-0" style="font-size:0.85rem;">
                                    <thead class="table-light text-secondary text-uppercase" style="font-size:0.75rem;">
                                        <tr>
                                            <th>Project & Client</th>
                                            <th class="text-center">Rate / hr</th>
                                            <th class="text-center">Budget Target</th>
                                            <th class="text-center">Allocated Shift</th>
                                            <th class="text-center">Actual Logged</th>
                                            <th class="text-center">Financial Consumed</th>
                                            <th class="text-center">Cost Surplus / Deficit</th>
                                            <th class="text-center" style="width:140px;">Burn Rate %</th>
                                            <th class="text-center">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($reportData)):
                                            foreach ($reportData as $row):
                                                $bClass = match($row['statusCat']) {
                                                    'over_budget'  => 'badge-over',
                                                    'near_limit'   => 'badge-near',
                                                    default        => 'badge-under'
                                                };
                                                $barClass = match($row['statusCat']) {
                                                    'over_budget'  => 'progress-bar-over',
                                                    'near_limit'   => 'progress-bar-near',
                                                    default        => 'progress-bar-under'
                                                };
                                                $statusTxt = match($row['statusCat']) {
                                                    'over_budget'  => '🔴 Over Budget',
                                                    'near_limit'   => '🟡 Near Limit',
                                                    default        => '🟢 Under Budget'
                                                };
                                                $costVarClass = $row['costVariance'] >= 0 ? 'text-success' : 'text-danger';
                                                $costVarSign  = $row['costVariance'] >= 0 ? '+₹' : '-₹';
                                        ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-bold text-dark"><?= htmlspecialchars($row['projectName']) ?></div>
                                                    <?php if (!empty($row['client'])): ?>
                                                        <div class="text-muted small"><i class="fa fa-building-o me-1"></i> <?= htmlspecialchars($row['client']) ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge bg-light text-dark border">₹<?= number_format($row['hourlyRate'], 0) ?>/hr</span>
                                                </td>
                                                <td class="text-center">
                                                    <div class="fw-bold text-primary"><?= number_format($row['budgetHours'], 1) ?> hrs</div>
                                                    <div class="text-muted small">₹<?= number_format($row['budgetCost'], 0) ?></div>
                                                </td>
                                                <td class="text-center">
                                                    <div class="fw-bold text-purple" style="color:#7c3aed;"><?= number_format($row['allocHours'], 1) ?> hrs</div>
                                                </td>
                                                <td class="text-center">
                                                    <div class="fw-bold text-success"><?= number_format($row['loggedHours'], 1) ?> hrs</div>
                                                    <div class="text-muted" style="font-size:0.72rem;"><?= $row['taskCount'] ?> tasks (<?= $row['internCount'] ?> interns)</div>
                                                </td>
                                                <td class="text-center fw-bold text-dark">
                                                    ₹<?= number_format($row['actualCost'], 0) ?>
                                                </td>
                                                <td class="text-center fw-bold <?= $costVarClass ?>">
                                                    <?= $costVarSign . number_format(abs($row['costVariance']), 0) ?>
                                                </td>
                                                <td class="text-center">
                                                    <div class="d-flex align-items-center justify-content-between mb-1" style="font-size:0.75rem;">
                                                        <span class="fw-bold"><?= $row['burnRate'] ?>%</span>
                                                    </div>
                                                    <div class="progress-burn">
                                                        <div class="progress-bar <?= $barClass ?>" style="width: <?= min(100, $row['burnRate']) ?>%; height:100%;"></div>
                                                    </div>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge <?= $bClass ?> px-2 py-1"><?= $statusTxt ?></span>
                                                </td>
                                            </tr>
                                        <?php endforeach;
                                        else: ?>
                                            <tr>
                                                <td colspan="9" class="text-center py-4 text-muted">
                                                    <i class="fa fa-info-circle fa-2x mb-2 d-block"></i>
                                                    No project consumption data found for the selected filter criteria.
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <script src="../assets/vendors/js/vendor.bundle.base.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap5.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>

    <script>
        $(document).ready(function() {
            $('#costTable').DataTable({
                dom: '<"d-flex align-items-center justify-content-between mb-3"Bf>rt<"d-flex align-items-center justify-content-between mt-3"ip>',
                buttons: [
                    { extend: 'excelHtml5', text: '📊 Export to Excel', className: 'btn btn-success btn-sm fw-bold me-2' },
                    { extend: 'csvHtml5', text: '📄 Export to CSV', className: 'btn btn-outline-secondary btn-sm fw-bold me-2' },
                    { extend: 'print', text: '🖨️ Print Report', className: 'btn btn-outline-info btn-sm fw-bold' }
                ],
                pageLength: 25,
                ordering: true,
                order: [[0, 'asc']]
            });
        });
    </script>
</body>
</html>
