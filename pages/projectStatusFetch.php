// projectStatus-fetch.php (to fetch the data via AJAX)
<?php
include ('../include/db_config.php');
if (mysqli_connect_errno()) {
    echo 'Failed to connect to MySQL: ' . mysqli_connect_error();
    exit();
}

// SQL query to join projectProgress and projectMaster
$sql = 'SELECT pp.projectId, pm.projectName, pp.progress, pp.remark
        FROM projectProgress pp
        INNER JOIN projectMaster pm ON pp.projectId = pm.projectId';

$result = mysqli_query($con, $sql);

$data = [];

while ($row = mysqli_fetch_assoc($result)) {
    $data[] = [
        'projectId' => $row['projectId'],
        'projectName' => $row['projectName'],
        'progress' => $row['progress'],
        'remark' => $row['remark']
    ];
}

echo json_encode(['data' => $data]);
?>
