<?php
session_start();
include('../include/db_config.php');

if (isset($_POST['userId'])) {
    $userId = mysqli_real_escape_string($con, $_POST['userId']);
    $fullName = mysqli_real_escape_string($con, $_POST['fullName']);
    $email = mysqli_real_escape_string($con, $_POST['email']);
    $office = mysqli_real_escape_string($con, $_POST['office']);
    $expectedPunchIn = mysqli_real_escape_string($con, $_POST['expectedPunchIn']);
    $expectedPunchOut = mysqli_real_escape_string($con, $_POST['expectedPunchOut']);
    $internId = mysqli_real_escape_string($con, $_POST['internId']);
    $designation = mysqli_real_escape_string($con, $_POST['designation']);
    $userRole = ($designation === 'seniorIntern') ? 'senior_intern' : (($designation === 'juniorIntern') ? 'junior_intern' : $designation);
    $dob = mysqli_real_escape_string($con, $_POST['dob']);
    $mobileNo = mysqli_real_escape_string($con, $_POST['mobileNo']);
    $username = mysqli_real_escape_string($con, $_POST['username']);
    $password = mysqli_real_escape_string($con, $_POST['password']);
    $type = mysqli_real_escape_string($con, $_POST['type']);
    $faceDetectionEnabled = isset($_POST['face_detection_enabled']) ? 1 : 0;

    // ✅ Only update password if user entered a new one
    // $passwordQuery = "";
    // if (!empty($_POST['password'])) {
    //     $password = password_hash($_POST['password'], PASSWORD_BCRYPT);
    //     $passwordQuery = ", password='$password'";
    // }

//     // ✅ Only update photo if new one is uploaded
//     $photoQuery = "";
//     if (isset($_FILES['profileImage']) && $_FILES['profileImage']['error'] == 0) {
//     $targetDir = "uploads/";
//     $fileName = basename($_FILES["profileImage"]["name"]);
//     $targetFilePath = $targetDir . $fileName;
//     move_uploaded_file($_FILES["profileImage"]["tmp_name"], $targetFilePath);
// }





    // default empty
$photoQuery = "";

// ✅ Check if new file uploaded
if (isset($_FILES['profilePhoto']) && $_FILES['profilePhoto']['error'] == 0) {
    $targetDir = "../profile-photos/";

    // create folder if not exists
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }

    // Generate unique filename to avoid overwrite
    $fileName = time() . "_" . basename($_FILES["profilePhoto"]["name"]);
    $targetFilePath = $targetDir . $fileName;

    // Move file to folder
    if (move_uploaded_file($_FILES["profilePhoto"]["tmp_name"], $targetFilePath)) {
        // ✅ Add photo column update
        $photoQuery = ", profilePhoto='$fileName'";
    }
}

// ✅ Update other info + photo (only if uploaded)
$sql = "UPDATE signup SET 
            fullName='$fullName', 
            email='$email' 
            $photoQuery 
        WHERE userId='$userId'";

if (mysqli_query($con, $sql)) {
    echo "<script>alert('Profile updated successfully');</script>";
} else {
    echo "<script>alert('Error updating profile');</script>";
}





    // ✅ Update query
    $query = "UPDATE signup SET 
                fullName='$fullName',
                email='$email',
                office='$office',
                expectedPunchIn='$expectedPunchIn',
                expectedPunchOut='$expectedPunchOut',
                internId='$internId',
                designation='$designation',
                userRole='$userRole',
                dob='$dob',
                mobileNo='$mobileNo',
                username='$username',
                password='$password',
                type='$type',
                mode='$type',
                face_detection_enabled='$faceDetectionEnabled'
               
               
                $photoQuery
              WHERE userId='$userId'";

    if (mysqli_query($con, $query)) {
        $_SESSION['status'] = true;
        $_SESSION['msg'] = "Employee profile updated successfully.";
    } else {
        $_SESSION['status'] = false;
        $_SESSION['msg'] = "Update failed: " . mysqli_error($con);
    }

    // header("Location: fetchProfile.php");
    // exit;
    echo "<script>window.location.href = 'fetchProfile.php';</script>";
    exit;
} else {
    $_SESSION['status'] = false;
    $_SESSION['msg'] = "Invalid request.";
    // header("Location: fetchProfile.php");
    // exit;
    echo "<script>window.location.href = 'fetchProfile.php';</script>";
    exit;
}
?>
