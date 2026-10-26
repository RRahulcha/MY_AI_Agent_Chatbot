<?php
session_start();
include('../include/db_config.php'); 

if(isset($_POST['addHoliday'])){
    $title = mysqli_real_escape_string($con, $_POST['title']);
    $from_date = $_POST['from_date'];
    $to_date = $_POST['to_date'];
    $description = mysqli_real_escape_string($con, $_POST['description']);

    // Insert into database holidays
    $insertQuery = "INSERT INTO holidays (title, from_date, to_date, description) 
                    VALUES ('$title', '$from_date', '$to_date', '$description')";

    if(mysqli_query($con, $insertQuery)){
        $_SESSION['status'] = true;
        $_SESSION['msg'] = "Holiday added successfully!";
    } else {
        $_SESSION['status'] = false;
        $_SESSION['msg'] = "Error: " . mysqli_error($con);
    }

    // header('location:add_holidays.php'); 
    // exit;
    echo "<script>window.location.href = 'add_holidays.php';</script>";
    exit;
}
?>
