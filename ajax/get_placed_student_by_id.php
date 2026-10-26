<?php
// ajax/get_placed_student_by_id.php
ob_start();
include '../include/db_config.php';
ob_end_clean();

header('Content-Type: application/json');

if (empty($_SESSION['userId'])) {
    echo json_encode(['success' => false, 'msg' => 'Not authenticated']);
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    echo json_encode(['success' => false, 'msg' => 'Invalid ID']);
    exit;
}

$stmt = $conn->prepare("SELECT * FROM placements WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$res = $stmt->get_result();

if ($row = $res->fetch_assoc()) {
    echo json_encode(['success' => true, 'data' => $row]);
} else {
    echo json_encode(['success' => false, 'msg' => 'Record not found']);
}
$stmt->close();
