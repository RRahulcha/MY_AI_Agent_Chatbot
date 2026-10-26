<?php
session_start();

if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

$userRole  = strtolower($_SESSION['userRole'] ?? '');
$userDesig = strtolower($_SESSION['designation'] ?? '');
if ($userRole === 'junior_intern' || $userRole === 'juniorintern' || $userDesig === 'juniorintern') {
    echo "<script>alert('You are not authorized to access this page.'); window.location.href = 'login.php';</script>";
    exit;
}
$allowedAllocRoles = ['resourceallocator', 'resource_allocator', 'resource allocator', 'resourcemanager', 'resource_manager', 'resourcehead', 'resource_head', 'admin', 'superadmin', 'hr'];
$canManageAlloc = in_array($userRole, $allowedAllocRoles) || in_array($userDesig, $allowedAllocRoles);

include('../include/db_config.php');
date_default_timezone_set('Asia/Kolkata');

$todayStr = date('Y-m-d');

// ── 1. Fetch Projects List for Dropdown ──
$projectsList = [];
$pRes = mysqli_query($con, "SELECT projectId, projectName, client FROM projectMaster WHERE status = 1 ORDER BY projectName ASC");
if ($pRes) {
    while ($r = mysqli_fetch_assoc($pRes)) {
        $projectsList[] = $r;
    }
}

// ── 2. Fetch Employees / Interns for Selection ──
$employeesList = [];
$eRes = mysqli_query($con, "
    SELECT s.userId, s.fullName, s.internId, s.emp_id, s.userRole, s.department
    FROM signup s
    WHERE (TRIM(LOWER(s.status)) = 'active' OR s.status = '1' OR s.status = 1)
      AND (s.regstatus = 'Approved' OR s.regstatus IS NULL OR s.regstatus = '')
      AND (s.designation IS NULL OR LOWER(s.designation) NOT IN ('admin', 'hr', 'superadmin', 'developer', 'md'))
      AND (s.userRole IS NULL OR LOWER(s.userRole) NOT IN ('admin', 'hr', 'superadmin', 'md'))
    ORDER BY s.fullName ASC
");
if ($eRes) {
    while ($r = mysqli_fetch_assoc($eRes)) {
        $employeesList[] = $r;
    }
}

// ── 3. Handle Edit Mode ──
$editProjectId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$selectedEmpIds = [];

if ($editProjectId > 0) {
    $qEdit = mysqli_query($con, "SELECT userId FROM allocatedProject WHERE projectId = '$editProjectId'");
    if ($qEdit) {
        while ($rEdit = mysqli_fetch_assoc($qEdit)) {
            $selectedEmpIds[] = $rEdit['userId'];
        }
    }
}

// ── 4. Main Combined Allocation Query ──
// Fetch allocations from both internAllocations and allocatedProject
$qCombined = mysqli_query($con, "
    SELECT 
        pm.projectId, pm.projectName, pm.client,
        COALESCE(ia.internId, ap.userId) AS userId,
        COALESCE(su.fullName, 'Employee') AS fullName,
        su.internId AS dispInternId, su.emp_id AS dispEmpId, su.userRole, su.department,
        COALESCE(ia.dailyHours, 4.0) AS dailyHours,
        COALESCE(ia.allocStartTime, '09:00:00') AS startTime,
        COALESCE(ia.allocEndTime, '13:00:00') AS endTime,
        COALESCE(ia.startDate, ap.modifiedDate) AS startDate,
        COALESCE(ia.endDate, '2099-12-31') AS endDate,
        su_mgr.fullName AS allocatedByManager,
        ap.id AS legacyAllocId,
        ia.id AS activeAllocId
    FROM projectMaster pm
    LEFT JOIN allocatedProject ap ON ap.projectId = pm.projectId
    LEFT JOIN internAllocations ia ON ia.projectName = pm.projectName AND ia.endDate >= '$todayStr'
    LEFT JOIN signup su ON (su.userId = ap.userId OR su.internId = ia.internId OR su.userId = ia.internId)
    LEFT JOIN signup su_mgr ON su_mgr.userId = ia.allocatedBy
    WHERE pm.status = 1 AND (ap.id IS NOT NULL OR ia.id IS NOT NULL)
    ORDER BY pm.projectName ASC, su.fullName ASC
");

$matrixData = [];
$uniqueProjects = [];
$uniqueDeplUsers = [];
$totalAllocatedHours = 0;

if ($qCombined) {
    while ($row = mysqli_fetch_assoc($qCombined)) {
        $pId   = $row['projectId'];
        $pName = $row['projectName'];
        $uId   = $row['userId'];

        if (empty($uId)) continue;

        if (!isset($matrixData[$pId])) {
            $matrixData[$pId] = [
                'projectId'   => $pId,
                'projectName' => $pName,
                'client'      => $row['client'],
                'employees'   => [],
                'totalHrs'    => 0,
                'legacyId'    => $row['legacyAllocId'],
                'activeId'    => $row['activeAllocId']
            ];
        }

        $uniqueProjects[$pId] = true;
        $uniqueDeplUsers[$uId] = true;

        $dispId = !empty($row['dispInternId']) ? $row['dispInternId'] : (!empty($row['dispEmpId']) ? $row['dispEmpId'] : $uId);
        $mgrName = !empty($row['allocatedByManager']) ? $row['allocatedByManager'] : 'Resource Head';
        $hrs = (float)$row['dailyHours'];

        // End Date Status
        $eDate = $row['endDate'];
        if ($eDate === '2099-12-31') {
            $dateRange = 'Ongoing Assignment';
            $statusBadge = '<span class="badge bg-success px-2 py-1"><i class="fa fa-check-circle me-1"></i> Active</span>';
        } else {
            $dateRange = date('d M Y', strtotime($row['startDate'])) . ' – ' . date('d M Y', strtotime($eDate));
            $diffDays = (int)((strtotime($eDate) - strtotime($todayStr)) / 86400);
            if ($diffDays < 0) {
                $statusBadge = '<span class="badge bg-danger px-2 py-1"><i class="fa fa-times-circle me-1"></i> Expired</span>';
            } elseif ($diffDays <= 3) {
                $statusBadge = '<span class="badge bg-warning text-dark px-2 py-1"><i class="fa fa-clock-o me-1"></i> Expires in ' . $diffDays . 'd</span>';
            } else {
                $statusBadge = '<span class="badge bg-success px-2 py-1"><i class="fa fa-check-circle me-1"></i> Active</span>';
            }
        }

        $matrixData[$pId]['employees'][$uId] = [
            'userId'      => $uId,
            'fullName'    => $row['fullName'],
            'dispId'      => $dispId,
            'userRole'    => !empty($row['userRole']) ? $row['userRole'] : 'Intern',
            'department'  => !empty($row['department']) ? $row['department'] : 'Engineering',
            'dailyHours'  => $hrs,
            'shiftTime'   => date('h:i A', strtotime($row['startTime'])) . ' – ' . date('h:i A', strtotime($row['endTime'])),
            'dateRange'   => $dateRange,
            'mgrName'     => $mgrName,
            'statusBadge' => $statusBadge
        ];

        $matrixData[$pId]['totalHrs'] += $hrs;
        $totalAllocatedHours += $hrs;
    }
}

$allocatedProjectsCount = count($uniqueProjects);
$totalAllocatedHeadcount = count($uniqueDeplUsers);
$avgHeadcountPerProject = $allocatedProjectsCount > 0 ? round($totalAllocatedHeadcount / $allocatedProjectsCount, 1) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Projects Allocation Dashboard & Analytics | SETTribe AMS</title>
    <?php include('../partials/_links.php'); ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-beta.1/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css">

    <style>
        .kpi-card { border-radius:12px; border:1px solid #e2e8f0; background:#fff; padding:20px; box-shadow:0 2px 10px rgba(0,0,0,0.03); }
        .kpi-title { font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; color: #64748b; }
        .kpi-val { font-size: 1.7rem; font-weight: 800; color: #1e293b; margin-top: 4px; }
        .kpi-icon { width:44px; height:44px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.2rem; }
        .table-responsive { border-radius:10px; overflow:hidden; border:1px solid #e2e8f0; }

        .select2-container--default .select2-selection--multiple {
            min-height: 42px; border-radius: 8px; border: 1px solid #ced4da; padding: 4px;
        }
        .select2-container--default .select2-selection--multiple .select2-selection__choice {
            background-color: #4f46e5 !important; color: white !important; border-radius: 6px; padding: 3px 8px; font-size: 0.82rem; font-weight: 600;
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
                                📌 Project Resource Allocation Dashboard
                            </h3>
                            <p class="text-muted mb-0" style="font-size:0.85rem;">
                                Executive Resource Matrix: Track project team assignments, shift hours, and capacity deployment
                            </p>
                        </div>
                        <span class="badge bg-primary px-3 py-2" style="border-radius:20px; font-size:0.8rem;">
                            Resource Allocation Overview
                        </span>
                    </div>

                    <!-- KPI Cards -->
                    <div class="row mb-4">
                        <div class="col-md-3 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-title">Allocated Projects</div>
                                    <div class="kpi-val text-primary"><?= $allocatedProjectsCount ?> <span style="font-size:0.9rem;">projects</span></div>
                                    <small class="text-muted">Active project teams</small>
                                </div>
                                <div class="kpi-icon bg-light-primary text-primary"><i class="fa fa-folder-tree"></i></div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-title">Assigned Headcount</div>
                                    <div class="kpi-val text-purple" style="color:#7c3aed;"><?= $totalAllocatedHeadcount ?> <span style="font-size:0.9rem;">members</span></div>
                                    <small class="text-muted">Total deployed team</small>
                                </div>
                                <div class="kpi-icon text-purple" style="background:#f3e8ff; color:#7c3aed;"><i class="fa fa-users-gear"></i></div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-title">Total Daily Shift Hours</div>
                                    <div class="kpi-val text-success"><?= number_format($totalAllocatedHours, 1) ?> <span style="font-size:0.9rem;">hrs</span></div>
                                    <small class="text-muted">Allocated Daily Capacity</small>
                                </div>
                                <div class="kpi-icon bg-light-success text-success"><i class="fa fa-business-time"></i></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-title">Avg Team Size</div>
                                    <div class="kpi-val text-info"><?= $avgHeadcountPerProject ?> <span style="font-size:0.9rem;">members</span></div>
                                    <small class="text-muted">Headcount per project</small>
                                </div>
                                <div class="kpi-icon bg-light-info text-info"><i class="fa fa-user-group"></i></div>
                            </div>
                        </div>
                    </div>

                    <!-- Main Data Matrix -->
                    <div class="card" style="border-radius:12px; border:1px solid #e2e8f0;">
                        <div class="card-body p-4">
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <h5 class="fw-bold text-dark mb-0"><i class="fa fa-list-check me-2 text-primary"></i> Current Project Resource Allocation Matrix</h5>
                                <span class="badge bg-light text-dark border px-3 py-2" style="font-size:0.8rem; border-radius:20px;">
                                    Showing <?= count($matrixData) ?> Projects
                                </span>
                            </div>

                            <div class="table-responsive">
                                <table id="allocatedProjectTable" class="table table-hover align-middle mb-0" style="font-size:0.85rem;">
                                    <thead class="table-light text-secondary text-uppercase" style="font-size:0.75rem;">
                                        <tr>
                                            <th>Project & Client</th>
                                            <th>Assigned Team Members</th>
                                            <th class="text-center">Shift Hours</th>
                                            <th>Allocation Validity</th>
                                            <th>Allocated Manager</th>
                                            <th class="text-center">Status</th>
                                            <th class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($matrixData)):
                                            foreach ($matrixData as $pRow):
                                                foreach ($pRow['employees'] as $emp):
                                        ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-bold text-dark"><?= htmlspecialchars($pRow['projectName']) ?></div>
                                                    <?php if (!empty($pRow['client'])): ?>
                                                        <div class="text-muted small"><i class="fa fa-building-o me-1"></i> <?= htmlspecialchars($pRow['client']) ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="fw-bold text-dark"><?= htmlspecialchars($emp['fullName']) ?></div>
                                                    <div class="text-muted small">
                                                        ID: <code><?= htmlspecialchars($emp['dispId']) ?></code>
                                                        <span class="badge bg-light text-dark border ms-1"><?= htmlspecialchars($emp['department']) ?></span>
                                                    </div>
                                                </td>
                                                <td class="text-center">
                                                    <div class="fw-bold text-purple" style="color:#7c3aed;"><?= number_format($emp['dailyHours'], 1) ?> hrs/day</div>
                                                    <div class="text-muted" style="font-size:0.72rem;"><?= htmlspecialchars($emp['shiftTime']) ?></div>
                                                </td>
                                                <td>
                                                    <div class="fw-bold text-dark" style="font-size:0.82rem;"><i class="fa fa-calendar me-1 text-primary"></i> <?= htmlspecialchars($emp['dateRange']) ?></div>
                                                </td>
                                                <td>
                                                    <div class="fw-bold text-secondary">👤 <?= htmlspecialchars($emp['mgrName']) ?></div>
                                                </td>
                                                <td class="text-center">
                                                    <?= $emp['statusBadge'] ?>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($canManageAlloc): ?>
                                                    <a href="projectAllocation.php?edit=<?= $pRow['projectId'] ?>" class="btn btn-outline-primary btn-sm me-1" title="Edit Allocation"><i class="fa fa-edit"></i></a>
                                                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="deletefun('projectAllocate_db', <?= $pRow['projectId'] ?>)" title="Delete Allocation"><i class="fa fa-trash"></i></button>
                                                    <?php else: ?>
                                                    <span class="badge bg-secondary px-2 py-1" style="font-size:0.75rem;"><i class="fa fa-eye me-1"></i> View Only</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach;
                                            endforeach;
                                        else: ?>
                                            <tr>
                                                <td colspan="7" class="text-center py-4 text-muted">
                                                    <i class="fa fa-info-circle fa-2x mb-2 d-block"></i>
                                                    No project resource allocation records found.
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
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-beta.1/js/select2.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap5.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        $(document).ready(function() {
            $('.js-example-basic-single').select2({
                placeholder: "-- Select Project --",
                allowClear: true
            });

            $('.js-example-basic-multiple').select2({
                placeholder: "Select Team Members",
                allowClear: true
            });

            $('#allocatedProjectTable').DataTable({
                dom: '<"d-flex align-items-center justify-content-between mb-3"Bf>rt<"d-flex align-items-center justify-content-between mt-3"ip>',
                buttons: [
                    { extend: 'excelHtml5', text: '📊 Export to Excel', className: 'btn btn-success btn-sm fw-bold me-2' },
                    { extend: 'csvHtml5', text: '📄 Export to CSV', className: 'btn btn-outline-secondary btn-sm fw-bold me-2' },
                    { extend: 'print', text: '🖨️ Print Matrix', className: 'btn btn-outline-info btn-sm fw-bold' }
                ],
                pageLength: 25,
                ordering: true,
                order: [[0, 'asc']]
            });
        });

        function deletefun(page, id) {
            Swal.fire({
                title: "Are you sure?",
                text: "This project allocation will be removed!",
                icon: "warning",
                showCancelButton: true,
                confirmButtonColor: "#ef4444",
                cancelButtonColor: "#64748b",
                confirmButtonText: "Yes, delete allocation"
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = page + ".php?delete=" + id;
                }
            });
        }
    </script>
</body>
</html>