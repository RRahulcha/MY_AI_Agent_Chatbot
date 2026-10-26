<?php
session_start();
include('../include/db_config.php');

/* ADMIN ACCESS CHECK */
if (!isset($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

$report_type = 'monthly';
$filterActive = isset($_GET['filter']) && $_GET['filter'] == '1';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Monthly Employee Task Analytics & Report</title>
    <?php include('../partials/_links.php'); ?>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css">

    <style>
        :root {
            --primary-bg: #f4f6fc;
            --card-bg: #ffffff;
            --brand-primary: #334155;
            --brand-primary-hover: #1E293B;
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
            background: linear-gradient(135deg, #0F172A 0%, #1E293B 50%, #334155 100%);
            border-radius: 16px;
            padding: 24px 28px;
            color: #ffffff;
            margin-bottom: 24px;
            box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.35);
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
            border-color: #475569;
            background-color: #ffffff;
            box-shadow: 0 0 0 3px rgba(71, 85, 105, 0.12);
        }

        .btn-submit-filter {
            background: linear-gradient(135deg, #0F172A 0%, #334155 100%);
            color: #ffffff;
            font-weight: 600;
            font-size: 0.88rem;
            height: 42px;
            padding: 0 24px;
            border-radius: 10px;
            border: none;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
        }

        .btn-submit-filter:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.35);
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

        /* ── Calendar & Table Card ── */
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

        .calendar {
            width: 100%;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid var(--border-color);
            background: #fff;
        }
        .calendar-header {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            background: #F8FAFC;
            border-bottom: 1px solid var(--border-color);
        }
        .calendar-header .cal-head-cell {
            padding: 12px 0;
            text-align: center;
            font-size: 0.75rem;
            font-weight: 800;
            color: #64748B;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .calendar-body {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
        }
        .calendar-cell {
            min-height: 110px;
            border-right: 1px solid var(--border-color);
            border-bottom: 1px solid var(--border-color);
            padding: 8px 6px 6px;
            position: relative;
            cursor: pointer;
            transition: background 0.13s;
            background: #fff;
        }
        .calendar-cell:hover { background: #F1F5F9; }
        .calendar-cell.empty { background: #FAFAFA; cursor: default; }
        .calendar-cell:nth-child(7n) { border-right: none; }

        .date-num {
            font-size: 0.82rem;
            font-weight: 700;
            color: #475467;
            width: 26px;
            height: 26px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            margin-bottom: 5px;
        }
        .calendar-cell.today .date-num { background: #1E293B; color: #fff; }

        .cal-task-pill {
            display: block;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 0.7rem;
            font-weight: 700;
            margin-bottom: 3px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 100%;
        }
        .cal-task-pill.has-tasks { background: #DCFCE7; color: #15803D; border: 1px solid #BBF7D0; }
        .cal-task-pill.no-tasks  { background: #F1F5F9; color: #94A3B8; border: 1px solid #E2E8F0; font-style: italic; }
        .cal-task-pill.weekend   { background: #EEF2FF; color: #4338CA; border: 1px solid #C7D2FE; }
        .cal-hours-sum { font-size: 0.72rem; font-weight: 700; color: #15803D; margin-top: 3px; }
        .cal-more { font-size: 0.68rem; color: #0EA5E9; font-weight: 700; margin-top: 2px; }

        /* Day Detail Modal */
        .day-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15,23,42,0.50);
            z-index: 1050;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(4px);
        }
        .day-modal-overlay.active { display: flex; }

        .day-modal {
            background: #fff;
            border-radius: 16px;
            width: 520px;
            max-width: 96vw;
            max-height: 85vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
            overflow: hidden;
            animation: modalIn 0.18s ease;
        }
        @keyframes modalIn {
            from { opacity: 0; transform: translateY(-14px) scale(0.97); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }
        .day-modal-header {
            padding: 18px 24px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #F8FAFC;
        }
        .day-modal-header .modal-date { font-size: 1.05rem; font-weight: 700; color: #1E293B; }
        .day-modal-header .modal-emp  { font-size: 0.82rem; color: #64748B; margin-top: 2px; }
        .modal-close { background: none; border: none; font-size: 1.5rem; color: #94A3B8; cursor: pointer; line-height: 1; }
        .modal-close:hover { color: #1E293B; }
        .day-modal-body { padding: 20px 24px; overflow-y: auto; }

        .modal-summary { display: flex; gap: 12px; margin-bottom: 18px; }
        .summary-chip { flex: 1; background: #F1F5F9; border-radius: 10px; padding: 12px; text-align: center; border: 1px solid #E2E8F0; }
        .summary-chip .chip-val   { font-size: 1.25rem; font-weight: 800; color: #1E293B; }
        .summary-chip .chip-label { font-size: 0.72rem; color: #64748B; font-weight: 700; text-transform: uppercase; margin-top: 2px; }

        .task-row { border: 1px solid var(--border-color); border-radius: 10px; padding: 12px 16px; margin-bottom: 10px; background: #fff; }
        .task-row:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .task-row .tr-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; margin-bottom: 6px; }
        .task-row .tr-name { font-size: 0.875rem; font-weight: 600; color: #1E293B; flex: 1; }
        .task-row .tr-hours { font-size: 0.8rem; font-weight: 700; color: #15803D; background: #DCFCE7; border: 1px solid #BBF7D0; border-radius: 6px; padding: 2px 8px; }
        .task-row .tr-meta { display: flex; gap: 8px; flex-wrap: wrap; }
        .tr-project { font-size: 0.72rem; font-weight: 700; background: #F1F5F9; color: #1E293B; border: 1px solid #CBD5E1; border-radius: 6px; padding: 2px 8px; }
        .tr-status { font-size: 0.72rem; font-weight: 700; border-radius: 6px; padding: 2px 8px; }
        .tr-status.completed   { background: #DCFCE7; color: #15803D; border: 1px solid #BBF7D0; }
        .tr-status.pending     { background: #FFEDD5; color: #C2410C; border: 1px solid #FED7AA; }
        .tr-status.in-progress { background: #E0F2FE; color: #0369A1; border: 1px solid #BAE6FD; }
        .tr-status.default     { background: #F1F5F9; color: #64748B; border: 1px solid #E2E8F0; }

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

        /* Export Table */
        .task-table {
            width: 100% !important;
            margin-bottom: 0;
            border-collapse: separate;
            border-spacing: 0;
        }

        .task-table thead tr {
            background: linear-gradient(135deg, #0F172A 0%, #334155 100%);
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
                        <h3 class="task-banner-title"><i class="fas fa-calendar-alt"></i> Monthly Employee Task Analytics</h3>
                        <p class="task-banner-sub">Calendar-based monthly task execution audit, man-hours breakdown, and daily activity logs</p>
                    </div>
                    <div class="task-banner-actions">
                        <?php if ($filterActive): ?>
                        <button type="button" class="btn-banner-action" onclick="window.print()">
                            <i class="fas fa-print"></i> Print Report
                        </button>
                        <?php endif; ?>
                        <a href="employeeTaskReportMonthly.php" class="btn-banner-action">
                            <i class="fas fa-undo"></i> Reset Filters
                        </a>
                    </div>
                </div>

                <!-- ── Filter Card ── -->
                <div class="filter-card">
                    <div class="filter-card-header">
                        <i class="fas fa-filter text-primary"></i> Filter Monthly Report Criteria
                    </div>
                    <form method="GET">
                        <input type="hidden" name="filter" value="1">
                        <div class="row g-3 align-items-end">

                            <div class="col-md-3">
                                <label><i class="fas fa-building text-primary mr-1"></i> Type / Office</label>
                                <select class="js-example-basic-single w-100" id="typeSelect" name="type">
                                    <option value="">-- All Types --</option>
                                    <option value="pune"   <?= (isset($_GET['type']) && $_GET['type']=='pune')   ? 'selected':'' ?>>Pune Office</option>
                                    <option value="kinwat" <?= (isset($_GET['type']) && $_GET['type']=='kinwat') ? 'selected':'' ?>>Kinwat Office</option>
                                    <option value="online" <?= (isset($_GET['type']) && $_GET['type']=='online') ? 'selected':'' ?>>Online Mode</option>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label><i class="fas fa-user-tie text-success mr-1"></i> Employee</label>
                                <select class="js-example-basic-single w-100" id="employeeSelect" name="employee">
                                    <option value="">-- All Employees --</option>
                                    <?php
                                    $selType = isset($_GET['type'])     ? trim($_GET['type'])     : '';
                                    $selEmp  = isset($_GET['employee']) ? trim($_GET['employee']) : '';

                                    if ($selType === 'online') {
                                        $empSQL = "SELECT userId, fullName FROM signup WHERE mode = 'Online' AND type != 'admin' AND status = 'active' ORDER BY fullName";
                                    } elseif (!empty($selType)) {
                                        $safeType = mysqli_real_escape_string($con, $selType);
                                        $empSQL = "SELECT userId, fullName FROM signup WHERE office = '$safeType' AND type != 'admin' AND status = 'active' ORDER BY fullName";
                                    } else {
                                        $empSQL = "SELECT userId, fullName FROM signup WHERE type != 'admin' AND status = 'active' ORDER BY fullName";
                                    }

                                    $empRes = mysqli_query($con, $empSQL);
                                    while ($emp = mysqli_fetch_assoc($empRes)) {
                                        $isSel = ($emp['userId'] === $selEmp) ? 'selected' : '';
                                        echo "<option value='" . htmlspecialchars($emp['userId'], ENT_QUOTES) . "' $isSel>" . htmlspecialchars($emp['fullName']) . "</option>";
                                    }
                                    ?>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label><i class="fas fa-calendar-alt text-warning mr-1"></i> Start Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="start_date"
                                    value="<?= isset($_GET['start_date']) ? htmlspecialchars($_GET['start_date']) : date('Y-m-01') ?>" required>
                            </div>

                            <div class="col-md-3">
                                <label><i class="fas fa-calendar-check text-danger mr-1"></i> End Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="end_date"
                                    value="<?= isset($_GET['end_date']) ? htmlspecialchars($_GET['end_date']) : date('Y-m-t') ?>" required>
                            </div>

                            <div class="col-md-3 mt-3">
                                <label><i class="fas fa-project-diagram text-info mr-1"></i> Project</label>
                                <select class="js-example-basic-single w-100" name="project">
                                    <option value="">-- All Projects --</option>
                                    <?php
                                    $q = mysqli_query($con, "SELECT DISTINCT projectName FROM TaskEntryNew WHERE projectName != '' ORDER BY projectName");
                                    while ($p = mysqli_fetch_assoc($q)) {
                                        $sel = (isset($_GET['project']) && $_GET['project'] == $p['projectName']) ? 'selected' : '';
                                        echo "<option value='" . htmlspecialchars($p['projectName'], ENT_QUOTES) . "' $sel>" . htmlspecialchars($p['projectName']) . "</option>";
                                    }
                                    ?>
                                </select>
                            </div>

                            <div class="col-md-3 mt-3">
                                <button type="submit" class="btn-submit-filter">
                                    <i class="fas fa-search"></i> Generate Monthly Report
                                </button>
                            </div>

                        </div>
                    </form>
                </div>

                <?php if ($filterActive): ?>

                <?php
                $where = [];

                if (!empty($_GET['type'])) {
                    $type = mysqli_real_escape_string($con, $_GET['type']);
                    if ($type === 'online') {
                        $where[] = "s.mode = 'Online'";
                    } else {
                        $where[] = "s.office = '$type'";
                    }
                }
                if (!empty($_GET['employee'])) {
                    $emp = mysqli_real_escape_string($con, $_GET['employee']);
                    $where[] = "ten.userId = '$emp'";
                }

                $start = mysqli_real_escape_string($con, $_GET['start_date']);
                $end   = mysqli_real_escape_string($con, $_GET['end_date']);
                $where[] = "DATE(ten.startTime) BETWEEN '$start' AND '$end'";

                if (!empty($_GET['project'])) {
                    $proj = mysqli_real_escape_string($con, $_GET['project']);
                    $where[] = "ten.projectName = '$proj'";
                }

                $where[] = "LOWER(s.type) != 'admin'";

                $sql = "
                    SELECT ten.*, s.fullName, s.emp_id, s.internId, DATE(ten.startTime) as task_date
                    FROM TaskEntryNew ten
                    LEFT JOIN signup s ON ten.userId = s.userId
                    WHERE " . implode(" AND ", $where) . "
                    ORDER BY DATE(ten.startTime) DESC, s.fullName ASC
                ";
                $res = mysqli_query($con, $sql);

                $tasksByDate        = [];
                $flatTaskList       = [];
                $totalTaskCount     = 0;
                $totalWorkMinutes   = 0;
                $distinctProjects   = [];
                $distinctEmployees  = [];

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
                        $d           = $row['task_date'];
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

                        $mins = (int)floor($durationSec / 60);
                        $totalWorkMinutes += $mins;
                        $totalTaskCount++;

                        if (!empty($row['projectName'])) { $distinctProjects[$row['projectName']] = true; }
                        if (!empty($row['fullName']))    { $distinctEmployees[$row['fullName']] = true; }

                        $tObj = [
                            'task'     => $row['task'],
                            'project'  => !empty($row['projectName']) ? $row['projectName'] : '-',
                            'mins'     => $mins,
                            'hours'    => sprintf('%02d:%02d', floor($mins/60), $mins%60),
                            'status'   => $row['status'] ?? '-',
                            'employee' => $row['fullName'],
                            'empId'    => $row['emp_id'] ?: $row['internId'],
                            'date'     => $d,
                        ];

                        if (!isset($tasksByDate[$d])) $tasksByDate[$d] = [];
                        $tasksByDate[$d][] = $tObj;
                        $flatTaskList[]    = $tObj;
                    }
                }

                $calStart    = new DateTime($start);
                $calEnd      = new DateTime($end);
                $diffDays    = $calStart->diff($calEnd)->days + 1;

                $firstOfGrid = clone $calStart;
                $dow = (int)$firstOfGrid->format('w');
                if ($dow > 0) $firstOfGrid->modify("-{$dow} days");

                $lastOfGrid = clone $calEnd;
                $dow2 = (int)$lastOfGrid->format('w');
                if ($dow2 < 6) $lastOfGrid->modify('+' . (6 - $dow2) . ' days');

                $startLabel = date('d M Y', strtotime($start));
                $endLabel   = date('d M Y', strtotime($end));

                $workHours = floor($totalWorkMinutes / 60);
                $workMins  = $totalWorkMinutes % 60;
                $totalHoursFormatted = sprintf("%dh %02dm", $workHours, $workMins);
                $uniqueProjectCount  = count($distinctProjects);
                $uniqueEmpCount      = count($distinctEmployees);
                $activeDaysCount     = count($tasksByDate);
                ?>

                <!-- ── Analytics KPI Stat Grid ── -->
                <div class="analytics-grid">
                    <div class="analytics-card">
                        <div class="analytics-icon icon-indigo"><i class="fas fa-calendar-alt"></i></div>
                        <div>
                            <div class="analytics-label">Total Days Evaluated</div>
                            <div class="analytics-value"><?= $diffDays ?></div>
                            <div class="analytics-subtext text-muted"><?= $activeDaysCount ?> Days Active</div>
                        </div>
                    </div>

                    <div class="analytics-card">
                        <div class="analytics-icon icon-emerald"><i class="fas fa-tasks"></i></div>
                        <div>
                            <div class="analytics-label">Total Tasks Logged</div>
                            <div class="analytics-value"><?= $totalTaskCount ?></div>
                            <div class="analytics-subtext text-success"><?= $uniqueEmpCount ?> Active Employee<?= $uniqueEmpCount !== 1 ? 's' : '' ?></div>
                        </div>
                    </div>

                    <div class="analytics-card">
                        <div class="analytics-icon icon-sky"><i class="fas fa-business-time"></i></div>
                        <div>
                            <div class="analytics-label">Total Monthly Hours</div>
                            <div class="analytics-value"><?= $totalHoursFormatted ?></div>
                            <div class="analytics-subtext text-info">Logged Man-Hours</div>
                        </div>
                    </div>

                    <div class="analytics-card">
                        <div class="analytics-icon icon-slate"><i class="fas fa-building"></i></div>
                        <div>
                            <div class="analytics-label">Projects Covered</div>
                            <div class="analytics-value"><?= $uniqueProjectCount ?></div>
                            <div class="analytics-subtext text-muted">Distinct Project Sites</div>
                        </div>
                    </div>
                </div>

                <!-- ── Monthly Calendar View Card ── -->
                <div class="report-card">
                    <div class="report-card-header">
                        <h4 class="report-card-title">
                            <i class="fas fa-calendar-week text-primary"></i> Monthly Task Calendar (<?= $startLabel ?> to <?= $endLabel ?>)
                        </h4>
                        <span class="badge bg-light text-dark font-weight-bold" style="font-size:.82rem;">
                            Click Any Date to Inspect Tasks
                        </span>
                    </div>
                    <div class="p-3">
                        <div class="calendar">
                            <div class="calendar-header">
                                <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $dl): ?>
                                    <div class="cal-head-cell"><?= $dl ?></div>
                                <?php endforeach; ?>
                            </div>

                            <div class="calendar-body">
                            <?php
                            $cursor = clone $firstOfGrid;
                            $today  = date('Y-m-d');

                            while ($cursor <= $lastOfGrid):
                                $dayStr    = $cursor->format('Y-m-d');
                                $dayLabel  = $cursor->format('j');
                                $dayOfWeek = $cursor->format('D');
                                $isToday   = ($dayStr === $today);
                                $inRange   = ($dayStr >= $start && $dayStr <= $end);

                                if (!$inRange):
                                    echo '<div class="calendar-cell empty"></div>';
                                    $cursor->modify('+1 day');
                                    continue;
                                endif;

                                $isWeekend = ($dayOfWeek === 'Sun');
                                $tasks     = $tasksByDate[$dayStr] ?? [];
                                $taskCount = count($tasks);
                                $totalMins = array_sum(array_column($tasks, 'mins'));
                                $totalHrs  = $totalMins > 0 ? sprintf('%02d:%02d', floor($totalMins/60), $totalMins%60) : '';

                                echo '<div class="calendar-cell' . ($isToday ? ' today' : '') . '" data-date="' . $dayStr . '">';
                                echo '<div class="date-num">' . $dayLabel . '</div>';

                                if ($isWeekend) {
                                    echo "<span class='cal-task-pill weekend'>Weekend</span>";
                                } elseif ($taskCount === 0) {
                                    echo "<span class='cal-task-pill no-tasks'>No tasks</span>";
                                } else {
                                    $firstTask = $tasks[0];
                                    $pillText  = $firstTask['project'] !== '-' ? $firstTask['project'] : $firstTask['task'];
                                    $pillText  = strlen($pillText) > 16 ? substr($pillText, 0, 14) . '…' : $pillText;
                                    echo "<span class='cal-task-pill has-tasks'>" . htmlspecialchars($pillText) . "</span>";
                                    if ($taskCount > 1) {
                                        echo "<div class='cal-more'>+" . ($taskCount - 1) . " more task" . ($taskCount > 2 ? 's' : '') . "</div>";
                                    }
                                    if ($totalHrs) {
                                        echo "<div class='cal-hours-sum'>⏱ {$totalHrs} total</div>";
                                    }
                                }

                                echo '</div>';
                                $cursor->modify('+1 day');
                            endwhile;
                            ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Data Table View & Export Suite Card ── -->
                <div class="report-card">
                    <div class="report-card-header">
                        <h4 class="report-card-title">
                            <i class="fas fa-file-export text-primary"></i> Monthly Task Log Export Suite
                        </h4>
                        <span class="badge bg-light text-dark font-weight-bold" style="font-size:.82rem;">
                            <?= count($flatTaskList) ?> Total Entries
                        </span>
                    </div>
                    <div class="table-responsive p-3">
                        <table class="table task-table" id="monthlyReportTable">
                            <thead>
                                <tr>
                                    <th>Sr. No.</th>
                                    <th>Date</th>
                                    <th>Employee</th>
                                    <th>Task Description</th>
                                    <th>Project Site</th>
                                    <th>Duration</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php 
                            $sr = 1;
                            foreach ($flatTaskList as $t):
                                $st     = strtolower(trim($t['status']));
                                $stCls  = 'tr-status default';
                                if (strpos($st, 'complet') !== false) { $stCls = 'tr-status completed'; }
                                elseif (strpos($st, 'progress') !== false || strpos($st, 'working') !== false) { $stCls = 'tr-status in-progress'; }
                                elseif (strpos($st, 'pending') !== false) { $stCls = 'tr-status pending'; }
                            ?>
                                <tr>
                                    <td><span class="fw-bold text-secondary"><?= $sr++ ?></span></td>
                                    <td><?= date('d M Y', strtotime($t['date'])) ?></td>
                                    <td>
                                        <div class="fw-bold"><?= htmlspecialchars($t['employee']) ?></div>
                                        <?php if (!empty($t['empId'])): ?>
                                            <small class="text-muted"><?= htmlspecialchars($t['empId']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-start" style="max-width:300px;"><?= htmlspecialchars($t['task']) ?></td>
                                    <td>
                                        <?php if ($t['project'] !== '-'): ?>
                                            <span class="tr-project"><i class="fas fa-building text-primary mr-1"></i><?= htmlspecialchars($t['project']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="hours-badge"><i class="fas fa-stopwatch text-primary mr-1"></i><?= $t['hours'] ?></span></td>
                                    <td><span class="<?= $stCls ?>"><?= htmlspecialchars(ucwords($t['status'])) ?></span></td>
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

<!-- Day Detail Modal -->
<div class="day-modal-overlay" id="dayModalOverlay">
    <div class="day-modal">
        <div class="day-modal-header">
            <div>
                <div class="modal-date" id="modalDate">—</div>
                <div class="modal-emp"  id="modalEmp"></div>
            </div>
            <button class="modal-close" id="modalClose" title="Close">&times;</button>
        </div>
        <div class="day-modal-body" id="modalBody"></div>
    </div>
</div>

<!-- JS Dependencies -->
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
const calendarData = <?= json_encode($tasksByDate ?? []) ?>;

function statusClass(s) {
    if (!s || s === '-') return 'default';
    const sl = s.toLowerCase();
    if (sl.includes('complet') || sl.includes('finish'))  return 'completed';
    if (sl.includes('progress') || sl.includes('working') || sl.includes('running')) return 'in-progress';
    if (sl.includes('pending'))  return 'pending';
    return 'default';
}

function escHtml(str) {
    if (!str) return '';
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function openModal(dateStr) {
    const tasks = calendarData[dateStr] || [];
    const d = new Date(dateStr + 'T00:00:00');
    document.getElementById('modalDate').textContent = d.toLocaleDateString('en-IN', { weekday:'long', day:'numeric', month:'long', year:'numeric' });
    document.getElementById('modalEmp').textContent  = tasks.length > 0 ? tasks[0].employee : '';
    const body = document.getElementById('modalBody');

    if (tasks.length === 0) {
        body.innerHTML = `<div class="modal-no-tasks"><i class="fas fa-clipboard-list"></i>No tasks recorded for this day.</div>`;
    } else {
        const totalMins = tasks.reduce((a,t) => a + t.mins, 0);
        const totalHrs  = String(Math.floor(totalMins/60)).padStart(2,'0') + ':' + String(totalMins%60).padStart(2,'0');
        const projects  = [...new Set(tasks.map(t => t.project).filter(p => p && p!=='-'))];

        let html = `
            <div class="modal-summary">
                <div class="summary-chip"><div class="chip-val">${tasks.length}</div><div class="chip-label">Tasks</div></div>
                <div class="summary-chip"><div class="chip-val">${totalHrs}</div><div class="chip-label">Total Hours</div></div>
                <div class="summary-chip"><div class="chip-val">${projects.length || '-'}</div><div class="chip-label">Projects</div></div>
            </div>`;

        tasks.forEach(t => {
            const sc = statusClass(t.status);
            html += `
                <div class="task-row">
                    <div class="tr-top">
                        <div class="tr-name">${escHtml(t.task)}</div>
                        <div class="tr-hours">⏱ ${t.hours}</div>
                    </div>
                    <div class="tr-meta">
                        ${t.project && t.project !== '-' ? `<span class="tr-project">📁 ${escHtml(t.project)}</span>` : ''}
                        <span class="tr-status ${sc}">${escHtml(t.status)}</span>
                    </div>
                </div>`;
        });
        body.innerHTML = html;
    }
    document.getElementById('dayModalOverlay').classList.add('active');
}

document.querySelectorAll('.calendar-cell:not(.empty)').forEach(function(cell) {
    cell.addEventListener('click', function() {
        const date = this.dataset.date;
        if (date) openModal(date);
    });
});
document.getElementById('modalClose').addEventListener('click', function() {
    document.getElementById('dayModalOverlay').classList.remove('active');
});
document.getElementById('dayModalOverlay').addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('active');
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') document.getElementById('dayModalOverlay').classList.remove('active');
});

$(document).ready(function() {
    $('.js-example-basic-single').select2({ placeholder: '-- Select Option --', allowClear: true, width: '100%' });

    if ($('#monthlyReportTable').length && $.fn.DataTable) {
        $('#monthlyReportTable').DataTable({
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
                search: "Filter Monthly Logs:",
                lengthMenu: "Show _MENU_ entries"
            }
        });
    }

    $('input[name="end_date"]').change(function() {
        const s = $('input[name="start_date"]').val();
        const e = $(this).val();
        if (s && e && new Date(e) < new Date(s)) {
            alert('End date cannot be before start date!');
            $(this).val('');
        }
    });

    $('#typeSelect').on('change', function() {
        const type = $(this).val();
        const $emp = $('#employeeSelect');
        try { $emp.select2('destroy'); } catch(e){}
        $emp.html('<option value="">Loading…</option>');

        $.ajax({
            url: 'get_employees_by_type.php',
            type: 'POST',
            data: { type: type },
            dataType: 'json',
            success: function(data) {
                let opts = '<option value="">-- All Employees --</option>';
                (data || []).forEach(function(e) {
                    opts += '<option value="' + e.userId + '">' + e.fullName + '</option>';
                });
                $emp.html(opts).select2({ placeholder: '-- All Employees --', allowClear: true, width: '100%' });
            },
            error: function() {
                $emp.html('<option value="">-- All Employees --</option>').select2({ placeholder: '-- All Employees --', allowClear: true, width: '100%' });
            }
        });
    });
});
</script>
</body>
</html>