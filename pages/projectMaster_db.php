<?php
session_start();
if (!isset($_SESSION['userId']) && empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

include('../include/db_config.php');
$dateTime = date('Y-m-d H:i:s');

if (isset($_POST['create'])) {
    require_once('../include/sweetAlert.php');

    $projectName      = addslashes($_POST['projectName']);
    $client           = addslashes($_POST['client']);
    $status           = $_POST['status'];
    $deadLine         = $_POST['deadLine'];
    $priority         = $_POST['priority'];
    $userId           = $_SESSION['userId'];
    $dateTime         = date('Y-m-d H:i:s');

    $budget_hours     = !empty($_POST['budget_hours']) ? floatval($_POST['budget_hours']) : 0.00;
    $hourly_rate      = !empty($_POST['hourly_rate']) ? floatval($_POST['hourly_rate']) : 0.00;

    $hostingType      = $_POST['hostingType'] ?? '';

    $git_access_link  = addslashes($_POST['git_access_link'] ?? '');
    $git_repo_link    = addslashes($_POST['git_repo_link']   ?? '');

    $Link             = addslashes($_POST['Link'] ?? '');
    $Host             = addslashes($_POST['Host'] ?? '');
    $User             = addslashes($_POST['User'] ?? '');
    $Pass             = addslashes($_POST['Pass'] ?? '');

    $query = mysqli_query($con, "
        INSERT INTO projectMaster
            (`projectName`, `client`, `hostingType`,
             `git_access_link`, `git_repo_link`,
             `Link`, `Host`, `User`, `Pass`,
             `deadLine`, `priority`, `budget_hours`, `hourly_rate`,
             `modificationDate`, `status`, `modifiedUserId`)
        VALUES
            ('$projectName', '$client', '$hostingType',
             '$git_access_link', '$git_repo_link',
             '$Link', '$Host', '$User', '$Pass',
             '$deadLine', '$priority', '$budget_hours', '$hourly_rate',
             '$dateTime', '$status', '$userId')
    ");

    if ($query) {
        $status = true;
        $msg = "Project is created successfully...";
    } else {
        $status = false;
        $msg = "Sorry, project can't be created: " . mysqli_error($con);
    }

    setSession($status, $msg);
    echo "<script>window.location.href = 'projectMaster.php';</script>";
    exit;
}

if (isset($_POST['update'])) {
    require_once('../include/sweetAlert.php');

    $projectId   = $_POST['update'];
    $projectName = addslashes($_POST['projectName']);
    $client      = addslashes($_POST['client']);
    $status      = $_POST['status'];
    $deadLine    = $_POST['deadLine'];
    $priority    = $_POST['priority'];
    $userId      = $_SESSION['userId'];
    $dateTime    = date('Y-m-d H:i:s');

    $budget_hours = !empty($_POST['budget_hours']) ? floatval($_POST['budget_hours']) : 0.00;
    $hourly_rate  = !empty($_POST['hourly_rate']) ? floatval($_POST['hourly_rate']) : 0.00;

    $hostingType     = $_POST['hostingType'] ?? '';
    $git_access_link = addslashes($_POST['git_access_link'] ?? '');
    $git_repo_link   = addslashes($_POST['git_repo_link']   ?? '');

    $Link = addslashes($_POST['Link'] ?? '');
    $Host = addslashes($_POST['Host'] ?? '');
    $User = addslashes($_POST['User'] ?? '');
    $Pass = addslashes($_POST['Pass'] ?? '');

    $extraFields = '';
    if ($hostingType === 'FTP') {
        $git_access_link = '';
        $git_repo_link = '';
        $extraFields = ", `git_access_link` = '', `git_repo_link` = ''";
    } elseif ($hostingType === 'Git') {
        $Link = '';
        $Host = '';
        $User = '';
        $Pass = '';
        $extraFields = ", `Link` = '', `Host` = '', `User` = '', `Pass` = ''";
    }

    $query = mysqli_query($con, "
        UPDATE projectMaster SET
            `projectName`       = '$projectName',
            `client`            = '$client',
            `hostingType`       = '$hostingType',
            `git_access_link`   = '$git_access_link',
            `git_repo_link`     = '$git_repo_link',
            `Link`              = '$Link',
            `Host`              = '$Host',
            `User`              = '$User',
            `Pass`              = '$Pass'
            $extraFields,
            `deadLine`          = '$deadLine',
            `priority`          = '$priority',
            `budget_hours`      = '$budget_hours',
            `hourly_rate`       = '$hourly_rate',
            `modificationDate`  = '$dateTime',
            `status`            = '$status',
            `modifiedUserId`    = '$userId'
        WHERE projectId = '$projectId'
    ");

    if ($query) {
        $status = true;
        $msg = "Project is updated successfully...";
    } else {
        $status = false;
        $msg = "Sorry, project can't be updated: " . mysqli_error($con);
    }
    setSession($status, $msg);
    echo "<script>window.location.href = 'projectMaster.php';</script>";
    exit;
}

if (isset($_GET['delete'])) {
    require_once('../include/sweetAlert.php');

    $projectId = $_GET['delete'];
    $query = mysqli_query($con, "DELETE FROM projectMaster WHERE projectId = '$projectId'");
    if ($query) {
        $status = true;
        $msg = "Project is deleted successfully...";
    } else {
        $status = false;
        $msg = "Sorry, project can't be deleted: " . mysqli_error($con);
    }
    setSession($status, $msg);
    echo "<script>window.location.href = 'projectMaster.php';</script>";
    exit;
}
?>