<?php
session_start();
include('../include/db_config.php');
include('../include/notifications.php'); // Required for FCM Push

function sendAppNotification($con, $toUserId, $title, $msg, $type) {
    mysqli_query($con, "INSERT INTO notifications (userId, title, message, type, is_read, created_at) VALUES ('$toUserId', '$title', '$msg', '$type', 0, NOW())");
    $userQuery = mysqli_query($con, "SELECT token FROM signup WHERE userId='$toUserId' LIMIT 1");
    if ($userQuery && mysqli_num_rows($userQuery) > 0) {
        $userData = mysqli_fetch_assoc($userQuery);
        if (!empty($userData['token']) && function_exists('sendFCMNotification')) {
            global $accessToken, $projectId;
            sendFCMNotification($userData['token'], $title, $msg, $accessToken, $projectId);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'];
    $myUserId = mysqli_real_escape_string($con, $_SESSION['userId']);

    // Get Intern's Name for the Notification
    $me_query = mysqli_query($con, "SELECT fullName FROM signup WHERE userId='$myUserId'");
    $myName = ($me_query && mysqli_num_rows($me_query) > 0) ? mysqli_fetch_assoc($me_query)['fullName'] : 'An Intern';

    if ($action == 'update_status') {
        $task_id = mysqli_real_escape_string($con, $_POST['task_id']);
        $allocator_id = mysqli_real_escape_string($con, $_POST['allocator_id']);
        $new_status = mysqli_real_escape_string($con, $_POST['new_status']);

        if(!empty($new_status)) {
            mysqli_query($con, "UPDATE task_allocation SET status='$new_status' WHERE id='$task_id'");
            
            // Notify Allocator
            sendAppNotification($con, $allocator_id, "Task Status Updated", "$myName changed their task status to: $new_status", "task");
            
            $_SESSION['msg'] = "Status Updated to " . $new_status;
            $_SESSION['status'] = true;
        }
    } 
    elseif ($action == 'req_extension') {
        $task_id = mysqli_real_escape_string($con, $_POST['task_id']);
        $allocator_id = mysqli_real_escape_string($con, $_POST['allocator_id']);
        $task_title = mysqli_real_escape_string($con, $_POST['task_title']);
        $reason = mysqli_real_escape_string($con, $_POST['reason']);

        mysqli_query($con, "UPDATE task_allocation SET ext_status='Requested', ext_reason='$reason' WHERE id='$task_id'");
        
        // Notify Allocator
        sendAppNotification($con, $allocator_id, "Extension Requested", "$myName requested a deadline extension for a task.", "task");

        $_SESSION['msg'] = "Extension Request sent to Senior.";
        $_SESSION['status'] = true;
    }

    header("Location: my_allocated_tasks.php");
    exit;
}
?>