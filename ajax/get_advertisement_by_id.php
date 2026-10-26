<?php
// ajax/get_advertisement_by_id.php
ob_start();
include '../include/db_config.php';
ob_end_clean();

header('Content-Type: application/json');

if (empty($_SESSION['userId'])) {
    echo json_encode(['success' => false, 'msg' => 'Not authenticated']);
    exit;
}

$groupId = trim($_GET['id'] ?? '');
if (empty($groupId)) {
    echo json_encode(['success' => false, 'msg' => 'Invalid group ID']);
    exit;
}

$stmt = $conn->prepare("
    SELECT id, advertisement_group_id, title, description, media_type, media_path, display_location, status, start_date, end_date
    FROM advertisements
    WHERE advertisement_group_id = ? OR id = ?
");
$stmt->bind_param('ss', $groupId, $groupId);
$stmt->execute();
$res = $stmt->get_result();

$items = [];
while ($row = $res->fetch_assoc()) {
    $items[] = $row;
}
$stmt->close();

echo json_encode(['success' => true, 'data' => $items]);
