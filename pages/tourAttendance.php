<?php
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 1);
session_start();
if (empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
} else {
    include('../include/db_config.php');
}

$userId = $_SESSION['userId'];
$date = date('Y-m-d');
$dateTime = date('Y-m-d H:i:s');
$time = date('H:i:s');

// ── Get user info ──────────────────────────────────────────────
$q0 = mysqli_query($con, "SELECT * FROM signup WHERE userId = '$userId'");
$r0 = mysqli_fetch_assoc($q0);
$designation = $r0['designation'];
$userRole = $_SESSION['userRole'] ?? '';

// ── JSON Response Helper ──────────────────────────────────────
function sendJsonResponse($data, $con = null)
{
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode($data);
    if ($con instanceof mysqli) $con->close();
    exit;
}

$isJson = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) || isset($_POST['isTour']);

// ── Determine redirect-back URL ────────────────────────────────
// Priority: explicit POST 'back' param → role-based fallback
function getRedirectUrl($designation, $userRole)
{
    // Explicit back param (e.g. passed from resourceHeadDashboard)
    if (!empty($_POST['back'])) {
        return basename($_POST['back']); // basename() prevents path traversal
    }

    $punchType = strtolower($_GET['punch'] ?? 'in');

    // Role / designation based fallback
    if ($designation === 'seniorIntern' || $userRole === 'senior_intern') {
        if ($punchType === 'in') {
            return 'taskDiary.php?mode=planned';
        }
        return 'srEmpDashboard.php';
    }
    if ($designation === 'resourceAllocator' || $userRole === 'resourceAllocator') {
        if ($punchType === 'in') {
            return 'taskDiary.php?mode=planned';
        }
        return 'resourceHeadDashboard.php';
    }
    if ($designation === 'receptionist') {
        return 'receptionistDashboard.php';
    }
    // Default: junior intern
    return 'jrEmpDashboard.php';
}

$redirectUrl = getRedirectUrl($designation, $userRole);

// ── Check previous attendance ──────────────────────────────────
$sql = mysqli_query($con, "SELECT * FROM tourAttendance WHERE date='$date' AND userId='$userId' ORDER BY id DESC");
$count = mysqli_num_rows($sql);
$res_c = mysqli_fetch_assoc($sql);

// ── Punch type ────────────────────────────────────────────────
$punchType = strtolower($_POST['punchType'] ?? $_GET['punchType'] ?? '');
$latitude = 0;
$longitude = 0;
$selfiePath = null;

// ── Save base64 selfie ─────────────────────────────────────────
function saveSelfie($base64, $userId, $punchType)
{
    if (empty($base64))
        return null;
    if (!in_array($punchType, ['in', 'out']))
        return null;

    $base64 = preg_replace('#^data:image/\w+;base64,#i', '', $base64);
    $base64 = str_replace(' ', '+', $base64);
    $imageData = base64_decode($base64);
    if ($imageData === false)
        return null;

    require_once '../include/r2_config.php';
    return uploadSelfieToR2($imageData, $userId, $punchType);
}

// Get selfie
if (!empty($_POST['selfie'])) {
    $selfiePath = saveSelfie($_POST['selfie'], $userId, $punchType);
} elseif (!empty($_POST['selfieOut'])) {
    $selfiePath = saveSelfie($_POST['selfieOut'], $userId, $punchType);
}

// ════════════════════════════════════════════════════════════════
// PUNCH IN
// ════════════════════════════════════════════════════════════════
if ($punchType === 'in') {

    if ($count > 0 && ($res_c['manHours'] == '' || $res_c['manHours'] == '00:00:00') && ($res_c['punchOutTime'] == '' || $res_c['punchOutTime'] == '00:00:00')) {
        if ($isJson) {
            sendJsonResponse(["success" => false, "message" => "Already punched in for tour today."], $con);
        }
    }

    if ($res_c['manHours'] != '00:00:00' || $count == 0) {
        include('../include/sweetAlert.php');

        $q = mysqli_query($con, "SELECT totalWorkingHours FROM signup WHERE userId = '$userId'");
    $r = mysqli_fetch_assoc($q);
    $actualWorkingHours = $r['totalWorkingHours'];

    $overTime = ($time < '09:00:00' || $time >= '18:30:00') ? 'yes' : '';
    $late = ($time > $r0['expectedPunchIn'] && $time < '19:30:00') ? 'yes' : '';

    // Check tour approval
    $q_tour = mysqli_query($con, "
        SELECT id FROM tour
        WHERE userId = '$userId'
          AND '$date' BETWEEN DATE(fromDate) AND DATE(toDate)
          AND status IN ('Approved', 'Pending')
        LIMIT 1
    ");
    $tourRow = mysqli_fetch_assoc($q_tour);
    $tourId = $tourRow['id'] ?? null;

    $query = mysqli_query($con, "
        INSERT INTO tourAttendance
            (userId, tourId, punchInTime, punchInLatitude, punchInLongitude, punchInSelfie,
             actualManHour, dateTime, date, lateMark, overTime)
        VALUES
            ('$userId', '$tourId', '$time', '$latitude', '$longitude', '$selfiePath',
             '$actualWorkingHours', '$dateTime', '$date', '$late', '$overTime')
    ") or die($con->error);

        $status = $query ? true : false;
        $msg = $query ? 'Tour Punched In Successfully.' : "Sorry, Tour Punch In can't be done. Please try again";

        if ($isJson) {
            sendJsonResponse(["success" => $status, "message" => $msg], $con);
        }

        setSession($status, $msg);
        echo "<script>window.location.href = '$redirectUrl';</script>";
        exit;
    }
}

// ════════════════════════════════════════════════════════════════
// PUNCH OUT
// ════════════════════════════════════════════════════════════════
elseif ($punchType === 'out') {
    
    if ($count == 0 || (!empty($res_c['punchOutTime']) && $res_c['punchOutTime'] !== '00:00:00')) {
        if ($isJson) {
            sendJsonResponse(["success" => false, "message" => "No active tour punch-in found for today."], $con);
        }
    }
    
    if (
        ($res_c['manHours'] == '' || $res_c['manHours'] == '00:00:00') &&
        ($res_c['punchOutTime'] == '' || $res_c['punchOutTime'] == '00:00:00')
    ) {
        include('../include/sweetAlert.php');

    $result_time = mysqli_query($con, "
        SELECT * FROM tourAttendance
        WHERE userId='$userId' AND date='$date' AND manHours='00:00:00'
    ") or die('Query Failed - 10 ' . $con->error);
    $res_time = mysqli_fetch_assoc($result_time);

    $punchInTimeStr = $res_time['punchInTime'];
    $otPunch = $punchInTimeStr;

    // ── FIX: correct man hours calculation ──────────────────────
    // Using DateTime objects so total hours > 23 are handled correctly
    $punchInDT = new DateTime($date . ' ' . $punchInTimeStr);
    $punchOutDT = new DateTime($date . ' ' . $time);

    // If punch-out is before punch-in (past midnight edge case), add a day
    if ($punchOutDT < $punchInDT) {
        $punchOutDT->modify('+1 day');
    }

    $interval = $punchInDT->diff($punchOutDT);
    // Total hours = days*24 + hours (fixes the bug where only $interval->h was used)
    $totalHours = ($interval->days * 24) + $interval->h;
    $manHours = str_pad($totalHours, 2, '0', STR_PAD_LEFT)
        . ':' . str_pad($interval->i, 2, '0', STR_PAD_LEFT)
        . ':' . str_pad($interval->s, 2, '0', STR_PAD_LEFT);

    $q = mysqli_query($con, "SELECT totalWorkingHours FROM signup WHERE userId = '$userId'");
    $r = mysqli_fetch_assoc($q);
    $actualWorkingHours = $r['totalWorkingHours'];

    $getQ = mysqli_query($con, "
        SELECT * FROM tourAttendance
        WHERE userId='$userId' AND date='$date'
        ORDER BY id DESC LIMIT 1
    ") or die('Query Failed - 8 ' . $con->error);
    $mysqliD = mysqli_fetch_assoc($getQ);
    $sr_no = $mysqliD['id'];

    // ── OT calculation ───────────────────────────────────────────
    $OTManHour = '00:00:00';
    if (!empty($otPunch) && $otPunch !== '00:00:00') {
        if ('09:30:00' > $otPunch) {
            $otStartTimeObj = DateTime::createFromFormat('H:i:s', '09:30:00');
            $punchInTimeObj = DateTime::createFromFormat('H:i:s', $otPunch);
            if ($otStartTimeObj !== false && $punchInTimeObj !== false) {
                $interval1 = $otStartTimeObj->diff($punchInTimeObj);
                $OTManHour = $interval1->format('%H:%I:%S');
            }
        } elseif ('20:30:00' < $otPunch) {
            $OTManHour = $manHours;
        }
    }

    $updatedToSenior = $_POST['updatedToSenior'] ?? $_GET['updatedToSenior'] ?? 'No';

    $query = mysqli_query($con, "
        UPDATE tourAttendance
        SET punchOutTime    = '$time',
            actualManHour   = '$actualWorkingHours',
            manHours        = '$manHours',
            punchOutLatitude  = '$latitude',
            punchOutLongitude = '$longitude',
            punchOutSelfie  = '$selfiePath',
            dateTime        = '$dateTime',
            OTManHour       = '$OTManHour',
            updatedToSenior = '$updatedToSenior'
        WHERE userId = '$userId' AND date = '$date' AND id = '$sr_no'
    ") or die($con->error);

        $status = $query ? true : false;
        $msg = $query ? 'Tour Punched Out Successfully.' : "Sorry, Tour Punch Out can't be done. Please try again";

        if ($isJson) {
            sendJsonResponse(["success" => $status, "message" => $msg], $con);
        }

        setSession($status, $msg);
        echo "<script>window.location.href = '$redirectUrl';</script>";
        exit;
    }
}

// Invalid request
if ($isJson) {
    sendJsonResponse(["success" => false, "message" => "Invalid tour punch request."], $con);
}
?>