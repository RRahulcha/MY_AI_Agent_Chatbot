<?php ob_start(); if (session_status() === PHP_SESSION_NONE) { ini_set('session.gc_maxlifetime', 2592000); session_set_cookie_params(2592000); session_start(); }
// ✅ Version check
$minRequiredVersion = 2;

if (isset($_GET['VERSION'])) {
    if ($_GET['VERSION'] != 2) {
        ob_end_clean();
        echo '<!DOCTYPE html>
<html>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="download/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
    <script>
        Swal.fire({
            icon: "warning",
            title: "Update Required",
            text: "Your app version is outdated. Please update to the latest version.",
            confirmButtonText: "Download Update",
            confirmButtonColor: "#3085d6",
            allowOutsideClick: false,
            allowEscapeKey: false
        }).then((result) => {
            window.location.href = "../download/index.php";
        });
    </script>
</body>
</html>';
        if (isset($con)) { $con->close(); } exit;
    }
}

/* ✅ Auto-login if cookies exist */
if (isset($_COOKIE['userId']) && isset($_COOKIE['userRole'])) {
    if (isset($_GET['token'])) {
        $_SESSION['token'] = $_GET['token'];
    }
    if (isset($_GET['VERSION'])) {
        $_SESSION['VERSION'] = $_GET['VERSION'];
    }
    ob_end_clean();
    header("Location: index.php");
    if (isset($con)) { $con->close(); } exit;
}

// ✅ Token handling (safe)
$token = $_GET['token'] ?? $_COOKIE['token'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>SETTribe AMS - Log In</title>
    <?php include('../partials/_links.php'); ?>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .auth .brand-logo img { width: 215px; }
        .bg {
            background: #2666fa url("../images/Picture2.png") center no-repeat;
            background-size: contain;
            min-height: 100vh;
        }
        .page-title {
            font-size: 20px;
            font-weight: 700;
            color: #2f55ff;
            margin-bottom: 5px;
        }
        .page-subtitle {
            font-size: 18px;
            font-weight: 500;
            color: #555;
            margin-bottom: 25px;
        }
        .beta-tag {
            font-size: 11px;
            background: #2f55ff;
            color: #fff;
            padding: 2px 6px;
            border-radius: 4px;
            margin-left: 8px;
            letter-spacing: 1px;
            font-weight: 500;
        }
        .form-label {
            font-weight: 500;
            margin-bottom: 5px;
            color: #333;
        }
        /* Loader overlay */
        #pageLoader {
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(255, 255, 255, 0.9);
            z-index: 99999;
            display: none;
            align-items: center;
            justify-content: center;
            flex-direction: column;
        }
        .spinner {
            width: 45px;
            height: 45px;
            border: 4px solid #e2e8f0;
            border-top-color: #2f55ff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-bottom: 12px;
        }
        .loader-text {
            font-weight: 600;
            color: #2f55ff;
            font-size: 16px;
        }
        @keyframes spin { 100% { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div id="pageLoader">
        <div class="spinner"></div>
        <div class="loader-text">Logging in...</div>
    </div>
    <div class="container-scroller">
        <div class="container-fluid page-body-wrapper full-page-wrapper">
            <div class="content-wrapper d-flex align-items-center auth px-0">
                <div class="row w-100 mx-0">
                    <div class="col-lg-8 mx-auto">
                        <div class="auth-form-light text-left">
                            <div class="row">
                                <div class="col-lg-6 mx-auto">
                                    <form class="p-5" onsubmit="return validateCaptcha();" action="login_db.php" method="POST">
                                        <div class="brand-logo">
                                            <img src="../images/logos/SETTribe-logo.png" alt="logo">
                                        </div>
                                        <div class="form-heading-wrapper">
                                            <h2 class="page-title">Attendance Management System <span class="beta-tag">BETA</span></h2>
                                            <h4 class="page-subtitle">Employee / Intern Login</h4>
                                        </div>

                                        <div class="form-group">
                                            <label for="username" class="form-label">Username <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control form-control-lg" id="username" name="username" placeholder="Username" required>
                                        </div>

                                        <div class="form-group position-relative">
                                            <label for="password" class="form-label">Password <span class="text-danger">*</span></label>
                                            <input type="password" class="form-control form-control-lg" name="password" id="password" placeholder="Password" style="padding-right: 40px;" required>
                                            <i id="togglePassword" class="fas fa-eye" style="position:absolute; right:10px; top:55%; transform:translateY(-50%); cursor:pointer; color:#666;"></i>
                                            <div class="text-right mt-2">
                                                <a href="forgot_password.php"><small>Forgot password?</small></a>
                                            </div>
                                        </div>

                                        <div class="form-group">
                                            <canvas id="captchaCanvas" width="200" height="30"></canvas>
                                            <i class="fas fa-sync-alt refresh-icon" onclick="generateCaptcha()"></i>
                                        </div>

                                        <div class="form-group last mb-4">
                                            <label for="captchaInput" class="form-label">Enter CAPTCHA code</label>
                                            <input type="text" id="captchaInput" placeholder="Enter CAPTCHA code" class="form-control" required>
                                        </div>

                                        <div id="message"></div>

                                        <div class="mt-3">
                                            <button class="btn btn-block btn-primary btn-lg font-weight-medium auth-form-btn" type="submit" name="logIn">LOG IN</button>
                                        </div>

                                        <div class="mt-2 text-center">
                                            <div class="d-flex align-items-center my-3">
                                                <hr class="flex-grow-1" style="border-color: #e2e8f0; margin: 0;">
                                                <span class="px-2 text-muted" style="font-size: 11px; font-weight: 700; letter-spacing: 0.5px;">OR BIOMETRIC VERIFICATION</span>
                                                <hr class="flex-grow-1" style="border-color: #e2e8f0; margin: 0;">
                                            </div>
                                            <a href="face_verification.php" class="btn btn-block btn-outline-primary btn-lg font-weight-bold shadow-sm" style="border-radius: 8px; border: 2px solid #2563eb; color: #2563eb; display: flex; align-items: center; justify-content: center; gap: 8px; font-size: 14px; padding: 10px 14px; text-decoration: none;">
                                                <i class="fa-solid fa-id-card-clip"></i> 3D Face Verification
                                            </a>
                                        </div>

                                        <input type="hidden" value="<?= htmlspecialchars($token) ?>" name="token">

                                        <div class="my-2 d-flex justify-content-between align-items-center">
                                            <div class="form-check">
                                                <label class="form-check-label text-muted">
                                                    <input type="checkbox" checked class="form-check-input" name="signed"> Keep me signed in
                                                </label>
                                            </div>
                                        </div>

                                        <div class="text-center mt-3">
                                            <small>Don't have an account? <a href="Registration.php?token=<?= htmlspecialchars($token) ?>">Create Account</a></small>
                                        </div>

                                        <div class="text-center mt-3 pt-2" style="border-top: 1px dashed #e2e8f0;">
                                            <a href="../apks/AMS%20-%20APK.apk" download class="btn btn-outline-success btn-sm font-weight-bold shadow-sm" style="border-radius: 8px; padding: 6px 16px; font-size: 13px;">
                                                <i class="fab fa-android mr-1"></i> Download Android App (APK)
                                            </a>
                                        </div>
                                    </form>
                                </div>
                                <div class="col-lg-6 mx-auto bg"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="../vendors/js/vendor.bundle.base.js"></script>
    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>
    <script src="../js/settings.js"></script>
    <script src="../js/todolist.js"></script>

    <?php
    // ✅ Fixed: null coalescing prevents undefined key warnings
    if (isset($_SESSION['alert_icon'])) {
        $icon  = $_SESSION['alert_icon']  ?? '';
        $title = $_SESSION['alert_title'] ?? '';
        $text  = $_SESSION['alert_text']  ?? '';

        echo "<script>
        const Toast = Swal.mixin({
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 2500,
            timerProgressBar: true,
            didOpen: (toast) => {
                toast.onmouseenter = Swal.stopTimer;
                toast.onmouseleave = Swal.resumeTimer;
            }
        });
        Toast.fire({ icon: '$icon', title: '$text' });";

        if ($icon === 'success' && isset($_SESSION['redirect_after_login'])) {
            echo "setTimeout(() => window.location.href = '{$_SESSION['redirect_after_login']}', 2000);";
        }

        if (isset($_SESSION['show_refresh_warning'])) {
            echo "setTimeout(() => {
                Swal.fire({
                    icon: 'warning',
                    title: 'For Your Dashboard',
                    html: '<p style=\"font-size:14px;\">Now you need to <strong>refresh this page</strong></p>',
                    confirmButtonText: '<i class=\"fas fa-sync-alt\"></i> Refresh Now',
                    confirmButtonColor: '#2f55ff',
                    allowOutsideClick: false,
                    allowEscapeKey: false
                }).then(() => window.location.reload());
            }, 3000);";
            unset($_SESSION['show_refresh_warning']);
        }

        echo "</script>";

        unset($_SESSION['alert_icon'], $_SESSION['alert_title'], $_SESSION['alert_text'], $_SESSION['redirect_after_login']);
    }
    ?>

    <script>
        // ✅ Password toggle
        const togglePassword = document.getElementById('togglePassword');
        const password = document.getElementById('password');
        togglePassword.addEventListener('click', function () {
            const type = password.type === 'password' ? 'text' : 'password';
            password.type = type;
            this.classList.toggle('fa-eye-slash');
        });

        // ✅ CAPTCHA
        let captchaCode;

        function generateCaptcha() {
            const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            captchaCode = Array.from({length: 6}, () => chars[Math.floor(Math.random() * chars.length)]).join('');

            const canvas = document.getElementById('captchaCanvas');
            const ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.font = '24px sans-serif';
            ctx.fillStyle = '#000';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';

            const spacing = canvas.width / captchaCode.length;
            for (let i = 0; i < captchaCode.length; i++) {
                ctx.save();
                ctx.translate(Math.random() * 5, Math.random() * 5);
                ctx.rotate(Math.random() * 0.1 - 0.05);
                ctx.fillText(captchaCode[i], spacing * i + spacing / 2, canvas.height / 2);
                ctx.restore();
            }

            ctx.strokeStyle = 'rgba(0, 0, 0, 0.2)';
            for (let i = 0; i < 3; i++) {
                ctx.beginPath();
                ctx.moveTo(Math.random() * canvas.width, Math.random() * canvas.height);
                ctx.lineTo(Math.random() * canvas.width, Math.random() * canvas.height);
                ctx.stroke();
            }
        }

        function validateCaptcha() {
            const userInput = document.getElementById('captchaInput').value;
            const message = document.getElementById('message');

            if (userInput === captchaCode) {
                message.textContent = "CAPTCHA validation successful!";
                document.getElementById('pageLoader').style.display = 'flex';
                return true;
            }

            message.textContent = "CAPTCHA validation failed!";
            document.getElementById('captchaInput').value = '';
            generateCaptcha();
            return false;
        }

        window.onload = generateCaptcha;
    </script>
</body>
</html>
