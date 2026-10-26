<?php
/**
 * meeting_reminder_cron.php
 *
 * Run this via a cron job every minute:
 *   * * * * * php /path/to/your/project/meeting_reminder_cron.php >> /tmp/meeting_cron.log 2>&1
 *
 * It finds meetings starting in the next 28–32 minute window (to avoid
 * duplicate sends even if cron fires slightly early/late) where a reminder
 * has NOT already been sent, sends notifications, then marks them as sent.
 *
 * Requires a column  `reminder_sent` TINYINT(1) DEFAULT 0  on the meetings table.
 * Add it once with:
 *   ALTER TABLE meetings ADD COLUMN reminder_sent TINYINT(1) NOT NULL DEFAULT 0;
 */

// ── Bootstrap ────────────────────────────────────────────────
define('CRON_RUN', true);

// Adjust path to match your project structure
require_once __DIR__ . '/../include/db_config.php';
require_once __DIR__ . '/send_meeting_notification.php';

date_default_timezone_set('Asia/Kolkata');

$now        = date('Y-m-d H:i:s');
$windowFrom = date('Y-m-d H:i:s', strtotime('+28 minutes'));
$windowTo   = date('Y-m-d H:i:s', strtotime('+32 minutes'));

echo "[" . date('Y-m-d H:i:s') . "] Cron started. Window: $windowFrom → $windowTo\n";

// ── Find meetings in the 30-min window that haven't been reminded ─
$query = mysqli_query($con, "
    SELECT id, agenda, meeting_date, meeting_time
    FROM meetings
    WHERE reminder_sent = 0
      AND (status IS NULL OR status != 'cancelled')
      AND CONCAT(meeting_date, ' ', meeting_time) BETWEEN '$windowFrom' AND '$windowTo'
");

if (!$query) {
    echo "DB error: " . mysqli_error($con) . "\n";
    exit(1);
}

$count = mysqli_num_rows($query);
echo "Found $count meeting(s) to remind.\n";

while ($row = mysqli_fetch_assoc($query)) {
    $meetingId = (int)$row['id'];
    $agenda    = $row['agenda'];

    echo "  → Sending reminder for meeting #$meetingId: \"$agenda\"\n";

    // Send reminder notifications
    sendMeetingNotification($con, $meetingId, 'reminder');

    // Mark as reminded so we don't send again
    mysqli_query($con, "UPDATE meetings SET reminder_sent = 1 WHERE id = $meetingId");

    echo "    ✓ Done.\n";
}

echo "[" . date('Y-m-d H:i:s') . "] Cron finished.\n";