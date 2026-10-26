<?php
include('../include/db_config.php');

if (isset($_GET['projectId'])) {
    $projectId = intval($_GET['projectId']);

    // Query to fetch progress, remark, and checklist for the selected projectId
    $query = "SELECT progress, remark, cid 
              FROM projectProgress 
              WHERE projectId = ? 
              AND modificationDate = (
                    SELECT MAX(modificationDate) 
                    FROM projectProgress 
                    WHERE projectId = ?
                )";
    
    if ($stmt = $con->prepare($query)) {
        $stmt->bind_param("ii", $projectId, $projectId);
        $stmt->execute();
        $result = $stmt->get_result();

        // Check if data exists for the project
        if ($result->num_rows > 0) {
            $data = $result->fetch_assoc();
            
            // Decode the checklist JSON if available & normalize to array
            $decoded = !empty($data['cid']) ? json_decode($data['cid'], true) : [];
            if (is_string($decoded)) {
                $checklist = array_map('trim', explode(',', $decoded));
            } elseif (is_array($decoded)) {
                $checklist = array_values($decoded);
            } else {
                $checklist = [];
            }
            
            // Send the response back as JSON
            echo json_encode([
                'success' => true,
                'progress' => floatval($data['progress']),
                'remark' => $data['remark'],
                'checklist' => $checklist
            ]);
        } else {
            // Send an empty response if no data is found
            echo json_encode(['success' => false, 'message' => 'No data found']);
        }
        $stmt->close();
    } else {
        // Error handling if query fails
        echo json_encode(['success' => false, 'message' => 'Database query failed']);
    }
} else {
    // If no projectId is provided in the GET request
    echo json_encode(['success' => false, 'message' => 'Project ID is missing']);
}
?>
