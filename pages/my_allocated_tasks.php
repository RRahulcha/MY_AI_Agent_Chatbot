<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}
include('../include/db_config.php');
$myUserId = $_SESSION['userId'];

// Fetch user details for personal greeting
$userRes = mysqli_query($con, "SELECT fullName FROM signup WHERE userId = '" . mysqli_real_escape_string($con, $myUserId) . "' LIMIT 1");
$userRow = mysqli_fetch_assoc($userRes);
$userFullName = $userRow['fullName'] ?? 'User';

// ── Analytics Computation ────────────────────────────────────────────────────
$tasksQuery = mysqli_query($con, "
    SELECT t.*, COALESCE(s.fullName, 'Unknown') as fullName 
    FROM task_allocation t 
    LEFT JOIN signup s ON t.allocator_id COLLATE utf8mb4_unicode_ci = s.userId COLLATE utf8mb4_unicode_ci 
    WHERE t.receiver_id = '" . mysqli_real_escape_string($con, $myUserId) . "' 
    ORDER BY t.deadline ASC
");

$tasks = [];
$totalTasks      = 0;
$completedTasks  = 0;
$inProgressTasks = 0;
$inReviewTasks   = 0;
$pendingTasks    = 0;
$overdueTasks    = 0;

if ($tasksQuery) {
    while ($r = mysqli_fetch_assoc($tasksQuery)) {
        $tasks[] = $r;
        $totalTasks++;
        $status = trim($r['status']);
        $isOverdue = (strtotime($r['deadline']) < time() && $status !== 'Done');

        if ($status === 'Done') {
            $completedTasks++;
        } elseif ($status === 'In Progress') {
            $inProgressTasks++;
        } elseif ($status === 'In Review') {
            $inReviewTasks++;
        } else {
            $pendingTasks++;
        }

        if ($isOverdue) {
            $overdueTasks++;
        }
    }
}

$activeTasks    = $totalTasks - $completedTasks;
$completionRate = $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100) : 0;

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <title>My Allocated Tasks</title>
    <?php include('../partials/_links.php'); ?>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        body { background-color: #f4f6fb !important; }
        
        /* ── Page Header ────────────────────────────────────────── */
        .page-header-box {
            background: #fff;
            border-radius: 12px;
            padding: 20px 24px;
            border: 1px solid #e2e8f0;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
        }

        /* ── KPI Analytics Cards ────────────────────────────────── */
        .kpi-card {
            background: #fff;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            padding: 16px 20px;
            transition: all 0.2s ease;
            height: 100%;
            box-shadow: 0 2px 6px rgba(0,0,0,0.03);
        }
        .kpi-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 18px rgba(0,0,0,0.07);
        }
        .kpi-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            flex-shrink: 0;
        }
        .kpi-val {
            font-size: 1.6rem;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.1;
            margin-top: 4px;
        }
        .kpi-lbl {
            font-size: 0.72rem;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .kpi-sub {
            font-size: 0.72rem;
            color: #94a3b8;
            margin-top: 3px;
        }

        /* ── Completion Progress Bar ────────────────────────────── */
        .progress-card {
            background: #fff;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            padding: 16px 20px;
            margin-bottom: 24px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.03);
        }
        .progress-bar-wrap {
            height: 10px;
            background: #e2e8f0;
            border-radius: 99px;
            overflow: hidden;
            margin-top: 8px;
        }
        .progress-bar-fill {
            height: 100%;
            border-radius: 99px;
            background: linear-gradient(90deg, #4b49ac 0%, #6366f1 100%);
            transition: width 0.6s ease;
        }

        /* ── Status Pills & Badges ──────────────────────────────── */
        .badge-status {
            font-size: 0.73rem;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .badge-done        { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .badge-in-progress { background: #dbeafe; color: #1d4ed8; border: 1px solid #bfdbfe; }
        .badge-in-review   { background: #f3e8ff; color: #7e22ce; border: 1px solid #e9d5ff; }
        .badge-pending     { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
        
        .badge-overdue-tag {
            background: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fecaca;
            font-size: 0.68rem;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 12px;
            margin-top: 3px;
            display: inline-block;
        }

        /* ── Filter Tabs ────────────────────────────────────────── */
        .filter-btn {
            border: 1px solid #cbd5e1;
            background: #fff;
            color: #475569;
            font-weight: 600;
            font-size: 0.78rem;
            padding: 6px 14px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.15s;
        }
        .filter-btn:hover { background: #f8fafc; color: #0f172a; }
        .filter-btn.active {
            background: #4b49ac;
            color: #fff;
            border-color: #4b49ac;
        }

        /* ── Table Styling ──────────────────────────────────────── */
        .card-table {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #fff;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
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
        .table tbody td {
            vertical-align: middle !important;
            font-size: 0.84rem !important;
            padding: 12px 14px !important;
            border-bottom: 1px solid #f1f5f9 !important;
            color: #334155;
        }
        .table tbody tr:hover { background-color: #f8fafc !important; }

        /* ── Quick Status Select ────────────────────────────────── */
        .status-select {
            height: 32px !important;
            font-size: 0.78rem !important;
            font-weight: 600 !important;
            border-radius: 6px !important;
            border: 1px solid #cbd5e1 !important;
            padding: 2px 8px !important;
            cursor: pointer;
        }
        .status-select:focus {
            border-color: #4b49ac !important;
            box-shadow: 0 0 0 2px rgba(75, 73, 172, 0.15) !important;
        }

        .btn-doc {
            background: #e0e7ff;
            color: #4338ca;
            border: 1px solid #c7d2fe;
            border-radius: 6px;
            padding: 4px 10px;
            font-size: 0.75rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.15s;
        }
        .btn-doc:hover {
            background: #4338ca;
            color: #fff;
            text-decoration: none;
        }

        .btn-ext {
            font-size: 0.72rem;
            font-weight: 600;
            padding: 4px 9px;
            border-radius: 6px;
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
                    <div class="page-header-box d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <div>
                            <h3 class="fw-bold text-dark mb-1" style="font-size:1.35rem;">
                                <i class="fa-solid fa-list-check text-primary me-2"></i> My Allocated Tasks
                            </h3>
                            <p class="text-muted small mb-0">Track, manage, and complete tasks assigned to you by leads and managers.</p>
                        </div>
                        <div class="text-end">
                            <span class="badge" style="background:#eef2ff; color:#4b49ac; font-size:0.8rem; padding:6px 14px; border-radius:20px; font-weight:600;">
                                <i class="fa-solid fa-user me-1"></i> <?= htmlspecialchars($userFullName) ?>
                            </span>
                        </div>
                    </div>

                    <!-- ── Alert Message Flash ──────────────────────────────────────────── -->
                    <?php if (isset($_SESSION['msg'])): ?>
                        <div class="alert alert-<?= $_SESSION['status'] ? 'success' : 'danger'; ?> alert-dismissible fade show mb-4" role="alert" style="border-radius:10px;">
                            <i class="fa-solid <?= $_SESSION['status'] ? 'fa-circle-check' : 'fa-circle-exclamation'; ?> me-2"></i>
                            <?= $_SESSION['msg']; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                        <?php unset($_SESSION['msg'], $_SESSION['status']); ?>
                    <?php endif; ?>

                    <!-- ── KPI Analytics Cards ──────────────────────────────────────────── -->
                    <div class="row mb-3 g-3">
                        <div class="col-md-2 col-sm-4 col-6">
                            <div class="kpi-card">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div class="kpi-lbl">Total</div>
                                    <div class="kpi-icon" style="background:#EEF2FF; color:#4B49AC;">
                                        <i class="fa-solid fa-layer-group"></i>
                                    </div>
                                </div>
                                <div class="kpi-val"><?= $totalTasks ?></div>
                                <div class="kpi-sub">All assigned</div>
                            </div>
                        </div>

                        <div class="col-md-2 col-sm-4 col-6">
                            <div class="kpi-card">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div class="kpi-lbl">Completed</div>
                                    <div class="kpi-icon" style="background:#E6F4EA; color:#1E8E3E;">
                                        <i class="fa-solid fa-circle-check"></i>
                                    </div>
                                </div>
                                <div class="kpi-val text-success"><?= $completedTasks ?></div>
                                <div class="kpi-sub"><?= $completionRate ?>% finish rate</div>
                            </div>
                        </div>

                        <div class="col-md-2 col-sm-4 col-6">
                            <div class="kpi-card">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div class="kpi-lbl">In Progress</div>
                                    <div class="kpi-icon" style="background:#E0F2FE; color:#0284C7;">
                                        <i class="fa-solid fa-spinner"></i>
                                    </div>
                                </div>
                                <div class="kpi-val" style="color:#0284C7;"><?= $inProgressTasks ?></div>
                                <div class="kpi-sub">Active working</div>
                            </div>
                        </div>

                        <div class="col-md-2 col-sm-4 col-6">
                            <div class="kpi-card">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div class="kpi-lbl">In Review</div>
                                    <div class="kpi-icon" style="background:#F3E8FF; color:#7E22CE;">
                                        <i class="fa-solid fa-magnifying-glass font-weight-bold"></i>
                                    </div>
                                </div>
                                <div class="kpi-val" style="color:#7E22CE;"><?= $inReviewTasks ?></div>
                                <div class="kpi-sub">Awaiting verification</div>
                            </div>
                        </div>

                        <div class="col-md-2 col-sm-4 col-6">
                            <div class="kpi-card">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div class="kpi-lbl">Pending</div>
                                    <div class="kpi-icon" style="background:#FEF7E0; color:#B06000;">
                                        <i class="fa-solid fa-clock"></i>
                                    </div>
                                </div>
                                <div class="kpi-val" style="color:#B06000;"><?= $pendingTasks ?></div>
                                <div class="kpi-sub">To be started</div>
                            </div>
                        </div>

                        <div class="col-md-2 col-sm-4 col-6">
                            <div class="kpi-card">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div class="kpi-lbl">Overdue</div>
                                    <div class="kpi-icon" style="background:#FEE2E2; color:#DC2626;">
                                        <i class="fa-solid fa-triangle-exclamation"></i>
                                    </div>
                                </div>
                                <div class="kpi-val text-danger"><?= $overdueTasks ?></div>
                                <div class="kpi-sub">Passed deadline</div>
                            </div>
                        </div>
                    </div>

                    <!-- ── Completion Progress Bar ──────────────────────────────────────── -->
                    <div class="progress-card">
                        <div class="d-flex align-items-center justify-content-between">
                            <span class="fw-bold text-dark" style="font-size:0.85rem;">
                                <i class="fa-solid fa-chart-line text-primary me-2"></i> Overall Workload Completion
                            </span>
                            <span class="fw-bold" style="font-size:0.85rem; color:#4b49ac;">
                                <?= $completedTasks ?> / <?= $totalTasks ?> Tasks Completed (<?= $completionRate ?>%)
                            </span>
                        </div>
                        <div class="progress-bar-wrap">
                            <div class="progress-bar-fill" style="width: <?= $completionRate ?>%;"></div>
                        </div>
                    </div>

                    <!-- ── Tasks Table Card ─────────────────────────────────────────────── -->
                    <div class="card-table p-3 p-md-4">
                        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                            <div>
                                <h5 class="fw-bold mb-0 text-dark">
                                    <i class="fa-solid fa-tasks me-2 text-primary"></i> Task List
                                </h5>
                                <small class="text-muted">Manage status updates and request deadline extensions when needed.</small>
                            </div>

                            <!-- Filter Pills -->
                            <div class="d-flex gap-1 flex-wrap" id="statusFilterGroup">
                                <button class="filter-btn active" onclick="filterStatus('all', this)">All (<?= $totalTasks ?>)</button>
                                <button class="filter-btn" onclick="filterStatus('In Progress', this)">In Progress (<?= $inProgressTasks ?>)</button>
                                <button class="filter-btn" onclick="filterStatus('In Review', this)">In Review (<?= $inReviewTasks ?>)</button>
                                <button class="filter-btn" onclick="filterStatus('Done', this)">Completed (<?= $completedTasks ?>)</button>
                                <button class="filter-btn" onclick="filterStatus('overdue', this)">Overdue (<?= $overdueTasks ?>)</button>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover w-100" id="myTaskTable">
                                <thead>
                                    <tr>
                                        <th style="width:15%">Allocated By</th>
                                        <th style="width:25%">Task Title</th>
                                        <th style="width:15%">Project</th>
                                        <th style="width:15%">Deadline</th>
                                        <th style="width:15%">Remarks</th>
                                        <th style="width:5%" class="text-center">Doc</th>
                                        <th style="width:10%">Status</th>
                                        <th style="width:10%" class="text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($tasks as $r): 
                                        $status = trim($r['status']);
                                        $isOverdue = (strtotime($r['deadline']) < time() && $status !== 'Done');
                                        
                                        $badgeClass = 'badge-pending';
                                        if ($status === 'Done')        $badgeClass = 'badge-done';
                                        if ($status === 'In Progress') $badgeClass = 'badge-in-progress';
                                        if ($status === 'In Review')   $badgeClass = 'badge-in-review';

                                        $filterKey = $isOverdue ? 'overdue' : $status;
                                    ?>
                                    <tr data-status="<?= htmlspecialchars($status) ?>" data-overdue="<?= $isOverdue ? 'true' : 'false' ?>">
                                        <td>
                                            <div class="fw-bold text-dark"><?= htmlspecialchars($r['fullName']) ?></div>
                                        </td>
                                        <td>
                                            <span class="fw-bold text-dark"><?= htmlspecialchars($r['task_title']) ?></span>
                                        </td>
                                        <td>
                                            <?php if ($r['task_type'] === 'Project work'): ?>
                                                <span class="badge bg-light text-dark border"><?= htmlspecialchars($r['project_name']) ?></span>
                                            <?php else: ?>
                                                <span class="text-muted fst-italic">Other / Internal</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="<?= $isOverdue ? 'text-danger fw-bold' : '' ?>">
                                                <i class="fa-regular fa-clock me-1"></i>
                                                <?= date('d M Y, h:i A', strtotime($r['deadline'])) ?>
                                            </div>
                                            <?php if ($isOverdue): ?>
                                                <span class="badge-overdue-tag">
                                                    <i class="fa-solid fa-triangle-exclamation me-1"></i>Overdue
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="text-muted small"><?= !empty($r['remark']) ? htmlspecialchars($r['remark']) : '—' ?></span>
                                        </td>
                                        <td class="text-center">
                                            <?php if (!empty($r['document_path'])): ?>
                                                <a href="../uploads/<?= htmlspecialchars($r['document_path']) ?>" target="_blank" class="btn-doc" title="Download Document">
                                                    <i class="fa-solid fa-file-arrow-down me-1"></i>Doc
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted small">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge-status <?= $badgeClass ?>">
                                                <?php if ($status === 'Done'): ?>
                                                    <i class="fa-solid fa-circle-check me-1"></i>
                                                <?php elseif ($status === 'In Progress'): ?>
                                                    <i class="fa-solid fa-spinner me-1"></i>
                                                <?php elseif ($status === 'In Review'): ?>
                                                    <i class="fa-solid fa-magnifying-glass me-1"></i>
                                                <?php else: ?>
                                                    <i class="fa-solid fa-clock me-1"></i>
                                                <?php endif; ?>
                                                <?= htmlspecialchars($status) ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($status !== 'Done'): ?>
                                                <form action="process_task_receiver.php" method="POST" class="d-inline-block mb-1">
                                                    <input type="hidden" name="action" value="update_status">
                                                    <input type="hidden" name="task_id" value="<?= $r['id'] ?>">
                                                    <input type="hidden" name="allocator_id" value="<?= $r['allocator_id'] ?>">
                                                    
                                                    <select name="new_status" class="form-select form-select-sm status-select" onchange="this.form.submit()">
                                                        <option value="">Update...</option>
                                                        <option value="In Progress" <?= $status === 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                                                        <option value="In Review" <?= $status === 'In Review' ? 'selected' : '' ?>>In Review</option>
                                                    </select>
                                                </form>

                                                <?php if ($r['ext_status'] === 'None'): ?>
                                                    <div>
                                                        <button type="button" class="btn btn-outline-danger btn-ext py-1 px-2 text-nowrap" data-bs-toggle="modal" data-bs-target="#reqExtModal<?= $r['id'] ?>">
                                                            <i class="fa-solid fa-clock-rotate-left me-1"></i> Extension
                                                        </button>
                                                    </div>
                                                <?php elseif ($r['ext_status'] === 'Requested'): ?>
                                                    <div>
                                                        <span class="badge bg-warning text-dark border" style="font-size:0.68rem;">Extension Pending</span>
                                                    </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-success fw-bold small">
                                                    <i class="fa-solid fa-circle-check me-1"></i> Complete
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>

                                    <!-- ── Extension Request Modal ────────────────────────────────────── -->
                                    <div class="modal fade" id="reqExtModal<?= $r['id'] ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog modal-dialog-centered">
                                            <div class="modal-content border-0 shadow-lg" style="border-radius:14px;">
                                                <form action="process_task_receiver.php" method="POST">
                                                    <input type="hidden" name="action" value="req_extension">
                                                    <input type="hidden" name="task_id" value="<?= $r['id'] ?>">
                                                    <input type="hidden" name="allocator_id" value="<?= $r['allocator_id'] ?>">
                                                    <input type="hidden" name="task_title" value="<?= htmlspecialchars($r['task_title']) ?>">
                                                    
                                                    <div class="modal-header bg-light">
                                                        <h5 class="modal-title fw-bold text-danger" style="font-size:1rem;">
                                                            <i class="fa-solid fa-clock-rotate-left me-2"></i> Request Extension
                                                        </h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                    </div>
                                                    <div class="modal-body p-4">
                                                        <div class="mb-3">
                                                            <label class="form-label text-muted small fw-bold mb-1">Task Title</label>
                                                            <div class="fw-bold text-dark"><?= htmlspecialchars($r['task_title']) ?></div>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label text-muted small fw-bold mb-1">Current Deadline</label>
                                                            <div class="text-danger fw-bold"><?= date('d M Y, h:i A', strtotime($r['deadline'])) ?></div>
                                                        </div>
                                                        <div class="mb-2">
                                                            <label class="form-label fw-bold small mb-1">Reason for Extension <span class="text-danger">*</span></label>
                                                            <textarea class="form-control" name="reason" rows="3" placeholder="Explain why you need additional time..." required></textarea>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer bg-light">
                                                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" class="btn btn-danger btn-sm px-3">Send Request</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

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
        let dataTableInstance = null;

        $(document).ready(function() {
            dataTableInstance = $('#myTaskTable').DataTable({
                "order": [[ 3, "asc" ]],
                "pageLength": 10,
                "language": {
                    "search": "Search tasks:",
                    "lengthMenu": "Show _MENU_ tasks"
                }
            });
        });

        function filterStatus(statusKey, btnElement) {
            // Update active filter button
            $('#statusFilterGroup .filter-btn').removeClass('active');
            $(btnElement).addClass('active');

            if (!dataTableInstance) return;

            if (statusKey === 'all') {
                dataTableInstance.column(6).search('').draw();
                // custom reset
                $.fn.dataTable.ext.search.pop();
                dataTableInstance.draw();
            } else if (statusKey === 'overdue') {
                $.fn.dataTable.ext.search.pop();
                $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                    const row = dataTableInstance.row(dataIndex).node();
                    return $(row).attr('data-overdue') === 'true';
                });
                dataTableInstance.draw();
            } else {
                $.fn.dataTable.ext.search.pop();
                dataTableInstance.column(6).search(statusKey).draw();
            }
        }
    </script>
</body>
</html>