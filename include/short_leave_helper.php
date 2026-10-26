<?php
/**
 * short_leave_helper.php - Short Leave Policy Helper
 * Policy effective from: 2026-08-01
 *  - 2 short leaves per month, max 2 hours each.
 *  - Unused short leaves do NOT carry forward.
 *  - Extra short leaves deducted from leave balance (0.5 day each).
 */

if (!defined('POLICY_EFFECTIVE_DATE')) {
    define('POLICY_EFFECTIVE_DATE', '2026-08-01');
}
if (!defined('SHORT_LEAVE_FREE_PER_MONTH')) {
    define('SHORT_LEAVE_FREE_PER_MONTH', 2);
}
if (!defined('SHORT_LEAVE_MAX_HOURS')) {
    define('SHORT_LEAVE_MAX_HOURS', 2.0);
}

if (!function_exists('ensureShortLeaveTable')) {
    function ensureShortLeaveTable(mysqli $con): void {
        static $done = false;
        if ($done) return;
        mysqli_query($con, "
            CREATE TABLE IF NOT EXISTS short_leaves (
                id           INT AUTO_INCREMENT PRIMARY KEY,
                userId       VARCHAR(50) NOT NULL,
                date         DATE NOT NULL,
                from_time    TIME NOT NULL,
                to_time      TIME NOT NULL,
                duration_hrs DECIMAL(4,2) NOT NULL,
                reason       VARCHAR(500) DEFAULT NULL,
                status       ENUM('Pending','Approved','Rejected') DEFAULT 'Pending',
                approved_by  VARCHAR(50) DEFAULT NULL,
                approved_at  DATETIME DEFAULT NULL,
                created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_month (userId, date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        $done = true;
    }
}

if (!function_exists('getMonthlyShortLeaveUsage')) {
    function getMonthlyShortLeaveUsage(mysqli $con, string $userId, string $yearMonth): array {
        ensureShortLeaveTable($con);
        $u  = mysqli_real_escape_string($con, $userId);
        $ym = mysqli_real_escape_string($con, $yearMonth);
        $res = mysqli_query($con, "
            SELECT COUNT(*) AS cnt, COALESCE(SUM(duration_hrs), 0) AS total_hrs
            FROM short_leaves
            WHERE userId = '$u'
              AND DATE_FORMAT(date, '%Y-%m') = '$ym'
              AND status = 'Approved'
        ");
        if ($res && $row = mysqli_fetch_assoc($res)) {
            return ['used' => (int)$row['cnt'], 'totalHours' => (float)$row['total_hrs']];
        }
        return ['used' => 0, 'totalHours' => 0.0];
    }
}

if (!function_exists('getRemainingFreeShortLeaves')) {
    function getRemainingFreeShortLeaves(mysqli $con, string $userId, string $yearMonth): int {
        $usage = getMonthlyShortLeaveUsage($con, $userId, $yearMonth);
        return max(0, SHORT_LEAVE_FREE_PER_MONTH - $usage['used']);
    }
}

if (!function_exists('evaluateShortLeavePolicy')) {
    function evaluateShortLeavePolicy(mysqli $con, string $userId, string $date, float $durationHrs): array {
        if ($date < POLICY_EFFECTIVE_DATE) {
            return ['allowed' => true, 'freeLeave' => true, 'deductFromLeave' => false,
                    'message' => 'Pre-policy date - approved without quota deduction.'];
        }
        if ($durationHrs <= 0 || $durationHrs > SHORT_LEAVE_MAX_HOURS) {
            return ['allowed' => false, 'freeLeave' => false, 'deductFromLeave' => false,
                    'message' => 'Short leave duration must be between 1 minute and ' . SHORT_LEAVE_MAX_HOURS . ' hours.'];
        }
        $yearMonth = substr($date, 0, 7);
        $remaining = getRemainingFreeShortLeaves($con, $userId, $yearMonth);
        if ($remaining > 0) {
            return ['allowed' => true, 'freeLeave' => true, 'deductFromLeave' => false,
                    'message' => "Approved as free short leave. You have {$remaining} free short leave(s) remaining this month."];
        }
        return ['allowed' => true, 'freeLeave' => false, 'deductFromLeave' => true,
                'message' => 'Free quota exhausted. 0.5 day will be deducted from your leave balance.'];
    }
}
