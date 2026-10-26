<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * early_go_helper.php — Early Go Policy Helper Engine
 * ─────────────────────────────────────────────────────────────────────────────
 * Implements the Early Go Policy (effective from POLICY_EFFECTIVE_DATE):
 * 1. Calculate actual punch-out time against expected punch-out.
 * 2. Calculate early departure duration in minutes.
 * 3. ≤ 15 mins → Allowed without mark (0 mark).
 * 4. >15 and ≤60 mins → 1 Early Go Mark.
 * 5. >60 and ≤120 mins → 1 Early Go Mark (treated same as 15–60 range).
 * 6. >120 mins → Half Day Leave.
 * 7. Monthly counter per employee (resets each calendar month YYYY-MM).
 * 8. 3rd Early Go Mark in month → Warning notification.
 * 9. Every 2 additional marks after warning (5th, 7th, 9th...) → Half Day Leave deduction.
 * 10. Monthly counter resets at beginning of month.
 * 11. Approved early departures (hr_granted = 1) do NOT count toward counter.
 * 12. Policy only applies from POLICY_EFFECTIVE_DATE (2026-08-01) onwards.
 * ─────────────────────────────────────────────────────────────────────────────
 */

// Share the same policy effective date constant defined in late_mark_helper.php
if (!defined('POLICY_EFFECTIVE_DATE')) {
    define('POLICY_EFFECTIVE_DATE', '2026-08-01');
}

if (!function_exists('ensureEarlyGoTableColumns')) {
    /**
     * Auto-migrate early_go table to support Early Go Policy columns.
     */
    function ensureEarlyGoTableColumns(mysqli $con): void {
        static $migrated = false;
        if ($migrated) return;

        $columnsNeeded = [
            'early_departure_minutes' => "ALTER TABLE early_go ADD COLUMN early_departure_minutes INT DEFAULT 0 AFTER early_go_time",
            'early_go_mark'           => "ALTER TABLE early_go ADD COLUMN early_go_mark TINYINT(1) DEFAULT 0 AFTER early_departure_minutes",
            'action_taken'            => "ALTER TABLE early_go ADD COLUMN action_taken VARCHAR(255) DEFAULT 'None' AFTER early_go_mark",
            'hr_granted'              => "ALTER TABLE early_go ADD COLUMN hr_granted TINYINT(1) DEFAULT 0 AFTER reason",
            'hr_granted_by'           => "ALTER TABLE early_go ADD COLUMN hr_granted_by VARCHAR(50) DEFAULT NULL AFTER hr_granted",
            'emp_name'                => "ALTER TABLE early_go ADD COLUMN emp_name VARCHAR(150) DEFAULT NULL AFTER userId",
        ];

        foreach ($columnsNeeded as $col => $sql) {
            $check = mysqli_query($con, "SHOW COLUMNS FROM early_go LIKE '$col'");
            if ($check && mysqli_num_rows($check) === 0) {
                mysqli_query($con, $sql);
            }
        }
        $migrated = true;
    }
}

if (!function_exists('getMonthlyEarlyGoCount')) {
    /**
     * Get count of unapproved Early Go Marks for user in specified month (YYYY-MM).
     */
    function getMonthlyEarlyGoCount(mysqli $con, string $userId, string $yearMonth, ?string $excludeDate = null): int {
        ensureEarlyGoTableColumns($con);
        $u = mysqli_real_escape_string($con, $userId);
        $ym = mysqli_real_escape_string($con, $yearMonth);
        
        $dateCondition = "";
        if (!empty($excludeDate)) {
            $exDate = mysqli_real_escape_string($con, $excludeDate);
            $dateCondition = " AND date != '$exDate'";
        }

        $res = mysqli_query($con, "
            SELECT COUNT(*) AS cnt 
            FROM early_go 
            WHERE userId = '$u' 
              AND DATE_FORMAT(date, '%Y-%m') = '$ym' 
              AND COALESCE(hr_granted, 0) = 0 
              AND early_go_mark = 1
              $dateCondition
        ");

        if ($res && $row = mysqli_fetch_assoc($res)) {
            return (int)$row['cnt'];
        }
        return 0;
    }
}

if (!function_exists('processEarlyGoPolicy')) {
    /**
     * Evaluate Early Go Policy for a punch out event.
     * 
     * @return array {
     *   earlyDepartureMinutes: int,
     *   earlyGoMark: int,
     *   actionTaken: string,
     *   isHalfDay: bool,
     *   monthlyCount: int,
     *   warningIssued: bool,
     *   halfDayDeducted: bool
     * }
     */
    function processEarlyGoPolicy(
        mysqli $con,
        string $userId,
        string $date,
        string $punchOutTime,
        string $expectedPunchOut = '18:30:00',
        string $reason = ''
    ): array {
        ensureEarlyGoTableColumns($con);

        // ── Policy Date Guard ─────────────────────────────────────────────────
        // The Early Go Policy is only effective from POLICY_EFFECTIVE_DATE.
        // Records before this date are fully exempt — no marks, no deductions.
        if ($date < POLICY_EFFECTIVE_DATE) {
            return [
                'earlyDepartureMinutes' => 0,
                'earlyGoMark'           => 0,
                'actionTaken'           => 'Pre-policy date (exempt)',
                'isHalfDay'             => false,
                'monthlyCount'          => 0,
                'warningIssued'         => false,
                'halfDayDeducted'       => false,
            ];
        }

        $escUser   = mysqli_real_escape_string($con, $userId);
        $escDate   = mysqli_real_escape_string($con, $date);
        $escReason = mysqli_real_escape_string($con, trim($reason));
        $escTime   = mysqli_real_escape_string($con, $punchOutTime);

        // Fetch employee name
        $nameRes = mysqli_query($con, "SELECT fullName FROM signup WHERE userId = '$escUser' LIMIT 1");
        $empName = ($nameRes && $nr = mysqli_fetch_assoc($nameRes)) ? $nr['fullName'] : '';
        $escEmpName = mysqli_real_escape_string($con, $empName);

        // Check if HR pre-granted early go for today
        $hrCheck = mysqli_query($con, "
            SELECT early_go_time, hr_granted 
            FROM early_go 
            WHERE userId = '$escUser' AND date = '$escDate' AND hr_granted = 1 
            ORDER BY id DESC LIMIT 1
        ");
        $hrRow = ($hrCheck && mysqli_num_rows($hrCheck) > 0) ? mysqli_fetch_assoc($hrCheck) : null;
        $isHrApproved = ($hrRow && (int)$hrRow['hr_granted'] === 1);

        // Determine effective expected punch out time
        $effectiveExpected = $expectedPunchOut;
        if ($isHrApproved && !empty($hrRow['early_go_time'])) {
            $effectiveExpected = $hrRow['early_go_time'];
        }

        // Calculate departure duration in minutes
        $expectedTs = strtotime("$date $effectiveExpected");
        $actualTs   = strtotime("$date $punchOutTime");
        $minutes    = max(0, (int)round(($expectedTs - $actualTs) / 60));

        $mark            = 0;
        $actionTaken     = 'Allowed (≤15 mins)';
        $isHalfDay       = false;
        $warningIssued   = false;
        $halfDayDeducted = false;

        if ($isHrApproved) {
            // Rule 10: Approved early departures do NOT count toward Early Go counter
            $mark        = 0;
            $actionTaken = 'HR Approved Early Go';
            $isHalfDay   = false;
        } else if ($minutes <= 15) {
            // Rule 3: Up to 15 mins -> allowed without mark
            $mark        = 0;
            $actionTaken = 'Allowed (≤15 mins)';
            $isHalfDay   = false;
        } else if ($minutes <= 60) {
            // Rule 4: 15–60 mins -> 1 Early Go Mark
            $mark        = 1;
            $actionTaken = '1 Early Go Mark (15–60 mins)';
            $isHalfDay   = false;
        } else if ($minutes <= 120) {
            // Rule 5: 60–120 mins -> 1 Early Go Mark (same penalty bracket)
            $mark        = 1;
            $actionTaken = '1 Early Go Mark (60–120 mins)';
            $isHalfDay   = false;
        } else {
            // Rule 6: More than 120 mins -> Half Day Leave
            $mark        = 1;
            $actionTaken = 'Half Day Leave (>120 mins)';
            $isHalfDay   = true;
        }

        // Calculate monthly counter
        $yearMonth = date('Y-m', strtotime($date));
        $prevCount = getMonthlyEarlyGoCount($con, $userId, $yearMonth, $date);
        $totalMonthlyCount = $prevCount + $mark;

        // Threshold checks (Rules 7 & 8)
        if ($mark === 1 && !$isHrApproved) {
            if ($totalMonthlyCount === 3) {
                // Rule 7: 3 Early Go Marks in a month -> Warning
                $warningIssued = true;
                $actionTaken  .= ' | Warning Issued';

                // Send notification if helper exists
                if (file_exists(__DIR__ . '/notification_helper.php')) {
                    require_once __DIR__ . '/notification_helper.php';
                    if (function_exists('sendNotification')) {
                        $monthName = date('F Y', strtotime($date));
                        sendNotification(
                            $con,
                            $userId,
                            'Early Go Warning ⚠️',
                            "Warning: You have accumulated 3 Early Go marks in {$monthName}. Further unapproved early departures will incur Half Day leave deductions.",
                            'early_go_warning',
                            $userId
                        );
                    }
                }
            } else if ($totalMonthlyCount > 3 && ($totalMonthlyCount - 3) % 2 === 0) {
                // Rule 8: Every 2 additional marks after warning (5th, 7th, 9th...) -> Half Day Leave deduction
                $halfDayDeducted = true;
                $isHalfDay       = true;
                $actionTaken    .= " | Half Day Deduction (Mark #{$totalMonthlyCount})";

                if (file_exists(__DIR__ . '/notification_helper.php')) {
                    require_once __DIR__ . '/notification_helper.php';
                    if (function_exists('sendNotification')) {
                        $monthName = date('F Y', strtotime($date));
                        sendNotification(
                            $con,
                            $userId,
                            'Half Day Leave Deduction ⚠️',
                            "Half Day Leave deducted due to Early Go mark #{$totalMonthlyCount} in {$monthName}.",
                            'early_go_deduction',
                            $userId
                        );
                    }
                }
            }
        }

        // Upsert record in early_go table
        $existingQ = mysqli_query($con, "SELECT id FROM early_go WHERE userId = '$escUser' AND date = '$escDate' LIMIT 1");
        if ($existingQ && mysqli_num_rows($existingQ) > 0) {
            $row = mysqli_fetch_assoc($existingQ);
            $id = (int)$row['id'];
            $updateSql = "
                UPDATE early_go SET
                    emp_name                = '$escEmpName',
                    early_go_time           = '$escTime',
                    early_departure_minutes = $minutes,
                    early_go_mark           = $mark,
                    action_taken            = '$actionTaken',
                    reason                  = '$escReason'
                WHERE id = $id
            ";
            mysqli_query($con, $updateSql);
        } else {
            $insertSql = "
                INSERT INTO early_go 
                    (userId, emp_name, early_go_time, early_departure_minutes, early_go_mark, action_taken, date, reason, hr_granted)
                VALUES 
                    ('$escUser', '$escEmpName', '$escTime', $minutes, $mark, '$actionTaken', '$escDate', '$escReason', 0)
            ";
            mysqli_query($con, $insertSql);
        }

        return [
            'earlyDepartureMinutes' => $minutes,
            'earlyGoMark'           => $mark,
            'actionTaken'           => $actionTaken,
            'isHalfDay'             => $isHalfDay,
            'monthlyCount'          => $totalMonthlyCount,
            'warningIssued'         => $warningIssued,
            'halfDayDeducted'       => $halfDayDeducted,
        ];
    }
}

if (!function_exists('getMonthlyEarlyGoSummary')) {
    /**
     * Get monthly summary stats for an employee.
     */
    function getMonthlyEarlyGoSummary(mysqli $con, string $userId, string $yearMonth): array {
        ensureEarlyGoTableColumns($con);
        $u  = mysqli_real_escape_string($con, $userId);
        $ym = mysqli_real_escape_string($con, $yearMonth);

        $q = mysqli_query($con, "
            SELECT 
                COUNT(*) AS total_early_departures,
                SUM(CASE WHEN COALESCE(hr_granted, 0) = 0 AND early_go_mark = 1 THEN 1 ELSE 0 END) AS unapproved_marks,
                SUM(CASE WHEN hr_granted = 1 THEN 1 ELSE 0 END) AS hr_approved_count,
                SUM(CASE WHEN action_taken LIKE '%Half Day%' THEN 1 ELSE 0 END) AS half_day_deductions,
                SUM(CASE WHEN action_taken LIKE '%Warning%' THEN 1 ELSE 0 END) AS warnings_count
            FROM early_go
            WHERE userId = '$u' AND DATE_FORMAT(date, '%Y-%m') = '$ym'
        ");

        $data = ($q && $r = mysqli_fetch_assoc($q)) ? $r : [];

        $unapprovedMarks = (int)($data['unapproved_marks'] ?? 0);
        return [
            'totalEarlyDepartures' => (int)($data['total_early_departures'] ?? 0),
            'unapprovedMarks'      => $unapprovedMarks,
            'hrApprovedCount'      => (int)($data['hr_approved_count'] ?? 0),
            'halfDayDeductions'    => (int)($data['half_day_deductions'] ?? 0),
            'warningsCount'        => (int)($data['warnings_count'] ?? 0),
            'warningThresholdMet'  => ($unapprovedMarks >= 3),
        ];
    }
}
