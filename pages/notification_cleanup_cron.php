<?php
/**
 * notification_cleanup_cron.php
 * 
 * Runs once daily via cron:
 *   0 2 * * * /usr/bin/php /path/to/pages/notification_cleanup_cron.php
 * 
 * Rules:
 *  - "No Active Task" and "Hourly Task Check" → delete after 7 days
 *  - All other notifications                  → delete after 60 days
 */

include('../include/db_config.php');
date_default_timezone_set('Asia/Kolkata');

echo "🧹 Notification cleanup started at " . date('Y-m-d H:i:s') . "<br>";

// ── Rule 1: Task cron notifications → 7 days ──────────────────────────
$stmt7 = $con->prepare("
DELETE FROM notifications
    WHERE (title IN ('No Active Task', 'Hourly Task Check') OR (title LIKE 'Hello%' AND message = 'Welcome to SETTribe!'))
      AND created_at < NOW() - INTERVAL 7 DAY
");
$stmt7->execute();
$deleted7 = $stmt7->affected_rows;
$stmt7->close();
echo "🗑️  Task notifications deleted (>7 days): $deleted7<br>";

// ── Rule 2: All other notifications → 60 days ─────────────────────────
$stmt60 = $con->prepare("
    DELETE FROM notifications
    WHERE title NOT IN ('No Active Task', 'Hourly Task Check')
      AND created_at < NOW() - INTERVAL 60 DAY
");
$stmt60->execute();
$deleted60 = $stmt60->affected_rows;
$stmt60->close();
echo "🗑️  Other notifications deleted (>60 days): $deleted60<br>";

// ── Rule 3: push_only_log cleanup (1 day is enough) ───────────────────
$stmtLog = $con->prepare("
    DELETE FROM push_only_log
    WHERE sent_at < NOW() - INTERVAL 1 DAY
");
$stmtLog->execute();
$deletedLog = $stmtLog->affected_rows;
$stmtLog->close();
echo "🗑️  push_only_log entries deleted (>1 day): $deletedLog<br>";

echo "<br>✅ Cleanup finished at " . date('H:i:s');
echo "<br>📊 Total deleted: " . ($deleted7 + $deleted60 + $deletedLog);
?>