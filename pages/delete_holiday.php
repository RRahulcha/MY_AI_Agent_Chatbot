<?php
session_start();
include('../include/db_config.php');

if ($_SESSION['userRole'] !== 'admin') {
    header('location:login.php');
    exit;
}

$id = $_GET['id'];
mysqli_query($con, "DELETE FROM holidays WHERE id='$id'");

$_SESSION['popup'] = [
    'type' => 'success',
    'message' => 'Holiday deleted successfully'
];

header('location:view_holidays.php');
