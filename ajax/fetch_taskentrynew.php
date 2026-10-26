<?php
if (session_status() === PHP_SESSION_NONE) session_start();
error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: text/html; charset=utf-8');
// ✅ OPTIMIZED: suppress debug output that polluted HTML responses in production

include('../include/db_config.php');
date_default_timezone_set('Asia/Kolkata');

$userId = $_SESSION['userId'] ?? 0;

$fromDate = trim($_GET['fromDate'] ?? '');
$toDate   = trim($_GET['toDate'] ?? '');

$dateWhere = "";
if (!empty($fromDate) && !empty($toDate)) {
    $fromDateEsc = mysqli_real_escape_string($con, $fromDate);
    $toDateEsc   = mysqli_real_escape_string($con, $toDate);
    $dateWhere   = " AND DATE(startTime) BETWEEN '$fromDateEsc' AND '$toDateEsc'";
} elseif (!empty($fromDate)) {
    $fromDateEsc = mysqli_real_escape_string($con, $fromDate);
    $dateWhere   = " AND DATE(startTime) >= '$fromDateEsc'";
} elseif (!empty($toDate)) {
    $toDateEsc   = mysqli_real_escape_string($con, $toDate);
    $dateWhere   = " AND DATE(startTime) <= '$toDateEsc'";
}

// ✅ OPTIMIZED: Fetch badge counts in single query matching selected date range!
$stmt = $con->prepare(
    "SELECT
        SUM(status = 'Running')  AS countRunning,
        SUM(status = 'Paused')   AS countPause,
        SUM(status = 'Finished') AS countEnd
     FROM TaskEntryNew
     WHERE userId = ?" . $dateWhere
);
$stmt->bind_param('s', $userId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$countRunning = (int)($row['countRunning'] ?? 0);
$countPause   = (int)($row['countPause']   ?? 0);
$countEnd     = (int)($row['countEnd']     ?? 0);

header('X-Running-Count: ' . $countRunning);
header('X-Pause-Count: ' . $countPause);
header('X-End-Count: ' . $countEnd);

$filter = $_GET['filter'] ?? 'Running';

$statusMap = ['Running' => 'Running', 'Pause' => 'Paused', 'End' => 'Finished'];
$dbStatus  = $statusMap[$filter] ?? 'Running';

$query     = mysqli_query($con, "SELECT taskId, workType, task, projectName, status, startTime, endTime, totalDuration, remark FROM TaskEntryNew WHERE userId='$userId' AND status='$dbStatus' $dateWhere ORDER BY startTime DESC");
$totalRows = mysqli_num_rows($query);

if ($totalRows === 0) {
    $labels = ['Running' => 'running', 'Pause' => 'paused', 'End' => 'ended'];
    $label  = $labels[$filter] ?? $filter;

    $dateMsg = "";
    if (!empty($fromDate) && !empty($toDate)) {
        $dateMsg = " for selected date range (" . date('d M Y', strtotime($fromDate)) . " to " . date('d M Y', strtotime($toDate)) . ")";
    } elseif (!empty($fromDate)) {
        $dateMsg = " from " . date('d M Y', strtotime($fromDate));
    } elseif (!empty($toDate)) {
        $dateMsg = " up to " . date('d M Y', strtotime($toDate));
    }

    echo "
    <tr>
        <td colspan='10'>
            <div style='text-align:center; padding:40px 20px; color:#6c757d;'>
                <i class='mdi mdi-clipboard-text-outline' style='font-size:38px; display:block; margin-bottom:10px; color:#adb5bd;'></i>
                <p style='font-size:15px; margin:0;'>No <strong>{$label}</strong> tasks found{$dateMsg}</p>
            </div>
        </td>
    </tr>";
    exit;
}

// ✅ OPTIMIZED: Pre-fetch ALL resumeTimes for Running tasks in ONE query matching date range.
$resumeMap = [];
if ($dbStatus === 'Running') {
    $rmq = mysqli_query($con,
        "SELECT taskId, resumeTime FROM taskStatus
         WHERE taskId IN (
             SELECT taskId FROM TaskEntryNew
             WHERE userId='$userId' AND status='Running' $dateWhere
         )
           AND status IN ('Started','Running')
         ORDER BY dateTime DESC"
    );
    while ($rr = mysqli_fetch_assoc($rmq)) {
        // Keep only the latest resumeTime per taskId (query is DESC, first row wins)
        if (!isset($resumeMap[$rr['taskId']])) {
            $resumeMap[$rr['taskId']] = $rr['resumeTime'];
        }
    }
}

// ✅ Pre-fetch project -> client mapping to ensure "Project Name - Client Name" display format
$projectClientMap = [];
$pmQ = mysqli_query($con, "SELECT projectName, client FROM projectMaster");
if ($pmQ) {
    while ($pmRow = mysqli_fetch_assoc($pmQ)) {
        $pn = trim($pmRow['projectName'] ?? '');
        $cl = trim($pmRow['client'] ?? '');
        if (!empty($pn) && !empty($cl) && strtoupper($cl) !== 'NA') {
            $projectClientMap[$pn] = $cl;
        }
    }
}

$i = 1;
while ($row = mysqli_fetch_assoc($query)) {
    $status = $row['status'];
    $taskId = $row['taskId'];

    /* === MAN HOURS === */
    if ($row['task'] === 'Lunch Break' || $row['task'] === 'Break') {
        if (!empty($row['startTime']) && !empty($row['endTime'])) {
            $diff  = max(0, strtotime($row['endTime']) - strtotime($row['startTime']));
            $manHr = sprintf('%02d:%02d:%02d', floor($diff/3600), floor(($diff%3600)/60), $diff%60);
        } else {
            $manHr = '00:00:00';
        }
        $timerHtml = "<span style='font-family:monospace;'>{$manHr}</span>";
    } else {
        $totalDuration = (int)($row['totalDuration'] ?? 0);
        if ($status === 'Running') {
            // ✅ Use pre-fetched map — no extra DB query per row
            $resumeTime   = isset($resumeMap[$taskId])
                ? strtotime($resumeMap[$taskId])
                : strtotime($row['startTime']);
            $totalSeconds = $totalDuration + (time() - $resumeTime);
            // Live timer span — JS will tick this every second
            $timerHtml = "<span class='live-timer' data-seconds='{$totalSeconds}'>"
                . sprintf('%02d:%02d:%02d', floor($totalSeconds/3600), floor(($totalSeconds%3600)/60), $totalSeconds%60)
                . "</span>";
        } else {
            $totalSeconds = $totalDuration;
            $manHr        = sprintf('%02d:%02d:%02d', floor($totalSeconds/3600), floor(($totalSeconds%3600)/60), $totalSeconds%60);
            $timerHtml    = "<span style='font-family:monospace;'>{$manHr}</span>";
        }
    }

    /* === FORMAT TIMES === */
    // data-order uses ISO timestamp so DataTables sorts chronologically, not by display text
    $startIso       = $row['startTime'] ? date('Y-m-d H:i:s', strtotime($row['startTime'])) : '';
    $endIso         = $row['endTime']   ? date('Y-m-d H:i:s', strtotime($row['endTime']))   : '';
    $formattedStart = $row['startTime'] ? date('d M Y, h:i A', strtotime($row['startTime'])) : '-';
    $formattedEnd   = $row['endTime']   ? date('d M Y, h:i A', strtotime($row['endTime']))   : '-';

    /* === STATUS BADGE === */
    $statusClass = '';
    $statusLabel = $status;
    if ($status === 'Running')      { $statusClass = 'status-running'; }
    elseif ($status === 'Paused')   { $statusClass = 'status-pause';   $statusLabel = 'Paused'; }
    elseif ($status === 'Finished') { $statusClass = 'status-end';     $statusLabel = 'Finished'; }

    /* === ACTION BUTTONS === */
    $actionHtml = '';
    if ($status === 'Running') {
        if ($row['task'] === 'Lunch Break' || $row['task'] === 'Break') {
            $actionHtml = "<button class='action-btn btn-end' onclick=\"updateStatus({$taskId}, 'Finished')\">&#9632; End</button>";
        } else {
            $actionHtml = "
                <button class='action-btn btn-pause' onclick=\"updateStatus({$taskId}, 'Paused')\">&#9646;&#9646; Pause</button>
                <button class='action-btn btn-end'   onclick=\"updateStatus({$taskId}, 'Finished')\">&#9632; End</button>";
        }
    } elseif ($status === 'Paused') {
        $actionHtml = "
            <button class='action-btn btn-running' onclick=\"updateStatus({$taskId}, 'Running')\">&#9654; Resume</button>
            <button class='action-btn btn-end'     onclick=\"updateStatus({$taskId}, 'Finished')\">&#9632; End</button>";
    }  elseif ($status === 'Finished') {
    $actionHtml = "<button class='action-btn btn-running' onclick=\"updateStatus({$taskId}, 'Running')\">&#9654; Resume</button>";
}
    $remarkDisplay  = (!empty($row['remark'])) ? htmlspecialchars($row['remark']) : '-';
    
    $rawProject = trim($row['projectName'] ?? '');
    if (!empty($rawProject)) {
        if (strpos($rawProject, ' - ') !== false) {
            $projectDisplay = htmlspecialchars($rawProject);
        } elseif (isset($projectClientMap[$rawProject])) {
            $projectDisplay = htmlspecialchars($rawProject . ' - ' . $projectClientMap[$rawProject]);
        } else {
            $projectDisplay = htmlspecialchars($rawProject);
        }
    } else {
        $projectDisplay = '-';
    }

    echo "
    <tr data-taskid='{$taskId}'>
        <td>{$i}</td>
        <td>{$row['workType']}</td>
        <td>{$row['task']}</td>
        <td>{$projectDisplay}</td>
        <td data-order='{$startIso}' style='font-size:13px;'>{$formattedStart}</td>
        <td>{$timerHtml}</td>
        <td data-order='{$endIso}' style='font-size:13px;'>{$formattedEnd}</td>
        <td class='status-cell {$statusClass}'>{$statusLabel}</td>
        <td>{$remarkDisplay}</td>
        <td>{$actionHtml}</td>
    </tr>";

    $i++;
}
?>