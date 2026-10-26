<?php
session_start();
include('../include/db_config.php');
require_once('../include/late_mark_helper.php');

// ✅ 1. Check login session
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>alert('User not logged in. Please login again.'); window.location.href='login.php';</script>";
    exit;
}

$userId = $_SESSION['userId'];
$officeId = $_SESSION['officeId'] ?? ''; // optional
$punchType = strtolower($_POST['punch'] ?? $_GET['punch'] ?? '');

// Dynamically resolve correct dashboard redirect URL
$userRole = $_SESSION['userRole'] ?? '';
$designation = $_SESSION['designation'] ?? '';
$redirectURL = 'jrEmpDashboard.php';
if ($designation === 'hr') {
    $redirectURL = 'hrDashboard.php';
} elseif ($designation === 'receptionist') {
    $redirectURL = 'receptionistDashboard.php';
} elseif ($userRole === 'senior_intern') {
    $redirectURL = 'srEmpDashboard.php';
} elseif ($userRole === 'resourceAllocator') {
    $redirectURL = 'resourceHeadDashboard.php';
} elseif ($userRole === 'admin') {
    $redirectURL = 'adminDashboard.php';
}

$imageData = $_POST['imageData'] ?? '';
$latitude = $_POST['latitude'] ?? '';
$longitude = $_POST['longitude'] ?? '';

// ✅ 2. Validate input data
if (empty($imageData) || empty($latitude) || empty($longitude) || empty($punchType)) {
    echo "<script>alert('Missing required data!'); window.history.back();</script>";
    exit;
}

// ✅ 3. Decode and save the selfie image
$image_parts = explode(";base64,", $imageData);
$image_base64 = base64_decode($image_parts[1]);
    require_once '../include/r2_config.php';
    $objectKey = uploadSelfieToR2($image_base64, $userId, $punchType);
    
    if (!$objectKey) {
        echo "<script>alert('Failed to save selfie image to Cloudflare R2!'); window.history.back();</script>";
        exit;
    }
    
    $filePath = $objectKey;

// ✅ 4. Set timestamps
$date = date('Y-m-d');
$time = date('H:i:s');
$dateTime = date('Y-m-d H:i:s');

// Check if user has an approved WFH for today
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

$wfhCheck = mysqli_query($con, "SELECT $dateSelect FROM wfhRequests WHERE userid = '$userId' AND status = 'Approved'");
if ($wfhCheck) {
    while ($wfhRow = mysqli_fetch_assoc($wfhCheck)) {
        $fDate = $wfhRow['fromDate'];
        $tDate = $wfhRow['toDate'];
        if ($date >= $fDate && $date <= $tDate) {
            $isWFHApprovedToday = true;
            break;
        }
    }
}

// Check if the user is permanently 'Online' in their profile
if (!$isWFHApprovedToday) {
    $userTypeQ = mysqli_query($con, "SELECT type FROM signup WHERE userId = '$userId'");
    if ($userTypeQ && mysqli_num_rows($userTypeQ) > 0) {
        $uRow = mysqli_fetch_assoc($userTypeQ);
        if (strtolower(trim($uRow['type'])) === 'online') {
            $isWFHApprovedToday = true;
        }
    }
}

// Fetch expected punch in for late mark evaluation
$expQ = mysqli_query($con, "SELECT expectedPunchIn FROM signup WHERE userId = '$userId' LIMIT 1");
$expRow = $expQ ? mysqli_fetch_assoc($expQ) : null;
$expectedPunchIn = $expRow['expectedPunchIn'] ?? '09:30:00';

$lateEval = evaluateLateMarkPolicy($time, $expectedPunchIn, $date);
$lateMark = ucfirst($lateEval['lateMark']); // 'Yes' or 'No'

// ── Role-based Late Mark restriction ───────────────────────────────────
// Only senior_intern, hr, and resourceAllocator are subject to the Late Mark Policy.
if (!isLateMarkEligible($userRole, $designation)) {
    $lateMark = 'No';
    $lateEval['isHalfDay'] = false; // also exempt from late-mark-driven half day
}

if ($lateEval['isHalfDay']) {
    $status = $isWFHApprovedToday ? 'Half Day (Online)' : 'Half Day';
    $lateMark = 'No';
} else {
    $status = $isWFHApprovedToday ? 'Present (Online)' : 'Present';
}

// Check if record already exists for today
$check = "SELECT * FROM attendance WHERE userId = '$userId' AND date = '$date'";
$res = mysqli_query($con, $check);

if ($punchType === 'in') {
    // --- Punch IN ---
    if (mysqli_num_rows($res) > 0) {
        echo "<script>alert('You have already punched in today!'); window.location.href='{$redirectURL}?punch=out';</script>";
        exit;
    }

    $insert = "INSERT INTO attendance (
        date, officeId, userId, punchInTime, punchInImage, 
        punchInLatitude, punchInLongitude, manHours, actualManHour, points, 
        status, dateTime, lateMark, overTime, OTManHour, 
        updatedToSenior, punchOutStatus
    ) VALUES (
        '$date', '$officeId', '$userId', '$time', '$filePath', 
        '$latitude', '$longitude', '00:00:00', '00:00:00', 0, 
        '$status', '$dateTime', '$lateMark', 'No', '00:00:00', 
        '', 'Normal'
    )";

    if (mysqli_query($con, $insert)) {
        // Redirect senior_intern to Task Diary for planned task entry
        if ($userRole === 'senior_intern') {
            echo "<script>alert('✅ Punch In recorded successfully!'); window.location.href='taskDiary.php?mode=planned';</script>";
        } else {
            echo "<script>alert('✅ Punch In recorded successfully!'); window.location.href='{$redirectURL}?punch=out';</script>";
        }

    } else {
        echo "<script>alert('Database Error: " . mysqli_error($con) . "'); window.history.back();</script>";
    }

} elseif ($punchType === 'out') {
    // --- Punch OUT ---
    if (mysqli_num_rows($res) == 0) {
        echo "<script>alert('⚠️ You have not punched in today!'); window.location.href='{$redirectURL}';</script>";
        exit;
    }

    $update = "UPDATE attendance 
               SET punchOutImage = '$filePath',
                   punchOutLatitude = '$latitude',
                   punchOutLongitude = '$longitude',
                   punchOutTime = '$time',
                   punchOutStatus = 'Completed'
               WHERE userId = '$userId' AND date = '$date'";

    if (mysqli_query($con, $update)) {
        echo "<script>alert('✅ Punch Out recorded successfully!'); window.location.href='{$redirectURL}';</script>";
    } else {
        echo "<script>alert('Database Error: " . mysqli_error($con) . "'); window.history.back();</script>";
    }

} else {
    echo "<script>alert('Invalid punch type.'); window.history.back();</script>";
}
?>
