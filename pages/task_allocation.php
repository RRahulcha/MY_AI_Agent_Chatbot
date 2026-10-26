<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

$role  = strtolower(str_replace([' ', '_'], '', $_SESSION['userRole'] ?? ''));
$desig = strtolower(str_replace([' ', '_'], '', $_SESSION['designation'] ?? ''));

$allowed_roles = [
    'admin',
    'hr',
    'resourceallocator',
    'resourcehead',
    'resourcemanager',
    'teamlead',
    'departmenthead',
    'manager',
    'seniorintern',
    'senior_intern',
    'developer',
    'md'
];

if (!in_array($role, $allowed_roles) && !in_array($desig, $allowed_roles)) {
    echo '<script>alert("Unauthorized. Only Seniors/HR/RA can allocate tasks."); window.location.href = "login.php";</script>';
    exit;
}

include('../include/db_config.php');
$myUserId = $_SESSION['userId'];

// Fetch user details for personal greeting
$userRes = mysqli_query($con, "SELECT fullName FROM signup WHERE userId = '" . mysqli_real_escape_string($con, $myUserId) . "' LIMIT 1");
$userRow = mysqli_fetch_assoc($userRes);
$userFullName = $userRow['fullName'] ?? 'User';

// ── Analytics Computation ────────────────────────────────────────────────────
$allocatedQuery = mysqli_query($con, "
    SELECT t.*, COALESCE(s.fullName, 'Unknown') as fullName 
    FROM task_allocation t 
    LEFT JOIN signup s ON t.receiver_id COLLATE utf8mb4_unicode_ci = s.userId COLLATE utf8mb4_unicode_ci 
    WHERE t.allocator_id = '" . mysqli_real_escape_string($con, $myUserId) . "' 
    ORDER BY t.created_at DESC
");

$allocatedTasks  = [];
$totalAllocated  = 0;
$completedCount  = 0;
$inProgressCount = 0;
$inReviewCount   = 0;
$pendingCount    = 0;
$overdueCount    = 0;
$extReqCount     = 0;

if ($allocatedQuery) {
    while ($r = mysqli_fetch_assoc($allocatedQuery)) {
        $allocatedTasks[] = $r;
        $totalAllocated++;
        $status = trim($r['status']);
        $isOverdue = (strtotime($r['deadline']) < time() && $status !== 'Done');

        if ($status === 'Done') {
            $completedCount++;
        } elseif ($status === 'In Progress') {
            $inProgressCount++;
        } elseif ($status === 'In Review') {
            $inReviewCount++;
        } else {
            $pendingCount++;
        }

        if ($isOverdue) {
            $overdueCount++;
        }

        if ($r['ext_status'] === 'Requested') {
            $extReqCount++;
        }
    }
}

$completionPct = $totalAllocated > 0 ? round(($completedCount / $totalAllocated) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <title>Allocate Task</title>
    <?php include('../partials/_links.php'); ?>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        body { background-color: #f4f6fb !important; }

        /* ── Header Box ────────────────────────────────────────── */
        .page-header-box {
            background: #fff;
            border-radius: 12px;
            padding: 20px 24px;
            border: 1px solid #e2e8f0;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
        }

        /* ── KPI Cards ──────────────────────────────────────────── */
        .kpi-card {
            background: #fff;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            padding: 16px 20px;
            transition: all 0.2s ease;
            height: 100%;
            box-shadow: 0 2px 6px rgba(0,0,0,0.03);
        }
        .kpi-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 18px rgba(0,0,0,0.07);
        }
        .kpi-icon {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0;
        }
        .kpi-val {
            font-size: 1.6rem;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.1;
            margin-top: 4px;
        }
        .kpi-lbl {
            font-size: 0.72rem;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .kpi-sub {
            font-size: 0.72rem;
            color: #94a3b8;
            margin-top: 3px;
        }

        /* ── Select2 Customization ────────────────────────────── */
        .select2-container .select2-selection--single {
            height: 42px !important;
            border: 1px solid #cbd5e1 !important;
            border-radius: 8px !important;
            padding: 0 12px !important;
            display: flex !important;
            align-items: center !important;
            background: #fff !important;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 40px !important;
            color: #334155 !important;
            font-size: 0.85rem !important;
            font-weight: 500 !important;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 40px !important;
        }
        .select2-dropdown {
            border: 1px solid #cbd5e1 !important;
            border-radius: 8px !important;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1) !important;
            font-size: 0.85rem !important;
        }
        select.select2[required] {
            display: block !important;
            position: absolute !important;
            clip: rect(0, 0, 0, 0) !important;
            top: 50% !important;
            left: 50% !important;
            width: 1px !important;
            height: 1px !important;
            opacity: 0 !important;
            z-index: -1 !important;
        }

        /* ── Form Card ────────────────────────────────────────── */
        .form-card {
            background: #fff;
            border-radius: 14px;
            border: 2px solid #4b49ac;
            padding: 24px;
            box-shadow: 0 4px 14px rgba(75, 73, 172, 0.08);
            margin-bottom: 24px;
        }
        .form-control {
            height: 42px !important;
            border-radius: 8px !important;
            border: 1px solid #cbd5e1 !important;
            font-size: 0.85rem !important;
            padding: 8px 14px !important;
        }
        .form-control:focus {
            border-color: #4b49ac !important;
            box-shadow: 0 0 0 3px rgba(75, 73, 172, 0.15) !important;
        }

        /* ── Badges ────────────────────────────────────────────── */
        .badge-status {
            font-size: 0.73rem;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .badge-done        { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .badge-in-progress { background: #dbeafe; color: #1d4ed8; border: 1px solid #bfdbfe; }
        .badge-in-review   { background: #f3e8ff; color: #7e22ce; border: 1px solid #e9d5ff; }
        .badge-pending     { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }

        .badge-overdue-tag {
            background: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fecaca;
            font-size: 0.68rem;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 12px;
            margin-top: 3px;
            display: inline-block;
        }

        /* ── Table Card ────────────────────────────────────────── */
        .card-table {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #fff;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
        }
        .table thead th {
            background-color: #f8fafc !important;
            color: #475569 !important;
            font-size: 0.74rem !important;
            font-weight: 700 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.04em !important;
            padding: 12px 14px !important;
            border-bottom: 2px solid #e2e8f0 !important;
        }
        .table tbody td {
            vertical-align: middle !important;
            font-size: 0.84rem !important;
            padding: 12px 14px !important;
            border-bottom: 1px solid #f1f5f9 !important;
            color: #334155;
        }
        .table tbody tr:hover { background-color: #f8fafc !important; }

        .btn-act {
            padding: 4px 8px;
            font-size: 0.75rem;
            border-radius: 6px;
            font-weight: 600;
        }
        
        .flatpickr-date { background-color: #fff !important; cursor: pointer; }
    </style>
</head>

<body>
    <div class="container-scroller">
        <?php include('../partials/_navbar.php'); ?>
        <div class="container-fluid page-body-wrapper">
            <?php include('../partials/_sidebar.php'); ?>

            <div class="main-panel">
                <div class="content-wrapper" style="background:#f4f6fb !important;">

                    <!-- ── Header Banner ────────────────────────────────────────────────── -->
                    <div class="page-header-box d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <div>
                            <h3 class="fw-bold text-dark mb-1" style="font-size:1.35rem;">
                                <i class="fa-solid fa-user-pen text-primary me-2"></i> Task Allocation
                            </h3>
                            <p class="text-muted small mb-0">Assign tasks to interns and team members, set deadlines, and track status.</p>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge" style="background:#eef2ff; color:#4b49ac; font-size:0.8rem; padding:7px 16px; border-radius:20px; font-weight:600;">
                                <i class="fa-solid fa-user-shield me-1"></i> <?= htmlspecialchars($userFullName) ?>
                            </span>
                            <button type="button" class="btn btn-primary btn-sm" id="toggleFormBtn" onclick="toggleForm()" style="background:#4b49ac; border-color:#4b49ac; border-radius:8px; padding:7px 16px; font-weight:600;">
                                <i class="fa-solid fa-plus me-1" id="toggleFormIcon"></i> <span id="toggleFormText">Allocate New Task</span>
                            </button>
                        </div>
                    </div>

                    <!-- ── Flash Alert ──────────────────────────────────────────────────── -->
                    <?php if (isset($_SESSION['msg'])): ?>
                        <div class="alert alert-<?= $_SESSION['status'] ? 'success' : 'danger'; ?> alert-dismissible fade show mb-4" role="alert" style="border-radius:10px;">
                            <i class="fa-solid <?= $_SESSION['status'] ? 'fa-circle-check' : 'fa-circle-exclamation'; ?> me-2"></i>
                            <?= $_SESSION['msg']; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                        <?php unset($_SESSION['msg'], $_SESSION['status']); ?>
                    <?php endif; ?>

                    <!-- ── KPI Analytics Summary ────────────────────────────────────────── -->
                    <div class="row mb-4 g-3">
                        <div class="col-md-2 col-sm-4 col-6">
                            <div class="kpi-card">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div class="kpi-lbl">Allocated</div>
                                    <div class="kpi-icon" style="background:#EEF2FF; color:#4B49AC;">
                                        <i class="fa-solid fa-paper-plane"></i>
                                    </div>
                                </div>
                                <div class="kpi-val"><?= $totalAllocated ?></div>
                                <div class="kpi-sub">Total assigned</div>
                            </div>
                        </div>

                        <div class="col-md-2 col-sm-4 col-6">
                            <div class="kpi-card">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div class="kpi-lbl">Completed</div>
                                    <div class="kpi-icon" style="background:#E6F4EA; color:#1E8E3E;">
                                        <i class="fa-solid fa-circle-check"></i>
                                    </div>
                                </div>
                                <div class="kpi-val text-success"><?= $completedCount ?></div>
                                <div class="kpi-sub"><?= $completionPct ?>% completed</div>
                            </div>
                        </div>

                        <div class="col-md-2 col-sm-4 col-6">
                            <div class="kpi-card">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div class="kpi-lbl">In Progress</div>
                                    <div class="kpi-icon" style="background:#E0F2FE; color:#0284C7;">
                                        <i class="fa-solid fa-spinner"></i>
                                    </div>
                                </div>
                                <div class="kpi-val" style="color:#0284C7;"><?= $inProgressCount ?></div>
                                <div class="kpi-sub">Active working</div>
                            </div>
                        </div>

                        <div class="col-md-2 col-sm-4 col-6">
                            <div class="kpi-card">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div class="kpi-lbl">In Review</div>
                                    <div class="kpi-icon" style="background:#F3E8FF; color:#7E22CE;">
                                        <i class="fa-solid fa-magnifying-glass"></i>
                                    </div>
                                </div>
                                <div class="kpi-val" style="color:#7E22CE;"><?= $inReviewCount ?></div>
                                <div class="kpi-sub">Needs verification</div>
                            </div>
                        </div>

                        <div class="col-md-2 col-sm-4 col-6">
                            <div class="kpi-card">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div class="kpi-lbl">Ext. Requests</div>
                                    <div class="kpi-icon" style="background:#FEF3C7; color:#D97706;">
                                        <i class="fa-solid fa-clock-rotate-left"></i>
                                    </div>
                                </div>
                                <div class="kpi-val" style="color:#D97706;"><?= $extReqCount ?></div>
                                <div class="kpi-sub">Extension asked</div>
                            </div>
                        </div>

                        <div class="col-md-2 col-sm-4 col-6">
                            <div class="kpi-card">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div class="kpi-lbl">Overdue</div>
                                    <div class="kpi-icon" style="background:#FEE2E2; color:#DC2626;">
                                        <i class="fa-solid fa-triangle-exclamation"></i>
                                    </div>
                                </div>
                                <div class="kpi-val text-danger"><?= $overdueCount ?></div>
                                <div class="kpi-sub">Passed deadline</div>
                            </div>
                        </div>
                    </div>

                    <!-- ── Task Allocation Form (Collapsible) ───────────────────────────── -->
                    <div id="allocationFormBox" class="form-card" style="display:none;">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h4 class="fw-bold text-primary mb-0" style="font-size:1.15rem;">
                                <i class="fa-solid fa-pen-to-square me-2"></i> New Task Assignment
                            </h4>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleForm()">
                                <i class="fa-solid fa-chevron-up me-1"></i> Close
                            </button>
                        </div>
                        <p class="text-muted small mb-4">Select an active team member and assign specific tasks with deadlines.</p>

                        <form action="process_task_allocation.php" method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="allocate">

                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Select Intern / Employee <span class="text-danger">*</span></label>
                                    <select class="form-control select2" name="receiver_id" required>
                                        <option value="">-- Select Active Intern --</option>
                                        <?php
                                        $q = mysqli_query($con, "SELECT userId, fullName, designation FROM signup WHERE status = 'Active' AND designation NOT IN ('admin', 'developer', 'md') ORDER BY fullName ASC");
                                        while ($r = mysqli_fetch_assoc($q)) {
                                            echo "<option value='" . htmlspecialchars($r['userId']) . "'>" . htmlspecialchars($r['fullName']) . " [" . htmlspecialchars($r['designation']) . "]</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Task Type <span class="text-danger">*</span></label>
                                    <select class="form-control select2" name="task_type" id="taskType" required>
                                        <option value="Project work">Project work</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-md-6" id="projectBox">
                                    <label class="form-label fw-bold small">Select Project <span class="text-danger">*</span></label>
                                    <select class="form-control select2" name="project_name" id="projectSelect">
                                        <option value="">-- Select Project --</option>
                                        <?php
                                        $pq = mysqli_query($con, "SELECT projectName, client FROM projectMaster WHERE status = 1 ORDER BY projectName ASC");
                                        if ($pq) {
                                            while ($pr = mysqli_fetch_assoc($pq)) {
                                                $pName = $pr['projectName'];
                                                $pClient = trim($pr['client'] ?? '');
                                                $pLabel = (!empty($pClient) && strtoupper($pClient) !== 'NA') ? ($pName . ' - ' . $pClient) : $pName;
                                                echo "<option value='" . htmlspecialchars($pName, ENT_QUOTES) . "' data-client='" . htmlspecialchars($pClient, ENT_QUOTES) . "'>" . htmlspecialchars($pLabel) . "</option>";
                                            }
                                        }
                                        ?>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Client Name</label>
                                    <input type="text" class="form-control" id="clientName" name="client_name" readonly placeholder="Auto-filled from project">
                                </div>
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Task Title / Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="task_title" placeholder="e.g. Implement User Authentication Module" required>
                                </div>

                                <div class="col-md-3">
                                    <label class="form-label fw-bold small">Deadline Date <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control flatpickr-date" name="deadline_date" placeholder="Select Date" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-bold small">Deadline Time <span class="text-danger">*</span></label>
                                    <input type="time" class="form-control" name="deadline_time" value="18:00" required>
                                </div>
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Initial Status <span class="text-danger">*</span></label>
                                    <select class="form-control select2" name="status" required>
                                        <option value="To Do" selected>To Do</option>
                                        <option value="In Progress">In Progress</option>
                                        <option value="Backlog">Backlog</option>
                                        <option value="Done">Done</option>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Attachment Document <span class="text-muted">(Optional)</span></label>
                                    <input type="file" name="task_doc" class="form-control" accept=".pdf,.doc,.docx,.jpg,.png">
                                </div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-bold small">Remark / Task Instructions <span class="text-danger">*</span></label>
                                <textarea class="form-control" name="remark" rows="3" placeholder="Provide detailed task description, expectations, and instructions..." required></textarea>
                            </div>

                            <div class="d-flex justify-content-end gap-2">
                                <button type="button" class="btn btn-light" onclick="toggleForm()">Cancel</button>
                                <button type="submit" class="btn btn-primary px-4" style="background:#4b49ac; border-color:#4b49ac;">
                                    <i class="fa-solid fa-paper-plane me-1"></i> Allocate Task
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- ── History Table (Allocated Tasks) ──────────────────────────────── -->
                    <div class="card-table">
                        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                            <div>
                                <h4 class="fw-bold mb-0 text-dark" style="font-size:1.15rem;">
                                    <i class="fa-solid fa-list-check me-2 text-primary"></i> Tasks Allocated by You
                                </h4>
                                <small class="text-muted">Review progress, review extension requests, and send reminders.</small>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover w-100" id="allocatorTable">
                                <thead>
                                    <tr>
                                        <th style="width:16%">Assigned To</th>
                                        <th style="width:24%">Task Title</th>
                                        <th style="width:15%">Project</th>
                                        <th style="width:16%">Deadline</th>
                                        <th style="width:10%">Status</th>
                                        <th style="width:11%">Ext. Request</th>
                                        <th style="width:8%" class="text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($allocatedTasks as $tr): 
                                        $status = trim($tr['status']);
                                        $isOverdue = (strtotime($tr['deadline']) < time() && $status !== 'Done');

                                        $badgeClass = 'badge-pending';
                                        if ($status === 'Done')        $badgeClass = 'badge-done';
                                        if ($status === 'In Progress') $badgeClass = 'badge-in-progress';
                                        if ($status === 'In Review')   $badgeClass = 'badge-in-review';
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold text-dark"><?= htmlspecialchars($tr['fullName']) ?></div>
                                        </td>
                                        <td>
                                            <span class="fw-bold text-dark"><?= htmlspecialchars($tr['task_title']) ?></span>
                                            <?php if (!empty($tr['document_path'])): ?>
                                                <a href="../uploads/<?= htmlspecialchars($tr['document_path']) ?>" target="_blank" class="ms-1 text-primary small" title="View Document">
                                                    <i class="fa-solid fa-paperclip"></i>
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($tr['task_type'] === 'Project work'): ?>
                                                <span class="badge bg-light text-dark border"><?= htmlspecialchars($tr['project_name']) ?></span>
                                            <?php else: ?>
                                                <span class="text-muted fst-italic">Other / Internal</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="<?= $isOverdue ? 'text-danger fw-bold' : '' ?>">
                                                <i class="fa-regular fa-clock me-1"></i>
                                                <?= date('d M Y, h:i A', strtotime($tr['deadline'])) ?>
                                            </div>
                                            <?php if ($isOverdue): ?>
                                                <span class="badge-overdue-tag">
                                                    <i class="fa-solid fa-triangle-exclamation me-1"></i>Overdue
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge-status <?= $badgeClass ?>">
                                                <?= htmlspecialchars($status) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($tr['ext_status'] === 'Requested'): ?>
                                                <span class="badge bg-danger text-white mb-1"><i class="fa-solid fa-circle-exclamation me-1"></i>Requested</span>
                                                <div class="text-muted small" style="font-size:0.7rem; line-height:1.2;">
                                                    <strong>Reason:</strong> <?= htmlspecialchars($tr['ext_reason']) ?>
                                                </div>
                                            <?php elseif ($tr['ext_status'] === 'Extended'): ?>
                                                <span class="badge bg-success text-white"><i class="fa-solid fa-check me-1"></i>Extended</span>
                                            <?php else: ?>
                                                <span class="text-muted small">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <div class="d-flex justify-content-center gap-1">
                                                <?php if ($status !== 'Done'): ?>
                                                    <form action="process_task_allocation.php" method="POST" class="d-inline">
                                                        <input type="hidden" name="action" value="mark_done">
                                                        <input type="hidden" name="task_id" value="<?= $tr['id'] ?>">
                                                        <button type="submit" class="btn btn-success btn-act" title="Mark Complete">
                                                            <i class="fa-solid fa-check"></i>
                                                        </button>
                                                    </form>

                                                    <button type="button" class="btn btn-info btn-act text-white" data-bs-toggle="modal" data-bs-target="#extendModal<?= $tr['id'] ?>" title="Extend Deadline">
                                                        <i class="fa-solid fa-clock-rotate-left"></i>
                                                    </button>
                                                <?php endif; ?>

                                                <form action="process_task_allocation.php" method="POST" class="d-inline" onsubmit="return confirm('Delete this task allocation?');">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="task_id" value="<?= $tr['id'] ?>">
                                                    <button type="submit" class="btn btn-danger btn-act" title="Delete Task">
                                                        <i class="fa-solid fa-trash"></i>
                                                    </button>
                                                </form>

                                                <?php if ($isOverdue): ?>
                                                    <form action="process_task_allocation.php" method="POST" class="d-inline">
                                                        <input type="hidden" name="action" value="followup">
                                                        <input type="hidden" name="receiver_id" value="<?= $tr['receiver_id'] ?>">
                                                        <input type="hidden" name="task_title" value="<?= htmlspecialchars($tr['task_title']) ?>">
                                                        <button type="submit" class="btn btn-warning btn-act text-dark" title="Send Follow-up Reminder">
                                                            <i class="fa-solid fa-bell"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>

                                    <!-- ── Extend Deadline Modal ────────────────────────────────────── -->
                                    <div class="modal fade" id="extendModal<?= $tr['id'] ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog modal-dialog-centered">
                                            <div class="modal-content border-0 shadow-lg" style="border-radius:14px;">
                                                <form action="process_task_allocation.php" method="POST">
                                                    <input type="hidden" name="action" value="extend_deadline">
                                                    <input type="hidden" name="task_id" value="<?= $tr['id'] ?>">
                                                    <input type="hidden" name="receiver_id" value="<?= $tr['receiver_id'] ?>">
                                                    
                                                    <div class="modal-header bg-light">
                                                        <h5 class="modal-title fw-bold text-primary" style="font-size:1rem;">
                                                            <i class="fa-solid fa-clock-rotate-left me-2"></i> Extend Task Deadline
                                                        </h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                    </div>
                                                    <div class="modal-body p-4">
                                                        <div class="mb-3">
                                                            <label class="form-label text-muted small fw-bold mb-1">Task</label>
                                                            <div class="fw-bold text-dark"><?= htmlspecialchars($tr['task_title']) ?></div>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label text-muted small fw-bold mb-1">Assigned To</label>
                                                            <div class="text-dark fw-bold"><?= htmlspecialchars($tr['fullName']) ?></div>
                                                        </div>
                                                        <div class="row g-3">
                                                            <div class="col-6">
                                                                <label class="form-label fw-bold small">New Date <span class="text-danger">*</span></label>
                                                                <input type="text" class="form-control flatpickr-date" name="new_deadline_date" required>
                                                            </div>
                                                            <div class="col-6">
                                                                <label class="form-label fw-bold small">New Time <span class="text-danger">*</span></label>
                                                                <input type="time" class="form-control" name="new_deadline_time" value="18:00" required>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer bg-light">
                                                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" class="btn btn-primary btn-sm px-3" style="background:#4b49ac; border-color:#4b49ac;">Update Deadline</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>
                <?php include("../partials/_footer.php"); ?>
            </div>
        </div>
    </div>

    <script src="../vendors/js/vendor.bundle.base.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>

    <script>
        $(document).ready(function () {
            $('.select2').select2({ width: '100%' });
            flatpickr(".flatpickr-date", { dateFormat: "Y-m-d", minDate: "today" });
            $('#allocatorTable').DataTable({ 
                "order": [[3, "asc"]], 
                "pageLength": 10,
                "language": {
                    "search": "Search allocated tasks:",
                    "lengthMenu": "Show _MENU_ tasks"
                }
            });

            $('#projectSelect').on('change', function () {
                var client = $(this).find(':selected').data('client');
                $('#clientName').val(client || '');
            });

            $('#taskType').on('change', function () {
                toggleProject();
            });
        });

        function toggleForm() {
            const box = document.getElementById('allocationFormBox');
            const btnText = document.getElementById('toggleFormText');
            const btnIcon = document.getElementById('toggleFormIcon');

            if (box.style.display === 'none' || box.style.display === '') {
                box.style.display = 'block';
                btnText.textContent = 'Hide Form';
                btnIcon.className = 'fa-solid fa-chevron-up me-1';
            } else {
                box.style.display = 'none';
                btnText.textContent = 'Allocate New Task';
                btnIcon.className = 'fa-solid fa-plus me-1';
            }
        }

        function toggleProject() {
            var type = document.getElementById('taskType').value;
            var box = document.getElementById('projectBox');
            var sel = document.getElementById('projectSelect');
            var clientName = document.getElementById('clientName');

            if (type === 'Project work') {
                box.style.display = 'block';
                sel.setAttribute('required', 'required');
                clientName.setAttribute('readonly', 'readonly');
                var client = $('#projectSelect').find(':selected').data('client');
                if (client !== undefined) {
                    clientName.value = client;
                }
            } else {
                box.style.display = 'none';
                sel.removeAttribute('required');
                clientName.value = '';
            }
        }
    </script>
</body>

</html>