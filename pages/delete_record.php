<?php
include('../include/db_config.php');

if (isset($_POST['id'])) {
    $id = intval($_POST['id']);
    $query = "DELETE FROM holidays WHERE id = $id";
    
    if (mysqli_query($con, $query)) {
        echo "Record deleted successfully!";
    } else {
        echo "Error deleting record: " . mysqli_error($con);
    }
} else {
    echo "Invalid request!";
}
?>