<?php
session_start();

if (!isset($_SESSION['userId'])) {
    exit("Unauthorized access");
}

include('../include/db_config.php');

$leaveId = $_GET['leave_id'] ?? '';

if (!$leaveId) {
    exit("Missing leave id");
}

$result = mysqli_query($con, "
    SELECT document_path 
    FROM leaves 
    WHERE leave_id = '$leaveId'
");

if (!$result || mysqli_num_rows($result) === 0) {
    exit("Document not found in database");
}

$row = mysqli_fetch_assoc($result);

if (empty($row['document_path'])) {
    exit("No document uploaded");
}

$fileName = basename(trim($row['document_path']));
$dir = __DIR__ . '/leavedocs/';
$filePath = $dir . $fileName;

if (!file_exists($filePath)) {

    $pattern = $dir . '*_' . preg_replace('/^\d+_/', '', $fileName);
    $matches = glob($pattern);

    if (!empty($matches)) {
        $filePath = $matches[0];
    } else {
        exit("File does not exist on server: $fileName");
    }
}

/* ---- FIX: fallback if mime_content_type() is missing ---- */
if (function_exists('mime_content_type')) {
    $mimeType = mime_content_type($filePath);
} else {
    $mimeType = "application/pdf";
}

header("Content-Type: $mimeType");
header('Content-Disposition: inline; filename="' . $fileName . '"');
header('Content-Length: ' . filesize($filePath));

readfile($filePath);
exit;
