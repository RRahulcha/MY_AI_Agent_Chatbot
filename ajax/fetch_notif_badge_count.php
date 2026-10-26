<?php
// ── 1. Session: read-only, no write lock ────────────────────
//    session_start() acquires a file lock that blocks other
//    requests from the same user until the script finishes.
//    session_write_close() releases the lock immediately after
//    we read the userId — so the rest of the page can load in
//    parallel without waiting for this poll to finish.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$userId = $_SESSION['userId'] ?? '';
session_write_close();          // ✅ release lock ASAP

// ── 2. Suppress errors from leaking into JSON output ────────
error_reporting(0);
ini_set('display_errors', 0);

// ── 3. Headers ───────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');

// No userId → return 0 immediately, no DB hit at all
if ($userId === '') {
    http_response_code(200);
    echo '{"unread_count":0}';
    exit;
}

// ── 4. DB ────────────────────────────────────────────────────
require_once('../include/db_config.php');

// ── 5. Fetch count ───────────────────────────────────────────
//    COUNT(*) on an indexed (userId, is_read) column is the
//    cheapest possible query — single integer, no row data.
$unread = 0;
$stmt = $con->prepare(
    "SELECT COUNT(*) FROM notifications WHERE userId = ? AND is_read = 0"
);
if ($stmt) {
    $stmt->bind_param('s', $userId);
    $stmt->execute();
    $stmt->bind_result($unread);
    $stmt->fetch();
    $stmt->close();
}
$unread = (int) $unread;

// ── 6. ETag — skip response body if count hasn't changed ────
//    The browser sends back the ETag on the next poll.
//    If the count is the same, we reply 304 Not Modified and
//    send zero bytes — saves parsing + re-rendering entirely.
$etag = '"notif-' . $userId . '-' . $unread . '"';
header('ETag: ' . $etag);
header('Cache-Control: private, no-cache');   // always revalidate

if (isset($_SERVER['HTTP_IF_NONE_MATCH']) &&
    $_SERVER['HTTP_IF_NONE_MATCH'] === $etag) {
    http_response_code(304);   // ✅ Not Modified — zero bytes sent
    exit;
}

// ── 7. Output ────────────────────────────────────────────────
http_response_code(200);
echo json_encode(['unread_count' => $unread], JSON_THROW_ON_ERROR);