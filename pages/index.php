<?php
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', 2592000);
    session_set_cookie_params(2592000);
    session_start();
}

require_once('../include/db_config.php');

// ✅ Build query string for redirects
$q = "?";
if (isset($_SESSION['VERSION'])) {
    $q .= "VERSION=" . urlencode($_SESSION['VERSION']);
}
if (isset($_SESSION['token'])) {
    $q .= (strlen($q) > 1 ? "&" : "") . "token=" . urlencode($_SESSION['token']);
}

// ✅ Check if user is logged in via cookie
if (!isset($_COOKIE['userId'])) {
    // Clear any stale cookies
    if (isset($_COOKIE['userId'])) {
        setcookie('userId', '', time() - 3600, '/');
    }
    if (isset($_COOKIE['userRole'])) {
        setcookie('userRole', '', time() - 3600, '/');
    }

    header("Location: login.php$q");
    if (isset($con)) {
        $con->close();
    }
    exit;
}

// ✅ Set session from cookie
$_SESSION['userId'] = $_COOKIE['userId'];

// ✅ Optimized: Use prepared statement to prevent SQL injection
$stmt = $con->prepare("SELECT userId, userRole, designation, employment_type, token FROM signup WHERE userId = ? LIMIT 1");
$stmt->bind_param("s", $_COOKIE['userId']);
$stmt->execute();
$result = $stmt->get_result();

// ✅ If user not found, logout
if ($result->num_rows === 0) {
    $stmt->close();
    setcookie('userId', '', time() - 3600, '/');
    setcookie('userRole', '', time() - 3600, '/');
    setcookie('designation', '', time() - 3600, '/');
    setcookie('token', '', time() - 3600, '/');
    header("Location: login.php$q");
    if (isset($con)) {
        $con->close();
    }
    exit;
}

$userData = $result->fetch_assoc();
$stmt->close();

// ✅ Restore session details
$_SESSION['userRole'] = $userData['userRole'];
$_SESSION['designation'] = $userData['designation'];
$_SESSION['employment_type'] = $userData['employment_type'] ?? '';

// ✅ Update FCM token if needed
if (isset($_SESSION['token']) && !empty($_SESSION['token'])) {
    if ($userData['token'] !== $_SESSION['token'] && empty($userData['token'])) {
        $updateStmt = $con->prepare("UPDATE signup SET token = ? WHERE userId = ?");
        $updateStmt->bind_param("ss", $_SESSION['token'], $_COOKIE['userId']);
        $updateStmt->execute();
        $updateStmt->close();
    }
}

// ✅ Optimized: Route user based on designation (using switch for better performance)
$designation = strtolower(trim(preg_replace('/\s+/', '', $userData['designation'] ?? '')));
$employmentType = strtolower(trim($userData['employment_type'] ?? ''));
$userRole = strtolower(trim($userData['userRole'] ?? ''));
$redirectURL = '';

switch ($designation) {
    case 'hr':
        // ✅ FIXED: HR should go to hrDashboard, not srEmpDashboard
        $redirectURL = 'hrDashboard.php';
        break;

    case 'admin':
        // Special admin check
        $redirectURL = ($_COOKIE['userId'] === '62af510636e89') ? 'mdDashboard.php' : 'adminDashboard.php';
        break;

    case 'md':
        $redirectURL = 'mdDashboard.php';
        break;

    case 'seniorintern':
        $redirectURL = 'srEmpDashboard.php';
        break;

    case 'juniorintern':
        $redirectURL = 'jrEmpDashboard.php';
        break;

    case 'developer':
        $redirectURL = 'DeveloperDashboard.php';
        break;

    case 'receptionist':
        $redirectURL = 'receptionistDashboard.php';
        break;

    case 'staff':
    case 'employee':
        $redirectURL = 'staffDashboard.php';
        break;

    case 'resourceallocator':
    case 'resourcehead':
        $redirectURL = 'resourceHeadDashboard.php';
        break;

    default:
        if (strpos($designation, 'senior') !== false) {
            $redirectURL = 'srEmpDashboard.php';
        } elseif (strpos($designation, 'junior') !== false) {
            $redirectURL = 'jrEmpDashboard.php';
        } elseif ($designation === 'staff' || $employmentType === 'staff' || $userRole === 'staff' || $userRole === 'employee') {
            $redirectURL = 'staffDashboard.php';
        } else {
            // Invalid role - clear cookies and redirect to login
            setcookie('userId', '', time() - 3600, '/');
            setcookie('userRole', '', time() - 3600, '/');
            setcookie('designation', '', time() - 3600, '/');
            setcookie('token', '', time() - 3600, '/');
            header("Location: login.php$q");
            if (isset($con)) {
                $con->close();
            }
            exit;
        }
        break;
}

// ✅ Perform redirect
header("Location: $redirectURL");
if (isset($con)) {
    $con->close();
}
exit;
?>
<!DOCTYPE html>
<html>

<head>
    <title>SETTribe's AMS</title>
</head>

<body>
    <!-- Fallback in case headers already sent -->
    <script>window.location.href = '<?= htmlspecialchars($redirectURL ?? "login.php$q") ?>';</script>
</body>

</html>