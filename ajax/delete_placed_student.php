<?php
// ajax/delete_placed_student.php
ob_start();
include '../include/db_config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (ob_get_length()) ob_clean();
    echo json_encode(['success' => false, 'msg' => 'Invalid request method']);
    exit;
}

if (empty($_SESSION['userId'])) {
    if (ob_get_length()) ob_clean();
    echo json_encode(['success' => false, 'msg' => 'Not authenticated']);
    exit;
}

$id = (int)($_POST['id'] ?? 0);
if (!$id) {
    if (ob_get_length()) ob_clean();
    echo json_encode(['success' => false, 'msg' => 'Invalid ID']);
    exit;
}

// Unlink photo file if exists
$stmt = $conn->prepare("SELECT photo_path FROM placements WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
    if (!empty($row['photo_path']) && file_exists('../pages/' . $row['photo_path'])) {
        @unlink('../pages/' . $row['photo_path']);
    }
}
$stmt->close();

// Perform Delete
$del = $conn->prepare("DELETE FROM placements WHERE id = ?");
$del->bind_param('i', $id);
$execRes = $del->execute();
$err = $del->error;
$del->close();

if ($execRes) {
    if (ob_get_length()) ob_clean();
    echo json_encode(['success' => true, 'msg' => 'Placed student record deleted successfully']);
    exit;
} else {
    if (ob_get_length()) ob_clean();
    echo json_encode(['success' => false, 'msg' => 'Failed to delete record: ' . $err]);
    exit;
}