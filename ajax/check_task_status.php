<?php
if (session_status() === PHP_SESSION_NONE) session_start();
include('../include/db_config.php');

error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json');
// Cache for 15 s — badge counts don't need to be real-time
header('Cache-Control: private, max-age=15');

$userId = $_SESSION['userId'] ?? 0;

// ✅ OPTIMIZED: One query with conditional SUMs instead of 3 separate COUNT queries.
//    Cuts 3 round-trips to the DB down to 1.
$stmt = $con->prepare(
    "SELECT
        SUM(status = 'Running')  AS countRunning,
        SUM(status = 'Paused')   AS countPause,
        SUM(status = 'Finished') AS countEnd
     FROM TaskEntryNew
     WHERE userId = ?"
);
$stmt->bind_param('s', $userId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$countRunning = (int)($row['countRunning'] ?? 0);
$countPause   = (int)($row['countPause']   ?? 0);
$countEnd     = (int)($row['countEnd']     ?? 0);

echo json_encode([
    'hasRunning'   => $countRunning > 0,
    'hasPause'     => $countPause   > 0,
    'hasEnd'       => $countEnd     > 0,
    'countRunning' => $countRunning,
    'countPause'   => $countPause,
    'countEnd'     => $countEnd,
]);
?>