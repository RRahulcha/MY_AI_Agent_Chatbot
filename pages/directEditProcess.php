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
if (!$con || mysqli_connect_errno()) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed.']);
    exit;
}

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
$allowedDirectEdit = ['resourceallocator', 'resource_allocator', 'resource allocator', 'resourcemanager', 'resource_manager', 'resourcehead', 'resource_head', 'admin', 'superadmin'];
if (!in_array($userRole, $allowedDirectEdit) && !in_array($userDesig, $allowedDirectEdit)) {
    http_response_code(403); echo json_encode(['status' => 'error', 'message' => 'No permission. Direct allocation editing is restricted to Resource Managers.']); exit;
}
mysqli_stmt_close($stmt);

$action       = trim($_POST['action'] ?? 'edit');
$allocationId = intval($_POST['allocationId'] ?? 0);

if ($allocationId <= 0) {
    http_response_code(400); echo json_encode(['status' => 'error', 'message' => 'Invalid allocation ID.']); exit;
}

// ── DELETE ────────────────────────────────────────────────────────────────────
if ($action === 'delete') {
    $stmt = mysqli_prepare($con, "SELECT id FROM internAllocations WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "i", $allocationId);
    mysqli_stmt_execute($stmt);
    $checkResult = mysqli_stmt_get_result($stmt);
    if (!$checkResult || mysqli_num_rows($checkResult) === 0) {
        http_response_code(404); echo json_encode(['status' => 'error', 'message' => 'Allocation not found.']); exit;
    }
    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare($con, "DELETE FROM internAllocations WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $allocationId);
    if (mysqli_stmt_execute($stmt)) {
        echo json_encode(['status' => 'success', 'id' => $allocationId, 'message' => 'Assignment deleted.']);
    } else {
        http_response_code(500); echo json_encode(['status' => 'error', 'message' => 'Delete failed.']);
    }
    mysqli_stmt_close($stmt);
    mysqli_close($con);
    exit;
}

// ── EDIT ──────────────────────────────────────────────────────────────────────
$internId       = trim($_POST['internId']       ?? '');
$projectName    = trim($_POST['projectName']    ?? '');
$startDate      = trim($_POST['startDate']      ?? '');
$endDate        = trim($_POST['endDate']        ?? '');
$allocStartTime = trim($_POST['allocStartTime'] ?? '');
$allocEndTime   = trim($_POST['allocEndTime']   ?? '');

if (empty($projectName) || empty($allocStartTime) || empty($allocEndTime)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Please fill all required fields.']);
    exit;
}

// Strip seconds (fixes HH:MM:SS from DB time columns)
function timeToMinutes($t) {
    $t = substr($t, 0, 5);
    if (!preg_match('/^([0-1]?[0-9]|2[0-3]):([0-5][0-9])$/', $t)) return false;
    list($h, $m) = explode(':', $t);
    return (int)$h * 60 + (int)$m;
}

$allocStartTime = substr($allocStartTime, 0, 5);
$allocEndTime   = substr($allocEndTime,   0, 5);

$allocStartMins = timeToMinutes($allocStartTime);
$allocEndMins   = timeToMinutes($allocEndTime);

if ($allocStartMins === false || $allocEndMins === false) {
    http_response_code(400); echo json_encode(['status' => 'error', 'message' => 'Invalid time format.']); exit;
}
if ($allocEndMins <= $allocStartMins) {
    http_response_code(400); echo json_encode(['status' => 'error', 'message' => 'End time must be after start time.']); exit;
}

// Fetch dates from DB if not provided
if (empty($startDate) || empty($endDate)) {
    $stmt = mysqli_prepare($con, "SELECT startDate, endDate FROM internAllocations WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "i", $allocationId);
    mysqli_stmt_execute($stmt);
    $dateResult = mysqli_stmt_get_result($stmt);
    if ($dateResult && mysqli_num_rows($dateResult) > 0) {
        $dateRow   = mysqli_fetch_assoc($dateResult);
        $startDate = $dateRow['startDate'];
        $endDate   = $dateRow['endDate'];
    } else {
        http_response_code(404); echo json_encode(['status' => 'error', 'message' => 'Allocation not found.']); exit;
    }
    mysqli_stmt_close($stmt);
}

if (strtotime($endDate) < strtotime($startDate)) {
    http_response_code(400); echo json_encode(['status' => 'error', 'message' => 'End date cannot be before start date.']); exit;
}

// Fetch intern's internId from allocation if not provided
if (empty($internId)) {
    $stmt = mysqli_prepare($con, "SELECT internId FROM internAllocations WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "i", $allocationId);
    mysqli_stmt_execute($stmt);
    $r = mysqli_stmt_get_result($stmt);
    if ($r && mysqli_num_rows($r) > 0) { $row = mysqli_fetch_assoc($r); $internId = $row['internId']; }
    mysqli_stmt_close($stmt);
}

// Check overlap (exclude current record)
$overlapDay = null;
$d    = strtotime($startDate);
$dEnd = strtotime($endDate);
while ($d <= $dEnd) {
    $dVal = date('Y-m-d', $d);
    $stmt = mysqli_prepare($con, "SELECT id FROM internAllocations WHERE internId = ? AND id != ? AND startDate <= ? AND endDate >= ? AND allocStartTime < ? AND allocEndTime > ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "sissss", $internId, $allocationId, $dVal, $dVal, $allocEndTime, $allocStartTime);
    mysqli_stmt_execute($stmt);
    $overResult = mysqli_stmt_get_result($stmt);
    if ($overResult && mysqli_num_rows($overResult) > 0) { $overlapDay = $dVal; mysqli_stmt_close($stmt); break; }
    mysqli_stmt_close($stmt);
    $d = strtotime('+1 day', $d);
}
if ($overlapDay) {
    http_response_code(409);
    echo json_encode(['status' => 'error', 'message' => 'This change would create a time overlap on ' . date('d M Y', strtotime($overlapDay)) . '.']);
    exit;
}

$dailyHours = ceil(($allocEndMins - $allocStartMins) / 60);

$stmt = mysqli_prepare($con, "UPDATE internAllocations SET projectName=?, startDate=?, endDate=?, allocStartTime=?, allocEndTime=?, dailyHours=? WHERE id=?");
mysqli_stmt_bind_param($stmt, "sssssii", $projectName, $startDate, $endDate, $allocStartTime, $allocEndTime, $dailyHours, $allocationId);

if (mysqli_stmt_execute($stmt)) {
    echo json_encode(['status' => 'success', 'id' => $allocationId, 'message' => 'Assignment updated successfully.']);
} else {
    error_log('directEditProcess.php UPDATE error: ' . mysqli_error($con));
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database update failed.']);
}
mysqli_stmt_close($stmt);
mysqli_close($con);
exit;