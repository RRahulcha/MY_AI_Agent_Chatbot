<?php
// ajax/delete_announcement.php
// Deletes an announcement record and its recipient mapping
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

$id = (int)($_POST['id'] ?? 0);
if (!$id) {
    echo json_encode(['success' => false, 'msg' => 'Invalid announcement ID']);
    exit;
}

// Delete from master and mapping
$stmt1 = $conn->prepare("DELETE FROM announcement_master WHERE id = ?");
$stmt1->bind_param('i', $id);
$res1 = $stmt1->execute();
$stmt1->close();

$stmt2 = $conn->prepare("DELETE FROM announcement_mapping WHERE announcement_id = ?");
$stmt2->bind_param('i', $id);
$stmt2->execute();
$stmt2->close();

if ($res1) {
    echo json_encode(['success' => true, 'msg' => 'Announcement deleted successfully']);
} else {
    echo json_encode(['success' => false, 'msg' => 'Failed to delete announcement: ' . $conn->error]);
}
