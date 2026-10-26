<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    header('location:login.php');
    exit();
}

include('../include/db_config.php');

$userId       = $_SESSION['userId'];
$userRole     = strtolower($_SESSION['userRole'] ?? '');
$designation  = strtolower($_SESSION['designation'] ?? '');
$userName     = htmlspecialchars($_SESSION['fullName'] ?? $_SESSION['userName'] ?? 'User');

$isJuniorIntern = ($userRole === 'junior_intern') || ($designation === 'juniorintern');

$projects = [];

if ($isJuniorIntern) {
    // Fetch allocated projects for junior intern
    $allocatedProjectIds = [];
    $q = mysqli_query($con, "SELECT projectId FROM allocatedProject WHERE userId = '$userId'");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $allocatedProjectIds[] = (int)$r['projectId'];
        }
    }

    if (!empty($allocatedProjectIds)) {
        $idList = implode(',', $allocatedProjectIds);
        $sql = "SELECT * FROM projectMaster WHERE projectId IN ($idList) ORDER BY modificationDate DESC, projectId DESC";
        $pq = mysqli_query($con, $sql);
        if ($pq) {
            while ($row = mysqli_fetch_assoc($pq)) {
                $projects[] = $row;
            }
        }
    }
} else {
    // Fetch all projects for employees / seniors / admin
    $sql = "SELECT * FROM projectMaster ORDER BY modificationDate DESC, projectId DESC";
    $pq = mysqli_query($con, $sql);
    if ($pq) {
        while ($row = mysqli_fetch_assoc($pq)) {
            $projects[] = $row;
        }
    }
}

// Calculate KPI Analytics
$totalProjects    = count($projects);
$activeProjects   = 0;
$highPriority     = 0;
$totalBudgetHours = 0.0;

foreach ($projects as $p) {
    if ((int)($p['status'] ?? 0) === 1) {
        $activeProjects++;
    }
    $prio = strtoupper($p['priority'] ?? '');
    if ($prio === 'P1' || $prio === 'P2') {
        $highPriority++;
    }
    $totalBudgetHours += floatval($p['budget_hours'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Employee Projects</title>
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

        #projectsTable thead th {
            background: #f8fafc; color: #475569; font-weight: 700;
            font-size: 0.78rem; text-transform: uppercase; border-bottom: 2px solid #e2e8f0;
        }

        .export-btn-group {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .btn-export-excel {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%) !important;
            color: #ffffff !important;
            border: none !important;
            font-weight: 600 !important;
            font-size: 0.8rem !important;
            padding: 7px 16px !important;
            border-radius: 8px !important;
            transition: all 0.2s ease !important;
            box-shadow: 0 2px 6px rgba(16, 185, 129, 0.25) !important;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .btn-export-excel:hover {
            background: linear-gradient(135deg, #059669 0%, #047857 100%) !important;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.35) !important;
            transform: translateY(-1px);
            color: #ffffff !important;
        }
        .btn-export-pdf {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%) !important;
            color: #ffffff !important;
            border: none !important;
            font-weight: 600 !important;
            font-size: 0.8rem !important;
            padding: 7px 16px !important;
            border-radius: 8px !important;
            transition: all 0.2s ease !important;
            box-shadow: 0 2px 6px rgba(239, 68, 68, 0.25) !important;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .btn-export-pdf:hover {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%) !important;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.35) !important;
            transform: translateY(-1px);
            color: #ffffff !important;
        }
        @media (max-width: 576px) {
            .export-btn-group {
                width: 100%;
                justify-content: space-between;
                margin-top: 8px;
            }
            .export-btn-group .btn {
                flex: 1;
                text-align: center;
                justify-content: center;
            }
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.4.1/css/responsive.bootstrap4.min.css">
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
                                <i class="fa-solid fa-folder-tree text-primary me-2"></i> Employee Projects
                            </h3>
                            <p class="text-muted small mb-0">View allocated projects, access staging URLs, repository links, and server credentials.</p>
                        </div>
                        <div>
                            <span class="badge bg-white text-dark border px-3 py-2" style="font-size:0.85rem; border-radius:20px;">
                                👤 <strong><?= $userName ?></strong> <span class="text-muted">(<?= $isJuniorIntern ? 'Junior Intern' : 'Team Member' ?>)</span>
                            </span>
                        </div>
                    </div>

                    <!-- ── 1. KPI Analytics Summary Dashboard ── -->
                    <div class="row mb-4">
                        <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-lbl"><?= $isJuniorIntern ? 'Allocated Projects' : 'Total Projects' ?></div>
                                    <div class="kpi-val text-primary"><?= $totalProjects ?></div>
                                    <small class="text-muted" style="font-size:0.72rem;"><?= $isJuniorIntern ? 'Assigned to you' : 'In system' ?></small>
                                </div>
                                <div class="kpi-icon" style="background:#EEEDFE; color:#4b49ac;">
                                    <i class="fa-solid fa-diagram-project"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-lbl">Active Projects</div>
                                    <div class="kpi-val text-success"><?= $activeProjects ?></div>
                                    <small class="text-muted" style="font-size:0.72rem;">Operational</small>
                                </div>
                                <div class="kpi-icon" style="background:#E6F4EA; color:#1E8E3E;">
                                    <i class="fa-solid fa-circle-check"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-lbl">High Priority</div>
                                    <div class="kpi-val text-danger"><?= $highPriority ?></div>
                                    <small class="text-muted" style="font-size:0.72rem;">P1 & P2 Priority</small>
                                </div>
                                <div class="kpi-icon" style="background:#FCE8E6; color:#C5221F;">
                                    <i class="fa-solid fa-fire"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-lbl">Budget Hours</div>
                                    <div class="kpi-val text-warning" style="color:#b06000 !important;"><?= number_format($totalBudgetHours, 1) ?> <span style="font-size:0.9rem;">hrs</span></div>
                                    <small class="text-muted" style="font-size:0.72rem;">Allocated work hours</small>
                                </div>
                                <div class="kpi-icon" style="background:#FEF7E0; color:#B06000;">
                                    <i class="fa-solid fa-clock"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── 2. Project Directory Table Section ── -->
                    <div class="card">
                        <div class="card-body p-4">
                            <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                                <div class="d-flex align-items-center gap-3">
                                    <h4 class="card-title mb-0">
                                        <i class="fa-solid fa-list-check text-primary me-2"></i> Project Access Directory
                                    </h4>
                                    <!-- Hosting Filter Dropdown -->
                                    <div class="d-flex align-items-center gap-2">
                                        <label for="hostingFilter" class="text-muted fw-bold mb-0" style="font-size:0.8rem;">Hosting Filter:</label>
                                        <select id="hostingFilter" class="form-control" style="width:160px;">
                                            <option value="">All Hosting Types</option>
                                            <option value="Git">Git Repository</option>
                                            <option value="FTP">FTP Server</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="export-btn-group">
                                    <button id="exportExcel" class="btn btn-export-excel" title="Export to Excel" onclick="exportTableToExcel()">
                                        <i class="fa-solid fa-file-excel me-1"></i> Excel
                                    </button>
                                    <button id="downloadPdf" class="btn btn-export-pdf" title="Download as PDF" onclick="exportTableToPDF()">
                                        <i class="fa-solid fa-file-pdf me-1"></i> PDF
                                    </button>
                                </div>
                            </div>
                            <hr class="text-muted opacity-25 mb-4">

                            <div class="table-responsive">
                                <table class="table table-hover align-middle w-100" id="projectsTable" style="font-size:0.85rem;">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Project</th>
                                            <th>Client</th>
                                            <th>Hosting & Credentials</th>
                                            <th>Priority</th>
                                            <th>Budget Hours</th>
                                            <th>Hourly Rate</th>
                                            <th>Deadline</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        if (!empty($projects)):
                                            $sr = 1;
                                            foreach ($projects as $p):
                                                $hosting = strtoupper(trim($p['hostingType'] ?? ''));
                                                $isFtp = $hosting === 'FTP';
                                                $isGit = $hosting === 'GIT';

                                                // Status badge
                                                $statusBadge = ($p['status'] == 1)
                                                    ? '<span class="badge bg-success text-white px-3 py-1" style="border-radius:20px; font-size:0.78rem;"><i class="fa-solid fa-circle-check me-1"></i> Active</span>'
                                                    : '<span class="badge bg-secondary text-white px-3 py-1" style="border-radius:20px; font-size:0.78rem;"><i class="fa-solid fa-circle-xmark me-1"></i> Inactive</span>';

                                                // Priority badge
                                                $prio = strtoupper($p['priority'] ?? 'P3');
                                                if ($prio === 'P1') {
                                                    $priorityBadge = '<span class="badge bg-danger text-white px-2 py-1" style="border-radius:6px; font-weight:700;"><i class="fa-solid fa-fire me-1"></i> P1 - Critical</span>';
                                                } elseif ($prio === 'P2') {
                                                    $priorityBadge = '<span class="badge bg-warning text-dark px-2 py-1" style="border-radius:6px; font-weight:700;"><i class="fa-solid fa-triangle-exclamation me-1"></i> P2 - High</span>';
                                                } elseif ($prio === 'P3') {
                                                    $priorityBadge = '<span class="badge bg-info text-white px-2 py-1" style="border-radius:6px; font-weight:700;"><i class="fa-solid fa-bars me-1"></i> P3 - Medium</span>';
                                                } else {
                                                    $priorityBadge = '<span class="badge bg-secondary text-white px-2 py-1" style="border-radius:6px; font-weight:600;">P4 - Low</span>';
                                                }

                                                // Hosting details
                                                $accessLink = !empty($p['git_access_link']) ? $p['git_access_link'] : '';
                                                $repoLink   = !empty($p['git_repo_link']) ? $p['git_repo_link'] : '';

                                                $accessBtn = !empty($accessLink) ? '<a href="' . htmlspecialchars($accessLink) . '" target="_blank" class="text-primary fw-bold ms-1" style="font-size:0.78rem;" title="Open Dev / Staging Server URL"><i class="fa-solid fa-globe me-1"></i>Open App</a>' : '';
                                                $repoBtn   = !empty($repoLink) ? '<a href="' . htmlspecialchars($repoLink) . '" target="_blank" class="text-dark ms-1" style="font-size:0.78rem;" title="Open Git Repository"><i class="fa-brands fa-github me-1"></i>Repo</a>' : '';

                                                if ($isGit) {
                                                    $hostingCol = '<div><span class="badge bg-dark text-white px-2 py-1" style="border-radius:6px;"><i class="fa-brands fa-github me-1"></i> Git</span> ' . $repoBtn . ' ' . $accessBtn . '</div>';
                                                } elseif ($isFtp) {
                                                    $ftpLink = !empty($p['Link']) ? $p['Link'] : '';
                                                    $ftpHost = !empty($p['Host']) ? htmlspecialchars($p['Host']) : '';
                                                    $ftpUser = !empty($p['User']) ? htmlspecialchars($p['User']) : '';
                                                    $ftpPass = !empty($p['Pass']) ? htmlspecialchars($p['Pass']) : '';

                                                    $ftpDetails = '';
                                                    if (!empty($ftpHost) || !empty($ftpUser)) {
                                                        $ftpDetails = '<div class="mt-1" style="font-size:0.75rem; line-height:1.35;">';
                                                        if (!empty($ftpHost)) $ftpDetails .= '<div><span class="text-muted">Host:</span> <strong class="text-dark">' . $ftpHost . '</strong></div>';
                                                        if (!empty($ftpUser)) $ftpDetails .= '<div><span class="text-muted">User:</span> <code class="text-primary">' . $ftpUser . '</code></div>';
                                                        if (!empty($ftpPass)) $ftpDetails .= '<div><span class="text-muted">Pass:</span> <code class="text-danger fw-bold">' . $ftpPass . '</code> <button type="button" class="btn btn-link text-secondary p-0 border-0 ms-1" style="font-size:0.75rem;" onclick="navigator.clipboard.writeText(\'' . addslashes($ftpPass) . '\')" title="Copy Password"><i class="fa-regular fa-copy"></i></button></div>';
                                                        $ftpDetails .= '</div>';
                                                    }

                                                    $ftpBtn  = !empty($ftpLink) ? '<a href="' . htmlspecialchars($ftpLink) . '" target="_blank" class="text-primary fw-bold ms-1" style="font-size:0.78rem;" title="Open FTP Link"><i class="fa-solid fa-arrow-up-right-from-square me-1"></i> Panel</a>' : '';
                                                    $hostingCol = '<div><span class="badge bg-primary text-white px-2 py-1" style="border-radius:6px;"><i class="fa-solid fa-server me-1"></i> FTP</span> ' . $ftpBtn . $ftpDetails . '</div>';
                                                } else {
                                                    $hostingCol = (!empty($accessBtn) ? $accessBtn : '<span class="text-muted" style="font-size:0.8rem;">—</span>');
                                                }

                                                // Budget Hours & Rate
                                                $budgetHours = (!empty($p['budget_hours']) && floatval($p['budget_hours']) > 0)
                                                    ? '<strong class="text-dark">' . number_format($p['budget_hours'], 1) . '</strong> <span class="text-muted" style="font-size:0.75rem;">hrs</span>'
                                                    : '<span class="text-muted" style="font-style:italic;">—</span>';

                                                $hourlyRate = (!empty($p['hourly_rate']) && floatval($p['hourly_rate']) > 0)
                                                    ? '<span class="text-success fw-bold">₹' . number_format($p['hourly_rate'], 2) . '</span><span class="text-muted" style="font-size:0.72rem;">/hr</span>'
                                                    : '<span class="text-muted" style="font-style:italic;">—</span>';

                                                // Dates
                                                $deadline = !empty($p['deadLine']) && $p['deadLine'] !== '0000-00-00 00:00:00' ? date('d M Y', strtotime($p['deadLine'])) : '-';
                                                $modDate  = !empty($p['modificationDate']) && $p['modificationDate'] !== '0000-00-00 00:00:00' ? date('d M Y', strtotime($p['modificationDate'])) : '';
                                        ?>
                                                <tr>
                                                    <td class="fw-bold text-secondary"><?= $sr++ ?></td>
                                                    <td>
                                                        <div class="fw-bold text-dark" style="font-size:0.9rem;"><?= htmlspecialchars($p['projectName']) ?></div>
                                                        <?php if (!empty($modDate)): ?>
                                                            <small class="text-muted" style="font-size:0.72rem;">Updated: <?= $modDate ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><strong class="text-secondary"><?= htmlspecialchars($p['client']) ?></strong></td>
                                                    <td><?= $hostingCol ?></td>
                                                    <td><?= $priorityBadge ?></td>
                                                    <td><?= $budgetHours ?></td>
                                                    <td><?= $hourlyRate ?></td>
                                                    <td><span class="badge bg-light text-dark border px-2 py-1" style="font-size:0.78rem;"><i class="fa-solid fa-calendar-day me-1 text-primary"></i> <?= $deadline ?></span></td>
                                                    <td><?= $statusBadge ?></td>
                                                </tr>
                                        <?php 
                                            endforeach;
                                        endif; 
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
            var table = $('#projectsTable').DataTable({
                responsive: true,
                order: [],
                pageLength: 10,
                lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "All"]],
                language: {
                    search: "Filter Projects:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ projects",
                    emptyTable: "No projects allocated or found"
                }
            });

            $('#hostingFilter').on('change', function() {
                var val = $(this).val();
                table.column(3).search(val).draw();
            });
        });

        function exportTableToExcel() {
            var tableClone = document.createElement("table");
            var headerRow = tableClone.insertRow();
            var headers = ["Project Name", "Client", "Hosting", "Priority", "Budget Hours", "Hourly Rate", "Deadline", "Status"];
            headers.forEach(h => {
                var th = document.createElement("th");
                th.innerText = h;
                headerRow.appendChild(th);
            });

            var table = $('#projectsTable').DataTable();
            table.rows().data().each(function(row) {
                var tr = tableClone.insertRow();
                tr.innerHTML = `
                    <td>${$(row[1]).find('.fw-bold').text().trim() || $(row[1]).text().trim()}</td>
                    <td>${$(row[2]).text().trim()}</td>
                    <td>${$(row[3]).text().trim()}</td>
                    <td>${$(row[4]).text().trim()}</td>
                    <td>${$(row[5]).text().trim()}</td>
                    <td>${$(row[6]).text().trim()}</td>
                    <td>${$(row[7]).text().trim()}</td>
                    <td>${$(row[8]).text().trim()}</td>
                `;
            });

            var workbook = XLSX.utils.table_to_book(tableClone, { sheet: "Employee Projects Report" });
            XLSX.writeFile(workbook, 'Employee_Projects_Report.xlsx');
        }

        async function exportTableToPDF() {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF('landscape');
            doc.text('Employee Projects Directory Report', 14, 15);

            const tableData = [];
            var table = $('#projectsTable').DataTable();
            table.rows().data().each(function(row) {
                tableData.push([
                    $(row[1]).find('.fw-bold').text().trim() || $(row[1]).text().trim(),
                    $(row[2]).text().trim(),
                    $(row[3]).text().trim(),
                    $(row[4]).text().trim(),
                    $(row[5]).text().trim(),
                    $(row[6]).text().trim(),
                    $(row[7]).text().trim(),
                    $(row[8]).text().trim()
                ]);
            });

            doc.autoTable({
                head: [["Project Name", "Client", "Hosting", "Priority", "Budget Hours", "Hourly Rate", "Deadline", "Status"]],
                body: tableData,
                startY: 25,
            });

            doc.save('Employee_Projects_Report.pdf');
        }
    </script>
</body>
</html>