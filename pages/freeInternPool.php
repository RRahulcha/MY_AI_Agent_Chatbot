<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

include('../include/db_config.php');
require_once('availabilityEngine.php');

$userId  = $_SESSION['userId'];
$userRow = mysqli_fetch_assoc(mysqli_query($con, "SELECT fullName, userRole, designation FROM signup WHERE userId='" . mysqli_real_escape_string($con, $userId) . "'"));
$userName = $userRow ? htmlspecialchars($userRow['fullName']) : 'Unknown';

// Date Parameter (Default to Today)
$selectedDate = isset($_GET['date']) ? trim($_GET['date']) : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = date('Y-m-d');
}

$formattedDateDisplay = date('d M Y', strtotime($selectedDate));
$isToday = ($selectedDate === date('Y-m-d'));

// ── Fetch all active interns (excluding management, HR, and admin staff) ──
$mgtRoles = ['hr', 'resourceallocator', 'resource_allocator', 'resource allocator', 'resourcemanager', 'resource_manager', 'resourcehead', 'resource_head', 'admin', 'superadmin', 'md', 'management', 'director', 'manager', 'receptionist', 'staff'];
$mgtRolesSql = "'" . implode("','", $mgtRoles) . "'";

$internResult = mysqli_query($con,
    "SELECT s.internId, s.fullName, s.userRole, s.designation,
            COALESCE(i.role, s.designation, 'Intern') AS role,
            COALESCE(i.batch, 'General') AS batch
     FROM   signup s
     LEFT   JOIN interns i ON s.internId COLLATE utf8mb4_unicode_ci = i.internId COLLATE utf8mb4_unicode_ci
     WHERE  (TRIM(LOWER(s.status)) = 'active' OR s.status = '1' OR s.status = 1 OR s.status IS NULL OR s.status = '')
       AND  s.internId IS NOT NULL AND TRIM(s.internId) != ''
       AND  TRIM(LOWER(COALESCE(s.userRole, ''))) NOT IN ($mgtRolesSql)
       AND  TRIM(LOWER(COALESCE(s.designation, ''))) NOT IN ($mgtRolesSql)
       AND  TRIM(LOWER(COALESCE(i.role, ''))) NOT IN ($mgtRolesSql)
     ORDER  BY s.fullName ASC"
);
if (!$internResult) die("Query failed: " . mysqli_error($con));

// ── Analytics Aggregators ─────────────────────────────────────────
$internData         = [];
$distinctRoles      = [];
$distinctBatches    = [];
$totalCapacityMins  = 0;
$totalUsedMins      = 0;
$totalFreeMins      = 0;
$roleStats          = [];

while ($row = mysqli_fetch_assoc($internResult)) {
    $uRole  = strtolower(trim($row['userRole'] ?? ''));
    $uDesig = strtolower(trim($row['designation'] ?? ''));
    $iRole  = strtolower(trim($row['role'] ?? ''));

    if (in_array($uRole, $mgtRoles) || in_array($uDesig, $mgtRoles) || in_array($iRole, $mgtRoles)) {
        continue;
    }

    $iid   = $row['internId'];
    $role  = trim($row['role']);
    $batch = trim($row['batch']);

    if ($role && !in_array($role, $distinctRoles)) $distinctRoles[] = $role;
    if ($batch && !in_array($batch, $distinctBatches)) $distinctBatches[] = $batch;

    // Availability Snapshot
    $snap = calculateAvailabilityForDate($con, $iid, $selectedDate);

    $freeMins  = $snap['available'] ? (int)$snap['freeMinutes'] : 0;
    $totalMins = $snap['available'] ? (int)$snap['totalMinutes'] : 0;
    $usedMins  = $snap['available'] ? (int)$snap['usedMinutes'] : 0;

    $totalCapacityMins += $totalMins;
    $totalUsedMins     += $usedMins;
    $totalFreeMins     += $freeMins;

    // Human-readable hours
    $totalHours = $totalMins > 0 ? round($totalMins / 60, 1) : 0;
    $usedHours  = round($usedMins / 60, 1);
    $freeHours  = round($freeMins / 60, 1);

    $isBlocked   = !$snap['available'];
    $blockReason = $snap['reason'] ?? '';

    if ($isBlocked) {
        $reasonLabels = [
            'sunday'         => 'Non-Working Day',
            'holiday'        => 'Company Holiday',
            'leave'          => 'On Approved Leave',
            'tour'           => 'On Official Tour',
            'no_work_window' => 'No Schedule Configured',
        ];
        $status      = 'blocked';
        $statusLabel = $reasonLabels[$blockReason] ?? ucfirst($blockReason);
        $statusClass = 'badge-blocked';
        $rowClass    = 'table-secondary';
    } elseif ($usedMins === 0) {
        $status      = 'fully_free';
        $statusLabel = formatHours($freeMins) . ' Available';
        $statusClass = 'badge-fully-free';
        $rowClass    = '';
    } elseif ($freeMins > 0) {
        $status      = 'partial';
        $statusLabel = formatHours($freeMins) . ' Available';
        $statusClass = 'badge-partial';
        $rowClass    = '';
    } else {
        $status      = 'full';
        $statusLabel = 'Fully Booked';
        $statusClass = 'badge-full';
        $rowClass    = 'table-secondary';
    }

    // Role Stats Aggregation
    if (!isset($roleStats[$role])) {
        $roleStats[$role] = ['total' => 0, 'fully_free' => 0, 'partial' => 0, 'full' => 0, 'blocked' => 0];
    }
    $roleStats[$role]['total']++;
    if (!isset($roleStats[$role][$status])) {
        $roleStats[$role][$status] = 0;
    }
    $roleStats[$role][$status]++;

    $internData[] = [
        'internId'    => $iid,
        'fullName'    => $row['fullName'],
        'role'        => $role,
        'batch'       => $batch,
        'usedMins'    => $usedMins,
        'freeMins'    => $freeMins,
        'totalMins'   => $totalMins,
        'usedHours'   => $usedHours,
        'freeHours'   => $freeHours,
        'totalHours'  => $totalHours,
        'workStart'   => $snap['workStartTime'] ? to12hr($snap['workStartTime']) : '09:00 AM',
        'workEnd'     => $snap['workEndTime'] ? to12hr($snap['workEndTime']) : '05:00 PM',
        'isBlocked'   => $isBlocked,
        'blockReason' => $blockReason,
        'status'      => $status,
        'statusLabel' => $statusLabel,
        'statusClass' => $statusClass,
        'rowClass'    => $rowClass,
    ];
}

sort($distinctRoles);
sort($distinctBatches);

// Sort: fully free first, partial second, full/blocked last
usort($internData, function($a, $b) {
    $order = ['fully_free' => 0, 'partial' => 1, 'full' => 2, 'blocked' => 3];
    return ($order[$a['status']] ?? 9) - ($order[$b['status']] ?? 9);
});

$totalInterns  = count($internData);
$freeCount     = count(array_filter($internData, fn($i) => $i['status'] === 'fully_free'));
$partialCount  = count(array_filter($internData, fn($i) => $i['status'] === 'partial'));
$fullCount     = count(array_filter($internData, fn($i) => $i['status'] === 'full'));
$blockedCount  = count(array_filter($internData, fn($i) => $i['status'] === 'blocked'));

$totalCapacityHours = round($totalCapacityMins / 60, 1);
$totalUsedHours     = round($totalUsedMins / 60, 1);
$totalFreeHours     = round($totalFreeMins / 60, 1);

$utilizationPct = $totalCapacityMins > 0 ? round(($totalUsedMins / $totalCapacityMins) * 100, 1) : 0;
$availablePct   = $totalCapacityMins > 0 ? round(($totalFreeMins / $totalCapacityMins) * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Free Intern Pool & Capacity Analytics</title>
<?php include('../partials/_links.php'); ?>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
<style>
:root {
    --primary-bg: #4b49ac;
    --primary-dark: #3a39a0;
    --success-color: #10b981;
    --warning-color: #f59e0b;
    --danger-color: #ef4444;
    --info-color: #3b82f6;
    --card-shadow: 0 4px 18px rgba(0, 0, 0, 0.05);
}

body {
    background-color: #f4f5fb;
}

/* Header Banner */
.analytics-header-banner {
    background: linear-gradient(135deg, #3a39a0 0%, #4b49ac 50%, #6c6bcc 100%);
    border-radius: 14px;
    padding: 22px 28px;
    color: #fff;
    box-shadow: 0 8px 24px rgba(75, 73, 172, 0.2);
    margin-bottom: 24px;
}

.header-title-badge {
    background: rgba(255,255,255,0.18);
    border: 1px solid rgba(255,255,255,0.3);
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.76rem;
    font-weight: 700;
}

/* KPI Cards */
.kpi-card {
    background: #fff;
    border-radius: 12px;
    padding: 18px 22px;
    box-shadow: var(--card-shadow);
    border: 1px solid #eef0f8;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    transition: transform 0.2s, box-shadow 0.2s;
}
.kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.08);
}
.kpi-icon-circle {
    width: 52px;
    height: 52px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.4rem;
    flex-shrink: 0;
}
.kpi-icon-primary { background: #eef1ff; color: #4b49ac; }
.kpi-icon-success { background: #d1fae5; color: #10b981; }
.kpi-icon-warning { background: #fef3c7; color: #f59e0b; }
.kpi-icon-danger  { background: #fee2e2; color: #ef4444; }

.kpi-num   { font-size: 1.85rem; font-weight: 800; color: #1e1b4b; line-height: 1.1; }
.kpi-label { font-size: 0.82rem; font-weight: 600; color: #6b7280; margin-top: 4px; }
.kpi-sub   { font-size: 0.74rem; color: #9ca3af; margin-top: 2px; }

/* Chart Cards */
.chart-card {
    background: #fff;
    border-radius: 12px;
    padding: 20px;
    box-shadow: var(--card-shadow);
    border: 1px solid #eef0f8;
    margin-bottom: 24px;
}
.chart-card-title {
    font-size: 0.95rem;
    font-weight: 700;
    color: #1f2937;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

/* Status Badges */
.badge-status-pill {
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 0.76rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.badge-fully-free { background: #d1fae5; color: #047857; border: 1px solid #a7f3d0; }
.badge-partial    { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
.badge-full       { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }
.badge-blocked    { background: #f3f4f6; color: #4b5563; border: 1px solid #e5e7eb; }

/* Capacity Bar */
.cap-bar-wrapper {
    display: flex;
    align-items: center;
    gap: 10px;
}
.cap-bar-bg {
    flex: 1;
    height: 8px;
    background: #e5e7eb;
    border-radius: 99px;
    overflow: hidden;
}
.cap-bar-fill {
    height: 100%;
    border-radius: 99px;
    transition: width 0.4s ease;
}

/* Custom Table Styling */
.table-custom-wrap {
    background: #fff;
    border-radius: 12px;
    box-shadow: var(--card-shadow);
    border: 1px solid #eef0f8;
    padding: 20px;
}
.table-custom standard thead th {
    background: #3a39a0 !important;
    color: #fff !important;
    font-weight: 700;
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    padding: 12px 16px;
}
.table-custom tbody tr:hover td {
    background: #f8f9fd !important;
}

.btn-request-action {
    background: linear-gradient(135deg, #4b49ac, #3a39a0);
    color: #fff !important;
    border: none;
    border-radius: 6px;
    padding: 5px 14px;
    font-size: 0.78rem;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    box-shadow: 0 2px 6px rgba(75,73,172,0.25);
    transition: all 0.18s;
}
.btn-request-action:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(75,73,172,0.35);
}
.btn-disabled-action {
    background: #e5e7eb;
    color: #9ca3af !important;
    border-radius: 6px;
    padding: 5px 14px;
    font-size: 0.78rem;
    font-weight: 600;
    cursor: not-allowed;
    display: inline-flex;
    align-items: center;
    gap: 5px;
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

            <!-- ── Top Header Banner ── -->
            <div class="analytics-header-banner">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <h3 class="m-0" style="font-weight:800;color:#fff;">
                                <i class="fa fa-users me-2"></i> Free Intern Pool & Capacity Analytics
                            </h3>
                            <span class="header-title-badge">
                                <?php echo $isToday ? 'Today\'s Live Snapshot' : 'Date: ' . $formattedDateDisplay; ?>
                            </span>
                        </div>
                        <p class="m-0" style="font-size:0.88rem;color:rgba(255,255,255,0.85);">
                            Real-time capacity utilization, unallocated working hours, and intern deployment report.
                        </p>
                    </div>

                    <!-- Date Selection Form -->
                    <form method="GET" action="freeInternPool.php" class="d-flex align-items-center gap-2">
                        <label style="font-size:0.8rem;font-weight:700;color:#fff;margin:0;white-space:nowrap;">Select Date:</label>
                        <input type="date" name="date" class="form-control form-control-sm"
                               value="<?php echo htmlspecialchars($selectedDate); ?>"
                               onchange="this.form.submit()"
                               style="border-radius:8px;font-weight:700;color:#4b49ac;width:150px;">
                        <?php if (!$isToday): ?>
                            <a href="freeInternPool.php" class="btn btn-sm btn-light" style="border-radius:8px;font-weight:700;font-size:0.78rem;">
                                Reset to Today
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- ── Executive KPI Row ── -->
            <div class="row mb-4">
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="kpi-card">
                        <div>
                            <div class="kpi-num"><?php echo $totalInterns; ?></div>
                            <div class="kpi-label">Active Interns</div>
                            <div class="kpi-sub">Total tracked in system</div>
                        </div>
                        <div class="kpi-icon-circle kpi-icon-primary">
                            <i class="fa fa-users"></i>
                        </div>
                    </div>
                </div>

                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="kpi-card">
                        <div>
                            <div class="kpi-num"><?php echo $freeCount + $partialCount; ?></div>
                            <div class="kpi-label">Available Interns</div>
                            <div class="kpi-sub"><?php echo $freeCount; ?> 100% Free | <?php echo $partialCount; ?> Partial</div>
                        </div>
                        <div class="kpi-icon-circle kpi-icon-success">
                            <i class="fa fa-check-circle"></i>
                        </div>
                    </div>
                </div>

                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="kpi-card">
                        <div>
                            <div class="kpi-num"><?php echo $totalFreeHours; ?> <span style="font-size:1rem;">hrs</span></div>
                            <div class="kpi-label">Unallocated Capacity</div>
                            <div class="kpi-sub"><?php echo $availablePct; ?>% of total capacity free</div>
                        </div>
                        <div class="kpi-icon-circle kpi-icon-warning">
                            <i class="fa fa-clock-o"></i>
                        </div>
                    </div>
                </div>

                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="kpi-card">
                        <div>
                            <div class="kpi-num"><?php echo $utilizationPct; ?>%</div>
                            <div class="kpi-label">Capacity Utilization</div>
                            <div class="kpi-sub"><?php echo $totalUsedHours; ?> hrs allocated out of <?php echo $totalCapacityHours; ?> hrs</div>
                        </div>
                        <div class="kpi-icon-circle kpi-icon-danger">
                            <i class="fa fa-line-chart"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Analytics Charts Row ── -->
            <div class="row mb-4">
                <!-- Capacity Utilization Donut Chart -->
                <div class="col-md-5 mb-3">
                    <div class="chart-card">
                        <div class="chart-card-title">
                            <span><i class="fa fa-pie-chart me-2" style="color:#4b49ac;"></i> Capacity Breakdown (Hours)</span>
                            <small class="text-muted"><?php echo $totalCapacityHours; ?> Total Hrs</small>
                        </div>
                        <div style="height: 240px; position: relative;">
                            <canvas id="chartCapacityDonut"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Intern Availability Status Distribution Bar Chart -->
                <div class="col-md-7 mb-3">
                    <div class="chart-card">
                        <div class="chart-card-title">
                            <span><i class="fa fa-bar-chart me-2" style="color:#10b981;"></i> Availability Status Distribution</span>
                            <small class="text-muted"><?php echo $totalInterns; ?> Total Interns</small>
                        </div>
                        <div style="height: 240px; position: relative;">
                            <canvas id="chartStatusBar"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Data Table & Toolbar ── -->
            <div class="table-custom-wrap">
                <!-- Filter Controls Toolbar -->
                <div class="row align-items-center mb-3">
                    <div class="col-md-3 mb-2">
                        <label style="font-size:0.78rem;font-weight:700;color:#555;">Filter by Status:</label>
                        <select id="filterStatus" class="form-control form-control-sm" style="border-radius:8px;">
                            <option value="all">All Statuses (<?php echo $totalInterns; ?>)</option>
                            <option value="fully_free">Fully Available (<?php echo $freeCount; ?>)</option>
                            <option value="partial">Partially Available (<?php echo $partialCount; ?>)</option>
                            <option value="full">Fully Booked (<?php echo $fullCount; ?>)</option>
                            <option value="blocked">On Leave / Holiday (<?php echo $blockedCount; ?>)</option>
                        </select>
                    </div>

                    <div class="col-md-3 mb-2">
                        <label style="font-size:0.78rem;font-weight:700;color:#555;">Filter by Role:</label>
                        <select id="filterRole" class="form-control form-control-sm" style="border-radius:8px;">
                            <option value="all">All Roles (<?php echo count($distinctRoles); ?>)</option>
                            <?php foreach ($distinctRoles as $rOpt): ?>
                                <option value="<?php echo htmlspecialchars($rOpt); ?>"><?php echo htmlspecialchars($rOpt); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-3 mb-2">
                        <label style="font-size:0.78rem;font-weight:700;color:#555;">Filter by Batch:</label>
                        <select id="filterBatch" class="form-control form-control-sm" style="border-radius:8px;">
                            <option value="all">All Batches (<?php echo count($distinctBatches); ?>)</option>
                            <?php foreach ($distinctBatches as $bOpt): ?>
                                <option value="<?php echo htmlspecialchars($bOpt); ?>"><?php echo htmlspecialchars($bOpt); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-3 text-md-right mb-2">
                        <label class="d-none d-md-block mb-1">&nbsp;</label>
                        <button type="button" id="btnResetFilters" class="btn btn-sm btn-outline-secondary" style="border-radius:8px;font-size:0.78rem;font-weight:600;">
                            <i class="fa fa-refresh me-1"></i> Reset Filters
                        </button>
                    </div>
                </div>

                <!-- Main Data Table -->
                <div class="table-responsive">
                    <table id="freePoolTable" class="table table-hover align-middle" style="width:100%;">
                        <thead style="background:#3a39a0;color:#fff;">
                            <tr>
                                <th style="width:4%;">#</th>
                                <th style="width:18%;">Intern Name</th>
                                <th style="width:14%;">Role &amp; Batch</th>
                                <th style="width:14%;">Work Window</th>
                                <th style="width:24%;">Capacity Utilization (<?php echo $formattedDateDisplay; ?>)</th>
                                <th style="width:14%;">Status</th>
                                <th style="width:12%;text-align:center;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        $serial = 1;
                        foreach ($internData as $intern):
                            $pctUsed = ($intern['totalMins'] > 0)
                                ? min(100, round(($intern['usedMins'] / $intern['totalMins']) * 100))
                                : 0;
                            $barColor = ($intern['status'] === 'fully_free') ? '#10b981'
                                      : (($intern['status'] === 'partial')   ? '#f59e0b' : '#ef4444');
                        ?>
                            <tr data-status="<?php echo $intern['status']; ?>"
                                data-role="<?php echo htmlspecialchars($intern['role']); ?>"
                                data-batch="<?php echo htmlspecialchars($intern['batch']); ?>">
                                <td><?php echo $serial++; ?></td>

                                <td>
                                    <strong style="color:#1f2937;font-size:0.88rem;"><?php echo htmlspecialchars($intern['fullName']); ?></strong>
                                    <small class="d-block text-muted" style="font-size:0.74rem;">ID: <?php echo htmlspecialchars($intern['internId']); ?></small>
                                </td>

                                <td>
                                    <div style="font-weight:600;color:#374151;font-size:0.82rem;"><?php echo htmlspecialchars($intern['role']); ?></div>
                                    <small style="color:#6b7280;font-size:0.75rem;">Batch: <?php echo htmlspecialchars($intern['batch']); ?></small>
                                </td>

                                <td>
                                    <small style="color:#4b49ac;font-weight:700;background:#eef1ff;padding:3px 8px;border-radius:6px;">
                                        <i class="fa fa-clock-o me-1"></i><?php echo $intern['workStart'] . ' – ' . $intern['workEnd']; ?>
                                    </small>
                                </td>

                                <td>
                                    <?php if ($intern['isBlocked']): ?>
                                        <div style="font-size:0.78rem;color:#6b7280;font-weight:600;">
                                            <i class="fa fa-ban me-1 text-danger"></i><?php echo htmlspecialchars($intern['statusLabel']); ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="cap-bar-wrapper">
                                            <div class="cap-bar-bg">
                                                <div class="cap-bar-fill" style="width:<?php echo $pctUsed; ?>%;background:<?php echo $barColor; ?>;"></div>
                                            </div>
                                            <span style="font-size:0.78rem;font-weight:700;color:#374151;min-width:90px;">
                                                <?php echo $intern['usedHours']; ?>h / <?php echo $intern['totalHours']; ?>h used
                                            </span>
                                        </div>
                                        <small style="font-size:0.73rem;color:#047857;font-weight:600;" class="mt-1 d-block">
                                            <i class="fa fa-check me-1"></i><?php echo $intern['freeHours']; ?> hrs free capacity
                                        </small>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <span class="badge-status-pill <?php echo $intern['statusClass']; ?>">
                                        <?php if ($intern['status'] === 'fully_free'): ?>
                                            <i class="fa fa-check-circle"></i>
                                        <?php elseif ($intern['status'] === 'partial'): ?>
                                            <i class="fa fa-adjust"></i>
                                        <?php elseif ($intern['status'] === 'full'): ?>
                                            <i class="fa fa-times-circle"></i>
                                        <?php else: ?>
                                            <i class="fa fa-ban"></i>
                                        <?php endif; ?>
                                        <span><?php echo htmlspecialchars($intern['statusLabel']); ?></span>
                                    </span>
                                </td>

                                <td style="text-align:center;">
                                    <?php if ($intern['isBlocked']): ?>
                                        <span class="btn-disabled-action">
                                            <i class="fa fa-ban"></i> Blocked
                                        </span>
                                    <?php elseif ($intern['status'] !== 'full'): ?>
                                        <a href="resourceRequest.php?internId=<?php echo urlencode($intern['internId']); ?>&startDate=<?php echo urlencode($selectedDate); ?>&endDate=<?php echo urlencode($selectedDate); ?>&freeHours=<?php echo $intern['freeHours']; ?>"
                                           class="btn-request-action">
                                            <i class="fa fa-paper-plane me-1"></i> Request
                                        </a>
                                    <?php else: ?>
                                        <span class="btn-disabled-action">
                                            <i class="fa fa-check-square-o"></i> Booked
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div><!-- /content-wrapper -->
        </div><!-- /main-panel -->
    </div><!-- /page-body-wrapper -->
</div><!-- /container-scroller -->

<!-- Core JS -->
<script src="../vendors/js/vendor.bundle.base.js"></script>
<script src="../js/off-canvas.js"></script>
<script src="../js/template.js"></script>
<script src="../js/settings.js"></script>

<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<script>
$(document).ready(function () {

    // DataTables Initialization
    var table = $('#freePoolTable').DataTable({
        pageLength: 15,
        lengthMenu: [[10, 15, 25, 50, -1], [10, 15, 25, 50, 'All']],
        ordering:   true,
        order:      [[5, 'asc']], // Order by Status
        info:       true,
        searching:  true,
        autoWidth:  false,
        scrollX:    true,
        language: {
            search: "",
            searchPlaceholder: "Search by intern, role, or status…",
            lengthMenu: "Show _MENU_ interns"
        }
    });

    // Custom DataTables Filter for Status, Role, Batch
    $.fn.dataTable.ext.search.push(
        function( settings, data, dataIndex ) {
            if (settings.nTable.id !== 'freePoolTable') return true;

            var row       = table.row(dataIndex).node();
            var status    = $(row).data('status');
            var role      = $(row).data('role');
            var batch     = $(row).data('batch');

            var selStatus = $('#filterStatus').val();
            var selRole   = $('#filterRole').val();
            var selBatch  = $('#filterBatch').val();

            if (selStatus && selStatus !== 'all' && status !== selStatus) return false;
            if (selRole && selRole !== 'all' && role !== selRole) return false;
            if (selBatch && selBatch !== 'all' && batch !== selBatch) return false;

            return true;
        }
    );

    // Event listeners for filters
    $('#filterStatus, #filterRole, #filterBatch').on('change', function() {
        table.draw();
    });

    $('#btnResetFilters').on('click', function() {
        $('#filterStatus').val('all');
        $('#filterRole').val('all');
        $('#filterBatch').val('all');
        table.search('').draw();
    });

    // ── Chart 1: Capacity Utilization Donut Chart ──
    var ctxDonut = document.getElementById('chartCapacityDonut').getContext('2d');
    new Chart(ctxDonut, {
        type: 'doughnut',
        data: {
            labels: ['Free Hours', 'Allocated Hours'],
            datasets: [{
                data: [<?php echo $totalFreeHours; ?>, <?php echo $totalUsedHours; ?>],
                backgroundColor: ['#10b981', '#4b49ac'],
                hoverBackgroundColor: ['#059669', '#3a39a0'],
                borderWidth: 2,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 12, weight: '600' } } },
                tooltip: {
                    callbacks: {
                        label: function(ctx) {
                            var val = ctx.raw || 0;
                            var pct = ((val / <?php echo max(1, $totalCapacityHours); ?>) * 100).toFixed(1);
                            return ' ' + ctx.label + ': ' + val + ' hrs (' + pct + '%)';
                        }
                    }
                }
            },
            cutout: '70%'
        }
    });

    // ── Chart 2: Availability Status Distribution Bar Chart ──
    var ctxBar = document.getElementById('chartStatusBar').getContext('2d');
    new Chart(ctxBar, {
        type: 'bar',
        data: {
            labels: ['Fully Available', 'Partially Available', 'Fully Booked', 'On Leave / Holiday'],
            datasets: [{
                label: 'Intern Count',
                data: [<?php echo $freeCount; ?>, <?php echo $partialCount; ?>, <?php echo $fullCount; ?>, <?php echo $blockedCount; ?>],
                backgroundColor: ['#10b981', '#f59e0b', '#ef4444', '#9ca3af'],
                borderRadius: 6,
                barThickness: 28
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(ctx) { return ' Interns: ' + ctx.raw; }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { stepSize: 1, precision: 0 }
                },
                x: {
                    grid: { display: false }
                }
            }
        }
    });

});
</script>

<?php
require_once('../include/sweetAlert.php');
if (isset($_SESSION['status'])) {
    if (!$_SESSION['status']) toastMsg('error', $_SESSION['msg']);
    else toastMsg('success', $_SESSION['msg']);
    unset($_SESSION['status'], $_SESSION['msg']);
}
?>
</body>
</html>