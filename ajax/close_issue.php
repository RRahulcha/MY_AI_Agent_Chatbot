<?php
session_start();
include('../include/db_config.php');

if (!isset($_SESSION['userId']) || $_SESSION['userRole'] !== 'developer') {
    http_response_code(403);
    exit('Unauthorized');
}

if (!isset($_POST['issueId'], $_POST['remark'])) {
    http_response_code(400);
    exit('Invalid request');
}

$issueId = (int) $_POST['issueId'];
$remark  = trim($_POST['remark']);

if ($remark === '') {
    http_response_code(400);
    exit('Remark is required');
}

/* ---------------- HELPERS ---------------- */
function extractSection($text, $marker) {
    if (preg_match(
        '/' . preg_quote($marker, '/') . '([\s\S]*?)(?=(---REMARK---|---DEADLINE---|$))/',
        $text,
        $m
    )) {
        return trim($m[1]);
    }
    return '';
}

function stripSections($text) {
    return trim(preg_replace(
        '/---REMARK---[\s\S]*?(?=(---DEADLINE---|$))|---DEADLINE---[\s\S]*$/',
        '',
        $text
    ));
}

/* ---------------- FETCH ISSUE ---------------- */
$q = mysqli_query($con, "SELECT description, type, userId FROM feedbackIssues WHERE id = $issueId");
if (!$q || mysqli_num_rows($q) === 0) {
    http_response_code(404);
    exit('Item not found');
}

$row = mysqli_fetch_assoc($q);
$desc     = $row['description'];
$itemType = ucfirst($row['type'] ?? 'Issue');
$itemUser = $row['userId'];

$baseDesc = stripSections($desc);
$deadline = extractSection($desc, '---DEADLINE---');

/* ---------------- FILE UPLOAD ---------------- */

$attachmentText = '';

if (
    isset($_FILES['attachment']) &&
    $_FILES['attachment']['error'] === UPLOAD_ERR_OK
) {
    // ABSOLUTE path — no guessing
    $uploadDir = __DIR__ . '/../uploads/issues/';

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    // Sanitize filename
    $originalName = $_FILES['attachment']['name'];
    $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
    $fileName = time() . '_' . $safeName;

    $targetPath = $uploadDir . $fileName;

    if (!move_uploaded_file($_FILES['attachment']['tmp_name'], $targetPath)) {
        error_log('UPLOAD DIR: ' . $uploadDir);
        error_log('Upload failed. Target: ' . $targetPath);
    } else {
        $attachmentText = "\nAttachment: " . $fileName;
    }
}

/* ---------------- REBUILD DESCRIPTION ---------------- */

$newDesc  = $baseDesc;
$newDesc .= "\n\n---REMARK---\n" . $remark . $attachmentText;

if ($deadline !== '') {
    $newDesc .= "\n\n---DEADLINE---\n" . $deadline;
}

/* ---------------- UPDATE DB ---------------- */

$stmt = mysqli_prepare(
    $con,
    "UPDATE feedbackIssues SET description = ?, status = 'Closed' WHERE id = ?"
);
mysqli_stmt_bind_param($stmt, 'si', $newDesc, $issueId);
mysqli_stmt_execute($stmt);

/* ---------------- SEND NOTIFICATION ---------------- */
if ($itemUser) {
    $tokenQuery = mysqli_query($con, "SELECT fullName, token FROM signup WHERE userId = '$itemUser'");
    if ($tokenQuery && mysqli_num_rows($tokenQuery) > 0) {
        $userRow = mysqli_fetch_assoc($tokenQuery);
        $fcmToken = $userRow['token'];
        $fullName = trim($userRow['fullName'] ?? 'User');

        if (!empty($fcmToken)) {
            include('../include/notifications.php');

            $title = "$itemType Closed ✅";
            $body  = "$itemType #$issueId has been resolved.\nRemark: $remark";

            $response = sendFCMNotification($fcmToken, $title, $body, $accessToken, $projectId);

            if ($response) {
                $stmtNotif = $con->prepare("INSERT INTO notifications (userId, title, message, status, is_read, created_at) VALUES (?, ?, ?, ?, 1, ?)");
                $status = 'sent';
                $dateTime = date('Y-m-d H:i:s');
                $stmtNotif->bind_param('sssss', $itemUser, $title, $body, $status, $dateTime);
                $stmtNotif->execute();
                $stmtNotif->close();
            }
        }
    }
}

http_response_code(200);
echo 'OK';
