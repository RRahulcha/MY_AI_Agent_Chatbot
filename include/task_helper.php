<?php
/**
 * Helper functions for Task Diary
 */

if (!function_exists('getNextWorkingDay')) {
    function getNextWorkingDay($dateStr) {
        $ts = strtotime($dateStr);
        $dayOfWeek = (int)date('N', $ts); // 1 = Mon, 5 = Fri, 6 = Sat, 7 = Sun
        if ($dayOfWeek === 5) {
            return date('Y-m-d', strtotime($dateStr . ' +3 days')); // Friday -> Monday
        } elseif ($dayOfWeek === 6) {
            return date('Y-m-d', strtotime($dateStr . ' +2 days')); // Saturday -> Monday
        } else {
            return date('Y-m-d', strtotime($dateStr . ' +1 day')); // Other days -> Next day
        }
    }
}

if (!function_exists('syncCarriedTasks')) {
    function syncCarriedTasks($con, $userId, $today = null) {
        if (empty($userId)) {
            return;
        }
        if (!$today) {
            $today = date('Y-m-d');
        }
        $now = date('Y-m-d H:i:s');
        $userIdEsc = mysqli_real_escape_string($con, $userId);

        // Find all past postponed or pending tasks from previous dates
        // that have not yet been carried to $today.
        $q = mysqli_query($con, "
            SELECT id, taskid, task_description, date, carried_from_date 
            FROM task_diary 
            WHERE userId = '$userIdEsc' 
              AND date < '$today' 
              AND status IN ('Postponed', 'Pending')
              AND (is_deleted = 0 OR is_deleted IS NULL)
            ORDER BY date ASC, id ASC
        ");

        if ($q && mysqli_num_rows($q) > 0) {
            while ($row = mysqli_fetch_assoc($q)) {
                $rawDesc = $row['task_description'];
                $taskDescEsc = mysqli_real_escape_string($con, $rawDesc);
                $origTaskId = !empty($row['taskid']) ? mysqli_real_escape_string($con, $row['taskid']) : ('TSK-' . str_pad($row['id'], 5, '0', STR_PAD_LEFT));
                // Original origin date: must be <= date (cannot be a future date)
                $origCarriedFrom = (!empty($row['carried_from_date']) && $row['carried_from_date'] <= $row['date']) 
                                   ? $row['carried_from_date'] 
                                   : $row['date'];
                $origCarriedFromEsc = mysqli_real_escape_string($con, $origCarriedFrom);

                // Check if this task description already exists for $today
                $checkQ = mysqli_query($con, "
                    SELECT id FROM task_diary 
                    WHERE userId = '$userIdEsc' 
                      AND date = '$today' 
                      AND task_description = '$taskDescEsc'
                      AND (is_deleted = 0 OR is_deleted IS NULL)
                    LIMIT 1
                ");

                if ($checkQ && mysqli_num_rows($checkQ) === 0) {
                    // Check if task is completed anywhere to avoid reviving completed tasks
                    $checkComp = mysqli_query($con, "
                        SELECT id FROM task_diary 
                        WHERE userId = '$userIdEsc' 
                          AND task_description = '$taskDescEsc'
                          AND status = 'Completed'
                          AND (is_deleted = 0 OR is_deleted IS NULL)
                        LIMIT 1
                    ");

                    if ($checkComp && mysqli_num_rows($checkComp) === 0) {
                        // Insert carried task for $today
                        mysqli_query($con, "
                            INSERT INTO task_diary (taskid, userId, date, task_description, task_type, status, carried_from_date, is_deleted, created_at, updated_at)
                            VALUES ('$origTaskId', '$userIdEsc', '$today', '$taskDescEsc', 'Planned', 'Pending', '$origCarriedFromEsc', 0, '$now', '$now')
                        ");
                    }
                }
            }
        }
    }
}
