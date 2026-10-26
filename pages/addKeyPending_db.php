<?php
session_start();
include('../include/db_config.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
        echo "<script>window.location.href = 'login.php';</script>";
        exit;
    }

    $userId = $_SESSION['userId'];
    $todayDate = date('Y-m-d');

    $projects = $_POST['project']; // array
    $tasks = $_POST['task']; // array
    $reasons = $_POST['reason']; // array
    $deadlines = $_POST['deadline']; // array

    // Validate arrays
    if (!is_array($projects) || count($projects) === 0) {
        $_SESSION['status'] = false;
        $_SESSION['msg'] = "No projects selected.";
        header("Location: addKeyPending.php");
        exit();
    }

    $count = count($projects);
    $insertedCount = 0;

    for ($i = 0; $i < $count; $i++) {
        // Skip if any required field is empty
        if (empty($projects[$i]) || empty($tasks[$i]) || empty($deadlines[$i])) {
            continue;
        }

        $projectId = $projects[$i];

        // Sanitize inputs
        $taskDesc = mysqli_real_escape_string($con, $tasks[$i]);
        $reason = mysqli_real_escape_string($con, $reasons[$i] ?? '');
        $deadline = mysqli_real_escape_string($con, $deadlines[$i]);

        $query = "INSERT INTO keyPendings
        (userId, projectId, taskDescription, reason, deadline, punchOutDate)
        VALUES 
        ('$userId', '$projectId', '$taskDesc', '$reason', '$deadline', '$todayDate')";

        if (mysqli_query($con, $query)) {
            $insertedCount++;
        }
    }

    if ($insertedCount > 0) {
        $_SESSION['status'] = true;
        $_SESSION['msg'] = "$insertedCount key pending task(s) saved successfully!";
    }
    else {
        $_SESSION['status'] = false;
        $_SESSION['msg'] = "Failed to save tasks or no tasks provided.";
    }

    header("Location: qrCodeScanner_copy.php?punch=Out");
    exit();
}
else {
    header("Location: addKeyPending.php");
    exit();
}
?>
