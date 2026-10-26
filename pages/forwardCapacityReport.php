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
$windowDays = isset($_GET['window_days']) ? (int)$_GET['window_days'] : 14;
if ($windowDays <= 0) $windowDays = 14;

$todayStr   = date('Y-m-d');
$futureDate = date('Y-m-d', strtotime("+$windowDays days"));

// ── 2. Capacity Query ──
// Fetch all active interns and check their active allocations
$qInterns = mysqli_query($con, "
    SELECT s.internId, s.userId, s.fullName, s.department, s.designation, s.mode
    FROM signup s
    WHERE (s.employment_type = 'Intern' OR s.userRole LIKE '%intern%')
      AND s.status = 'Active' AND s.regstatus = 'Approved'
      AND s.internId IS NOT NULL AND s.internId != ''
    ORDER BY s.fullName ASC
");

$capacityData = [];
$freeBenchCount = 0;
$expiringSoonCount = 0;
$fullyBookedCount = 0;

if ($qInterns) {
    while ($i = mysqli_fetch_assoc($qInterns)) {
        $intIdEsc = mysqli_real_escape_string($con, $i['internId']);
        
        // Find latest/max endDate from internAllocations
        $qAlloc = mysqli_query($con, "
            SELECT projectName, clientName, startDate, endDate, allocStartTime, allocEndTime
            FROM internAllocations
            WHERE internId = '$intIdEsc' AND endDate >= '$todayStr'
            ORDER BY endDate DESC LIMIT 1
        ");

        if ($qAlloc && mysqli_num_rows($qAlloc) > 0) {
            $allocRow = mysqli_fetch_assoc($qAlloc);
            $maxEnd = $allocRow['endDate'];
            $diffDays = (int)((strtotime($maxEnd) - strtotime($todayStr)) / 86400);

            if ($diffDays <= 3) {
                $statusType = 'expiring_soon';
                $statusBadge = '<span class="badge bg-warning text-dark px-2 py-1"><i class="fa fa-exclamation-circle me-1"></i> Free in ' . max(0, $diffDays) . ' Days</span>';
                $expiringSoonCount++;
            } else {
                $statusType = 'fully_booked';
                $statusBadge = '<span class="badge bg-secondary px-2 py-1"><i class="fa fa-lock me-1"></i> Booked till ' . date('d M Y', strtotime($maxEnd)) . '</span>';
                $fullyBookedCount++;
            }

            $currentProject = $allocRow['projectName'];
            $clientName     = $allocRow['clientName'];
            $freeDate       = date('d M Y', strtotime('+1 day', strtotime($maxEnd)));
        } else {
            // Free right now
            $statusType = 'bench';
            $statusBadge = '<span class="badge bg-success px-2 py-1"><i class="fa fa-check-circle me-1"></i> Free / On Bench</span>';
            $freeBenchCount++;

            $currentProject = '— (Unallocated)';
            $clientName     = '';
            $freeDate       = 'Available Immediately';
        }

        $capacityData[] = [
            'internId'       => $i['internId'],
            'fullName'       => $i['fullName'],
            'department'     => !empty($i['department']) ? $i['department'] : 'Software Engineering',
            'designation'    => !empty($i['designation']) ? $i['designation'] : 'Intern',
            'mode'           => $i['mode'],
            'currentProject' => $currentProject,
            'clientName'     => $clientName,
            'freeDate'       => $freeDate,
            'statusType'     => $statusType,
            'statusBadge'    => $statusBadge
        ];
    }
}

$totalInterns = count($capacityData);
$benchRate    = $totalInterns > 0 ? round(($freeBenchCount / $totalInterns) * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Forward Capacity Forecast & Bench Heatmap | SETTribe AMS</title>
    <?php include('../partials/_links.php'); ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css">

    <style>
        .kpi-card { border-radius:12px; border:1px solid #e2e8f0; background:#fff; padding:20px; box-shadow:0 2px 10px rgba(0,0,0,0.03); }
        .kpi-title { font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; color: #64748b; }
        .kpi-val { font-size: 1.7rem; font-weight: 800; color: #1e293b; margin-top: 4px; }
        .kpi-icon { width:44px; height:44px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.2rem; }
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
                                🏖️ 14-to-30 Day Forward Capacity Forecast & Bench Heatmap
                            </h3>
                            <p class="text-muted mb-0" style="font-size:0.85rem;">
                                Executive Forward Capacity Forecast: Track upcoming intern bench availability and project rollover dates
                            </p>
                        </div>
                        <span class="badge px-3 py-2" style="border-radius:20px; font-size:0.8rem; font-weight:600; background-color:#10b981 !important; color:#ffffff !important; box-shadow: 0 2px 8px rgba(16,185,129,0.3);">
                            Forward Capacity Intelligence
                        </span>
                    </div>

                    <!-- Filters -->
                    <div class="card mb-4" style="border-radius:12px; border:1px solid #e2e8f0; box-shadow:0 2px 8px rgba(0,0,0,0.02);">
                        <div class="card-body p-3">
                            <form method="GET" action="forwardCapacityReport.php" class="row g-3 align-items-end">
                                <div class="col-lg-6 col-md-6 col-12">
                                    <label class="form-label fw-bold text-muted mb-1" style="font-size:0.78rem;">Forecast Window</label>
                                    <select name="window_days" class="form-select custom-filter-select">
                                        <option value="14" <?= $windowDays == 14 ? 'selected' : '' ?>>Next 14 Days Forecast (2 Weeks)</option>
                                        <option value="30" <?= $windowDays == 30 ? 'selected' : '' ?>>Next 30 Days Forecast (1 Month)</option>
                                    </select>
                                </div>
                                <div class="col-lg-4 col-md-6 col-12 d-flex gap-2">
                                    <button type="submit" class="btn btn-filter-submit flex-grow-1"><i class="fa fa-filter me-1"></i> Update Forecast</button>
                                    <a href="forwardCapacityReport.php" class="btn btn-filter-reset" title="Reset Filters"><i class="fa fa-undo"></i></a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- KPI Cards -->
                    <div class="row mb-4">
                        <div class="col-md-3 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-title">Current Bench Capacity</div>
                                    <div class="kpi-val text-success"><?= $freeBenchCount ?> <span style="font-size:0.9rem;">interns</span></div>
                                    <small class="text-muted">Available Immediately</small>
                                </div>
                                <div class="kpi-icon bg-light-success text-success"><i class="fa fa-umbrella-beach"></i></div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-title">Expiring Soon (≤ 3 Days)</div>
                                    <div class="kpi-val text-warning"><?= $expiringSoonCount ?> <span style="font-size:0.9rem;">interns</span></div>
                                    <small class="text-muted">Becoming free this week</small>
                                </div>
                                <div class="kpi-icon bg-light-warning text-warning"><i class="fa fa-hourglass-half"></i></div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-title">Fully Booked Capacity</div>
                                    <div class="kpi-val text-secondary"><?= $fullyBookedCount ?> <span style="font-size:0.9rem;">interns</span></div>
                                    <small class="text-muted">Booked > 3 days</small>
                                </div>
                                <div class="kpi-icon bg-light text-secondary"><i class="fa fa-lock"></i></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-title">Bench Ratio %</div>
                                    <div class="kpi-val text-primary"><?= $benchRate ?>%</div>
                                    <small class="text-muted">Unallocated Intern Rate</small>
                                </div>
                                <div class="kpi-icon bg-light-primary text-primary"><i class="fa fa-pie-chart"></i></div>
                            </div>
                        </div>
                    </div>

                    <!-- Main Data Matrix -->
                    <div class="card" style="border-radius:12px; border:1px solid #e2e8f0;">
                        <div class="card-body p-4">
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <h5 class="fw-bold text-dark mb-0"><i class="fa fa-users text-primary me-2"></i> Intern Forward Availability Matrix</h5>
                                <span class="badge bg-light text-dark border px-3 py-2" style="font-size:0.8rem; border-radius:20px;">
                                    Showing Total <?= count($capacityData) ?> Interns
                                </span>
                            </div>

                            <div class="table-responsive">
                                <table id="capacityTable" class="table table-hover align-middle mb-0" style="font-size:0.85rem;">
                                    <thead class="table-light text-secondary text-uppercase" style="font-size:0.75rem;">
                                        <tr>
                                            <th>Intern Details</th>
                                            <th>Department / Skill</th>
                                            <th>Current Active Project</th>
                                            <th>Next Availability Date</th>
                                            <th class="text-center">Capacity Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($capacityData)):
                                            foreach ($capacityData as $row):
                                        ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-bold text-dark"><?= htmlspecialchars($row['fullName']) ?></div>
                                                    <div class="text-muted small">ID: <code><?= htmlspecialchars($row['internId']) ?></code></div>
                                                </td>
                                                <td>
                                                    <div class="fw-bold text-dark"><?= htmlspecialchars($row['department']) ?></div>
                                                    <span class="badge bg-light text-dark border"><?= htmlspecialchars($row['mode']) ?></span>
                                                </td>
                                                <td>
                                                    <div class="fw-bold text-primary"><?= htmlspecialchars($row['currentProject']) ?></div>
                                                    <?php if (!empty($row['clientName'])): ?>
                                                        <div class="text-muted small"><i class="fa fa-building-o me-1"></i> <?= htmlspecialchars($row['clientName']) ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="fw-bold text-dark"><i class="fa fa-calendar-check-o text-success me-1"></i> <?= htmlspecialchars($row['freeDate']) ?></div>
                                                </td>
                                                <td class="text-center">
                                                    <?= $row['statusBadge'] ?>
                                                </td>
                                            </tr>
                                        <?php endforeach;
                                        else: ?>
                                            <tr>
                                                <td colspan="5" class="text-center py-4 text-muted">
                                                    <i class="fa fa-info-circle fa-2x mb-2 d-block"></i>
                                                    No intern capacity records found.
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
            $('#capacityTable').DataTable({
                dom: '<"d-flex align-items-center justify-content-between mb-3"Bf>rt<"d-flex align-items-center justify-content-between mt-3"ip>',
                buttons: [
                    { extend: 'excelHtml5', text: '📊 Export to Excel', className: 'btn btn-success btn-sm fw-bold me-2' },
                    { extend: 'csvHtml5', text: '📄 Export to CSV', className: 'btn btn-outline-secondary btn-sm fw-bold me-2' },
                    { extend: 'print', text: '🖨️ Print Report', className: 'btn btn-outline-info btn-sm fw-bold' }
                ],
                pageLength: 25,
                ordering: true,
                order: [[3, 'asc']]
            });
        });
    </script>
</body>
</html>
