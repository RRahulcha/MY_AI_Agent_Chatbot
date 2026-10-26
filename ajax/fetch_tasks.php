<?php
include('../include/db_config.php');

if (isset($_POST['category']) && !empty($_POST['category'])) {
    $category = trim($_POST['category']);

    $sql = "SELECT task FROM TaskMaster WHERE taskType = ? ORDER BY task ASC";
    $stmt = mysqli_prepare($con, $sql);
    mysqli_stmt_bind_param($stmt, "s", $category);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if (mysqli_num_rows($result) > 0) {
        echo "<option value='' disabled selected>-- Select Task --</option>";
        while ($row = mysqli_fetch_assoc($result)) {
            echo "<option value='" . htmlspecialchars($row['task']) . "'>" . htmlspecialchars($row['task']) . "</option>";
        }
    } else {
        echo "<option value=''>No tasks found for $category</option>";
    }

    mysqli_stmt_close($stmt);
} else {
    echo "<option value=''>No category selected</option>";
}
?>
