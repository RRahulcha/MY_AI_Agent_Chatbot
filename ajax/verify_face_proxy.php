<?php
/**
 * Face Verification & 3D Motion Enrollment Proxy Endpoint
 * Settribe AMS
 * Relays multi-pose face captures (frontal + directional movement frames)
 * to local AI Engine for 512-D composite embedding registration & photo update.
 * Enforces mandatory GPS Geolocation and authentication verification.
 */
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../include/db_config.php';

// Helper function to check if password is bcrypt hash
if (!function_exists('isHashedPassword')) {
    function isHashedPassword($password) {
        return (strlen($password) == 60 && preg_match('/^\$2[aby]\$/', $password));
    }
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (empty($data) || empty($data['frontal_image'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Frontal camera frame is required for biometric verification'
    ]);
    exit;
}

// ── Mandatory Location Validation ──
$latitude  = isset($data['latitude']) ? floatval($data['latitude']) : 0.0;
$longitude = isset($data['longitude']) ? floatval($data['longitude']) : 0.0;

if (empty($latitude) || empty($longitude) || ($latitude == 0.0 && $longitude == 0.0)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'GPS Location is mandatory for 3D verification. Please allow location access.'
    ]);
    exit;
}

$userId = !empty($data['user_id']) ? strval($data['user_id']) : (!empty($_SESSION['userId']) ? strval($_SESSION['userId']) : null);
$name = !empty($data['name']) ? strval($data['name']) : (!empty($_SESSION['fullName']) ? strval($_SESSION['fullName']) : $userId);

if (empty($userId)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Employee ID is required for verification registration'
    ]);
    exit;
}

// Security Check: Verify employee authenticated
$isAuthorized = false;

// 1. Logged in session match: if user is currently logged in, they can update their biometric profile
if (!empty($_SESSION['userId']) && strval($_SESSION['userId']) === strval($userId)) {
    $isAuthorized = true;
}

// 2. Pre-authentication via auth_face_verification.php
if (!$isAuthorized && !empty($_SESSION['face_auth_verified_user']) && strval($_SESSION['face_auth_verified_user']) === strval($userId)) {
    $authTime = $_SESSION['face_auth_timestamp'] ?? 0;
    if ((time() - $authTime) < 1800) { // 30 minutes auth window
        $isAuthorized = true;
    }
}

// 3. Fallback: email & password provided directly in request
if (!$isAuthorized && !empty($data['password']) && !empty($data['email']) && isset($con) && $con) {
    $stmt = $con->prepare("
        SELECT userId, emp_id, internId, email, password, status 
        FROM signup 
        WHERE userId = ? 
           OR emp_id = ? 
           OR internId = ? 
           OR username = ? 
           OR email = ? 
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param("sssss", $userId, $userId, $userId, $userId, $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $row = $res->fetch_assoc()) {
            $sp = $row['password'] ?? '';
            $pwMatch = isHashedPassword($sp) ? password_verify($data['password'], $sp) : ($data['password'] === $sp);
            
            if ($pwMatch && strtolower($row['status'] ?? '') !== 'inactive') {
                $isAuthorized = true;
                $userId = $row['userId'];
                $_SESSION['face_auth_verified_user'] = $row['userId'];
                $_SESSION['face_auth_timestamp'] = time();
            }
        }
        $stmt->close();
    }
}

if (!$isAuthorized) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Authentication required: Please verify your Employee ID, Email, and Password before 3D face verification.'
    ]);
    exit;
}

$payload = [
    'user_id' => $userId,
    'name' => $name,
    'frontal_image' => $data['frontal_image'],
    'left_image' => $data['left_image'] ?? null,
    'right_image' => $data['right_image'] ?? null,
    'liveness_image' => $data['liveness_image'] ?? null,
    'poses' => $data['poses'] ?? [],
    'metadata' => [
        'source' => 'web_motion_verifier',
        'enrolled_by' => $_SESSION['username'] ?? 'system',
        'role' => $_SESSION['userRole'] ?? '',
        'latitude' => $latitude,
        'longitude' => $longitude,
        'verified_at' => date('Y-m-d H:i:s')
    ]
];

$aiBaseUrl = getenv('AI_SERVICE_URL') ?: ($_ENV['AI_SERVICE_URL'] ?? 'http://127.0.0.1:5001');
$aiUrl = rtrim($aiBaseUrl, '/') . '/api/verify_and_register_face';
$payloadJson = json_encode($payload);

$response = false;
$httpCode = 0;
$err = '';

for ($attempt = 1; $attempt <= 2; $attempt++) {
    $ch = curl_init($aiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadJson);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 35);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($response !== false) {
        break;
    }

    // If connection failed, attempt to auto-launch Python AI service in background
    if ($attempt === 1) {
        $pyScript = realpath(__DIR__ . '/../ai_service/app.py');
        $pyExe = 'C:\\Users\\Acer\\AppData\\Local\\Programs\\Python\\Python312\\python.exe';
        if (file_exists($pyExe) && file_exists($pyScript)) {
            @pclose(@popen("start /B \"\" \"{$pyExe}\" \"{$pyScript}\"", "r"));
            sleep(3);
        }
    }
}

if ($response === false) {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'message' => 'Biometric Engine error: ' . ($err ?: 'Unable to reach AI service on port 5001. Please try again in 5 seconds while model finishes loading.')
    ]);
    exit;
}

$resData = json_decode($response, true);
if ($resData && !empty($resData['success']) && !empty($resData['photo_filename'])) {
    $photoFilename = $resData['photo_filename'];

    // Update MySQL signup table profilePhoto directly
    if (isset($con) && $con) {
        $upStmt = $con->prepare("UPDATE signup SET profilePhoto = ? WHERE userId = ?");
        if ($upStmt) {
            $upStmt->bind_param("ss", $photoFilename, $userId);
            $upStmt->execute();
            $upStmt->close();
        }
    }

    // Update current session photo if it matches
    if (!empty($_SESSION['userId']) && strval($_SESSION['userId']) === strval($userId)) {
        $_SESSION['profilePhoto'] = $photoFilename;
    }
}

http_response_code($httpCode ?: 200);
echo $response;
