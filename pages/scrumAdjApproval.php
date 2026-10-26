<?php
ob_start();
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['userId'])) {
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

include('../include/db_config.php');
ob_end_clean();

$adjId      = isset($_POST['adjId'])      ? (int)$_POST['adjId']                                     : 0;
$action     = isset($_POST['action'])     ? trim($_POST['action'])                                    : '';
$approvedBy = isset($_POST['approvedBy']) ? mysqli_real_escape_string($con, trim($_POST['approvedBy'])) : '';

if (!$adjId || !in_array($action, ['approve', 'reject', 'modify_approve'])) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid parameters', 'debug' => ['adjId' => $adjId, 'action' => $action]]);
    exit;
}

// Fetch adjustment — remove status filter so we can debug
$adjResult = mysqli_query($con, "SELECT * FROM scrumTaskAdjustments WHERE id = $adjId");
if (!$adjResult) {
    echo json_encode(['status' => 'error', 'message' => 'Query error: ' . mysqli_error($con)]);
    exit;
}

$adj = mysqli_fetch_assoc($adjResult);
if (!$adj) {
    echo json_encode(['status' => 'error', 'message' => 'No record found for id: ' . $adjId]);
    exit;
}

if ($adj['status'] !== 'pending') {
    echo json_encode(['status' => 'error', 'message' => 'Already processed. Current status: ' . $adj['status']]);
    exit;
}

$internId = mysqli_real_escape_string($con, $adj['internId']);
$date     = mysqli_real_escape_string($con, $adj['date']);

// ── REJECT ───────────────────────────────────────────────────────
if ($action === 'reject') {
    $r = mysqli_query($con, "
        UPDATE scrumTaskAdjustments
        SET status = 'rejected', approvedBy = '$approvedBy', updatedAt = NOW()
        WHERE id = $adjId
    ");
    if (!$r) {
        echo json_encode(['status' => 'error', 'message' => 'Reject failed: ' . mysqli_error($con)]);
        exit;
    }
    echo json_encode(['status' => 'success', 'message' => 'Rejected']);
    exit;
}

// ── APPROVE / MODIFY_APPROVE ──────────────────────────────────────
if ($action === 'modify_approve') {
    $proj   = mysqli_real_escape_string($con, trim($_POST['newProjectName'] ?? ''));
    $client = mysqli_real_escape_string($con, trim($_POST['newClientName']  ?? ''));
    $st     = mysqli_real_escape_string($con, trim($_POST['newStartTime']   ?? ''));
    $et     = mysqli_real_escape_string($con, trim($_POST['newEndTime']     ?? ''));
} else {
    $proj   = mysqli_real_escape_string($con, $adj['newProjectName']);
    $client = mysqli_real_escape_string($con, $adj['newClientName'] ?? '');
    $st     = mysqli_real_escape_string($con, $adj['newStartTime']);
    $et     = mysqli_real_escape_string($con, $adj['newEndTime']);
}

if (empty($proj)) {
    echo json_encode(['status' => 'error', 'message' => 'Project name is empty']);
    exit;
}

// Delete existing allocations for this intern on this date
mysqli_query($con, "
    DELETE FROM internAllocations
    WHERE internId COLLATE utf8mb4_unicode_ci = '$internId'
      AND startDate <= '$date' AND endDate >= '$date'
");

if (mysqli_error($con)) {
    echo json_encode(['status' => 'error', 'message' => 'Delete failed: ' . mysqli_error($con)]);
    exit;
}

// Insert new allocation
mysqli_query($con, "
    INSERT INTO internAllocations
        (internId, projectName, clientName, allocStartTime, allocEndTime, startDate, endDate, allocatedBy)
    VALUES
        ('$internId', '$proj', '$client', '$st', '$et', '$date', '$date', '$approvedBy')
");

if (mysqli_error($con)) {
    echo json_encode(['status' => 'error', 'message' => 'Insert failed: ' . mysqli_error($con)]);
    exit;
}

// Update adjustment status
$finalStatus = ($action === 'modify_approve') ? 'approvedModified' : 'approved';

mysqli_query($con, "
    UPDATE scrumTaskAdjustments
    SET status         = '$finalStatus',
        approvedBy     = '$approvedBy',
        newProjectName = '$proj',
        newClientName  = '$client',
        newStartTime   = '$st',
        newEndTime     = '$et',
        updatedAt      = NOW()
    WHERE id = $adjId
");

if (mysqli_error($con)) {
    echo json_encode(['status' => 'error', 'message' => 'Status update failed: ' . mysqli_error($con)]);
    exit;
}

echo json_encode(['status' => 'success', 'message' => 'Approved and applied']);