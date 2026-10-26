<?php
session_start();
include('../include/db_config.php');

if (!isset($_SESSION['userId'])) {
    echo json_encode([]);
    exit;
}

$designation = isset($_GET['designation']) ? $conn->real_escape_string($_GET['designation']) : 'All';

$sql = "SELECT DISTINCT batch_number FROM signup WHERE regstatus='Approved' AND status='Active' AND batch_number IS NOT NULL AND batch_number != ''";

if ($designation !== 'All') {
    $sql .= " AND designation = '$designation'";
}

$sql .= " ORDER BY batch_number";

$result = $conn->query($sql);
$batches = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $batches[] = $row['batch_number'];
    }
}

echo json_encode($batches);
?>
