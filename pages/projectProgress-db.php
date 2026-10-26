<?php
// Include database connection
include('../include/db_config.php');

// Check if form data is posted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once('../include/sweetAlert.php');

    // Validate input data
    if (!isset($_POST['projectId'], $_POST['progress'], $_POST['remark'], $_POST['userId']) || empty($_POST['userId'])) {
        setSession(false, 'Err: Missing Fields');
        // header("location:projectProgress");
        // exit();
        echo "<script>window.location.href = 'projectProgress.php';</script>";
    exit;
    }

    // Sanitize inputs
    $projectId = intval($_POST['projectId']);
    $progress = floatval($_POST['progress']);
    $remark = trim($_POST['remark']);
    $userId = $_POST['userId'];
    
    // Get the current date and time for modificationDate
    $modificationDate = date('Y-m-d H:i:s');
    
    // Get the checked tasks from the form (ensure proper JSON array conversion)
    $checkedTasks = [];
    if (isset($_POST['checkedTasks']) && is_array($_POST['checkedTasks'])) {
        $checkedTasks = array_map('intval', $_POST['checkedTasks']);
    } elseif (isset($_POST['checklistArray']) && !empty($_POST['checklistArray'])) {
        $raw = $_POST['checklistArray'];
        if (is_array($raw)) {
            $checkedTasks = array_map('intval', $raw);
        } else {
            $parts = explode(',', $raw);
            $checkedTasks = array_map('intval', array_filter($parts, 'strlen'));
        }
    }
    $checkedTasksJson = json_encode(array_values($checkedTasks)); // Store as clean JSON array [1,2,3]

    // Insert into projectProgress table with checklist data
    $insertQuery = "INSERT INTO projectProgress (projectId, progress, remark, modificationDate, userId, cid) VALUES (?, ?, ?, ?, ?, ?)";
    
    if ($insertStmt = $con->prepare($insertQuery)) {
        // Bind parameters for the insert statement
        $insertStmt->bind_param("idssss", $projectId, $progress, $remark, $modificationDate, $userId, $checkedTasksJson);
        
        // Execute the insert query
        if ($insertStmt->execute()) {
            $status = true;
            $msg = "Project progress inserted successfully!";
        } else {
            $status = false;
            $msg = "Error inserting project progress: " . $insertStmt->error;
        }
        $insertStmt->close();
    } else {
        $status = false;
        $msg = "Error preparing statement: " . $con->error;
    }
    
    // Set session message and redirect
    setSession($status, $msg);
    // header("location:projectProgress");
    // exit();
    echo "<script>window.location.href = 'projectProgress.php';</script>";
    exit;
}
?>
