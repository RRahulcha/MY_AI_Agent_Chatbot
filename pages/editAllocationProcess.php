<?php
session_start();

if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['status'] = false;
    $_SESSION['msg']    = "Invalid request method.";
    echo "<script>window.location.href = 'weeklyPlanOverview.php';</script>";
    exit;
}

include('../include/db_config.php');
require_once('availabilityEngine.php');

$allocationId   = intval($_POST['allocationId']   ?? 0);
$internId       = trim(mysqli_real_escape_string($con, $_POST['internId']       ?? ''));
$projectName    = trim(mysqli_real_escape_string($con, $_POST['projectName']    ?? ''));
$startDate      = trim(mysqli_real_escape_string($con, $_POST['startDate']      ?? ''));
$endDate        = trim(mysqli_real_escape_string($con, $_POST['endDate']        ?? ''));
$allocStartTime = trim(mysqli_real_escape_string($con, $_POST['allocStartTime'] ?? ''));
$allocEndTime   = trim(mysqli_real_escape_string($con, $_POST['allocEndTime']   ?? ''));
$dailyHours     = intval($_POST['dailyHours'] ?? 0);

// ── Validation ─────────────────────────────────────────────────────────────
if ($allocationId <= 0 || empty($projectName) || empty($startDate) || empty($endDate)) {
    $_SESSION['status'] = false;
    $_SESSION['msg']    = "Please fill all required fields.";
    echo "<script>window.location.href = 'weeklyPlanOverview.php';</script>";
    exit;
}

if (!empty($allocStartTime) && !empty($allocEndTime)) {
    if (!preg_match('/^\d{2}:\d{2}$/', $allocStartTime) ||
        !preg_match('/^\d{2}:\d{2}$/', $allocEndTime)) {
        $_SESSION['status'] = false;
        $_SESSION['msg']    = "Invalid time format.";
        echo "<script>window.location.href = 'weeklyPlanOverview.php';</script>";
        exit;
    }
    $allocStartMins = timeToMinutes($allocStartTime);
    $allocEndMins   = timeToMinutes($allocEndTime);
    if ($allocEndMins <= $allocStartMins) {
        $_SESSION['status'] = false;
        $_SESSION['msg']    = "End time must be after start time.";
        echo "<script>window.location.href = 'weeklyPlanOverview.php';</script>";
        exit;
    }
    $dailyHours = (int)ceil(($allocEndMins - $allocStartMins) / 60);
}

if ($endDate < $startDate) {
    $_SESSION['status'] = false;
    $_SESSION['msg']    = "End date cannot be before start date.";
    echo "<script>window.location.href = 'weeklyPlanOverview.php';</script>";
    exit;
}

// ── Verify the allocation belongs to an active intern ─────────────────────
$check = mysqli_query($con,
    "SELECT ia.id FROM internAllocations ia
     JOIN signup s ON ia.internId COLLATE utf8mb4_unicode_ci = s.internId COLLATE utf8mb4_unicode_ci
     WHERE ia.id = '$allocationId' AND s.status = 'active'"
);
if (!$check || mysqli_num_rows($check) === 0) {
    $_SESSION['status'] = false;
    $_SESSION['msg']    = "Allocation not found or intern is not in working phase.";
    echo "<script>window.location.href = 'weeklyPlanOverview.php';</script>";
    exit;
}

// ── Check time-slot overlap on every day in the new range (FIX-4) ──────────
// Uses exact minute-level check via availabilityEngine, excluding this record.
$overlapDay = null;
if (!empty($allocStartTime) && !empty($allocEndTime)) {
    $d    = strtotime($startDate);
    $dEnd = strtotime($endDate);
    while ($d <= $dEnd) {
        $dVal = date('Y-m-d', $d);
        $oc   = checkTimeSlotOverlap(
            $con, $internId, $dVal,
            $allocStartTime, $allocEndTime,
            $allocationId,  // exclude the current record
            false           // ignore pending — only approved
        );
        if ($oc['overlap']) { $overlapDay = $dVal; break; }
        $d = strtotime('+1 day', $d);
    }
}

if ($overlapDay) {
    $_SESSION['status'] = false;
    $_SESSION['msg']    = "This edit would overlap an existing assignment on "
                          . date('d M Y', strtotime($overlapDay)) . ". Please choose a different time slot.";
    echo "<script>window.location.href = 'weeklyPlanOverview.php';</script>";
    exit;
}

// ── Update ─────────────────────────────────────────────────────────────────
$timeSetClause = '';
if (!empty($allocStartTime) && !empty($allocEndTime)) {
    $timeSetClause = ", allocStartTime = '$allocStartTime', allocEndTime = '$allocEndTime', dailyHours = '$dailyHours'";
}

$update = mysqli_query($con, "
    UPDATE internAllocations
    SET    projectName = '$projectName',
           startDate   = '$startDate',
           endDate     = '$endDate'
           $timeSetClause
    WHERE  id = '$allocationId'
");

if ($update) {
    $_SESSION['status'] = true;
    $_SESSION['msg']    = "Assignment updated successfully.";
} else {
    error_log('editAllocationProcess.php error: ' . mysqli_error($con));
    $_SESSION['status'] = false;
    $_SESSION['msg']    = "Failed to update assignment. Please try again.";
}

echo "<script>window.location.href = 'weeklyPlanOverview.php';</script>";
exit;
?>