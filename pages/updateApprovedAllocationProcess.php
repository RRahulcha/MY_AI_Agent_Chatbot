<?php
session_start();

if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['status'] = false;
    $_SESSION['msg']    = "Invalid request method.";
    echo "<script>window.location.href = 'resourceApprovals.php';</script>";
    exit;
}

include('../include/db_config.php');
require_once('availabilityEngine.php');

$userRole  = strtolower(trim($_SESSION['userRole'] ?? ''));
$userDesig = strtolower(trim($_SESSION['designation'] ?? ''));
$allowedAllocProc = ['resourceallocator', 'resource_allocator', 'resource allocator', 'resourcemanager', 'resource_manager', 'resourcehead', 'resource_head', 'admin', 'superadmin', 'hr'];
if (!in_array($userRole, $allowedAllocProc) && !in_array($userDesig, $allowedAllocProc)) {
    $_SESSION['status'] = false;
    $_SESSION['msg']    = "Unauthorized: Only Resource Managers can edit approved requests.";
    echo "<script>window.location.href = 'resourceApprovals.php';</script>";
    exit;
}

$requestId    = intval($_POST['requestId']    ?? 0);
$allocationId = intval($_POST['allocationId'] ?? 0);
$startDate    = trim(mysqli_real_escape_string($con, $_POST['startDate']      ?? ''));
$endDate      = trim(mysqli_real_escape_string($con, $_POST['endDate']        ?? ''));
$allocST      = trim(mysqli_real_escape_string($con, $_POST['allocStartTime'] ?? ''));
$allocET      = trim(mysqli_real_escape_string($con, $_POST['allocEndTime']   ?? ''));

if ($requestId <= 0 || empty($startDate) || empty($endDate) || empty($allocST) || empty($allocET)) {
    $_SESSION['status'] = false;
    $_SESSION['msg']    = "❌ Edit failed: Missing required fields.";
    echo "<script>window.location.href = 'resourceApprovals.php';</script>";
    exit;
}

if (strlen($allocST) === 5) $allocST .= ':00';
if (strlen($allocET) === 5) $allocET .= ':00';

if (strtotime($endDate) < strtotime($startDate)) {
    $_SESSION['status'] = false;
    $_SESSION['msg']    = "❌ Edit failed: End date cannot be before start date.";
    echo "<script>window.location.href = 'resourceApprovals.php';</script>";
    exit;
}

$stMins = timeToMinutes($allocST);
$etMins = timeToMinutes($allocET);
if ($stMins === false || $etMins === false || $etMins <= $stMins) {
    $_SESSION['status'] = false;
    $_SESSION['msg']    = "❌ Edit failed: End time must be after start time.";
    echo "<script>window.location.href = 'resourceApprovals.php';</script>";
    exit;
}

// Fetch the existing resource request (must be Approved)
$reqQ = mysqli_query($con, "SELECT * FROM resourceRequests WHERE id='$requestId' AND status='Approved' LIMIT 1");
if (!$reqQ || mysqli_num_rows($reqQ) === 0) {
    $_SESSION['status'] = false;
    $_SESSION['msg']    = "❌ Approved request not found or not in Approved status.";
    echo "<script>window.location.href = 'resourceApprovals.php';</script>";
    exit;
}
$request  = mysqli_fetch_assoc($reqQ);
$internId = mysqli_real_escape_string($con, $request['internId']);
$projName = mysqli_real_escape_string($con, $request['projectName']);

// Resolve allocationId if not provided
if ($allocationId <= 0) {
    $oldStart = mysqli_real_escape_string($con, $request['startDate']);
    $allocCheck = mysqli_query($con,
        "SELECT id FROM internAllocations
         WHERE internId = '$internId' AND projectName = '$projName' AND startDate = '$oldStart'
         ORDER BY id DESC LIMIT 1"
    );
    if ($allocCheck && mysqli_num_rows($allocCheck) > 0) {
        $allocRow     = mysqli_fetch_assoc($allocCheck);
        $allocationId = (int)$allocRow['id'];
    }
}

// Run validation against leaves, tours, holidays, and other allocations (excluding $allocationId)
$val = validateAllocationRequest(
    $con,
    $internId,
    $startDate,
    $endDate,
    $allocST,
    $allocET,
    $allocationId > 0 ? $allocationId : null,
    false
);

if (!$val['valid']) {
    $_SESSION['status'] = false;
    $_SESSION['msg']    = "❌ Cannot update — conflicts detected: " . implode(" | ", $val['errors']);
    echo "<script>window.location.href = 'resourceApprovals.php';</script>";
    exit;
}

$dailyHours = (int)ceil(($etMins - $stMins) / 60);

// Update resourceRequests
$upReq = mysqli_query($con, "
    UPDATE resourceRequests
    SET startDate      = '$startDate',
        endDate        = '$endDate',
        allocStartTime = '$allocST',
        allocEndTime   = '$allocET',
        dailyHours     = '$dailyHours'
    WHERE id = '$requestId'
");

// Update internAllocations
if ($allocationId > 0) {
    $upAlloc = mysqli_query($con, "
        UPDATE internAllocations
        SET startDate      = '$startDate',
            endDate        = '$endDate',
            allocStartTime = '$allocST',
            allocEndTime   = '$allocET',
            dailyHours     = '$dailyHours'
        WHERE id = '$allocationId'
    ");
} else {
    // If allocationId wasn't found by ID, update matching intern & project row
    $oldStart = mysqli_real_escape_string($con, $request['startDate']);
    $upAlloc = mysqli_query($con, "
        UPDATE internAllocations
        SET startDate      = '$startDate',
            endDate        = '$endDate',
            allocStartTime = '$allocST',
            allocEndTime   = '$allocET',
            dailyHours     = '$dailyHours'
        WHERE internId = '$internId' AND projectName = '$projName' AND startDate = '$oldStart'
    ");
}

if ($upReq) {
    $_SESSION['status'] = true;
    $dateStr = date('d M Y', strtotime($startDate)) . ' to ' . date('d M Y', strtotime($endDate));
    $timeStr = to12hr(substr($allocST, 0, 5)) . ' – ' . to12hr(substr($allocET, 0, 5));
    $_SESSION['msg']    = "✅ Approved request updated successfully ({$dateStr}, {$timeStr}, {$dailyHours} hr/day).";
} else {
    $_SESSION['status'] = false;
    $_SESSION['msg']    = "Failed to update approved request. Please try again.";
}

echo "<script>window.location.href = 'resourceApprovals.php';</script>";
exit;
