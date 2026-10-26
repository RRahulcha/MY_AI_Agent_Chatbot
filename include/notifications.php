<?php
// 🔔 Firebase Push Notification (Added for AMS login)

if (!function_exists('sendFCMNotification')) {

    function sendFCMNotification($fcmToken, $title, $body, $accessToken, $projectId)
    {
        if (empty($fcmToken)) {
            return false;
        }

        $url = "https://fcm.googleapis.com/v1/projects/$projectId/messages:send";

        $payload = [
            "message" => [
                "token" => $fcmToken,
                "notification" => [
                    "title" => $title,
                    "body" => $body
                ]
            ]
        ];

        $headers = [
            "Authorization: Bearer $accessToken",
            "Content-Type: application/json"
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

        $response = curl_exec($ch);

        if ($response === false) {
            error_log("FCM Error: " . curl_error($ch));
        }

        @curl_close($ch);
        return $response;
    }
}






// Function to generate JWT using Firebase service account
function generateJWT($serviceAccountKey)
{
    $header = base64_encode(json_encode([
        'alg' => 'RS256',
        'typ' => 'JWT'
    ]));

    $iat = time();
    $exp = $iat + 3600; // Token valid for 1 hour

    $payload = base64_encode(json_encode([
        'iss' => $serviceAccountKey['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $iat,
        'exp' => $exp
    ]));

    $base = $header . '.' . $payload;

    // Sign the JWT with the private key
    $signature = '';
    openssl_sign($base, $signature, $serviceAccountKey['private_key'], 'sha256WithRSAEncryption');

    $jwt = $base . '.' . base64_encode($signature);
    return $jwt;
}

// Function to get OAuth 2.0 access token using JWT
function getAccessToken($jwt)
{
    $url = 'https://oauth2.googleapis.com/token';

    $fields = [
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $result = curl_exec($ch);
    if ($result === false) {
        die('Curl failed: ' . curl_error($ch));
    }

    @curl_close($ch);
    $response = json_decode($result, true);
    return $response['access_token'];
}

// Function to send FCM notification
function sendFCMNotification($fcmToken, $title, $body, $accessToken, $projectId)
{
    $url = 'https://fcm.googleapis.com/v1/projects/' . $projectId . '/messages:send';

    $message = [
        'message' => [
            'token' => $fcmToken,
            'notification' => [
                'title' => $title,
                'body' => $body
            ]
        ]
    ];

    $headers = [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));

    $result = curl_exec($ch);

    if ($result === FALSE) {
        die('FCM Send Error: ' . curl_error($ch));
    }

    @curl_close($ch);
    return $result;
}

// Load the service account key and export getFirebaseAccessToken()
$projectId = 'settribe-s-ams';

if (!function_exists('getFirebaseAccessToken')) {
    function getFirebaseAccessToken() {
        $serviceAccountPath = __DIR__ . '/settribe-s-ams.json';
        if (!file_exists($serviceAccountPath)) {
            return null;
        }
        $serviceAccountKey = json_decode(file_get_contents($serviceAccountPath), true);
        if (!$serviceAccountKey) {
            return null;
        }
        $jwt = generateJWT($serviceAccountKey);
        return getAccessToken($jwt);
    }
}
?>