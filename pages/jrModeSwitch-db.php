<?php
session_start();
if(!isset($_SESSION['userId']) && empty($_SESSION['userId'])){
    // header('location:login');
    // die();
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

include('../include/db_config.php');

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    require_once('../include/sweetAlert.php');
    // Check if 'intern' and 'status' are set in the POST data
    if (isset($_POST['internId']) && isset($_POST['type'])) {
        $internId = $_POST['internId'];   // Get the selected intern's userId
        $type = $_POST['type'];     // Get the selected status (Online/Offline)

        // Update the intern's type and permanent mode in the database
        $updateQuery = "UPDATE signup SET type = '$type', mode = '$type' WHERE userId = '$internId' AND status ='Active'";

        // Execute the update query
        if (mysqli_query($con, $updateQuery)) {
            $status = true;
            $msg = "Intern mode updated successfully!";
        } else {
            $status = false;
            $msg = "Error updating intern mode: " . mysqli_error($con);
        }
    } else {
        $status = false;
        $msg = "Please select an intern and a mode to switch to.";
    }
    setSession($status, $msg);
    // header('location:jrModeSwitch');
    echo "<script>window.location.href = 'jrModeSwitch.php';</script>";
    exit;
}


?>