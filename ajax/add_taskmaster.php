<?php
include('../include/db_config.php');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Get data safely
    $taskType = isset($_POST['taskType']) ? trim($_POST['taskType']) : '';
    $tasks = isset($_POST['task']) ? $_POST['task'] : [];

    if (empty($taskType)) {
        echo json_encode(["success" => false, "message" => "Task type is required."]);
        exit;
    }

    if (empty($tasks) || !is_array($tasks)) {
        echo json_encode(["success" => false, "message" => "Please add at least one task."]);
        exit;
    }

    $inserted = 0;

    // ✅ Insert each non-empty task
    foreach ($tasks as $task) {
        $task = trim($task);
        if ($task !== "") {
            $taskTypeEsc = mysqli_real_escape_string($con, $taskType);
            $taskEsc = mysqli_real_escape_string($con, $task);
            $query = "INSERT INTO TaskMaster (taskType, task) VALUES ('$taskTypeEsc', '$taskEsc')";
            if (mysqli_query($con, $query)) {
                $inserted++;
            } else {
                // Uncomment below line if you want to debug
                // echo json_encode(["success" => false, "message" => "SQL Error: " . mysqli_error($con)]);
                // exit;
            }
        }
    }

    if ($inserted > 0) {
        echo json_encode(["success" => true, "message" => "Tasks saved successfully!"]);
    } else {
        echo json_encode(["success" => false, "message" => "No valid tasks to insert."]);
    }

} else {
    echo json_encode(["success" => false, "message" => "Invalid request method."]);
}
?>
