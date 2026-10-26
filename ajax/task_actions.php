<?php
session_start();
include('../include/db_config.php');
date_default_timezone_set('Asia/Kolkata');

header('Content-Type: application/json');

$userId = $_SESSION['juniorId'] ?? ($_SESSION['userId'] ?? 0);

$response = ['success' => false, 'message' => 'Invalid request'];

if ($userId === 0) {
    echo json_encode(['success' => false, 'message' => 'User not logged in']);
    exit;
}

if (isset($_POST['action'])) {
    $action = $_POST['action'];

    // ✅ START TASK
    if ($action === 'start') {

    $workType = mysqli_real_escape_string($con, $_POST['workType'] ?? '');
    $task = mysqli_real_escape_string($con, $_POST['task'] ?? '');
    $remark = mysqli_real_escape_string($con, $_POST['remark'] ?? '');
    $projectId = mysqli_real_escape_string($con, $_POST['projectId'] ?? '');
    $startTime = date('Y-m-d H:i:s');

    // If Project Work → Fetch projectName & client name
    $projectName = null;
    if ($workType === "Project Work" && $projectId != "") {
        $p = mysqli_query($con, "SELECT projectName, client FROM projectMaster WHERE projectId='$projectId' LIMIT 1");
        if ($p && $r = mysqli_fetch_assoc($p)) {
            $pName  = trim($r['projectName'] ?? '');
            $client = trim($r['client'] ?? '');
            if (!empty($client) && strtoupper($client) !== 'NA') {
                $projectName = "{$pName} - {$client}";
            } else {
                $projectName = $pName;
            }
        }
    }

    // ▼ Check for running task
    $checkQuery = mysqli_query($con, "SELECT taskId FROM TaskEntryNew WHERE userId='$userId' AND status='Running' LIMIT 1");
    if (mysqli_num_rows($checkQuery) > 0) {
        echo json_encode([
            'success' => false,
            'message' => 'You already have a running task. Please pause or finish it first.'
        ]);
        exit;
    }

    // ▼ Insert into DB with projectName
    $sql = "INSERT INTO TaskEntryNew 
            (userId, projectName, workType, task, remark, startTime, status, totalDuration)
            VALUES 
            ('$userId', '$projectName', '$workType', '$task', '$remark', '$startTime', 'Running', 0)";

    if (mysqli_query($con, $sql)) {
        // Insert first running entry into taskStatus
        $taskId = mysqli_insert_id($con);
        $now = date('Y-m-d H:i:s');

        if (mysqli_query($con, "
            INSERT INTO taskStatus (taskId, resumeTime, pauseTime, manHours, status, dateTime, userId)
            VALUES ('$taskId', '$now', '1970-01-01 00:00:00', '00:00:00', 'Started', '$now', '$userId')
        ")) {
            $response = ['success' => true, 'message' => 'Task started successfully'];
        } else {
            $response = ['success' => false, 'message' => mysqli_error($con)];
        }
    } else {
        $response = ['success' => false, 'message' => mysqli_error($con)];
    }
}

    // ✅ UPDATE STATUS
    elseif ($action === 'updateStatus') {
        $taskId = intval($_POST['id'] ?? 0);
        $status = mysqli_real_escape_string($con, $_POST['status'] ?? '');
        $now = date('Y-m-d H:i:s');

        if ($taskId === 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid task ID']);
            exit;
        }

        // Get current task info
        $result = mysqli_query($con, "SELECT startTime, totalDuration, status FROM TaskEntryNew WHERE taskId='$taskId' AND userId='$userId'");
        $task = mysqli_fetch_assoc($result);
        if (!$task) {
            echo json_encode(['success' => false, 'message' => 'Task not found']);
            exit;
        }

        // Get latest open segment from taskStatus
        $seg = mysqli_query($con, "
            SELECT resumeTime 
            FROM taskStatus 
            WHERE taskId='$taskId' AND pauseTime='1970-01-01 00:00:00'
            ORDER BY id DESC LIMIT 1
        ");
        $segRow = mysqli_fetch_assoc($seg);
        $resumeTime = $segRow ? strtotime($segRow['resumeTime']) : null;

        $prevTotal = (int)$task['totalDuration'];

        // Duration to add only if closing a running segment
        $elapsed = ($task['status'] === 'Running' && $resumeTime) ? (time() - $resumeTime) : 0;
        $newTotal = $prevTotal + $elapsed;

        // -----------------------------------------
        // 🚨 NEW ADDITION: Stop resume if other task is running
        // -----------------------------------------
        if ($status === 'Running') {

            // Check if any other task is running
            $checkRunning = mysqli_query($con, "
                SELECT taskId 
                FROM TaskEntryNew 
                WHERE userId='$userId' 
                AND status='Running' 
                AND taskId != '$taskId'
                LIMIT 1
            ");

            if (mysqli_num_rows($checkRunning) > 0) {
                echo json_encode([
                    'success' => false,
                    'message' => 'You already have another running task. Pause or finish it first.'
                ]);
                exit;
            }
        }
        // -----------------------------------------

        if ($status === 'Paused') {
            // Close current segment
            mysqli_query($con, "
                UPDATE taskStatus
                SET pauseTime='$now', manHours=SEC_TO_TIME(TIME_TO_SEC('$now') - TIME_TO_SEC(resumeTime)), status='Paused'
                WHERE taskId='$taskId' AND pauseTime='1970-01-01 00:00:00'
            ");

            // Update main table
            $sql = "UPDATE TaskEntryNew 
                    SET status='Paused', totalDuration='$newTotal'
                    WHERE taskId='$taskId' AND userId='$userId'";
        } 
        elseif ($status === 'Running') {
            // Start new running segment
            mysqli_query($con, "
                INSERT INTO taskStatus (taskId, resumeTime, pauseTime, manHours, status, dateTime, userId)
                VALUES ('$taskId', '$now', '1970-01-01 00:00:00', '00:00:00', 'Running', '$now', '$userId')
            ");

            $sql = "UPDATE TaskEntryNew 
                    SET status='Running'
                    WHERE taskId='$taskId' AND userId='$userId'";
        } 
        elseif ($status === 'Finished') {
            // Close final segment
            mysqli_query($con, "
                UPDATE taskStatus
                SET pauseTime='$now', manHours=SEC_TO_TIME(TIME_TO_SEC('$now') - TIME_TO_SEC(resumeTime)), status='Finished'
                WHERE taskId='$taskId' AND pauseTime='1970-01-01 00:00:00'
            ");

            $sql = "UPDATE TaskEntryNew 
                    SET status='Finished', endTime='$now', totalDuration='$newTotal'
                    WHERE taskId='$taskId' AND userId='$userId'";
        }

        if (mysqli_query($con, $sql)) {
            $response = ['success' => true, 'message' => "Status updated to $status"];
        } else {
            $response = ['success' => false, 'message' => mysqli_error($con)];
        }
    }
}

echo json_encode($response);
exit;
?>
