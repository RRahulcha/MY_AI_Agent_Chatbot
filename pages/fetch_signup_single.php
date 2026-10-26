<?php
include('../include/db_config.php');

$id = $_POST['id'];

$sql = mysqli_query($con, "SELECT fullName, employment_type, emp_id, internId, designation FROM signup WHERE srNo='$id'");
$row = mysqli_fetch_assoc($sql);

$empType = $row['employment_type'];
$desig   = strtolower($row['designation'] ?? '');

if ($empType == 'Employee') {

    if (empty($row['emp_id'])) {
        $newId = "EMP" . str_pad(rand(1, 9999), 4, "0", STR_PAD_LEFT);
        mysqli_query($con, "UPDATE signup SET emp_id = '$newId' WHERE srNo = '$id'");
    } else {
        $newId = $row['emp_id'];
    }

    $row['empAutoId'] = $newId;

} else if ($empType == 'Intern') {

    if (empty($row['internId'])) {
        $newId = "INT" . str_pad(rand(1, 9999), 4, "0", STR_PAD_LEFT);
        mysqli_query($con, "UPDATE signup SET internId = '$newId' WHERE srNo = '$id'");
    } else {
        $newId = $row['internId'];
    }

    $row['empAutoId'] = $newId;

} else if ($empType == 'Staff' || $desig == 'staff') {

    if (empty($row['emp_id'])) {
        $newId = "STF" . str_pad(rand(1, 9999), 4, "0", STR_PAD_LEFT);
        mysqli_query($con, "UPDATE signup SET emp_id = '$newId' WHERE srNo = '$id'");
    } else {
        $newId = $row['emp_id'];
    }

    $row['empAutoId'] = $newId;
}

echo json_encode($row);
?>
