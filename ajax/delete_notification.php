<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

require_once('../include/db_config.php');

// Check if user is logged in
if (!isset($_SESSION['userId'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$userId = $_SESSION['userId'];

// Get notification ID from POST
$notificationId = isset($_POST['notificationId']) ? intval($_POST['notificationId']) : 0;

if ($notificationId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid notification ID']);
    exit;
}

// Verify the notification belongs to this user before deleting
$checkQuery = mysqli_query($con, 
    "SELECT id FROM notifications 
     WHERE id = $notificationId 
     AND userId = '" . mysqli_real_escape_string($con, $userId) . "'"
);

if (!$checkQuery || mysqli_num_rows($checkQuery) === 0) {
    echo json_encode(['success' => false, 'message' => 'Notification not found or access denied']);
    exit;
}

// Delete the notification
$deleteQuery = mysqli_query($con, 
    "DELETE FROM notifications 
     WHERE id = $notificationId 
     AND userId = '" . mysqli_real_escape_string($con, $userId) . "'"
);

if ($deleteQuery) {
    // Get updated unread count
    $countQuery = mysqli_query($con,
        "SELECT COUNT(*) AS cnt FROM notifications 
         WHERE userId = '" . mysqli_real_escape_string($con, $userId) . "' 
         AND is_read = 0"
    );
    
    $unreadCount = 0;
    if ($countQuery) {
        $row = mysqli_fetch_assoc($countQuery);
        $unreadCount = intval($row['cnt']);
    }
    
    echo json_encode([
        'success' => true, 
        'message' => 'Notification deleted successfully',
        'unread_count' => $unreadCount
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to delete notification']);
}
?>