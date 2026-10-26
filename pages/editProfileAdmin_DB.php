<?php
session_start();
include('../include/db_config.php');

if (isset($_POST['update'])) {
    $userId   = mysqli_real_escape_string($con, $_POST['userId']);
    $fullName = mysqli_real_escape_string($con, $_POST['fullName']);
    $username = mysqli_real_escape_string($con, $_POST['username']);
    $password = mysqli_real_escape_string($con, $_POST['password']);
    $email    = mysqli_real_escape_string($con, $_POST['email']);
    $dob      = mysqli_real_escape_string($con, $_POST['dob']);

    $profilePhoto = '';
    if (!empty($_FILES["upload"]["name"])) {
        $fileName = $userId . "_" . time() . "." . pathinfo($_FILES["upload"]["name"], PATHINFO_EXTENSION);
        move_uploaded_file($_FILES["upload"]["tmp_name"], "../profile-photos/" . $fileName);
        $profilePhoto = ", profilePhoto='$fileName'";
    }

    $query = mysqli_query($con, "UPDATE signup 
        SET fullName='$fullName', dob='$dob', username='$username', password='$password', email='$email'
        $profilePhoto
        WHERE userId='$userId'");

    if ($query) {
        $_SESSION['status'] = true;
        $_SESSION['msg'] = "Intern profile updated successfully.";
    } else {
        $_SESSION['status'] = false;
        $_SESSION['msg'] = "Update failed! Please try again.";
    }

    // header("Location: editProfile_Admin.php?userId=" . $userId);
    // exit;
    echo "<script>window.location.href = 'editProfile_Admin.php?userId={$userId}';</script>";
exit;
}
?>
