<?php
session_start();
// Prevent PHP warnings from breaking the JSON response
error_reporting(0);
ini_set('display_errors', 0);

// Force output to be JSON
header('Content-Type: application/json');

// Ensure this path exactly matches your config path
include('../include/db_config.php');

$response = [
    'attendance' => 0,
    'tasks' => 0,
    'error' => ''
];

if(isset($_POST['userId'])) {
    $userId = mysqli_real_escape_string($con, $_POST['userId']);

    try {
        // 1. Calculate Tasks Completed (Where status is 'end' or 'completed')
        $taskQuery = mysqli_query($con, "SELECT COUNT(*) as total_completed FROM taskEntry WHERE userId = '$userId' AND (status = 'end' OR status = 'completed')");
        if($taskQuery) {
            $taskData = mysqli_fetch_assoc($taskQuery);
            $response['tasks'] = $taskData['total_completed'] ? (int)$taskData['total_completed'] : 0;
        }

        // 2. Calculate Attendance Percentage
        $attQuery = mysqli_query($con, "SELECT COUNT(DISTINCT date) as days_present FROM attendance WHERE userId = '$userId'");
        $days_present = 0;
        if($attQuery) {
            $attData = mysqli_fetch_assoc($attQuery);
            $days_present = $attData['days_present'] ? (int)$attData['days_present'] : 0;
        }

        // 3. Get Intern Joining Date to calculate total expected working days
        $userQuery = mysqli_query($con, "SELECT * FROM signup WHERE userId = '$userId'");
        
        if($userQuery && mysqli_num_rows($userQuery) > 0) {
            $userData = mysqli_fetch_assoc($userQuery);
            
            // Checking common column names for joining date (adjust if yours is different)
            $join_date_str = null;
            if(!empty($userData['created_at'])) $join_date_str = $userData['created_at'];
            elseif(!empty($userData['date'])) $join_date_str = $userData['date'];
            elseif(!empty($userData['timestamp'])) $join_date_str = $userData['timestamp'];

            if(!empty($join_date_str)) {
                $join_date = new DateTime($join_date_str);
                $today = new DateTime();
                $total_days = $join_date->diff($today)->days;
                $total_days = ($total_days == 0) ? 1 : $total_days; // Prevent division by zero

                // Calculate approx working days (5 out of 7 days a week)
                $working_days = floor($total_days * (5/7));
                if($working_days == 0) $working_days = 1;

                $attendance_percentage = round(($days_present / $working_days) * 100, 2);
                $response['attendance'] = ($attendance_percentage > 100) ? 100 : $attendance_percentage;
            } else {
                // Fallback: If no joining date exists in the database, 
                // we assume an ideal month has ~22 working days.
                $attendance_percentage = round(($days_present / 22) * 100, 2);
                $response['attendance'] = ($attendance_percentage > 100) ? 100 : $attendance_percentage;
            }
        }
    } catch (Exception $e) {
        $response['error'] = "Database Error: " . $e->getMessage();
    }
    
    // Output valid JSON
    echo json_encode($response);
    exit;
} else {
    $response['error'] = 'No userId provided';
    echo json_encode($response);
    exit;
}
?>