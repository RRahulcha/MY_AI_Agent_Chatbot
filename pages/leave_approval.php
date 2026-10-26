<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

$userRole    = strtolower($_SESSION['userRole'] ?? '');
$designation = strtolower($_SESSION['designation'] ?? '');

$isJuniorIntern = ($userRole === 'junior_intern') || ($designation === 'juniorintern');

if ($isJuniorIntern) {
    echo "<script>alert('Unauthorized access. You do not have permissions to approve leaves.'); window.location.href = 'index.php';</script>";
    exit();
}

include('../include/db_config.php');
require_once('../include/notification_helper.php');

// ══════════════════════════════════════════════════════════
// AUTO-EXPIRE: Mark pending leaves older than 5 days ago
// ══════════════════════════════════════════════════════════
$today       = date('Y-m-d');
$fiveDaysAgo = date('Y-m-d', strtotime('-5 days'));
mysqli_query($con, "
    UPDATE leaves
    SET    status       = 'Expired',
           updated_date = NOW()
    WHERE  status    = 'Pending'
      AND  leave_type != 'Auto'
      AND  to_date   < '$fiveDaysAgo'
");

function getLeaveTypeFullForm($code) {
    $t = ['CL'=>'Casual Leave','SL'=>'Sick Leave','PL'=>'Privilege / Earned Leave','TD'=>'Tour Day','RO'=>'Rostering Off','IL'=>'Interview Leave'];
    return $t[$code] ?? $code;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $leaveId   = mysqli_real_escape_string($con, $_POST['leave_id']);
    $rawAction = $_POST['action'];
    $reason    = isset($_POST['reason']) ? mysqli_real_escape_string($con, $_POST['reason']) : NULL;
    $balanceLeaves = ['SL','CL','PL','TD','RO'];
    $approverId = $_SESSION['userId'];

    if ($rawAction === 'cancel') {
        $action = 'Cancelled';
        $lr = mysqli_query($con, "SELECT userId, status, leave_type, from_date, to_date FROM leaves WHERE leave_id='$leaveId'");
        if ($lr && $ld = mysqli_fetch_assoc($lr)) {
            // Calculate day span excluding Sundays
            $days = 0;
            $s = strtotime($ld['from_date']);
            $e = strtotime($ld['to_date']);
            for ($d = $s; $d <= $e; $d += 86400) {
                if (date('N', $d) != 7) $days++;
            }
            if ($days < 1) $days = 1;

            // Refund balance if leave was previously approved
            if ($ld['status'] === 'Approved' && in_array($ld['leave_type'], $balanceLeaves)) {
                $uid = mysqli_real_escape_string($con, $ld['userId']);
                $lt  = mysqli_real_escape_string($con, $ld['leave_type']);
                mysqli_query($con, "UPDATE signup SET `$lt` = `$lt` + $days WHERE userId='$uid'");
            }
            mysqli_query($con, "UPDATE leaves SET status='Cancelled', approved_by='$approverId', updated_date=NOW(), rejection_reason=" . ($reason ? "'$reason'" : "NULL") . " WHERE leave_id='$leaveId'");
            if (!empty($ld['userId'])) {
                notifyEmployee($con, $ld['userId'], 'Cancelled', 0);
            }
        }
    } else {
        $action = ($rawAction === 'approve') ? 'Approved' : 'Rejected';
        if ($action === 'Approved') {
            $lr = mysqli_query($con, "SELECT userId, leave_type, from_date, to_date FROM leaves WHERE leave_id='$leaveId'");
            if ($lr && $ld = mysqli_fetch_assoc($lr)) {
                $days = 0;
                $s = strtotime($ld['from_date']);
                $e = strtotime($ld['to_date']);
                for ($d = $s; $d <= $e; $d += 86400) {
                    if (date('N', $d) != 7) $days++;
                }
                if ($days < 1) $days = 1;

                if (in_array($ld['leave_type'], $balanceLeaves)) {
                    $uid = mysqli_real_escape_string($con, $ld['userId']);
                    $lt  = mysqli_real_escape_string($con, $ld['leave_type']);
                    mysqli_query($con, "UPDATE signup SET `$lt` = GREATEST(0, `$lt` - $days) WHERE userId='$uid'");
                }
            }
        }
        mysqli_query($con, "UPDATE leaves SET status='$action', approved_by='$approverId', updated_date=NOW(), rejection_reason=" . ($action==='Rejected' ? "'$reason'" : "NULL") . " WHERE leave_id='$leaveId'");
        $leaveQuery = mysqli_query($con, "SELECT userId FROM leaves WHERE leave_id='$leaveId'");
        if ($leaveQuery && $leaveData = mysqli_fetch_assoc($leaveQuery)) {
            notifyEmployee($con, $leaveData['userId'], $action, 0);
        }
    }

    $_SESSION['status'] = true;
    $_SESSION['msg']    = "Leave application has been $action successfully.";
    echo "<script>window.location.href = 'leave_approval.php';</script>"; 
    exit;
}

// Calculate KPI Analytics
$approverId = $_SESSION['userId'];
$isAdminOrHr = ($userRole === 'admin' || $userRole === 'hr' || $designation === 'admin' || $designation === 'hr' || $userRole === 'resourceallocator');

$whereClause = $isAdminOrHr 
    ? "WHERE l.leave_type!='Auto'" 
    : "WHERE l.leave_type!='Auto' AND (l.userId='$approverId' OR l.approved_by='$approverId')";

$kpiQ = mysqli_query($con, "
    SELECT 
        COUNT(*) AS totalLeaves,
        SUM(CASE WHEN l.status = 'Pending' THEN 1 ELSE 0 END) AS pendingCount,
        SUM(CASE WHEN l.status = 'Approved' THEN 1 ELSE 0 END) AS approvedCount,
        SUM(CASE WHEN l.status IN ('Rejected','Cancelled') THEN 1 ELSE 0 END) AS rejectedCount,
        SUM(CASE WHEN l.status = 'Approved' THEN (DATEDIFF(l.to_date, l.from_date) + 1) ELSE 0 END) AS approvedDays
    FROM leaves l
    $whereClause
");
$kpi = $kpiQ ? mysqli_fetch_assoc($kpiQ) : [];
$pendingCount  = (int)($kpi['pendingCount'] ?? 0);
$approvedCount = (int)($kpi['approvedCount'] ?? 0);
$rejectedCount = (int)($kpi['rejectedCount'] ?? 0);
$approvedDays  = (int)($kpi['approvedDays'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Leave Approvals & Management</title>
    <?php include('../partials/_links.php'); ?>
    <style>
        .card { border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 2px 10px rgba(0,0,0,0.03); }
        .card-title { font-size: 1.15rem; font-weight: 700; color: #1e293b; }
        .form-control { border-radius: 8px; border: 1px solid #cbd5e1; padding: 10px 14px; font-size: 0.88rem; }
        
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

        .badge-pending { background: #FEF3C7; color: #92400E; border: 1px solid #FCD34D; }
        .badge-approved { background: #D1FAE5; color: #065F46; border: 1px solid #6EE7B7; }
        .badge-rejected { background: #FEE2E2; color: #991B1B; border: 1px solid #FCA5A5; }
        .badge-cancelled { background: #F1F5F9; color: #475569; border: 1px solid #CBD5E1; }
        .badge-expired { background: #E2E8F0; color: #64748B; border: 1px solid #CBD5E1; }

        #leavependingTable thead th, #processedTable thead th {
            background: #f8fafc; color: #475569; font-weight: 700;
            font-size: 0.78rem; text-transform: uppercase; border-bottom: 2px solid #e2e8f0;
        }

        /* Document Overlay */
        .doc-preview-overlay {
            position: fixed; inset: 0; background: rgba(0,0,0,0.8);
            display: flex; justify-content: center; align-items: center; z-index: 99999;
        }
        .doc-preview-content {
            position: relative; width: 85%; height: 85%; background: #fff; border-radius: 12px;
            padding: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        .doc-preview-close {
            position: absolute; top: -12px; right: -12px; background: #ef4444; color: #fff;
            border: none; border-radius: 50%; width: 32px; height: 32px; font-size: 16px;
            cursor: pointer; display: flex; align-items: center; justify-content: center;
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.4.1/css/responsive.bootstrap4.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
                <div class="content-wrapper" style="background:#f4f6fb; padding: 24px;">

                    <!-- Page Title & Header -->
                    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
                        <div>
                            <h3 class="fw-bold text-dark mb-1">
                                <i class="fa-solid fa-clipboard-check text-primary me-2"></i> Leave Approvals & Management
                            </h3>
                            <p class="text-muted small mb-0">Review pending leave applications, approve or reject requests, and audit historical leave records.</p>
                        </div>
                    </div>

                    <!-- ── 1. KPI Analytics Summary Dashboard ── -->
                    <div class="row mb-4">
                        <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-lbl">Pending Review</div>
                                    <div class="kpi-val text-warning" style="color:#b06000 !important;"><?= $pendingCount ?></div>
                                    <small class="text-muted" style="font-size:0.72rem;">Requires your action</small>
                                </div>
                                <div class="kpi-icon" style="background:#FEF7E0; color:#B06000;">
                                    <i class="fa-solid fa-clock"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-lbl">Approved Leaves</div>
                                    <div class="kpi-val text-success"><?= $approvedCount ?></div>
                                    <small class="text-muted" style="font-size:0.72rem;">Granted applications</small>
                                </div>
                                <div class="kpi-icon" style="background:#E6F4EA; color:#1E8E3E;">
                                    <i class="fa-solid fa-circle-check"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-lbl">Rejected / Cancelled</div>
                                    <div class="kpi-val text-danger"><?= $rejectedCount ?></div>
                                    <small class="text-muted" style="font-size:0.72rem;">Declined requests</small>
                                </div>
                                <div class="kpi-icon" style="background:#FCE8E6; color:#C5221F;">
                                    <i class="fa-solid fa-circle-xmark"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-lbl">Approved Days Off</div>
                                    <div class="kpi-val text-info" style="color:#0284C7 !important;"><?= $approvedDays ?> <span style="font-size:0.9rem;">days</span></div>
                                    <small class="text-muted" style="font-size:0.72rem;">Total days granted</small>
                                </div>
                                <div class="kpi-icon" style="background:#E0F2FE; color:#0284C7;">
                                    <i class="fa-solid fa-plane-departure"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── 2. Pending Applications Table Card ── -->
                    <div class="card mb-4">
                        <div class="card-body p-4">
                            <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                                <div>
                                    <h4 class="card-title mb-0">
                                        <i class="fa-solid fa-clock-rotate-left text-warning me-2"></i> Pending Leave Applications
                                    </h4>
                                    <p class="text-muted small mb-0">Applications requiring review and authorization.</p>
                                </div>
                            </div>
                            <hr class="text-muted opacity-25 mb-4">

                            <div class="table-responsive">
                                <table class="table table-hover align-middle w-100" id="leavependingTable" style="font-size:0.85rem;">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Applicant Name</th>
                                            <th>Leave Type</th>
                                            <th>Duration</th>
                                            <th>Reason</th>
                                            <th>Task Handover</th>
                                            <?php if ($isAdminOrHr): ?><th>Interview Leave</th><?php endif; ?>
                                            <th>Medical Doc</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        if ($isAdminOrHr) {
                                            $query = "SELECT l.leave_id, l.leave_type, l.from_date, l.to_date, l.reason, l.created_date, l.interview_leave, l.document_path, a.fullName AS applicant_name, h.fullName AS handover_to_name
                                                      FROM leaves l LEFT JOIN signup a ON l.userId=a.userId LEFT JOIN signup h ON l.informed_colleagues=h.userId
                                                      WHERE l.status='Pending' AND l.leave_type!='Auto' ORDER BY l.created_date DESC";
                                        } else {
                                            $query = "SELECT l.leave_id, l.leave_type, l.from_date, l.to_date, l.reason, l.created_date, l.document_path, l.approved_by, a.fullName AS applicant_name, h.fullName AS handover_to_name
                                                      FROM leaves l LEFT JOIN signup a ON l.userId=a.userId LEFT JOIN signup h ON l.informed_colleagues=h.userId
                                                      WHERE l.status='Pending' AND l.leave_type!='Auto' AND (l.userId='$approverId' OR l.approved_by='$approverId') ORDER BY l.created_date DESC";
                                        }

                                        $result = mysqli_query($con, $query);
                                        $sr = 1;
                                        if ($result && mysqli_num_rows($result) > 0) {
                                            while ($row = mysqli_fetch_assoc($result)) {
                                                $handover = !empty($row['handover_to_name']) ? htmlspecialchars($row['handover_to_name']) : 'N/A';
                                                $fromDateStr = !empty($row['from_date']) ? date('d M Y', strtotime($row['from_date'])) : '-';
                                                $toDateStr   = !empty($row['to_date']) ? date('d M Y', strtotime($row['to_date'])) : '-';

                                                $fromTs = strtotime($row['from_date']);
                                                $toTs   = strtotime($row['to_date']);
                                                $daysCount = floor(($toTs - $fromTs) / 86400) + 1;

                                                $durationHtml = '<div><strong class="text-dark">' . $fromDateStr . '</strong> to <strong class="text-dark">' . $toDateStr . '</strong></div>';
                                                $durationHtml .= '<small class="text-primary fw-bold" style="font-size:0.75rem;"><i class="fa-solid fa-calendar-day me-1"></i>' . $daysCount . ($daysCount == 1 ? ' Day' : ' Days') . '</small>';

                                                $docBtn = '<span class="text-muted" style="font-style:italic;font-size:.8rem;">—</span>';
                                                if (!empty($row['document_path'])) {
                                                    $docUrl = htmlspecialchars($row['document_path'], ENT_QUOTES);
                                                    $docBtn = "<button onclick=\"viewDocument('{$docUrl}')\" class='btn btn-outline-info btn-sm px-2 py-1' style='border-radius:6px;'><i class='fa-solid fa-file-medical me-1'></i> View</button>";
                                                }

                                                $leaveName = getLeaveTypeFullForm($row['leave_type']);
                                                $interviewBadge = '—';
                                                if ($isAdminOrHr) {
                                                    $isInterview = ($row['interview_leave'] == 1 || strtolower((string)$row['interview_leave']) === 'yes');
                                                    $interviewBadge = $isInterview ? '<span class="badge bg-warning text-dark px-2 py-1"><i class="fa-solid fa-user-graduate me-1"></i> Yes</span>' : '<span class="badge bg-light text-muted border px-2 py-1">No</span>';
                                                }
                                        ?>
                                                <tr>
                                                    <td class="fw-bold text-secondary"><?= $sr++ ?></td>
                                                    <td><strong class="text-dark"><?= htmlspecialchars($row['applicant_name'] ?? 'N/A') ?></strong></td>
                                                    <td>
                                                        <span class="badge bg-light text-dark border px-2 py-1" style="font-size:0.8rem; font-weight:600;">
                                                            <?= htmlspecialchars($leaveName) ?>
                                                        </span>
                                                    </td>
                                                    <td><?= $durationHtml ?></td>
                                                    <td style="max-width:180px; word-break:break-word;"><?= htmlspecialchars($row['reason'] ?? '') ?></td>
                                                    <td><strong class="text-secondary"><?= $handover ?></strong></td>
                                                    <?php if ($isAdminOrHr): ?><td><?= $interviewBadge ?></td><?php endif; ?>
                                                    <td><?= $docBtn ?></td>
                                                    <td>
                                                        <div class="d-flex align-items-center gap-1">
                                                            <button onclick="confirmAction('<?= $row['leave_id'] ?>','approve')" class="btn btn-success text-white btn-sm px-2 py-1" style="border-radius:6px; font-weight:600;">
                                                                <i class="fa-solid fa-check me-1"></i> Approve
                                                            </button>
                                                            <button onclick="confirmAction('<?= $row['leave_id'] ?>','reject')" class="btn btn-danger text-white btn-sm px-2 py-1" style="border-radius:6px; font-weight:600;">
                                                                <i class="fa-solid fa-xmark me-1"></i> Reject
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                        <?php 
                                            }
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </div>

                        </div>
                    </div>

                    <!-- ── 3. Processed Applications Table Card ── -->
                    <div class="card">
                        <div class="card-body p-4">
                            <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                                <div>
                                    <h4 class="card-title mb-0">
                                        <i class="fa-solid fa-box-archive text-primary me-2"></i> Processed Applications Audit
                                    </h4>
                                    <p class="text-muted small mb-0">History of approved, rejected, cancelled, and expired requests.</p>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <button id="exportExcel" class="btn btn-success text-white btn-sm px-3 py-2" style="border-radius:6px;" title="Export to Excel" onclick="exportTableToExcel()">
                                        <i class="fa-solid fa-file-excel me-1"></i> Excel
                                    </button>
                                    <button id="downloadPdf" class="btn btn-danger text-white btn-sm px-3 py-2" style="border-radius:6px;" title="Download as PDF" onclick="exportTableToPDF()">
                                        <i class="fa-solid fa-file-pdf me-1"></i> PDF
                                    </button>
                                </div>
                            </div>
                            <hr class="text-muted opacity-25 mb-4">

                            <div class="table-responsive">
                                <table class="table table-hover align-middle w-100" id="processedTable" style="font-size:0.85rem;">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Applicant Name</th>
                                            <th>Leave Type</th>
                                            <th>Duration</th>
                                            <th>Processed On</th>
                                            <th>Status</th>
                                            <th>Rejection / Cancel Reason</th>
                                            <th>Document</th>
                                            <?php if ($isAdminOrHr): ?><th>Approved By</th><th>Action</th><?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        if ($isAdminOrHr) {
                                            $pq = "SELECT l.leave_id, l.leave_type, l.from_date, l.to_date, l.status, l.updated_date, l.rejection_reason, l.document_path, a.fullName AS applicant_name, ap.fullName AS approver_name
                                                   FROM leaves l LEFT JOIN signup a ON l.userId=a.userId LEFT JOIN signup ap ON l.approved_by=ap.userId
                                                   WHERE l.status IN ('Approved','Rejected','Expired','Cancelled') ORDER BY l.updated_date DESC";
                                        } else {
                                            $pq = "SELECT l.leave_id, l.leave_type, l.from_date, l.to_date, l.status, l.updated_date, l.rejection_reason, l.document_path, l.approved_by, a.fullName AS applicant_name, ap.fullName AS approver_name
                                                   FROM leaves l LEFT JOIN signup a ON l.userId=a.userId LEFT JOIN signup ap ON l.approved_by=ap.userId
                                                   WHERE l.status IN ('Approved','Rejected','Cancelled','Expired') AND (l.userId='$approverId' OR l.approved_by='$approverId') ORDER BY l.updated_date DESC";
                                        }

                                        $pr = mysqli_query($con, $pq);
                                        $srNo = 1;
                                        if ($pr && mysqli_num_rows($pr) > 0) {
                                            while ($row = mysqli_fetch_assoc($pr)) {
                                                $s = $row['status'];

                                                if ($s === 'Approved') {
                                                    $badge = '<span class="badge badge-approved px-2 py-1"><i class="fa-solid fa-check me-1"></i> Approved</span>';
                                                } elseif ($s === 'Rejected') {
                                                    $badge = '<span class="badge badge-rejected px-2 py-1"><i class="fa-solid fa-xmark me-1"></i> Rejected</span>';
                                                } elseif ($s === 'Expired') {
                                                    $badge = '<span class="badge badge-expired px-2 py-1"><i class="fa-solid fa-hourglass-end me-1"></i> Expired</span>';
                                                } else {
                                                    $badge = '<span class="badge badge-cancelled px-2 py-1"><i class="fa-solid fa-ban me-1"></i> ' . htmlspecialchars($s) . '</span>';
                                                }

                                                $reason = ($s === 'Rejected' && !empty($row['rejection_reason']))
                                                    ? '<span class="text-danger fw-bold">' . htmlspecialchars($row['rejection_reason']) . '</span>'
                                                    : '<span class="text-muted" style="font-style:italic;">—</span>';

                                                if ($s === 'Expired') {
                                                    $reason = '<span class="text-warning fw-bold" style="color:#856404 !important;">Leave period passed without approval</span>';
                                                }

                                                $fromDateStr = !empty($row['from_date']) ? date('d M Y', strtotime($row['from_date'])) : '-';
                                                $toDateStr   = !empty($row['to_date']) ? date('d M Y', strtotime($row['to_date'])) : '-';

                                                $fromTs = strtotime($row['from_date']);
                                                $toTs   = strtotime($row['to_date']);
                                                $daysCount = floor(($toTs - $fromTs) / 86400) + 1;

                                                $durationHtml = '<div><strong class="text-dark">' . $fromDateStr . '</strong> to <strong class="text-dark">' . $toDateStr . '</strong></div>';
                                                $durationHtml .= '<small class="text-primary fw-bold" style="font-size:0.75rem;"><i class="fa-solid fa-calendar-day me-1"></i>' . $daysCount . ($daysCount == 1 ? ' Day' : ' Days') . '</small>';

                                                $updatedDate = !empty($row['updated_date']) ? date('d M Y, h:i A', strtotime($row['updated_date'])) : '-';

                                                $docBtn = '<span class="text-muted" style="font-style:italic;">—</span>';
                                                if (!empty($row['document_path'])) {
                                                    $docUrl = htmlspecialchars($row['document_path'], ENT_QUOTES);
                                                    $docBtn = "<button onclick=\"viewDocument('{$docUrl}')\" class='btn btn-outline-info btn-sm px-2 py-1' style='border-radius:6px;'><i class='fa-solid fa-file-medical me-1'></i> View</button>";
                                                }

                                                $ltTag = '<span class="badge bg-light text-dark border px-2 py-1" style="font-size:0.8rem; font-weight:600;">' . getLeaveTypeFullForm($row['leave_type']) . '</span>';
                                        ?>
                                                <tr>
                                                    <td class="fw-bold text-secondary"><?= $srNo++ ?></td>
                                                    <td><strong class="text-dark"><?= htmlspecialchars($row['applicant_name'] ?? 'N/A') ?></strong></td>
                                                    <td><?= $ltTag ?></td>
                                                    <td><?= $durationHtml ?></td>
                                                    <td class="text-muted" style="font-size:0.78rem;"><?= $updatedDate ?></td>
                                                    <td><?= $badge ?></td>
                                                    <td style="max-width:180px; word-break:break-word;"><?= $reason ?></td>
                                                    <td><?= $docBtn ?></td>
                                                    <?php if ($isAdminOrHr): ?>
                                                        <td><strong class="text-secondary"><?= !empty($row['approver_name']) ? htmlspecialchars($row['approver_name']) : '—' ?></strong></td>
                                                        <td>
                                                            <?php if ($s === 'Approved'): ?>
                                                                <button onclick="confirmAction('<?= $row['leave_id'] ?>','cancel')" class="btn btn-outline-danger btn-sm px-2 py-1" style="border-radius:6px; font-size:0.78rem;">
                                                                    <i class="fa-solid fa-ban me-1"></i> Cancel Leave
                                                                </button>
                                                            <?php else: ?>
                                                                <span class="text-muted" style="font-style:italic;">—</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    <?php endif; ?>
                                                </tr>
                                        <?php 
                                            }
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </div>

                        </div>
                    </div>

                </div> <!-- Content Wrapper -->
            </div> <!-- Main Panel -->

        </div>
    </div>

    <!-- Hidden POST Form -->
    <form id="actionForm" method="POST" action="leave_approval.php">
        <input type="hidden" name="leave_id" id="form_leave_id">
        <input type="hidden" name="action" id="form_action">
        <input type="hidden" name="reason" id="form_reason">
    </form>

    <!-- Scripts -->
    <script src="../vendors/js/vendor.bundle.base.js"></script>
    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>
    <script src="../js/settings.js"></script>

    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.17.0/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.4.0/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.23/jspdf.plugin.autotable.min.js"></script>

    <script>
        $(document).ready(function() {
            $('#leavependingTable').DataTable({
                responsive: true,
                order: [],
                language: {
                    search: "Search Pending:",
                    emptyTable: "No pending leave applications requiring action"
                }
            });

            $('#processedTable').DataTable({
                responsive: true,
                order: [],
                language: {
                    search: "Search History:",
                    emptyTable: "No processed leave records found"
                }
            });
        });

        function confirmAction(leaveId, action) {
            let title = action === 'approve' ? 'Approve Leave Application?' : (action === 'reject' ? 'Reject Leave Application?' : 'Cancel Approved Leave?');
            let confirmText = action === 'approve' ? 'Yes, Approve' : (action === 'reject' ? 'Yes, Reject' : 'Yes, Cancel');
            let confirmColor = action === 'approve' ? '#1E8E3E' : '#C5221F';

            if (action === 'reject' || action === 'cancel') {
                Swal.fire({
                    title: title,
                    text: 'Please provide a reason:',
                    input: 'textarea',
                    inputPlaceholder: 'Enter reason here...',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: confirmColor,
                    confirmButtonText: confirmText,
                    inputValidator: (value) => {
                        if (!value || !value.trim()) {
                            return 'Reason is required!';
                        }
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        $('#form_leave_id').val(leaveId);
                        $('#form_action').val(action);
                        $('#form_reason').val(result.value);
                        $('#actionForm').submit();
                    }
                });
            } else {
                Swal.fire({
                    title: title,
                    text: 'Are you sure you want to approve this leave request?',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: confirmColor,
                    confirmButtonText: confirmText
                }).then((result) => {
                    if (result.isConfirmed) {
                        $('#form_leave_id').val(leaveId);
                        $('#form_action').val(action);
                        $('#form_reason').val('');
                        $('#actionForm').submit();
                    }
                });
            }
        }

        function viewDocument(url) {
            const overlay = document.createElement('div');
            overlay.className = 'doc-preview-overlay';
            const content = document.createElement('div');
            content.className = 'doc-preview-content';
            const closeBtn = document.createElement('button');
            closeBtn.className = 'doc-preview-close';
            closeBtn.innerHTML = '✕';
            closeBtn.onclick = () => document.body.removeChild(overlay);

            if (url.toLowerCase().endsWith('.pdf')) {
                const iframe = document.createElement('iframe');
                iframe.src = url;
                iframe.style.cssText = 'width:100%; height:100%; border:none; border-radius:8px;';
                content.appendChild(closeBtn);
                content.appendChild(iframe);
            } else {
                const img = document.createElement('img');
                img.src = url;
                img.style.cssText = 'max-width:100%; max-height:100%; display:block; margin:auto; border-radius:8px;';
                content.appendChild(closeBtn);
                content.appendChild(img);
            }

            overlay.onclick = (e) => { if (e.target === overlay) document.body.removeChild(overlay); };
            overlay.appendChild(content);
            document.body.appendChild(overlay);
        }

        function exportTableToExcel() {
            var tableClone = document.createElement("table");
            var headerRow = tableClone.insertRow();
            var headers = ["Applicant Name", "Leave Type", "Duration", "Processed On", "Status", "Reason"];
            headers.forEach(h => {
                var th = document.createElement("th");
                th.innerText = h;
                headerRow.appendChild(th);
            });

            var table = $('#processedTable').DataTable();
            table.rows().data().each(function(row) {
                var tr = tableClone.insertRow();
                tr.innerHTML = `
                    <td>${$(row[1]).text().trim()}</td>
                    <td>${$(row[2]).text().trim()}</td>
                    <td>${$(row[3]).text().trim()}</td>
                    <td>${$(row[4]).text().trim()}</td>
                    <td>${$(row[5]).text().trim()}</td>
                    <td>${$(row[6]).text().trim()}</td>
                `;
            });

            var workbook = XLSX.utils.table_to_book(tableClone, { sheet: "Processed Leaves Report" });
            XLSX.writeFile(workbook, 'Leave_Approvals_Report.xlsx');
        }

        async function exportTableToPDF() {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF('landscape');
            doc.text('Leave Approvals Audit Report', 14, 15);

            const tableData = [];
            var table = $('#processedTable').DataTable();
            table.rows().data().each(function(row) {
                tableData.push([
                    $(row[1]).text().trim(),
                    $(row[2]).text().trim(),
                    $(row[3]).text().trim(),
                    $(row[4]).text().trim(),
                    $(row[5]).text().trim(),
                    $(row[6]).text().trim()
                ]);
            });

            doc.autoTable({
                head: [["Applicant Name", "Leave Type", "Duration", "Processed On", "Status", "Reason"]],
                body: tableData,
                startY: 25,
            });

            doc.save('Leave_Approvals_Report.pdf');
        }
    </script>

    <?php
    if (isset($_SESSION['status'])) {
        toastMsg($_SESSION['status'] ? 'success' : 'error', $_SESSION['msg']);
        unset($_SESSION['status'], $_SESSION['msg']);
    }
    ?>
</body>
</html>