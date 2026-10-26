<?php
session_start();
include('../include/db_config.php');

if (!isset($_SESSION['userId'])) {
    echo "<script>window.location.href='login.php';</script>";
    exit;
}

$userId   = $_SESSION['userId'];
$getUser  = mysqli_query($con, "SELECT designation, fullName FROM signup WHERE userId='$userId'");
$userData = mysqli_fetch_assoc($getUser);

if (!$userData ||
    ($userData['designation'] != 'seniorIntern' && $userData['designation'] != 'resourceAllocator')) {
    echo "<script>alert('Unauthorized Access'); window.location.href='logout.php';</script>";
    exit;
}

$userName = htmlspecialchars($userData['fullName'] ?? 'Unknown');

// ── If the logged-in user is a seniorIntern, restrict to their assigned candidates ──
// resourceAllocator can see ALL candidates.
$isSeniorIntern  = ($userData['designation'] === 'seniorIntern');
$assignedFilter  = $isSeniorIntern
    ? " AND c.assignedInterviewer = '$userId'"
    : '';

// ── Search / Filter ───────────────────────────────────────────────────────────
$search       = isset($_GET['search'])  ? mysqli_real_escape_string($con, trim($_GET['search'])) : '';
$filterStatus = isset($_GET['status'])  ? mysqli_real_escape_string($con, $_GET['status'])        : '';
$filterMode   = isset($_GET['mode'])    ? mysqli_real_escape_string($con, $_GET['mode'])           : '';

$where = "WHERE 1=1";
if ($search)       $where .= " AND (c.applicantName LIKE '%$search%' OR c.email LIKE '%$search%' OR c.mobile LIKE '%$search%')";
if ($filterStatus === 'Pending')        $where .= " AND te.id IS NULL";
elseif ($filterStatus === 'Evaluated')  $where .= " AND te.id IS NOT NULL AND te.submittedToHr = 0";
elseif ($filterStatus === 'Sent to HR') $where .= " AND te.submittedToHr = 1";
if ($filterMode)      $where .= " AND c.interviewMode = '$filterMode'";

// ── Stats (scoped to this intern if seniorIntern) ─────────────────────────────
$totalCandidates = (int)mysqli_fetch_assoc(mysqli_query($con,
    "SELECT COUNT(*) as c
     FROM candidates c
     WHERE 1=1 $assignedFilter"
))['c'];

$pending = (int)mysqli_fetch_assoc(mysqli_query($con,
    "SELECT COUNT(*) as c
     FROM candidates c
     LEFT JOIN technical_evaluations te ON c.id = te.candidateId
     WHERE te.id IS NULL $assignedFilter"
))['c'];

$evaluated = (int)mysqli_fetch_assoc(mysqli_query($con,
    "SELECT COUNT(*) as c
     FROM technical_evaluations te
     JOIN candidates c ON c.id = te.candidateId
     WHERE te.submittedToHr = 0 $assignedFilter"
))['c'];

$sentToHR = (int)mysqli_fetch_assoc(mysqli_query($con,
    "SELECT COUNT(*) as c
     FROM technical_evaluations te
     JOIN candidates c ON c.id = te.candidateId
     WHERE te.submittedToHr = 1 $assignedFilter"
))['c'];

// ── Fetch rows for table (search/filter + intern scope) ───────────────────────
$dataQuery = "
    SELECT c.*, te.id AS evalId, te.submittedToHr, te.overallScore, te.overallFeedback, te.submittedAt
    FROM candidates c
    LEFT JOIN technical_evaluations te ON c.id = te.candidateId
    $where
    $assignedFilter
    ORDER BY c.id DESC
";
$query = mysqli_query($con, $dataQuery);

if (!$query) {
    die("<div style='padding:20px;background:#fee;color:red;margin:20px;border-radius:8px;'>
        <strong>Query Error:</strong> " . mysqli_error($con) . "
        <br><strong>Query:</strong> " . htmlspecialchars($dataQuery) . "
    </div>");
}

$rows = [];
while ($r = mysqli_fetch_assoc($query)) {
    $r['langData'] = [];
    if ($r['evalId']) {
        $lq = mysqli_query($con, "SELECT * FROM language_ratings WHERE evaluationId='" . intval($r['evalId']) . "'");
        if ($lq) while ($l = mysqli_fetch_assoc($lq)) $r['langData'][] = $l;
    }
    $rows[] = $r;
}
$totalRows = count($rows);

// ── Fetch for Excel export (same scope) ──────────────────────────────────────
$allCandidates = [];
$allQ = mysqli_query($con, "
    SELECT c.*, te.id AS evalId, te.submittedToHr, te.overallScore, te.overallFeedback, te.submittedAt
    FROM candidates c
    LEFT JOIN technical_evaluations te ON c.id = te.candidateId
    $where
    $assignedFilter
    ORDER BY c.id DESC
");
if ($allQ) {
    while ($r = mysqli_fetch_assoc($allQ)) {
        $r['languages'] = [];
        if ($r['evalId']) {
            $lq = mysqli_query($con, "SELECT * FROM language_ratings WHERE evaluationId='" . intval($r['evalId']) . "'");
            if ($lq) while ($l = mysqli_fetch_assoc($lq)) $r['languages'][] = $l;
        }
        $allCandidates[] = $r;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Technical Evaluation</title>
    <?php include('../partials/_links.php'); ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap4.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/exceljs/dist/exceljs.min.js"></script>
    <style>
        :root {
            --primary: #2f55ff;
            --primary-light: #eef1ff;
            --success: #28a745;
            --warning: #f59e0b;
            --danger: #e53935;
            --info: #17a2b8;
            --border: #e0e4ef;
            --radius: 8px;
            --shadow: 0 2px 12px rgba(47,85,255,0.07);
        }
        .stat-card {
            background:#fff; border-radius:12px; padding:18px 22px;
            box-shadow:var(--shadow); border-left:4px solid var(--primary);
            display:flex; align-items:center; gap:16px;
        }
        .stat-card.total     { border-color:var(--primary); }
        .stat-card.pending   { border-color:var(--warning); }
        .stat-card.evaluated { border-color:var(--info); }
        .stat-card.sent      { border-color:var(--success); }
        .stat-icon {
            width:46px; height:46px; border-radius:10px;
            display:flex; align-items:center; justify-content:center;
            font-size:1.2rem; flex-shrink:0;
        }
        .stat-icon.total     { background:var(--primary-light); color:var(--primary); }
        .stat-icon.pending   { background:#fff8e1; color:var(--warning); }
        .stat-icon.evaluated { background:#e3f2fd; color:var(--info); }
        .stat-icon.sent      { background:#f0fff4; color:var(--success); }
        .stat-num   { font-size:1.7rem; font-weight:800; color:#222; line-height:1; }
        .stat-label { font-size:0.82rem; color:#888; margin-top:2px; }

        .filter-bar {
            background:#fff; border-radius:var(--radius); padding:16px 20px;
            box-shadow:var(--shadow); margin-bottom:20px;
            display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end;
        }
        .filter-bar .form-group { margin:0; flex:1; min-width:160px; }
        .filter-bar label { font-size:0.78rem; font-weight:600; color:#888; margin-bottom:4px; display:block; }
        .filter-bar .form-control,.filter-bar select {
            border-radius:6px; font-size:0.87rem;
            border:1.5px solid var(--border); padding:7px 11px;
        }
        .btn-filter { background:var(--primary); color:#fff; border:none; border-radius:6px; padding:9px 20px; font-weight:600; font-size:0.88rem; cursor:pointer; }
        .btn-reset  { background:#f1f3f9; color:#555; border:none; border-radius:6px; padding:9px 16px; font-weight:600; font-size:0.88rem; cursor:pointer; text-decoration:none; display:inline-block; }
        .btn-excel  { background:#217346; color:#fff; border:none; border-radius:6px; padding:8px 16px; font-weight:600; font-size:0.88rem; cursor:pointer; display:inline-flex; align-items:center; gap:6px; transition:background 0.2s; }
        .btn-excel:hover { background:#185c37; color:#fff; }

        .evaluation-table-wrap { background:#fff; border-radius:12px; box-shadow:var(--shadow); overflow:hidden; }
        .evaluation-table-wrap .table-header { padding:18px 22px; display:flex; align-items:center; justify-content:space-between; border-bottom:1px solid var(--border); }
        .evaluation-table-wrap .table-header h5 { margin:0; font-weight:700; font-size:1rem; }

        table.eval-table { width:100%; border-collapse:collapse; font-size:0.87rem; }
        .eval-table th { background:#f8f9fd; color:#555; font-weight:700; font-size:0.78rem; text-transform:uppercase; letter-spacing:0.05em; padding:12px 16px; border-bottom:1px solid var(--border); white-space:nowrap; }
        .eval-table td { padding:11px 16px; border-bottom:1px solid #f2f4fb; vertical-align:middle; color:#333; }
        .eval-table tr:last-child td { border-bottom:none; }
        .eval-table tr:hover td { background:#f8f9ff; }

        .badge-status  { display:inline-block; padding:4px 11px; border-radius:20px; font-size:0.75rem; font-weight:700; letter-spacing:0.03em; }
        .badge-pending   { background:#fff8e1; color:#b45309; }
        .badge-evaluated { background:#e3f2fd; color:#1565c0; }
        .badge-sent      { background:#f0fff4; color:#15803d; }

        .badge-mode    { display:inline-block; padding:3px 10px; border-radius:20px; font-size:0.75rem; font-weight:600; }
        .badge-online  { background:#eef1ff; color:var(--primary); }
        .badge-offline { background:#f3f4f6; color:#555; }

        .score-badge { background:#ede9ff; color:#4B49AC; padding:4px 11px; border-radius:15px; font-size:0.78rem; font-weight:700; display:inline-block; }

        .btn-group-custom { display:flex; gap:5px; justify-content:center; }
        .btn-action { border:none; border-radius:6px; padding:6px 14px; font-size:0.82rem; font-weight:600; cursor:pointer; transition:all 0.2s; text-decoration:none; display:inline-block; }
        .btn-action.evaluate { background:var(--primary-light); color:var(--primary); }
        .btn-action.evaluate:hover { background:var(--primary); color:#fff; }
        .btn-action.edit  { background:#fff8e1; color:var(--warning); }
        .btn-action.edit:hover  { background:var(--warning); color:#fff; }
        .btn-action.send  { background:#f0fff4; color:var(--success); }
        .btn-action.send:hover  { background:var(--success); color:#fff; }
        .btn-action.view  { background:#e3f2fd; color:var(--info); }
        .btn-action.view:hover  { background:var(--info); color:#fff; }
        .btn-action.disabled { background:#f3f4f6; color:#999; cursor:not-allowed; }

        /* DataTables overrides */
        .dataTables_wrapper .dataTables_paginate .paginate_button.current,
        .dataTables_wrapper .dataTables_paginate .paginate_button.current:hover {
            background:var(--primary) !important; border-color:var(--primary) !important;
            color:#fff !important; border-radius:6px;
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
            background:var(--primary-light) !important; border-color:var(--primary) !important;
            color:var(--primary) !important; border-radius:6px;
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button { border-radius:6px; font-size:0.83rem; font-weight:600; }
        .dataTables_wrapper .dataTables_filter input { border:1.5px solid var(--border); border-radius:6px; padding:5px 10px; font-size:0.87rem; }
        .dataTables_wrapper .dataTables_length select { border:1.5px solid var(--border); border-radius:6px; padding:4px 8px; font-size:0.87rem; }
        .dataTables_wrapper .dataTables_info { font-size:0.83rem; color:#888; padding-top:10px; }
        .dataTables_wrapper .dataTables_paginate { padding-top:10px; }
        div.dataTables_wrapper div.dataTables_filter { text-align:right; }
        .dt-controls-row { padding:12px 22px 0; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; }

        /* Modals */
        .modal-overlay { display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:9999; justify-content:center; align-items:center; }
        .modal-overlay.show { display:flex; }
        .modal-box { background:white; border-radius:12px; padding:0; max-width:500px; width:90%; box-shadow:0 10px 40px rgba(0,0,0,0.2); }
        .view-modal-box { max-width:700px; max-height:90vh; overflow-y:auto; }
        .modal-header-custom { background:var(--primary-light); padding:18px 24px; border-bottom:1px solid var(--border); border-radius:12px 12px 0 0; display:flex; align-items:center; justify-content:space-between; }
        .modal-header-custom h4 { color:var(--primary); margin:0; font-weight:700; font-size:1.1rem; }
        .modal-close { background:none; border:none; font-size:1.5rem; color:#999; cursor:pointer; padding:0; width:30px; height:30px; display:flex; align-items:center; justify-content:center; }
        .modal-close:hover { color:#333; }
        .modal-body-custom { padding:24px; }
        .modal-footer-custom { padding:16px 24px; border-top:1px solid var(--border); display:flex; gap:10px; justify-content:flex-end; }
        .info-row { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px; }
        .info-item label { font-size:0.75rem; color:#888; display:block; margin-bottom:4px; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; }
        .info-item p { margin:0; font-weight:600; color:#333; font-size:0.9rem; }
        .lang-table { width:100%; border-collapse:collapse; margin:15px 0; }
        .lang-table th { background:#f8f9fd; padding:10px; font-size:0.8rem; font-weight:700; color:#555; text-transform:uppercase; letter-spacing:0.05em; }
        .lang-table td { padding:10px; border-bottom:1px solid #f2f4fb; }
        .feedback-box { background:#f8f9fb; padding:16px; border-radius:8px; margin-top:10px; border-left:3px solid var(--primary); font-size:0.88rem; color:#555; line-height:1.6; }
        .section-title { font-size:0.85rem; font-weight:800; text-transform:uppercase; letter-spacing:0.08em; color:var(--primary); border-bottom:2px solid var(--border); padding-bottom:6px; margin:20px 0 12px; }
        .empty-state { text-align:center; padding:48px 20px; color:#aaa; }
        .empty-state i { font-size:2.5rem; margin-bottom:12px; color:#d0d5f0; }
        .btn-primary-custom { background:var(--primary); color:#fff; border:none; border-radius:6px; padding:8px 18px; font-weight:600; font-size:0.88rem; cursor:pointer; text-decoration:none; display:inline-block; }
        .btn-secondary-custom { background:#f1f3f9; color:#555; border:none; border-radius:6px; padding:8px 18px; font-weight:600; font-size:0.88rem; cursor:pointer; }

        /* Scope badge shown when a senior intern is logged in */
        .scope-notice { background:#eef1ff; border:1px solid #c7d2fe; border-radius:8px; padding:10px 16px; margin-bottom:18px; font-size:0.85rem; color:#3730a3; display:flex; align-items:center; gap:8px; }
    </style>
</head>
<body>
<div class="container-scroller">
    <?php include('../partials/_navbar.php'); ?>
    <div class="container-fluid page-body-wrapper">
        <?php include('../partials/_sidebar.php'); ?>
        <div class="main-panel">
            <div class="content-wrapper">

                <!-- Page Header -->
                <div class="d-flex align-items-center justify-content-between mb-4">
                    <div>
                        <h4 class="mb-1" style="font-weight:800;color:#222;">
                            <i class="fa-solid fa-clipboard-check me-2" style="color:var(--primary)"></i>
                            Technical Evaluation
                        </h4>
                        <p class="mb-0" style="color:#888;font-size:0.88rem;">
                            Logged in as <strong><?php echo $userName; ?></strong>
                            &mdash; <em><?php echo $isSeniorIntern ? 'Senior Intern' : 'Resource Allocator'; ?></em>
                        </p>
                    </div>
                    <button class="btn-excel" onclick="exportEvaluationExcel()">
                        <i class="fa-solid fa-file-excel"></i> Export Excel
                    </button>
                </div>

                <?php if ($isSeniorIntern): ?>
                                <?php endif; ?>

                <!-- Stats -->
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <div class="stat-card total">
                            <div class="stat-icon total"><i class="fa-solid fa-users"></i></div>
                            <div>
                                <div class="stat-num"><?php echo $totalCandidates; ?></div>
                                <div class="stat-label"><?php echo $isSeniorIntern ? 'My Candidates' : 'Total Candidates'; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stat-card pending">
                            <div class="stat-icon pending"><i class="fa-solid fa-hourglass-half"></i></div>
                            <div>
                                <div class="stat-num"><?php echo $pending; ?></div>
                                <div class="stat-label">Pending Evaluation</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stat-card evaluated">
                            <div class="stat-icon evaluated"><i class="fa-solid fa-clipboard-check"></i></div>
                            <div>
                                <div class="stat-num"><?php echo $evaluated; ?></div>
                                <div class="stat-label">Evaluated</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stat-card sent">
                            <div class="stat-icon sent"><i class="fa-solid fa-paper-plane"></i></div>
                            <div>
                                <div class="stat-num"><?php echo $sentToHR; ?></div>
                                <div class="stat-label">Sent to HR</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Bar -->
                <form method="GET" action="" class="filter-bar">
                    <div class="form-group">
                        <label>Search</label>
                        <input type="text" name="search" class="form-control"
                               placeholder="Name, email, mobile…"
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" class="form-control">
                            <option value="">All Status</option>
                            <option value="Pending"    <?php echo ($filterStatus === 'Pending')    ? 'selected' : ''; ?>>Pending</option>
                            <option value="Evaluated"  <?php echo ($filterStatus === 'Evaluated')  ? 'selected' : ''; ?>>Evaluated</option>
                            <option value="Sent to HR" <?php echo ($filterStatus === 'Sent to HR') ? 'selected' : ''; ?>>Sent to HR</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Mode</label>
                        <select name="mode" class="form-control">
                            <option value="">All Modes</option>
                            <option value="Online"  <?php echo ($filterMode === 'Online')  ? 'selected' : ''; ?>>Online</option>
                            <option value="Offline" <?php echo ($filterMode === 'Offline') ? 'selected' : ''; ?>>Offline</option>
                        </select>
                    </div>
                    <button type="submit" class="btn-filter">
                        <i class="fa-solid fa-magnifying-glass me-1"></i> Filter
                    </button>
                    <a href="technical_evaluation.php" class="btn-reset">
                        <i class="fa-solid fa-rotate me-1"></i> Reset
                    </a>
                </form>

                <!-- Table -->
                <div class="evaluation-table-wrap">
                    <div class="table-header">
                        <h5>
                            <i class="fa-solid fa-list me-2" style="color:var(--primary)"></i>
                            Evaluation Records
                        </h5>
                        <span style="color:#888;font-size:0.83rem;">
                            <?php echo $totalRows; ?> record<?php echo $totalRows !== 1 ? 's' : ''; ?> found
                        </span>
                    </div>

                    <div style="overflow-x:auto;padding:16px 16px 0;">
                        <table class="eval-table table table-hover" id="evalTable" style="width:100%">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Mobile</th>
                                    <th>Application Date</th>
                                    <th>Mode</th>
                                    <th>Score</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($rows) > 0): ?>
                                    <?php $sr = 1; foreach ($rows as $row): ?>
                                        <?php
                                        $mode      = htmlspecialchars($row['interviewMode'] ?? $row['interview_mode'] ?? '');
                                        $modeClass = (strtolower($mode) === 'online') ? 'badge-online' : 'badge-offline';

                                        if (!$row['evalId']) {
                                            $status = 'Pending';    $statusClass = 'badge-pending';
                                        } elseif ($row['submittedToHr'] == 0) {
                                            $status = 'Evaluated';  $statusClass = 'badge-evaluated';
                                        } else {
                                            $status = 'Sent to HR'; $statusClass = 'badge-sent';
                                        }

                                        $viewData = [
                                            'name'        => $row['applicantName'],
                                            'email'       => $row['email'],
                                            'mobile'      => $row['mobile'],
                                            'appDate'     => $row['applicationDate'],
                                            'mode'        => $mode,
                                            'score'       => $row['overallScore'],
                                            'feedback'    => $row['overallFeedback'],
                                            'submittedAt' => $row['submittedAt'],
                                            'langs'       => $row['langData']
                                        ];
                                        $viewDataJson = htmlspecialchars(json_encode($viewData), ENT_QUOTES, 'UTF-8');
                                        ?>
                                        <tr>
                                            <td><?= $sr++ ?></td>
                                            <td><strong><?= htmlspecialchars($row['applicantName']) ?></strong></td>
                                            <td style="color:#666;font-size:0.82rem;"><?= htmlspecialchars($row['email']) ?></td>
                                            <td style="font-size:0.82rem;"><?= htmlspecialchars($row['mobile']) ?></td>
                                            <td><?= $row['applicationDate'] ? date('d M Y', strtotime($row['applicationDate'])) : '—' ?></td>
                                            <td>
                                                <?php if ($mode): ?>
                                                    <span class="badge-mode <?= $modeClass ?>"><?= $mode ?></span>
                                                <?php else: ?>—<?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($row['overallScore']): ?>
                                                    <span class="score-badge"><?= $row['overallScore'] ?> / 5</span>
                                                <?php else: ?>—<?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge-status <?= $statusClass ?>"><?= $status ?></span>
                                            </td>
                                            <td>
                                                <div class="btn-group-custom">
                                                    <?php if (!$row['evalId']): ?>
                                                        <a href="evaluate_candidate.php?id=<?= $row['id'] ?>" class="btn-action evaluate">
                                                            <i class="fa-solid fa-clipboard-check me-1"></i> Evaluate
                                                        </a>
                                                    <?php elseif ($row['submittedToHr'] == 0): ?>
                                                        <a href="evaluate_candidate.php?id=<?= $row['id'] ?>" class="btn-action edit">
                                                            <i class="fa-solid fa-pen me-1"></i> Edit
                                                        </a>
                                                        <button class="btn-action send"
                                                                onclick="sendToHr(<?= $row['id'] ?>, '<?= htmlspecialchars($row['applicantName'], ENT_QUOTES) ?>')">
                                                            <i class="fa-solid fa-paper-plane me-1"></i> Send
                                                        </button>
                                                        <button class="btn-action view"
                                                                onclick='showViewModal(<?= $viewDataJson ?>)'>
                                                            <i class="fa-solid fa-eye me-1"></i> View
                                                        </button>
                                                    <?php else: ?>
                                                        <button class="btn-action disabled" disabled>
                                                            <i class="fa-solid fa-check me-1"></i> Sent
                                                        </button>
                                                        <button class="btn-action view"
                                                                onclick='showViewModal(<?= $viewDataJson ?>)'>
                                                            <i class="fa-solid fa-eye me-1"></i> View
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- Confirm / Send to HR Modal -->
<div class="modal-overlay" id="confirmModal">
    <div class="modal-box">
        <div class="modal-header-custom">
            <h4><i class="fa-solid fa-paper-plane me-2"></i> Send to HR</h4>
            <button class="modal-close" onclick="closeConfirmModal()">&times;</button>
        </div>
        <div class="modal-body-custom">
            <p style="font-size:0.9rem;color:#555;">
                Send <strong id="candidateName" style="color:var(--primary)"></strong>'s evaluation to HR?
            </p>
            <p style="color:#999;font-size:0.82rem;margin-top:8px;">
                <i class="fa-solid fa-circle-info me-1"></i>
                Once sent, you cannot edit the evaluation.
            </p>
        </div>
        <div class="modal-footer-custom">
            <button class="btn-secondary-custom" onclick="closeConfirmModal()">Cancel</button>
            <a href="#" id="sendLink" class="btn-primary-custom">
                <i class="fa-solid fa-paper-plane me-1"></i> Send to HR
            </a>
        </div>
    </div>
</div>

<!-- View Modal -->
<div class="modal-overlay" id="viewModal">
    <div class="modal-box view-modal-box">
        <div class="modal-header-custom">
            <h4><i class="fa-solid fa-eye me-2"></i> Evaluation Details</h4>
            <button class="modal-close" onclick="closeViewModal()">&times;</button>
        </div>
        <div class="modal-body-custom">
            <div class="info-row">
                <div class="info-item"><label>Candidate Name</label><p id="vName"></p></div>
                <div class="info-item"><label>Email</label><p id="vEmail"></p></div>
                <div class="info-item"><label>Mobile</label><p id="vMobile"></p></div>
                <div class="info-item"><label>Application Date</label><p id="vAppDate"></p></div>
                <div class="info-item"><label>Interview Mode</label><p id="vMode"></p></div>
                <div class="info-item"><label>Submitted At</label><p id="vSubmittedAt"></p></div>
                <div class="info-item" style="grid-column:span 2;"><label>Overall Score</label><p id="vScore"></p></div>
            </div>
            <div class="section-title"><i class="fa-solid fa-code me-2"></i> Language Ratings</div>
            <table class="lang-table">
                <thead>
                    <tr><th>Language</th><th>Conceptual</th><th>Practical</th><th>Average</th></tr>
                </thead>
                <tbody id="vLangBody"></tbody>
            </table>
            <div class="section-title"><i class="fa-solid fa-comment-dots me-2"></i> Overall Feedback</div>
            <div class="feedback-box" id="vFeedback"></div>
        </div>
        <div class="modal-footer-custom">
            <button class="btn-secondary-custom" onclick="closeViewModal()">Close</button>
        </div>
    </div>
</div>

<!-- Data for Excel export -->
<script>
const allCandidatesData = <?php echo json_encode($allCandidates); ?>;
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
    $('#evalTable').DataTable({
        pageLength: 10,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
        order: [],
        columnDefs: [{ orderable: false, targets: [-1] }],
        responsive: { details: false },
        language: {
            search: '',
            searchPlaceholder: 'Search records...',
            lengthMenu: 'Show _MENU_ entries',
            info: 'Showing _START_ to _END_ of _TOTAL_ records',
            infoEmpty: 'No records available',
            infoFiltered: '(filtered from _MAX_ total)',
            paginate: { first:'«', last:'»', next:'›', previous:'‹' },
            emptyTable: `<div class="empty-state">
                <i class="fa-solid fa-inbox d-block"></i>
                <?php if ($isSeniorIntern): ?>
                    No candidates have been assigned to you yet.
                <?php else: ?>
                    No evaluation records found.
                    <?php if ($totalCandidates > 0): ?>
                        <br><small>There are <?php echo $totalCandidates; ?> total candidates but none match your current filters.</small>
                    <?php endif; ?>
                <?php endif; ?>
            </div>`
        },
        dom: '<"dt-controls-row"lf>rt<"dt-controls-row"ip>',
        initComplete: function () {
            $('.dataTables_filter input').addClass('form-control').css({
                'border':'1.5px solid #e0e4ef','border-radius':'6px',
                'font-size':'0.87rem','padding':'7px 11px'
            });
            $('.dataTables_length select').addClass('form-control').css({
                'border':'1.5px solid #e0e4ef','border-radius':'6px','font-size':'0.87rem'
            });
        }
    });
});

function sendToHr(id, name) {
    document.getElementById('candidateName').textContent = name;
    document.getElementById('sendLink').href = 'submitToHr.php?candidateId=' + id;
    document.getElementById('confirmModal').classList.add('show');
}
function closeConfirmModal() { document.getElementById('confirmModal').classList.remove('show'); }

function showViewModal(data) {
    document.getElementById('vName').textContent        = data.name        || '—';
    document.getElementById('vEmail').textContent       = data.email       || '—';
    document.getElementById('vMobile').textContent      = data.mobile      || '—';
    document.getElementById('vAppDate').textContent     = data.appDate     ? formatDate(data.appDate)     : '—';
    document.getElementById('vSubmittedAt').textContent = data.submittedAt ? formatDate(data.submittedAt) : '—';
    document.getElementById('vFeedback').textContent    = data.feedback    || 'No feedback provided';

    document.getElementById('vScore').innerHTML = data.score
        ? '<span class="score-badge">' + data.score + ' / 5</span>' : '—';

    if (data.mode) {
        var mc = data.mode.toLowerCase() === 'online' ? 'badge-online' : 'badge-offline';
        document.getElementById('vMode').innerHTML = '<span class="badge-mode ' + mc + '">' + data.mode + '</span>';
    } else {
        document.getElementById('vMode').textContent = '—';
    }

    var tbody = document.getElementById('vLangBody');
    tbody.innerHTML = '';
    if (data.langs && data.langs.length > 0) {
        data.langs.forEach(function(l) {
            var avg = ((parseFloat(l.conceptualScore) + parseFloat(l.practicalScore)) / 2).toFixed(1);
            var tr  = document.createElement('tr');
            tr.innerHTML =
                '<td><strong>' + l.languageName + '</strong></td>' +
                '<td><span class="score-badge">' + l.conceptualScore + ' / 5</span></td>' +
                '<td><span class="score-badge">' + l.practicalScore  + ' / 5</span></td>' +
                '<td><span class="score-badge">' + avg               + ' / 5</span></td>';
            tbody.appendChild(tr);
        });
    } else {
        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:#999;">No language ratings recorded</td></tr>';
    }

    document.getElementById('viewModal').classList.add('show');
}
function closeViewModal() { document.getElementById('viewModal').classList.remove('show'); }

document.getElementById('confirmModal').addEventListener('click', function(e) { if (e.target===this) this.classList.remove('show'); });
document.getElementById('viewModal').addEventListener('click',   function(e) { if (e.target===this) this.classList.remove('show'); });

function formatDate(dateStr) {
    if (!dateStr) return '—';
    var d = new Date(dateStr);
    var m = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return d.getDate() + ' ' + m[d.getMonth()] + ' ' + d.getFullYear();
}

async function exportEvaluationExcel() {
    if (!allCandidatesData || allCandidatesData.length === 0) {
        alert('No records to export.'); return;
    }

    const workbook  = new ExcelJS.Workbook();
    const worksheet = workbook.addWorksheet('Technical Evaluations');

    const currentDate = new Date();
    const toDate  = currentDate.toLocaleDateString('en-GB').split('/').join('-');
    const title   = `Technical Evaluation Report — Up to ${toDate}`;

    worksheet.mergeCells('A1:L1');
    const titleCell = worksheet.getCell('A1');
    titleCell.value = title;
    titleCell.font  = { bold:true, size:16 };
    titleCell.alignment = { horizontal:'center', vertical:'middle' };
    titleCell.fill  = { type:'pattern', pattern:'solid', fgColor:{ argb:'FFD3D3D3' } };
    worksheet.getRow(1).height = 25;

    const headers  = ['#','Name','Email','Mobile','Application Date','Mode','Overall Score','Overall Feedback','Status','Submitted At','Languages Evaluated','Language Details'];
    const headerRow = worksheet.addRow(headers);
    headerRow.eachCell(cell => {
        cell.font      = { bold:true };
        cell.alignment = { horizontal:'center', vertical:'middle', wrapText:true };
        cell.fill      = { type:'pattern', pattern:'solid', fgColor:{ argb:'FFF2F2F2' } };
        cell.border    = { top:{style:'thin'}, bottom:{style:'thin'}, left:{style:'thin'}, right:{style:'thin'} };
    });
    worksheet.getRow(2).height = 30;

    allCandidatesData.forEach((c, idx) => {
        let status = 'Pending';
        if (c.evalId) status = c.submittedToHr == 1 ? 'Sent to HR' : 'Evaluated';

        let langNames = '—', langDetails = '—';
        // Update the exportEvaluationExcel function's language details generation:
if (c.languages && c.languages.length > 0) {
    langNames = c.languages.map(l => l.languageName).join(', ');
    langDetails = c.languages.map(l => {
        let avg = ((parseFloat(l.conceptualScore) + parseFloat(l.practicalScore)) / 2).toFixed(1);
        let feedback = l.languageFeedback ? ` - Feedback: ${l.languageFeedback}` : '';
        return `${l.languageName}: C${l.conceptualScore}/P${l.practicalScore} (Avg:${avg})${feedback}`;
    }).join(' | ');
}

        const row = worksheet.addRow([
            idx + 1,
            c.applicantName || '',
            c.email         || '',
            c.mobile        || '',
            c.applicationDate || '',
            c.interviewMode || c.interview_mode || '—',
            c.overallScore ? `${c.overallScore} / 5` : '—',
            c.overallFeedback || '—',
            status,
            c.submittedAt || '—',
            langNames,
            langDetails
        ]);

        row.eachCell((cell, colNumber) => {
            cell.alignment = {
                horizontal: colNumber === 1 ? 'center' : 'left',
                vertical:   'middle',
                wrapText:   colNumber === 8 || colNumber === 12
            };
            cell.border = { top:{style:'thin'}, bottom:{style:'thin'}, left:{style:'thin'}, right:{style:'thin'} };
        });
    });

    worksheet.columns = [
        {width:5},{width:25},{width:30},{width:15},
        {width:15},{width:12},{width:15},{width:40},
        {width:15},{width:18},{width:30},{width:50}
    ];

    const buffer = await workbook.xlsx.writeBuffer();
    const blob   = new Blob([buffer], { type:'application/octet-stream' });
    const link   = document.createElement('a');
    link.href     = URL.createObjectURL(blob);
    link.download = `Technical_Evaluation_${new Date().toISOString().split('T')[0]}.xlsx`;
    link.click();
}
</script>

<?php if (isset($_SESSION['swal_icon'])): ?>
<script>
Swal.fire({
    icon: '<?= $_SESSION['swal_icon'] ?>',
    title: '<?= $_SESSION['swal_title'] ?>',
    text: '<?= $_SESSION['swal_message'] ?>',
    confirmButtonColor: '#3085d6'
});
</script>
<?php
unset($_SESSION['swal_icon']);
unset($_SESSION['swal_title']);
unset($_SESSION['swal_message']);
endif;
?>
</body>
</html>