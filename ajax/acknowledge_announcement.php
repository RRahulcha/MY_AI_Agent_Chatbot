<?php
// ajax/acknowledge_announcement.php
// Marks an announcement as read/acknowledged (is_read = 1) for the logged-in user
ob_start();
include '../include/db_config.php';
ob_end_clean();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'msg' => 'Invalid request method']);
    exit;
}

if (empty($_SESSION['userId'])) {
    echo json_encode(['success' => false, 'msg' => 'Not authenticated']);
    exit;
}

$announcementId = (int)($_POST['announcement_id'] ?? 0);
$userId         = $_SESSION['userId'];

if (!$announcementId) {
    echo json_encode(['success' => false, 'msg' => 'Invalid announcement ID']);
    exit;
}

// Update read status for this user
$stmt = $conn->prepare("
    UPDATE announcement_mapping
    SET is_read = 1, read_date = NOW()
    WHERE announcement_id = ? AND employee_id = ?
");
$stmt->bind_param('is', $announcementId, $userId);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'msg' => 'Announcement acknowledged']);
} else {
    echo json_encode(['success' => false, 'msg' => 'Failed to acknowledge: ' . $stmt->error]);
}
$stmt->close();
