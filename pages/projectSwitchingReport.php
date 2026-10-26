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
$fromDate = isset($_GET['from_date']) && !empty($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-d', strtotime('-6 days'));
$toDate   = isset($_GET['to_date']) && !empty($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$internFilter  = isset($_GET['intern_id']) ? trim((string)$_GET['intern_id']) : '';

$fromDateEsc     = mysqli_real_escape_string($con, $fromDate);
$toDateEsc       = mysqli_real_escape_string($con, $toDate);
$internFilterEsc = mysqli_real_escape_string($con, $internFilter);

// Dropdown List
$internsList = [];
$internsQ = mysqli_query($con, "SELECT DISTINCT internId, fullName, userId FROM signup WHERE (employment_type='Intern' OR userRole LIKE '%intern%') AND internId IS NOT NULL AND internId != '' ORDER BY fullName ASC");
while ($row = mysqli_fetch_assoc($internsQ)) {
    $internsList[] = $row;
}

// ── 2. Switching Query Logic ──
// Group allocations per intern per date to detect multi-project switching
$whereSql = "WHERE ia.startDate <= '$toDateEsc' AND ia.endDate >= '$fromDateEsc'";
if (!empty($internFilterEsc)) {
    $whereSql .= " AND (ia.internId = '$internFilterEsc' OR su.userId = '$internFilterEsc')";
}

$qSwitch = mysqli_query($con, "
    SELECT ia.internId, su.fullName, su.userId,
           COUNT(DISTINCT ia.projectName) AS projectCount,
           GROUP_CONCAT(DISTINCT ia.projectName ORDER BY ia.allocStartTime ASC SEPARATOR ' ➔ ') AS projectChain,
           SUM(ia.dailyHours) AS totalDailyHrs,
           MIN(ia.allocStartTime) AS minStart,
           MAX(ia.allocEndTime) AS maxEnd,
           su_alloc.fullName AS managerName
    FROM internAllocations ia
    LEFT JOIN signup su ON (su.internId = ia.internId OR su.userId = ia.internId)
    LEFT JOIN signup su_alloc ON su_alloc.userId = ia.allocatedBy
    $whereSql
    GROUP BY ia.internId, su.fullName, su.userId, su_alloc.fullName
    ORDER BY projectCount DESC, su.fullName ASC
");

$switchData = [];
$totalSwitches = 0;
$multiSwitchInterns = 0;

if ($qSwitch) {
    while ($r = mysqli_fetch_assoc($qSwitch)) {
        $pCount = (int)$r['projectCount'];
        $switches = max(0, $pCount - 1);
        $totalSwitches += $switches;
        if ($switches > 0) $multiSwitchInterns++;

        $riskLevel = 'low';
        if ($pCount >= 3) $riskLevel = 'high';
        elseif ($pCount == 2) $riskLevel = 'medium';

        $switchData[] = [
            'internId'     => $r['internId'],
            'fullName'     => !empty($r['fullName']) ? $r['fullName'] : $r['internId'],
            'projectCount' => $pCount,
            'switches'     => $switches,
            'projectChain' => $r['projectChain'],
            'totalHours'   => (float)$r['totalDailyHrs'],
            'shiftTime'    => date('h:i A', strtotime($r['minStart'])) . ' – ' . date('h:i A', strtotime($r['maxEnd'])),
            'managerName'  => !empty($r['managerName']) ? $r['managerName'] : 'Resource Head',
            'riskLevel'    => $riskLevel
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Unplanned Project Switching Index Report | SETTribe AMS</title>
    <?php include('../partials/_links.php'); ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css">

    <style>
        .kpi-card { border-radius:12px; border:1px solid #e2e8f0; background:#fff; padding:20px; box-shadow:0 2px 10px rgba(0,0,0,0.03); }
        .kpi-title { font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; color: #64748b; }
        .kpi-val { font-size: 1.7rem; font-weight: 800; color: #1e293b; margin-top: 4px; }
        .kpi-icon { width:44px; height:44px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.2rem; }
        .badge-risk-low  { background:#dcfce7; color:#15803d; border:1px solid #bbf7d0; font-weight:700; }
        .badge-risk-med  { background:#fef9c3; color:#a16207; border:1px solid #fef08a; font-weight:700; }
        .badge-risk-high { background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5; font-weight:700; }
        .table-responsive { border-radius: 10px; overflow-x: auto !important; -webkit-overflow-scrolling: touch; border: 1px solid #e2e8f0; }

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

        /* Master Search Bar Styling */
        .dataTables_filter input {
            height: 40px !important;
            border-radius: 8px !important;
            border: 1px solid #cbd5e1 !important;
            padding: 6px 14px 6px 36px !important;
            font-size: 0.88rem !important;
            color: #1e293b !important;
            background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='11' cy='11' r='8'/%3E%3Cpath d='m21 21-4.3-4.3'/%3E%3C/svg%3E") no-repeat 12px center !important;
            background-size: 16px !important;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04) !important;
            transition: all 0.2s !important;
            min-width: 320px !important;
        }
        .dataTables_filter input:focus {
            border-color: #4b49ac !important;
            box-shadow: 0 0 0 3px rgba(75, 73, 172, 0.15) !important;
            outline: none !important;
        }
        .dataTables_filter label {
            margin-bottom: 0 !important;
            font-weight: 600 !important;
            color: #64748b !important;
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
                                ⚡ Unplanned Project Switching & Disruption Index Report
                            </h3>
                            <p class="text-muted mb-0" style="font-size:0.85rem;">
                                Context-Switching Audit: Track mid-day/mid-week project switches & intern disruption risk
                            </p>
                        </div>
                        <span class="badge px-3 py-2" style="border-radius:20px; font-size:0.8rem; font-weight:600; background-color:#f59e0b !important; color:#ffffff !important; box-shadow: 0 2px 8px rgba(245,158,11,0.3);">
                            Agility & Disruption Audit
                        </span>
                    </div>

                    <!-- Filters -->
                    <div class="card mb-4" style="border-radius:12px; border:1px solid #e2e8f0; box-shadow:0 2px 8px rgba(0,0,0,0.02);">
                        <div class="card-body p-3">
                            <form method="GET" action="projectSwitchingReport.php" class="row g-3 align-items-end">
                                <div class="col-lg-3 col-md-3 col-12">
                                    <label class="form-label fw-bold text-muted mb-1" style="font-size:0.78rem;">From Date</label>
                                    <input type="date" name="from_date" class="form-control custom-filter-input" value="<?= htmlspecialchars((string)$fromDate) ?>">
                                </div>
                                <div class="col-lg-3 col-md-3 col-12">
                                    <label class="form-label fw-bold text-muted mb-1" style="font-size:0.78rem;">To Date</label>
                                    <input type="date" name="to_date" class="form-control custom-filter-input" value="<?= htmlspecialchars((string)$toDate) ?>">
                                </div>
                                <div class="col-lg-4 col-md-4 col-12">
                                    <label class="form-label fw-bold text-muted mb-1" style="font-size:0.78rem;">Intern Filter</label>
                                    <select name="intern_id" class="form-select custom-filter-select">
                                        <option value="">All Interns</option>
                                        <?php foreach ($internsList as $i): 
                                            $val = !empty($i['internId']) ? $i['internId'] : $i['userId'];
                                            $sel = ($internFilter === $val) ? 'selected' : '';
                                        ?>
                                            <option value="<?= htmlspecialchars((string)$val) ?>" <?= $sel ?>><?= htmlspecialchars((string)$i['fullName']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-lg-2 col-md-2 col-12 d-flex gap-2">
                                    <button type="submit" class="btn btn-filter-submit flex-grow-1"><i class="fa fa-filter me-1"></i> Filter</button>
                                    <a href="projectSwitchingReport.php" class="btn btn-filter-reset" title="Reset Filters"><i class="fa fa-undo"></i></a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- KPI Summary -->
                    <div class="row mb-4">
                        <div class="col-md-4 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-title">Total Project Switches</div>
                                    <div class="kpi-val text-warning"><?= $totalSwitches ?> <span style="font-size:0.9rem;">switches</span></div>
                                    <small class="text-muted">Mid-Period Reassignments</small>
                                </div>
                                <div class="kpi-icon bg-light-warning text-warning"><i class="fa fa-arrows-split-up-and-left"></i></div>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-title">Impacted Interns</div>
                                    <div class="kpi-val text-danger"><?= $multiSwitchInterns ?> <span style="font-size:0.9rem;">interns</span></div>
                                    <small class="text-muted">Assigned to >1 project</small>
                                </div>
                                <div class="kpi-icon bg-light-danger text-danger"><i class="fa fa-user-gear"></i></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-title">Disruption Index Ratio</div>
                                    <div class="kpi-val text-primary">
                                        <?= count($switchData) > 0 ? round(($multiSwitchInterns / count($switchData)) * 100, 1) : 0 ?>%
                                    </div>
                                    <small class="text-muted">% Interns experiencing switches</small>
                                </div>
                                <div class="kpi-icon bg-light-primary text-primary"><i class="fa fa-bolt"></i></div>
                            </div>
                        </div>
                    </div>

                    <!-- Main Data Matrix -->
                    <div class="card" style="border-radius:12px; border:1px solid #e2e8f0;">
                        <div class="card-body p-4">
                            <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                                <h5 class="fw-bold text-dark mb-0"><i class="fa fa-table me-2 text-warning"></i> Context-Switching & Disruption Audit Matrix</h5>
                                <div class="d-flex align-items-center gap-2">
                                    <div style="position:relative;min-width:280px;">
                                        <i class="fa fa-search" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:0.85rem;pointer-events:none;"></i>
                                        <input type="text" id="customMasterSearch" class="form-control form-control-sm" placeholder="Search intern, project, shift, manager…" style="padding-left:34px;border-radius:8px;border:1px solid #cbd5e1;height:38px;font-size:0.84rem;">
                                    </div>
                                    <span class="badge bg-light text-dark border px-3 py-2" style="font-size:0.8rem; border-radius:20px;white-space:nowrap;">
                                        Showing <?= count($switchData) ?> Records
                                    </span>
                                </div>
                            </div>

                            <div class="table-responsive">
                                <table id="switchingTable" class="table table-hover align-middle mb-0" style="font-size:0.85rem;">
                                    <thead class="table-light text-secondary text-uppercase" style="font-size:0.75rem;">
                                        <tr>
                                            <th>Intern Details</th>
                                            <th class="text-center">Assigned Projects</th>
                                            <th>Project Allocation Chain (Timeline)</th>
                                            <th class="text-center">Total Shift Hours</th>
                                            <th>Allocated Manager</th>
                                            <th class="text-center">Disruption Risk</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($switchData)):
                                            foreach ($switchData as $row):
                                                $rBadge = match($row['riskLevel']) {
                                                    'high'   => 'badge-risk-high',
                                                    'medium' => 'badge-risk-med',
                                                    default  => 'badge-risk-low'
                                                };
                                                $rLabel = match($row['riskLevel']) {
                                                    'high'   => '🔴 High Friction (3+ Proj)',
                                                    'medium' => '🟡 Medium Switch (2 Proj)',
                                                    default  => '🟢 Low / Single Project'
                                                };
                                        ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-bold text-dark"><?= htmlspecialchars($row['fullName']) ?></div>
                                                    <div class="text-muted small">ID: <code><?= htmlspecialchars($row['internId']) ?></code></div>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge bg-primary px-3 py-1" style="border-radius:12px; font-size:0.8rem;">
                                                        <?= $row['projectCount'] ?> Project<?= $row['projectCount'] > 1 ? 's' : '' ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="fw-bold text-dark" style="font-size:0.83rem;">
                                                        <i class="fa fa-route me-1 text-primary"></i> <?= htmlspecialchars($row['projectChain']) ?>
                                                    </div>
                                                    <div class="text-muted small"><i class="fa fa-clock-o me-1"></i> Shift: <?= htmlspecialchars($row['shiftTime']) ?></div>
                                                </td>
                                                <td class="text-center fw-bold text-purple" style="color:#7c3aed;">
                                                    <?= number_format($row['totalHours'], 1) ?> hrs
                                                </td>
                                                <td>
                                                    <div class="fw-bold text-secondary">👤 <?= htmlspecialchars($row['managerName']) ?></div>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge <?= $rBadge ?> px-2 py-1"><?= $rLabel ?></span>
                                                </td>
                                            </tr>
                                        <?php endforeach;
                                        else: ?>
                                            <tr>
                                                <td colspan="6" class="text-center py-4 text-muted">
                                                    <i class="fa fa-info-circle fa-2x mb-2 d-block"></i>
                                                    No allocation switching records found for the selected period.
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

    <script src="../vendors/js/vendor.bundle.base.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap5.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>

    <script>
        $(document).ready(function() {
            var table = $('#switchingTable').DataTable({
                dom: '<"d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2"Bf>rt<"d-flex align-items-center justify-content-between mt-3 flex-wrap gap-2"ip>',
                buttons: [
                    { extend: 'excelHtml5', text: '📊 Export to Excel', className: 'btn btn-success btn-sm fw-bold me-2' },
                    { extend: 'csvHtml5', text: '📄 Export to CSV', className: 'btn btn-outline-secondary btn-sm fw-bold me-2' },
                    { extend: 'print', text: '🖨️ Print Report', className: 'btn btn-outline-info btn-sm fw-bold' }
                ],
                language: {
                    search: "",
                    searchPlaceholder: "Master Search by intern, project, shift, manager…"
                },
                pageLength: 25,
                ordering: true,
                order: [[1, 'desc']],
                scrollX: true,
                autoWidth: false
            });

            $('#customMasterSearch').on('keyup change clear', function() {
                table.search(this.value).draw();
            });
        });
    </script>
</body>
</html>
