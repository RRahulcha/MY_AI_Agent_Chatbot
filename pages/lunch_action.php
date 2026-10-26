<?php
session_start();
header('Content-Type: application/json');
require_once '../include/db_config.php';

date_default_timezone_set('Asia/Kolkata');

if (empty($_SESSION['userId'])) {
    echo json_encode(['success' => false, 'msg' => 'Session expired']);
    exit;
}

$userId = $_SESSION['userId'];
$type = $_POST['type'] ?? '';
$today  = date('Y-m-d');
$now    = date('Y-m-d H:i:s'); // ✅ IST SAFE

if (!in_array($type, ['start', 'end'])) {
    echo json_encode(['success' => false, 'msg' => 'Invalid action']);
    exit;
}

/* 🔍 Fetch today's Lunch task (NO DATE()) */
$lunchQuery = mysqli_query($con, "
    SELECT taskId, status
    FROM TaskEntryNew
    WHERE userId='$userId'
      AND task='Lunch Break'
      AND startTime >= '$today 00:00:00'
      AND startTime <= '$today 23:59:59'
    ORDER BY taskId DESC
    LIMIT 1
");

$lunch = mysqli_fetch_assoc($lunchQuery);

/* ================= START LUNCH ================= */
if ($type === 'start') {

// ⏰ Lunch allowed only after 10:00 AM
$currentTime = date('H:i');

if ($currentTime < '10:00') {
    echo json_encode([
        'success' => false,
        'msg' => 'Lunch break can be started only after 10:00 AM'
    ]);
    exit;
}


    // 🔒 CHECK: any running task other than Lunch Break
    $runningTask = mysqli_query($con, "
        SELECT taskId, task
        FROM TaskEntryNew
        WHERE userId = '$userId'
          AND status = 'Running'
          AND task != 'Lunch Break'
        LIMIT 1
    ");

    if ($runningTask && mysqli_num_rows($runningTask) > 0) {
        echo json_encode([
            'success' => false,
            'msg' => 'Please pause your current task before starting Lunch.'
        ]);
        exit;
    }

    // ❌ Lunch already running
    if ($lunch && $lunch['status'] === 'Running') {
        echo json_encode(['success' => false, 'msg' => 'Lunch already started']);
        exit;
    }

    // ❌ Lunch already completed
    if ($lunch && $lunch['status'] === 'Finished') {
        echo json_encode(['success' => false, 'msg' => 'Lunch already completed']);
        exit;
    }

    // ✅ Start Lunch
    $insert = mysqli_query($con, "
        INSERT INTO TaskEntryNew
        (userId, projectName, workType, task, startTime, status)
        VALUES
        ('$userId', NULL, 'Lunch Break', 'Lunch Break', '$now', 'Running')
    ");

    if (!$insert) {
        echo json_encode(['success' => false, 'msg' => mysqli_error($con)]);
        exit;
    }

    $taskId = mysqli_insert_id($con);
    mysqli_query($con, "
        INSERT INTO taskStatus (taskId, resumeTime, pauseTime, manHours, status, dateTime, userId)
        VALUES ('$taskId', '$now', '1970-01-01 00:00:00', '00:00:00', 'Started', '$now', '$userId')
    ");

    echo json_encode(['success' => true]);
    exit;
}


/* ================= END LUNCH ================= */
if ($type === 'end') {

    if (!$lunch || $lunch['status'] !== 'Running') {
        echo json_encode(['success' => false, 'msg' => 'Lunch not started']);
        exit;
    }

    // Get current task info
    $result = mysqli_query($con, "SELECT startTime, totalDuration, status FROM TaskEntryNew WHERE taskId='{$lunch['taskId']}' AND userId='$userId'");
    $task = mysqli_fetch_assoc($result);
    if (!$task) {
        echo json_encode(['success' => false, 'message' => 'Task not found']);
        exit;
    }

    // Get latest open segment from taskStatus
    $seg = mysqli_query($con, "
            SELECT resumeTime 
            FROM taskStatus 
            WHERE taskId='{$lunch['taskId']}' AND pauseTime='1970-01-01 00:00:00'
            ORDER BY id DESC LIMIT 1
        ");
    $segRow = mysqli_fetch_assoc($seg);
    $resumeTime = $segRow ? strtotime($segRow['resumeTime']) : null;

    $prevTotal = (int)$task['totalDuration'];

    // Duration to add only if closing a running segment
    $elapsed = ($task['status'] === 'Running' && $resumeTime) ? (time() - $resumeTime) : 0;
    $newTotal = $prevTotal + $elapsed;

    $update = mysqli_query($con, "
        UPDATE TaskEntryNew
        SET endTime = '$now', status = 'Finished', totalDuration = '$newTotal'
        WHERE taskId = '{$lunch['taskId']}'
    ");

    mysqli_query($con, "
        UPDATE taskStatus
        SET pauseTime='$now', manHours=SEC_TO_TIME(TIME_TO_SEC('$now') - TIME_TO_SEC(resumeTime)), status='Finished'
        WHERE taskId='{$lunch['taskId']}' AND pauseTime='1970-01-01 00:00:00'
    ");


    if (!$update) {
        echo json_encode(['success' => false, 'msg' => mysqli_error($con)]);
        exit;
    }

    echo json_encode(['success' => true]);
    exit;
}
