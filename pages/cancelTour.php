<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    http_response_code(403);
    exit('forbidden');
}
require_once('../include/db_config.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('method not allowed');
}

$tourId = isset($_POST['tourId']) ? (int)$_POST['tourId'] : 0;
$cancelReason = trim($_POST['cancelReason'] ?? '');

if ($tourId <= 0 || $cancelReason === '') {
    http_response_code(422);
    exit('missing fields');
}

$userId = $_SESSION['userId'];

// Only allow cancel if it belongs to the user and not Approved/Rejected/Cancel already
$sql = "UPDATE tour
        SET status = 'Cancelled', cancelReason = ?
        WHERE id = ? AND userId = ? AND status NOT IN ('Rejected', 'Cancelled')";
$stmt = $con->prepare($sql);
$stmt->bind_param('sis', $cancelReason, $tourId, $userId);

if (!$stmt->execute()) {
    http_response_code(500);
    exit('error');
}

$affected = $stmt->affected_rows;
$stmt->close();

if ($affected > 0) {
    // Automatically delete associated tour attendance records to clean up punch-ins
    $sqlDelete = "DELETE FROM tourAttendance WHERE tourId = ? AND userId = ?";
    $stmtDelete = $con->prepare($sqlDelete);
    if ($stmtDelete) {
        $stmtDelete->bind_param('is', $tourId, $userId);
        $stmtDelete->execute();
        $stmtDelete->close();
    }
    echo 'success';
} else {
    echo 'nochange';
}
$con->close();
