<?php
/**
 * Face Attendance Secure Proxy Endpoint
 * Relays webcam base64 frames from client browser to local AI Service (127.0.0.1:5001)
 * Enforces mandatory GPS Geolocation for attendance recording.
 */
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../include/db_config.php';

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (empty($data) || empty($data['image'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'detected' => false,
        'recognized' => false,
        'message' => 'No camera frame received'
    ]);
    exit;
}

// ── Location: Mandatory Check ──
$latitude  = isset($data['latitude']) ? floatval($data['latitude']) : 0.0;
$longitude = isset($data['longitude']) ? floatval($data['longitude']) : 0.0;

// Determine target: If logged in AND not explicitly in kiosk mode, bind to session userId
$targetUserId = null;
$mode = $data['mode'] ?? '';

if (!empty($_SESSION['userId']) && $mode !== 'kiosk') {
    $targetUserId = strval($_SESSION['userId']);
} elseif (!empty($data['target_user_id']) && !empty($_SESSION['userId'])) {
    $targetUserId = strval($_SESSION['userId']);
} elseif (!empty($data['target_user_id'])) {
    $targetUserId = strval($data['target_user_id']);
} else {
    // Kiosk mode: search all registered employees
    $targetUserId = null;
}

$payload = [
    'image' => $data['image'],
    'target_user_id' => $targetUserId,
    'latitude' => $latitude,
    'longitude' => $longitude
];

$aiBaseUrl = getenv('AI_SERVICE_URL') ?: ($_ENV['AI_SERVICE_URL'] ?? 'http://127.0.0.1:5001');
$aiUrl = rtrim($aiBaseUrl, '/') . '/api/scan_face';
$ch = curl_init($aiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($response === false) {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'detected' => false,
        'recognized' => false,
        'message' => 'Face Recognition Service is offline or starting up on server (Port 5001).'
    ]);
    exit;
}

$resData = json_decode($response, true);
if ($resData && !empty($resData['recognized']) && !empty($resData['user_id']) && isset($con) && $con) {
    $recUid = strval($resData['user_id']);
    $action = $resData['action'] ?? '';
    $latStr = strval($latitude ?: '0.0');
    $lngStr = strval($longitude ?: '0.0');

    if ($action === 'punch_in') {
        $upAtt = $con->prepare("
            UPDATE attendance 
            SET punchInLatitude = ?, punchInLongitude = ? 
            WHERE userId = ? AND date = CURDATE() 
            ORDER BY id DESC LIMIT 1
        ");
        if ($upAtt) {
            $upAtt->bind_param("sss", $latStr, $lngStr, $recUid);
            $upAtt->execute();
            $upAtt->close();
        }
    } elseif ($action === 'punch_out') {
        $upAtt = $con->prepare("
            UPDATE attendance 
            SET punchOutLatitude = ?, punchOutLongitude = ? 
            WHERE userId = ? AND date = CURDATE() 
            ORDER BY id DESC LIMIT 1
        ");
        if ($upAtt) {
            $upAtt->bind_param("sss", $latStr, $lngStr, $recUid);
            $upAtt->execute();
            $upAtt->close();
        }
    }
}

http_response_code($httpCode ?: 200);
echo $response;
