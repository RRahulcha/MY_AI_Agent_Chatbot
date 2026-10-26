<?php
/**
 * cron_phase_transition.php
 * ─────────────────────────────────────────────────────────────────
 * Runs daily via cron. Automatically transitions interns from
 * Training → Working when their trainingEndDate has passed.
 *
 * Setup cron (runs every day at midnight):
 *   0 0 * * * php /path/to/your/project/cron_phase_transition.php
 *
 * Or via cPanel Cron Jobs:
 *   Command: php /home/youraccount/public_html/yourproject/cron_phase_transition.php
 * ─────────────────────────────────────────────────────────────────
 */
 
// ── Allow running both from CLI and browser (protect browser access) ─
if (php_sapi_name() !== 'cli') {
    // Optional: add a secret token for browser-based testing
    // e.g. call: cron_phase_transition.php?token=YOUR_SECRET
    $secret = 'YOUR_CRON_SECRET_TOKEN'; // Change this!
    if (!isset($_GET['token']) || $_GET['token'] !== $secret) {
        http_response_code(403);
        die('Access denied.');
    }
}
 
define('BASE_PATH', dirname(__FILE__));
include(BASE_PATH . '/../include/db_config.php');
 
$today = date('Y-m-d');
$log   = [];
$log[] = "=== Phase Transition Cron | " . date('Y-m-d H:i:s') . " ===";
 
// ── Check if trainingEndDate column exists ────────────────────────
$colCheck = mysqli_query($con, "SHOW COLUMNS FROM interns LIKE 'trainingEndDate'");
if (!$colCheck || mysqli_num_rows($colCheck) === 0) {
    $log[] = "ERROR: trainingEndDate column does not exist in interns table. Run onBoarding.php first to create it.";
    writeLog($log);
    exit;
}
 
// ── Find all interns still in Training whose trainingEndDate has passed ──
$query = mysqli_query($con, "
    SELECT internId, fullName, email, trainingEndDate, stipend, approvedBy
    FROM interns
    WHERE phase = 'Training'
      AND trainingEndDate IS NOT NULL
      AND trainingEndDate <= '$today'
");
 
if (!$query) {
    $log[] = "ERROR: Query failed - " . mysqli_error($con);
    writeLog($log);
    exit;
}
 
$transitioned = 0;
$errors       = 0;
 
while ($intern = mysqli_fetch_assoc($query)) {
    $internId    = mysqli_real_escape_string($con, $intern['internId']);
    $fullName    = $intern['fullName'];
    $trainingEnd = $intern['trainingEndDate'];
 
    // Update phase to Working
    $update = mysqli_query($con, "
        UPDATE interns SET phase = 'Working'
        WHERE internId = '$internId' AND phase = 'Training'
    ");
 
    if ($update && mysqli_affected_rows($con) > 0) {
        $transitioned++;
        $log[] = "✔ TRANSITIONED: {$fullName} ({$internId}) | Training ended: {$trainingEnd} | Stipend: {$intern['stipend']}";
    } else {
        $errors++;
        $log[] = "✘ FAILED: {$fullName} ({$internId}) | " . mysqli_error($con);
    }
}
 
// ── Summary ──────────────────────────────────────────────────────
$log[] = "";
$log[] = "Summary: {$transitioned} intern(s) transitioned to Working phase.";
if ($errors > 0) {
    $log[] = "Errors: {$errors} intern(s) failed to transition.";
}
$log[] = "=== Done ===";
 
writeLog($log);
 
// ── Print to console if CLI ───────────────────────────────────────
if (php_sapi_name() === 'cli') {
    foreach ($log as $line) echo $line . PHP_EOL;
} else {
    header('Content-Type: text/plain');
    echo implode("\n", $log);
}
 
// ── Log to file ───────────────────────────────────────────────────
function writeLog(array $lines) {
    $logDir  = BASE_PATH . '/../logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . '/phase_transition_' . date('Y-m') . '.log';
    $content = implode("\n", $lines) . "\n\n";
    @file_put_contents($logFile, $content, FILE_APPEND);
}
 