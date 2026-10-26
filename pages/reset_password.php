<?php
session_start();
if (!isset($_SESSION['otp_verified'])) {
    $_SESSION['alert_icon'] = 'error';
    $_SESSION['alert_text'] = 'Unauthorized Access';
    $_SESSION['alert_redirect'] = 'login.php';
    header("Location: login.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <title>SETTribe AMS - Reset Password</title>
    <?php include('../partials/_links.php'); ?>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        .auth .brand-logo img {
            width: 215px;
            margin-bottom: 15px;
        }

        .bg {
            background-image: url("../images/Picture2.png");
            background-position: center;
            background-repeat: no-repeat;
            background-size: cover;
        }

        .page-title {
            font-size: 20px;
            font-weight: 700;
            color: #2f55ff;
            margin-bottom: 5px;
        }

        .page-subtitle {
            font-size: 16px;
            font-weight: 500;
            color: #555;
            margin-bottom: 25px;
        }

        .eye-icon {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #666;
        }
    </style>
</head>

<body>
    <div class="container-scroller">
        <div class="container-fluid page-body-wrapper full-page-wrapper">
            <div class="content-wrapper d-flex align-items-center auth px-0">
                <div class="row w-100 mx-0">
                    <div class="col-lg-8 mx-auto">
                        <div class="auth-form-light text-left">
                            <div class="row">

                                <!-- LEFT FORM -->
                                <div class="col-lg-6 mx-auto">
                                    <form class="p-5" method="POST" action="../ajax/reset_password_db.php">

                                        <div class="brand-logo text-center">
                                            <img src="../images/logos/SETTribe-logo.png" alt="logo">
                                        </div>

                                        <h2 class="page-title">Reset Password</h2>
                                        <p class="page-subtitle">
                                            Enter a new password for your account.
                                        </p>

                                        <!-- New Password -->
                                        <div class="form-group position-relative">
                                            <input type="password" class="form-control form-control-lg" name="password"
                                                id="pwd" placeholder="New Password" required>
                                            <i class="fas fa-eye eye-icon" onclick="togglePassword('pwd', this)"></i>
                                        </div>

                                        <!-- Confirm Password -->
                                        <div class="form-group position-relative">
                                            <input type="password" class="form-control form-control-lg"
                                                name="confirm_password" id="cpwd" placeholder="Confirm Password"
                                                required>
                                            <i class="fas fa-eye eye-icon" onclick="togglePassword('cpwd', this)"></i>
                                        </div>

                                        <div class="mt-4">
                                            <button type="submit"
                                                class="btn btn-block btn-primary btn-lg font-weight-medium">
                                                Update Password
                                            </button>
                                        </div>

                                        <div class="text-center mt-3">
                                            <small>
                                                Remember your password?
                                                <a href="login.php">Login</a>
                                            </small>
                                        </div>

                                    </form>
                                </div>

                                <!-- RIGHT IMAGE -->
                                <div class="col-lg-6 mx-auto bg"></div>

                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- JS -->
    <script src="../vendors/js/vendor.bundle.base.js"></script>
    <script src="../js/off-canvas.js"></script>
    <script src="../js/hoverable-collapse.js"></script>
    <script src="../js/template.js"></script>
    <script src="../js/settings.js"></script>

    <!-- Toggle Password -->
    <script>
        function togglePassword(inputId, icon) {
            const input = document.getElementById(inputId);
            if (input.type === "password") {
                input.type = "text";
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = "password";
                icon.classList.remove('fa-eye-slash');
            }
        }
    </script>

    <!-- SweetAlert -->
    <?php
    if (isset($_SESSION['alert_icon'])) {
        $icon = $_SESSION['alert_icon'];
        $text = $_SESSION['alert_text'];
        $redirect = $_SESSION['alert_redirect'] ?? '';

        echo "<script>
        Swal.fire({
            icon: '$icon',
            text: '$text',
            timer: 2500,
            showConfirmButton: false
        }).then(() => {
            " . ($redirect ? "window.location.href = '$redirect';" : "") . "
        });
    </script>";

        unset($_SESSION['alert_icon']);
        unset($_SESSION['alert_text']);
        unset($_SESSION['alert_redirect']);
    }
    ?>

</body>

</html>