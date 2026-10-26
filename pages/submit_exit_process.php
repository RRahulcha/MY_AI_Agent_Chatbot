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
    $userId = mysqli_real_escape_string($con, $_POST['userId']);
    $attendance = mysqli_real_escape_string($con, $_POST['attendance_percentage']);
    $tasks = mysqli_real_escape_string($con, $_POST['tasks_completed']);
    $reason = mysqli_real_escape_string($con, $_POST['reason']);
    $feedback = mysqli_real_escape_string($con, $_POST['feedback']);
    
    // Checkboxes
    $tech = isset($_POST['tech_handover']) ? 1 : 0;
    $access = isset($_POST['access_revoked']) ? 1 : 0;
    $interview = isset($_POST['exit_interview']) ? 1 : 0; 
    
    $file_path = NULL;

    if (isset($_FILES["exp_letter"]) && $_FILES["exp_letter"]["error"] == 0) {
        $target_dir = "../uploads/exp_letters/";
        if(!is_dir($target_dir)) mkdir($target_dir, 0777, true);
        $file_name = time() . "_" . basename($_FILES["exp_letter"]["name"]);
        $target_file = $target_dir . $file_name;
        if (move_uploaded_file($_FILES["exp_letter"]["tmp_name"], $target_file)) {
            $file_path = "exp_letters/" . $file_name;
        }
    }

    $file_path_sql = $file_path ? "'$file_path'" : "NULL";
    
    // Insert into the exit process table
    $query = "INSERT INTO exit_process (userId, reason_for_leaving, intern_feedback, attendance_percentage, tasks_completed, tech_handover, access_revoked, exit_interview, exp_letter_path, status) 
              VALUES ('$userId', '$reason', '$feedback', '$attendance', '$tasks', '$tech', '$access', '$interview', $file_path_sql, 'Pending RH Approval')";
    
    if (mysqli_query($con, $query)) {
        
        // =========================================================================
        // SMART COPY: Move Intern from `signup` to `delete_data` safely
        // =========================================================================
        
        // 1. Get valid columns that actually exist in the delete_data table
        $validColumns = [];
        $colQuery = mysqli_query($con, "SHOW COLUMNS FROM delete_data");
        while ($colRow = mysqli_fetch_assoc($colQuery)) {
            $validColumns[] = $colRow['Field'];
        }

        // 2. Set their status to Inactive so it carries over
        mysqli_query($con, "UPDATE signup SET status = 'Inactive' WHERE userId = '$userId'");
        
        // 3. Fetch data from signup and ONLY copy matching columns
        $userRowQuery = mysqli_query($con, "SELECT * FROM signup WHERE userId='$userId'");
        if ($userRow = mysqli_fetch_assoc($userRowQuery)) {
            $cols = [];
            $vals = [];
            
            foreach ($userRow as $colName => $val) {
                // Ignore auto-increment ID fields to prevent Duplicate Key crashes
                if ($colName === 'id' || $colName === 'srNo') {
                    continue; 
                }

                // Only include the column if it physically exists in delete_data table
                if (in_array($colName, $validColumns)) {
                    $cols[] = "`$colName`";
                    if (is_null($val)) {
                        $vals[] = "NULL";
                    } else {
                        $vals[] = "'" . mysqli_real_escape_string($con, $val) . "'";
                    }
                }
            }
            
            // Explicitly handle the 'delete_date' requirement
            if (in_array('delete_date', $validColumns) && !in_array('`delete_date`', $cols)) {
                $cols[] = "`delete_date`";
                $vals[] = "'" . date('Y-m-d H:i:s') . "'"; // Assigns current date/time
            }

            // Explicitly push the 'reason' if that column exists in delete_data
            if (in_array('reason', $validColumns) && !in_array('`reason`', $cols)) {
                $cols[] = "`reason`";
                $vals[] = "'" . mysqli_real_escape_string($con, $reason) . "'";
            }
            
            $colString = implode(", ", $cols);
            $valString = implode(", ", $vals);
            
            // Insert safely into delete_data table
            $insertDelete = mysqli_query($con, "INSERT INTO delete_data ($colString) VALUES ($valString)");
            
            // If copy was successful, physically delete them from signup table
            if ($insertDelete) {
                mysqli_query($con, "DELETE FROM signup WHERE userId='$userId'");
            }
        }
        
        // =========================================================================
        // SEND NOTIFICATION TO RH
        // =========================================================================
        $internName = isset($userRow['fullName']) ? $userRow['fullName'] : 'an Intern';
        
        $rhId = '662cdc02506f2'; 
        sendAppNotification($con, $rhId, "Exit Process Initiated", "HR initiated an exit process for $internName. Awaiting your approval.", "exit_process");

        $_SESSION['msg'] = "Exit process forwarded to Resource Head. Intern moved to Inactive list.";
        $_SESSION['status'] = true;
    } else {
        $_SESSION['msg'] = "Database Error: " . mysqli_error($con);
        $_SESSION['status'] = false;
    }
    
    header("Location: hr_exit_process.php");
    exit();
}
?>