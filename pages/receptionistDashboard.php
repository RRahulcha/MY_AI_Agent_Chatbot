<?php

session_start();
date_default_timezone_set('Asia/Kolkata');
error_reporting(E_ALL);
ini_set('display_errors', 0);

// ── Auth & role guard ──
if (empty($_SESSION['userId'])) {
    echo "<script>window.location.href='login.php';</script>";
    exit;
}
include('../include/checkPasswordStatus.php');

$userId = $_SESSION['userId'];
$designation = $_SESSION['designation'] ?? '';

// Guard to ensure ONLY receptionist can access this dashboard
if (strtolower(trim($designation)) !== 'receptionist') {
    echo '<script>alert("You are not authorized to access this page.");window.location.href="login.php";</script>';
    die();
}

include('../include/db_config.php');

// ── Modal flag ──
$showModal = !isset($_SESSION['modal_shown']);
if ($showModal)
    $_SESSION['modal_shown'] = true;

// ── Date helpers ──
$today = date('Y-m-d');
$todayMD = date('m-d');
$firstDate = date('Y-m-01');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$weekStart = date('Y-m-d', strtotime('monday this week'));
$userIdEsc = mysqli_real_escape_string($con, $userId);

// Sync WFH status dynamically
$columnsResult = mysqli_query($con, "SHOW COLUMNS FROM wfhRequests");
$existingColumns = [];
if ($columnsResult) {
    while ($colRow = mysqli_fetch_assoc($columnsResult)) {
        $existingColumns[] = strtolower($colRow['Field']);
    }
}
$hasFromDate = in_array('fromdate', $existingColumns);
$hasToDate = in_array('todate', $existingColumns);
$hasWfhDate = in_array('wfhdate', $existingColumns);

$dateSelect = "wfhDate AS fromDate, wfhDate AS toDate";
if ($hasFromDate && $hasToDate) {
    $dateSelect = $hasWfhDate ? "COALESCE(fromDate, wfhDate) AS fromDate, COALESCE(toDate, wfhDate) AS toDate" : "fromDate, toDate";
} elseif ($hasWfhDate) {
    $dateSelect = "wfhDate AS fromDate, wfhDate AS toDate";
}

$wfhQuery = mysqli_query($con, "SELECT $dateSelect FROM wfhRequests WHERE userid = '$userIdEsc' AND status = 'Approved'");
$wfhToday = false;
if ($wfhQuery) {
    while ($wfhRow = mysqli_fetch_assoc($wfhQuery)) {
        $fDate = $wfhRow['fromDate'];
        $tDate = $wfhRow['toDate'];
        if ($today >= $fDate && $today <= $tDate) {
            $wfhToday = true;
            break;
        }
    }
}

if ($wfhToday) {
    mysqli_query($con, "UPDATE signup SET type = 'Online' WHERE userId = '$userIdEsc'");
} else {
    mysqli_query($con, "UPDATE signup SET type = mode WHERE userId = '$userIdEsc'");
}

// ── Receptionist User record ──
$userData = mysqli_fetch_assoc(mysqli_query(
    $con,
    "SELECT fullName, expectedPunchIn, expectedPunchOut, type, start_date, office, designation
     FROM signup WHERE userId='$userIdEsc'"
));
$expectedPunchIn2 = $userData['expectedPunchIn'] ?? '09:30:00';
$expectedPunchOut = $userData['expectedPunchOut'] ?? '18:30:00';
$userMode = !empty($userData['type']) ? $userData['type'] : 'Offline';
$userStartDate = $userData['start_date'] ?? '';
$expectedPunchInBase = $userData['expectedPunchIn'] ?? '09:30:00';
$userOffice = $userData['office'] ?? 'Kinwat';
$userDesignation = $userData['designation'] ?? 'Receptionist';
$userOfficeEsc = mysqli_real_escape_string($con, $userOffice);

// ── Birthdays (Filtered by User's office) ──
$todayBirthdays = [];
$bdQ = mysqli_query($con, "SELECT fullName, mobileNo FROM signup WHERE status='Active' AND DATE_FORMAT(dob,'%m-%d')='$todayMD' AND LOWER(office)=LOWER('$userOfficeEsc')");
if ($bdQ) {
    while ($row = mysqli_fetch_assoc($bdQ)) {
        $todayBirthdays[] = $row;
    }
}

// Late punch-in override
$lpiQ = mysqli_query($con, "SELECT delayed_time FROM late_punch_in WHERE userId='$userIdEsc' AND date='$today'");
if (mysqli_num_rows($lpiQ) > 0)
    $expectedPunchIn2 = mysqli_fetch_assoc($lpiQ)['delayed_time'];

// ── Tour check ──
$tourQ = mysqli_query($con, "SELECT 1 FROM tour WHERE userId='$userIdEsc' AND '$today' BETWEEN DATE(fromDate) AND DATE(toDate) AND status IN ('Approved','Pending') LIMIT 1");
$onTour = mysqli_num_rows($tourQ) > 0;
$attendanceTableName = $onTour ? 'tourAttendance' : 'attendance';

// ── Today's attendance ──
$attTodayQ = mysqli_query($con, "SELECT punchInTime,punchOutTime FROM $attendanceTableName WHERE userId='$userIdEsc' AND date='$today' ORDER BY dateTime DESC LIMIT 1");
$attRow = mysqli_fetch_assoc($attTodayQ) ?: [];
$punchInTime = $attRow['punchInTime'] ?? '';
$punchOutTime = $attRow['punchOutTime'] ?? '';
$hasPunchIn = !empty($punchInTime) && $punchInTime !== '00:00:00';
$hasPunchOut = !empty($punchOutTime) && $punchOutTime !== '00:00:00';
$notPunchedYet = !$hasPunchIn;
$isPunchedIn = $hasPunchIn && !$hasPunchOut;

$attCountQ = mysqli_query($con, "SELECT COUNT(*) AS cnt FROM $attendanceTableName WHERE userId='$userIdEsc' AND date='$today'");
$attendanceCount = (int) (mysqli_fetch_assoc($attCountQ)['cnt'] ?? 0);

// ── Hours worked ──
$hours_difference = 0;
if ($hasPunchIn) {
    $in = strtotime($punchInTime);
    $out = $hasPunchOut ? strtotime($punchOutTime) : time();
    $hours_difference = max(0, ($out - $in) / 3600);
}
$over_time = $hours_difference > 8 ? round($hours_difference - 8, 1) : 0;

// ── Today progress display ──
$totalMinutesToday = (int) round($hours_difference * 60);
$displayH = intdiv($totalMinutesToday, 60);
$displayM = $totalMinutesToday % 60;
$perc_today = min(100, max(0, ($hours_difference / 8) * 100));

// ── Lunch status ──
$lunchStatus = 'not_started';
$lunchDuration = 0;
$lunchQ = mysqli_query($con, "SELECT status,totalDuration FROM TaskEntryNew WHERE userId='$userIdEsc' AND task='Lunch Break' AND DATE(startTime)=CURDATE() ORDER BY taskId DESC LIMIT 1");
if ($lunchQ && mysqli_num_rows($lunchQ) > 0) {
    $lunch = mysqli_fetch_assoc($lunchQ);
    if ($lunch['status'] === 'Running')
        $lunchStatus = 'started';
    elseif ($lunch['status'] === 'Finished') {
        $lunchStatus = 'completed';
        $lunchDuration = $lunch['totalDuration'] > 0 ? round($lunch['totalDuration'] / 60) : 0;
    }
}

// ── Week + Month + Overtime stats ──
$statsQ = mysqli_query($con, "
    SELECT
        /* ── Week (weekStart → today) ── */
        SUM(
            CASE
                WHEN date BETWEEN '$weekStart' AND '$today'
                     AND punchInTime IS NOT NULL AND punchInTime != '00:00:00'
                     AND (
                            date = '$today'
                            OR (punchOutTime IS NOT NULL AND punchOutTime != '00:00:00')
                        )
                THEN TIMESTAMPDIFF(MINUTE, punchInTime,
                        CASE
                            WHEN date = '$today' AND (punchOutTime IS NULL OR punchOutTime = '00:00:00')
                                 THEN CURTIME()
                            ELSE punchOutTime
                        END
                     )
                ELSE 0
            END
        ) AS weekMin,

        /* ── Month (firstDate → today) ── */
        SUM(
            CASE
                WHEN date BETWEEN '$firstDate' AND '$today'
                     AND punchInTime IS NOT NULL AND punchInTime != '00:00:00'
                     AND (
                            date = '$today'
                            OR (punchOutTime IS NOT NULL AND punchOutTime != '00:00:00')
                        )
                THEN TIMESTAMPDIFF(MINUTE, punchInTime,
                        CASE
                            WHEN date = '$today' AND (punchOutTime IS NULL OR punchOutTime = '00:00:00')
                                 THEN CURTIME()
                            ELSE punchOutTime
                        END
                     )
                ELSE 0
            END
        ) AS monthMin,

        /* ── Overtime (firstDate → yesterday, only completed days) ── */
        SUM(
            CASE
                WHEN date BETWEEN '$firstDate' AND '$yesterday'
                     AND punchInTime  IS NOT NULL AND punchInTime  != '00:00:00'
                     AND punchOutTime IS NOT NULL AND punchOutTime != '00:00:00'
                     AND TIMESTAMPDIFF(MINUTE, punchInTime, punchOutTime) > 480   -- > 8h
                THEN ROUND(
                        (TIMESTAMPDIFF(MINUTE, punchInTime, punchOutTime) - 480) / 60,
                     1)
                ELSE 0
            END
        ) AS overtimeHrs

    FROM attendance
    WHERE userId = '$userIdEsc'
      AND date BETWEEN '$weekStart' AND '$today'
");

$statsRow = mysqli_fetch_assoc($statsQ);
$weekMin = (int) max(0, $statsRow['weekMin'] ?? 0);
$monthMin = (int) max(0, $statsRow['monthMin'] ?? 0);
$hours_overtiming_php = (float) ($statsRow['overtimeHrs'] ?? 0);

$week_hr = intdiv($weekMin, 60);
$week_mn = $weekMin % 60;
$perc_week = min(100, max(0, ($weekMin / (56 * 60)) * 100));

$mh = intdiv($monthMin, 60);
$mm = $monthMin % 60;
$perc_month = min(100, max(0, ($monthMin / (240 * 60)) * 100));

// ── Running task check ──
$hasRunning = mysqli_num_rows(mysqli_query($con, "SELECT taskId FROM TaskEntryNew WHERE userId='$userIdEsc' AND status='Running' LIMIT 1")) > 0;

// ── Early-go unlock check ──
$_po_egRow = mysqli_fetch_assoc(mysqli_query(
    $con,
    "SELECT early_go_time FROM early_go
     WHERE userId='$userIdEsc' AND date='$today' AND hr_granted=1
     ORDER BY id DESC LIMIT 1"
));

$_po_earlyGoTs = null;
$_po_hasHrEarlyGo = false;

if ($_po_egRow && !empty($_po_egRow['early_go_time'])) {
    $_po_earlyGoTs = strtotime(
        date('Y-m-d') . ' ' . $_po_egRow['early_go_time']
    );
    $_po_hasHrEarlyGo = true;
}

$_po_expectedOutTs = strtotime(
    date('Y-m-d') . ' ' . $expectedPunchOut
);

$_po_targetOutTs = (
    $_po_hasHrEarlyGo &&
    $_po_earlyGoTs < $_po_expectedOutTs
)
    ? $_po_earlyGoTs
    : $_po_expectedOutTs;

$_po_unlockTs = $_po_targetOutTs - (15 * 60);
$_po_unlocked = (time() >= $_po_unlockTs);
$_po_unlockDisp = date('h:i A', $_po_unlockTs);

// ── Punch URLs ──
$punchInUrl = $userMode === 'Online' ? 'captureSelfie.php?punch=in' : 'qrCodeScanner_copy.php?punch=In';
$punchOutUrl = $userMode === 'Online' ? 'captureSelfie.php?punch=out' : 'qrCodeScanner_copy.php?punch=Out';

// ── Grace-time check ──
$graceTime = date('H:i:s', strtotime($expectedPunchIn2 . ' +30 minutes'));
$nowTime = date('H:i:s');

// ══════════════════════════════════════════════════
// WORKFORCE STATS - FILTERED BY KINWAT BRANCH
// ══════════════════════════════════════════════════

$presentQ = mysqli_query($con, "SELECT COUNT(DISTINCT a.userId) AS c FROM attendance a JOIN signup s ON a.userId=s.userId WHERE a.date='$today' AND s.status='Active' AND LOWER(s.office)=LOWER('$userOfficeEsc')");
$present = mysqli_fetch_assoc($presentQ)['c'];

$tourCountQ = mysqli_query($con, "SELECT COUNT(DISTINCT ta.userId) AS c FROM tourAttendance ta JOIN signup s ON ta.userId=s.userId WHERE ta.date='$today' AND s.status='Active' AND LOWER(s.office)=LOWER('$userOfficeEsc')");
$onTourCount = mysqli_fetch_assoc($tourCountQ)['c'];

$pendingLeaveQ = mysqli_query($con, "SELECT COUNT(*) AS c FROM leaves l JOIN signup s ON l.userId=s.userId WHERE TRIM(LOWER(l.status))='pending' AND l.leave_type!='Auto' AND l.to_date >= CURDATE() AND LOWER(s.office)=LOWER('$userOfficeEsc')");
$pendingLeaves = mysqli_fetch_assoc($pendingLeaveQ)['c'];

// ── Absent count (LEFT JOIN) ──
$absentQ = mysqli_query($con, "
    SELECT COUNT(s.userId) AS c
    FROM signup s
    LEFT JOIN attendance      a  ON a.userId  = s.userId  AND a.date     = '$today'
    LEFT JOIN tourAttendance  ta ON ta.userId = s.userId  AND ta.date    = '$today'
    LEFT JOIN leaves          l  ON l.userId  = s.userId
                                 AND l.status  = 'Approved'
                                 AND '$today' BETWEEN DATE(l.from_date) AND DATE(l.to_date)
    WHERE s.designation != 'admin'
      AND s.status = 'Active'
      AND LOWER(s.office) = LOWER('$userOfficeEsc')
      AND a.userId  IS NULL
      AND ta.userId IS NULL
      AND l.userId  IS NULL
");
$absentCount = mysqli_fetch_assoc($absentQ)['c'] ?? 0;

// Today's leave list
$todayLeavesQ = mysqli_query($con, "
    SELECT COALESCE(s.fullName,'Not Found') AS fullName, l.reason,
           DATE_FORMAT(l.from_date,'%d %b') AS fdate,
           DATE_FORMAT(l.to_date,'%d %b %Y') AS tdate,
           COALESCE(a.fullName, l.approved_by) AS approved_by_name
    FROM leaves l
    LEFT JOIN signup s ON TRIM(LOWER(l.userId))=TRIM(LOWER(s.userId))
    LEFT JOIN signup a ON TRIM(LOWER(l.approved_by))=TRIM(LOWER(a.userId))
    WHERE TRIM(LOWER(l.status))='approved' AND CURDATE() BETWEEN DATE(l.from_date) AND DATE(l.to_date) AND s.status = 'Active' AND LOWER(s.office)=LOWER('$userOfficeEsc')
    ORDER BY s.fullName
");
$todayLeaveCnt = mysqli_num_rows($todayLeavesQ);
$onLeave = $todayLeaveCnt;

// ── Absent today list ──
$absentListQ = mysqli_query($con, "
    SELECT s.fullName, s.type, s.office
    FROM signup s
    LEFT JOIN attendance      a  ON a.userId  = s.userId  AND a.date     = '$today'
    LEFT JOIN tourAttendance  ta ON ta.userId = s.userId  AND ta.date    = '$today'
    LEFT JOIN leaves          l  ON l.userId  = s.userId
                                 AND l.status  = 'Approved'
                                 AND '$today' BETWEEN DATE(l.from_date) AND DATE(l.to_date)
    WHERE s.designation != 'admin'
      AND s.status = 'Active'
      AND LOWER(s.office) = LOWER('$userOfficeEsc')
      AND a.userId  IS NULL
      AND ta.userId IS NULL
      AND l.userId  IS NULL
    ORDER BY s.fullName
");
$absentCount = mysqli_num_rows($absentListQ);

// ── Personal Monthly Attendance Stats (Jr Emp Style) ──
$holidaysMap = [];
$hRes = mysqli_query($con, "SELECT from_date,to_date,title FROM holidays WHERE from_date<='$today' AND to_date>='$firstDate'");
if ($hRes) {
    while ($hRow = mysqli_fetch_assoc($hRes)) {
        for ($d = strtotime($hRow['from_date']); $d <= strtotime($hRow['to_date']); $d += 86400) {
            $hd = date('Y-m-d', $d);
            if ($hd >= $firstDate && $hd <= $today)
                $holidaysMap[$hd] = $hRow['title'];
        }
    }
}

$leaveDatesMap = [];
$lRes = mysqli_query($con, "SELECT from_date,to_date,leave_type FROM leaves WHERE userId='$userIdEsc' AND status='Approved' AND from_date<='$today' AND to_date>='$firstDate'");
if ($lRes) {
    while ($lRow = mysqli_fetch_assoc($lRes)) {
        for ($d = strtotime($lRow['from_date']); $d <= strtotime($lRow['to_date']); $d += 86400) {
            $ld = date('Y-m-d', $d);
            if ($ld >= $firstDate && $ld <= $today)
                $leaveDatesMap[$ld] = $lRow['leave_type'];
        }
    }
}

$isOfflineIntern = strtolower($userData['type'] ?? '') === 'offline';
$validRODatesThisMonth = [];
if ($isOfflineIntern) {
    $roQ2 = mysqli_query($con, "SELECT from_date,to_date FROM leaves WHERE userId='$userIdEsc' AND leave_type='RO' AND status='Approved' ORDER BY from_date ASC");
    $allRO = [];
    if ($roQ2) {
        while ($roRow = mysqli_fetch_assoc($roQ2)) {
            for ($d = strtotime($roRow['from_date']); $d <= strtotime($roRow['to_date']); $d += 86400) {
                $rd = date('Y-m-d', $d);
                if ($rd >= $firstDate && $rd <= $today)
                    $allRO[] = $rd;
            }
        }
    }
    sort($allRO);
    $validRODatesThisMonth = array_slice($allRO, 0, 2);
}

$regMap = [];
$regRes = mysqli_query($con, "SELECT date, punch_in FROM attendance_regularization WHERE user_id='$userIdEsc' AND status='Approved' AND date BETWEEN '$firstDate' AND '$today'");
if ($regRes) {
    while ($rRow = mysqli_fetch_assoc($regRes)) {
        $regMap[$rRow['date']] = $rRow['punch_in'];
    }
}

$lateIntimationsMap = [];
$liRes = mysqli_query($con, "SELECT date FROM late_punch_in WHERE userId='$userIdEsc' AND date BETWEEN '$firstDate' AND '$today'");
if ($liRes) {
    while ($liRow = mysqli_fetch_assoc($liRes)) {
        $lateIntimationsMap[$liRow['date']] = true;
    }
}

$attMap = [];
$aRes = mysqli_query($con, "SELECT date,punchInTime,punchOutTime,status,lateMark FROM attendance WHERE userId='$userIdEsc' AND date BETWEEN '$firstDate' AND '$today'");
if ($aRes) {
    while ($aRow = mysqli_fetch_assoc($aRes)) {
        $attMap[$aRow['date']] = $aRow;
    }
}

$tourAttMap = [];
$tRes = mysqli_query($con, "SELECT date,punchInTime FROM tourAttendance WHERE userId='$userIdEsc' AND punchInTime!='00:00:00' AND date BETWEEN '$firstDate' AND '$today'");
if ($tRes) {
    while ($tRow = mysqli_fetch_assoc($tRes)) {
        $tourAttMap[$tRow['date']] = $tRow;
    }
}

$presentDays = $absentDays = $lateDays = $leaveTaken = 0;
$period = new DatePeriod(new DateTime($firstDate), new DateInterval('P1D'), new DateTime(date('Y-m-d', strtotime($today . ' +1 day'))));
foreach ($period as $dateObj) {
    $day = $dateObj->format('Y-m-d');
    $dow = $dateObj->format('D');
    if ($day > $today)
        continue;
    if ($dow === 'Sun')
        continue;
    if ($dow === 'Sat' && !$isOfflineIntern && (!isset($leaveDatesMap[$day]) || $leaveDatesMap[$day] !== 'RO'))
        continue;
    if (!empty($userStartDate) && $day < $userStartDate)
        continue;
    if (isset($holidaysMap[$day]))
        continue;
    if (isset($tourAttMap[$day])) {
        $presentDays++;
        continue;
    }
    if (isset($attMap[$day]) && !empty($attMap[$day]['punchInTime']) && $attMap[$day]['punchInTime'] !== '00:00:00') {
        $att = $attMap[$day];
        $isLate = strtolower($att['lateMark'] ?? 'no') === 'yes';
        $isAbsentLate = false;

        if ($isLate) {
            $diffMins = (strtotime("1970-01-01 {$att['punchInTime']}") - strtotime("1970-01-01 " . ($expectedPunchInBase ?? '09:30:00'))) / 60;
            // Receptionist is never marked absent for late punch in
            $isAbsentLate = false;
        }

        if ($isLate || $isAbsentLate) {
            if (isset($lateIntimationsMap[$day])) {
                $isAbsentLate = false;
                $isLate = true;
            }
        }

        if (isset($regMap[$day])) {
            $regPunchIn = $regMap[$day];
            $isLate = (strtotime($regPunchIn) > strtotime($expectedPunchInBase ?? '09:30:00'));
            $isAbsentLate = false;
        }

        if ($isAbsentLate)
            $absentDays++;
        else {
            $presentDays++;
            if ($isLate)
                $lateDays++;
        }
        continue;
    }
    if (isset($leaveDatesMap[$day])) {
        if ($leaveDatesMap[$day] === 'RO') {
            if ($isOfflineIntern && !in_array($day, $validRODatesThisMonth))
                $absentDays++;
        } else
            $leaveTaken++;
        continue;
    }
    if ($day < $today)
        $absentDays++;
}

$hrName = explode(' ', $userData['fullName'])[0];
$greeting = (date('H') < 12) ? 'Good morning' : ((date('H') < 17) ? 'Good afternoon' : 'Good evening');

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receptionist Dashboard - Kinwat Branch</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <?php include('../partials/_links.php') ?>

    <style>
        :root {
            --ink: #111827;
            --ink-2: #374151;
            --ink-3: #6b7280;
            --ink-4: #9ca3af;
            --surface: #ffffff;
            --surface-2: #f9fafb;
            --surface-3: #f3f4f6;
            --border: #e5e7eb;
            --border-2: #d1d5db;
            --accent: #1d4ed8;
            --accent-2: #1e40af;
            --accent-bg: #eff6ff;
            --accent-mid: #3b82f6;
            --green: #059669;
            --green-bg: #ecfdf5;
            --amber: #d97706;
            --amber-bg: #fffbeb;
            --red: #dc2626;
            --red-bg: #fef2f2;
            --purple: #7c3aed;
            --purple-bg: #f5f3ff;
            --teal: #0d9488;
            --teal-bg: #f0fdfa;
            --orange: #ea580c;
            --orange-bg: #fff7ed;
            --radius: 10px;
            --radius-lg: 14px;
            --shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.07);
        }

        body {
            font-family: 'DM Sans', sans-serif;
            background: #f0f2f7;
            color: var(--ink);
            font-size: 14px;
            line-height: 1.6;
        }

        .hr-wrap {
            padding: 0 0 40px 0;
        }

        .page-header {
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            padding: 20px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 28px;
        }

        .page-header__date {
            font-size: 12px;
            color: var(--ink-3);
            background: var(--surface-2);
            border: 1px solid var(--border);
            padding: 6px 14px;
            border-radius: 99px;
            font-weight: 500;
        }

        .section {
            padding: 0 28px;
            margin-bottom: 28px;
        }

        .section-label {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: var(--ink-3);
            margin-bottom: 12px;
        }

        .birthday-bar {
            background: linear-gradient(90deg, #fef3c7, #fffbeb);
            border-bottom: 1px solid #fde68a;
            padding: 9px 28px;
            font-size: 13px;
            color: #92400e;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
            overflow: hidden;
        }

        .birthday-bar .icon {
            font-size: 15px;
            flex-shrink: 0;
        }

        .birthday-bar marquee {
            flex: 1;
        }

        .birthday-bar .wish-chip {
            display: inline-block;
            background: #d97706;
            color: #fff;
            font-size: 11px;
            font-weight: 600;
            padding: 2px 10px;
            border-radius: 99px;
            text-decoration: none;
            margin-left: 6px;
            transition: background 0.15s;
        }

        .birthday-bar .wish-chip:hover {
            background: #b45309;
        }

        .two-col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        .three-col {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 18px;
        }

        .four-col {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
        }

        @media (max-width:1200px) {
            .four-col {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width:900px) {

            .two-col,
            .three-col {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width:640px) {
            .four-col {
                grid-template-columns: 1fr;
            }
        }

        .stat-mini-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        .stat-mini {
            border-radius: 10px;
            padding: 12px 14px;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .stat-mini.green {
            background: var(--green-bg);
            border: 1px solid #a7f3d0;
        }

        .stat-mini.red {
            background: var(--red-bg);
            border: 1px solid #fecaca;
        }

        .stat-mini.amber {
            background: var(--amber-bg);
            border: 1px solid #fde68a;
        }

        .stat-mini.purple {
            background: var(--purple-bg);
            border: 1px solid #ddd6fe;
        }

        .stat-mini__val {
            font-size: 24px;
            font-weight: 700;
            line-height: 1;
        }

        .stat-mini__lbl {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .4px;
        }

        .stat-mini.green .stat-mini__val,
        .stat-mini.green .stat-mini__lbl {
            color: var(--green);
        }

        .stat-mini.red .stat-mini__val,
        .stat-mini.red .stat-mini__lbl {
            color: var(--red);
        }

        .stat-mini.amber .stat-mini__val,
        .stat-mini.amber .stat-mini__lbl {
            color: var(--amber);
        }

        .stat-mini.purple .stat-mini__val,
        .stat-mini.purple .stat-mini__lbl {
            color: var(--purple);
        }

        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        .card__header {
            padding: 16px 20px 12px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .card__title {
            font-size: 14px;
            font-weight: 600;
            color: var(--ink);
        }

        .card__subtitle {
            font-size: 12px;
            color: var(--ink-3);
            margin-top: 2px;
        }

        .card__body {
            padding: 16px 20px;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 16px 18px;
            text-decoration: none;
            display: flex;
            flex-direction: column;
            gap: 8px;
            box-shadow: var(--shadow);
            transition: box-shadow 0.18s, transform 0.18s;
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            border-radius: var(--radius-lg) var(--radius-lg) 0 0;
        }

        .stat-card.blue::before {
            background: var(--accent);
        }

        .stat-card.green::before {
            background: var(--green);
        }

        .stat-card.amber::before {
            background: var(--amber);
        }

        .stat-card.red::before {
            background: var(--red);
        }

        .stat-card.purple::before {
            background: var(--purple);
        }

        .stat-card.teal::before {
            background: var(--teal);
        }

        .stat-card.orange::before {
            background: var(--orange);
        }

        .stat-card__icon {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
        }

        .stat-card.blue .stat-card__icon {
            background: var(--accent-bg);
            color: var(--accent);
        }

        .stat-card.green .stat-card__icon {
            background: var(--green-bg);
            color: var(--green);
        }

        .stat-card.amber .stat-card__icon {
            background: var(--amber-bg);
            color: var(--amber);
        }

        .stat-card.red .stat-card__icon {
            background: var(--red-bg);
            color: var(--red);
        }

        .stat-card.purple .stat-card__icon {
            background: var(--purple-bg);
            color: var(--purple);
        }

        .stat-card.teal .stat-card__icon {
            background: var(--teal-bg);
            color: var(--teal);
        }

        .stat-card.orange .stat-card__icon {
            background: var(--orange-bg);
            color: var(--orange);
        }

        .stat-card__val {
            font-size: 28px;
            font-weight: 600;
            color: var(--ink);
            line-height: 1;
        }

        .stat-card__lbl {
            font-size: 12px;
            color: var(--ink-3);
            font-weight: 500;
        }

        .btn-punch {
            background: linear-gradient(135deg, var(--accent), var(--accent-2));
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 10px 22px;
            font-size: 13px;
            font-weight: 700;
            box-shadow: 0 3px 12px rgba(29, 78, 216, .28);
            cursor: pointer;
            transition: opacity .2s;
            display: inline-block;
            text-decoration: none;
        }

        .btn-punch:hover {
            opacity: .88;
            color: #fff;
        }

        .progress.progress-md {
            height: 6px;
            border-radius: 99px;
        }

        .charts-data .progress {
            background: #eef1f7;
            border-radius: 99px;
        }

        .progress-bar {
            border-radius: 99px;
        }

        .border-block {
            border: 1px solid #e8edf5;
            padding: 10px 14px;
            border-radius: 10px;
            background: #fafbfd;
        }

        .tit {
            font-size: 11px;
            margin-bottom: .4rem;
            color: #64748b;
            font-weight: 600;
            letter-spacing: .3px;
            text-transform: uppercase;
        }

        .progress-wrap {
            position: relative;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .progress-wrap .progress {
            flex: 1;
            cursor: pointer;
        }

        .prog-tooltip {
            display: none;
            position: absolute;
            bottom: calc(100% + 8px);
            left: 50%;
            transform: translateX(-50%);
            background: #1e293b;
            color: #f1f5f9;
            font-size: 11px;
            font-weight: 600;
            padding: 5px 10px;
            border-radius: 7px;
            white-space: nowrap;
            z-index: 99;
            pointer-events: none;
            box-shadow: 0 2px 10px rgba(0, 0, 0, .18);
        }

        .prog-tooltip::after {
            content: '';
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translateX(-50%);
            border: 5px solid transparent;
            border-top-color: #1e293b;
        }

        .progress-wrap:hover .prog-tooltip {
            display: block;
        }

        .punch-times {
            margin-top: 8px;
        }

        .punch-time-row {
            font-size: 13px;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .punch-time-row strong {
            color: var(--ink-2);
            min-width: 80px;
        }

        .punch-val {
            font-weight: 500;
            color: var(--ink-3);
        }

        .punch-val.has-value {
            color: var(--ink);
            font-weight: 600;
        }

        .punch-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .punch-dot.in {
            background: var(--green);
        }

        .punch-dot.out {
            background: var(--red);
        }

        .lunch-summary {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 14px;
            padding-top: 12px;
            border-top: 1px solid #eef1f7;
        }

        .lunch-item {
            flex: 1;
            text-align: center;
        }

        .lunch-title {
            font-size: 11px;
            font-weight: 700;
            color: #475569;
            letter-spacing: .5px;
            text-transform: uppercase;
        }

        .lunch-value {
            font-size: 11px;
            color: #64748b;
            margin-top: 5px;
        }

        .lunch-divider {
            width: 1px;
            height: 28px;
            background: #e5e5e5;
        }

        .alert-warning {
            color: #92400e;
            background: #fffbeb;
            border: 1.5px solid #fcd34d;
            border-radius: 10px;
            font-size: 13px;
            padding: 10px 14px;
        }

        .blink-alert {
            animation: blink 1s infinite;
            font-weight: 600;
        }

        @keyframes blink {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: 0;
            }
        }

        .att-row {
            display: flex;
            gap: 10px;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid var(--border);
        }

        .att-item {
            flex: 1;
            text-align: center;
            padding: 10px 6px;
            border-radius: var(--radius);
            background: var(--surface-2);
            border: 1px solid var(--border);
        }

        .att-item__val {
            font-size: 22px;
            font-weight: 600;
            line-height: 1;
        }

        .att-item__lbl {
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 4px;
            color: var(--ink-3);
        }

        .att-item.present .att-item__val {
            color: var(--green);
        }

        .att-item.leave .att-item__val {
            color: var(--purple);
        }

        .att-item.tour .att-item__val {
            color: var(--teal);
        }

        .att-item.absent .att-item__val {
            color: var(--red);
        }

        .view-btn {
            display: inline-block;
            padding: 6px 16px;
            border: 1px solid var(--border-2);
            border-radius: 99px;
            font-size: 12px;
            font-weight: 600;
            color: var(--ink-2);
            background: transparent;
            cursor: pointer;
            transition: background 0.13s;
            text-decoration: none;
        }

        .view-btn:hover {
            background: var(--accent-bg);
            border-color: #bfdbfe;
            color: var(--accent);
        }

        .absent-list {
            list-style: none;
            padding: 0;
            margin: 0;
            max-height: 220px;
            overflow-y: auto;
        }

        .absent-list li {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 9px 0;
            border-bottom: 1px solid var(--border);
            font-size: 13px;
            color: var(--ink-2);
        }

        .absent-list li:last-child {
            border-bottom: none;
        }

        .absent-list .name {
            font-weight: 500;
        }

        .absent-list .meta {
            font-size: 11px;
            color: var(--ink-3);
        }

        .mode-pill {
            display: inline-block;
            font-size: 10px;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 99px;
        }

        .mode-pill.online {
            background: var(--green-bg);
            color: var(--green);
        }

        .mode-pill.offline {
            background: var(--orange-bg);
            color: var(--orange);
        }

        .absent-empty {
            text-align: center;
            padding: 24px 0;
            color: var(--ink-3);
            font-size: 13px;
        }

        .absent-empty i {
            font-size: 1.6rem;
            color: var(--green);
            opacity: 0.5;
            display: block;
            margin-bottom: 6px;
        }

        .modal-content {
            border: none;
            border-radius: var(--radius-lg);
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
        }

        .modal-header {
            background: var(--accent);
            padding: 16px 20px;
        }

        .modal-header .modal-title {
            color: #fff;
            font-size: 15px;
            font-weight: 600;
        }

        .modal-header .btn-close {
            filter: invert(1);
            opacity: 0.8;
        }

        .modal-footer {
            background: var(--surface-2);
            border-top: 1px solid var(--border);
            padding: 12px 20px;
        }

        .leave-table,
        .absent-table {
            width: 100%;
            border-collapse: collapse;
        }

        .leave-table th,
        .absent-table th {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--ink-3);
            background: var(--surface-2);
            border-bottom: 1px solid var(--border);
            padding: 10px 14px;
        }

        .leave-table td,
        .absent-table td {
            padding: 10px 14px;
            border-bottom: 1px solid var(--border);
            font-size: 13px;
            color: var(--ink-2);
        }

        .leave-table tr:last-child td,
        .absent-table tr:last-child td {
            border-bottom: none;
        }

        .leave-table tr:hover td,
        .absent-table tr:hover td {
            background: var(--surface-2);
        }

        .cam-modal {
            display: none;
            position: fixed;
            z-index: 9999;
            inset: 0;
            background: rgba(15, 23, 42, .65);
            justify-content: center;
            align-items: center;
        }

        .cam-modal-inner {
            background: #fff;
            padding: 24px;
            border-radius: 16px;
            text-align: center;
            width: 360px;
            max-width: 95vw;
        }

        .cam-modal-inner video {
            width: 300px;
            height: 300px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
        }
    </style>
</head>

<body>
    <?php if (isset($_SESSION['alert_icon'])): ?>
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <script>document.addEventListener('DOMContentLoaded', function () { Swal.mixin({ toast: true, position: 'top-end', showConfirmButton: false, timer: 2500, timerProgressBar: true }).fire({ icon: '<?= $_SESSION['alert_icon'] ?>', title: '<?= $_SESSION['alert_text'] ?>' }); });</script>
        <?php unset($_SESSION['alert_icon'], $_SESSION['alert_title'], $_SESSION['alert_text']); endif; ?>

    <div class="container-scroller">
        <?php include('../partials/_navbar.php'); ?>
        <div class="container-fluid page-body-wrapper">
            <?php include('../partials/_sidebar.php'); ?>

            <div class="main-panel">
                <div class="hr-wrap">

                    <!-- Birthday Banner -->
                    <?php if (!empty($todayBirthdays)): ?>
                        <div class="birthday-bar">
                            <span class="icon">🎂</span>
                            <marquee behavior="scroll" direction="left" scrollamount="4" onmouseover="this.stop();"
                                onmouseout="this.start();">
                                <strong>Today's Birthdays:</strong>
                                <?php foreach ($todayBirthdays as $b):
                                    $fn = $b['fullName'];
                                    $first = explode(' ', $fn)[0];
                                    $wa = 'https://wa.me/?text=' . urlencode("Happy Birthday $first 🎉");
                                    echo "<span style='margin:0 30px;'>🎉 $fn <a class='wish-chip' href='$wa' target='_blank'>Wish</a></span>";
                                endforeach; ?>
                            </marquee>
                        </div>
                    <?php endif; ?>

                    <!-- Page Header -->
                    <div class="page-header">
                        <div>
                            <h2 style="font-size:20px;font-weight:700;color:var(--ink);margin:0;"><?= $greeting ?>,
                                <?= htmlspecialchars($hrName) ?>!
                            </h2>
                            <p style="font-size:12px;color:var(--ink-3);margin:4px 0 0 0;">Role:
                                <?= htmlspecialchars($userDesignation) ?> | Branch: <?= htmlspecialchars($userOffice) ?>
                            </p>
                        </div>
                        <div class="page-header__date"><?= date('l, d F Y') ?></div>
                    </div>

                    <!-- Company Stats (Active Interns Card Removed) -->
                    <div class="section">
                        <div class="section-label">Company Overview</div>
                        <div class="four-col">
                            <a href="down_tracking.php?type=present" class="stat-card teal">
                                <div class="stat-card__icon"><i class="bi bi-building-check"></i></div>
                                <div class="stat-card__val"><?= $present ?></div>
                                <div class="stat-card__lbl">Present Today</div>
                            </a>
                            <a href="down_tracking.php?type=leave" class="stat-card purple">
                                <div class="stat-card__icon"><i class="bi bi-calendar-x"></i></div>
                                <div class="stat-card__val"><?= $onLeave ?></div>
                                <div class="stat-card__lbl">On Leave</div>
                            </a>
                            <a href="#" class="stat-card orange" data-bs-toggle="modal" data-bs-target="#absentModal">
                                <div class="stat-card__icon"><i class="bi bi-person-x"></i></div>
                                <div class="stat-card__val"><?= $absentCount ?></div>
                                <div class="stat-card__lbl">Absent Today</div>
                            </a>
                            <a href="leave_approval.php" class="stat-card red">
                                <div class="stat-card__icon"><i class="bi bi-hourglass-split"></i></div>
                                <div class="stat-card__val"><?= $pendingLeaves ?></div>
                                <div class="stat-card__lbl">Leaves Pending</div>
                            </a>
                        </div>
                    </div>

                    <!-- Receptionist Personal Stats: Timesheet + Statistics -->
                    <div class="section">
                        <div class="section-label">My Timesheet</div>
                        <div class="two-col">
                            <!-- Timesheet -->
                            <div class="card">
                                <div class="card__body">
                                    <h4 class="card__title" style="margin-bottom:12px;">Today's Work <span
                                            style="font-size:10px;color:var(--ink-3);font-weight:400;">—
                                            <?= date('d M Y') ?></span></h4>
                                    <div class="row">
                                        <div class="col-6">
                                            <div class="punch-times">
                                                <div class="punch-time-row">
                                                    <span class="punch-dot in"></span>
                                                    <strong>Punch In:</strong>
                                                    <span class="punch-val <?= $hasPunchIn ? 'has-value' : '' ?>">
                                                        <?= $hasPunchIn ? date('h:i:s A', strtotime($punchInTime)) : '—' ?>
                                                    </span>
                                                </div>
                                                <div class="punch-time-row">
                                                    <span class="punch-dot out"></span>
                                                    <strong>Punch Out:</strong>
                                                    <span class="punch-val <?= $hasPunchOut ? 'has-value' : '' ?>">
                                                        <?= $hasPunchOut ? date('h:i:s A', strtotime($punchOutTime)) : '—' ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-6" style="text-align:end;">
                                            <?php if ($onTour):
                                                $tourDispQ = mysqli_query($con, "SELECT punchInTime,punchOutTime,manHours FROM tourAttendance WHERE userId='$userIdEsc' AND date='$today' ORDER BY dateTime DESC LIMIT 1");
                                                $tourDispRow = mysqli_fetch_assoc($tourDispQ);
                                                $tourAttCount = (int) mysqli_num_rows($tourDispQ);
                                                $tHasIn = !empty($tourDispRow['punchInTime']) && $tourDispRow['punchInTime'] !== '00:00:00';
                                                $tHasOut = !empty($tourDispRow['punchOutTime']) && $tourDispRow['punchOutTime'] !== '00:00:00';
                                                $tHasMH = !empty($tourDispRow['manHours']) && $tourDispRow['manHours'] !== '00:00:00';
                                                $tourPunchedIn = $tHasIn && !$tHasOut && !$tHasMH;
                                                $tourCompleted = $tHasIn && ($tHasOut || $tHasMH);
                                                ?>
                                                <?php if ($tourAttCount === 0 || $tourCompleted): ?>
                                                    <a href="captureSelfie.php?punch=in&tour=1" class="btn-punch">Tour Punch
                                                        In</a>
                                                <?php elseif ($tourPunchedIn): ?>
                                                    <a href="captureSelfie.php?punch=out&tour=1" class="btn-punch">Tour Punch
                                                        Out</a>
                                                <?php endif; ?>
                                            <?php elseif ($notPunchedYet): ?>
                                                <a href="<?= $punchInUrl ?>" class="btn-punch">Punch In</a>
                                            <?php elseif ($isPunchedIn): ?>
                                                <?php if ($hasRunning): ?>
                                                    <div class="alert alert-warning mt-2 blink-alert">You have a running task.
                                                        Please pause or finish it before Punch Out.</div>
                                                <?php else: ?>
                                                    <?php if ($_po_unlocked): ?>
                                                        <a href="<?= $punchOutUrl ?>" class="btn-punch">Punch Out</a>
                                                    <?php else: ?>
                                                        <button class="btn-punch" disabled style="opacity:.55;cursor:not-allowed;"
                                                            title="Punch Out unlocks at <?= $_po_unlockDisp ?>">
                                                            <i class="fas fa-lock mr-1" style="font-size:11px;"></i> Locked Till
                                                            <?= $_po_unlockDisp ?>
                                                        </button>
                                                        <div style="font-size:11px;color:#6b7280;margin-top:5px;text-align:center;">
                                                            Unlocks at <strong><?= $_po_unlockDisp ?></strong> — <span
                                                                id="countdownTimer"></span>
                                                        </div>
                                                        <script>
                                                            (function () {
                                                                var unlockTs = <?= $_po_unlockTs ?> * 1000;
                                                                function tick() {
                                                                    var diff = Math.max(0, Math.floor((unlockTs - Date.now()) / 1000));
                                                                    var timerSpan = document.getElementById('countdownTimer');
                                                                    if (timerSpan) {
                                                                        if (diff <= 0) {
                                                                            location.reload();
                                                                        } else {
                                                                            var h = Math.floor(diff / 3600);
                                                                            var m = Math.floor((diff % 3600) / 60);
                                                                            var s = diff % 60;
                                                                            var p = [];
                                                                            if (h > 0) p.push(h + 'h');
                                                                            if (m > 0 || h > 0) p.push(String(m).padStart(2, '0') + 'm');
                                                                            p.push(String(s).padStart(2, '0') + 's');
                                                                            timerSpan.textContent = p.join(' ') + ' remaining';
                                                                        }
                                                                    }
                                                                    if (diff > 0) setTimeout(tick, 1000);
                                                                }
                                                                tick();
                                                            })();
                                                        </script>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            <?php elseif ($hasPunchIn && $hasPunchOut): ?>
                                                <a href="<?= $punchInUrl ?>" class="btn-punch">Punch In</a>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- Today progress -->
                                    <div class="charts-data mt-3">
                                        <div class="border-block mb-2">
                                            <p class="mb-1 tit">Today</p>
                                            <div class="progress-wrap">
                                                <div class="prog-tooltip">Worked: <?= $displayH ?>h
                                                    <?= str_pad($displayM, 2, '0', STR_PAD_LEFT) ?>m of 8h &nbsp;|&nbsp;
                                                    <?= round($perc_today) ?>% complete
                                                </div>
                                                <div class="progress progress-md flex-grow-1">
                                                    <div class="progress-bar bg-success"
                                                        style="width:<?= max(3, round($perc_today, 2)) ?>%"></div>
                                                </div>
                                                <p class="mb-0" style="font-size:11px;white-space:nowrap;">
                                                    <?= $displayH ?>h <?= str_pad($displayM, 2, '0', STR_PAD_LEFT) ?>m /
                                                    <span style="font-size:10px;color:#83878b;">8hr</span>
                                                </p>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="lunch-summary">
                                        <div class="lunch-item">
                                            <div class="lunch-title">LUNCH BREAK</div>
                                            <div class="lunch-value">
                                                <?php if ($lunchStatus === 'not_started'): ?>
                                                    <button onclick="updateLunch('start')"
                                                        class="btn btn-sm btn-outline-success"
                                                        style="font-size:12px;padding:5px 15px;">Start</button>
                                                <?php elseif ($lunchStatus === 'started'): ?>
                                                    <button onclick="updateLunch('end')"
                                                        class="btn btn-sm btn-outline-danger"
                                                        style="font-size:12px;padding:5px 15px;">End</button>
                                                <?php else: ?>
                                                    <div style="color:#22c55e;font-size:11px;font-weight:600;">
                                                        <?= $lunchDuration ?> min
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="lunch-divider"></div>
                                        <div class="lunch-item">
                                            <div class="lunch-title">OVERTIME</div>
                                            <div class="lunch-value"><?= $over_time > 0 ? $over_time . ' hr' : '0 hr' ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Statistics -->
                            <div class="card">
                                <div class="card__body">
                                    <h4 class="card__title" style="margin-bottom:12px;">My Statistics</h4>
                                    <div class="charts-data">
                                        <?php
                                        $stats = [
                                            ['label' => 'This Week', 'color' => 'bg-danger', 'hr' => $week_hr, 'mn' => $week_mn, 'perc' => $perc_week, 'of' => '56'],
                                            ['label' => 'This Month', 'color' => 'bg-warning', 'hr' => $mh, 'mn' => $mm, 'perc' => $perc_month, 'of' => '240'],
                                            ['label' => 'Monthly Overtime', 'color' => 'bg-info', 'hr' => null, 'mn' => null, 'perc' => min(100, max(3, $hours_overtiming_php * 10)), 'of' => null, 'ot' => $hours_overtiming_php],
                                        ];
                                        foreach ($stats as $s): ?>
                                            <div class="mt-1 border-block">
                                                <p class="mb-0 tit"><?= $s['label'] ?></p>
                                                <div class="progress-wrap">
                                                    <?php if ($s['hr'] !== null): ?>
                                                        <div class="prog-tooltip">Worked: <?= $s['hr'] ?>h
                                                            <?= str_pad($s['mn'], 2, '0', STR_PAD_LEFT) ?>m of <?= $s['of'] ?>h
                                                            &nbsp;|&nbsp; <?= round($s['perc']) ?>% complete
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="prog-tooltip">Total overtime this month: <?= $s['ot'] ?> hr
                                                        </div>
                                                    <?php endif; ?>
                                                    <div class="progress progress-md flex-grow-1">
                                                        <div class="progress-bar <?= $s['color'] ?>"
                                                            style="width:<?= max(3, round($s['perc'], 2)) ?>%"></div>
                                                    </div>
                                                    <?php if ($s['hr'] !== null): ?>
                                                        <p class="mb-0"
                                                            style="font-size:11px;white-space:nowrap;margin-left:8px;">
                                                            <?= $s['hr'] ?>h <?= str_pad($s['mn'], 2, '0', STR_PAD_LEFT) ?>m /
                                                            <span style="font-size:10px;color:#83878b;"><?= $s['of'] ?>hr</span>
                                                        </p>
                                                    <?php else: ?>
                                                        <p class="mb-0" style="font-size:11px;margin-left:8px;"><?= $s['ot'] ?>
                                                            hr</p>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>


                    <!-- Main Row: Monthly Attendance + On Leave Today + Absent Today -->
                    <div class="section">
                        <div class="three-col">
                            <!-- Monthly Attendance Card -->
                            <div class="card">
                                <div class="card__header">
                                    <div>
                                        <div class="card__title">Monthly Attendance</div>
                                        <div class="card__subtitle">Personal Summary</div>
                                    </div>
                                    <a href="myAttendance.php" class="view-btn">View Details</a>
                                </div>
                                <div class="card__body">
                                    <div class="stat-mini-grid" style="margin-bottom: auto;">
                                        <div class="stat-mini green">
                                            <div class="stat-mini__val"><?= $presentDays ?></div>
                                            <div class="stat-mini__lbl">Present</div>
                                        </div>
                                        <div class="stat-mini red">
                                            <div class="stat-mini__val"><?= $absentDays ?></div>
                                            <div class="stat-mini__lbl">Absent</div>
                                        </div>
                                        <div class="stat-mini amber">
                                            <div class="stat-mini__val"><?= $lateDays ?></div>
                                            <div class="stat-mini__lbl">Late Days</div>
                                        </div>
                                        <div class="stat-mini purple">
                                            <div class="stat-mini__val"><?= $leaveTaken ?></div>
                                            <div class="stat-mini__lbl">Leaves Taken</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- On Leave Today -->
                            <div class="card">
                                <div class="card__header">
                                    <div>
                                        <div class="card__title">On Leave Today</div>
                                        <div class="card__subtitle"><?= date('d M Y') ?></div>
                                    </div>
                                    <button class="view-btn" data-bs-toggle="modal" data-bs-target="#onLeaveModal">View
                                        all</button>
                                </div>
                                <div class="card__body" style="text-align:center;padding-top:20px;">
                                    <div style="font-size:52px;font-weight:700;color:var(--purple);line-height:1;">
                                        <?= $onLeave ?>
                                    </div>
                                    <div style="font-size:13px;color:var(--ink-3);margin-top:6px;">employees currently
                                        on leave</div>
                                    <div class="att-row">
                                        <div class="att-item present">
                                            <div class="att-item__val"><?= $present ?></div>
                                            <div class="att-item__lbl">Present</div>
                                        </div>
                                        <div class="att-item leave">
                                            <div class="att-item__val"><?= $onLeave ?></div>
                                            <div class="att-item__lbl">On Leave</div>
                                        </div>
                                        <div class="att-item tour">
                                            <div class="att-item__val"><?= $onTourCount ?></div>
                                            <div class="att-item__lbl">On Tour</div>
                                        </div>
                                        <div class="att-item absent">
                                            <div class="att-item__val"><?= $absentCount ?></div>
                                            <div class="att-item__lbl">Absent</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Absent Today -->
                            <div class="card">
                                <div class="card__header">
                                    <div>
                                        <div class="card__title">Absent Today</div>
                                        <div class="card__subtitle">Active staff with no punch-in</div>
                                    </div>
                                    <button class="view-btn" data-bs-toggle="modal" data-bs-target="#absentModal">View
                                        all</button>
                                </div>
                                <div class="card__body" style="padding:12px 16px;">
                                    <?php
                                    // Filtered by Kinwat
                                    $absentPreviewQ = mysqli_query($con, "
                                        SELECT s.fullName, s.type, s.office
                                        FROM signup s
                                        LEFT JOIN attendance      a  ON a.userId  = s.userId  AND a.date     = '$today'
                                        LEFT JOIN tourAttendance  ta ON ta.userId = s.userId  AND ta.date    = '$today'
                                        LEFT JOIN leaves          l  ON l.userId  = s.userId
                                                                     AND l.status  = 'Approved'
                                                                     AND '$today' BETWEEN DATE(l.from_date) AND DATE(l.to_date)
                                        WHERE s.designation != 'admin'
                                          AND s.status = 'Active'
                                          AND LOWER(s.office) = LOWER('$userOfficeEsc')
                                          AND a.userId  IS NULL
                                          AND ta.userId IS NULL
                                          AND l.userId  IS NULL
                                        ORDER BY s.fullName
                                        LIMIT 5
                                    ");
                                    if ($absentPreviewQ && mysqli_num_rows($absentPreviewQ) > 0) {
                                        echo "<ul class='absent-list'>";
                                        while ($ar = mysqli_fetch_assoc($absentPreviewQ)) {
                                            $modeLower = strtolower($ar['type'] ?? 'offline');
                                            $office = htmlspecialchars($ar['office'] ?? '');
                                            echo "<li>
                                                <div>
                                                    <div class='name'>" . htmlspecialchars($ar['fullName']) . "</div>
                                                    <div class='meta'>" . $office . "</div>
                                                </div>
                                                <span class='mode-pill $modeLower'>" . htmlspecialchars($ar['type'] ?? 'Offline') . "</span>
                                            </li>";
                                        }
                                        echo "</ul>";
                                        if ($absentCount > 5) {
                                            echo "<div style='text-align:center;margin-top:10px;font-size:12px;color:var(--ink-3);'>+" . ($absentCount - 5) . " more &mdash; <a href='#' data-bs-toggle='modal' data-bs-target='#absentModal' style='color:var(--accent);font-weight:600;'>View all</a></div>";
                                        }
                                    } else {
                                        echo "<div class='absent-empty'><i class='bi bi-emoji-smile'></i>All active staff have punched in today!</div>";
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <!-- On Leave Modal -->
    <div class="modal fade" id="onLeaveModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-calendar-check me-2"></i>Today's Leaves —
                        <?= date('d M, Y') ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0" style="max-height:60vh;overflow-y:auto;">
                    <table class="leave-table">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Reason</th>
                                <th>Duration</th>
                                <th>Approved By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if ($todayLeaveCnt > 0) {
                                while ($row = mysqli_fetch_assoc($todayLeavesQ)) {
                                    echo "<tr>
                                        <td><strong>{$row['fullName']}</strong></td>
                                        <td style='color:var(--ink-3)'>{$row['reason']}</td>
                                        <td style='color:var(--ink-3)'>{$row['fdate']} – {$row['tdate']}</td>
                                        <td style='color:var(--ink-3)'>{$row['approved_by_name']}</td>
                                    </tr>";
                                }
                            } else {
                                echo "<tr><td colspan='4' style='text-align:center;color:var(--ink-3);padding:24px;'>No employees on leave today</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
                <div class="modal-footer">
                    <span style="font-size:12px;color:var(--ink-3);"><i class="bi bi-people me-1"></i>Total:
                        <strong><?= $todayLeaveCnt ?></strong></span>
                    <button type="button" class="btn btn-outline-primary btn-sm" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Absent Today Modal -->
    <div class="modal fade" id="absentModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header" style="background:var(--orange);">
                    <h5 class="modal-title"><i class="bi bi-person-x me-2"></i>Absent Today — <?= date('d M, Y') ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0" style="max-height:60vh;overflow-y:auto;">
                    <table class="absent-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Employee</th>
                                <th>Mode</th>
                                <th>Office</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $absentIdx = 1;
                            if ($absentListQ && mysqli_num_rows($absentListQ) > 0) {
                                while ($ar = mysqli_fetch_assoc($absentListQ)) {
                                    $modeLower = strtolower($ar['type'] ?? 'offline');
                                    $pillColor = $modeLower === 'online'
                                        ? 'background:var(--green-bg);color:var(--green);'
                                        : 'background:var(--orange-bg);color:var(--orange);';
                                    echo "<tr>
                                        <td style='color:var(--ink-3)'>{$absentIdx}</td>
                                        <td><strong>" . htmlspecialchars($ar['fullName']) . "</strong></td>
                                        <td><span style='display:inline-block;font-size:10px;font-weight:600;padding:2px 8px;border-radius:99px;$pillColor'>" . htmlspecialchars($ar['type'] ?? 'Offline') . "</span></td>
                                        <td style='color:var(--ink-3)'>" . htmlspecialchars($ar['office'] ?? '—') . "</td>
                                    </tr>";
                                    $absentIdx++;
                                }
                            } else {
                                echo "<tr><td colspan='4' style='text-align:center;color:var(--ink-3);padding:24px;'>All active staff have punched in today 🎉</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
                <div class="modal-footer">
                    <span style="font-size:12px;color:var(--ink-3);"><i class="bi bi-person-x me-1"></i>Total Absent:
                        <strong><?= $absentCount ?></strong></span>
                    <button type="button" class="btn btn-outline-primary btn-sm" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="../vendors/js/vendor.bundle.base.js"></script>
    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>
    <script src="../js/settings.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // ── Tour camera: generic factory ──
        function makeCameraModal(modalId, videoId, canvasId) {
            let stream = null;
            return {
                open(latId, lngId) {
                    const modal = document.getElementById(modalId);
                    if (!modal) return;
                    modal.style.display = 'flex';
                    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' } })
                        .then(s => { stream = s; document.getElementById(videoId).srcObject = s; })
                        .catch(err => alert('Camera access denied: ' + err));
                    if (navigator.geolocation && latId)
                        navigator.geolocation.getCurrentPosition(pos => {
                            document.getElementById(latId).value = pos.coords.latitude;
                            document.getElementById(lngId).value = pos.coords.longitude;
                        }, () => { });
                },
                close() {
                    const modal = document.getElementById(modalId);
                    if (modal) modal.style.display = 'none';
                    if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; }
                },
                capture(inputId, formId) {
                    const v = document.getElementById(videoId), c = document.getElementById(canvasId);
                    c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
                    document.getElementById(inputId).value = c.toDataURL('image/png');
                    this.close();
                    document.getElementById(formId).submit();
                }
            };
        }
        const tourIn = makeCameraModal('tourPunchInModal', 'videoTourIn', 'canvasTourIn');
        const tourOut = makeCameraModal('tourPunchOutModal', 'videoTourOut', 'canvasTourOut');
        function openTourPunchInModal() { tourIn.open('punchInLat', 'punchInLong'); }
        function closeTourPunchInModal() { tourIn.close(); }
        function captureTourPunchIn() { tourIn.capture('selfieInput', 'punchInForm'); }
        function openTourPunchOutModal() { tourOut.open('punchOutLat', 'punchOutLong'); }
        function closeTourPunchOutModal() { tourOut.close(); }
        function captureTourPunchOut() { tourOut.capture('punchOutSelfieData', 'tourPunchOutForm'); }

        // ── Lunch ──
        function updateLunch(type) {
            fetch('lunch_action.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'type=' + encodeURIComponent(type) })
                .then(r => r.json())
                .then(data => {
                    if (!data.success) { Swal.fire({ icon: 'error', title: 'Error', text: data.msg || 'Lunch action failed' }); return; }
                    Swal.fire({ icon: 'success', title: 'Success', text: type === 'start' ? 'Lunch started' : 'Lunch ended', timer: 1500, showConfirmButton: false });
                    setTimeout(() => location.reload(), 1600);
                }).catch(() => Swal.fire({ icon: 'error', title: 'Server Error', text: 'Something went wrong.' }));
        }

        if (window.location.search.length > 0) { window.history.replaceState({}, document.title, window.location.pathname); }
    </script>

    <?php
    require_once('../include/sweetAlert.php');
    if (isset($_SESSION['msg'])) {
        $status = $_SESSION['status'];
        $msg = $_SESSION['msg'];
        if ($status != true) {
            toastMsg('error', $msg);
        } else {
            toastMsg('success', $msg);
        }
        unset($_SESSION['status'], $_SESSION['msg']);
    }
    include('../include/passwordChangeAlert.php');
    ?>
</body>

</html>