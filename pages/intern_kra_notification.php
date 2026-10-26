<?php
/**
 * intern_kra_cron.php
 * ---------------------------------------------------------------
 * Friday Reminder Cron Job - Intern KRA Submission Reminder
 *
 * Schedule this file to run every Friday via cron:
 *   0 9 * * 5 /usr/bin/php /var/www/html/cron/intern_kra_cron.php
 *
 * This script:
 *  1. Checks if today is Friday
 *  2. Inserts reminder notifications for SrEmployee, TeamLead, ResourceHead
 *  3. Logs execution to intern_kra_cron_log table (optional)
 * ---------------------------------------------------------------
 */

// Allow CLI execution; block direct browser access in production
if (php_sapi_name() !== 'cli') {
    // Allow running via browser for manual testing — remove in production
    // die('CLI only');
}

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/include/db_config.php';

$today     = date('N'); // 1=Mon … 5=Fri
$weekNum   = (int)date('W');
$weekYear  = (int)date('Y');
$now       = date('Y-m-d H:i:s');

echo "[" . date('Y-m-d H:i:s') . "] KRA Reminder Cron Started\n";

// Only run on Friday (day 5); remove this check for manual testing
if ($today != 5) {
    echo "[SKIP] Today is not Friday. Day: $today\n";
    exit;
}

echo "[INFO] Today is Friday. Sending KRA reminders for Week $weekNum, $weekYear...\n";

// Fetch all active SrEmployee, TeamLead, ResourceHead
$usersQuery = mysqli_query($con,
    "SELECT userId, fullName, designation
     FROM signup
     WHERE designation IN ('srEmployee', 'teamLead', 'resourceHead')
       AND status = 'active'
     ORDER BY fullName ASC"
);

if (!$usersQuery || mysqli_num_rows($usersQuery) === 0) {
    echo "[WARN] No eligible users found for reminder.\n";
    exit;
}

$sentCount   = 0;
$skipCount   = 0;
$systemId    = 'SYSTEM';
$systemName  = 'System';

while ($u = mysqli_fetch_assoc($usersQuery)) {
    $toUserId   = mysqli_real_escape_string($con, $u['userId']);
    $toUserName = mysqli_real_escape_string($con, $u['fullName']);

    // Check if reminder already sent this week for this user
    $dupCheck = mysqli_query($con,
        "SELECT id FROM notifications
         WHERE toUserId   = '$toUserId'
           AND type       = 'kra_friday_reminder'
           AND refId      = '$weekNum'
           AND YEAR(createdAt) = '$weekYear'"
    );

    if ($dupCheck && mysqli_num_rows($dupCheck) > 0) {
        echo "[SKIP] Reminder already sent to $toUserName for Week $weekNum\n";
        $skipCount++;
        continue;
    }

    $message = mysqli_real_escape_string($con,
        "⏰ Weekly Reminder: Please submit KRA ratings for your interns before end of day. (Week $weekNum, $weekYear)"
    );

    $insert = mysqli_query($con,
        "INSERT INTO notifications
         (toUserId, toUserName, fromUserId, fromUserName, message, type, refId, status, createdAt)
         VALUES
         ('$toUserId', '$toUserName', '$systemId', '$systemName',
          '$message', 'kra_friday_reminder', '$weekNum', 'unread', '$now')"
    );

    if ($insert) {
        echo "[SENT] Reminder sent to $toUserName ($u[designation])\n";
        $sentCount++;
    } else {
        echo "[ERROR] Failed to send reminder to $toUserName: " . mysqli_error($con) . "\n";
    }
}

// Log cron run
$totalUsers = mysqli_num_rows($usersQuery);
$logMsg     = mysqli_real_escape_string($con, "KRA Friday Reminder — Week $weekNum/$weekYear | Sent: $sentCount | Skipped: $skipCount");

mysqli_query($con,
    "INSERT INTO internKraCronLog (runAt, weekNumber, weekYear, sentCount, skipCount, notes)
     VALUES ('$now', '$weekNum', '$weekYear', '$sentCount', '$skipCount', '$logMsg')"
);

echo "[DONE] Total: $totalUsers | Sent: $sentCount | Skipped: $skipCount\n";
echo "[" . date('Y-m-d H:i:s') . "] KRA Reminder Cron Finished\n";
exit;