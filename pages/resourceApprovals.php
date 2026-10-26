<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

include('../include/db_config.php');
require_once('availabilityEngine.php');

$userId = $_SESSION['userId'];
$safeUserId = mysqli_real_escape_string($con, $userId);

$userRow   = mysqli_fetch_assoc(mysqli_query($con, "SELECT fullName, userRole, designation FROM signup WHERE userId='$safeUserId'"));
$userName  = $userRow ? htmlspecialchars($userRow['fullName']) : 'Unknown';
$userRole  = strtolower(trim($userRow['userRole'] ?? $_SESSION['userRole'] ?? ''));
$userDesig = strtolower(trim($userRow['designation'] ?? $_SESSION['designation'] ?? ''));

$allowedApproveRoles = ['resourceallocator', 'resource_allocator', 'resource allocator', 'resourcemanager', 'resource_manager', 'resourcehead', 'resource_head', 'admin', 'superadmin', 'hr'];
$canApprove = in_array($userRole, $allowedApproveRoles) || in_array($userDesig, $allowedApproveRoles);

// ── Pending Requests Query (Grouped by Intern) ──────────────────────────────
$pendingQuery = "
    SELECT
        r.id,
        r.internId,
        COALESCE(i.fullName, s_int.fullName, r.internId) AS internName,
        COALESCE(i.role, s_int.designation, 'Intern') AS internRole,
        COALESCE(TIME_FORMAT(s_int.expectedPunchIn, '%H:%i'), '09:00') AS workStartTime,
        COALESCE(TIME_FORMAT(s_int.expectedPunchOut, '%H:%i'), '17:00') AS workEndTime,
        r.projectName,
        r.clientName,
        r.requestedBy,
        COALESCE(s_req.fullName, r.requestedBy) AS requestedByName,
        r.startDate,
        r.endDate,
        r.dailyHours,
        r.allocStartTime,
        r.allocEndTime,
        r.createdAt
    FROM resourceRequests r
    LEFT JOIN interns i 
        ON r.internId COLLATE utf8mb4_unicode_ci = i.internId COLLATE utf8mb4_unicode_ci
    LEFT JOIN signup s_int 
        ON r.internId COLLATE utf8mb4_unicode_ci = s_int.internId COLLATE utf8mb4_unicode_ci
    LEFT JOIN signup s_req 
        ON r.requestedBy COLLATE utf8mb4_unicode_ci = s_req.userId COLLATE utf8mb4_unicode_ci
    WHERE r.status = 'Pending'
    ORDER BY r.internId ASC, r.createdAt DESC
";
$pendingResult = mysqli_query($con, $pendingQuery);
if ($pendingResult === false) die("Pending Query failed: " . mysqli_error($con));
$totalPending = mysqli_num_rows($pendingResult);

// Group pending requests by Intern
$groupedPending = [];
while ($pRow = mysqli_fetch_assoc($pendingResult)) {
    $iid = $pRow['internId'];

    // Pre-validate initial request parameters against leaves, holidays, tours, allocations
    $pSDate = $pRow['startDate'];
    $pEDate = $pRow['endDate'];
    $pSTime = !empty($pRow['allocStartTime']) ? $pRow['allocStartTime'] : '09:00:00';
    $pETime = !empty($pRow['allocEndTime'])   ? $pRow['allocEndTime']   : '17:00:00';

    $preVal = validateAllocationRequest($con, $iid, $pSDate, $pEDate, $pSTime, $pETime, null, false);
    $pRow['hasConflict'] = !$preVal['valid'];
    $pRow['conflictMsg'] = !$preVal['valid'] ? implode(' | ', $preVal['errors']) : '';
    $pRow['warningMsg']  = !empty($preVal['warnings']) ? implode(' | ', $preVal['warnings']) : '';

    if (!isset($groupedPending[$iid])) {
        $groupedPending[$iid] = [
            'internId'           => $iid,
            'internName'         => $pRow['internName'],
            'internRole'         => $pRow['internRole'],
            'workStartTime'      => $pRow['workStartTime'],
            'workEndTime'        => $pRow['workEndTime'],
            'requestedByNames'   => [],
            'requests'           => []
        ];
    }
    $groupedPending[$iid]['requests'][] = $pRow;
    // Collect unique TL names per intern group
    $tlName = $pRow['requestedByName'];
    if ($tlName && !in_array($tlName, $groupedPending[$iid]['requestedByNames'])) {
        $groupedPending[$iid]['requestedByNames'][] = $tlName;
    }
}
$totalInternsAwaiting = count($groupedPending);

// ── Build TL-grouped view from intern-grouped data ────────────────────────────
$groupedPendingByTL = [];
foreach ($groupedPending as $iid => $group) {
    foreach ($group['requests'] as $req) {
        $tlId   = $req['requestedBy'] ?? 'unknown';
        $tlName = $req['requestedByName'] ?? $tlId;
        if (!isset($groupedPendingByTL[$tlId])) {
            $groupedPendingByTL[$tlId] = [
                'tlId'     => $tlId,
                'tlName'   => $tlName,
                'requests' => []
            ];
        }
        // Carry intern info into each request row for the lead view table
        $reqWithIntern = $req;
        $reqWithIntern['internName'] = $group['internName'];
        $reqWithIntern['internRole'] = $group['internRole'];
        $groupedPendingByTL[$tlId]['requests'][] = $reqWithIntern;
    }
}
$totalTLsAwaiting = count($groupedPendingByTL);

// ── Processed History Query ─────────────────────────────────────────────────
$historyQuery = "
    SELECT
        r.id,
        r.internId,
        COALESCE(MAX(i.fullName),
            (SELECT si.fullName FROM signup si WHERE si.internId COLLATE utf8mb4_unicode_ci = r.internId COLLATE utf8mb4_unicode_ci LIMIT 1),
            r.internId
        ) AS internName,
        COALESCE(MAX(i.role),
            (SELECT si2.designation FROM signup si2 WHERE si2.internId COLLATE utf8mb4_unicode_ci = r.internId COLLATE utf8mb4_unicode_ci LIMIT 1),
            '-'
        ) AS internRole,
        r.projectName,
        r.clientName,
        r.requestedBy,
        COALESCE(MAX(s.fullName), r.requestedBy) AS requestedByName,
        r.startDate,
        r.endDate,
        r.dailyHours,
        r.allocStartTime,
        r.allocEndTime,
        r.status,
        r.createdAt,
        MAX(a.id) AS allocationId,
        MAX(a.createdAt) AS allocatedAt,
        MAX(u.fullName)  AS approvedByName
    FROM resourceRequests r
    LEFT JOIN interns i
        ON r.internId COLLATE utf8mb4_unicode_ci = i.internId COLLATE utf8mb4_unicode_ci
    LEFT JOIN signup s
        ON r.requestedBy COLLATE utf8mb4_unicode_ci = s.userId COLLATE utf8mb4_unicode_ci
    LEFT JOIN internAllocations a
        ON r.internId     COLLATE utf8mb4_unicode_ci = a.internId    COLLATE utf8mb4_unicode_ci
        AND r.projectName COLLATE utf8mb4_unicode_ci = a.projectName COLLATE utf8mb4_unicode_ci
        AND r.startDate = a.startDate
    LEFT JOIN signup u
        ON a.allocatedBy COLLATE utf8mb4_unicode_ci = u.userId COLLATE utf8mb4_unicode_ci
    WHERE r.status IN ('Approved', 'Rejected')
    GROUP BY r.id, r.internId, r.projectName, r.clientName, r.requestedBy, r.startDate, r.endDate, r.dailyHours, r.allocStartTime, r.allocEndTime, r.status, r.createdAt
    ORDER BY r.createdAt DESC
";
$historyResult = mysqli_query($con, $historyQuery);
if ($historyResult === false) die("History query failed: " . mysqli_error($con));
$totalHistory = mysqli_num_rows($historyResult);

// Collect history rows & distinct interns for filters
$historyRows = [];
$distinctHistoryInterns = [];

while ($hRow = mysqli_fetch_assoc($historyResult)) {
    $historyRows[] = $hRow;
    $iid = $hRow['internId'];
    if (!isset($distinctHistoryInterns[$iid])) {
        $distinctHistoryInterns[$iid] = $hRow['internName'];
    }
}
asort($distinctHistoryInterns);

$totalRequests = $totalPending + $totalHistory;
$totalApproved = (int)mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as c FROM resourceRequests WHERE status='Approved'"))['c'];
$totalRejected = (int)mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as c FROM resourceRequests WHERE status='Rejected'"))['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Resource Approvals Hub</title>
<?php include('../partials/_links.php'); ?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap4.min.css">

<style>
:root {
    --primary:       #4b49ac;
    --primary-light: #eef1ff;
    --success:       #28a745;
    --warning:       #f59e0b;
    --danger:        #e53935;
    --info:          #17a2b8;
    --border:        #e0e4ef;
    --radius:        10px;
    --shadow:        0 4px 14px rgba(75, 73, 172, 0.07);
}

/* ── Stat cards ── */
.stat-card {
    background: #fff; border-radius: 12px; padding: 18px 22px;
    box-shadow: var(--shadow); border-left: 4px solid var(--primary);
    display: flex; align-items: center; gap: 16px;
}
.stat-card.total    { border-color: var(--primary); }
.stat-card.pending  { border-color: var(--warning); }
.stat-card.approved { border-color: var(--success); }
.stat-card.rejected { border-color: var(--danger);  }

.stat-icon {
    width: 46px; height: 46px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.2rem; flex-shrink: 0;
}
.stat-icon.total    { background: var(--primary-light); color: var(--primary); }
.stat-icon.pending  { background: #fff8e1;              color: var(--warning); }
.stat-icon.approved { background: #f0fff4;              color: var(--success); }
.stat-icon.rejected { background: #fff0f0;              color: var(--danger);  }

.stat-num   { font-size: 1.7rem; font-weight: 800; color: #222; line-height: 1; }
.stat-label { font-size: 0.82rem; color: #888; margin-top: 2px; }

/* ── Intern Grouped Card ── */
.intern-group-card {
    background: #fff; border-radius: 12px;
    box-shadow: var(--shadow); border: 1px solid var(--border);
    margin-bottom: 24px; overflow: hidden;
}
.intern-card-header {
    background: linear-gradient(135deg, #4b49ac 0%, #3a39a0 100%);
    padding: 16px 22px; border-bottom: 1px solid rgba(0,0,0,0.15);
    display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;
}
.avatar-circle {
    width: 44px; height: 44px; border-radius: 50%;
    background: rgba(255,255,255,0.2); color: #fff;
    border: 2px solid rgba(255,255,255,0.4);
    display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 1.15rem; flex-shrink: 0;
}

/* ── Inline Inputs ── */
.inline-input-box {
    display: inline-flex; align-items: center; gap: 4px;
    background: #f8f9fd; border: 1.5px solid #dcdfe8;
    border-radius: 8px; padding: 4px 8px; transition: border-color 0.2s;
}
.inline-input-box:focus-within {
    border-color: #4b49ac; box-shadow: 0 0 0 3px rgba(75, 73, 172, 0.12);
}
.inline-date-input {
    width: 130px; border: none; background: transparent;
    font-weight: 600; color: #4b49ac; font-size: 0.8rem; padding: 2px 4px; outline: none;
}
.inline-time-input {
    width: 95px; border: none; background: transparent;
    font-weight: 600; color: #4b49ac; font-size: 0.82rem; padding: 2px 4px; outline: none;
}

/* ── Filter Toolbar ── */
.filter-toolbar {
    background: #f8f9fd; border: 1px solid var(--border);
    border-radius: 10px; padding: 14px 18px; margin-bottom: 16px;
}

/* ── Table wrapper card ── */
.table-wrap {
    background: #fff; border-radius: 12px;
    box-shadow: var(--shadow); overflow: hidden; margin-bottom: 28px;
    border: 1px solid var(--border);
}
.table-wrap .table-header {
    padding: 18px 22px;
    display: flex; align-items: center; justify-content: space-between;
    border-bottom: 1px solid var(--border); background: #fafbfe;
}
.table-wrap .table-header h5 { margin: 0; font-weight: 700; font-size: 1rem; color: #222; }
.table-wrap .table-header .record-count { color: #888; font-size: 0.83rem; }

.approval-table thead th {
    background: #3a39a0; color: #fff; font-weight: 700;
    font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.05em;
    padding: 12px 16px; border-bottom: 1px solid rgba(0,0,0,0.12); white-space: nowrap;
}
.approval-table td {
    padding: 12px 16px; border-bottom: 1px solid #f2f4fb;
    vertical-align: middle; color: #222; font-size: 0.84rem; white-space: nowrap;
}
.approval-table tr:hover td { background: #f0efff; }

/* ── Badges ── */
.badge-status {
    display: inline-block; padding: 4px 11px;
    border-radius: 20px; font-size: 0.75rem; font-weight: 700;
}
.badge-pending  { background: #fff8e1; color: #b45309; }
.badge-approved { background: #f0fff4; color: #15803d; }
.badge-rejected { background: #fff0f0; color: #b91c1c; }

/* ── View Tabs ── */
.view-tabs {
    display: flex; gap: 0;
    background: #fff; border-radius: 12px;
    box-shadow: var(--shadow); border: 1px solid var(--border);
    padding: 6px; width: fit-content; margin-bottom: 20px;
}
.view-tab-btn {
    padding: 9px 24px; border-radius: 8px; border: none; background: transparent;
    font-weight: 700; font-size: 0.85rem; color: #888; cursor: pointer;
    display: flex; align-items: center; gap: 8px;
    transition: all 0.22s cubic-bezier(.4,0,.2,1);
}
.view-tab-btn:hover { color: #444; background: #f4f5fb; }
.view-tab-btn.active-intern {
    background: linear-gradient(135deg,#4b49ac,#6c6bcc); color: #fff;
    box-shadow: 0 2px 10px rgba(75,73,172,0.22);
}
.view-tab-btn.active-lead {
    background: linear-gradient(135deg,#0d9488,#0891b2); color: #fff;
    box-shadow: 0 2px 10px rgba(13,148,136,0.22);
}
.view-tab-btn .tab-count {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 22px; height: 22px; border-radius: 20px;
    font-size: 0.72rem; font-weight: 800; padding: 0 6px;
}
.view-tab-btn.active-intern .tab-count { background: rgba(255,255,255,0.25); color:#fff; }
.view-tab-btn.active-lead   .tab-count { background: rgba(255,255,255,0.25); color:#fff; }
.view-tab-btn:not(.active-intern):not(.active-lead) .tab-count { background: #eef1ff; color: #4b49ac; }

/* ── TL Group Card ── */
.tl-group-card {
    background: #fff; border-radius: 12px;
    box-shadow: var(--shadow); border: 1px solid var(--border);
    margin-bottom: 24px; overflow: hidden;
}
.tl-card-header {
    background: linear-gradient(135deg, #0d7a72 0%, #0b6b8a 100%);
    padding: 16px 22px; border-bottom: 1px solid rgba(0,0,0,0.15);
    display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;
}
.tl-avatar-circle {
    width: 44px; height: 44px; border-radius: 50%;
    background: rgba(255,255,255,0.2); color: #fff;
    border: 2px solid rgba(255,255,255,0.4);
    display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 1.15rem; flex-shrink: 0;
}
</style>
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
        <div class="content-wrapper">

            <!-- Page Header -->
            <div class="d-flex align-items-center justify-content-between mb-4">
                <div>
                    <h4 class="mb-1" style="font-weight:800;color:#222;">
                        <i class="fa fa-users me-2" style="color:var(--primary)"></i>
                        Resource Approvals Dashboard
                    </h4>
                    <p class="mb-0" style="color:#888;font-size:0.88rem;">
                        Resource Manager View — Requests grouped by intern for instant date & time adjustment & approval.
                    </p>
                </div>
            </div>

            <!-- Stat Cards -->
            <div class="row mb-4">
                <div class="col-md-3 mb-3">
                    <div class="stat-card total">
                        <div class="stat-icon total"><i class="fa fa-list-alt"></i></div>
                        <div>
                            <div class="stat-num"><?php echo $totalRequests; ?></div>
                            <div class="stat-label">Total Requests</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="stat-card pending">
                        <div class="stat-icon pending"><i class="fa fa-hourglass-half"></i></div>
                        <div>
                            <div class="stat-num" id="stat-pending-num"><?php echo $totalPending; ?></div>
                            <div class="stat-label" id="stat-pending-label">Pending Requests (<?php echo $totalInternsAwaiting; ?> Interns)</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="stat-card approved">
                        <div class="stat-icon approved"><i class="fa fa-check-circle"></i></div>
                        <div>
                            <div class="stat-num" id="stat-approved-num"><?php echo $totalApproved; ?></div>
                            <div class="stat-label">Approved</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="stat-card rejected">
                        <div class="stat-icon rejected"><i class="fa fa-times-circle"></i></div>
                        <div>
                            <div class="stat-num" id="stat-rejected-num"><?php echo $totalRejected; ?></div>
                            <div class="stat-label">Rejected</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════
                 SECTION 1 — PENDING REQUESTS (TABBED VIEW)
            ══════════════════════════════════════════════ -->
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h5 style="font-weight:700;color:#222;margin:0;">
                    <i class="fa fa-clock-o me-2" style="color:var(--warning)"></i>
                    Pending Requests
                </h5>
                <span class="text-muted" style="font-size:0.83rem;" id="view-count-label">
                    Showing <?php echo $totalInternsAwaiting; ?> intern(s) with pending resource requests
                </span>
            </div>

            <!-- Tab Switcher -->
            <div class="view-tabs">
                <button class="view-tab-btn active-intern" id="tab-btn-intern" onclick="switchView('intern')">
                    <i class="fa fa-user"></i> Intern View
                    <span class="tab-count" id="tab-count-intern"><?php echo $totalInternsAwaiting; ?></span>
                </button>
                <button class="view-tab-btn" id="tab-btn-lead" onclick="switchView('lead')">
                    <i class="fa fa-users"></i> Lead View
                    <span class="tab-count" id="tab-count-lead"><?php echo $totalTLsAwaiting; ?></span>
                </button>
            </div>

            <!-- Pending Search Bar -->
            <div style="position:relative;margin-bottom:18px;">
                <i class="fa fa-search" style="position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#aaa;font-size:0.9rem;pointer-events:none;"></i>
                <input type="text" id="pend-search-input"
                    placeholder="Search by intern, team lead, project or client…"
                    style="width:100%;padding:10px 14px 10px 38px;border:1.5px solid var(--border);border-radius:10px;font-size:0.85rem;color:#333;background:#fff;box-shadow:var(--shadow);outline:none;transition:border-color 0.2s;"
                    onfocus="this.style.borderColor='#4b49ac'" onblur="this.style.borderColor='var(--border)'">
                <span id="pend-search-count" style="position:absolute;right:14px;top:50%;transform:translateY(-50%);font-size:0.78rem;color:#aaa;"></span>
            </div>

            <!-- ── INTERN VIEW PANE ── -->
            <div id="pane-intern-view">
            <?php if (empty($groupedPending)): ?>
                <div class="card mb-4" style="border-radius:12px;border:1px solid var(--border);">
                    <div class="card-body text-center py-5">
                        <i class="fa fa-check-circle-o fa-3x text-success mb-3 d-block"></i>
                        <h5 style="font-weight:700;color:#333;">All Caught Up!</h5>
                        <p class="text-muted" style="font-size:0.88rem;margin:0;">There are no pending resource requests awaiting approval at this time.</p>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($groupedPending as $iid => $group): 
                    $reqCount = count($group['requests']);
                    $hasCompeting = $reqCount > 1;
                ?>
                <div class="intern-group-card">
                    <!-- Intern Header -->
                    <div class="intern-card-header">
                        <div class="d-flex align-items-center gap-3">
                            <div class="avatar-circle">
                                <?php echo strtoupper(substr($group['internName'], 0, 1)); ?>
                            </div>
                            <div>
                                <h5 class="mb-0" style="font-weight:800;color:#fff;">
                                    <?php echo htmlspecialchars($group['internName']); ?>
                                    <span class="badge badge-primary ml-2" style="font-size:0.75rem;border-radius:12px;padding:3px 10px;background:rgba(255,255,255,0.2);color:#fff;border:1px solid rgba(255,255,255,0.35);">
                                        <?php echo htmlspecialchars($group['internRole']); ?>
                                    </span>
                                </h5>
                                <small style="color:rgba(255,255,255,0.8);">
                                    <i class="fa fa-clock-o" style="color:rgba(255,255,255,0.75);"></i> Normal Working Window:
                                    <strong style="color:#fff;"><?php echo to12hr($group['workStartTime']) . ' – ' . to12hr($group['workEndTime']); ?></strong>
                                </small>
                            </div>
                        </div>

                        <div class="d-flex align-items-center gap-2">
                            <?php if ($hasCompeting): ?>
                                <span class="badge py-2 px-3" style="font-size:0.78rem;border-radius:20px;background:rgba(255,255,255,0.18);color:#fff;border:1px solid rgba(255,255,255,0.3);font-weight:700;">
                                    <i class="fa fa-exclamation-triangle me-1"></i> <?php echo $reqCount; ?> Competing Requests
                                </span>
                            <?php else: ?>
                                <span class="badge py-2 px-3" style="font-size:0.78rem;border-radius:20px;background:rgba(255,255,255,0.18);color:#fff;border:1px solid rgba(255,255,255,0.3);font-weight:700;">
                                    <i class="fa fa-paper-plane me-1"></i> 1 Pending Request
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Competing Requests Table for this Intern -->
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" style="width:100%;">
                            <thead style="background:#3a39a0;color:#fff;font-size:0.76rem;text-transform:uppercase;letter-spacing:0.04em;">
                                <tr>
                                    <th style="padding:12px 18px;border-bottom:1px solid rgba(255,255,255,0.12);width:3%;">#</th>
                                    <th style="padding:12px 18px;border-bottom:1px solid rgba(255,255,255,0.12);width:15%;">Requested By (TL)</th>
                                    <th style="padding:12px 18px;border-bottom:1px solid rgba(255,255,255,0.12);width:16%;">Project & Client</th>
                                    <th style="padding:12px 18px;border-bottom:1px solid rgba(255,255,255,0.12);width:24%;">Dates (RM Adjustable)</th>
                                    <th style="padding:12px 18px;border-bottom:1px solid rgba(255,255,255,0.12);width:22%;">Time Slot (RM Adjustable)</th>
                                    <th style="padding:12px 18px;border-bottom:1px solid rgba(255,255,255,0.12);width:7%;">Hrs/Day</th>
                                    <th style="padding:12px 18px;border-bottom:1px solid rgba(255,255,255,0.12);width:8%;">Submitted</th>
                                    <th style="padding:12px 18px;border-bottom:1px solid rgba(255,255,255,0.12);text-align:center;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $rIdx = 1;
                                foreach ($group['requests'] as $req):
                                    $reqId      = (int)$req['id'];
                                    $reqBy      = htmlspecialchars($req['requestedByName']);
                                    $projName   = htmlspecialchars($req['projectName']);
                                    $clientName = htmlspecialchars($req['clientName']);
                                    $sDateRaw   = $req['startDate'];
                                    $eDateRaw   = $req['endDate'];
                                    $stVal      = !empty($req['allocStartTime']) ? substr($req['allocStartTime'], 0, 5) : '09:00';
                                    $etVal      = !empty($req['allocEndTime'])   ? substr($req['allocEndTime'], 0, 5)   : '17:00';
                                    $dailyHrs   = (int)$req['dailyHours'];
                                    $submitted  = date('d M Y', strtotime($req['createdAt']));
                                    $createdRaw = date('Y-m-d', strtotime($req['createdAt']));
                                ?>
                                <tr style="<?php echo !empty($req['hasConflict']) ? 'background:#fff8f8;border-bottom:1px solid #fee2e2;' : 'border-bottom:1px solid #f2f4fb;'; ?>" data-reqid="<?php echo $reqId; ?>" data-internid="<?php echo htmlspecialchars($req['internId']); ?>" data-created="<?php echo $createdRaw; ?>">
                                    <td style="padding:14px 18px;vertical-align:middle;font-weight:700;color:#4b49ac;"><?php echo $rIdx++; ?></td>
                                    
                                    <td style="padding:14px 18px;vertical-align:middle;">
                                        <strong style="color:#2b2b40;font-size:0.88rem;"><?php echo $reqBy; ?></strong>
                                    </td>

                                    <td style="padding:14px 18px;vertical-align:middle;">
                                        <div style="font-weight:700;color:#2b2b40;font-size:0.86rem;"><?php echo $projName; ?></div>
                                        <small class="text-muted"><?php echo $clientName; ?></small>

                                        <!-- Conflict Alert Banner -->
                                        <div class="conflict-status-container conflict-box-<?php echo $reqId; ?>" style="margin-top:6px;">
                                            <?php if (!empty($req['hasConflict'])): ?>
                                                <div class="conflict-alert-badge" style="background:#fff0f0; border:1px solid #fecaca; border-radius:6px; padding:4px 8px; color:#dc2626; font-size:0.77rem; font-weight:600; display:inline-flex; align-items:center; gap:5px;">
                                                    <i class="fa fa-exclamation-triangle"></i>
                                                    <span>Conflict: <?php echo htmlspecialchars($req['conflictMsg']); ?></span>
                                                </div>
                                            <?php elseif (!empty($req['warningMsg'])): ?>
                                                <div class="warning-alert-badge" style="background:#fffbe0; border:1px solid #fef08a; border-radius:6px; padding:4px 8px; color:#b45309; font-size:0.77rem; font-weight:600; display:inline-flex; align-items:center; gap:5px;">
                                                    <i class="fa fa-info-circle"></i>
                                                    <span><?php echo htmlspecialchars($req['warningMsg']); ?></span>
                                                </div>
                                            <?php else: ?>
                                                <div class="valid-alert-badge" style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:6px; padding:4px 8px; color:#15803d; font-size:0.77rem; font-weight:600; display:inline-flex; align-items:center; gap:5px;">
                                                    <i class="fa fa-check-circle"></i>
                                                    <span>Slot Available — No Conflicts</span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                    <!-- Dates Input (RM Adjustable) -->
                                    <td style="padding:14px 18px;vertical-align:middle;">
                                        <div class="inline-input-box">
                                            <input type="date" class="inline-date-input date-st-val" 
                                                   value="<?php echo $sDateRaw; ?>" 
                                                   data-reqid="<?php echo $reqId; ?>" required>
                                            <span style="color:#999;font-size:0.75rem;">to</span>
                                            <input type="date" class="inline-date-input date-et-val" 
                                                   value="<?php echo $eDateRaw; ?>" 
                                                   data-reqid="<?php echo $reqId; ?>" required>
                                        </div>
                                    </td>

                                    <!-- Time Slot Input (RM Adjustable) -->
                                    <td style="padding:14px 18px;vertical-align:middle;">
                                        <div class="inline-input-box">
                                            <input type="time" class="inline-time-input time-st-val" 
                                                   value="<?php echo $stVal; ?>" 
                                                   data-reqid="<?php echo $reqId; ?>" required>
                                            <span style="color:#999;font-size:0.75rem;">to</span>
                                            <input type="time" class="inline-time-input time-et-val" 
                                                   value="<?php echo $etVal; ?>" 
                                                   data-reqid="<?php echo $reqId; ?>" required>
                                        </div>
                                    </td>

                                    <td style="padding:14px 18px;vertical-align:middle;">
                                        <span class="badge hrs-badge-<?php echo $reqId; ?>" 
                                              style="font-size:0.8rem;font-weight:700;color:#4b49ac;background:#eef1ff;border-radius:6px;padding:4px 8px;">
                                            <?php echo $dailyHrs; ?> hrs
                                        </span>
                                    </td>

                                    <td style="padding:14px 18px;vertical-align:middle;color:#777;font-size:0.8rem;white-space:nowrap;">
                                        <?php echo $submitted; ?>
                                    </td>

                                    <!-- ACTIONS & Hidden Self-Contained Form -->
                                    <td style="padding:14px 18px;vertical-align:middle;text-align:center;white-space:nowrap;">
                                        <?php if ($canApprove): ?>
                                        <!-- Self-Contained Form -->
                                        <form id="form_alloc_<?php echo $reqId; ?>" method="POST" action="resourceAllocationProcess.php" style="display:none;">
                                            <input type="hidden" name="requestId"       value="<?php echo $reqId; ?>">
                                            <input type="hidden" name="approvedBy"      value="<?php echo htmlspecialchars($userId); ?>">
                                            <input type="hidden" name="action"          id="action_input_<?php echo $reqId; ?>" value="approve">
                                            <input type="hidden" name="rejectionReason" id="reject_reason_input_<?php echo $reqId; ?>" value="">
                                            <input type="hidden" name="newStartDate"    id="form_sdate_<?php echo $reqId; ?>" value="">
                                            <input type="hidden" name="newEndDate"      id="form_edate_<?php echo $reqId; ?>" value="">
                                            <input type="hidden" name="newAllocST"      id="form_st_<?php echo $reqId; ?>" value="">
                                            <input type="hidden" name="newAllocET"      id="form_et_<?php echo $reqId; ?>" value="">
                                        </form>

                                        <button type="button" class="btn btn-success btn-sm px-3 mr-1 btn-inline-approve" 
                                                data-reqid="<?php echo $reqId; ?>"
                                                data-intern="<?php echo htmlspecialchars($group['internName']); ?>"
                                                data-project="<?php echo $projName; ?>"
                                                style="border-radius:6px;font-weight:600;font-size:0.8rem;">
                                            <i class="fa fa-check me-1"></i> Approve
                                        </button>
                                        <button type="button" class="btn btn-outline-danger btn-sm px-3 btn-inline-reject" 
                                                data-reqid="<?php echo $reqId; ?>"
                                                data-intern="<?php echo htmlspecialchars($group['internName']); ?>"
                                                data-project="<?php echo $projName; ?>"
                                                style="border-radius:6px;font-weight:600;font-size:0.8rem;">
                                            <i class="fa fa-times me-1"></i> Reject
                                        </button>
                                        <?php else: ?>
                                        <span class="badge bg-secondary px-3 py-2" style="font-size:0.8rem;border-radius:6px;">
                                            <i class="fa fa-eye me-1"></i> View Only
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
            </div><!-- /pane-intern-view -->

            <!-- ── LEAD VIEW PANE ── -->
            <div id="pane-lead-view" style="display:none;">
            <?php if (empty($groupedPendingByTL)): ?>
                <div class="card mb-4" style="border-radius:12px;border:1px solid var(--border);">
                    <div class="card-body text-center py-5">
                        <i class="fa fa-check-circle-o fa-3x text-success mb-3 d-block"></i>
                        <h5 style="font-weight:700;color:#333;">All Caught Up!</h5>
                        <p class="text-muted" style="font-size:0.88rem;margin:0;">There are no pending resource requests at this time.</p>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($groupedPendingByTL as $tlId => $tlGroup):
                    $tlReqCount   = count($tlGroup['requests']);
                    $tlInternIds  = array_unique(array_column($tlGroup['requests'], 'internId'));
                    $tlInternCount = count($tlInternIds);
                ?>
                <div class="tl-group-card">
                    <!-- TL Header -->
                    <div class="tl-card-header">
                        <div class="d-flex align-items-center gap-3">
                            <div class="tl-avatar-circle">
                                <?php echo strtoupper(substr($tlGroup['tlName'], 0, 1)); ?>
                            </div>
                            <div>
                                <h5 class="mb-0" style="font-weight:800;color:#fff;">
                                    <?php echo htmlspecialchars($tlGroup['tlName']); ?>
                                    <span class="badge ml-2" style="font-size:0.72rem;border-radius:12px;padding:3px 10px;background:rgba(255,255,255,0.2);color:#fff;border:1px solid rgba(255,255,255,0.35);">Team Lead</span>
                                </h5>
                                <small style="color:rgba(255,255,255,0.8);">
                                    <i class="fa fa-user me-1"></i>
                                    Requested <strong style="color:#fff;"><?php echo $tlInternCount; ?></strong> intern<?php echo $tlInternCount > 1 ? 's' : ''; ?> &nbsp;|&nbsp;
                                    <i class="fa fa-file-text-o me-1"></i>
                                    <strong style="color:#fff;"><?php echo $tlReqCount; ?></strong> total request<?php echo $tlReqCount > 1 ? 's' : ''; ?>
                                </small>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <?php if ($tlInternCount > 1): ?>
                                <span class="badge py-2 px-3" style="font-size:0.78rem;border-radius:20px;background:rgba(255,255,255,0.18);color:#fff;border:1px solid rgba(255,255,255,0.35);font-weight:700;">
                                    <i class="fa fa-users me-1"></i> <?php echo $tlInternCount; ?> Interns Requested
                                </span>
                            <?php else: ?>
                                <span class="badge py-2 px-3" style="font-size:0.78rem;border-radius:20px;background:rgba(255,255,255,0.18);color:#fff;border:1px solid rgba(255,255,255,0.3);font-weight:700;">
                                    <i class="fa fa-paper-plane me-1"></i> 1 Intern Requested
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Requests Table for this TL -->
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" style="width:100%;">
                            <thead style="background:#0b6b8a;color:#fff;font-size:0.76rem;text-transform:uppercase;letter-spacing:0.04em;">
                                <tr>
                                    <th style="padding:12px 18px;border-bottom:1px solid rgba(255,255,255,0.12);width:3%;">#</th>
                                    <th style="padding:12px 18px;border-bottom:1px solid rgba(255,255,255,0.12);width:16%;">Intern Name</th>
                                    <th style="padding:12px 18px;border-bottom:1px solid rgba(255,255,255,0.12);width:16%;">Project &amp; Client</th>
                                    <th style="padding:12px 18px;border-bottom:1px solid rgba(255,255,255,0.12);width:24%;">Dates (RM Adjustable)</th>
                                    <th style="padding:12px 18px;border-bottom:1px solid rgba(255,255,255,0.12);width:22%;">Time Slot (RM Adjustable)</th>
                                    <th style="padding:12px 18px;border-bottom:1px solid rgba(255,255,255,0.12);width:7%;">Hrs/Day</th>
                                    <th style="padding:12px 18px;border-bottom:1px solid rgba(255,255,255,0.12);width:8%;">Submitted</th>
                                    <th style="padding:12px 18px;border-bottom:1px solid rgba(255,255,255,0.12);text-align:center;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $tlRIdx = 1;
                                foreach ($tlGroup['requests'] as $tlReq):
                                    $tlReqId      = (int)$tlReq['id'];
                                    $tlInternName = htmlspecialchars($tlReq['internName']);
                                    $tlInternRole = htmlspecialchars($tlReq['internRole']);
                                    $tlProjName   = htmlspecialchars($tlReq['projectName']);
                                    $tlClientName = htmlspecialchars($tlReq['clientName']);
                                    $tlSDateRaw   = $tlReq['startDate'];
                                    $tlEDateRaw   = $tlReq['endDate'];
                                    $tlStVal      = !empty($tlReq['allocStartTime']) ? substr($tlReq['allocStartTime'], 0, 5) : '09:00';
                                    $tlEtVal      = !empty($tlReq['allocEndTime'])   ? substr($tlReq['allocEndTime'], 0, 5)   : '17:00';
                                    $tlDailyHrs   = (int)$tlReq['dailyHours'];
                                    $tlSubmitted  = date('d M Y', strtotime($tlReq['createdAt']));
                                    $tlCreatedRaw = date('Y-m-d', strtotime($tlReq['createdAt']));
                                ?>
                                <tr style="<?php echo !empty($tlReq['hasConflict']) ? 'background:#fff8f8;border-bottom:1px solid #fee2e2;' : 'border-bottom:1px solid #f0fdfa;'; ?>" data-reqid="<?php echo $tlReqId; ?>" data-internid="<?php echo htmlspecialchars($tlReq['internId']); ?>" data-created="<?php echo $tlCreatedRaw; ?>">
                                    <td style="padding:14px 18px;vertical-align:middle;font-weight:700;color:#0d9488;"><?php echo $tlRIdx++; ?></td>

                                    <td style="padding:14px 18px;vertical-align:middle;">
                                        <div style="display:flex;align-items:center;gap:8px;">
                                            <div style="width:30px;height:30px;border-radius:50%;background:#4b49ac;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:0.85rem;flex-shrink:0;">
                                                <?php echo strtoupper(substr($tlReq['internName'], 0, 1)); ?>
                                            </div>
                                            <div>
                                                <div style="font-weight:700;color:#2b2b40;font-size:0.87rem;"><?php echo $tlInternName; ?></div>
                                                <small style="color:#888;"><?php echo $tlInternRole; ?></small>
                                            </div>
                                        </div>
                                    </td>

                                    <td style="padding:14px 18px;vertical-align:middle;">
                                        <div style="font-weight:700;color:#2b2b40;font-size:0.86rem;"><?php echo $tlProjName; ?></div>
                                        <small class="text-muted"><?php echo $tlClientName; ?></small>

                                        <!-- Conflict Alert Banner -->
                                        <div class="conflict-status-container conflict-box-<?php echo $tlReqId; ?>" style="margin-top:6px;">
                                            <?php if (!empty($tlReq['hasConflict'])): ?>
                                                <div class="conflict-alert-badge" style="background:#fff0f0; border:1px solid #fecaca; border-radius:6px; padding:4px 8px; color:#dc2626; font-size:0.77rem; font-weight:600; display:inline-flex; align-items:center; gap:5px;">
                                                    <i class="fa fa-exclamation-triangle"></i>
                                                    <span>Conflict: <?php echo htmlspecialchars($tlReq['conflictMsg']); ?></span>
                                                </div>
                                            <?php elseif (!empty($tlReq['warningMsg'])): ?>
                                                <div class="warning-alert-badge" style="background:#fffbe0; border:1px solid #fef08a; border-radius:6px; padding:4px 8px; color:#b45309; font-size:0.77rem; font-weight:600; display:inline-flex; align-items:center; gap:5px;">
                                                    <i class="fa fa-info-circle"></i>
                                                    <span><?php echo htmlspecialchars($tlReq['warningMsg']); ?></span>
                                                </div>
                                            <?php else: ?>
                                                <div class="valid-alert-badge" style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:6px; padding:4px 8px; color:#15803d; font-size:0.77rem; font-weight:600; display:inline-flex; align-items:center; gap:5px;">
                                                    <i class="fa fa-check-circle"></i>
                                                    <span>Slot Available — No Conflicts</span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                    <!-- Dates Input (RM Adjustable) -->
                                    <td style="padding:14px 18px;vertical-align:middle;">
                                        <div class="inline-input-box">
                                            <input type="date" class="inline-date-input date-st-val"
                                                   value="<?php echo $tlSDateRaw; ?>"
                                                   data-reqid="<?php echo $tlReqId; ?>" required>
                                            <span style="color:#999;font-size:0.75rem;">to</span>
                                            <input type="date" class="inline-date-input date-et-val"
                                                   value="<?php echo $tlEDateRaw; ?>"
                                                   data-reqid="<?php echo $tlReqId; ?>" required>
                                        </div>
                                    </td>

                                    <!-- Time Slot Input (RM Adjustable) -->
                                    <td style="padding:14px 18px;vertical-align:middle;">
                                        <div class="inline-input-box">
                                            <input type="time" class="inline-time-input time-st-val"
                                                   value="<?php echo $tlStVal; ?>"
                                                   data-reqid="<?php echo $tlReqId; ?>" required>
                                            <span style="color:#999;font-size:0.75rem;">to</span>
                                            <input type="time" class="inline-time-input time-et-val"
                                                   value="<?php echo $tlEtVal; ?>"
                                                   data-reqid="<?php echo $tlReqId; ?>" required>
                                        </div>
                                    </td>

                                    <td style="padding:14px 18px;vertical-align:middle;">
                                        <span class="badge hrs-badge-<?php echo $tlReqId; ?>"
                                              style="font-size:0.8rem;font-weight:700;color:#0d9488;background:#ccfbf1;border-radius:6px;padding:4px 8px;">
                                            <?php echo $tlDailyHrs; ?> hrs
                                        </span>
                                    </td>

                                    <td style="padding:14px 18px;vertical-align:middle;color:#777;font-size:0.8rem;white-space:nowrap;">
                                        <?php echo $tlSubmitted; ?>
                                    </td>

                                    <!-- ACTIONS -->
                                    <td style="padding:14px 18px;vertical-align:middle;text-align:center;white-space:nowrap;">
                                        <?php if ($canApprove): ?>
                                        <!-- Form IDs prefixed with tl_ to avoid duplicate IDs -->
                                        <form id="form_alloc_tl_<?php echo $tlReqId; ?>" method="POST" action="resourceAllocationProcess.php" style="display:none;">
                                            <input type="hidden" name="requestId"       value="<?php echo $tlReqId; ?>">
                                            <input type="hidden" name="approvedBy"      value="<?php echo htmlspecialchars($userId); ?>">
                                            <input type="hidden" name="action"          id="action_input_tl_<?php echo $tlReqId; ?>" value="approve">
                                            <input type="hidden" name="rejectionReason" id="reject_reason_input_tl_<?php echo $tlReqId; ?>" value="">
                                            <input type="hidden" name="newStartDate"    id="form_sdate_tl_<?php echo $tlReqId; ?>" value="">
                                            <input type="hidden" name="newEndDate"      id="form_edate_tl_<?php echo $tlReqId; ?>" value="">
                                            <input type="hidden" name="newAllocST"      id="form_st_tl_<?php echo $tlReqId; ?>" value="">
                                            <input type="hidden" name="newAllocET"      id="form_et_tl_<?php echo $tlReqId; ?>" value="">
                                        </form>
                                        <button type="button" class="btn btn-success btn-sm px-3 mr-1 btn-tl-approve"
                                                data-reqid="<?php echo $tlReqId; ?>"
                                                data-intern="<?php echo $tlInternName; ?>"
                                                data-project="<?php echo $tlProjName; ?>"
                                                style="border-radius:6px;font-weight:600;font-size:0.8rem;">
                                            <i class="fa fa-check me-1"></i> Approve
                                        </button>
                                        <button type="button" class="btn btn-outline-danger btn-sm px-3 btn-tl-reject"
                                                data-reqid="<?php echo $tlReqId; ?>"
                                                data-intern="<?php echo $tlInternName; ?>"
                                                data-project="<?php echo $tlProjName; ?>"
                                                style="border-radius:6px;font-weight:600;font-size:0.8rem;">
                                            <i class="fa fa-times me-1"></i> Reject
                                        </button>
                                        <?php else: ?>
                                        <span class="badge bg-secondary px-3 py-2" style="font-size:0.8rem;border-radius:6px;">
                                            <i class="fa fa-eye me-1"></i> View Only
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
            </div><!-- /pane-lead-view -->

            <!-- ══════════════════════════════════════════════
                 SECTION 2 — PROCESSED HISTORY
            ══════════════════════════════════════════════ -->
            <div class="table-wrap mt-4">
                <div class="table-header">
                    <div>
                        <h5 class="mb-1">
                            <i class="fa fa-history me-2" style="color:var(--info)"></i>
                            Previously Processed Requests History
                        </h5>
                        <small class="text-muted">Filter processed allocations by intern, status, or week.</small>
                    </div>
                    <span class="record-count">
                        <?php echo $totalHistory; ?> record<?php echo $totalHistory !== 1 ? 's' : ''; ?>
                    </span>
                </div>

                <!-- ── FILTER TOOLBAR ── -->
                <div class="filter-toolbar m-3">
                    <div class="row align-items-center">
                        <!-- Intern Filter -->
                        <div class="col-md-3 mb-2 mb-md-0">
                            <label class="mb-1" style="font-size:0.78rem;font-weight:700;color:#555;">
                                <i class="fa fa-user me-1" style="color:#4b49ac;"></i> Filter by Intern:
                            </label>
                            <select id="histInternFilter" class="form-control form-control-sm" style="border-radius:6px;">
                                <option value="all">All Interns (<?php echo count($distinctHistoryInterns); ?>)</option>
                                <?php foreach ($distinctHistoryInterns as $hIid => $hIname): ?>
                                    <option value="<?php echo htmlspecialchars($hIid); ?>"><?php echo htmlspecialchars($hIname); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Status Filter -->
                        <div class="col-md-2 mb-2 mb-md-0">
                            <label class="mb-1" style="font-size:0.78rem;font-weight:700;color:#555;">
                                <i class="fa fa-tag me-1" style="color:#4b49ac;"></i> Status:
                            </label>
                            <select id="histStatusFilter" class="form-control form-control-sm" style="border-radius:6px;">
                                <option value="all">All Statuses</option>
                                <option value="Approved">Approved Only</option>
                                <option value="Rejected">Rejected Only</option>
                            </select>
                        </div>

                        <!-- Week Preset Filter -->
                        <div class="col-md-3 mb-2 mb-md-0">
                            <label class="mb-1" style="font-size:0.78rem;font-weight:700;color:#555;">
                                <i class="fa fa-calendar me-1" style="color:#4b49ac;"></i> Week / Date Range:
                            </label>
                            <select id="histWeekFilter" class="form-control form-control-sm" style="border-radius:6px;">
                                <option value="all">All Time</option>
                                <option value="this_week">This Week</option>
                                <option value="next_week">Next Week</option>
                                <option value="last_week">Last Week</option>
                                <option value="this_month">This Month</option>
                                <option value="custom">Custom Date Range</option>
                            </select>
                        </div>

                        <!-- Custom Date Pickers -->
                        <div class="col-md-3 mb-2 mb-md-0">
                            <label class="mb-1" style="font-size:0.78rem;font-weight:700;color:#555;">From / To Date:</label>
                            <div class="d-flex align-items-center gap-1">
                                <input type="date" id="histStartDate" class="form-control form-control-sm" style="border-radius:6px;font-size:0.78rem;">
                                <span style="font-size:0.75rem;color:#999;">to</span>
                                <input type="date" id="histEndDate" class="form-control form-control-sm" style="border-radius:6px;font-size:0.78rem;">
                            </div>
                        </div>

                        <!-- Reset Button -->
                        <div class="col-md-1 text-right">
                            <label class="d-none d-md-block mb-1">&nbsp;</label>
                            <button type="button" id="histResetBtn" class="btn btn-sm btn-outline-secondary" style="border-radius:6px;font-size:0.78rem;" title="Reset all filters">
                                <i class="fa fa-refresh"></i> Reset
                            </button>
                        </div>
                    </div>
                </div>

                <div style="padding: 0 0 16px;">
                    <table class="approval-table table table-hover" id="historyTable" style="width:100%">
                        <thead>
                            <tr>
                                <th style="width:4%">#</th>
                                <th style="width:9%">Intern Name</th>
                                <th style="width:8%">Role</th>
                                <th style="width:10%">Project</th>
                                <th style="width:9%">Client</th>
                                <th style="width:9%">Requested By</th>
                                <th style="width:7%">Start Date</th>
                                <th style="width:7%">End Date</th>
                                <th style="width:10%">Time Slot</th>
                                <th style="width:5%">Hrs/Day</th>
                                <th style="width:8%">Submitted</th>
                                <th style="width:8%">Processed</th>
                                <th style="width:9%">Approved By</th>
                                <th style="width:7%">Status</th>
                                <th style="width:7%;text-align:center;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        $hSerial = 1;
                        foreach ($historyRows as $hRow):
                            $hReqId       = (int)$hRow['id'];
                            $hAllocId     = (int)($hRow['allocationId'] ?? 0);
                            $hInternId    = htmlspecialchars($hRow['internId']);
                            $hInternName  = htmlspecialchars($hRow['internName']);
                            $hInternRole  = htmlspecialchars($hRow['internRole']);
                            $hProjectName = htmlspecialchars($hRow['projectName']);
                            $hClientName  = htmlspecialchars($hRow['clientName']);
                            $hRequestedBy = htmlspecialchars($hRow['requestedByName']);
                            $hStartDateRaw= $hRow['startDate'];
                            $hEndDateRaw  = $hRow['endDate'];
                            $hStartDate   = date('d M Y', strtotime($hStartDateRaw));
                            $hEndDate     = date('d M Y', strtotime($hEndDateRaw));
                            $hDailyHours  = (int) $hRow['dailyHours'];
                            $hST          = $hRow['allocStartTime'] ?? '';
                            $hET          = $hRow['allocEndTime']   ?? '';
                            $hStVal       = !empty($hST) ? substr($hST, 0, 5) : '09:00';
                            $hEtVal       = !empty($hET) ? substr($hET, 0, 5) : '17:00';
                            $hSlotDisp    = ($hST && $hET) ? to12hr($hStVal) . ' – ' . to12hr($hEtVal) : 'Full Day';
                            $hSubmittedOn = date('d M Y', strtotime($hRow['createdAt']));
                            $hProcessedOn = $hRow['allocatedAt'] ? date('d M Y', strtotime($hRow['allocatedAt'])) : '—';
                            $hApprovedBy  = $hRow['approvedByName'] ? htmlspecialchars($hRow['approvedByName']) : '—';
                            $hStatus      = $hRow['status'];
                            $badgeClass   = $hStatus === 'Approved' ? 'badge-approved' : 'badge-rejected';
                            $iconClass    = $hStatus === 'Approved' ? 'fa-check-circle' : 'fa-times-circle';
                        ?>
                            <tr data-intern-id="<?php echo $hInternId; ?>" 
                                data-status="<?php echo $hStatus; ?>"
                                data-start-date="<?php echo $hStartDateRaw; ?>"
                                data-end-date="<?php echo $hEndDateRaw; ?>">
                                <td><?php echo $hSerial++; ?></td>
                                <td><strong><?php echo $hInternName; ?></strong></td>
                                <td><?php echo $hInternRole; ?></td>
                                <td><?php echo $hProjectName; ?></td>
                                <td><?php echo $hClientName; ?></td>
                                <td><?php echo $hRequestedBy; ?></td>
                                <td><?php echo $hStartDate; ?></td>
                                <td><?php echo $hEndDate; ?></td>
                                <td><small style="background:#eef1ff;color:#4b49ac;border-radius:10px;padding:2px 8px;font-weight:600;"><?php echo $hSlotDisp; ?></small></td>
                                <td><?php echo $hDailyHours; ?> hrs</td>
                                <td><?php echo $hSubmittedOn; ?></td>
                                <td><?php echo $hProcessedOn; ?></td>
                                <td><?php echo $hApprovedBy; ?></td>
                                <td>
                                    <span class="badge-status <?php echo $badgeClass; ?>">
                                        <i class="fa <?php echo $iconClass; ?> me-1"></i><?php echo $hStatus; ?>
                                    </span>
                                </td>
                                <td style="text-align:center;">
                                    <?php if ($canApprove && $hStatus === 'Approved'): ?>
                                        <button type="button" class="btn btn-outline-primary btn-sm px-2 btn-edit-approved-req"
                                                data-reqid="<?php echo $hReqId; ?>"
                                                data-allocid="<?php echo $hAllocId; ?>"
                                                data-internid="<?php echo $hInternId; ?>"
                                                data-internname="<?php echo $hInternName; ?>"
                                                data-projectname="<?php echo $hProjectName; ?>"
                                                data-startdate="<?php echo $hStartDateRaw; ?>"
                                                data-enddate="<?php echo $hEndDateRaw; ?>"
                                                data-st="<?php echo $hStVal; ?>"
                                                data-et="<?php echo $hEtVal; ?>"
                                                style="border-radius:6px;font-size:0.75rem;font-weight:600;">
                                            <i class="fa fa-pencil me-1"></i> Edit
                                        </button>
                                    <?php else: ?>
                                        <span class="text-muted" style="font-size:0.8rem;">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div><!-- /content-wrapper -->
        </div><!-- /main-panel -->

    </div><!-- /page-body-wrapper -->
</div><!-- /container-scroller -->

<!-- Rejection Reason Modal -->
<div class="modal fade" id="inlineRejectModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-md" role="document">
    <div class="modal-content" style="border-radius:12px;border:none;">
      <div class="modal-header" style="background:linear-gradient(135deg,#e53935,#c62828);border-radius:12px 12px 0 0;padding:16px 24px;">
        <h5 class="modal-title" style="color:#fff;font-weight:700;">
          <i class="fa fa-times-circle me-2"></i> Reject Resource Request
        </h5>
        <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:0.8;">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body" style="padding:24px;">
        <p style="font-size:0.875rem;color:#555;">Reject request for
          <strong id="rej-modal-intern"></strong> on project <strong id="rej-modal-project"></strong>?
        </p>
        <div class="form-group">
          <label style="font-weight:600;font-size:0.875rem;">Rejection Reason <small class="text-muted">(Optional)</small></label>
          <textarea class="form-control" id="rej-modal-reason-text" rows="3"
                    placeholder="Enter reason for rejection…" style="resize:none;font-size:0.875rem;"></textarea>
        </div>
      </div>
      <div class="modal-footer" style="padding:12px 24px;">
        <button type="button" class="btn btn-secondary" data-dismiss="modal" style="border-radius:6px;">Cancel</button>
        <button type="button" class="btn btn-danger" id="rej-modal-confirm-btn" style="border-radius:6px;">
          Confirm Rejection
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Edit Approved Request Modal -->
<div class="modal fade" id="modalEditApprovedRequest" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-md" role="document">
    <div class="modal-content" style="border-radius:12px;border:none;box-shadow:0 10px 30px rgba(0,0,0,0.2);">
      <div class="modal-header" style="background:linear-gradient(135deg,#4b49ac,#3a39a0);border-radius:12px 12px 0 0;padding:16px 24px;">
        <h5 class="modal-title" style="color:#fff;font-weight:700;">
          <i class="fa fa-pencil-square-o me-2"></i> Edit Approved Request Details
        </h5>
        <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:0.8;">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <form id="form_edit_approved_request" method="POST" action="updateApprovedAllocationProcess.php">
        <div class="modal-body" style="padding:20px 24px;">
          <input type="hidden" name="requestId" id="edit_app_requestId" value="">
          <input type="hidden" name="allocationId" id="edit_app_allocationId" value="">
          <input type="hidden" id="edit_app_internId" value="">

          <div class="alert alert-light border mb-3" style="border-radius:8px;padding:12px 16px;">
            <div style="font-weight:700;color:#2b2b40;font-size:0.9rem;" id="edit_app_display_intern">Intern Name</div>
            <small class="text-muted d-block" id="edit_app_display_project">Project Name</small>
          </div>

          <!-- Dates -->
          <div class="form-group mb-3">
            <label style="font-weight:700;font-size:0.83rem;color:#444;">Requested Allocation Dates:</label>
            <div class="d-flex align-items-center gap-2">
              <input type="date" class="form-control form-control-sm" name="startDate" id="edit_app_startDate" required style="border-radius:6px;font-weight:600;color:#4b49ac;">
              <span style="font-weight:600;color:#888;">to</span>
              <input type="date" class="form-control form-control-sm" name="endDate" id="edit_app_endDate" required style="border-radius:6px;font-weight:600;color:#4b49ac;">
            </div>
          </div>

          <!-- Time Slot -->
          <div class="form-group mb-3">
            <label style="font-weight:700;font-size:0.83rem;color:#444;">Time Slot (Punch Window):</label>
            <div class="d-flex align-items-center gap-2">
              <input type="time" class="form-control form-control-sm" name="allocStartTime" id="edit_app_allocST" required style="border-radius:6px;font-weight:600;color:#4b49ac;">
              <span style="font-weight:600;color:#888;">to</span>
              <input type="time" class="form-control form-control-sm" name="allocEndTime" id="edit_app_allocET" required style="border-radius:6px;font-weight:600;color:#4b49ac;">
            </div>
          </div>

          <!-- Live Conflict Alert Container -->
          <div id="edit_app_conflict_container" style="margin-top:10px;"></div>
        </div>

        <div class="modal-footer" style="background:#fafbfe;border-radius:0 0 12px 12px;padding:12px 24px;">
          <button type="button" class="btn btn-secondary btn-sm px-3" data-dismiss="modal" style="border-radius:6px;font-weight:600;">Cancel</button>
          <button type="submit" id="btn_save_approved_edit" class="btn btn-primary btn-sm px-4" style="border-radius:6px;font-weight:600;background:#4b49ac;">
            <i class="fa fa-save me-1"></i> Save Changes
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Core scripts -->
<script src="../vendors/js/vendor.bundle.base.js"></script>
<script src="../js/off-canvas.js"></script>
<script src="../js/hoverable-collapse.js"></script>
<script src="../js/template.js"></script>
<script src="../js/settings.js"></script>
<script src="../js/todolist.js"></script>

<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>

<script>
$(document).ready(function () {

    // Dynamic calculation of daily hours on time change
    $(document).on('input', '.time-st-val, .time-et-val', function() {
        var reqId = $(this).data('reqid');
        var tr = $(this).closest('tr');
        var st = tr.find('.time-st-val').val();
        var et = tr.find('.time-et-val').val();
        if (st && et) {
            var stMins = parseInt(st.split(':')[0])*60 + parseInt(st.split(':')[1]);
            var etMins = parseInt(et.split(':')[0])*60 + parseInt(et.split(':')[1]);
            if (etMins > stMins) {
                var hrs = Math.ceil((etMins - stMins) / 60);
                $('.hrs-badge-' + reqId).text(hrs + ' hrs');
            } else {
                $('.hrs-badge-' + reqId).text('Invalid time');
            }
        }
    });

    // Approve Button (Intern View)
    $(document).on('click', '.btn-inline-approve', function() {
        var reqId   = $(this).data('reqid');
        var intern  = $(this).data('intern');
        var project = $(this).data('project');
        var tr      = $(this).closest('tr');
        
        var sDate = tr.find('.date-st-val').val();
        var eDate = tr.find('.date-et-val').val();
        var st    = tr.find('.time-st-val').val();
        var et    = tr.find('.time-et-val').val();
        
        if (!sDate || !eDate) {
            alert('Please select both start and end dates.');
            return;
        }
        if (eDate < sDate) {
            alert('End date cannot be before start date.');
            return;
        }
        if (!st || !et) {
            alert('Please select both start and end times for the allocation.');
            return;
        }
        
        var confirmMsg = 'Confirm allocation of ' + intern + ' to "' + project + '"?\n' +
                         '• Dates: ' + sDate + ' to ' + eDate + '\n' +
                         '• Time: ' + st + ' - ' + et;

        if (confirm(confirmMsg)) {
            processRequestAjax(reqId, 'approve', {
                newStartDate: sDate,
                newEndDate: eDate,
                newAllocST: st,
                newAllocET: et
            });
        }
    });

    // Intern View — Reject Button
    var targetRejectReqId = null;
    $(document).on('click', '.btn-inline-reject', function() {
        targetRejectReqId = $(this).data('reqid');
        $('#rej-modal-intern').text($(this).data('intern'));
        $('#rej-modal-project').text($(this).data('project'));
        $('#rej-modal-reason-text').val('');
        $('#inlineRejectModal').data('source', 'intern').modal('show');
    });
    // Note: #rej-modal-confirm-btn handler is defined outside $(document).ready
    //       after Lead View reject handler, handling both intern & tl sources.

    // DataTables for History
    var historyTable = $('#historyTable').DataTable({
        pageLength: 10,
        lengthMenu: [[10, 25, 50, -1], [10, 25, 50, 'All']],
        ordering:   true,
        order:      [[11, 'desc']],
        info:       true,
        searching:  true,
        autoWidth:  false,
        scrollX:    true,
        language: {
            emptyTable:       "No processed requests found.",
            zeroRecords:      "No matching requests found for selected filters.",
            search:           "",
            searchPlaceholder:"Search history…",
            lengthMenu:       "Show _MENU_ entries"
        }
    });

    // Custom DataTables Filter Function for History
    $.fn.dataTable.ext.search.push(
        function( settings, data, dataIndex ) {
            if (settings.nTable.id !== 'historyTable') return true;

            var row = historyTable.row(dataIndex).node();
            var internId     = $(row).data('intern-id');
            var status       = $(row).data('status');
            var reqStartDate = $(row).data('start-date'); // YYYY-MM-DD
            var reqEndDate   = $(row).data('end-date');   // YYYY-MM-DD

            var selIntern = $('#histInternFilter').val();
            var selStatus = $('#histStatusFilter').val();
            var filterST  = $('#histStartDate').val();
            var filterET  = $('#histEndDate').val();

            // 1. Intern filter
            if (selIntern && selIntern !== 'all' && internId !== selIntern) {
                return false;
            }

            // 2. Status filter
            if (selStatus && selStatus !== 'all' && status !== selStatus) {
                return false;
            }

            // 3. Date range filter
            if (filterST && reqEndDate < filterST) {
                return false;
            }
            if (filterET && reqStartDate > filterET) {
                return false;
            }

            return true;
        }
    );

    // Filter Change Event Handlers
    $('#histInternFilter, #histStatusFilter, #histStartDate, #histEndDate').on('change', function() {
        historyTable.draw();
    });

    // Week Preset Quick Selector
    $('#histWeekFilter').on('change', function() {
        var preset = $(this).val();
        var curr = new Date();
        var day  = curr.getDay(); // 0 = Sun, 1 = Mon...
        var diffToMon = curr.getDate() - day + (day === 0 ? -6 : 1); // Adjust for Sunday

        function fmtDate(d) {
            var month = '' + (d.getMonth() + 1),
                day   = '' + d.getDate(),
                year  = d.getFullYear();
            if (month.length < 2) month = '0' + month;
            if (day.length < 2)   day   = '0' + day;
            return [year, month, day].join('-');
        }

        if (preset === 'this_week') {
            var monday = new Date(curr.setDate(diffToMon));
            var sunday = new Date(monday);
            sunday.setDate(monday.getDate() + 6);
            $('#histStartDate').val(fmtDate(monday));
            $('#histEndDate').val(fmtDate(sunday));
        } else if (preset === 'next_week') {
            var monday = new Date(curr.setDate(diffToMon + 7));
            var sunday = new Date(monday);
            sunday.setDate(monday.getDate() + 6);
            $('#histStartDate').val(fmtDate(monday));
            $('#histEndDate').val(fmtDate(sunday));
        } else if (preset === 'last_week') {
            var monday = new Date(curr.setDate(diffToMon - 7));
            var sunday = new Date(monday);
            sunday.setDate(monday.getDate() + 6);
            $('#histStartDate').val(fmtDate(monday));
            $('#histEndDate').val(fmtDate(sunday));
        } else if (preset === 'this_month') {
            var firstDay = new Date(curr.getFullYear(), curr.getMonth(), 1);
            var lastDay  = new Date(curr.getFullYear(), curr.getMonth() + 1, 0);
            $('#histStartDate').val(fmtDate(firstDay));
            $('#histEndDate').val(fmtDate(lastDay));
        } else if (preset === 'all') {
            $('#histStartDate').val('');
            $('#histEndDate').val('');
        }
        historyTable.draw();
    });

    // Reset Filters
    $('#histResetBtn').on('click', function() {
        $('#histInternFilter').val('all');
        $('#histStatusFilter').val('all');
        $('#histWeekFilter').val('all');
        $('#histStartDate').val('');
        $('#histEndDate').val('');
        historyTable.search('').draw();
    });

});

// ── Tab Switching ──
function switchView(view) {
    if (view === 'intern') {
        $('#pane-intern-view').show();
        $('#pane-lead-view').hide();
        $('#tab-btn-intern').addClass('active-intern').removeClass('active-lead');
        $('#tab-btn-lead').removeClass('active-intern active-lead');
        $('#view-count-label').text('Showing <?php echo $totalInternsAwaiting; ?> intern(s) with pending resource requests');
    } else {
        $('#pane-intern-view').hide();
        $('#pane-lead-view').show();
        $('#tab-btn-lead').addClass('active-lead').removeClass('active-intern');
        $('#tab-btn-intern').removeClass('active-intern active-lead');
        $('#view-count-label').text('Showing <?php echo $totalTLsAwaiting; ?> team lead(s) with pending resource requests');
    }
    pendingSearch($('#pend-search-input').val()); // re-apply search on tab switch
}

// ── Pending Search ──
function pendingSearch(q) {
    q = (q || '').toLowerCase().trim();
    var visibleIntern = 0, visibleTL = 0;

    $('.intern-group-card').each(function() {
        var text = $(this).text().toLowerCase();
        var show = !q || text.indexOf(q) > -1;
        $(this).toggle(show);
        if (show) visibleIntern++;
    });

    $('.tl-group-card').each(function() {
        var text = $(this).text().toLowerCase();
        var show = !q || text.indexOf(q) > -1;
        $(this).toggle(show);
        if (show) visibleTL++;
    });

    var count = $('#pane-lead-view').is(':visible') ? visibleTL : visibleIntern;
    $('#pend-search-count').text(q ? count + ' result(s)' : '');
}

$('#pend-search-input').on('input', function() {
    pendingSearch($(this).val());
});

// ── Helper AJAX Processor ──
function processRequestAjax(reqId, action, payload) {
    payload = payload || {};
    payload.isAjax    = 1;
    payload.requestId = reqId;
    payload.action    = action;

    $.ajax({
        url: 'resourceAllocationProcess.php',
        type: 'POST',
        data: payload,
        dataType: 'json',
        success: function(res) {
            if (res.status) {
                // Toast notification
                if (typeof Toast !== 'undefined') {
                    Toast.fire({
                        icon: 'success',
                        text: res.msg || (action === 'approve' ? 'Request approved successfully.' : 'Request rejected.')
                    });
                }

                // Update Approved / Rejected stat counters
                if (action === 'approve') {
                    var curApp = parseInt($('#stat-approved-num').text()) || 0;
                    $('#stat-approved-num').text(curApp + 1);
                } else if (action === 'reject') {
                    var curRej = parseInt($('#stat-rejected-num').text()) || 0;
                    $('#stat-rejected-num').text(curRej + 1);
                }

                // Fade out & remove row across all views (Intern View & Lead View)
                var rows = $('tr[data-reqid="' + reqId + '"]');
                rows.fadeOut(350, function() {
                    $(this).remove();
                    updateCardAndStatCounts();
                });
            } else {
                // Show Error via SweetAlert modal without page reload
                if (typeof sweetMsg === 'function') {
                    sweetMsg('error', res.msg);
                } else if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        title: 'Validation Alert',
                        html: '<div style="text-align:left;font-size:0.92rem;line-height:1.6;color:#333;margin-top:10px;">' + res.msg + '</div>',
                        icon: 'error',
                        confirmButtonColor: '#4b49ac',
                        confirmButtonText: 'OK, Understood'
                    });
                } else {
                    alert(res.msg);
                }
            }
        },
        error: function() {
            alert('An error occurred while processing the request. Please try again.');
        }
    });
}

function updateCardAndStatCounts() {
    // 1. Intern Group Cards
    $('.intern-group-card').each(function() {
        var card = $(this);
        var remainingRows = card.find('tbody tr').length;
        if (remainingRows === 0) {
            card.fadeOut(300, function() { $(this).remove(); checkEmptyStates(); });
        }
    });

    // 2. TL Group Cards
    $('.tl-group-card').each(function() {
        var card = $(this);
        var remainingRows = card.find('tbody tr').length;
        if (remainingRows === 0) {
            card.fadeOut(300, function() { $(this).remove(); checkEmptyStates(); });
        }
    });

    checkEmptyStates();
}

function checkEmptyStates() {
    var totalInternCards = $('.intern-group-card').length;
    var totalTLCards     = $('.tl-group-card').length;
    var totalPendingRows = $('#pane-intern-view tbody tr').length;

    // Update tab counts
    $('#tab-count-intern').text(totalInternCards);
    $('#tab-count-lead').text(totalTLCards);

    // Update header stats
    $('#stat-pending-num').text(totalPendingRows);
    $('#stat-pending-label').text('Pending Requests (' + totalInternCards + ' Interns)');

    if ($('#pane-intern-view').is(':visible')) {
        $('#view-count-label').text('Showing ' + totalInternCards + ' intern(s) with pending resource requests');
    } else {
        $('#view-count-label').text('Showing ' + totalTLCards + ' team lead(s) with pending resource requests');
    }

    if (totalInternCards === 0 && $('#pane-intern-view .empty-state-card').length === 0) {
        $('#pane-intern-view').html(
            '<div class="card mb-4 empty-state-card" style="border-radius:12px;border:1px solid var(--border);">' +
            '<div class="card-body text-center py-5">' +
            '<i class="fa fa-check-circle-o fa-3x text-success mb-3 d-block"></i>' +
            '<h5 style="font-weight:700;color:#333;">All Caught Up!</h5>' +
            '<p class="text-muted" style="font-size:0.88rem;margin:0;">There are no pending resource requests at this time.</p>' +
            '</div></div>'
        );
    }

    if (totalTLCards === 0 && $('#pane-lead-view .empty-state-card').length === 0) {
        $('#pane-lead-view').html(
            '<div class="card mb-4 empty-state-card" style="border-radius:12px;border:1px solid var(--border);">' +
            '<div class="card-body text-center py-5">' +
            '<i class="fa fa-check-circle-o fa-3x text-success mb-3 d-block"></i>' +
            '<h5 style="font-weight:700;color:#333;">All Caught Up!</h5>' +
            '<p class="text-muted" style="font-size:0.88rem;margin:0;">There are no pending resource requests at this time.</p>' +
            '</div></div>'
        );
    }
}

// ── Lead View: Approve Button ──
$(document).on('click', '.btn-tl-approve', function() {
    var reqId   = $(this).data('reqid');
    var intern  = $(this).data('intern');
    var project = $(this).data('project');
    var tr      = $(this).closest('tr');

    var sDate = tr.find('.date-st-val').val();
    var eDate = tr.find('.date-et-val').val();
    var st    = tr.find('.time-st-val').val();
    var et    = tr.find('.time-et-val').val();

    if (!sDate || !eDate) { alert('Please select both start and end dates.'); return; }
    if (eDate < sDate)    { alert('End date cannot be before start date.'); return; }
    if (!st || !et)       { alert('Please select both start and end times.'); return; }

    var confirmMsg = 'Confirm allocation of ' + intern + ' to "' + project + '"?\n' +
                     '• Dates: ' + sDate + ' to ' + eDate + '\n' +
                     '• Time: ' + st + ' - ' + et;
    if (confirm(confirmMsg)) {
        processRequestAjax(reqId, 'approve', {
            newStartDate: sDate,
            newEndDate: eDate,
            newAllocST: st,
            newAllocET: et
        });
    }
});

// ── Lead View: Reject Button ──
var tlTargetRejectId = null;
$(document).on('click', '.btn-tl-reject', function() {
    tlTargetRejectId = $(this).data('reqid');
    $('#rej-modal-intern').text($(this).data('intern'));
    $('#rej-modal-project').text($(this).data('project'));
    $('#rej-modal-reason-text').val('');
    $('#inlineRejectModal').data('source', 'tl').modal('show');
});

// Override confirm to handle both intern view and lead view via AJAX
$('#rej-modal-confirm-btn').off('click').on('click', function() {
    var source = $('#inlineRejectModal').data('source') || 'intern';
    var reqId  = (source === 'tl') ? tlTargetRejectId : targetRejectReqId;
    var reason = $('#rej-modal-reason-text').val();

    if (reqId) {
        processRequestAjax(reqId, 'reject', {
            rejectionReason: reason
        });
    }
    $('#inlineRejectModal').modal('hide');
});

// ── Real-Time Conflict Re-validation ──
function checkRowConflict(tr) {
    var reqId    = tr.data('reqid');
    var internId = tr.data('internid');
    var sDate    = tr.find('.date-st-val').val();
    var eDate    = tr.find('.date-et-val').val();
    var st       = tr.find('.time-st-val').val();
    var et       = tr.find('.time-et-val').val();

    if (!reqId || !internId || !sDate || !eDate || !st || !et) return;

    var container = $('.conflict-box-' + reqId);

    $.ajax({
        url: 'checkAllocationConflictAjax.php',
        type: 'POST',
        data: {
            internId: internId,
            startDate: sDate,
            endDate: eDate,
            allocStartTime: st,
            allocEndTime: et
        },
        dataType: 'json',
        success: function(res) {
            var matchedTrs = $('tr[data-reqid="' + reqId + '"]');
            if (!res.valid) {
                var msg = (res.errors || []).join(' | ');
                container.html(
                    '<div class="conflict-alert-badge" style="background:#fff0f0; border:1px solid #fecaca; border-radius:6px; padding:4px 8px; color:#dc2626; font-size:0.77rem; font-weight:600; display:inline-flex; align-items:center; gap:5px;">' +
                    '<i class="fa fa-exclamation-triangle"></i>' +
                    '<span>Conflict: ' + $('<div>').text(msg).html() + '</span>' +
                    '</div>'
                );
                matchedTrs.css('background-color', '#fff8f8');
            } else if (res.warnings && res.warnings.length > 0) {
                var wmsg = res.warnings.join(' | ');
                container.html(
                    '<div class="warning-alert-badge" style="background:#fffbe0; border:1px solid #fef08a; border-radius:6px; padding:4px 8px; color:#b45309; font-size:0.77rem; font-weight:600; display:inline-flex; align-items:center; gap:5px;">' +
                    '<i class="fa fa-info-circle"></i>' +
                    '<span>' + $('<div>').text(wmsg).html() + '</span>' +
                    '</div>'
                );
                matchedTrs.css('background-color', '#ffffff');
            } else {
                container.html(
                    '<div class="valid-alert-badge" style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:6px; padding:4px 8px; color:#15803d; font-size:0.77rem; font-weight:600; display:inline-flex; align-items:center; gap:5px;">' +
                    '<i class="fa fa-check-circle"></i>' +
                    '<span>Slot Available — No Conflicts</span>' +
                    '</div>'
                );
                matchedTrs.css('background-color', '#ffffff');
            }
        }
    });
}

$(document).on('change input', '.inline-date-input, .inline-time-input', function() {
    var tr = $(this).closest('tr');
    checkRowConflict(tr);
});

// ── Edit Approved Request Modal Handlers ──
$(document).on('click', '.btn-edit-approved-req', function() {
    var btn = $(this);
    $('#edit_app_requestId').val(btn.data('reqid'));
    $('#edit_app_allocationId').val(btn.data('allocid'));
    $('#edit_app_internId').val(btn.data('internid'));
    $('#edit_app_display_intern').text(btn.data('internname'));
    $('#edit_app_display_project').text('Project: ' + btn.data('projectname'));
    $('#edit_app_startDate').val(btn.data('startdate'));
    $('#edit_app_endDate').val(btn.data('enddate'));
    $('#edit_app_allocST').val(btn.data('st'));
    $('#edit_app_allocET').val(btn.data('et'));

    $('#modalEditApprovedRequest').modal('show');
    validateApprovedEditModal();
});

function validateApprovedEditModal() {
    var internId  = $('#edit_app_internId').val();
    var allocId   = $('#edit_app_allocationId').val();
    var sDate     = $('#edit_app_startDate').val();
    var eDate     = $('#edit_app_endDate').val();
    var st        = $('#edit_app_allocST').val();
    var et        = $('#edit_app_allocET').val();
    var container = $('#edit_app_conflict_container');

    if (!internId || !sDate || !eDate || !st || !et) return;

    $.ajax({
        url: 'checkAllocationConflictAjax.php',
        type: 'POST',
        data: {
            internId: internId,
            startDate: sDate,
            endDate: eDate,
            allocStartTime: st,
            allocEndTime: et,
            excludeAllocationId: allocId
        },
        dataType: 'json',
        success: function(res) {
            if (!res.valid) {
                var msg = (res.errors || []).join(' | ');
                container.html(
                    '<div style="background:#fff0f0; border:1px solid #fecaca; border-radius:6px; padding:8px 12px; color:#dc2626; font-size:0.8rem; font-weight:600;">' +
                    '<i class="fa fa-exclamation-triangle me-1"></i> Conflict: ' + $('<div>').text(msg).html() +
                    '</div>'
                );
                $('#btn_save_approved_edit').prop('disabled', true);
            } else if (res.warnings && res.warnings.length > 0) {
                var wmsg = res.warnings.join(' | ');
                container.html(
                    '<div style="background:#fffbe0; border:1px solid #fef08a; border-radius:6px; padding:8px 12px; color:#b45309; font-size:0.8rem; font-weight:600;">' +
                    '<i class="fa fa-info-circle me-1"></i> Note: ' + $('<div>').text(wmsg).html() +
                    '</div>'
                );
                $('#btn_save_approved_edit').prop('disabled', false);
            } else {
                container.html(
                    '<div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:6px; padding:8px 12px; color:#15803d; font-size:0.8rem; font-weight:600;">' +
                    '<i class="fa fa-check-circle me-1"></i> Slot Available — No Conflicts' +
                    '</div>'
                );
                $('#btn_save_approved_edit').prop('disabled', false);
            }
        }
    });
}

$(document).on('change input', '#edit_app_startDate, #edit_app_endDate, #edit_app_allocST, #edit_app_allocET', function() {
    validateApprovedEditModal();
});
</script>

<?php
require_once('../include/sweetAlert.php');
if (isset($_SESSION['status'])) {
    $sessionStatus = $_SESSION['status'];
    $msg           = $_SESSION['msg'];
    if ($sessionStatus != true) {
        sweetMsg('error', $msg);
    } else {
        toastMsg('success', $msg);
    }
    unset($_SESSION['status']);
    unset($_SESSION['msg']);
}
?>

</body>
</html>
