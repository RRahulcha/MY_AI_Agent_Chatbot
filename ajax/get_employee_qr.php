<?php
/**
 * Employee Identity & Attendance QR Code Generator
 * Settribe AMS
 * Generates official QR code containing complete employee profile details for everyone/all posts.
 * Supports image/svg+xml and application/json formats.
 */
session_start();
session_write_close();
require_once __DIR__ . '/../include/db_config.php';
require_once __DIR__ . '/../vendors/TCPDF/tcpdf_barcodes_2d.php';

$userId = $_GET['user_id'] ?? $_POST['user_id'] ?? ($_SESSION['userId'] ?? '');
$format = strtolower($_GET['format'] ?? 'svg');

if (empty($userId)) {
    http_response_code(400);
    if ($format === 'json') {
        echo json_encode(['success' => false, 'message' => 'User ID is required']);
    } else {
        header('Content-Type: image/svg+xml');
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200"><text x="10" y="100" fill="red">User ID Required</text></svg>';
    }
    exit;
}

if (!isset($con) || !$con) {
    http_response_code(500);
    exit('Database unavailable');
}

$stmt = $con->prepare("
    SELECT userId, emp_id, internId, fullName, username, email, mobileNo, 
           designation, department, branch, office, mode, type, userRole, 
           employment_type, status, registration_date, start_date, end_date, 
           expectedPunchIn, expectedPunchOut, totalWorkingHours, profilePhoto
    FROM signup 
    WHERE userId = ? 
       OR emp_id = ? 
       OR internId = ? 
       OR username = ? 
       OR email = ? 
    LIMIT 1
");

$stmt->bind_param("sssss", $userId, $userId, $userId, $userId, $userId);
$stmt->execute();
$res = $stmt->get_result();

if (!$res || $res->num_rows === 0) {
    $stmt->close();
    http_response_code(404);
    if ($format === 'json') {
        echo json_encode(['success' => false, 'message' => 'Employee not found']);
    } else {
        header('Content-Type: image/svg+xml');
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200"><text x="10" y="100" fill="red">Employee Not Found</text></svg>';
    }
    exit;
}

$u = $res->fetch_assoc();
$stmt->close();

$workMode = $u['type'] ?: ($u['mode'] ?: 'Offline');
$employeeId = $u['emp_id'] ?: ($u['internId'] ?: $u['userId']);

// Compact permanent employee data payload for QR code (high contrast, bold dots)
$qrPayload = [
    'system' => 'SETTribe AMS',
    'userId' => strval($u['userId']),
    'emp_id' => strval($employeeId),
    'name'   => strval($u['fullName']),
    'role'   => strval($u['userRole'] ?: 'employee'),
    'mode'   => strval($workMode),
    'dept'   => strval($u['department'] ?: 'General'),
    'token'  => 'SETTRIBE-' . strtoupper($u['userId']) . '-PERMANENT'
];

$qrString = json_encode($qrPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// Generate 2D QR Code using TCPDF2DBarcode (QRCODE,L has large dots, highly readable by webcams)
$barcode = new TCPDF2DBarcode($qrString, 'QRCODE,L');
$svgCode = $barcode->getBarcodeSVGcode(4, 4, '#0f172a');

// Add viewBox & preserveAspectRatio and set width/height to 100% so SVG scales perfectly inside any <img> tag or container
$svgCode = preg_replace('/<svg width="([0-9.]+)" height="([0-9.]+)"/', '<svg viewBox="0 0 $1 $2" width="100%" height="100%" preserveAspectRatio="xMidYMid meet"', $svgCode);

if ($format === 'json') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    echo json_encode([
        'success'    => true,
        'user_id'    => $u['userId'],
        'qr_data'    => $qrPayload,
        'qr_string'  => $qrString,
        'svg'        => $svgCode
    ]);
    exit;
}

// Default: Direct SVG Image Output
if (ob_get_length()) ob_clean();
header('Content-Type: image/svg+xml');
if (isset($_GET['download']) && ($_GET['download'] === '1' || $_GET['download'] === 'true')) {
    $cleanId = preg_replace('/[^a-zA-Z0-9_-]/', '', $employeeId);
    header('Content-Disposition: attachment; filename="SETTribe_QR_' . $cleanId . '.svg"');
}
header('Cache-Control: public, max-age=86400');
echo $svgCode;

