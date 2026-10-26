<?php
if (session_status() === PHP_SESSION_NONE) session_start();
include('../include/db_config.php');

header('Content-Type: application/json');

if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

// ── Mark All as Read ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'markAll') {
    $userId = $_SESSION['userId'];
    $stmt = $con->prepare("UPDATE notifications SET is_read = 1, status = 'read' WHERE userId = ? AND is_read = 0");
    $stmt->bind_param('s', $userId);
    if ($stmt->execute()) {
        $affected = $stmt->affected_rows;
        echo json_encode([
            'success' => true,
            'message' => $affected > 0 ? "All {$affected} notification(s) marked as read." : "No unread notifications."
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error.']);
    }
    $stmt->close();
    $con->close();
    exit;
}

// ── Mark Single as Read ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['notificationId'])) {
    $notificationId = (int)$_POST['notificationId'];
    $userId         = $_SESSION['userId'];

    // ✅ OPTIMIZED: ownership check folded into the UPDATE WHERE clause.
    //    One query instead of SELECT + UPDATE. affected_rows tells us if the
    //    row existed and belonged to this user.
    $stmt = $con->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND userId = ?");
    $stmt->bind_param('is', $notificationId, $userId);
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        echo json_encode(['success' => true, 'message' => 'Notification marked as read.']);
    } elseif ($stmt->affected_rows === 0) {
        // Row not found, not owned by this user, or already read
        echo json_encode(['success' => false, 'message' => 'Notification not found or already read.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to mark notification as read.']);
    }
    $stmt->close();
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
}
$con->close();
?>