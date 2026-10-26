<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once('../include/db_config.php');
header('Content-Type: application/json');
// ✅ Cache for 20 s — the navbar popup only needs near-realtime data when opened
header('Cache-Control: private, max-age=20');

if (empty($_SESSION['userId'])) {
    echo json_encode([]);
    exit;
}

$userId = $_SESSION['userId'];

// ✅ OPTIMIZED: Use prepared statement (safe) + only select needed columns
$stmt = $con->prepare(
    "SELECT taskId, task, status, workType, projectName
     FROM TaskEntryNew
     WHERE userId = ?
       AND status IN ('Running','Paused')
     ORDER BY taskId DESC"
);
$stmt->bind_param('s', $userId);
$stmt->execute();
$tasks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo json_encode($tasks);