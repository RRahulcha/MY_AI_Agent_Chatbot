<?php
/**
 * deleteResourceRequest.php
 * Deletes a resource request submitted by the current user or admin/resource head.
 */

session_start();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Please log in.']);
    exit;
}

include('../include/db_config.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['requestId'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request parameters.']);
    exit;
}

$reqId  = (int)$_POST['requestId'];
$userId = $_SESSION['userId'];
$userRole  = strtolower($_SESSION['userRole'] ?? '');
$userDesig = strtolower($_SESSION['designation'] ?? '');

$isAdminOrRH = in_array($userRole, ['admin', 'superadmin', 'resourceallocator', 'resourcehead', 'resourcemanager', 'resource allocator', 'resource head', 'resource manager', 'md', 'hr']) ||
               in_array($userDesig, ['admin', 'superadmin', 'resourceallocator', 'resourcehead', 'resourcemanager', 'resource allocator', 'resource head', 'resource manager', 'md', 'hr']);

// Verify request exists
$stmt = $con->prepare("SELECT id, requestedBy, status FROM resourceRequests WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $reqId);
$stmt->execute();
$res = $stmt->get_result();

if (!$res || $res->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Resource request not found.']);
    exit;
}

$row = $res->fetch_assoc();

// Resolve user identifier variations for ownership check
$userRow = mysqli_fetch_assoc(mysqli_query($con, "
    SELECT userId, internId, username, fullName 
    FROM signup 
    WHERE userId = '" . mysqli_real_escape_string($con, $userId) . "' 
       OR username = '" . mysqli_real_escape_string($con, $userId) . "' 
       OR internId = '" . mysqli_real_escape_string($con, $userId) . "' 
    LIMIT 1
"));

$userMatchIds = array_map('strtolower', array_map('trim', array_filter([
    (string)$userId,
    (string)($userRow['userId'] ?? ''),
    (string)($userRow['internId'] ?? ''),
    (string)($userRow['username'] ?? ''),
    (string)($userRow['fullName'] ?? '')
])));

$reqUser = strtolower(trim((string)$row['requestedBy']));
$isOwner = in_array($reqUser, $userMatchIds);

// Security check: Only the requester or admin/resource head can delete
if (!$isAdminOrRH && !$isOwner) {
    echo json_encode(['success' => false, 'message' => 'You do not have permission to delete this request.']);
    exit;
}

// Perform deletion
$delStmt = $con->prepare("DELETE FROM resourceRequests WHERE id = ? LIMIT 1");
$delStmt->bind_param("i", $reqId);

if ($delStmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Resource request deleted successfully.']);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error while deleting request.']);
}
