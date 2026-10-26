<?php
// ajax/save_placed_student.php
ob_start();
include '../include/db_config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'msg' => 'Invalid request method']);
    exit;
}

if (empty($_SESSION['userId'])) {
    echo json_encode(['success' => false, 'msg' => 'Not authenticated']);
    exit;
}

$student_user_id = trim($_POST['student_user_id'] ?? '');
$student_name    = trim($_POST['student_name'] ?? '');
$branch          = trim($_POST['branch'] ?? '');
$company_name    = trim($_POST['company_name'] ?? '');
$role_offered    = trim($_POST['role_offered'] ?? '');
$package_ctc     = trim($_POST['package_ctc'] ?? '');
$placement_date  = !empty($_POST['placement_date']) ? $_POST['placement_date'] : date('Y-m-d');
$remarks         = trim($_POST['remarks'] ?? '');
$added_by        = $_SESSION['userId'];

// Handle missing schema-required fields cleanly
$placement_group_id = trim($_POST['placement_group_id'] ?? '1'); // Default fallback till schema flow is verified
$content            = trim($_POST['content'] ?? ''); 

if (empty($student_name) || empty($company_name)) {
    echo json_encode(['success' => false, 'msg' => 'Student Name and Company Name are required.']);
    exit;
}

// Handle Photo Upload
$photoPath = '';
if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
    $uploadDir = '../pages/uploads/placed_students/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    if (!in_array($ext, $allowed)) {
        echo json_encode(['success' => false, 'msg' => 'Invalid image format. Allowed: JPG, PNG, GIF, WebP']);
        exit;
    }

    $newFile = uniqid('student_') . '_' . time() . '.' . $ext;
    $target  = $uploadDir . $newFile;

    if (move_uploaded_file($_FILES['photo']['tmp_name'], $target)) {
        $photoPath = 'uploads/placed_students/' . $newFile;
    }
}

// Corrected Prepared Statement with 12 Placeholders
$stmt = $conn->prepare("
    INSERT INTO placements 
        (student_user_id, student_name, branch, company_name, role_offered,
         package_ctc, placement_date, photo_path, remarks, added_by,
         placement_group_id, content, status) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
");

// Exact 12 string parameters match: 'ssssssssssss' (12 's')
$stmt->bind_param(
    'ssssssssssss', 
    $student_user_id, 
    $student_name, 
    $branch, 
    $company_name, 
    $role_offered, 
    $package_ctc, 
    $placement_date, 
    $photoPath, 
    $remarks, 
    $added_by,
    $placement_group_id,
    $content
);

if ($stmt->execute()) {
    $stmt->close();
    
    // Clear buffer to drop any trailing whitespace/notices
    if (ob_get_length()) ob_clean();
    
    echo json_encode(['success' => true, 'msg' => 'Placed student record added successfully!']);
    exit; // Ensure script stops instantly
} else {
    $err = $stmt->error;
    $stmt->close();
    
    if (ob_get_length()) ob_clean();
    
    echo json_encode(['success' => false, 'msg' => 'Failed to save record: ' . $err]);
    exit; // Ensure script stops instantly
}