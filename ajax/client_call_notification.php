<?php
session_start();
include('../include/db_config.php');
include_once('../include/notifications.php');

if ($_SESSION['userRole'] !== 'admin' && $_SESSION['userRole'] !== 'hr') {
    exit('Unauthorized');
}

$id        = (int)($_POST['notification_id'] ?? 0);
$employees = $_POST['employees'] ?? [];
$message   = trim($_POST['message'] ?? '');

if ($message === '' || empty($employees)) {
    exit('Invalid data');
}

/* ================= UPDATE ================= */
$updatedAt = date('Y-m-d H:i:s');

if ($id > 0) {
    $stmt = $con->prepare(
        "UPDATE client_call_notifications
         SET message = ?, updated_at = ?
         WHERE id = ?"
    );
    $stmt->bind_param('ssi', $message, $updatedAt, $id);
    $stmt->execute();
    $stmt->close();

    echo 'OK';
    exit;
}

/* ================= INSERT ================= */
$insertStmt = $con->prepare(
    "INSERT INTO client_call_notifications
     (employee_userId, message, created_by, created_at)
     VALUES (?, ?, ?, ?)"
);

foreach ($employees as $uid) {

    // 1️⃣ Fetch token and name
    $selectStmt = $con->prepare(
        "SELECT fullName, token 
         FROM signup 
         WHERE userId = ? AND status = 'Active'"
    );
    $selectStmt->bind_param('s', $uid);
    $selectStmt->execute();
    $res = $selectStmt->get_result();
    $selectStmt->close();

    if ($res->num_rows === 0) continue;

    $user = $res->fetch_assoc();
    $fcmToken = trim($user['token'] ?? '');
    $fullName = trim($user['fullName'] ?? 'Employee');

    if ($fcmToken === '') continue;

    // 2️⃣ Send FCM
    $title = "Client Call Reminder 📞 from HR";
    $body  = $message;

    $response = sendFCMNotification($fcmToken, $title, $body, $accessToken, $projectId);
    if (!$response) continue; // skip if notification fails

    // 3️⃣ Insert client_call_notifications
    $createdAt = date('Y-m-d H:i:s');
    $insertStmt->bind_param('ssss', $uid, $message, $_SESSION['userId'], $createdAt);
    $insertStmt->execute();

    // 4️⃣ Log in notifications table
    $stmtNotif = $con->prepare(
        "INSERT INTO notifications
         (userId, title, message, status, is_read, created_at)
         VALUES (?, ?, ?, 'sent', 1, ?)"
    );
    $stmtNotif->bind_param('ssss', $uid, $title, $body, $createdAt);
    $stmtNotif->execute();
    $stmtNotif->close();
}

$insertStmt->close();
echo 'OK';
