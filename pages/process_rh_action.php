<?php
session_start();
include('../include/db_config.php');
include('../include/notifications.php'); 

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
    $id = mysqli_real_escape_string($con, $_POST['id']);
    $userId = mysqli_real_escape_string($con, $_POST['userId']);
    $action = $_POST['action'];

    if ($action == 'approve') {
        $query = "UPDATE exit_process SET status = 'Approved', rejection_remark = NULL WHERE id = '$id'";
        $_SESSION['msg'] = "Approved Successfully.";
        $title = "Exit Process Approved";
        $msg = "Resource Head Approved an exit process. You can issue the letter.";
    } elseif ($action == 'reject') {
        $remark = mysqli_real_escape_string($con, $_POST['remark']);
        $query = "UPDATE exit_process SET status = 'Rejected', rejection_remark = '$remark' WHERE id = '$id'";
        $_SESSION['msg'] = "Rejected. HR has been notified to fix the issues.";
        $title = "Exit Process Rejected";
        $msg = "Resource Head Rejected an exit process. Reason: $remark";
    }

    if (mysqli_query($con, $query)) {
        // Send Notification to HR
        $hrId = '66cc22b9427a8'; 
        sendAppNotification($con, $hrId, $title, $msg, "exit_process");

        $_SESSION['status'] = true;
    } else {
        $_SESSION['status'] = false;
        $_SESSION['msg'] = "Error updating status.";
    }
    header("Location: rh_exit_approvals.php");
    exit();
}
?>