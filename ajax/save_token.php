<?php
session_start();
header("Content-Type: application/json");

// 1️⃣ Check login
if (!isset($_SESSION['userId'])) {
    http_response_code(401);
    echo json_encode(["error" => "User not logged in"]);
    exit;
}

// 2️⃣ Read JSON body
$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['token'])) {
    http_response_code(400);
    echo json_encode(["error" => "Token missing"]);
    exit;
}

$token  = $data['token'];
$userId = $_SESSION['userId'];

// 3️⃣ DB connection (use your existing config if available)
include('../include/db_config.php'); // preferred

// 4️⃣ Save token
$stmt = $con->prepare("UPDATE signup SET fcm_token = ? WHERE userId = ?");
$stmt->bind_param("ss", $token, $userId);
$stmt->execute();

$stmt->close();

echo json_encode(["success" => true]);
