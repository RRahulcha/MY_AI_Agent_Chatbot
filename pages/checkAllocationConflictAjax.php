<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo json_encode(['valid' => false, 'errors' => ['Unauthorized: Please log in.']]);
    exit;
}

include('../include/db_config.php');
require_once('availabilityEngine.php');

$internId            = trim($_POST['internId']            ?? $_GET['internId']            ?? '');
$startDate           = trim($_POST['startDate']           ?? $_GET['startDate']           ?? '');
$endDate             = trim($_POST['endDate']             ?? $_GET['endDate']             ?? '');
$allocStartTime      = trim($_POST['allocStartTime']      ?? $_GET['allocStartTime']      ?? '');
$allocEndTime        = trim($_POST['allocEndTime']        ?? $_GET['allocEndTime']        ?? '');
$excludeAllocationId = intval($_POST['excludeAllocationId'] ?? $_GET['excludeAllocationId'] ?? 0);
if ($excludeAllocationId <= 0) $excludeAllocationId = null;

if (empty($internId) || empty($startDate) || empty($endDate) || empty($allocStartTime) || empty($allocEndTime)) {
    echo json_encode([
        'valid'  => false,
        'errors' => ['Missing required parameter(s) for availability check.']
    ]);
    exit;
}

// Format time HH:MM
if (strlen($allocStartTime) === 5) $allocStartTime .= ':00';
if (strlen($allocEndTime) === 5)   $allocEndTime   .= ':00';

$res = validateAllocationRequest(
    $con,
    $internId,
    $startDate,
    $endDate,
    $allocStartTime,
    $allocEndTime,
    $excludeAllocationId,
    false // includePending = false
);

echo json_encode($res);
