<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
} elseif (isset($_SESSION['userRole']) && $_SESSION['userRole'] === 'junior_intern') {
    echo '<script>
        alert("You are not authorized to access this page.");
        window.location.href = "login.php";
        </script>';
    exit;
}

include('../include/db_config.php');

// Default values for Create mode
$projectId       = null;
$projectName     = '';
$client          = '';
$status          = '1';      // default Active
$deadLine        = '';
$priority        = '';
$budget_hours    = '';
$hourly_rate     = '';

$hostingType      = '';       // 'Git' or 'FTP'
$git_access_link  = '';
$git_repo_link    = '';

$Link = '';
$Host = '';
$User = '';
$Pass = '';

// Check edit mode
if (isset($_GET['projectId'])) {
    $projectId = mysqli_real_escape_string($con, $_GET['projectId']);
    $query = mysqli_query($con, "SELECT * FROM projectMaster WHERE projectId = '$projectId'");
    if ($query && $result = mysqli_fetch_assoc($query)) {
        $projectName  = $result['projectName'] ?? '';
        $client       = $result['client'] ?? '';
        $hostingType  = $result['hostingType'] ?? '';
        $status       = $result['status'] ?? '1';
        $deadLine     = $result['deadLine'] ?? '';
        $priority     = $result['priority'] ?? '';
        $budget_hours = $result['budget_hours'] ?? '';
        $hourly_rate  = $result['hourly_rate'] ?? '';

        if ($hostingType === 'Git') {
            $git_access_link = $result['git_access_link'] ?? '';
            $git_repo_link   = $result['git_repo_link'] ?? '';
        } elseif ($hostingType === 'FTP') {
            $Link = $result['Link'] ?? '';
            $Host = $result['Host'] ?? '';
            $User = $result['User'] ?? '';
            $Pass = $result['Pass'] ?? '';
        }
    }
}

// Fetch KPI Analytics Statistics
$kpiQ = mysqli_query($con, "
    SELECT 
        COUNT(*) AS totalProjects,
        SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) AS activeProjects,
        SUM(CASE WHEN priority IN ('P1', 'P2') THEN 1 ELSE 0 END) AS highPriority,
        SUM(COALESCE(budget_hours, 0)) AS totalBudgetHours
    FROM projectMaster
");
$kpiStats = $kpiQ ? mysqli_fetch_assoc($kpiQ) : [];
$totalProjects    = (int)($kpiStats['totalProjects'] ?? 0);
$activeProjects   = (int)($kpiStats['activeProjects'] ?? 0);
$highPriority     = (int)($kpiStats['highPriority'] ?? 0);
$totalBudgetHours = floatval($kpiStats['totalBudgetHours'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Project Master</title>
    <?php include('../partials/_links.php'); ?>
    <style>
        .card { border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 2px 10px rgba(0,0,0,0.03); }
        .card-title { font-size: 1.15rem; font-weight: 700; color: #1e293b; }
        .form-control { border-radius: 8px; border: 1px solid #cbd5e1; padding: 10px 14px; font-size: 0.88rem; }
        .form-control:focus { border-color: #4b49ac; box-shadow: 0 0 0 3px rgba(75,73,172,0.12); }
        
        /* ── Custom Select Dropdown Styling ── */
        select.form-control, select.form-select {
            height: 44px !important;
            padding: 10px 36px 10px 14px !important;
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

        select.form-control:focus, select.form-select:focus {
            border-color: #4b49ac !important;
            outline: 0 !important;
            box-shadow: 0 0 0 3px rgba(75, 73, 172, 0.15) !important;
        }

        .dataTables_length select {
            height: 38px !important;
            padding: 4px 30px 4px 12px !important;
            border-radius: 6px !important;
            border: 1px solid #cbd5e1 !important;
            font-size: 0.82rem !important;
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

        .status-group { display: flex; align-items: center; gap: 20px; margin-top: 0.4rem; }
        .status-group .form-check { display: flex; align-items: center; margin: 0; }
        .status-group .form-check-input { margin: 0 6px 0 0; position: static; cursor: pointer; }
        .status-group .form-check-label { margin: 0; cursor: pointer; font-weight: 600; }
        
        #projectMasterTable thead th {
            background: #f8fafc; color: #475569; font-weight: 700;
            font-size: 0.78rem; text-transform: uppercase; border-bottom: 2px solid #e2e8f0;
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
                                <i class="fa-solid fa-diagram-project text-primary me-2"></i> Project Master
                            </h3>
                            <p class="text-muted small mb-0">Manage projects, client associations, hosting credentials, priorities, and budget hours.</p>
                        </div>
                    </div>

                    <!-- ── 1. KPI Analytics Summary Dashboard ── -->
                    <div class="row mb-4">
                        <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-lbl">Total Projects</div>
                                    <div class="kpi-val text-primary"><?= $totalProjects ?></div>
                                    <small class="text-muted" style="font-size:0.72rem;">Registered projects</small>
                                </div>
                                <div class="kpi-icon" style="background:#EEEDFE; color:#4b49ac;">
                                    <i class="fa-solid fa-folder-open"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                            <div class="kpi-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="kpi-lbl">Active Projects</div>
                                    <div class="kpi-val text-success"><?= $activeProjects ?></div>
                                    <small class="text-muted" style="font-size:0.72rem;">Currently operational</small>
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
                                    <small class="text-muted" style="font-size:0.72rem;">Total allocated budget</small>
                                </div>
                                <div class="kpi-icon" style="background:#FEF7E0; color:#B06000;">
                                    <i class="fa-solid fa-stopwatch"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── 2. Create / Edit Project Form Card ── -->
                    <div class="card mb-4">
                        <div class="card-body p-4">
                            <h4 class="card-title mb-3" id="pageTitle">
                                <i class="fa-solid <?= isset($projectId) ? 'fa-pen-to-square' : 'fa-plus-circle' ?> text-primary me-2"></i>
                                <?= isset($projectId) ? 'Edit Project Details' : 'Create New Project' ?>
                            </h4>
                            <hr class="text-muted opacity-25 mb-4">

                            <form class="forms-sample" action="projectMaster_db.php" method="post" onsubmit="return validateForm()">
                                
                                <!-- Row 1: Project Name, Client Name, Hosting Type -->
                                <div class="row mb-3">
                                    <div class="col-md-4 mb-3 mb-md-0">
                                        <div class="form-group mb-0">
                                            <label for="projectName" class="fw-bold text-secondary mb-1">Project Name <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" id="projectName" name="projectName"
                                                value="<?= htmlspecialchars($projectName, ENT_QUOTES, 'UTF-8') ?>"
                                                placeholder="Enter Project Name" required>
                                        </div>
                                    </div>

                                    <div class="col-md-4 mb-3 mb-md-0">
                                        <div class="form-group mb-0">
                                            <label for="client" class="fw-bold text-secondary mb-1">Client Name <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" id="client" name="client"
                                                value="<?= htmlspecialchars($client, ENT_QUOTES, 'UTF-8') ?>"
                                                placeholder="Enter Client Name" required>
                                        </div>
                                    </div>

                                    <div class="col-md-4">
                                        <div class="form-group mb-0" id="hostingGroup">
                                            <label for="hostingType" class="fw-bold text-secondary mb-1">Project Hosted On <span class="text-danger">*</span></label>
                                            <select class="form-control" id="hostingType" name="hostingType" required>
                                                <option value="" selected disabled>Select Hosting Type</option>
                                                <option value="Git" <?= ($hostingType === 'Git') ? 'selected' : '' ?>>Git Repository</option>
                                                <option value="FTP" <?= ($hostingType === 'FTP') ? 'selected' : '' ?>>FTP Server</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <!-- Row 2: Sheet Confirmation -->
                                <div class="row mb-3">
                                    <div class="col-md-12">
                                        <div class="p-3 border rounded bg-light">
                                            <label class="fw-bold text-secondary mb-2 d-block" style="font-size:0.88rem;">
                                                Have you added the Project Name and Client Details in the 
                                                <span class="text-danger">SETTribe Project Current Status Sheet?</span> <span class="text-danger">*</span>
                                            </label>
                                            <div class="status-group">
                                                <div class="form-check">
                                                    <input class="form-check-input sheet-check" type="radio" name="sheetConfirmed" id="sheetYes" value="yes">
                                                    <label class="form-check-label text-success" for="sheetYes"><i class="fa-solid fa-check me-1"></i> Yes, Confirmed</label>
                                                </div>
                                                <div class="form-check">
                                                    <input class="form-check-input sheet-check" type="radio" name="sheetConfirmed" id="sheetNo" value="no">
                                                    <label class="form-check-label text-danger" for="sheetNo"><i class="fa-solid fa-xmark me-1"></i> Not Yet</label>
                                                </div>
                                                <a href="https://docs.google.com/spreadsheets/d/1Fbo5Wapr0V8V8StxKMXwmyTiT1LsBltdmGbYNAt4-Zc/edit?gid=392811506#gid=392811506"
                                                   target="_blank" id="openSheetBtn" class="btn btn-warning btn-sm text-dark fw-bold ms-3" style="display:none; border-radius:6px;">
                                                    <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> Open Google Sheet
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Row 3: Git Fields -->
                                <div class="git-fields mb-3" id="gitFields" style="display: <?= ($hostingType === 'Git') ? 'block' : 'none' ?>;">
                                    <div class="row">
                                        <div class="col-md-6 mb-3 mb-md-0">
                                            <div class="form-group mb-0">
                                                <label class="fw-bold text-secondary mb-1">Project Access Link (Dev / Staging URL)</label>
                                                <input type="url" name="git_access_link" id="git_access_link" class="form-control"
                                                    value="<?= htmlspecialchars($git_access_link ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                    placeholder="https://dev.example.com/project-name">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group mb-0">
                                                <label class="fw-bold text-secondary mb-1">Git Repository Link</label>
                                                <input type="url" name="git_repo_link" id="git_repo_link" class="form-control"
                                                    value="<?= htmlspecialchars($git_repo_link ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                    placeholder="https://github.com/organization/repo.git">
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Row 4: FTP Fields -->
                                <div class="ftp-fields mb-3" id="ftpFields" style="display: <?= ($hostingType === 'FTP') ? 'block' : 'none' ?>;">
                                    <div class="row mb-3">
                                        <div class="col-md-6 mb-3 mb-md-0">
                                            <div class="form-group mb-0">
                                                <label class="fw-bold text-secondary mb-1">FTP Link / Panel URL</label>
                                                <input type="url" name="Link" id="Link" class="form-control"
                                                    value="<?= htmlspecialchars($Link ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                    placeholder="https://panel.example.com">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group mb-0">
                                                <label class="fw-bold text-secondary mb-1">FTP Host</label>
                                                <input type="text" name="Host" id="Host" class="form-control"
                                                    value="<?= htmlspecialchars($Host ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                    placeholder="ftp.example.com">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6 mb-3 mb-md-0">
                                            <div class="form-group mb-0">
                                                <label class="fw-bold text-secondary mb-1">FTP Username</label>
                                                <input type="text" name="User" id="User" class="form-control"
                                                    value="<?= htmlspecialchars($User ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                    placeholder="Enter FTP Username">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group mb-0">
                                                <label class="fw-bold text-secondary mb-1">FTP Password</label>
                                                <input type="password" name="Pass" id="Pass" class="form-control"
                                                    value="<?= htmlspecialchars($Pass ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                    placeholder="Enter FTP Password">
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Row 5: Priority, Budget Hours, Hourly Rate, Deadline, Status -->
                                <div class="row mb-4">
                                    <div class="col-md-3 mb-3 mb-md-0">
                                        <div class="form-group mb-0">
                                            <label for="priority" class="fw-bold text-secondary mb-1">Priority <span class="text-danger">*</span></label>
                                            <select class="form-control" id="priority" name="priority" required>
                                                <option value="" disabled <?= empty($priority) ? 'selected' : '' ?>>Select Priority</option>
                                                <option value="P1" <?= ($priority === 'P1') ? 'selected' : '' ?>>P1 - Critical</option>
                                                <option value="P2" <?= ($priority === 'P2') ? 'selected' : '' ?>>P2 - High</option>
                                                <option value="P3" <?= ($priority === 'P3') ? 'selected' : '' ?>>P3 - Medium</option>
                                                <option value="P4" <?= ($priority === 'P4') ? 'selected' : '' ?>>P4 - Low</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="col-md-2 mb-3 mb-md-0">
                                        <div class="form-group mb-0">
                                            <label for="budget_hours" class="fw-bold text-secondary mb-1">Budget Hours</label>
                                            <input type="number" step="0.5" min="0" class="form-control" id="budget_hours" name="budget_hours"
                                                value="<?= htmlspecialchars($budget_hours, ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. 120.0">
                                        </div>
                                    </div>

                                    <div class="col-md-2 mb-3 mb-md-0">
                                        <div class="form-group mb-0">
                                            <label for="hourly_rate" class="fw-bold text-secondary mb-1">Hourly Rate (₹)</label>
                                            <input type="number" step="0.01" min="0" class="form-control" id="hourly_rate" name="hourly_rate"
                                                value="<?= htmlspecialchars($hourly_rate, ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. 500.00">
                                        </div>
                                    </div>

                                    <div class="col-md-3 mb-3 mb-md-0">
                                        <div class="form-group mb-0">
                                            <label for="deadLine" class="fw-bold text-secondary mb-1">Deadline <span class="text-danger">*</span></label>
                                            <?php $currentDateTime = date('Y-m-d\TH:i'); ?>
                                            <input type="datetime-local" class="form-control" id="deadLine" name="deadLine"
                                                value="<?= !empty($deadLine) ? date('Y-m-d\TH:i', strtotime($deadLine)) : $currentDateTime; ?>"
                                                min="<?= $currentDateTime ?>" required>
                                        </div>
                                    </div>

                                    <div class="col-md-2">
                                        <div class="form-group mb-0">
                                            <label class="fw-bold text-secondary mb-1 d-block">Status <span class="text-danger">*</span></label>
                                            <div class="status-group mt-1">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="radio" name="status" id="statusActive" value="1" <?= ($status !== '0') ? 'checked' : '' ?>>
                                                    <label class="form-check-label text-success" for="statusActive">Active</label>
                                                </div>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="radio" name="status" id="statusInactive" value="0" <?= ($status === '0') ? 'checked' : '' ?>>
                                                    <label class="form-check-label text-secondary" for="statusInactive">Inactive</label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Submit & Cancel Buttons -->
                                <div class="d-flex align-items-center gap-2">
                                    <button type="submit" id="submitBtn" class="btn btn-primary px-4 py-2" style="border-radius:8px; font-weight:600;"
                                        name="<?= isset($projectId) ? 'update' : 'create' ?>"
                                        value="<?= $projectId ?? '' ?>">
                                        <i class="fa-solid <?= isset($projectId) ? 'fa-pen-to-square' : 'fa-plus' ?> me-1"></i>
                                        <?= isset($projectId) ? 'Update Project' : 'Create Project' ?>
                                    </button>

                                    <a href="projectMaster.php" id="cancelBtn" class="btn btn-light px-4 py-2" style="border-radius:8px; display: <?= isset($projectId) ? 'inline-block' : 'none' ?>;">
                                        Cancel
                                    </a>
                                </div>

                            </form>
                        </div>
                    </div>

                    <!-- ── 3. All Projects Data Table Section ── -->
                    <div class="card">
                        <div class="card-body p-4">
                            <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                                <div>
                                    <h4 class="card-title mb-0">
                                        <i class="fa-solid fa-list-check text-primary me-2"></i> Registered Projects Directory
                                    </h4>
                                    <p class="text-muted small mb-0">View, search, edit, and export project records.</p>
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
                                <table class="table table-hover align-middle w-100" id="projectMasterTable" style="font-size:0.85rem;">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Project</th>
                                            <th>Client</th>
                                            <th>Hosting</th>
                                            <th>Priority</th>
                                            <th>Budget Hours</th>
                                            <th>Hourly Rate</th>
                                            <th>Deadline</th>
                                            <th>Status</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="projectTableBody"></tbody>
                                </table>
                            </div>

                        </div>
                    </div>

                </div> <!-- Content Wrapper -->
            </div> <!-- Main Panel -->

        </div>
    </div>

    <!-- Vendors & Scripts -->
    <script src="../vendors/js/vendor.bundle.base.js"></script>
    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>
    <script src="../js/settings.js"></script>

    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.17.0/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.4.0/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.23/jspdf.plugin.autotable.min.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const hostingType = document.getElementById('hostingType');
            const gitFields   = document.getElementById('gitFields');
            const ftpFields   = document.getElementById('ftpFields');

            function toggleHostingFields() {
                if (!hostingType) return;
                gitFields.style.display = 'none';
                ftpFields.style.display = 'none';

                if (hostingType.value === 'Git') {
                    if (ftpFields) ftpFields.querySelectorAll('input').forEach(i => i.value = '');
                    gitFields.style.display = 'block';
                } else if (hostingType.value === 'FTP') {
                    if (gitFields) gitFields.querySelectorAll('input').forEach(i => i.value = '');
                    ftpFields.style.display = 'block';
                }
            }

            if (hostingType) {
                hostingType.addEventListener('change', toggleHostingFields);
                toggleHostingFields();
            }

            // Google Sheet Confirmation logic
            const submitBtn    = document.getElementById('submitBtn');
            const openSheetBtn = document.getElementById('openSheetBtn');
            const sheetRadios  = document.querySelectorAll('.sheet-check');
            const isEditMode   = <?= isset($projectId) ? 'true' : 'false' ?>;

            if (!isEditMode) {
                submitBtn.disabled = true;
            }

            sheetRadios.forEach(radio => {
                radio.addEventListener('change', function () {
                    if (this.value === 'yes') {
                        submitBtn.disabled = false;
                        openSheetBtn.style.display = 'none';
                    } else {
                        submitBtn.disabled = true;
                        openSheetBtn.style.display = 'inline-block';
                    }
                });
            });
        });

        function validateForm() {
            var projectName = document.getElementById("projectName").value;
            var clientName  = document.getElementById("client").value;

            var projectNameRegex = /^[0-9]/;
            if (projectNameRegex.test(projectName)) {
                alert("Error: Project Name should not start with a number.");
                return false;
            }

            var clientNameRegex = /^[A-Za-z\s,-]+$/;
            if (!clientNameRegex.test(clientName)) {
                alert("Error: Client Name should contain only alphabets.");
                return false;
            }

            return true;
        }

        function edit(projectId) {
            window.location.href = "projectMaster.php?projectId=" + projectId;
        }

        $(document).ready(function() {
            loadProjects();

            function loadProjects() {
                $.ajax({
                    url: "projectFetch.php",
                    type: "GET",
                    success: function(data) {
                        if ($.fn.DataTable.isDataTable('#projectMasterTable')) {
                            $('#projectMasterTable').DataTable().destroy();
                        }

                        $("#projectTableBody").html(data);

                        $('#projectMasterTable').DataTable({
                            responsive: true,
                            order: [],
                            language: {
                                search: "Filter Projects:",
                                lengthMenu: "Show _MENU_ projects",
                                info: "Showing _START_ to _END_ of _TOTAL_ projects",
                                emptyTable: "No projects registered"
                            }
                        });
                    },
                    error: function(err) {
                        console.error("Error loading projects:", err.responseText);
                    }
                });
            }
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

            var table = $('#projectMasterTable').DataTable();
            table.rows().data().each(function(row) {
                var tr = tableClone.insertRow();
                tr.innerHTML = `
                    <td>${$(row[1]).text().trim()}</td>
                    <td>${$(row[2]).text().trim()}</td>
                    <td>${$(row[3]).text().trim()}</td>
                    <td>${$(row[4]).text().trim()}</td>
                    <td>${$(row[5]).text().trim()}</td>
                    <td>${$(row[6]).text().trim()}</td>
                    <td>${$(row[7]).text().trim()}</td>
                    <td>${$(row[8]).text().trim()}</td>
                `;
            });

            var workbook = XLSX.utils.table_to_book(tableClone, { sheet: "Projects Report" });
            XLSX.writeFile(workbook, 'ProjectMaster_Report.xlsx');
        }

        async function exportTableToPDF() {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF('landscape');
            doc.text('Project Master Directory Report', 14, 15);

            const tableData = [];
            var table = $('#projectMasterTable').DataTable();
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

            doc.save('ProjectMaster_Report.pdf');
        }
    </script>

    <?php
    require_once('../include/sweetAlert.php');
    if (isset($_SESSION['status'])) {
        $status = $_SESSION['status'];
        $msg    = $_SESSION['msg'];
        if ($status != true) {
            toastMsg('error', $msg);
        } else {
            toastMsg('success', $msg);
        }
        unset($_SESSION['status']);
        unset($_SESSION['msg']);
    }
    ?>
</body>
</html>