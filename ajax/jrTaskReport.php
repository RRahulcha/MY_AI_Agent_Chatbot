<?php
session_start();
require_once('../include/db_config.php');

ini_set('display_errors', 0);
error_reporting(E_ALL);

date_default_timezone_set('Asia/Kolkata');

// If not logged in
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

// Safe input helper
function in($key, $default = '') {
    return isset($_REQUEST[$key]) ? trim($_REQUEST[$key]) : $default;
}

$loggedInUserId = mysqli_real_escape_string($con, $_SESSION['userId']);

/***************************************************
 * EXPORT ALL (EXCEL/PDF)
 ***************************************************/
if (isset($_GET['exportAll'])) {

    $intern = mysqli_real_escape_string($con, in('intern', ''));
    $fdate  = in('fdate', '');
    $tdate  = in('tdate', '');

    $whereClauses = [];
    $whereClauses[] = "T.userId COLLATE utf8mb4_unicode_ci != '{$loggedInUserId}'";

    if ($intern !== '') {
        $whereClauses[] = "T.userId COLLATE utf8mb4_unicode_ci = '" . mysqli_real_escape_string($con, $intern) . "'";
    }

    if ($fdate !== '' && $tdate !== '') {
        $whereClauses[] = "T.startTime BETWEEN '$fdate 00:00:00' AND '$tdate 23:59:59'";
    } elseif ($fdate !== '') {
        $whereClauses[] = "T.startTime >= '$fdate 00:00:00'";
    } elseif ($tdate !== '') {
        $whereClauses[] = "T.startTime <= '$tdate 23:59:59'";
    }

    $where = count($whereClauses) ? " WHERE " . implode(" AND ", $whereClauses) : "";

    $sql = "SELECT T.projectName, T.workType, T.task, T.status, T.startTime, T.endTime, T.totalDuration, 
                   S.fullName
            FROM TaskEntryNew T
            LEFT JOIN signup S 
                ON T.userId COLLATE utf8mb4_unicode_ci = S.userId COLLATE utf8mb4_unicode_ci
            $where
            ORDER BY T.startTime DESC";

    $res = mysqli_query($con, $sql);
    $out = [];

    while ($row = mysqli_fetch_assoc($res)) {
        $status = $row['status'];
        $startTime = strtotime($row['startTime']);
        $totalDuration = isset($row['totalDuration']) ? (int)$row['totalDuration'] : 0;

        if ($status === 'Running') {
            $elapsed = time() - $startTime;
            $totalSeconds = $totalDuration + $elapsed;
        } else {
            $totalSeconds = $totalDuration;
        }

        $hours = floor($totalSeconds / 3600);
        $minutes = floor(($totalSeconds % 3600) / 60);
        $seconds = $totalSeconds % 60;
        $manHr = sprintf("%02d:%02d:%02d", $hours, $minutes, $seconds);

        $out[] = [
            "fullName"    => $row['fullName'] ?? 'N/A',
            "projectName" => $row['projectName'] ?? 'N/A',
            "workType"    => $row['workType'] ?? 'N/A',
            "task"        => $row['task'] ?? '',
            "manHr"       => $manHr,
            "status"      => $row['status'] ?? ''
        ];
    }

    header('Content-Type: application/json');
    echo json_encode($out);
    exit;
}

/***************************************************
 * STATS SUMMARY ENDPOINT (KPI CARDS)
 ***************************************************/
if (isset($_GET['fetchStats'])) {
    $intern = mysqli_real_escape_string($con, in('intern', ''));
    $fdate  = in('fdate', '');
    $tdate  = in('tdate', '');

    $whereClauses = [];
    $whereClauses[] = "T.userId COLLATE utf8mb4_unicode_ci != '{$loggedInUserId}'";

    if ($intern !== '') {
        $whereClauses[] = "T.userId COLLATE utf8mb4_unicode_ci = '" . mysqli_real_escape_string($con, $intern) . "'";
    }

    if ($fdate !== '' && $tdate !== '') {
        $whereClauses[] = "T.startTime BETWEEN '$fdate 00:00:00' AND '$tdate 23:59:59'";
    } elseif ($fdate !== '') {
        $whereClauses[] = "T.startTime >= '$fdate 00:00:00'";
    } elseif ($tdate !== '') {
        $whereClauses[] = "T.startTime <= '$tdate 23:59:59'";
    }

    $where = count($whereClauses) ? " WHERE " . implode(" AND ", $whereClauses) : "";

    $statsSql = "
        SELECT 
            COUNT(*) AS totalTasks,
            SUM(CASE WHEN LOWER(T.status) = 'finished' THEN 1 ELSE 0 END) AS finishedTasks,
            SUM(CASE WHEN LOWER(T.status) = 'running' THEN 1 ELSE 0 END) AS runningTasks,
            SUM(COALESCE(T.totalDuration, 0)) AS totalSeconds
        FROM TaskEntryNew T
        LEFT JOIN signup S 
            ON T.userId COLLATE utf8mb4_unicode_ci = S.userId COLLATE utf8mb4_unicode_ci
        $where
    ";

    $statsRes = mysqli_query($con, $statsSql);
    $sRow = $statsRes ? mysqli_fetch_assoc($statsRes) : [];

    $totalSec = floatval($sRow['totalSeconds'] ?? 0);
    $totalHours = round($totalSec / 3600, 1);

    header('Content-Type: application/json');
    echo json_encode([
        'totalTasks'    => (int)($sRow['totalTasks'] ?? 0),
        'finishedTasks' => (int)($sRow['finishedTasks'] ?? 0),
        'runningTasks'  => (int)($sRow['runningTasks'] ?? 0),
        'totalHours'    => $totalHours
    ]);
    exit;
}

/***************************************************
 * DATATABLE MODE
 ***************************************************/
header("Content-Type: application/json");

$draw   = intval($_POST['draw'] ?? 1);
$start  = intval($_POST['start'] ?? 0);
$length = intval($_POST['length'] ?? 10);

$columnIndex = intval($_POST['order'][0]['column'] ?? 0);
$columnDir   = $_POST['order'][0]['dir'] ?? 'asc';

$columnMap = [
    0 => 'T.taskId',
    1 => 'S.fullName',
    2 => 'T.projectName',
    3 => 'T.workType',
    4 => 'T.task',
    5 => 'T.startTime',
    6 => 'T.status'
];

$orderColumn = $columnMap[$columnIndex] ?? 'T.taskId';
$orderDir = ($columnDir === 'asc') ? 'ASC' : 'DESC';

$search = mysqli_real_escape_string($con, $_POST['search']['value'] ?? '');
$intern = mysqli_real_escape_string($con, $_POST['intern'] ?? '');
$fdate  = $_POST['fdate'] ?? '';
$tdate  = $_POST['tdate'] ?? '';

$whereClauses = [];
$whereClauses[] = "T.userId COLLATE utf8mb4_unicode_ci != '$loggedInUserId'";

if ($search !== '') {
    $whereClauses[] = "(
        T.projectName LIKE '%$search%' OR
        T.workType LIKE '%$search%' OR
        T.task LIKE '%$search%' OR
        T.status LIKE '%$search%' OR
        S.fullName COLLATE utf8mb4_unicode_ci LIKE '%$search%'
    )";
}

if ($intern !== '') {
    $whereClauses[] = "T.userId COLLATE utf8mb4_unicode_ci = '$intern'";
}

if ($fdate !== '' && $tdate !== '') {
    $whereClauses[] = "T.startTime BETWEEN '$fdate 00:00:00' AND '$tdate 23:59:59'";
} elseif ($fdate !== '') {
    $whereClauses[] = "T.startTime >= '$fdate 00:00:00'";
} elseif ($tdate !== '') {
    $whereClauses[] = "T.startTime <= '$tdate 23:59:59'";
}

$where = count($whereClauses) ? " WHERE " . implode(' AND ', $whereClauses) : "";

$totalRes = mysqli_query(
    $con, 
    "SELECT COUNT(*) AS c 
     FROM TaskEntryNew 
     WHERE userId COLLATE utf8mb4_unicode_ci != '$loggedInUserId'"
);
$total = mysqli_fetch_assoc($totalRes);

$totalFilteredRes = mysqli_query(
    $con, 
    "SELECT COUNT(*) AS c 
     FROM TaskEntryNew T 
     LEFT JOIN signup S 
        ON T.userId COLLATE utf8mb4_unicode_ci = S.userId COLLATE utf8mb4_unicode_ci
     $where"
);
$totalFiltered = mysqli_fetch_assoc($totalFilteredRes);

$sql = "
    SELECT T.taskId, T.projectName, T.workType, T.task, T.status, 
           T.startTime, T.endTime, T.totalDuration, S.fullName
    FROM TaskEntryNew T
    LEFT JOIN signup S 
        ON T.userId COLLATE utf8mb4_unicode_ci = S.userId COLLATE utf8mb4_unicode_ci
    $where
    ORDER BY $orderColumn $orderDir
    LIMIT $start, $length
";

$res = mysqli_query($con, $sql);
$data = [];
$sr = $start + 1;

while ($row = mysqli_fetch_assoc($res)) {

    $status = $row['status'] ?? 'Finished';
    $startTime = strtotime($row['startTime']);
    $totalDuration = isset($row['totalDuration']) ? (int)$row['totalDuration'] : 0;

    if ($status === 'Running') {
        $elapsed = time() - $startTime;
        $totalSeconds = $totalDuration + $elapsed;
    } else {
        $totalSeconds = $totalDuration;
    }

    $hours = floor($totalSeconds / 3600);
    $minutes = floor(($totalSeconds % 3600) / 60);
    $seconds = $totalSeconds % 60;
    $manHr = sprintf("%02d:%02d:%02d", $hours, $minutes, $seconds);

    // Status Badge
    $st = strtolower($status);
    if ($st === 'finished') {
        $statusHtml = '<span class="badge bg-success text-white px-2 py-1" style="border-radius:6px; font-weight:600;"><i class="fa-solid fa-circle-check me-1"></i> Finished</span>';
    } elseif ($st === 'running') {
        $statusHtml = '<span class="badge bg-primary text-white px-2 py-1" style="border-radius:6px; font-weight:600;"><i class="fa-solid fa-play me-1"></i> Running</span>';
    } elseif ($st === 'paused') {
        $statusHtml = '<span class="badge bg-warning text-dark px-2 py-1" style="border-radius:6px; font-weight:600;"><i class="fa-solid fa-pause me-1"></i> Paused</span>';
    } else {
        $statusHtml = '<span class="badge bg-secondary text-white px-2 py-1" style="border-radius:6px; font-weight:600;">' . htmlspecialchars($status) . '</span>';
    }

    $data[] = [
        "srNo"        => $sr++,
        "fullName"    => htmlspecialchars($row['fullName'] ?? 'N/A'),
        "projectName" => htmlspecialchars($row['projectName'] ?? 'N/A'),
        "workType"    => '<span class="badge bg-light text-dark border px-2 py-1" style="font-size:0.78rem;">' . htmlspecialchars($row['workType'] ?? 'N/A') . '</span>',
        "task"        => htmlspecialchars($row['task'] ?? ''),
        "manHr"       => '<code class="text-primary fw-bold" style="font-size:0.85rem;">' . $manHr . '</code>',
        "status"      => $statusHtml
    ];
}

echo json_encode([
    "draw"            => $draw,
    "recordsTotal"    => intval($total['c'] ?? 0),
    "recordsFiltered" => intval($totalFiltered['c'] ?? 0),
    "data"            => $data
]);
exit;
?>
