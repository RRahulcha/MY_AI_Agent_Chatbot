<?php
require_once __DIR__ . '/../include/db_config.php';

$sql = "SELECT * FROM employees";
$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Total Employees</title>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>

    <h2>Total Employees</h2>

    <!-- Search Bar -->
    <input type="text" id="searchBar" placeholder="Search Employees..." onkeyup="searchFunction()">

    <!-- Export Button -->
    <button onclick="exportToExcel()">Export to Excel</button>

    <table border="1" id="employeeTable">
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Email</th>
                <th>Position</th>
                <th>Department</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($row = $result->fetch_assoc()): ?>
                <tr>
                    <td><?php echo $row["id"]; ?></td>
                    <td><?php echo $row["name"]; ?></td>
                    <td><?php echo $row["email"]; ?></td>
                    <td><?php echo $row["position"]; ?></td>
                    <td><?php echo $row["department"]; ?></td>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>

    <script>
        // Search function
        function searchFunction() {
            var input, filter, table, tr, td, i, txtValue;
            input = document.getElementById("searchBar");
            filter = input.value.toUpperCase();
            table = document.getElementById("employeeTable");
            tr = table.getElementsByTagName("tr");

            for (i = 1; i < tr.length; i++) {
                td = tr[i].getElementsByTagName("td")[1]; // Search by name
                if (td) {
                    txtValue = td.textContent || td.innerText;
                    tr[i].style.display = txtValue.toUpperCase().indexOf(filter) > -1 ? "" : "none";
                }
            }
        }

        // Export to Excel
        function exportToExcel() {
            window.location.href = "export_excel.php";
        }
    </script>

</body>
</html>

<?php $conn->close(); ?>
