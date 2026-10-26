<?php
// ajax/update_placed_student.php
ob_start();
include '../include/db_config.php';

header('Content-Type: application/json');

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

$id             = (int)($_POST['id'] ?? 0);
$student_name    = trim($_POST['student_name'] ?? '');
$branch          = trim($_POST['branch'] ?? '');
$company_name    = trim($_POST['company_name'] ?? '');
$role_offered    = trim($_POST['role_offered'] ?? '');
$package_ctc     = trim($_POST['package_ctc'] ?? '');
$placement_date  = !empty($_POST['placement_date']) ? $_POST['placement_date'] : null;
$status          = trim($_POST['status'] ?? 'active');
$remarks         = trim($_POST['remarks'] ?? '');

if (!$id || empty($student_name) || empty($company_name)) {
    if (ob_get_length()) ob_clean();
    echo json_encode(['success' => false, 'msg' => 'ID, Student Name, and Company Name are required.']);
    exit;
}

// Photo update check
$photoPath = null;
if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
    $uploadDir = '../pages/uploads/placed_students/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    if (in_array($ext, $allowed)) {
        $newFile = uniqid('student_') . '_' . time() . '.' . $ext;
        $target  = $uploadDir . $newFile;
        if (move_uploaded_file($_FILES['photo']['tmp_name'], $target)) {
            $photoPath = 'uploads/placed_students/' . $newFile;
        }
    }
}

if ($photoPath !== null) {
    $stmt = $conn->prepare("
        UPDATE placements
        SET student_name = ?, branch = ?, company_name = ?, role_offered = ?, package_ctc = ?, placement_date = ?, status = ?, photo_path = ?, remarks = ?
        WHERE id = ?
    ");
    $stmt->bind_param('sssssssssi', $student_name, $branch, $company_name, $role_offered, $package_ctc, $placement_date, $status, $photoPath, $remarks, $id);
} else {
    $stmt = $conn->prepare("
        UPDATE placements
        SET student_name = ?, branch = ?, company_name = ?, role_offered = ?, package_ctc = ?, placement_date = ?, status = ?, remarks = ?
        WHERE id = ?
    ");
    $stmt->bind_param('ssssssssi', $student_name, $branch, $company_name, $role_offered, $package_ctc, $placement_date, $status, $remarks, $id);
}

if ($stmt->execute()) {
    $stmt->close();
    if (ob_get_length()) ob_clean();
    echo json_encode(['success' => true, 'msg' => 'Placed student record updated successfully!']);
    exit;
} else {
    $err = $stmt->error;
    $stmt->close();
    if (ob_get_length()) ob_clean();
    echo json_encode(['success' => false, 'msg' => 'Failed to update record: ' . $err]);
    exit;
}