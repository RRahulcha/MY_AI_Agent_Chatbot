<?php
session_start();
if(!isset($_SESSION['userId']) && empty($_SESSIOn['userId'])){
    // header('location:login');
    // die();
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

include('../include/db_config.php');
$dateTime = date('Y-m-d H:i:s');
if(isset($_POST['start'])){
    
    require_once('../include/sweetAlert.php');
    
    $projectId = $_POST['projectId'];
    $location = $_POST['location'];
    if(!empty($_POST['expectedDeadline'])){
        $expectedDeadline = date('Y-m-d H:i:s', strtotime($_POST['expectedDeadline']));
    }
    
    $check_status = mysqli_query($con,"UPDATE taskStatus SET status = 'pause' WHERE userId = '{$_SESSION['userId']}' AND status = 'resumed'") or die("ERROR 29");   
    
    $check_status = mysqli_query($con,"UPDATE taskEntry SET status = 'pause' WHERE userId = '{$_SESSION['userId']}' AND status = 'resumed'") or die("ERROR 31");
    
    if(!empty($_POST['newTask'])){
        $taskId = uniqid('task');
        $task = addslashes($_POST['newTask']);
        $query = mysqli_query($con, "INSERT INTO taskEntry (`taskId`, `task`, `projectId`, `expectedDeadline`, `startTime`, `userId`, `dateTime`, `location`,`status`) VALUES ('$taskId', '$task', '$projectId', '$expectedDeadline', '$dateTime', '{$_SESSION['userId']}', '$dateTime', '$location','start') ");    
    } else {
        $taskId = $_POST['task'];
        $query = mysqli_query($con, "UPDATE taskEntry SET expectedDeadline = '$expectedDeadline', status = 'start', startTime = '$dateTime', location = '$location' WHERE taskId = '$taskId'") or die($con->error);
    }
    
    $query = mysqli_query($con, "INSERT INTO taskStatus (`taskId`, `resumeTime`, `status`, `dateTime`, `userId`) VALUES ('$taskId', '$dateTime', 'resumed', '$dateTime', '{$_SESSION['userId']}')") or die($con->error);
    if($query){
        $status = true;
        $msg = "Your task has been entered. Now you can start your work";
    } else {
        $status = false;
        $msg = "Sorry, your task can\'t be entried. Please try again after sometime";
    }
    setSession($status, $msg);
    // header('location:taskEntry');
    echo "<script>window.location.href = 'taskEntry.php';</script>";
    exit;
}

if(isset($_GET['pause'])){
    require_once('../include/sweetAlert.php');
    
    $id = $_GET['pause'];
    $query = mysqli_query($con, "SELECT * FROM taskStatus WHERE id = '$id'");
    $result = mysqli_fetch_assoc($query);
    $taskId = $result['taskId'];
    $resumeTime = date_create($result['resumeTime']);
    $pauseTime = date_create($dateTime);
    $interval = date_diff($resumeTime, $pauseTime);
    $manHours = $interval->h.":".$interval->i.":".$interval->s;
    $resumeTime = $result['resumeTime'];
    $pauseTime = $dateTime;
    $query = mysqli_query($con, "UPDATE taskEntry SET `dateTime` = '$dateTime', status = 'pause' WHERE taskId = '$taskId' ");
    $query = mysqli_query($con, "UPDATE taskStatus SET `pauseTime` = '$pauseTime',  `manHours` = '$manHours', `status` = 'pause', `dateTime` = '$dateTime' WHERE id = '$id'");
    if($query){
        $status = true;
        $msg = "Your task has been paused.";
    } else {
        $status = false;
        $msg = "Sorry, your task can\'t be paused.";
    }
    setSession($status, $msg);
    // header('location:taskEntry');
    echo "<script>window.location.href = 'taskEntry.php';</script>";
    exit;
}

if(isset($_GET['resume'])){
    
    $check_status = mysqli_query($con,"UPDATE taskStatus SET status = 'pause' WHERE userId = '{$_SESSION['userId']}' AND status = 'resumed'") or die("ERROR 29");   
    
    $check_status = mysqli_query($con,"UPDATE taskEntry SET status = 'pause' WHERE userId = '{$_SESSION['userId']}' AND status = 'resumed'") or die("ERROR 31");
    
    $taskId = $_GET['resume'];
    $resumeTime = $dateTime;
    $query = mysqli_query($con, "UPDATE taskEntry SET `dateTime` = '$dateTime' , status = 'resume' WHERE taskId = '$taskId' ");
    $query = mysqli_query($con, "INSERT INTO taskStatus (`taskId`, `resumeTime`, `status`, `dateTime`, `userId`) VALUES ('$taskId', '$resumeTime', 'resumed', '$dateTime', '{$_SESSION['userId']}')");
    if($query){
        $status = true;
        $msg = "Your task has been resumed.";
    } else {
        $status = false;
        $msg = "Sorry, your task can\'t be resumed.";
    }
    // header('location:taskEntry');
    echo "<script>window.location.href = 'taskEntry.php';</script>";
    exit;
}


if (isset($_GET['end'])) {
    require_once('../include/sweetAlert.php');
    require_once('../include/con.php');

    $taskId = $_GET['end'];
    $sum = strtotime('00:00:00');
    $totalTime = 0;
    $dateTime = date('Y-m-d H:i:s');

    // Fetch all task status records for the given taskId
    $query = mysqli_query($con, "SELECT * FROM taskStatus WHERE taskId = '$taskId' ORDER BY id ASC");
    
    while ($result = mysqli_fetch_assoc($query)) {
        $timeinsec = strtotime($result['manHours']) - $sum;
        $totalTime += $timeinsec;
        $id = $result['id'];
        $resumeTime = $result['resumeTime'];
    }

    // Check if pauseTime is not set (default zero value)
    if ($result['pauseTime'] == "0000-00-00 00:00:00") {
        $resumeTime_T = date_create($resumeTime);
        $pauseTime = date_create($dateTime);
        $interval = date_diff($resumeTime_T, $pauseTime);
        $manHours = $interval->h . ":" . $interval->i . ":" . $interval->s;
        $pauseTime = $dateTime;

        $timeinsec = strtotime($manHours) - $sum;
        $totalTime += $timeinsec;

        // Update taskStatus table
        $updateQuery = mysqli_query($con, "UPDATE taskStatus SET 
            `pauseTime` = '$pauseTime',  
            `manHours` = '$manHours', 
            `status` = 'end', 
            `dateTime` = '$dateTime' 
            WHERE id = '$id'");
    } else {
        // Update only the status and dateTime
        $updateQuery = mysqli_query($con, "UPDATE taskStatus SET 
            `status` = 'end', 
            `dateTime` = '$dateTime' 
            WHERE id = '$id'");
    }

    // Calculate total man-hours
    $h = intval($totalTime / 3600);
    $totalTime -= ($h * 3600);
    $m = intval($totalTime / 60);
    $s = $totalTime - ($m * 60);
    $manHours = sprintf("%02d:%02d:%02d", $h, $m, $s); // Format time properly

    // Update taskEntry table
    $updateEntry = mysqli_query($con, "UPDATE taskEntry SET  
        `endTime` = '$dateTime', 
        `manHours` = '$manHours', 
        `dateTime` = '$dateTime', 
        `status` = 'end' 
        WHERE taskId = '$taskId'");

    // Set status message
    if ($updateEntry) {
        $status = true;
        $msg = "Your task has been successfully ended.";
    } else {
        $status = false;
        $msg = "Sorry, your task couldn't be ended.";
    }

    setSession($status, $msg);
    // header('location:taskEntry');
    echo "<script>window.location.href = 'taskEntry.php';</script>";
    exit;
}



if(isset($_POST['update'])){
    require_once('../include/sweetAlert.php');
    
    $taskId = $_POST['taskId'];
    $projectId = addslashes($_POST['projectId']);
   echo $task = $_POST['task'];
    $expectedDeadline = $_POST['expectedDeadline'];
    $location = $_POST['location'];
    $status = $_POST['status'];
    $userId = $_SESSION['userId'];
    $query = mysqli_query($con, "UPDATE taskEntry SET `projectId` = '$projectId', `task` = '$task', `expectedDeadline` = '$expectedDeadline', `location` = '$location' WHERE taskId = '$taskId';");
    
    if($query){
        $status = true;
        $msg = "Task is updated successfully...";
    } else {
        $status = false;
        $msg = "Sorry, task can't be update. Please try again.";
    }
    setSession($status, $msg);
}


if(isset($_GET['delete'])){
    require_once('../include/sweetAlert.php');
    
    $taskId = $_GET['delete'];
    $query = mysqli_query($con, "DELETE FROM taskEntry WHERE taskId = '$taskId'");
    if($query){
        $status = true;
        $msg = "Task is deleted successfully...";
    } else {
        $status = false;
        $msg = "Sorry, task can't be delete. Please try again.";
    }
    setSession($status, $msg);
    // header('location:taskEntry');
    echo "<script>window.location.href = 'taskEntry.php';</script>";
    exit;
}

?>