<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    header("Location: login.php");
    exit();
}
include('../include/db_config.php');
date_default_timezone_set('Asia/Kolkata');

// Check access - only HR, Admin, Resource Head
$currentUserId = $_SESSION['userId'];
$accessQ = mysqli_query($con, "SELECT designation, userRole, fullName FROM signup WHERE userId = '$currentUserId' LIMIT 1");
$accessRow = mysqli_fetch_assoc($accessQ);
$currentDesignation = strtolower(trim($accessRow['designation'] ?? ''));
$currentUserRole    = strtolower(trim($accessRow['userRole'] ?? ''));
$currentFullName    = $accessRow['fullName'] ?? 'HR Admin';

$allowedRoles = ['admin', 'superadmin', 'hr', 'resourceallocator', 'resource_allocator', 'resource allocator', 'resourcehead', 'resource_head'];
if (!in_array($currentDesignation, $allowedRoles) && !in_array($currentUserRole, $allowedRoles)) {
    echo "<script>alert('Access Denied'); window.location.href='login.php';</script>";
    exit();
}

// Handle POST - Extend internship
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['extend_action'])) {
    $internUserId = mysqli_real_escape_string($con, $_POST['intern_id']);
    $newEndDate = mysqli_real_escape_string($con, $_POST['new_end_date']);
    $reason = mysqli_real_escape_string($con, $_POST['extension_reason']);

    // Get current end date
    $curQ = mysqli_query($con, "SELECT end_date, fullName FROM signup WHERE userId = '$internUserId' LIMIT 1");
    $curRow = mysqli_fetch_assoc($curQ);
    $oldEndDate = $curRow['end_date'];
    $internName = $curRow['fullName'];

    if (empty($newEndDate) || strtotime($newEndDate) <= strtotime($oldEndDate)) {
        $_SESSION['status'] = false;
        $_SESSION['msg'] = "New end date must be after current end date ($oldEndDate).";
        header("Location: internship-extension.php");
        exit();
    }

    // Update signup table
    $updateQ = mysqli_query($con, "UPDATE signup SET end_date = '$newEndDate' WHERE userId = '$internUserId'");

    if ($updateQ) {
        // Insert history into internshipExtension table
        $changedByRole = $currentDesignation;
        $insertHistory = mysqli_query($con, "
            INSERT INTO internshipExtension (internId, oldEndDate, newEndDate, reason, changedBy, changedByRole, changedAt)
            VALUES ('$internUserId', '$oldEndDate', '$newEndDate', '$reason', '$currentUserId', '$changedByRole', NOW())
        ");

        // Send notification to the intern
        $notifTitle = "Internship Extended 🎉";
        $oldF = date('d-m-Y', strtotime($oldEndDate));
        $newF = date('d-m-Y', strtotime($newEndDate));
        $notifBody = "Hello $internName 👋\nYour internship has been extended from $oldF to $newF by $currentFullName.";
        $notifStatus = 'sent';
        $now = date('Y-m-d H:i:s');

        $stmt = $con->prepare("INSERT INTO notifications (userId, title, message, status, is_read, created_at, type) VALUES (?, ?, ?, ?, 0, ?, 'extension')");
        if ($stmt) {
            $stmt->bind_param('sssss', $internUserId, $notifTitle, $notifBody, $notifStatus, $now);
            $stmt->execute();
            $stmt->close();
        }

        // FCM push notification
        $tokenQ = mysqli_query($con, "SELECT token FROM signup WHERE userId = '$internUserId' LIMIT 1");
        $tokenRow = mysqli_fetch_assoc($tokenQ);
        if (!empty($tokenRow['token'])) {
            include_once('../include/notifications.php');
            if (isset($accessToken) && isset($projectId)) {
                sendFCMNotification($tokenRow['token'], $notifTitle, $notifBody, $accessToken, $projectId);
            }
        }

        $_SESSION['status'] = true;
        $_SESSION['msg'] = "Internship for $internName extended successfully to " . date('d-m-Y', strtotime($newEndDate)) . ".";
    } else {
        $_SESSION['status'] = false;
        $_SESSION['msg'] = "Error extending internship: " . mysqli_error($con);
    }

    header("Location: internship-extension.php");
    exit();
}

// ── 1. Analytics & Metrics Queries ───────────────────────────────────────────
$totalExtensions = (int)mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as c FROM internshipExtension"))['c'];
$totalDaysExt = (int)mysqli_fetch_assoc(mysqli_query($con, "SELECT COALESCE(SUM(DATEDIFF(newEndDate, oldEndDate)), 0) as s FROM internshipExtension"))['s'];
$avgDaysExt = ($totalExtensions > 0) ? round($totalDaysExt / $totalExtensions, 1) : 0;
$currentMonthExt = (int)mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as c FROM internshipExtension WHERE MONTH(changedAt) = MONTH(CURRENT_DATE()) AND YEAR(changedAt) = YEAR(CURRENT_DATE())"))['c'];
$activeInternsCount = (int)mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as c FROM signup WHERE status = 'active' AND designation IN ('juniorIntern', 'seniorIntern')"))['c'];

// Extension monthly trend (last 6 months)
$trendLabels = [];
$trendCounts = [];
$trendQ = mysqli_query($con, "
    SELECT DATE_FORMAT(changedAt, '%b %Y') as monthYear, COUNT(*) as cnt
    FROM internshipExtension
    GROUP BY DATE_FORMAT(changedAt, '%Y-%m'), DATE_FORMAT(changedAt, '%b %Y')
    ORDER BY DATE_FORMAT(changedAt, '%Y-%m') ASC
    LIMIT 6
");
if ($trendQ) {
    while ($tr = mysqli_fetch_assoc($trendQ)) {
        $trendLabels[] = $tr['monthYear'];
        $trendCounts[] = (int)$tr['cnt'];
    }
}
if (empty($trendLabels)) {
    $trendLabels = [date('b Y')];
    $trendCounts = [0];
}

// Extensions by Designation
$desgCounts = ['Junior Intern' => 0, 'Senior Intern' => 0, 'Employee / Other' => 0];
$desgQ = mysqli_query($con, "
    SELECT s.designation, COUNT(*) as cnt
    FROM internshipExtension ie
    JOIN signup s ON s.userId COLLATE utf8mb4_unicode_ci = ie.internId
    GROUP BY s.designation
");
if ($desgQ) {
    while ($dr = mysqli_fetch_assoc($desgQ)) {
        $d = strtolower(trim($dr['designation'] ?? ''));
        if ($d === 'juniorintern') $desgCounts['Junior Intern'] += (int)$dr['cnt'];
        elseif ($d === 'seniorintern') $desgCounts['Senior Intern'] += (int)$dr['cnt'];
        else $desgCounts['Employee / Other'] += (int)$dr['cnt'];
    }
}

// Fetch active interns for dropdown
$activeUsers = mysqli_query($con, "
    SELECT userId, fullName, designation, employment_type
    FROM signup
    WHERE status = 'active'
    AND designation IN ('juniorIntern', 'seniorIntern')
    ORDER BY fullName ASC
");

// Fetch extension history
$historyQ = mysqli_query($con, "
    SELECT ie.id, ie.internId, ie.oldEndDate, ie.newEndDate, ie.reason, ie.changedBy, ie.changedByRole, ie.changedAt,
           s1.fullName AS internName, s1.designation AS internDesignation, s1.employment_type,
           s2.fullName AS changedByName
    FROM internshipExtension ie
    LEFT JOIN signup s1 ON s1.userId COLLATE utf8mb4_unicode_ci = ie.internId
    LEFT JOIN signup s2 ON s2.userId COLLATE utf8mb4_unicode_ci = ie.changedBy
    ORDER BY ie.changedAt DESC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <title>SETTribe · Internship Extension Analytics</title>
    <?php include('../partials/_links.php'); ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.4.1/css/responsive.bootstrap5.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary: #4f46e5;
            --primary-dark: #3730a3;
            --primary-light: #eef2ff;
            --success: #10b981;
            --success-light: #ecfdf5;
            --warning: #f59e0b;
            --warning-light: #fffbeb;
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
        .icon-green  { background: var(--success-light); color: var(--success); }
        .icon-purple { background: #f3e8ff; color: var(--purple); }

        .kpi-value { font-size: 1.7rem; font-weight: 800; color: #0f172a; line-height: 1.1; margin-bottom: 6px; }
        .kpi-sub { font-size: 0.75rem; color: #64748b; font-weight: 600; }

        /* ── Report Card ── */
        .analytics-table-card {
            background: #ffffff;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            padding: 24px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.03);
            margin-bottom: 30px;
        }

        .btn-extend {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: #fff;
            border: none; border-radius: var(--radius-md); padding: 10px 28px; font-weight: 700;
            font-size: .85rem; cursor: pointer; transition: all .2s; letter-spacing: .3px;
        }
        .btn-extend:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(79,70,229,.3); color: #fff; }
        .btn-extend:disabled { opacity: .5; cursor: not-allowed; transform: none; }

        .date-info-card {
            background: linear-gradient(135deg, #f8fafc, #eef2ff); border: 1px solid #c7d2fe;
            border-radius: 10px; padding: 16px 20px; margin-top: 12px; display: none;
        }
        .date-info-card .info-label { font-size: .76rem; color: #64748b; font-weight: 600; margin-bottom:2px; }
        .date-info-card .info-value { font-size: .88rem; color: #0f172a; font-weight: 700; }

        .badge-type { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: .72rem; font-weight: 700; }
        .badge-intern { background: #dbeafe; color: #1e40af; }
        .badge-employee { background: #d1fae5; color: #065f46; }

        /* History table */
        #extensionHistoryTable { width: 100% !important; font-size: .84rem; border-collapse: separate; border-spacing: 0; }
        #extensionHistoryTable thead th {
            background: linear-gradient(135deg, #1e293b, #334155); color: #fff; padding: 12px 14px; font-weight: 700;
            font-size: .78rem; text-transform: uppercase; letter-spacing: .4px; border: none; text-align:center;
        }
        #extensionHistoryTable thead th:first-child { border-top-left-radius: 8px; }
        #extensionHistoryTable thead th:last-child { border-top-right-radius: 8px; }
        #extensionHistoryTable tbody td {
            padding: 11px 14px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; text-align:center; color:#334155;
        }
        #extensionHistoryTable tbody tr:hover td { background: #f8fafc !important; }
        .badge-ext { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: .75rem; font-weight: 700; }
        .badge-old { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .badge-new { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .dt-buttons .btn { background: #10b981 !important; color: #fff !important; border: none !important; border-radius: 6px !important; font-size: .8rem !important; font-weight: 700 !important; padding: 6px 16px !important; }
        .dt-buttons .btn:hover { background: #059669 !important; }
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
                                    <i class="fas fa-calendar-plus"></i> Internship &amp; Tenure Management
                                </div>
                                <h2 style="font-size: 1.65rem; font-weight: 800; margin-bottom: 4px;">
                                    Internship &amp; Employment Extension Analytics
                                </h2>
                                <p style="font-size: 0.85rem; opacity: 0.88; margin: 0;">
                                    Extend intern/employee end dates, audit past adjustments, and track tenure duration metrics.
                                </p>
                            </div>
                            <div>
                                <a href="active-interns.php" class="btn btn-light text-primary font-weight-bold" style="border-radius:10px; font-size:0.82rem;">
                                    <i class="fas fa-users mr-1"></i> Active Interns Roster
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- ── KPI METRICS ROW ── -->
                    <div class="row mb-4">
                        <div class="col-xl-2 col-md-4 col-sm-6 mb-3 mb-xl-0">
                            <div class="kpi-card kpi-blue">
                                <div class="kpi-header">
                                    <span class="kpi-title">Total Extensions</span>
                                    <div class="kpi-icon-wrap icon-blue"><i class="fas fa-clock-rotate-left"></i></div>
                                </div>
                                <div>
                                    <div class="kpi-value"><?= $totalExtensions ?></div>
                                    <div class="kpi-sub">Total Date Extensions</div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-3 col-md-4 col-sm-6 mb-3 mb-xl-0">
                            <div class="kpi-card kpi-cyan">
                                <div class="kpi-header">
                                    <span class="kpi-title">Total Days Extended</span>
                                    <div class="kpi-icon-wrap icon-cyan"><i class="fas fa-calendar-day"></i></div>
                                </div>
                                <div>
                                    <div class="kpi-value">+<?= number_format($totalDaysExt) ?></div>
                                    <div class="kpi-sub">Cumulative Added Days</div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-2 col-md-4 col-sm-6 mb-3 mb-xl-0">
                            <div class="kpi-card kpi-amber">
                                <div class="kpi-header">
                                    <span class="kpi-title">Avg Extension</span>
                                    <div class="kpi-icon-wrap icon-amber"><i class="fas fa-chart-line"></i></div>
                                </div>
                                <div>
                                    <div class="kpi-value">+<?= $avgDaysExt ?> <small style="font-size:0.9rem; font-weight:700;">Days</small></div>
                                    <div class="kpi-sub">Average Extension Period</div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-2 col-md-6 col-sm-6 mb-3 mb-xl-0">
                            <div class="kpi-card kpi-green">
                                <div class="kpi-header">
                                    <span class="kpi-title">This Month</span>
                                    <div class="kpi-icon-wrap icon-green"><i class="fas fa-calendar-check"></i></div>
                                </div>
                                <div>
                                    <div class="kpi-value"><?= $currentMonthExt ?></div>
                                    <div class="kpi-sub">Processed This Month</div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-3 col-md-6 col-sm-6 mb-3 mb-xl-0">
                            <div class="kpi-card kpi-purple">
                                <div class="kpi-header">
                                    <span class="kpi-title">Active Intern Roster</span>
                                    <div class="kpi-icon-wrap icon-purple"><i class="fas fa-user-check"></i></div>
                                </div>
                                <div>
                                    <div class="kpi-value"><?= $activeInternsCount ?></div>
                                    <div class="kpi-sub">Active Junior &amp; Senior Interns</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── CHARTS ROW ── -->
                    <div class="row mb-4">
                        <!-- Extension Activity Timeline -->
                        <div class="col-lg-7 mb-3 mb-lg-0">
                            <div class="analytics-table-card h-100" style="padding:20px;">
                                <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
                                    <h5 style="font-size:.95rem; font-weight:800; color:#0f172a; margin:0;">
                                        <i class="fas fa-chart-bar text-primary mr-1"></i> Extension Activity Timeline
                                    </h5>
                                    <span class="badge badge-light border" style="font-size:.72rem;">Monthly Adjustments</span>
                                </div>
                                <div style="position: relative; height: 230px;">
                                    <canvas id="extensionTrendChart"></canvas>
                                </div>
                            </div>
                        </div>

                        <!-- Designation Breakdown Chart -->
                        <div class="col-lg-5">
                            <div class="analytics-table-card h-100" style="padding:20px;">
                                <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
                                    <h5 style="font-size:.95rem; font-weight:800; color:#0f172a; margin:0;">
                                        <i class="fas fa-chart-pie text-purple mr-1"></i> Extensions by Designation
                                    </h5>
                                    <span class="badge badge-light border" style="font-size:.72rem;">Intern / Role Distribution</span>
                                </div>
                                <div style="position: relative; height: 230px;">
                                    <canvas id="designationDoughnutChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── EXTENSION FORM CARD ── -->
                    <div class="analytics-table-card mb-4">
                        <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
                            <h5 style="font-size:1.05rem; font-weight:800; color:#0f172a; margin:0;">
                                <i class="fas fa-calendar-plus text-primary mr-1"></i> Extend Internship / Employment End Date
                            </h5>
                            <span class="badge badge-primary font-weight-bold" style="font-size:.75rem;">HR Executive Action</span>
                        </div>

                        <form method="POST" action="" id="extensionForm">
                            <input type="hidden" name="extend_action" value="1">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <div class="form-group mb-0">
                                        <label style="font-weight:700; font-size:.85rem; color:#334155;">Select Intern / Employee <span class="text-danger">*</span></label>
                                        <select class="form-control" name="intern_id" id="internSelect" required>
                                            <option value="" selected disabled>-- Search &amp; Select Person --</option>
                                            <?php while ($u = mysqli_fetch_assoc($activeUsers)) { ?>
                                                <option value="<?= $u['userId'] ?>"
                                                    data-type="<?= $u['employment_type'] ?>"
                                                    data-desg="<?= $u['designation'] ?>">
                                                    <?= htmlspecialchars($u['fullName']) ?>
                                                    (<?= ucfirst($u['employment_type']) ?> - <?= ucfirst($u['designation']) ?>)
                                                </option>
                                            <?php } ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="form-group mb-0">
                                        <label style="font-weight:700; font-size:.85rem; color:#334155;">Reason for Extension <span class="text-danger">*</span></label>
                                        <textarea class="form-control" name="extension_reason" id="extensionReason" rows="2" placeholder="Provide justification for date extension..." required style="border-radius:8px;"></textarea>
                                    </div>
                                </div>
                            </div>

                            <!-- Live Date Info Card -->
                            <div class="date-info-card" id="dateInfoCard">
                                <div class="row align-items-center">
                                    <div class="col-md-3 mb-2 mb-md-0">
                                        <div class="info-label"><i class="fas fa-user text-primary mr-1"></i> Candidate Name</div>
                                        <div class="info-value" id="infoName">-</div>
                                    </div>
                                    <div class="col-md-2 mb-2 mb-md-0">
                                        <div class="info-label"><i class="fas fa-id-badge text-info mr-1"></i> Employment Type</div>
                                        <div class="info-value" id="infoType">-</div>
                                    </div>
                                    <div class="col-md-2 mb-2 mb-md-0">
                                        <div class="info-label"><i class="fas fa-calendar-alt text-muted mr-1"></i> Joining Date</div>
                                        <div class="info-value" id="infoJoining">-</div>
                                    </div>
                                    <div class="col-md-2 mb-2 mb-md-0">
                                        <div class="info-label"><i class="fas fa-calendar-times text-danger mr-1"></i> Current End Date</div>
                                        <div class="info-value text-danger" id="infoEndDate">-</div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-group mb-0">
                                            <label style="font-weight:700; font-size:.8rem; color:#0f172a;">New End Date <span class="text-danger">*</span></label>
                                            <input type="date" class="form-control" name="new_end_date" id="newEndDate" required disabled style="border-radius:8px; font-weight:700;">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-3 text-right">
                                <button type="submit" class="btn-extend" id="extendBtn" disabled>
                                    <i class="fas fa-calendar-plus mr-1"></i> Save Date Extension
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- ── EXTENSION HISTORY DATATABLE ── -->
                    <div class="analytics-table-card">
                        <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                            <div>
                                <h5 class="mb-0 font-weight-bold" style="color:#0f172a; font-size:1.05rem;">
                                    <i class="fas fa-history text-primary mr-1"></i> Extension Audit Trail &amp; Log History
                                </h5>
                                <p style="font-size:0.8rem; color:#64748b; margin:2px 0 0 0;">
                                    Comprehensive audit trail of all end-date modifications and duration extensions.
                                </p>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table id="extensionHistoryTable" class="table table-hover dt-responsive nowrap w-100">
                                <thead>
                                    <tr>
                                        <th></th>
                                        <th>Sr.</th>
                                        <th style="text-align:left;">Intern / Employee Name</th>
                                        <th>Type</th>
                                        <th>Designation</th>
                                        <th>Previous End Date</th>
                                        <th>New End Date</th>
                                        <th>Duration Added</th>
                                        <th style="text-align:left;">Extension Reason</th>
                                        <th>Modified By</th>
                                        <th>Action Timestamp</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $sr = 1;
                                    if ($historyQ && mysqli_num_rows($historyQ) > 0) {
                                        while ($h = mysqli_fetch_assoc($historyQ)) {
                                            $daysExt = (strtotime($h['newEndDate']) - strtotime($h['oldEndDate'])) / 86400;
                                            $typeBadge = ($h['employment_type'] == 'Intern')
                                                ? '<span class="badge-type badge-intern">Intern</span>'
                                                : '<span class="badge-type badge-employee">Employee</span>';
                                            $oldF = date('d M Y', strtotime($h['oldEndDate']));
                                            $newF = date('d M Y', strtotime($h['newEndDate']));
                                            $changedAtF = date('d M Y, h:i A', strtotime($h['changedAt']));
                                            $internName = !empty($h['internName']) ? htmlspecialchars($h['internName']) : $h['internId'];
                                            $changedByN = !empty($h['changedByName']) ? htmlspecialchars($h['changedByName']) : $h['changedBy'];
                                            $desg = ucfirst($h['internDesignation'] ?? '-');
                                            $reasonTxt = !empty($h['reason']) ? htmlspecialchars($h['reason']) : '-';

                                            echo "<tr>
                                                <td></td>
                                                <td class='font-weight-bold text-muted'>{$sr}</td>
                                                <td style='text-align:left;'><strong style='color:#0f172a;'>{$internName}</strong></td>
                                                <td>{$typeBadge}</td>
                                                <td><span class='badge badge-light border'>{$desg}</span></td>
                                                <td><span class='badge-ext badge-old'>{$oldF}</span></td>
                                                <td><span class='badge-ext badge-new'>{$newF}</span></td>
                                                <td><span class='badge badge-success' style='font-size:.76rem; font-weight:800;'>+{$daysExt} Days</span></td>
                                                <td style='text-align:left; font-size:.8rem; max-width:220px; white-space:normal;'>{$reasonTxt}</td>
                                                <td><strong>{$changedByN}</strong><br><small class='text-muted'>(" . ucfirst($h['changedByRole'] ?? '') . ")</small></td>
                                                <td style='font-size:.78rem;'>{$changedAtF}</td>
                                            </tr>";
                                            $sr++;
                                        }
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <script src="../vendors/js/vendor.bundle.base.js"></script>
    <script src="../vendors/chart.js/Chart.min.js"></script>
    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>
    <script src="../js/settings.js"></script>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.4.1/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.4.1/js/responsive.bootstrap5.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
    <script src="../vendors/select2/select2.min.js"></script>

    <script>
    $(document).ready(function () {

        // Searchable dropdown
        $('#internSelect').select2({
            placeholder: '-- Search & Select Person --',
            allowClear: true,
            width: '100%'
        });

        // 1. Extension Trend Timeline Bar Chart
        var ctxTrend = document.getElementById('extensionTrendChart').getContext('2d');
        new Chart(ctxTrend, {
            type: 'bar',
            data: {
                labels: <?= json_encode($trendLabels) ?>,
                datasets: [{
                    label: 'Extensions Granted',
                    data: <?= json_encode($trendCounts) ?>,
                    backgroundColor: '#4f46e5',
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

        // 2. Designation Breakdown Doughnut Chart
        var ctxDesg = document.getElementById('designationDoughnutChart').getContext('2d');
        new Chart(ctxDesg, {
            type: 'doughnut',
            data: {
                labels: ['Junior Intern', 'Senior Intern', 'Employee / Other'],
                datasets: [{
                    data: [
                        <?= $desgCounts['Junior Intern'] ?>,
                        <?= $desgCounts['Senior Intern'] ?>,
                        <?= $desgCounts['Employee / Other'] ?>
                    ],
                    backgroundColor: ['#3b82f6', '#8b5cf6', '#10b981'],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                legend: { position: 'right', labels: { boxWidth: 12, fontSize: 11 } }
            }
        });

        // DataTable with Export
        $('#extensionHistoryTable').DataTable({
            paging: true, searching: true, ordering: true, info: true,
            responsive: {
                details: { display: $.fn.dataTable.Responsive.display.childRow, type: 'column', target: 0 }
            },
            columnDefs: [
                { className: 'control', orderable: false, targets: 0 },
                { responsivePriority: 1, targets: [2] },
                { responsivePriority: 2, targets: [5, 6] },
                { responsivePriority: 3, targets: [7, 9, 10] },
                { responsivePriority: 4, targets: [3, 4, 8] },
                { responsivePriority: 5, targets: [1] }
            ],
            order: [[10, 'desc']],
            pageLength: 10,
            lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "All"]],
            autoWidth: false,
            dom: 'Bfrtip',
            buttons: [{
                extend: 'excelHtml5',
                text: '<i class="fa-solid fa-file-excel"></i> Export to Excel',
                className: 'btn btn-success btn-sm',
                title: 'Internship Extension History - SETTribe AMS',
                exportOptions: { columns: [1, 2, 3, 4, 5, 6, 7, 8, 9, 10] }
            }],
            language: { emptyTable: "No extension records found" }
        });

        // AJAX - Fetch dates on intern select
        $('#internSelect').on('change', function () {
            var userId = $(this).val();
            if (!userId) return;

            $('#dateInfoCard').slideDown(300);
            $('#newEndDate').prop('disabled', true).val('');
            $('#extendBtn').prop('disabled', true);
            $('#infoName').text('Loading...');

            $.ajax({
                url: '../ajax/getInternDates.php',
                type: 'POST',
                data: { userId: userId },
                dataType: 'json',
                success: function (res) {
                    if (res.success) {
                        $('#infoName').text(res.fullName);
                        var typeLabel = (res.employment_type === 'Intern')
                            ? '<span class="badge-type badge-intern">Intern</span>'
                            : '<span class="badge-type badge-employee">Employee</span>';
                        $('#infoType').html(typeLabel);

                        if (res.start_date) {
                            var sd = new Date(res.start_date);
                            $('#infoJoining').text(sd.toLocaleDateString('en-IN', {day:'2-digit', month:'2-digit', year:'numeric'}));
                        } else {
                            $('#infoJoining').text('Not Set');
                        }

                        if (res.end_date) {
                            var ed = new Date(res.end_date);
                            $('#infoEndDate').text(ed.toLocaleDateString('en-IN', {day:'2-digit', month:'2-digit', year:'numeric'}));
                            var minDate = new Date(ed);
                            minDate.setDate(minDate.getDate() + 1);
                            var minStr = minDate.toISOString().split('T')[0];
                            $('#newEndDate').attr('min', minStr).prop('disabled', false);
                        } else {
                            $('#infoEndDate').text('Not Set');
                            var today = new Date();
                            today.setDate(today.getDate() + 1);
                            $('#newEndDate').attr('min', today.toISOString().split('T')[0]).prop('disabled', false);
                        }
                        $('#extendBtn').prop('disabled', false);
                    } else {
                        Swal.fire('Error', res.error || 'Could not fetch data', 'error');
                    }
                },
                error: function () {
                    Swal.fire('Error', 'Failed to connect to server', 'error');
                }
            });
        });

        // Form validation & confirm
        $('#extensionForm').on('submit', function (e) {
            e.preventDefault();
            var form = this;
            var internName = $('#internSelect option:selected').text().trim();
            var newDate = $('#newEndDate').val();
            var reason = $('#extensionReason').val().trim();

            if (!$('#internSelect').val() || !newDate || !reason) {
                Swal.fire('Validation Error', 'Please fill all required fields.', 'error');
                return false;
            }

            var formattedDate = new Date(newDate).toLocaleDateString('en-IN', {day:'2-digit', month:'2-digit', year:'numeric'});

            Swal.fire({
                title: 'Confirm Extension',
                html: 'Extend <strong>' + internName + '</strong>\'s end date to <strong>' + formattedDate + '</strong>?<br><br><small>Reason: ' + reason + '</small>',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#4f46e5',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, Extend',
                cancelButtonText: 'Cancel'
            }).then(function (result) {
                if (result.isConfirmed) {
                    $('#extendBtn').prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Processing...');
                    form.submit();
                }
            });
        });
    });
    </script>

    <?php
    if (isset($_SESSION['status']) && isset($_SESSION['msg'])) {
        $status = $_SESSION['status'];
        $msg = $_SESSION['msg'];
    ?>
    <script>
    window.onload = function () {
        Swal.fire({
            icon: '<?= $status ? "success" : "error" ?>',
            title: '<?= $status ? "Success" : "Error" ?>',
            text: '<?= addslashes($msg) ?>',
            confirmButtonColor: '#4f46e5'
        });
    };
    </script>
    <?php
        unset($_SESSION['status']);
        unset($_SESSION['msg']);
    }
    ?>
</body>
</html>
