<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
header('Content-Type: application/json');

if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

include('../include/db_config.php');
require_once('availabilityEngine.php');

$userId = $_SESSION['userId'];
$stmt = mysqli_prepare($con, "SELECT userRole, designation FROM signup WHERE userId = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, "s", $userId);
mysqli_stmt_execute($stmt);
$userResult = mysqli_stmt_get_result($stmt);
if (!$userResult || mysqli_num_rows($userResult) === 0) {
    http_response_code(403); echo json_encode(['status' => 'error', 'message' => 'User not found.']); exit;
}
$userRow   = mysqli_fetch_assoc($userResult);
$userRole  = strtolower(trim($userRow['userRole'] ?? ''));
$userDesig = strtolower(trim($userRow['designation'] ?? ''));
$allowedDirectAssign = ['resourceallocator', 'resource_allocator', 'resource allocator', 'resourcemanager', 'resource_manager', 'resourcehead', 'resource_head', 'admin', 'superadmin'];
if (!in_array($userRole, $allowedDirectAssign) && !in_array($userDesig, $allowedDirectAssign)) {
    http_response_code(403); echo json_encode(['status' => 'error', 'message' => 'No permission. Direct assignment is restricted to Resource Managers.']); exit;
}
mysqli_stmt_close($stmt);

$internId       = trim($_POST['internId']       ?? '');
$projectName    = trim($_POST['projectName']    ?? '');
$clientName     = trim($_POST['clientName']     ?? '');  // allowed to be empty now
$startDate      = trim($_POST['startDate']      ?? '');
$endDate        = trim($_POST['endDate']        ?? '');
$allocStartTime = trim($_POST['allocStartTime'] ?? '');
$allocEndTime   = trim($_POST['allocEndTime']   ?? '');
$assignedBy     = trim($_POST['assignedBy']     ?? $userId);

// Only truly required fields
if (empty($internId) || empty($projectName) || empty($startDate) || empty($endDate) || empty($allocStartTime) || empty($allocEndTime)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Please fill all required fields.']);
    exit;
}

if (strtotime($endDate) < strtotime($startDate)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'End date cannot be before start date.']);
    exit;
}

// timeToMinutes() is provided by availabilityEngine.php

$allocStartMins = timeToMinutes($allocStartTime);
$allocEndMins   = timeToMinutes($allocEndTime);

if ($allocStartMins === false || $allocEndMins === false) {
    http_response_code(400); echo json_encode(['status' => 'error', 'message' => 'Invalid time format.']); exit;
}
if ($allocEndMins <= $allocStartMins) {
    http_response_code(400); echo json_encode(['status' => 'error', 'message' => 'End time must be after start time.']); exit;
}

// Verify intern exists
$stmt = mysqli_prepare($con, "SELECT internId FROM signup WHERE internId = ? AND status = 'active' LIMIT 1");
mysqli_stmt_bind_param($stmt, "s", $internId);
mysqli_stmt_execute($stmt);
$internResult = mysqli_stmt_get_result($stmt);
if (!$internResult || mysqli_num_rows($internResult) === 0) {
    http_response_code(404); echo json_encode(['status' => 'error', 'message' => 'Intern not found or not active.']); exit;
}
mysqli_stmt_close($stmt);

// Check for overlaps — approved allocations AND pending requests
$overlapDay = null;
$d    = strtotime($startDate);
$dEnd = strtotime($endDate);
while ($d <= $dEnd) {
    $dVal = date('Y-m-d', $d);
    // Check approved allocations
    $stmt = mysqli_prepare($con, "SELECT id FROM internAllocations WHERE internId = ? AND startDate <= ? AND endDate >= ? AND allocStartTime < ? AND allocEndTime > ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "sssss", $internId, $dVal, $dVal, $allocEndTime, $allocStartTime);
    mysqli_stmt_execute($stmt);
    $overResult = mysqli_stmt_get_result($stmt);
    if ($overResult && mysqli_num_rows($overResult) > 0) { $overlapDay = $dVal; mysqli_stmt_close($stmt); break; }
    mysqli_stmt_close($stmt);
    // Check pending resource requests (FIX-5)
    $stmt2 = mysqli_prepare($con, "SELECT id FROM resourceRequests WHERE internId = ? AND status = 'Pending' AND startDate <= ? AND endDate >= ? AND allocStartTime < ? AND allocEndTime > ? LIMIT 1");
    mysqli_stmt_bind_param($stmt2, "sssss", $internId, $dVal, $dVal, $allocEndTime, $allocStartTime);
    mysqli_stmt_execute($stmt2);
    $pendResult = mysqli_stmt_get_result($stmt2);
    if ($pendResult && mysqli_num_rows($pendResult) > 0) {
        $overlapDay = $dVal . ' (pending request)';
        mysqli_stmt_close($stmt2);
        break;
    }
    mysqli_stmt_close($stmt2);
    $d = strtotime('+1 day', $d);
}
if ($overlapDay) {
    http_response_code(409);
    echo json_encode(['status' => 'error', 'message' => 'Time slot overlaps with an existing assignment or pending request on ' . $overlapDay . '.']);
    exit;
}

$dailyHours = ceil(($allocEndMins - $allocStartMins) / 60);

$stmt = mysqli_prepare($con, "INSERT INTO internAllocations (internId, projectName, clientName, startDate, endDate, dailyHours, allocStartTime, allocEndTime, allocatedBy) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
mysqli_stmt_bind_param($stmt, "sssssisss", $internId, $projectName, $clientName, $startDate, $endDate, $dailyHours, $allocStartTime, $allocEndTime, $assignedBy);

if (mysqli_stmt_execute($stmt)) {
    $newId = mysqli_insert_id($con);
    http_response_code(201);
    echo json_encode(['status' => 'success', 'id' => $newId, 'message' => 'Assignment created successfully.']);
} else {
    error_log('directAssignProcess.php DB error: ' . mysqli_error($con));
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to save assignment.']);
}
mysqli_stmt_close($stmt);
mysqli_close($con);
exit;