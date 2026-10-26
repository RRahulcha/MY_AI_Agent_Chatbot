<?php
/**
 * updateResourceRequestProcess.php
 * Handles updating dates, project, and time slots for pending resource requests.
 */

session_start();

if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    header('Content-Type: application/json');
    echo json_encode(['status' => false, 'msg' => 'Unauthorized access.']);
    exit;
}

include('../include/db_config.php');
require_once('availabilityEngine.php');

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || isset($_POST['isAjax']);

$respond = function(bool $ok, string $msg) use ($isAjax) {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => $ok, 'msg' => $msg]);
        exit;
    }
    $_SESSION['flash_page'] = 'resourceRequest';
    $_SESSION['status']     = $ok;
    $_SESSION['msg']        = $msg;
    echo "<script>window.location.href = 'resourceRequest.php';</script>";
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $respond(false, "Invalid request method.");
}

$requestId      = (int)($_POST['requestId'] ?? 0);
$projectName    = trim(mysqli_real_escape_string($con, $_POST['projectName']    ?? ''));
$clientName     = trim(mysqli_real_escape_string($con, $_POST['clientName']     ?? ''));
$startDate      = trim(mysqli_real_escape_string($con, $_POST['startDate']      ?? ''));
$endDate        = trim(mysqli_real_escape_string($con, $_POST['endDate']        ?? ''));
$allocStartTime = trim(mysqli_real_escape_string($con, $_POST['allocStartTime'] ?? ''));
$allocEndTime   = trim(mysqli_real_escape_string($con, $_POST['allocEndTime']   ?? ''));

if ($requestId <= 0 || empty($startDate) || empty($endDate) || empty($allocStartTime) || empty($allocEndTime)) {
    $respond(false, "Please fill all required fields correctly.");
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
    $respond(false, "Invalid date format.");
}

if ($endDate < $startDate) {
    $respond(false, "End date cannot be before start date.");
}

// Fetch existing request
$reqQ = mysqli_query($con, "SELECT * FROM resourceRequests WHERE id = $requestId LIMIT 1");
if (!$reqQ || mysqli_num_rows($reqQ) === 0) {
    $respond(false, "Resource request not found.");
}
$reqRow   = mysqli_fetch_assoc($reqQ);
$internId = $reqRow['internId'];

if ($reqRow['status'] !== 'Pending') {
    $respond(false, "Only pending requests can be edited directly. Approved requests must be modified by the Resource Manager.");
}

// Format time HH:MM
if (strlen($allocStartTime) === 5) $allocStartTime .= ':00';
if (strlen($allocEndTime) === 5)   $allocEndTime   .= ':00';

$startMins = timeToMinutes($allocStartTime);
$endMins   = timeToMinutes($allocEndTime);

if ($endMins <= $startMins) {
    $respond(false, "Allocation end time must be after start time.");
}

// Validate allocation against leaves, tours, holidays, approved allocations
$val = validateAllocationRequest($con, $internId, $startDate, $endDate, $allocStartTime, $allocEndTime, null, false);
if (!$val['valid']) {
    $respond(false, "❌ Cannot update request — conflicts detected: " . implode(" | ", $val['errors']));
}

$dailyHours = (int)ceil(($endMins - $startMins) / 60);

// Use existing project/client if empty
if (empty($projectName)) $projectName = $reqRow['projectName'];
if (empty($clientName))  $clientName  = $reqRow['clientName'];

$updateSql = "UPDATE resourceRequests SET 
                projectName    = '$projectName',
                clientName     = '$clientName',
                startDate      = '$startDate',
                endDate        = '$endDate',
                allocStartTime = '$allocStartTime',
                allocEndTime   = '$allocEndTime',
                dailyHours     = '$dailyHours'
              WHERE id = $requestId AND status = 'Pending'";

if (mysqli_query($con, $updateSql)) {
    $respond(true, "✓ Resource request updated successfully!");
} else {
    $respond(false, "❌ DB error updating request: " . mysqli_error($con));
}
