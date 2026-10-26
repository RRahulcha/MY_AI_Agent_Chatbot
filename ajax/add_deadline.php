<?php
session_start();
include('../include/db_config.php');

if (!isset($_SESSION['userId']) || $_SESSION['userRole'] !== 'developer') {
    http_response_code(403);
    exit('Unauthorized');
}

if (!isset($_POST['issueId'], $_POST['deadline'])) {
    http_response_code(400);
    exit('Invalid request');
}

$issueId  = intval($_POST['issueId']);
$deadline = trim($_POST['deadline']);

if ($deadline === '') {
    http_response_code(400);
    exit('Deadline required');
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

/* ---------------- FETCH ITEM ---------------- */
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
$remark   = extractSection($desc, '---REMARK---');

/* ---------------- REBUILD DESCRIPTION ---------------- */

$newDesc = $baseDesc;

if ($remark !== '') {
    $newDesc .= "\n\n---REMARK---\n" . $remark;
}

$newDesc .= "\n\n---DEADLINE---\n" . $deadline;

/* ---------------- UPDATE ---------------- */

$stmt = mysqli_prepare(
    $con,
    "UPDATE feedbackIssues SET description = ? WHERE id = ?"
);
mysqli_stmt_bind_param($stmt, 'si', $newDesc, $issueId);
mysqli_stmt_execute($stmt);

/* ---------------- SEND NOTIFICATION ---------------- */
if ($itemUser) {
    $tokenQuery = mysqli_query($con, "SELECT token, fullName FROM signup WHERE userId = '$itemUser'");
    if ($tokenQuery && mysqli_num_rows($tokenQuery) > 0) {
        $tokenRow = mysqli_fetch_assoc($tokenQuery);
        $fcmToken = $tokenRow['token'];
        $fullName = trim($tokenRow['fullName'] ?? 'User');

        if (!empty($fcmToken)) {
            include('../include/notifications.php');

            $title = "Deadline Set ⏰";
            $body  = "$itemType #$issueId will be resolved at $deadline";

            $response = sendFCMNotification($fcmToken, $title, $body, $accessToken, $projectId);

            if ($response) {
                $stmtNotif = $con->prepare("INSERT INTO notifications (userId, title, message, status, is_read, created_at) VALUES (?, ?, ?, ?, 0, ?)");
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
