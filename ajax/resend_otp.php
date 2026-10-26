<?php
session_start();
require '../vendor/autoload.php';
include('../include/db_config.php');

use PHPMailer\PHPMailer\PHPMailer;

// ❌ No OTP session
if (!isset($_SESSION['otp_email'], $_SESSION['otp_time'])) {
    $_SESSION['alert_icon'] = 'error';
    $_SESSION['alert_text'] = 'OTP session expired. Please try again.';
    $_SESSION['alert_redirect'] = '../pages/forgot_password.php';
    header("Location: ../pages/verify_otp.php");
    exit;
}

// ⏱ Enforce 60s resend limit (SERVER-SIDE)
$wait = 60 - (time() - $_SESSION['otp_time']);
if ($wait > 0) {
    $_SESSION['alert_icon'] = 'warning';
    $_SESSION['alert_text'] = "Please wait {$wait} seconds before resending OTP";
    header("Location: ../pages/verify_otp.php");
    exit;
}

// 🔢 Generate NEW OTP
$otp = random_int(100000, 999999);

// 🔐 Overwrite OTP
$_SESSION['otp_hash']   = password_hash($otp, PASSWORD_DEFAULT);
$_SESSION['otp_expiry'] = time() + 300;
$_SESSION['otp_time']   = time();

$email = $_SESSION['otp_email'];

// 📧 Send OTP
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

    $mail->Subject = 'Password Reset OTP';
    $mail->Body = "
        <div style='font-family: Arial, sans-serif; color: #333; max-width: 600px; margin: auto;'>
            <h2 style='color: #2c3e50;'>Password Reset Request</h2>
            <p>Hello,</p>

            <p>We received a request to reset your account password. Please use the One-Time Password (OTP) below to proceed:</p>

            <div style='text-align: center; margin: 30px 0;'>
                <span style='font-size: 28px; letter-spacing: 4px; font-weight: bold; color: #1abc9c;'>
                    $otp
                </span>
            </div>

            <p><strong>Validity:</strong> This OTP is valid for <strong>5 minutes</strong>.</p>

            <p>If you did not request a password reset, please ignore this email or contact our support team immediately.</p>

            <hr style='margin: 30px 0;'>

            <p style='font-size: 12px; color: #777;'>
                This is an automated message. Please do not reply to this email.
            </p>

            <p style='font-size: 12px; color: #777;'>
                &copy; " . date('Y') . " SETTribe AMS. All rights reserved.
            </p>
        </div>
    ";


    // // 🔍 DEBUG
    // $mail->SMTPDebug = 2;
    // $mail->Debugoutput = 'html';

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

    $_SESSION['alert_icon'] = 'success';
    $_SESSION['alert_text'] = 'OTP has been sent to your email';
    $_SESSION['alert_redirect'] = '../pages/verify_otp.php';

    header("Location: ../pages/verify_otp.php");
    exit;


} 
catch (Exception $e) {
    $_SESSION['alert_icon'] = 'error';
    $_SESSION['alert_text'] = 'Failed to resend OTP. Try again later.';
}

header("Location: ../pages/verify_otp.php");
exit;
