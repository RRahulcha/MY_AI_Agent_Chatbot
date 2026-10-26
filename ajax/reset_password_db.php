<?php
session_start();

// ---> THIS IS THE ONLY CHANGE: Pointing to your local mail folder instead of vendor <---
require '../mail/PHPMailerAutoload.php';

include('../include/db_config.php');

// ❌ Unauthorized access
if (!isset($_SESSION['otp_verified'], $_SESSION['otp_email'])) {
    $_SESSION['alert_icon'] = 'error';
    $_SESSION['alert_text'] = 'Unauthorized Access';
    $_SESSION['alert_redirect'] = '../pages/login.php';
    header("Location: ../pages/login.php");
    exit;
}

// ❌ Password mismatch
if ($_POST['password'] !== $_POST['confirm_password']) {
    $_SESSION['alert_icon'] = 'error';
    $_SESSION['alert_text'] = 'Passwords do not match';
    $_SESSION['alert_redirect'] = '../pages/reset_password.php';
    header("Location: ../pages/reset_password.php");
    exit;
}

// ❌ Weak password
if (strlen($_POST['password']) < 8) {
    $_SESSION['alert_icon'] = 'warning';
    $_SESSION['alert_text'] = 'Password must be at least 8 characters long';
    $_SESSION['alert_redirect'] = '../pages/reset_password.php';
    header("Location: ../pages/reset_password.php");
    exit;
}

// ❌ DB connection check
if (!$conn) {
    $_SESSION['alert_icon'] = 'error';
    $_SESSION['alert_text'] = 'Database connection error';
    $_SESSION['alert_redirect'] = '../pages/reset_password.php';
    header("Location: ../pages/reset_password.php");
    exit;
}

// 🔐 Hash password
$pass = $_POST['password'];
$email = $_SESSION['otp_email'];

// ✅ Update password (MySQLi prepared statement)
$sql = "UPDATE signup SET password = ? WHERE email = ?";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "ss", $pass, $email);

if (!mysqli_stmt_execute($stmt)) {
    mysqli_stmt_close($stmt);
    $_SESSION['alert_icon'] = 'error';
    $_SESSION['alert_text'] = 'Failed to update password';
    $_SESSION['alert_redirect'] = '../pages/reset_password.php';
    header("Location: ../pages/reset_password.php");
    exit;
}
mysqli_stmt_close($stmt);

// 📧 Send confirmation email 
try {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->SMTPAuth = true;
    $mail->SMTPSecure = 'ssl';
    $mail->Host = 'smtp.hostinger.com';
    $mail->Port = 465;
    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';
    $mail->Username = "noreply@settribe.com";
    $mail->Password = 'NoReply@SETTribe1';

    $mail->setFrom("noreply@settribe.com", 'SETTribe AMS');
    $mail->addAddress($email);

    $mail->Subject = 'Password Updated Successfully';
    $mail->Body = "
    <div style='font-family: Arial, sans-serif; color: #333; max-width: 600px; margin: auto;'>

        <h2 style='color: #2c3e50;'>Password Changed Successfully</h2>

        <p>Hello,</p>

        <p>
            This is a confirmation that the password for your account has been
            <strong>updated successfully</strong>.
        </p>

        <div style='margin: 25px 0; padding: 15px; background-color: #f4f6fb; border-left: 4px solid #1abc9c;'>
            <p style='margin: 0;'>
                <strong>When:</strong> " . date('d M Y, h:i A') . "<br>
                <strong>Action:</strong> Password reset completed
            </p>
        </div>

        <p>
            If you made this change, no further action is required.
        </p>

        <p style='color: #c0392b;'>
            <strong>Didn’t make this change?</strong><br>
            Please contact our support team immediately or reset your password again
            to secure your account.
        </p>

        <hr style='margin: 30px 0;'>

        <p style='font-size: 12px; color: #777;'>
            This is an automated message. Please do not reply to this email.
        </p>

        <p style='font-size: 12px; color: #777;'>
            &copy; " . date('Y') . " SETTribe AMS. All rights reserved.
        </p>

    </div>
";

    $mail->SMTPOptions = array(
        'ssl' => array(
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => false
        )
    );

    if (!$mail->send()) {
        throw new Exception($mail->ErrorInfo);
    }
} catch (Exception $e) {
    // Email failure should not block password reset
}

// ✅ Success alert
$_SESSION['alert_icon'] = 'success';
$_SESSION['alert_text'] = 'Password updated successfully. Please login.';
$_SESSION['alert_redirect'] = '../pages/login.php';

// 🧹 Cleanup
unset($_SESSION['otp_verified']);
unset($_SESSION['otp_email']);
unset($_SESSION['otp_hash']);
unset($_SESSION['otp_expiry']);

header("Location: ../pages/login.php");
exit;