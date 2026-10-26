<?php
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', 2592000);
    session_set_cookie_params(2592000);
    session_start();
}
ob_start();

if (isset($_REQUEST['logIn'])) {
    include('../include/db_config.php');
    include('../include/notifications.php'); // ✅ Include your FCM notification helper

    $username = mysqli_real_escape_string($con, $_POST['username']);
    $password = $_POST['password']; // Don't escape - we'll use it for password_verify

    // Use prepared statement to prevent SQL injection
    $query = $con->prepare("SELECT * FROM signup WHERE username = ?");
    $query->bind_param("s", $username);
    $query->execute();
    $result = $query->get_result();

    if ($result->num_rows > 0) {
        $userData = $result->fetch_assoc();
        $query->close();

        // ========== PASSWORD VERIFICATION ==========
        $storedPassword = $userData['password'];
        $passwordValid = false;

        // Check if password is hashed or plain text
        if (isHashedPassword($storedPassword)) {
            // Password is hashed - use password_verify
            $passwordValid = password_verify($password, $storedPassword);
        } else {
            // Password is plain text - compare directly
            $passwordValid = ($password === $storedPassword);
            
            // ✅ OPTIONAL: Auto-upgrade plain text to hashed on successful login
            if ($passwordValid) {
                $hashedPassword = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
                $upgradeQuery = $con->prepare("UPDATE signup SET password = ? WHERE userId = ?");
                $upgradeQuery->bind_param("ss", $hashedPassword, $userData['userId']);
                $upgradeQuery->execute();
                $upgradeQuery->close();
            }
        }

        // If password is invalid, redirect with error
        if (!$passwordValid) {
            $_SESSION['alert_icon'] = 'error';
            $_SESSION['alert_title'] = 'Invalid Credentials';
            $_SESSION['alert_text'] = 'Invalid Username or Password. Please try again.';
            header("Location: login.php");
            if (isset($con)) { $con->close(); } exit;
        }

        // ========== REST OF YOUR LOGIN LOGIC ==========

        /* 🔴 BLOCK INACTIVE USERS */
        if (strtolower($userData['status']) === 'inactive') {
            $_SESSION['alert_icon']  = 'error';
            $_SESSION['alert_title'] = 'Account Disabled';
            $_SESSION['alert_text']  = 'Your account has been disabled. Contact HR if you believe this is an error.';
            header("Location: login.php");
            if (isset($con)) { $con->close(); } exit;
        }

        // 🟡 Pending
        if (strtolower($userData['regstatus']) == "pending") {
            $_SESSION['alert_icon'] = 'info';
            $_SESSION['alert_title'] = 'Pending Approval';
            $_SESSION['alert_text'] = 'Please waiting for approval.';
            header("Location: login.php");
            if (isset($con)) { $con->close(); } exit;
        }

        // 🔴 Rejected
        if (strtolower($userData['regstatus']) == "rejected") {
            $_SESSION['alert_icon'] = 'error';
            $_SESSION['alert_title'] = 'Registration Rejected';
            $_SESSION['alert_text'] = 'Your registration has been rejected by HR.';
            header("Location: login.php");
            if (isset($con)) { $con->close(); } exit;
        }

        // 🗓 Date validation
        $today = date('Y-m-d');
        $start_date = $userData['start_date'];
        $end_date = $userData['end_date'];

        if (!empty($start_date) && $today < $start_date) {
            $_SESSION['alert_icon'] = 'warning';
            $_SESSION['alert_title'] = 'Login Not Started Yet';
            $_SESSION['alert_text'] = "Your internship starts on $start_date. Please try again later.";
            header("Location: login.php");
            if (isset($con)) { $con->close(); } exit;
        }

        if (!empty($end_date) && $today > $end_date) {
            $_SESSION['alert_icon'] = 'error';
            $_SESSION['alert_title'] = 'Internship Ended';
            $_SESSION['alert_text'] = "Your internship ended on $end_date. You can no longer login.";
            header("Location: login.php");
            if (isset($con)) { $con->close(); } exit;
        }

        // ✅ Generate and store secure token
        $token = $_POST['token'] ?? ''; // get token from POST (or empty string)
        $userId = $userData['userId'];
        if (!empty($token)) {
            $updateToken = $con->prepare("UPDATE signup SET token=? WHERE userId=?");
            $updateToken->bind_param("ss", $token, $userId);
            $updateToken->execute();
            $updateToken->close();
        }

        // ✅ Store in session too (optional)
        $_SESSION['userId'] = $userData['userId'];
        $_SESSION['userRole'] = $userData['userRole'];
        $_SESSION['designation'] = $userData['designation'];
        $_SESSION['token'] = $token;
        $_SESSION['modal_shown'] = 'false';

        // ✅ Always set persistent cookies (30 days) to keep the user signed in
        setcookie('userId', $userData['userId'], time() + (86400 * 30), '/');
        setcookie('userRole', $userData['userRole'], time() + (86400 * 30), '/');
        setcookie('designation', $userData['designation'], time() + (86400 * 30), '/');
        setcookie('modal_shown', 'false', time() + (86400 * 30), '/');
        setcookie('token', $token, time() + (86400 * 30), '/');

        // 🔥 ✅ PASSWORD CHANGE CHECK
        if ($userData['passwordChangeRequired'] == 0) {
            $_SESSION['alert_icon'] = 'warning';
            $_SESSION['alert_title'] = 'Change Password Required';
            $_SESSION['alert_text'] = 'Please change your password before continuing.';
            $_SESSION['redirect_after_login'] = 'changePassword.php';
            $_SESSION['show_refresh_warning'] = true;

            header("Location: login.php");
            if (isset($con)) { $con->close(); } exit;
        }

// ✅ Redirect Logic
$designation = strtolower(trim(preg_replace('/\s+/', '', $userData['designation'])));
$employment_type = strtolower(trim($userData['employment_type']));
$redirectURL = '';

if ($designation === "hr") {

    $redirectURL = 'hrDashboard.php';

} elseif ($designation === "resourceallocator") {

    $redirectURL = 'resourceHeadDashboard.php';

} elseif ($designation === "admin") {

    // 🔹 Special admin dashboard
    if ($userId === '62af510636e89') {
        $redirectURL = 'mdDashboard.php';
    } else {
        $redirectURL = 'adminDashboard.php';
    }

} elseif ($designation === "developer") {

    $redirectURL = 'DeveloperDashboard.php';

} elseif ($employment_type === "employee" && strpos($designation, "senior") !== false) {

    $redirectURL = 'srEmpDashboard.php';

} elseif ($employment_type === "employee" && strpos($designation, "resourceAllocator") !== false) {

    $redirectURL = 'resourceHeadDashboard.php';

} elseif ($employment_type === "employee" && strpos($designation, "junior") !== false) {

    $redirectURL = 'jrEmpDashboard.php';

} elseif ($employment_type === "intern" && strpos($designation, "senior") !== false) {

    $redirectURL = 'srEmpDashboard.php';

} elseif ($employment_type === "intern" && strpos($designation, "junior") !== false) {

    $redirectURL = 'jrEmpDashboard.php';

} elseif ($designation === "receptionist") {

    $redirectURL = 'receptionistDashboard.php';

} elseif ($designation === "staff" || $designation === "employee" || $employment_type === "staff") {

    $redirectURL = 'staffDashboard.php';

} else {

    $_SESSION['alert_icon'] = 'error';
    $_SESSION['alert_title'] = 'Unauthorized Access';
    $_SESSION['alert_text'] = 'You are not authorized to access this section.';

    header("Location: login.php");
    if (isset($con)) { $con->close(); } exit;
}

        // ✅ Success Login
        $_SESSION['alert_icon'] = 'success';
        $_SESSION['alert_title'] = 'Login Successful!';
        $_SESSION['alert_text'] = "Welcome back, " . $userData['fullName'] . "!";
        $_SESSION['redirect_after_login'] = $redirectURL;

        // ✅ 🟢 SEND FCM NOTIFICATION (lazy — only fires if user has a device token)
        $fcmToken = $userData['token'];

        if (!empty($fcmToken)) {
            // getFirebaseAccessToken() does the JWT + OAuth round-trip ONLY here,
            // not on every page load — this is the key performance fix.
            $accessToken = function_exists('getFirebaseAccessToken') ? getFirebaseAccessToken() : null;

            if (!empty($accessToken)) {
                $fullName = trim($userData['fullName'] ?? $userData['name'] ?? $username);
                $title    = "Hello $fullName 👋";
                $body     = "Welcome to SETTribe!";
                $response = sendFCMNotification($fcmToken, $title, $body, $accessToken, $projectId);

                if ($response) {
                    $dateTime = date('Y-m-d H:i:s');
                    $stmt     = $con->prepare("INSERT INTO notifications (userId, title, message, status, is_read, created_at) VALUES (?, ?, ?, ?, 1, ?)");
                    $status   = 'sent';
                    $stmt->bind_param('sssss', $userId, $title, $body, $status, $dateTime);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }

        // ✅ Redirect to login.php to show SweetAlert then move to dashboard
        header("Location: login.php");
        if (isset($con)) { $con->close(); } exit;

    } else {
        // ❌ Invalid Credentials - User not found
        $_SESSION['alert_icon'] = 'error';
        $_SESSION['alert_title'] = 'Invalid Credentials';
        $_SESSION['alert_text'] = 'Invalid Username or Password. Please try again.';
        header("Location: login.php");
        if (isset($con)) { $con->close(); } exit;
    }
}
ob_end_flush();

// ========== HELPER FUNCTION ==========

/**
 * Check if password is hashed (bcrypt)
 */
function isHashedPassword($password) {
    // Bcrypt hashes start with $2a$, $2b$, $2x$, or $2y$ and are 60 chars long
    return (strlen($password) == 60 && preg_match('/^\$2[aby]\$/', $password));
}
?>
