<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once('../include/db_config.php');

error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json');
header('Cache-Control: private, max-age=0, no-cache');

if (empty($_SESSION['userId'])) {
    echo json_encode(['notifications' => [], 'unread_count' => 0, 'total_pages' => 1, 'current_page' => 1]);
    exit;
}

$userId = $_SESSION['userId'];

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// First, get unread count and total notifications count
$countStmt = $con->prepare("
    SELECT 
        SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) AS unread_total,
        COUNT(*) AS total_items
    FROM notifications
    WHERE userId = ? 
      AND title NOT IN ('No Active Task', 'Hourly Task Check')
");
$countStmt->bind_param('s', $userId);
$countStmt->execute();
$countResult = $countStmt->get_result()->fetch_assoc();
$countStmt->close();

$unread = (int)($countResult['unread_total'] ?? 0);
$totalItems = (int)($countResult['total_items'] ?? 0);
$totalPages = ceil($totalItems / $limit);
if ($totalPages < 1) $totalPages = 1;

$stmt = $con->prepare(
    "SELECT id, title, message, link, type, is_read,
            DATE_FORMAT(created_at, '%d %b %Y, %h:%i %p') AS created_at
     FROM   notifications
     WHERE  userId = ?
       AND title NOT IN ('No Active Task', 'Hourly Task Check')
     ORDER  BY is_read ASC, id DESC
     LIMIT  ? OFFSET ?"
);
$stmt->bind_param('sii', $userId, $limit, $offset);
$stmt->execute();
$notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo json_encode([
    'notifications' => $notifications,
    'unread_count'  => $unread,
    'total_pages'   => $totalPages,
    'current_page'  => $page
]);