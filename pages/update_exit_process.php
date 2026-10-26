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
    $exit_id = mysqli_real_escape_string($con, $_POST['exit_id']);
    $userId = mysqli_real_escape_string($con, $_POST['userId']); 
    $reason = mysqli_real_escape_string($con, $_POST['reason']);
    $feedback = mysqli_real_escape_string($con, $_POST['feedback']);
    
    $tech = isset($_POST['tech_handover']) ? 1 : 0;
    $access = isset($_POST['access_revoked']) ? 1 : 0;
    $interview = isset($_POST['exit_interview']) ? 1 : 0; // Captures your new checkbox

    $file_sql = "";
    if (isset($_FILES["exp_letter"]) && $_FILES["exp_letter"]["error"] == 0) {
        $target_dir = "../uploads/exp_letters/";
        if(!is_dir($target_dir)) mkdir($target_dir, 0777, true);
        $file_name = time() . "_" . basename($_FILES["exp_letter"]["name"]);
        if (move_uploaded_file($_FILES["exp_letter"]["tmp_name"], $target_dir . $file_name)) {
            $file_path = "exp_letters/" . $file_name;
            $file_sql = ", exp_letter_path = '$file_path'";
        }
    }

    $query = "UPDATE exit_process SET reason_for_leaving = '$reason', intern_feedback = '$feedback', tech_handover = '$tech', access_revoked = '$access', exit_interview = '$interview', status = 'Pending RH Approval', rejection_remark = NULL $file_sql WHERE id = '$exit_id'";
    
    if (mysqli_query($con, $query)) {
        
        $internRes = mysqli_query($con, "SELECT fullName FROM signup WHERE userId='$userId'");
        $internName = ($internRes && mysqli_num_rows($internRes) > 0) ? mysqli_fetch_assoc($internRes)['fullName'] : 'an Intern';
        
        // Notify RH
        $rhId = '662cdc02506f2'; 
        sendAppNotification($con, $rhId, "Exit Process Resubmitted", "HR corrected and resubmitted the exit process for $internName.", "exit_process");

        $_SESSION['msg'] = "Request successfully updated and resubmitted to Resource Head.";
        $_SESSION['status'] = true;
    } else {
        $_SESSION['msg'] = "Database Error.";
        $_SESSION['status'] = false;
    }
    
    header("Location: hr_exit_process.php");
    exit();
}
?>