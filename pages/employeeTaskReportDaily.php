<?php
session_start();
include('../include/db_config.php');

/* ACCESS CHECK */
if (!isset($_SESSION['userId'])) {
    echo "<script>window.location.href='login.php';</script>";
    exit;
}

$filterActive = isset($_GET['filter']) && $_GET['filter'] == '1';

// Pre-process filter data if requested
$taskRows           = [];
$totalTaskCount     = 0;
$totalWorkMinutes   = 0;
$completedCount     = 0;
$inProgressCount    = 0;
$pendingCount       = 0;
$distinctProjects   = [];
$distinctEmployees  = [];

if ($filterActive) {
    $where = [];

    /* TYPE */
    if (!empty($_GET['type'])) {
        $type = mysqli_real_escape_string($con, $_GET['type']);
        if ($type === 'online') {
            $where[] = "s.mode = 'Online'";
        } else {
            $where[] = "s.office = '$type'";
        }
    }

    /* EMPLOYEE */
    if (!empty($_GET['employee'])) {
        $emp = mysqli_real_escape_string($con, $_GET['employee']);
        $where[] = "ten.userId = '$emp'";
    }

    /* DATE */
    $date = !empty($_GET['date']) ? mysqli_real_escape_string($con, $_GET['date']) : date('Y-m-d');
    $where[] = "ten.startTime BETWEEN '$date 00:00:00' AND '$date 23:59:59'";

    /* PROJECT */
    if (!empty($_GET['project'])) {
        $proj = mysqli_real_escape_string($con, $_GET['project']);
        $where[] = "ten.projectName = '$proj'";
    }

    $where[] = "s.type != 'admin'";

    $sql = "SELECT ten.*, s.fullName, s.emp_id, s.internId
            FROM TaskEntryNew ten
            LEFT JOIN signup s ON ten.userId = s.userId
            WHERE " . implode(" AND ", $where) . "
            ORDER BY ten.startTime DESC";

    $res = mysqli_query($con, $sql);

    if ($res) {
        $rawRows = [];
        $runningTaskIds = [];
        while ($row = mysqli_fetch_assoc($res)) {
            $rawRows[] = $row;
            if (strtolower(trim($row['status'] ?? '')) === 'running') {
                $runningTaskIds[] = (int)$row['taskId'];
            }
        }

        $resumeMap = [];
        if (!empty($runningTaskIds)) {
            $taskIdsStr = implode(',', array_unique($runningTaskIds));
            $rmq = mysqli_query($con, "
                SELECT taskId, resumeTime FROM taskStatus
                WHERE taskId IN ($taskIdsStr)
                  AND status IN ('Started','Running')
                ORDER BY id DESC
            ");
            if ($rmq) {
                while ($rr = mysqli_fetch_assoc($rmq)) {
                    if (!isset($resumeMap[$rr['taskId']])) {
                        $resumeMap[$rr['taskId']] = $rr['resumeTime'];
                    }
                }
            }
        }

        $nowTs = time();
        foreach ($rawRows as $row) {
            $durationSec = (int)($row['totalDuration'] ?? 0);
            $st          = strtolower(trim($row['status'] ?? ''));

            if ($st === 'running') {
                $tId       = (int)$row['taskId'];
                $resumeStr = $resumeMap[$tId] ?? ($row['startTime'] ?? '');
                $resumeTs  = !empty($resumeStr) ? strtotime($resumeStr) : $nowTs;
                if ($resumeTs && $nowTs > $resumeTs) {
                    $durationSec += ($nowTs - $resumeTs);
                }
            }

            $mins = floor($durationSec / 60);
            $totalWorkMinutes += $mins;

            if ($st === 'completed' || $st === 'finished') {
                $completedCount++;
            } elseif ($st === 'in progress' || $st === 'working' || $st === 'ongoing' || $st === 'running') {
                $inProgressCount++;
            } else {
                $pendingCount++;
            }

            $totalTaskCount++;

            if (!empty($row['projectName'])) {
                $distinctProjects[$row['projectName']] = true;
            }
            if (!empty($row['fullName'])) {
                $distinctEmployees[$row['fullName']] = true;
            }

            $row['_durationSec']    = $durationSec;
            $row['_formattedHours'] = sprintf('%02dh %02dm', floor($mins / 60), $mins % 60);
            $taskRows[] = $row;
        }
    }
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
    <title>Daily Employee Task Analytics Report</title>
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

        /* ── Hero Banner ── */
        .task-banner {
            background: linear-gradient(135deg, #1E293B 0%, #334155 50%, #475569 100%);
            border-radius: 16px;
            padding: 24px 28px;
            color: #ffffff;
            margin-bottom: 24px;
            box-shadow: 0 10px 25px -5px rgba(30, 41, 59, 0.3);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            position: relative;
            overflow: hidden;
        }

        .task-banner::before {
            content: '';
            position: absolute;
            top: -40%;
            right: -10%;
            width: 320px;
            height: 320px;
            background: rgba(255, 255, 255, 0.06);
            border-radius: 50%;
            pointer-events: none;
        }

        .task-banner-title {
            font-size: 1.5rem;
            font-weight: 800;
            margin: 0 0 6px 0;
            display: flex;
            align-items: center;
            gap: 12px;
            letter-spacing: -0.02em;
        }

        .task-banner-sub {
            font-size: 0.88rem;
            color: rgba(255, 255, 255, 0.85);
            margin: 0;
        }

        .task-banner-actions {
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
            color: var(--text-dark);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
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
            background: linear-gradient(135deg, #1E293B 0%, #475569 100%);
            color: #ffffff;
            font-weight: 600;
            font-size: 0.88rem;
            height: 42px;
            padding: 0 24px;
            border-radius: 10px;
            border: none;
            box-shadow: 0 4px 12px rgba(30, 41, 59, 0.25);
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
        }

        .btn-submit-filter:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(30, 41, 59, 0.35);
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

        .icon-indigo  { background: #EEF2FF; color: #4F46E5; }
        .icon-emerald { background: #ECFDF5; color: #10B981; }
        .icon-amber   { background: #FEF3C7; color: #D97706; }
        .icon-sky     { background: #E0F2FE; color: #0284C7; }
        .icon-slate   { background: #F1F5F9; color: #475569; }

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

        /* ── Table Card & Badges ── */
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

        .task-table {
            width: 100% !important;
            margin-bottom: 0;
            border-collapse: separate;
            border-spacing: 0;
        }

        .task-table thead tr {
            background: linear-gradient(135deg, #1E293B 0%, #475569 100%);
        }

        .task-table thead th {
            color: #ffffff !important;
            font-size: 0.75rem !important;
            font-weight: 700 !important;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            padding: 14px 16px !important;
            border: none !important;
            text-align: center;
        }

        .task-table tbody td {
            font-size: 0.85rem;
            vertical-align: middle;
            padding: 12px 16px;
            border-bottom: 1px solid #F1F5F9;
            text-align: center;
            color: #334155;
        }

        .task-table tbody tr:hover {
            background-color: #F8FAFC;
        }

        /* Badges */
        .status-badge-success { background: #DCFCE7; color: #15803D; border: 1px solid #BBF7D0; }
        .status-badge-warning { background: #FEF3C7; color: #B45309; border: 1px solid #FDE68A; }
        .status-badge-info    { background: #E0F2FE; color: #0369A1; border: 1px solid #BAE6FD; }

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

        /* Select2 */
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

        @media print {
            .task-banner-actions, .filter-card, .sidebar, .navbar, .dt-buttons {
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
        <?php include('../partials/_sidebar.php'); ?>

        <div class="main-panel">
            <div class="content-wrapper">

                <!-- ── Hero Banner ── -->
                <div class="task-banner">
                    <div>
                        <h3 class="task-banner-title"><i class="fas fa-tasks"></i> Daily Employee Task Analytics</h3>
                        <p class="task-banner-sub">Detailed task execution logs, project allocations, and daily man-hours audit</p>
                    </div>
                    <div class="task-banner-actions">
                        <?php if ($filterActive): ?>
                        <button type="button" class="btn-banner-action" onclick="window.print()">
                            <i class="fas fa-print"></i> Print Report
                        </button>
                        <?php endif; ?>
                        <a href="employeeTaskReportDaily.php" class="btn-banner-action">
                            <i class="fas fa-undo"></i> Reset Filters
                        </a>
                    </div>
                </div>

                <!-- ── Filter Card ── -->
                <div class="filter-card">
                    <div class="filter-card-header">
                        <i class="fas fa-filter text-primary"></i> Filter Daily Task Logs
                    </div>
                    <form method="GET">
                        <input type="hidden" name="filter" value="1">
                        <div class="row g-3 align-items-end">

                            <div class="col-md-3">
                                <label><i class="fas fa-building text-primary mr-1"></i> Type / Office</label>
                                <select class="js-example-basic-single w-100" id="typeSelect" name="type">
                                    <option value="">-- All Types --</option>
                                    <option value="pune" <?= (isset($_GET['type']) && $_GET['type'] == 'pune') ? 'selected' : '' ?>>Pune Office</option>
                                    <option value="kinwat" <?= (isset($_GET['type']) && $_GET['type'] == 'kinwat') ? 'selected' : '' ?>>Kinwat Office</option>
                                    <option value="online" <?= (isset($_GET['type']) && $_GET['type'] == 'online') ? 'selected' : '' ?>>Online Mode</option>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label><i class="fas fa-user-tie text-success mr-1"></i> Employee</label>
                                <select class="js-example-basic-single w-100" id="employeeSelect" name="employee">
                                    <option value="">-- Select Employee --</option>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label><i class="fas fa-calendar-alt text-warning mr-1"></i> Date</label>
                                <input type="date" class="form-control" name="date" value="<?= $_GET['date'] ?? date('Y-m-d') ?>" required>
                            </div>

                            <div class="col-md-3">
                                <label><i class="fas fa-project-diagram text-info mr-1"></i> Project</label>
                                <select class="js-example-basic-single w-100" name="project">
                                    <option value="">-- All Projects --</option>
                                    <?php
                                    $q = mysqli_query($con, "SELECT DISTINCT projectName FROM TaskEntryNew WHERE projectName!='' ORDER BY projectName ASC");
                                    while ($p = mysqli_fetch_assoc($q)) {
                                        $sel = (isset($_GET['project']) && $_GET['project'] == $p['projectName']) ? 'selected' : '';
                                        echo "<option value='" . htmlspecialchars($p['projectName']) . "' $sel>" . htmlspecialchars($p['projectName']) . "</option>";
                                    }
                                    ?>
                                </select>
                            </div>

                            <div class="col-md-3 mt-3">
                                <button type="submit" class="btn-submit-filter">
                                    <i class="fas fa-search"></i> Show Daily Report
                                </button>
                            </div>

                        </div>
                    </form>
                </div>

                <?php if ($filterActive): ?>

                <!-- ── KPI Analytics Stat Grid ── -->
                <div class="analytics-grid">
                    <div class="analytics-card">
                        <div class="analytics-icon icon-indigo"><i class="fas fa-tasks"></i></div>
                        <div>
                            <div class="analytics-label">Total Tasks Logged</div>
                            <div class="analytics-value"><?= $totalTaskCount ?></div>
                            <div class="analytics-subtext text-muted"><?= $uniqueEmpCount ?> Active Employee<?= $uniqueEmpCount !== 1 ? 's' : '' ?></div>
                        </div>
                    </div>

                    <div class="analytics-card">
                        <div class="analytics-icon icon-sky"><i class="fas fa-stopwatch"></i></div>
                        <div>
                            <div class="analytics-label">Total Logged Hours</div>
                            <div class="analytics-value"><?= $totalHoursFormatted ?></div>
                            <div class="analytics-subtext text-info">Daily Task Duration</div>
                        </div>
                    </div>

                    <div class="analytics-card">
                        <div class="analytics-icon icon-emerald"><i class="fas fa-check-circle"></i></div>
                        <div>
                            <div class="analytics-label">Completed Tasks</div>
                            <div class="analytics-value"><?= $completedCount ?></div>
                            <div class="analytics-subtext text-success"><i class="fas fa-check mr-1"></i>Finished</div>
                        </div>
                    </div>

                    <div class="analytics-card">
                        <div class="analytics-icon icon-amber"><i class="fas fa-spinner"></i></div>
                        <div>
                            <div class="analytics-label">In Progress / Active</div>
                            <div class="analytics-value"><?= $inProgressCount + $pendingCount ?></div>
                            <div class="analytics-subtext text-warning"><i class="fas fa-clock mr-1"></i>Ongoing Shift</div>
                        </div>
                    </div>

                    <div class="analytics-card">
                        <div class="analytics-icon icon-slate"><i class="fas fa-building"></i></div>
                        <div>
                            <div class="analytics-label">Projects Covered</div>
                            <div class="analytics-value"><?= $uniqueProjectCount ?></div>
                            <div class="analytics-subtext text-muted">Distinct Projects</div>
                        </div>
                    </div>
                </div>

                <!-- ── Report Table Card ── -->
                <div class="report-card">
                    <div class="report-card-header">
                        <h4 class="report-card-title">
                            <i class="fas fa-list-alt text-primary"></i> Daily Task Breakdown (<?= date('d M Y', strtotime($_GET['date'] ?? date('Y-m-d'))) ?>)
                        </h4>
                        <span class="badge bg-light text-dark font-weight-bold" style="font-size:.82rem;">
                            <?= count($taskRows) ?> Log Entries
                        </span>
                    </div>
                    <div class="table-responsive p-3">
                        <table class="table task-table" id="dailyReportTable">
                            <thead>
                                <tr>
                                    <th>Sr. No.</th>
                                    <th>Employee</th>
                                    <th>Date</th>
                                    <th>Task Description</th>
                                    <th>Project Name</th>
                                    <th>Duration</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php 
                            $sr = 1;
                            foreach ($taskRows as $row):
                                $st     = strtolower(trim($row['status'] ?? ''));
                                $stCls  = 'status-badge-info';
                                $stIcon = 'fa-info-circle';
                                if ($st === 'completed' || $st === 'finished') {
                                    $stCls = 'status-badge-success'; $stIcon = 'fa-check-circle';
                                } elseif ($st === 'in progress' || $st === 'working' || $st === 'ongoing' || $st === 'running') {
                                    $stCls = 'status-badge-warning'; $stIcon = 'fa-clock';
                                }
                            ?>
                                <tr>
                                    <td><span class="fw-bold text-secondary"><?= $sr++ ?></span></td>
                                    <td>
                                        <div class="fw-bold"><?= htmlspecialchars($row['fullName'] ?? 'Unknown') ?></div>
                                        <?php if (!empty($row['emp_id']) || !empty($row['internId'])): ?>
                                            <small class="text-muted"><?= htmlspecialchars($row['emp_id'] ?: $row['internId']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= date('d M Y', strtotime($row['startTime'])) ?></td>
                                    <td class="text-start" style="max-width: 300px;">
                                        <div class="fw-semibold"><?= htmlspecialchars($row['task']) ?></div>
                                    </td>
                                    <td>
                                        <?php if (!empty($row['projectName'])): ?>
                                            <span class="project-tag-pill"><i class="fas fa-building text-primary mr-1"></i><?= htmlspecialchars($row['projectName']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-order="<?= $row['_durationSec'] ?? 0 ?>">
                                        <?php if ($st === 'running'): ?>
                                            <span class="hours-badge live-timer-badge" data-seconds="<?= $row['_durationSec'] ?? 0 ?>" style="border-color: #28a745; background-color: #eafaf1; color: #28a745; font-weight: 700;">
                                                <i class="fas fa-play-circle text-success mr-1"></i><span class="timer-text"><?= $row['_formattedHours'] ?></span>
                                            </span>
                                        <?php else: ?>
                                            <span class="hours-badge"><i class="fas fa-stopwatch text-primary mr-1"></i><?= $row['_formattedHours'] ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?= $stCls ?>" style="padding:5px 12px; border-radius:20px; font-size:.75rem;">
                                            <i class="fas <?= $stIcon ?> mr-1"></i> <?= htmlspecialchars(ucwords($row['status'])) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php endif; ?>

            </div>
        </div>
    </div>
</div>

<!-- JS -->
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
    const selectedType = "<?= $_GET['type'] ?? '' ?>";
    const selectedEmployee = "<?= $_GET['employee'] ?? '' ?>";

    $(document).ready(function() {
        $('.js-example-basic-single').select2({ placeholder: '-- Select Option --', allowClear: true, width: '100%' });
    });

    function loadEmployees(type, selectedEmp = '') {
        try { $('#employeeSelect').select2('destroy'); } catch(e){}
        $('#employeeSelect').html('<option>Loading...</option>');

        if (type) {
            $.post('get_employees_by_type.php', { type }, function (data) {
                let opt = '<option value="">-- Select Employee --</option>';
                if (Array.isArray(data)) {
                    data.forEach(e => {
                        let sel = (e.userId === selectedEmp) ? 'selected' : '';
                        opt += `<option value="${e.userId}" ${sel}>${e.fullName}</option>`;
                    });
                }
                $('#employeeSelect').html(opt).select2({ placeholder: '-- Select Employee --', allowClear: true, width: '100%' });
            }, 'json');
        } else {
            $('#employeeSelect').html('<option value="">-- Select Employee --</option>').select2({ placeholder: '-- Select Employee --', allowClear: true, width: '100%' });
        }
    }

    $('#typeSelect').change(function () {
        loadEmployees($(this).val());
    });

    <?php if (isset($_GET['type']) && !empty($_GET['type'])): ?>
        loadEmployees(selectedType, selectedEmployee);
    <?php endif; ?>
</script>

<?php if ($filterActive): ?>
<script>
    function startLiveTimers() {
        const timerBadges = document.querySelectorAll('.live-timer-badge');
        if (timerBadges.length === 0) return;
        setInterval(function () {
            timerBadges.forEach(function (el) {
                let secs = parseInt(el.getAttribute('data-seconds') || '0', 10) + 1;
                el.setAttribute('data-seconds', secs);
                const hrs = Math.floor(secs / 3600);
                const mins = Math.floor((secs % 3600) / 60);
                const formatted = String(hrs).padStart(2, '0') + 'h ' + String(mins).padStart(2, '0') + 'm';
                const textSpan = el.querySelector('.timer-text');
                if (textSpan) {
                    textSpan.textContent = formatted;
                }
            });
        }, 1000);
    }

    $(document).ready(function () {
        startLiveTimers();
        if ($('#dailyReportTable').length && $.fn.DataTable) {
            $('#dailyReportTable').DataTable({
                paging: true,
                searching: true,
                ordering: true,
                info: true,
                pageLength: 25,
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
                    search: "Filter Tasks:",
                    lengthMenu: "Show _MENU_ entries",
                    emptyTable: "No task records found for selected filters."
                }
            });
        }
    });
</script>
<?php endif; ?>

</body>
</html>