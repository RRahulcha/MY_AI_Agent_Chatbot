<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once('../include/db_config.php');

// Suppress any warnings/notices from leaking into JSON output
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json');
header('Cache-Control: private, max-age=0, no-cache');

if (empty($_SESSION['userId'])) {
    echo json_encode(['data' => []]);
    exit;
}

$userId = $_SESSION['userId'];

// Fetch notifications for this user (latest 50, newest first)
$stmt = $con->prepare(
    "SELECT id, title, message, link, type, is_read,
            created_at,
            DATE_FORMAT(created_at, '%d %b %Y, %h:%i %p') AS formatted_created_at,
            CASE WHEN is_read = 1 THEN 'Read' ELSE 'Unread' END AS status
     FROM   notifications
     WHERE  userId = ?
     ORDER  BY created_at DESC
     LIMIT  50"
);
$stmt->bind_param('s', $userId);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo json_encode(['data' => $rows]);