<?php
session_start();

if (!isset($_SESSION['otp_hash'], $_SESSION['otp_expiry'])) {
    $_SESSION['alert_icon'] = 'error';
    $_SESSION['alert_text'] = 'OTP session expired';
    $_SESSION['alert_redirect'] = '../pages/forgot_password.php';
    header("Location: ../pages/verify_otp.php");
    exit;
}

if (time() > $_SESSION['otp_expiry']) {
    $_SESSION['alert_icon'] = 'error';
    $_SESSION['alert_text'] = 'OTP expired';
    $_SESSION['alert_redirect'] = '../pages/forgot_password.php';
    header("Location: ../pages/verify_otp.php");
    exit;
}

if (!password_verify($_POST['otp'], $_SESSION['otp_hash'])) {
    $_SESSION['alert_icon'] = 'error';
    $_SESSION['alert_text'] = 'Invalid OTP';
    header("Location: ../pages/verify_otp.php");
    exit;
}

// ✅ OTP verified
$_SESSION['otp_verified'] = true;

$_SESSION['alert_icon'] = 'success';
$_SESSION['alert_text'] = 'OTP verified successfully';
$_SESSION['alert_redirect'] = '../pages/reset_password.php';

header("Location: ../pages/verify_otp.php");
exit;
