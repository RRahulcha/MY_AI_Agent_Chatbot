<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

$currentUserRole    = strtolower(trim($_SESSION['userRole'] ?? ''));
$currentDesignation = strtolower(trim($_SESSION['designation'] ?? ''));

$allowedRoles = ['senior_intern', 'seniorintern', 'hr', 'admin', 'superadmin', 'resourceallocator', 'resource_allocator'];
if (!in_array($currentUserRole, $allowedRoles) && !in_array($currentDesignation, $allowedRoles)) {
    echo '<script>
        alert("You are not authorized to access this page.");
        window.location.href = "login.php";
        </script>';
    die();
}
include('../include/db_config.php');
date_default_timezone_set('Asia/Kolkata');

// ── Analytics Metrics ────────────────────────────────────────────────────────
$totalJr = (int)mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as c FROM signup WHERE designation = 'juniorIntern' AND status = 'Active' AND regstatus = 'Approved' AND fullName != ''"))['c'];
$onlineJr = (int)mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as c FROM signup WHERE designation = 'juniorIntern' AND status = 'Active' AND regstatus = 'Approved' AND fullName != '' AND LOWER(type) = 'online'"))['c'];
$offlineJr = (int)mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as c FROM signup WHERE designation = 'juniorIntern' AND status = 'Active' AND regstatus = 'Approved' AND fullName != '' AND LOWER(type) = 'offline'"))['c'];
$onlineRatio = ($totalJr > 0) ? round(($onlineJr / $totalJr) * 100, 1) : 0;

// Branch / Location breakdown for bar chart (normalized case & trimmed)
$branchCounts = [];
$bQ = mysqli_query($con, "SELECT COALESCE(NULLIF(TRIM(office), ''), NULLIF(TRIM(branch), ''), 'Main Office') as location, LOWER(type) as mode, COUNT(*) as cnt FROM signup WHERE designation = 'juniorIntern' AND status = 'Active' AND regstatus = 'Approved' AND fullName != '' GROUP BY COALESCE(NULLIF(TRIM(office), ''), NULLIF(TRIM(branch), ''), 'Main Office'), LOWER(type)");
if ($bQ) {
    while ($br = mysqli_fetch_assoc($bQ)) {
        $rawLoc = trim($br['location'] ?? '');
        $loc = !empty($rawLoc) ? ucfirst(strtolower($rawLoc)) : 'Main Office';
        $m = ($br['mode'] === 'online') ? 'Online' : 'Offline';
        if (!isset($branchCounts[$loc])) {
            $branchCounts[$loc] = ['Online' => 0, 'Offline' => 0];
        }
        $branchCounts[$loc][$m] += (int)$br['cnt'];
    }
}
if (empty($branchCounts)) {
    $branchCounts['Main Office'] = ['Online' => $onlineJr, 'Offline' => $offlineJr];
}

// Fetch all active junior interns for dropdown and table
$juniorInterns = [];
$jq = mysqli_query($con, "SELECT userId, fullName, email, mobileNo, office, branch, type, start_date, registration_date FROM signup WHERE designation = 'juniorIntern' AND status = 'Active' AND regstatus = 'Approved' AND fullName != '' ORDER BY fullName ASC");
if ($jq) {
    while ($r = mysqli_fetch_assoc($jq)) {
        $juniorInterns[] = $r;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <title>SETTribe · Junior Intern Mode Switch</title>
    <?php include('../partials/_links.php'); ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-beta.1/css/select2.min.css" rel="stylesheet" />
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
        .kpi-cyan::before    { background: #06b6d4; }
        .kpi-amber::before   { background: var(--warning); }
        .kpi-green::before   { background: var(--success); }
        .kpi-purple::before  { background: var(--purple); }

        .kpi-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
        .kpi-title { font-size: 0.78rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.4px; }
        .kpi-icon-wrap { width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1rem; }
        .icon-blue   { background: var(--primary-light); color: var(--primary); }
        .icon-cyan   { background: #ecfeff; color: #06b6d4; }
        .icon-amber  { background: var(--warning-light); color: var(--warning); }
        .icon-green  { background: #ecfdf5; color: var(--success); }
        .icon-purple { background: #f3e8ff; color: var(--purple); }

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
            color: #ffffff; border: none; font-weight: 700; padding: 10px 24px; border-radius: var(--radius-md); box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25); transition: all 0.2s;
        }
        .btn-gradient-primary:hover { color: #fff; transform: translateY(-1px); }

        /* Select2 wrap fixes */
        .select2-container--default .select2-selection--single {
            height: 42px; border-radius: var(--radius-md); border: 1px solid var(--border); padding-top: 6px; font-weight: 600;
        }

        /* Mode Badges */
        .badge-mode { padding: 4px 12px; border-radius: 12px; font-size: 0.75rem; font-weight: 700; display: inline-flex; align-items: center; gap: 4px; }
        .badge-mode-online  { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
        .badge-mode-offline { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }

        /* Radio Pills */
        .mode-radio-group { display: flex; gap: 12px; }
        .mode-radio-pill {
            flex: 1; border: 1px solid var(--border); border-radius: var(--radius-md); padding: 10px 16px;
            cursor: pointer; text-align: center; font-weight: 700; font-size: 0.85rem; transition: all 0.2s;
            display: flex; align-items: center; justify-content: center; gap: 8px; color: #475569;
        }
        .mode-radio-pill:hover { border-color: var(--primary); background: var(--primary-light); }
        .mode-radio-input { display: none; }
        .mode-radio-input:checked + .mode-radio-pill {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: #ffffff; border-color: var(--primary); box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);
        }

        #jrTable { width: 100% !important; border-collapse: separate; border-spacing: 0; }
        #jrTable thead th {
            background: linear-gradient(135deg, #1e293b, #334155); color: #fff; padding: 12px 14px; font-weight: 700;
            font-size: .78rem; text-transform: uppercase; letter-spacing: .4px; border: none; text-align:center;
        }
        #jrTable thead th:first-child { border-top-left-radius: 8px; }
        #jrTable thead th:last-child { border-top-right-radius: 8px; }
        #jrTable tbody td {
            padding: 11px 14px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; text-align:center; color:#334155; font-size:.84rem;
        }
        #jrTable tbody tr:hover td { background: #f8fafc !important; }
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
                                    <i class="fas fa-repeat"></i> Junior Intern Work Mode Control
                                </div>
                                <h2 style="font-size: 1.65rem; font-weight: 800; margin-bottom: 4px;">
                                    Junior Intern Mode Switch Dashboard
                                </h2>
                                <p style="font-size: 0.85rem; opacity: 0.88; margin: 0;">
                                    Switch and update operating work modes (Online vs Offline) for active junior interns.
                                </p>
                            </div>
                            <div>
                                <span class="badge badge-light text-primary font-weight-bold p-2" style="border-radius:10px; font-size:0.82rem;">
                                    <i class="fas fa-users mr-1"></i> Total Junior Interns: <?= $totalJr ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- ── KPI METRICS ROW ── -->
                    <div class="row mb-4">
                        <div class="col-xl-3 col-md-6 col-sm-6 mb-3 mb-xl-0">
                            <div class="kpi-card kpi-blue">
                                <div class="kpi-header">
                                    <span class="kpi-title">Active Junior Interns</span>
                                    <div class="kpi-icon-wrap icon-blue"><i class="fas fa-user-graduate"></i></div>
                                </div>
                                <div>
                                    <div class="kpi-value"><?= $totalJr ?></div>
                                    <div class="kpi-sub">Total Roster Count</div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-3 col-md-6 col-sm-6 mb-3 mb-xl-0">
                            <div class="kpi-card kpi-cyan">
                                <div class="kpi-header">
                                    <span class="kpi-title">Online Mode</span>
                                    <div class="kpi-icon-wrap icon-cyan"><i class="fas fa-laptop-house"></i></div>
                                </div>
                                <div>
                                    <div class="kpi-value"><?= $onlineJr ?></div>
                                    <div class="kpi-sub">Working Remotely</div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-3 col-md-6 col-sm-6 mb-3 mb-xl-0">
                            <div class="kpi-card kpi-amber">
                                <div class="kpi-header">
                                    <span class="kpi-title">Offline Mode</span>
                                    <div class="kpi-icon-wrap icon-amber"><i class="fas fa-building"></i></div>
                                </div>
                                <div>
                                    <div class="kpi-value"><?= $offlineJr ?></div>
                                    <div class="kpi-sub">Working On-site</div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-3 col-md-6 col-sm-6 mb-3 mb-xl-0">
                            <div class="kpi-card kpi-purple">
                                <div class="kpi-header">
                                    <span class="kpi-title">Online Ratio</span>
                                    <div class="kpi-icon-wrap icon-purple"><i class="fas fa-chart-pie"></i></div>
                                </div>
                                <div>
                                    <div class="kpi-value"><?= $onlineRatio ?>%</div>
                                    <div class="kpi-sub">Remote Work Ratio</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── CHARTS ROW ── -->
                    <div class="row mb-4">
                        <!-- Mode Distribution Doughnut Chart -->
                        <div class="col-lg-5 mb-3 mb-lg-0">
                            <div class="analytics-table-card h-100" style="padding:20px;">
                                <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
                                    <h5 style="font-size:.95rem; font-weight:800; color:#0f172a; margin:0;">
                                        <i class="fas fa-chart-pie text-primary mr-1"></i> Active Work Mode Ratio
                                    </h5>
                                    <span class="badge badge-light border" style="font-size:.72rem;">Online vs Offline</span>
                                </div>
                                <div style="position: relative; height: 210px;">
                                    <canvas id="modeDoughnutChart"></canvas>
                                </div>
                            </div>
                        </div>

                        <!-- Location Mode Bar Chart -->
                        <div class="col-lg-7">
                            <div class="analytics-table-card h-100" style="padding:20px;">
                                <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
                                    <h5 style="font-size:.95rem; font-weight:800; color:#0f172a; margin:0;">
                                        <i class="fas fa-building text-purple mr-1"></i> Work Mode by Office Branch
                                    </h5>
                                    <span class="badge badge-light border" style="font-size:.72rem;">Branch Breakdown</span>
                                </div>
                                <div style="position: relative; height: 210px;">
                                    <canvas id="branchBarChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── MODE SWITCH FORM CARD ── -->
                    <div class="analytics-table-card mb-4" id="switchFormCard">
                        <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
                            <h5 style="font-size:1.05rem; font-weight:800; color:#0f172a; margin:0;">
                                <i class="fas fa-repeat text-primary mr-1"></i> Switch Junior Intern Work Mode
                            </h5>
                            <span class="badge badge-primary font-weight-bold" style="font-size:.75rem;">Action Form</span>
                        </div>

                        <form class="forms-sample" action="jrModeSwitch-db.php" method="post" id="modeSwitchForm">
                            <div class="row align-items-end">
                                <div class="col-md-5 mb-3">
                                    <div class="form-group mb-0">
                                        <label style="font-weight:700; font-size:.85rem; color:#334155;">Select Junior Intern <span class="text-danger">*</span></label>
                                        <select class="js-example-basic-single w-100" id="internSelect" name="internId" onchange="fetchInternType()" required>
                                            <option value="" selected disabled>-- Search &amp; Select Junior Intern --</option>
                                            <?php foreach ($juniorInterns as $fetch): ?>
                                                <option value="<?= htmlspecialchars($fetch['userId']); ?>" data-type="<?= htmlspecialchars($fetch['type'] ?? 'Offline'); ?>">
                                                    <?= htmlspecialchars($fetch['fullName']); ?> (<?= htmlspecialchars($fetch['office'] ?? $fetch['branch'] ?? 'Main Office') ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <div class="form-group mb-0">
                                        <label style="font-weight:700; font-size:.85rem; color:#334155;">Current Operating Mode</label>
                                        <div id="currentModeDisplay">
                                            <span class="badge badge-light border text-muted" style="padding:10px 16px; font-weight:700; font-size:.85rem; display:block;">Select an intern above</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-4 mb-3">
                                    <div class="form-group mb-0">
                                        <label style="font-weight:700; font-size:.85rem; color:#334155;">Set New Work Mode <span class="text-danger">*</span></label>
                                        <div class="mode-radio-group">
                                            <label style="flex:1; margin:0;">
                                                <input type="radio" class="mode-radio-input" name="type" id="modeOffline" value="Offline" required checked>
                                                <span class="mode-radio-pill"><i class="fas fa-building"></i> Offline</span>
                                            </label>
                                            <label style="flex:1; margin:0;">
                                                <input type="radio" class="mode-radio-input" name="type" id="modeOnline" value="Online">
                                                <span class="mode-radio-pill"><i class="fas fa-laptop-house"></i> Online</span>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-3 text-right">
                                <button type="submit" id="submitBtn" class="btn-gradient-primary" name="switch">
                                    <i class="fas fa-save mr-1"></i> Update Work Mode
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- ── JUNIOR INTERN ROSTER TABLE CARD ── -->
                    <div class="analytics-table-card">
                        <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                            <div>
                                <h5 class="mb-0 font-weight-bold" style="color:#0f172a; font-size:1.05rem;">
                                    <i class="fas fa-users text-primary mr-1"></i> Active Junior Interns Mode Roster
                                </h5>
                                <p style="font-size:0.8rem; color:#64748b; margin:2px 0 0 0;">
                                    Complete list of active junior interns and their current work operating mode.
                                </p>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover" id="jrTable">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th style="text-align:left;">Intern Name</th>
                                        <th>Contact Details</th>
                                        <th>Office / Branch</th>
                                        <th>Joining Date</th>
                                        <th>Current Work Mode</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $sr = 1;
                                    foreach ($juniorInterns as $r):
                                        $modeVal = ucfirst(strtolower($r['type'] ?? 'Offline'));
                                        $modeBadgeClass = (strtolower($modeVal) === 'online') ? 'badge-mode-online' : 'badge-mode-offline';
                                        $jDate = !empty($r['start_date']) ? date('d M Y', strtotime($r['start_date'])) : (!empty($r['registration_date']) ? date('d M Y', strtotime($r['registration_date'])) : '—');
                                    ?>
                                        <tr>
                                            <td class="font-weight-bold text-muted"><?= $sr++; ?></td>
                                            <td style="text-align:left;">
                                                <strong style="color:#0f172a; font-size:.88rem;"><?= htmlspecialchars($r['fullName']) ?></strong>
                                            </td>
                                            <td>
                                                <div style="font-size:.8rem; font-weight:600; color:#334155;"><?= htmlspecialchars($r['email'] ?? '—') ?></div>
                                                <small class="text-muted"><?= htmlspecialchars($r['mobileNo'] ?? '—') ?></small>
                                            </td>
                                            <td><span class="badge badge-light border"><?= htmlspecialchars($r['office'] ?? $r['branch'] ?? 'Main Office') ?></span></td>
                                            <td><?= $jDate ?></td>
                                            <td>
                                                <span class="badge-mode <?= $modeBadgeClass ?>">
                                                    <i class="fas <?= strtolower($modeVal) === 'online' ? 'fa-laptop-house' : 'fa-building' ?> mr-1"></i> <?= $modeVal ?>
                                                </span>
                                            </td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-outline-primary font-weight-bold" onclick="selectInternForSwitch('<?= $r['userId'] ?>', '<?= $modeVal ?>')">
                                                    <i class="fas fa-repeat mr-1"></i> Switch Mode
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <!-- JS -->
    <script src="../vendors/js/vendor.bundle.base.js"></script>
    <script src="../vendors/chart.js/Chart.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-beta.1/js/select2.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>
    <script src="../js/settings.js"></script>

    <script>
    $(document).ready(function() {
        $('.js-example-basic-single').select2({
            placeholder: "-- Search & Select Junior Intern --",
            allowClear: true,
            width: '100%'
        });

        $('#jrTable').DataTable({
            pageLength: 15,
            order: [[ 0, "asc" ]],
            language: { search: "<i class='fas fa-search text-muted mr-1'></i> Search Junior Interns:" }
        });

        // 1. Mode Distribution Doughnut Chart
        var ctxDoughnut = document.getElementById('modeDoughnutChart').getContext('2d');
        new Chart(ctxDoughnut, {
            type: 'doughnut',
            data: {
                labels: ['Online Mode', 'Offline Mode'],
                datasets: [{
                    data: [<?= $onlineJr ?>, <?= $offlineJr ?>],
                    backgroundColor: ['#4f46e5', '#f59e0b'],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                legend: { position: 'right', labels: { boxWidth: 12, fontSize: 11 } }
            }
        });

        // 2. Location Mode Bar Chart
        var branchLabels = <?= json_encode(array_keys($branchCounts)) ?>;
        var onlineData = [];
        var offlineData = [];
        <?php foreach ($branchCounts as $bName => $bVals): ?>
            onlineData.push(<?= $bVals['Online'] ?>);
            offlineData.push(<?= $bVals['Offline'] ?>);
        <?php endforeach; ?>

        var ctxBranch = document.getElementById('branchBarChart').getContext('2d');
        new Chart(ctxBranch, {
            type: 'bar',
            data: {
                labels: branchLabels,
                datasets: [
                    { label: 'Online Mode', data: onlineData, backgroundColor: '#4f46e5', borderRadius: 4 },
                    { label: 'Offline Mode', data: offlineData, backgroundColor: '#f59e0b', borderRadius: 4 }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                legend: { position: 'top', labels: { boxWidth: 12, fontSize: 11 } },
                scales: {
                    xAxes: [{ gridLines: { display: false } }],
                    yAxes: [{ ticks: { beginAtZero: true, stepSize: 1 } }]
                }
            }
        });

        // Form confirmation
        $('#modeSwitchForm').on('submit', function (e) {
            e.preventDefault();
            var form = this;
            var internName = $('#internSelect option:selected').text().trim();
            var newMode = $('input[name="type"]:checked').val();

            if (!$('#internSelect').val() || !newMode) {
                Swal.fire('Validation Error', 'Please select an intern and target mode.', 'error');
                return false;
            }

            Swal.fire({
                title: 'Confirm Mode Switch',
                html: 'Switch work mode for <strong>' + internName + '</strong> to <strong>' + newMode + '</strong>?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#4f46e5',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, Update Mode',
                cancelButtonText: 'Cancel'
            }).then(function (result) {
                if (result.isConfirmed) {
                    $('#submitBtn').prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Processing...');
                    form.submit();
                }
            });
        });
    });

    function fetchInternType() {
        var selectElement = document.getElementById('internSelect');
        var selectedOption = selectElement.options[selectElement.selectedIndex];
        if (!selectedOption || !selectedOption.value) {
            document.getElementById('currentModeDisplay').innerHTML = '<span class="badge badge-light border text-muted" style="padding:10px 16px; font-weight:700; font-size:.85rem; display:block;">Select an intern above</span>';
            return;
        }

        var internType = selectedOption.getAttribute('data-type') || 'Offline';
        var badgeClass = (internType.toLowerCase() === 'online') ? 'badge-mode-online' : 'badge-mode-offline';
        var icon = (internType.toLowerCase() === 'online') ? 'fa-laptop-house' : 'fa-building';

        document.getElementById('currentModeDisplay').innerHTML = '<span class="badge-mode ' + badgeClass + '" style="font-size:.85rem; padding:8px 16px; width:100%; justify-content:center;"><i class="fas ' + icon + ' mr-1"></i> Currently ' + internType + '</span>';
    }

    function selectInternForSwitch(userId, currentMode) {
        $('#internSelect').val(userId).trigger('change');
        if (currentMode.toLowerCase() === 'online') {
            $('#modeOffline').prop('checked', true);
        } else {
            $('#modeOnline').prop('checked', true);
        }

        $('html, body').animate({
            scrollTop: $('#switchFormCard').offset().top - 100
        }, 400);
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