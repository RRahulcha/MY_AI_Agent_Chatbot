<?php
if (session_status() === PHP_SESSION_NONE)
    session_start();
require_once('../include/db_config.php');

error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json');
header('Cache-Control: private, max-age=0, no-cache');

if (empty($_SESSION['userId'])) {
    session_write_close();
    echo json_encode(['unread_count' => 0]);
    exit;
}

$userId = $_SESSION['userId'];
session_write_close(); // ⚡ Release session file lock immediately to prevent blocking concurrent PHP requests

// Get unread count and max ID using indexed query
$stmt = $con->prepare("SELECT COUNT(*) AS unread_total, MAX(id) AS max_id FROM notifications WHERE userId = ? AND is_read = 0 AND title NOT IN ('No Active Task', 'Hourly Task Check')");
$stmt->bind_param('s', $userId);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$stmt->close();
$unread = (int) ($result['unread_total'] ?? 0);
$maxId  = (int) ($result['max_id'] ?? 0);

$newNotifications = [];
if (isset($_GET['last_id']) && (int) $_GET['last_id'] > 0) {
    $lastId = (int) $_GET['last_id'];
    $fetchStmt = $con->prepare("SELECT id, title, message FROM notifications WHERE userId = ? AND id > ? AND is_read = 0 AND created_at >= NOW() - INTERVAL 1 DAY AND title NOT IN ('No Active Task', 'Hourly Task Check') ORDER BY id ASC LIMIT 5");
    $fetchStmt->bind_param('si', $userId, $lastId);
    $fetchStmt->execute();
    $newNotifications = $fetchStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $fetchStmt->close();
}

echo json_encode([
    'unread_count' => $unread,
    'max_id' => $maxId,
    'new_notifications' => $newNotifications
]);