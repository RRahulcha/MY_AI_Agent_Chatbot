<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * attendance.php  —  Punch In / Punch Out handler
 * ─────────────────────────────────────────────────────────────────────────────
 */

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function sendJsonResponse(array $data, $con = null): void
{
    if (ob_get_length()) {
        ob_clean();
    }
    echo json_encode($data);
    if ($con instanceof mysqli) {
        $con->close();
    }
    exit;
}

if (empty($_SESSION['userId'])) {
    sendJsonResponse(["success" => false, "message" => "Not logged in."]);
}

include('../include/db_config.php');
require_once('../include/early_go_helper.php');
require_once('../include/late_mark_helper.php');
require_once('../include/r2_config.php');

if (!$con) {
    sendJsonResponse(["success" => false, "message" => "Database connection failed."]);
}

$userId = $_SESSION['userId'];
$date = date('Y-m-d');
$dateTime = date('Y-m-d H:i:s');
$time = date('H:i:s');

$punchType = strtolower($_POST['punchType'] ?? $_GET['punch'] ?? '');

// ── Selfie is mandatory ──
$selfieInput = $_POST['selfie'] ?? $_POST['imageData'] ?? '';
if (empty($selfieInput)) {
    sendJsonResponse(["success" => false, "message" => "Selfie is mandatory. Please capture selfie."], $con);
}

// ── Save selfie image ──
$selfie = "";
if (strpos($selfieInput, 'data:image') === 0 || strlen($selfieInput) > 500) {
    $imageData = str_replace("data:image/jpeg;base64,", "", $selfieInput);
    $imageData = str_replace("data:image/png;base64,", "", $imageData);
    $imageData = str_replace(" ", "+", $imageData);
    $fileData = base64_decode($imageData);
    unset($imageData, $selfieInput);

    if ($fileData !== false && !empty($fileData)) {
        $r2Error = '';
        try {
            $objectKey = uploadSelfieToR2($fileData, $userId, $punchType);
            if ($objectKey) {
                $selfie = $objectKey;
            }
        } catch (Throwable $r2Ex) {
            $r2Error = $r2Ex->getMessage();
            error_log("R2 upload error: " . $r2Error);
        }

        // R2 upload is mandatory — block attendance if upload failed
        if (empty($selfie)) {
            $errMsg = "Selfie upload to cloud storage failed. Please try again.";
            if (!empty($r2Error)) {
                $errMsg .= " [R2: " . substr($r2Error, 0, 200) . "]";
            }
            sendJsonResponse(["success" => false, "message" => $errMsg], $con);
        }
    } else {
        sendJsonResponse(["success" => false, "message" => "Captured image data is corrupted or empty. Please retake your selfie."], $con);
    }
} else {
    // If we received something that isn't a base64 image (like 'data:,') reject it.
    // The client should only ever send base64 strings starting with 'data:image'.
    $debugStr = substr(htmlspecialchars($selfieInput), 0, 50);
    sendJsonResponse(["success" => false, "message" => "Invalid selfie image format received (Debug: {$debugStr}). Please retake your selfie."], $con);
}

// ── Location ──
$latitude = isset($_POST['latitude']) ? floatval($_POST['latitude']) : 0.0;
$longitude = isset($_POST['longitude']) ? floatval($_POST['longitude']) : 0.0;
$punchOutLatitude = isset($_POST['punchOutLatitude']) ? floatval($_POST['punchOutLatitude']) : $latitude;
$punchOutLongitude = isset($_POST['punchOutLongitude']) ? floatval($_POST['punchOutLongitude']) : $longitude;

// ══════════════════════════════════════════════════════════════════════════════
//  QUERY 1: Fetch user timing
// ══════════════════════════════════════════════════════════════════════════════
$userStmt = $con->prepare(
    "SELECT expectedPunchIn, expectedPunchOut, totalWorkingHours
     FROM signup WHERE userId = ? LIMIT 1"
);
if (!$userStmt) {
    sendJsonResponse(["success" => false, "message" => "Database query error: " . $con->error], $con);
}
$userStmt->bind_param('s', $userId);
$userStmt->execute();
$r0 = $userStmt->get_result()->fetch_assoc();
$userStmt->close();

if (!$r0) {
    sendJsonResponse(["success" => false, "message" => "User not found."], $con);
}

// ══════════════════════════════════════════════════════════════════════════════
//  QUERY 2: Fetch today's attendance rows
// ══════════════════════════════════════════════════════════════════════════════
$attStmt = $con->prepare(
    "SELECT id, punchInTime, punchOutTime
     FROM attendance
     WHERE userId = ? AND date = ?
     ORDER BY id DESC"
);
if (!$attStmt) {
    sendJsonResponse(["success" => false, "message" => "Database query error: " . $con->error], $con);
}
$attStmt->bind_param('ss', $userId, $date);
$attStmt->execute();
$attResult = $attStmt->get_result();

$openPunchRow = null;
$hasCompletedPunch = false;
$intimationRow = null;

while ($row = $attResult->fetch_assoc()) {
    $pi = isset($row['punchInTime']) ? trim((string) $row['punchInTime']) : '';
    $po = isset($row['punchOutTime']) ? trim((string) $row['punchOutTime']) : '';

    $hasIn = (!empty($pi) && $pi !== '00:00:00' && $pi !== '00:00');
    $hasOut = (!empty($po) && $po !== '00:00:00' && $po !== '00:00' && strtolower($po) !== 'null');

    if ($hasIn && !$hasOut) {
        if ($openPunchRow === null) {
            $openPunchRow = $row;
        }
    } elseif ($hasIn && $hasOut) {
        $hasCompletedPunch = true;
    }
}
$attStmt->close();

$hasOpenPunch = ($openPunchRow !== null);

// ════════════════════════════════════════════════
//  PUNCH IN
// ════════════════════════════════════════════════
if ($punchType === 'in') {

    if ($hasOpenPunch) {
        sendJsonResponse(["success" => false, "message" => "Already punched in for today."], $con);
    }

    if ($hasCompletedPunch) {
        sendJsonResponse(["success" => false, "message" => "Attendance already recorded for today."], $con);
    }

    $expectedPunchIn = $r0['expectedPunchIn'] ?? '09:30:00';
    $actualWorkingHours = $r0['totalWorkingHours'] ?? '09:00:00';

    $lateEval = evaluateLateMarkPolicy($time, $expectedPunchIn, $date);
    $late = $lateEval['lateMark'];
    $isHalfDay = $lateEval['isHalfDay'];

    // ── Role-based Late Mark restriction ─────────────────────────────────────
    // Only senior_intern, hr, and resourceAllocator are subject to Late Mark Policy.
    $sessionRole = $_SESSION['userRole'] ?? '';
    $sessionDesignation = $_SESSION['designation'] ?? '';
    if (!isLateMarkEligible($sessionRole, $sessionDesignation)) {
        $late = 'no';
        $isHalfDay = false; // late-mark-driven half day also exempted
    }

    $overTime = ($time < '09:00:00') ? 'yes' : 'no';

    // Check WFH status
    $isWFHApprovedToday = false;
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

    $wfhStmt = $con->prepare("SELECT $dateSelect FROM wfhRequests WHERE userid = ? AND status = 'Approved'");
    if ($wfhStmt) {
        $wfhStmt->bind_param('s', $userId);
        $wfhStmt->execute();
        $wfhRes = $wfhStmt->get_result();
        while ($wfhRow = $wfhRes->fetch_assoc()) {
            $fDate = $wfhRow['fromDate'];
            $tDate = $wfhRow['toDate'];
            if ($date >= $fDate && $date <= $tDate) {
                $isWFHApprovedToday = true;
                break;
            }
        }
        $wfhStmt->close();
    }

    // Auto-close previous day open punches
    $autoCloseStmt = $con->prepare(
        "UPDATE attendance
         SET    punchOutTime = '23:59:59',
                manHours     = TIMEDIFF('23:59:59', punchInTime),
                status       = 'Present'
         WHERE  userId       = ?
           AND  punchOutTime = '00:00:00'
           AND  date         < ?"
    );
    if ($autoCloseStmt) {
        $autoCloseStmt->bind_param('ss', $userId, $date);
        $autoCloseStmt->execute();
        $autoCloseStmt->close();
    }

    if ($intimationRow !== null) {
        $intimationId = (int) $intimationRow['id'];
        $status = $isHalfDay
            ? ($isWFHApprovedToday ? 'Half Day (Online)' : 'Half Day')
            : ($isWFHApprovedToday ? 'Present (Online)' : 'Present');

        $updateStmt = $con->prepare(
            "UPDATE attendance
             SET punchInTime      = ?,
                 punchInImage     = ?,
                 punchInLatitude  = ?,
                 punchInLongitude = ?,
                 lateMark         = ?,
                 overTime         = ?,
                 dateTime         = ?,
                 status           = ?
             WHERE id = ?"
        );
        if (!$updateStmt) {
            sendJsonResponse(["success" => false, "message" => "Failed to prepare update: " . $con->error], $con);
        }
        $updateStmt->bind_param(
            'ssddssssi',
            $time,
            $selfie,
            $latitude,
            $longitude,
            $late,
            $overTime,
            $dateTime,
            $status,
            $intimationId
        );
        $query = $updateStmt->execute();
        $updateStmt->close();
    } else {
        $punchOutDefault = '00:00:00';
        $punchOutImgDefault = '';
        $punchOutLatDefault = 0.0;
        $punchOutLonDefault = 0.0;
        $manHoursDefault = '00:00:00';
        $status = $isHalfDay
            ? ($isWFHApprovedToday ? 'Half Day (Online)' : 'Half Day')
            : ($isWFHApprovedToday ? 'Present (Online)' : 'Present');
        $remark = '';

        $insertStmt = $con->prepare(
            "INSERT INTO attendance
                (userId, punchInTime, punchInImage,
                 punchInLatitude, punchInLongitude,
                 punchOutTime, punchOutImage,
                 punchOutLatitude, punchOutLongitude,
                 manHours, actualManHour,
                 dateTime, date, lateMark, overTime,
                 status, remark)
            VALUES
                (?, ?, ?,
                 ?, ?,
                 ?, ?,
                 ?, ?,
                 ?, ?,
                 ?, ?, ?, ?,
                 ?, ?)"
        );
        if (!$insertStmt) {
            sendJsonResponse(["success" => false, "message" => "Failed to prepare insert: " . $con->error], $con);
        }
        $insertStmt->bind_param(
            'sssddssddssssssss',
            $userId,
            $time,
            $selfie,
            $latitude,
            $longitude,
            $punchOutDefault,
            $punchOutImgDefault,
            $punchOutLatDefault,
            $punchOutLonDefault,
            $manHoursDefault,
            $actualWorkingHours,
            $dateTime,
            $date,
            $late,
            $overTime,
            $status,
            $remark
        );
        $query = $insertStmt->execute();
        $insertStmt->close();
    }

    if ($query) {
        sendJsonResponse(["success" => true, "message" => "Punch In Successful"], $con);
    } else {
        sendJsonResponse(["success" => false, "message" => "Failed to save attendance: " . $con->error], $con);
    }

}
// ════════════════════════════════════════════════
//  PUNCH OUT
// ════════════════════════════════════════════════
elseif ($punchType === 'out') {

    if (!$hasOpenPunch) {
        sendJsonResponse(["success" => false, "message" => "No open punch-in found for today. Please punch in first."], $con);
    }

    $openId = (int) $openPunchRow['id'];
    $punchInTime = $openPunchRow['punchInTime'];

    $punchInObj = date_create($punchInTime);
    $punchOutObj = date_create($time);
    $interval = date_diff($punchInObj, $punchOutObj);
    $manHours = $interval->format('%H:%I:%S');

    $expectedPunchOut = $r0['expectedPunchOut'] ?? '18:30:00';
    $actualWorkingHours = $r0['totalWorkingHours'] ?? '09:00:00';

    $isEarly = ($time < $expectedPunchOut) ? 'Yes' : 'No';
    $earlyReason = $_POST['earlyReason'] ?? '';

    if ($isEarly === 'Yes' && empty($earlyReason)) {
        sendJsonResponse(["success" => false, "early" => true], $con);
    }

    // Evaluate Early Go Policy
    $policyResult = processEarlyGoPolicy(
        $con,
        $userId,
        $date,
        $time,
        $expectedPunchOut,
        $earlyReason
    );

    $status = 'Present';
    if ($policyResult['isHalfDay']) {
        $status = 'Half Day';
    }

    $updateStmt = $con->prepare(
        "UPDATE attendance SET
            punchOutTime      = ?,
            punchOutImage     = ?,
            punchOutLatitude  = ?,
            punchOutLongitude = ?,
            manHours          = ?,
            actualManHour     = ?,
            dateTime          = ?,
            status            = ?
        WHERE id = ?"
    );
    if (!$updateStmt) {
        sendJsonResponse(["success" => false, "message" => "Failed to prepare update: " . $con->error], $con);
    }
    $updateStmt->bind_param(
        'ssddssssi',
        $time,
        $selfie,
        $punchOutLatitude,
        $punchOutLongitude,
        $manHours,
        $actualWorkingHours,
        $dateTime,
        $status,
        $openId
    );
    $queryResult = $updateStmt->execute();
    $updateStmt->close();

    if (!$queryResult) {
        sendJsonResponse(["success" => false, "message" => "Failed to update attendance: " . $con->error], $con);
    }

    sendJsonResponse([
        "success" => true,
        "message" => "Punch Out Successful",
        "early" => ($isEarly === 'Yes'),
        "earlyGoPolicy" => [
            "minutes" => $policyResult['earlyDepartureMinutes'],
            "mark" => $policyResult['earlyGoMark'],
            "action" => $policyResult['actionTaken'],
            "monthlyCount" => $policyResult['monthlyCount'],
            "warningIssued" => $policyResult['warningIssued'],
            "halfDayDeducted" => $policyResult['halfDayDeducted']
        ]
    ], $con);

} else {
    sendJsonResponse(["success" => false, "message" => "Invalid punch type: '$punchType'"], $con);
}
