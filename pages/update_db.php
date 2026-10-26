<?php
session_start();
include('../include/db_config.php');

if (isset($_POST['update'])) {
    $userId = mysqli_real_escape_string($con, $_POST['userId']);
    $fullName = mysqli_real_escape_string($con, $_POST['fullName']);
    $email    = mysqli_real_escape_string($con, $_POST['email']);
    // fetch all other fields the same way...

    $sql = "UPDATE signup SET 
                fullName='$fullName',
                email='$email',
                office='{$_POST['office']}',
                expectedPunchIn='{$_POST['expectedPunchIn']}',
                expectedPunchOut='{$_POST['expectedPunchOut']}',
                internId='{$_POST['internId']}',
                designation='{$_POST['designation']}',
                dob='{$_POST['dob']}',
                mobNo='{$_POST['mobNo']}',
                username='{$_POST['username']}',
                type='{$_POST['type']}'
            WHERE userId='$userId'";

    if (mysqli_query($con, $sql)) {
        $_SESSION['status'] = true;
        $_SESSION['msg'] = "Employee updated successfully!";
    } else {
        $_SESSION['status'] = false;
        $_SESSION['msg'] = "Error: " . mysqli_error($con);
    }

    // header("Location: employeeList.php"); // redirect back to list page
    // exit;
    echo "<script>window.location.href = 'employeeList.php';</script>";
    exit;
}
?>
