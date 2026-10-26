<?php
/**
 * Logout Script
 * Handles complete session and cookie cleanup for secure logout
 */

// ✅ Start the session first (required to access session variables)
session_start();

// ✅ Delete all custom cookies (set expiry time to past)
// Make sure the path matches where cookies were set
if (isset($_COOKIE['userId'])) {
    setcookie('userId', '', time() - 3600, '/');
    unset($_COOKIE['userId']);
}
if (isset($_COOKIE['userRole'])) {
    setcookie('userRole', '', time() - 3600, '/');
    unset($_COOKIE['userRole']);
}
if (isset($_COOKIE['designation'])) {
    setcookie('designation', '', time() - 3600, '/');
    unset($_COOKIE['designation']);
}
if (isset($_COOKIE['token'])) {
    setcookie('token', '', time() - 3600, '/');
    unset($_COOKIE['token']);
}
if (isset($_COOKIE['modal_shown'])) {
    setcookie('modal_shown', '', time() - 3600, '/');
    unset($_COOKIE['modal_shown']);
}

// ✅ Clear all session variables
session_unset();

// ✅ Destroy the session completely
session_destroy();

// ✅ Also delete the session cookie itself (recommended for complete cleanup)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), 
        '', 
        time() - 3600,
        $params["path"], 
        $params["domain"],
        $params["secure"], 
        $params["httponly"]
    );
}

// ✅ Prevent browser caching of this page
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// ✅ Redirect to login page using header (preferred method)
header('Location: login.php');
exit;
?>