<?php
// ajax/save_announcement.php
// Saves a new announcement + recipient mapping
ob_start();
include '../include/db_config.php';
ob_end_clean();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'msg' => 'Invalid request method']);
    exit;
}

if (empty($_SESSION['userId'])) {
    echo json_encode(['success' => false, 'msg' => 'Not authenticated. Please log in again.']);
    exit;
}

$title         = trim($_POST['title'] ?? '');
$description   = trim($_POST['description'] ?? '');
$priority      = $_POST['priority'] ?? 'normal';
$show_on_board = isset($_POST['show_on_board']) ? 1 : 0;
$format_date = function($d) {
    if (empty($d)) return null;
    $d = trim($d);
    if (preg_match('/^\d{4}$/', $d)) {
        return $d . '-12-31';
    }
    $ts = strtotime($d);
    return $ts ? date('Y-m-d', $ts) : null;
};

$format_datetime = function($d) {
    if (empty($d)) return null;
    $d = trim($d);
    $ts = strtotime($d);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
};

$expiry_date   = $format_date($_POST['expiry_date'] ?? null);
$scheduled     = $format_datetime($_POST['scheduled_date'] ?? null);
$createdBy     = $_SESSION['userId'];

// Handle recipient IDs from employees[] OR participants[] OR send_to_all
$employees = $_POST['employees'] ?? ($_POST['participants'] ?? []);
if (!is_array($employees)) {
    $employees = array_filter(explode(',', (string)$employees));
}

// If "Send to Entire Company" is checked or "ALL" selected, fetch all active users
$sendToAll = !empty($_POST['send_to_all']) || in_array('ALL', $employees) || in_array('__ALL__', $employees);
if ($sendToAll) {
    $allRes = $conn->query("SELECT userId FROM signup WHERE regstatus='Approved' AND status='Active' AND userId IS NOT NULL AND userId != ''");
    $employees = [];
    if ($allRes) {
        while ($r = $allRes->fetch_assoc()) {
            $employees[] = $r['userId'];
        }
    }
}

if (empty($title)) {
    echo json_encode(['success' => false, 'msg' => 'Announcement Title is required.']);
    exit;
}
if (empty($employees)) {
    echo json_encode(['success' => false, 'msg' => 'Please select at least one recipient or check "Send to Entire Company".']);
    exit;
}

// Validate priority
$validPriorities = ['normal', 'important', 'urgent'];
if (!in_array($priority, $validPriorities)) $priority = 'normal';
$is_urgent = ($priority === 'urgent') ? 1 : 0;

// Insert into announcement_master
$stmt = $conn->prepare("
    INSERT INTO announcement_master
        (title, description, created_by, created_date, status, is_urgent, priority, show_on_board, expiry_date, scheduled_date)
    VALUES (?, ?, ?, NOW(), 1, ?, ?, ?, ?, ?)
");
if (!$stmt) {
    echo json_encode(['success' => false, 'msg' => 'Database prepare failed: ' . $conn->error]);
    exit;
}

$stmt->bind_param('sssisiss', $title, $description, $createdBy, $is_urgent, $priority, $show_on_board, $expiry_date, $scheduled);

if (!$stmt->execute()) {
    echo json_encode(['success' => false, 'msg' => 'Failed to save announcement: ' . $stmt->error]);
    exit;
}
$announcementId = $stmt->insert_id;
$stmt->close();

// Insert recipient mapping into announcement_mapping
$stmtMap = $conn->prepare("INSERT IGNORE INTO announcement_mapping (announcement_id, employee_id, is_read) VALUES (?, ?, 0)");
$inserted = 0;
if ($stmtMap) {
    foreach (array_unique($employees) as $empId) {
        $empId = trim($empId);
        if (empty($empId)) continue;
        $stmtMap->bind_param('is', $announcementId, $empId);
        if ($stmtMap->execute()) $inserted++;
    }
    $stmtMap->close();
}

echo json_encode([
    'success' => true,
    'msg'     => "Announcement sent to {$inserted} recipient(s) successfully!"
]);
