<?php
session_start();
if (!isset($_SESSION['userId']) && empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

include('../include/db_config.php');
require_once('../include/sweetAlert.php');

$userRole = strtolower(trim($_SESSION['userRole'] ?? ''));
if (!in_array($userRole, ['resourceallocator', 'resourcemanager', 'resource_manager'])) {
    echo "<script>alert('Unauthorized: Project allocation management is restricted to Resource Managers.'); window.location.href = 'projectAllocation.php';</script>";
    exit;
}

$dateTime = date('Y-m-d H:i:s');

/* ===================== ALLOCATE ===================== */
if (isset($_POST['allocate'])) {

    $project = addslashes($_POST['project']);
    $userId  = $_SESSION['userId'];
    $inserted = false;

    foreach ($_POST['employee'] as $employee) {

        // ✅ CHECK ACTIVE USER
        $check = mysqli_query($con, "
            SELECT userId FROM signup 
            WHERE userId = '$employee' 
            AND status = 'Active'
        ");

        if (mysqli_num_rows($check) == 0) {
            continue; // ❌ skip inactive
        }

        mysqli_query($con, "
            INSERT INTO allocatedProject 
            (projectId, userId, modifiedUserId, modifiedDate)
            VALUES ('$project', '$employee', '$userId', '$dateTime')
        ");

        $inserted = true;
    }

    if ($inserted) {
        setSession(true, "Project is allocated successfully...");
    } else {
        setSession(false, "No active members selected.");
    }

    echo "<script>window.location.href = 'projectAllocation.php';</script>";
    exit;
}

/* ===================== UPDATE ===================== */
if (isset($_POST['update'])) {

    $projectId = $_POST['update'];
    $project   = addslashes($_POST['project']);
    $userId    = $_SESSION['userId'];
    $inserted  = false;

    // Remove old allocation
    mysqli_query($con, "DELETE FROM allocatedProject WHERE projectId = '$projectId'");

    foreach ($_POST['employee'] as $employee) {

        // ✅ CHECK ACTIVE USER
        $check = mysqli_query($con, "
            SELECT userId FROM signup 
            WHERE userId = '$employee' 
            AND status = 'Active'
        ");

        if (mysqli_num_rows($check) == 0) {
            continue;
        }

        mysqli_query($con, "
            INSERT INTO allocatedProject 
            (projectId, userId, modifiedUserId, modifiedDate)
            VALUES ('$project', '$employee', '$userId', '$dateTime')
        ");

        $inserted = true;
    }

    if ($inserted) {
        setSession(true, "Project Allocation is updated successfully...");
    } else {
        setSession(false, "No active members selected.");
    }

    echo "<script>window.location.href = 'projectAllocation.php';</script>";
    exit;
}

/* ===================== DELETE ===================== */
if (isset($_GET['delete'])) {

    $projectId = $_GET['delete'];

    if (mysqli_query($con, "DELETE FROM allocatedProject WHERE projectId = '$projectId'")) {
        setSession(true, "Project Allocation is deleted successfully...");
    } else {
        setSession(false, "Sorry, project allocation can't be deleted.");
    }

    echo "<script>window.location.href = 'projectAllocation.php';</script>";
    exit;
}
?>
