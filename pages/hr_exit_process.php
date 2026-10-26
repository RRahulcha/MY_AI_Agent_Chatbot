<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

$role = isset($_SESSION['userRole']) ? strtolower(trim($_SESSION['userRole'])) : '';
$desig = isset($_SESSION['designation']) ? strtolower(trim($_SESSION['designation'])) : '';
if ($role !== 'admin' && $role !== 'hr' && $desig !== 'admin' && $desig !== 'hr') {
    echo '<script>alert("Unauthorized access."); window.location.href = "login.php";</script>';
    exit;
}

include('../include/db_config.php');
date_default_timezone_set('Asia/Kolkata');

// ── Analytics Metrics ────────────────────────────────────────────────────────
$totalExit    = (int)mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as c FROM exit_process"))['c'];
$approvedExit = (int)mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as c FROM exit_process WHERE status = 'Approved'"))['c'];
$pendingExit  = (int)mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as c FROM exit_process WHERE status LIKE '%Pending%'"))['c'];
$rejectedExit = (int)mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as c FROM exit_process WHERE status = 'Rejected'"))['c'];

$techHandoverCount = (int)mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as c FROM exit_process WHERE tech_handover = 1"))['c'];
$accessRevokedCount = (int)mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as c FROM exit_process WHERE access_revoked = 1"))['c'];
$exitInterviewCount = (int)mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as c FROM exit_process WHERE exit_interview = 1"))['c'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <title>SETTribe · HR Exit Process Portal</title>
    <?php include('../partials/_links.php'); ?>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.4.1/css/responsive.bootstrap4.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary: #4f46e5;
            --primary-dark: #3730a3;
            --primary-light: #eef2ff;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --purple: #8b5cf6;
            --border: #e2e8f0;
            --radius-lg: 14px;
            --radius-md: 10px;
        }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: #f1f5f9;
            color: #1e293b;
        }

        /* ── Header Banner ── */
        .analytics-header-card {
            background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #312e81 100%);
            border-radius: var(--radius-lg);
            padding: 26px 30px;
            color: #ffffff;
            margin-bottom: 24px;
            box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.3);
            position: relative;
            overflow: hidden;
        }
        .analytics-header-card::after {
            content: '';
            position: absolute;
            right: -50px;
            top: -50px;
            width: 240px;
            height: 240px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 50%;
            pointer-events: none;
        }
        .header-title-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 0.76rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 10px;
        }

        /* ── KPI Cards ── */
        .kpi-card {
            background: #ffffff;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            padding: 18px 20px;
            height: 100%;
            box-shadow: 0 4px 14px rgba(0,0,0,0.03);
            transition: all 0.25s ease;
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .kpi-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 24px rgba(0,0,0,0.06);
            border-color: #cbd5e1;
        }
        .kpi-card::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; }
        .kpi-blue::before    { background: var(--primary); }
        .kpi-green::before   { background: var(--success); }
        .kpi-amber::before   { background: var(--warning); }
        .kpi-red::before     { background: var(--danger); }

        .kpi-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
        .kpi-title { font-size: 0.78rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.4px; }
        .kpi-icon-wrap { width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1rem; }
        .icon-blue   { background: var(--primary-light); color: var(--primary); }
        .icon-green  { background: #ecfdf5; color: var(--success); }
        .icon-amber  { background: #fffbeb; color: var(--warning); }
        .icon-red    { background: #fef2f2; color: var(--danger); }

        .kpi-value { font-size: 1.7rem; font-weight: 800; color: #0f172a; line-height: 1.1; margin-bottom: 6px; }
        .kpi-sub { font-size: 0.75rem; color: #64748b; font-weight: 600; }

        /* ── Section Cards ── */
        .analytics-table-card {
            background: #ffffff;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            padding: 24px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.03);
            margin-bottom: 30px;
        }

        .btn-gradient-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: #ffffff; border: none; font-weight: 700; padding: 10px 26px; border-radius: var(--radius-md); box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25); transition: all 0.2s;
        }
        .btn-gradient-primary:hover { color: #fff; transform: translateY(-1px); }

        /* Select2 wrap fixes */
        .select2-container--default .select2-selection--single {
            height: 42px; border-radius: var(--radius-md); border: 1px solid var(--border); padding-top: 6px; font-weight: 600;
        }

        /* Criteria box */
        .criteria-box-card {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border: 1px solid #cbd5e1;
            border-radius: var(--radius-md);
            padding: 16px 20px;
            margin-bottom: 20px;
        }
        .stat-value { font-size: 1.4rem; font-weight: 800; color: #0f172a; }

        /* Upload Area */
        .upload-area-custom {
            border: 2px dashed var(--primary);
            border-radius: var(--radius-md);
            padding: 20px;
            text-align: center;
            background: #f8fafc;
            cursor: pointer;
            transition: all 0.25s ease;
        }
        .upload-area-custom:hover {
            background: var(--primary-light);
            border-color: var(--primary-dark);
        }

        /* Checkbox cards */
        .custom-switch-card {
            background: #f8fafc;
            border: 1px solid var(--border);
            padding: 14px 16px;
            border-radius: var(--radius-md);
            height: 100%;
        }

        #hrHistoryTable { width: 100% !important; border-collapse: separate; border-spacing: 0; }
        #hrHistoryTable thead th {
            background: linear-gradient(135deg, #1e293b, #334155); color: #fff; padding: 12px 14px; font-weight: 700;
            font-size: .78rem; text-transform: uppercase; letter-spacing: .4px; border: none; text-align:center;
        }
        #hrHistoryTable thead th:first-child { border-top-left-radius: 8px; }
        #hrHistoryTable thead th:last-child { border-top-right-radius: 8px; }
        #hrHistoryTable tbody td {
            padding: 11px 14px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; text-align:center; color:#334155; font-size:.84rem;
        }
        #hrHistoryTable tbody tr:hover td { background: #f8fafc !important; }
    </style>
</head>

<body>
    <div class="container-scroller">
        <?php include('../partials/_navbar.php'); ?>

        <div class="container-fluid page-body-wrapper">
            <?php include('../partials/_sidebar.php'); include('../partials/_settings-panel.php'); ?>

            <div class="main-panel">
                <div class="content-wrapper">

                    <!-- ── HEADER BANNER ── -->
                    <div class="analytics-header-card">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                            <div>
                                <div class="header-title-badge">
                                    <i class="fas fa-door-open"></i> Offboarding Clearance Portal
                                </div>
                                <h2 style="font-size: 1.65rem; font-weight: 800; margin-bottom: 4px;">
                                    HR Exit Process &amp; Relieving Clearance
                                </h2>
                                <p style="font-size: 0.85rem; opacity: 0.88; margin: 0;">
                                    Initiate exit clearances, evaluate attendance &amp; task criteria, upload draft experience letters, and dispatch relieving confirmations.
                                </p>
                            </div>
                            <div>
                                <span class="badge badge-light text-primary font-weight-bold p-2" style="border-radius:10px; font-size:0.82rem;">
                                    <i class="fas fa-file-contract mr-1"></i> Total Clearances: <?= $totalExit ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- ── KPI METRICS ROW ── -->
                    <div class="row mb-4">
                        <div class="col-xl-3 col-md-6 col-sm-6 mb-3 mb-xl-0">
                            <div class="kpi-card kpi-blue">
                                <div class="kpi-header">
                                    <span class="kpi-title">Total Requests</span>
                                    <div class="kpi-icon-wrap icon-blue"><i class="fas fa-clipboard-list"></i></div>
                                </div>
                                <div>
                                    <div class="kpi-value"><?= $totalExit ?></div>
                                    <div class="kpi-sub">Total Initiated Exit Processes</div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-3 col-md-6 col-sm-6 mb-3 mb-xl-0">
                            <div class="kpi-card kpi-green">
                                <div class="kpi-header">
                                    <span class="kpi-title">Approved Clearances</span>
                                    <div class="kpi-icon-wrap icon-green"><i class="fas fa-check-circle"></i></div>
                                </div>
                                <div>
                                    <div class="kpi-value"><?= $approvedExit ?></div>
                                    <div class="kpi-sub">Relieving Approved by RH</div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-3 col-md-6 col-sm-6 mb-3 mb-xl-0">
                            <div class="kpi-card kpi-amber">
                                <div class="kpi-header">
                                    <span class="kpi-title">Pending RH Review</span>
                                    <div class="kpi-icon-wrap icon-amber"><i class="fas fa-clock"></i></div>
                                </div>
                                <div>
                                    <div class="kpi-value"><?= $pendingExit ?></div>
                                    <div class="kpi-sub">Awaiting Decision</div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-3 col-md-6 col-sm-6 mb-3 mb-xl-0">
                            <div class="kpi-card kpi-red">
                                <div class="kpi-header">
                                    <span class="kpi-title">Revision Required</span>
                                    <div class="kpi-icon-wrap icon-red"><i class="fas fa-exclamation-triangle"></i></div>
                                </div>
                                <div>
                                    <div class="kpi-value"><?= $rejectedExit ?></div>
                                    <div class="kpi-sub">Rejected / Resubmit Needed</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── CHARTS ROW ── -->
                    <div class="row mb-4">
                        <!-- Exit Status Doughnut Chart -->
                        <div class="col-lg-5 mb-3 mb-lg-0">
                            <div class="analytics-table-card h-100" style="padding:20px;">
                                <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
                                    <h5 style="font-size:.95rem; font-weight:800; color:#0f172a; margin:0;">
                                        <i class="fas fa-chart-pie text-primary mr-1"></i> Exit Process Status Split
                                    </h5>
                                    <span class="badge badge-light border" style="font-size:.72rem;">Status Distribution</span>
                                </div>
                                <div style="position: relative; height: 210px;">
                                    <canvas id="exitStatusDoughnut"></canvas>
                                </div>
                            </div>
                        </div>

                        <!-- Offboarding Checklist Completion Bar Chart -->
                        <div class="col-lg-7">
                            <div class="analytics-table-card h-100" style="padding:20px;">
                                <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
                                    <h5 style="font-size:.95rem; font-weight:800; color:#0f172a; margin:0;">
                                        <i class="fas fa-tasks text-purple mr-1"></i> Offboarding Checklist Completion
                                    </h5>
                                    <span class="badge badge-light border" style="font-size:.72rem;">Compliance Stats</span>
                                </div>
                                <div style="position: relative; height: 210px;">
                                    <canvas id="checklistBarChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── INITIATE EXIT PROCESS FORM CARD ── -->
                    <div class="analytics-table-card mb-4" id="formCard">
                        <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
                            <h5 style="font-size:1.05rem; font-weight:800; color:#0f172a; margin:0;" id="formTitle">
                                <i class="fas fa-file-signature text-primary mr-1"></i> Initiate Exit Clearance Process
                            </h5>
                            <span class="badge badge-primary font-weight-bold" style="font-size:.75rem;">HR Clearance Action</span>
                        </div>

                        <div id="editRemarkAlert" class="alert alert-warning d-none font-weight-bold mb-3" style="border-radius:10px;"></div>

                        <form action="submit_exit_process.php" method="POST" enctype="multipart/form-data" id="exitForm">
                            <input type="hidden" name="exit_id" id="update_exit_id" value="">
                            <input type="hidden" name="userId" id="hidden_userId" value="">

                            <!-- User Select Dropdown -->
                            <div class="form-group mb-3">
                                <label style="font-weight:700; font-size:.85rem; color:#334155;">Select Employee / Intern <span class="text-danger">*</span></label>
                                <select class="form-control select2" name="userId" id="internSelect" required>
                                    <option value="">-- Search &amp; Select Employee / Intern --</option>
                                    <?php
                                    $q = mysqli_query($con, "SELECT userId, fullName, designation, emp_id, internId FROM signup WHERE designation != 'admin' AND designation != 'superadmin' ORDER BY fullName ASC");
                                    while ($r = mysqli_fetch_assoc($q)) {
                                        $idTag = !empty($r['emp_id']) ? $r['emp_id'] : (!empty($r['internId']) ? $r['internId'] : $r['userId']);
                                        echo "<option value='" . htmlspecialchars($r['userId']) . "'>" . htmlspecialchars($r['fullName']) . " (" . htmlspecialchars($r['designation']) . " - " . htmlspecialchars($idTag) . ")</option>";
                                    }
                                    ?>
                                </select>
                            </div>

                            <!-- Dynamic Criteria Box -->
                            <div class="criteria-box-card d-none" id="criteriaBox">
                                <div class="row text-center">
                                    <div class="col-md-6 border-right">
                                        <p class="text-muted mb-1 font-weight-bold" style="font-size:12px;">Attendance Benchmark (Min 80%)</p>
                                        <div class="stat-value" id="attPercent">--%</div>
                                        <span class="badge badge-pill mt-1" id="attBadge">Pending</span>
                                        <input type="hidden" name="attendance_percentage" id="inputAtt">
                                    </div>
                                    <div class="col-md-6">
                                        <p class="text-muted mb-1 font-weight-bold" style="font-size:12px;">Completed Tasks Benchmark (Min 10)</p>
                                        <div class="stat-value" id="taskCount">--</div>
                                        <span class="badge badge-pill mt-1" id="taskBadge">Pending</span>
                                        <input type="hidden" name="tasks_completed" id="inputTask">
                                    </div>
                                </div>
                            </div>

                            <!-- Reason and Feedback -->
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <div class="form-group mb-0">
                                        <label style="font-weight:700; font-size:.85rem; color:#334155;">Reason for Leaving <span class="text-danger">*</span></label>
                                        <textarea class="form-control" name="reason" id="reasonField" rows="3" required placeholder="Specify detailed reason for leaving..." style="resize:none; border-radius:var(--radius-md);"></textarea>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="form-group mb-0">
                                        <label style="font-weight:700; font-size:.85rem; color:#334155;">Overall Feedback <span class="text-danger">*</span></label>
                                        <textarea class="form-control" name="feedback" id="feedbackField" rows="3" required placeholder="Provide HR overall performance & exit feedback..." style="resize:none; border-radius:var(--radius-md);"></textarea>
                                    </div>
                                </div>
                            </div>

                            <!-- Offboarding Checklist Switches -->
                            <div class="row mb-3">
                                <div class="col-md-4 mb-2 mb-md-0">
                                    <div class="custom-switch-card">
                                        <div class="custom-control custom-checkbox">
                                            <input type="checkbox" class="custom-control-input" id="techHandover" name="tech_handover">
                                            <label class="custom-control-label font-weight-bold text-dark" for="techHandover" style="cursor:pointer; font-size:13px;">
                                                <i class="fas fa-code-branch text-primary mr-1"></i> Technical Handover Done
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-2 mb-md-0">
                                    <div class="custom-switch-card">
                                        <div class="custom-control custom-checkbox">
                                            <input type="checkbox" class="custom-control-input" id="accessRevoked" name="access_revoked">
                                            <label class="custom-control-label font-weight-bold text-dark" for="accessRevoked" style="cursor:pointer; font-size:13px;">
                                                <i class="fas fa-user-lock text-warning mr-1"></i> System Access Revoked
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="custom-switch-card">
                                        <div class="custom-control custom-checkbox">
                                            <input type="checkbox" class="custom-control-input" id="exitInterview" name="exit_interview">
                                            <label class="custom-control-label font-weight-bold text-dark" for="exitInterview" style="cursor:pointer; font-size:13px;">
                                                <i class="fas fa-clipboard-check text-success mr-1"></i> Exit Interview Completed
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Experience Letter File Upload -->
                            <div class="form-group mb-4">
                                <label style="font-weight:700; font-size:.85rem; color:#334155;">Draft Experience / Relieving Letter <span class="text-muted font-weight-normal">(PDF Format Only)</span></label>
                                <div class="upload-area-custom" id="uploadArea" onclick="document.getElementById('exp_letter').click()">
                                    <div class="file-icon mb-2" style="font-size:1.6rem; color:var(--primary);"><i class="fas fa-cloud-upload-alt"></i></div>
                                    <p class="upload-text font-weight-bold mb-0" id="uploadText" style="color:#0f172a; font-size:0.9rem;">Click or drag PDF document to upload</p>
                                    <small class="text-muted">Only single PDF documents are accepted</small>
                                    <input type="file" id="exp_letter" name="exp_letter" accept=".pdf" style="display:none;" onchange="validateFile(event)">
                                </div>
                                <div id="simple-file-preview" class="d-none mt-2 ml-1">
                                    <span class="text-dark font-weight-bold"><i class="fas fa-file-pdf mr-1 text-danger"></i> <span id="simple-file-name">filename.pdf</span></span>
                                    <button type="button" class="btn btn-link text-danger p-0 ml-3 font-weight-bold" style="text-decoration: none;" onclick="clearFile(event)">✕ Clear File</button>
                                </div>
                                <div id="existingFileAlert" class="mt-2 text-info d-none font-weight-bold small"><i class="fas fa-paperclip mr-1"></i> Document already attached. Uploading a new PDF will overwrite the existing file.</div>
                            </div>

                            <div class="text-right">
                                <button type="button" id="cancelEditBtn" class="btn btn-light font-weight-bold mr-2 d-none" onclick="window.location.reload();">Cancel Edit</button>
                                <button type="submit" id="submitBtn" class="btn-gradient-primary">
                                    Submit Exit Clearance to RH <i class="fas fa-arrow-right ml-2"></i>
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- ── HISTORY TABLE CARD ── -->
                    <div class="analytics-table-card">
                        <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                            <div>
                                <h5 class="mb-0 font-weight-bold" style="color:#0f172a; font-size:1.05rem;">
                                    <i class="fas fa-history text-primary mr-1"></i> Exit Process Clearances History
                                </h5>
                                <p style="font-size:0.8rem; color:#64748b; margin:2px 0 0 0;">
                                    Complete audit trail of initiated exit clearances, approvals, and document dispatches.
                                </p>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover" id="hrHistoryTable">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th style="text-align:left;">Employee / Intern Name</th>
                                        <th>Status</th>
                                        <th>Reason for Leaving</th>
                                        <th>RH Remarks</th>
                                        <th>Experience Letter</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $q = mysqli_query($con, "SELECT e.*, COALESCE(s.fullName, 'Unknown User') as fullName, s.email, s.designation FROM exit_process e LEFT JOIN signup s ON e.userId COLLATE utf8mb4_unicode_ci = s.userId COLLATE utf8mb4_unicode_ci ORDER BY e.created_at DESC");
                                    if ($q) {
                                        while ($r = mysqli_fetch_assoc($q)) {
                                            $st = $r['status'];
                                            $badgeClass = ($st === 'Approved') ? 'badge-success' : (($st === 'Rejected') ? 'badge-danger' : 'badge-warning');
                                    ?>
                                            <tr>
                                                <td class="text-muted font-weight-bold"><?= date('d M Y', strtotime($r['created_at'])); ?></td>
                                                <td style="text-align:left;">
                                                    <strong style="color:#0f172a; font-size:.88rem;"><?= htmlspecialchars($r['fullName']); ?></strong>
                                                    <div><small class="text-muted"><?= htmlspecialchars($r['designation'] ?? 'Team Member') ?></small></div>
                                                </td>
                                                <td><span class="badge <?= $badgeClass; ?> p-2 font-weight-bold" style="border-radius:12px; font-size:.78rem;"><?= htmlspecialchars($st); ?></span></td>
                                                <td style="max-width:200px; white-space:normal; font-size:.82rem;"><?= htmlspecialchars($r['reason_for_leaving']); ?></td>
                                                <td style="max-width:180px; white-space:normal; font-size:.82rem;" class="<?= $st === 'Rejected' ? 'text-danger font-weight-bold' : 'text-muted' ?>">
                                                    <?= ($st === 'Rejected' && !empty($r['rejection_remark'])) ? htmlspecialchars($r['rejection_remark']) : '—'; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($r['exp_letter_path'])): ?>
                                                        <a href="../uploads/<?= htmlspecialchars($r['exp_letter_path']); ?>" target="_blank" class="btn btn-sm btn-outline-info font-weight-bold" title="Download Document">
                                                            <i class="fas fa-download mr-1"></i> PDF
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="text-muted small">None</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($st === 'Approved'): ?>
                                                        <?php
                                                        $internName = $r['fullName'];
                                                        $internEmail = $r['email'];
                                                        $subject = urlencode("Experience Letter & Relieving Confirmation");
                                                        $bodyText = "Dear {$internName},\n\nWe have officially processed your exit clearance. Your technical handover and access revocations have been confirmed, and your final exit request has been approved.\n\nPlease find attached your official Experience Letter.\n\nWe appreciate your contributions during your time here and wish you the absolute best in your future career endeavors.\n\nWarm Regards,\nHuman Resources Department";
                                                        $gmailLink = "https://mail.google.com/mail/?view=cm&fs=1&to=" . urlencode($internEmail) . "&su=" . $subject . "&body=" . urlencode($bodyText);
                                                        ?>
                                                        <a href="<?= $gmailLink; ?>" target="_blank" class="btn btn-sm btn-success font-weight-bold shadow-sm" onclick="return confirm('This will open Gmail in a new tab.\n\nIMPORTANT: Please remember to download the Document (from the Experience Letter column) and manually attach it to the email before sending!');">
                                                            <i class="fas fa-paper-plane mr-1"></i> Email Letter
                                                        </a>
                                                    <?php elseif ($st === 'Rejected'): ?>
                                                        <button type="button" class="btn btn-sm btn-warning font-weight-bold text-dark edit-btn shadow-sm"
                                                            data-id="<?= $r['id']; ?>"
                                                            data-userid="<?= htmlspecialchars($r['userId']); ?>"
                                                            data-reason="<?= htmlspecialchars($r['reason_for_leaving']); ?>"
                                                            data-feedback="<?= htmlspecialchars($r['intern_feedback']); ?>"
                                                            data-remark="<?= htmlspecialchars($r['rejection_remark']); ?>"
                                                            data-hasfile="<?= !empty($r['exp_letter_path']) ? 'yes' : 'no'; ?>">
                                                            <i class="fas fa-pen mr-1"></i> Edit &amp; Resubmit
                                                        </button>
                                                    <?php else: ?>
                                                        <span class="text-muted small"><i class="fas fa-lock mr-1"></i> Pending RH</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                    <?php }
                                    } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <!-- JS Scripts -->
    <script src="../vendors/js/vendor.bundle.base.js"></script>
    <script src="../vendors/chart.js/Chart.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>
    <script src="../js/settings.js"></script>

    <script>
    $(document).ready(function() {
        $('.select2').select2({ placeholder: "-- Search & Select Employee / Intern --", allowClear: true, width: '100%' });
        $('#hrHistoryTable').DataTable({ "order": [[ 0, "desc" ]], "pageLength": 10 });

        // 1. Exit Status Doughnut Chart
        var ctxDoughnut = document.getElementById('exitStatusDoughnut').getContext('2d');
        new Chart(ctxDoughnut, {
            type: 'doughnut',
            data: {
                labels: ['Approved', 'Pending RH Review', 'Revision Required'],
                datasets: [{
                    data: [<?= $approvedExit ?>, <?= $pendingExit ?>, <?= $rejectedExit ?>],
                    backgroundColor: ['#10b981', '#f59e0b', '#ef4444'],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                legend: { position: 'right', labels: { boxWidth: 12, fontSize: 11 } }
            }
        });

        // 2. Checklist Completion Bar Chart
        var ctxChecklist = document.getElementById('checklistBarChart').getContext('2d');
        new Chart(ctxChecklist, {
            type: 'bar',
            data: {
                labels: ['Tech Handover Done', 'Access Revoked', 'Exit Interview Done'],
                datasets: [{
                    label: 'Completed Clearances',
                    data: [<?= $techHandoverCount ?>, <?= $accessRevokedCount ?>, <?= $exitInterviewCount ?>],
                    backgroundColor: ['#4f46e5', '#8b5cf6', '#10b981'],
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                legend: { display: false },
                scales: {
                    xAxes: [{ gridLines: { display: false } }],
                    yAxes: [{ ticks: { beginAtZero: true, stepSize: 1 } }]
                }
            }
        });

        // AJAX fetch intern criteria
        $('#internSelect').on('change', function() {
            var userId = $(this).val();
            $('#hidden_userId').val(userId);
            if (userId) {
                $('#criteriaBox').removeClass('d-none').hide().fadeIn('fast');
                $('#attPercent, #taskCount').text('...');
                $.ajax({
                    url: 'ajax_fetch_intern_stats.php', type: 'POST', data: {userId: userId}, dataType: 'json',
                    success: function(data) {
                        var att = parseFloat(data.attendance || 0);
                        var tasks = parseInt(data.tasks || 0);

                        $('#inputAtt').val(att);
                        $('#inputTask').val(tasks);
                        $('#attPercent').text(att + '%');

                        if (att >= 80) {
                            $('#attPercent').removeClass('text-danger').addClass('text-success');
                            $('#attBadge').text('Eligible').removeClass('badge-danger badge-warning').addClass('badge-success');
                        } else {
                            $('#attPercent').removeClass('text-success').addClass('text-danger');
                            $('#attBadge').text('Below 80%').removeClass('badge-success badge-warning').addClass('badge-danger');
                        }

                        $('#taskCount').text(tasks);
                        if (tasks >= 10) {
                            $('#taskCount').removeClass('text-danger').addClass('text-success');
                            $('#taskBadge').text('Eligible').removeClass('badge-danger badge-warning').addClass('badge-success');
                        } else {
                            $('#taskCount').removeClass('text-success').addClass('text-danger');
                            $('#taskBadge').text('Incomplete ('+tasks+'/10)').removeClass('badge-success badge-warning').addClass('badge-danger');
                        }
                    }
                });
            } else {
                $('#criteriaBox').addClass('d-none');
            }
        });

        // Edit button click
        $('.edit-btn').on('click', function() {
            $('html, body').animate({ scrollTop: $('#formCard').offset().top - 80 }, 400);
            $('#update_exit_id').val($(this).data('id'));
            $('#internSelect').val($(this).data('userid')).trigger('change');
            $('#reasonField').val($(this).data('reason'));
            $('#feedbackField').val($(this).data('feedback'));
            $('#editRemarkAlert').removeClass('d-none').html('<i class="fas fa-exclamation-triangle mr-2"></i><strong>RH Rejection Remark:</strong> ' + $(this).data('remark'));
            $('#exitForm').attr('action', 'update_exit_process.php');

            $('#formTitle').html('<i class="fas fa-pen text-warning mr-2"></i> Edit &amp; Resubmit Exit Clearance');
            $('#submitBtn').html('Resubmit Clearance <i class="fas fa-redo ml-2"></i>').removeClass('btn-gradient-primary').addClass('btn-warning text-dark font-weight-bold');
            $('#cancelEditBtn').removeClass('d-none');
        });
    });

    function validateFile(event) {
        const fileInput = document.getElementById('exp_letter');
        const file = fileInput.files[0];
        if (file) {
            const fileName = file.name;
            const fileParts = fileName.split('.');
            if (fileParts.length > 2) { alert("Invalid file format."); clearFile(event); return; }
            if (fileParts[fileParts.length - 1].toLowerCase() !== 'pdf') { alert("STRICT SECURITY: Only PDF files allowed."); clearFile(event); return; }
            document.getElementById('simple-file-name').textContent = fileName;
            document.getElementById('simple-file-preview').classList.remove('d-none');
            document.getElementById('uploadText').textContent = "PDF Selected: " + fileName;
            document.getElementById('uploadText').classList.add('text-success');
        }
    }

    function clearFile(event) {
        if (event) { event.preventDefault(); event.stopPropagation(); }
        document.getElementById('exp_letter').value = '';
        document.getElementById('simple-file-preview').classList.add('d-none');
        document.getElementById('uploadText').textContent = "Click or drag PDF document to upload";
        document.getElementById('uploadText').classList.remove('text-success');
    }
    </script>

    <?php
    require_once('../include/sweetAlert.php');
    if (isset($_SESSION['msg'])) {
        $status = $_SESSION['status'] ?? false;
        $msg    = $_SESSION['msg'];
        if ($status != true) {
            toastMsg('error', $msg);
        } else {
            toastMsg('success', $msg);
        }
        unset($_SESSION['msg']);
        unset($_SESSION['status']);
    }
    ?>
</body>
</html>