<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database connection
require_once __DIR__ . '/include/db_config.php';

// ✅ UPDATED QUERY: Include Email column
$query = "SELECT ID, Name, Email FROM ProjectMaster WHERE Name IS NOT NULL AND Name != '' ORDER BY Name ASC";
$result = mysqli_query($con, $query);

$projects = [];

if ($result) {
    if (mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $projects[] = [
                'id' => $row['ID'],
                'name' => $row['Name'],
                'email' => $row['Email']  // Added email field
            ];
        }
    }
} else {
    $projects = ['error' => 'Query failed: ' . mysqli_error($con)];
}

// Send JSON response
header('Content-Type: application/json');
echo json_encode($projects);

mysqli_close($con);
?>