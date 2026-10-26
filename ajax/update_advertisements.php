<?php
// ajax/update_advertisements.php
ob_start();
include '../include/db_config.php';
ob_end_clean();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'msg' => 'Invalid request method']);
    exit;
}

if (empty($_SESSION['userId'])) {
    echo json_encode(['success' => false, 'msg' => 'Not authenticated']);
    exit;
}

$itemIds    = $_POST['edit_item_id'] ?? [];
$titles     = $_POST['edit_title'] ?? [];
$otherInfos = $_POST['edit_other_info'] ?? [];
$files      = $_FILES['edit_file'] ?? [];

if (empty($itemIds) || !is_array($itemIds)) {
    echo json_encode(['success' => false, 'msg' => 'No items submitted for update']);
    exit;
}

$uploadDir = '../pages/uploads/advertisements/';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$updatedCount = 0;
for ($i = 0; $i < count($itemIds); $i++) {
    $id        = (int)$itemIds[$i];
    $title     = trim($titles[$i] ?? '');
    $otherInfo = trim($otherInfos[$i] ?? '');

    if (!$id) continue;

    // Check if new file uploaded
    $newPath = null;
    if (isset($files['name'][$i]) && $files['error'][$i] === UPLOAD_ERR_OK) {
        $fileName = $files['name'][$i];
        $tmpName  = $files['tmp_name'][$i];
        $ext      = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        $newFileName = uniqid('ad_') . '_' . time() . '.' . $ext;
        $targetFile  = $uploadDir . $newFileName;

        if (move_uploaded_file($tmpName, $targetFile)) {
            $newPath = 'uploads/advertisements/' . $newFileName;
        }
    }

    if ($newPath !== null) {
        $stmt = $conn->prepare("UPDATE advertisements SET title = ?, description = ?, media_path = ? WHERE id = ?");
        $stmt->bind_param('sssi', $title, $otherInfo, $newPath, $id);
    } else {
        $stmt = $conn->prepare("UPDATE advertisements SET title = ?, description = ? WHERE id = ?");
        $stmt->bind_param('ssi', $title, $otherInfo, $id);
    }

    if ($stmt->execute()) $updatedCount++;
    $stmt->close();
}

echo json_encode(['success' => true, 'msg' => "Updated {$updatedCount} advertisement item(s) successfully!"]);
