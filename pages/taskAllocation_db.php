<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    // header('location:login');
    // die();
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

include('../include/con.php');
$dateTime = date('Y-m-d H:i:s');

if (isset($_POST['allocate'])) {
    require_once('../include/sweetAlert.php');

    $project = mysqli_real_escape_string($con, $_POST['project']);
    $userId = $_SESSION['userId'];

    $i = 0;
    foreach ($_POST['task'] as $task) {
        $task = mysqli_real_escape_string($con, $task);
        $taskId = uniqid('task');
        $priority = mysqli_real_escape_string($con, $_POST['priority'][$i]);
        $expectedDeadline = mysqli_real_escape_string($con, $_POST['expectedDeadline'][$i]);
        $employee = mysqli_real_escape_string($con, $_POST['employees'][$i]); // Assign correct employee for the task

        $query = mysqli_query($con, "INSERT INTO taskEntry (`taskId`, `task`, `projectId`, `allocatedBy`, `priority`, `expectedDeadline`, `userId`, `dateTime`) 
        VALUES ('$taskId', '$task', '$project', '$userId', '$priority', '$expectedDeadline', '$employee', NOW())") or die($con->error);

        $i++; // Move to the next task-employee pair
    }

    if ($query) {
        $status = true;
        $msg = "Task allocated successfully...";
    } else {
        $status = false;
        $msg = "Sorry, task can't be allocated. Please try again.";
    }

    setSession($status, $msg);
    // header('location:taskAllocation');
    // exit();
    echo "<script>window.location.href = 'taskAllocation.php';</script>";
    exit;
}


if (isset($_POST['update'])) {
    require_once('../include/sweetAlert.php');

    $id = mysqli_real_escape_string($con, $_POST['update']);
    $project = mysqli_real_escape_string($con, $_POST['project']);
    $userId = mysqli_real_escape_string($con, $_POST['employees'][0]);
    $task = mysqli_real_escape_string($con, $_POST['task'][0]);
    $priority = mysqli_real_escape_string($con, $_POST['priority'][0]);
    $expectedDeadline = mysqli_real_escape_string($con, $_POST['expectedDeadline'][0]);


    $query = mysqli_query($con, "UPDATE taskEntry 
        SET `projectId` = '$project', `userId` = '$userId', `task` = '$task', `priority` = '$priority', `expectedDeadline` = '$expectedDeadline'
        WHERE id = '$id'");

    if ($query) {
        $status = true;
        $msg = "Task is updated successfully...";
    } else {
        $status = false;
        $msg = "Sorry, task can't be updated. Please try again.";
    }
    setSession($status, $msg);
    // header('location:taskAllocation');
    // exit();
    echo "<script>window.location.href = 'taskAllocation.php';</script>";
    exit;
}


if (isset($_GET['delete'])) {
    require_once('../include/sweetAlert.php');

    $id = mysqli_real_escape_string($con, $_GET['delete']);
    
    $query = mysqli_query($con, "DELETE FROM taskEntry WHERE id = '$id'");

    if ($query) {
        $status = true;
        $msg = "Task is deleted successfully...";
    } else {
        $status = false;
        $msg = "Sorry, task can't be deleted. Please try again.";
    }
     // Display ID and Query on UI
     echo "<p><strong>Deleted ID:</strong> $id</p>";
     echo "<p><strong>Executed Query:</strong> $queryString</p>";
    setSession($status, $msg);
    // header('location:taskAllocation');
    // exit();
    echo "<script>window.location.href = 'taskAllocation.php';</script>";
    exit;
}
?>
