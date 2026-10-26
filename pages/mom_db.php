<?php
session_start();
include('../include/db_config.php');
require_once('../include/sweetAlert.php');

if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}

// ======================================================
// 1️⃣  AUTO GENERATE mom_id (MOM NUMBER)
// ======================================================
$lastMom = mysqli_query($con, "SELECT mom_id FROM moms ORDER BY id DESC LIMIT 1");

if ($lastMom && mysqli_num_rows($lastMom) > 0) {
    $row = mysqli_fetch_assoc($lastMom);

    // Extract number from MOM-001
    $lastNumber = intval(substr($row['mom_id'], 4));

    // Increment it
    $newNumber = $lastNumber + 1;
} else {
    // First record
    $newNumber = 1;
}

// Format MOM number (0001, 0002…)
$mom_id = "MOM-" . str_pad($newNumber, 3, '0', STR_PAD_LEFT);

// ======================================================
// 2️⃣  EXISTING CODE (NO REMOVALS)
// ======================================================

$created_date = date("Y-m-d h:i a");
$Meeting_Called_By = mysqli_real_escape_string($con, $_POST['meeting_called_by']);
$Date = mysqli_real_escape_string($con, $_POST['date']);
$Time = mysqli_real_escape_string($con, $_POST['time']);
$projectId = mysqli_real_escape_string($con, $_POST['projectId']);
$Meeting_Type = mysqli_real_escape_string($con, $_POST['meeting_type']);
$Mode = mysqli_real_escape_string($con, $_POST['mode']);
$Agenda = mysqli_real_escape_string($con, $_POST['agenda']);
$userId = $_SESSION['userId'];

// Debug logs
error_log("Raw Attendies: " . print_r($_POST['attendies'], true));
error_log("Raw Apologies: " . print_r($_POST['apologies'], true));

// Multiple select conversion
$Attendies = isset($_POST['attendies']) && is_array($_POST['attendies']) ? implode(',', $_POST['attendies']) : '';
$Apologies = isset($_POST['apologies']) && is_array($_POST['apologies']) ? implode(',', $_POST['apologies']) : '';

error_log("Formatted Attendies: " . $Attendies);
error_log("Formatted Apologies: " . $Apologies);

// Key points handling
$key_pointed = "";
if (!empty($_POST['key_points']) && is_array($_POST['key_points'])) {
    $key_pointed = implode("$#POINT#$", $_POST['key_points']);
}

// ======================================================
// 3️⃣  INSERT WITH mom_id INCLUDED
// ======================================================

$updated_date = date("Y-m-d h:i a");

$sql = "INSERT INTO moms (
            mom_id, created_date, meeting_Called_By, date, time,
            projectId, meeting_Type, mode, agenda, attendies,
            apologies, key_points, userId, updated_date
        ) 
        VALUES (
            '$mom_id', '$created_date', '$Meeting_Called_By', '$Date', '$Time',
            '$projectId', '$Meeting_Type', '$Mode', '$Agenda', '$Attendies',
            '$Apologies', '$key_pointed', '$userId', '$updated_date'
        )";


error_log("SQL Query: " . $sql);

// DB insertion
$query = mysqli_query($con, $sql) or die(mysqli_error($con));

if ($query) {
    $status = true;
    $msg = "MOM created successfully!";
} else {
    $status = false;
    $msg = "Sorry, MOM can't be created. Please try again.";
}

setSession($status, $msg);
echo "<script>window.location.href = 'momEntry.php';</script>";
exit;

?>
