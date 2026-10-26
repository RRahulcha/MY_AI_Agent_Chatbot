<?php
// Assuming you have a database connection established as $con
include('../include/db_config.php');

$employees = array();

// Add a placeholder option
$employees[] = "<option value='' selected disabled>-- Select Employee --</option>";

$q = mysqli_query($con, "SELECT fullName, userId FROM signup WHERE designation != 'admin'");
while ($r = mysqli_fetch_assoc($q)) {
    $employees[] = "<option value='{$r['userId']}'>{$r['fullName']}</option>";
}

echo implode("", $employees);  // Output the options as a string
?>
