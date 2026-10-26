<?php
session_start();
include('../include/db_config.php');

if (isset($_POST['signUp'])) {
    
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
    
    require_once('../include/sweetAlert.php');
    
    $userId = uniqid();
    $fullName = trim($_POST['fullName']);
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $internId = trim($_POST['internId']);
    $type = $_POST['type'];
    $email = trim($_POST['email']);

    $office = trim($_POST['office']);

    $designation = $_POST['designation'];
    $userRole = ($designation === 'seniorIntern') ? 'senior_intern' : (($designation === 'juniorIntern') ? 'junior_intern' : $designation);
    $createdDate = date('Y-m-d H:i:s');
    $mob = trim($_POST['mobNo']);

    $dateofbirth = $_POST['dob'];

    $expectedPunchIn = $_POST['expectedPunchIn'];
    $expectedPunchOut = $_POST['expectedPunchOut'];
    $interval = date_diff(date_create($expectedPunchIn), date_create($expectedPunchOut));
    $manHours = $interval->h . ":" . $interval->i . ":" . $interval->s;
    
    // Handle file upload
    if (!empty($_FILES["upload"]["name"])) {
        $fileName = $_FILES["upload"]["name"];
        $tempname = $_FILES["upload"]["tmp_name"];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $newFileName = $userId . "_" . $fullName . "." . $fileExtension;
        $folder = "../profile-photos/" . $newFileName;

        move_uploaded_file($tempname, $folder);
    } else {
        $newFileName = ""; // If no file is uploaded
    }

    // Check if email or mobile number already exists
    $checkQuery = mysqli_query($con, "SELECT * FROM signup WHERE email = '$email'");
    if (mysqli_num_rows($checkQuery) > 0) {
        $status = false;
        $msg = "An account with this Email or Mobile Number already exists!";
        setSession($status, $msg);
        // header('location:register');
        // exit();
        echo "<script>window.location.href = 'register.php';</script>";
    exit;
    }

    // Check if username is already taken
    $query = mysqli_query($con, "SELECT * FROM signup WHERE username = '$username'");
    if (mysqli_num_rows($query) > 0) {
        $status = false;
        $msg = "This username is already taken. Please try another username.";
        setSession($status, $msg);
        // header('location:register');
        // exit();
        echo "<script>window.location.href = 'register.php';</script>";
    exit;
    }

    // Insert new employee into the database
    $query = mysqli_query($con, "INSERT INTO signup 
        (`userId`, `fullName`, `username`, `password`, `email`,`office`, `mobileNo`, `designation`, `createdDate`, `profilePhoto`, `internId`, `expectedPunchIn`, `expectedPunchOut`, `totalWorkingHours`, `type`, `status`, `dob`, `userRole`) 
        VALUES ('$userId', '$fullName', '$username', '$password', '$email', '$office','$mob', '$designation', '$createdDate', '$newFileName', '$internId', '$expectedPunchIn', '$expectedPunchOut', '$manHours', '$type', 'Active', '$dateofbirth', '$userRole')") 
        or die("Database error");
         
    if ($query) {
        $status = true;
        $msg = "Your account has been created successfully!";
    } else {
        $status = false;
        $msg = "There was an error creating your account. Please try again later.";
    }

    setSession($status, $msg);
    // header('location:register');
    echo "<script>window.location.href = 'register.php';</script>";
    exit;
}
?>
