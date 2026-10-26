<?php
session_start();
include('../include/db_config.php');
 
if (!isset($_SESSION['userId'])) {
    echo "<script>window.location.href='login.php';</script>";
    exit;
}
 
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    unset($_SESSION['swal']);
}
 
$userId   = $_SESSION['userId'];
$getUser  = mysqli_query($con, "SELECT designation, fullName FROM signup WHERE userId='$userId'");
$userData = mysqli_fetch_assoc($getUser);
 
$userRole = strtolower(trim($_SESSION['userRole'] ?? ''));
$desig    = strtolower(trim($userData['designation'] ?? ''));

if (!$userData || (!in_array($desig, ['hr', 'seniorintern', 'admin']) && !in_array($userRole, ['hr', 'admin']))) {
    echo "<script>alert('Unauthorized Access'); window.location.href='logout.php';</script>";
    exit;
}

$userName = htmlspecialchars($userData['fullName'] ?? 'Unknown');
 
// ── Handle POST Requests ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['cancelDecision'])) {
        $evalId = intval($_POST['evalId']);
        $updateQuery = "UPDATE technical_evaluations SET hrStatus = NULL, hrComments = NULL, hrDecidedAt = NULL WHERE id = $evalId AND submittedToHr = 1";
        if (mysqli_query($con, $updateQuery)) {
            $_SESSION['swal'] = ['icon' => 'success', 'title' => 'Decision Revoked', 'text' => 'The evaluation decision has been reverted to Pending.'];
        } else {
            $_SESSION['swal'] = ['icon' => 'error', 'title' => 'DB Error', 'text' => 'Failed to revoke decision.'];
        }
        header("Location: technicalEvaluationHr.php");
        exit;
    }
 
    $evalId        = intval($_POST['evalId']);
    $hrStatus      = in_array($_POST['hrStatus'], ['approved', 'rejected']) ? $_POST['hrStatus'] : '';
    $hrComments    = mysqli_real_escape_string($con, trim($_POST['hrComments'] ?? ''));
    $confirmedMode = mysqli_real_escape_string($con, trim($_POST['confirmedMode'] ?? ''));

    if ($evalId <= 0) {
        $_SESSION['swal'] = ['icon' => 'error', 'title' => 'Invalid', 'text' => 'Invalid evaluation ID.'];
        header("Location: technicalEvaluationHr.php"); exit;
    }

    $lockCheck = mysqli_query($con, "SELECT id, candidateId FROM technical_evaluations WHERE id = $evalId AND submittedToHr = 1");
    $evalRow = mysqli_fetch_assoc($lockCheck);
    if (!$evalRow) {
        $_SESSION['swal'] = ['icon' => 'error', 'title' => 'Not Found', 'text' => 'Evaluation record not found or not submitted to HR.'];
        header("Location: technicalEvaluationHr.php"); exit;
    }

    if (empty($hrStatus)) {
        $_SESSION['swal'] = ['icon' => 'error', 'title' => 'Decision Required', 'text' => 'Please select Approve or Reject.'];
        header("Location: technicalEvaluationHr.php"); exit;
    }

    if ($hrStatus === 'rejected' && empty($hrComments)) {
        $_SESSION['swal'] = ['icon' => 'error', 'title' => 'Reason Required', 'text' => 'Please provide a reason for rejection.'];
        header("Location: technicalEvaluationHr.php"); exit;
    }

    $commentsValue = !empty($hrComments) ? "'$hrComments'" : 'NULL';

    if ($hrStatus === 'approved' && !empty($confirmedMode)) {
        if (!empty($evalRow['candidateId'])) {
            $candId = intval($evalRow['candidateId']);
            mysqli_query($con, "UPDATE candidates SET interviewMode = '$confirmedMode' WHERE id = $candId");
        }
        $updateQuery = "
            UPDATE technical_evaluations
            SET hrStatus       = '$hrStatus',
                hrComments     = $commentsValue,
                internshipMode = '$confirmedMode',
                hrDecidedAt    = NOW()
            WHERE id = $evalId AND submittedToHr = 1
        ";
    } else {
        $updateQuery = "
            UPDATE technical_evaluations
            SET hrStatus    = '$hrStatus',
                hrComments  = $commentsValue,
                hrDecidedAt = NOW()
            WHERE id = $evalId AND submittedToHr = 1
        ";
    }
    $updateResult = mysqli_query($con, $updateQuery);

    if (!$updateResult) {
        error_log('HR Evaluation update failed: ' . mysqli_error($con));
        $_SESSION['swal'] = ['icon' => 'error', 'title' => 'DB Error', 'text' => 'Update failed: ' . mysqli_error($con)];
        header("Location: technicalEvaluationHr.php"); exit;
    }
 
    $_SESSION['swal'] = [
        'icon'  => $hrStatus === 'approved' ? 'success' : 'info',
        'title' => $hrStatus === 'approved' ? 'Approved!' : 'Rejected',
        'text'  => 'The evaluation has been ' . $hrStatus . ' successfully.'
                 . ($hrStatus === 'approved' ? ' The candidate will now appear in the Onboarding list.' : '')
    ];
    header("Location: technicalEvaluationHr.php");
    exit;
}

// ── Search / Filter ───────────────────────────────────────────────
$search       = isset($_GET['search'])   ? mysqli_real_escape_string($con, trim($_GET['search'])) : '';
$filterStatus = isset($_GET['status'])   ? mysqli_real_escape_string($con, $_GET['status'])       : '';
$filterMode   = isset($_GET['mode'])     ? mysqli_real_escape_string($con, $_GET['mode'])         : '';
 
$where = "WHERE te.submittedToHr = 1";
if ($search) $where .= " AND (c.applicantName LIKE '%$search%' OR c.email LIKE '%$search%' OR c.mobile LIKE '%$search%')";
if ($filterStatus === 'Pending')  { $where .= " AND (te.hrStatus IS NULL OR te.hrStatus = '' OR te.hrStatus = 'pending')"; }
elseif ($filterStatus === 'Approved') { $where .= " AND te.hrStatus = 'approved'"; }
elseif ($filterStatus === 'Rejected') { $where .= " AND te.hrStatus = 'rejected'"; }
if ($filterMode) {  $where .= " AND c.interviewMode = '$filterMode'";}

// Stats
$totalSubmitted = (int)mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as c FROM technical_evaluations WHERE submittedToHr = 1"))['c'];
$pending = (int)mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as c FROM technical_evaluations WHERE submittedToHr = 1 AND (hrStatus IS NULL OR hrStatus = '' OR hrStatus = 'pending')"))['c'];
$approved = (int)mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as c FROM technical_evaluations WHERE submittedToHr = 1 AND hrStatus = 'approved'"))['c'];
$rejected = (int)mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as c FROM technical_evaluations WHERE submittedToHr = 1 AND hrStatus = 'rejected'"))['c'];

// Fetch all data (no pagination — DataTables handles client-side)
$allCandidates = [];
$allQ = mysqli_query($con, "
    SELECT c.*, te.*, te.id AS evalId 
    FROM candidates c
    INNER JOIN technical_evaluations te ON c.id = te.candidateId
    $where
    ORDER BY te.submittedAt DESC
");
while ($r = mysqli_fetch_assoc($allQ)) {
    $langQuery = mysqli_query($con, "SELECT * FROM language_ratings WHERE evaluationId='" . intval($r['evalId']) . "'");
    $langs = [];
    while ($l = mysqli_fetch_assoc($langQuery)) $langs[] = $l;
    $r['languages'] = $langs;
    $allCandidates[] = $r;
}
$totalRows = count($allCandidates);

// Check approved candidates not onboarded
$diagResult = mysqli_query($con, "
    SELECT COUNT(*) AS cnt
    FROM candidates c
    INNER JOIN technical_evaluations te ON c.id = te.candidateId
    WHERE te.submittedToHr = 1
      AND te.hrStatus = 'approved'
      AND c.email NOT IN (SELECT email FROM interns WHERE email IS NOT NULL AND email != '')
");
$diagRow = mysqli_fetch_assoc($diagResult);
$approvedNotOnboarded = (int)$diagRow['cnt'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Technical Evaluation HR</title>
    <?php include('../partials/_links.php'); ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap4.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/exceljs/dist/exceljs.min.js"></script>
    
    <style>
        :root {
            --primary: #2f55ff;
            --primary-light: #eef1ff;
            --success: #28a745;
            --warning: #f59e0b;
            --danger: #e53935;
            --info: #17a2b8;
            --border: #e0e4ef;
            --radius: 8px;
            --shadow: 0 2px 12px rgba(47,85,255,0.07);
        }
        
        .swal2-container { z-index: 99999 !important; }
        
        .stat-card {
            background: #fff; border-radius: 12px; padding: 18px 22px;
            box-shadow: var(--shadow); border-left: 4px solid var(--primary);
            display: flex; align-items: center; gap: 16px;
        }
        .stat-card.total    { border-color: var(--primary); }
        .stat-card.pending  { border-color: var(--warning); }
        .stat-card.approved { border-color: var(--success); }
        .stat-card.rejected { border-color: var(--danger); }
        
        .stat-icon { width: 46px; height: 46px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0; }
        .stat-icon.total    { background: var(--primary-light); color: var(--primary); }
        .stat-icon.pending  { background: #fff8e1; color: var(--warning); }
        .stat-icon.approved { background: #f0fff4; color: var(--success); }
        .stat-icon.rejected { background: #fff1f1; color: var(--danger); }
        
        .stat-num   { font-size: 1.7rem; font-weight: 800; color: #222; line-height: 1; }
        .stat-label { font-size: 0.82rem; color: #888; margin-top: 2px; }
        
        .filter-bar {
            background: #fff; border-radius: var(--radius); padding: 16px 20px;
            box-shadow: var(--shadow); margin-bottom: 20px;
            display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end;
        }
        .filter-bar .form-group { margin: 0; flex: 1; min-width: 160px; }
        .filter-bar label { font-size: 0.78rem; font-weight: 600; color: #888; margin-bottom: 4px; display: block; }
        .filter-bar .form-control, .filter-bar select { border-radius: 6px; font-size: 0.87rem; border: 1.5px solid var(--border); padding: 7px 11px; }
        .btn-filter { background: var(--primary); color: #fff; border: none; border-radius: 6px; padding: 9px 20px; font-weight: 600; font-size: 0.88rem; cursor: pointer; }
        .btn-reset  { background: #f1f3f9; color: #555; border: none; border-radius: 6px; padding: 9px 16px; font-weight: 600; font-size: 0.88rem; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-excel  { background: #217346; color: #fff; border: none; border-radius: 6px; padding: 8px 16px; font-weight: 600; font-size: 0.88rem; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: background 0.2s; }
        .btn-excel:hover { background: #185c37; color: #fff; }
        
        .alert-banner { background: #f0fff4; border: 1px solid #c3e6cb; border-left: 4px solid var(--success); border-radius: 8px; padding: 12px 18px; margin-bottom: 20px; font-size: 0.85rem; color: #155724; display: flex; align-items: center; gap: 10px; }
        .alert-banner a { color: #155724; font-weight: 700; text-decoration: underline; }
        
        .evaluation-table-wrap { background: #fff; border-radius: 12px; box-shadow: var(--shadow); overflow: hidden; }
        .evaluation-table-wrap .table-header { padding: 18px 22px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid var(--border); }
        .evaluation-table-wrap .table-header h5 { margin: 0; font-weight: 700; font-size: 1rem; }
        
        table.eval-table { width: 100%; border-collapse: collapse; font-size: 0.87rem; }
        .eval-table th { background: #f8f9fd; color: #555; font-weight: 700; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.05em; padding: 12px 16px; border-bottom: 1px solid var(--border); white-space: nowrap; }
        .eval-table td { padding: 11px 16px; border-bottom: 1px solid #f2f4fb; vertical-align: middle; color: #333; }
        .eval-table tr:last-child td { border-bottom: none; }
        .eval-table tr:hover td { background: #f8f9ff; }
        
        .badge-status { display: inline-block; padding: 4px 11px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; letter-spacing: 0.03em; }
        .badge-pending   { background: #fff8e1; color: #b45309; }
        .badge-approved  { background: #f0fff4; color: #15803d; }
        .badge-rejected  { background: #fff1f1; color: var(--danger); }
        
        .badge-mode { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
        .badge-online  { background: #eef1ff; color: var(--primary); }
        .badge-offline { background: #f3f4f6; color: #555; }
        
        .score-badge { background: #ede9ff; color: #4B49AC; padding: 4px 11px; border-radius: 15px; font-size: 0.78rem; font-weight: 700; display: inline-block; }
        
        .btn-action { border: none; border-radius: 6px; padding: 6px 14px; font-size: 0.82rem; font-weight: 600; cursor: pointer; transition: all 0.2s; text-decoration: none; display: inline-block; }
        .btn-action.decide { background: var(--primary-light); color: var(--primary); }
        .btn-action.decide:hover { background: var(--primary); color: #fff; }
        .btn-action.view { background: #e3f2fd; color: var(--info); }
        .btn-action.view:hover { background: var(--info); color: #fff; }
        
        /* DataTables overrides */
        .dataTables_wrapper .dataTables_paginate .paginate_button.current,
        .dataTables_wrapper .dataTables_paginate .paginate_button.current:hover {
            background: var(--primary) !important;
            border-color: var(--primary) !important;
            color: #fff !important;
            border-radius: 6px;
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
            background: var(--primary-light) !important;
            border-color: var(--primary) !important;
            color: var(--primary) !important;
            border-radius: 6px;
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button { border-radius: 6px; font-size: 0.83rem; font-weight: 600; }
        .dataTables_wrapper .dataTables_filter input { border: 1.5px solid var(--border); border-radius: 6px; padding: 5px 10px; font-size: 0.87rem; }
        .dataTables_wrapper .dataTables_length select { border: 1.5px solid var(--border); border-radius: 6px; padding: 4px 8px; font-size: 0.87rem; }
        .dataTables_wrapper .dataTables_info { font-size: 0.83rem; color: #888; padding-top: 10px; }
        .dataTables_wrapper .dataTables_paginate { padding-top: 10px; }
        .dt-controls-row { padding: 12px 22px 0; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; }

        /* Modal Styles */
        .modalOverlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 9999; justify-content: center; align-items: center; }
        .modalOverlay.active { display: flex; }
        .modalBox { background: white; border-radius: 12px; padding: 0; max-width: 750px; width: 90%; max-height: 92vh; overflow-y: auto; box-shadow: 0 10px 40px rgba(0,0,0,0.2); }
        
        .modalHeader { background: var(--primary-light); padding: 18px 24px; border-bottom: 1px solid var(--border); border-radius: 12px 12px 0 0; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 1; }
        .modalHeader h5 { color: var(--primary); margin: 0; font-weight: 700; font-size: 1.1rem; }
        .modalClose { background: none; border: none; font-size: 1.5rem; color: #999; cursor: pointer; padding: 0; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; }
        .modalClose:hover { color: #333; }
        
        .modalBody { padding: 24px; }
        .modalFooter { padding: 16px 24px; border-top: 1px solid var(--border); display: flex; gap: 10px; justify-content: space-between; position: sticky; bottom: 0; background: #fff; }
        
        .sectionTitle { font-size: 0.85rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.08em; color: var(--primary); border-bottom: 2px solid var(--border); padding-bottom: 6px; margin: 20px 0 12px; }
        
        .infoGrid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
        .infoItem label { font-size: 0.75rem; color: #888; display: block; margin-bottom: 4px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; }
        .infoItem p { margin: 0; font-weight: 600; color: #333; font-size: 0.9rem; }
        
        .langTable { width: 100%; border-collapse: collapse; margin: 15px 0; }
        .langTable th { background: #f8f9fd; padding: 10px; font-size: 0.8rem; font-weight: 700; color: #555; text-transform: uppercase; letter-spacing: 0.05em; }
        .langTable td { padding: 10px; border-bottom: 1px solid #f2f4fb; }
        
        .feedbackBox { background: #f8f9fb; padding: 16px; border-radius: 8px; margin-top: 10px; border-left: 3px solid var(--primary); font-size: 0.88rem; color: #555; line-height: 1.6; white-space: pre-wrap; }
        
        .decisionBtns { display: flex; gap: 12px; margin-bottom: 16px; }
        .decisionBtnApprove, .decisionBtnReject { flex: 1; padding: 12px; border: 2px solid #e0e0e0; border-radius: 10px; background: #fff; cursor: pointer; transition: all 0.2s; font-weight: 600; font-size: 14px; color: #555; display: flex; align-items: center; justify-content: center; gap: 6px; }
        .decisionBtnApprove:hover, .decisionBtnApprove.selected { border-color: var(--success); background: #f0fff4; color: var(--success); }
        .decisionBtnReject:hover, .decisionBtnReject.selected { border-color: var(--danger); background: #fff5f5; color: var(--danger); }
        
        .notice-box { background: #fff8e1; border: 1px solid #ffe082; padding: 10px 14px; border-radius: 8px; margin-bottom: 15px; font-size: 0.85rem; }
        
        .fee-confirm-box { background: #f4f9f4; padding: 12px; border-radius: 8px; border: 1px solid #c3e6cb; margin-bottom: 15px; }
        .fee-confirm-box label { font-weight: 600; font-size: 0.85rem; color: #155724; display: flex; align-items: center; gap: 8px; cursor: pointer; margin: 0; }
        .fee-confirm-box input[type="checkbox"] { width: 16px; height: 16px; }
        
        .empty-state { text-align: center; padding: 48px 20px; color: #aaa; }
        .empty-state i { font-size: 2.5rem; margin-bottom: 12px; color: #d0d5f0; }
        
        .btn-primary-custom { background: var(--primary); color: #fff; border: none; border-radius: 6px; padding: 8px 18px; font-weight: 600; font-size: 0.88rem; cursor: pointer; }
        .btn-secondary-custom { background: #f1f3f9; color: #555; border: none; border-radius: 6px; padding: 8px 18px; font-weight: 600; font-size: 0.88rem; cursor: pointer; }
        .btn-danger-custom { background: var(--danger); color: #fff; border: none; border-radius: 6px; padding: 6px 14px; font-weight: 600; font-size: 0.82rem; cursor: pointer; }
    </style>
</head>
<body>
<div class="container-scroller">
    <?php include('../partials/_navbar.php'); ?>
    <div class="container-fluid page-body-wrapper">
        <?php include('../partials/_sidebar.php'); ?>
        <div class="main-panel">
            <div class="content-wrapper">
 
                <?php if (isset($_SESSION['swal'])): ?>
                <script>
                document.addEventListener('DOMContentLoaded', function () {
                    Swal.fire({
                        icon:               '<?= $_SESSION['swal']['icon'] ?>',
                        title:              '<?= addslashes($_SESSION['swal']['title']) ?>',
                        text:               '<?= addslashes($_SESSION['swal']['text']) ?>',
                        confirmButtonColor: '#2f55ff'
                    });
                });
                </script>
                <?php unset($_SESSION['swal']); endif; ?>
 
                <!-- Page Header -->
                <div class="d-flex align-items-center justify-content-between mb-4">
                    <div>
                        <h4 class="mb-1" style="font-weight:800;color:#222;">
                            <i class="fa-solid fa-user-check me-2" style="color:var(--primary)"></i>
                            Technical Evaluation — HR Review
                        </h4>
                        <p class="mb-0" style="color:#888;font-size:0.88rem;">
                            Logged in as <strong><?php echo $userName; ?></strong>
                        </p>
                    </div>
                    <button class="btn-excel" onclick="exportToExcel()">
                        <i class="fa-solid fa-file-excel"></i> Export Excel
                    </button>
                </div>
 
                
 
                <!-- Stats -->
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <div class="stat-card total">
                            <div class="stat-icon total"><i class="fa-solid fa-paper-plane"></i></div>
                            <div><div class="stat-num"><?php echo $totalSubmitted; ?></div><div class="stat-label">Total Submitted</div></div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stat-card pending">
                            <div class="stat-icon pending"><i class="fa-solid fa-hourglass-half"></i></div>
                            <div><div class="stat-num"><?php echo $pending; ?></div><div class="stat-label">Pending Review</div></div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stat-card approved">
                            <div class="stat-icon approved"><i class="fa-solid fa-check-circle"></i></div>
                            <div><div class="stat-num"><?php echo $approved; ?></div><div class="stat-label">Approved</div></div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stat-card rejected">
                            <div class="stat-icon rejected"><i class="fa-solid fa-times-circle"></i></div>
                            <div><div class="stat-num"><?php echo $rejected; ?></div><div class="stat-label">Rejected</div></div>
                        </div>
                    </div>
                </div>
 
                <!-- Filter Bar -->
                <form method="GET" action="" class="filter-bar">
                    <div class="form-group">
                        <label>Search</label>
                        <input type="text" name="search" class="form-control" placeholder="Name, email, mobile…" value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="form-group">
                        <label>HR Decision</label>
                        <select name="status" class="form-control">
                            <option value="">All Decisions</option>
                            <option value="Pending"  <?php echo ($filterStatus === 'Pending')  ? 'selected' : ''; ?>>Pending</option>
                            <option value="Approved" <?php echo ($filterStatus === 'Approved') ? 'selected' : ''; ?>>Approved</option>
                            <option value="Rejected" <?php echo ($filterStatus === 'Rejected') ? 'selected' : ''; ?>>Rejected</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Mode</label>
                        <select name="mode" class="form-control">
                            <option value="">All Modes</option>
                            <option value="Online"  <?php echo ($filterMode === 'Online')  ? 'selected' : ''; ?>>Online</option>
                            <option value="Offline" <?php echo ($filterMode === 'Offline') ? 'selected' : ''; ?>>Offline</option>
                        </select>
                    </div>
                    <button type="submit" class="btn-filter"><i class="fa-solid fa-magnifying-glass me-1"></i> Filter</button>
                    <a href="technicalEvaluationHr.php" class="btn-reset"><i class="fa-solid fa-rotate me-1"></i> Reset</a>
                </form>
 
                <!-- Table -->
                <div class="evaluation-table-wrap">
                    <div class="table-header">
                        <h5><i class="fa-solid fa-list me-2" style="color:var(--primary)"></i> HR Review Records</h5>
                        <span style="color:#888;font-size:0.83rem;"><?php echo $totalRows; ?> records found</span>
                    </div>
 
                    <div style="overflow-x:auto; padding: 16px 16px 0;">
                        <table class="eval-table table table-hover" id="hrEvalTable" style="width:100%">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Mobile</th>
                                    <th>Mode</th>
                                    <th>Application Date</th>
                                    <th>Submitted At</th>
                                    <th>HR Decision</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $sr = 1;
                                if (count($allCandidates) > 0):
                                    foreach ($allCandidates as $row):
                                        $mode = htmlspecialchars($row['internshipMode'] ?? $row['interviewMode'] ?? $row['interview_mode'] ?? '');
                                        $modeClass = (strtolower($mode) === 'online') ? 'badge-online' : 'badge-offline';
                                        $isDecided = in_array($row['hrStatus'], ['approved', 'rejected']);
                                        if ($row['hrStatus'] === 'approved')      { $status = 'Approved'; $statusClass = 'badge-approved'; }
                                        elseif ($row['hrStatus'] === 'rejected')  { $status = 'Rejected'; $statusClass = 'badge-rejected'; }
                                        else                                       { $status = 'Pending';  $statusClass = 'badge-pending'; }

                                        $langData = $row['languages'] ?? [];
                                        $internshipMode  = trim($row['internshipMode'] ?? '');
                                        $applicationDate = $row['applicationDate'] ?? '';
                                        $feeAccept       = $row['feeAccept'] ?? $row['feeStatus'] ?? '';
                                        
                                        $rowData = [
                                            'evalId'     => intval($row['evalId']),
                                            'name'       => $row['applicantName'] ?? '',
                                            'email'      => $row['email'] ?? '',
                                            'mobile'     => $row['mobile'] ?? '',
                                            'appDate'    => $applicationDate,
                                            'score'      => $row['overallScore'] ?? '',
                                            'mode'       => $mode,
                                            'feedback'   => $row['overallFeedback'] ?? '',
                                            'feeAccept'  => $feeAccept ?: '—',
                                            'hrStatus'   => $row['hrStatus'] ?? '',
                                            'hrComments' => $row['hrComments'] ?? '',
                                            'submittedAt'=> $row['submittedAt'] ?? '',
                                            'langs'      => $langData,
                                            'isDecided'  => $isDecided
                                        ];
                                        $rowDataJson = htmlspecialchars(json_encode($rowData), ENT_QUOTES, 'UTF-8');
                                ?>
                                <tr>
                                    <td><?= $sr++ ?></td>
                                    <td><strong><?= htmlspecialchars($row['applicantName']) ?></strong></td>
                                    <td style="color:#666;font-size:0.82rem;"><?= htmlspecialchars($row['email']) ?></td>
                                    <td style="font-size:0.82rem;"><?= htmlspecialchars($row['mobile']) ?></td>
                                    <td>
                                        <?php if ($mode): ?>
                                            <span class="badge-mode <?= $modeClass ?>"><?= $mode ?></span>
                                        <?php else: ?>—<?php endif; ?>
                                    </td>
                                    <td><?= $applicationDate ? date('d M Y', strtotime($applicationDate)) : '—' ?></td>
                                    <td><?= $row['submittedAt'] ? date('d M Y', strtotime($row['submittedAt'])) : '—' ?></td>
                                    <td><span class="badge-status <?= $statusClass ?>"><?= $status ?></span></td>
                                    <td>
                                        <button class="btn-action <?= $isDecided ? 'view' : 'decide' ?>"
                                                onclick="openModalByEvalId(<?= intval($row['evalId']) ?>)">
                                            <i class="fa-solid fa-<?= $isDecided ? 'eye' : 'gavel' ?> me-1"></i>
                                            <?= $isDecided ? 'View & Edit' : 'Review' ?>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; else: ?>
                                <tr>
                                    <td colspan="9">
                                        <div class="empty-state">
                                            <i class="fa-solid fa-inbox d-block"></i>
                                            No evaluation records found.
                                        </div>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
 
            </div>
            <?php include('../partials/_footer.php'); ?>
        </div>
    </div>
</div>
 
<!-- Modal -->
<div class="modalOverlay" id="previewModal">
    <div class="modalBox">
        <div class="modalHeader">
            <h5 id="modalTitle"><i class="fa-solid fa-gavel me-2"></i> Evaluation Review</h5>
            <button class="modalClose" onclick="closeModal()">&times;</button>
        </div>
        <div class="modalBody">
            <div class="sectionTitle"><i class="fa-solid fa-user me-2"></i> Candidate Information</div>
            <div class="infoGrid">
                <div class="infoItem"><label>Name</label><p id="prevName"></p></div>
                <div class="infoItem"><label>Email</label><p id="prevEmail"></p></div>
                <div class="infoItem"><label>Mobile</label><p id="prevMobile"></p></div>
                <div class="infoItem"><label>Application Date</label><p id="prevAppDate"></p></div>
                <div class="infoItem"><label>Fee Confirmation</label><p id="prevFeeAccept"></p></div>
                <div class="infoItem"><label>Interview Mode</label><p id="prevMode"></p></div>
                <div class="infoItem" style="grid-column:span 2;"><label>Overall Score</label><p id="prevScore"></p></div>
            </div>
            
            <div class="sectionTitle"><i class="fa-solid fa-code me-2"></i> Language Ratings</div>
            <table class="langTable">
                <thead><tr><th>Language</th><th>Conceptual</th><th>Practical</th><th>Average</th></tr></thead>
                <tbody id="prevLangBody"></tbody>
            </table>
            
            <div class="sectionTitle"><i class="fa-solid fa-comment-dots me-2"></i> Overall Feedback</div>
            <div class="feedbackBox" id="prevFeedback"></div>
            
            <div class="sectionTitle"><i class="fa-solid fa-gavel me-2"></i> HR Decision</div>
            <div id="decisionArea"></div>
        </div>
        <div class="modalFooter">
            <div style="flex:1;text-align:left;">
                <button type="button" class="btn-danger-custom" id="btnRevoke" style="display:none;" onclick="revokeDecision()">
                    <i class="fa-solid fa-undo me-1"></i> Revoke Decision
                </button>
            </div>
            <button type="button" class="btn-secondary-custom" onclick="closeModal()">Close</button>
            <button type="button" class="btn-primary-custom" id="saveDecisionBtn" onclick="submitDecision()">
                <i class="fa-solid fa-save me-1"></i> Save Decision
            </button>
        </div>
    </div>
</div>
 
<script>
const allCandidatesData = <?php echo json_encode($allCandidates); ?>;
</script>
 
<script src="../vendors/js/vendor.bundle.base.js"></script>
<script src="../js/off-canvas.js"></script>
<script src="../js/hoverable-collapse.js"></script>
<script src="../js/template.js"></script>
<script src="../js/settings.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap4.min.js"></script>

<script>
$(document).ready(function () {
    $('#hrEvalTable').DataTable({
        pageLength: 10,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
        order: [],
        responsive: true,
        language: {
            emptyTable: '<div class="empty-state"><i class="fa-solid fa-inbox d-block"></i> No evaluation records found.</div>',
            search: '',
            searchPlaceholder: 'Search records...',
            lengthMenu: 'Show _MENU_ entries',
            info: 'Showing _START_ to _END_ of _TOTAL_ records',
            infoEmpty: 'No records available',
            infoFiltered: '(filtered from _MAX_ total)',
            paginate: { first: '«', last: '»', next: '›', previous: '‹' }
        },
        columnDefs: [
            { orderable: false, targets: [8] },
            { defaultContent: '', targets: '_all' }  // ← fixes column count mismatch
        ],
        dom: '<"dt-controls-row"lf>rt<"dt-controls-row"ip>',
        initComplete: function () {
            $('.dataTables_filter input').addClass('form-control').css({ 'border': '1.5px solid #e0e4ef', 'border-radius': '6px', 'font-size': '0.87rem', 'padding': '7px 11px' });
            $('.dataTables_length select').addClass('form-control').css({ 'border': '1.5px solid #e0e4ef', 'border-radius': '6px', 'font-size': '0.87rem' });
        }
    });
});

var currentEvalId  = null;
var selectedStatus = null;

async function exportToExcel() {
    if (!allCandidatesData || allCandidatesData.length === 0) { alert('No records to export.'); return; }
    const workbook = new ExcelJS.Workbook();
    const worksheet = workbook.addWorksheet('HR Review');
    const currentDate = new Date();
    const title = `HR Technical Evaluation Report - ${currentDate.toLocaleDateString('en-GB')}`;
    worksheet.mergeCells('A1:N1');
    const titleCell = worksheet.getCell('A1');
    titleCell.value = title;
    titleCell.font = { bold: true, size: 16 };
    titleCell.alignment = { horizontal: 'center', vertical: 'middle' };
    titleCell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFD3D3D3' } };
    worksheet.getRow(1).height = 25;
    const headers = ['#', 'Name', 'Email', 'Mobile', 'Mode', 'Application Date', 'Submitted At', 'Overall Score', 'Overall Feedback', 'Fee Confirmation', 'HR Decision', 'HR Comments', 'Languages Evaluated', 'Language Details'];
    const headerRow = worksheet.addRow(headers);
    headerRow.eachCell(cell => {
        cell.font = { bold: true };
        cell.alignment = { horizontal: 'center', vertical: 'middle', wrapText: true };
        cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFF2F2F2' } };
        cell.border = { top: { style: 'thin' }, bottom: { style: 'thin' }, left: { style: 'thin' }, right: { style: 'thin' } };
    });
    worksheet.getRow(2).height = 30;
    allCandidatesData.forEach((c, idx) => {
        let hrDecision = 'Pending';
        if (c.hrStatus === 'approved') hrDecision = 'Approved';
        else if (c.hrStatus === 'rejected') hrDecision = 'Rejected';
        let mode = c.internshipMode || c.interviewMode || c.interview_mode || '—';
        let feeAccept = c.feeAccept || c.feeStatus || '—';
        let langNames = '—', langDetails = '—';
// Update the exportToExcel function's language details generation:
if (c.languages && c.languages.length > 0) {
    langNames = c.languages.map(l => l.languageName).join(', ');
    langDetails = c.languages.map(l => {
        let avg = ((parseFloat(l.conceptualScore) + parseFloat(l.practicalScore)) / 2).toFixed(1);
        let feedback = l.languageFeedback ? ` - Feedback: ${l.languageFeedback}` : '';
        return `${l.languageName}: C${l.conceptualScore}/P${l.practicalScore} (Avg:${avg})${feedback}`;
    }).join(' | ');
}
        const row = worksheet.addRow([idx + 1, c.applicantName || '', c.email || '', c.mobile || '', mode, c.applicationDate || '', c.submittedAt || '', c.overallScore ? `${c.overallScore} / 5` : '—', c.overallFeedback || '—', feeAccept, hrDecision, c.hrComments || '', langNames, langDetails]);
        row.eachCell((cell, colNumber) => {
            cell.alignment = { horizontal: colNumber === 1 ? 'center' : 'left', vertical: 'middle', wrapText: colNumber === 9 || colNumber === 14 };
            cell.border = { top: { style: 'thin' }, bottom: { style: 'thin' }, left: { style: 'thin' }, right: { style: 'thin' } };
        });
    });
    worksheet.columns = [{ width: 5 }, { width: 25 }, { width: 30 }, { width: 15 }, { width: 12 }, { width: 15 }, { width: 18 }, { width: 15 }, { width: 40 }, { width: 18 }, { width: 15 }, { width: 30 }, { width: 30 }, { width: 50 }];
    const buffer = await workbook.xlsx.writeBuffer();
    const blob = new Blob([buffer], { type: "application/octet-stream" });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `HR_Technical_Evaluation_${new Date().toISOString().split('T')[0]}.xlsx`;
    link.click();
}

function openModalByEvalId(evalId) {
    var candidate = (allCandidatesData || []).find(function(c) {
        return (c.evalId == evalId || c.id == evalId);
    });
    if (!candidate) {
        Swal.fire({ icon: 'error', title: 'Error', text: 'Candidate record not found.', confirmButtonColor: '#2f55ff' });
        return;
    }
    var mode = candidate.internshipMode || candidate.interviewMode || candidate.interview_mode || '';
    var feeAccept = candidate.feeAccept || candidate.feeStatus || '—';
    var isDecided = (candidate.hrStatus === 'approved' || candidate.hrStatus === 'rejected');
    
    openModal({
        evalId:      parseInt(candidate.evalId || candidate.id),
        name:        candidate.applicantName || '',
        email:       candidate.email || '',
        mobile:      candidate.mobile || '',
        appDate:     candidate.applicationDate || '',
        score:       candidate.overallScore || '',
        mode:        mode,
        feedback:    candidate.overallFeedback || '',
        feeAccept:   feeAccept,
        hrStatus:    candidate.hrStatus || '',
        hrComments:  candidate.hrComments || '',
        submittedAt: candidate.submittedAt || '',
        langs:       candidate.languages || candidate.langData || [],
        isDecided:   isDecided
    });
}

function openModal(data) {
    currentEvalId  = data.evalId;
    selectedStatus = null;
    document.getElementById('prevName').textContent      = data.name;
    document.getElementById('prevEmail').textContent     = data.email;
    document.getElementById('prevMobile').textContent    = data.mobile;
    document.getElementById('prevAppDate').textContent   = data.appDate ? formatDate(data.appDate) : '—';
    document.getElementById('prevScore').innerHTML       = '<span class="score-badge">' + (data.score || '—') + ' / 5</span>';
    document.getElementById('prevFeeAccept').textContent = data.feeAccept || '—';
    document.getElementById('prevFeedback').textContent  = data.feedback || 'No feedback provided';
    var modeBadge = '—';
    if (data.mode) { var ml = data.mode.toLowerCase(); var modeClass = ml === 'online' ? 'badge-online' : 'badge-offline'; modeBadge = '<span class="badge-mode ' + modeClass + '">' + data.mode + '</span>'; }
    document.getElementById('prevMode').innerHTML = modeBadge;
    var tbody = document.getElementById('prevLangBody');
    tbody.innerHTML = '';
    if (data.langs && data.langs.length > 0) {
        data.langs.forEach(function (l) {
            var avg = ((parseFloat(l.conceptualScore) + parseFloat(l.practicalScore)) / 2).toFixed(1);
            var tr = document.createElement('tr');
            tr.innerHTML = '<td><strong>' + l.languageName + '</strong></td><td><span class="score-badge">' + l.conceptualScore + ' / 5</span></td><td><span class="score-badge">' + l.practicalScore + ' / 5</span></td><td><span class="score-badge">' + avg + ' / 5</span></td>';
            tbody.appendChild(tr);
        });
    } else {
        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; color:#999;">No language ratings recorded</td></tr>';
    }
    document.getElementById('modalTitle').innerHTML = data.isDecided ? '<i class="fa-solid fa-edit me-2"></i> Evaluation Review (Edit Mode)' : '<i class="fa-solid fa-gavel me-2"></i> Evaluation Review';
    document.getElementById('btnRevoke').style.display = data.isDecided ? 'inline-block' : 'none';
    var prevNotice = '';
    if (data.isDecided) {
        var bdg = data.hrStatus === 'approved' ? '<span style="color:#28a745;font-weight:bold;">Approved</span>' : '<span style="color:#dc3545;font-weight:bold;">Rejected</span>';
        prevNotice = '<div class="notice-box"><strong>Note:</strong> Currently marked as ' + bdg + '. You can update the decision below.</div>';
    }
    var prevMode = (data.mode && data.mode.trim() !== '' && data.mode !== '—') ? data.mode.trim() : 'Mentor-Led';

    document.getElementById('decisionArea').innerHTML = prevNotice +
        '<div class="decisionBtns"><button type="button" class="decisionBtnApprove" id="btnApprove" onclick="selectDecision(\'approved\')"><i class="fa-solid fa-check"></i> Approve Candidate</button><button type="button" class="decisionBtnReject" id="btnReject" onclick="selectDecision(\'rejected\')"><i class="fa-solid fa-times"></i> Reject Candidate</button></div>' +
        '<div id="approveCommentGroup" style="display:none;">' +
            '<div class="form-group mb-3">' +
                '<label style="font-weight:600;font-size:0.85rem;color:#333;"><i class="fa-solid fa-graduation-cap text-primary me-1"></i> Internship Mode / Recommendation <span style="color:red;">*</span></label>' +
                '<select id="formInterviewMode" class="form-control" style="border-radius:6px; font-weight:600;">' +
                    '<option value="Mentor-Led">Mentor-Led</option>' +
                    '<option value="Free of Cost">Free of Cost</option>' +
                    '<option value="Stipend-based Internship">Stipend-based Internship</option>' +
                    '<option value="Not Selected">Not Selected</option>' +
                '</select>' +
                '<small class="text-muted" style="font-size:0.75rem;">(Pre-selected with previous mode: <strong>' + prevMode + '</strong>)</small>' +
            '</div>' +
            '<div class="fee-confirm-box mb-3"><label><input type="checkbox" id="feeConfirmCheck"> I confirm the fee acceptance and approve to Onboard <span style="color:red;">*</span></label></div>' +
            '<div class="form-group"><label style="font-weight:600;font-size:0.85rem;">Comments <span style="font-weight:400;color:#888;">(optional)</span></label><textarea id="formHrCommentsApprove" class="form-control" rows="3" placeholder="Add any notes..." style="border-radius:6px;"></textarea></div>' +
        '</div>' +
        '<div id="remarkGroup" style="display:none;"><div class="form-group"><label style="font-weight:600;font-size:0.85rem;">Reason for Rejection <span style="color:red;">*</span></label><textarea id="formHrComments" class="form-control" rows="3" placeholder="Please provide a reason for rejection..." style="border-radius:6px;"></textarea></div></div>';
    
    var modeSelect = document.getElementById('formInterviewMode');
    if (modeSelect) {
        var hasOpt = false;
        for (var i = 0; i < modeSelect.options.length; i++) {
            if (modeSelect.options[i].value === prevMode) { hasOpt = true; break; }
        }
        if (!hasOpt) {
            var newOpt = document.createElement('option');
            newOpt.value = prevMode;
            newOpt.textContent = prevMode;
            modeSelect.appendChild(newOpt);
        }
        modeSelect.value = prevMode;
    }

    if (data.hrStatus === 'approved' || data.hrStatus === 'rejected') {
        selectDecision(data.hrStatus);
        if (data.hrStatus === 'approved') { document.getElementById('feeConfirmCheck').checked = true; document.getElementById('formHrCommentsApprove').value = data.hrComments || ''; }
        else { document.getElementById('formHrComments').value = data.hrComments || ''; }
    }
    document.getElementById('previewModal').classList.add('active');
}
 
function selectDecision(status) {
    selectedStatus = status;
    document.getElementById('btnApprove').classList.toggle('selected', status === 'approved');
    document.getElementById('btnReject').classList.toggle('selected',  status === 'rejected');
    document.getElementById('remarkGroup').style.display         = status === 'rejected' ? 'block' : 'none';
    document.getElementById('approveCommentGroup').style.display = status === 'approved' ? 'block' : 'none';
}
 
function revokeDecision() {
    Swal.fire({ icon: 'warning', title: 'Revoke Decision?', text: 'This will reset the evaluation back to Pending status.', showCancelButton: true, confirmButtonColor: '#d33', cancelButtonColor: '#aaa', confirmButtonText: 'Yes, Revoke' }).then(function (result) {
        if (result.isConfirmed) {
            var form = document.createElement('form');
            form.method = 'POST'; form.action = 'technicalEvaluationHr.php';
            [['evalId', currentEvalId], ['cancelDecision', '1']].forEach(function (pair) { var inp = document.createElement('input'); inp.type = 'hidden'; inp.name = pair[0]; inp.value = pair[1]; form.appendChild(inp); });
            document.body.appendChild(form); form.submit();
        }
    });
}
 
function submitDecision() {
    if (!selectedStatus) { Swal.fire({ icon: 'warning', title: 'Select a Decision', text: 'Please select Approve or Reject before saving.', confirmButtonColor: '#2f55ff' }); return; }
    var capturedEvalId = currentEvalId, capturedStatus = selectedStatus, hrComments = '', confirmedMode = '';
    if (capturedStatus === 'rejected') {
        hrComments = document.getElementById('formHrComments').value.trim();
        if (!hrComments) { Swal.fire({ icon: 'error', title: 'Reason Required', text: 'Please provide a reason for rejection.', confirmButtonColor: '#2f55ff' }); return; }
    } else {
        var feeConfirm = document.getElementById('feeConfirmCheck');
        if (feeConfirm && !feeConfirm.checked) { Swal.fire({ icon: 'error', title: 'Fee Confirmation Required', text: 'You must confirm the fee acceptance to approve and onboard the candidate.', confirmButtonColor: '#2f55ff' }); return; }
        var modeEl = document.getElementById('formInterviewMode');
        confirmedMode = modeEl ? modeEl.value : '';
        if (!confirmedMode) { Swal.fire({ icon: 'error', title: 'Interview Mode Required', text: 'Please select an Interview Mode.', confirmButtonColor: '#2f55ff' }); return; }
        var approveEl = document.getElementById('formHrCommentsApprove');
        hrComments = approveEl ? approveEl.value.trim() : '';
    }
    document.getElementById('previewModal').classList.remove('active');
    Swal.fire({ icon: capturedStatus === 'approved' ? 'question' : 'warning', title: 'Confirm ' + (capturedStatus === 'approved' ? 'Approval' : 'Rejection'), text: 'You are about to save this decision.', showCancelButton: true, confirmButtonColor: capturedStatus === 'approved' ? '#28a745' : '#dc3545', cancelButtonColor: '#aaa', confirmButtonText: capturedStatus === 'approved' ? 'Yes, Approve' : 'Yes, Reject' }).then(function (result) {
        if (result.isConfirmed) {
            var form = document.createElement('form'); form.method = 'POST'; form.action = 'technicalEvaluationHr.php';
            [['evalId', capturedEvalId], ['hrStatus', capturedStatus], ['hrComments', hrComments], ['confirmedMode', confirmedMode]].forEach(function (pair) { var inp = document.createElement('input'); inp.type = 'hidden'; inp.name = pair[0]; inp.value = pair[1]; form.appendChild(inp); });
            document.body.appendChild(form); form.submit();
        } else { document.getElementById('previewModal').classList.add('active'); }
    });
}
 
function closeModal() { document.getElementById('previewModal').classList.remove('active'); currentEvalId = null; selectedStatus = null; }
document.getElementById('previewModal').addEventListener('click', function (e) { if (e.target === this) closeModal(); });

function formatDate(dateStr) {
    if (!dateStr) return '—';
    var date = new Date(dateStr);
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return date.getDate() + ' ' + months[date.getMonth()] + ' ' + date.getFullYear();
}
</script>
</body>
</html>