<?php
session_start();

require '../mail/PHPMailerAutoload.php';
include('../include/db_config.php');

$db = isset($conn) ? $conn : (isset($con) ? $con : null);
if (!$db) {
    die("Database connection variable not found.");
}

$email = $_POST['email'] ?? '';

// ❌ Invalid request
if (empty($email)) {
    $_SESSION['alert_icon'] = 'error';
    $_SESSION['alert_text'] = 'Invalid request';
    $_SESSION['alert_redirect'] = '../pages/forgot_password.php';
    header("Location: ../pages/forgot_password.php");
    exit;
}

// ✅ Check if email exists
$sql = "SELECT srNo FROM signup WHERE email = ?";
$stmt = mysqli_prepare($db, $sql);

if (!$stmt) {
    die(mysqli_error($db));
}
mysqli_stmt_bind_param($stmt, "s", $email);
mysqli_stmt_execute($stmt);
mysqli_stmt_store_result($stmt);

if (mysqli_stmt_num_rows($stmt) === 0) {
    mysqli_stmt_close($stmt);
    $_SESSION['alert_icon'] = 'error';
    $_SESSION['alert_text'] = 'Email not registered';
    $_SESSION['alert_redirect'] = '../pages/forgot_password.php';
    header("Location: ../pages/forgot_password.php");
    exit;
}
mysqli_stmt_close($stmt);

// ⏱ Rate-limit OTP (60 seconds)
if (isset($_SESSION['otp_time'])) {
    $remaining = 60 - (time() - $_SESSION['otp_time']);

    if ($remaining > 0) {
        $_SESSION['alert_icon'] = 'warning';
        $_SESSION['alert_redirect'] = '../pages/forgot_password.php';
        $_SESSION['alert_require_ok'] = true;
        $_SESSION['alert_countdown'] = $remaining;
        header("Location: ../pages/forgot_password.php");
        exit;
    }
}

// 🔢 Generate OTP
$otp = random_int(100000, 999999);

// 🔐 Store OTP securely in session
$_SESSION['otp_email']  = $email;
$_SESSION['otp_hash']   = password_hash($otp, PASSWORD_DEFAULT);
$_SESSION['otp_expiry'] = time() + 300;
$_SESSION['otp_time']   = time();

// 📧 Email HTML Body
$emailBody = "
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
        <p style='font-size: 12px; color: #777;'>This is an automated message. Please do not reply to this email.</p>
        <p style='font-size: 12px; color: #777;'>&copy; " . date('Y') . " SETTribe AMS. All rights reserved.</p>
    </div>
";

// ============================================================
// METHOD 1: PHPMailer SMTP (Fix 1 — port 587 + tls)
// ============================================================
$smtpSent = false;

$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->SMTPAuth   = true;
    $mail->Host       = 'smtp.hostinger.com';
    $mail->Port       = 587;         // ✅ Changed from 465
    $mail->SMTPSecure = 'tls';       // ✅ Changed from ssl
    $mail->Username   = 'noreply@settribe.com';
    $mail->Password   = 'NoReply@SETTribe1';

    $mail->setFrom('noreply@settribe.com', 'SETTribe AMS');
    $mail->addAddress($email);
    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';

    $mail->Subject = 'Password Reset OTP';
    $mail->Body    = $emailBody;

    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true
        ]
    ];

    $mail->send();
    $smtpSent = true;

} catch (Exception $e) {
    // SMTP failed — will try Resend API below
    $smtpSent = false;
}

// ============================================================
// METHOD 2: Resend API fallback (if SMTP fails)
// ============================================================
if (!$smtpSent) {

    $apiKey = 'YOUR_RESEND_API_KEY'; // 🔑 Get free key at resend.com

    $payload = json_encode([
        'from'    => 'SETTribe AMS <noreply@settribe.com>',
        'to'      => [$email],
        'subject' => 'Password Reset OTP',
        'html'    => $emailBody
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://api.resend.com/emails',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS => $payload
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 && $httpCode !== 201) {
        // Both methods failed
        $_SESSION['alert_icon']     = 'error';
        $_SESSION['alert_text']     = 'Failed to send OTP. Please try again.';
        $_SESSION['alert_redirect'] = '../pages/forgot_password.php';
        header("Location: ../pages/forgot_password.php");
        exit;
    }
}

// ✅ OTP sent successfully (either via SMTP or Resend)
$_SESSION['alert_icon']     = 'success';
$_SESSION['alert_text']     = 'OTP has been sent to your email';
$_SESSION['alert_redirect'] = '../pages/verify_otp.php';
header("Location: ../pages/verify_otp.php");
exit;