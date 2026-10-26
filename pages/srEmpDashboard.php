<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
session_start();

// ── Auth & role guard ──
if (empty($_SESSION['userId'])) {
    echo "<script>window.location.href='login.php';</script>";
    exit;
}
include('../include/checkPasswordStatus.php');
if ($_SESSION['userRole'] !== 'senior_intern') {
    echo '<script>alert("You are not authorized to access this page.");window.location.href="logout.php";</script>';
    die();
}

include('../include/db_config.php');
require_once('../include/late_mark_helper.php');
require_once('availabilityEngine.php');

// ── Modal flag ──
$showModal = !isset($_SESSION['modal_shown']);
if ($showModal)
    $_SESSION['modal_shown'] = true;

// ── Date helpers ──
$today = date('Y-m-d');
$todayMD = date('m-d');
$firstDate = date('Y-m-01');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$userId = $_SESSION['userId'];

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

$wfhQuery = mysqli_query($con, "SELECT $dateSelect FROM wfhRequests WHERE userid = '$userId' AND status = 'Approved'");
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
    mysqli_query($con, "UPDATE signup SET type = 'Online' WHERE userId = '$userId'");
} else {
    mysqli_query($con, "UPDATE signup SET type = mode WHERE userId = '$userId'");
}

// ── Key Pending POST handler (unchanged) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_key_pending'])) {
    $projects = $_POST['project'];
    $tasks = $_POST['task'];
    $reasons = $_POST['reason'];
    $deadlines = $_POST['deadline'];
    $count = count($projects);
    for ($i = 0; $i < $count; $i++) {
        if (empty($projects[$i]) || empty($tasks[$i]) || empty($deadlines[$i]))
            continue;
        $pId = $projects[$i];
        $tD = mysqli_real_escape_string($con, $tasks[$i]);
        $rs = mysqli_real_escape_string($con, $reasons[$i] ?? '');
        $dl = mysqli_real_escape_string($con, $deadlines[$i]);
        mysqli_query($con, "INSERT INTO key_pendings (user_id,project_id,task_description,reason,deadline,punch_out_date) VALUES ('$userId','$pId','$tD','$rs','$dl','$today')");
    }
    $_SESSION['alert_icon'] = 'success';
    $_SESSION['alert_text'] = 'Key pending tasks saved successfully!';
    $um = mysqli_fetch_assoc(mysqli_query($con, "SELECT type FROM signup WHERE userId='$userId'"))['type'] ?? 'Offline';
    $pu = $um === 'Online' ? 'captureSelfie.php?punch=out' : 'qrCodeScanner_copy.php?punch=Out';
    $_SESSION['key_pending_submitted'] = true;
    header("Location: $pu");
    exit();
}

// ── Birthdays ──
$todayBirthdays = [];
$bdQ = mysqli_query($con, "SELECT fullName,mobileNo FROM signup WHERE regstatus='Approved' AND DATE_FORMAT(dob,'%m-%d')='$todayMD'");
while ($row = mysqli_fetch_assoc($bdQ))
    $todayBirthdays[] = $row;

// ── User record ──
$userData = mysqli_fetch_assoc(mysqli_query($con, "SELECT * FROM signup WHERE userId='$userId'"));
$expectedPunchOut = $userData['expectedPunchOut'] ?? '18:30:00';
$expectedPunchIn2 = $userData['expectedPunchIn'] ?? '09:30:00';
$expectedPunchInBase = $userData['expectedPunchIn'] ?? '09:30:00';
$expInDisp = !empty($expectedPunchInBase) ? date('h:i A', strtotime('2000-01-01 ' . $expectedPunchInBase)) : '09:30 AM';
$expOutDisp = !empty($expectedPunchOut) ? date('h:i A', strtotime('2000-01-01 ' . $expectedPunchOut)) : '06:30 PM';
$isOfflineIntern = strtolower($userData['type'] ?? '') === 'offline';
$userStartDate = $userData['start_date'] ?? '';
$userMode = $userData['type'] ?? 'Offline';

// Late punch-in override
$lpiQ = mysqli_query($con, "SELECT delayed_time FROM late_punch_in WHERE userId='$userId' AND date='$today'");
if (mysqli_num_rows($lpiQ) > 0)
    $expectedPunchIn2 = mysqli_fetch_assoc($lpiQ)['delayed_time'];

// ── Tour check ──
$tourQ = mysqli_query($con, "SELECT 1 FROM tour WHERE userId='$userId' AND '$today' BETWEEN DATE(fromDate) AND DATE(toDate) AND status IN ('Approved','Pending') LIMIT 1");
$onTour = mysqli_num_rows($tourQ) > 0;
$attendanceTableName = $onTour ? 'tourAttendance' : 'attendance';

// ── Today's attendance row ──
$attTodayQ = mysqli_query($con, "SELECT * FROM $attendanceTableName WHERE date='$today' AND userId='$userId' ORDER BY id DESC LIMIT 1");
$attRow = mysqli_fetch_assoc($attTodayQ);
$attCountQ = mysqli_query($con, "SELECT COUNT(*) AS cnt FROM $attendanceTableName WHERE userId='$userId' AND date='$today'");
$attendanceCount = (int) (mysqli_fetch_assoc($attCountQ)['cnt'] ?? 0);

// ── Clean punch state (mirrors junior logic) ──
$dispPunchIn = $attRow['punchInTime'] ?? null;
$dispPunchOut = $attRow['punchOutTime'] ?? null;
$dispLateMark = strtolower($attRow['lateMark'] ?? 'no');
$hasPunchIn = !empty($dispPunchIn) && $dispPunchIn !== '00:00:00';
$hasPunchOut = !empty($dispPunchOut) && $dispPunchOut !== '00:00:00';
$notPunchedYet = !$hasPunchIn;
$isPunchedIn = $hasPunchIn && !$hasPunchOut;

$dispIsLate = ($dispLateMark === 'yes');
$dispIsAbsentLate = false;
if ($dispIsLate && $hasPunchIn) {
    $diffMins = (strtotime("1970-01-01 $dispPunchIn") - strtotime("1970-01-01 $expectedPunchInBase")) / 60;
    if ($diffMins > 30)
        $dispIsAbsentLate = true;
}

// ── Hours worked ──
$hours_difference = 0;
if ($hasPunchIn) {
    $in = strtotime($dispPunchIn);
    $out = $hasPunchOut ? strtotime($dispPunchOut) : time();
    if ($out > $in)
        $hours_difference = ($out - $in) / 3600;
}
$over_time = $hours_difference > 8 ? round($hours_difference - 8, 1) : 0;

// ── Today progress display ──
$totalMinutesToday = (int) round($hours_difference * 60);
$displayH = intdiv($totalMinutesToday, 60);
$displayM = $totalMinutesToday % 60;
$perc_today = min(100, max(0, ($hours_difference / 8) * 100));

// ── Punch URLs ──
$punchInUrl = $userMode === 'Online' ? 'captureSelfie.php?punch=in' : 'qrCodeScanner_copy.php?punch=In';
$punchOutUrl = $userMode === 'Online' ? 'captureSelfie.php?punch=out' : 'qrCodeScanner_copy.php?punch=Out';

// ── Task Diary check (punch-out gate) ──
include_once('../include/task_helper.php');
$_diaryTableExists = mysqli_num_rows(mysqli_query($con, "SHOW TABLES LIKE 'task_diary'")) > 0;
$_diaryTotalToday = 0;
$_diaryPendingToday = 0;
$_diaryHasPlanned = false;
if ($_diaryTableExists) {
    syncCarriedTasks($con, $userId, $today);
    $_dQ1 = mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) AS cnt FROM task_diary WHERE userId='$userId' AND date='$today' AND (is_deleted = 0 OR is_deleted IS NULL)"));
    $_diaryTotalToday = (int) ($_dQ1['cnt'] ?? 0);
    $_dQ2 = mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) AS cnt FROM task_diary WHERE userId='$userId' AND date='$today' AND status='Pending' AND (is_deleted = 0 OR is_deleted IS NULL)"));
    $_diaryPendingToday = (int) ($_dQ2['cnt'] ?? 0);
    $_diaryHasPlanned = $_diaryTotalToday > 0;
}
$_diaryAllUpdated = ($_diaryTotalToday > 0 && $_diaryPendingToday === 0);

// ── Lunch status ──
$lunchStatus = 'not_started';
$lunchStart = '';
$lunchEnd = '';
$lunchDuration = 0;
$lunchQ = mysqli_query($con, "SELECT startTime,endTime,status,totalDuration FROM TaskEntryNew WHERE userId='$userId' AND task='Lunch Break' AND DATE(startTime)=CURDATE() ORDER BY taskId DESC LIMIT 1");
if ($lunchQ && mysqli_num_rows($lunchQ) > 0) {
    $lunch = mysqli_fetch_assoc($lunchQ);
    $lunchStart = $lunch['startTime'];
    $lunchEnd = $lunch['endTime'];
    if ($lunch['status'] === 'Running')
        $lunchStatus = 'started';
    elseif ($lunch['status'] === 'Finished') {
        $lunchStatus = 'completed';
        $lunchDuration = $lunch['totalDuration'] > 0
            ? round($lunch['totalDuration'] / 60)
            : round((strtotime($lunchEnd) - strtotime($lunchStart)) / 60);
    }
}

// ── Running task check ──
$hasRunning = mysqli_num_rows(mysqli_query($con, "SELECT taskId FROM TaskEntryNew WHERE userId='$userId' AND status='Running' LIMIT 1")) > 0;

// ── Punch-out time-lock check ──
$_userIdEsc = mysqli_real_escape_string($con, $userId);

// Get expected punch-out from DB
$_poSignup = mysqli_fetch_assoc(mysqli_query(
    $con,
    "SELECT expectedPunchOut FROM signup WHERE userId='$_userIdEsc' LIMIT 1"
));

$_expectedOut = $_poSignup['expectedPunchOut'] ?? '18:30:00';

$_expectedOutTs = strtotime(date('Y-m-d') . ' ' . $_expectedOut);

// Check for HR-granted early go today
$_egRow = mysqli_fetch_assoc(mysqli_query(
    $con,
    "SELECT early_go_time FROM early_go
     WHERE userId='$_userIdEsc'
     AND date='$today'
     AND hr_granted=1
     ORDER BY id DESC
     LIMIT 1"
));

$_earlyGoTs = null;
$_hasHrEarlyGo = false;

if ($_egRow && !empty($_egRow['early_go_time'])) {

    $_earlyGoTs = strtotime(
        date('Y-m-d') . ' ' . $_egRow['early_go_time']
    );

    $_hasHrEarlyGo = true;
}

// Effective unlock time (unlocks 15 minutes before expected punch out / early go time)
$_targetOutTs = (
    $_hasHrEarlyGo &&
    $_earlyGoTs < $_expectedOutTs
)
    ? $_earlyGoTs
    : $_expectedOutTs;
$_unlockTs = $_targetOutTs - (15 * 60);
$_poBtnUnlocked = (time() >= $_unlockTs);
$_unlockDisp = date('h:i A', $_unlockTs);

// ── Week stats ──
$weekStart = date('Y-m-d', strtotime('monday this week'));
$weekQ = mysqli_query($con, "SELECT punchInTime,punchOutTime,date FROM attendance WHERE userId='$userId' AND date BETWEEN '$weekStart' AND '$today'");
$weekMin = 0;
while ($w = mysqli_fetch_assoc($weekQ)) {
    if (empty($w['punchInTime']) || $w['punchInTime'] === '00:00:00')
        continue;
    $in = strtotime($w['punchInTime']);
    if ($w['date'] === $today)
        $out = (!empty($w['punchOutTime']) && $w['punchOutTime'] !== '00:00:00') ? strtotime($w['punchOutTime']) : time();
    elseif (!empty($w['punchOutTime']) && $w['punchOutTime'] !== '00:00:00')
        $out = strtotime($w['punchOutTime']);
    else
        continue;
    if ($out > $in)
        $weekMin += (int) (($out - $in) / 60);
}
$week_hr_php = intdiv($weekMin, 60);
$week_mn_php = $weekMin % 60;
$perc_week = min(100, max(0, ($weekMin / (56 * 60)) * 100));

// ── Month stats ──
$roDays = (int) (mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) AS c FROM attendance WHERE userId='$userId' AND date BETWEEN '$firstDate' AND '$today' AND status='Rostering Off'"))['c'] ?? 0);
$monthQ = mysqli_query($con, "SELECT punchInTime,punchOutTime,date FROM attendance WHERE userId='$userId' AND date BETWEEN '$firstDate' AND '$today'");
$monthMin = 0;
while ($mR = mysqli_fetch_assoc($monthQ)) {
    if (empty($mR['punchInTime']) || $mR['punchInTime'] === '00:00:00')
        continue;
    $in = strtotime($mR['punchInTime']);
    if ($mR['date'] === $today)
        $out = (!empty($mR['punchOutTime']) && $mR['punchOutTime'] !== '00:00:00') ? strtotime($mR['punchOutTime']) : time();
    elseif (!empty($mR['punchOutTime']) && $mR['punchOutTime'] !== '00:00:00')
        $out = strtotime($mR['punchOutTime']);
    else
        continue;
    if ($out > $in)
        $monthMin += (int) (($out - $in) / 60);
}
$finalMin = max(0, $monthMin - ($roDays * 8 * 60));
$month_hr_php = intdiv($finalMin, 60);
$month_mn_php = $finalMin % 60;
$perc_month = min(100, max(0, ($finalMin / (240 * 60)) * 100));

// ── Monthly overtime ──
$otQ = mysqli_query($con, "SELECT punchInTime,punchOutTime FROM attendance WHERE userId='$userId' AND date BETWEEN '$firstDate' AND '$yesterday'");
$hours_overtiming_php = 0;
while ($ot = mysqli_fetch_assoc($otQ)) {
    if (!empty($ot['punchInTime']) && !empty($ot['punchOutTime']) && $ot['punchOutTime'] !== '00:00:00') {
        $diff = (strtotime($ot['punchOutTime']) - strtotime($ot['punchInTime'])) / 3600;
        if ($diff > 8)
            $hours_overtiming_php += round($diff - 8, 1);
    }
}
if ($over_time > 0)
    $hours_overtiming_php += $over_time;

// ── Attendance summary (mirrors myAttendance.php) ──
$holidaysMap = [];
$hRes = mysqli_query($con, "SELECT from_date,to_date,title FROM holidays WHERE from_date<='$today' AND to_date>='$firstDate'");
while ($hRow = mysqli_fetch_assoc($hRes))
    for ($d = strtotime($hRow['from_date']); $d <= strtotime($hRow['to_date']); $d += 86400) {
        $hd = date('Y-m-d', $d);
        if ($hd >= $firstDate && $hd <= $today)
            $holidaysMap[$hd] = $hRow['title'];
    }

$leaveDatesMap = [];
$lRes = mysqli_query($con, "SELECT from_date,to_date,leave_type FROM leaves WHERE userId='$userId' AND status='Approved' AND from_date<='$today' AND to_date>='$firstDate'");
while ($lRow = mysqli_fetch_assoc($lRes))
    for ($d = strtotime($lRow['from_date']); $d <= strtotime($lRow['to_date']); $d += 86400) {
        $ld = date('Y-m-d', $d);
        if ($ld >= $firstDate && $ld <= $today)
            $leaveDatesMap[$ld] = $lRow['leave_type'];
    }

$validRODatesThisMonth = [];
if ($isOfflineIntern) {
    $roQ2 = mysqli_query($con, "SELECT from_date,to_date FROM leaves WHERE userId='$userId' AND leave_type='RO' AND status='Approved' ORDER BY from_date ASC");
    $allRO = [];
    while ($roRow = mysqli_fetch_assoc($roQ2))
        for ($d = strtotime($roRow['from_date']); $d <= strtotime($roRow['to_date']); $d += 86400) {
            $rd = date('Y-m-d', $d);
            if ($rd >= $firstDate && $rd <= $today)
                $allRO[] = $rd;
        }
    sort($allRO);
    $validRODatesThisMonth = array_slice($allRO, 0, 2);
}

$regMap = [];
$regRes = mysqli_query($con, "SELECT date, punch_in FROM attendance_regularization WHERE user_id='$userId' AND status='Approved' AND date BETWEEN '$firstDate' AND '$today'");
while ($rRow = mysqli_fetch_assoc($regRes)) {
    $regMap[$rRow['date']] = $rRow['punch_in'];
}

$lateIntimationsMap = [];
$liRes = mysqli_query($con, "SELECT date FROM late_punch_in WHERE userId='$userId' AND date BETWEEN '$firstDate' AND '$today'");
while ($liRow = mysqli_fetch_assoc($liRes)) {
    $lateIntimationsMap[$liRow['date']] = true;
}

$attMap = [];
$aRes = mysqli_query($con, "SELECT date,punchInTime,punchOutTime,status,lateMark FROM attendance WHERE userId='$userId' AND date BETWEEN '$firstDate' AND '$today'");
while ($aRow = mysqli_fetch_assoc($aRes))
    $attMap[$aRow['date']] = $aRow;

$tourAttMap = [];
$tRes = mysqli_query($con, "SELECT date FROM tourAttendance WHERE userId='$userId' AND punchInTime!='00:00:00' AND date BETWEEN '$firstDate' AND '$today'");
while ($tRow = mysqli_fetch_assoc($tRes))
    $tourAttMap[$tRow['date']] = $tRow;

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
        $dayExpIn = ($day < '2026-08-01') ? '10:00:00' : ($expectedPunchInBase ?? '09:30:00');
        
        $lateEval = evaluateLateMarkPolicy($att['punchInTime'], $dayExpIn, $day);
        $isLate = $lateEval['isLate'];
        $isAbsentLate = $lateEval['isHalfDay'];

        if ($isLate || $isAbsentLate) {
            if (isset($lateIntimationsMap[$day])) {
                $isAbsentLate = false;
                $isLate = true;
            }
        }

        if (isset($regMap[$day])) {
            $regPunchIn = $regMap[$day];
            $lateEvalReg = evaluateLateMarkPolicy($regPunchIn, $dayExpIn, $day);
            $isLate = $lateEvalReg['isLate'];
            $isAbsentLate = $lateEvalReg['isHalfDay'];
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

// ── Other data ──
$pendingTasksCount = (int) (mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) AS c FROM taskEntry WHERE (allocatedBy='$userId' OR userId='$userId') AND status!='end'"))['c'] ?? 0);
$leaveCount = (int) (mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(DISTINCT userId) AS c FROM leaves WHERE status='Approved' AND CURDATE() BETWEEN from_date AND to_date"))['c'] ?? 0);

$myLeavesQ = mysqli_query($con, "SELECT leave_name,leave_type,from_date,to_date,status FROM leaves WHERE userId='$userId' AND status='Pending' AND DATE(from_date)>='$today' ORDER BY from_date ASC LIMIT 5");

// ── Assigned tasks from Weekly Plan ──
$assignedTasksQ = mysqli_query($con, "
    SELECT a.id, a.projectName, a.clientName, a.allocStartTime, a.allocEndTime, a.dailyHours,
           COALESCE(MAX(su_rr.fullName), MAX(su.fullName)) AS leadName,
           COALESCE(MAX(su_rr.mobileNo), MAX(su.mobileNo)) AS leadMobile
    FROM internAllocations a
    LEFT JOIN signup su ON su.userId = a.allocatedBy
    LEFT JOIN resourceRequests rr ON rr.internId = a.internId AND rr.projectName = a.projectName AND a.startDate <= rr.endDate AND a.endDate >= rr.startDate
    LEFT JOIN signup su_rr ON su_rr.userId = rr.requestedBy
    WHERE a.internId = (SELECT internId FROM signup WHERE userId='$userIdEsc')
      AND '$today' BETWEEN a.startDate AND a.endDate
    GROUP BY a.id, a.projectName, a.clientName, a.allocStartTime, a.allocEndTime, a.dailyHours
    ORDER BY a.allocStartTime ASC
");

// ── Upcoming meetings ──
$upcomingMeetingsQ = mysqli_query($con, "
    SELECT m.*, s.fullName AS creator_name
    FROM meetings m
    LEFT JOIN signup s ON s.userId COLLATE utf8mb4_unicode_ci = m.creator_id COLLATE utf8mb4_unicode_ci
    WHERE (FIND_IN_SET('$userId', m.participants COLLATE utf8mb4_unicode_ci) > 0 OR m.creator_id = '$userId')
      AND m.meeting_date >= CURDATE()
      AND (m.status IS NULL OR (m.status != 'completed' AND m.status != 'cancelled'))
    ORDER BY m.meeting_date ASC, m.meeting_time ASC
    LIMIT 4
");

// Project queries for progress widget
$projQ = mysqli_query($con, "
    SELECT DISTINCT pm.projectId, pm.projectName, pm.deadline 
    FROM projectMaster pm 
    LEFT JOIN allocatedProject ap ON pm.projectId = ap.projectId AND ap.userId = '$userId'
    LEFT JOIN internAllocations ia ON pm.projectName = ia.projectName AND ia.internId = (SELECT internId FROM signup WHERE userId = '$userId')
    LEFT JOIN projectProgress pp ON pm.projectId = pp.projectId AND pp.userId = '$userId'
    WHERE (ap.userId IS NOT NULL OR ia.internId IS NOT NULL OR pp.userId IS NOT NULL)
    AND (pm.status = 'Active' OR pm.status = 'active' OR pm.status = 1)
    ORDER BY pm.projectName
");
$projProgressQ = mysqli_query($con, "SELECT pm.projectId,pm.projectName,pp.progress,pp.remark,pm.deadline FROM projectMaster pm JOIN projectProgress pp ON pm.projectId=pp.projectId WHERE pp.userId='$userId' AND pp.modificationDate=(SELECT MAX(modificationDate) FROM projectProgress WHERE projectId=pm.projectId AND userId='$userId') ORDER BY pp.modificationDate DESC LIMIT 5");

// Grace time
$graceTime = date('H:i:s', strtotime($expectedPunchIn2 . ' +60 minutes'));
$nowTime = date('H:i:s');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <?php include('../partials/_links.php'); ?>
    <style>
        :root {
            --brand: #1977e1;
            --brand-dark: #1254b0;
            --brand-light: #f0f7ff;
            --brand-border: #bfdbfe;
            --green: #059669;
            --green-bg: #ecfdf5;
            --amber: #d97706;
            --amber-bg: #fffbeb;
            --orange: #ea580c;
            --orange-bg: #fff7ed;
            --red: #dc2626;
            --red-bg: #fef2f2;
            --purple: #7c3aed;
            --purple-bg: #f5f3ff;
            --ink: #111827;
            --ink-2: #374151;
            --ink-3: #6b7280;
            --ink-4: #9ca3af;
            --border: #e5e7eb;
            --surface-2: #f9fafb;
            --radius: 10px;
            --radius-lg: 14px;
            --shadow-card: 0 4px 24px rgba(25, 119, 225, .08), 0 1px 4px rgba(0, 0, 0, .05);
        }

        body {
            background: var(--ams-page-bg, #f0f2f7);
        }

        .card {
            border-radius: var(--radius-lg) !important;
            box-shadow: var(--shadow-card) !important;
            border: 1px solid var(--border, #e8edf5) !important;
            background: var(--surface, #ffffff);
        }

        .card-body {
            padding: 20px !important
        }

        .heading {
            color: var(--brand) !important;
            font-weight: 700 !important
        }

        .card-accent {
            height: 3px;
            background: linear-gradient(135deg, #1977e1, #1254b0);
            border-radius: 99px;
            margin-bottom: 14px
        }

        .two-col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
            margin-bottom: 20px
        }

        .two-col>div {
            min-width: 0
        }

        .three-col {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 18px;
            margin-bottom: 20px
        }

        .two-col-wide {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
            margin-bottom: 20px
        }

        @media(max-width:992px) {
            .three-col {
                grid-template-columns: 1fr 1fr
            }
        }

        @media(max-width:768px) {

            .two-col,
            .two-col-wide {
                grid-template-columns: 1fr
            }
        }

        @media(max-width:576px) {
            .three-col {
                grid-template-columns: 1fr
            }
        }

        /* Buttons */
        .btn-inverse-success.btn-rounded,
        a.btn-inverse-success.btn-rounded {
            background: linear-gradient(135deg, var(--brand), var(--brand-dark)) !important;
            color: #fff !important;
            border: none !important;
            border-radius: 10px !important;
            padding: 10px 22px !important;
            font-size: 13px !important;
            font-weight: 700 !important;
            box-shadow: 0 3px 12px rgba(25, 119, 225, .28) !important;
            text-decoration: none !important;
            display: inline-block !important;
            transition: background .18s, box-shadow .18s !important
        }

        .btn-inverse-success.btn-rounded:hover,
        a.btn-inverse-success.btn-rounded:hover {
            background: linear-gradient(135deg, #1561c0, #0f3f8a) !important;
            box-shadow: 0 4px 18px rgba(25, 119, 225, .38) !important
        }

        .btn-inverse-warning.btn-rounded {
            border-radius: 10px !important;
            font-size: 13px !important;
            font-weight: 600 !important;
            padding: 10px 18px !important
        }

        /* Progress */
        .progress.progress-md {
            height: 6px;
            border-radius: 99px
        }

        .charts-data .progress {
            background: #eef1f7;
            border-radius: 99px
        }

        .progress-bar {
            border-radius: 99px
        }

        .border-block {
            border: 1px solid var(--border, #e8edf5) !important;
            padding: 10px 14px !important;
            border-radius: 10px !important;
            background: var(--surface-2, #fafbfd) !important;
            margin-top: 8px !important;
        }

        .tit {
            font-size: 11px;
            margin-bottom: .4rem;
            color: #64748b;
            font-weight: 600;
            letter-spacing: .3px;
            text-transform: uppercase
        }

        .progress-wrap {
            position: relative;
            display: flex;
            align-items: center;
            gap: 8px
        }

        .progress-wrap .progress {
            flex: 1;
            cursor: pointer
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
            box-shadow: 0 2px 10px rgba(0, 0, 0, .18)
        }

        .prog-tooltip::after {
            content: '';
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translateX(-50%);
            border: 5px solid transparent;
            border-top-color: #1e293b
        }

        .progress-wrap:hover .prog-tooltip {
            display: block
        }

        /* Lunch */
        .lunch-summary {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 14px;
            padding-top: 12px;
            border-top: 1px solid #eef1f7
        }

        .lunch-item {
            flex: 1;
            text-align: center
        }

        .lunch-title {
            font-size: 11px;
            font-weight: 700;
            color: #475569;
            letter-spacing: .5px;
            text-transform: uppercase
        }

        .lunch-value {
            font-size: 11px;
            color: #64748b;
            margin-top: 5px
        }

        .lunch-divider {
            width: 1px;
            height: 28px;
            background: #e5e5e5
        }

        /* Alerts */
        .alert-warning {
            color: #92400e;
            background: #fffbeb;
            border: 1.5px solid #fcd34d;
            border-radius: 10px;
            font-size: 13px;
            padding: 10px 14px;
            margin-top: 8px !important
        }

        .blink-alert {
            animation: blink 1s infinite;
            font-weight: 600
        }

        @keyframes blink {

            0%,
            100% {
                opacity: 1
            }

            50% {
                opacity: 0
            }
        }

        /* Stats mini */
        .stat-mini-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px
        }

        .stat-mini {
            border-radius: 10px;
            padding: 12px 14px;
            display: flex;
            flex-direction: column;
            gap: 4px
        }

        .stat-mini.green {
            background: var(--green-bg);
            border: 1px solid #a7f3d0
        }

        .stat-mini.red {
            background: var(--red-bg);
            border: 1px solid #fecaca
        }

        .stat-mini.amber {
            background: var(--amber-bg);
            border: 1px solid #fde68a
        }

        .stat-mini.purple {
            background: var(--purple-bg);
            border: 1px solid #ddd6fe
        }

        .stat-mini__val {
            font-size: 24px;
            font-weight: 700;
            line-height: 1
        }

        .stat-mini__lbl {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .4px
        }

        .stat-mini.green .stat-mini__val,
        .stat-mini.green .stat-mini__lbl {
            color: var(--green)
        }

        .stat-mini.red .stat-mini__val,
        .stat-mini.red .stat-mini__lbl {
            color: var(--red)
        }

        .stat-mini.amber .stat-mini__val,
        .stat-mini.amber .stat-mini__lbl {
            color: var(--amber)
        }

        .stat-mini.purple .stat-mini__val,
        .stat-mini.purple .stat-mini__lbl {
            color: var(--purple)
        }

        /* Leaves & Tasks */
        .leave-badge {
            display: inline-block;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 99px
        }

        .leave-badge.approved {
            background: var(--green-bg);
            color: var(--green);
            border: 1px solid #a7f3d0
        }

        .leave-badge.pending {
            background: var(--amber-bg);
            color: var(--amber);
            border: 1px solid #fde68a
        }

        .leave-badge.rejected {
            background: var(--red-bg);
            color: var(--red);
            border: 1px solid #fecaca
        }

        .leave-badge.cancelled {
            background: var(--surface-2);
            color: var(--ink-3);
            border: 1px solid var(--border)
        }

        .leave-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 9px 0;
            border-bottom: 1px solid #f3f4f6;
            gap: 10px
        }

        .leave-row:last-child {
            border-bottom: none
        }

        .leave-row__type {
            font-size: 12px;
            font-weight: 600;
            color: var(--ink-2)
        }

        .leave-row__date {
            font-size: 11px;
            color: var(--ink-3);
            margin-top: 2px
        }

        .quick-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px
        }

        .quick-link {
            display: flex;
            align-items: center;
            gap: 9px;
            padding: 10px 12px;
            border-radius: 9px;
            border: 1px solid var(--border);
            background: var(--surface-2);
            text-decoration: none;
            color: var(--ink-2);
            font-size: 12px;
            font-weight: 600;
            transition: background .13s, border-color .13s
        }

        .quick-link:hover {
            background: var(--brand-light);
            border-color: var(--brand-border);
            color: var(--brand)
        }

        .quick-link__icon {
            width: 30px;
            height: 30px;
            border-radius: 7px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            flex-shrink: 0
        }

        .task-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 9px 0;
            border-bottom: 1px solid #f3f4f6;
            gap: 10px
        }

        .task-row:last-child {
            border-bottom: none
        }

        .task-row__name {
            font-size: 12px;
            font-weight: 600;
            color: var(--ink-2);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 160px
        }

        .task-row__time {
            font-size: 11px;
            color: var(--ink-3);
            margin-top: 2px
        }

        .task-status {
            display: inline-flex;
            align-items: center;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 99px
        }

        .task-status.running {
            background: #eff6ff;
            color: var(--brand);
            border: 1px solid #bfdbfe
        }

        .task-status.finished {
            background: var(--green-bg);
            color: var(--green);
            border: 1px solid #a7f3d0
        }

        .task-status.paused {
            background: var(--amber-bg);
            color: var(--amber);
            border: 1px solid #fde68a
        }

        /* Table */
        .table th {
            border: none;
            font-size: 12px;
            background: linear-gradient(135deg, var(--brand), var(--brand-dark));
            color: #f8f9fa;
            font-weight: 700;
            padding: 8px 12px
        }

        .table tbody tr:hover {
            background-color: #eef4ff
        }

        .table td {
            padding: 8px 12px;
            vertical-align: middle;
            font-size: 13px
        }

        /* Misc */
        .beta-tag {
            font-size: 10px;
            background: var(--brand);
            color: #fff;
            padding: 2px 7px;
            border-radius: 5px;
            vertical-align: middle;
            margin-left: 6px;
            font-weight: 600;
            letter-spacing: .5px;
            display: inline-block
        }

        .birthday-marquee-full {
            width: 100%;
            padding: 10px 0
        }

        .birthday-marquee-full .bname {
            margin: 0 40px;
            font-weight: 600;
            color: #d35400;
            font-size: 14px
        }

        .wish-btn {
            display: inline-block;
            padding: 4px 12px;
            background: var(--brand);
            color: #fff;
            text-decoration: none;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 8px
        }

        .wish-btn:hover {
            background: var(--brand-dark)
        }

        .empty-state {
            text-align: center;
            padding: 20px;
            color: var(--ink-3);
            font-size: 12px
        }

        .empty-state i {
            font-size: 1.5rem;
            opacity: .35;
            display: block;
            margin-bottom: 6px
        }

        /* Punch time block */
        .pt-label {
            font-size: 11px;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .3px;
            display: block;
            margin-bottom: 2px
        }

        .pt-value {
            font-size: 13px;
            font-weight: 700;
            color: var(--ink);
            margin-bottom: 8px;
            display: block
        }

        .pt-value.dash {
            color: var(--ink-4);
            font-weight: 400
        }

        .pt-late-tag {
            display: inline-block;
            font-size: 9px;
            font-weight: 700;
            background: #fff7ed;
            color: #c2410c;
            border: 1px solid #fed7aa;
            border-radius: 4px;
            padding: 1px 5px;
            margin-left: 4px
        }

        /* Camera modal */
        .modalPunch {
            display: none;
            position: fixed;
            z-index: 999;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(15, 23, 42, .65);
            justify-content: center;
            align-items: center
        }

        .modal-content-punch {
            background: #fff;
            padding: 24px;
            border-radius: 16px;
            text-align: center;
            width: 360px;
            box-shadow: 0 8px 32px rgba(25, 119, 225, .15)
        }

        .modal-content-punch h4 {
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 14px
        }

        .modal-content-punch video {
            width: 300px;
            height: 300px;
            border: 2px solid #e2e8f0;
            border-radius: 12px
        }

        /* Info modal */
        .modal-noti {
            display: none;
            position: fixed;
            z-index: 100;
            left: 50%;
            top: 55%;
            transform: translate(-50%, -50%);
            max-width: 400px;
            background: #fff;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 8px 32px rgba(25, 119, 225, .15);
            text-align: center
        }

        /* Leave modal */
        #onLeaveModal .modal-header {
            background: linear-gradient(135deg, var(--brand), var(--brand-dark)) !important
        }

        #onLeaveModal thead th {
            background: linear-gradient(135deg, var(--brand), var(--brand-dark)) !important;
            color: #fff !important;
            position: sticky;
            top: 0;
            z-index: 5
        }

        #onLeaveModal .table-responsive {
            max-height: 70vh;
            overflow-y: auto
        }

        .btn-outline-primary {
            border-color: var(--brand) !important;
            color: var(--brand) !important;
            border-radius: 8px !important;
            font-weight: 600 !important;
            font-size: 13px !important
        }

        .btn-outline-primary:hover {
            background: var(--brand) !important;
            color: #fff !important
        }

        .btn-outline-success {
            border-color: var(--brand) !important;
            color: var(--brand) !important;
            border-radius: 8px !important;
            font-weight: 600 !important
        }

        .btn-outline-success:hover {
            background: var(--brand) !important;
            color: #fff !important
        }

        /* Task summary mobile */
        .table-responsive {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border-radius: 10px
        }

        #taskSummaryTable {
            width: 100% !important;
            min-width: 700px;
            border-collapse: collapse
        }

        #taskSummaryTable thead th .sort-icon {
            margin-left: 6px;
            font-size: 1rem;
            color: #fff
        }

        #taskSummaryTable.dataTable>thead .sorting:before,
        #taskSummaryTable.dataTable>thead .sorting:after,
        #taskSummaryTable.dataTable>thead .sorting_asc:before,
        #taskSummaryTable.dataTable>thead .sorting_asc:after,
        #taskSummaryTable.dataTable>thead .sorting_desc:before,
        #taskSummaryTable.dataTable>thead .sorting_desc:after {
            display: none !important
        }

        @media(max-width:768px) {
            #taskSummaryTable {
                min-width: 600px
            }

            #taskSummaryTable th,
            #taskSummaryTable td {
                font-size: 11px !important;
                padding: 8px 10px !important
            }

            .dataTables_wrapper .dataTables_paginate {
                overflow-x: auto;
                white-space: nowrap
            }
        }

        /* Key pending popup */
        #keyPendingPopup .modal-container {
            background: #fff;
            padding: 24px;
            border-radius: 16px;
            width: 520px;
            min-width: 360px;
            max-width: 760px;
            margin: 16px auto;
            text-align: left;
            max-height: 80vh;
            overflow-y: auto;
            position: relative;
            box-shadow: 0 8px 32px rgba(25, 119, 225, .15)
        }

        @media(max-width:576px) {
            #keyPendingPopup .modal-container {
                width: 90%;
                min-width: unset;
                max-width: 95%;
                max-height: 85vh;
                padding: 14px
            }
        }

        .meeting-btn {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 14px;
            border-radius: 10px;
            text-decoration: none;
            font-size: 12px;
            font-weight: 700;
            color: #fff !important;
            background: linear-gradient(135deg, var(--brand), var(--brand-dark));
            border: none;
            transition: all .2s ease;
            box-shadow: 0 4px 14px rgba(25, 119, 225, .28)
        }

        .meeting-btn:hover {
            color: #fff !important;
            background: linear-gradient(135deg, var(--brand-dark), var(--brand));
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(25, 119, 225, .35)
        }

        .meeting-btn i {
            font-size: 14px
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
            <script>var hoursDifference = <?= $hours_difference ?>;</script>

            <div class="main-panel">
                <div class="content-wrapper">

                    <?php if (!empty($todayBirthdays)): ?>
                        <div class="birthday-marquee-full">
                            <marquee behavior="scroll" direction="left" scrollamount="5" onmouseover="this.stop();"
                                onmouseout="this.start();">
                                🎉 <b>Today's Birthdays:</b>
                                <?php foreach ($todayBirthdays as $b):
                                    $fn = explode(' ', $b['fullName'])[0];
                                    $wa = "https://wa.me/?text=" . urlencode("Happy Birthday $fn 🎉"); ?>
                                    <span class="bname">🎂 <?= htmlspecialchars($b['fullName']) ?> <a class="wish-btn"
                                            href="<?= $wa ?>" target="_blank">Wish</a></span>
                                <?php endforeach; ?>
                            </marquee>
                        </div>
                    <?php endif; ?>

                    <div class="beta-marquee-container">
                        <marquee behavior="scroll" direction="left" scrollamount="5" onmouseover="this.stop();"
                            onmouseout="this.start();">
                            <span class="beta-tag">This application is currently in the beta version. For any issues or
                                suggestions, please add in <a href="feedback.php"
                                    style="color:white;text-decoration:underline;">feedback / issues</a> section.</span>
                        </marquee>
                    </div>

                    <!-- ROW 1: Timesheet + Statistics -->
                    <div class="two-col">

                        <!-- TIMESHEET -->
                        <div class="card">
                            <div class="card-body">
                                <div class="card-accent"></div>
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <h4 class="card-title heading mb-0">Timesheet <span
                                            style="font-size:10px;color:#64748b;font-weight:400;">of
                                            <?= date('d M Y', strtotime($today)) ?></span></h4>
                                    <?php if (strtolower($userMode ?? '') === 'online'): ?>
                                        <span class="badge"
                                            style="background:#e0e7ff;color:#3730a3;font-weight:700;font-size:0.75rem;padding:4px 10px;border-radius:12px;border:1px solid #c7d2fe;"><i
                                                class="fa-solid fa-wifi mr-1"></i> Mode: Online</span>
                                    <?php else: ?>
                                        <span class="badge"
                                            style="background:#dcfce7;color:#166534;font-weight:700;font-size:0.75rem;padding:4px 10px;border-radius:12px;border:1px solid #bbf7d0;"><i
                                                class="fa-solid fa-building mr-1"></i> Mode: Offline</span>
                                    <?php endif; ?>
                                </div>
                                <div class="row">
                                    <div class="col-6">
                                        <span class="pt-label">Punch In <small
                                                style="color:#64748b;font-weight:normal;">(Expected:
                                                <?= $expInDisp ?>)</small></span>
                                        <?php if ($hasPunchIn): ?>
                                            <span
                                                class="pt-value"><?= date('h:i:s A', strtotime($dispPunchIn)) ?><?php if ($dispIsLate): ?><span
                                                        class="pt-late-tag"><?= $dispIsAbsentLate ? 'Late (Present)' : 'Late' ?></span><?php endif; ?></span>
                                        <?php else: ?><span class="pt-value dash">—</span><?php endif; ?>
                                        <span class="pt-label" style="margin-top:6px;">Punch Out <small
                                                style="color:#64748b;font-weight:normal;">(Expected:
                                                <?= $expOutDisp ?>)</small></span>
                                        <?php if ($hasPunchOut): ?>
                                            <span class="pt-value"><?= date('h:i:s A', strtotime($dispPunchOut)) ?></span>
                                        <?php else: ?><span class="pt-value dash">—</span><?php endif; ?>
                                    </div>
                                    <div class="col-6" style="text-align:end;">

                                        <?php
                                        // ── Tour state ──
                                        $tourQ2 = mysqli_query($con, "SELECT 1 FROM tour WHERE userId='$userId' AND '$today' BETWEEN DATE(fromDate) AND DATE(toDate) AND status IN ('Approved','Pending') LIMIT 1");
                                        $onTour2 = mysqli_num_rows($tourQ2) > 0;

                                        if ($onTour2):
                                            $tourAttQ = mysqli_query($con, "SELECT punchInTime,punchOutTime,manHours FROM tourAttendance WHERE userId='$userId' AND date='$today' ORDER BY id DESC LIMIT 1");
                                            $tourResult = mysqli_fetch_assoc($tourAttQ);
                                            $tourAttCount = (int) mysqli_num_rows($tourAttQ);
                                            $tHasIn = !empty($tourResult['punchInTime']) && $tourResult['punchInTime'] !== '00:00:00';
                                            $tHasOut = !empty($tourResult['punchOutTime']) && $tourResult['punchOutTime'] !== '00:00:00';
                                            $tHasMH = !empty($tourResult['manHours']) && $tourResult['manHours'] !== '00:00:00';
                                            $tourPunchedIn = $tHasIn && !$tHasOut && !$tHasMH;
                                            $tourCompleted = $tHasIn && ($tHasOut || $tHasMH);
                                            ?>
                                            <?php if ($tourAttCount == 0 || $tourCompleted): ?>
                                                <a href="captureSelfie.php?punch=in&tour=1"
                                                    class="btn p-2 btn-inverse-success btn-rounded btn-fw">Tour Punch In</a>
                                            <?php elseif ($tourPunchedIn): ?>
                                                <a href="captureSelfie.php?punch=out&tour=1"
                                                    class="btn p-2 btn-inverse-success btn-rounded btn-fw">Tour Punch Out</a>
                                            <?php endif; ?>

                                        <?php elseif ($notPunchedYet): ?>
                                            <?php
                                            if ($graceTime >= $nowTime):
                                                ?>
                                                <a href="<?= $punchInUrl ?>"
                                                    class="btn p-2 btn-inverse-success btn-rounded btn-fw">Punch In</a>
                                                <?php
                                            else:
                                                $leaveQ0 = mysqli_query($con, "SELECT 1 FROM leaves WHERE userId='$userId' AND CURDATE() BETWEEN from_date AND to_date AND status='Approved' LIMIT 1");
                                                if ($attendanceCount > 0 || mysqli_num_rows($leaveQ0) > 0):
                                                    ?>
                                                    <a href="<?= $punchInUrl ?>"
                                                        class="btn p-2 btn-inverse-success btn-rounded btn-fw">Punch In</a>
                                                    <?php
                                                else:
                                                    $chk = mysqli_query($con, "SELECT 1 FROM leaves WHERE userId='$userId' AND from_date='$today' AND to_date='$today' LIMIT 1");
                                                    if (mysqli_num_rows($chk) == 0) {
                                                        $lid = 'LID_' . uniqid();
                                                        $ld = 'L-' . strtoupper(substr($userId, -4)) . '-' . date('dmY');
                                                        $ins = $con->prepare('INSERT INTO leaves (created_date,leave_id,leave_d,leave_name,leave_type,leave_day,from_date,to_date,reason,approved_by,informed_colleagues,userId,status,updated_date) VALUES (NOW(),?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
                                                        $ln = 'Auto Leave - Missing Punch In';
                                                        $lt = 'Auto';
                                                        $ld2 = 'Full Day';
                                                        $rs = 'System marked auto leave due to missing punch-in.';
                                                        $ab = '';
                                                        $ic = '';
                                                        $st = 'Pending';
                                                        $ins->bind_param('ssssssssssss', $lid, $ld, $ln, $lt, $ld2, $today, $today, $rs, $ab, $ic, $userId, $st);
                                                        $ins->execute();
                                                        $ins->close();
                                                    }
                                                    ?>
                                                    <div class="alert alert-warning mt-2 blink-alert">You haven't punched in on
                                                        time, so marked as absent.</div>
                                                    <?php
                                                endif;
                                            endif;
                                            ?>

                                        <?php elseif ($isPunchedIn): ?>
                                            <?php if ($hasRunning): ?>
                                                <div class="alert alert-warning mt-2 blink-alert">You have a running task.
                                                    Please pause or finish it before Punch Out.</div>
                                            <?php else: ?>

                                                <?php if ($_hasHrEarlyGo && $_earlyGoTs < $_expectedOutTs): ?>
                                                    <div
                                                        style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:6px 12px;font-size:11px;color:#92400e;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
                                                        <i class="fas fa-user-check"></i>
                                                        HR approved early go at <strong><?= date('h:i A', $_earlyGoTs) ?></strong>
                                                    </div>
                                                <?php endif; ?>

                                                <?php if ($_diaryHasPlanned && !$_diaryAllUpdated): ?>
                                                    <button type="button" class="btn p-2 btn-secondary btn-rounded btn-fw" disabled
                                                        style="opacity:.55;cursor:not-allowed;"
                                                        title="Update all Task Diary entries before Punch Out">
                                                        <i class="fas fa-lock mr-1" style="font-size:11px;"></i> Punch Out
                                                    </button>
                                                    <div style="font-size:11px;color:#dc2626;margin-top:5px;">
                                                        <i class="bi bi-exclamation-triangle"></i> <?= $_diaryPendingToday ?>
                                                        task(s) pending — <a href="taskDiary.php"
                                                            style="color:#1977e1;font-weight:600;">Update Task Diary</a>
                                                    </div>
                                                <?php else: ?>
                                                    <?php if ($_poBtnUnlocked): ?>
                                                        <button type="button" id="punchOutBtn"
                                                            class="btn p-2 btn-inverse-success btn-rounded btn-fw"
                                                            onclick="directPunchOut()">Punch Out</button>
                                                    <?php else: ?>
                                                        <button type="button" id="punchOutBtn"
                                                            class="btn p-2 btn-secondary btn-rounded btn-fw" disabled
                                                            style="opacity:.55;cursor:not-allowed;"
                                                            title="Punch Out unlocks at <?= $_unlockDisp ?>">
                                                            <i class="fas fa-lock mr-1" style="font-size:11px;"></i> Locked Till
                                                            <?= $_unlockDisp ?>
                                                        </button>
                                                        <div style="font-size:11px;color:#d97706;margin-top:5px;">
                                                            <i class="bi bi-clock-history"></i> Unlocks at <?= $_unlockDisp ?> (<span
                                                                id="countdownTimer"></span>)
                                                        </div>
                                                        <script>
                                                            (function () {
                                                                var unlockTs = <?= $_unlockTs ?> * 1000;
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
                                                    <?php endif; endif; ?>


                                            <?php endif; ?>


                                        <?php elseif ($hasPunchIn && $hasPunchOut): ?>
                                            <a href="<?= $punchInUrl ?>"
                                                class="btn p-2 btn-inverse-success btn-rounded btn-fw">Punch In</a>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Today's progress bar -->
                                <div class="mt-3 border-block">
                                    <p class="mb-0 tit">Today</p>
                                    <div class="progress-wrap">
                                        <div class="prog-tooltip">Worked: <?= $displayH ?>h
                                            <?= str_pad($displayM, 2, '0', STR_PAD_LEFT) ?>m / 8h &nbsp;|&nbsp;
                                            <?= round($perc_today) ?>% done
                                        </div>
                                        <div class="progress progress-md flex-grow-1">
                                            <div class="progress-bar bg-success"
                                                style="width:<?= max(3, round($perc_today, 2)) ?>%"></div>
                                        </div>
                                        <p class="mb-0" style="font-size:11px;white-space:nowrap;"><?= $displayH ?>h
                                            <?= str_pad($displayM, 2, '0', STR_PAD_LEFT) ?>m / <span
                                                style="font-size:10px;color:#83878b;">8hr</span>
                                        </p>
                                    </div>
                                </div>

                                <!-- Lunch + Overtime -->
                                <div class="lunch-summary">
                                    <div class="lunch-item">
                                        <div class="lunch-title">LUNCH BREAK</div>
                                        <div class="lunch-value">
                                            <?php if ($notPunchedYet): ?>
                                                <button class="btn btn-outline-secondary btn-xs ms-1"
                                                    style="font-size:12px;padding:5px 15px;cursor:not-allowed;" disabled
                                                    title="Please punch in first to start lunch">Start</button>
                                            <?php elseif ($lunchStatus === 'not_started'): ?>
                                                <button onclick="updateLunch('start')"
                                                    class="btn btn-outline-success btn-xs ms-1"
                                                    style="font-size:12px;padding:5px 15px;">Start</button>
                                            <?php elseif ($lunchStatus === 'started'): ?>
                                                <button onclick="updateLunch('end')"
                                                    class="btn btn-outline-danger btn-xs ms-1"
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

                        <!-- STATISTICS -->
                        <div class="card">
                            <div class="card-body">
                                <div class="card-accent"></div>
                                <p class="card-title heading">Statistics</p>
                                <div class="charts-data">
                                    <?php
                                    $stats = [
                                        ['This Week', 'bg-danger', $week_hr_php, $week_mn_php, $perc_week, '56', null],
                                        ['This Month', 'bg-warning', $month_hr_php, $month_mn_php, $perc_month, '240', null],
                                        ['Monthly Overtime', 'bg-info', null, null, min(100, max(3, $hours_overtiming_php * 10)), null, $hours_overtiming_php],
                                    ];
                                    foreach ($stats as [$label, $color, $hr, $mn, $perc, $of, $ot]): ?>
                                        <div class="mt-1 border-block">
                                            <p class="mb-0 tit"><?= $label ?></p>
                                            <div class="progress-wrap">
                                                <?php if ($hr !== null): ?>
                                                    <div class="prog-tooltip">Worked: <?= $hr ?>h
                                                        <?= str_pad($mn, 2, '0', STR_PAD_LEFT) ?>m of <?= $of ?>h &nbsp;|&nbsp;
                                                        <?= round($perc) ?>% complete
                                                    </div>
                                                <?php else: ?>
                                                    <div class="prog-tooltip">Total overtime this month: <?= $ot ?> hr</div>
                                                <?php endif; ?>
                                                <div class="progress progress-md flex-grow-1">
                                                    <div class="progress-bar <?= $color ?>"
                                                        style="width:<?= max(3, round($perc, 2)) ?>%"></div>
                                                </div>
                                                <?php if ($hr !== null): ?>
                                                    <p class="mb-0" style="font-size:11px;white-space:nowrap;margin-left:8px;">
                                                        <?= $hr ?>h <?= str_pad($mn, 2, '0', STR_PAD_LEFT) ?>m / <span
                                                            style="font-size:10px;color:#83878b;"><?= $of ?>hr</span>
                                                    </p>
                                                <?php else: ?>
                                                    <p class="mb-0" style="font-size:11px;margin-left:8px;"><?= $ot ?> hr</p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div><!-- /.two-col -->

                    <!-- PRIORITY FEATURED CARD: TODAY'S ALLOCATED PROJECT & TEAM LEAD CONTACT -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card priority-featured-card">
                                <div class="card-body p-4">
                                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                                        <div>
                                            <h4 class="fw-bold mb-1 priority-card-title">
                                                <i class="fa fa-calendar-check-o me-2 text-primary"></i>
                                                Today's Priority Project Schedule
                                            </h4>
                                            <p class="text-muted mb-0" style="font-size:0.8rem;">Your allocated project
                                                assignment and team lead contact for today (<?= date('l, d M Y') ?>)</p>
                                        </div>
                                        <span class="badge badge-primary px-3 py-2"
                                            style="font-size:0.78rem; border-radius:20px; background:#2563eb;">
                                            ⏰ Today's Assignment
                                        </span>
                                    </div>

                                    <?php if ($assignedTasksQ && mysqli_num_rows($assignedTasksQ) > 0):
                                        mysqli_data_seek($assignedTasksQ, 0); ?>
                                        <div class="row">
                                            <?php while ($t = mysqli_fetch_assoc($assignedTasksQ)):
                                                $lName = !empty($t['leadName']) ? $t['leadName'] : 'Resource Manager';
                                                $lPhone = !empty($t['leadMobile']) ? $t['leadMobile'] : '';
                                                ?>
                                                <div class="col-md-6 mb-3">
                                                    <div class="p-3 priority-lead-card">
                                                        <div class="d-flex align-items-center justify-content-between mb-2">
                                                            <h5 class="fw-bold text-dark mb-0">
                                                                <?= htmlspecialchars($t['projectName']) ?>
                                                            </h5>
                                                            <span class="badge badge-success"
                                                                style="background:#eaf3de; color:#27500A; font-weight:700;">Active
                                                                Assignment</span>
                                                        </div>
                                                        <?php if (!empty($t['clientName'])): ?>
                                                            <p class="text-muted mb-2" style="font-size:0.8rem;"><i
                                                                    class="fa fa-building-o me-1"></i> Client:
                                                                <strong><?= htmlspecialchars($t['clientName']) ?></strong>
                                                            </p>
                                                        <?php endif; ?>

                                                        <div class="d-flex align-items-center gap-3 my-2 p-2 priority-shift-badge"
                                                            style="border-radius:8px; font-size:0.82rem;">
                                                            <?php
                                                            $sTime = !empty($t['allocStartTime']) ? date('h:i A', strtotime($t['allocStartTime'])) : '—';
                                                            $eTime = !empty($t['allocEndTime']) ? date('h:i A', strtotime($t['allocEndTime'])) : '—';
                                                            ?>
                                                            <div><i class="fa fa-clock-o text-primary me-1"></i> Shift Time:
                                                                <strong><?= $sTime ?> – <?= $eTime ?></strong>
                                                            </div>
                                                        </div>

                                                        <div class="mt-3 pt-2" style="border-top:1px dashed #e0dfff;">
                                                            <div
                                                                class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                                                                <div>
                                                                    <div
                                                                        style="font-size:0.72rem; color:#888; text-transform:uppercase; font-weight:700;">
                                                                        Project / Requested Lead:</div>
                                                                    <div
                                                                        style="font-size:0.88rem; font-weight:700;" class="priority-lead-name">
                                                                        👤 <?= htmlspecialchars($lName) ?></div>
                                                                </div>
                                                                <?php if (!empty($lPhone)): ?>
                                                                    <a href="tel:<?= htmlspecialchars($lPhone) ?>"
                                                                        class="btn btn-sm btn-success fw-bold px-3 py-2"
                                                                        style="border-radius:6px; font-size:0.8rem;">
                                                                        <i class="fa fa-phone me-1"></i> Call Lead:
                                                                        <?= htmlspecialchars($lPhone) ?>
                                                                    </a>
                                                                <?php else: ?>
                                                                    <span class="text-muted small"><i
                                                                            class="fa fa-info-circle me-1"></i> Contact via
                                                                        RM</span>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endwhile; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="p-3 text-center priority-empty-card">
                                            <i class="fa fa-calendar-o fa-2x text-muted mb-2 d-block"></i>
                                            <p class="text-muted mb-0 fw-bold">No project allocations scheduled for today.
                                            </p>
                                            <small class="text-muted">Contact your Resource Head if you are expecting a new
                                                assignment.</small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>




                    <!-- ROW 2: Attendance + Leaves + Quick Links -->
                    <div class="three-col">
                        <!-- Monthly Attendance -->
                        <div class="card">
                            <div class="card-body">
                                <div class="card-accent"></div>
                                <p class="card-title heading mb-2">Monthly Attendance</p>
                                <div class="stat-mini-grid">
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
                                <div style="margin-top:8px;text-align:right;">
                                    <a href="myAttendance.php"
                                        style="font-size:11px;color:var(--brand);font-weight:600;text-decoration:none;">View
                                        Calendar →</a>
                                </div>
                            </div>
                        </div>

                        <!-- My Leave Status -->
                        <div class="card">
                            <div class="card-body">
                                <div class="card-accent"></div>
                                <div
                                    style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                                    <p class="card-title heading mb-0">My Leave Status</p>
                                    <a href="apply_leave.php"
                                        style="font-size:11px;color:var(--brand);font-weight:600;text-decoration:none;">+
                                        Apply</a>
                                </div>

                                <!-- Leave Policy Balances -->
                                <?php
                                $dCL = (int) ($userData['CL'] ?? 0);
                                $dSL = (int) ($userData['SL'] ?? 0);
                                $dPL = (int) ($userData['PL'] ?? 0);
                                $dTotal = $dCL + $dSL + $dPL;
                                ?>
                                <div class="d-flex justify-content-between text-center p-2 mb-2 rounded border-block"
                                    style="font-size:11px;">
                                    <div><span class="text-muted d-block"
                                            style="font-size:10px;">CL</span><strong><?= $dCL ?></strong>/4</div>
                                    <div><span class="text-muted d-block"
                                            style="font-size:10px;">SL</span><strong><?= $dSL ?></strong>/4</div>
                                    <div><span class="text-muted d-block"
                                            style="font-size:10px;">PL/EL</span><strong><?= $dPL ?></strong>/5</div>
                                    <div><span class="text-muted d-block" style="font-size:10px;">Total</span><strong
                                            style="color:var(--brand);"><?= $dTotal ?></strong>/13</div>
                                </div>

                                <?php if ($myLeavesQ && mysqli_num_rows($myLeavesQ) > 0):
                                    while ($lv = mysqli_fetch_assoc($myLeavesQ)): ?>
                                        <div class="leave-row">
                                            <div>
                                                <div class="leave-row__type"><?= htmlspecialchars($lv['leave_type']) ?></div>
                                                <div class="leave-row__date"><?= date('d M', strtotime($lv['from_date'])) ?> –
                                                    <?= date('d M Y', strtotime($lv['to_date'])) ?>
                                                </div>
                                            </div>
                                            <span
                                                class="leave-badge <?= strtolower($lv['status']) ?>"><?= $lv['status'] ?></span>
                                        </div>
                                    <?php endwhile;
                                else: ?>
                                    <div class="empty-state"><i class="bi bi-calendar-check"></i>No leave applications</div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Quick Links -->
                        <div class="card">
                            <div class="card-body">
                                <div class="card-accent"></div>
                                <p class="card-title heading mb-2">Quick Links</p>
                                <div class="quick-grid">
                                    <?php
                                    $links = [
                                        ['taskDiary.php', '#fef3c7', '#d97706', 'bi-journal-text', 'Task Diary'],
                                        ['taskEntry.php', '#eff6ff', 'var(--brand)', 'bi-list-check', 'My Tasks'],
                                        ['weeklyPlanOverview.php', '#f0fdf4', '#16a34a', 'bi-calendar-week', 'Weekly Plan'],
                                        ['apply_leave.php', 'var(--green-bg)', 'var(--green)', 'bi-calendar-plus', 'Apply Leave'],
                                        ['apply_tour.php', 'var(--amber-bg)', 'var(--amber)', 'bi-map', 'Apply Tour'],
                                        ['apply-wfh.php', 'var(--purple-bg)', 'var(--purple)', 'bi-house-door', 'Apply WFH'],
                                        ['jrTaskEntry.php', 'var(--orange-bg)', 'var(--orange)', 'bi-person-plus', 'Allocate Task'],
                                        ['myAttendance.php', 'var(--red-bg)', 'var(--red)', 'bi-calendar3', 'Attendance'],
                                        ['Attendance_Regularization.php', '#f0fdf4', '#16a34a', 'bi-pencil-square', 'Regularization'],
                                        ['feedback.php', '#fdf4ff', '#9333ea', 'bi-chat-dots', 'Feedback'],
                                    ];
                                    foreach ($links as [$href, $bg, $fg, $icon, $label]): ?>
                                        <a href="<?= $href ?>" class="quick-link">
                                            <div class="quick-link__icon" style="background:<?= $bg ?>;color:<?= $fg ?>;"><i
                                                    class="bi <?= $icon ?>"></i></div>
                                            <?= $label ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div><!-- /.three-col -->

                    <!-- ROW 3: Today's Tasks + Project Progress -->
                    <div class="two-col-wide">
                        <!-- PROJECT PROGRESS -->
                        <div class="card">
                            <div class="card-body">
                                <div class="card-accent"></div>
                                <div
                                    style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                                    <p class="card-title heading mb-0">Project Progress</p>
                                    <a href="projectProgress.php"
                                        style="font-size:11px;color:var(--brand);font-weight:600;text-decoration:none;">Manage
                                        →</a>
                                </div>
                                <form method="POST" action="projectProgress-db.php" id="dashProjProgressForm"
                                    style="margin-bottom:8px;">
                                    <input type="hidden" name="userId" value="<?= $userId ?>">
                                    <input type="hidden" name="progress" id="dashProgressVal" value="0">
                                    <input type="hidden" name="checklistArray" value="">
                                    <div style="display:flex;gap:6px;align-items:flex-end;flex-wrap:wrap;">
                                        <div style="flex:1;min-width:0;">
                                            <label
                                                style="font-size:9px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.3px;display:block;margin-bottom:2px;">Project</label>
                                            <select name="projectId" id="dashProjSelect"
                                                class="form-control form-control-sm"
                                                style="font-size:11px;padding:4px 8px;border-radius:7px;border:1px solid #e2e8f0;height:auto;"
                                                onchange="dashFetchProject()" required>
                                                <option value="" disabled selected>-- Select --</option>
                                                <?php if ($projQ) {
                                                    mysqli_data_seek($projQ, 0);
                                                    while ($rp = mysqli_fetch_assoc($projQ))
                                                        echo "<option value='" . htmlspecialchars($rp['projectId']) . "'>" . htmlspecialchars($rp['projectName']) . "</option>";
                                                } ?>
                                            </select>
                                        </div>
                                        <div style="flex-shrink:0;text-align:center;min-width:42px;">
                                            <label
                                                style="font-size:9px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.3px;display:block;margin-bottom:2px;">Progress</label>
                                            <span id="dashProgressDisplay"
                                                style="font-size:17px;font-weight:700;color:var(--brand);line-height:1;display:block;">0%</span>
                                        </div>
                                        <div style="flex-shrink:0;">
                                            <label
                                                style="font-size:9px;display:block;margin-bottom:2px;visibility:hidden;">·</label>
                                            <button type="submit" name="create" class="btn btn-sm"
                                                style="background:linear-gradient(135deg,var(--brand),var(--brand-dark));color:#fff;border:none;border-radius:7px;font-size:11px;font-weight:700;padding:5px 12px;">Set</button>
                                        </div>
                                    </div>
                                    <div style="margin-top:5px;">
                                        <div style="background:#eef1f7;border-radius:99px;height:5px;">
                                            <div id="dashProgressBar"
                                                style="width:0%;background:var(--brand);height:5px;border-radius:99px;transition:width .3s;">
                                            </div>
                                        </div>
                                    </div>
                                    <div style="margin-top:5px;"><textarea name="remark" id="dashRemark" rows="2"
                                            placeholder="Remark (optional)"
                                            style="width:100%;border-radius:7px;border:1px solid var(--border,#e2e8f0);background:var(--surface-2,#f8fafc);font-size:11px;padding:5px 8px;resize:none;box-sizing:border-box;color:var(--ink,#1e293b);"></textarea>
                                    </div>
                                </form>
                                <div style="height:1px;background:#f3f4f6;margin-bottom:7px;"></div>
                                <div
                                    style="font-size:9px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px;">
                                    Recent Status</div>
                                <?php if ($projProgressQ && mysqli_num_rows($projProgressQ) > 0):
                                    while ($pp = mysqli_fetch_assoc($projProgressQ)):
                                        $prog = (int) $pp['progress'];
                                        $bc = $prog >= 80 ? '#059669' : ($prog >= 40 ? '#d97706' : '#dc2626');
                                        $dl = !empty($pp['deadline']) ? date('d M Y', strtotime($pp['deadline'])) : '—';
                                        ?>
                                        <div
                                            style="margin-bottom:6px;padding:6px 8px;background:#f9fafb;border-radius:7px;border:1px solid #e8edf5;">
                                            <div
                                                style="display:flex;align-items:center;justify-content:space-between;margin-bottom:3px;gap:6px;">
                                                <span
                                                    style="font-size:11px;font-weight:600;color:#374151;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:160px;"
                                                    title="<?= htmlspecialchars($pp['projectName']) ?>"><?= htmlspecialchars($pp['projectName']) ?></span>
                                                <span
                                                    style="font-size:10px;font-weight:700;color:<?= $bc ?>;flex-shrink:0;"><?= $prog ?>%</span>
                                            </div>
                                            <div style="background:#eef1f7;border-radius:99px;height:4px;">
                                                <div
                                                    style="width:<?= $prog ?>%;background:<?= $bc ?>;height:4px;border-radius:99px;transition:width .3s;">
                                                </div>
                                            </div>
                                            <?php if (!empty($pp['remark'])): ?>
                                                <div
                                                    style="font-size:10px;color:#6b7280;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                                    <?= htmlspecialchars(mb_substr($pp['remark'], 0, 50)) ?>
                                                    <?= mb_strlen($pp['remark']) > 50 ? '…' : '' ?>
                                                </div>
                                            <?php endif; ?>
                                            <div style="font-size:9px;color:#9ca3af;margin-top:1px;"><i
                                                    class="bi bi-calendar2"></i> Deadline: <?= $dl ?></div>
                                        </div>
                                    <?php endwhile;
                                else: ?>
                                    <div class="empty-state" style="padding:10px;"><i class="bi bi-bar-chart-line"></i>No
                                        project progress logged yet</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div><!-- /.two-col-wide -->

                    <!-- ROW 3.5: Upcoming Meetings -->
                    <div style="margin-bottom:20px;">
                        <div class="card">
                            <div class="card-body">
                                <div class="card-accent"></div>
                                <div
                                    style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                                    <p class="card-title heading mb-0"><i class="bi bi-camera-video me-1"></i> Upcoming
                                        Meetings</p>
                                    <a href="meetingHistory.php"
                                        style="font-size:12px;color:var(--brand);font-weight:600;text-decoration:none;">View
                                        Calendar →</a>
                                </div>
                                <?php if ($upcomingMeetingsQ && mysqli_num_rows($upcomingMeetingsQ) > 0): ?>
                                    <div class="row">
                                        <?php while ($m = mysqli_fetch_assoc($upcomingMeetingsQ)):
                                            $mDate = $m['meeting_date'];
                                            $mTime = $m['meeting_time'];
                                            $mLink = $m['meeting_link'] ?? '';
                                            $desc = $m['description'] ?? '';
                                            $agenda = $m['agenda'] ?? '';
                                            $mode = strtolower($m['mode'] ?? 'online');
                                            $creator = $m['creator_name'] ?? 'Organizer';
                                            ?>
                                            <div class="col-md-6 col-lg-4 mb-3">
                                                <div class="border-block"
                                                    style="border-radius:10px; padding:15px; height:100%; display:flex; flex-direction:column; justify-content:space-between;">
                                                    <div>
                                                        <div
                                                            style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:8px;">
                                                            <span class="badge"
                                                                style="background:<?= $mode === 'online' ? '#e8f5e9;color:#2e7d32;' : '#fff3e0;color:#e65100;' ?> font-size:10px; font-weight:700; border-radius:99px; padding:3px 10px; text-transform:uppercase;">
                                                                <?= htmlspecialchars($mode) ?>
                                                            </span>
                                                            <span style="font-size:11px; color:#64748b; font-weight:600;">
                                                                📅 <?= date('d M Y', strtotime($mDate)) ?>
                                                            </span>
                                                        </div>
                                                        <h5
                                                            style="font-size:14px; font-weight:700; color:#1e293b; margin-bottom:6px;">
                                                            <?= htmlspecialchars($agenda) ?>
                                                        </h5>
                                                        <?php if (!empty($desc)): ?>
                                                            <p
                                                                style="font-size:12px; color:#64748b; margin-bottom:12px; line-height:1.4; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;">
                                                                <?= htmlspecialchars($desc) ?>
                                                            </p>
                                                        <?php endif; ?>
                                                        <div style="font-size:11px; color:#64748b; margin-bottom:12px;">
                                                            <i class="bi bi-clock me-1"></i>
                                                            <?= date('h:i A', strtotime($mTime)) ?>
                                                        </div>

                                                        <div style="font-size:11px; color:#64748b; margin-bottom:12px;">
                                                            <i class="bi bi-person-plus me-1"></i>
                                                            Invited By: <strong><?= htmlspecialchars($creator) ?></strong>
                                                        </div>
                                                    </div>
                                                    <?php if (!empty($mLink)): ?>
                                                        <div
                                                            style="background:#f1f5f9; border:1px solid #cbd5e1; border-radius:8px; padding:6px 10px; margin-bottom:8px; display:flex; align-items:center; justify-content:space-between; gap:6px;">
                                                            <span
                                                                style="font-size:11px; color:#475569; font-weight:600; text-overflow:ellipsis; overflow:hidden; white-space:nowrap; max-width:70%;"
                                                                title="<?= htmlspecialchars($mLink) ?>">
                                                                🔗 <a href="<?= htmlspecialchars($mLink) ?>" target="_blank"
                                                                    rel="noopener noreferrer"
                                                                    style="color:#0284c7; text-decoration:underline; font-weight:600;"><?= htmlspecialchars($mLink) ?></a>
                                                            </span>
                                                            <a href="<?= htmlspecialchars($mLink) ?>" target="_blank"
                                                                rel="noopener noreferrer"
                                                                class="btn btn-sm btn-success fw-bold px-2 py-1"
                                                                style="font-size:11px; border-radius:6px; white-space:nowrap;">
                                                                <i class="bi bi-camera-video-fill me-1"></i> Join
                                                            </a>
                                                        </div>
                                                        <a href="<?= htmlspecialchars($mLink) ?>" target="_blank"
                                                            class="meeting-btn">
                                                            <i class="bi bi-camera-video-fill me-1"></i> Join Meeting
                                                        </a>
                                                    <?php else: ?>
                                                        <button class="btn btn-sm btn-secondary w-100 fw-bold" disabled
                                                            style="border-radius:8px; font-size:12px; padding:6px 12px;">
                                                            In-Person (<?= htmlspecialchars($m['branch'] ?? 'Office') ?>)
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endwhile; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="empty-state"><i class="bi bi-calendar-x"></i>No upcoming meetings scheduled
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- ROW 4: On Leave + Task Summary -->
                    <div class="two-col-wide">
                        <!-- ON LEAVE -->
                        <div class="card text-center">
                            <div class="card-body">
                                <div class="card-accent"></div>
                                <h5 class="card-title heading">On Leave (Today)</h5>
                                <h3 class="fw-bold mb-2" style="font-size:2rem;color:var(--brand);"><?= $leaveCount ?>
                                </h3>
                                <p class="text-muted small">Employees currently on leave</p>
                                <button type="button" class="btn btn-outline-primary btn-sm px-4 fw-bold mt-1"
                                    data-bs-toggle="modal" data-bs-target="#onLeaveModal">View Details</button>
                            </div>
                        </div>

                        <!-- TASK SUMMARY -->
                        <div class="card">
                            <div class="card-body" style="overflow-y:auto;">
                                <div class="card-accent"></div>
                                <p class="card-title heading">Task Summary (Last 5 Days)</p>
                                <div class="table-responsive">
                                    <table id="taskSummaryTable" class="table table-sm table-bordered mb-0">
                                        <thead class="text-center">
                                            <tr>
                                                <th>Date <i class="sort-icon bi bi-chevron-expand"></i></th>
                                                <th>Task <i class="sort-icon bi bi-chevron-expand"></i></th>
                                                <th>Status <i class="sort-icon bi bi-chevron-expand"></i></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $tsQ = mysqli_query($con, "SELECT DATE(startTime) AS taskDate,task,status FROM TaskEntryNew WHERE userId='$userId' ORDER BY startTime DESC LIMIT 5");
                                            if ($tsQ && mysqli_num_rows($tsQ) > 0) {
                                                while ($tr = mysqli_fetch_assoc($tsQ)) {
                                                    $badge = match ($tr['status']) { 'Finished' => 'bg-success text-white', 'Paused' => 'bg-warning text-white', 'Running' => 'bg-primary text-white', default => 'bg-secondary text-white'};
                                                    echo "<tr class='text-center'><td>{$tr['taskDate']}</td><td>" . htmlspecialchars($tr['task']) . "</td><td><span class='badge $badge'>{$tr['status']}</span></td></tr>";
                                                }
                                            } else
                                                echo "<tr><td colspan='3' class='text-muted text-center'>No tasks found</td></tr>";
                                            ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div><!-- /.two-col-wide -->

                    <!-- On Leave Modal -->
                    <div class="modal fade" id="onLeaveModal" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-lg modal-dialog-centered">
                            <div class="modal-content shadow-lg border-0 rounded-3">
                                <div class="modal-header text-white"
                                    style="background:linear-gradient(135deg,#1977e1,#1254b0);">
                                    <h5 class="modal-title fw-bold"><i class="bi bi-calendar-check"></i> Today's Leaves
                                        (<?= date('d M, Y') ?>)</h5>
                                </div>
                                <div class="modal-body p-0">
                                    <?php
                                    $todayLeaves = mysqli_query($con, "
                                        SELECT COALESCE(s.fullName,'Not Found') AS fullName,l.reason,
                                               DATE_FORMAT(l.from_date,'%d %b') AS fdate,
                                               DATE_FORMAT(l.to_date,'%d %b %Y') AS tdate,
                                               COALESCE(a.fullName,l.approved_by) AS approved_by_name
                                        FROM leaves l
                                        LEFT JOIN signup s ON TRIM(LOWER(l.userId))=TRIM(LOWER(s.userId))
                                        LEFT JOIN signup a ON TRIM(LOWER(l.approved_by))=TRIM(LOWER(a.userId))
                                        WHERE TRIM(LOWER(l.status))='approved' AND CURDATE() BETWEEN DATE(l.from_date) AND DATE(l.to_date)
                                        ORDER BY s.fullName");
                                    $todayLeaveCount = mysqli_num_rows($todayLeaves);
                                    ?>
                                    <div class="table-responsive">
                                        <table class="table table-striped table-hover align-middle mb-0 border">
                                            <thead>
                                                <tr>
                                                    <th>Employee Name</th>
                                                    <th>Reason</th>
                                                    <th>From - To Date</th>
                                                    <th>Approved By</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if ($todayLeaveCount > 0)
                                                    while ($lr = mysqli_fetch_assoc($todayLeaves))
                                                        echo "<tr><td>{$lr['fullName']}</td><td>{$lr['reason']}</td><td>{$lr['fdate']} - {$lr['tdate']}</td><td>{$lr['approved_by_name']}</td></tr>";
                                                else
                                                    echo "<tr><td colspan='4' class='text-center text-muted py-3'>No employees on leave today</td></tr>"; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <div
                                    class="modal-footer bg-light border-0 d-flex justify-content-between align-items-center">
                                    <small class="text-muted"><i class="bi bi-people"></i> Total:
                                        <strong><?= $todayLeaveCount ?></strong> employees</small>
                                    <button type="button" class="btn btn-outline-primary btn-sm px-4 rounded-pill"
                                        data-bs-dismiss="modal">Close</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Info Modal -->
                    <div id="myModal" class="modal-noti">
                        <div style="padding:0;border:none;">
                            <span class="close" onclick="closeModal()"
                                style="color:#aaa;font-size:28px;font-weight:bold;position:absolute;top:-21px;right:-13px;cursor:pointer;">&times;</span>
                            <h2 style="margin:10px 0;font-size:18px;font-weight:700;color:#0f172a;">Important
                                Information</h2>
                            <div class="border-block"
                                style="margin:5px 0;font-size:15px;padding:10px;border-radius:10px;">
                                Total Pending Tasks: <strong
                                    style="color:#d9534f;"><?= $pendingTasksCount ?></strong><br>
                                <a href="taskEntry.php"
                                    style="margin-top:6px;display:inline-block;font-size:13px;text-decoration:none;color:#fff;background:linear-gradient(135deg,#1977e1,#1254b0);padding:5px 12px;border-radius:7px;font-weight:600;">View
                                    Details</a>
                            </div>
                            <button onclick="closeModal()" class="btn btn-sm btn-outline-primary fw-bold"
                                style="margin-top:15px;padding:8px 24px;border-radius:8px;">OK</button>
                        </div>
                    </div>

                    <?php include('../partials/_freeInternsWidget.php'); ?>

                </div><!-- /.content-wrapper -->
            </div><!-- /.main-panel -->
        </div>
    </div>

    <script src="../vendors/js/vendor.bundle.base.js"></script>
    <script src="../vendors/chart.js/Chart.min.js"></script>
    <script src="../vendors/datatables.net/jquery.dataTables.js"></script>
    <script src="../vendors/datatables.net-bs4/dataTables.bootstrap4.js"></script>
    <script src="../js/dataTables.select.min.js"></script>
    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>
    <script src="../js/settings.js"></script>
    <script src="../js/todolist.js?V=1"></script>
    <script src="../js/dashboard.js?v=1.0.1"></script>
    <script src="../js/Chart.roundedBarCharts.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

    <?php require_once('../include/sweetAlert.php');
    if (isset($_SESSION['msg'])) {
        $status = $_SESSION['status'];
        $msg = $_SESSION['msg'];
        if (!$status)
            toastMsg('error', $msg);
        else
            toastMsg('success', $msg);
        unset($_SESSION['status'], $_SESSION['msg']);
    } ?>

    <script>
        // ── Info modal ──
        document.addEventListener('DOMContentLoaded', function () {
            const modal = document.getElementById('myModal');
            if (modal) {
            <?php if ($showModal): ?>modal.style.display = 'block'; <?php endif; ?>
                const closeBtn = modal.querySelector('.close');
                if (closeBtn) closeBtn.addEventListener('click', closeModal);
            }
        });
        function closeModal() { const m = document.getElementById('myModal'); if (m) m.style.display = 'none'; }

        // ── Overtime ──
        function overTimePopup() { document.getElementById('overTimePopup').style.display = 'flex'; }
        function overTimeClosePopup() { document.getElementById('overTimePopup').style.display = 'none'; }
        function directPunchOut() {
            // Check Task Diary before allowing punch out
            fetch('../ajax/taskDiary.php?action=check_all_updated')
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.hasPlanned && !data.allUpdated) {
                        Swal.fire({
                            icon: 'warning',
                            title: 'Task Diary Incomplete',
                            html: 'You have <strong>' + data.pendingTasks + '</strong> pending task(s) in your Task Diary.<br>Please update all tasks before punching out.',
                            confirmButtonText: 'Go to Task Diary',
                            showCancelButton: true,
                            cancelButtonText: 'Cancel',
                            confirmButtonColor: '#1977e1'
                        }).then(result => {
                            if (result.isConfirmed) window.location.href = 'taskDiary.php';
                        });
                    } else {
                        window.location.href = '<?= $punchOutUrl ?>';
                    }
                })
                .catch(() => { window.location.href = '<?= $punchOutUrl ?>'; });
        }

        // ── Camera modal factory (reusable) ──
        function makeCameraModal(modalId, videoId, canvasId) {
            let stream = null;
            return {
                open(latId, lngId) {
                    const modal = document.getElementById(modalId); if (!modal) return;
                    modal.style.display = 'flex';
                    navigator.mediaDevices.getUserMedia({ video: true })
                        .then(s => { stream = s; document.getElementById(videoId).srcObject = s; })
                        .catch(err => alert('Camera access denied: ' + err));
                    if (navigator.geolocation && latId)
                        navigator.geolocation.getCurrentPosition(pos => {
                            document.getElementById(latId).value = pos.coords.latitude;
                            document.getElementById(lngId).value = pos.coords.longitude;
                        }, () => { });
                },
                close() {
                    const modal = document.getElementById(modalId); if (modal) modal.style.display = 'none';
                    if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; }
                    const v = document.getElementById(videoId); if (v) v.srcObject = null;
                },
                capture(inputId, formId) {
                    const v = document.getElementById(videoId), c = document.getElementById(canvasId);
                    c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
                    document.getElementById(inputId).value = c.toDataURL('image/png');
                    this.close(); document.getElementById(formId).submit();
                }
            };
        }
        const punchInModal = makeCameraModal('punchModal', 'video', 'canvas');
        const tourOutModal = makeCameraModal('tourPunchOutModal', 'videoTourOut', 'canvasTourOut');
        function openPunchModal() { punchInModal.open('punchInLat', 'punchInLong'); }
        function closePunchModal() { punchInModal.close(); }
        function capturePunchSelfie() { punchInModal.capture('selfieInput', 'punchInForm'); }
        function openTourPunchOutModal() { tourOutModal.open('punchOutLat', 'punchOutLong'); }
        function closeTourPunchOutModal() { tourOutModal.close(); }
        function captureTourPunchOut() { tourOutModal.capture('punchOutSelfieData', 'tourPunchOutForm'); }

        // ── Lunch ──
        function updateLunch(type) {
            if (!hoursDifference || hoursDifference <= 0) {
                Swal.fire({ icon: 'warning', text: 'Please punch in before starting your lunch break.', confirmButtonText: 'OK' }); return;
            }

            const runFetch = () => {
                fetch('lunch_action.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'type=' + encodeURIComponent(type) })
                    .then(r => r.json())
                    .then(data => {
                        if (!data.success) { Swal.fire({ icon: 'error', text: data.msg || 'Lunch action failed' }); return; }
                        Swal.fire({ icon: 'success', text: type === 'start' ? 'Lunch started successfully' : 'Lunch ended successfully', timer: 1500, showConfirmButton: false });
                        setTimeout(() => location.reload(), 1600);
                    }).catch(() => Swal.fire({ icon: 'error', text: 'Something went wrong.' }));
            };

            if (type === 'start') {
                const now = new Date();
                if ((now.getHours() * 60 + now.getMinutes()) < (13 * 60)) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Early Lunch Break',
                        text: 'Your Lunch Break starts at 1PM. Do you still want to start now?',
                        showCancelButton: true,
                        confirmButtonText: 'Yes, Start',
                        cancelButtonText: 'Cancel',
                        confirmButtonColor: '#28a745',
                        cancelButtonColor: '#dc3545'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            runFetch();
                        }
                    });
                    return;
                }
            }
            runFetch();
        }

        // ── Key Pending form helpers ──
        function addProjectForm() {
            const first = document.querySelector('.project-form-block'); if (!first) return;
            const block = first.cloneNode(true);
            block.querySelectorAll('select,textarea,input').forEach(el => el.value = '');
            const btn = block.querySelector('.btn-danger'); if (btn) btn.onclick = function () { removeProjectForm(this); };
            document.getElementById('projectFormContainer').appendChild(block); updateRemoveButtons();
        }
        function removeProjectForm(btn) {
            const blocks = document.querySelectorAll('.project-form-block');
            if (blocks.length > 1) { btn.closest('.project-form-block').remove(); updateRemoveButtons(); }
            else Swal.fire({ icon: 'warning', text: 'You must have at least one project entry.', timer: 2000, showConfirmButton: false });
        }
        function updateRemoveButtons() {
            document.querySelectorAll('.project-form-block').forEach((b, i) => {
                const btn = b.querySelector('.btn-danger'); if (btn) btn.style.display = i === 0 ? 'none' : 'inline-block';
            });
        }
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.project-form-block .btn-danger').forEach(btn => { btn.onclick = function () { removeProjectForm(this); }; });
            updateRemoveButtons();
        });

        // ── Project Progress widget ──
        function dashFetchProject() {
            const id = document.getElementById('dashProjSelect').value; if (!id) return;
            fetch('fetchProjectData.php?projectId=' + encodeURIComponent(id))
                .then(r => r.json())
                .then(data => {
                    const prog = data.success ? parseFloat(data.progress) || 0 : 0;
                    document.getElementById('dashProgressVal').value = prog;
                    document.getElementById('dashProgressDisplay').textContent = prog.toFixed(0) + '%';
                    document.getElementById('dashRemark').value = data.success ? (data.remark || '') : '';
                    const bar = document.getElementById('dashProgressBar');
                    bar.style.width = prog + '%';
                    bar.style.background = prog >= 80 ? '#059669' : prog >= 40 ? '#d97706' : '#dc2626';
                }).catch(() => { });
        }

        // ── DataTable ──
        $('#taskSummaryTable').DataTable({ destroy: true, paging: true, searching: true, ordering: false, info: true });
        if (window.location.search.length > 0) window.history.replaceState({}, document.title, window.location.pathname);
    </script>

    <?php include('../include/passwordChangeAlert.php'); ?>
</body>

</html>