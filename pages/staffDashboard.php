<?php
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', 2592000); // 30 days
    session_set_cookie_params(2592000);          // 30 days
    session_start();
}
date_default_timezone_set('Asia/Kolkata');
error_reporting(E_ALL);
ini_set('display_errors', 0);

// ── Auth & role guard ──
if (empty($_SESSION['userId'])) {
    echo "<script>window.location.href='login.php';</script>";
    exit;
}
include('../include/checkPasswordStatus.php');

$userId      = $_SESSION['userId'];
$userRole    = strtolower(trim($_SESSION['userRole'] ?? ''));
$designation = strtolower(trim($_SESSION['designation'] ?? ''));
$empType     = strtolower(trim($_SESSION['employment_type'] ?? ''));

// Guard: Only staff / employee accounts allowed
$allowed = ($userRole === 'staff' || $userRole === 'employee' || $designation === 'staff' || $empType === 'staff');
if (!$allowed) {
    echo '<script>alert("You are not authorized to access this page.");window.location.href="login.php";</script>';
    die();
}

include('../include/db_config.php');

// ── User details ──
$userIdEsc = mysqli_real_escape_string($con, $userId);
$userQ = mysqli_query($con, "SELECT * FROM signup WHERE userId = '$userIdEsc' LIMIT 1");
$userData = mysqli_fetch_assoc($userQ) ?? [];

$userName    = $userData['fullName'] ?? $_SESSION['fullName'] ?? 'Staff Member';
$userOffice  = $userData['office']   ?? 'Main Office';
$userMode    = $userData['mode']     ?? 'Offline';
$expectedIn  = $userData['expectedPunchIn']  ?? '09:30:00';
$expectedOut = $userData['expectedPunchOut'] ?? '18:30:00';

// ── Dates ──
$today      = date('Y-m-d');
$firstDate  = date('Y-m-01');
$weekStart  = date('Y-m-d', strtotime('monday this week'));
$monthStart = date('Y-m-01');
$monthEnd   = date('Y-m-t');

// Greeting helper
$hour = (int)date('H');
$greeting = $hour < 12 ? 'Good Morning' : ($hour < 17 ? 'Good Afternoon' : 'Good Evening');

// ── Today's Attendance Check ──
$todayAttQ = mysqli_query($con, "SELECT * FROM attendance WHERE userId = '$userIdEsc' AND date = '$today' LIMIT 1");
$todayAtt  = ($todayAttQ && mysqli_num_rows($todayAttQ) > 0) ? mysqli_fetch_assoc($todayAttQ) : [];

$punchedIn  = !empty($todayAtt['punchInTime']) && $todayAtt['punchInTime'] !== '00:00:00';
$punchedOut = !empty($todayAtt['punchOutTime']) && $todayAtt['punchOutTime'] !== '00:00:00';

$punchInDisplay  = $punchedIn  ? date('h:i A', strtotime($todayAtt['punchInTime']))  : 'Not Punched In';
$punchOutDisplay = $punchedOut ? date('h:i A', strtotime($todayAtt['punchOutTime'])) : 'Not Punched Out';

$todayManHours = $todayAtt['manHours'] ?? '00:00:00';

// Punch URL based on Mode
$punchInUrl  = $userMode === 'Online' ? 'captureSelfie.php?punch=in'  : 'qrCodeScanner_copy.php?punch=In';
$punchOutUrl = $userMode === 'Online' ? 'captureSelfie.php?punch=out' : 'qrCodeScanner_copy.php?punch=Out';

// ── Monthly Attendance Analytics ──
$monthStatsQ = mysqli_query($con, "
    SELECT 
        COUNT(*) as total_days,
        SUM(CASE WHEN status = 'Present' OR status = 'Late' THEN 1 ELSE 0 END) as present_days,
        SUM(CASE WHEN status = 'Absent' THEN 1 ELSE 0 END) as absent_days,
        SUM(CASE WHEN (lateMark = 1 OR lateMark = 'yes' OR status = 'Late') 
                 AND date NOT IN (SELECT date FROM attendance_regularization WHERE user_id = '$userIdEsc' AND status = 'Approved')
            THEN 1 ELSE 0 END) as late_days,
        SUM(TIME_TO_SEC(manHours)) as total_seconds
    FROM attendance 
    WHERE userId = '$userIdEsc' 
    AND date >= '$monthStart' AND date <= '$monthEnd'
");
$monthStats = ($monthStatsQ && mysqli_num_rows($monthStatsQ) > 0) ? mysqli_fetch_assoc($monthStatsQ) : [];

$presentDays  = (int)($monthStats['present_days'] ?? 0);
$lateDays     = (int)($monthStats['late_days'] ?? 0);
$totalSeconds = (int)($monthStats['total_seconds'] ?? 0);

$totalHours = intdiv($totalSeconds, 3600);
$totalMins  = intdiv($totalSeconds % 3600, 60);

// ── Approved Leaves & WFH this month ──
$leaveQ = mysqli_query($con, "
    SELECT COUNT(*) as cnt 
    FROM leaves 
    WHERE userId = '$userIdEsc' 
      AND status = 'Approved' 
      AND from_date >= '$firstDate'
");
$approvedLeaves = (int)(($leaveQ && $lRow = mysqli_fetch_assoc($leaveQ)) ? ($lRow['cnt'] ?? 0) : 0);

// Dynamic column check for wfhRequests to support production DB variances
$wfhColsResult = mysqli_query($con, "SHOW COLUMNS FROM wfhRequests");
$wfhCols = [];
if ($wfhColsResult) {
    while ($colRow = mysqli_fetch_assoc($wfhColsResult)) {
        $wfhCols[] = strtolower($colRow['Field']);
    }
}
$wfhUserCol = in_array('userid', $wfhCols) ? 'userid' : (in_array('user_id', $wfhCols) ? 'user_id' : 'userId');
$hasFromDate = in_array('fromdate', $wfhCols);
$hasWfhDate  = in_array('wfhdate', $wfhCols);

$wfhDateCondition = "1=1";
if ($hasFromDate && $hasWfhDate) {
    $wfhDateCondition = "(fromDate >= '$firstDate' OR wfhDate >= '$firstDate')";
} elseif ($hasFromDate) {
    $wfhDateCondition = "fromDate >= '$firstDate'";
} elseif ($hasWfhDate) {
    $wfhDateCondition = "wfhDate >= '$firstDate'";
}

$wfhQ = mysqli_query($con, "
    SELECT COUNT(*) as cnt 
    FROM wfhRequests 
    WHERE $wfhUserCol = '$userIdEsc' 
      AND status = 'Approved' 
      AND $wfhDateCondition
");
$approvedWfh = (int)(($wfhQ && $wRow = mysqli_fetch_assoc($wfhQ)) ? ($wRow['cnt'] ?? 0) : 0);

// ── Recent Attendance History (Last 7 Records) ──
$historyQ = mysqli_query($con, "
    SELECT date, punchInTime, punchOutTime, manHours, status, lateMark 
    FROM attendance 
    WHERE userId = '$userIdEsc' 
    ORDER BY date DESC 
    LIMIT 7
");
$historyRecords = [];
if ($historyQ) {
    while ($row = mysqli_fetch_assoc($historyQ)) {
        $historyRecords[] = $row;
    }
}

// ── Recent Leave & WFH Requests ──
$recentRequests = [];

$recentLeavesQ = mysqli_query($con, "
    SELECT 'Leave' as reqType, leave_type as subType, from_date as reqDate, status, reason 
    FROM leaves WHERE userId = '$userIdEsc' 
    ORDER BY from_date DESC LIMIT 5
");
if ($recentLeavesQ) {
    while ($row = mysqli_fetch_assoc($recentLeavesQ)) {
        $recentRequests[] = $row;
    }
}

$wfhReqDateCol = ($hasFromDate && $hasWfhDate) ? "COALESCE(fromDate, wfhDate)" : ($hasFromDate ? "fromDate" : ($hasWfhDate ? "wfhDate" : "createdDate"));
$recentWfhQ = mysqli_query($con, "
    SELECT 'WFH' as reqType, 'Work From Home' as subType, $wfhReqDateCol as reqDate, status, reason 
    FROM wfhRequests WHERE $wfhUserCol = '$userIdEsc' 
    ORDER BY $wfhReqDateCol DESC LIMIT 5
");
if ($recentWfhQ) {
    while ($row = mysqli_fetch_assoc($recentWfhQ)) {
        $recentRequests[] = $row;
    }
}

usort($recentRequests, function($a, $b) {
    return strtotime($b['reqDate'] ?? 0) - strtotime($a['reqDate'] ?? 0);
});
$recentRequests = array_slice($recentRequests, 0, 5);

// ── Birthdays Today ──
$todayMD = date('m-d');
$signupColsResult = mysqli_query($con, "SHOW COLUMNS FROM signup");
$signupCols = [];
if ($signupColsResult) {
    while ($scRow = mysqli_fetch_assoc($signupColsResult)) {
        $signupCols[] = strtolower($scRow['Field']);
    }
}
$statusCond = "1=1";
if (in_array('regstatus', $signupCols) && in_array('status', $signupCols)) {
    $statusCond = "(regstatus = 'Approved' OR status = 'Active')";
} elseif (in_array('regstatus', $signupCols)) {
    $statusCond = "regstatus = 'Approved'";
} elseif (in_array('status', $signupCols)) {
    $statusCond = "status = 'Active'";
}

$birthdayQ = mysqli_query($con, "
    SELECT fullName FROM signup 
    WHERE DATE_FORMAT(dob, '%m-%d') = '$todayMD' AND $statusCond
");
$todayBirthdays = [];
if ($birthdayQ) {
    while ($bRow = mysqli_fetch_assoc($birthdayQ)) {
        $todayBirthdays[] = $bRow['fullName'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Dashboard — SETTribe AMS</title>

    <?php include('../partials/_links.php'); ?>

    <style>
        :root {
            --ink: #0f172a;
            --ink-2: #334155;
            --ink-3: #64748b;
            --surface: #ffffff;
            --surface-2: #f8fafc;
            --border: #e2e8f0;
            --accent: #4F46E5;
            --accent-bg: #EEF2FF;
            --green: #10B981;
            --green-bg: #ECFDF5;
            --amber: #F59E0B;
            --amber-bg: #FFFBEB;
            --purple: #8B5CF6;
            --purple-bg: #F5F3FF;
            --red: #EF4444;
            --red-bg: #FEF2F2;
            --radius: 12px;
            --radius-lg: 16px;
            --shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.06);
        }

        body { font-family: 'Inter', system-ui, -apple-system, sans-serif; background-color: #f1f5f9; color: var(--ink); }
        .staff-wrap { padding: 24px; max-width: 1400px; margin: 0 auto; }

        /* Welcome Banner */
        .welcome-card {
            background: linear-gradient(135deg, #3047EC 0%, #6366F1 100%);
            color: #ffffff;
            border-radius: var(--radius-lg);
            padding: 28px 32px;
            margin-bottom: 24px;
            box-shadow: 0 10px 25px -5px rgba(79, 70, 229, 0.3);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }
        .welcome-card h2 { font-size: 24px; font-weight: 700; margin: 0; }
        .welcome-card p { margin: 6px 0 0 0; opacity: 0.9; font-size: 13px; }
        .welcome-badges { display: flex; gap: 8px; flex-wrap: wrap; }
        .w-chip {
            background: rgba(255,255,255,0.2);
            backdrop-filter: blur(8px);
            padding: 6px 14px;
            border-radius: 99px;
            font-size: 12px;
            font-weight: 600;
        }

        /* 4 Stat Cards */
        .grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 18px; margin-bottom: 24px; }
        @media (max-width: 1024px) { .grid-4 { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 640px) { .grid-4 { grid-template-columns: 1fr; } }

        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 20px;
            box-shadow: var(--shadow);
            display: flex;
            align-items: center;
            gap: 16px;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 25px -3px rgba(0,0,0,0.08); }
        .stat-icon {
            width: 50px; height: 50px; border-radius: var(--radius);
            display: flex; align-items: center; justify-content: center;
            font-size: 22px; flex-shrink: 0;
        }
        .stat-icon.green { background: var(--green-bg); color: var(--green); }
        .stat-icon.amber { background: var(--amber-bg); color: var(--amber); }
        .stat-icon.purple { background: var(--purple-bg); color: var(--purple); }
        .stat-icon.accent { background: var(--accent-bg); color: var(--accent); }

        .stat-val { font-size: 24px; font-weight: 700; color: var(--ink); line-height: 1.1; }
        .stat-lbl { font-size: 12px; color: var(--ink-3); font-weight: 600; margin-top: 4px; text-transform: uppercase; letter-spacing: 0.5px; }

        /* Main 2 Column Layout */
        .grid-main { display: grid; grid-template-columns: 2fr 1fr; gap: 24px; }
        @media (max-width: 1024px) { .grid-main { grid-template-columns: 1fr; } }

        .card-box {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            margin-bottom: 24px;
            overflow: hidden;
        }
        .card-box-header {
            padding: 18px 24px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .card-box-title { font-size: 15px; font-weight: 700; color: var(--ink); margin: 0; }
        .card-box-body { padding: 24px; }

        /* Punch Action Box */
        .punch-panel {
            background: linear-gradient(135deg, #FAF5FF 0%, #F3E8FF 100%);
            border: 1px solid #E9D5FF;
            border-radius: var(--radius);
            padding: 20px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 20px;
        }
        .punch-info div { font-size: 13px; color: var(--ink-2); }
        .punch-info strong { color: var(--ink); font-size: 15px; }

        .btn-punch {
            padding: 12px 28px;
            border-radius: 99px;
            font-size: 14px;
            font-weight: 700;
            border: none;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);
        }
        .btn-punch.in { background: var(--green); color: #fff; }
        .btn-punch.in:hover { background: #059669; }
        .btn-punch.out { background: var(--red); color: #fff; }
        .btn-punch.out:hover { background: #DC2626; }

        /* Table Design */
        .table-custom { width: 100%; border-collapse: collapse; }
        .table-custom th {
            background: var(--surface-2);
            color: var(--ink-3);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }
        .table-custom td {
            padding: 14px 16px;
            border-bottom: 1px solid var(--border);
            font-size: 13px;
            color: var(--ink-2);
        }
        .table-custom tr:last-child td { border-bottom: none; }

        /* Status Badges */
        .badge-status {
            padding: 4px 10px;
            border-radius: 99px;
            font-size: 11px;
            font-weight: 700;
            display: inline-block;
        }
        .badge-status.present { background: var(--green-bg); color: var(--green); }
        .badge-status.late { background: var(--amber-bg); color: var(--amber); }
        .badge-status.leave { background: var(--purple-bg); color: var(--purple); }
        .badge-status.approved { background: var(--green-bg); color: var(--green); }
        .badge-status.pending { background: var(--amber-bg); color: var(--amber); }

        /* Quick Action Shortcuts */
        .shortcut-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
        .shortcut-btn {
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 16px;
            text-decoration: none;
            color: var(--ink);
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.18s;
        }
        .shortcut-btn:hover {
            background: var(--accent-bg);
            border-color: #C7D2FE;
            color: var(--accent);
            transform: translateY(-2px);
        }
        .shortcut-btn i { font-size: 18px; color: var(--accent); }

        /* Birthday Marquee */
        .bday-bar {
            background: #FFFBEB;
            border: 1px solid #FDE68A;
            border-radius: var(--radius);
            padding: 10px 16px;
            color: #92400E;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
    </style>
</head>
<body>
    <div class="container-scroller">
        <?php include('../partials/_navbar.php'); ?>
        <div class="container-fluid page-body-wrapper">
            <?php include('../partials/_sidebar.php'); ?>

            <div class="main-panel">
                <div class="staff-wrap">

                    <!-- Birthday Alert Banner -->
                    <?php if (!empty($todayBirthdays)): ?>
                        <div class="bday-bar">
                            <span>🎂</span>
                            <marquee behavior="scroll" direction="left" scrollamount="4" onmouseover="this.stop();" onmouseout="this.start();">
                                <strong>Today's Birthdays:</strong> 🎉 <?= implode(' &bull; 🎉 ', $todayBirthdays) ?>
                            </marquee>
                        </div>
                    <?php endif; ?>

                    <!-- Welcome Header Banner -->
                    <div class="welcome-card">
                        <div>
                            <h2><?= $greeting ?>, <?= htmlspecialchars($userName) ?>! 👋</h2>
                            <p>Welcome to your personal staff dashboard. Manage your daily attendance, tasks, and requests.</p>
                        </div>
                        <div class="welcome-badges">
                            <span class="w-chip"><i class="fa-solid fa-briefcase me-1"></i> Staff Member</span>
                            <span class="w-chip"><i class="fa-solid fa-building me-1"></i> <?= htmlspecialchars($userOffice) ?></span>
                            <span class="w-chip"><i class="fa-solid fa-calendar-day me-1"></i> <?= date('d M Y') ?></span>
                        </div>
                    </div>

                    <!-- 4 Key Stat Cards -->
                    <div class="grid-4">
                        <div class="stat-card">
                            <div class="stat-icon green"><i class="fa-solid fa-user-check"></i></div>
                            <div>
                                <div class="stat-val"><?= $presentDays ?> Days</div>
                                <div class="stat-lbl">Present This Month</div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon amber"><i class="fa-solid fa-clock"></i></div>
                            <div>
                                <div class="stat-val"><?= $lateDays ?> Days</div>
                                <div class="stat-lbl">Late Marks This Month</div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon purple"><i class="fa-solid fa-calendar-minus"></i></div>
                            <div>
                                <div class="stat-val"><?= $approvedLeaves + $approvedWfh ?> Days</div>
                                <div class="stat-lbl">Approved Leave / WFH</div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon accent"><i class="fa-solid fa-hourglass-half"></i></div>
                            <div>
                                <div class="stat-val"><?= $totalHours ?>h <?= $totalMins ?>m</div>
                                <div class="stat-lbl">Total Hours Worked</div>
                            </div>
                        </div>
                    </div>

                    <!-- Main Content 2 Columns -->
                    <div class="grid-main">
                        
                        <!-- Left Column: Attendance & Recent Activity -->
                        <div>
                            
                            <!-- Punch-In Panel Card -->
                            <div class="card-box">
                                <div class="card-box-header">
                                    <h3 class="card-box-title"><i class="fa-solid fa-fingerprint me-2 text-primary"></i>Today's Attendance Status</h3>
                                    <span class="badge <?= $punchedIn ? 'bg-success' : 'bg-warning' ?> text-white">
                                        <?= $punchedIn ? ($punchedOut ? 'Completed Day' : 'Currently Punched In') : 'Awaiting Punch-In' ?>
                                    </span>
                                </div>
                                <div class="card-box-body">
                                    <div class="punch-panel">
                                        <div class="punch-info">
                                            <div>Punch In: <strong><?= $punchInDisplay ?></strong></div>
                                            <div>Punch Out: <strong><?= $punchOutDisplay ?></strong></div>
                                            <div>Hours Today: <strong><?= $todayManHours ?></strong></div>
                                        </div>
                                        <div>
                                            <?php if (!$punchedIn): ?>
                                                <a href="<?= $punchInUrl ?>" class="btn-punch in">
                                                    <i class="fa-solid fa-right-to-bracket"></i> Punch In Now
                                                </a>
                                            <?php elseif (!$punchedOut): ?>
                                                <a href="<?= $punchOutUrl ?>" class="btn-punch out">
                                                    <i class="fa-solid fa-right-from-bracket"></i> Punch Out Now
                                                </a>
                                            <?php else: ?>
                                                <button class="btn-punch in" disabled style="opacity:0.6; cursor:not-allowed;">
                                                    <i class="fa-solid fa-circle-check"></i> Day Completed
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <small class="text-muted"><i class="fa-solid fa-info-circle me-1"></i> Expected hours: <?= date('h:i A', strtotime($expectedIn)) ?> to <?= date('h:i A', strtotime($expectedOut)) ?> (Mode: <?= htmlspecialchars($userMode) ?>)</small>
                                </div>
                            </div>

                            <!-- Recent Attendance History Table -->
                            <div class="card-box">
                                <div class="card-box-header">
                                    <h3 class="card-box-title"><i class="fa-solid fa-history me-2 text-primary"></i>Recent Attendance History</h3>
                                    <a href="myAttendance.php" class="btn btn-sm btn-outline-primary rounded-pill">View All</a>
                                </div>
                                <div class="card-box-body" style="padding:0;">
                                    <?php if (!empty($historyRecords)): ?>
                                        <table class="table-custom">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Punch In</th>
                                                    <th>Punch Out</th>
                                                    <th>Man Hours</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($historyRecords as $rec): 
                                                    $isLate = ($rec['lateMark'] == 1 || $rec['status'] === 'Late');
                                                ?>
                                                    <tr>
                                                        <td><strong><?= date('d M Y (D)', strtotime($rec['date'])) ?></strong></td>
                                                        <td><?= ($rec['punchInTime'] && $rec['punchInTime'] !== '00:00:00') ? date('h:i A', strtotime($rec['punchInTime'])) : '—' ?></td>
                                                        <td><?= ($rec['punchOutTime'] && $rec['punchOutTime'] !== '00:00:00') ? date('h:i A', strtotime($rec['punchOutTime'])) : '—' ?></td>
                                                        <td><?= $rec['manHours'] ?? '00:00:00' ?></td>
                                                        <td>
                                                            <span class="badge-status <?= $isLate ? 'late' : 'present' ?>">
                                                                <?= $isLate ? 'Late' : 'Present' ?>
                                                            </span>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    <?php else: ?>
                                        <div class="p-4 text-center text-muted">No recent attendance records found.</div>
                                    <?php endif; ?>
                                </div>
                            </div>

                        </div>

                        <!-- Right Column: Shortcuts & Recent Requests -->
                        <div>
                            
                            <!-- Quick Action Shortcuts -->
                            <div class="card-box">
                                <div class="card-box-header">
                                    <h3 class="card-box-title"><i class="fa-solid fa-bolt me-2 text-warning"></i>Quick Shortcuts</h3>
                                </div>
                                <div class="card-box-body">
                                    <div class="shortcut-grid">
                                        <a href="myAttendance.php" class="shortcut-btn">
                                            <i class="fa-solid fa-calendar-check"></i> My Attendance
                                        </a>
                                        <a href="apply_leave.php" class="shortcut-btn">
                                            <i class="fa-solid fa-plane-departure"></i> Apply Leave
                                        </a>
                                        <a href="apply-wfh.php" class="shortcut-btn">
                                            <i class="fa-solid fa-house-laptop"></i> Apply WFH
                                        </a>
                                        <a href="Attendance_Regularization.php" class="shortcut-btn">
                                            <i class="fa-solid fa-list-check"></i> Regularization
                                        </a>
                                        <a href="late_punch_early_go.php" class="shortcut-btn">
                                            <i class="fa-solid fa-clock"></i> Late Intimation
                                        </a>
                                        <a href="editProfile.php" class="shortcut-btn">
                                            <i class="fa-solid fa-user-gear"></i> Edit Profile
                                        </a>
                                    </div>
                                </div>
                            </div>

                            <!-- Recent Requests Status Card -->
                            <div class="card-box">
                                <div class="card-box-header">
                                    <h3 class="card-box-title"><i class="fa-solid fa-paper-plane me-2 text-indigo"></i>Recent Applications</h3>
                                </div>
                                <div class="card-box-body" style="padding:0;">
                                    <?php if (!empty($recentRequests)): ?>
                                        <table class="table-custom">
                                            <thead>
                                                <tr>
                                                    <th>Type</th>
                                                    <th>Date</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($recentRequests as $req): 
                                                    $stClass = strtolower($req['status']) === 'approved' ? 'approved' : 'pending';
                                                ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?= htmlspecialchars($req['reqType']) ?></strong>
                                                            <br><small class="text-muted"><?= htmlspecialchars($req['subType']) ?></small>
                                                        </td>
                                                        <td><?= date('d M Y', strtotime($req['reqDate'])) ?></td>
                                                        <td>
                                                            <span class="badge-status <?= $stClass ?>">
                                                                <?= htmlspecialchars($req['status']) ?>
                                                            </span>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    <?php else: ?>
                                        <div class="p-4 text-center text-muted">No recent leave or WFH requests.</div>
                                    <?php endif; ?>
                                </div>
                            </div>

                        </div>

                    </div>

                </div>
                
                <!-- Footer -->
                <footer class="footer">
                    <div class="d-sm-flex justify-content-center justify-content-sm-between">
                        <span class="text-muted text-center text-sm-left d-block d-sm-inline-block">SETTribe AMS &copy; <?= date('Y') ?>. All rights reserved.</span>
                    </div>
                </footer>
            </div>
        </div>
    </div>

    <script src="../vendors/js/vendor.bundle.base.js"></script>
    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>
</body>

<!-- ask -->
</html>
