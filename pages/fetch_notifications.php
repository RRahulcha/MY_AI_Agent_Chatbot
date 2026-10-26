<?php
ini_set('display_errors', 0);
error_reporting(0);
include('../include/db_config.php'); 
session_start();




$userId = $_SESSION['userId']; 

$query = "SELECT * FROM notifications WHERE userId = '$userId' ORDER BY created_at DESC LIMIT 5";
$result = mysqli_query($con, $query);

$notifications = [];
while ($row = mysqli_fetch_assoc($result)) {
    $notifications[] = $row;
}

echo json_encode($notifications);
?>
