<?php
// SETTribe AMS Dev connection page

// ✅ Delegate to central db_config if available, or initialize directly
if (file_exists(__DIR__ . '/db_config.php')) {
    require_once __DIR__ . '/db_config.php';
} else {
    // ✅ Start session only if not started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // ✅ Database connection
    $con = mysqli_connect("103.160.107.18", "nmrmlatur_settribe_ams", "SETTribe@ams1821", "nmrmlatur_settribe_ams");

    // ✅ Set timezone
    date_default_timezone_set('Asia/Kolkata');

    // ✅ Multiple connection variable aliases
    $conn = $con;
    $dbconnect = $con;
    $connect = $con;
    $db = $con;

    // ✅ Use cookie only if it exists
    if (isset($_COOKIE['userId']) && !empty($_COOKIE['userId'])) {
        $_SESSION['userId'] = $_COOKIE['userId'];
    }
}
?>