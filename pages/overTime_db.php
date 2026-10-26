<?php
session_start();
include('../include/db_config.php');

if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    require_once('../include/sweetAlert.php');
    $userId = $_SESSION['userId'];
    $back = $_POST['back'] ?? 'overTime.php';
    $date = date('Y-m-d');

    if (isset($_POST['expectedPunchOut']) && isset($_POST['overtimeReason'])) {
        $expectedPunchOut = mysqli_real_escape_string($con, $_POST['expectedPunchOut']);
        $reason = mysqli_real_escape_string($con, $_POST['overtimeReason']);

        if (empty($expectedPunchOut) || empty($reason)) {
            echo "<script>
                alert('Please fill all fields'); 
                window.history.back();
            </script>";
            exit;
        }

        $sql = "INSERT INTO lateGo (userId, date, lateGoTime, reason) VALUES ('$userId', '$date', '$expectedPunchOut', '$reason')";

        if (mysqli_query($con, $sql)) {

            $msg = "Expected Punch Out time extend to " . $expectedPunchOut;
            $status = "success";
        } else {
            $msg = "Error submitting request: " . mysqli_error($con);
            $status = "error";
        }



        setSession($status, $msg);
        echo "<script>window.location.href = '$back';</script>";
        exit;
    }
}
?>