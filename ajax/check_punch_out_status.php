<?php

if (session_status() === PHP_SESSION_NONE) session_start();
require_once('../include/db_config.php');
require_once('../include/ams_cache.php');

header('Content-Type: application/json');
error_reporting(0);
ini_set('display_errors', 0);

if (empty($_SESSION['userId'])) {
    echo json_encode(['unlocked' => false, 'reason' => 'not_logged_in']);
    if (isset($con)) { $con->close(); } exit;
}

$userId   = $_SESSION['userId'];
$cacheKey = "punch_out_status:{$userId}:" . date('Y-m-d');

$cached = ams_cache_get($cacheKey);
if ($cached !== false) {
    // Recalculate secondsLeft in real-time (it changes every second)
    // but avoid hitting DB again for the static fields
    $cached['secondsLeft'] = max(0, $cached['unlockTs'] - time());
    $cached['unlocked']    = (time() >= $cached['unlockTs']);
    echo json_encode($cached);
    if (isset($con)) { $con->close(); } exit;
}

// ── DB fetch (runs at most once per 30 s per user) ───────────────────────────
$userId = mysqli_real_escape_string($con, $userId);
$today  = date('Y-m-d');
$nowTs  = time();

$poStmt = $con->prepare("SELECT expectedPunchOut FROM signup WHERE userId = ? LIMIT 1");
$poStmt->bind_param('s', $userId);
$poStmt->execute();
$row = $poStmt->get_result()->fetch_assoc();
$poStmt->close();

$expectedOut   = $row['expectedPunchOut'] ?? '18:30:00';
$expectedOutTs = strtotime(date('Y-m-d') . ' ' . $expectedOut);

$egStmt = $con->prepare(
    "SELECT early_go_time FROM early_go
     WHERE userId = ? AND date = ? AND hr_granted = 1
     ORDER BY id DESC LIMIT 1"
);
$egStmt->bind_param('ss', $userId, $today);
$egStmt->execute();
$egRow = $egStmt->get_result()->fetch_assoc();
$egStmt->close();

$earlyGoTs       = null;
$hasHrEarlyGo    = false;
$earlyGoTimeDisp = null;

if ($egRow && !empty($egRow['early_go_time'])) {
    $earlyGoTs       = strtotime(date('Y-m-d') . ' ' . $egRow['early_go_time']);
    $hasHrEarlyGo    = true;
    $earlyGoTimeDisp = date('h:i A', $earlyGoTs);
}

$unlockTs = $expectedOutTs;
if ($hasHrEarlyGo && $earlyGoTs < $expectedOutTs) {
    $unlockTs = $earlyGoTs;
}

$unlocked       = ($nowTs >= $unlockTs);
$secondsLeft    = max(0, $unlockTs - $nowTs);
$unlockTimeDisp = date('h:i A', $unlockTs);

$result = [
    'unlocked'         => $unlocked,
    'unlockTime'       => $unlockTimeDisp,
    'unlockTs'         => $unlockTs,
    'secondsLeft'      => $secondsLeft,
    'hasHrEarlyGo'     => $hasHrEarlyGo,
    'earlyGoTime'      => $earlyGoTimeDisp,
    'expectedPunchOut' => date('h:i A', $expectedOutTs),
    'reason'           => $unlocked
        ? ($hasHrEarlyGo && $earlyGoTs < $expectedOutTs ? 'early_go' : 'normal_time')
        : 'locked',
];

// Cache for 30 s — countdown JS updates on the client, no need for more frequent DB hits
ams_cache_set($cacheKey, $result, 30);

echo json_encode($result);
