<?php
/**
 * send_meeting_notification.php
 * Sends FCM push notifications for meetings.
 * Include this wherever you need to send meeting notifications.
 *
 * Usage:
 *   include 'send_meeting_notification.php';
 *   sendMeetingNotification($con, $meetingId, 'created');   // on creation
 *   sendMeetingNotification($con, $meetingId, 'reminder');  // 30-min cron
 */

if (!defined('FCM_API_KEY')) {
    define('FCM_API_KEY', 'AAAATNyl-cg:APA91bHNY9rLBLSjran_NsyqE8GkmRgnDmOs9jzq0xavzV2jsOUkyItJIXzi9aLsg1JWYMsVPDSVYWgXyco7eBqa7OnQm_sI4ijokF8VnyAHZwjUZPGRYYlKi9ISqLSbVEI6EtV57bM9');
}

/**
 * Core FCM sender — batches up to 1000 tokens per request.
 */
function fcmSend(array $tokens, string $title, string $body, array $data = []): array
{
    $tokens = array_values(array_filter(array_unique($tokens)));
    $sent   = 0;
    $failed = 0;

    if (empty($tokens)) {
        return ['sent' => 0, 'failed' => 0];
    }

    foreach (array_chunk($tokens, 1000) as $chunk) {
        $payload = [
            'registration_ids' => $chunk,
            'notification'     => [
                'title'     => $title,
                'body'      => $body,
                'sound'     => 'default',
                'smallIcon' => 'small_icon',
            ],
            'data'             => array_merge($data, [
                'title' => $title,
                'body'  => $body,
            ]),
            'priority'          => 'high',
            'content_available' => true,
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL,            'https://fcm.googleapis.com/fcm/send');
        curl_setopt($ch, CURLOPT_POST,           true);
        curl_setopt($ch, CURLOPT_HTTPHEADER,     [
            'Authorization: key=' . FCM_API_KEY,
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POSTFIELDS,     json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT,        10);

        $result  = curl_exec($ch);
        curl_close($ch);

        if ($result !== false) {
            $decoded = json_decode($result, true);
            $sent   += (int)($decoded['success'] ?? 0);
            $failed += (int)($decoded['failure'] ?? 0);
        }
    }

    return ['sent' => $sent, 'failed' => $failed];
}

/**
 * Save an in-app notification row to the `notifications` table
 * (same table used by the Notifications page you shared).
 */
function saveInAppNotification($con, string $userId, string $title, string $message, int $meetingId): void
{
    $userId  = mysqli_real_escape_string($con, $userId);
    $title   = mysqli_real_escape_string($con, $title);
    $message = mysqli_real_escape_string($con, $message);
    $link    = '../pages/meetingHistory.php?highlight=' . $meetingId;
    mysqli_query($con, "
        INSERT INTO notifications (userId, title, message, link, type, status, is_read, created_at)
        VALUES ('$userId', '$title', '$message', '$link', 'meeting', 'active', 0, NOW())
    ");
}

/**
 * Resolve all participant userIds for a meeting.
 * Participants column stores a comma-separated list of userIds OR batch numbers.
 */
function getMeetingParticipantUserIds($con, array $participantTokens, string $creatorId): array
{
    $userIds = [];

    foreach ($participantTokens as $token) {
        $token = trim($token);
        if ($token === '') continue;

        // Check if it's a direct userId
        $esc = mysqli_real_escape_string($con, $token);
        $r   = mysqli_query($con, "SELECT userId FROM signup WHERE userId='$esc' LIMIT 1");
        if ($r && mysqli_num_rows($r) > 0) {
            $userIds[] = $token;
            continue;
        }

        // Otherwise treat as batch number — get all userIds in that batch
        $r2 = mysqli_query($con, "SELECT userId FROM signup WHERE batch_number='$esc' AND regstatus='Approved'");
        if ($r2) {
            while ($row = mysqli_fetch_assoc($r2)) {
                $userIds[] = $row['userId'];
            }
        }
    }

    // Always include creator
    $userIds[] = $creatorId;

    return array_values(array_unique($userIds));
}

/**
 * Main function: send meeting notification to all participants.
 *
 * @param mysqli  $con        DB connection
 * @param int     $meetingId  meetings.id
 * @param string  $type       'created' | 'reminder'
 */
function sendMeetingNotification($con, int $meetingId, string $type = 'created'): void
{
    // 1. Fetch meeting details
    $mid = (int)$meetingId;
    $mQ  = mysqli_query($con, "
        SELECT m.*, s.fullName AS creatorName
        FROM meetings m
        LEFT JOIN signup s ON m.creator_id = s.userId
        WHERE m.id = $mid
        LIMIT 1
    ");
    if (!$mQ || mysqli_num_rows($mQ) === 0) return;

    $meeting    = mysqli_fetch_assoc($mQ);
    $agenda     = $meeting['agenda']      ?? 'Meeting';
    $meetDate   = $meeting['meeting_date'] ?? '';
    $meetTime   = $meeting['meeting_time'] ?? '';
    $creatorId  = $meeting['creator_id']   ?? '';
    $creatorName = $meeting['creatorName'] ?? 'Admin';
    $mode       = ucfirst($meeting['mode'] ?? 'Online');
    $rawParts   = $meeting['participants'] ?? '';

    // 2. Resolve participant userIds
    $partTokens = array_filter(explode(',', $rawParts));
    $userIds    = getMeetingParticipantUserIds($con, $partTokens, $creatorId);

    if (empty($userIds)) return;

    // 3. Build notification text
    $dateDisp = !empty($meetDate) ? date('d M Y', strtotime($meetDate)) : '';
    $timeDisp = !empty($meetTime) ? date('h:i A', strtotime($meetTime)) : '';

    if ($type === 'created') {
        $title   = "📅 New Meeting Scheduled";
        $message = "\"$agenda\" scheduled on $dateDisp at $timeDisp ($mode). By $creatorName.";
    } else {
        // reminder
        $title   = "⏰ Meeting Starting in 30 Minutes";
        $message = "\"$agenda\" starts at $timeDisp today ($mode). By $creatorName.";
    }

    // 4. Collect FCM tokens + save in-app notifications
    $fcmTokens = [];

    foreach ($userIds as $uid) {
        // Save in-app notification for each participant
        saveInAppNotification($con, $uid, $title, $message, $meetingId);

        // Get FCM token (adjust column name if yours is different)
        $esc = mysqli_real_escape_string($con, $uid);
        $tQ  = mysqli_query($con, "SELECT fcm_token FROM signup WHERE userId='$esc' AND fcm_token IS NOT NULL AND fcm_token != '' LIMIT 1");
        if ($tQ && mysqli_num_rows($tQ) > 0) {
            $fcmTokens[] = mysqli_fetch_assoc($tQ)['fcm_token'];
        }
    }

    // 5. Send FCM push
    if (!empty($fcmTokens)) {
        fcmSend($fcmTokens, $title, $message, [
            'type'       => 'meeting',
            'meeting_id' => (string)$meetingId,
        ]);
    }
}