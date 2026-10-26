<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);

// Auth check
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

// Role check
if ($_SESSION['userRole'] !== 'senior_intern' && $_SESSION['userRole'] !== 'resourceAllocator' && $_SESSION['userRole'] !== 'hr') {
    echo '<script>alert("You are not authorized to access this page.");window.location.href="logout.php";</script>';
    die();
}

include('../include/db_config.php');
include('../include/task_helper.php');

$userId = $_SESSION['userId'];
$today = date('Y-m-d');
$mode = $_GET['mode'] ?? 'view'; // 'planned' = post-punch-in, 'view' = during day

// Schema check helper
$_chkCol = mysqli_query($con, "SHOW COLUMNS FROM task_diary LIKE 'taskid'");
if ($_chkCol && mysqli_num_rows($_chkCol) === 0) {
    mysqli_query($con, "ALTER TABLE task_diary ADD COLUMN taskid VARCHAR(50) NULL DEFAULT NULL AFTER id");
    mysqli_query($con, "UPDATE task_diary SET taskid = CONCAT('TSK-', LPAD(id, 5, '0')) WHERE taskid IS NULL OR taskid = ''");
    @mysqli_query($con, "ALTER TABLE task_diary ADD INDEX idx_taskid (taskid)");
} else {
    mysqli_query($con, "UPDATE task_diary SET taskid = CONCAT('TSK-', LPAD(id, 5, '0')) WHERE taskid IS NULL OR taskid = ''");
    // Ensure index is non-unique to allow carried-over postponed tasks to retain the exact same taskid
    $_chkIndex = mysqli_query($con, "SHOW INDEX FROM task_diary WHERE Key_name = 'idx_taskid'");
    if ($_chkIndex && $idxRow = mysqli_fetch_assoc($_chkIndex)) {
        if (isset($idxRow['Non_unique']) && $idxRow['Non_unique'] == 0) {
            @mysqli_query($con, "ALTER TABLE task_diary DROP INDEX idx_taskid");
            @mysqli_query($con, "ALTER TABLE task_diary ADD INDEX idx_taskid (taskid)");
        }
    }
}

$_chkDel = mysqli_query($con, "SHOW COLUMNS FROM task_diary LIKE 'is_deleted'");
if ($_chkDel && mysqli_num_rows($_chkDel) === 0) {
    mysqli_query($con, "ALTER TABLE task_diary ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
}

// Ensure any carried/postponed tasks from past dates are synced for today
syncCarriedTasks($con, $userId, $today);

// Fetch all today's active tasks in one query for optimization
$tasksQ = mysqli_query($con, "
    SELECT id, taskid, task_description, task_type, status, carried_from_date,
           DATE_FORMAT(created_at, '%h:%i %p') AS created_time
    FROM task_diary
    WHERE userId = '$userId' AND date = '$today' AND (is_deleted = 0 OR is_deleted IS NULL)
    ORDER BY CASE WHEN status = 'Pending' THEN 1 ELSE 2 END, task_type ASC, id ASC
");

$todayTasks = [];
$hasUserSubmittedPlanned = !empty($_SESSION['task_diary_submitted_' . $today]);
$completedCnt = 0;
$postponedCnt = 0;
$pendingCnt = 0;

if ($tasksQ) {
    while ($row = mysqli_fetch_assoc($tasksQ)) {
        $todayTasks[] = $row;
        if ($row['task_type'] === 'Planned' && empty($row['carried_from_date'])) {
            $hasUserSubmittedPlanned = true;
        }
        if ($row['status'] === 'Completed') {
            $completedCnt++;
        } elseif ($row['status'] === 'Postponed') {
            $postponedCnt++;
        } else {
            $pendingCnt++;
        }
    }
}
$totalCount = count($todayTasks);

// If mode=planned but already submitted planned tasks for today, switch to view mode
if ($mode === 'planned' && $hasUserSubmittedPlanned) {
    $mode = 'view';
}

// Fetch carried over tasks ONLY when in planned mode and no planned tasks submitted yet
$carriedTasks = [];
if ($mode === 'planned' && !$hasUserSubmittedPlanned) {
    foreach ($todayTasks as $t) {
        if (!empty($t['carried_from_date']) && $t['status'] === 'Pending') {
            $carriedTasks[] = $t;
        }
    }
}

$carriedCount = count($carriedTasks);

// Dashboard URL
$redirectURL = 'srEmpDashboard.php';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Task Diary</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="shortcut icon" href="../images/logos/SETTribe_logo.png" />
    <?php include('../partials/_links.php'); ?>
    <style>
        :root {
            --brand: #1977e1;
            --brand-dark: #1254b0;
            --brand-light: #f0f7ff;
            --green: #059669;
            --green-bg: #ecfdf5;
            --amber: #d97706;
            --amber-bg: #fffbeb;
            --red: #dc2626;
            --ink: #111827;
            --ink-2: #374151;
            --ink-3: #6b7280;
            --border: #e5e7eb;
            --surface: #f9fafb;
            --radius: 12px;
        }



        .diary-container {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(25, 119, 225, 0.10), 0 2px 8px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            width: 100%;
            max-width: 1100px;
            margin: 10px auto 30px auto;
        }

        .diary-header {
            background: linear-gradient(135deg, var(--brand) 0%, var(--brand-dark) 100%);
            padding: 24px 32px;
            text-align: center;
            color: white;
        }

        .diary-header h2 {
            margin: 0 0 4px;
            font-weight: 700;
            font-size: 22px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .diary-header p {
            margin: 0;
            font-size: 13px;
            opacity: 0.85;
        }

        .diary-body {
            padding: 28px 32px;
        }

        .section-title {
            font-size: 15px;
            font-weight: 700;
            color: var(--ink);
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .section-title .badge-count {
            background: var(--brand);
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 99px;
        }

        /* ── Task Input Block ── */
        .task-input-block {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
            animation: fadeIn 0.25s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-8px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .task-input-block .task-number {
            width: 28px;
            height: 28px;
            background: var(--brand-light);
            color: var(--brand);
            font-size: 12px;
            font-weight: 700;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .task-input-block input[type="text"] {
            flex: 1;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 14px;
            transition: border-color 0.2s;
            outline: none;
        }

        .task-input-block input[type="text"]:focus {
            border-color: var(--brand);
            box-shadow: 0 0 0 3px rgba(25, 119, 225, 0.1);
        }

        .task-input-block .btn-remove {
            width: 32px;
            height: 32px;
            border: none;
            background: #fef2f2;
            color: var(--red);
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
            flex-shrink: 0;
        }

        .task-input-block .btn-remove:hover {
            background: #fee2e2;
            transform: scale(1.05);
        }

        .btn-add-task {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--brand-light);
            color: var(--brand);
            border: 1.5px dashed var(--brand);
            border-radius: 8px;
            padding: 8px 16px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            margin-bottom: 20px;
        }

        .btn-add-task:hover {
            background: #dbeafe;
            transform: translateY(-1px);
        }

        /* ── Action Buttons ── */
        .btn-primary-custom {
            background: linear-gradient(135deg, var(--brand), var(--brand-dark));
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 12px 28px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 4px 14px rgba(25, 119, 225, 0.28);
        }

        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(25, 119, 225, 0.35);
        }

        .btn-primary-custom:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .btn-secondary-custom {
            background: #f1f5f9;
            color: var(--ink-2);
            border: 1.5px solid var(--border);
            border-radius: 8px;
            padding: 10px 20px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-secondary-custom:hover {
            background: #e2e8f0;
        }

        /* ── Task Card (View Mode) ── */
        .task-card {
            background: var(--surface);
            border: 1.5px solid var(--border);
            border-radius: var(--radius);
            padding: 14px 16px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: all 0.2s;
        }

        .task-card:hover {
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        }

        .task-card.completed {
            border-color: #a7f3d0;
            background: #f0fdf4;
        }

        .task-card.postponed {
            border-color: #fed7aa;
            background: #fffbeb;
        }

        .task-card .task-info {
            flex: 1;
        }

        .task-card .task-desc {
            font-size: 14px;
            font-weight: 600;
            color: var(--ink);
            margin-bottom: 4px;
        }

        .task-card .task-meta {
            font-size: 11px;
            color: var(--ink-3);
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .task-card .task-actions {
            display: flex;
            gap: 6px;
            flex-shrink: 0;
        }

        .badge-type {
            font-size: 10px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 99px;
            text-transform: uppercase;
        }

        .badge-type.planned {
            background: #dbeafe;
            color: var(--brand);
        }

        .badge-type.unplanned {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-type.carried {
            background: #fce7f3;
            color: #be185d;
        }

        .badge-status {
            font-size: 10px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 99px;
            text-transform: uppercase;
        }

        .badge-status.pending {
            background: #e0e7ff;
            color: #3730a3;
        }

        .badge-status.completed-badge {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-status.postponed-badge {
            background: #fef3c7;
            color: #92400e;
        }

        .btn-status {
            padding: 5px 12px;
            font-size: 11px;
            font-weight: 700;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-status.btn-complete {
            background: #d1fae5;
            color: #065f46;
        }

        .btn-status.btn-complete:hover {
            background: #a7f3d0;
        }

        .btn-status.btn-postpone {
            background: #fef3c7;
            color: #92400e;
        }

        .btn-status.btn-postpone:hover {
            background: #fde68a;
        }

        .btn-status.btn-remove-task {
            background: #fef2f2;
            color: var(--red);
        }

        .btn-status.btn-remove-task:hover {
            background: #fee2e2;
        }

        /* ── Summary Bar ── */
        .summary-bar {
            background: linear-gradient(135deg, #f8fafc, #f0f4ff);
            border: 1.5px solid #e0e7ff;
            border-radius: var(--radius);
            padding: 14px 18px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-around;
            text-align: center;
        }

        .summary-bar .stat {
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .summary-bar .stat-value {
            font-size: 22px;
            font-weight: 800;
            color: var(--brand);
        }

        .summary-bar .stat-label {
            font-size: 11px;
            font-weight: 600;
            color: var(--ink-3);
            text-transform: uppercase;
        }

        /* ── Unplanned Task Form ── */
        .unplanned-form {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .unplanned-form input {
            flex: 1;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 14px;
            outline: none;
        }

        .unplanned-form input:focus {
            border-color: var(--brand);
            box-shadow: 0 0 0 3px rgba(25, 119, 225, 0.1);
        }

        .unplanned-form button {
            flex-shrink: 0;
        }

        /* ── Empty State ── */
        .empty-state {
            text-align: center;
            padding: 30px;
            color: var(--ink-3);
        }

        .empty-state i {
            font-size: 36px;
            display: block;
            margin-bottom: 10px;
            color: #cbd5e1;
        }

        /* ── Divider ── */
        .divider {
            height: 1px;
            background: var(--border);
            margin: 20px 0;
        }

        /* ── Carried-over Alert ── */
        .carried-alert {
            background: #fef3c7;
            border: 1px solid #fde68a;
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 12px;
            color: #92400e;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* ── Loader ── */
        .diary-loader-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.7);
            z-index: 9999;
            display: none;
            align-items: center;
            justify-content: center;
            flex-direction: column;
        }

        .diary-loader-spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid var(--brand);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-bottom: 12px;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        /* ── Responsive ── */
        @media (max-width: 480px) {
            .diary-container {
                border-radius: 12px;
            }

            .diary-header {
                padding: 16px 18px;
            }

            .diary-header h2 {
                font-size: 17px;
            }

            .diary-body {
                padding: 16px;
            }

            .task-card {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }

            .task-card .task-actions {
                width: 100%;
                justify-content: flex-end;
            }

            .summary-bar {
                flex-wrap: wrap;
                gap: 10px;
            }

            .unplanned-form {
                flex-direction: column;
            }
        }
    </style>
</head>

<body>
    <!-- Page Loader -->
    <div class="diary-loader-overlay" id="diaryLoader">
        <div class="diary-loader-spinner"></div>
        <div style="color:var(--brand); font-weight:600; font-size:14px;" id="diaryLoaderText">Processing...</div>
    </div>

    <div class="container-scroller">
        <?php include('../partials/_navbar.php'); ?>
        <div class="container-fluid page-body-wrapper">
            <?php
            include('../partials/_sidebar.php');
            include('../partials/_settings-panel.php');
            ?>
            <div class="main-panel">
                <div class="content-wrapper">
                    <div class="diary-container">
                        <div class="diary-header">
                            <h2><i class="bi bi-journal-text"></i> Task Diary</h2>
                            <p>
                                <?= date('l, d M Y', strtotime($today)) ?>
                            </p>
                        </div>

                        <div class="diary-body">

                            <?php if ($mode === 'planned' && !$hasPlannedTasks): ?>
                                <!-- ═══════════ POST PUNCH-IN: PLANNED TASKS FORM ═══════════ -->

                                <?php if ($carriedCount > 0): ?>
                                    <div class="carried-alert">
                                        <i class="bi bi-arrow-repeat"></i>
                                        <span><strong>
                                                <?= $carriedCount ?> task(s)
                                            </strong> carried over from yesterday (postponed). They've been added as planned
                                            tasks.</span>
                                    </div>
                                <?php endif; ?>

                                <div class="section-title">
                                    <i class="bi bi-list-check" style="color:var(--brand);"></i>
                                    Enter Your Planned Tasks for Today (Within 30 Mins of Punch In)
                                </div>
                                <p style="font-size:13px; color:var(--ink-3); margin-bottom:16px;">
                                    Add the tasks you plan to work on today. Planned tasks must be entered within 30 minutes
                                    of Punch In. You must enter at least 1 task before proceeding.
                                </p>

                                <div id="plannedTasksContainer">
                                    <?php if ($carriedCount > 0):
                                        $idx = 1;
                                        foreach ($carriedTasks as $ct):
                                            ?>
                                            <div class="task-input-block">
                                                <span class="task-number">
                                                    <?= $idx ?>
                                                </span>
                                                <input type="text" class="planned-task-input"
                                                    value="<?= htmlspecialchars($ct['task_description']) ?>"
                                                    placeholder="Task description..." maxlength="500">
                                                <button type="button" class="btn-remove" onclick="removePlannedRow(this)"
                                                    title="Remove">
                                                    <i class="bi bi-x-lg"></i>
                                                </button>
                                            </div>
                                            <?php $idx++; endforeach; else: ?>
                                        <div class="task-input-block">
                                            <span class="task-number">1</span>
                                            <input type="text" class="planned-task-input"
                                                placeholder="Enter task description..." maxlength="500" autofocus>
                                            <button type="button" class="btn-remove" onclick="removePlannedRow(this)"
                                                title="Remove" style="visibility:hidden;">
                                                <i class="bi bi-x-lg"></i>
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <button type="button" class="btn-add-task" onclick="addPlannedRow()">
                                    <i class="bi bi-plus-circle"></i> Add Another Task
                                </button>

                                <div class="divider"></div>

                                <div style="display:flex; gap:10px; justify-content:center;">
                                    <button type="button" class="btn-primary-custom" id="submitPlannedBtn"
                                        onclick="submitPlannedTasks()">
                                        <i class="bi bi-check2-all"></i> Submit & Proceed to Dashboard
                                    </button>
                                </div>

                            <?php else: ?>
                                <!-- ═══════════ VIEW MODE: TASK MANAGEMENT ═══════════ -->

                                <!-- Summary Bar -->
                                <div class="summary-bar" id="summaryBar">
                                    <div class="stat">
                                        <span class="stat-value" id="totalStat">
                                            <?= $totalCount ?>
                                        </span>
                                        <span class="stat-label">Total</span>
                                    </div>
                                    <div class="stat">
                                        <span class="stat-value" id="pendingStat" style="color:#6366f1;">
                                            <?= $pendingCnt ?>
                                        </span>
                                        <span class="stat-label">Pending</span>
                                    </div>
                                    <div class="stat">
                                        <span class="stat-value" id="completedStat" style="color:var(--green);">
                                            <?= $completedCnt ?>
                                        </span>
                                        <span class="stat-label">Completed</span>
                                    </div>
                                    <div class="stat">
                                        <span class="stat-value" id="postponedStat" style="color:var(--amber);">
                                            <?= $postponedCnt ?>
                                        </span>
                                        <span class="stat-label">Postponed</span>
                                    </div>
                                </div>

                                <!-- Add Task -->
                                <div class="section-title">
                                    <i class="bi bi-plus-square"
                                        style="color:<?= $isWithin30Mins ? 'var(--brand)' : 'var(--amber)' ?>;"></i>
                                    <?= $addFormTitle ?>     <?php if (!empty($addFormBadge)): ?><small
                                            style="font-size:12px; font-weight:normal; color:var(--ink-3);"><?= $addFormBadge ?></small><?php endif; ?>
                                </div>
                                <div class="unplanned-form">
                                    <input type="text" id="unplannedInput" placeholder="<?= $addFormPlaceholder ?>"
                                        maxlength="500">
                                    <button type="button" class="btn-primary-custom" onclick="addUnplannedTask()"
                                        style="padding:10px 18px; font-size:13px;">
                                        <i class="bi bi-plus-lg"></i> Add
                                    </button>
                                </div>

                                <div class="divider"></div>

                                <?php if ($pendingCnt > 0): ?>
                                    <!-- Bulk Actions -->
                                    <div class="bulk-action-bar"
                                        style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 14px; margin-bottom:16px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                                        <div style="display:flex; align-items:center;">
                                            <input type="checkbox" id="selectAllTasks" onchange="toggleAllTasks(this)"
                                                style="width:16px; height:16px; margin-right:8px; cursor:pointer;">
                                            <label for="selectAllTasks"
                                                style="margin:0; font-size:13px; font-weight:600; color:var(--ink-1); cursor:pointer;">Select
                                                All Pending</label>
                                        </div>
                                        <div style="display:flex; gap:8px;">
                                            <button type="button" class="btn-primary-custom"
                                                onclick="submitBulkAction('Completed')"
                                                style="padding:6px 12px; font-size:12px; border-radius:6px; background:#059669; border:none;"
                                                disabled id="bulkDoneBtn"><i class="bi bi-check-all"></i> Mark Done</button>
                                            <button type="button" class="btn-secondary-custom"
                                                onclick="submitBulkAction('Postponed')"
                                                style="padding:6px 12px; font-size:12px; border-radius:6px; background:#fffbeb; color:#d97706; border-color:#fcd34d;"
                                                disabled id="bulkPostponeBtn"><i class="bi bi-arrow-right-circle"></i>
                                                Postpone</button>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- Task List -->
                                <div class="section-title">
                                    <i class="bi bi-journal-text" style="color:var(--brand);"></i>
                                    Today's Tasks
                                    <span class="badge-count" id="taskCountBadge">
                                        <?= $totalCount ?>
                                    </span>
                                </div>

                                <div id="taskListContainer">
                                    <?php if ($totalCount > 0): ?>
                                        <?php foreach ($todayTasks as $task):
                                            $statusClass = strtolower($task['status']);
                                            $isCarried = !empty($task['carried_from_date']);
                                            $displayTaskId = !empty($task['taskid']) ? $task['taskid'] : ('TSK-' . str_pad($task['id'], 5, '0', STR_PAD_LEFT));
                                            ?>
                                            <div class="task-card <?= $statusClass ?>" id="task-<?= $task['id'] ?>">
                                                <div class="task-info" style="display:flex; align-items:flex-start; gap:10px;">
                                                    <?php if ($task['status'] === 'Pending'): ?>
                                                        <input type="checkbox" class="task-bulk-checkbox" value="<?= $task['id'] ?>"
                                                            style="width:16px; height:16px; margin-top:4px; cursor:pointer;"
                                                            onchange="updateBulkCheckboxState()">
                                                    <?php endif; ?>
                                                    <div style="flex:1;">
                                                        <div class="task-desc">
                                                            <span class="badge bg-secondary text-white font-monospace"
                                                                style="font-size:11px; padding:3px 8px; border-radius:4px; font-weight:700; margin-right:6px;">
                                                                <?= htmlspecialchars($displayTaskId) ?>
                                                            </span>
                                                            <?= htmlspecialchars($task['task_description']) ?>
                                                        </div>
                                                        <div class="task-meta">
                                                            <span class="badge-type <?= strtolower($task['task_type']) ?>">
                                                                <?= $task['task_type'] ?>
                                                            </span>
                                                            <?php if ($isCarried): ?>
                                                                <span class="badge-type carried">↩ Carried Over</span>
                                                            <?php endif; ?>
                                                            <span class="badge-status <?= $statusClass ?>-badge">
                                                                <?= $task['status'] ?>
                                                            </span>
                                                            <span><i class="bi bi-clock"></i>
                                                                <?= $task['created_time'] ?>
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="task-actions">
                                                    <?php if ($task['status'] === 'Pending'): ?>
                                                        <button class="btn-status btn-complete"
                                                            onclick="updateTaskStatus(<?= $task['id'] ?>, 'Completed')"
                                                            title="Mark Completed">
                                                            <i class="bi bi-check-lg"></i> Done
                                                        </button>
                                                        <button class="btn-status btn-postpone"
                                                            onclick="updateTaskStatus(<?= $task['id'] ?>, 'Postponed')"
                                                            title="Postpone">
                                                            <i class="bi bi-arrow-right-circle"></i> Postpone
                                                        </button>
                                                    <?php else: ?>
                                                        <span style="font-size:18px; margin-right:4px;">
                                                            <?= $task['status'] === 'Completed' ? '✅' : '🔁' ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <button class="btn-status btn-remove-task"
                                                        onclick="removeTask(<?= $task['id'] ?>, '<?= htmlspecialchars($displayTaskId) ?>')"
                                                        title="Delete Task (Soft Delete)">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="empty-state" id="emptyState">
                                            <i class="bi bi-journal-x"></i>
                                            <p>No tasks for today yet. Add your first task above!</p>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="divider"></div>

                                <div style="display:flex; gap:10px; justify-content:center; flex-wrap:wrap;">
                                    <a href="<?= $redirectURL ?>" class="btn-secondary-custom"
                                        style="text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
                                        <i class="bi bi-arrow-left"></i> Back to Dashboard
                                    </a>
                                </div>

                            <?php endif; ?>

                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="../vendors/jquery/jquery.min.js"></script>
    <script src="../vendors/select2/select2.min.js"></script>
    <script src="../vendors/js/vendor.bundle.base.js"></script>
    <script src="../vendors/typeahead.js/typeahead.bundle.min.js"></script>
    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>
    <script src="../js/settings.js"></script>
    <script src="../js/select2.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>




    <script>
        // Loader Functions
        function showLoader(text = 'Processing...') {
            document.getElementById('diaryLoaderText').textContent = text;
            document.getElementById('diaryLoader').style.display = 'flex';
        }
        function hideLoader() {
            document.getElementById('diaryLoader').style.display = 'none';
        }

        // ═══════════ PLANNED TASKS (Post Punch-In) ═══════════

        function addPlannedRow() {
            const container = document.getElementById('plannedTasksContainer');
            const blocks = container.querySelectorAll('.task-input-block');
            const num = blocks.length + 1;

            const div = document.createElement('div');
            div.className = 'task-input-block';
            div.innerHTML = `
        <span class="task-number">${num}</span>
        <input type="text" class="planned-task-input" placeholder="Enter task description..." maxlength="500">
        <button type="button" class="btn-remove" onclick="removePlannedRow(this)" title="Remove">
            <i class="bi bi-x-lg"></i>
        </button>
    `;
            container.appendChild(div);
            div.querySelector('input').focus();
            updatePlannedNumbers();
        }

        function removePlannedRow(btn) {
            const container = document.getElementById('plannedTasksContainer');
            const blocks = container.querySelectorAll('.task-input-block');
            if (blocks.length <= 1) {
                Swal.fire({ icon: 'warning', text: 'You must have at least 1 task.', timer: 2000, showConfirmButton: false });
                return;
            }
            btn.closest('.task-input-block').remove();
            updatePlannedNumbers();
        }

        function updatePlannedNumbers() {
            const blocks = document.querySelectorAll('#plannedTasksContainer .task-input-block');
            blocks.forEach((block, i) => {
                block.querySelector('.task-number').textContent = i + 1;
                const removeBtn = block.querySelector('.btn-remove');
                removeBtn.style.visibility = blocks.length <= 1 ? 'hidden' : 'visible';
            });
        }

        function submitPlannedTasks() {
            const inputs = document.querySelectorAll('.planned-task-input');
            const tasks = [];

            inputs.forEach(input => {
                const val = input.value.trim();
                if (val) tasks.push(val);
            });

            if (tasks.length === 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'No Tasks Entered',
                    text: 'Please enter at least 1 planned task before proceeding.',
                    confirmButtonColor: '#1977e1'
                });
                return;
            }

            const btn = document.getElementById('submitPlannedBtn');
            btn.disabled = true;
            showLoader('Saving planned tasks...');

            $.ajax({
                url: '../ajax/taskDiary.php',
                method: 'POST',
                data: { action: 'save_planned', tasks: tasks },
                dataType: 'json',
                success: function (res) {
                    hideLoader();
                    if (res.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Tasks Saved!',
                            text: res.msg,
                            timer: 1800,
                            showConfirmButton: false,
                            timerProgressBar: true
                        }).then(() => {
                            window.location.href = '<?= $redirectURL ?>';
                        });
                    } else {
                        btn.disabled = false;
                        Swal.fire({ icon: 'error', text: res.msg, confirmButtonColor: '#1977e1' });
                    }
                },
                error: function () {
                    hideLoader();
                    btn.disabled = false;
                    Swal.fire({ icon: 'error', text: 'Network error. Please try again.', confirmButtonColor: '#1977e1' });
                }
            });
        }


        // ═══════════ VIEW MODE OPERATIONS ═══════════

        function addUnplannedTask() {
            const input = document.getElementById('unplannedInput');
            const desc = input.value.trim();
            if (!desc) {
                Swal.fire({ icon: 'warning', text: 'Please enter a task description.', timer: 2000, showConfirmButton: false });
                return;
            }

            showLoader('Adding task...');
            $.ajax({
                url: '../ajax/taskDiary.php',
                method: 'POST',
                data: { action: 'add_unplanned', task_description: desc },
                dataType: 'json',
                success: function (res) {
                    hideLoader();
                    if (res.success) {
                        input.value = '';
                        Swal.fire({ icon: 'success', text: res.msg, timer: 1500, showConfirmButton: false });
                        setTimeout(() => location.reload(), 1600);
                    } else {
                        Swal.fire({ icon: 'error', text: res.msg, confirmButtonColor: '#1977e1' });
                    }
                },
                error: function () {
                    hideLoader();
                    Swal.fire({ icon: 'error', text: 'Network error.', confirmButtonColor: '#1977e1' });
                }
            });
        }

        function updateTaskStatus(taskId, status) {
            const confirmText = status === 'Completed'
                ? 'Mark this task as completed?'
                : 'Postpone this task? It will appear as a planned task tomorrow.';

            Swal.fire({
                title: status === 'Completed' ? '✅ Complete Task' : '🔁 Postpone Task',
                text: confirmText,
                showCancelButton: true,
                confirmButtonText: 'Yes',
                cancelButtonText: 'Cancel',
                confirmButtonColor: status === 'Completed' ? '#059669' : '#d97706',
                cancelButtonColor: '#6b7280'
            }).then((result) => {
                if (!result.isConfirmed) return;

                showLoader('Updating task...');
                $.ajax({
                    url: '../ajax/taskDiary.php',
                    method: 'POST',
                    data: { action: 'update_status', task_id: taskId, status: status },
                    dataType: 'json',
                    success: function (res) {
                        hideLoader();
                        if (res.success) {
                            Swal.fire({ icon: 'success', text: res.msg, timer: 1200, showConfirmButton: false });
                            setTimeout(() => location.reload(), 1300);
                        } else {
                            Swal.fire({ icon: 'error', text: res.msg, confirmButtonColor: '#1977e1' });
                        }
                    },
                    error: function () {
                        hideLoader();
                        Swal.fire({ icon: 'error', text: 'Network error.', confirmButtonColor: '#1977e1' });
                    }
                });
            });
        }

        function removeTask(taskId, taskidStr = '') {
            const promptTitle = taskidStr
                ? `Are you sure you want to delete the task ${taskidStr}?`
                : 'Are you sure you want to delete this task?';

            Swal.fire({
                title: promptTitle,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, Delete',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#6b7280'
            }).then((result) => {
                if (!result.isConfirmed) return;

                showLoader('Deleting task...');
                $.ajax({
                    url: '../ajax/taskDiary.php',
                    method: 'POST',
                    data: { action: 'remove_task', task_id: taskId },
                    dataType: 'json',
                    success: function (res) {
                        hideLoader();
                        if (res.success) {
                            Swal.fire({ icon: 'success', text: res.msg, timer: 1200, showConfirmButton: false });
                            setTimeout(() => location.reload(), 1300);
                        } else {
                            Swal.fire({ icon: 'error', text: res.msg, confirmButtonColor: '#1977e1' });
                        }
                    },
                    error: function () {
                        hideLoader();
                        Swal.fire({ icon: 'error', text: 'Network error.', confirmButtonColor: '#1977e1' });
                    }
                });
            });
        }

        // Bulk Actions
        function toggleAllTasks(checkbox) {
            const checkboxes = document.querySelectorAll('.task-bulk-checkbox');
            checkboxes.forEach(cb => cb.checked = checkbox.checked);
            updateBulkCheckboxState();
        }

        function updateBulkCheckboxState() {
            const checkboxes = document.querySelectorAll('.task-bulk-checkbox');
            let anyChecked = false;
            let allChecked = true;
            checkboxes.forEach(cb => {
                if (cb.checked) anyChecked = true;
                else allChecked = false;
            });

            if (checkboxes.length === 0) allChecked = false;

            const selectAll = document.getElementById('selectAllTasks');
            if (selectAll) selectAll.checked = allChecked;

            const doneBtn = document.getElementById('bulkDoneBtn');
            const postponeBtn = document.getElementById('bulkPostponeBtn');
            if (doneBtn) doneBtn.disabled = !anyChecked;
            if (postponeBtn) postponeBtn.disabled = !anyChecked;
        }

        function submitBulkAction(status) {
            const checkboxes = document.querySelectorAll('.task-bulk-checkbox:checked');
            const taskIds = Array.from(checkboxes).map(cb => cb.value);

            if (taskIds.length === 0) return;

            const confirmText = status === 'Completed'
                ? `Mark ${taskIds.length} tasks as completed?`
                : `Postpone ${taskIds.length} tasks? They will appear as planned tasks tomorrow.`;

            Swal.fire({
                title: status === 'Completed' ? '✅ Bulk Complete' : '🔁 Bulk Postpone',
                text: confirmText,
                showCancelButton: true,
                confirmButtonText: 'Yes, proceed',
                cancelButtonText: 'Cancel',
                confirmButtonColor: status === 'Completed' ? '#059669' : '#d97706',
                cancelButtonColor: '#6b7280'
            }).then((result) => {
                if (!result.isConfirmed) return;

                showLoader('Updating tasks...');
                $.ajax({
                    url: '../ajax/taskDiary.php',
                    method: 'POST',
                    data: { action: 'bulk_update_status', task_ids: taskIds, status: status },
                    dataType: 'json',
                    success: function (res) {
                        hideLoader();
                        if (res.success) {
                            Swal.fire({ icon: 'success', text: res.msg, timer: 1200, showConfirmButton: false });
                            setTimeout(() => location.reload(), 1300);
                        } else {
                            Swal.fire({ icon: 'error', text: res.msg, confirmButtonColor: '#1977e1' });
                        }
                    },
                    error: function () {
                        hideLoader();
                        Swal.fire({ icon: 'error', text: 'Network error.', confirmButtonColor: '#1977e1' });
                    }
                });
            });
        }

        // Allow Enter key to add tasks
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                const unplannedInput = document.getElementById('unplannedInput');
                if (document.activeElement === unplannedInput) {
                    e.preventDefault();
                    addUnplannedTask();
                }
                if (document.activeElement.classList.contains('planned-task-input')) {
                    e.preventDefault();
                    addPlannedRow();
                }
            }
        });
    </script>
</body>

</html>