<?php
session_start();
$token = ($_GET['token']) ?? '';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <title>SETTribe AMS - Forgot Password</title>
    <?php include('../partials/_links.php'); ?>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        .auth .brand-logo img {
            width: 215px;
        }

        .bg {
            background-image: url("../images/Picture2.png");
            background-color: #cccccc;
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
                                    <form class="p-5" method="POST" action="../ajax/send_otp.php">

                                        <div class="brand-logo text-center">
                                            <img src="../images/logos/SETTribe-logo.png" alt="logo">
                                        </div>

                                        <h2 class="page-title">Forgot Password</h2>
                                        <p class="page-subtitle">
                                            Enter your registered email address.<br>
                                            We’ll send you an OTP to reset your password.
                                        </p>

                                        <div class="form-group">
                                            <input type="email" class="form-control form-control-lg" name="email"
                                                placeholder="Registered Email Address" required>
                                        </div>

                                        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

                                        <div class="mt-4">
                                            <button type="submit"
                                                class="btn btn-block btn-primary btn-lg font-weight-medium">
                                                Send OTP
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

    <?php
    if (isset($_SESSION['alert_icon'])) {

        $icon = $_SESSION['alert_icon'];
        $text = $_SESSION['alert_text'];
        $redirect = $_SESSION['alert_redirect'] ?? '';
        $requireOk = !empty($_SESSION['alert_require_ok']);
        $countdown = $_SESSION['alert_countdown'] ?? 0;
        ?>
        <script>
            <?php if ($requireOk && $countdown > 0): ?>
                let remaining = <?= (int) $countdown ?>;
                Swal.fire({
                    icon: 'warning',
                    html: `
                    <div style="margin-top: 6px;">
                        <div style="
                            font-size: 22px;
                            font-weight: 600;
                            margin-bottom: 10px;
                            ">
                            Please wait
                        </div>

                    <div style="
                        font-size: 18px;
                        line-height: 1.5;
                    ">
                        You can request another OTP in
                    <strong style="font-size: 20px;">
                    <span id="sec">${remaining}</span> seconds
                </strong>
            </div>
        </div>
    `,
                    confirmButtonText: 'OK',
                    allowOutsideClick: false,
                    didOpen: () => {

                        const icon = Swal.getIcon();
                        if (icon) {
                            icon.style.marginTop = '16px';
                            icon.style.marginBottom = '12px';
                        }
                        const secEl = document.getElementById('sec');
                        const interval = setInterval(() => {
                            remaining--;
                            secEl.textContent = remaining;

                            if (remaining <= 0) {
                                clearInterval(interval);
                            }
                        }, 1000);
                    }
                }).then(() => {
                    <?php if (!empty($redirect)): ?>
                        window.location.href = '<?= $redirect ?>';
                    <?php endif; ?>
                });
            <?php else: ?>
                Swal.fire({
                    icon: '<?= $icon ?>',
                    text: '<?= $text ?>',
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => {
                    <?php if (!empty($redirect)): ?>
                        window.location.href = '<?= $redirect ?>';
                    <?php endif; ?>
                });
            <?php endif; ?>
        </script>
        <?php

        // cleanup
        unset($_SESSION['alert_icon']);
        unset($_SESSION['alert_text']);
        unset($_SESSION['alert_redirect']);
        unset($_SESSION['alert_require_ok']);
        unset($_SESSION['alert_countdown']);
    }
    ?>

</body>

</html>