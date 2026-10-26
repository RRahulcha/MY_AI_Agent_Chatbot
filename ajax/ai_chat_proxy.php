<?php
/**
 * SETTribe AMS - AI Chatbot Server-Side Auth Proxy
 * 
 * Secure gateway between authenticated browser sessions and the internal AI FastAPI microservice.
 * Enforces:
 * 1. Master Feature Flag (AI_CHATBOT_ENABLED)
 * 2. Active PHP Session & Cookie Authentication
 * 3. Role-Based Allowlist Checking
 * 4. Cryptographic HMAC Token Minting (Zero Trust on client-supplied headers)
 * 5. Circuit Breaker / Soft Degradation on microservice outage
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

// Load environment configuration
require_once __DIR__ . '/../include/db_config.php';

// ── 1. Check Master Feature Flag ─────────────────────────────────────────────
$aiEnabledRaw = getenv('AI_CHATBOT_ENABLED') ?: ($_ENV['AI_CHATBOT_ENABLED'] ?? false);
$aiEnabled = filter_var($aiEnabledRaw, FILTER_VALIDATE_BOOLEAN);

if (!$aiEnabled) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'AI Assistant feature is currently disabled by administrator.'
    ]);
    exit;
}

// ── 2. Authenticate User Session ─────────────────────────────────────────────
$userId = $_SESSION['userId'] ?? $_COOKIE['userId'] ?? null;
$userRole = $_SESSION['userRole'] ?? $_COOKIE['userRole'] ?? null;
$designation = $_SESSION['designation'] ?? $_COOKIE['designation'] ?? '';

if (empty($userId)) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized: Active user session required.'
    ]);
    exit;
}

// Fetch userRole from database if missing from session
if (empty($userRole) && isset($con) && $con) {
    $stmtRole = $con->prepare("SELECT userRole, designation FROM signup WHERE userId = ? LIMIT 1");
    if ($stmtRole) {
        $stmtRole->bind_param("s", $userId);
        $stmtRole->execute();
        $resRole = $stmtRole->get_result();
        if ($rowRole = $resRole->fetch_assoc()) {
            $userRole = $rowRole['userRole'] ?? '';
            if (empty($designation)) {
                $designation = $rowRole['designation'] ?? '';
            }
            $_SESSION['userRole'] = $userRole;
            $_SESSION['designation'] = $designation;
        }
        $stmtRole->close();
    }
}

// ── 3. Role Allowlist Enforcement ───────────────────────────────────────────
$allowedRolesStr = getenv('AI_CHATBOT_ALLOWED_ROLES') ?: ($_ENV['AI_CHATBOT_ALLOWED_ROLES'] ?? '*');
$allowedRoles = array_map('trim', explode(',', strtolower($allowedRolesStr)));

if (!empty($allowedRoles) && !in_array('*', $allowedRoles)) {
    $normalizedRole = strtolower(trim($userRole ?? ''));
    $roleAllowed = false;
    foreach ($allowedRoles as $allowed) {
        if ($normalizedRole === $allowed || strpos($normalizedRole, $allowed) !== false) {
            $roleAllowed = true;
            break;
        }
    }
    if (!$roleAllowed) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Your role is not authorized to use the AI Assistant.'
        ]);
        exit;
    }
}

// ── 4. Parse & Validate Payload ──────────────────────────────────────────────
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid JSON payload.'
    ]);
    exit;
}

$action = trim($data['action'] ?? ($_GET['action'] ?? 'chat'));

// ── 4A. Action: Get User Chat & Search History ────────────────────────────────
if ($action === 'get_history') {
    try {
        $stmt = $con->prepare("
            SELECT m.id, m.sender, m.message, m.created_at
            FROM ai_messages m
            INNER JOIN ai_conversations c ON m.conversation_id = c.id
            WHERE c.user_id = ?
            ORDER BY m.id DESC
            LIMIT 60
        ");
        $stmt->bind_param("s", $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $rawMessages = [];
        while ($row = $res->fetch_assoc()) {
            $rawMessages[] = $row;
        }
        $chronological = array_reverse($rawMessages);

        // Group into Query-Reply cards for history display
        $historyItems = [];
        $currentUserQuery = null;
        $currentQueryTime = null;

        foreach ($chronological as $msg) {
            if ($msg['sender'] === 'user') {
                if ($currentUserQuery !== null) {
                    $historyItems[] = [
                        'query' => $currentUserQuery,
                        'reply' => '',
                        'time' => $currentQueryTime
                    ];
                }
                $currentUserQuery = $msg['message'];
                $currentQueryTime = date('d M, h:i A', strtotime($msg['created_at']));
            } else if ($msg['sender'] === 'assistant') {
                $historyItems[] = [
                    'query' => $currentUserQuery ?: 'Search / Request',
                    'reply' => $msg['message'],
                    'time' => $currentQueryTime ?: date('d M, h:i A', strtotime($msg['created_at']))
                ];
                $currentUserQuery = null;
                $currentQueryTime = null;
            }
        }
        if ($currentUserQuery !== null) {
            $historyItems[] = [
                'query' => $currentUserQuery,
                'reply' => '',
                'time' => $currentQueryTime
            ];
        }

        echo json_encode([
            'success' => true,
            'history' => array_reverse($historyItems),
            'raw_messages' => $chronological
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── 4B. Action: Clear User Chat History ──────────────────────────────────────
if ($action === 'clear_history') {
    try {
        $stmt = $con->prepare("
            DELETE m FROM ai_messages m
            INNER JOIN ai_conversations c ON m.conversation_id = c.id
            WHERE c.user_id = ?
        ");
        $stmt->bind_param("s", $userId);
        $stmt->execute();
        echo json_encode(['success' => true, 'message' => 'Chat history cleared successfully.']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

$message = trim($data['message'] ?? '');
$conversationId = trim($data['conversation_id'] ?? '');
$confirmationId = trim($data['confirmation_id'] ?? '');

if (empty($message) && empty($confirmationId)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Message or confirmation ID is required.'
    ]);
    exit;
}

// Prevent oversized requests (16KB max)
if (strlen($message) > 16384) {
    http_response_code(413);
    echo json_encode([
        'success' => false,
        'error' => 'Message exceeds maximum allowable length (16KB).'
    ]);
    exit;
}

// ── 5. Mint Short-Lived HMAC-SHA256 Token (60-second TTL) ────────────────────
$secret = getenv('AI_INTERNAL_SECRET') ?: ($_ENV['AI_INTERNAL_SECRET'] ?? '');

if (empty($secret) || strlen($secret) < 32) {
    error_log("[AMS AI Proxy] Missing or insecure AI_INTERNAL_SECRET.");
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal authentication security misconfiguration.'
    ]);
    exit;
}

$headerJson = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
$payloadJson = json_encode([
    'sub' => (string)$userId,
    'role' => (string)($userRole ?? 'unknown'),
    'designation' => (string)$designation,
    'iat' => time(),
    'exp' => time() + 60,
    'jti' => bin2hex(random_bytes(16))
]);

$b64Header = rtrim(strtr(base64_encode($headerJson), '+/', '-_'), '=');
$b64Payload = rtrim(strtr(base64_encode($payloadJson), '+/', '-_'), '=');
$signature = hash_hmac('sha256', "$b64Header.$b64Payload", $secret, true);
$b64Signature = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

$internalToken = "$b64Header.$b64Payload.$b64Signature";

// ── 6. Proxy to Internal AI Service with Circuit Breaker ─────────────────────
$serviceUrl = rtrim(getenv('AI_CHAT_SERVICE_URL') ?: ($_ENV['AI_CHAT_SERVICE_URL'] ?? 'http://127.0.0.1:8000'), '/');
$targetEndpoint = "$serviceUrl/api/chat";

$postData = json_encode([
    'message' => $message,
    'conversation_id' => $conversationId,
    'confirmation_id' => $confirmationId
]);

$ch = curl_init($targetEndpoint);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $postData,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        "Authorization: Bearer $internalToken"
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 2,
    CURLOPT_TIMEOUT => 15
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// Soft failover if Python microservice is offline
if ($response === false || $httpCode >= 500 || $httpCode === 0) {
    if (!empty($curlError)) {
        error_log("[AMS AI Proxy] cURL error connecting to $targetEndpoint: $curlError");
    }
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'error' => 'AMS AI Assistant is temporarily unreachable. Please ensure the AI microservice is running.'
    ]);
    exit;
}

// Return the microservice response directly
http_response_code($httpCode);
$decoded = json_decode($response, true);
if (is_array($decoded)) {
    if (!isset($decoded['reply']) && isset($decoded['response'])) {
        $decoded['reply'] = $decoded['response'];
    }
    if (!isset($decoded['response']) && isset($decoded['reply'])) {
        $decoded['response'] = $decoded['reply'];
    }

    // Persist conversation and messages to database
    if ($httpCode === 200 && !empty($decoded['success']) && !empty($message)) {
        try {
            $convId = 'conv_' . md5($userId . '_' . date('Y-m'));
            $convTitle = mb_substr($message, 0, 60);

            $chk = $con->prepare("SELECT id FROM ai_conversations WHERE id = ?");
            $chk->bind_param("s", $convId);
            $chk->execute();
            if ($chk->get_result()->num_rows === 0) {
                $insC = $con->prepare("INSERT INTO ai_conversations (id, user_id, title, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())");
                $insC->bind_param("sss", $convId, $userId, $convTitle);
                $insC->execute();
            } else {
                $updC = $con->prepare("UPDATE ai_conversations SET updated_at = NOW() WHERE id = ?");
                $updC->bind_param("s", $convId);
                $updC->execute();
            }

            // Insert user query
            $insU = $con->prepare("INSERT INTO ai_messages (conversation_id, sender, message, created_at) VALUES (?, 'user', ?, NOW())");
            $insU->bind_param("ss", $convId, $message);
            $insU->execute();

            // Insert bot reply
            $botReply = $decoded['response'] ?? '';
            if (!empty($botReply)) {
                $insA = $con->prepare("INSERT INTO ai_messages (conversation_id, sender, message, created_at) VALUES (?, 'assistant', ?, NOW())");
                $insA->bind_param("ss", $convId, $botReply);
                $insA->execute();
            }
        } catch (Exception $e) {
            error_log("[AMS AI Proxy] History persistence error: " . $e->getMessage());
        }
    }

    echo json_encode($decoded);
} else {
    echo $response;
}
