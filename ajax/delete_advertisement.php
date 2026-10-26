<?php
// ajax/delete_advertisement.php
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
    echo json_encode(['success' => false, 'msg' => 'Invalid ID']);
    exit;
}

// Fetch file path to delete file
$stmt = $conn->prepare("SELECT media_path FROM advertisements WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
    if ($row['media_path'] && file_exists('../pages/' . $row['media_path'])) {
        @unlink('../pages/' . $row['media_path']);
    }
}
$stmt->close();

$del = $conn->prepare("DELETE FROM advertisements WHERE id = ?");
$del->bind_param('i', $id);
$res = $del->execute();
$del->close();

if ($res) {
    echo json_encode(['success' => true, 'msg' => 'Advertisement deleted successfully']);
} else {
    echo json_encode(['success' => false, 'msg' => 'Failed to delete advertisement']);
}
