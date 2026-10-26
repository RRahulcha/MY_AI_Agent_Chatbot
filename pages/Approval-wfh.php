<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}
if (in_array($_SESSION['userRole'], ['junior_intern', 'senior_intern']) || in_array($_SESSION['designation'] ?? '', ['juniorIntern', 'seniorIntern'])) {
    echo "<script>
        alert('You cannot open this page. Please log in with the correct role.');
        window.location.href = 'login.php';
    </script>";
    exit();
}

include('../include/db_config.php');
include('auto-update-wfh-status.php');

$userIdString   = mysqli_real_escape_string($con, $_SESSION['userId']);
$approverUserId = $userIdString;
$userRole       = $_SESSION['userRole'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $wfhId  = mysqli_real_escape_string($con, $_POST['wfh_id']);
    $action = ($_POST['action'] === 'approve') ? 'Approved' : 'Rejected';
    $reason = isset($_POST['reason']) ? mysqli_real_escape_string($con, $_POST['reason']) : NULL;
    $approverId = $approverUserId;

    $updateStatusQuery = "
        UPDATE wfhRequests
        SET status = '$action',
            approvedBy = '$approverId',
            updatedDate = NOW(),
            rejectionReason = " . ($action === 'Rejected' ? "'$reason'" : "NULL") . "
        WHERE wfh_id = '$wfhId'
    ";

    $wfhQuery    = "SELECT userid, wfhDate FROM wfhRequests WHERE wfh_id = '$wfhId'";
    $wfhQueryRes = mysqli_query($con, $wfhQuery);
    $wfhData     = mysqli_fetch_assoc($wfhQueryRes);
    $targetUserId = $wfhData['userid'];

    if (mysqli_query($con, $updateStatusQuery)) {
        $currentDate = date('Y-m-d');
        
        // Dynamically check columns to support older database schemas
        $columnsResult = mysqli_query($con, "SHOW COLUMNS FROM wfhRequests");
        $existingColumns = [];
        if ($columnsResult) {
            while ($colRow = mysqli_fetch_assoc($columnsResult)) {
                $existingColumns[] = strtolower($colRow['Field']);
            }
        }
        $hasFromDate = in_array('fromdate', $existingColumns);
        $hasToDate = in_array('todate', $existingColumns);
        $hasWfhDate = in_array('wfhdate', $existingColumns);

        $dateSelect = "wfhDate AS fromDate, wfhDate AS toDate";
        if ($hasFromDate && $hasToDate) {
            $dateSelect = $hasWfhDate ? "COALESCE(fromDate, wfhDate) AS fromDate, COALESCE(toDate, wfhDate) AS toDate" : "fromDate, toDate";
        } elseif ($hasWfhDate) {
            $dateSelect = "wfhDate AS fromDate, wfhDate AS toDate";
        }

        $getWFHDate  = mysqli_query($con, "SELECT $dateSelect FROM wfhRequests WHERE wfh_id='$wfhId'");
        if ($getWFHDate && mysqli_num_rows($getWFHDate) > 0) {
            $wfhDateData = mysqli_fetch_assoc($getWFHDate);
            $fromDate    = $wfhDateData['fromDate'];
            $toDate      = $wfhDateData['toDate'];
            if ($currentDate >= $fromDate && $currentDate <= $toDate) {
                mysqli_query($con, "UPDATE signup SET type='Online' WHERE userId='$targetUserId' AND type='Offline'");
            }
        }
    }

    include_once('../include/notifications.php');

    if ($wfhQueryRes && mysqli_num_rows($wfhQueryRes) > 0) {
        $fromDateVal = $wfhData['wfhDate'];
        $toDateVal   = $wfhData['wfhDate'];
        $userResult  = mysqli_query($con, "SELECT token, fullName FROM signup WHERE userId = '$targetUserId' LIMIT 1");
        if ($userResult && mysqli_num_rows($userResult) > 0) {
            $userData = mysqli_fetch_assoc($userResult);
            if (!empty($userData['token'])) {
                $fcmToken   = $userData['token'];
                $notifTitle = "WFH Request Update";
                $statusText = ($action === 'Approved') ? "approved" : "rejected";
                $fromDate   = date('d-m-Y', strtotime($fromDateVal));
                $toDate     = date('d-m-Y', strtotime($toDateVal));
                if ($action == 'rejected') $statusText .= " with reason: $reason";
                $notifBody  = "Work From Home request for date $fromDate - $toDate has been $statusText.";
                if (isset($accessToken) && isset($projectId)) {
                    $response = sendFCMNotification($fcmToken, $notifTitle, $notifBody, $accessToken, $projectId);
                    if ($response) {
                        $sp   = 'sent';
                        $dt   = date('Y-m-d H:i:s');
                        $stmt = $con->prepare("INSERT INTO notifications (userId, title, message, status, is_read, created_at) VALUES (?, ?, ?, ?, 1, ?)");
                        if ($stmt) { $stmt->bind_param('sssss', $targetUserId, $notifTitle, $notifBody, $sp, $dt); $stmt->execute(); $stmt->close(); }
                    }
                }
            }
        }
    }

    $_SESSION['status'] = true;
    $_SESSION['msg']    = "WFH request has been $action successfully.";
    echo "<script>window.location.href = 'Approval-wfh.php';</script>";
    exit;
}

$today = date('Y-m-d');
$fiveDaysAgo = date('Y-m-d', strtotime('-5 days'));
$currentUserIdStr = $_SESSION['userId'];

// ── KPI Analytics (scoped by role) ──────────────────────────────────
$kpiWhere = ($userRole === 'admin' || $userRole === 'hr') ? "1=1" : "(approvedBy = '$currentUserIdStr' OR userid = '$currentUserIdStr')";
$kpiQ = mysqli_query($con, "
    SELECT
        COUNT(*) AS totalWfh,
        SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) AS approvedWfh,
        SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) AS rejectedWfh,
        SUM(CASE WHEN (status IS NULL OR status = '' OR status = 'Pending') AND wfhDate >= '$today' THEN 1 ELSE 0 END) AS pendingWfh,
        SUM(CASE WHEN status = 'Approved' THEN (DATEDIFF(COALESCE(toDate, wfhDate, fromDate), COALESCE(fromDate, wfhDate, toDate)) + 1) ELSE 0 END) AS totalGrantedDays
    FROM wfhRequests
    WHERE $kpiWhere
");
$kpi = $kpiQ ? mysqli_fetch_assoc($kpiQ) : [];
$totalWfh        = (int)($kpi['totalWfh']        ?? 0);
$approvedWfh     = (int)($kpi['approvedWfh']     ?? 0);
$rejectedWfh     = (int)($kpi['rejectedWfh']     ?? 0);
$pendingWfh      = (int)($kpi['pendingWfh']      ?? 0);
$totalGrantedDays = (int)($kpi['totalGrantedDays'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>WFH Approval</title>
    <?php include('../partials/_links.php'); ?>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <script src="https://code.jquery.com/jquery-3.5.1.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.4.1/css/responsive.bootstrap5.min.css">
    <script src="https://cdn.datatables.net/responsive/2.4.1/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.4.1/js/responsive.bootstrap5.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.17.0/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.4.0/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.23/jspdf.plugin.autotable.min.js"></script>

    <style>
        :root {
            --brand:      #103d9e;
            --brand-dark: #0c2f7a;
            --brand-bg:   #eef2fc;
            --green:      #1e7e44;
            --green-h:    #165e33;
            --red:        #c0392b;
            --red-h:      #962d22;
            --teal:       #138496;
            --teal-h:     #0f6674;
            --border:     #dee2ec;
            --hover-row:  #f0f4fd;
            --muted:      #6c757d;
            --radius:     6px;
        }

        .page-body-wrapper, .content-wrapper, .main-panel { overflow-x: hidden !important; }
        .content-wrapper { padding: 24px !important; background: #f4f6fb !important; }

        /* KPI Cards */
        .kpi-card {
            background: #fff; border-radius: 12px; border: 1px solid #e2e8f0;
            padding: 18px 20px; transition: transform 0.2s, box-shadow 0.2s;
        }
        .kpi-card:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0,0,0,0.06); }
        .kpi-icon {
            width: 48px; height: 48px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.3rem; flex-shrink: 0;
        }
        .kpi-val { font-size: 1.6rem; font-weight: 800; color: #0f172a; line-height: 1.1; margin-top: 4px; }
        .kpi-lbl { font-size: 0.78rem; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.04em; }

        /* Custom Select Dropdown Styling */
        select.form-control, select.form-select {
            height: 38px !important;
            padding: 6px 32px 6px 12px !important;
            border-radius: 8px !important;
            border: 1px solid #cbd5e1 !important;
            background-color: #ffffff !important;
            color: #1e293b !important;
            font-size: 0.85rem !important;
            font-weight: 500 !important;
            appearance: none !important;
            -webkit-appearance: none !important;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23475569' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e") !important;
            background-repeat: no-repeat !important;
            background-position: right 10px center !important;
            background-size: 14px 10px !important;
            cursor: pointer;
        }

        .card { border: 1px solid var(--border); border-radius: 8px; box-shadow: 0 1px 6px rgba(16,61,158,.07); }
        .card-body { padding: 18px 20px; }
        .card-title { font-size: 1rem; font-weight: 700; color: var(--brand); margin-bottom: 2px; }
        .card-description { font-size: .8rem; color: var(--muted); margin-bottom: 14px; }

        .section-title { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; }
        .section-title::after { content: ''; flex: 1; height: 2px; background: linear-gradient(to right, var(--brand-bg), transparent); border-radius: 2px; }

        /* ── Tables ── */
        #wfhPendingTable, #processedWfhTable {
            width: 100% !important;
            font-size: .855rem;
            border-collapse: separate;
            border-spacing: 0;
        }
        #wfhPendingTable thead th, #processedWfhTable thead th {
            background: var(--brand); color: #fff;
            padding: 10px 13px; font-weight: 600; font-size: .775rem;
            text-transform: uppercase; letter-spacing: .045em;
            border: none; border-bottom: 2px solid var(--brand-dark); white-space: nowrap;
        }
        #wfhPendingTable thead th:first-child, #processedWfhTable thead th:first-child { border-radius: var(--radius) 0 0 0; }
        #wfhPendingTable thead th:last-child,  #processedWfhTable thead th:last-child  { border-radius: 0 var(--radius) 0 0; }

        #wfhPendingTable tbody td, #processedWfhTable tbody td {
            padding: 9px 13px; border-bottom: 1px solid var(--border);
            vertical-align: middle; white-space: normal; word-break: break-word;
        }
        #wfhPendingTable  tbody td:nth-child(1),
        #wfhPendingTable  tbody td:nth-child(2),
        #wfhPendingTable  tbody td:nth-child(3),
        #wfhPendingTable  tbody td:nth-child(5),
        #wfhPendingTable  tbody td:nth-child(6),
        #processedWfhTable tbody td:nth-child(1),
        #processedWfhTable tbody td:nth-child(2),
        #processedWfhTable tbody td:nth-child(3),
        #processedWfhTable tbody td:nth-child(4),
        #processedWfhTable tbody td:nth-child(5),
        #processedWfhTable tbody td:nth-child(7) { white-space: nowrap; }
        #wfhPendingTable  tbody td:nth-child(4),
        #processedWfhTable tbody td:nth-child(6) { max-width: 260px; }

        #wfhPendingTable  tbody tr:last-child td,
        #processedWfhTable tbody tr:last-child td { border-bottom: none; }
        #wfhPendingTable  tbody tr:nth-child(even) td,
        #processedWfhTable tbody tr:nth-child(even) td { background: #fafbff; }
        #wfhPendingTable  tbody tr:hover td,
        #processedWfhTable tbody tr:hover td { background: var(--hover-row) !important; }

        /* ── Date status badges ── */
        .wfh-badge {
            display: inline-block; padding: 2px 8px; border-radius: 20px;
            font-size: .7rem; font-weight: 700; vertical-align: middle;
            margin-left: 4px; white-space: nowrap;
        }
        .badge-upcoming { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
        .badge-today    { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
        .badge-expired  { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }

        /* ── Buttons ── */
        .btn-approve, .btn-reject, .btn-info {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 4px 12px; border: none; border-radius: var(--radius);
            font-size: .775rem; font-weight: 600; cursor: pointer;
            transition: background .15s, transform .1s; white-space: nowrap;
            position: relative; z-index: 10;
        }
        .btn-approve { background: var(--green); color: #fff; box-shadow: 0 1px 4px rgba(30,126,68,.3); }
        .btn-approve:hover { background: var(--green-h); transform: translateY(-1px); }
        .btn-reject  { background: var(--red);   color: #fff; box-shadow: 0 1px 4px rgba(192,57,43,.3); }
        .btn-reject:hover  { background: var(--red-h);   transform: translateY(-1px); }
        .btn-info    { background: var(--teal);  color: #fff; box-shadow: 0 1px 4px rgba(19,132,150,.25); }
        .btn-info:hover    { background: var(--teal-h);  transform: translateY(-1px); }

        .action-cell { display: flex; gap: 5px; flex-wrap: wrap; align-items: center; position: relative; z-index: 10; }

        .table-responsive { overflow: visible !important; }

        /* ── Status badges ── */
        .badge { display: inline-block; padding: 3px 9px; border-radius: 20px; font-size: .73rem; font-weight: 700; }
        .badge-approved { background: #d1fae5; color: #065f46; }
        .badge-rejected { background: #fee2e2; color: #991b1b; }
        .badge-pending  { background: #fef3c7; color: #92400e; }

        /* ── DataTables overrides ── */
        .dataTables_wrapper .dataTables_filter input,
        .dataTables_wrapper .dataTables_length select {
            border: 1px solid var(--border); border-radius: var(--radius);
            padding: 4px 9px; font-size: .8rem; outline: none;
        }
        .dataTables_wrapper .dataTables_filter input:focus { border-color: var(--brand); }
        .dataTables_wrapper .dataTables_info { font-size: .78rem; color: var(--muted); }
        .dataTables_paginate .paginate_button { border-radius: var(--radius) !important; font-size: .78rem !important; }
        .dataTables_paginate .paginate_button.current,
        .dataTables_paginate .paginate_button.current:hover {
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
            text-align: center; font-weight: bold; box-shadow: 0 0 3px #555;
        }

        /* ── Overlays ── */
        .image-preview-overlay, .pdf-preview-overlay {
            position: fixed; inset: 0; background: rgba(0,0,0,.82);
            display: flex; justify-content: center; align-items: center; z-index: 9999; cursor: pointer;
        }
        .image-preview-content { position: relative; max-width: 90%; max-height: 90%; background: transparent; cursor: default; }
        .pdf-preview-content {
            position: relative; width: 90%; height: 90%; background: #fff;
            border-radius: 8px; cursor: default; box-shadow: 0 8px 32px rgba(0,0,0,.4);
        }
        .image-preview-cancel-btn, .pdf-preview-cancel-btn {
            position: absolute; top: -38px; right: -8px;
            background: var(--red); color: #fff; border: none;
            border-radius: 50%; width: 32px; height: 32px; font-size: 16px;
            cursor: pointer; z-index: 10000; display: flex; align-items: center; justify-content: center;
            transition: background .2s;
        }
        .image-preview-cancel-btn:hover, .pdf-preview-cancel-btn:hover { background: var(--red-h); }
        .image-preview-img { max-width: 100%; max-height: 80vh; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,.5); display: block; }
        .pdf-preview-iframe { width: 100%; height: 100%; border: none; border-radius: 8px; }

        @media (max-width: 768px) {
            .content-wrapper { padding: 10px !important; }
            .card-body { padding: 12px 13px; }
            .pdf-preview-content { width: 95%; height: 85%; }
            .pdf-preview-cancel-btn { top: -34px; right: 0; width: 28px; height: 28px; font-size: 14px; }
            #processedWfhTable th:nth-child(4), #processedWfhTable td:nth-child(4),
            #processedWfhTable th:nth-child(6), #processedWfhTable td:nth-child(6),
            #processedWfhTable th:nth-child(7), #processedWfhTable td:nth-child(7),
            #processedWfhTable th:nth-child(8), #processedWfhTable td:nth-child(8),
            #processedWfhTable th:nth-child(9), #processedWfhTable td:nth-child(9) { display: none; }
            .dataTables_wrapper .dataTables_length,
            .dataTables_wrapper .dataTables_filter,
            .dataTables_wrapper .dataTables_info,
            .dataTables_wrapper .dataTables_paginate { text-align: center; margin: 8px 0; }
        }
        #wfhPendingTable_wrapper .row { align-items: center; }
        #wfhPendingTable_wrapper .dataTables_length,
        #wfhPendingTable_wrapper .dataTables_filter,
        #wfhPendingTable_wrapper .dataTables_info,
        #wfhPendingTable_wrapper .dataTables_paginate { float: none; }
    </style>
</head>
<body>
<div class="container-scroller">
    <?php include('../partials/_navbar.php') ?>
    <div class="container-fluid page-body-wrapper">
        <?php include('../partials/_sidebar.php'); include('../partials/_settings-panel.php'); ?>
        <div class="main-panel">
            <div class="content-wrapper">

                <!-- ── Page Title ── -->
                <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
                    <div>
                        <h3 class="fw-bold text-dark mb-1">
                            <i class="fa-solid fa-house-laptop text-primary me-2"></i> WFH Approval Centre
                        </h3>
                        <p class="text-muted small mb-0">Review, approve, and manage all Work From Home requests.</p>
                    </div>
                </div>

                <!-- ── KPI Analytics Cards ── -->
                <div class="row mb-4">
                    <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                        <div class="kpi-card d-flex align-items-center justify-content-between">
                            <div>
                                <div class="kpi-lbl">Pending Requests</div>
                                <div class="kpi-val" style="color:#b06000;"><?= $pendingWfh ?></div>
                                <small class="text-muted" style="font-size:0.72rem;">Awaiting review</small>
                            </div>
                            <div class="kpi-icon" style="background:#FEF7E0; color:#B06000;">
                                <i class="fa-solid fa-clock"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                        <div class="kpi-card d-flex align-items-center justify-content-between">
                            <div>
                                <div class="kpi-lbl">Approved WFH</div>
                                <div class="kpi-val text-success"><?= $approvedWfh ?></div>
                                <small class="text-muted" style="font-size:0.72rem;">Granted requests</small>
                            </div>
                            <div class="kpi-icon" style="background:#E6F4EA; color:#1E8E3E;">
                                <i class="fa-solid fa-circle-check"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                        <div class="kpi-card d-flex align-items-center justify-content-between">
                            <div>
                                <div class="kpi-lbl">Rejected</div>
                                <div class="kpi-val text-danger"><?= $rejectedWfh ?></div>
                                <small class="text-muted" style="font-size:0.72rem;">Declined requests</small>
                            </div>
                            <div class="kpi-icon" style="background:#FEE2E2; color:#991B1B;">
                                <i class="fa-solid fa-circle-xmark"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                        <div class="kpi-card d-flex align-items-center justify-content-between">
                            <div>
                                <div class="kpi-lbl">Total WFH Days Granted</div>
                                <div class="kpi-val text-info" style="color:#0284C7 !important;"><?= $totalGrantedDays ?> <span style="font-size:0.9rem;">days</span></div>
                                <small class="text-muted" style="font-size:0.72rem;">Cumulative approved remote days</small>
                            </div>
                            <div class="kpi-icon" style="background:#E0F2FE; color:#0284C7;">
                                <i class="fa-solid fa-house-user"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ═══════════════ TABLE 1: WFH Approval (last 5 days, today, upcoming) ═══════════════ -->
                <div class="row mb-3">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
                                    <div>
                                        <div class="section-title mb-0"><h4 class="card-title mb-0"><i class="fa-solid fa-hourglass-half text-warning me-2"></i>Pending WFH Requests</h4></div>
                                        <p class="card-description mb-0">Requests from the last 5 days, today, and upcoming dates awaiting your action.</p>
                                    </div>
                                </div>
                                <hr class="text-muted opacity-25 mb-3">
                                <div class="table-responsive">
                                    <table id="wfhPendingTable" class="table table-hover dt-responsive w-100">
                                        <thead>
                                            <tr>
                                                <th>Sr. No</th>
                                                <th>Applicant Name</th>
                                                <th>WFH Date</th>
                                                <th>Reason</th>
                                                <th>Document</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php
                                        /* Only show pending requests where wfhDate >= fiveDaysAgo */
                                        if ($userRole === 'admin' || $userRole === 'hr') {
                                            $pendingQuery = "
                                                SELECT w.wfh_id, w.wfhDate, w.reason, w.updatedDate, w.documentPath, w.userid, s.fullName
                                                FROM wfhRequests w
                                                LEFT JOIN signup s ON s.userId = w.userid
                                                WHERE (w.status IS NULL OR w.status = '' OR w.status = 'Pending')
                                                AND w.wfhDate >= '$fiveDaysAgo'
                                                ORDER BY w.wfhDate ASC";
                                        } else {
                                            $pendingQuery = "
                                                SELECT w.wfh_id, w.wfhDate, w.reason, w.updatedDate, w.documentPath, w.userid, s.fullName
                                                FROM wfhRequests w
                                                LEFT JOIN signup s ON s.userId = w.userid
                                                WHERE (w.status IS NULL OR w.status = '' OR w.status = 'Pending')
                                                AND w.wfhDate >= '$fiveDaysAgo'
                                                AND w.approvedBy = '$currentUserIdStr'
                                                ORDER BY w.wfhDate ASC";
                                        }
                                        $pendingResult = mysqli_query($con, $pendingQuery);

                                        $pendingSr = 1;
                                        if ($pendingResult && mysqli_num_rows($pendingResult) > 0) {
                                            while ($row = mysqli_fetch_assoc($pendingResult)) {
                                                $wfhDay   = date('Y-m-d', strtotime($row['wfhDate']));
                                                $wfhDisp  = date('d-m-Y', strtotime($row['wfhDate']));

                                                /* Date badge: today, upcoming, or past */
                                                if ($wfhDay === $today) {
                                                    $dateBadge = '<span class="wfh-badge badge-today">&#9679; Today</span>';
                                                } elseif ($wfhDay < $today) {
                                                    $dateBadge = '<span class="wfh-badge badge-expired">&#9679; Past</span>';
                                                } else {
                                                    $dateBadge = '<span class="wfh-badge badge-upcoming">&#9650; Upcoming</span>';
                                                }

                                                /* Applicant name */
                                                if (!empty($row['fullName'])) {
                                                    $applicantName = $row['fullName'];
                                                } else {
                                                    $uid = !empty($row['userid']) ? $row['userid'] : '';
                                                    $nr  = !empty($uid) ? mysqli_query($con, "SELECT fullName FROM signup WHERE userId = '$uid' LIMIT 1") : false;
                                                    $applicantName = ($nr && mysqli_num_rows($nr) > 0) ? mysqli_fetch_assoc($nr)['fullName'] : $uid;
                                                }

                                                /* Document button */
                                                $docBtn = '<span class="text-muted" style="font-style:italic;font-size:.8rem;">None</span>';
                                                if (!empty($row['documentPath']) && $row['documentPath'] != '-') {
                                                    $fn = basename($row['documentPath']);
                                                    $docBtn = "<button type='button' onclick=\"viewDocument('" . addslashes($fn) . "')\" class='btn btn-info btn-sm'>&#128196; View</button>";
                                                }

                                                $wid = $row['wfh_id'];
                                                echo "<tr>
                                                    <td>{$pendingSr}</td>
                                                    <td data-search='" . strtolower(trim($applicantName)) . "'>
                                                        <strong>" . htmlspecialchars($applicantName) . "</strong>
                                                    </td>
                                                    <td>{$wfhDisp} {$dateBadge}</td>
                                                    <td>" . nl2br(htmlspecialchars($row['reason'])) . "</td>
                                                    <td>{$docBtn}</td>
                                                    <td><div class='action-cell'>
                                                        <button type='button' onclick=\"confirmAction('{$wid}', 'approve')\" class='btn btn-approve btn-sm'>&#10003; Approve</button>
                                                        <button type='button' onclick=\"confirmAction('{$wid}', 'reject')\"  class='btn btn-reject  btn-sm'>&#10005; Reject</button>
                                                    </div></td>
                                                </tr>";
                                                $pendingSr++;
                                            }
                                        }
                                        ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ═══════════════ TABLE 2: Processed WFH (approved + rejected + expired) ═══════════════ -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
                                    <div class="d-flex align-items-center gap-3">
                                        <div>
                                            <div class="section-title mb-0"><h4 class="card-title mb-0"><i class="fa-solid fa-list-check text-primary me-2"></i>Processed WFH Requests</h4></div>
                                            <p class="card-description mb-0">Approved, rejected, and expired requests — full audit log.</p>
                                        </div>
                                        <!-- Status Filter -->
                                        <div class="d-flex align-items-center gap-2">
                                            <label for="statusFilter" class="text-muted fw-bold mb-0" style="font-size:0.78rem; white-space:nowrap;">Filter:</label>
                                            <select id="statusFilter" class="form-control">
                                                <option value="">All Statuses</option>
                                                <option value="Approved">Approved</option>
                                                <option value="Rejected">Rejected</option>
                                                <option value="Expired">Expired</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <button class="btn btn-success text-white btn-sm px-3 py-1" style="border-radius:6px;" onclick="exportWfhExcel()">
                                            <i class="fa-solid fa-file-excel me-1"></i> Excel
                                        </button>
                                        <button class="btn btn-danger text-white btn-sm px-3 py-1" style="border-radius:6px;" onclick="exportWfhPDF()">
                                            <i class="fa-solid fa-file-pdf me-1"></i> PDF
                                        </button>
                                    </div>
                                </div>
                                <hr class="text-muted opacity-25 mb-3">
                                <div class="table-responsive">
                                    <table id="processedWfhTable" class="table table-hover dt-responsive w-100">
                                        <thead>
                                            <tr>
                                                <th>Sr. No</th>
                                                <th>Applicant Name</th>
                                                <th>WFH Date</th>
                                                <th>Processed On</th>
                                                <th>Status</th>
                                                <th>Rejection Reason</th>
                                                <th>Document</th>
                                                <?php if ($_SESSION['userRole'] == 'admin'): ?><th>Approved By</th><?php endif; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php
                                        /*
                                         * Show:
                                         *   1. Requests with status Approved / Rejected  (all dates)
                                         *   2. Requests still Pending but wfhDate < today  → shown as "Expired"
                                         */
                                        if ($userRole == 'admin' || (isset($_SESSION['designation']) && $_SESSION['designation'] == 'hr')) {
                                            $processedQuery = "
                                                SELECT w.wfh_id, w.wfhDate, w.status, w.updatedDate,
                                                       w.rejectionReason, w.documentPath, w.userid, w.approvedBy, s.fullName
                                                FROM wfhRequests w
                                                LEFT JOIN signup s ON s.userId = w.userid
                                                WHERE w.status IN ('Approved','Rejected')
                                                   OR (
                                                        (w.status IS NULL OR w.status = '' OR w.status = 'Pending')
                                                        AND w.wfhDate < '$today'
                                                      )
                                                ORDER BY w.updatedDate DESC, w.wfhDate DESC";
                                        } else {
                                            $processedQuery = "
                                                SELECT w.wfh_id, w.wfhDate, w.status, w.updatedDate,
                                                       w.rejectionReason, w.documentPath, w.userid, w.approvedBy, s.fullName
                                                FROM wfhRequests w
                                                LEFT JOIN signup s ON s.userId = w.userid
                                                WHERE (
                                                        w.status IN ('Approved','Rejected')
                                                        OR (
                                                            (w.status IS NULL OR w.status = '' OR w.status = 'Pending')
                                                            AND w.wfhDate < '$today'
                                                          )
                                                      )
                                                AND (w.approvedBy = '$currentUserIdStr' OR w.userid = '$currentUserIdStr')
                                                ORDER BY w.updatedDate DESC, w.wfhDate DESC";
                                        }

                                        $pr = mysqli_query($con, $processedQuery);

                                        $srNo = 1;
                                        if ($pr && mysqli_num_rows($pr) > 0) {
                                            while ($row = mysqli_fetch_assoc($pr)) {
                                                $applicantName = !empty($row['fullName']) ? $row['fullName'] : $row['userid'];

                                                /* Determine display status — expired pending gets its own badge */
                                                $rawStatus = $row['status'];
                                                $isExpired = (empty($rawStatus) || $rawStatus === 'Pending')
                                                             && !empty($row['wfhDate'])
                                                             && date('Y-m-d', strtotime($row['wfhDate'])) < $today;

                                                if ($isExpired) {
                                                    $statusBadge = '<span class="wfh-badge badge-expired">&#9888; Expired</span>';
                                                } elseif ($rawStatus === 'Approved') {
                                                    $statusBadge = '<span class="badge badge-approved">&#10003; Approved</span>';
                                                } else {
                                                    $statusBadge = '<span class="badge badge-rejected">&#10005; Rejected</span>';
                                                }

                                                /* Rejection reason (only for Rejected, not Expired) */
                                                $reason = (!$isExpired && $rawStatus === 'Rejected' && !empty($row['rejectionReason']))
                                                    ? htmlspecialchars($row['rejectionReason'])
                                                    : '<span class="text-muted" style="font-style:italic;font-size:.8rem;">&mdash;</span>';

                                                $wfhDateDisp = !empty($row['wfhDate'])     ? date('d-m-Y',     strtotime($row['wfhDate']))     : '&mdash;';
                                                $updatedDate = !empty($row['updatedDate']) ? date('d-m-Y H:i', strtotime($row['updatedDate'])) : '&mdash;';

                                                /* Document button */
                                                $docBtn = '<span class="text-muted" style="font-style:italic;font-size:.8rem;">&mdash;</span>';
                                                if (!empty($row['documentPath']) && $row['documentPath'] != '-') {
                                                    $fn = basename($row['documentPath']);
                                                    $docBtn = "<button type='button' onclick=\"viewDocument('" . addslashes($fn) . "')\" class='btn btn-info btn-sm'>&#128196; View</button>";
                                                }

                                                echo "<tr>
                                                    <td>{$srNo}</td>
                                                    <td><strong>" . htmlspecialchars($applicantName) . "</strong></td>
                                                    <td>{$wfhDateDisp}</td>
                                                    <td>{$updatedDate}</td>
                                                    <td>{$statusBadge}</td>
                                                    <td>{$reason}</td>
                                                    <td>{$docBtn}</td>";

                                                if ($userRole == 'admin') {
                                                    $aid = isset($row['approvedBy']) ? $row['approvedBy'] : '';
                                                    $approverName = '<span class="text-muted" style="font-style:italic;font-size:.8rem;">&mdash;</span>';
                                                    if (!empty($aid)) {
                                                        $ar = mysqli_query($con, "SELECT fullName FROM signup WHERE userId = '$aid' LIMIT 1");
                                                        if ($ar && mysqli_num_rows($ar) > 0) $approverName = htmlspecialchars(mysqli_fetch_assoc($ar)['fullName']);
                                                    }
                                                    echo "<td>{$approverName}</td>";
                                                }
                                                echo "</tr>";
                                                $srNo++;
                                            }
                                        }
                                        ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<script src="../js/off-canvas.js"></script>
<script src="../js/hoverable-collapse.js"></script>
<script src="../js/template.js"></script>
<script src="../js/settings.js"></script>
<script src="../js/select2.js"></script>

<script>
$(document).ready(function () {
    if ($.fn.DataTable.isDataTable('#wfhPendingTable'))   $('#wfhPendingTable').DataTable().destroy();
    if ($.fn.DataTable.isDataTable('#processedWfhTable')) $('#processedWfhTable').DataTable().destroy();

    $('#wfhPendingTable').DataTable({
        paging: true, searching: true, ordering: true, info: true,
        responsive: { details: { display: $.fn.dataTable.Responsive.display.childRow, type: 'column', target: 0 } },
        columnDefs: [
            { className: 'control', orderable: false, targets: 0 },
            { responsivePriority: 1, targets: [1] },
            { responsivePriority: 2, targets: [5] },
            { responsivePriority: 3, targets: [2] },
            { responsivePriority: 4, targets: [3] },
            { responsivePriority: 5, targets: [4] }
        ],
        order: [[2, 'asc']],
        pageLength: 10,
        lengthMenu: [[10,25,50,-1],[10,25,50,"All"]],
        autoWidth: false,
        language: { emptyTable: "No pending WFH requests found" }
    });

    var processedTable = $('#processedWfhTable').DataTable({
        paging: true, searching: true, ordering: true, info: true,
        responsive: true,
        order: [[3, 'desc']],
        pageLength: 10,
        lengthMenu: [[10,25,50,-1],[10,25,50,"All"]],
        autoWidth: false,
        language: { emptyTable: "No processed WFH requests found" }
    });

    $('#statusFilter').on('change', function() {
        processedTable.column(4).search($(this).val()).draw();
    });

    $(window).resize(function () {
        if ($(window).width() <= 768) $('.dataTables_wrapper .row').css('justify-content','center');
    }).resize();
});

function exportWfhExcel() {
    var tableClone = document.createElement("table");
    var headerRow = tableClone.insertRow();
    ["Applicant", "WFH Date", "Processed On", "Status", "Rejection Reason"].forEach(function(h) {
        var th = document.createElement("th"); th.innerText = h; headerRow.appendChild(th);
    });
    var table = $('#processedWfhTable').DataTable();
    table.rows({ search: 'applied' }).data().each(function(row) {
        var tr = tableClone.insertRow();
        tr.innerHTML = '<td>' + $(row[1]).text().trim() + '</td>'
            + '<td>' + $(row[2]).text().trim() + '</td>'
            + '<td>' + $(row[3]).text().trim() + '</td>'
            + '<td>' + $(row[4]).text().trim() + '</td>'
            + '<td>' + $(row[5]).text().trim() + '</td>';
    });
    var wb = XLSX.utils.table_to_book(tableClone, { sheet: "WFH Requests" });
    XLSX.writeFile(wb, 'WFH_Requests.xlsx');
}

async function exportWfhPDF() {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF('landscape');
    doc.text('WFH Requests Report', 14, 15);
    var tableData = [];
    var table = $('#processedWfhTable').DataTable();
    table.rows({ search: 'applied' }).data().each(function(row) {
        tableData.push([
            $(row[1]).text().trim(),
            $(row[2]).text().trim(),
            $(row[3]).text().trim(),
            $(row[4]).text().trim(),
            $(row[5]).text().trim()
        ]);
    });
    doc.autoTable({
        head: [["Applicant", "WFH Date", "Processed On", "Status", "Rejection Reason"]],
        body: tableData,
        startY: 25,
    });
    doc.save('WFH_Requests.pdf');
}

function confirmAction(wfhId, action) {
    if (!wfhId) { console.error('WFH ID is missing.'); return; }

    if (typeof Swal === 'undefined') {
        if (action === 'reject') {
            var reason = prompt('Please provide a reason for rejection:');
            if (reason && reason.trim()) submitForm(wfhId, action, reason.trim());
        } else {
            if (confirm('Approve this WFH request?')) submitForm(wfhId, action, '');
        }
        return;
    }

    if (action === "reject") {
        Swal.fire({
            title: "Reject WFH Request",
            text: "Please provide a reason for rejection:",
            input: "textarea",
            inputPlaceholder: "Enter reason here...",
            showCancelButton: true,
            confirmButtonColor: "#c0392b",
            cancelButtonColor: "#6c757d",
            confirmButtonText: "Reject Request",
            inputValidator: v => { if (!v || !v.trim()) return "You must enter a reason!"; }
        }).then(r => { if (r.isConfirmed) submitForm(wfhId, action, r.value); });
    } else {
        Swal.fire({
            title: "Approve WFH Request",
            text: "This will mark attendance as present with WFH remark for the specified date.",
            icon: "question",
            showCancelButton: true,
            confirmButtonColor: "#1e7e44",
            cancelButtonColor: "#6c757d",
            confirmButtonText: "Yes, Approve",
            cancelButtonText: "Cancel"
        }).then(r => { if (r.isConfirmed) submitForm(wfhId, action, ""); });
    }
}

function submitForm(wfhId, action, reason) {
    reason = reason || "";
    var form = document.createElement("form");
    form.method = "POST";
    form.action = "";
    [["wfh_id", wfhId], ["action", action]].forEach(function(p) {
        var i = document.createElement("input");
        i.type = "hidden"; i.name = p[0]; i.value = p[1];
        form.appendChild(i);
    });
    if (action === "reject") {
        var ri = document.createElement("input");
        ri.type = "hidden"; ri.name = "reason"; ri.value = reason;
        form.appendChild(ri);
    }
    document.body.appendChild(form);
    form.submit();
}

function viewDocument(fileName) {
    var fp  = "wfh_docs/" + fileName;
    var low = fp.toLowerCase();
    var isImg = ['.jpg','.jpeg','.png','.gif','.bmp','.webp'].some(function(e){ return low.endsWith(e); });
    if (isImg) showImagePreview(fp);
    else if (low.endsWith('.pdf')) showPDFPreview(fp);
    else window.open(fp, '_blank');
}

function showImagePreview(url) {
    var ov = document.createElement('div'); ov.className = 'image-preview-overlay';
    var ct = document.createElement('div'); ct.className = 'image-preview-content';
    var bn = document.createElement('button'); bn.className = 'image-preview-cancel-btn'; bn.innerHTML = '&#10005;';
    var im = document.createElement('img'); im.src = url; im.className = 'image-preview-img'; im.alt = 'WFH Doc';
    im.onerror = function(){ this.style.display='none'; ct.innerHTML += '<div style="color:#fff;text-align:center;padding:20px"><h4>Image could not be loaded</h4></div>'; };
    bn.onclick = function(){ document.body.removeChild(ov); };
    ov.onclick = function(e){ if (e.target === ov) document.body.removeChild(ov); };
    var esc = function(e){ if(e.key==='Escape'){ document.body.removeChild(ov); document.removeEventListener('keydown',esc); } };
    document.addEventListener('keydown', esc);
    ct.appendChild(bn); ct.appendChild(im); ov.appendChild(ct); document.body.appendChild(ov);
}

function showPDFPreview(url) {
    var ov = document.createElement('div'); ov.className = 'pdf-preview-overlay';
    var ct = document.createElement('div'); ct.className = 'pdf-preview-content';
    var bn = document.createElement('button'); bn.className = 'pdf-preview-cancel-btn'; bn.innerHTML = '&#10005;';
    var fr = document.createElement('iframe'); fr.src = url; fr.className = 'pdf-preview-iframe'; fr.title = 'WFH Doc';
    bn.onclick = function(){ document.body.removeChild(ov); fr.src=''; };
    ov.onclick = function(e){ if(e.target===ov){ document.body.removeChild(ov); fr.src=''; } };
    var esc = function(e){ if(e.key==='Escape'){ document.body.removeChild(ov); fr.src=''; document.removeEventListener('keydown',esc); } };
    document.addEventListener('keydown', esc);
    ct.appendChild(bn); ct.appendChild(fr); ov.appendChild(ct); document.body.appendChild(ov);
    setTimeout(function(){ fr.focus(); }, 100);
}
</script>

<?php
require_once('../include/sweetAlert.php');
if (isset($_SESSION['status'])) {
    if ($_SESSION['status'] != true) toastMsg('error', $_SESSION['msg']);
    else toastMsg('success', $_SESSION['msg']);
    unset($_SESSION['status']);
    unset($_SESSION['msg']);
}
?>
</body>
</html>