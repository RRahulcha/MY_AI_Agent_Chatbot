<?php
session_start();

if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

include('../include/db_config.php');
date_default_timezone_set('Asia/Kolkata');

$userRole  = strtolower($_SESSION['userRole'] ?? '');
$userDesig = strtolower($_SESSION['designation'] ?? '');
$allowedRoles = ['resourceallocator', 'resource_allocator', 'resourcehead', 'resource_head', 'resource allocator', 'resourcemanager', 'resource_manager', 'admin', 'superadmin', 'hr', 'md'];

if (!in_array($userRole, $allowedRoles) && !in_array($userDesig, $allowedRoles)) {
    echo "<script>alert('Access Denied. Resource Managers and Admins only.'); window.location.href = 'jrEmpDashboard.php';</script>";
    exit;
}

// ── 1. Filters ──
$fromDate = isset($_GET['from_date']) && !empty($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$toDate   = isset($_GET['to_date']) && !empty($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$leadFilter = isset($_GET['requested_by']) ? trim((string)$_GET['requested_by']) : '';

$fromDateEsc = mysqli_real_escape_string($con, $fromDate);
$toDateEsc   = mysqli_real_escape_string($con, $toDate);
$leadFilterEsc = mysqli_real_escape_string($con, $leadFilter);

// Team Leads list for dropdown
$leadsList = [];
$qLeads = mysqli_query($con, "
    SELECT DISTINCT su.userId, su.fullName 
    FROM signup su
    WHERE su.userId IN (SELECT DISTINCT requestedBy FROM resourceRequests WHERE requestedBy IS NOT NULL AND requestedBy != '')
       OR LOWER(REPLACE(REPLACE(su.userRole, '_', ''), ' ', '')) IN ('teamlead', 'departmenthead', 'manager', 'seniorintern', 'projectmanager', 'pm', 'sremployee', 'lead')
       OR LOWER(REPLACE(REPLACE(su.designation, '_', ''), ' ', '')) IN ('teamlead', 'departmenthead', 'manager', 'seniorintern', 'projectmanager', 'pm', 'sremployee', 'lead')
    ORDER BY su.fullName ASC
");
if ($qLeads) {
    while ($row = mysqli_fetch_assoc($qLeads)) {
        if (!empty($row['userId']) && !empty($row['fullName'])) {
            $leadsList[] = $row;
        }
    }
}

// ── 2. Audit Query ──
$whereSql = "WHERE rr.startDate <= '$toDateEsc' AND rr.endDate >= '$fromDateEsc'";
if (!empty($leadFilterEsc)) {
    $whereSql .= " AND rr.requestedBy = '$leadFilterEsc'";
}

$qAudit = mysqli_query($con, "
    SELECT rr.requestedBy, su.fullName AS leadName, su.mobileNo, su.department,
           COUNT(rr.id) AS totalRequests,
           SUM(CASE WHEN rr.status = 'Approved' THEN 1 ELSE 0 END) AS approvedCount,
           SUM(CASE WHEN rr.status = 'Pending' THEN 1 ELSE 0 END) AS pendingCount,
           SUM(CASE WHEN rr.status = 'Rejected' THEN 1 ELSE 0 END) AS rejectedCount,
           SUM(rr.dailyHours) AS totalReqHours,
           SUM(CASE WHEN rr.status = 'Approved' THEN rr.dailyHours ELSE 0 END) AS approvedReqHours
    FROM resourceRequests rr
    LEFT JOIN signup su ON su.userId = rr.requestedBy
    $whereSql
    GROUP BY rr.requestedBy, su.fullName, su.mobileNo, su.department
    ORDER BY totalReqHours DESC
");

$auditData = [];
$totalSubmittedReq = 0;
$totalApprovedHrs  = 0;
$totalRequestedHrs = 0;

if ($qAudit) {
    while ($r = mysqli_fetch_assoc($qAudit)) {
        $totReq = (int)$r['totalRequests'];
        $appCnt = (int)$r['approvedCount'];
        $reqHrs = (float)$r['totalReqHours'];
        $appHrs = (float)$r['approvedReqHours'];

        $appRate = $totReq > 0 ? round(($appCnt / $totReq) * 100, 1) : 0;

        $totalSubmittedReq += $totReq;
        $totalRequestedHrs += $reqHrs;
        $totalApprovedHrs  += $appHrs;

        $auditData[] = [
            'leadId'       => $r['requestedBy'],
            'leadName'     => !empty($r['leadName']) ? $r['leadName'] : $r['requestedBy'],
            'mobileNo'     => $r['mobileNo'],
            'department'   => !empty($r['department']) ? $r['department'] : 'Project Management',
            'totalRequests'=> $totReq,
            'approvedCount'=> $appCnt,
            'pendingCount' => (int)$r['pendingCount'],
            'rejectedCount'=> (int)$r['rejectedCount'],
            'reqHours'     => $reqHrs,
            'appHours'     => $appHrs,
            'approvalRate' => $appRate
        ];
    }
}

$overallApprovalRate = $totalSubmittedReq > 0 ? round(($totalApprovedHrs / max(1,$totalRequestedHrs)) * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Team Lead Demand & Approval Audit Report | SETTribe AMS</title>
    <?php include('../partials/_links.php'); ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css">

    <style>
        .kpi-card { border-radius:12px; border:1px solid #e2e8f0; background:#fff; padding:20px; box-shadow:0 2px 10px rgba(0,0,0,0.03); }
        .kpi-title { font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; color: #64748b; }
        .kpi-val { font-size: 1.7rem; font-weight: 800; color: #1e293b; margin-top: 4px; }
        .kpi-icon { width:44px; height:44px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.2rem; }
        .progress-rate { height:8px; border-radius:4px; background:#e2e8f0; overflow:hidden; }
        .table-responsive { border-radius:10px; overflow:hidden; border:1px solid #e2e8f0; }

        /* Custom Filter Controls & Dropdown Styling */
        .custom-filter-input, .custom-filter-select {
            height: 42px !important;
            width: 100% !important;
            max-width: 100% !important;
            box-sizing: border-box !important;
            border-radius: 8px !important;
            border: 1px solid #cbd5e1 !important;
            font-size: 0.88rem !important;
            font-weight: 500 !important;
            color: #1e293b !important;
            background-color: #ffffff !important;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04) !important;
            transition: all 0.2s ease-in-out !important;
        }
        .custom-filter-select {
            padding: 8px 36px 8px 14px !important;
            appearance: none !important;
            -webkit-appearance: none !important;
            -moz-appearance: none !important;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E") !important;
            background-repeat: no-repeat !important;
            background-position: right 14px center !important;
            background-size: 16px !important;
        }
        .custom-filter-input:focus, .custom-filter-select:focus {
            border-color: #4b49ac !important;
            box-shadow: 0 0 0 3px rgba(75, 73, 172, 0.15) !important;
            outline: none !important;
        }
        .btn-filter-submit {
            height: 42px !important;
            border-radius: 8px !important;
            background: linear-gradient(135deg, #4b49ac 0%, #3f3da3 100%) !important;
            color: #ffffff !important;
            font-weight: 600 !important;
            font-size: 0.88rem !important;
            border: none !important;
            box-shadow: 0 2px 6px rgba(75, 73, 172, 0.25) !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 6px !important;
            transition: all 0.2s ease-in-out !important;
        }
        .btn-filter-submit:hover {
            background: linear-gradient(135deg, #3f3da3 0%, #35338a 100%) !important;
            box-shadow: 0 4px 12px rgba(75, 73, 172, 0.35) !important;
            color: #ffffff !important;
        }
        .btn-filter-reset {
            height: 42px !important;
            width: 42px !important;
            border-radius: 8px !important;
            border: 1px solid #cbd5e1 !important;
            background-color: #ffffff !important;
            color: #64748b !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            transition: all 0.2s ease-in-out !important;
            flex-shrink: 0 !important;
        }
        .btn-filter-reset:hover {
            background-color: #f8fafc !important;
            color: #0f172a !important;
            border-color: #94a3b8 !important;
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

                    <!-- Header -->
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
                        <div>
                            <h3 class="fw-bold mb-1" style="color:#2d3748;">
                                👥 Team Lead Resource Demand & Approval Audit Report
                            </h3>
                            <p class="text-muted mb-0" style="font-size:0.85rem;">
                                Demand Analysis: Audit intern resource requests, approval rates, and hour grants per Team Lead
                            </p>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <a href="resourceApprovals.php" class="btn btn-sm text-white px-3 py-2 shadow-sm d-inline-flex align-items-center gap-2" style="border-radius:8px; font-size:0.82rem; font-weight:600; background: linear-gradient(135deg, #4b49ac 0%, #3f3da3 100%); border:none; box-shadow: 0 2px 8px rgba(75,73,172,0.3);">
                                <i class="fa fa-clipboard-check"></i> Resource Approval Hub
                            </a>
                        </div>
                    </div>

                    <!-- Filters -->
                    <div class="card mb-4" style="border-radius:12px; border:1px solid #e2e8f0; box-shadow:0 2px 8px rgba(0,0,0,0.02);">
                        <div class="card-body p-3">
                            <form method="GET" action="teamLeadRequestAuditReport.php" class="row g-3 align-items-end">
                                <div class="col-lg-3 col-md-3 col-12">
                                    <label class="form-label fw-bold text-muted mb-1" style="font-size:0.78rem;">From Date</label>
                                    <input type="date" name="from_date" class="form-control custom-filter-input" value="<?= htmlspecialchars((string)$fromDate) ?>">
                                </div>
                                <div class="col-lg-3 col-md-3 col-12">
                                    <label class="form-label fw-bold text-muted mb-1" style="font-size:0.78rem;">To Date</label>
                                    <input type="date" name="to_date" class="form-control custom-filter-input" value="<?= htmlspecialchars((string)$toDate) ?>">
                                </div>
                                <div class="col-lg-4 col-md-4 col-12">
                                    <label class="form-label fw-bold text-muted mb-1" style="font-size:0.78rem;">Team Lead</label>
                                    <select name="requested_by" class="form-select custom-filter-select">
                                        <option value="">All Team Leads</option>
                                        <?php foreach ($leadsList as $l): 
                                            $sel = ($leadFilter === $l['userId']) ? 'selected' : '';
                                        ?>
                                            <option value="<?= htmlspecialchars((string)$l['userId']) ?>" <?= $sel ?>><?= htmlspecialchars((string)$l['fullName']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-lg-2 col-md-2 col-12 d-flex gap-2">
                                    <button type="submit" class="btn btn-filter-submit flex-grow-1"><i class="fa fa-filter me-1"></i> Filter</button>
                                    <a href="teamLeadRequestAuditReport.php" class="btn btn-filter-reset" title="Reset Filters"><i class="fa fa-undo"></i></a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- KPI Summary -->
                    <div class="row mb-4">
                        <div class="col-md-3 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-title">Total Requests Submitted</div>
                                    <div class="kpi-val text-primary"><?= $totalSubmittedReq ?> <span style="font-size:0.9rem;">requests</span></div>
                                    <small class="text-muted">By all Team Leads</small>
                                </div>
                                <div class="kpi-icon bg-light-primary text-primary"><i class="fa fa-paper-plane"></i></div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-title">Requested Manhours</div>
                                    <div class="kpi-val text-purple" style="color:#7c3aed;"><?= number_format($totalRequestedHrs, 1) ?> <span style="font-size:0.9rem;">hrs</span></div>
                                    <small class="text-muted">Total Demand</small>
                                </div>
                                <div class="kpi-icon text-purple" style="background:#f3e8ff; color:#7c3aed;"><i class="fa fa-clock"></i></div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-title">Approved Manhours</div>
                                    <div class="kpi-val text-success"><?= number_format($totalApprovedHrs, 1) ?> <span style="font-size:0.9rem;">hrs</span></div>
                                    <small class="text-muted">Granted by RM</small>
                                </div>
                                <div class="kpi-icon bg-light-success text-success"><i class="fa fa-check-double"></i></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-title">Hour Grant Ratio</div>
                                    <div class="kpi-val text-info"><?= $overallApprovalRate ?>%</div>
                                    <small class="text-muted">Granted vs Demanded Ratio</small>
                                </div>
                                <div class="kpi-icon bg-light-info text-info"><i class="fa fa-chart-line"></i></div>
                            </div>
                        </div>
                    </div>

                    <!-- Main Data Table -->
                    <div class="card" style="border-radius:12px; border:1px solid #e2e8f0;">
                        <div class="card-body p-4">
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <h5 class="fw-bold text-dark mb-0"><i class="fa fa-table me-2 text-primary"></i> Team Lead Demand & Approval Rate Matrix</h5>
                                <span class="badge bg-light text-dark border px-3 py-2" style="font-size:0.8rem; border-radius:20px;">
                                    Showing <?= count($auditData) ?> Team Leads
                                </span>
                            </div>

                            <div class="table-responsive">
                                <table id="auditTable" class="table table-hover align-middle mb-0" style="font-size:0.85rem;">
                                    <thead class="table-light text-secondary text-uppercase" style="font-size:0.75rem;">
                                        <tr>
                                            <th>Team Lead Details</th>
                                            <th class="text-center">Submitted Requests</th>
                                            <th class="text-center">Requested Hours</th>
                                            <th class="text-center">Approved Hours</th>
                                            <th class="text-center" style="width:140px;">Approval Rate %</th>
                                            <th class="text-center">Breakdown</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($auditData)):
                                            foreach ($auditData as $row):
                                                $rate = $row['approvalRate'];
                                                $barClass = $rate >= 80 ? 'bg-success' : ($rate >= 50 ? 'bg-warning' : 'bg-danger');
                                        ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-bold text-dark">👤 <?= htmlspecialchars($row['leadName']) ?></div>
                                                    <?php if (!empty($row['mobileNo'])): ?>
                                                        <div class="text-muted small"><i class="fa fa-phone me-1"></i> <?= htmlspecialchars($row['mobileNo']) ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center fw-bold text-dark">
                                                    <?= $row['totalRequests'] ?>
                                                </td>
                                                <td class="text-center fw-bold text-primary">
                                                    <?= number_format($row['reqHours'], 1) ?> hrs
                                                </td>
                                                <td class="text-center fw-bold text-success">
                                                    <?= number_format($row['appHours'], 1) ?> hrs
                                                </td>
                                                <td class="text-center">
                                                    <div class="d-flex align-items-center justify-content-between mb-1" style="font-size:0.75rem;">
                                                        <span class="fw-bold"><?= $rate ?>%</span>
                                                    </div>
                                                    <div class="progress-rate">
                                                        <div class="progress-bar <?= $barClass ?>" style="width: <?= min(100, $rate) ?>%; height:100%;"></div>
                                                    </div>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge bg-success text-white me-1"><?= $row['approvedCount'] ?> Approved</span>
                                                    <span class="badge bg-warning text-dark me-1"><?= $row['pendingCount'] ?> Pending</span>
                                                    <span class="badge bg-danger text-white"><?= $row['rejectedCount'] ?> Rejected</span>
                                                </td>
                                            </tr>
                                        <?php endforeach;
                                        else: ?>
                                            <tr>
                                                <td colspan="6" class="text-center py-4 text-muted">
                                                    <i class="fa fa-info-circle fa-2x mb-2 d-block"></i>
                                                    No team lead request records found for the selected period.
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <script src="../assets/vendors/js/vendor.bundle.base.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap5.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>

    <script>
        $(document).ready(function() {
            $('#auditTable').DataTable({
                dom: '<"d-flex align-items-center justify-content-between mb-3"Bf>rt<"d-flex align-items-center justify-content-between mt-3"ip>',
                buttons: [
                    { extend: 'excelHtml5', text: '📊 Export to Excel', className: 'btn btn-success btn-sm fw-bold me-2' },
                    { extend: 'csvHtml5', text: '📄 Export to CSV', className: 'btn btn-outline-secondary btn-sm fw-bold me-2' },
                    { extend: 'print', text: '🖨️ Print Report', className: 'btn btn-outline-info btn-sm fw-bold' }
                ],
                pageLength: 25,
                ordering: true,
                order: [[3, 'desc']]
            });
        });
    </script>
</body>
</html>
