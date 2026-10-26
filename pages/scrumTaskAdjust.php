<?php
/**
 * scrumTaskAdjust.php
 * Handles task adjustment submissions from scrumCall.php
 *
 * POST params:
 *   internId       — internId of the affected intern
 *   requestedBy    — userId of who submitted
 *   isResourceHead — '1' if resource head (direct apply), '0' if scrum host (pending approval)
 *   newTasks       — JSON array of { project, client, startTime, endTime }
 *   oldTasks       — JSON array of current allocations (for record keeping)
 *   date           — Y-m-d
 */

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['userId'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

include('../include/db_config.php');

$internId       = isset($_POST['internId'])       ? mysqli_real_escape_string($con, $_POST['internId'])   : '';
$requestedBy    = isset($_POST['requestedBy'])    ? mysqli_real_escape_string($con, $_POST['requestedBy']): '';
$isResourceHead = isset($_POST['isResourceHead']) ? ($_POST['isResourceHead'] === '1') : false;
$date           = isset($_POST['date'])           ? mysqli_real_escape_string($con, $_POST['date'])       : date('Y-m-d');
$newTasks       = isset($_POST['newTasks'])       ? json_decode($_POST['newTasks'], true) : [];
$oldTasks       = isset($_POST['oldTasks'])       ? json_decode($_POST['oldTasks'], true) : [];

if (empty($internId) || empty($newTasks)) {
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
    exit;
}

// For each new task, build the old project name string for audit
$oldProjectName = '';
$oldStartTime   = '';
$oldEndTime     = '';
if (!empty($oldTasks)) {
    $oldProjectName = implode(', ', array_column($oldTasks, 'projectName'));
    $oldStartTime   = $oldTasks[0]['allocStartTime'] ?? '';
    $oldEndTime     = $oldTasks[count($oldTasks)-1]['allocEndTime'] ?? '';
}

if ($isResourceHead) {
    // ── DIRECT APPLY: Resource Head bypasses approval ──────────────────
    // Remove existing allocations for this intern on this date
    mysqli_query($con, "
        DELETE FROM internAllocations
        WHERE internId COLLATE utf8mb4_unicode_ci = '$internId'
          AND startDate <= '$date' AND endDate >= '$date'
    ");

    $newAllocations = [];
    foreach ($newTasks as $task) {
        $proj   = mysqli_real_escape_string($con, $task['project'] ?? '');
        $client = mysqli_real_escape_string($con, $task['client']  ?? '');
        $st     = mysqli_real_escape_string($con, $task['startTime']);
        $et     = mysqli_real_escape_string($con, $task['endTime']);

        if (empty($proj)) continue;

        mysqli_query($con, "
            INSERT INTO internAllocations
                (internId, projectName, clientName, allocStartTime, allocEndTime, startDate, endDate, assignedBy)
            VALUES
                ('$internId', '$proj', '$client', '$st', '$et', '$date', '$date', '$requestedBy')
        ");

        if (mysqli_error($con)) {
            echo json_encode(['status' => 'error', 'message' => 'DB error: ' . mysqli_error($con)]);
            exit;
        }

        $newAllocations[] = [
            'id'           => mysqli_insert_id($con),
            'projectName'  => $proj,
            'clientName'   => $client,
            'allocStartTime' => $st,
            'allocEndTime'   => $et,
        ];
    }

    // Log this adjustment in the audit table
    foreach ($newTasks as $task) {
        $proj   = mysqli_real_escape_string($con, $task['project'] ?? '');
        $client = mysqli_real_escape_string($con, $task['client']  ?? '');
        $st     = mysqli_real_escape_string($con, $task['startTime']);
        $et     = mysqli_real_escape_string($con, $task['endTime']);
        $oldPN  = mysqli_real_escape_string($con, $oldProjectName);
        $oldST  = mysqli_real_escape_string($con, $oldStartTime);
        $oldET  = mysqli_real_escape_string($con, $oldEndTime);

        mysqli_query($con, "
            INSERT INTO scrumTaskAdjustments
                (internId, requestedBy, oldProjectName, oldStartTime, oldEndTime,
                 newProjectName, newClientName, newStartTime, newEndTime,
                 date, status, approvedBy, createdAt, updatedAt)
            VALUES
                ('$internId', '$requestedBy', '$oldPN', '$oldST', '$oldET',
                 '$proj', '$client', '$st', '$et',
                 '$date', 'approved', '$requestedBy', NOW(), NOW())
        ");
    }

    echo json_encode([
        'status'      => 'success',
        'message'     => 'Task adjusted directly by Resource Head',
        'allocations' => $newAllocations
    ]);

} else {
    // ── PENDING APPROVAL: Scrum Host submits → goes to Resource Head ───
    foreach ($newTasks as $task) {
        $proj   = mysqli_real_escape_string($con, $task['project'] ?? '');
        $client = mysqli_real_escape_string($con, $task['client']  ?? '');
        $st     = mysqli_real_escape_string($con, $task['startTime']);
        $et     = mysqli_real_escape_string($con, $task['endTime']);
        $oldPN  = mysqli_real_escape_string($con, $oldProjectName);
        $oldST  = mysqli_real_escape_string($con, $oldStartTime);
        $oldET  = mysqli_real_escape_string($con, $oldEndTime);

        if (empty($proj)) continue;

        mysqli_query($con, "
            INSERT INTO scrumTaskAdjustments
                (internId, requestedBy, oldProjectName, oldStartTime, oldEndTime,
                 newProjectName, newClientName, newStartTime, newEndTime,
                 date, status, createdAt, updatedAt)
            VALUES
                ('$internId', '$requestedBy', '$oldPN', '$oldST', '$oldET',
                 '$proj', '$client', '$st', '$et',
                 '$date', 'pending', NOW(), NOW())
        ");

        if (mysqli_error($con)) {
            echo json_encode(['status' => 'error', 'message' => 'DB error: ' . mysqli_error($con)]);
            exit;
        }
    }

    echo json_encode([
        'status'  => 'success',
        'message' => 'Task adjustment submitted for approval'
    ]);
}