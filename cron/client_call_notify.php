<?php
require_once __DIR__ . '/../include/db_config.php';
require_once __DIR__ . '/../include/notifications.php';
date_default_timezone_set('Asia/Kolkata');

if ((int)date('d') !== 15) exit;

$createdAt = date('Y-m-d H:i:s');

$q = mysqli_query($con,"
SELECT c.*, s.fullName, s.token
FROM client_call_notifications c
JOIN signup s ON s.userId COLLATE utf8mb4_unicode_ci 
        = c.employee_userId COLLATE utf8mb4_unicode_ci
WHERE c.is_active = 1
");

while($r = mysqli_fetch_assoc($q)){
    if(!$r['token']) continue;

    sendFCMNotification(
        $r['token'],
        'Client Call Reminder 📞 from Admin',
        $r['message'],
        $accessToken,
        $projectId
    );

    $stmt = $con->prepare(
        "INSERT INTO notifications
        (userId, title, message, status, is_read, created_at)
        VALUES (?, 'Client Call', ?, 'sent', 0, ?)"
    );
    $stmt->bind_param('sss', $r['employee_userId'], $r['message'], $createdAt);
    $stmt->execute();
}
