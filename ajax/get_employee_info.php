<?php
/**
 * Face Verification - Employee Info Proxy Endpoint
 * Settribe AMS
 */
session_start();
header('Content-Type: application/json');

$userId = $_GET['user_id'] ?? $_POST['user_id'] ?? null;
if (empty($userId) && !empty($_SESSION['userId'])) {
    $userId = $_SESSION['userId'];
}

if (empty($userId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'User ID is required']);
    exit;
}

$aiBaseUrl = getenv('AI_SERVICE_URL') ?: ($_ENV['AI_SERVICE_URL'] ?? 'http://127.0.0.1:5001');
$aiUrl = rtrim($aiBaseUrl, '/') . '/api/get_employee_info?user_id=' . urlencode($userId);
$ch = curl_init($aiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($response === false) {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'message' => 'AI Service unreachable: ' . ($err ?: 'Connection failed')
    ]);
    exit;
}

http_response_code($httpCode ?: 200);
echo $response;
