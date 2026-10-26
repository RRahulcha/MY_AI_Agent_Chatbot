<?php
session_start();
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}
include('../include/db_config.php');
require_once('../include/sweetAlert.php');

if (isset($_POST['updateProfile'])) {
    $userId   = $_SESSION['userId'];
    $fullName = mysqli_real_escape_string($con, trim($_POST['fullName']));
    $mobileNo = mysqli_real_escape_string($con, trim($_POST['mobileNo']));

    if (empty($fullName)) {
        setSession(false, "Full name is required.");
        echo "<script>window.location.href = 'changePassword.php';</script>";
        exit;
    }

    // Handle profile photo - prefer cropped base64 data
    $fileName = '';
    $saved = false;

    // Option 1: Cropped image (base64 from Cropper.js)
    if (!empty($_POST['croppedImage']) && strpos($_POST['croppedImage'], 'data:image/') === 0) {
        $data = $_POST['croppedImage'];
        $parts = explode(',', $data, 2);
        if (count($parts) === 2) {
            // Detect extension from mime
            preg_match('/data:image\/(\w+);/', $parts[0], $matches);
            $ext = isset($matches[1]) ? $matches[1] : 'jpeg';
            if ($ext === 'jpeg') $ext = 'jpg';

            $imageData = base64_decode($parts[1]);
            if ($imageData !== false && strlen($imageData) <= 5 * 1024 * 1024) {
                $fileName = $userId . "_" . time() . "." . $ext;
                $folder = "../profile-photos/" . $fileName;
                file_put_contents($folder, $imageData);
                $saved = true;
            }
        } 
    }

    // Option 2: Raw file upload fallback
    if (!$saved && !empty($_FILES["profilePhoto"]["name"])) {
        $file     = $_FILES["profilePhoto"];
        $fileSize = $file["size"];
        $tmpName  = $file["tmp_name"];
        $origName = $file["name"];
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        $allowedTypes = ['jpg', 'jpeg', 'png', 'webp'];
        if (!in_array($ext, $allowedTypes)) {
            setSession(false, "Invalid image format. Only JPG, JPEG, PNG, WEBP are allowed.");
            echo "<script>window.location.href = 'changePassword.php';</script>";
            exit;
        }
        if ($fileSize > 5 * 1024 * 1024) {
            setSession(false, "Image size must be less than 5 MB.");
            echo "<script>window.location.href = 'changePassword.php';</script>";
            exit;
        }
        $mimeType = mime_content_type($tmpName);
        if (strpos($mimeType, 'image/') !== 0) {
            setSession(false, "Uploaded file is not a valid image.");
            echo "<script>window.location.href = 'changePassword.php';</script>";
            exit;
        }

        $fileName = $userId . "_" . time() . "." . $ext;
        $folder   = "../profile-photos/" . $fileName;
        move_uploaded_file($tmpName, $folder);
        $saved = true;
    }

   if ($saved && $fileName) {

    $query = mysqli_query($con, "UPDATE signup 
                                 SET `fullName`='$fullName',
                                     `mobileNo`='$mobileNo',
                                     `profilePhoto`='$fileName'
                                 WHERE userId='$userId'");

} else {

    $query = mysqli_query($con, "UPDATE signup 
                                 SET `fullName`='$fullName',
                                     `mobileNo`='$mobileNo'
                                 WHERE userId='$userId'");
}
    if ($query) {
        $msg = "Profile updated successfully!";
        if ($saved && $fileName) {
            require_once('../include/ai_service_helper.php');
            $aiRes = enroll_user_face_ai($userId, $fullName, $fileName);
            if (isset($aiRes['face_detected']) && $aiRes['face_detected'] === false) {
                $msg = "Profile updated! Warning: No face detected in photo. Please upload a clear photo for biometric attendance.";
            } elseif (isset($aiRes['face_detected']) && $aiRes['face_detected'] === true) {
                $msg = "Profile updated & Biometric Face verified successfully!";
            }
        }
        setSession(true, $msg);
    } else {
        setSession(false, "Error updating profile. Please try again.");
    }

    echo "<script>window.location.href = 'changePassword.php';</script>";
    exit;
}
?>
