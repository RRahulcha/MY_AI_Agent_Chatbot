<?php
include('../include/db_config.php');

// Get filter parameters
$fdate = isset($_POST['fdate']) ? mysqli_real_escape_string($con, $_POST['fdate']) : '';
$tdate = isset($_POST['tdate']) ? mysqli_real_escape_string($con, $_POST['tdate']) : '';
$office = isset($_POST['office']) ? mysqli_real_escape_string($con, $_POST['office']) : '';
$employmentType = isset($_POST['employmentType']) ? mysqli_real_escape_string($con, $_POST['employmentType']) : '';
$intern = isset($_POST['intern']) ? mysqli_real_escape_string($con, $_POST['intern']) : '';
$status = isset($_POST['status']) ? mysqli_real_escape_string($con, $_POST['status']) : '';

// Build the base query
$query = "SELECT 
            s.fullName, 
            s.type,
            a.date,
            a.punchInTime,
            a.punchOutTime,
            a.manHours,
            CASE 
                WHEN a.punchInTime IS NOT NULL AND a.punchOutTime IS NOT NULL THEN 'Present'
                WHEN l.leave_day IS NOT NULL THEN 'Leave'
                WHEN DAYOFWEEK(a.date) = 1 THEN 'Weekend'
                WHEN a.date < CURDATE() THEN 'Absent'
                ELSE 'Future'
            END AS status
          FROM signup s
          LEFT JOIN attendance a ON s.userId = a.userId
          LEFT JOIN leaves l ON s.userId = l.userId AND a.date = l.leave_day
          WHERE 1=1";

// Add date filter
if (!empty($fdate) && !empty($tdate)) {
    $query .= " AND a.date BETWEEN '$fdate' AND '$tdate'";
}

// Add office filter
if (!empty($office)) {
    $query .= " AND s.office = '$office'";
}

// Add employment type filter
if (!empty($employmentType)) {
    $query .= " AND s.type = '$employmentType'";
}

// Add intern filter
if (!empty($intern)) {
    $query .= " AND s.userId = '$intern'";
}

// Add status filter
if (!empty($status)) {
    if ($status == 'Present') {
        $query .= " AND a.punchInTime IS NOT NULL AND a.punchOutTime IS NOT NULL";
    } elseif ($status == 'Absent') {
        $query .= " AND a.date < CURDATE() AND a.punchInTime IS NULL AND a.punchOutTime IS NULL";
    } elseif ($status == 'Leave') {
        $query .= " AND l.leave_day IS NOT NULL";
    }
}

$query .= " ORDER BY s.fullName, a.date";

$result = mysqli_query($con, $query) or die("Error in query: " . mysqli_error($con));

$attendanceData = array();
while ($row = mysqli_fetch_assoc($result)) {
    $attendanceData[] = $row;
}

header('Content-Type: application/json');
echo json_encode($attendanceData);
?>