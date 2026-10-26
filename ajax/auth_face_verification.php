<?php
/**
 * Face Verification - Employee Pre-Authentication Endpoint
 * Settribe AMS
 * Validates Employee ID, Email, and Password before allowing 3D Motion Face Verification & enrollment.
 * If user is already logged in, automatically authorizes without asking for credentials.
 */
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../include/db_config.php';

// Helper function to check if password is a bcrypt hash
if (!function_exists('isHashedPassword')) {
    function isHashedPassword($password) {
        return (strlen($password) == 60 && preg_match('/^\$2[aby]\$/', $password));
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method Not Allowed. Use POST.'
    ]);
    exit;
}

if (!isset($con) || !$con) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection unavailable.'
    ]);
    exit;
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
if (empty($data)) {
    $data = $_POST;
}

// ── 1. If user is already logged in into AMS session, authorize automatically ──
if (!empty($_SESSION['userId']) && empty($data['force_credentials'])) {
    $sessionUserId = strval($_SESSION['userId']);
    $stmt = $con->prepare("
        SELECT userId, username, fullName, email, designation, userRole, profilePhoto, status 
        FROM signup 
        WHERE userId = ? 
        LIMIT 1
    ");

    if ($stmt) {
        $stmt->bind_param("s", $sessionUserId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $user = $result->fetch_assoc()) {
            $stmt->close();

            if (!empty($user['status']) && strtolower($user['status']) === 'inactive') {
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'message' => 'Your account is disabled/inactive. Please contact HR.'
                ]);
                exit;
            }

            $_SESSION['face_auth_verified_user'] = $user['userId'];
            $_SESSION['face_auth_timestamp']     = time();

            echo json_encode([
                'success' => true,
                'logged_in' => true,
                'message' => 'Session authenticated automatically for ' . ($user['fullName'] ?: $user['userId']),
                'user' => [
                    'userId'       => $user['userId'],
                    'username'     => $user['username'],
                    'fullName'     => $user['fullName'],
                    'email'        => $user['email'] ?? '',
                    'designation'  => $user['designation'] ?: ($user['userRole'] ?: 'Employee'),
                    'userRole'     => $user['userRole'] ?: '',
                    'profilePhoto' => $user['profilePhoto'] ?: ''
                ]
            ]);
            exit;
        }
        $stmt->close();
    }
}

// ── 2. Logged out state: Require Employee ID, Registered Email, and Password ──
$employeeId = trim($data['employee_id'] ?? $data['user_id'] ?? '');
$email      = trim($data['email'] ?? '');
$password   = $data['password'] ?? '';

if (empty($employeeId)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Please enter your Employee ID or Username.'
    ]);
    exit;
}

if (empty($email)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Please enter your registered Email Address.'
    ]);
    exit;
}

if (empty($password)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Please enter your account Password.'
    ]);
    exit;
}

// Find employee by Employee ID, Intern ID, User ID, Username, or Email
$stmt = $con->prepare("
    SELECT userId, emp_id, internId, username, fullName, email, password, designation, userRole, profilePhoto, status 
    FROM signup 
    WHERE userId = ? 
       OR emp_id = ? 
       OR internId = ? 
       OR username = ? 
       OR email = ?
    LIMIT 1
");

if (!$stmt) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database query preparation failed: ' . $con->error
    ]);
    exit;
}

$stmt->bind_param("sssss", $employeeId, $employeeId, $employeeId, $employeeId, $employeeId);
$stmt->execute();
$result = $stmt->get_result();

// Secondary check by email if employee ID had typo or user typed username/email
if (($result->num_rows === 0) && !empty($email)) {
    $stmt->close();
    $stmt = $con->prepare("
        SELECT userId, emp_id, internId, username, fullName, email, password, designation, userRole, profilePhoto, status 
        FROM signup 
        WHERE email = ? OR username = ? 
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param("ss", $email, $email);
        $stmt->execute();
        $result = $stmt->get_result();
    }
}

if (!$result || $result->num_rows === 0) {
    if (isset($stmt) && $stmt) { $stmt->close(); }
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => "No employee found with Employee ID: '{$employeeId}'. Please verify your Employee ID or Intern ID."
    ]);
    exit;
}

$user = $result->fetch_assoc();
$stmt->close();

// Check if user is active
if (!empty($user['status']) && strtolower($user['status']) === 'inactive') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'This account is currently disabled/inactive. Please contact HR.'
    ]);
    exit;
}

// Verify password first
$storedPassword = $user['password'] ?? '';
$passwordValid  = false;

if (isHashedPassword($storedPassword)) {
    $passwordValid = password_verify($password, $storedPassword);
} else {
    // Plain-text fallback
    $passwordValid = ($password === $storedPassword);
}

if (!$passwordValid) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Incorrect password. Please verify your credentials and try again.'
    ]);
    exit;
}

// Auto-upgrade plain text to bcrypt hash upon successful authentication
if (!empty($password) && !isHashedPassword($storedPassword)) {
    $hashed = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
    $upStmt = $con->prepare("UPDATE signup SET password = ? WHERE userId = ?");
    if ($upStmt) {
        $upStmt->bind_param("ss", $hashed, $user['userId']);
        $upStmt->execute();
        $upStmt->close();
    }
}

// Sync email: If user verified their Employee ID & Password, update email to current input if different
$storedEmail = strtolower(trim($user['email'] ?? ''));
$inputEmail  = strtolower(trim($email));

if (!empty($inputEmail) && $storedEmail !== $inputEmail) {
    $upMail = $con->prepare("UPDATE signup SET email = ? WHERE userId = ?");
    if ($upMail) {
        $upMail->bind_param("ss", $inputEmail, $user['userId']);
        $upMail->execute();
        $upMail->close();
        $user['email'] = $inputEmail;
    }
}

// Authentication succeeded! Set session verification flags
$_SESSION['face_auth_verified_user'] = $user['userId'];
$_SESSION['face_auth_timestamp']     = time();

echo json_encode([
    'success' => true,
    'logged_in' => false,
    'message' => 'Employee authentication verified successfully!',
    'user' => [
        'userId'       => $user['userId'],
        'username'     => $user['username'],
        'fullName'     => $user['fullName'],
        'email'        => $user['email'] ?? '',
        'designation'  => $user['designation'] ?: ($user['userRole'] ?: 'Employee'),
        'userRole'     => $user['userRole'] ?: '',
        'profilePhoto' => $user['profilePhoto'] ?: ''
    ]
]);
