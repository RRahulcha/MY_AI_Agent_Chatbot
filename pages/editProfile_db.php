<?php
session_start();

if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href='login.php';</script>";
    exit;
}

include('../include/db_config.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    require_once('../include/sweetAlert.php');

    $fullName = mysqli_real_escape_string($con, $_POST['fullName']);
    $username = mysqli_real_escape_string($con, $_POST['username']);
    $email    = mysqli_real_escape_string($con, $_POST['email']);
    $mobileNo = isset($_POST['mobileNo']) ? mysqli_real_escape_string($con, $_POST['mobileNo']) : '';
    $dob      = mysqli_real_escape_string($con, $_POST['dob']);
    $userId   = $_SESSION['userId'];

    // ── Password handling ─────────────────────────────────────────────────
    $passwordUpdate = "";
    $rawPassword    = trim($_POST['password']);

    if (!empty($rawPassword)) {
        // Fetch current stored password from DB
        $userIdEscaped = mysqli_real_escape_string($con, $userId);
        $pwRow = mysqli_fetch_assoc(
            mysqli_query($con, "SELECT password FROM signup WHERE userId='$userIdEscaped' LIMIT 1")
        );
        $storedPassword = $pwRow['password'] ?? '';

        // Check if submitted value is different from stored value
        // This handles both: plain text (old) and bcrypt hash (new) stored passwords
        $isAlreadyHashed = (strlen($storedPassword) === 60 && strpos($storedPassword, '$2y$') === 0);

        if ($isAlreadyHashed) {
            // Stored password is a bcrypt hash — only update if new value doesn't verify against it
            if (!password_verify($rawPassword, $storedPassword)) {
                $hashed        = password_hash($rawPassword, PASSWORD_BCRYPT);
                $hashedEscaped = mysqli_real_escape_string($con, $hashed);
                $passwordUpdate = ", `password`='$hashedEscaped'";
            }
            // else: same password re-submitted, skip update
        } else {
            // Stored password is plain text (old format) — hash it if different
            if ($rawPassword !== $storedPassword) {
                $hashed        = password_hash($rawPassword, PASSWORD_BCRYPT);
                $hashedEscaped = mysqli_real_escape_string($con, $hashed);
                $passwordUpdate = ", `password`='$hashedEscaped'";
            }
            // else: same plain text re-submitted, skip update
        }
    }
    // ─────────────────────────────────────────────────────────────────────

    if (!empty($_FILES["upload"]["name"])) {

        $documentName = $_FILES["upload"]["name"];
        $tempname     = $_FILES["upload"]["tmp_name"];
        $ext          = pathinfo($documentName, PATHINFO_EXTENSION);

        $fileName = $userId . "_" . time() . "." . $ext;
        $folder   = "../profile-photos/" . $fileName;

        move_uploaded_file($tempname, $folder);

        $query = mysqli_query($con, "
            UPDATE signup
            SET
                fullName = '$fullName',
                dob      = '$dob',
                username = '$username',
                profilePhoto = '$fileName',
                email    = '$email',
                mobileNo = '$mobileNo'
                $passwordUpdate
            WHERE userId = '$userId'
        ");

    } else {

        $query = mysqli_query($con, "
            UPDATE signup
            SET
                fullName = '$fullName',
                dob      = '$dob',
                username = '$username',
                email    = '$email',
                mobileNo = '$mobileNo'
                $passwordUpdate
            WHERE userId = '$userId'
        ");
    }

    if ($query) {
        $status = true;
        $msg    = "Profile updated successfully...";

        if (!empty($_FILES["upload"]["name"])) {
            require_once('../include/ai_service_helper.php');
            $aiRes = enroll_user_face_ai($userId, $fullName, $fileName);
            if (isset($aiRes['face_detected']) && $aiRes['face_detected'] === false) {
                $msg = "Profile updated! Warning: No clear face detected in the photo. Please upload a clear face photo so face attendance can verify you.";
            } elseif (isset($aiRes['face_detected']) && $aiRes['face_detected'] === true) {
                $msg = "Profile updated & Biometric Face verified successfully!";
            }
        }
    } else {
        $status = false;
        $msg    = "Sorry, profile can't be updated. Please try again.";
    }

    setSession($status, $msg);

    echo "<script>window.location.href='editProfile.php';</script>";
    exit;
} else {
    header("Location: editProfile.php");
    exit;
}
?>