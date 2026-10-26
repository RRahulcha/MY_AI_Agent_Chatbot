<?php
session_start();

/* ========== AUTH CHECK ========== */
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href='login.php';</script>";
    exit;
}

include('../include/db_config.php');
require_once('../include/sweetAlert.php');

/* ========== METHOD CHECK ========== */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo "<script>window.location.href='jrStatusChange.php';</script>";
    exit;
}

/* ========== INPUT VALIDATION ========== */
if (
    !isset($_POST['internId']) ||
    !isset($_POST['status'])
) {
    setSession(false, "Required data missing!");
    echo "<script>window.location.href='jrStatusChange.php';</script>";
    exit;
}

$userId  = trim($_POST['internId']);
$status  = trim($_POST['status']); // Active / Inactive

/* ========== ALLOW ONLY VALID VALUES ========== */
if ($status !== 'Active' && $status !== 'Inactive') {
    setSession(false, "Invalid status value!");
    echo "<script>window.location.href='jrStatusChange.php';</script>";
    exit;
}

/* ========== UPDATE signup.status ========== */
$stmt = $con->prepare(
    "UPDATE signup SET status = ? WHERE userId = ?"
);


if (!$stmt) {
    setSession(false, "Database prepare failed!");
    echo "<script>window.location.href='jrStatusChange.php';</script>";
    exit;
}

$stmt->bind_param("ss", $status, $userId);

if ($stmt->execute()) {
    setSession(true, "Intern status updated to $status");
} else {
    setSession(false, "Failed to update status!");
}

$stmt->close();

/* ========== REDIRECT ========== */
echo "<script>window.location.href='jrStatusChange.php';</script>";
exit;
