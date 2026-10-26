<?php
session_start();
include('../include/db_config.php');

if (empty($_SESSION['userRole']) || $_SESSION['userRole'] !== 'admin') {
    die('Unauthorized');
}

$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');

$sql = "
SELECT 
    s.fullName,
    a.date,
    a.lunch_start_time,
    a.lunch_end_time,
    TIMESTAMPDIFF(
        MINUTE,
        CONCAT(a.date,' ',a.lunch_start_time),
        CONCAT(a.date,' ',a.lunch_end_time)
    ) AS lunch_minutes
FROM attendance a
JOIN signup s ON s.userId = a.userId
WHERE a.lunch_start_time != '00:00:00'
  AND a.lunch_end_time   != '00:00:00'
  AND a.date BETWEEN ? AND ?
ORDER BY s.fullName, a.date
";

$stmt = $con->prepare($sql);
$stmt->bind_param("ss", $from, $to);
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Intern-wise Lunch Report</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="p-4">

<h4>🍱 Intern-wise Lunch Report</h4>

<form class="row g-2 mb-3">
    <div class="col-md-3">
        <input type="date" name="from" value="<?= $from ?>" class="form-control">
    </div>
    <div class="col-md-3">
        <input type="date" name="to" value="<?= $to ?>" class="form-control">
    </div>
    <div class="col-md-2">
        <button class="btn btn-primary">Filter</button>
    </div>
</form>

<table class="table table-bordered table-striped">
    <thead class="table-dark">
        <tr>
            <th>Intern</th>
            <th>Date</th>
            <th>Lunch Start</th>
            <th>Lunch End</th>
            <th>Duration (min)</th>
        </tr>
    </thead>
    <tbody>
        <?php while ($r = $result->fetch_assoc()): ?>
        <tr>
            <td><?= htmlspecialchars($r['fullName']) ?></td>
            <td><?= date('d M Y', strtotime($r['date'])) ?></td>
            <td><?= $r['lunch_start_time'] ?></td>
            <td><?= $r['lunch_end_time'] ?></td>
            <td class="fw-bold"><?= $r['lunch_minutes'] ?></td>
        </tr>
        <?php endwhile; ?>
    </tbody>
</table>

</body>
</html>
