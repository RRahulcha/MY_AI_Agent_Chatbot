<?php
// ajax/save_advertisements.php
// Saves advertisement items (images or videos) grouped by advertisement_group_id

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

error_reporting(0);
ini_set('display_errors', '0');

ob_start();
include '../include/db_config.php';
if (ob_get_length()) ob_clean();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (ob_get_length()) ob_clean();
    echo json_encode(['success' => false, 'msg' => 'Invalid request method']);
    exit;
}

if (empty($_SESSION['userId'])) {
    if (ob_get_length()) ob_clean();
    echo json_encode(['success' => false, 'msg' => 'Not authenticated']);
    exit;
}

$display_location = trim($_POST['display_location'] ?? 'Main Display Center');
$start_date       = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
$end_date         = !empty($_POST['end_date']) ? $_POST['end_date'] : null;

$titles     = $_POST['title'] ?? [];
$otherInfos = $_POST['other_info'] ?? [];
$mediaTypes = $_POST['media_type'] ?? [];
$files      = $_FILES['file'] ?? [];

if (empty($titles) || !is_array($titles)) {
    if (ob_get_length()) ob_clean();
    echo json_encode(['success' => false, 'msg' => 'No items submitted']);
    exit;
}

$groupId = 'group_' . uniqid() . '_' . time();
$uploadDir = '../pages/uploads/advertisements/';

if (!file_exists($uploadDir)) {
    @mkdir($uploadDir, 0777, true);
}

$allowedImg = ['jpg', 'jpeg', 'png', 'webp'];
$allowedVid = ['mp4', 'webm', 'mov'];
$savedCount = 0;

$stmt = $conn->prepare("
    INSERT INTO advertisements
        (advertisement_group_id, title, description, media_type, media_path, display_location, status, start_date, end_date)
    VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)
");

if (!$stmt) {
    if (ob_get_length()) ob_clean();
    echo json_encode(['success' => false, 'msg' => 'Database error: ' . $conn->error]);
    exit;
}

for ($i = 0; $i < count($titles); $i++) {
    $title     = trim($titles[$i] ?? '');
    $otherInfo = trim($otherInfos[$i] ?? '');
    $mType     = strtolower(trim($mediaTypes[$i] ?? 'image'));

    if (empty($title) && empty($otherInfo)) continue;

    $mediaPath = '';
    if (isset($files['error'][$i]) && $files['error'][$i] !== UPLOAD_ERR_NO_FILE) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            $errCode = $files['error'][$i];
            $errMsgs = [
                UPLOAD_ERR_INI_SIZE   => 'File size exceeds server upload limit (upload_max_filesize).',
                UPLOAD_ERR_FORM_SIZE  => 'File size exceeds form limit.',
                UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary upload directory on server.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload.'
            ];
            $errMsg = $errMsgs[$errCode] ?? 'File upload error code: ' . $errCode;
            $stmt->close();
            if (ob_get_length()) ob_clean();
            echo json_encode(['success' => false, 'msg' => "Item #" . ($i + 1) . ": " . $errMsg]);
            exit;
        }

        $fileName = $files['name'][$i] ?? '';
        $tmpName  = $files['tmp_name'][$i] ?? '';
        $ext      = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        $allowed = ($mType === 'video') ? $allowedVid : $allowedImg;
        if (!in_array($ext, $allowed)) {
            $stmt->close();
            if (ob_get_length()) ob_clean();
            echo json_encode(['success' => false, 'msg' => "Item #" . ($i + 1) . ": Invalid file extension .{$ext} for {$mType}"]);
            exit;
        }

        $newFileName = uniqid('ad_') . '_' . time() . '.' . $ext;
        $targetFile  = $uploadDir . $newFileName;

        if (move_uploaded_file($tmpName, $targetFile)) {
            $mediaPath = 'uploads/advertisements/' . $newFileName;
        } else {
            $stmt->close();
            if (ob_get_length()) ob_clean();
            echo json_encode(['success' => false, 'msg' => "Item #" . ($i + 1) . ": Failed to move uploaded file."]);
            exit;
        }
    }

    $stmt->bind_param('ssssssss', $groupId, $title, $otherInfo, $mType, $mediaPath, $display_location, $start_date, $end_date);
    if ($stmt->execute()) {
        $savedCount++;
    }
}
$stmt->close();

if (ob_get_length()) ob_clean();

if ($savedCount > 0) {
    echo json_encode(['success' => true, 'msg' => "Saved {$savedCount} advertisement item(s) successfully!"]);
} else {
    echo json_encode(['success' => false, 'msg' => 'Failed to save items or no valid files uploaded.']);
}
exit;
