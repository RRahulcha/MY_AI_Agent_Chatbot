<?php
// ajax/toggle_advertisement_status.php
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

$id     = (int)($_POST['id'] ?? 0);
$status = (int)($_POST['status'] ?? 0);

if (!$id) {
    echo json_encode(['success' => false, 'msg' => 'Invalid ID']);
    exit;
}

$stmt = $conn->prepare("UPDATE advertisements SET status = ? WHERE id = ?");
$stmt->bind_param('ii', $status, $id);

if ($stmt->execute()) {
    $stText = $status ? 'activated' : 'paused';
    echo json_encode(['success' => true, 'msg' => "Advertisement {$stText} successfully!"]);
} else {
    echo json_encode(['success' => false, 'msg' => 'Failed to update status']);
}
$stmt->close();
