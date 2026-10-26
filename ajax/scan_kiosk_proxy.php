<?php
/**
 * Live Face Attendance Kiosk Proxy Endpoint
 * Allows identifying any registered employee in front of the kiosk camera.
 */
session_start();
header('Content-Type: application/json');

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

$payload = [
    'image' => $data['image'],
    'target_user_id' => null // Kiosk mode: searches all registered employees
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

http_response_code($httpCode ?: 200);
echo $response;
