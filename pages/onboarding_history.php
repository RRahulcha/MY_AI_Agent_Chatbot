<?php
session_start();
include('../include/db_config.php');
 
if (!isset($_SESSION['userId'])) {
    echo "<script>window.location.href='login.php';</script>";
    exit;
}
 
$userId   = $_SESSION['userId'];
$userRow = mysqli_fetch_assoc(mysqli_query($con, "SELECT fullName FROM signup WHERE userId='$userId'"));
$userName = $userRow ? htmlspecialchars($userRow['fullName']) : 'Unknown';

// ── AUTO PHASE SHIFT: Training → Working ─────────────────────────
$today = date('Y-m-d');
$hasTrainingEndColCheck = mysqli_query($con, "SHOW COLUMNS FROM interns LIKE 'trainingEndDate'");
if ($hasTrainingEndColCheck && mysqli_num_rows($hasTrainingEndColCheck) > 0) {
    $autoShiftResult = mysqli_query($con, "
        UPDATE interns 
        SET phase = 'Working'
        WHERE phase = 'Training'
          AND trainingEndDate IS NOT NULL
          AND trainingEndDate <= '$today'
    ");
    if ($autoShiftResult && mysqli_affected_rows($con) > 0) {
        error_log('Auto-shifted ' . mysqli_affected_rows($con) . ' intern(s) from Training to Working on ' . $today);
    }
}
 
// ── Handle EDIT ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_submit'])) {
    $internId     = mysqli_real_escape_string($con, $_POST['editInternId']);
    $fullName     = mysqli_real_escape_string($con, $_POST['editFullName']);
    $email        = mysqli_real_escape_string($con, $_POST['editEmail']);
    $role         = mysqli_real_escape_string($con, $_POST['editRole']);
    $doj          = mysqli_real_escape_string($con, $_POST['editDoj']);
    $expectedEnd  = mysqli_real_escape_string($con, $_POST['editExpectedEnd']);
    $workingSlots = mysqli_real_escape_string($con, $_POST['editWorkingSlots']);
    $batch        = mysqli_real_escape_string($con, $_POST['editBatch']);
    $techStack    = mysqli_real_escape_string($con, $_POST['editTechStack']);
    $phase        = mysqli_real_escape_string($con, $_POST['editPhase']);
    $mode         = mysqli_real_escape_string($con, $_POST['editMode']);
    $stipend      = mysqli_real_escape_string($con, $_POST['editStipend']);
    $stipendAmount= ($stipend === 'yes') ? (float)$_POST['editStipendAmount'] : 0;
    $branch       = mysqli_real_escape_string($con, $_POST['editBranch'] ?? 'Pune');
    $intern_location = $branch;

    $trainingDays    = ($stipend === 'yes') ? 8 : 15;
    $trainingEndDate = date('Y-m-d', strtotime($doj . ' + ' . $trainingDays . ' days'));

    $hasLocCol = false;
    $locChk = mysqli_query($con, "SHOW COLUMNS FROM interns LIKE 'intern_location'");
    if ($locChk && mysqli_num_rows($locChk) > 0) $hasLocCol = true;
 
    $hasTrainingEndCol = false;
    $tedChk = mysqli_query($con, "SHOW COLUMNS FROM interns LIKE 'trainingEndDate'");
    if ($tedChk && mysqli_num_rows($tedChk) > 0) $hasTrainingEndCol = true;

    $hasBranchCol = false;
    $branchChk = mysqli_query($con, "SHOW COLUMNS FROM interns LIKE 'branch'");
    if ($branchChk && mysqli_num_rows($branchChk) > 0) $hasBranchCol = true;
 
    $locPart         = $hasLocCol         ? ", intern_location='$intern_location'"  : '';
    $trainingEndPart = $hasTrainingEndCol ? ", trainingEndDate='$trainingEndDate'"  : '';
    $branchPart      = $hasBranchCol      ? ", branch='$branch'"                    : '';
 
    $update = "UPDATE interns SET
        fullName        = '$fullName',
        email           = '$email',
        role            = '$role',
        doj             = '$doj',
        expectedEndDate = '$expectedEnd',
        workingSlots    = '$workingSlots',
        batch           = '$batch',
        techStack       = '$techStack',
        phase           = '$phase',
        mode            = '$mode',
        stipend         = '$stipend',
        stipendAmount   = '$stipendAmount'
        $branchPart
        $locPart
        $trainingEndPart
        WHERE internId = '$internId'";
 
    if (mysqli_query($con, $update)) {
        mysqli_query($con, "UPDATE signup SET fullName='$fullName', email='$email', mode='$mode', branch='$branch' WHERE internId='$internId'");
        $_SESSION['editSuccess'] = "Intern record updated successfully.";
    } else {
        $_SESSION['editError'] = "Update failed: " . mysqli_error($con);
    }
    header("Location: onboarding_history.php");
    exit;
}
 
// ── Search / Filter ───────────────────────────────────────────────
$search      = isset($_GET['search'])  ? mysqli_real_escape_string($con, trim($_GET['search'])) : '';
$filterPhase = isset($_GET['phase'])   ? mysqli_real_escape_string($con, $_GET['phase'])         : '';
$filterBatch = isset($_GET['batch'])   ? mysqli_real_escape_string($con, $_GET['batch'])         : '';
$filterRole  = isset($_GET['role'])    ? mysqli_real_escape_string($con, $_GET['role'])           : '';
 
$where = "WHERE 1=1";
if ($search)      $where .= " AND (i.fullName LIKE '%$search%' OR i.email LIKE '%$search%' OR i.internId LIKE '%$search%')";
if ($filterPhase) $where .= " AND i.phase = '$filterPhase'";
if ($filterBatch) $where .= " AND i.batch = '$filterBatch'";
if ($filterRole)  $where .= " AND i.role  = '$filterRole'";
 
$phases  = ['Training', 'Working'];
$roles   = ['Java Developer','PHP Developer','UI Developer','Python Developer','Android Developer','Data Analyst','.Net Developer','Tester'];
 
$batchesForFilter = [];
$bfQ = mysqli_query($con, "SELECT DISTINCT batch FROM interns WHERE batch IS NOT NULL AND batch != '' ORDER BY batch");
while ($bf = mysqli_fetch_assoc($bfQ)) $batchesForFilter[] = $bf['batch'];
 
$hasLocCol = false;
$locChk2 = mysqli_query($con, "SHOW COLUMNS FROM interns LIKE 'intern_location'");
if ($locChk2 && mysqli_num_rows($locChk2) > 0) $hasLocCol = true;
$locSelect = $hasLocCol ? ", i.intern_location" : ", '' as intern_location";

$hasBranchCol2 = false;
$branchChk2 = mysqli_query($con, "SHOW COLUMNS FROM interns LIKE 'branch'");
if ($branchChk2 && mysqli_num_rows($branchChk2) > 0) $hasBranchCol2 = true;
$branchSelect = $hasBranchCol2 ? ", i.branch" : ", '' as branch";
 
$hasTrainingEndCol = false;
$tedChk2 = mysqli_query($con, "SHOW COLUMNS FROM interns LIKE 'trainingEndDate'");
if ($tedChk2 && mysqli_num_rows($tedChk2) > 0) $hasTrainingEndCol = true;
$trainingEndSelect = $hasTrainingEndCol ? ", i.trainingEndDate" : ", NULL as trainingEndDate";

// Fetch ALL rows (no pagination — DataTables handles it client-side)
$allInterns = [];
$allQ = mysqli_query($con, "
    SELECT i.*, COALESCE(s.fullName,'—') as mentorName $locSelect $branchSelect $trainingEndSelect
    FROM interns i
    LEFT JOIN signup s ON s.userId = i.approvedBy
    $where ORDER BY i.createdAt DESC
");
while ($r = mysqli_fetch_assoc($allQ)) $allInterns[] = $r;
$totalRows = count($allInterns);
 
// All active batches for edit modal
$batchesForEdit = [];
$bfEQ = mysqli_query($con, "SELECT batchName FROM batches WHERE status='Active' ORDER BY batchName");
while ($be = mysqli_fetch_assoc($bfEQ)) $batchesForEdit[] = $be['batchName'];
 
function getTrainingPhaseInfo($intern) {
    $today = date('Y-m-d');
    if (empty($intern['trainingEndDate']) || $intern['phase'] !== 'Training') return null;
    $end      = $intern['trainingEndDate'];
    $daysLeft = (int)ceil((strtotime($end) - strtotime($today)) / 86400);
    $trainingDays = ($intern['stipend'] === 'yes') ? 8 : 15;
    $type     = ($intern['stipend'] === 'yes') ? 'Stipend' : 'Mentor-led';
    return [
        'end'          => $end,
        'daysLeft'     => $daysLeft,
        'trainingDays' => $trainingDays,
        'type'         => $type,
        'isOverdue'    => $daysLeft < 0,
        'isDueToday'   => $daysLeft === 0,
    ];
}

function getDisplayLocation($intern) {
    if (!empty($intern['branch'])) return $intern['branch'];
    if (!empty($intern['intern_location'])) return $intern['intern_location'];
    return '—';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Onboarding History</title>
    <?php include('../partials/_links.php'); ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap4.min.css">
    <script src="https://cdn.jsdelivr.net/npm/exceljs/dist/exceljs.min.js"></script>
    <style>
        :root {
            --primary: #2f55ff;
            --primary-light: #eef1ff;
            --success: #28a745;
            --warning: #f59e0b;
            --danger: #e53935;
            --border: #e0e4ef;
            --radius: 8px;
            --shadow: 0 2px 12px rgba(47,85,255,0.07);
        }
        .stat-card {
            background: #fff; border-radius: 12px; padding: 18px 22px;
            box-shadow: var(--shadow); border-left: 4px solid var(--primary);
            display: flex; align-items: center; gap: 16px;
        }
        .stat-card.training { border-color: var(--warning); }
        .stat-card.working  { border-color: var(--success); }
        .stat-card.total    { border-color: var(--primary); }
        .stat-icon { width: 46px; height: 46px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0; }
        .stat-icon.total    { background: var(--primary-light); color: var(--primary); }
        .stat-icon.training { background: #fff8e1; color: var(--warning); }
        .stat-icon.working  { background: #f0fff4; color: var(--success); }
        .stat-num   { font-size: 1.7rem; font-weight: 800; color: #222; line-height: 1; }
        .stat-label { font-size: 0.82rem; color: #888; margin-top: 2px; }
        .filter-bar {
            background: #fff; border-radius: var(--radius); padding: 16px 20px;
            box-shadow: var(--shadow); margin-bottom: 20px;
            display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end;
        }
        .filter-bar .form-group { margin: 0; flex: 1; min-width: 160px; }
        .filter-bar label { font-size: 0.78rem; font-weight: 600; color: #888; margin-bottom: 4px; display: block; }
        .filter-bar .form-control, .filter-bar select { border-radius: 6px; font-size: 0.87rem; border: 1.5px solid var(--border); padding: 7px 11px; }
        .btn-filter { background: var(--primary); color: #fff; border: none; border-radius: 6px; padding: 9px 20px; font-weight: 600; font-size: 0.88rem; cursor: pointer; }
        .btn-reset  { background: #f1f3f9; color: #555; border: none; border-radius: 6px; padding: 9px 16px; font-weight: 600; font-size: 0.88rem; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-excel  { background: #217346; color: #fff; border: none; border-radius: 6px; padding: 8px 16px; font-weight: 600; font-size: 0.88rem; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: background 0.2s; }
        .btn-excel:hover { background: #185c37; color: #fff; }
        .history-table-wrap { background: #fff; border-radius: 12px; box-shadow: var(--shadow); overflow: hidden; }
        .history-table-wrap .table-header { padding: 18px 22px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid var(--border); }
        .history-table-wrap .table-header h5 { margin: 0; font-weight: 700; font-size: 1rem; }
        table.intern-table { width: 100%; border-collapse: collapse; font-size: 0.87rem; }
        .intern-table th { background: #f8f9fd; color: #555; font-weight: 700; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.05em; padding: 12px 16px; border-bottom: 1px solid var(--border); white-space: nowrap; }
        .intern-table td { padding: 11px 16px; border-bottom: 1px solid #f2f4fb; vertical-align: middle; color: #333; }
        .intern-table tr:last-child td { border-bottom: none; }
        .intern-table tr:hover td { background: #f8f9ff; }
        .badge-phase { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; letter-spacing: 0.03em; }
        .badge-training { background: #fff8e1; color: #b45309; }
        .badge-working  { background: #f0fff4; color: #15803d; }
        .badge-mode { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
        .badge-online  { background: #eef1ff; color: var(--primary); }
        .badge-offline { background: #f3f4f6; color: #555; }
        .badge-stipend-yes { background: #f0fff4; color: #15803d; font-size: 0.75rem; font-weight: 600; padding: 3px 10px; border-radius: 20px; display: inline-block; }
        .badge-stipend-no  { background: #fff1f1; color: #e53935; font-size: 0.75rem; font-weight: 600; padding: 3px 10px; border-radius: 20px; display: inline-block; }
        .btn-edit { background: var(--primary-light); color: var(--primary); border: none; border-radius: 6px; padding: 6px 14px; font-size: 0.82rem; font-weight: 600; cursor: pointer; transition: background 0.2s; }
        .btn-edit:hover { background: var(--primary); color: #fff; }

        .training-phase-wrap { display: flex; flex-direction: column; gap: 3px; }
        .training-days-badge {
            display: inline-flex; align-items: center; gap: 4px;
            font-size: 0.72rem; font-weight: 700; padding: 2px 8px;
            border-radius: 20px; white-space: nowrap;
        }
        .training-days-badge.ok      { background: #e8f5e9; color: #2e7d32; }
        .training-days-badge.warning { background: #fff8e1; color: #b45309; }
        .training-days-badge.overdue { background: #fff1f1; color: var(--danger); }
        .training-days-badge.today   { background: #fce4ec; color: #c62828; animation: pulse 1.5s infinite; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.6; } }
        .training-type-badge {
            display: inline-flex; align-items: center; gap: 3px;
            font-size: 0.68rem; font-weight: 600; padding: 2px 7px; border-radius: 20px;
        }
        .training-type-stipend    { background: #e3f2fd; color: #1565c0; }
        .training-type-mentorlead { background: #f3e5f5; color: #6a1b9a; }
        .training-progress-bar { height: 4px; border-radius: 2px; background: #e0e4ef; overflow: hidden; margin-top: 2px; width: 100px; }
        .training-progress-fill { height: 100%; border-radius: 2px; transition: width 0.3s; }
        .progress-ok      { background: #28a745; }
        .progress-warning { background: #f59e0b; }
        .progress-overdue { background: var(--danger); }
        .working-phase-wrap { display: flex; flex-direction: column; gap: 3px; }
        .badge-working-simple { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; letter-spacing: 0.03em; background: #f0fff4; color: #15803d; }

        /* DataTables overrides */
        .dataTables_wrapper .dataTables_paginate .paginate_button.current,
        .dataTables_wrapper .dataTables_paginate .paginate_button.current:hover {
            background: var(--primary) !important;
            border-color: var(--primary) !important;
            color: #fff !important;
            border-radius: 6px;
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
            background: var(--primary-light) !important;
            border-color: var(--primary) !important;
            color: var(--primary) !important;
            border-radius: 6px;
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button { border-radius: 6px; font-size: 0.83rem; font-weight: 600; }
        .dataTables_wrapper .dataTables_filter input { border: 1.5px solid var(--border); border-radius: 6px; padding: 5px 10px; font-size: 0.87rem; }
        .dataTables_wrapper .dataTables_length select { border: 1.5px solid var(--border); border-radius: 6px; padding: 4px 8px; font-size: 0.87rem; }
        .dataTables_wrapper .dataTables_info { font-size: 0.83rem; color: #888; padding-top: 10px; }
        .dataTables_wrapper .dataTables_paginate { padding-top: 10px; }
        .dt-controls-row { padding: 12px 22px 0; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; }

        .modal-header { background: var(--primary-light); border-bottom: 1px solid var(--border); }
        .modal-title  { color: var(--primary); font-weight: 700; }
        .modal .form-control, .modal select { border-radius: 6px; border: 1.5px solid var(--border); font-size: 0.9rem; }
        .modal .form-control:focus, .modal select:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(47,85,255,0.1); }
        .modal label { font-weight: 600; font-size: 0.85rem; color: #555; }
        .modal-section { font-size: 0.78rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.08em; color: var(--primary); border-bottom: 1px solid var(--border); padding-bottom: 4px; margin: 16px 0 12px; }
        .btn-onboard { background: var(--primary); color: #fff; border: none; border-radius: 6px; padding: 8px 18px; font-weight: 600; font-size: 0.88rem; text-decoration: none; display: inline-flex; align-items: center; gap: 7px; }
        .btn-onboard:hover { background: #1a3de0; color: #fff; text-decoration: none; }
        .alert { border-radius: var(--radius); }
        .empty-state { text-align: center; padding: 48px 20px; color: #aaa; }
        .empty-state i { font-size: 2.5rem; margin-bottom: 12px; color: #d0d5f0; }
    </style>
</head>
<body>
<div class="container-scroller">
    <?php include('../partials/_navbar.php'); ?>
    <div class="container-fluid page-body-wrapper">
        <?php include('../partials/_sidebar.php'); include('../partials/_settings-panel.php'); ?>
        <div class="main-panel">
            <div class="content-wrapper">
 
                <?php if (isset($_SESSION['editSuccess'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo $_SESSION['editSuccess']; unset($_SESSION['editSuccess']); ?>
                        <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
                    </div>
                <?php endif; ?>
                <?php if (isset($_SESSION['editError'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo $_SESSION['editError']; unset($_SESSION['editError']); ?>
                        <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
                    </div>
                <?php endif; ?>

                <!-- Page Header -->
                <div class="d-flex align-items-center justify-content-between mb-4">
                    <div>
                        <h4 class="mb-1" style="font-weight:800;color:#222;">
                            <i class="fa-solid fa-clock-rotate-left me-2" style="color:var(--primary)"></i>
                            Onboarding History
                        </h4>
                        <p class="mb-0" style="color:#888;font-size:0.88rem;">
                            Logged in as <strong><?php echo $userName; ?></strong>
                        </p>
                    </div>
                    <div style="display:flex;gap:10px;align-items:center;">
                        <button class="btn-excel" onclick="exportHistoryExcel()">
                            <i class="fa-solid fa-file-excel"></i> Export Excel
                        </button>
                        <a href="onBoarding.php" class="btn-onboard">
                            <i class="fa-solid fa-user-plus"></i> New Onboarding
                        </a>
                    </div>
                </div>
 
                <!-- Stats -->
                <?php
                $statsTotal    = (int)mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as c FROM interns"))['c'];
                $statsTraining = (int)mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as c FROM interns WHERE phase='Training'"))['c'];
                $statsWorking  = (int)mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as c FROM interns WHERE phase='Working'"))['c'];
                ?>
                <div class="row mb-4">
                    <div class="col-md-4 mb-3">
                        <div class="stat-card total">
                            <div class="stat-icon total"><i class="fa-solid fa-users"></i></div>
                            <div><div class="stat-num"><?php echo $statsTotal; ?></div><div class="stat-label">Total Interns Onboarded</div></div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="stat-card training">
                            <div class="stat-icon training"><i class="fa-solid fa-book-open"></i></div>
                            <div><div class="stat-num"><?php echo $statsTraining; ?></div><div class="stat-label">In Training Phase</div></div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="stat-card working">
                            <div class="stat-icon working"><i class="fa-solid fa-briefcase"></i></div>
                            <div><div class="stat-num"><?php echo $statsWorking; ?></div><div class="stat-label">In Working Phase</div></div>
                        </div>
                    </div>
                </div>
 
                <!-- Filter Bar -->
                <form method="GET" action="" class="filter-bar">
                    <div class="form-group">
                        <label>Search</label>
                        <input type="text" name="search" class="form-control" placeholder="Name, email, intern ID…" value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="form-group">
                        <label>Phase</label>
                        <select name="phase" class="form-control">
                            <option value="">All Phases</option>
                            <?php foreach ($phases as $p): ?>
                                <option value="<?php echo $p; ?>" <?php echo ($filterPhase === $p) ? 'selected' : ''; ?>><?php echo $p; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Batch</label>
                        <select name="batch" class="form-control">
                            <option value="">All Batches</option>
                            <?php foreach ($batchesForFilter as $b): ?>
                                <option value="<?php echo htmlspecialchars($b); ?>" <?php echo ($filterBatch === $b) ? 'selected' : ''; ?>><?php echo htmlspecialchars($b); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Role</label>
                        <select name="role" class="form-control">
                            <option value="">All Roles</option>
                            <?php foreach ($roles as $r): ?>
                                <option value="<?php echo $r; ?>" <?php echo ($filterRole === $r) ? 'selected' : ''; ?>><?php echo $r; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn-filter"><i class="fa-solid fa-magnifying-glass me-1"></i> Filter</button>
                    <a href="onboarding_history.php" class="btn-reset"><i class="fa-solid fa-rotate me-1"></i> Reset</a>
                </form>
 
                <!-- Table -->
                <div class="history-table-wrap">
                    <div class="table-header">
                        <h5><i class="fa-solid fa-list me-2" style="color:var(--primary)"></i> Intern Records</h5>
                        <span style="color:#888;font-size:0.83rem;"><?php echo $totalRows; ?> records found</span>
                    </div>
 
                    <div style="overflow-x:auto; padding: 16px 16px 0;">
                        <table class="intern-table table table-hover" id="internTable" style="width:100%">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Intern ID</th>
                                    <th>Full Name</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Mentor</th>
                                    <th>Location</th>
                                    <th>DOJ</th>
                                    <th>End Date</th>
                                    <th>Batch</th>
                                    <th>Working Slots</th>
                                    <th>Mode</th>
                                    <th>Stipend</th>
                                    <th>Phase &amp; Training</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $sr = 1;
                                if (count($allInterns) > 0):
                                    foreach ($allInterns as $intern):
                                        $phaseClass   = (strtolower($intern['phase'] ?? '') === 'working') ? 'badge-working' : 'badge-training';
                                        $modeClass    = (strtolower($intern['mode']  ?? '') === 'online')  ? 'badge-online'  : 'badge-offline';
                                        $stipendClass = ($intern['stipend'] === 'yes') ? 'badge-stipend-yes' : 'badge-stipend-no';
                                        $stipendText  = ($intern['stipend'] === 'yes') ? '₹' . number_format($intern['stipendAmount']) : 'No';
                                        $tpInfo       = getTrainingPhaseInfo($intern);
                                        $displayLoc   = getDisplayLocation($intern);
                                ?>
                                <tr>
                                    <td><?php echo $sr++; ?></td>
                                    <td><code style="background:#f0f2ff;padding:2px 7px;border-radius:4px;color:var(--primary);font-size:0.8rem;"><?php echo htmlspecialchars($intern['internId']); ?></code></td>
                                    <td><strong><?php echo htmlspecialchars($intern['fullName']); ?></strong></td>
                                    <td style="color:#666;font-size:0.82rem;"><?php echo htmlspecialchars($intern['email']); ?></td>
                                    <td><?php echo htmlspecialchars($intern['role']); ?></td>
                                    <td style="font-size:0.82rem;color:#555;">
                                        <i class="fa-solid fa-chalkboard-teacher me-1" style="color:#aaa;"></i>
                                        <?php echo htmlspecialchars($intern['mentorName'] ?? '—'); ?>
                                    </td>
                                    <td style="font-size:0.82rem;color:#666;">
                                        <i class="fa-solid fa-map-marker-alt me-1" style="color:#aaa;"></i>
                                        <?php echo htmlspecialchars($displayLoc); ?>
                                    </td>
                                    <td><?php echo $intern['doj'] ? date('d M Y', strtotime($intern['doj'])) : '—'; ?></td>
                                    <td><?php echo $intern['expectedEndDate'] ? date('d M Y', strtotime($intern['expectedEndDate'])) : '—'; ?></td>
                                    <td><?php echo htmlspecialchars($intern['batch'] ?? '—'); ?></td>
                                    <td style="font-size:0.82rem;"><?php echo htmlspecialchars($intern['workingSlots'] ?? '—'); ?></td>
                                    <td><span class="badge-mode <?php echo $modeClass; ?>"><?php echo htmlspecialchars($intern['mode'] ?? '—'); ?></span></td>
                                    <td><span class="<?php echo $stipendClass; ?>"><?php echo $stipendText; ?></span></td>
 
                                    <!-- Phase & Training Info -->
                                    <td>
                                        <?php if (strtolower($intern['phase'] ?? '') === 'working'): ?>
                                            <div class="working-phase-wrap">
                                                <span class="badge-working-simple">
                                                    <i class="fa-solid fa-briefcase me-1"></i> Working
                                                </span>
                                            </div>
                                        <?php else: ?>
                                            <div class="training-phase-wrap">
                                                <span class="badge-phase badge-training">Training</span>
                                                <?php if ($tpInfo): ?>
                                                    <?php
                                                        $typeClass  = $tpInfo['type'] === 'Stipend' ? 'training-type-stipend' : 'training-type-mentorlead';
                                                        $typeIcon   = $tpInfo['type'] === 'Stipend' ? 'fa-coins' : 'fa-chalkboard-teacher';
                                                        $typeLabel  = $tpInfo['type'] === 'Stipend' ? 'Stipend · 8d' : 'Mentor-led · 15d';
                                                        if ($tpInfo['isOverdue']) {
                                                            $dayClass  = 'overdue'; $dayText = 'Overdue ' . abs($tpInfo['daysLeft']) . 'd';
                                                            $dayIcon   = 'fa-circle-exclamation'; $progClass = 'progress-overdue'; $progress = 100;
                                                        } elseif ($tpInfo['isDueToday']) {
                                                            $dayClass  = 'today'; $dayText = 'Ends Today!';
                                                            $dayIcon   = 'fa-bell'; $progClass = 'progress-warning'; $progress = 100;
                                                        } elseif ($tpInfo['daysLeft'] <= 3) {
                                                            $dayClass  = 'warning'; $dayText = $tpInfo['daysLeft'] . 'd left';
                                                            $dayIcon   = 'fa-clock'; $progClass = 'progress-warning';
                                                            $elapsed   = $tpInfo['trainingDays'] - $tpInfo['daysLeft'];
                                                            $progress  = min(100, round(($elapsed / $tpInfo['trainingDays']) * 100));
                                                        } else {
                                                            $dayClass  = 'ok'; $dayText = $tpInfo['daysLeft'] . 'd left';
                                                            $dayIcon   = 'fa-hourglass-half'; $progClass = 'progress-ok';
                                                            $elapsed   = $tpInfo['trainingDays'] - $tpInfo['daysLeft'];
                                                            $progress  = min(100, round(($elapsed / $tpInfo['trainingDays']) * 100));
                                                        }
                                                    ?>
                                                    <span class="training-type-badge <?php echo $typeClass; ?>">
                                                        <i class="fa-solid <?php echo $typeIcon; ?>"></i> <?php echo $typeLabel; ?>
                                                    </span>
                                                    <span class="training-days-badge <?php echo $dayClass; ?>">
                                                        <i class="fa-solid <?php echo $dayIcon; ?>"></i> <?php echo $dayText; ?>
                                                    </span>
                                                    <div class="training-progress-bar">
                                                        <div class="training-progress-fill <?php echo $progClass; ?>" style="width:<?php echo $progress; ?>%"></div>
                                                    </div>
                                                    <span style="font-size:0.68rem;color:#aaa;">
                                                        ends <?php echo date('d M', strtotime($tpInfo['end'])); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
 
                                    <td>
                                        <button class="btn-edit" onclick='openEditModal(<?php echo json_encode($intern); ?>)'>
                                            <i class="fa-solid fa-pen me-1"></i> Edit
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; else: ?>
                                <tr><td colspan="15"><div class="empty-state"><i class="fa-solid fa-inbox d-block"></i>No intern records found.</div></td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
 
            </div>
        </div>
    </div>
</div>
 
<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fa-solid fa-pen me-2"></i> Edit Intern Record</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <form method="POST" id="editForm">
                <input type="hidden" name="edit_submit" value="1">
                <input type="hidden" name="editInternId" id="editInternId">
                <div class="modal-body">
 
                    <div class="modal-section">Personal Info</div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group mb-3"><label>Full Name</label><input type="text" class="form-control" name="editFullName" id="editFullName" required></div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group mb-3"><label>Email</label><input type="email" class="form-control" name="editEmail" id="editEmail" required></div>
                        </div>
                    </div>
 
                    <div class="modal-section">Role & Stack</div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label>Role</label>
                                <select class="form-control" name="editRole" id="editRole">
                                    <?php foreach ($roles as $r): ?><option value="<?php echo $r; ?>"><?php echo $r; ?></option><?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group mb-3"><label>Tech Stack</label><input type="text" class="form-control" name="editTechStack" id="editTechStack"></div>
                        </div>
                    </div>
 
                    <div class="modal-section">Dates & Schedule</div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group mb-3"><label>Date of Joining</label><input type="date" class="form-control" name="editDoj" id="editDoj"></div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group mb-3"><label>Expected End Date</label><input type="date" class="form-control" name="editExpectedEnd" id="editExpectedEnd"></div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group mb-3"><label>Working Slots</label><input type="text" class="form-control" name="editWorkingSlots" id="editWorkingSlots" placeholder="e.g. 09:00 - 18:00"></div>
                        </div>
                    </div>
 
                    <div class="modal-section">Batch, Phase & Mode</div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group mb-3">
                                <label>Batch</label>
                                <select class="form-control" name="editBatch" id="editBatch">
                                    <?php foreach ($batchesForEdit as $b): ?><option value="<?php echo htmlspecialchars($b); ?>"><?php echo htmlspecialchars($b); ?></option><?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group mb-3">
                                <label>Phase</label>
                                <select class="form-control" name="editPhase" id="editPhase">
                                    <option value="Training">Training</option>
                                    <option value="Working">Working</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group mb-3">
                                <label>Mode</label>
                                <select class="form-control" name="editMode" id="editMode">
                                    <option value="Online">Online</option>
                                    <option value="Offline">Offline</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="modal-section">Branch</div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label><i class="fa-solid fa-map-marker-alt me-1" style="color:var(--primary)"></i> Branch (Location)</label>
                                <select class="form-control" name="editBranch" id="editBranch">
                                    <option value="Pune">Pune</option>
                                    <option value="Kinwat">Kinwat</option>
                                </select>
                                <small class="text-muted" style="font-size:0.76rem;">Branch serves as the intern's location</small>
                            </div>
                        </div>
                    </div>
 
                    <div class="modal-section">Stipend</div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group mb-3">
                                <label>Stipend</label>
                                <select class="form-control" name="editStipend" id="editStipend" onchange="toggleEditStipend(this.value)">
                                    <option value="no">No</option>
                                    <option value="yes">Yes</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4" id="editStipendAmountWrap" style="display:none;">
                            <div class="form-group mb-3">
                                <label>Stipend Amount (₹)</label>
                                <input type="number" class="form-control" name="editStipendAmount" id="editStipendAmount" min="0" step="100">
                            </div>
                        </div>
                    </div>
                    <small class="text-muted" style="font-size:0.78rem;">
                        <i class="fa-solid fa-circle-info me-1" style="color:var(--primary)"></i>
                        Changing stipend or DOJ will automatically recalculate the training end date
                        (Stipend = 8 days, No Stipend = 15 days from DOJ).
                    </small>
 
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk me-1"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
 
<!-- Embedded data for Excel export -->
<script>
const allInternsData = <?php echo json_encode($allInterns); ?>;
const exportFilters = {
    search: <?php echo json_encode($search); ?>,
    phase:  <?php echo json_encode($filterPhase); ?>,
    batch:  <?php echo json_encode($filterBatch); ?>,
    role:   <?php echo json_encode($filterRole); ?>
};
</script>
 
<script src="../vendors/js/vendor.bundle.base.js"></script>
<script src="../js/off-canvas.js"></script>
<script src="../js/hoverable-collapse.js"></script>
<script src="../js/template.js"></script>
<script src="../js/settings.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap4.min.js"></script>

<script>
$(document).ready(function () {
    $('#internTable').DataTable({
        pageLength: 10,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
        order: [],
        responsive: true,
        scrollX: true,
        language: {
            search: '',
            searchPlaceholder: 'Search records...',
            lengthMenu: 'Show _MENU_ entries',
            info: 'Showing _START_ to _END_ of _TOTAL_ records',
            infoEmpty: 'No records available',
            infoFiltered: '(filtered from _MAX_ total)',
            paginate: { first: '«', last: '»', next: '›', previous: '‹' }
        },
        columnDefs: [
            { orderable: false, targets: [13, 14] }
        ],
        dom: '<"dt-controls-row"lf>rt<"dt-controls-row"ip>',
        initComplete: function () {
            $('.dataTables_filter input').addClass('form-control').css({ 'border': '1.5px solid #e0e4ef', 'border-radius': '6px', 'font-size': '0.87rem', 'padding': '7px 11px' });
            $('.dataTables_length select').addClass('form-control').css({ 'border': '1.5px solid #e0e4ef', 'border-radius': '6px', 'font-size': '0.87rem' });
        }
    });
});

function openEditModal(intern) {
    document.getElementById('editInternId').value      = intern.internId        || '';
    document.getElementById('editFullName').value      = intern.fullName        || '';
    document.getElementById('editEmail').value         = intern.email           || '';
    document.getElementById('editRole').value          = intern.role            || '';
    document.getElementById('editTechStack').value     = intern.techStack       || '';
    document.getElementById('editDoj').value           = intern.doj             || '';
    document.getElementById('editExpectedEnd').value   = intern.expectedEndDate || '';
    document.getElementById('editWorkingSlots').value  = intern.workingSlots    || '';
    document.getElementById('editBatch').value         = intern.batch           || '';
    document.getElementById('editPhase').value         = intern.phase           || 'Training';
    document.getElementById('editMode').value          = intern.mode            || 'Online';
    document.getElementById('editStipend').value       = intern.stipend         || 'no';
    document.getElementById('editStipendAmount').value = intern.stipendAmount   || '';
    var branch = intern.branch || intern.intern_location || 'Pune';
    if (branch !== 'Pune' && branch !== 'Kinwat') branch = 'Pune';
    document.getElementById('editBranch').value = branch;
    toggleEditStipend(intern.stipend);
    $('#editModal').modal('show');
}
 
function toggleEditStipend(val) {
    const wrap  = document.getElementById('editStipendAmountWrap');
    const input = document.getElementById('editStipendAmount');
    if (val === 'yes') { wrap.style.display = 'block'; input.required = true; }
    else               { wrap.style.display = 'none';  input.required = false; }
}
 
async function exportHistoryExcel() {
    if (!allInternsData || allInternsData.length === 0) {
        alert('No records to export.');
        return;
    }

    const workbook = new ExcelJS.Workbook();
    const worksheet = workbook.addWorksheet('Onboarding History');

    const today = new Date().toLocaleDateString('en-GB');
    const title = `Onboarding History Report - ${today}`;

    worksheet.mergeCells('A1:Q1');
    const titleCell = worksheet.getCell('A1');
    titleCell.value = title;
    titleCell.font = { bold: true, size: 16 };
    titleCell.alignment = { horizontal: 'center', vertical: 'middle' };
    titleCell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFD3D3D3' } };
    worksheet.getRow(1).height = 30;

    const headers = ['#', 'Intern ID', 'Full Name', 'Email', 'Role', 'Mentor', 'Location', 'Date of Joining', 'Expected End Date', 'Training End Date', 'Training Type', 'Batch', 'Working Slots', 'Mode', 'Stipend', 'Stipend Amount (₹)', 'Phase'];
    const headerRow = worksheet.addRow(headers);
    headerRow.eachCell(cell => {
        cell.font = { bold: true };
        cell.alignment = { horizontal: 'center', vertical: 'middle', wrapText: true };
        cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFF2F2F2' } };
        cell.border = { top: { style: 'thin' }, bottom: { style: 'thin' }, left: { style: 'thin' }, right: { style: 'thin' } };
    });
    worksheet.getRow(2).height = 30;

    allInternsData.forEach((i, idx) => {
        const location = i.branch || i.intern_location || '—';
        const trainingType = i.stipend === 'yes' ? 'Stipend-based (8 days)' : 'Mentor-led (15 days)';
        const row = worksheet.addRow([
            idx + 1, i.internId || '', i.fullName || '', i.email || '', i.role || '',
            i.mentorName || '—', location, i.doj || '', i.expectedEndDate || '',
            i.trainingEndDate || '', i.phase === 'Training' ? trainingType : '—',
            i.batch || '—', i.workingSlots || '—', i.mode || '—',
            i.stipend === 'yes' ? 'Yes' : 'No',
            i.stipend === 'yes' ? parseFloat(i.stipendAmount || 0) : 0,
            i.phase || '—'
        ]);
        row.eachCell((cell, colNumber) => {
            cell.alignment = { horizontal: colNumber === 1 ? 'center' : 'left', vertical: 'middle', wrapText: colNumber === 4 || colNumber === 11 };
            cell.border = { top: { style: 'thin' }, bottom: { style: 'thin' }, left: { style: 'thin' }, right: { style: 'thin' } };
        });
        if (i.phase === 'Working') {
            row.eachCell(cell => { cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFF0FFF4' } }; });
        } else if (i.phase === 'Training') {
            row.eachCell(cell => { cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFFFF8E1' } }; });
        }
    });

    worksheet.columns = [
        { width: 5 }, { width: 16 }, { width: 24 }, { width: 30 }, { width: 20 },
        { width: 22 }, { width: 18 }, { width: 16 }, { width: 16 }, { width: 18 },
        { width: 24 }, { width: 16 }, { width: 18 }, { width: 12 }, { width: 10 },
        { width: 18 }, { width: 14 }
    ];

    const parts = [];
    if (exportFilters.phase) parts.push(exportFilters.phase);
    if (exportFilters.batch) parts.push(exportFilters.batch);
    if (exportFilters.role) parts.push(exportFilters.role);
    const suffix = parts.length ? '_' + parts.join('_').replace(/[^a-z0-9_]/gi, '_') : '';

    const buffer = await workbook.xlsx.writeBuffer();
    const blob = new Blob([buffer], { type: "application/octet-stream" });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `Onboarding_History${suffix}_${new Date().toISOString().split('T')[0]}.xlsx`;
    link.click();
}
</script>
</body>
</html>