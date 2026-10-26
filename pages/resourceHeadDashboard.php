<?php
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', 2592000); // 30 days
    session_set_cookie_params(2592000);          // 30 days
    session_start();
}
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    header("Location: login.php");
    exit;
}

include('../include/checkPasswordStatus.php');
date_default_timezone_set('Asia/Kolkata');

// ✅ Authorization check
if ($_SESSION['userRole'] !== 'resourceAllocator' && $_SESSION['designation'] !== 'resourceAllocator' && $_SESSION['userRole'] !== 'admin') {
    echo '<script>alert("You are not authorized to access this page."); window.location.href = "logout.php";</script>';
    die();
}

// ✅ Modal flag
$showModal = !isset($_SESSION['modal_shown']);
if ($showModal)
    $_SESSION['modal_shown'] = true;

include('../include/db_config.php');
require_once('availabilityEngine.php');

$today = date('Y-m-d');
$todayMD = date('m-d');
$raId = $_SESSION['userId'];

// ── Session Filters (Type & Branch) ──
$intType = $_SESSION['int_type'] ?? null;
$intBranch = $_SESSION['int_branch'] ?? null;

$typeCondition = '';
if (!empty($intType) && $intType !== 'Reset' && $intType !== 'All') {
    $typeCondition = " AND s.type = '" . mysqli_real_escape_string($con, $intType) . "'";
}
$typeConditionPlain = '';
if (!empty($intType) && $intType !== 'Reset' && $intType !== 'All') {
    $typeConditionPlain = " AND type = '" . mysqli_real_escape_string($con, $intType) . "'";
}

$branchCondition = '';
if (!empty($intBranch) && $intBranch !== 'Reset' && $intBranch !== 'All') {
    $escBranch = mysqli_real_escape_string($con, $intBranch);
    $branchCondition = " AND (s.branch = '$escBranch' OR s.office = '$escBranch')";
}
$branchConditionPlain = '';
if (!empty($intBranch) && $intBranch !== 'Reset' && $intBranch !== 'All') {
    $escBranch = mysqli_real_escape_string($con, $intBranch);
    $branchConditionPlain = " AND (branch = '$escBranch' OR office = '$escBranch')";
}

// Dynamic Available Branches
$availableBranches = [];
$qBranch = mysqli_query($con, "SELECT DISTINCT branch FROM signup WHERE branch IS NOT NULL AND branch != '' AND status = 'Active' ORDER BY branch ASC");
if ($qBranch) {
    while ($bRow = mysqli_fetch_assoc($qBranch)) {
        $availableBranches[] = $bRow['branch'];
    }
}
if (empty($availableBranches)) {
    $availableBranches = ['Pune', 'Kinwat'];
}

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

$wfhQuery = mysqli_query($con, "SELECT $dateSelect FROM wfhRequests WHERE userid = '$raId' AND status = 'Approved'");
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
    mysqli_query($con, "UPDATE signup SET type = 'Online' WHERE userId = '$raId'");
} else {
    mysqli_query($con, "UPDATE signup SET type = mode WHERE userId = '$raId'");
}

// ── Logged in User Data ──
$stmt = $con->prepare("SELECT type, expectedPunchOut, expectedPunchIn, start_date FROM signup WHERE userId = ? LIMIT 1");
$stmt->bind_param("s", $raId);
$stmt->execute();
$raData = $stmt->get_result()->fetch_assoc();
$stmt->close();

$userMode = $raData['type'] ?? 'Offline';
$expectedPunchOutTime = $raData['expectedPunchOut'] ?? '18:30:00';
$expectedPunchIn2 = $raData['expectedPunchIn'] ?? '09:30:00';
$expectedPunchInBase = $raData['expectedPunchIn'] ?? '09:30:00';
$expInDisp = !empty($expectedPunchInBase) ? date('h:i A', strtotime('2000-01-01 ' . $expectedPunchInBase)) : '09:30 AM';
$expOutDisp = !empty($expectedPunchOutTime) ? date('h:i A', strtotime('2000-01-01 ' . $expectedPunchOutTime)) : '06:30 PM';
$isOfflineIntern = (strtolower($raData['type'] ?? '') === 'offline');
$userStartDate = $raData['start_date'] ?? '';

// ── Birthdays ──
$todayBirthdays = [];
$stmt = $con->prepare("SELECT fullName, mobileNo FROM signup WHERE regstatus='Approved' AND status='Active' AND DATE_FORMAT(dob,'%m-%d')=? LIMIT 10");
$stmt->bind_param("s", $todayMD);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc())
    $todayBirthdays[] = $row;
$stmt->close();

// ── Late punch-in override ──
$stmt = $con->prepare("SELECT delayed_time FROM late_punch_in WHERE userId=? AND date=? LIMIT 1");
$stmt->bind_param("ss", $raId, $today);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0)
    $expectedPunchIn2 = $result->fetch_assoc()['delayed_time'];
$stmt->close();

// ── Tour check ──
$stmt = $con->prepare("SELECT COUNT(*) as cnt FROM tour WHERE userId=? AND ? BETWEEN DATE(fromDate) AND DATE(toDate) AND status IN ('Approved','Pending')");
$stmt->bind_param("ss", $raId, $today);
$stmt->execute();
$onTour = (int) $stmt->get_result()->fetch_assoc()['cnt'] > 0;
$stmt->close();

$attendanceTableName = $onTour ? 'tourAttendance' : 'attendance';

// ── Today's attendance ──
$stmt = $con->prepare("SELECT punchInTime, punchOutTime, manHours, status FROM $attendanceTableName WHERE userId=? AND date=? ORDER BY id DESC LIMIT 1");
$stmt->bind_param("ss", $raId, $today);
$stmt->execute();
$result = $stmt->get_result();
$attendanceRow = ($result->num_rows > 0) ? $result->fetch_assoc() : [];
$attendanceCount = $result->num_rows;
$stmt->close();

$punchInTime = $attendanceRow['punchInTime'] ?? '';
$punchOutTime = $attendanceRow['punchOutTime'] ?? '';
$hasPunchIn = !empty($punchInTime) && $punchInTime !== '00:00:00';
$hasPunchOut = !empty($punchOutTime) && $punchOutTime !== '00:00:00';
$notPunchedYet = !$hasPunchIn;
$isPunchedIn = $hasPunchIn && !$hasPunchOut;

// ── Display query ──
$dispQuery = $onTour
    ? "SELECT punchInTime, punchOutTime, manHours FROM tourAttendance WHERE userId=? AND date=? ORDER BY id DESC LIMIT 1"
    : "SELECT punchInTime, punchOutTime, manHours, lateMark FROM attendance WHERE userId=? AND date=? ORDER BY id DESC LIMIT 1";

$stmt = $con->prepare($dispQuery);
$stmt->bind_param("ss", $raId, $today);
$stmt->execute();
$result = $stmt->get_result();
$dispRow = ($result->num_rows > 0) ? $result->fetch_assoc() : null;
$stmt->close();

$dispPunchIn = $dispRow['punchInTime'] ?? null;
$dispPunchOut = $dispRow['punchOutTime'] ?? null;
$dispLateMark = strtolower($dispRow['lateMark'] ?? 'no');
$dispIsLate = ($dispLateMark === 'yes');
$dispIsAbsentLate = false;
if ($dispIsLate && !empty($dispPunchIn) && $dispPunchIn !== '00:00:00') {
    $expSec = strtotime("1970-01-01 $expectedPunchInBase");
    $piSec = strtotime("1970-01-01 $dispPunchIn");
    $diffMins = ($piSec - $expSec) / 60;
    if ($diffMins > 30)
        $dispIsAbsentLate = true;
}

// ── Hours worked ──
$hours_difference = 0;
if (!empty($dispPunchIn) && $dispPunchIn !== '00:00:00') {
    $punchInTs = strtotime($dispPunchIn);
    $punchOutTs = (!empty($dispPunchOut) && $dispPunchOut !== '00:00:00') ? strtotime($dispPunchOut) : time();
    if ($punchOutTs > $punchInTs)
        $hours_difference = ($punchOutTs - $punchInTs) / 3600;
}
$over_time = ($hours_difference > 8) ? round($hours_difference - 8, 1) : 0;
$totalMinutesToday = (int) round($hours_difference * 60);
$displayH = intdiv($totalMinutesToday, 60);
$displayM = $totalMinutesToday % 60;
$perc_today = min(100, max(0, ($hours_difference / 8) * 100));

// ── Lunch status ──
$lunchStatus = 'not_started';
$lunchStart = '';
$lunchEnd = '';
$lunchDuration = 0;
$stmt = $con->prepare("SELECT startTime, endTime, status FROM TaskEntryNew WHERE userId=? AND task='Lunch Break' AND DATE(startTime)=? ORDER BY taskId DESC LIMIT 1");
$stmt->bind_param("ss", $raId, $today);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $lunch = $result->fetch_assoc();
    $lunchStart = $lunch['startTime'];
    $lunchEnd = $lunch['endTime'];
    if ($lunch['status'] === 'Running')
        $lunchStatus = 'started';
    elseif ($lunch['status'] === 'Finished') {
        $lunchStatus = 'completed';
        $lunchDuration = round((strtotime($lunchEnd) - strtotime($lunchStart)) / 60);
    }
}
$stmt->close();

// ── Punch URLs ──
$punchOutUrl = ($userMode === 'Online') ? 'captureSelfie.php?punch=out' : 'qrCodeScanner_copy.php?punch=Out';
$punchInUrl = ($userMode === 'Online') ? 'captureSelfie.php?punch=in' : 'qrCodeScanner_copy.php?punch=In';

// ── Time Lock ──
$_expectedOutTs = strtotime(date('Y-m-d') . ' ' . $expectedPunchOutTime);
$_egStmt = $con->prepare("SELECT early_go_time FROM early_go WHERE userId=? AND date=? AND hr_granted=1 ORDER BY id DESC LIMIT 1");
$_egStmt->bind_param("ss", $raId, $today);
$_egStmt->execute();
$_egRow = $_egStmt->get_result()->fetch_assoc();
$_egStmt->close();
$_earlyGoTs = null;
$_hasHrEarlyGo = false;
if ($_egRow && !empty($_egRow['early_go_time'])) {
    $_earlyGoTs = strtotime(date('Y-m-d') . ' ' . $_egRow['early_go_time']);
    $_hasHrEarlyGo = true;
}
$_targetOutTs = ($_hasHrEarlyGo && $_earlyGoTs < $_expectedOutTs) ? $_earlyGoTs : $_expectedOutTs;
$_unlockTs = $_targetOutTs - (15 * 60);
$_poBtnUnlocked = (time() >= $_unlockTs);

// ── Personal Statistics ──
$firstDate = date('Y-m-01');
$wkStart = date('Y-m-d', strtotime('monday this week'));

$stmt = $con->prepare("SELECT punchInTime, punchOutTime, date FROM attendance WHERE userId=? AND date BETWEEN ? AND ?");
$stmt->bind_param("sss", $raId, $wkStart, $today);
$stmt->execute();
$result = $stmt->get_result();
$wkMin = 0;
while ($w = $result->fetch_assoc()) {
    if (empty($w['punchInTime']) || $w['punchInTime'] === '00:00:00')
        continue;
    $in = strtotime($w['punchInTime']);
    $out = ($w['date'] === $today)
        ? ((!empty($w['punchOutTime']) && $w['punchOutTime'] !== '00:00:00') ? strtotime($w['punchOutTime']) : time())
        : ((!empty($w['punchOutTime']) && $w['punchOutTime'] !== '00:00:00') ? strtotime($w['punchOutTime']) : 0);
    if ($out > $in)
        $wkMin += (int) (($out - $in) / 60);
}
$stmt->close();
$wkH = intdiv($wkMin, 60);
$wkM = $wkMin % 60;
$perc_week = min(100, max(0, ($wkMin / (56 * 60)) * 100));

// Month attendance
$stmt = $con->prepare("SELECT COUNT(*) as c FROM attendance WHERE userId=? AND date BETWEEN ? AND ? AND status='Rostering Off'");
$stmt->bind_param("sss", $raId, $firstDate, $today);
$stmt->execute();
$roDays = (int) $stmt->get_result()->fetch_assoc()['c'];
$stmt->close();
$dedMin = $roDays * 8 * 60;

$stmt = $con->prepare("SELECT punchInTime, punchOutTime, date FROM attendance WHERE userId=? AND date BETWEEN ? AND ?");
$stmt->bind_param("sss", $raId, $firstDate, $today);
$stmt->execute();
$result = $stmt->get_result();
$mTotalMin = 0;
while ($mR = $result->fetch_assoc()) {
    if (empty($mR['punchInTime']) || $mR['punchInTime'] === '00:00:00')
        continue;
    $mIn = strtotime($mR['punchInTime']);
    $mOut = ($mR['date'] === $today)
        ? ((!empty($mR['punchOutTime']) && $mR['punchOutTime'] !== '00:00:00') ? strtotime($mR['punchOutTime']) : time())
        : ((!empty($mR['punchOutTime']) && $mR['punchOutTime'] !== '00:00:00') ? strtotime($mR['punchOutTime']) : 0);
    if ($mOut > $mIn)
        $mTotalMin += (int) (($mOut - $mIn) / 60);
}
$stmt->close();
$mFinalMin = max(0, $mTotalMin - $dedMin);
$mH = intdiv($mFinalMin, 60);
$mM = $mFinalMin % 60;
$perc_month = min(100, max(0, ($mFinalMin / (240 * 60)) * 100));

// Overtime
$yesterday = date('Y-m-d', strtotime('yesterday'));
$stmt = $con->prepare("SELECT punchInTime, punchOutTime FROM attendance WHERE userId=? AND date BETWEEN ? AND ?");
$stmt->bind_param("sss", $raId, $firstDate, $yesterday);
$stmt->execute();
$result = $stmt->get_result();
$otHours = 0;
while ($otR = $result->fetch_assoc()) {
    if (!empty($otR['punchInTime']) && !empty($otR['punchOutTime']) && $otR['punchOutTime'] !== '00:00:00') {
        $diffHrs = (strtotime($otR['punchOutTime']) - strtotime($otR['punchInTime'])) / 3600;
        if ($diffHrs > 8)
            $otHours += round($diffHrs - 8, 1);
    }
}
$stmt->close();
if ($over_time > 0)
    $otHours += $over_time;

// Attendance summary counters
$holidaysMap = [];
$stmt = $con->prepare("SELECT from_date, to_date, title FROM holidays WHERE from_date <= ? AND to_date >= ?");
$stmt->bind_param("ss", $today, $firstDate);
$stmt->execute();
$result = $stmt->get_result();
while ($hRow = $result->fetch_assoc())
    for ($d = strtotime($hRow['from_date']); $d <= strtotime($hRow['to_date']); $d += 86400) {
        $hd = date('Y-m-d', $d);
        if ($hd >= $firstDate && $hd <= $today)
            $holidaysMap[$hd] = $hRow['title'];
    }
$stmt->close();

$leaveDatesMap = [];
$stmt = $con->prepare("SELECT from_date, to_date, leave_type FROM leaves WHERE userId=? AND status='Approved' AND from_date <= ? AND to_date >= ?");
$stmt->bind_param("sss", $raId, $today, $firstDate);
$stmt->execute();
$result = $stmt->get_result();
while ($lRow = $result->fetch_assoc())
    for ($d = strtotime($lRow['from_date']); $d <= strtotime($lRow['to_date']); $d += 86400) {
        $ld = date('Y-m-d', $d);
        if ($ld >= $firstDate && $ld <= $today)
            $leaveDatesMap[$ld] = $lRow['leave_type'];
    }
$stmt->close();

$validRODatesThisMonth = [];
if ($isOfflineIntern) {
    $stmt = $con->prepare("SELECT from_date, to_date FROM leaves WHERE userId=? AND leave_type='RO' AND status='Approved' ORDER BY from_date ASC");
    $stmt->bind_param("s", $raId);
    $stmt->execute();
    $result = $stmt->get_result();
    $allROThisMonth = [];
    while ($roRow = $result->fetch_assoc())
        for ($d = strtotime($roRow['from_date']); $d <= strtotime($roRow['to_date']); $d += 86400) {
            $rd = date('Y-m-d', $d);
            if ($rd >= $firstDate && $rd <= $today)
                $allROThisMonth[] = $rd;
        }
    $stmt->close();
    sort($allROThisMonth);
    $validRODatesThisMonth = array_slice($allROThisMonth, 0, 2);
}

$regMap = [];
$stmt = $con->prepare("SELECT date, punch_in FROM attendance_regularization WHERE user_id=? AND status='Approved' AND date BETWEEN ? AND ?");
$stmt->bind_param("sss", $raId, $firstDate, $today);
$stmt->execute();
$result = $stmt->get_result();
while ($rRow = $result->fetch_assoc()) {
    $regMap[$rRow['date']] = $rRow['punch_in'];
}
$stmt->close();

$lateIntimationsMap = [];
$stmt = $con->prepare("SELECT date FROM late_punch_in WHERE userId=? AND date BETWEEN ? AND ?");
$stmt->bind_param("sss", $raId, $firstDate, $today);
$stmt->execute();
$result = $stmt->get_result();
while ($liRow = $result->fetch_assoc()) {
    $lateIntimationsMap[$liRow['date']] = true;
}
$stmt->close();

$attMap = [];
$stmt = $con->prepare("SELECT date, punchInTime, punchOutTime, status, lateMark FROM attendance WHERE userId=? AND date BETWEEN ? AND ?");
$stmt->bind_param("sss", $raId, $firstDate, $today);
$stmt->execute();
$result = $stmt->get_result();
while ($aRow = $result->fetch_assoc())
    $attMap[$aRow['date']] = $aRow;
$stmt->close();

$tourAttMap = [];
$stmt = $con->prepare("SELECT date, punchInTime, punchOutTime FROM tourAttendance WHERE userId=? AND punchInTime != '00:00:00' AND date BETWEEN ? AND ?");
$stmt->bind_param("sss", $raId, $firstDate, $today);
$stmt->execute();
$result = $stmt->get_result();
while ($tRow = $result->fetch_assoc())
    $tourAttMap[$tRow['date']] = $tRow;
$stmt->close();

$presentDays = 0;
$absentDays = 0;
$lateDays = 0;
$leaveTaken = 0;
$period = new DatePeriod(new DateTime($firstDate), new DateInterval('P1D'), new DateTime(date('Y-m-d', strtotime($today . ' +1 day'))));
foreach ($period as $dateObj) {
    $day = $dateObj->format('Y-m-d');
    $dayOfWeek = $dateObj->format('D');
    if ($day > $today)
        continue;
    if ($dayOfWeek === 'Sun')
        continue;
    if ($dayOfWeek === 'Sat') {
        if (!$isOfflineIntern) {
            if (!isset($leaveDatesMap[$day]) || $leaveDatesMap[$day] !== 'RO')
                continue;
        }
    }
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
            $diffMins = (strtotime("1970-01-01 {$att['punchInTime']}") - strtotime("1970-01-01 " . ($expectedPunchInBase ?? '09:00:00'))) / 60;
            if ($diffMins > 30)
                $isAbsentLate = true;
        }

        if ($isLate || $isAbsentLate) {
            if (isset($lateIntimationsMap[$day])) {
                $isAbsentLate = false;
                $isLate = true;
            }
        }

        if (isset($regMap[$day])) {
            $regPunchIn = $regMap[$day];
            $isLate = (strtotime($regPunchIn) > strtotime($expectedPunchInBase ?? '09:00:00'));
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
        $leaveType = $leaveDatesMap[$day];
        if ($leaveType === 'RO') {
            if ($isOfflineIntern && !in_array($day, $validRODatesThisMonth))
                $absentDays++;
        } else
            $leaveTaken++;
        continue;
    }
    if ($day < $today)
        $absentDays++;
}

// ══════════════════════════════════════════════════
// RESOURCE MANAGEMENT ANALYTICS & STATS (FILTERED)
// ══════════════════════════════════════════════════

// Resource Requests
$pendingReq = (int) (mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as c FROM resourceRequests WHERE status='Pending'"))['c'] ?? 0);
$approvedReq = (int) (mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as c FROM resourceRequests WHERE status='Approved'"))['c'] ?? 0);
$rejectedReq = (int) (mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as c FROM resourceRequests WHERE status='Rejected'"))['c'] ?? 0);
$activeProj = (int) (mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as c FROM projectMaster WHERE status=1"))['c'] ?? 0);

// Total active team members (filtered by type & branch)
$qTotalEmp = mysqli_query($con, "SELECT COUNT(*) as c FROM signup s WHERE designation!='admin' AND status='Active' $typeCondition $branchCondition");
$totalEmp = (int) (mysqli_fetch_assoc($qTotalEmp)['c'] ?? 0);

// Allocated members
$qAllocated = mysqli_query($con, "
    SELECT COUNT(DISTINCT ap.userId) as c
    FROM allocatedProject ap
    JOIN signup s ON ap.userId = s.userId
    JOIN projectMaster pm ON ap.projectId = pm.projectId
    WHERE s.designation!='admin' AND s.status='Active' AND pm.status=1
    $typeCondition $branchCondition
");
$allocated = (int) (mysqli_fetch_assoc($qAllocated)['c'] ?? 0);
$benchCount = max(0, $totalEmp - $allocated);
$benchRate = $totalEmp > 0 ? round(($benchCount / $totalEmp) * 100, 1) : 0;

// Overloaded members
$qOverloaded = mysqli_query($con, "
    SELECT COUNT(DISTINCT userId) AS c FROM (
        SELECT ap.userId
        FROM allocatedProject ap
        JOIN signup s ON ap.userId = s.userId
        JOIN projectMaster pm ON ap.projectId = pm.projectId
        WHERE s.designation!='admin' AND s.status='Active' AND pm.status=1
        $typeCondition $branchCondition
        GROUP BY ap.userId
        HAVING COUNT(ap.projectId) > 2
    ) AS overTable
");
$overloadedCount = (int) (mysqli_fetch_assoc($qOverloaded)['c'] ?? 0);

// Branch distribution stats
$branchStats = [];
$qBranchDist = mysqli_query($con, "
    SELECT COALESCE(NULLIF(s.branch,''), s.office, 'Main') AS bName, COUNT(*) AS total
    FROM signup s
    WHERE designation!='admin' AND status='Active' $typeCondition
    GROUP BY bName ORDER BY total DESC
");
if ($qBranchDist) {
    while ($bRow = mysqli_fetch_assoc($qBranchDist)) {
        $branchStats[$bRow['bName']] = (int) $bRow['total'];
    }
}

// Resource requests list
$resListQ = $con->prepare("
    SELECT rr.*, s.fullName, s.designation, pm.projectName
    FROM resourceRequests rr
    LEFT JOIN signup s ON rr.requestedBy = s.userId
    LEFT JOIN projectMaster pm ON rr.projectName = pm.projectName
    WHERE rr.status='Pending'
    ORDER BY rr.createdAt DESC
    LIMIT 8
");
$resListQ->execute();
$resListResult = $resListQ->get_result();
$resListCnt = $resListResult->num_rows;

// Active Projects & Allocation
$projAllocQ = $con->prepare("
    SELECT pm.projectId, pm.projectName, pm.deadLine, pm.priority,
           COUNT(DISTINCT ap.userId) AS teamSize,
           COALESCE(MAX(pp.progress), 0) AS progress
    FROM projectMaster pm
    LEFT JOIN allocatedProject ap ON pm.projectId = ap.projectId
    LEFT JOIN projectProgress  pp ON pm.projectId = pp.projectId
    WHERE pm.status = 1
    GROUP BY pm.projectId, pm.projectName, pm.deadLine, pm.priority
    ORDER BY CASE pm.priority WHEN 'p1' THEN 1 WHEN 'p2' THEN 2 WHEN 'p3' THEN 3 ELSE 4 END, pm.deadLine ASC
    LIMIT 8
");
$projAllocQ->execute();
$projAllocResult = $projAllocQ->get_result();

$topProjNames = [];
$topProjTeams = [];
if ($projAllocResult && $projAllocResult->num_rows > 0) {
    while ($r = $projAllocResult->fetch_assoc()) {
        $topProjNames[] = substr($r['projectName'], 0, 12) . '..';
        $topProjTeams[] = (int) $r['teamSize'];
    }
    $projAllocResult->data_seek(0);
}

// Upcoming Meetings
$upcomingMeetingsQ = $con->prepare("
    SELECT m.*, s.fullName AS creator_name
    FROM meetings m
    LEFT JOIN signup s ON s.userId COLLATE utf8mb4_unicode_ci = m.creator_id COLLATE utf8mb4_unicode_ci
    WHERE (FIND_IN_SET(?, m.participants COLLATE utf8mb4_unicode_ci) > 0 OR m.creator_id = ?)
      AND m.meeting_date >= CURDATE()
      AND (m.status IS NULL OR (m.status != 'completed' AND m.status != 'cancelled'))
    ORDER BY m.meeting_date ASC, m.meeting_time ASC
    LIMIT 4
");
$upcomingMeetingsQ->bind_param("ss", $raId, $raId);
$upcomingMeetingsQ->execute();
$upcomingMeetingsResult = $upcomingMeetingsQ->get_result();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resource Head Analytics Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <?php include('../partials/_links.php') ?>
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
            --teal: #0d9488;
            --teal-bg: #f0fdfa;
            --accent: #1d4ed8;
            --accent-2: #1e40af;
            --accent-bg: #eff6ff;
            --ink: #111827;
            --ink-2: #374151;
            --ink-3: #6b7280;
            --ink-4: #9ca3af;
            --surface: #ffffff;
            --surface-2: #f9fafb;
            --surface-3: #f3f4f6;
            --border: #e5e7eb;
            --border-2: #d1d5db;
            --radius: 10px;
            --radius-lg: 14px;
            --shadow: 0 1px 3px rgba(0, 0, 0, 0.06), 0 1px 2px rgba(0, 0, 0, 0.04);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.07), 0 1px 3px rgba(0, 0, 0, 0.04);
            --shadow-card: 0 4px 24px rgba(25, 119, 225, 0.08), 0 1px 4px rgba(0, 0, 0, 0.05);
        }

        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0
        }

        body {
            font-family: 'DM Sans', sans-serif;
            background: #f0f2f7;
            color: var(--ink);
            font-size: 14px;
            line-height: 1.6
        }

        .card {
            border-radius: var(--radius-lg) !important;
            box-shadow: var(--shadow-card) !important;
            border: 1px solid #e8edf5 !important;
            background: var(--surface)
        }

        .card-body {
            padding: 22px !important
        }

        .heading {
            color: var(--brand) !important;
            font-weight: 700 !important
        }

        .btn-inverse-success.btn-rounded,
        a.btn-inverse-success.btn-rounded {
            background: linear-gradient(135deg, var(--brand) 0%, var(--brand-dark) 100%) !important;
            color: #fff !important;
            border: none !important;
            border-radius: 10px !important;
            padding: 10px 22px !important;
            font-size: 13px !important;
            font-weight: 700 !important;
            box-shadow: 0 3px 12px rgba(25, 119, 225, 0.28) !important;
            transition: background .18s, box-shadow .18s !important;
            text-decoration: none !important;
            display: inline-block !important
        }

        .btn-inverse-success.btn-rounded:hover,
        a.btn-inverse-success.btn-rounded:hover {
            background: linear-gradient(135deg, #1561c0 0%, #0f3f8a 100%) !important;
            box-shadow: 0 4px 18px rgba(25, 119, 225, 0.38) !important
        }

        .filter-header-bar {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 12px 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            box-shadow: var(--shadow);
            margin-bottom: 20px;
        }

        .filter-group {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .filter-label {
            font-size: 11px;
            font-weight: 700;
            color: var(--ink-3);
            text-transform: uppercase;
            letter-spacing: .4px;
        }

        .pill-btn {
            font-size: 12px;
            font-weight: 600;
            padding: 5px 14px;
            border-radius: 8px;
            border: 1px solid var(--border);
            color: var(--ink-2);
            background: #f9fafb;
            text-decoration: none;
            transition: all .15s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .pill-btn:hover {
            background: var(--brand-bg);
            color: var(--brand);
            border-color: #bfdbfe;
            text-decoration: none;
        }

        .pill-btn.active {
            background: var(--brand);
            color: #fff;
            border-color: var(--brand);
            font-weight: 700;
        }

        .pill-btn.reset {
            border-color: #fecaca;
            color: var(--red);
            background: var(--red-bg);
        }

        .pill-btn.reset:hover {
            background: var(--red);
            color: #fff;
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
            overflow: hidden
        }

        .birthday-bar marquee {
            flex: 1
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
            margin-left: 6px
        }

        .section {
            padding: 0 28px;
            margin-bottom: 24px
        }

        .section-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: var(--ink-3);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .section-label::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border)
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 14px
        }

        @media(max-width:1200px) {
            .stats-grid {
                grid-template-columns: repeat(3, 1fr)
            }
        }

        @media(max-width:640px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr)
            }
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
            transition: box-shadow .18s, transform .18s;
            position: relative;
            overflow: hidden;
            color: var(--ink)
        }

        .stat-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
            text-decoration: none
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            border-radius: var(--radius-lg) var(--radius-lg) 0 0
        }

        .stat-card.orange::before {
            background: var(--orange)
        }

        .stat-card.green::before {
            background: var(--green)
        }

        .stat-card.red::before {
            background: var(--red)
        }

        .stat-card.blue::before {
            background: var(--accent)
        }

        .stat-card.purple::before {
            background: var(--purple)
        }

        .stat-card.teal::before {
            background: var(--teal)
        }

        .stat-card__icon {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px
        }

        .stat-card.orange .stat-card__icon {
            background: var(--orange-bg);
            color: var(--orange)
        }

        .stat-card.green .stat-card__icon {
            background: var(--green-bg);
            color: var(--green)
        }

        .stat-card.red .stat-card__icon {
            background: var(--red-bg);
            color: var(--red)
        }

        .stat-card.blue .stat-card__icon {
            background: var(--accent-bg);
            color: var(--accent)
        }

        .stat-card.purple .stat-card__icon {
            background: var(--purple-bg);
            color: var(--purple)
        }

        .stat-card.teal .stat-card__icon {
            background: var(--teal-bg);
            color: var(--teal)
        }

        .stat-card__val {
            font-size: 26px;
            font-weight: 700;
            color: var(--ink);
            line-height: 1
        }

        .stat-card__lbl {
            font-size: 11px;
            color: var(--ink-3);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .3px
        }

        .two-col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px
        }

        @media(max-width:900px) {
            .two-col {
                grid-template-columns: 1fr
            }
        }

        .tools-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 12px
        }

        @media(max-width:1100px) {
            .tools-grid {
                grid-template-columns: repeat(3, 1fr)
            }
        }

        @media(max-width:640px) {
            .tools-grid {
                grid-template-columns: repeat(2, 1fr)
            }
        }

        .tool-card {
            display: flex;
            flex-direction: column;
            gap: 8px;
            padding: 14px 16px;
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            text-decoration: none;
            color: var(--ink-2);
            font-size: 13px;
            font-weight: 600;
            transition: background .13s, border-color .13s, box-shadow .13s
        }

        .tool-card:hover {
            background: var(--accent-bg);
            border-color: #bfdbfe;
            color: var(--accent);
            box-shadow: var(--shadow-md);
            text-decoration: none
        }

        .tool-card__icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px
        }

        .tool-card__desc {
            font-size: 11px;
            font-weight: 400;
            color: var(--ink-3);
            margin-top: 2px
        }

        .proj-table {
            width: 100%;
            border-collapse: collapse
        }

        .proj-table th {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--ink-3);
            border-bottom: 1px solid var(--border);
            padding: 8px 12px;
            text-align: left;
            background: var(--surface-2)
        }

        .proj-table td {
            padding: 10px 12px;
            border-bottom: 1px solid var(--border);
            font-size: 13px;
            color: var(--ink-2);
            vertical-align: middle
        }

        .proj-table tr:hover td {
            background: var(--surface-2)
        }

        .priority-pill {
            display: inline-block;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 99px;
            text-transform: uppercase
        }

        .p1 {
            background: var(--red-bg);
            color: var(--red)
        }

        .p2 {
            background: var(--orange-bg);
            color: var(--orange)
        }

        .p3 {
            background: var(--amber-bg);
            color: var(--amber)
        }

        .p4 {
            background: var(--green-bg);
            color: var(--green)
        }

        .team-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: var(--accent-bg);
            color: var(--accent);
            font-size: 11px;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 99px
        }

        .deadline-warn {
            color: var(--red);
            font-weight: 700
        }

        .empty-state {
            text-align: center;
            padding: 32px 20px;
            color: var(--ink-3);
            font-size: 13px
        }

        .empty-state i {
            font-size: 2rem;
            color: var(--green);
            display: block;
            margin-bottom: 8px
        }

        .card__header {
            padding: 16px 20px 12px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #fff;
            border-top-left-radius: var(--radius-lg);
            border-top-right-radius: var(--radius-lg)
        }

        .card__title {
            font-size: 14px;
            font-weight: 700;
            color: var(--ink);
            line-height: 1.3
        }

        .card__subtitle {
            font-size: 12px;
            color: var(--ink-3);
            margin-top: 2px
        }

        .card__body {
            padding: 16px 20px
        }

        .badge-count {
            font-size: 10px;
            font-weight: 700;
            background: var(--red);
            color: #fff;
            padding: 2px 7px;
            border-radius: 99px
        }

        .badge-count.orange {
            background: var(--orange)
        }

        .view-link {
            font-size: 12px;
            color: var(--accent);
            font-weight: 600;
            text-decoration: none
        }

        .view-link:hover {
            color: var(--accent-2);
            text-decoration: underline
        }

        .req-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 11px 14px;
            border-radius: var(--radius);
            border: 1px solid var(--border);
            background: var(--surface-2);
            margin-bottom: 8px;
            gap: 12px
        }

        .req-row:hover {
            background: var(--accent-bg);
            border-color: #bfdbfe
        }

        .req-row__avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: var(--accent-bg);
            color: var(--accent);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 700;
            flex-shrink: 0
        }

        .req-row__name {
            font-size: 13px;
            font-weight: 600;
            color: var(--ink)
        }

        .req-row__meta {
            font-size: 11px;
            color: var(--ink-3);
            margin-top: 2px
        }

        .req-row__actions {
            display: flex;
            gap: 6px;
            flex-shrink: 0
        }

        .btn-approve {
            background: var(--green-bg);
            color: var(--green);
            border: 1px solid #a7f3d0;
            border-radius: 7px;
            padding: 5px 13px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer
        }

        .btn-approve:hover {
            background: var(--green);
            color: #fff
        }

        .btn-reject {
            background: var(--red-bg);
            color: var(--red);
            border: 1px solid #fecaca;
            border-radius: 7px;
            padding: 5px 13px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer
        }

        .btn-reject:hover {
            background: var(--red);
            color: #fff
        }

        /* Punch & Personal Attendance Styles */
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

        .border-block {
            border: 1px solid #e8edf5 !important;
            padding: 10px 14px !important;
            border-radius: 10px !important;
            background: #fafbfd
        }

        .tit {
            font-size: 11px;
            margin-bottom: 0.4rem;
            line-height: 1rem;
            color: #64748b;
            font-weight: 600;
            letter-spacing: 0.3px;
            text-transform: uppercase
        }

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
            letter-spacing: 0.5px;
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

        .alert-warning {
            color: #92400e;
            background: #fffbeb;
            border: 1.5px solid #fcd34d;
            border-radius: 10px;
            font-size: 13px;
            padding: 10px 14px
        }

        .blink-alert {
            animation: blink 1s infinite;
            font-weight: 600;
            text-align: left
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

        .modalPunch {
            display: none;
            position: fixed;
            z-index: 999;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(15, 23, 42, 0.65);
            justify-content: center;
            align-items: center
        }

        .modal-content-punch {
            background: #fff;
            padding: 24px;
            border-radius: 16px;
            text-align: center;
            width: 360px;
            box-shadow: 0 8px 32px rgba(25, 119, 225, 0.15)
        }

        .modal-content-punch h4 {
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 14px
        }

        .punch-time-block {
            display: flex;
            flex-direction: column;
            gap: 2px;
            margin-bottom: 10px
        }

        .punch-time-block .pt-label {
            font-size: 11px;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px
        }

        .punch-time-block .pt-value {
            font-size: 13px;
            font-weight: 700;
            color: var(--ink)
        }

        .punch-time-block .pt-value.dash {
            color: var(--ink-4);
            font-weight: 400
        }

        .punch-time-block .pt-late-tag {
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

        .stat-mini-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: 10px
        }

        .stat-mini {
            border-radius: 10px;
            padding: 10px 12px;
            display: flex;
            flex-direction: column;
            gap: 3px
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
            font-size: 22px;
            font-weight: 700;
            line-height: 1
        }

        .stat-mini__lbl {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.4px
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

        .po-early-banner {
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 8px;
            padding: 6px 12px;
            font-size: 11px;
            color: #92400e;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px
        }

        /* Modal Noti */
        .modal-noti-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.4);
            z-index: 1050;
            align-items: center;
            justify-content: center
        }

        .modal-noti-overlay.open {
            display: flex
        }

        .modal-noti-box {
            background: var(--surface);
            border-radius: var(--radius-lg);
            padding: 28px;
            max-width: 360px;
            width: 90%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
            text-align: center;
            position: relative
        }

        .close-x {
            position: absolute;
            top: 12px;
            right: 14px;
            background: none;
            border: none;
            font-size: 18px;
            color: var(--ink-3);
            cursor: pointer
        }

        .btn-primary-sm {
            display: inline-block;
            background: var(--accent);
            color: #fff;
            font-size: 13px;
            font-weight: 600;
            padding: 8px 22px;
            border-radius: 8px;
            text-decoration: none;
            border: none;
            cursor: pointer;
            margin-right: 8px
        }

        .btn-ghost-sm {
            display: inline-block;
            background: transparent;
            color: var(--ink-3);
            font-size: 13px;
            font-weight: 500;
            padding: 8px 18px;
            border-radius: 8px;
            border: 1px solid var(--border-2);
            cursor: pointer
        }
    </style>
</head>

<body>

    <div class="container-scroller">
        <?php include('../partials/_navbar.php'); ?>
        <div class="container-fluid page-body-wrapper">
            <?php include('../partials/_sidebar.php'); ?>

            <div class="main-panel">
                <div class="ra-wrap" style="padding-top:16px;">

                    <!-- Birthday Banner -->
                    <?php if (!empty($todayBirthdays)): ?>
                        <div class="birthday-bar mb-3">
                            <span style="font-size:15px;flex-shrink:0;">🎂</span>
                            <marquee behavior="scroll" direction="left" scrollamount="4" onmouseover="this.stop();"
                                onmouseout="this.start();">
                                <strong>Today's Birthdays:</strong>
                                <?php foreach ($todayBirthdays as $b):
                                    $fn = $b['fullName'];
                                    $first = explode(' ', $fn)[0];
                                    $wa = 'https://wa.me/?text=' . urlencode("Happy Birthday $first 🎉");
                                    ?>
                                    <span style="margin:0 32px;">🎉 <?= htmlspecialchars($fn) ?> <a class="wish-chip"
                                            href="<?= $wa ?>" target="_blank">Wish</a></span>
                                <?php endforeach; ?>
                            </marquee>
                        </div>
                    <?php endif; ?>

                    <!-- ══════════════════════════════════════════════════
                     TOP DUAL FILTER BAR (TYPE & BRANCH)
                     ══════════════════════════════════════════════════ -->
                    <div class="section mb-2">
                        <div class="filter-header-bar">
                            <!-- Intern Type Filter -->
                            <div class="filter-group">
                                <span class="filter-label"><i class="bi bi-people-fill mr-1"></i>Resource Type:</span>
                                <a href="SET_SESSION.php?type=All&back=resourceHeadDashboard.php"
                                    class="pill-btn <?= (empty($intType) || $intType === 'Reset' || $intType === 'All') ? 'active' : '' ?>">All
                                    Types</a>
                                <a href="SET_SESSION.php?type=Online&back=resourceHeadDashboard.php"
                                    class="pill-btn <?= ($intType === 'Online') ? 'active' : '' ?>"><i
                                        class="bi bi-wifi"></i> Online</a>
                                <a href="SET_SESSION.php?type=Offline&back=resourceHeadDashboard.php"
                                    class="pill-btn <?= ($intType === 'Offline') ? 'active' : '' ?>"><i
                                        class="bi bi-building"></i> Offline</a>
                            </div>

                            <!-- Branch Filter -->
                            <div class="filter-group">
                                <span class="filter-label"><i class="bi bi-geo-alt-fill mr-1"></i>Branch:</span>
                                <a href="SET_SESSION.php?branch=All&back=resourceHeadDashboard.php"
                                    class="pill-btn <?= (empty($intBranch) || $intBranch === 'Reset' || $intBranch === 'All') ? 'active' : '' ?>">All
                                    Branches</a>
                                <?php foreach ($availableBranches as $bName): ?>
                                    <a href="SET_SESSION.php?branch=<?= urlencode($bName) ?>&back=resourceHeadDashboard.php"
                                        class="pill-btn <?= ($intBranch === $bName) ? 'active' : '' ?>"><?= htmlspecialchars($bName) ?></a>
                                <?php endforeach; ?>
                                <?php if (!empty($intType) || !empty($intBranch)): ?>
                                    <a href="SET_SESSION.php?type=Reset&branch=Reset&back=resourceHeadDashboard.php"
                                        class="pill-btn reset" title="Clear all filters"><i class="bi bi-x-circle"></i>
                                        Reset</a>
                                <?php endif; ?>
                            </div>

                            <!-- Date Badge -->
                            <div class="date-pill ml-auto" style="font-size:12px;font-weight:600;color:var(--ink-2);">
                                <i class="bi bi-calendar3 mr-1 text-primary"></i><?= date('d M Y') ?>
                            </div>
                        </div>
                    </div>

                    <!-- ══════════════════════════════════════════════════
                     RESOURCE INTELLIGENCE KPI STAT CARDS
                     ══════════════════════════════════════════════════ -->
                    <div class="section">
                        <div class="section-label">Resource Pool & Capacity Overview</div>
                        <div class="stats-grid">
                            <a href="active-interns.php" class="stat-card teal">
                                <div class="stat-card__icon"><i class="bi bi-people-fill"></i></div>
                                <div class="stat-card__val"><?= $totalEmp ?></div>
                                <div class="stat-card__lbl">Total Resources</div>
                            </a>
                            <a href="projectAllocation.php" class="stat-card purple">
                                <div class="stat-card__icon"><i class="bi bi-person-check-fill"></i></div>
                                <div class="stat-card__val"><?= $allocated ?></div>
                                <div class="stat-card__lbl">Allocated Members</div>
                            </a>
                            <a href="freeInternPool.php" class="stat-card blue">
                                <div class="stat-card__icon"><i class="bi bi-person-workspace"></i></div>
                                <div class="stat-card__val"><?= $benchCount ?> <small
                                        style="font-size:12px;color:var(--brand);">(<?= $benchRate ?>%)</small></div>
                                <div class="stat-card__lbl">Bench / Free Pool</div>
                            </a>
                            <a href="resourceApprovals.php?filter=pending" class="stat-card orange">
                                <div class="stat-card__icon"><i class="bi bi-hourglass-split"></i></div>
                                <div class="stat-card__val"><?= $pendingReq ?></div>
                                <div class="stat-card__lbl">Pending Requests</div>
                            </a>
                            <a href="projectMaster.php" class="stat-card green">
                                <div class="stat-card__icon"><i class="bi bi-diagram-3-fill"></i></div>
                                <div class="stat-card__val"><?= $activeProj ?></div>
                                <div class="stat-card__lbl">Active Projects</div>
                            </a>
                            <a href="allocationVarianceReport.php" class="stat-card red">
                                <div class="stat-card__icon"><i class="bi bi-exclamation-triangle-fill"></i></div>
                                <div class="stat-card__val"><?= $overloadedCount ?></div>
                                <div class="stat-card__lbl">Multi-Allocated Risk</div>
                            </a>
                        </div>
                    </div>

                    <!-- ══════════════════════════════════════════════════
                     PERSONAL EMPLOYEE OPERATIONS (TIMESHEET & ATTENDANCE)
                     ══════════════════════════════════════════════════ -->
                    <div class="section">
                        <div class="section-label">My Employee Dashboard & Attendance</div>
                        <div class="two-col">

                            <!-- TIMESHEET & PUNCH IN/OUT -->
                            <div class="card">
                                <div class="card-body">
                                    <div
                                        style="height:3px;background:linear-gradient(135deg,#1977e1 0%,#1254b0 100%);border-radius:99px;margin-bottom:14px;">
                                    </div>
                                    <h4 class="card-title heading">Timesheet
                                        <span style="font-size:10px;color:#64748b;font-weight:400;">of
                                            <?= date('d M Y', strtotime($today)) ?></span>
                                    </h4>

                                    <div class="row align-items-center mt-3">
                                        <div class="col-6">
                                            <div class="punch-time-block">
                                                <div class="pt-label">Punch In <small
                                                        style="color:#64748b;font-weight:normal;">(Expected:
                                                        <?= $expInDisp ?>)</small></div>
                                                <?php if (!empty($dispPunchIn) && $dispPunchIn !== '00:00:00'): ?>
                                                    <div class="pt-value">
                                                        <?= date('h:i A', strtotime($dispPunchIn)) ?>    <?php if ($dispIsLate): ?><span
                                                                class="pt-late-tag"><?= $dispIsAbsentLate ? 'Late (Absent)' : 'Late' ?></span><?php endif; ?>
                                                    </div>
                                                    <div style="font-size:10px;color:#64748b;">
                                                        <?= date('s', strtotime($dispPunchIn)) ?>s</div>
                                                <?php else: ?>
                                                    <div class="pt-value dash">—</div><?php endif; ?>
                                            </div>
                                            <div class="punch-time-block">
                                                <div class="pt-label">Punch Out <small
                                                        style="color:#64748b;font-weight:normal;">(Expected:
                                                        <?= $expOutDisp ?>)</small></div>
                                                <?php if (!empty($dispPunchOut) && $dispPunchOut !== '00:00:00'): ?>
                                                    <div class="pt-value"><?= date('h:i A', strtotime($dispPunchOut)) ?>
                                                    </div>
                                                    <div style="font-size:10px;color:#64748b;">
                                                        <?= date('s', strtotime($dispPunchOut)) ?>s</div>
                                                <?php else: ?>
                                                    <div class="pt-value dash">—</div><?php endif; ?>
                                            </div>
                                        </div>

                                        <div class="col-6" style="text-align:end;">
                                            <?php
                                            $stmt = $con->prepare("SELECT COUNT(*) as cnt FROM tour WHERE userId=? AND ? BETWEEN DATE(fromDate) AND DATE(toDate) AND status IN ('Approved','Pending')");
                                            $stmt->bind_param("ss", $raId, $today);
                                            $stmt->execute();
                                            $onTour2 = (int) $stmt->get_result()->fetch_assoc()['cnt'] > 0;
                                            $stmt->close();

                                            if ($onTour2):
                                                $stmt = $con->prepare("SELECT punchInTime, punchOutTime, manHours FROM tourAttendance WHERE userId=? AND date=? ORDER BY id DESC LIMIT 1");
                                                $stmt->bind_param("ss", $raId, $today);
                                                $stmt->execute();
                                                $tourAttResult = $stmt->get_result();
                                                $tourAttRow2 = ($tourAttResult->num_rows > 0) ? $tourAttResult->fetch_assoc() : [];
                                                $stmt->close();
                                                $tourPunchInTime = $tourAttRow2['punchInTime'] ?? '';
                                                $tourPunchOutTime = $tourAttRow2['punchOutTime'] ?? '';
                                                $tourManHours = $tourAttRow2['manHours'] ?? '00:00:00';
                                                $tourHasPunchIn = !empty($tourPunchInTime) && $tourPunchInTime !== '00:00:00';
                                                $tourHasPunchOut = !empty($tourPunchOutTime) && $tourPunchOutTime !== '00:00:00';
                                                $tourHasManHours = !empty($tourManHours) && $tourManHours !== '00:00:00';
                                                $tourNotPunchedYet = !$tourHasPunchIn;
                                                $tourIsPunchedIn = $tourHasPunchIn && !$tourHasPunchOut && !$tourHasManHours;

                                                if ($tourNotPunchedYet || $tourHasManHours):
                                                    ?>
                                                    <a href="captureSelfie.php?punch=in&tour=1" class="btn p-2 btn-inverse-success btn-rounded btn-fw">Tour Punch In</a>
                                                    <?php
                                                elseif ($tourIsPunchedIn):
                                                    ?>
                                                    <a href="captureSelfie.php?punch=out&tour=1" class="btn p-2 btn-inverse-success btn-rounded btn-fw">Tour Punch Out</a>
                                                    <?php
                                                endif;

                                            else:
                                                if ($notPunchedYet || ($hasPunchIn && $hasPunchOut)):
                                                    if (date('H:i:s', strtotime($expectedPunchIn2 . ' +30 minutes')) >= date('H:i:s')):
                                                        ?>
                                                        <a href="<?= $punchInUrl ?>"
                                                            class="btn p-2 btn-inverse-success btn-rounded btn-fw">Punch In</a>
                                                        <?php
                                                    else:
                                                        $stmt = $con->prepare("SELECT COUNT(*) as cnt FROM leaves WHERE userId=? AND CURDATE() BETWEEN from_date AND to_date AND status='Approved'");
                                                        $stmt->bind_param("s", $raId);
                                                        $stmt->execute();
                                                        $leaveCount0 = (int) $stmt->get_result()->fetch_assoc()['cnt'];
                                                        $stmt->close();
                                                        if ($attendanceCount > 0 || $leaveCount0 > 0):
                                                            ?>
                                                            <a href="<?= $punchInUrl ?>"
                                                                class="btn p-2 btn-inverse-success btn-rounded btn-fw">Punch In</a>
                                                            <?php
                                                        else:
                                                            $stmt = $con->prepare("SELECT COUNT(*) as cnt FROM leaves WHERE userId=? AND from_date=? AND to_date=?");
                                                            $stmt->bind_param("sss", $raId, $today, $today);
                                                            $stmt->execute();
                                                            $checkLeaveCount = (int) $stmt->get_result()->fetch_assoc()['cnt'];
                                                            $stmt->close();
                                                            if ($checkLeaveCount == 0) {
                                                                $leave_id = uniqid('LID_');
                                                                $leave_d = 'L-' . strtoupper(substr($raId, -4)) . '-' . date('dmY');
                                                                $stmt = $con->prepare('INSERT INTO leaves (created_date,leave_id,leave_d,leave_name,leave_type,leave_day,from_date,to_date,reason,approved_by,informed_colleagues,userId,status,updated_date) VALUES (NOW(),?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
                                                                $leave_name = 'Auto Leave - Missing Punch In';
                                                                $leave_type = 'Auto';
                                                                $leave_day = 'Full Day';
                                                                $reason_auto = 'System marked auto leave due to missing punch-in.';
                                                                $approved_by = '';
                                                                $informed_colleagues = '';
                                                                $status_auto = 'Pending';
                                                                $stmt->bind_param('ssssssssssss', $leave_id, $leave_d, $leave_name, $leave_type, $leave_day, $today, $today, $reason_auto, $approved_by, $informed_colleagues, $raId, $status_auto);
                                                                $stmt->execute();
                                                                $stmt->close();
                                                            }
                                                            ?>
                                                            <div class="alert alert-warning mt-2 blink-alert">You haven't punched in on
                                                                time, so marked as absent.</div>
                                                            <?php
                                                        endif;
                                                    endif;

                                                elseif ($isPunchedIn):
                                                    $stmt = $con->prepare("SELECT COUNT(*) as cnt FROM TaskEntryNew WHERE userId=? AND status='Running'");
                                                    $stmt->bind_param("s", $raId);
                                                    $stmt->execute();
                                                    $hasRunning = (int) $stmt->get_result()->fetch_assoc()['cnt'] > 0;
                                                    $stmt->close();

                                                    if ($hasRunning):
                                                        ?>
                                                        <div class="alert alert-warning mt-2 blink-alert">You have a running task.
                                                            Please pause or finish it before Punch Out.</div>
                                                        <?php
                                                    else:
                                                        if ($_hasHrEarlyGo && $_earlyGoTs < $_expectedOutTs):
                                                            ?>
                                                            <div class="po-early-banner">
                                                                <i class="bi bi-person-check-fill"></i>
                                                                HR approved early go at
                                                                <strong><?= date('h:i A', $_earlyGoTs) ?></strong>
                                                            </div>
                                                        <?php
                                                        endif;

                                                        if ($_poBtnUnlocked):
                                                            ?>
                                                            <a href="<?= $punchOutUrl ?>" class="btn p-2 btn-inverse-success btn-rounded btn-fw">Punch Out</a>
                                                        <?php else: ?>
                                                            <button class="btn p-2 btn-secondary btn-rounded btn-fw" disabled style="opacity:.55;cursor:not-allowed;" title="Punch Out unlocks at <?= $_unlockDisp ?>">
                                                                <i class="fas fa-lock mr-1" style="font-size:11px;"></i> Locked Till <?= $_unlockDisp ?>
                                                            </button>
                                                            <div style="font-size:11px;color:#6b7280;margin-top:5px;">
                                                                Unlocks at <strong><?= $_unlockDisp ?></strong> — <span id="countdownTimer"></span>
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
                                                                                var h = Math.floor(diff / 3600), m = Math.floor((diff % 3600) / 60), s = diff % 60;
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
                                                        <?php
                                                    endif;
                                                endif;
                                            endif;
                                            ?>
                                        </div>
                                    </div>

                                    <!-- Today progress -->
                                    <div class="mt-3 border-block">
                                        <p class="mb-0 tit">Today's Work Progress</p>
                                        <div class="progress-wrap">
                                            <div class="prog-tooltip">Worked: <?= $displayH ?>h
                                                <?= str_pad($displayM, 2, '0', STR_PAD_LEFT) ?>m / 8h &nbsp;|&nbsp;
                                                <?= round($perc_today) ?>% done</div>
                                            <div class="progress progress-md flex-grow-1">
                                                <div class="progress-bar bg-success"
                                                    style="width:<?= max(3, round($perc_today, 2)) ?>%"></div>
                                            </div>
                                            <p class="mb-0" style="font-size:11px;white-space:nowrap;"><?= $displayH ?>h
                                                <?= str_pad($displayM, 2, '0', STR_PAD_LEFT) ?>m / <span
                                                    style="font-size:10px;color:#83878b;">8hr</span></p>
                                        </div>
                                    </div>

                                    <!-- Lunch + Overtime -->
                                    <div class="lunch-summary">
                                        <div class="lunch-item">
                                            <div class="lunch-title">LUNCH BREAK</div>
                                            <div class="lunch-value">
                                                <?php if ($lunchStatus === 'not_started'): ?>
                                                    <button onclick="updateLunch('start')"
                                                        class="btn btn-outline-success btn-xs ms-1"
                                                        style="font-size:12px;padding:5px 15px;border-color:#1977e1;color:#1977e1;border-radius:8px;font-weight:600;">Start</button>
                                                <?php elseif ($lunchStatus === 'started'): ?>
                                                    <button onclick="updateLunch('end')"
                                                        class="btn btn-outline-danger btn-xs ms-1"
                                                        style="font-size:12px;padding:5px 15px;border-radius:8px;font-weight:600;">End</button>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($lunchStatus === 'completed'): ?>
                                                <p style="color:#22c55e;font-size:11px;font-weight:600;margin:0;">
                                                    <?= date('h:i A', strtotime($lunchStart)) ?> –
                                                    <?= date('h:i A', strtotime($lunchEnd)) ?> (<?= $lunchDuration ?> min)
                                                </p>
                                            <?php endif; ?>
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

                            <!-- MONTHLY ATTENDANCE -->
                            <div class="card">
                                <div class="card-body">
                                    <div
                                        style="height:3px;background:linear-gradient(135deg,#1977e1 0%,#1254b0 100%);border-radius:99px;margin-bottom:14px;">
                                    </div>
                                    <h4 class="card-title heading">Monthly Attendance</h4>
                                    <p style="font-size:11px;color:#64748b;margin-bottom:16px;">Your attendance summary
                                        for <?= date('F Y') ?></p>
                                    <div class="stat-mini-grid">
                                        <div class="stat-mini green">
                                            <div class="stat-mini__val"><?= $presentDays ?></div>
                                            <div class="stat-mini__lbl">Present Days</div>
                                        </div>
                                        <div class="stat-mini red">
                                            <div class="stat-mini__val"><?= $absentDays ?></div>
                                            <div class="stat-mini__lbl">Absent Days</div>
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
                                    <div style="margin-top:14px;text-align:right;">
                                        <a href="myAttendance.php"
                                            style="font-size:11px;color:var(--brand);font-weight:600;text-decoration:none;">View
                                            Full Calendar →</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ══════════════════════════════════════════════════
                     4 INTERACTIVE ANALYTICAL CHARTS
                     ══════════════════════════════════════════════════ -->
                    <div class="section">
                        <div class="section-label">Resource Allocation & Capacity Analytics</div>
                        <div class="row" style="row-gap:16px;">
                            <!-- Chart 1: Resource Allocation Breakdown -->
                            <div class="col-md-6 col-lg-3">
                                <div class="card h-100">
                                    <div class="card-body" style="padding:16px!important;">
                                        <div
                                            style="font-size:13px;font-weight:700;color:var(--brand);margin-bottom:10px;">
                                            Resource Load Breakdown</div>
                                        <div style="position:relative;height:180px;">
                                            <canvas id="chartResourceAllocation"></canvas>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Chart 2: Branch Distribution -->
                            <div class="col-md-6 col-lg-3">
                                <div class="card h-100">
                                    <div class="card-body" style="padding:16px!important;">
                                        <div
                                            style="font-size:13px;font-weight:700;color:var(--brand);margin-bottom:10px;">
                                            Resources by Branch</div>
                                        <div style="position:relative;height:180px;">
                                            <canvas id="chartBranchDistribution"></canvas>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Chart 3: Top Projects Demand -->
                            <div class="col-md-6 col-lg-3">
                                <div class="card h-100">
                                    <div class="card-body" style="padding:16px!important;">
                                        <div
                                            style="font-size:13px;font-weight:700;color:var(--brand);margin-bottom:10px;">
                                            Project Team Allocation</div>
                                        <div style="position:relative;height:180px;">
                                            <canvas id="chartProjectDemand"></canvas>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Chart 4: Request Approval Funnel -->
                            <div class="col-md-6 col-lg-3">
                                <div class="card h-100">
                                    <div class="card-body" style="padding:16px!important;">
                                        <div
                                            style="font-size:13px;font-weight:700;color:var(--brand);margin-bottom:10px;">
                                            Resource Request Funnel</div>
                                        <div style="position:relative;height:180px;">
                                            <canvas id="chartRequestFunnel"></canvas>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ══════════════════════════════════════════════════
                     PENDING REQUESTS & ACTIVE PROJECTS
                     ══════════════════════════════════════════════════ -->
                    <div class="section">
                        <div class="two-col">

                            <!-- PENDING RESOURCE REQUESTS -->
                            <div class="card">
                                <div class="card__header">
                                    <div>
                                        <div class="card__title">Pending Resource Requests
                                            <?php if ($pendingReq > 0)
                                                echo '<span class="badge-count orange" style="margin-left:6px;">' . $pendingReq . '</span>'; ?></div>
                                        <div class="card__subtitle">Review and action incoming requests</div>
                                    </div>
                                    <a href="resourceApprovals.php" class="view-link">View all →</a>
                                </div>
                                <div class="card__body">
                                    <?php if ($resListCnt > 0):
                                        while ($req = $resListResult->fetch_assoc()):
                                            $reqId = $req['id'] ?? $req['requestId'] ?? 0;
                                            $reqDesc = htmlspecialchars($req['description'] ?? $req['request_details'] ?? 'Resource Request');
                                            $projName = htmlspecialchars($req['projectName'] ?? 'N/A');
                                            $reqDate = !empty($req['createdAt']) ? date('d M Y', strtotime($req['createdAt'])) : '-';
                                            $name = htmlspecialchars($req['fullName'] ?? 'Unknown');
                                            $initials = strtoupper(substr($req['fullName'] ?? 'U', 0, 1));
                                            ?>
                                            <div class="req-row">
                                                <div class="req-row__avatar"><?= $initials ?></div>
                                                <div style="flex:1;min-width:0;">
                                                    <div class="req-row__name"><?= $name ?></div>
                                                    <div class="req-row__meta"><?= $reqDesc ?> &nbsp;·&nbsp; <?= $projName ?>
                                                        &nbsp;·&nbsp; <?= $reqDate ?></div>
                                                </div>
                                                <div class="req-row__actions">
                                                    <form method="POST" action="resourceApprovals.php" style="display:inline;">
                                                        <input type="hidden" name="action" value="approve">
                                                        <input type="hidden" name="requestId" value="<?= $reqId ?>">
                                                        <button type="submit" class="btn-approve">✓ Approve</button>
                                                    </form>
                                                    <form method="POST" action="resourceApprovals.php" style="display:inline;">
                                                        <input type="hidden" name="action" value="reject">
                                                        <input type="hidden" name="requestId" value="<?= $reqId ?>">
                                                        <button type="submit" class="btn-reject">✗ Reject</button>
                                                    </form>
                                                </div>
                                            </div>
                                        <?php endwhile;
                                    else: ?>
                                        <div class="empty-state"><i class="bi bi-check-circle-fill"></i>No pending resource
                                            requests</div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- ACTIVE PROJECTS & ALLOCATION -->
                            <div class="card">
                                <div class="card__header">
                                    <div>
                                        <div class="card__title">Active Projects &amp; Resource Allocation</div>
                                        <div class="card__subtitle">Sorted by priority and deadline</div>
                                    </div>
                                    <a href="projectAllocation.php" class="view-link">Manage →</a>
                                </div>
                                <div style="overflow-x:auto;max-height:280px;overflow-y:auto;">
                                    <table class="proj-table">
                                        <thead>
                                            <tr>
                                                <th>Project</th>
                                                <th>Deadline</th>
                                                <th>Priority</th>
                                                <th>Team</th>
                                                <th style="min-width:140px;">Progress</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if ($projAllocResult && $projAllocResult->num_rows > 0):
                                                while ($row = $projAllocResult->fetch_assoc()):
                                                    $daysLeft = (strtotime($row['deadLine']) - strtotime($today)) / 86400;
                                                    $dlClass = $daysLeft <= 7 ? 'deadline-warn' : '';
                                                    $prKey = strtolower($row['priority']);
                                                    $progress = (int) $row['progress'];
                                                    $barColor = $progress >= 75 ? 'var(--green)' : ($progress >= 50 ? 'var(--amber)' : 'var(--red)');
                                                    $warnLabel = $daysLeft <= 7 ? ' <span style="font-size:10px;">(⚠ ' . (int) $daysLeft . 'd)</span>' : '';
                                                    ?>
                                                    <tr>
                                                        <td style="font-weight:600;color:var(--ink);">
                                                            <?= htmlspecialchars($row['projectName']) ?></td>
                                                        <td class="<?= $dlClass ?>">
                                                            <?= date('d M y', strtotime($row['deadLine'])) ?>        <?= $warnLabel ?>
                                                        </td>
                                                        <td><span
                                                                class="priority-pill <?= $prKey ?>"><?= strtoupper($row['priority']) ?></span>
                                                        </td>
                                                        <td><span class="team-pill"><i class="bi bi-people"></i>
                                                                <?= (int) $row['teamSize'] ?></span></td>
                                                        <td>
                                                            <div style="display:flex;align-items:center;gap:8px;">
                                                                <div
                                                                    style="flex:1;height:5px;background:var(--surface-3);border-radius:99px;overflow:hidden;">
                                                                    <div
                                                                        style="height:100%;width:<?= $progress ?>%;background:<?= $barColor ?>;border-radius:99px;">
                                                                    </div>
                                                                </div>
                                                                <span
                                                                    style="font-size:11px;color:var(--ink-3);width:28px;text-align:right;"><?= $progress ?>%</span>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endwhile;
                                            else: ?>
                                                <tr>
                                                    <td colspan="5" class="empty-state" style="padding:24px;">No active
                                                        projects found</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                        </div>
                    </div>

                    <!-- ══════════════════════════════════════════════════
                     RESOURCE HEAD ANALYTICS & MANAGEMENT TOOLS
                     ══════════════════════════════════════════════════ -->
                    <div class="section">
                        <div class="section-label">Resource Management & Deep Analytics Tools</div>
                        <div class="card">
                            <div class="card__body">
                                <div class="tools-grid">
                                    <?php
                                    $tools = [
                                        ['href' => 'resourceApprovals.php', 'icon' => 'bi bi-people-fill', 'bg' => 'var(--orange-bg)', 'ic' => 'var(--orange)', 'title' => 'Resource Approvals', 'sub' => 'Review & approve requests'],
                                        ['href' => 'projectAllocation.php', 'icon' => 'bi bi-diagram-3-fill', 'bg' => 'var(--accent-bg)', 'ic' => 'var(--accent)', 'title' => 'Project Allocation', 'sub' => 'Assign team members'],
                                        ['href' => 'freeInternPool.php', 'icon' => 'bi bi-person-workspace', 'bg' => 'var(--green-bg)', 'ic' => 'var(--green)', 'title' => 'Bench / Free Pool', 'sub' => 'View available resources'],
                                        ['href' => 'allocationVarianceReport.php', 'icon' => 'bi bi-sliders', 'bg' => 'var(--purple-bg)', 'ic' => 'var(--purple)', 'title' => 'Variance Report', 'sub' => 'Allocation vs Logged'],
                                        ['href' => 'forwardCapacityReport.php', 'icon' => 'bi bi-calendar-check', 'bg' => 'var(--teal-bg)', 'ic' => 'var(--teal)', 'title' => 'Forward Capacity', 'sub' => 'Resource forecast'],
                                        ['href' => 'projectUtilizationReport.php', 'icon' => 'bi bi-bar-chart-fill', 'bg' => 'var(--orange-bg)', 'ic' => 'var(--orange)', 'title' => 'Utilization Report', 'sub' => 'Project hours & metrics'],
                                        ['href' => 'projectCostEfficiencyReport.php', 'icon' => 'bi bi-piggy-bank', 'bg' => 'var(--accent-bg)', 'ic' => 'var(--accent)', 'title' => 'Cost Efficiency', 'sub' => 'Budget & efficiency'],
                                        ['href' => 'projectSwitchingReport.php', 'icon' => 'bi bi-arrow-repeat', 'bg' => 'var(--purple-bg)', 'ic' => 'var(--purple)', 'title' => 'Project Switching', 'sub' => 'Track switching rate'],
                                        ['href' => 'weeklyPlanOverview.php', 'icon' => 'bi bi-calendar-week-fill', 'bg' => 'var(--green-bg)', 'ic' => 'var(--green)', 'title' => 'Weekly Plan', 'sub' => 'Overview & planning'],
                                        ['href' => 'projectMaster.php', 'icon' => 'bi bi-layers-fill', 'bg' => 'var(--red-bg)', 'ic' => 'var(--red)', 'title' => 'Project Master', 'sub' => 'Manage all projects'],
                                        ['href' => 'createMeeting.php', 'icon' => 'bi bi-camera-video-fill', 'bg' => 'var(--teal-bg)', 'ic' => 'var(--teal)', 'title' => 'Schedule Meeting', 'sub' => 'Create a new meeting'],
                                        ['href' => 'meetingHistory.php', 'icon' => 'bi bi-clock-history', 'bg' => 'var(--accent-bg)', 'ic' => 'var(--accent)', 'title' => 'Meeting History', 'sub' => 'Past & upcoming logs'],
                                    ];
                                    foreach ($tools as $t):
                                        ?>
                                        <a href="<?= $t['href'] ?>" class="tool-card">
                                            <div class="tool-card__icon"
                                                style="background:<?= $t['bg'] ?>;color:<?= $t['ic'] ?>;"><i
                                                    class="<?= $t['icon'] ?>"></i></div>
                                            <div>
                                                <div><?= $t['title'] ?></div>
                                                <div class="tool-card__desc"><?= $t['sub'] ?></div>
                                            </div>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- FREE INTERNS / BENCH POOL WIDGET -->
                    <div class="section">
                        <?php include('../partials/_freeInternsWidget.php'); ?>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <!-- Pending Requests Notification Modal -->
    <div class="modal-noti-overlay" id="pendingModal">
        <div class="modal-noti-box">
            <button class="close-x" onclick="closePendingModal()">&times;</button>
            <h2>Pending Resource Requests</h2>
            <div class="task-info"
                style="background:var(--surface-2);border:1px solid var(--border);border-radius:10px;padding:14px;margin-bottom:18px;">
                <div class="task-count" style="font-size:34px;font-weight:700;color:var(--orange);line-height:1;">
                    <?= $pendingReq ?></div>
                <div class="task-sub" style="font-size:12px;color:var(--ink-3);margin-top:4px;">requests awaiting your
                    review</div>
            </div>
            <a href="resourceApprovals.php" class="btn-primary-sm">Review Now</a>
            <button onclick="closePendingModal()" class="btn-ghost-sm">Dismiss</button>
        </div>
    </div>

    <script src="../vendors/js/vendor.bundle.base.js"></script>
    <script src="../vendors/chart.js/Chart.min.js"></script>
    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>
    <script src="../js/settings.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            <?php if ($showModal && $pendingReq > 0): ?>
                document.getElementById('pendingModal').classList.add('open');
            <?php endif; ?>
            if (window.location.search.length > 0) window.history.replaceState({}, document.title, window.location.pathname);
        });
        function closePendingModal() { document.getElementById('pendingModal').classList.remove('open'); }

        // Tour Punch In
        let punchInStream = null;
        function openPunchModal() {
            const modal = document.getElementById('punchModal'); if (!modal) return;
            modal.style.display = 'flex';
            navigator.mediaDevices.getUserMedia({ video: true }).then(s => { punchInStream = s; document.getElementById('video').srcObject = s; }).catch(err => alert('Camera access denied: ' + err));
            if (navigator.geolocation) { navigator.geolocation.getCurrentPosition(pos => { const lat = document.getElementById('punchInLat'); const lng = document.getElementById('punchInLong'); if (lat) lat.value = pos.coords.latitude; if (lng) lng.value = pos.coords.longitude; }, () => { }); }
        }
        function closePunchModal() {
            const modal = document.getElementById('punchModal'); if (modal) modal.style.display = 'none';
            if (punchInStream) punchInStream.getTracks().forEach(t => t.stop());
            const vid = document.getElementById('video'); if (vid) vid.srcObject = null;
        }
        function capturePunchSelfie() {
            const canvas = document.getElementById('canvas'), video = document.getElementById('video'), input = document.getElementById('selfieInput');
            if (!canvas || !video || !input) return;
            canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
            input.value = canvas.toDataURL('image/png'); closePunchModal(); document.getElementById('punchInForm').submit();
        }

        // Tour Punch Out
        let streamTourOut = null;
        function openTourPunchOutModal() {
            const modal = document.getElementById('tourPunchOutModal'), video = document.getElementById('videoTourOut'); if (!modal || !video) return;
            modal.style.display = 'flex';
            navigator.mediaDevices.getUserMedia({ video: true }).then(s => { streamTourOut = s; video.srcObject = s; }).catch(err => alert('Camera access denied: ' + err));
            if (navigator.geolocation) { navigator.geolocation.getCurrentPosition(pos => { const l = document.getElementById('punchOutLat'); const g = document.getElementById('punchOutLong'); if (l) l.value = pos.coords.latitude; if (g) g.value = pos.coords.longitude; }, () => { }); }
        }
        function closeTourPunchOutModal() {
            const modal = document.getElementById('tourPunchOutModal'), video = document.getElementById('videoTourOut');
            if (modal) modal.style.display = 'none'; if (streamTourOut) streamTourOut.getTracks().forEach(t => t.stop()); if (video) video.srcObject = null;
        }
        function captureTourPunchOut() {
            const video = document.getElementById('videoTourOut'), canvas = document.getElementById('canvasTourOut'), input = document.getElementById('punchOutSelfieData');
            if (!video || !canvas || !input) return;
            canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
            input.value = canvas.toDataURL('image/png'); closeTourPunchOutModal(); document.getElementById('punchOutForm').submit();
        }

        // Lunch Action
        function updateLunch(type) {
            if (type === 'start') {
                const now = new Date();
                if ((now.getHours() * 60 + now.getMinutes()) < (13 * 60)) { Swal.fire({ icon: 'warning', text: 'Your Lunch Break starts at 1PM.', confirmButtonText: 'OK' }); return; }
            }
            fetch('lunch_action.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'type=' + encodeURIComponent(type) })
                .then(res => res.json()).then(data => {
                    if (!data.success) { Swal.fire({ icon: 'error', text: data.msg || 'Lunch action failed' }); return; }
                    Swal.fire({ icon: 'success', text: type === 'start' ? 'Lunch started successfully' : 'Lunch ended successfully', timer: 1500, showConfirmButton: false });
                    setTimeout(() => location.reload(), 1600);
                }).catch(() => Swal.fire({ icon: 'error', text: 'Something went wrong.' }));
        }

        window.addEventListener('load', function () {
            /* ── Chart 1: Resource Allocation Breakdown ── */
            var ctx1 = document.getElementById('chartResourceAllocation');
            if (ctx1) {
                new Chart(ctx1, {
                    type: 'doughnut',
                    data: {
                        labels: ['Allocated', 'Bench / Free Pool', 'Multi-Allocated Risk'],
                        datasets: [{
                            data: [<?= $allocated ?>, <?= $benchCount ?>, <?= $overloadedCount ?>],
                            backgroundColor: ['#7c3aed', '#1977e1', '#dc2626'],
                            borderWidth: 2, borderColor: '#fff'
                        }]
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false, cutoutPercentage: 65,
                        legend: { display: false }
                    }
                });
            }

            /* ── Chart 2: Branch Distribution ── */
            var ctx2 = document.getElementById('chartBranchDistribution');
            if (ctx2) {
                new Chart(ctx2, {
                    type: 'doughnut',
                    data: {
                        labels: <?= json_encode(array_keys($branchStats)) ?>,
                        datasets: [{
                            data: <?= json_encode(array_values($branchStats)) ?>,
                            backgroundColor: ['#1977e1', '#059669', '#d97706', '#7c3aed', '#0891b2'],
                            borderWidth: 2, borderColor: '#fff'
                        }]
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false, cutoutPercentage: 65,
                        legend: { display: false }
                    }
                });
            }

            /* ── Chart 3: Project Demand & Team Size ── */
            var ctx3 = document.getElementById('chartProjectDemand');
            if (ctx3) {
                new Chart(ctx3, {
                    type: 'bar',
                    data: {
                        labels: <?= json_encode($topProjNames) ?>,
                        datasets: [{
                            label: 'Team Size',
                            data: <?= json_encode($topProjTeams) ?>,
                            backgroundColor: '#1977e1',
                            borderRadius: 4
                        }]
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        legend: { display: false },
                        scales: {
                            xAxes: [{ gridLines: { display: false } }],
                            yAxes: [{ ticks: { beginAtZero: true } }]
                        }
                    }
                });
            }

            /* ── Chart 4: Resource Request Funnel ── */
            var ctx4 = document.getElementById('chartRequestFunnel');
            if (ctx4) {
                new Chart(ctx4, {
                    type: 'doughnut',
                    data: {
                        labels: ['Pending', 'Approved', 'Rejected'],
                        datasets: [{
                            data: [<?= $pendingReq ?>, <?= $approvedReq ?>, <?= $rejectedReq ?>],
                            backgroundColor: ['#ea580c', '#059669', '#dc2626'],
                            borderWidth: 2, borderColor: '#fff'
                        }]
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false, cutoutPercentage: 65,
                        legend: { display: false }
                    }
                });
            }
        });
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
    ?>
    <?php include('../include/passwordChangeAlert.php'); ?>
</body>

</html>