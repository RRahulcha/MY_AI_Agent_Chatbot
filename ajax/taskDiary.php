<?php
session_start();
header('Content-Type: application/json');

// Auth check
if (!isset($_SESSION['userId']) || empty($_SESSION['userId'])) {
    echo json_encode(['success' => false, 'msg' => 'Not logged in']);
    exit;
}

include('../include/db_config.php');
include('../include/task_helper.php');

$userId = $_SESSION['userId'];
$today = date('Y-m-d');
$now = date('Y-m-d H:i:s');

// ── Database Schema Migration Helpers ──
$_chkCol = mysqli_query($con, "SHOW COLUMNS FROM task_diary LIKE 'taskid'");
if ($_chkCol && mysqli_num_rows($_chkCol) === 0) {
    mysqli_query($con, "ALTER TABLE task_diary ADD COLUMN taskid VARCHAR(50) NULL DEFAULT NULL AFTER id");
    mysqli_query($con, "UPDATE task_diary SET taskid = CONCAT('TSK-', LPAD(id, 5, '0')) WHERE taskid IS NULL OR taskid = ''");
    @mysqli_query($con, "ALTER TABLE task_diary ADD INDEX idx_taskid (taskid)");
} else {
    mysqli_query($con, "UPDATE task_diary SET taskid = CONCAT('TSK-', LPAD(id, 5, '0')) WHERE taskid IS NULL OR taskid = ''");
    // Ensure index is non-unique to allow carried-over postponed tasks to retain the exact same taskid
    $_chkIndex = mysqli_query($con, "SHOW INDEX FROM task_diary WHERE Key_name = 'idx_taskid'");
    if ($_chkIndex && $idxRow = mysqli_fetch_assoc($_chkIndex)) {
        if (isset($idxRow['Non_unique']) && $idxRow['Non_unique'] == 0) {
            @mysqli_query($con, "ALTER TABLE task_diary DROP INDEX idx_taskid");
            @mysqli_query($con, "ALTER TABLE task_diary ADD INDEX idx_taskid (taskid)");
        }
    }
}

$_chkDel = mysqli_query($con, "SHOW COLUMNS FROM task_diary LIKE 'is_deleted'");
if ($_chkDel && mysqli_num_rows($_chkDel) === 0) {
    mysqli_query($con, "ALTER TABLE task_diary ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
}

if (!function_exists('ensureTaskId')) {
    function ensureTaskId($con, $id)
    {
        $taskid = 'TSK-' . str_pad($id, 5, '0', STR_PAD_LEFT);
        mysqli_query($con, "UPDATE task_diary SET taskid = '$taskid' WHERE id = $id AND (taskid IS NULL OR taskid = '')");
        return $taskid;
    }
}


syncCarriedTasks($con, $userId, $today);

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    // ── Save planned tasks (bulk) ──
    case 'save_planned':
        $tasks = $_POST['tasks'] ?? [];
        if (!is_array($tasks) || count($tasks) === 0) {
            echo json_encode(['success' => false, 'msg' => 'At least 1 planned task is required']);
            exit;
        }

        // Filter out empty descriptions
        $tasks = array_filter($tasks, function ($t) {
            return !empty(trim($t));
        });

        if (count($tasks) === 0) {
            echo json_encode(['success' => false, 'msg' => 'At least 1 planned task is required']);
            exit;
        }

        // Check 30-minute window post punch-in
        $taskType = 'Planned';
        $punchInTime = null;
        $attQ = mysqli_query($con, "SELECT punchInTime, dateTime FROM attendance WHERE userId = '$userId' AND date = '$today' ORDER BY dateTime ASC LIMIT 1");
        if ($attQ && $attRow = mysqli_fetch_assoc($attQ)) {
            if (!empty($attRow['punchInTime'])) {
                $punchInTime = $today . ' ' . $attRow['punchInTime'];
            } elseif (!empty($attRow['dateTime'])) {
                $punchInTime = $attRow['dateTime'];
            }
        }

        if ($punchInTime) {
            $diffMins = (strtotime($now) - strtotime($punchInTime)) / 60;
            if ($diffMins > 30) {
                // Automatically convert to Unplanned if more than 30 mins after punch-in
                $taskType = 'Unplanned';
            }
        }

        $inserted = 0;
        foreach ($tasks as $descItem) {
            $descClean = trim($descItem);
            $descEsc = mysqli_real_escape_string($con, $descClean);

            // Check if task with this description already exists for today (e.g. carried over from postponement)
            $chkExisting = mysqli_query($con, "
                SELECT id, taskid FROM task_diary
                WHERE userId = '$userId' AND date = '$today' AND task_description = '$descEsc' AND (is_deleted = 0 OR is_deleted IS NULL)
                LIMIT 1
            ");

            if ($chkExisting && $rowExisting = mysqli_fetch_assoc($chkExisting)) {
                // Keep original taskid, update task_type if needed
                $exId = (int) $rowExisting['id'];
                mysqli_query($con, "
                    UPDATE task_diary SET task_type = '$taskType', updated_at = '$now'
                    WHERE id = $exId
                ");
                if (empty($rowExisting['taskid'])) {
                    ensureTaskId($con, $exId);
                }
                $inserted++;
            } else {
                // New task insertion
                $q = mysqli_query($con, "
                    INSERT INTO task_diary (userId, date, task_description, task_type, status, is_deleted, created_at, updated_at)
                    VALUES ('$userId', '$today', '$descEsc', '$taskType', 'Pending', 0, '$now', '$now')
                ");
                if ($q) {
                    $insertedId = mysqli_insert_id($con);
                    ensureTaskId($con, $insertedId);
                    $inserted++;
                }
            }
        }

        // Soft-delete any carried tasks for today that were removed from the planned tasks form
        $submittedDescs = array_map(function ($t) {
            return strtolower(trim($t));
        }, $tasks);

        $carriedInDbQ = mysqli_query($con, "
            SELECT id, task_description FROM task_diary
            WHERE userId = '$userId' AND date = '$today' AND carried_from_date IS NOT NULL AND status = 'Pending' AND (is_deleted = 0 OR is_deleted IS NULL)
        ");
        if ($carriedInDbQ) {
            while ($cRow = mysqli_fetch_assoc($carriedInDbQ)) {
                if (!in_array(strtolower(trim($cRow['task_description'])), $submittedDescs)) {
                    $cId = (int) $cRow['id'];
                    mysqli_query($con, "UPDATE task_diary SET is_deleted = 1, updated_at = '$now' WHERE id = $cId");
                }
            }
        }

        // Mark that planned tasks have been submitted for today (session flag)
        $_SESSION['task_diary_submitted_' . $today] = true;

        $msgStr = $inserted > 0 ? "$inserted task(s) saved successfully as $taskType" : 'Failed to save tasks';
        if ($taskType === 'Unplanned' && $inserted > 0) {
            $msgStr .= " (30 minutes elapsed since Punch In)";
        }

        echo json_encode([
            'success' => $inserted > 0,
            'msg' => $msgStr,
            'count' => $inserted,
            'task_type' => $taskType
        ]);
        break;

    // ── Add a single unplanned task ──
    case 'add_unplanned':
        $desc = trim($_POST['task_description'] ?? '');
        if (empty($desc)) {
            echo json_encode(['success' => false, 'msg' => 'Task description is required']);
            exit;
        }

        // Check 30-minute window post punch-in
        $taskType = 'Unplanned';
        $punchInTime = null;
        $attQ = mysqli_query($con, "SELECT punchInTime, dateTime FROM attendance WHERE userId = '$userId' AND date = '$today' ORDER BY dateTime ASC LIMIT 1");
        if ($attQ && $attRow = mysqli_fetch_assoc($attQ)) {
            if (!empty($attRow['punchInTime'])) {
                $punchInTime = $today . ' ' . $attRow['punchInTime'];
            } elseif (!empty($attRow['dateTime'])) {
                $punchInTime = $attRow['dateTime'];
            }
        }

        if ($punchInTime) {
            $diffMins = (strtotime($now) - strtotime($punchInTime)) / 60;
            if ($diffMins <= 30) {
                // Save as Planned if added within 30 mins of punch-in
                $taskType = 'Planned';
            }
        }

        $descEsc = mysqli_real_escape_string($con, $desc);
        $q = mysqli_query($con, "
            INSERT INTO task_diary (userId, date, task_description, task_type, status, is_deleted, created_at, updated_at)
            VALUES ('$userId', '$today', '$descEsc', '$taskType', 'Pending', 0, '$now', '$now')
        ");

        if ($q) {
            $insertedId = mysqli_insert_id($con);
            ensureTaskId($con, $insertedId);
        }

        echo json_encode([
            'success' => (bool) $q,
            'msg' => $q ? "$taskType task added successfully" : 'Failed to add task',
            'task_type' => $taskType
        ]);
        break;

    // ── Update task status (Completed / Postponed) ──
    case 'update_status':
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $newStatus = $_POST['status'] ?? '';

        if ($taskId <= 0 || !in_array($newStatus, ['Completed', 'Postponed'])) {
            echo json_encode(['success' => false, 'msg' => 'Invalid task ID or status']);
            exit;
        }

        $newStatusEsc = mysqli_real_escape_string($con, $newStatus);
        $q = mysqli_query($con, "
            UPDATE task_diary SET status = '$newStatusEsc', updated_at = '$now'
            WHERE id = $taskId AND userId = '$userId' AND (is_deleted = 0 OR is_deleted IS NULL)
        ");

        // If postponed, carry over to next working day keeping the EXACT SAME taskid
        if ($q && $newStatus === 'Postponed') {
            $nextDate = getNextWorkingDay($today);
            // Get the task description, taskid, and origin carried_from_date
            $taskRow = mysqli_fetch_assoc(mysqli_query($con, "SELECT task_description, taskid, carried_from_date, date FROM task_diary WHERE id = $taskId"));
            if ($taskRow) {
                $taskDesc = mysqli_real_escape_string($con, $taskRow['task_description']);
                $origTaskId = !empty($taskRow['taskid']) ? mysqli_real_escape_string($con, $taskRow['taskid']) : ('TSK-' . str_pad($taskId, 5, '0', STR_PAD_LEFT));
                $origCarriedFrom = (!empty($taskRow['carried_from_date']) && $taskRow['carried_from_date'] <= $taskRow['date']) 
                                   ? $taskRow['carried_from_date'] 
                                   : $taskRow['date'];
                $origCarriedFromEsc = mysqli_real_escape_string($con, $origCarriedFrom);

                // Check if already carried over to $nextDate
                $existsQ = mysqli_query($con, "
                    SELECT id FROM task_diary
                    WHERE userId = '$userId' AND date = '$nextDate' AND task_description = '$taskDesc' AND (is_deleted = 0 OR is_deleted IS NULL)
                    LIMIT 1
                ");
                if (mysqli_num_rows($existsQ) === 0) {
                    mysqli_query($con, "
                        INSERT INTO task_diary (taskid, userId, date, task_description, task_type, status, carried_from_date, is_deleted, created_at, updated_at)
                        VALUES ('$origTaskId', '$userId', '$nextDate', '$taskDesc', 'Planned', 'Pending', '$origCarriedFromEsc', 0, '$now', '$now')
                    ");
                }
            }
        }

        echo json_encode([
            'success' => (bool) $q,
            'msg' => $q ? "Task marked as $newStatus" : 'Failed to update task'
        ]);
        break;

    // ── Bulk update task status (Completed / Postponed) ──
    case 'bulk_update_status':
        $taskIds = $_POST['task_ids'] ?? [];
        $newStatus = $_POST['status'] ?? '';

        if (!is_array($taskIds) || empty($taskIds) || !in_array($newStatus, ['Completed', 'Postponed'])) {
            echo json_encode(['success' => false, 'msg' => 'Invalid task IDs or status']);
            exit;
        }

        $newStatusEsc = mysqli_real_escape_string($con, $newStatus);
        $updated = 0;
        foreach ($taskIds as $tid) {
            $taskId = (int) $tid;
            if ($taskId > 0) {
                $q = mysqli_query($con, "
                    UPDATE task_diary SET status = '$newStatusEsc', updated_at = '$now'
                    WHERE id = $taskId AND userId = '$userId' AND (is_deleted = 0 OR is_deleted IS NULL)
                ");
                if ($q) {
                    $updated++;
                    // If postponed, carry over to next working day keeping the EXACT SAME taskid
                    if ($newStatus === 'Postponed') {
                        $nextDate = getNextWorkingDay($today);
                        $taskRow = mysqli_fetch_assoc(mysqli_query($con, "SELECT task_description, taskid, carried_from_date, date FROM task_diary WHERE id = $taskId"));
                        if ($taskRow) {
                            $taskDesc = mysqli_real_escape_string($con, $taskRow['task_description']);
                            $origTaskId = !empty($taskRow['taskid']) ? mysqli_real_escape_string($con, $taskRow['taskid']) : ('TSK-' . str_pad($taskId, 5, '0', STR_PAD_LEFT));
                            $origCarriedFrom = (!empty($taskRow['carried_from_date']) && $taskRow['carried_from_date'] <= $taskRow['date']) 
                                               ? $taskRow['carried_from_date'] 
                                               : $taskRow['date'];
                            $origCarriedFromEsc = mysqli_real_escape_string($con, $origCarriedFrom);

                            $existsQ = mysqli_query($con, "
                                SELECT id FROM task_diary
                                WHERE userId = '$userId' AND date = '$nextDate' AND task_description = '$taskDesc' AND (is_deleted = 0 OR is_deleted IS NULL)
                                LIMIT 1
                            ");
                            if (mysqli_num_rows($existsQ) === 0) {
                                mysqli_query($con, "
                                    INSERT INTO task_diary (taskid, userId, date, task_description, task_type, status, carried_from_date, is_deleted, created_at, updated_at)
                                    VALUES ('$origTaskId', '$userId', '$nextDate', '$taskDesc', 'Planned', 'Pending', '$origCarriedFromEsc', 0, '$now', '$now')
                                ");
                            }
                        }
                    }
                }
            }
        }

        echo json_encode([
            'success' => $updated > 0,
            'msg' => $updated > 0 ? "$updated task(s) marked as $newStatus" : 'Failed to update tasks'
        ]);
        break;

    // ── Remove a task (Soft Delete) ──
    case 'remove_task':
        $taskId = (int) ($_POST['task_id'] ?? 0);
        if ($taskId <= 0) {
            echo json_encode(['success' => false, 'msg' => 'Invalid task ID']);
            exit;
        }

        // Soft delete task
        $q = mysqli_query($con, "
            UPDATE task_diary 
            SET is_deleted = 1, updated_at = '$now' 
            WHERE id = $taskId AND userId = '$userId' AND (is_deleted = 0 OR is_deleted IS NULL)
        ");

        $affected = mysqli_affected_rows($con);

        echo json_encode([
            'success' => $affected > 0,
            'msg' => $affected > 0 ? 'Task deleted successfully' : 'Cannot delete this task'
        ]);
        break;

    // ── Fetch today's tasks ──
    case 'fetch_tasks':
        $q = mysqli_query($con, "
            SELECT id, taskid, task_description, task_type, status, carried_from_date,
                   DATE_FORMAT(created_at, '%h:%i %p') AS created_time
            FROM task_diary
            WHERE userId = '$userId' AND date = '$today' AND (is_deleted = 0 OR is_deleted IS NULL)
            ORDER BY task_type ASC, id ASC
        ");

        $tasks = [];
        while ($row = mysqli_fetch_assoc($q)) {
            $tasks[] = $row;
        }

        // Check if planned tasks have been submitted
        $hasPlanned = false;
        foreach ($tasks as $t) {
            if ($t['task_type'] === 'Planned') {
                $hasPlanned = true;
                break;
            }
        }

        echo json_encode([
            'success' => true,
            'tasks' => $tasks,
            'hasPlanned' => $hasPlanned,
            'totalCount' => count($tasks),
            'pendingCount' => count(array_filter($tasks, fn($t) => $t['status'] === 'Pending'))
        ]);
        break;

    // ── Check if all tasks are updated (for punch-out gate) ──
    case 'check_all_updated':
        $totalQ = mysqli_query($con, "SELECT COUNT(*) AS cnt FROM task_diary WHERE userId = '$userId' AND date = '$today' AND (is_deleted = 0 OR is_deleted IS NULL)");
        $pendingQ = mysqli_query($con, "SELECT COUNT(*) AS cnt FROM task_diary WHERE userId = '$userId' AND date = '$today' AND status = 'Pending' AND (is_deleted = 0 OR is_deleted IS NULL)");

        $total = (int) (mysqli_fetch_assoc($totalQ)['cnt'] ?? 0);
        $pending = (int) (mysqli_fetch_assoc($pendingQ)['cnt'] ?? 0);

        echo json_encode([
            'success' => true,
            'allUpdated' => ($total > 0 && $pending === 0),
            'totalTasks' => $total,
            'pendingTasks' => $pending,
            'hasPlanned' => $total > 0
        ]);
        break;

    default:
        echo json_encode(['success' => false, 'msg' => 'Invalid action']);
        break;
}
?>