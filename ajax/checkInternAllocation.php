<?php
/**
 * AJAX endpoint: Check if an intern already has an approved allocation
 * for the given date range.
 * 
 * Params (GET):
 *   internId  - The intern's ID
 *   startDate - Start date (Y-m-d)
 *   endDate   - End date (Y-m-d)
 * 
 * Returns JSON:
 *   { allocated: true/false, allocations: [...] }
 */
header('Content-Type: application/json');

include('../include/db_config.php');

$internId  = trim(mysqli_real_escape_string($con, $_GET['internId']  ?? ''));
$startDate = trim(mysqli_real_escape_string($con, $_GET['startDate'] ?? ''));
$endDate   = trim(mysqli_real_escape_string($con, $_GET['endDate']   ?? ''));

if (empty($internId) || empty($startDate) || empty($endDate)) {
    echo json_encode(['allocated' => false, 'allocations' => [], 'error' => 'Missing parameters']);
    exit;
}

// Check for overlapping approved allocations
$query = "
    SELECT 
        a.projectName,
        a.clientName,
        a.startDate,
        a.endDate,
        COALESCE(TIME_FORMAT(a.allocStartTime, '%H:%i'), '-') AS allocStartTime,
        COALESCE(TIME_FORMAT(a.allocEndTime, '%H:%i'), '-')   AS allocEndTime,
        a.dailyHours
    FROM internAllocations a
    WHERE a.internId = '$internId'
      AND a.startDate <= '$endDate'
      AND a.endDate   >= '$startDate'
    ORDER BY a.startDate ASC
";

$result = mysqli_query($con, $query);

$allocations = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $allocations[] = $row;
    }
}

// Also check for pending requests (to warn about duplicates)
$pendingQuery = "
    SELECT projectName, startDate, endDate
    FROM resourceRequests
    WHERE internId = '$internId'
      AND status = 'Pending'
      AND startDate <= '$endDate'
      AND endDate   >= '$startDate'
";
$pendingResult = mysqli_query($con, $pendingQuery);

$pendingRequests = [];
if ($pendingResult) {
    while ($row = mysqli_fetch_assoc($pendingResult)) {
        $pendingRequests[] = $row;
    }
}

echo json_encode([
    'allocated'       => count($allocations) > 0,
    'allocations'     => $allocations,
    'hasPending'      => count($pendingRequests) > 0,
    'pendingRequests' => $pendingRequests
]);
?>
