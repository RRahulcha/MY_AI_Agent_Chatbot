<?php

if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    // No user session, skip check
    return;
}

include_once(__DIR__ . '/db_config.php');

$userId = $_SESSION['userId'];

// Check password change status
$passwordCheckQuery = $con->prepare("SELECT passwordChangeRequired FROM signup WHERE userId = ?");
$passwordCheckQuery->bind_param("s", $userId);
$passwordCheckQuery->execute();
$passwordCheckResult = $passwordCheckQuery->get_result();

if ($passwordCheckResult->num_rows > 0) {
    $passwordData = $passwordCheckResult->fetch_assoc();
    $passwordChangeRequired = $passwordData['passwordChangeRequired'];

    // Check if password change is required (value is 0 or 'no')
    $needsPasswordChange = false;

    if (is_numeric($passwordChangeRequired)) {
        // If it's numeric (0 = needs change, 1 = changed)
        $needsPasswordChange = ($passwordChangeRequired == 0);
    } else {
        // If it's string ('yes' = changed, 'no' = needs change)
        $needsPasswordChange = (strtolower(trim($passwordChangeRequired)) === 'no');
    }

    // If user needs to change password and is NOT on the change password page
    if ($needsPasswordChange && basename($_SERVER['PHP_SELF']) !== 'changePassword.php') {
        // Set a flag that JavaScript will check - NO EXIT, LET PAGE LOAD
        echo '<script>window.passwordChangeRequired = true;</script>';
    }
}

$passwordCheckQuery->close();
?>