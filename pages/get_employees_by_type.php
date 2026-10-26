<?php
session_start();
include('../include/db_config.php');

// Check if admin
if (!isset($_SESSION['userId']) || ($_SESSION['designation'] !== 'admin' && $_SESSION['designation'] !== 'hr')) {
    header('HTTP/1.1 403 Forbidden');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['type'])) {
    $type = mysqli_real_escape_string($con, $_POST['type']);
    
    if ($type == 'online') {
        $query = "SELECT userId, fullName FROM signup 
                  WHERE mode = 'Online' 
                  AND designation != 'admin' 
                  ORDER BY fullName";
    } else {
        $query = "SELECT userId, fullName FROM signup 
                  WHERE office = '$type' 
                  AND designation != 'admin' 
                  ORDER BY fullName";
    }
    
    $result = mysqli_query($con, $query);
    $employees = [];
    
    while ($row = mysqli_fetch_assoc($result)) {
        $employees[] = [
            'userId' => $row['userId'],
            'fullName' => $row['fullName']
        ];
    }
    
    header('Content-Type: application/json');
    echo json_encode($employees);
}
?>