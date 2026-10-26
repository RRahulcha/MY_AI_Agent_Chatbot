<?php
require '../include/db_config.php';

// Set headers for Excel
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=jrTaskReport.xls");

// Retrieve filters from GET request
$intern = $_GET['intern'] ?? '';
$fdate = $_GET['fdate'] ?? '';
$tdate = $_GET['tdate'] ?? '';

// Apply filters
$nameFilter = !empty($intern) ? "taskEntry.userId = '$intern'" : "1=1";
$dateFilter = !empty($fdate) && !empty($tdate) 
    ? "dateTime BETWEEN '$fdate 00:00:00' AND '$tdate 23:59:59'" 
    : "1=1";

// Build SQL query
$query = "SELECT taskEntry.*, signup.fullName 
          FROM taskEntry 
          JOIN signup ON taskEntry.userId = signup.userId 
          WHERE $nameFilter AND $dateFilter";

// Execute query
$result = mysqli_query($con, $query);

// Check for errors
if (!$result) {
    die("Query Error: " . mysqli_error($con));
}

// Output Excel Table Headers
echo "Intern Name\tTask\tExpected Time\tLocation\tStatus\n";

// Populate table rows
while ($row = mysqli_fetch_assoc($result)) {
    $expectedDeadline = date_create($row['expectedDeadline']);
    $formattedDeadline = date_format($expectedDeadline, "h:ia (d M y)");
    echo "{$row['fullName']}\t{$row['task']}\t$formattedDeadline\t{$row['location']}\t{$row['status']}\n";
}

// Exit script
exit;
?>
