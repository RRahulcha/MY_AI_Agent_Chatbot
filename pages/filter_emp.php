<?php
include('../include/db_config.php');

$office = isset($_POST['office']) ? mysqli_real_escape_string($con, $_POST['office']) : '';
$mode   = isset($_POST['mode']) ? mysqli_real_escape_string($con, $_POST['mode']) : '';

$query = "SELECT userId, fullName 
          FROM signup 
          WHERE (
                    TRIM(LOWER(status)) = 'active'
                    OR status = '1'
                    OR status = 1
                 )";   // ✅ Only active members

if (!empty($office)) {
    $query .= " AND office = '$office'";
}

if (!empty($mode) && $mode != 'Reset') {
    $query .= " AND type = '$mode'";
}

$result = mysqli_query($con, $query);

$employees = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $employees[] = $row;
    }
} else {
    $error = mysqli_error($con);
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['error' => $error, 'query' => $query]);
    exit;
}

header('Content-Type: application/json');
echo json_encode(['query' => $query, 'employees' => $employees]);
