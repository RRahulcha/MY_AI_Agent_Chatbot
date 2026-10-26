<?php
session_start();
include('../include/db_config.php');

if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

if (isset($_POST['userId']) && !empty($_POST['userId'])) {
    $uid = mysqli_real_escape_string($con, $_POST['userId']);

    $query = mysqli_query($con, "
        SELECT fullName, start_date, end_date, designation, employment_type
        FROM signup
        WHERE userId = '$uid' 
        LIMIT 1 
    ");

    if ($query && mysqli_num_rows($query) > 0) {
        $row = mysqli_fetch_assoc($query);
        echo json_encode([ 
            'success'         => true,
            'fullName'        => $row['fullName'],
            'start_date'      => $row['start_date'],
            'end_date'        => $row['end_date'],
            'designation'     => $row['designation'],
            'employment_type' => $row['employment_type']
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'User not found']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'No userId provided']);
}
?>
