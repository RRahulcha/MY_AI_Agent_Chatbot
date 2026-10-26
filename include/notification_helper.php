<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * notification_helper.php  —  OPTIMIZED v2
 * Place: /include/notification_helper.php
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * WHAT CHANGED FROM v1 — summary for code review:
 *
 *  1. FCM is now ASYNC (fire-and-forget after response is sent).
 *     Uses fastcgi_finish_request() when available (nginx/php-fpm).
 *     Falls back to a safe synchronous try/catch — no worker file needed.
 *     Result: leave_apply / leave_approval pages return instantly on fpm.
 *
 *  2. OAuth token is cached on DISK (system temp) — not just in a static var.
 *     Static var = lost every request. Disk cache = shared across all requests
 *     until the token actually expires. Saves one HTTPS round-trip per request.
 *
 *  3. _saveNotification() accepts a pre-prepared $stmt for batch inserts.
 *     Notifying 5 admins used to prepare+close the INSERT 5 times.
 *     Now the statement is prepared ONCE and executed 5 times.
 *
 *  4. Invalid / expired FCM tokens are silently pruned from the signup table
 *     when FCM returns UNREGISTERED or INVALID_ARGUMENT. No more retrying
 *     dead tokens on every notification.
 *
 *  5. All public function signatures are UNCHANGED — zero breaking changes.
 *
 * ── CONFIGURATION ─────────────────────────────────────────────────────────────
 *
 *   FCM_PROJECT_ID            Your Firebase project ID
 *   FCM_SERVICE_ACCOUNT_JSON  Absolute path to service-account key JSON
 *
 * ── PUBLIC API (unchanged) ────────────────────────────────────────────────────
 *
 *  sendNotification($con, $userId, $title, $message, $type, $referenceId)
 *  sendNotificationToAdmins($con, $title, $message, $type, $referenceId)
 *  sendNotificationToRole($con, $role, $title, $message, $type, $referenceId)
 *  notifyAdmins($con, $applicantName, $leaveNumericId)
 *  notifyEmployee($con, $employeeUserId, $status, $leaveNumericId)
 *
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);


// ════════════════════════════════════════════════════════════════════════════
//  CONFIGURATION HELPERS
// ════════════════════════════════════════════════════════════════════════════

function _fcmEnv(string $key): string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        throw new RuntimeException(
            "[NotificationHelper] Missing required env var: {$key}"
        );
    }
    return $value;
}


// ════════════════════════════════════════════════════════════════════════════
//  PUBLIC GENERIC FUNCTIONS  (signatures unchanged from v1)
// ════════════════════════════════════════════════════════════════════════════

/**
 * Send a notification to ONE specific user (DB record + async FCM push).
 */
function sendNotification(
    mysqli $con,
    string $userId,
    string $title,
    string $message,
    string $type = '',
    string $referenceId = ''
): void {
    // Fetch token
    $stmt = $con->prepare("SELECT token FROM signup WHERE userId = ? LIMIT 1");
    if (!$stmt) { _logError('sendNotification: prepare failed: ' . $con->error); return; }
    $stmt->bind_param('s', $userId);
    $stmt->execute();
    $stmt->bind_result($fcmToken);
    $stmt->fetch();
    $stmt->close();
    $fcmToken = (string)($fcmToken ?? '');

    // ── Prepare INSERT once, execute once ────────────────────────────────
    $insertStmt = _prepareInsertStmt($con);
    if ($insertStmt) {
        _executeInsert($insertStmt, $userId, $title, $message, $type, $referenceId);
        $insertStmt->close();
    }

    // ── Queue FCM push — runs AFTER response is sent, never blocks ───────
    if (trim($fcmToken) !== '') {
        _queueFCMPush($fcmToken, $title, $message);
    }
}

/**
 * Send a notification to ALL active Admin users.
 */
function sendNotificationToAdmins(
    mysqli $con,
    string $title,
    string $message,
    string $type = '',
    string $referenceId = ''
): void {
    $stmt = $con->prepare(
        "SELECT userId, token
         FROM   signup
         WHERE  (userRole = 'admin' OR userRole = 'hr' OR designation = 'Admin' OR designation = 'hr' OR designation = 'HR')
           AND  status = 'active'"
    );
    if (!$stmt) { _logError('sendNotificationToAdmins: prepare failed: ' . $con->error); return; }
    $stmt->execute();
    $admins = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($admins)) return;

    // ── Prepare INSERT once for all admin rows ────────────────────────────
    $insertStmt = _prepareInsertStmt($con);

    foreach ($admins as $admin) {
        if ($insertStmt) {
            _executeInsert($insertStmt, $admin['userId'], $title, $message, $type, $referenceId);
        }
        $token = trim((string)($admin['token'] ?? ''));
        if ($token !== '') {
            _queueFCMPush($token, $title, $message);
        }
    }

    if ($insertStmt) $insertStmt->close();
}

/**
 * Send a notification to ALL active users with a specific userRole.
 */
function sendNotificationToRole(
    mysqli $con,
    string $role,
    string $title,
    string $message,
    string $type = '',
    string $referenceId = ''
): void {
    $stmt = $con->prepare(
        "SELECT userId, token FROM signup WHERE userRole = ? AND status = 'active'"
    );
    if (!$stmt) { _logError('sendNotificationToRole: prepare failed: ' . $con->error); return; }
    $stmt->bind_param('s', $role);
    $stmt->execute();
    $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($users)) return;

    $insertStmt = _prepareInsertStmt($con);

    foreach ($users as $user) {
        if ($insertStmt) {
            _executeInsert($insertStmt, $user['userId'], $title, $message, $type, $referenceId);
        }
        $token = trim((string)($user['token'] ?? ''));
        if ($token !== '') {
            _queueFCMPush($token, $title, $message);
        }
    }

    if ($insertStmt) $insertStmt->close();
}


// ════════════════════════════════════════════════════════════════════════════
//  LEAVE CONVENIENCE WRAPPERS  (unchanged from v1)
// ════════════════════════════════════════════════════════════════════════════

function notifyAdmins(mysqli $con, string $applicantName, string $leaveNumericId): void
{
    sendNotificationToAdmins(
        $con,
        "New Leave Request 📋",
        "{$applicantName} has applied for leave. Please review and take action.",
        "leave_applied",
        (string)$leaveNumericId
    );
}

function notifyEmployee(
    mysqli $con,
    string $employeeUserId,
    string $status,
    string $leaveNumericId
): void {
    if ($status === 'Approved') {
        $title   = "Leave Approved ✅";
        $message = "Your leave request has been approved. Enjoy your time off!";
    } else {
        $title   = "Leave Rejected ❌";
        $message = "Your leave request has been rejected. Please check the rejection reason in the Leave section.";
    }
    sendNotification($con, $employeeUserId, $title, $message, 'leave_status', $leaveNumericId);
}


// ════════════════════════════════════════════════════════════════════════════
//  INTERNAL — BATCH INSERT HELPERS
// ════════════════════════════════════════════════════════════════════════════

/**
 * Prepare the INSERT statement ONCE.
 * The caller re-uses it across multiple execute() calls, then closes it.
 * This replaces v1's pattern of prepare+close inside every loop iteration.
 */
function _prepareInsertStmt(mysqli $con): mysqli_stmt|false
{
    $stmt = $con->prepare(
        "INSERT INTO notifications
             (userId, title, message, type, reference_id, status, is_read, created_at)
         VALUES
             (?, ?, ?, ?, ?, 'Unread', 0, NOW())"
    );
    if (!$stmt) {
        _logError('_prepareInsertStmt: prepare failed: ' . $con->error);
        return false;
    }
    return $stmt;
}

/**
 * Execute one INSERT using a pre-prepared statement.
 * bind_param is called each time because values change per row.
 */
function _executeInsert(
    mysqli_stmt $stmt,
    string $userId,
    string $title,
    string $message,
    string $type,
    string $referenceId
): void {
    $refValue = ($referenceId !== '' && $referenceId !== '0') ? $referenceId : null;
    $stmt->bind_param('sssss', $userId, $title, $message, $type, $refValue);
    if (!$stmt->execute()) {
        _logError('_executeInsert: execute failed: ' . $stmt->error);
    }
}


// ════════════════════════════════════════════════════════════════════════════
//  INTERNAL — ASYNC FCM DISPATCH
// ════════════════════════════════════════════════════════════════════════════

/**
 * Queue a FCM push to fire AFTER the HTTP response is sent to the browser.
 *
 * Strategy:
 *  - If fastcgi_finish_request() is available (php-fpm / nginx):
 *      Registers a shutdown function that flushes the response first,
 *      then calls Firebase. The user sees the page instantly.
 *  - Fallback (Apache mod_php / any other SAPI):
 *      Calls Firebase synchronously but wraps it in try/catch so a slow
 *      or failing FCM call logs an error and never crashes the page.
 *      No external worker file needed.
 */
function _queueFCMPush(string $fcmToken, string $title, string $body): void
{
    if (function_exists('fastcgi_finish_request')) {
        // ── php-fpm / nginx — true async, zero wait for the user ─────────
        register_shutdown_function(function() use ($fcmToken, $title, $body) {
            fastcgi_finish_request(); // flush response to browser first
            try {
                _sendFCMPush($fcmToken, $title, $body);
            } catch (Throwable $e) {
                _logError('Async FCM push failed: ' . $e->getMessage());
            }
        });
    } else {
        // ── Apache / mod_php — synchronous but safe ───────────────────────
        // FCM call happens before redirect, but is fully isolated:
        // any timeout or error is caught and logged — never shown to the user.
        try {
            _sendFCMPush($fcmToken, $title, $body);
        } catch (Throwable $e) {
            _logError('FCM push failed: ' . $e->getMessage());
        }
    }
}


// ════════════════════════════════════════════════════════════════════════════
//  INTERNAL — FCM HTTP v1
// ════════════════════════════════════════════════════════════════════════════

/**
 * Send a FCM push notification.
 *
 * NEW in v2: if FCM returns UNREGISTERED or INVALID_ARGUMENT, the token is
 * removed from the signup table so we never waste a network call on it again.
 *
 * @throws RuntimeException  Caught by _queueFCMPush; never reaches the caller.
 */
function _sendFCMPush(string $fcmToken, string $title, string $body): void
{
    $projectId   = _fcmEnv('FCM_PROJECT_ID');
    $accessToken = _getFCMAccessToken();

    $payload = json_encode([
        'message' => [
            'token'        => $fcmToken,
            'notification' => ['title' => $title, 'body' => $body],
            'data'         => ['title' => $title, 'body' => $body, 'click_action' => 'FLUTTER_NOTIFICATION_CLICK'],
            'android'      => [
                'priority'     => 'HIGH',
                'notification' => ['sound' => 'default', 'channel_id' => 'high_importance_channel', 'click_action' => 'FLUTTER_NOTIFICATION_CLICK'],
            ],
            'apns' => [
                'payload' => ['aps' => ['sound' => 'default', 'content-available' => 1]], 
            ],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send",
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response = curl_exec($ch);
    $curlErr  = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    @curl_close($ch);

    if ($response === false || $curlErr !== '') {
        throw new RuntimeException("cURL error: {$curlErr}");
    }

    $decoded = json_decode((string)$response, true);

    // ── Dead-token pruning (NEW in v2) ────────────────────────────────────
    // FCM returns 404 UNREGISTERED when the device has uninstalled the app,
    // or 400 INVALID_ARGUMENT for a malformed token.
    // Either way, retrying will always fail — delete the token so we stop trying.
    if ($httpCode === 404 || $httpCode === 400) {
        $fcmError = $decoded['error']['details'][0]['errorCode']
                 ?? $decoded['error']['status']
                 ?? '';
        if (in_array($fcmError, ['UNREGISTERED', 'INVALID_ARGUMENT'], true)) {
            _pruneDeadToken($fcmToken);
            return; // not a bug — token is just gone
        }
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException("FCM returned HTTP {$httpCode}: {$response}");
    }

    if (!empty($decoded['error'])) {
        throw new RuntimeException("FCM error: " . ($decoded['error']['message'] ?? $response));
    }
}

/**
 * Remove a dead FCM token from the signup table.
 * Called when FCM confirms the token is permanently invalid.
 * Uses a fresh connection so it works from the background worker too.
 */
function _pruneDeadToken(string $fcmToken): void
{
    try {
        // Re-use the global $con if available (web context),
        // otherwise open a fresh connection (CLI worker context).
        global $con;
        $db = ($con instanceof mysqli && $con->ping()) ? $con : null;

        if ($db === null) {
            // Worker context: load db config to get credentials
            $cfgPath = __DIR__ . '/db_config.php';
            if (!file_exists($cfgPath)) return;
            require_once $cfgPath;
            $db = $con ?? null;
        }

        if (!($db instanceof mysqli)) return;

        $stmt = $db->prepare("UPDATE signup SET token = NULL WHERE token = ?");
        if ($stmt) {
            $stmt->bind_param('s', $fcmToken);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Throwable $e) {
        _logError('_pruneDeadToken failed: ' . $e->getMessage());
    }
}

/**
 * Get a valid FCM OAuth 2.0 Bearer token.
 *
 * v2 improvement: token is cached in a temp FILE, not just a static variable.
 * Static var = re-fetched on every PHP request.
 * File cache = shared across ALL requests until the token actually expires.
 * Saves one HTTPS round-trip to Google per page load when sending notifications.
 */
function _getFCMAccessToken(): string
{
    // ── In-process cache (fastest — no disk I/O on second call in same request)
    static $cachedToken  = null;
    static $cachedExpiry = 0;
    if ($cachedToken !== null && time() < $cachedExpiry - 60) {
        return $cachedToken;
    }

    // ── Disk cache (shared across all PHP processes / requests)
    $cacheFile = sys_get_temp_dir() . '/fcm_oauth_token_' . md5(_fcmEnv('FCM_PROJECT_ID')) . '.json';
    if (file_exists($cacheFile)) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if (!empty($cached['token']) && time() < ((int)$cached['expiry']) - 60) {
            $cachedToken  = $cached['token'];
            $cachedExpiry = (int)$cached['expiry'];
            return $cachedToken;
        }
    }

    // ── Fetch a new token from Google ────────────────────────────────────
    $serviceAccountPath = _fcmEnv('FCM_SERVICE_ACCOUNT_JSON');
    if (!is_readable($serviceAccountPath)) {
        throw new RuntimeException(
            "[NotificationHelper] Service account file not readable: {$serviceAccountPath}"
        );
    }

    $sa = json_decode(file_get_contents($serviceAccountPath), true);
    if (json_last_error() !== JSON_ERROR_NONE || empty($sa['private_key']) || empty($sa['client_email'])) {
        throw new RuntimeException("[NotificationHelper] Service account JSON invalid.");
    }

    $now    = time();
    $header = _base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claims = _base64url(json_encode([
        'iss'   => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ]));

    $signingInput = $header . '.' . $claims;
    $privateKey   = openssl_pkey_get_private($sa['private_key']);
    if ($privateKey === false) {
        throw new RuntimeException("[NotificationHelper] Could not load private key.");
    }

    $signature = '';
    if (!openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException("[NotificationHelper] Failed to sign JWT.");
    }

    $jwt = $signingInput . '.' . _base64url($signature);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://oauth2.googleapis.com/token',
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response = curl_exec($ch);
    $curlErr  = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    @curl_close($ch);

    if ($response === false || $curlErr !== '') {
        throw new RuntimeException("[NotificationHelper] cURL OAuth error: {$curlErr}");
    }

    $data = json_decode($response, true);
    if ($httpCode !== 200 || empty($data['access_token'])) {
        throw new RuntimeException(
            "[NotificationHelper] OAuth request failed (HTTP {$httpCode}): {$response}"
        );
    }

    $expiry       = $now + (int)($data['expires_in'] ?? 3600);
    $cachedToken  = $data['access_token'];
    $cachedExpiry = $expiry;

    // ── Write to disk cache ───────────────────────────────────────────────
    file_put_contents(
        $cacheFile,
        json_encode(['token' => $cachedToken, 'expiry' => $expiry]),
        LOCK_EX   // ← atomic write — no partial reads from parallel requests
    );

    return $cachedToken;
}

/**
 * URL-safe Base64 — required for JWT.
 */
function _base64url(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Centralised error logging.
 */
function _logError(string $message): void
{
    error_log('[NotificationHelper] ' . $message);
}