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
$fromDate      = isset($_GET['from_date']) && !empty($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-d', strtotime('-6 days'));
$toDate        = isset($_GET['to_date']) && !empty($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$internFilter  = isset($_GET['intern_id']) ? trim((string)$_GET['intern_id']) : '';
$projectFilter = isset($_GET['project_name']) ? trim((string)$_GET['project_name']) : '';

$fromDateEsc      = mysqli_real_escape_string($con, $fromDate);
$toDateEsc        = mysqli_real_escape_string($con, $toDate);
$internFilterEsc  = mysqli_real_escape_string($con, $internFilter);
$projectFilterEsc = mysqli_real_escape_string($con, $projectFilter);

// ── 2. Dropdown Lists ──
$internsList = [];
$internsQ = mysqli_query($con, "SELECT DISTINCT internId, fullName, userId FROM signup WHERE (employment_type='Intern' OR userRole LIKE '%intern%') AND internId IS NOT NULL AND internId != '' ORDER BY fullName ASC");
while ($row = mysqli_fetch_assoc($internsQ)) {
    $internsList[] = $row;
}

$projectsList = [];
$projectsQ = mysqli_query($con, "SELECT DISTINCT projectName, client FROM projectMaster WHERE status=1 AND projectName != '' ORDER BY projectName ASC");
if ($projectsQ) {
    while ($pRow = mysqli_fetch_assoc($projectsQ)) {
        $projectsList[] = $pRow;
    }
}

// ── 3. Data Processing (3-Way Matrix Engine) ──
$matrix = [];

function makeKey($internId, $projName) {
    return strtolower(trim((string)($internId ?? ''))) . '___' . strtolower(trim((string)($projName ?? '')));
}

function formatTimeSlot($start, $end) {
    if (empty($start) || empty($end)) return '—';
    $st = date('h:i A', strtotime($start));
    $et = date('h:i A', strtotime($end));
    return "$st – $et";
}

// STEP 1: Resource Requests (Initial Planned Request)
$reqWhere = "WHERE rr.status = 'Approved' AND rr.startDate <= '$toDateEsc' AND rr.endDate >= '$fromDateEsc'";
if (!empty($internFilterEsc)) {
    $reqWhere .= " AND (rr.internId = '$internFilterEsc' OR su.userId = '$internFilterEsc')";
}
if (!empty($projectFilterEsc)) {
    $reqWhere .= " AND rr.projectName = '$projectFilterEsc'";
}

$qReq = mysqli_query($con, "
    SELECT rr.internId, su.fullName, su.userId, rr.projectName, rr.clientName,
           SUM(rr.dailyHours) AS totalReqHours,
           MIN(rr.allocStartTime) AS minReqStart,
           MAX(rr.allocEndTime) AS maxReqEnd,
           COUNT(rr.id) AS reqDays
    FROM resourceRequests rr
    LEFT JOIN signup su ON (su.internId = rr.internId OR su.userId = rr.internId)
    $reqWhere
    GROUP BY rr.internId, su.fullName, su.userId, rr.projectName, rr.clientName
");

if ($qReq) {
    while ($r = mysqli_fetch_assoc($qReq)) {
        $key = makeKey($r['internId'], $r['projectName']);
        if (!isset($matrix[$key])) {
            $matrix[$key] = [
                'internId'    => $r['internId'],
                'userId'      => $r['userId'],
                'fullName'    => !empty($r['fullName']) ? $r['fullName'] : $r['internId'],
                'projectName' => $r['projectName'],
                'clientName'  => $r['clientName'],
                'reqHours'    => 0,
                'reqSlot'     => '—',
                'allocHours'  => 0,
                'allocSlot'   => '—',
                'loggedHours' => 0,
                'taskCount'   => 0,
                'finishedCnt' => 0,
                'tasks'       => []
            ];
        }
        $matrix[$key]['reqHours'] += (float)$r['totalReqHours'];
        $matrix[$key]['reqSlot']   = formatTimeSlot($r['minReqStart'], $r['maxReqEnd']);
        if (empty($matrix[$key]['clientName'])) $matrix[$key]['clientName'] = $r['clientName'];
    }
}

// STEP 2: Intern Allocations (Runtime Modified Schedule)
$allocWhere = "WHERE ia.startDate <= '$toDateEsc' AND ia.endDate >= '$fromDateEsc'";
if (!empty($internFilterEsc)) {
    $allocWhere .= " AND (ia.internId = '$internFilterEsc' OR su.userId = '$internFilterEsc')";
}
if (!empty($projectFilterEsc)) {
    $allocWhere .= " AND ia.projectName = '$projectFilterEsc'";
}

$qAlloc = mysqli_query($con, "
    SELECT ia.internId, su.fullName, su.userId, ia.projectName, ia.clientName,
           SUM(ia.dailyHours) AS totalAllocHours,
           MIN(ia.allocStartTime) AS minAllocStart,
           MAX(ia.allocEndTime) AS maxAllocEnd,
           COUNT(ia.id) AS allocDays
    FROM internAllocations ia
    LEFT JOIN signup su ON (su.internId = ia.internId OR su.userId = ia.internId)
    $allocWhere
    GROUP BY ia.internId, su.fullName, su.userId, ia.projectName, ia.clientName
");

if ($qAlloc) {
    while ($a = mysqli_fetch_assoc($qAlloc)) {
        $key = makeKey($a['internId'], $a['projectName']);
        if (!isset($matrix[$key])) {
            $matrix[$key] = [
                'internId'    => $a['internId'],
                'userId'      => $a['userId'],
                'fullName'    => !empty($a['fullName']) ? $a['fullName'] : $a['internId'],
                'projectName' => $a['projectName'],
                'clientName'  => $a['clientName'],
                'reqHours'    => 0,
                'reqSlot'     => '—',
                'allocHours'  => 0,
                'allocSlot'   => '—',
                'loggedHours' => 0,
                'taskCount'   => 0,
                'finishedCnt' => 0,
                'tasks'       => []
            ];
        }
        $matrix[$key]['allocHours'] += (float)$a['totalAllocHours'];
        $matrix[$key]['allocSlot']   = formatTimeSlot($a['minAllocStart'], $a['maxAllocEnd']);
        if (empty($matrix[$key]['clientName'])) $matrix[$key]['clientName'] = $a['clientName'];
    }
}

// STEP 3: TaskEntryNew (Actual Logged Employee Tasks & Manhours)
$taskWhere = "WHERE DATE(t.startTime) BETWEEN '$fromDateEsc' AND '$toDateEsc'";
if (!empty($internFilterEsc)) {
    $taskWhere .= " AND (su.internId = '$internFilterEsc' OR su.userId = '$internFilterEsc')";
}
if (!empty($projectFilterEsc)) {
    $taskWhere .= " AND t.projectName = '$projectFilterEsc'";
}

$qTasks = mysqli_query($con, "
    SELECT t.taskId, t.userId, su.internId, su.fullName, t.projectName, t.task, t.workType,
           t.startTime, t.endTime, t.manHr, t.status, t.remark
    FROM TaskEntryNew t
    JOIN signup su ON su.userId = t.userId
    $taskWhere
    ORDER BY t.startTime DESC
");

if ($qTasks) {
    while ($t = mysqli_fetch_assoc($qTasks)) {
        $intId = !empty($t['internId']) ? $t['internId'] : $t['userId'];
        $key = makeKey($intId, $t['projectName']);
        
        if (!isset($matrix[$key])) {
            $matrix[$key] = [
                'internId'    => $intId,
                'userId'      => $t['userId'],
                'fullName'    => !empty($t['fullName']) ? $t['fullName'] : $intId,
                'projectName' => $t['projectName'],
                'clientName'  => '',
                'reqHours'    => 0,
                'reqSlot'     => '—',
                'allocHours'  => 0,
                'allocSlot'   => '—',
                'loggedHours' => 0,
                'taskCount'   => 0,
                'finishedCnt' => 0,
                'tasks'       => []
            ];
        }
        
        $taskManHr = (float)$t['manHr'];
        if ($taskManHr <= 0 && !empty($t['startTime']) && !empty($t['endTime'])) {
            $stTime = strtotime($t['startTime']);
            $etTime = strtotime($t['endTime']);
            if ($etTime > $stTime) {
                $taskManHr = round(($etTime - $stTime) / 3600, 2);
                $t['manHr'] = $taskManHr;
            }
        }

        $matrix[$key]['loggedHours'] += $taskManHr;
        $matrix[$key]['taskCount']++;
        if ($t['status'] === 'Finished') $matrix[$key]['finishedCnt']++;
        $matrix[$key]['tasks'][] = $t;
    }
}

// Executive KPI Totals
$totalReq   = 0;
$totalAlloc = 0;
$totalLogged= 0;

foreach ($matrix as $m) {
    $totalReq    += $m['reqHours'];
    $totalAlloc  += $m['allocHours'];
    $totalLogged += $m['loggedHours'];
}

$alignmentRate = $totalAlloc > 0 ? round(($totalLogged / $totalAlloc) * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>3-Way Resource Allocation vs Task Report | SETTribe AMS</title>
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
        .kpi-val { font-size: 1.8rem; font-weight: 800; color: #1e293b; margin-top: 4px; }
        .kpi-icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; }
        
        .badge-aligned { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; font-weight:700; }
        .badge-pending { background: #fef9c3; color: #a16207; border: 1px solid #fef08a; font-weight:700; }
        .badge-unplanned { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; font-weight:700; }
        
        .step-badge { font-size: 0.7rem; font-weight: 800; padding: 2px 8px; border-radius: 4px; text-transform: uppercase; }
        .step-1 { background: #e0f2fe; color: #0369a1; }
        .step-2 { background: #f3e8ff; color: #6b21a8; }
        .step-3 { background: #dcfce7; color: #166534; }

        .table-responsive { 
            border-radius: 10px; 
            overflow-x: auto !important; 
            overflow-y: visible !important; 
            border: 1px solid #e2e8f0; 
            -webkit-overflow-scrolling: touch;
        }
        #varianceTable th, #varianceTable td {
            white-space: nowrap;
        }
        .var-pos { color: #16a34a; }
        .var-neg { color: #dc2626; }
        .var-zero { color: #64748b; }

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
                                📊 3-Way Resource Allocation & Task Variance Report
                            </h3>
                            <p class="text-muted mb-0" style="font-size:0.85rem;">
                                Compare <strong>Planned Requests (Step 1)</strong> vs <strong>Modified Allocations (Step 2)</strong> vs <strong>Actual Logged Tasks (Step 3)</strong>
                            </p>
                        </div>
                        <span class="badge px-3 py-2" style="border-radius:20px; font-size:0.8rem; font-weight:600; background-color:#4b49ac !important; color:#ffffff !important; box-shadow: 0 2px 8px rgba(75,73,172,0.3);">
                            Live Execution Analytics
                        </span>
                    </div>

                    <!-- Filters Bar -->
                    <div class="card mb-4" style="border-radius:12px; border:1px solid #e2e8f0; box-shadow:0 2px 8px rgba(0,0,0,0.02);">
                        <div class="card-body p-3">
                            <form method="GET" action="allocationVarianceReport.php" class="row g-3 align-items-end">
                                <div class="col-lg-3 col-md-3 col-12">
                                    <label class="form-label fw-bold text-muted mb-1" style="font-size:0.78rem;">From Date</label>
                                    <input type="date" name="from_date" class="form-control custom-filter-input" value="<?= htmlspecialchars((string)($fromDate ?? '')) ?>">
                                </div>
                                <div class="col-lg-3 col-md-3 col-12">
                                    <label class="form-label fw-bold text-muted mb-1" style="font-size:0.78rem;">To Date</label>
                                    <input type="date" name="to_date" class="form-control custom-filter-input" value="<?= htmlspecialchars((string)($toDate ?? '')) ?>">
                                </div>
                                <div class="col-lg-2 col-md-3 col-12">
                                    <label class="form-label fw-bold text-muted mb-1" style="font-size:0.78rem;">Intern</label>
                                    <select name="intern_id" class="form-select custom-filter-select">
                                        <option value="">All Interns</option>
                                        <?php foreach ($internsList as $i): 
                                            $val = !empty($i['internId']) ? $i['internId'] : $i['userId'];
                                            $sel = ($internFilter === $val) ? 'selected' : '';
                                        ?>
                                            <option value="<?= htmlspecialchars((string)$val) ?>" <?= $sel ?>><?= htmlspecialchars((string)$i['fullName']) ?> (<?= htmlspecialchars((string)$val) ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-lg-2 col-md-3 col-12">
                                    <label class="form-label fw-bold text-muted mb-1" style="font-size:0.78rem;">Project</label>
                                    <select name="project_name" class="form-select custom-filter-select">
                                        <option value="">All Projects</option>
                                        <?php foreach ($projectsList as $pItem): 
                                            $pName = $pItem['projectName'];
                                            $pClient = trim($pItem['client'] ?? '');
                                            $pLabel = (!empty($pClient) && strtoupper($pClient) !== 'NA') ? ($pName . ' - ' . $pClient) : $pName;
                                            $selP = ($projectFilter === $pName) ? 'selected' : '';
                                        ?>
                                            <option value="<?= htmlspecialchars((string)$pName) ?>" <?= $selP ?>><?= htmlspecialchars((string)$pLabel) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-lg-2 col-md-12 col-12 d-flex gap-2">
                                    <button type="submit" class="btn btn-filter-submit flex-grow-1"><i class="fa fa-filter me-1"></i> Filter</button>
                                    <a href="allocationVarianceReport.php" class="btn btn-filter-reset" title="Reset Filters"><i class="fa fa-undo"></i></a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Executive KPI Cards -->
                    <div class="row mb-4">
                        <div class="col-md-3 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-title"><span class="step-badge step-1 me-1">Step 1</span> Planned Requests</div>
                                    <div class="kpi-val text-primary"><?= number_format($totalReq, 1) ?> <span style="font-size:0.9rem;">hrs</span></div>
                                    <small class="text-muted">Approved RM Requests</small>
                                </div>
                                <div class="kpi-icon bg-light-primary text-primary"><i class="fa fa-clipboard-list"></i></div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-title"><span class="step-badge step-2 me-1">Step 2</span> Modified Schedule</div>
                                    <div class="kpi-val text-purple" style="color:#7c3aed;"><?= number_format($totalAlloc, 1) ?> <span style="font-size:0.9rem;">hrs</span></div>
                                    <small class="text-muted">Active Allocated Hours</small>
                                </div>
                                <div class="kpi-icon text-purple" style="background:#f3e8ff; color:#7c3aed;"><i class="fa fa-sliders"></i></div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-title"><span class="step-badge step-3 me-1">Step 3</span> Actual Logged</div>
                                    <div class="kpi-val text-success"><?= number_format($totalLogged, 1) ?> <span style="font-size:0.9rem;">hrs</span></div>
                                    <small class="text-muted">TaskEntryNew Manhours</small>
                                </div>
                                <div class="kpi-icon bg-light-success text-success"><i class="fa fa-clock-o"></i></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-title">Execution Alignment</div>
                                    <div class="kpi-val <?= $alignmentRate >= 80 ? 'text-success' : ($alignmentRate >= 50 ? 'text-warning' : 'text-danger') ?>">
                                        <?= $alignmentRate ?>%
                                    </div>
                                    <small class="text-muted">Actual vs Allocated Ratio</small>
                                </div>
                                <div class="kpi-icon bg-light-info text-info"><i class="fa fa-chart-line"></i></div>
                            </div>
                        </div>
                    </div>

                    <!-- Main Data Matrix Table -->
                    <div class="card" style="border-radius:12px; border:1px solid #e2e8f0;">
                        <div class="card-body p-4">
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <h5 class="fw-bold text-dark mb-0"><i class="fa fa-table me-2 text-primary"></i> 3-Way Variance Matrix Records</h5>
                                <span class="badge bg-light text-dark border px-3 py-2" style="font-size:0.8rem; border-radius:20px;">
                                    Showing <?= count($matrix) ?> Combined Records
                                </span>
                            </div>

                            <div class="table-responsive">
                                <table id="varianceTable" class="table table-hover align-middle mb-0" style="font-size:0.85rem;">
                                    <thead class="table-light text-secondary text-uppercase" style="font-size:0.75rem;">
                                        <tr>
                                            <th>Intern Details</th>
                                            <th>Project & Client</th>
                                            <th class="text-center" style="background:#f0f9ff;"><span class="step-badge step-1">Step 1</span> Planned Request</th>
                                            <th class="text-center" style="background:#faf5ff;"><span class="step-badge step-2">Step 2</span> Modified Schedule</th>
                                            <th class="text-center" style="background:#f0fdf4;"><span class="step-badge step-3">Step 3</span> Actual Logged</th>
                                            <th class="text-center">Exec Variance</th>
                                            <th class="text-center">Status</th>
                                            <th class="text-center">Tasks & Logs</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($matrix)): 
                                            $modalIndex = 0;
                                            foreach ($matrix as $key => $m):
                                                $modalIndex++;
                                                $reqH   = $m['reqHours'];
                                                $allocH = $m['allocHours'];
                                                $logH   = $m['loggedHours'];
                                                
                                                $planVar = $allocH - $reqH;
                                                $execVar = $logH - $allocH;

                                                // Status determination
                                                if ($allocH > 0 && $logH > 0 && abs($execVar) <= 0.5) {
                                                    $statusBadge = '<span class="badge badge-aligned px-2 py-1"><i class="fa fa-check-circle me-1"></i> Aligned</span>';
                                                } elseif ($allocH > 0 && $logH == 0) {
                                                    $statusBadge = '<span class="badge badge-pending px-2 py-1"><i class="fa fa-clock-o me-1"></i> Pending Log</span>';
                                                } elseif ($allocH == 0 && $logH > 0) {
                                                    $statusBadge = '<span class="badge badge-unplanned px-2 py-1"><i class="fa fa-exclamation-triangle me-1"></i> Unplanned Work</span>';
                                                } elseif ($allocH > 0 && $logH < $allocH) {
                                                    $statusBadge = '<span class="badge badge-pending px-2 py-1"><i class="fa fa-arrow-down me-1"></i> Under-Utilized</span>';
                                                } elseif ($allocH > 0 && $logH > $allocH) {
                                                    $statusBadge = '<span class="badge badge-unplanned px-2 py-1"><i class="fa fa-arrow-up me-1"></i> Over-Utilized</span>';
                                                } elseif ($reqH > 0 && $allocH == 0 && $logH == 0) {
                                                    $statusBadge = '<span class="badge bg-secondary text-white px-2 py-1"><i class="fa fa-minus-circle me-1"></i> Reallocated / Removed</span>';
                                                } elseif (!empty($m['taskCount']) && $m['taskCount'] > 0) {
                                                    $statusBadge = '<span class="badge badge-pending px-2 py-1"><i class="fa fa-clock me-1"></i> Logged (0 hrs)</span>';
                                                } else {
                                                    $statusBadge = '<span class="badge bg-light text-muted border px-2 py-1"><i class="fa fa-minus me-1"></i> No Activity</span>';
                                                }

                                                $execVarClass = $execVar > 0 ? 'var-pos' : ($execVar < 0 ? 'var-neg' : 'var-zero');
                                                $execVarSign  = $execVar > 0 ? '+' : '';
                                        ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-bold text-dark"><?= htmlspecialchars((string)($m['fullName'] ?? '')) ?></div>
                                                    <div class="text-muted small">ID: <code><?= htmlspecialchars((string)($m['internId'] ?? '')) ?></code></div>
                                                </td>
                                                <td>
                                                    <?php if (!empty($m['projectName'])): ?>
                                                        <div class="fw-bold text-primary"><?= htmlspecialchars((string)$m['projectName']) ?></div>
                                                    <?php else: ?>
                                                        <div class="fw-bold text-secondary" style="font-style: italic;"><i class="fa fa-coffee me-1 text-muted"></i> General / Non-Project Activities</div>
                                                        <div class="text-muted small" style="font-size:0.72rem;">(Lunch Break, Meetings, Routine Tasks)</div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($m['clientName'])): ?>
                                                        <div class="text-muted small"><i class="fa fa-building-o me-1"></i> <?= htmlspecialchars((string)($m['clientName'] ?? '')) ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <!-- Step 1 -->
                                                <td class="text-center" style="background:#f0f9ff;">
                                                    <div class="fw-bold text-dark"><?= number_format($reqH, 1) ?> hrs</div>
                                                    <div class="text-muted" style="font-size:0.72rem;"><?= htmlspecialchars((string)($m['reqSlot'] ?? '')) ?></div>
                                                </td>
                                                <!-- Step 2 -->
                                                <td class="text-center" style="background:#faf5ff;">
                                                    <div class="fw-bold text-purple" style="color:#7c3aed;"><?= number_format($allocH, 1) ?> hrs</div>
                                                    <div class="text-muted" style="font-size:0.72rem;"><?= htmlspecialchars((string)($m['allocSlot'] ?? '')) ?></div>
                                                </td>
                                                <!-- Step 3 -->
                                                <td class="text-center" style="background:#f0fdf4;">
                                                    <div class="fw-bold text-success"><?= number_format($logH, 1) ?> hrs</div>
                                                    <div class="text-muted" style="font-size:0.72rem;"><?= $m['taskCount'] ?> task entries</div>
                                                </td>
                                                <!-- Variance -->
                                                <td class="text-center fw-bold <?= $execVarClass ?>">
                                                    <?= $execVarSign . number_format($execVar, 1) ?> hrs
                                                </td>
                                                <!-- Status -->
                                                <td class="text-center">
                                                    <?= $statusBadge ?>
                                                </td>
                                                <!-- Task Log Modal Trigger -->
                                                <td class="text-center">
                                                    <?php if (!empty($m['tasks'])): ?>
                                                        <button type="button" class="btn btn-sm btn-outline-primary task-detail-btn view-task-logs"
                                                            onclick="openTaskLogsModal('<?= addslashes(htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8')) ?>')">
                                                            <i class="fa fa-list me-1"></i> Logs (<?= count($m['tasks']) ?>)
                                                        </button>
                                                    <?php else: ?>
                                                        <span class="text-muted small">No logs</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Global Reusable Task Detail Modal -->
                    <div class="modal fade" id="globalTaskLogModal" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-lg modal-dialog-centered">
                            <div class="modal-content" style="border-radius:12px;">
                                <div class="modal-header bg-light">
                                    <h5 class="modal-title fw-bold text-dark">
                                        <i class="fa fa-clock-o me-2 text-primary"></i> <span id="globalModalTitle">Logged Tasks</span>
                                    </h5>
                                    <button type="button" class="btn-close close" data-bs-dismiss="modal" data-dismiss="modal" aria-label="Close">
                                        <span aria-hidden="true">&times;</span>
                                    </button>
                                </div>
                                <div class="modal-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-sm table-striped mb-0" style="font-size:0.8rem;">
                                            <thead class="table-dark">
                                                <tr>
                                                    <th>Start Time</th>
                                                    <th>End Time</th>
                                                    <th>Task &amp; Work Type</th>
                                                    <th class="text-center">Status</th>
                                                    <th class="text-end">Manhours</th>
                                                </tr>
                                            </thead>
                                            <tbody id="globalModalTableBody">
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <div class="modal-footer bg-light">
                                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal" data-dismiss="modal">Close</button>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap5.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>

    <?php
    $tasksMap = [];
    if (!empty($matrix)) {
        foreach ($matrix as $k => $m) {
            if (!empty($m['tasks'])) {
                $pLabel = !empty($m['projectName']) ? $m['projectName'] : 'General / Non-Project Activities';
                $tasksMap[$k] = [
                    'title' => ($m['fullName'] ?? '') . ' (' . $pLabel . ')',
                    'tasks' => $m['tasks']
                ];
            }
        }
    }
    ?>

    <script>
        window.matrixTasksMap = <?= json_encode($tasksMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

        function escHtml(str) {
            if (!str) return '';
            return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        $(document).ready(function() {
            $('#varianceTable').DataTable({
                scrollX: true,
                autoWidth: false,
                dom: '<"d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2"Bf>rt<"d-flex align-items-center justify-content-between mt-3 flex-wrap gap-2"ip>',
                buttons: [
                    { extend: 'excelHtml5', text: '📊 Export to Excel', className: 'btn btn-success btn-sm fw-bold me-2' },
                    { extend: 'csvHtml5', text: '📄 Export to CSV', className: 'btn btn-outline-secondary btn-sm fw-bold me-2' },
                    { extend: 'print', text: '🖨️ Print Report', className: 'btn btn-outline-info btn-sm fw-bold' }
                ],
                pageLength: 25,
                ordering: true,
                order: [[0, 'asc']],
                language: {
                    emptyTable: '<div class="p-4 text-center text-muted"><i class="fa fa-info-circle fa-2x mb-2 d-block"></i>No allocation or task records found for the selected filter criteria.</div>'
                }
            });

            $(document).on('click', '#globalTaskLogModal [data-bs-dismiss="modal"], #globalTaskLogModal [data-dismiss="modal"], #globalTaskLogModal .btn-close, #globalTaskLogModal .close', function() {
                closeTaskLogsModal();
            });
        });

        // ── GLOBAL: Must be outside $(document).ready() so onclick= attributes can call them ──
        function openTaskLogsModal(key) {
            const item = (window.matrixTasksMap && window.matrixTasksMap[key]) ? window.matrixTasksMap[key] : null;

            if (!item) {
                alert('No task details available for this row.');
                return;
            }

            $('#globalModalTitle').text('Logged Tasks: ' + item.title);

            let html = '';
            const tasks = item.tasks || [];
            if (Array.isArray(tasks) && tasks.length > 0) {
                tasks.forEach(function(tk) {
                    let stBadge = 'bg-secondary text-white';
                    if (tk.status === 'Finished') stBadge = 'bg-success text-white';
                    else if (tk.status === 'Running') stBadge = 'bg-primary text-white';
                    else if (tk.status === 'Paused') stBadge = 'bg-warning text-white';

                    let stFmt = tk.startTime ? tk.startTime : '-';
                    let etFmt = tk.endTime ? tk.endTime : 'In Progress';
                    let manHr = parseFloat(tk.manHr || 0).toFixed(2);

                    html += '<tr>' +
                        '<td>' + escHtml(stFmt) + '</td>' +
                        '<td>' + escHtml(etFmt) + '</td>' +
                        '<td><div class="fw-bold">' + escHtml(tk.task || '') + '</div>' +
                        (tk.workType ? '<span class="badge bg-light text-dark border">' + escHtml(tk.workType) + '</span>' : '') + '</td>' +
                        '<td class="text-center"><span class="badge ' + stBadge + '">' + escHtml(tk.status || '') + '</span></td>' +
                        '<td class="text-end fw-bold">' + manHr + ' hrs</td>' +
                        '</tr>';
                });
            } else {
                html = '<tr><td colspan="5" class="text-center text-muted p-3">No task details available.</td></tr>';
            }

            $('#globalModalTableBody').html(html);

            const modalEl = document.getElementById('globalTaskLogModal');
            if (modalEl) {
                if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                    try {
                        let modalObj = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
                        modalObj.show();
                        return;
                    } catch(e) {}
                }
                if ($.fn && $.fn.modal) {
                    try {
                        $('#globalTaskLogModal').modal('show');
                        return;
                    } catch(e) {}
                }
                // Direct DOM fallback
                modalEl.style.display = 'block';
                modalEl.classList.add('show');
                modalEl.removeAttribute('aria-hidden');
                document.body.classList.add('modal-open');
                if ($('.modal-backdrop').length === 0) {
                    $('<div class="modal-backdrop fade show"></div>').appendTo(document.body);
                }
            }
        }

        function closeTaskLogsModal() {
            const modalEl = document.getElementById('globalTaskLogModal');
            if (modalEl) {
                if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                    try {
                        let modalObj = bootstrap.Modal.getInstance(modalEl);
                        if (modalObj) { modalObj.hide(); return; }
                    } catch(e) {}
                }
                if ($.fn && $.fn.modal) {
                    try {
                        $('#globalTaskLogModal').modal('hide');
                        return;
                    } catch(e) {}
                }
                modalEl.style.display = 'none';
                modalEl.classList.remove('show');
                modalEl.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('modal-open');
                $('.modal-backdrop').remove();
            }
        }
    </script>
</body>
</html>
