<?php
include('../include/db_config.php');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $id = intval($_POST['id']);
    $delete = mysqli_query($con, "DELETE FROM TaskMaster WHERE taskId = $id");
    if ($delete) {
        echo json_encode(["success" => true, "message" => "Task deleted successfully!"]);
    } else {
        echo json_encode(["success" => false, "message" => "Failed to delete task."]);
    }
} else {
    echo json_encode(["success" => false, "message" => "Invalid request."]);
}
?>
