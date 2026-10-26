<?php
// Output buffer MUST be first — captures any stray PHP output (errors, notices, session warnings)
// so they never corrupt the JSON response
ob_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once('../include/db_config.php');

// Discard anything buffered before this point (startup errors, etc.)
ob_clean();

header('Content-Type: application/json');

// ── Auth check ──
if (empty($_SESSION['userId'])) {
    ob_end_clean();
    echo json_encode(array('success' => false, 'msg' => 'Not logged in'));
    exit;
}

$userId    = mysqli_real_escape_string($con, $_SESSION['userId']);
$taskId    = isset($_POST['taskId']) ? (int)$_POST['taskId'] : 0;
$newStatus = isset($_POST['status']) ? trim($_POST['status']) : '';
$now       = date('Y-m-d H:i:s');

$allowed = array('Running', 'Paused', 'Finished');

if (!$taskId || !in_array($newStatus, $allowed)) {
    ob_end_clean();
    echo json_encode(array('success' => false, 'msg' => 'Invalid request'));
    exit;
}

// ── Fetch the task — must belong to this user ──
$fetch = mysqli_query($con,
    "SELECT taskId, status, startTime, totalDuration
     FROM TaskEntryNew
     WHERE taskId = " . $taskId . " AND userId = '" . $userId . "'"
);

if (!$fetch || mysqli_num_rows($fetch) === 0) {
    ob_end_clean();
    echo json_encode(array('success' => false, 'msg' => 'Task not found'));
    exit;
}

$task          = mysqli_fetch_assoc($fetch);
$currentStatus = $task['status'];
$totalDuration = (int)$task['totalDuration'];
$startTime     = (string)$task['startTime'];

// ── Helper: seconds elapsed since startTime ──
function calcElapsed($st) {
    if (empty($st) || $st === '0000-00-00 00:00:00' || $st === '0000-00-00') {
        return 0;
    }
    $ts = strtotime($st);
    if ($ts === false || $ts <= 0) { return 0; }
    return max(0, time() - $ts);
}

// ════════════════════════════════════════════════════════════
// PAUSE  (Running → Paused)
// ════════════════════════════════════════════════════════════
if ($newStatus === 'Paused' && $currentStatus === 'Running') {

    $totalDuration += calcElapsed($startTime);

    $q = mysqli_query($con,
        "UPDATE TaskEntryNew
         SET status         = 'Paused',
             lastPausedTime = '" . $now . "',
             totalDuration  = " . $totalDuration . "
         WHERE taskId = " . $taskId . " AND userId = '" . $userId . "'"
    );

    ob_end_clean();
    echo json_encode(array(
        'success' => (bool)$q,
        'msg'     => $q ? 'Task paused' : mysqli_error($con)
    ));
    exit;
}

// ════════════════════════════════════════════════════════════
// RESUME  (Paused → Running)
// ════════════════════════════════════════════════════════════
if ($newStatus === 'Running' && $currentStatus === 'Paused') {

    // Auto-pause any other running task for this user first
    $others = mysqli_query($con,
        "SELECT taskId, startTime, totalDuration
         FROM TaskEntryNew
         WHERE userId = '" . $userId . "' AND status = 'Running'"
    );
    if ($others) {
        while ($o = mysqli_fetch_assoc($others)) {
            $oElapsed  = calcElapsed((string)$o['startTime']);
            $oDuration = (int)$o['totalDuration'] + $oElapsed;
            $oId       = (int)$o['taskId'];
            mysqli_query($con,
                "UPDATE TaskEntryNew
                 SET status         = 'Paused',
                     lastPausedTime = '" . $now . "',
                     totalDuration  = " . $oDuration . "
                 WHERE taskId = " . $oId
            );
        }
    }

    // Resume — reset startTime so elapsed tracking starts fresh from now
    $q = mysqli_query($con,
        "UPDATE TaskEntryNew
         SET status    = 'Running',
             startTime = '" . $now . "'
         WHERE taskId = " . $taskId . " AND userId = '" . $userId . "'"
    );

    ob_end_clean();
    echo json_encode(array(
        'success' => (bool)$q,
        'msg'     => $q ? 'Task resumed' : mysqli_error($con)
    ));
    exit;
}

// ════════════════════════════════════════════════════════════
// FINISH
// ════════════════════════════════════════════════════════════
if ($newStatus === 'Finished') {

    // Only add elapsed time if task was still RUNNING.
    // If Paused, totalDuration already has all accumulated time.
    if ($currentStatus === 'Running') {
        $totalDuration += calcElapsed($startTime);
    }

if ($totalDuration < 0 || $totalDuration > 315360000) {
    $totalDuration = 0;
}

// Seconds → decimal hours  (e.g. 5400s → 1.50 h)
$hours = (int)floor($totalDuration / 3600);
$mins  = (int)floor(($totalDuration % 3600) / 60);
$manHr = round($hours + ($mins / 60.0), 2);

    $q = mysqli_query($con,
        "UPDATE TaskEntryNew
         SET status        = 'Finished',
             endTime       = '" . $now . "',
             totalDuration = " . $totalDuration . ",
             manHr         = " . $manHr . "
         WHERE taskId = " . $taskId . " AND userId = '" . $userId . "'"
    );

    ob_end_clean();
    echo json_encode(array(
        'success' => (bool)$q,
        'msg'     => $q ? 'Task finished' : mysqli_error($con)
    ));
    exit;
}

// ── Fallback ──
ob_end_clean();
echo json_encode(array(
    'success' => false,
    'msg'     => "Cannot change '" . $currentStatus . "' to '" . $newStatus . "'"
));