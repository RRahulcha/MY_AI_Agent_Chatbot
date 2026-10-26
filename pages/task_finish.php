<?php
session_start();
header('Content-Type: application/json');

require_once '../include/db_config.php';
date_default_timezone_set('Asia/Kolkata');

/* ------------------ AUTH CHECK ------------------ */
if (empty($_SESSION['userId'])) {
    echo json_encode(['success' => false, 'msg' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['userId'];

/* ------------------ FIND RUNNING TASK ------------------ */
$stmt = $con->prepare("
    SELECT taskId, startTime
    FROM TaskEntryNew
    WHERE userId = ? AND status = 'Running'
    ORDER BY startTime DESC
    LIMIT 1
");
$stmt->bind_param("s", $userId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'msg' => 'No running task found']);
    exit;
}

$row = $result->fetch_assoc();
$taskId    = $row['taskId'];
$startTime = strtotime($row['startTime']);
$endTime   = time();

/* ------------------ CALCULATE MAN HOURS ------------------ */
$diffSeconds = $endTime - $startTime;

$hours = floor($diffSeconds / 3600);
$mins  = floor(($diffSeconds % 3600) / 60);

$manHr = sprintf("%02d:%02d:00", $hours, $mins);

/* ------------------ UPDATE TASK ------------------ */
$update = $con->prepare("
    UPDATE TaskEntryNew
    SET endTime = NOW(),
        manHr   = ?,
        status  = 'Finished'
    WHERE taskId = ?
");
$update->bind_param("si", $manHr, $taskId);

if ($update->execute()) {
    echo json_encode([
        'success' => true,
        'manHr'   => $manHr
    ]);
} else {
    echo json_encode([
        'success' => false,
        'msg' => 'Failed to finish task'
    ]);
}
