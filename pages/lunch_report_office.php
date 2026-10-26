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
    a.date,
    COUNT(a.userId) AS total_interns,
    ROUND(AVG(
        TIMESTAMPDIFF(
            MINUTE,
            CONCAT(a.date,' ',a.lunch_start_time),
            CONCAT(a.date,' ',a.lunch_end_time)
        )
    ),2) AS avg_lunch_minutes,
    SUM(
        TIMESTAMPDIFF(
            MINUTE,
            CONCAT(a.date,' ',a.lunch_start_time),
            CONCAT(a.date,' ',a.lunch_end_time)
        ) > 60
    ) AS exceeded_limit
FROM attendance a
WHERE a.lunch_start_time != '00:00:00'
  AND a.lunch_end_time   != '00:00:00'
  AND a.date BETWEEN ? AND ?
GROUP BY a.date
ORDER BY a.date DESC
";

$stmt = $con->prepare($sql);
$stmt->bind_param("ss", $from, $to);
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Office Lunch Summary</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="p-4">

<h4>🏢 Office-wise Lunch Summary</h4>

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

<table class="table table-bordered table-hover">
    <thead class="table-primary">
        <tr>
            <th>Date</th>
            <th>Total Interns</th>
            <th>Avg Lunch (min)</th>
            <th>Exceeded 60 min</th>
        </tr>
    </thead>
    <tbody>
        <?php while ($r = $result->fetch_assoc()): ?>
        <tr>
            <td><?= date('d M Y', strtotime($r['date'])) ?></td>
            <td><?= $r['total_interns'] ?></td>
            <td class="fw-bold"><?= $r['avg_lunch_minutes'] ?></td>
            <td class="text-danger fw-bold"><?= $r['exceeded_limit'] ?></td>
        </tr>
        <?php endwhile; ?>
    </tbody>
</table>

</body>
</html>
