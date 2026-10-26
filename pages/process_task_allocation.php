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
    $allocator_id = mysqli_real_escape_string($con, $_SESSION['userId']);

    // Safely get Allocator Name (Fixes the ID showing instead of name bug)
    $me_query = mysqli_query($con, "SELECT fullName FROM signup WHERE userId='$allocator_id'");
    $myName = ($me_query && mysqli_num_rows($me_query) > 0) ? mysqli_fetch_assoc($me_query)['fullName'] : 'A Senior';

    if ($action == 'allocate') {
        $receiver_id = mysqli_real_escape_string($con, $_POST['receiver_id']);
        $task_type = mysqli_real_escape_string($con, $_POST['task_type']);
        $project_name = ($task_type == 'Project work') ? mysqli_real_escape_string($con, $_POST['project_name']) : NULL;
        $task_title = mysqli_real_escape_string($con, $_POST['task_title']);
        $remark = mysqli_real_escape_string($con, $_POST['remark']);
        
        $deadline_date = mysqli_real_escape_string($con, $_POST['deadline_date']);
        $deadline_time = mysqli_real_escape_string($con, $_POST['deadline_time']);
        $deadline = $deadline_date . ' ' . $deadline_time . ':00'; 

        $status = mysqli_real_escape_string($con, $_POST['status']);
        $file_path = NULL;

        if (isset($_FILES["task_doc"]) && $_FILES["task_doc"]["error"] == 0) {
            $target_dir = "../uploads/tasks/";
            if(!is_dir($target_dir)) mkdir($target_dir, 0777, true);
            $file_name = time() . "_" . preg_replace("/[^a-zA-Z0-9.\-_]/", "", basename($_FILES["task_doc"]["name"]));
            if (move_uploaded_file($_FILES["task_doc"]["tmp_name"], $target_dir . $file_name)) {
                $file_path = "tasks/" . $file_name;
            }
        }

        $file_path_sql = $file_path ? "'$file_path'" : "NULL";
        $proj_sql = $project_name ? "'$project_name'" : "NULL";

        $q = "INSERT INTO task_allocation (allocator_id, receiver_id, task_type, project_name, task_title, remark, document_path, deadline, status) 
              VALUES ('$allocator_id', '$receiver_id', '$task_type', $proj_sql, '$task_title', '$remark', $file_path_sql, '$deadline', '$status')";

        if(mysqli_query($con, $q)) {
            // Notify Receiver
            sendAppNotification($con, $receiver_id, "New Task Assigned", "$myName assigned you a new task: $task_title", "task");
            
            // Notify Resource Manager (RM)
            $rm_query = mysqli_query($con, "SELECT userId FROM signup WHERE designation='resourceAllocator'");
            if($rm_query) {
                while($rm = mysqli_fetch_assoc($rm_query)) {
                    sendAppNotification($con, $rm['userId'], "Task Allocated", "$myName allocated a task to an intern.", "task");
                }
            }

            $_SESSION['msg'] = "Task Allocated Successfully!";
            $_SESSION['status'] = true;
        } else {
            $_SESSION['msg'] = "Database Error.";
            $_SESSION['status'] = false;
        }
    } 
    elseif ($action == 'mark_done') {
        $task_id = mysqli_real_escape_string($con, $_POST['task_id']);
        mysqli_query($con, "UPDATE task_allocation SET status='Done' WHERE id='$task_id'");
        $_SESSION['msg'] = "Task marked as Done.";
        $_SESSION['status'] = true;
    } 
    elseif ($action == 'delete') {
        $task_id = mysqli_real_escape_string($con, $_POST['task_id']);
        mysqli_query($con, "DELETE FROM task_allocation WHERE id='$task_id'");
        $_SESSION['msg'] = "Task Deleted.";
        $_SESSION['status'] = true;
    } 
    elseif ($action == 'extend_deadline') {
        $task_id = mysqli_real_escape_string($con, $_POST['task_id']);
        $receiver_id = mysqli_real_escape_string($con, $_POST['receiver_id']);
        $new_deadline_date = mysqli_real_escape_string($con, $_POST['new_deadline_date']);
        $new_deadline_time = mysqli_real_escape_string($con, $_POST['new_deadline_time']);
        $new_deadline = $new_deadline_date . ' ' . $new_deadline_time . ':00';
        
        mysqli_query($con, "UPDATE task_allocation SET deadline='$new_deadline', ext_status='Extended' WHERE id='$task_id'");
        
        // Notify Receiver
        sendAppNotification($con, $receiver_id, "Deadline Extended", "$myName approved your request and extended the deadline.", "task");
        
        $_SESSION['msg'] = "Deadline Extended & Intern Notified.";
        $_SESSION['status'] = true;
    }
    elseif ($action == 'followup') {
        $receiver_id = mysqli_real_escape_string($con, $_POST['receiver_id']);
        
        // Notify Receiver
        sendAppNotification($con, $receiver_id, "URGENT: Overdue Task", "$myName is requesting a follow-up. Your task is past its deadline.", "task");
        
        $_SESSION['msg'] = "Follow-up Notification Sent to Intern.";
        $_SESSION['status'] = true;
    }

    header("Location: task_allocation.php");
    exit;
}
?>