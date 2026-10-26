<?php
include('../include/db_config.php');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $taskId = $_POST['taskId'] ?? '';
    $taskType = $_POST['taskType'] ?? '';
    $task = $_POST['task'] ?? '';

    if (empty($taskId) || empty($taskType) || empty($task)) {
        echo json_encode(["success" => false, "message" => "All fields are required."]);
        exit;
    }

    $taskType = mysqli_real_escape_string($con, $taskType);
    $task = mysqli_real_escape_string($con, $task);

    $query = "UPDATE TaskMaster SET taskType='$taskType', task='$task' WHERE taskId='$taskId'";
    if (mysqli_query($con, $query)) {
        echo json_encode(["success" => true, "message" => "Task updated successfully!"]);
    } else {
        echo json_encode(["success" => false, "message" => "Database error: " . mysqli_error($con)]);
    }
} else {
    echo json_encode(["success" => false, "message" => "Invalid request."]);
}
?>
