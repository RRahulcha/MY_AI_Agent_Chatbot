<?php
include '../include/db_config.php'; // Include your database connection file

if (isset($_GET['projectId'])) {
    $projectId = $_GET['projectId'];
    
    // Fetch tasks for the selected project
    $query = mysqli_query($con, "SELECT taskId, task FROM taskEntry WHERE projectId = '$projectId' AND userId = '{$_SESSION['userId']}'");
    
    // Prepare the options for the task dropdown
    $options = '<option value="" selected="" disabled="">-- Select Task --</option>';
    $options .= '<option value="task">Add New Task</option>'; // Add the "Add New Task" option
    while ($row = mysqli_fetch_assoc($query)) {
        $options .= '<option value="' . $row['taskId'] . '">' . $row['task'] . '</option>';
    }
    
    // Return the options
    echo $options;
}
?>