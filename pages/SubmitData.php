<?php
header('Content-Type: application/json');

// Read the input JSON data
$data = json_decode(file_get_contents('php://input'), true);

$allocatedProject = $data['allocatedProject'];
$activeInterns = $data['activeInterns'];
$infoText = $data['infoText'];
$sortedInterns = $data['sortedInterns'];

// Database connection
require_once __DIR__ . '/../include/db_config.php';

// Insert the data into the database
$stmt = $conn->prepare("INSERT INTO dashboard_data (allocated_project, active_interns, info_text, sorted_interns) VALUES (?, ?, ?, ?)");
$stmt->bind_param("ssss", $allocatedProject, $activeInterns, $infoText, $sortedInterns);

if ($stmt->execute()) {
    echo json_encode(['message' => 'Data submitted successfully!']);
} else {
    echo json_encode(['message' => 'Error submitting data: ' . $stmt->error]);
}

$stmt->close();
$conn->close();
?>
