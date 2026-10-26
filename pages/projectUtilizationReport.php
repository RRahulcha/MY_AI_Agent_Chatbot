<?php
session_start();

if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

include('../include/db_config.php');
date_default_timezone_set('Asia/Kolkata');

$userRole = strtolower($_SESSION['userRole'] ?? '');
if ($userRole === 'junior_intern' || $userRole === 'juniorintern') {
    echo "<script>alert('You are not authorized to access this page.'); window.location.href = 'login.php';</script>";
    exit;
}

// ── 1. Filters & Defaults ──
$fromDate      = isset($_GET['from_date']) && !empty($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$toDate        = isset($_GET['to_date']) && !empty($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$projectFilter = isset($_GET['project']) ? trim((string)$_GET['project']) : '';

$fromDateEsc      = mysqli_real_escape_string($con, $fromDate);
$toDateEsc        = mysqli_real_escape_string($con, $toDate);
$projectFilterEsc = mysqli_real_escape_string($con, $projectFilter);

// ── 2. Helper Functions ──
function formatSecToHHMM($seconds) {
    $hours   = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    return sprintf("%02d:%02d", $hours, $minutes);
}

function formatUserRoleLabel($designation, $userRole) {
    $raw = !empty($designation) ? $designation : (!empty($userRole) ? $userRole : 'Intern');
    $clean = strtolower(str_replace(['_', '-'], '', $raw));
    
    if (strpos($clean, 'seniorintern') !== false) return ['label' => 'Senior Intern', 'class' => 'bg-primary text-white'];
    if (strpos($clean, 'juniorintern') !== false) return ['label' => 'Junior Intern', 'class' => 'bg-info text-white'];
    if (strpos($clean, 'teamlead') !== false) return ['label' => 'Team Lead', 'class' => 'bg-purple text-white', 'style' => 'background:#6f42c1;color:#fff;'];
    if (strpos($clean, 'resourcehead') !== false) return ['label' => 'Resource Head', 'class' => 'bg-dark text-white'];
    if (strpos($clean, 'resourceallocator') !== false) return ['label' => 'Resource Allocator', 'class' => 'bg-warning text-dark'];
    
    return ['label' => ucwords(str_replace('_', ' ', $raw)), 'class' => 'bg-secondary text-white'];
}

// ── 3. Fetch Total Active Eligible Resource Pool ──
$activePoolQ = mysqli_query($con, "
    SELECT COUNT(DISTINCT s.userId) AS activeCount
    FROM signup s
    WHERE (TRIM(LOWER(s.status)) = 'active' OR s.status = '1' OR s.status = 1 OR s.status IS NULL OR s.status = '')
      AND (LOWER(s.regstatus) = 'approved' OR s.regstatus IS NULL OR s.regstatus = '')
      AND (s.designation IS NULL OR LOWER(s.designation) NOT IN ('admin', 'hr', 'superadmin', 'md'))
      AND (s.userRole IS NULL OR LOWER(s.userRole) NOT IN ('admin', 'hr', 'superadmin', 'md'))
");
$totalActivePoolCount = 0;
if ($activePoolQ && $aRow = mysqli_fetch_assoc($activePoolQ)) {
    $totalActivePoolCount = (int)$aRow['activeCount'];
}

// ── 4. Fetch Project Master Map ──
$projectClientMap = [];
$projListRes = mysqli_query($con, "SELECT DISTINCT projectName, client FROM projectMaster WHERE projectName != '' AND status=1 ORDER BY projectName ASC");
$projList = [];
if ($projListRes) {
    while ($m = mysqli_fetch_assoc($projListRes)) {
        $projList[] = $m;
        $projectClientMap[$m['projectName']] = $m['client'];
    }
}

// ── 5. Main Utilization Query (Only Active Interns & Employees) ──
$whereSql = "WHERE t.startTime >= '{$fromDateEsc} 00:00:00' AND t.startTime <= '{$toDateEsc} 23:59:59'";
if (!empty($projectFilterEsc)) {
    $whereSql .= " AND (t.projectName = '{$projectFilterEsc}' OR t.workType = '{$projectFilterEsc}')";
}

$query = "
    SELECT t.userId, t.projectName, t.workType, t.totalDuration, t.startTime, t.status, 
           COALESCE(NULLIF(s.fullName, ''), t.userId) AS fullName, 
           s.internId, s.emp_id, s.department, s.designation, s.userRole, s.status AS userStatus
    FROM TaskEntryNew t 
    INNER JOIN signup s ON t.userId COLLATE utf8mb4_unicode_ci = s.userId
    $whereSql
      AND (TRIM(LOWER(s.status)) = 'active' OR s.status = '1' OR s.status = 1 OR s.status IS NULL OR s.status = '')
      AND (LOWER(s.regstatus) = 'approved' OR s.regstatus IS NULL OR s.regstatus = '')
    ORDER BY t.projectName ASC, s.fullName ASC
";

$result = mysqli_query($con, $query);

// ── 6. Process Utilization Matrix ──
$reportData      = [];
$projectTotalSec = [];
$totalSystemSec  = 0;
$uniqueUsers     = [];

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $rawPName = trim((string)$row['projectName']);
        $workType = trim((string)$row['workType']);

        $isGeneralActivity = false;
        if (!empty($rawPName)) {
            $pName = $rawPName;
        } elseif (!empty($workType)) {
            $pName = $workType;
            $isGeneralActivity = true;
        } else {
            $pName = 'General Activity';
            $isGeneralActivity = true;
        }

        $duration = (int)$row['totalDuration'];

        if ($row['status'] === 'Running' && !empty($row['startTime'])) {
            $elapsed = time() - strtotime($row['startTime']);
            if ($elapsed > 0) $duration += $elapsed;
        }

        $empId   = $row['userId'];
        $dispId  = !empty($row['internId']) ? $row['internId'] : (!empty($row['emp_id']) ? $row['emp_id'] : $empId);
        $empName = !empty($row['fullName']) ? $row['fullName'] : $dispId;

        if (!isset($reportData[$pName])) {
            $reportData[$pName] = [
                'isGeneral' => $isGeneralActivity,
                'members'   => []
            ];
            $projectTotalSec[$pName] = 0;
        }

        if (!isset($reportData[$pName]['members'][$empId])) {
            $reportData[$pName]['members'][$empId] = [
                'userId'      => $empId,
                'dispId'      => $dispId,
                'name'        => $empName,
                'designation' => $row['designation'],
                'userRole'    => $row['userRole'],
                'department'  => !empty($row['department']) ? $row['department'] : 'General',
                'workType'    => !empty($workType) ? $workType : 'Task Work',
                'seconds'     => 0,
                'taskCount'   => 0
            ];
        }

        $reportData[$pName]['members'][$empId]['seconds'] += $duration;
        $reportData[$pName]['members'][$empId]['taskCount']++;
        $projectTotalSec[$pName] += $duration;
        $totalSystemSec += $duration;
        $uniqueUsers[$empId] = true;
    }
}

$activeProjectCount     = count($reportData);
$totalDeployedHeadcount = count($uniqueUsers);
$totalSystemHours       = round($totalSystemSec / 3600, 1);
$avgHoursPerProject     = $activeProjectCount > 0 ? round($totalSystemHours / $activeProjectCount, 1) : 0;
$poolCountDisplay       = max($totalDeployedHeadcount, $totalActivePoolCount);
$headcountUtilRate      = $poolCountDisplay > 0 ? round(($totalDeployedHeadcount / $poolCountDisplay) * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Project Wise Employee Utilization | SETTribe AMS</title>
    <?php include('../partials/_links.php'); ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css">

    <style>
        .kpi-card { 
            border-radius:14px; 
            border:1px solid #e2e8f0; 
            background:#ffffff; 
            padding:22px; 
            box-shadow:0 4px 14px rgba(0,0,0,0.03); 
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .kpi-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.06);
        }
        .kpi-title { font-size: 0.76rem; text-transform: uppercase; letter-spacing: 0.6px; font-weight: 700; color: #64748b; }
        .kpi-val { font-size: 1.75rem; font-weight: 800; color: #0f172a; margin-top: 4px; line-height: 1.2; }
        .kpi-icon { width:48px; height:48px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:1.25rem; flex-shrink:0; }
        .progress-share { height:9px; border-radius:6px; background:#e2e8f0; overflow:hidden; }
        .table-responsive { border-radius:12px; overflow:hidden; border:1px solid #e2e8f0; }
        .badge-role { font-size:0.7rem; font-weight:600; padding:4px 8px; border-radius:6px; }
        .bg-light-purple { background:#f3e8ff; color:#7c3aed; }
        .bg-light-blue { background:#e0f2fe; color:#0284c7; }
        .bg-light-green { background:#dcfce7; color:#15803d; }
        .bg-light-orange { background:#ffedd5; color:#c2410c; }

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
                            <h3 class="fw-bold mb-1" style="color:#0f172a;">
                                📊 Project-Wise Employee & Intern Utilization Analytics
                            </h3>
                            <p class="text-muted mb-0" style="font-size:0.85rem;">
                                Active Resource Management: Track exact logged manhours, contribution percentages, and active deployed headcount per project
                            </p>
                        </div>
                        <span class="badge px-3 py-2" style="border-radius:20px; font-size:0.8rem; font-weight:600; background-color:#4b49ac !important; color:#ffffff !important; box-shadow: 0 2px 8px rgba(75,73,172,0.3);">
                            Executive Utilization Intelligence
                        </span>
                    </div>

                    <!-- Filter Bar -->
                    <div class="card mb-4" style="border-radius:14px; border:1px solid #e2e8f0; box-shadow:0 2px 8px rgba(0,0,0,0.02);">
                        <div class="card-body p-3">
                            <form method="GET" action="projectUtilizationReport.php" class="row g-3 align-items-end">
                                <div class="col-lg-3 col-md-3 col-12">
                                    <label class="form-label fw-bold text-muted mb-1" style="font-size:0.78rem;">From Date</label>
                                    <input type="date" name="from_date" class="form-control custom-filter-input" value="<?= htmlspecialchars($fromDate) ?>" max="<?= date('Y-m-d') ?>" required>
                                </div>
                                <div class="col-lg-3 col-md-3 col-12">
                                    <label class="form-label fw-bold text-muted mb-1" style="font-size:0.78rem;">To Date</label>
                                    <input type="date" name="to_date" class="form-control custom-filter-input" value="<?= htmlspecialchars($toDate) ?>" max="<?= date('Y-m-d') ?>" required>
                                </div>
                                <div class="col-lg-3 col-md-3 col-12">
                                    <label class="form-label fw-bold text-muted mb-1" style="font-size:0.78rem;">Project</label>
                                    <select name="project" class="form-select custom-filter-select">
                                        <option value="">All Projects</option>
                                        <?php foreach ($projList as $pItem): 
                                            $pName = $pItem['projectName'];
                                            $pClient = trim($pItem['client'] ?? '');
                                            $pLabel = (!empty($pClient) && strtoupper($pClient) !== 'NA') ? ($pName . ' - ' . $pClient) : $pName;
                                            $selP = ($projectFilter === $pName) ? 'selected' : '';
                                        ?>
                                            <option value="<?= htmlspecialchars((string)$pName) ?>" <?= $selP ?>><?= htmlspecialchars((string)$pLabel) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-lg-3 col-md-3 col-12 d-flex gap-2">
                                    <button type="submit" class="btn btn-filter-submit flex-grow-1"><i class="fa fa-filter me-1"></i> Filter</button>
                                    <a href="projectUtilizationReport.php" class="btn btn-filter-reset" title="Reset Filters"><i class="fa fa-undo"></i></a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- KPI Cards -->
                    <div class="row mb-4">
                        <div class="col-md-3 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-title">Active Projects</div>
                                    <div class="kpi-val text-primary"><?= $activeProjectCount ?> <span style="font-size:0.85rem; font-weight:600;">projects</span></div>
                                    <small class="text-muted" style="font-size:0.75rem;"><i class="fa fa-circle-check text-success me-1"></i>With logged manhours</small>
                                </div>
                                <div class="kpi-icon bg-light-blue"><i class="fa fa-folder-open"></i></div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-title">Deployed Active Headcount</div>
                                    <div class="kpi-val text-purple" style="color:#7c3aed;"><?= $totalDeployedHeadcount ?> <span style="font-size:0.85rem; font-weight:600;">members</span></div>
                                    <small class="text-muted" style="font-size:0.75rem;"><i class="fa fa-users text-primary me-1"></i><?= $headcountUtilRate ?>% of <?= $poolCountDisplay ?> active pool</small>
                                </div>
                                <div class="kpi-icon bg-light-purple"><i class="fa fa-users"></i></div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-title">Total Logged Manhours</div>
                                    <div class="kpi-val text-success"><?= formatSecToHHMM($totalSystemSec) ?></div>
                                    <small class="text-muted" style="font-size:0.75rem;"><i class="fa fa-calculator text-muted me-1"></i><?= $totalSystemHours ?> Decimal Hours</small>
                                </div>
                                <div class="kpi-icon bg-light-green"><i class="fa fa-clock"></i></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-title">Avg Hours / Project</div>
                                    <div class="kpi-val text-warning" style="color:#c2410c;"><?= $avgHoursPerProject ?> <span style="font-size:0.85rem; font-weight:600;">hrs</span></div>
                                    <small class="text-muted" style="font-size:0.75rem;"><i class="fa fa-chart-line text-warning me-1"></i>Average effort per project</small>
                                </div>
                                <div class="kpi-icon bg-light-orange"><i class="fa fa-chart-pie"></i></div>
                            </div>
                        </div>
                    </div>

                    <!-- Utilization Data Table -->
                    <div class="card" style="border-radius:14px; border:1px solid #e2e8f0; box-shadow:0 4px 14px rgba(0,0,0,0.03);">
                        <div class="card-body p-4">
                            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                                <h5 class="fw-bold text-dark mb-0"><i class="fa fa-table me-2 text-primary"></i> Project-Wise Active Employee & Intern Utilization Matrix</h5>
                                <span class="badge bg-light text-dark border px-3 py-2" style="font-size:0.8rem; border-radius:20px; font-weight:600;">
                                    Period: <?= date('d M Y', strtotime($fromDate)) ?> – <?= date('d M Y', strtotime($toDate)) ?>
                                </span>
                            </div>

                            <div class="table-responsive">
                                <table id="utilizationTable" class="table table-hover align-middle mb-0" style="font-size:0.85rem;">
                                    <thead class="table-light text-secondary text-uppercase" style="font-size:0.74rem; letter-spacing:0.4px;">
                                        <tr>
                                            <th>Project Name & Client</th>
                                            <th>Employee / Intern Details</th>
                                            <th>Role & Designation</th>
                                            <th>Department</th>
                                            <th>Work Type</th>
                                            <th class="text-center">Time Logged (HH:MM)</th>
                                            <th class="text-center">Decimal Hours</th>
                                            <th class="text-center" style="width:140px;">Project Share %</th>
                                            <th class="text-center">Contribution Role</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($reportData)):
                                            foreach ($reportData as $pName => $pData):
                                                $empMap  = $pData['members'];
                                                $pTotSec = max(1, $projectTotalSec[$pName]);
                                                $client  = $projectClientMap[$pName] ?? '';
                                                $isGen   = $pData['isGeneral'];

                                                foreach ($empMap as $emp):
                                                    $sec    = $emp['seconds'];
                                                    $decHrs = round($sec / 3600, 2);
                                                    $hhmm   = formatSecToHHMM($sec);
                                                    $share  = round(($sec / $pTotSec) * 100, 1);

                                                    $cBadge = $share >= 25 ? 'bg-success text-white' : ($share >= 10 ? 'bg-info text-white' : 'bg-light text-dark border');
                                                    $cRole  = $share >= 25 ? '🟢 Key Driver (>25%)' : ($share >= 10 ? '🔵 Core Contributor' : '⚪ Support (<10%)');
                                                    
                                                    $roleMeta = formatUserRoleLabel($emp['designation'], $emp['userRole']);
                                        ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-bold text-dark"><?= htmlspecialchars($pName) ?></div>
                                                    <?php if (!empty($client)): ?>
                                                        <div class="text-muted small"><i class="fa fa-building me-1 text-secondary"></i> <?= htmlspecialchars($client) ?></div>
                                                    <?php elseif ($isGen): ?>
                                                        <div class="text-muted small"><span class="badge bg-light text-dark border" style="font-size:0.65rem;">Internal Activity</span></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="fw-bold text-dark"><?= htmlspecialchars($emp['name']) ?></div>
                                                    <div class="text-muted small">ID: <code><?= htmlspecialchars($emp['dispId']) ?></code></div>
                                                </td>
                                                <td>
                                                    <span class="badge badge-role <?= $roleMeta['class'] ?>" style="<?= $roleMeta['style'] ?? '' ?>">
                                                        <?= htmlspecialchars($roleMeta['label']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-light text-dark border"><?= htmlspecialchars($emp['department']) ?></span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-light-blue border"><?= htmlspecialchars($emp['workType']) ?></span>
                                                </td>
                                                <td class="text-center fw-bold text-dark">
                                                    <?= $hhmm ?>
                                                </td>
                                                <td class="text-center fw-bold text-primary">
                                                    <?= $decHrs ?> hrs
                                                </td>
                                                <td class="text-center">
                                                    <div class="d-flex align-items-center justify-content-between mb-1" style="font-size:0.75rem;">
                                                        <span class="fw-bold"><?= $share ?>%</span>
                                                    </div>
                                                    <div class="progress-share">
                                                        <div class="progress-bar bg-primary" style="width: <?= min(100, $share) ?>%; height:100%;"></div>
                                                    </div>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge <?= $cBadge ?> px-2 py-1"><?= $cRole ?></span>
                                                </td>
                                            </tr>
                                        <?php endforeach;
                                            endforeach;
                                        else: ?>
                                            <tr>
                                                <td colspan="9" class="text-center py-4 text-muted">
                                                    <i class="fa fa-info-circle fa-2x mb-2 d-block text-secondary"></i>
                                                    No active project utilization records found for the selected date range.
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
            $('#utilizationTable').DataTable({
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