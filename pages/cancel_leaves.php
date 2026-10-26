<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

include('../include/db_config.php');

$today = date('Y-m-d');
$userId = $_SESSION['userId'];
$userRole = $_SESSION['userRole'] ?? '';

// Calculate KPI Analytics for the logged-in user
$kpiQ = mysqli_query($con, "
    SELECT 
        COUNT(*) AS totalLeaves,
        SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) AS approvedLeaves,
        SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) AS pendingLeaves,
        SUM(CASE WHEN status = 'Approved' THEN (DATEDIFF(to_date, from_date) + 1) ELSE 0 END) AS totalDaysOff
    FROM leaves 
    WHERE userId = '$userId'
      AND leave_name NOT LIKE '%Auto Leave%'
");
$kpi = $kpiQ ? mysqli_fetch_assoc($kpiQ) : [];
$totalLeaves    = (int)($kpi['totalLeaves'] ?? 0);
$approvedLeaves = (int)($kpi['approvedLeaves'] ?? 0);
$pendingLeaves  = (int)($kpi['pendingLeaves'] ?? 0);
$totalDaysOff   = (int)($kpi['totalDaysOff'] ?? 0);

// Fetch user leave balance quotas
$userBalQ = mysqli_query($con, "SELECT CL, SL, PL FROM signup WHERE userId='$userId'");
$userBal  = $userBalQ ? mysqli_fetch_assoc($userBalQ) : [];
$myCL = (int)($userBal['CL'] ?? 0);
$mySL = (int)($userBal['SL'] ?? 0);
$myPL = (int)($userBal['PL'] ?? 0);
$myTotalPaid = $myCL + $mySL + $myPL;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>My Leaves</title>
    <?php include('../partials/_links.php'); ?>
    <style>
        .card { border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 2px 10px rgba(0,0,0,0.03); }
        .card-title { font-size: 1.15rem; font-weight: 700; color: #1e293b; }
        .form-control { border-radius: 8px; border: 1px solid #cbd5e1; padding: 10px 14px; font-size: 0.88rem; }
        
        /* Custom Select Dropdown Styling */
        select.form-control, select.form-select {
            height: 42px !important;
            padding: 8px 36px 8px 14px !important;
            border-radius: 8px !important;
            border: 1px solid #cbd5e1 !important;
            background-color: #ffffff !important;
            color: #1e293b !important;
            font-size: 0.88rem !important;
            font-weight: 500 !important;
            appearance: none !important;
            -webkit-appearance: none !important;
            -moz-appearance: none !important;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%3c475569' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e") !important;
            background-repeat: no-repeat !important;
            background-position: right 14px center !important;
            background-size: 14px 10px !important;
            cursor: pointer;
        }

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

        #myLeavesTable thead th {
            background: #f8fafc; color: #475569; font-weight: 700;
            font-size: 0.78rem; text-transform: uppercase; border-bottom: 2px solid #e2e8f0;
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
                                <i class="fa-solid fa-calendar-days text-primary me-2"></i> My Leaves
                            </h3>
                            <p class="text-muted small mb-0">Track leave applications, approval statuses, leave balances, and cancel upcoming leaves.</p>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <a href="apply_leave.php" class="btn btn-primary px-3 py-2" style="border-radius:8px; font-weight:600;">
                                <i class="fa-solid fa-plus me-1"></i> Apply Leave
                            </a>
                        </div>
                    </div>

                    <!-- ── 1. KPI Analytics Summary Dashboard ── -->
                    <div class="row mb-4">
                        <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-lbl">Total Applications</div>
                                    <div class="kpi-val text-primary"><?= $totalLeaves ?></div>
                                    <small class="text-muted" style="font-size:0.72rem;">All time requests</small>
                                </div>
                                <div class="kpi-icon" style="background:#EEEDFE; color:#4b49ac;">
                                    <i class="fa-solid fa-paper-plane"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-lbl">Approved Leaves</div>
                                    <div class="kpi-val text-success"><?= $approvedLeaves ?></div>
                                    <small class="text-muted" style="font-size:0.72rem;">Granted leaves</small>
                                </div>
                                <div class="kpi-icon" style="background:#E6F4EA; color:#1E8E3E;">
                                    <i class="fa-solid fa-circle-check"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-lbl">Pending Review</div>
                                    <div class="kpi-val text-warning" style="color:#b06000 !important;"><?= $pendingLeaves ?></div>
                                    <small class="text-muted" style="font-size:0.72rem;">Awaiting manager approval</small>
                                </div>
                                <div class="kpi-icon" style="background:#FEF7E0; color:#B06000;">
                                    <i class="fa-solid fa-clock"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-lbl">Approved Days Off</div>
                                    <div class="kpi-val text-info" style="color:#0284C7 !important;"><?= $totalDaysOff ?> <span style="font-size:0.9rem;">days</span></div>
                                    <small class="text-muted" style="font-size:0.72rem;">Total leave duration</small>
                                </div>
                                <div class="kpi-icon" style="background:#E0F2FE; color:#0284C7;">
                                    <i class="fa-solid fa-plane-departure"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- FY Leave Entitlements Summary Card -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card p-3" style="background:#F8FAFC; border:1px solid #E2E8F0;">
                                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                                    <div class="fw-bold text-dark" style="font-size:0.95rem;">
                                        <i class="fa-solid fa-calendar-check text-primary me-2"></i> Financial Year Leave Entitlements (1 Aug – 31 Mar)
                                    </div>
                                    <a href="apply_leave.php" class="btn btn-primary btn-sm px-3 py-1 fw-bold" style="border-radius:6px;">+ Apply New Leave</a>
                                </div>
                                <div class="row text-center mt-3 g-2">
                                    <div class="col-md col-6">
                                        <div class="p-2 rounded bg-white border">
                                            <span class="text-muted small d-block">Casual Leave (CL)</span>
                                            <h5 class="fw-bold text-primary mb-0 mt-1"><?= $myCL ?> <span style="font-size:0.75rem; color:#64748B;">/ 4 Days</span></h5>
                                        </div>
                                    </div>
                                    <div class="col-md col-6">
                                        <div class="p-2 rounded bg-white border">
                                            <span class="text-muted small d-block">Sick Leave (SL)</span>
                                            <h5 class="fw-bold text-success mb-0 mt-1"><?= $mySL ?> <span style="font-size:0.75rem; color:#64748B;">/ 4 Days</span></h5>
                                        </div>
                                    </div>
                                    <div class="col-md col-6">
                                        <div class="p-2 rounded bg-white border">
                                            <span class="text-muted small d-block">Privilege Leave (PL/EL)</span>
                                            <h5 class="fw-bold text-warning mb-0 mt-1"><?= $myPL ?> <span style="font-size:0.75rem; color:#64748B;">/ 5 Days</span></h5>
                                        </div>
                                    </div>
                                    <div class="col-md col-6">
                                        <div class="p-2 rounded bg-white border">
                                            <span class="text-muted small d-block">Rostering Off (RO)</span>
                                            <?php
                                            $roUsedThisMonth = 0;
                                            $roCountQ = mysqli_query($con, "SELECT from_date, to_date FROM leaves WHERE userId = '$userId' AND leave_type = 'RO' AND status IN ('Approved', 'Pending')");
                                            if ($roCountQ) {
                                                while ($rcRow = mysqli_fetch_assoc($roCountQ)) {
                                                    $s = strtotime($rcRow['from_date']);
                                                    $e = strtotime($rcRow['to_date']);
                                                    for ($d = $s; $d <= $e; $d += 86400) {
                                                        if (date('Y-m', $d) === date('Y-m')) {
                                                            $roUsedThisMonth++;
                                                        }
                                                    }
                                                }
                                            }
                                            $roRemaining = max(0, 2 - $roUsedThisMonth);
                                            ?>
                                            <h5 class="fw-bold mb-0 mt-1" style="color:#DC2626;"><?= $roRemaining ?> <span style="font-size:0.75rem; color:#64748B;">/ 2 Days (Mth)</span></h5>
                                        </div>
                                    </div>
                                    <div class="col-md col-6">
                                        <div class="p-2 rounded bg-white border">
                                            <span class="text-muted small d-block">Total Paid Remaining</span>
                                            <h5 class="fw-bold mb-0 mt-1" style="color:#7C3AED;"><?= $myTotalPaid ?> <span style="font-size:0.75rem; color:#64748B;">/ 13 Days</span></h5>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── 2. My Leaves Directory Table Section ── -->
                    <div class="card">
                        <div class="card-body p-4">
                            <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                                <div class="d-flex align-items-center gap-3">
                                    <h4 class="card-title mb-0">
                                        <i class="fa-solid fa-list-check text-primary me-2"></i> My Leave Applications
                                    </h4>
                                    <!-- Leave Status Filter -->
                                    <div class="d-flex align-items-center gap-2">
                                        <label for="statusFilter" class="text-muted fw-bold mb-0" style="font-size:0.8rem;">Status Filter:</label>
                                        <select id="statusFilter" class="form-control" style="width:160px;">
                                            <option value="">All Statuses</option>
                                            <option value="Approved">Approved</option>
                                            <option value="Pending">Pending</option>
                                            <option value="Rejected">Rejected</option>
                                            <option value="Cancelled">Cancelled</option>
                                            <option value="Expired">Expired</option>
                                        </select>
                                    </div>
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
                                <table class="table table-hover align-middle w-100" id="myLeavesTable" style="font-size:0.85rem;">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Leave Type</th>
                                            <th>Leave Duration</th>
                                            <?php if ($userRole === 'junior_intern'): ?>
                                                <th>Interview Leave</th>
                                            <?php endif; ?>
                                            <th>Status</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $q1 = mysqli_query($con, "
                                            SELECT 
                                                t.id,
                                                t.leave_name,
                                                t.from_date,
                                                t.to_date,
                                                t.status,
                                                t.interview_leave
                                            FROM leaves t
                                            WHERE t.userId = '$userId'
                                              AND t.leave_name NOT LIKE '%Auto Leave%'
                                              AND NOT EXISTS (
                                                  SELECT 1
                                                  FROM attendance_regularization ar
                                                  WHERE ar.user_id COLLATE utf8mb4_0900_ai_ci = t.userId
                                                    AND ar.date BETWEEN t.from_date AND t.to_date
                                                    AND ar.status = 'approved'
                                              )
                                            ORDER BY t.from_date DESC
                                        ");

                                        $sr = 1;
                                        if ($q1) {
                                            while ($res = mysqli_fetch_assoc($q1)) {
                                                $leaveName = $res['leave_name'];
                                                if ($leaveName == "Sick Leave" || $leaveName == "SL") {
                                                    $leaveName = "Sick Leave";
                                                } elseif ($leaveName == "CL" || $leaveName == "Casual Leave") {
                                                    $leaveName = "Casual Leave";
                                                } elseif ($leaveName == "PL" || $leaveName == "Privilege / Earned Leave" || $leaveName == "Personal Leave") {
                                                    $leaveName = "Privilege / Earned Leave";
                                                } elseif ($leaveName == "RO") {
                                                    $leaveName = "Rostering Off";
                                                }

                                                // Dates & Duration
                                                $fromDateStr = !empty($res['from_date']) ? date('d M Y', strtotime($res['from_date'])) : '-';
                                                $toDateStr   = !empty($res['to_date']) ? date('d M Y', strtotime($res['to_date'])) : '-';

                                                $fromTs = strtotime($res['from_date']);
                                                $toTs   = strtotime($res['to_date']);
                                                $daysCount = floor(($toTs - $fromTs) / 86400) + 1;
                                                
                                                $durationHtml = '<div><strong class="text-dark">' . $fromDateStr . '</strong> to <strong class="text-dark">' . $toDateStr . '</strong></div>';
                                                $durationHtml .= '<small class="text-primary fw-bold" style="font-size:0.75rem;"><i class="fa-solid fa-calendar-day me-1"></i>' . $daysCount . ($daysCount == 1 ? ' Day' : ' Days') . '</small>';

                                                // Status & Action Logic
                                                $todayTs = strtotime($today);
                                                $isExpired = ($toTs < $todayTs);
                                                $status = $res['status'];

                                                if ($isExpired) {
                                                    $statusBadge = '<span class="badge badge-expired px-2 py-1"><i class="fa-solid fa-hourglass-end me-1"></i> Expired</span>';
                                                    $actionBtn = '<button class="btn btn-secondary btn-sm px-2 py-1" disabled style="opacity:0.6;"><i class="fa-solid fa-clock me-1"></i> Expired</button>';
                                                } elseif ($status === 'Cancelled') {
                                                    $statusBadge = '<span class="badge badge-cancelled px-2 py-1"><i class="fa-solid fa-ban me-1"></i> Cancelled</span>';
                                                    $actionBtn = '<span class="text-muted" style="font-size:0.8rem;">—</span>';
                                                } elseif ($status === 'Rejected') {
                                                    $statusBadge = '<span class="badge badge-rejected px-2 py-1"><i class="fa-solid fa-xmark me-1"></i> Rejected</span>';
                                                    $actionBtn = '<span class="text-muted" style="font-size:0.8rem;">—</span>';
                                                } elseif ($status === 'Approved') {
                                                    $statusBadge = '<span class="badge badge-approved px-2 py-1"><i class="fa-solid fa-check me-1"></i> Approved</span>';
                                                    $actionBtn = '<button type="button" class="btn btn-outline-danger btn-sm px-3 py-1" style="border-radius:6px; font-weight:600;" onclick="openLeavePopup(' . $res['id'] . ');"><i class="fa-solid fa-ban me-1"></i> Cancel Leave</button>';
                                                } else {
                                                    $statusBadge = '<span class="badge badge-pending px-2 py-1"><i class="fa-solid fa-clock me-1"></i> Pending</span>';
                                                    $actionBtn = '<button type="button" class="btn btn-outline-danger btn-sm px-3 py-1" style="border-radius:6px; font-weight:600;" onclick="openLeavePopup(' . $res['id'] . ');"><i class="fa-solid fa-ban me-1"></i> Cancel Leave</button>';
                                                }

                                                $interviewBadge = '—';
                                                if ($userRole === 'junior_intern') {
                                                    $isInterview = (!empty($res['interview_leave']) && ($res['interview_leave'] == 1 || strtolower($res['interview_leave']) == 'yes'));
                                                    $interviewBadge = $isInterview 
                                                        ? '<span class="badge bg-warning text-dark px-2 py-1"><i class="fa-solid fa-user-graduate me-1"></i> Yes</span>' 
                                                        : '<span class="badge bg-light text-muted border px-2 py-1">No</span>';
                                                }
                                        ?>
                                                <tr>
                                                    <td class="fw-bold text-secondary"><?= $sr++ ?></td>
                                                    <td>
                                                        <span class="badge bg-light text-dark border px-2 py-1" style="font-size:0.82rem; font-weight:600;">
                                                            <i class="fa-solid fa-user-clock me-1 text-primary"></i> <?= htmlspecialchars($leaveName) ?>
                                                        </span>
                                                    </td>
                                                    <td><?= $durationHtml ?></td>
                                                    <?php if ($userRole === 'junior_intern'): ?>
                                                        <td><?= $interviewBadge ?></td>
                                                    <?php endif; ?>
                                                    <td><?= $statusBadge ?></td>
                                                    <td><?= $actionBtn ?></td>
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
            var table = $('#myLeavesTable').DataTable({
                responsive: true,
                order: [],
                pageLength: 10,
                lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "All"]],
                language: {
                    search: "Filter Leaves:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries",
                    emptyTable: "No leave applications found"
                }
            });

            $('#statusFilter').on('change', function() {
                var val = $(this).val();
                table.column(<?php echo ($userRole === 'junior_intern') ? 4 : 3; ?>).search(val).draw();
            });
        });

        function openLeavePopup(id) {
            $.ajax({
                url: "getLeaveDates.php",
                type: "POST",
                data: { id: id },
                success: function(data) {
                    Swal.fire({
                        title: "Cancel Leave Application?",
                        html: data,
                        icon: "warning",
                        showCancelButton: true,
                        confirmButtonColor: "#dc3545",
                        cancelButtonColor: "#6c757d",
                        confirmButtonText: "Yes, Cancel Leave",
                        cancelButtonText: "Keep Leave"
                    }).then((result) => {
                        if (result.isConfirmed) {
                            cancelLeave(id);
                        }
                    });
                }
            });
        }

        function cancelLeave(id) {
        console.log("Cancelling Leave ID:", id); // Check karein ID sahi aa rahi hai ya undefined
            $.ajax({
                url: "cancelLeaveAjax.php",
                type: "POST",
                data: { id: id },
                success: function(res) {
                    if (res.trim() === "success") {
                        Swal.fire({
                            icon: "success",
                            title: "Leave Cancelled",
                            text: "Your leave application has been cancelled.",
                            timer: 1500,
                            showConfirmButton: false
                        });
                        setTimeout(() => location.reload(), 1600);
                    } else if (res.trim() === "expired") {
                        Swal.fire(
                            "Not Allowed",
                            "You cannot cancel a leave after the leave date has passed.",
                            "warning"
                        );
                    } else {
                        Swal.fire("Error", "Something went wrong while cancelling leave.", "error");
                    }
                }
            });
        }

        function cancelLeaveSingle(dateStr, id) {
            $.ajax({
                url: "cancelSingleDay.php",
                type: "POST",
                data: { id: id, date: dateStr },
                success: function(res) {
                    if (res.trim() === "success") {
                        Swal.fire({
                            icon: "success",
                            title: "Date Cancelled",
                            text: "Leave for " + dateStr + " has been cancelled.",
                            timer: 1500,
                            showConfirmButton: false
                        });
                        setTimeout(() => location.reload(), 1600);
                    } else {
                        Swal.fire("Error", "Could not cancel leave for selected date.", "error");
                    }
                }
            });
        }

        function exportTableToExcel() {
            var tableClone = document.createElement("table");
            var headerRow = tableClone.insertRow();
            var headers = ["Leave Type", "Duration", "Status"];
            headers.forEach(h => {
                var th = document.createElement("th");
                th.innerText = h;
                headerRow.appendChild(th);
            });

            var table = $('#myLeavesTable').DataTable();
            table.rows().data().each(function(row) {
                var tr = tableClone.insertRow();
                tr.innerHTML = `
                    <td>${$(row[1]).text().trim()}</td>
                    <td>${$(row[2]).text().trim()}</td>
                    <td>${$(row[3]).text().trim()}</td>
                `;
            });

            var workbook = XLSX.utils.table_to_book(tableClone, { sheet: "My Leaves Report" });
            XLSX.writeFile(workbook, 'My_Leaves_Report.xlsx');
        }

        async function exportTableToPDF() {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF('portrait');
            doc.text('My Leaves Application Report', 14, 15);

            const tableData = [];
            var table = $('#myLeavesTable').DataTable();
            table.rows().data().each(function(row) {
                tableData.push([
                    $(row[1]).text().trim(),
                    $(row[2]).text().trim(),
                    $(row[3]).text().trim()
                ]);
            });

            doc.autoTable({
                head: [["Leave Type", "Duration", "Status"]],
                body: tableData,
                startY: 25,
            });

            doc.save('My_Leaves_Report.pdf');
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