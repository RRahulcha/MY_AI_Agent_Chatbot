<?php
session_start();
header('Content-Type: application/json');

// Auth check
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo json_encode(['success' => false, 'msg' => 'Not logged in']);
    exit;
}

// Role check - Only MD can access this data
$userRole = $_SESSION['userRole'] ?? '';
// Normalize role names
$roleNormalized = strtolower(str_replace('_', '', $userRole));

if ($roleNormalized !== 'md' && $roleNormalized !== 'hr') {
    echo json_encode(['success' => false, 'msg' => 'Unauthorized']);
    exit;
}

include('../include/db_config.php');
include('../include/task_helper.php');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'get_leads_status') {
    $date = mysqli_real_escape_string($con, $_POST['date'] ?? date('Y-m-d'));

    $res = mysqli_query($con, "SELECT DISTINCT userId FROM task_diary WHERE date = '$date' AND (is_deleted = 0 OR is_deleted IS NULL)");
    $filledUserIds = [];
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $filledUserIds[] = $row['userId'];
        }
    }

    echo json_encode([
        'success' => true,
        'filledUserIds' => $filledUserIds
    ]);
    exit;
}

if ($action === 'get_tasks') {
    $targetUserId = mysqli_real_escape_string($con, $_POST['targetUserId'] ?? '');
    $date = mysqli_real_escape_string($con, $_POST['date'] ?? date('Y-m-d'));
    $status = mysqli_real_escape_string($con, $_POST['status'] ?? 'All');

    if (empty($targetUserId)) {
        echo json_encode(['success' => false, 'msg' => 'Target user ID is required']);
        exit;
    }

    $whereClause = "t.userId = '$targetUserId' AND t.date = '$date' AND (t.is_deleted = 0 OR t.is_deleted IS NULL)";

    if ($status !== 'All') {
        $whereClause .= " AND t.status = '$status'";
    }

    $q = "
        SELECT t.id, t.taskid, t.task_description, t.task_type, t.status, 
               DATE_FORMAT(t.created_at, '%h:%i %p') AS created_time,
               DATE_FORMAT(t.updated_at, '%h:%i %p') AS updated_time,
               s.fullName
        FROM task_diary t
        LEFT JOIN signup s ON t.userId COLLATE utf8mb4_unicode_ci = s.userId COLLATE utf8mb4_unicode_ci
        WHERE $whereClause
        ORDER BY CASE WHEN t.status = 'Pending' THEN 1 ELSE 2 END, t.task_type ASC, t.id ASC
    ";

    $res = mysqli_query($con, $q);
    $tasks = [];
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $tasks[] = $row;
        }
    }

    // Fetch stats for all tasks of this user on this date (independent of status filter)
    $qStats = "SELECT 
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) AS completed,
        SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN status = 'Postponed' THEN 1 ELSE 0 END) AS postponed,
        SUM(CASE WHEN LOWER(task_type) = 'planned' THEN 1 ELSE 0 END) AS planned,
        SUM(CASE WHEN LOWER(task_type) = 'unplanned' THEN 1 ELSE 0 END) AS unplanned
        FROM task_diary
        WHERE userId = '$targetUserId' AND date = '$date' AND (is_deleted = 0 OR is_deleted IS NULL)";

    $resStats = mysqli_query($con, $qStats);
    $stats = [
        'total' => 0,
        'completed' => 0,
        'pending' => 0,
        'postponed' => 0,
        'planned' => 0,
        'unplanned' => 0
    ];

    if ($resStats && $rStats = mysqli_fetch_assoc($resStats)) {
        $stats = [
            'total' => (int)($rStats['total'] ?? 0),
            'completed' => (int)($rStats['completed'] ?? 0),
            'pending' => (int)($rStats['pending'] ?? 0),
            'postponed' => (int)($rStats['postponed'] ?? 0),
            'planned' => (int)($rStats['planned'] ?? 0),
            'unplanned' => (int)($rStats['unplanned'] ?? 0),
        ];
    }

    echo json_encode([
        'success' => true,
        'tasks' => $tasks,
        'stats' => $stats
    ]);
    exit;
}

echo json_encode(['success' => false, 'msg' => 'Invalid action']);
?>
