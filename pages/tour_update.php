<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>"; exit;
}
include('../include/db_config.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tourId  = (int) $_POST['tour_id'];
    $action  = $_POST['action'];
    $adminId = $_SESSION['userId'];

    if ($action === 'Approve') {
        $sql = "UPDATE tour SET status='Approved', approvedBy='$adminId', rejectionReason='' WHERE id=$tourId";
    } elseif ($action === 'Reject') {
        $rr  = mysqli_real_escape_string($con, $_POST['rejection_reason']);
        $sql = "UPDATE tour SET status='Rejected', rejectionReason='$rr', approvedBy='$adminId' WHERE id=$tourId";
    } elseif ($action === 'Cancel') {
        $cr  = mysqli_real_escape_string($con, $_POST['cancel_reason']);
        $sql = "UPDATE tour SET status='Cancelled', cancelReason='$cr', approvedBy='$adminId' WHERE id=$tourId";
    } else {
        $_SESSION['status'] = false; $_SESSION['msg'] = "Invalid action.";
        echo "<script>window.location.href = 'tour_update.php';</script>"; exit;
    }

    $_SESSION['status'] = mysqli_query($con, $sql) ? true : false;
    if ($_SESSION['status']) {

        include_once('../include/notifications.php');

        $tourQuery = mysqli_query($con, "
            SELECT userId, fromDate, toDate
            FROM tour
            WHERE id = '$tourId'
            LIMIT 1
        ");

        if ($tourQuery && mysqli_num_rows($tourQuery) > 0) {
            $tourData = mysqli_fetch_assoc($tourQuery);
            $targetUserId = $tourData['userId'];

            $userQuery = mysqli_query($con, "
                SELECT token
                FROM signup
                WHERE userId = '$targetUserId'
                LIMIT 1
            ");

            if ($userQuery && mysqli_num_rows($userQuery) > 0) {
                $userData = mysqli_fetch_assoc($userQuery);

                if (!empty($userData['token'])) {
                    $fcmToken = $userData['token'];
                    $fromDate = date('d-m-Y', strtotime($tourData['fromDate']));
                    $toDate   = date('d-m-Y', strtotime($tourData['toDate']));

                    $title = "Tour Request Update";
                    $body = "Your tour request from $fromDate to $toDate has been " . strtolower($action) . ".";

                    sendFCMNotification(
                        $fcmToken,
                        $title,
                        $body,
                        $accessToken,
                        $projectId
                    );

                    $statusValue = 'sent';
                    $createdAt = date('Y-m-d H:i:s');

                    $stmt = $con->prepare("
                        INSERT INTO notifications
                        (userId, title, message, status, is_read, created_at)
                        VALUES (?, ?, ?, ?, 1, ?)
                    ");

                    $stmt->bind_param(
                        "sssss",
                        $targetUserId,
                        $title,
                        $body,
                        $statusValue,
                        $createdAt
                    );

                    $stmt->execute();
                    $stmt->close();
                }
            }
        }
    }

    $_SESSION['msg'] = $_SESSION['status']
        ? "Tour " . ($action === 'Approve' ? "approved" : ($action === 'Reject' ? "rejected" : "cancelled")) . " successfully."
        : "Error: " . mysqli_error($con);
    echo "<script>window.location.href = 'tour_update.php';</script>"; exit;
}

/* -----------------------------------------------------
   📊 REAL-TIME TOUR ANALYTICS QUERY
----------------------------------------------------- */
$statsSql = "SELECT 
    COUNT(*) AS total,
    SUM(CASE WHEN status = 'Pending' OR status IS NULL OR status = '' THEN 1 ELSE 0 END) AS pending,
    SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) AS approved,
    SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) AS rejected,
    SUM(CASE WHEN status = 'Cancelled' THEN 1 ELSE 0 END) AS cancelled
FROM tour";

$statsRes = mysqli_query($con, $statsSql);
$stats = mysqli_fetch_assoc($statsRes) ?? ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'cancelled' => 0];

$totalCount     = intval($stats['total']);
$pendingCount   = intval($stats['pending']);
$approvedCount  = intval($stats['approved']);
$rejectedCount  = intval($stats['rejected']);
$cancelledCount = intval($stats['cancelled']);

$approvedPct  = $totalCount > 0 ? round(($approvedCount / $totalCount) * 100, 1) : 0;
$pendingPct   = $totalCount > 0 ? round(($pendingCount / $totalCount) * 100, 1) : 0;
$rejectedPct  = $totalCount > 0 ? round(($rejectedCount / $totalCount) * 100, 1) : 0;
$cancelledPct = $totalCount > 0 ? round(($cancelledCount / $totalCount) * 100, 1) : 0;

$filterStatus = isset($_GET['status']) ? trim($_GET['status']) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Tour Applications & Analytics</title>
    <?php include('../partials/_links.php'); ?>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.4.1/css/responsive.bootstrap5.min.css">

    <style>
        :root {
            --brand:      #103d9e;
            --brand-dark: #0c2f7a;
            --brand-bg:   #eef2fc;
            --green:      #1e7e44;
            --green-h:    #165e33;
            --red:        #c0392b;
            --red-h:      #962d22;
            --orange:     #b45309;
            --orange-h:   #92400e;
            --border:     #dee2ec;
            --hover-row:  #f0f4fd;
            --muted:      #6c757d;
            --radius:     6px;
        }

        body {
            background: #f4f6fb;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        }

        .page-body-wrapper, .content-wrapper, .main-panel { overflow-x: hidden !important; }
        .content-wrapper { padding: 20px !important; }

        .stat-card-box {
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 12px rgba(0,0,0,0.03);
            transition: all 0.2s ease-in-out;
        }

        .stat-card-box:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(16, 61, 158, 0.1);
        }

        .stat-card-box.active-filter {
            border: 2px solid #103d9e !important;
            background: #f0f4ff !important;
        }

        .icon-circle {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }

        .font-11 { font-size: 11px; }
        .font-12 { font-size: 12px; }
        .font-13 { font-size: 13px; }

        .card { border: 1px solid var(--border); border-radius: 12px; box-shadow: 0 1px 6px rgba(16,61,158,.07); background: #ffffff; }
        .card-body { padding: 20px; }
        .card-title { font-size: 1rem; font-weight: 700; color: var(--brand); margin-bottom: 2px; }

        .section-title { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; }
        .section-title::after { content: ''; flex: 1; height: 2px; background: linear-gradient(to right, var(--brand-bg), transparent); border-radius: 2px; }

        /* ── Tables ── */
        #pendingTourTable, #processedTourTable {
            width: 100% !important; font-size: .855rem;
            border-collapse: separate; border-spacing: 0;
        }
        #pendingTourTable thead th, #processedTourTable thead th {
            background: var(--brand) !important; color: #fff !important;
            padding: 11px 14px; font-weight: 600; font-size: .785rem;
            text-transform: uppercase; letter-spacing: .045em;
            border: none; border-bottom: 2px solid var(--brand-dark); white-space: nowrap;
        }
        #pendingTourTable thead th:first-child, #processedTourTable thead th:first-child { border-radius: 8px 0 0 0; }
        #pendingTourTable thead th:last-child,  #processedTourTable thead th:last-child  { border-radius: 0 8px 0 0; }

        #pendingTourTable tbody td, #processedTourTable tbody td {
            padding: 10px 14px; border-bottom: 1px solid var(--border);
            vertical-align: middle; white-space: normal; word-break: break-word;
        }

        #pendingTourTable  tbody tr:hover td,
        #processedTourTable tbody tr:hover td { background: var(--hover-row) !important; }

        /* cancelled row tint */
        #processedTourTable tbody tr.cancelled-row td { background: #fffbf0 !important; }
        #processedTourTable tbody tr.cancelled-row:hover td { background: #fff3cd !important; }

        /* ── Buttons ── */
        .btn-approve, .btn-reject, .btn-cancel-tour {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 5px 12px; border: none; border-radius: var(--radius);
            font-size: .775rem; font-weight: 600; cursor: pointer;
            transition: background .15s, transform .1s; white-space: nowrap;
        }
        .btn-approve     { background: var(--green);  color: #fff; box-shadow: 0 1px 4px rgba(30,126,68,.3); }
        .btn-approve:hover     { background: var(--green-h);  transform: translateY(-1px); }
        .btn-reject      { background: var(--red);    color: #fff; box-shadow: 0 1px 4px rgba(192,57,43,.3); }
        .btn-reject:hover      { background: var(--red-h);    transform: translateY(-1px); }
        .btn-cancel-tour { background: #b45309; color: #fff; box-shadow: 0 1px 4px rgba(180,83,9,.3); }
        .btn-cancel-tour:hover { background: #92400e; transform: translateY(-1px); }

        .action-cell { display: flex; gap: 5px; flex-wrap: wrap; align-items: center; }

        /* ── Badges ── */
        .badge { display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: .73rem; font-weight: 700; }
        .badge-success   { background: #d1fae5; color: #065f46; }
        .badge-danger    { background: #fee2e2; color: #991b1b; }
        .badge-secondary { background: #f3f4f6; color: #374151; }
        .badge-warning   { background: #fef3c7; color: #92400e; }
        .badge-cancelled { background: #fff3cd; color: #856404; border: 1px solid #ffc107; }

        /* ── DataTables ── */
        .dataTables_wrapper .dataTables_filter input,
        .dataTables_wrapper .dataTables_length select {
            border: 1px solid var(--border); border-radius: var(--radius); padding: 4px 9px; font-size: .8rem; outline: none;
        }
        .dataTables_wrapper .dataTables_filter input:focus { border-color: var(--brand); }
        .dataTables_wrapper .dataTables_info { font-size: .78rem; color: var(--muted); }
        .dataTables_paginate .paginate_button { border-radius: var(--radius) !important; font-size: .78rem !important; }
        .dataTables_paginate .paginate_button.current, .dataTables_paginate .paginate_button.current:hover {
            background: var(--brand) !important; color: #fff !important; border-color: var(--brand) !important;
        }
        .dataTables_paginate .paginate_button:hover:not(.current) {
            background: var(--brand-bg) !important; color: var(--brand) !important; border-color: var(--border) !important;
        }
        td.control { cursor: pointer; }
        table.dataTable.dtr-inline.collapsed > tbody > tr > td.control:before,
        table.dataTable.dtr-inline.collapsed > tbody > tr > th.control:before {
            background-color: var(--brand) !important; color: #fff !important;
            border-radius: 50%; width: 20px; height: 20px; line-height: 20px;
            text-align: center; font-weight: bold; box-shadow: 0 0 3px #444; border: 2px solid white;
        }

        /* ── Modals ── */
        .modal-content { border: none; border-radius: 12px; box-shadow: 0 8px 30px rgba(0,0,0,.15); }
        .modal-header  { background: var(--brand); color: #fff; border-radius: 12px 12px 0 0; }
        .modal-title   { font-size: .95rem; font-weight: 700; }
        .modal-header .close { color: #fff; opacity: .85; }
        .modal-header .close:hover { opacity: 1; }
        .modal-footer .btn-danger    { background: var(--red);    border: none; border-radius: var(--radius); font-weight: 600; font-size: .85rem; padding: 6px 18px; }
        .modal-footer .btn-danger:hover    { background: var(--red-h); }
        .modal-footer .btn-warning   { background: #b45309; color:#fff; border: none; border-radius: var(--radius); font-weight: 600; font-size: .85rem; padding: 6px 18px; }
        .modal-footer .btn-warning:hover   { background: #92400e; }
        .modal-footer .btn-secondary { border-radius: var(--radius); font-size: .85rem; padding: 6px 16px; }
        .form-control:focus { border-color: var(--brand); box-shadow: 0 0 0 .15rem rgba(16,61,158,.15); }

        #cancelTourModal .modal-header { background: #b45309; }

        @media (max-width: 768px) {
            .content-wrapper { padding: 10px !important; }
            .card-body { padding: 12px 13px; }
            .action-cell { flex-direction: column; }
            .action-cell form, .action-cell button { width: 100%; }
        }
    </style>
</head>
<body>
<div class="container-scroller">
    <?php include('../partials/_navbar.php'); ?>
    <div class="container-fluid page-body-wrapper">
        <?php include('../partials/_sidebar.php'); include('../partials/_settings-panel.php'); ?>
        <div class="main-panel">
            <div class="content-wrapper">

                <!-- ── HEADER CARD ── -->
                <div class="card mb-4 border-0 shadow-sm" style="border-radius: 12px; background: #ffffff;">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div>
                                <h4 class="font-weight-bold mb-1" style="color: #103d9e;">
                                    <i class="fa-solid fa-plane-departure me-2"></i>Tour Applications & Approval Analytics
                                </h4>
                                <p class="text-muted font-13 mb-0">Review, verify, approve, reject, or cancel employee tour applications with real-time statistics.</p>
                            </div>
                            <?php if (isset($_GET['back'])) { ?>
                                <a href="srEmpDashboard.php" class="btn btn-sm btn-outline-primary" style="border-radius: 6px;">
                                    <i class="fa-solid fa-arrow-left me-1"></i> Back to Dashboard
                                </a>
                            <?php } ?>
                        </div>
                    </div>
                </div>

                <!-- 📊 ANALYTICS STAT CARDS -->
                <div class="row mb-4 g-3">
                    <!-- Card 1: Total Tour Applications -->
                    <div class="col-xl-3 col-md-6">
                        <a href="tour_update.php" class="text-decoration-none">
                            <div class="stat-card-box p-3">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="text-uppercase text-muted font-11 font-weight-bold">Total Tour Requests</span>
                                    <div class="icon-circle bg-light text-primary">
                                        <i class="fa-solid fa-plane-departure"></i>
                                    </div>
                                </div>
                                <h3 class="font-weight-bold text-dark mb-1"><?= number_format($totalCount) ?></h3>
                                <div class="d-flex align-items-center font-12 text-muted gap-2">
                                    <span class="badge badge-light border text-dark font-11"><i class="fa-solid fa-clock text-warning me-1"></i>Pending: <?= $pendingCount ?></span>
                                    <span class="badge badge-light border text-dark font-11"><i class="fa-solid fa-check text-success me-1"></i>Approved: <?= $approvedCount ?></span>
                                </div>
                            </div>
                        </a>
                    </div>

                    <!-- Card 2: Pending Applications -->
                    <div class="col-xl-3 col-md-6">
                        <a href="tour_update.php?status=Pending" class="text-decoration-none">
                            <div class="stat-card-box p-3 <?= $filterStatus == 'Pending' ? 'active-filter' : '' ?>">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="text-uppercase text-warning font-11 font-weight-bold">Pending Action</span>
                                    <div class="icon-circle bg-warning-light text-warning" style="background:#fffbeb;">
                                        <i class="fa-solid fa-hourglass-half"></i>
                                    </div>
                                </div>
                                <div class="d-flex align-items-baseline gap-2 mb-1">
                                    <h3 class="font-weight-bold text-dark mb-0"><?= number_format($pendingCount) ?></h3>
                                    <?php if ($pendingCount > 0): ?>
                                        <span class="badge badge-warning text-dark font-11">Action Required</span>
                                    <?php endif; ?>
                                </div>
                                <p class="font-12 text-muted mb-0">
                                    <strong><?= $pendingPct ?>%</strong> of total awaiting approval
                                </p>
                            </div>
                        </a>
                    </div>

                    <!-- Card 3: Approved Tours -->
                    <div class="col-xl-3 col-md-6">
                        <a href="tour_update.php?status=Approved" class="text-decoration-none">
                            <div class="stat-card-box p-3 <?= $filterStatus == 'Approved' ? 'active-filter' : '' ?>">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="text-uppercase text-success font-11 font-weight-bold">Approved Tours</span>
                                    <div class="icon-circle bg-success-light text-success" style="background:#ecfdf5;">
                                        <i class="fa-solid fa-circle-check"></i>
                                    </div>
                                </div>
                                <div class="d-flex align-items-baseline gap-2 mb-1">
                                    <h3 class="font-weight-bold text-dark mb-0"><?= number_format($approvedCount) ?></h3>
                                    <span class="badge badge-success font-11"><?= $approvedPct ?>% Rate</span>
                                </div>
                                <p class="font-12 text-muted mb-0">
                                    Active approved tour requests
                                </p>
                            </div>
                        </a>
                    </div>

                    <!-- Card 4: Rejected / Cancelled Tours -->
                    <div class="col-xl-3 col-md-6">
                        <a href="tour_update.php?status=Rejected" class="text-decoration-none">
                            <div class="stat-card-box p-3 <?= ($filterStatus == 'Rejected' || $filterStatus == 'Cancelled') ? 'active-filter' : '' ?>">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="text-uppercase text-danger font-11 font-weight-bold">Rejected / Cancelled</span>
                                    <div class="icon-circle bg-danger-light text-danger" style="background:#fff1f2;">
                                        <i class="fa-solid fa-circle-xmark"></i>
                                    </div>
                                </div>
                                <div class="d-flex align-items-baseline gap-2 mb-1">
                                    <h3 class="font-weight-bold text-dark mb-0"><?= number_format($rejectedCount + $cancelledCount) ?></h3>
                                    <span class="badge badge-danger font-11"><?= round($rejectedPct + $cancelledPct, 1) ?>%</span>
                                </div>
                                <p class="font-12 text-muted mb-0">
                                    Rejected: <strong><?= $rejectedCount ?></strong> | Cancelled: <strong><?= $cancelledCount ?></strong>
                                </p>
                            </div>
                        </a>
                    </div>
                </div>

                <!-- 📊 STATUS BREAKDOWN PROGRESS BAR -->
                <?php if ($totalCount > 0): ?>
                <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px; background: #ffffff;">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="font-weight-bold font-13 text-dark"><i class="fa-solid fa-chart-pie me-2 text-primary"></i>Tour Applications Status Ratio</span>
                            <span class="font-12 text-muted">Total: <strong><?= $totalCount ?></strong> Applications</span>
                        </div>
                        <div class="progress" style="height: 10px; border-radius: 6px; background: #e2e8f0;">
                            <div class="progress-bar bg-success" role="progressbar" style="width: <?= $approvedPct ?>%" title="Approved: <?= $approvedCount ?> (<?= $approvedPct ?>%)"></div>
                            <div class="progress-bar bg-warning" role="progressbar" style="width: <?= $pendingPct ?>%" title="Pending: <?= $pendingCount ?> (<?= $pendingPct ?>%)"></div>
                            <div class="progress-bar bg-danger" role="progressbar" style="width: <?= $rejectedPct ?>%" title="Rejected: <?= $rejectedCount ?> (<?= $rejectedPct ?>%)"></div>
                            <div class="progress-bar" role="progressbar" style="width: <?= $cancelledPct ?>%; background:#b45309;" title="Cancelled: <?= $cancelledCount ?> (<?= $cancelledPct ?>%)"></div>
                        </div>
                        <div class="d-flex justify-content-between font-12 text-muted mt-2 flex-wrap gap-2">
                            <span><i class="fa-solid fa-circle text-success me-1 font-11"></i> Approved: <strong><?= $approvedCount ?></strong> (<?= $approvedPct ?>%)</span>
                            <span><i class="fa-solid fa-circle text-warning me-1 font-11"></i> Pending: <strong><?= $pendingCount ?></strong> (<?= $pendingPct ?>%)</span>
                            <span><i class="fa-solid fa-circle text-danger me-1 font-11"></i> Rejected: <strong><?= $rejectedCount ?></strong> (<?= $rejectedPct ?>%)</span>
                            <span><i class="fa-solid fa-circle me-1 font-11" style="color:#b45309;"></i> Cancelled: <strong><?= $cancelledCount ?></strong> (<?= $cancelledPct ?>%)</span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- 🔍 FILTER BAR CARD -->
                <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px; background: #ffffff;">
                    <div class="card-body p-3">
                        <form method="GET" class="m-0">
                            <div class="row align-items-end g-3">
                                <div class="col-md-6">
                                    <label class="form-label font-weight-bold text-dark font-12 mb-1">
                                        <i class="fa-solid fa-filter text-muted me-1"></i> Filter by Tour Status
                                    </label>
                                    <select name="status" class="form-control form-control-sm" style="border-radius: 6px; height: 38px;">
                                        <option value="">-- All Tour Applications --</option>
                                        <option value="Pending" <?= ($filterStatus == 'Pending' ? 'selected' : '') ?>>Pending</option>
                                        <option value="Approved" <?= ($filterStatus == 'Approved' ? 'selected' : '') ?>>Approved</option>
                                        <option value="Rejected" <?= ($filterStatus == 'Rejected' ? 'selected' : '') ?>>Rejected</option>
                                        <option value="Cancelled" <?= ($filterStatus == 'Cancelled' ? 'selected' : '') ?>>Cancelled</option>
                                    </select>
                                </div>
                                <div class="col-md-6 d-flex gap-2">
                                    <button type="submit" class="btn btn-primary btn-sm px-4 flex-grow-1" style="background: #103d9e; border: none; height: 38px; border-radius: 6px;">
                                        <i class="fa-solid fa-filter me-1"></i> Apply Filter
                                    </button>
                                    <a href="tour_update.php" class="btn btn-light btn-sm border px-3" style="height: 38px; border-radius: 6px; display: inline-flex; align-items: center; justify-content: center;" title="Reset Filter">
                                        <i class="fa-solid fa-rotate-right me-1"></i> Reset
                                    </a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- ══ Pending Tours (If Any or Filtered) ══ -->
                <?php if (empty($filterStatus) || $filterStatus === 'Pending'): ?>
                <?php
                $pendingQuery = mysqli_query($con, "SELECT t.*, s.fullName AS employeeName, p.projectName
                    FROM tour t 
                    JOIN signup s ON s.userId=t.userId 
                    JOIN projectMaster p ON p.projectId=t.projectId
                    WHERE t.status='Pending' ORDER BY t.dateTime DESC");
                $pendingNumRows = mysqli_num_rows($pendingQuery);
                ?>
                <?php if ($pendingNumRows > 0): ?>
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="section-title">
                                    <h4 class="card-title mb-0"><i class="fa-solid fa-hourglass-half me-2 text-warning"></i>Pending Tour Applications</h4>
                                </div>
                                <p style="font-size:.8rem;color:var(--muted);margin-bottom:14px;">Applications awaiting your approval</p>
                                <div class="table-responsive">
                                    <table id="pendingTourTable" class="table table-hover dt-responsive w-100">
                                        <thead>
                                            <tr>
                                                <th style="width: 20px;"></th>
                                                <th style="width: 30px;">#</th>
                                                <th>Employee</th>
                                                <th>Project</th>
                                                <th>From</th>
                                                <th>To</th>
                                                <th>Reason</th>
                                                <th>Applied On</th>
                                                <th class="text-center">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php
                                        $cnt = 1;
                                        while ($row = mysqli_fetch_assoc($pendingQuery)):
                                        ?>
                                            <tr>
                                                <td></td>
                                                <td class="font-13 text-muted"><?= $cnt++ ?></td>
                                                <td><strong class="text-dark"><?= htmlspecialchars($row['employeeName']) ?></strong></td>
                                                <td><span class="badge badge-light border text-dark"><?= htmlspecialchars($row['projectName']) ?></span></td>
                                                <td class="font-13"><?= date("d-m-Y", strtotime($row['fromDate'])) ?></td>
                                                <td class="font-13"><?= date("d-m-Y", strtotime($row['toDate'])) ?></td>
                                                <td class="font-13"><?= htmlspecialchars($row['reason']) ?></td>
                                                <td class="font-13"><?= date("d-m-Y H:i", strtotime($row['dateTime'])) ?></td>
                                                <td class="text-center">
                                                    <div class="action-cell justify-content-center">
                                                        <form method="POST" style="display:inline;">
                                                            <input type="hidden" name="tour_id" value="<?= $row['id'] ?>">
                                                            <input type="hidden" name="action"  value="Approve">
                                                            <button type="submit" class="btn btn-approve btn-sm"><i class="fa-solid fa-check me-1"></i> Approve</button>
                                                        </form>
                                                        <button class="btn btn-reject btn-sm" onclick="openRejectModal(<?= $row['id'] ?>)"><i class="fa-solid fa-xmark me-1"></i> Reject</button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <?php endif; ?>

                <!-- ══ Processed Tours ══ -->
                <?php if (empty($filterStatus) || $filterStatus !== 'Pending'): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="section-title">
                                    <h4 class="card-title mb-0"><i class="fa-solid fa-list-check me-2 text-primary"></i>Processed Tour Applications</h4>
                                </div>
                                <p style="font-size:.8rem;color:var(--muted);margin-bottom:14px;">Review, reject, or cancel processed tour applications</p>
                                <div class="table-responsive">
                                    <table id="processedTourTable" class="table table-hover dt-responsive w-100">
                                        <thead>
                                            <tr>
                                                <th style="width: 20px;"></th>
                                                <th style="width: 30px;">#</th>
                                                <th>Employee</th>
                                                <th>Project</th>
                                                <th>From</th>
                                                <th>To</th>
                                                <th>Reason</th>
                                                <th>Applied On</th>
                                                <th style="display:none;">Raw Date</th>
                                                <th>Status</th>
                                                <th>Rejection / Cancel Reason</th>
                                                <?php if ($_SESSION['userRole'] == 'admin'): ?><th>Approved By</th><?php endif; ?>
                                                <th class="text-center">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php
                                        $whereClause = "WHERE t.status IN ('Approved','Rejected','Cancelled')";
                                        if (!empty($filterStatus) && $filterStatus !== 'Pending') {
                                            $escFilter = mysqli_real_escape_string($con, $filterStatus);
                                            $whereClause = "WHERE t.status = '$escFilter'";
                                        }

                                        $res = mysqli_query($con, "SELECT t.*, s.fullName AS employeeName, p.projectName, ap.fullName AS approvedByName
                                            FROM tour t 
                                            JOIN signup s ON s.userId=t.userId 
                                            JOIN projectMaster p ON p.projectId=t.projectId
                                            LEFT JOIN signup ap ON ap.userId=t.approvedBy
                                            $whereClause ORDER BY t.dateTime DESC");
                                        $cnt = 1;
                                        while ($row = mysqli_fetch_assoc($res)):
                                            $s  = $row['status'];
                                            $sc = match($s) {
                                                'Approved'  => 'badge-success',
                                                'Rejected'  => 'badge-danger',
                                                'Cancelled' => 'badge-cancelled',
                                                default     => 'badge-warning'
                                            };
                                            $si = match($s) {
                                                'Approved'  => '<i class="fa-solid fa-circle-check me-1"></i>',
                                                'Rejected'  => '<i class="fa-solid fa-circle-xmark me-1"></i>',
                                                'Cancelled' => '<i class="fa-solid fa-ban me-1"></i>',
                                                default     => ''
                                            };
                                            $rowClass = ($s === 'Cancelled') ? ' class="cancelled-row"' : '';
                                        ?>
                                            <tr<?= $rowClass ?>>
                                                <td></td>
                                                <td class="font-13 text-muted"><?= $cnt++ ?></td>
                                                <td><strong class="text-dark"><?= htmlspecialchars($row['employeeName']) ?></strong></td>
                                                <td><span class="badge badge-light border text-dark"><?= htmlspecialchars($row['projectName']) ?></span></td>
                                                <td class="font-13"><?= date("d-m-Y", strtotime($row['fromDate'])) ?></td>
                                                <td class="font-13"><?= date("d-m-Y", strtotime($row['toDate'])) ?></td>
                                                <td class="font-13"><?= htmlspecialchars($row['reason']) ?></td>
                                                <td class="font-13"><?= date("d-m-Y H:i", strtotime($row['dateTime'])) ?></td>
                                                <td style="display:none;"><?= $row['dateTime'] ?></td>
                                                <td><span class="badge <?= $sc ?>"><?= $si . $s ?></span></td>
                                                <td class="font-13">
                                                    <?php if ($s === 'Cancelled' && !empty($row['cancelReason'])): ?>
                                                        <?= htmlspecialchars($row['cancelReason']) ?>
                                                    <?php elseif ($s === 'Rejected' && !empty($row['rejectionReason'])): ?>
                                                        <?= htmlspecialchars($row['rejectionReason']) ?>
                                                    <?php else: ?>
                                                        <span class="text-muted" style="font-style:italic;font-size:.8rem;">&mdash;</span>
                                                    <?php endif; ?>
                                                </td>
                                                <?php if ($_SESSION['userRole'] == 'admin'): ?>
                                                    <td class="font-13"><?= $row['approvedByName'] ? htmlspecialchars($row['approvedByName']) : '<span class="text-muted" style="font-style:italic;font-size:.8rem;">&mdash;</span>' ?></td>
                                                <?php endif; ?>
                                                <td class="text-center">
                                                    <?php if ($s !== 'Cancelled'): ?>
                                                        <button
                                                            class="btn btn-cancel-tour btn-sm"
                                                            onclick="openCancelModal(<?= $row['id'] ?>, '<?= htmlspecialchars($row['employeeName'], ENT_QUOTES) ?>', '<?= date('d-m-Y', strtotime($row['fromDate'])) ?>', '<?= date('d-m-Y', strtotime($row['toDate'])) ?>')">
                                                            <i class="fa-solid fa-ban me-1"></i> Cancel Tour
                                                        </button>
                                                    <?php else: ?>
                                                        <span class="text-muted font-12"><i class="fa-solid fa-minus"></i></span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- ══ Reject Modal ══ -->
                <div class="modal fade" id="rejectModal" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog">
                        <form method="POST" class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title"><i class="fa-solid fa-user-xmark me-2"></i>Reject Tour Application</h5>
                                <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
                            </div>
                            <div class="modal-body">
                                <input type="hidden" name="tour_id" id="reject_tour_id">
                                <input type="hidden" name="action" value="Reject">
                                <div class="form-group">
                                    <label class="font-weight-bold font-13">Rejection Reason <span class="text-danger">*</span></label>
                                    <textarea name="rejection_reason" class="form-control" rows="4" placeholder="Enter rejection reason..." required style="border-radius: 6px;"></textarea>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="submit" class="btn btn-danger"><i class="fa-solid fa-xmark me-1"></i> Reject</button>
                                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- ══ Cancel Tour Modal ══ -->
                <div class="modal fade" id="cancelTourModal" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog">
                        <form method="POST" class="modal-content" id="cancelTourForm">
                            <div class="modal-header">
                                <h5 class="modal-title"><i class="fa-solid fa-ban me-2"></i>Cancel Tour</h5>
                                <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
                            </div>
                            <div class="modal-body">
                                <input type="hidden" name="tour_id"  id="cancel_tour_id">
                                <input type="hidden" name="action"   value="Cancel">

                                <div style="background:#fff8f0;border:1px solid #fde68a;border-radius:8px;padding:12px 14px;margin-bottom:16px;">
                                    <div style="font-size:.78rem;color:#92400e;font-weight:700;margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px;">Tour Summary</div>
                                    <div style="font-size:.85rem;color:#374151;">
                                        <strong>Employee:</strong> <span id="cancel_emp_name"></span><br>
                                        <strong>Period:</strong> <span id="cancel_from_date"></span> &rarr; <span id="cancel_to_date"></span>
                                    </div>
                                </div>

                                <div class="form-group mb-0">
                                    <label class="font-weight-bold font-13 text-dark">
                                        Cancellation Reason <span class="text-danger">*</span>
                                    </label>
                                    <textarea
                                        name="cancel_reason"
                                        id="cancel_reason_input"
                                        class="form-control mt-1"
                                        rows="4"
                                        placeholder="Please provide a reason for cancelling this tour..."
                                        required
                                        minlength="10" style="border-radius: 6px;"></textarea>
                                    <div style="font-size:.75rem;color:var(--muted);margin-top:4px;">Minimum 10 characters required.</div>
                                    <div id="cancel_reason_error" style="display:none;color:#c0392b;font-size:.78rem;margin-top:4px;font-weight:600;">
                                        <i class="fa-solid fa-circle-exclamation me-1"></i> Please enter a valid cancellation reason (at least 10 characters).
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-warning text-white" onclick="submitCancelTour()"><i class="fa-solid fa-ban me-1"></i> Confirm Cancel</button>
                                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                            </div>
                        </form>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="../vendors/js/vendor.bundle.base.js"></script>
<script src="../js/off-canvas.js"></script>
<script src="../js/hoverable-collapse.js"></script>
<script src="../js/template.js"></script>
<script src="../js/settings.js"></script>
<script src="../js/todolist.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.4.1/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.4.1/js/responsive.bootstrap5.min.js"></script>

<script>
$(document).ready(function () {
    if ($('#pendingTourTable').length) {
        var pt = $('#pendingTourTable').DataTable({
            responsive: { details: { display: $.fn.dataTable.Responsive.display.childRow, type: 'column', target: 0 } },
            columnDefs: [
                { className: 'control', orderable: false, targets: 0 },
                { orderable: false, targets: 8 }
            ],
            order: [[7,'desc']], pageLength: 10,
            lengthMenu: [[10,25,50,-1],[10,25,50,"All"]],
            language: { emptyTable: "No pending tour applications" }
        });
    }

    if ($('#processedTourTable').length) {
        var prt = $('#processedTourTable').DataTable({
            responsive: { details: { display: $.fn.dataTable.Responsive.display.childRow, type: 'column', target: 0 } },
            columnDefs: [
                { className: 'control', orderable: false, targets: 0 },
                { targets: 8, visible: false, searchable: false }
            ],
            order: [[8,'desc']], pageLength: 10,
            language: { emptyTable: "No processed tour applications" }
        });
    }

    $(window).on('resize', function () {
        if (typeof pt !== 'undefined') pt.columns.adjust().responsive.recalc();
        if (typeof prt !== 'undefined') prt.columns.adjust().responsive.recalc();
    });

    $('#cancel_reason_input').on('input', function() {
        if ($(this).val().trim().length >= 10) {
            $('#cancel_reason_error').hide();
            $(this).removeClass('is-invalid');
        }
    });

    $('#cancelTourModal').on('hidden.bs.modal', function() {
        $('#cancel_reason_input').val('').removeClass('is-invalid');
        $('#cancel_reason_error').hide();
    });
});

function openRejectModal(tourId) {
    $('#reject_tour_id').val(tourId);
    $('#rejectModal').modal('show');
}

function openCancelModal(tourId, empName, fromDate, toDate) {
    $('#cancel_tour_id').val(tourId);
    $('#cancel_emp_name').text(empName);
    $('#cancel_from_date').text(fromDate);
    $('#cancel_to_date').text(toDate);
    $('#cancel_reason_input').val('').removeClass('is-invalid');
    $('#cancel_reason_error').hide();
    $('#cancelTourModal').modal('show');
}

function submitCancelTour() {
    var reason = $('#cancel_reason_input').val().trim();

    if (reason.length < 10) {
        $('#cancel_reason_input').addClass('is-invalid');
        $('#cancel_reason_error').show();
        $('#cancel_reason_input').focus();
        return;
    }

    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Cancel this tour?',
            text: 'This action will mark the tour as Cancelled and cannot be undone.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#b45309',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, Cancel Tour',
            cancelButtonText: 'Go Back'
        }).then(function(result) {
            if (result.isConfirmed) {
                $('#cancelTourForm').submit();
            }
        });
    } else {
        if (confirm('Are you sure you want to cancel this tour?')) {
            $('#cancelTourForm').submit();
        }
    }
}
</script>

<?php
require_once('../include/sweetAlert.php');
if (isset($_SESSION['status'])) {
    if ($_SESSION['status'] != true) toastMsg('error', $_SESSION['msg']); else toastMsg('success', $_SESSION['msg']);
    unset($_SESSION['status']); unset($_SESSION['msg']);
}
?>
</body>
</html>