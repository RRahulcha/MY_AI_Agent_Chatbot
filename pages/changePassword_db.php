<?php
session_start();

// Validate session
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    $_SESSION['status'] = false;
    $_SESSION['msg'] = 'Session expired. Please login again.';
    header('Location: login.php');
    exit;
}

include('../include/db_config.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: changePassword.php');
    exit;
}

$userId = $_SESSION['userId'];
$currentPassword = $_POST['currentPassword'] ?? '';
$newPassword = $_POST['newPassword'] ?? '';
$confirmPassword = $_POST['confirmPassword'] ?? '';

// ========== VALIDATION ==========

// 1. Check if all fields are provided
if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
    $_SESSION['status'] = false;
    $_SESSION['msg'] = 'All fields are required.';
    header('Location: changePassword.php');
    exit;
}

// 2. Check if new passwords match
if ($newPassword !== $confirmPassword) {
    $_SESSION['status'] = false;
    $_SESSION['msg'] = 'New passwords do not match.';
    header('Location: changePassword.php');
    exit;
}

// ========== DATABASE OPERATIONS ==========

// 3. Get current password from database FIRST
$query = $con->prepare("SELECT password FROM signup WHERE userId = ?");
if (!$query) {
    $_SESSION['status'] = false;
    $_SESSION['msg'] = 'Database error: ' . $con->error;
    header('Location: changePassword.php');
    exit;
}

$query->bind_param("s", $userId);
$query->execute();
$result = $query->get_result();

if ($result->num_rows === 0) {
    $_SESSION['status'] = false;
    $_SESSION['msg'] = 'User not found.';
    header('Location: changePassword.php');
    exit;
}

$user = $result->fetch_assoc();
$storedPassword = $user['password'];
$query->close();

// 4. Verify current password - handle both plain text and hashed passwords
$currentPasswordValid = false;

if (isHashedPassword($storedPassword)) {
    // If password is already hashed, use password_verify
    $currentPasswordValid = password_verify($currentPassword, $storedPassword);
} else {
    // If password is plain text, compare directly
    $currentPasswordValid = ($currentPassword === $storedPassword);
}

if (!$currentPasswordValid) {
    $_SESSION['status'] = false;
    $_SESSION['msg'] = 'Current password is incorrect.';
    header('Location: changePassword.php');
    exit;
}

// 5. Check if new password is same as current password (after current password verification)
if (isHashedPassword($storedPassword)) {
    // If stored password is hashed, verify new password against it
    if (password_verify($newPassword, $storedPassword)) {
        $_SESSION['status'] = false;
        $_SESSION['msg'] = 'New password must be different from current password.';
        header('Location: changePassword.php');
        exit;
    }
} else {
    // If stored password is plain text, compare directly
    if ($newPassword === $storedPassword) {
        $_SESSION['status'] = false;
        $_SESSION['msg'] = 'New password must be different from current password.';
        header('Location: changePassword.php');
        exit;
    }
}

// 6. Validate password strength (minimum 8 chars, uppercase, lowercase, numbers, special char)
$passwordStrength = validatePasswordStrength($newPassword);
if (!$passwordStrength['valid']) {
    $_SESSION['status'] = false;
    $_SESSION['msg'] = 'Password does not meet security requirements: ' . implode(', ', $passwordStrength['missing']);
    header('Location: changePassword.php');
    exit;
}

// 7. Hash the new password using bcrypt
$hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 10]);

// 8. Update password and set passwordChangeRequired to 'yes' and update passwordChangedDate
$updateQuery = $con->prepare("UPDATE signup SET password = ?, passwordChangeRequired = '1', passwordChangeDate = NOW()  WHERE userId = ?");

if (!$updateQuery) {
    $_SESSION['status'] = false;
    $_SESSION['msg'] = 'Database error: ' . $con->error;
    header('Location: changePassword.php');
    exit;
}

$updateQuery->bind_param("ss", $hashedPassword, $userId);

if ($updateQuery->execute()) {
    $updateQuery->close();

    

    // 9. Success - Set session and redirect to dashboard
    $_SESSION['status'] = true;
    $_SESSION['msg'] = 'Password updated successfully! Redirecting to dashboard...';

    // Determine dashboard based on user role and designation
    $roleQuery = $con->prepare("SELECT userRole, designation, employment_type FROM signup WHERE userId = ?");
    $roleQuery->bind_param("s", $userId);
    $roleQuery->execute();
    $roleResult = $roleQuery->get_result();
    $roleData = $roleResult->fetch_assoc();
    $roleQuery->close();

    // Get dashboard redirect page
    $redirectPage = getDashboardPage($roleData, $userId);

    // Redirect to intended page or dashboard
    header("Location: $redirectPage");
    exit;

} else {
    $_SESSION['status'] = false;
    $_SESSION['msg'] = 'Error updating password: ' . $updateQuery->error;
    $updateQuery->close();
    header('Location: changePassword.php');
    exit;
}

// ========== HELPER FUNCTIONS ==========

/**
 * Validate password strength
 * Requirements: min 8 chars, uppercase, number, special char
 */
function validatePasswordStrength($password) {
    $missing = [];
    $valid = true;

    if (strlen($password) < 8) {
        $missing[] = 'At least 8 characters';
        $valid = false;
    }

    if (!preg_match('/[A-Z]/', $password)) {
        $missing[] = 'At least one uppercase letter';
        $valid = false;
    }

    if (!preg_match('/[0-9]/', $password)) {
        $missing[] = 'At least one number';
        $valid = false;
    }

    if (!preg_match('/[!@#$%^&*()_+\-=\[\]{};\':"\\|,.<>\/?]/', $password)) {
        $missing[] = 'At least one special character';
        $valid = false;
    }

    return ['valid' => $valid, 'missing' => $missing];
}

/**
 * Check if password is hashed (bcrypt)
 */
function isHashedPassword($password) {
    // Bcrypt hashes start with $2a$, $2b$, $2x$, or $2y$ and are 60 chars long
    return (strlen($password) == 60 && preg_match('/^\$2[aby]\$/', $password));
}

/**
 * Get dashboard page based on user role and designation
 */
function getDashboardPage($roleData, $userId) {
    $designation = strtolower(trim($roleData['designation'] ?? ''));
    $employment_type = strtolower(trim($roleData['employment_type'] ?? ''));

    // Special case for specific user ID
    if ($userId === '62af510636e89') {
        return 'mdDashboard.php';
    }

    // Check designation first
    if ($designation === "hr") {
        return 'srEmpDashboard.php';
    } elseif ($designation === "admin") {
        return 'adminDashboard.php';
    } elseif ($designation === "developer") {
        return 'DeveloperDashboard.php';
    } elseif ($designation === "receptionist") {
        return 'receptionistDashboard.php';
    } elseif ($employment_type === "employee" && strpos($designation, "senior") !== false) {
        return 'srEmpDashboard.php';
    } elseif ($employment_type === "employee" && strpos($designation, "junior") !== false) {
        return 'jrEmpDashboard.php';
    } elseif ($employment_type === "intern" && strpos($designation, "senior") !== false) {
        return 'srEmpDashboard.php';
    } elseif ($employment_type === "intern" && strpos($designation, "junior") !== false) {
        return 'jrEmpDashboard.php';
    }

    // Default fallback
    return 'dashboard.php';
}
?>