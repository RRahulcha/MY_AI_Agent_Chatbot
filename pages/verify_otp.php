<?php
session_start();

// 🔐 Prevent direct access
if (!isset($_SESSION['otp_email'])) {
    $_SESSION['alert_icon'] = 'error';
    $_SESSION['alert_text'] = 'Unauthorized Access';
    $_SESSION['alert_redirect'] = '../pages/forgot_password.php';
    header("Location: forgot_password.php");
    exit;
}
?>
<?php
$remaining = 0;
if (isset($_SESSION['otp_time'])) {
    $remaining = max(0, 60 - (time() - $_SESSION['otp_time']));
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Verify OTP | SETTribe AMS</title>

    <?php include('../partials/_links.php'); ?>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        .auth-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f4f6fb;
        }

        .auth-card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            display: flex;
            overflow: hidden;
            max-width: 900px;
            width: 100%;
        }

        .auth-left {
            padding: 40px;
            flex: 1;
        }

        .auth-right {
            flex: 1;
            background: url("../images/Picture2.png") center/cover no-repeat;
        }

        .brand-logo img {
            width: 220px;
            margin-bottom: 20px;
        }

        .page-title {
            font-size: 20px;
            font-weight: 700;
            color: #2f55ff;
        }

        .page-subtitle {
            font-size: 15px;
            color: #555;
            margin-bottom: 25px;
        }
    </style>
</head>

<body>

    <div class="auth-container">
        <div class="auth-card">

            <!-- LEFT -->
            <div class="auth-left">
                <div class="brand-logo">
                    <img src="../images/logos/SETTribe-logo.png" alt="SETTribe Logo">
                </div>

                <h2 class="page-title">Verify OTP</h2>
                <p class="page-subtitle">
                    Enter the 6-digit OTP sent to your email
                </p>

                <form method="POST" action="../ajax/verify_otp_ajax.php">
                    <div class="form-group">
                        <input type="tel" name="otp" id="otp" class="form-control form-control-lg"
                            placeholder="Enter 6-digit OTP" maxlength="6" required>
                    </div>

                    <button class="btn btn-primary btn-lg w-100 mt-3">
                        Verify OTP
                    </button>
                </form>

                <div class="text-center mt-4">
                    <form method="POST" action="../ajax/resend_otp.php">
                        <button type="submit" id="resendBtn" class="btn btn-link p-0" <?= $remaining > 0 ? 'disabled' : '' ?>>
                            Resend OTP
                        </button>
                    </form>

                    <small id="timerText" class="text-muted d-block mt-1">
                        <?php if ($remaining > 0): ?>
                            You can resend OTP in <span id="countdown"><?= $remaining ?></span>s
                        <?php endif; ?>
                    </small>
                </div>

            </div>

            <!-- RIGHT -->
            <div class="auth-right d-none d-lg-block"></div>

        </div>
    </div>

    <!-- ✅ SWEETALERT SESSION ALERT -->
    <?php
    if (isset($_SESSION['alert_icon'])) {

        $icon = $_SESSION['alert_icon'];
        $text = $_SESSION['alert_text'];
        $redirect = $_SESSION['alert_redirect'] ?? '';
    ?>

        <script>
            Swal.fire({
                icon: '<?= $icon ?>',
                text: '<?= $text ?>',
                timer: 2500,
                showConfirmButton: false
            }).then(() => {
                <?php if (!empty($redirect)): ?>
                    window.location.href = '<?= $redirect ?>';
                <?php endif; ?>
            });
        </script>

    <?php
        unset($_SESSION['alert_icon']);
        unset($_SESSION['alert_text']);
        unset($_SESSION['alert_redirect']);
    }
    ?>

    <script>
        const mobileInput = document.getElementById('otp');
        mobileInput.addEventListener('input', function() {
            this.value = this.value.replace(/\D/g, '');
            if (this.value.length > 10) {
                this.value = this.value.slice(0, 10);
            }
        });
        let remaining = <?= $remaining ?>;
        const countdownEl = document.getElementById('countdown');
        const resendBtn = document.getElementById('resendBtn');

        if (remaining > 0) {
            const interval = setInterval(() => {
                remaining--;
                if (countdownEl) countdownEl.textContent = remaining;

                if (remaining <= 0) {
                    clearInterval(interval);
                    resendBtn.disabled = false;
                    document.getElementById('timerText').textContent = 'You can resend OTP now';
                }
            }, 1000);
        }
    </script>

</body>

</html>