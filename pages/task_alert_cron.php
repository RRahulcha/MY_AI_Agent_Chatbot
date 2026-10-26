<?php
include('../include/db_config.php');
include('../include/notifications.php'); // must have sendFCMNotification()

date_default_timezone_set('Asia/Kolkata');

$current_time = date('Y-m-d H:i:s');
$today = date('Y-m-d');

echo "✅ Script running at $current_time<br>";

// Fetch axll users with at least one running task
$query = mysqli_query($con, "
    SELECT 
    t.taskId, 
    t.userId, 
    t.task, 
    t.startTime, 
    t.totalDuration, 
    s.fullName, 
    s.username, 
    s.token
FROM TaskEntryNew AS t
JOIN signup AS s 
    ON CAST(t.userId AS CHAR CHARACTER SET utf8mb4) = CAST(s.userId AS CHAR CHARACTER SET utf8mb4)
WHERE 
    t.status = 'Running'  
    AND s.regstatus = 'approved' 
    AND s.token IS NOT NULL
");

if (!$query) {
    die("Query failed: " . mysqli_error($con));
}

while ($row = mysqli_fetch_assoc($query)) {
    $taskId = $row['taskId'];
    $userId = $row['userId'];
    $task = $row['task'];
    $fullName = $row['fullName'];
    $token = $row['token'];
    $startTime = strtotime($row['startTime']);
    $totalDuration = (int) $row['totalDuration'];

    // Calculate how long the task has been running
    $elapsedSeconds = (time() - $startTime) + $totalDuration;
    $elapsedHours = $elapsedSeconds / 3600;

    // Check if running for 1 hour or more
    if ($elapsedHours >= 1) {
        // ✅ Check if we already sent a notification for this task within the last hour
$time_60_minutes_ago = date('Y-m-d H:i:s', strtotime('-60 minutes', strtotime($current_time)));
$checkNotif = mysqli_query($con, "
    SELECT id FROM push_only_log 
    WHERE userId='$userId' 
      AND notif_key='hourly_task_check' 
      AND sent_at >= '$time_60_minutes_ago'
");

if (mysqli_num_rows($checkNotif) == 0) {
            $title = "Hourly Task Check";
            $body = "Hey $fullName 👋, you've been working on '$task' for over an hour. Are you still working on it?";

echo "📨 Sending hourly alert to $fullName for task '$task'...<br>";
$response = sendFCMNotification($token, $title, $body, $accessToken, $projectId);
echo "Response: $response<br>";

// Log to push_only_log for dedup — not notifications (won't show in bell icon)
$stmt = $con->prepare("INSERT INTO push_only_log (userId, notif_key, sent_at) VALUES (?, 'hourly_task_check', NOW())");
$stmt->bind_param('s', $userId);
$stmt->execute();
$stmt->close();
        } else {
            echo "⏳ Notification already sent in the last hour for $fullName.<br>";
        }
    } else {
        echo "⌛ $fullName’s task running for less than 1 hour.<br>";
    }
}
echo "<br>";
echo "<br>⏭ Checking users with NO running tasks...<br>";

// Fetch all approved users with FCM token
$users = mysqli_query($con, "
    SELECT s.userId, s.fullName, s.username, s.token, a.punchInTime, a.date, a.punchOutTime
    FROM signup s JOIN attendance a ON s.userId = a.userId 
    WHERE s.regstatus = 'approved' AND a.date = '$today' AND a.punchOutTime = '00:00:00'
      AND (s.token IS NOT NULL OR s.token != '');
");

while ($u = mysqli_fetch_assoc($users)) {
    $uid = $u['userId'];
    $fullName = $u['fullName'];
    $token = $u['token'];

    // Check if this user has any running task
    $runningTask = mysqli_query($con, "
        SELECT taskId FROM TaskEntryNew 
        WHERE userId = '$uid' 
          AND status = 'Running'
        LIMIT 1
    ");

    if (mysqli_num_rows($runningTask) == 0) {

        $time_10_minutes_ago = date('Y-m-d H:i:s', strtotime('-10 minutes', strtotime($current_time)));

        // Check if notification already sent within last 1 hour
        $checkNotif = mysqli_query($con, "
                SELECT id FROM push_only_log
                    WHERE userId='$uid'
                    AND notif_key='no_active_task'
                    AND sent_at >= '$time_10_minutes_ago'
                ");

if (mysqli_num_rows($checkNotif) == 0) {

            $title = "No Active Task";
            $body = "Hey $fullName 👋, you don't have any active task running. Please start your task.";

echo "📨 Sending no-task alert to $fullName...<br>";
$response = sendFCMNotification($token, $title, $body, $accessToken, $projectId);
echo "Response: $response<br>";

// Log to push_only_log for dedup — not notifications (won't show in bell icon)
$stmt = $con->prepare("INSERT INTO push_only_log (userId, notif_key, sent_at) VALUES (?, 'no_active_task', NOW())");
$stmt->bind_param('s', $uid);
$stmt->execute();
$stmt->close();

        } else {
            echo "⏳ No-task alert already sent within 10 minutes for $fullName.<br>";
        }
    } else {
        echo "✔ $fullName has a running task — no alert needed.<br>";
    }
}

echo "<br>✅ Finished at " . date('H:i:s');
?>