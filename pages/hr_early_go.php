<?php
session_start();
date_default_timezone_set('Asia/Kolkata');

if (empty($_SESSION['userId'])) {
    echo "<script>window.location.href='login.php';</script>";
    exit;
}
include('../include/checkPasswordStatus.php');
include('../include/db_config.php');

$hrUserId = $_SESSION['userId'];

// ── Role guard: only HR allowed ──
$hrRow = mysqli_fetch_assoc(mysqli_query(
    $con,
    "SELECT fullName, designation FROM signup WHERE userId='" . mysqli_real_escape_string($con, $hrUserId) . "'"
));
if (!$hrRow || (strtolower($hrRow['designation']) !== 'hr' && strtolower(trim($hrRow['designation'] ?? 'resourceallocator')) !== 'resourceallocator')) {
    echo '<script>alert("You are not authorized to access this page.");window.location.href="hrDashboard.php";</script>';
    exit;
}
$hrName = $hrRow['fullName'];

// ── Auto-add columns if missing ──
$cols = [
    'early_go' => ['hr_granted', "ALTER TABLE early_go ADD COLUMN hr_granted TINYINT(1) DEFAULT 0 AFTER reason"],
    'early_go2' => ['hr_granted_by', "ALTER TABLE early_go ADD COLUMN hr_granted_by VARCHAR(50) DEFAULT NULL AFTER hr_granted"],
    'early_go3' => ['emp_name', "ALTER TABLE early_go ADD COLUMN emp_name VARCHAR(150) DEFAULT NULL AFTER userId"],
];
foreach ($cols as $c) {
    $chk = mysqli_query($con, "SHOW COLUMNS FROM early_go LIKE '{$c[0]}'");
    if ($chk && mysqli_num_rows($chk) === 0)
        mysqli_query($con, $c[1]);
}

$today = date('Y-m-d');
$esc = fn($v) => mysqli_real_escape_string($con, $v);

// ══════════════════════════════════════════════════════════════
// AJAX: fetch interns for select2 search
// ══════════════════════════════════════════════════════════════
if (isset($_GET['action']) && $_GET['action'] === 'search_interns') {
    header('Content-Type: application/json');
    $q = $esc($_GET['q'] ?? '');
    $res = mysqli_query(
        $con,
        "SELECT userId, fullName, emp_id, expectedPunchOut
         FROM signup
         WHERE status='Active' AND designation!='admin'
           AND (fullName LIKE '%$q%' OR emp_id LIKE '%$q%')
         ORDER BY fullName ASC LIMIT 30"
    );
    $out = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $out[] = [
            'id' => $r['userId'],
            'text' => $r['fullName'] . ' (' . ($r['emp_id'] ?: $r['userId']) . ')',
            'fullName' => $r['fullName'],
            'expectedPunchOut' => $r['expectedPunchOut'] ?? '18:30:00',
        ];
    }
    echo json_encode($out);
    exit;
}

// ══════════════════════════════════════════════════════════════
// AJAX: get intern punch-out time
// ══════════════════════════════════════════════════════════════
if (isset($_GET['action']) && $_GET['action'] === 'get_intern_info') {
    header('Content-Type: application/json');
    $uid = $esc($_GET['userId'] ?? '');
    $r = mysqli_fetch_assoc(mysqli_query(
        $con,
        "SELECT fullName, emp_id, expectedPunchOut FROM signup WHERE userId='$uid' LIMIT 1"
    ));
    if (!$r) {
        echo json_encode(['success' => false]);
        exit;
    }

    // Check if already has early_go today
    $eg = mysqli_fetch_assoc(mysqli_query(
        $con,
        "SELECT early_go_time FROM early_go WHERE userId='$uid' AND date='$today' AND hr_granted=1 LIMIT 1"
    ));

    echo json_encode([
        'success' => true,
        'fullName' => $r['fullName'],
        'emp_id' => $r['emp_id'] ?: $r['userId'],
        'expectedPunchOut' => $r['expectedPunchOut'] ?? '18:30:00',
        'alreadyGranted' => $eg ? date('h:i A', strtotime($eg['early_go_time'])) : null,
    ]);
    exit;
}

// ══════════════════════════════════════════════════════════════
// POST: save early go record
// ══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['intern_id'])) {
    header('Content-Type: application/json');

    $internId = $esc($_POST['intern_id']);
    $earlyTime = $esc($_POST['early_go_time']);   // HH:MM (24hr hidden input)
    $reason = $esc($_POST['reason']);

    // Validate intern exists
    $internRow = mysqli_fetch_assoc(mysqli_query(
        $con,
        "SELECT fullName, expectedPunchOut FROM signup WHERE userId='$internId' AND status='Active' LIMIT 1"
    ));
    if (!$internRow) {
        echo json_encode(['success' => false, 'message' => 'Intern not found.']);
        exit;
    }

    // Early time must be before their expected punch-out
    if (!empty($internRow['expectedPunchOut']) && $internRow['expectedPunchOut'] !== '00:00:00') {
        if (strtotime($earlyTime) >= strtotime($internRow['expectedPunchOut'])) {
            echo json_encode([
                'success' => false,
                'message' => 'Early go time must be before the intern\'s expected punch-out time (' .
                    date('h:i A', strtotime($internRow['expectedPunchOut'])) . ').'
            ]);
            exit;
        }
    }

    $internName = $esc($internRow['fullName']);
    $hrEsc = $esc($hrUserId);

    // Delete any existing HR-granted early-go for this intern today (allow update)
    mysqli_query($con, "DELETE FROM early_go WHERE userId='$internId' AND date='$today' AND hr_granted=1");

    $ins = mysqli_query(
        $con,
        "INSERT INTO early_go (userId, emp_name, early_go_time, early_departure_minutes, early_go_mark, action_taken, date, reason, approved_by, hr_granted, hr_granted_by)
         VALUES ('$internId','$internName','$earlyTime',0,0,'HR Approved Early Go','$today','$reason','$hrEsc',1,'$hrEsc')"
    );

    if ($ins) {
        // ── Send notification to intern ──
        $chkLink = mysqli_query($con, "SHOW COLUMNS FROM notifications LIKE 'link'");
        if ($chkLink && mysqli_num_rows($chkLink) === 0) {
            mysqli_query($con, "ALTER TABLE notifications ADD COLUMN link VARCHAR(500) DEFAULT NULL AFTER message");
        }
        $formattedTime = date('h:i A', strtotime($earlyTime));
        $ntitle = $esc("⏰ Early Go Approved");
        $nmsg = $esc("HR has approved your early punch-out today.\n📌 Reason: $reason\n🕐 You may punch out at: $formattedTime");
        $nlink = $esc('../pages/late_punch_early_go.php');
        mysqli_query(
            $con,
            "INSERT INTO notifications (userId, title, message, link, type, is_read, created_at)
             VALUES ('$internId','$ntitle','$nmsg','$nlink','early_go',0,NOW())"
        );
        echo json_encode(['success' => true, 'message' => "Early go approved for {$internRow['fullName']} at $formattedTime."]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($con)]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════
// ANALYTICS CALCULATIONS
// ══════════════════════════════════════════════════════════════
// 1. Today's count
$todayCountQ = mysqli_query($con, "SELECT COUNT(*) as cnt FROM early_go WHERE hr_granted = 1 AND date = '$today'");
$todayCount = (int) (mysqli_fetch_assoc($todayCountQ)['cnt'] ?? 0);

// 2. Last 7 days count
$weekCountQ = mysqli_query($con, "SELECT COUNT(*) as cnt FROM early_go WHERE hr_granted = 1 AND date BETWEEN DATE_SUB('$today', INTERVAL 6 DAY) AND '$today'");
$weekCount = (int) (mysqli_fetch_assoc($weekCountQ)['cnt'] ?? 0);

// 3. Average Early Go Time
$avgTimeQ = mysqli_query($con, "SELECT SEC_TO_TIME(AVG(TIME_TO_SEC(early_go_time))) as avg_time FROM early_go WHERE hr_granted = 1 AND date BETWEEN DATE_SUB('$today', INTERVAL 6 DAY) AND '$today'");
$avgTimeRow = mysqli_fetch_assoc($avgTimeQ);
$avgTimeStr = !empty($avgTimeRow['avg_time']) ? date('h:i A', strtotime($avgTimeRow['avg_time'])) : '—';

// 4. Daily trend (Last 7 days)
$dailyTrend = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $dailyTrend[$d] = 0;
}
$trendQ = mysqli_query($con, "SELECT date, COUNT(*) as cnt FROM early_go WHERE hr_granted = 1 AND date BETWEEN DATE_SUB('$today', INTERVAL 6 DAY) AND '$today' GROUP BY date");
while ($r = mysqli_fetch_assoc($trendQ)) {
    if (isset($dailyTrend[$r['date']])) {
        $dailyTrend[$r['date']] = (int) $r['cnt'];
    }
}

// 5. Reasons Breakdown
$reasonsQ = mysqli_query($con, "SELECT reason FROM early_go WHERE hr_granted = 1 AND date BETWEEN DATE_SUB('$today', INTERVAL 30 DAY) AND '$today'");
$reasonCategories = [
    'Medical / Health' => 0,
    'Personal Work' => 0,
    'Official / Client' => 0,
    'Academic / Exam' => 0,
    'Urgent / Emergency' => 0
];
while ($r = mysqli_fetch_assoc($reasonsQ)) {
    $rsn = strtolower(trim($r['reason'] ?? ''));
    if (strpos($rsn, 'med') !== false || strpos($rsn, 'doc') !== false || strpos($rsn, 'health') !== false || strpos($rsn, 'sick') !== false) {
        $reasonCategories['Medical / Health']++;
    } elseif (strpos($rsn, 'exam') !== false || strpos($rsn, 'clg') !== false || strpos($rsn, 'coll') !== false || strpos($rsn, 'study') !== false) {
        $reasonCategories['Academic / Exam']++;
    } elseif (strpos($rsn, 'work') !== false || strpos($rsn, 'site') !== false || strpos($rsn, 'client') !== false || strpos($rsn, 'off') !== false) {
        $reasonCategories['Official / Client']++;
    } elseif (strpos($rsn, 'urg') !== false || strpos($rsn, 'emerg') !== false) {
        $reasonCategories['Urgent / Emergency']++;
    } else {
        $reasonCategories['Personal Work']++;
    }
}

// ── Fetch recent HR-granted records (today + last 7 days) ──
$recentQ = mysqli_query(
    $con,
    "SELECT eg.id, eg.emp_name, COALESCE(s.fullName, eg.emp_name) AS fullName,
            s.emp_id, eg.early_go_time, eg.date, eg.reason,
            DATE_FORMAT(eg.date,'%d %b %Y') AS fmt_date,
            hg.fullName AS granted_by_name
     FROM   early_go eg
     LEFT JOIN signup s  ON s.userId  = eg.userId
     LEFT JOIN signup hg ON hg.userId = eg.hr_granted_by
     WHERE  eg.hr_granted = 1
       AND  eg.date BETWEEN DATE_SUB('$today', INTERVAL 6 DAY) AND '$today'
     ORDER  BY eg.date DESC, eg.early_go_time ASC"
);
$recentRows = [];
while ($r = mysqli_fetch_assoc($recentQ))
    $recentRows[] = $r;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HR Early Go Analytics — SETTribe AMS</title>
    <?php include('../partials/_links.php'); ?>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">
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
            --danger-light: #fef2f2;
            --info: #06b6d4;
            --info-light: #ecfeff;
            --purple: #8b5cf6;
            --purple-light: #f5f3ff;
            --border: #e2e8f0;
            --radius-lg: 14px;
            --radius-md: 10px;
        }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: #f1f5f9;
            color: #1e293b;
        }

        html,
        body {
            max-width: 100vw;
            overflow-x: hidden;
        }

        .container-scroller,
        .page-body-wrapper,
        .main-panel,
        .content-wrapper {
            max-width: 100%;
            overflow-x: hidden;
        }

        .eg-wrap {
            padding: 24px;
            max-width: 1200px;
            margin: 0 auto;
            width: 100%;
            box-sizing: border-box;
        }

        /* ── Header Banner ── */
        .analytics-header-card {
            background: linear-gradient(135deg, #064e3b 0%, #047857 50%, #059669 100%);
            border-radius: var(--radius-lg);
            padding: 22px 26px;
            color: #ffffff;
            margin-bottom: 24px;
            box-shadow: 0 10px 25px -5px rgba(5, 150, 105, 0.3);
            position: relative;
            overflow: hidden;
            width: 100%;
            box-sizing: border-box;
        }

        .analytics-header-card::after {
            content: '';
            position: absolute;
            right: -50px;
            top: -50px;
            width: 220px;
            height: 220px;
            background: rgba(255, 255, 255, 0.08);
            border-radius: 50%;
            pointer-events: none;
        }

        .analytics-header-card h2 {
            font-size: 1.5rem;
            font-weight: 800;
            margin-bottom: 4px;
            word-break: break-word;
            overflow-wrap: break-word;
            max-width: 100%;
        }

        .analytics-header-card p {
            font-size: 0.84rem;
            opacity: 0.9;
            margin: 0;
            word-break: break-word;
            overflow-wrap: break-word;
            max-width: 100%;
        }

        .header-title-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.74rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        /* ── KPI Cards ── */
        .kpi-card {
            background: #ffffff;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            padding: 18px 20px;
            height: 100%;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.03);
            transition: all 0.25s ease;
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .kpi-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.06);
            border-color: #cbd5e1;
        }

        .kpi-card::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
        }

        .kpi-green::before {
            background: var(--success);
        }

        .kpi-purple::before {
            background: var(--purple);
        }

        .kpi-amber::before {
            background: var(--warning);
        }

        .kpi-cyan::before {
            background: var(--info);
        }

        .kpi-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
        }

        .kpi-title {
            font-size: 0.78rem;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .kpi-icon-wrap {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }

        .icon-green {
            background: var(--success-light);
            color: var(--success);
        }

        .icon-purple {
            background: var(--purple-light);
            color: var(--purple);
        }

        .icon-amber {
            background: var(--warning-light);
            color: var(--warning);
        }

        .icon-cyan {
            background: var(--info-light);
            color: var(--info);
        }

        .kpi-value {
            font-size: 1.6rem;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.1;
            margin-bottom: 6px;
            word-break: break-word;
        }

        .kpi-sub {
            font-size: 0.75rem;
            color: #64748b;
            font-weight: 600;
        }

        /* ── Cards Section ── */
        .eg-card {
            background: #ffffff;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.03);
            margin-bottom: 24px;
            overflow: hidden;
            width: 100%;
            box-sizing: border-box;
        }

        .eg-card__hdr {
            padding: 16px 22px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            width: 100%;
            box-sizing: border-box;
        }

        .select2-container .select2-selection--single {
            height: 100% !important;
        }

        .eg-card__title {
            font-size: 0.92rem;
            font-weight: 800;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            word-break: break-word;
            overflow-wrap: break-word;
            max-width: 100%;
        }

        .eg-card__body {
            padding: 20px;
            width: 100%;
            box-sizing: border-box;
        }

        /* ── Preset Chips for Reason ── */
        .reason-chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 0.74rem;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 20px;
            border: 1px solid #cbd5e1;
            background: #f8fafc;
            color: #475569;
            cursor: pointer;
            transition: all 0.2s;
            margin-right: 6px;
            margin-top: 6px;
        }

        .reason-chip:hover {
            background: var(--primary-light);
            color: var(--primary);
            border-color: #c7d2fe;
        }

        /* ── Time Row ── */
        .time-row {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }

        .time-row select {
            flex: 1;
            min-width: 70px;
            height: 42px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 13px;
            padding: 0 10px;
            color: #1e293b;
            font-weight: 600;
        }

        .time-row .sep {
            font-weight: 800;
            color: #94a3b8;
        }

        /* ── Intern Info Panel ── */
        .intern-info {
            background: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: 10px;
            padding: 14px 18px;
            margin-top: 14px;
            display: none;
        }

        .intern-info__row {
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
        }

        .intern-info__item {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .intern-info__label {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: #0284c7;
        }

        .intern-info__value {
            font-size: 13px;
            font-weight: 700;
            color: #0f172a;
        }

        .already-granted {
            background: #fffbeb;
            border: 1px solid #fef3c7;
            border-radius: 8px;
            padding: 8px 14px;
            font-size: 12px;
            color: #b45309;
            margin-top: 10px;
            display: none;
            font-weight: 600;
        }

        /* ── Buttons ── */
        .btn-submit {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: #fff;
            border: none;
            border-radius: 9px;
            padding: 10px 24px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(79, 70, 229, 0.28);
            transition: all 0.2s;
        }

        .btn-submit:hover {
            opacity: .9;
            transform: translateY(-1px);
        }

        .btn-cancel {
            background: #fff;
            color: #475569;
            border: 1px solid var(--border);
            border-radius: 9px;
            padding: 10px 20px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            margin-left: 10px;
            transition: background .15s;
        }

        .btn-cancel:hover {
            background: #f8fafc;
            color: #0f172a;
            text-decoration: none;
        }

        /* DataTables & Responsive Table Wrapper */
        .table-responsive-wrapper {
            width: 100% !important;
            overflow-x: auto !important;
            -webkit-overflow-scrolling: touch;
            display: block;
            margin-top: 4px;
        }

        .table-responsive-wrapper table {
            width: 100% !important;
            min-width: 620px;
        }

        .table thead th {
            background: linear-gradient(135deg, #1e293b, #334155);
            color: #ffffff;
            font-size: 0.76rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            border: none;
            padding: 11px 12px;
            white-space: nowrap;
        }

        .table tbody td {
            font-size: 0.83rem;
            padding: 11px 12px;
            vertical-align: middle;
            color: #334155;
        }

        td.reason-cell {
            white-space: normal !important;
            min-width: 130px;
            max-width: 220px;
            word-break: break-word;
            overflow-wrap: break-word;
        }

        .badge-time {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: var(--primary-light);
            color: var(--primary);
            border-radius: 20px;
            padding: 3px 10px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .badge-today {
            background: #dcfce7;
            color: #15803d;
            border-radius: 20px;
            padding: 2px 8px;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
        }

        .select2-container {
            width: 100% !important;
        }

        /* ── Responsive Media Queries ── */
        @media (max-width: 991px) {
            .eg-wrap {
                padding: 16px;
            }

            .analytics-header-card {
                padding: 18px 20px;
            }

            .analytics-header-card h2 {
                font-size: 1.35rem;
            }
        }

        @media (max-width: 768px) {
            .eg-wrap {
                padding: 12px 10px;
            }

            .analytics-header-card {
                padding: 16px 18px;
                margin-bottom: 16px;
            }

            .analytics-header-card h2 {
                font-size: 1.2rem;
            }

            .analytics-header-card p {
                font-size: 0.78rem;
            }

            .header-btn-wrap {
                width: 100%;
                margin-top: 10px;
                text-align: left !important;
            }

            .header-btn-wrap a {
                display: inline-flex;
                text-align: center;
            }

            .kpi-card {
                padding: 14px 16px;
            }

            .kpi-value {
                font-size: 1.4rem;
            }

            .eg-card {
                margin-bottom: 16px;
            }

            .eg-card__hdr {
                padding: 14px 16px;
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }

            .eg-card__body {
                padding: 14px;
            }

            .intern-info {
                padding: 12px;
            }

            .intern-info__row {
                gap: 12px;
            }

            .time-row select {
                height: 40px;
                font-size: 12px;
            }
        }

        @media (max-width: 576px) {
            .eg-wrap {
                padding: 8px 6px;
            }

            .analytics-header-card {
                padding: 14px;
                border-radius: 12px;
            }

            .analytics-header-card h2 {
                font-size: 1.1rem;
            }

            .analytics-header-card p {
                font-size: 0.75rem;
            }

            .header-btn-wrap a {
                width: 100%;
                justify-content: center;
            }

            .eg-card__title {
                font-size: 0.88rem;
            }

            .reason-chip {
                font-size: 0.7rem;
                padding: 3px 8px;
                margin-right: 4px;
                margin-top: 4px;
            }

            .form-actions-wrap {
                display: flex;
                flex-direction: column;
                gap: 8px;
                width: 100%;
            }

            .btn-submit,
            .btn-cancel {
                width: 100%;
                text-align: center;
                margin-left: 0 !important;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                box-sizing: border-box;
            }

            .intern-info__row {
                flex-direction: column;
                gap: 8px;
            }

            .intern-info__item {
                width: 100%;
            }

            .chart-container {
                height: 210px !important;
            }
        }
    </style>
</head>

<body>
    <div class="container-scroller">
        <?php include('../partials/_navbar.php'); ?>
        <div class="container-fluid page-body-wrapper">
            <?php include('../partials/_sidebar.php');
            include('../partials/_settings-panel.php'); ?>
            <div class="main-panel">
                <div class="eg-wrap">

                    <!-- Header Banner -->
                    <div class="analytics-header-card">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                            <div>
                                <div class="header-title-badge">
                                    <i class="fas fa-clock"></i> HR Early Go Management &amp; Analytics
                                </div>
                                <h2 style="font-size: 1.6rem; font-weight: 800; margin-bottom: 4px;">
                                    Intern Early Go Permissions
                                </h2>
                                <p style="font-size: 0.85rem; opacity: 0.88; margin: 0;">
                                    Grant HR-approved early punch-out permissions, notify interns, and monitor early
                                    departure analytics.
                                </p>
                            </div>
                            <div class="text-right header-btn-wrap">
                                <a href="Early_Go Report.php" class="btn btn-light px-3 py-2"
                                    style="font-size:0.82rem; font-weight:700; border-radius:20px; color:#059669;">
                                    <i class="fas fa-file-alt mr-1"></i> Full Early Go Report
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- ── KPI METRICS ROW ── -->
                    <div class="row mb-4">
                        <div class="col-xl-3 col-md-6 col-sm-6 mb-3">
                            <div class="kpi-card kpi-green">
                                <div class="kpi-header">
                                    <span class="kpi-title">Granted Today</span>
                                    <div class="kpi-icon-wrap icon-green"><i class="fas fa-calendar-day"></i></div>
                                </div>
                                <div>
                                    <div class="kpi-value"><?= $todayCount ?></div>
                                    <div class="kpi-sub">HR Approvals Today</div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-3 col-md-6 col-sm-6 mb-3">
                            <div class="kpi-card kpi-purple">
                                <div class="kpi-header">
                                    <span class="kpi-title">Last 7 Days</span>
                                    <div class="kpi-icon-wrap icon-purple"><i class="fas fa-history"></i></div>
                                </div>
                                <div>
                                    <div class="kpi-value"><?= $weekCount ?></div>
                                    <div class="kpi-sub">Total Granted This Week</div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-3 col-md-6 col-sm-6 mb-3">
                            <div class="kpi-card kpi-amber">
                                <div class="kpi-header">
                                    <span class="kpi-title">Avg. Departure Time</span>
                                    <div class="kpi-icon-wrap icon-amber"><i class="fas fa-clock"></i></div>
                                </div>
                                <div>
                                    <div class="kpi-value" style="font-size:1.45rem; font-family:monospace;">
                                        <?= $avgTimeStr ?>
                                    </div>
                                    <div class="kpi-sub">Avg Approved Punch-out</div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-3 col-md-6 col-sm-6 mb-3">
                            <div class="kpi-card kpi-cyan">
                                <div class="kpi-header">
                                    <span class="kpi-title">Top Reason (30d)</span>
                                    <div class="kpi-icon-wrap icon-cyan"><i class="fas fa-info-circle"></i></div>
                                </div>
                                <div>
                                    <?php
                                    arsort($reasonCategories);
                                    $topReason = key($reasonCategories);
                                    $topCount = current($reasonCategories);
                                    ?>
                                    <div class="kpi-value" style="font-size:1.25rem;">
                                        <?= htmlspecialchars((string) ($topReason ?? 'N/A')) ?>
                                    </div>
                                    <div class="kpi-sub">Count: <strong><?= $topCount ?></strong> permissions</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── ANALYTICS CHARTS ── -->
                    <div class="row mb-4">
                        <!-- Daily Trend Bar Chart -->
                        <div class="col-lg-7 mb-3 mb-lg-0">
                            <div class="eg-card h-100">
                                <div class="eg-card__hdr">
                                    <div class="eg-card__title">
                                        <i class="fas fa-chart-bar text-primary"></i> Daily Early Go Trend (Last 7 Days)
                                    </div>
                                </div>
                                <div class="eg-card__body">
                                    <div class="chart-container" style="position: relative; height: 230px;">
                                        <canvas id="dailyTrendChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Reasons Distribution Chart -->
                        <div class="col-lg-5">
                            <div class="eg-card h-100">
                                <div class="eg-card__hdr">
                                    <div class="eg-card__title">
                                        <i class="fas fa-chart-pie text-purple"></i> Reason Categories (30 Days)
                                    </div>
                                </div>
                                <div class="eg-card__body">
                                    <div class="chart-container" style="position: relative; height: 230px;">
                                        <canvas id="reasonsChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── GRANT EARLY GO FORM CARD ── -->
                    <div class="eg-card">
                        <div class="eg-card__hdr">
                            <div class="eg-card__title">
                                <i class="fas fa-plus-circle text-success"></i> Grant HR Early Go Approval
                            </div>
                            <span class="badge badge-light border" style="font-size:.72rem;">Sends Automatic
                                Notification</span>
                        </div>
                        <div class="eg-card__body">
                            <form id="earlyGoForm">
                                <div class="row">
                                    <!-- Select Intern -->
                                    <div class="col-12 mb-3">
                                        <label class="form-label"
                                            style="font-weight:700; font-size:.8rem; color:#475569;">
                                            Select Intern / Employee <span class="text-danger">*</span>
                                        </label>
                                        <select id="internSelect" name="intern_id" style="width:100%;">
                                            <option value="">Search by name or employee ID…</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- Intern info panel -->
                                <div class="intern-info" id="internInfoPanel">
                                    <div class="intern-info__row">
                                        <div class="intern-info__item">
                                            <span class="intern-info__label">Name</span>
                                            <span class="intern-info__value" id="ii_name">—</span>
                                        </div>
                                        <div class="intern-info__item">
                                            <span class="intern-info__label">Employee ID</span>
                                            <span class="intern-info__value" id="ii_empid">—</span>
                                        </div>
                                        <div class="intern-info__item">
                                            <span class="intern-info__label">Scheduled Punch-Out</span>
                                            <span class="intern-info__value" id="ii_punchout">—</span>
                                        </div>
                                    </div>
                                    <div class="already-granted" id="alreadyGrantedBox">
                                        <i class="fas fa-exclamation-triangle mr-1"></i>
                                        This intern already has an HR-approved early go today at
                                        <strong id="alreadyGrantedTime"></strong>. Submitting will update it.
                                    </div>
                                </div>

                                <div class="row mt-3">
                                    <!-- Early Go Time -->
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label"
                                            style="font-weight:700; font-size:.8rem; color:#475569;">
                                            Early Go Time <span class="text-danger">*</span>
                                        </label>
                                        <input type="hidden" id="earlyGoTimeHidden" name="early_go_time">
                                        <div class="time-row">
                                            <select id="egHour">
                                                <option value="">hh</option>
                                                <?php for ($h = 1; $h <= 12; $h++): ?>
                                                    <option value="<?= $h ?>"><?= str_pad($h, 2, '0', STR_PAD_LEFT) ?>
                                                    </option>
                                                <?php endfor; ?>
                                            </select>
                                            <span class="sep">:</span>
                                            <select id="egMin">
                                                <option value="">mm</option>
                                                <?php for ($m = 0; $m < 60; $m += 5): ?>
                                                    <option value="<?= $m ?>"><?= str_pad($m, 2, '0', STR_PAD_LEFT) ?>
                                                    </option>
                                                <?php endfor; ?>
                                            </select>
                                            <select id="egAmPm">
                                                <option value="AM">AM</option>
                                                <option value="PM" selected>PM</option>
                                            </select>
                                        </div>
                                        <small style="font-size:11px; color:#64748b;" class="mt-1 d-block">
                                            Must be <strong>before</strong> scheduled punch-out time.
                                        </small>
                                    </div>

                                    <!-- Reason -->
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label"
                                            style="font-weight:700; font-size:.8rem; color:#475569;">
                                            Reason <span class="text-danger">*</span>
                                        </label>
                                        <input type="text" class="form-control" id="reason" name="reason"
                                            placeholder="e.g. Personal work, Medical appointment…"
                                            style="height:42px; border-radius:8px;">

                                        <!-- Quick Reason Chips -->
                                        <div style="margin-top:4px;">
                                            <span class="reason-chip" onclick="fillReason('🏥 Medical Appointment')">🏥
                                                Medical</span>
                                            <span class="reason-chip" onclick="fillReason('🚘 Personal Work')">🚘
                                                Personal</span>
                                            <span class="reason-chip" onclick="fillReason('📚 Academic / Exam')">📚
                                                Exam</span>
                                            <span class="reason-chip" onclick="fillReason('💼 Official Site Visit')">💼
                                                Official Work</span>
                                            <span class="reason-chip" onclick="fillReason('⚡ Urgent Emergency')">⚡
                                                Emergency</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-3 form-actions-wrap">
                                    <button type="submit" class="btn-submit" id="submitBtn">
                                        <i class="fas fa-check-circle mr-1"></i> Grant Early Go Permission
                                    </button>
                                    <a href="hrDashboard.php" class="btn-cancel">Cancel</a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- ── RECENT RECORDS TABLE ── -->
                    <div class="eg-card">
                        <div class="eg-card__hdr">
                            <div class="eg-card__title">
                                <i class="fas fa-list-alt text-primary"></i> Recent HR-Granted Early Go Logs (Last 7
                                Days)
                            </div>
                            <span class="badge badge-light border" style="font-size:.72rem;">Total:
                                <?= count($recentRows) ?> Records</span>
                        </div>
                        <div class="eg-card__body">
                            <?php if (empty($recentRows)): ?>
                                <div class="text-center py-4 color-slate" style="font-size:13px; color:#64748b;">
                                    <i class="fas fa-clipboard-list mb-2"
                                        style="font-size:2rem; color:#cbd5e1; display:block;"></i>
                                    No HR-granted early go records in the last 7 days.
                                </div>
                            <?php else: ?>
                                <div class="table-responsive-wrapper">
                                    <table class="table table-hover" id="earlyGoTable">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Intern Name</th>
                                                <th>Emp ID</th>
                                                <th>Date</th>
                                                <th>Approved Time</th>
                                                <th>Reason</th>
                                                <th>Granted By</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recentRows as $i => $row): ?>
                                                <tr class="<?= $row['date'] === $today ? 'table-warning' : '' ?>">
                                                    <td class="font-weight-bold text-muted"><?= $i + 1 ?></td>
                                                    <td>
                                                        <strong
                                                            style="color:#0f172a;"><?= htmlspecialchars((string) ($row['fullName'] ?: ($row['emp_name'] ?? 'Unknown'))) ?></strong>
                                                        <?php if ($row['date'] === $today): ?>
                                                            <span class="badge-today ml-1">Today</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><code
                                                            style="font-size:.78rem;"><?= htmlspecialchars((string) ($row['emp_id'] ?? '—')) ?></code>
                                                    </td>
                                                    <td style="white-space:nowrap; font-weight:600;"><?= $row['fmt_date'] ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge-time">
                                                            <i class="fas fa-clock" style="font-size:10px;"></i>
                                                            <?= date('h:i A', strtotime($row['early_go_time'])) ?>
                                                        </span>
                                                    </td>
                                                    <td class="reason-cell"><span
                                                            style="color:#334155; font-weight:500;"><?= htmlspecialchars((string) ($row['reason'] ?? '')) ?></span>
                                                    </td>
                                                    <td>
                                                        <span class="badge badge-light border text-dark"
                                                            style="font-size:.72rem;">
                                                            <i class="fas fa-user-shield text-info mr-1"></i>
                                                            <?= htmlspecialchars((string) ($row['granted_by_name'] ?? 'HR')) ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                </div><!-- /eg-wrap -->
            </div><!-- /main-panel -->
        </div>
    </div>

    <!-- Scripts -->
    <script src="../vendors/js/vendor.bundle.base.js"></script>
    <script src="../vendors/chart.js/Chart.min.js"></script>
    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>
    <script src="../js/settings.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>

    <script>
        $(document).ready(function () {

            $('#earlyGoTable').DataTable({
                pageLength: 10,
                responsive: false,
                ordering: true,
                searching: true,
                lengthChange: true,
                order: [[3, 'desc']]
            });

            // ── Select2 intern search ──
            $('#internSelect').select2({
                placeholder: 'Search by name or employee ID…',
                allowClear: true,
                width: '100%',
                minimumInputLength: 1,
                ajax: {
                    url: 'hr_early_go.php',
                    dataType: 'json',
                    delay: 250,
                    data: function (params) {
                        return {
                            action: 'search_interns',
                            q: params.term
                        };
                    },
                    processResults: function (data) {
                        return { results: data };
                    },
                    cache: true
                }
            });

            // ── On intern select ──
            $('#internSelect').on('select2:select', function (e) {
                var userId = e.params.data.id;
                loadInternInfo(userId);
            });

            $('#internSelect').on('select2:unselect select2:clear', function () {
                $('#internInfoPanel').hide();
            });

            // ── Sync time selects to hidden input ──
            $('#egHour, #egMin, #egAmPm').on('change', syncTimeHidden);

            // ── Form submit ──
            $('#earlyGoForm').on('submit', function (e) {
                e.preventDefault();
                submitForm();
            });

            // ── Daily Trend Chart ──
            var ctxTrend = document.getElementById('dailyTrendChart').getContext('2d');
            new Chart(ctxTrend, {
                type: 'bar',
                data: {
                    labels: <?= json_encode(array_map(fn($d) => date('D, d M', strtotime($d)), array_keys($dailyTrend))) ?>,
                    datasets: [{
                        label: 'Granted Early Go Permissions',
                        data: <?= json_encode(array_values($dailyTrend)) ?>,
                        backgroundColor: '#10b981',
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

            // ── Reason Categories Chart ──
            var ctxReasons = document.getElementById('reasonsChart').getContext('2d');
            new Chart(ctxReasons, {
                type: 'doughnut',
                data: {
                    labels: <?= json_encode(array_keys($reasonCategories)) ?>,
                    datasets: [{
                        data: <?= json_encode(array_values($reasonCategories)) ?>,
                        backgroundColor: ['#06b6d4', '#4f46e5', '#8b5cf6', '#f59e0b', '#ef4444'],
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    legend: {
                        position: window.innerWidth < 768 ? 'bottom' : 'right',
                        labels: { boxWidth: 12, fontSize: 10 }
                    }
                }
            });
        });

        function fillReason(val) {
            $('#reason').val(val);
        }

        function loadInternInfo(userId) {
            $.getJSON('hr_early_go.php', { action: 'get_intern_info', userId: userId }, function (data) {
                if (!data.success) return;
                $('#ii_name').text(data.fullName);
                $('#ii_empid').text(data.emp_id);
                $('#ii_punchout').text(formatTime24(data.expectedPunchOut));
                $('#internInfoPanel').show();
                if (data.alreadyGranted) {
                    $('#alreadyGrantedTime').text(data.alreadyGranted);
                    $('#alreadyGrantedBox').show();
                } else {
                    $('#alreadyGrantedBox').hide();
                }
            });
        }

        function formatTime24(t) {
            if (!t || t === '00:00:00') return '—';
            var parts = t.split(':');
            var h = parseInt(parts[0]), m = parseInt(parts[1]);
            var ap = h >= 12 ? 'PM' : 'AM';
            h = h % 12; if (h === 0) h = 12;
            return h + ':' + String(m).padStart(2, '0') + ' ' + ap;
        }

        function syncTimeHidden() {
            var h = parseInt($('#egHour').val());
            var m = parseInt($('#egMin').val());
            var ap = $('#egAmPm').val();
            if (isNaN(h) || isNaN(m)) { $('#earlyGoTimeHidden').val(''); return; }
            var h24 = h;
            if (ap === 'AM') { if (h === 12) h24 = 0; }
            else { if (h !== 12) h24 = h + 12; }
            $('#earlyGoTimeHidden').val(String(h24).padStart(2, '0') + ':' + String(m).padStart(2, '0'));
        }

        function submitForm() {
            var internId = $('#internSelect').val();
            var earlyTime = $('#earlyGoTimeHidden').val();
            var reason = $('#reason').val().trim();

            if (!internId) { showToast('error', 'Please select an intern.'); return; }
            if (!earlyTime) { showToast('error', 'Please select a valid early go time.'); return; }
            if (!reason) { showToast('error', 'Please enter a reason.'); return; }

            var internName = $('#internSelect option:selected').text();
            var formattedTime = formatHiddenTime(earlyTime);

            Swal.fire({
                title: 'Confirm Early Go',
                html: 'Grant <strong>' + $('<div>').text(internName).html() + '</strong> early punch-out at <strong>' + formattedTime + '</strong>?<br><br>' +
                    '<small style="color:#6b7280;">Reason: ' + $('<div>').text(reason).html() + '</small>',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes, Grant',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#4f46e5'
            }).then(function (result) {
                if (!result.isConfirmed) return;

                var $btn = $('#submitBtn').prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Saving…');

                $.ajax({
                    url: 'hr_early_go.php',
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        intern_id: internId,
                        early_go_time: earlyTime,
                        reason: reason
                    },
                    success: function (res) {
                        $btn.prop('disabled', false).html('<i class="fas fa-check-circle mr-1"></i> Grant Early Go Permission');
                        if (res.success) {
                            Swal.fire({
                                icon: 'success', title: 'Early Go Granted!',
                                text: res.message,
                                confirmButtonColor: '#4f46e5'
                            }).then(function () { location.reload(); });
                        } else {
                            Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                        }
                    },
                    error: function () {
                        $btn.prop('disabled', false).html('<i class="fas fa-check-circle mr-1"></i> Grant Early Go Permission');
                        Swal.fire({ icon: 'error', title: 'Network Error', text: 'Please try again.' });
                    }
                });
            });
        }

        function formatHiddenTime(t) {
            if (!t) return '—';
            var parts = t.split(':');
            var h = parseInt(parts[0]), m = parseInt(parts[1]);
            var ap = h >= 12 ? 'PM' : 'AM';
            h = h % 12; if (h === 0) h = 12;
            return h + ':' + String(m).padStart(2, '0') + ' ' + ap;
        }

        function showToast(icon, msg) {
            Swal.fire({
                icon: icon, text: msg, toast: true, position: 'top-end',
                showConfirmButton: false, timer: 2500, timerProgressBar: true
            });
        }
    </script>

    <?php
    require_once('../include/sweetAlert.php');
    if (isset($_SESSION['alert_icon'])) {
        echo "<script>document.addEventListener('DOMContentLoaded',function(){Swal.mixin({toast:true,position:'top-end',showConfirmButton:false,timer:2500,timerProgressBar:true}).fire({icon:'{$_SESSION['alert_icon']}',title:'{$_SESSION['alert_text']}'});});</script>";
        unset($_SESSION['alert_icon'], $_SESSION['alert_text']);
    }
    ?>
</body>

</html>