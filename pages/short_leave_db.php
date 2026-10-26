<?php
session_start();
if (empty($_SESSION['userId'])) {
    header('Location: login.php'); exit;
}
require_once('../include/db_config.php');
require_once('../include/short_leave_helper.php');

$userId = $_SESSION['userId'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: apply_short_leave.php'); exit;
}

$date      = $_POST['sl_date']   ?? '';
$fromTime  = $_POST['from_time'] ?? '';
$toTime    = $_POST['to_time']   ?? '';
$reason    = trim($_POST['sl_reason'] ?? '');

// Validate required fields
if (empty($date) || empty($fromTime) || empty($toTime) || empty($reason)) {
    $_SESSION['sl_error'] = 'All fields are required.';
    header('Location: apply_short_leave.php'); exit;
}

// Calculate duration in hours
$fromSec = strtotime("$date $fromTime");
$toSec   = strtotime("$date $toTime");
if ($toSec <= $fromSec) {
    $_SESSION['sl_error'] = 'To time must be after From time.';
    header('Location: apply_short_leave.php'); exit;
}
$durationHrs = round(($toSec - $fromSec) / 3600, 2);

// Evaluate policy
$eval = evaluateShortLeavePolicy($con, $userId, $date, $durationHrs);
if (!$eval['allowed']) {
    $_SESSION['sl_error'] = $eval['message'];
    header('Location: apply_short_leave.php'); exit;
}

// Check for duplicate application on same date
$escUser   = mysqli_real_escape_string($con, $userId);
$escDate   = mysqli_real_escape_string($con, $date);
$escFrom   = mysqli_real_escape_string($con, $fromTime);
$escTo     = mysqli_real_escape_string($con, $toTime);
$escReason = mysqli_real_escape_string($con, $reason);

$dupQ = mysqli_query($con, "SELECT id FROM short_leaves WHERE userId='$escUser' AND date='$escDate' AND status != 'Rejected' LIMIT 1");
if ($dupQ && mysqli_num_rows($dupQ) > 0) {
    $_SESSION['sl_error'] = 'You already have a short leave application for this date.';
    header('Location: apply_short_leave.php'); exit;
}

// Insert the short leave request
$ins = mysqli_query($con, "
    INSERT INTO short_leaves (userId, date, from_time, to_time, duration_hrs, reason, status)
    VALUES ('$escUser', '$escDate', '$escFrom', '$escTo', '$durationHrs', '$escReason', 'Pending')
");

if ($ins) {
    $deductNote = $eval['deductFromLeave'] ? ' Note: 0.5 day will be deducted from your leave balance upon approval.' : '';
    $_SESSION['sl_success'] = 'Short leave request submitted successfully!' . $deductNote;
} else {
    $_SESSION['sl_error'] = 'Database error. Please try again.';
}

header('Location: apply_short_leave.php');
exit;
