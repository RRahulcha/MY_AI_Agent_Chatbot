<?php
include('../include/db_config.php');

$adm = $_POST['adm'] ?? '';
$avs = $_POST['avs'] ?? '';

// Build the filter conditions properly
$conditions = [];

if (!empty($adm)) {
    $conditions[] = "office='$adm'";
}

if (!empty($avs) && $avs !== 'Reset') {
    $conditions[] = "type='$avs'";
}

// Only create the SQL condition if there are filters
$types = "";
if (!empty($conditions)) {
    $types = "AND " . implode(" AND ", $conditions);
}
?>

<option value="" selected>-- Select Name --</option>
<option value="">All</option>

<?php
// Fetch employees based on filters
// If no filters are selected, show all active employees
if (empty($conditions)) {
    // No filters - show all employees
    $query1 = mysqli_query($con, "SELECT * FROM signup WHERE designation != 'admin' AND status = 'Active' ORDER BY fullName");
    while ($fetch = mysqli_fetch_assoc($query1)) {
?>
        <option value="<?php echo htmlspecialchars($fetch['userId']); ?>">
            <?php echo htmlspecialchars($fetch['fullName']); ?>
        </option>
<?php
    }
} else {
    // With filters - show filtered employees
    $query1 = mysqli_query($con, "SELECT * FROM signup WHERE designation != 'admin' AND status = 'Active' $types ORDER BY fullName");
    while ($fetch = mysqli_fetch_assoc($query1)) {
?>
        <option value="<?php echo htmlspecialchars($fetch['userId']); ?>">
            <?php echo htmlspecialchars($fetch['fullName']); ?>
        </option>
<?php
    }
}
?>